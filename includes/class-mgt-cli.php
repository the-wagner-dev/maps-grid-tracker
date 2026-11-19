<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps Grid Tracker commands. For servers with DISABLE_WP_CRON, run `wp maps-grid tick` from the system crontab every 5 minutes.
 */
class MGT_CLI
{
    /**
     * Start a due scheduled scan and collect ready results.
     *
     * ## OPTIONS
     *
     * [--quiet]
     * : No output.
     */
    public function tick($args, $assoc)
    {
        $out = MGT_Scanner::tick();
        if (!empty($assoc['quiet'])) {
            return;
        }
        if (!empty($out['locked'])) {
            WP_CLI::log('Another tick is running.');
            return;
        }
        if ($out['started']) {
            WP_CLI::log("Started scheduled scan #{$out['started']}.");
        }
        if ($out['error']) {
            WP_CLI::warning($out['error']);
        }
        WP_CLI::success(sprintf('Checked %d tasks, received %d results.', $out['checked'] ?? 0, $out['done'] ?? 0));
    }

    /**
     * Start a scan now with the current settings (costs money unless sandbox is on).
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation.
     */
    public function scan($args, $assoc)
    {
        $s = MGT_Settings::get();
        $requests = MGT_Settings::points_count($s) * count(MGT_Settings::keywords($s));
        WP_CLI::confirm(sprintf('Post %d tasks (about $%s)%s?', $requests, number_format(MGT_Settings::estimate($s), 2), $s['sandbox'] ? ' to the sandbox' : ''), $assoc);
        $result = MGT_Scanner::start_scan('manual');
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success("Scan #{$result} started.");
    }

    /**
     * List recent scans.
     */
    public function status($args, $assoc)
    {
        $rows = array_map(function ($r) {
            $r['cost'] = number_format((float) $r['cost'], 4);
            unset($r['error']);
            return $r;
        }, MGT_DB::scans_list(20));
        WP_CLI\Utils\format_items('table', $rows, ['id', 'created_at', 'status', 'source', 'tasks_done', 'tasks_failed', 'tasks_total', 'cost']);
        $next = MGT_Settings::next_run();
        WP_CLI::log('Next scheduled scan: ' . ($next ? wp_date('Y-m-d H:i', $next) : 'off'));
        WP_CLI::log('Last tick: ' . (get_option('mgt_last_tick') ? wp_date('Y-m-d H:i', (int) get_option('mgt_last_tick')) : 'never'));
    }
}
