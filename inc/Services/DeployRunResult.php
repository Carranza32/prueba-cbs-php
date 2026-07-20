<?php

namespace CBSNorthStar\Services;

/**
 * DeployRunResult — immutable value object returned by DeployOrchestrator::run().
 *
 * Represents the final outcome of a single deploy attempt from any trigger source.
 * Callers use this to decide what to return to the user (AJAX response, HTTP status,
 * log line) without knowing anything about locking, logging, or run lifecycle.
 *
 * Status values:
 *   completed — store() returned success=true; all products processed without error.
 *   failed    — store() returned success=false, or threw an exception, or returned
 *               an unexpected value. run_id is set; the run is closed in the DB.
 *   blocked   — the deploy lock was already held; store() was never called.
 *               run_id is the INCUMBENT run's ID, not a new one.
 *
 * Quick usage:
 *   if ( $result->wasSuccessful() ) { ... $result->message ... }
 *   if ( $result->wasBlocked() )    { ... $result->message ... }
 *   if ( ! $result->wasSuccessful() ) { error_log( $result->debugSummary() ); }
 */
class DeployRunResult
{
    // -------------------------------------------------------------------------
    // Status constants — match DeployRunRepository::STATUS_* where applicable
    // -------------------------------------------------------------------------

    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_BLOCKED   = 'blocked';
    const STATUS_QUEUED    = 'queued';

    // -------------------------------------------------------------------------
    // Public properties (read-only by convention)
    // -------------------------------------------------------------------------

    /**
     * @var string UUID of the run that was created.
     *             When blocked: UUID of the INCUMBENT run (the one that blocked us).
     *             Empty string in the rare case a run could not be created at all.
     */
    public string $run_id;

    /** @var string One of STATUS_COMPLETED, STATUS_FAILED, STATUS_BLOCKED. */
    public string $status;

    /** @var bool True only when status = completed. */
    public bool $success;

    /**
     * @var string Normalized, human-readable outcome string.
     *             Safe for admin notices and AJAX error messages.
     *             Always a string — never an array.
     */
    public string $message;

    /**
     * @var array Original message array from SaveProduct::store() when
     *            it returned per-site messages. Empty for exceptions and
     *            blocked outcomes. Useful for detailed admin display.
     */
    public array $raw_messages;

    /**
     * @var string|null Exception class name when the run failed due to an
     *                  uncaught Throwable. Null in all other cases.
     */
    public ?string $exception_class;

    // -------------------------------------------------------------------------
    // Private constructor — use static factories only
    // -------------------------------------------------------------------------

    private function __construct() {}

    // -------------------------------------------------------------------------
    // Static factories
    // -------------------------------------------------------------------------

    /**
     * store() returned success = true.
     *
     * @param string       $runId       UUID of the completed run.
     * @param string|array $rawMessages Raw message(s) from store().
     * @return self
     */
    public static function completed( string $runId, $rawMessages = [] ): self
    {
        $instance                  = new self();
        $instance->run_id          = $runId;
        $instance->status          = self::STATUS_COMPLETED;
        $instance->success         = true;
        $instance->raw_messages    = is_array( $rawMessages ) ? $rawMessages : [];
        $instance->message         = self::flattenMessages( $rawMessages, 'Deploy completed successfully.' );
        $instance->exception_class = null;

        return $instance;
    }

    /**
     * store() returned success = false, returned an unexpected value,
     * or threw an exception.
     *
     * @param string          $runId        UUID of the failed run.
     * @param string          $message      Normalized error message.
     * @param array           $rawMessages  Per-site messages when available.
     * @param \Throwable|null $exception    The caught exception, if any.
     * @return self
     */
    public static function failed(
        string     $runId,
        string     $message,
        array      $rawMessages = [],
        ?\Throwable $exception  = null
    ): self {
        $instance                  = new self();
        $instance->run_id          = $runId;
        $instance->status          = self::STATUS_FAILED;
        $instance->success         = false;
        $instance->message         = $message ?: 'Deploy failed.';
        $instance->raw_messages    = $rawMessages;
        $instance->exception_class = $exception ? get_class( $exception ) : null;

        return $instance;
    }

    /**
     * The deploy lock was already held — store() was never called.
     *
     * @param string $incumbentRunId UUID of the run currently holding the lock.
     * @param string $message        Human-readable conflict description from the lock service.
     * @return self
     */
    public static function blocked( string $incumbentRunId, string $message ): self
    {
        $instance                  = new self();
        $instance->run_id          = $incumbentRunId;
        $instance->status          = self::STATUS_BLOCKED;
        $instance->success         = false;
        $instance->message         = $message ?: 'A deploy is already in progress.';
        $instance->raw_messages    = [];
        $instance->exception_class = null;

        return $instance;
    }

    // -------------------------------------------------------------------------
    // Convenience helpers
    // -------------------------------------------------------------------------

    /** @return bool True only when the deploy completed without error. */
    public function wasSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** @return bool True when the deploy was denied by an active lock. */
    public function wasBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    /** @return bool True when the deploy was queued to run after the active one finishes. */
    public function wasQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    /**
     * The deploy lock was held but the request was queued — will run automatically.
     *
     * @param string $incumbentRunId UUID of the run currently holding the lock.
     * @param string $message        Human-readable description.
     * @return self
     */
    public static function queued( string $incumbentRunId, string $message ): self
    {
        $instance                  = new self();
        $instance->run_id          = $incumbentRunId;
        $instance->status          = self::STATUS_QUEUED;
        $instance->success         = false;
        $instance->message         = $message ?: 'Deploy queued — will run after the current one finishes.';
        $instance->raw_messages    = [];
        $instance->exception_class = null;

        return $instance;
    }

    /** @return bool True when the deploy ran but failed (exception or store() error). */
    public function wasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * One-line summary for log files and debug output.
     * Does not include stack traces.
     *
     * @return string
     */
    public function debugSummary(): string
    {
        $parts = [
            'status='  . $this->status,
            'run_id='  . ( $this->run_id ?: 'none' ),
            'message=' . $this->message,
        ];

        if ( $this->exception_class ) {
            $parts[] = 'exception=' . $this->exception_class;
        }

        return implode( ' | ', $parts );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a string or array of messages to a single plain string.
     *
     * @param string|array $messages
     * @param string       $fallback Used when $messages is empty.
     * @return string
     */
    private static function flattenMessages( $messages, string $fallback ): string
    {
        if ( is_array( $messages ) ) {
            $messages = array_filter( array_map( 'strval', $messages ) );
            return $messages ? implode( '; ', $messages ) : $fallback;
        }

        $str = trim( (string) $messages );
        return $str !== '' ? $str : $fallback;
    }
}
