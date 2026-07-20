<?php

namespace CBSNorthStar\Helpers;

use CBSNorthStar\Repositories\LocationsRepository;

class CurrentLocation {

	private static $cache = null;

	public static function details(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$siteId = isset( $_COOKIE['siteid'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['siteid'] ) )
			: '';

		$empty = [
			'site_id' => '',
			'name'    => '',
			'address' => '',
			'city'    => '',
			'state'   => '',
		];

		if ( '' === $siteId ) {
			return self::$cache = $empty;
		}

		global $wpdb;
		$repo     = new LocationsRepository( $wpdb );
		$configId = $repo->getLatestConfigId();
		$row      = $repo->findBySiteId( $siteId, $configId );

		if ( null === $row ) {
			return self::$cache = $empty;
		}

		return self::$cache = [
			'site_id' => (string) ( $row['siteid']    ?? '' ),
			'name'    => (string) ( $row['site_name'] ?? '' ),
			'address' => (string) ( $row['address1']  ?? '' ),
			'city'    => (string) ( $row['city']      ?? '' ),
			'state'   => (string) ( $row['state']     ?? '' ),
		];
	}

	public static function name(): string {
		return self::details()['name'];
	}

	public static function address(): string {
		return self::details()['address'];
	}

	public static function city(): string {
		return self::details()['city'];
	}

	public static function state(): string {
		return self::details()['state'];
	}
}
