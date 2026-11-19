<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Two tables: scans (one run over all keywords) and points (one DataForSEO task = keyword × grid point).
 */
class MGT_DB
{
    public static function scans(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mgt_scans';
    }

    public static function points(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mgt_points';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $scans = self::scans();
        $points = self::points();
        dbDelta("CREATE TABLE {$scans} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            finished_at datetime NULL,
            status varchar(20) NOT NULL DEFAULT 'running',
            source varchar(20) NOT NULL DEFAULT 'manual',
            config longtext NOT NULL,
            tasks_total int(10) unsigned NOT NULL DEFAULT 0,
            tasks_done int(10) unsigned NOT NULL DEFAULT 0,
            tasks_failed int(10) unsigned NOT NULL DEFAULT 0,
            cost decimal(12,5) NOT NULL DEFAULT 0,
            error text NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) {$charset};");
        dbDelta("CREATE TABLE {$points} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            keyword varchar(191) NOT NULL,
            point_index smallint(5) unsigned NOT NULL,
            lat decimal(9,6) NOT NULL,
            lng decimal(9,6) NOT NULL,
            task_id varchar(64) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'new',
            rank_pos smallint(5) unsigned NULL,
            result longtext NULL,
            error varchar(255) NULL,
            checked_at datetime NULL,
            PRIMARY KEY  (id),
            KEY scan_status (scan_id,status),
            KEY task_id (task_id)
        ) {$charset};");
        update_option('mgt_db_version', MGT_DB_VERSION, false);
    }

    public static function uninstall(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::points()); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DROP TABLE IF EXISTS ' . self::scans()); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public static function scan(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::scans() . ' WHERE id = %d', $id), ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['config'] = json_decode((string) $row['config'], true) ?: [];
        return $row;
    }

    public static function scans_list(int $limit = 100): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT id, created_at, finished_at, status, source, tasks_total, tasks_done, tasks_failed, cost, error FROM ' . self::scans() . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A) ?: [];
    }

    /** Scans that have finished with at least some data, newest first. */
    public static function finished_scans(int $limit = 100): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT id, created_at, status FROM " . self::scans() . " WHERE status IN ('done','partial') ORDER BY id DESC LIMIT %d", $limit), ARRAY_A) ?: [];
    }

    public static function running_scans(): array
    {
        global $wpdb;
        return $wpdb->get_col("SELECT id FROM " . self::scans() . " WHERE status = 'running' ORDER BY id ASC") ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public static function scan_points(int $scan_id, ?string $keyword = null): array
    {
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::points() . ' WHERE scan_id = %d';
        $args = [$scan_id];
        if ($keyword !== null) {
            $sql .= ' AND keyword = %s';
            $args[] = $keyword;
        }
        $sql .= ' ORDER BY keyword ASC, point_index ASC';
        return $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: [];
    }

    /** Previous finished scan that contains the keyword. */
    public static function previous_scan_with_keyword(int $scan_id, string $keyword): ?int
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT s.id FROM " . self::scans() . " s JOIN " . self::points() . " p ON p.scan_id = s.id
             WHERE s.id < %d AND s.status IN ('done','partial') AND p.keyword = %s AND p.status = 'done'
             ORDER BY s.id DESC LIMIT 1",
            $scan_id,
            $keyword
        ));
        return $id ? (int) $id : null;
    }

    public static function delete_scan(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::points(), ['scan_id' => $id], ['%d']);
        $wpdb->delete(self::scans(), ['id' => $id], ['%d']);
    }
}
