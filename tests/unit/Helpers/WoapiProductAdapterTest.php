<?php

namespace {
	// $wpdb stub so parseActiveDate()'s "bare local wall-clock" branch (via
	// SiteClock::epochForSiteLocal()) is testable without a live WP/DB test
	// environment — mirrors CategoryVisibilityTest's wc_get_products stub.
	if ( ! class_exists( 'CBSNorthStarWoapiProductAdapterTestWpdbStub' ) ) {
		class CBSNorthStarWoapiProductAdapterTestWpdbStub {
			public function prepare( $query, ...$args ) {
				return $query;
			}
			public function get_var( $query ) {
				if ( false !== strpos( $query, 'cbs_site_details' ) ) {
					return $GLOBALS['__wpa_timezone'] ?? null;
				}
				if ( false !== strpos( $query, 'cbs_time_zone_settings' ) ) {
					return $GLOBALS['__wpa_offset'] ?? null;
				}
				return null;
			}
		}
	}
}

namespace CBSNorthStar\Tests\Helpers {

	use CBSNorthStar\Helpers\WoapiProductAdapter;
	use PHPUnit\Framework\TestCase;

	/**
	 * Unit tests for WoapiProductAdapter::isMenuItemActive().
	 *
	 * The helper is pure (no DB / WordPress dependencies): any falsy IsActive
	 * value (false, 0) marks an item inactive — matching getItemDetails()'s truthy
	 * check — while a null item or a missing/null flag must count as active so a
	 * payload-shape change can never mass-hide items.
	 */
	final class WoapiProductAdapterTest extends TestCase {

		/** @var mixed Whatever $GLOBALS['wpdb'] held before this test (real wpdb, or unset) */
		private $originalWpdb;
		private bool $hadWpdb = false;

		protected function setUp(): void {
			parent::setUp();
			$this->hadWpdb      = array_key_exists( 'wpdb', $GLOBALS );
			$this->originalWpdb = $GLOBALS['wpdb'] ?? null;

			global $wpdb;
			$wpdb = new \CBSNorthStarWoapiProductAdapterTestWpdbStub();
			unset( $GLOBALS['__wpa_timezone'], $GLOBALS['__wpa_offset'] );
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

		/**
		 * @dataProvider menuItemProvider
		 */
		public function test_is_menu_item_active( ?object $item, bool $expected ): void {
			$this->assertSame( $expected, WoapiProductAdapter::isMenuItemActive( $item ) );
		}

		/**
		 * @return array<string,array{0:?object,1:bool}>
		 */
		public static function menuItemProvider(): array {
			return [
				'explicitly active'        => [ (object) [ 'IsActive' => true ], true ],
				'explicitly disabled'      => [ (object) [ 'IsActive' => false ], false ],
				'missing IsActive flag'    => [ (object) [ 'MenuItemId' => 'abc-123' ], true ],
				'null IsActive flag'       => [ (object) [ 'IsActive' => null ], true ],
				'null item (not in map)'   => [ null, true ],
				'truthy non-bool IsActive' => [ (object) [ 'IsActive' => 1 ], true ],
				'falsy int IsActive'       => [ (object) [ 'IsActive' => 0 ], false ],
				'falsy string IsActive'    => [ (object) [ 'IsActive' => '0' ], false ],
			];
		}

		/**
		 * Unit tests for WoapiProductAdapter::parseActiveDate() — the deploy-write
		 * parser for `/menuitems`' StartDate/EndDate (product-active-date-window /
		 * save-product-deploy capabilities).
		 *
		 * Confirmed live (2026-07-13): this is the same CBS/WOAPI backend as the
		 * available-slots API, which this plugin already documents elsewhere
		 * (MainController::getMenuIdForDateTime(), SiteClock::slotHasPassed(),
		 * TimeSlotService) as tagging the SITE's own local wall-clock with a
		 * `+00:00`/`Z`-looking suffix that is NOT genuine UTC. So any offset/zone
		 * suffix on StartDate/EndDate is ALWAYS ignored — never trusted, even when
		 * present — and the literal wall-clock digits are converted using the
		 * site's own configured timezone instead.
		 */
		public function test_null_value_fails_open(): void {
			$this->assertNull( WoapiProductAdapter::parseActiveDate( null, 'site-1' ) );
		}

		public function test_empty_string_fails_open(): void {
			$this->assertNull( WoapiProductAdapter::parseActiveDate( '', 'site-1' ) );
		}

		public function test_non_string_non_int_fails_open(): void {
			$this->assertNull( WoapiProductAdapter::parseActiveDate( array( 'not' => 'a date' ), 'site-1' ) );
		}

		public function test_garbage_string_fails_open(): void {
			$this->assertNull( WoapiProductAdapter::parseActiveDate( 'definitely not a date', 'site-1' ) );
		}

		public function test_bare_unix_timestamp_is_used_directly(): void {
			// Unambiguous either way — there is no offset to (mis)interpret.
			$this->assertSame(
				1752364800,
				WoapiProductAdapter::parseActiveDate( '1752364800', 'site-1' )
			);
		}

		public function test_z_suffix_is_ignored_and_site_local_timezone_applies(): void {
			$GLOBALS['__wpa_timezone'] = 'us-eastern-standard-time';
			$GLOBALS['__wpa_offset']   = '-5:00';

			// "...T00:00:00Z" must NOT be read as literal UTC midnight — the Z is
			// CBS's known mislabeling of a local wall-clock. The literal 00:00:00
			// digits are this site's own local midnight, converted via its
			// configured timezone (UTC-5 here, so 05:00 UTC).
			$this->assertSame(
				gmmktime( 5, 0, 0, 7, 13, 2026 ),
				WoapiProductAdapter::parseActiveDate( '2026-07-13T00:00:00Z', 'site-1' )
			);
		}

		public function test_explicit_numeric_offset_is_ignored_and_site_local_timezone_applies(): void {
			$GLOBALS['__wpa_timezone'] = 'us-eastern-standard-time';
			$GLOBALS['__wpa_offset']   = '-5:00';

			// The "-05:00" WOAPI attaches here is also ignored, same as Z — only
			// the site's OWN configured timezone (also -5:00 here) decides the
			// result. See test_offset_suffix_value_itself_has_no_effect_on_result()
			// for proof the stated offset's VALUE plays no role at all.
			$this->assertSame(
				gmmktime( 5, 0, 0, 7, 13, 2026 ),
				WoapiProductAdapter::parseActiveDate( '2026-07-13T00:00:00-05:00', 'site-1' )
			);
		}

		public function test_offset_suffix_value_itself_has_no_effect_on_result(): void {
			$GLOBALS['__wpa_timezone'] = 'us-eastern-standard-time';
			$GLOBALS['__wpa_offset']   = '-5:00';

			// Same literal wall-clock, wildly different stated offsets (including
			// one that would flip the calendar day if trusted) — the result must
			// be IDENTICAL every time, because CBS's stated offset is never
			// consulted, only the site's own configured timezone.
			$viaZ        = WoapiProductAdapter::parseActiveDate( '2026-07-13T00:00:00Z', 'site-1' );
			$viaPlusNine = WoapiProductAdapter::parseActiveDate( '2026-07-13T00:00:00+09:00', 'site-1' );
			$viaMinusThree = WoapiProductAdapter::parseActiveDate( '2026-07-13T00:00:00-03:00', 'site-1' );
			$viaNoOffset = WoapiProductAdapter::parseActiveDate( '2026-07-13T00:00:00', 'site-1' );

			$this->assertSame( $viaZ, $viaPlusNine );
			$this->assertSame( $viaZ, $viaMinusThree );
			$this->assertSame( $viaZ, $viaNoOffset );
		}

		public function test_bare_local_date_uses_site_timezone(): void {
			$GLOBALS['__wpa_timezone'] = 'us-eastern-standard-time';
			$GLOBALS['__wpa_offset']   = '-5:00';

			$this->assertSame(
				gmmktime( 5, 0, 0, 7, 13, 2026 ),
				WoapiProductAdapter::parseActiveDate( '2026-07-13', 'site-1' )
			);
		}

		public function test_bare_local_date_with_unknown_site_timezone_fails_open(): void {
			$GLOBALS['__wpa_timezone'] = null;

			$this->assertNull(
				WoapiProductAdapter::parseActiveDate( '2026-07-13', 'site-unknown' )
			);
		}
	}
}
