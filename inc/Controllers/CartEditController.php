<?php
/**
 * Cart Edit Controller
 *
 */

namespace CBSNorthStar\Controllers;

use CBSNorthStar\Helpers\BuildNumberHelper;

defined('ABSPATH') || exit;

class CartEditController {

    private const NONCE_ACTION = 'northstar_edit_cart_item';
    private const POST_KEY     = 'edit_cart_item_key';
    private const POST_NONCE   = 'edit_cart_item_nonce';

    public static function init(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueueScripts']);
        add_filter('woocommerce_add_to_cart_redirect', [__CLASS__, 'redirectAfterEdit'], 10, 1);
        add_action('woocommerce_add_to_cart', [__CLASS__, 'removeOldItemAfterAdd'], 20, 1);
    }

    public static function enqueueScripts(): void {
        if ( ! is_product() || empty($_GET['edit_cart_item']) ) {
            return;
        }

        if ( ! function_exists('WC') || ! WC()->cart ) {
            return;
        }

        wp_enqueue_script(
            'northstar-cart-edit',
            plugins_url('/js/cartEdit.js', CBS_PLUGIN_FILE),
            ['jquery', 'wc-add-to-cart'],
            BuildNumberHelper::getBuildNumber(),
            true
        );

        $cart_item_key = sanitize_text_field( wp_unslash($_GET['edit_cart_item']) );
        $cart_item_data = self::getCartItemData($cart_item_key);

        if ( $cart_item_data ) {
            wp_localize_script('northstar-cart-edit', 'northstarCartEdit', $cart_item_data);
        }
    }

    public static function redirectAfterEdit(string $url): string {
        if ( isset($_POST['add-to-cart'], $_POST[self::POST_KEY], $_POST[self::POST_NONCE]) ) {
            return wc_get_cart_url();
        }
        return $url;
    }

    /**
     * Removes the old cart item once the new one is in the cart.
     *
     * @param string $new_cart_item_key
     * @param int    $product_id
     * @param int    $quantity
     * @param int    $variation_id
     * @param array  $variation
     * @param array  $cart_item_data
     */
    public static function removeOldItemAfterAdd(
        $new_cart_item_key
    ): void {
        if ( empty($_POST['add-to-cart']) || empty($_POST[self::POST_KEY]) || empty($_POST[self::POST_NONCE]) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash($_POST[self::POST_NONCE]) );
        if ( ! wp_verify_nonce($nonce, self::NONCE_ACTION) ) {
            return;
        }

        if ( ! function_exists('WC') || ! WC()->cart ) {
            return;
        }

        $old_key = sanitize_text_field( wp_unslash($_POST[self::POST_KEY]) );


        if ( $old_key === $new_cart_item_key ) {
            return;
        }

        $cart = WC()->cart->get_cart();
        if ( isset($cart[$old_key]) ) {
            WC()->cart->remove_cart_item($old_key);
        }
    }

    private static function getCartItemData(string $cart_item_key): ?array {
        if ( ! function_exists('WC') || ! WC()->cart ) {
            return null;
        }

        $cart = WC()->cart->get_cart();
        if ( ! isset($cart[$cart_item_key]) ) {
            return null;
        }

        $cart_item = $cart[$cart_item_key];
        $cart_item_selected_for = isset($cart_item['item_selected_for']) ? sanitize_text_field($cart_item['item_selected_for']) : '';

        return [
            'isEditing'      => true,
            'cartItemKey'    => $cart_item_key,
            'productId'      => (int) ($cart_item['product_id'] ?? 0),
            'quantity'       => (int) ($cart_item['quantity'] ?? 1),
            'components'     => self::parseMeta($cart_item, 'product_component_id'),
            'servingOptions' => self::parseMeta($cart_item, 'product_serving_options'),
            'nonce'          => wp_create_nonce(self::NONCE_ACTION),
            'selectedFor'    => $cart_item_selected_for,
            'i18n'           => [
                'updateCart' => __('Update Cart', 'northstar-online-ordering'),
                'addToCart'  => __('Add to Cart', 'northstar-online-ordering'),
            ],
        ];
    }

    private static function parseMeta(array $cart_item, string $meta_key): array {
        if ( empty($cart_item[$meta_key]) ) {
            return [];
        }

        $data = $cart_item[$meta_key];

        if ( is_array($data) ) {
            return $data;
        }

        if ( is_string($data) ) {
            $decoded = json_decode(trim($data, " \t\n\r\0\x0B'\""), true);
            if ( json_last_error() === JSON_ERROR_NONE && is_array($decoded) ) {
                return ($meta_key === 'product_component_id')
                    ? self::formatComponentsForJs($decoded)
                    : $decoded;
            }
        }

        return [];
    }

    private static function formatComponentsForJs(array $components): array {
        $formatted = [];

        foreach ($components as $id => $data) {
            if ( ! is_array($data) ) {
                continue;
            }

            $position = '';
            $clean_id = (string) $id;

            if ( self::endsWith($clean_id, '_left') ) {
                $clean_id = substr($clean_id, 0, -5);
                $position = 'left';
            } elseif ( self::endsWith($clean_id, '_right') ) {
                $clean_id = substr($clean_id, 0, -6);
                $position = 'right';
            } elseif ( self::endsWith($clean_id, '_whole') ) {
                $clean_id = substr($clean_id, 0, -6);
                $position = 'whole';
            }

            $formatted[] = [
                'componentid'      => $clean_id,
                'position'         => $position,
                'quantity'         => isset($data['quantity']) ? (int) $data['quantity'] : 1,
                'servingOptionIds' => isset($data['servingOptionIds']) && is_array($data['servingOptionIds']) ? $data['servingOptionIds'] : [],
            ];
        }

        return $formatted;
    }

    private static function endsWith(string $haystack, string $needle): bool {
        $len = strlen($needle);
        if ($len === 0) {
            return true;
        }
        return substr($haystack, -$len) === $needle;
    }
    
    /**
     * Get edit URL for cart item
     *
     * @param string     $cart_item_key Cart item key.
     * @param \WC_Product $product Product object.
     * @return string Edit URL.
     */
    public static function getEditUrl( string $cart_item_key, $product ): string {
        return add_query_arg(
            [ 'edit_cart_item' => $cart_item_key ],
            $product->get_permalink()
        );
    }
}
