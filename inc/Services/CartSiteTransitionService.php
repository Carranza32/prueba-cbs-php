<?php
namespace CBSNorthStar\Services;

use CBSNorthStar\Services\Concerns\RepricesCartItems;

class CartSiteTransitionService {

    use RepricesCartItems;

    /**
     * Transition cart items from the old site to $newSiteId.
     * Items that exist in the new site are kept with updated prices.
     * Items that do not exist are silently removed.
     */
    public function transition( string $newSiteId ): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        $cart = WC()->cart->get_cart();

        if ( empty( $cart ) ) {
            return;
        }

        foreach ( $cart as $cartKey => $cartItem ) {
            $oldProductId = $cartItem['product_id'];
            $itemId       = get_post_meta( $oldProductId, '_itemid', true );

            if ( empty( $itemId ) ) {
                WC()->cart->remove_cart_item( $cartKey );
                continue;
            }

            $newProductId = $this->findProductByItemId( $itemId, $newSiteId );

            if ( ! $newProductId ) {
                WC()->cart->remove_cart_item( $cartKey );
                continue;
            }

            $newProduct = wc_get_product( $newProductId );

            if ( ! $newProduct || ! $newProduct->is_purchasable() ) {
                WC()->cart->remove_cart_item( $cartKey );
                continue;
            }

            $newTotalPrice = $this->recalculateTotalPrice( $newProductId, $cartItem );

            if ( $newTotalPrice === null ) {
                // Selected component or serving option missing in new site — drop line rather than undercharge.
                WC()->cart->remove_cart_item( $cartKey );
                continue;
            }

            WC()->cart->cart_contents[ $cartKey ]['product_id'] = $newProductId;
            WC()->cart->cart_contents[ $cartKey ]['data']        = $newProduct;
            WC()->cart->cart_contents[ $cartKey ]['total_price'] = $newTotalPrice;
        }

        WC()->cart->calculate_totals();
    }

    /**
     * Find a published WC product matching $itemId at $siteId.
     *
     * Site switch is not menu-scoped: a cart line is matched by item + site only,
     * so the same dish carries over regardless of which daypart menu it lived on.
     */
    private function findProductByItemId( string $itemId, string $siteId ): ?int {
        return $this->findProductByMetaQuery( [
            'relation' => 'AND',
            [
                'key'     => '_itemid',
                'value'   => $itemId,
                'compare' => '=',
            ],
            [
                'key'     => '_siteid',
                'value'   => $siteId,
                'compare' => '=',
            ],
        ] );
    }
}
