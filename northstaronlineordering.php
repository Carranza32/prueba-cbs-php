<?php
/*
  Plugin Name: Northstar Online Ordering
  Plugin URI: https://northstarrestaurantpos.com/
  Description: Online ordering for restaurants, synchronizing and pairing your data with the Northstar Point of Sale.
  Author: Northstar
  Version: 2545
  Author URI: https://northstarrestaurantpos.com/
*/
error_reporting(E_ALL);
ini_set('display_errors', 1);
use CBSNorthStar\Helpers\WoapiRequest;
use CBSNorthStar\Init\ScriptLoader;
use CBSNorthStar\Repositories\ProcessRepository;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\views\SiteConfiguration;
use CBSNorthStar\Models\Sites;
use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Models\Cart;
use CBSNorthStar\Models\ProductDetail;
use CBSNorthStar\Controllers\MainController;
use CBSNorthStar\Controllers\TransactionController;
use CBSNorthStar\Helpers\BuildNumberHelper;
use CBSNorthStar\Admin\LogViewerPage;
use CBSNorthStar\Admin\SessionReportPage;
use CBSNorthStar\Admin\ProductReportPage;
use CBSNorthStar\Admin\DeployRunActionsHandler;
use CBSNorthStar\Admin\NoDaypartNotice;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Services\ComponentVersionChecker;
use CBSNorthStar\Logger\AIErrorAnalyzer;

include_once plugin_dir_path(__FILE__) . 'inc/cbs_setting_payment_locations.php';

if ( ! defined( 'CBS_PLUGIN_FILE' ) ) {
  define( 'CBS_PLUGIN_FILE', __FILE__ );
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 *
 * @since 1.0.8
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            CBS_PLUGIN_FILE,
            true
        );
    }
} );


if ( file_exists( dirname(__FILE__) . '/vendor/autoload.php' ) ) {
    require_once dirname(__FILE__) . '/vendor/autoload.php' ;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command( 'northstar fix-orphan-categories', 'CBSNorthStar\Cli\FixOrphanCategoriesCommand' );
    \WP_CLI::add_command( 'northstar fix-category-id-duplicates', 'CBSNorthStar\Cli\FixCategoryIdDuplicatesCommand' );
    \WP_CLI::add_command( 'northstar dedupe-products', 'CBSNorthStar\Cli\DedupeProductsCommand' );
}


if (!defined('ABSPATH')) {
  echo "You dont have permission for direct access.";
  exit;
}

error_reporting(1);

/**
 * Current database schema version.
 * Bump this number whenever create_tables.php changes (new table, new column,
 * changed column type, etc.). The migration runner in migrations.php will
 * detect the mismatch and apply the required changes automatically on the
 * next page load — no reinstall needed.
 */
define( 'CBS_DB_VERSION', BuildNumberHelper::getBuildNumber() );

require_once __DIR__ . '/migrations.php';

/**
 * Activate the plugin.
 * Also runs migrations so a fresh activation gets all tables immediately.
 */
function cbs_activate() {
    cbs_run_db_migrations();
}
register_activation_hook( __FILE__, 'cbs_activate' );

// Run migrations on every page load. The runner exits early when the
// stored version already matches CBS_DB_VERSION, so the overhead is
// a single get_option() call in the normal (already up-to-date) case.
add_action( 'plugins_loaded', 'cbs_run_db_migrations' );

register_deactivation_hook( __FILE__, 'cbs_deactivate' );

function cbs_deactivate() {
  wp_clear_scheduled_hook( 'cbs_daily_prduct_update' );
  wp_clear_scheduled_hook( 'cbs_regenerate_product_lookup_table' );
}

// -------------------------------------------------------------------------
// Session Reference — initialize numeric transaction reference per browser session
// -------------------------------------------------------------------------

use CBSNorthStar\Helpers\SessionReference;

add_action('init', [SessionReference::class, 'init'], 1);

// -------------------------------------------------------------------------
// CBS Logging System — register admin page and async WP-Cron hooks
// -------------------------------------------------------------------------

add_action('plugins_loaded', function () {
    // Register admin pages and action handlers
    LogViewerPage::register();
    SessionReportPage::register();
    ProductReportPage::register();
    DeployRunActionsHandler::register();
    add_action( 'admin_notices', [ ComponentVersionChecker::create(), 'renderAdminNotice' ] );
    add_action( 'admin_notices', [ NoDaypartNotice::create(), 'renderAdminNotice' ] );

    // One-time schema upgrade for existing installs (table added after initial activation)
    if (!get_option('cbs_session_events_table_v1')) {
        global $wpdb;
        $collate = $wpdb->has_cap('collation') ? $wpdb->get_charset_collate() : '';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cbs_session_events (
            id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_ref varchar(20)         NOT NULL,
            event_type      varchar(50)         NOT NULL,
            wc_order_id     bigint(20) UNSIGNED DEFAULT NULL,
            site_id         varchar(100)        DEFAULT NULL,
            status          varchar(20)         NOT NULL,
            details         longtext            DEFAULT NULL,
            created_at      datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_transaction_ref (transaction_ref),
            KEY idx_wc_order_id     (wc_order_id),
            KEY idx_status          (status),
            KEY idx_created_at      (created_at)
        ) {$collate}");
        update_option('cbs_session_events_table_v1', true);
    }

    // One-time migration: add retried_from_run_id column to the product run log.
    // New installs get this column via create_tables.php (dbDelta on activation).
    // Existing installs need a direct ALTER TABLE because dbDelta only applies
    // CREATE TABLE diffs, not post-activation column additions.
    if (!get_option('cbs_product_run_log_retried_v1')) {
        global $wpdb;
        $table      = $wpdb->prefix . 'cbs_product_run_log';
        $col_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = %s
                   AND COLUMN_NAME  = 'retried_from_run_id'",
                $table
            )
        );
        if ( ! $col_exists ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN retried_from_run_id VARCHAR(36) DEFAULT NULL AFTER conflict_type" );
        }
        update_option('cbs_product_run_log_retried_v1', true);
    }
});

/**
 * Async WP-Cron: AI analysis worker
 * Triggered via wp_schedule_single_event() inside CBSLogger.
 */
add_action('cbs_run_ai_analysis', function (array $args): void {
    $analyzer = new AIErrorAnalyzer();
    $analyzer->analyze(
        (int)  ($args['insight_id'] ?? 0),
               $args['channel']    ?? 'general',
               $args['level']      ?? 'error',
               $args['message']    ?? '',
        (array)($args['context']   ?? [])
    );
});

/**
 * Async WP-Cron: ELK Stack sender
 * Triggered via wp_schedule_single_event() inside CBSLogger.
 */
add_action('cbs_send_to_elk', function (array $args): void {
    $elkUrl = get_option('cbs_elk_url', 'https://logs.cbsnorthstar.com/log');

    // SSRF guard: require HTTPS and reject private/reserved/loopback destinations.
    if (strtolower((string) parse_url($elkUrl, PHP_URL_SCHEME)) !== 'https') {
        return;
    }

    $elkHost = (string) parse_url($elkUrl, PHP_URL_HOST);
    if ($elkHost === '') {
        return;
    }


    $ips = [];

    $ipv4 = gethostbyname($elkHost);
    if ($ipv4 !== $elkHost) {
        $ips[] = $ipv4;
    }

    $aaaaRecords = @dns_get_record($elkHost, DNS_AAAA);
    if (is_array($aaaaRecords)) {
        foreach ($aaaaRecords as $rec) {
            if (isset($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
    }

    if (empty($ips)) {
        return;
    }

    $publicFlags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    foreach ($ips as $resolvedIp) {
        if (filter_var($resolvedIp, FILTER_VALIDATE_IP, $publicFlags) === false) {
            return;  // Private, reserved, or loopback — reject
        }
    }

    wp_remote_request($elkUrl, [
        'method'  => 'PUT',
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode([
            'channel'   => $args['channel']  ?? '',
            'level'     => $args['level']    ?? '',
            'message'   => $args['message']  ?? '',
            'context'   => $args['context']  ?? [],
            'site_id'   => $args['site_id']  ?? '',
            'timestamp' => $args['time']     ?? current_time('mysql'),
            'plugin'    => 'northstaronlineordering',
        ]),
    ]);
});


/*
* AJAX function for products.
*/

    function get_wc_categories(){
      global $wpdb;
    $pf = $wpdb->prefix;
    $siteId= $_POST['siteid'];
    $menu_id= $_POST['menuid'];
    $output="";
    $query="SELECT DISTINCT p.term_id, p.name, p.slug FROM " . $pf . "terms p, " . $pf . "termmeta m1, " . $pf . "termmeta m2 WHERE p.term_id = m1.term_id and p.term_id = m2.term_id AND m1.meta_key = 'site_id' AND m1.meta_value = '".$siteId."' AND m2.meta_key = 'menu_id' AND m2.meta_value = '".$menu_id."' order by p.name ";
    $categories = $wpdb->get_results($query);
      foreach ($categories as $category) {
        $image_id           = get_term_meta( $category->term_id, 'thumbnail_id', true );
        $post_thumbnail_img = wp_get_attachment_image_src( $image_id, 'large' );
       $output.="
       <div style='background-image:url( $post_thumbnail_img[0])' class='row category-item' data-termid='".$category->term_id."'>
       <div class='col-12' style=' text-align: center; margin-top: auto;    margin-bottom: auto;'><h4>".$category->name."</h4></div>
       </div>
       ";
      }
     
  echo $output;
  wp_die();
    }
    add_action('wp_ajax_get_wc_categories', 'get_wc_categories');
    add_action('wp_ajax_nopriv_get_wc_categories','get_wc_categories');
    
    function getProductById()
    {
      global $wpdb;
      $result    = null; 
      $output="";
      $productid = $_POST["product"];
      $result    = $wpdb->get_results("SELECT * FROM wp_posts where ID={$productid}");
      $image = wp_get_attachment_image_src( get_post_thumbnail_id( $productid ), 'single-post-thumbnail');
      $product = array(
        "ID"=>$result[0]->ID,
        "post_title"=>$result[0]->post_title,
        "post_content"=>$result[0]->post_content,
        "image"=> count($image)>0?$image[0]:null,
        "stock"=> get_post_meta($post_id, '_stock'),
      ); 
      $output.="
      <div class='row  menu-back'>
      <div class='col-12'><button id='back_to_cat' type=\"button\" class=\"btn btn-outline-dark btn-lg btn-block \">Back</button></div>
      </div>
      ";
      
      ob_start();
   ?>
      <div class="card mb-3" style="max-width: 540px;">
  <div class="row no-gutters">
    <div class="col-md-4">
      <img src="<?=$image[0];?>" class="card-img" alt="...">
    </div>
    <div class="col-md-8">
      <div class="card-body">
        <h5 class="card-title"><?=$result[0]->post_title;?></h5>
        <p class="card-text"><?=$result[0]->post_content;?></p>
     </div>
    </div>
  </div>
</div>
      <?php
         $output.=ob_get_contents();
         ob_end_clean();
      echo $output;
       wp_die();
    }
    add_action('wp_ajax_getProductById', 'getProductById');
    add_action('wp_ajax_nopriv_getProductById','getProductById');
    function getProductsByCategoryId()
    {
      global $wpdb;
      $output = "";
      $categoryId = isset($_POST["catid"]) ? absint($_POST["catid"]) : 0;

      // Fail closed: scope products to the active site AND the active daypart
      // menu so a category shared by name across sites/menus never returns
      // another site's or menu's items (OE-26387 / OE-26399). Parametrised to
      // remove the previous raw catid SQL injection.
      $siteId = \CBSNorthStar\Helpers\SiteScope::resolveActiveSiteId();
      $menuId = \CBSNorthStar\Helpers\MenuScope::resolveActiveMenuId( $siteId );
      $sqlnew = $wpdb->prepare(
          "SELECT p.ID, p.post_title, GROUP_CONCAT(pm.meta_value, '') AS price
           FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
           INNER JOIN {$wpdb->postmeta} pm  ON pm.post_id = p.ID  AND pm.meta_key = '_price'
           INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_type' AND pm2.meta_value = 0
           INNER JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = p.ID AND pm3.meta_key = '_siteid' AND pm3.meta_value = %s
           INNER JOIN {$wpdb->postmeta} pm4 ON pm4.post_id = p.ID AND pm4.meta_key = '_menuid' AND pm4.meta_value = %s
           WHERE p.post_status = 'publish' AND p.post_parent = 0 AND p.post_type = 'product'
             AND tr.term_taxonomy_id = %d
           GROUP BY p.ID",
          '' !== $siteId ? $siteId : \CBSNorthStar\Helpers\SiteScope::NO_SITE,
          '' !== $menuId ? $menuId : \CBSNorthStar\Helpers\MenuScope::NO_MENU,
          $categoryId
      );
      $result = $wpdb->get_results($sqlnew);
      $output.="
      <div class='row  menu-back'>
      <div class='col-12'><button id='back_to_cat' type=\"button\" class=\"btn btn-outline-dark btn-lg btn-block \">Back</button></div>
      </div>
      ";
      foreach ($result as $product) {
        $large_image_url = wp_get_attachment_image_src( get_post_thumbnail_id( $product->ID ), 'large' );
        $price = number_format($product->price, 2, '.', ' ');
        $output.="
       <div style='background-image:url( $large_image_url[0])' class='row product-item' data-product='".$product->ID."' data-cat='{$categoryId}'>
       <div class='col-12'><h4>".$product->post_title."</h4><p class='align-bottom'>Price: $".$price."</p></div>
       </div>";
      }

      echo $output ;
      wp_die();
    }
    add_action('wp_ajax_getProductsByCategoryId', 'getProductsByCategoryId');
    add_action('wp_ajax_nopriv_getProductsByCategoryId','getProductsByCategoryId');
    function get_wc_products() {
      $html="";
      $varition_args = array(
              'post_type' => 'product',
              'posts_per_page' => 100,
              'meta_query' => array(
                array(
                    'key'   => '_siteid',
                    'value' => $_POST['siteid']))
              
          );
       $variation_query = new WP_Query($varition_args);
  
  
      if ($variation_query->have_posts()) {
              while ($variation_query->have_posts()) {
                   $variation_query->the_post();
                   global $product;
                   $html.= '<tr>';
                   $html.= '<td>'.get_the_ID().'</td>';
                   $html.= '<td>'.get_the_title().'</td>';
                   $html.= '</tr>';
              }
      }
  
      //Returns records
      $data = [];
      $data['product_html'] = $html;
      echo $html;
      wp_die();
      }
      add_action('wp_ajax_get_wc_products', 'get_wc_products');
      add_action('wp_ajax_nopriv_get_wc_products','get_wc_products');

 add_action( 'cbs_regenerate_product_lookup_table', 'cbs_regenerate_product_lookup_table_callback_function', 10);
 function cbs_regenerate_product_lookup_table_callback_function() {
  if ( function_exists( 'wc_update_product_lookup_tables' ) && function_exists( 'wc_update_product_lookup_tables_is_running' ) ) {
    if ( ! wc_update_product_lookup_tables_is_running() ) {
      wc_update_product_lookup_tables();
    }
 }
}
add_filter('woocommerce_checkout_get_value','__return_empty_string', 1, 1);

class MyPlugin
{

  private $my_plugin_screen_name;
  private static $instance;
  /*......*/

  static function GetInstance()
  {

    if (!isset(self::$instance)) {
      self::$instance = new self();
    }
    return self::$instance;
  }

/**
   * Create main settings menu of plugin
   */
  public function PluginMenu()
  {
    $this->my_plugin_screen_name = add_menu_page(
      'Northstar Online Ordering',
      'Northstar Online Ordering',
      'manage_options',
      __FILE__,
      array($this, 'RenderPage'),
      plugins_url('northstaronlineordering/img/icon.png', __DIR__)
    );

    add_submenu_page(
            'northstaronlineordering/northstaronlineordering.php', 'Generate QR Code', 'Generate QR Code', 'manage_options', 'generate-qrcode', 
            array( $this, 'cbs_qrcode_page_callback' )
        );
        add_submenu_page(
          'northstaronlineordering/northstaronlineordering.php', 'Generate QR Code for Menu online', 'Generate QR Code for Menu online', 'manage_options', 'generate-qrcode-menu', 
          array( $this, 'cbs_qrcode_menu_callback' )
      );    

   add_submenu_page(
            'northstaronlineordering/northstaronlineordering.php', 'General Settings', 'Settings', 'manage_options', 'general-settings', 
            array( $this, 'cbs_general_settings_callback' )
        );
        add_submenu_page(
          'northstaronlineordering/northstaronlineordering.php', 'payment Settings', 'Payment Settings', 'manage_options', 'payment-settings', 
          array( $this, 'cbs_payment_settings_callback' )
      );
      
  }

  /**
   * Create geneal settings page
   */
  public function cbs_payment_settings_callback() {
    if (!current_user_can('manage_options')) {
         wp_die('Unauthorized user');
     }
     echo "<h1>Northstar Online Ordering Payment Settings Page</h1>";

     ?>
     <form method="post" action="options.php">
      <?php
            settings_fields( 'payment-settings' );
            do_settings_sections( 'payment-settings' );
            submit_button();
     ?>
   </form>
   <?php
;
 }

  /**
   * Create geneal settings page
   */
function cbs_general_settings_callback() {
   if (!current_user_can('manage_options')) {
        wp_die('Unauthorized user');
    }
    echo "<h1>Northstar Online Ordering Settings Page</h1>";

    echo "<h3 id='settings_page_msg'></h3>";
    if (isset($_POST['cbs_google_api'])) {
        $status=update_option('cbs_google_api', $_POST['cbs_google_api']);
       
          if($status==true){
             echo "<script>
             document.getElementById('settings_page_msg').innerHTML='Updated Successfully';
             jQuery('#settings_page_msg').delay(2000).fadeOut();
             </script>";
             
          }
    } 

    $value = get_option('cbs_google_api', '');
    include_once plugin_dir_path(__FILE__) . 'inc/cbs_settings_page.php' ;
}
/**
 * generate a qr code to menu online
 */
function cbs_qrcode_menu_callback(){
  global $wpdb;
$mytoken = $wpdb->get_results("SELECT * FROM cbs_configure_details ORDER BY id DESC LIMIT 1");
    $latesttoken=$mytoken[0]->id;
   $myrows = $wpdb->get_results( "SELECT * FROM cbs_site_details as cbs_site_details
  inner join cbs_configure_details as cbs_configure_details on cbs_site_details.config_id = cbs_configure_details.id
where cbs_site_details.config_id=$latesttoken" );


  $qrcode_output='<h1>Generate QR Code</h1>';

  ?>

        <script>
          jQuery(function () {

        jQuery('#qrcodeform').on('submit', function (e) {
          var formData = jQuery("#qrcodeform").serialize();
          e.preventDefault();
          jQuery.ajax({
            url: '<?php echo plugins_url('qrcodegen/qrmenu.php', __FILE__) ?>',
            type: "post",
            data:formData,
            cache: false,
            success: function(data) {
              jQuery("#showqr").html(data);
            }
          });

        });

      });
        </script>

    <?php

  $qrcode_output.='<form id="qrcodeform">
  <select name="sites">';
  if(!empty($myrows))
  {

          foreach($myrows as $row)
            {  
          $siteId=$row->siteid;
          $sitename=$row->site_name;
          $qrcode_output.='<option value='.$siteId.'>'.$sitename.'</option>';
            }
  }
  

  $qrcode_output.='</select>
  <input type="submit" name="qrcode_submit" value="Generate QR Code">
  </form>
  <div id="showqr"></div>';
  echo $qrcode_output;
}
/**
   * Create qr code menu
   */
function cbs_qrcode_page_callback() {
  global $wpdb;

  $get_token = $wpdb->get_results( "SELECT token,instance FROM cbs_configure_details order by id desc limit 1" );
  $site_instance=$get_token[0]->instance;
  $token_id=$get_token[0]->id;
  $site_token=$get_token[0]->token;

  $get_instance_url = $wpdb->get_results("SELECT instance_ecmurl,instance_oeapiurl FROM cbs_instances where instance_name='".$site_instance."'");
  $instance_oeapi_url=$get_instance_url[0]->instance_oeapiurl;

  $mytoken = $wpdb->get_results("SELECT * FROM cbs_configure_details ORDER BY id DESC LIMIT 1");
  $latesttoken=$mytoken[0]->id;
  $myrows = $wpdb->get_results( "SELECT * FROM cbs_site_details as cbs_site_details
  inner join cbs_configure_details as cbs_configure_details on cbs_site_details.config_id = cbs_configure_details.id
  where cbs_site_details.config_id=$latesttoken" );
?>
  
  <h1>Generate QR Code</h1>
  <form id="search-for-locations" method="POST" name="form"> 
  <select name="sites_id">
    <?php
    if(isset($_POST["sites_id"]))
    {
      if(!empty($myrows)){
        foreach($myrows as $row){  
          $siteId=$row->siteid;
          $sitename=$row->site_name;
          if($siteId==$_POST["sites_id"])
          {
            echo'<option selected value='.$siteId.'>'.$sitename.'</option>';
          }
          else{
            echo'<option value='.$siteId.'>'.$sitename.'</option>';
          }
        }
      }
    }
    else{
      if(!empty($myrows)){
        foreach($myrows as $row){
          $siteId=$row->siteid;
          $sitename=$row->site_name;
          echo'<option value='.$siteId.'>'.$sitename.'</option>';
        }
      }
    }
    ?>
  </select>
  <input type="submit" name="submit-site-id" value="Search Locations">
  </form> 
 
  <?php if($_POST['sites_id']){?>
    <?php $site_id = $_POST['sites_id'];?>
    <?php $qrcode_output = ""; ?>
    <script>
    jQuery(document).ready(function(){
      jQuery("select.area").change(function(){
        var selectedArea = jQuery(".area option:selected").val();
        var siteId = <?= json_encode($site_id)?>;
        jQuery.ajax({
              type: "POST",
              url: '<?php echo plugins_url('qrcodegen/search_location.php', __FILE__) ?>',
              data: { area : selectedArea, site : siteId
              } 
        }).done(function(data){
            jQuery("#response").html(data);
          });
      });
    });
    </script>
    <script>
    jQuery(function () {
      jQuery('#qrcodeform').on('submit', function (e) {
        var formData = jQuery("#qrcodeform").serialize();
        var selectedArea = jQuery(".area option:selected").val();
        var siteId = <?= json_encode($site_id)?>;
        var selectedLocation  = jQuery(".locations option:selected").val();
        var ec  = jQuery("#areaExternalCode").val();
          


          e.preventDefault();
          jQuery.ajax({
            url: '<?php echo plugins_url('qrcodegen/qr.php', __FILE__) ?>',
            type: "POST",
            data: { area : selectedArea, site : siteId, location : selectedLocation,areaExternalCode:ec
              }, 
            cache: false,
            success: function(data) {
              jQuery("#showqr").html(data);
            }
          });
        });
      });
    </script>
    <?php $url = '/areas';?>
    <form id="qrcodeform" name="location-selector" method="POST"> 
      <?php $connection = new Connection(); ?>
      <?php $response = $connection->getData($site_id, $url, 'Token'); ?>
      <select name="area" class="area" >
        <option>Select</option>
        <?php
        if(!empty($response)){
          foreach($response as $data){
            foreach($data as $areas){
              echo '<option value='.$areas->AreaId.'>'.$areas->Name.'</option>';

            }
          }
        }
        ?>
      </select> 
      <div id="response"> </div>
    
    </form>
    <div id="showqr"></div>
    <?php echo $qrcode_output; ?>
    <?php } ?>
  <?php
}

  /**
   * To render the configuration page.
   * @param
   * @return void
   */
  public function RenderPage() {

    global $wpdb;
    global  $sites_name_error;
    $timezone = "";
    $sites_name_error="Unable to send and retrieve data from the following Site(s):<br>";
    $get_instance_url = $wpdb->get_results("SELECT instance_ecmurl FROM cbs_instances where instance_name='".$_POST['instance_name']."'");
    $instance_ecm_url=$get_instance_url[0]->instance_ecmurl;
    $responseName = array();
    $status = ProcessRepository::create()->getStatus('save_product');
    $errorMsg = ($status->result) == 2 ?
        'This is the result of the last time the save product process was executed <br>' . implode('<br>',json_decode($status->message)) : null;

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
      $token = $_POST['token'];
      $instance = $instance_ecm_url;
      $url_endpoint = $instance . '/ecm/api/v1/site/';

      $response = WoapiRequest::create()->get($url_endpoint, [
          'token' => $token
      ]);

      foreach ($response->Data as $responseKey => $responseVal) {
        $responseName[$responseKey]['name'] = $responseVal->Name;
        $responseName[$responseKey]['siteID'] = $responseVal->SiteId;
      }
    }
      $instance_record = array();
      $instances = $wpdb->get_results("SELECT * FROM cbs_instances");
      
      if(!empty($instances))  {
        foreach($instances as $inst){
              $instance_record[$inst->instance_name] = $inst->instance_oeapiurl;
          }
      }
      
      if (isset($_POST['submit']) || isset($_POST['update'])  ) {
      global $wpdb;
      $token = $_POST['token'];
      $instance = $_POST['instance_name'];
      
      if(isset($_POST['submit']))
      {
        $insertFormData = $wpdb->insert('cbs_configure_details', array(
          'token' => $token,
          'instance' => $instance,
        ));
        $lastid = $wpdb->insert_id;
      }
    
      $siteDetail = "";
      $i = 1;
      foreach ($response->Data as $responseKey => $responseVal) {
        $responseName[$responseKey]['siteID'] = $responseVal->SiteId;
        $responseName[$responseKey]['name'] = $responseVal->Name;
        $menutype = !empty($_POST['menu_type_' . $responseVal->SiteId]) ? $_POST['menu_type_' . $responseVal->SiteId] : '';
        if($menutype === "Default"){
          $siteInfo = (new Connection())->getData($responseVal->SiteId, '', 'Token');
          $timezone = $siteInfo->Data->BclTimeZoneId ? $siteInfo->Data->BclTimeZoneId : '';
        }
        $areaid = !empty($_POST['area' . $responseVal->SiteId]) ? $_POST['area' . $responseVal->SiteId] : '';
        $paylater = !empty($_POST['pay_later_' . $responseVal->SiteId]) ? $_POST['pay_later_' . $responseVal->SiteId] : '';
        $shipping = !empty($_POST['shipping_' . $responseVal->SiteId]) ? sanitize_text_field($_POST['shipping_' . $responseVal->SiteId]) : '';
        $payment = !empty($_POST['payment_control_' . $responseVal->SiteId]) ? $_POST['payment_control_' . $responseVal->SiteId] : '';
        $siteDetail = $wpdb->get_row("SELECT * FROM cbs_site_details where siteid='" . $responseVal->SiteId . "'");

        if (!empty($siteDetail)) {
            global $wpdb;

            $table = 'cbs_site_details';

            $data = [
              'menu_type'           => $menutype,
              'payment_control'     => $payment,
              'pay_later_control'   => $paylater,
              'shipping_control'    => $shipping,
              'areaid'              => $areaid,
              'area_name'           => $area_name,
              'site_name'           => $responseVal->Name,
              'address1'            => $responseVal->Address1,
              'address2'            => $responseVal->Address2,
              'state'               => $responseVal->State,
              'city'                => $responseVal->City,
              'zipcode'             => $responseVal->Zip,
              'countrycode'         => $responseVal->CountryCode,
              'phone'               => $responseVal->Phone,
              'latitude'            => $responseVal->Latitude,
              'longitude'           => $responseVal->Longitude,
              'startofbusinesstime' => $responseVal->StartOfBusinessTime,
              'startofbusinessweek' => $responseVal->StartOfBusinessWeek,
              'kitchenopentime'     => $responseVal->KitchenOpenTime,
              'kitchenclosetime'    => $responseVal->KitchenCloseTime,
              'payperiodstartdate'  => $responseVal->PayPeriodStartDate,
              'payperiodstartime'   => $responseVal->PayPeriodStartTime,
              'isactive'            => $responseVal->IsActive,
              'timezone'            => $timezone,
              'enableseatNumber'    => $responseVal->EnableSeatNumber,
            ];

            if (isset($lastid)) {
              $data['config_id'] = $lastid;
            }

            $where = [ 'id' => (int) $siteDetail->id ];

            $updateFormData = $wpdb->update(
              $table,
              $data,
              $where
            );

            if ($updateFormData === false) {
              error_log('DB update error: ' . $wpdb->last_error);
            }
        } else {
          $wpdb->insert('cbs_site_details', array(
            'siteid' => $responseVal->SiteId,
            'site_name' => $responseVal->Name,
            'menu_type' => $menutype,
            'areaid' =>  !empty($areaid) ? $areaid : '',
            'area_name' => !empty($area_name) ? $area_name : '',
            'address1' => $responseVal->Address1,
            'address2' => $responseVal->Address2,
            'state' => $responseVal->State,
            'city' => $responseVal->City,
            'zipcode' => $responseVal->Zip,
            'countrycode' => $responseVal->CountryCode,
            'phone' => $responseVal->Phone,
            'latitude' => $responseVal->Latitude,
            'longitude' => $responseVal->Longitude,
            'startofbusinesstime' => $responseVal->StartOfBusinessTime,
            'startofbusinessweek' => $responseVal->StartOfBusinessWeek,
            'kitchenopentime' => $responseVal->KitchenOpenTime,
            'kitchenclosetime' => $responseVal->KitchenCloseTime,
            'payperiodstartdate' => $responseVal->PayPeriodStartDate,
            'payperiodstartime' => $responseVal->PayPeriodStartTime,
            'isactive' => $responseVal->IsActive,
            'timezone' => $timezone,
            'enableseatNumber' => $responseVal->EnableSeatNumber,
            'config_id' => $lastid,
            'pay_later_control' => $paylater,
            'shipping_control' => $shipping,
            'payment_control' => $payment,
          ));
        }
        $i++;
      }

      if ($insertFormData || isset($updateFormData)) {
        $success = 'Data submitted successfully';
?>
         <script>
          jQuery.ajax({
            url: '<?php echo plugins_url('webhooks/webhook_registration.php', __FILE__) ?>',
            type: "GET",
            cache: false,
            success: function(data) {
                console.log("webhooks registration called");
            }
          });
        </script>
    <?php
      } else {
        $errorMsg = 'Data not submitted! Try again!!!';
      }
      unset($_POST);
    }
    global $wpdb;
    $latesttoken=null;
    $mytoken = $wpdb->get_results("SELECT * FROM cbs_configure_details ORDER BY id DESC");

    if(count($mytoken)>1){
      $latesttoken=$mytoken[0]->id;
    }
   
    if(isset($latesttoken))
    {
      $myrows = $wpdb->get_results("SELECT * FROM cbs_site_details as cbs_site_details
      inner join cbs_configure_details as cbs_configure_details on cbs_site_details.config_id =cbs_configure_details.id where cbs_site_details.config_id='".$latesttoken."'");
   
    }
    else
    {
       $myrows = $wpdb->get_results("SELECT * FROM cbs_site_details as cbs_site_details
       inner join cbs_configure_details as cbs_configure_details on cbs_site_details.config_id =cbs_configure_details.id");
       $mytoken = $wpdb->get_results("SELECT * FROM cbs_configure_details ORDER BY id DESC LIMIT 1");
    }
    ?>

    <!Doctype html>
    <html lang="en">
      <head>
        <meta charset="UTF-8">
        <title>Configuration Setting</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
      </head>

    <body>
      <div class="container inst_container bootstrap-wrapper">
                <header class="heading"> Configuration Setting </header>
        <div class="cbs-header">
          <img src="<?php echo  plugin_dir_url( __FILE__ ). "img/LogoNOS.png"?>" alt="logo">
        </div>
          <?php  /*  echo (new SiteConfiguration())->render(); */ ?> 
        <?php
        if (isset($success)) {
        ?>
          <p class="alert alert-success" style="text-align: center;"><?php echo $success; ?></p>
        <?php
          unset($success);
        }
        if (isset($errorMsg)) {
        ?>
          <p class="alert alert-danger" style="text-align: center;"><?php echo $errorMsg; ?></p>
        <?php
          unset($errorMsg);
        }
        ?>
        <?php $siteMode = get_option('siteMode', 'olo'); ?>
        <?php $compression = get_option('image_compression', '1'); ?>
        <!-- Get Sites Form -->
        <form id="css-styles" method="POST" enctype="application/json" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
          <div class="row">
          <?php if (isset($_POST['token'])) {?>
            <div class="col-sm-12">
              <div class="row">
                <div class="col-md-4  col-sm-6 col-xs-12"><label class="firstname">Access Token :</label></div>
                <div class="col-md-8  col-sm-6 col-xs-12">
                  <input type="text" onkeypress="return blockSpecialChar(event)" class="form-control" name="token" id="token" value="<?php if (isset($token)) {echo $token;} ?>" > 
                </div>
                </div>
              </div>
            <div class="col-sm-12">
              <div class="row">
                <div class="col-xs-12  col-sm-6 col-md-4"><label class="lastname">Please Select an Instance :</label></div>

                  <div class="col-xs-12  col-sm-6 col-md-6">
                    <select class="form-control" id="instance" name="instance" required>
                      <option>Please select an instance</option>
                      <?php
                      foreach ($instance_record as $key => $value) {
                        if (isset($_POST['instance_name']) && $_POST['instance_name'] == $key) {
                          echo '<option selected value=' . $value . '>' . $key . '</option>';
                        } else {
                          echo '<option value=' . $value . '>' . $key . '</option>';
                        }
                      }
                      ?>
                    </select>
                    <input id="instance_name" name="instance_name" type="text" hidden value="<?php echo $_POST['instance_name']; ?>" >
                  </div>
                  <div class="col-xs-12 col-md-2">
                    <button class="btn btn-sm btn btn-success" type="submit" name="#" value="Get Sites">Get Sites</button>
                  </div>
              </div>
            </div>
            <div class="col-sm-12">
              <div class="row">
                <div class="col-md-4  col-sm-6 col-xs-12"><label class="firstname" for="displayMode">Site Mode: </label></div>
                <div class="col-md-8  col-sm-6 col-xs-12">
                  <select class="form-control" name="displayMode" id="display_mode" required>
                    <option value="olo" <?php echo $siteMode === 'olo'? 'selected' : '' ?> >Online Ordering</option>
                    <option value="kiosk" <?php echo $siteMode === 'kiosk'? 'selected': '' ?> >Kiosk</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="col-sm-12">
              <div class="row">
                <div class="col-md-4  col-sm-6 col-xs-12"><label class="image_compression" for="image_compression">Image Compression: </label></div>
                <div class="col-md-8  col-sm-6 col-xs-12">
                  <select class="form-control" name="image_compression" id="image_compression" required>
                    <option value="1" <?php echo $compression === '1'? 'selected' : '' ?> >1</option>
                    <option value="2" <?php echo $compression === '2'? 'selected': '' ?> >2</option>
                    <option value="3" <?php echo $compression === '3'? 'selected': '' ?> >3</option>
                    <option value="4" <?php echo $compression === '4'? 'selected': '' ?> >4</option>
                    <option value="5" <?php echo $compression === '5'? 'selected': '' ?> >5</option>
                    <option value="6" <?php echo $compression === '6'? 'selected': '' ?> >6</option>
                    <option value="7" <?php echo $compression === '7'? 'selected': '' ?> >7</option>
                    <option value="8" <?php echo $compression === '8'? 'selected': '' ?> >8</option>
                    <option value="9" <?php echo $compression === '9'? 'selected': '' ?> >9</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="col-sm-12">
              <table id="example1" class="table table-striped table-bordered" style="width:100%">
                <thead>
                  <tr>
                    <th style="text-align: center;">Site </th>
                      <th style="text-align: center;">Menu Type</th>
                      <th style="text-align: center;">Shipping</th>
                      <th style="text-align: center;">Scan QR</th>
                      <th style="text-align: center;">Area</th>
                      <th style="text-align: center;">Payment Option</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $count = 1;?>
                  <?php foreach ($responseName as $resvalue) { ?>
                  <?php $qur_data = $wpdb->get_row("SELECT * FROM cbs_site_details where siteid='" . $resvalue['siteID'] . "'"); ?>
                  <tr>
                    <td>
                      <input type="hidden" name="site_name<?= $resvalue['siteID']; ?>" value="<?= $resvalue['siteID']; ?>"><?= $resvalue['name']; ?>
                    </td>
                    <td>
                      <select class="form-control" name="menu_type_<?= $resvalue['siteID'] ?>" id="purpose<?= $resvalue['siteID']; ?>" required  data-menu-type='<?= !empty($qur_data->menu_type) ? $qur_data->menu_type : '' ?>' rowId="<?= $resvalue['siteID']; ?>" data-area-id='<?= !empty($qur_data->areaid) ? $qur_data->areaid : '' ?>'>
                        <option value="Disabled" <?php if ($qur_data->menu_type == 'Disabled') {
                                                        echo 'selected';
                                                      } ?>>Disabled
                        </option>
                        <option value="Default" <?php if ($qur_data->menu_type == 'Default') {
                                                      echo 'selected';
                                                    } ?>>Default</option>
                      <option value="Coming" <?php if ($qur_data->menu_type == 'Coming') {
                                                  echo 'selected';
                                                } ?>>Coming Soon</option>
                      </select>
                    </td>
                      <td>
                      <select class="form-control" name="shipping_<?= $resvalue['siteID']; ?>" id="shipping<?= $resvalue['siteID']; ?>">


                        <option value="Disabled" <?php if ($qur_data->shipping_control == 'Disabled') {
                                                    echo 'selected';
                                                  } ?>>Disabled</option>
                        <option value="Enabled" <?php if ($qur_data->shipping_control == 'Enabled') {
                                                  echo 'selected';
                                                } ?>>Enabled</option>
                      </select>
                    </td>
                    <td>
                      <select class="form-control" name="pay_later_<?= $resvalue['siteID']; ?>" id="pay_later<?= $resvalue['siteID']; ?>">
                        <option value="Disabled" <?php if ($qur_data->pay_later_control == 'Disabled') {
                                                    echo 'selected';
                                                  } ?>>Disabled</option>
                        <option value="Enabled" <?php if ($qur_data->pay_later_control == 'Enabled') {
                                                  echo 'selected';
                                                } ?>>Enabled</option>
                      </select>
                    </td>
                    <td class="<?php echo "area".$resvalue['siteID']?>">
                    </td>
                    <td>
                      <select class="form-control" name="payment_control_<?= $resvalue['siteID']; ?>" id="payment_control<?= $resvalue['siteID']; ?>">
                        <option value="stripe" <?php if ($qur_data->payment_control == 'stripe') {
                                                    echo 'selected';
                                                  } ?>>Stripe</option>
                        <option value="beyond" <?php if ($qur_data->payment_control == 'beyond') {
                                                  echo 'selected';
                                                } ?>>Beyond</option>
                        <option value="clover" <?php if ($qur_data->payment_control == 'clover') {
                                                  echo 'selected';
                                                } ?>>Clover</option>
                        <option value="authorize" <?php if ($qur_data->payment_control == 'authorize') {
                                                  echo 'selected';
                                                } ?>>Authorize</option>
                        <option value="None" <?php if ($qur_data->payment_control == '' || $qur_data->payment_control == 'None') {
                                                  echo 'selected';
                                                } ?>>None</option>
                      </select>
                    </td>
                  </tr>
                    <?php
                      $count++;
                    } ?>
                </tbody>
              </table>
                <div class="col-md-4 col-sm-4 col-xs-12 custom-width-style" style="text-align: left;position: absolute;bottom: 7px">
                  <button class="btn btn-warning" type="submit" name="submit" id="save1">Save</button>
                  <button class="btn btn-warning" type="button" onClick="window.location.reload()">Cancel</button>
                </div>
              </div>
            <?php } else { ?>

              <!-- Show Form  -->
              <div class="col-sm-12">
                <div class="row">
                  <div class="col-xs-12 col-sm-6 col-md-4"><label class="firstname">Access Token :</label></div>
                  <div class="col-xs-12  col-sm-6 col-md-8">
                    <input type="text" onkeypress="return blockSpecialChar(event)" class="form-control" name="token" id="token" value="<?= $mytoken[0]->token; ?>" placeholder="Enter Access Token" required="">
                  </div>
                </div>
              </div>
              <div class="col-sm-12">
                <div class="row">
                  <div class="col-xs-12 col-sm-6 col-md-4"><label class="lastname" for="instance">Please Select an Instance :</label></div>
                  <div class="col-xs-12 col-sm-6 col-md-6">
                    <select class="form-control" id="instance" name="instance" required>
                      <option>Please select an instance</option>
                      <?php
                      $instance = $mytoken[0]->instance;
                      foreach ($instance_record as $key => $value) {
                        if (isset($instance) && $instance == $key) {
                          echo '<option selected value=' . $value . '>' . $key . '</option>';
                        } else {
                          echo '<option value=' . $value . '>' . $key . '</option>';
                        }
                      }

                      ?>
                    </select>
                    <input id="instance_name" name="instance_name" type="text" hidden value="<?php echo $instance;  ?>" >
                  </div>
                  <div class="col-xs-12 col-md-2">
                    <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start">
                      <button class="btn btn-sm btn-success" style="width:160px" type="submit" name="#" value="Get Sites">Get Sites</button>
                      <?php if (isset($myrows) && count($myrows) != 0) { ?>
                      <button id="cbs-deploy-btn" type="button" class="btn btn-sm btn-success" style="width:160px">Save Products</button>
                      <button id="cbs-deploy-media-btn" type="button" class="btn btn-sm btn-primary" style="width:160px">Save Products &amp; Media</button>
                      <?php } ?>
                    </div>
                    <?php if (isset($myrows) && count($myrows) != 0) { ?>
                    <div id="cbs-deploy-ui">
                      <div id="cbs-deploy-queue-notice" style="display:none;margin-top:6px;font-size:12px;color:#856404;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:6px 10px"></div>
                    </div>

                    <style>
                    #cbs-deploy-modal{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center}
                    #cbs-deploy-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55)}
                    #cbs-deploy-modal-dialog{position:relative;background:#fff;border-radius:6px;width:500px;max-width:92vw;box-shadow:0 8px 32px rgba(0,0,0,.3);display:flex;flex-direction:column}
                    #cbs-deploy-modal-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #ddd}
                    #cbs-deploy-modal-header h3{margin:0;font-size:16px;font-weight:600}
                    #cbs-modal-close{background:none;border:none;font-size:24px;line-height:1;color:#666;padding:0 4px;border-radius:3px;cursor:pointer}
                    #cbs-modal-close:disabled{opacity:.3;cursor:default}
                    #cbs-modal-close:not(:disabled):hover{background:#f0f0f0}
                    #cbs-deploy-modal-body{padding:20px 18px}
                    #cbs-deploy-modal-footer{padding:12px 18px;border-top:1px solid #ddd;display:flex;justify-content:flex-end;gap:8px}
                    #cbs-progress-bar-wrap{background:#e0e0e0;border-radius:4px;height:22px;overflow:hidden;margin-bottom:12px}
                    #cbs-progress-bar{background:#5b9bd5;height:100%;width:0%;line-height:22px;color:#fff;text-align:center;font-size:12px;transition:width .3s}
                    #cbs-deploy-step{margin:0 0 4px;font-size:13px;font-weight:500}
                    #cbs-deploy-site{margin:0 0 4px;font-size:12px;color:#555}
                    #cbs-deploy-counts{margin:0 0 4px;font-size:12px}
                    #cbs-deploy-result{margin-top:12px}
                    </style>

                    <div id="cbs-deploy-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="cbs-modal-title">
                      <div id="cbs-deploy-modal-backdrop"></div>
                      <div id="cbs-deploy-modal-dialog">
                        <div id="cbs-deploy-modal-header">
                          <h3 id="cbs-modal-title">Save Products</h3>
                          <button id="cbs-modal-close" type="button" aria-label="Close" disabled>&times;</button>
                        </div>
                        <div id="cbs-deploy-modal-body">
                          <div id="cbs-progress-bar-wrap">
                            <div id="cbs-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                          </div>
                          <p id="cbs-deploy-step" aria-live="polite" aria-atomic="true"></p>
                          <p id="cbs-deploy-site"></p>
                          <div id="cbs-deploy-counts"></div>
                          <div id="cbs-deploy-result" aria-live="polite" aria-atomic="true"></div>
                        </div>
                        <div id="cbs-deploy-modal-footer">
                          <button id="cbs-cancel-btn" type="button" class="btn btn-sm btn-warning" style="display:none">Cancel</button>
                        </div>
                      </div>
                    </div>
                    <?php } ?>

                  </div>
                  <br>

                </div>



              </div>
              <div class="col-sm-12">
                <div class="row">
                  <div class="col-md-4  col-sm-6 col-xs-12"><label class="firstname" for="displayMode">Site Mode: </label></div>
                  <div class="col-md-8  col-sm-6 col-xs-12">
                  <select class="form-control" name="displayMode" id="display_mode" required>
                    <option value="olo" <?php echo $siteMode === 'olo'? 'selected' : '' ?> >Online ordering</option>
                    <option value="kiosk" <?php echo $siteMode === 'kiosk'? 'selected': '' ?> >Kiosk</option>
                  </select>
                  </div>
                </div>
              </div>
              <div class="col-sm-12">
              <div class="row">
                <div class="col-md-4  col-sm-6 col-xs-12"><label class="image_compression" for="image_compression">Image Compression: </label></div>
                <div class="col-md-8  col-sm-6 col-xs-12">
                  <select class="form-control" name="image_compression" id="image_compression" required>
                    <option value="1" <?php echo $compression === '1'? 'selected' : '' ?> >1</option>
                    <option value="2" <?php echo $compression === '2'? 'selected': '' ?> >2</option>
                    <option value="3" <?php echo $compression === '3'? 'selected': '' ?> >3</option>
                    <option value="4" <?php echo $compression === '4'? 'selected': '' ?> >4</option>
                    <option value="5" <?php echo $compression === '5'? 'selected': '' ?> >5</option>
                    <option value="6" <?php echo $compression === '6'? 'selected': '' ?> >6</option>
                    <option value="7" <?php echo $compression === '7'? 'selected': '' ?> >7</option>
                    <option value="8" <?php echo $compression === '8'? 'selected': '' ?> >8</option>
                    <option value="9" <?php echo $compression === '9'? 'selected': '' ?> >9</option>
                  </select>
                </div>
              </div>
            </div>

          </div>

          <?php $error = $_GET['error']; ?>
          <?php $sites_error = explode(",",$_GET['sites_error']); ?>
          <?php if($error){?>
          <div class="row d-flex justify-content-center">
            <div class="col-md-3"></div>
            <div class="col-md-6">
              <div id="sites_error" class="alert alert-danger text-center" role="alert">
                <?php echo "Could not save product information"; ?>
            
              </div>
            </div>
            <div class="col-md-3"></div>
          </div>
          <?php } ?>
          <div class="col-sm-12">
            <table id="example2" class="table table-striped table-bordered" style="width:100%">
              <thead>
                <tr>
                  <th style="display: none;"></th>
                  <th style="text-align: center;">Site Name</th>
                  <th style="text-align: center;">Menu Type</th>
                  <th style="text-align: center;">Shipping</th>
                  <th style="text-align: center;white-space: pre;">Order at Table</th>
                  <th style="text-align: center;white-space: pre;">Area</th>
                  <th style="text-align: center;">Payment Option</th>
                </tr>
              </thead>
              <tbody>

                <?php 
                $count = 1;
             
                foreach ($myrows as $key => $cbsdata) {
                ?>

                  <tr>
                    <input type="hidden" name="site_id" id="site_id" value="<?= $cbsdata->siteid; ?>">
                    <td style="display: none;"><?= $cbsdata->id; ?></td>
                    <td>
                      
                      <?php 
                      $detail = (ConfigurationRepository::create())->getWebhook($cbsdata->siteid , 'MenuChanged');
                      $registeredDomain = $detail->webhookurl ? parse_url($detail->webhookurl, PHP_URL_HOST)  : "";
                      $url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                      $domain = parse_url($url, PHP_URL_HOST);

                    if(in_array($cbsdata->siteid,$sites_error)){
                        echo "<span style='color:red'> {$cbsdata->site_name}</span>";
                      $sites_name_error.=$cbsdata->site_name."<br>";
                      $update_query = "UPDATE cbs_site_details SET menu_type='Disable' WHERE siteid='" . $cbsdata->siteid . "'";
                      $wpdb->query($wpdb->prepare($update_query));
                    }
                    else{
                      echo "<span style='color:green'> {$cbsdata->site_name}</span>";
                    }
                    if($registeredDomain != $domain && $registeredDomain != ""){

                      echo "<div> Webhook registered invalid. Register it again. </div>";
                    }
                    ?></td>
                    <td>
                      <select class="form-control" name="menu_type_<?= $cbsdata->siteid; ?>" id="purpose<?= $cbsdata->siteid; ?>"  rowId="<?= $cbsdata->siteid; ?>" data-area-id='<?= !empty($cbsdata->areaid) ? $cbsdata->areaid : '' ?>'>
                        <option value="Disabled" <?php if ($cbsdata->menu_type == 'Disabled') {
                                                    echo 'selected';
                                                  } ?>>Disabled</option>
                        <option value="Default" <?php if ($cbsdata->menu_type == 'Default') {
                                                  echo 'selected';
                                                } ?>>Default</option>
                        <option value="Coming" <?php if ($cbsdata->menu_type == 'Coming') {
                                                  echo 'selected';
                                                } ?>>Coming Soon</option>

                      </select>
                    </td>
                      <td>
                      <select class="form-control" name="shipping_<?= $cbsdata->siteid; ?>" id="shipping<?= $cbsdata->siteid; ?>">


                        <option value="Disabled" <?php if ($cbsdata->shipping_control == 'Disabled') {
                                                    echo 'selected';
                                                  } ?>>Disabled</option>
                        <option value="Enabled" <?php if ($cbsdata->shipping_control == 'Enabled') {
                                                  echo 'selected';
                                                } ?>>Enabled</option>
                      </select>
                    </td>
                    <td>
                      <select class="form-control" name="pay_later_<?= $cbsdata->siteid; ?>" id="pay_later<?= $cbsdata->siteid; ?>">


                        <option value="Disabled" <?php if ($cbsdata->pay_later_control == 'Disabled') {
                                                    echo 'selected';
                                                  } ?>>Disabled</option>
                        <option value="Enabled" <?php if ($cbsdata->pay_later_control == 'Enabled') {
                                                  echo 'selected';
                                                } ?>>Enabled</option>
                      </select>
                    </td>
                    <td class="<?php echo "area".$cbsdata->siteid?>" data-defaultarea="<?php echo $cbsdata->areaid?>">
                      Area no selected
                    </td>
                    <td>
                      <select class="form-control" name="payment_control_<?= $cbsdata->siteid; ?>" id="payment_control<?= $cbsdata->siteid; ?>">
                        <option value="stripe" <?php if ($cbsdata->payment_control == 'stripe') {
                                                    echo 'selected';
                                                  } ?>>Stripe</option>
                        <option value="beyond" <?php if ($cbsdata->payment_control == 'beyond') {
                                                  echo 'selected';
                                                } ?>>Beyond</option>
                        <option value="clover" <?php if ($cbsdata->payment_control == 'clover') {
                                                  echo 'selected';
                                                } ?>>Clover</option>
                        <option value="authorize" <?php if ($cbsdata->payment_control == 'authorize') {
                                                  echo 'selected';
                                                } ?>>Authorize</option>
                        <option value="None" <?php if ($cbsdata->payment_control == '' || $cbsdata->payment_control == 'None') {
                                                  echo 'selected';
                                                } ?>>None</option>
                      </select>
                    </td>
                  </tr>
                  <script type="text/javascript">
                    jQuery(document).ready(function() {
                      jQuery('#sites_error').html('<?= $sites_name_error; ?>');
                      jQuery("#business<?= $count; ?>").hide();
                      jQuery('#purpose<?= $count; ?>').on('change', function() {
                        if (this.value == 'Day Part') {
                          jQuery("#business<?= $count; ?>").show();
                          jQuery("#business_span<?= $count; ?>").hide();
                        } else {
                          jQuery("#business<?= $count; ?>").hide();
                          jQuery("#business_span<?= $count; ?>").show();
                        }
                      });
                    })
                  </script>
                <?php
                  $count++;
                } ?>
              </tbody>
            </table> <div class="col-md-4 col-sm-4 col-xs-12 custom-width-style" style="text-align: left;position: absolute;bottom: 7px;">
              <button class="btn btn-warning" type="submit" name="update" id="update1">Update</button>
              <button class="btn btn-warning" type="button" onClick="window.location.reload()">Cancel</button>
            </div>

          </div>
        <?php } ?>
        <button
            id="clear-register"
            class="btn btn-warning"
            type="button"
            url= "<?php echo plugins_url('webhooks/webhook_registration.php', __FILE__) ?>"
          >
          Register Webhook
        </button>
        </form>
        <form id="areaform" method="POST" enctype="application/json" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
          <input type="hidden" id="areaval" name="areaforminput[]">
          <input type="hidden" id="rowval" name="rowidforminput[]">
        </form>
      </div>

    </body>

    </html>

    <script type="text/javascript">

      jQuery(document).ready(function() {
        jQuery('#saveproduct').click(function() {
          if (jQuery('#token').val().length === 0) {
            alert('Please enter Access token!');
            jQuery('#token').focus();
            return false;
          }
        })
      });
    </script>

<?php }



/**
   * Function to be called on plugin initialization
   */

  public function InitPlugin()
  {
    $payment_settings = new PaymentSettings();
    add_action('admin_menu', array($this, 'PluginMenu'));
    add_action( 'admin_init', array( $payment_settings , 'setup_sections' ) );
    add_action( 'admin_init', array( $payment_settings , 'setup_fields' ) );
    
    $mainController = new MainController();
    $transactionController = new TransactionController();
    add_action('rest_api_init', [$mainController, 'registerRoutes']);
    add_action('rest_api_init', [$transactionController, 'registerRoutes']);


    
    function cbsBlocksInit() {
      register_block_type( __DIR__ . '/build/categories' );
      register_block_type( __DIR__ . '/build/products' );
      register_block_type( __DIR__ . '/build/total' );
      register_block_type( __DIR__ . '/build/blockb' );
      register_block_type( __DIR__ . '/build/cart' );
      register_block_type( __DIR__ . '/build/product-detail' );
      register_block_type(__DIR__. '/build/pin-device');
      register_block_type(__DIR__. '/build/checkout');
      register_block_type(__DIR__. '/build/order-type');
      register_block_type(__DIR__. '/build/start-over');
      register_block_type(__DIR__. '/build/guest-identification');
      register_block_type(__DIR__. '/build/slider');
    }
    if(get_option('siteMode') === 'kiosk'){
      add_action( 'init', 'cbsBlocksInit' );
    }

     /**
     * v2 libs
    */
    include_once plugin_dir_path(__FILE__) . 'lib/qrpay.php';
    // libs
    include_once plugin_dir_path(__FILE__) . 'inc/wp_cbs_shortcode.php';
    include_once plugin_dir_path(__FILE__) . 'inc/custom-woocommerce.php';
    include_once plugin_dir_path(__FILE__) . 'inc/woocommerce_hooks.php';
    include_once plugin_dir_path(__FILE__) . 'inc/additional_checkout_field.php';
    include_once plugin_dir_path(__FILE__) . 'webhooks/woocommerce_products_modify_unavailable.php';
    include_once plugin_dir_path(__FILE__) . 'cbs_functions.php';
   

    function cbs_admin_style_js()
    {
        wp_enqueue_style('admincss', plugins_url('/northstaronlineordering/css/admin_style.css', __DIR__));

        wp_enqueue_script(
            'cbs-deploy-progress',
            plugins_url( '/js/deploy-progress.js', CBS_PLUGIN_FILE ),
            [],
            BuildNumberHelper::getBuildNumber(),
            true
        );
        wp_localize_script( 'cbs-deploy-progress', 'cbsDeployData', [
            'restBase' => get_rest_url( null, 'northstaronlineordering/v1' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ] );
    }
    add_action( 'admin_enqueue_scripts', 'cbs_admin_style_js' );

    function cbs_style_js()
    {
        $version = BuildNumberHelper::getBuildNumber();
        wp_enqueue_style('formcss', plugins_url('css/form.css', CBS_PLUGIN_FILE), array(), $version);
        wp_enqueue_style('cbsstylecss', plugins_url('css/style.css', CBS_PLUGIN_FILE), array(), $version);
        wp_enqueue_style('locationpagecss', plugins_url('css/location_page.css', CBS_PLUGIN_FILE), array(), $version);
        wp_enqueue_style('cdn_datatable_bootstrap_min_css', '//cdn.datatables.net/1.10.23/css/dataTables.bootstrap.min.css');
        wp_enqueue_style('cdn_fixheader_bootstrap_min_css', '//cdn.datatables.net/fixedheader/3.1.7/css/fixedHeader.bootstrap.min.css');
        

        wp_register_script('jquery-cookie', plugins_url('js/jquery-cookie-1.4.1/jquery.cookie.js', __FILE__), array('jquery'), $version);
        wp_enqueue_script('jquery-cookie');
        wp_enqueue_style('cdn_fixheader_font-awesome', '//cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css');
        wp_enqueue_script('formjs', plugins_url('/northstaronlineordering/js/form.js', __DIR__), array('jquery'), $version, true);
        wp_enqueue_script('customjs', plugins_url('/northstaronlineordering/js/custom.js', __DIR__), array('jquery'), $version, true);
        wp_enqueue_script('cbs_datatable_min_js', '//cdn.datatables.net/1.10.23/js/jquery.dataTables.min.js', array('jquery'), '', true);
        wp_enqueue_script('cbs_datatable_bootstrap_min_js', 'https://cdn.datatables.net/1.10.23/js/dataTables.bootstrap.min.js', array('jquery', 'cbs_datatable_min_js'), '', true);
        wp_enqueue_script('cbs_datatable_fixheader_min_js', '//cdn.datatables.net/fixedheader/3.1.7/js/dataTables.fixedHeader.min.js', array('jquery', 'cbs_datatable_min_js'), '', true);
        wp_enqueue_script(
          'kiosk-custom',
          plugins_url('northstaronlineordering/assets/dist/js/olo_components.bundle.js'),
          array( 'jquery' ),
          $version,
          true
        );
        wp_localize_script( 'kiosk-custom', 'olo_vars_object',
          array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'symbol_product' => get_woocommerce_currency_symbol(),
            'ajax_nonce' => wp_create_nonce('ajax-login-nonce'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'stickyFooterPriceSelector' => '.sticky-footer-product-bar .price',
          )
        );
        wp_enqueue_script('savecookiesjs', plugins_url('/northstaronlineordering/js/savecookies.js', __DIR__), array('jquery'), $version, true);
        wp_enqueue_script('ajax-custom-script', plugins_url('/northstaronlineordering/js/cbs_custom.js', __DIR__),  array( 'jquery' ), $version, true);
        wp_localize_script('ajax-custom-script', 'pluginData', array(
          'url' => plugins_url('', __FILE__)
      ));
        wp_enqueue_script('submit_to_kitchen',
        plugins_url('/js/submit_to_kitchen.js',CBS_PLUGIN_FILE),
        array( 'jquery' ),
        $version,
        true
      );
      wp_enqueue_script('tip-keyboard-nav',
        plugins_url('/js/tip_keyboard_nav.js',CBS_PLUGIN_FILE),
        array( ),
        $version,
        true
      );
      
            // Localize the script with some data
        $data_to_be_passed = array(
          'ajax_url' => admin_url( 'admin-ajax.php' ),
          'nonce'    => wp_create_nonce( 'session_nonce' ),
        );
        
        wp_localize_script( 'ajax-custom-script', 'session_obj', $data_to_be_passed );

    }


    add_action( 'init', 'cbs_style_js' );


    function cbs_stylesheet(){
      $plugin_url = plugin_dir_url( __FILE__ );
      $version = BuildNumberHelper::getBuildNumber();
      wp_enqueue_style( 'productstyle',  $plugin_url . "css/product_style.css" , array(), $version);
      if( is_page( 'menu-online' ) ) {
        wp_enqueue_style( 'cdn-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@4.4.1/dist/css/bootstrap.min.css');
      }
      if(is_page('menu-items')) {
        wp_enqueue_style('menuitemstyle', $plugin_url . "css/menuitems.css",array(),$version);
      }
      if(is_page('checkout')) {
        wp_enqueue_style('checkoutstyle', $plugin_url . "css/checkout.css",array(),$version);
      }
      if(is_cart()) {
        wp_enqueue_style('cartstyle', $plugin_url . "css/cart.css",array(),$version);
      }
      if(is_account_page()) {
        wp_enqueue_style('myaccountstyle', $plugin_url . "css/myaccount.css",array(),$version);
      }
        remove_filter('style_loader_src', 'tmpl_remove_wp_ver_css_js', 9999);
 
        remove_filter('script_loader_src', 'tmpl_remove_wp_ver_css_js', 9999);
    }
    add_action('wp_enqueue_scripts', 'cbs_stylesheet');

    function enqueueCartFragments()
    {
      // Check if ‘wc-cart-fragments’ script is already enqueued or registered
      if ( ! wp_script_is( 'wc-cart-fragments', 'enqueued' ) && wp_script_is( 'wc-cart-fragments', 'registered' ) ) {

        wp_enqueue_script( 'wc-cart-fragments' );
        }
    }
    add_action('wp_enqueue_scripts', 'enqueueCartFragments');

    // Fix: wc-authorize-net-cim.min.js is registered without declaring its dependency
    // on the SkyVerge payment form framework script, causing "this.payment_fields.find"
    // TypeError when the CIM script loads before the parent class is defined.
    // Uses prefix search instead of hardcoded version handle to survive plugin updates.
    add_action( 'wp_enqueue_scripts', function() {
        global $wp_scripts;
        if ( ! isset( $wp_scripts->registered['wc-authorize-net-cim'] ) ) {
            return;
        }

        $sv_handle = '';
        foreach ( array_keys( $wp_scripts->registered ) as $handle ) {
            if ( strpos( $handle, 'sv-wc-payment-gateway-payment-form-v' ) === 0 ) {
                $sv_handle = $handle;
                break;
            }
        }

        if ( $sv_handle ) {
            $deps = &$wp_scripts->registered['wc-authorize-net-cim']->deps;
            if ( ! in_array( $sv_handle, $deps, true ) ) {
                $deps[] = $sv_handle;
            }
        }
    }, 9999 );
   
  }

}

$MyPlugin = MyPlugin::GetInstance();
$MyPlugin->InitPlugin();

if (class_exists(ScriptLoader::class)) {
    (new ScriptLoader())->loadScripts();
}
