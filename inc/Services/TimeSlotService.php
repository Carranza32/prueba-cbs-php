<?php

namespace CBSNorthStar\Services;

use CBSNorthStar\DataApi\Timeslots\TimeSlotsConnection;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Helpers\TimeSlotValueParser;
use CBSNorthStar\Helpers\SiteClock;

class TimeSlotService {
    public static function create(): TimeSlotService {
        return new TimeSlotService();
    }

    public function oloGetAvailableTimeslotOptions(string $siteId, string $areaId, string $slotDate): array {

        // Business date of the requested slots, captured before $slotDate is reshaped to ISO.
        // Used below to drop slots that have already passed in the site's own clock — CBS clamps
        // to the last slot when every slot is past instead of returning empty.
        $businessDate = substr($slotDate, 0, 10);

        // CBS interprets slotDate as an instant, so it must carry the timezone
        // offset (ISO 8601). Callers pass a naive WP-local wall-clock string.
        $slotDate = TimeSlotValueParser::toLocalIso8601($slotDate);

        $baseUrl = "/available";
        $url     = add_query_arg([
            'areaId'   => $areaId,
            'slotDate' => $slotDate,
        ], $baseUrl);

		CBSLogger::api()->info('Fetching time slots', ['siteId' => $siteId, 'areaId' => $areaId, 'slotDate' => $slotDate]);

        try {
            $response = (new TimeSlotsConnection())->getData($siteId, $url, 'Token');
        } catch(\Exception $e) {
            CBSLogger::api()->error('Failed to fetch available time slots', ['message' => $e->getMessage()]);
        }

        $options = [
            '' => __('Select a time slot', 'olo'),
        ];

        foreach ($response->availableTimeSlots as $slot) {
            if (empty($slot->timeSlotId) || empty($slot->slotTime)) continue;


            if (isset($slot->capacityAvailable) && (int)$slot->capacityAvailable <= 0) {
                continue;
            }

            // Drop slots that have already passed in the SITE's own clock (SiteClock — shared with
            // the checkout backstop and daypart watcher, so "expired" is defined identically and
            // honors the WP timezone vs site timezone difference). Guards CBS's clamp-to-last-slot
            // behaviour. '=== true' only: a null (undeterminable) result fails open and keeps the slot.
            if (true === SiteClock::slotHasPassed($siteId, $businessDate, $slot->slotTime)) {
                continue;
            }

            $value = $slot->timeSlotId . '|' . $slot->slotTime;
            // Display the literal wall-clock CBS returned (offset ignored), not the UTC instant.
            $label = TimeSlotValueParser::formatDisplayTime($slot->slotTime);

            if (isset($slot->capacityAvailable, $slot->totalCapacity) && carbon_get_theme_option('olo_time_slot_debug_capacity')) {
                $label .= sprintf(' (%d/%d)', (int)$slot->capacityAvailable, (int)$slot->totalCapacity);
            }
            $options[$value] = $label;
        }
        return $options;
    }

	/**
	 * Call the CBS reserve endpoint for a specific time slot.
	 *
	 * @param  string $siteId       CBS site identifier (from cookie/config).
	 * @param  string $businessDate Y-m-d date of the slot being reserved.
	 * @param  string $rawSlotTime Raw slotTime string from the CBS available-slots API.
	 * @param  string $timeSlotId  UUID of the slot being reserved.
	 * @return array{success: bool, message: string, response?: object}
	 */
	public function reserveTimeSlot(
		string $siteId,
		string $businessDate,
		string $rawSlotTime,
		string $timeSlotId
	): array {
		$payload = TimeSlotValueParser::buildReservePayload( $businessDate, $rawSlotTime, $timeSlotId );

		try {
			$response = ( new TimeSlotsConnection() )->reserveTimeSlot( $siteId, $payload );
		} catch ( \Exception $e ) {
			write_log( '[TimeSlotService] reserveTimeSlot error: ' . $e->getMessage() );
			return [ 'success' => false, 'message' => $e->getMessage() ];
		}

		// Require a valid object with success === true; anything else is a failure.
		if ( ! is_object( $response ) || empty( $response->success ) ) {
			$msg = ( is_object( $response ) && ! empty( $response->error ) )
				? (string) $response->error
				: 'Reservation failed.';
			write_log( '[TimeSlotService] reserveTimeSlot CBS error: ' . $msg . ' raw=' . wp_json_encode( $response ) );
			return [ 'success' => false, 'message' => $msg ];
		}

		return [ 'success' => true, 'message' => '', 'response' => $response ];
	}

	/**
	 * Confirm a previously reserved time slot via the CBS confirm endpoint.
	 *
	 * Should be called after the order has been successfully submitted to WOAPI.
	 *
	 * @param  string $siteId           CBS site identifier.
	 * @param  string $timeSlotsOrderId The timeSlotsOrderId saved on the WC order.
	 * @return bool True if CBS acknowledged the confirmation, false otherwise.
	 */
	public function confirmTimeSlot( string $siteId, string $timeSlotsOrderId, string $checkId = '' ): bool {
		try {
			$response = ( new TimeSlotsConnection() )->confirmTimeSlot( $siteId, $timeSlotsOrderId, $checkId );
		} catch ( \Exception $e ) {
			CBSLogger::api()->error( '[TimeSlotService] confirmTimeSlot request failed', [
				'message'          => $e->getMessage(),
				'siteId'           => $siteId,
				'timeSlotsOrderId' => $timeSlotsOrderId,
			] );
			return false;
		}

		if ( ! is_object( $response ) || empty( $response->success ) ) {
			$msg = ( is_object( $response ) && ! empty( $response->error ) )
				? (string) $response->error
				: 'Confirm failed.';
			CBSLogger::api()->error( '[TimeSlotService] confirmTimeSlot CBS error', [
				'error'            => $msg,
				'siteId'           => $siteId,
				'timeSlotsOrderId' => $timeSlotsOrderId,
				'raw'              => $response,
			] );
			return false;
		}

		return true;
	}

	/**
	 * Release a previously reserved time slot via the CBS delete endpoint.
	 *
	 * Failures are logged and swallowed — a delete failure must not prevent
	 * the customer from reserving a different slot.
	 *
	 * @param  string $siteId              CBS site identifier.
	 * @param  string $timeSlotsOrderId timeSlotsOrderId returned by the reserve API.
	 * @param  string $businessDate        Y-m-d date of the slot being released.
	 * @param  string $rawSlotTime        Raw slotTime string (ISO 8601 preferred).
	 * @param  string $timeSlotId         UUID of the slot being released.
	 * @return bool True if the API acknowledged the delete, false otherwise.
	 */
	public function deleteTimeSlotReservation(
		string $siteId,
		string $timeSlotsOrderId,
		string $businessDate,
		string $rawSlotTime,
		string $timeSlotId,
		bool   $blocking = true
	): bool {
		$sessionId = '';
		$userId    = (string) get_current_user_id();

		if ( function_exists( 'WC' ) && WC()->session ) {
			$sessionId = (string) WC()->session->get_customer_id();
		}

		$reserveFields = TimeSlotValueParser::buildReservePayload( $businessDate, $rawSlotTime, $timeSlotId );

		$payload = [
			'businessDate' => $reserveFields['businessDate'],
			'slotTime'     => $reserveFields['slotTime'],
			'timeSlotId'   => $reserveFields['timeSlotId'],
			'sessionId'    => $sessionId,
			'userId'       => $userId,
		];

		try {
			$response = ( new TimeSlotsConnection() )->deleteTimeSlotReservation(
				$siteId,
				$timeSlotsOrderId,
				$payload,
				$blocking
			);
		} catch ( \Exception $e ) {
			write_log( '[TimeSlotService] deleteTimeSlotReservation error: ' . $e->getMessage() );
			return false;
		}

		// Fire-and-forget release (e.g. on site switch): the request was dispatched non-blocking,
		// there is no response to confirm. Treat as best-effort success — the hold otherwise
		// expires on its own server-side.
		if ( ! $blocking ) {
			return true;
		}

		// Require a valid object with Ok === true; anything else is a failure.
		if ( ! is_object( $response ) || ! isset( $response->Ok ) || true !== $response->Ok ) {
			$msg = ( is_object( $response ) && isset( $response->ErrorMessage ) )
				? (string) $response->ErrorMessage
				: 'Delete failed.';
			write_log( '[TimeSlotService] deleteTimeSlotReservation CBS error: ' . $msg . ' raw=' . wp_json_encode( $response ) );
			return false;
		}

		return true;
	}
}
