<?php
namespace CBSNorthStar\Services\Concerns;

/**
 * Shared cart-item reprice helpers, used whenever a cart line must be re-pointed
 * to a different product and its total recomputed against that product's pricing
 * — the site switch ({@see \CBSNorthStar\Services\CartSiteTransitionService}) and
 * the daypart change ({@see \CBSNorthStar\Services\CartMenuTransitionService}).
 *
 * Pricing rule: base price + selected components + selected serving options.
 * recalculateTotalPrice() returns null when a selected modifier has no price on
 * the target product, signalling the caller to drop the line rather than
 * undercharge.
 */
trait RepricesCartItems {

    /**
     * Run a single-result product lookup and return the matching published product
     * id, or null. The caller supplies the full meta_query so each transition can
     * scope by its own criteria (site only, or site + menu).
     *
     * @param array $metaQuery A WP_Query meta_query array assembled by the caller.
     */
    protected function findProductByMetaQuery( array $metaQuery ): ?int {
        $query = new \WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => $metaQuery,
        ] );

        return ! empty( $query->posts ) ? (int) $query->posts[0] : null;
    }

    /**
     * Build a flat map of componentId => price for a product.
     */
    protected function buildComponentPriceMap( int $productId ): array {
        $raw = get_post_meta( $productId, '_components', true );
        if ( empty( $raw ) ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        $map = [];
        foreach ( $decoded as $components ) {
            foreach ( $components as $component ) {
                if ( isset( $component['componentId'], $component['componentprice'] ) ) {
                    $map[ $component['componentId'] ] = (float) $component['componentprice'];
                }
            }
        }

        return $map;
    }

    /**
     * Build a flat map of servingOptionId => price for a product.
     */
    protected function buildServingOptionPriceMap( int $productId ): array {
        $raw = get_post_meta( $productId, '_servingoptions', true );
        if ( empty( $raw ) ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        $map = [];
        foreach ( $decoded as $options ) {
            foreach ( $options as $option ) {
                if ( isset( $option['servingOptionId'], $option['servingOptionPrice'] ) ) {
                    $map[ $option['servingOptionId'] ] = (float) $option['servingOptionPrice'];
                }
            }
        }

        return $map;
    }

    /**
     * Recalculate total price using the target product's data.
     * base + selected components + selected serving options.
     * Returns null if any selected modifier is missing on the target product.
     */
    protected function recalculateTotalPrice( int $newProductId, array $cartItem ): ?float {
        // Re-fetch defensively: the product could be deleted/trashed between the
        // caller's purchasable check and here (e.g. a concurrent ECM deploy). Drop
        // the line rather than fatal on ->get_price() of a false.
        $product = wc_get_product( $newProductId );
        if ( ! $product ) {
            return null;
        }
        $base = (float) $product->get_price();

        $componentPrices    = $this->buildComponentPriceMap( $newProductId );
        $selectedComponents = json_decode( $cartItem['product_component_id'] ?? '{}', true ) ?: [];
        $componentTotal     = 0.0;
        foreach ( $selectedComponents as $componentId => $data ) {
            if ( ! array_key_exists( $componentId, $componentPrices ) ) {
                return null;
            }
            $qty             = (int) ( $data['quantity'] ?? 1 );
            $componentTotal += $componentPrices[ $componentId ] * $qty;
        }

        $optionPrices    = $this->buildServingOptionPriceMap( $newProductId );
        $selectedOptions = $cartItem['product_serving_options'] ?? [];
        $optionTotal     = 0.0;
        foreach ( $selectedOptions as $option ) {
            $optionId = $option['optionId'] ?? null;
            if ( $optionId === null || ! array_key_exists( $optionId, $optionPrices ) ) {
                return null;
            }
            $optionTotal += $optionPrices[ $optionId ];
        }

        return $base + $componentTotal + $optionTotal;
    }
}
