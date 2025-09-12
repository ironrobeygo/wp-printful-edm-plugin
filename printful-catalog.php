<?php
/**
 * Plugin Name: Printful Product Catalog
 * Plugin URI: https://yoursite.com/
 * Description: A plugin to display Printful products with embedded design maker integration
 * Version: 1.0.0
 * Author: Rob Go
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if ( ! function_exists('pf_set_flash_notice') ) {
    /**
     * Set a short-lived flash notice that survives a redirect, with placement controls.
     *
     * @param string $message
     * @param string $type       'error'|'success'|'warning'
     * @param int    $ttl        seconds cookie lifetime
     * @param array  $placement  [
     *   'hook'     => 'woocommerce_before_cart'    // optional WP action hook to render at (server-side)
     *   'pr'       => 5,                           // optional priority for hook
     *   'selector' => '#pf-flash-slot',            // optional CSS selector (client-side insertion)
     *   'position' => 'afterbegin',                // beforebegin|afterbegin|beforeend|afterend
     * ]
     */
    function pf_set_flash_notice( $message, $type = 'error', $ttl = 120, $placement = array() ) {
        if ( headers_sent() ) { return false; }
        $payload = array(
            'm' => wp_strip_all_tags( (string) $message ),
            't' => in_array( $type, array( 'error', 'success', 'warning' ), true ) ? $type : 'error',
            'p' => array(),
        );
        if ( ! empty( $placement['hook'] ) ) {
            // allow only safe chars in hook names
            $payload['p']['h']  = preg_replace( '/[^a-z0-9_]/i', '', (string) $placement['hook'] );
            $payload['p']['pr'] = isset( $placement['pr'] ) ? (int) $placement['pr'] : 5;
        }
        if ( ! empty( $placement['selector'] ) ) {
            $payload['p']['s']   = (string) $placement['selector'];
            $payload['p']['pos'] = in_array( $placement['position'] ?? '', array('beforebegin','afterbegin','beforeend','afterend'), true )
                ? $placement['position'] : 'afterbegin';
        }
        $cookie_value = rawurlencode( wp_json_encode( $payload ) );
        $params = sprintf(
            '; Max-Age=%d; Path=%s; %sHttpOnly; SameSite=Lax',
            absint( $ttl ),
            ( COOKIEPATH ? COOKIEPATH : '/' ),
            is_ssl() ? 'Secure; ' : ''
        );
        header( 'Set-Cookie: pf_flash=' . $cookie_value . $params, false );
        return true;
    }
}

if ( ! function_exists('pf_get_flash_payload') ) {
    function pf_get_flash_payload() {
        if ( empty( $_COOKIE['pf_flash'] ) ) { return null; }
        $payload = json_decode( wp_unslash( $_COOKIE['pf_flash'] ), true );
        if ( empty( $payload['m'] ) ) { return null; }
        return $payload;
    }
}

if ( ! function_exists('pf_render_flash_banner') ) {
    function pf_render_flash_banner( $payload = null ) {
        $payload = $payload ?: pf_get_flash_payload();
        if ( empty( $payload['m'] ) ) { return false; }

        $class = 'is-error';
        if ( isset( $payload['t'] ) ) {
            if ( 'success' === $payload['t'] ) { $class = 'is-success'; }
            elseif ( 'warning' === $payload['t'] ) { $class = 'is-warning'; }
        }
        ?>
        <div id="pf-flash-banner"
             class="wc-block-components-notice-banner notification <?php echo esc_attr( $class ); ?>"
             role="alert" aria-live="assertive" style="margin:16px;">
            <div class="wc-block-components-notice-banner__content">
                <?php echo esc_html( $payload['m'] ); ?>
            </div>
        </div>
        <style>
            /* Fallback look if WC Blocks CSS is not loaded */
            .wc-block-components-notice-banner { padding:12px 14px; border-radius:8px; border:1px solid;
                font: 500 14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,Helvetica,Arial,sans-serif; }
            .wc-block-components-notice-banner.is-error   { background:#fff1f1; border-color:#f1a9a9; color:#8a1f1f; }
            .wc-block-components-notice-banner.is-success { background:#f1fff4; border-color:#a9f1b6; color:#1f8a3f; }
            .wc-block-components-notice-banner.is-warning { background:#fffbe6; border-color:#f1e3a9; color:#8a6a1f; }
            .wc-block-components-notice-banner__content { margin:0; }
        </style>
        <?php
        // Clear cookie after output so we don't double-render
        setcookie( 'pf_flash', '', time() - 3600, ( COOKIEPATH ? COOKIEPATH : '/' ), COOKIE_DOMAIN, is_ssl(), true );
        return true;
    }
}

/**
 * If a target WP hook is requested, register a one-time renderer there.
 * Runs early so we can hook into WooCommerce template actions.
 */
function pf_setup_flash_hook() {
    $payload = pf_get_flash_payload();
    if ( ! $payload || empty( $payload['p']['h'] ) ) { return; }
    $hook = $payload['p']['h'];
    $prio = isset( $payload['p']['pr'] ) ? (int) $payload['p']['pr'] : 5;
    if ( $hook ) {
        add_action( $hook, function () use ( $payload ) {
            pf_render_flash_banner( $payload );
        }, $prio );
    }
}
add_action( 'init', 'pf_setup_flash_hook', 0 );

/** Default placement: only if no custom hook is requested. */
add_action( 'wp_body_open', function () {
    $payload = pf_get_flash_payload();
    if ( $payload && empty( $payload['p']['h'] ) ) {
        pf_render_flash_banner( $payload );
    }
}, 5 );

/** Optional: enqueue WC Blocks styles if available for consistent visuals. */
add_action('wp_enqueue_scripts', function () {
    if ( function_exists('wp_style_is') && wp_style_is('wc-blocks-style', 'registered') ) {
        wp_enqueue_style('wc-blocks-style');
    }
}, 5);

// Define plugin constants
define('PRINTFUL_CATALOG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PRINTFUL_CATALOG_PLUGIN_PATH', plugin_dir_path(__FILE__));

class PrintfulCatalogPlugin {
    
    private $api_key;
    private $store_id;
    
    public function __construct() {
        add_action('init',                                          array($this, 'init'));
        add_action('init',                                          array($this, 'register_cart_line_overrides'));
        add_action('wp_enqueue_scripts',                            array($this, 'enqueue_scripts'));
        add_shortcode('printful_catalog',                           array($this, 'display_catalog_shortcode'));
        // Alias tag to support [display_catalog_shortcode ...]
        add_shortcode('display_catalog_shortcode',                  array($this, 'display_catalog_shortcode'));
        add_shortcode('printful_design_maker',                      array($this, 'display_design_maker_shortcode'));
        
        // AJAX handlers
        add_action('wp_ajax_load_more_products',                    array($this, 'load_more_products'));
        add_action('wp_ajax_nopriv_load_more_products',             array($this, 'load_more_products'));
        add_action('wp_ajax_save_design_draft',                     array($this, 'save_design_draft'));
        add_action('wp_ajax_add_design_to_cart',                    array($this, 'add_design_to_cart'));
        add_action('wp_ajax_get_design_data',                       array($this, 'get_design_data'));
        add_action('wp_ajax_add_saved_design_to_cart',              array($this, 'add_saved_design_to_cart'));
        add_action('wp_ajax_nopriv_printful_guest_save_template',   array($this, 'ajax_printful_guest_save_template'));
        add_action('wp_ajax_printful_guest_save_template',          array($this, 'ajax_printful_guest_save_template'));
        add_action('wp_ajax_printful_claim_draft',                  array($this, 'ajax_printful_claim_draft'));

        add_action('wp_ajax_get_edm_token',                         array($this, 'get_edm_token'));
        add_action('wp_ajax_nopriv_get_edm_token',                  array($this, 'get_edm_token'));

        add_action('wp_ajax_printful_save_template',                array($this, 'ajax_printful_save_template'));  
        add_action('wp_ajax_nopriv_printful_save_template',         array($this, 'ajax_printful_save_template'));
        
        // Admin menu
        add_action('admin_menu',                                    array($this, 'admin_menu'));
        add_action('wp_ajax_delete_design',                         array($this, 'delete_design'));
        
        // WooCommerce My Account integration
        add_action('init',                                          array($this, 'add_my_designs_endpoint'));
        add_filter('woocommerce_account_menu_items',                array($this, 'add_my_designs_menu_item'));
        add_action('woocommerce_account_my-designs_endpoint',       array($this, 'my_designs_content'));

        require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/hooks.php';
        require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/class-printful-auto-confirm.php';
        require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/class-printful-webhook-admin.php';

        if(did_action('plugins_loaded')){
            // If plugins_loaded has already happened, call init immediately.
            Printful_AutoConfirm::init();
        } else {
            add_action('plugins_loaded', ['Printful_AutoConfirm', 'init']);
        }
        if ( is_admin() ) {
            if (did_action('plugins_loaded')) {
                Printful_Webhook_Admin::init();
            } else {
                add_action('plugins_loaded', ['Printful_Webhook_Admin','init']);
            }
        }

        // Ensure WooCommerce is active before registering shipping
        add_action('plugins_loaded', function () {
            if (!class_exists('WooCommerce')) {
                // Optional: admin notice if you want
                return;
            }

            // Load shipping class AFTER WC has loaded shipping base classes
            add_action('woocommerce_shipping_init', function () {
                require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/class-wc-shipping-printful-live.php';
            });

            // Register the method with Woo
            add_filter('woocommerce_shipping_methods', function ($methods) {
                $methods['printful_live'] = 'WC_Shipping_Printful_Live';
                return $methods;
            });
        });

    }
    
    public function init() {
        $this->create_database_tables();
        $this->api_key = get_option('printful_api_key', '');
        $this->store_id = get_option('printful_store_id', '');
        add_filter('woocommerce_shipping_methods', function($methods){
            $methods['printful_live'] = 'WC_Shipping_Printful_Live';
            return $methods;
        });

        add_action('woocommerce_payment_complete', [__CLASS__, 'hook_wc_processing'], 10, 1);

        // Belt-and-suspenders: if status jumps straight to processing/completed
        add_action('woocommerce_order_status_changed', function($order_id, $from, $to){
            if (in_array($to, ['completed'], true)) {
                $this->pf_submit_order_if_needed($order_id);
            }
        }, 10, 3);

    }

    public function pf_submit_order_if_needed($order_id) {
        if (get_post_meta($order_id, '_printful_order_id', true)) return;

        if (class_exists('Printful_AutoConfirm')) {
            Printful_AutoConfirm::pf_submit_order_if_needed($order_id);
        }
    }

    public function register_cart_line_overrides() {
        // Ensure WooCommerce is active
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
                return sprintf('<img src="%s" alt="%s" style="width:80px;height:auto;"/>',
                    esc_url($cart_item['mockup_url']),
                    esc_attr($alt)
                );
            }
            return $thumb;
        }, 10, 3);

        add_filter('woocommerce_store_api_cart_item_images', function($thumb, $cart_item, $cart_item_key){
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
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_style('printful-catalog-css', PRINTFUL_CATALOG_PLUGIN_URL . 'assets/printful-catalog.css', array(), '1.0.0');
        
        if ( function_exists('is_account_page') && is_account_page() ) {
            wp_enqueue_script('printful-catalog-js', PRINTFUL_CATALOG_PLUGIN_URL . 'assets/printful-catalog.js', array('jquery'), '1.0.0', true);
            wp_enqueue_script('pf-claim-draft', PRINTFUL_CATALOG_PLUGIN_URL . 'assets/printful-edm-draft.js', [], '1.0.0', true);
        }

        if(is_singular()){
            global $post;
            if ($post instanceof WP_Post && has_shortcode($post->post_content, 'printful_design_maker')) {
                if (is_page('design')) {
                    wp_enqueue_script('printful-edm-js', PRINTFUL_CATALOG_PLUGIN_URL . 'assets/printful-edm.js', array('jquery'), '1.0.0', true);
                    wp_enqueue_script('printful-edm-embed','https://files.cdn.printful.com/embed/embed.js',array(),null,false);
                }
            }

			if ($post instanceof WP_Post && has_shortcode($post->post_content, 'printful_catalog')) {
				wp_enqueue_script('printful-catalog-js', PRINTFUL_CATALOG_PLUGIN_URL . 'assets/printful-catalog.js', array('jquery'), '1.0.0', true);
			}
        }
        
        // Localize script for AJAX
        $ajax_data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('printful_nonce'),
            'store_id' => get_option('printful_store_id', '')
        );
        
        // Add cart URL if WooCommerce is active
        if (function_exists('wc_get_cart_url')) {
            $ajax_data['cart_url'] = wc_get_cart_url();
        }

        wp_localize_script('printful-catalog-js', 'printful_ajax', $ajax_data);
        wp_localize_script('printful-edm-js', 'printful_ajax', $ajax_data);
    }
    
    public function create_database_tables() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'printful_designs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            product_id varchar(50) NOT NULL,
            design_data longtext NULL,
            design_name varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'draft',
            external_product_id varchar(191) NULL,
            template_id bigint(20) unsigned NULL,
            mockup_url text NULL,
            unit_price decimal(10,2) NULL,
            currency char(3) NULL,
            variant_id bigint(20) unsigned NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_saved datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user (user_id),
            KEY idx_external (external_product_id),
            UNIQUE KEY user_ext (user_id, external_product_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Hardening pass: add any missing columns/indexes if dbDelta flaked (older WP/regex quirks).
        $this->maybe_add_column($table_name, 'external_product_id', "ALTER TABLE $table_name ADD COLUMN external_product_id varchar(191) NULL");
        $this->maybe_add_column($table_name, 'template_id',        "ALTER TABLE $table_name ADD COLUMN template_id bigint(20) unsigned NULL");
        $this->maybe_add_column($table_name, 'mockup_url',         "ALTER TABLE $table_name ADD COLUMN mockup_url text NULL");
        $this->maybe_add_column($table_name, 'last_saved',         "ALTER TABLE $table_name ADD COLUMN last_saved datetime DEFAULT CURRENT_TIMESTAMP");
        $this->maybe_add_column($table_name, 'unit_price', "ALTER TABLE $table_name ADD COLUMN unit_price decimal(10,2) NULL");
        $this->maybe_add_column($table_name, 'currency', "ALTER TABLE $table_name ADD COLUMN currency char(3) NULL");
        $this->maybe_add_column($table_name, 'variant_id', "ALTER TABLE $table_name ADD COLUMN variant_id bigint(20) unsigned NULL");
        $this->maybe_add_index($table_name, 'user_ext',            "ALTER TABLE $table_name ADD UNIQUE KEY user_ext (user_id, external_product_id)");
        $this->maybe_add_index($table_name, 'idx_user',            "ALTER TABLE $table_name ADD KEY idx_user (user_id)");
        $this->maybe_add_index($table_name, 'idx_external',        "ALTER TABLE $table_name ADD KEY idx_external (external_product_id)");
    }
    
    public function admin_menu() {
        add_options_page(
            'Printful Catalog Settings',
            'Printful Catalog',
            'manage_options',
            'printful-catalog-settings',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
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
            $this->clear_product_cache();
            
            echo '<div class="notice notice-success"><p>Settings saved and cache cleared!</p></div>';
        }
        
        if (isset($_POST['clear_cache'])) {
            $this->clear_product_cache();
            echo '<div class="notice notice-success"><p>Product cache cleared!</p></div>';
        }
        
        $api_key = get_option('printful_api_key', '');
        $store_id = get_option('printful_store_id', '');
        $container_id = (int) get_option('pf_container_product_id', 0);
        $markup_pct   = (float) get_option('pf_markup_pct', 0);
        $markup_fix   = (float) get_option('pf_markup_fix', 0);
        $show_retail  = (int) get_option('pf_show_retail', 0);

        $allowed_category_ids = (array) get_option('pf_allowed_category_ids', []);
            $force_refresh_cats   = isset($_GET['pf_refresh_cats']);
            $cats                 = $this->pf_get_all_catalog_categories($force_refresh_cats);
            $cat_rows             = $this->pf_build_category_paths($cats);
            $refresh_url          = add_query_arg(
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
                                <?php endforeach; endif; ?>
                            </div>

                            <script>
                            (function(){
                                const search = document.getElementById('pf-cat-search');
                                const grid   = document.getElementById('pf-cat-grid');
                                const selAll = document.getElementById('pf-cat-select-all');
                                const clrAll = document.getElementById('pf-cat-clear-all');

                                if (search) {
                                search.addEventListener('input', function(){
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
    
    public function get_edm_token() {
        check_ajax_referer( 'printful_nonce', 'nonce' );

        error_log('AJAX get_edm_token called');

        $product_id          = isset($_POST['product_id']) ? sanitize_text_field($_POST['product_id']) : '';
        $external_product_id = isset($_POST['external_product_id']) ? sanitize_text_field($_POST['external_product_id']) : '';

        if ($external_product_id === '') {
            // last-resort fallback: unique per user+timestamp
            $external_product_id = 'u' . (get_current_user_id() ?: 0) . ':p' . ($product_id ?: '0') . ':ts:' . time();
        }

        if ( empty($product_id) && empty($external_product_id) ) {
            wp_send_json_error( array('error'=>'missing_ids','message'=>'Product ID or External Product ID is required') );
            return;
        }

        $nonce_token = $this->get_edm_nonce_token( $product_id, null, $external_product_id );

        error_log('EDM Nonce Token: ' . var_export($nonce_token, true));

        if ( is_string( $nonce_token ) && $nonce_token !== '' ) {
            wp_send_json_success( array(
                'nonce'               => $nonce_token,               // <-- string, top-level
                'external_product_id' => $external_product_id,
                'product_id'          => $product_id,
            ) );
        } else {
            wp_send_json_error( array('error'=>'api_failed','message'=>'Unable to get EDM token') );
        }
    }
    
    public function get_products($offset = 0, $limit = 8, $category_ids_override = null) {
        $raw = $category_ids_override;
        if (is_null($raw)) {
            $requested_ids = array();
        } elseif (is_array($raw)) {
            $requested_ids = $raw;
        } else {
            $requested_ids = preg_split('/[,\\s]+/', (string)$raw);
        }
        $effective_ids = array_values(array_unique(array_filter(array_map('intval', (array)$requested_ids))));

        // Build CSV (may be empty -> API will return all unless constrained server-side)
        $category_ids = !empty($effective_ids) ? implode(',', $effective_ids) : '';

        // Include categories in cache key so different filters don't collide
        $cache_key = sprintf('pf_products_%d_%d_%s', (int)$offset, (int)$limit, md5($category_ids));
        if (($cached = $this->get_cached_products($cache_key)) !== false) {
             return $cached;
        }

        try {
            $url = 'v2/catalog-products'
                . '?category_ids=' . rawurlencode($category_ids)
                . '&limit='  . intval($limit)
                . '&offset=' . intval($offset)
                . '&selling_region_name=australia'
                . '&destination_country=AU';

            $products = $this->make_api_request($url);

            if (!$products || !isset($products['data'])) {
                error_log('Printful API Error: empty products');
                return [];
            }

            $result = [];
            foreach ($products['data'] as $product) {
                $price = $this->get_catalog_min_price($product['id']);
                $result[] = [
                    'id'          => $product['id'],
                    'title'       => $product['name'],
                    'type'        => $product['type'],
                    'image'       => $product['image'],
                    'price'       => $price,
                    'description' => isset($product['description']) ? $product['description'] : '',
                ];
            }

            $this->set_cached_products($cache_key, $result, 1800); // 10 min
            return $result;

        } catch (Exception $e) {
            error_log('Printful Catalog Error: ' . $e->getMessage());
            return [];
        }
    }
    
    public function display_catalog_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => 8,
            'modal' => 'false',
            'category_id' => ''
        ), $atts);
        
        // Check if API key is configured
        if (empty($this->api_key)) {
            return '<div class="printful-error"><p>Error: Printful API key not configured. Please check plugin settings.</p></div>';
        }
        
        // Check if store ID is configured when using modal
        if ($atts['modal'] === 'true' && empty($this->store_id)) {
            return '<div class="printful-error"><p>Error: Printful Store ID not configured. Please check plugin settings for modal functionality.</p></div>';
        }

        // Pull admin-selected categories (fallback to the 4 defaults)
        $admin_ids = (array) get_option('pf_allowed_category_ids', []);
        $admin_ids = array_values(array_unique(array_filter(array_map('intval', $admin_ids))));

        // Shortcode-specified (CSV) category IDs override admin if provided
        $shortcode_ids = array();
        if (!empty($atts['category_id'])) {
            $shortcode_ids = array_values(array_unique(array_filter(array_map('intval',
                preg_split('/[,\s]+/', (string) $atts['category_id'])
            ))));
        }

        // Choose the source list for filters + "Show All"
        $source_ids = !empty($shortcode_ids) ? $shortcode_ids : $admin_ids;

        // Fallback if neither admin nor shortcode provided anything
        if (empty($source_ids)) {
            $source_ids = array(1,2,3,4); // generic fallback
        }

        $all_cats     = $this->pf_get_all_catalog_categories(false);
        $cat_rows     = $this->pf_build_category_paths($all_cats); // each row: ['id'=>int,'label'=>string]
        $label_by_id  = array();
        foreach ($cat_rows as $r) { $label_by_id[(int)$r['id']] = $r['label']; }
        $fallback_lbl = array(1 => "Men's", 2 => "Women's", 3 => 'Kids', 4 => 'Accessories');

        $filter_items = array();
        foreach ($source_ids as $cid) {
            $label = isset($label_by_id[$cid]) ? $label_by_id[$cid] : (isset($fallback_lbl[$cid]) ? $fallback_lbl[$cid] : ('Category '.$cid));
            $filter_items[(int)$cid] = $label;
        }

        $show_all_ids = array_map('intval', array_keys($filter_items));
        $show_all_csv = implode(',', $show_all_ids);

        // Current selection
        $current_csv = !empty($selected_ids)
            ? implode(',', array_map('intval', $selected_ids))
            : $show_all_csv;

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
        
        $selected_ids = array();
        if (isset($_GET['category_id'])) {
            $selected_ids = array_values(array_unique(array_filter(array_map('intval',
                preg_split('/[,\s]+/', (string) $_GET['category_id'])
            ))));
            // keep only those that exist in the source set
            $selected_ids = array_values(array_intersect($selected_ids, $source_ids));
        }

        if (empty($selected_ids)) {
            $selected_ids = $source_ids;
        }

        // Initial fetch respects the selection (empty array = "all" = baseline_ids)
        $limit    = intval($atts['limit'] ?? 8);
        $products = $this->get_products(0, $limit, $selected_ids);

        if (empty($products)) {
            return '<div class="printful-error"><p>Unable to load products at this time. Please try again later.</p></div>';
        }
        
        $use_modal = ($atts['modal'] === 'true');
        
        ob_start();
        ?>
        <div id="printful-catalog"
            class="printful-catalog-container"
            data-selected-ids="<?php echo esc_attr(implode(',', $selected_ids)); ?>"
            data-category-ids="<?php echo esc_attr($show_all_csv); ?>">
            <div class="pf-filter-row">
                <label for="pf-category-select" class="pf-filter-label">Filter:</label>
                <select id="pf-category-select"
                        class="pf-filter-select"
                        data-show-all="<?php echo esc_attr($show_all_csv); ?>">
                    <option value="<?php echo esc_attr($show_all_csv); ?>"
                            <?php selected($current_csv, $show_all_csv); ?>>
                    Show All
                    </option>

                    <?php foreach ($grouped as $groupLabel => $items): ?>
                    <optgroup label="<?php echo esc_attr($groupLabel); ?>">
                        <?php foreach ($items as $it): ?>
                        <option value="<?php echo esc_attr((string)$it['id']); ?>"
                                <?php selected($current_csv, (string)$it['id']); ?>>
                            <?php echo esc_html($it['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
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
                                    data-product-id="<?php echo esc_attr($product['id']); ?>"
                                    data-modal="<?php echo $use_modal ? 'true' : 'false'; ?>">
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
                    data-category-ids="<?php echo esc_attr($show_all_csv); ?>">
                    Load More Products
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function display_design_maker_shortcode($atts) {
        $atts = shortcode_atts(array(
            'title' => 'Design Your Product',
            'product_id' => '',
            'design_id'  => '',
            'back_url' => ''
        ), $atts);
        
        $back_url = '';
        if (!empty($atts['back_url'])) {
            $back_url = $atts['back_url'];
        } elseif (isset($_GET['back'])) {
            $back_url = wp_unslash((string) $_GET['back']);
        } else {
            // same-host referrer only
            $ref = wp_get_referer();
            if ($ref) {
                $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
                $ref_host  = wp_parse_url($ref, PHP_URL_HOST);
                if ($site_host && $ref_host && strtolower($site_host) === strtolower($ref_host)) {
                    $back_url = $ref;
                }
            }
            // optional plugin setting fallback (e.g., catalog page)
            if (!$back_url) {
                $catalog_page_id = (int) get_option('pf_catalog_page_id', 0); // define this option if you like
                if ($catalog_page_id) {
                    $back_url = get_permalink($catalog_page_id);
                }
            }
        }
        // final fallback
        if (!$back_url) {
            $back_url = home_url('/');
        }
        // sanitize/lock to allowed host
        $back_url = wp_validate_redirect($back_url, home_url('/'));

		$prefill = array(
			'design_name'         => '',
			'external_product_id' => '',
			'template_id'         => 0,
			'variant_id'          => 0,
		);

        // pull from URL if not provided as shortcode attrs
        if (empty($atts['product_id']) && isset($_GET['product_id'])) {
            $atts['product_id'] = sanitize_text_field($_GET['product_id']);
        }
        if (empty($atts['design_id']) && isset($_GET['design_id'])) {
            $atts['design_id'] = sanitize_text_field($_GET['design_id']);
			$row = $this->fetch_design_by_id((int) $atts['design_id'], true);
			if (! $row) {
				// Protect privacy: only the owner can see their design details
				return '<p>Error: Design not found or you do not have access.</p>';
			}

			if (empty($atts['product_id']) && ! empty($row['product_id'])) {
				$atts['product_id'] = (string) $row['product_id'];
			}

			$prefill['design_name']         = (string) ($row['design_name'] ?? '');
			$prefill['external_product_id'] = (string) ($row['external_product_id'] ?? '');
			$prefill['template_id']         = (int)    ($row['template_id'] ?? 0);
			$prefill['variant_id']          = (int)    ($row['variant_id'] ?? 0);
		}
        
        $store_id = get_option('printful_store_id', '');
        
        if (empty($store_id)) {
            return '<div class="printful-error"><p>Error: Printful Store ID not configured. Please check plugin settings.</p></div>';
        }
        
        if (empty($atts['product_id']) && empty($atts['design_id'])) {
            return '<p>Error: No product ID or design ID specified. Please provide a product_id or design_id parameter.</p>';
        }
        
        // Generate unique ID for this shortcode instance
        $unique_id = 'printful_edm_' . uniqid();

        $variants_for_js = [];
        if (!empty($atts['product_id'])) {
            $resp = $this->make_api_request("v2/catalog-products/{$atts['product_id']}", 'GET');
            foreach (($resp['data']['variants'] ?? []) as $v) {
                $variants_for_js[] = [
                    'id'    => (int) ($v['id'] ?? 0),
                    'color' => (string) ($v['color'] ?? ''),
                    'size'  => (string) ($v['size'] ?? ''),
                ];
            }
        }

        $store_id   	= (int) get_option('printful_store_id');
		$design_name 	= $prefill['design_name'] ?: 'My Design';
        
        ob_start();
        ?>
        <div id="design-maker-container"
             data-unique-id="<?php echo esc_attr($unique_id); ?>"
             data-product-id="<?php echo esc_attr($atts['product_id']); ?>"
             data-design-id="<?php echo esc_attr($atts['design_id']); ?>"
			 data-external-product-id="<?php echo esc_attr($prefill['external_product_id']); ?>"
             data-store-id="<?php echo esc_attr($store_id); ?>"
             data-current-user-id="<?php echo (int) get_current_user_id(); ?>"
             data-variants='<?php echo wp_json_encode($variants_for_js, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>'>
            <div class="design-maker-header">
                <div class="design-info">
                    <h2><?php echo $atts['title']; ?></h2>
                    <input type="text" id="design-name-<?php echo $unique_id; ?>" class="design-name-input" placeholder="Enter design name..." value="<?php echo esc_attr($design_name); ?>" />
                </div>
                <div class="pf-toolbar" style="display:flex;align-items:center;gap:12px;justify-content:space-between;margin:8px 0;">
                    <div class="pf-price" style="margin-top: 26px;">
                        <span class="pf-price-label" style="opacity:.75;margin-right:6px;">Price:</span>
                        <span id="pf-live-price">—</span>
                    </div>
                    <div class="design-actions">
                        <button type="button" id="edm-save-btn" class="pf-btn pf-btn--primary">Save Design</button>
                        <button type="button" onclick="location.href = '<?php echo esc_url($back_url); ?>';" class="pf-btn pf-btn--secondary">Back to Catalog Page</button>
                    </div>
                </div>

            </div>
            
            <div id="printful-edm-container" class="edm-container">
                <div class="edm-loading">
                    <p>Loading Printful Design Maker...</p>
                    <div class="spinner"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function load_more_products() {
        check_ajax_referer('printful_nonce', 'nonce');

        $offset = intval($_POST['offset'] ?? 0);
        $limit  = intval($_POST['limit']  ?? 8);

        // Read category_ids[] posted from JS
        $raw = $_POST['category_ids'] ?? [];
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[,\\s]+/', (string)$raw);
        }
        $requested_ids = array_values(array_unique(array_filter(array_map('intval', (array)$parts))));

        $products = $this->get_products($offset, $limit, $requested_ids);

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
            'next_offset' => $offset + $limit,
        ]);
    }
    
    public function save_design_draft() {
        check_ajax_referer('printful_nonce', 'nonce');

        if ( ! is_user_logged_in() ) {
            $redirect = function_exists('wc_get_page_permalink')
                ? wc_get_page_permalink('myaccount')
                : wp_login_url();
        
            wp_send_json_error( array(
                'error'    => 'auth_required',
                'message'  => 'Please log in to save designs.',
                'redirect' => $redirect,
            ) );
            return;
        }

        $user_id  = get_current_user_id();
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        $design_name = sanitize_text_field($_POST['design_name'] ?? 'Untitled Design');

        // EDM path (preferred)
        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        $external_product_id = isset($_POST['external_product_id']) ? sanitize_text_field($_POST['external_product_id']) : '';

        if ($template_id && $external_product_id) {
            // OPTIONAL: fetch thumbnail (requires product_templates/read scope)
            $mockup_url = '';
            $tpl = $this->make_api_request('product-templates/' . $template_id, 'GET');
            if (is_array($tpl) && !empty($tpl['result']['mockup_file_url'])) {
                $mockup_url = esc_url_raw($tpl['result']['mockup_file_url']);
            }

            $row_id = $this->save_or_update_design_row(
                $user_id, $product_id, $design_name, 'draft', $external_product_id, $template_id, $mockup_url
            );
            wp_send_json_success(array('message' => 'Saved (EDM)', 'design_id' => $row_id, 'template_id' => $template_id));
            return;
        }

        // Legacy fallback (non-EDM)
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
    
    public function add_design_to_cart() {
        check_ajax_referer('printful_nonce', 'nonce');

        if ( ! is_user_logged_in() ) {
            $redirect = function_exists('wc_get_page_permalink')
                ? wc_get_page_permalink('myaccount')
                : wp_login_url();
        
            wp_send_json_error( array(
                'error'    => 'auth_required',
                'message'  => 'Please log in to save designs.',
                'redirect' => $redirect,
            ) );
            return;
        }

        $container_product_id = (int) get_option('pf_container_product_id', 0);
        if (!$container_product_id) {
            wp_send_json_error('Container product not configured');
            return;
        }

        // Normalize price
        $unit_raw = isset($_POST['unit_price']) ? (float) $_POST['unit_price'] : 0;
        $currency = sanitize_text_field($_POST['currency'] ?? get_woocommerce_currency());
        $markup_pct = (float) get_option('pf_markup_pct', 0);
        $markup_fix = (float) get_option('pf_markup_fix', 0);
        $final_price = $unit_raw * (1 + $markup_pct/100.0) + $markup_fix;

        // Persist/lookup the design row if you have template/external IDs
        $variant_id = isset($_POST['variant_id']) ? (int) $_POST['variant_id'] : 0;
        $template_id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
        $external_product_id = sanitize_text_field($_POST['external_product_id'] ?? '');

        $design_name = sanitize_text_field($_POST['design_name'] ?? 'Untitled Design');
        $mockup_url  = ''; // optional lookup if you have it
        $design_category = ''; // set from your product type if available

        // Build cart item meta
        $meta = array(
            'pf_item'             => 1,
            'design_name'         => $design_name,
            'product_id'          => (int)($_POST['product_id'] ?? 0), // Printful catalog product id
            'variant_id'          => $variant_id,
            'template_id'         => $template_id,
            'external_product_id' => $external_product_id,
            'mockup_url'          => $mockup_url,
            'design_category'     => $design_category,
            'unit_price'          => (float) $currentPrice,
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
    
    public function add_saved_design_to_cart() {
        if ( ! check_ajax_referer('printful_nonce', 'nonce', false) ) {
            wp_send_json_error('invalid_nonce'); return;
        }
        
        if ( ! is_user_logged_in() ) {
            $redirect = function_exists('wc_get_page_permalink')
                ? wc_get_page_permalink('myaccount')
                : wp_login_url();
        
            wp_send_json_error( array(
                'error'    => 'auth_required',
                'message'  => 'Please log in to save designs.',
                'redirect' => $redirect,
            ) );
            return;
        }

        $user_id   = get_current_user_id();
        $design_id = isset($_POST['design_id']) ? (int) $_POST['design_id'] : 0;
        if (!$design_id) { wp_send_json_error('missing_design_id'); return; }

        global $wpdb;
        // Adjust table name if your plugin defines a property for it
        $table = $wpdb->prefix . 'printful_designs';

        // Load the saved design row (must belong to the current user)
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND user_id = %d LIMIT 1",
                $design_id, $user_id
            ),
            ARRAY_A
        );

        if ( ! $row ) { wp_send_json_error('design_not_found'); return; }

        // ---- Gather core fields from row ----
        $pf_product_id       = (int)($row['product_id'] ?? 0);             // Printful catalog product id
        $pf_variant_id       = (int)($row['variant_id'] ?? 0);             // Prefer having this stored when saved
        $template_id         = (int)($row['template_id'] ?? 0);
        $external_product_id = (string)($row['external_product_id'] ?? '');
        $design_name         = (string)($row['design_name'] ?? 'Saved Design');
        $mockup_url          = (string)($row['mockup_url'] ?? '');         // might be empty if mockup still rendering
        $design_category     = (string)($row['design_category'] ?? ($row['category'] ?? '')); // support either column name

        $unit_raw = (float)$row['unit_price'];
        $currency = sanitize_text_field($_POST['currency'] ?? get_woocommerce_currency());

        // Apply markup settings if you sell retail (optional)
        $markup_pct = (float) get_option('pf_markup_pct', 0);
        $markup_fix = (float) get_option('pf_markup_fix', 0);
        $final_price = $unit_raw * (1 + $markup_pct/100.0) + $markup_fix;

        // ---- Add to cart as container product ----
        $container_product_id = (int) get_option('pf_container_product_id', 0);
        if ( ! $container_product_id ) { wp_send_json_error('container_not_configured'); return; }

        $cart_meta = [
            'pf_item'             => 1,
            'design_id'           => (int)$row['id'],
            'design_name'         => $design_name,
            'product_id'          => $pf_product_id,
            'variant_id'          => $pf_variant_id,     // can be 0; server shipping method will ignore if empty
            'template_id'         => $template_id,
            'external_product_id' => $external_product_id,
            'mockup_url'          => esc_url_raw($mockup_url),
            'design_category'     => $design_category,
            'unit_price'          => (float)$final_price,
            'currency'            => $currency,
            'unique_key'          => md5($user_id . $row['id'] . microtime(true)), // avoid line merges
        ];

		error_log(wp_json_encode($cart_meta));

        $key = WC()->cart->add_to_cart($container_product_id, 1, 0, [], $cart_meta);
        if ( ! $key ) { 
            wp_send_json_error('add_to_cart_failed'); 
            return; 
        }
        
        pf_set_flash_notice( 'Design added to your cart.', 'success' );
        wp_send_json_success(['redirect' => wc_get_cart_url()]);
    }

    public function get_design_data() {
        check_ajax_referer('printful_nonce', 'nonce');
        
        if ( ! is_user_logged_in() ) {
            $redirect = function_exists('wc_get_page_permalink')
                ? wc_get_page_permalink('myaccount')
                : wp_login_url();
        
            wp_send_json_error( array(
                'error'    => 'auth_required',
                'message'  => 'Please log in to save designs.',
                'redirect' => $redirect,
            ) );
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
    
    public function add_design_product_endpoint() {
        add_rewrite_rule('^design/?', 'index.php?design=1', 'top');
        add_rewrite_tag('%design%', '([^&]+)');
    }
    
    public function add_my_designs_endpoint() {
        add_rewrite_endpoint('my-designs', EP_ROOT | EP_PAGES);
    }
    
    public function add_my_designs_menu_item($items) {
        $new_items = [];
    
        foreach ( $items as $key => $label ) {
            $new_items[$key] = $label;
    
            // Insert after Dashboard
            if ( $key === 'dashboard' ) {
                $new_items['my-designs'] = __( 'My Designs', 'textdomain' );
            }
        }
    
        return $new_items;
    }

    public function update_db_mockup_url_value($db_id, $template_id){
        $mockup_url = '';
        $tpl = $this->make_api_request('product-templates/' . $template_id, 'GET');
        if (is_array($tpl) && !empty($tpl['result']['mockup_file_url'])) {
            $mockup_url = esc_url_raw($tpl['result']['mockup_file_url']);
        }
        if(!empty($mockup_url)){
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
    
    public function my_designs_content() {
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
                            if($design->mockup_url === null || $design->mockup_url === ''){
                                $thumb = $this->update_db_mockup_url_value($design->id, $design->template_id);
                            } else {
                                $thumb = $design->mockup_url;
                            }
                            $designer_url = add_query_arg(
                                array(
                                    'product_id'  => $design->product_id,
                                    'external_id' => $design->external_product_id, // used in your EDM init to reopen same draft
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
                            <!-- <a class="button" href="<?php echo esc_url($designer_url); ?>" style="display:inline-block;margin-top:8px">Edit in Designer</a> -->

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

    public function delete_design() {
        check_ajax_referer('printful_nonce', 'nonce');

        if ( ! is_user_logged_in() ) {
            $redirect = function_exists('wc_get_page_permalink')
                ? wc_get_page_permalink('myaccount')
                : wp_login_url();
        
            wp_send_json_error( array(
                'error'    => 'auth_required',
                'message'  => 'Please log in to save designs.',
                'redirect' => $redirect,
            ) );
            return;
        }

        $design_id = isset($_POST['design_id']) ? intval($_POST['design_id']) : 0;
        if (!$design_id) {
            wp_send_json_error('Missing design_id');
        }

        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'printful_designs';

        // Ensure the design belongs to the current user
        $deleted = $wpdb->delete($table, array('id' => $design_id, 'user_id' => $user_id), array('%d','%d'));

        if ($deleted !== false) {
            wp_send_json_success(array('deleted' => (int)$deleted));
        } else {
            wp_send_json_error('Delete failed');
        }
    }

    public function ajax_printful_save_template() {
        check_ajax_referer('printful_nonce', 'nonce');

        if (!is_user_logged_in()) {
            $redirect = ( function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url() );
            wp_send_json_error( array(
                'error'   => 'auth_required',
                'message' => 'Please log in to save designs!',
                'redirect'=> $redirect,
            ) );
        }

        $user_id                = get_current_user_id();
        $product_id             = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $store_id               = sanitize_text_field($_POST['store_id'] ?? '');
        $template_id            = sanitize_text_field($_POST['template_id'] ?? '');
        $external_product_id    = sanitize_text_field($_POST['external_product_id'] ?? '');
        $design_name            = sanitize_text_field($_POST['design_name'] ?? '');
        $want_mockup            = !empty($_POST['generate_mockup']);
        $unit_price             = isset($_POST['unit_price']) ? (float) $_POST['unit_price'] : null;
        $currency               = sanitize_text_field($_POST['currency'] ?? '');
        $variant_id             = isset($_POST['variant_id']) ? (int) $_POST['variant_id'] : 0;
        $replace_id             = isset($_POST['replace_id']) ? (int) $_POST['replace_id'] : 0;

        if (!$template_id || !$external_product_id) {
            wp_send_json_error('Missing template_id or external_product_id');
        }

        $mockup_url = '';
        $tpl = $this->make_api_request('product-templates/' . $template_id, 'GET');
        if (is_array($tpl) && !empty($tpl['result']['mockup_file_url'])) {
            $mockup_url = esc_url_raw($tpl['result']['mockup_file_url']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'printful_designs';

        /** Upsert by (user_id, external_product_id) */
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id=%d AND external_product_id=%s",
            $user_id, $external_product_id
        ));

        $data = [
            'user_id'               => $user_id,
            'product_id'            => $product_id,
            'design_data'           => null, // we store template_id instead of raw canvas
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
        $formats = array('%d','%s','%s','%s','%s','%s','%d','%s','%f','%s','%d','%s');

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
            // If replace_id is not owned by the user, fall through to standard upsert logic
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

    public function ajax_printful_guest_save_template() {
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
            wp_send_json_error([ 'error' => 'bad_request', 'message' => 'Missing template data.' ]);
        }
    
        $mockup_url = '';
        $tpl = $this->make_api_request('product-templates/' . $template_id, 'GET');
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
    
        // Keep for 6 hours
        set_transient('pf_guest_draft_' . $token, $data, 6 * HOUR_IN_SECONDS);
    
        // Convenience cookie (not required; we also pass token in URL)
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
    
    public function ajax_printful_claim_draft() {
        check_ajax_referer('printful_nonce', 'nonce');
    
        if (!is_user_logged_in()) {
            wp_send_json_error([ 'error' => 'auth_required', 'message' => 'Please log in.' ]);
        }
    
        $token = sanitize_text_field($_POST['token'] ?? '');
        if (!$token) {
            wp_send_json_error([ 'error' => 'bad_request', 'message' => 'Missing token.' ]);
        }
    
        $key  = 'pf_guest_draft_' . $token;
        $data = get_transient($key);
        if (!$data || empty($data['template_id']) || empty($data['external_product_id'])) {
            wp_send_json_error([ 'error' => 'not_found', 'message' => 'Draft expired or already claimed.' ]);
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
    
        // Send them to "My Designs"
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

	private function fetch_design_by_id($design_id, $require_owner = true){
		global $wpdb;
		$table     = $wpdb->prefix . 'printful_designs';
		$design_id = absint($design_id);
		if (! $design_id) {
			return null;
		}

		if ($require_owner && is_user_logged_in()) {
			$uid = get_current_user_id();
			$sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND user_id = %d LIMIT 1", $design_id, $uid);
		} else {
			$sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $design_id);
		}
		$row = $wpdb->get_row($sql, ARRAY_A);
		return $row ?: null;
	}

	/* NEW: small helpers to safely add columns/indexes when missing */
	private function maybe_add_column($table, $column, $sql){
		global $wpdb;
		$exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $column));
		if (!$exists) {
			$wpdb->query($sql);
		}
	}

	private function maybe_add_index($table, $index, $sql){
		global $wpdb;
		$exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s", $index));
		if (!$exists) {
			$wpdb->query($sql);
		}
	}

	private function clear_product_cache(){
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_printful_products_%' OR option_name LIKE '_transient_timeout_printful_products_%'");
	}

	private function pf_get_all_catalog_categories($force_refresh = false){
		$cache_key = 'pf_catalog_categories_all_v2';
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if ($cached !== false) return $cached;
		}

		$categories = [];
		$limit = 100;
		$offset = 0;

		do {
			$endpoint = "v2/catalog-categories?limit={$limit}&offset={$offset}";
			$resp = $this->make_api_request($endpoint, 'GET');

			if (!is_array($resp) || empty($resp['data']) || !is_array($resp['data'])) break;

			foreach ($resp['data'] as $row) {
				$categories[] = [
					'id'        => (int) ($row['id'] ?? 0),
					'parent_id' => (int) ($row['parent_id'] ?? 0),
					'title'     => (string) ($row['title'] ?? ''),
					'image_url' => (string) ($row['image_url'] ?? ''),
				];
			}

			$count = count($resp['data']);
			$offset += $limit;
		} while ($count === $limit);

		set_transient($cache_key, $categories, DAY_IN_SECONDS);
		return $categories;
	}

	private function pf_build_category_paths(array $cats){
		$byId = [];
		foreach ($cats as $c) $byId[$c['id']] = $c;

		$cache = [];
		$pathOf = function ($id) use (&$byId, &$cache, &$pathOf) {
			if (isset($cache[$id])) return $cache[$id];
			if (!isset($byId[$id])) return '';
			$chain = [];
			$cur = $byId[$id];
			$guard = 0;
			while ($cur && $guard++ < 20) {
				array_unshift($chain, (string)($cur['title'] ?? ''));
				$pid = (int)($cur['parent_id'] ?? 0);
				$cur = $pid && isset($byId[$pid]) ? $byId[$pid] : null;
			}
			return $cache[$id] = trim(implode(' › ', array_filter($chain)));
		};

		$rows = [];
		foreach ($cats as $c) $rows[] = ['id' => $c['id'], 'label' => $pathOf($c['id'])];
		usort($rows, fn($a, $b) => strcasecmp($a['label'], $b['label']));
		return $rows;
	}

	private function get_edm_nonce_token($product_id, $variant_id = null, $external_product_id = ''){
		$payload = array(
			'external_product_id' => $external_product_id ? (string) $external_product_id : (string) $product_id,
			'external_customer_id' => (string)get_current_user_id() ?: null,
		);
		// if ( ! empty($_SERVER['REMOTE_ADDR']) )     { $payload['ip_address'] = sanitize_text_field($_SERVER['REMOTE_ADDR']); }
		// if ( ! empty($_SERVER['HTTP_USER_AGENT']) ) { $payload['user_agent'] = sanitize_text_field($_SERVER['HTTP_USER_AGENT']); }

		// Must call the API base (your make_api_request should prefix with https://api.printful.com/)
		$response = $this->make_api_request('embedded-designer/nonces', 'POST', $payload);

		if (is_array($response) && isset($response['result']['nonce']['nonce']) && is_string($response['result']['nonce']['nonce'])) {
			return $response['result']['nonce']['nonce'];
		}
		return false;
	}

	private function get_catalog_min_price($product_id, $currency = null, $region = null){
		$key_bits = [$product_id, $currency ?: 'store', $region ?: 'store'];
		$cache_key = 'pf_min_price_' . implode('_', $key_bits);
		$cached = get_transient($cache_key);
		if ($cached !== false) return $cached;

		// Build v2 prices endpoint; omit params to use your store currency/region
		$endpoint = "v2/catalog-products/{$product_id}/prices";
		$qs = [];
		if ($currency) $qs[] = 'currency=AUD';
		if ($region)   $qs[] = 'selling_region_name=australia';
		if ($qs) $endpoint .= '?' . implode('&', $qs);

		$res = $this->make_api_request($endpoint, 'GET');
		if (!$res || empty($res['data'])) return null;

		$data = $res['data'];

		// Map first-placement prices by technique (if present)
		$placement_price_by_tech = [];
		if (!empty($data['product']['placements']) && is_array($data['product']['placements'])) {
			foreach ($data['product']['placements'] as $pl) {
				$techKey = $pl['technique_key'] ?? $pl['techniqueKey'] ?? null;
				if (!$techKey) continue;
				$placement_price_by_tech[$techKey] = (float) (
					$pl['discounted_price'] ?? $pl['price'] ?? 0
				);
			}
		}

		// Walk all variant technique prices; pick minimum total
		$min = INF;
		if (!empty($data['variants']) && is_array($data['variants'])) {
			foreach ($data['variants'] as $v) {
				foreach ($v['techniques'] as $t) {
					$prices[]    = (float)$t['price'];
				}
			}

			$min = $prices ? min($prices) : null;
		}

		if (!is_finite($min)) return null;

		set_transient($cache_key, $min, 12 * HOUR_IN_SECONDS);
		return $min;
	}

	private function get_cached_products($cache_key){
		return get_transient('printful_products_' . md5($cache_key));
	}

	private function set_cached_products($cache_key, $products, $expiration = 3600){
		set_transient('printful_products_' . md5($cache_key), $products, $expiration);
	}
}

new PrintfulCatalogPlugin();

register_activation_hook(__FILE__, function() {
    $plugin = new PrintfulCatalogPlugin();
    $plugin->create_database_tables();
    
    $plugin->add_my_designs_endpoint();
    
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});