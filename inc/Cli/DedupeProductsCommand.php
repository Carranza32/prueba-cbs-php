<?php

namespace CBSNorthStar\Cli;

use CBSNorthStar\Logger\CBSLogger;

/**
 * WP-CLI command: wp northstar dedupe-products
 *
 * Finds product posts that share the same `_itemid` meta — the canonical
 * menu-item identity used by the deploy — and trashes the redundant ones,
 * keeping a single canonical post per item id. This cleans up duplicates that
 * predate the OE-26396 deploy fix; the deploy itself no longer creates them.
 *
 * Same `_itemid` on more than one product post is always a duplicate: a menu
 * item shared across sites lives on ONE post carrying multiple `_siteid` values,
 * and MenuItemId GUIDs do not collide across ECM instances — so deduping by
 * `_itemid` never merges two genuinely distinct products.
 *
 * Dry-run by default; pass --apply to actually trash. Posts are trashed, never
 * hard-deleted, so the operation is recoverable.
 */
class DedupeProductsCommand
{
  /**
   * Trash redundant product posts that share an `_itemid`, keeping one canonical post per item.
   *
   * ## OPTIONS
   *
   * [--apply]
   * : Actually trash the redundant duplicates. Without this flag the command only
   *   prints the plan and changes nothing (dry-run).
   *
   * ## EXAMPLES
   *
   *     wp northstar dedupe-products            # dry-run — list duplicates only
   *     wp northstar dedupe-products --apply    # trash the redundant duplicates
   *
   * @when after_wp_load
   *
   * @param array $args      Positional arguments (unused).
   * @param array $assocArgs Associative arguments.
   */
  public function __invoke( array $args, array $assocArgs ): void
  {
    global $wpdb;
    $apply  = isset( $assocArgs['apply'] );
    $prefix = $apply ? '' : '[DRY-RUN] ';

    // Item ids carried by more than one product post (publish or trash).
    $itemIds = $wpdb->get_col(
      "SELECT pm.meta_value
         FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_itemid'
          AND pm.meta_value <> ''
          AND p.post_type = 'product'
          AND p.post_status IN ('publish','trash')
        GROUP BY pm.meta_value
       HAVING COUNT(DISTINCT p.ID) > 1"
    );

    if ( empty( $itemIds ) ) {
      \WP_CLI::success( 'No duplicate products found.' );
      return;
    }

    \WP_CLI::log( sprintf( '%s%d item id(s) have duplicate products.', $prefix, count( $itemIds ) ) );

    $trashedTotal = 0;

    foreach ( $itemIds as $itemId ) {
      // Canonical keeper first: prefer published over trashed, then lowest ID.
      $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID, p.post_status, p.post_title
           FROM {$wpdb->posts} p
          INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
          WHERE pm.meta_key = '_itemid'
            AND pm.meta_value = %s
            AND p.post_type = 'product'
            AND p.post_status IN ('publish','trash')
          ORDER BY ( p.post_status = 'publish' ) DESC, p.ID ASC",
        $itemId
      ) );

      if ( count( $rows ) < 2 ) {
        continue; // race: resolved between the two queries
      }

      $keeper       = array_shift( $rows );
      $redundantIds = array_map( static fn( $r ) => (int) $r->ID, $rows );

      \WP_CLI::log( sprintf(
        '  item %s: keep #%d (%s "%s"), redundant %s',
        $itemId,
        (int) $keeper->ID,
        $keeper->post_status,
        $keeper->post_title,
        implode( ', ', array_map( static fn( $id ) => '#' . $id, $redundantIds ) )
      ) );

      if ( ! $apply ) {
        continue;
      }

      foreach ( $rows as $row ) {
        $id = (int) $row->ID;

        // Already-trashed redundant duplicates are not re-trashed, but their
        // identity still must be detached below: a trashed post that keeps a live
        // _itemid is a re-publish candidate (prefetchProductMap matches across
        // publish+trash and the deploy update path re-sets post_status). Still-
        // published duplicates are trashed first.
        if ( $row->post_status !== 'trash' && ! wp_trash_post( $id ) ) {
          \WP_CLI::warning( sprintf( '    failed to trash #%d', $id ) );
          continue;
        }

        // Detach the canonical identity so a later deploy cannot re-match and
        // re-publish this duplicate. Renamed, not deleted, for audit/restore.
        $detached = $wpdb->update(
          $wpdb->postmeta,
          [ 'meta_key' => '_itemid_deduped' ],
          [ 'post_id' => $id, 'meta_key' => '_itemid' ]
        );

        if ( $detached === false ) {
          \WP_CLI::warning( sprintf( '    failed to detach _itemid from #%d', $id ) );
          continue;
        }

        clean_post_cache( $id );
        $trashedTotal++;
        CBSLogger::general()->info( 'dedupe-products: cleaned duplicate product', [
          'item_id'    => $itemId,
          'trashed_id' => $id,
          'kept_id'    => (int) $keeper->ID,
        ] );
      }
    }

    if ( $apply ) {
      \WP_CLI::success( sprintf( 'Done — cleaned %d duplicate product(s).', $trashedTotal ) );
      return;
    }

    \WP_CLI::log( 'Dry-run complete. Re-run with --apply to trash the redundant duplicates.' );
  }
}
