<?php

/**
 * Plugin Name: Printful Product Catalog
 * Plugin URI: https://yoursite.com/
 * Description: A plugin to display Printful products with embedded design maker integration
 * Version: 1.0.0
 * Author: Rob Go
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
	exit;
}

define('PRINTFUL_CATALOG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PRINTFUL_CATALOG_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PRINTFUL_API_KEY', get_option('printful_api_key'));
define('PRINTFUL_STORE_ID', get_option('printful_store_id'));

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Shortcodes/DisplayCatalogShortcode.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Shortcodes/DisplayDesignMakerShortcode.php';

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Ajax/CatalogAjax.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Ajax/DesignAjax.php';

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Classes/AdminClass.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Classes/WooIntegrationClass.php';

class PrintfulCatalogPlugin{

	public function __construct(){
		add_action('init',                                          array($this, 'init'));
		add_action('wp_enqueue_scripts',                            array($this, 'enqueue_scripts'));

		// Shortcode handlers
		(new DisplayCatalogShortcode())->register();
		(new DisplayDesignMakerShortcode())->register();

		// AJAX handlers
		(new CatalogAjax())->register();
		(new DesignAjax())->register();

		// Admin menu
		(new AdminClass())->register();

		// WooCommerce My Account integration
		(new WooIntegrationClass())->register();
	}

	public function init(){
		$this->create_database_tables();
	}

	public function enqueue_scripts(){
		wp_enqueue_script('jquery');
		wp_enqueue_style('printful-catalog-css', PRINTFUL_CATALOG_PLUGIN_URL . 'assets/printful-catalog.css', array(), '1.0.0');

		if (function_exists('is_account_page') && is_account_page()) {
			wp_enqueue_script('printful-catalog-js', PRINTFUL_CATALOG_PLUGIN_URL . 'assets/printful-catalog.js', array('jquery'), '1.0.0', true);
			wp_enqueue_script('pf-claim-draft', PRINTFUL_CATALOG_PLUGIN_URL . 'assets/printful-edm-draft.js', [], '1.0.0', true);
		}

		if (is_singular()) {
			global $post;
			if ($post instanceof WP_Post && has_shortcode($post->post_content, 'printful_design_maker')) {
				if (is_page('design')) {
					wp_enqueue_script('printful-edm-js', PRINTFUL_CATALOG_PLUGIN_URL . 'assets/printful-edm.js', array('jquery'), '1.0.0', true);
					wp_enqueue_script('printful-edm-embed', 'https://files.cdn.printful.com/embed/embed.js', array(), null, false);
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

	public function create_database_tables(){
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
}

new PrintfulCatalogPlugin();

register_activation_hook(__FILE__, function () {
	$plugin = new PrintfulCatalogPlugin();
	$plugin->create_database_tables();

	flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
	flush_rewrite_rules();
});
