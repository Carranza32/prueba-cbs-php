<?php

namespace CBSNorthStar\Migrations;

use CBSNorthStar\Logger\CBSLogger;

/**
 * One-time (re-runnable) migration that enforces one menu_item_category_id row
 * per product_cat term.
 *
 * Two classes of problem are handled:
 *
 *  - Same-value duplicates: the same UUID appears more than once on a term.
 *    Repair: delete all rows for that key, add back exactly one.
 *
 *  - Different-value conflicts: a term holds two or more distinct UUIDs.
 *    This migration cannot determine which is correct without API context.
 *    Repair: remove same-value duplicates, then flag for the next sync to resolve.
 *    The sync's enforce-exactly-one logic will delete the wrong value automatically.
 */
class FixCategoryIdDuplicates
{
  /**
   * Run the migration.
   *
   * @param bool $dryRun When true, log what would change without writing.
   * @return object {cleaned: int, flagged: int, alreadyClean: int}
   */
  public static function run( bool $dryRun = false ): object {
    $cleaned      = 0;
    $flagged      = 0;
    $alreadyClean = 0;

    $terms = get_terms( array(
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
      'fields'     => 'all',
    ) );

    if ( is_wp_error( $terms ) ) {
      CBSLogger::products()->error( 'FixCategoryIdDuplicates: get_terms() failed', array(
        'error_code'    => $terms->get_error_code(),
        'error_message' => $terms->get_error_message(),
      ) );
      return (object) compact( 'cleaned', 'flagged', 'alreadyClean' );
    }

    if ( ! is_array( $terms ) || empty( $terms ) ) {
      CBSLogger::products()->info( 'FixCategoryIdDuplicates: no product_cat terms found.' );
      return (object) compact( 'cleaned', 'flagged', 'alreadyClean' );
    }

    foreach ( $terms as $term ) {
      $termId = (int) $term->term_id;
      $rows   = get_term_meta( $termId, 'menu_item_category_id', false );

      if ( empty( $rows ) ) {
        $alreadyClean++;
        continue;
      }

      // Already exactly one row — nothing to do.
      if ( count( $rows ) === 1 ) {
        $alreadyClean++;
        continue;
      }

      // Count distinct values.
      $distinct = array_values( array_unique( array_map( 'strval', $rows ) ) );

      if ( count( $distinct ) === 1 ) {
        // Pure same-value duplicates: [X, X, X] → [X]
        $dupeValue   = $distinct[0];
        $dupeCount   = count( $rows ) - 1;
        CBSLogger::products()->info( 'FixCategoryIdDuplicates: removing same-value duplicates', array(
          'term_id'    => $termId,
          'term_name'  => $term->name,
          'value'      => $dupeValue,
          'rows_before'=> count( $rows ),
          'dry_run'    => $dryRun,
        ) );

        if ( ! $dryRun ) {
          // delete_term_meta with a value removes ALL rows for that key+value.
          // Re-add one to restore the single correct row.
          delete_term_meta( $termId, 'menu_item_category_id', $dupeValue );
          add_term_meta( $termId, 'menu_item_category_id', $dupeValue, false );
        }

        $cleaned += $dupeCount;

      } else {
        // Mixed: multiple distinct values, e.g. [X, Y] or [X, Y, Y].
        // Remove same-value duplicates within the set so the term has one row
        // per distinct value — the next deploy's enforce-exactly-one logic will
        // then delete whichever value the API no longer recognises.
        $extraRows = count( $rows ) - count( $distinct );
        if ( $extraRows > 0 ) {
          CBSLogger::products()->info( 'FixCategoryIdDuplicates: removing intra-set duplicates before flagging', array(
            'term_id'    => $termId,
            'term_name'  => $term->name,
            'rows_before'=> count( $rows ),
            'distinct'   => $distinct,
            'dry_run'    => $dryRun,
          ) );

          if ( ! $dryRun ) {
            // Rebuild: delete all rows, re-add one per distinct value.
            delete_term_meta( $termId, 'menu_item_category_id' );
            foreach ( $distinct as $val ) {
              add_term_meta( $termId, 'menu_item_category_id', $val, false );
            }
          }

          $cleaned += $extraRows;
        }

        CBSLogger::products()->warning(
          'FixCategoryIdDuplicates: term has conflicting distinct menu_item_category_id values — will self-heal on next sync',
          array(
            'term_id'         => $termId,
            'term_name'       => $term->name,
            'conflicting_ids' => $distinct,
          )
        );
        $flagged++;
      }
    }

    CBSLogger::products()->info( 'FixCategoryIdDuplicates: complete', array(
      'cleaned'       => $cleaned,
      'flagged'       => $flagged,
      'already_clean' => $alreadyClean,
      'dry_run'       => $dryRun,
    ) );

    return (object) compact( 'cleaned', 'flagged', 'alreadyClean' );
  }
}
