<?php
/**
 * Plugin Name:       CloudScale Backup & Restore
 * Plugin URI:        https://cloudscale.consulting
 * Description:       No-nonsense WordPress backup and restore. Backs up database, media, plugins and themes into a single zip. Scheduled or manual, with safe restore and maintenance mode.
 * Version:           3.2.251
 * Author:            Andrew Baker
 * Author URI:        https://andrewbaker.ninja
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Tested up to:      6.9
 * Requires PHP:      8.1
 * Text Domain:       cloudscale-backup-restore
 */

defined( 'ABSPATH' ) || exit;

define('CSBR_VERSION',    '3.2.251');
define('CSBR_AMI_POLL_MAX_AGE', 5 * 600);              // Stop polling after 5 attempts (50 minutes)
define('CSBR_AMI_POLL_INTERVAL', 600);                 // Re-poll every 10 minutes
define('CSBR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CSBR_BACKUP_DIR', WP_CONTENT_DIR . '/cloudscale-backups/');
define('CSBR_MAINT_FILE', ABSPATH . '.maintenance');

require_once CSBR_PLUGIN_DIR . 'includes/class-cloudscale-backup-utils.php';
require_once CSBR_PLUGIN_DIR . 'includes/class-plugin-auto-recovery.php';
require_once CSBR_PLUGIN_DIR . 'includes/plugin-auto-recovery-render.php';
CSBR_Plugin_Auto_Recovery::init();

/**
 * Write a message to the PHP error log when WP_DEBUG is enabled.
 *
 * Delegates to CloudScale_Backup_Utils::log(). Silenced in production.
 *
 * @since 1.0.0
 * @param string $message Message to write to the PHP error log.
 * @return void
 */
function csbr_log( string $message ): void {
    CloudScale_Backup_Utils::log( $message );
}

// ============================================================
// Job persistence — bypass object cache
// ============================================================
// The WP object cache drop-in (Redis) stores transients in PHP memory when
// Redis is unavailable. Loopback background workers start a fresh PHP process
// with empty memory, so get_transient() returns false and jobs never run.
// These helpers write directly to wp_options so every PHP process can read them.

function csbr_set_job( string $job_id, array $data, int $ttl = HOUR_IN_SECONDS ): void {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->replace( $wpdb->options, [ 'option_name' => 'csbr_job_' . $job_id,         'option_value' => maybe_serialize( $data ),              'autoload' => 'no' ] );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->replace( $wpdb->options, [ 'option_name' => 'csbr_job_expires_' . $job_id, 'option_value' => (string) ( time() + $ttl ), 'autoload' => 'no' ] );
}

function csbr_get_job( string $job_id ): array|false {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $expires = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'csbr_job_expires_' . $job_id ) );
    if ( $expires && $expires < time() ) { csbr_delete_job( $job_id ); return false; }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'csbr_job_' . $job_id ) );
    return $value !== null ? maybe_unserialize( $value ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_maybe_unserialize -- value written only by this plugin via csbr_set_job()
}

function csbr_delete_job( string $job_id ): void {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete( $wpdb->options, [ 'option_name' => 'csbr_job_' . $job_id ] );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete( $wpdb->options, [ 'option_name' => 'csbr_job_expires_' . $job_id ] );
}

// ============================================================
// Bootstrap
// ============================================================

register_activation_hook(__FILE__, 'csbr_activate');
register_deactivation_hook(__FILE__, 'csbr_deactivate');

/**
 * Plugin activation: create the backup directory and schedule the first cron event.
 *
 * @since 1.0.0
 * @return void
 */
function csbr_activate(): void {
    csbr_ensure_backup_dir();
    csbr_reschedule();
}

/**
 * Plugin deactivation: clear all scheduled hooks, remove maintenance mode, and
 * delete versioned asset files so a reinstall starts clean.
 *
 * @since 1.0.0
 * @return void
 */
function csbr_deactivate(): void {
    wp_clear_scheduled_hook('csbr_scheduled_backup');
    wp_clear_scheduled_hook('csbr_scheduled_ami_backup');
    wp_clear_scheduled_hook('csbr_ami_poll');
    wp_clear_scheduled_hook('csbr_s3_retry');
    wp_clear_scheduled_hook('csbr_ami_delete_check');
    csbr_maintenance_off();

    // Delete only versioned asset copies — never the canonical script.js / style.css source files.
    // Deleting the sources would break the admin UI on Deactivate → Reactivate without a fresh upload.
    $dir = plugin_dir_path(__FILE__);
    foreach (glob($dir . 'script-*.js') ?: [] as $f) { wp_delete_file($f); }
    foreach (glob($dir . 'style-*.css') ?: [] as $f) { wp_delete_file($f); }

    // Clean old assets/ subdirectory from previous versions
    $assets = $dir . 'assets/';
    if (is_dir($assets)) {
        foreach (glob($assets . '*') as $f) { if (is_file($f)) { wp_delete_file($f); } }
        rmdir($assets); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem unavailable in activation/version hooks; empty dir after file deletion
    }

    CSBR_Plugin_Auto_Recovery::deactivate();
}

// Version change detector — runs opcache flush on upgrade
add_action('admin_init', function () {
    $cached = get_option('csbr_loaded_version', '');
    if ($cached !== CSBR_VERSION) {
        if (function_exists('opcache_reset')) { opcache_reset(); }
        $dir = plugin_dir_path(__FILE__);
        // Clean up versioned JS/CSS files created by older plugin versions
        foreach (glob($dir . 'script-*.js') ?: [] as $f) { wp_delete_file($f); }
        foreach (glob($dir . 'style-*.css') ?: [] as $f) { wp_delete_file($f); }
        // Clean old assets/ subfolder from early versions
        $assets = $dir . 'assets/';
        if (is_dir($assets)) {
            foreach (glob($assets . '*') as $f) { if (is_file($f)) { wp_delete_file($f); } }
            rmdir($assets); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem unavailable in activation/version hooks; empty dir after file deletion
        }
        // Migrate legacy cs_ options to csbr_ prefix (one-time, idempotent).
        $legacy_options = [
            'cs_loaded_version','cs_schedule_enabled','cs_run_days','cs_run_days_saved',
            'cs_run_hour','cs_run_minute','cs_schedule_components','cs_auto_repair',
            'cs_retention','cs_backup_prefix','cs_backup_seq',
            'cs_s3_bucket','cs_s3_prefix','cs_s3_log',
            'cs_gdrive_remote','cs_gdrive_path','cs_gdrive_log',
            'cs_ami_prefix','cs_ami_reboot','cs_ami_region_override','cs_ami_max','cs_ami_log',
            'cs_ami_schedule_days','cs_ami_run_hour','cs_ami_run_minute','cs_ami_sync_enabled',
            'cs_gdrive_sync_enabled','cs_dropbox_sync_enabled','cs_onedrive_sync_enabled',
            'cs_cloud_schedule_enabled','cs_notify_enabled','cs_notify_email','cs_notify_on',
            'cs_dropbox_remote','cs_dropbox_path','cs_dropbox_log',
            'cs_onedrive_remote','cs_onedrive_path','cs_onedrive_log',
        ];
        foreach ($legacy_options as $old_key) {
            $new_key = 'csbr_' . substr($old_key, 3); // strip 'cs_', add 'csbr_'
            if (get_option($old_key) !== false && get_option($new_key) === false) {
                update_option($new_key, get_option($old_key), false);
                delete_option($old_key);
            }
        }
        update_option('csbr_loaded_version', CSBR_VERSION);
    }
});

/**
 * Create the backup directory and its protection files if they do not exist.
 *
 * Creates CSBR_BACKUP_DIR, drops an .htaccess deny-all, and adds a silent index.php.
 *
 * @since 1.0.0
 * @return void
 */
function csbr_ensure_backup_dir(): void {
    if (!file_exists(CSBR_BACKUP_DIR)) {
        wp_mkdir_p(CSBR_BACKUP_DIR);
    }
    $htaccess = CSBR_BACKUP_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP Filesystem API unavailable before init; writing to plugin-owned backup dir
    }
    $index = CSBR_BACKUP_DIR . 'index.php';
    if (!file_exists($index)) {
        file_put_contents($index, "<?php // Silence\n");
    }
}

// ============================================================
// Scheduling — day-of-week based
// ============================================================

// Register required cron intervals
add_filter('cron_schedules', function (array $schedules): array {
    if (!isset($schedules['daily'])) {
        $schedules['daily'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => 'Once Daily',
        ];
    }
    return $schedules;
});

// ============================================================
// AMI state poller — WP-Cron single event
// ============================================================

/**
 * Poll AWS for the state of every AMI log entry that is still 'pending'.
 * Scheduled as a single event 10 minutes after an AMI is created.
 * If any entries remain pending and are younger than CSBR_AMI_POLL_MAX_AGE,
 * reschedules itself for another CSBR_AMI_POLL_INTERVAL seconds.
 * Gives up automatically after 2 hours to avoid polling indefinitely.
 *
 * @since 2.74.0
 * @return void
 */
add_action('csbr_ami_poll', 'csbr_ami_poll_handler');
function csbr_ami_poll_handler(): void {
    $aws = csbr_find_aws();
    if (!$aws) {
        csbr_log('[CloudScale Backup & Restore] AMI poll: AWS CLI not found, skipping.');
        return;
    }

    $log     = (array) get_option('csbr_ami_log', []);
    $region  = csbr_get_instance_region();
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $changed = false;
    $still_pending = false;

    foreach ($log as &$entry) {
        // Only poll entries that have an AMI ID and are still pending
        if (empty($entry['ami_id']) || !isset($entry['ok']) || !$entry['ok']) {
            continue;
        }
        if (($entry['state'] ?? '') !== 'pending') {
            continue;
        }

        // Stop polling entries older than CSBR_AMI_POLL_MAX_AGE
        $age = time() - (int) ($entry['time'] ?? 0);
        if ($age > CSBR_AMI_POLL_MAX_AGE) {
            // Don't overwrite with timed-out — let Refresh All query AWS directly
            // Just stop scheduling further polls for this entry
            csbr_log('[CloudScale Backup & Restore] AMI poll: gave up scheduling polls for ' . $entry['ami_id'] . ' after ' . round($age / 60) . ' min — use Refresh All to check current state');
            continue;
        }

        $cmd = escapeshellarg($aws) . ' ec2 describe-images'
             . ' --image-ids ' . escapeshellarg($entry['ami_id'])
             . $region_flag
             . ' --query "Images[0].State"'
             . ' --output text 2>&1';

        $raw   = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $state = $raw;

        if ($state && $state !== 'None' && !str_contains(strtolower($state), 'error') && !str_contains(strtolower($state), 'unable') && !str_contains(strtolower($state), 'invalid')) {
            if ($entry['state'] !== $state) {
                $entry['state'] = $state;
                $changed = true;
                csbr_log('[CloudScale Backup & Restore] AMI poll: ' . $entry['ami_id'] . ' → ' . $state);
            }
            if ($state === 'pending') {
                $still_pending = true;
            }
        } else {
            // AWS returned nothing or an error — likely a credentials issue on this server user
            // Keep state as 'pending' so user knows it hasn't resolved, log the raw output
            $still_pending = true;
            csbr_log('[CloudScale Backup & Restore] AMI poll: describe-images returned "' . $raw . '" for ' . $entry['ami_id'] . ' — possible credentials issue for www-data user. Use Refresh All from the admin UI instead.');
        }
    }
    unset($entry);

    if ($changed) {
        update_option('csbr_ami_log', $log, false);
    }

    // Reschedule if any entries are still pending and not yet timed out
    if ($still_pending && !wp_next_scheduled('csbr_ami_poll')) {
        wp_schedule_single_event(time() + CSBR_AMI_POLL_INTERVAL, 'csbr_ami_poll');
        csbr_log('[CloudScale Backup & Restore] AMI poll: rescheduled in ' . (CSBR_AMI_POLL_INTERVAL / 60) . ' min (pending AMIs remain)');
    }
}

/**
 * Return the configured file-backup days of the week as an array of integers (1=Mon…7=Sun).
 *
 * Reads directly from the database to bypass the object cache so cron handlers
 * always see the most recently saved value.
 *
 * @since 1.0.0
 * @return int[] Day-of-week numbers, e.g. [1, 3, 5] for Mon/Wed/Fri.
 */
function csbr_get_run_days(): array {
    global $wpdb;
    // Read directly from DB — bypasses object cache entirely
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional direct read to bypass object cache and get authoritative cron-safe value
    $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'csbr_run_days' ) );
    if ($raw === null) {
        return [1, 3, 5]; // option row doesn't exist yet — install default
    }
    $saved = maybe_unserialize($raw); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_maybe_unserialize -- value written only by this plugin
    // Empty array in DB means no days saved yet — return defaults
    if (!is_array($saved) || empty($saved)) {
        return [1, 3, 5];
    }
    return array_map('intval', $saved);
}

/**
 * Clear and reschedule the file-backup and AMI cron events based on current settings.
 *
 * Called on activation and whenever the schedule settings are saved.
 *
 * @since 1.0.0
 * @return void
 */
function csbr_reschedule(): void {
    wp_clear_scheduled_hook('csbr_scheduled_backup');
    wp_clear_scheduled_hook('csbr_scheduled_ami_backup');

    $timezone = wp_timezone();
    $now      = new DateTime('now', $timezone);

    // Local file backup — only if local schedule is enabled
    if (get_option('csbr_schedule_enabled', false)) {
        $run_days = csbr_get_run_days();
        if (!empty($run_days)) {
            $hour   = intval(get_option('csbr_run_hour',   3));
            $minute = intval(get_option('csbr_run_minute', 0));
            $candidate = clone $now;
            $candidate->setTime($hour, $minute, 0);
            for ($i = 0; $i <= 7; $i++) {
                $dow = intval($candidate->format('N')); // 1=Mon...7=Sun
                if (in_array($dow, $run_days, true) && $candidate > $now) break;
                $candidate->modify('+1 day');
                $candidate->setTime($hour, $minute, 0);
            }
            wp_schedule_event($candidate->getTimestamp(), 'daily', 'csbr_scheduled_backup');
        }
    }

    // Cloud backup (AMI + S3 + GDrive) — runs at local backup time + delay
    if (get_option('csbr_cloud_schedule_enabled', false)) {
        $cloud_days = array_map('intval', (array) get_option('csbr_ami_schedule_days', []));
        if (!empty($cloud_days)) {
            $delay_mins   = max(15, intval(get_option('csbr_cloud_backup_delay', 30)));
            $base_hour    = intval(get_option('csbr_run_hour',   3));
            $base_minute  = intval(get_option('csbr_run_minute', 0));
            $cloud_total  = ($base_hour * 60 + $base_minute + $delay_mins) % 1440;
            $cloud_hour   = intval($cloud_total / 60);
            $cloud_minute = $cloud_total % 60;
            $candidate = clone $now;
            $candidate->setTime($cloud_hour, $cloud_minute, 0);
            for ($i = 0; $i <= 7; $i++) {
                $dow = intval($candidate->format('N'));
                if (in_array($dow, $cloud_days, true) && $candidate > $now) break;
                $candidate->modify('+1 day');
                $candidate->setTime($cloud_hour, $cloud_minute, 0);
            }
            wp_schedule_event($candidate->getTimestamp(), 'daily', 'csbr_scheduled_ami_backup');
        }
    }
}

add_action('csbr_scheduled_backup', function () {
    // Day-of-week guard — WP cron fires daily so skip days not in the list
    $run_days = csbr_get_run_days();
    $today    = intval((new DateTime('now', wp_timezone()))->format('N'));
    if (!in_array($today, $run_days, true)) return;

    set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort(true);
    csbr_ensure_backup_dir();

    $c = (array) get_option('csbr_schedule_components', ['db', 'media', 'plugins', 'themes']);
    csbr_create_backup(
        in_array('db',        $c, true),
        in_array('media',     $c, true),
        in_array('plugins',   $c, true),
        in_array('themes',    $c, true),
        in_array('mu',        $c, true),
        in_array('languages', $c, true),
        in_array('dropins',   $c, true),
        in_array('htaccess',    $c, true),
        in_array('wpconfig',    $c, true)
    );
    csbr_enforce_retention();
    if ( get_option( 'csbr_auto_repair', false ) ) {
        csbr_run_table_repair();
    }
});

add_action('csbr_scheduled_ami_backup', function () {
    if (!get_option('csbr_cloud_schedule_enabled', false)) return;

    // Day-of-week guard
    $cloud_days = array_map('intval', (array) get_option('csbr_ami_schedule_days', []));
    $today      = intval((new DateTime('now', wp_timezone()))->format('N'));
    if (empty($cloud_days) || !in_array($today, $cloud_days, true)) return;

    // 1. AMI snapshot (if enabled and prefix configured)
    if (get_option('csbr_ami_sync_enabled', true) && get_option('csbr_ami_prefix', '')) {
        $result = csbr_do_create_ami();
        if ($result['ok']) {
            csbr_log('[CloudScale Backup & Restore] Scheduled AMI created: ' . $result['ami_id'] . ' (' . $result['name'] . ')');
        } else {
            csbr_log('[CloudScale Backup & Restore] Scheduled AMI failed: ' . ($result['error'] ?? 'unknown error'));
        }
    }

    // 2. S3 sync of latest local backup zip
    if (get_option('csbr_s3_sync_enabled', true)) {
        $latest = csbr_get_latest_backup_path();
        if ($latest) {
            csbr_sync_to_s3($latest);
            csbr_log('[CloudScale Backup & Restore] Scheduled S3 sync: ' . basename($latest));
        }
    }

    // 3. Google Drive sync of latest local backup zip
    if (get_option('csbr_gdrive_sync_enabled', true)) {
        $latest = $latest ?? csbr_get_latest_backup_path();
        if ($latest) {
            csbr_sync_to_gdrive($latest);
            csbr_log('[CloudScale Backup & Restore] Scheduled GDrive sync: ' . basename($latest));
        }
    }

    // 4. Dropbox sync of latest local backup zip
    if (get_option('csbr_dropbox_sync_enabled', false)) {
        $latest = $latest ?? csbr_get_latest_backup_path();
        if ($latest) {
            csbr_sync_to_dropbox($latest);
            csbr_log('[CloudScale Backup & Restore] Scheduled Dropbox sync: ' . basename($latest));
        }
    }
});

// ============================================================
// Admin menu
// ============================================================

add_action('admin_menu', function () {
    add_management_page(
        __( 'CloudScale Backup & Restore', 'cloudscale-backup-restore' ),
        __( 'CloudScale Backup & Restore', 'cloudscale-backup-restore' ),
        'manage_options',
        'cloudscale-backup',
        'csbr_admin_page'
    );
});

add_action('admin_enqueue_scripts', function (): void {
    // Menu icon CSS must apply on all admin pages — it targets the sidebar nav item.
    wp_register_style( 'csbr-admin-menu-icon', false, [], CSBR_VERSION );
    wp_enqueue_style( 'csbr-admin-menu-icon' ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion, WordPress.WP.GlobalEnqueuedAssets.EnqueuedInSidebar -- inline-only style required on all admin pages for sidebar nav icon
    wp_add_inline_style(
        'csbr-admin-menu-icon',
        '#adminmenu a[href="tools.php?page=cloudscale-backup"]::before {
            font-family: dashicons;
            content: "\f333";
            color: #fff;
            margin-right: 5px;
            vertical-align: middle;
            font-size: 16px;
            line-height: 1;
        }'
    );
});

add_action('admin_enqueue_scripts', function (string $hook): void {
    if ($hook !== 'tools_page_cloudscale-backup') return;
    wp_enqueue_style('csbr-style',   plugin_dir_url(__FILE__) . 'style.css',  [], CSBR_VERSION);
    wp_enqueue_script('csbr-script', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], CSBR_VERSION, true);
    wp_enqueue_script('csbr-par-script', plugin_dir_url(__FILE__) . 'plugin-auto-recovery.js', ['jquery', 'csbr-script'], CSBR_VERSION, true);
    wp_localize_script('csbr-script', 'CSBR', [
        'ajax_url'       => admin_url('admin-ajax.php'),
        'admin_post_url' => admin_url('admin-post.php'),
        'nonce'          => wp_create_nonce('csbr_nonce'),
        'site_url'       => get_site_url(),
        'ami_max'        => max(1, intval(get_option('csbr_ami_max', 10))),
    ]);
});

// ============================================================
// Admin page
// ============================================================

/**
 * Render the plugin admin page (Tools > CloudScale Backup & Restore).
 *
 * @since 1.0.0
 * @return void
 */
function csbr_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'cloudscale-backup-restore' ) );
    }
    csbr_ensure_backup_dir();

    $backups      = csbr_list_backups();
    $upload_dir   = wp_upload_dir();
    $upload_size   = csbr_dir_size($upload_dir['basedir']);
    $plugins_size  = csbr_dir_size(WP_PLUGIN_DIR);
    $themes_size   = csbr_dir_size(get_theme_root());
    // "Other" backup targets
    $mu_path       = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    $mu_size       = is_dir($mu_path) ? csbr_dir_size($mu_path) : 0;
    $lang_path     = WP_CONTENT_DIR . '/languages';
    $lang_size     = is_dir($lang_path) ? csbr_dir_size($lang_path) : 0;
    $htaccess_path = ABSPATH . '.htaccess';
    $htaccess_size = file_exists($htaccess_path) ? (int) filesize($htaccess_path) : 0;
    $wpconfig_path = ABSPATH . 'wp-config.php';
    $wpconfig_size = file_exists($wpconfig_path) ? (int) filesize($wpconfig_path) : 0;
    $dropins_size     = 0;
    foreach (glob(WP_CONTENT_DIR . '/*.php') ?: [] as $_f) { $dropins_size += (int) filesize($_f); }
    // Estimate database size from information_schema
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema size query; caching not applicable for live size estimates
    $db_size = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(data_length + index_length)
         FROM information_schema.TABLES
         WHERE table_schema = %s",
        DB_NAME
    ));
    $next_run     = wp_next_scheduled('csbr_scheduled_backup');
    $ami_next_run = wp_next_scheduled('csbr_scheduled_ami_backup');
    $maint_active = file_exists(CSBR_MAINT_FILE);

    // Disk space
    $free_bytes  = disk_free_space(CSBR_BACKUP_DIR);
    if ($free_bytes === false) $free_bytes = disk_free_space(ABSPATH);
    $total_bytes = disk_total_space(CSBR_BACKUP_DIR);
    if ($total_bytes === false) $total_bytes = disk_total_space(ABSPATH);
    $latest_size = !empty($backups) ? $backups[0]['size'] : 0;

    // Traffic-light: how many more backups fit in free space?
    // Red < 4, Amber < 10, Green >= 10. Fallback to size-relative if no backups yet.
    $baseline     = $latest_size > 0 ? $latest_size : 100 * 1024 * 1024;
    $backups_fit  = ($free_bytes !== false && $baseline > 0) ? (int) floor($free_bytes / $baseline) : null;
    $disk_status  = 'green';
    if ($free_bytes !== false) {
        if ($backups_fit !== null && $backups_fit < 4)   $disk_status = 'red';
        elseif ($backups_fit !== null && $backups_fit < 10) $disk_status = 'amber';
    }

    // Show banner for amber or red
    $show_disk_alert = ($disk_status !== 'green');

    // Table overhead — sum of InnoDB data_free across all tables in this DB
    $csbr_overhead_bytes  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->prepare(
            'SELECT COALESCE(SUM(data_free), 0) FROM information_schema.TABLES WHERE table_schema = %s AND engine = %s',
            DB_NAME,
            'InnoDB'
        )
    );
    $csbr_overhead_mb     = $csbr_overhead_bytes / (1024 * 1024);
    $csbr_overhead_status = $csbr_overhead_mb <= 24 ? 'green' : ( $csbr_overhead_mb < 52 ? 'amber' : 'red' );

    // Handle form POST for schedule save (plain form, no redirect)
    $csbr_schedule_saved_msg = '';
    if (isset($_POST['csbr_action']) && sanitize_key( wp_unslash( $_POST['csbr_action'] ) ) === 'save_schedule') { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside block via check_admin_referer()
        check_admin_referer('csbr_nonce', 'nonce');
        $raw_days   = isset($_POST['run_days']) && is_array($_POST['run_days']) ? wp_unslash( $_POST['run_days'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_admin_referer(); sanitised via array_map('intval') on next line
        $clean_days = array_values(array_filter(array_map('intval', $raw_days), fn($d) => $d >= 1 && $d <= 7));
        update_option('csbr_run_days',         $clean_days);
        update_option('csbr_run_days_saved',   '1');
        update_option('csbr_schedule_enabled', ! empty( wp_unslash( $_POST['schedule_enabled'] ?? '' ) )); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_admin_referer(); checkbox presence only, no value used
        update_option('csbr_run_hour',   max(0, min(23, intval( wp_unslash( $_POST['run_hour']   ?? 3 ) ))));
        update_option('csbr_run_minute', max(0, min(59, intval( wp_unslash( $_POST['run_minute'] ?? 0 ) ))));
        $valid_components = ['db', 'media', 'plugins', 'themes', 'mu', 'languages', 'dropins', 'htaccess', 'wpconfig'];
        $raw_components   = isset($_POST['schedule_components']) && is_array($_POST['schedule_components']) ? wp_unslash( $_POST['schedule_components'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_admin_referer(); values validated via array_intersect() against a whitelist
        $clean_components = array_values(array_intersect($raw_components, $valid_components));
        // Default to core four if nothing selected
        if (empty($clean_components)) { $clean_components = ['db', 'media', 'plugins', 'themes']; }
        update_option('csbr_schedule_components', $clean_components);
        update_option('csbr_auto_repair', ! empty( wp_unslash( $_POST['auto_repair'] ?? '' ) )); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_admin_referer(); checkbox presence only
        update_option('csbr_notify_enabled', ! empty( wp_unslash( $_POST['notify_enabled'] ?? '' ) )); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checkbox presence only
        update_option('csbr_notify_email',   sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- unslashed above
        $raw_notify_on   = sanitize_key( wp_unslash( $_POST['notify_on'] ?? 'both' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_option('csbr_notify_on', in_array($raw_notify_on, ['success','failure','both'], true) ? $raw_notify_on : 'both');
        update_option('csbr_encrypt_password', sanitize_text_field( wp_unslash( $_POST['encrypt_password'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        wp_cache_delete('csbr_run_days',            'options');
        wp_cache_delete('csbr_run_days_saved',      'options');
        wp_cache_delete('csbr_schedule_enabled',    'options');
        wp_cache_delete('csbr_schedule_components', 'options');
        wp_cache_delete('csbr_run_hour',            'options');
        wp_cache_delete('csbr_run_minute',          'options');
        wp_cache_delete('csbr_auto_repair',         'options');
        wp_cache_delete('csbr_notify_enabled',      'options');
        wp_cache_delete('csbr_notify_email',        'options');
        wp_cache_delete('csbr_notify_on',           'options');
        wp_cache_delete('csbr_encrypt_password',    'options');
        wp_cache_delete('alloptions',             'options');
        csbr_reschedule();
        $day_names = ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $saved_labels = implode(', ', array_map(fn($d) => $day_names[$d] ?? $d, $clean_days));
        $csbr_schedule_saved_msg = $saved_labels ?: 'No days selected';
    }

    // Settings — read after any POST save so page shows updated values
    $enabled      = isset($_POST['csbr_action']) ? !empty($_POST['schedule_enabled']) : (bool) get_option('csbr_schedule_enabled', false); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only display; nonce verified in save block above; boolean !empty() check only
    $auto_repair  = isset($_POST['csbr_action']) ? !empty($_POST['auto_repair']) : (bool) get_option('csbr_auto_repair', false); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only display
    $notify_enabled = isset($_POST['csbr_action']) ? !empty($_POST['notify_enabled']) : (bool) get_option('csbr_notify_enabled', false); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $notify_email   = isset($_POST['csbr_action']) ? sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ) : get_option('csbr_notify_email', ''); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $notify_on      = isset($_POST['csbr_action']) ? sanitize_key( wp_unslash( $_POST['notify_on'] ?? 'both' ) ) : get_option('csbr_notify_on', 'both'); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $enc_password   = isset($_POST['csbr_action']) ? sanitize_text_field( wp_unslash( $_POST['encrypt_password'] ?? '' ) ) : (string) get_option('csbr_encrypt_password', ''); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $run_days_sel    = csbr_get_run_days();
    $hour            = intval(get_option('csbr_run_hour',      3));
    $minute          = intval(get_option('csbr_run_minute',    0));
    $ami_run_hour    = intval(get_option('csbr_ami_run_hour',  3));
    $ami_run_minute  = intval(get_option('csbr_ami_run_minute', 30));
    $sched_components  = (array) get_option('csbr_schedule_components', ['db', 'media', 'plugins', 'themes']);
    $manual_defaults   = get_option('csbr_manual_defaults', null);
    $md                = is_array($manual_defaults) ? $manual_defaults : null; // null = no saved defaults yet
    $retention      = intval(get_option('csbr_retention', 8));
    $backup_prefix  = sanitize_key(get_option('csbr_backup_prefix', 'bkup')) ?: 'bkup';
    $s3_bucket     = get_option('csbr_s3_bucket', '');
    $s3_prefix     = get_option('csbr_s3_prefix', 'backups/');
    $s3_saved_msg  = '';
    $gdrive_remote       = get_option('csbr_gdrive_remote', '');
    $gdrive_path         = get_option('csbr_gdrive_path', 'cloudscale-backups/');
    $s3_sync_enabled        = (bool) get_option('csbr_s3_sync_enabled', true);
    $gdrive_sync_enabled    = (bool) get_option('csbr_gdrive_sync_enabled', true);
    $ami_sync_enabled       = (bool) get_option('csbr_ami_sync_enabled', true);
    $cloud_schedule_enabled = (bool) get_option('csbr_cloud_schedule_enabled', false);
    $cloud_backup_delay     = max(15, intval(get_option('csbr_cloud_backup_delay', 30)));
    $ami_prefix          = get_option('csbr_ami_prefix', '');
    $ami_reboot          = (bool) get_option('csbr_ami_reboot', false);
    $ami_region_override = get_option('csbr_ami_region_override', '');
    $ami_max             = intval(get_option('csbr_ami_max', 10));
    $ami_schedule_days   = (array) get_option('csbr_ami_schedule_days', []);
    $dump_method  = csbr_mysqldump_available() ? 'mysqldump (native — fast)' : 'PHP streamed (compatible)';
    $restore_method = csbr_mysql_cli_available() ? 'mysql CLI (native — fast)' : 'PHP streamed (compatible)';

    // MySQL version
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare -- static query; no user input; caching not applicable for live server version
    $mysql_version = $wpdb->get_var( 'SELECT VERSION()' ) ?: 'Unknown';
    $db_label      = 'MySQL ' . $mysql_version . ' — ' . DB_NAME;

    // Disk usage percentage for bar fill
    $free_pct = ($free_bytes !== false && $total_bytes > 0)
        ? min(100, round(($free_bytes / $total_bytes) * 100))
        : null;

    $days_map = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    // Pre-compute cloud vars (needed by Run Backup section which now appears at the top of the page)
    $aws_path = csbr_find_aws();
    $aws_ver  = $aws_path ? trim((string) shell_exec(escapeshellarg($aws_path) . ' --version 2>&1')) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $s3_log    = (array) get_option('csbr_s3_log', []);
    $s3_synced = array_filter($s3_log, fn($e) => !empty($e['ok']));
    $s3_last   = empty($s3_synced) ? null : max(array_column($s3_synced, 'time'));
    $s3_last_failed_entry = csbr_latest_failed_log_entry($s3_log, $s3_last);
    $rclone_path = csbr_find_rclone();
    $rclone_ver  = $rclone_path ? trim((string) shell_exec(escapeshellarg($rclone_path) . ' version --no-check-update 2>&1 | head -1')) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $gdrive_log    = (array) get_option('csbr_gdrive_log', []);
    $gdrive_synced = array_filter($gdrive_log, fn($e) => !empty($e['ok']));
    $gdrive_last   = empty($gdrive_synced) ? null : max(array_column($gdrive_synced, 'time'));
    $gdrive_last_failed_entry = csbr_latest_failed_log_entry($gdrive_log, $gdrive_last);
    $s3_remote_count     = get_option('csbr_s3_remote_count', null);
    $gdrive_remote_count = get_option('csbr_gdrive_remote_count', null);
    try {
        $ami_instance_id = csbr_get_instance_id();
        $ami_region      = csbr_get_instance_region();
        $ami_log         = (array) get_option('csbr_ami_log', []);
        usort($ami_log, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
        $ami_log_recent  = $ami_log;
    } catch (\Throwable $e) {
        $ami_instance_id = '';
        $ami_region      = '';
        $ami_log         = [];
        $ami_log_recent  = [];
        csbr_log('[CloudScale Backup & Restore] AMI panel init error: ' . $e->getMessage());
    }
    $ami_golden_count    = count( array_filter( $ami_log_recent, fn( $e ) => ! empty( $e['golden'] ) ) );
    $ami_nongolden_count = count( array_filter( $ami_log_recent, fn( $e ) =>   empty( $e['golden'] ) ) );
    $s3_history          = (array) get_option( 'csbr_s3_history',     [] );
    uasort( $s3_history, fn( $a, $b ) => ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ) );
    $s3_golden_count     = count( array_filter( $s3_history,     fn( $e ) => ! empty( $e['golden'] ) ) );
    $s3_nongolden_count  = count( array_filter( $s3_history,     fn( $e ) =>   empty( $e['golden'] ) ) );
    $gdrive_history      = (array) get_option( 'csbr_gdrive_history', [] );
    uasort( $gdrive_history, fn( $a, $b ) => ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ) );
    $gdrive_golden_count    = count( array_filter( $gdrive_history, fn( $e ) => ! empty( $e['golden'] ) ) );
    $gdrive_nongolden_count = count( array_filter( $gdrive_history, fn( $e ) =>   empty( $e['golden'] ) ) );
    $dropbox_remote          = get_option( 'csbr_dropbox_remote', '' );
    $dropbox_path            = get_option( 'csbr_dropbox_path', 'cloudscale-backups/' );
    $dropbox_sync_enabled    = (bool) get_option( 'csbr_dropbox_sync_enabled', false );
    $dropbox_log             = (array) get_option( 'csbr_dropbox_log', [] );
    $dropbox_synced          = array_filter( $dropbox_log, fn( $e ) => ! empty( $e['ok'] ) );
    $dropbox_last            = empty( $dropbox_synced ) ? null : max( array_column( $dropbox_synced, 'time' ) );
    $dropbox_last_failed_entry = csbr_latest_failed_log_entry( $dropbox_log, $dropbox_last );
    $dropbox_remote_count    = get_option( 'csbr_dropbox_remote_count', null );
    $dropbox_history         = (array) get_option( 'csbr_dropbox_history', [] );
    uasort( $dropbox_history, fn( $a, $b ) => ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ) );
    $dropbox_golden_count    = count( array_filter( $dropbox_history, fn( $e ) => ! empty( $e['golden'] ) ) );
    $dropbox_nongolden_count = count( array_filter( $dropbox_history, fn( $e ) =>   empty( $e['golden'] ) ) );
    $onedrive_remote          = get_option( 'csbr_onedrive_remote', '' );
    $onedrive_path            = get_option( 'csbr_onedrive_path', 'cloudscale-backups/' );
    $onedrive_sync_enabled    = (bool) get_option( 'csbr_onedrive_sync_enabled', false );
    $onedrive_log             = (array) get_option( 'csbr_onedrive_log', [] );
    $onedrive_synced          = array_filter( $onedrive_log, fn( $e ) => ! empty( $e['ok'] ) );
    $onedrive_last            = empty( $onedrive_synced ) ? null : max( array_column( $onedrive_synced, 'time' ) );
    $onedrive_last_failed_entry = csbr_latest_failed_log_entry( $onedrive_log, $onedrive_last );
    $onedrive_remote_count    = get_option( 'csbr_onedrive_remote_count', null );
    $onedrive_history         = (array) get_option( 'csbr_onedrive_history', [] );
    uasort( $onedrive_history, fn( $a, $b ) => ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ) );
    $onedrive_golden_count    = count( array_filter( $onedrive_history, fn( $e ) => ! empty( $e['golden'] ) ) );
    $onedrive_nongolden_count = count( array_filter( $onedrive_history, fn( $e ) =>   empty( $e['golden'] ) ) );

    // Pre-compute the oldest non-golden entry per provider — shown as "Delete Soon" when at capacity.
    $ami_ok_ng = array_values( array_filter( $ami_log_recent, fn( $e ) => ! empty( $e['ok'] ) && empty( $e['golden'] ) && ( $e['state'] ?? '' ) !== 'deleted in AWS' ) );
    $ami_delete_soon_id = '';
    if ( count( $ami_ok_ng ) >= $ami_max ) {
        usort( $ami_ok_ng, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
        $ami_delete_soon_id = $ami_ok_ng[0]['ami_id'] ?? '';
    }
    $s3_ng = array_filter( $s3_history, fn( $e ) => empty( $e['golden'] ) );
    $s3_delete_soon_name = '';
    if ( count( $s3_ng ) >= $ami_max ) {
        uasort( $s3_ng, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
        $s3_first = array_key_first( $s3_ng );
        $s3_delete_soon_name = $s3_ng[ $s3_first ]['name'] ?? $s3_first;
    }
    $gdrive_ng = array_filter( $gdrive_history, fn( $e ) => empty( $e['golden'] ) );
    $gdrive_delete_soon_name = '';
    if ( count( $gdrive_ng ) >= $ami_max ) {
        uasort( $gdrive_ng, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
        $gdrive_first = array_key_first( $gdrive_ng );
        $gdrive_delete_soon_name = $gdrive_ng[ $gdrive_first ]['name'] ?? $gdrive_first;
    }
    $dropbox_ng = array_filter( $dropbox_history, fn( $e ) => empty( $e['golden'] ) );
    $dropbox_delete_soon_name = '';
    if ( count( $dropbox_ng ) >= $ami_max ) {
        uasort( $dropbox_ng, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
        $dropbox_first = array_key_first( $dropbox_ng );
        $dropbox_delete_soon_name = $dropbox_ng[ $dropbox_first ]['name'] ?? $dropbox_first;
    }
    $onedrive_ng = array_filter( $onedrive_history, fn( $e ) => empty( $e['golden'] ) );
    $onedrive_delete_soon_name = '';
    if ( count( $onedrive_ng ) >= $ami_max ) {
        uasort( $onedrive_ng, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
        $onedrive_first = array_key_first( $onedrive_ng );
        $onedrive_delete_soon_name = $onedrive_ng[ $onedrive_first ]['name'] ?? $onedrive_first;
    }
    ?>
    <div class="wrap cs-wrap">

        <!-- ===================== HEADER ===================== -->
        <div class="cs-header">
            <div class="cs-header-title">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <h1 style="margin:0;">&#9729; <?php echo esc_html__( 'CloudScale Backup &amp; Restore', 'cloudscale-backup-restore' ); ?></h1>
                    <span style="display:inline-block;background:#fff;color:#1565c0;font-size:0.95rem;font-weight:800;letter-spacing:0.04em;padding:3px 14px;border-radius:20px;font-family:monospace;line-height:1.6;flex-shrink:0;">v<?php echo esc_html( CSBR_VERSION ); ?></span>
                </div>
                <p class="cs-header-sub"><?php esc_html_e( 'Database · media · plugins · themes. No timeouts, no limits.', 'cloudscale-backup-restore' ); ?></p>
                <p class="cs-header-free">&#9989; <?php esc_html_e( '100% free forever — no licence, no premium tier, no feature restrictions. Everything is included.', 'cloudscale-backup-restore' ); ?></p>
            </div>
            <div class="cs-header-status">
                <?php if ($maint_active): ?>
                    <span class="cs-badge cs-badge-warn">&#9888; <?php esc_html_e( 'Maintenance Mode Active', 'cloudscale-backup-restore' ); ?></span>
                <?php endif; ?>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <a href="https://andrewbaker.ninja/2026/02/24/cloudscale-backup-restore-a-wordpress-backup-plugin-that-does-exactly-what-it-says/" target="_blank" rel="noopener" class="cs-header-blog"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ffcc80;box-shadow:0 0 5px #ffcc80;flex-shrink:0;"></span>andrewbaker.ninja</a>
                    <a href="https://andrewbaker.ninja/wordpress-plugin-help/backup-restore-help/" target="_blank" rel="noopener" class="cs-header-help">&#128218; Help &amp; Documentation</a>
                </div>
            </div>
        </div>

        <!-- ===================== DISK SPACE ALERT ===================== -->
        <?php if ($show_disk_alert): ?>
        <div class="cs-alert cs-alert-<?php echo esc_attr($disk_status); ?>">
            <?php if ($disk_status === 'red'): ?>
                <span class="cs-alert-icon">🔴</span>
                <div><strong>Critical: Very low disk space</strong> —
                <?php echo esc_html(csbr_format_size((int)$free_bytes)); ?> free<?php if ($backups_fit !== null): ?>, room for approximately <strong><?php echo (int) $backups_fit; ?> more backup(s)</strong><?php endif; ?>.
                Free up space or move old backups off-server immediately.</div>
            <?php else: ?>
                <span class="cs-alert-icon">🟡</span>
                <div><strong>Warning: Disk space is running low</strong> —
                <?php echo esc_html(csbr_format_size((int)$free_bytes)); ?> free<?php if ($backups_fit !== null): ?>, room for approximately <strong><?php echo (int) $backups_fit; ?> more backup(s)</strong><?php endif; ?>.
                Consider freeing space or reducing your retention count.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ===================== TABS ===================== -->
        <div class="cs-tab-bar">
            <button class="cs-tab cs-tab--active" data-tab="local">&#128230; Local Backups</button>
            <button class="cs-tab" data-tab="cloud">&#9729; Cloud Backups</button>
            <button class="cs-tab" data-tab="autorecovery">&#128737; Plugin Auto Recovery</button>
        </div>

        <div id="cs-tab-local" class="cs-tab-panel">

        <!-- ===================== SETTINGS ===================== -->
        <hr style="border:none;border-top:3px solid #37474f;margin:18px 0 16px;">
        <div class="cs-grid cs-grid-1" style="display:flex!important;flex-direction:column!important;gap:16px!important;">

            <!-- SCHEDULE CARD -->
            <div class="cs-card cs-card--blue">
                <form method="post" action="" id="cs-schedule-form">
                <?php wp_nonce_field('csbr_nonce', 'nonce'); ?>
                <input type="hidden" name="csbr_action" value="save_schedule">
                <div class="cs-card-stripe cs-stripe--blue" style="background:linear-gradient(135deg,#1565c0 0%,#2196f3 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">⏰ <?php echo esc_html__( 'Backup Schedule', 'cloudscale-backup-restore' ); ?></h2><button type="button" onclick="csScheduleExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button></div>

                <!-- Enable/disable checkbox — inline with label -->
                <div class="cs-field-group">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-schedule-enabled" name="schedule_enabled" value="1" <?php checked($enabled); ?>>
                        <?php esc_html_e( 'Enable automatic backups', 'cloudscale-backup-restore' ); ?>
                    </label>
                </div>

                <!-- fieldset[disabled] is the only reliable cross-browser disable mechanism -->
                <fieldset id="cs-schedule-controls" <?php echo !$enabled ? 'disabled' : ''; ?> class="cs-schedule-fieldset">
                    <legend class="screen-reader-text">Schedule controls</legend>

                    <!-- File backup days + components + time -->
                    <div class="cs-field-group">
                        <span class="cs-field-label"><?php esc_html_e( 'File backup days', 'cloudscale-backup-restore' ); ?></span>
                        <div class="cs-day-checks">
                            <?php foreach ($days_map as $num => $day_label): ?>
                            <label class="cs-day-check-label">
                                <input type="checkbox"
                                       class="cs-day-check"
                                       name="run_days[]"
                                       value="<?php echo (int) $num; ?>"
                                       <?php checked(in_array($num, $run_days_sel, false)); ?>>
                                <?php echo esc_html($day_label); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cs-field-group">
                        <span class="cs-field-label"><?php esc_html_e( 'Include in scheduled backup', 'cloudscale-backup-restore' ); ?></span>
                        <div style="display:flex;flex-wrap:wrap;gap:6px 20px;margin-top:6px;">
                            <?php
                            $sched_comp_map = [
                                'db'          => 'Database',
                                'media'       => 'Media uploads',
                                'plugins'     => 'Plugins',
                                'themes'      => 'Themes',
                                'mu'          => 'Must-use plugins',
                                'languages'   => 'Languages',
                                'dropins'     => 'Dropins',
                                'htaccess'    => '.htaccess',
                                'wpconfig'    => 'wp-config.php',
                            ];
                            foreach ($sched_comp_map as $key => $label):
                            ?>
                            <label class="cs-option-label" style="margin:0;">
                                <input type="checkbox" name="schedule_components[]" value="<?php echo esc_attr($key); ?>"
                                    <?php checked(in_array($key, $sched_components, true)); ?>>
                                <?php echo esc_html($label); ?>
                                <?php if ($key === 'wpconfig'): ?><span class="cs-sensitive-badge" style="margin-left:4px;">&#9888; credentials</span><?php endif; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="cs-help">Choose which components are included each time the scheduled backup runs. Manual backups always let you choose individually.</p>
                    </div>

                    <div class="cs-field-group">
                        <label class="cs-option-label" style="margin:0;">
                            <input type="checkbox" name="auto_repair" value="1" <?php checked( $auto_repair ); ?>>
                            <?php esc_html_e( 'Run Table Repairs automatically after each scheduled backup', 'cloudscale-backup-restore' ); ?>
                        </label>
                        <p class="cs-help"><?php esc_html_e( 'Runs OPTIMIZE TABLE on any InnoDB tables with overhead after the scheduled backup completes.', 'cloudscale-backup-restore' ); ?></p>
                    </div>

                    <div class="cs-field-group">
                        <label class="cs-field-label" for="cs-run-hour"><?php esc_html_e( 'File backup time', 'cloudscale-backup-restore' ); ?></label>
                        <div class="cs-inline">
                            <select id="cs-run-hour" name="run_hour" class="cs-input-sm">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                    <option value="<?php echo (int) $h; ?>" <?php selected($hour, $h); ?>><?php echo esc_html(str_pad($h, 2, '0', STR_PAD_LEFT)); ?></option>
                                <?php endfor; ?>
                            </select>
                            <span class="cs-muted-text">:</span>
                            <select id="cs-run-minute" name="run_minute" class="cs-input-sm">
                                <?php foreach ([0, 15, 30, 45] as $m): ?>
                                    <option value="<?php echo (int) $m; ?>" <?php selected($minute, $m); ?>><?php echo esc_html(str_pad($m, 2, '0', STR_PAD_LEFT)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="cs-muted-text">server time &nbsp;·&nbsp; now: <?php echo esc_html(current_time('H:i T')); ?> &nbsp;·&nbsp; TZ: <?php echo esc_html(wp_timezone_string()); ?></span>
                        </div>
                        <?php if ($next_run): ?>
                        <p class="cs-help">Next file backup: <strong><?php echo esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'D j M \a\t H:i')); ?></strong></p>
                        <?php endif; ?>
                    </div>

                </fieldset>

                <!-- EMAIL NOTIFICATIONS -->
                <div class="cs-field-group" style="margin-top:16px;padding-top:14px;border-top:1px solid #e0e0e0;">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-notify-enabled" name="notify_enabled" value="1" <?php checked($notify_enabled); ?>>
                        <?php esc_html_e( 'Email notifications', 'cloudscale-backup-restore' ); ?>
                    </label>
                    <p class="cs-help"><?php esc_html_e( 'Send an email after each backup or restore completes.', 'cloudscale-backup-restore' ); ?></p>
                    <div id="cs-notify-controls" style="margin-top:10px;<?php echo $notify_enabled ? '' : 'display:none;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded string literals, not user input ?>">
                        <div style="margin-bottom:8px;">
                            <label class="cs-field-label" for="cs-notify-email" style="display:inline-block;min-width:90px;"><?php esc_html_e( 'Send to', 'cloudscale-backup-restore' ); ?></label>
                            <input type="email" id="cs-notify-email" name="notify_email"
                                   value="<?php echo esc_attr($notify_email); ?>"
                                   placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"
                                   style="max-width:100%;padding:4px 8px;height:32px;">
                            <span class="cs-muted-text" style="margin-left:6px;"><?php esc_html_e( '(leave blank to use admin email)', 'cloudscale-backup-restore' ); ?></span>
                        </div>
                        <div style="display:flex;gap:20px;flex-wrap:wrap;">
                            <label class="cs-option-label" style="margin:0;">
                                <input type="radio" name="notify_on" value="both" <?php checked($notify_on, 'both'); ?>>
                                <?php esc_html_e( 'Success &amp; failures', 'cloudscale-backup-restore' ); ?>
                            </label>
                            <label class="cs-option-label" style="margin:0;">
                                <input type="radio" name="notify_on" value="failure" <?php checked($notify_on, 'failure'); ?>>
                                <?php esc_html_e( 'Failures only', 'cloudscale-backup-restore' ); ?>
                            </label>
                            <label class="cs-option-label" style="margin:0;">
                                <input type="radio" name="notify_on" value="success" <?php checked($notify_on, 'success'); ?>>
                                <?php esc_html_e( 'Success only', 'cloudscale-backup-restore' ); ?>
                            </label>
                        </div>
                        <div style="margin-top:10px;display:flex;align-items:center;gap:10px;">
                            <button type="button" id="cs-notify-test-btn" class="button"><?php esc_html_e( 'Send Test Email', 'cloudscale-backup-restore' ); ?></button>
                            <span id="cs-notify-test-msg" style="font-size:0.88rem;"></span>
                        </div>
                        <p id="cs-notify-port-warn" style="display:none;margin-top:8px;padding:8px 12px;background:#fff8e1;border:1px solid #ffe082;border-radius:4px;font-size:0.85rem;color:#5d4037;"></p>
                    </div>
                </div>

                <!-- BACKUP ENCRYPTION -->
                <div class="cs-field-group" style="margin-top:16px;padding-top:14px;border-top:1px solid #e0e0e0;">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-encrypt-enabled" name="encrypt_enabled" value="1" <?php checked( $enc_password !== '' ); ?>>
                        <?php esc_html_e( 'Encrypt backups (AES-256)', 'cloudscale-backup-restore' ); ?>
                    </label>
                    <p class="cs-help"><?php esc_html_e( 'Password-protect every backup zip with AES-256 encryption. Required to restore encrypted backups — do not lose this password.', 'cloudscale-backup-restore' ); ?></p>
                    <div id="cs-encrypt-controls" style="margin-top:10px;<?php echo $enc_password !== '' ? '' : 'display:none;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded string literals, not user input ?>">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <label class="cs-field-label" for="cs-encrypt-password" style="min-width:90px;"><?php esc_html_e( 'Password', 'cloudscale-backup-restore' ); ?></label>
                            <input type="password" id="cs-encrypt-password" name="encrypt_password"
                                   value="<?php echo esc_attr( $enc_password ); ?>"
                                   autocomplete="new-password"
                                   style="max-width:100%;padding:4px 8px;height:32px;">
                            <button type="button" id="cs-encrypt-toggle" class="button button-small" style="flex-shrink:0;"><?php esc_html_e( 'Show', 'cloudscale-backup-restore' ); ?></button>
                        </div>
                        <div style="margin-top:8px;background:#fff8e1;border:1px solid #f9a825;border-radius:4px;padding:8px 12px;font-size:0.82rem;color:#5d4037;">
                            &#9888; <?php esc_html_e( 'If you change or clear this password, existing encrypted backups cannot be restored. The password is stored in the WordPress database.', 'cloudscale-backup-restore' ); ?>
                        </div>
                        <?php if ( ! defined( 'ZipArchive::EM_AES_256' ) ): ?>
                        <div style="margin-top:8px;background:#ffebee;border:1px solid #e53935;border-radius:4px;padding:8px 12px;font-size:0.82rem;color:#b71c1c;">
                            &#10007; <?php esc_html_e( 'Your PHP/libzip build does not support AES-256 encryption. Upgrade libzip to enable this feature.', 'cloudscale-backup-restore' ); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="cs-off-notice" class="cs-off-notice" <?php echo $enabled ? 'style="display:none"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded static string, no user input ?>>
                    Automatic backups are <strong>off</strong>. Enable the checkbox above to configure a schedule, or run backups manually below.
                </div>

                <button type="submit" name="csbr_save_schedule" class="button button-primary cs-mt"><?php esc_html_e( 'Save Schedule', 'cloudscale-backup-restore' ); ?></button>
                <?php if ($csbr_schedule_saved_msg): ?>
                <span class="cs-saved-msg" style="display:inline;margin-left:10px">✓ <?php echo esc_html($csbr_schedule_saved_msg); ?></span>
                <?php endif; ?>

                </form>
            </div>

            <!-- RETENTION CARD -->
            <?php
            // Compute retention storage estimate server-side
            $ret_needed       = $latest_size > 0 ? $retention * $latest_size : 0;
            $ret_over         = $ret_needed > 0 && $free_bytes !== false && $ret_needed > (int)$free_bytes;
            $ret_has_baseline = $latest_size > 0;
            ?>
            <div class="cs-card cs-card--green">
                <div class="cs-card-stripe cs-stripe--green" style="background:linear-gradient(135deg,#2e7d32 0%,#43a047 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">🗂 <?php echo esc_html__( 'Retention & Storage', 'cloudscale-backup-restore' ); ?></h2><button type="button" onclick="csRetentionExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button></div>

                <div class="cs-field-group">
                    <label class="cs-field-label" for="cs-backup-prefix"><?php esc_html_e( 'Backup filename prefix', 'cloudscale-backup-restore' ); ?></label>
                    <div class="cs-inline">
                        <input type="text" id="cs-backup-prefix"
                               value="<?php echo esc_attr($backup_prefix); ?>"
                               maxlength="32" style="width:390px;padding:4px 8px;height:32px;" placeholder="bkup">
                        <span class="cs-muted-text">_f12.zip</span>
                    </div>
                    <p class="cs-help">Lowercase letters, numbers, and hyphens only. Example: <code>mysite</code> produces <code>mysite_f12.zip</code>. Existing backups are unaffected.</p>
                </div>

                <div class="cs-field-group">
                    <label class="cs-field-label"><?php esc_html_e( 'Keep last', 'cloudscale-backup-restore' ); ?></label>
                    <div class="cs-inline">
                        <input type="number" id="cs-retention"
                               value="<?php echo (int) $retention; ?>" min="1" max="9999"
                               class="cs-input-sm <?php echo esc_attr( $ret_over ? 'cs-retention-over' : '' ); ?>">
                        <span class="cs-muted-text">backups</span>
                        <?php if ($ret_has_baseline): ?>
                        <span id="cs-retention-tl"
                              class="cs-ret-tl cs-ret-tl--<?php echo esc_attr( $ret_over ? 'red' : 'green' ); ?>">
                            <span class="cs-tl-dot"></span>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div id="cs-retention-storage" class="cs-retention-storage"
                         data-latest-size="<?php echo (int)$latest_size; ?>"
                         data-free-bytes="<?php echo (int) ( $free_bytes !== false ? $free_bytes : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer cast, no user data ?>">
                        <?php if ($ret_has_baseline): ?>
                        <div class="cs-ret-row">
                            <span class="cs-ret-label">Estimated storage needed</span>
                            <span id="cs-retention-est"
                                  class="cs-ret-val <?php echo esc_attr( $ret_over ? 'cs-retention-est--over' : '' ); ?>">
                                <?php echo esc_html(csbr_format_size($ret_needed)); ?>
                            </span>
                        </div>
                        <div class="cs-ret-row">
                            <span class="cs-ret-label">Disk free space</span>
                            <span class="cs-ret-val cs-free-<?php echo esc_attr($disk_status); ?>">
                                <?php echo $free_bytes !== false ? esc_html(csbr_format_size((int)$free_bytes)) : 'Unknown'; ?>
                            </span>
                        </div>
                        <?php else: ?>
                        <div class="cs-ret-row">
                            <span id="cs-retention-est" class="cs-retention-no-baseline">
                                Run your first backup to see a storage estimate.
                            </span>
                        </div>
                        <?php endif; ?>
                        <div id="cs-retention-warn" class="cs-retention-warn"
                             <?php if ( ! $ret_over ): ?> style="display:none"<?php endif; ?>>
                            ⚠ Estimated storage exceeds available disk space — reduce the retention count or free up space.
                        </div>
                    </div>

                    <p class="cs-help">Oldest backups beyond this limit are deleted automatically after each run.</p>
                </div>

                <div class="cs-storage-grid">
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Backup storage used</span>
                        <span class="cs-storage-value cs-value--blue"><?php echo esc_html(csbr_format_size(csbr_dir_size(CSBR_BACKUP_DIR))); ?></span>
                    </div>
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Media uploads</span>
                        <span class="cs-storage-value"><?php echo esc_html(csbr_format_size($upload_size)); ?></span>
                    </div>
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Plugins folder</span>
                        <span class="cs-storage-value"><?php echo esc_html(csbr_format_size($plugins_size)); ?></span>
                    </div>
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Themes folder</span>
                        <span class="cs-storage-value"><?php echo esc_html(csbr_format_size($themes_size)); ?></span>
                    </div>
                </div>

                <button type="button" id="cs-save-retention" class="button button-primary cs-mt"><?php esc_html_e( 'Save Retention Settings', 'cloudscale-backup-restore' ); ?></button>
                <span id="cs-retention-saved" class="cs-saved-msg" style="display:none">✓ Saved</span>
            </div>



            <?php if ( false ): // AMI card rendered in Cloud tab ?>
            <div class="cs-card cs-card--indigo">
                <div class="cs-card-stripe cs-stripe--indigo" style="background:linear-gradient(135deg,#1a237e 0%,#3949ab 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128247; <?php echo esc_html__( 'AWS EC2 AMI Snapshot', 'cloudscale-backup-restore' ); ?></h2>
                    <button type="button" onclick="csAmiExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button>
                </div>

                <div class="cs-info-row">
                    <span>Instance ID</span>
                    <strong><?php
                        if ($ami_instance_id) {
                            echo '<span style="color:#2e7d32">&#10003; ' . esc_html($ami_instance_id) . '</span>';
                        } else {
                            echo '<span style="color:#c62828">&#10007; Not detected (not running on EC2, or IMDS unavailable)</span>';
                        }
                    ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Region</span>
                    <strong><?php echo $ami_region ? esc_html($ami_region) : '<span style="color:#999">Unknown — set override below</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- false branch is a hardcoded HTML string ?></strong>
                </div>

                <div class="cs-field-row cs-mt">
                    <label for="cs-ami-region-override"><strong>Region override</strong></label>
                    <input type="text" id="cs-ami-region-override" class="cs-input-sm" placeholder="[Enter Region Override]"
                           value="<?php echo esc_attr($ami_region_override); ?>" style="width:calc(30em - 50px);min-width:200px;">
                    <p class="cs-help">Set this if the region shown above is wrong or Unknown. Bypasses IMDS detection entirely. Example: <code>af-south-1</code></p>
                </div>

                <div class="cs-field-row cs-mt">
                    <div style="display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap;">
                        <div>
                            <label for="cs-ami-prefix"><strong>AMI name prefix</strong></label>
                            <input type="text" id="cs-ami-prefix" placeholder="[Enter Name Prefix]"
                                   value="<?php echo esc_attr($ami_prefix); ?>"
                                   style="width:calc(20em - 50px);min-width:140px;">
                        </div>
                    </div>
                    <p class="cs-help" style="margin-top:6px;">Prefix example: <code>prod-web01</code> &rarr; <code>prod-web01_20260227_1430</code>. The oldest AMI is automatically deregistered when the <em>Max Cloud Backups to Keep</em> limit (set in Cloud Backup Settings above) is exceeded.</p>
                </div>

                <div class="cs-field-group">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-ami-reboot" <?php checked($ami_reboot); ?>>
                        Reboot instance after AMI creation <span class="cs-sensitive-badge" style="margin-left:6px;">&#9888; downtime</span>
                    </label>
                    <p class="cs-help">Rebooting ensures filesystem consistency. Without reboot, the AMI is created from a live (crash consistent) snapshot.</p>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" onclick="csAmiSave()" class="button button-primary"><?php esc_html_e( 'Save AWS EC2 AMI Settings', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csAmiCreate()" class="button" style="margin-left:8px;background:#1a237e!important;color:#fff!important;border-color:#1a237e!important;" <?php if ( ! $ami_instance_id ): ?> disabled title="<?php esc_attr_e( 'EC2 instance not detected', 'cloudscale-backup-restore' ); ?>"<?php endif; ?>>&#128247; <?php esc_html_e( 'Create AMI Now', 'cloudscale-backup-restore' ); ?></button>
                    <span id="cs-ami-settings-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
                </div>


                <?php if (!empty($ami_log_recent)): ?>
                <div class="cs-mt" style="margin-top:16px;">
                    <p class="cs-field-label" style="margin-bottom:6px;"><?php esc_html_e( 'Recent AWS EC2 AMI snapshots', 'cloudscale-backup-restore' ); ?></p>
                    <div class="cs-table-wrap">
                    <table class="widefat cs-table" style="font-size:0.82rem;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'AWS AMI Name', 'cloudscale-backup-restore' ); ?></th>
                                <th style="width:120px;"><?php esc_html_e( 'Tag', 'cloudscale-backup-restore' ); ?></th>
                                <th style="width:130px;"><?php esc_html_e( 'AMI ID', 'cloudscale-backup-restore' ); ?></th>
                                <th style="width:110px;"><?php esc_html_e( 'Created', 'cloudscale-backup-restore' ); ?></th>
                                <th style="width:80px;"><?php esc_html_e( 'Status', 'cloudscale-backup-restore' ); ?></th>
                                <th style="width:220px;"><?php esc_html_e( 'Actions', 'cloudscale-backup-restore' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cs-ami-tbody">
                        <?php foreach ($ami_log_recent as $ami_i => $entry): ?>
                            <?php
                            $row_ami_id    = esc_attr($entry['ami_id'] ?? '');
                            $entry_state   = $entry['state'] ?? '';
                            $is_deleted    = ($entry_state === 'deleted in AWS');
                            $is_ok         = !empty($entry['ok']);
                            $is_golden     = ! empty( $entry['golden'] );
                            $is_delete_soon = ! $is_golden && ! $is_deleted && ( $entry['ami_id'] ?? '' ) === $ami_delete_soon_id;
                            ?>
                            <tr id="cs-ami-row-<?php echo esc_attr( $row_ami_id ); ?>"<?php if ( $is_golden ): ?> class="cs-row-golden"<?php elseif ( $is_delete_soon ): ?> class="cs-row-delete-soon"<?php endif; ?>>
                                <?php $faded = $is_deleted ? 'style="opacity:0.45;"' : ''; ?>
                                <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>><?php echo esc_html($entry['name'] ?? '—'); ?><span class="cs-ami-golden-star"<?php if ( ! $is_golden ): ?> style="display:none;"<?php endif; ?>> &#11088;</span><?php if ( $ami_i === 0 && ! $is_deleted ) echo wp_kses( '<span class="cs-latest-badge">Latest</span>', [ 'span' => [ 'class' => [] ] ] ); ?><?php if ( $is_delete_soon ) echo wp_kses( '<span class="cs-delete-soon-badge">Delete Soon</span>', [ 'span' => [ 'class' => [] ] ] ); ?></td>
                                <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?> id="cs-ami-tag-cell-<?php echo esc_attr( $row_ami_id ); ?>" style="white-space:nowrap;"><span class="cs-ami-tag-text"><?php if ( $entry['tag'] ?? '' ): ?><?php echo esc_html( $entry['tag'] ); ?><?php else: ?><span class="cs-muted-text">No tag</span><?php endif; ?></span> <button type="button" onclick="csAmiTagEdit('<?php echo esc_js( $row_ami_id ); ?>','<?php echo esc_js( $entry['tag'] ?? '' ); ?>')" class="button button-small" title="<?php esc_attr_e( 'Edit tag', 'cloudscale-backup-restore' ); ?>" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;"><?php esc_html_e( 'Edit', 'cloudscale-backup-restore' ); ?></button></td>
                                <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>><code style="font-size:0.78rem;"><?php echo esc_html($entry['ami_id'] ?? '—'); ?></code></td>
                                <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>><?php echo esc_html(isset($entry['time']) ? wp_date('j M Y H:i', $entry['time']) : '—'); ?></td>
                                <td id="cs-ami-state-<?php echo esc_attr( $row_ami_id ); ?>" <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>>
                                    <?php if ($is_deleted): ?>
                                        <span style="color:#999;font-weight:600;">&#128465; deleted in AWS</span>
                                    <?php elseif ($is_ok): ?>
                                        <?php
                                        $sc = $entry_state === 'available' ? '#2e7d32' : ($entry_state === 'pending' ? '#e65100' : '#757575');
                                        $si = $entry_state === 'available' ? '&#10003;' : ($entry_state === 'pending' ? '&#9203;' : '&#10007;');
                                        ?>
                                        <span style="color:<?php echo esc_attr( $sc ); ?>;font-weight:600;"><?php echo $si; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded HTML entity ?> <?php echo esc_html($entry_state ?: 'Created'); ?></span>
                                    <?php else: ?>
                                        <?php $err_raw = substr($entry['error'] ?? '', 0, 120); ?>
                                        <span style="color:#c62828;font-weight:600;" title="<?php echo esc_attr($err_raw ?: 'No error detail recorded'); ?>">&#10007; Failed</span>
                                        <?php if ($err_raw): ?>
                                        <br><span style="color:#c62828;font-size:0.72rem;word-break:break-word;display:block;max-width:180px;line-height:1.3;margin-top:2px;"><?php echo esc_html($err_raw); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td id="cs-ami-actions-<?php echo esc_attr( $row_ami_id ); ?>" style="white-space:nowrap;vertical-align:middle;">
                                    <?php if (!empty($entry['ami_id'])): ?>
                                    <?php if (!$is_deleted): ?>
                                    <button type="button" onclick="csAmiRefreshOne('<?php echo esc_js( $row_ami_id ); ?>')" class="button button-small" title="<?php esc_attr_e( 'Refresh this AMI state from AWS', 'cloudscale-backup-restore' ); ?>" style="min-width:0;padding:2px 6px;margin-bottom:3px;"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></button>
                                    <button type="button" onclick="csAmiSetGolden('<?php echo esc_js( $row_ami_id ); ?>')" class="button button-small" id="cs-ami-golden-btn-<?php echo esc_attr( $row_ami_id ); ?>" data-golden="<?php echo esc_attr( $is_golden ? '1' : '0' ); ?>" title="<?php echo esc_attr( $is_golden ? __( 'Remove Golden Image', 'cloudscale-backup-restore' ) : __( 'Mark as Golden Image', 'cloudscale-backup-restore' ) ); ?>" style="min-width:0;padding:2px 6px;margin-bottom:3px;<?php echo esc_attr( $is_golden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '' ); ?>">&#11088;</button>
                                    <?php if ( $entry_state === 'available' ): ?>
                                    <button type="button" onclick="csAmiRestore('<?php echo esc_js( $row_ami_id ); ?>', '<?php echo esc_js( $entry['name'] ?? '' ); ?>')" class="button button-small" title="<?php esc_attr_e( 'Restore server to this AMI snapshot', 'cloudscale-backup-restore' ); ?>" style="min-width:0;padding:2px 8px;color:#1a237e;border-color:#1a237e;margin-bottom:3px;">&#8617; <?php esc_html_e( 'Restore', 'cloudscale-backup-restore' ); ?></button>
                                    <?php endif; // available ?>
                                    <?php endif; // !$is_deleted ?>
                                    <button type="button" onclick="csAmiDelete('<?php echo esc_js( $row_ami_id ); ?>', '<?php echo esc_js($entry['name'] ?? ''); ?>', <?php echo esc_attr( $is_deleted ? 'true' : 'false' ); ?>)" class="button button-small" title="<?php echo $is_deleted ? esc_attr__( 'Remove record', 'cloudscale-backup-restore' ) : esc_attr__( 'Deregister AMI', 'cloudscale-backup-restore' ); ?>" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php echo $is_deleted ? esc_html__( 'Remove', 'cloudscale-backup-restore' ) : esc_html__( 'Delete', 'cloudscale-backup-restore' ); ?></button>
                                    <?php else: ?>
                                    <button type="button" onclick="csAmiRemoveFailed('<?php echo esc_js($entry['name'] ?? ''); ?>')" class="button button-small" title="<?php esc_attr_e( 'Remove failed record', 'cloudscale-backup-restore' ); ?>" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php esc_html_e( 'Remove', 'cloudscale-backup-restore' ); ?></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; // end: AMI card in Cloud tab ?>

            <!-- SYSTEM INFO CARD -->
            <div class="cs-card cs-card--purple">
                <div class="cs-card-stripe cs-stripe--purple" style="background:linear-gradient(135deg,#6a1b9a 0%,#8e24aa 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">⚙ <?php echo esc_html__( 'System Info', 'cloudscale-backup-restore' ); ?></h2><button type="button" onclick="csSystemExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button></div>

                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Backup method', 'cloudscale-backup-restore' ); ?></span>
                    <strong><?php echo esc_html($dump_method); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Restore method', 'cloudscale-backup-restore' ); ?></span>
                    <strong><?php echo esc_html($restore_method); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'PHP memory limit', 'cloudscale-backup-restore' ); ?></span>
                    <strong><?php echo esc_html(ini_get('memory_limit')); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Max execution time', 'cloudscale-backup-restore' ); ?></span>
                    <strong><?php echo ini_get('max_execution_time') === '0' ? esc_html__( 'Unlimited', 'cloudscale-backup-restore' ) : esc_html(ini_get('max_execution_time')) . 's'; ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Database', 'cloudscale-backup-restore' ); ?></span>
                    <strong><?php echo esc_html($db_label); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Total backups stored', 'cloudscale-backup-restore' ); ?></span>
                    <strong><?php echo (int) count($backups); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Backup directory', 'cloudscale-backup-restore' ); ?></span>
                    <strong class="cs-path"><?php echo esc_html(CSBR_BACKUP_DIR); ?></strong>
                </div>

                <!-- Traffic-light disk row -->
                <div class="cs-info-row cs-disk-row">
                    <span><?php esc_html_e( 'Disk free space', 'cloudscale-backup-restore' ); ?></span>
                    <strong class="cs-tl cs-tl--<?php echo esc_attr($disk_status); ?>">
                        <span class="cs-tl-dot"></span>
                        <?php echo $free_bytes !== false ? esc_html(csbr_format_size((int)$free_bytes)) : 'Unavailable'; ?>
                        <?php if ($total_bytes): ?>
                            <span class="cs-disk-of">/ <?php echo esc_html(csbr_format_size((int)$total_bytes)); ?></span>
                        <?php endif; ?>
                    </strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Latest backup size', 'cloudscale-backup-restore' ); ?></span>
                    <strong><?php echo $latest_size > 0 ? esc_html(csbr_format_size($latest_size)) : '—'; ?></strong>
                </div>

                <?php if ($free_pct !== null): ?>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Percentage free space', 'cloudscale-backup-restore' ); ?></span>
                    <strong class="cs-tl cs-tl--<?php echo esc_attr($disk_status); ?>">
                        <span class="cs-tl-dot"></span>
                        <?php echo (int) $free_pct; ?>%
                    </strong>
                </div>
                <div class="cs-disk-bar-wrap">
                    <div class="cs-disk-bar">
                        <div class="cs-disk-bar-fill cs-disk-fill--<?php echo esc_attr($disk_status); ?>"
                             style="width:<?php echo (int) $free_pct; ?>%"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /cs-grid-1 -->

        <!-- ===================== TABLE OVERHEAD REPAIR ===================== -->
        <hr style="border:none;border-top:3px solid #37474f;margin:18px 0 16px;">
        <div class="cs-card cs-full">
            <div class="cs-card-stripe" style="background:linear-gradient(135deg,#bf360c 0%,#e64a19 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#129529; <?php esc_html_e( 'Table Overhead Repair', 'cloudscale-backup-restore' ); ?></h2><button type="button" onclick="csRepairExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button></div>

            <?php
            if ( $csbr_overhead_status === 'green' ) {
                $csbr_ohd_bg  = '#2e7d32';
                $csbr_ohd_lbl = esc_html__( 'OK', 'cloudscale-backup-restore' );
            } elseif ( $csbr_overhead_status === 'amber' ) {
                $csbr_ohd_bg  = '#fb8c00';
                $csbr_ohd_lbl = esc_html__( 'Warning', 'cloudscale-backup-restore' );
            } else {
                $csbr_ohd_bg  = '#c62828';
                $csbr_ohd_lbl = esc_html__( 'High', 'cloudscale-backup-restore' );
            }
            $csbr_ohd_fmt = round( $csbr_overhead_mb, 1 ) . ' MB ' . esc_html__( 'overhead', 'cloudscale-backup-restore' );
            ?>
            <p style="margin:0 0 12px;">
                <span style="display:inline-block;background:<?php echo esc_attr( $csbr_ohd_bg ); ?>;color:#fff;font-size:0.82rem;font-weight:700;padding:4px 12px;border-radius:5px;">
                    <?php if ( $csbr_overhead_status !== 'green' ) echo '&#9888; '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML entity ?>
                    <?php echo $csbr_ohd_lbl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?> &mdash; <?php echo esc_html( $csbr_ohd_fmt ); ?>
                </span>
            </p>
            <p style="margin:0 0 14px;font-size:0.88rem;color:#555;line-height:1.6;">
                <?php esc_html_e( 'After bulk deletes, MySQL tables accumulate fragmentation (unused space). Running a repair reclaims this space and can improve query performance. Safe to run at any time — tables remain readable during the operation.', 'cloudscale-backup-restore' ); ?>
            </p>
            <button id="cs-repair-btn" type="button" class="button" onclick="csRepairTables()" style="background:#bf360c;color:#fff;border-color:#8d1f05;"><?php esc_html_e( 'Run Repair Now', 'cloudscale-backup-restore' ); ?></button>
            <span id="cs-repair-msg" style="margin-left:10px;font-size:0.88rem;"></span>
        </div>

        <!-- ===================== MANUAL BACKUP ===================== -->
        <hr style="border:none;border-top:3px solid #37474f;margin:18px 0 16px;">
        <div class="cs-card cs-card--orange cs-full">
            <div class="cs-card-stripe cs-stripe--orange" style="background:linear-gradient(135deg,#e65100 0%,#f57c00 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#9654; <?php echo esc_html__( 'Create Local Backup Now', 'cloudscale-backup-restore' ); ?></h2><button type="button" onclick="csBackupExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button></div>
            <?php
            // Pass sizes to JS for live total calculation
            $backup_sizes = [
                'db'        => $db_size,
                'media'     => $upload_size,
                'plugins'   => $plugins_size,
                'themes'    => $themes_size,
                'mu'        => $mu_size,
                'languages' => $lang_size,
                'htaccess'  => $htaccess_size,
                'wpconfig'  => $wpconfig_size,
                'dropins'     => $dropins_size,
                'free'        => $free_bytes !== false ? (int)$free_bytes : 0,
                'latest'    => $latest_size,
            ];
            wp_add_inline_script( 'csbr-script', 'window.CSBR_SIZES = ' . wp_json_encode( $backup_sizes ) . ';', 'before' );
            $s3_diag = [
                'aws_found'   => (bool) $aws_path,
                'aws_version' => $aws_ver,
                'bucket'      => $s3_bucket,
                'prefix'      => $s3_prefix,
                'synced'      => $s3_remote_count !== null ? (int) $s3_remote_count : count($s3_synced),
                'total'       => count($backups),
                'last_fmt'    => $s3_last ? csbr_human_age($s3_last) . ' ago (' . wp_date('j M Y H:i', $s3_last) . ')' : null,
            ];
            wp_add_inline_script( 'csbr-script', 'window.CSBR_S3_DIAG = ' . wp_json_encode( $s3_diag ) . ';', 'before' );
            $gdrive_diag = [
                'rclone_found'   => (bool) $rclone_path,
                'rclone_version' => $rclone_ver,
                'remote'         => $gdrive_remote,
                'path'           => $gdrive_path,
                'synced'         => $gdrive_remote_count !== null ? (int) $gdrive_remote_count : count($gdrive_synced),
                'total'          => count($backups),
                'last_fmt'       => $gdrive_last ? csbr_human_age($gdrive_last) . ' ago (' . wp_date('j M Y H:i', $gdrive_last) . ')' : null,
            ];
            wp_add_inline_script( 'csbr-script', 'window.CSBR_GDRIVE_DIAG = ' . wp_json_encode( $gdrive_diag ) . ';', 'before' );
            ?>
            <div class="cs-run-grid">
                <!-- Column 1: Core -->
                <div class="cs-options-col">
                    <p class="cs-options-col-heading">Core</p>
                    <?php
                    $mc = function(string $key, bool $fallback) use ($md): string {
                        return ($md !== null ? in_array($key, $md, true) : $fallback) ? 'checked' : '';
                    };
                    ?>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-db" <?php echo esc_attr( $mc('db', true) ); ?> data-size="<?php echo (int) $db_size; ?>"> Database <code><?php echo $db_size > 0 ? esc_html(csbr_format_size($db_size)) : '~unknown'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-media" <?php echo esc_attr( $mc('media', true) ); ?> data-size="<?php echo (int) $upload_size; ?>"> Media uploads <code><?php echo esc_html(csbr_format_size($upload_size)); ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-plugins" <?php echo esc_attr( $mc('plugins', true) ); ?> data-size="<?php echo (int) $plugins_size; ?>"> Plugins <code><?php echo esc_html(csbr_format_size($plugins_size)); ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-themes" <?php echo esc_attr( $mc('themes', true) ); ?> data-size="<?php echo (int) $themes_size; ?>"> Themes <code><?php echo esc_html(csbr_format_size($themes_size)); ?></code></label>
                </div>
                <!-- Column 2: Other -->
                <div class="cs-options-col cs-options-col--other">
                    <p class="cs-options-col-heading">Other</p>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-mu" <?php echo esc_attr( $mc('mu', $mu_size > 0) ); ?> data-size="<?php echo absint( $mu_size ); ?>"> Must-use plugins <code><?php echo $mu_size > 0 ? esc_html(csbr_format_size($mu_size)) : '0 B'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-languages" <?php echo esc_attr( $mc('languages', $lang_size > 0) ); ?> data-size="<?php echo absint( $lang_size ); ?>"> Languages <code><?php echo $lang_size > 0 ? esc_html(csbr_format_size($lang_size)) : '0 B'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-dropins" <?php echo esc_attr( $mc('dropins', false) ); ?> data-size="<?php echo (int) $dropins_size; ?>"> Dropins <small>(object-cache.php…)</small> <code><?php echo $dropins_size > 0 ? esc_html(csbr_format_size($dropins_size)) : '0 B'; ?></code></label>
<label class="cs-option-label"><input type="checkbox" id="cs-include-htaccess" <?php echo esc_attr( $mc('htaccess', $htaccess_size > 0) ); ?> data-size="<?php echo absint( $htaccess_size ); ?>"> .htaccess <code><?php echo $htaccess_size > 0 ? esc_html(csbr_format_size($htaccess_size)) : 'not found'; ?></code></label>
                    <label class="cs-option-label cs-option-sensitive">
                        <input type="checkbox" id="cs-include-wpconfig" <?php echo esc_attr( $mc('wpconfig', false) ); ?> data-size="<?php echo (int) $wpconfig_size; ?>">
                        wp-config.php <code><?php echo $wpconfig_size > 0 ? esc_html(csbr_format_size($wpconfig_size)) : 'not found'; ?></code>
                        <span class="cs-sensitive-badge">&#9888; credentials</span>
                    </label>
                </div>
                <!-- Column 3: Summary + button + progress -->
                <div>
                    <div class="cs-backup-summary" id="cs-backup-summary">
                        <div class="cs-backup-summary-row">
                            <span class="cs-summary-label">Estimated backup size</span>
                            <span class="cs-summary-value" id="cs-total-size">—</span>
                        </div>
                        <div class="cs-backup-summary-row">
                            <span class="cs-summary-label">Disk free space</span>
                            <span class="cs-summary-value cs-free-<?php echo esc_attr( $disk_status ); ?>" id="cs-free-space">
                                <?php echo $free_bytes !== false ? esc_html( csbr_format_size( (int) $free_bytes ) ) : 'Unknown'; ?>
                            </span>
                        </div>
                        <div class="cs-backup-summary-row" id="cs-space-warn-row" style="display:none">
                            <span class="cs-summary-warn" id="cs-space-warn"></span>
                        </div>
                    </div>
                    <div id="cs-cloud-space-warn" style="display:none;margin:10px 0 4px;padding:10px 12px;background:#fff8e1;border:1px solid #f57c00;border-radius:4px;font-size:0.84rem;">
                        <div id="cs-cloud-space-rows" style="margin-bottom:8px;"></div>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <button type="button" id="cs-run-backup-anyway" class="button" style="background:#ef6c00;color:#fff;border-color:#bf360c;"><?php esc_html_e( 'Run Backup Anyway', 'cloudscale-backup-restore' ); ?></button>
                            <button type="button" id="cs-space-check-cancel" class="button"><?php esc_html_e( 'Cancel', 'cloudscale-backup-restore' ); ?></button>
                        </div>
                    </div>
                    <button type="button" id="cs-run-backup" class="button button-primary cs-btn-lg">&#9654; <?php esc_html_e( 'Create Local Backup Now', 'cloudscale-backup-restore' ); ?></button>
                    <div style="margin-top:8px;">
                        <button type="button" id="cs-save-manual-defaults" class="button"><?php esc_html_e( 'Save as defaults', 'cloudscale-backup-restore' ); ?></button>
                        <span id="cs-manual-defaults-msg" style="margin-left:8px;font-size:0.82rem;font-weight:600;display:none;"></span>
                    </div>
                    <div id="cs-backup-progress" style="display:none" class="cs-progress-panel">
                        <p id="cs-backup-msg" class="cs-progress-msg">Starting backup...</p>
                        <div class="cs-progress-bar"><div id="cs-backup-fill" class="cs-progress-fill"></div></div>
                        <p class="cs-help">Do not close this window. Large sites may take several minutes.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================== LOCAL BACKUP HISTORY ===================== -->
        <hr style="border:none;border-top:3px solid #37474f;margin:18px 0 16px;">
        <div class="cs-card cs-card--teal cs-full">
            <div class="cs-card-stripe cs-stripe--teal" style="background:linear-gradient(135deg,#004d40 0%,#00897b 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128339; <?php echo esc_html__( 'Local Backups', 'cloudscale-backup-restore' ); ?></h2><button type="button" onclick="csHistoryExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button></div>
            <?php if ( empty( $backups ) ): ?>
                <p class="cs-empty"><?php esc_html_e( 'No backups yet. Run your first backup above.', 'cloudscale-backup-restore' ); ?></p>
            <?php else: ?>
            <div class="cs-table-wrap">
            <table class="widefat cs-table">
                <thead>
                    <tr>
                        <th class="cs-col-actions" style="width:72px!important;min-width:72px!important;max-width:72px!important;"><?php esc_html_e( 'Actions', 'cloudscale-backup-restore' ); ?></th>
                        <th class="cs-col-num" style="width:18px!important;min-width:18px!important;max-width:18px!important;padding-left:4px!important;padding-right:4px!important;"><?php esc_html_e( '#', 'cloudscale-backup-restore' ); ?></th>
                        <th><?php esc_html_e( 'Filename', 'cloudscale-backup-restore' ); ?></th>
                        <th class="cs-col-size"><?php esc_html_e( 'Size', 'cloudscale-backup-restore' ); ?></th>
                        <th class="cs-col-meta"><?php esc_html_e( 'Created', 'cloudscale-backup-restore' ); ?></th>
                        <th class="cs-col-age"><?php esc_html_e( 'Age', 'cloudscale-backup-restore' ); ?></th>
                        <th class="cs-col-type"><?php esc_html_e( 'Type', 'cloudscale-backup-restore' ); ?></th>
                        <?php if ( $s3_bucket ): ?><th class="cs-col-s3" style="width:36px!important;text-align:center;" title="<?php esc_attr_e( 'S3 sync status', 'cloudscale-backup-restore' ); ?>">S3</th><?php endif; ?>
                        <?php if ( $gdrive_remote ): ?><th style="width:36px!important;text-align:center;" title="<?php esc_attr_e( 'Google Drive sync status', 'cloudscale-backup-restore' ); ?>">GDrive</th><?php endif; ?>
                        <?php if ( $dropbox_remote ): ?><th style="width:36px!important;text-align:center;" title="<?php esc_attr_e( 'Dropbox sync status', 'cloudscale-backup-restore' ); ?>">Dropbox</th><?php endif; ?>
                        <?php if ( $onedrive_remote ): ?><th style="width:36px!important;text-align:center;" title="<?php esc_attr_e( 'OneDrive sync status', 'cloudscale-backup-restore' ); ?>">OneDrive</th><?php endif; ?>
                    </tr>
                </thead>
                <?php
                $n_to_delete     = max( 0, count( $backups ) - $retention );
                $hist_col_count  = 7 + ( $s3_bucket ? 1 : 0 ) + ( $gdrive_remote ? 1 : 0 ) + ( $dropbox_remote ? 1 : 0 ) + ( $onedrive_remote ? 1 : 0 );
                if ( $n_to_delete > 0 ):
                ?>
                <tr><td colspan="<?php echo (int) $hist_col_count; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded integer literal ?>" style="padding:6px 10px!important;background:#fff3e0;border-left:3px solid #e65100;font-size:0.82rem;color:#6d3b00;">
                    &#9888; <strong><?php echo (int) $n_to_delete; ?> backup<?php echo $n_to_delete !== 1 ? 's' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded static string ?> will be automatically deleted on the next backup run</strong> — highlighted in orange below. Increase your retention count or download them first to keep them.
                </td></tr>
                <?php endif; ?>
                <tbody>
                <?php
                $s3_log = (array) get_option( 'csbr_s3_log', [] );
                foreach ( $backups as $i => $b ):
                    $is_db         = str_contains( $b['type'], 'DB' ) || $b['type'] === 'Full' || $b['type'] === 'Full+';
                    $s3_entry       = $s3_log[ $b['name'] ]       ?? null;
                    $gdrive_entry   = $gdrive_log[ $b['name'] ]   ?? null;
                    $dropbox_entry  = $dropbox_log[ $b['name'] ]  ?? null;
                    $onedrive_entry = $onedrive_log[ $b['name'] ] ?? null;
                    $will_delete      = $i >= $retention;
                    $delete_soon_local = ! $will_delete && $i === $retention - 1 && count( $backups ) >= $retention;
                ?>
                    <tr class="<?php echo esc_attr( $i === 0 ? 'cs-row-latest' : ( $delete_soon_local ? 'cs-row-delete-soon' : '' ) ); ?>" style="height:40px!important;max-height:40px!important;<?php echo $will_delete ? 'background:linear-gradient(90deg,#fff3e0 0%,#fff8f0 100%);border-left:3px solid #e65100;' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded static string ?>">
                        <td class="cs-col-actions cs-actions" style="width:72px!important;min-width:72px!important;max-width:72px!important;padding:4px 6px!important;vertical-align:middle!important;white-space:nowrap;">
                            <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=csbr_download&file=' . urlencode( $b['name'] ) . '&_wpnonce=' . wp_create_nonce( 'csbr_download' ) ) ); ?>" class="cs-icon-btn cs-icon-btn--blue" title="Download">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            </a>
                            <?php if ( $is_db ): ?>
                            <button type="button" class="cs-icon-btn cs-icon-btn--green cs-restore-btn" data-file="<?php echo esc_attr( $b['name'] ); ?>" data-date="<?php echo esc_attr( $b['date'] ); ?>" title="Restore database">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="cs-icon-btn cs-icon-btn--red cs-delete-btn" data-file="<?php echo esc_attr( $b['name'] ); ?>" title="Delete backup">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                            </button>
                        </td>
                        <td class="cs-col-num cs-idx" style="padding:6px 8px!important;vertical-align:middle!important;white-space:nowrap;"><?php echo (int) ( $i + 1 ); ?><?php if ( $will_delete ) echo wp_kses( '&nbsp;<span style="font-size:0.68rem;font-weight:700;background:#e65100;color:#fff;padding:1px 5px;border-radius:3px;vertical-align:middle;">&#128465; delete</span>', [ 'span' => [ 'style' => [] ] ] ); ?></td>
                        <td class="cs-filename" style="padding:6px 8px!important;vertical-align:middle!important;" title="<?php echo esc_attr( $b['name'] ); ?>"><?php echo esc_html( $b['name'] ); ?><?php if ( ! empty( $b['encrypted'] ) ) echo ' <span title="AES-256 encrypted" style="font-size:0.78rem;">&#128274;</span>'; ?><?php if ( $i === 0 ) echo wp_kses( '<span class="cs-latest-badge">Latest</span>', [ 'span' => [ 'class' => [] ] ] ); ?><?php if ( $delete_soon_local ) echo wp_kses( '<span class="cs-delete-soon-badge">Delete Soon</span>', [ 'span' => [ 'class' => [] ] ] ); ?></td>
                        <td class="cs-col-size" style="padding:6px 8px!important;vertical-align:middle!important;"><?php echo esc_html( csbr_format_size( $b['size'] ) ); ?></td>
                        <td class="cs-col-meta cs-created" style="padding:6px 8px!important;vertical-align:middle!important;"><?php echo esc_html( $b['date'] ); ?></td>
                        <td class="cs-col-age cs-age" style="padding:6px 8px!important;vertical-align:middle!important;"><?php echo esc_html( csbr_human_age( $b['mtime'] ) ); ?></td>
                        <td class="cs-col-type" style="padding:6px 8px!important;vertical-align:middle!important;"><span class="cs-type-badge cs-type-<?php echo esc_attr( preg_replace( '/[^a-z0-9]/', '', str_replace( '+', 'plus', strtolower( explode( ' ', $b['type'] )[0] ) ) ) ); ?>"><?php echo esc_html( $b['type'] ); ?></span></td>
                        <?php if ( $s3_bucket ): ?>
                        <td style="text-align:center;padding:4px 6px!important;vertical-align:middle!important;min-width:48px;">
                            <?php if ( ! $s3_entry ): ?>
                                <button type="button" onclick="csS3SyncFile(this,'<?php echo esc_js( $b['name'] ); ?>')" class="button button-small" style="font-size:10px;padding:1px 7px;height:auto;line-height:1.6;">&#8593; Sync</button>
                            <?php elseif ( $s3_entry['ok'] ): ?>
                                <span title="Synced to S3 on <?php echo esc_attr( wp_date( 'j M Y H:i', $s3_entry['time'] ) ); ?>" style="color:#2e7d32;font-size:14px;">&#10003;</span>
                            <?php elseif ( ! empty( $s3_entry['retry_at'] ) && $s3_entry['retry_at'] > time() ): ?>
                                <?php $mins = max( 1, (int) ceil( ( $s3_entry['retry_at'] - time() ) / 60 ) ); ?>
                                <span style="color:#e65100;font-size:10px;" title="Sync failed — auto-retry in ~<?php echo (int) $mins; ?> min">&#8987; ~<?php echo (int) $mins; ?>m</span>
                            <?php else: ?>
                                <div style="font-size:10px;color:#c62828;line-height:1.3;text-align:left;">
                                    <strong style="display:block;">&#10007; S3 failed</strong>
                                    <span style="word-break:break-all;display:block;margin-bottom:3px;"><?php echo esc_html( substr( $s3_entry['error'] ?? 'Unknown error', 0, 80 ) ); ?></span>
                                    <button type="button" onclick="csS3SyncFile(this,'<?php echo esc_js( $b['name'] ); ?>')" class="button button-small" style="font-size:10px;padding:1px 7px;height:auto;line-height:1.6;">&#8635; Retry</button>
                                </div>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ( $gdrive_remote ): ?>
                        <td style="text-align:center;padding:4px 6px!important;vertical-align:middle!important;min-width:48px;">
                            <?php if ( ! $gdrive_entry ): ?>
                                <span style="color:#bbb;font-size:12px;">—</span>
                            <?php elseif ( $gdrive_entry['ok'] ): ?>
                                <span title="Synced to Google Drive on <?php echo esc_attr( wp_date( 'j M Y H:i', $gdrive_entry['time'] ) ); ?>" style="color:#2e7d32;font-size:14px;">&#10003;</span>
                            <?php else: ?>
                                <span title="<?php echo esc_attr( substr( $gdrive_entry['error'] ?? 'Sync failed', 0, 120 ) ); ?>" style="color:#c62828;font-size:12px;font-weight:700;">&#10007;</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ( $dropbox_remote ): ?>
                        <td style="text-align:center;padding:4px 6px!important;vertical-align:middle!important;min-width:48px;">
                            <?php if ( ! $dropbox_entry ): ?>
                                <span style="color:#bbb;font-size:12px;">—</span>
                            <?php elseif ( $dropbox_entry['ok'] ): ?>
                                <span title="Synced to Dropbox on <?php echo esc_attr( wp_date( 'j M Y H:i', $dropbox_entry['time'] ) ); ?>" style="color:#2e7d32;font-size:14px;">&#10003;</span>
                            <?php else: ?>
                                <span title="<?php echo esc_attr( substr( $dropbox_entry['error'] ?? 'Sync failed', 0, 120 ) ); ?>" style="color:#c62828;font-size:12px;font-weight:700;">&#10007;</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ( $onedrive_remote ): ?>
                        <td style="text-align:center;padding:4px 6px!important;vertical-align:middle!important;min-width:48px;">
                            <?php if ( ! $onedrive_entry ): ?>
                                <span style="color:#bbb;font-size:12px;">—</span>
                            <?php elseif ( $onedrive_entry['ok'] ): ?>
                                <span title="Synced to OneDrive on <?php echo esc_attr( wp_date( 'j M Y H:i', $onedrive_entry['time'] ) ); ?>" style="color:#2e7d32;font-size:14px;">&#10003;</span>
                            <?php else: ?>
                                <span title="<?php echo esc_attr( substr( $onedrive_entry['error'] ?? 'Sync failed', 0, 120 ) ); ?>" style="color:#c62828;font-size:12px;font-weight:700;">&#10007;</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="cs-help cs-mt">Showing <?php echo (int) count( $backups ); ?> backup(s). Retention limit: <?php echo (int) $retention; ?>. Oldest backups are removed automatically after each run.</p>
            <p class="cs-help" style="margin-top:6px;word-break:break-all;">&#128193; <?php esc_html_e( 'Stored at:', 'cloudscale-backup-restore' ); ?> <code id="cs-backup-path"><?php echo esc_html( CSBR_BACKUP_DIR ); ?></code> <button type="button" id="cs-copy-path" onclick="csCopyBackupPath()" style="margin-left:6px;padding:2px 8px;font-size:0.75rem;cursor:pointer;vertical-align:middle;" class="button button-small">Copy</button></p>
            <?php endif; ?>
        </div>

        <!-- ===================== RESTORE FROM UPLOAD ===================== -->
        <hr style="border:none;border-top:3px solid #37474f;margin:18px 0 16px;">
        <div class="cs-card cs-card--red cs-full">
            <div class="cs-card-stripe cs-stripe--red" style="background:linear-gradient(135deg,#b71c1c 0%,#e53935 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">↩ <?php echo esc_html__( 'Restore from Uploaded File', 'cloudscale-backup-restore' ); ?></h2><button type="button" onclick="csRestoreExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button></div>
            <div class="cs-restore-upload-grid">
                <div>
                    <p>Upload a <code>.zip</code> (from this plugin) or a raw <code>.sql</code> file, then choose what to restore.</p>
                    <input type="file" id="cs-restore-file" accept=".zip,.sql" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">

                    <?php /* Step 1 row: Choose File + Upload button */ ?>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap;">
                        <label for="cs-restore-file" id="cs-restore-file-label" class="button" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;flex-shrink:0;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <?php esc_html_e( 'Choose File', 'cloudscale-backup-restore' ); ?>
                        </label>
                        <span id="cs-restore-file-name" style="font-size:0.85rem;color:#666;flex:1;min-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php esc_html_e( 'No file chosen', 'cloudscale-backup-restore' ); ?></span>
                        <button type="button" id="cs-restore-upload-btn" class="button button-secondary" style="display:inline-flex;align-items:center;gap:6px;flex-shrink:0;min-width:200px;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <?php esc_html_e( 'Upload Backup File', 'cloudscale-backup-restore' ); ?>
                        </button>
                    </div>

                    <?php /* Step 2 row: always visible; Restore button disabled until upload completes */ ?>
                    <div id="cs-restore-ready-row" style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap;">
                        <span id="cs-restore-ready-label" style="font-size:0.85rem;color:#999;flex:1;"><?php esc_html_e( 'Upload a file first', 'cloudscale-backup-restore' ); ?></span>
                        <button type="button" id="cs-restore-delete-staged-btn" disabled style="background:none;border:none;color:#c62828;font-size:0.8rem;cursor:pointer;padding:0 4px;flex-shrink:0;opacity:0.3;" title="<?php esc_attr_e( 'Delete staged upload', 'cloudscale-backup-restore' ); ?>">&#x1F5D1;</button>
                        <button type="button" id="cs-restore-open-modal-btn" class="button button-primary" disabled style="display:inline-flex;align-items:center;gap:6px;flex-shrink:0;min-width:200px;justify-content:center;opacity:1;cursor:not-allowed;background:#c5d8f0;border-color:#aac4e8;color:#6a96c8;box-shadow:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0"/><polyline points="9 12 12 9 15 12"/><line x1="12" y1="21" x2="12" y2="9"/></svg>
                            <?php esc_html_e( 'View Restore Options', 'cloudscale-backup-restore' ); ?>
                        </button>
                    </div>
                </div>
                <div id="cs-restore-upload-progress" style="display:none" class="cs-progress-panel">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                        <p id="cs-restore-upload-msg" class="cs-progress-msg" style="margin:0;">Uploading...</p>
                        <button type="button" id="cs-restore-upload-cancel" class="button button-small" style="flex-shrink:0;"><?php esc_html_e( 'Cancel', 'cloudscale-backup-restore' ); ?></button>
                    </div>
                    <div class="cs-progress-bar"><div id="cs-restore-upload-fill" class="cs-progress-fill"></div></div>
                </div>
            </div>
        </div>

        <!-- ===================== RESTORE MODAL ===================== -->

        <!-- ===================== UNIFIED RESTORE MODAL ===================== -->
        <div id="cs-restore-modal" class="cs-modal" style="display:none">
            <div class="cs-modal-box" style="max-width:580px;">
                <h2>⚠ <?php esc_html_e( 'Restore Database', 'cloudscale-backup-restore' ); ?></h2>
                <div class="cs-modal-body">
                    <div class="cs-warning-box" id="cs-modal-warning-full">
                        <strong><?php esc_html_e( 'BEFORE YOU RESTORE — TAKE A SNAPSHOT', 'cloudscale-backup-restore' ); ?></strong>
                        <ul>
                            <li><?php esc_html_e( 'Take a server/VM snapshot in your hosting control panel or AWS console right now.', 'cloudscale-backup-restore' ); ?></li>
                            <li><?php esc_html_e( 'This will put the site into maintenance mode, drop and recreate all database tables, then bring the site back online.', 'cloudscale-backup-restore' ); ?></li>
                            <li><?php esc_html_e( 'Active sessions will be interrupted. Users will see a maintenance page.', 'cloudscale-backup-restore' ); ?></li>
                        </ul>
                    </div>
                    <div class="cs-warning-box" id="cs-modal-warning-selective" style="display:none;background:#fff8e1;border-color:#f9a825;">
                        <strong><?php esc_html_e( 'Selective restore', 'cloudscale-backup-restore' ); ?></strong>
                        <ul>
                            <li><?php esc_html_e( 'Only the selected tables will be dropped and recreated from this backup.', 'cloudscale-backup-restore' ); ?></li>
                            <li><?php esc_html_e( 'All other tables remain untouched. No maintenance mode is used.', 'cloudscale-backup-restore' ); ?></li>
                        </ul>
                    </div>
                    <p style="margin:10px 0 4px;"><?php esc_html_e( 'Restoring from:', 'cloudscale-backup-restore' ); ?> <strong id="cs-modal-filename"></strong> <span id="cs-modal-date-wrap" style="color:#666;">&nbsp;|&nbsp; <?php esc_html_e( 'Created:', 'cloudscale-backup-restore' ); ?> <span id="cs-modal-date"></span></span></p>

                    <div style="margin:14px 0 10px;">
                        <p style="font-weight:600;margin:0 0 8px 0;"><?php esc_html_e( 'What would you like to restore?', 'cloudscale-backup-restore' ); ?></p>
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;">
                            <input type="radio" name="cs-restore-mode" value="full" id="cs-mode-full" checked>
                            <span><strong><?php esc_html_e( 'Full database', 'cloudscale-backup-restore' ); ?></strong> &mdash; <?php esc_html_e( 'drop and recreate all tables', 'cloudscale-backup-restore' ); ?></span>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;">
                            <input type="radio" name="cs-restore-mode" value="selective" id="cs-mode-selective">
                            <span><strong><?php esc_html_e( 'Specific tables only', 'cloudscale-backup-restore' ); ?></strong> &mdash; <?php esc_html_e( 'choose which tables to restore', 'cloudscale-backup-restore' ); ?></span>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="radio" name="cs-restore-mode" value="post" id="cs-mode-post">
                            <span><strong><?php esc_html_e( 'Single post or page', 'cloudscale-backup-restore' ); ?></strong> &mdash; <?php esc_html_e( 'restore one post and its metadata', 'cloudscale-backup-restore' ); ?></span>
                        </label>
                    </div>

                    <div id="cs-modal-post-section" style="display:none;margin:4px 0 12px;">
                        <div id="cs-modal-post-loading" style="padding:10px 0;color:#666;font-size:0.88rem;">&#8987; <?php esc_html_e( 'Loading posts from backup…', 'cloudscale-backup-restore' ); ?></div>
                        <div id="cs-modal-post-wrap" style="display:none;">
                            <input type="text" id="cs-modal-post-search" placeholder="<?php esc_attr_e( 'Search by title or ID…', 'cloudscale-backup-restore' ); ?>" style="width:100%;box-sizing:border-box;padding:5px 8px;margin-bottom:6px;font-size:0.88rem;border:1px solid #ccc;border-radius:4px;">
                            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px;font-size:0.82rem;color:#444;">
                                <label style="display:flex;align-items:center;gap:4px;"><?php esc_html_e( 'From', 'cloudscale-backup-restore' ); ?> <input type="date" id="cs-post-date-from" style="padding:3px 5px;border:1px solid #ccc;border-radius:4px;font-size:0.82rem;"></label>
                                <label style="display:flex;align-items:center;gap:4px;"><?php esc_html_e( 'To', 'cloudscale-backup-restore' ); ?> <input type="date" id="cs-post-date-to" style="padding:3px 5px;border:1px solid #ccc;border-radius:4px;font-size:0.82rem;"></label>
                                <a href="#" id="cs-post-date-clear" style="color:#666;text-decoration:none;font-size:0.8rem;"><?php esc_html_e( 'Clear dates', 'cloudscale-backup-restore' ); ?></a>
                                <span style="margin-left:auto;"><a href="#" id="cs-post-sel-all" style="color:#1565c0;text-decoration:none;"><?php esc_html_e( 'All', 'cloudscale-backup-restore' ); ?></a> / <a href="#" id="cs-post-sel-none" style="color:#1565c0;text-decoration:none;"><?php esc_html_e( 'None', 'cloudscale-backup-restore' ); ?></a></span>
                            </div>
                            <div id="cs-modal-post-list" style="max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;background:#fafafa;"></div>
                        </div>
                        <div id="cs-modal-post-selected" style="display:none;margin-top:8px;padding:7px 10px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;font-size:0.88rem;"></div>
                        <div id="cs-modal-post-error" style="display:none;color:#c62828;font-size:0.88rem;margin-top:6px;"></div>
                    </div>

                    <div id="cs-modal-table-section" style="display:none;margin:4px 0 12px;">
                        <div id="cs-modal-table-loading" style="padding:10px 0;color:#666;font-size:0.88rem;">&#8987; <?php esc_html_e( 'Loading table list…', 'cloudscale-backup-restore' ); ?></div>
                        <div id="cs-modal-table-wrap" style="display:none;">
                            <div style="margin-bottom:6px;">
                                <a href="#" id="cs-modal-sel-all" style="font-size:0.82rem;"><?php esc_html_e( 'Select all', 'cloudscale-backup-restore' ); ?></a>
                                &nbsp;&middot;&nbsp;
                                <a href="#" id="cs-modal-sel-none" style="font-size:0.82rem;"><?php esc_html_e( 'Clear all', 'cloudscale-backup-restore' ); ?></a>
                            </div>
                            <div id="cs-modal-table-list" style="max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:8px 12px;display:flex;flex-direction:column;gap:4px;background:#fafafa;"></div>
                        </div>
                        <div id="cs-modal-table-error" style="display:none;color:#c62828;font-size:0.88rem;margin-top:6px;"></div>
                    </div>

                    <div class="cs-confirm-check">
                        <label>
                            <input type="checkbox" id="cs-confirm-snapshot">
                            <span id="cs-confirm-label"><?php esc_html_e( 'I have taken a server snapshot and understand this will overwrite the live database.', 'cloudscale-backup-restore' ); ?></span>
                        </label>
                    </div>
                </div>
                <div class="cs-modal-footer" id="cs-restore-footer">
                    <button type="button" id="cs-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" id="cs-modal-confirm" class="button button-primary cs-btn-danger" disabled><?php esc_html_e( 'Restore Now', 'cloudscale-backup-restore' ); ?></button>
                </div>
                <div id="cs-modal-progress" style="display:none" class="cs-modal-progress">
                    <p id="cs-modal-progress-msg" class="cs-progress-msg"><?php esc_html_e( 'Enabling maintenance mode...', 'cloudscale-backup-restore' ); ?></p>
                    <div class="cs-progress-bar"><div id="cs-modal-fill" class="cs-progress-fill"></div></div>
                    <p class="cs-help"><?php esc_html_e( 'Do not close this window.', 'cloudscale-backup-restore' ); ?></p>
                </div>
            </div>
        </div>
        <div id="cs-modal-overlay" class="cs-modal-overlay" style="display:none"></div>

        </div><!-- /cs-tab-local -->

        <!-- ===================== CLOUD BACKUPS TAB ===================== -->
        <div id="cs-tab-cloud" class="cs-tab-panel" style="display:none">

            <hr style="border:none;border-top:3px solid #37474f;margin:18px 0 16px;">
            <div class="cs-grid cs-grid-1" style="display:flex!important;flex-direction:column!important;gap:16px!important;">

            <!-- CLOUD SCHEDULE CARD -->
            <div class="cs-card cs-card--blue">
                <div class="cs-card-stripe cs-stripe--blue" style="background:linear-gradient(135deg,#1565c0 0%,#2196f3 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">⏰ <?php echo esc_html__( 'Cloud Backup Settings', 'cloudscale-backup-restore' ); ?></h2><button type="button" onclick="csCloudScheduleExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button></div>

                <?php if (!$enabled && ($s3_sync_enabled || $gdrive_sync_enabled)): ?>
                <div style="background:#fff8e1;border:1px solid #f9a825;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:0.88rem;color:#5d4037;">
                    ⚠ <strong>Local backup schedule is disabled</strong> — S3 and Google Drive will sync the existing latest backup but no new backups will be created automatically. <a href="#cs-tab-local" onclick="jQuery('.cs-tab[data-tab=\'local\']').trigger(\'click\');return false;">Enable it on the Local Backups tab.</a>
                </div>
                <?php endif; ?>

                <div class="cs-field-group">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-cloud-schedule-enabled" <?php checked($cloud_schedule_enabled); ?>>
                        <?php esc_html_e( 'Enable automatic cloud backups', 'cloudscale-backup-restore' ); ?>
                    </label>
                </div>

                <fieldset id="cs-cloud-schedule-controls" <?php echo !$cloud_schedule_enabled ? 'disabled' : ''; ?> class="cs-schedule-fieldset">
                    <legend class="screen-reader-text">Cloud schedule controls</legend>

                    <div class="cs-field-group">
                        <span class="cs-field-label">Cloud backup days</span>
                        <div class="cs-day-checks">
                            <?php foreach ($days_map as $num => $day_label): ?>
                            <label class="cs-day-check-label">
                                <input type="checkbox"
                                       class="cs-ami-day-check"
                                       value="<?php echo (int) $num; ?>"
                                       <?php checked(in_array($num, $ami_schedule_days, false)); ?>>
                                <?php echo esc_html($day_label); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cs-field-group">
                        <label class="cs-field-label" for="cs-cloud-backup-delay"><?php esc_html_e( 'Cloud Backup Delay', 'cloudscale-backup-restore' ); ?></label>
                        <div class="cs-inline">
                            <input type="number" id="cs-cloud-backup-delay" class="cs-input-sm"
                                   min="15" max="1440" step="1"
                                   value="<?php echo (int) $cloud_backup_delay; ?>"
                                   style="width:80px;">
                            <span class="cs-muted-text">minutes after local backup (min 15)</span>
                            <span id="cs-cloud-delay-preview" style="margin-left:10px;font-weight:600;color:#1565c0;"></span>
                        </div>
                        <?php if ($ami_next_run): ?>
                        <p class="cs-help">Next cloud backup: <strong><?php echo esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $ami_next_run), 'D j M \a\t H:i')); ?></strong></p>
                        <?php endif; ?>
                    </div>

                    <div class="cs-field-group" style="border-top:1px solid #e0e0e0;padding-top:14px;margin-top:4px;">
                        <span class="cs-field-label">Include in cloud backup</span>
                        <?php
                        $ami_configured      = ! empty( $ami_prefix ) && ! empty( $ami_instance_id );
                        $s3_configured       = ! empty( $s3_bucket );
                        $gdrive_configured   = ! empty( $gdrive_remote );
                        $dropbox_configured  = ! empty( $dropbox_remote );
                        $onedrive_configured = ! empty( $onedrive_remote );
                        ?>
                        <div style="display:flex;flex-direction:column;gap:6px;margin-top:6px;">
                            <label class="cs-option-label" style="margin:0;<?php echo ! $ami_configured ? 'opacity:0.55;' : ''; ?>">
                                <input type="checkbox" id="cs-cloud-ami-enabled" <?php checked($ami_sync_enabled); ?> <?php echo ! $ami_configured ? 'disabled' : ''; ?>>
                                &#128247; AWS EC2 AMI Snapshot <span class="cs-sensitive-badge" style="margin-left:4px;">&#9888; reboot</span>
                                <?php if ( ! $ami_configured ): ?><span style="font-size:0.72rem;color:#999;margin-left:6px;">— Not configured</span><?php endif; ?>
                            </label>
                            <label class="cs-option-label" style="margin:0;<?php echo ! $s3_configured ? 'opacity:0.55;' : ''; ?>">
                                <input type="checkbox" id="cs-cloud-s3-enabled" <?php checked($s3_sync_enabled); ?> <?php echo ! $s3_configured ? 'disabled' : ''; ?>>
                                &#9729; AWS S3 Remote Backup
                                <?php if ( ! $s3_configured ): ?><span style="font-size:0.72rem;color:#999;margin-left:6px;">— Not configured</span><?php endif; ?>
                            </label>
                            <label class="cs-option-label" style="margin:0;<?php echo ! $gdrive_configured ? 'opacity:0.55;' : ''; ?>">
                                <input type="checkbox" id="cs-cloud-gdrive-enabled" <?php checked($gdrive_sync_enabled); ?> <?php echo ! $gdrive_configured ? 'disabled' : ''; ?>>
                                &#128196; Google Drive Backup
                                <?php if ( ! $gdrive_configured ): ?><span style="font-size:0.72rem;color:#999;margin-left:6px;">— Not configured</span><?php endif; ?>
                            </label>
                            <label class="cs-option-label" style="margin:0;<?php echo ! $dropbox_configured ? 'opacity:0.55;' : ''; ?>">
                                <input type="checkbox" id="cs-cloud-dropbox-enabled" <?php checked($dropbox_sync_enabled); ?> <?php echo ! $dropbox_configured ? 'disabled' : ''; ?>>
                                &#128194; Dropbox Backup
                                <?php if ( ! $dropbox_configured ): ?><span style="font-size:0.72rem;color:#999;margin-left:6px;">— Not configured</span><?php endif; ?>
                            </label>
                            <label class="cs-option-label" style="margin:0;<?php echo ! $onedrive_configured ? 'opacity:0.55;' : ''; ?>">
                                <input type="checkbox" id="cs-cloud-onedrive-enabled" <?php checked($onedrive_sync_enabled); ?> <?php echo ! $onedrive_configured ? 'disabled' : ''; ?>>
                                &#128462; OneDrive Backup
                                <?php if ( ! $onedrive_configured ): ?><span style="font-size:0.72rem;color:#999;margin-left:6px;">— Not configured</span><?php endif; ?>
                            </label>
                        </div>
                        <p class="cs-help">Providers must be configured in their cards below before they can be enabled here.</p>
                    </div>

                    <div class="cs-field-group" style="border-top:1px solid #e0e0e0;padding-top:14px;margin-top:4px;">
                        <label class="cs-field-label" for="cs-ami-max"><strong><?php esc_html_e( 'Max Cloud Backups to Keep', 'cloudscale-backup-restore' ); ?></strong></label>
                        <div class="cs-inline" style="margin-top:4px;">
                            <input type="number" id="cs-ami-max" class="cs-input-sm" min="1" max="999"
                                   value="<?php echo esc_attr($ami_max); ?>" style="width:80px;">
                            <span class="cs-muted-text">backups per destination</span>
                        </div>
                        <p class="cs-help">Applies to AWS S3, Google Drive, and AWS EC2 AMI snapshots. Oldest are deleted automatically when this limit is exceeded.</p>
                    </div>

                </fieldset>

                <div id="cs-cloud-off-notice" class="cs-off-notice" <?php echo $cloud_schedule_enabled ? 'style="display:none"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded static string, no user input ?>>
                    Automatic cloud backups are <strong>off</strong>. Enable the checkbox above to configure a schedule.
                </div>

                <button type="button" onclick="csCloudScheduleSave()" class="button button-primary cs-mt"><?php esc_html_e( 'Save Schedule', 'cloudscale-backup-restore' ); ?></button>
                <span id="cs-cloud-schedule-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
            </div>

            <!-- GOOGLE DRIVE BACKUP CARD -->
            <div class="cs-card cs-card--gdrive">
                <div class="cs-card-stripe" style="background:linear-gradient(135deg,#0f9d58 0%,#34a853 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128196; <?php echo esc_html__( 'Google Drive Backup', 'cloudscale-backup-restore' ); ?></h2>
                    <button type="button" onclick="csGDriveExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button>
                </div>

                <div class="cs-field-row cs-mt">
                    <label for="cs-gdrive-remote"><strong>rclone remote name</strong></label>
                    <input type="text" id="cs-gdrive-remote" class="regular-text" placeholder="[Enter Remote Name]"
                           value="<?php echo esc_attr($gdrive_remote); ?>">
                    <p class="cs-help">The remote name you gave when running <code>rclone config</code>, e.g. <code>gdrive</code>.</p>
                </div>

                <div class="cs-field-row">
                    <label for="cs-gdrive-path"><strong>Destination folder</strong></label>
                    <input type="text" id="cs-gdrive-path" class="regular-text" placeholder="[Enter Folder Path]"
                           value="<?php echo esc_attr($gdrive_path); ?>">
                    <p class="cs-help">Folder path inside the Drive. Trailing slash required. Leave blank to copy to the root.</p>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" onclick="csGDriveSave()" class="button button-primary"><?php esc_html_e( 'Save Drive Settings', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csGDriveTest()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Test Connection', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csGDriveDiagnose()" class="button" style="margin-left:8px;background:#e65100!important;color:#fff!important;border-color:#bf360c!important;"><?php esc_html_e( 'Diagnose', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csGDriveSyncLatest()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Copy Last Backup to Cloud', 'cloudscale-backup-restore' ); ?></button>
                </div>
                <div style="min-height:1.5em;margin-top:5px;">
                    <span id="cs-gdrive-msg" style="font-size:0.85rem;font-weight:600;"></span>
                </div>

                <?php if ($gdrive_remote): ?>
                <div class="cs-info-row cs-mt">
                    <span>Destination</span>
                    <strong><code><?php echo esc_html(rtrim($gdrive_remote, ':') . ':' . ltrim($gdrive_path, '/')); ?></code></strong>
                </div>
                <div class="cs-info-row">
                    <span>Backups in Drive</span>
                    <strong id="cs-gdrive-count-val"><?php echo $gdrive_remote_count !== null ? (int) $gdrive_remote_count : (int) count($gdrive_synced); ?> in Drive &nbsp;·&nbsp; <?php echo (int) count($backups); ?> local</strong>
                </div>
                <div class="cs-info-row">
                    <span>Last sync</span>
                    <strong><?php echo $gdrive_last ? esc_html(csbr_human_age($gdrive_last) . ' (' . wp_date('j M Y H:i', $gdrive_last) . ')') : esc_html__('Never', 'cloudscale-backup-restore'); ?></strong>
                </div>
                <?php if ($gdrive_last_failed_entry): ?>
                <div class="cs-info-row" style="align-items:flex-start;">
                    <span style="color:#c62828;padding-top:1px;"><?php esc_html_e( 'Sync error', 'cloudscale-backup-restore' ); ?></span>
                    <span style="color:#c62828;font-weight:700;display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;overflow-wrap:anywhere;word-break:break-word;">
                        <span><?php echo esc_html( csbr_human_age( $gdrive_last_failed_entry['time'] ) . ' (' . wp_date( 'j M Y H:i', $gdrive_last_failed_entry['time'] ) . ')' ); ?></span>
                        <?php
                        $_err_raw   = substr( $gdrive_last_failed_entry['error'] ?? 'Sync failed', 0, 400 );
                        $_err_clean = preg_replace( '/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2} \S+ : /', '', $_err_raw );
                        $_err_parts = preg_split( '/ \d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2} \S+ : /', $_err_clean, 3 );
                        ?>
                        <span style="font-weight:normal;"><?php echo esc_html( $_err_parts[0] ?? '' ); ?></span>
                        <?php if ( ! empty( $_err_parts[1] ) ): ?><span style="font-weight:normal;"><?php echo esc_html( $_err_parts[1] ); ?></span><?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

            </div>

            <!-- DROPBOX BACKUP CARD -->
            <div class="cs-card cs-card--dropbox">
                <div class="cs-card-stripe" style="background:linear-gradient(135deg,#0032a0 0%,#0061ff 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128194; <?php echo esc_html__( 'Dropbox Backup', 'cloudscale-backup-restore' ); ?></h2>
                    <button type="button" onclick="csDropboxExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button>
                </div>

                <div class="cs-field-row cs-mt">
                    <label for="cs-dropbox-remote"><strong><?php esc_html_e( 'rclone remote name', 'cloudscale-backup-restore' ); ?></strong></label>
                    <input type="text" id="cs-dropbox-remote" class="regular-text" placeholder="[Enter Remote Name]"
                           value="<?php echo esc_attr( $dropbox_remote ); ?>">
                    <p class="cs-help"><?php esc_html_e( 'The remote name you gave when running', 'cloudscale-backup-restore' ); ?> <code>rclone config</code>, e.g. <code>dropbox</code>.</p>
                </div>

                <div class="cs-field-row">
                    <label for="cs-dropbox-path"><strong><?php esc_html_e( 'Destination folder', 'cloudscale-backup-restore' ); ?></strong></label>
                    <input type="text" id="cs-dropbox-path" class="regular-text" placeholder="[Enter Folder Path]"
                           value="<?php echo esc_attr( $dropbox_path ); ?>">
                    <p class="cs-help"><?php esc_html_e( 'Folder path inside Dropbox. Trailing slash required. Leave blank to copy to the Dropbox root.', 'cloudscale-backup-restore' ); ?></p>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" onclick="csDropboxSave()" class="button button-primary"><?php esc_html_e( 'Save Dropbox Settings', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csDropboxTest()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Test Connection', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csDropboxDiagnose()" class="button" style="margin-left:8px;background:#e65100!important;color:#fff!important;border-color:#bf360c!important;"><?php esc_html_e( 'Diagnose', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csDropboxSyncLatest()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Copy Last Backup to Cloud', 'cloudscale-backup-restore' ); ?></button>
                </div>
                <div style="min-height:1.5em;margin-top:5px;">
                    <span id="cs-dropbox-msg" style="font-size:0.85rem;font-weight:600;"></span>
                </div>

                <?php if ( $dropbox_remote ): ?>
                <div class="cs-info-row cs-mt">
                    <span><?php esc_html_e( 'Destination', 'cloudscale-backup-restore' ); ?></span>
                    <strong><code><?php echo esc_html( rtrim( $dropbox_remote, ':' ) . ':' . ltrim( $dropbox_path, '/' ) ); ?></code></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Backups in Dropbox', 'cloudscale-backup-restore' ); ?></span>
                    <strong id="cs-dropbox-count-val"><?php echo $dropbox_remote_count !== null ? (int) $dropbox_remote_count : (int) count( $dropbox_synced ); ?> in Dropbox &nbsp;&middot;&nbsp; <?php echo (int) count( $backups ); ?> local</strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Last sync', 'cloudscale-backup-restore' ); ?></span>
                    <strong><?php echo $dropbox_last ? esc_html( csbr_human_age( $dropbox_last ) . ' (' . wp_date( 'j M Y H:i', $dropbox_last ) . ')' ) : esc_html__( 'Never', 'cloudscale-backup-restore' ); ?></strong>
                </div>
                <?php if ($dropbox_last_failed_entry): ?>
                <div class="cs-info-row" style="align-items:flex-start;">
                    <span style="color:#c62828;padding-top:1px;"><?php esc_html_e( 'Sync error', 'cloudscale-backup-restore' ); ?></span>
                    <span style="color:#c62828;font-weight:700;display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;overflow-wrap:anywhere;word-break:break-word;">
                        <span><?php echo esc_html( csbr_human_age( $dropbox_last_failed_entry['time'] ) . ' (' . wp_date( 'j M Y H:i', $dropbox_last_failed_entry['time'] ) . ')' ); ?></span>
                        <?php
                        $_err_raw   = substr( $dropbox_last_failed_entry['error'] ?? 'Sync failed', 0, 400 );
                        $_err_clean = preg_replace( '/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2} \S+ : /', '', $_err_raw );
                        $_err_parts = preg_split( '/ \d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2} \S+ : /', $_err_clean, 3 );
                        ?>
                        <span style="font-weight:normal;"><?php echo esc_html( $_err_parts[0] ?? '' ); ?></span>
                        <?php if ( ! empty( $_err_parts[1] ) ): ?><span style="font-weight:normal;"><?php echo esc_html( $_err_parts[1] ); ?></span><?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- ONEDRIVE BACKUP CARD -->
            <div class="cs-card cs-card--onedrive">
                <div class="cs-card-stripe" style="background:linear-gradient(135deg,#0078d4 0%,#00bcf2 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128462; <?php echo esc_html__( 'OneDrive Backup', 'cloudscale-backup-restore' ); ?></h2>
                    <button type="button" onclick="csOneDriveExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button>
                </div>

                <div class="cs-field-row cs-mt">
                    <label for="cs-onedrive-remote"><strong><?php esc_html_e( 'rclone remote name', 'cloudscale-backup-restore' ); ?></strong></label>
                    <input type="text" id="cs-onedrive-remote" class="regular-text" placeholder="[Enter Remote Name]"
                           value="<?php echo esc_attr( $onedrive_remote ); ?>">
                    <p class="cs-help"><?php esc_html_e( 'The remote name you gave when running', 'cloudscale-backup-restore' ); ?> <code>rclone config</code>, e.g. <code>onedrive</code>.</p>
                </div>

                <div class="cs-field-row">
                    <label for="cs-onedrive-path"><strong><?php esc_html_e( 'Destination folder', 'cloudscale-backup-restore' ); ?></strong></label>
                    <input type="text" id="cs-onedrive-path" class="regular-text" placeholder="[Enter Folder Path]"
                           value="<?php echo esc_attr( $onedrive_path ); ?>">
                    <p class="cs-help"><?php esc_html_e( 'Folder path inside OneDrive. Trailing slash required. Leave blank to copy to the root.', 'cloudscale-backup-restore' ); ?></p>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" onclick="csOneDriveSave()" class="button button-primary"><?php esc_html_e( 'Save OneDrive Settings', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csOneDriveTest()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Test Connection', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csOneDriveDiagnose()" class="button" style="margin-left:8px;background:#e65100!important;color:#fff!important;border-color:#bf360c!important;"><?php esc_html_e( 'Diagnose', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csOneDriveSyncLatest()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Copy Last Backup to Cloud', 'cloudscale-backup-restore' ); ?></button>
                </div>
                <div style="min-height:1.5em;margin-top:5px;">
                    <span id="cs-onedrive-msg" style="font-size:0.85rem;font-weight:600;"></span>
                </div>

                <?php if ( $onedrive_remote ): ?>
                <div class="cs-info-row cs-mt">
                    <span><?php esc_html_e( 'Destination', 'cloudscale-backup-restore' ); ?></span>
                    <strong><code><?php echo esc_html( rtrim( $onedrive_remote, ':' ) . ':' . ltrim( $onedrive_path, '/' ) ); ?></code></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Backups in OneDrive', 'cloudscale-backup-restore' ); ?></span>
                    <strong id="cs-onedrive-count-val"><?php echo $onedrive_remote_count !== null ? (int) $onedrive_remote_count : (int) count( $onedrive_synced ); ?> in OneDrive &nbsp;&middot;&nbsp; <?php echo (int) count( $backups ); ?> local</strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Last sync', 'cloudscale-backup-restore' ); ?></span>
                    <strong><?php echo $onedrive_last ? esc_html( csbr_human_age( $onedrive_last ) . ' (' . wp_date( 'j M Y H:i', $onedrive_last ) . ')' ) : esc_html__( 'Never', 'cloudscale-backup-restore' ); ?></strong>
                </div>
                <?php if ( $onedrive_last_failed_entry ): ?>
                <div class="cs-info-row" style="align-items:flex-start;">
                    <span style="color:#c62828;padding-top:1px;"><?php esc_html_e( 'Sync error', 'cloudscale-backup-restore' ); ?></span>
                    <span style="color:#c62828;font-weight:700;display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;overflow-wrap:anywhere;word-break:break-word;">
                        <span><?php echo esc_html( csbr_human_age( $onedrive_last_failed_entry['time'] ) . ' (' . wp_date( 'j M Y H:i', $onedrive_last_failed_entry['time'] ) . ')' ); ?></span>
                        <?php
                        $_err_raw   = substr( $onedrive_last_failed_entry['error'] ?? 'Sync failed', 0, 400 );
                        $_err_clean = preg_replace( '/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2} \S+ : /', '', $_err_raw );
                        $_err_parts = preg_split( '/ \d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2} \S+ : /', $_err_clean, 3 );
                        ?>
                        <span style="font-weight:normal;"><?php echo esc_html( $_err_parts[0] ?? '' ); ?></span>
                        <?php if ( ! empty( $_err_parts[1] ) ): ?><span style="font-weight:normal;"><?php echo esc_html( $_err_parts[1] ); ?></span><?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- S3 REMOTE BACKUP CARD -->
            <div class="cs-card cs-card--pink">
                <div class="cs-card-stripe cs-stripe--pink" style="background:linear-gradient(135deg,#880e4f 0%,#e91e8c 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#9729; <?php echo esc_html__( 'AWS S3 Remote Backup', 'cloudscale-backup-restore' ); ?></h2>
                    <button type="button" onclick="csS3Explain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button>
                </div>

                <div class="cs-field-row cs-mt">
                    <label for="cs-s3-bucket"><strong>S3 Bucket name</strong></label>
                    <input type="text" id="cs-s3-bucket" class="regular-text" placeholder="[Enter Bucket Name]"
                           value="<?php echo esc_attr($s3_bucket); ?>">
                    <p class="cs-help">Bucket name only &mdash; no <code>s3://</code> prefix.</p>
                </div>

                <div class="cs-field-row">
                    <label for="cs-s3-prefix"><strong>Key prefix (folder)</strong></label>
                    <input type="text" id="cs-s3-prefix" class="regular-text" placeholder="[Enter Path Prefix]"
                           value="<?php echo esc_attr($s3_prefix); ?>">
                    <p class="cs-help">Trailing slash required. Leave as <code>backups/</code> or set to <code>/</code> for bucket root.</p>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" onclick="csS3Save()" class="button button-primary"><?php esc_html_e( 'Save AWS S3 Settings', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csS3Test()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Test Connection', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csS3Diagnose()" class="button" style="margin-left:8px;background:#e65100!important;color:#fff!important;border-color:#bf360c!important;"><?php esc_html_e( 'Diagnose', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csS3SyncLatest()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Copy Last Backup to Cloud', 'cloudscale-backup-restore' ); ?></button>
                </div>
                <div style="min-height:1.5em;margin-top:5px;">
                    <span id="cs-s3-msg" style="font-size:0.85rem;font-weight:600;"></span>
                </div>

                <?php if ($s3_bucket): ?>
                <div class="cs-info-row cs-mt">
                    <span>Destination</span>
                    <strong><code>s3://<?php echo esc_html(rtrim($s3_bucket, '/') . '/' . ltrim($s3_prefix, '/')); ?></code></strong>
                </div>
                <div class="cs-info-row">
                    <span>Backups in AWS S3</span>
                    <strong id="cs-s3-count-val"><?php echo $s3_remote_count !== null ? (int) $s3_remote_count : (int) count($s3_synced); ?> in bucket &nbsp;·&nbsp; <?php echo (int) count($backups); ?> local</strong>
                </div>
                <div class="cs-info-row">
                    <span>Last S3 sync</span>
                    <strong><?php echo $s3_last ? esc_html(csbr_human_age($s3_last) . ' (' . wp_date('j M Y H:i', $s3_last) . ')') : esc_html__('Never', 'cloudscale-backup-restore'); ?></strong>
                </div>
                <?php if ($s3_last_failed_entry): ?>
                <div class="cs-info-row" style="align-items:flex-start;">
                    <span style="color:#c62828;padding-top:1px;"><?php esc_html_e( 'Sync error', 'cloudscale-backup-restore' ); ?></span>
                    <span style="color:#c62828;font-weight:700;display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;overflow-wrap:anywhere;word-break:break-word;">
                        <span><?php echo esc_html( csbr_human_age( $s3_last_failed_entry['time'] ) . ' (' . wp_date( 'j M Y H:i', $s3_last_failed_entry['time'] ) . ')' ); ?></span>
                        <?php
                        $_err_raw   = substr( $s3_last_failed_entry['error'] ?? 'Sync failed', 0, 400 );
                        $_err_clean = preg_replace( '/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2} \S+ : /', '', $_err_raw );
                        $_err_parts = preg_split( '/ \d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2} \S+ : /', $_err_clean, 3 );
                        ?>
                        <span style="font-weight:normal;"><?php echo esc_html( $_err_parts[0] ?? '' ); ?></span>
                        <?php if ( ! empty( $_err_parts[1] ) ): ?><span style="font-weight:normal;"><?php echo esc_html( $_err_parts[1] ); ?></span><?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

            </div>

            <!-- AMI SNAPSHOT CARD -->
            <div class="cs-card cs-card--indigo">
                <div class="cs-card-stripe cs-stripe--indigo" style="background:linear-gradient(135deg,#1a237e 0%,#3949ab 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128247; <?php echo esc_html__( 'AWS EC2 AMI Snapshot', 'cloudscale-backup-restore' ); ?></h2>
                    <button type="button" onclick="csAmiExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button>
                </div>

                <div class="cs-info-row">
                    <span>Instance ID</span>
                    <strong><?php
                        if ($ami_instance_id) {
                            echo '<span style="color:#2e7d32">&#10003; ' . esc_html($ami_instance_id) . '</span>';
                        } else {
                            echo '<span style="color:#c62828">&#10007; Not detected (not running on EC2, or IMDS unavailable)</span>';
                        }
                    ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Region</span>
                    <strong><?php echo $ami_region ? esc_html($ami_region) : '<span style="color:#999">Unknown — set override below</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- false branch is a hardcoded HTML string ?></strong>
                </div>

                <div class="cs-field-row cs-mt">
                    <label for="cs-ami-region-override"><strong>Region override</strong></label>
                    <input type="text" id="cs-ami-region-override" class="cs-input-sm" placeholder="[Enter Region Override]"
                           value="<?php echo esc_attr($ami_region_override); ?>" style="width:calc(30em - 50px);min-width:200px;">
                    <p class="cs-help">Set this if the region shown above is wrong or Unknown. Bypasses IMDS detection entirely. Example: <code>af-south-1</code></p>
                </div>

                <div class="cs-field-row cs-mt">
                    <div style="display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap;">
                        <div>
                            <label for="cs-ami-prefix"><strong>AMI name prefix</strong></label>
                            <input type="text" id="cs-ami-prefix" placeholder="[Enter Name Prefix]"
                                   value="<?php echo esc_attr($ami_prefix); ?>"
                                   style="width:calc(20em - 50px);min-width:140px;">
                        </div>
                    </div>
                    <p class="cs-help" style="margin-top:6px;">Prefix example: <code>prod-web01</code> &rarr; <code>prod-web01_20260227_1430</code>. The oldest AMI is automatically deregistered when the <em>Max Cloud Backups to Keep</em> limit (set in Cloud Backup Settings above) is exceeded.</p>
                </div>

                <div class="cs-field-group">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-ami-reboot" <?php checked($ami_reboot); ?>>
                        Reboot instance after AMI creation <span class="cs-sensitive-badge" style="margin-left:6px;">&#9888; downtime</span>
                    </label>
                    <p class="cs-help">Rebooting ensures filesystem consistency. Without reboot, the AMI is created from a live (crash consistent) snapshot.</p>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" onclick="csAmiSave()" class="button button-primary"><?php esc_html_e( 'Save AWS EC2 AMI Settings', 'cloudscale-backup-restore' ); ?></button>
                    <button type="button" onclick="csAmiCreate()" class="button" style="margin-left:8px;background:#1a237e!important;color:#fff!important;border-color:#1a237e!important;" <?php if ( ! $ami_instance_id ): ?> disabled title="<?php esc_attr_e( 'EC2 instance not detected', 'cloudscale-backup-restore' ); ?>"<?php endif; ?>>&#128247; <?php esc_html_e( 'Create AMI Now', 'cloudscale-backup-restore' ); ?></button>
                    <span id="cs-ami-settings-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
                </div>

            </div>

            </div><!-- /cs-grid-1 cloud -->

        <!-- ===================== UNIFIED BACKUP HISTORY PANEL ===================== -->
        <hr style="border:none;border-top:3px solid #37474f;margin:18px 0 16px;">
        <div class="cs-card cs-card--teal cs-full" id="cs-history-panel">
            <div class="cs-card-stripe cs-stripe--teal" style="background:linear-gradient(135deg,#004d40 0%,#00897b 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128339; <?php echo esc_html__( 'Cloud Backup History', 'cloudscale-backup-restore' ); ?></h2><button type="button" onclick="csHistoryExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button></div>

            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #e0e0e0;">
                <label for="cs-history-source" style="font-weight:600;font-size:0.9rem;white-space:nowrap;margin:0;"><?php esc_html_e( 'View history for:', 'cloudscale-backup-restore' ); ?></label>
                <select id="cs-history-source" style="padding:5px 10px;font-size:0.88rem;border:1px solid #ccc;border-radius:4px;min-width:260px;">
                    <option value="s3">&#9729; AWS S3</option>
                    <option value="ami">&#128247; AWS EC2 AMI Snapshots</option>
                    <option value="gdrive">&#128196; <?php esc_html_e( 'Google Drive', 'cloudscale-backup-restore' ); ?></option>
                    <option value="dropbox">&#128194; Dropbox</option>
                    <option value="onedrive">&#9729; OneDrive</option>
                </select>
                <!-- Per-provider action bars — shown/hidden by JS -->
                <div id="cs-hist-act-s3" class="cs-hist-act" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <button type="button" onclick="csS3HistoryRefresh()" class="button" id="cs-s3h-refresh-btn" style="background:#1565c0!important;color:#fff!important;border-color:#1565c0!important;">&#8635; <?php esc_html_e( 'Refresh', 'cloudscale-backup-restore' ); ?></button>
                    <span id="cs-s3h-golden-count" style="font-size:0.82rem;color:#f57f17;font-weight:600;">&#11088; <?php echo (int) $s3_golden_count; ?> / 4 golden&emsp;<?php echo (int) $s3_nongolden_count; ?> / <?php echo (int) $ami_max; ?> <?php esc_html_e( 'max backups', 'cloudscale-backup-restore' ); ?></span>
                    <span id="cs-s3h-msg" style="font-size:0.85rem;font-weight:600;"></span>
                </div>
                <div id="cs-hist-act-gdrive" class="cs-hist-act" style="display:none;align-items:center;gap:8px;flex-wrap:wrap;">
                    <button type="button" onclick="csGDriveHistoryRefresh()" class="button" id="cs-gd-refresh-btn" style="background:#1565c0!important;color:#fff!important;border-color:#1565c0!important;">&#8635; <?php esc_html_e( 'Refresh', 'cloudscale-backup-restore' ); ?></button>
                    <span id="cs-gd-golden-count" style="font-size:0.82rem;color:#f57f17;font-weight:600;">&#11088; <?php echo (int) $gdrive_golden_count; ?> / 4 golden&emsp;<?php echo (int) $gdrive_nongolden_count; ?> / <?php echo (int) $ami_max; ?> <?php esc_html_e( 'max backups', 'cloudscale-backup-restore' ); ?></span>
                    <span id="cs-gd-msg" style="font-size:0.85rem;font-weight:600;"></span>
                </div>
                <div id="cs-hist-act-dropbox" class="cs-hist-act" style="display:none;align-items:center;gap:8px;flex-wrap:wrap;">
                    <button type="button" onclick="csDropboxHistoryRefresh()" class="button" id="cs-db-refresh-btn" style="background:#1565c0!important;color:#fff!important;border-color:#1565c0!important;">&#8635; <?php esc_html_e( 'Refresh', 'cloudscale-backup-restore' ); ?></button>
                    <span id="cs-db-golden-count" style="font-size:0.82rem;color:#f57f17;font-weight:600;">&#11088; <?php echo (int) $dropbox_golden_count; ?> / 4 golden&emsp;<?php echo (int) $dropbox_nongolden_count; ?> / <?php echo (int) $ami_max; ?> <?php esc_html_e( 'max backups', 'cloudscale-backup-restore' ); ?></span>
                    <span id="cs-db-msg" style="font-size:0.85rem;font-weight:600;"></span>
                </div>
                <div id="cs-hist-act-onedrive" class="cs-hist-act" style="display:none;align-items:center;gap:8px;flex-wrap:wrap;">
                    <button type="button" onclick="csOneDriveHistoryRefresh()" class="button" id="cs-od-refresh-btn" style="background:#1565c0!important;color:#fff!important;border-color:#1565c0!important;">&#8635; <?php esc_html_e( 'Refresh', 'cloudscale-backup-restore' ); ?></button>
                    <span id="cs-od-golden-count" style="font-size:0.82rem;color:#f57f17;font-weight:600;">&#11088; <?php echo (int) $onedrive_golden_count; ?> / 4 golden&emsp;<?php echo (int) $onedrive_nongolden_count; ?> / <?php echo (int) $ami_max; ?> <?php esc_html_e( 'max backups', 'cloudscale-backup-restore' ); ?></span>
                    <span id="cs-od-msg" style="font-size:0.85rem;font-weight:600;"></span>
                </div>
                <div id="cs-hist-act-ami" class="cs-hist-act" style="display:none;align-items:center;gap:8px;flex-wrap:wrap;">
                    <button type="button" onclick="csAmiResetAndRefresh()" class="button" id="cs-ami-refresh-all" style="background:#1565c0!important;color:#fff!important;border-color:#1565c0!important;">&#8635; <?php esc_html_e( 'Refresh', 'cloudscale-backup-restore' ); ?></button>
                    <span id="cs-ami-golden-count" style="font-size:0.82rem;color:#f57f17;font-weight:600;">&#11088; <?php echo (int) $ami_golden_count; ?> / 4 golden&emsp;<?php echo (int) $ami_nongolden_count; ?> / <?php echo (int) $ami_max; ?> <?php esc_html_e( 'max backups', 'cloudscale-backup-restore' ); ?></span>
                    <span id="cs-ami-msg" style="font-size:0.85rem;font-weight:600;"></span>
                </div>
            </div>

            <!-- S3 PANE -->
            <div id="cs-hist-pane-s3" class="cs-hist-pane" style="display:none">
                <p class="cs-help" style="margin:0 0 12px;"><?php esc_html_e( 'Queries AWS S3 live — picks up all backups including ones made before this screen existed, and removes any you deleted directly in AWS S3.', 'cloudscale-backup-restore' ); ?></p>
                <?php if ( ! empty( $s3_history ) ): ?>
                <div class="cs-table-wrap">
                <table class="widefat cs-table" style="font-size:0.82rem;" id="cs-s3h-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'File Name', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Tag', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Size', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'Created', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( 'Actions', 'cloudscale-backup-restore' ); ?></th>
                    </tr></thead>
                    <tbody id="cs-s3h-tbody">
                    <?php $s3_first_key = array_key_first( $s3_history ); ?>
                    <?php foreach ( $s3_history as $sf_key => $sf ): ?>
                        <?php
                        $sf_name        = $sf['name'] ?? $sf_key;
                        $sf_golden      = ! empty( $sf['golden'] );
                        $sf_tag         = $sf['tag'] ?? '';
                        $sf_key_e       = esc_attr( preg_replace( '/[^a-zA-Z0-9_\-]/', '_', $sf_name ) );
                        $sf_js          = esc_js( $sf_name );
                        $sf_delete_soon = ! $sf_golden && $sf_name === $s3_delete_soon_name;
                        ?>
                        <tr id="cs-s3h-row-<?php echo $sf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>"<?php if ( $sf_golden ): ?> class="cs-row-golden"<?php elseif ( $sf_delete_soon ): ?> class="cs-row-delete-soon"<?php endif; ?>>
                            <td><?php echo esc_html( $sf_name ); ?><span class="cs-s3h-golden-star"<?php if ( ! $sf_golden ): ?> style="display:none;"<?php endif; ?>> &#11088;</span><?php if ( $sf_key === $s3_first_key ) echo wp_kses( '<span class="cs-latest-badge">Latest</span>', [ 'span' => [ 'class' => [] ] ] ); ?><?php if ( $sf_delete_soon ) echo wp_kses( '<span class="cs-delete-soon-badge">Delete Soon</span>', [ 'span' => [ 'class' => [] ] ] ); ?></td>
                            <td id="cs-s3h-tag-cell-<?php echo $sf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" style="white-space:nowrap;">
                                <span class="cs-s3h-tag-text"><?php if ( $sf_tag ): ?><?php echo esc_html( $sf_tag ); ?><?php else: ?><span class="cs-muted-text">No tag</span><?php endif; ?></span>
                                <button type="button" onclick="csS3HistoryTagEdit('<?php echo $sf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>','<?php echo esc_js( $sf_tag ); ?>')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;"><?php esc_html_e( 'Edit', 'cloudscale-backup-restore' ); ?></button>
                            </td>
                            <td><?php echo esc_html( $sf['size_fmt'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $sf['time'] ? wp_date( 'j M Y H:i', $sf['time'] ) : '—' ); ?></td>
                            <td id="cs-s3h-actions-<?php echo $sf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" style="white-space:nowrap;vertical-align:middle;">
                                <button type="button" onclick="csS3HistorySetGolden('<?php echo $sf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>')" class="button button-small" id="cs-s3h-golden-btn-<?php echo $sf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" data-golden="<?php echo esc_attr( $sf_golden ? '1' : '0' ); ?>" title="<?php echo esc_attr( $sf_golden ? __( 'Remove Golden Image', 'cloudscale-backup-restore' ) : __( 'Mark as Golden Image', 'cloudscale-backup-restore' ) ); ?>" style="min-width:0;padding:2px 6px;margin-bottom:3px;<?php echo esc_attr( $sf_golden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '' ); ?>">&#11088;</button>
                                <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'csbr_s3_download', 'file' => $sf_name, 'nonce' => wp_create_nonce( 'csbr_nonce' ) ], admin_url( 'admin-post.php' ) ) ); ?>" class="button button-small" style="min-width:0;padding:2px 6px;margin-bottom:3px;text-decoration:none;display:inline-block;">&#8659; <?php esc_html_e( 'Download', 'cloudscale-backup-restore' ); ?></a>
                                <button type="button" onclick="csS3HistoryDelete('<?php echo $sf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>')" class="button button-small" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php esc_html_e( 'Delete', 'cloudscale-backup-restore' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <p class="cs-empty"><?php esc_html_e( 'No AWS S3 backups found. Click "Sync from AWS S3" to query your bucket live.', 'cloudscale-backup-restore' ); ?></p>
                <?php endif; ?>
            </div>

            <!-- GOOGLE DRIVE PANE -->
            <div id="cs-hist-pane-gdrive" class="cs-hist-pane" style="display:none">
                <p class="cs-help" style="margin:0 0 12px;"><?php esc_html_e( 'Queries Google Drive live — picks up all backups including ones made before this screen existed, and removes any you deleted directly in Drive.', 'cloudscale-backup-restore' ); ?></p>
                <?php if ( ! empty( $gdrive_history ) ): ?>
                <div class="cs-table-wrap">
                <table class="widefat cs-table" style="font-size:0.82rem;" id="cs-gd-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'File Name', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Tag', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Size', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'Created', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:180px;"><?php esc_html_e( 'Actions', 'cloudscale-backup-restore' ); ?></th>
                    </tr></thead>
                    <tbody id="cs-gd-tbody">
                    <?php $gd_first_key = array_key_first( $gdrive_history ); ?>
                    <?php foreach ( $gdrive_history as $gf_key => $gf ): ?>
                        <?php
                        $gf_name        = $gf['name'] ?? $gf_key;
                        $gf_golden      = ! empty( $gf['golden'] );
                        $gf_tag         = $gf['tag'] ?? '';
                        $gf_key_e       = esc_attr( preg_replace( '/[^a-zA-Z0-9_\-]/', '_', $gf_name ) );
                        $gf_js          = esc_js( $gf_name );
                        $gf_delete_soon = ! $gf_golden && $gf_name === $gdrive_delete_soon_name;
                        ?>
                        <tr id="cs-gd-row-<?php echo $gf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>"<?php if ( $gf_golden ): ?> class="cs-row-golden"<?php elseif ( $gf_delete_soon ): ?> class="cs-row-delete-soon"<?php endif; ?>>
                            <td><?php echo esc_html( $gf_name ); ?><span class="cs-gd-golden-star"<?php if ( ! $gf_golden ): ?> style="display:none;"<?php endif; ?>> &#11088;</span><?php if ( $gf_key === $gd_first_key ) echo wp_kses( '<span class="cs-latest-badge">Latest</span>', [ 'span' => [ 'class' => [] ] ] ); ?><?php if ( $gf_delete_soon ) echo wp_kses( '<span class="cs-delete-soon-badge">Delete Soon</span>', [ 'span' => [ 'class' => [] ] ] ); ?></td>
                            <td id="cs-gd-tag-cell-<?php echo $gf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" style="white-space:nowrap;">
                                <span class="cs-gd-tag-text"><?php if ( $gf_tag ): ?><?php echo esc_html( $gf_tag ); ?><?php else: ?><span class="cs-muted-text">No tag</span><?php endif; ?></span>
                                <button type="button" onclick="csGDriveHistoryTagEdit('<?php echo $gf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>','<?php echo esc_js( $gf_tag ); ?>')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>
                            </td>
                            <td><?php echo esc_html( $gf['size_fmt'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $gf['time'] ? wp_date( 'j M Y H:i', $gf['time'] ) : '—' ); ?></td>
                            <td id="cs-gd-actions-<?php echo $gf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" style="white-space:nowrap;vertical-align:middle;">
                                <button type="button" onclick="csGDriveHistorySetGolden('<?php echo $gf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>')" class="button button-small" id="cs-gd-golden-btn-<?php echo $gf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" data-golden="<?php echo esc_attr( $gf_golden ? '1' : '0' ); ?>" title="<?php echo esc_attr( $gf_golden ? __( 'Remove Golden Image', 'cloudscale-backup-restore' ) : __( 'Mark as Golden Image', 'cloudscale-backup-restore' ) ); ?>" style="min-width:0;padding:2px 6px;margin-bottom:3px;<?php echo esc_attr( $gf_golden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '' ); ?>">&#11088;</button>
                                <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'csbr_gdrive_download', 'file' => $gf_name, 'nonce' => wp_create_nonce( 'csbr_nonce' ) ], admin_url( 'admin-post.php' ) ) ); ?>" class="button button-small" style="min-width:0;padding:2px 6px;margin-bottom:3px;text-decoration:none;display:inline-block;">&#8659; <?php esc_html_e( 'Download', 'cloudscale-backup-restore' ); ?></a>
                                <button type="button" onclick="csGDriveHistoryDelete('<?php echo $gf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>')" class="button button-small" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php esc_html_e( 'Delete', 'cloudscale-backup-restore' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <p class="cs-empty"><?php esc_html_e( 'No Google Drive backups found. Click "Sync from Google Drive" to query your Drive live.', 'cloudscale-backup-restore' ); ?></p>
                <?php endif; ?>
            </div>

            <!-- DROPBOX PANE -->
            <div id="cs-hist-pane-dropbox" class="cs-hist-pane" style="display:none">
                <p class="cs-help" style="margin:0 0 12px;"><?php esc_html_e( 'Queries Dropbox live via rclone — picks up all backups including ones made before this screen existed, and removes any you deleted directly in Dropbox.', 'cloudscale-backup-restore' ); ?></p>
                <div class="cs-table-wrap" <?php echo empty( $dropbox_history ) ? 'style="display:none"' : ''; ?> id="cs-db-table-wrap">
                <table class="widefat cs-table" style="font-size:0.82rem;" id="cs-db-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'File Name', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Tag', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Size', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'Created', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:180px;"><?php esc_html_e( 'Actions', 'cloudscale-backup-restore' ); ?></th>
                    </tr></thead>
                    <tbody id="cs-db-tbody">
                    <?php $db_first_key = array_key_first( $dropbox_history ); ?>
                    <?php foreach ( $dropbox_history as $dbf_key => $dbf ): ?>
                        <?php
                        $dbf_name        = $dbf['name'] ?? $dbf_key;
                        $dbf_golden      = ! empty( $dbf['golden'] );
                        $dbf_tag         = $dbf['tag'] ?? '';
                        $dbf_key_e       = esc_attr( preg_replace( '/[^a-zA-Z0-9_\-]/', '_', $dbf_name ) );
                        $dbf_js          = esc_js( $dbf_name );
                        $dbf_delete_soon = ! $dbf_golden && $dbf_name === $dropbox_delete_soon_name;
                        ?>
                        <tr id="cs-db-row-<?php echo $dbf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>"<?php if ( $dbf_golden ): ?> class="cs-row-golden"<?php elseif ( $dbf_delete_soon ): ?> class="cs-row-delete-soon"<?php endif; ?>>
                            <td><?php echo esc_html( $dbf_name ); ?><span class="cs-db-golden-star"<?php if ( ! $dbf_golden ): ?> style="display:none;"<?php endif; ?>> &#11088;</span><?php if ( $dbf_key === $db_first_key ) echo wp_kses( '<span class="cs-latest-badge">Latest</span>', [ 'span' => [ 'class' => [] ] ] ); ?><?php if ( $dbf_delete_soon ) echo wp_kses( '<span class="cs-delete-soon-badge">Delete Soon</span>', [ 'span' => [ 'class' => [] ] ] ); ?></td>
                            <td id="cs-db-tag-cell-<?php echo $dbf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" style="white-space:nowrap;">
                                <span class="cs-db-tag-text"><?php if ( $dbf_tag ): ?><?php echo esc_html( $dbf_tag ); ?><?php else: ?><span class="cs-muted-text">No tag</span><?php endif; ?></span>
                                <button type="button" onclick="csDropboxHistoryTagEdit('<?php echo $dbf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>','<?php echo esc_js( $dbf_tag ); ?>')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>
                            </td>
                            <td><?php echo esc_html( $dbf['size_fmt'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $dbf['time'] ? wp_date( 'j M Y H:i', $dbf['time'] ) : '—' ); ?></td>
                            <td id="cs-db-actions-<?php echo $dbf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" style="white-space:nowrap;vertical-align:middle;">
                                <button type="button" onclick="csDropboxHistorySetGolden('<?php echo $dbf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>')" class="button button-small" id="cs-db-golden-btn-<?php echo $dbf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" data-golden="<?php echo esc_attr( $dbf_golden ? '1' : '0' ); ?>" title="<?php echo esc_attr( $dbf_golden ? __( 'Remove Golden Image', 'cloudscale-backup-restore' ) : __( 'Mark as Golden Image', 'cloudscale-backup-restore' ) ); ?>" style="min-width:0;padding:2px 6px;margin-bottom:3px;<?php echo esc_attr( $dbf_golden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '' ); ?>">&#11088;</button>
                                <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'csbr_dropbox_download', 'file' => $dbf_name, 'nonce' => wp_create_nonce( 'csbr_nonce' ) ], admin_url( 'admin-post.php' ) ) ); ?>" class="button button-small" style="min-width:0;padding:2px 6px;margin-bottom:3px;text-decoration:none;display:inline-block;">&#8659; <?php esc_html_e( 'Download', 'cloudscale-backup-restore' ); ?></a>
                                <button type="button" onclick="csDropboxHistoryDelete('<?php echo $dbf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>')" class="button button-small" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php esc_html_e( 'Delete', 'cloudscale-backup-restore' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="cs-empty" id="cs-db-empty" <?php echo ! empty( $dropbox_history ) ? 'style="display:none"' : ''; ?>><?php esc_html_e( 'No Dropbox backups found. Click "Sync from Dropbox" to query your Dropbox live.', 'cloudscale-backup-restore' ); ?></p>
            </div>

            <!-- ONEDRIVE PANE -->
            <div id="cs-hist-pane-onedrive" class="cs-hist-pane" style="display:none">
                <p class="cs-help" style="margin:0 0 12px;"><?php esc_html_e( 'Queries OneDrive live via rclone — picks up all backups including ones made before this screen existed, and removes any you deleted directly in OneDrive.', 'cloudscale-backup-restore' ); ?></p>
                <div class="cs-table-wrap" <?php echo empty( $onedrive_history ) ? 'style="display:none"' : ''; ?> id="cs-od-table-wrap">
                <table class="widefat cs-table" style="font-size:0.82rem;" id="cs-od-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'File Name', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Tag', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Size', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'Created', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:180px;"><?php esc_html_e( 'Actions', 'cloudscale-backup-restore' ); ?></th>
                    </tr></thead>
                    <tbody id="cs-od-tbody">
                    <?php $od_first_key = array_key_first( $onedrive_history ); ?>
                    <?php foreach ( $onedrive_history as $odf_key => $odf ): ?>
                        <?php
                        $odf_name        = $odf['name'] ?? $odf_key;
                        $odf_golden      = ! empty( $odf['golden'] );
                        $odf_tag         = $odf['tag'] ?? '';
                        $odf_key_e       = esc_attr( preg_replace( '/[^a-zA-Z0-9_\-]/', '_', $odf_name ) );
                        $odf_js          = esc_js( $odf_name );
                        $odf_delete_soon = ! $odf_golden && $odf_name === $onedrive_delete_soon_name;
                        ?>
                        <tr id="cs-od-row-<?php echo $odf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>"<?php if ( $odf_golden ): ?> class="cs-row-golden"<?php elseif ( $odf_delete_soon ): ?> class="cs-row-delete-soon"<?php endif; ?>>
                            <td><?php echo esc_html( $odf_name ); ?><span class="cs-od-golden-star"<?php if ( ! $odf_golden ): ?> style="display:none;"<?php endif; ?>> &#11088;</span><?php if ( $odf_key === $od_first_key ) echo wp_kses( '<span class="cs-latest-badge">Latest</span>', [ 'span' => [ 'class' => [] ] ] ); ?><?php if ( $odf_delete_soon ) echo wp_kses( '<span class="cs-delete-soon-badge">Delete Soon</span>', [ 'span' => [ 'class' => [] ] ] ); ?></td>
                            <td id="cs-od-tag-cell-<?php echo $odf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" style="white-space:nowrap;">
                                <span class="cs-od-tag-text"><?php if ( $odf_tag ): ?><?php echo esc_html( $odf_tag ); ?><?php else: ?><span class="cs-muted-text">No tag</span><?php endif; ?></span>
                                <button type="button" onclick="csOneDriveHistoryTagEdit('<?php echo $odf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>','<?php echo esc_js( $odf_tag ); ?>')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>
                            </td>
                            <td><?php echo esc_html( $odf['size_fmt'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $odf['time'] ? wp_date( 'j M Y H:i', $odf['time'] ) : '—' ); ?></td>
                            <td id="cs-od-actions-<?php echo $odf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" style="white-space:nowrap;vertical-align:middle;">
                                <button type="button" onclick="csOneDriveHistorySetGolden('<?php echo $odf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>')" class="button button-small" id="cs-od-golden-btn-<?php echo $odf_key_e; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>" data-golden="<?php echo esc_attr( $odf_golden ? '1' : '0' ); ?>" title="<?php echo esc_attr( $odf_golden ? __( 'Remove Golden Image', 'cloudscale-backup-restore' ) : __( 'Mark as Golden Image', 'cloudscale-backup-restore' ) ); ?>" style="min-width:0;padding:2px 6px;margin-bottom:3px;<?php echo esc_attr( $odf_golden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '' ); ?>">&#11088;</button>
                                <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'csbr_onedrive_download', 'file' => $odf_name, 'nonce' => wp_create_nonce( 'csbr_nonce' ) ], admin_url( 'admin-post.php' ) ) ); ?>" class="button button-small" style="min-width:0;padding:2px 6px;margin-bottom:3px;text-decoration:none;display:inline-block;">&#8659; <?php esc_html_e( 'Download', 'cloudscale-backup-restore' ); ?></a>
                                <button type="button" onclick="csOneDriveHistoryDelete('<?php echo $odf_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above ?>')" class="button button-small" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php esc_html_e( 'Delete', 'cloudscale-backup-restore' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="cs-empty" id="cs-od-empty" <?php echo ! empty( $onedrive_history ) ? 'style="display:none"' : ''; ?>><?php esc_html_e( 'No OneDrive backups found. Click "Refresh" to query your OneDrive live.', 'cloudscale-backup-restore' ); ?></p>
            </div>

            <!-- AMI PANE -->
            <div id="cs-hist-pane-ami" class="cs-hist-pane" style="display:none">
                <?php if ( ! empty( $ami_log_recent ) ): ?>
                <div class="cs-table-wrap">
                <table class="widefat cs-table" style="font-size:0.82rem;">
                    <thead><tr>
                        <th><?php esc_html_e( 'AWS AMI Name', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Tag', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'AMI ID', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:110px;"><?php esc_html_e( 'Created', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Status', 'cloudscale-backup-restore' ); ?></th>
                        <th style="width:220px;"><?php esc_html_e( 'Actions', 'cloudscale-backup-restore' ); ?></th>
                    </tr></thead>
                    <tbody id="cs-ami-tbody">
                    <?php foreach ( $ami_log_recent as $ami_i => $entry ): ?>
                        <?php
                        $row_ami_id     = esc_attr( $entry['ami_id'] ?? '' );
                        $entry_state    = $entry['state'] ?? '';
                        $is_deleted     = ( $entry_state === 'deleted in AWS' );
                        $is_ok          = ! empty( $entry['ok'] );
                        $is_golden      = ! empty( $entry['golden'] );
                        $is_delete_soon = ! $is_golden && ! $is_deleted && ( $entry['ami_id'] ?? '' ) === $ami_delete_soon_id;
                        ?>
                        <tr id="cs-ami-row-<?php echo esc_attr( $row_ami_id ); ?>"<?php if ( $is_golden ): ?> class="cs-row-golden"<?php elseif ( $is_delete_soon ): ?> class="cs-row-delete-soon"<?php endif; ?>>
                            <?php $faded = $is_deleted ? 'style="opacity:0.45;"' : ''; ?>
                            <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>><?php echo esc_html( $entry['name'] ?? '—' ); ?><span class="cs-ami-golden-star"<?php if ( ! $is_golden ): ?> style="display:none;"<?php endif; ?>> &#11088;</span><?php if ( $ami_i === 0 && ! $is_deleted ) echo wp_kses( '<span class="cs-latest-badge">Latest</span>', [ 'span' => [ 'class' => [] ] ] ); ?><?php if ( $is_delete_soon ) echo wp_kses( '<span class="cs-delete-soon-badge">Delete Soon</span>', [ 'span' => [ 'class' => [] ] ] ); ?></td>
                            <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?> id="cs-ami-tag-cell-<?php echo esc_attr( $row_ami_id ); ?>" style="white-space:nowrap;"><span class="cs-ami-tag-text"><?php if ( $entry['tag'] ?? '' ): ?><?php echo esc_html( $entry['tag'] ); ?><?php else: ?><span class="cs-muted-text">No tag</span><?php endif; ?></span> <button type="button" onclick="csAmiTagEdit('<?php echo esc_js( $row_ami_id ); ?>','<?php echo esc_js( $entry['tag'] ?? '' ); ?>')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;"><?php esc_html_e( 'Edit', 'cloudscale-backup-restore' ); ?></button></td>
                            <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>><code style="font-size:0.78rem;"><?php echo esc_html( $entry['ami_id'] ?? '—' ); ?></code></td>
                            <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>><?php echo esc_html( isset( $entry['time'] ) ? wp_date( 'j M Y H:i', $entry['time'] ) : '—' ); ?></td>
                            <td id="cs-ami-state-<?php echo esc_attr( $row_ami_id ); ?>" <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>>
                                <?php if ( $is_deleted ): ?>
                                    <span style="color:#999;font-weight:600;">&#128465; deleted in AWS</span>
                                <?php elseif ( $is_ok ): ?>
                                    <?php
                                    $sc = $entry_state === 'available' ? '#2e7d32' : ( $entry_state === 'pending' ? '#e65100' : '#757575' );
                                    $si = $entry_state === 'available' ? '&#10003;' : ( $entry_state === 'pending' ? '&#9203;' : '&#10007;' );
                                    ?>
                                    <span style="color:<?php echo esc_attr( $sc ); ?>;font-weight:600;"><?php echo $si; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded HTML entity ?> <?php echo esc_html( $entry_state ?: 'Created' ); ?></span>
                                <?php else: ?>
                                    <?php $err_raw = substr( $entry['error'] ?? '', 0, 120 ); ?>
                                    <span style="color:#c62828;font-weight:600;" title="<?php echo esc_attr( $err_raw ?: 'No error detail recorded' ); ?>">&#10007; Failed</span>
                                    <?php if ( $err_raw ): ?>
                                    <br><span style="color:#c62828;font-size:0.72rem;word-break:break-word;display:block;max-width:180px;line-height:1.3;margin-top:2px;"><?php echo esc_html( $err_raw ); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td id="cs-ami-actions-<?php echo esc_attr( $row_ami_id ); ?>" style="white-space:nowrap;vertical-align:middle;">
                                <?php if ( ! empty( $entry['ami_id'] ) ): ?>
                                <?php if ( ! $is_deleted ): ?>
                                <button type="button" onclick="csAmiRefreshOne('<?php echo esc_js( $row_ami_id ); ?>')" class="button button-small" title="<?php esc_attr_e( 'Refresh this AMI state from AWS', 'cloudscale-backup-restore' ); ?>" style="min-width:0;padding:2px 6px;margin-bottom:3px;"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></button>
                                <button type="button" onclick="csAmiSetGolden('<?php echo esc_js( $row_ami_id ); ?>')" class="button button-small" id="cs-ami-golden-btn-<?php echo esc_attr( $row_ami_id ); ?>" data-golden="<?php echo esc_attr( $is_golden ? '1' : '0' ); ?>" title="<?php echo esc_attr( $is_golden ? __( 'Remove Golden Image', 'cloudscale-backup-restore' ) : __( 'Mark as Golden Image', 'cloudscale-backup-restore' ) ); ?>" style="min-width:0;padding:2px 6px;margin-bottom:3px;<?php echo esc_attr( $is_golden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '' ); ?>">&#11088;</button>
                                <?php if ( $entry_state === 'available' ): ?>
                                <button type="button" onclick="csAmiRestore('<?php echo esc_js( $row_ami_id ); ?>','<?php echo esc_js( $entry['name'] ?? '' ); ?>')" class="button button-small" style="min-width:0;padding:2px 8px;color:#1a237e;border-color:#1a237e;margin-bottom:3px;">&#8617; <?php esc_html_e( 'Restore', 'cloudscale-backup-restore' ); ?></button>
                                <?php endif; ?>
                                <?php endif; ?>
                                <button type="button" onclick="csAmiDelete('<?php echo esc_js( $row_ami_id ); ?>','<?php echo esc_js( $entry['name'] ?? '' ); ?>',<?php echo esc_attr( $is_deleted ? 'true' : 'false' ); ?>)" class="button button-small" title="<?php echo $is_deleted ? esc_attr__( 'Remove record', 'cloudscale-backup-restore' ) : esc_attr__( 'Deregister AMI', 'cloudscale-backup-restore' ); ?>" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php echo $is_deleted ? esc_html__( 'Remove', 'cloudscale-backup-restore' ) : esc_html__( 'Delete', 'cloudscale-backup-restore' ); ?></button>
                                <?php else: ?>
                                <button type="button" onclick="csAmiRemoveFailed('<?php echo esc_js( $entry['name'] ?? '' ); ?>')" class="button button-small" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php esc_html_e( 'Remove', 'cloudscale-backup-restore' ); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <p class="cs-empty"><?php esc_html_e( 'No AWS EC2 AMI snapshots recorded yet. Create one from the Cloud Backups tab.', 'cloudscale-backup-restore' ); ?></p>
                <?php endif; ?>
            </div>

        </div><!-- /cs-history-panel -->

        </div><!-- /cs-tab-cloud -->

        <?php csbr_par_render_tab(); ?>

        <!-- ===================== ACTIVITY LOG ===================== -->
        <div id="cs-log-panel" style="margin-top:18px;border:1px solid #37474f;border-radius:6px;background:#1e272e;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 14px;background:#263238;">
                <span style="color:#fff!important;font-family:monospace;font-size:0.82rem;font-weight:700;">&#128196; Activity Log</span>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span id="cs-log-status" style="font-size:0.72rem;color:#aaa!important;"></span>
                    <button id="cs-log-copy" type="button" style="background:#37474f!important;border:none!important;color:#fff!important;border-radius:4px;padding:2px 10px;font-size:0.75rem;cursor:pointer;box-shadow:none!important;">&#128203; Copy</button>
                    <button id="cs-log-refresh" type="button" style="background:#37474f!important;border:none!important;color:#fff!important;border-radius:4px;padding:2px 10px;font-size:0.75rem;cursor:pointer;box-shadow:none!important;">&#8635; Refresh</button>
                    <button id="cs-log-clear" type="button" style="background:#37474f!important;border:none!important;color:#fff!important;border-radius:4px;padding:2px 10px;font-size:0.75rem;cursor:pointer;box-shadow:none!important;">Clear</button>
                </div>
            </div>
            <div id="cs-log-body" style="max-height:calc(10 * 0.76rem * 1.7 + 14px);overflow-y:auto;padding:6px 14px 8px;">
                <div id="cs-log-entries" style="font-family:monospace;font-size:0.76rem;line-height:1.7;"></div>
                <div id="cs-log-empty" style="font-family:monospace;font-size:0.76rem;color:#fff!important;font-style:italic;padding:2px 0;">No log entries yet.</div>
            </div>
        </div>

    </div><!-- /cs-wrap -->
    <?php
}

// ============================================================
// AJAX — Run backup
// ============================================================

add_action('wp_ajax_csbr_run_backup', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    csbr_ensure_backup_dir();

    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_ajax_referer() above; boolean !empty() checkbox checks only
    $include_db        = !empty($_POST['include_db']);
    $include_media     = !empty($_POST['include_media']);
    $include_plugins   = !empty($_POST['include_plugins']);
    $include_themes    = !empty($_POST['include_themes']);
    $include_mu        = !empty($_POST['include_mu']);
    $include_languages = !empty($_POST['include_languages']);
    $include_dropins   = !empty($_POST['include_dropins']);
    $include_htaccess    = !empty($_POST['include_htaccess']);
    $include_wpconfig    = !empty($_POST['include_wpconfig']);
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    if (!$include_db && !$include_media && !$include_plugins && !$include_themes
        && !$include_mu && !$include_languages && !$include_dropins
        && !$include_htaccess && !$include_wpconfig) {
        wp_send_json_error('Select at least one option.');
    }

    set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort(true);

    try {
        $filename = csbr_create_backup(
            $include_db, $include_media, $include_plugins, $include_themes,
            $include_mu, $include_languages, $include_dropins, $include_htaccess, $include_wpconfig
        );
        csbr_enforce_retention();
        $s3      = $GLOBALS['csbr_last_s3_result']      ?? ['skipped' => true];
        $gdrive  = $GLOBALS['csbr_last_gdrive_result']  ?? ['skipped' => true];
        $dropbox = $GLOBALS['csbr_last_dropbox_result'] ?? ['skipped' => true];
        $s3_msg = '';
        if (!isset($s3['skipped'])) {
            $s3_msg = $s3['ok']
                ? '✓ Synced to ' . $s3['dest']
                : '⚠ S3 sync failed: ' . $s3['error'];
        }
        $dropbox_msg = '';
        if (!isset($dropbox['skipped'])) {
            $dropbox_msg = $dropbox['ok']
                ? '✓ Synced to Dropbox: ' . $dropbox['dest']
                : '⚠ Dropbox sync failed: ' . $dropbox['error'];
        }
        $gdrive_msg = '';
        if (!isset($gdrive['skipped'])) {
            $gdrive_msg = $gdrive['ok']
                ? '✓ Synced to Drive: ' . $gdrive['dest']
                : '⚠ Drive sync failed: ' . $gdrive['error'];
        }
        wp_send_json_success([
            'message'      => 'Backup complete: ' . $filename,
            'filename'     => $filename,
            's3_ok'        => $s3['ok'] ?? null,
            's3_msg'       => $s3_msg,
            'gdrive_ok'    => $gdrive['ok'] ?? null,
            'gdrive_msg'   => $gdrive_msg,
            'dropbox_ok'   => $dropbox['ok'] ?? null,
            'dropbox_msg'  => $dropbox_msg,
        ]);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// ============================================================
// AJAX — Async backup: start (returns job ID immediately)
// ============================================================

add_action( 'wp_ajax_csbr_start_backup', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    csbr_ensure_backup_dir();

    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $opts = [
        'include_db'        => ! empty( $_POST['include_db'] ),
        'include_media'     => ! empty( $_POST['include_media'] ),
        'include_plugins'   => ! empty( $_POST['include_plugins'] ),
        'include_themes'    => ! empty( $_POST['include_themes'] ),
        'include_mu'        => ! empty( $_POST['include_mu'] ),
        'include_languages' => ! empty( $_POST['include_languages'] ),
        'include_dropins'   => ! empty( $_POST['include_dropins'] ),
        'include_htaccess'  => ! empty( $_POST['include_htaccess'] ),
        'include_wpconfig'  => ! empty( $_POST['include_wpconfig'] ),
    ];
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    if ( ! array_filter( $opts ) ) {
        wp_send_json_error( 'Select at least one option.' );
    }

    $job_id = 'csbr_bkjob_' . bin2hex( random_bytes( 8 ) );
    csbr_set_job( $job_id, [ 'status' => 'queued', 'started' => time(), 'opts' => $opts ] );

    // Register the backup to run after the HTTP response is sent, then send the response.
    // register_shutdown_function fires after wp_die()/exit, at which point fastcgi_finish_request()
    // has already flushed the response — so the browser gets a clean JSON reply instantly.
    register_shutdown_function( function () use ( $job_id, $opts ): void {
        if ( function_exists( 'fastcgi_finish_request' ) ) fastcgi_finish_request();
        set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        ignore_user_abort( true );
        csbr_execute_backup_job( $job_id, $opts );
    } );

    wp_send_json_success( [ 'job_id' => $job_id ] );
} );

// ============================================================
// AJAX — Async backup: poll status
// ============================================================

add_action( 'wp_ajax_csbr_backup_status', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
    check_ajax_referer( 'csbr_nonce', 'nonce' );

    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $job_id = sanitize_key( $_POST['job_id'] ?? '' );
    if ( ! $job_id || ( ! str_starts_with( $job_id, 'csbr_bkjob_' ) && ! str_starts_with( $job_id, 'csbr_syncjob_' ) ) ) {
        wp_send_json_error( 'Invalid job ID.' );
    }

    $data = csbr_get_job( $job_id );
    if ( $data === false ) {
        wp_send_json_error( 'Job not found or expired.' );
    }

    wp_send_json_success( $data );
} );

// ============================================================
// Email notifications
// ============================================================

/**
 * Send a backup/restore notification email if notifications are enabled.
 *
 * Respects the csbr_notify_on option: 'both', 'success', or 'failure'.
 * Falls back to the site admin email when csbr_notify_email is blank.
 *
 * @since 3.2.251
 * @param bool   $success       True on success, false on failure.
 * @param string $subject_extra Short descriptor appended to the subject line.
 * @param string $body          Plain-text email body.
 * @return void
 */
function csbr_send_backup_notification( bool $success, string $subject_extra, string $body ): void {
    if ( ! get_option( 'csbr_notify_enabled', false ) ) {
        return;
    }
    $notify_on = (string) get_option( 'csbr_notify_on', 'both' );
    if ( $notify_on === 'success' && ! $success ) {
        csbr_log( '[CloudScale Backup & Restore] Email notification skipped (notify_on=success, this was a failure).' );
        return;
    }
    if ( $notify_on === 'failure' && $success ) {
        csbr_log( '[CloudScale Backup & Restore] Email notification skipped (notify_on=failure, this was a success).' );
        return;
    }
    $to = (string) get_option( 'csbr_notify_email', '' );
    if ( ! is_email( $to ) ) {
        $to = (string) get_option( 'admin_email' );
    }
    if ( ! is_email( $to ) ) {
        csbr_log( '[CloudScale Backup & Restore] Email notification skipped — no valid email address configured.' );
        return;
    }
    $site    = get_bloginfo( 'name' ) ?: home_url();
    $status  = $success ? 'Success' : 'Failed';
    $subject = '[' . $site . '] Backup ' . $status . ': ' . $subject_extra;
    csbr_log( '[CloudScale Backup & Restore] Sending notification email to ' . $to . ' — subject: ' . $subject );
    $sent = wp_mail( $to, $subject, $body );
    if ( $sent ) {
        csbr_log( '[CloudScale Backup & Restore] Notification email sent to ' . $to );
    } else {
        csbr_log( '[CloudScale Backup & Restore] ERROR: wp_mail() returned false for notification email to ' . $to . ' — check wp_mail_failed log entries and server mail configuration.' );
    }
}

// Log wp_mail failures to the activity log so they are visible in the UI.
add_action( 'wp_mail_failed', function ( $error ): void {
    if ( $error instanceof WP_Error ) {
        csbr_log( '[CloudScale Backup & Restore] wp_mail_failed: ' . $error->get_error_message() . ' (code: ' . $error->get_error_code() . ')' );
    }
} );

// ============================================================
// AJAX — Async backup: execute backup (now runs inline after fastcgi_finish_request)
// ============================================================

/**
 * Run the actual backup job after the HTTP response has been flushed to the browser.
 *
 * @since 3.2.251
 */
function csbr_execute_backup_job( string $job_id, array $opts ): void {
    $data = csbr_get_job( $job_id );
    if ( $data === false ) return;
    // Abort if a restore is in progress — running a backup mid-restore would corrupt both operations.
    if ( get_transient( 'csbr_restore_active' ) ) {
        csbr_log( '[CloudScale Backup & Restore] Backup job ' . $job_id . ' skipped — a restore is currently in progress.' );
        csbr_set_job( $job_id, array_merge( $data, [ 'status' => 'failed', 'error' => 'Skipped: restore in progress.' ] ) );
        return;
    }
    csbr_set_job( $job_id, array_merge( $data, [ 'status' => 'running' ] ) );
    try {
        // Cloud space pre-check: estimate from the most recent local backup zip.
        // If any enabled rclone provider has less free space than that estimate, abort early.
        $existing_zips = glob( CSBR_BACKUP_DIR . '*.zip' ) ?: [];
        if ( $existing_zips ) {
            rsort( $existing_zips );
            $est_size     = (int) filesize( $existing_zips[0] );
            $est_mb       = round( $est_size / 1048576, 1 );
            $space_errors = [];
            if ( get_option( 'csbr_dropbox_sync_enabled', false ) && get_option( 'csbr_dropbox_remote', '' ) ) {
                $free = csbr_rclone_free_bytes( get_option( 'csbr_dropbox_remote', '' ) );
                if ( $free !== null && $free < $est_size ) {
                    $space_errors[] = 'Dropbox has ' . round( $free / 1048576, 1 ) . ' MB free (last backup was ' . $est_mb . ' MB).';
                }
            }
            if ( get_option( 'csbr_gdrive_sync_enabled', true ) && get_option( 'csbr_gdrive_remote', '' ) ) {
                $free = csbr_rclone_free_bytes( get_option( 'csbr_gdrive_remote', '' ) );
                if ( $free !== null && $free < $est_size ) {
                    $space_errors[] = 'Google Drive has ' . round( $free / 1048576, 1 ) . ' MB free (last backup was ' . $est_mb . ' MB).';
                }
            }
            if ( get_option( 'csbr_onedrive_sync_enabled', false ) && get_option( 'csbr_onedrive_remote', '' ) ) {
                $free = csbr_rclone_free_bytes( get_option( 'csbr_onedrive_remote', '' ) );
                if ( $free !== null && $free < $est_size ) {
                    $space_errors[] = 'OneDrive has ' . round( $free / 1048576, 1 ) . ' MB free (last backup was ' . $est_mb . ' MB).';
                }
            }
            if ( $space_errors ) {
                $msg = 'Not enough cloud storage space — ' . implode( ' ', $space_errors ) . ' Free up space or disable that provider before backing up.';
                csbr_log( '[CloudScale Backup & Restore] Backup aborted — ' . $msg );
                csbr_set_job( $job_id, [ 'status' => 'error', 'message' => $msg ] );
                csbr_send_backup_notification( false, 'Insufficient cloud storage', "Backup failed — insufficient cloud storage.\n\nError: {$msg}\n\nSite: " . home_url() . "\n" );
                wp_die();
            }
        }

        $filename = csbr_create_backup(
            ! empty( $opts['include_db'] ),
            ! empty( $opts['include_media'] ),
            ! empty( $opts['include_plugins'] ),
            ! empty( $opts['include_themes'] ),
            ! empty( $opts['include_mu'] ),
            ! empty( $opts['include_languages'] ),
            ! empty( $opts['include_dropins'] ),
            ! empty( $opts['include_htaccess'] ),
            ! empty( $opts['include_wpconfig'] )
        );
        csbr_enforce_retention();

        $s3       = $GLOBALS['csbr_last_s3_result']       ?? [ 'skipped' => true ];
        $gdrive   = $GLOBALS['csbr_last_gdrive_result']   ?? [ 'skipped' => true ];
        $dropbox  = $GLOBALS['csbr_last_dropbox_result']  ?? [ 'skipped' => true ];
        $onedrive = $GLOBALS['csbr_last_onedrive_result'] ?? [ 'skipped' => true ];

        $s3_msg = '';
        if ( ! isset( $s3['skipped'] ) ) {
            $s3_msg = $s3['ok'] ? '✓ Synced to ' . $s3['dest'] : '⚠ S3 sync failed: ' . $s3['error'];
        }
        $gdrive_msg = '';
        if ( ! isset( $gdrive['skipped'] ) ) {
            $gdrive_msg = $gdrive['ok'] ? '✓ Synced to Drive: ' . $gdrive['dest'] : '⚠ Drive sync failed: ' . $gdrive['error'];
        }
        $dropbox_msg = '';
        if ( ! isset( $dropbox['skipped'] ) ) {
            $dropbox_msg = $dropbox['ok'] ? '✓ Synced to Dropbox: ' . $dropbox['dest'] : '⚠ Dropbox sync failed: ' . $dropbox['error'];
        }
        $onedrive_msg = '';
        if ( ! isset( $onedrive['skipped'] ) ) {
            $onedrive_msg = $onedrive['ok'] ? '✓ Synced to OneDrive: ' . $onedrive['dest'] : '⚠ OneDrive sync failed: ' . $onedrive['error'];
        }

        csbr_set_job( $job_id, [
            'status'        => 'complete',
            'filename'      => $filename,
            's3_ok'         => $s3['ok']       ?? null,
            's3_msg'        => $s3_msg,
            'gdrive_ok'     => $gdrive['ok']   ?? null,
            'gdrive_msg'    => $gdrive_msg,
            'dropbox_ok'    => $dropbox['ok']  ?? null,
            'dropbox_msg'   => $dropbox_msg,
            'onedrive_ok'   => $onedrive['ok'] ?? null,
            'onedrive_msg'  => $onedrive_msg,
        ] );

        // Save completion timestamp so the dashboard widget uses the same reference
        // point as cloud sync log entries (all recorded after the zip is created).
        update_option( 'csbr_last_backup_time', time(), false );

        $cloud_lines = array_filter( [ $s3_msg, $gdrive_msg, $dropbox_msg, $onedrive_msg ] );
        $notify_body  = "Backup completed successfully.\n\nFile: " . basename( $filename ) . "\nSize: " . csbr_format_size( (int) filesize( $filename ) ) . "\n";
        if ( $cloud_lines ) {
            $notify_body .= "\nCloud sync:\n" . implode( "\n", $cloud_lines ) . "\n";
        }
        $notify_body .= "\nSite: " . home_url() . "\n";
        csbr_send_backup_notification( true, basename( $filename ), $notify_body );

    } catch ( Exception $e ) {
        csbr_log( '[CloudScale Backup & Restore] EXCEPTION in backup job ' . $job_id . ': ' . $e->getMessage() );
        csbr_set_job( $job_id, [ 'status' => 'error', 'message' => $e->getMessage() ] );
        csbr_send_backup_notification( false, 'Error', "Backup failed.\n\nError: " . $e->getMessage() . "\n\nSite: " . home_url() . "\n" );
    }
}

// ============================================================
// AJAX — Delete backup
// ============================================================

add_action('wp_ajax_csbr_delete_backup', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $file = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
    $path = CSBR_BACKUP_DIR . $file;
    if (file_exists($path) && strpos(realpath($path), realpath(CSBR_BACKUP_DIR)) === 0) {
        wp_delete_file( $path );
        wp_send_json_success('Deleted.');
    }
    wp_send_json_error('File not found.');
});

// ============================================================
// Restore source helpers (shared by history + staged upload)
// ============================================================

/**
 * Resolve a restore source to an absolute path from either a backup-dir filename
 * or a staged-upload key. Calls wp_send_json_error() and exits on failure.
 *
 * @since 3.2.251
 * @param array $post POST data containing 'file' or 'staged_key'.
 * @return array{path:string,filename:string,ext:string,staged:bool,staged_key:string}
 */
function csbr_resolve_restore_source( array $post ): array {
    if ( ! empty( $post['staged_key'] ) ) {
        $key    = sanitize_text_field( wp_unslash( $post['staged_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies nonce
        $staged = get_transient( 'csbr_staged_' . $key );
        if ( ! $staged || empty( $staged['path'] ) || ! file_exists( $staged['path'] ) ) {
            wp_send_json_error( 'Staged file not found or expired. Please re-upload.' );
        }
        return [
            'path'       => $staged['path'],
            'filename'   => $staged['filename'],
            'ext'        => $staged['ext'],
            'staged'     => true,
            'staged_key' => $key,
        ];
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- caller verifies nonce
    $file = sanitize_file_name( wp_unslash( $post['file'] ?? '' ) );
    $path = CSBR_BACKUP_DIR . $file;
    if ( ! file_exists( $path ) || strpos( realpath( $path ), realpath( CSBR_BACKUP_DIR ) ) !== 0 ) {
        wp_send_json_error( 'Backup file not found.' );
    }
    return [
        'path'       => $path,
        'filename'   => $file,
        'ext'        => strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ),
        'staged'     => false,
        'staged_key' => '',
    ];
}

/**
 * Delete a staged upload file and its transient.
 *
 * Called by the user-triggered delete button (csbr_delete_staged AJAX action).
 * Restore handlers no longer call this — staged files persist for the full
 * 2-hour transient window so the same upload can be used for multiple restores.
 *
 * @since 3.2.251
 * @param array $source Return value of csbr_resolve_restore_source().
 * @return void
 */
function csbr_cleanup_staged( array $source ): void {
    if ( $source['staged'] && file_exists( $source['path'] ) ) {
        wp_delete_file( $source['path'] );
        delete_transient( 'csbr_staged_' . $source['staged_key'] );
    }
}

// ============================================================
// AJAX — Restore from stored backup
// ============================================================

add_action('wp_ajax_csbr_restore_backup', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort(true);

    $source   = csbr_resolve_restore_source( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
    $src_size = file_exists( $source['path'] ) ? CloudScale_Backup_Utils::format_size( (int) filesize( $source['path'] ) ) : 'unknown size';
    csbr_log( '[CloudScale Backup & Restore] Full restore initiated — file: ' . ( $source['filename'] ?? '?' ) . ' (' . $src_size . ')' );

    try {
        set_transient( 'csbr_restore_active', 1, 10 * MINUTE_IN_SECONDS );
        csbr_log( '[CloudScale Backup & Restore] Maintenance mode ON' );
        csbr_maintenance_on();
        if ( $source['ext'] === 'sql' ) {
            csbr_restore_sql_file( $source['path'] );
        } else {
            csbr_restore_from_zip( $source['path'] );
        }
        csbr_maintenance_off();
        csbr_log( '[CloudScale Backup & Restore] Maintenance mode OFF — restore complete' );
        delete_transient( 'csbr_restore_active' );
        csbr_send_backup_notification( true, 'Restore completed', "Restore completed successfully.\n\nFile: {$source['filename']}\n\nSite: " . home_url() . "\n" );
        wp_send_json_success('Database restored successfully. Maintenance mode disabled. Site is back online.');
    } catch (Exception $e) {
        csbr_maintenance_off();
        delete_transient( 'csbr_restore_active' );
        csbr_log( '[CloudScale Backup & Restore] Maintenance mode OFF — restore failed' );
        csbr_log( '[CloudScale Backup & Restore] EXCEPTION in full restore: ' . $e->getMessage() . ' — file: ' . ( $source['filename'] ?? '?' ) );
        csbr_send_backup_notification( false, 'Restore failed', "Restore failed.\n\nFile: {$source['filename']}\nError: " . $e->getMessage() . "\n\nSite: " . home_url() . "\n" );
        wp_send_json_error('Restore failed: ' . $e->getMessage() . ' — Maintenance mode has been disabled.');
    }
});

// ============================================================
// AJAX — Restore from upload
// ============================================================

add_action('wp_ajax_csbr_restore_upload', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort(true);

    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- nonce verified via check_ajax_referer( 'csbr_nonce', 'nonce' ); tmp_name is a server path, not user input; file extension is validated below
    if (empty($_FILES['backup_file'])) {
        wp_send_json_error('No file uploaded.');
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    $ext = strtolower(pathinfo(sanitize_file_name( wp_unslash( $_FILES['backup_file']['name'] ) ), PATHINFO_EXTENSION));
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

    if (!in_array($ext, ['zip', 'sql'], true)) {
        wp_send_json_error('Only .zip or .sql files accepted.');
    }

    $uploaded_name = sanitize_file_name( wp_unslash( $_FILES['backup_file']['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce verified via check_ajax_referer() above; sanitized via sanitize_file_name()
    $up_size       = file_exists( $tmp ) ? CloudScale_Backup_Utils::format_size( (int) filesize( $tmp ) ) : 'unknown size';
    csbr_log( '[CloudScale Backup & Restore] Restore-from-upload initiated — file: ' . $uploaded_name . ' (' . $up_size . ')' );

    try {
        set_transient( 'csbr_restore_active', 1, 10 * MINUTE_IN_SECONDS );
        csbr_log( '[CloudScale Backup & Restore] Maintenance mode ON' );
        csbr_maintenance_on();
        if ($ext === 'zip') {
            csbr_restore_from_zip($tmp);
        } else {
            csbr_restore_sql_file($tmp);
        }
        csbr_maintenance_off();
        csbr_log( '[CloudScale Backup & Restore] Maintenance mode OFF — restore complete' );
        delete_transient( 'csbr_restore_active' );
        csbr_send_backup_notification( true, 'Restore from upload completed', "Restore from uploaded file completed successfully.\n\nFile: {$uploaded_name}\n\nSite: " . home_url() . "\n" );
        wp_send_json_success('Database restored. Maintenance mode disabled. Site is back online.');
    } catch (Exception $e) {
        csbr_maintenance_off();
        delete_transient( 'csbr_restore_active' );
        csbr_log( '[CloudScale Backup & Restore] Maintenance mode OFF — restore failed' );
        csbr_log( '[CloudScale Backup & Restore] EXCEPTION in restore-from-upload: ' . $e->getMessage() );
        csbr_send_backup_notification( false, 'Restore from upload failed', "Restore from uploaded file failed.\n\nError: " . $e->getMessage() . "\n\nSite: " . home_url() . "\n" );
        wp_send_json_error('Restore failed: ' . $e->getMessage());
    }
});

// ============================================================
// AJAX — Chunked upload: receive one 2 MB slice, assemble on final chunk
// ====================================================================
add_action( 'wp_ajax_csbr_upload_chunk', function (): void {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
	check_ajax_referer( 'csbr_nonce', 'nonce' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce verified above
	$upload_key   = sanitize_key( wp_unslash( $_POST['upload_key'] ?? '' ) );
	$chunk_index  = max( 0, intval( $_POST['chunk_index'] ?? 0 ) );
	$total_chunks = max( 1, intval( $_POST['total_chunks'] ?? 1 ) );
	$orig_name    = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

	if ( ! $upload_key || ! $orig_name ) {
		wp_send_json_error( 'Missing parameters.' );
	}

	$ext = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, [ 'zip', 'sql' ], true ) ) {
		wp_send_json_error( 'Only .zip and .sql files are accepted.' );
	}

	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( empty( $_FILES['chunk_data']['tmp_name'] ) || ! is_uploaded_file( $_FILES['chunk_data']['tmp_name'] ) ) {
		wp_send_json_error( 'No chunk data received.' );
	}
	$tmp        = $_FILES['chunk_data']['tmp_name']; // Server-controlled PHP temp path — not user-supplied data.
	$chunk_size = $_FILES['chunk_data']['size'] ?? 0;
	// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	// Log upload start on first chunk.
	if ( 0 === $chunk_index ) {
		CloudScale_Backup_Utils::log( "[CloudScale Backup & Restore] Upload started: {$orig_name} ({$total_chunks} chunk(s))" );
	}

	// Save chunk to a dedicated temp directory.
	$chunk_dir = WP_CONTENT_DIR . '/csbr-staging/chunks/' . $upload_key . '/';
	if ( ! is_dir( $chunk_dir ) ) {
		wp_mkdir_p( $chunk_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP Filesystem unavailable; protecting staging dir
		file_put_contents( $chunk_dir . '.htaccess', "Deny from all\n" );
	}

	$chunk_path = $chunk_dir . sprintf( 'chunk_%05d', $chunk_index );
	// Use WP Filesystem to move the uploaded chunk out of the PHP temp dir.
	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}
	if ( ! $wp_filesystem->move( $tmp, $chunk_path, true ) ) {
		CloudScale_Backup_Utils::log( "[CloudScale Backup & Restore] Upload error: failed to save chunk {$chunk_index} of {$orig_name}" );
		wp_send_json_error( 'Failed to save chunk.' );
	}

	// Not the last chunk — acknowledge and wait for more.
	if ( $chunk_index < $total_chunks - 1 ) {
		wp_send_json_success( [ 'chunk' => $chunk_index, 'assembled' => false ] );
	}

	// Last chunk: assemble all pieces into the staging file.
	$stage_dir = WP_CONTENT_DIR . '/csbr-staging/';
	if ( ! is_dir( $stage_dir ) ) {
		wp_mkdir_p( $stage_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP Filesystem unavailable
		file_put_contents( $stage_dir . '.htaccess', "Deny from all\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP Filesystem unavailable
		file_put_contents( $stage_dir . 'index.php', "<?php // Silence\n" );
	}

	$key         = wp_generate_password( 32, false );
	$staged_path = $stage_dir . $key . '.' . $ext;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming binary assembly
	$out = fopen( $staged_path, 'wb' );
	if ( ! $out ) {
		CloudScale_Backup_Utils::log( "[CloudScale Backup & Restore] Upload error: could not create staged file for {$orig_name}" );
		wp_send_json_error( 'Could not create staged file.' );
	}
	$ok         = true;
	$total_size = 0;
	for ( $i = 0; $i < $total_chunks; $i++ ) {
		$cf = $chunk_dir . sprintf( 'chunk_%05d', $i );
		if ( ! file_exists( $cf ) ) { $ok = false; break; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming binary assembly
		$in = fopen( $cf, 'rb' );
		if ( $in ) {
			$total_size += stream_copy_to_stream( $in, $out );
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired fopen above
		}
		wp_delete_file( $cf );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired fopen above
	@rmdir( $chunk_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup

	if ( ! $ok ) {
		wp_delete_file( $staged_path );
		CloudScale_Backup_Utils::log( "[CloudScale Backup & Restore] Upload error: missing chunk(s) assembling {$orig_name}" );
		wp_send_json_error( 'One or more chunks missing — upload incomplete.' );
	}

	$size_fmt = CloudScale_Backup_Utils::format_size( $total_size );
	CloudScale_Backup_Utils::log( "[CloudScale Backup & Restore] Upload complete: {$orig_name} ({$size_fmt}, {$total_chunks} chunk(s)) — staged for restore" );

	set_transient( 'csbr_staged_' . $key, [
		'path'     => $staged_path,
		'filename' => $orig_name,
		'ext'      => $ext,
		'time'     => time(),
	], 1800 );

	wp_send_json_success( [
		'key'      => $key,
		'filename' => $orig_name,
		'ext'      => $ext,
		'assembled' => true,
	] );
} );

// AJAX — Log a client-side event (network errors, cancellations) to the activity log
// ==================================================================================
add_action( 'wp_ajax_csbr_log_event', function (): void {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
	check_ajax_referer( 'csbr_nonce', 'nonce' );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_text_field handles it
	$msg = sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) );
	if ( $msg ) {
		CloudScale_Backup_Utils::log( '[CloudScale Backup & Restore] ' . $msg );
	}
	wp_send_json_success();
} );

// AJAX — Delete a staged upload file + transient
// ============================================================
// AJAX — Send test notification email
// ============================================================

add_action( 'wp_ajax_csbr_send_test_email', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );

    $to = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
    if ( ! is_email( $to ) ) {
        $to = (string) get_option( 'admin_email' );
    }
    if ( ! is_email( $to ) ) {
        wp_send_json_error( [ 'msg' => 'No valid email address.', 'port_warning' => '' ] );
    }

    // Check outbound SMTP port availability before attempting to send.
    $smtp_ports = [ 587 => 'STARTTLS', 465 => 'SSL', 25 => 'plain' ];
    $open_ports = [];
    $port_log   = [];
    foreach ( $smtp_ports as $port => $label ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- raw TCP port probe; no WP equivalent for non-HTTP sockets
        $sock = @fsockopen( 'smtp.gmail.com', $port, $errno, $errstr, 3 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- expected to fail when port is blocked
        if ( $sock ) {
            $open_ports[] = $port . ' (' . $label . ')';
            fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing a network socket, not a file
            $port_log[] = 'port ' . $port . ' open';
        } else {
            $port_log[] = 'port ' . $port . ' blocked';
        }
    }

    // Detect whether a local MTA is running (postfix / sendmail / exim).
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_pgrep -- read-only process list probe; no exec/shell_exec
    $mta_running  = false;
    $mta_procs    = [ 'postfix', 'master', 'sendmail', 'exim', 'exim4' ];
    foreach ( $mta_procs as $proc ) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- read-only pgrep; suppressed per plugin policy
        exec( 'pgrep -x ' . escapeshellarg( $proc ) . ' 2>/dev/null', $pgrep_out, $pgrep_rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        if ( 0 === $pgrep_rc ) {
            $mta_running = true;
            break;
        }
    }

    // If an MTA is running, check whether it has a relay configured.
    // Without a relayhost, Postfix attempts direct delivery via port 25 — blocked on AWS EC2.
    $mta_has_relay = false;
    if ( $mta_running ) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- read-only postconf query
        exec( 'postconf -h relayhost 2>/dev/null', $relay_out, $relay_rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        $relayhost = trim( $relay_out[0] ?? '' );
        $mta_has_relay = ( '' !== $relayhost );
    }

    $port_warning = '';
    if ( empty( $open_ports ) ) {
        $port_warning = 'All outbound SMTP ports (587, 465, 25) appear blocked on this server. '
                      . 'AWS EC2 restricts port 25 by default; 587/465 may also be filtered. '
                      . 'Install a SMTP plugin (e.g. WP Mail SMTP) to route mail through an external provider.';
        csbr_log( '[CloudScale Backup & Restore] Test email — SMTP port check: all ports blocked (' . implode( ', ', $port_log ) . ')' );
    } elseif ( ! $mta_running ) {
        $port_warning = 'Outbound SMTP ports are reachable (' . implode( ', ', $open_ports ) . ') but no local mail agent '
                      . '(Postfix / Sendmail / Exim) is running on this server. WordPress hands the email to the system '
                      . 'sendmail binary which has nowhere to deliver it, so the email is silently dropped. '
                      . 'Install the WP Mail SMTP plugin and configure it to send directly via port 587 — your server can reach it.';
        csbr_log( '[CloudScale Backup & Restore] Test email — SMTP ports open but no local MTA detected (' . implode( ', ', $port_log ) . ')' );
    } elseif ( ! $mta_has_relay ) {
        $port_warning = 'Postfix is running but has no relay configured. It will attempt direct delivery via port 25, '
                      . 'which AWS EC2 blocks at the infrastructure level regardless of security group rules. '
                      . 'Configure a relay (e.g. Gmail SMTP or Amazon SES) so Postfix sends via port 587 instead. '
                      . 'The email has been accepted into the Postfix queue but will not be delivered.';
        csbr_log( '[CloudScale Backup & Restore] Test email — Postfix running but no relayhost configured; port 25 delivery will fail (' . implode( ', ', $port_log ) . ')' );
    } else {
        csbr_log( '[CloudScale Backup & Restore] Test email — SMTP port check: ' . implode( ', ', $port_log ) . '; relayhost=' . $relayhost );
    }

    $site    = get_bloginfo( 'name' ) ?: home_url();
    $subject = '[' . $site . '] TEST: Backup notification test';
    $body    = "This is a test notification from CloudScale Backup & Restore.\n\n"
             . "If you received this, email notifications are working correctly.\n\n"
             . "Site: " . home_url() . "\n"
             . "Sent to: " . $to . "\n";

    csbr_log( '[CloudScale Backup & Restore] Test email requested — sending to ' . $to );
    $sent = wp_mail( $to, $subject, $body );
    if ( $sent ) {
        $sent_msg = 'Test email dispatched to ' . $to;
        if ( $port_warning ) {
            $sent_msg .= ' — but see warning below';
        }
        csbr_log( '[CloudScale Backup & Restore] Test email accepted by mail transport for ' . $to );
        wp_send_json_success( [ 'msg' => $sent_msg, 'port_warning' => $port_warning ] );
    } else {
        csbr_log( '[CloudScale Backup & Restore] ERROR: Test email — wp_mail() returned false for ' . $to . ' (' . implode( ', ', $port_log ) . ')' );
        wp_send_json_error( [ 'msg' => 'wp_mail() returned false — check Activity Log.', 'port_warning' => $port_warning ] );
    }
} );

// ================================================
add_action( 'wp_ajax_csbr_delete_staged', function (): void {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
	check_ajax_referer( 'csbr_nonce', 'nonce' );
	$key = sanitize_key( wp_unslash( $_POST['staged_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
	if ( ! $key ) { wp_send_json_error( 'Missing key.' ); }
	$data = get_transient( 'csbr_staged_' . $key );
	if ( $data && ! empty( $data['path'] ) && file_exists( $data['path'] ) ) {
		wp_delete_file( $data['path'] );
	}
	delete_transient( 'csbr_staged_' . $key );
	CloudScale_Backup_Utils::log( '[CloudScale Backup & Restore] Staged upload deleted by user.' );
	wp_send_json_success();
} );

// AJAX — Stage an uploaded file (two-step upload → modal flow)
// ============================================================

add_action( 'wp_ajax_csbr_stage_upload', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );

    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- nonce verified above; tmp_name is a server path; ext validated below
    if ( empty( $_FILES['backup_file'] ) || empty( $_FILES['backup_file']['tmp_name'] ) ) {
        wp_send_json_error( 'No file uploaded.' );
    }
    $tmp_name  = $_FILES['backup_file']['tmp_name'];
    $orig_name = sanitize_file_name( wp_unslash( $_FILES['backup_file']['name'] ?? '' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

    $ext = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, [ 'zip', 'sql' ], true ) ) {
        wp_send_json_error( 'Only .zip and .sql files are accepted.' );
    }
    if ( ! is_uploaded_file( $tmp_name ) ) {
        wp_send_json_error( 'Invalid upload.' );
    }

    $stage_dir = WP_CONTENT_DIR . '/csbr-staging/';
    if ( ! is_dir( $stage_dir ) ) {
        wp_mkdir_p( $stage_dir );
        file_put_contents( $stage_dir . '.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP Filesystem unavailable here; protecting staging dir
        file_put_contents( $stage_dir . 'index.php', "<?php // Silence\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP Filesystem unavailable here
    }

    $key         = wp_generate_password( 32, false );
    $staged_path = $stage_dir . $key . '.' . $ext;
    // Use WP Filesystem to move the uploaded file out of the PHP temp dir.
    global $wp_filesystem;
    if ( empty( $wp_filesystem ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    if ( ! $wp_filesystem->move( $tmp_name, $staged_path, true ) ) {
        wp_send_json_error( 'Failed to stage uploaded file.' );
    }

    set_transient( 'csbr_staged_' . $key, [
        'path'     => $staged_path,
        'filename' => $orig_name,
        'ext'      => $ext,
        'time'     => time(),
    ], 1800 );

    wp_send_json_success( [
        'key'      => $key,
        'filename' => $orig_name,
        'ext'      => $ext,
    ] );
} );

// ============================================================
// AJAX — List tables in a backup zip (for selective restore)
// ============================================================

add_action( 'wp_ajax_csbr_list_backup_tables', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );

    $source = csbr_resolve_restore_source( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above

    if ( $source['ext'] === 'sql' ) {
        $sql = file_get_contents( $source['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading staged/backup SQL file; WP Filesystem API not appropriate
        if ( empty( $sql ) ) { wp_send_json_error( 'SQL file is empty.' ); }
    } else {
        $zip = new ZipArchive();
        if ( $zip->open( $source['path'] ) !== true ) { wp_send_json_error( 'Cannot open backup zip.' ); }
        $enc_pwd = (string) get_option( 'csbr_encrypt_password', '' );
        if ( $enc_pwd !== '' ) { $zip->setPassword( $enc_pwd ); }
        $sql = $zip->getFromName( 'database.sql' );
        $zip->close();
        if ( empty( $sql ) ) { wp_send_json_error( 'No database.sql in this backup.' ); }
    }

    $tables = csbr_list_tables_in_dump( $sql );
    if ( empty( $tables ) ) { wp_send_json_error( 'No tables found in this backup.' ); }
    wp_send_json_success( [ 'tables' => $tables ] );
} );

// ============================================================
// AJAX — Selective restore (chosen tables only)
// ============================================================

add_action( 'wp_ajax_csbr_restore_selective', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort( true );

    $source = csbr_resolve_restore_source( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above

    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; sanitized via array_map below
    $raw_tables = isset( $_POST['tables'] ) && is_array( $_POST['tables'] ) ? wp_unslash( $_POST['tables'] ) : [];
    $tables     = array_values( array_filter( array_map( 'sanitize_text_field', $raw_tables ) ) );
    if ( empty( $tables ) ) { wp_send_json_error( 'No tables selected.' ); }

    if ( $source['ext'] === 'sql' ) {
        $sql = file_get_contents( $source['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading staged/backup SQL file
        if ( empty( $sql ) ) { wp_send_json_error( 'SQL file is empty.' ); }
    } else {
        $zip = new ZipArchive();
        if ( $zip->open( $source['path'] ) !== true ) { wp_send_json_error( 'Cannot open backup zip.' ); }
        $enc_pwd = (string) get_option( 'csbr_encrypt_password', '' );
        if ( $enc_pwd !== '' ) { $zip->setPassword( $enc_pwd ); }
        $sql = $zip->getFromName( 'database.sql' );
        $zip->close();
        if ( empty( $sql ) ) { wp_send_json_error( 'No database.sql in this backup.' ); }
    }

    $filtered = csbr_filter_sql_for_tables( $sql, $tables );
    if ( empty( trim( $filtered ) ) ) {
        wp_send_json_error( 'None of the selected tables were found in this backup.' );
    }

    try {
        csbr_execute_sql_string( $filtered );
        $label = implode( ', ', $tables );
        csbr_send_backup_notification( true, 'Selective restore completed', "Selective restore completed.\n\nFile: {$source['filename']}\nTables restored: {$label}\n\nSite: " . home_url() . "\n" );
        wp_send_json_success( 'Restored ' . count( $tables ) . ' table(s): ' . $label );
    } catch ( Exception $e ) {
        csbr_log( '[CloudScale Backup & Restore] EXCEPTION in selective restore: ' . $e->getMessage() . ' — file: ' . ( $source['filename'] ?? '?' ) );
        csbr_send_backup_notification( false, 'Selective restore failed', "Selective restore failed.\n\nFile: {$source['filename']}\nError: " . $e->getMessage() . "\n\nSite: " . home_url() . "\n" );
        wp_send_json_error( 'Selective restore failed: ' . $e->getMessage() );
    }
} );

// ============================================================
// AJAX — List posts/pages in a backup (for post-level restore)
// ============================================================

add_action( 'wp_ajax_csbr_list_backup_posts', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backup files

    $source = csbr_resolve_restore_source( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
    csbr_log( '[CloudScale Backup & Restore] Listing posts from backup: ' . ( $source['filename'] ?? '?' ) );

    try {
        $sql = csbr_get_sql_from_source( $source );
    } catch ( Exception $e ) {
        csbr_log( '[CloudScale Backup & Restore] EXCEPTION reading backup SQL for post list: ' . $e->getMessage() );
        wp_send_json_error( $e->getMessage() );
    }

    // Find posts table — prefer the shortest name ending in 'posts' (e.g. wp_posts,
    // not wp_aioseo_posts or wp_pmxi_posts which also end in 'posts').
    $posts_table   = '';
    $posts_table_l = PHP_INT_MAX;
    foreach ( csbr_list_tables_in_dump( $sql ) as $t ) {
        if ( substr( $t, -5 ) === 'posts' && strlen( $t ) < $posts_table_l ) {
            $posts_table   = $t;
            $posts_table_l = strlen( $t );
        }
    }
    if ( ! $posts_table ) { wp_send_json_error( 'No posts table found in this backup.' ); }

    // Filter to posts table SQL first, then derive column indices from the smaller string
    // (avoids PCRE backtrack limits on large full-dump strings).
    $posts_sql = csbr_filter_sql_for_tables( $sql, [ $posts_table ] );
    $cols      = csbr_get_table_column_indices( $posts_sql, $posts_table );
    $idx_id  = $cols['ID'] ?? 0;
    $idx_dat = $cols['post_date'] ?? 2;
    $idx_ttl = $cols['post_title'] ?? 5;
    $idx_sta = $cols['post_status'] ?? 7;
    $idx_typ = $cols['post_type'] ?? 20;
    $max_idx = max( $idx_id, $idx_dat, $idx_ttl, $idx_sta, $idx_typ );

    $posts = [];
    $skip_types   = [ 'revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ];
    $skip_statuses = [ 'auto-draft', 'inherit' ];

    foreach ( csbr_split_sql( $posts_sql ) as $stmt ) {
        $s = ltrim( $stmt );
        if ( ! preg_match( '/^INSERT\s+(?:IGNORE\s+)?INTO\s+`' . preg_quote( $posts_table, '/' ) . '`/i', $s ) ) { continue; }
        foreach ( csbr_parse_insert_row_strings( $stmt ) as $row ) {
            $vals = csbr_parse_row_columns( $row );
            if ( count( $vals ) <= $max_idx ) { continue; }
            $typ = $vals[ $idx_typ ];
            $sta = $vals[ $idx_sta ];
            if ( in_array( $typ, $skip_types, true ) || in_array( $sta, $skip_statuses, true ) ) { continue; }
            $posts[] = [
                'id'     => (int) $vals[ $idx_id ],
                'title'  => $vals[ $idx_ttl ] !== '' ? $vals[ $idx_ttl ] : '(no title)',
                'type'   => $typ,
                'status' => $sta,
                'date'   => substr( $vals[ $idx_dat ], 0, 10 ),
            ];
        }
    }

    usort( $posts, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
    wp_send_json_success( [ 'posts' => $posts ] );
} );

// ============================================================
// AJAX — Restore a single post + its postmeta from a backup
// ============================================================

add_action( 'wp_ajax_csbr_restore_post', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backup files

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
    $post_id = intval( wp_unslash( $_POST['post_id'] ?? 0 ) );
    if ( $post_id < 1 ) { wp_send_json_error( 'Invalid post ID.' ); }

    $source = csbr_resolve_restore_source( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
    csbr_log( '[CloudScale Backup & Restore] Restoring post ID ' . $post_id . ' from backup: ' . ( $source['filename'] ?? '?' ) );

    try {
        $sql = csbr_get_sql_from_source( $source );
    } catch ( Exception $e ) {
        csbr_log( '[CloudScale Backup & Restore] EXCEPTION reading backup SQL for post restore (ID ' . $post_id . '): ' . $e->getMessage() );
        wp_send_json_error( $e->getMessage() );
    }

    // Find posts + postmeta table names — prefer shortest match to avoid plugin shadow tables
    // (e.g. wp_aioseo_posts also ends in 'posts'; wp_posts is always shorter).
    $posts_table    = '';
    $postmeta_table = '';
    $posts_table_l  = PHP_INT_MAX;
    $meta_table_l   = PHP_INT_MAX;
    foreach ( csbr_list_tables_in_dump( $sql ) as $t ) {
        if ( substr( $t, -8 ) === 'postmeta' && strlen( $t ) < $meta_table_l ) {
            $postmeta_table = $t;
            $meta_table_l   = strlen( $t );
        } elseif ( substr( $t, -5 ) === 'posts' && strlen( $t ) < $posts_table_l ) {
            $posts_table   = $t;
            $posts_table_l = strlen( $t );
        }
    }
    if ( ! $posts_table ) { wp_send_json_error( 'No posts table found in this backup.' ); }

    $restore_sql = '';

    // -- wp_posts row --
    $posts_filtered = csbr_filter_sql_for_tables( $sql, [ $posts_table ] );
    $cols           = csbr_get_table_column_indices( $posts_filtered, $posts_table );
    $idx_id         = $cols['ID'] ?? 0;

    foreach ( csbr_split_sql( $posts_filtered ) as $stmt ) {
        if ( ! preg_match( '/^INSERT\s+(?:IGNORE\s+)?INTO\s+`' . preg_quote( $posts_table, '/' ) . '`/i', ltrim( $stmt ) ) ) { continue; }
        $col_clause = csbr_get_insert_column_clause( $stmt );
        $matching   = [];
        foreach ( csbr_parse_insert_row_strings( $stmt ) as $row ) {
            $vals = csbr_parse_row_columns( $row );
            if ( isset( $vals[ $idx_id ] ) && (int) $vals[ $idx_id ] === $post_id ) {
                $matching[] = '(' . $row . ')';
            }
        }
        if ( $matching ) {
            $restore_sql .= 'REPLACE INTO `' . $posts_table . '`' . $col_clause . ' VALUES ' . implode( ',', $matching ) . ";\n";
        }
    }

    if ( ! $restore_sql ) {
        wp_send_json_error( "Post ID {$post_id} was not found in this backup." );
    }

    // -- wp_postmeta rows --
    if ( $postmeta_table ) {
        $meta_filtered = csbr_filter_sql_for_tables( $sql, [ $postmeta_table ] );
        $meta_cols     = csbr_get_table_column_indices( $meta_filtered, $postmeta_table );
        $idx_meta_pid  = $meta_cols['post_id'] ?? 1;

        foreach ( csbr_split_sql( $meta_filtered ) as $stmt ) {
            if ( ! preg_match( '/^INSERT\s+(?:IGNORE\s+)?INTO\s+`' . preg_quote( $postmeta_table, '/' ) . '`/i', ltrim( $stmt ) ) ) { continue; }
            $col_clause = csbr_get_insert_column_clause( $stmt );
            $matching   = [];
            foreach ( csbr_parse_insert_row_strings( $stmt ) as $row ) {
                $vals = csbr_parse_row_columns( $row );
                if ( isset( $vals[ $idx_meta_pid ] ) && (int) $vals[ $idx_meta_pid ] === $post_id ) {
                    $matching[] = '(' . $row . ')';
                }
            }
            if ( $matching ) {
                $restore_sql .= 'REPLACE INTO `' . $postmeta_table . '`' . $col_clause . ' VALUES ' . implode( ',', $matching ) . ";\n";
            }
        }
    }

    try {
        csbr_execute_sql_string( $restore_sql );
        csbr_log( '[CloudScale Backup & Restore] Post ID ' . $post_id . ' restored successfully from ' . ( $source['filename'] ?? '?' ) );
        wp_send_json_success( "Post ID {$post_id} and its metadata have been restored." );
    } catch ( Exception $e ) {
        csbr_log( '[CloudScale Backup & Restore] EXCEPTION executing post restore SQL (ID ' . $post_id . '): ' . $e->getMessage() );
        wp_send_json_error( 'Post restore failed: ' . $e->getMessage() );
    }
} );

// Schedule is now saved via plain HTML form POST (see page handler above)

// ============================================================
// AJAX — Save manual backup defaults
// ============================================================

add_action('wp_ajax_csbr_save_manual_defaults', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $valid = ['db', 'media', 'plugins', 'themes', 'mu', 'languages', 'dropins', 'htaccess', 'wpconfig'];
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_ajax_referer( 'csbr_nonce', 'nonce' ); values validated via array_intersect() against a whitelist
    $raw   = isset($_POST['components']) && is_array($_POST['components']) ? wp_unslash( $_POST['components'] ) : [];
    $clean = array_values(array_intersect($raw, $valid));
    update_option('csbr_manual_defaults', $clean);
    wp_send_json_success('Defaults saved');
});

// ============================================================
// AJAX — Save retention
// ============================================================

add_action('wp_ajax_csbr_test_s3', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $bucket = get_option('csbr_s3_bucket', '');
    $prefix = get_option('csbr_s3_prefix', 'backups/');
    if (!$bucket) {
        wp_send_json_error('No bucket configured. Save your S3 settings first.');
    }
    $aws = csbr_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found on server.');
    }
    $prefix = trim($prefix, '/');
    $dest   = 's3://' . rtrim($bucket, '/') . '/' . ($prefix ? $prefix . '/' : '') . 'cloudscale-test.txt';
    $content = "CloudScale Backup connection test\nWritten: " . gmdate('Y-m-d H:i:s T') . "\nBucket: " . $bucket . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'csbr_test_');
    file_put_contents($tmp, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file for CLI upload test; WP Filesystem API not appropriate for temp file operations
    $real_tmp = realpath($tmp) ?: $tmp;
    $cmd = escapeshellarg($aws) . ' s3 cp ' . escapeshellarg($real_tmp) . ' ' . escapeshellarg($dest) . ' 2>&1';
    $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    wp_delete_file($tmp);
    // AWS CLI outputs nothing on success with --only-show-errors, but without that flag
    // it outputs "upload: /path to s3://..." which is a success message not an error
    if ($out && stripos($out, 'upload:') === false && stripos($out, 'completed') === false) {
        wp_send_json_error('Upload failed: ' . $out);
    }
    wp_send_json_success('Test file written to ' . $dest);
});

add_action('wp_ajax_csbr_save_s3', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $bucket = sanitize_text_field( wp_unslash( $_POST['bucket'] ?? '' ) );
    $prefix = sanitize_text_field( wp_unslash( $_POST['prefix'] ?? 'backups/' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    // Ensure prefix ends with /
    $prefix = rtrim($prefix, '/') . '/';
    update_option('csbr_s3_bucket', $bucket);
    update_option('csbr_s3_prefix', $prefix);
    wp_send_json_success(['bucket' => $bucket, 'prefix' => $prefix]);
});

// S3 auto-retry — single WP-Cron event scheduled 5 min after a failed sync
add_action('csbr_s3_retry', function (string $filename): void {
    $path = CSBR_BACKUP_DIR . $filename;
    if (!file_exists($path)) {
        csbr_log('[CloudScale Backup & Restore] S3 retry: file no longer exists: ' . $filename);
        return;
    }
    csbr_log('[CloudScale Backup & Restore] S3 retry attempt for ' . $filename);
    csbr_sync_to_s3($path, false); // false = no further retries on second failure
});

add_action('wp_ajax_csbr_s3_sync_file', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    if (!$filename || !str_ends_with($filename, '.zip')) {
        wp_send_json_error('Invalid filename.');
    }
    $path = CSBR_BACKUP_DIR . $filename;
    if (!file_exists($path)) {
        wp_send_json_error('File not found on server.');
    }
    $result = csbr_sync_to_s3($path);
    if (isset($result['skipped'])) {
        wp_send_json_error('S3 not configured — set a bucket name in the AWS S3 Remote Backup settings.');
    }
    if ($result['ok']) {
        wp_send_json_success('Synced to ' . $result['dest']);
    } else {
        wp_send_json_error($result['error'] ?? 'Sync failed.');
    }
});

// ============================================================
// AJAX — AMI snapshot operations
// ============================================================

add_action('wp_ajax_csbr_save_ami', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $prefix          = sanitize_text_field( wp_unslash( $_POST['prefix'] ?? '' ) );
    $reboot          = !empty( $_POST['reboot'] );
    $region_override = sanitize_text_field( wp_unslash( $_POST['region_override'] ?? '' ) );
    $ami_max         = max(1, min(999, intval( wp_unslash( $_POST['ami_max'] ?? 10 ) )));
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    // Validate region format if provided: letters, digits, hyphens only
    if ($region_override && !preg_match('/^[a-z0-9-]+$/', $region_override)) {
        wp_send_json_error('Invalid region format. Example: af-south-1');
    }
    update_option('csbr_ami_prefix', $prefix);
    update_option('csbr_ami_reboot', $reboot);
    update_option('csbr_ami_region_override', $region_override);
    update_option('csbr_ami_max', $ami_max);
    wp_send_json_success(['prefix' => $prefix, 'reboot' => $reboot, 'region' => $region_override, 'ami_max' => $ami_max]);
});

add_action('wp_ajax_csbr_save_cloud_schedule', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_ajax_referer() above; intval()/!empty() used for boolean/integer fields
    $raw_days   = array_filter(explode(',', sanitize_text_field( wp_unslash( $_POST['ami_schedule_days'] ?? '' ) )));
    $clean_days = array_values(array_filter(array_map('intval', $raw_days), fn($d) => $d >= 1 && $d <= 7));
    update_option('csbr_ami_schedule_days',   $clean_days);
    update_option('csbr_cloud_backup_delay',  max(15, intval( wp_unslash( $_POST['cloud_backup_delay'] ?? 30 ) )));
    update_option('csbr_ami_max',             max(1, min(999, intval( wp_unslash( $_POST['cloud_max'] ?? 10 ) ))));
    update_option('csbr_s3_sync_enabled',        !empty($_POST['s3_sync_enabled']));
    update_option('csbr_gdrive_sync_enabled',    !empty($_POST['gdrive_sync_enabled']));
    update_option('csbr_dropbox_sync_enabled',   !empty($_POST['dropbox_sync_enabled']));
    update_option('csbr_onedrive_sync_enabled',  !empty($_POST['onedrive_sync_enabled']));
    update_option('csbr_ami_sync_enabled',       !empty($_POST['ami_sync_enabled']));
    update_option('csbr_cloud_schedule_enabled', !empty($_POST['cloud_schedule_enabled']));
    wp_cache_delete('csbr_ami_schedule_days',       'options');
    wp_cache_delete('csbr_cloud_backup_delay',      'options');
    wp_cache_delete('csbr_ami_max',                 'options');
    wp_cache_delete('csbr_s3_sync_enabled',         'options');
    wp_cache_delete('csbr_gdrive_sync_enabled',     'options');
    wp_cache_delete('csbr_dropbox_sync_enabled',    'options');
    wp_cache_delete('csbr_onedrive_sync_enabled',   'options');
    wp_cache_delete('csbr_ami_sync_enabled',        'options');
    wp_cache_delete('csbr_cloud_schedule_enabled',  'options');
    wp_cache_delete('alloptions',                'options');
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    csbr_reschedule();
    wp_send_json_success('Saved');
});

add_action('wp_ajax_csbr_save_gdrive', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $remote = sanitize_text_field( wp_unslash( $_POST['remote'] ?? '' ) );
    $path   = sanitize_text_field( wp_unslash( $_POST['path']   ?? 'cloudscale-backups/' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    if ($remote && !preg_match('/^[a-zA-Z0-9_\-]+$/', $remote)) {
        wp_send_json_error('Invalid remote name. Use only letters, numbers, hyphens and underscores.');
    }
    update_option('csbr_gdrive_remote', $remote);
    update_option('csbr_gdrive_path',   $path);
    wp_send_json_success(['remote' => $remote, 'path' => $path]);
});

add_action('wp_ajax_csbr_test_gdrive', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $remote = get_option('csbr_gdrive_remote', '');
    if (!$remote) { wp_send_json_error('No remote configured — save settings first.'); }
    $rclone = csbr_find_rclone();
    if (!$rclone) { wp_send_json_error('rclone not found on server.'); }
    $cmd = escapeshellarg($rclone) . ' lsd ' . escapeshellarg($remote . ':') . ' --max-depth 1 2>&1';
    $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ($out && preg_match('/error|failed|denied|invalid/i', $out)) {
        wp_send_json_error('Connection failed: ' . substr($out, 0, 200));
    }
    wp_send_json_success('Connected to ' . esc_html($remote));
});

add_action('wp_ajax_csbr_sync_latest_s3', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $latest = csbr_get_latest_backup_path();
    if (!$latest) { wp_send_json_error('No local backups found.'); }
    $result = csbr_sync_to_s3($latest);
    if (isset($result['skipped'])) { wp_send_json_error('S3 not configured — save bucket settings first.'); }
    $result['ok'] ? wp_send_json_success('Synced: ' . basename($latest)) : wp_send_json_error($result['error'] ?? 'Sync failed.');
});

add_action('wp_ajax_csbr_sync_latest_gdrive', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $latest = csbr_get_latest_backup_path();
    if (!$latest) { wp_send_json_error('No local backups found.'); }
    $result = csbr_sync_to_gdrive($latest);
    if (isset($result['skipped'])) { wp_send_json_error('Google Drive not configured — save remote settings first.'); }
    $result['ok'] ? wp_send_json_success('Synced: ' . basename($latest)) : wp_send_json_error($result['error'] ?? 'Sync failed.');
});

// ============================================================
// AJAX — Dropbox handlers
// ============================================================

add_action( 'wp_ajax_csbr_save_dropbox', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $remote = sanitize_text_field( wp_unslash( $_POST['remote'] ?? '' ) );
    $path   = sanitize_text_field( wp_unslash( $_POST['path']   ?? 'cloudscale-backups/' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    if ( $remote && ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $remote ) ) {
        wp_send_json_error( 'Invalid remote name. Use only letters, numbers, hyphens and underscores.' );
    }
    update_option( 'csbr_dropbox_remote', $remote );
    update_option( 'csbr_dropbox_path',   $path );
    wp_send_json_success( [ 'remote' => $remote, 'path' => $path ] );
} );

add_action( 'wp_ajax_csbr_test_dropbox', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $remote = get_option( 'csbr_dropbox_remote', '' );
    if ( ! $remote ) { wp_send_json_error( 'No remote configured — save settings first.' ); }
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) { wp_send_json_error( 'rclone not found on server.' ); }
    $cmd = escapeshellarg( $rclone ) . ' lsd ' . escapeshellarg( $remote . ':' ) . ' --max-depth 1 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( $out && preg_match( '/error|failed|denied|invalid/i', $out ) ) {
        wp_send_json_error( 'Connection failed: ' . substr( $out, 0, 200 ) );
    }
    wp_send_json_success( 'Connected to ' . esc_html( $remote ) );
} );

add_action( 'wp_ajax_csbr_sync_latest_dropbox', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $latest = csbr_get_latest_backup_path();
    if ( ! $latest ) { wp_send_json_error( 'No local backups found.' ); }
    $result = csbr_sync_to_dropbox( $latest );
    if ( isset( $result['skipped'] ) ) { wp_send_json_error( 'Dropbox not configured — save remote settings first.' ); }
    $result['ok'] ? wp_send_json_success( 'Synced: ' . basename( $latest ) ) : wp_send_json_error( $result['error'] ?? 'Sync failed.' );
} );

add_action( 'wp_ajax_csbr_save_onedrive', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified above
    $remote = sanitize_text_field( wp_unslash( $_POST['remote'] ?? '' ) );
    $path   = sanitize_text_field( wp_unslash( $_POST['path']   ?? 'cloudscale-backups/' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    if ( $remote && ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $remote ) ) {
        wp_send_json_error( 'Invalid remote name. Use only letters, numbers, hyphens and underscores.' );
    }
    update_option( 'csbr_onedrive_remote', $remote );
    update_option( 'csbr_onedrive_path',   $path );
    wp_send_json_success( [ 'remote' => $remote, 'path' => $path ] );
} );

add_action( 'wp_ajax_csbr_test_onedrive', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $remote = get_option( 'csbr_onedrive_remote', '' );
    if ( ! $remote ) { wp_send_json_error( 'No remote configured — save settings first.' ); }
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) { wp_send_json_error( 'rclone not found on server.' ); }
    $cmd = escapeshellarg( $rclone ) . ' lsd ' . escapeshellarg( $remote . ':' ) . ' --max-depth 1 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( $out && preg_match( '/error|failed|denied|invalid/i', $out ) ) {
        wp_send_json_error( 'Connection failed: ' . substr( $out, 0, 200 ) );
    }
    wp_send_json_success( 'Connected to ' . esc_html( $remote ) );
} );

add_action( 'wp_ajax_csbr_sync_latest_onedrive', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $latest = csbr_get_latest_backup_path();
    if ( ! $latest ) { wp_send_json_error( 'No local backups found.' ); }
    $result = csbr_sync_to_onedrive( $latest );
    if ( isset( $result['skipped'] ) ) { wp_send_json_error( 'OneDrive not configured — save remote settings first.' ); }
    $result['ok'] ? wp_send_json_success( 'Synced: ' . basename( $latest ) ) : wp_send_json_error( $result['error'] ?? 'Sync failed.' );
} );

// ============================================================
// AJAX — Async cloud sync: shared helpers
// ============================================================

/**
 * Start an async cloud sync job.
 *
 * Uses fastcgi_finish_request() to flush the JSON response to the browser immediately,
 * then runs the upload in the same PHP-FPM process — no HTTP loopback needed.
 * This bypasses CloudFront/CDN routing entirely.
 *
 * @since 3.2.251
 */
function csbr_start_async_sync( string $provider_action ): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $latest = csbr_get_latest_backup_path();
    if ( ! $latest ) wp_send_json_error( 'No local backups found.' );

    $job_id = 'csbr_syncjob_' . bin2hex( random_bytes( 8 ) );
    csbr_set_job( $job_id, [ 'status' => 'queued', 'started' => time() ] );
    csbr_log( "[CloudScale Backup & Restore] Sync job queued: {$job_id} ({$provider_action}) for " . basename( $latest ) );

    // Run the upload after the HTTP response is flushed to the browser.
    // register_shutdown_function fires after wp_send_json_success/wp_die, then
    // fastcgi_finish_request flushes the clean JSON response before the upload starts.
    register_shutdown_function( function () use ( $job_id, $provider_action, $latest ): void {
        if ( function_exists( 'fastcgi_finish_request' ) ) fastcgi_finish_request();
        set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        ignore_user_abort( true );
        csbr_execute_sync_job( $job_id, $provider_action, $latest );
    } );

    wp_send_json_success( [ 'job_id' => $job_id ] );
}

/**
 * Execute a cloud sync job — called after the HTTP response has been flushed.
 *
 * @since 3.2.251
 */
function csbr_execute_sync_job( string $job_id, string $provider_action, string $latest ): void {
    $map = [
        'csbr_do_sync_job_s3'       => [ 'csbr_sync_to_s3',       'S3' ],
        'csbr_do_sync_job_gdrive'   => [ 'csbr_sync_to_gdrive',   'Google Drive' ],
        'csbr_do_sync_job_dropbox'  => [ 'csbr_sync_to_dropbox',  'Dropbox' ],
        'csbr_do_sync_job_onedrive' => [ 'csbr_sync_to_onedrive', 'OneDrive' ],
    ];
    if ( ! isset( $map[ $provider_action ] ) ) {
        csbr_set_job( $job_id, [ 'status' => 'error', 'message' => 'Unknown provider action.' ] );
        return;
    }
    [ $sync_fn, $label ] = $map[ $provider_action ];

    csbr_set_job( $job_id, [ 'status' => 'running', 'started' => time() ] );
    csbr_log( "[CloudScale Backup & Restore] {$label} sync starting: " . basename( $latest ) . ' (' . round( filesize( $latest ) / 1048576, 1 ) . ' MB)' );

    $result = $sync_fn( $latest );

    if ( isset( $result['skipped'] ) ) {
        csbr_log( "[CloudScale Backup & Restore] {$label} sync skipped: not configured." );
        csbr_set_job( $job_id, [ 'status' => 'error', 'message' => $label . ' not configured.' ] );
    } elseif ( $result['ok'] ) {
        csbr_log( "[CloudScale Backup & Restore] {$label} sync complete: " . basename( $latest ) );
        csbr_set_job( $job_id, [ 'status' => 'complete', 'message' => 'Synced: ' . basename( $latest ) ] );
    } else {
        csbr_log( "[CloudScale Backup & Restore] {$label} sync failed: " . ( $result['error'] ?? 'unknown error' ) );
        csbr_set_job( $job_id, [ 'status' => 'error', 'message' => $result['error'] ?? 'Sync failed.' ] );
    }
}

// ============================================================
// AJAX — Async S3 sync
// ============================================================

add_action( 'wp_ajax_csbr_start_sync_s3',     function (): void { csbr_start_async_sync( 'csbr_do_sync_job_s3' ); } );
add_action( 'wp_ajax_csbr_do_sync_job_s3',    function (): void { csbr_do_async_sync( 'csbr_sync_to_s3', 'S3' ); } );

// ============================================================
// AJAX — Async GDrive sync
// ============================================================

add_action( 'wp_ajax_csbr_start_sync_gdrive',  function (): void { csbr_start_async_sync( 'csbr_do_sync_job_gdrive' ); } );
add_action( 'wp_ajax_csbr_do_sync_job_gdrive', function (): void { csbr_do_async_sync( 'csbr_sync_to_gdrive', 'Google Drive' ); } );

// ============================================================
// AJAX — Async Dropbox sync
// ============================================================

add_action( 'wp_ajax_csbr_start_sync_dropbox',   function (): void { csbr_start_async_sync( 'csbr_do_sync_job_dropbox' ); } );
add_action( 'wp_ajax_csbr_do_sync_job_dropbox',  function (): void { csbr_do_async_sync( 'csbr_sync_to_dropbox',  'Dropbox' ); } );

// ============================================================
// AJAX — Async OneDrive sync
// ============================================================

add_action( 'wp_ajax_csbr_start_sync_onedrive',  function (): void { csbr_start_async_sync( 'csbr_do_sync_job_onedrive' ); } );
add_action( 'wp_ajax_csbr_do_sync_job_onedrive', function (): void { csbr_do_async_sync( 'csbr_sync_to_onedrive', 'OneDrive' ); } );

// ============================================================
// AJAX — Batch job status (single request covers all pending sync jobs)
// ============================================================

add_action( 'wp_ajax_csbr_batch_job_status', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via check_ajax_referer() above
    $raw     = sanitize_text_field( wp_unslash( $_POST['job_ids'] ?? '' ) );
    $ids     = array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) );
    $results = [];
    foreach ( $ids as $id ) {
        if ( ! str_starts_with( $id, 'csbr_syncjob_' ) && ! str_starts_with( $id, 'csbr_bkjob_' ) ) continue;
        $data         = csbr_get_job( $id );
        $results[$id] = $data !== false ? $data : [ 'status' => 'not_found' ];
    }
    wp_send_json_success( $results );
} );

// ============================================================
// AJAX — Cloud space check (pre-backup warning)
// ============================================================

add_action( 'wp_ajax_csbr_cloud_space_check', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $only = sanitize_key( $_POST['provider'] ?? '' ); // optional: limit to one provider

    $zips     = glob( CSBR_BACKUP_DIR . '*.zip' ) ?: [];
    $est_size = 0;
    if ( $zips ) {
        rsort( $zips );
        $est_size = (int) filesize( $zips[0] );
    }

    $providers = [];

    if ( ( ! $only || $only === 'dropbox' ) && get_option( 'csbr_dropbox_sync_enabled', false ) && get_option( 'csbr_dropbox_remote', '' ) ) {
        $free = csbr_rclone_free_bytes( get_option( 'csbr_dropbox_remote', '' ) );
        $providers['dropbox'] = [
            'label'   => 'Dropbox',
            'free_mb' => $free !== null ? round( $free / 1048576, 1 ) : null,
            'est_mb'  => $est_size ? round( $est_size / 1048576, 1 ) : null,
            'ok'      => $free === null || ! $est_size || $free >= $est_size,
        ];
    }

    if ( ( ! $only || $only === 'gdrive' ) && get_option( 'csbr_gdrive_sync_enabled', true ) && get_option( 'csbr_gdrive_remote', '' ) ) {
        $free = csbr_rclone_free_bytes( get_option( 'csbr_gdrive_remote', '' ) );
        $providers['gdrive'] = [
            'label'   => 'Google Drive',
            'free_mb' => $free !== null ? round( $free / 1048576, 1 ) : null,
            'est_mb'  => $est_size ? round( $est_size / 1048576, 1 ) : null,
            'ok'      => $free === null || ! $est_size || $free >= $est_size,
        ];
    }

    wp_send_json_success( $providers );
} );

// ============================================================
// AJAX — Activity log viewer
// ============================================================

add_action( 'wp_ajax_csbr_get_activity_log', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $since   = (int) wp_unslash( $_POST['since'] ?? 0 );
    $entries = (array) get_option( 'csbr_activity_log', [] );
    // Also pull any pending individual log entries written by concurrent background workers.
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'cs\_log\_%' ORDER BY option_name LIMIT 200", ARRAY_A );
    foreach ( $rows as $row ) {
        $val = maybe_unserialize( $row['option_value'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_maybe_unserialize -- value written only by this plugin via csbr_log()
        if ( is_array( $val ) && isset( $val['t'], $val['m'] ) ) {
            $entries[] = $val;
        }
    }
    usort( $entries, fn( $a, $b ) => ( $a['t'] ?? 0 ) <=> ( $b['t'] ?? 0 ) );
    if ( $since ) {
        $entries = array_values( array_filter( $entries, fn( $e ) => ( $e['t'] ?? 0 ) > $since ) );
    }
    wp_send_json_success( [ 'entries' => array_slice( $entries, -100 ), 'server_time' => time() ] );
} );

add_action( 'wp_ajax_csbr_clear_activity_log', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    update_option( 'csbr_activity_log', [], false );
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cs\_log\_%'" );
    wp_send_json_success( 'Cleared.' );
} );

// ============================================================
// AJAX — Delete oldest cloud backup (space recovery)
// ============================================================

add_action( 'wp_ajax_csbr_delete_oldest_cloud', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_ajax_referer( 'csbr_nonce', 'nonce' ); sanitize_key() and (float) cast sanitise inputs
    $provider  = sanitize_key( $_POST['provider'] ?? '' );
    $need_mb   = (float) wp_unslash( $_POST['need_mb'] ?? 0 );  // how much space the backup needs
    $need_bytes = $need_mb > 0 ? (int) ( $need_mb * 1048576 ) : 0;

    $remote = $provider === 'dropbox' ? get_option( 'csbr_dropbox_remote', '' ) : get_option( 'csbr_gdrive_remote', '' );

    // Delete oldest backups one by one until there is enough free space (or no more to delete).
    $deleted = [];
    $max_loops  = 20; // safety cap
    $target     = (int) ( $need_bytes * 1.15 ); // 15% buffer so we don't stop right at the edge
    for ( $i = 0; $i < $max_loops; $i++ ) {
        // Check current free space
        $free = $remote ? csbr_rclone_free_bytes( $remote ) : null;
        if ( $need_bytes > 0 && $free !== null && $free >= $target ) {
            break; // enough space now (with buffer)
        }
        $result = csbr_delete_oldest_cloud_backup( $provider );
        if ( ! $result['ok'] ) {
            if ( empty( $deleted ) ) {
                wp_send_json_error( $result['error'] ?? 'Delete failed.' );
            }
            break; // no more deletable backups
        }
        $deleted[] = $result['deleted'] ?? '';
        if ( $need_bytes === 0 ) break; // single-delete mode if no target size given
    }

    $free     = $remote ? csbr_rclone_free_bytes( $remote ) : null;
    $free_mb  = $free !== null ? round( $free / 1048576, 1 ) : null;
    wp_send_json_success( [
        'ok'      => true,
        'deleted' => implode( ', ', array_filter( $deleted ) ),
        'count'   => count( $deleted ),
        'free_mb' => $free_mb,
    ] );
} );

/**
 * Delete the oldest non-golden backup from a cloud provider to reclaim space.
 *
 * @since 3.2.251
 * @param string $provider 'dropbox' or 'gdrive'.
 * @return array{ok: bool, deleted?: string, error?: string}
 */
function csbr_delete_oldest_cloud_backup( string $provider ): array {
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) return [ 'ok' => false, 'error' => 'rclone not found.' ];

    if ( $provider === 'dropbox' ) {
        $remote = get_option( 'csbr_dropbox_remote', '' );
        if ( ! $remote ) return [ 'ok' => false, 'error' => 'Dropbox not configured.' ];
        $dest_path = ltrim( get_option( 'csbr_dropbox_path', 'cloudscale-backups/' ), '/' );
        $history   = (array) get_option( 'csbr_dropbox_history', [] );
        $regular   = array_filter( $history, fn( $e ) => empty( $e['golden'] ) );
        if ( empty( $regular ) ) return [ 'ok' => false, 'error' => 'No non-golden Dropbox backups to delete.' ];
        uasort( $regular, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
        $key  = array_key_first( $regular );
        $name = $regular[ $key ]['name'] ?? $key;
        $cmd  = escapeshellarg( $rclone ) . ' deletefile ' . escapeshellarg( rtrim( $remote, ':' ) . ':' . rtrim( $dest_path, '/' ) . '/' . $name ) . ' 2>&1';
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        if ( $out && ! preg_match( '/object not found/i', $out ) ) return [ 'ok' => false, 'error' => 'Delete failed: ' . $out ];
        unset( $history[ $key ] );
        update_option( 'csbr_dropbox_history', $history, false );
        csbr_log( '[CloudScale Backup & Restore] Manually deleted oldest Dropbox backup: ' . $name );
        return [ 'ok' => true, 'deleted' => $name ];

    } elseif ( $provider === 'gdrive' ) {
        $remote    = get_option( 'csbr_gdrive_remote', '' );
        if ( ! $remote ) return [ 'ok' => false, 'error' => 'Google Drive not configured.' ];
        $dest_path   = ltrim( get_option( 'csbr_gdrive_path', 'cloudscale-backups/' ), '/' );
        $remote_path = rtrim( $remote, ':' ) . ':' . $dest_path;
        $history     = (array) get_option( 'csbr_gdrive_history', [] );
        $regular     = array_filter( $history, fn( $e ) => empty( $e['golden'] ) );
        if ( $regular ) {
            uasort( $regular, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
            $name = array_key_first( $regular );
        } else {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
            $lsout = trim( (string) shell_exec( escapeshellarg( $rclone ) . ' lsjson ' . escapeshellarg( $remote_path ) . ' --files-only 2>&1' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
            if ( ! $lsout ) return [ 'ok' => false, 'error' => 'No files found on Google Drive.' ];
            $files = json_decode( $lsout, true );
            if ( ! is_array( $files ) ) return [ 'ok' => false, 'error' => 'Could not list Google Drive files.' ];
            $zips = array_values( array_filter( $files, fn( $f ) => str_ends_with( $f['Name'] ?? '', '.zip' ) ) );
            if ( empty( $zips ) ) return [ 'ok' => false, 'error' => 'No backup zips found on Google Drive.' ];
            usort( $zips, fn( $a, $b ) => strcmp( $a['ModTime'] ?? '', $b['ModTime'] ?? '' ) );
            $name = $zips[0]['Name'];
        }
        $cmd = escapeshellarg( $rclone ) . ' deletefile ' . escapeshellarg( $remote_path . $name ) . ' 2>&1';
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        if ( $out && ! preg_match( '/object not found/i', $out ) ) return [ 'ok' => false, 'error' => 'Delete failed: ' . $out ];
        unset( $history[ $name ] );
        update_option( 'csbr_gdrive_history', $history, false );
        csbr_log( '[CloudScale Backup & Restore] Manually deleted oldest Google Drive backup: ' . $name );
        return [ 'ok' => true, 'deleted' => $name ];
    }

    return [ 'ok' => false, 'error' => 'Unknown provider.' ];
}

add_action( 'wp_ajax_csbr_dropbox_refresh_history', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    if ( ! get_option( 'csbr_dropbox_remote', '' ) ) { wp_send_json_error( 'Dropbox not configured.' ); }
    $hist = csbr_dropbox_refresh_history();
    update_option( 'csbr_dropbox_remote_count', count( $hist ), false );
    wp_send_json_success( [ 'files' => array_values( $hist ), 'count' => count( $hist ) ] );
} );

add_action( 'wp_ajax_csbr_dropbox_set_golden', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    $history  = (array) get_option( 'csbr_dropbox_history', [] );
    if ( ! isset( $history[$filename] ) ) { wp_send_json_error( 'File not found in history.' ); }
    $history[$filename]['golden'] = empty( $history[$filename]['golden'] );
    update_option( 'csbr_dropbox_history', $history, false );
    wp_send_json_success( [ 'golden' => $history[$filename]['golden'] ] );
} );

add_action( 'wp_ajax_csbr_dropbox_set_tag', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    $tag      = substr( sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) ), 0, 40 );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $history = (array) get_option( 'csbr_dropbox_history', [] );
    if ( ! isset( $history[$filename] ) ) { wp_send_json_error( 'File not found in history.' ); }
    $history[$filename]['tag'] = $tag;
    update_option( 'csbr_dropbox_history', $history, false );
    wp_send_json_success( [ 'tag' => $tag ] );
} );

add_action( 'wp_ajax_csbr_dropbox_delete_remote', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    if ( ! $filename ) { wp_send_json_error( 'Invalid filename.' ); }
    $remote = get_option( 'csbr_dropbox_remote', '' );
    if ( ! $remote ) { wp_send_json_error( 'Dropbox not configured.' ); }
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) { wp_send_json_error( 'rclone not found.' ); }
    $dest_path = ltrim( get_option( 'csbr_dropbox_path', 'cloudscale-backups/' ), '/' );
    $rpath     = rtrim( $remote, ':' ) . ':' . rtrim( $dest_path, '/' ) . '/' . $filename;
    $cmd       = escapeshellarg( $rclone ) . ' deletefile ' . escapeshellarg( $rpath ) . ' 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( $out && preg_match( '/error|failed|no such/i', $out ) ) {
        wp_send_json_error( 'Delete failed: ' . substr( $out, 0, 200 ) );
    }
    $history = (array) get_option( 'csbr_dropbox_history', [] );
    unset( $history[$filename] );
    update_option( 'csbr_dropbox_history', $history, false );
    update_option( 'csbr_dropbox_remote_count', count( $history ), false );
    wp_send_json_success( 'Deleted.' );
} );

/**
 * Runs OPTIMIZE TABLE on all InnoDB tables with overhead.
 *
 * @return int Number of tables optimized.
 */
function csbr_run_table_repair(): int {
    global $wpdb;
    $tables = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->prepare(
            'SELECT table_name FROM information_schema.TABLES WHERE table_schema = %s AND data_free > 0 AND engine = %s',
            DB_NAME,
            'InnoDB'
        )
    );
    $count = 0;
    foreach ( $tables as $table ) {
        $wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count++;
    }
    return $count;
}

add_action( 'wp_ajax_csbr_repair_tables', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $count = csbr_run_table_repair();
    wp_send_json_success( $count . ' table' . ( $count === 1 ? '' : 's' ) . ' optimized.' );
} );

add_action( 'admin_post_csbr_dropbox_download', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden', 'cloudscale-backup-restore' ), 403 ); }
    // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified below
    $nonce    = sanitize_text_field( wp_unslash( $_GET['nonce']   ?? '' ) );
    $filename = sanitize_file_name( wp_unslash( $_GET['file']    ?? '' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    if ( ! wp_verify_nonce( $nonce, 'csbr_nonce' ) ) { wp_die( esc_html__( 'Invalid nonce.', 'cloudscale-backup-restore' ), 403 ); }
    if ( ! $filename || ! str_ends_with( $filename, '.zip' ) ) { wp_die( esc_html__( 'Invalid filename.', 'cloudscale-backup-restore' ), 400 ); }
    $remote = get_option( 'csbr_dropbox_remote', '' );
    if ( ! $remote ) { wp_die( esc_html__( 'Dropbox not configured.', 'cloudscale-backup-restore' ) ); }
    $rclone    = csbr_find_rclone();
    if ( ! $rclone ) { wp_die( esc_html__( 'rclone not found on server.', 'cloudscale-backup-restore' ) ); }
    $dest_path = ltrim( get_option( 'csbr_dropbox_path', 'cloudscale-backups/' ), '/' );
    $rpath     = rtrim( $remote, ':' ) . ':' . rtrim( $dest_path, '/' ) . '/' . $filename;
    $tmp       = wp_tempnam( $filename );
    $cmd       = escapeshellarg( $rclone ) . ' copyto ' . escapeshellarg( $rpath ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( $out || ! file_exists( $tmp ) || filesize( $tmp ) === 0 ) {
        wp_delete_file( $tmp );
        wp_die( esc_html__( 'Download failed:', 'cloudscale-backup-restore' ) . ' ' . esc_html( $out ) );
    }
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . str_replace( [ "\r", "\n", '"', '\\' ], '', $filename ) . '"' );
    header( 'Content-Length: ' . filesize( $tmp ) );
    readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming binary download; WP_Filesystem has no streaming equivalent
    wp_delete_file( $tmp );
    exit;
} );

add_action( 'wp_ajax_csbr_onedrive_refresh_history', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    if ( ! get_option( 'csbr_onedrive_remote', '' ) ) { wp_send_json_error( 'OneDrive not configured.' ); }
    $hist = csbr_onedrive_refresh_history();
    update_option( 'csbr_onedrive_remote_count', count( $hist ), false );
    wp_send_json_success( [ 'files' => array_values( $hist ), 'count' => count( $hist ) ] );
} );

add_action( 'wp_ajax_csbr_onedrive_set_golden', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    $history  = (array) get_option( 'csbr_onedrive_history', [] );
    if ( ! isset( $history[$filename] ) ) { wp_send_json_error( 'File not found in history.' ); }
    $history[$filename]['golden'] = empty( $history[$filename]['golden'] );
    update_option( 'csbr_onedrive_history', $history, false );
    wp_send_json_success( [ 'golden' => $history[$filename]['golden'] ] );
} );

add_action( 'wp_ajax_csbr_onedrive_set_tag', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    $tag      = substr( sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) ), 0, 40 );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $history = (array) get_option( 'csbr_onedrive_history', [] );
    if ( ! isset( $history[$filename] ) ) { wp_send_json_error( 'File not found in history.' ); }
    $history[$filename]['tag'] = $tag;
    update_option( 'csbr_onedrive_history', $history, false );
    wp_send_json_success( [ 'tag' => $tag ] );
} );

add_action( 'wp_ajax_csbr_onedrive_delete_remote', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    if ( ! $filename ) { wp_send_json_error( 'Invalid filename.' ); }
    $remote = get_option( 'csbr_onedrive_remote', '' );
    if ( ! $remote ) { wp_send_json_error( 'OneDrive not configured.' ); }
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) { wp_send_json_error( 'rclone not found.' ); }
    $dest_path = ltrim( get_option( 'csbr_onedrive_path', 'cloudscale-backups/' ), '/' );
    $rpath     = rtrim( $remote, ':' ) . ':' . rtrim( $dest_path, '/' ) . '/' . $filename;
    $cmd       = escapeshellarg( $rclone ) . ' deletefile ' . escapeshellarg( $rpath ) . ' 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( $out && preg_match( '/error|failed|no such/i', $out ) ) {
        wp_send_json_error( 'Delete failed: ' . substr( $out, 0, 200 ) );
    }
    $history = (array) get_option( 'csbr_onedrive_history', [] );
    unset( $history[$filename] );
    update_option( 'csbr_onedrive_history', $history, false );
    update_option( 'csbr_onedrive_remote_count', count( $history ), false );
    wp_send_json_success( 'Deleted.' );
} );

add_action( 'admin_post_csbr_onedrive_download', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden', 'cloudscale-backup-restore' ), 403 ); }
    // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified below
    $nonce    = sanitize_text_field( wp_unslash( $_GET['nonce']   ?? '' ) );
    $filename = sanitize_file_name( wp_unslash( $_GET['file']    ?? '' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    if ( ! wp_verify_nonce( $nonce, 'csbr_nonce' ) ) { wp_die( esc_html__( 'Invalid nonce.', 'cloudscale-backup-restore' ), 403 ); }
    if ( ! $filename || ! str_ends_with( $filename, '.zip' ) ) { wp_die( esc_html__( 'Invalid filename.', 'cloudscale-backup-restore' ), 400 ); }
    $remote = get_option( 'csbr_onedrive_remote', '' );
    if ( ! $remote ) { wp_die( esc_html__( 'OneDrive not configured.', 'cloudscale-backup-restore' ) ); }
    $rclone    = csbr_find_rclone();
    if ( ! $rclone ) { wp_die( esc_html__( 'rclone not found on server.', 'cloudscale-backup-restore' ) ); }
    $dest_path = ltrim( get_option( 'csbr_onedrive_path', 'cloudscale-backups/' ), '/' );
    $rpath     = rtrim( $remote, ':' ) . ':' . rtrim( $dest_path, '/' ) . '/' . $filename;
    $tmp       = wp_tempnam( $filename );
    $cmd       = escapeshellarg( $rclone ) . ' copyto ' . escapeshellarg( $rpath ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( $out || ! file_exists( $tmp ) || filesize( $tmp ) === 0 ) {
        wp_delete_file( $tmp );
        wp_die( esc_html__( 'Download failed:', 'cloudscale-backup-restore' ) . ' ' . esc_html( $out ) );
    }
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . str_replace( [ "\r", "\n", '"', '\\' ], '', $filename ) . '"' );
    header( 'Content-Length: ' . filesize( $tmp ) );
    readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming binary download; WP_Filesystem has no streaming equivalent
    wp_delete_file( $tmp );
    exit;
} );

add_action('wp_ajax_csbr_create_ami', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    set_time_limit(120); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to allow AMI create-image API call to complete

    $instance_id = csbr_get_instance_id();
    if (!$instance_id) {
        wp_send_json_error('Cannot detect EC2 instance ID. Is this server running on EC2 with IMDS enabled?');
    }

    $aws = csbr_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found on server.');
    }

    $prefix = get_option('csbr_ami_prefix', '');
    if (!$prefix) {
        wp_send_json_error('Set an AMI name prefix in settings first.');
    }

    $reboot  = (bool) get_option('csbr_ami_reboot', false);
    $region  = csbr_get_instance_region();
    $tz      = wp_timezone();
    $now     = new DateTime('now', $tz);
    // AWS AMI names allow: a-z A-Z 0-9 ( ) . - / _ only, max 128 chars
    $safe_prefix = preg_replace('/[^a-zA-Z0-9().\-\/_]/', '-', $prefix);
    $safe_prefix = preg_replace('/-{2,}/', '-', trim($safe_prefix, '-'));
    $ami_name    = substr($safe_prefix . '_' . $now->format('Ymd_Hi'), 0, 128);

    // AWS descriptions must be ASCII only
    $description = 'CloudScale Backup AMI - ' . get_site_url() . ' - ' . $now->format('Y-m-d H:i T');
    $description = preg_replace('/[^\x20-\x7E]/', '', $description);

    // Build the create-image command
    $no_reboot = $reboot ? '' : ' --no-reboot';
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $cmd = escapeshellarg($aws) . ' ec2 create-image'
         . ' --instance-id ' . escapeshellarg($instance_id)
         . ' --name ' . escapeshellarg($ami_name)
         . ' --description ' . escapeshellarg($description)
         . $no_reboot
         . $region_flag
         . ' --output json 2>&1';

    $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

    // Parse the JSON response to get AMI ID
    $result = json_decode($out, true);
    $ami_id = $result['ImageId'] ?? null;

    $log = (array) get_option('csbr_ami_log', []);

    if ($ami_id) {
        $entry = [
            'ok'          => true,
            'ami_id'      => $ami_id,
            'name'        => $ami_name,
            'instance_id' => $instance_id,
            'time'        => time(),
            'reboot'      => $reboot,
            'state'       => 'pending',
        ];
        $log[] = $entry;
        // Enforce csbr_ami_max — deregister and prune oldest successful entries beyond the limit
        $log = csbr_ami_enforce_max($log, $aws, $region);

        $msg = 'AMI creation started: ' . $ami_id . ' (' . $ami_name . ')';
        if ($reboot) $msg .= ' — instance will reboot shortly';

        // Schedule the first state poll in 10 minutes if not already queued
        if (!wp_next_scheduled('csbr_ami_poll')) {
            wp_schedule_single_event(time() + CSBR_AMI_POLL_INTERVAL, 'csbr_ami_poll');
            csbr_log('[CloudScale Backup & Restore] AMI poll: first check scheduled in ' . (CSBR_AMI_POLL_INTERVAL / 60) . ' min for ' . $ami_id);
        }

        wp_send_json_success(['message' => $msg, 'ami_id' => $ami_id, 'name' => $ami_name]);
    } else {
        $entry = [
            'ok'     => false,
            'name'   => $ami_name,
            'time'   => time(),
            'error'  => substr($out, 0, 500),
        ];
        $log[] = $entry;
        if (count($log) > 20) $log = array_slice($log, -20);
        update_option('csbr_ami_log', $log, false);

        csbr_log('[CloudScale Backup & Restore] AMI creation failed: ' . $out);
        wp_send_json_error('AMI creation failed: ' . $out);
    }
});

// ============================================================
// AMI retention enforcement — deregisters oldest AMIs from AWS
// ============================================================

/**
 * Enforce the maximum AMI retention limit, deregistering the oldest AMIs from AWS as needed.
 *
 * @since 2.74.0
 * @param array  $log    Current AMI log array.
 * @param string $aws    Absolute path to the AWS CLI binary.
 * @param string $region AWS region, or empty string to let the CLI use its default.
 * @return array Updated AMI log array with pruned entries removed.
 */
function csbr_ami_enforce_max(array $log, string $aws, string $region): array {
    $ami_max      = max(1, intval(get_option('csbr_ami_max', 10)));
    // Golden images are never pruned and do not count towards the quota.
    $golden_entries = array_values(array_filter($log, fn($e) => !empty($e['ok']) && !empty($e['ami_id']) && !empty($e['golden'])));
    $ok_entries     = array_values(array_filter($log, fn($e) => !empty($e['ok']) && !empty($e['ami_id']) &&  empty($e['golden'])));
    $fail_entries   = array_values(array_filter($log, fn($e) =>  empty($e['ok']) ||  empty($e['ami_id'])));

    // Sort oldest first (non-golden only — these are the pruneable entries)
    usort($ok_entries, fn($a, $b) => ($a['time'] ?? 0) <=> ($b['time'] ?? 0));

    if (count($ok_entries) > $ami_max) {
        $to_prune    = array_slice($ok_entries, 0, count($ok_entries) - $ami_max);
        $keep        = array_slice($ok_entries, -$ami_max);
        $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';

        foreach ($to_prune as $old) {
            $ami_id = $old['ami_id'] ?? '';
            if (!$ami_id || !preg_match('/^ami-[a-f0-9]+$/', $ami_id)) {
                continue;
            }
            $cmd = escapeshellarg($aws) . ' ec2 deregister-image'
                 . ' --image-id ' . escapeshellarg($ami_id)
                 . $region_flag
                 . ' 2>&1';
            $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
            // deregister-image returns empty output on success.
            // Also treat "InvalidAMIID.NotFound" as success — already gone from AWS.
            $already_gone = str_contains($out, 'InvalidAMIID') || str_contains($out, 'does not exist');
            if ($out === '' || $already_gone) {
                // Deregistered (or already gone) — drop local record
                csbr_log('[CloudScale Backup & Restore] AMI auto-deregistered: ' . $ami_id . ' (' . ($old['name'] ?? '') . ')');
            } else {
                // Deregistration failed — still drop from local log to enforce the count limit,
                // but mark it so the user knows manual cleanup in AWS may be needed.
                csbr_log('[CloudScale Backup & Restore] AMI auto-deregister FAILED for ' . $ami_id . ': ' . $out . ' — removed from local log; may need manual removal in AWS console');
            }
        }

        $ok_entries = $keep;
    }

    // Keep at most 20 failed entries
    $fail_entries = array_slice($fail_entries, -20);
    $log = array_values(array_merge($golden_entries, $ok_entries, $fail_entries));
    update_option('csbr_ami_log', $log, false);
    return $log;
}

// ============================================================
// AMI creation — shared logic used by scheduler and AJAX handler
// ============================================================

/**
 * Create an AMI snapshot of the current EC2 instance.
 * Returns an associative array with keys:
 *   'ok'     => bool
 *   'ami_id' => string|null
 *   'name'   => string
 *   'error'  => string  (only present when ok === false)
 *
 * @since 2.74.0
 */
function csbr_do_create_ami(): array {
    $instance_id = csbr_get_instance_id();
    if (!$instance_id) {
        return ['ok' => false, 'ami_id' => null, 'name' => '', 'error' => 'EC2 instance ID not detected.'];
    }

    $aws = csbr_find_aws();
    if (!$aws) {
        return ['ok' => false, 'ami_id' => null, 'name' => '', 'error' => 'AWS CLI not found on server.'];
    }

    $prefix = get_option('csbr_ami_prefix', '');
    if (!$prefix) {
        return ['ok' => false, 'ami_id' => null, 'name' => '', 'error' => 'No AMI name prefix configured.'];
    }

    $reboot  = (bool) get_option('csbr_ami_reboot', false);
    $region  = csbr_get_instance_region();
    $tz      = wp_timezone();
    $now     = new DateTime('now', $tz);

    $safe_prefix = preg_replace('/[^a-zA-Z0-9().\-\/_]/', '-', $prefix);
    $safe_prefix = preg_replace('/-{2,}/', '-', trim($safe_prefix, '-'));
    $ami_name    = substr($safe_prefix . '_' . $now->format('Ymd_Hi'), 0, 128);

    $description = 'CloudScale Backup AMI - ' . get_site_url() . ' - ' . $now->format('Y-m-d H:i T');
    $description = preg_replace('/[^\x20-\x7E]/', '', $description);

    $no_reboot   = $reboot ? '' : ' --no-reboot';
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $cmd = escapeshellarg($aws) . ' ec2 create-image'
         . ' --instance-id ' . escapeshellarg($instance_id)
         . ' --name '        . escapeshellarg($ami_name)
         . ' --description ' . escapeshellarg($description)
         . $no_reboot
         . $region_flag
         . ' --output json 2>&1';

    $out    = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $result = json_decode($out, true);
    $ami_id = $result['ImageId'] ?? null;

    $log = (array) get_option('csbr_ami_log', []);

    if ($ami_id) {
        $log[] = [
            'ok'          => true,
            'ami_id'      => $ami_id,
            'name'        => $ami_name,
            'instance_id' => $instance_id,
            'time'        => time(),
            'reboot'      => $reboot,
            'state'       => 'pending',
        ];
        csbr_ami_enforce_max($log, $aws, $region);

        if (!wp_next_scheduled('csbr_ami_poll')) {
            wp_schedule_single_event(time() + CSBR_AMI_POLL_INTERVAL, 'csbr_ami_poll');
        }

        return ['ok' => true, 'ami_id' => $ami_id, 'name' => $ami_name];
    } else {
        $log[] = [
            'ok'    => false,
            'name'  => $ami_name,
            'time'  => time(),
            'error' => substr($out, 0, 500),
        ];
        if (count($log) > 20) { $log = array_slice($log, -20); }
        update_option('csbr_ami_log', $log, false);

        return ['ok' => false, 'ami_id' => null, 'name' => $ami_name, 'error' => $out];
    }
}

add_action('wp_ajax_csbr_ami_status', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );

    $aws = csbr_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found.');
    }

    // Accept optional specific AMI ID for per-row refresh
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $specific_ami = isset($_POST['ami_id']) ? sanitize_text_field( wp_unslash( $_POST['ami_id'] ) ) : '';

    $log = (array) get_option('csbr_ami_log', []);

    if ($specific_ami) {
        // Look up the specific AMI
        $target = null;
        foreach ($log as $entry) {
            if (($entry['ami_id'] ?? '') === $specific_ami) { $target = $entry; break; }
        }
        if (!$target) {
            wp_send_json_error('AMI ' . $specific_ami . ' not found in log.');
        }
    } else {
        // Find last successful entry with an AMI ID
        $target = null;
        foreach (array_reverse($log) as $entry) {
            if (!empty($entry['ami_id'])) { $target = $entry; break; }
        }
    }

    if (!$target) {
        wp_send_json_error('No AMI found in log. Create one first.');
    }

    $region = csbr_get_instance_region();
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $cmd = escapeshellarg($aws) . ' ec2 describe-images'
         . ' --image-ids ' . escapeshellarg($target['ami_id'])
         . $region_flag
         . ' --query "Images[0].State"'
         . ' --output text 2>&1';

    $state = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

    if ($state && !str_contains($state, 'error') && !str_contains($state, 'Error')) {
        // Update the log entry state
        foreach ($log as &$e) {
            if (($e['ami_id'] ?? '') === $target['ami_id']) {
                $e['state'] = $state;
            }
        }
        unset($e);
        update_option('csbr_ami_log', $log, false);

        if ($specific_ami) {
            wp_send_json_success(['ami_id' => $target['ami_id'], 'name' => $target['name'] ?? '', 'state' => $state, 'golden' => ! empty( $target['golden'] ), 'tag' => $target['tag'] ?? '']);
        } else {
            wp_send_json_success($target['ami_id'] . ' (' . ($target['name'] ?? '') . ') — state: ' . $state);
        }
    } else {
        wp_send_json_error('Could not query AMI status: ' . $state);
    }
});

add_action('wp_ajax_csbr_deregister_ami', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );

    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $ami_id = sanitize_text_field( wp_unslash( $_POST['ami_id'] ?? '' ) );
    if (!$ami_id || !preg_match('/^ami-[a-f0-9]+$/', $ami_id)) {
        wp_send_json_error('Invalid AMI ID.');
    }

    $aws = csbr_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found.');
    }

    $region = csbr_get_instance_region();
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $cmd = escapeshellarg($aws) . ' ec2 deregister-image'
         . ' --image-id ' . escapeshellarg($ami_id)
         . $region_flag
         . ' 2>&1';

    $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

    // deregister-image returns empty output on success
    if ($out === '' || str_contains($out, '"return": true') || str_contains($out, 'Return')) {
        // Mark as pending_delete in log and schedule a check in 15 minutes
        $log = (array) get_option('csbr_ami_log', []);
        foreach ($log as &$entry) {
            if (($entry['ami_id'] ?? '') === $ami_id) {
                $entry['state'] = 'pending_delete';
                break;
            }
        }
        unset($entry);
        update_option('csbr_ami_log', $log, false);

        // Schedule a cron check in 15 minutes to confirm deletion and clean up
        if (!wp_next_scheduled('csbr_ami_delete_check', [$ami_id])) {
            wp_schedule_single_event(time() + 900, 'csbr_ami_delete_check', [$ami_id]);
        }

        wp_send_json_success('AMI ' . $ami_id . ' deregistered. Status will update automatically in 15 minutes.');
    } else {
        csbr_log('[CloudScale Backup & Restore] AMI deregister failed: ' . $out);
        wp_send_json_error('Deregister failed: ' . $out);
    }
});

// ============================================================
// Cron — Confirm AMI deletion 15 minutes after deregister
// ============================================================

/**
 * WP-Cron callback: verify an AMI has been deleted in AWS 15 minutes after deregistration.
 *
 * Removes the log entry if AWS confirms the image is gone; updates the state if it still exists.
 * Scheduled by the csbr_deregister_ami AJAX handler immediately after a successful deregister call.
 *
 * @since 3.2.54
 * @param string $ami_id The AWS AMI ID to check (e.g. 'ami-0abc1234').
 * @return void
 */
add_action('csbr_ami_delete_check', function (string $ami_id): void {
    $log = (array) get_option('csbr_ami_log', []);

    // Find the entry
    $entry_idx = null;
    foreach ($log as $i => $e) {
        if (($e['ami_id'] ?? '') === $ami_id) {
            $entry_idx = $i;
            break;
        }
    }
    if ($entry_idx === null) return; // already removed from log

    $aws = csbr_find_aws();
    if (!$aws) {
        csbr_log('[CloudScale Backup & Restore] AMI delete check: AWS CLI not found for ' . $ami_id);
        return;
    }

    $region      = csbr_get_instance_region();
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $cmd = escapeshellarg($aws) . ' ec2 describe-images --image-ids ' . escapeshellarg($ami_id) . $region_flag . ' --output json 2>&1';
    $out  = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $data = json_decode($out, true);

    if (empty($data['Images'])) {
        // Gone from AWS — remove from local log
        $log = array_values(array_filter($log, fn($e) => ($e['ami_id'] ?? '') !== $ami_id));
        csbr_log('[CloudScale Backup & Restore] AMI delete confirmed, removed from log: ' . $ami_id);
    } else {
        // Still exists — update state from AWS
        $log[$entry_idx]['state'] = $data['Images'][0]['State'] ?? 'unknown';
        csbr_log('[CloudScale Backup & Restore] AMI delete check: ' . $ami_id . ' state = ' . $log[$entry_idx]['state']);
    }

    update_option('csbr_ami_log', $log, false);
});

// ============================================================
// AJAX — Refresh all AMI states (one at a time, 2s gap between calls)
// ============================================================

add_action('wp_ajax_csbr_ami_refresh_all', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups

    $aws = csbr_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found.');
    }

    // Read directly from DB — bypasses object cache which may serve stale state
    // after csbr_ami_reset_deleted has just written new values
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bypasses object cache intentionally; post-reset read must be authoritative
    $raw_log = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'csbr_ami_log' ) );
    $log     = $raw_log ? (array) maybe_unserialize($raw_log) : []; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_maybe_unserialize -- value written only by this plugin

    $region      = csbr_get_instance_region();
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $results     = [];

    foreach ($log as &$entry) {
        if (empty($entry['ami_id']) || !preg_match('/^ami-[a-f0-9]+$/', $entry['ami_id'])) {
            continue;
        }

        $ami_id = $entry['ami_id'];

        $cmd = escapeshellarg($aws) . ' ec2 describe-images'
             . ' --image-ids ' . escapeshellarg($ami_id)
             . $region_flag
             . ' --query "Images[*].{ImageId:ImageId,State:State}"'
             . ' --output json 2>&1';

        $out    = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $images = json_decode($out, true);

        // Determine state: if AWS returned a valid image entry use its state,
        // otherwise the AMI is gone
        $new_state = 'deleted in AWS';
        if (is_array($images)) {
            foreach ($images as $img) {
                if (($img['ImageId'] ?? '') === $ami_id) {
                    $new_state = $img['State'] ?? 'unknown';
                    break;
                }
            }
        }

        csbr_log('[CloudScale Backup & Restore] Refresh All: ' . $ami_id . ' → ' . $new_state);

        // Always write the state — never skip based on previous value
        $entry['state'] = $new_state;
        if ($new_state !== 'deleted in AWS') {
            $entry['ok'] = true;
        }

        $results[] = [
            'ami_id' => $ami_id,
            'name'   => $entry['name'] ?? '',
            'state'  => $new_state,
        ];

        sleep(2);
    }
    unset($entry);

    // Auto-prune log entries confirmed deleted for more than 30 days
    $prune_cutoff = time() - (30 * DAY_IN_SECONDS);
    $log = array_values(array_filter($log, function($e) use ($prune_cutoff) {
        if (($e['state'] ?? '') !== 'deleted in AWS') return true;
        return (int)($e['time'] ?? 0) > $prune_cutoff;
    }));

    // Always write back — state is always refreshed from AWS
    update_option('csbr_ami_log', $log, false);

    wp_send_json_success(['results' => $results]);
});

// ============================================================
// AJAX — Reset all 'deleted in AWS' log entries back to 'pending' for re-query
// ============================================================

add_action('wp_ajax_csbr_ami_reset_deleted', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    $log     = (array) get_option('csbr_ami_log', []);
    $reset   = 0;
    foreach ($log as &$entry) {
        if (!empty($entry['ami_id']) && ($entry['state'] ?? '') === 'deleted in AWS') {
            $entry['state'] = 'pending';
            $entry['ok']    = true;
            $reset++;
        }
    }
    unset($entry);
    update_option('csbr_ami_log', $log, false);
    wp_send_json_success(['reset' => $reset]);
});

// ============================================================
// AJAX — Remove a log record (for AMIs already deleted in AWS)
// ============================================================

add_action('wp_ajax_csbr_ami_remove_record', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );

    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $ami_id = sanitize_text_field( wp_unslash( $_POST['ami_id'] ?? '' ) );
    if (!$ami_id || !preg_match('/^ami-[a-f0-9]+$/', $ami_id)) {
        wp_send_json_error('Invalid AMI ID.');
    }

    $log     = (array) get_option('csbr_ami_log', []);
    $updated = array_values(array_filter($log, fn($e) => ($e['ami_id'] ?? '') !== $ami_id));

    update_option('csbr_ami_log', $updated, false);
    wp_send_json_success('Record removed.');
});

add_action('wp_ajax_csbr_ami_remove_failed', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    if (!$name) {
        wp_send_json_error('No name provided.');
    }
    $log     = (array) get_option('csbr_ami_log', []);
    $updated = array_values(array_filter($log, fn($e) => ($e['name'] ?? '') !== $name || !empty($e['ami_id'])));
    if (count($updated) === count($log)) {
        wp_send_json_error('Record not found.');
    }
    update_option('csbr_ami_log', $updated, false);
    wp_send_json_success('Record removed.');
});

// ============================================================
// AJAX — Restore EC2 instance to an AMI snapshot (replace root volume)
// ============================================================

/**
 * AJAX: initiate an EC2 replace-root-volume-task from an AMI snapshot.
 *
 * @since 3.2.124
 * @return void Sends JSON success with task_id, or JSON error with message.
 */
add_action('wp_ajax_csbr_ami_restore', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $ami_id = sanitize_text_field( wp_unslash( $_POST['ami_id'] ?? '' ) );
    if ( ! preg_match( '/^ami-[a-f0-9]{8,17}$/', $ami_id ) ) {
        wp_send_json_error( 'Invalid AMI ID.' );
    }

    $aws = csbr_find_aws();
    if ( ! $aws ) {
        wp_send_json_error( 'AWS CLI not found on this server.' );
    }

    $instance_id = csbr_get_instance_id();
    if ( ! $instance_id ) {
        wp_send_json_error( 'EC2 instance ID not detected.' );
    }

    $region      = csbr_get_instance_region();
    $region_flag = $region ? ' --region ' . escapeshellarg( $region ) : '';

    $cmd = escapeshellarg( $aws ) . ' ec2 create-replace-root-volume-task'
         . ' --instance-id ' . escapeshellarg( $instance_id )
         . ' --image-id '    . escapeshellarg( $ami_id )
         . $region_flag
         . ' --output json 2>&1';

    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $output  = shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $decoded = json_decode( $output ?? '', true );

    if ( isset( $decoded['ReplaceRootVolumeTask']['ReplaceRootVolumeTaskId'] ) ) {
        $task_id = sanitize_text_field( $decoded['ReplaceRootVolumeTask']['ReplaceRootVolumeTaskId'] );
        csbr_log( '[CloudScale Backup & Restore] AMI restore task created: ' . $task_id . ' (AMI: ' . $ami_id . ')' );
        wp_send_json_success( [ 'task_id' => $task_id ] );
    } else {
        csbr_log( '[CloudScale Backup & Restore] AMI restore failed. Output: ' . substr( $output ?? '', 0, 300 ) );
        wp_send_json_error( is_string( $output ) ? trim( substr( $output, 0, 300 ) ) : 'No output from AWS CLI.' );
    }
});

// ============================================================
// AJAX — AMI tag and golden image management
// ============================================================

/**
 * AJAX: set or clear a tag on an AMI log entry.
 *
 * @since 3.2.124
 * @return void Sends JSON success with new tag value.
 */
add_action('wp_ajax_csbr_ami_set_tag', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $ami_id = sanitize_text_field( wp_unslash( $_POST['ami_id'] ?? '' ) );
    $tag    = sanitize_text_field( wp_unslash( $_POST['tag']    ?? '' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    if ( ! preg_match( '/^ami-[a-f0-9]{8,17}$/', $ami_id ) ) {
        wp_send_json_error( 'Invalid AMI ID.' );
    }
    $log   = (array) get_option( 'csbr_ami_log', [] );
    $found = false;
    foreach ( $log as &$entry ) {
        if ( ( $entry['ami_id'] ?? '' ) === $ami_id ) {
            $entry['tag'] = $tag;
            $found = true;
            break;
        }
    }
    unset( $entry );
    if ( ! $found ) { wp_send_json_error( 'AMI not found in log.' ); }
    update_option( 'csbr_ami_log', $log, false );
    wp_send_json_success( [ 'tag' => $tag ] );
});

/**
 * AJAX: toggle golden image status for an AMI log entry.
 *
 * Enforces a maximum of 4 golden images across all AMI entries.
 * Golden images are exempt from auto-pruning in csbr_ami_enforce_max().
 *
 * @since 3.2.124
 * @return void Sends JSON success with new golden bool.
 */
add_action('wp_ajax_csbr_ami_set_golden', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $ami_id = sanitize_text_field( wp_unslash( $_POST['ami_id'] ?? '' ) );
    if ( ! preg_match( '/^ami-[a-f0-9]{8,17}$/', $ami_id ) ) {
        wp_send_json_error( 'Invalid AMI ID.' );
    }
    $log = (array) get_option( 'csbr_ami_log', [] );
    $idx = null;
    foreach ( $log as $i => $entry ) {
        if ( ( $entry['ami_id'] ?? '' ) === $ami_id ) { $idx = $i; break; }
    }
    if ( $idx === null ) { wp_send_json_error( 'AMI not found in log.' ); }
    $currently = ! empty( $log[ $idx ]['golden'] );
    if ( ! $currently ) {
        $count = count( array_filter( $log, fn( $e ) => ! empty( $e['golden'] ) ) );
        if ( $count >= 4 ) {
            wp_send_json_error( 'Maximum 4 golden images. Remove one first.' );
        }
    }
    $log[ $idx ]['golden'] = ! $currently;
    update_option( 'csbr_ami_log', $log, false );
    wp_send_json_success( [ 'golden' => ! $currently ] );
});

// ============================================================
// AJAX — S3 history management
// ============================================================

/**
 * AJAX: refresh S3 history by querying the bucket via AWS CLI.
 *
 * @since 3.2.124
 * @return void Sends JSON success with array of file entries.
 */
// ============================================================
// AJAX — Google Drive history management
// ============================================================

/**
 * AJAX: refresh Google Drive history by querying the remote via rclone.
 *
 * @since 3.2.124
 * @return void Sends JSON success with array of file entries.
 */
add_action('wp_ajax_csbr_gdrive_refresh_history', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    if ( ! get_option( 'csbr_gdrive_remote', '' ) ) {
        wp_send_json_error( 'Google Drive not configured.' );
    }
    $gd_hist = csbr_gdrive_refresh_history();
    update_option( 'csbr_gdrive_remote_count', count( $gd_hist ), false );
    wp_send_json_success( [ 'files' => array_values( $gd_hist ), 'count' => count( $gd_hist ) ] );
});

/**
 * AJAX: set or clear a tag on a GDrive history entry.
 *
 * @since 3.2.124
 * @return void Sends JSON success with new tag value.
 */
add_action('wp_ajax_csbr_gdrive_set_tag', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    $tag      = sanitize_text_field( wp_unslash( $_POST['tag']      ?? '' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    if ( ! $filename ) { wp_send_json_error( 'No filename.' ); }
    $hist = (array) get_option( 'csbr_gdrive_history', [] );
    if ( ! isset( $hist[ $filename ] ) ) { wp_send_json_error( 'File not found in history.' ); }
    $hist[ $filename ]['tag'] = $tag;
    update_option( 'csbr_gdrive_history', $hist, false );
    wp_send_json_success( [ 'tag' => $tag ] );
});

/**
 * AJAX: toggle golden image status for a GDrive history entry.
 *
 * Enforces a maximum of 4 golden images (separate pool from AMI and S3).
 *
 * @since 3.2.124
 * @return void Sends JSON success with new golden bool.
 */
add_action('wp_ajax_csbr_gdrive_set_golden', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    if ( ! $filename ) { wp_send_json_error( 'No filename.' ); }
    $hist = (array) get_option( 'csbr_gdrive_history', [] );
    if ( ! isset( $hist[ $filename ] ) ) { wp_send_json_error( 'File not found in history.' ); }
    $currently = ! empty( $hist[ $filename ]['golden'] );
    if ( ! $currently ) {
        $count = count( array_filter( $hist, fn( $e ) => ! empty( $e['golden'] ) ) );
        if ( $count >= 4 ) {
            wp_send_json_error( 'Maximum 4 golden images. Remove one first.' );
        }
    }
    $hist[ $filename ]['golden'] = ! $currently;
    update_option( 'csbr_gdrive_history', $hist, false );
    wp_send_json_success( [ 'golden' => ! $currently ] );
});

/**
 * AJAX: delete a file from Google Drive and remove it from the local history.
 *
 * @since 3.2.124
 * @return void Sends JSON success or error.
 */
add_action('wp_ajax_csbr_gdrive_delete_remote', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    if ( ! $filename ) { wp_send_json_error( 'No filename.' ); }
    $remote = get_option( 'csbr_gdrive_remote', '' );
    if ( ! $remote ) { wp_send_json_error( 'Google Drive not configured.' ); }
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) { wp_send_json_error( 'rclone not found.' ); }
    $dest_path   = ltrim( get_option( 'csbr_gdrive_path', 'cloudscale-backups/' ), '/' );
    $remote_file = rtrim( $remote, ':' ) . ':' . $dest_path . $filename;
    $cmd         = escapeshellarg( $rclone ) . ' deletefile ' . escapeshellarg( $remote_file ) . ' 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( $out === '' ) {
        $hist = (array) get_option( 'csbr_gdrive_history', [] );
        unset( $hist[ $filename ] );
        update_option( 'csbr_gdrive_history', $hist, false );
        update_option( 'csbr_gdrive_remote_count', max( 0, (int) get_option( 'csbr_gdrive_remote_count', 0 ) - 1 ), false );
        csbr_log( '[CloudScale Backup & Restore] GDrive history: deleted ' . $filename );
        wp_send_json_success( 'Deleted.' );
    } else {
        wp_send_json_error( trim( substr( $out, 0, 200 ) ) );
    }
});

add_action('wp_ajax_csbr_s3_refresh_history', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    if ( ! get_option( 'csbr_s3_bucket', '' ) ) {
        wp_send_json_error( 'S3 not configured.' );
    }
    $s3_hist = csbr_s3_refresh_history();
    update_option( 'csbr_s3_remote_count', count( $s3_hist ), false );
    wp_send_json_success( [ 'files' => array_values( $s3_hist ), 'count' => count( $s3_hist ) ] );
});

/**
 * AJAX: set or clear a tag on an S3 history entry.
 *
 * @since 3.2.124
 * @return void Sends JSON success with new tag value.
 */
add_action('wp_ajax_csbr_s3_set_tag', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    $tag      = sanitize_text_field( wp_unslash( $_POST['tag']      ?? '' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    if ( ! $filename ) { wp_send_json_error( 'No filename.' ); }
    $hist = (array) get_option( 'csbr_s3_history', [] );
    if ( ! isset( $hist[ $filename ] ) ) { wp_send_json_error( 'File not found in history.' ); }
    $hist[ $filename ]['tag'] = $tag;
    update_option( 'csbr_s3_history', $hist, false );
    wp_send_json_success( [ 'tag' => $tag ] );
});

/**
 * AJAX: toggle golden image status for an S3 history entry.
 *
 * Enforces a maximum of 4 golden images across all S3 history entries
 * (separate pool from AMI golden images).
 *
 * @since 3.2.124
 * @return void Sends JSON success with new golden bool.
 */
add_action('wp_ajax_csbr_s3_set_golden', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    if ( ! $filename ) { wp_send_json_error( 'No filename.' ); }
    $hist = (array) get_option( 'csbr_s3_history', [] );
    if ( ! isset( $hist[ $filename ] ) ) { wp_send_json_error( 'File not found in history.' ); }
    $currently = ! empty( $hist[ $filename ]['golden'] );
    if ( ! $currently ) {
        $count = count( array_filter( $hist, fn( $e ) => ! empty( $e['golden'] ) ) );
        if ( $count >= 4 ) {
            wp_send_json_error( 'Maximum 4 golden images. Remove one first.' );
        }
    }
    $hist[ $filename ]['golden'] = ! $currently;
    update_option( 'csbr_s3_history', $hist, false );
    wp_send_json_success( [ 'golden' => ! $currently ] );
});

/**
 * AJAX: delete a file from S3 and remove it from the local history.
 *
 * @since 3.2.124
 * @return void Sends JSON success or error.
 */
add_action('wp_ajax_csbr_s3_delete_remote', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    if ( ! $filename ) { wp_send_json_error( 'No filename.' ); }
    $bucket = get_option( 'csbr_s3_bucket', '' );
    if ( ! $bucket ) { wp_send_json_error( 'S3 not configured.' ); }
    $aws = csbr_find_aws();
    if ( ! $aws ) { wp_send_json_error( 'AWS CLI not found.' ); }
    $prefix  = '/' . ltrim( get_option( 'csbr_s3_prefix', 'backups/' ), '/' );
    $s3_path = 's3://' . rtrim( $bucket, '/' ) . rtrim( $prefix, '/' ) . '/' . $filename;
    $cmd     = escapeshellarg( $aws ) . ' s3 rm ' . escapeshellarg( $s3_path ) . ' 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( $out === '' || str_starts_with( $out, 'delete:' ) ) {
        $hist = (array) get_option( 'csbr_s3_history', [] );
        unset( $hist[ $filename ] );
        update_option( 'csbr_s3_history', $hist, false );
        update_option( 'csbr_s3_remote_count', max( 0, (int) get_option( 'csbr_s3_remote_count', 0 ) - 1 ), false );
        csbr_log( '[CloudScale Backup & Restore] S3 history: deleted ' . $filename );
        wp_send_json_success( 'Deleted.' );
    } else {
        wp_send_json_error( trim( substr( $out, 0, 200 ) ) );
    }
});

/**
 * AJAX: pull a file from S3 to the local backup directory.
 *
 * After a successful pull the file will appear in the Backup History table
 * on the next page load.
 *
 * @since 3.2.124
 * @return void Sends JSON success or error.
 */
add_action('wp_ajax_csbr_s3_pull', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    csbr_ensure_backup_dir();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via check_ajax_referer() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    if ( ! $filename ) { wp_send_json_error( 'No filename.' ); }
    $bucket = get_option( 'csbr_s3_bucket', '' );
    if ( ! $bucket ) { wp_send_json_error( 'S3 not configured.' ); }
    $aws = csbr_find_aws();
    if ( ! $aws ) { wp_send_json_error( 'AWS CLI not found.' ); }
    $prefix     = '/' . ltrim( get_option( 'csbr_s3_prefix', 'backups/' ), '/' );
    $s3_path    = 's3://' . rtrim( $bucket, '/' ) . rtrim( $prefix, '/' ) . '/' . $filename;
    $local_path = CSBR_BACKUP_DIR . $filename;
    $cmd        = escapeshellarg( $aws ) . ' s3 cp ' . escapeshellarg( $s3_path ) . ' ' . escapeshellarg( $local_path ) . ' 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( ! file_exists( $local_path ) ) {
        wp_send_json_error( $out ?: 'Pull failed — file not found after download.' );
    }
    // Belt-and-braces path check (sanitize_file_name prevents traversal, but verify)
    $real_dir  = realpath( CSBR_BACKUP_DIR );
    $real_file = realpath( $local_path );
    if ( $real_dir && $real_file && strpos( $real_file, $real_dir ) !== 0 ) {
        wp_delete_file( $local_path );
        wp_send_json_error( 'Security check failed.' );
    }
    csbr_log( '[CloudScale Backup & Restore] S3 pull: downloaded ' . $filename );
    wp_send_json_success( 'Pulled to local backup folder. Reload to see it in Backup History.' );
});

add_action('wp_ajax_csbr_save_retention', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_ajax_referer() above; intval() used for integer field
    $r = max(1, min(9999, intval( wp_unslash( $_POST['retention'] ?? 8 ) )));
    update_option('csbr_retention', $r);
    $prefix = sanitize_key( wp_unslash( $_POST['backup_prefix'] ?? 'bkup' ) ) ?: 'bkup';
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    update_option('csbr_backup_prefix', $prefix);
    wp_send_json_success('Saved');
});

// ============================================================
// Download handler
// ============================================================

add_action('admin_post_csbr_download', function (): void {
    check_admin_referer('csbr_download');
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Forbidden', 'cloudscale-backup-restore' ) );
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- GET param sanitised immediately via sanitize_file_name()
    $file = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
    $path = CSBR_BACKUP_DIR . $file;

    if (!file_exists($path) || strpos(realpath($path), realpath(CSBR_BACKUP_DIR)) !== 0) {
        wp_die( esc_html__( 'File not found.', 'cloudscale-backup-restore' ) );
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace( [ "\r", "\n", '"', '\\' ], '', $file ) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache');
    readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming binary download requires readfile(); WP_Filesystem has no streaming equivalent
    exit;
});


/**
 * S3 download handler — streams a backup zip from S3 (or local cache) to the browser.
 *
 * Serves from CSBR_BACKUP_DIR if the file exists locally; otherwise pulls from S3
 * to a temporary path, streams it, then cleans up.
 *
 * @since 3.2.124
 * @return void
 */
add_action('admin_post_csbr_s3_download', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Forbidden', 'cloudscale-backup-restore' ) );
    }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitised immediately
    if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'csbr_nonce' ) ) {
        wp_die( esc_html__( 'Security check failed.', 'cloudscale-backup-restore' ) );
    }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitised immediately
    $filename = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
    if ( ! $filename ) {
        wp_die( esc_html__( 'No file specified.', 'cloudscale-backup-restore' ) );
    }
    $local   = CSBR_BACKUP_DIR . $filename;
    $cleanup = false;
    if ( ! file_exists( $local ) ) {
        // File not cached locally — pull from S3 to a temp path
        $bucket = get_option( 'csbr_s3_bucket', '' );
        $aws    = csbr_find_aws();
        if ( ! $bucket || ! $aws ) {
            wp_die( esc_html__( 'S3 not configured or AWS CLI not found.', 'cloudscale-backup-restore' ) );
        }
        $prefix  = '/' . ltrim( get_option( 'csbr_s3_prefix', 'backups/' ), '/' );
        $s3_path = 's3://' . rtrim( $bucket, '/' ) . rtrim( $prefix, '/' ) . '/' . $filename;
        $tmp     = get_temp_dir() . 'csbr_s3dl_' . md5( $filename . wp_generate_uuid4() ) . '.zip';
        $cmd     = escapeshellarg( $aws ) . ' s3 cp ' . escapeshellarg( $s3_path ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1';
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        if ( ! file_exists( $tmp ) ) {
            wp_die( esc_html__( 'Could not retrieve file from S3.', 'cloudscale-backup-restore' ) );
        }
        $local   = $tmp;
        $cleanup = true;
    }
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . str_replace( [ "\r", "\n", '"', '\\' ], '', $filename ) . '"' );
    header( 'Content-Length: ' . filesize( $local ) );
    header( 'Cache-Control: no-cache' );
    readfile( $local ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming binary download
    if ( $cleanup ) wp_delete_file( $local );
    exit;
});

/**
 * Google Drive download handler — streams a backup zip from Drive to the browser.
 *
 * Uses rclone copyto to pull the file to a temp path, streams it, then cleans up.
 *
 * @since 3.2.124
 * @return void
 */
add_action('admin_post_csbr_gdrive_download', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Forbidden', 'cloudscale-backup-restore' ) );
    }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitised immediately
    if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'csbr_nonce' ) ) {
        wp_die( esc_html__( 'Security check failed.', 'cloudscale-backup-restore' ) );
    }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitised immediately
    $filename = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
    if ( ! $filename ) {
        wp_die( esc_html__( 'No file specified.', 'cloudscale-backup-restore' ) );
    }
    $remote = get_option( 'csbr_gdrive_remote', '' );
    $rclone = csbr_find_rclone();
    if ( ! $remote || ! $rclone ) {
        wp_die( esc_html__( 'Google Drive not configured or rclone not found.', 'cloudscale-backup-restore' ) );
    }
    $dest_path   = ltrim( get_option( 'csbr_gdrive_path', 'cloudscale-backups/' ), '/' );
    $remote_file = rtrim( $remote, ':' ) . ':' . $dest_path . $filename;
    $tmp         = get_temp_dir() . 'csbr_gddl_' . md5( $filename . wp_generate_uuid4() ) . '.zip';
    $cmd         = escapeshellarg( $rclone ) . ' copyto ' . escapeshellarg( $remote_file ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( ! file_exists( $tmp ) ) {
        wp_die( esc_html__( 'Could not retrieve file from Google Drive.', 'cloudscale-backup-restore' ) );
    }
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . str_replace( [ "\r", "\n", '"', '\\' ], '', $filename ) . '"' );
    header( 'Content-Length: ' . filesize( $tmp ) );
    header( 'Cache-Control: no-cache' );
    readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming binary download
    wp_delete_file( $tmp );
    exit;
});
// ============================================================
// Quick backup from dashboard widget
// ============================================================

/**
 * Handle the dashboard widget "Create Local Backup Now" form POST.
 *
 * Runs a full backup (db + media + plugins + themes) and redirects
 * to the plugin admin page. Uses admin-post.php for correct POST handling.
 *
 * @since 3.2.1
 * @return void
 */
add_action( 'admin_post_csbr_quick_backup', function (): void {
    check_admin_referer( 'csbr_quick_backup', '_csbr_quick_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Forbidden', 'cloudscale-backup-restore' ) );
    }

    set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort( true );
    csbr_ensure_backup_dir();

    try {
        csbr_create_backup( true, true, true, true );
        csbr_enforce_retention();
    } catch ( Exception $e ) {
        csbr_log( '[CloudScale Backup & Restore] Quick backup from dashboard widget failed: ' . $e->getMessage() );
    }

    wp_safe_redirect( admin_url( 'tools.php?page=cloudscale-backup' ) );
    exit;
} );

// ============================================================
// Dashboard widget
// ============================================================

add_action('wp_dashboard_setup', function (): void {
    wp_add_dashboard_widget(
        'csbr_backup_status_widget',
        '&#128736; ' . esc_html__( 'CloudScale Backup Status', 'cloudscale-backup-restore' ),
        'csbr_render_dashboard_widget'
    );
});

/**
 * Render the Dashboard quick-backup widget.
 *
 * @since 1.0.0
 * @return void
 */
function csbr_render_dashboard_widget(): void {
    $backups    = csbr_list_backups();
    $count      = count($backups);
    $latest     = $backups[0] ?? null;
    $free_bytes = disk_free_space(CSBR_BACKUP_DIR);
    if ($free_bytes === false) $free_bytes = disk_free_space(ABSPATH);
    $settings_url = admin_url('tools.php?page=cloudscale-backup');

    // Time since last backup — use saved completion time (recorded after cloud syncs)
    // so it matches the cloud sync log times in the rows below. Falls back to filemtime
    // for backups created before this option existed.
    if ($latest) {
        $saved_time = (int) get_option( 'csbr_last_backup_time', 0 );
        $age_secs   = time() - ( $saved_time > 0 ? $saved_time : (int) $latest['mtime'] );
        if ($age_secs < 3600) {
            $age_label = round($age_secs / 60) . ' minutes ago';
        } elseif ($age_secs < 86400) {
            $h = floor($age_secs / 3600);
            $age_label = $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        } else {
            $d = floor($age_secs / 86400);
            $age_label = $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        // Traffic light for age
        if ($age_secs < 86400) {
            $age_color = '#2e7d32'; // green — within 24h
        } elseif ($age_secs < 86400 * 3) {
            $age_color = '#e65100'; // amber — 1-3 days
        } else {
            $age_color = '#c62828'; // red — over 3 days
        }
    } else {
        $age_label = 'Never';
        $age_color = '#c62828';
    }

    // Cloud sync age rows — only for configured providers
    $csbr_age = function( int $ts ): array {
        if ( $ts <= 0 ) return [ 'Never', '#c62828' ];
        $secs = time() - $ts;
        if ( $secs < 3600 ) {
            $label = round( $secs / 60 ) . ' min ago';
        } elseif ( $secs < 86400 ) {
            $h = floor( $secs / 3600 );
            $label = $h . ' hour' . ( $h === 1 ? '' : 's' ) . ' ago';
        } else {
            $d = floor( $secs / 86400 );
            $label = $d . ' day' . ( $d === 1 ? '' : 's' ) . ' ago';
        }
        $color = $secs < 86400 ? '#2e7d32' : ( $secs < 86400 * 3 ? '#e65100' : '#c62828' );
        return [ $label, $color ];
    };

    $cloud_rows = [];

    if ( get_option( 'csbr_s3_bucket', '' ) ) {
        $s3_log    = (array) get_option( 'csbr_s3_log', [] );
        $s3_ok     = array_filter( $s3_log, fn( $e ) => ! empty( $e['ok'] ) );
        $s3_last   = $s3_ok ? max( array_column( $s3_ok, 'time' ) ) : 0;
        $cloud_rows[] = [ '&#9729; S3 last sync', ...$csbr_age( (int) $s3_last ) ];
    }
    if ( get_option( 'csbr_gdrive_remote', '' ) ) {
        $gd_log   = (array) get_option( 'csbr_gdrive_log', [] );
        $gd_ok    = array_filter( $gd_log, fn( $e ) => ! empty( $e['ok'] ) );
        $gd_last  = $gd_ok ? max( array_column( $gd_ok, 'time' ) ) : 0;
        $cloud_rows[] = [ '&#128196; Google Drive last sync', ...$csbr_age( (int) $gd_last ) ];
    }
    if ( get_option( 'csbr_dropbox_remote', '' ) ) {
        $db_log   = (array) get_option( 'csbr_dropbox_log', [] );
        $db_ok    = array_filter( $db_log, fn( $e ) => ! empty( $e['ok'] ) );
        $db_last  = $db_ok ? max( array_column( $db_ok, 'time' ) ) : 0;
        $cloud_rows[] = [ '&#128194; Dropbox last sync', ...$csbr_age( (int) $db_last ) ];
    }
    if ( get_option( 'csbr_onedrive_remote', '' ) || get_option( 'csbr_onedrive_sync_enabled', false ) || get_option( 'csbr_onedrive_path', null ) !== null ) {
        $od_log   = (array) get_option( 'csbr_onedrive_log', [] );
        $od_ok    = array_filter( $od_log, fn( $e ) => ! empty( $e['ok'] ) );
        $od_last  = $od_ok ? max( array_column( $od_ok, 'time' ) ) : 0;
        $cloud_rows[] = [ '&#128462; OneDrive last sync', ...$csbr_age( (int) $od_last ) ];
    }
    if ( get_option( 'csbr_ami_prefix', '' ) ) {
        $ami_log  = (array) get_option( 'csbr_ami_log', [] );
        $ami_ok   = array_filter( $ami_log, fn( $e ) => ! empty( $e['ok'] ) && ! empty( $e['ami_id'] ) );
        $ami_last = $ami_ok ? max( array_column( $ami_ok, 'time' ) ) : 0;
        $cloud_rows[] = [ '&#128247; AMI last snapshot', ...$csbr_age( (int) $ami_last ) ];
    }

    $widget_style = 'margin: -12px -12px 0 -12px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;';
    $stats_style  = 'padding: 14px 16px 6px 16px;';
    $row_style    = 'display:flex; justify-content:space-between; align-items:center; padding: 7px 0; border-bottom: 1px solid #f0f0f0;';
    $label_style  = 'color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em;';
    $value_style  = 'font-size: 14px; font-weight: 700; color: #1d2327;';
    $btn_base     = 'display:block; width:100%; padding:11px 0; text-align:center; font-size:13px; font-weight:700; color:#fff; text-decoration:none; border:none; cursor:pointer; letter-spacing:0.02em;';
    ?>
    <div style="<?php echo esc_attr( $widget_style ); ?>">
        <div style="<?php echo esc_attr( $stats_style ); ?>">

            <div style="<?php echo esc_attr( $row_style ); ?>">
                <span style="<?php echo esc_attr( $label_style ); ?>"><?php esc_html_e( 'Last backup', 'cloudscale-backup-restore' ); ?></span>
                <span style="<?php echo esc_attr( $value_style . ' color:' . $age_color ); ?>">
                    <?php echo esc_html($age_label); ?>
                </span>
            </div>

            <div style="<?php echo esc_attr( $row_style ); ?>">
                <span style="<?php echo esc_attr( $label_style ); ?>"><?php esc_html_e( 'Last backup size', 'cloudscale-backup-restore' ); ?></span>
                <span style="<?php echo esc_attr( $value_style ); ?>">
                    <?php echo $latest ? esc_html(csbr_format_size((int)$latest['size'])) : '—'; ?>
                </span>
            </div>

            <div style="<?php echo esc_attr( $row_style ); ?>">
                <span style="<?php echo esc_attr( $label_style ); ?>"><?php esc_html_e( 'Backups stored', 'cloudscale-backup-restore' ); ?></span>
                <span style="<?php echo esc_attr( $value_style ); ?>">
                    <?php
                    $retention = (int) get_option('csbr_retention', 8);
                    echo esc_html($count . ' / ' . $retention);
                    ?>
                </span>
            </div>

            <div style="<?php echo esc_attr( $row_style . ( empty( $cloud_rows ) ? ' border-bottom:none;' : '' ) ); ?>">
                <span style="<?php echo esc_attr( $label_style ); ?>"><?php esc_html_e( 'Free disk space', 'cloudscale-backup-restore' ); ?></span>
                <span style="<?php echo esc_attr( $value_style ); ?>">
                    <?php echo $free_bytes !== false ? esc_html(csbr_format_size((int)$free_bytes)) : '—'; ?>
                </span>
            </div>

            <?php
            $cloud_pairs = array_chunk( $cloud_rows, 2 );
            $last_pair   = count( $cloud_pairs ) - 1;
            foreach ( $cloud_pairs as $pi => $pair ):
                $is_last = ( $pi === $last_pair );
            ?>
            <div style="display:flex; gap:8px; padding:7px 0; <?php echo $is_last ? 'border-bottom:none;' : 'border-bottom:1px solid #f0f0f0;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded string literals, not user input ?>">
                <?php foreach ( $pair as $cr ): ?>
                <div style="flex:1; min-width:0;">
                    <span style="<?php echo esc_attr( $label_style ); ?> display:block; margin-bottom:1px;"><?php echo wp_kses( $cr[0], [] ); ?></span>
                    <span style="<?php echo esc_attr( $value_style ); ?> font-size:13px; color:<?php echo esc_attr( $cr[2] ); ?>;"><?php echo esc_html( $cr[1] ); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

        </div>

        <div style="padding: 4px 12px 12px 12px;">
            <a href="<?php echo esc_url($settings_url); ?>"
               id="cs-widget-main-btn"
               style="<?php echo esc_attr( $btn_base . ' background: linear-gradient(135deg, #1976d2 0%, #0d47a1 100%); border-radius: 8px; transition: background 0.15s ease, box-shadow 0.15s ease, letter-spacing 0.15s ease;' ); ?>"
               onmouseover="this.style.background='linear-gradient(135deg,#2196f3 0%,#1565c0 100%)';this.style.boxShadow='0 8px 24px rgba(13,71,161,0.55)';this.style.letterSpacing='0.06em';"
               onmouseout="this.style.background='linear-gradient(135deg,#1976d2 0%,#0d47a1 100%)';this.style.boxShadow='';this.style.letterSpacing='0.02em';">
                &#128736; <?php esc_html_e( 'CloudScale Backup & Restore', 'cloudscale-backup-restore' ); ?>
            </a>
        </div>
    </div>
    <?php
}

// ============================================================
// Maintenance mode
// ============================================================

/**
 * Enable WordPress maintenance mode by writing the .maintenance file.
 *
 * @since 1.0.0
 * @return void
 */
function csbr_maintenance_on(): void {
    $php = '<?php $upgrading = ' . time() . '; ?>';
    file_put_contents(CSBR_MAINT_FILE, $php); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing .maintenance file requires direct filesystem access; WP Filesystem API unavailable at restore time
}

/**
 * Disable WordPress maintenance mode by deleting the .maintenance file.
 *
 * @since 1.0.0
 * @return void
 */
function csbr_maintenance_off(): void {
    if (file_exists(CSBR_MAINT_FILE)) {
        wp_delete_file(CSBR_MAINT_FILE);
    }
}

// ============================================================
// Core — Create backup
// ============================================================

/**
 * Create a backup zip containing the requested components.
 *
 * @since 1.0.0
 * @param bool $include_db        Include a full database dump.
 * @param bool $include_media     Include wp-content/uploads/.
 * @param bool $include_plugins   Include wp-content/plugins/.
 * @param bool $include_themes    Include wp-content/themes/.
 * @param bool $include_mu        Include wp-content/mu-plugins/.
 * @param bool $include_languages Include wp-content/languages/.
 * @param bool $include_dropins   Include wp-content/ drop-in PHP files.
 * @param bool $include_htaccess  Include the root .htaccess file.
 * @param bool $include_wpconfig  Include wp-config.php.
 * @return void
 */
function csbr_create_backup(
    bool $include_db, bool $include_media,
    bool $include_plugins    = false, bool $include_themes    = false,
    bool $include_mu         = false, bool $include_languages = false,
    bool $include_dropins    = false, bool $include_htaccess  = false,
    bool $include_wpconfig   = false
): string {
    // Build single-char type code: f=full, F=full+other, d=db, m=media, p=plugins, t=themes
    // For custom combos, concatenate initials: e.g. "dm"=db+media, "dmp"=db+media+plugins
    $core_all = $include_db && $include_media && $include_plugins && $include_themes;
    $has_other = $include_mu || $include_languages || $include_dropins || $include_htaccess || $include_wpconfig;
    if ($core_all && !$has_other) {
        $tcode = 'f';
    } elseif ($core_all && $has_other) {
        $tcode = 'F';
    } else {
        $tcode = '';
        if ($include_db)        $tcode .= 'd';
        if ($include_media)     $tcode .= 'm';
        if ($include_plugins)   $tcode .= 'p';
        if ($include_themes)    $tcode .= 't';
        if ($include_mu)        $tcode .= 'u';
        if ($include_languages) $tcode .= 'l';
        if ($include_dropins)   $tcode .= 'o';
        if ($include_htaccess)    $tcode .= 'h';
        if ($include_wpconfig)    $tcode .= 'c';
    }

    // Increment global sequence number, skip any that already exist on disk
    $prefix = sanitize_key(get_option('csbr_backup_prefix', 'bkup')) ?: 'bkup';
    $seq = (int) get_option('csbr_backup_seq', 0);
    do {
        $seq++;
        $filename = $prefix . '_' . $tcode . $seq . '.zip';
    } while (file_exists(CSBR_BACKUP_DIR . $filename));
    update_option('csbr_backup_seq', $seq, false);
    $zip_path = CSBR_BACKUP_DIR . $filename;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Cannot create zip at: ' . $zip_path); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception caught and message passed to wp_send_json_error()
    }

    if ($include_db) {
        $zip->addFromString('database.sql', csbr_dump_database());
    }

    if ($include_media) {
        csbr_add_dir_to_zip($zip, wp_upload_dir()['basedir'], 'uploads');
    }

    if ($include_plugins) {
        csbr_add_dir_to_zip($zip, WP_PLUGIN_DIR, 'plugins');
    }

    if ($include_themes) {
        csbr_add_dir_to_zip($zip, get_theme_root(), 'themes');
    }

    if ($include_mu) {
        $mu_path = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        if (is_dir($mu_path)) csbr_add_dir_to_zip($zip, $mu_path, 'mu-plugins');
    }

    if ($include_languages) {
        $lang_path = WP_CONTENT_DIR . '/languages';
        if (is_dir($lang_path)) csbr_add_dir_to_zip($zip, $lang_path, 'languages');
    }

    if ($include_dropins) {
        foreach (glob(WP_CONTENT_DIR . '/*.php') ?: [] as $f) {
            $zip->addFile($f, 'dropins/' . basename($f));
        }
    }

    if ($include_htaccess) {
        $ht = ABSPATH . '.htaccess';
        if (file_exists($ht)) $zip->addFile($ht, '.htaccess');
    }

    if ($include_wpconfig) {
        $wpc = ABSPATH . 'wp-config.php';
        if (file_exists($wpc)) $zip->addFile($wpc, 'wp-config.php');
    }

    $enc_pwd = (string) get_option( 'csbr_encrypt_password', '' );
    $zip->addFromString('backup-meta.json', json_encode([
        'plugin_version'    => CSBR_VERSION,
        'created'           => gmdate('c'),
        'wp_version'        => get_bloginfo('version'),
        'site_url'          => get_site_url(),
        'table_prefix'      => $GLOBALS['wpdb']->prefix,
        'include_db'        => $include_db,
        'include_media'     => $include_media,
        'include_plugins'   => $include_plugins,
        'include_themes'    => $include_themes,
        'include_mu'        => $include_mu,
        'include_languages' => $include_languages,
        'include_dropins'   => $include_dropins,
        'include_htaccess'    => $include_htaccess,
        'include_wpconfig'    => $include_wpconfig,
        'encrypted'           => $enc_pwd !== '',
    ], JSON_PRETTY_PRINT));

    if ( $enc_pwd !== '' && defined( 'ZipArchive::EM_AES_256' ) ) {
        $zip->setPassword( $enc_pwd );
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $zip->setEncryptionIndex( $i, ZipArchive::EM_AES_256 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- intentional AES-256 encryption of backup data
        }
    }

    $zip->close();

    // Cloud sync — results stored in globals for AJAX handler to include in response
    $GLOBALS['csbr_last_s3_result']       = csbr_sync_to_s3( CSBR_BACKUP_DIR . $filename );
    $GLOBALS['csbr_last_gdrive_result']   = csbr_sync_to_gdrive( CSBR_BACKUP_DIR . $filename );
    $GLOBALS['csbr_last_dropbox_result']  = csbr_sync_to_dropbox( CSBR_BACKUP_DIR . $filename );
    $GLOBALS['csbr_last_onedrive_result'] = csbr_sync_to_onedrive( CSBR_BACKUP_DIR . $filename );

    return $filename;
}

// ============================================================
// S3 sync
// ============================================================

// ============================================================
// EC2 metadata helpers
// ============================================================

/**
 * Fetch an IMDSv2 session token from the EC2 instance metadata service.
 *
 * Returns an empty string when not running on EC2.
 *
 * @since 2.74.0
 * @return string IMDSv2 token, or empty string on failure.
 */
function csbr_get_imds_token(): string {
    $response = wp_remote_request( 'http://169.254.169.254/latest/api/token', [
        'method'    => 'PUT',
        'timeout'   => 2,
        'headers'   => [ 'X-aws-ec2-metadata-token-ttl-seconds' => '60' ],
        'sslverify' => false,
    ] );
    if ( is_wp_error( $response ) ) return '';
    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) return '';
    return wp_remote_retrieve_body( $response );
}

/**
 * Fetch a value from the EC2 instance metadata service (IMDS).
 *
 * Tries IMDSv2 first and falls back to IMDSv1 if the token request fails.
 *
 * @since 2.74.0
 * @param string $path IMDS path, e.g. 'latest/meta-data/instance-id'.
 * @return string Metadata value, or empty string on failure.
 */
function csbr_imds_get(string $path): string {
    $token    = csbr_get_imds_token();
    $headers  = $token ? [ 'X-aws-ec2-metadata-token' => $token ] : [];
    $response = wp_remote_get( 'http://169.254.169.254' . $path, [
        'timeout'   => 2,
        'headers'   => $headers,
        'sslverify' => false,
    ] );
    if ( is_wp_error( $response ) ) return '';
    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) return '';
    return trim( wp_remote_retrieve_body( $response ) );
}

/**
 * Return the EC2 instance ID, or an empty string when not running on EC2.
 *
 * Result is cached in a static variable for the request lifetime.
 * Also checked against a one-hour WordPress transient to avoid repeated IMDS calls.
 *
 * @since 2.74.0
 * @return string Instance ID (e.g. 'i-0abc123def456') or empty string.
 */
function csbr_get_instance_id(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $cached = csbr_imds_get('/latest/meta-data/instance-id');
    } catch (\Throwable $e) {
        $cached = '';
    }

    return $cached;
}

/**
 * Return the AWS region of the running EC2 instance, or an empty string.
 *
 * Respects the csbr_ami_region_override option. Result cached per request and
 * in a one-hour WordPress transient.
 *
 * @since 2.74.0
 * @return string Region code (e.g. 'us-east-1') or empty string.
 */
function csbr_get_instance_region(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    // Manual override always wins — avoids IMDS dependency entirely
    $override = get_option('csbr_ami_region_override', '');
    if ($override) {
        $cached = $override;
        return $cached;
    }

    try {
        // Availability zone is e.g. "af-south-1a" — strip the trailing letter
        $az = csbr_imds_get('/latest/meta-data/placement/availability-zone');
        $cached = $az ? preg_replace('/[a-z]$/', '', $az) : '';
    } catch (\Throwable $e) {
        $cached = '';
    }

    return $cached;
}

/**
 * Return the absolute path to the AWS CLI binary, or an empty string if not found.
 *
 * @since 2.74.0
 * @return string Absolute path to 'aws', e.g. '/usr/local/bin/aws', or ''.
 */
/**
 * Delete old backup zips from the configured S3 bucket, keeping only the most recent N.
 *
 * Uses the same retention count as local backups (csbr_retention option).
 *
 * @since 3.2.124
 * @return void
 */
function csbr_enforce_s3_retention(): void {
    if (!get_option('csbr_s3_sync_enabled', true)) return;
    $bucket = get_option('csbr_s3_bucket', '');
    if (!$bucket) return;

    $aws = csbr_find_aws();
    if (!$aws) return;

    $retention = max(1, intval(get_option('csbr_ami_max', 10)));
    $prefix    = '/' . ltrim(get_option('csbr_s3_prefix', 'backups/'), '/');
    $s3_base   = 's3://' . rtrim($bucket, '/') . rtrim($prefix, '/') . '/';
    $cmd = escapeshellarg($aws) . ' s3 ls ' . escapeshellarg($s3_base) . ' 2>&1';
    $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if (!$out) return;

    $files = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if (!$line) continue;
        // Format: "2026-03-18 03:30:00     12345 bkup_f44.zip"
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+\d+\s+(.+\.zip)$/', $line, $m)) {
            $files[] = ['date' => $m[1], 'name' => $m[2]];
        }
    }

    // Store the current remote count before any deletions
    // Golden images are never pruned and do not count towards the quota.
    $s3_hist   = (array) get_option( 'csbr_s3_history', [] );
    $pruneable = array_values( array_filter( $files, fn( $f ) => empty( $s3_hist[ $f['name'] ]['golden'] ) ) );
    $remote_count = count( $files ); // total (including goldens) for display

    if ( count( $pruneable ) <= $retention ) {
        update_option('csbr_s3_remote_count', $remote_count, false);
        return;
    }

    usort($pruneable, fn($a, $b) => strcmp($a['date'], $b['date'])); // oldest non-golden first
    $to_delete = array_slice($pruneable, 0, count($pruneable) - $retention);

    foreach ($to_delete as $file) {
        $cmd     = escapeshellarg($aws) . ' s3 rm ' . escapeshellarg($s3_base . $file['name']) . ' 2>&1';
        $del_out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        // s3 rm prints "delete: s3://..." on success; anything else is an error
        if ($del_out === '' || str_starts_with($del_out, 'delete:')) {
            $remote_count--;
            csbr_log('[CloudScale Backup & Restore] S3 retention: deleted ' . $file['name']);
        } else {
            csbr_log('[CloudScale Backup & Restore] S3 retention WARNING: failed to delete ' . $file['name'] . ' — ' . $del_out);
        }
    }

    update_option('csbr_s3_remote_count', $remote_count, false);
}

/**
 * Delete old backup zips from the configured Google Drive remote, keeping only the most recent N.
 *
 * Uses the same retention count as local backups (csbr_retention option).
 *
 * @since 3.2.124
 * @return void
 */
function csbr_enforce_gdrive_retention(): void {
    if (!get_option('csbr_gdrive_sync_enabled', true)) return;
    $remote = get_option('csbr_gdrive_remote', '');
    if (!$remote) return;

    $rclone = csbr_find_rclone();
    if (!$rclone) return;

    $retention   = max(1, intval(get_option('csbr_ami_max', 10)));
    $dest_path   = ltrim(get_option('csbr_gdrive_path', 'cloudscale-backups/'), '/');
    $remote_path = rtrim($remote, ':') . ':' . $dest_path;

    $cmd  = escapeshellarg($rclone) . ' lsjson ' . escapeshellarg($remote_path) . ' --files-only 2>&1';
    $out  = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if (!$out) return;

    $data = json_decode($out, true);
    if (!is_array($data)) return;

    $files = array_values(array_filter($data, fn($f) => str_ends_with($f['Name'] ?? '', '.zip')));

    // Golden images are never pruned and do not count towards the quota.
    $gdrive_hist = (array) get_option( 'csbr_gdrive_history', [] );
    $pruneable   = array_values( array_filter( $files, fn( $f ) => empty( $gdrive_hist[ $f['Name'] ?? '' ]['golden'] ) ) );
    $remote_count = count( $files ); // total (including goldens) for display

    if ( count( $pruneable ) <= $retention ) {
        update_option('csbr_gdrive_remote_count', $remote_count, false);
        return;
    }

    usort($pruneable, fn($a, $b) => strcmp($a['ModTime'] ?? '', $b['ModTime'] ?? '')); // oldest non-golden first
    $to_delete = array_slice($pruneable, 0, count($pruneable) - $retention);

    foreach ($to_delete as $file) {
        $file_path = $remote_path . ($file['Name'] ?? '');
        $cmd       = escapeshellarg($rclone) . ' delete ' . escapeshellarg($file_path) . ' 2>&1';
        $del_out   = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        // rclone delete prints nothing on success; any output indicates an error
        if ($del_out === '') {
            $remote_count--;
            csbr_log('[CloudScale Backup & Restore] GDrive retention: deleted ' . ($file['Name'] ?? ''));
        } else {
            csbr_log('[CloudScale Backup & Restore] GDrive retention WARNING: failed to delete ' . ($file['Name'] ?? '') . ' — ' . $del_out);
        }
    }

    update_option('csbr_gdrive_remote_count', $remote_count, false);
}


// ============================================================
// GDrive history — query remote and merge with stored tags/golden
// ============================================================

/**
 * Refresh the Google Drive backup history by querying the configured remote.
 *
 * Runs `rclone lsjson`, parses Name/Size/ModTime for each .zip, and merges
 * the result with the existing csbr_gdrive_history option (preserving tag and
 * golden fields). Entries no longer on Drive are dropped.
 *
 * @since 3.2.124
 * @return array Updated history keyed by filename, or empty array if not configured.
 */
function csbr_gdrive_refresh_history(): array {
    $remote = get_option( 'csbr_gdrive_remote', '' );
    if ( ! $remote ) return [];
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) return [];

    $dest_path   = ltrim( get_option( 'csbr_gdrive_path', 'cloudscale-backups/' ), '/' );
    $remote_path = rtrim( $remote, ':' ) . ':' . $dest_path;
    $cmd         = escapeshellarg( $rclone ) . ' lsjson ' . escapeshellarg( $remote_path ) . ' --files-only 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out  = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $data = json_decode( $out ?: '[]', true );
    if ( ! is_array( $data ) ) return [];

    $existing = (array) get_option( 'csbr_gdrive_history', [] );
    $found    = [];

    foreach ( $data as $f ) {
        $name = $f['Name'] ?? '';
        if ( ! str_ends_with( $name, '.zip' ) ) continue;
        $size = (int) ( $f['Size']    ?? 0 );
        $mod  = $f['ModTime'] ?? '';
        $ts   = $mod ? (int) strtotime( $mod ) : 0;
        $found[ $name ] = [
            'name'       => $name,
            'size_bytes' => $size,
            'size_fmt'   => csbr_format_size( $size ),
            'time'       => $ts,
            'tag'        => $existing[ $name ]['tag']    ?? '',
            'golden'     => $existing[ $name ]['golden'] ?? false,
            'date_fmt'   => $ts ? wp_date( 'j M Y H:i', $ts ) : '—',
        ];
    }

    // Newest first for display
    uasort( $found, fn( $a, $b ) => ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ) );
    update_option( 'csbr_gdrive_history', $found, false );
    return $found;
}
function csbr_find_rclone(): string {
    $candidates = [
        trim((string) shell_exec('which rclone 2>/dev/null')), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        '/usr/local/bin/rclone',
        '/usr/bin/rclone',
        '/snap/bin/rclone',
        '/home/' . get_current_user() . '/.local/bin/rclone',
    ];
    foreach ($candidates as $path) {
        if ($path && file_exists($path) && is_executable($path)) {
            return $path;
        }
    }
    return '';
}

/**
 * Query free bytes available on an rclone remote via `rclone about --json`.
 * Returns null if the remote does not report quota info or if rclone is unavailable.
 *
 * @since 3.2.251
 * @param string $remote rclone remote name (with or without trailing colon).
 * @return int|null Free bytes, or null if not determinable.
 */
function csbr_rclone_free_bytes( string $remote ): ?int {
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) return null;
    $r   = escapeshellarg( rtrim( $remote, ':' ) . ':' );
    $cmd = 'timeout 15 ' . escapeshellarg( $rclone ) . " about {$r} --json 2>/dev/null";
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    if ( ! $out ) return null;
    $data = json_decode( $out, true );
    if ( ! is_array( $data ) ) return null;
    $total = isset( $data['total'] ) ? (int) $data['total'] : null;
    $used  = isset( $data['used'] )  ? (int) $data['used']  : null;
    $free  = isset( $data['free'] )  ? (int) $data['free']  : null;
    // Prefer total-used over reported free: Dropbox over-quota returns INT64_MAX as free
    // instead of a real value, which would silently bypass the space check.
    if ( $total !== null && $used !== null ) {
        return max( 0, $total - $used );
    }
    return $free;
}

/**
 * Upload a local backup file to Google Drive using rclone.
 *
 * @since 3.2.124
 * @param string $local_path Absolute filesystem path to the backup zip.
 * @return array{ok: bool, dest: string, error?: string, skipped?: bool} Result array.
 */
function csbr_sync_to_gdrive(string $local_path): array {
    if (!get_option('csbr_gdrive_sync_enabled', true)) return ['skipped' => true];
    $remote = get_option('csbr_gdrive_remote', '');
    if (!$remote) return ['skipped' => true];

    $rclone = csbr_find_rclone();
    if (!$rclone) {
        return ['ok' => false, 'error' => 'rclone not found. Install rclone and configure a Google Drive remote.'];
    }

    $dest_path = ltrim(get_option('csbr_gdrive_path', 'cloudscale-backups/'), '/');
    $dest      = rtrim($remote, ':') . ':' . $dest_path;
    $escaped   = escapeshellarg($local_path);
    $edest     = escapeshellarg($dest);

    // Space pre-check: abort before uploading if Google Drive has less free space than the file.
    $file_size = file_exists( $local_path ) ? (int) filesize( $local_path ) : 0;
    if ( $file_size > 0 ) {
        $free = csbr_rclone_free_bytes( $remote );
        if ( $free !== null && $free < $file_size ) {
            $free_mb = round( $free / 1048576, 1 );
            $need_mb = round( $file_size / 1048576, 1 );
            $err     = "Not enough space on Google Drive: {$free_mb} MB free, backup needs {$need_mb} MB.";
            csbr_log( '[CloudScale Backup & Restore] GDrive sync skipped — ' . $err );
            return [ 'ok' => false, 'dest' => $dest, 'error' => $err ];
        }
    }

    $cmd = escapeshellarg($rclone) . " copy --timeout 5m --contimeout 30s {$escaped} {$edest} 2>&1";
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

    // Space error: free oldest local backup and retry once
    if ($out && csbr_is_space_error($out)) {
        csbr_free_local_space('Google Drive', basename($local_path));
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    }

    $filename = basename($local_path);
    $log      = (array) get_option('csbr_gdrive_log', []);

    if ($out) {
        csbr_log('[CloudScale Backup & Restore] GDrive sync error: ' . $out);
        $result       = ['ok' => false, 'dest' => $dest, 'error' => $out];
        $log[$filename] = ['ok' => false, 'time' => time(), 'dest' => $dest, 'error' => $out];
    } else {
        csbr_log('[CloudScale Backup & Restore] GDrive sync OK: ' . $dest);
        $result       = ['ok' => true, 'dest' => $dest];
        $log[$filename] = ['ok' => true, 'time' => time(), 'dest' => $dest];
        csbr_enforce_gdrive_retention();
    }

    // Prune log entries for files that no longer exist
    $existing = array_map('basename', glob(CSBR_BACKUP_DIR . '*.zip') ?: []);
    foreach (array_keys($log) as $k) {
        if (!in_array($k, $existing, true)) unset($log[$k]);
    }
    update_option('csbr_gdrive_log', $log, false);

    return $result;
}

/**
 * Upload a local backup file to Dropbox using rclone.
 *
 * @since 3.2.83
 * @param string $local_path Absolute filesystem path to the backup zip.
 * @return array{ok: bool, dest: string, error?: string, skipped?: bool} Result array.
 */
function csbr_sync_to_dropbox( string $local_path ): array {
    if ( ! get_option( 'csbr_dropbox_sync_enabled', false ) ) return [ 'skipped' => true ];
    $remote = get_option( 'csbr_dropbox_remote', '' );
    if ( ! $remote ) return [ 'skipped' => true ];

    $rclone = csbr_find_rclone();
    if ( ! $rclone ) {
        return [ 'ok' => false, 'error' => 'rclone not found. Install rclone and configure a Dropbox remote.' ];
    }

    $dest_path = ltrim( get_option( 'csbr_dropbox_path', 'cloudscale-backups/' ), '/' );
    $dest      = rtrim( $remote, ':' ) . ':' . $dest_path;
    $escaped   = escapeshellarg( $local_path );
    $edest     = escapeshellarg( $dest );

    // Space pre-check: abort before uploading if Dropbox has less free space than the file.
    $file_size = file_exists( $local_path ) ? (int) filesize( $local_path ) : 0;
    if ( $file_size > 0 ) {
        $free = csbr_rclone_free_bytes( $remote );
        if ( $free !== null && $free < $file_size ) {
            $free_mb = round( $free / 1048576, 1 );
            $need_mb = round( $file_size / 1048576, 1 );
            $err     = "Not enough space on Dropbox: {$free_mb} MB free, backup needs {$need_mb} MB.";
            csbr_log( '[CloudScale Backup & Restore] Dropbox sync skipped — ' . $err );
            return [ 'ok' => false, 'dest' => $dest, 'error' => $err ];
        }
    }

    $cmd = escapeshellarg( $rclone ) . " copy --dropbox-batch-mode off --timeout 5m --contimeout 30s {$escaped} {$edest} 2>&1";
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

    // Space error: free oldest local backup and retry once
    if ( $out && csbr_is_space_error( $out ) ) {
        csbr_free_local_space( 'Dropbox', basename( $local_path ) );
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    }

    $filename = basename( $local_path );
    $log      = (array) get_option( 'csbr_dropbox_log', [] );

    if ( $out ) {
        csbr_log( '[CloudScale Backup & Restore] Dropbox sync error: ' . $out );
        $result         = [ 'ok' => false, 'dest' => $dest, 'error' => $out ];
        $log[$filename] = [ 'ok' => false, 'time' => time(), 'dest' => $dest, 'error' => $out ];
    } else {
        csbr_log( '[CloudScale Backup & Restore] Dropbox sync OK: ' . $dest );
        $result         = [ 'ok' => true, 'dest' => $dest ];
        $log[$filename] = [ 'ok' => true, 'time' => time(), 'dest' => $dest ];
        csbr_enforce_dropbox_retention();
    }

    $existing = array_map( 'basename', glob( CSBR_BACKUP_DIR . '*.zip' ) ?: [] );
    foreach ( array_keys( $log ) as $k ) {
        if ( ! in_array( $k, $existing, true ) ) unset( $log[$k] );
    }
    update_option( 'csbr_dropbox_log', $log, false );

    return $result;
}

/**
 * Refresh the Dropbox backup history by querying via rclone lsjson.
 *
 * @since 3.2.83
 * @return array Updated history keyed by filename, or empty array if not configured.
 */
function csbr_dropbox_refresh_history(): array {
    $remote = get_option( 'csbr_dropbox_remote', '' );
    if ( ! $remote ) return [];
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) return [];

    $dest_path   = ltrim( get_option( 'csbr_dropbox_path', 'cloudscale-backups/' ), '/' );
    $remote_path = rtrim( $remote, ':' ) . ':' . $dest_path;
    $cmd         = escapeshellarg( $rclone ) . ' lsjson ' . escapeshellarg( $remote_path ) . ' --files-only 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out  = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $data = json_decode( $out ?: '[]', true );
    if ( ! is_array( $data ) ) return [];

    $existing = (array) get_option( 'csbr_dropbox_history', [] );
    $found    = [];

    foreach ( $data as $f ) {
        $name = $f['Name'] ?? '';
        if ( ! str_ends_with( $name, '.zip' ) ) continue;
        $size = (int) ( $f['Size']    ?? 0 );
        $mod  = $f['ModTime'] ?? '';
        $ts   = $mod ? (int) strtotime( $mod ) : 0;
        $found[$name] = [
            'name'       => $name,
            'size_bytes' => $size,
            'size_fmt'   => csbr_format_size( $size ),
            'time'       => $ts,
            'tag'        => $existing[$name]['tag']    ?? '',
            'golden'     => $existing[$name]['golden'] ?? false,
            'date_fmt'   => $ts ? wp_date( 'j M Y H:i', $ts ) : '—',
        ];
    }

    uasort( $found, fn( $a, $b ) => ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ) );
    update_option( 'csbr_dropbox_history', $found, false );
    return $found;
}

/**
 * Delete old Dropbox backups beyond csbr_ami_max retention limit.
 *
 * @since 3.2.83
 * @return void
 */
function csbr_enforce_dropbox_retention(): void {
    $max    = max( 1, intval( get_option( 'csbr_ami_max', 10 ) ) );
    $remote = get_option( 'csbr_dropbox_remote', '' );
    if ( ! $remote ) return;
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) return;

    $history = (array) get_option( 'csbr_dropbox_history', [] );
    if ( empty( $history ) ) return;

    $golden  = array_values( array_filter( $history, fn( $e ) => ! empty( $e['golden'] ) ) );
    $regular = array_values( array_filter( $history, fn( $e ) =>   empty( $e['golden'] ) ) );

    if ( count( $regular ) <= $max ) return;

    usort( $regular, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
    $to_prune = array_slice( $regular, 0, count( $regular ) - $max );
    $dest_path = ltrim( get_option( 'csbr_dropbox_path', 'cloudscale-backups/' ), '/' );

    foreach ( $to_prune as $old ) {
        $name  = $old['name'] ?? '';
        if ( ! $name ) continue;
        $rpath = rtrim( $remote, ':' ) . ':' . rtrim( $dest_path, '/' ) . '/' . $name;
        $cmd   = escapeshellarg( $rclone ) . ' deletefile ' . escapeshellarg( $rpath ) . ' 2>&1';
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        unset( $history[$name] );
        csbr_log( '[CloudScale Backup & Restore] Dropbox retention: deleted ' . $name );
    }

    update_option( 'csbr_dropbox_history', $history, false );
    update_option( 'csbr_dropbox_remote_count', count( array_merge( $golden, array_slice( $regular, -$max ) ) ), false );
}

function csbr_find_aws(): string {
    $candidates = [
        trim((string) shell_exec('which aws 2>/dev/null')), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        '/usr/local/bin/aws',
        '/usr/bin/aws',
        '/usr/local/aws-cli/v2/current/bin/aws',
        '/home/' . get_current_user() . '/.local/bin/aws',
    ];
    foreach ($candidates as $path) {
        if ($path && file_exists($path) && is_executable($path)) {
            return $path;
        }
    }
    return '';
}

/**
 * Upload a local backup file to the configured S3 bucket using the AWS CLI.
 *
 * @since 3.0.0
 * @param string $local_path     Absolute filesystem path to the backup zip.
 * @param bool   $schedule_retry Whether to schedule a WP-Cron retry on failure. Default true.
 * @return array{ok: bool, dest: string, error?: string, skipped?: bool} Result array.
 */
function csbr_sync_to_s3(string $local_path, bool $schedule_retry = true): array {
    if (!get_option('csbr_s3_sync_enabled', true)) return ['skipped' => true];
    $bucket = get_option('csbr_s3_bucket', '');
    if (!$bucket) return ['skipped' => true];

    $aws = csbr_find_aws();
    if (!$aws) {
        return ['ok' => false, 'error' => 'AWS CLI not found. Check common locations or ensure it is executable by the web server user.'];
    }

    $prefix  = get_option('csbr_s3_prefix', 'backups/');
    $prefix  = '/' . ltrim($prefix, '/');
    $dest    = 's3://' . rtrim($bucket, '/') . rtrim($prefix, '/') . '/' . basename($local_path);
    $escaped = escapeshellarg($local_path);
    $edest   = escapeshellarg($dest);

    $cmd = escapeshellarg($aws) . " s3 cp {$escaped} {$edest} --only-show-errors 2>&1";
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

    // Space error: free oldest local backup and retry once
    if ($out && csbr_is_space_error($out)) {
        csbr_free_local_space('S3', basename($local_path));
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $out = trim((string) shell_exec($cmd)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    }

    $filename = basename($local_path);
    $log      = (array) get_option('csbr_s3_log', []);

    if ($out) {
        csbr_log('[CloudScale Backup & Restore] S3 sync error: ' . $out);
        $result = ['ok' => false, 'dest' => $dest, 'error' => $out];
        $retry_at = null;
        if ($schedule_retry && !wp_next_scheduled('csbr_s3_retry', [$filename])) {
            wp_schedule_single_event(time() + 300, 'csbr_s3_retry', [$filename]);
            $retry_at = time() + 300;
            csbr_log('[CloudScale Backup & Restore] S3 retry scheduled in 5 min for ' . $filename);
        }
        $log[$filename] = array_filter([
            'ok'       => false,
            'time'     => time(),
            'dest'     => $dest,
            'error'    => $out,
            'retry_at' => $retry_at,
        ], fn($v) => $v !== null);
    } else {
        csbr_log('[CloudScale Backup & Restore] S3 sync OK: ' . $dest);
        $result = ['ok' => true, 'dest' => $dest];
        $log[$filename] = ['ok' => true, 'time' => time(), 'dest' => $dest];
        csbr_enforce_s3_retention();
    }

    // Prune log entries for files that no longer exist
    $existing = array_map('basename', glob(CSBR_BACKUP_DIR . '*.zip') ?: []);
    foreach (array_keys($log) as $k) {
        if (!in_array($k, $existing, true)) unset($log[$k]);
    }
    update_option('csbr_s3_log', $log, false);

    return $result;
}

// ============================================================
// Core — Database dump
// ============================================================


// ============================================================
// S3 history — query bucket and merge with stored tags/golden
// ============================================================

/**
 * Refresh the S3 backup history by querying the configured bucket.
 *
 * Runs `aws s3 ls`, parses date/size/filename for each .zip, and merges the
 * result with the existing csbr_s3_history option (preserving tag and golden
 * fields for files that still exist in S3). Entries for files no longer in
 * S3 are dropped.
 *
 * @since 3.2.124
 * @return array Updated history keyed by filename, or empty array if S3 not configured.
 */
function csbr_s3_refresh_history(): array {
    $bucket = get_option( 'csbr_s3_bucket', '' );
    if ( ! $bucket ) return [];
    $aws = csbr_find_aws();
    if ( ! $aws ) return [];

    $prefix    = '/' . ltrim( get_option( 'csbr_s3_prefix', 'backups/' ), '/' );
    $s3_base   = 's3://' . rtrim( $bucket, '/' ) . rtrim( $prefix, '/' ) . '/';
    $cmd       = escapeshellarg( $aws ) . ' s3 ls ' . escapeshellarg( $s3_base ) . ' 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out       = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $existing  = (array) get_option( 'csbr_s3_history', [] );
    $found     = [];

    foreach ( explode( "\n", $out ) as $line ) {
        $line = trim( $line );
        if ( ! $line ) continue;
        // Format: "2026-03-18 03:30:00     12345 bkup_f44.zip"
        if ( preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\d+)\s+(.+\.zip)$/', $line, $m ) ) {
            $name = $m[3];
            $ts   = (int) strtotime( $m[1] );
            $size = (int) $m[2];
            $found[ $name ] = [
                'name'       => $name,
                'size_bytes' => $size,
                'size_fmt'   => csbr_format_size( $size ),
                'time'       => $ts,
                'tag'        => $existing[ $name ]['tag']    ?? '',
                'golden'     => $existing[ $name ]['golden'] ?? false,
                'date_fmt'   => $ts ? wp_date( 'j M Y H:i', $ts ) : '—',
                'local'      => file_exists( CSBR_BACKUP_DIR . $name ),
            ];
        }
    }

    update_option( 'csbr_s3_history', $found, false );
    return $found;
}
/**
 * Dump the full WordPress database to a SQL string using the best available method.
 *
 * Uses mysqldump CLI if available, otherwise falls back to the PHP streamed implementation.
 *
 * @since 1.0.0
 * @return string Complete SQL dump as a string.
 */
function csbr_dump_database(): string {
    return csbr_mysqldump_available() ? csbr_dump_via_mysqldump() : csbr_dump_via_php($GLOBALS['wpdb']);
}

/**
 * Check whether the mysqldump CLI binary is available on the server.
 *
 * @since 1.0.0
 * @return bool True if mysqldump is found in PATH.
 */
function csbr_mysqldump_available(): bool {
    exec('which mysqldump 2>/dev/null', $out, $rc); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- checking binary availability via which; no user input
    return $rc === 0;
}

/**
 * Dump the database using the native mysqldump CLI for maximum speed.
 *
 * Uses --single-transaction --quick --lock-tables=false for a non-locking dump.
 * The database password is passed via the MYSQL_PWD environment variable, not a shell argument.
 *
 * @since 1.0.0
 * @return string Complete SQL dump as a string.
 */
function csbr_dump_via_mysqldump(): string {
    [$host, $port] = csbr_parse_db_host(DB_HOST);
    $tmp = tempnam(sys_get_temp_dir(), 'csbr_dump_') . '.sql';

    $cmd = sprintf(
        'MYSQL_PWD=%s mysqldump --single-transaction --quick --lock-tables=false --complete-insert -h %s -P %s -u %s %s > %s 2>&1',
        escapeshellarg(DB_PASSWORD),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_NAME),
        escapeshellarg($tmp)
    );

    exec($cmd, $output, $rc); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- mysqldump CLI backup; all args built with escapeshellarg()

    if ($rc !== 0 || !file_exists($tmp) || filesize($tmp) < 100) {
        wp_delete_file($tmp);
        return csbr_dump_via_php($GLOBALS['wpdb']);
    }

    $sql = file_get_contents($tmp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading mysqldump output from temp file; WP Filesystem API not appropriate for temp file operations
    wp_delete_file( $tmp );
    return $sql;
}

/**
 * Dump the database via PHP, reading each table in 500-row chunks.
 *
 * Used as a fallback when mysqldump is not available. Never loads the full
 * database into memory at once.
 *
 * @since 1.0.0
 * @param \wpdb $wpdb WordPress database object.
 * @return string Complete SQL dump as a string.
 */
function csbr_dump_via_php(\wpdb $wpdb): string {
    $out   = [];
    $out[] = '-- CloudScale Backup & Restore v' . CSBR_VERSION;
    $out[] = '-- Generated: ' . gmdate('Y-m-d H:i:s');
    $out[] = '-- Site: ' . get_site_url();
    $out[] = '-- Database: ' . DB_NAME;
    $out[] = '';
    $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $out[] = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = '';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare -- SHOW TABLES: schema introspection required for backup; no user input; caching not applicable
    $tables = $wpdb->get_col( 'SHOW TABLES' );

    foreach ($tables as $table) {
        $safe_table = esc_sql( $table );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema introspection required for backup; table name from SHOW TABLES, sanitised via esc_sql()
        $create     = $wpdb->get_row( "SHOW CREATE TABLE `{$safe_table}`", ARRAY_N );
        $out[]      = "DROP TABLE IF EXISTS `{$safe_table}`;";
        $out[]      = $create[1] . ';';
        $out[]      = '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table row count; table name sanitised via esc_sql()
        $total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$safe_table}`" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list required for backup; table name sanitised via esc_sql()
        $columns = $wpdb->get_col( "DESCRIBE `{$safe_table}`" );
        $chunk   = 500;
        $offset  = 0;

        while ($offset < $total) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- backup dump; $safe_table sanitised via esc_sql(); caching not applicable for backup
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$safe_table}` LIMIT %d OFFSET %d", $chunk, $offset ),
                ARRAY_N
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if (empty($rows)) break;

            $col_list = '`' . implode('`, `', $columns) . '`';
            $vals     = [];
            foreach ($rows as $row) {
                $escaped = array_map(fn($v) => $v === null ? 'NULL' : "'" . esc_sql($v) . "'", $row);
                $vals[]  = '(' . implode(', ', $escaped) . ')';
            }

            $out[] = "INSERT INTO `{$safe_table}` ({$col_list}) VALUES";
            $out[] = implode(",\n", $vals) . ';';
            $out[] = '';
            $offset += $chunk;
        }

        $out[] = '';
    }

    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    return implode("\n", $out);
}

/**
 * Recursively add all files from a directory into an open ZipArchive.
 *
 * @since 1.0.0
 * @param ZipArchive $zip    Open ZipArchive instance.
 * @param string     $dir    Absolute path to the source directory.
 * @param string     $prefix Path prefix to use inside the zip (e.g. 'uploads/').
 * @return void
 */
function csbr_add_dir_to_zip(ZipArchive $zip, string $dir, string $prefix): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        if ($file->isFile()) {
            $local = $prefix . '/' . ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
            $zip->addFile($file->getPathname(), $local);
        }
    }
}

// ============================================================
// Core — Restore
// ============================================================

/**
 * Extract database.sql from a backup zip and restore it.
 *
 * @since 1.0.0
 * @param string $zip_path Absolute path to the backup zip file.
 * @return void
 * @throws \Exception If the zip cannot be opened or contains no database.sql.
 */
function csbr_restore_from_zip(string $zip_path): void {
    csbr_log( '[CloudScale Backup & Restore] Opening zip: ' . basename( $zip_path ) );
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        throw new Exception('Cannot open zip file.');
    }

    $enc_pwd = (string) get_option( 'csbr_encrypt_password', '' );
    if ( $enc_pwd !== '' ) {
        $zip->setPassword( $enc_pwd );
        csbr_log( '[CloudScale Backup & Restore] Zip: encryption password set' );
    }

    $idx = $zip->locateName('database.sql');
    if ($idx === false) {
        $zip->close();
        throw new Exception('No database.sql in this backup. Is it a DB or Full backup?');
    }

    $sql = $zip->getFromIndex($idx);
    $zip->close();
    csbr_log( '[CloudScale Backup & Restore] Zip: database.sql extracted (' . CloudScale_Backup_Utils::format_size( strlen( $sql ?: '' ) ) . ')' );

    if (empty($sql)) {
        $hint = $enc_pwd !== ''
            ? ' The backup may be encrypted with a different password — check Backup Encryption in Schedule & Settings.'
            : ' If this backup was encrypted, set the matching password in Schedule & Settings first.';
        throw new Exception( 'database.sql could not be read.' . $hint ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $hint is a hardcoded string literal, not user input
    }

    csbr_execute_sql_string($sql);
}

/**
 * Read a .sql file from disk and execute it against the database.
 *
 * @since 1.0.0
 * @param string $path Absolute path to the .sql file.
 * @return void
 * @throws \Exception If the file is empty or unreadable.
 */
function csbr_restore_sql_file(string $path): void {
    csbr_log( '[CloudScale Backup & Restore] Reading SQL file: ' . basename( $path ) . ' (' . CloudScale_Backup_Utils::format_size( (int) filesize( $path ) ) . ')' );
    $sql = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading uploaded SQL backup file; WP Filesystem API not appropriate for restore operations
    if (empty($sql)) {
        throw new Exception('SQL file is empty.');
    }
    csbr_execute_sql_string($sql);
}

/**
 * Upload a local backup file to Microsoft OneDrive using rclone.
 *
 * @since 3.2.251
 * @param string $local_path Absolute filesystem path to the backup zip.
 * @return array{ok: bool, dest: string, error?: string, skipped?: bool} Result array.
 */
function csbr_sync_to_onedrive( string $local_path ): array {
    if ( ! get_option( 'csbr_onedrive_sync_enabled', false ) ) return [ 'skipped' => true ];
    $remote = get_option( 'csbr_onedrive_remote', '' );
    if ( ! $remote ) return [ 'skipped' => true ];

    $rclone = csbr_find_rclone();
    if ( ! $rclone ) {
        return [ 'ok' => false, 'error' => 'rclone not found. Install rclone and configure a OneDrive remote.' ];
    }

    $dest_path = ltrim( get_option( 'csbr_onedrive_path', 'cloudscale-backups/' ), '/' );
    $dest      = rtrim( $remote, ':' ) . ':' . $dest_path;
    $escaped   = escapeshellarg( $local_path );
    $edest     = escapeshellarg( $dest );

    // Space pre-check
    $file_size = file_exists( $local_path ) ? (int) filesize( $local_path ) : 0;
    if ( $file_size > 0 ) {
        $free = csbr_rclone_free_bytes( $remote );
        if ( $free !== null && $free < $file_size ) {
            $free_mb = round( $free / 1048576, 1 );
            $need_mb = round( $file_size / 1048576, 1 );
            $err     = "Not enough space on OneDrive: {$free_mb} MB free, backup needs {$need_mb} MB.";
            csbr_log( '[CloudScale Backup & Restore] OneDrive sync skipped — ' . $err );
            return [ 'ok' => false, 'dest' => $dest, 'error' => $err ];
        }
    }

    $cmd = escapeshellarg( $rclone ) . " copy --timeout 5m --contimeout 30s {$escaped} {$edest} 2>&1";
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

    if ( $out && csbr_is_space_error( $out ) ) {
        csbr_free_local_space( 'OneDrive', basename( $local_path ) );
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $out = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    }

    $filename = basename( $local_path );
    $log      = (array) get_option( 'csbr_onedrive_log', [] );

    if ( $out ) {
        csbr_log( '[CloudScale Backup & Restore] OneDrive sync error: ' . $out );
        $result         = [ 'ok' => false, 'dest' => $dest, 'error' => $out ];
        $log[$filename] = [ 'ok' => false, 'time' => time(), 'dest' => $dest, 'error' => $out ];
    } else {
        csbr_log( '[CloudScale Backup & Restore] OneDrive sync OK: ' . $dest );
        $result         = [ 'ok' => true, 'dest' => $dest ];
        $log[$filename] = [ 'ok' => true, 'time' => time(), 'dest' => $dest ];
        csbr_enforce_onedrive_retention();
    }

    $existing = array_map( 'basename', glob( CSBR_BACKUP_DIR . '*.zip' ) ?: [] );
    foreach ( array_keys( $log ) as $k ) {
        if ( ! in_array( $k, $existing, true ) ) unset( $log[$k] );
    }
    update_option( 'csbr_onedrive_log', $log, false );

    return $result;
}

/**
 * Refresh the OneDrive backup history by querying via rclone lsjson.
 *
 * @since 3.2.251
 * @return array Updated history keyed by filename, or empty array if not configured.
 */
function csbr_onedrive_refresh_history(): array {
    $remote = get_option( 'csbr_onedrive_remote', '' );
    if ( ! $remote ) return [];
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) return [];

    $dest_path   = ltrim( get_option( 'csbr_onedrive_path', 'cloudscale-backups/' ), '/' );
    $remote_path = rtrim( $remote, ':' ) . ':' . $dest_path;
    $cmd         = escapeshellarg( $rclone ) . ' lsjson ' . escapeshellarg( $remote_path ) . ' --files-only 2>&1';
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out  = trim( (string) shell_exec( $cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $data = json_decode( $out ?: '[]', true );
    if ( ! is_array( $data ) ) return [];

    $existing = (array) get_option( 'csbr_onedrive_history', [] );
    $found    = [];

    foreach ( $data as $f ) {
        $name = $f['Name'] ?? '';
        if ( ! str_ends_with( $name, '.zip' ) ) continue;
        $size = (int) ( $f['Size']    ?? 0 );
        $mod  = $f['ModTime'] ?? '';
        $ts   = $mod ? (int) strtotime( $mod ) : 0;
        $found[$name] = [
            'name'       => $name,
            'size_bytes' => $size,
            'size_fmt'   => csbr_format_size( $size ),
            'time'       => $ts,
            'tag'        => $existing[$name]['tag']    ?? '',
            'golden'     => $existing[$name]['golden'] ?? false,
            'date_fmt'   => $ts ? wp_date( 'j M Y H:i', $ts ) : '—',
        ];
    }

    uasort( $found, fn( $a, $b ) => ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ) );
    update_option( 'csbr_onedrive_history', $found, false );
    return $found;
}

/**
 * Delete old OneDrive backups beyond the configured retention limit.
 *
 * @since 3.2.251
 * @return void
 */
function csbr_enforce_onedrive_retention(): void {
    $max    = max( 1, intval( get_option( 'csbr_ami_max', 10 ) ) );
    $remote = get_option( 'csbr_onedrive_remote', '' );
    if ( ! $remote ) return;
    $rclone = csbr_find_rclone();
    if ( ! $rclone ) return;

    $history = csbr_onedrive_refresh_history();
    if ( empty( $history ) ) return;

    $golden  = array_values( array_filter( $history, fn( $e ) => ! empty( $e['golden'] ) ) );
    $regular = array_values( array_filter( $history, fn( $e ) =>   empty( $e['golden'] ) ) );

    if ( count( $regular ) <= $max ) return;

    usort( $regular, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );
    $to_prune  = array_slice( $regular, 0, count( $regular ) - $max );
    $dest_path = ltrim( get_option( 'csbr_onedrive_path', 'cloudscale-backups/' ), '/' );

    foreach ( $to_prune as $old ) {
        $name  = $old['name'] ?? '';
        if ( ! $name ) continue;
        $rpath = rtrim( $remote, ':' ) . ':' . rtrim( $dest_path, '/' ) . '/' . $name;
        $cmd   = escapeshellarg( $rclone ) . ' deletefile ' . escapeshellarg( $rpath ) . ' 2>&1';
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        unset( $history[$name] );
        csbr_log( '[CloudScale Backup & Restore] OneDrive retention: deleted ' . $name );
    }

    update_option( 'csbr_onedrive_history', $history, false );
    update_option( 'csbr_onedrive_remote_count', count( array_merge( $golden, array_slice( $regular, -$max ) ) ), false );
}

/**
 * List all table names defined in a SQL dump string.
 *
 * Scans for CREATE TABLE statements and returns each table name once.
 *
 * @since 3.2.251
 * @param string $sql Raw SQL dump string.
 * @return string[] Table names in the order they appear in the dump.
 */
/**
 * Extract the SQL dump string from a resolved source (zip or .sql file).
 *
 * @since 3.2.251
 * @param array $source Return value of csbr_resolve_restore_source().
 * @return string Raw SQL dump contents.
 * @throws Exception If the file cannot be read or is empty.
 */
function csbr_get_sql_from_source( array $source ): string {
    if ( $source['ext'] === 'sql' ) {
        $sql = file_get_contents( $source['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local backup/staged file
        if ( empty( $sql ) ) { throw new Exception( 'SQL file is empty.' ); }
        return $sql;
    }
    $zip = new ZipArchive();
    if ( $zip->open( $source['path'] ) !== true ) { throw new Exception( 'Cannot open backup zip.' ); }
    $enc_pwd = (string) get_option( 'csbr_encrypt_password', '' );
    if ( $enc_pwd !== '' ) { $zip->setPassword( $enc_pwd ); }
    $sql = $zip->getFromName( 'database.sql' );
    $zip->close();
    if ( empty( $sql ) ) { throw new Exception( 'No database.sql in this backup.' ); }
    return $sql;
}

/**
 * Return a map of column name => zero-based index for a table in a SQL dump.
 *
 * Tries the INSERT column list first; falls back to CREATE TABLE column order.
 * Returns an empty array on failure (callers use known WordPress defaults).
 *
 * @since 3.2.251
 * @param string $sql   Full SQL dump.
 * @param string $table Table name to look up.
 * @return array<string,int> Map of column name to zero-based position.
 */
function csbr_get_table_column_indices( string $sql, string $table ): array {
    // Strategy 1: INSERT with explicit column list (--complete-insert or PHP dump).
    // Grab only the first line of the INSERT statement so the regex stays small.
    foreach ( [ 'INSERT INTO `' . $table . '`', 'INSERT IGNORE INTO `' . $table . '`' ] as $ins_needle ) {
        $pos = stripos( $sql, $ins_needle );
        if ( $pos !== false ) {
            $eol  = strpos( $sql, "\n", $pos );
            $line = substr( $sql, $pos, $eol !== false ? ( $eol - $pos ) : 4096 );
            // Match: INSERT INTO `table` (`col1`, `col2`, ...) VALUES
            if ( preg_match( '/`[^`]+`\s+\((`[^)]+`)\)\s+VALUES/i', $line, $m ) ) {
                $names = array_map( static fn( $c ) => trim( $c, " `\t" ), explode( ',', $m[1] ) );
                if ( $names && $names[0] !== '' ) {
                    return array_flip( $names );
                }
            }
            break; // only check the first INSERT for this table
        }
    }

    // Strategy 2: CREATE TABLE column order.
    // Use a balanced-paren scanner to locate the body — robust across all MySQL/MariaDB
    // versions regardless of ENGINE, CHARSET, or line-ending variations.
    $ct_needle = 'CREATE TABLE `' . $table . '`';
    $ct_pos    = stripos( $sql, $ct_needle );
    if ( $ct_pos === false ) {
        $ct_needle = 'CREATE TABLE IF NOT EXISTS `' . $table . '`';
        $ct_pos    = stripos( $sql, $ct_needle );
    }
    if ( $ct_pos !== false ) {
        $open = strpos( $sql, '(', $ct_pos ); // opening ( of the column list
        if ( $open !== false ) {
            // Walk forward tracking paren depth and string state to find the matching ).
            $sql_len = strlen( $sql );
            $depth   = 1;
            $in_str  = false;
            $j       = $open + 1;
            while ( $j < $sql_len && $depth > 0 ) {
                $ch = $sql[ $j ];
                if ( $in_str ) {
                    if ( $ch === '\\' ) { $j++; } // skip escaped char
                    elseif ( $ch === "'" ) { $in_str = false; }
                } else {
                    if ( $ch === "'" ) { $in_str = true; }
                    elseif ( $ch === '(' ) { $depth++; }
                    elseif ( $ch === ')' ) { $depth--; }
                }
                $j++;
            }
            // Contents between the outer parens of CREATE TABLE
            $body       = substr( $sql, $open + 1, $j - $open - 2 );
            $cols_found = [];
            foreach ( explode( "\n", $body ) as $ln ) {
                $ln = ltrim( $ln, " \t\r" );
                // Lines that start with `)` are the closing of a nested expression — stop.
                if ( $ln !== '' && $ln[0] === ')' ) {
                    break;
                }
                // Column definition lines start with a backtick-quoted name followed by a type keyword.
                if ( preg_match( '/^`([^`]+)`\s+[a-z]/i', $ln, $lm ) ) {
                    $cols_found[] = $lm[1];
                }
            }
            if ( ! empty( $cols_found ) ) {
                return array_flip( $cols_found );
            }
        }
    }
    return [];
}

/**
 * Extract the column list clause (with surrounding spaces) from an INSERT statement.
 * Returns '' if the INSERT uses positional VALUES (no explicit column list).
 *
 * @since 3.2.251
 * @param string $insert_sql A complete INSERT ... statement.
 * @return string e.g. ' (`ID`,`post_title`,...)' or ''.
 */
function csbr_get_insert_column_clause( string $insert_sql ): string {
    if ( preg_match( '/INSERT\s+(?:IGNORE\s+)?INTO\s+`[^`]+`\s+(\([^)]+\))\s+VALUES/i', $insert_sql, $m ) ) {
        return ' ' . $m[1];
    }
    return '';
}

/**
 * Parse each VALUES row from a SQL INSERT statement into an array of row content strings.
 * Each returned string is the raw content between the outer parentheses of one row,
 * with quotes and escape sequences intact.
 *
 * @since 3.2.251
 * @param string $insert_sql A complete INSERT ... VALUES (...),(...),...; statement.
 * @return string[] Array of row content strings (outer parens stripped).
 */
function csbr_parse_insert_row_strings( string $insert_sql ): array {
    $vpos = stripos( $insert_sql, ' VALUES' );
    if ( $vpos === false ) { return []; }
    $clause = ltrim( substr( $insert_sql, $vpos + 7 ) ); // ltrim handles \n or space after VALUES
    $clause = rtrim( $clause, '; ' );

    $rows = [];
    $len  = strlen( $clause );
    $i    = 0;

    while ( $i < $len ) {
        while ( $i < $len && $clause[ $i ] !== '(' ) { $i++; }
        if ( $i >= $len ) { break; }
        $i++; // skip opening (

        $start    = $i;
        $depth    = 1;
        $in_quote = false;

        while ( $i < $len ) {
            $c = $clause[ $i ];
            if ( $in_quote ) {
                if ( $c === '\\' ) { $i++; } // skip escaped char
                elseif ( $c === "'" ) { $in_quote = false; }
            } else {
                if ( $c === "'" )     { $in_quote = true; }
                elseif ( $c === '(' ) { $depth++; }
                elseif ( $c === ')' ) {
                    $depth--;
                    if ( $depth === 0 ) { break; }
                }
            }
            $i++;
        }

        $rows[] = substr( $clause, $start, $i - $start );
        $i++; // skip closing )
        while ( $i < $len && ( $clause[ $i ] === ',' || $clause[ $i ] === ' ' || $clause[ $i ] === "\n" || $clause[ $i ] === "\r" || $clause[ $i ] === "\t" ) ) { $i++; }
    }

    return $rows;
}

/**
 * Parse column values from a single VALUES row content string (no outer parens).
 *
 * Handles SQL escape sequences, quoted strings, and unquoted values (NULL, integers).
 * Returns each value as a plain PHP string; NULL becomes the string 'NULL'.
 *
 * @since 3.2.251
 * @param string $row Row content without outer parentheses.
 * @return string[] Ordered array of column value strings.
 */
function csbr_parse_row_columns( string $row ): array {
    $cols = [];
    $len  = strlen( $row );
    $i    = 0;

    while ( $i < $len ) {
        while ( $i < $len && ( $row[ $i ] === ' ' || $row[ $i ] === "\t" || $row[ $i ] === "\n" || $row[ $i ] === "\r" ) ) { $i++; }
        if ( $i >= $len ) { break; }

        if ( $row[ $i ] === "'" ) {
            $i++;
            $val = '';
            while ( $i < $len ) {
                if ( $row[ $i ] === '\\' && $i + 1 < $len ) {
                    $next = $row[ ++$i ];
                    $val .= match ( $next ) {
                        'n'  => "\n",
                        'r'  => "\r",
                        't'  => "\t",
                        default => $next,
                    };
                    $i++;
                } elseif ( $row[ $i ] === "'" ) {
                    $i++;
                    break;
                } else {
                    $val .= $row[ $i++ ];
                }
            }
            $cols[] = $val;
        } else {
            $j = $i;
            while ( $j < $len && $row[ $j ] !== ',' ) { $j++; }
            $cols[] = trim( substr( $row, $i, $j - $i ) );
            $i = $j;
        }

        if ( $i < $len && $row[ $i ] === ',' ) { $i++; }
    }

    return $cols;
}

function csbr_list_tables_in_dump( string $sql ): array {
    preg_match_all( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $sql, $m );
    return array_values( array_unique( $m[1] ) );
}

/**
 * Filter a SQL dump to only include statements for the given tables.
 *
 * Preserves session-variable SET statements and MySQL version conditionals.
 * Strips database-level statements (CREATE DATABASE, USE).
 *
 * @since 3.2.251
 * @param string   $sql    Raw SQL dump string.
 * @param string[] $tables Table names to keep.
 * @return string Filtered SQL, ready to execute.
 */
function csbr_filter_sql_for_tables( string $sql, array $tables ): string {
    $stmts     = csbr_split_sql( $sql );
    $table_set = array_flip( array_map( 'strtolower', $tables ) );
    $out       = [];

    foreach ( $stmts as $stmt ) {
        $s = ltrim( $stmt );

        // Always keep SET statements and MySQL version conditionals.
        if ( preg_match( '/^SET\s+/i', $s ) || str_starts_with( $s, '/*!' ) ) {
            $out[] = $stmt . ';';
            continue;
        }

        // Always keep UNLOCK TABLES (paired with LOCK TABLES we may have kept).
        if ( preg_match( '/^UNLOCK\s+TABLES/i', $s ) ) {
            $out[] = $stmt . ';';
            continue;
        }

        // Skip database-level statements.
        if ( preg_match( '/^(?:CREATE\s+DATABASE|USE\s+)/i', $s ) ) {
            continue;
        }

        // Table-specific statements: include only if table is in the selected set.
        if ( preg_match(
            '/^(?:DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?|CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|INSERT\s+(?:IGNORE\s+)?INTO\s+|TRUNCATE\s+(?:TABLE\s+)?|ALTER\s+TABLE\s+|LOCK\s+TABLES\s+)`([^`]+)`/i',
            $s,
            $m
        ) ) {
            if ( isset( $table_set[ strtolower( $m[1] ) ] ) ) {
                $out[] = $stmt . ';';
            }
        }
    }

    return implode( "\n", $out );
}

/**
 * Execute a SQL string against the database using the best available method.
 *
 * Uses the mysql CLI if available, otherwise falls back to the PHP character-level parser.
 *
 * The PHP fallback executes pre-parsed SQL statements verbatim via $wpdb->query().
 * prepare() cannot be applied here: the statements are complete DDL/DML from a trusted
 * backup file (CREATE TABLE, INSERT … VALUES …), not parameterised templates.
 * Access is gated behind manage_options + nonce before this function is ever called.
 *
 * @since 1.0.0
 * @param string $sql Complete SQL to execute.
 * @return void
 */
function csbr_execute_sql_string(string $sql): void {
    global $wpdb;

    // Acquire MySQL advisory lock — prevents a second concurrent restore from running simultaneously.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- restore requires direct execution; prepare() cannot be applied to DDL/DML replay from a trusted backup file
    $lock = $wpdb->get_var( "SELECT GET_LOCK('csbr_restore_lock', 10)" );
    if ( '1' !== (string) $lock ) {
        throw new Exception( 'Could not acquire restore lock — another restore may already be running. Please wait and try again.' );
    }
    csbr_log( '[CloudScale Backup & Restore] Advisory lock acquired' );

    if (csbr_mysql_cli_available()) {
        csbr_log( '[CloudScale Backup & Restore] DB execution path: mysql CLI' );
        try {
            csbr_restore_via_mysql_cli($sql);
        } finally {
            $wpdb->query( "SELECT RELEASE_LOCK('csbr_restore_lock')" );
            csbr_log( '[CloudScale Backup & Restore] Advisory lock released' );
        }
        return;
    }

    csbr_log( '[CloudScale Backup & Restore] DB execution path: wpdb fallback' );
    $stmts       = csbr_split_sql( $sql );
    $stmt_count  = count( $stmts );
    $err_count   = 0;
    csbr_log( '[CloudScale Backup & Restore] Executing ' . $stmt_count . ' SQL statements' );

    $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

    foreach ( $stmts as $stmt ) {
        $stmt = trim($stmt);
        if (empty($stmt) || str_starts_with($stmt, '--') || str_starts_with($stmt, '/*')) {
            continue;
        }
        $wpdb->query($stmt);
        if ($wpdb->last_error) {
            ++$err_count;
            csbr_log('[CloudScale Backup & Restore] Restore statement error: ' . $wpdb->last_error . ' | ' . substr($stmt, 0, 200));
        }
    }

    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
    $wpdb->query( "SELECT RELEASE_LOCK('csbr_restore_lock')" );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    csbr_log( '[CloudScale Backup & Restore] Advisory lock released' );
    csbr_log( '[CloudScale Backup & Restore] DB execution complete — ' . $stmt_count . ' statements, ' . $err_count . ' error(s)' );
}

/**
 * Check whether the mysql CLI client binary is available on the server.
 *
 * @since 1.0.0
 * @return bool True if mysql is found in PATH.
 */
function csbr_mysql_cli_available(): bool {
    exec('which mysql 2>/dev/null', $out, $rc); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- checking binary availability via which; no user input
    return $rc === 0;
}

/**
 * Restore a SQL string by piping it through the mysql CLI binary.
 *
 * Writes the SQL to a temp file and passes it to mysql via stdin.
 * The database password is passed via the MYSQL_PWD environment variable.
 *
 * @since 1.0.0
 * @param string $sql Complete SQL to execute.
 * @return void
 * @throws \Exception If the restore command exits with a non-zero status.
 */
function csbr_restore_via_mysql_cli(string $sql): void {
    $stmt_count = count( csbr_split_sql( $sql ) );
    csbr_log( '[CloudScale Backup & Restore] mysql CLI: starting restore (' . CloudScale_Backup_Utils::format_size( strlen( $sql ) ) . ', ~' . $stmt_count . ' statements)' );

    $tmp = tempnam(sys_get_temp_dir(), 'csbr_restore_') . '.sql';
    file_put_contents($tmp, $sql); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing SQL to temp file for mysql CLI restore; WP Filesystem API not appropriate here

    [$host, $port] = csbr_parse_db_host(DB_HOST);

    $cmd = sprintf(
        'MYSQL_PWD=%s mysql -h %s -P %s -u %s %s < %s 2>&1',
        escapeshellarg(DB_PASSWORD),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_NAME),
        escapeshellarg($tmp)
    );

    exec($cmd, $output, $rc); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- mysql CLI restore; all args built with escapeshellarg()
    wp_delete_file( $tmp );

    if ($rc !== 0) {
        csbr_log( '[CloudScale Backup & Restore] mysql CLI: restore FAILED (exit ' . $rc . ') — ' . implode( ' | ', $output ) );
        throw new Exception('mysql CLI restore failed: ' . implode(' | ', $output)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception caught and message passed to wp_send_json_error()
    }
    csbr_log( '[CloudScale Backup & Restore] mysql CLI: restore complete (exit 0)' );
}

/**
 * Split a SQL dump string into individual statements.
 *
 * Uses a character-level parser that correctly handles quoted strings, escaped
 * characters, and multi-line INSERT statements.
 *
 * @since 1.0.0
 * @param string $sql Raw SQL dump string.
 * @return string[] Array of individual SQL statements (without trailing semicolons).
 */
function csbr_split_sql(string $sql): array {
    $stmts      = [];
    $current    = '';
    $in_string  = false;
    $str_char   = '';
    $len        = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($in_string) {
            $current .= $c;
            if ($c === '\\') {
                $current .= $sql[++$i] ?? '';
            } elseif ($c === $str_char) {
                $in_string = false;
            }
        } elseif ($c === "'" || $c === '"' || $c === '`') {
            $in_string = true;
            $str_char  = $c;
            $current  .= $c;
        } elseif ($c === ';') {
            $t = trim($current);
            if ($t !== '') $stmts[] = $t;
            $current = '';
        } else {
            $current .= $c;
        }
    }

    $t = trim($current);
    if ($t !== '') $stmts[] = $t;

    return $stmts;
}

// ============================================================
// Utilities
// ============================================================

/**
 * Parse a DB_HOST value into a [host, port] pair.
 *
 * @since 1.0.0
 * @param string $host Raw DB_HOST value, e.g. 'localhost' or '127.0.0.1:3307'.
 * @return array{string, string} Two-element array: [hostname, port].
 */
function csbr_parse_db_host( string $host ): array {
    return CloudScale_Backup_Utils::parse_db_host( $host );
}

/**
 * Return the absolute path to the most recently modified backup zip in the backup directory.
 *
 * @since 3.2.28
 * @return string Absolute path to the newest zip file, or empty string if none exist.
 */
function csbr_get_latest_backup_path(): string {
    $files = glob(CSBR_BACKUP_DIR . '*.zip') ?: [];
    if (!$files) return '';
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0];
}

function csbr_list_backups(): array {
    $backups = [];
    if (!is_dir(CSBR_BACKUP_DIR)) return $backups;

    $files = glob(CSBR_BACKUP_DIR . '*.zip') ?: [];

    $current_prefix = sanitize_key(get_option('csbr_backup_prefix', 'bkup')) ?: 'bkup';

    foreach ($files as $file) {
        $name = basename($file);
        // {prefix}_{tcode}{seq}.zip — decode type from single-char code in filename
        // Also match legacy bkup_ prefix if a custom prefix has been set
        // Legacy formats: backup_full_2026-02-25_... or full_000001.zip
        $p = preg_quote($current_prefix, '/');
        if (preg_match('/^' . $p . '_([a-zA-Z]*)\d+\.zip$/', $name, $tm)
            || ($current_prefix !== 'bkup' && preg_match('/^bkup_([a-zA-Z]*)\d+\.zip$/', $name, $tm))) {
            $tcode = $tm[1];
            $type = match($tcode) {
                'f'  => 'Full',
                'F'  => 'Full+',
                'd'  => 'DB',
                'm'  => 'Media',
                'p'  => 'Plugins',
                't'  => 'Themes',
                'dm' => 'DB + Media',
                'dp' => 'DB + Plugins',
                'dt' => 'DB + Themes',
                'dmp'  => 'DB + Media + Plugins',
                'dmt'  => 'DB + Media + Themes',
                'dpt'  => 'DB + Plugins + Themes',
                'dmpt' => 'Full',
                default => strtoupper($tcode) ?: 'Custom',
            };
        } elseif (preg_match('/^(?:backup_)?(.+?)_(?:\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}|\d{6}|\d{4})\.zip$/', $name, $m)) {
            $slug = $m[1];
            $type = match($slug) {
                'full'                    => 'Full',
                'full-plus'               => 'Full+',
                'db'                      => 'DB',
                'media'                   => 'Media',
                'plugins'                 => 'Plugins',
                'themes'                  => 'Themes',
                'db-media-plugins-themes' => 'Full',
                default                   => ucfirst(str_replace('-', ' + ', $slug)),
            };
        } else {
            $type = 'Unknown';
        }
        // Detect AES encryption via zip central-directory stat (no file read needed).
        $is_enc = false;
        $zs = new ZipArchive();
        if ( $zs->open( $file ) === true ) {
            $stat   = $zs->statIndex( 0 );
            $is_enc = isset( $stat['encryption_method'] ) && $stat['encryption_method'] !== 0;
            $zs->close();
        }
        $backups[] = [
            'name'      => $name,
            'type'      => $type,
            'size'      => filesize($file),
            'date'      => wp_date('j M Y H:i', filemtime($file)),
            'mtime'     => filemtime($file),
            'encrypted' => $is_enc,
        ];
    }

    usort($backups, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return $backups;
}

/**
 * Delete backup files that exceed the configured retention count (oldest first).
 *
 * @since 1.0.0
 * @return void
 */
function csbr_enforce_retention(): void {
    $keep    = intval(get_option('csbr_retention', 8));
    $backups = csbr_list_backups();
    foreach (array_slice($backups, $keep) as $b) {
        wp_delete_file(CSBR_BACKUP_DIR . $b['name']);
    }
}

/**
 * Return the total size in bytes of all files under a directory, recursively.
 *
 * @since 1.0.0
 * @param string $dir Absolute path to the directory.
 * @return int Total size in bytes, or 0 if the directory does not exist.
 */
function csbr_dir_size( string $dir ): int {
    return CloudScale_Backup_Utils::dir_size( $dir );
}

/**
 * Format a byte count as a human-readable string (B / KB / MB / GB).
 *
 * @since 1.0.0
 * @param int $bytes Size in bytes.
 * @return string Formatted string, e.g. '12.5 MB'.
 */
function csbr_format_size( int $bytes ): string {
    return CloudScale_Backup_Utils::format_size( $bytes );
}

/**
 * Format bytes as MB string with thousand separators and 2 decimal places.
 * Shows KB for values under 0.1 MB.
 *
 * @param int $bytes
 * @return string e.g. '1,234.56 MB'
 */
/**
 * Return a human-readable relative age string for a Unix timestamp.
 *
 * @since 1.0.0
 * @param int $timestamp Unix timestamp to describe.
 * @return string E.g. '5 min ago', '3 hr ago', '12 days ago', or a formatted date.
 */
function csbr_human_age( int $timestamp ): string {
    return CloudScale_Backup_Utils::human_age( $timestamp );
}

/**
 * Return the most recent failed log entry for a cloud provider, but only if it
 * occurred after the last successful sync (so we don't surface stale errors).
 *
 * @since 3.2.137
 * @param array    $log     Provider log array keyed by filename.
 * @param int|null $last_ok Unix timestamp of the last successful sync, or null.
 * @return array|null The failed log entry array, or null if none qualifies.
 */
function csbr_latest_failed_log_entry( array $log, ?int $last_ok ): ?array {
    if ( empty( $log ) ) return null;
    $most_recent = array_reduce(
        $log,
        fn( $carry, $e ) => ( ! $carry || ( $e['time'] ?? 0 ) > ( $carry['time'] ?? 0 ) ) ? $e : $carry
    );
    if ( ! $most_recent || ! empty( $most_recent['ok'] ) ) return null;
    if ( $last_ok && ( $most_recent['time'] ?? 0 ) <= $last_ok ) return null;
    return $most_recent;
}

/**
 * Return true if the given shell output string indicates a disk/storage space error.
 *
 * @since 3.2.137
 * @param string $error Shell command output.
 * @return bool
 */
function csbr_is_space_error( string $error ): bool {
    return (bool) preg_match(
        '/no space left|disk quota|insufficient.?storage|ENOSPC|quota.?exceeded|storageQuotaExceeded|not enough space|out of.?space/i',
        $error
    );
}

/**
 * Delete the oldest local backup zip to free disk space, then log a warning.
 * Never deletes the file currently being synced.
 *
 * @since 3.2.137
 * @param string $context     Provider name for log message (e.g. 'Dropbox').
 * @param string $exclude_file Basename of the file currently being processed — skip it.
 * @return string Basename of deleted file, or empty string if nothing was deleted.
 */
function csbr_free_local_space( string $context, string $exclude_file = '' ): string {
    $zips = glob( CSBR_BACKUP_DIR . '*.zip' ) ?: [];
    if ( count( $zips ) <= 1 ) {
        csbr_log( "[CloudScale Backup & Restore] Space error during {$context} sync: only one local backup exists, cannot free space. Consider increasing server disk storage." );
        return '';
    }
    usort( $zips, fn( $a, $b ) => filemtime( $a ) - filemtime( $b ) );
    foreach ( $zips as $zip ) {
        if ( $exclude_file && basename( $zip ) === $exclude_file ) continue;
        $name = basename( $zip );
        if ( wp_delete_file( $zip ) ) {
            csbr_log( "[CloudScale Backup & Restore] Space error during {$context} sync: deleted oldest local backup {$name} to free disk space. Consider increasing server storage or reducing retention count." );
            return $name;
        }
    }
    return '';
}

/**
 * Verify that the current AJAX request carries a valid nonce.
 *
 * Capability must be checked independently by each handler before calling this
 * function so that every action has a visible, self-contained permission gate.
 * Sends a JSON error response and exits if the nonce check fails.
 *
 * @since 1.0.0
 * @return void
 */
function csbr_verify_nonce(): void {
    if ( ! check_ajax_referer( 'csbr_nonce', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
}
