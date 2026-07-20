<?php

namespace CBSNorthStar\Helpers;

/**
 * Price math for selected components/serving options on the cart, checkout, and
 * thank-you pages (OE-26614). Pure — no WP/WC calls — so it's unit-testable in isolation
 * from the hook-registration code in inc/custom-woocommerce.php that calls it.
 */
class ComponentPricing {

	/**
	 * Line total for a selected component at the given quantity.
	 *
	 * Some components price each additional unit differently (pricingLevels, e.g. 1st free,
	 * 2nd/3rd priced) rather than a flat per-unit price, so the levels actually consumed must
	 * be summed instead of unit price * qty.
	 *
	 * @param array $componentvalue Component payload (componentPrice/componentprice, pricingLevels).
	 * @param int   $qty Selected quantity.
	 */
	public static function calculateLineTotal( array $componentvalue, int $qty ): float {
		$pricingLevels = $componentvalue['pricingLevels'] ?? [];

		if ( ! empty( $pricingLevels ) ) {
			$total = 0.0;
			for ( $level = 1; $level <= $qty; $level++ ) {
				$total += (float) ( $pricingLevels[ $level ]['price'] ?? 0 );
			}
			return $total;
		}

		$unitPrice = (float) ( $componentvalue['componentPrice'] ?? $componentvalue['componentprice'] ?? 0 );
		return $unitPrice * $qty;
	}

	/**
	 * Whether a default component at the given category position is free under the
	 * category rules (OE-26648). Mirrors calculatePriceBaseOnRules() in rules.js for
	 * the quick-add case, where every priced component is a default with one instance,
	 * so its ordinal among the selected defaults equals its category position.
	 *
	 * @param array $rules    Category rules (FreeUpTo, DefaultComponentsAreFree, ...).
	 * @param int   $position 1-based position of the component within the category selection.
	 */
	public static function isDefaultComponentFree( array $rules, int $position ): bool {
		// Default components are always free, at any quantity/position.
		if ( ! empty( $rules['DefaultComponentsAreFree'] ) ) {
			return true;
		}

		// FreeUpTo is positional: first N components in the category are free.
		if ( ! empty( $rules['FreeUpTo'] ) && $position <= (int) $rules['FreeUpTo'] ) {
			return true;
		}

		if ( ! empty( $rules['FirstDefaultComponentsLevelsFree'] ) && $position <= (int) $rules['FirstDefaultComponentsLevelsFree'] ) {
			return true;
		}

		if ( ! empty( $rules['FreeAfter'] ) && $position > (int) $rules['FreeAfter'] ) {
			return true;
		}

		return false;
	}
}
