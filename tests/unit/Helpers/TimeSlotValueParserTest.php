<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\TimeSlotValueParser;
use PHPUnit\Framework\TestCase;

final class TimeSlotValueParserTest extends TestCase {

	/**
	 * Exercises the WordPress-free core of toLocalIso8601(): a wall-clock string is
	 * interpreted in the injected timezone and emitted as ISO 8601 with offset,
	 * preserving the wall-clock value (no shifting).
	 *
	 * @dataProvider formatWithOffsetProvider
	 */
	public function test_format_with_offset( string $input, string $tz, string $expected ): void {
		$method = new \ReflectionMethod( TimeSlotValueParser::class, 'formatWithOffset' );
		$method->setAccessible( true );

		$this->assertSame(
			$expected,
			$method->invoke( null, $input, new \DateTimeZone( $tz ) )
		);
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function formatWithOffsetProvider(): array {
		return array(
			// December → CST (standard time), offset -06:00.
			'CST standard time'        => array( '2025-12-29 13:45:00', 'America/Chicago', '2025-12-29T13:45:00-06:00' ),
			// July → CDT (daylight time), offset -05:00 — proves DST is honored.
			'CDT daylight time'        => array( '2025-07-01 13:45:00', 'America/Chicago', '2025-07-01T13:45:00-05:00' ),
			// Date-only input (the "future day" branch builds "Y-m-d 00:00:00").
			'date only'                => array( '2025-12-29', 'America/Chicago', '2025-12-29T00:00:00-06:00' ),
			// Already ISO 8601 with offset → DateTime honors it, passed through.
			'already iso idempotent'   => array( '2025-12-29T13:45:00-06:00', 'America/Chicago', '2025-12-29T13:45:00-06:00' ),
			// Positive half-hour offset, to confirm the +HH:mm shape.
			'positive half-hour offset' => array( '2025-12-29 13:45:00', 'Asia/Kolkata', '2025-12-29T13:45:00+05:30' ),
		);
	}

	/**
	 * An unparseable string fails soft: the original value is returned so the
	 * available-timeslots request still fires rather than throwing.
	 */
	public function test_format_with_offset_fails_soft_on_garbage(): void {
		$method = new \ReflectionMethod( TimeSlotValueParser::class, 'formatWithOffset' );
		$method->setAccessible( true );

		$this->assertSame(
			'not-a-date',
			$method->invoke( null, 'not-a-date', new \DateTimeZone( 'America/Chicago' ) )
		);
	}

	/**
	 * buildReservePayload() forwards slotTime and timeSlotId verbatim from the CBS
	 * available-slots response — the offset CBS sent is never edited or normalized,
	 * so CBS receives its own value back and the slot validates — while businessDate
	 * is shaped to the "Y-m-d\T00:00:00" form the API uses.
	 *
	 * @dataProvider buildReservePayloadProvider
	 */
	public function test_build_reserve_payload( string $businessDate, string $rawSlotTime, string $timeSlotId, string $expectedBusinessDate ): void {
		$this->assertSame(
			array(
				'businessDate' => $expectedBusinessDate,
				'slotTime'     => $rawSlotTime,
				'timeSlotId'   => $timeSlotId,
			),
			TimeSlotValueParser::buildReservePayload( $businessDate, $rawSlotTime, $timeSlotId )
		);
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public static function buildReservePayloadProvider(): array {
		return array(
			// UTC offset from CBS preserved exactly — the QA-failing case.
			'utc offset preserved' => array( '2026-06-13', '2026-06-13T17:31:00+00:00', '11111111-1111-1111-1111-111111111111', '2026-06-13T00:00:00' ),
			// Negative offset preserved unchanged.
			'negative offset'      => array( '2026-06-13', '2026-06-13T17:31:00-07:00', '22222222-2222-2222-2222-222222222222', '2026-06-13T00:00:00' ),
			// Zulu designator left untouched.
			'zulu untouched'       => array( '2026-06-13', '2026-06-13T17:31:00Z', '33333333-3333-3333-3333-333333333333', '2026-06-13T00:00:00' ),
			// Time-only slotTime forwarded as-is (no businessDate splicing).
			'time only verbatim'   => array( '2026-06-13', '17:31:00', '44444444-4444-4444-4444-444444444444', '2026-06-13T00:00:00' ),
		);
	}

	/**
	 * toBusinessDate() shapes a date to the CBS businessDate form: the calendar date
	 * at midnight with no offset. A trailing time is dropped; a malformed value is
	 * returned unchanged (fail-soft).
	 *
	 * @dataProvider toBusinessDateProvider
	 */
	public function test_to_business_date( string $input, string $expected ): void {
		$this->assertSame( $expected, TimeSlotValueParser::toBusinessDate( $input ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function toBusinessDateProvider(): array {
		return array(
			'plain date'        => array( '2026-06-15', '2026-06-15T00:00:00' ),
			'already midnight'  => array( '2026-06-15T00:00:00', '2026-06-15T00:00:00' ),
			'time dropped'      => array( '2026-06-15 23:00:00', '2026-06-15T00:00:00' ),
			'iso with offset'   => array( '2026-06-15T23:00:00+00:00', '2026-06-15T00:00:00' ),
			'malformed soft'    => array( 'not-a-date', 'not-a-date' ),
			'empty soft'        => array( '', '' ),
		);
	}

	/**
	 * formatDisplayTime() renders the literal wall-clock written in the slotTime — the
	 * HH:MM:SS in the string — and ignores the timezone offset entirely (no conversion to
	 * UTC or to any site timezone). This is the time the customer should see.
	 *
	 * @dataProvider formatDisplayTimeProvider
	 */
	public function test_format_display_time( string $slotTime, string $format, string $expected ): void {
		$this->assertSame( $expected, TimeSlotValueParser::formatDisplayTime( $slotTime, $format ) );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function formatDisplayTimeProvider(): array {
		return array(
			// The QA-failing case: -05:00 offset must NOT shift the displayed time to 9:40 PM.
			'negative offset ignored' => array( '2026-06-19T16:40:00-05:00', 'g:i A', '4:40 PM' ),
			// Legacy CBS +00:00 format still renders its literal hour (no regression).
			'plus-zero offset literal' => array( '2026-06-19T09:18:00+00:00', 'g:i A', '9:18 AM' ),
			// Zulu designator treated the same — literal wall-clock.
			'zulu literal'            => array( '2026-06-19T16:40:00Z', 'g:i A', '4:40 PM' ),
			// Bare time, no date/offset (CBS "can return a bare time like 13:45:00").
			'bare time'               => array( '13:45:00', 'g:i A', '1:45 PM' ),
			// Midnight / noon boundaries.
			'midnight'                => array( '2026-06-19T00:00:00-05:00', 'g:i A', '12:00 AM' ),
			'noon'                    => array( '2026-06-19T12:00:00-05:00', 'g:i A', '12:00 PM' ),
			// Custom 24-hour format honored.
			'custom 24h format'       => array( '2026-06-19T16:40:00-05:00', 'H:i', '16:40' ),
		);
	}

	/**
	 * An unparseable or empty slotTime fails soft: the original value is returned so the
	 * field still renders something rather than blanking or throwing.
	 *
	 * @dataProvider formatDisplayTimeFailsSoftProvider
	 */
	public function test_format_display_time_fails_soft( string $slotTime ): void {
		$this->assertSame( $slotTime, TimeSlotValueParser::formatDisplayTime( $slotTime ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function formatDisplayTimeFailsSoftProvider(): array {
		return array(
			'garbage' => array( 'not-a-date' ),
			'empty'   => array( '' ),
		);
	}
}
