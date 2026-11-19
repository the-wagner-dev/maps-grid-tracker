<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tools → Maps Grid: report (map), history (scans) and settings. Uses core admin markup only.
 */
class MGT_Admin
{
    const SLUG = 'maps-grid-tracker';
    const CAP = 'manage_options';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        foreach (['save_settings', 'run_scan', 'collect', 'delete_scan', 'find_business', 'use_business', 'test_connection'] as $action) {
            add_action('admin_post_mgt_' . $action, [__CLASS__, 'handle_' . $action]);
        }
        add_filter('plugin_action_links_' . plugin_basename(MGT_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(self::url('settings')) . '">' . esc_html__('Settings', 'maps-grid-tracker') . '</a>');
            return $links;
        });
    }

    public static function url(string $tab = 'report', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $args), admin_url('tools.php'));
    }

    public static function menu(): void
    {
        add_management_page(
            __('Maps Grid Tracker', 'maps-grid-tracker'),
            __('Maps Grid', 'maps-grid-tracker'),
            self::CAP,
            self::SLUG,
            [__CLASS__, 'render']
        );
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'tools_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style('mgt-leaflet', MGT_URL . 'assets/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_style('mgt-admin', MGT_URL . 'assets/admin.css', ['mgt-leaflet'], MGT_VERSION);
        wp_enqueue_script('mgt-leaflet', MGT_URL . 'assets/leaflet/leaflet.js', [], '1.9.4', true);
        wp_enqueue_script('mgt-admin', MGT_URL . 'assets/admin.js', ['mgt-leaflet'], MGT_VERSION, true);
        wp_localize_script('mgt-admin', 'mgtL10n', [
            'notFound'  => __('Not in results', 'maps-grid-tracker'),
            'position'  => __('Position', 'maps-grid-tracker'),
            'point'     => __('Point', 'maps-grid-tracker'),
            'top'       => __('Top 3 here', 'maps-grid-tracker'),
            'error'     => __('No data', 'maps-grid-tracker'),
            'center'    => __('Grid centre', 'maps-grid-tracker'),
            'estimate'  => __('%1$d points × %2$d keywords = %3$d requests ≈ $%4$s per scan', 'maps-grid-tracker'),
        ]);
    }

    /* ---------- notices ---------- */

    private static function notice(string $message, string $type = 'success'): void
    {
        set_transient('mgt_notice_' . get_current_user_id(), ['message' => $message, 'type' => $type], 60);
    }

    private static function print_notice(): void
    {
        $key = 'mgt_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (is_array($notice)) {
            delete_transient($key);
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice['type']), esc_html($notice['message']));
        }
    }

    private static function guard(string $action): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You are not allowed to do this.', 'maps-grid-tracker'), 403);
        }
        check_admin_referer($action);
    }

    private static function redirect(string $tab, array $args = []): void
    {
        wp_safe_redirect(self::url($tab, $args));
        exit;
    }

    /* ---------- handlers ---------- */

    public static function handle_save_settings(): void
    {
        self::guard('mgt_save_settings');
        $current = MGT_Settings::get();
        $new = MGT_Settings::sanitize($_POST['mgt'] ?? [], $current); // phpcs:ignore WordPress.Security.NonceVerification
        $schedule_changed = $new['schedule'] !== $current['schedule'] || $new['run_hour'] !== $current['run_hour'];
        MGT_Settings::update($new);
        if ($schedule_changed || ($new['schedule'] !== 'off' && !MGT_Settings::next_run())) {
            MGT_Settings::reschedule();
        }
        if ($new['api_login'] !== $current['api_login'] || $new['api_password'] !== $current['api_password'] || $new['sandbox'] !== $current['sandbox']) {
            delete_transient('mgt_account');
        }
        self::notice(__('Settings saved.', 'maps-grid-tracker'));
        self::redirect('settings');
    }

    public static function handle_test_connection(): void
    {
        self::guard('mgt_test_connection');
        $account = (new MGT_API(MGT_Settings::get()))->account();
        if (is_wp_error($account)) {
            delete_transient('mgt_account');
            self::notice($account->get_error_message(), 'error');
        } else {
            set_transient('mgt_account', $account, 10 * MINUTE_IN_SECONDS);
            /* translators: %s: account balance in USD */
            self::notice(sprintf(__('Connected. Balance: $%s', 'maps-grid-tracker'), number_format_i18n($account['balance'], 2)));
        }
        self::redirect('settings');
    }

    public static function handle_run_scan(): void
    {
        self::guard('mgt_run_scan');
        $result = MGT_Scanner::start_scan('manual');
        if (is_wp_error($result)) {
            self::notice($result->get_error_message(), 'error');
            self::redirect('history');
        }
        /* translators: %d: scan number */
        self::notice(sprintf(__('Scan #%d started. DataForSEO usually returns standard-queue results within a few minutes; they are collected every 5 minutes or with “Check results now”.', 'maps-grid-tracker'), $result));
        self::redirect('history');
    }

    public static function handle_collect(): void
    {
        self::guard('mgt_collect');
        $stats = MGT_Scanner::collect(40, 1000);
        /* translators: 1: requests made, 2: results received */
        self::notice(sprintf(__('Checked %1$d tasks, received %2$d results.', 'maps-grid-tracker'), $stats['checked'], $stats['done']));
        self::redirect(sanitize_key($_POST['return'] ?? 'history')); // phpcs:ignore WordPress.Security.NonceVerification
    }

    public static function handle_delete_scan(): void
    {
        self::guard('mgt_delete_scan');
        $id = absint($_POST['scan'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
        if ($id) {
            MGT_DB::delete_scan($id);
            /* translators: %d: scan number */
            self::notice(sprintf(__('Scan #%d deleted.', 'maps-grid-tracker'), $id));
        }
        self::redirect('history');
    }

    public static function handle_find_business(): void
    {
        self::guard('mgt_find_business');
        $s = MGT_Settings::get();
        $query = sanitize_text_field(wp_unslash($_POST['query'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        if ($query === '' || !MGT_Settings::has_credentials($s) || !MGT_Settings::has_center($s)) {
            self::notice(__('Save the API access and the grid centre first, then enter the business name.', 'maps-grid-tracker'), 'error');
            self::redirect('settings');
        }
        $coordinate = sprintf('%.6f,%.6f,%dz', $s['center_lat'], $s['center_lng'], $s['zoom']);
        $live = (new MGT_API($s))->live($query, $coordinate, $s['language_code'], 20);
        if (is_wp_error($live)) {
            self::notice($live->get_error_message(), 'error');
            self::redirect('settings');
        }
        $found = [];
        foreach ($live['items'] as $item) {
            $found[] = [
                'title'   => (string) ($item['title'] ?? ''),
                'address' => (string) ($item['address'] ?? ''),
                'cid'     => (string) ($item['cid'] ?? ''),
                'domain'  => preg_replace('/^www\./', '', (string) ($item['domain'] ?? '')),
                'rating'  => $item['rating']['value'] ?? null,
                'votes'   => $item['rating']['votes_count'] ?? null,
            ];
        }
        set_transient('mgt_lookup_' . get_current_user_id(), ['query' => $query, 'items' => $found, 'cost' => $live['cost']], HOUR_IN_SECONDS);
        self::redirect('settings', ['lookup' => 1]);
    }

    public static function handle_use_business(): void
    {
        self::guard('mgt_use_business');
        // phpcs:disable WordPress.Security.NonceVerification
        MGT_Settings::update([
            'business_cid'    => preg_replace('/[^0-9]/', '', (string) ($_POST['cid'] ?? '')),
            'business_name'   => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'business_domain' => preg_replace('/^www\./', '', sanitize_text_field(wp_unslash($_POST['domain'] ?? ''))) ?: MGT_Settings::get()['business_domain'],
        ]);
        // phpcs:enable
        delete_transient('mgt_lookup_' . get_current_user_id());
        self::notice(__('Business saved.', 'maps-grid-tracker'));
        self::redirect('settings');
    }

    /* ---------- rendering ---------- */

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $tab = sanitize_key($_GET['tab'] ?? 'report'); // phpcs:ignore WordPress.Security.NonceVerification
        $tabs = [
            'report'   => __('Report', 'maps-grid-tracker'),
            'history'  => __('Scans', 'maps-grid-tracker'),
            'settings' => __('Settings', 'maps-grid-tracker'),
        ];
        if (!isset($tabs[$tab])) {
            $tab = 'report';
        }
        echo '<div class="wrap mgt-wrap">';
        echo '<h1>' . esc_html__('Maps Grid Tracker', 'maps-grid-tracker') . '</h1>';
        self::print_notice();
        self::status_notices();
        echo '<nav class="nav-tab-wrapper wp-clearfix">';
        foreach ($tabs as $key => $label) {
            printf('<a href="%s" class="nav-tab%s">%s</a>', esc_url(self::url($key)), $key === $tab ? ' nav-tab-active' : '', esc_html($label));
        }
        echo '</nav>';
        call_user_func([__CLASS__, 'tab_' . $tab]);
        echo '</div>';
    }

    private static function status_notices(): void
    {
        $s = MGT_Settings::get();
        if (!empty($s['sandbox'])) {
            echo '<div class="notice notice-info"><p>' . esc_html__('Sandbox mode is on: DataForSEO returns sample data for free. Turn it off in Settings for real rankings.', 'maps-grid-tracker') . '</p></div>';
        }
        $last_tick = (int) get_option('mgt_last_tick', 0);
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        if ($s['schedule'] !== 'off' && $cron_disabled && $last_tick < time() - 20 * MINUTE_IN_SECONDS) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('WP-Cron is disabled on this site (DISABLE_WP_CRON) and no tick has run in the last 20 minutes, so scheduled scans will not start. Add this line to the server crontab:', 'maps-grid-tracker') . '</p>';
            printf('<p><code>*/5 * * * * cd %s &amp;&amp; wp maps-grid tick --quiet</code></p></div>', esc_html(untrailingslashit(ABSPATH)));
        }
        $cron_error = get_option('mgt_last_cron_error');
        if (is_array($cron_error)) {
            /* translators: 1: date, 2: error */
            echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('Scheduled scan failed on %1$s: %2$s', 'maps-grid-tracker'), self::date((int) $cron_error['time']), $cron_error['message'])) . '</p></div>';
        }
        foreach (MGT_DB::running_scans() as $id) {
            $scan = MGT_DB::scan((int) $id);
            if (!$scan) {
                continue;
            }
            echo '<div class="notice notice-info mgt-running"><p>';
            /* translators: 1: scan number, 2: results received, 3: total tasks */
            echo esc_html(sprintf(__('Scan #%1$d is running: %2$d of %3$d results received. Results are collected every 5 minutes.', 'maps-grid-tracker'), $scan['id'], $scan['tasks_done'], $scan['tasks_total'])) . ' ';
            echo '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mgt_collect');
            echo '<input type="hidden" name="action" value="mgt_collect"><input type="hidden" name="return" value="' . esc_attr(sanitize_key($_GET['tab'] ?? 'report')) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
            submit_button(__('Check results now', 'maps-grid-tracker'), 'secondary small', 'submit', false);
            echo '</form></div>';
        }
    }

    private static function date($value): string
    {
        $ts = is_numeric($value) ? (int) $value : strtotime($value . ' UTC');
        return $ts ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : '—';
    }

    private static function status_label(string $status): string
    {
        $labels = [
            'running' => __('Running', 'maps-grid-tracker'),
            'done'    => __('Done', 'maps-grid-tracker'),
            'partial' => __('Partly done', 'maps-grid-tracker'),
            'failed'  => __('Failed', 'maps-grid-tracker'),
        ];
        return $labels[$status] ?? $status;
    }

    /* Report */

    private static function tab_report(): void
    {
        $scans = MGT_DB::finished_scans();
        if (!$scans) {
            echo '<div class="mgt-empty"><p>' . esc_html__('No finished scans yet.', 'maps-grid-tracker') . '</p><p>';
            printf(
                '<a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a>',
                esc_url(self::url('settings')),
                esc_html__('Settings', 'maps-grid-tracker'),
                esc_url(self::url('history')),
                esc_html__('Run a scan', 'maps-grid-tracker')
            );
            echo '</p></div>';
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        $scan_id = absint($_GET['scan'] ?? 0) ?: (int) $scans[0]['id'];
        $scan = MGT_DB::scan($scan_id) ?: MGT_DB::scan((int) $scans[0]['id']);
        $keywords = $scan['config']['keywords'] ?? [];
        $keyword = isset($_GET['keyword']) ? sanitize_text_field(wp_unslash($_GET['keyword'])) : ($keywords[0] ?? '');
        // phpcs:enable
        if (!in_array($keyword, $keywords, true)) {
            $keyword = $keywords[0] ?? '';
        }
        $depth = (int) ($scan['config']['depth'] ?? 20);

        echo '<form method="get" class="mgt-filter">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="tab" value="report">';
        echo '<label>' . esc_html__('Scan', 'maps-grid-tracker') . ' <select name="scan" onchange="this.form.submit()">';
        foreach ($scans as $row) {
            printf('<option value="%d"%s>#%d · %s</option>', (int) $row['id'], selected((int) $row['id'], (int) $scan['id'], false), (int) $row['id'], esc_html(self::date($row['created_at'])));
        }
        echo '</select></label> <label>' . esc_html__('Keyword', 'maps-grid-tracker') . ' <select name="keyword" onchange="this.form.submit()">';
        foreach ($keywords as $kw) {
            printf('<option value="%s"%s>%s</option>', esc_attr($kw), selected($kw, $keyword, false), esc_html($kw));
        }
        echo '</select></label> <noscript><button class="button">' . esc_html__('Show', 'maps-grid-tracker') . '</button></noscript></form>';

        $points = MGT_DB::scan_points((int) $scan['id'], $keyword);
        $stats = MGT_Scanner::stats($points, $depth);
        $prev_id = MGT_DB::previous_scan_with_keyword((int) $scan['id'], $keyword);
        $prev = $prev_id ? MGT_Scanner::stats(MGT_DB::scan_points($prev_id, $keyword), (int) (MGT_DB::scan($prev_id)['config']['depth'] ?? $depth)) : null;

        echo '<div class="mgt-cards">';
        self::card(__('Average position', 'maps-grid-tracker'), $stats['arp'], $prev['arp'] ?? null, true, __('Only points where the business was found.', 'maps-grid-tracker'));
        /* translators: %d: depth + 1 */
        self::card(__('Average total position', 'maps-grid-tracker'), $stats['atrp'], $prev['atrp'] ?? null, true, sprintf(__('Not found counts as %d.', 'maps-grid-tracker'), $depth + 1));
        self::card(__('Top 3', 'maps-grid-tracker'), $stats['top3_share'], $prev['top3_share'] ?? null, false, sprintf('%d / %d', $stats['top3'], $stats['points']));
        /* translators: %d: depth */
        self::card(sprintf(__('Found in top %d', 'maps-grid-tracker'), $depth), $stats['found_share'], $prev['found_share'] ?? null, false, sprintf('%d / %d', $stats['found'], $stats['points']));
        echo '</div>';
        if ($prev_id) {
            /* translators: %d: scan number */
            echo '<p class="description">' . esc_html(sprintf(__('Changes are compared with scan #%d.', 'maps-grid-tracker'), $prev_id)) . '</p>';
        }

        $map_points = [];
        foreach ($points as $p) {
            $result = json_decode((string) $p['result'], true) ?: [];
            $map_points[] = [
                'i'    => (int) $p['point_index'] + 1,
                'lat'  => (float) $p['lat'],
                'lng'  => (float) $p['lng'],
                'ok'   => $p['status'] === 'done',
                'rank' => (int) $p['rank_pos'],
                'top'  => $result['top'] ?? [],
            ];
        }
        echo '<div id="mgt-map" class="mgt-map" role="img" aria-label="' . esc_attr__('Map of positions on the grid', 'maps-grid-tracker') . '"></div>';
        echo '<ul class="mgt-legend">';
        echo '<li><span class="mgt-dot mgt-top3"></span>1–3</li><li><span class="mgt-dot mgt-top10"></span>4–10</li>';
        /* translators: %d: depth */
        echo '<li><span class="mgt-dot mgt-top20"></span>11–' . (int) $depth . '</li><li><span class="mgt-dot mgt-none"></span>' . esc_html(sprintf(__('not in top %d', 'maps-grid-tracker'), $depth)) . '</li></ul>';
        wp_add_inline_script('mgt-admin', 'window.mgtReport = ' . wp_json_encode([
            'center' => $scan['config']['center'] ?? [0, 0],
            'depth'  => $depth,
            'points' => $map_points,
        ]) . ';', 'before');

        // All keywords of this scan.
        echo '<h2>' . esc_html__('All keywords in this scan', 'maps-grid-tracker') . '</h2>';
        echo '<table class="widefat striped mgt-table"><thead><tr><th>' . esc_html__('Keyword', 'maps-grid-tracker') . '</th><th>' . esc_html__('Average position', 'maps-grid-tracker') . '</th><th>' . esc_html__('Average total position', 'maps-grid-tracker') . '</th><th>' . esc_html__('Top 3', 'maps-grid-tracker') . '</th><th>' . esc_html__('Found', 'maps-grid-tracker') . '</th></tr></thead><tbody>';
        $all = MGT_DB::scan_points((int) $scan['id']);
        $by_kw = [];
        foreach ($all as $p) {
            $by_kw[$p['keyword']][] = $p;
        }
        foreach ($keywords as $kw) {
            $st = MGT_Scanner::stats($by_kw[$kw] ?? [], $depth);
            printf(
                '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%d%%</td><td>%d%%</td></tr>',
                esc_url(self::url('report', ['scan' => $scan['id'], 'keyword' => $kw])),
                esc_html($kw),
                esc_html($st['arp'] === null ? '—' : number_format_i18n($st['arp'], 1)),
                esc_html($st['atrp'] === null ? '—' : number_format_i18n($st['atrp'], 1)),
                (int) $st['top3_share'],
                (int) $st['found_share']
            );
        }
        echo '</tbody></table>';
        $cfg = $scan['config'];
        /* translators: 1: grid size, 2: spacing km, 3: zoom, 4: business */
        echo '<p class="description">' . esc_html(sprintf(__('Grid %1$d×%1$d, %2$s km between points, zoom %3$d. Business: %4$s', 'maps-grid-tracker'), $cfg['grid_size'] ?? 0, number_format_i18n((float) ($cfg['spacing_km'] ?? 0), 2), $cfg['zoom'] ?? 0, implode(', ', array_filter([$cfg['business_name'] ?? '', !empty($cfg['business_cid']) ? 'CID ' . $cfg['business_cid'] : '', $cfg['business_domain'] ?? ''])))) . '</p>';
    }

    /** Stat card. Positions (lower is better) have one decimal; shares are whole percents. */
    private static function card(string $label, $value, $previous, bool $is_position, string $note): void
    {
        $fmt = fn($v) => $is_position ? number_format_i18n((float) $v, 1) : number_format_i18n((float) $v) . '%';
        $delta = '';
        if ($previous !== null && $value !== null) {
            $diff = (float) $value - (float) $previous;
            if (abs($diff) >= 0.05) {
                $better = $is_position ? $diff < 0 : $diff > 0;
                $amount = $is_position ? number_format_i18n(abs($diff), 1) : number_format_i18n(abs($diff)) . ' ' . __('pp', 'maps-grid-tracker');
                $delta = sprintf('<span class="mgt-delta %s">%s%s</span>', $better ? 'is-better' : 'is-worse', $diff > 0 ? '+' : '−', esc_html($amount));
            }
        }
        printf(
            '<div class="mgt-card"><div class="mgt-card__label">%s</div><div class="mgt-card__value">%s %s</div><div class="mgt-card__note">%s</div></div>',
            esc_html($label),
            esc_html($value === null ? '—' : $fmt($value)),
            $delta, // Built from escaped parts above.
            esc_html($note)
        );
    }

    /* History */

    private static function tab_history(): void
    {
        $s = MGT_Settings::get();
        $kw = count(MGT_Settings::keywords($s));
        $requests = MGT_Settings::points_count($s) * $kw;
        echo '<div class="mgt-actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mgt_run_scan');
        echo '<input type="hidden" name="action" value="mgt_run_scan">';
        /* translators: 1: requests, 2: estimated cost */
        $confirm = sprintf(__('Start a scan: %1$d requests, about $%2$s. Continue?', 'maps-grid-tracker'), $requests, number_format(MGT_Settings::estimate($s), 2));
        printf('<button type="submit" class="button button-primary" data-mgt-confirm="%s"%s>%s</button> ', esc_attr($confirm), $requests ? '' : ' disabled', esc_html__('Run scan now', 'maps-grid-tracker'));
        /* translators: 1: points, 2: keywords, 3: estimated cost */
        echo '<span class="description">' . esc_html(sprintf(__('%1$d points × %2$d keywords, about $%3$s', 'maps-grid-tracker'), MGT_Settings::points_count($s), $kw, number_format(MGT_Settings::estimate($s), 2))) . '</span>';
        echo '</form>';
        $next = MGT_Settings::next_run();
        if ($s['schedule'] !== 'off' && $next) {
            /* translators: %s: date */
            echo '<p class="description">' . esc_html(sprintf(__('Next scheduled scan: %s', 'maps-grid-tracker'), self::date($next))) . '</p>';
        }
        echo '</div>';

        $scans = MGT_DB::scans_list();
        if (!$scans) {
            echo '<p>' . esc_html__('No scans yet.', 'maps-grid-tracker') . '</p>';
            return;
        }
        echo '<table class="widefat striped mgt-table"><thead><tr>';
        foreach ([__('Scan', 'maps-grid-tracker'), __('Started', 'maps-grid-tracker'), __('Source', 'maps-grid-tracker'), __('Status', 'maps-grid-tracker'), __('Results', 'maps-grid-tracker'), __('Cost', 'maps-grid-tracker'), ''] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($scans as $row) {
            echo '<tr>';
            printf('<td>#%d</td><td>%s</td><td>%s</td>', (int) $row['id'], esc_html(self::date($row['created_at'])), esc_html($row['source'] === 'cron' ? __('schedule', 'maps-grid-tracker') : __('manual', 'maps-grid-tracker')));
            $status = esc_html(self::status_label($row['status']));
            if ($row['error']) {
                $status .= '<br><span class="description">' . esc_html($row['error']) . '</span>';
            }
            echo '<td>' . $status . '</td>'; // Escaped above.
            $failed = (int) $row['tasks_failed'] ? ' <span class="description">' . esc_html(sprintf(/* translators: %d: failed tasks */ __('(%d failed)', 'maps-grid-tracker'), $row['tasks_failed'])) . '</span>' : '';
            printf('<td>%d / %d%s</td><td>$%s</td>', (int) $row['tasks_done'], (int) $row['tasks_total'], $failed, esc_html(number_format((float) $row['cost'], 3)));
            echo '<td class="mgt-row-actions">';
            if (in_array($row['status'], ['done', 'partial'], true)) {
                printf('<a class="button button-small" href="%s">%s</a> ', esc_url(self::url('report', ['scan' => $row['id']])), esc_html__('View', 'maps-grid-tracker'));
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mgt_delete_scan');
            printf('<input type="hidden" name="action" value="mgt_delete_scan"><input type="hidden" name="scan" value="%d">', (int) $row['id']);
            /* translators: %d: scan number */
            printf('<button type="submit" class="button-link button-link-delete" data-mgt-confirm="%s">%s</button>', esc_attr(sprintf(__('Delete scan #%d and its results?', 'maps-grid-tracker'), $row['id'])), esc_html__('Delete', 'maps-grid-tracker'));
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
    }

    /* Settings */

    private static function tab_settings(): void
    {
        $s = MGT_Settings::get();
        $account = get_transient('mgt_account');
        $post = esc_url(admin_url('admin-post.php'));

        echo '<form method="post" action="' . $post . '" class="mgt-settings">'; // Escaped above.
        wp_nonce_field('mgt_save_settings');
        echo '<input type="hidden" name="action" value="mgt_save_settings">';

        echo '<h2>' . esc_html__('DataForSEO access', 'maps-grid-tracker') . '</h2><table class="form-table" role="presentation">';
        self::row('mgt-login', __('API login', 'maps-grid-tracker'), sprintf('<input type="text" id="mgt-login" name="mgt[api_login]" value="%s" class="regular-text" autocomplete="off">', esc_attr($s['api_login'])));
        $pw_note = $s['api_password'] !== ''
            ? esc_html__('A password is saved. Leave empty to keep it.', 'maps-grid-tracker') . ' <label><input type="checkbox" name="mgt[api_password_clear]" value="1"> ' . esc_html__('Remove saved password', 'maps-grid-tracker') . '</label>'
            : esc_html__('API password from the DataForSEO dashboard (API Access), not the account password.', 'maps-grid-tracker');
        self::row('mgt-password', __('API password', 'maps-grid-tracker'), '<input type="password" id="mgt-password" name="mgt[api_password]" value="" class="regular-text" autocomplete="new-password"><p class="description">' . $pw_note . '</p>');
        self::row('mgt-sandbox', __('Sandbox', 'maps-grid-tracker'), sprintf('<label><input type="checkbox" id="mgt-sandbox" name="mgt[sandbox]" value="1"%s> %s</label>', checked($s['sandbox'], true, false), esc_html__('Use sandbox.dataforseo.com (free sample data, for testing)', 'maps-grid-tracker')));
        if (is_array($account)) {
            /* translators: 1: login, 2: balance */
            self::row('', __('Account', 'maps-grid-tracker'), esc_html(sprintf(__('%1$s, balance $%2$s', 'maps-grid-tracker'), $account['login'], number_format_i18n($account['balance'], 2))));
        }
        echo '</table>';

        echo '<h2>' . esc_html__('Business', 'maps-grid-tracker') . '</h2><table class="form-table" role="presentation">';
        self::row('mgt-bname', __('Name', 'maps-grid-tracker'), sprintf('<input type="text" id="mgt-bname" name="mgt[business_name]" value="%s" class="regular-text">', esc_attr($s['business_name'])));
        self::row('mgt-cid', __('Google CID', 'maps-grid-tracker'), sprintf('<input type="text" id="mgt-cid" name="mgt[business_cid]" value="%s" class="regular-text code" inputmode="numeric"><p class="description">%s</p>', esc_attr($s['business_cid']), esc_html__('The most reliable match. Use “Find business” below to fill it in.', 'maps-grid-tracker')));
        self::row('mgt-domain', __('Website domain', 'maps-grid-tracker'), sprintf('<input type="text" id="mgt-domain" name="mgt[business_domain]" value="%s" class="regular-text code"><p class="description">%s</p>', esc_attr($s['business_domain']), esc_html__('Used when the CID is empty or does not match: the profile’s website domain.', 'maps-grid-tracker')));
        echo '</table>';

        echo '<h2>' . esc_html__('Grid', 'maps-grid-tracker') . '</h2>';
        echo '<p class="description">' . esc_html__('Click the map to move the grid centre.', 'maps-grid-tracker') . '</p>';
        echo '<div id="mgt-preview" class="mgt-map mgt-map--preview"></div>';
        echo '<table class="form-table" role="presentation">';
        self::row('mgt-lat', __('Centre', 'maps-grid-tracker'), sprintf(
            '<input type="text" id="mgt-lat" name="mgt[center_lat]" value="%s" class="small-text mgt-coord" placeholder="52.2297" aria-label="%s"> <input type="text" id="mgt-lng" name="mgt[center_lng]" value="%s" class="small-text mgt-coord" placeholder="21.0122" aria-label="%s"> <span class="description">%s</span>',
            esc_attr($s['center_lat']),
            esc_attr__('Latitude', 'maps-grid-tracker'),
            esc_attr($s['center_lng']),
            esc_attr__('Longitude', 'maps-grid-tracker'),
            esc_html__('latitude, longitude', 'maps-grid-tracker')
        ));
        $sizes = '';
        foreach (MGT_Settings::GRID_SIZES as $n) {
            $sizes .= sprintf('<option value="%d"%s>%d × %d</option>', $n, selected($n, (int) $s['grid_size'], false), $n, $n);
        }
        self::row('mgt-size', __('Grid size', 'maps-grid-tracker'), '<select id="mgt-size" name="mgt[grid_size]">' . $sizes . '</select>');
        self::row('mgt-spacing', __('Distance between points', 'maps-grid-tracker'), sprintf('<input type="number" id="mgt-spacing" name="mgt[spacing_km]" value="%s" min="0.1" max="20" step="0.1" class="small-text"> km', esc_attr($s['spacing_km'])));
        self::row('mgt-zoom', __('Map zoom', 'maps-grid-tracker'), sprintf('<input type="number" id="mgt-zoom" name="mgt[zoom]" value="%d" min="3" max="21" class="small-text"><p class="description">%s</p>', (int) $s['zoom'], esc_html__('Google Maps zoom used for each search (13–15 for a city).', 'maps-grid-tracker')));
        echo '</table>';

        echo '<h2>' . esc_html__('Search', 'maps-grid-tracker') . '</h2><table class="form-table" role="presentation">';
        self::row('mgt-keywords', __('Keywords', 'maps-grid-tracker'), sprintf('<textarea id="mgt-keywords" name="mgt[keywords]" rows="6" class="large-text code">%s</textarea><p class="description">%s</p>', esc_textarea($s['keywords']), esc_html__('One per line, up to 50.', 'maps-grid-tracker')));
        self::row('mgt-lang', __('Language', 'maps-grid-tracker'), sprintf('<input type="text" id="mgt-lang" name="mgt[language_code]" value="%s" class="small-text code"> <span class="description">%s</span>', esc_attr($s['language_code']), esc_html__('e.g. pl, en, de', 'maps-grid-tracker')));
        self::row('mgt-depth', __('Results to check', 'maps-grid-tracker'), sprintf('<input type="number" id="mgt-depth" name="mgt[depth]" value="%d" min="10" max="100" step="10" class="small-text"><p class="description">%s</p>', (int) $s['depth'], esc_html__('Positions below this count are shown as “not found”. Up to 100 costs the same.', 'maps-grid-tracker')));
        echo '</table>';

        echo '<h2>' . esc_html__('Schedule and cost', 'maps-grid-tracker') . '</h2><table class="form-table" role="presentation">';
        $labels = [
            'off'      => __('Manual only', 'maps-grid-tracker'),
            'daily'    => __('Daily', 'maps-grid-tracker'),
            'weekly'   => __('Weekly', 'maps-grid-tracker'),
            'biweekly' => __('Every 2 weeks', 'maps-grid-tracker'),
            'monthly'  => __('Every 30 days', 'maps-grid-tracker'),
        ];
        $opts = '';
        foreach ($labels as $key => $label) {
            $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($key), selected($key, $s['schedule'], false), esc_html($label));
        }
        $hours = '';
        for ($h = 0; $h < 24; $h++) {
            $hours .= sprintf('<option value="%d"%s>%02d:00</option>', $h, selected($h, (int) $s['run_hour'], false), $h);
        }
        $next = MGT_Settings::next_run();
        $next_text = ($s['schedule'] !== 'off' && $next) ? '<p class="description">' . esc_html(sprintf(/* translators: %s: date */ __('Next scan: %s', 'maps-grid-tracker'), self::date($next))) . '</p>' : '';
        self::row('mgt-schedule', __('Automatic scans', 'maps-grid-tracker'), '<select id="mgt-schedule" name="mgt[schedule]">' . $opts . '</select> <label for="mgt-hour">' . esc_html__('at', 'maps-grid-tracker') . '</label> <select id="mgt-hour" name="mgt[run_hour]">' . $hours . '</select> <span class="description">' . esc_html(wp_timezone_string()) . '</span>' . $next_text);
        self::row('mgt-price', __('Price per request', 'maps-grid-tracker'), sprintf('$<input type="number" id="mgt-price" name="mgt[price_per_task]" value="%s" min="0" step="0.0001" class="small-text"><p class="description">%s</p><p class="description" id="mgt-estimate"></p>', esc_attr($s['price_per_task']), esc_html__('Google Maps SERP, standard queue, from your DataForSEO price list. Only used for estimates; the real cost is taken from the API.', 'maps-grid-tracker')));
        echo '</table>';
        submit_button(__('Save settings', 'maps-grid-tracker'));
        echo '</form>';

        wp_add_inline_script('mgt-admin', 'window.mgtSettings = ' . wp_json_encode(['fallback' => [52.2297, 21.0122]]) . ';', 'before');

        // Separate forms: connection test and business lookup.
        echo '<hr><h2>' . esc_html__('Tools', 'maps-grid-tracker') . '</h2><div class="mgt-tools">';
        echo '<form method="post" action="' . $post . '">'; // Escaped above.
        wp_nonce_field('mgt_test_connection');
        echo '<input type="hidden" name="action" value="mgt_test_connection">';
        submit_button(__('Test connection', 'maps-grid-tracker'), 'secondary', 'submit', false);
        echo ' <span class="description">' . esc_html__('Free: reads the account balance.', 'maps-grid-tracker') . '</span></form>';

        echo '<form method="post" action="' . $post . '">'; // Escaped above.
        wp_nonce_field('mgt_find_business');
        echo '<input type="hidden" name="action" value="mgt_find_business">';
        printf('<label for="mgt-query" class="screen-reader-text">%s</label><input type="search" id="mgt-query" name="query" value="%s" class="regular-text" placeholder="%s"> ', esc_html__('Business name', 'maps-grid-tracker'), esc_attr($s['business_name']), esc_attr__('Business name', 'maps-grid-tracker'));
        submit_button(__('Find business', 'maps-grid-tracker'), 'secondary', 'submit', false);
        echo ' <span class="description">' . esc_html__('One live request at the grid centre (about $0.002).', 'maps-grid-tracker') . '</span></form>';
        echo '</div>';

        $lookup = isset($_GET['lookup']) ? get_transient('mgt_lookup_' . get_current_user_id()) : null; // phpcs:ignore WordPress.Security.NonceVerification
        if (is_array($lookup)) {
            /* translators: 1: query, 2: cost */
            echo '<h3>' . esc_html(sprintf(__('Results for “%1$s” (cost $%2$s)', 'maps-grid-tracker'), $lookup['query'], number_format((float) $lookup['cost'], 4))) . '</h3>';
            if (!$lookup['items']) {
                echo '<p>' . esc_html__('Nothing found.', 'maps-grid-tracker') . '</p>';
            } else {
                echo '<table class="widefat striped mgt-table"><thead><tr><th>' . esc_html__('Name', 'maps-grid-tracker') . '</th><th>' . esc_html__('Address', 'maps-grid-tracker') . '</th><th>' . esc_html__('Website', 'maps-grid-tracker') . '</th><th>CID</th><th></th></tr></thead><tbody>';
                foreach ($lookup['items'] as $item) {
                    echo '<tr><td>' . esc_html($item['title']) . ($item['rating'] !== null ? ' <span class="description">★ ' . esc_html($item['rating']) . ' (' . (int) $item['votes'] . ')</span>' : '') . '</td>';
                    echo '<td>' . esc_html($item['address']) . '</td><td>' . esc_html($item['domain']) . '</td><td><code>' . esc_html($item['cid']) . '</code></td><td>';
                    echo '<form method="post" action="' . $post . '">'; // Escaped above.
                    wp_nonce_field('mgt_use_business');
                    printf('<input type="hidden" name="action" value="mgt_use_business"><input type="hidden" name="cid" value="%s"><input type="hidden" name="title" value="%s"><input type="hidden" name="domain" value="%s">', esc_attr($item['cid']), esc_attr($item['title']), esc_attr($item['domain']));
                    submit_button(__('This is my business', 'maps-grid-tracker'), 'small', 'submit', false);
                    echo '</form></td></tr>';
                }
                echo '</tbody></table>';
            }
        }
    }

    private static function row(string $id, string $label, string $field): void
    {
        $th = $id !== '' ? sprintf('<label for="%s">%s</label>', esc_attr($id), esc_html($label)) : esc_html($label);
        echo '<tr><th scope="row">' . $th . '</th><td>' . $field . '</td></tr>'; // Fields are escaped by callers.
    }
}
