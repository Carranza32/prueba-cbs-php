<?php

namespace {
	// Minimal $wpdb stub so CategoryVisibility::renderableSlugSet()'s batched
	// SQL path (the fast path most menu renders actually use) is testable
	// without a live WP/DB test environment. prepare() does real placeholder
	// substitution (order-preserving) so the captured query can be asserted on;
	// get_col() just records the final query and returns a canned result.
	if ( ! class_exists( 'CBSNorthStarCategoryVisibilityRenderableSlugSetTestWpdbStub' ) ) {
		class CBSNorthStarCategoryVisibilityRenderableSlugSetTestWpdbStub {
			public $posts             = 'wp_posts';
			public $postmeta          = 'wp_postmeta';
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy     = 'wp_term_taxonomy';
			public $terms             = 'wp_terms';
			public $last_error        = '';
			public $capturedQuery     = '';

			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$i = 0;
				return preg_replace_callback(
					'/%[sd]/',
					function () use ( &$i, $args ) {
						$value = $args[ $i ] ?? '';
						$i++;
						return is_numeric( $value ) ? (string) $value : "'" . $value . "'";
					},
					$query
				);
			}

			public function get_col( $query ) {
				$this->capturedQuery = $query;
				return array( 'burgers' );
			}

			// Needed since CategoryVisibility::renderableSlugSet() now caps its
			// transient TTL via MenuItemActiveWindow::cacheTtl(), which queries for
			// the site's next active-date-window boundary. Returning null (no known
			// boundary) keeps this test's set_transient() call on its default TTL,
			// which is not what these tests assert on anyway.
			public function get_var( $query ) {
				return null;
			}
		}
	}

	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return false; // force a cache miss so the SQL query actually runs
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl ) {
			return true;
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $default ) {
			return $default;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $key, $default = false ) {
			return $default;
		}
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
}

namespace CBSNorthStar\Tests\Helpers {

	use CBSNorthStar\Helpers\CategoryVisibility;
	use CBSNorthStar\Helpers\MenuItemActiveWindow;
	use PHPUnit\Framework\TestCase;

	/**
	 * Unit tests for CategoryVisibility::renderableSlugSet()'s batched SQL path —
	 * the actual fast path most menu renders use in production (memoized/
	 * transient-cached), as opposed to the per-slug wc_get_products() fallback
	 * already covered by CategoryVisibilityTest.
	 *
	 * This path builds its own raw SQL with explicit joins rather than going
	 * through ProductScope::metaQuery(), so the product-active-date-window
	 * clause added there does NOT automatically apply here — it must be
	 * mirrored directly in this query, or a scheduled item's category would
	 * stay visible/hidden incorrectly on the path most renders actually take.
	 */
	final class CategoryVisibilityRenderableSlugSetTest extends TestCase {

		/** @var mixed Whatever $GLOBALS['wpdb'] held before this test (real wpdb, or unset) */
		private $originalWpdb;
		private bool $hadWpdb = false;

		protected function setUp(): void {
			parent::setUp();
			$this->hadWpdb      = array_key_exists( 'wpdb', $GLOBALS );
			$this->originalWpdb = $GLOBALS['wpdb'] ?? null;

			$GLOBALS['wpdb'] = new \CBSNorthStarCategoryVisibilityRenderableSlugSetTestWpdbStub();
		}

		/**
		 * Restore the real $wpdb (if this ran under a WP-loaded bootstrap) or
		 * unset it entirely (minimal bootstrap) — PHPUnit runs all test classes
		 * in one process, and CategoryVisibilityTest relies on $GLOBALS['wpdb']
		 * being ABSENT (or the real wpdb) to reach its own wc_get_products
		 * fallback path via renderableSlugSet()'s `!isset($GLOBALS['wpdb'])` guard.
		 */
		protected function tearDown(): void {
			if ( $this->hadWpdb ) {
				$GLOBALS['wpdb'] = $this->originalWpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
			parent::tearDown();
		}

		/**
		 * Invokes the private renderableSlugSet() directly — bypassing
		 * hasRenderableProducts()'s `! function_exists('wc_get_products')` guard,
		 * which is irrelevant to the batched path this test targets and would
		 * otherwise short-circuit before ever reaching $wpdb in a WooCommerce-less
		 * test run.
		 */
		private function renderableSlugSet( string $siteId, string $menuId ): ?array {
			$method = new \ReflectionMethod( CategoryVisibility::class, 'renderableSlugSet' );
			$method->setAccessible( true );
			return $method->invoke( null, $siteId, $menuId );
		}

		/**
		 * renderableSlugSet() memoizes per (siteId|menuId) in a function-static
		 * array that persists for the whole PHPUnit process. Each test uses its
		 * own site id — distinct from CategoryVisibilityTest's 'site-1'/'menu-A'
		 * AND from each other — so every test forces a fresh cache miss instead
		 * of returning a previous test's memoized verdict without touching this
		 * test's freshly-stubbed $wpdb.
		 */
		public function test_query_left_joins_active_date_meta_keyed_to_the_site(): void {
			$site = 'site-active-window-fastpath-1';
			$this->renderableSlugSet( $site, 'menu-active-window-fastpath' );

			$sql = $GLOBALS['wpdb']->capturedQuery;

			$this->assertStringContainsString( 'LEFT JOIN', $sql );
			$this->assertStringContainsString(
				"'" . MenuItemActiveWindow::startKey( $site ) . "'",
				$sql
			);
			$this->assertStringContainsString(
				"'" . MenuItemActiveWindow::stopKey( $site ) . "'",
				$sql
			);
		}

		public function test_query_treats_missing_active_date_meta_as_unconstrained(): void {
			$this->renderableSlugSet( 'site-active-window-fastpath-2', 'menu-active-window-fastpath' );

			$sql = $GLOBALS['wpdb']->capturedQuery;

			$this->assertStringContainsString( 'active_start.meta_value IS NULL', $sql );
			$this->assertStringContainsString( 'active_stop.meta_value IS NULL', $sql );
		}

		public function test_query_still_returns_the_underlying_result(): void {
			$result = $this->renderableSlugSet( 'site-active-window-fastpath-3', 'menu-active-window-fastpath' );

			$this->assertSame( array( 'burgers' => true ), $result );
		}
	}
}
