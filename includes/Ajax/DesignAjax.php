<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/PrintfulApiServices.php';

final class DesignAjax {

	private $api;
	private bool $registered = false;

	public function __construct(){
		$this->api = new PrintfulApi();
	}

	public function register(){
		if($this->registered) return;
		add_action('wp_ajax_save_design_draft',                     [$this, 'save_design_draft']);
		add_action('wp_ajax_add_design_to_cart',                    [$this, 'add_design_to_cart']);
		add_action('wp_ajax_get_design_data',                       [$this, 'get_design_data']);
		add_action('wp_ajax_add_saved_design_to_cart',              [$this, 'add_saved_design_to_cart']);
		add_action('wp_ajax_nopriv_printful_guest_save_template',   [$this, 'ajax_printful_guest_save_template']);
		add_action('wp_ajax_printful_guest_save_template',          [$this, 'ajax_printful_guest_save_template']);
		add_action('wp_ajax_printful_claim_draft',                  [$this, 'ajax_printful_claim_draft']);
		add_action('wp_ajax_printful_save_template',                [$this, 'ajax_printful_save_template']);
		add_action('wp_ajax_nopriv_printful_save_template',         [$this, 'ajax_printful_save_template']);
		add_action('wp_ajax_delete_design',                         [$this, 'delete_design']);

		$this->registered = true;
	}

	public function save_design_draft(){
		check_ajax_referer('printful_nonce', 'nonce');

		if (! is_user_logged_in()) {
			$redirect = function_exists('wc_get_page_permalink')
				? wc_get_page_permalink('myaccount')
				: wp_login_url();

			wp_send_json_error(array(
				'error'    => 'auth_required',
				'message'  => 'Please log in to save designs.',
				'redirect' => $redirect,
			));
			return;
		}

		$user_id  = get_current_user_id();
		$product_id = sanitize_text_field($_POST['product_id'] ?? '');
		$design_name = sanitize_text_field($_POST['design_name'] ?? 'Untitled Design');

		$template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
		$external_product_id = isset($_POST['external_product_id']) ? sanitize_text_field($_POST['external_product_id']) : '';

		if ($template_id && $external_product_id) {
			$mockup_url = '';
			$tpl = $this->api->request('product-templates/' . $template_id, 'GET');
			if (is_array($tpl) && !empty($tpl['result']['mockup_file_url'])) {
				$mockup_url = esc_url_raw($tpl['result']['mockup_file_url']);
			}

			$row_id = $this->save_or_update_design_row(
				$user_id,
				$product_id,
				$design_name,
				'draft',
				$external_product_id,
				$template_id,
				$mockup_url
			);
			wp_send_json_success(array('message' => 'Saved (EDM)', 'design_id' => $row_id, 'template_id' => $template_id));
			return;
		}

		$design_data = sanitize_textarea_field($_POST['design_data'] ?? '');
		if (empty($design_data)) {
			wp_send_json_error('Missing template_id/external_product_id (EDM) and no legacy design_data provided');
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'printful_designs';
		$result = $wpdb->insert(
			$table,
			array(
				'user_id'     => $user_id,
				'product_id'  => $product_id,
				'design_data' => $design_data,
				'design_name' => $design_name,
				'status'      => 'draft'
			),
			array('%d', '%s', '%s', '%s', '%s')
		);

		if ($result) {
			wp_send_json_success('Design saved as draft (legacy)');
		} else {
			wp_send_json_error('Failed to save design');
		}
	}

	public function add_design_to_cart(){
		check_ajax_referer('printful_nonce', 'nonce');

		if (! is_user_logged_in()) {
			$redirect = function_exists('wc_get_page_permalink')
				? wc_get_page_permalink('myaccount')
				: wp_login_url();

			wp_send_json_error(array(
				'error'    => 'auth_required',
				'message'  => 'Please log in to save designs.',
				'redirect' => $redirect,
			));
			return;
		}

		$container_product_id = (int) get_option('pf_container_product_id', 0);
		if (!$container_product_id) {
			wp_send_json_error('Container product not configured');
			return;
		}

		$unit_raw = isset($_POST['unit_price']) ? (float) $_POST['unit_price'] : 0;
		$currency = sanitize_text_field($_POST['currency'] ?? get_woocommerce_currency());

		$variant_id = isset($_POST['variant_id']) ? (int) $_POST['variant_id'] : 0;
		$template_id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
		$external_product_id = sanitize_text_field($_POST['external_product_id'] ?? '');

		$design_name = sanitize_text_field($_POST['design_name'] ?? 'Untitled Design');
		$mockup_url  = ''; // optional lookup if you have it
		$design_category = ''; // set from your product type if available

		$meta = array(
			'pf_item'             => 1,
			'design_name'         => $design_name,
			'product_id'          => (int)($_POST['product_id'] ?? 0), // Printful catalog product id
			'variant_id'          => $variant_id,
			'template_id'         => $template_id,
			'external_product_id' => $external_product_id,
			'mockup_url'          => $mockup_url,
			'design_category'     => $design_category,
			'unit_price'          => (float) $unit_raw,
			'currency'            => $currency,
			'unique_key'          => md5(get_current_user_id() . $template_id . microtime(true))
		);

		$key = WC()->cart->add_to_cart($container_product_id, 1, 0, array(), $meta);

		if (!$key) {
			wp_send_json_error('add_to_cart_failed');
			return;
		}
		wp_send_json_success(array('redirect' => wc_get_cart_url()));
	}

	public function add_saved_design_to_cart(){
		if (! check_ajax_referer('printful_nonce', 'nonce', false)) {
			wp_send_json_error('invalid_nonce');
			return;
		}

		if (! is_user_logged_in()) {
			$redirect = function_exists('wc_get_page_permalink')
				? wc_get_page_permalink('myaccount')
				: wp_login_url();

			wp_send_json_error(array(
				'error'    => 'auth_required',
				'message'  => 'Please log in to save designs.',
				'redirect' => $redirect,
			));
			return;
		}

		$user_id   = get_current_user_id();
		$design_id = isset($_POST['design_id']) ? (int) $_POST['design_id'] : 0;
		if (!$design_id) {
			wp_send_json_error('missing_design_id');
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'printful_designs';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND user_id = %d LIMIT 1",
				$design_id,
				$user_id
			),
			ARRAY_A
		);

		if (! $row) {
			wp_send_json_error('design_not_found');
			return;
		}

		$pf_product_id       	= (int)($row['product_id'] ?? 0);             // Printful catalog product id
		$pf_variant_id       	= (int)($row['variant_id'] ?? 0);             // Prefer having this stored when saved
		$template_id         	= (int)($row['template_id'] ?? 0);
		$external_product_id 	= (string)($row['external_product_id'] ?? '');
		$design_name         	= (string)($row['design_name'] ?? 'Saved Design');
		$mockup_url          	= (string)($row['mockup_url'] ?? '');         // might be empty if mockup still rendering
		$design_category     	= (string)($row['design_category'] ?? ($row['category'] ?? '')); // support either column name

		$unit_raw 				= (float)$row['unit_price'];
		$currency 				= sanitize_text_field($_POST['currency'] ?? get_woocommerce_currency());

		$markup_pct 			= (float) get_option('pf_markup_pct', 0);
		$markup_fix 			= (float) get_option('pf_markup_fix', 0);
		$final_price 			= $unit_raw * (1 + $markup_pct / 100.0) + $markup_fix;

		$container_product_id 	= (int) get_option('pf_container_product_id', 0);
		if (! $container_product_id) {
			wp_send_json_error('container_not_configured');
			return;
		}

		$cart_meta = [
			'pf_item'             => 1,
			'design_id'           => (int)$row['id'],
			'design_name'         => $design_name,
			'product_id'          => $pf_product_id,
			'variant_id'          => $pf_variant_id,
			'template_id'         => $template_id,
			'external_product_id' => $external_product_id,
			'mockup_url'          => esc_url_raw($mockup_url),
			'design_category'     => $design_category,
			'unit_price'          => (float)$final_price,
			'currency'            => $currency,
			'unique_key'          => md5($user_id . $row['id'] . microtime(true)),
		];

		$key = WC()->cart->add_to_cart($container_product_id, 1, 0, [], $cart_meta);
		if (! $key) {
			wp_send_json_error('add_to_cart_failed');
			return;
		}

		pf_set_flash_notice('Design added to your cart.', 'success');
		wp_send_json_success(['redirect' => wc_get_cart_url()]);
	}

	public function get_design_data(){
		check_ajax_referer('printful_nonce', 'nonce');

		if (! is_user_logged_in()) {
			$redirect = function_exists('wc_get_page_permalink')
				? wc_get_page_permalink('myaccount')
				: wp_login_url();

			wp_send_json_error(array(
				'error'    => 'auth_required',
				'message'  => 'Please log in to save designs.',
				'redirect' => $redirect,
			));
			return;
		}

		$design_id = intval($_POST['design_id']);
		$user_id = get_current_user_id();

		global $wpdb;
		$table_name = $wpdb->prefix . 'printful_designs';

		$design = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
			$design_id,
			$user_id
		));

		if ($design) {
			wp_send_json_success($design);
		} else {
			wp_send_json_error('Design not found');
		}
	}

	public function add_design_product_endpoint(){
		add_rewrite_rule('^design/?', 'index.php?design=1', 'top');
		add_rewrite_tag('%design%', '([^&]+)');
	}

	public function add_my_designs_menu_item($items){
		$new_items = [];

		foreach ($items as $key => $label) {
			$new_items[$key] = $label;

			// Insert after Dashboard
			if ($key === 'dashboard') {
				$new_items['my-designs'] = __('My Designs', 'textdomain');
			}
		}

		return $new_items;
	}

	public function ajax_printful_claim_draft(){
		check_ajax_referer('printful_nonce', 'nonce');

		if (!is_user_logged_in()) {
			wp_send_json_error(['error' => 'auth_required', 'message' => 'Please log in.']);
		}

		$token = sanitize_text_field($_POST['token'] ?? '');
		if (!$token) {
			wp_send_json_error(['error' => 'bad_request', 'message' => 'Missing token.']);
		}

		$key  = 'pf_guest_draft_' . $token;
		$data = get_transient($key);
		if (!$data || empty($data['template_id']) || empty($data['external_product_id'])) {
			wp_send_json_error(['error' => 'not_found', 'message' => 'Draft expired or already claimed.']);
		}

		$user_id   = get_current_user_id();
		$product_id = (int) ($data['product_id'] ?? 0);
		$row_id = $this->save_or_update_design_row(
			$user_id,
			$product_id,
			(string) ($data['design_name'] ?? 'Untitled Design'),
			'saved',
			$data['external_product_id'],
			(int) $data['template_id'],
			(string) ($data['mockup_url'] ?? ''),
			(float) $data['unit_price'],
			(string) $data['currency'],
			(int) $data['variant_id'],
		);

		delete_transient($key);
		if (!headers_sent()) {
			setcookie('pf_draft', '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), false);
		}

		$account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/');
		$my_designs_url = function_exists('wc_get_endpoint_url')
			? wc_get_endpoint_url('my-designs', '', $account)
			: $account;

		wp_send_json_success([
			'row_id'   => (int) $row_id,
			'redirect' => $my_designs_url,
			'message'  => 'Your draft has been saved to My Designs.',
		]);
	}

	public function ajax_printful_guest_save_template(){
		check_ajax_referer('printful_nonce', 'nonce');

		$product_id             = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		$store_id               = sanitize_text_field($_POST['store_id'] ?? '');
		$template_id            = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
		$external_product_id    = sanitize_text_field($_POST['external_product_id'] ?? '');
		$design_name            = sanitize_text_field($_POST['design_name'] ?? 'Untitled Design');
		$unit_price             = isset($_POST['unit_price']) ? (float) $_POST['unit_price'] : null;
		$currency               = sanitize_text_field($_POST['currency'] ?? '');
		$variant_id             = isset($_POST['variant_id']) ? (int) $_POST['variant_id'] : 0;

		if (!$template_id || empty($external_product_id)) {
			wp_send_json_error(['error' => 'bad_request', 'message' => 'Missing template data.']);
		}

		$mockup_url = '';
		$tpl = $this->api->request('product-templates/' . $template_id, 'GET');
		if (is_array($tpl) && !empty($tpl['result']['mockup_file_url'])) {
			$mockup_url = esc_url_raw($tpl['result']['mockup_file_url']);
		}

		$token = wp_generate_uuid4();
		$data  = [
			'product_id'            => $product_id,
			'design_name'           => $design_name,
			'status'                => 'saved',
			'external_product_id'   => $external_product_id,
			'template_id'           => $template_id,
			'mockup_url'            => $mockup_url,
			'unit_price'            => ($unit_price !== null ? $unit_price : null),
			'currency'              => ($currency ?: null),
			'variant_id'            => ($variant_id ?: null),
			'created'               => time(),
		];

		set_transient('pf_guest_draft_' . $token, $data, 6 * HOUR_IN_SECONDS);

		if (!headers_sent()) {
			setcookie('pf_draft', $token, time() + 6 * HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), false);
		}

		$login_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
		$login_url = add_query_arg('pf_draft', rawurlencode($token), $login_url);

		wp_send_json_success([
			'token'    => $token,
			'redirect' => $login_url,
			'message'  => 'Draft saved temporarily. Please log in to continue.',
		]);
	}

	public function ajax_printful_save_template(){
		check_ajax_referer('printful_nonce', 'nonce');

		if (!is_user_logged_in()) {
			$redirect = (function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url());
			wp_send_json_error(array(
				'error'   => 'auth_required',
				'message' => 'Please log in to save designs!',
				'redirect' => $redirect,
			));
		}

		$user_id                = get_current_user_id();
		$product_id             = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		$template_id            = sanitize_text_field($_POST['template_id'] ?? '');
		$external_product_id    = sanitize_text_field($_POST['external_product_id'] ?? '');
		$design_name            = sanitize_text_field($_POST['design_name'] ?? '');
		$unit_price             = isset($_POST['unit_price']) ? (float) $_POST['unit_price'] : null;
		$currency               = sanitize_text_field($_POST['currency'] ?? '');
		$variant_id             = isset($_POST['variant_id']) ? (int) $_POST['variant_id'] : 0;
		$replace_id             = isset($_POST['replace_id']) ? (int) $_POST['replace_id'] : 0;

		if (!$template_id || !$external_product_id) {
			wp_send_json_error('Missing template_id or external_product_id');
		}

		$mockup_url = '';
		$tpl = $this->api->request('product-templates/' . $template_id, 'GET');
		if (is_array($tpl) && !empty($tpl['result']['mockup_file_url'])) {
			$mockup_url = esc_url_raw($tpl['result']['mockup_file_url']);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'printful_designs';

		$existing_id = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM $table WHERE user_id=%d AND external_product_id=%s",
			$user_id,
			$external_product_id
		));

		$data = [
			'user_id'               => $user_id,
			'product_id'            => $product_id,
			'design_data'           => null,
			'design_name'           => $design_name,
			'status'                => 'saved',
			'external_product_id'   => $external_product_id,
			'template_id'           => $template_id,
			'mockup_url'            => $mockup_url,
			'unit_price'            => ($unit_price !== null ? $unit_price : null),
			'currency'              => ($currency ?: null),
			'variant_id'            => ($variant_id ?: null),
			'last_saved'            => current_time('mysql'),
		];
		$formats = array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%s', '%d', '%s');

		if ($replace_id) {
			$owner = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE id = %d", $replace_id));
			if ($owner && $owner === (int)$user_id) {
				$wpdb->update($table, $data, array('id' => $replace_id), $formats, array('%d'));
				$redirect = function_exists('wc_get_account_endpoint_url')
					? wc_get_account_endpoint_url('my-designs')
					: (function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/'));
				wp_send_json_success(array(
					'id'          => $replace_id,
					'template_id' => $template_id,
					'redirect'    => $redirect,
				));
			}
		}

		$redirect = function_exists('wc_get_account_endpoint_url')
			? wc_get_account_endpoint_url('my-designs')
			: site_url('/my-account/my-designs/');

		if ($existing_id) {
			$wpdb->update($table, $data, array('id' => (int)$existing_id), $formats, array('%d'));
			wp_send_json_success(array(
				'id'          => (int)$existing_id,
				'template_id' => $template_id,
				'redirect'    => $redirect,
			));
		} else {
			$wpdb->insert($table, $data, $formats);
			wp_send_json_success(array(
				'id'          => (int)$wpdb->insert_id,
				'template_id' => $template_id,
				'redirect'    => $redirect,
			));
		}
	}

	public function delete_design(){
		check_ajax_referer('printful_nonce', 'nonce');

		if (! is_user_logged_in()) {
			$redirect = function_exists('wc_get_page_permalink')
				? wc_get_page_permalink('myaccount')
				: wp_login_url();

			wp_send_json_error(array(
				'error'    => 'auth_required',
				'message'  => 'Please log in to save designs.',
				'redirect' => $redirect,
			));
			return;
		}

		$design_id = isset($_POST['design_id']) ? intval($_POST['design_id']) : 0;
		if (!$design_id) {
			wp_send_json_error('Missing design_id');
		}

		$user_id = get_current_user_id();
		global $wpdb;
		$table = $wpdb->prefix . 'printful_designs';

		$deleted = $wpdb->delete($table, array('id' => $design_id, 'user_id' => $user_id), array('%d', '%d'));

		if ($deleted !== false) {
			wp_send_json_success(array('deleted' => (int)$deleted));
		} else {
			wp_send_json_error('Delete failed');
		}
	}

	private function save_or_update_design_row($user_id, $product_id, $design_name, $status, $external_product_id, $template_id, $mockup_url = '', $unit_price = null, $currency = '', $variant_id = 0){
		global $wpdb;
		$table = $wpdb->prefix . 'printful_designs';

		$existing_id = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM $table WHERE user_id=%d AND external_product_id=%s",
			$user_id,
			$external_product_id
		));

		$data = array(
			'user_id'            => $user_id,
			'product_id'         => $product_id,
			'design_data'        => null, // legacy field not used for EDM
			'design_name'        => $design_name ?: 'Untitled Design',
			'status'             => $status,
			'external_product_id' => $external_product_id,
			'template_id'        => $template_id ?: null,
			'mockup_url'         => $mockup_url ?: null,
			'unit_price'        => ($unit_price !== null ? $unit_price : null),
			'currency'          => ($currency ?: null),
			'variant_id'        => ($variant_id ?: null),
			'last_saved'         => current_time('mysql'),
		);
		$formats = array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%s', '%d', '%s');

		if ($existing_id) {
			$wpdb->update($table, $data, array('id' => (int)$existing_id), $formats, array('%d'));
			return (int)$existing_id;
		} else {
			$wpdb->insert($table, $data, $formats);
			return (int)$wpdb->insert_id;
		}
	}

}