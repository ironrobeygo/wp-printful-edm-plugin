<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Classes/hooks.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Classes/class-printful-auto-confirm.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Classes/class-printful-webhook-admin.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/PrintfulApiServices.php';

final class WooIntegrationClass
{
	private $api;
	private bool $registered = false;

	public function __construct() {
		$this->api = new PrintfulApi();
	}

	public function register()
	{
		if ($this->registered) return;

		add_action('init',                                          [$this, 'add_my_designs_endpoint']);
		add_action('init',                                          [$this, 'register_cart_line_overrides']);
		add_action('woocommerce_account_menu_items',                [$this, 'add_my_designs_menu_item']);
		add_action('woocommerce_account_my-designs_endpoint',       [$this, 'my_designs_content']);

		add_filter('woocommerce_shipping_methods', 					[$this, 'register_shipping_method']);
		add_action('woocommerce_payment_complete', 					[__CLASS__, 'hook_wc_processing'], 10, 1);
		add_action('woocommerce_order_status_changed', 				[$this, 'submit_order_if_needed'], 10, 3);

		if (did_action('plugins_loaded')) {
			Printful_AutoConfirm::init();
		} else {
			add_action('plugins_loaded', ['Printful_AutoConfirm', 'init']);
		}
		if (is_admin()) {
			if (did_action('plugins_loaded')) {
				Printful_Webhook_Admin::init();
			} else {
				add_action('plugins_loaded', ['Printful_Webhook_Admin', 'init']);
			}
		}

		add_action('plugins_loaded', function () {
			if (!class_exists('WooCommerce')) {
				return;
			}

			add_action('woocommerce_shipping_init', [$this, 'shipping_init']);
			add_filter('woocommerce_shipping_methods', [$this, 'register_shipping_method']);
		});

		$this->registered = true;
	}

	public function register_cart_line_overrides(){
		if (!class_exists('WC_Cart')) {
			return;
		}

		// Override cart item name and permalink for our custom items
		add_filter('woocommerce_cart_item_name', function ($name, $cart_item, $cart_item_key) {
			if (!empty($cart_item['pf_item'])) {
				$design  = !empty($cart_item['design_name']) ? $cart_item['design_name'] : 'Custom Design';
				$cat     = !empty($cart_item['design_category']) ? $cart_item['design_category'] : '';
				$title   = $cat ? sprintf('%s — %s', esc_html($design), esc_html($cat)) : esc_html($design);

				$name = '<strong class="pf-line-title">' . $title . '</strong>';
			}
			return $name;
		}, 10, 3);

		add_filter('woocommerce_cart_item_permalink', function ($permalink, $cart_item, $cart_item_key) {
			if (!empty($cart_item['pf_item'])) {
				return false; // no link
			}
			return $permalink;
		}, 10, 3);

		add_filter('woocommerce_cart_item_thumbnail', function ($thumb, $cart_item, $cart_item_key) {
			// Only for our Printful container items (we set this flag in add_to_cart meta)
			if (!empty($cart_item['pf_item']) && !empty($cart_item['mockup_url'])) {
				$alt = !empty($cart_item['design_name']) ? $cart_item['design_name'] : 'Custom Design';
				return sprintf(
					'<img src="%s" alt="%s" style="width:80px;height:auto;"/>',
					esc_url($cart_item['mockup_url']),
					esc_attr($alt)
				);
			}
			return $thumb;
		}, 10, 3);

		add_filter('woocommerce_store_api_cart_item_images', function ($thumb, $cart_item, $cart_item_key) {
			$design_name = 'Custom Design';
			$image_path = $thumb;

			if (!empty($cart_item['pf_item']) && !empty($cart_item['mockup_url'])) {
				$image_path = $cart_item['mockup_url'];
				$design_name = $cart_item['design_name'];
			}
			// Only for our Printful container items (we set this flag in add_to_cart meta)
			return [
				(object)[
					'id'        => (int) 0,
					'src'       => $image_path,
					'thumbnail' => $image_path,
					'srcset'    => (string)'',
					'sizes'     => (string)'',
					'name'      => $design_name,
					'alt'       => $design_name,
				]
			];
		}, 10, 3);
	}

	public function add_my_designs_endpoint(){
		add_rewrite_endpoint('my-designs', EP_ROOT | EP_PAGES);
	}

	public function shipping_init(){
		require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Classes/class-wc-shipping-printful-live.php';
	}

	public function register_shipping_method(array $methods): array{
		$methods['printful_live'] = 'WC_Shipping_Printful_Live';
		return $methods;
	}

	public function submit_order_if_needed($order_id, $from, $to){
		if (in_array($to, ['completed'], true)) {
			$this->pf_submit_order_if_needed($order_id);
		}
	}

	public function pf_submit_order_if_needed($order_id){
		if (get_post_meta($order_id, '_printful_order_id', true)) return;

		if (class_exists('Printful_AutoConfirm')) {
			Printful_AutoConfirm::pf_submit_order_if_needed($order_id);
		}
	}

	public function my_designs_content(){
		if (!is_user_logged_in()) {
			return;
		}

		$user_id = get_current_user_id();
		global $wpdb;
		$table_name = $wpdb->prefix . 'printful_designs';

		$designs = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_at DESC",
			$user_id
		));

		?>
		<div class="my-designs-section">
			<h3>My Designs</h3>
			<?php if (empty($designs)): ?>
				<p>You haven't created any designs yet.</p>
			<?php else: ?>
				<div class="designs-grid">
					<?php foreach ($designs as $design): ?>
						<?php
						$thumb = '';
						if ($design->mockup_url === null || $design->mockup_url === '') {
							$thumb = $this->update_db_mockup_url_value($design->id, $design->template_id);
						} else {
							$thumb = $design->mockup_url;
						}
						$designer_url = add_query_arg(
							array(
								'product_id'  => $design->product_id,
								'external_id' => $design->external_product_id,
							),
							site_url('/design/')
						);
						?>
						<div id="design-card-<?php echo $design->id; ?>" class="design-card" style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff">
							<img src="<?php echo esc_url($thumb); ?>" alt="" style="width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:8px;background:#f8fafc">
							<div style="margin-top:8px;"><strong><?php echo esc_html($design->design_name ?: 'Untitled'); ?></strong></div>
							<?php if (!empty($design->template_id)): ?>
								<div style="font-size:12px;color:#6b7280">ID: <?php echo esc_html($design->id); ?></div>
							<?php endif; ?>

							<?php if ($design->status === 'saved'): ?>
								<button type="button" class="add-to-cart-design pf-btn pf-btn--primary" data-design-id="<?php echo esc_attr($design->id); ?>" style="margin-top:8px">Add to Cart</button>
							<?php endif; ?>

							<button type="button"
								class="edit-design pf-btn"
								data-design-id="<?php echo (int)$design->id; ?>"
								style="margin-top:8px">
								Edit in Designer
							</button>

							<button type="button" class="delete-design pf-btn pf-btn--danger" data-design-id="<?php echo (int)$design->id; ?>">Delete</button>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function update_db_mockup_url_value($db_id, $template_id){
		$mockup_url = '';
		$tpl = $this->api->request('product-templates/' . $template_id, 'GET');
		if (is_array($tpl) && !empty($tpl['result']['mockup_file_url'])) {
			$mockup_url = esc_url_raw($tpl['result']['mockup_file_url']);
		}
		if (!empty($mockup_url)) {
			global $wpdb;
			$table = $wpdb->prefix . 'printful_designs';
			$wpdb->update(
				$table,
				array('mockup_url' => $mockup_url),
				array('id' => (int)$db_id),
				array('%s'),
				array('%d')
			);
		}
		return $mockup_url;
	}

	public function add_my_designs_menu_item($items){
		$new_items = [];

		foreach ($items as $key => $label) {
			$new_items[$key] = $label;

			if ($key === 'dashboard') {
				$new_items['my-designs'] = __('My Designs', 'textdomain');
			}
		}

		return $new_items;
	}
}
