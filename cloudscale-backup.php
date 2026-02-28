<?php
/**
 * Plugin Name:       CloudScale Free Backup and Restore
 * Plugin URI:        https://your-wordpress-site.example.com/cloudscale-backup
 * Description:       No-nonsense WordPress backup and restore. Backs up database, media, plugins and themes into a single zip. Scheduled or manual, with safe restore and maintenance mode.
 * Version:           2.76.0
 * Author:            Andrew Baker
 * Author URI:        https://your-wordpress-site.example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Tested up to:      6.7
 * Requires PHP:      8.1
 * Text Domain:       cloudscale-backup
 */

defined('ABSPATH') || exit;

define('CS_VERSION',    '2.76.0');
define('CS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CS_BACKUP_DIR', WP_CONTENT_DIR . '/cloudscale-backups/');
define('CS_MAINT_FILE', ABSPATH . '.maintenance');

// ============================================================
// Bootstrap
// ============================================================

register_activation_hook(__FILE__, 'cs_activate');
register_deactivation_hook(__FILE__, 'cs_deactivate');

function cs_activate() {
    cs_ensure_backup_dir();
    cs_reschedule();
}

function cs_deactivate() {
    wp_clear_scheduled_hook('cs_scheduled_backup');
    cs_maintenance_off();

    // Wipe asset files so Deactivate > Delete > Upload leaves no stale code
    $dir = plugin_dir_path(__FILE__);
    foreach (glob($dir . '*.{js,css}', GLOB_BRACE) as $f) { @unlink($f); }

    // Clean old assets/ subdirectory from previous versions
    $assets = $dir . 'assets/';
    if (is_dir($assets)) {
        foreach (glob($assets . '*') as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($assets);
    }
}

// Version change detector — cleans stale assets on upgrade without deactivation
add_action('admin_init', function () {
    $cached = get_option('cs_loaded_version', '');
    if ($cached !== CS_VERSION) {
        if (function_exists('opcache_reset')) { opcache_reset(); }
        // Delete any old assets/ subfolder from previous versions
        $assets = plugin_dir_path(__FILE__) . 'assets/';
        if (is_dir($assets)) {
            foreach (glob($assets . '*') as $f) { if (is_file($f)) { @unlink($f); } }
            @rmdir($assets);
        }
        update_option('cs_loaded_version', CS_VERSION);
    }
});

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

// Ensure daily interval exists
add_filter('cron_schedules', function (array $schedules): array {
    if (!isset($schedules['daily'])) {
        $schedules['daily'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => 'Once Daily',
        ];
    }
    return $schedules;
});

function cs_get_run_days(): array {
    global $wpdb;
    // Read directly from DB — bypasses object cache entirely
    $raw = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cs_run_days'");
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

function cs_reschedule(): void {
    wp_clear_scheduled_hook('cs_scheduled_backup');

    $enabled = get_option('cs_schedule_enabled', false);
    if (!$enabled) return;

    $hour     = intval(get_option('cs_run_hour', 3));
    $timezone = wp_timezone();
    $now      = new DateTime('now', $timezone);
    $run_days = cs_get_run_days();

    // Walk forward up to 8 days to find next matching day/time
    $candidate = clone $now;
    $candidate->setTime($hour, 0, 0);

    for ($i = 0; $i <= 7; $i++) {
        $dow = intval($candidate->format('N')); // 1=Mon...7=Sun
        if (in_array($dow, $run_days, true) && $candidate > $now) {
            break;
        }
        $candidate->modify('+1 day');
        $candidate->setTime($hour, 0, 0);
    }

    wp_schedule_event($candidate->getTimestamp(), 'daily', 'cs_scheduled_backup');
}

add_action('cs_scheduled_backup', function () {
    // Skip days not in the configured run-day list
    $run_days = cs_get_run_days();
    $today    = intval((new DateTime('now', wp_timezone()))->format('N'));
    if (!in_array($today, $run_days, true)) return;

    set_time_limit(0);
    ignore_user_abort(true);
    cs_ensure_backup_dir();
    cs_create_backup(true, true, true, true);
    cs_enforce_retention();
});

// ============================================================
// Admin menu
// ============================================================

add_action('admin_menu', function () {
    add_management_page(
        'CloudScale Free Backup and Restore',
        'CloudScale Backup & Restore',
        'manage_options',
        'cloudscale-backup',
        'cs_admin_page'
    );
});

add_action('admin_enqueue_scripts', function (string $hook): void {
    if ($hook !== 'tools_page_cloudscale-backup') return;
    wp_enqueue_style('cs-style',  plugin_dir_url(__FILE__) . 'style.css',  [], CS_VERSION);
    wp_enqueue_script('cs-script', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], CS_VERSION, true);
    wp_localize_script('cs-script', 'CS', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('cs_nonce'),
        'site_url' => get_site_url(),
    ]);
});

// ============================================================
// Admin page
// ============================================================

function cs_admin_page(): void {
    cs_ensure_backup_dir();

    $backups      = cs_list_backups();
    $upload_dir   = wp_upload_dir();
    $upload_size   = cs_dir_size($upload_dir['basedir']);
    $plugins_size  = cs_dir_size(WP_PLUGIN_DIR);
    $themes_size   = cs_dir_size(get_theme_root());
    // "Other" backup targets
    $mu_path       = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    $mu_size       = is_dir($mu_path) ? cs_dir_size($mu_path) : 0;
    $lang_path     = WP_CONTENT_DIR . '/languages';
    $lang_size     = is_dir($lang_path) ? cs_dir_size($lang_path) : 0;
    $htaccess_path = ABSPATH . '.htaccess';
    $htaccess_size = file_exists($htaccess_path) ? (int) filesize($htaccess_path) : 0;
    $wpconfig_path = ABSPATH . 'wp-config.php';
    $wpconfig_size = file_exists($wpconfig_path) ? (int) filesize($wpconfig_path) : 0;
    $dropins_size  = 0;
    foreach (glob(WP_CONTENT_DIR . '/*.php') ?: [] as $_f) { $dropins_size += (int) filesize($_f); }

    // Estimate database size from information_schema
    global $wpdb;
    $db_size = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(data_length + index_length)
         FROM information_schema.TABLES
         WHERE table_schema = %s",
        DB_NAME
    ));
    $next_run     = wp_next_scheduled('cs_scheduled_backup');
    $maint_active = file_exists(CS_MAINT_FILE);

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
        $raw_days   = isset($_POST['run_days']) && is_array($_POST['run_days']) ? $_POST['run_days'] : [];
        $clean_days = array_values(array_filter(array_map('intval', $raw_days), fn($d) => $d >= 1 && $d <= 7));
        update_option('cs_run_days',         $clean_days);
        update_option('cs_run_days_saved',   '1');
        update_option('cs_schedule_enabled', !empty($_POST['schedule_enabled']));
        update_option('cs_run_hour',         max(0, min(23, intval($_POST['run_hour'] ?? 3))));
        wp_cache_delete('cs_run_days',         'options');
        wp_cache_delete('cs_run_days_saved',   'options');
        wp_cache_delete('cs_schedule_enabled', 'options');
        wp_cache_delete('alloptions',          'options');
        cs_reschedule();
        $day_names = ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $saved_labels = implode(', ', array_map(fn($d) => $day_names[$d] ?? $d, $clean_days));
        $cs_schedule_saved_msg = $saved_labels ?: 'No days selected';
    }

    // Settings — read after any POST save so page shows updated values
    $enabled      = isset($_POST['cs_action']) ? !empty($_POST['schedule_enabled']) : (bool) get_option('cs_schedule_enabled', false);
    $run_days_sel = cs_get_run_days();
    $hour         = intval(get_option('cs_run_hour', 3));
    $retention    = intval(get_option('cs_retention', 8));
    $s3_bucket    = get_option('cs_s3_bucket', '');
    $s3_prefix    = get_option('cs_s3_prefix', 'backups/');
    $s3_saved_msg = '';
    $ami_prefix   = get_option('cs_ami_prefix', '');
    $ami_reboot   = (bool) get_option('cs_ami_reboot', false);
    $dump_method  = cs_mysqldump_available() ? 'mysqldump (native — fast)' : 'PHP streamed (compatible)';
    $restore_method = cs_mysql_cli_available() ? 'mysql CLI (native — fast)' : 'PHP streamed (compatible)';

    // MySQL version
    $mysql_version = $wpdb->get_var('SELECT VERSION()') ?: 'Unknown';
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
                <h1>☁ CloudScale Free Backup &amp; Restore</h1>
                <p class="cs-header-sub">Database · media · plugins · themes. No timeouts, no external services.</p>
                <p class="cs-header-free">✅ 100% free forever — no licence, no premium tier, no feature restrictions. Everything is included.</p>
            </div>
            <div class="cs-header-status">
                <?php if ($maint_active): ?>
                    <span class="cs-badge cs-badge-warn">⚠ Maintenance Mode Active</span>
                <?php else: ?>
                    <span class="cs-badge cs-badge-ok" style="background:#16a34a!important;color:#fff!important;border:1px solid #15803d!important;display:inline-flex;align-items:center;gap:6px;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#86efac;box-shadow:0 0 5px #86efac;flex-shrink:0;"></span>Site Online</span>
                <?php endif; ?>
                <a href="https://your-wordpress-site.example.com" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:6px 16px;background:#f57c00!important;color:#fff!important;font-size:0.8rem;font-weight:700;border-radius:20px;text-decoration:none!important;border:1px solid #e65100!important;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ffcc80;box-shadow:0 0 5px #ffcc80;flex-shrink:0;"></span>andrewbaker.ninja</a>
            </div>
        </div>

        <!-- ===================== DISK SPACE ALERT ===================== -->
        <?php if ($show_disk_alert): ?>
        <div class="cs-alert cs-alert-<?php echo $disk_status; ?>">
            <?php if ($disk_status === 'red'): ?>
                <span class="cs-alert-icon">🔴</span>
                <div><strong>Critical: Very low disk space</strong> —
                <?php echo cs_format_size((int)$free_bytes); ?> free<?php if ($backups_fit !== null): ?>, room for approximately <strong><?php echo $backups_fit; ?> more backup(s)</strong><?php endif; ?>.
                Free up space or move old backups off-server immediately.</div>
            <?php else: ?>
                <span class="cs-alert-icon">🟡</span>
                <div><strong>Warning: Disk space is running low</strong> —
                <?php echo cs_format_size((int)$free_bytes); ?> free<?php if ($backups_fit !== null): ?>, room for approximately <strong><?php echo $backups_fit; ?> more backup(s)</strong><?php endif; ?>.
                Consider freeing space or reducing your retention count.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ===================== SETTINGS ===================== -->
        <div class="cs-section-ribbon"><span>Schedule &amp; Settings</span></div>
        <div class="cs-grid cs-grid-1" style="display:flex!important;flex-direction:column!important;gap:16px!important;">

            <!-- SCHEDULE CARD -->
            <div class="cs-card cs-card--blue">
                <form method="post" action="" id="cs-schedule-form">
                <?php wp_nonce_field('cs_nonce', 'nonce'); ?>
                <input type="hidden" name="cs_action" value="save_schedule">
                <div class="cs-card-stripe cs-stripe--blue" style="background:linear-gradient(135deg,#1565c0 0%,#2196f3 100%);display:flex;align-items:center;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">⏰ Backup Schedule</h2></div>

                <!-- Enable/disable checkbox — inline with label -->
                <div class="cs-field-group">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-schedule-enabled" name="schedule_enabled" value="1" <?php checked($enabled); ?>>
                        Enable automatic backups
                    </label>
                </div>

                <!-- fieldset[disabled] is the only reliable cross-browser disable mechanism -->
                <fieldset id="cs-schedule-controls" <?php echo !$enabled ? 'disabled' : ''; ?> class="cs-schedule-fieldset">
                    <legend class="screen-reader-text">Schedule controls</legend>

                    <div class="cs-field-group">
                        <span class="cs-field-label">Run on these days</span>
                        <div class="cs-day-checks">
                            <?php foreach ($days_map as $num => $day_label): ?>
                            <label class="cs-day-check-label">
                                <input type="checkbox"
                                       class="cs-day-check"
                                       name="run_days[]"
                                       value="<?php echo $num; ?>"
                                       <?php checked(in_array($num, $run_days_sel, false)); ?>>
                                <?php echo $day_label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="cs-help">Select one or more days. Backup runs once per selected day at the time below.</p>
                    </div>

                    <div class="cs-field-group">
                        <label class="cs-field-label" for="cs-run-hour">Run at time (server)</label>
                        <div class="cs-inline">
                            <select id="cs-run-hour" class="cs-input-sm">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                    <option value="<?php echo $h; ?>" <?php selected($hour, $h); ?>><?php echo str_pad($h, 2, '0', STR_PAD_LEFT); ?>:00</option>
                                <?php endfor; ?>
                            </select>
                            <span class="cs-muted-text">server time</span>
                        </div>
                        <p class="cs-help">Now: <?php echo current_time('H:i T'); ?> &nbsp;·&nbsp; TZ: <?php echo wp_timezone_string(); ?></p>
                    </div>

                    <?php if ($next_run): ?>
                    <div class="cs-next-run">
                        <span class="cs-next-run-label">Next scheduled run</span>
                        <span class="cs-next-run-time"><?php echo get_date_from_gmt(date('Y-m-d H:i:s', $next_run), 'D j M \a\t H:i'); ?></span>
                    </div>
                    <?php endif; ?>

                </fieldset>

                <div id="cs-off-notice" class="cs-off-notice" <?php echo $enabled ? 'style="display:none"' : ''; ?>>
                    Automatic backups are <strong>off</strong>. Enable the checkbox above to configure a schedule, or run backups manually below.
                </div>

                <button type="submit" name="cs_save_schedule" class="button button-primary cs-mt">Save Schedule</button>
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
                <div class="cs-card-stripe cs-stripe--green" style="background:linear-gradient(135deg,#2e7d32 0%,#43a047 100%);display:flex;align-items:center;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">🗂 Retention &amp; Storage</h2></div>

                <div class="cs-field-group">
                    <label class="cs-field-label">Keep last</label>
                    <div class="cs-inline">
                        <input type="number" id="cs-retention"
                               value="<?php echo $retention; ?>" min="1" max="9999"
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
                                <?php echo cs_format_size($ret_needed); ?>
                            </span>
                        </div>
                        <div class="cs-ret-row">
                            <span class="cs-ret-label">Disk free space</span>
                            <span class="cs-ret-val cs-free-<?php echo $disk_status; ?>">
                                <?php echo $free_bytes !== false ? cs_format_size((int)$free_bytes) : 'Unknown'; ?>
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
                        <span class="cs-storage-value cs-value--blue"><?php echo cs_format_size(cs_dir_size(CS_BACKUP_DIR)); ?></span>
                    </div>
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Media uploads</span>
                        <span class="cs-storage-value"><?php echo cs_format_size($upload_size); ?></span>
                    </div>
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Plugins folder</span>
                        <span class="cs-storage-value"><?php echo cs_format_size($plugins_size); ?></span>
                    </div>
                    <div class="cs-storage-item">
                        <span class="cs-storage-label">Themes folder</span>
                        <span class="cs-storage-value"><?php echo cs_format_size($themes_size); ?></span>
                    </div>
                </div>

                <button type="button" id="cs-save-retention" class="button button-primary cs-mt">Save Retention Settings</button>
                <span id="cs-retention-saved" class="cs-saved-msg" style="display:none">✓ Saved</span>
            </div>

            <!-- S3 REMOTE BACKUP CARD — self-contained, no script.js dependency -->
            <div class="cs-card cs-card--pink">
                <div class="cs-card-stripe cs-stripe--pink" style="background:linear-gradient(135deg,#880e4f 0%,#e91e8c 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#9729; S3 Remote Backup</h2>
                    <button type="button" onclick="csS3Explain()" style="background:transparent;border:1.5px solid rgba(255,255,255,0.7);color:#fff;border-radius:6px;padding:4px 12px;font-size:0.78rem;font-weight:600;cursor:pointer;">Explain&hellip;</button>
                </div>

                <p class="cs-help">After each backup, the zip will be synced to your S3 bucket using the AWS CLI. The AWS CLI must be installed and configured on the server with appropriate credentials.</p>

                <div class="cs-info-row">
                    <span>AWS CLI</span>
                    <strong><?php
                        $aws_path = cs_find_aws();
                        if ($aws_path) {
                            $aws_ver = trim((string) shell_exec(escapeshellarg($aws_path) . ' --version 2>&1'));
                            echo '<span style="color:#2e7d32">&#10003; ' . esc_html($aws_ver ?: $aws_path) . '</span>';
                        } else {
                            echo '<span style="color:#c62828">&#10007; Not found in PATH or common locations</span>';
                        }
                    ?></strong>
                </div>

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
                    <button type="button" onclick="csS3Save()" class="button button-primary">Save S3 Settings</button>
                    <button type="button" onclick="csS3Test()" class="button" style="margin-left:8px">Test Connection</button>
                    <span id="cs-s3-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
                </div>

                <?php if ($s3_bucket): ?>
                <div class="cs-info-row cs-mt">
                    <span>Destination</span>
                    <strong><code>s3://<?php echo esc_html(rtrim($s3_bucket, '/') . '/' . ltrim($s3_prefix, '/')); ?></code></strong>
                </div>
                <?php
                $s3_log     = (array) get_option('cs_s3_log', []);
                $s3_synced  = array_filter($s3_log, fn($e) => !empty($e['ok']));
                $s3_last    = empty($s3_synced) ? null : max(array_column($s3_synced, 'time'));
                ?>
                <div class="cs-info-row">
                    <span>Backups in S3</span>
                    <strong><?php echo count($s3_synced); ?> of <?php echo count($backups); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Last S3 sync</span>
                    <strong><?php echo $s3_last ? esc_html(cs_human_age($s3_last) . ' ago (' . date('Y-m-d H:i', $s3_last) . ')') : 'Never'; ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <?php
            $cs_ajax = esc_js(admin_url('admin-ajax.php'));
            $cs_nonce = esc_js(wp_create_nonce('cs_nonce'));
            ?>
            <script>
            var CS_S3_AJAX  = '<?php echo $cs_ajax; ?>';
            var CS_S3_NONCE = '<?php echo $cs_nonce; ?>';

            function csS3Msg(text, ok) {
                var el = document.getElementById('cs-s3-msg');
                el.innerHTML = text;
                el.style.color = ok ? '#2e7d32' : '#c62828';
            }

            function csS3Post(action, extra, onDone) {
                var params = 'action=' + action + '&nonce=' + encodeURIComponent(CS_S3_NONCE);
                if (extra) params += '&' + extra;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', CS_S3_AJAX, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    try { onDone(JSON.parse(xhr.responseText)); }
                    catch(e) { onDone({success:false, data:'Bad response: ' + xhr.responseText.substring(0,100)}); }
                };
                xhr.onerror = function() { onDone({success:false, data:'Network error'}); };
                xhr.send(params);
            }

            function csS3Save() {
                var bucket = document.getElementById('cs-s3-bucket').value.trim();
                var prefix = document.getElementById('cs-s3-prefix').value.trim() || 'backups/';
                csS3Msg('Saving...', true);
                csS3Post('cs_save_s3',
                    'bucket=' + encodeURIComponent(bucket) + '&prefix=' + encodeURIComponent(prefix),
                    function(res) { csS3Msg(res.success ? '&#10003; Saved' : '&#10007; ' + res.data, res.success); }
                );
            }

            function csS3Test() {
                csS3Msg('Testing...', true);
                csS3Post('cs_test_s3', '', function(res) {
                    csS3Msg((res.success ? '&#10003; ' : '&#10007; ') + res.data, res.success);
                });
            }

            function csS3Explain() {
                var el = document.getElementById('cs-s3-explain-overlay');
                if (el) { el.style.display = 'flex'; return; }
                var ov = document.createElement('div');
                ov.id = 'cs-s3-explain-overlay';
                ov.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:999999;display:flex;align-items:center;justify-content:center;';
                var box = document.createElement('div');
                box.style.cssText = 'background:#fff;border-radius:8px;max-width:600px;width:92%;max-height:80vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,0.4);';
                var head = document.createElement('div');
                head.style.cssText = 'background:linear-gradient(135deg,#880e4f,#e91e8c);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;';
                head.innerHTML = '<strong style="color:#fff;">S3 Remote Backup - Setup Guide</strong>';
                var closeBtn = document.createElement('button');
                closeBtn.type = 'button';
                closeBtn.innerHTML = '&times;';
                closeBtn.style.cssText = 'background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;line-height:1;';
                closeBtn.onclick = function() { ov.style.display = 'none'; };
                head.appendChild(closeBtn);
                var body = document.createElement('div');
                body.style.cssText = 'overflow-y:auto;padding:20px 24px;';
                body.innerHTML = '<p><strong>How it works</strong><br>After every backup the plugin runs <code>aws s3 cp</code> to upload the zip. Local copy always kept.</p>'
                    + '<p><strong>Install (Ubuntu/Debian)</strong></p><pre style="background:#f4f4f4;padding:10px;border-radius:4px;font-size:12px;overflow-x:auto;">curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o awscliv2.zip\nunzip awscliv2.zip &amp;&amp; sudo ./aws/install</pre>'
                    + '<p><strong>Install (Amazon Linux)</strong></p><pre style="background:#f4f4f4;padding:10px;border-radius:4px;font-size:12px;">sudo yum install -y aws-cli</pre>'
                    + '<p><strong>Credentials</strong><br>1. IAM instance role (recommended)<br>2. <code>aws configure</code> as web server user<br>3. Env vars: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION</p>'
                    + '<p><strong>Minimum IAM policy</strong></p><pre style="background:#f4f4f4;padding:10px;border-radius:4px;font-size:12px;overflow-x:auto;">{\n  "Version": "2012-10-17",\n  "Statement": [{\n    "Effect": "Allow",\n    "Action": ["s3:PutObject","s3:GetObject","s3:ListBucket"],\n    "Resource": ["arn:aws:s3:::YOUR-BUCKET","arn:aws:s3:::YOUR-BUCKET/*"]\n  }]\n}</pre>';
                box.appendChild(head);
                box.appendChild(body);
                ov.appendChild(box);
                ov.addEventListener('click', function(e) { if (e.target === ov) ov.style.display = 'none'; });
                document.body.appendChild(ov);
            }
            </script>

            <!-- AMI SNAPSHOT CARD — self-contained, no script.js dependency -->
            <?php
            try {
            $ami_instance_id = cs_get_instance_id();
            $ami_region      = cs_get_instance_region();
            $ami_log         = (array) get_option('cs_ami_log', []);
            // Show last 5, newest first
            $ami_log_recent  = array_slice(array_reverse($ami_log), 0, 5);
            } catch (\Throwable $e) {
                $ami_instance_id = '';
                $ami_region      = '';
                $ami_log         = [];
                $ami_log_recent  = [];
                error_log('[CloudScale Backup] AMI panel init error: ' . $e->getMessage());
            }
            ?>
            <div class="cs-card cs-card--indigo">
                <div class="cs-card-stripe cs-stripe--indigo" style="background:linear-gradient(135deg,#1a237e 0%,#3949ab 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
                    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128247; EC2 AMI Snapshot</h2>
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
                    <strong><?php echo $ami_region ? esc_html($ami_region) : '<span style="color:#999">Unknown</span>'; ?></strong>
                </div>

                <div class="cs-field-row cs-mt">
                    <label for="cs-ami-prefix"><strong>AMI name prefix</strong></label>
                    <input type="text" id="cs-ami-prefix" class="regular-text" placeholder="mysite-backup"
                           value="<?php echo esc_attr($ami_prefix); ?>">
                    <p class="cs-help">Example: entering <code>prod-web01</code> creates AMIs named <code>prod-web01_20260227_1430</code></p>
                </div>

                <div class="cs-field-group">
                    <label class="cs-enable-label">
                        <input type="checkbox" id="cs-ami-reboot" <?php checked($ami_reboot); ?>>
                        Reboot instance after AMI creation <span class="cs-sensitive-badge" style="margin-left:6px;">&#9888; downtime</span>
                    </label>
                    <p class="cs-help">Rebooting ensures filesystem consistency. Without reboot, the AMI is created from a live (crash consistent) snapshot.</p>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" onclick="csAmiSave()" class="button button-primary">Save AMI Settings</button>
                    <button type="button" onclick="csAmiCreate()" class="button" style="margin-left:8px;background:#1a237e!important;color:#fff!important;border-color:#1a237e!important;" <?php echo $ami_instance_id ? '' : 'disabled title="EC2 instance not detected"'; ?>>&#128247; Create AMI Now</button>
                    <button type="button" onclick="csAmiStatus()" class="button" style="margin-left:4px;" <?php echo $ami_instance_id ? '' : 'disabled'; ?>>Check Status</button>
                    <span id="cs-ami-msg" style="margin-left:10px;font-size:0.85rem;font-weight:600;"></span>
                </div>

                <?php if (!empty($ami_log_recent)): ?>
                <div class="cs-mt" style="margin-top:16px;">
                    <p class="cs-field-label" style="margin-bottom:6px;">Recent AMI snapshots</p>
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
                        <tbody>
                        <?php foreach ($ami_log_recent as $entry): ?>
                            <?php $row_ami_id = esc_attr($entry['ami_id'] ?? ''); ?>
                            <tr id="cs-ami-row-<?php echo $row_ami_id; ?>">
                                <td><?php echo esc_html($entry['name'] ?? '—'); ?></td>
                                <td><code style="font-size:0.78rem;"><?php echo esc_html($entry['ami_id'] ?? '—'); ?></code></td>
                                <td><?php echo esc_html(isset($entry['time']) ? date('Y-m-d H:i', $entry['time']) : '—'); ?></td>
                                <td id="cs-ami-state-<?php echo $row_ami_id; ?>">
                                    <?php if (!empty($entry['ok'])): ?>
                                        <span style="color:#2e7d32;font-weight:600;">&#10003; <?php echo esc_html($entry['state'] ?? 'Created'); ?></span>
                                    <?php else: ?>
                                        <span style="color:#c62828;font-weight:600;">&#10007; Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($entry['ami_id'])): ?>
                                    <button type="button" onclick="csAmiRefreshRow('<?php echo $row_ami_id; ?>')" class="button button-small" title="Refresh status" style="min-width:0;padding:2px 8px;">&#8635; Refresh</button>
                                    <button type="button" onclick="csAmiDelete('<?php echo $row_ami_id; ?>', '<?php echo esc_attr($entry['name'] ?? ''); ?>')" class="button button-small" title="Deregister AMI" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Delete</button>
                                    <?php else: ?>
                                    <span style="color:#999;">—</span>
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

            <script>
            function csAmiMsg(text, ok) {
                var el = document.getElementById('cs-ami-msg');
                el.innerHTML = text;
                el.style.color = ok ? '#2e7d32' : '#c62828';
            }

            function csAmiPost(action, extra, onDone) {
                var params = 'action=' + action + '&nonce=' + encodeURIComponent(CS_S3_NONCE);
                if (extra) params += '&' + extra;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', CS_S3_AJAX, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    try { onDone(JSON.parse(xhr.responseText)); }
                    catch(e) { onDone({success:false, data:'Bad response: ' + xhr.responseText.substring(0,100)}); }
                };
                xhr.onerror = function() { onDone({success:false, data:'Network error'}); };
                xhr.send(params);
            }

            function csAmiSave() {
                var prefix = document.getElementById('cs-ami-prefix').value.trim();
                var reboot = document.getElementById('cs-ami-reboot').checked ? '1' : '0';
                csAmiMsg('Saving...', true);
                csAmiPost('cs_save_ami',
                    'prefix=' + encodeURIComponent(prefix) + '&reboot=' + reboot,
                    function(res) { csAmiMsg(res.success ? '&#10003; Saved' : '&#10007; ' + res.data, res.success); }
                );
            }

            function csAmiCreate() {
                var prefix = document.getElementById('cs-ami-prefix').value.trim();
                if (!prefix) { csAmiMsg('&#10007; Enter an AMI name prefix first.', false); return; }
                var reboot = document.getElementById('cs-ami-reboot').checked;
                var msg = 'Create an AMI snapshot of this instance now?';
                if (reboot) msg += '\n\nWARNING: The instance will be REBOOTED. This will cause brief downtime.';
                if (!confirm(msg)) return;
                csAmiMsg('Creating AMI... this may take a moment.', true);
                csAmiPost('cs_create_ami', '', function(res) {
                    if (res.success) {
                        csAmiMsg('&#10003; ' + (res.data.message || 'AMI created'), true);
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        csAmiMsg('&#10007; ' + (res.data || 'AMI creation failed'), false);
                    }
                });
            }

            function csAmiStatus() {
                csAmiMsg('Checking...', true);
                csAmiPost('cs_ami_status', '', function(res) {
                    if (res.success) {
                        csAmiMsg('&#10003; ' + res.data, true);
                    } else {
                        csAmiMsg('&#10007; ' + (res.data || 'Could not check status'), false);
                    }
                });
            }

            function csAmiRefreshRow(amiId) {
                var stateCell = document.getElementById('cs-ami-state-' + amiId);
                if (stateCell) stateCell.innerHTML = '<span style="color:#1565c0;font-weight:600;">&#8987; Checking...</span>';
                csAmiPost('cs_ami_status', 'ami_id=' + encodeURIComponent(amiId), function(res) {
                    if (res.success && res.data && res.data.state) {
                        var state = res.data.state;
                        var color = state === 'available' ? '#2e7d32' : (state === 'pending' ? '#e65100' : '#c62828');
                        var icon  = state === 'available' ? '&#10003;' : (state === 'pending' ? '&#9203;' : '&#10007;');
                        if (stateCell) stateCell.innerHTML = '<span style="color:' + color + ';font-weight:600;">' + icon + ' ' + state + '</span>';
                    } else {
                        if (stateCell) stateCell.innerHTML = '<span style="color:#c62828;font-weight:600;">&#10007; ' + (res.data || 'Error') + '</span>';
                    }
                });
            }

            function csAmiDelete(amiId, amiName) {
                var label = amiName ? amiName + ' (' + amiId + ')' : amiId;
                if (!confirm('Deregister (delete) this AMI?\n\n' + label + '\n\nThis will deregister the AMI from AWS. Associated EBS snapshots will NOT be deleted automatically.')) return;
                csAmiMsg('Deregistering ' + amiId + '...', true);
                csAmiPost('cs_deregister_ami', 'ami_id=' + encodeURIComponent(amiId), function(res) {
                    if (res.success) {
                        csAmiMsg('&#10003; ' + (res.data || 'AMI deregistered'), true);
                        var row = document.getElementById('cs-ami-row-' + amiId);
                        if (row) {
                            row.style.opacity = '0.4';
                            row.style.textDecoration = 'line-through';
                            // Remove action buttons
                            var cells = row.getElementsByTagName('td');
                            if (cells.length >= 5) cells[4].innerHTML = '<span style="color:#999;font-style:italic;">Deregistered</span>';
                            if (cells.length >= 4) cells[3].innerHTML = '<span style="color:#999;font-weight:600;">&#10007; Deregistered</span>';
                        }
                    } else {
                        csAmiMsg('&#10007; ' + (res.data || 'Deregister failed'), false);
                    }
                });
            }

            function csAmiExplain() {
                var el = document.getElementById('cs-ami-explain-overlay');
                if (el) { el.style.display = 'flex'; return; }
                var ov = document.createElement('div');
                ov.id = 'cs-ami-explain-overlay';
                ov.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:999999;display:flex;align-items:center;justify-content:center;';
                var box = document.createElement('div');
                box.style.cssText = 'background:#fff;border-radius:8px;max-width:600px;width:92%;max-height:80vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,0.4);';
                var head = document.createElement('div');
                head.style.cssText = 'background:linear-gradient(135deg,#1a237e,#3949ab);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;';
                head.innerHTML = '<strong style="color:#fff;">EC2 AMI Snapshot - Setup Guide</strong>';
                var closeBtn = document.createElement('button');
                closeBtn.type = 'button';
                closeBtn.innerHTML = '&times;';
                closeBtn.style.cssText = 'background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;line-height:1;';
                closeBtn.onclick = function() { ov.style.display = 'none'; };
                head.appendChild(closeBtn);
                var body = document.createElement('div');
                body.style.cssText = 'overflow-y:auto;padding:20px 24px;';
                body.innerHTML = '<p><strong>How it works</strong><br>Creates an Amazon Machine Image (AMI) of the running EC2 instance. This is a full disk snapshot that can be used to launch an identical replacement server.</p>'
                    + '<p><strong>Requirements</strong></p><ul style="margin:8px 0 12px 20px;font-size:0.9rem;"><li>Instance must be running on EC2 with IMDSv1 or IMDSv2 enabled</li><li>AWS CLI installed and configured on the server</li><li>IAM role or credentials with the required permissions</li></ul>'
                    + '<p><strong>AMI naming</strong><br>You provide a prefix (e.g. <code>prod-web01</code>) and the plugin appends a timestamp: <code>prod-web01_20260227_1430</code></p>'
                    + '<p><strong>Reboot option</strong><br>With reboot enabled, EC2 cleanly shuts down the instance before snapshotting, ensuring filesystem consistency. Without reboot, the snapshot is crash consistent (like pulling the power).</p>'
                    + '<p><strong>Minimum IAM policy</strong></p><pre style="background:#f4f4f4;padding:10px;border-radius:4px;font-size:12px;overflow-x:auto;">{\n  "Version": "2012-10-17",\n  "Statement": [{\n    "Effect": "Allow",\n    "Action": [\n      "ec2:CreateImage",\n      "ec2:DeregisterImage",\n      "ec2:DescribeImages",\n      "ec2:DescribeInstances",\n      "ec2:RebootInstances"\n    ],\n    "Resource": "*"\n  }]\n}</pre>'
                    + '<p><strong>Restoring from AMI</strong><br>In the AWS Console: EC2 &rarr; AMIs &rarr; select your AMI &rarr; Launch Instance. Or use <code>aws ec2 run-instances --image-id ami-xxx</code>.</p>';
                box.appendChild(head);
                box.appendChild(body);
                ov.appendChild(box);
                ov.addEventListener('click', function(e) { if (e.target === ov) ov.style.display = 'none'; });
                document.body.appendChild(ov);
            }
            </script>

            <!-- SYSTEM INFO CARD -->
            <div class="cs-card cs-card--purple">
                <div class="cs-card-stripe cs-stripe--purple" style="background:linear-gradient(135deg,#6a1b9a 0%,#8e24aa 100%);display:flex;align-items:center;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">⚙ System Info</h2></div>

                <div class="cs-info-row">
                    <span>Backup method</span>
                    <strong><?php echo esc_html($dump_method); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Restore method</span>
                    <strong><?php echo esc_html($restore_method); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>PHP memory limit</span>
                    <strong><?php echo ini_get('memory_limit'); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Max execution time</span>
                    <strong><?php echo ini_get('max_execution_time') === '0' ? 'Unlimited' : ini_get('max_execution_time') . 's'; ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Database</span>
                    <strong><?php echo esc_html($db_label); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Total backups stored</span>
                    <strong><?php echo count($backups); ?></strong>
                </div>
                <div class="cs-info-row">
                    <span>Backup directory</span>
                    <strong class="cs-path"><?php echo esc_html(CS_BACKUP_DIR); ?></strong>
                </div>

                <!-- Traffic-light disk row -->
                <div class="cs-info-row cs-disk-row">
                    <span>Disk free space</span>
                    <strong class="cs-tl cs-tl--<?php echo $disk_status; ?>">
                        <span class="cs-tl-dot"></span>
                        <?php echo $free_bytes !== false ? cs_format_size((int)$free_bytes) : 'Unavailable'; ?>
                        <?php if ($total_bytes): ?>
                            <span class="cs-disk-of">/ <?php echo cs_format_size((int)$total_bytes); ?></span>
                        <?php endif; ?>
                    </strong>
                </div>
                <div class="cs-info-row">
                    <span>Latest backup size</span>
                    <strong><?php echo $latest_size > 0 ? cs_format_size($latest_size) : '—'; ?></strong>
                </div>

                <?php if ($free_pct !== null): ?>
                <div class="cs-info-row">
                    <span>Percentage free space</span>
                    <strong class="cs-tl cs-tl--<?php echo $disk_status; ?>">
                        <span class="cs-tl-dot"></span>
                        <?php echo $free_pct; ?>%
                    </strong>
                </div>
                <div class="cs-disk-bar-wrap">
                    <div class="cs-disk-bar">
                        <div class="cs-disk-bar-fill cs-disk-fill--<?php echo $disk_status; ?>"
                             style="width:<?php echo $free_pct; ?>%"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /cs-grid-1 -->

        <!-- ===================== MANUAL BACKUP ===================== -->
        <div class="cs-section-ribbon"><span>Manual Backup</span></div>
        <div class="cs-card cs-card--orange cs-full">
            <div class="cs-card-stripe cs-stripe--orange" style="background:linear-gradient(135deg,#e65100 0%,#f57c00 100%);display:flex;align-items:center;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">▶ Run Backup Now</h2></div>
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
                'dropins'   => $dropins_size,
                'free'      => $free_bytes !== false ? (int)$free_bytes : 0,
                'latest'    => $latest_size,  // actual compressed size of last backup
            ];
            ?>
            <script>
            window.CS_BACKUP_SIZES = <?php echo json_encode($backup_sizes); ?>;
            </script>
            <div class="cs-run-grid">
                <!-- Column 1: Core -->
                <div class="cs-options-col">
                    <p class="cs-options-col-heading">Core</p>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-db" checked data-size="<?php echo $db_size; ?>"> Database <code><?php echo $db_size > 0 ? cs_format_size($db_size) : '~unknown'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-media" checked data-size="<?php echo $upload_size; ?>"> Media uploads <code><?php echo cs_format_size($upload_size); ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-plugins" checked data-size="<?php echo $plugins_size; ?>"> Plugins <code><?php echo cs_format_size($plugins_size); ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-themes" checked data-size="<?php echo $themes_size; ?>"> Themes <code><?php echo cs_format_size($themes_size); ?></code></label>
                </div>
                <!-- Column 2: Other -->
                <div class="cs-options-col cs-options-col--other">
                    <p class="cs-options-col-heading">Other</p>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-mu" <?php echo $mu_size > 0 ? 'checked' : ''; ?> data-size="<?php echo $mu_size; ?>"> Must-use plugins <code><?php echo $mu_size > 0 ? cs_format_size($mu_size) : '0 B'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-languages" <?php echo $lang_size > 0 ? 'checked' : ''; ?> data-size="<?php echo $lang_size; ?>"> Languages <code><?php echo $lang_size > 0 ? cs_format_size($lang_size) : '0 B'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-dropins" data-size="<?php echo $dropins_size; ?>"> Dropins <small>(object-cache.php…)</small> <code><?php echo $dropins_size > 0 ? cs_format_size($dropins_size) : '0 B'; ?></code></label>
                    <label class="cs-option-label"><input type="checkbox" id="cs-include-htaccess" <?php echo $htaccess_size > 0 ? 'checked' : ''; ?> data-size="<?php echo $htaccess_size; ?>"> .htaccess <code><?php echo $htaccess_size > 0 ? cs_format_size($htaccess_size) : 'not found'; ?></code></label>
                    <label class="cs-option-label cs-option-sensitive">
                        <input type="checkbox" id="cs-include-wpconfig" data-size="<?php echo $wpconfig_size; ?>">
                        wp-config.php <code><?php echo $wpconfig_size > 0 ? cs_format_size($wpconfig_size) : 'not found'; ?></code>
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
                            <span class="cs-summary-value <?php echo 'cs-free-' . $disk_status; ?>" id="cs-free-space">
                                <?php echo $free_bytes !== false ? cs_format_size((int)$free_bytes) : 'Unknown'; ?>
                            </span>
                        </div>
                        <div class="cs-backup-summary-row" id="cs-space-warn-row" style="display:none">
                            <span class="cs-summary-warn" id="cs-space-warn"></span>
                        </div>
                    </div>
                    <button type="button" id="cs-run-backup" class="button button-primary cs-btn-lg">▶ Run Backup Now</button>
                    <div id="cs-backup-progress" style="display:none" class="cs-progress-panel">
                        <p id="cs-backup-msg" class="cs-progress-msg">Starting backup...</p>
                        <div class="cs-progress-bar"><div id="cs-backup-fill" class="cs-progress-fill"></div></div>
                        <p class="cs-help">Do not close this window. Large sites may take several minutes.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================== BACKUP HISTORY ===================== -->
        <div class="cs-section-ribbon"><span>Backup History</span></div>
        <div class="cs-card cs-card--teal cs-full">
            <div class="cs-card-stripe cs-stripe--teal" style="background:linear-gradient(135deg,#004d40 0%,#00897b 100%);display:flex;align-items:center;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">🕓 Backup History</h2></div>
            <?php if (empty($backups)): ?>
                <p class="cs-empty">No backups yet. Run your first backup above.</p>
            <?php else: ?>
            <div class="cs-table-wrap">
            <table class="widefat cs-table">
                <thead>
                    <tr>
                        <th class="cs-col-actions" style="width:72px!important;min-width:72px!important;max-width:72px!important;">Actions</th>
                        <th class="cs-col-num" style="width:18px!important;min-width:18px!important;max-width:18px!important;padding-left:4px!important;padding-right:4px!important;">#</th>
                        <th>Filename</th>
                        <th class="cs-col-size">Size</th>
                        <th class="cs-col-meta">Created</th>
                        <th class="cs-col-age">Age</th>
                        <th class="cs-col-type">Type</th>
                        <?php if ($s3_bucket): ?><th class="cs-col-s3" style="width:36px!important;text-align:center;" title="S3 sync status">S3</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $s3_log = (array) get_option('cs_s3_log', []);
                foreach ($backups as $i => $b):
                    $is_db  = (str_contains($b['name'], '_db') || str_contains($b['name'], '_full'));
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
                        <td class="cs-col-num cs-idx" style="padding:6px 8px!important;vertical-align:middle!important;white-space:nowrap;"><?php echo $i + 1; ?><?php if ($i === 0) echo '&nbsp;<span class="cs-latest-badge">latest</span>'; ?></td>
                        <td class="cs-filename" style="padding:6px 8px!important;vertical-align:middle!important;" title="<?php echo esc_attr($b['name']); ?>"><?php echo esc_html($b['name']); ?></td>
                        <td class="cs-col-size" style="padding:6px 8px!important;vertical-align:middle!important;"><?php echo esc_html(cs_format_size($b['size'])); ?></td>
                        <td class="cs-col-meta cs-created" style="padding:6px 8px!important;vertical-align:middle!important;"><?php echo esc_html($b['date']); ?></td>
                        <td class="cs-col-age cs-age" style="padding:6px 8px!important;vertical-align:middle!important;"><?php echo esc_html(cs_human_age($b['mtime'])); ?></td>
                        <td class="cs-col-type" style="padding:6px 8px!important;vertical-align:middle!important;"><span class="cs-type-badge cs-type-<?php echo esc_attr(strtolower(explode(' ', $b['type'])[0])); ?>"><?php echo esc_html($b['type']); ?></span></td>
                        <?php if ($s3_bucket): ?>
                        <td style="width:36px!important;text-align:center;padding:6px 4px!important;vertical-align:middle!important;">
                            <?php if (!$s3_entry): ?>
                                <span title="Not synced to S3" style="color:#bbb;font-size:16px;">—</span>
                            <?php elseif ($s3_entry['ok']): ?>
                                <span title="Synced to S3 on <?php echo esc_attr(date('Y-m-d H:i', $s3_entry['time'])); ?>" style="color:#2e7d32;font-size:16px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                                </span>
                            <?php else: ?>
                                <span title="S3 sync failed: <?php echo esc_attr($s3_entry['error'] ?? 'unknown error'); ?>" style="color:#c62828;font-size:16px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- .cs-table-wrap -->
            <p class="cs-help cs-mt">Showing <?php echo count($backups); ?> backup(s). Retention limit: <?php echo $retention; ?>. Oldest backups are removed automatically after each run.</p>
            <?php endif; ?>
        </div>

        <!-- ===================== RESTORE FROM UPLOAD ===================== -->
        <div class="cs-section-ribbon"><span>Restore from File</span></div>
        <div class="cs-card cs-card--red cs-full">
            <div class="cs-card-stripe cs-stripe--red" style="background:linear-gradient(135deg,#b71c1c 0%,#e53935 100%);display:flex;align-items:center;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;"><h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">↩ Restore from Uploaded File</h2></div>
            <div class="cs-restore-upload-grid">
                <div>
                    <p>Upload a <code>.zip</code> (from this plugin) or a raw <code>.sql</code> file to restore the database.</p>
                    <input type="file" id="cs-restore-file" accept=".zip,.sql">
                    <button type="button" id="cs-restore-upload-btn" class="button button-secondary cs-mt">↩ Restore from Upload</button>
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
                <h2>⚠ Restore Database</h2>
                <div class="cs-modal-body">
                    <div class="cs-warning-box">
                        <strong>BEFORE YOU RESTORE — TAKE A SNAPSHOT</strong>
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
                    <button type="button" id="cs-modal-cancel" class="button">Cancel — keep current database</button>
                    <button type="button" id="cs-modal-confirm" class="button button-primary cs-btn-danger" disabled>Restore Now</button>
                </div>
                <div id="cs-modal-progress" style="display:none" class="cs-modal-progress">
                    <p id="cs-modal-progress-msg" class="cs-progress-msg">Enabling maintenance mode...</p>
                    <div class="cs-progress-bar"><div id="cs-modal-fill" class="cs-progress-fill"></div></div>
                    <p class="cs-help">Do not close this window.</p>
                </div>
            </div>
        </div>
        <div id="cs-modal-overlay" class="cs-modal-overlay" style="display:none"></div>

    </div><!-- /cs-wrap -->
    <?php
}

// ============================================================
// AJAX — Run backup
// ============================================================

add_action('wp_ajax_cs_run_backup', function (): void {
    cs_verify_nonce();
    cs_ensure_backup_dir();

    $include_db        = !empty($_POST['include_db']);
    $include_media     = !empty($_POST['include_media']);
    $include_plugins   = !empty($_POST['include_plugins']);
    $include_themes    = !empty($_POST['include_themes']);
    $include_mu        = !empty($_POST['include_mu']);
    $include_languages = !empty($_POST['include_languages']);
    $include_dropins   = !empty($_POST['include_dropins']);
    $include_htaccess  = !empty($_POST['include_htaccess']);
    $include_wpconfig  = !empty($_POST['include_wpconfig']);

    if (!$include_db && !$include_media && !$include_plugins && !$include_themes
        && !$include_mu && !$include_languages && !$include_dropins
        && !$include_htaccess && !$include_wpconfig) {
        wp_send_json_error('Select at least one option.');
    }

    set_time_limit(0);
    ignore_user_abort(true);

    try {
        $filename = cs_create_backup(
            $include_db, $include_media, $include_plugins, $include_themes,
            $include_mu, $include_languages, $include_dropins, $include_htaccess, $include_wpconfig
        );
        cs_enforce_retention();
        $s3 = $GLOBALS['cs_last_s3_result'] ?? ['skipped' => true];
        $s3_msg = '';
        if (!isset($s3['skipped'])) {
            $s3_msg = $s3['ok']
                ? '✓ Synced to ' . $s3['dest']
                : '⚠ S3 sync failed: ' . $s3['error'];
        }
        wp_send_json_success([
            'message'  => 'Backup complete: ' . $filename,
            'filename' => $filename,
            's3_ok'    => $s3['ok'] ?? null,
            's3_msg'   => $s3_msg,
        ]);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// ============================================================
// AJAX — Delete backup
// ============================================================

add_action('wp_ajax_cs_delete_backup', function (): void {
    cs_verify_nonce();
    $file = sanitize_file_name($_POST['file'] ?? '');
    $path = CS_BACKUP_DIR . $file;
    if (file_exists($path) && strpos(realpath($path), realpath(CS_BACKUP_DIR)) === 0) {
        unlink($path);
        wp_send_json_success('Deleted.');
    }
    wp_send_json_error('File not found.');
});

// ============================================================
// AJAX — Restore from stored backup
// ============================================================

add_action('wp_ajax_cs_restore_backup', function (): void {
    cs_verify_nonce();
    set_time_limit(0);
    ignore_user_abort(true);

    $file = sanitize_file_name($_POST['file'] ?? '');
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
    cs_verify_nonce();
    set_time_limit(0);
    ignore_user_abort(true);

    if (empty($_FILES['backup_file'])) {
        wp_send_json_error('No file uploaded.');
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION));

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
// AJAX — Save retention
// ============================================================

add_action('wp_ajax_cs_test_s3', function (): void {
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
    $content = "CloudScale Backup connection test\nWritten: " . date('Y-m-d H:i:s T') . "\nBucket: " . $bucket . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'cs_test_');
    file_put_contents($tmp, $content);
    $real_tmp = realpath($tmp) ?: $tmp;
    $cmd = escapeshellarg($aws) . ' s3 cp ' . escapeshellarg($real_tmp) . ' ' . escapeshellarg($dest) . ' 2>&1';
    $out = trim((string) shell_exec($cmd));
    @unlink($tmp);
    // AWS CLI outputs nothing on success with --only-show-errors, but without that flag
    // it outputs "upload: /path to s3://..." which is a success message not an error
    if ($out && stripos($out, 'upload:') === false && stripos($out, 'completed') === false) {
        wp_send_json_error('Upload failed: ' . $out);
    }
    wp_send_json_success('Test file written to ' . $dest);
});

add_action('wp_ajax_cs_save_s3', function (): void {
    cs_verify_nonce();
    $bucket = sanitize_text_field($_POST['bucket'] ?? '');
    $prefix = sanitize_text_field($_POST['prefix'] ?? 'backups/');
    // Ensure prefix ends with /
    $prefix = rtrim($prefix, '/') . '/';
    update_option('cs_s3_bucket', $bucket);
    update_option('cs_s3_prefix', $prefix);
    wp_send_json_success(['bucket' => $bucket, 'prefix' => $prefix]);
});

// ============================================================
// AJAX — AMI snapshot operations
// ============================================================

add_action('wp_ajax_cs_save_ami', function (): void {
    cs_verify_nonce();
    $prefix = sanitize_text_field($_POST['prefix'] ?? '');
    $reboot = !empty($_POST['reboot']);
    update_option('cs_ami_prefix', $prefix);
    update_option('cs_ami_reboot', $reboot);
    wp_send_json_success(['prefix' => $prefix, 'reboot' => $reboot]);
});

add_action('wp_ajax_cs_create_ami', function (): void {
    cs_verify_nonce();
    set_time_limit(120);

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
        // Keep last 20 entries
        if (count($log) > 20) $log = array_slice($log, -20);
        update_option('cs_ami_log', $log, false);

        $msg = 'AMI creation started: ' . $ami_id . ' (' . $ami_name . ')';
        if ($reboot) $msg .= ' — instance will reboot shortly';
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

        error_log('[CloudScale Backup] AMI creation failed: ' . $out);
        wp_send_json_error('AMI creation failed: ' . $out);
    }
});

add_action('wp_ajax_cs_ami_status', function (): void {
    cs_verify_nonce();

    $aws = cs_find_aws();
    if (!$aws) {
        wp_send_json_error('AWS CLI not found.');
    }

    // Accept optional specific AMI ID for per-row refresh
    $specific_ami = isset($_POST['ami_id']) ? sanitize_text_field($_POST['ami_id']) : '';

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
    cs_verify_nonce();

    $ami_id = sanitize_text_field($_POST['ami_id'] ?? '');
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
        // Update the log to mark as deregistered
        $log = (array) get_option('cs_ami_log', []);
        foreach ($log as &$e) {
            if (($e['ami_id'] ?? '') === $ami_id) {
                $e['state'] = 'deregistered';
                $e['ok']    = false;
            }
        }
        unset($e);
        update_option('cs_ami_log', $log, false);

        wp_send_json_success('AMI ' . $ami_id . ' deregistered successfully.');
    } else {
        error_log('[CloudScale Backup] AMI deregister failed: ' . $out);
        wp_send_json_error('Deregister failed: ' . $out);
    }
});

add_action('wp_ajax_cs_save_retention', function (): void {
    cs_verify_nonce();
    $r = max(1, min(9999, intval($_POST['retention'] ?? 8)));
    update_option('cs_retention', $r);
    wp_send_json_success('Retention set to ' . $r);
});

// ============================================================
// Download handler
// ============================================================

add_action('admin_post_cs_download', function (): void {
    check_admin_referer('cs_download');
    if (!current_user_can('manage_options')) wp_die('Forbidden');

    $file = sanitize_file_name($_GET['file'] ?? '');
    $path = CS_BACKUP_DIR . $file;

    if (!file_exists($path) || strpos(realpath($path), realpath(CS_BACKUP_DIR)) !== 0) {
        wp_die('File not found.');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache');
    readfile($path);
    exit;
});

// ============================================================
// Dashboard widget
// ============================================================

add_action('wp_dashboard_setup', function (): void {
    wp_add_dashboard_widget(
        'cs_backup_status_widget',
        '&#128736; CloudScale Backup Status',
        'cs_render_dashboard_widget'
    );
});

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
    <div style="<?php echo $widget_style; ?>">
        <div style="<?php echo $stats_style; ?>">

            <div style="<?php echo $row_style; ?>">
                <span style="<?php echo $label_style; ?>">Last backup</span>
                <span style="<?php echo $value_style; ?> color:<?php echo $age_color; ?>">
                    <?php echo esc_html($age_label); ?>
                </span>
            </div>

            <div style="<?php echo $row_style; ?>">
                <span style="<?php echo $label_style; ?>">Last backup size</span>
                <span style="<?php echo $value_style; ?>">
                    <?php echo $latest ? esc_html(cs_format_size((int)$latest['size'])) : '—'; ?>
                </span>
            </div>

            <div style="<?php echo $row_style; ?>">
                <span style="<?php echo $label_style; ?>">Backups stored</span>
                <span style="<?php echo $value_style; ?>">
                    <?php
                    $retention = (int) get_option('cs_retention', 8);
                    echo esc_html($count . ' / ' . $retention);
                    ?>
                </span>
            </div>

            <div style="<?php echo $row_style; ?> border-bottom:none;">
                <span style="<?php echo $label_style; ?>">Free disk space</span>
                <span style="<?php echo $value_style; ?>">
                    <?php echo $free_bytes !== false ? esc_html(cs_format_size((int)$free_bytes)) : '—'; ?>
                </span>
            </div>

        </div>

        <a href="<?php echo esc_url($settings_url); ?>"
           style="<?php echo $btn_base; ?> background: linear-gradient(90deg, #e65100 0%, #b71c1c 100%); margin-top:8px;">
            &#128736; CloudScale Backup &amp; Restore
        </a>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=cs_run_backup&quick=1'), 'cs_quick_backup')); ?>"
           style="<?php echo $btn_base; ?> background: linear-gradient(90deg, #00897b 0%, #1b5e20 100%); margin-top:2px; border-radius: 0 0 3px 3px;">
            &#9654; Run Backup Now
        </a>
    </div>
    <?php
}

// ============================================================
// Maintenance mode
// ============================================================

function cs_maintenance_on(): void {
    $php = '<?php $upgrading = ' . time() . '; ?>';
    file_put_contents(CS_MAINT_FILE, $php);
}

function cs_maintenance_off(): void {
    if (file_exists(CS_MAINT_FILE)) {
        @unlink(CS_MAINT_FILE);
    }
}

// ============================================================
// Core — Create backup
// ============================================================

function cs_create_backup(
    bool $include_db, bool $include_media,
    bool $include_plugins  = false, bool $include_themes    = false,
    bool $include_mu       = false, bool $include_languages = false,
    bool $include_dropins  = false, bool $include_htaccess  = false,
    bool $include_wpconfig = false
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
        if ($include_htaccess)  $tcode .= 'h';
        if ($include_wpconfig)  $tcode .= 'c';
    }

    // Increment global sequence number, skip any that already exist on disk
    $seq = (int) get_option('cs_backup_seq', 0);
    do {
        $seq++;
        $filename = 'bkup_' . $tcode . $seq . '.zip';
    } while (file_exists(CS_BACKUP_DIR . $filename));
    update_option('cs_backup_seq', $seq, false);
    $zip_path = CS_BACKUP_DIR . $filename;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Cannot create zip at: ' . $zip_path);
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
        $mu_path = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
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
        'plugin_version'    => CS_VERSION,
        'created'           => date('c'),
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
        'include_htaccess'  => $include_htaccess,
        'include_wpconfig'  => $include_wpconfig,
    ], JSON_PRETTY_PRINT));

    $zip->close();

    // S3 sync — result stored in global for AJAX handler to include in response
    $GLOBALS['cs_last_s3_result'] = cs_sync_to_s3(CS_BACKUP_DIR . $filename);

    return $filename;
}

// ============================================================
// S3 sync
// ============================================================

// ============================================================
// EC2 metadata helpers
// ============================================================

function cs_get_imds_token(): string {
    if (!function_exists('curl_init')) return '';
    try {
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
        return ($code === 200 && $token) ? $token : '';
    } catch (\Throwable $e) {
        return '';
    }
}

function cs_imds_get(string $path): string {
    if (!function_exists('curl_init')) return '';
    try {
        // Try IMDSv2 first, fall back to IMDSv1
        $token   = cs_get_imds_token();
        $headers = $token ? ['X-aws-ec2-metadata-token: ' . $token] : [];

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

        return ($code === 200 && $body) ? trim($body) : '';
    } catch (\Throwable $e) {
        return '';
    }
}

function cs_get_instance_id(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    // Check transient first to avoid IMDS calls on every page load
    $transient = get_transient('cs_ec2_instance_id');
    if ($transient !== false) {
        $cached = $transient;
        return $cached;
    }

    try {
        $cached = cs_imds_get('/latest/meta-data/instance-id');
    } catch (\Throwable $e) {
        $cached = '';
    }

    // Cache for 1 hour — empty string means "not on EC2", still cache it
    set_transient('cs_ec2_instance_id', $cached, HOUR_IN_SECONDS);
    return $cached;
}

function cs_get_instance_region(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $transient = get_transient('cs_ec2_region');
    if ($transient !== false) {
        $cached = $transient;
        return $cached;
    }

    try {
        // Availability zone is e.g. "eu-west-1a" — strip the trailing letter
        $az = cs_imds_get('/latest/meta-data/placement/availability-zone');
        $cached = $az ? preg_replace('/[a-z]$/', '', $az) : '';
    } catch (\Throwable $e) {
        $cached = '';
    }

    set_transient('cs_ec2_region', $cached, HOUR_IN_SECONDS);
    return $cached;
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

function cs_sync_to_s3(string $local_path): array {
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
        error_log('[CloudScale Backup] S3 sync error: ' . $out);
        $result = ['ok' => false, 'dest' => $dest, 'error' => $out];
        $log[$filename] = ['ok' => false, 'time' => time(), 'dest' => $dest, 'error' => $out];
    } else {
        error_log('[CloudScale Backup] S3 sync OK: ' . $dest);
        $result = ['ok' => true, 'dest' => $dest];
        $log[$filename] = ['ok' => true, 'time' => time(), 'dest' => $dest];
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

function cs_dump_database(): string {
    return cs_mysqldump_available() ? cs_dump_via_mysqldump() : cs_dump_via_php($GLOBALS['wpdb']);
}

function cs_mysqldump_available(): bool {
    exec('which mysqldump 2>/dev/null', $out, $rc);
    return $rc === 0;
}

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
        @unlink($tmp);
        return cs_dump_via_php($GLOBALS['wpdb']);
    }

    $sql = file_get_contents($tmp);
    unlink($tmp);
    return $sql;
}

function cs_dump_via_php(\wpdb $wpdb): string {
    $out   = [];
    $out[] = '-- CloudScale Free Backup v' . CS_VERSION;
    $out[] = '-- Generated: ' . date('Y-m-d H:i:s');
    $out[] = '-- Site: ' . get_site_url();
    $out[] = '-- Database: ' . DB_NAME;
    $out[] = '';
    $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $out[] = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = '';

    $tables = $wpdb->get_col('SHOW TABLES');

    foreach ($tables as $table) {
        $create  = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
        $out[]   = "DROP TABLE IF EXISTS `{$table}`;";
        $out[]   = $create[1] . ';';
        $out[]   = '';

        $total   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $columns = $wpdb->get_col("DESCRIBE `{$table}`");
        $chunk   = 500;
        $offset  = 0;

        while ($offset < $total) {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $chunk, $offset),
                ARRAY_N
            );
            if (empty($rows)) break;

            $col_list = '`' . implode('`, `', $columns) . '`';
            $vals     = [];
            foreach ($rows as $row) {
                $escaped = array_map(fn($v) => $v === null ? 'NULL' : "'" . esc_sql($v) . "'", $row);
                $vals[]  = '(' . implode(', ', $escaped) . ')';
            }

            $out[] = "INSERT INTO `{$table}` ({$col_list}) VALUES";
            $out[] = implode(",\n", $vals) . ';';
            $out[] = '';
            $offset += $chunk;
        }

        $out[] = '';
    }

    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    return implode("\n", $out);
}

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

function cs_restore_sql_file(string $path): void {
    $sql = file_get_contents($path);
    if (empty($sql)) {
        throw new Exception('SQL file is empty.');
    }
    cs_execute_sql_string($sql);
}

function cs_execute_sql_string(string $sql): void {
    global $wpdb;

    if (cs_mysql_cli_available()) {
        cs_restore_via_mysql_cli($sql);
        return;
    }

    $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

    foreach (cs_split_sql($sql) as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt) || str_starts_with($stmt, '--') || str_starts_with($stmt, '/*')) {
            continue;
        }
        $wpdb->query($stmt);
        if ($wpdb->last_error) {
            error_log('CS Restore statement error: ' . $wpdb->last_error . ' | ' . substr($stmt, 0, 200));
        }
    }

    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
}

function cs_mysql_cli_available(): bool {
    exec('which mysql 2>/dev/null', $out, $rc);
    return $rc === 0;
}

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
    unlink($tmp);

    if ($rc !== 0) {
        throw new Exception('mysql CLI restore failed: ' . implode(' | ', $output));
    }
}

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

function cs_parse_db_host(string $host): array {
    $port = '3306';
    if (str_contains($host, ':')) {
        [$host, $port] = explode(':', $host, 2);
    }
    return [$host, $port];
}

function cs_list_backups(): array {
    $backups = [];
    if (!is_dir(CS_BACKUP_DIR)) return $backups;

    $files = glob(CS_BACKUP_DIR . '*.zip') ?: [];

    foreach ($files as $file) {
        $name = basename($file);
        // bkup_{tcode}{seq}.zip — decode type from single-char code in filename
        // Legacy formats: backup_full_2026-02-25_... or full_000001.zip
        if (preg_match('/^bkup_([a-zA-Z]*)\d+\.zip$/', $name, $tm)) {
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
            'date'  => date('Y-m-d H:i', filemtime($file)),
            'mtime' => filemtime($file),
        ];
    }

    usort($backups, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return $backups;
}

function cs_enforce_retention(): void {
    $keep    = intval(get_option('cs_retention', 8));
    $backups = cs_list_backups();
    foreach (array_slice($backups, $keep) as $b) {
        @unlink(CS_BACKUP_DIR . $b['name']);
    }
}

function cs_dir_size(string $dir): int {
    $size = 0;
    if (!is_dir($dir)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
        $size += $f->getSize();
    }
    return $size;
}

function cs_format_size(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function cs_human_age(int $timestamp): string {
    $diff = time() - $timestamp;
    if ($diff < 3600)   return round($diff / 60) . ' min ago';
    if ($diff < 86400)  return round($diff / 3600) . ' hr ago';
    if ($diff < 604800) return round($diff / 86400) . ' days ago';
    return date('j M Y', $timestamp);
}

function cs_verify_nonce(): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions.');
    }
    if (!check_ajax_referer('cs_nonce', 'nonce', false)) {
        wp_send_json_error('Security check failed.');
    }
}
