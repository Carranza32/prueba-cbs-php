<?php
namespace CBSNorthStar\Services;

use CBSNorthStar\Services\Concerns\RepricesCartItems;
use CBSNorthStar\Helpers\ProductScope;

/**
 * Transition an in-progress cart when the active daypart MENU changes while the
 * site stays the same (e.g. breakfast → lunch at noon). Mirrors
 * {@see CartSiteTransitionService} but scopes the product lookup by the new menu
 * as well as the site, so a line is only kept if the same dish exists on the new
 * daypart's menu.
 *
 * Unlike the site switch (which drops missing lines silently), the daypart flow
 * needs to warn the user first — so this service separates inspection from
 * mutation: {@see self::preview()} reports survivors/missing without touching the
 * cart, {@see self::apply()} performs the reprice + removal after the user has
 * acknowledged any removals.
 */
class CartMenuTransitionService {

    use RepricesCartItems;

    /**
     * Inspect the cart against $newMenuId WITHOUT mutating it.
     *
     * @return array{survivors: array<int,array{key:string,name:string}>, missing: array<int,array{key:string,name:string}>}
     */
    public function preview( string $siteId, string $newMenuId ): array {
        return $this->evaluate( $siteId, $newMenuId, false );
    }

    /**
     * Reprice surviving lines to the new menu and remove lines that do not exist
     * on it, then recalculate totals.
     *
     * @return array{survivors: array<int,array{key:string,name:string}>, missing: array<int,array{key:string,name:string}>}
     *         `missing` is the set of lines that were removed.
     */
    public function apply( string $siteId, string $newMenuId ): array {
        $result = $this->evaluate( $siteId, $newMenuId, true );

        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->calculate_totals();
        }

        return $result;
    }

    /**
     * Walk the cart once, classifying each line as a survivor (exists + repriceable
     * on the new menu) or missing. When $mutate is true, survivors are re-pointed to
     * the new product and missing lines are removed.
     */
    private function evaluate( string $siteId, string $newMenuId, bool $mutate ): array {
        $survivors = [];
        $missing   = [];

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return [ 'survivors' => $survivors, 'missing' => $missing ];
        }

        $cart = WC()->cart->get_cart();

        // Empty cart, or unresolved scope: do nothing. Never destroy a cart we
        // cannot safely re-match — the caller falls back to leaving it untouched.
        if ( empty( $cart ) || '' === $siteId || '' === $newMenuId ) {
            return [ 'survivors' => $survivors, 'missing' => $missing ];
        }

        foreach ( $cart as $cartKey => $cartItem ) {
            $oldProductId = $cartItem['product_id'];
            $itemName     = ( isset( $cartItem['data'] ) && $cartItem['data'] instanceof \WC_Product )
                ? $cartItem['data']->get_name()
                : get_the_title( $oldProductId );
            $itemId       = get_post_meta( $oldProductId, '_itemid', true );

            $newProductId = $itemId ? $this->findProductByItemId( $itemId, $siteId, $newMenuId ) : null;
            $newProduct   = $newProductId ? wc_get_product( $newProductId ) : null;
            $newTotal     = ( $newProduct && $newProduct->is_purchasable() )
                ? $this->recalculateTotalPrice( $newProductId, $cartItem )
                : null;

            // Drop the line when the item is absent from the new menu, not
            // purchasable, or a selected modifier has no price there (dropping
            // beats undercharging — same rule as the site transition).
            if ( ! $newProductId || ! $newProduct || ! $newProduct->is_purchasable() || null === $newTotal ) {
                $missing[] = [ 'key' => (string) $cartKey, 'name' => (string) $itemName ];
                if ( $mutate ) {
                    WC()->cart->remove_cart_item( $cartKey );
                }
                continue;
            }

            $survivors[] = [ 'key' => (string) $cartKey, 'name' => (string) $itemName ];
            if ( $mutate ) {
                WC()->cart->cart_contents[ $cartKey ]['product_id']  = $newProductId;
                WC()->cart->cart_contents[ $cartKey ]['data']        = $newProduct;
                WC()->cart->cart_contents[ $cartKey ]['total_price'] = $newTotal;
            }
        }

        return [ 'survivors' => $survivors, 'missing' => $missing ];
    }

    /**
     * Find a published WC product matching $itemId scoped to BOTH $siteId and the
     * new $newMenuId. The menu clause is what makes this a daypart transition
     * rather than a site transition.
     */
    private function findProductByItemId( string $itemId, string $siteId, string $newMenuId ): ?int {
        return $this->findProductByMetaQuery(
            ProductScope::metaQuery( $siteId, $newMenuId, [
                [
                    'key'     => '_itemid',
                    'value'   => $itemId,
                    'compare' => '=',
                ],
            ] )
        );
    }
}
