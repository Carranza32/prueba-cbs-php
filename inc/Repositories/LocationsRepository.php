<?php
namespace CBSNorthStar\Repositories;

final class LocationsRepository {

	private const EARTH_RADIUS_MILES = 3956.0;
	private const DEFAULT_RADIUS     = 5.0;

	/**
	 * @var wpdb
	 */
	private $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function getLatestConfigId(): int {
		$sql = "SELECT id FROM cbs_configure_details ORDER BY id DESC LIMIT 1";
		$id  = $this->wpdb->get_var( $sql );

		return $id ? (int) $id : 0;
	}

	public function searchLocations( string $keyword, float $radius, int $configId ): array {
		$keyword = sanitize_text_field( $keyword );
		$radius  = $this->normalizeRadius( $radius );

		if ( '' === $keyword ) {
			return $this->getAllLocations( $configId );
		}

		if ( $this->isZipQuery( $keyword ) ) {
			$coords = $this->getCoordinatesFromZip( $keyword );

			if ( ! empty( $coords['lat'] ) && ! empty( $coords['lng'] ) ) {
				$exact_results = $this->findByExactZip( $keyword, $configId, (float) $coords['lat'], (float) $coords['lng'] );
			} else {
				$exact_results = $this->findByExactZip( $keyword, $configId );
			}

			if ( ! empty( $exact_results ) ) {
				return $exact_results;
			}

			if ( ! empty( $coords['lat'] ) && ! empty( $coords['lng'] ) ) {
				$zip_results = $this->getLocationsByCoordinates( (float) $coords['lat'], (float) $coords['lng'], $radius, $configId );
			} else {
				$zip_results = [];
			}

			if ( ! empty( $zip_results ) ) {
				return $zip_results;
			}
		}

		return $this->findByTextSearch( $keyword, $configId );
	}

	public function getLocationsByCoordinates( float $lat, float $lng, float $radius, int $configId ): array {
		$radius = $this->normalizeRadius( $radius );

		$sql = $this->wpdb->prepare(
			"
			SELECT
				sd.*,
				cd.*,
				(
					%f * 2 * ASIN(
						SQRT(
							POWER( SIN( ( %f - sd.latitude ) * PI() / 180 / 2 ), 2 ) +
							COS( %f * PI() / 180 ) * COS( sd.latitude * PI() / 180 ) *
							POWER( SIN( ( %f - sd.longitude ) * PI() / 180 / 2 ), 2 )
						)
					)
				) AS distance
			FROM cbs_site_details sd
			INNER JOIN cbs_configure_details cd
				ON sd.config_id = cd.id
			WHERE sd.menu_type <> %s
			  AND sd.config_id = %d
			  AND sd.latitude IS NOT NULL
			  AND sd.longitude IS NOT NULL
			HAVING distance <= %f
			ORDER BY distance ASC, sd.site_name ASC
			",
			self::EARTH_RADIUS_MILES,
			$lat,
			$lat,
			$lng,
			'Disabled',
			$configId,
			$radius
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	public function findBySiteId( string $siteId, int $configId ): ?array {
		if ( '' === $siteId || $configId <= 0 ) {
			return null;
		}

		$sql = $this->wpdb->prepare(
			"
			SELECT sd.*, cd.*
			FROM cbs_site_details sd
			INNER JOIN cbs_configure_details cd
				ON sd.config_id = cd.id
			WHERE sd.menu_type <> %s
			  AND sd.config_id = %d
			  AND sd.siteid = %s
			LIMIT 1
			",
			'Disabled',
			$configId,
			$siteId
		);

		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return $row ?: null;
	}

	public function getAllLocations( int $configId ): array {
		$sql = $this->wpdb->prepare(
			"
			SELECT sd.*, cd.*
			FROM cbs_site_details sd
			INNER JOIN cbs_configure_details cd
				ON sd.config_id = cd.id
			WHERE sd.menu_type <> %s
			  AND sd.config_id = %d
			ORDER BY sd.site_name ASC
			",
			'Disabled',
			$configId
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	private function findByTextSearch( string $raw_search, int $configId ): array {
		$tokens = preg_split(
			'/[^\p{L}\p{N}]+/u',
			mb_strtolower( $raw_search ),
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		$tokens = array_values(
			array_unique(
				array_filter(
					$tokens,
					static function ( $token ) {
						return mb_strlen( $token ) >= 2;
					}
				)
			)
		);

		$tokens = array_slice( $tokens, 0, 6 );

		$base_sql = "
			SELECT sd.*, cd.*
			FROM cbs_site_details sd
			INNER JOIN cbs_configure_details cd
				ON sd.config_id = cd.id
			WHERE sd.menu_type <> %s
			  AND sd.config_id = %d
		";

		$args = [ 'Disabled', $configId ];

		if ( empty( $tokens ) ) {
			return $this->wpdb->get_results(
				$this->wpdb->prepare( $base_sql, ...$args ),
				ARRAY_A
			);
		}

		$token_clauses = [];

		foreach ( $tokens as $token ) {
			$like = '%' . $this->wpdb->esc_like( $token ) . '%';

			$token_clauses[] = "(
				LOWER(sd.zipcode) LIKE %s
				OR LOWER(sd.city) LIKE %s
				OR LOWER(sd.address1) LIKE %s
				OR LOWER(sd.state) LIKE %s
				OR LOWER(sd.site_name) LIKE %s
				OR LOWER(CONCAT_WS(' ', sd.address1, sd.city, sd.state, sd.zipcode)) LIKE %s
			)";

			array_push( $args, $like, $like, $like, $like, $like, $like );
		}

		$strict_sql = $base_sql . ' AND ' . implode( ' AND ', $token_clauses );
		$strict     = $this->wpdb->get_results(
			$this->wpdb->prepare( $strict_sql, ...$args ),
			ARRAY_A
		);

		if ( ! empty( $strict ) ) {
			return $strict;
		}

		if ( count( $tokens ) > 1 ) {
			$relaxed_sql = $base_sql . ' AND (' . implode( ' OR ', $token_clauses ) . ')';

			return $this->wpdb->get_results(
				$this->wpdb->prepare( $relaxed_sql, ...$args ),
				ARRAY_A
			);
		}

		return [];
	}

	private function findByExactZip( string $zip, int $configId, float $lat = null, float $lng = null ): array {
		if ( null !== $lat && null !== $lng ) {
			$sql = $this->wpdb->prepare(
				"
				SELECT sd.*, cd.*,
					(
						%f * 2 * ASIN(
							SQRT(
								POWER( SIN( ( %f - sd.latitude ) * PI() / 180 / 2 ), 2 ) +
								COS( %f * PI() / 180 ) * COS( sd.latitude * PI() / 180 ) *
								POWER( SIN( ( %f - sd.longitude ) * PI() / 180 / 2 ), 2 )
							)
						)
					) AS distance
				FROM cbs_site_details sd
				INNER JOIN cbs_configure_details cd
					ON sd.config_id = cd.id
				WHERE sd.menu_type <> %s
				  AND sd.config_id = %d
				  AND sd.zipcode = %s
				ORDER BY distance ASC, sd.site_name ASC
				",
				self::EARTH_RADIUS_MILES,
				$lat,
				$lat,
				$lng,
				'Disabled',
				$configId,
				trim( $zip )
			);
		} else {
			$sql = $this->wpdb->prepare(
				"
				SELECT sd.*, cd.*
				FROM cbs_site_details sd
				INNER JOIN cbs_configure_details cd
					ON sd.config_id = cd.id
				WHERE sd.menu_type <> %s
				  AND sd.config_id = %d
				  AND sd.zipcode = %s
				ORDER BY sd.site_name ASC
				",
				'Disabled',
				$configId,
				trim( $zip )
			);
		}

		return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
	}

	private function findByZipProximity( string $zip, float $radius, int $configId ): array {
		$coords = $this->getCoordinatesFromZip( $zip );

		if ( empty( $coords['lat'] ) || empty( $coords['lng'] ) ) {
			return [];
		}

		return $this->getLocationsByCoordinates(
			(float) $coords['lat'],
			(float) $coords['lng'],
			$radius,
			$configId
		);
	}

	private function isZipQuery( string $value ): bool {
		return (bool) preg_match( '/^\d{5}(?:-\d{4})?$/', trim( $value ) );
	}

	private function normalizeRadius( float $radius ): float {
		if ( $radius <= 0 ) {
			return self::DEFAULT_RADIUS;
		}

		return min( max( $radius, 0.1 ), 50 );
	}

	private function getCoordinatesFromZip( string $zip ): array {
		$zip       = trim( $zip );
		$cache_key = 'cbs_zip_geo_' . md5( $zip );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$url = add_query_arg(
			[
				'postalcode'   => $zip,
				'countrycodes' => 'us',
				'format'       => 'jsonv2',
				'limit'        => 1,
			],
			'https://nominatim.openstreetmap.org/search'
		);

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'User-Agent' => 'CBS Locations Search/1.0',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
            set_transient($cache_key, [], HOUR_IN_SECONDS);
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
            set_transient($cache_key, [], HOUR_IN_SECONDS);
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data[0]['lat'] ) || empty( $data[0]['lon'] ) ) {
            set_transient($cache_key, [], HOUR_IN_SECONDS);
			return [];
		}

		$coords = [
			'lat' => (float) $data[0]['lat'],
			'lng' => (float) $data[0]['lon'],
		];

		set_transient( $cache_key, $coords, DAY_IN_SECONDS );

		return $coords;
	}
}