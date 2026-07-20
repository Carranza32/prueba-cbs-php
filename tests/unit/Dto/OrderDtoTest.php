<?php

namespace CBSNorthStar\Tests\Dto;

use CBSNorthStar\Dto\OrderDto;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for OrderDto::getComponents() componentName resolution and
 * OrderDto::getComponentPrice()'s price-resolution cascade (component-price-level-fallback).
 *
 * Instantiated via ReflectionClass::newInstanceWithoutConstructor() since the
 * real constructor requires a WC_Order; getComponents()/getComponentPrice() are
 * pure array logic that does not touch $this->order.
 */
final class OrderDtoTest extends TestCase {

	private function makeDto(): OrderDto {
		$reflection = new ReflectionClass( OrderDto::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	private function callGetComponentPrice( OrderDto $dto, array $data, string $componentId, int $quantity ): ?string {
		$reflection = new ReflectionClass( OrderDto::class );
		$method     = $reflection->getMethod( 'getComponentPrice' );
		$method->setAccessible( true );
		return $method->invoke( $dto, $data, $componentId, $quantity );
	}

	public function test_component_name_is_resolved_from_components_data(): void {
		$dto = $this->makeDto();

		$idComponents = [
			'comp-1' => [ 'quantity' => 1 ],
		];
		$componentsData = [
			'cat-1' => [
				[ 'componentId' => 'comp-1', 'componentName' => 'Extra Cheese', 'componentprice' => 1.5 ],
			],
		];

		$components = $dto->getComponents( $idComponents, $componentsData );

		$this->assertSame( 'Extra Cheese', $components[0]['componentName'] );
	}

	public function test_component_name_is_null_when_component_id_not_found(): void {
		$dto = $this->makeDto();

		$idComponents = [
			'comp-missing' => [ 'quantity' => 1 ],
		];
		$componentsData = [
			'cat-1' => [
				[ 'componentId' => 'comp-1', 'componentName' => 'Extra Cheese', 'componentprice' => 1.5 ],
			],
		];

		$components = $dto->getComponents( $idComponents, $componentsData );

		$this->assertArrayHasKey( 'componentName', $components[0] );
		$this->assertNull( $components[0]['componentName'] );
	}

	public function test_component_name_key_is_omitted_when_components_data_is_empty(): void {
		$dto = $this->makeDto();

		$idComponents = [
			'comp-1' => [ 'quantity' => 1 ],
		];

		$components = $dto->getComponents( $idComponents, [] );

		$this->assertArrayNotHasKey( 'componentName', $components[0] );
	}

	public function test_duplicate_component_id_resolves_name_independently_per_instance(): void {
		$dto = $this->makeDto();

		$idComponents = [
			'comp-1' => [ 'quantity' => 2 ],
		];
		$componentsData = [
			'cat-1' => [
				[ 'componentId' => 'comp-1', 'componentName' => 'Extra Cheese', 'componentprice' => 1.5 ],
			],
		];

		$components = $dto->getComponents( $idComponents, $componentsData );

		$this->assertCount( 2, $components );
		$this->assertSame( 'Extra Cheese', $components[0]['componentName'] );
		$this->assertSame( 'Extra Cheese', $components[1]['componentName'] );
	}

	public function test_component_price_returns_exact_level_price(): void {
		$dto  = $this->makeDto();
		$data = [
			'cat-1' => [
				[
					'componentId'   => 'comp-1',
					'pricingLevels' => [ 3 => [ 'price' => '5' ], 4 => [ 'price' => '2' ] ],
				],
			],
		];

		$this->assertSame( '5', $this->callGetComponentPrice( $dto, $data, 'comp-1', 3 ) );
		$this->assertSame( '2', $this->callGetComponentPrice( $dto, $data, 'comp-1', 4 ) );
	}

	public function test_component_price_clamps_to_last_level_when_quantity_exceeds_max(): void {
		$dto  = $this->makeDto();
		$data = [
			'cat-1' => [
				[
					'componentId'   => 'bc6e4f4e-37f9-4443-88f4-a3b2cf6b8dcb',
					'pricingLevels' => [ 3 => [ 'price' => '5' ], 4 => [ 'price' => '2' ] ],
					'componentprice' => '0.50',
				],
			],
		];

		// Reproduces the reported bug: instance 5 has no defined level, but 4 does
		// — it must clamp to level 4's price ("2"), not fall back to the flat
		// componentprice ("0.50") and not return null.
		$this->assertSame( '2', $this->callGetComponentPrice( $dto, $data, 'bc6e4f4e-37f9-4443-88f4-a3b2cf6b8dcb', 5 ) );
	}

	public function test_component_price_falls_back_to_flat_price_for_mid_range_gap(): void {
		$dto  = $this->makeDto();
		$data = [
			'cat-1' => [
				[
					'componentId'     => 'comp-1',
					'pricingLevels'   => [ 1 => [ 'price' => '0.50' ], 3 => [ 'price' => '5' ] ],
					'componentprice'  => '9.00',
				],
			],
		];

		// Level 2 is missing but quantity (2) does not exceed the highest defined
		// level (3), so this is a mid-range gap, not a clamp — must use the flat price.
		$this->assertSame( '9.00', $this->callGetComponentPrice( $dto, $data, 'comp-1', 2 ) );
	}

	public function test_component_price_falls_back_to_flat_price_when_pricing_levels_empty(): void {
		$dto  = $this->makeDto();
		$data = [
			'cat-1' => [
				[ 'componentId' => 'comp-1', 'pricingLevels' => [], 'componentprice' => '1.50' ],
			],
		];

		$this->assertSame( '1.50', $this->callGetComponentPrice( $dto, $data, 'comp-1', 1 ) );
	}

	public function test_component_price_returns_null_when_all_fallbacks_exhausted(): void {
		$dto  = $this->makeDto();
		$data = [
			'cat-1' => [
				[
					'componentId'    => 'comp-1',
					// Mid-range gap (quantity 2 does not exceed the highest defined
					// level, 3) with no flat price to fall back to.
					'pricingLevels'  => [ 1 => [ 'price' => '0.50' ], 3 => [ 'price' => '5' ] ],
					'componentprice' => '',
				],
			],
		];

		$this->assertNull( $this->callGetComponentPrice( $dto, $data, 'comp-1', 2 ) );
	}

	public function test_component_price_ignores_non_numeric_pricing_level_keys(): void {
		$dto  = $this->makeDto();
		$data = [
			'cat-1' => [
				[
					'componentId'    => 'comp-1',
					'pricingLevels'  => [ 'foo' => [ 'price' => '9' ] ],
					'componentprice' => '4.00',
				],
			],
		];

		// No numeric keys survive the clamp-target filter, so this behaves as if
		// pricingLevels were empty — falls back to the flat price.
		$this->assertSame( '4.00', $this->callGetComponentPrice( $dto, $data, 'comp-1', 2 ) );
	}
}
