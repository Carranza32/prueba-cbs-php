<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\ComponentPricing;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ComponentPricing::calculateLineTotal() (OE-26614) and
 * ComponentPricing::isDefaultComponentFree() (OE-26648).
 *
 * Pure math, no WP/WC dependency, so the tiered-vs-flat pricing decision can be exercised
 * in isolation from the hook-registration code in inc/custom-woocommerce.php that renders it.
 */
final class ComponentPricingTest extends TestCase {

	/**
	 * @dataProvider lineTotalProvider
	 */
	public function test_calculate_line_total( array $componentvalue, int $qty, float $expected ): void {
		$this->assertSame( $expected, ComponentPricing::calculateLineTotal( $componentvalue, $qty ) );
	}

	/**
	 * @dataProvider defaultComponentFreeProvider
	 */
	public function test_is_default_component_free( array $rules, int $position, bool $expected ): void {
		$this->assertSame( $expected, ComponentPricing::isDefaultComponentFree( $rules, $position ) );
	}

	/**
	 * @return array<string,array{0:array,1:int,2:bool}>
	 */
	public static function defaultComponentFreeProvider(): array {
		return [
			'DefaultComponentsAreFree frees position 1'          => [ [ 'DefaultComponentsAreFree' => true ], 1, true ],
			'DefaultComponentsAreFree frees any position'        => [ [ 'DefaultComponentsAreFree' => true ], 5, true ],
			'DefaultComponentsAreFree numeric truthy (1)'        => [ [ 'DefaultComponentsAreFree' => 1 ], 3, true ],
			'DefaultComponentsAreFree wins over FreeUpTo window' => [
				[
					'DefaultComponentsAreFree' => true,
					'FreeUpTo'                 => 2,
				],
				5,
				true,
			],
			'FreeUpTo frees positions inside the window'         => [ [ 'FreeUpTo' => 2 ], 2, true ],
			'FreeUpTo charges positions outside the window'      => [ [ 'FreeUpTo' => 2 ], 3, false ],
			'FirstDefaultComponentsLevelsFree inside the window' => [ [ 'FirstDefaultComponentsLevelsFree' => 2 ], 2, true ],
			'FirstDefaultComponentsLevelsFree outside window'    => [ [ 'FirstDefaultComponentsLevelsFree' => 2 ], 3, false ],
			'FreeAfter frees positions past the threshold'       => [ [ 'FreeAfter' => 2 ], 3, true ],
			'FreeAfter charges positions before the threshold'   => [ [ 'FreeAfter' => 2 ], 2, false ],
			'no rules charges'                                   => [ [], 1, false ],
			'MinRequired alone does not free'                    => [ [ 'MinRequired' => 2 ], 1, false ],
		];
	}

	/**
	 * @return array<string,array{0:array,1:int,2:float}>
	 */
	public static function lineTotalProvider(): array {
		return [
			'flat componentPrice (kiosk casing) x qty' => [
				[ 'componentPrice' => 1.50 ],
				3,
				4.50,
			],
			'flat componentprice (legacy web casing) x qty' => [
				[ 'componentprice' => 0.75 ],
				2,
				1.50,
			],
			'missing price defaults to 0' => [
				[],
				3,
				0.0,
			],
			'qty 0 is 0 regardless of price' => [
				[ 'componentPrice' => 5.00 ],
				0,
				0.0,
			],
			'tiered pricingLevels sums the levels consumed' => [
				[
					'componentPrice' => 0.75, // must be ignored once pricingLevels is present
					'pricingLevels'  => [
						1 => [ 'price' => 0.0 ],
						2 => [ 'price' => 0.75 ],
						3 => [ 'price' => 0.75 ],
					],
				],
				3,
				1.50,
			],
			'tiered pricingLevels partial quantity (2 of 3 levels)' => [
				[
					'pricingLevels' => [
						1 => [ 'price' => 0.0 ],
						2 => [ 'price' => 0.75 ],
						3 => [ 'price' => 0.75 ],
					],
				],
				2,
				0.75,
			],
			'tiered pricingLevels missing a level defaults that level to 0' => [
				[
					'pricingLevels' => [
						1 => [ 'price' => 0.50 ],
					],
				],
				3,
				0.50,
			],
		];
	}
}
