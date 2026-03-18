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
$options = [
    'cs_loaded_version',
    'cs_schedule_enabled',
    'cs_run_days',
    'cs_run_days_saved',
    'cs_run_hour',
    'cs_run_minute',
    'cs_schedule_components',
    'cs_retention',
    'cs_backup_prefix',
    'cs_backup_seq',
    'cs_s3_bucket',
    'cs_s3_prefix',
    'cs_s3_log',
    'cs_gdrive_remote',
    'cs_gdrive_path',
    'cs_gdrive_log',
    'cs_s3_sync_enabled',
    'cs_gdrive_sync_enabled',
    'cs_ami_sync_enabled',
    'cs_cloud_schedule_enabled',
    'cs_cloud_backup_delay',
    'cs_ami_prefix',
    'cs_ami_reboot',
    'cs_ami_region_override',
    'cs_ami_max',
    'cs_ami_log',
    'cs_ami_run_hour',
    'cs_ami_run_minute',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'cs_scheduled_backup' );
wp_clear_scheduled_hook( 'cs_scheduled_ami_backup' );
wp_clear_scheduled_hook( 'cs_ami_poll' );
wp_clear_scheduled_hook( 'cs_s3_retry' );
wp_clear_scheduled_hook( 'cs_ami_delete_check' );
