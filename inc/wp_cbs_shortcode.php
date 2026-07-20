<?php
/**
 * Add shortcodes
 *
 * Used to create various shortcodes in plugin
 *
 */

use CBSNorthStar\Order\Check;
use CBSNorthStar\Repositories\CartRecordRepository;
use CBSNorthStar\Dto\LocationDto;
use CBSNorthStar\Repositories\DaypartMenusRepository;
use CBSNorthStar\Set_sessions_for_site;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Services\TimeSlotService;
use CBSNorthStar\Helpers\TimeSlotReservationSession;

include(plugin_dir_path(__DIR__) . 'cbs_functions.php');
include(plugin_dir_path(__DIR__) . '/inc/Set_sessions_for_site.php');

/**
 * Parse nav timeslot cookies into override parameters for getActiveDaypartMenu().
 *
 * @return array [?string $overrideTime, ?string $overrideDay]
 */
function oloNavSlotOverrides(): array
{
    $slotTime = isset($_COOKIE['oloNavTimeslotTime']) ? sanitize_text_field($_COOKIE['oloNavTimeslotTime']) : null;
    $slotDate = isset($_COOKIE['oloNavTimeslotDate']) ? sanitize_text_field($_COOKIE['oloNavTimeslotDate']) : null;

    if (!$slotTime || !$slotDate) {
        return [null, null];
    }

    $overrideTime = null;
    $overrideDay  = null;

    $dt = DateTime::createFromFormat('Y-m-d', $slotDate);
    if ($dt) {
        $overrideDay = $dt->format('l');
    }

    // The CBS available-slots API returns each slotTime as the site's local
    // wall-clock time tagged with a +00:00 offset (e.g. "2026-06-15T09:18:00+00:00"
    // is 09:18 local, not 09:18 UTC). The slot picker shows that same literal
    // wall-clock via date_i18n, so the daypart resolver must match against it too.
    // date_parse reads the literal HH:MM:SS and ignores the offset; running the
    // string through a timezone would shift it away from the time the user actually
    // saw (e.g. to the WP-global zone) and select the wrong menu.
    $parsed = date_parse( $slotTime );
    if ( $parsed && $parsed['error_count'] === 0 ) {
        $overrideTime = sprintf( '%02d:%02d:%02d', $parsed['hour'], $parsed['minute'], $parsed['second'] );
    }

    return [$overrideTime, $overrideDay];
}

// Clear navigation cookies when on home page or locations page
add_action('template_redirect', 'clearNavigationCookiesOnHome');
function clearNavigationCookiesOnHome() {
    if (is_front_page() || is_home() || is_page('locations')) {
        if (isset($_COOKIE['last_category'])) {
            setcookie("last_category", "", time() - 3600, '/', "", is_ssl(), false);
            unset($_COOKIE['last_category']);
        }
        if (isset($_COOKIE['oloNavTimeslot'])) {
            setcookie('oloNavTimeslot',      '', time() - 3600, '/', '', is_ssl(), false);
            setcookie('oloNavTimeslotId',   '', time() - 3600, '/', '', is_ssl(), false);
            setcookie('oloNavTimeslotTime', '', time() - 3600, '/', '', is_ssl(), false);
            setcookie('oloNavTimeslotDate', '', time() - 3600, '/', '', is_ssl(), false);
            setcookie('oloNavAreaId',       '', time() - 3600, '/', '', is_ssl(), false);
        }
    }
}


// Redirect to /menu-items/ when ?cat_slug belongs to a different daypart menu
add_action('template_redirect', 'olo_guard_stale_category_slug');
function olo_guard_stale_category_slug() {
    if ( ! is_page('menu-items') || empty( $_GET['cat_slug'] ) ) {
        return;
    }

    $siteId       = \CBSNorthStar\Helpers\SiteScope::resolveActiveSiteId();
    $activeMenuId = \CBSNorthStar\Helpers\MenuScope::resolveActiveMenuId( $siteId );

    $catSlug = sanitize_title( wp_unslash( $_GET['cat_slug'] ) );
    $term    = get_term_by( 'slug', $catSlug, 'product_cat' );

    if ( ! $term || is_wp_error( $term ) || ! in_array( $activeMenuId, get_term_meta( $term->term_id, 'menu_id', false ), true ) ) {
        setcookie( 'categoryname', '', time() - 3600, '/', '', is_ssl(), false );
        setcookie( 'categoryslug', '', time() - 3600, '/', '', is_ssl(), false );
        setcookie( 'last_category', '', time() - 3600, '/', '', is_ssl(), false );
        if ( ! headers_sent() ) {
          header( 'X-CBS-Redirect-Reason: stale-category-slug' );
        }
        wp_safe_redirect( home_url( '/menu-items/' ) );
        exit;
    }
}

add_filter( 'woocommerce_shortcode_products_query', 'cbs_filter_shortcode_products_by_site', 10, 2 );
function cbs_filter_shortcode_products_by_site( $query_args, $atts ) {
    if ( ! is_page( 'menu-items' ) ) {
        return $query_args;
    }
    // Fail closed: an unresolved site/menu yields a never-match clause so the
    // shortcode renders no products instead of every site's or menu's
    // (OE-26387 / OE-26399). Appended as a nested AND group so it composes with
    // any meta_query WooCommerce already built for the shortcode.
    $siteId = \CBSNorthStar\Helpers\SiteScope::resolveActiveSiteId();
    $menuId = \CBSNorthStar\Helpers\MenuScope::resolveActiveMenuId( $siteId );
    $query_args['meta_query'][] = \CBSNorthStar\Helpers\ProductScope::metaQuery( $siteId, $menuId );
    return $query_args;
}

add_filter('woocommerce_shortcode_products_query', 'excludeChildCategoryProducts', 10, 2);
function excludeChildCategoryProducts($query_args, $atts) {
    if (!empty($atts['category'])) {
        $slugs = array_map('trim', explode(',', $atts['category']));
        $term_ids = [];
        foreach ($slugs as $s) {
            $t = get_term_by('slug', $s, 'product_cat');
            if ($t) $term_ids[] = (int) $t->term_id;
        }
        if (!empty($term_ids)) {
            if (!empty($query_args['tax_query']) && is_array($query_args['tax_query'])) {
                foreach ($query_args['tax_query'] as &$tax) {
                    if (isset($tax['taxonomy']) && $tax['taxonomy'] === 'product_cat') {
                        $tax['include_children'] = false;
                        $tax['terms'] = $term_ids;
                        $tax['field'] = 'term_id';
                    }
                }
                unset($tax);
            } else {
                $query_args['tax_query'][] = [
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $term_ids,
                    'include_children' => false,
                ];
            }
        }
    }
    return $query_args;
}

add_filter('woocommerce_loop_product_link', 'modifyProductPermalink', 10, 2);
function modifyProductPermalink($link, $product) {
    if (!function_exists('wc_get_loop_prop') || !wc_get_loop_prop('is_shortcode')) {
        return $link;
    }

    $linkedItems = get_post_meta($product->get_id(), '_link_to_category', true);
    $linktoCategoryData= json_decode($linkedItems, true) ?? [];

    if (empty($linktoCategoryData)) {
        return $link;
    }

    $custom_url = add_query_arg(
        array(
            'cat_slug' => $linktoCategoryData['slug'],
            'cat_name' => $linktoCategoryData['name'],
        ),
        home_url('/menu-items/')
    );

    return $custom_url;
}

add_filter('woocommerce_get_price_html', 'hidePriceForLinkToProducts', 10, 2);
function hidePriceForLinkToProducts($price_html, $product) {
    $linkedItems = get_post_meta($product->get_id(), '_link_to_category', true);
    $linktoCategoryData= json_decode($linkedItems, true) ?? [];
    
    if (!empty($linktoCategoryData)) {
        return '';
    }
    
    return $price_html;
}

add_action('init', 'start_session', 1);
  function start_session()
  {
    if (!session_id()) {
      session_start();
    }
  }

  function loadWindowsToIanaMapping($filePath) {
    $mapping = [];
    $xml = simplexml_load_file($filePath);

    foreach ($xml->xpath("//mapTimezones/mapZone") as $zone) {
        $windowsTimezone = (string) $zone['other'];
        $ianaTimezones = (string) $zone['type'];

        $firstIanaTimezone = explode(' ', $ianaTimezones)[0];

        $mapping[$windowsTimezone] = $firstIanaTimezone;
    }

    return $mapping;
}

function convertDotNetToPhpTimezone($dotNetTimezone, $mapping) {
  return $mapping[$dotNetTimezone] ?? null;
}

/**
 * Render categories nav markup from pre-fetched category rows.
 *
 * @param array $results Rows with ->term_id, ->name, ->slug.
 * @return string
 */
function cbs_render_categories_nav_from_results( array $results ): string {
  if ( empty( $results ) ) {
    return '';
  }

  $output = "<nav class='customlist' data-testid='categories-nav-list'>";
  $lastCategory = $_COOKIE['last_category'] ?? '';
  $lastCategory = clearLastCategoryIfNeeded( $lastCategory );
  $currentCategoryName = sanitize_text_field( wp_unslash( $_GET['cat_name'] ?? '' ) );

  foreach ( $results as $row ) {
    $category = get_term_by( 'slug', $row->slug, 'product_cat' );

    if ( ! $category || empty( $category->count ) ) {
      continue;
    }

    $categoryUrl = esc_url(
      add_query_arg(
        array(
          'cat_slug' => $row->slug,
          'cat_name' => $row->name,
        ),
        home_url( '/menu-items/' )
      )
    );
    $catdesc = isset( $category->description ) && ! empty( $category->description )
      ? wp_kses_post( $category->description )
      : '';
    $categoryName = esc_html( $row->name );
    $categorySlug = esc_attr( $row->slug );

    if ( is_page( 'categories' ) ) {
      $output .= "<a href='" . $categoryUrl . "' class='custom-list-item complete' data-testid='categories-card-link' data-testid-category='categories-card-item-" . $categorySlug . "'><div><h2 data-testid='categories-card-name'>" . $categoryName . "</h2>
            <p class='product-category-description' data-testid='categories-card-desc'>" . $catdesc . "</p></div></a>";
      continue;
    }

    if ( $row->name == $currentCategoryName || $row->name == $lastCategory ) {
      $output .= "<div class='custom-list-item ' data-testid='categories-nav-item' data-testid-category='categories-nav-item-" . $categorySlug . "'><a class='active' data-testid='categories-nav-link' href='" . $categoryUrl . "'>" . $categoryName . "</a>
            </div>";
      setcookie( 'last_category', $row->name, 0, '/', '', is_ssl(), false );
    } else {
      $output .= "<div class='custom-list-item' data-testid='categories-nav-item' data-testid-category='categories-nav-item-" . $categorySlug . "'><a data-testid='categories-nav-link' href='" . $categoryUrl . "'>" . $categoryName . "</a>
          </div>";
    }
  }

  $output .= '</nav>';
  return $output;
}

/**
 * Fetch top-level product categories scoped by site + menu.
 *
 * Uses taxonomy join + EXISTS termmeta checks to avoid large DISTINCT scans.
 * Memoized per request because menuitems/categories flows can request the same
 * scope more than once.
 *
 * @param string $siteId
 * @param string $menuId
 * @return array
 */
function cbs_get_top_level_categories_for_site_menu( string $siteId, string $menuId ): array {
  static $memo = array();

  $key = $siteId . '|' . $menuId;
  if ( array_key_exists( $key, $memo ) ) {
    return $memo[ $key ];
  }

  global $wpdb;

  $sql = $wpdb->prepare(
    "SELECT t.term_id, t.name, t.slug
      FROM {$wpdb->terms} t
      INNER JOIN {$wpdb->term_taxonomy} tt
        ON tt.term_id = t.term_id
        AND tt.taxonomy = %s
        AND tt.parent = 0
      WHERE EXISTS (
        SELECT 1
        FROM {$wpdb->termmeta} tm_site
        WHERE tm_site.term_id = t.term_id
          AND tm_site.meta_key = %s
          AND tm_site.meta_value = %s
      )
      AND EXISTS (
        SELECT 1
        FROM {$wpdb->termmeta} tm_menu
        WHERE tm_menu.term_id = t.term_id
          AND tm_menu.meta_key = %s
          AND tm_menu.meta_value = %s
      )
      ORDER BY t.name",
    'product_cat',
    'site_id',
    $siteId,
    'menu_id',
    $menuId
  );

  $rows = $wpdb->get_results( $sql );
  $memo[ $key ] = is_array( $rows ) ? $rows : array();

  return $memo[ $key ];
}

/**
 * Apply the configured category termmeta order to a category row list.
 *
 * @param array $results Rows with ->term_id, ->name, ->slug.
 * @return array
 */
function cbs_sort_categories_by_termmeta_order( array $results ): array {
  global $wpdb;

  if ( empty( $results ) ) {
    return $results;
  }

  $termIds = array_map(
    static function( $row ) {
      return (int) $row->term_id;
    },
    $results
  );

  $placeholders = implode( ',', array_fill( 0, count( $termIds ), '%d' ) );
  $sql = $wpdb->prepare(
    "SELECT term_id, CAST(meta_value AS UNSIGNED) AS sort_order
      FROM {$wpdb->termmeta}
      WHERE meta_key = 'order'
        AND term_id IN ($placeholders)
      ORDER BY CAST(meta_value AS UNSIGNED) ASC",
    $termIds
  );

  $orderData = $wpdb->get_results( $sql );
  if ( empty( $orderData ) ) {
    return $results;
  }

  $positions = array();
  foreach ( $orderData as $index => $row ) {
    $positions[ (int) $row->term_id ] = $index;
  }

  usort(
    $results,
    static function( $left, $right ) use ( $positions ) {
      $leftPosition = $positions[ (int) $left->term_id ] ?? PHP_INT_MAX;
      $rightPosition = $positions[ (int) $right->term_id ] ?? PHP_INT_MAX;

      if ( $leftPosition === $rightPosition ) {
        return strcmp( (string) $left->name, (string) $right->name );
      }

      return $leftPosition <=> $rightPosition;
    }
  );

  return $results;
}
  /**
   * Menu function
   *
   * Display all category of specific menu
   */

  function cbs_categories_func(){
    
    global $wpdb;
    global $woocommerce;
    $pf = $wpdb->prefix;
    $siteid_flag=0;
    $items_available = "";
    $structure = "";
    $siteId = "";
    $site_name = "";
    $table_num = "";
    $areaId = "";
    $site_token='';
    $get_instance_url = "";
    $output = "";
    $noMenu = false;


    $structure = new Set_sessions_for_site();
    $structure -> set_site_id();
    $siteId = $structure->get_site_id();
    $site_name = $structure->getSiteName($siteId);


    if($_GET['location']){
      $structure -> set_location();
      $table_num = $structure -> get_location();
      setcookie("table_num", $table_num, time()+86400, '/', "", is_ssl() , true );
    }
    if($_GET['area']){
      $structure -> set_area();
      $areaId = $structure -> get_area();
      if($areaId){

          setcookie("area_id", $areaId, time()+86400, '/', "", is_ssl() , true );
        
        }
    }

    if(empty($_COOKIE['siteid'])){
      setcookie('siteid', $siteId, time()+86400, '/', "", is_ssl() , true );
    }
    if ( empty($_COOKIE['siteIdJs']) ) {
      setcookie('siteIdJs', $siteId, time()+86400, '/', "", is_ssl() , false); // JS-accessible
    }

     if(!isset( $_COOKIE['sitename'])){

     setcookie("sitename", $site_name, time()+86400, '/', "", is_ssl() , false );
    }

    if(! is_page('menu-items') && ! is_product()){
     do_shortcode('[cbs_breadcrumbs]');
    }


    if(!isset($_COOKIE['siteid'])){
      $siteid_flag=1;
      $output = displayNoLocationSelectedMessage();
          if(!is_page( 'menu-items' ))
          {
            echo $_COOKIE['siteid'] ;
            echo $output;
          }
    }
    
    $paylater = $structure->get_paylater();
    
    if (!empty($paylater))
    {
      setcookie("pay_later_control", $paylater[0]->pay_later_control, time()+86400, '/', "", is_ssl() , true );
    }
    
    if(!isset($_COOKIE['table_num']) || $_COOKIE['table_num']=="")
    {
     setcookie("table_num", $table_num, time()+86400, '/', "", is_ssl() , true );
     $_COOKIE["table_num"]=$table_num;

    }
      if ( $areaId && empty( $_COOKIE['area_external_code'] ?? '' ) )
    {
     
         setcookie(
           "area_external_code",
            sanitize_text_field( $areaId ),
            time() + 86400,
            '/',
            "",
            is_ssl(),
            true
        );


    }

    $site_token= $structure-> get_token();

    $GLOBALS['site_token']=$site_token;
    $instance_oeapi_url = $structure-> get_instance_url();
    $GLOBALS['instance_oeapi_url']=$instance_oeapi_url;
    $myrows = $structure -> get_site_details ();
    $siteResolved = !empty($myrows); // true whenever a site was actually found, independent of cookie freshness

    if(!empty($myrows)){
      $siteId="";
      $menutype="";
      $areaids="";
      $menuids="";
      $siteId=$myrows[0]->siteid;
      $menutype=$myrows[0]->menu_type;
      $areaids=$myrows[0]->areaid;
      $timezone = $myrows[0]->timezone;
      setcookie("siteTimezone", $timezone, time() + 36000, "/");

      $mappingfile = plugin_dir_path(__FILE__) . '../assets/windowsZones.xml';
      $dotNetToPhpMapping = loadWindowsToIanaMapping($mappingfile);
      
      $phpTimezone = convertDotNetToPhpTimezone($timezone, $dotNetToPhpMapping);
      setCookie("cbstimezonesite", $phpTimezone, time() + 86400, '/', "", is_ssl(), true);
      if($menutype=="Default"){
      $menuids = getDefaultWebOrderingMenuId($siteId, $menutype); // get menu id's from site id
      $noMenu = empty($menuids); // no daypart menu matches the selected time

        if (isset($siteId) && !empty($siteId)) {
          $menu_id=$menuids;
          $results = cbs_get_top_level_categories_for_site_menu( (string) $siteId, (string) $menu_id );
          $results = cbs_sort_categories_by_termmeta_order( $results );

          // OE-26454: hide categories with no renderable product for this site+menu.
          // The deploy now keeps a category's site_id/menu_id term meta as stable
          // membership, so term meta alone no longer proves the category has items —
          // gate the nav on an actual in-scope product count (fail-open helper).
          $results = \CBSNorthStar\Helpers\CategoryVisibility::filterRenderable( $results, (string) $siteId, [ (string) $menu_id ] );
        }
      }else{
        [$overrideTime, $overrideDay] = oloNavSlotOverrides();
        $menuids_arr = getDayPartMenuId($siteId,$menutype,$areaids,$overrideTime,$overrideDay);
        $noMenu = empty($menuids_arr); // no daypart menu matched the selected slot

        $results_arr = array();

        if (isset($siteId) && !empty($siteId)) {
          foreach($menuids_arr as $menu_key=>$menu_val){
            $menu_id=$menu_val;
            $raw_results = cbs_get_top_level_categories_for_site_menu( (string) $siteId, (string) $menu_id );
            $results_arr=array_merge($results_arr,$raw_results);
          }
        }
        usort($results_arr, "cmp");
        $results_sort=$results_arr;
        $results=array_unique($results_sort, SORT_REGULAR);

        // OE-26454: keep a category if it has a renderable product under ANY of the
        // active daypart menus (same fail-open gate as the Default branch).
        if ( isset($siteId) && !empty($siteId) ) {
          $results = \CBSNorthStar\Helpers\CategoryVisibility::filterRenderable( $results, (string) $siteId, array_map('strval', array_values($menuids_arr)) );
        }

      }
    }
    
    if (!empty($results)) {
        setcookie("category_arr", json_encode($results), time()+86400, '/', "", is_ssl() , true );
      $_COOKIE['category_arr']=$results;
      $output = cbs_render_categories_nav_from_results( $results );

     return $output;


    }
    else{
      if(!$siteResolved){
        // No site could be resolved at all (distinct from $siteid_flag, which only
        // tracks whether the 'siteid' cookie was already primed by a prior request).
      $output = displayNoLocationSelectedMessage();
        return $output;
      }
      elseif($noMenu){
        $output = displayNoMenuAvailableMessage();
        return $output;
      }
      else{
        // Site + menu resolved but zero renderable categories survived
        // (none attached, or all filtered out by CategoryVisibility::filterRenderable()).
        $output = displayNoCategoriesAvailableMessage();
        CBSLogger::general()->warning( 'cbs_categories_func — active menu resolved but has zero renderable categories', [
          'site_id'  => $siteId,
          'menu_id'  => isset( $menutype ) && $menutype === 'Default' ? ( $menu_id ?? null ) : ( $menuids_arr ?? null ),
          'menutype' => $menutype ?? null,
        ] );
        return $output;
      }
    }
  }

  add_shortcode('categories', 'cbs_categories_func');




 /**
   * Menu items function
   *
   * Display all menu items of specific category
   */

   function cbs_menuitems_func(){
    global $wpdb;
    $pf = $wpdb->prefix;

    $details = "";
    $menuids = "";
    $menutype= "";
    $site_id = "";

    $catobj = new Set_sessions_for_site();
    $catobj-> set_sessions();

    $ordertype = $_COOKIE['orderType'] ?? null;
     
    
    if (get_option('ordertype_setting') !== "3" && get_option('ordertype_setting') !== null) {
      if(!isset($_COOKIE['orderType']) || $_COOKIE['orderType'] !== "2")
      {
        $ordertype = get_option('ordertype_setting');
        setcookie("orderType", $ordertype, time() + (30 * 24 * 60 * 60),  '/', "", is_ssl() , false );
      }
    }

    if(isset($_GET['site_id'])){
      $siteId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['site_id']);
      if( $siteId !== '' && $siteId != $_COOKIE['siteid'] ){
        // Expire old httponly cookies so JS can set updated non-httponly versions
        setcookie("siteid",   "", time() - 3600, '/', '', is_ssl(), false);
        setcookie("sitename", "", time() - 3600, '/', '', is_ssl(), false);
        setcookie("siteIdJs", "", time() - 3600, '/', '', is_ssl(), false);
        // Mark previous site so site-switch handler can run smart cart transition.
        if ( !empty( $_COOKIE['siteid'] ) ) {
            setcookie( 'olo_prev_siteid', sanitize_text_field( $_COOKIE['siteid'] ), time() + 120, '/' );
        }
        // Expire stale area cookies so getAreaId() re-resolves from the new site's configuration.
        setcookie( 'areaId',     '', time() - 3600, '/', '', is_ssl(), true );
        setcookie( 'area_id',    '', time() - 3600, '/', '', is_ssl(), true );
        setcookie( 'areaIdSite', '', time() - 3600, '/', '', is_ssl(), true );
        unset( $_COOKIE['areaId'], $_COOKIE['area_id'], $_COOKIE['areaIdSite'] );
        // OE-26541: a time slot confirmed on the previous site must not carry into the new
        // site's header info bar, Edit, or ASAP flows. Expire the nav-slot cookies on switch
        // (non-httponly: the JS layer reads them — matches the set form and the clears above).
        setcookie( 'oloNavTimeslot',        '', time() - 3600, '/', '', is_ssl(), false );
        setcookie( 'oloNavTimeslotId',      '', time() - 3600, '/', '', is_ssl(), false );
        setcookie( 'oloNavTimeslotTime',    '', time() - 3600, '/', '', is_ssl(), false );
        setcookie( 'oloNavTimeslotDate',    '', time() - 3600, '/', '', is_ssl(), false );
        setcookie( 'oloNavTimeslotOrderId', '', time() - 3600, '/', '', is_ssl(), false );
        setcookie( 'oloNavTimeslotSiteId',  '', time() - 3600, '/', '', is_ssl(), false );
        setcookie( 'oloNavAreaId',          '', time() - 3600, '/', '', is_ssl(), false );
        unset(
            $_COOKIE['oloNavTimeslot'], $_COOKIE['oloNavTimeslotId'], $_COOKIE['oloNavTimeslotTime'],
            $_COOKIE['oloNavTimeslotDate'], $_COOKIE['oloNavTimeslotOrderId'],
            $_COOKIE['oloNavTimeslotSiteId'], $_COOKIE['oloNavAreaId']
        );

        // OE-26541: expiring cookies alone does not release the previous site's reservation.
        // The WC session still holds it — TimeSlotsLoader::renderField() restores business_date
        // and raw_value from TimeSlotReservationSession — and the CBS hold stays active until it
        // is deleted. Release the hold against the PREVIOUS site (the one it was made on) and
        // clear the session so the slot cannot leak into the new site's checkout render.
        $priorReservation = TimeSlotReservationSession::get();
        if ( $priorReservation ) {
            try {
                $priorOrderId = $priorReservation['times_slots_order_id'] ?? '';
                $priorSlotId  = $priorReservation['time_slot_id'] ?? '';
                if ( $priorOrderId && $priorSlotId ) {
                    // The reserve flow stores site_id; fall back to the still-present previous
                    // siteid cookie (this request's setcookie('siteid','') only affects the next one).
                    $priorSiteId = ! empty( $priorReservation['site_id'] )
                        ? $priorReservation['site_id']
                        : sanitize_text_field( (string) ( $_COOKIE['siteid'] ?? '' ) );
                    // Non-blocking: this runs on the site-switch render path, so the CBS hold
                    // release must not sit on a 15s blocking request before the page reloads.
                    TimeSlotService::create()->deleteTimeSlotReservation(
                        $priorSiteId,
                        (string) $priorOrderId,
                        (string) ( $priorReservation['business_date'] ?? '' ),
                        (string) ( $priorReservation['slot_time'] ?? '' ),
                        (string) $priorSlotId,
                        false
                    );
                }
            } catch ( \Throwable $e ) {
                // Best-effort release: a delete failure must never block the site switch.
                CBSLogger::api()->warning( 'OE-26541 site-switch reservation release failed', [ 'message' => $e->getMessage() ] );
            } finally {
                // Clear locally regardless of the CBS result so the stale slot cannot leak into
                // renderField; an unreleased hold expires on its own server-side. finally{} runs
                // even if the delete above throws, so the switch always reaches the reload clean.
                TimeSlotReservationSession::clear();
            }
        }
        ?>
        <script>
          (function() {
            function getCookie(name) {
                let value = `; ${document.cookie}`;
                let parts = value.split(`; ${name}=`);
                if (parts.length === 2) return parts.pop().split(';').shift();
            }

            function setCookie(name, value, days) {
                let expires = "";
                if (days) {
                    let date = new Date();
                    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                    expires = "; expires=" + date.toUTCString();
                }
                document.cookie = name + "=" + (value || "") + expires + "; path=/";
            }

            const siteId   = <?php echo wp_json_encode( $siteId ); ?>;
            const currentSiteId = getCookie('siteid');

            if (!currentSiteId || currentSiteId !== siteId) {
                setCookie('siteid', siteId, 1);
                setCookie('siteIdJs', siteId, 1);
                location.reload();
            }
          })();
        </script>
        <?php
      }
    }

    $catobj->set_site_id();
    if($catobj->get_site_id() === '' || $catobj->get_site_id() === null){
      return displayNoLocationSelectedMessage();
    }

    $results = array();  
    if ( ! isset( $_COOKIE['siteid'] ) || empty( $_COOKIE['siteid'] ) ) {
        setcookie( 'siteid', $catobj->get_site_id(), time() + 86400, '/', '', is_ssl(), true );
    }
    // OE-26541: keep the JS-readable siteIdJs cookie bound to the current site, not only set it
    // when empty. The slot-confirm flow and the server updated the httponly `siteid` but left the
    // JS mirror `siteIdJs` stale across a site switch — so the header Edit/ASAP buttons (which read
    // siteIdJs) fetched slots for the previously visited site (e.g. a closed site → "no slots").
    // Re-sync whenever it differs from the authoritative current site.
    if ( empty( $_COOKIE['siteIdJs'] ) || $_COOKIE['siteIdJs'] !== $catobj->get_site_id() ) {
        setcookie( 'siteIdJs', $catobj->get_site_id(), time() + 86400, '/', '', is_ssl(), false ); // JS-accessible
        $_COOKIE['siteIdJs'] = $catobj->get_site_id();
    }
    $currentSiteId = $catobj->get_site_id();
    if( empty($_COOKIE['sitename']) || (isset($_COOKIE['siteid']) && $_COOKIE['siteid'] !== $currentSiteId) ){
      setcookie("sitename", $catobj->getSiteName($currentSiteId), time()+86400, '/', "", is_ssl() , false );
    }

    // OE-26541: keep the JS-readable area_id cookie bound to the current site. getAreaId()
    // honors the areaIdSite guard and re-derives from cbs_site_details when the cached area
    // belongs to a different site, so re-sync whenever area_id is empty OR no longer matches
    // the site being visited (not only when empty) — otherwise a stale area_id from a
    // previously visited site is sent to the timeslots /available endpoint.
    // getAreaId() also re-syncs the httponly areaId/areaIdSite pair used by the checkout
    // picker, so call it regardless. Respect an explicit ?area= selection for this request;
    // otherwise re-bind area_id to the current site so a stale value from a previously
    // visited site is not reused.
    $derivedAreaId = getAreaId( $currentSiteId );
    if ( empty( $_GET['area'] ) && $derivedAreaId
        && ( empty( $_COOKIE['area_id'] ) || $_COOKIE['area_id'] !== $derivedAreaId ) ) {
        setcookie( 'area_id', $derivedAreaId, time() + 86400, '/', '', is_ssl(), false );
        $_COOKIE['area_id'] = $derivedAreaId;
    }
    
    if($_GET['location']){
      $catobj-> set_location();
      $table_num = $catobj-> get_location();
    }
    if($_GET['area']){
      $catobj-> set_area();
      $area_id = $catobj-> get_area();
    }
    $paylater = $catobj->get_paylater();
    if(!isset($_COOKIE["locationinvalid"])){
      if (!empty($paylater)) {
        setcookie("pay_later_control", $paylater[0]->pay_later_control,time()+86400, '/', "", is_ssl() , true );
      }
      if($table_num != ""){
        if(isset($_COOKIE['table_num']))
          {
            if($_COOKIE['table_num'] != $table_num)
              {
              setcookie("table_num", "", time() - 3600 , '/', "", is_ssl() , true );
              setcookie("area_id", "", time() - 3600 , '/', "", is_ssl() , true );
              CBSLogger::general()->info('Location is different');
              }
          }
      }
      if(!isset($_COOKIE['table_num']) || $_COOKIE['table_num']=="")
      {
        setcookie("table_num", $table_num,time()+86400, '/', "", is_ssl() , true );
      }
    }
    if(isset($_COOKIE["locationavailable"])){
      $sc_msg = "Location Available";
      wc_add_notice(__($sc_msg), 'success');
      CBSLogger::general()->info($sc_msg);
      setcookie("locationavailable", "", time() - 3600 , '/', "", is_ssl() , true );
    }

    $site_id = $catobj->get_site_id();
    $GLOBALS['site_token']= $catobj->get_token();
    $GLOBALS['instance_oeapi_url']=$catobj->get_instance_url();
    $details = $catobj->get_site_details();
    $menutype= $details[0]->menu_type;

    if($menutype=="Default"){
      $menuids = getDefaultWebOrderingMenuId( $site_id ,$menutype);

      // No daypart menu matches the selected time: show the empty state instead
      // of an empty menu page (replaces the old confirm()/reload loop).
      if (empty($menuids) && !empty($site_id)) {
        return displayNoMenuAvailableMessage();
      }

      if (isset($site_id) && !empty($site_id)) {
        $menu_id=$menuids;
        $catalogVersion = (string) get_option( 'cbs_catalog_cache_version', '0' );
        $categoryCacheKey = 'cbs_sc_categories_' . md5( $site_id . '|' . $menu_id . '|' . $catalogVersion );
        $cachedCategories = get_transient( $categoryCacheKey );

        if ( is_array( $cachedCategories ) ) {
          $results = $cachedCategories;
        } else {
          $results = cbs_get_top_level_categories_for_site_menu( (string) $site_id, (string) $menu_id );
          $results = cbs_sort_categories_by_termmeta_order( $results );

          // OE-26454: gate on renderable products so the category_arr cookie and the
          // first-category product block below never surface an empty category.
          $results = \CBSNorthStar\Helpers\CategoryVisibility::filterRenderable( $results, (string) $site_id, [ (string) $menu_id ] );

          // Capped so this cache can never outlive an item's next active-date-window
          // start/stop crossing (which can flip a category between empty and
          // non-empty) — see MenuItemActiveWindow::cacheTtl().
          set_transient(
            $categoryCacheKey,
            $results,
            \CBSNorthStar\Helpers\MenuItemActiveWindow::cacheTtl(
              (string) $site_id,
              (int) apply_filters( 'cbs_menu_categories_cache_ttl', HOUR_IN_SECONDS )
            )
          );
        }
      }
    }

    // Menu resolved ($menuids non-empty) but zero renderable categories survived
    // filtering. Return bare, same as the noMenu/no-location checks above, so the
    // empty state isn't squeezed inside the #cbs_menu_category nav-strip wrapper
    // below (styled for a horizontal category strip, not a full-page message).
    if ( 'Default' === $menutype && empty( $results ) && ! empty( $site_id ) ) {
        CBSLogger::general()->warning( 'cbs_menuitems_func — active menu resolved but has zero renderable categories', [
            'site_id'  => $site_id,
            'menu_id'  => $menu_id ?? null,
            'menutype' => $menutype,
        ] );
        return displayNoCategoriesAvailableMessage();
    }

    if (!empty($results)) {
    setcookie("category_arr", 'results', time() + 86400, '/', "", is_ssl(), true);
     $_COOKIE['category_arr']=$results;
    }

    do_action("load_menuitems"); // for webhooks
    $cat_slug = $_GET['cat_slug'];
    $cat_name = $_GET['cat_name'];
   
    $cat_name=rtrim($cat_name,"\'");
    setcookie("categoryname", $cat_name,time()+86400, '/', "", is_ssl() , true );
    $_COOKIE['categoryname']=$cat_name;
   
    $cat_slug=rtrim($cat_slug,"\'");
   setcookie("categoryslug", $cat_slug,time()+86400, '/', "", is_ssl() , true );
   $_COOKIE['categoryslug']=$cat_slug;

   if(carbon_get_theme_option('olo_enable_infinity_scrolling')){
      cbsInfinityScroll();
      echo cbsFloatingCart();
      return;
   }

  if ( 'Default' === $menutype && ! empty( $results ) ) {
    $data = cbs_render_categories_nav_from_results( $results );
  } else {
    $data = do_shortcode('[categories]');
  }
  $output = '<div id="cbs_menu_category" class="cbs_menu_category" data-testid="menuitems-categories-wrapper">' . $data . '</div>';

    if(!isset($_COOKIE['siteid']) || empty($_COOKIE['siteid']))
    {
      $siteid_flag=1;
      $output.= displayNoLocationSelectedMessage();
    }


    if(empty($cat_slug))
    {

        if(isset($_COOKIE['category_arr']))
        {
            $results=$_COOKIE['category_arr'];
            $output.="<div>";
            foreach ($results as $row)
            {
                $showDescription = get_term_meta($row->term_id, 'category_description_visibility', false);
                $category = get_term_by( 'slug', $row->slug, 'product_cat' );
                if (isset($category->description) && !empty($category->description))
                {
                    $catdesc= $category->description;
                }
                else
                {
                    $catdesc="";
                }
                $output .="<div class='menu_with_menuitems' data-testid='menuitems-products-section'>";
                $output .= "<div id='menu_title_desc' class='menu_title_desc' data-testid='menuitems-category-header'><a href='" . get_site_url() . "/menu-items/?cat_slug=$row->slug&cat_name=$row->name' data-testid='menuitems-category-title-link'>" . stripslashes($row->name) . "</a><p class='".$row->term_id." ".$showDescription."' data-testid='menuitems-category-desc'>".$catdesc."</p></div>";
                $output .= cbs_render_scoped_products( $row->slug );
                $output .="</div>";
                break;
            }
        }

    }
    else
    {
        $catname=$_COOKIE['categoryname'] ?? $cat_name;
        $catslug= $_COOKIE['categoryslug'] ?? $cat_slug;

        $category = get_term_by( 'slug', $catslug, 'product_cat' );
        $showDescription = get_term_meta($category->term_id, 'category_description_visibility', false) ? "show" : "hide";
        if (isset($category->description) && !empty($category->description))
        {
            $catdesc= $category->description;
        }
        else
        {
            $catdesc="";
        }
        
        
        $output .="<div class='menu_with_menuitems' data-testid='menuitems-products-section'>";
        $parentCategory = getParentCategory($catslug);
        if($parentCategory) {
            $output .= renderBreadcrumbsHtml($parentCategory, $catname);
        }
        $output .= "<div id='menu_title_desc' class='menu_title_desc' data-testid='menuitems-category-header'><a href='" . get_site_url() . "/menu-items/?cat_slug=".urlencode($catslug)."&cat_name=".urlencode($catname)."' data-testid='menuitems-category-title-link'>" . stripslashes($catname) . "</a>
            <p class='".$category->term_id." ".$showDescription."' data-testid='menuitems-category-desc'>".$catdesc."</p></div>";
        $output .= cbs_render_scoped_products( $catslug );
        $output .="</div>";
    }

    $output .= cbsFloatingCart();

   return $output;
  }

  add_shortcode('menuitems', 'cbs_menuitems_func');

	/**
	 * Render the WooCommerce [products] block for one category, cached per scope.
	 *
	 * The legacy menu render re-ran the full product query + per-product loop on every page
	 * load (OE-26548). The product list HTML is generic (no per-user / per-cart fragments), so
	 * cache it keyed by the SAME scope the `woocommerce_shortcode_products_query` filter applies
	 * — active site + active menu via {@see SiteScope::resolveActiveSiteId()} /
	 * {@see MenuScope::resolveActiveMenuId()} — plus the category slug, per-page, catalog
	 * ordering and `cbs_catalog_cache_version`. This mirrors the REST path
	 * ({@see MainController::getProductsHTML()}) so cached HTML can never be served across a
	 * different site, menu or daypart (OE-26387 / OE-26399 / OE-26454 leakage guard).
	 *
	 * The cache is busted on deploy by the version token and capped by a filterable TTL.
	 * Fail-closed (unresolved site) renders live and is not cached, so a transient
	 * site-resolution glitch never pins an empty menu.
	 *
	 * @param string $categorySlug Product category slug.
	 * @return string Rendered (or cached) product block HTML.
	 */
/**
 * Run the [products] shortcode with WooCommerce's own notice output
 * (woocommerce_before_shop_loop -> woocommerce_output_all_notices) disabled.
 *
 * OE-26616: any WC notice queued during this request (e.g. cbsInfinityScroll()'s own
 * wc_print_notices() call, or a future caller) must never reach this shortcode's own
 * shop-loop hook, because cbs_render_scoped_products() caches this HTML in a transient.
 * Without this guard, a notice caught here gets baked verbatim into that cache and
 * replays — stale — for every visitor hitting the entry, on top of duplicating
 * whatever already showed it to the request that generated it.
 *
 * @param string $shortcode The [products ...] shortcode string to render.
 * @return string Rendered HTML, guaranteed free of notice markup.
 */
function cbs_render_products_shortcode_without_notices( string $shortcode ): string {
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_output_all_notices', 10 );
	try {
		$html = do_shortcode( $shortcode );
	} finally {
		add_action( 'woocommerce_before_shop_loop', 'woocommerce_output_all_notices', 10 );
	}
	return $html;
}

function cbs_render_scoped_products( $categorySlug ) {
	$perPage   = get_option( 'cbs_product_quantity', 20 );
  $categorySlug = sanitize_title( (string) $categorySlug );
  $renderAllProducts = (bool) apply_filters( 'cbs_menuitems_render_all_products', false );
  $shortcodeLimit = $renderAllProducts ? -1 : max( 1, (int) $perPage );
  $shortcode    = '[products paginate="true" limit="' . $shortcodeLimit . '" per_page="' . $perPage . '" columns="5" category="' . $categorySlug . '" ]';

	$siteId = \CBSNorthStar\Helpers\SiteScope::resolveActiveSiteId();
	// Without a resolved site the scope filter fails closed (no products); render live
	// rather than caching an empty block under an ambiguous key.
	if ( '' === $siteId ) {
		return cbs_render_products_shortcode_without_notices( $shortcode );
	}

	$menuId  = \CBSNorthStar\Helpers\MenuScope::resolveActiveMenuId( $siteId );
	$version = (string) get_option( 'cbs_catalog_cache_version', '0' );

	// Catalog ordering is per-session; include it so a sort selection cannot serve another
	// session stale HTML (same reasoning as MainController::getProductsHTML).
	$ordering = ( function_exists( 'WC' ) && WC()->query ) ? WC()->query->get_catalog_ordering_args() : array();
  $productPage = isset( $_GET['product-page'] ) ? absint( wp_unslash( $_GET['product-page'] ) ) : 1;
  if ( $productPage < 1 ) {
    $productPage = 1;
  }
  $orderby = $ordering['orderby'] ?? '';
  $metaKey = $ordering['meta_key'] ?? '';
	$order    = $ordering['order'] ?? '';

	$cacheKey = \CBSNorthStar\Helpers\MenuRenderCache::productKey(
		(string) $categorySlug,
		$siteId,
		$menuId,
		(int) $perPage,
    (int) $productPage,
		(string) $orderby,
    (string) $metaKey,
		(string) $order,
		$version
	);

	$cached = get_transient( $cacheKey );
	if ( false !== $cached ) {
		return $cached;
	}

	$html = cbs_render_products_shortcode_without_notices( $shortcode );
	// Capped so this cache can never outlive an item's next active-date-window
	// start/stop crossing — a plain HOUR_IN_SECONDS TTL here would otherwise let
	// a boundary crossing with no deploy nearby sit stale for up to an hour.
	$ttl = \CBSNorthStar\Helpers\MenuItemActiveWindow::cacheTtl(
		$siteId,
		(int) apply_filters( 'cbs_menu_products_cache_ttl', HOUR_IN_SECONDS )
	);
	set_transient( $cacheKey, $html, $ttl );

	return $html;
}




  function cbsInfinityScroll(){

    // Fail closed: render no menu when the active site can't be resolved, so the
    // infinity-scroll menu never lists items from another site (OE-26387).
    $siteId = \CBSNorthStar\Helpers\SiteScope::resolveActiveSiteId();
    if ( '' === $siteId ) {
        return;
    }
    // Recompute the active menu server-side rather than trusting the
    // currentMenu cookie — a stale/forged cookie is exactly how wrong-menu items
    // leak (OE-26399). '' when no menu is active, which matches no terms below.
    $menuId = \CBSNorthStar\Helpers\MenuScope::resolveActiveMenuId( $siteId );


    $terms = get_terms([
      'taxonomy'   => 'product_cat',
      'hide_empty' => true,
      // Top-level categories only: exclude LinkTo child terms that now carry site_id/menu_id meta.
      'parent'     => 0,
      'meta_query' => [
        'relation' => 'AND',
        [
            'key'     => 'site_id',
            'value'   => $siteId,
            'compare' => '='
        ],
        [
            'key'     => 'menu_id',
            'value'   => $menuId,
            'compare' => '='
        ],
      ],
    ]);

    // OE-26454: drop categories with no renderable product for this site+menu so neither
    // the sticky bar nor the section list shows an empty category heading. hide_empty above
    // only honors WP's global term count, not the site+menu-scoped product set.
    $terms = is_array( $terms )
        ? \CBSNorthStar\Helpers\CategoryVisibility::filterRenderable( $terms, (string) $siteId, [ (string) $menuId ] )
        : array();

    echo "<div class='infinite-scroll-menu-container menu_with_menuitems' data-site-id='". esc_attr($siteId) ."' data-testid='menuitems-scroll-container'>";
    echo "<div class='category-sections-container' data-testid='menuitems-sticky-categories'>";
    echo '<ul id="sticky-category-bar" data-testid="menuitems-sticky-bar-list">';
    foreach ($terms as $term) {
        echo '<li class="category-item custom-list-item" data-target-category="'. esc_attr($term->slug) .'" data-testid="menuitems-sticky-category-item" data-testid-category="menuitems-sticky-category-' . esc_attr($term->slug) . '">'. esc_html($term->name) .'</li>';
    }
    echo '</ul>';
    echo "</div>";
    echo "<div class='products-sections-wrapper woocommerce' data-testid='menuitems-sections-wrapper'>";
    echo "<div class='woocommerce-notices-wrapper'>";
     wc_print_notices();
    echo "</div>";
    foreach ($terms as $term) {
        $count_meta_query = \CBSNorthStar\Helpers\ProductScope::metaQuery(
            $siteId,
            $menuId,
            [ [ 'key' => '_stock_status', 'value' => 'instock', 'compare' => '=' ] ]
        );
        $count_query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 12,
            'fields'         => 'ids',
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $term->slug,
            ]],
            'meta_query'     => $count_meta_query,
        ]);
        $product_count = $count_query->post_count;
        wp_reset_postdata();

        echo '<section id="section-'. esc_attr($term->slug) .'"
                    class="product-category-section"
                    data-category-slug="'. esc_attr($term->slug) .'"
                    data-product-count="'. esc_attr($product_count) .'"
                    data-testid="menuitems-category-section"
                    data-testid-category="menuitems-section-'. esc_attr($term->slug) .'">
                <h2 data-testid="menuitems-section-title">'. esc_html($term->name) .'</h2>
                <div class="products-container-wrapper" data-testid="menuitems-products-container">
                <ul class="products products-container" data-page="1" data-testid="menuitems-products-list">
                </ul>
                </div>
                <div class="infinite-trigger"></div>
            </section>';
    }
    echo "</div>";
    echo "</div>";
  }


  function cbsFloatingCart() {
    $count = 0;
    if (function_exists('WC') && WC()->cart) {
        $count = WC()->cart->get_cart_contents_count();
    }

    $itemText = ($count == 1) ? 'item' : 'items';
    $ariaLabel = $count . ' ' . $itemText . ' in cart';

    $output = "";
    $output .= "<div class='floating-menu cbs-floating-cart' data-testid='menuitems-cart-float-wrapper'>
      <a href='".get_site_url()."/cart' class='cart position-relative d-inline-flex' aria-label='".$ariaLabel."' data-testid='menuitems-cart-float-link'>
        <i class='fas fa-shopping-cart fa-lg' aria-hidden='true'></i>
          <span class='cart-basket cart-basket-floating d-flex align-items-center justify-content-center' aria-live='polite' aria-atomic='true' data-testid='menuitems-cart-float-count'>
            <span class='sr-only'>".$ariaLabel."</span>
            <span aria-hidden='true'>".$count."</span>
          </span>
      </a>
    </div>";
    return $output;
  }

   /**
   * Default webordering menu id
   *
   * Fetch default web ordering menu id
   * @param string $siteId site id.
   * @param string $menutype menu type.
   * @return string
   */

  function getDefaultWebOrderingMenuId($siteId,$menutype)
  {
    $activeMenu = (DaypartMenusRepository::create())->getActiveDaypartMenu(
        $siteId,
        ...oloNavSlotOverrides()
    );

    // When no daypart menu matches, return null and let callers render the
    // no-menu empty state (displayNoMenuAvailableMessage). Previously this echoed
    // a confirm() + window.location.reload() that re-triggered indefinitely.
    if ( !empty($activeMenu) ) {
      $menuChanged  = !isset($_COOKIE["currentMenu"]) || $_COOKIE["currentMenu"] !== (string) $activeMenu;
      $siteSwitched = !empty( $_COOKIE['olo_prev_siteid'] );

      if ( $menuChanged || $siteSwitched ) {
          if ( $menuChanged ) {
              setcookie("currentMenu", (string) $activeMenu, time() + 86400, "/");
          }
          if ( $siteSwitched ) {
              // Site switch — always schedule smart cart transition, even if menu id matches.
              setcookie( 'olo_smart_cart_transition', '1', time() + 60, '/' );
              setcookie( 'olo_prev_siteid', '', time() - 3600, '/' );
          } elseif ( $menuChanged ) {
              // Menu/daypart change only — clear cart as before.
              setcookie('olo_clear_cart', '1', time() + 60, '/');
          }
          if ( ! headers_sent() ) {
            $reason = $siteSwitched ? 'site-switch-refresh' : 'menu-change-refresh';
            header( 'X-CBS-Redirect-Reason: ' . $reason );
          }
          wp_safe_redirect(add_query_arg( null, null ));
          exit;
      }
    }
    

    return $activeMenu;
  }

  add_action('wp_loaded', function () {
      if (is_admin() || wp_doing_ajax()) return;

    // Site switch: smart cart transition — keep matching items, update prices, drop missing.
    if (!empty($_COOKIE['olo_smart_cart_transition']) && $_COOKIE['olo_smart_cart_transition'] === '1') {
        $newSiteId = sanitize_text_field( $_COOKIE['siteid'] ?? '' );
        if ($newSiteId && function_exists('WC') && WC()->cart) {
            (new \CBSNorthStar\Services\CartSiteTransitionService())->transition($newSiteId);
        }
        if (!headers_sent()) {
            setcookie('olo_smart_cart_transition', '', time() - 3600, '/');
        }
        return;
    }

    // Menu/daypart change only: full cart clear.
    if (!empty($_COOKIE['olo_clear_cart']) && $_COOKIE['olo_clear_cart'] === '1') {
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
            WC()->cart->calculate_totals();
        }

        if (!headers_sent()) {
            setcookie('olo_clear_cart', '', time() - 3600, '/');
        }
    }
}, 20);
  
  
    /**
   * Dayparts menu id
   *
   * Fetch menu ids of dayparts
   * @param string $siteId site id.
   * @param string $menutype menu type.
   * @param string $areaids area ids.
   * @return array
   */
  function getDayPartMenuId($siteId,$menutype,$areaids,$overrideTime=null,$overrideDay=null)
  {

              $token = $GLOBALS['site_token'];
              $token_type = 'Token';
              $area_id_array = explode(",", $areaids);
              $site_info_url =$GLOBALS['instance_oeapi_url'].'/sites/'.$siteId;
              $response_site_info = \CBSNorthStar\Helpers\ShortcodeApiCache::remember(
                  'site',
                  $site_info_url,
                  function () use ( $site_info_url, $token, $token_type ) {
                      return get_api_data_shortcode( $site_info_url, $token, $token_type );
                  }
              );
              $time_zone_site=$response_site_info->Data->BclTimeZoneId;
              $url = $GLOBALS['instance_oeapi_url'].'/sites/'.$siteId.'/areadaypartmenus';
              $response = \CBSNorthStar\Helpers\ShortcodeApiCache::remember(
                  'areadaypartmenus',
                  $url,
                  function () use ( $url, $token, $token_type ) {
                      return get_api_data_shortcode( $url, $token, $token_type );
                  }
              );
              $daypartmenu_arr=$response->Data;
              $url1 = $GLOBALS['instance_oeapi_url'].'/sites/'.$siteId.'/dayparts';
              $response1 = \CBSNorthStar\Helpers\ShortcodeApiCache::remember(
                  'dayparts',
                  $url1,
                  function () use ( $url1, $token, $token_type ) {
                      return get_api_data_shortcode( $url1, $token, $token_type );
                  }
              );
              $dayparts_arr=$response1->Data;
              $menuids_arr=array();
              foreach($area_id_array as $ar_key=>$ar_value)
              {

                foreach($daypartmenu_arr as $day_key=>$day_value)
                {
                  if($ar_value==$day_value->AreaId)
                  {
                    $getvalid_menu=check_valid_menuid_shortcode($day_value->MenuId,$day_value->DayPartId,$dayparts_arr,$time_zone_site,$overrideTime,$overrideDay);
                    if($getvalid_menu!="")
                    {
                      array_push($menuids_arr,$getvalid_menu);
                    }

                  }
                }

              }

              $menuids_arr = array_unique($menuids_arr);
              return $menuids_arr;
  }

   /**
   * Check valid menu id according to dayparts id
   *
   * @param string $MenuId menu id.
   * @param string $DayPartId daypart id.
   * @param array $dayparts_arr area ids.
   * @return string
   */
  function check_valid_menuid_shortcode($MenuId,$DayPartId,$dayparts_arr,$time_zone,$overrideTime=null,$overrideDay=null)
  {
        if ($overrideTime !== null && $overrideDay !== null) {
            $current_time = $overrideTime;
            $current_day  = $overrideDay;
        } else {
            $now = \CBSNorthStar\Helpers\SiteClock::nowInTimezone((string) $time_zone);
            $current_time = $now->format('H:i:s');
            $current_day  = $now->format('l');
        }

        $toMinutes = static function ($time) {
            $time = trim((string) $time);
            if (1 !== preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $parts)) {
                return null;
            }

            $hours = (int) $parts[1];
            $mins  = (int) $parts[2];

            if ($hours < 0 || $hours > 23 || $mins < 0 || $mins > 59) {
                return null;
            }

            return $hours * 60 + $mins;
        };

        $nowMin = $toMinutes($current_time);
        if (null === $nowMin) {
            return '';
        }

        foreach($dayparts_arr as $dp_key=>$db_val)
        {
          if($DayPartId==$db_val->DayPartId)
          {
           $weekdays=$db_val->DaysOfWeek->DayOfWeek;
           $startime=$db_val->StartTime;
           $endtime=$db_val->EndTime;

            if (!in_array($current_day, $weekdays, true)) {
              continue;
            }

            $startMin = $toMinutes($startime);
            $endMin   = $toMinutes($endtime);

            if (null === $startMin || null === $endMin) {
              continue;
            }

            $matches = ($endMin > $startMin)
              ? ($nowMin >= $startMin && $nowMin < $endMin)
              : ($nowMin >= $startMin || $nowMin < $endMin);

            if ($matches)
            {
              return $MenuId;
            }
          }
        }

        return '';
  }

/**
   * Compare two arrays
   *
   * @param array $a
   * @param array $b
   * @return array
   */
   function cmp($a, $b) 
   {
     return strcmp($a->name, $b->name);
   }


/**
   * Fetch data from api
   *
   * @param string $url url of api endpoints with required parameters.
   * @param string $token token provided.
   * @param string $token_type token type.
   * @return object
   */
  function get_api_data_shortcode($url,$token,$token_type)
  {
     $curl = curl_init();
     curl_setopt_array($curl, array(
     CURLOPT_URL => $url,
     CURLOPT_RETURNTRANSFER => true,
     CURLOPT_ENCODING => '',
     CURLOPT_MAXREDIRS => 10,
     // Finite timeouts so a slow or hung WOAPI cannot pin a PHP-FPM worker
     // indefinitely (CURLOPT_TIMEOUT => 0 meant "wait forever" and exhausted
     // workers under any upstream degradation). Both are filterable.
     CURLOPT_CONNECTTIMEOUT => (int) apply_filters('cbs_shortcode_api_connect_timeout', 5),
     CURLOPT_TIMEOUT        => (int) apply_filters('cbs_shortcode_api_timeout', 15),
     CURLOPT_FOLLOWLOCATION => true,
     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
     CURLOPT_CUSTOMREQUEST => 'GET',
     CURLOPT_HTTPHEADER => array(
         "Authorization: $token_type ".$token
       ),
     ));
     $raw = curl_exec($curl);
     if ($raw === false) {
         \CBSNorthStar\Logger\CBSLogger::api()->error('Shortcode API request failed', [
             'url'   => $url,
             'errno' => curl_errno($curl),
             'error' => curl_error($curl),
         ]);
         curl_close($curl);
         return null;
     }
     curl_close($curl);
     $response = json_decode($raw);
     if(!empty($response->Data)) {
         return $response;
     }
}

/**
 * Generate breadcrumbs
 */
function get_breadcrumb() {
    $categoryname = '';
    $categoryslug = '';
    
    // Get site name from cookie or fallback to WordPress blog name
    $sitename = !empty($_COOKIE['sitename']) 
        ? sanitize_text_field($_COOKIE['sitename']) 
        : get_bloginfo('name');
    
    // Get category details from cookies
    if (!empty($_COOKIE['categoryname'])) {
        $categoryname = sanitize_text_field($_COOKIE['categoryname']);
    }
    
    if (!empty($_COOKIE['categoryslug'])) {
        $categoryslug = sanitize_key($_COOKIE['categoryslug']);
    }
    
      echo '<div id="cbs_breadcrumbs" class="cbs_breadcrumbs">';
      echo '<a href="'.home_url('/locations').'" rel="nofollow">Home</a>';
      if(is_page( '' ))
          {
            echo "&nbsp;&nbsp;&#187;&nbsp;&nbsp;";
            echo '<a href="'.home_url('/categories').'" rel="nofollow">'.$sitename.'</a>';
          }
        if(is_page( 'menu-items' ))
          {
            echo "&nbsp;&nbsp;&#187;&nbsp;&nbsp;";
            echo '<a href="'.home_url('/categories').'" rel="nofollow">'.$sitename.'</a>';
            echo "&nbsp;&nbsp;&#187;&nbsp;&nbsp;";
            echo '<a href="'.home_url('/menu-items/?cat_slug='.$categoryslug.'&cat_name='.$categoryname).'" rel="nofollow">'.$categoryname.'</a>';
          }
          if(is_product() && is_single())
          {
            echo "&nbsp;&nbsp;&#187;&nbsp;&nbsp;";
            echo '<a href="'.home_url('/categories').'" rel="nofollow">'.$sitename.'</a>';
            echo "&nbsp;&nbsp;&#187;&nbsp;&nbsp;";
            echo '<a href="'.home_url('/menu-items/?cat_slug='.$categoryslug.'&cat_name='.$categoryname).'" rel="nofollow">'.$categoryname.'</a>';
            echo "&nbsp;&nbsp;&#187;&nbsp;&nbsp;";
            echo '<a rel="nofollow">'.the_title().'</a>';
          }
           echo '</div>';

}


  /**
   * Adding custom breadcrumbs
   *
   */
  function cbs_breadcrumbs_func()
  {
//********************************************* */
global $post;

$terms = get_the_terms( $post->ID, 'product_cat' );

 $nterms = get_the_terms( $post->ID, 'product_tag'  );

 foreach ($terms  as $term  ) {                    

     $product_cat_id = $term->term_id;              

     $product_cat_name = $term->name;      
     $product_cat_slug = $term->slug;
     $url='/menu-items/?cat_slug='.$product_cat_slug.'&cat_name='.$product_cat_name;      

     break;

 }

 if( is_product()){
  echo '<div class="back-bar"><a href="'.$url.'"><i class="fa-sharp fa-solid fa-arrow-left"></i><span>'.$product_cat_name.'</span></a></div>';

 }elseif(is_page('menu-items'))
 {

 }else{
  global $wpdb;
    get_breadcrumb();
    $output="";
    return $output;
 }

    //****************************************** */
  
  }

add_shortcode('cbs_breadcrumbs', 'cbs_breadcrumbs_func');

  function menuonline_mode(){
    global $wpdb;
    // Sanitize at ingestion: this value is later injected into an inline script,
    // so an unsanitized $_GET would allow reflected XSS via a crafted site_id.
    $siteid = isset($_GET['site_id']) ? sanitize_text_field(wp_unslash($_GET['site_id'])) : '';
    $get_token=[];
$site_token='';
$get_token = $wpdb->get_results( "SELECT token,instance FROM cbs_configure_details order by id desc limit 1" );


$site_token=$get_token[0]->token;
$GLOBALS['site_token']=$site_token;
$site_instance=$get_token[0]->instance;


$get_instance_url = $wpdb->get_results("SELECT instance_ecmurl,instance_oeapiurl FROM cbs_instances where instance_name='".$site_instance."'");
    $instance_ecm_url=$get_instance_url[0]->instance_ecmurl;
    $instance_oeapi_url=$get_instance_url[0]->instance_oeapiurl;

    $GLOBALS['instance_oeapi_url']=$instance_oeapi_url;
    $menu_id = getDefaultWebOrderingMenuId($siteid,'Default');
    $my_plugin = WP_PLUGIN_DIR . '/northstaronlinedordering';
    $output="<style>
    body{
      background-color:black;
    }
    }
    #header, #footer{
      display: none;
      } .home_page_banner, .header_container{display:none} #content {
        width: 100%;
    } .category-item{
      border: white solid 1px;
      color:white;
      margin-bottom:20px;
      height:150px;
      background-position: center;
      background-size: cover;
      position:relative;
    }
    .category-item::after{
      content:'';
      position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color:black;
    opacity: .7;
    z-index: 1;

    }
    .category-item> * {
      z-index: 100;
  }
   .product-item{
      border: white solid 1px;
      color:white;
      margin-bottom:20px;
      height:150px;
      background-position: center;
      background-size: cover;
      position:relative;
      z-index:1;
    }
    .product-item::after{
      content:'';
      position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color:black;
    opacity: .7;
    z-index: 1;
    }
    .product-item> *{

        z-index: 100;

    }
     .menu-back{
      border: white solid 1px;
      color:white;
      margin-bottom:5px;
    }
    </style><div id='cb-container' class='row justify-content-center' style='min-height:400px'> <img id='imgloading' style='position:absolute;z-index:1;height:50px;' src='/wp-content/plugins/northstaronlineordering/img/loading-25.gif'><div id='wc-products' class='col-6 my-auto'>
     
      </div></div><script>
     jQuery(document).ready(function(){
      jQuery('body').addClass('vh-100');
      getCategories();
      jQuery('#footer').hide();
      jQuery('#mobile_header').hide();

     
   });//end body
   function getCategories(){
    jQuery.ajax({
      type: \"POST\",
      url: '".admin_url( 'admin-ajax.php' )."',
      data: {
        action: 'get_wc_categories',
        siteid: '".$siteid."',
        menuid: '".$menu_id."'
      },
      success: function (data) {
          jQuery('#wc-products').html(data);
          jQuery('#imgloading').hide();
          jQuery('.category-item').click(function(){
            var catid=jQuery(this).attr('data-termid');
            jQuery('#imgloading').show();
            getProductByCategory(catid);
           
          });
      }
  });  
   }

    function getProduct(id,cat){
      jQuery.ajax({
        type: \"POST\",
        url: '".admin_url( 'admin-ajax.php' )."',
        data: {
          action: 'getProductById',
          product: id
        },
        success: function (data) {

           jQuery('#wc-products').html(data);
           jQuery('#imgloading').hide();
           jQuery('#back_to_cat').click(function(){
            getProductByCategory(cat);
          });
        }
    });  
    }
    function getProductByCategory(id)
    {
      jQuery.ajax({
        type: \"POST\",
        url: '".admin_url( 'admin-ajax.php' )."',
        data: {
          action: 'getProductsByCategoryId',
          catid: id,
          siteid: ".wp_json_encode( $siteid )."
        },
        success: function (data) {
          //console.log(data);
           jQuery('#wc-products').html(data);
           jQuery('#imgloading').hide();
          // jQuery('.product-item').click(function(){
            //jQuery('#imgloading').show();
           // var productid=jQuery(this).attr('data-product');
           // var cat=jQuery(this).attr('data-cat');
            //getProduct(productid,cat);
          //});
          jQuery('#back_to_cat').click(function(){
            getCategories();
          });
        }
    });  
    }
      </script>";
    return $output;
  }
  add_shortcode('cbs_menuonline', 'menuonline_mode');


function renderBreadcrumbsHtml($parentCategory, $current = '') {
    if (empty($current)) { return ''; }
    $parentUrl = esc_url("/menu-items/?cat_slug=" . urlencode($parentCategory->slug) . "&cat_name=" . urlencode($parentCategory->name));
    $backArrow = "<a class='breadcrumb-back' href='" . $parentUrl . "'><i class='fas fa-arrow-left' aria-hidden='true'></i></a>";
    $parentHtml = "<a class='breadcrumb-parent' href='" . $parentUrl . "'>" . esc_html($parentCategory->name) . "</a><span class='breadcrumb-separator'><i class='fas fa-chevron-right' aria-hidden='true'></i></span>";
    return "<nav class='breadcrumbs'>{$backArrow}{$parentHtml}<span class='breadcrumb-current'>" . esc_html($current) . "</span></nav>";
}

function getParentCategory($slug): ?WP_Term {
    $term = get_term_by('slug', $slug, 'product_cat');
    if (!$term || is_wp_error($term)) { return null; }
    if ((int) $term->parent === 0) { return null; }
    $parent = get_term($term->parent, 'product_cat');
    return ($parent && !is_wp_error($parent)) ? $parent : null;
}

/**
 * Clear last_category cookie if certain conditions are met
 *
 * @param string $lastCategory Current last category value
 * @return string Empty string if cookie was cleared, original value otherwise
 */
function clearLastCategoryIfNeeded($lastCategory) {
    if (empty($lastCategory)) {
        return $lastCategory;
    }
    
    $shouldClear = shouldClearLastCategoryCookie($lastCategory);
    
    if ($shouldClear) {
        setcookie("last_category", "", time() - 3600, '/', "", is_ssl(), false);
        return '';
    }
    
    return $lastCategory;
}

/**
 * Determine if the last_category cookie should be cleared
 *
 * @param string $lastCategory Current last category value
 * @return bool True if cookie should be cleared
 */
function shouldClearLastCategoryCookie($lastCategory) {
    $catName = isset($_GET['cat_name']) ? $_GET['cat_name'] : '';
    $catSlug = isset($_GET['cat_slug']) ? $_GET['cat_slug'] : '';

    $parentCategory = getParentCategory($catSlug);

    if ($catName === '' || 
        !$parentCategory || 
        (is_object($parentCategory) && $parentCategory->name !== $lastCategory)) {
        return true;
    }

    return false;
}

/**
 * Display message when no location is selected
 *
 * @return string HTML message for no location selected
 */
function displayNoLocationSelectedMessage(): string {
  $locationPage = get_page_by_path('locations');
  $url = $locationPage instanceof WP_Post 
    ? get_permalink($locationPage->ID) 
    : home_url('/locations');

  return '<div class="col-md-12 no-location-selected-container">
      <div class="no-location-selected-main">
        <div class="inner-info">
          <div class="location-icon">
            <i class="fas fa-map-marker-alt"></i>
          </div>
          <div class="no-location-message search-message">
            <div class="no-location-text">Location not selected</div>
            <div class="no-location-subtext">Please select location first</div>
            <a href="'.$url.'" class="btn-location-select">
              <i class="fas fa-location-arrow"></i>
              Go to locations
            </a>
            </div>
        </div>
      </div>
    </div>';
}

/**
 * Empty state shown on menu pages when the selected time slot (or the current
 * time, with time slots disabled) does not map to any active daypart menu.
 *
 * Replaces the old confirm()/reload loop. The call to action depends on whether
 * the time-slot feature is enabled:
 *   - enabled  → reopen the time-slot popup in place (reuses .olo-ts-header-edit)
 *   - disabled → reload the page (only sensible action)
 *
 * @return string
 */
function displayNoMenuAvailableMessage(): string {
  $tsEnabled = function_exists('carbon_get_theme_option')
    && (bool) carbon_get_theme_option('olo_enable_time_slots');

  if ($tsEnabled) {
    $cta = '<button type="button" class="olo-ts-header-edit btn-location-select">
              <i class="fas fa-clock"></i>
              Choose another time slot
            </button>';
  } else {
    $reloadUrl = esc_url(add_query_arg(null, null));
    $cta = '<a href="'.$reloadUrl.'" class="btn-location-select">
              <i class="fas fa-redo"></i>
              Reload
            </a>';
  }

  return '<div class="col-md-12 no-location-selected-container">
      <div class="no-location-selected-main">
        <div class="inner-info">
          <div class="location-icon">
            <i class="fas fa-clock"></i>
          </div>
          <div class="no-location-message search-message">
            <div class="no-location-text">No menu available right now</div>
            <div class="no-location-subtext">There is no menu for the selected time.</div>
            '.$cta.'
          </div>
        </div>
      </div>
    </div>';
}

/**
 * Empty state shown on menu pages when the active daypart menu resolved
 * successfully but has zero renderable categories (e.g. every attached
 * category was filtered out by CategoryVisibility::filterRenderable()).
 *
 * Distinct from displayNoMenuAvailableMessage(), which covers the case
 * where no daypart window matches at all. Reuses the same CTA pattern.
 *
 * @return string
 */
function displayNoCategoriesAvailableMessage(): string {
  $tsEnabled = function_exists('carbon_get_theme_option')
    && (bool) carbon_get_theme_option('olo_enable_time_slots');

  if ($tsEnabled) {
    $cta = '<button type="button" class="olo-ts-header-edit btn-location-select">
              <i class="fas fa-clock"></i>
              Choose another time slot
            </button>';
  } else {
    $reloadUrl = esc_url(add_query_arg(null, null));
    $cta = '<a href="'.$reloadUrl.'" class="btn-location-select">
              <i class="fas fa-redo"></i>
              Reload
            </a>';
  }

  return '<div class="col-md-12 no-location-selected-container">
      <div class="no-location-selected-main">
        <div class="inner-info">
          <div class="location-icon">
            <i class="fas fa-utensils"></i>
          </div>
          <div class="no-location-message search-message">
            <div class="no-location-text">No items available on this menu right now</div>
            <div class="no-location-subtext">There are no items available for this menu at the moment.</div>
            '.$cta.'
          </div>
        </div>
      </div>
    </div>';
}
