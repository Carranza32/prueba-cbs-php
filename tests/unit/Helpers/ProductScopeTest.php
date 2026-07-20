<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\MenuItemActiveWindow;
use CBSNorthStar\Helpers\ProductScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProductScope::metaQuery() — the single chokepoint every
 * storefront read path funnels through (OE-26387/OE-26399), now also
 * carrying the per-site active-date-window clause (product-active-date-window
 * capability, design.md Decision 2/5). Asserting the clause is present here
 * means all six call sites inherit it without any of them needing a copy.
 */
final class ProductScopeTest extends TestCase {

	public function test_meta_query_includes_site_menu_and_active_window_clauses(): void {
		$query = ProductScope::metaQuery( 'site-1', 'menu-A' );

		$this->assertSame( 'AND', $query['relation'] );
		$this->assertContains(
			[ 'key' => '_siteid', 'value' => 'site-1', 'compare' => '=' ],
			$query
		);
		$this->assertContains(
			[ 'key' => '_menuid', 'value' => 'menu-A', 'compare' => '=' ],
			$query
		);

		$activeWindowClauses = array_values( array_filter(
			$query,
			static fn( $clause ) => is_array( $clause ) && isset( $clause['relation'] ) && 'AND' === $clause['relation']
				&& isset( $clause[0]['relation'], $clause[1]['relation'] ) && 'OR' === $clause[0]['relation']
		) );

		$this->assertCount( 1, $activeWindowClauses, 'Exactly one active-date-window AND-group should be present.' );

		// Each OR-group is [NOT EXISTS, empty-string '=', NUMERIC comparison] —
		// the middle condition matches an existing-but-empty-string meta row,
		// mirroring isWithinWindow()'s '' !== $start / '' !== $stop fail-open
		// checks (a NUMERIC compare alone would CAST('' AS SIGNED) to 0, which
		// wrongly fails the stop group since 0 is never > now).
		$this->assertSame(
			MenuItemActiveWindow::startKey( 'site-1' ),
			$activeWindowClauses[0][0][0]['key']
		);
		$this->assertSame( 'NOT EXISTS', $activeWindowClauses[0][0][0]['compare'] );
		$this->assertSame(
			MenuItemActiveWindow::startKey( 'site-1' ),
			$activeWindowClauses[0][0][1]['key']
		);
		$this->assertSame( '', $activeWindowClauses[0][0][1]['value'] );
		$this->assertSame( '=', $activeWindowClauses[0][0][1]['compare'] );
		$this->assertSame(
			MenuItemActiveWindow::startKey( 'site-1' ),
			$activeWindowClauses[0][0][2]['key']
		);
		$this->assertSame( '<=', $activeWindowClauses[0][0][2]['compare'] );
		$this->assertSame(
			MenuItemActiveWindow::stopKey( 'site-1' ),
			$activeWindowClauses[0][1][0]['key']
		);
		$this->assertSame(
			MenuItemActiveWindow::stopKey( 'site-1' ),
			$activeWindowClauses[0][1][1]['key']
		);
		$this->assertSame( '', $activeWindowClauses[0][1][1]['value'] );
		$this->assertSame( '=', $activeWindowClauses[0][1][1]['compare'] );
		$this->assertSame(
			MenuItemActiveWindow::stopKey( 'site-1' ),
			$activeWindowClauses[0][1][2]['key']
		);
		$this->assertSame( '>', $activeWindowClauses[0][1][2]['compare'] );
	}

	public function test_extra_clauses_are_preserved_before_canonical_clauses(): void {
		$extra = [ 'key' => '_stock_status', 'value' => 'instock', 'compare' => '=' ];
		$query = ProductScope::metaQuery( 'site-1', 'menu-A', [ $extra ] );

		// array_merge(['relation' => 'AND'], $extra, [...]) puts $extra right
		// after the relation key (numeric index 0) — confirm it survived intact.
		$this->assertSame( $extra, $query[0] );
	}

	public function test_active_window_clause_is_keyed_per_site(): void {
		$queryA = ProductScope::metaQuery( 'site-A', 'menu-1' );
		$queryB = ProductScope::metaQuery( 'site-B', 'menu-1' );

		$this->assertNotSame(
			MenuItemActiveWindow::startKey( 'site-A' ),
			MenuItemActiveWindow::startKey( 'site-B' )
		);

		// Both queries carry a clause keyed to THEIR OWN site, not the other's.
		$this->assertStringContainsString( 'site-A', json_encode( $queryA ) );
		$this->assertStringNotContainsString( '_active_start_site-B', json_encode( $queryA ) );
		$this->assertStringContainsString( 'site-B', json_encode( $queryB ) );
	}
}
