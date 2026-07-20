<?php

namespace CBSNorthStar\Init\Loaders;

use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Repositories\DeployRunRepository;
use CBSNorthStar\SaveProduct;
use CBSNorthStar\Models\Sites;
use CBSNorthStar\Helpers\BuildNumberHelper;
use CBSNorthStar\Services\DeployOrchestrator;
use CBSNorthStar\Controllers\DeployController;

class SaveProductLoader implements JavaScriptLoaderContract
{
  private static $instance = null;

  public static function create(): ?SaveProductLoader
  {
    if (self::$instance === null) {
      self::$instance = new SaveProductLoader();
    }

    return self::$instance;
  }
  public function registerScripts()
  {
    wp_enqueue_script('save_product_handle',
      plugins_url('/js/save_product.js',CBS_PLUGIN_FILE),
      array( 'jquery' ),
      BuildNumberHelper::getBuildNumber(),
      true
    );

    wp_localize_script('save_product_handle', 'saveProductData', array(
      'loadingIconUrl' => plugins_url('/', dirname(__FILE__)) . '../../img/loading-25.gif'
  ));

    add_action('wp_ajax_save_product_action', array($this, 'ajaxHandler'));
    add_action('wp_ajax_nopriv_save_product_action', array($this, 'ajaxHandler'));

    add_action('wp_ajax_menu_day_parts', array($this, 'menuDayParts'));
    add_action('wp_ajax_nopriv_menu_day_parts', array($this, 'menuDayParts'));

    add_action('wp_ajax_clear_register_action', array($this, 'deleteRegister'));
    add_action('wp_ajax_clear_register_action', array($this, 'deleteRegister'));

    add_action('wp_ajax_update_site_display_mode', array($this, 'handleSiteMode'));
    add_action('wp_ajax_nopriv_update_site_display_mode', array($this, 'handleSiteMode'));

    add_action('wp_ajax_nopriv_update_image_compression', array($this, 'handleCompression'));
    add_action('wp_ajax_update_image_compression', array($this, 'handleCompression'));

    // Deploy REST endpoints + background cron callback.
    add_action( 'rest_api_init', [ DeployController::create(), 'registerRoutes' ] );
    // 4 accepted args: runId, optional site-ID scope (webhook-scoped deploys),
    // force-full flag (admin/manual runs bypass the menu-hash skip),
    // and skip_images (passed from the UI deploy button).
    add_action( DeployController::CRON_HOOK, [ DeployController::class, 'runBackgroundDeploy' ], 10, 4 );
  }

  public function ajaxHandler(): void
  {
    if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'save_product_action' ) {
      return;
    }

    // Only administrators may trigger a deploy.
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
      return;
    }

    // Delegate the entire lifecycle to the orchestrator.
    // Lock acquire, store() execution, logging, and lock release all happen there.
    $result = DeployOrchestrator::create()->run(
      DeployRunRepository::TRIGGER_MANUAL,
      get_current_user_id(),
      'admin_button'
    );

    // Prefer raw per-site messages so the client can render one per line.
    // Falls back to the flattened string when raw_messages is empty
    // (e.g. blocked runs, exceptions without per-site context).
    $payload = ! empty( $result->raw_messages ) ? $result->raw_messages : $result->message;

    if ( $result->wasSuccessful() ) {
      wp_send_json_success( $payload );
    } else {
      wp_send_json_error( [ 'message' => $payload ] );
    }
  }
  public function deleteRegister(){
    if (isset($_POST['action']) && $_POST['action'] === 'clear_register_action') {
      $deleted = (ConfigurationRepository::create())->deleteWebhook();
      wp_send_json_success($deleted);
    }
  }
  public function menuDayParts(){
    if (isset($_POST['action']) && $_POST['action'] === 'menu_day_parts') {
      $siteId = $_POST['siteId'];
      $token = $_POST['token'];
      $instance = $_POST['instance'];
      $site = new Sites();
      $siteDayParts = $site->requestSiteAreaDayParts($siteId , $token , $instance);
      wp_send_json_success($siteDayParts);
    }
  }
  public function handleSiteMode() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_site_display_mode') {
      $siteMode = $_POST['siteMode'] ?? null;
      $currentSiteMode = get_option('siteMode', 'olo');
      $response = ['message' => 'No changes made'];
      if ($siteMode !== $currentSiteMode) {
        update_option('siteMode', $siteMode);
        $response = ['message' => 'Site mode updated successfully'];
      }

      wp_send_json_success($response);
    }
  }
  public function handleCompression() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_image_compression') {
      $compression = $_POST['image_compression'] ?? null;
      $currentCompression = get_option('image_compression', '1');
      $response = ['message' => 'No changes made'];
      if ($compression !== $currentCompression) {
        update_option('image_compression', $compression);
        $response = ['message' => 'Image compression updated successfully'];
      }
      wp_send_json_success($response);
    }
  }
}



