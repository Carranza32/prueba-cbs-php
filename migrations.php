<?php

/**
 * Database migration runner.
 *
 * Called on every page load via the `plugins_loaded` hook.
 * Compares the stored DB version against CBS_DB_VERSION (which equals the
 * build number from build.txt) and runs any pending migrations, then updates
 * the stored version.
 *
 * HOW TO ADD A SCHEMA CHANGE
 * --------------------------
 * 1. Update the relevant CREATE TABLE statement in create_tables.php
 *    (dbDelta will add missing columns to existing tables automatically).
 * 2. Run the pipeline with a new build number — no manual constant bump needed.
 * 3. If you need to do something beyond adding a column — rename/drop a
 *    column, backfill data, change a column type — add a version_compare
 *    block below inside cbs_run_db_migrations(), guarded by the build number.
 *
 * Example for a change shipped in build 1.0.10:
 *
 *   if ( version_compare( $installed_version, '1.0.10', '<' ) ) {
 *       $wpdb->query( "ALTER TABLE cbs_site_details ADD COLUMN new_col VARCHAR(255) DEFAULT NULL" );
 *   }
 */

function cbs_run_db_migrations() {
    $installed_version = get_option( 'cbs_db_version', '0.0.0' );

    if ( $installed_version === CBS_DB_VERSION ) {
        return; // Already up to date.
    }

    // Prevent concurrent migrations.
    $lock_key = 'cbs_db_migration_lock';
    if ( get_transient( $lock_key ) ) {
        return;
    }
    set_transient( $lock_key, true, 60 );

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Always (re-)run the base table creation.
    // dbDelta is safe to run on existing tables: it creates missing tables
    // and adds missing columns, but never removes or modifies existing ones.
   require_once __DIR__ . '/create_tables.php';

    // -----------------------------------------------------------------------
    // Version-specific migrations — add blocks here as the schema evolves.
    // Each block must be guarded with version_compare so it only runs once.
    // -----------------------------------------------------------------------

    // Example (uncomment and adapt for a future build):
    // if ( version_compare( $installed_version, '1.0.10', '<' ) ) {
    //     $wpdb->query( "ALTER TABLE cbs_site_details ADD COLUMN new_column VARCHAR(255) DEFAULT NULL" );
    // }

    // Enforce one daypart-menu row per (siteid, menuid, daypartid). The deploy
    // upsert (DaypartMenusRepository::updateMenu) keys on these three columns;
    // a UNIQUE constraint guarantees that contract at the DB level and stops
    // concurrent deploys from creating duplicate rows. Self-guarding via an
    // index-existence check so it is safe to run on every migration pass.
    if ( ! cbs_migrate_daypartmenus_unique_key( $wpdb ) ) {
        return;
    }

    // One-time cleanup of duplicate products (OE-26396). Idempotent and
    // self-guarding via the cbs_dedupe_products_done option, so it trashes the
    // redundant duplicates exactly once per site as this build propagates across
    // the fleet — no per-site SSH/WP-CLI required. A failure logs and returns
    // false, which blocks the cbs_db_version bump below so the cleanup retries on
    // the next migration pass (throttled to ~once/60s by the migration lock).
    if ( ! cbs_migrate_dedupe_duplicate_products( $wpdb ) ) {
        return;
    }

    // -----------------------------------------------------------------------

    update_option( 'cbs_db_version', CBS_DB_VERSION );
    delete_transient( $lock_key );
}

/**
 * One-time, idempotent data migration: trash duplicate product posts that share
 * the same `_itemid` (the canonical menu-item identity), keeping one canonical
 * post per item id. Cleans up duplicates created before the OE-26396 deploy fix.
 *
 * Same `_itemid` on more than one product post is always a duplicate: a menu
 * item shared across sites lives on ONE post carrying multiple `_siteid` values,
 * and MenuItemId GUIDs do not collide across ECM instances.
 *
 * Raw `$wpdb` is used (option A) so the cleanup is cheap enough to run inside the
 * `plugins_loaded` migration pass without firing WooCommerce trash hooks.
 * Currently-`publish` non-canonical rows are flipped to `trash`; the
 * `_wp_trash_meta_*` stamps keep that recoverable from Products → Trash. EVERY
 * non-canonical duplicate (newly trashed AND already-trashed) then has its
 * `_itemid` renamed to `_itemid_deduped`, so no later deploy can re-match and
 * re-publish a trashed duplicate. Self-guards on the cbs_dedupe_products_done
 * option so it runs at most once per site.
 *
 * @param \wpdb $wpdb
 * @return bool True on success (or already-done / nothing to clean); false on a
 *              DB error, so the caller can block the version bump and retry.
 */
function cbs_migrate_dedupe_duplicate_products( $wpdb ) {
    if ( get_option( 'cbs_dedupe_products_done' ) ) {
        return true; // Already cleaned on this site.
    }

    // Every product post that is NOT the canonical keeper for its _itemid, across
    // BOTH 'publish' and 'trash'. Canonical = lowest published ID (else lowest ID
    // overall). We need the already-trashed non-canonical rows too, not just the
    // published ones we flip: a duplicate trashed by hand (e.g. support) keeps a
    // live _itemid, and prefetchProductMap() matches _itemid across publish+trash
    // while the deploy update path re-sets post_status — so such a row is still a
    // re-publish (resurrection) candidate until its identity is detached.
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_status
           FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->postmeta} pm
                   ON pm.post_id = p.ID AND pm.meta_key = '_itemid'
           INNER JOIN (
                 SELECT pm2.meta_value AS item_id,
                        MIN( CASE WHEN p2.post_status = 'publish' THEN p2.ID END ) AS keep_publish_id,
                        MIN( p2.ID ) AS keep_any_id
                   FROM {$wpdb->postmeta} pm2
                   INNER JOIN {$wpdb->posts} p2 ON p2.ID = pm2.post_id
                  WHERE pm2.meta_key   = '_itemid'
                    AND pm2.meta_value <> ''
                    AND p2.post_type   = 'product'
                    AND p2.post_status IN ('publish','trash')
                  GROUP BY pm2.meta_value
                 HAVING COUNT(DISTINCT p2.ID) > 1
               ) g ON g.item_id = pm.meta_value
          WHERE p.post_type  = 'product'
            AND p.post_status IN ('publish','trash')
            AND p.ID <> COALESCE( g.keep_publish_id, g.keep_any_id )"
    );

    if ( $wpdb->last_error ) {
        error_log( 'cbs_migrate_dedupe_duplicate_products: lookup failed — ' . $wpdb->last_error );
        return false; // block the version bump so it retries on the next migration pass
    }

    // Partition: $idsToDetach = all non-canonical duplicates (identity must be
    // renamed); $idsToTrash = the still-published subset we also flip to trash.
    $idsToDetach = [];
    $idsToTrash  = [];
    foreach ( (array) $rows as $row ) {
        $id = (int) $row->ID;
        if ( $id <= 0 ) {
            continue;
        }
        $idsToDetach[] = $id;
        if ( $row->post_status === 'publish' ) {
            $idsToTrash[] = $id;
        }
    }

    if ( empty( $idsToDetach ) ) {
        update_option( 'cbs_dedupe_products_done', 1, false );
        return true; // nothing to clean
    }

    $now = time();

    // Flip the still-published duplicates to trash (recoverable from Products → Trash).
    if ( ! empty( $idsToTrash ) ) {
        $trashPlaceholders = implode( ',', array_fill( 0, count( $idsToTrash ), '%d' ) );
        $updated           = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_status = 'trash' WHERE ID IN ($trashPlaceholders)",
                ...$idsToTrash
            )
        );

        if ( $updated === false ) {
            error_log( 'cbs_migrate_dedupe_duplicate_products: trash update failed — ' . $wpdb->last_error );
            return false; // block the version bump so it retries on the next migration pass
        }
    }

    // Detach the canonical identity from EVERY non-canonical duplicate (newly
    // trashed AND already-trashed), so no later deploy can re-match and re-PUBLISH
    // a trashed copy. Renaming the key (not deleting it) makes the post invisible
    // to all _itemid lookups while preserving the value for audit/restore. The
    // result is checked: if this fails, a trashed dup keeps a live _itemid, so we
    // must NOT mark the migration done — block the bump and retry next pass.
    $detachPlaceholders = implode( ',', array_fill( 0, count( $idsToDetach ), '%d' ) );
    $renamed            = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_key = '_itemid_deduped'
              WHERE meta_key = '_itemid' AND post_id IN ($detachPlaceholders)",
            ...$idsToDetach
        )
    );

    if ( $renamed === false ) {
        error_log( 'cbs_migrate_dedupe_duplicate_products: identity detach failed — ' . $wpdb->last_error );
        return false; // block the version bump so it retries on the next migration pass
    }

    // Stamp the trash meta WP uses for restore on the rows we just trashed, and
    // drop stale caches for every post we touched.
    foreach ( $idsToTrash as $id ) {
        update_post_meta( $id, '_wp_trash_meta_status', 'publish' );
        update_post_meta( $id, '_wp_trash_meta_time', $now );
    }
    foreach ( $idsToDetach as $id ) {
        clean_post_cache( $id );
    }

    update_option( 'cbs_dedupe_products_done', 1, false );

    error_log( sprintf(
        'cbs_migrate_dedupe_duplicate_products: trashed %d, detached %d duplicate product(s): %s',
        count( $idsToTrash ),
        count( $idsToDetach ),
        implode( ',', $idsToDetach )
    ) );

    return true;
}

/**
 * One-time, idempotent migration: de-duplicate cbs_daypartmenus and add a
 * UNIQUE key on (siteid, menuid, daypartid).
 *
 * siteid is declared LONGTEXT, so the index uses column prefixes (150 chars —
 * comfortably longer than the UUIDs stored there) to stay within InnoDB's
 * index-length limit. Dedup must happen before the ALTER or it fails on
 * existing duplicates.
 *
 * @param \wpdb $wpdb
 * @return bool True when the index exists or was successfully created, false on failure.
 */
function cbs_migrate_daypartmenus_unique_key( $wpdb ) {
    $index_name = 'uniq_site_menu_daypart';

    $existing = $wpdb->get_results(
        "SHOW INDEX FROM cbs_daypartmenus WHERE Key_name = '{$index_name}'"
    );

    if ( ! empty( $existing ) ) {
        return true; // Already applied.
    }

    // Remove duplicate rows, keeping the lowest id for each natural key.
    $dedup = $wpdb->query(
        "DELETE t1 FROM cbs_daypartmenus t1
         INNER JOIN cbs_daypartmenus t2
            ON t1.siteid    = t2.siteid
           AND t1.menuid    = t2.menuid
           AND t1.daypartid = t2.daypartid
           AND t1.id        > t2.id"
    );

    if ( $dedup === false ) {
        error_log( 'cbs_migrate_daypartmenus_unique_key: failed to deduplicate rows — ' . $wpdb->last_error );
        return false;
    }

    $result = $wpdb->query(
        "ALTER TABLE cbs_daypartmenus
            ADD UNIQUE KEY {$index_name} (siteid(150), menuid(150), daypartid(150))"
    );

    if ( $result === false ) {
        error_log( 'cbs_migrate_daypartmenus_unique_key: failed to add unique key — ' . $wpdb->last_error );
        return false;
    }

    return true;
}
