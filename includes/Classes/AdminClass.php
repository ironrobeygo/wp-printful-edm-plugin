<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Utils/PrintfulCache.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/CatalogServices.php';

class AdminClass{
	private $catalog;
	private bool $registered = false;

	public function __construct(){
		$this->catalog = new CatalogService();
		
	}

	public function register(){
		if($this->registered) return;

		add_action('admin_menu',                                    [$this, 'menu']);

		$this->registered = true;
	}

	public function menu()
	{
		add_options_page(
			'Printful Catalog Settings',
			'Printful Catalog',
			'manage_options',
			'printful-catalog-settings',
			array($this, 'page')
		);
	}

	public function page()
	{
		if (isset($_POST['submit'])) {
			update_option('printful_api_key', sanitize_text_field($_POST['printful_api_key']));
			update_option('printful_store_id', sanitize_text_field($_POST['printful_store_id']));
			update_option('pf_container_product_id', (int)($_POST['pf_container_product_id'] ?? 0));
			update_option('pf_markup_pct', (float)($_POST['pf_markup_pct'] ?? 0));
			update_option('pf_markup_fix', (float)($_POST['pf_markup_fix'] ?? 0));
			update_option('pf_show_retail', !empty($_POST['pf_show_retail']) ? 1 : 0);

			update_option(
				'pf_allowed_category_ids',
				array_values(array_unique(array_map('intval', (array)($_POST['pf_allowed_category_ids'] ?? []))))
			);

			// Clear cache when settings are updated
			PrintfulCache::clear_product_cache();

			echo '<div class="notice notice-success"><p>Settings saved and cache cleared!</p></div>';
		}

		if (isset($_POST['clear_cache'])) {
			PrintfulCache::clear_product_cache();
			echo '<div class="notice notice-success"><p>Product cache cleared!</p></div>';
		}

		$api_key 				= get_option('printful_api_key', '');
		$store_id 				= get_option('printful_store_id', '');
		$container_id 			= (int) get_option('pf_container_product_id', 0);
		$allowed_category_ids 	= (array) get_option('pf_allowed_category_ids', []);
		$force_refresh_cats   	= isset($_GET['pf_refresh_cats']);
		$cats                 	= $this->catalog->pf_get_all_catalog_categories($force_refresh_cats);
		$cat_rows             	= $this->catalog->pf_build_category_paths($cats);
		$refresh_url          	= add_query_arg(
			['page' => 'printful-catalog-settings', 'pf_refresh_cats' => 1],
			admin_url('options-general.php')
		);

		?>
		<div class="wrap">
			<h1>Printful Catalog Settings</h1>
			<form method="post" action="">
				<table class="form-table">
					<tr>
						<th scope="row">Printful API Key</th>
						<td>
							<input type="text" name="printful_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
							<p class="description">Enter your Printful API key. You can find this in your Printful dashboard under Settings > API.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Printful Store ID</th>
						<td>
							<input type="text" name="printful_store_id" value="<?php echo esc_attr($store_id); ?>" class="regular-text" />
							<p class="description">Enter your Printful Store ID. Required for the Embedded Design Maker. You can find this in your Printful dashboard URL or API responses.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Container Product ID</th>
						<td>
							<input type="number" name="pf_container_product_id" value="<?php echo esc_attr($container_id); ?>" class="small-text" />
							<p class="description">WooCommerce product used to carry custom designs in cart.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Catalog Categories</th>
						<td>
							<p>
								Select which Printful catalog categories to include when pulling products.
								<a class="button" href="<?php echo esc_url($refresh_url); ?>">Force refresh from API</a>
							</p>

							<input type="text" id="pf-cat-search" placeholder="Search categories…" style="min-width:320px;padding:6px;margin:6px 0;">
							<button type="button" class="button" id="pf-cat-select-all">Select all</button>
							<button type="button" class="button" id="pf-cat-clear-all">Clear all</button>

							<div id="pf-cat-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px;max-width:1100px;margin-top:8px;">
								<?php if (empty($cat_rows)): ?>
									<em>No categories found. Click “Force refresh from API”.</em>
									<?php else: foreach ($cat_rows as $r):
										$id = (int)$r['id'];
										$label = $r['label'] ?: ('Category #' . $id);
										$checked = in_array($id, $allowed_category_ids, true) ? 'checked' : '';
									?>
										<label style="border:1px solid #ccd0d4;border-radius:6px;padding:8px;background:#fff;display:flex;gap:8px;align-items:center;">
											<input type="checkbox" class="pf-cat" name="pf_allowed_category_ids[]" value="<?php echo esc_attr($id); ?>" <?php echo $checked; ?> />
											<span><?php echo esc_html($label); ?></span>
										</label>
								<?php endforeach;
								endif; ?>
							</div>

							<script>
								(function() {
									const search = document.getElementById('pf-cat-search');
									const grid = document.getElementById('pf-cat-grid');
									const selAll = document.getElementById('pf-cat-select-all');
									const clrAll = document.getElementById('pf-cat-clear-all');

									if (search) {
										search.addEventListener('input', function() {
											const q = this.value.toLowerCase();
											Array.from(grid.querySelectorAll('label')).forEach(el => {
												el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
											});
										});
									}
									if (selAll) selAll.addEventListener('click', () => grid.querySelectorAll('input.pf-cat').forEach(cb => cb.checked = true));
									if (clrAll) clrAll.addEventListener('click', () => grid.querySelectorAll('input.pf-cat').forEach(cb => cb.checked = false));
								})();
							</script>
						</td>
					</tr>
				</table>
				<?php submit_button('Save Settings'); ?>
			</form>

			<hr>

			<h2>Cache Management</h2>
			<p>If products are not displaying correctly or showing outdated information, clear the cache.</p>
			<form method="post" action="">
				<?php submit_button('Clear Product Cache', 'secondary', 'clear_cache'); ?>
			</form>

			<hr>

			<h2>Usage</h2>
			<p><strong>Display Product Catalog (Page Redirect):</strong></p>
			<code>[printful_catalog]</code>

			<p><strong>Standalone Design Maker:</strong></p>
			<code>[printful_design_maker]</code>

			<p><strong>Note:</strong> Modal functionality requires both API Key and Store ID to be configured.</p>
		</div>
		<?php
	}
}
