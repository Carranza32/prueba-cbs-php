<?php

namespace CBSNorthStar\Cli;

use CBSNorthStar\Migrations\FixOrphanCategories;

/**
 * WP-CLI command: wp northstar fix-orphan-categories
 *
 * Consolidates duplicate product_cat terms that share the same external
 * menu_item_category_id meta on one or all Northstar sites.
 */
class FixOrphanCategoriesCommand
{
  /**
   * Fix orphaned/duplicate product categories caused by name-based term matching.
   *
   * ## OPTIONS
   *
   * [--site-id=<id>]
   * : Limit the migration to a single site ID (must be a positive integer). Omit to run for all sites.
   *
   * ## EXAMPLES
   *
   *     wp northstar fix-orphan-categories
   *     wp northstar fix-orphan-categories --site-id=5
   *
   * @when after_wp_load
   *
   * @param array $args        Positional arguments (unused).
   * @param array $assocArgs   Associative arguments.
   */
  public function __invoke( array $args, array $assocArgs ): void
  {
    $rawSiteId = $assocArgs['site-id'] ?? null;
    $siteId    = null;

    if ( $rawSiteId !== null ) {
      if ( ! is_numeric( $rawSiteId ) || (int) $rawSiteId <= 0 ) {
        \WP_CLI::error( '--site-id must be a positive integer; got: ' . $rawSiteId );
      }
      $siteId = (int) $rawSiteId;
    }

    \WP_CLI::log( $siteId !== null
      ? "Running FixOrphanCategories for site {$siteId}..."
      : 'Running FixOrphanCategories for all sites...'
    );

    $summary = ( new FixOrphanCategories() )->run( $siteId );

    if ( empty( $summary ) ) {
      \WP_CLI::warning( 'No sites processed — a deploy may be in progress or no sites have category meta.' );
      return;
    }

    foreach ( $summary as $sid => $data ) {
      \WP_CLI::log( sprintf(
        'Site %d — duplicate groups: %d, terms deleted: %d, products reassigned: %d',
        $sid,
        $data['duplicate_groups'],
        $data['terms_deleted'],
        $data['products_reassigned']
      ) );
    }

    \WP_CLI::success( 'Done.' );
  }
}
