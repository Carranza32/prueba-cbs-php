<?php
/**
 * Cart block.
 *
 * @package northstaronlineordering
 */

 global $cartBlockOptionKey;
 global $cacheKey;
 $cacheKey = $cache_key;

 // Generate a unique key for the option
 $cartBlockOptionKey = 'cart_block_attributes_' . wp_generate_uuid4();
 $id = "";

if (!isset($attributes['shortcodes']) || !is_string($attributes['shortcodes'])) {
    error_log('Cart block: shortcodes attribute must be a string');
    return '<p><em>Cart block: Configuration error - invalid shortcode format</em></p>';
}

if (preg_match("/id=['\"]?(\d+)['\"]?/", $attributes['shortcodes'], $matches)) {
    $id = $matches[1];
}else{
    // Handle missing or invalid shortcode ID
    error_log('Cart block: Invalid or missing shortcode ID in attributes');
    return '<p><em>Cart block: Configuration error - missing shortcode ID</em></p>';
}

if (empty($id)) {
    error_log('Cart block: Cannot store empty ID in options');
    return '<p><em>Cart block: Configuration error - missing shortcode ID</em></p>';
}

update_option($cartBlockOptionKey, $id);

$shortcodeTag = "woocommerce_prl_recommendations";
$shortcode_id = $shortcodeTag.'_'.$id;
$cache_key = 'shortcode_cache_' . $shortcode_id;

// Generate or retrieve cached HTML
$cached_html = get_transient($cache_key);
if (!$cached_html && shortcode_exists($shortcodeTag)) {
    $cached_html = do_shortcode("[".$shortcodeTag." id='".$id."']");
    set_transient($cache_key, $cached_html, 36000);
}


 // Output the block container
 ?>
 <div id="block-container-cart" data-option-key="<?php echo esc_attr($cartBlockOptionKey); ?>" data-cache-key="<?php echo esc_attr($cache_key); ?>" data-testid="cart-block-root">
    <?php
    $is_editor = ( function_exists('wp_is_block_editor') && wp_is_block_editor() )
    || ( defined('REST_REQUEST') && REST_REQUEST );

    if ( class_exists('WooCommerce') && ! $is_editor ) {
        echo apply_filters('cbs_cart_content', '');
    } else {
        echo '<p><em>Cart block preview (hidden content in editor)</em></p>';
    }
     ?>
</div>
<?php
