<?php

namespace CBSNorthStar\Tests;

use CBSNorthStar\Views\QuickOrderButtons;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for quick-add default-component pricing (change:
 * fix-quick-add-free-default-component-pricing).
 *
 * Rules must be resolved per component ruleId — a category can mix ruleIds, so
 * the old reset(info.rules) single-rule read priced free defaults with whichever
 * rule survived category assembly. The reference semantics are the detail
 * view's rulesGlobal[component.rule] lookup (rules.js), and the invariant is
 * parity: quick add charges what Customize charges for the same selection.
 *
 * QuickOrderButtons' constructor loads Component/ServingOption models (DB/API),
 * so instances are built without the constructor and seeded via reflection
 * (established pattern: SaveProduct*Test).
 */
final class QuickOrderButtonsPricingTest extends TestCase {

	private const FREE_RULE  = 'rule-free-defaults';
	private const PLAIN_RULE = 'rule-plain';

	private function makeInstance(): QuickOrderButtons {
		$instance = ( new \ReflectionClass( QuickOrderButtons::class ) )->newInstanceWithoutConstructor();
		foreach ( [
			'servingOptions'        => [ 'servingOptions' => [] ],
			'componentsSelected'    => [],
			'componentsSelectedQty' => [],
			'price'                 => 0,
		] as $name => $value ) {
			$property = new ReflectionProperty( QuickOrderButtons::class, $name );
			$property->setAccessible( true );
			$property->setValue( $instance, $value );
		}
		return $instance;
	}

	private function getProperty( QuickOrderButtons $instance, string $name ) {
		$property = new ReflectionProperty( QuickOrderButtons::class, $name );
		$property->setAccessible( true );
		return $property->getValue( $instance );
	}

	private function rule( array $overrides = [] ): array {
		return array_merge( [
			'MaxAllowed'                       => 1000,
			'MinRequired'                      => 0,
			'FreeAfter'                        => '',
			'FreeUpTo'                         => 0,
			'MaxUnique'                        => 0,
			'DefaultComponentsAreFree'         => false,
			'FirstDefaultComponentsLevelsFree' => false,
		], $overrides );
	}

	private function component( string $name, float $price, string $ruleId, int $isDefault = 1 ): array {
		return [
			'componentName'  => $name,
			'componentPrice' => $price,
			'pricingLevels'  => [],
			'isDefault'      => $isDefault,
			'ruleId'         => $ruleId,
			'siteId'         => 'site-1',
			'componentId'    => 'id-' . strtolower( $name ),
			'key'            => 'Toppings',
		];
	}

	/**
	 * @param array<string,array> $items    componentName => component
	 * @param array<string,array> $rulesMap ruleId => rule
	 */
	private function category( array $items, array $rulesMap ): array {
		return [
			'Toppings' => [
				'info'  => [ 'catName' => 'Toppings', 'componentCatId' => 'cat-1', 'rules' => $rulesMap ],
				'items' => $items,
			],
		];
	}

	// ── Spec: Mixed-ruleId category ─────────────────────────────────────────────

	public function test_mixed_rule_ids_price_each_default_by_its_own_rule(): void {
		$rulesMap = [
			self::FREE_RULE  => $this->rule( [ 'DefaultComponentsAreFree' => true ] ),
			self::PLAIN_RULE => $this->rule(),
		];

		$orderings = [
			'free rule first' => [
				'Cheese' => $this->component( 'Cheese', 1.00, self::FREE_RULE ),
				'Bacon'  => $this->component( 'Bacon', 2.00, self::PLAIN_RULE ),
			],
			'free rule last'  => [
				'Bacon'  => $this->component( 'Bacon', 2.00, self::PLAIN_RULE ),
				'Cheese' => $this->component( 'Cheese', 1.00, self::FREE_RULE ),
			],
		];

		foreach ( $orderings as $label => $items ) {
			$instance = $this->makeInstance();
			$instance->verifyComponentsRequirements( $this->category( $items, $rulesMap ) );

			$this->assertSame( 2.00, (float) $this->getProperty( $instance, 'price' ),
				"Only Bacon (plain rule) is charged regardless of iteration order ({$label})." );
		}
	}

	// ── Specs: DefaultComponentsAreFree / payload rulePrice ─────────────────────

	public function test_free_default_contributes_zero_and_payload_carries_rule_price(): void {
		$instance = $this->makeInstance();
		$unmet    = $instance->verifyComponentsRequirements( $this->category(
			[ 'Cheese' => $this->component( 'Cheese', 1.00, self::FREE_RULE ) ],
			[ self::FREE_RULE => $this->rule( [ 'DefaultComponentsAreFree' => true ] ) ]
		) );

		$this->assertSame( 0.0, (float) $this->getProperty( $instance, 'price' ),
			'A DefaultComponentsAreFree default contributes $0 to selComponentsPrice.' );
		$this->assertSame( 0, $unmet, 'The product stays quick-addable.' );

		$selected = $this->getProperty( $instance, 'componentsSelected' );
		$this->assertCount( 1, $selected );
		$this->assertArrayHasKey( 'rulePrice', $selected[0],
			'The posted payload must carry rulePrice so processProductComponents() does not fall back to the raw price.' );
		$this->assertSame( 0.0, (float) $selected[0]['rulePrice'] );
	}

	public function test_charged_default_carries_its_rule_adjusted_price(): void {
		$instance = $this->makeInstance();
		$instance->verifyComponentsRequirements( $this->category(
			[ 'Bacon' => $this->component( 'Bacon', 2.00, self::PLAIN_RULE ) ],
			[ self::PLAIN_RULE => $this->rule() ]
		) );

		$selected = $this->getProperty( $instance, 'componentsSelected' );
		$this->assertSame( 2.0, (float) $selected[0]['rulePrice'] );
		$this->assertSame( 2.0, (float) $this->getProperty( $instance, 'price' ) );
	}

	// ── Spec: FreeUpTo position window ──────────────────────────────────────────

	public function test_free_up_to_frees_only_the_first_default(): void {
		$rulesMap = [ self::PLAIN_RULE => $this->rule( [ 'FreeUpTo' => 1 ] ) ];
		$instance = $this->makeInstance();
		$instance->verifyComponentsRequirements( $this->category(
			[
				'Cheese' => $this->component( 'Cheese', 1.00, self::PLAIN_RULE ),
				'Bacon'  => $this->component( 'Bacon', 2.00, self::PLAIN_RULE ),
			],
			$rulesMap
		) );

		$selected = $this->getProperty( $instance, 'componentsSelected' );
		$this->assertSame( 0.0, (float) $selected[0]['rulePrice'], 'First default is inside the FreeUpTo window.' );
		$this->assertSame( 2.0, (float) $selected[1]['rulePrice'], 'Second default is outside it.' );
		$this->assertSame( 2.0, (float) $this->getProperty( $instance, 'price' ) );
	}

	// ── Spec: No rule configured ────────────────────────────────────────────────

	public function test_missing_rule_entry_charges_configured_price_without_error(): void {
		$instance = $this->makeInstance();
		$instance->verifyComponentsRequirements( $this->category(
			[ 'Cheese' => $this->component( 'Cheese', 1.50, 'rule-not-in-map' ) ],
			[ self::PLAIN_RULE => $this->rule() ]
		) );

		$this->assertSame( 1.5, (float) $this->getProperty( $instance, 'price' ),
			'A component whose ruleId is missing from the map is charged its configured price (fail-safe), not freed or fataled.' );
	}

	// ── Spec: MinRequired from the correct rule ─────────────────────────────────

	public function test_min_required_evaluated_per_component_rule(): void {
		$rulesMap = [
			'rule-min-one' => $this->rule( [ 'MinRequired' => 1 ] ),
			self::PLAIN_RULE => $this->rule(),
		];

		$satisfied = $this->makeInstance();
		$this->assertSame( 0, $satisfied->verifyComponentsRequirements( $this->category(
			[
				'Cheese' => $this->component( 'Cheese', 1.00, 'rule-min-one' ),
				'Olives' => $this->component( 'Olives', 0.50, self::PLAIN_RULE, 0 ),
			],
			$rulesMap
		) ), 'MinRequired=1 met by one selected default → quick-addable, whichever rule sits beside it.' );

		$unmet = $this->makeInstance();
		$this->assertSame( 1, $unmet->verifyComponentsRequirements( $this->category(
			[
				'Cheese' => $this->component( 'Cheese', 1.00, 'rule-min-two' ),
			],
			[ 'rule-min-two' => $this->rule( [ 'MinRequired' => 2 ] ) ]
		) ), 'MinRequired=2 with one default → category unmet, product is Customize-only.' );
	}
}
