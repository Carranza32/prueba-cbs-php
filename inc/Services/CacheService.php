<?php

namespace CBSNorthStar\Services;

class CacheService
{
  /**
   * Clear WooCommerce product and term caches.
   *
   * Deletes WC-specific transients from the options table, rebuilds lookup
   * tables, then invalidates only WC product/term object-cache groups via
   * WC_Cache_Helper rather than calling wp_cache_flush() (which would wipe
   * sessions, user-meta, and all unrelated data).
   */
  public static function clearProductCache(): void
  {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wc\_product\_%' OR option_name LIKE '\_transient\_timeout\_wc\_product\_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wc\_term\_%'   OR option_name LIKE '\_transient\_timeout\_wc\_term\_%'" );
    wc_update_product_lookup_tables();
    // Bump WC version keys — all versioned product/term object-cache entries that
    // depend on these keys become stale without touching unrelated cache groups.
    \WC_Cache_Helper::get_transient_version( 'product', true );
    \WC_Cache_Helper::get_transient_version( 'term_meta', true );
    // Evict term object-cache entries for product_cat specifically.
    clean_taxonomy_cache( 'product_cat' );
  }
}
