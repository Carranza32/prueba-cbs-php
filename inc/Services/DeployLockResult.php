<?php

namespace CBSNorthStar\Services;

/**
 * DeployLockResult — immutable value object returned by DeployLockService::tryAcquire().
 *
 * Contains every piece of information the caller needs to decide whether to
 * proceed with a deploy, log a conflict, or show an admin notice, without
 * needing to call the lock service a second time.
 *
 * Factory methods:
 *   DeployLockResult::acquired($runId)                          — lock granted
 *   DeployLockResult::blocked($activeRunId, $source, $msg)     — denied by active run
 *   DeployLockResult::staleRecovered($newRunId, $staleRunId)   — stale lock cleared; now granted
 *
 * Property guide:
 *   acquired           true when this request now owns the lock.
 *   blocked            true when this request was denied.
 *   stale_lock_recovered  true when a stale lock was force-released before acquisition.
 *   active_run_id      When acquired/stale_recovered: the new run's UUID.
 *                      When blocked: the incumbent run's UUID (the one blocking us).
 *   blocking_source    trigger_type of the run that is blocking us. NULL when acquired.
 *   message            Human-readable outcome for admin notices or log messages.
 */
class DeployLockResult
{
    // -------------------------------------------------------------------------
    // Public properties (read-only by convention — no setters)
    // -------------------------------------------------------------------------

    /** @var bool True when this request now holds the lock and may proceed. */
    public bool $acquired;

    /** @var bool True when this request was denied the lock. */
    public bool $blocked;

    /**
     * @var bool True when the request was blocked but has been written to the
     *           pending queue — it will run automatically after the current
     *           deploy finishes. A subset of blocked = true.
     */
    public bool $queued;

    /**
     * @var bool True when the lock was acquired only after force-releasing a stale
     *           predecessor. Callers can use this to log an extra warning event.
     */
    public bool $stale_lock_recovered;

    /**
     * @var string|null
     *   Acquired:        UUID of the new run that now holds the lock.
     *   Blocked:         UUID of the incumbent run that prevented acquisition.
     *   Stale recovered: UUID of the new run (stale predecessor's UUID is in the event log).
     */
    public ?string $active_run_id;

    /**
     * @var string|null trigger_type of the run that is blocking this request.
     *                  NULL when the lock was acquired successfully.
     */
    public ?string $blocking_source;

    /** @var string Human-readable outcome — safe to surface in admin notices. */
    public string $message;

    // -------------------------------------------------------------------------
    // Private constructor — use static factories only
    // -------------------------------------------------------------------------

    private function __construct() {}

    // -------------------------------------------------------------------------
    // Static factories
    // -------------------------------------------------------------------------

    /**
     * Lock was granted to this request on the first attempt.
     *
     * @param string $runId UUID of the newly created run.
     * @return self
     */
    public static function acquired( string $runId ): self
    {
        $instance                      = new self();
        $instance->acquired            = true;
        $instance->blocked             = false;
        $instance->queued              = false;
        $instance->stale_lock_recovered = false;
        $instance->active_run_id       = $runId;
        $instance->blocking_source     = null;
        $instance->message             = 'Deploy lock acquired. Run started.';

        return $instance;
    }

    /**
     * Lock was denied because an active run is already in progress.
     *
     * @param string $incumbentRunId  UUID of the run that currently holds the lock.
     * @param string $incumbentSource trigger_type of the incumbent run.
     * @param string $message         Human-readable conflict description.
     * @return self
     */
    public static function blocked(
        string $incumbentRunId,
        string $incumbentSource,
        string $message
    ): self {
        $instance                      = new self();
        $instance->acquired            = false;
        $instance->blocked             = true;
        $instance->queued              = false;
        $instance->stale_lock_recovered = false;
        $instance->active_run_id       = $incumbentRunId;
        $instance->blocking_source     = $incumbentSource;
        $instance->message             = $message;

        return $instance;
    }

    /**
     * Lock was denied but the request has been written to the pending queue.
     * It will run automatically after the current deploy finishes.
     *
     * @param string $incumbentRunId  UUID of the run currently holding the lock.
     * @param string $incumbentSource trigger_type of the incumbent run.
     * @param string $message         Human-readable description.
     * @return self
     */
    public static function queued(
        string $incumbentRunId,
        string $incumbentSource,
        string $message
    ): self {
        $instance                      = new self();
        $instance->acquired            = false;
        $instance->blocked             = true;
        $instance->queued              = true;
        $instance->stale_lock_recovered = false;
        $instance->active_run_id       = $incumbentRunId;
        $instance->blocking_source     = $incumbentSource;
        $instance->message             = $message;

        return $instance;
    }

    /**
     * A stale lock was force-released and the lock was then re-acquired.
     * The run may proceed; the stale predecessor has been marked in the event log.
     *
     * @param string $newRunId   UUID of the newly created run.
     * @param string $staleRunId UUID of the stale run that was cleared.
     * @return self
     */
    public static function staleRecovered( string $newRunId, string $staleRunId ): self
    {
        $instance                      = new self();
        $instance->acquired            = true;
        $instance->blocked             = false;
        $instance->queued              = false;
        $instance->stale_lock_recovered = true;
        $instance->active_run_id       = $newRunId;
        $instance->blocking_source     = null;
        $instance->message             = sprintf(
            'Stale lock cleared (previous run %s). Deploy lock acquired.',
            $staleRunId
        );

        return $instance;
    }

    // -------------------------------------------------------------------------
    // Convenience helpers
    // -------------------------------------------------------------------------

    /**
     * True when the caller may proceed with the deploy.
     * Covers both clean acquisition and stale-recovery acquisition.
     *
     * @return bool
     */
    public function wasAcquired(): bool
    {
        return $this->acquired;
    }

    /**
     * True when the caller must NOT proceed — another run is active.
     *
     * @return bool
     */
    public function wasBlocked(): bool
    {
        return $this->blocked;
    }

    /**
     * True when the request was queued (blocked but will run automatically).
     *
     * @return bool
     */
    public function wasQueued(): bool
    {
        return $this->queued;
    }
}
