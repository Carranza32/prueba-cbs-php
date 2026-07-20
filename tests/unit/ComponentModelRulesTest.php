<?php

namespace CBSNorthStar\Tests;

use CBSNorthStar\Models\Component;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for category rules assembly in the Component model (change:
 * fix-quick-add-free-default-component-pricing).
 *
 * getComponentInfo() must ACCUMULATE info.rules across a category's components
 * (keyed by ruleId) — the old per-component reassignment left only the last
 * component's rule, which mispriced quick-add and blanked the detail view's
 * rulesGlobal for clobbered ruleIds. setRules()'s 'Default' branch must not
 * inherit free-flags from unrelated site rules, and $component->key must keep
 * its established final-value semantics (last key of the site rules list —
 * consumed by processProductComponents() as the cart category name).
 */
final class ComponentModelRulesTest extends TestCase {

	private function makeModel( array $rulesData, array $productComponents = [] ): Component {
		return new class( $rulesData, $productComponents ) extends Component {
			private array $stubRulesData;
			private array $stubComponents;

			public function __construct( array $rulesData, array $components ) {
				$this->stubRulesData  = $rulesData;
				$this->stubComponents = $components;
			}

			public function getComponentsRule( $siteId, $apicallCount, $prefetchedResponse = null ) {
				return [ 'Data' => $this->stubRulesData ];
			}

			public function getProductComponents( $productId ) {
				return $this->stubComponents;
			}
		};
	}

	private function ruleObject( string $ruleId, array $overrides = [] ): object {
		return (object) array_merge( [
			'RuleId'                           => $ruleId,
			'MaxAllowed'                       => 5,
			'MinRequired'                      => 0,
			'FreeAfter'                        => '',
			'FreeUpTo'                         => 0,
			'MaxUnique'                        => 0,
			'DefaultComponentsAreFree'         => false,
			'FirstDefaultComponentsLevelsFree' => false,
		], $overrides );
	}

	private function componentObject( string $name, string $ruleId ): object {
		return (object) [
			'componentName'           => $name,
			'componentprice'          => 1.25,
			'pricingLevels'           => [],
			'isDefault'               => 1,
			'ruleId'                  => $ruleId,
			'siteId'                  => 'site-1',
			'numberOfPlacements'      => 1,
			'componentId'             => 'id-' . strtolower( $name ),
			'image'                   => 'img.png',
			'categoryName'            => 'Toppings',
			'componentServingOptions' => [],
		];
	}

	private function invokeSetRules( Component $model, object $component ): array {
		$method = new ReflectionMethod( Component::class, 'setRules' );
		$method->setAccessible( true );
		return $method->invokeArgs( $model, [ &$component, 1 ] );
	}

	// ── Spec: Category rules map completeness ───────────────────────────────────

	public function test_get_component_info_accumulates_rules_across_mixed_rule_ids(): void {
		$rulesData = [
			'Sides'    => $this->ruleObject( 'R1', [ 'DefaultComponentsAreFree' => true ] ),
			'Toppings' => $this->ruleObject( 'R2' ),
		];
		$model = $this->makeModel( $rulesData, [
			'cat-1' => [
				$this->componentObject( 'Cheese', 'R1' ),
				$this->componentObject( 'Bacon', 'R2' ),
			],
		] );

		$method = new ReflectionMethod( Component::class, 'getComponentInfo' );
		$method->setAccessible( true );
		$info = $method->invoke( $model, 0 );

		$rules = $info['Toppings']['info']['rules'];
		$this->assertArrayHasKey( 'R1', $rules, 'First component\'s rule must survive later components (old code overwrote it).' );
		$this->assertArrayHasKey( 'R2', $rules );
		$this->assertTrue( (bool) $rules['R1']['DefaultComponentsAreFree'] );
		$this->assertFalse( (bool) $rules['R2']['DefaultComponentsAreFree'] );
		$this->assertSame( 'Toppings', $info['Toppings']['info']['catName'],
			'catName/componentCatId keys survive the restructured per-key assignment.' );
	}

	// ── setRules: matched rule passthrough ──────────────────────────────────────

	public function test_set_rules_returns_matched_rule_data(): void {
		$component = $this->componentObject( 'Cheese', 'R1' );
		$result    = $this->invokeSetRules(
			$this->makeModel( [ 'Sides' => $this->ruleObject( 'R1', [ 'FreeUpTo' => 2, 'MinRequired' => 1 ] ) ] ),
			$component
		);

		$this->assertSame( 2, $result['R1']['FreeUpTo'] );
		$this->assertSame( 1, $result['R1']['MinRequired'] );
	}

	// ── Spec: No rule configured ('Default' branch) ─────────────────────────────

	public function test_default_rule_id_gets_static_safe_flags_not_another_rules(): void {
		$component = $this->componentObject( 'Plain', 'Default' );
		$result    = $this->invokeSetRules(
			$this->makeModel( [
				'Sides'    => $this->ruleObject( 'R1', [ 'DefaultComponentsAreFree' => true, 'FirstDefaultComponentsLevelsFree' => true ] ),
				'Toppings' => $this->ruleObject( 'R2', [ 'DefaultComponentsAreFree' => true ] ),
			] ),
			$component
		);

		$this->assertFalse( (bool) $result['Default']['DefaultComponentsAreFree'],
			'A no-rule component must not inherit free entitlements from whichever site rule iterates last.' );
		$this->assertFalse( (bool) $result['Default']['FirstDefaultComponentsLevelsFree'] );
		$this->assertSame( 1000, $result['Default']['MaxAllowed'] );
		$this->assertSame( 0, $result['Default']['MinRequired'] );
	}

	// ── $component->key final-value semantics (pinned, design D3) ───────────────

	public function test_component_key_keeps_last_rules_list_key_semantics(): void {
		$rulesData = [
			'Sides'    => $this->ruleObject( 'R1' ),
			'Toppings' => $this->ruleObject( 'R2' ),
			'Sauces'   => $this->ruleObject( 'R3' ),
		];

		// Match in the middle of the list: key must still end as the LAST list
		// key (existing behavior consumed by processProductComponents() as the
		// cart category name) — the loop deliberately has no break.
		$component = $this->componentObject( 'Cheese', 'R2' );
		$this->invokeSetRules( $this->makeModel( $rulesData ), $component );

		$this->assertSame( 'Sauces', $component->key );
	}
}
