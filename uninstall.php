<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/class-mgt-db.php';
MGT_DB::uninstall();
foreach (['mgt_settings', 'mgt_db_version', 'mgt_next_run', 'mgt_last_tick', 'mgt_last_cron_error'] as $option) {
    delete_option($option);
}
delete_transient('mgt_account');
wp_clear_scheduled_hook('mgt_tick');
