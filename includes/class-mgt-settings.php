<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings stored in one non-autoloaded option. The API password is never sent back to the browser.
 */
class MGT_Settings
{
    const OPTION = 'mgt_settings';
    const GRID_SIZES = [3, 5, 7, 9, 11, 13, 15];
    const SCHEDULES = ['off' => 0, 'daily' => 1, 'weekly' => 7, 'biweekly' => 14, 'monthly' => 30];

    public static function defaults(): array
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return [
            'api_login'       => '',
            'api_password'    => '',
            'sandbox'         => false,
            'business_name'   => get_bloginfo('name'),
            'business_cid'    => '',
            'business_domain' => preg_replace('/^www\./', '', $host),
            'center_lat'      => '',
            'center_lng'      => '',
            'grid_size'       => 7,
            'spacing_km'      => 1.5,
            'zoom'            => 14,
            'language_code'   => substr(get_locale(), 0, 2),
            'depth'           => 20,
            'keywords'        => '',
            'schedule'        => 'off',
            'run_hour'        => 6,
            'price_per_task'  => 0.0006,
        ];
    }

    public static function get(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    public static function update(array $values): void
    {
        update_option(self::OPTION, array_merge(self::get(), $values), false);
    }

    /** Sanitise posted form values. An empty password keeps the stored one. */
    public static function sanitize(array $in, array $current): array
    {
        $out = [];
        $out['api_login'] = sanitize_text_field(wp_unslash($in['api_login'] ?? ''));
        $password = trim((string) wp_unslash($in['api_password'] ?? ''));
        $out['api_password'] = $password !== '' ? $password : $current['api_password'];
        if (!empty($in['api_password_clear'])) {
            $out['api_password'] = '';
        }
        $out['sandbox'] = !empty($in['sandbox']);
        $out['business_name'] = sanitize_text_field(wp_unslash($in['business_name'] ?? ''));
        $out['business_cid'] = preg_replace('/[^0-9]/', '', (string) ($in['business_cid'] ?? ''));
        $domain = strtolower(sanitize_text_field(wp_unslash($in['business_domain'] ?? '')));
        $domain = preg_replace('#^https?://#', '', $domain);
        $out['business_domain'] = preg_replace('/^www\./', '', rtrim(explode('/', $domain)[0], '.'));
        $out['center_lat'] = self::coordinate($in['center_lat'] ?? '', 90);
        $out['center_lng'] = self::coordinate($in['center_lng'] ?? '', 180);
        $size = (int) ($in['grid_size'] ?? 7);
        $out['grid_size'] = in_array($size, self::GRID_SIZES, true) ? $size : 7;
        $out['spacing_km'] = max(0.1, min(20.0, round((float) str_replace(',', '.', (string) ($in['spacing_km'] ?? 1.5)), 2)));
        $out['zoom'] = max(3, min(21, (int) ($in['zoom'] ?? 14)));
        $lang = strtolower(preg_replace('/[^a-zA-Z-]/', '', (string) ($in['language_code'] ?? 'pl')));
        $out['language_code'] = $lang !== '' ? $lang : 'pl';
        $out['depth'] = max(10, min(100, (int) ($in['depth'] ?? 20)));
        $keywords = array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($in['keywords'] ?? '')))));
        $out['keywords'] = implode("\n", array_slice(array_values(array_unique($keywords)), 0, 50));
        $schedule = (string) ($in['schedule'] ?? 'off');
        $out['schedule'] = array_key_exists($schedule, self::SCHEDULES) ? $schedule : 'off';
        $out['run_hour'] = max(0, min(23, (int) ($in['run_hour'] ?? 6)));
        $out['price_per_task'] = max(0.0, round((float) str_replace(',', '.', (string) ($in['price_per_task'] ?? 0.0006)), 5));
        return $out;
    }

    private static function coordinate($value, int $limit): string
    {
        $value = trim(str_replace(',', '.', (string) $value));
        if ($value === '' || !is_numeric($value) || abs((float) $value) > $limit) {
            return '';
        }
        return (string) round((float) $value, 6);
    }

    public static function keywords(array $s): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $s['keywords']))));
    }

    public static function has_credentials(array $s): bool
    {
        return $s['api_login'] !== '' && $s['api_password'] !== '';
    }

    public static function has_center(array $s): bool
    {
        return $s['center_lat'] !== '' && $s['center_lng'] !== '';
    }

    public static function points_count(array $s): int
    {
        return (int) $s['grid_size'] ** 2;
    }

    public static function estimate(array $s): float
    {
        return self::points_count($s) * count(self::keywords($s)) * (float) $s['price_per_task'];
    }

    /** Next scheduled run as a Unix timestamp, or 0 when scheduling is off. */
    public static function next_run(): int
    {
        return (int) get_option('mgt_next_run', 0);
    }

    /** Recalculate the next run: the first run_hour (site time) after $after. */
    public static function reschedule(?int $after = null): void
    {
        $s = self::get();
        if ($s['schedule'] === 'off') {
            update_option('mgt_next_run', 0, false);
            return;
        }
        $after = $after ?? time();
        $tz = wp_timezone();
        $next = (new DateTimeImmutable('@' . $after))->setTimezone($tz)->setTime((int) $s['run_hour'], 0);
        if ($next->getTimestamp() <= $after) {
            $next = $next->modify('+1 day');
        }
        update_option('mgt_next_run', $next->getTimestamp(), false);
    }

    /** After a scheduled scan started: move the next run forward by the schedule interval. */
    public static function advance(): void
    {
        $s = self::get();
        $days = self::SCHEDULES[$s['schedule']] ?? 0;
        if (!$days) {
            update_option('mgt_next_run', 0, false);
            return;
        }
        $tz = wp_timezone();
        $next = (new DateTimeImmutable('@' . max(self::next_run(), 1)))->setTimezone($tz);
        while ($next->getTimestamp() <= time()) {
            $next = $next->modify("+{$days} days");
        }
        update_option('mgt_next_run', $next->setTime((int) $s['run_hour'], 0)->getTimestamp(), false);
    }
}
