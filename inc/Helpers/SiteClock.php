<?php

namespace CBSNorthStar\Helpers;

/**
 * Resolves "now" as a wall-clock DateTime in a site's own timezone.
 *
 * A site's BCL/Windows timezone id is stored in cbs_site_details.timezone and mapped to
 * a GMT offset via cbs_time_zone_settings — the same mapping check_valid_menuid_shortcode()
 * and KitchenHours use. Extracted here so the daypart-menu resolver and the kitchen-hours
 * gate share one implementation instead of each carrying its own copy (OE-26418).
 *
 * Fails soft: when a site has no timezone (only menu_type = "Default" sites populate the
 * column) or the id is not in the lookup table, it falls back to WordPress current_time()
 * so a missing-data case degrades to the previous WP-global behaviour rather than erroring.
 */
class SiteClock {

	/**
	 * Per-request memo of resolved GMT offsets, keyed by BCL timezone id. Caches misses
	 * (null) too, so an unknown timezone is queried at most once per request.
	 *
	 * @var array<string,?int>
	 */
	private static array $offsetCache = array();

	/**
	 * Per-request memo of site id => BCL timezone id, so resolving the same site's clock
	 * repeatedly (e.g. category + product paths in one request) hits the DB once.
	 *
	 * @var array<string,string>
	 */
	private static array $siteTzCache = array();

	/**
	 * "Now" as a wall-clock DateTime for a site id.
	 *
	 * Looks up the site's stored BCL timezone, then defers to {@see self::nowInTimezone()}.
	 */
	public static function nowForSite( string $siteId ): \DateTime {
		return self::nowInTimezone( self::siteTimezone( $siteId ) );
	}

	/**
	 * Whether a selected pickup slot has already passed in the site's own clock.
	 *
	 * Compares the slot's literal wall-clock (date_parse ignores the +00:00 offset
	 * the CBS available-slots API tags onto slotTime — matching oloNavSlotOverrides()
	 * and the picker display; running it through a timezone would shift the hour)
	 * against {@see self::nowForSite()}. A configurable grace window
	 * (olo_timeslot_grace_minutes) absorbs minor skew.
	 *
	 * Shared by the checkout backstop (woocommerce_checkout_process) and the daypart
	 * watcher endpoint (active-daypart-menu) so both decide "expired" identically.
	 *
	 * @param string $siteId   Site id (for the site-local clock).
	 * @param string $slotDate Selected slot business date, expected Y-m-d.
	 * @param string $slotTime Selected slot time (ISO 8601 with TZ offset, or H:i[:s]).
	 * @return bool|null true = passed, false = still valid/future, null = could not
	 *                   determine (blank/malformed input or clock error) → fail open.
	 */
	public static function slotHasPassed( string $siteId, string $slotDate, string $slotTime ): ?bool {
		if ( '' === $siteId || '' === $slotDate || '' === $slotTime ) {
			return null;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $slotDate ) ) {
			return null;
		}
		// The regex only checks shape, not that the date is a real calendar day. Without
		// this, new \DateTime() below would silently normalise an impossible date (e.g.
		// 2026-02-31 -> 2026-03-03), turning malformed input into a valid adjacent date
		// that can return true (block checkout) instead of null (fail open). Round-trip
		// through createFromFormat('!Y-m-d', …) ('!' zeroes the time fields): a real,
		// canonically formatted date re-formats to itself; anything else fails open.
		$slotDateObj = \DateTimeImmutable::createFromFormat( '!Y-m-d', $slotDate );
		if ( false === $slotDateObj || $slotDateObj->format( 'Y-m-d' ) !== $slotDate ) {
			return null;
		}

		$parsed = date_parse( $slotTime );
		if ( ! $parsed || $parsed['error_count'] > 0 || false === $parsed['hour'] ) {
			return null;
		}
		// date_parse() flags out-of-range/garbage times via error_count, but an absent
		// minute/second comes back as false (benign) and is coerced to 0. Range-check the
		// final components so no impossible time can slip into the timestamp below.
		$slotHour   = (int) $parsed['hour'];
		$slotMinute = ( false === $parsed['minute'] ) ? 0 : (int) $parsed['minute'];
		$slotSecond = ( false === $parsed['second'] ) ? 0 : (int) $parsed['second'];
		if ( $slotHour > 23 || $slotMinute > 59 || $slotSecond > 59 ) {
			return null;
		}
		$slotStamp = sprintf(
			'%s %02d:%02d:%02d',
			$slotDate,
			$slotHour,
			$slotMinute,
			$slotSecond
		);

		try {
			// Floating (timezone-less) wall-clock instants on both sides: nowForSite()
			// returns a UTC-shifted DateTime whose H:i:s IS the site-local time, so it
			// is re-anchored as a plain string to compare wall-clock against wall-clock.
			$slotWallClock = new \DateTime( $slotStamp );
			$nowWallClock  = new \DateTime( self::nowForSite( $siteId )->format( 'Y-m-d H:i:s' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		$graceMinutes = (int) apply_filters( 'olo_timeslot_grace_minutes', 0 );
		if ( $graceMinutes > 0 ) {
			$slotWallClock->modify( '+' . $graceMinutes . ' minutes' );
		}

		return $nowWallClock > $slotWallClock;
	}

	/**
	 * "Now" as a wall-clock DateTime in the given BCL/Windows timezone.
	 *
	 * Returns WP current_time() when the id is blank or has no cbs_time_zone_settings row.
	 * Only the wall-clock components (H:i:s, weekday) of the result are meaningful.
	 */
	public static function nowInTimezone( string $bclTimeZoneId ): \DateTime {
		$offsetSeconds = self::offsetSeconds( $bclTimeZoneId );

		if ( null === $offsetSeconds ) {
			return new \DateTime( current_time( 'Y-m-d H:i:s' ) , wp_timezone() );
		}

		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$now->modify( ( $offsetSeconds >= 0 ? '+' : '-' ) . abs( $offsetSeconds ) . ' seconds' );

		return $now;
	}

	/**
	 * Convert a site-local wall-clock string (no offset — e.g. an ECM date meant
	 * as "midnight local to that site") into the absolute UTC unix timestamp it
	 * represents, using the same BCL timezone lookup as {@see self::nowForSite()}.
	 *
	 * Used at deploy-write time to store `_active_start_{siteId}`/
	 * `_active_stop_{siteId}` as true UTC epochs (product-active-date-window
	 * capability) so read-time comparisons need no further timezone math.
	 *
	 * @param string $siteId    Site id.
	 * @param string $wallClock 'Y-m-d H:i:s' (or any format \DateTime accepts)
	 *                          local wall-clock, with no offset/zone in it.
	 * @return int|null Absolute UTC unix timestamp, or null when the site's
	 *                   timezone is unknown or $wallClock is malformed (fail
	 *                   open — callers should treat null as "could not determine").
	 */
	public static function epochForSiteLocal( string $siteId, string $wallClock ): ?int {
		$offsetSeconds = self::offsetSeconds( self::siteTimezone( $siteId ) );
		if ( null === $offsetSeconds ) {
			return null;
		}

		try {
			// Parsed as if it were UTC purely to get a stable epoch for the literal
			// wall-clock digits; the site's offset is then subtracted to convert
			// that literal local time into the real UTC instant it corresponds to
			// (e.g. local midnight in UTC-5 is 05:00 real UTC the same day).
			$asIfUtc = new \DateTime( $wallClock, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		return $asIfUtc->getTimestamp() - $offsetSeconds;
	}

	/**
	 * The BCL timezone id stored for a site in cbs_site_details, or '' when unknown.
	 */
	private static function siteTimezone( string $siteId ): string {
		$siteId = trim( $siteId );
		if ( '' === $siteId ) {
			return '';
		}

		if ( array_key_exists( $siteId, self::$siteTzCache ) ) {
			return self::$siteTzCache[ $siteId ];
		}

		global $wpdb;
		$timezone = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT timezone FROM cbs_site_details WHERE siteid = %s AND timezone <> %s LIMIT 1',
				$siteId,
				''
			)
		);

		return self::$siteTzCache[ $siteId ] = ( null === $timezone ) ? '' : (string) $timezone;
	}

	/**
	 * GMT offset in seconds for a BCL/Windows timezone description, or null if unknown.
	 */
	private static function offsetSeconds( string $bclTimeZoneId ): ?int {
		$bclTimeZoneId = trim( $bclTimeZoneId );
		if ( '' === $bclTimeZoneId ) {
			return null;
		}

		if ( array_key_exists( $bclTimeZoneId, self::$offsetCache ) ) {
			return self::$offsetCache[ $bclTimeZoneId ];
		}

		global $wpdb;
		$offset = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT gmt_offset FROM cbs_time_zone_settings WHERE timezone_desc = %s',
				$bclTimeZoneId
			)
		);

		$seconds = ( null === $offset || '' === $offset )
			? null
			: self::parseOffsetToSeconds( (string) $offset );

		return self::$offsetCache[ $bclTimeZoneId ] = $seconds;
	}

	/**
	 * Parse a gmt_offset string ("0", "-5:00", "+5:30") to seconds, sign applied to both
	 * hours and minutes. Returns null if malformed.
	 */
	private static function parseOffsetToSeconds( string $offset ): ?int {
		$offset = trim( $offset );
		if ( ! preg_match( '/^([+-]?)(\d{1,2})(?::(\d{2}))?$/', $offset, $m ) ) {
			return null;
		}

		$sign    = ( '-' === $m[1] ) ? -1 : 1;
		$hours   = (int) $m[2];
		$minutes = isset( $m[3] ) ? (int) $m[3] : 0;

		if ( $hours > 14 || $minutes > 59 ) {
			return null;
		}

		if ( 14 === $hours && 0 !== $minutes ) {
			return null;
		}

		return $sign * ( $hours * 3600 + $minutes * 60 );
	}
}
