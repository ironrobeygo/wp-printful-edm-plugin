<?php
/**
 * Plugin Hooks and Configuration
 * Printful Catalog Plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// 1) Dynamic price per line
add_action('woocommerce_before_calculate_totals', function($cart){
    if (is_admin() && !defined('DOING_AJAX')) return;
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['unit_price']) && isset($item['data'])) {
            $item['data']->set_price((float)$item['unit_price']);
        }
    }
}, 20);

// 2) Show design info in cart/checkout
add_filter('woocommerce_get_item_data', function($data, $item){
    if (!empty($item['design_name'])) {
        $data[] = ['key'=>'Design',   'value'=>wp_kses_post($item['design_name'])];
    }
    if (!empty($item['design_category'])) {
        $data[] = ['key'=>'Category', 'value'=>esc_html($item['design_category'])];
    }
    return $data;
}, 10, 2);

// 3) Use mockup as thumbnail (cart/minicart)
add_filter('woocommerce_cart_item_thumbnail', function($thumb, $item){
    if (!empty($item['mockup_url'])) {
        return sprintf('<img src="%s" alt="" />', esc_url($item['mockup_url']));
    }
    return $thumb;
}, 10, 2);

// 4) Persist meta to order line items
add_action('woocommerce_checkout_create_order_line_item', function($order_item, $cart_item_key, $values){
    foreach (['design_name','product_id','variant_id','template_id','external_product_id','mockup_url','design_category'] as $k) {
        if (!empty($values[$k])) $order_item->add_meta_data($k, $values[$k], true);
    }
}, 10, 3);

// 5) Restore meta from session
add_filter('woocommerce_get_cart_item_from_session', function($session_data, $values){
    $keys = ['pf_item','design_name','product_id','variant_id','template_id','external_product_id','mockup_url','design_category','unit_price','currency','unique_key'];
    foreach ($keys as $k) {
        if (isset($values[$k])) $session_data[$k] = $values[$k];
    }
    return $session_data;
}, 10, 2);

/**
 * Replace product title with a custom one (e.g., Design Name + Category)
 */
add_filter('woocommerce_cart_item_name', function ($name, $cart_item, $cart_item_key) {
    if (!empty($cart_item['pf_item'])) {
        $design  = !empty($cart_item['design_name']) ? $cart_item['design_name'] : 'Custom Design';
        $cat     = !empty($cart_item['design_category']) ? $cart_item['design_category'] : '';
        $title   = $cat ? sprintf('%s — %s', esc_html($design), esc_html($cat)) : esc_html($design);

        // Build a clean title without a link (cart & checkout use this)
        $name = '<strong class="pf-line-title">' . $title . '</strong>';
    }
    return $name;
}, 10, 3);

/**
 * Remove the product permalink for our custom items (prevents theme from re-wrapping title as a link)
 */
add_filter('woocommerce_cart_item_permalink', function ($permalink, $cart_item, $cart_item_key) {
    if (!empty($cart_item['pf_item'])) {
        return false; // no link
    }
    return $permalink;
}, 10, 3);

/**
 * (Optional) Also show the mockup in the mini-cart widget
 */
add_filter('woocommerce_widget_cart_item_quantity', function ($html, $cart_item, $cart_item_key) {
    if (!empty($cart_item['pf_item']) && !empty($cart_item['mockup_url'])) {
        $img = sprintf('<img src="%s" alt="" style="width:40px;height:auto;margin-right:8px;vertical-align:middle;"/>',
            esc_url($cart_item['mockup_url'])
        );
        // Prepend image to the quantity line
        $html = $img . $html;
    }
    return $html;
}, 10, 3);

add_action('woocommerce_order_item_meta_start', function ($item_id, $item, $order, $plain_text) {
    $url = $item->get_meta('mockup_url', true);
    if ($url) {
        echo '<div class="pf-order-thumb" style="margin:6px 0; float: left;">';
        echo '<img src="'.esc_url($url).'" alt="" style="width:96px;height:auto;border-radius:6px;" />';
        echo '</div>';
    }
}, 10, 4);

add_filter('woocommerce_display_item_meta', function ($html, $item, $args) {

    foreach ( $item->get_formatted_meta_data() as $meta_id => $meta ) {
        if( $meta->key !== 'design_name' ) {
            continue; // skip our image URL meta
        }
        $value     = $args['autop'] ? wp_kses_post( $meta->display_value ) : wp_kses_post( make_clickable( trim( $meta->display_value ) ) );
        $strings = $meta->display_value;
    }

    if ( $strings ) {
		$html = $args['before'] . $strings . $args['after'];
	}
    return $html;

}, 10, 3);