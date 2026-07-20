<?php

namespace CBSNorthStar\Helpers;

/**
 * Parses and builds time-slot values used across checkout and the reserve API.
 *
 * The select field stores a combined value: "{timeSlotId}|{slotTime}".
 * All parsing of that format is centralised here so it is never manually
 * exploded in multiple places.
 */
class TimeSlotValueParser {

	/** Delimiter between timeSlotId and slotTime in the select field value. */
	const DELIMITER = '|';

	/**
	 * Parse a raw "{timeSlotId}|{slotTime}" select value.
	 *
	 * @param  string $raw Raw value from the select field.
	 * @return array{time_slot_id: string, slot_time: string}|null
	 *         Null when the value is empty or missing the delimiter.
	 */
	public static function parse( string $raw ): ?array {
		if ( '' === $raw ) {
			return null;
		}

		$pos = strpos( $raw, self::DELIMITER );
		if ( false === $pos ) {
			return null;
		}

		$timeSlotId = substr( $raw, 0, $pos );
		$slotTime   = substr( $raw, $pos + 1 );

		if ( '' === $timeSlotId || '' === $slotTime ) {
			return null;
		}

		return [
			'time_slot_id' => $timeSlotId,
			'slot_time'    => $slotTime,
		];
	}

	/**
	 * Build the JSON body required by the CBS reserve API.
	 *
	 * slotTime and timeSlotId are forwarded verbatim from the CBS available-slots
	 * response — no offset edit, no timezone normalization. Sending CBS its own
	 * values back is what keeps the reserved instant matching the slot the customer
	 * selected; rewriting the offset shifts the instant and CBS rejects it as
	 * "not available".
	 *
	 * businessDate is shaped to the form the available-slots endpoint returns —
	 * the calendar date at midnight, no offset ("2026-06-15T00:00:00") — via
	 * {@see self::toBusinessDate()}, since the reserve endpoint expects that form
	 * rather than a bare "Y-m-d".
	 *
	 * @param  string $businessDate Date of the slot, "Y-m-d" (e.g. "2025-12-29").
	 * @param  string $rawSlotTime  slotTime string exactly as returned by /available.
	 * @param  string $timeSlotId   UUID from the CBS API.
	 * @return array{businessDate: string, slotTime: string, timeSlotId: string}
	 */
	public static function buildReservePayload(
		string $businessDate,
		string $rawSlotTime,
		string $timeSlotId
	): array {
		return [
			'businessDate' => self::toBusinessDate( $businessDate ),
			'slotTime'     => $rawSlotTime,
			'timeSlotId'   => $timeSlotId,
		];
	}

	/**
	 * Shape a date to the businessDate form the CBS API uses: the calendar date at
	 * midnight with no timezone offset, e.g. "2026-06-15T00:00:00". This mirrors the
	 * businessDate the available-slots endpoint returns, which the reserve and delete
	 * endpoints expect.
	 *
	 * Accepts a "Y-m-d" value (or any string starting with one — a time portion is
	 * discarded, as businessDate is a calendar day) and returns "Y-m-d\T00:00:00".
	 * Returns the input unchanged when it has no leading date, so a malformed value
	 * still reaches the API rather than throwing.
	 *
	 * @param  string $businessDate Date string, "Y-m-d" or a value beginning with one.
	 * @return string "Y-m-d\T00:00:00", or the original string when no date prefix.
	 */
	public static function toBusinessDate( string $businessDate ): string {
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', trim( $businessDate ), $matches ) ) {
			return $matches[1] . 'T00:00:00';
		}

		return $businessDate;
	}

	/**
	 * Format a WordPress-local wall-clock datetime as ISO 8601 with the site's
	 * configured timezone offset, e.g. "2026-06-12T14:30:00-06:00".
	 *
	 * Used for the available-timeslots request's `slotDate` parameter, which the
	 * CBS data API interprets as an instant and therefore requires with an offset.
	 *
	 * The input is interpreted as already being in the WordPress timezone, so the
	 * wall-clock value is preserved and only the offset is attached (no shifting).
	 * Returns the input unchanged on parse failure so the request still fires.
	 *
	 * @param  string $localDateTime "Y-m-d H:i:s" or "Y-m-d" in the WP timezone.
	 * @return string ISO 8601 datetime with TZ offset, or the original string on
	 *                parse failure.
	 */
	public static function toLocalIso8601( string $localDateTime ): string {
		return self::formatWithOffset( $localDateTime, wp_timezone() );
	}

	/**
	 * Pure core for {@see self::toLocalIso8601()}: interpret a wall-clock string in
	 * the given timezone and emit ISO 8601 with offset. Kept WordPress-free so it is
	 * unit-testable by injecting a fixed timezone.
	 *
	 * @param  string        $localDateTime Wall-clock datetime in $tz.
	 * @param  \DateTimeZone $tz            Timezone whose offset is attached.
	 * @return string ISO 8601 with offset, or the original string on parse failure.
	 */
	private static function formatWithOffset( string $localDateTime, \DateTimeZone $tz ): string {
		try {
			return ( new \DateTime( $localDateTime, $tz ) )->format( 'Y-m-d\TH:i:sP' );
		} catch ( \Exception $e ) {
			return $localDateTime;
		}
	}

	/**
	 * Format a CBS slotTime for display as its literal wall-clock — the HH:MM:SS written
	 * in the string, with the timezone offset ignored (not converted).
	 *
	 * CBS returns slotTime as the time to show the customer; the attached offset is not a
	 * conversion target. A slot tagged "2026-06-19T16:40:00-05:00" is displayed as
	 * "4:40 PM", not shifted to its UTC instant (21:40) or to the site timezone.
	 * strtotime() + date()/date_i18n() resolve the string to a UTC instant and — because
	 * WordPress forces PHP's default timezone to UTC — render that shifted time, so this
	 * reads the literal components via date_parse() and formats them with no timezone math.
	 *
	 * @param  string $slotTime CBS slotTime: ISO 8601 with offset, or a bare "H:i:s".
	 * @param  string $format   PHP date() format for the time. Defaults to "g:i A".
	 * @return string Formatted literal time, or the original string on parse failure.
	 */
	public static function formatDisplayTime( string $slotTime, string $format = 'g:i A' ): string {
		$parsed = date_parse( $slotTime );

		if ( ! is_array( $parsed ) || ! empty( $parsed['error_count'] ) || false === $parsed['hour'] ) {
			return $slotTime;
		}

		$time = sprintf(
			'%02d:%02d:%02d',
			(int) $parsed['hour'],
			(int) $parsed['minute'],
			(int) $parsed['second']
		);

		$dt = \DateTime::createFromFormat( '!H:i:s', $time, new \DateTimeZone( 'UTC' ) );

		return $dt ? $dt->format( $format ) : $slotTime;
	}
}
