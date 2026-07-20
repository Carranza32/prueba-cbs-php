<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\KitchenHours;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure window logic of KitchenHours::isOpenAt().
 *
 * isOpenAt is intentionally free of DB / WordPress dependencies, so the open/closed
 * decision can be exercised in isolation by injecting the moment to evaluate. The
 * timezone-resolution path (isOpenNow) is covered by manual QA — it needs WP + the
 * cbs_time_zone_settings table.
 */
final class KitchenHoursTest extends TestCase {

	/**
	 * @param array<string,string> $site
	 *
	 * @dataProvider windowProvider
	 */
	public function test_is_open_at( array $site, string $when, bool $expected ): void {
		$this->assertSame(
			$expected,
			KitchenHours::isOpenAt( $site, new \DateTime( $when ) )
		);
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string,2:bool}>
	 */
	public static function windowProvider(): array {
		$day      = [ 'kitchenopentime' => '4:00 AM', 'kitchenclosetime' => '11:59 PM' ];
		$overnight = [ 'kitchenopentime' => '4:00 PM', 'kitchenclosetime' => '2:00 AM' ];
		$iso      = [ 'kitchenopentime' => '2024-01-01T04:00:00', 'kitchenclosetime' => '2024-01-01T23:59:00' ];

		return [
			// Same-day window 04:00–23:59.
			'daytime mid-window is open'        => [ $day, '2024-06-06 10:00:00', true ],
			'before open is closed (1 AM bug)'  => [ $day, '2024-06-06 01:00:00', false ],
			'exactly at open is open'           => [ $day, '2024-06-06 04:00:00', true ],
			'exactly at close is closed'        => [ $day, '2024-06-06 23:59:00', false ],
			'one minute before close is open'   => [ $day, '2024-06-06 23:58:00', true ],

			// Overnight window 16:00–02:00 (spans midnight).
			'overnight evening is open'         => [ $overnight, '2024-06-06 23:00:00', true ],
			'overnight after midnight is open'  => [ $overnight, '2024-06-06 01:00:00', true ],
			'overnight pre-open is closed'      => [ $overnight, '2024-06-06 03:00:00', false ],
			'overnight afternoon is closed'     => [ $overnight, '2024-06-06 15:00:00', false ],
			'overnight exactly at close closed' => [ $overnight, '2024-06-06 02:00:00', false ],

			// ISO datetime inputs parse the same as "h:i A".
			'iso datetime mid-window is open'   => [ $iso, '2024-06-06 12:00:00', true ],
			'iso datetime before open closed'   => [ $iso, '2024-06-06 02:00:00', false ],
		];
	}

	public function test_open_equals_close_is_treated_as_24h(): void {
		$site = [ 'kitchenopentime' => '12:00 AM', 'kitchenclosetime' => '12:00 AM' ];

		$this->assertTrue( KitchenHours::isOpenAt( $site, new \DateTime( '2024-06-06 03:30:00' ) ) );
		$this->assertTrue( KitchenHours::isOpenAt( $site, new \DateTime( '2024-06-06 17:45:00' ) ) );
	}

	/**
	 * @param array<string,string> $site
	 *
	 * @dataProvider failOpenProvider
	 */
	public function test_unparseable_hours_fail_open( array $site ): void {
		$this->assertTrue(
			KitchenHours::isOpenAt( $site, new \DateTime( '2024-06-06 03:00:00' ) ),
			'Missing or unparseable hours must never block ordering.'
		);
	}

	/**
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function failOpenProvider(): array {
		return [
			'both blank'        => [ [ 'kitchenopentime' => '', 'kitchenclosetime' => '' ] ],
			'open placeholder'  => [ [ 'kitchenopentime' => '--', 'kitchenclosetime' => '11:59 PM' ] ],
			'close missing'     => [ [ 'kitchenopentime' => '4:00 AM' ] ],
			'garbage value'     => [ [ 'kitchenopentime' => 'not a time', 'kitchenclosetime' => 'nope' ] ],
			'keys absent'       => [ [] ],
		];
	}
}
