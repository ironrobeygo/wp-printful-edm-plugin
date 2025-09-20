<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/CatalogServices.php';

final class CatalogAjax{
	private $catalog;
	private bool $registered = false; 

	public function __construct(){
		$this->catalog = new CatalogService();	
	}

	public function register(){
		if($this->registered) return;
		add_action('wp_ajax_load_more_products',        [$this, 'handle']);
		add_action('wp_ajax_nopriv_load_more_products', [$this, 'handle']);

		$this->registered = true;
	}

	public function handle()
	{
		check_ajax_referer('printful_nonce', 'nonce');

		$offset = intval($_POST['offset'] ?? 0);
		$limit  = intval($_POST['limit']  ?? 8);

		$category_ids_raw = $_POST['category_ids'] ?? [];
		if (!is_array($category_ids_raw)) {
			$category_ids_raw = ($category_ids_raw === '' ? [] : preg_split('/[,\s]+/', (string)$category_ids_raw, -1, PREG_SPLIT_NO_EMPTY));
		}
		$requested_ids = array_values(array_filter(array_map('intval', $category_ids_raw)));

		$techniques_raw = $_POST['technique'] ?? [];
		if (!is_array($techniques_raw)) {
			$techniques_raw = ($techniques_raw === '' ? [] : preg_split('/[,\s]+/', (string)$techniques_raw, -1, PREG_SPLIT_NO_EMPTY));
		}
		$techniques = array_values(array_filter(array_map('strval', $techniques_raw)));

		$placements_raw = $_POST['placements'] ?? [];
		if (!is_array($placements_raw)) {
			$placements_raw = ($placements_raw === '' ? [] : preg_split('/[,\s]+/', (string)$placements_raw, -1, PREG_SPLIT_NO_EMPTY));
		}
		$placements = array_values(array_filter(array_map('strval', $placements_raw)));

		$colors_raw = $_POST['color'] ?? [];
		if (!is_array($colors_raw)) {
			$colors_raw = ($colors_raw === '' ? [] : preg_split('/[,\s]+/', (string)$colors_raw, -1, PREG_SPLIT_NO_EMPTY));
		}
		$colors = array_values(array_filter(array_map('strval', $colors_raw)));

		$sizes_raw = $_POST['sizes'] ?? [];
		if (!is_array($sizes_raw)) {
			$sizes_raw = ($sizes_raw === '' ? [] : preg_split('/[,\s]+/', (string)$sizes_raw, -1, PREG_SPLIT_NO_EMPTY));
		}
		$sizes = array_values(array_filter(array_map('strval', $sizes_raw)));

		$filters = [];
		if (!empty($techniques)) 	 $filters['techniques'] 	= $techniques;
		if (!empty($placements)) 	 $filters['placements'] 	= $placements;
		if (!empty($colors))     	 $filters['colors']     	= $colors;
		if (!empty($sizes))      	 $filters['sizes']      	= $sizes;

		$products = $this->catalog->get_products($offset, $limit, $requested_ids, $filters);

		if (empty($products)) {
			wp_send_json_error('No more products found');
			return;
		}

		ob_start();
		foreach ($products as $product): ?>
			<div class="product-card" data-product-id="<?php echo esc_attr($product['id']); ?>">
				<div class="product-image">
					<img src="<?php echo esc_url($product['image']); ?>" alt="" />
				</div>
				<div class="product-info">
					<h3 class="product-title"><?php echo esc_html($product['title']); ?></h3>
					<p class="product-id">ID: <?php echo esc_html($product['id']); ?></p>
					<p class="product-price">From $<?php echo esc_html(number_format($product['price'], 2)); ?></p>
					<button class="design-button pf-btn pf-btn--primary" data-product-id="<?php echo esc_attr($product['id']); ?>">
						Design Product
					</button>
				</div>
			</div>
		<?php endforeach;
		$html = ob_get_clean();

		wp_send_json_success([
			'html'        => $html,
			'offset'      => $limit + $offset,
		]);
	}
}
