<?php

namespace CBSNorthStar\Helpers;

/**
 * Rule-based "is this component instance free" evaluation for the ECM order payload (OE-26645).
 * Pure — no WP/WC calls — mirrors src/product-detail/view.js's isInstanceFree()/calculateCategoryTotal()
 * so the two stay in sync without a runtime dependency between them.
 */
class ComponentFreeRules {

	/**
     * Rule precedence (short-circuit): FreeUpTo -> DefaultComponentsAreFree -> FirstDefaultComponentsLevelsFree -> FreeAfter -> FreeEvery.
     * An instance marked free by an earlier rule returns immediately, preventing discount double-counting.
     *
     * @param array $rule Rule fields for this component's ruleId (FreeUpTo, DefaultComponentsAreFree,
     *                     FirstDefaultComponentsLevelsFree, FreeAfter, FreeEvery). Missing/falsy keys mean disabled.
     */
	public static function isInstanceFree( bool $isDefault, array $rule, int $position, int $instanceNumber ): bool {
		if ( ! empty( $rule['FreeUpTo'] ) && $position <= $rule['FreeUpTo'] ) {
			return true;
		}

		if ( ! empty( $rule['DefaultComponentsAreFree'] ) && $isDefault ) {
			return true;
		}

		if ( ! empty( $rule['FirstDefaultComponentsLevelsFree'] ) && $isDefault && $instanceNumber <= $rule['FirstDefaultComponentsLevelsFree'] ) {
			return true;
		}

		if ( ! empty( $rule['FreeAfter'] ) && $position > $rule['FreeAfter'] ) {
			return true;
		}

		// --- Task 1: FreeEvery promotional rule (every Nth selected instance is free) ---
		if ( ! empty( $rule['FreeEvery'] ) && (int) $rule['FreeEvery'] > 0 && $position % (int) $rule['FreeEvery'] === 0 ) {
            return true;
        }

		return false;
	}

	/**
	 * @param array $orderedComponents Ordered list (original selection order) of
	 *                                 ['componentId' => string, 'categoryId' => string, 'ruleId' => string,
	 *                                  'isDefault' => bool, 'quantity' => int].
	 * @param array $rulesByRuleId     Map of ruleId => rule fields (see isInstanceFree()).
	 * @return string[] "componentId:instanceNumber" keys that are free.
	 */
	public static function computeFreeInstanceKeys( array $orderedComponents, array $rulesByRuleId ): array {
		$byCategory = [];
		foreach ( $orderedComponents as $component ) {
			$byCategory[ $component['categoryId'] ][] = $component;
		}

		$freeKeys = [];

		foreach ( $byCategory as $components ) {
			$position       = 0;
			$instanceCounts = [];

			foreach ( $components as $component ) {
				$rule = $rulesByRuleId[ $component['ruleId'] ] ?? [];
				$quantity = max( 1, (int) $component['quantity'] );

				for ( $i = 0; $i < $quantity; $i++ ) {
					$position++;
					$componentId = $component['componentId'];
					$instanceCounts[ $componentId ] = ( $instanceCounts[ $componentId ] ?? 0 ) + 1;

					if ( self::isInstanceFree( $component['isDefault'], $rule, $position, $instanceCounts[ $componentId ] ) ) {
						$freeKeys[] = "{$componentId}:{$instanceCounts[ $componentId ]}";
					}
				}
			}
		}

		return $freeKeys;
	}
}
