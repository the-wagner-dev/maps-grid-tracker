<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scan lifecycle: build the grid, post DataForSEO tasks (standard queue), collect results on ticks.
 */
class MGT_Scanner
{
    const LOCK = 'mgt_tick_lock';

    /** Grid points row by row from the north-west corner. */
    public static function grid(array $s): array
    {
        $n = (int) $s['grid_size'];
        $lat0 = (float) $s['center_lat'];
        $lng0 = (float) $s['center_lng'];
        $step = (float) $s['spacing_km'];
        $dlat = $step / 110.574;
        $dlng = $step / (111.320 * max(0.01, cos(deg2rad($lat0))));
        $half = intdiv($n, 2);
        $points = [];
        for ($row = 0; $row < $n; $row++) {
            for ($col = 0; $col < $n; $col++) {
                $points[] = [
                    'i'   => $row * $n + $col,
                    'lat' => round($lat0 + ($half - $row) * $dlat, 6),
                    'lng' => round($lng0 + ($col - $half) * $dlng, 6),
                ];
            }
        }
        return $points;
    }

    /** Start a scan for all keywords. @return int|WP_Error Scan id. */
    public static function start_scan(string $source = 'manual')
    {
        global $wpdb;
        $s = MGT_Settings::get();
        $keywords = MGT_Settings::keywords($s);
        if (!MGT_Settings::has_credentials($s)) {
            return new WP_Error('mgt_config', __('Enter the DataForSEO login and password first.', 'maps-grid-tracker'));
        }
        if (!MGT_Settings::has_center($s)) {
            return new WP_Error('mgt_config', __('Set the grid centre (latitude and longitude).', 'maps-grid-tracker'));
        }
        if (!$keywords) {
            return new WP_Error('mgt_config', __('Add at least one keyword.', 'maps-grid-tracker'));
        }
        if ($s['business_cid'] === '' && $s['business_domain'] === '') {
            return new WP_Error('mgt_config', __('Set the business CID or website domain so it can be found in the results.', 'maps-grid-tracker'));
        }
        if (MGT_DB::running_scans()) {
            return new WP_Error('mgt_busy', __('Another scan is still running. Wait until it finishes.', 'maps-grid-tracker'));
        }

        $grid = self::grid($s);
        $config = [
            'keywords'        => $keywords,
            'grid_size'       => (int) $s['grid_size'],
            'spacing_km'      => (float) $s['spacing_km'],
            'zoom'            => (int) $s['zoom'],
            'center'          => [(float) $s['center_lat'], (float) $s['center_lng']],
            'language_code'   => $s['language_code'],
            'depth'           => (int) $s['depth'],
            'business_name'   => $s['business_name'],
            'business_cid'    => $s['business_cid'],
            'business_domain' => $s['business_domain'],
            'sandbox'         => (bool) $s['sandbox'],
        ];
        $wpdb->insert(MGT_DB::scans(), [
            'created_at'  => current_time('mysql', true),
            'status'      => 'running',
            'source'      => $source,
            'config'      => wp_json_encode($config),
            'tasks_total' => count($grid) * count($keywords),
        ]);
        $scan_id = (int) $wpdb->insert_id;
        if (!$scan_id) {
            return new WP_Error('mgt_db', __('Could not save the scan to the database.', 'maps-grid-tracker'));
        }

        // Insert point rows in chunks.
        $rows = [];
        foreach ($keywords as $keyword) {
            foreach ($grid as $p) {
                $rows[] = $wpdb->prepare('(%d,%s,%d,%f,%f)', $scan_id, $keyword, $p['i'], $p['lat'], $p['lng']);
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $wpdb->query('INSERT INTO ' . MGT_DB::points() . ' (scan_id, keyword, point_index, lat, lng) VALUES ' . implode(',', $chunk)); // phpcs:ignore WordPress.DB.PreparedSQL
        }

        $result = self::post_new_tasks($scan_id, $s, $config);
        if (is_wp_error($result)) {
            $wpdb->update(MGT_DB::scans(), ['status' => 'failed', 'finished_at' => current_time('mysql', true), 'error' => $result->get_error_message()], ['id' => $scan_id]);
            $wpdb->update(MGT_DB::points(), ['status' => 'error', 'error' => 'not posted'], ['scan_id' => $scan_id, 'status' => 'new']);
            return $result;
        }
        self::refresh_counts($scan_id);
        return $scan_id;
    }

    /** Post all 'new' points of a scan in batches of 100. Fails only if nothing could be posted. */
    private static function post_new_tasks(int $scan_id, array $s, array $config)
    {
        global $wpdb;
        $api = new MGT_API($s);
        $points = $wpdb->get_results($wpdb->prepare('SELECT id, keyword, lat, lng FROM ' . MGT_DB::points() . " WHERE scan_id = %d AND status = 'new' ORDER BY id", $scan_id), ARRAY_A);
        $posted = 0;
        $cost = 0.0;
        $last_error = null;
        foreach (array_chunk($points, MGT_API::MAX_TASKS_PER_POST) as $batch) {
            $tasks = [];
            foreach ($batch as $p) {
                $tasks[] = [
                    'keyword'             => $p['keyword'],
                    'language_code'       => $config['language_code'],
                    'location_coordinate' => sprintf('%.6f,%.6f,%dz', $p['lat'], $p['lng'], $config['zoom']),
                    'depth'               => $config['depth'],
                    'tag'                 => 'mgt-' . $p['id'],
                ];
            }
            $response = $api->post_tasks($tasks);
            if (is_wp_error($response)) {
                $last_error = $response;
                $ids = implode(',', array_map('intval', array_column($batch, 'id')));
                $wpdb->query($wpdb->prepare('UPDATE ' . MGT_DB::points() . " SET status = 'error', error = %s WHERE id IN ({$ids})", mb_substr($response->get_error_message(), 0, 250))); // phpcs:ignore WordPress.DB.PreparedSQL
                continue;
            }
            foreach ($response as $task) {
                $point_id = (int) str_replace('mgt-', '', $task['tag']);
                if (!$point_id) {
                    continue;
                }
                if ($task['status_code'] === 20100 && $task['id'] !== '') {
                    $wpdb->update(MGT_DB::points(), ['task_id' => $task['id'], 'status' => 'queued'], ['id' => $point_id, 'scan_id' => $scan_id]);
                    $posted++;
                    $cost += $task['cost'];
                } else {
                    $wpdb->update(MGT_DB::points(), ['status' => 'error', 'error' => mb_substr($task['status_code'] . ' ' . $task['status_message'], 0, 250)], ['id' => $point_id, 'scan_id' => $scan_id]);
                }
            }
        }
        // Anything the API did not acknowledge.
        $wpdb->update(MGT_DB::points(), ['status' => 'error', 'error' => 'no task created'], ['scan_id' => $scan_id, 'status' => 'new']);
        $wpdb->update(MGT_DB::scans(), ['cost' => $cost], ['id' => $scan_id]);
        if ($posted === 0) {
            return $last_error ?: new WP_Error('mgt_api', __('DataForSEO did not accept any task.', 'maps-grid-tracker'));
        }
        return $posted;
    }

    /**
     * Fetch ready results of running scans.
     * @return array ['checked' => int, 'done' => int]
     */
    public static function collect(int $time_budget = 25, int $max_requests = 400): array
    {
        global $wpdb;
        $s = MGT_Settings::get();
        $stats = ['checked' => 0, 'done' => 0];
        if (!MGT_Settings::has_credentials($s)) {
            return $stats;
        }
        $started = microtime(true);
        foreach (MGT_DB::running_scans() as $scan_id) {
            $scan = MGT_DB::scan((int) $scan_id);
            if (!$scan) {
                continue;
            }
            $api = new MGT_API(array_merge($s, ['sandbox' => !empty($scan['config']['sandbox'])]));
            $queued = $wpdb->get_results($wpdb->prepare('SELECT id, task_id FROM ' . MGT_DB::points() . " WHERE scan_id = %d AND status = 'queued' ORDER BY id LIMIT %d", $scan_id, $max_requests), ARRAY_A);
            foreach (array_chunk($queued, 20) as $batch) {
                if (microtime(true) - $started > $time_budget || $stats['checked'] >= $max_requests) {
                    break;
                }
                $stats['checked'] += count($batch);
                $results = $api->get_tasks(array_column($batch, 'task_id'));
                $pending = 0;
                $failed = 0;
                foreach ($batch as $p) {
                    $task = $results[$p['task_id']] ?? new WP_Error('mgt_api', 'missing');
                    if (is_wp_error($task)) {
                        $failed++; // Network or auth problem: retried on the next tick.
                        continue;
                    }
                    if ($task['state'] === 'pending') {
                        $pending++;
                        continue;
                    }
                    if ($task['state'] === 'error') {
                        $wpdb->update(MGT_DB::points(), ['status' => 'error', 'error' => mb_substr($task['message'], 0, 250), 'checked_at' => current_time('mysql', true)], ['id' => $p['id']]);
                        continue;
                    }
                    $parsed = self::parse($task['items'], $scan['config']);
                    $wpdb->update(MGT_DB::points(), [
                        'status'     => 'done',
                        'rank_pos'   => $parsed['rank'],
                        'result'     => wp_json_encode($parsed, JSON_UNESCAPED_UNICODE),
                        'checked_at' => current_time('mysql', true),
                    ], ['id' => $p['id']]);
                    $stats['done']++;
                }
                if ($failed === count($batch)) {
                    break 2;
                }
                if ($pending === count($batch)) {
                    break; // The rest of the queue is not ready yet.
                }
            }
            // Give up on tasks that never completed.
            if (strtotime($scan['created_at'] . ' UTC') < time() - 3 * DAY_IN_SECONDS) {
                $wpdb->update(MGT_DB::points(), ['status' => 'error', 'error' => 'timeout'], ['scan_id' => $scan_id, 'status' => 'queued']);
            }
            self::refresh_counts((int) $scan_id);
        }
        return $stats;
    }

    /** Find the business in the results and keep the top 3 for context. */
    public static function parse(array $items, array $config): array
    {
        $cid = (string) ($config['business_cid'] ?? '');
        $domain = (string) ($config['business_domain'] ?? '');
        $found = null;
        $top = [];
        foreach ($items as $item) {
            $rank = (int) ($item['rank_group'] ?? 0);
            $item_domain = preg_replace('/^www\./', '', strtolower((string) ($item['domain'] ?? '')));
            $is_ours = ($cid !== '' && (string) ($item['cid'] ?? '') === $cid)
                || ($domain !== '' && $item_domain !== '' && $item_domain === $domain);
            if ($is_ours && $found === null) {
                $found = ['rank' => $rank, 'title' => (string) ($item['title'] ?? '')];
            }
            if (count($top) < 3) {
                $top[] = [
                    'rank'   => $rank,
                    'title'  => (string) ($item['title'] ?? ''),
                    'rating' => isset($item['rating']['value']) ? round((float) $item['rating']['value'], 1) : null,
                    'votes'  => isset($item['rating']['votes_count']) ? (int) $item['rating']['votes_count'] : null,
                    'ours'   => $is_ours,
                ];
            }
        }
        return [
            'rank'  => $found ? $found['rank'] : 0,
            'title' => $found['title'] ?? '',
            'count' => count($items),
            'top'   => $top,
        ];
    }

    public static function refresh_counts(int $scan_id): void
    {
        global $wpdb;
        $counts = $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) n FROM ' . MGT_DB::points() . ' WHERE scan_id = %d GROUP BY status', $scan_id), OBJECT_K);
        $n = fn($k) => isset($counts[$k]) ? (int) $counts[$k]->n : 0;
        $data = ['tasks_done' => $n('done'), 'tasks_failed' => $n('error')];
        if ($n('queued') === 0 && $n('new') === 0) {
            $data['status'] = $n('done') === 0 ? 'failed' : ($n('error') > 0 ? 'partial' : 'done');
            $data['finished_at'] = current_time('mysql', true);
        }
        $wpdb->update(MGT_DB::scans(), $data, ['id' => $scan_id, 'status' => 'running']);
    }

    /** Cron/CLI entry point: start a due scheduled scan, then collect results. */
    public static function tick(): array
    {
        if (get_transient(self::LOCK)) {
            return ['locked' => true];
        }
        set_transient(self::LOCK, 1, 6 * MINUTE_IN_SECONDS);
        update_option('mgt_last_tick', time(), false);
        $out = ['started' => null, 'error' => null];
        try {
            $s = MGT_Settings::get();
            $next = MGT_Settings::next_run();
            if ($s['schedule'] !== 'off' && $next && time() >= $next) {
                $result = self::start_scan('cron');
                MGT_Settings::advance();
                if (is_wp_error($result)) {
                    $out['error'] = $result->get_error_message();
                    update_option('mgt_last_cron_error', ['time' => time(), 'message' => $out['error']], false);
                } else {
                    $out['started'] = $result;
                    delete_option('mgt_last_cron_error');
                }
            }
            // CLI/cron has no request timeout: allow longer runs (the system cron is serialised with flock).
            $long = (defined('WP_CLI') && WP_CLI) || wp_doing_cron();
            $out += self::collect($long ? 240 : 25, $long ? 5000 : 400);
        } finally {
            delete_transient(self::LOCK);
        }
        return $out;
    }

    /** Summary numbers for one keyword's points. Not found counts as depth + 1 in ATRP. */
    public static function stats(array $points, int $depth): array
    {
        $done = array_filter($points, fn($p) => $p['status'] === 'done');
        $total = count($done);
        $ranks = array_map(fn($p) => (int) $p['rank_pos'], $done);
        $found = array_filter($ranks, fn($r) => $r > 0);
        $top3 = array_filter($found, fn($r) => $r <= 3);
        return [
            'points' => $total,
            'found'  => count($found),
            'top3'   => count($top3),
            'arp'    => $found ? round(array_sum($found) / count($found), 1) : null,
            'atrp'   => $total ? round(array_sum(array_map(fn($r) => $r > 0 ? $r : $depth + 1, $ranks)) / $total, 1) : null,
            'top3_share'  => $total ? round(100 * count($top3) / $total) : 0,
            'found_share' => $total ? round(100 * count($found) / $total) : 0,
        ];
    }
}
