<?php
/**
 * File: class-printful-webhook-admin.php
 * Purpose: Adds a Settings page (and reusable button renderer) to subscribe/rotate
 *          Printful Webhooks v2 and store the public key + secret hex.
 *
 * How to wire:
 *   require_once __DIR__ . '/class-printful-webhook-admin.php';
 *   if (did_action('plugins_loaded')) { Printful_Webhook_Admin::init(); }
 *   else { add_action('plugins_loaded', ['Printful_Webhook_Admin','init']); }
 *
 * Requirements:
 *   - Option 'printful_api_key' must contain your Bearer token
 *   - Option 'printful_store_id' must contain your Store ID (if using account-level token)
 *   - Your webhook endpoint (already in your plugin): /wp-json/printful/v2/webhook
 */

if (!defined('ABSPATH')) { exit; }

class Printful_Webhook_Admin {
    const PAGE_SLUG = 'printful-webhooks';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_pf_subscribe_webhooks', [__CLASS__, 'handle_subscribe']);
        add_action('admin_post_pf_clear_webhooks', [__CLASS__, 'handle_clear']);
    }

    /**
     * Add a Settings → Printful Webhooks page
     */
    public static function register_menu() {
        add_options_page(
            __('Printful Webhooks', 'printful'),
            __('Printful Webhooks', 'printful'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Reusable renderer: you can call this inside your existing settings page
     * to show just the subscribe widget instead of using a separate page.
     */
    public static function render_subscribe_button() {
        if (!current_user_can('manage_options')) return;
        self::render_inner(false); // no wrapping page
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Printful Webhooks', 'printful') . '</h1>';

        // Admin notices
        if (isset($_GET['pf_msg'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['pf_msg']));
            $err = isset($_GET['pf_err']) ? esc_html(wp_unslash($_GET['pf_err'])) : '';
            if ($msg === 'ok') {
                echo '<div class="notice notice-success"><p>Subscribed. Keys saved.</p></div>';
            } elseif ($msg === 'cleared') {
                echo '<div class="notice notice-success"><p>Saved keys cleared.</p></div>';
            } elseif ($msg === 'missing_token') {
                echo '<div class="notice notice-error"><p>Missing API token. Save the Printful token first.</p></div>';
            } elseif ($msg === 'missing_keys') {
                echo '<div class="notice notice-error"><p>Subscribe response did not contain keys. '
                    . ($err ? '<code>'.$err.'</code>' : '') . '</p></div>';
            } elseif ($msg === 'err') {
                echo '<div class="notice notice-error"><p>Subscribe failed: '
                    . ($err ? '<code>'.$err.'</code>' : '') . '</p></div>';
            }
        }

        self::render_inner(true);
        echo '</div>';
    }

    private static function render_inner($wrap_card = true) {
        $token    = trim((string) get_option('printful_api_key'));
        $store_id = trim((string) get_option('printful_store_id'));
        $pub      = trim((string) get_option('pf_webhook_public_key'));
        $secret   = trim((string) get_option('pf_webhook_secret_hex'));

        // Allow overriding the endpoint (e.g., ngrok https URL)
        $endpoint = esc_url( apply_filters(
            'pf_webhook_default_url',
            home_url('/wp-json/printful/v2/webhook')
        ));

        if ($wrap_card) echo '<div class="card" style="max-width:900px">';

        echo '<p>'.esc_html__('Use this tool to subscribe your store to Printful Webhooks v2 and save the verification keys.', 'printful').'</p>';

        if (!$token) {
            echo '<div class="notice notice-error"><p>Missing API token (option: <code>printful_api_key</code>).</p></div>';
        }
        if (!$store_id) {
            echo '<div class="notice notice-warning"><p>Store ID (option: <code>printful_store_id</code>) is empty. '
            .'If your token is account-level, this header is required.</p></div>';
        }
        if (strpos($endpoint, 'https://') !== 0) {
            echo '<div class="notice notice-warning"><p>Your webhook URL is not HTTPS or publicly accessible. '
            .'Use an HTTPS URL (e.g., via ngrok) so Printful can deliver events.</p></div>';
        }

        echo '<table class="widefat striped" style="margin-top:10px"><tbody>';
        echo '<tr><th style="width:240px">Webhook Endpoint</th><td><code>'.$endpoint.'</code></td></tr>';
        echo '<tr><th>Public Key</th><td>'.($pub ? '<code>'.esc_html($pub).'</code>' : '<em>— not set —</em>').'</td></tr>';
        echo '<tr><th>Secret (HEX)</th><td>'.($secret ? '<code>'.esc_html(self::mask_hex($secret)).'</code>' : '<em>— not set —</em>').'</td></tr>';
        echo '</tbody></table>';

        // --- Separate forms so nonces don't collide ---

        // Subscribe form
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block; margin-top:15px; margin-right:8px">';
        wp_nonce_field('pf_subscribe_webhooks');
        echo '<input type="hidden" name="action" value="pf_subscribe_webhooks" />';
        echo '<button type="submit" class="button button-primary">Subscribe / Rotate Keys</button>';
        echo '</form>';

        // Refresh link
        echo '<a class="button" style="margin-top:15px; margin-right:8px" href="'.esc_url(add_query_arg(['pf_test'=>1])).'">Refresh Status</a>';

        // Clear form
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block; margin-top:15px">';
        wp_nonce_field('pf_clear_webhooks');
        echo '<input type="hidden" name="action" value="pf_clear_webhooks" />';
        echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'Clear saved keys?\')">Clear Saved Keys</button>';
        echo '</form>';

        // Optional live check of current config
        if (isset($_GET['pf_test'])) {
            $cfg = self::get_current_config($token, $store_id);
            echo '<h2>Live Config (GET /v2/webhooks)</h2>';
            echo '<pre style="white-space:pre-wrap">'.esc_html(json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
        }

        if ($wrap_card) echo '</div>';
    }

    public static function handle_clear() {
        if (!current_user_can('manage_options')) wp_die('nope');
        check_admin_referer('pf_clear_webhooks');
        delete_option('pf_webhook_public_key');
        delete_option('pf_webhook_secret_hex');
        wp_safe_redirect(add_query_arg('pf_msg', 'cleared', wp_get_referer() ?: admin_url('options-general.php?page='.self::PAGE_SLUG)));
        exit;
    }

    public static function handle_subscribe() {
        if (!current_user_can('manage_options')) wp_die('nope');
        check_admin_referer('pf_subscribe_webhooks');

        $token    = trim((string) get_option('printful_api_key'));
        $store_id = trim((string) get_option('printful_store_id'));
        if (!$token) {
            wp_safe_redirect(add_query_arg('pf_msg','missing_token', wp_get_referer() ?: admin_url('options-general.php?page='.self::PAGE_SLUG)));
            exit;
        }

        $default_url = apply_filters('pf_webhook_default_url', home_url('/wp-json/printful/v2/webhook'));
        $body = [
            'default_url' => $default_url,
            'events' => [
                ['type' => 'order_created'],
                ['type' => 'order_updated'],
            ],
        ];

        $resp = self::http_v2('POST', '/v2/webhooks', $body, $token, $store_id);
        if (!$resp['ok']) {
            $msg = 'HTTP '.$resp['code'].': '.substr($resp['raw'] ?? '', 0, 300);
            wp_safe_redirect(add_query_arg(['pf_msg'=>'err','pf_err'=>rawurlencode($msg)], wp_get_referer() ?: admin_url('options-general.php?page='.self::PAGE_SLUG)));
            exit;
        }

        $json   = $resp['json'];
        $result = $json['result'] ?? $json['data'] ?? [];
        $pub    = isset($result['public_key']) ? (string)$result['public_key'] : '';
        $secret = isset($result['secret_key']) ? (string)$result['secret_key'] : '';

        if ($pub && $secret) {
            update_option('pf_webhook_public_key', $pub);
            update_option('pf_webhook_secret_hex', $secret);
            wp_safe_redirect(add_query_arg('pf_msg','ok', wp_get_referer() ?: admin_url('options-general.php?page='.self::PAGE_SLUG)));
        } else {
            $dbg = substr(json_encode($json), 0, 300);
            wp_safe_redirect(add_query_arg(['pf_msg'=>'missing_keys','pf_err'=>rawurlencode($dbg)], wp_get_referer() ?: admin_url('options-general.php?page='.self::PAGE_SLUG)));
        }
        exit;
    }

    private static function http_v2($method, $path, $body, $token, $store_id) {
        $url = 'https://api.printful.com' . $path;
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
        if (!empty($store_id)) $headers['X-PF-Store-Id'] = (string)$store_id;

        $args = [ 'method' => $method, 'timeout' => 30, 'headers' => $headers ];
        if (!is_null($body)) $args['body'] = wp_json_encode($body);

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return [ 'ok' => false, 'code' => 0, 'raw' => $resp->get_error_message(), 'json' => null ];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);
        return [ 'ok' => $code >= 200 && $code < 300, 'code' => $code, 'raw' => $raw, 'json' => $json ];
    }

    private static function get_current_config($token, $store_id) {
        if (!$token) return ['error' => 'Missing token'];
        $resp = self::http_v2('GET', '/v2/webhooks', null, $token, $store_id);
        if (!$resp['ok']) return ['error' => 'HTTP '.$resp['code'], 'raw' => $resp['raw']];
        return $resp['json'];
    }

    private static function mask_hex($hex) {
        $hex = preg_replace('/\s+/', '', (string)$hex);
        if (strlen($hex) <= 8) return str_repeat('•', max(0, strlen($hex) - 2)) . substr($hex, -2);
        return substr($hex, 0, 6) . str_repeat('•', max(0, strlen($hex) - 12)) . substr($hex, -6);
    }
}