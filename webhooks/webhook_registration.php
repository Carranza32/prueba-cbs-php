<?php
use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Logger\CBSLogger;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(dirname(dirname(dirname(dirname(__FILE__)))));

if ( file_exists( dirname(__FILE__) . '/../vendor/autoload.php' ) ) {
  require_once dirname(__FILE__) . '/../vendor/autoload.php';
}

if (file_exists($root . '/wp-load.php')) {
  require_once($root . '/wp-load.php');
} else {
  require_once($root . '/wp-config.php');
}

$get_token = $wpdb->get_results("SELECT id,token,instance FROM cbs_configure_details order by id desc limit 1");

if ( empty( $get_token ) ) {
    error_log( '[CBS Webhooks] Webhook registration aborted: no configuration token found in cbs_configure_details.' );
    return;
}

$token_id        = $get_token[0]->id;
$site_token      = $get_token[0]->token;
$site_instance   = $get_token[0]->instance;

$get_instance_url = $wpdb->get_results("SELECT instance_ecmurl,instance_oeapiurl FROM cbs_instances where instance_name='".$site_instance."'");

if ( empty( $get_instance_url ) ) {
    error_log( '[CBS Webhooks] Webhook registration aborted: no instance URL found for instance "' . $site_instance . '".' );
    return;
}

$instance_ecm_url    = $get_instance_url[0]->instance_ecmurl;
$instance_oeapi_url  = $get_instance_url[0]->instance_oeapiurl;

$myrows = $wpdb->get_results("SELECT * FROM cbs_site_details as cbs_site_details
  inner join cbs_configure_details as cbs_configure_details on cbs_site_details.config_id = cbs_configure_details.id where cbs_site_details.menu_type!='Disabled' AND cbs_site_details.config_id=$token_id
");

if (!empty($myrows)) {
  try {
    foreach ($myrows as $row) {
      $siteId = $row->siteid;
      Webhook_Registration($siteId, $token_id);
    }
  } catch (Exception $e) {
    echo $e->getMessage();
  }
}

/**
 * Register webhooks for a site using a GET-first reconcile against the remote list.
 *
 * For each webhook type, the remote OEAPI list is checked first. If a matching
 * registration for this site's URL already exists, the local DB is synced from the
 * remote (upsert). If the remote has no matching entry, the webhook is registered
 * fresh. Stale local records with no remote counterpart are removed before re-registering.
 *
 * @param string $SiteId   OEAPI site ID.
 * @param int    $token_id Local config token ID.
 */
function Webhook_Registration($SiteId, $token_id)
{
  $pluginfile  = plugin_dir_url(__FILE__);
  $baseUrl     = $GLOBALS['instance_oeapi_url'] . '/sites/' . $SiteId . '/webhooks/registrations';
  $token       = $GLOBALS['site_token'];
  $token_type  = 'Token';

  $remoteIndex = fetch_remote_webhook_index($baseUrl, $token, $token_type, $SiteId);

  if ( $remoteIndex === null ) {
    CBSLogger::webhooks()->warning( 'Skipping reconcile for site: remote GET failed, local state preserved.', [ 'siteId' => $SiteId ] );
    return;
  }

  $webhookTypes = [
    'MenuChanged'            => $pluginfile . 'listener_menuitemchanged.php',
    'UnavailableItemsChanged' => $pluginfile . 'listener_unavailableitem.php',
  ];

  foreach ($webhookTypes as $filterType => $listenerUrl) {
    reconcile_webhook($SiteId, $token_id, $baseUrl, $token, $token_type, $filterType, $listenerUrl, $remoteIndex);
  }
}

/**
 * Fetch all remote webhook registrations for a site and index them by
 * "WebHookUri|Filter" for O(1) lookup.
 *
 * Returns null on network/HTTP/parse errors so callers can distinguish a
 * transient failure from a genuinely empty remote registry (empty array).
 *
 * @param string $baseUrl    Registrations endpoint URL.
 * @param string $token      Auth token.
 * @param string $token_type Auth token type.
 * @param string $siteId     OEAPI site ID (for logging).
 * @return array|null Indexed by "{WebHookUri}|{Filter}" => registration object,
 *                    empty array when Ok:true but no registrations exist,
 *                    null on error.
 */
function fetch_remote_webhook_index($baseUrl, $token, $token_type, $siteId)
{
  $response = \CBSNorthStar\Helpers\WoapiRequest::create()->get($baseUrl, [
    'token'     => $token,
    'tokenType' => $token_type,
  ]);

  // Network failure, unexpected shape, or API-level error — signal with null.
  if ( empty($response) || !isset($response->Ok) || !$response->Ok ) {
    CBSLogger::webhooks()->warning( 'Webhook GET failed; returning null to prevent destructive reconcile.', [
      'siteId'   => $siteId,
      'response' => $response,
    ] );
    return null;
  }

  // Ok: true but Data is empty — site genuinely has no registrations.
  if ( empty($response->Data) ) {
    return [];
  }

  $index = [];
  foreach ($response->Data as $entry) {
    if (!empty($entry->WebHookUri) && !empty($entry->Filters)) {
      $key          = $entry->WebHookUri . '|' . $entry->Filters[0];
      $index[$key]  = $entry;
    }
  }

  return $index;
}

/**
 * Reconcile a single webhook type for a site against the remote list.
 *
 * Decision table:
 *   Remote match found            → upsert local DB, skip POST
 *   No remote match, local stale  → delete local, POST fresh, upsert
 *   No remote match, no local     → POST fresh, upsert
 *   POST returns 409              → re-fetch remote and upsert if match found
 *
 * @param string $siteId      OEAPI site ID.
 * @param int    $tokenId     Local config token ID.
 * @param string $baseUrl     Registrations endpoint URL.
 * @param string $token       Auth token.
 * @param string $tokenType   Auth token type.
 * @param string $filterType  Webhook filter type (e.g., 'MenuChanged').
 * @param string $listenerUrl Full URL to the local listener file.
 * @param array  $remoteIndex Indexed remote registrations from fetch_remote_webhook_index().
 */
function reconcile_webhook($siteId, $tokenId, $baseUrl, $token, $tokenType, $filterType, $listenerUrl, $remoteIndex)
{
  $repo      = ConfigurationRepository::create();
  $lookupKey = $listenerUrl . '|' . $filterType;

  // ── Remote match found for this site's URL ────────────────────────────────
  if (isset($remoteIndex[$lookupKey])) {
    $remote = $remoteIndex[$lookupKey];
    $repo->upsertWebhook(
      $siteId,
      $tokenId,
      $remote->Id,
      (string) ($remote->Secret ?? ''),
      $remote->WebHookUri,
      $filterType
    );
    return;
  }

  // ── No remote match — remove stale local record if present ───────────────
  $local = $repo->getWebhook($siteId, $filterType);
  if (!empty($local)) {
    $repo->deleteWebhookBySiteAndType($siteId, $filterType);
  }

  // ── POST to register ──────────────────────────────────────────────────────
  $postBody = json_encode([
    'webHookUri' => $listenerUrl,
    'filters'    => [$filterType],
  ]);

  $response = get_api_data_post($baseUrl, $token, $tokenType, $postBody);

  // 409: webhook exists on remote (race or residual) — re-fetch and sync
  if (isset($response->Ok) && !$response->Ok) {
    if (!empty($response->ErrorMessage) && stripos($response->ErrorMessage, 'already exists') !== false) {
      CBSLogger::webhooks()->warning('Webhook POST returned 409, re-fetching remote to reconcile', [
        'siteId'     => $siteId,
        'filterType' => $filterType,
        'error'      => $response->ErrorMessage,
      ]);

      $freshIndex = fetch_remote_webhook_index($baseUrl, $token, $tokenType, $siteId);
      if ( $freshIndex === null ) {
        CBSLogger::webhooks()->warning( 'Second GET also failed after 409; cannot reconcile.', [
          'siteId'     => $siteId,
          'filterType' => $filterType,
        ] );
        return;
      }
      if (isset($freshIndex[$lookupKey])) {
        $remote = $freshIndex[$lookupKey];
        $repo->upsertWebhook(
          $siteId,
          $tokenId,
          $remote->Id,
          (string) ($remote->Secret ?? ''),
          $remote->WebHookUri,
          $filterType
        );
      }
      return;
    }

    CBSLogger::webhooks()->error('Webhook POST failed', [
      'siteId'     => $siteId,
      'filterType' => $filterType,
      'error'      => $response->ErrorMessage ?? 'unknown',
    ]);
    return;
  }

  // Success — save to local DB
  if (!empty($response->Data->Id)) {
    $repo->upsertWebhook(
      $siteId,
      $tokenId,
      $response->Data->Id,
      (string) ($response->Data->Secret ?? ''),
      $response->Data->WebHookUri,
      $filterType
    );
  }
}

/**
 * POST a webhook registration to the OEAPI.
 *
 * @param string $url         Registrations endpoint.
 * @param string $token       Auth token.
 * @param string $tokenType   Auth token type.
 * @param string $postRequest JSON-encoded request body.
 * @return object API response object.
 */
function get_api_data_post($url, $token, $tokenType, $postRequest)
{
  return \CBSNorthStar\Helpers\WoapiRequest::create()->post($url, [
    'body'      => $postRequest,
    'token'     => $token,
    'tokenType' => $tokenType,
  ]);
}
