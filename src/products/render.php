<?php
use CBSNorthStar\Helpers\SiteScope;
use CBSNorthStar\Repositories\DaypartMenusRepository;

// Resolve the active site through SiteScope so the block attribute is validated
// against cbs_site_details before it influences menu resolution: an unknown or
// disabled siteId resolves to '' (fail-closed) instead of being trusted as-is.
// Falls back to the block's siteId attribute when the cookie is absent, so the
// menu can resolve its site and the products REST call never runs unfiltered
// (OE-26387).
$resolvedSiteId = SiteScope::resolveActiveSiteId(null, $attributes['siteId'] ?? '');
$siteId = '' !== $resolvedSiteId ? $resolvedSiteId : null;
$activeMenu = null;
$catalogCacheVersion = (string) get_option('cbs_catalog_cache_version', '0');
if ($siteId) {
    [$overrideTime, $overrideDay] = function_exists('oloNavSlotOverrides') ? oloNavSlotOverrides() : [null, null];
    $activeMenu = DaypartMenusRepository::create()->getActiveDaypartMenu($siteId, $overrideTime, $overrideDay);
    $storedMenu = isset($_COOKIE['currentMenu']) ? sanitize_text_field($_COOKIE['currentMenu']) : null;

    if (($storedMenu ?? '') !== ($activeMenu ?? '')) {
        if (!headers_sent()) {
            if ($activeMenu !== null) {
                setcookie('currentMenu', $activeMenu, time() + 86400, '/', '', true, true);
                $_COOKIE['currentMenu'] = $activeMenu;
            } else {
                setcookie('currentMenu', '', time() - 3600, '/', '', true, true);
                unset($_COOKIE['currentMenu']);
            }
        }
    }
}
?>
<div id="products-block-wrapper"
     data-numberofproducts="<?php echo esc_attr($attributes['lenght'] ?? 0) ?>"
     data-active-menu="<?php echo esc_attr($activeMenu ?? '') ?>"
     data-site-id="<?php echo esc_attr($siteId ?? '') ?>"
    data-catalog-version="<?php echo esc_attr($catalogCacheVersion) ?>"
     data-testid="menuitems-block-wrapper">
    <div class="search-container" data-testid="menuitems-search-container">
    <div class="search-box" id="search-box" data-testid="menuitems-search-box">
    </div>
    </div>
<div class="woocommerce columns-<?php echo esc_attr( wc_get_loop_prop( 'columns' ) ); ?>">
    <ul id="product-list" class="products columns-<?php echo esc_attr( wc_get_loop_prop( 'columns' ) ); ?>" data-testid="menuitems-product-list">
    </ul>
    <ul id="search-results" class="products columns-<?php echo esc_attr( wc_get_loop_prop( 'columns' ) ); ?>" data-testid="menuitems-search-results-list">
    </ul>
    <ul id="sub-category" class="products columns-<?php echo esc_attr( wc_get_loop_prop( 'columns' ) ); ?>" data-testid="menuitems-subcategory-list">
    </ul>
</div>

<div id="loading-more-container" class="loading-more-container" data-testid="menuitems-loading-container"></div>

<div class="overlay hidden" data-testid="menuitems-overlay">
    <div class="spinner" data-testid="menuitems-spinner"></div>
</div>
</div>
