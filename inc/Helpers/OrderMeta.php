<?php

namespace CBSNorthStar\Helpers;

/**
 * HPOS-compatible CRUD wrapper for CBS NorthStar order metadata (OE-26645).
 *
 * Replaces legacy direct post meta calls (get_post_meta / update_post_meta)
 * to ensure compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
class OrderMeta {
    private const ALLOWED_KEYS = [
        'cbs_orderid',
        'cbs_siteid',
        'cbs_checknumber',
        'cbs_orderFinalized',
    ];

    /**
     * Retrieves a CBS meta value from an order using the WooCommerce CRUD API.
     *
     * @param \WC_Order|int|string $order The WooCommerce order object or order ID.
     * @param string               $key   The meta key to retrieve.
     * @param bool                 $single Whether to return a single value or an array.
     * @return mixed The meta value, or null/empty if invalid.
     */
    public static function get( $order, string $key, bool $single = true ) {
        $order_obj = self::resolve_order( $order );
        if ( ! $order_obj || ! self::is_allowed_key( $key ) ) {
            return $single ? '' : [];
        }

        return $order_obj->get_meta( $key, $single );
    }

    /**
     * Updates or adds a CBS meta value to an order using the WooCommerce CRUD API.
     *
     * @param \WC_Order|int|string $order The WooCommerce order object or order ID.
     * @param string               $key   The meta key to update.
     * @param mixed                $value The value to store.
     * @param bool                 $save  Whether to immediately persist changes to the database.
     * @return bool True on successful update, false otherwise.
     */
    public static function set( $order, string $key, $value, bool $save = true ): bool {
        $order_obj = self::resolve_order( $order );
        if ( ! $order_obj || ! self::is_allowed_key( $key ) ) {
            return false;
        }

        $order_obj->update_meta_data( $key, $value );

        if ( $save ) {
            $order_obj->save();
        }

        return true;
    }

    /**
     * Helper to resolve an order ID or object into a valid WC_Order instance.
     *
     * @param \WC_Order|int|string $order
     * @return \WC_Order|false
     */
    private static function resolve_order( $order ) {
        if ( $order instanceof \WC_Order ) {
            return $order;
        }

        if ( is_numeric( $order ) && $order > 0 && function_exists( 'wc_get_order' ) ) {
            return wc_get_order( $order );
        }

        return false;
    }

    /**
     * Validates if the key belongs to the allowed CBS order meta schema.
     */
    private static function is_allowed_key( string $key ): bool {
        return in_array( $key, self::ALLOWED_KEYS, true );
    }
}