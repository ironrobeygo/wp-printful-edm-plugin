<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/PrintfulApiServices.php';

final class EDMAuthentication{

	private $api;

	public function __construct(){
		
		$this->api = new PrintfulApi();

	}

	public function get_nonce_token($product_id, $variant_id = null, $external_product_id = '')
	{
		$payload = array(
			'external_product_id' => $external_product_id ? (string) $external_product_id : (string) $product_id,
			'external_customer_id' => (string)get_current_user_id() ?: null,
		);
		// if ( ! empty($_SERVER['REMOTE_ADDR']) )     { $payload['ip_address'] = sanitize_text_field($_SERVER['REMOTE_ADDR']); }
		// if ( ! empty($_SERVER['HTTP_USER_AGENT']) ) { $payload['user_agent'] = sanitize_text_field($_SERVER['HTTP_USER_AGENT']); }

		$response = $this->api->request('embedded-designer/nonces', 'POST', $payload);

		if (is_array($response) && isset($response['result']['nonce']['nonce']) && is_string($response['result']['nonce']['nonce'])) {
			return $response['result']['nonce']['nonce'];
		}
		return false;
	}
}