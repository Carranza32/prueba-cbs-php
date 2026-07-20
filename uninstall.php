<?php

/**
 * Uninstall
 *
 * Used to run when plugin get uninstalled
 *
 */


// if uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
  die;
}

use CBSNorthStar\Helpers\WoapiRequest;

if ( file_exists( dirname(__FILE__) . '/vendor/autoload.php' ) ) {
  require_once dirname(__FILE__) . '/vendor/autoload.php' ;
}

global $wpdb;


$get_token = [];
$site_token = '';

$myrows = $wpdb->get_results("SELECT * FROM cbs_webhook_registration");



if (!empty($myrows))         // Checking if $results have some values or not
{

  try {
    foreach ($myrows as $row) 
    {
      $siteId = $row->siteid;
      $webhookId = $row->webhookid;
      $token_id= $row->tokenid;

      $get_token = $wpdb->get_results("SELECT token,instance FROM cbs_configure_details where id=".$token_id);
      $site_token = $get_token[0]->token;
      $site_instance = $get_token[0]->instance;

      $get_instance_url = $wpdb->get_results("SELECT instance_ecmurl,instance_oeapiurl FROM cbs_instances where instance_name='".$site_instance."'");
    $instance_ecm_url=$get_instance_url[0]->instance_ecmurl;
    $instance_oeapi_url=$get_instance_url[0]->instance_oeapiurl;

      $url =$instance_oeapi_url . "/sites/" . $siteId . "/webhooks/registrations/" . $webhookId . "/";
      $response = get_api_data_webhook($url, $site_token, "Token");
      if (!$response->Ok)
      {
        echo "Something went wrong while deleting webhooks";
      }
    } //foreach loop ends
  } catch (Exception $e) {
    echo $e->getMessage();
  }
} // if empty statement ends

// Delete plugin options so a reinstall of the same version triggers migrations fresh
delete_option('cbs_db_version');
delete_option('cbs_session_events_table_v1');
delete_option('cbs_product_run_log_retried_v1');

// Deploy-state options: the cross-process deploy lock, the pending-deploy
// queue slot, the per-site daypart snapshots, and the per-site deploy hash
// caches (cbs_deploy_menu_hashes_{siteId}). The LIKE patterns cover the
// per-site keys; the literal deletes cover the fixed keys.
delete_option('cbs_deploy_db_lock');
delete_option('cbs_deploy_pending');
$wpdb->query(
    "DELETE FROM {$wpdb->options}
      WHERE option_name LIKE 'cbs_deploy_menu_hashes\_%'
         OR option_name LIKE 'cbs_daypart_snapshot\_%'"
);

// Per-product deploy fingerprints (safe to remove — absence just means the
// next deploy processes everything in full).
$wpdb->query(
    $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cbs_item_hash')
);

// Delete all custom tables
  $wpdb->query("DROP TABLE IF EXISTS cbs_webhook_registration");
  $wpdb->query("DROP TABLE IF EXISTS cbs_site_details");
  $wpdb->query("DROP TABLE IF EXISTS cbs_configure_details");
  $wpdb->query("DROP TABLE IF EXISTS cbs_daypartmenus");
  $wpdb->query("DROP TABLE IF EXISTS cbs_instances");
  $wpdb->query("DROP TABLE IF EXISTS cbs_save_api_response");
  $wpdb->query("DROP TABLE IF EXISTS cbs_time_zone_settings");
  $wpdb->query("DROP TABLE IF EXISTS wp_woocommerce_cart_record");
  $wpdb->query("DROP TABLE IF EXISTS save_product_process");
  $wpdb->query("DROP TABLE IF EXISTS cbs_api_calls_log");
  $wpdb->query("DROP TABLE IF EXISTS cbs_processes");
/**
   * Fetch data from api
   *
   * @param string $url url of api endpoints with required parameters.
   * @param string $token token provided.
   * @param string $token_type token type.
   * @return array
   */
function get_api_data_webhook($url, $token, $token_type)
{
    $response = WoapiRequest::create()->delete($url, [
      'token' => $token,
      'tokenType' => $token_type
    ]);

    if (!empty($response->Data)) {
      return $response;
    }
}
