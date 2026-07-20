<?php

namespace {
	// Minimal $wpdb stub so epochForSiteLocal()'s tests can control the site's
	// BCL timezone id / gmt_offset lookups without a live WP/DB test environment
	// — mirrors CategoryVisibilityTest's wc_get_products stub pattern. Routes by
	// which table the query targets; SiteClock's own SQL shape is untouched.
	if ( ! class_exists( 'CBSNorthStarSiteClockTestWpdbStub' ) ) {
		class CBSNorthStarSiteClockTestWpdbStub {
			public function prepare( $query, ...$args ) {
				return $query;
			}
			public function get_var( $query ) {
				if ( false !== strpos( $query, 'cbs_site_details' ) ) {
					return $GLOBALS['__sc_timezone'] ?? null;
				}
				if ( false !== strpos( $query, 'cbs_time_zone_settings' ) ) {
					return $GLOBALS['__sc_offset'] ?? null;
				}
				return null;
			}
		}
	}
}

namespace CBSNorthStar\Tests\Helpers {

	use CBSNorthStar\Helpers\SiteClock;
	use PHPUnit\Framework\TestCase;

	final class SiteClockTest extends TestCase {

		/** @var mixed Whatever $GLOBALS['wpdb'] held before this test (real wpdb, or unset) */
		private $originalWpdb;
		private bool $hadWpdb = false;

		protected function setUp(): void {
			parent::setUp();
			$this->hadWpdb      = array_key_exists( 'wpdb', $GLOBALS );
			$this->originalWpdb = $GLOBALS['wpdb'] ?? null;

			global $wpdb;
			$wpdb = new \CBSNorthStarSiteClockTestWpdbStub();
			unset( $GLOBALS['__sc_timezone'], $GLOBALS['__sc_offset'] );
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
		 * @dataProvider parseOffsetProvider
		 */
		public function test_parse_offset_to_seconds( string $offset, ?int $expected ): void {
			$reflection = new \ReflectionMethod( SiteClock::class, 'parseOffsetToSeconds' );
			$reflection->setAccessible( true );

			$this->assertSame( $expected, $reflection->invoke( null, $offset ) );
		}

		/**
		 * @return array<string,array{0:string,1:?int}>
		 */
		public static function parseOffsetProvider(): array {
			return array(
				'zero offset'                => array( '0', 0 ),
				'positive with minutes'      => array( '+5:30', 19800 ),
				'negative with minutes'      => array( '-5:30', -19800 ),
				'positive max utc offset'    => array( '+14:00', 50400 ),
				'negative max utc offset'    => array( '-14:00', -50400 ),
				'invalid minutes are rejected' => array( '+5:75', null ),
				'invalid hour range rejected'  => array( '+25:00', null ),
				'invalid max-hour minutes'     => array( '+14:30', null ),
				'malformed text rejected'      => array( 'foo', null ),
			);
		}

		/**
		 * Local midnight in a UTC-5 site (e.g. US Eastern standard time) is
		 * 05:00 the SAME day in real UTC — offset is negative (behind UTC), so
		 * the local wall-clock instant comes LATER in absolute UTC terms.
		 */
		public function test_epoch_for_site_local_negative_offset(): void {
			$GLOBALS['__sc_timezone'] = 'us-eastern-standard-time';
			$GLOBALS['__sc_offset']   = '-5:00';

			$epoch = SiteClock::epochForSiteLocal( 'site-negative-offset', '2026-07-13 00:00:00' );

			$this->assertSame(
				gmmktime( 5, 0, 0, 7, 13, 2026 ),
				$epoch
			);
		}

		/**
		 * Local midnight in a UTC+5:30 site (e.g. India) is 18:30 the PREVIOUS
		 * day in real UTC — offset is positive (ahead of UTC), so the local
		 * wall-clock instant comes EARLIER in absolute UTC terms.
		 */
		public function test_epoch_for_site_local_positive_offset(): void {
			$GLOBALS['__sc_timezone'] = 'india-standard-time';
			$GLOBALS['__sc_offset']   = '+5:30';

			$epoch = SiteClock::epochForSiteLocal( 'site-positive-offset', '2026-07-13 00:00:00' );

			$this->assertSame(
				gmmktime( 18, 30, 0, 7, 12, 2026 ),
				$epoch
			);
		}

		public function test_epoch_for_site_local_unknown_timezone_fails_open_to_null(): void {
			$GLOBALS['__sc_timezone'] = null;

			$this->assertNull(
				SiteClock::epochForSiteLocal( 'site-unknown', '2026-07-13 00:00:00' )
			);
		}

		public function test_epoch_for_site_local_malformed_wall_clock_fails_open_to_null(): void {
			$GLOBALS['__sc_timezone'] = 'us-eastern-standard-time';
			$GLOBALS['__sc_offset']   = '-5:00';

			$this->assertNull(
				SiteClock::epochForSiteLocal( 'site-malformed', 'not-a-date' )
			);
		}
	}
}
