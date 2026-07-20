<?php

use CBSNorthStar\Woapi\Connection;

if ( is_readable( plugin_dir_path(__FILE__) . '/vendor/autoload.php' ) ) {
    require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';
}

if (!function_exists('write_log')) {

    /**
     * Backward-compatible write_log() — routes to CBSLogger::general()->debug().
     *
     * @deprecated Prefer CBSLogger directly for new code. It provides structured,
     *             channel-specific logging with proper log levels.
     *             Use channel-specific calls, e.g.:
     *               CBSLogger::orders()->error('...', $context);
     *               CBSLogger::api()->debug('...');
     *
     * @param mixed $log
     */
    function write_log($log): void {
        if (class_exists(\CBSNorthStar\Logger\CBSLogger::class)) {
            \CBSNorthStar\Logger\CBSLogger::general()->debug($log);
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log((string) $log);
            }
        }
    }

}
if (!function_exists('get_post_id_by_meta_key_and_value')) {
	/**
	 * Get post id from meta key and value
	 * @param string $key
	 * @param mixed $value
	 * @return int|bool
	 * @author
	 */
	function get_post_id_by_meta_key_and_value($key, $value) {
		global $wpdb;
		$meta = $wpdb->get_results("SELECT * FROM `".$wpdb->postmeta."` WHERE meta_key='".$wpdb->escape($key)."' AND meta_value='".$wpdb->escape($value)."'");
		if (is_array($meta) && !empty($meta) && isset($meta[0])) {
			$meta = $meta[0];
		}
		if (is_object($meta)) {
			return $meta->post_id;
		}
		else {
			return false;
		}
	}
}

add_filter( 'woocommerce_locate_template', 'interceptWcTemplate', 10, 3 );
/**
 * Filter the cart template path to use cart.php in this plugin instead of the one in WooCommerce.
 *
 * @param string $template      Default template file path.
 * @param string $template_name Template file slug.
 * @param string $template_path Template file name.
 *
 * @return string The new Template file path.
 */

 if (!function_exists('interceptWcTemplate')) {
	function interceptWcTemplate( $template, $template_name, $template_path ) {

		$template_directory = trailingslashit( plugin_dir_path( __FILE__ ) ) . 'woocommerce/';
		$path = $template_directory . $template_name;
	
		return file_exists( $path ) ? $path : $template;
	
	}
 }

add_filter( 'woocommerce_default_address_fields', 'checkoutAddressFields', 999 );
 if (!function_exists('checkoutAddressFields')) {
	function checkoutAddressFields( $fields ) {
		$fields['first_name']['required']   = false;
		$fields['last_name']['required']    = false;
		$fields['country']['required']      = false;
		if( carbon_get_theme_option('olo_make_address_field_1_required') ){
			$fields['address_1']['required']   = true;
		} else {
			$fields['address_1']['required']   = false;
		}
		$fields['city']['required']         = false;
		$fields['state']['required']        = false;
		$fields['postcode']['required']     = false;

		return $fields;
	}
 }


add_filter( 'woocommerce_billing_fields', 'checkoutContactFields');
if (!function_exists('checkoutContactFields')) {
function checkoutContactFields( $fields ) {
    $fields['billing_email']['required']    = false;
    return $fields;
}
}

add_action('carbon_fields_theme_options_container_saved', function () {

    $make_required = (bool) carbon_get_theme_option('olo_make_address_field_2_required');
    $desired = $make_required ? 'required' : 'optional';

    update_option('woocommerce_checkout_address_2_field', $desired);

    // Mutual exclusion: both tipping flags cannot be true simultaneously.
    // If both are checked on save, prefer 'hide_no_thanks_olo_tipping' and clear the other.
    if ( carbon_get_theme_option('hide_no_thanks_olo_tipping') && carbon_get_theme_option('olo_no_thanks_tip') ) {
        carbon_set_theme_option('olo_no_thanks_tip', false);
    }

}, 10, 0);

// Self-heal stale data from sites that had both flags saved as true before
// the mutual-exclusion rule was introduced. Runs once until corrected.
add_action('admin_init', function () {
    if ( function_exists('carbon_get_theme_option') && function_exists('carbon_set_theme_option') ) {
        if ( carbon_get_theme_option('hide_no_thanks_olo_tipping') && carbon_get_theme_option('olo_no_thanks_tip') ) {
            carbon_set_theme_option('olo_no_thanks_tip', false);
        }
    }
});