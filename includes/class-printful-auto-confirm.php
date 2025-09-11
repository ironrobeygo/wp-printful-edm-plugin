<?php
// ========== FILE: class-printful-auto-confirm.php ==========
// Drop this file next to your main plugin file (printful-catalog.php)
// Then, in printful-catalog.php, add:
//   require_once __DIR__ . '/class-printful-auto-confirm.php';
//   add_action('plugins_loaded', ['Printful_AutoConfirm', 'init']);

if (!defined('ABSPATH')) { exit; }

class Printful_AutoConfirm {
    // You can flip this via an option if you want a UI toggle later
    const ENABLED_DEFAULT = true;
    private static $booted = false;

    // Backoff (minutes) for cron retries when Printful hasn’t finished costs/printfiles yet
    private static $RETRY_MINUTES = [1, 4, 16, 64, 256];

    // ===== Public bootstrap =====
    public static function init() {
        if (self::$booted) return;
        self::$booted = true;

        // REST route to receive webhooks (v2 webhooks recommended)
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Automatically send/confirm after Woo order becomes processing
        // Retry hooks
        add_action('printful_autoconfirm_retry', [__CLASS__, 'retry_job'], 10, 2);
        add_action('printful_autoconfirm_retry_event', [__CLASS__, 'retry_job'], 10, 2);
    }

    // ===== Woo entrypoint =====
    public static function hook_wc_processing($order_id) {
        // don’t duplicate
        if (get_post_meta($order_id, '_printful_order_id', true)) return;
        try {
            self::pf_submit_order_if_needed($order_id);
        } catch (\Throwable $e) {
            error_log('[Printful_AutoConfirm] '.$e->getMessage());
        }
    }

    public static function pf_submit_order_if_needed($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // already sent?
        if ($order->get_meta('printful_order_id')) return;

        // Collect PF line items
        $items_payload = [];
        foreach ($order->get_items('line_item') as $item_id => $item) {
            // We only push our container-product lines
            $variant_id = (int) $item->get_meta('variant_id', true);
            if ($variant_id <= 0) continue;

            $qty          = (int) $item->get_quantity();
            $template_id  = (int) $item->get_meta('template_id', true);          // saved from EDM
            $external_pid = (string) $item->get_meta('external_product_id', true);
            $design_name  = (string) $item->get_meta('design_name', true);

            $one = [
                'variant_id' => $variant_id,
                'quantity'   => max(1, $qty),
                // Optional: name/note shows up in PF dashboard
                'name'       => $design_name ?: null,
            ];

            if ($template_id > 0) {
                $one['product_template_id'] = $template_id;
            }

            $items_payload[] = array_filter($one, static fn($v) => $v !== null && $v !== '');
        }

        if (empty($items_payload)) {
            $order->add_order_note('Printful: No PF items found (missing variant_id) — not submitted.');
            return;
        }

        // Recipient from shipping (fallback to billing)
        $rc_name  = trim($order->get_shipping_first_name().' '.$order->get_shipping_last_name()) ?: trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
        $rc_email = $order->get_billing_email();
        $recipient = [
            'name'         => $rc_name,
            'phone'        => $order->get_billing_phone(),
            'email'        => $rc_email,
            'address1'     => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'address2'     => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
            'city'         => $order->get_shipping_city()      ?: $order->get_billing_city(),
            'state_code'   => $order->get_shipping_state()     ?: $order->get_billing_state(),  // use codes (CA, NY, ON)
            'country_code' => $order->get_shipping_country()   ?: $order->get_billing_country(),
            'zip'          => $order->get_shipping_postcode()  ?: $order->get_billing_postcode(),
        ];

        // Shipping service (we stored it earlier when customer picked a rate)
        $service = (string) $order->get_meta('printful_shipping_service');

        // Build Printful order payload
        $payload = [
            'external_id' => (string)$order->get_id(), // your reference
            'recipient'   => $recipient,
            'items'       => $items_payload,
            // If you want PF to place the order immediately:
            'confirm'     => true,
        ];
        if ($service) {
            // Printful accepts a string code for shipping service (e.g., "STANDARD", "EXPRESS")
            $payload['shipping'] = $service;
        }

        // Optional: packing slip
        $payload['packing_slip'] = [
            'email' => 'digital@droneit.com.au',
            'message' => 'Thank you for your order!',
            'phone' => '',
        ];

        error_log(wp_json_encode($payload));

        // ---- Send to Printful ----
        $logger = wc_get_logger();
        $ctx = ['source' => 'printful_order_push'];

        try {
            // orders endpoint (do NOT prefix with v2)
            $res = self::make_api_request('orders', 'POST', $payload); // if you made it public

            if (!$res || empty($res['result'])) {
                $order->add_order_note('Printful: create failed (empty response). See debug log.');
                $logger->warning('PF create order failed: '. wp_json_encode($res), $ctx);
                return;
            }

            // Printful responds with 'id' and 'status' in result
            $pf = $res['result'];
            $pf_id     = $pf['id']     ?? null;
            $pf_status = $pf['status'] ?? null;

            if ($pf_id) {
                $order->update_meta_data('printful_order_id', $pf_id);
                if ($pf_status) $order->update_meta_data('printful_status', $pf_status);
                $order->add_order_note(sprintf('Printful: order created (PF #%s, status: %s)', $pf_id, $pf_status ?: 'unknown'));
                $order->save();
            } else {
                $order->add_order_note('Printful: create returned without id. See log.');
                $logger->warning('PF create order missing id: '. wp_json_encode($res), $ctx);
            }

        } catch (\Throwable $e) {
            $order->add_order_note('Printful: create error — '.$e->getMessage());
            $logger->error('PF create exception: '.$e->getMessage(), $ctx);
        }
    }

    // ===== Retry glue =====
    public static function retry_job($pf_id, $attempt) {
        $token    = trim((string) get_option('printful_api_key'));
        $store_id = (int) get_option('printful_store_id');
        $flow     = get_transient('pf_flow_'.$pf_id) ?: 'v2';
        try {
            if ($flow === 'v1') {
                $r = self::http_v1('POST', "/orders/{$pf_id}/confirm", null, $token, $store_id);
                if (self::was_v1_confirm_ok($r)) return; // success, stop
            } else {
                $r = self::http_v2('POST', "/v2/orders/{$pf_id}/confirmation", null, $token, $store_id);
                if (self::was_v2_confirm_ok($r)) return;
            }
        } catch (\Throwable $e) {
            // keep retrying
        }
        self::schedule_retry($pf_id, (int)$attempt + 1);
    }

    private static function try_confirm_later($pf_id, $attempt, $token, $store_id, $flow) {
        // Remember which flow this order used so our retry knows which endpoint to hit
        set_transient('pf_flow_'.$pf_id, $flow, 3 * DAY_IN_SECONDS);
        self::schedule_retry($pf_id, $attempt);
    }

    private static function schedule_retry($pf_id, $attempt) {
        $idx   = min($attempt, count(self::$RETRY_MINUTES) - 1);
        $delay = (int) self::$RETRY_MINUTES[$idx] * 60;
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, 'printful_autoconfirm_retry', [ 'pf_id' => (string)$pf_id, 'attempt' => (int)$attempt ], 'printful');
        } else {
            wp_schedule_single_event(time() + $delay, 'printful_autoconfirm_retry_event', [ (string)$pf_id, (int)$attempt ]);
        }
    }

    // ===== Webhook (optional fast‑path) =====
    public static function register_routes() {
        register_rest_route('printful/v2', '/webhook', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'webhook_handler'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function webhook_handler(\WP_REST_Request $req) {
        $raw = $req->get_body();
        // If you configured v2 webhooks, headers will be present; if not, skip validation.
        $sig = $req->get_header('x-pf-webhook-signature');
        $pub = $req->get_header('x-pf-webhook-public-key');
        $cfg_pub = trim((string) get_option('pf_webhook_public_key'));
        $cfg_hex = trim((string) get_option('pf_webhook_secret_hex'));

        if ($sig && $pub) {
            if (!$cfg_pub || !$cfg_hex || $pub !== $cfg_pub) {
                return new \WP_REST_Response(['ok' => false, 'reason' => 'Webhook keys not set or mismatch'], 400);
            }
            $secret_bin = @hex2bin($cfg_hex);
            if ($secret_bin === false) return new \WP_REST_Response(['ok' => false, 'reason' => 'Bad secret hex'], 400);
            $calc_hex = hash_hmac('sha256', $raw, $secret_bin);
            if (!hash_equals(strtolower($sig), strtolower($calc_hex))) {
                return new \WP_REST_Response(['ok' => false, 'reason' => 'Bad signature'], 403);
            }
        }

        $payload = json_decode($raw, true);
        if (!$payload) return new \WP_REST_Response(['ok' => true], 200);

        // v2 shape: { type: 'order_updated', data: { order: { id, status, costs: {calculation_status} }}} etc.
        $type  = $payload['type'] ?? '';
        $order = $payload['data']['order'] ?? null;
        if ($order && in_array($type, ['order_created','order_updated','order_failed'], true)) {
            $pf_id  = $order['id'] ?? null;
            $status = strtolower($order['status'] ?? '');
            $calc   = strtolower($order['costs']['calculation_status'] ?? '');
            if ($pf_id && $status === 'draft' && $calc !== 'calculating') {
                // Try v2 confirm first; if it fails, try v1
                $token    = trim((string) get_option('printful_api_key'));
                $store_id = (int) get_option('printful_store_id');
                $r = self::http_v2('POST', "/v2/orders/{$pf_id}/confirmation", null, $token, $store_id);
                if (!self::was_v2_confirm_ok($r)) {
                    self::http_v1('POST', "/orders/{$pf_id}/confirm", null, $token, $store_id);
                }
            }
        }
        return new \WP_REST_Response(['ok' => true], 200);
    }

    public static function make_api_request($endpoint, $method = 'GET', $data = null) {
        $api_key = trim((string) get_option('printful_api_key'));
        $store_id = (int) get_option('printful_store_id');
        
        if (empty($api_key)) {
            error_log('Printful API Error: API key not configured');
            return false;
        }
        
        $url = 'https://api.printful.com/' . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'X-PF-Store-Id' => $store_id
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

    // ===== HTTP helpers =====
    private static function http_v1($method, $path, $body, $token, $store_id) {
        $url = 'https://api.printful.com' . $path;
        if ($method === 'GET' && is_array($body) && !empty($body)) {
            $url = add_query_arg($body, $url);
            $body = null;
        }
        $headers = [ 'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Content-Type' => 'application/json' ];
        if ($store_id) $headers['X-PF-Store-Id'] = (string)$store_id; // required for account-level tokens per docs
        $resp = wp_remote_request($url, [ 'method' => $method, 'headers' => $headers, 'timeout' => 30, 'body' => is_null($body) ? null : wp_json_encode($body) ]);
        return self::normalize_response($resp);
    }

    private static function http_v2($method, $path, $body, $token, $store_id) {
        $url = 'https://api.printful.com' . $path;
        $headers = [ 'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Content-Type' => 'application/json' ];
        if ($store_id) $headers['X-PF-Store-Id'] = (string)$store_id;
        $resp = wp_remote_request($url, [ 'method' => $method, 'headers' => $headers, 'timeout' => 30, 'body' => is_null($body) ? null : wp_json_encode($body) ]);
        return self::normalize_response($resp);
    }

    private static function normalize_response($resp) {
        if (is_wp_error($resp)) return [ 'ok' => false, 'code' => 0, 'error' => $resp->get_error_message() ];
        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);
        return [ 'ok' => $code >= 200 && $code < 300, 'code' => $code, 'json' => $json, 'raw' => $raw ];
    }

    private static function extract_v1_order_id($resp) {
        $j = $resp['json']['result'] ?? null;
        if (isset($j['id'])) return (int)$j['id'];
        if (isset($j['order']['id'])) return (int)$j['order']['id'];
        return null;
    }
    private static function extract_v1_status($resp) {
        $j = $resp['json']['result'] ?? null;
        return is_array($j) ? strtolower($j['status'] ?? ($j['order']['status'] ?? '')) : '';
    }

    private static function extract_v2_order_id($resp) {
        $j = $resp['json']['data'] ?? null;
        return isset($j['id']) ? (int)$j['id'] : null;
    }

    private static function was_v2_confirm_ok($resp) {
        // v2 may return 204 No Content
        if (!$resp) return false;
        if ((int)$resp['code'] === 204) return true;
        // Some builds may return order JSON; treat 200 with order status != draft as success
        $j = $resp['json']['data'] ?? null;
        if ($j && isset($j['status']) && strtolower($j['status']) !== 'draft') return true;
        return false;
    }
    private static function was_v1_confirm_ok($resp) {
        if (!$resp) return false;
        if ((int)$resp['code'] === 200 || (int)$resp['code'] === 204) return true;
        return false;
    }
}