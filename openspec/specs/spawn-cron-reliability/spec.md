# Capability: spawn-cron-reliability

## Overview

Ensures the deploy cron loopback fires reliably in all contexts where a deploy is initiated or queued. Addresses three failure modes: a stale `doing_cron` transient that causes `spawn_cron()` to return `false` in webhook and REST trigger paths; `spawn_cron()` returning `false` unconditionally when `DOING_CRON` is defined (inside `firePendingDeploy`); and `spawn_result` log values masking `false` returns as `"ok"`.

---

## Requirements

### Requirement: Webhook and REST trigger MUST clear stale doing_cron transient before spawn_cron

After acquiring the deploy lock and scheduling the cron event, the trigger path SHALL call `spawn_cron()`. If `spawn_cron()` returns `false` AND no `WP_Error` is returned AND `DOING_CRON` is NOT defined (i.e. the false return is due to the `doing_cron` transient being fresh from the previous run), the trigger SHALL delete the `doing_cron` transient and call `spawn_cron()` once more.

This applies to:
- `webhooks/listener_menuitemchanged.php` (hook-deploy trigger)
- `DeployController::handleStart()` (REST admin-deploy trigger)

#### Scenario: spawn_cron returns false due to stale transient in webhook listener
WHEN a webhook-triggered deploy fires within 60 seconds of the previous cron completing
AND `spawn_cron()` returns `false` (not a WP_Error, DOING_CRON not defined)
THEN the system SHALL delete the `doing_cron` transient
AND call `spawn_cron()` a second time
AND log whether the retry succeeded or failed (with `spawn_result_retry` in the log context)

#### Scenario: spawn_cron returns false due to stale transient in REST admin trigger
WHEN an admin REST deploy fires within 60 seconds of the previous cron completing
AND `spawn_cron()` returns `false` (not a WP_Error, DOING_CRON not defined)
THEN the system SHALL delete the `doing_cron` transient
AND call `spawn_cron()` a second time
AND log whether the retry succeeded or failed

#### Scenario: spawn_cron succeeds on first attempt
WHEN `spawn_cron()` returns non-false on the first call
THEN the system SHALL NOT delete the `doing_cron` transient
AND SHALL NOT call `spawn_cron()` again

---

### Requirement: firePendingDeploy MUST use direct loopback when DOING_CRON is defined

When `firePendingDeploy()` is called from within `runBackgroundDeploy()` (i.e., inside a `wp-cron.php` process where `DOING_CRON` is defined), the system SHALL NOT call `spawn_cron()` directly, because `spawn_cron()` returns `false` immediately when `DOING_CRON` is defined.

Instead, the system SHALL:
1. Delete the `doing_cron` transient (signaling the current cron run is complete).
2. Fire a non-blocking HTTP POST to `site_url('wp-cron.php')` using `wp_remote_post` with `blocking = false` and `timeout = 0.01`.

#### Scenario: firePendingDeploy fires after deploy completes inside cron
WHEN `firePendingDeploy()` is called from the `finally` block of `runBackgroundDeploy()`
AND a pending deploy entry exists in the queue
AND `DOING_CRON` is defined
THEN the system SHALL delete the `doing_cron` transient
AND SHALL make a non-blocking `wp_remote_post` to `wp-cron.php`
AND SHALL log the direct-loopback attempt with context including `run_id` and `pending_trigger`

#### Scenario: firePendingDeploy fires from outside cron context (not expected but safe)
WHEN `firePendingDeploy()` is called from a context where `DOING_CRON` is NOT defined
THEN the system SHALL still use the direct loopback path (consistent behaviour, no branch on DOING_CRON)

---

### Requirement: spawn_result log MUST distinguish false, true, and WP_Error

The `spawn_result` value in all deploy log entries (REST trigger, webhook trigger, firePendingDeploy) SHALL be:
- `"ok"` when `spawn_cron()` or the loopback returns a truthy success value
- `"false"` when `spawn_cron()` returns boolean `false`
- The WP_Error message when `spawn_cron()` returns a `WP_Error` instance

#### Scenario: spawn_cron returns false — log shows false, not ok
WHEN `spawn_cron()` returns `false` (e.g. DOING_CRON defined, doing_cron transient fresh, or no due events)
THEN the log entry SHALL record `spawn_result` as `"false"`

#### Scenario: spawn_cron returns WP_Error — log shows error message
WHEN `spawn_cron()` returns a `WP_Error`
THEN the log entry SHALL record `spawn_result` as the error message string

#### Scenario: spawn_cron returns true — log shows ok
WHEN `spawn_cron()` returns `true` or a truthy array response
THEN the log entry SHALL record `spawn_result` as `"ok"`

#### Scenario: retry succeeds — log includes retry context
WHEN the first `spawn_cron()` call returns `false` due to stale transient
AND the retry after transient deletion returns truthy
THEN the log entry SHALL include `spawn_result: "false"` for the first attempt
AND `spawn_result_retry: "ok"` for the retry result

---

## Edge Cases

- If the `doing_cron` transient deletion fails (e.g. object cache unavailable), the retry call to `spawn_cron()` may still return `false`. This MUST be logged as a warning, not swallowed.
- The direct loopback in `firePendingDeploy` targets `site_url('wp-cron.php')`. If the site URL is misconfigured or the loopback is blocked at the network layer (e.g., Pressable Nginx), the loopback may silently fail. This is identical behaviour to what `spawn_cron()` does internally and is NOT a new failure mode introduced by this fix.
- If `DISABLE_WP_CRON` is true, `spawn_cron()` returns `false` without checking the `doing_cron` transient. The transient-deletion retry would still fire, but `spawn_cron()` would return `false` again. This is expected and acceptable — sites with `DISABLE_WP_CRON` manage cron externally. Log `spawn_result` as `"false"` and do not retry further.
