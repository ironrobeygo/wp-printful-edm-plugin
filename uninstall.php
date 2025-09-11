<?php
/**
 * Uninstall script for Printful Catalog Plugin
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Remove database table
$table_name = $wpdb->prefix . 'printful_designs';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Remove plugin options
delete_option('printful_api_key');

// Clear scheduled events
wp_clear_scheduled_hook('printful_cleanup_old_drafts');

// Clear transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_printful_products_%'");