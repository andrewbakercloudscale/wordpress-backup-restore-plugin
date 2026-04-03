<?php
/**
 * Uninstall handler — runs when the plugin is deleted via Plugins > Delete.
 *
 * Removes all plugin options and clears scheduled cron events.
 * Backup files in /wp-content/cloudscale-backups/ are intentionally
 * left on disk so that a reinstall or manual restore remains possible.
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove all plugin options.
$csbr_options = [
    'csbr_loaded_version',
    'csbr_schedule_enabled',
    'csbr_run_days',
    'csbr_run_days_saved',
    'csbr_run_hour',
    'csbr_run_minute',
    'csbr_schedule_components',
    'csbr_manual_defaults',
    'csbr_retention',
    'csbr_backup_prefix',
    'csbr_backup_seq',
    'csbr_s3_bucket',
    'csbr_s3_prefix',
    'csbr_s3_log',
    'csbr_gdrive_remote',
    'csbr_gdrive_path',
    'csbr_gdrive_log',
    'csbr_dropbox_remote',
    'csbr_dropbox_path',
    'csbr_dropbox_log',
    'csbr_dropbox_sync_enabled',
    'csbr_dropbox_remote_count',
    'csbr_dropbox_history',
    'csbr_s3_sync_enabled',
    'csbr_gdrive_sync_enabled',
    'csbr_s3_remote_count',
    'csbr_gdrive_remote_count',
    'csbr_ami_sync_enabled',
    'csbr_ami_schedule_days',
    'csbr_cloud_schedule_enabled',
    'csbr_cloud_backup_delay',
    'csbr_ami_prefix',
    'csbr_ami_reboot',
    'csbr_ami_region_override',
    'csbr_ami_max',
    'csbr_ami_log',
    'csbr_ami_run_hour',
    'csbr_ami_run_minute',
    'csbr_s3_history',
    'csbr_gdrive_history',
];

foreach ( $csbr_options as $option ) {
    delete_option( $option );
}

// Plugin Auto Recovery options.
$csbr_par_options = [
    'csbr_par_monitors',
    'csbr_par_history',
    'csbr_par_settings',
    'csbr_par_watchdog_heartbeat',
    'csbr_par_pending_notifications',
];
foreach ( $csbr_par_options as $option ) {
    delete_option( $option );
}

// Remove PAR fatal-error dropin if we wrote it.
$dropin = WP_CONTENT_DIR . '/fatal-error-handler.php';
if ( file_exists( $dropin ) && strpos( file_get_contents( $dropin ), 'CloudScale-PAR-Dropin' ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    wp_delete_file( $dropin );
}

// Remove PAR state file and watchdog script.
$par_dir = WP_CONTENT_DIR . '/cloudscale-backups/.plugin-auto-recovery/';
if ( is_dir( $par_dir ) ) {
    $state_file = $par_dir . 'state.json';
    if ( file_exists( $state_file ) ) {
        wp_delete_file( $state_file );
    }
}
$watchdog_script = '/usr/local/bin/csbr-par-watchdog.sh';
if ( file_exists( $watchdog_script ) ) {
    wp_delete_file( $watchdog_script );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'csbr_scheduled_backup' );
wp_clear_scheduled_hook( 'csbr_scheduled_ami_backup' );
wp_clear_scheduled_hook( 'csbr_ami_poll' );
wp_clear_scheduled_hook( 'csbr_s3_retry' );
wp_clear_scheduled_hook( 'csbr_ami_delete_check' );
