<?php
if (!defined('ABSPATH')) exit;

// If somehow loaded too early, bail gracefully (prevents fatals in edge cases)
if ( ! class_exists('WC_Shipping_Method') ) {
    return;
}

class WC_Shipping_Printful_Live extends WC_Shipping_Method {
  private $api_key;
  private $store_id;
  
  public function __construct($instance_id = 0) {
    $this->id                 = 'printful_live';
    $this->instance_id        = absint($instance_id);
    $this->method_title       = __('Printful Live Rates','pf');
    $this->method_description = __('Get live shipping quotes from Printful','pf');
    $this->enabled            = 'yes';
    $this->title              = __('Printful Shipping','pf');
    $this->supports           = ['shipping-zones','instance-settings'];
    $this->init();
  }
  public function init(){
    $this->instance_form_fields = []; // optionally: handling fee/markup
    $this->api_key = get_option('printful_api_key', '');
    $this->store_id = get_option('printful_store_id', '');
  }

  public function calculate_shipping($package = []) {
      $logger = wc_get_logger();
      $ctx = ['source' => 'printful_live'];

      // 1) Collect Printful lines (must have pf_item flag + variant_id)
      $items = [];
      foreach ($package['contents'] as $key => $line) {
          // Our add_to_cart meta should be at top level in the line array
          if (empty($line['pf_item'])) {
              continue;
          }
          $variant_id = isset($line['variant_id']) ? (int) $line['variant_id'] : 0;
          $qty        = (int) ($line['quantity'] ?? 0);
          if ($variant_id > 0 && $qty > 0) {
              $items[] = ['variant_id' => $variant_id, 'quantity' => $qty];
          }
      }

      if (!$items) {
          // Nothing to quote -> no rates returned
          $logger->warning('[PF] No PF items with variant_id in package; cannot quote', $ctx);
          return;
      }

      // 2) Build recipient address (Woo uses these keys)
      $dest = $package['destination'];
      $recipient = [
          'address1'     => (string)($dest['address']  ?? $dest['address_1'] ?? ''),
          'city'         => (string)($dest['city']     ?? ''),
          'state_code'   => (string)($dest['state']    ?? ''),   // 2-letter for US/CA
          'country_code' => (string)($dest['country']  ?? ''),
          'zip'          => (string)($dest['postcode'] ?? ''),
      ];

      // Print helpful logs
      $logger->info('[PF] Rates payload '. wp_json_encode([
          'recipient' => $recipient, 'items' => $items, 'currency' => get_woocommerce_currency()
      ]), $ctx);

      // 3) Call Printful v2 shipping rates
      // NOTE: make_api_request should prepend the base URL; ensure endpoint includes v2/
      $resp = $this->make_api_request('shipping/rates', 'POST', [
          'recipient' => $recipient,
          'items'     => $items,
          'currency'  => get_woocommerce_currency(),
      ]);

      if (empty($resp) || empty($resp['result'])) {
          $logger->warning('[PF] Rates API returned empty/error: '. wp_json_encode($resp), $ctx);
          return; // Woo will show "No shipping options"
      }

      // 4) Add each returned service as a Woo rate
      foreach ($resp['result'] as $rate) {
          $cost  = (float)($rate['rate'] ?? 0);
          $label = $rate['name'] ?? $rate['service'] ?? 'Shipping';
          if (isset($rate['minDeliveryDays'], $rate['maxDeliveryDays'])) {
              $label .= sprintf(' (%d–%d days)', (int)$rate['minDeliveryDays'], (int)$rate['maxDeliveryDays']);
          }

          $this->add_rate([
              'id'       => $this->id . ':' . sanitize_title($rate['id'] ?? $label),
              'label'    => $label,
              'cost'     => $cost,
              'meta_data'=> ['printful_service_code' => $rate['id'] ?? $rate['service'] ?? ''],
          ]);
      }

      $logger->info('[PF] Added '.count($resp['result']).' rate(s)', $ctx);
  }

    public function make_api_request($endpoint, $method = 'GET', $data = null) {
        if (empty($this->api_key)) {
            error_log('Printful API Error: API key not configured');
            return false;
        }
        
        $url = 'https://api.printful.com/' . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'X-PF-Store-Id' => $this->store_id
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

add_action('woocommerce_checkout_create_order', function($order){
  foreach ($order->get_shipping_methods() as $sm) {
    if ($sm->get_method_id() === 'printful_live') {
      $order->update_meta_data('printful_shipping_service', $sm->get_meta('printful_service_code') ?: $sm->get_method_id());
    }
  }
});