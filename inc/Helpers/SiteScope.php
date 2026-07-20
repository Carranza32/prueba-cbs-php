<?php

namespace CBSNorthStar\Helpers;

/**
 * Resolves the active site id for menu read paths and builds the canonical
 * `_siteid` product meta_query clause.
 *
 * The whole point is to fail CLOSED: when the active site cannot be resolved we
 * return an empty id (callers render nothing) or a never-match meta clause,
 * instead of silently dropping the site filter and leaking every site's items
 * onto the wrong menu (OE-26387).
 */
class SiteScope {

	/**
	 * Sentinel forced into the meta_query when no site can be resolved. Real
	 * site ids come from ECM (GUID-like strings), so this literal can never
	 * collide with a stored `_siteid` value — the query simply matches nothing.
	 */
	public const NO_SITE = '__no_site__';

	/**
	 * Per-request memo of validation results: site id => validated site id, or
	 * '' when the candidate is not a real (enabled) site.
	 *
	 * @var array<string,string>
	 */
	private static $validated = array();

	/**
	 * Resolve the active site id, failing closed.
	 *
	 * Resolution order, first non-empty wins: explicit REST `site_id` param ->
	 * `$_POST['site_id']`/`$_POST['siteid']` (admin-ajax handlers) ->
	 * `$_GET['site_id']` -> `$_COOKIE['siteid']` -> caller-supplied attribute
	 * fallback (e.g. the products/categories block `siteId` attribute). The
	 * candidate is then validated against `cbs_site_details`; an unknown,
	 * disabled or empty site yields '' so callers can render nothing.
	 *
	 * @param \WP_REST_Request|null $request           Current REST request, if any.
	 * @param string                $attributeFallback Block-attribute siteId fallback.
	 */
	public static function resolveActiveSiteId( $request = null, string $attributeFallback = '' ): string {
		$candidate = '';

		if ( $request instanceof \WP_REST_Request ) {
			$param = $request->get_param( 'site_id' );
			if ( null !== $param && '' !== $param ) {
				$candidate = sanitize_text_field( wp_unslash( $param ) );
			}
		}

		// admin-ajax handlers pass the site over POST (e.g. get_wc_categories /
		// getProductsByCategoryId for the cbs_menuonline menu).
		if ( '' === $candidate && ! empty( $_POST['site_id'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_POST['site_id'] ) );
		}

		if ( '' === $candidate && ! empty( $_POST['siteid'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_POST['siteid'] ) );
		}

		if ( '' === $candidate && ! empty( $_GET['site_id'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_GET['site_id'] ) );
		}

		if ( '' === $candidate && ! empty( $_COOKIE['siteid'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_COOKIE['siteid'] ) );
		}

		if ( '' === $candidate && '' !== $attributeFallback ) {
			$candidate = sanitize_text_field( $attributeFallback );
		}

		if ( '' === $candidate ) {
			return '';
		}

		return self::isValidSite( $candidate ) ? $candidate : '';
	}

	/**
	 * Canonical `_siteid` meta_query clause for product queries.
	 *
	 * When $siteId is empty the clause is forced to a never-match sentinel so
	 * callers that cannot short-circuit (WP/WC query filters) render no products
	 * instead of every site's products.
	 *
	 * @return array{key:string,value:string,compare:string}
	 */
	public static function productSiteMetaClause( string $siteId ): array {
		return array(
			'key'     => '_siteid',
			'value'   => '' !== $siteId ? $siteId : self::NO_SITE,
			'compare' => '=',
		);
	}

	/**
	 * Whether $siteId is a real, enabled site in the current configuration.
	 */
	private static function isValidSite( string $siteId ): bool {
		if ( array_key_exists( $siteId, self::$validated ) ) {
			return '' !== self::$validated[ $siteId ];
		}

		global $wpdb;
		// Validate the site exists and is enabled across ANY configuration.
		// Sites legitimately live under older config_ids, so this must NOT be
		// scoped to the latest config — doing so fail-closes a valid site's
		// whole menu (e.g. a site on config 52 while the latest is 53).
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM cbs_site_details WHERE siteid = %s AND menu_type <> %s LIMIT 1",
				$siteId,
				'Disabled'
			)
		);

		self::$validated[ $siteId ] = $exists ? $siteId : '';

		return (bool) $exists;
	}
}
