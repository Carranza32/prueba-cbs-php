<?php

namespace CBSNorthStar\Migrations;

use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Repositories\DeployRunRepository;
use CBSNorthStar\Services\CacheService;

/**
 * One-time (re-runnable) migration that consolidates duplicate product_cat terms
 * that share the same menu_item_category_id term meta on a given site.
 *
 * Duplicates accumulate on sites deployed before the ID-based category lookup was
 * introduced: name-based matching created a new term each time a category was
 * renamed in WOAPI. This class finds those duplicates, re-assigns all products to
 * the canonical term (highest product count; lowest term_id as tiebreaker), then
 * deletes the orphaned terms.
 */
class FixOrphanCategories
{
  /** @var array Summary counters for the most recent run(). */
  private array $summary = [];

  /**
   * Run the migration.
   *
   * @param int|null $site_id  Limit to a specific site. Pass null to run for all sites.
   * @return array             Summary keyed by site_id.
   */
  public function run( ?int $site_id = null ): array
  {
    $this->summary = [];

    // Global deploy-lock guard: abort entirely if any deploy is in progress.
    $activeRun = DeployRunRepository::create()->findActiveRun();
    if ( $activeRun !== null ) {
      CBSLogger::products()->warning( 'FixOrphanCategories: aborting — a deploy is currently running', array(
        'run_id' => $activeRun['id'] ?? 'unknown',
      ) );
      return $this->summary;
    }

    $siteIds = ( $site_id !== null )
      ? array( $site_id )
      : $this->getAllSiteIds();

    foreach ( $siteIds as $sid ) {
      $this->summary[ $sid ] = $this->processSite( (int) $sid );
    }

    $anyChanges = false;
    foreach ( $this->summary as $siteSummary ) {
      if ( ( $siteSummary['terms_deleted'] ?? 0 ) > 0 || ( $siteSummary['products_reassigned'] ?? 0 ) > 0 ) {
        $anyChanges = true;
        break;
      }
    }
    if ( $anyChanges ) {
      CacheService::clearProductCache();
    }

    return $this->summary;
  }

  /**
   * Process one site: find duplicate term groups, consolidate, delete orphans.
   *
   * Groups are merged into connected components before any deletions so that a
   * term elected canonical in one group cannot be deleted in another. Products
   * are migrated and wp_delete_term called only after resolveCanonical() has
   * run for the full component.
   */
  private function processSite( int $siteId ): array
  {
    $duplicateGroups = $this->findDuplicates( $siteId );

    $termsDeleted       = 0;
    $productsReassigned = 0;
    $deletedTermIds     = [];

    if ( ! empty( $duplicateGroups ) ) {
      // Build a flat term_id → WP_Term lookup from every group.
      $termById = [];
      foreach ( $duplicateGroups as $terms ) {
        foreach ( $terms as $term ) {
          $termById[ $term->term_id ] = $term;
        }
      }

      // Convert each group to a set of term_ids, then merge overlapping sets into
      // connected components. Two groups that share a term become one component so
      // resolveCanonical() picks a single winner for all of them before anything
      // is deleted.
      $sets = [];
      foreach ( $duplicateGroups as $terms ) {
        $ids = [];
        foreach ( $terms as $term ) {
          $ids[] = $term->term_id;
        }
        $sets[] = $ids;
      }
      $components = $this->mergeConnectedComponents( $sets );

      foreach ( $components as $componentTermIds ) {
        $componentTerms = [];
        foreach ( $componentTermIds as $id ) {
          if ( isset( $termById[ $id ] ) ) {
            $componentTerms[] = $termById[ $id ];
          }
        }
        if ( empty( $componentTerms ) ) {
          continue;
        }

        // Canonical is elected once for the whole component — safe to delete
        // all other members now that no group can re-elect a different winner.
        $canonical = $this->resolveCanonical( $componentTerms );

        foreach ( $componentTerms as $term ) {
          if ( $term->term_id === $canonical->term_id ) {
            continue;
          }
          if ( isset( $deletedTermIds[ $term->term_id ] ) ) {
            continue;
          }
          $productsReassigned += $this->consolidateProducts( $term->term_id, $canonical->term_id );
          $deleteResult = wp_delete_term( $term->term_id, 'product_cat' );
          if ( ! is_wp_error( $deleteResult ) && $deleteResult !== false ) {
            $termsDeleted++;
            $deletedTermIds[ $term->term_id ] = true;
            CBSLogger::products()->info( 'FixOrphanCategories: deleted orphan term', array(
              'orphan_term_id'    => $term->term_id,
              'canonical_term_id' => $canonical->term_id,
              'site_id'           => $siteId,
            ) );
          } else {
            CBSLogger::products()->warning( 'FixOrphanCategories: wp_delete_term failed', array(
              'orphan_term_id' => $term->term_id,
              'error'          => is_wp_error( $deleteResult ) ? $deleteResult->get_error_message() : 'term not found',
            ) );
          }
        }
      }
    }

    CBSLogger::products()->info( 'FixOrphanCategories: site complete', array(
      'site_id'            => $siteId,
      'duplicate_groups'   => count( $duplicateGroups ),
      'terms_deleted'      => $termsDeleted,
      'products_reassigned'=> $productsReassigned,
    ) );

    return array(
      'duplicate_groups'    => count( $duplicateGroups ),
      'terms_deleted'       => $termsDeleted,
      'products_reassigned' => $productsReassigned,
    );
  }

  /**
   * Merge overlapping sets of term_ids into connected components.
   *
   * Two sets are merged when they share at least one element. The outer loop
   * repeats until a full pass produces no new merges, which handles chains where
   * sets A and B are disjoint but both overlap with C added in a later iteration.
   *
   * @param array[] $sets  Each element is an array of integer term_ids.
   * @return array[]
   */
  private function mergeConnectedComponents( array $sets ): array
  {
    $changed = true;
    while ( $changed ) {
      $changed = false;
      $merged  = [];
      while ( ! empty( $sets ) ) {
        $current = array_shift( $sets );
        $placed  = false;
        foreach ( $merged as &$existing ) {
          if ( ! empty( array_intersect( $current, $existing ) ) ) {
            $existing = array_values( array_unique( array_merge( $existing, $current ) ) );
            $placed   = true;
            $changed  = true;
            break;
          }
        }
        unset( $existing );
        if ( ! $placed ) {
          $merged[] = $current;
        }
      }
      $sets = $merged;
    }
    return $sets;
  }

  /**
   * Find all product_cat terms for the site that share the same menu_item_category_id.
   * Returns an array keyed by the external category ID, each value an array of WP_Term.
   *
   * @param int $siteId
   * @return array<string, \WP_Term[]>
   */
  private function findDuplicates( int $siteId ): array
  {
    $terms = get_terms( array(
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
      'meta_query' => array(
        array( 'key' => 'site_id', 'value' => $siteId ),
      ),
    ) );

    if ( ! is_array( $terms ) || empty( $terms ) ) {
      return array();
    }

    // Group terms by their menu_item_category_id meta value.
    $groups = array();
    foreach ( $terms as $term ) {
      $extIds = get_term_meta( $term->term_id, 'menu_item_category_id', false );
      foreach ( $extIds as $extId ) {
        $groups[ $extId ][] = $term;
      }
    }

    // Keep only groups that have more than one term (actual duplicates).
    return array_filter( $groups, static function ( array $group ): bool {
      return count( $group ) > 1;
    } );
  }

  /**
   * Select the canonical term from a group of duplicates.
   * Preference: highest product count; tiebreaker: lowest term_id.
   *
   * @param \WP_Term[] $terms
   * @return \WP_Term
   */
  private function resolveCanonical( array $terms ): \WP_Term
  {
    usort( $terms, static function ( \WP_Term $a, \WP_Term $b ): int {
      $countA = (int) $a->count;
      $countB = (int) $b->count;
      if ( $countA !== $countB ) {
        return $countB - $countA; // higher count first
      }
      return $a->term_id - $b->term_id; // lower term_id first
    } );

    return $terms[0];
  }

  /**
   * Re-assign all products from $fromTermId to $toTermId under product_cat.
   *
   * @param int $fromTermId
   * @param int $toTermId
   * @return int  Number of products reassigned.
   */
  private function consolidateProducts( int $fromTermId, int $toTermId ): int
  {
    $productIds = get_objects_in_term( $fromTermId, 'product_cat' );
    if ( is_wp_error( $productIds ) || empty( $productIds ) ) {
      return 0;
    }

    $count = 0;
    foreach ( $productIds as $productId ) {
      wp_remove_object_terms( (int) $productId, $fromTermId, 'product_cat' );
      wp_add_object_terms( (int) $productId, $toTermId, 'product_cat' );
      $count++;
    }

    return $count;
  }

  /**
   * Collect all site IDs that have product_cat terms with menu_item_category_id meta.
   *
   * @return int[]
   */
  private function getAllSiteIds(): array
  {
    global $wpdb;
    $rows = $wpdb->get_col(
      "SELECT DISTINCT tm.meta_value
       FROM {$wpdb->termmeta} tm
       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
       WHERE tt.taxonomy = 'product_cat'
         AND tm.meta_key = 'site_id'"
    );

    $ids = [];
    foreach ( $rows as $row ) {
      $id = intval( $row );
      if ( $id > 0 ) {
        $ids[] = $id;
      } else {
        CBSLogger::products()->warning( 'FixOrphanCategories: skipping non-numeric site_id meta value', array(
          'raw_value' => $row,
        ) );
      }
    }
    return $ids;
  }
}
