<?php

namespace CBSNorthStar\Tests;

use CBSNorthStar\SaveProduct;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for SaveProduct::computeItemFingerprint() — component pricing-level
 * coverage (change: fix-incremental-deploy-component-price-layers).
 *
 * A component node carries only PricingLevelId references; its Layer/Price
 * definitions live in the per-menu pricingLevelMap. The fingerprint must hash
 * those resolved definitions so a component price-layer edit — which never
 * alters the component node itself — still invalidates every item that uses
 * the component. Exercised via reflection on an instance built without the
 * DB-coupled constructor (same pattern as SaveProductMenuSkipGuardTest).
 */
final class SaveProductItemFingerprintTest extends TestCase {

	private const ITEM_ID = 'item-1';
	private const SITE_ID = 'site-1';

	/**
	 * @param array<string,mixed> $overrides Property name => value overrides.
	 */
	private function computeFingerprint( array $overrides = [] ): ?string {
		$instance = ( new \ReflectionClass( SaveProduct::class ) )->newInstanceWithoutConstructor();

		$properties = array_merge( $this->baseProperties(), $overrides );
		foreach ( $properties as $name => $value ) {
			$property = new ReflectionProperty( SaveProduct::class, $name );
			$property->setAccessible( true );
			$property->setValue( $instance, $value );
		}

		$method = new ReflectionMethod( SaveProduct::class, 'computeItemFingerprint' );
		$method->setAccessible( true );
		return $method->invoke( $instance, self::ITEM_ID, self::SITE_ID );
	}

	/**
	 * Baseline: one item with one component; the component references two
	 * pricing levels (layers 1 and 2), the item references its own level.
	 *
	 * @return array<string,mixed>
	 */
	private function baseProperties(): array {
		return [
			'menuHashSkipEnabled' => true,
			'forceFullDeploy'     => false,
			'pendingAuxHashes'    => [ 'rules' => 'rules-hash', 'serving' => 'serving-hash' ],
			'menuItemMap'         => [ self::ITEM_ID => $this->itemNode() ],
			'componentMap'        => [ 'comp-1' => $this->componentNode( [ 'pl-c1', 'pl-c2' ] ) ],
			'componentCategoryMap'=> [ 'cat-1' => (object) [ 'ComponentCategoryId' => 'cat-1', 'Name' => 'Toppings' ] ],
			'pricingLevelMap'     => $this->pricingLevelMap(),
			// Pre-populated cache-hit so computeItemFingerprint()'s call to
			// getMenuItemDatesForSite() never falls through to the DB/HTTP-coupled
			// ProductManager path in this pure-reflection unit test.
			'menuItemDatesCache'  => [ self::SITE_ID => [] ],
		];
	}

	private function itemNode(): object {
		return (object) [
			'MenuItemId'    => self::ITEM_ID,
			'Name'          => 'Burger',
			'Components'    => [
				(object) [
					'ComponentId'       => 'comp-1',
					'IsDefault'         => false,
					'ComponentCategory' => (object) [ 'ComponentCategoryId' => 'cat-1' ],
				],
			],
			'PricingLevels' => (object) [
				'PricingLevel' => [ (object) [ 'PricingLevelId' => 'pl-item' ] ],
			],
		];
	}

	/**
	 * @param array<int,string>|object $levelRefs PricingLevelId list, or a bare
	 *                                            object to simulate the WOAPI
	 *                                            single-level shape.
	 */
	private function componentNode( $levelRefs ): object {
		$refs = is_array( $levelRefs )
			? array_map( static fn( $id ) => (object) [ 'PricingLevelId' => $id ], $levelRefs )
			: $levelRefs;

		return (object) [
			'ComponentId'   => 'comp-1',
			'Name'          => 'Cheese',
			'PricingLevels' => (object) [ 'PricingLevel' => $refs ],
		];
	}

	/**
	 * @param float $layer2Price Price for the component's layer-2 definition.
	 * @param float $unrelatedPrice Price for a definition nothing references.
	 * @return array<string,object>
	 */
	private function pricingLevelMap( float $layer2Price = 1.50, float $unrelatedPrice = 9.99 ): array {
		return [
			'pl-item'      => (object) [ 'PricingLevelId' => 'pl-item', 'Layer' => 1, 'Price' => 10.00 ],
			'pl-c1'        => (object) [ 'PricingLevelId' => 'pl-c1', 'Layer' => 1, 'Price' => 1.00 ],
			'pl-c2'        => (object) [ 'PricingLevelId' => 'pl-c2', 'Layer' => 2, 'Price' => $layer2Price ],
			'pl-c3'        => (object) [ 'PricingLevelId' => 'pl-c3', 'Layer' => 3, 'Price' => 2.00 ],
			'pl-unrelated' => (object) [ 'PricingLevelId' => 'pl-unrelated', 'Layer' => 1, 'Price' => $unrelatedPrice ],
		];
	}

	// ── Spec: Component layer price edited in ECM ──────────────────────────────

	public function test_component_layer_price_change_alters_fingerprint(): void {
		$baseline = $this->computeFingerprint();
		$edited   = $this->computeFingerprint( [ 'pricingLevelMap' => $this->pricingLevelMap( 2.00 ) ] );

		$this->assertNotNull( $baseline );
		$this->assertNotSame( $baseline, $edited, 'A price edit on a component-referenced pricing level must change the item fingerprint even though the item and component nodes are untouched.' );
	}

	// ── Spec: New layer attached to a component ────────────────────────────────

	public function test_new_component_level_reference_alters_fingerprint(): void {
		$baseline = $this->computeFingerprint();
		$attached = $this->computeFingerprint( [
			'componentMap' => [ 'comp-1' => $this->componentNode( [ 'pl-c1', 'pl-c2', 'pl-c3' ] ) ],
		] );

		$this->assertNotSame( $baseline, $attached, 'A component gaining a pricing-level reference must change the item fingerprint.' );
	}

	public function test_definition_disappearing_from_map_alters_fingerprint(): void {
		$withoutLayer2 = $this->pricingLevelMap();
		unset( $withoutLayer2['pl-c2'] );

		$baseline = $this->computeFingerprint();
		$missing  = $this->computeFingerprint( [ 'pricingLevelMap' => $withoutLayer2 ] );

		$this->assertNotSame( $baseline, $missing, 'A referenced definition vanishing from the per-menu map must change the fingerprint (null marker).' );
	}

	// ── Spec: Unrelated pricing-level change does not invalidate ───────────────

	public function test_unrelated_pricing_level_change_keeps_fingerprint(): void {
		$baseline  = $this->computeFingerprint();
		$unrelated = $this->computeFingerprint( [ 'pricingLevelMap' => $this->pricingLevelMap( 1.50, 5.55 ) ] );

		$this->assertSame( $baseline, $unrelated, 'A pricing level referenced by neither the item nor its components must not perturb the fingerprint (component-precise invalidation).' );
	}

	// ── Spec: Active-date window change (product-active-date-window / OE-26686) ───

	public function test_start_date_change_alters_fingerprint(): void {
		$baseline = $this->computeFingerprint( [
			'menuItemDatesCache' => [ self::SITE_ID => [
				self::ITEM_ID => (object) [ 'StartDate' => '2026-07-14T14:00:00+00:00', 'EndDate' => null ],
			] ],
		] );
		$edited = $this->computeFingerprint( [
			'menuItemDatesCache' => [ self::SITE_ID => [
				self::ITEM_ID => (object) [ 'StartDate' => '2026-07-14T14:30:00+00:00', 'EndDate' => null ],
			] ],
		] );

		$this->assertNotNull( $baseline );
		$this->assertNotSame(
			$baseline,
			$edited,
			'A Start/EndDate-only ECM edit must change the item fingerprint even though the /menu/ item node and its components are untouched — StartDate/EndDate live only in the /menuitems response (product-active-date-window / OE-26686).'
		);
	}

	public function test_no_date_entry_still_fingerprints(): void {
		$fingerprint = $this->computeFingerprint(); // baseProperties() default: no entry for ITEM_ID in menuItemDatesCache
		$this->assertNotNull( $fingerprint, 'An item with no active-date window configured must still fingerprint without error.' );
	}

	// ── Spec: Single-object component pricing-level node ───────────────────────

	public function test_single_object_component_pricing_level_is_normalized(): void {
		$singleRef = $this->componentNode( (object) [ 'PricingLevelId' => 'pl-c1' ] );

		$baseline = $this->computeFingerprint( [ 'componentMap' => [ 'comp-1' => $singleRef ] ] );
		$this->assertNotNull( $baseline, 'A bare-object PricingLevel (WOAPI single-level shape) must fingerprint without error.' );

		$map                = $this->pricingLevelMap();
		$map['pl-c1']->Price = 1.25;
		$edited             = $this->computeFingerprint( [
			'componentMap'    => [ 'comp-1' => $singleRef ],
			'pricingLevelMap' => $map,
		] );

		$this->assertNotSame( $baseline, $edited, 'The single referenced definition must be part of the hashed input.' );
	}
}
