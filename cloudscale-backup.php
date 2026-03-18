<?php
/**
 * Plugin Name:       CloudScale Free Backup and Restore
 * Plugin URI:        https://andrewbaker.ninja/cloudscale-backup
 * Description:       No-nonsense WordPress backup and restore. Backs up database, media, plugins and themes into a single zip. Scheduled or manual, with safe restore and maintenance mode.
 * Version:           3.2.59
 * Author:            Andrew Baker
 * Author URI:        https://andrewbaker.ninja
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Tested up to:      6.9
 * Requires PHP:      8.1
 * Text Domain:       cloudscale-free-backup-and-restore
 */

defined( 'ABSPATH' ) || exit;

define('CS_BACKUP_VERSION',    '3.2.59');
define('CS_BACKUP_AMI_POLL_MAX_AGE', 5 * 600);              // Stop polling after 5 attempts (50 minutes)
define('CS_BACKUP_AMI_POLL_INTERVAL', 600);                 // Re-poll every 10 minutes
define('CS_BACKUP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CS_BACKUP_DIR', WP_CONTENT_DIR . '/cloudscale-backups/');
define('CS_BACKUP_MAINT_FILE', ABSPATH . '.maintenance');

require_once CS_BACKUP_PLUGIN_DIR . 'includes/class-cloudscale-backup-utils.php';

/**
 * Write a message to the PHP error log when WP_DEBUG is enabled.
 *
 * Delegates to CloudScale_Backup_Utils::log(). Silenced in production.
 *
 * @since 1.0.0
 * @param string $message Message to write to the PHP error log.
 * @return void
 */
function cs_log( string $message ): void {
    CloudScale_Backup_Utils::log( $message );
}

// ============================================================
// Bootstrap
// ============================================================

register_activation_hook(__FILE__, 'cs_activate');
register_deactivation_hook(__FILE__, 'cs_deactivate');

/**
 * Plugin activation: create the backup directory and schedule the first cron event.
 *
 * @since 1.0.0
 * @return void
 */
function cs_activate(): void {
    cs_ensure_backup_dir();
    cs_reschedule();
}

/**
 * Plugin deactivation: clear all scheduled hooks, remove maintenance mode, and
 * delete versioned asset files so a reinstall starts clean.
 *
 * @since 1.0.0
 * @return void
 */
function cs_deactivate(): void {
    wp_clear_scheduled_hook('cs_scheduled_backup');
    wp_clear_scheduled_hook('cs_scheduled_ami_backup');
    wp_clear_scheduled_hook('cs_ami_poll');
    wp_clear_scheduled_hook('cs_s3_retry');
    wp_clear_scheduled_hook('cs_ami_delete_check');
    cs_maintenance_off();

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
}

/**
 * Return the versioned filename for a JS/CSS asset.
 * On first page load after a deploy, copies script.js -> script-2-77-0.js
 * (and style.css -> style-2-77-0.css). The CDN has never seen the new URL
 * so it must fetch from origin — query strings (?ver=) are stripped by
 * CloudFront and other CDNs and do not bust the cache.
 *
 * @since 1.0.0
 * @param string $ext File extension — 'js' or 'css'.
 * @return string Filename (not path) of the versioned asset, e.g. 'script-3-2-0.js'.
 */
function cs_get_versioned_asset( string $ext ): string {
    $ver_slug  = str_replace('.', '-', CS_BACKUP_VERSION);
    $src_name  = ($ext === 'js') ? 'script.' . $ext : 'style.' . $ext;
    $dest_name = ($ext === 'js') ? 'script-' . $ver_slug . '.js' : 'style-' . $ver_slug . '.css';
    $dir       = plugin_dir_path(__FILE__);

    if (!file_exists($dir . $dest_name)) {
        // Delete old versioned copies first
        $pattern = ($ext === 'js') ? $dir . 'script-*.js' : $dir . 'style-*.css';
        foreach (glob($pattern) ?: [] as $old) {
            if (basename($old) !== $dest_name) { wp_delete_file($old); }
        }
        // Copy the canonical source to the new versioned name
        if (file_exists($dir . $src_name)) {
            copy($dir . $src_name, $dir . $dest_name);
        }
    }

    return file_exists($dir . $dest_name) ? $dest_name : $src_name;
}

// Version change detector — cleans stale assets on upgrade without deactivation
add_action('admin_init', function () {
    $cached = get_option('cs_loaded_version', '');
    if ($cached !== CS_BACKUP_VERSION) {
        if (function_exists('opcache_reset')) { opcache_reset(); }
        $dir = plugin_dir_path(__FILE__);
        // Delete old versioned JS/CSS files from previous versions
        foreach (glob($dir . 'script-*.js') ?: [] as $f) { wp_delete_file($f); }
        foreach (glob($dir . 'style-*.css') ?: [] as $f) { wp_delete_file($f); }
        // Clean old assets/ subfolder from early versions
        $assets = $dir . 'assets/';
        if (is_dir($assets)) {
            foreach (glob($assets . '*') as $f) { if (is_file($f)) { wp_delete_file($f); } }
            rmdir($assets); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem unavailable in activation/version hooks; empty dir after file deletion
        }
        update_option('cs_loaded_version', CS_BACKUP_VERSION);
    }
});

/**
 * Create the backup directory and its protection files if they do not exist.
 *
 * Creates CS_BACKUP_DIR, drops an .htaccess deny-all, and adds a silent index.php.
 *
 * @since 1.0.0
 * @return void
 */
function cs_ensure_backup_dir(): void {
    if (!file_exists(CS_BACKUP_DIR)) {
        wp_mkdir_p(CS_BACKUP_DIR);
    }
    $htaccess = CS_BACKUP_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }
    $index = CS_BACKUP_DIR . 'index.php';
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
 * If any entries remain pending and are younger than CS_BACKUP_AMI_POLL_MAX_AGE,
 * reschedules itself for another CS_BACKUP_AMI_POLL_INTERVAL seconds.
 * Gives up automatically after 2 hours to avoid polling indefinitely.
 *
 * @since 2.74.0
 * @return void
 */
add_action('cs_ami_poll', 'cs_ami_poll_handler');
function cs_ami_poll_handler(): void {
    $aws = cs_find_aws();
    if (!$aws) {
        cs_log('[CloudScale Backup] AMI poll: AWS CLI not found, skipping.');
        return;
    }

    $log     = (array) get_option('cs_ami_log', []);
    $region  = cs_get_instance_region();
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

        // Stop polling entries older than CS_BACKUP_AMI_POLL_MAX_AGE
        $age = time() - (int) ($entry['time'] ?? 0);
        if ($age > CS_BACKUP_AMI_POLL_MAX_AGE) {
            // Don't overwrite with timed-out — let Refresh All query AWS directly
            // Just stop scheduling further polls for this entry
            cs_log('[CloudScale Backup] AMI poll: gave up scheduling polls for ' . $entry['ami_id'] . ' after ' . round($age / 60) . ' min — use Refresh All to check current state');
            continue;
        }

        $cmd = escapeshellarg($aws) . ' ec2 describe-images'
             . ' --image-ids ' . escapeshellarg($entry['ami_id'])
             . $region_flag
             . ' --query "Images[0].State"'
             . ' --output text 2>&1';

        $raw   = trim((string) shell_exec($cmd));
        $state = $raw;

        if ($state && $state !== 'None' && !str_contains(strtolower($state), 'error') && !str_contains(strtolower($state), 'unable') && !str_contains(strtolower($state), 'invalid')) {
            if ($entry['state'] !== $state) {
                $entry['state'] = $state;
                $changed = true;
                cs_log('[CloudScale Backup] AMI poll: ' . $entry['ami_id'] . ' → ' . $state);
            }
            if ($state === 'pending') {
                $still_pending = true;
            }
        } else {
            // AWS returned nothing or an error — likely a credentials issue on this server user
            // Keep state as 'pending' so user knows it hasn't resolved, log the raw output
            $still_pending = true;
            cs_log('[CloudScale Backup] AMI poll: describe-images returned "' . $raw . '" for ' . $entry['ami_id'] . ' — possible credentials issue for www-data user. Use Refresh All from the admin UI instead.');
        }
    }
    unset($entry);

    if ($changed) {
        update_option('cs_ami_log', $log, false);
    }

    // Reschedule if any entries are still pending and not yet timed out
    if ($still_pending && !wp_next_scheduled('cs_ami_poll')) {
        wp_schedule_single_event(time() + CS_BACKUP_AMI_POLL_INTERVAL, 'cs_ami_poll');
        cs_log('[CloudScale Backup] AMI poll: rescheduled in ' . (CS_BACKUP_AMI_POLL_INTERVAL / 60) . ' min (pending AMIs remain)');
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
function cs_get_run_days(): array {
    global $wpdb;
    // Read directly from DB — bypasses object cache entirely
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional direct read to bypass object cache and get authoritative cron-safe value
    $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'cs_run_days' ) );
    if ($raw === null) {
        return [1, 3, 5]; // option row doesn't exist yet — install default
    }
    $saved = maybe_unserialize($raw);
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
function cs_reschedule(): void {
    wp_clear_scheduled_hook('cs_scheduled_backup');
    wp_clear_scheduled_hook('cs_scheduled_ami_backup');

    $timezone = wp_timezone();
    $now      = new DateTime('now', $timezone);

    // Local file backup — only if local schedule is enabled
    if (get_option('cs_schedule_enabled', false)) {
        $run_days = cs_get_run_days();
        if (!empty($run_days)) {
            $hour   = intval(get_option('cs_run_hour',   3));
            $minute = intval(get_option('cs_run_minute', 0));
            $candidate = clone $now;
            $candidate->setTime($hour, $minute, 0);
            for ($i = 0; $i <= 7; $i++) {
                $dow = intval($candidate->format('N')); // 1=Mon...7=Sun
                if (in_array($dow, $run_days, true) && $candidate > $now) break;
                $candidate->modify('+1 day');
                $candidate->setTime($hour, $minute, 0);
            }
            wp_schedule_event($candidate->getTimestamp(), 'daily', 'cs_scheduled_backup');
        }
    }

    // Cloud backup (AMI + S3 + GDrive) — runs at local backup time + delay
    if (get_option('cs_cloud_schedule_enabled', false)) {
        $cloud_days = array_map('intval', (array) get_option('cs_ami_schedule_days', []));
        if (!empty($cloud_days)) {
            $delay_mins   = max(15, intval(get_option('cs_cloud_backup_delay', 30)));
            $base_hour    = intval(get_option('cs_run_hour',   3));
            $base_minute  = intval(get_option('cs_run_minute', 0));
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
            wp_schedule_event($candidate->getTimestamp(), 'daily', 'cs_scheduled_ami_backup');
        }
    }
}

add_action('cs_scheduled_backup', function () {
    // Day-of-week guard — WP cron fires daily so skip days not in the list
    $run_days = cs_get_run_days();
    $today    = intval((new DateTime('now', wp_timezone()))->format('N'));
    if (!in_array($today, $run_days, true)) return;

    set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort(true);
    cs_ensure_backup_dir();

    $c = (array) get_option('cs_schedule_components', ['db', 'media', 'plugins', 'themes']);
    cs_create_backup(
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
    cs_enforce_retention();
});

add_action('cs_scheduled_ami_backup', function () {
    if (!get_option('cs_cloud_schedule_enabled', false)) return;

    // Day-of-week guard
    $cloud_days = array_map('intval', (array) get_option('cs_ami_schedule_days', []));
    $today      = intval((new DateTime('now', wp_timezone()))->format('N'));
    if (empty($cloud_days) || !in_array($today, $cloud_days, true)) return;

    // 1. AMI snapshot (if enabled and prefix configured)
    if (get_option('cs_ami_sync_enabled', true) && get_option('cs_ami_prefix', '')) {
        $result = cs_do_create_ami();
        if ($result['ok']) {
            cs_log('[CloudScale Backup] Scheduled AMI created: ' . $result['ami_id'] . ' (' . $result['name'] . ')');
        } else {
            cs_log('[CloudScale Backup] Scheduled AMI failed: ' . ($result['error'] ?? 'unknown error'));
        }
    }

    // 2. S3 sync of latest local backup zip
    if (get_option('cs_s3_sync_enabled', true)) {
        $latest = cs_get_latest_backup_path();
        if ($latest) {
            cs_sync_to_s3($latest);
            cs_log('[CloudScale Backup] Scheduled S3 sync: ' . basename($latest));
        }
    }

    // 3. Google Drive sync of latest local backup zip
    if (get_option('cs_gdrive_sync_enabled', true)) {
        $latest = $latest ?? cs_get_latest_backup_path();
        if ($latest) {
            cs_sync_to_gdrive($latest);
            cs_log('[CloudScale Backup] Scheduled GDrive sync: ' . basename($latest));
        }
    }
});

// ============================================================
// Admin menu
// ============================================================

add_action('admin_menu', function () {
    add_management_page(
        __( 'CloudScale Free Backup and Restore', 'cloudscale-free-backup-and-restore' ),
        __( 'CloudScale Backup & Restore', 'cloudscale-free-backup-and-restore' ),
        'manage_options',
        'cloudscale-backup',
        'cs_admin_page'
    );
});

add_action('admin_enqueue_scripts', function (string $hook): void {
    if ($hook !== 'tools_page_cloudscale-backup') return;
    $css_file = cs_get_versioned_asset('css');
    $js_file  = cs_get_versioned_asset('js');
    wp_enqueue_style('cs-style',   plugin_dir_url(__FILE__) . $css_file, [], CS_BACKUP_VERSION);
    wp_enqueue_script('cs-script', plugin_dir_url(__FILE__) . $js_file,  ['jquery'], CS_BACKUP_VERSION, true);
    wp_localize_script('cs-script', 'CS', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('cs_nonce'),
        'site_url' => get_site_url(),
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
function cs_admin_page(): void {
    cs_ensure_backup_dir();

    $backups      = cs_list_backups();
    $upload_dir   = wp_upload_dir();
    $upload_size   = cs_dir_size($upload_dir['basedir']);
    $plugins_size  = cs_dir_size(WP_PLUGIN_DIR);
    $themes_size   = cs_dir_size(get_theme_root());
    // "Other" backup targets
    $mu_path       = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    $mu_size       = is_dir($mu_path) ? cs_dir_size($mu_path) : 0;
    $lang_path     = WP_CONTENT_DIR . '/languages';
    $lang_size     = is_dir($lang_path) ? cs_dir_size($lang_path) : 0;
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
    $next_run     = wp_next_scheduled('cs_scheduled_backup');
    $ami_next_run = wp_next_scheduled('cs_scheduled_ami_backup');
    $maint_active = file_exists(CS_BACKUP_MAINT_FILE);

    // Disk space
    $free_bytes  = disk_free_space(CS_BACKUP_DIR);
    if ($free_bytes === false) $free_bytes = disk_free_space(ABSPATH);
    $total_bytes = disk_total_space(CS_BACKUP_DIR);
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

    // Handle form POST for schedule save (plain form, no redirect)
    $cs_schedule_saved_msg = '';
    if (isset($_POST['cs_action']) && $_POST['cs_action'] === 'save_schedule') {
        check_admin_referer('cs_nonce', 'nonce');
        $raw_days   = isset($_POST['run_days']) && is_array($_POST['run_days']) ? wp_unslash( $_POST['run_days'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_admin_referer(); sanitised via array_map('intval') on next line
        $clean_days = array_values(array_filter(array_map('intval', $raw_days), fn($d) => $d >= 1 && $d <= 7));
        update_option('cs_run_days',         $clean_days);
        update_option('cs_run_days_saved',   '1');
        update_option('cs_schedule_enabled', !empty($_POST['schedule_enabled']));
        update_option('cs_run_hour',   max(0, min(23, intval($_POST['run_hour']   ?? 3))));
        update_option('cs_run_minute', max(0, min(59, intval($_POST['run_minute'] ?? 0))));
        $valid_components = ['db', 'media', 'plugins', 'themes', 'mu', 'languages', 'dropins', 'htaccess', 'wpconfig'];
        $raw_components   = isset($_POST['schedule_components']) && is_array($_POST['schedule_components']) ? wp_unslash( $_POST['schedule_components'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_admin_referer(); values validated via array_intersect() against a whitelist
        $clean_components = array_values(array_intersect($raw_components, $valid_components));
        // Default to core four if nothing selected
        if (empty($clean_components)) { $clean_components = ['db', 'media', 'plugins', 'themes']; }
        update_option('cs_schedule_components', $clean_components);
        wp_cache_delete('cs_run_days',            'options');
        wp_cache_delete('cs_run_days_saved',      'options');
        wp_cache_delete('cs_schedule_enabled',    'options');
        wp_cache_delete('cs_schedule_components', 'options');
        wp_cache_delete('cs_run_hour',            'options');
        wp_cache_delete('cs_run_minute',          'options');
        wp_cache_delete('alloptions',             'options');
        cs_reschedule();
        $day_names = ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $saved_labels = implode(', ', array_map(fn($d) => $day_names[$d] ?? $d, $clean_days));
        $cs_schedule_saved_msg = $saved_labels ?: 'No days selected';
    }

    // Settings — read after any POST save so page shows updated values
    $enabled      = isset($_POST['cs_action']) ? !empty($_POST['schedule_enabled']) : (bool) get_option('cs_schedule_enabled', false);
    $run_days_sel    = cs_get_run_days();
    $hour            = intval(get_option('cs_run_hour',      3));
    $minute          = intval(get_option('cs_run_minute',    0));
    $ami_run_hour    = intval(get_option('cs_ami_run_hour',  3));
    $ami_run_minute  = intval(get_option('cs_ami_run_minute', 30));
    $sched_components  = (array) get_option('cs_schedule_components', ['db', 'media', 'plugins', 'themes']);
    $manual_defaults   = get_option('cs_manual_defaults', null);
    $md                = is_array($manual_defaults) ? $manual_defaults : null; // null = no saved defaults yet
    $retention      = intval(get_option('cs_retention', 8));
    $backup_prefix  = sanitize_key(get_option('cs_backup_prefix', 'bkup')) ?: 'bkup';
    $s3_bucket     = get_option('cs_s3_bucket', '');
    $s3_prefix     = get_option('cs_s3_prefix', 'backups/');
    $s3_saved_msg  = '';
    $gdrive_remote       = get_option('cs_gdrive_remote', '');
    $gdrive_path         = get_option('cs_gdrive_path', 'cloudscale-backups/');
    $s3_sync_enabled        = (bool) get_option('cs_s3_sync_enabled', true);
    $gdrive_sync_enabled    = (bool) get_option('cs_gdrive_sync_enabled', true);
    $ami_sync_enabled       = (bool) get_option('cs_ami_sync_enabled', true);
    $cloud_schedule_enabled = (bool) get_option('cs_cloud_schedule_enabled', false);
    $cloud_backup_delay     = max(15, intval(get_option('cs_cloud_backup_delay', 30)));
    $ami_prefix          = get_option('cs_ami_prefix', '');
    $ami_reboot          = (bool) get_option('cs_ami_reboot', false);
    $ami_region_override = get_option('cs_ami_region_override', '');
    $ami_max             = intval(get_option('cs_ami_max', 10));
    $ami_schedule_days   = (array) get_option('cs_ami_schedule_days', []);
    $dump_method  = cs_mysqldump_available() ? 'mysqldump (native — fast)' : 'PHP streamed (compatible)';
    $restore_method = cs_mysql_cli_available() ? 'mysql CLI (native — fast)' : 'PHP streamed (compatible)';

    // MySQL version
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare -- static query; no user input; caching not applicable for live server version
    $mysql_version = $wpdb->get_var( 'SELECT VERSION()' ) ?: 'Unknown';
    $db_label      = 'MySQL ' . $mysql_version . ' — ' . DB_NAME;

    // Disk usage percentage for bar fill
    $free_pct = ($free_bytes !== false && $total_bytes > 0)
        ? min(100, round(($free_bytes / $total_bytes) * 100))
        : null;

    $days_map = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    ?>
    <div class="wrap cs-wrap">

        <!-- ===================== HEADER ===================== -->
        <div class="cs-header">
            <div class="cs-header-title">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <h1 style="margin:0;">☁ <?php echo esc_html__( 'CloudScale Free Backup & Restore', 'cloudscale-free-backup-and-restore' ); ?></h1>
                    <span style="display:inline-block;background:#fff;color:#1565c0;font-size:0.95rem;font-weight:800;letter-spacing:0.04em;padding:3px 14px;border-radius:20px;font-family:monospace;line-height:1.6;flex-shrink:0;">v<?php echo esc_html( CS_BACKUP_VERSION ); ?></span>
                </div>
                <p class="cs-header-sub"><?php esc_html_e( 'Database · media · plugins · themes. No timeouts, no external services.', 'cloudscale-free-backup-and-restore' ); ?></p>
                <p class="cs-header-free">✅ <?php esc_html_e( '100% free forever — no licence, no premium tier, no feature restrictions. Everything is included.', 'cloudscale-free-backup-and-restore' ); ?></p>
            </div>
            <div class="cs-header-status">
                <?php if ($maint_active): ?>
                    <span class="cs-badge cs-badge-warn">⚠ <?php esc_html_e( 'Maintenance Mode Active', 'cloudscale-free-backup-and-restore' ); ?></span>
                <?php else: ?>
                    <span class="cs-badge cs-badge-ok"><?php esc_html_e( 'Site Online', 'cloudscale-free-backup-and-restore' ); ?></span>
                <?php endif; ?>
                <a href="https://andrewbaker.ninja/2026/02/24/cloudscale-free-backup-and-restore-a-wordpress-backup-plugin-that-does-exactly-what-it-says/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:6px 16px;background:#f57c00!important;color:#fff!important;font-size:0.8rem;font-weight:700;border-radius:20px;text-decoration:none!important;border:1px solid #e65100!important;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ffcc80;box-shadow:0 0 5px #ffcc80;flex-shrink:0;"></span>andrewbaker.ninja</a>
            </div>
        </div>

        <!-- ===================== DISK SPACE ALERT ===================== -->
        <?php if ($show_disk_alert): ?>
        <div class="cs-alert cs-alert-<?php echo esc_attr($disk_status); ?>">
            <?php if ($disk_status === 'red'): ?>
                <span class="cs-alert-icon">🔴</span>
                <div><strong>Critical: Very low disk space</strong> —
                <?php echo esc_html(cs_format_size((int)$free_bytes)); ?> free<?php if ($backups_fit !== null): ?>, room for approximately <strong><?php echo (int) $backups_fit; ?> more backup(s)</strong><?php endif; ?>.
                Free up space or move old backups off-server immediately.</div>
            <?php else: ?>
                <span class="cs-alert-icon">🟡</span>
                <div><strong>Warning: Disk space is running low</strong> —
                <?php echo esc_html(cs_format_size((int)$free_bytes)); ?> free<?php if ($backups_fit !== null): ?>, room for approximately <strong><?php echo (int) $backups_fit; ?> more backup(s)</strong><?php endif; ?>.
                Consider freeing space or reducing your retention count.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        // Pre-compute cloud vars (needed before CS_S3_DIAG/CS_GDRIVE_DIAG inline scripts in Manual Backup section)
        $aws_path = cs_find_aws();
        $aws_ver  = $aws_path ? trim((string) shell_exec(escapeshellarg($aws_path) . ' --version 2>&1')) : '';
        $s3_log    = (array) get_option('cs_s3_log', []);
        $s3_synced = array_filter($s3_log, fn($e) => !empty($e['ok']));
        $s3_last   = empty($s3_synced) ? null : max(array_column($s3_synced, 'time'));
        $rclone_path = cs_find_rclone();
        $rclone_ver  = $rclone_path ? trim((string) shell_exec(escapeshellarg($rclone_path) . ' version --no-check-update 2>&1 | head -1')) : '';
        $gdrive_log    = (array) get_option('cs_gdrive_log', []);
        $gdrive_synced = array_filter($gdrive_log, fn($e) => !empty($e['ok']));
        $gdrive_last   = empty($gdrive_synced) ? null : max(array_column($gdrive_synced, 'time'));
        try {
            $ami_instance_id = cs_get_instance_id();
            $ami_region      = cs_get_instance_region();
            $ami_log         = (array) get_option('cs_ami_log', []);
            usort($ami_log, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
            $ami_log_recent  = $ami_log;
        } catch (\Throwable $e) {
            $ami_instance_id = '';
            $ami_region      = '';
            $ami_log         = [];
            $ami_log_recent  = [];
            cs_log('[CloudScale Backup] AMI panel init error: ' . $e->getMessage());
        }
        ?>

        <!-- ===================== TABS ===================== -->
        <div class="cs-tab-bar">
            <button class="cs-tab cs-tab--active" data-tab="local">&#128230; Local Backups</button>
            <button class="cs-tab" data-tab="cloud">&#9729; Cloud Backups</button>
        </div>

        <div id="cs-tab-local" class="cs-tab-panel">

        <!-- ===================== SETTINGS ===================== -->
        <div class="cs-section-ribbon"><span><?php esc_html_e( 'Schedule & Settings', 'cloudscale-free-backup-and-restore' ); ?></span></div>
        <div class="cs-grid cs-grid-1" style="display:flex!important;flex-direction:column!important;gap:16px!important;">

            <!-- SCHEDULE CARD -->
            <div class="cs-card cs-card--blue">
                <form method="post" action="" id="cs-schedule-form">
                <?php wp_nonce_field('cs_nonce', 'nonce'); ?>
                <input type="hidden" name="cs_action" value="save_schedule">
                <div class="cs-card-stripe cs-stripe--blue" style="background:linear-gradient(135deg,#1565c0 0%,#2196f3 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">⏰ <?php echo esc_html__( 'Backup Schedule', 'cloudscale-free-backup-and-restore' ); ?></h2><button type="button" onclick="csScheduleExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button></div>

                <!-- Enable/disable checkbox — inline with label -->
                <div class="cs-field-group">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-schedule-enabled" name="schedule_enabled" value="1" <?php checked($enabled); ?>>
                        <?php esc_html_e( 'Enable automatic backups', 'cloudscale-free-backup-and-restore' ); ?>
                    </label>
                </div>

                <!-- fieldset[disabled] is the only reliable cross-browser disable mechanism -->
                <fieldset id="cs-schedule-controls" <?php echo !$enabled ? 'disabled' : ''; ?> class="cs-schedule-fieldset">
                    <legend class="screen-reader-text">Schedule controls</legend>

                    <!-- File backup days + components + time -->
                    <div class="cs-field-group">
                        <span class="cs-field-label"><?php esc_html_e( 'File backup days', 'cloudscale-free-backup-and-restore' ); ?></span>
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
                        <span class="cs-field-label"><?php esc_html_e( 'Include in scheduled backup', 'cloudscale-free-backup-and-restore' ); ?></span>
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
                        <label class="cs-field-label" for="cs-run-hour"><?php esc_html_e( 'File backup time', 'cloudscale-free-backup-and-restore' ); ?></label>
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

                <div id="cs-off-notice" class="cs-off-notice" <?php echo $enabled ? 'style="display:none"' : ''; ?>>
                    Automatic backups are <strong>off</strong>. Enable the checkbox above to configure a schedule, or run backups manually below.
                </div>

                <button type="submit" name="cs_save_schedule" class="button button-primary cs-mt"><?php esc_html_e( 'Save Schedule', 'cloudscale-free-backup-and-restore' ); ?></button>
                <?php if ($cs_schedule_saved_msg): ?>
                <span class="cs-saved-msg" style="display:inline;margin-left:10px">✓ <?php echo esc_html($cs_schedule_saved_msg); ?></span>
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
                <div class="cs-card-stripe cs-stripe--green" style="background:linear-gradient(135deg,#2e7d32 0%,#43a047 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">🗂 <?php echo esc_html__( 'Retention & Storage', 'cloudscale-free-backup-and-restore' ); ?></h2><button type="button" onclick="csRetentionExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button></div>

                <div class="cs-field-group">
                    <label class="cs-field-label" for="cs-backup-prefix"><?php esc_html_e( 'Backup filename prefix', 'cloudscale-free-backup-and-restore' ); ?></label>
                    <div class="cs-inline">
                        <input type="text" id="cs-backup-prefix" class="cs-input-sm"
                               value="<?php echo esc_attr($backup_prefix); ?>"
                               maxlength="32" style="width:140px;" placeholder="bkup">
                        <span class="cs-muted-text">_f12.zip</span>
                    </div>
                    <p class="cs-help">Lowercase letters, numbers, and hyphens only. Example: <code>mysite</code> produces <code>mysite_f12.zip</code>. Existing backups are unaffected.</p>
                </div>

                <div class="cs-field-group">
                    <label class="cs-field-label"><?php esc_html_e( 'Keep last', 'cloudscale-free-backup-and-restore' ); ?></label>
                    <div class="cs-inline">
                        <input type="number" id="cs-retention"
                               value="<?php echo (int) $retention; ?>" min="1" max="9999"
                               class="cs-input-sm <?php echo $ret_over ? 'cs-retention-over' : ''; ?>">
                        <span class="cs-muted-text">backups</span>
                        <?php if ($ret_has_baseline): ?>
                        <span id="cs-retention-tl"
                              class="cs-ret-tl cs-ret-tl--<?php echo $ret_over ? 'red' : 'green'; ?>">
                            <span class="cs-tl-dot"></span>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div id="cs-retention-storage" class="cs-retention-storage"
                         data-latest-size="<?php echo (int)$latest_size; ?>"
                         data-free-bytes="<?php echo $free_bytes !== false ? (int)$free_bytes : 0; ?>">
                        <?php if ($ret_has_baseline): ?>
                        <div class="cs-ret-row">
                            <span class="cs-ret-label">Estimated storage needed</span>
                            <span id="cs-retention-est"
                                  class="cs-ret-val <?php echo $ret_over ? 'cs-retention-est--over' : ''; ?>">
                                <?php echo esc_html(cs_format_size($ret_needed)); ?>
                            </span>
                        </div>
                        <div class="cs-ret-row">
                            <span class="cs-ret-label">Disk free space</span>
                            <span class="cs-ret-val cs-free-<?php echo esc_attr($disk_status); ?>">
                                <?php echo $free_bytes !== false ? esc_html(cs_format_size((int)$free_bytes)) : 'Unknown'; ?>
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
                             <?php echo $ret_over ? '' : 'style="display:none"'; ?>>
                            ⚠ Estimated storage exceeds available disk space — reduce the retention count or free up space.
                        </div>
                    </div>

                    <p class="cs-help">Oldest backups beyond this limit are deleted automatically after each run.</p>
                </div>

                <div class="cs-storage-grid">
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Backup storage used</span>
                        <span class="cs-storage-value cs-value--blue"><?php echo esc_html(cs_format_size(cs_dir_size(CS_BACKUP_DIR))); ?></span>
                    </div>
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Media uploads</span>
                        <span class="cs-storage-value"><?php echo esc_html(cs_format_size($upload_size)); ?></span>
                    </div>
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Plugins folder</span>
                        <span class="cs-storage-value"><?php echo esc_html(cs_format_size($plugins_size)); ?></span>
                    </div>
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Themes folder</span>
                        <span class="cs-storage-value"><?php echo esc_html(cs_format_size($themes_size)); ?></span>
                    </div>
                </div>

                <button type="button" id="cs-save-retention" class="button button-primary cs-mt"><?php esc_html_e( 'Save Retention Settings', 'cloudscale-free-backup-and-restore' ); ?></button>
                <span id="cs-retention-saved" class="cs-saved-msg" style="display:none">✓ Saved</span>
            </div>



            <?php if ( false ): // AMI card rendered in Cloud tab ?>
            <div class="cs-card cs-card--indigo">
                <div class="cs-card-stripe cs-stripe--indigo" style="background:linear-gradient(135deg,#1a237e 0%,#3949ab 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128247; <?php echo esc_html__( 'EC2 AMI Snapshot', 'cloudscale-free-backup-and-restore' ); ?></h2>
                    <button type="button" onclick="csAmiExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button>
                </div>

                <p class="cs-help">Create a full machine image (AMI) of this EC2 instance. The AMI name will be <code>{prefix}_yyyyMMdd_HHmm</code>. Requires AWS CLI with <code>ec2:CreateImage</code>, <code>ec2:DescribeImages</code>, <code>ec2:DeregisterImage</code> and <code>ec2:RebootInstances</code> permissions.</p>

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
                    <strong><?php echo $ami_region ? esc_html($ami_region) : '<span style="color:#999">Unknown — set override below</span>'; ?></strong>
                </div>

                <div class="cs-field-row cs-mt">
                    <label for="cs-ami-region-override"><strong>Region override</strong></label>
                    <input type="text" id="cs-ami-region-override" class="cs-input-sm" placeholder="e.g. af-south-1"
                           value="<?php echo esc_attr($ami_region_override); ?>" style="width:calc(20em - 50px);min-width:140px;">
                    <p class="cs-help">Set this if the region shown above is wrong or Unknown. Bypasses IMDS detection entirely. Example: <code>af-south-1</code></p>
                </div>

                <div class="cs-field-row cs-mt">
                    <div style="display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap;">
                        <div>
                            <label for="cs-ami-prefix"><strong>AMI name prefix</strong></label>
                            <input type="text" id="cs-ami-prefix" placeholder="mysite-backup"
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
                    <button type="button" onclick="csAmiSave()" class="button button-primary"><?php esc_html_e( 'Save AMI Settings', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <button type="button" onclick="csAmiCreate()" class="button" style="margin-left:8px;background:#1a237e!important;color:#fff!important;border-color:#1a237e!important;" <?php echo $ami_instance_id ? '' : 'disabled title="EC2 instance not detected"'; ?>>&#128247; Create AMI Now</button>
                    <button type="button" onclick="csAmiResetAndRefresh()" class="button" id="cs-ami-refresh-all" style="margin-left:4px;background:#c2185b!important;color:#fff!important;border-color:#880e4f!important;">&#8635; Refresh All</button>
                    <span id="cs-ami-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
                </div>


                <?php if (!empty($ami_log_recent)): ?>
                <div class="cs-mt" style="margin-top:16px;">
                    <p class="cs-field-label" style="margin-bottom:6px;"><?php esc_html_e( 'Recent AMI snapshots', 'cloudscale-free-backup-and-restore' ); ?></p>
                    <div class="cs-table-wrap">
                    <table class="widefat cs-table" style="font-size:0.82rem;">
                        <thead>
                            <tr>
                                <th>AMI Name</th>
                                <th style="width:130px;">AMI ID</th>
                                <th style="width:110px;">Created</th>
                                <th style="width:80px;">Status</th>
                                <th style="width:140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="cs-ami-tbody">
                        <?php foreach ($ami_log_recent as $entry): ?>
                            <?php
                            $row_ami_id  = esc_attr($entry['ami_id'] ?? '');
                            $entry_state = $entry['state'] ?? '';
                            $is_deleted  = ($entry_state === 'deleted in AWS');
                            $is_ok       = !empty($entry['ok']);
                            ?>
                            <tr id="cs-ami-row-<?php echo esc_attr( $row_ami_id ); ?>">
                                <?php $faded = $is_deleted ? 'style="opacity:0.45;"' : ''; ?>
                                <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>><?php echo esc_html($entry['name'] ?? '—'); ?></td>
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
                                <td id="cs-ami-actions-<?php echo esc_attr( $row_ami_id ); ?>">
                                    <?php if (!empty($entry['ami_id'])): ?>
                                    <?php if (!$is_deleted): ?>
                                    <button type="button" onclick="csAmiRefreshOne('<?php echo esc_js( $row_ami_id ); ?>')" class="button button-small" title="Refresh this AMI state from AWS" style="min-width:0;padding:2px 6px;margin-bottom:3px;"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></button>
                                    <?php endif; ?>
                                    <button type="button" onclick="csAmiDelete('<?php echo esc_js( $row_ami_id ); ?>', '<?php echo esc_js($entry['name'] ?? ''); ?>', <?php echo $is_deleted ? 'true' : 'false'; ?>)" class="button button-small" title="<?php echo $is_deleted ? 'Remove record' : 'Deregister AMI'; ?>" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php echo $is_deleted ? 'Remove' : 'Delete'; ?></button>
                                    <?php else: ?>
                                    <button type="button" onclick="csAmiRemoveFailed('<?php echo esc_js($entry['name'] ?? ''); ?>')" class="button button-small" title="Remove failed record" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Remove</button>
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
                <div class="cs-card-stripe cs-stripe--purple" style="background:linear-gradient(135deg,#6a1b9a 0%,#8e24aa 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">⚙ <?php echo esc_html__( 'System Info', 'cloudscale-free-backup-and-restore' ); ?></h2><button type="button" onclick="csSystemExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button></div>

                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Backup method', 'cloudscale-free-backup-and-restore' ); ?></span>
                    <strong><?php echo esc_html($dump_method); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Restore method', 'cloudscale-free-backup-and-restore' ); ?></span>
                    <strong><?php echo esc_html($restore_method); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'PHP memory limit', 'cloudscale-free-backup-and-restore' ); ?></span>
                    <strong><?php echo esc_html(ini_get('memory_limit')); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Max execution time', 'cloudscale-free-backup-and-restore' ); ?></span>
                    <strong><?php echo ini_get('max_execution_time') === '0' ? esc_html__( 'Unlimited', 'cloudscale-free-backup-and-restore' ) : esc_html(ini_get('max_execution_time')) . 's'; ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Database', 'cloudscale-free-backup-and-restore' ); ?></span>
                    <strong><?php echo esc_html($db_label); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Total backups stored', 'cloudscale-free-backup-and-restore' ); ?></span>
                    <strong><?php echo (int) count($backups); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Backup directory', 'cloudscale-free-backup-and-restore' ); ?></span>
                    <strong class="cs-path"><?php echo esc_html(CS_BACKUP_DIR); ?></strong>
                </div>

                <!-- Traffic-light disk row -->
                <div class="cs-info-row cs-disk-row">
                    <span><?php esc_html_e( 'Disk free space', 'cloudscale-free-backup-and-restore' ); ?></span>
                    <strong class="cs-tl cs-tl--<?php echo esc_attr($disk_status); ?>">
                        <span class="cs-tl-dot"></span>
                        <?php echo $free_bytes !== false ? esc_html(cs_format_size((int)$free_bytes)) : 'Unavailable'; ?>
                        <?php if ($total_bytes): ?>
                            <span class="cs-disk-of">/ <?php echo esc_html(cs_format_size((int)$total_bytes)); ?></span>
                        <?php endif; ?>
                    </strong>
                </div>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Latest backup size', 'cloudscale-free-backup-and-restore' ); ?></span>
                    <strong><?php echo $latest_size > 0 ? esc_html(cs_format_size($latest_size)) : '—'; ?></strong>
                </div>

                <?php if ($free_pct !== null): ?>
                <div class="cs-info-row">
                    <span><?php esc_html_e( 'Percentage free space', 'cloudscale-free-backup-and-restore' ); ?></span>
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

        <!-- ===================== MANUAL BACKUP ===================== -->
        <div class="cs-section-ribbon"><span><?php esc_html_e( 'Manual Backup', 'cloudscale-free-backup-and-restore' ); ?></span></div>
        <div class="cs-card cs-card--orange cs-full">
            <div class="cs-card-stripe cs-stripe--orange" style="background:linear-gradient(135deg,#e65100 0%,#f57c00 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">▶ <?php echo esc_html__( 'Run Backup Now', 'cloudscale-free-backup-and-restore' ); ?></h2><button type="button" onclick="csBackupExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button></div>
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
                'latest'    => $latest_size,  // actual compressed size of last backup
            ];
            wp_add_inline_script( 'cs-script', 'window.CS_BACKUP_SIZES = ' . wp_json_encode( $backup_sizes ) . ';', 'before' );
            $s3_diag = [
                'aws_found'   => (bool) $aws_path,
                'aws_version' => $aws_ver,
                'bucket'      => $s3_bucket,
                'prefix'      => $s3_prefix,
                'synced'      => count($s3_synced),
                'total'       => count($backups),
                'last_fmt'    => $s3_last ? cs_human_age($s3_last) . ' ago (' . wp_date('j M Y H:i', $s3_last) . ')' : null,
            ];
            wp_add_inline_script( 'cs-script', 'window.CS_S3_DIAG = ' . wp_json_encode( $s3_diag ) . ';', 'before' );
            $gdrive_diag = [
                'rclone_found'   => (bool) $rclone_path,
                'rclone_version' => $rclone_ver,
                'remote'         => $gdrive_remote,
                'path'           => $gdrive_path,
                'synced'         => count($gdrive_synced),
                'total'          => count($backups),
                'last_fmt'       => $gdrive_last ? cs_human_age($gdrive_last) . ' ago (' . wp_date('j M Y H:i', $gdrive_last) . ')' : null,
            ];
            wp_add_inline_script( 'cs-script', 'window.CS_GDRIVE_DIAG = ' . wp_json_encode( $gdrive_diag ) . ';', 'before' );
            ?>
            <div class="cs-run-grid">
                <!-- Column 1: Core -->
                <div class="cs-options-col">
                    <p class="cs-options-col-heading">Core</p>
                    <?php
                    // Helper: checked if saved default exists for key, otherwise fall back to $fallback
                    $mc = function(string $key, bool $fallback) use ($md): string {
                        return ($md !== null ? in_array($key, $md, true) : $fallback) ? 'checked' : '';
                    };
                    ?>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-db" <?php echo esc_attr( $mc('db', true) ); ?> data-size="<?php echo (int) $db_size; ?>"> Database <code><?php echo $db_size > 0 ? esc_html(cs_format_size($db_size)) : '~unknown'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-media" <?php echo esc_attr( $mc('media', true) ); ?> data-size="<?php echo (int) $upload_size; ?>"> Media uploads <code><?php echo esc_html(cs_format_size($upload_size)); ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-plugins" <?php echo esc_attr( $mc('plugins', true) ); ?> data-size="<?php echo (int) $plugins_size; ?>"> Plugins <code><?php echo esc_html(cs_format_size($plugins_size)); ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-themes" <?php echo esc_attr( $mc('themes', true) ); ?> data-size="<?php echo (int) $themes_size; ?>"> Themes <code><?php echo esc_html(cs_format_size($themes_size)); ?></code></label>
                </div>
                <!-- Column 2: Other -->
                <div class="cs-options-col cs-options-col--other">
                    <p class="cs-options-col-heading">Other</p>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-mu" <?php echo esc_attr( $mc('mu', $mu_size > 0) ); ?> data-size="<?php echo absint( $mu_size ); ?>"> Must-use plugins <code><?php echo $mu_size > 0 ? esc_html(cs_format_size($mu_size)) : '0 B'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-languages" <?php echo esc_attr( $mc('languages', $lang_size > 0) ); ?> data-size="<?php echo absint( $lang_size ); ?>"> Languages <code><?php echo $lang_size > 0 ? esc_html(cs_format_size($lang_size)) : '0 B'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-dropins" <?php echo esc_attr( $mc('dropins', false) ); ?> data-size="<?php echo (int) $dropins_size; ?>"> Dropins <small>(object-cache.php…)</small> <code><?php echo $dropins_size > 0 ? esc_html(cs_format_size($dropins_size)) : '0 B'; ?></code></label>
<label class="cs-option-label"><input type="checkbox" id="cs-include-htaccess" <?php echo esc_attr( $mc('htaccess', $htaccess_size > 0) ); ?> data-size="<?php echo absint( $htaccess_size ); ?>"> .htaccess <code><?php echo $htaccess_size > 0 ? esc_html(cs_format_size($htaccess_size)) : 'not found'; ?></code></label>
                    <label class="cs-option-label cs-option-sensitive">
                        <input type="checkbox" id="cs-include-wpconfig" <?php echo esc_attr( $mc('wpconfig', false) ); ?> data-size="<?php echo (int) $wpconfig_size; ?>">
                        wp-config.php <code><?php echo $wpconfig_size > 0 ? esc_html(cs_format_size($wpconfig_size)) : 'not found'; ?></code>
                        <span class="cs-sensitive-badge">⚠ credentials</span>
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
                                <?php echo $free_bytes !== false ? esc_html( cs_format_size( (int) $free_bytes ) ) : 'Unknown'; ?>
                            </span>
                        </div>
                        <div class="cs-backup-summary-row" id="cs-space-warn-row" style="display:none">
                            <span class="cs-summary-warn" id="cs-space-warn"></span>
                        </div>
                    </div>
                    <button type="button" id="cs-run-backup" class="button button-primary cs-btn-lg">▶ <?php esc_html_e( 'Run Backup Now', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <div style="margin-top:8px;">
                        <button type="button" id="cs-save-manual-defaults" class="button"><?php esc_html_e( 'Save as defaults', 'cloudscale-free-backup-and-restore' ); ?></button>
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

        <!-- ===================== BACKUP HISTORY ===================== -->
        <div class="cs-section-ribbon"><span><?php esc_html_e( 'Backup History', 'cloudscale-free-backup-and-restore' ); ?></span></div>
        <div class="cs-card cs-card--teal cs-full">
            <div class="cs-card-stripe cs-stripe--teal" style="background:linear-gradient(135deg,#004d40 0%,#00897b 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">🕓 <?php echo esc_html__( 'Backup History', 'cloudscale-free-backup-and-restore' ); ?></h2><button type="button" onclick="csHistoryExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button></div>
            <?php if (empty($backups)): ?>
                <p class="cs-empty"><?php esc_html_e( 'No backups yet. Run your first backup above.', 'cloudscale-free-backup-and-restore' ); ?></p>
            <?php else: ?>
            <div class="cs-table-wrap">
            <table class="widefat cs-table">
                <thead>
                    <tr>
                        <th class="cs-col-actions" style="width:72px!important;min-width:72px!important;max-width:72px!important;"><?php esc_html_e( 'Actions', 'cloudscale-free-backup-and-restore' ); ?></th>
                        <th class="cs-col-num" style="width:18px!important;min-width:18px!important;max-width:18px!important;padding-left:4px!important;padding-right:4px!important;">#</th>
                        <th><?php esc_html_e( 'Filename', 'cloudscale-free-backup-and-restore' ); ?></th>
                        <th class="cs-col-size"><?php esc_html_e( 'Size', 'cloudscale-free-backup-and-restore' ); ?></th>
                        <th class="cs-col-meta"><?php esc_html_e( 'Created', 'cloudscale-free-backup-and-restore' ); ?></th>
                        <th class="cs-col-age"><?php esc_html_e( 'Age', 'cloudscale-free-backup-and-restore' ); ?></th>
                        <th class="cs-col-type"><?php esc_html_e( 'Type', 'cloudscale-free-backup-and-restore' ); ?></th>
                        <?php if ($s3_bucket): ?><th class="cs-col-s3" style="width:36px!important;text-align:center;" title="<?php esc_attr_e( 'S3 sync status', 'cloudscale-free-backup-and-restore' ); ?>">S3</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $s3_log = (array) get_option('cs_s3_log', []);
                foreach ($backups as $i => $b):
                    $is_db  = str_contains($b['type'], 'DB') || $b['type'] === 'Full' || $b['type'] === 'Full+';
                    $s3_entry = $s3_log[$b['name']] ?? null;
                ?>
                    <tr class="<?php echo $i === 0 ? 'cs-row-latest' : ''; ?>" style="height:40px!important;max-height:40px!important;">
                        <td class="cs-col-actions cs-actions" style="width:72px!important;min-width:72px!important;max-width:72px!important;padding:4px 6px!important;vertical-align:middle!important;white-space:nowrap;">
                            <a href="<?php echo esc_url(admin_url('admin-post.php?action=cs_download&file=' . urlencode($b['name']) . '&_wpnonce=' . wp_create_nonce('cs_download'))); ?>" class="cs-icon-btn cs-icon-btn--blue" title="Download">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            </a>
                            <?php if ($is_db): ?>
                            <button type="button" class="cs-icon-btn cs-icon-btn--green cs-restore-btn" data-file="<?php echo esc_attr($b['name']); ?>" data-date="<?php echo esc_attr($b['date']); ?>" title="Restore database">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="cs-icon-btn cs-icon-btn--red cs-delete-btn" data-file="<?php echo esc_attr($b['name']); ?>" title="Delete backup">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                            </button>
                        </td>
                        <td class="cs-col-num cs-idx" style="padding:6px 8px!important;vertical-align:middle!important;white-space:nowrap;"><?php echo (int) ( $i + 1 ); ?><?php if ($i === 0) echo wp_kses( '&nbsp;<span class="cs-latest-badge">latest</span>', [ 'span' => [ 'class' => [] ] ] ); ?></td>
                        <td class="cs-filename" style="padding:6px 8px!important;vertical-align:middle!important;" title="<?php echo esc_attr($b['name']); ?>"><?php echo esc_html($b['name']); ?></td>
                        <td class="cs-col-size" style="padding:6px 8px!important;vertical-align:middle!important;"><?php echo esc_html(cs_format_size($b['size'])); ?></td>
                        <td class="cs-col-meta cs-created" style="padding:6px 8px!important;vertical-align:middle!important;"><?php echo esc_html($b['date']); ?></td>
                        <td class="cs-col-age cs-age" style="padding:6px 8px!important;vertical-align:middle!important;"><?php echo esc_html(cs_human_age($b['mtime'])); ?></td>
                        <td class="cs-col-type" style="padding:6px 8px!important;vertical-align:middle!important;"><span class="cs-type-badge cs-type-<?php echo esc_attr(preg_replace('/[^a-z0-9]/', '', str_replace('+', 'plus', strtolower(explode(' ', $b['type'])[0])))); ?>"><?php echo esc_html($b['type']); ?></span></td>
                        <?php if ($s3_bucket): ?>
                        <td style="text-align:center;padding:4px 6px!important;vertical-align:middle!important;min-width:48px;">
                            <?php if (!$s3_entry): ?>
                                <button type="button"
                                        onclick="csS3SyncFile(this,'<?php echo esc_js($b['name']); ?>')"
                                        class="button button-small"
                                        style="font-size:10px;padding:1px 7px;height:auto;line-height:1.6;">
                                    ↑ Sync
                                </button>
                            <?php elseif ($s3_entry['ok']): ?>
                                <span title="Synced to S3 on <?php echo esc_attr(wp_date('j M Y H:i', $s3_entry['time'])); ?>" style="color:#2e7d32;font-size:16px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                                </span>
                            <?php elseif (!empty($s3_entry['retry_at']) && $s3_entry['retry_at'] > time()): ?>
                                <?php $mins = max(1, (int) ceil(($s3_entry['retry_at'] - time()) / 60)); ?>
                                <span style="color:#e65100;font-size:10px;" title="Sync failed — auto-retry in ~<?php echo (int) $mins; ?> min">
                                    ⏱ ~<?php echo (int) $mins; ?>m
                                </span>
                            <?php else: ?>
                                <div style="font-size:10px;color:#c62828;line-height:1.3;text-align:left;">
                                    <strong style="display:block;">&#10007; S3 failed</strong>
                                    <span style="word-break:break-all;display:block;margin-bottom:3px;"><?php echo esc_html(substr($s3_entry['error'] ?? 'Unknown error', 0, 80)); ?></span>
                                    <button type="button"
                                            onclick="csS3SyncFile(this,'<?php echo esc_js($b['name']); ?>')"
                                            class="button button-small"
                                            style="font-size:10px;padding:1px 7px;height:auto;line-height:1.6;">
                                        ↺ Retry
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- .cs-table-wrap -->
            <p class="cs-help cs-mt">Showing <?php echo (int) count($backups); ?> backup(s). Retention limit: <?php echo (int) $retention; ?>. Oldest backups are removed automatically after each run.</p>
            <?php endif; ?>
            <p class="cs-help" style="margin-top:6px;word-break:break-all;">&#128193; <?php esc_html_e( 'Stored at:', 'cloudscale-free-backup-and-restore' ); ?> <code id="cs-backup-path"><?php echo esc_html( CS_BACKUP_DIR ); ?></code> <button type="button" id="cs-copy-path" onclick="csCopyBackupPath()" style="margin-left:6px;padding:2px 8px;font-size:0.75rem;cursor:pointer;vertical-align:middle;" class="button button-small">Copy</button></p>
        </div>

        <!-- ===================== RESTORE FROM UPLOAD ===================== -->
        <div class="cs-section-ribbon"><span><?php esc_html_e( 'Restore from File', 'cloudscale-free-backup-and-restore' ); ?></span></div>
        <div class="cs-card cs-card--red cs-full">
            <div class="cs-card-stripe cs-stripe--red" style="background:linear-gradient(135deg,#b71c1c 0%,#e53935 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">↩ <?php echo esc_html__( 'Restore from Uploaded File', 'cloudscale-free-backup-and-restore' ); ?></h2><button type="button" onclick="csRestoreExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button></div>
            <div class="cs-restore-upload-grid">
                <div>
                    <p>Upload a <code>.zip</code> (from this plugin) or a raw <code>.sql</code> file to restore the database.</p>
                    <input type="file" id="cs-restore-file" accept=".zip,.sql" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">
                    <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
                        <label for="cs-restore-file" id="cs-restore-file-label" class="button" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;flex-shrink:0;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <?php esc_html_e( 'Choose File', 'cloudscale-free-backup-and-restore' ); ?>
                        </label>
                        <span id="cs-restore-file-name" style="font-size:0.85rem;color:#666;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php esc_html_e( 'No file chosen', 'cloudscale-free-backup-and-restore' ); ?></span>
                        <button type="button" id="cs-restore-upload-btn" class="button button-secondary" style="display:inline-flex;align-items:center;gap:6px;flex-shrink:0;margin-left:auto;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0"/><polyline points="9 12 12 9 15 12"/><line x1="12" y1="21" x2="12" y2="9"/></svg>
                            <?php esc_html_e( 'Restore from Upload', 'cloudscale-free-backup-and-restore' ); ?>
                        </button>
                    </div>
                </div>
                <div id="cs-restore-upload-progress" style="display:none" class="cs-progress-panel">
                    <p id="cs-restore-upload-msg" class="cs-progress-msg">Uploading...</p>
                    <div class="cs-progress-bar"><div id="cs-restore-upload-fill" class="cs-progress-fill"></div></div>
                </div>
            </div>
        </div>

        <!-- ===================== RESTORE MODAL ===================== -->

        <div id="cs-restore-modal" class="cs-modal" style="display:none">
            <div class="cs-modal-box">
                <h2>⚠ <?php esc_html_e( 'Restore Database', 'cloudscale-free-backup-and-restore' ); ?></h2>
                <div class="cs-modal-body">
                    <div class="cs-warning-box">
                        <strong><?php esc_html_e( 'BEFORE YOU RESTORE — TAKE A SNAPSHOT', 'cloudscale-free-backup-and-restore' ); ?></strong>
                        <ul>
                            <li>Take a server/VM snapshot in your hosting control panel or AWS console <strong>right now</strong>.</li>
                            <li>If anything goes wrong you can roll back to the snapshot instantly.</li>
                            <li>This restore will put the site into maintenance mode, drop and recreate all database tables, then bring the site back online.</li>
                            <li>Active sessions will be interrupted. Users will see a maintenance page.</li>
                        </ul>
                    </div>
                    <p>You are about to restore from:</p>
                    <p><strong id="cs-modal-filename"></strong> &nbsp;|&nbsp; Created: <span id="cs-modal-date"></span></p>
                    <div class="cs-confirm-check">
                        <label>
                            <input type="checkbox" id="cs-confirm-snapshot">
                            I have taken a server snapshot and understand this will overwrite the live database.
                        </label>
                    </div>
                </div>
                <div class="cs-modal-footer">
                    <button type="button" id="cs-modal-cancel" class="button"><?php esc_html_e( 'Cancel — keep current database', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <button type="button" id="cs-modal-confirm" class="button button-primary cs-btn-danger" disabled><?php esc_html_e( 'Restore Now', 'cloudscale-free-backup-and-restore' ); ?></button>
                </div>
                <div id="cs-modal-progress" style="display:none" class="cs-modal-progress">
                    <p id="cs-modal-progress-msg" class="cs-progress-msg">Enabling maintenance mode...</p>
                    <div class="cs-progress-bar"><div id="cs-modal-fill" class="cs-progress-fill"></div></div>
                    <p class="cs-help">Do not close this window.</p>
                </div>
            </div>
        </div>
        <div id="cs-modal-overlay" class="cs-modal-overlay" style="display:none"></div>

        </div><!-- /cs-tab-local -->

        <!-- ===================== CLOUD BACKUPS TAB ===================== -->
        <div id="cs-tab-cloud" class="cs-tab-panel" style="display:none">

            <div class="cs-section-ribbon"><span>Cloud Backup Settings</span></div>
            <div class="cs-grid cs-grid-1" style="display:flex!important;flex-direction:column!important;gap:16px!important;">

            <!-- CLOUD SCHEDULE CARD -->
            <div class="cs-card cs-card--blue">
                <div class="cs-card-stripe cs-stripe--blue" style="background:linear-gradient(135deg,#1565c0 0%,#2196f3 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">⏰ <?php echo esc_html__( 'Cloud Backup Settings', 'cloudscale-free-backup-and-restore' ); ?></h2><button type="button" onclick="csCloudScheduleExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button></div>

                <?php if (!$enabled && ($s3_sync_enabled || $gdrive_sync_enabled)): ?>
                <div style="background:#fff8e1;border:1px solid #f9a825;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:0.88rem;color:#5d4037;">
                    ⚠ <strong>Local backup schedule is disabled</strong> — S3 and Google Drive will sync the existing latest backup but no new backups will be created automatically. <a href="#cs-tab-local" onclick="jQuery('.cs-tab[data-tab=\'local\']').trigger(\'click\');return false;">Enable it on the Local Backups tab.</a>
                </div>
                <?php endif; ?>

                <div class="cs-field-group">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-cloud-schedule-enabled" <?php checked($cloud_schedule_enabled); ?>>
                        <?php esc_html_e( 'Enable automatic cloud backups', 'cloudscale-free-backup-and-restore' ); ?>
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
                        <p class="cs-help">On each selected day: AMI snapshot (if configured), then S3 sync, then Google Drive sync — in that order.</p>
                    </div>

                    <div class="cs-field-group">
                        <label class="cs-field-label" for="cs-cloud-backup-delay"><?php esc_html_e( 'Cloud Backup Delay', 'cloudscale-free-backup-and-restore' ); ?></label>
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
                        <div style="display:flex;flex-direction:column;gap:6px;margin-top:6px;">
                            <label class="cs-option-label" style="margin:0;">
                                <input type="checkbox" id="cs-cloud-ami-enabled" <?php checked($ami_sync_enabled); ?>>
                                &#128247; EC2 AMI Snapshot <span class="cs-sensitive-badge" style="margin-left:4px;">&#9888; reboot</span>
                            </label>
                            <label class="cs-option-label" style="margin:0;">
                                <input type="checkbox" id="cs-cloud-s3-enabled" <?php checked($s3_sync_enabled); ?>>
                                &#9729; S3 Remote Backup
                            </label>
                            <label class="cs-option-label" style="margin:0;">
                                <input type="checkbox" id="cs-cloud-gdrive-enabled" <?php checked($gdrive_sync_enabled); ?>>
                                &#128196; Google Drive Backup
                            </label>
                        </div>
                        <p class="cs-help">Each destination must also be configured. AMI requires AWS CLI and a prefix in the EC2 settings below.</p>
                    </div>

                    <div class="cs-field-group" style="border-top:1px solid #e0e0e0;padding-top:14px;margin-top:4px;">
                        <label class="cs-field-label" for="cs-ami-max"><strong><?php esc_html_e( 'Max Cloud Backups to Keep', 'cloudscale-free-backup-and-restore' ); ?></strong></label>
                        <div class="cs-inline" style="margin-top:4px;">
                            <input type="number" id="cs-ami-max" class="cs-input-sm" min="1" max="999"
                                   value="<?php echo esc_attr($ami_max); ?>" style="width:80px;">
                            <span class="cs-muted-text">backups per destination</span>
                        </div>
                        <p class="cs-help">Applies to S3, Google Drive, and AMI snapshots. Oldest are deleted automatically when this limit is exceeded.</p>
                    </div>

                </fieldset>

                <div id="cs-cloud-off-notice" class="cs-off-notice" <?php echo $cloud_schedule_enabled ? 'style="display:none"' : ''; ?>>
                    Automatic cloud backups are <strong>off</strong>. Enable the checkbox above to configure a schedule.
                </div>

                <button type="button" onclick="csCloudScheduleSave()" class="button button-primary cs-mt"><?php esc_html_e( 'Save Schedule', 'cloudscale-free-backup-and-restore' ); ?></button>
                <span id="cs-cloud-schedule-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
            </div>

            <!-- S3 REMOTE BACKUP CARD -->
            <div class="cs-card cs-card--pink">
                <div class="cs-card-stripe cs-stripe--pink" style="background:linear-gradient(135deg,#880e4f 0%,#e91e8c 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#9729; <?php echo esc_html__( 'S3 Remote Backup', 'cloudscale-free-backup-and-restore' ); ?></h2>
                    <button type="button" onclick="csS3Explain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button>
                </div>

                <p class="cs-help">After each local backup, the latest zip is automatically synced to your S3 bucket using the AWS CLI. You can also push the latest local backup to S3 manually at any time.</p>

                <div class="cs-field-row cs-mt">
                    <label for="cs-s3-bucket"><strong>S3 Bucket name</strong></label>
                    <input type="text" id="cs-s3-bucket" class="regular-text" placeholder="my-bucket-name"
                           value="<?php echo esc_attr($s3_bucket); ?>">
                    <p class="cs-help">Bucket name only &mdash; no <code>s3://</code> prefix.</p>
                </div>

                <div class="cs-field-row">
                    <label for="cs-s3-prefix"><strong>Key prefix (folder)</strong></label>
                    <input type="text" id="cs-s3-prefix" class="regular-text" placeholder="backups/"
                           value="<?php echo esc_attr($s3_prefix); ?>">
                    <p class="cs-help">Trailing slash required. Leave as <code>backups/</code> or set to <code>/</code> for bucket root.</p>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" onclick="csS3Save()" class="button button-primary"><?php esc_html_e( 'Save S3 Settings', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <button type="button" onclick="csS3Test()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Test Connection', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <button type="button" onclick="csS3Diagnose()" class="button" style="margin-left:8px;background:#e65100!important;color:#fff!important;border-color:#bf360c!important;"><?php esc_html_e( 'Diagnose', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <button type="button" onclick="csS3SyncLatest()" class="button" style="margin-left:8px;background:#6a1b9a!important;color:#fff!important;border-color:#4a148c!important;"><?php esc_html_e( 'Sync Local Backup Now', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <span id="cs-s3-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
                </div>

                <?php if ($s3_bucket): ?>
                <div class="cs-info-row cs-mt">
                    <span>Destination</span>
                    <strong><code>s3://<?php echo esc_html(rtrim($s3_bucket, '/') . '/' . ltrim($s3_prefix, '/')); ?></code></strong>
                </div>
                <div class="cs-info-row">
                    <span>Backups in S3</span>
                    <strong><?php echo (int) count($s3_synced); ?> of <?php echo (int) count($backups); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Last S3 sync</span>
                    <strong><?php echo $s3_last ? esc_html(cs_human_age($s3_last) . ' ago (' . wp_date('j M Y H:i', $s3_last) . ')') : 'Never'; ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <!-- GOOGLE DRIVE BACKUP CARD -->
            <div class="cs-card cs-card--gdrive">
                <div class="cs-card-stripe" style="background:linear-gradient(135deg,#0f9d58 0%,#34a853 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128196; <?php echo esc_html__( 'Google Drive Backup', 'cloudscale-free-backup-and-restore' ); ?></h2>
                    <button type="button" onclick="csGDriveExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button>
                </div>

                <p class="cs-help">After each local backup, the latest zip is automatically copied to Google Drive via <code>rclone</code>. You can also push the latest local backup manually at any time.</p>

                <div class="cs-field-row cs-mt">
                    <label for="cs-gdrive-remote"><strong>rclone remote name</strong></label>
                    <input type="text" id="cs-gdrive-remote" class="regular-text" placeholder="gdrive"
                           value="<?php echo esc_attr($gdrive_remote); ?>">
                    <p class="cs-help">The remote name you gave when running <code>rclone config</code>, e.g. <code>gdrive</code>.</p>
                </div>

                <div class="cs-field-row">
                    <label for="cs-gdrive-path"><strong>Destination folder</strong></label>
                    <input type="text" id="cs-gdrive-path" class="regular-text" placeholder="cloudscale-backups/"
                           value="<?php echo esc_attr($gdrive_path); ?>">
                    <p class="cs-help">Folder path inside the Drive. Trailing slash required. Leave blank to copy to the root.</p>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" onclick="csGDriveSave()" class="button button-primary"><?php esc_html_e( 'Save Drive Settings', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <button type="button" onclick="csGDriveTest()" class="button" style="margin-left:8px;background:#2e7d32!important;color:#fff!important;border-color:#1b5e20!important;"><?php esc_html_e( 'Test Connection', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <button type="button" onclick="csGDriveDiagnose()" class="button" style="margin-left:8px;background:#e65100!important;color:#fff!important;border-color:#bf360c!important;"><?php esc_html_e( 'Diagnose', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <button type="button" onclick="csGDriveSyncLatest()" class="button" style="margin-left:8px;background:#6a1b9a!important;color:#fff!important;border-color:#4a148c!important;"><?php esc_html_e( 'Sync Local Backup Now', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <span id="cs-gdrive-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
                </div>

                <?php if ($gdrive_remote): ?>
                <div class="cs-info-row cs-mt">
                    <span>Destination</span>
                    <strong><code><?php echo esc_html(rtrim($gdrive_remote, ':') . ':' . ltrim($gdrive_path, '/')); ?></code></strong>
                </div>
                <div class="cs-info-row">
                    <span>Backups synced</span>
                    <strong><?php echo (int) count($gdrive_synced); ?> of <?php echo (int) count($backups); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Last sync</span>
                    <strong><?php echo $gdrive_last ? esc_html(cs_human_age($gdrive_last) . ' ago (' . wp_date('j M Y H:i', $gdrive_last) . ')') : 'Never'; ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <!-- AMI SNAPSHOT CARD -->
            <div class="cs-card cs-card--indigo">
                <div class="cs-card-stripe cs-stripe--indigo" style="background:linear-gradient(135deg,#1a237e 0%,#3949ab 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128247; <?php echo esc_html__( 'EC2 AMI Snapshot', 'cloudscale-free-backup-and-restore' ); ?></h2>
                    <button type="button" onclick="csAmiExplain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button>
                </div>

                <p class="cs-help">Create a full machine image (AMI) of this EC2 instance. The AMI name will be <code>{prefix}_yyyyMMdd_HHmm</code>. Requires AWS CLI with <code>ec2:CreateImage</code>, <code>ec2:DescribeImages</code>, <code>ec2:DeregisterImage</code> and <code>ec2:RebootInstances</code> permissions.</p>

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
                    <strong><?php echo $ami_region ? esc_html($ami_region) : '<span style="color:#999">Unknown — set override below</span>'; ?></strong>
                </div>

                <div class="cs-field-row cs-mt">
                    <label for="cs-ami-region-override"><strong>Region override</strong></label>
                    <input type="text" id="cs-ami-region-override" class="cs-input-sm" placeholder="e.g. af-south-1"
                           value="<?php echo esc_attr($ami_region_override); ?>" style="width:calc(20em - 50px);min-width:140px;">
                    <p class="cs-help">Set this if the region shown above is wrong or Unknown. Bypasses IMDS detection entirely. Example: <code>af-south-1</code></p>
                </div>

                <div class="cs-field-row cs-mt">
                    <div style="display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap;">
                        <div>
                            <label for="cs-ami-prefix"><strong>AMI name prefix</strong></label>
                            <input type="text" id="cs-ami-prefix" placeholder="mysite-backup"
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
                    <button type="button" onclick="csAmiSave()" class="button button-primary"><?php esc_html_e( 'Save AMI Settings', 'cloudscale-free-backup-and-restore' ); ?></button>
                    <button type="button" onclick="csAmiCreate()" class="button" style="margin-left:8px;background:#1a237e!important;color:#fff!important;border-color:#1a237e!important;" <?php echo $ami_instance_id ? '' : 'disabled title="EC2 instance not detected"'; ?>>&#128247; Create AMI Now</button>
                    <button type="button" onclick="csAmiResetAndRefresh()" class="button" id="cs-ami-refresh-all" style="margin-left:4px;background:#c2185b!important;color:#fff!important;border-color:#880e4f!important;">&#8635; Refresh All</button>
                    <span id="cs-ami-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
                </div>

                <?php if (!empty($ami_log_recent)): ?>
                <div class="cs-mt" style="margin-top:16px;">
                    <p class="cs-field-label" style="margin-bottom:6px;"><?php esc_html_e( 'Recent AMI snapshots', 'cloudscale-free-backup-and-restore' ); ?></p>
                    <div class="cs-table-wrap">
                    <table class="widefat cs-table" style="font-size:0.82rem;">
                        <thead>
                            <tr>
                                <th>AMI Name</th>
                                <th style="width:130px;">AMI ID</th>
                                <th style="width:110px;">Created</th>
                                <th style="width:80px;">Status</th>
                                <th style="width:140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="cs-ami-tbody">
                        <?php foreach ($ami_log_recent as $entry): ?>
                            <?php
                            $row_ami_id  = esc_attr($entry['ami_id'] ?? '');
                            $entry_state = $entry['state'] ?? '';
                            $is_deleted  = ($entry_state === 'deleted in AWS');
                            $is_ok       = !empty($entry['ok']);
                            ?>
                            <tr id="cs-ami-row-<?php echo esc_attr( $row_ami_id ); ?>">
                                <?php $faded = $is_deleted ? 'style="opacity:0.45;"' : ''; ?>
                                <td <?php echo $faded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe HTML attribute string ?>><?php echo esc_html($entry['name'] ?? '—'); ?></td>
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
                                <td id="cs-ami-actions-<?php echo esc_attr( $row_ami_id ); ?>">
                                    <?php if (!empty($entry['ami_id'])): ?>
                                    <?php if (!$is_deleted): ?>
                                    <button type="button" onclick="csAmiRefreshOne('<?php echo esc_js( $row_ami_id ); ?>')" class="button button-small" title="Refresh this AMI state from AWS" style="min-width:0;padding:2px 6px;margin-bottom:3px;"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></button>
                                    <?php endif; ?>
                                    <button type="button" onclick="csAmiDelete('<?php echo esc_js( $row_ami_id ); ?>', '<?php echo esc_js($entry['name'] ?? ''); ?>', <?php echo $is_deleted ? 'true' : 'false'; ?>)" class="button button-small" title="<?php echo $is_deleted ? 'Remove record' : 'Deregister AMI'; ?>" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; <?php echo $is_deleted ? 'Remove' : 'Delete'; ?></button>
                                    <?php else: ?>
                                    <button type="button" onclick="csAmiRemoveFailed('<?php echo esc_js($entry['name'] ?? ''); ?>')" class="button button-small" title="Remove failed record" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Remove</button>
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

            </div><!-- /cs-grid-1 cloud -->

        </div><!-- /cs-tab-cloud -->

    </div><!-- /cs-wrap -->
    <?php
}

// ============================================================
// AJAX — Run backup
// ============================================================

add_action('wp_ajax_cs_run_backup', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    cs_ensure_backup_dir();

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified via cs_verify_nonce() above
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
        $filename = cs_create_backup(
            $include_db, $include_media, $include_plugins, $include_themes,
            $include_mu, $include_languages, $include_dropins, $include_htaccess, $include_wpconfig
        );
        cs_enforce_retention();
        $s3     = $GLOBALS['cs_last_s3_result']     ?? ['skipped' => true];
        $gdrive = $GLOBALS['cs_last_gdrive_result'] ?? ['skipped' => true];
        $s3_msg = '';
        if (!isset($s3['skipped'])) {
            $s3_msg = $s3['ok']
                ? '✓ Synced to ' . $s3['dest']
                : '⚠ S3 sync failed: ' . $s3['error'];
        }
        $gdrive_msg = '';
        if (!isset($gdrive['skipped'])) {
            $gdrive_msg = $gdrive['ok']
                ? '✓ Synced to Drive: ' . $gdrive['dest']
                : '⚠ Drive sync failed: ' . $gdrive['error'];
        }
        wp_send_json_success([
            'message'    => 'Backup complete: ' . $filename,
            'filename'   => $filename,
            's3_ok'      => $s3['ok'] ?? null,
            's3_msg'     => $s3_msg,
            'gdrive_ok'  => $gdrive['ok'] ?? null,
            'gdrive_msg' => $gdrive_msg,
        ]);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// ============================================================
// AJAX — Delete backup
// ============================================================

add_action('wp_ajax_cs_delete_backup', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $file = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
    $path = CS_BACKUP_DIR . $file;
    if (file_exists($path) && strpos(realpath($path), realpath(CS_BACKUP_DIR)) === 0) {
        wp_delete_file( $path );
        wp_send_json_success('Deleted.');
    }
    wp_send_json_error('File not found.');
});

// ============================================================
// AJAX — Restore from stored backup
// ============================================================

add_action('wp_ajax_cs_restore_backup', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort(true);

    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $file = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
    $path = CS_BACKUP_DIR . $file;

    if (!file_exists($path) || strpos(realpath($path), realpath(CS_BACKUP_DIR)) !== 0) {
        wp_send_json_error('Backup file not found.');
    }

    try {
        cs_maintenance_on();
        cs_restore_from_zip($path);
        cs_maintenance_off();
        wp_send_json_success('Database restored successfully. Maintenance mode disabled. Site is back online.');
    } catch (Exception $e) {
        cs_maintenance_off(); // Always remove maintenance mode even on failure
        wp_send_json_error('Restore failed: ' . $e->getMessage() . ' — Maintenance mode has been disabled.');
    }
});

// ============================================================
// AJAX — Restore from upload
// ============================================================

add_action('wp_ajax_cs_restore_upload', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort(true);

    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- nonce verified via cs_verify_nonce(); tmp_name is a server path, not user input; file extension is validated below
    if (empty($_FILES['backup_file'])) {
        wp_send_json_error('No file uploaded.');
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    $ext = strtolower(pathinfo(sanitize_file_name( wp_unslash( $_FILES['backup_file']['name'] ) ), PATHINFO_EXTENSION));
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

    if (!in_array($ext, ['zip', 'sql'], true)) {
        wp_send_json_error('Only .zip or .sql files accepted.');
    }

    try {
        cs_maintenance_on();
        if ($ext === 'zip') {
            cs_restore_from_zip($tmp);
        } else {
            cs_restore_sql_file($tmp);
        }
        cs_maintenance_off();
        wp_send_json_success('Database restored. Maintenance mode disabled. Site is back online.');
    } catch (Exception $e) {
        cs_maintenance_off();
        wp_send_json_error('Restore failed: ' . $e->getMessage());
    }
});

// Schedule is now saved via plain HTML form POST (see page handler above)

// ============================================================
// AJAX — Save manual backup defaults
// ============================================================

add_action('wp_ajax_cs_save_manual_defaults', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    $valid = ['db', 'media', 'plugins', 'themes', 'mu', 'languages', 'dropins', 'htaccess', 'wpconfig'];
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via cs_verify_nonce(); values validated via array_intersect() against a whitelist
    $raw   = isset($_POST['components']) && is_array($_POST['components']) ? wp_unslash( $_POST['components'] ) : [];
    $clean = array_values(array_intersect($raw, $valid));
    update_option('cs_manual_defaults', $clean);
    wp_send_json_success('Defaults saved');
});

// ============================================================
// AJAX — Save retention
// ============================================================

add_action('wp_ajax_cs_test_s3', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    $bucket = get_option('cs_s3_bucket', '');
    $prefix = get_option('cs_s3_prefix', 'backups/');
    if (!$bucket) {
        wp_send_json_error('No bucket configured. Save your S3 settings first.');
    }
    $aws = cs_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found on server.');
    }
    $prefix = trim($prefix, '/');
    $dest   = 's3://' . rtrim($bucket, '/') . '/' . ($prefix ? $prefix . '/' : '') . 'cloudscale-test.txt';
    $content = "CloudScale Backup connection test\nWritten: " . gmdate('Y-m-d H:i:s T') . "\nBucket: " . $bucket . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'cs_test_');
    file_put_contents($tmp, $content);
    $real_tmp = realpath($tmp) ?: $tmp;
    $cmd = escapeshellarg($aws) . ' s3 cp ' . escapeshellarg($real_tmp) . ' ' . escapeshellarg($dest) . ' 2>&1';
    $out = trim((string) shell_exec($cmd));
    wp_delete_file($tmp);
    // AWS CLI outputs nothing on success with --only-show-errors, but without that flag
    // it outputs "upload: /path to s3://..." which is a success message not an error
    if ($out && stripos($out, 'upload:') === false && stripos($out, 'completed') === false) {
        wp_send_json_error('Upload failed: ' . $out);
    }
    wp_send_json_success('Test file written to ' . $dest);
});

add_action('wp_ajax_cs_save_s3', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $bucket = sanitize_text_field( wp_unslash( $_POST['bucket'] ?? '' ) );
    $prefix = sanitize_text_field( wp_unslash( $_POST['prefix'] ?? 'backups/' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    // Ensure prefix ends with /
    $prefix = rtrim($prefix, '/') . '/';
    update_option('cs_s3_bucket', $bucket);
    update_option('cs_s3_prefix', $prefix);
    wp_send_json_success(['bucket' => $bucket, 'prefix' => $prefix]);
});

// S3 auto-retry — single WP-Cron event scheduled 5 min after a failed sync
add_action('cs_s3_retry', function (string $filename): void {
    $path = CS_BACKUP_DIR . $filename;
    if (!file_exists($path)) {
        cs_log('[CloudScale Backup] S3 retry: file no longer exists: ' . $filename);
        return;
    }
    cs_log('[CloudScale Backup] S3 retry attempt for ' . $filename);
    cs_sync_to_s3($path, false); // false = no further retries on second failure
});

add_action('wp_ajax_cs_s3_sync_file', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
    if (!$filename || !str_ends_with($filename, '.zip')) {
        wp_send_json_error('Invalid filename.');
    }
    $path = CS_BACKUP_DIR . $filename;
    if (!file_exists($path)) {
        wp_send_json_error('File not found on server.');
    }
    $result = cs_sync_to_s3($path);
    if (isset($result['skipped'])) {
        wp_send_json_error('S3 not configured — set a bucket name in the S3 Remote Backup settings.');
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

add_action('wp_ajax_cs_save_ami', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $prefix          = sanitize_text_field( wp_unslash( $_POST['prefix'] ?? '' ) );
    $reboot          = !empty( $_POST['reboot'] );
    $region_override = sanitize_text_field( wp_unslash( $_POST['region_override'] ?? '' ) );
    $ami_max         = max(1, min(999, intval( $_POST['ami_max'] ?? 10 )));
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    // Validate region format if provided: letters, digits, hyphens only
    if ($region_override && !preg_match('/^[a-z0-9-]+$/', $region_override)) {
        wp_send_json_error('Invalid region format. Example: af-south-1');
    }
    update_option('cs_ami_prefix', $prefix);
    update_option('cs_ami_reboot', $reboot);
    update_option('cs_ami_region_override', $region_override);
    update_option('cs_ami_max', $ami_max);
    wp_send_json_success(['prefix' => $prefix, 'reboot' => $reboot, 'region' => $region_override, 'ami_max' => $ami_max]);
});

add_action('wp_ajax_cs_save_cloud_schedule', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    cs_verify_nonce();
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $raw_days   = array_filter(explode(',', sanitize_text_field( wp_unslash( $_POST['ami_schedule_days'] ?? '' ) )));
    $clean_days = array_values(array_filter(array_map('intval', $raw_days), fn($d) => $d >= 1 && $d <= 7));
    update_option('cs_ami_schedule_days',   $clean_days);
    update_option('cs_cloud_backup_delay',  max(15, intval( $_POST['cloud_backup_delay'] ?? 30 )));
    update_option('cs_ami_max',             max(1, min(999, intval( $_POST['cloud_max'] ?? 10 ))));
    update_option('cs_s3_sync_enabled',        !empty($_POST['s3_sync_enabled']));
    update_option('cs_gdrive_sync_enabled',    !empty($_POST['gdrive_sync_enabled']));
    update_option('cs_ami_sync_enabled',       !empty($_POST['ami_sync_enabled']));
    update_option('cs_cloud_schedule_enabled', !empty($_POST['cloud_schedule_enabled']));
    wp_cache_delete('cs_ami_schedule_days',      'options');
    wp_cache_delete('cs_cloud_backup_delay',     'options');
    wp_cache_delete('cs_ami_max',                'options');
    wp_cache_delete('cs_s3_sync_enabled',        'options');
    wp_cache_delete('cs_gdrive_sync_enabled',    'options');
    wp_cache_delete('cs_ami_sync_enabled',       'options');
    wp_cache_delete('cs_cloud_schedule_enabled', 'options');
    wp_cache_delete('alloptions',                'options');
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    cs_reschedule();
    wp_send_json_success('Saved');
});

add_action('wp_ajax_cs_save_gdrive', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    cs_verify_nonce();
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $remote = sanitize_text_field( wp_unslash( $_POST['remote'] ?? '' ) );
    $path   = sanitize_text_field( wp_unslash( $_POST['path']   ?? 'cloudscale-backups/' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    if ($remote && !preg_match('/^[a-zA-Z0-9_\-]+$/', $remote)) {
        wp_send_json_error('Invalid remote name. Use only letters, numbers, hyphens and underscores.');
    }
    update_option('cs_gdrive_remote', $remote);
    update_option('cs_gdrive_path',   $path);
    wp_send_json_success(['remote' => $remote, 'path' => $path]);
});

add_action('wp_ajax_cs_test_gdrive', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    cs_verify_nonce();
    $remote = get_option('cs_gdrive_remote', '');
    if (!$remote) { wp_send_json_error('No remote configured — save settings first.'); }
    $rclone = cs_find_rclone();
    if (!$rclone) { wp_send_json_error('rclone not found on server.'); }
    $cmd = escapeshellarg($rclone) . ' lsd ' . escapeshellarg($remote . ':') . ' --max-depth 1 2>&1';
    $out = trim((string) shell_exec($cmd));
    if ($out && preg_match('/error|failed|denied|invalid/i', $out)) {
        wp_send_json_error('Connection failed: ' . substr($out, 0, 200));
    }
    wp_send_json_success('Connected to ' . esc_html($remote));
});

add_action('wp_ajax_cs_sync_latest_s3', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    cs_verify_nonce();
    $latest = cs_get_latest_backup_path();
    if (!$latest) { wp_send_json_error('No local backups found.'); }
    $result = cs_sync_to_s3($latest);
    if (isset($result['skipped'])) { wp_send_json_error('S3 not configured — save bucket settings first.'); }
    $result['ok'] ? wp_send_json_success('Synced: ' . basename($latest)) : wp_send_json_error($result['error'] ?? 'Sync failed.');
});

add_action('wp_ajax_cs_sync_latest_gdrive', function (): void {
    if (!current_user_can('manage_options')) { wp_send_json_error('Forbidden', 403); }
    cs_verify_nonce();
    $latest = cs_get_latest_backup_path();
    if (!$latest) { wp_send_json_error('No local backups found.'); }
    $result = cs_sync_to_gdrive($latest);
    if (isset($result['skipped'])) { wp_send_json_error('Google Drive not configured — save remote settings first.'); }
    $result['ok'] ? wp_send_json_success('Synced: ' . basename($latest)) : wp_send_json_error($result['error'] ?? 'Sync failed.');
});

add_action('wp_ajax_cs_create_ami', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    set_time_limit(120); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to allow AMI create-image API call to complete

    $instance_id = cs_get_instance_id();
    if (!$instance_id) {
        wp_send_json_error('Cannot detect EC2 instance ID. Is this server running on EC2 with IMDS enabled?');
    }

    $aws = cs_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found on server.');
    }

    $prefix = get_option('cs_ami_prefix', '');
    if (!$prefix) {
        wp_send_json_error('Set an AMI name prefix in settings first.');
    }

    $reboot  = (bool) get_option('cs_ami_reboot', false);
    $region  = cs_get_instance_region();
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

    $out = trim((string) shell_exec($cmd));

    // Parse the JSON response to get AMI ID
    $result = json_decode($out, true);
    $ami_id = $result['ImageId'] ?? null;

    $log = (array) get_option('cs_ami_log', []);

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
        // Enforce cs_ami_max — deregister and prune oldest successful entries beyond the limit
        $log = cs_ami_enforce_max($log, $aws, $region);

        $msg = 'AMI creation started: ' . $ami_id . ' (' . $ami_name . ')';
        if ($reboot) $msg .= ' — instance will reboot shortly';

        // Schedule the first state poll in 10 minutes if not already queued
        if (!wp_next_scheduled('cs_ami_poll')) {
            wp_schedule_single_event(time() + CS_BACKUP_AMI_POLL_INTERVAL, 'cs_ami_poll');
            cs_log('[CloudScale Backup] AMI poll: first check scheduled in ' . (CS_BACKUP_AMI_POLL_INTERVAL / 60) . ' min for ' . $ami_id);
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
        update_option('cs_ami_log', $log, false);

        cs_log('[CloudScale Backup] AMI creation failed: ' . $out);
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
function cs_ami_enforce_max(array $log, string $aws, string $region): array {
    $ami_max      = max(1, intval(get_option('cs_ami_max', 10)));
    $ok_entries   = array_values(array_filter($log, fn($e) => !empty($e['ok']) && !empty($e['ami_id'])));
    $fail_entries = array_values(array_filter($log, fn($e) =>  empty($e['ok']) ||  empty($e['ami_id'])));

    // Sort oldest first
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
            $out = trim((string) shell_exec($cmd));
            // deregister-image returns empty output on success.
            // Also treat "InvalidAMIID.NotFound" as success — already gone from AWS.
            $already_gone = str_contains($out, 'InvalidAMIID') || str_contains($out, 'does not exist');
            if ($out === '' || $already_gone) {
                // Deregistered (or already gone) — drop local record
                cs_log('[CloudScale Backup] AMI auto-deregistered: ' . $ami_id . ' (' . ($old['name'] ?? '') . ')');
            } else {
                // Deregistration failed — still drop from local log to enforce the count limit,
                // but mark it so the user knows manual cleanup in AWS may be needed.
                cs_log('[CloudScale Backup] AMI auto-deregister FAILED for ' . $ami_id . ': ' . $out . ' — removed from local log; may need manual removal in AWS console');
            }
        }

        $ok_entries = $keep;
    }

    // Keep at most 20 failed entries
    $fail_entries = array_slice($fail_entries, -20);
    $log = array_values(array_merge($ok_entries, $fail_entries));
    update_option('cs_ami_log', $log, false);
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
function cs_do_create_ami(): array {
    $instance_id = cs_get_instance_id();
    if (!$instance_id) {
        return ['ok' => false, 'ami_id' => null, 'name' => '', 'error' => 'EC2 instance ID not detected.'];
    }

    $aws = cs_find_aws();
    if (!$aws) {
        return ['ok' => false, 'ami_id' => null, 'name' => '', 'error' => 'AWS CLI not found on server.'];
    }

    $prefix = get_option('cs_ami_prefix', '');
    if (!$prefix) {
        return ['ok' => false, 'ami_id' => null, 'name' => '', 'error' => 'No AMI name prefix configured.'];
    }

    $reboot  = (bool) get_option('cs_ami_reboot', false);
    $region  = cs_get_instance_region();
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

    $out    = trim((string) shell_exec($cmd));
    $result = json_decode($out, true);
    $ami_id = $result['ImageId'] ?? null;

    $log = (array) get_option('cs_ami_log', []);

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
        cs_ami_enforce_max($log, $aws, $region);

        if (!wp_next_scheduled('cs_ami_poll')) {
            wp_schedule_single_event(time() + CS_BACKUP_AMI_POLL_INTERVAL, 'cs_ami_poll');
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
        update_option('cs_ami_log', $log, false);

        return ['ok' => false, 'ami_id' => null, 'name' => $ami_name, 'error' => $out];
    }
}

add_action('wp_ajax_cs_ami_status', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();

    $aws = cs_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found.');
    }

    // Accept optional specific AMI ID for per-row refresh
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $specific_ami = isset($_POST['ami_id']) ? sanitize_text_field( wp_unslash( $_POST['ami_id'] ) ) : '';

    $log = (array) get_option('cs_ami_log', []);

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

    $region = cs_get_instance_region();
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $cmd = escapeshellarg($aws) . ' ec2 describe-images'
         . ' --image-ids ' . escapeshellarg($target['ami_id'])
         . $region_flag
         . ' --query "Images[0].State"'
         . ' --output text 2>&1';

    $state = trim((string) shell_exec($cmd));

    if ($state && !str_contains($state, 'error') && !str_contains($state, 'Error')) {
        // Update the log entry state
        foreach ($log as &$e) {
            if (($e['ami_id'] ?? '') === $target['ami_id']) {
                $e['state'] = $state;
            }
        }
        unset($e);
        update_option('cs_ami_log', $log, false);

        if ($specific_ami) {
            wp_send_json_success(['ami_id' => $target['ami_id'], 'name' => $target['name'] ?? '', 'state' => $state]);
        } else {
            wp_send_json_success($target['ami_id'] . ' (' . ($target['name'] ?? '') . ') — state: ' . $state);
        }
    } else {
        wp_send_json_error('Could not query AMI status: ' . $state);
    }
});

add_action('wp_ajax_cs_deregister_ami', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();

    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $ami_id = sanitize_text_field( wp_unslash( $_POST['ami_id'] ?? '' ) );
    if (!$ami_id || !preg_match('/^ami-[a-f0-9]+$/', $ami_id)) {
        wp_send_json_error('Invalid AMI ID.');
    }

    $aws = cs_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found.');
    }

    $region = cs_get_instance_region();
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $cmd = escapeshellarg($aws) . ' ec2 deregister-image'
         . ' --image-id ' . escapeshellarg($ami_id)
         . $region_flag
         . ' 2>&1';

    $out = trim((string) shell_exec($cmd));

    // deregister-image returns empty output on success
    if ($out === '' || str_contains($out, '"return": true') || str_contains($out, 'Return')) {
        // Mark as pending_delete in log and schedule a check in 15 minutes
        $log = (array) get_option('cs_ami_log', []);
        foreach ($log as &$entry) {
            if (($entry['ami_id'] ?? '') === $ami_id) {
                $entry['state'] = 'pending_delete';
                break;
            }
        }
        unset($entry);
        update_option('cs_ami_log', $log, false);

        // Schedule a cron check in 15 minutes to confirm deletion and clean up
        if (!wp_next_scheduled('cs_ami_delete_check', [$ami_id])) {
            wp_schedule_single_event(time() + 900, 'cs_ami_delete_check', [$ami_id]);
        }

        wp_send_json_success('AMI ' . $ami_id . ' deregistered. Status will update automatically in 15 minutes.');
    } else {
        cs_log('[CloudScale Backup] AMI deregister failed: ' . $out);
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
 * Scheduled by the cs_deregister_ami AJAX handler immediately after a successful deregister call.
 *
 * @since 3.2.54
 * @param string $ami_id The AWS AMI ID to check (e.g. 'ami-0abc1234').
 * @return void
 */
add_action('cs_ami_delete_check', function (string $ami_id): void {
    $log = (array) get_option('cs_ami_log', []);

    // Find the entry
    $entry_idx = null;
    foreach ($log as $i => $e) {
        if (($e['ami_id'] ?? '') === $ami_id) {
            $entry_idx = $i;
            break;
        }
    }
    if ($entry_idx === null) return; // already removed from log

    $aws = cs_find_aws();
    if (!$aws) {
        cs_log('[CloudScale Backup] AMI delete check: AWS CLI not found for ' . $ami_id);
        return;
    }

    $region      = cs_get_instance_region();
    $region_flag = $region ? ' --region ' . escapeshellarg($region) : '';
    $cmd = escapeshellarg($aws) . ' ec2 describe-images --image-ids ' . escapeshellarg($ami_id) . $region_flag . ' --output json 2>&1';
    $out  = trim((string) shell_exec($cmd));
    $data = json_decode($out, true);

    if (empty($data['Images'])) {
        // Gone from AWS — remove from local log
        $log = array_values(array_filter($log, fn($e) => ($e['ami_id'] ?? '') !== $ami_id));
        cs_log('[CloudScale Backup] AMI delete confirmed, removed from log: ' . $ami_id);
    } else {
        // Still exists — update state from AWS
        $log[$entry_idx]['state'] = $data['Images'][0]['State'] ?? 'unknown';
        cs_log('[CloudScale Backup] AMI delete check: ' . $ami_id . ' state = ' . $log[$entry_idx]['state']);
    }

    update_option('cs_ami_log', $log, false);
});

// ============================================================
// AJAX — Refresh all AMI states (one at a time, 2s gap between calls)
// ============================================================

add_action('wp_ajax_cs_ami_refresh_all', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups

    $aws = cs_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found.');
    }

    // Read directly from DB — bypasses object cache which may serve stale state
    // after cs_ami_reset_deleted has just written new values
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bypasses object cache intentionally; post-reset read must be authoritative
    $raw_log = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'cs_ami_log' ) );
    $log     = $raw_log ? (array) maybe_unserialize($raw_log) : [];

    $region      = cs_get_instance_region();
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

        $out    = trim((string) shell_exec($cmd));
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

        cs_log('[CloudScale Backup] Refresh All: ' . $ami_id . ' → ' . $new_state);

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
    update_option('cs_ami_log', $log, false);

    wp_send_json_success(['results' => $results]);
});

// ============================================================
// AJAX — Reset all 'deleted in AWS' log entries back to 'pending' for re-query
// ============================================================

add_action('wp_ajax_cs_ami_reset_deleted', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    $log     = (array) get_option('cs_ami_log', []);
    $reset   = 0;
    foreach ($log as &$entry) {
        if (!empty($entry['ami_id']) && ($entry['state'] ?? '') === 'deleted in AWS') {
            $entry['state'] = 'pending';
            $entry['ok']    = true;
            $reset++;
        }
    }
    unset($entry);
    update_option('cs_ami_log', $log, false);
    wp_send_json_success(['reset' => $reset]);
});

// ============================================================
// AJAX — Remove a log record (for AMIs already deleted in AWS)
// ============================================================

add_action('wp_ajax_cs_ami_remove_record', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();

    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $ami_id = sanitize_text_field( wp_unslash( $_POST['ami_id'] ?? '' ) );
    if (!$ami_id || !preg_match('/^ami-[a-f0-9]+$/', $ami_id)) {
        wp_send_json_error('Invalid AMI ID.');
    }

    $log     = (array) get_option('cs_ami_log', []);
    $updated = array_values(array_filter($log, fn($e) => ($e['ami_id'] ?? '') !== $ami_id));

    update_option('cs_ami_log', $updated, false);
    wp_send_json_success('Record removed.');
});

add_action('wp_ajax_cs_ami_remove_failed', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    if (!$name) {
        wp_send_json_error('No name provided.');
    }
    $log     = (array) get_option('cs_ami_log', []);
    $updated = array_values(array_filter($log, fn($e) => ($e['name'] ?? '') !== $name || !empty($e['ami_id'])));
    if (count($updated) === count($log)) {
        wp_send_json_error('Record not found.');
    }
    update_option('cs_ami_log', $updated, false);
    wp_send_json_success('Record removed.');
});

add_action('wp_ajax_cs_save_retention', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }
    cs_verify_nonce();
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified via cs_verify_nonce() above
    $r = max(1, min(9999, intval( $_POST['retention'] ?? 8 )));
    update_option('cs_retention', $r);
    $prefix = sanitize_key( wp_unslash( $_POST['backup_prefix'] ?? 'bkup' ) ) ?: 'bkup';
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    update_option('cs_backup_prefix', $prefix);
    wp_send_json_success('Saved');
});

// ============================================================
// Download handler
// ============================================================

add_action('admin_post_cs_download', function (): void {
    check_admin_referer('cs_download');
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Forbidden', 'cloudscale-free-backup-and-restore' ) );
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- GET param sanitised immediately via sanitize_file_name()
    $file = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
    $path = CS_BACKUP_DIR . $file;

    if (!file_exists($path) || strpos(realpath($path), realpath(CS_BACKUP_DIR)) !== 0) {
        wp_die( esc_html__( 'File not found.', 'cloudscale-free-backup-and-restore' ) );
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache');
    readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming binary download requires readfile(); WP_Filesystem has no streaming equivalent
    exit;
});

// ============================================================
// Quick backup from dashboard widget
// ============================================================

/**
 * Handle the dashboard widget "Run Backup Now" form POST.
 *
 * Runs a full backup (db + media + plugins + themes) and redirects
 * to the plugin admin page. Uses admin-post.php for correct POST handling.
 *
 * @since 3.2.1
 * @return void
 */
add_action( 'admin_post_cs_quick_backup', function (): void {
    check_admin_referer( 'cs_quick_backup', '_cs_quick_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Forbidden', 'cloudscale-free-backup-and-restore' ) );
    }

    set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent PHP timeout on large backups
    ignore_user_abort( true );
    cs_ensure_backup_dir();

    try {
        cs_create_backup( true, true, true, true );
        cs_enforce_retention();
    } catch ( Exception $e ) {
        cs_log( '[CloudScale Backup] Quick backup from dashboard widget failed: ' . $e->getMessage() );
    }

    wp_safe_redirect( admin_url( 'tools.php?page=cloudscale-backup' ) );
    exit;
} );

// ============================================================
// Dashboard widget
// ============================================================

add_action('wp_dashboard_setup', function (): void {
    wp_add_dashboard_widget(
        'cs_backup_status_widget',
        '&#128736; ' . esc_html__( 'CloudScale Backup Status', 'cloudscale-free-backup-and-restore' ),
        'cs_render_dashboard_widget'
    );
});

/**
 * Render the Dashboard quick-backup widget.
 *
 * @since 1.0.0
 * @return void
 */
function cs_render_dashboard_widget(): void {
    $backups    = cs_list_backups();
    $count      = count($backups);
    $latest     = $backups[0] ?? null;
    $free_bytes = disk_free_space(CS_BACKUP_DIR);
    if ($free_bytes === false) $free_bytes = disk_free_space(ABSPATH);
    $settings_url = admin_url('tools.php?page=cloudscale-backup');

    // Time since last backup
    if ($latest) {
        $age_secs = time() - (int) $latest['mtime'];
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
                <span style="<?php echo esc_attr( $label_style ); ?>"><?php esc_html_e( 'Last backup', 'cloudscale-free-backup-and-restore' ); ?></span>
                <span style="<?php echo esc_attr( $value_style . ' color:' . $age_color ); ?>">
                    <?php echo esc_html($age_label); ?>
                </span>
            </div>

            <div style="<?php echo esc_attr( $row_style ); ?>">
                <span style="<?php echo esc_attr( $label_style ); ?>"><?php esc_html_e( 'Last backup size', 'cloudscale-free-backup-and-restore' ); ?></span>
                <span style="<?php echo esc_attr( $value_style ); ?>">
                    <?php echo $latest ? esc_html(cs_format_size((int)$latest['size'])) : '—'; ?>
                </span>
            </div>

            <div style="<?php echo esc_attr( $row_style ); ?>">
                <span style="<?php echo esc_attr( $label_style ); ?>"><?php esc_html_e( 'Backups stored', 'cloudscale-free-backup-and-restore' ); ?></span>
                <span style="<?php echo esc_attr( $value_style ); ?>">
                    <?php
                    $retention = (int) get_option('cs_retention', 8);
                    echo esc_html($count . ' / ' . $retention);
                    ?>
                </span>
            </div>

            <div style="<?php echo esc_attr( $row_style . ' border-bottom:none;' ); ?>">
                <span style="<?php echo esc_attr( $label_style ); ?>"><?php esc_html_e( 'Free disk space', 'cloudscale-free-backup-and-restore' ); ?></span>
                <span style="<?php echo esc_attr( $value_style ); ?>">
                    <?php echo $free_bytes !== false ? esc_html(cs_format_size((int)$free_bytes)) : '—'; ?>
                </span>
            </div>

        </div>

        <a href="<?php echo esc_url($settings_url); ?>"
           style="<?php echo esc_attr( $btn_base . ' background: linear-gradient(90deg, #e65100 0%, #b71c1c 100%); margin-top:8px;' ); ?>">
            &#128736; <?php esc_html_e( 'CloudScale Backup & Restore', 'cloudscale-free-backup-and-restore' ); ?>
        </a>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;padding:0;">
            <?php wp_nonce_field( 'cs_quick_backup', '_cs_quick_nonce' ); ?>
            <input type="hidden" name="action" value="cs_quick_backup">
            <button type="submit" style="<?php echo esc_attr( $btn_base ); ?> background: linear-gradient(90deg, #00897b 0%, #1b5e20 100%); margin-top:2px; border-radius: 0 0 3px 3px; width:100%; border:none; cursor:pointer;">
                &#9654; <?php esc_html_e( 'Run Backup Now', 'cloudscale-free-backup-and-restore' ); ?>
            </button>
        </form>
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
function cs_maintenance_on(): void {
    $php = '<?php $upgrading = ' . time() . '; ?>';
    file_put_contents(CS_BACKUP_MAINT_FILE, $php);
}

/**
 * Disable WordPress maintenance mode by deleting the .maintenance file.
 *
 * @since 1.0.0
 * @return void
 */
function cs_maintenance_off(): void {
    if (file_exists(CS_BACKUP_MAINT_FILE)) {
        wp_delete_file(CS_BACKUP_MAINT_FILE);
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
function cs_create_backup(
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
    $prefix = sanitize_key(get_option('cs_backup_prefix', 'bkup')) ?: 'bkup';
    $seq = (int) get_option('cs_backup_seq', 0);
    do {
        $seq++;
        $filename = $prefix . '_' . $tcode . $seq . '.zip';
    } while (file_exists(CS_BACKUP_DIR . $filename));
    update_option('cs_backup_seq', $seq, false);
    $zip_path = CS_BACKUP_DIR . $filename;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Cannot create zip at: ' . $zip_path); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception caught and message passed to wp_send_json_error()
    }

    if ($include_db) {
        $zip->addFromString('database.sql', cs_dump_database());
    }

    if ($include_media) {
        cs_add_dir_to_zip($zip, wp_upload_dir()['basedir'], 'uploads');
    }

    if ($include_plugins) {
        cs_add_dir_to_zip($zip, WP_PLUGIN_DIR, 'plugins');
    }

    if ($include_themes) {
        cs_add_dir_to_zip($zip, get_theme_root(), 'themes');
    }

    if ($include_mu) {
        $mu_path = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        if (is_dir($mu_path)) cs_add_dir_to_zip($zip, $mu_path, 'mu-plugins');
    }

    if ($include_languages) {
        $lang_path = WP_CONTENT_DIR . '/languages';
        if (is_dir($lang_path)) cs_add_dir_to_zip($zip, $lang_path, 'languages');
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

    $zip->addFromString('backup-meta.json', json_encode([
        'plugin_version'    => CS_BACKUP_VERSION,
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
    ], JSON_PRETTY_PRINT));

    $zip->close();

    // S3 sync — result stored in global for AJAX handler to include in response
    $GLOBALS['cs_last_s3_result']     = cs_sync_to_s3(CS_BACKUP_DIR . $filename);
    $GLOBALS['cs_last_gdrive_result'] = cs_sync_to_gdrive(CS_BACKUP_DIR . $filename);

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
 * Returns an empty string when not running on EC2 or when curl is unavailable.
 *
 * @since 2.74.0
 * @return string IMDSv2 token, or empty string on failure.
 */
function cs_get_imds_token(): string {
    if (!function_exists('curl_init')) return '';
    try {
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close -- AWS IMDS requires sub-second connect timeout (1s); wp_remote_get() does not support CURLOPT_CONNECTTIMEOUT separately from CURLOPT_TIMEOUT
        $ch = @curl_init('http://169.254.169.254/latest/api/token');
        if (!$ch) return '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_HTTPHEADER     => ['X-aws-ec2-metadata-token-ttl-seconds: 60'],
            CURLOPT_CUSTOMREQUEST  => 'PUT',
        ]);
        $token = @curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close
        return ($code === 200 && $token) ? $token : '';
    } catch (\Throwable $e) {
        return '';
    }
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
function cs_imds_get(string $path): string {
    if (!function_exists('curl_init')) return '';
    try {
        // Try IMDSv2 first, fall back to IMDSv1
        $token   = cs_get_imds_token();
        $headers = $token ? ['X-aws-ec2-metadata-token: ' . $token] : [];

        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close -- AWS IMDS requires sub-second connect timeout; wp_remote_get() does not support CURLOPT_CONNECTTIMEOUT separately
        $ch = @curl_init('http://169.254.169.254' . $path);
        if (!$ch) return '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = @curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close

        return ($code === 200 && $body) ? trim($body) : '';
    } catch (\Throwable $e) {
        return '';
    }
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
function cs_get_instance_id(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $cached = cs_imds_get('/latest/meta-data/instance-id');
    } catch (\Throwable $e) {
        $cached = '';
    }

    return $cached;
}

/**
 * Return the AWS region of the running EC2 instance, or an empty string.
 *
 * Respects the cs_ami_region_override option. Result cached per request and
 * in a one-hour WordPress transient.
 *
 * @since 2.74.0
 * @return string Region code (e.g. 'us-east-1') or empty string.
 */
function cs_get_instance_region(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    // Manual override always wins — avoids IMDS dependency entirely
    $override = get_option('cs_ami_region_override', '');
    if ($override) {
        $cached = $override;
        return $cached;
    }

    try {
        // Availability zone is e.g. "af-south-1a" — strip the trailing letter
        $az = cs_imds_get('/latest/meta-data/placement/availability-zone');
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
 * Uses the same retention count as local backups (cs_retention option).
 *
 * @since 3.2.59
 * @return void
 */
function cs_enforce_s3_retention(): void {
    if (!get_option('cs_s3_sync_enabled', true)) return;
    $bucket = get_option('cs_s3_bucket', '');
    if (!$bucket) return;

    $aws = cs_find_aws();
    if (!$aws) return;

    $retention  = max(1, intval(get_option('cs_ami_max', 10)));
    $prefix     = '/' . ltrim(get_option('cs_s3_prefix', 'backups/'), '/');
    $s3_base    = 's3://' . rtrim($bucket, '/') . rtrim($prefix, '/') . '/';
    $cmd = escapeshellarg($aws) . ' s3 ls ' . escapeshellarg($s3_base) . ' 2>&1';
    $out = trim((string) shell_exec($cmd));
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

    if (count($files) <= $retention) return;

    usort($files, fn($a, $b) => strcmp($a['date'], $b['date'])); // oldest first
    $to_delete = array_slice($files, 0, count($files) - $retention);

    foreach ($to_delete as $file) {
        $cmd     = escapeshellarg($aws) . ' s3 rm ' . escapeshellarg($s3_base . $file['name']) . ' 2>&1';
        $del_out = trim((string) shell_exec($cmd));
        cs_log('[CloudScale Backup] S3 retention: deleted ' . $file['name'] . ($del_out ? ' — ' . $del_out : ' — OK'));
    }
}

/**
 * Delete old backup zips from the configured Google Drive remote, keeping only the most recent N.
 *
 * Uses the same retention count as local backups (cs_retention option).
 *
 * @since 3.2.59
 * @return void
 */
function cs_enforce_gdrive_retention(): void {
    if (!get_option('cs_gdrive_sync_enabled', true)) return;
    $remote = get_option('cs_gdrive_remote', '');
    if (!$remote) return;

    $rclone = cs_find_rclone();
    if (!$rclone) return;

    $retention   = max(1, intval(get_option('cs_ami_max', 10)));
    $dest_path   = ltrim(get_option('cs_gdrive_path', 'cloudscale-backups/'), '/');
    $remote_path = rtrim($remote, ':') . ':' . $dest_path;

    $cmd  = escapeshellarg($rclone) . ' lsjson ' . escapeshellarg($remote_path) . ' --files-only 2>&1';
    $out  = trim((string) shell_exec($cmd));
    if (!$out) return;

    $data  = json_decode($out, true);
    if (!is_array($data)) return;

    $files = array_values(array_filter($data, fn($f) => str_ends_with($f['Name'] ?? '', '.zip')));
    if (count($files) <= $retention) return;

    usort($files, fn($a, $b) => strcmp($a['ModTime'] ?? '', $b['ModTime'] ?? '')); // oldest first
    $to_delete = array_slice($files, 0, count($files) - $retention);

    foreach ($to_delete as $file) {
        $file_path = $remote_path . ($file['Name'] ?? '');
        $cmd       = escapeshellarg($rclone) . ' delete ' . escapeshellarg($file_path) . ' 2>&1';
        $del_out   = trim((string) shell_exec($cmd));
        cs_log('[CloudScale Backup] GDrive retention: deleted ' . ($file['Name'] ?? '') . ($del_out ? ' — ' . $del_out : ' — OK'));
    }
}

function cs_find_rclone(): string {
    $candidates = [
        trim((string) shell_exec('which rclone 2>/dev/null')),
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
 * Upload a local backup file to Google Drive using rclone.
 *
 * @since 3.2.59
 * @param string $local_path Absolute filesystem path to the backup zip.
 * @return array{ok: bool, dest: string, error?: string, skipped?: bool} Result array.
 */
function cs_sync_to_gdrive(string $local_path): array {
    if (!get_option('cs_gdrive_sync_enabled', true)) return ['skipped' => true];
    $remote = get_option('cs_gdrive_remote', '');
    if (!$remote) return ['skipped' => true];

    $rclone = cs_find_rclone();
    if (!$rclone) {
        return ['ok' => false, 'error' => 'rclone not found. Install rclone and configure a Google Drive remote.'];
    }

    $dest_path = ltrim(get_option('cs_gdrive_path', 'cloudscale-backups/'), '/');
    $dest      = rtrim($remote, ':') . ':' . $dest_path;
    $escaped   = escapeshellarg($local_path);
    $edest     = escapeshellarg($dest);

    $cmd = escapeshellarg($rclone) . " copy {$escaped} {$edest} 2>&1";
    $out = trim((string) shell_exec($cmd));

    $filename = basename($local_path);
    $log      = (array) get_option('cs_gdrive_log', []);

    if ($out) {
        cs_log('[CloudScale Backup] GDrive sync error: ' . $out);
        $result       = ['ok' => false, 'dest' => $dest, 'error' => $out];
        $log[$filename] = ['ok' => false, 'time' => time(), 'dest' => $dest, 'error' => $out];
    } else {
        cs_log('[CloudScale Backup] GDrive sync OK: ' . $dest);
        $result       = ['ok' => true, 'dest' => $dest];
        $log[$filename] = ['ok' => true, 'time' => time(), 'dest' => $dest];
        cs_enforce_gdrive_retention();
    }

    // Prune log entries for files that no longer exist
    $existing = array_map('basename', glob(CS_BACKUP_DIR . '*.zip') ?: []);
    foreach (array_keys($log) as $k) {
        if (!in_array($k, $existing, true)) unset($log[$k]);
    }
    update_option('cs_gdrive_log', $log, false);

    return $result;
}

function cs_find_aws(): string {
    $candidates = [
        trim((string) shell_exec('which aws 2>/dev/null')),
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
function cs_sync_to_s3(string $local_path, bool $schedule_retry = true): array {
    if (!get_option('cs_s3_sync_enabled', true)) return ['skipped' => true];
    $bucket = get_option('cs_s3_bucket', '');
    if (!$bucket) return ['skipped' => true];

    $aws = cs_find_aws();
    if (!$aws) {
        return ['ok' => false, 'error' => 'AWS CLI not found. Check common locations or ensure it is executable by the web server user.'];
    }

    $prefix  = get_option('cs_s3_prefix', 'backups/');
    $prefix  = '/' . ltrim($prefix, '/');
    $dest    = 's3://' . rtrim($bucket, '/') . rtrim($prefix, '/') . '/' . basename($local_path);
    $escaped = escapeshellarg($local_path);
    $edest   = escapeshellarg($dest);

    $cmd = escapeshellarg($aws) . " s3 cp {$escaped} {$edest} --only-show-errors 2>&1";
    $out = trim((string) shell_exec($cmd));

    $filename = basename($local_path);
    $log      = (array) get_option('cs_s3_log', []);

    if ($out) {
        cs_log('[CloudScale Backup] S3 sync error: ' . $out);
        $result = ['ok' => false, 'dest' => $dest, 'error' => $out];
        $retry_at = null;
        if ($schedule_retry && !wp_next_scheduled('cs_s3_retry', [$filename])) {
            wp_schedule_single_event(time() + 300, 'cs_s3_retry', [$filename]);
            $retry_at = time() + 300;
            cs_log('[CloudScale Backup] S3 retry scheduled in 5 min for ' . $filename);
        }
        $log[$filename] = array_filter([
            'ok'       => false,
            'time'     => time(),
            'dest'     => $dest,
            'error'    => $out,
            'retry_at' => $retry_at,
        ], fn($v) => $v !== null);
    } else {
        cs_log('[CloudScale Backup] S3 sync OK: ' . $dest);
        $result = ['ok' => true, 'dest' => $dest];
        $log[$filename] = ['ok' => true, 'time' => time(), 'dest' => $dest];
        cs_enforce_s3_retention();
    }

    // Prune log entries for files that no longer exist
    $existing = array_map('basename', glob(CS_BACKUP_DIR . '*.zip') ?: []);
    foreach (array_keys($log) as $k) {
        if (!in_array($k, $existing, true)) unset($log[$k]);
    }
    update_option('cs_s3_log', $log, false);

    return $result;
}

// ============================================================
// Core — Database dump
// ============================================================

/**
 * Dump the full WordPress database to a SQL string using the best available method.
 *
 * Uses mysqldump CLI if available, otherwise falls back to the PHP streamed implementation.
 *
 * @since 1.0.0
 * @return string Complete SQL dump as a string.
 */
function cs_dump_database(): string {
    return cs_mysqldump_available() ? cs_dump_via_mysqldump() : cs_dump_via_php($GLOBALS['wpdb']);
}

/**
 * Check whether the mysqldump CLI binary is available on the server.
 *
 * @since 1.0.0
 * @return bool True if mysqldump is found in PATH.
 */
function cs_mysqldump_available(): bool {
    exec('which mysqldump 2>/dev/null', $out, $rc);
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
function cs_dump_via_mysqldump(): string {
    [$host, $port] = cs_parse_db_host(DB_HOST);
    $tmp = tempnam(sys_get_temp_dir(), 'cs_dump_') . '.sql';

    $cmd = sprintf(
        'MYSQL_PWD=%s mysqldump --single-transaction --quick --lock-tables=false -h %s -P %s -u %s %s > %s 2>&1',
        escapeshellarg(DB_PASSWORD),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_NAME),
        escapeshellarg($tmp)
    );

    exec($cmd, $output, $rc);

    if ($rc !== 0 || !file_exists($tmp) || filesize($tmp) < 100) {
        wp_delete_file($tmp);
        return cs_dump_via_php($GLOBALS['wpdb']);
    }

    $sql = file_get_contents($tmp);
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
function cs_dump_via_php(\wpdb $wpdb): string {
    $out   = [];
    $out[] = '-- CloudScale Free Backup v' . CS_BACKUP_VERSION;
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
function cs_add_dir_to_zip(ZipArchive $zip, string $dir, string $prefix): void {
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
function cs_restore_from_zip(string $zip_path): void {
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        throw new Exception('Cannot open zip file.');
    }

    $idx = $zip->locateName('database.sql');
    if ($idx === false) {
        $zip->close();
        throw new Exception('No database.sql in this backup. Is it a DB or Full backup?');
    }

    $sql = $zip->getFromIndex($idx);
    $zip->close();

    if (empty($sql)) {
        throw new Exception('database.sql is empty.');
    }

    cs_execute_sql_string($sql);
}

/**
 * Read a .sql file from disk and execute it against the database.
 *
 * @since 1.0.0
 * @param string $path Absolute path to the .sql file.
 * @return void
 * @throws \Exception If the file is empty or unreadable.
 */
function cs_restore_sql_file(string $path): void {
    $sql = file_get_contents($path);
    if (empty($sql)) {
        throw new Exception('SQL file is empty.');
    }
    cs_execute_sql_string($sql);
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
function cs_execute_sql_string(string $sql): void {
    global $wpdb;

    if (cs_mysql_cli_available()) {
        cs_restore_via_mysql_cli($sql);
        return;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare -- static session variable; no user input; restore requires direct execution
    $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

    foreach (cs_split_sql($sql) as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt) || str_starts_with($stmt, '--') || str_starts_with($stmt, '/*')) {
            continue;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- full SQL from a trusted backup file; prepare() cannot be applied to DDL/DML replay
        $wpdb->query($stmt);
        if ($wpdb->last_error) {
            cs_log('CS Restore statement error: ' . $wpdb->last_error . ' | ' . substr($stmt, 0, 200));
        }
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoPrepare -- static session variable; restore requires direct execution
    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
}

/**
 * Check whether the mysql CLI client binary is available on the server.
 *
 * @since 1.0.0
 * @return bool True if mysql is found in PATH.
 */
function cs_mysql_cli_available(): bool {
    exec('which mysql 2>/dev/null', $out, $rc);
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
function cs_restore_via_mysql_cli(string $sql): void {
    $tmp = tempnam(sys_get_temp_dir(), 'cs_restore_') . '.sql';
    file_put_contents($tmp, $sql);

    [$host, $port] = cs_parse_db_host(DB_HOST);

    $cmd = sprintf(
        'MYSQL_PWD=%s mysql -h %s -P %s -u %s %s < %s 2>&1',
        escapeshellarg(DB_PASSWORD),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_NAME),
        escapeshellarg($tmp)
    );

    exec($cmd, $output, $rc);
    wp_delete_file( $tmp );

    if ($rc !== 0) {
        throw new Exception('mysql CLI restore failed: ' . implode(' | ', $output)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception caught and message passed to wp_send_json_error()
    }
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
function cs_split_sql(string $sql): array {
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
function cs_parse_db_host( string $host ): array {
    return CloudScale_Backup_Utils::parse_db_host( $host );
}

/**
 * Return the absolute path to the most recently modified backup zip in the backup directory.
 *
 * @since 3.2.28
 * @return string Absolute path to the newest zip file, or empty string if none exist.
 */
function cs_get_latest_backup_path(): string {
    $files = glob(CS_BACKUP_DIR . '*.zip') ?: [];
    if (!$files) return '';
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0];
}

function cs_list_backups(): array {
    $backups = [];
    if (!is_dir(CS_BACKUP_DIR)) return $backups;

    $files = glob(CS_BACKUP_DIR . '*.zip') ?: [];

    $current_prefix = sanitize_key(get_option('cs_backup_prefix', 'bkup')) ?: 'bkup';

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
        $backups[] = [
            'name'  => $name,
            'type'  => $type,
            'size'  => filesize($file),
            'date'  => wp_date('j M Y H:i', filemtime($file)),
            'mtime' => filemtime($file),
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
function cs_enforce_retention(): void {
    $keep    = intval(get_option('cs_retention', 8));
    $backups = cs_list_backups();
    foreach (array_slice($backups, $keep) as $b) {
        wp_delete_file(CS_BACKUP_DIR . $b['name']);
    }
}

/**
 * Return the total size in bytes of all files under a directory, recursively.
 *
 * @since 1.0.0
 * @param string $dir Absolute path to the directory.
 * @return int Total size in bytes, or 0 if the directory does not exist.
 */
function cs_dir_size( string $dir ): int {
    return CloudScale_Backup_Utils::dir_size( $dir );
}

/**
 * Format a byte count as a human-readable string (B / KB / MB / GB).
 *
 * @since 1.0.0
 * @param int $bytes Size in bytes.
 * @return string Formatted string, e.g. '12.5 MB'.
 */
function cs_format_size( int $bytes ): string {
    return CloudScale_Backup_Utils::format_size( $bytes );
}

/**
 * Return a human-readable relative age string for a Unix timestamp.
 *
 * @since 1.0.0
 * @param int $timestamp Unix timestamp to describe.
 * @return string E.g. '5 min ago', '3 hr ago', '12 days ago', or a formatted date.
 */
function cs_human_age( int $timestamp ): string {
    return CloudScale_Backup_Utils::human_age( $timestamp );
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
function cs_verify_nonce(): void {
    if ( ! check_ajax_referer( 'cs_nonce', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.' );
    }
}
