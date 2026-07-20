<?php

namespace CBSNorthStar\DataApi\Timeslots;

use CBSNorthStar\Helpers\WoapiRequest;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Repositories\ConfigurationRepository;


class TimeSlotsConnection extends \CBSNorthStar\Woapi\Connection {

    protected ?ConfigurationRepository $configuration;
    public function __construct()
    {
        $this->configuration = ConfigurationRepository::create();
    }
  
    /**
     * @param $siteId
     * @param $url
     * @return array
     */
    protected function getConfiguration( $siteId,  $path): array
    {
        $configuration = $this->configuration->getDetails();
        $instanceName  = (string) ($configuration->instance ?? '');
        $token         = (string) ($configuration->token ?? '');

        $baseUrl = $this->resolveBaseUrl($instanceName);
        $baseUrl = trailingslashit($baseUrl);

        $path = '/' . ltrim($path, '/');

        $url = $baseUrl . 'data/api/v1/timeslots/site/' . rawurlencode($siteId) . $path;

        return array($token, $url);
    }

	/**
	 * POST to the CBS reserve endpoint for the given site.
	 *
	 * Delegates HTTP transport to the shared WoapiRequest layer so authentication
	 * headers, logging, and error handling are consistent with the rest of the plugin.
	 *
	 * @param  string $siteId  CBS site identifier.
	 * @param  array  $payload  Associative array matching the reserve request body.
	 * @return object|null      Decoded response object, or null on WP_Error.
	 * @throws \Exception       On HTTP-level failure (non-2xx or invalid JSON).
	 */
	public function reserveTimeSlot( string $siteId, array $payload ): ?object {
		// Log exactly what we POST to the reserve endpoint (token omitted) for QA debugging.
		// Log $payload as an array: the logger JSON-encodes the whole context, so a pre-
		// encoded string would show with escaped quotes and read like a double-encode.
		[ , $reserveUrl ] = $this->getConfiguration( $siteId, '/reserve' );
		CBSLogger::api()->info( '[TimeSlotsConnection] Reserve request', [
			'siteId'  => $siteId,
			'url'     => $reserveUrl,
			'payload' => $payload,
		] );

		$response = $this->postData( $siteId, '/reserve', 'Token', wp_json_encode( $payload ) );

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Reserve request WP_Error: ' . $response->get_error_message() );
		}

		return is_object( $response ) ? $response : null;
	}

	/**
	 * PUT to the CBS confirm endpoint for a previously reserved time slot.
	 *
	 * Endpoint: PUT data/api/v1/timeslots/site/{siteId}/confirm?timeSlotsOrderId={id}
	 *
	 * @param  string $siteId           CBS site identifier.
	 * @param  string $timeSlotsOrderId The timeSlotsOrderId returned by the reserve API.
	 * @return object|null              Decoded response object, or null on failure.
	 * @throws \Exception               On HTTP-level failure or WP_Error.
	 */
	public function confirmTimeSlot( string $siteId, string $timeSlotsOrderId, string $checkId = '' ): ?object {
		[ $token, $baseUrl ] = $this->getConfiguration( $siteId, '/confirm' );

		$params = [ 'timeSlotsOrderId' => rawurlencode( $timeSlotsOrderId ) ];
		if ( $checkId !== '' ) {
			$params['checkId'] = rawurlencode( $checkId );
		}
		$url = add_query_arg( $params, $baseUrl );

		// Log exactly what we PUT to the confirm endpoint, mirroring reserveTimeSlot()'s
		// logging above — lets QA/support verify a non-empty checkId was sent for a given
		// order without re-deriving it from wp-remote HTTP transport logs (OE-26588).
		CBSLogger::api()->info( '[TimeSlotsConnection] Confirm request', [
			'siteId'           => $siteId,
			'timeSlotsOrderId' => $timeSlotsOrderId,
			'checkId'          => $checkId,
			'url'              => $url,
		] );

		$response = wp_remote_request( $url, [
			'method'  => 'PUT',
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Token ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body'    => '{}',
		] );

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Confirm request WP_Error: ' . $response->get_error_message() );
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body );

		return is_object( $decoded ) ? $decoded : null;
	}

	/**
	 * DELETE a previously reserved time slot via the CBS delete endpoint.
	 *
	 * Endpoint: DELETE data/api/v1/timeslots/site/{siteId}/delete?timeSlotsOrderId={id}
	 *
	 * The existing deleteData() helper on the parent class does not support a
	 * request body, so this method builds the request directly using wp_remote_request().
	 *
	 * @param  string $siteId             CBS site identifier.
	 * @param  string $timeSlotsOrderId The timeSlotsOrderId returned by the reserve API.
	 * @param  array  $payload             Body: businessDate, slotTime, timeSlotId, sessionId, userId.
	 * @return object|null                 Decoded response object, or null on failure.
	 */
	public function deleteTimeSlotReservation(
		string $siteId,
		string $timeSlotsOrderId,
		array  $payload,
		bool   $blocking = true
	): ?object {
		[ $token, $baseUrl ] = $this->getConfiguration( $siteId, '/delete' );

		$url = add_query_arg( [ 'timeSlotsOrderId' => rawurlencode( $timeSlotsOrderId ) ], $baseUrl );

		$response = wp_remote_request( $url, [
			'method'   => 'DELETE',
			// Non-blocking fire-and-forget (e.g. releasing a hold on site switch) must not sit
			// on a render/critical path waiting out the full timeout; it returns immediately and
			// the caller does not read a response.
			'blocking' => $blocking,
			'timeout'  => $blocking ? 15 : 1,
			'headers' => [
				'Authorization' => 'Token ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( ! $blocking ) {
			// Request dispatched; there is no body to read in non-blocking mode.
			return null;
		}

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Delete reservation WP_Error: ' . $response->get_error_message() );
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body );

		return is_object( $decoded ) ? $decoded : null;
	}

    private function resolveBaseUrl(string $instance_name): string
    {
        $instance_name = strtolower(trim($instance_name));


        $special = [
            'aws' => 'https://staging-services.cbsnorthstar.com/staging/',
            'ylcc' => 'https://dev-services.cbsnorthstar.com/dev/',
            'dev'     => 'https://dev-services.cbsnorthstar.com/dev/',
			'callaway' => 'https://callaway-services.cbsnorthstar.com/callaway/',
        ];

        if (isset($special[$instance_name])) {
            return $special[$instance_name];
        }

        return 'https://services.cbsnorthstar.com/' . rawurlencode($instance_name) . '/';
    }
}
