<?php

namespace CBSNorthStar\Repositories;

use CBSNorthStar\Helpers\SiteClock;
use CBSNorthStar\Logger\CBSLogger;

class DaypartMenusRepository
{
    protected $db;
    private static $instance = null;
    private function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;

        return $this;
    }

    public static function create(): ?DaypartMenusRepository
    {
        if (self::$instance === null) {
            self::$instance = new DaypartMenusRepository();
        }

        return self::$instance;
    }

    public function updateMenu(string $menuId, string $daypartId , string $startTime, string $endTime , string $days , int $displayOrder , string $siteId)
    {
        $newData = array(
            'menuid' => $menuId,
            'daypartid' => $daypartId,
            'starttime' => $startTime,
            'endtime' => $endTime,
            'days'    => $days,
            'displayorder' => $displayOrder,
            'siteid' => $siteId
        );

        $format = array('%s', '%s', '%s' , '%s' ,'%s' , '%d' , '%s');
        // The natural key is (siteid, menuid, daypartid). displayorder is a
        // mutable attribute, NOT part of the identity — keying the upsert on it
        // caused a fresh INSERT whenever displayorder changed, producing
        // duplicate rows for the same menu/daypart. siteid MUST be in the WHERE
        // or the update clobbers the same menu/daypart rows of every site.
        $whereFormat = ['%s', '%s', '%s'];

        $where = array(
            'siteid'    => $siteId,
            'menuid'    => $menuId,
            'daypartid' => $daypartId,
        );


        if(!empty($this->getMenus($menuId , $daypartId , $siteId))){
            return $this->db->update('cbs_daypartmenus', $newData, $where, $format, $whereFormat);
        }else{
            return $this->db->insert('cbs_daypartmenus', $newData, $format);
        }

    }

    public function getMenus($menuId, $daypartId, $siteId){
        $query = $this->db->prepare(
            'SELECT * FROM cbs_daypartmenus
            WHERE menuid = %s AND daypartid = %s AND siteid = %s',
            [$menuId, $daypartId, $siteId]
        );

        return  $this->db->get_results($query);
    }

    /**
     * Differentially sync ALL daypart-menu rows for a site (true upsert).
     *
     * Compares the built state against the existing rows on the natural key
     * (siteid, menuid, daypartid) and issues only the writes that are needed:
     * unchanged rows are NOT touched, changed rows are UPDATEd in place,
     * new rows are INSERTed, and rows that disappeared upstream get targeted
     * DELETEs by id. Duplicate rows for the same key (legacy data predating
     * the unique index) are removed on the fly.
     *
     * This replaces the previous "delete everything, re-insert inside a
     * transaction" swap. The transaction wrapper is kept, but the diff makes
     * the safety engine-independent: on MyISAM tables (where transactions are
     * silent no-ops and the old swap's delete window was real) the table can
     * still never be observed empty, because no statement ever touches rows
     * that aren't actually changing. On the common no-change deploy this
     * issues zero writes.
     *
     * Empty-set guard: if $rows is empty we do NOT wipe existing rows — an
     * empty rebuild almost always means an upstream fetch/parse problem, not an
     * intentionally menu-less site.
     *
     * @param string  $siteId
     * @param array[] $rows   Each row: menuid, daypartid, starttime, endtime,
     *                        days, displayorder, siteid.
     * @return bool True on commit; false on rollback or intentional no-op.
     */
    public function replaceSiteMenus(string $siteId, array $rows): bool
    {
        if (empty($rows)) {
            // Nothing to write — leave existing rows untouched rather than
            // blanking the table on a suspected fetch failure.
            return false;
        }

        $format = array('%s', '%s', '%s', '%s', '%s', '%d', '%s');

        // Index incoming rows by natural key.
        $incoming = [];
        foreach ($rows as $row) {
            $incoming[$row['menuid'] . '|' . $row['daypartid']] = $row;
        }

        $existing = $this->db->get_results(
            $this->db->prepare('SELECT * FROM cbs_daypartmenus WHERE siteid = %s', $siteId)
        );

        $this->db->query('START TRANSACTION');

        $ok   = true;
        $seen = [];

        foreach ((array) $existing as $row) {
            $key = $row->menuid . '|' . $row->daypartid;

            // Removed upstream, or a duplicate of a key already handled —
            // targeted delete by primary key.
            if (!isset($incoming[$key]) || isset($seen[$key])) {
                if ($this->db->delete('cbs_daypartmenus', ['id' => (int) $row->id], ['%d']) === false) {
                    $ok = false;
                    break;
                }
                continue;
            }

            $seen[$key] = true;
            $new        = $incoming[$key];

            $changed =
                   !$this->sameTime($row->starttime, $new['starttime'])
                || !$this->sameTime($row->endtime, $new['endtime'])
                || (string) $row->days !== (string) $new['days']
                || (int) $row->displayorder !== (int) $new['displayorder'];

            if ($changed) {
                if ($this->db->update('cbs_daypartmenus', $new, ['id' => (int) $row->id], $format, ['%d']) === false) {
                    $ok = false;
                    break;
                }
            }
            // Identical row → no write at all.
        }

        if ($ok) {
            foreach ($incoming as $key => $new) {
                if (isset($seen[$key])) {
                    continue;
                }
                if ($this->db->insert('cbs_daypartmenus', $new, $format) === false) {
                    $ok = false;
                    break;
                }
            }
        }

        if (!$ok) {
            $this->db->query('ROLLBACK');
            return false;
        }

        $this->db->query('COMMIT');
        return true;
    }

    /**
     * Compare two time values tolerant of format differences — the TIME
     * column reads back as 'HH:MM:SS' while the API may send 'HH:MM'.
     * Falls back to a strict string compare when either side is unparseable
     * (a false "changed" verdict only costs one redundant UPDATE).
     */
    private function sameTime($a, $b): bool
    {
        $ta = strtotime((string) $a);
        $tb = strtotime((string) $b);

        if ($ta !== false && $tb !== false) {
            return date('H:i:s', $ta) === date('H:i:s', $tb);
        }

        return (string) $a === (string) $b;
    }
    public function deleteMenus($siteId){
        $query = $this->db->prepare('DELETE FROM cbs_daypartmenus where siteid = %s',[
            $siteId
          ]);
      
          $result = $this->db->query($query);
          return $result !== false;
    }
    public function getSiteDaypartMenus($siteId){
        $query = $this->db->prepare('SELECT * FROM cbs_daypartmenus where siteid = %s ORDER BY displayorder ASC, id ASC',[
            $siteId
          ]);
          return $this->db->get_results($query);
    }

    /**
     * Request-scoped memo of {@see self::getSiteDaypartMenus()} for the render/resolve path.
     *
     * {@see self::getActiveDaypartRow()} runs several times per menu page load (category nav,
     * the product-scope query filter, cart hooks) for the same site, each re-reading
     * cbs_daypartmenus (OE-26548). The schedule is constant within a request, so memoise it.
     *
     * Deploy callers keep using the uncached getSiteDaypartMenus() directly, so they always
     * read their own freshly-written rows within the same process.
     *
     * @param  mixed $siteId Site identifier (uuid/string).
     * @return array<int,\stdClass>
     */
    private function getSiteDaypartMenusMemoized($siteId) {
        static $memo = array();
        $key = (string) $siteId;
        if ( ! array_key_exists( $key, $memo ) ) {
            $memo[ $key ] = $this->getSiteDaypartMenus( $siteId );
        }
        return $memo[ $key ];
    }

    /**
     * Resolve the active daypart menu id for a site at the current (or overridden) moment.
     *
     * Thin shell: reads the schedule, decides which wall-clock day/time to evaluate, and
     * delegates the window selection to the pure {@see self::pickActiveMenu()}.
     *
     * "Now" is taken in the SITE's own timezone (via {@see SiteClock}); a missing/unmapped
     * timezone falls back to WordPress current_time(). QA time-travel overrides
     * ($overrideTime / $overrideDay from oloNavSlotOverrides() or the REST date/time params)
     * are used verbatim as wall-clock values with no timezone conversion.
     *
     * @return string|null Menu id, or null when no daypart window matches the moment.
     */
    public function getActiveDaypartMenu($siteId, $overrideTime = null, $overrideDay = null){
        $row = $this->getActiveDaypartRow($siteId, $overrideTime, $overrideDay);

        return null !== $row ? (string) $row->menuid : null;
    }

    /**
     * Resolve the active daypart ROW for a site at the current (or overridden) moment.
     *
     * Same schedule read + day/time resolution as {@see self::getActiveDaypartMenu()},
     * but returns the whole matched row so callers can read the window's `endtime`
     * (and other columns) alongside the menu id — e.g. OE-26492's active-daypart
     * endpoint, which reports when the current daypart ends so the client can detect
     * a boundary crossing for an in-progress order.
     *
     * @return \stdClass|null Matched daypart row, or null when no window matches.
     */
    public function getActiveDaypartRow($siteId, $overrideTime = null, $overrideDay = null): ?\stdClass {
        $siteMenus = $this->getSiteDaypartMenusMemoized($siteId);

        // Fall back to snapshot stored in wp_options when live table is empty during a deploy window.
        // Reads via $wpdb directly (bypasses object cache) so it works even when external object
        // cache (Redis) is broken — same pattern as DeployLockService::readDbLock().
        if ( empty( $siteMenus ) ) {
            $raw = $this->db->get_var(
                $this->db->prepare(
                    "SELECT option_value FROM {$this->db->options} WHERE option_name = %s",
                    'cbs_daypart_snapshot_' . $siteId
                )
            );
            $snapshotRecord = $raw ? maybe_unserialize( $raw ) : null;
            if ( is_array( $snapshotRecord ) && ! empty( $snapshotRecord['data'] ) ) {
                $expired = isset( $snapshotRecord['expires_at'] ) && time() > (int) $snapshotRecord['expires_at'];
                if ( ! $expired ) {
                    CBSLogger::general()->warning( 'getActiveDaypartRow — live table empty, serving from snapshot', [
                        'site_id'        => $siteId,
                        'snapshot_count' => count( (array) $snapshotRecord['data'] ),
                        'expires_in'     => (int) $snapshotRecord['expires_at'] - time(),
                    ] );
                    $siteMenus = $snapshotRecord['data'];
                }
            }
        }

        // QA time-travel overrides are used verbatim (no timezone conversion); otherwise
        // evaluate "now" in the site's own timezone via SiteClock (fails soft to WP time).
        if (null !== $overrideTime && null !== $overrideDay) {
            $checkTime = $overrideTime;
            $checkDay  = $overrideDay;
        } else {
            $now       = SiteClock::nowForSite((string) $siteId);
            $checkTime = $overrideTime ?? $now->format('H:i:s');
            $checkDay  = $overrideDay  ?? $now->format('l');
        }

        return self::pickActiveRow($siteMenus, $checkTime, $checkDay);
    }

    /**
     * Pick the active menu from a site's daypart rows for a given wall-clock day + time.
     *
     * PURE: no DB, no WordPress, no current_time() — so the window logic is unit-testable
     * in isolation (mirrors KitchenHours::isOpenAt). Selection rules:
     *
    *  - A row matches when $checkDay is in its days AND $checkTime falls in its window
    *    (start-inclusive, end-exclusive; overnight windows wrap past midnight).
     *  - Among matches the LOWEST displayorder wins. displayorder is the operator's explicit
     *    priority control: they force a menu by stacking it above the broader/catch-all
     *    windows (smaller displayorder). Window length is not a factor.
     *  - No matching window → null (caller fails closed: render nothing). A single-menu
     *    site is NOT special-cased off-hours — it too respects its window (upstream
     *    "fix timeslot hours", commit 972886c2).
     *
     * @param array  $rows      Daypart rows (stdClass: days, starttime, endtime, displayorder, menuid).
     * @param string $checkTime Wall-clock time "HH:MM[:SS]".
     * @param string $checkDay  Full English weekday name (e.g. "Monday").
     */
    public static function pickActiveMenu(array $rows, string $checkTime, string $checkDay): ?string
    {
        $row = self::pickActiveRow($rows, $checkTime, $checkDay);

        return null !== $row ? (string) $row->menuid : null;
    }

    /**
     * Pick the active daypart ROW for a given wall-clock day + time.
     *
     * Same PURE selection rules as {@see self::pickActiveMenu()} (which delegates here),
     * but returns the whole matched row object instead of just its menu id, so callers
     * that also need the window's `endtime`/`daypartid` do not have to re-run the loop.
     *
     * @param array  $rows      Daypart rows (stdClass: days, starttime, endtime, displayorder, menuid).
     * @param string $checkTime Wall-clock time "HH:MM[:SS]".
     * @param string $checkDay  Full English weekday name (e.g. "Monday").
     * @return object|null The matched row, or null when no window matches.
     */
    public static function pickActiveRow(array $rows, string $checkTime, string $checkDay)
    {
        $nowMin = self::toMinutes($checkTime);
        if (null === $nowMin) {
            return null;
        }

        $best      = null; // matched row object
        $bestOrder = null; // its displayorder

        foreach ($rows as $row) {
            if (!self::daysMatch($checkDay, (string) $row->days)) {
                continue;
            }

            $start = self::toMinutes((string) $row->starttime);
            $end   = self::toMinutes((string) $row->endtime);

            if (null === $start || null === $end) {
                continue;
            }

            $matches = ($end > $start)
                ? ($nowMin >= $start && $nowMin < $end)   // same-day window
                : ($nowMin >= $start || $nowMin < $end);  // overnight window (or start == end => all day)

            if (!$matches) {
                continue;
            }

            // Lowest displayorder wins. displayorder is the operator's explicit priority knob:
            // they force a menu by giving it a smaller order than the broader/catch-all windows.
            // Rows already arrive ORDER BY displayorder ASC, so the strict < keeps the first match.
            $order = (int) $row->displayorder;
            if (null === $best || $order < $bestOrder) {
                $best      = $row;
                $bestOrder = $order;
            }
        }

        return $best;
    }

    /**
     * Minutes since midnight for a "HH:MM[:SS]" wall-clock string (seconds ignored).
     */
    private static function toMinutes(string $time): ?int
    {
        $time = trim($time);
        if (1 !== preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $parts)) {
            return null;
        }

        $hours   = (int) $parts[1];
        $minutes = (int) $parts[2];

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    /**
     * Whether $checkDay is listed in a daypart's days string.
     *
     * Hardened against separator/whitespace/case variation: splits on commas, trims each
     * entry, and compares case-insensitively (full English weekday names).
     */
    private static function daysMatch(string $checkDay, string $daysCsv): bool
    {
        foreach (explode(',', $daysCsv) as $day) {
            if (0 === strcasecmp(trim($day), trim($checkDay))) {
                return true;
            }
        }

        return false;
    }
}