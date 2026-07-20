<?php

namespace CBSNorthStar;

use CBSNorthStar\Logger\CBSLogger;

/**
 * Invalidates the catalog product caches when a Northstar Ordering Setting that
 * changes the rendered menu is toggled.
 *
 * Both the server-side render cache and the browser sessionStorage
 * `productCache` (see src/products/view.js) key off the
 * `cbs_catalog_cache_version` option, emitted as `data-catalog-version` in
 * src/products/render.php and consumed in Controllers\MainController. That
 * version is bumped on catalog deploy (SaveProduct::bumpCatalogCacheVersion)
 * but not when a menu-affecting setting changes, so the stale menu survived
 * page reloads until the browser was closed (which clears sessionStorage).
 * Bumping the version on those setting changes lets a plain reload pick them up.
 * (OE-26569)
 */
class CatalogCacheInvalidator
{
    /** Monotonic version shared by the server and browser product caches. */
    private const VERSION_OPTION = 'cbs_catalog_cache_version';

    /**
     * Carbon Fields theme-option keys (stored with the `_` prefix) whose value
     * changes the rendered menu markup and so must invalidate those caches.
     */
    private const WATCHED_OPTIONS = [
        '_cbs_display_quantity',
        '_olo_show_taxable_tag',
    ];

    public static function register(): void
    {
        // `updated_option` fires only when the stored value actually changed,
        // and `added_option` covers the first time a setting is saved, so the
        // bump happens only when a watched setting really changes.
        add_action( 'added_option', [ self::class, 'maybeBump' ], 10, 1 );
        add_action( 'updated_option', [ self::class, 'maybeBump' ], 10, 1 );
    }

    public static function maybeBump( $option ): void
    {
        if ( ! in_array( (string) $option, self::WATCHED_OPTIONS, true ) ) {
            return;
        }

        $current = (int) get_option( self::VERSION_OPTION, '0' );
        $next    = (string) max( 1, $current + 1 );

        // Bumping VERSION_OPTION re-enters this hook; it is not a watched
        // option, so the in_array guard above stops the recursion immediately.
        update_option( self::VERSION_OPTION, $next, false );

        if ( class_exists( CBSLogger::class ) ) {
            CBSLogger::products()->info( 'Catalog cache version bumped after settings change', [
                'cache_version' => $next,
                'option'        => (string) $option,
            ] );
        }
    }
}
