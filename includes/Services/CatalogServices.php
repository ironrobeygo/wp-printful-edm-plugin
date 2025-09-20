<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Utils/PrintfulCache.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/PrintfulApiServices.php';

final class CatalogService
{
	private $api;
	public function __construct()
	{
		$this->api 		= new PrintfulApi();
	}

	public function get_products($offset = 0, $limit = 8, $category_ids_override = null, $filters = []){
		$raw = $category_ids_override;
		if (is_null($raw)) {
			$requested_ids = array();
		} elseif (is_array($raw)) {
			$requested_ids = $raw;
		} else {
			$requested_ids = preg_split('/[,\\s]+/', (string)$raw);
		}
		$effective_ids = array_values(array_unique(array_filter(array_map('intval', (array)$requested_ids))));

		// Build CSV (may be empty -> API will return all unless constrained server-side)
		$category_ids = !empty($effective_ids) ? implode(',', $effective_ids) : '';

		$cache_key = sprintf(
			'pf_products_%d_%d_%s_%s',
			(int)$offset,
			(int)$limit,
			md5($category_ids),
			md5(json_encode($filters))
		);
		if (($cached = PrintfulCache::get_cached_products($cache_key)) !== false) {
			return $cached;
		}

		try {
			$params = [
				'limit'               => intval($limit),
				'offset'              => intval($offset),
				'selling_region_name' => 'australia',
				'destination_country' => 'AU',
			];

			// Only include if non-empty
			if (!empty($category_ids_override))   $params['category_ids'] = array_values($category_ids_override);
			if (!empty($filters['techniques']))   $params['techniques']   = $filters['techniques'];   // e.g. ['dtg','embroidery']
			if (!empty($filters['placements']))   $params['placements']   = $filters['placements'];   // e.g. ['front','back']
			if (!empty($filters['colors']))       $params['colors']       = $filters['colors'];       // e.g. ['black','white']
			if (!empty($filters['sizes']))        $params['sizes']        = $filters['sizes'];       // e.g. ['black','white']

			foreach (['category_ids', 'techniques', 'placements', 'colors', 'sizes'] as $k) {
				if (!empty($params[$k])) {
					// normalize to array then CSV
					$arr = is_array($params[$k]) ? $params[$k] : preg_split('/[,\s]+/', (string)$params[$k], -1, PREG_SPLIT_NO_EMPTY);
					$params[$k] = implode(',', array_map('strval', $arr));
				}
			}

			$url = 'v2/catalog-products' . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

			$products = $this->api->request($url);

			if (!$products || !isset($products['data'])) {
				error_log('Printful API Error: empty products');
				return [];
			}

			$result = [];
			foreach ($products['data'] as $product) {
				$price = $this->get_catalog_min_price($product['id']);
				$result[] = [
					'id'          => $product['id'],
					'title'       => $product['name'],
					'type'        => $product['type'],
					'image'       => $product['image'],
					'price'       => $price,
					'description' => isset($product['description']) ? $product['description'] : '',
				];
			}

			PrintfulCache::set_cached_products($cache_key, $result, 1800); // 10 min
			return $result;
		} catch (Exception $e) {
			error_log('Printful Catalog Error: ' . $e->getMessage());
			return [];
		}
	}

	public function pf_get_all_catalog_categories($force_refresh = false)
	{
		$cache_key = 'pf_catalog_categories_all_v2';
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if ($cached !== false) return $cached;
		}

		$categories = [];
		$limit = 100;
		$offset = 0;

		do {
			$endpoint = "v2/catalog-categories?limit={$limit}&offset={$offset}";
			$resp = $this->api->request($endpoint, 'GET');

			if (!is_array($resp) || empty($resp['data']) || !is_array($resp['data'])) break;

			foreach ($resp['data'] as $row) {
				$categories[] = [
					'id'        => (int) ($row['id'] ?? 0),
					'parent_id' => (int) ($row['parent_id'] ?? 0),
					'title'     => (string) ($row['title'] ?? ''),
					'image_url' => (string) ($row['image_url'] ?? ''),
				];
			}

			$count = count($resp['data']);
			$offset += $limit;
		} while ($count === $limit);

		set_transient($cache_key, $categories, DAY_IN_SECONDS);
		return $categories;
	}

	public function pf_build_category_paths(array $cats)
	{
		$byId = [];
		foreach ($cats as $c) $byId[$c['id']] = $c;

		$cache = [];
		$pathOf = function ($id) use (&$byId, &$cache, &$pathOf) {
			if (isset($cache[$id])) return $cache[$id];
			if (!isset($byId[$id])) return '';
			$chain = [];
			$cur = $byId[$id];
			$guard = 0;
			while ($cur && $guard++ < 20) {
				array_unshift($chain, (string)($cur['title'] ?? ''));
				$pid = (int)($cur['parent_id'] ?? 0);
				$cur = $pid && isset($byId[$pid]) ? $byId[$pid] : null;
			}
			return $cache[$id] = trim(implode(' › ', array_filter($chain)));
		};

		$rows = [];
		foreach ($cats as $c) $rows[] = ['id' => $c['id'], 'label' => $pathOf($c['id'])];
		usort($rows, fn($a, $b) => strcasecmp($a['label'], $b['label']));
		return $rows;
	}

	public function pf_catalog_filter_definitions()
	{
		return [
			'technique' => [
				['id' => 'cut-sew', 'label' => 'Cut & sew sublimation'],
				['id' => 'dtfilm', 'label' => 'DTF printing'],
				['id' => 'dtg', 'label' => 'DTG printing'],
				['id' => 'embroidery', 'label' => 'Embroidery'],
			],
			'color' => [
				['id' => 'black', 'label' => 'Black'],
				['id' => 'white', 'label' => 'White'],
				['id' => 'heather_gray', 'label' => 'Heather Gray'],
				['id' => 'gray', 'label' => 'Gray'],
				['id' => 'navy', 'label' => 'Navy'],
				['id' => 'royal_blue', 'label' => 'Royal Blue'],
				['id' => 'blue', 'label' => 'Blue'],
				['id' => 'red', 'label' => 'Red'],
				['id' => 'maroon', 'label' => 'Maroon'],
				['id' => 'burgundy', 'label' => 'Burgundy'],
				['id' => 'pink', 'label' => 'Pink'],
				['id' => 'orange', 'label' => 'Orange'],
				['id' => 'yellow', 'label' => 'Yellow'],
				['id' => 'gold', 'label' => 'Gold'],
				['id' => 'green', 'label' => 'Green'],
				['id' => 'forest', 'label' => 'Forest'],
				['id' => 'olive', 'label' => 'Olive'],
				['id' => 'purple', 'label' => 'Purple'],
				['id' => 'brown', 'label' => 'Brown'],
				['id' => 'khaki', 'label' => 'Khaki'],
				['id' => 'tan', 'label' => 'Tan'],
				['id' => 'cream', 'label' => 'Cream'],
				['id' => 'charcoal', 'label' => 'Charcoal'],
				['id' => 'silver', 'label' => 'Silver'],
			],
			'sizes' => [
				['id' => '2XS', 'label' => '2XS'],
				['id' => 'XS', 'label' => 'XS'],
				['id' => 'S', 'label' => 'S'],
				['id' => 'M', 'label' => 'M'],
				['id' => 'L', 'label' => 'L'],
				['id' => 'XL', 'label' => 'XL'],
				['id' => '2XL', 'label' => '2XL'],
				['id' => '3XL', 'label' => '3XL'],
				['id' => '4XL', 'label' => '4XL'],
				['id' => '5XL', 'label' => '5XL'],
			]
		];
	}

	private function get_catalog_min_price($product_id, $currency = null, $region = null)
	{
		$key_bits = [$product_id, $currency ?: 'store', $region ?: 'store'];
		$cache_key = 'pf_min_price_' . implode('_', $key_bits);
		$cached = get_transient($cache_key);
		if ($cached !== false) return $cached;

		$endpoint = "v2/catalog-products/{$product_id}/prices";
		$qs = [];
		if ($currency) $qs[] = 'currency=AUD';
		if ($region)   $qs[] = 'selling_region_name=australia';
		if ($qs) $endpoint .= '?' . implode('&', $qs);

		$res = $this->api->request($endpoint, 'GET');
		if (!$res || empty($res['data'])) return null;

		$data = $res['data'];

		$placement_price_by_tech = [];
		if (!empty($data['product']['placements']) && is_array($data['product']['placements'])) {
			foreach ($data['product']['placements'] as $pl) {
				$techKey = $pl['technique_key'] ?? $pl['techniqueKey'] ?? null;
				if (!$techKey) continue;
				$placement_price_by_tech[$techKey] = (float) (
					$pl['discounted_price'] ?? $pl['price'] ?? 0
				);
			}
		}

		$min = INF;
		if (!empty($data['variants']) && is_array($data['variants'])) {
			foreach ($data['variants'] as $v) {
				foreach ($v['techniques'] as $t) {
					$prices[]    = (float)$t['price'];
				}
			}

			$min = $prices ? min($prices) : null;
		}

		if (!is_finite($min)) return null;

		set_transient($cache_key, $min, 12 * HOUR_IN_SECONDS);
		return $min;
	}
}
