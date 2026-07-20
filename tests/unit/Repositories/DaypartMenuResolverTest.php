<?php

namespace CBSNorthStar\Tests\Repositories;

use CBSNorthStar\Repositories\DaypartMenusRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure window-selection logic of DaypartMenusRepository::pickActiveMenu().
 *
 * pickActiveMenu is intentionally free of DB / WordPress dependencies (the singleton's
 * $wpdb capture is never touched on a static call), so precedence, boundary and overnight
 * behaviour can be exercised in isolation by passing rows + an explicit day/time. The
 * timezone-resolution path (getActiveDaypartMenu → SiteClock) is covered by manual QA — it
 * needs WP + the cbs_site_details / cbs_time_zone_settings tables.
 */
final class DaypartMenuResolverTest extends TestCase {

	/**
	 * Build a daypart row matching the get_results() stdClass shape.
	 */
	private static function row( string $menuid, string $start, string $end, string $days, int $order ): \stdClass {
		return (object) array(
			'menuid'       => $menuid,
			'starttime'    => $start,
			'endtime'      => $end,
			'days'         => $days,
			'displayorder' => $order,
		);
	}

	/**
	 * Operator-correct daypart config: Mon–Fri breakfast and lunch windows stacked ABOVE
	 * a near-24h catch-all (smaller displayorder) so each specific menu wins during its
	 * own hours. displayorder is the explicit priority knob — see resolveProvider().
	 *
	 * @return array<int,\stdClass>
	 */
	private static function labConfig(): array {
		$all  = 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday, Saturday';
		$week = 'Monday, Tuesday, Wednesday, Thursday, Friday';

		// Rows are returned ORDER BY displayorder ASC, as getSiteDaypartMenus() yields them.
		return array(
			self::row( 'LUNCH',     '12:00:00', '14:00:00', $week, 1 ),
			self::row( 'BREAKFAST', '00:00:00', '12:00:00', $week, 2 ),
			self::row( 'CATCHALL',  '00:00:00', '23:45:00', $all,  3 ),
		);
	}

	/**
	 * @param array<int,\stdClass> $rows
	 *
	 * @dataProvider resolveProvider
	 */
	public function test_pick_active_menu( array $rows, string $day, string $time, ?string $expected ): void {
		$this->assertSame( $expected, DaypartMenusRepository::pickActiveMenu( $rows, $time, $day ) );
	}

	/**
	 * @return array<string,array{0:array<int,\stdClass>,1:string,2:string,3:?string}>
	 */
	public static function resolveProvider(): array {
		$lab       = self::labConfig();
		$allDays   = 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday, Saturday';
		$weekdays  = 'Monday, Tuesday, Wednesday, Thursday, Friday';

		return array(
			// Operator-correct lab config (specific windows stacked above the catch-all):
			// the lower-order specific menu wins during its own hours.
			'lunch wins by lower order at 13:00'     => array( $lab, 'Tuesday', '13:00:00', 'LUNCH' ),
			'breakfast wins by lower order at 08:00' => array( $lab, 'Tuesday', '08:00:00', 'BREAKFAST' ),

			// End-exclusive boundary: breakfast ends 12:00, lunch starts 12:00 → lunch owns noon.
			'noon boundary resolves to lunch'       => array( $lab, 'Tuesday', '12:00:00', 'LUNCH' ),

			// Weekend: Mon–Fri windows miss on the day → only the daily catch-all matches.
			'saturday morning is catch-all'         => array( $lab, 'Saturday', '08:00:00', 'CATCHALL' ),
			'sunday lunchtime is catch-all'         => array( $lab, 'Sunday', '13:00:00', 'CATCHALL' ),

			// Pre-existing 23:45–24:00 data gap on this multi-menu site → no match.
			'late-night gap returns null'           => array( $lab, 'Tuesday', '23:50:00', null ),

			// displayorder — not window width — decides. A WIDER window with a lower order
			// beats a narrower one: the operator's explicit priority knob (precedence revert).
			'lower order beats narrower window'     => array(
				array(
					self::row( 'WIDE',   '00:00:00', '23:45:00', $allDays, 1 ),
					self::row( 'NARROW', '12:00:00', '14:00:00', $allDays, 2 ),
				),
				'Tuesday', '13:00:00', 'WIDE',
			),
			// ...and the narrow window wins only when the operator gives it the lower order.
			'narrow window wins when ordered first' => array(
				array(
					self::row( 'NARROW', '12:00:00', '14:00:00', $allDays, 1 ),
					self::row( 'WIDE',   '00:00:00', '23:45:00', $allDays, 2 ),
				),
				'Tuesday', '13:00:00', 'NARROW',
			),

			// Equal displayorder → first matching row wins (rows arrive ORDER BY displayorder ASC).
			'equal order keeps first match'         => array(
				array(
					self::row( 'FIRST',  '09:00:00', '11:00:00', $allDays, 5 ),
					self::row( 'SECOND', '09:00:00', '11:00:00', $allDays, 5 ),
				),
				'Monday', '10:00:00', 'FIRST',
			),
			'equal order ignores span and keeps first match' => array(
				array(
					self::row( 'WIDE_FIRST',   '00:00:00', '23:45:00', $allDays, 5 ),
					self::row( 'NARROW_SECOND','12:00:00', '14:00:00', $allDays, 5 ),
				),
				'Monday', '13:00:00', 'WIDE_FIRST',
			),

			// All-day (start == end) window matches every time; it wins or loses purely on order.
			'all-day yields to lower-order lunch'   => array(
				array(
					self::row( 'ALLDAY', '00:00:00', '00:00:00', $allDays, 2 ),
					self::row( 'LUNCH',  '12:00:00', '14:00:00', $allDays, 1 ),
				),
				'Monday', '13:00:00', 'LUNCH',
			),
			'all-day wins when alone matching'      => array(
				array(
					self::row( 'ALLDAY', '00:00:00', '00:00:00', $allDays, 2 ),
					self::row( 'LUNCH',  '12:00:00', '14:00:00', $allDays, 1 ),
				),
				'Monday', '20:00:00', 'ALLDAY',
			),

			// Overnight window (22:00 → 02:00) wraps midnight; matching is independent of
			// precedence, so it is given the lower order here to isolate the wrap logic.
			'overnight evening matches'             => array(
				array(
					self::row( 'NIGHT',    '22:00:00', '02:00:00', $allDays, 1 ),
					self::row( 'CATCHALL', '00:00:00', '23:45:00', $allDays, 2 ),
				),
				'Friday', '23:00:00', 'NIGHT',
			),
			'overnight after midnight matches'      => array(
				array(
					self::row( 'NIGHT',    '22:00:00', '02:00:00', $allDays, 1 ),
					self::row( 'CATCHALL', '00:00:00', '23:45:00', $allDays, 2 ),
				),
				'Saturday', '01:00:00', 'NIGHT',
			),
			'outside overnight falls to catch-all'  => array(
				array(
					self::row( 'NIGHT',    '22:00:00', '02:00:00', $allDays, 1 ),
					self::row( 'CATCHALL', '00:00:00', '23:45:00', $allDays, 2 ),
				),
				'Saturday', '03:00:00', 'CATCHALL',
			),

			// Day matching is by the CURRENT day only (OE-26418 decision: no prev-day
			// carry-over). A Friday-only overnight window owns Friday evening...
			'overnight single-day owns its evening' => array(
				array(
					self::row( 'NIGHT',    '22:00:00', '02:00:00', 'Friday',  1 ),
					self::row( 'CATCHALL', '00:00:00', '23:45:00', $allDays, 2 ),
				),
				'Friday', '23:00:00', 'NIGHT',
			),
			// ...but does NOT carry into Saturday's post-midnight slice — that hour
			// resolves to whatever matches on Saturday (here, the catch-all).
			'overnight single-day no saturday carry' => array(
				array(
					self::row( 'NIGHT',    '22:00:00', '02:00:00', 'Friday',  1 ),
					self::row( 'CATCHALL', '00:00:00', '23:45:00', $allDays, 2 ),
				),
				'Saturday', '01:00:00', 'CATCHALL',
			),

			// Single-row site is NOT special-cased off-hours: it respects its window and
			// returns null when "now" is outside it (upstream "fix timeslot hours",
			// commit 972886c2 removed the previous single-menu fallback).
			'single row off-hours returns null'     => array(
				array( self::row( 'ONLY', '00:00:00', '12:00:00', $weekdays, 1 ) ),
				'Monday', '20:00:00', null,
			),
			'single row in-window matches'          => array(
				array( self::row( 'ONLY', '00:00:00', '12:00:00', $weekdays, 1 ) ),
				'Monday', '09:00:00', 'ONLY',
			),

			// Multi-row site with no matching window → null (fail closed downstream).
			'multi row no match returns null'       => array(
				array(
					self::row( 'A', '06:00:00', '10:00:00', $weekdays, 1 ),
					self::row( 'B', '11:00:00', '14:00:00', $weekdays, 2 ),
				),
				'Saturday', '08:00:00', null,
			),

			// Hardened day parsing: no space after comma + mixed case still matches.
			'days without spaces match'             => array(
				array( self::row( 'X', '08:00:00', '10:00:00', 'monday,TUESDAY', 1 ) ),
				'Tuesday', '09:00:00', 'X',
			),
			'days with extra spaces still match'    => array(
				array( self::row( 'X', '08:00:00', '10:00:00', '  Monday  ,   Tuesday   ', 1 ) ),
				'Tuesday', '09:00:00', 'X',
			),
			'day miss returns null'                 => array(
				array( self::row( 'X', '08:00:00', '10:00:00', 'Monday, Tuesday', 1 ) ),
				'Wednesday', '09:00:00', null,
			),

			// No rows at all → null.
			'empty schedule returns null'           => array( array(), 'Monday', '09:00:00', null ),

			// Malformed clock strings fail closed.
			'invalid check time returns null'       => array(
				array( self::row( 'ONLY', '00:00:00', '23:45:00', $allDays, 1 ) ),
				'Monday', 'foo', null,
			),
			'invalid row start time is skipped'     => array(
				array(
					self::row( 'BAD',  'foo',      '23:45:00', $allDays, 1 ),
					self::row( 'GOOD', '12:00:00', '14:00:00', $allDays, 2 ),
				),
				'Monday', '13:00:00', 'GOOD',
			),
			'invalid row end time is skipped'       => array(
				array(
					self::row( 'BAD',  '00:00:00', '25:00:00', $allDays, 1 ),
					self::row( 'GOOD', '12:00:00', '14:00:00', $allDays, 2 ),
				),
				'Monday', '13:00:00', 'GOOD',
			),
			'invalid row minute is skipped'         => array(
				array(
					self::row( 'BAD',  '12:60:00', '14:00:00', $allDays, 1 ),
					self::row( 'GOOD', '12:00:00', '14:00:00', $allDays, 2 ),
				),
				'Monday', '13:00:00', 'GOOD',
			),
		);
	}
}
