<?php

namespace CBSNorthStar\Helpers;

use CBSNorthStar\Logger\CBSLogger;

/**
 * Decide whether a site's kitchen is open, using the ECM-synced kitchenopentime /
 * kitchenclosetime stored in cbs_site_details, evaluated in the site's own timezone.
 *
 * Source of truth for the OE-26385 "respect ECM kitchen hours" gate. Fail-open by design:
 * any missing or unparseable data resolves to "open" so a data problem never wrongly blocks
 * ordering. The time-window comparison mirrors the theme's location-modal isOpenNow().
 */
class KitchenHours {

	private const MINUTES_PER_DAY = 1440;

	/**
	 * Is the kitchen open right now, in the site's own timezone?
	 *
	 * @param array $site Row from cbs_site_details (ARRAY_A): kitchenopentime, kitchenclosetime, timezone.
	 */
	public static function isOpenNow( array $site ): bool {
		if ( null === self::windowMinutes( $site ) ) {
			CBSLogger::general()->warning( '[KITCHEN HOURS] Unparseable kitchen hours — treating site as open', [
				'siteid' => $site['siteid'] ?? '',
				'open'   => $site['kitchenopentime'] ?? null,
				'close'  => $site['kitchenclosetime'] ?? null,
			] );
		}

		return self::isOpenAt( $site, self::nowInSiteTimezone( $site ) );
	}

	/**
	 * Is the kitchen open at $when, interpreted against the kitchen's local wall-clock?
	 *
	 * Pure (no DB, no logging) so the window logic is unit-testable in isolation. Fail-open:
	 * blank / equal / unparseable hours return true.
	 */
	public static function isOpenAt( array $site, \DateTimeInterface $when ): bool {
		$window = self::windowMinutes( $site );
		if ( null === $window ) {
			return true;
		}

		$openMin  = $window['open'];
		$closeMin = $window['close'];

		// Open == close, or a near-full-day span → treat as open 24h.
		$span = ( ( $closeMin - $openMin ) % self::MINUTES_PER_DAY + self::MINUTES_PER_DAY ) % self::MINUTES_PER_DAY;
		if ( 0 === $span || $span >= self::MINUTES_PER_DAY - 1 ) {
			return true;
		}

		$nowMin = (int) $when->format( 'G' ) * 60 + (int) $when->format( 'i' );

		if ( $closeMin > $openMin ) {
			// Same-day window: open at start, closed at the close minute.
			return $nowMin >= $openMin && $nowMin < $closeMin;
		}

		// Overnight window spanning midnight (e.g. 16:00 → 02:00).
		return $nowMin >= $openMin || $nowMin < $closeMin;
	}

	/**
	 * Parse the site's open/close strings to minutes-since-midnight, or null if either is
	 * blank or unparseable.
	 *
	 * @return array{open:int,close:int}|null
	 */
	private static function windowMinutes( array $site ): ?array {
		$openMin  = self::toMinutes( $site['kitchenopentime'] ?? '' );
		$closeMin = self::toMinutes( $site['kitchenclosetime'] ?? '' );

		if ( null === $openMin || null === $closeMin ) {
			return null;
		}

		return [ 'open' => $openMin, 'close' => $closeMin ];
	}

	/**
	 * Parse an ECM hour value ("4:00 AM", "16:00", or an ISO datetime) to minutes since
	 * midnight. Only the wall-clock components are read, so any timezone on the value is
	 * irrelevant. Returns null when blank or unparseable.
	 *
	 * @param mixed $value
	 */
	private static function toMinutes( $value ): ?int {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value || '--' === $value ) {
			return null;
		}

		try {
			$dt = new \DateTime( $value );
		} catch ( \Exception $e ) {
			return null;
		}

		return (int) $dt->format( 'G' ) * 60 + (int) $dt->format( 'i' );
	}

	/**
	 * "Now" as a wall-clock DateTime in the site's timezone.
	 *
	 * Delegates to {@see SiteClock}, the shared per-site-timezone resolver. Falls back to
	 * WordPress current_time() when the column is empty (only populated for
	 * menu_type = "Default") or no mapping row exists.
	 */
	private static function nowInSiteTimezone( array $site ): \DateTime {
		return SiteClock::nowInTimezone( (string) ( $site['timezone'] ?? '' ) );
	}
}
