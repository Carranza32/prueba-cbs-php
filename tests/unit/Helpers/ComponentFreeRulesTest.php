<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\ComponentFreeRules;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ComponentFreeRules (OE-26645).
 *
 * Pure rule math, no WP/WC dependency, mirroring src/product-detail/view.js's
 * isInstanceFree()/calculateCategoryTotal() so server-side (ECM payload) and
 * frontend (customer-facing price) agree on which component instances are free.
 */
final class ComponentFreeRulesTest extends TestCase {

	/**
	 * @dataProvider isInstanceFreeProvider
	 */
	public function test_is_instance_free( bool $isDefault, array $rule, int $position, int $instanceNumber, bool $expected ): void {
		$this->assertSame( $expected, ComponentFreeRules::isInstanceFree( $isDefault, $rule, $position, $instanceNumber ) );
	}

	public static function isInstanceFreeProvider(): array {
		return [
			'no rules at all' => [ false, [], 1, 1, false ],
			'FreeUpTo covers this position' => [ false, [ 'FreeUpTo' => 3 ], 3, 1, true ],
			'FreeUpTo does not cover this position' => [ false, [ 'FreeUpTo' => 3 ], 4, 1, false ],
			'DefaultComponentsAreFree, first instance, is default' => [ true, [ 'DefaultComponentsAreFree' => true ], 1, 1, true ],
			'DefaultComponentsAreFree, second instance, is default' => [ true, [ 'DefaultComponentsAreFree' => true ], 2, 2, true ],
			'DefaultComponentsAreFree, fifth instance, is default (OE-26648)' => [ true, [ 'DefaultComponentsAreFree' => true ], 5, 5, true ],
			'DefaultComponentsAreFree, first instance, not default' => [ false, [ 'DefaultComponentsAreFree' => true ], 1, 1, false ],
			'DefaultComponentsAreFree, fifth instance, not default' => [ false, [ 'DefaultComponentsAreFree' => true ], 5, 5, false ],
			'FirstDefaultComponentsLevelsFree covers instance' => [ true, [ 'FirstDefaultComponentsLevelsFree' => 2 ], 5, 2, true ],
			'FirstDefaultComponentsLevelsFree does not cover instance' => [ true, [ 'FirstDefaultComponentsLevelsFree' => 2 ], 5, 3, false ],
			'FirstDefaultComponentsLevelsFree, not default' => [ false, [ 'FirstDefaultComponentsLevelsFree' => 2 ], 1, 1, false ],
			'FreeAfter, position beyond threshold' => [ false, [ 'FreeAfter' => 2 ], 3, 1, true ],
			'FreeAfter, position at threshold' => [ false, [ 'FreeAfter' => 2 ], 2, 1, false ],

			'FreeEvery, matches exact multiple' => [ false, [ 'FreeEvery' => 3 ], 3, 1, true ],
            'FreeEvery, does not match non-multiple' => [ false, [ 'FreeEvery' => 3 ], 2, 1, false ],
            'FreeEvery, handles higher multiples' => [ false, [ 'FreeEvery' => 3 ], 6, 2, true ],
            'FreeEvery, ignores zero safely' => [ false, [ 'FreeEvery' => 0 ], 3, 1, false ],
            'FreeEvery, ignores negative values safely' => [ false, [ 'FreeEvery' => -2 ], 4, 1, false ],
			'FreeEvery combined with FreeUpTo (pos 3 free via FreeEvery after FreeUpTo:2)' => [ false, [ 'FreeUpTo' => 2, 'FreeEvery' => 3 ], 3, 1, true ],
		];
	}

	public function test_compute_free_instance_keys_free_up_to_across_component_ids(): void {
		$ordered = [
			[ 'componentId' => 'A', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => false, 'quantity' => 1 ],
			[ 'componentId' => 'B', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => false, 'quantity' => 1 ],
			[ 'componentId' => 'C', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => false, 'quantity' => 1 ],
		];
		$rules = [ 'R1' => [ 'FreeUpTo' => 2 ] ];

		$free = ComponentFreeRules::computeFreeInstanceKeys( $ordered, $rules );

		$this->assertSame( [ 'A:1', 'B:1' ], $free );
	}

	public function test_compute_free_instance_keys_quantity_greater_than_one(): void {
		$ordered = [
			[ 'componentId' => 'A', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => false, 'quantity' => 3 ],
		];
		$rules = [ 'R1' => [ 'FreeUpTo' => 2 ] ];

		$free = ComponentFreeRules::computeFreeInstanceKeys( $ordered, $rules );

		$this->assertSame( [ 'A:1', 'A:2' ], $free );
	}

	public function test_compute_free_instance_keys_resets_per_category(): void {
		$ordered = [
			[ 'componentId' => 'A', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => false, 'quantity' => 1 ],
			[ 'componentId' => 'B', 'categoryId' => 'cat2', 'ruleId' => 'R1', 'isDefault' => false, 'quantity' => 1 ],
		];
		$rules = [ 'R1' => [ 'FreeUpTo' => 1 ] ];

		$free = ComponentFreeRules::computeFreeInstanceKeys( $ordered, $rules );

		$this->assertSame( [ 'A:1', 'B:1' ], $free );
	}

	public function test_compute_free_instance_keys_unmatched_rule_id_is_not_free(): void {
		$ordered = [
			[ 'componentId' => 'A', 'categoryId' => 'cat1', 'ruleId' => 'Default', 'isDefault' => false, 'quantity' => 1 ],
		];

		$free = ComponentFreeRules::computeFreeInstanceKeys( $ordered, [] );

		$this->assertSame( [], $free );
	}

	public function test_compute_free_instance_keys_no_rules_available(): void {
		$ordered = [
			[ 'componentId' => 'A', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => true, 'quantity' => 1 ],
		];

		$free = ComponentFreeRules::computeFreeInstanceKeys( $ordered, [] );

		$this->assertSame( [], $free );
	}

	public function test_compute_free_instance_keys_default_component_free_at_any_quantity(): void {
		$ordered = [
			[ 'componentId' => 'A', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => true, 'quantity' => 5 ],
		];
		$rules = [ 'R1' => [ 'DefaultComponentsAreFree' => true ] ];

		// OE-26648: DefaultComponentsAreFree frees every instance of a default
		// component, not just the first — previously only 'A:1' would be free.
		$free = ComponentFreeRules::computeFreeInstanceKeys( $ordered, $rules );

		$this->assertSame( [ 'A:1', 'A:2', 'A:3', 'A:4', 'A:5' ], $free );
	}

	
	public function test_compute_free_instance_keys_free_every(): void {
        $ordered = [
            [ 'componentId' => 'A', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => false, 'quantity' => 2 ],
            [ 'componentId' => 'B', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => false, 'quantity' => 2 ],
            [ 'componentId' => 'C', 'categoryId' => 'cat1', 'ruleId' => 'R1', 'isDefault' => false, 'quantity' => 2 ],
        ];
        $rules = [ 'R1' => [ 'FreeEvery' => 2 ] ];
        $free = ComponentFreeRules::computeFreeInstanceKeys( $ordered, $rules );

        $this->assertSame( [ 'A:2', 'B:2', 'C:2' ], $free );
    }
}
