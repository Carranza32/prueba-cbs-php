<?php

namespace CBSNorthStar\Helpers;

/**
 * Manages time-slot reservation state in the WooCommerce session.
 *
 * Storing the reservation server-side prevents reliance on cookies alone and
 * allows checkout validation to confirm that the customer completed a
 * successful backend reservation before the order is placed.
 *
 * Session data shape:
 * [
 *   'time_slot_id'        => string   UUID of the reserved slot
 *   'slot_time'           => string   ISO 8601 slot time with TZ offset
 *   'business_date'       => string   Y-m-d date of the reservation
 *   'raw_value'           => string   Original "{id}|{time}" select value
 *   'reserved_at'         => int      Unix timestamp when the reservation was made
 *   'times_slots_order_id'=> string   CBS timeSlotsOrderId returned by the reserve API
 *                                     — used to release the slot when the user picks
 *                                     a different time or abandons checkout.
 * ]
 */
class TimeSlotReservationSession {

	const SESSION_KEY = 'olo_timeslot_reservation';

	/**
	 * Store reservation data in the WC session.
	 * Any prior reservation for this session is overwritten.
	 *
	 * @param array $data Recognised keys: time_slot_id, slot_time, business_date,
	 *                    raw_value, reserved_at, times_slots_order_id.
	 */
	public static function set( array $data ): void {
		$session = self::session();
		if ( ! $session ) {
			return;
		}
		$session->set( self::SESSION_KEY, $data );

		// REST routes (reserveTimeSlot / releaseTimeslotReservation) never trigger
		// WooCommerce's automatic session-cookie hook (woocommerce_set_cart_cookies,
		// bound to front-end template rendering) — force it here so a guest's
		// reservation survives into the next request instead of silently starting
		// a brand-new anonymous session on every call (OE-26588).
		if ( method_exists( $session, 'set_customer_session_cookie' ) ) {
			$session->set_customer_session_cookie( true );
		}
	}

	/**
	 * Retrieve the current reservation data, or null if none exists.
	 *
	 * @return array|null
	 */
	public static function get(): ?array {
		$session = self::session();
		if ( ! $session ) {
			return null;
		}
		$data = $session->get( self::SESSION_KEY );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Return the CBS timeSlotsOrderId from the active reservation, or null.
	 *
	 * @return string|null
	 */
	public static function getTimeSlotsOrderId(): ?string {
		$data = self::get();
		return ( $data && ! empty( $data['times_slots_order_id'] ) )
			? (string) $data['times_slots_order_id']
			: null;
	}

	/**
	 * Remove reservation data from the session.
	 * Called after a successful order is created or when a prior reservation
	 * has been released via the CBS delete endpoint.
	 */
	public static function clear(): void {
		$session = self::session();
		if ( $session ) {
			$session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * Check whether the stored reservation matches the given slot and date.
	 *
	 * @param  string $timeSlotId   UUID to check against the stored reservation.
	 * @param  string $businessDate Y-m-d date to check against the stored reservation.
	 * @return bool
	 */
	public static function matchesSelection( string $timeSlotId, string $businessDate ): bool {
		$data = self::get();
		if ( ! $data ) {
			return false;
		}
		return isset( $data['time_slot_id'], $data['business_date'] )
			&& $data['time_slot_id'] === $timeSlotId
			&& $data['business_date'] === $businessDate;
	}

	/**
	 * Resolve the active WC session, initialising it if needed.
	 *
	 * @return \WC_Session_Handler|\WC_Session|null
	 */
	private static function session() {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return null;
		}

		if ( ! WC()->session ) {
			if ( ! class_exists( 'WC_Session_Handler' ) ) {
				return null;
			}
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}

		return WC()->session;
	}
}
