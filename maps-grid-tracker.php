<?php
/**
 * Plugin Name:       Maps Grid Tracker
 * Plugin URI:        https://github.com/the-wagner-dev/maps-grid-tracker
 * Description:       Tracks Google Maps rankings of your business on a grid of points around a location, using the DataForSEO API. Tools → Maps Grid.
 * Version:           1.0.3
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            thewagner.dev
 * Author URI:        https://thewagner.dev
 * License:           GPL-2.0-or-later
 * Text Domain:       maps-grid-tracker
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

const MGT_VERSION = '1.0.3';
const MGT_DB_VERSION = '1';
define('MGT_FILE', __FILE__);
define('MGT_DIR', plugin_dir_path(__FILE__));
define('MGT_URL', plugin_dir_url(__FILE__));

require_once MGT_DIR . 'includes/class-mgt-settings.php';
require_once MGT_DIR . 'includes/class-mgt-db.php';
require_once MGT_DIR . 'includes/class-mgt-api.php';
require_once MGT_DIR . 'includes/class-mgt-scanner.php';

if (is_admin()) {
    require_once MGT_DIR . 'includes/class-mgt-admin.php';
    MGT_Admin::init();
}

if (defined('WP_CLI') && WP_CLI) {
    require_once MGT_DIR . 'includes/class-mgt-cli.php';
    WP_CLI::add_command('maps-grid', 'MGT_CLI');
}

add_action('init', function () {
    load_plugin_textdomain('maps-grid-tracker', false, dirname(plugin_basename(MGT_FILE)) . '/languages');
});

add_filter('cron_schedules', function ($schedules) {
    $schedules['mgt_five_minutes'] = [
        'interval' => 5 * MINUTE_IN_SECONDS,
        'display'  => 'Maps Grid Tracker: every 5 minutes',
    ];
    return $schedules;
});

add_action('mgt_tick', ['MGT_Scanner', 'tick']);

register_activation_hook(__FILE__, function () {
    MGT_DB::install();
    if (!wp_next_scheduled('mgt_tick')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'mgt_five_minutes', 'mgt_tick');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('mgt_tick');
});

add_action('plugins_loaded', function () {
    if (get_option('mgt_db_version') !== MGT_DB_VERSION) {
        MGT_DB::install();
    }
});
