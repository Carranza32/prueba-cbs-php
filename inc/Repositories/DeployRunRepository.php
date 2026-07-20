<?php

namespace CBSNorthStar\Repositories;

use CBSNorthStar\Helpers\BuildNumberHelper;

/**
 * DeployRunRepository — persists and queries product-deploy run records and their events.
 *
 * Two tables are managed:
 *   {prefix}cbs_product_run_log    — one row per deploy run (status, counts, timing, etc.)
 *   {prefix}cbs_product_run_events — one row per event within a run (audit trail)
 *
 * Usage:
 *   DeployRunRepository::create()->createRun($runId, DeployRunRepository::TRIGGER_MANUAL);
 *   DeployRunRepository::create()->appendEvent($runId, DeployRunRepository::EVENT_RUN_STARTED, 'info', 'Run started');
 */
class DeployRunRepository
{
    // -------------------------------------------------------------------------
    // Run status constants
    // -------------------------------------------------------------------------

    const STATUS_RUNNING         = 'running';
    const STATUS_COMPLETED       = 'completed';
    const STATUS_FAILED          = 'failed';
    const STATUS_BLOCKED         = 'blocked';
    const STATUS_PARTIAL_SUCCESS = 'partial_success';
    const STATUS_STALE           = 'stale';

    // -------------------------------------------------------------------------
    // Trigger type constants
    // -------------------------------------------------------------------------

    const TRIGGER_MANUAL     = 'manual';
    const TRIGGER_HOOK       = 'hook';
    const TRIGGER_CRON       = 'cron';
    const TRIGGER_BACKGROUND = 'background';
    const TRIGGER_CLI        = 'cli';

    // -------------------------------------------------------------------------
    // Event type constants
    // -------------------------------------------------------------------------

    const EVENT_RUN_STARTED          = 'run.started';
    const EVENT_RUN_COMPLETED        = 'run.completed';
    const EVENT_RUN_FAILED           = 'run.failed';
    const EVENT_RUN_BLOCKED          = 'run.blocked';
    const EVENT_RUN_PARTIAL_SUCCESS  = 'run.partial_success';
    const EVENT_RUN_HEARTBEAT        = 'run.heartbeat';
    const EVENT_RUN_RETRIED          = 'run.retried';
    const EVENT_RUN_REQUEUED         = 'run.requeued';
    const EVENT_ADMIN_LOCK_CLEARED   = 'admin.lock_cleared';
    const EVENT_PRODUCT_SUCCESS      = 'product.save_succeeded';
    const EVENT_PRODUCT_FAILED       = 'product.save_failed';
    const EVENT_PRODUCT_SKIPPED      = 'product.skipped';
    const EVENT_LOCK_CONFLICT             = 'lock.conflict';
    const EVENT_SITE_NO_AREA             = 'site.no_area';
    const EVENT_SITE_DAYPART_NOT_MATCHED = 'site.daypart_not_matched';
    const EVENT_SITE_DAYPART_EMPTY       = 'site.daypart_empty';
    const EVENT_SITE_DAYPART_CHANGED     = 'site.daypart_changed';
    const EVENT_SITE_DAYPART_SYNCED      = 'site.daypart_synced';
    const EVENT_MENU_SKIPPED_UNCHANGED   = 'menu.skipped_unchanged';

    // -------------------------------------------------------------------------
    // Severity constants
    // -------------------------------------------------------------------------

    const SEVERITY_DEBUG    = 'debug';
    const SEVERITY_INFO     = 'info';
    const SEVERITY_WARNING  = 'warning';
    const SEVERITY_ERROR    = 'error';
    const SEVERITY_CRITICAL = 'critical';

    // -------------------------------------------------------------------------
    // Conflict type constants
    // -------------------------------------------------------------------------

    const CONFLICT_DUPLICATE_MANUAL              = 'duplicate_manual';
    const CONFLICT_HOOK_BLOCKED_BY_MANUAL        = 'hook_blocked_by_manual';
    const CONFLICT_MANUAL_BLOCKED_BY_HOOK        = 'manual_blocked_by_hook';
    const CONFLICT_MANUAL_BLOCKED_BY_BACKGROUND  = 'manual_blocked_by_background';
    const CONFLICT_BACKGROUND_BLOCKED_BY_MANUAL  = 'background_blocked_by_manual';
    const CONFLICT_STALE_LOCK_DETECTED           = 'stale_lock_detected';

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static ?self $instance = null;

    /** @var \wpdb */
    protected \wpdb $db;

    private function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    public static function create(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function runsTable(): string
    {
        return $this->db->prefix . 'cbs_product_run_log';
    }

    private function eventsTable(): string
    {
        return $this->db->prefix . 'cbs_product_run_events';
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new deploy run row with status 'running'.
     *
     * If $userId is provided and > 0, the user_login snapshot is fetched via
     * get_userdata() and stored alongside the user_id for historical accuracy.
     * The plugin version is captured from BuildNumberHelper::getBuildNumber().
     *
     * @param string      $runId         UUID for this run.
     * @param string      $triggerType   One of the TRIGGER_* constants.
     * @param int|null    $userId        WP user ID that triggered the run, or null.
     * @param string|null $triggerSource Human-readable source label (e.g. button slug, hook name).
     * @return bool True on successful insert.
     */
    public function createRun(
        string  $runId,
        string  $triggerType,
        ?int    $userId        = null,
        ?string $triggerSource = null
    ): bool {
        $userLogin     = null;
        $resolvedUserId = null;

        if ($userId !== null && $userId > 0) {
            $resolvedUserId = $userId;
            $userData = get_userdata($userId);
            if ($userData) {
                $userLogin = $userData->user_login;
            }
        }

        $pluginVersion = BuildNumberHelper::getBuildNumber();
        $now           = current_time('mysql', true);

        $result = $this->db->insert(
            $this->runsTable(),
            [
                'run_id'          => $runId,
                'trigger_type'    => $triggerType,
                'trigger_source'  => $triggerSource,
                'user_id'         => $resolvedUserId,
                'user_login'      => $userLogin,
                'status'          => self::STATUS_RUNNING,
                'plugin_version'  => $pluginVersion,
                'started_at'      => $now,
                'created_at'      => $now,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Update columns on an existing run row identified by run_id.
     *
     * Only keys present in the allow-list are written to the database.
     * Unrecognised keys are silently ignored.
     *
     * @param string $runId UUID of the run to update.
     * @param array  $data  Associative array of column => value pairs.
     * @return bool True if the update executed without a DB error.
     */
    public function updateRun(string $runId, array $data): bool
    {
        $allowedKeys = [
            'status',
            'products_attempted',
            'products_succeeded',
            'products_failed',
            'products_skipped',
            'finished_at',
            'last_heartbeat_at',
            'duration_ms',
            'error_message',
            'error_code',
            'blocked_by_run_id',
            'conflict_type',
            'retried_from_run_id',
        ];

        $filtered = array_intersect_key($data, array_flip($allowedKeys));

        if (empty($filtered)) {
            return true;
        }

        $result = $this->db->update(
            $this->runsTable(),
            $filtered,
            ['run_id' => $runId]
        );

        return $result !== false;
    }

    /**
     * Update last_heartbeat_at to the current time for the given run.
     *
     * Called periodically during SaveProduct::store() (once per site) so the
     * stale-recovery check in DeployLockService does not incorrectly kill a
     * slow but healthy deploy.
     *
     * @param string $runId UUID of the active run.
     * @return void
     */
    public function updateHeartbeat( string $runId ): bool
    {
        return $this->updateRun( $runId, [ 'last_heartbeat_at' => current_time( 'mysql', true ) ] );
    }

    /**
     * Append one event to the run's event log.
     *
     * $context is JSON-encoded when non-empty; otherwise NULL is stored to
     * keep the column clean and avoid wasteful empty-object rows.
     *
     * @param string      $runId      UUID of the parent run.
     * @param string      $eventType  One of the EVENT_* constants.
     * @param string      $severity   One of the SEVERITY_* constants.
     * @param string      $message    Human-readable description of the event.
     * @param array       $context    Optional structured key/value payload.
     * @param int|null    $productId  WC product ID when event is product-scoped.
     * @param string|null $productSku Product SKU when event is product-scoped.
     * @return bool True on successful insert.
     */
    public function appendEvent(
        string  $runId,
        string  $eventType,
        string  $severity,
        string  $message,
        array   $context    = [],
        ?int    $productId  = null,
        ?string $productSku = null
    ): bool {
        $result = $this->db->insert(
            $this->eventsTable(),
            [
                'run_id'      => $runId,
                'event_type'  => $eventType,
                'severity'    => $severity,
                'message'     => $message,
                'product_id'  => $productId,
                'product_sku' => $productSku,
                'context'     => !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : null,
                'occurred_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Fetch a single run row by its UUID.
     *
     * @param string $runId UUID of the run.
     * @return array|null Associative row array, or null if not found.
     */
    public function findByRunId(string $runId): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->runsTable()} WHERE run_id = %s LIMIT 1",
                $runId
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Return the most recently finished run with a terminal status (not 'running').
     *
     * Used by handleStart() to report the previous run result to users starting a new deploy.
     *
     * @param string|null $excludeRunId Exclude this run_id from results (pass the new run's ID).
     * @return array|null Associative run row, or null if none found.
     */
    public function findLastFinishedRun( ?string $excludeRunId = null ): ?array
    {
        $terminalStatuses = [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_PARTIAL_SUCCESS,
            self::STATUS_STALE,
        ];
        $placeholders = implode( ', ', array_fill( 0, count( $terminalStatuses ), '%s' ) );

        if ( $excludeRunId !== null ) {
            $row = $this->db->get_row(
                $this->db->prepare(
                    "SELECT * FROM {$this->runsTable()}
                     WHERE status IN ({$placeholders})
                       AND run_id != %s
                     ORDER BY finished_at DESC
                     LIMIT 1",
                    array_merge( $terminalStatuses, [ $excludeRunId ] )
                ),
                ARRAY_A
            );
        } else {
            $row = $this->db->get_row(
                $this->db->prepare(
                    "SELECT * FROM {$this->runsTable()}
                     WHERE status IN ({$placeholders})
                     ORDER BY finished_at DESC
                     LIMIT 1",
                    $terminalStatuses
                ),
                ARRAY_A
            );
        }

        return $row ?: null;
    }

    /**
     * Return the most recently started run that is still in 'running' status.
     *
     * Used by locking logic to detect an in-progress deploy before starting a new one.
     *
     * @return array|null Associative run row, or null if no active run exists.
     */
    public function findActiveRun(): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->runsTable()} WHERE status = %s ORDER BY started_at DESC LIMIT 1",
                self::STATUS_RUNNING
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Return a paginated list of run rows for the admin report list view.
     *
     * Supported filter keys:
     *   status        — exact status string (one of the STATUS_* constants)
     *   trigger_type  — exact trigger type string
     *   date_from     — inclusive lower bound (Y-m-d)
     *   date_to       — inclusive upper bound (Y-m-d)
     *   has_errors    — bool; when true, only runs where products_failed > 0 are returned
     *
     * @param array $filters  Associative filter map (see above).
     * @param int   $perPage  Number of rows per page.
     * @param int   $offset   Row offset for the current page.
     * @return array Array of associative run rows.
     */
    public function getRunSummaries(array $filters, int $perPage, int $offset): array
    {
        [$where, $values] = $this->buildWhere($filters);

        $sql = "SELECT * FROM {$this->runsTable()} r {$where} ORDER BY r.created_at DESC LIMIT %d OFFSET %d";

        $allValues = array_merge($values, [$perPage, $offset]);

        return $this->db->get_results(
            $this->db->prepare($sql, $allValues),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Count runs matching filters; used for pagination calculations.
     *
     * Accepts the same filter keys as getRunSummaries().
     *
     * @param array $filters Associative filter map.
     * @return int Total matching run count.
     */
    public function countRuns(array $filters): int
    {
        [$where, $values] = $this->buildWhere($filters);

        $sql = "SELECT COUNT(*) FROM {$this->runsTable()} r {$where}";

        return (int) ($values
            ? $this->db->get_var($this->db->prepare($sql, $values))
            : $this->db->get_var($sql));
    }

    /**
     * Return aggregate statistics for the stat-card row on the admin report page.
     *
     * A single GROUP BY query is used; no N+1 queries.
     *
     * @param array $filters Optional filters (same keys as getRunSummaries()).
     * @return array Keys: total, completed, failed, blocked, partial_success.
     */
    public function getStats(array $filters = []): array
    {
        [$where, $values] = $this->buildWhere($filters);

        $sql = "
            SELECT
                COUNT(*)                                          AS total,
                SUM(r.status = 'completed')                       AS completed,
                SUM(r.status = 'failed')                          AS failed,
                SUM(r.status = 'blocked')                         AS blocked,
                SUM(r.status = 'partial_success')                 AS partial_success
            FROM {$this->runsTable()} r
            {$where}
        ";

        $row = $values
            ? $this->db->get_row($this->db->prepare($sql, $values), ARRAY_A)
            : $this->db->get_row($sql, ARRAY_A);

        if (!$row) {
            return [
                'total'          => 0,
                'completed'      => 0,
                'failed'         => 0,
                'blocked'        => 0,
                'partial_success' => 0,
            ];
        }

        return [
            'total'           => (int) $row['total'],
            'completed'       => (int) $row['completed'],
            'failed'          => (int) $row['failed'],
            'blocked'         => (int) $row['blocked'],
            'partial_success' => (int) $row['partial_success'],
        ];
    }

    /**
     * Return all event rows for a run, ordered chronologically.
     *
     * @param string $runId UUID of the parent run.
     * @return array Array of associative event rows.
     */
    public function getEventsForRun(string $runId): array
    {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->eventsTable()} WHERE run_id = %s ORDER BY occurred_at ASC",
                $runId
            ),
            ARRAY_A
        ) ?: [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a parameterised WHERE clause from a filters array.
     *
     * Returns [$whereSQL, $valuesArray]. The runs table is aliased as `r`.
     *
     * Supported filter keys:
     *   status         — exact match on r.status
     *   trigger_type   — exact match on r.trigger_type
     *   date_from      — r.created_at >= 'Y-m-d 00:00:00'
     *   date_to        — r.created_at <= 'Y-m-d 23:59:59'
     *   has_errors     — bool; when truthy, adds r.products_failed > 0
     *   product_search — numeric: match exact product_id in events;
     *                    string:  LIKE match on product_sku in events.
     *                    Uses a subquery so only runs that touched the product appear.
     *
     * @param array $filters Raw filter map from the caller.
     * @return array{0: string, 1: array} Two-element array: [whereSQL, values].
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $values  = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'r.status = %s';
            $values[]  = sanitize_text_field($filters['status']);
        }

        if (!empty($filters['trigger_type'])) {
            $clauses[] = 'r.trigger_type = %s';
            $values[]  = sanitize_text_field($filters['trigger_type']);
        }

        if (!empty($filters['date_from'])) {
            $clauses[] = 'r.created_at >= %s';
            $values[]  = sanitize_text_field($filters['date_from']) . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = 'r.created_at <= %s';
            $values[]  = sanitize_text_field($filters['date_to']) . ' 23:59:59';
        }

        if (!empty($filters['has_errors'])) {
            $clauses[] = 'r.products_failed > 0';
        }

        if (!empty($filters['product_search'])) {
            $search = $filters['product_search'];
            $evt    = $this->eventsTable();

            if (ctype_digit((string) $search)) {
                // Numeric — treat as exact product_id lookup.
                $clauses[] = "r.run_id IN (SELECT e.run_id FROM {$evt} e WHERE e.product_id = %d)";
                $values[]  = (int) $search;
            } else {
                // Non-numeric — LIKE match on product_sku.
                $clauses[] = "r.run_id IN (SELECT e.run_id FROM {$evt} e WHERE e.product_sku LIKE %s)";
                $values[]  = '%' . $this->db->esc_like( sanitize_text_field($search) ) . '%';
            }
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        return [$where, $values];
    }
}
