<?php
/**
 * OEAPI daypart-config cache for the legacy menu shortcodes (OE-26548).
 *
 * @package NorthStarOnlineOrdering
 */

namespace CBSNorthStar\Helpers;

/**
 * Short-lived cache for the read-only OEAPI config calls made while rendering the
 * legacy menu shortcodes.
 *
 * The `[categories]` / `[menuitems]` render path resolves the active daypart menu
 * from three OEAPI reads per request (site info, area→menu map, daypart schedule).
 * Those responses are static configuration that only changes on a deploy, yet they
 * were fetched uncached on every page load — three sequential cURL calls (5s connect
 * + 15s read each) on the critical path of every menu render (OE-26548).
 *
 * This helper memoises the raw responses in a transient so repeat renders skip the
 * network entirely. It deliberately caches the RAW response, never the resolved menu
 * id: the time-dependent selection ({@see check_valid_menuid_shortcode()}) keeps
 * running live against the cached data, so daypart rollover stays time-accurate.
 *
 * Invalidation mirrors the REST product cache: the key folds in
 * `cbs_catalog_cache_version` (bumped by the deploy) so a deploy invalidates instantly,
 * with a modest TTL as a safety net. Failed/empty responses are never cached.
 */
class ShortcodeApiCache {

	/** Transient key prefix for cached OEAPI shortcode responses. */
	private const KEY_PREFIX = 'cbs_oeapi_';

	/**
	 * Default TTL (seconds) for cached OEAPI config — a safety net beneath the
	 * deploy-driven version bust. Filterable via `cbs_oeapi_cache_ttl`.
	 */
	private const DEFAULT_TTL = 600; // 10 * MINUTE_IN_SECONDS

	/**
	 * Build the transient key for an endpoint response.
	 *
	 * The URL already carries the site id (`/sites/{siteId}/…`), so md5(url) scopes
	 * the entry per site + endpoint; the catalog version busts every entry on deploy.
	 *
	 * @param string $endpoint Short label for readability (e.g. 'site', 'dayparts').
	 * @param string $url      Full OEAPI request URL.
	 */
	public static function key( string $endpoint, string $url ): string {
		$version = (string) get_option( 'cbs_catalog_cache_version', '0' );

		return self::KEY_PREFIX . $endpoint . '_' . md5( $url ) . '_' . $version;
	}

	/**
	 * Resolve the cache TTL in seconds (filterable).
	 */
	public static function ttl(): int {
		return (int) apply_filters( 'cbs_oeapi_cache_ttl', self::DEFAULT_TTL );
	}

	/**
	 * Return the cached response for $url, or run $fetch and cache a successful result.
	 *
	 * A successful response is a truthy value whose `->Data` is non-empty — the same
	 * success shape {@see get_api_data_shortcode()} itself returns. Anything else
	 * (null on cURL failure, empty `Data`) is passed through WITHOUT caching so the
	 * next render retries rather than pinning an empty menu for the whole TTL.
	 *
	 * @param string   $endpoint Short label used in the cache key.
	 * @param string   $url      Full OEAPI request URL.
	 * @param callable $fetch    Zero-arg callable returning the raw response (or null).
	 * @return mixed The cached or freshly fetched response.
	 */
	public static function remember( string $endpoint, string $url, callable $fetch ) {
		$cacheKey = self::key( $endpoint, $url );

		$cached = get_transient( $cacheKey );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = $fetch();

		if ( ! empty( $response ) && ! empty( ( (array) $response )['Data'] ) ) {
			set_transient( $cacheKey, $response, self::ttl() );
		}

		return $response;
	}
}
