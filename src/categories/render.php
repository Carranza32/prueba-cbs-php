<?php
use CBSNorthStar\Models\Categories;

$siteID = isset($_COOKIE["siteid"]) && $_COOKIE["siteid"] !== ""
    ? sanitize_text_field($_COOKIE["siteid"])
    : ($attributes["siteId"] ?? "");

// Fail open to the empty-state for every cause (no site selected, invalid/offline
// site, misconfigured plugin, or no daypart menu) so the kiosk shows one consistent
// message instead of a fatal/white screen or the raw site GUID.
try {
    $categories = (new Categories($siteID))->load();
} catch (\Throwable $e) {
    $categories = [];
}
$showEmptyState = empty($categories);

if (!isset($attributes['defaultimage']) || empty($attributes['defaultimage'])) {
    $attributes['defaultimage'] = plugin_dir_url(__FILE__) . '../../img/placeholder.png';
}

?>
  <div id="cbs-categories-block" class="cbs-categories-block" data-testid="categories-block-wrapper">
    <div class="categories <?php echo esc_attr($attributes["flexDirection"] ? "col" : "row"); ?>" data-testid="categories-block-list">
<?php
    if($showEmptyState) { ?>
    <div class="no-available-site-message" data-testid="categories-block-empty">
        <div class="no-menu-inner">
            <div class="no-menu-icon">
                <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M3 9l1.5-5h15L21 9"></path>
                    <path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V9z"></path>
                    <path d="M5 11v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8"></path>
                    <path d="M9 20v-5h6v5"></path>
                </svg>
            </div>
            <div class="no-menu-text"><?php echo esc_html__('No menu available', 'cbs-categories'); ?></div>
            <div class="no-menu-subtext"><?php echo esc_html__('Please try again in a moment or ask a team member for help.', 'cbs-categories'); ?></div>
        </div>
    </div>
    <?php
    }
    else {
        foreach($categories as $category){
            $categoryNameEncoded = urlencode($category->name);
            $activeClass = $category->name == urldecode((sanitize_text_field($_GET['cat_name'] ?? ''))) ? "active" : "";
            $url = get_permalink() . "?cat_slug=" . $category->slug . "&cat_name=". $categoryNameEncoded;
            ?>
            <div class='category-list-item <?php echo $activeClass; ?>' data-categoryslug="<?php echo esc_attr($category->slug); ?>" data-testid="categories-block-item" data-testid-category="categories-block-item-<?php echo esc_attr($category->slug); ?>">
                <a class='category-link' href="<?php echo esc_url($url); ?>" data-testid="categories-block-link">
                    <img src="<?php echo esc_url($category->thumbnail_id ? wp_get_attachment_url($category->thumbnail_id) : $attributes["defaultimage"]); ?>" alt="<?php echo esc_attr($category->name); ?>" data-testid="categories-block-img">
                    <span data-testid="categories-block-name"><?php echo esc_html($category->name); ?></span>
                </a>
            </div>
        <?php
        }
    }
?>
    </div>
 </div>
