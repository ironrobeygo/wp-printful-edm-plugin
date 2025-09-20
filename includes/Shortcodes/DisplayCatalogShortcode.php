<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/PrintfulApiServices.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/CatalogServices.php';

class DisplayCatalogShortcode{
	private $apikey 	= PRINTFUL_API_KEY;
	private $catalog;
	private bool $registered = false;
	
	public function __construct(){
		$this->catalog = new CatalogService();
	}

	public function register(): void{
		if($this->registered) return;

		add_shortcode('printful_catalog', [$this, 'render']);

		$this->registered = true;
	}
	public function render($atts = []){
		$atts = shortcode_atts(array(
			'limit' => 8,
			'category_id' => ''
		), $atts);

		// Check if API key is configured
		if (empty($this->apikey)) {
			return '<div class="printful-error"><p>Error: Printful API key not configured. Please check plugin settings.</p></div>';
		}

		$admin_ids = (array) get_option('pf_allowed_category_ids', []);
		$admin_ids = array_values(array_unique(array_filter(array_map('intval', $admin_ids))));

		$shortcode_ids = array();
		if (!empty($atts['category_id'])) {
			$shortcode_ids = array_values(array_unique(array_filter(array_map(
				'intval',
				preg_split('/[,\s]+/', (string) $atts['category_id'])
			))));
		}

		$source_ids = !empty($shortcode_ids) ? $shortcode_ids : $admin_ids;

		// Fallback if neither admin nor shortcode provided anything
		if (empty($source_ids)) {
			$source_ids = array(1, 2, 3, 4); // generic fallback
		}

		$all_cats     = $this->catalog->pf_get_all_catalog_categories(false);
		$cat_rows     = $this->catalog->pf_build_category_paths($all_cats); // each row: ['id'=>int,'label'=>string]
		$label_by_id  = array();
		foreach ($cat_rows as $r) {
			$label_by_id[(int)$r['id']] = $r['label'];
		}
		$fallback_lbl = array(1 => "Men's", 2 => "Women's", 3 => 'Kids', 4 => 'Accessories');

		$filter_items = array();
		foreach ($source_ids as $cid) {
			$label = isset($label_by_id[$cid]) ? $label_by_id[$cid] : (isset($fallback_lbl[$cid]) ? $fallback_lbl[$cid] : ('Category ' . $cid));
			$filter_items[(int)$cid] = $label;
		}

		$show_all_ids = array_map('intval', array_keys($filter_items));
		$show_all_csv = implode(',', $show_all_ids);

		// --- URL-based category preselect for checkbox panel (supports multiple names) ---
		$selected_ids = [];
		$aliases = ['category_ids', 'category_id', 'pf_cat', 'category']; // accept CSV or single
		$raw = [];
		foreach ($aliases as $k) {
			if (isset($_GET[$k]) && $_GET[$k] !== '') {
				$raw[] = (string) $_GET[$k];
			}
		}
		if (!empty($raw)) {
			$merged = implode(',', $raw);
			$selected_ids = array_values(array_unique(array_filter(array_map(
				'intval',
				preg_split('/[,\s]+/', $merged, -1, PREG_SPLIT_NO_EMPTY)
			))));
			// Keep only categories that belong to THIS page’s set
			$selected_ids = array_values(array_intersect($selected_ids, $show_all_ids));
		}
		if (empty($selected_ids)) {
			// Default to Show All for this page
			$selected_ids = $show_all_ids;
		}

		$selected_csv = implode(',', $selected_ids);
		$is_show_all  = (count($selected_ids) === count($show_all_ids)) && !array_diff($show_all_ids, $selected_ids);

		// Group categories by first section, show last section as option text
		$grouped = [];

		foreach ($filter_items as $id => $label) {
			$id = (int)$id;
			$parts = preg_split('/\s*[›>]\s*/u', (string) $label);
			$first = isset($parts[0]) ? trim($parts[0]) : (string)$label;
			$last  = trim(end($parts));
			if ($first === '') $first = (string)$label;
			if ($last  === '') $last  = (string)$label;
			$grouped[$first][] = ['id' => $id, 'name' => $last];
		}

		if (empty($selected_ids)) {
			$selected_ids = $source_ids;
		}

		// Initial fetch respects the selection (empty array = "all" = baseline_ids)
		$limit    = intval($atts['limit'] ?? 8);
		$products = $this->catalog->get_products(0, $limit, $selected_ids);

		if (empty($products)) {
			return '<div class="printful-error"><p>Unable to load products at this time. Please try again later.</p></div>';
		}

		$__pfc_filters = $this->catalog->pf_catalog_filter_definitions();

		ob_start();
		?>
		<div id="printful-catalog"
			class="printful-catalog-container"
			data-in-stock-only="1"
			data-selected-ids="<?php echo esc_attr($selected_csv); ?>"
			data-category-ids="<?php echo esc_attr($selected_csv); ?>"
			data-default-category-ids="<?php echo esc_attr($show_all_csv); ?>">
			<div class="pfc-topbar">
				<div class="pfc-filters-left">
					<div class="pfc-dropdown" data-filter="category">
						<button>Categories ▾</button>
						<div class="pfc-dropdown-panel">
							<!-- Show All behaves as 'no category filter' -->
							<label>
								<input type="checkbox" name="category_id[]" value="<?php echo esc_attr($show_all_csv); ?>"
									data-role="show-all" <?php echo $is_show_all ? 'checked' : ''; ?>>
								Show All
							</label>

							<?php foreach ($grouped as $groupLabel => $items): ?>
								<strong class="pfc-subgroup"><?php echo esc_html($groupLabel); ?></strong>
								<?php foreach ($items as $it): ?>
									<label>
										<input type="checkbox" name="category_id[]" value="<?php echo esc_attr((string)$it['id']); ?>"
											<?php echo (!$is_show_all && in_array((int)$it['id'], $selected_ids, true)) ? 'checked' : ''; ?>>
										<?php echo esc_html($it['name']); ?>
									</label>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="pfc-dropdown" data-filter="technique">
						<button>Technique ▾</button>
						<div class="pfc-dropdown-panel">
							<?php foreach ($__pfc_filters['technique'] as $o): ?>
								<label><input type="checkbox" name="technique[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
							<?php endforeach; ?>
							<a href="#" data-clear="technique">Clear</a>
						</div>
					</div>

					<div class="pfc-dropdown" data-filter="color">
						<button>Color ▾</button>
						<div class="pfc-dropdown-panel">
							<?php foreach ($__pfc_filters['color'] as $o): ?>
								<label><input type="checkbox" name="color[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
							<?php endforeach; ?>
							<a href="#" data-clear="color">Clear</a>
						</div>
					</div>

					<div class="pfc-dropdown" data-filter="placements">
						<button>Branding options ▾</button>
						<div class="pfc-dropdown-panel">
							<a href="#" data-clear="placements">Clear</a>
						</div>
					</div>

					<div class="pfc-dropdown" data-filter="sizes">
						<button>Sizes ▾</button>
						<div class="pfc-dropdown-panel">
							<?php foreach ($__pfc_filters['sizes'] as $o): ?>
								<label><input type="checkbox" name="sizes[]" value="<?php echo esc_attr($o['id']); ?>"> <?php echo esc_html($o['label']); ?></label>
							<?php endforeach; ?>
							<a href="#" data-clear="sizes">Clear</a>
						</div>
					</div>
				</div>

				<div class="pfc-filters-right">
					<button type="button" class="pfc-btn-reset pf-btn pf-btn--primary" data-role="reset_filters">
						Reset
					</button>
				</div>
			</div>


			<div id="pf-products-grid" class="products-grid">
				<?php foreach ($products as $product): ?>
					<div class="product-card" data-product-id="<?php echo esc_attr($product['id']); ?>">
						<div class="product-image">
							<img src="<?php echo esc_url($product['image']); ?>"
								alt="<?php echo esc_attr($product['title']); ?>"
								onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPk5vIEltYWdlPC90ZXh0Pjwvc3ZnPg=='" />
						</div>
						<div class="product-info">
							<h3 class="product-title"><?php echo esc_html($product['title']); ?></h3>
							<p class="product-id">ID: <?php echo esc_html($product['id']); ?></p>
							<p class="product-price">From $<?php echo esc_html(number_format($product['price'], 2)); ?></p>
							<button class="design-button pf-btn pf-btn--primary"
								data-product-id="<?php echo esc_attr($product['id']); ?>">
								Design Product
							</button>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div id="pf-catalog-spinner" class="loading-spinner" style="display:none;">Loading…</div>

			<div class="load-more-container">
				<button id="load-more-products"
					class="pf-btn pf-btn--secondary"
					data-offset="<?php echo intval($atts['limit']); ?>"
					data-limit="<?php echo intval($atts['limit']); ?>"
					data-category-ids="<?php echo esc_attr($selected_csv); ?>"
					data-default-category-ids="<?php echo esc_attr($show_all_csv); ?>">
					Load More Products
				</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
