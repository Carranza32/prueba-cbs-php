<?php

namespace CBSNorthStar\Repositories;

/**
 * SessionEventRepository — persists and queries order lifecycle events per browser session.
 *
 * Each row in {prefix}cbs_session_events represents one step in the order flow
 * (validation, submission, payment, etc.) tagged with the session's transaction_ref.
 *
 * Usage:
 *   SessionEventRepository::create()->logEvent($ref, self::EVENT_ORDER_SUBMITTED, self::STATUS_SUCCESS, [...]);
 */
class SessionEventRepository
{
    // -------------------------------------------------------------------------
    // Event type constants
    // -------------------------------------------------------------------------

    const EVENT_ORDER_VALIDATED   = 'order_validated';
    const EVENT_ORDER_SUBMITTED   = 'order_submitted';
    const EVENT_ORDER_FINALIZED   = 'order_finalized';
    const EVENT_PAYMENT_PROCESSED = 'payment_processed';
    const EVENT_ORDER_FAILED      = 'order_failed';
    const EVENT_PAYMENT_FAILED    = 'payment_failed';
    const EVENT_VALIDATION_FAILED = 'validation_failed';

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED  = 'failed';

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static ?self $instance = null;

    /** @var \wpdb */
    protected $db;

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

    private function table(): string
    {
        return $this->db->prefix . 'cbs_session_events';
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert one lifecycle event row.
     * Silently skips when $transactionRef is empty (CLI / REST contexts with no session cookie).
     */
    public function logEvent(
        string  $transactionRef,
        string  $eventType,
        string  $status,
        array   $details    = [],
        ?int    $wcOrderId  = null,
        ?string $siteId     = null
    ): void {
        if (empty($transactionRef)) {
            return;
        }

        $this->db->insert(
            $this->table(),
            [
                'transaction_ref' => $transactionRef,
                'event_type'      => $eventType,
                'wc_order_id'     => $wcOrderId,
                'site_id'         => $siteId,
                'status'          => $status,
                'details'         => !empty($details) ? json_encode($details, JSON_UNESCAPED_SLASHES) : null,
                'created_at'      => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }

    // -------------------------------------------------------------------------
    // Read — list view
    // -------------------------------------------------------------------------

    /**
     * Returns one aggregated row per transaction_ref, ordered by most recent activity first.
     * Includes a `has_failure` flag (1 if any event in the session is failed).
     * Includes `event_types` as a comma-separated list of event type names.
     */
    public function getSessionSummaries(array $filters, int $perPage, int $offset): array
    {
        [$where, $values] = $this->buildWhere($filters);

        $having = '';
        if (!empty($filters['status'])) {
            $having = $filters['status'] === 'failed'
                ? 'HAVING has_failure = 1'
                : 'HAVING has_failure = 0';
        }

        $sql = "
            SELECT
                e.transaction_ref,
                MIN(e.created_at)  AS first_seen,
                MAX(e.created_at)  AS last_seen,
                MAX(e.wc_order_id) AS wc_order_id,
                MAX(e.site_id)     AS site_id,
                GROUP_CONCAT(e.event_type ORDER BY e.created_at SEPARATOR ',') AS event_types,
                MAX(CASE WHEN e.status = 'failed' THEN 1 ELSE 0 END)          AS has_failure,
                MAX(JSON_UNQUOTE(JSON_EXTRACT(e.details, '$.check_id')))       AS check_id,
                MAX(JSON_UNQUOTE(JSON_EXTRACT(e.details, '$.check_number')))  AS check_number
            FROM {$this->table()} e
            {$where}
            GROUP BY e.transaction_ref
            {$having}
            ORDER BY last_seen DESC
            LIMIT %d OFFSET %d
        ";

        $allValues = array_merge($values, [$perPage, $offset]);
        return $this->db->get_results(
            $this->db->prepare($sql, $allValues),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Count of distinct sessions matching filters (used for pagination).
     */
    public function countSessions(array $filters): int
    {
        [$where, $values] = $this->buildWhere($filters);

        $sql = "SELECT COUNT(DISTINCT e.transaction_ref) FROM {$this->table()} AS e {$where}";

        return (int) ($values
            ? $this->db->get_var($this->db->prepare($sql, $values))
            : $this->db->get_var($sql));
    }

    /**
     * Stat card counts: total sessions, sessions with no failures, sessions with at least one failure.
     */
    public function getStats(): array
    {
        $rows = $this->db->get_results("
            SELECT
                COUNT(*)              AS total,
                SUM(has_failure = 0)  AS successful,
                SUM(has_failure = 1)  AS failed
            FROM (
                SELECT MAX(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS has_failure
                FROM {$this->table()}
                GROUP BY transaction_ref
            ) sub
        ", ARRAY_A);

        return $rows[0] ?? ['total' => 0, 'successful' => 0, 'failed' => 0];
    }

    // -------------------------------------------------------------------------
    // Read — detail view
    // -------------------------------------------------------------------------

    /**
     * All events for a single session, oldest first.
     */
    public function getEventsForSession(string $transactionRef): array
    {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table()} WHERE transaction_ref = %s ORDER BY created_at ASC",
                $transactionRef
            ),
            ARRAY_A
        ) ?: [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a WHERE clause from the filters array.
     * Returns [$whereSQL, $valuesArray].
     *
     * Supported filter keys:
     *   date_from  — inclusive lower bound (Y-m-d)
     *   date_to    — inclusive upper bound (Y-m-d)
     *   site_id    — exact match
     *   status     — 'failed' or 'successful' (applied as HAVING on the aggregated has_failure flag, not row-level)
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $values  = [];

        if (!empty($filters['date_from'])) {
            $clauses[] = 'e.created_at >= %s';
            $values[]  = sanitize_text_field($filters['date_from']) . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $clauses[] = 'e.created_at <= %s';
            $values[]  = sanitize_text_field($filters['date_to']) . ' 23:59:59';
        }
        if (!empty($filters['site_id'])) {
            $clauses[] = 'e.site_id = %s';
            $values[]  = sanitize_text_field($filters['site_id']);
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        return [$where, $values];
    }
}
