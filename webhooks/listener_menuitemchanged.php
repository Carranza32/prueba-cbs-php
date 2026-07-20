<?php

// Capture any output that may have been emitted by a PHP auto_prepend or
// error handler before this script started, so the echo challenge response
// is never contaminated.
ob_start();

// OEAPI verification must happen before WordPress loads — wp-load.php can emit
// output (notices, plugin hooks) that would corrupt the plain-text response.
if ( isset( $_GET['echo'] ) ) {
    ob_end_clean();
    header( 'Content-Type: text/plain' );
    $echo_val = substr( strip_tags( $_GET['echo'] ), 0, 256 );
    echo $echo_val;
    exit;
}

ob_end_clean();

header( 'Access-Control-Allow-Origin: *' );
header( 'Content-Type: text/plain' );

$_wp_root = dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) );
if ( file_exists( $_wp_root . '/wp-load.php' ) ) {
    require_once $_wp_root . '/wp-load.php';
} else {
    require_once $_wp_root . '/wp-config.php';
}

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
    exit;
}

$json   = file_get_contents( 'php://input' );
$object = json_decode( $json );

if ( json_last_error() !== JSON_ERROR_NONE ) {
    header( 'HTTP/1.0 415 Unsupported Media Type' );
    exit;
}

// Diagnostic: log all incoming HTTP headers when the setting is on.
if ( function_exists( 'carbon_get_theme_option' ) && (bool) carbon_get_theme_option( 'olo_log_webhook_headers' ) ) {
    $incomingHeaders = array_filter(
        $_SERVER,
        static fn( $key ) => strncmp( $key, 'HTTP_', 5 ) === 0,
        ARRAY_FILTER_USE_KEY
    );
    \CBSNorthStar\Logger\CBSLogger::webhooks()->info( 'MenuChanged webhook — incoming headers', [
        'headers' => $incomingHeaders,
    ] );
}

// Authenticate the webhook before doing any work. An unauthenticated caller
// must not be able to trigger a full product deploy by hitting this URL.
$bypassSignature = function_exists( 'carbon_get_theme_option' ) && (bool) carbon_get_theme_option( 'olo_disable_webhook_signature' );
if ( $bypassSignature ) {
    \CBSNorthStar\Logger\CBSLogger::webhooks()->warning( 'MenuChanged webhook — signature verification bypassed by admin setting' );
} elseif ( ! \CBSNorthStar\Helpers\WebhookVerifier::verify( $json, 'MenuChanged' ) ) {
    \CBSNorthStar\Logger\CBSLogger::webhooks()->warning( 'Rejected unauthenticated MenuChanged webhook' );
    status_header( 401 );
    die( 'Invalid webhook signature' );
}

if ( ! isset( $object->Id ) || $object->Id === '' ) {
    \CBSNorthStar\Logger\CBSLogger::webhooks()->warning( 'MenuChanged webhook received with no Id — skipped.' );
    exit;
}

// Log receipt immediately so we can detect duplicate deliveries (same Id = OEAPI retry).
$notificationId = $object->Id;
$action         = $object->Notifications[0]->Action ?? 'unknown';

/**
 * Extract the site IDs this notification applies to, so the deploy can be
 * scoped to the affected site(s) instead of redeploying every site.
 *
 * Defensive by design: the payload shape is provider-controlled, so we scan
 * the known candidate fields, keep only GUID-shaped values that match a
 * configured site, and fall back to a FULL deploy (null) when nothing
 * usable is found. A scoping mistake must never silently skip a site that
 * actually changed.
 *
 * @param object $object Decoded notification payload.
 * @return string[]|null Site IDs to deploy, or null for a full deploy.
 */
function cbs_extract_webhook_site_ids( $object ): ?array
{
    $guidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    $candidates  = [];

    $collect = static function ( $value ) use ( &$candidates, $guidPattern ) {
        foreach ( (array) $value as $v ) {
            if ( is_string( $v ) && preg_match( $guidPattern, $v ) ) {
                $candidates[ strtolower( $v ) ] = $v;
            }
        }
    };

    $collect( $object->SiteId ?? null );
    $collect( $object->SiteIds ?? null );

    foreach ( (array) ( $object->Notifications ?? [] ) as $notification ) {
        if ( ! is_object( $notification ) ) {
            continue;
        }
        $collect( $notification->SiteId ?? null );
        $arguments = $notification->Arguments ?? null;
        if ( is_object( $arguments ) ) {
            $collect( $arguments->SiteId ?? null );
            $collect( $arguments->SiteIds ?? null );
            $collect( $arguments->Sites ?? null );
        }
    }

    if ( empty( $candidates ) ) {
        return null; // Nothing recognizable — full deploy.
    }

    // Keep only IDs that correspond to configured sites.
    $configuration = \CBSNorthStar\Repositories\ConfigurationRepository::create();
    $configDetails = $configuration->getDetails();
    $sites         = $configuration->getSiteDetails( $configDetails->id );

    $configured = [];
    if ( is_array( $sites ) || is_object( $sites ) ) {
        foreach ( $sites as $site ) {
            $configured[ strtolower( (string) ( $site->siteid ?? '' ) ) ] = true;
        }
    }

    $matched = [];
    foreach ( $candidates as $lower => $original ) {
        if ( isset( $configured[ $lower ] ) ) {
            $matched[] = $original;
        }
    }

    return empty( $matched ) ? null : $matched;
}

$scopedSiteIds = cbs_extract_webhook_site_ids( $object );

\CBSNorthStar\Logger\CBSLogger::webhooks()->info( 'MenuChanged webhook received.', [
    'notification_id' => $notificationId,
    'action'          => $action,
    'deploy_scope'    => $scopedSiteIds === null ? 'full' : implode( ',', $scopedSiteIds ),
] );

file_put_contents( __DIR__ . '/callback_menu.txt', $json );

global $wpdb;
$wpdb->insert( 'cbs_api_calls_log', [ 'id' => 0, 'action' => $action ] );

// Acquire the deploy lock now (fast — one DB + cache op) so we can return 200
// to OEAPI immediately and prevent retries caused by a slow synchronous deploy.
$lockService = \CBSNorthStar\Services\DeployLockService::create();
$lockResult  = $lockService->tryAcquire(
    \CBSNorthStar\Repositories\DeployRunRepository::TRIGGER_HOOK,
    null,
    'listener_menuitemchanged',
    $scopedSiteIds
);

if ( $lockResult->wasBlocked() ) {
    \CBSNorthStar\Logger\CBSLogger::webhooks()->warning( 'MenuChanged webhook deploy blocked — another run is active.', [
        'notification_id' => $notificationId,
        'message'         => $lockResult->message,
        'active_run_id'   => $lockResult->active_run_id,
    ] );
    // Respond 200 — the conflict is recorded. OEAPI must not retry a blocked delivery.
    exit;
}

if ( $lockResult->wasQueued() ) {
    \CBSNorthStar\Logger\CBSLogger::webhooks()->info( 'MenuChanged webhook deploy queued.', [
        'notification_id' => $notificationId,
        'message'         => $lockResult->message,
    ] );
    exit;
}

$runId     = $lockResult->active_run_id;
$startedAt = current_time( 'mysql' );

// Count Default sites for the progress bar total — restricted to the deploy
// scope so a scoped run's progress still reaches 100%.
$configuration = \CBSNorthStar\Repositories\ConfigurationRepository::create();
$configDetails = $configuration->getDetails();
$sites         = $configuration->getSiteDetails( $configDetails->id );
$totalSites    = 0;
$scopeLower    = $scopedSiteIds === null ? null : array_map( 'strtolower', $scopedSiteIds );
if ( is_array( $sites ) || is_object( $sites ) ) {
    foreach ( $sites as $site ) {
        if ( ( $site->menu_type ?? '' ) !== 'Default' ) {
            continue;
        }
        if ( $scopeLower !== null && ! in_array( strtolower( (string) $site->siteid ), $scopeLower, true ) ) {
            continue;
        }
        $totalSites++;
    }
}

\CBSNorthStar\Services\DeployProgress::init( $runId, $totalSites, $startedAt );

// Schedule background execution and respond immediately.
// spawn_cron() sends a non-blocking loopback to wp-cron.php so OEAPI gets its
// 200 response before the deploy starts — preventing delivery retries.
wp_schedule_single_event( time(), \CBSNorthStar\Controllers\DeployController::CRON_HOOK, [ $runId, $scopedSiteIds, false ] );
$spawnResult = spawn_cron();
// spawn_cron() returns false when the doing_cron transient is still fresh from the
// previous (now-completed) cron run. Since we hold the deploy lock, clear the stale
// transient and retry once so the loopback actually fires.
$spawnRetry = null;
if ( false === $spawnResult && ! defined( 'DOING_CRON' ) ) {
    // Intentional: the deploy lock is already held, so no other deploy is running.
    // spawn_cron() returned false only because the doing_cron transient is still
    // "fresh" from a now-completed cron run — WP never clears it after a job ends.
    // The !defined('DOING_CRON') guard ensures we are not inside wp-cron.php, so
    // there is no live cron process in this PHP context to race with.
    // Pattern is identical to the same retry block in DeployController::handleStart().
    delete_transient( 'doing_cron' );
    $spawnRetry = spawn_cron();
}

$formatSpawn = static function ( $result ): string {
    if ( is_wp_error( $result ) ) {
        return $result->get_error_message();
    }
    return false === $result ? 'false' : 'ok';
};

\CBSNorthStar\Logger\CBSLogger::webhooks()->info( 'MenuChanged webhook deploy scheduled.', [
    'notification_id'    => $notificationId,
    'action'             => $action,
    'run_id'             => $runId,
    'total_sites'        => $totalSites,
    'deploy_scope'       => $scopedSiteIds === null ? 'full' : implode( ',', $scopedSiteIds ),
    'spawn_result'       => $formatSpawn( $spawnResult ),
    'spawn_result_retry' => null !== $spawnRetry ? $formatSpawn( $spawnRetry ) : null,
] );

// HTTP response is sent here. OEAPI receives 200 before the cron runs.
exit;
