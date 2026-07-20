<?php

namespace CBSNorthStar\Tests;

use CBSNorthStar\SaveProduct;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for the site aux-hash gate (change:
 * fix-incremental-deploy-component-price-layers).
 *
 * Component definitions (incl. their PricingLevelId refs) exist only in the
 * site root payload, never in a per-menu payload, so the menu-level skip must
 * be gated on a 'components' aux hash the same way it is gated on 'rules' and
 * 'serving'. Missing payloads or missing stored hashes must resolve in the
 * conservative direction (treated as changed → no skip).
 */
final class SaveProductAuxHashGateTest extends TestCase {

	private const SITE_ID  = 'site-1';
	private const MENU_ID  = 'menu-1';
	private const MENU_MD5 = 'a3f5c9e1b2d4f6a8c0e2b4d6f8a0c2e4';

	private function makeInstance( array $properties ): SaveProduct {
		$instance = ( new \ReflectionClass( SaveProduct::class ) )->newInstanceWithoutConstructor();
		foreach ( $properties as $name => $value ) {
			$property = new ReflectionProperty( SaveProduct::class, $name );
			$property->setAccessible( true );
			$property->setValue( $instance, $value );
		}
		return $instance;
	}

	private function getProperty( SaveProduct $instance, string $name ) {
		$property = new ReflectionProperty( SaveProduct::class, $name );
		$property->setAccessible( true );
		return $property->getValue( $instance );
	}

	// ── shouldSkipUnchangedMenu() gate ──────────────────────────────────────────

	private function invokeShouldSkip( array $siteAuxUnchanged, bool $forceFull = false ): bool {
		$instance = $this->makeInstance( [
			'menuHashSkipEnabled' => true,
			'forceFullDeploy'     => $forceFull,
			'siteAuxUnchanged'    => $siteAuxUnchanged,
			'storedMenuHashes'    => [ self::MENU_ID => self::MENU_MD5 ],
		] );
		$method = new ReflectionMethod( SaveProduct::class, 'shouldSkipUnchangedMenu' );
		$method->setAccessible( true );
		return $method->invoke( $instance, self::MENU_ID, self::MENU_MD5 );
	}

	public function test_menu_skips_when_all_aux_verdicts_unchanged(): void {
		$this->assertTrue(
			$this->invokeShouldSkip( [ 'rules' => true, 'serving' => true, 'root' => false, 'components' => true, 'dates' => true ] ),
			'Matching menu hash + unchanged rules/serving/components/dates must preserve the skip (spec: Component data unchanged preserves skip behavior).'
		);
	}

	public function test_menu_not_skipped_when_dates_changed(): void {
		$this->assertFalse(
			$this->invokeShouldSkip( [ 'rules' => true, 'serving' => true, 'components' => true, 'dates' => false ] ),
			'A changed active-date payload must disable the menu skip even when every menu payload is byte-identical (product-active-date-window / OE-26686).'
		);
	}

	public function test_menu_not_skipped_when_dates_verdict_missing(): void {
		$this->assertFalse(
			$this->invokeShouldSkip( [ 'rules' => true, 'serving' => true, 'components' => true ] ),
			'A missing dates verdict must count as changed — the conservative direction.'
		);
	}

	public function test_menu_not_skipped_when_components_changed(): void {
		$this->assertFalse(
			$this->invokeShouldSkip( [ 'rules' => true, 'serving' => true, 'root' => false, 'components' => false ] ),
			'A changed component-definition payload must disable the menu skip even when every menu payload is byte-identical.'
		);
	}

	public function test_menu_not_skipped_when_components_verdict_missing(): void {
		$this->assertFalse(
			$this->invokeShouldSkip( [ 'rules' => true, 'serving' => true, 'root' => false ] ),
			'A missing components verdict (pre-release stored hashes, unprefetched payload) must count as changed — the conservative direction.'
		);
	}

	public function test_existing_rules_and_serving_gates_still_apply(): void {
		$this->assertFalse( $this->invokeShouldSkip( [ 'rules' => false, 'serving' => true, 'components' => true ] ) );
		$this->assertFalse( $this->invokeShouldSkip( [ 'rules' => true, 'serving' => false, 'components' => true ] ) );
	}

	public function test_force_full_never_skips(): void {
		$this->assertFalse(
			$this->invokeShouldSkip( [ 'rules' => true, 'serving' => true, 'components' => true ], true ),
			'forceFull deploys must bypass the menu skip regardless of verdicts (spec: Force-full deploys bypass change detection unchanged).'
		);
	}

	// ── evaluateSiteAuxHashes() components verdict ──────────────────────────────

	private function rootResponse(): object {
		return (object) [
			'Data' => (object) [
				'DataDefinitions' => (object) [
					'Components'          => (object) [ 'Component' => [ (object) [ 'ComponentId' => 'comp-1', 'Name' => 'Cheese' ] ] ],
					'ComponentCategories' => (object) [ 'ComponentCategory' => [ (object) [ 'ComponentCategoryId' => 'cat-1' ] ] ],
				],
			],
		];
	}

	private function evaluate( array $properties ): SaveProduct {
		$instance = $this->makeInstance( array_merge( [
			'menuHashSkipEnabled'      => true,
			'forceFullDeploy'          => false,
			'prefetchedRules'          => [ self::SITE_ID => (object) [ 'rules' => [] ] ],
			'prefetchedServingOptions' => [ self::SITE_ID => (object) [ 'serving' => [] ] ],
			'siteDataCache'            => [],
			'storedAuxHashes'          => [],
			// Pre-populated cache-hit so evaluateSiteAuxHashes()'s call to
			// getMenuItemDatesForSite() never falls through to the DB/HTTP-coupled
			// ProductManager path in this pure-reflection unit test.
			'menuItemDatesCache'       => [ self::SITE_ID => [] ],
		], $properties ) );
		$method = new ReflectionMethod( SaveProduct::class, 'evaluateSiteAuxHashes' );
		$method->setAccessible( true );
		$method->invoke( $instance, self::SITE_ID );
		return $instance;
	}

	// ── evaluateSiteAuxHashes() dates verdict (product-active-date-window / OE-26686) ──

	public function test_dates_hash_recorded_and_matches_on_second_run(): void {
		$dates = [ 'item-1' => (object) [ 'StartDate' => '2026-07-14T14:00:00+00:00', 'EndDate' => null ] ];

		$first = $this->evaluate( [ 'menuItemDatesCache' => [ self::SITE_ID => $dates ] ] );

		$pending = $this->getProperty( $first, 'pendingAuxHashes' );
		$this->assertArrayHasKey( 'dates', $pending, 'Dates hash must always be recorded for persistence.' );
		$this->assertFalse(
			$this->getProperty( $first, 'siteAuxUnchanged' )['dates'],
			'First run (no stored dates hash) must not report unchanged.'
		);

		$second = $this->evaluate( [
			'menuItemDatesCache' => [ self::SITE_ID => $dates ],
			'storedAuxHashes'    => [ 'dates' => $pending['dates'] ],
		] );
		$this->assertTrue(
			$this->getProperty( $second, 'siteAuxUnchanged' )['dates'],
			'Identical /menuitems dates + matching stored hash → unchanged verdict.'
		);
	}

	public function test_date_change_flips_dates_verdict(): void {
		$dates   = [ 'item-1' => (object) [ 'StartDate' => '2026-07-14T14:00:00+00:00', 'EndDate' => null ] ];
		$first   = $this->evaluate( [ 'menuItemDatesCache' => [ self::SITE_ID => $dates ] ] );
		$pending = $this->getProperty( $first, 'pendingAuxHashes' );

		$edited = [ 'item-1' => (object) [ 'StartDate' => '2026-07-14T14:30:00+00:00', 'EndDate' => null ] ];
		$second = $this->evaluate( [
			'menuItemDatesCache' => [ self::SITE_ID => $edited ],
			'storedAuxHashes'    => [ 'dates' => $pending['dates'] ],
		] );

		$this->assertFalse(
			$this->getProperty( $second, 'siteAuxUnchanged' )['dates'],
			'An item StartDate/EndDate change must flip the dates verdict to changed even though no per-menu payload changed (product-active-date-window / OE-26686).'
		);
	}

	public function test_absent_root_payload_yields_no_components_verdict(): void {
		$instance = $this->evaluate( [ 'siteDataCache' => [] ] );

		$pending   = $this->getProperty( $instance, 'pendingAuxHashes' );
		$unchanged = $this->getProperty( $instance, 'siteAuxUnchanged' );

		$this->assertArrayNotHasKey( 'components', $pending, 'No root payload → no components hash recorded.' );
		$this->assertFalse( $unchanged['components'], 'No verdict must resolve to changed (no skip) — the conservative direction.' );
	}

	public function test_components_hash_recorded_and_matches_on_second_run(): void {
		$first = $this->evaluate( [ 'siteDataCache' => [ self::SITE_ID => $this->rootResponse() ] ] );

		$pending = $this->getProperty( $first, 'pendingAuxHashes' );
		$this->assertArrayHasKey( 'components', $pending, 'Root payload present → components hash recorded for persistence.' );
		$this->assertFalse(
			$this->getProperty( $first, 'siteAuxUnchanged' )['components'],
			'First run (no stored components hash) must not report unchanged (spec: No stored components hash).'
		);

		$second = $this->evaluate( [
			'siteDataCache'   => [ self::SITE_ID => $this->rootResponse() ],
			'storedAuxHashes' => [ 'components' => $pending['components'] ],
		] );
		$this->assertTrue(
			$this->getProperty( $second, 'siteAuxUnchanged' )['components'],
			'Identical component subtrees + matching stored hash → unchanged verdict.'
		);
	}

	public function test_component_definition_change_flips_verdict(): void {
		$first   = $this->evaluate( [ 'siteDataCache' => [ self::SITE_ID => $this->rootResponse() ] ] );
		$pending = $this->getProperty( $first, 'pendingAuxHashes' );

		$edited = $this->rootResponse();
		$edited->Data->DataDefinitions->Components->Component[0]->PricingLevels =
			(object) [ 'PricingLevel' => [ (object) [ 'PricingLevelId' => 'pl-new' ] ] ];

		$second = $this->evaluate( [
			'siteDataCache'   => [ self::SITE_ID => $edited ],
			'storedAuxHashes' => [ 'components' => $pending['components'] ],
		] );
		$this->assertFalse(
			$this->getProperty( $second, 'siteAuxUnchanged' )['components'],
			'A component gaining a pricing-level ref must flip the components verdict to changed (spec: Component definition changes but menu payloads are byte-identical).'
		);
	}

	public function test_force_full_records_hashes_but_never_reports_unchanged(): void {
		$primed  = $this->evaluate( [ 'siteDataCache' => [ self::SITE_ID => $this->rootResponse() ] ] );
		$pending = $this->getProperty( $primed, 'pendingAuxHashes' );

		$forced = $this->evaluate( [
			'forceFullDeploy' => true,
			'siteDataCache'   => [ self::SITE_ID => $this->rootResponse() ],
			'storedAuxHashes' => [ 'components' => $pending['components'] ],
		] );

		$this->assertSame(
			$pending['components'],
			$this->getProperty( $forced, 'pendingAuxHashes' )['components'],
			'forceFull must still record fresh hashes so subsequent incremental runs can skip.'
		);
		$this->assertFalse(
			$this->getProperty( $forced, 'siteAuxUnchanged' )['components'],
			'forceFull must never report unchanged, even against a matching stored hash.'
		);
	}
}
