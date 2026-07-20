<?php

namespace {
	// Minimal global stubs so this suite can exercise MenuItemActiveWindow without a
	// live WP/DB test environment — mirrors CategoryVisibilityTest's wc_get_products
	// stub pattern. Guarded so a WP-loaded context uses the real functions.
	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( $postId, $key, $single = false ) {
			$store = $GLOBALS['__miaw_postmeta'] ?? array();
			return $store[ $postId ][ $key ] ?? '';
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type, $gmt = 0 ) {
			return $GLOBALS['__miaw_now'] ?? time();
		}
	}

	// $wpdb stub for secondsUntilNextSiteBoundary()/cacheTtl() — the test controls
	// get_var()'s return directly rather than simulating a real MIN() aggregate
	// (that SQL shape is exercised separately against a live DB during manual QA).
	if ( ! class_exists( 'CBSNorthStarMenuItemActiveWindowTestWpdbStub' ) ) {
		class CBSNorthStarMenuItemActiveWindowTestWpdbStub {
			public $postmeta = 'wp_postmeta';
			public function prepare( $query, ...$args ) {
				return $query;
			}
			public function get_var( $query ) {
				return $GLOBALS['__miaw_next_boundary_raw'] ?? null;
			}
		}
	}
}

namespace CBSNorthStar\Tests\Helpers {

	use CBSNorthStar\Helpers\MenuItemActiveWindow;
	use PHPUnit\Framework\TestCase;

	/**
	 * Unit tests for MenuItemActiveWindow — the shared verdict used by
	 * ProductScope's read-path filter, the cart-page check, and the
	 * OrderProcess.php backstop (product-active-date-window /
	 * cart-item-active-date-enforcement capabilities).
	 */
	final class MenuItemActiveWindowTest extends TestCase {

		private int $productId = 42;
		private string $site   = 'site-1';

		/** @var mixed Whatever $GLOBALS['wpdb'] held before this test (real wpdb, or unset) */
		private $originalWpdb;
		private bool $hadWpdb = false;

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['__miaw_postmeta'] = array();
			$GLOBALS['__miaw_now']      = 1000;

			$this->hadWpdb      = array_key_exists( 'wpdb', $GLOBALS );
			$this->originalWpdb = $GLOBALS['wpdb'] ?? null;
			$GLOBALS['wpdb']    = new \CBSNorthStarMenuItemActiveWindowTestWpdbStub();
			unset( $GLOBALS['__miaw_next_boundary_raw'] );
		}

		/**
		 * Restore the real $wpdb (if this ran under a WP-loaded bootstrap) or
		 * unset it entirely (minimal bootstrap) — PHPUnit runs all test classes
		 * in one process, so leaving the stub in place would break every other
		 * test file's DB access after this one runs.
		 */
		protected function tearDown(): void {
			if ( $this->hadWpdb ) {
				$GLOBALS['wpdb'] = $this->originalWpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
			parent::tearDown();
		}

		private function setMeta( ?int $start, ?int $stop ): void {
			$meta = array();
			if ( null !== $start ) {
				$meta[ MenuItemActiveWindow::startKey( $this->site ) ] = $start;
			}
			if ( null !== $stop ) {
				$meta[ MenuItemActiveWindow::stopKey( $this->site ) ] = $stop;
			}
			$GLOBALS['__miaw_postmeta'][ $this->productId ] = $meta;
		}

		public function test_neither_date_set_is_unconstrained(): void {
			$this->setMeta( null, null );
			$this->assertTrue( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_both_dates_set_before_window(): void {
			$this->setMeta( 2000, 3000 );
			$GLOBALS['__miaw_now'] = 1000;
			$this->assertFalse( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_both_dates_set_inside_window(): void {
			$this->setMeta( 1000, 3000 );
			$GLOBALS['__miaw_now'] = 2000;
			$this->assertTrue( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_both_dates_set_after_window(): void {
			$this->setMeta( 1000, 2000 );
			$GLOBALS['__miaw_now'] = 3000;
			$this->assertFalse( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_only_start_set_before_start(): void {
			$this->setMeta( 2000, null );
			$GLOBALS['__miaw_now'] = 1000;
			$this->assertFalse( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_only_start_set_after_start_is_open_ended(): void {
			$this->setMeta( 1000, null );
			$GLOBALS['__miaw_now'] = 999999;
			$this->assertTrue( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_only_stop_set_before_stop(): void {
			$this->setMeta( null, 2000 );
			$GLOBALS['__miaw_now'] = 1000;
			$this->assertTrue( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_only_stop_set_after_stop(): void {
			$this->setMeta( null, 2000 );
			$GLOBALS['__miaw_now'] = 2000;
			$this->assertFalse( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_boundary_exact_start_is_active(): void {
			$this->setMeta( 1000, 2000 );
			$GLOBALS['__miaw_now'] = 1000;
			$this->assertTrue( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_boundary_exact_stop_is_inactive(): void {
			$this->setMeta( 1000, 2000 );
			$GLOBALS['__miaw_now'] = 2000;
			$this->assertFalse( MenuItemActiveWindow::isWithinWindow( $this->productId, $this->site ) );
		}

		public function test_empty_site_id_fails_open(): void {
			$this->setMeta( 2000, 3000 );
			$GLOBALS['__miaw_now'] = 1000;
			$this->assertTrue( MenuItemActiveWindow::isWithinWindow( $this->productId, '' ) );
		}

		public function test_different_sites_do_not_collide(): void {
			$GLOBALS['__miaw_postmeta'][ $this->productId ] = array(
				MenuItemActiveWindow::startKey( 'site-A' ) => 1000,
				MenuItemActiveWindow::stopKey( 'site-A' )  => 2000,
			);
			$GLOBALS['__miaw_now'] = 2500;

			$this->assertFalse( MenuItemActiveWindow::isWithinWindow( $this->productId, 'site-A' ) );
			$this->assertTrue( MenuItemActiveWindow::isWithinWindow( $this->productId, 'site-B' ) );
		}

		public function test_find_out_of_window_cart_lines_returns_only_violations(): void {
			$GLOBALS['__miaw_now'] = 2500;
			$GLOBALS['__miaw_postmeta'] = array(
				1 => array( MenuItemActiveWindow::stopKey( $this->site ) => 2000 ), // expired
				2 => array(), // unconstrained
				3 => array( MenuItemActiveWindow::startKey( $this->site ) => 3000 ), // not started
			);

			$cartLines = array(
				'key-a' => array( 'product_id' => 1, 'name' => 'Expired Item' ),
				'key-b' => array( 'product_id' => 2, 'name' => 'Fine Item' ),
				'key-c' => array( 'product_id' => 3, 'name' => 'Future Item' ),
			);

			$result = MenuItemActiveWindow::findOutOfWindowCartLines( $cartLines, $this->site );

			$this->assertSame(
				array( 'key-a' => 'Expired Item', 'key-c' => 'Future Item' ),
				$result
			);
		}

		public function test_find_out_of_window_cart_lines_empty_cart_returns_empty(): void {
			$this->assertSame( array(), MenuItemActiveWindow::findOutOfWindowCartLines( array(), $this->site ) );
		}

		/**
		 * Unit tests for secondsUntilNextSiteBoundary()/cacheTtl() — cap cached
		 * product/category renders (cbs_render_scoped_products(),
		 * MainController::getProductsHTML(), CategoryVisibility::renderableSlugSet())
		 * so none of them can outlive a scheduled start/stop crossing.
		 */
		public function test_seconds_until_next_boundary_null_when_none_stored(): void {
			$GLOBALS['__miaw_now'] = 1000;
			$GLOBALS['__miaw_next_boundary_raw'] = null;

			$this->assertNull( MenuItemActiveWindow::secondsUntilNextSiteBoundary( $this->site ) );
		}

		public function test_seconds_until_next_boundary_computes_difference(): void {
			$GLOBALS['__miaw_now'] = 1000;
			$GLOBALS['__miaw_next_boundary_raw'] = '1500';

			$this->assertSame( 500, MenuItemActiveWindow::secondsUntilNextSiteBoundary( $this->site ) );
		}

		public function test_seconds_until_next_boundary_empty_site_id_returns_null(): void {
			$this->assertNull( MenuItemActiveWindow::secondsUntilNextSiteBoundary( '' ) );
		}

		public function test_cache_ttl_uses_default_when_no_boundary_known(): void {
			$GLOBALS['__miaw_now'] = 1000;
			$GLOBALS['__miaw_next_boundary_raw'] = null;

			$this->assertSame( 3600, MenuItemActiveWindow::cacheTtl( $this->site, 3600 ) );
		}

		public function test_cache_ttl_caps_to_boundary_when_sooner_than_default(): void {
			$GLOBALS['__miaw_now'] = 1000;
			$GLOBALS['__miaw_next_boundary_raw'] = '1300'; // 300s away, well under the 3600 default

			$this->assertSame( 300, MenuItemActiveWindow::cacheTtl( $this->site, 3600 ) );
		}

		public function test_cache_ttl_never_exceeds_default_even_with_a_distant_boundary(): void {
			$GLOBALS['__miaw_now'] = 1000;
			$GLOBALS['__miaw_next_boundary_raw'] = '1000000'; // far beyond the default TTL

			$this->assertSame( 3600, MenuItemActiveWindow::cacheTtl( $this->site, 3600 ) );
		}

		public function test_cache_ttl_floors_at_one_not_zero(): void {
			$GLOBALS['__miaw_now'] = 1000;
			$GLOBALS['__miaw_next_boundary_raw'] = '1000'; // boundary is RIGHT NOW — 0s away

			// Not 0: WordPress treats a 0 transient expiration as "never expires",
			// which is the opposite of the intent when a boundary lands immediately.
			$this->assertSame( 1, MenuItemActiveWindow::cacheTtl( $this->site, 3600 ) );
		}
	}
}
