<?php

namespace CBSNorthStar\Cli;

use CBSNorthStar\Migrations\FixCategoryIdDuplicates;

/**
 * WP-CLI command: wp northstar fix-category-id-duplicates
 *
 * Finds and removes duplicate menu_item_category_id term meta rows on
 * product_cat terms. Flags terms that hold two genuinely different IDs
 * for operator review.
 */
class FixCategoryIdDuplicatesCommand
{
  /**
   * Remove duplicate menu_item_category_id rows from product_cat terms.
   *
   * Same-value duplicates are deleted automatically. Terms with two or more
   * distinct values are flagged in the log for manual review — this command
   * does not auto-resolve which ID is correct.
   *
   * ## OPTIONS
   *
   * [--dry-run]
   * : Print what would change without writing to the database.
   *
   * ## EXAMPLES
   *
   *     wp northstar fix-category-id-duplicates
   *     wp northstar fix-category-id-duplicates --dry-run
   *
   * @when after_wp_load
   *
   * @param array $args       Positional arguments (unused).
   * @param array $assocArgs  Associative arguments.
   */
  public function __invoke( array $args, array $assocArgs ): void {
    $dryRun = isset( $assocArgs['dry-run'] );

    if ( $dryRun ) {
      \WP_CLI::log( '[dry-run] No database changes will be made.' );
    }

    \WP_CLI::log( 'Scanning product_cat terms for duplicate menu_item_category_id rows...' );

    $result = FixCategoryIdDuplicates::run( $dryRun );

    \WP_CLI::log( '' );
    \WP_CLI::log( '--- Summary ---' );
    \WP_CLI::log( sprintf( 'Already clean (no duplicates):          %d', $result->alreadyClean ) );
    \WP_CLI::log( sprintf( 'Duplicate same-value rows removed:      %d', $result->cleaned ) );
    \WP_CLI::log( sprintf( 'Terms flagged (conflicting distinct IDs):%d', $result->flagged ) );

    if ( $dryRun && ( $result->cleaned > 0 || $result->flagged > 0 ) ) {
      \WP_CLI::log( '' );
      \WP_CLI::log( 'Re-run without --dry-run to apply changes.' );
    }

    if ( $result->flagged > 0 ) {
      \WP_CLI::warning( sprintf(
        '%d term(s) have conflicting distinct menu_item_category_id values. Check the log for details and resolve manually.',
        $result->flagged
      ) );
      \WP_CLI::halt( 1 );
    }

    \WP_CLI::success( 'Done.' );
  }
}
