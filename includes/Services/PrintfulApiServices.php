<?php
if (!defined('ABSPATH')) {
	exit;
}

final class PrintfulApi {

	protected $apiKey 	= PRINTFUL_API_KEY;
	protected $storeId 	= PRINTFUL_STORE_ID;
	protected $baseUrl	= 'https://api.printful.com/';

	public function request($endpoint, $method = 'GET', $data = null){
		if (empty($this->apiKey)) {
			error_log('Printful API Error: API key not configured');
			return false;
		}

		$url = $this->baseUrl . $endpoint;

		$args = array(
			'method' => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->apiKey,
				'Content-Type' => 'application/json',
				'X-PF-Store-Id' => $this->storeId
			),
			'timeout' => 15
		);

		if ($data && $method !== 'GET') {
			$args['body'] = json_encode($data);
		}

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			error_log('Printful API WP Error: ' . $response->get_error_message());
			return false;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		if ($response_code !== 200) {
			error_log('Printful API HTTP Error: ' . $response_code . ' - ' . wp_remote_retrieve_body($response));
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$decoded = json_decode($body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			error_log('Printful API JSON Error: ' . json_last_error_msg());
			return false;
		}

		return $decoded;
	}

}