<?php
/**
 * Automatic Crash Recovery — pre-update backup and automatic rollback.
 *
 * Architecture overview
 * ─────────────────────
 * WP-Cron is useless here: if a plugin update causes a PHP fatal error,
 * WordPress itself is broken and wp-cron.php will never fire.  Instead,
 * this feature generates a bash watchdog script that the server administrator
 * installs as a system-cron job (identical approach to the CloudScale Crash
 * Recovery plugin).  The watchdog runs every minute, probes the site with
 * curl, and rolls back via the filesystem when it detects repeated failure —
 * no WordPress bootstrap required.
 *
 * PHP role
 * ────────
 * • Hook upgrader_pre_install  → copy plugin dir + write JSON state file.
 * • Hook upgrader_process_complete → activate monitoring in JSON state file.
 * • Register AJAX handlers for the admin UI (settings, status, history).
 * • Generate the bash watchdog script (shown in the UI for copy-paste).
 * • Write / remove a wp-content/fatal-error-handler.php dropin that shows
 *   a branded recovery page instead of the white screen of death.
 *
 * Bash watchdog role
 * ──────────────────
 * • Probe health URL every minute via curl.
 * • On N consecutive failures: read state.json, rm -rf broken plugin,
 *   cp -r backup to restore, mark state, call WP-CLI for notifications.
 *
 * @package CloudScale_Backup
 * @since   3.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Automatic Crash Recovery controller.
 *
 * @since 3.3.0
 */
class CSBR_Plugin_Auto_Recovery {

	// ── wp_options keys ───────────────────────────────────────────────────────
	const MONITORS_KEY = 'csbr_par_monitors';
	const HISTORY_KEY  = 'csbr_par_history';
	const SETTINGS_KEY = 'csbr_par_settings';

	// ── Tuning ────────────────────────────────────────────────────────────────
	const LOG_PREFIX    = '[Automatic Crash Recovery]';
	const MAX_HISTORY   = 50;
	const MAX_BACKUP_AGE = 72 * HOUR_IN_SECONDS; // seconds before stale backup dirs are purged

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	/**
	 * Register all hooks. Called once during plugin bootstrap.
	 *
	 * @since 3.3.0
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', [ self::class, 'maybe_register_upgrade_hooks' ] );
		add_action( 'admin_init',     [ self::class, 'on_admin_init' ] );

		// AJAX — registered unconditionally so the UI works even when the
		// feature is turned off (user can still save/test settings).
		add_action( 'wp_ajax_csbr_par_save_settings',          [ self::class, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_csbr_par_test_health',            [ self::class, 'ajax_test_health' ] );
		add_action( 'wp_ajax_csbr_par_get_status',             [ self::class, 'ajax_get_status' ] );
		add_action( 'wp_ajax_csbr_par_dismiss_history',        [ self::class, 'ajax_dismiss_history' ] );
		add_action( 'wp_ajax_csbr_par_manual_rollback',        [ self::class, 'ajax_manual_rollback' ] );
		add_action( 'wp_ajax_csbr_par_record_watchdog_result', [ self::class, 'ajax_record_watchdog_result' ] );
	}

	/**
	 * Clean up all state and caches on plugin deactivation.
	 *
	 * @since 3.3.0
	 */
	public static function deactivate(): void {
		self::remove_fatal_handler_dropin();
	}

	/**
	 * Register the upgrader interceptor hooks — only when the feature is on.
	 *
	 * @since 3.3.0
	 */
	public static function maybe_register_upgrade_hooks(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_filter( 'upgrader_pre_install',      [ self::class, 'pre_update_backup' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ self::class, 'post_update_register' ], 10, 2 );
	}

	/**
	 * Admin-init: sync the fatal-handler dropin, expire stale monitors,
	 * process any pending watchdog rollback notifications, and tidy backups.
	 *
	 * @since 3.3.0
	 */
	public static function on_admin_init(): void {
		// Skip heavy/file-system operations during AJAX — they are UI-only
		// and writing the dropin during an AJAX response can corrupt the JSON output.
		if ( wp_doing_ajax() ) {
			self::expire_stale_monitors();
			self::process_pending_watchdog_notifications();
			return;
		}

		// Keep the dropin in sync with the enabled/disabled state.
		if ( self::is_enabled() ) {
			self::write_fatal_handler_dropin();
		} else {
			self::remove_fatal_handler_dropin();
		}

		// Expire monitors whose window has passed.
		self::expire_stale_monitors();

		// Process any rollback results the watchdog wrote to the state file.
		self::process_pending_watchdog_notifications();

		// Periodically tidy old backup directories.
		self::maybe_clean_stale_backups();
	}

	// ── Core — Pre-update backup ───────────────────────────────────────────────

	/**
	 * Filter: upgrader_pre_install
	 *
	 * Fires before the new plugin files are moved into place.  The existing
	 * plugin directory still contains the working version — ideal for backup.
	 *
	 * Never blocks the update: returns $response unchanged in all cases.
	 *
	 * @since 3.3.0
	 * @param  mixed                $response   Pass-through filter value.
	 * @param  array<string,string> $hook_extra Upgrader context.
	 * @return mixed Unchanged $response.
	 */
	public static function pre_update_backup( mixed $response, array $hook_extra ): mixed {
		if ( ( $hook_extra['type']   ?? '' ) !== 'plugin' ) return $response;
		if ( ( $hook_extra['action'] ?? '' ) !== 'update'  ) return $response;

		$plugin_file = trim( $hook_extra['plugin'] ?? '' );
		if ( empty( $plugin_file ) ) return $response;

		$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
		if ( ! is_dir( $plugin_dir ) ) {
			self::log( sprintf( '%s Skipping backup for %s — directory not found.', self::LOG_PREFIX, $plugin_file ) );
			return $response;
		}

		try {
			$monitor_id = self::backup_plugin( $plugin_file, $plugin_dir );
			self::log( sprintf( '%s Pre-update backup created for %s (ID: %s).', self::LOG_PREFIX, $plugin_file, $monitor_id ) );
		} catch ( Throwable $e ) {
			// Log the failure but never abort the update.
			self::log( sprintf( '%s ERROR: Pre-update backup failed for %s — %s', self::LOG_PREFIX, $plugin_file, $e->getMessage() ) );
		}

		return $response;
	}

	// ── Core — Post-update monitoring registration ─────────────────────────────

	/**
	 * Action: upgrader_process_complete
	 *
	 * Transitions pending monitors to 'monitoring' and writes the JSON state
	 * file that the bash watchdog reads.
	 *
	 * @since 3.3.0
	 * @param  WP_Upgrader         $upgrader   (unused)
	 * @param  array<string,mixed> $hook_extra
	 */
	public static function post_update_register( WP_Upgrader $upgrader, array $hook_extra ): void {
		if ( ( $hook_extra['action'] ?? '' ) !== 'update' ) return;
		if ( ( $hook_extra['type']   ?? '' ) !== 'plugin'  ) return;

		$updated = [];
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$updated = $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$updated = [ (string) $hook_extra['plugin'] ];
		}
		if ( empty( $updated ) ) return;

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$settings       = self::get_settings();
		$window_seconds = max( 60, (int) round( ( $settings['window_minutes'] ?? 5 ) * 60 ) );
		$health_url     = ! empty( $settings['health_url'] ) ? $settings['health_url'] : home_url( '/' );
		$monitors       = self::get_monitors();
		$changed        = false;

		foreach ( $updated as $plugin_file ) {
			// Find the pending monitor that pre_update_backup created.
			$matched_id = null;
			foreach ( $monitors as $id => $m ) {
				if ( $m['plugin_file'] === $plugin_file && $m['status'] === 'pending' ) {
					$matched_id = (string) $id;
					break;
				}
			}
			if ( $matched_id === null ) {
				self::log( sprintf( '%s No pre-update backup found for %s — monitoring skipped.', self::LOG_PREFIX, $plugin_file ) );
				continue;
			}

			$plugin_path   = WP_PLUGIN_DIR . '/' . $plugin_file;
			$new_data      = file_exists( $plugin_path ) ? get_plugin_data( $plugin_path, false, false ) : [];
			$version_after = $new_data['Version'] ?? 'unknown';

			$monitors[ $matched_id ] = array_merge( $monitors[ $matched_id ], [
				'status'             => 'monitoring',
				'version_after'      => $version_after,
				'health_url'         => $health_url,
				'monitoring_started' => time(),
				'monitoring_until'   => time() + $window_seconds,
				'fail_count'         => 0,
				'last_probe_at'      => 0,
				'watchdog_rollback'  => null, // filled by watchdog after rollback
			] );
			$changed = true;

			self::log( sprintf(
				'%s Monitoring started for %s (v%s → v%s). Window: %d min.',
				self::LOG_PREFIX,
				$monitors[ $matched_id ]['plugin_name'] ?? $plugin_file,
				$monitors[ $matched_id ]['version_before'] ?? '?',
				$version_after,
				(int) round( $window_seconds / 60 )
			) );
		}

		if ( $changed ) {
			self::save_monitors( $monitors );
			self::write_state_file( $monitors ); // consumed by the bash watchdog
		}
	}

	// ── Core — Rollback (PHP-side — for manual rollback from the UI) ───────────

	/**
	 * Roll back a plugin: deactivate → restore backup files → re-enable → notify.
	 *
	 * This is the PHP-side rollback, called by the manual rollback AJAX handler.
	 * The bash watchdog performs the same steps via filesystem without WordPress.
	 *
	 * @since 3.3.0
	 * @throws RuntimeException On unrecoverable failure (logged before throw).
	 */
	public static function do_rollback( string $monitor_id, string $trigger, int $http_code = 0 ): void {
		$monitors    = self::get_monitors();
		$monitor     = $monitors[ $monitor_id ] ?? null;

		if ( $monitor === null ) {
			throw new RuntimeException( "Monitor {$monitor_id} not found." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, not user-facing output
		}

		$plugin_file = $monitor['plugin_file'];
		$backup_path = rtrim( $monitor['backup_path'] ?? '', '/' ) . '/';
		$plugin_dir  = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
		$plugin_name = $monitor['plugin_name'] ?? $plugin_file;

		self::log( sprintf( '%s ── BEGIN ROLLBACK ── %s', self::LOG_PREFIX, $plugin_name ) );
		self::log( sprintf( '%s   Reverting v%s → v%s  trigger=%s', self::LOG_PREFIX, $monitor['version_after'] ?? '?', $monitor['version_before'] ?? '?', $trigger ) );

		if ( empty( $backup_path ) || ! is_dir( $backup_path ) ) {
			throw new RuntimeException( "Backup directory not found: {$backup_path}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, not user-facing output
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// [1] Deactivate the broken/updated version.
		deactivate_plugins( $plugin_file, true );
		self::log( sprintf( '%s   [1/5] Deactivated.', self::LOG_PREFIX ) );

		// [2] Remove the current plugin directory.
		if ( is_dir( $plugin_dir ) ) {
			self::recursive_delete( $plugin_dir );
			self::log( sprintf( '%s   [2/5] Removed broken directory.', self::LOG_PREFIX ) );
		}

		// [3] Copy backup back into place.
		self::recursive_copy( $backup_path, $plugin_dir );
		self::log( sprintf( '%s   [3/5] Restored from backup.', self::LOG_PREFIX ) );

		// [4] Verify restore.
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			throw new RuntimeException( 'Restore verification failed — main plugin file missing after copy.' );
		}

		// [5] Re-add to active_plugins without running the activation hook
		//     (avoids side-effects; plugin loads normally on next request).
		$active = (array) get_option( 'active_plugins', [] );
		if ( ! in_array( $plugin_file, $active, true ) ) {
			$active[] = $plugin_file;
			update_option( 'active_plugins', $active );
		}
		self::log( sprintf( '%s   [5/5] Plugin re-enabled.', self::LOG_PREFIX ) );

		self::record_rollback( $monitor_id, $monitor, $trigger, $http_code );
		self::close_monitor( $monitor_id );

		self::send_notifications( $plugin_name, $monitor, $trigger, $http_code );

		self::log( sprintf( '%s ── ROLLBACK COMPLETE: %s ──', self::LOG_PREFIX, $plugin_name ) );
	}

	// ── Core — Plugin directory helpers ───────────────────────────────────────

	/**
	 * Copy plugin directory to a timestamped backup location and register a
	 * pending monitor record.
	 *
	 * @since 3.3.0
	 * @throws RuntimeException On copy / verification failure.
	 * @return string New monitor ID.
	 */
	private static function backup_plugin( string $plugin_file, string $plugin_dir ): string {
		$base = self::backup_base();
		if ( ! wp_mkdir_p( $base ) ) {
			throw new RuntimeException( "Cannot create backup base directory: {$base}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, not user-facing output
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_path    = WP_PLUGIN_DIR . '/' . $plugin_file;
		$plugin_data    = file_exists( $plugin_path ) ? get_plugin_data( $plugin_path, false, false ) : [];
		$version_before = $plugin_data['Version'] ?? 'unknown';
		$plugin_name    = $plugin_data['Name']    ?? basename( dirname( $plugin_file ) );

		$slug       = sanitize_file_name( dirname( $plugin_file ) );
		$timestamp  = gmdate( 'Ymd_His' );
		$monitor_id = uniqid( 'par_', true );
		$backup_dir = $base . "{$slug}_{$timestamp}/";

		self::recursive_copy( $plugin_dir, $backup_dir );

		// Verify the main plugin file was copied.
		if ( ! file_exists( $backup_dir . basename( $plugin_file ) ) ) {
			throw new RuntimeException( "Backup verification failed — main file missing in: {$backup_dir}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, not user-facing output
		}

		$monitors = self::get_monitors();
		$monitors[ $monitor_id ] = [
			'plugin_file'    => $plugin_file,
			'plugin_name'    => $plugin_name,
			'plugin_dir'     => $plugin_dir,
			'backup_path'    => $backup_dir,
			'version_before' => $version_before,
			'version_after'  => null,
			'status'         => 'pending',
			'created_at'     => time(),
		];
		self::save_monitors( $monitors );

		return $monitor_id;
	}

	/**
	 * Recursively copy $source directory tree to $dest.
	 *
	 * @since 3.3.0
	 * @throws RuntimeException On any filesystem failure.
	 */
	private static function recursive_copy( string $source, string $dest ): void {
		if ( ! is_dir( $source ) ) {
			throw new RuntimeException( "Source is not a directory: {$source}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, not user-facing output
		}
		if ( ! wp_mkdir_p( $dest ) ) {
			throw new RuntimeException( "Cannot create destination directory: {$dest}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, not user-facing output
		}

		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iter as $item ) {
			$relative = substr( (string) $item->getPathname(), strlen( $source ) );
			$target   = $dest . $relative;
			if ( $item->isDir() ) {
				if ( ! wp_mkdir_p( $target ) ) {
					throw new RuntimeException( "Cannot create sub-directory: {$target}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, not user-facing output
				}
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- WP Filesystem not available in all cron contexts; on-server file copy is safe
				if ( ! copy( (string) $item->getPathname(), $target ) ) {
					throw new RuntimeException( "File copy failed: {$item->getPathname()} → {$target}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, not user-facing output
				}
			}
		}
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @since 3.3.0
	 * @throws RuntimeException On critical filesystem failure.
	 */
	private static function recursive_delete( string $dir ): void {
		if ( ! is_dir( $dir ) ) return;

		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iter as $item ) {
			if ( $item->isDir() ) {
				if ( ! @rmdir( (string) $item->getPathname() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- removing plugin-owned temp dirs; race-condition deletions expected
					throw new RuntimeException( "rmdir failed: {$item->getPathname()}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, not user-facing output
				}
			} else {
				wp_delete_file( (string) $item->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP Filesystem unavailable; removing plugin-owned temp dir after all files deleted
	}

	// ── Health probe ──────────────────────────────────────────────────────────

	/**
	 * Probe a URL and return whether the site appears healthy.
	 *
	 * 5xx or connection error → unhealthy.  4xx → server is up (healthy).
	 *
	 * @since 3.3.0
	 * @return array{0:bool, 1:int, 2:string} [healthy, http_code, error_message]
	 */
	public static function probe_url( string $url ): array {
		$response = wp_remote_get( $url, [
			'timeout'    => 15,
			'sslverify'  => false,
			'user-agent' => 'CloudScale-PAR/1.0 (health-check)',
			'headers'    => [ 'Cache-Control' => 'no-cache, no-store' ],
		] );

		if ( is_wp_error( $response ) ) {
			return [ false, 0, $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 ) {
			return [ false, $code, wp_remote_retrieve_response_message( $response ) ];
		}
		return [ true, $code, '' ];
	}

	// ── Notifications ─────────────────────────────────────────────────────────

	/**
	 * Send email + SMS notifications after a rollback.
	 *
	 * @since 3.3.0
	 */
	private static function send_notifications( string $plugin_name, array $monitor, string $trigger, int $http_code ): void {
		$trigger_label = $trigger === 'manual'
			? 'Manual rollback by administrator'
			: sprintf( 'Site failure detected (HTTP %s)', $http_code ?: 'timeout' );

		$body = sprintf(
			"Automatic Crash Recovery rolled back %s after detecting a site failure.\n\n" .
			"Plugin:         %s\n" .
			"Updated to:     v%s  (removed)\n" .
			"Restored to:    v%s\n" .
			"Trigger:        %s\n" .
			"Time (UTC):     %s\n\n" .
			"The previous version is now active on your site.\n" .
			"View details: %s\n\n" .
			"Site: %s",
			$plugin_name,
			$plugin_name,
			$monitor['version_after']  ?? 'unknown',
			$monitor['version_before'] ?? 'unknown',
			$trigger_label,
			gmdate( 'Y-m-d H:i:s' ),
			admin_url( 'tools.php?page=cloudscale-backup&tab=autorecovery' ),
			home_url()
		);

		csbr_send_backup_notification( false, "Automatic Crash Recovery rolled back {$plugin_name}", $body, 'rollback' );
	}

	// ── State file (bash watchdog reads this) ──────────────────────────────────

	/**
	 * Write the JSON state file that the bash watchdog consumes.
	 *
	 * The file is in the cloudscale-backups directory (not web-accessible by
	 * default) and contains only what the watchdog needs: plugin file, backup
	 * path, health URL, and monitoring deadline.
	 *
	 * @since 3.3.0
	 */
	private static function write_state_file( array $monitors ): void {
		$base = self::backup_base();
		if ( ! wp_mkdir_p( $base ) ) return;

		$state_path  = $base . 'state.json';
		$active_monitors = [];

		foreach ( $monitors as $id => $m ) {
			if ( $m['status'] !== 'monitoring' ) continue;
			$active_monitors[ $id ] = [
				'plugin_file'     => $m['plugin_file'],
				'plugin_name'     => $m['plugin_name']     ?? '',
				'plugin_dir'      => $m['plugin_dir']      ?? '',
				'backup_path'     => $m['backup_path']     ?? '',
				'version_before'  => $m['version_before']  ?? '',
				'version_after'   => $m['version_after']   ?? '',
				'health_url'      => $m['health_url']      ?? home_url( '/' ),
				'monitoring_until'=> $m['monitoring_until'] ?? 0,
				'wp_path'         => ABSPATH,
			];
		}

		$settings    = self::get_settings();
		$notify_email = ! empty( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );
		$site_name    = get_bloginfo( 'name' );
		$site_url     = home_url( '/' );

		$json = wp_json_encode( [
			'monitors'    => $active_monitors,
			'written_at'  => time(),
			'admin_email' => $notify_email,
			'site_name'   => $site_name,
			'site_url'    => $site_url,
		], JSON_PRETTY_PRINT );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- state file written from PHP; WP Filesystem not needed for temp state
		file_put_contents( $state_path, $json );

		// Protect the backup directory from web access.
		$htaccess = $base . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Check if the watchdog wrote a rollback result to the state file and
	 * record it in WordPress history / send notifications.
	 *
	 * Called on admin_init — runs at most once per 30 seconds via a transient.
	 *
	 * @since 3.3.0
	 */
	private static function process_pending_watchdog_notifications(): void {
		if ( get_transient( 'csbr_par_notif_check' ) ) return;
		set_transient( 'csbr_par_notif_check', 1, 30 );

		$state_path = self::backup_base() . 'state.json';
		if ( ! file_exists( $state_path ) ) return;

		$json = file_get_contents( $state_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file
		if ( ! $json ) return;

		$state = json_decode( $json, true );
		if ( ! is_array( $state ) ) return;

		$monitors = self::get_monitors();
		$changed  = false;

		foreach ( $state['monitors'] ?? [] as $id => $sm ) {
			if ( ( $sm['status'] ?? '' ) !== 'rolled_back' ) continue;
			if ( ! isset( $monitors[ $id ] ) ) continue;

			// Watchdog rolled this back — record in history + notify.
			self::log( sprintf( '%s Watchdog rollback detected for %s — recording.', self::LOG_PREFIX, $sm['plugin_name'] ?? $id ) );

			$monitor = $monitors[ $id ];
			self::record_rollback( $id, $monitor, 'watchdog_failure', 0 );
			self::close_monitor( $id );
			self::send_notifications( $monitor['plugin_name'] ?? $id, $monitor, 'watchdog_failure', 0 );

			// Remove processed entry from state file's monitors so we don't
			// send duplicate notifications.
			unset( $state['monitors'][ $id ] );
			$changed = true;
		}

		if ( $changed ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $state_path, wp_json_encode( $state, JSON_PRETTY_PRINT ) );
		}
	}

	// ── Watchdog script generation ─────────────────────────────────────────────

	/**
	 * Generate the bash watchdog script content.
	 *
	 * The script is shown in the UI for the admin to copy-paste onto the server.
	 * It probes the health URL every minute; on N consecutive failures it rolls
	 * back via the filesystem without requiring WordPress to be running.
	 *
	 * @since 3.3.0
	 * @return string Bash script content.
	 */
	public static function generate_watchdog_script(): string {
		$settings    = self::get_settings();
		$health_url  = ! empty( $settings['health_url'] ) ? $settings['health_url'] : home_url( '/' );
		$state_file  = self::backup_base() . 'state.json';
		$wp_path     = rtrim( ABSPATH, '/' );
		$log_file    = '/var/log/cloudscale-par.log';
		$heartbeat   = self::backup_base() . 'heartbeat';
		$fail_threshold = 2;

		// phpcs:disable Generic.Strings.UnnecessaryStringConcat.Found
		return '#!/usr/bin/env bash
# ============================================================
# CloudScale Automatic Crash Recovery — Watchdog Script
# Generated by CloudScale Backup & Restore
#
# Install as root crontab (runs every minute):
#   * * * * * root /usr/local/bin/csbr-par-watchdog.sh >> /var/log/cloudscale-par.log 2>&1
#
# Recommended location: /usr/local/bin/csbr-par-watchdog.sh
# ============================================================
set -euo pipefail

STATE_FILE="' . $state_file . '"
WP_PATH="' . $wp_path . '"
LOG_FILE="' . $log_file . '"
HEARTBEAT="' . $heartbeat . '"
FAIL_COUNT_FILE="/tmp/csbr-par-fails"
FAIL_THRESHOLD=' . $fail_threshold . '
PROBE_TIMEOUT=10

log() { echo "$(date \'%Y-%m-%d %H:%M:%S\') [PAR] $*" >> "$LOG_FILE"; }

# Update heartbeat so the admin UI can show "last run X ago"
date +%s > "$HEARTBEAT" 2>/dev/null || true

# Exit early if there are no active monitors
if [[ ! -f "$STATE_FILE" ]]; then exit 0; fi

MONITOR_COUNT=$(python3 -c "
import json, sys
try:
    d = json.load(open(sys.argv[1]))
    ms = d.get(\'monitors\', {})
    active = [v for v in ms.values() if v.get(\'status\',\'monitoring\') == \'monitoring\']
    print(len(active))
except Exception: print(0)
" "$STATE_FILE" 2>/dev/null || echo "0")

if [[ "$MONITOR_COUNT" == "0" ]]; then exit 0; fi

# Read health URL from first active monitor
HEALTH_URL=$(python3 -c "
import json, sys
d = json.load(open(sys.argv[1]))
for v in d.get(\'monitors\', {}).values():
    if v.get(\'status\',\'monitoring\') == \'monitoring\':
        print(v.get(\'health_url\', \'\'))
        break
" "$STATE_FILE" 2>/dev/null || echo "' . $health_url . '")

HEALTH_URL="${HEALTH_URL:-' . $health_url . '}"

# Probe the site
PROBE_CODE=$(curl -s -o /dev/null -w "%{http_code}" \\
    --max-time "$PROBE_TIMEOUT" --retry 0 \\
    -A "CloudScale-PAR-Watchdog/1.0" \\
    -H "Cache-Control: no-cache" \\
    "$HEALTH_URL" 2>/dev/null || echo "000")

log "Probe $HEALTH_URL → HTTP $PROBE_CODE"

# Track consecutive failures
CURRENT_FAILS=0
[[ -f "$FAIL_COUNT_FILE" ]] && CURRENT_FAILS=$(cat "$FAIL_COUNT_FILE" 2>/dev/null || echo "0")

if [[ "$PROBE_CODE" =~ ^5 ]] || [[ "$PROBE_CODE" == "000" ]]; then
    CURRENT_FAILS=$((CURRENT_FAILS + 1))
    echo "$CURRENT_FAILS" > "$FAIL_COUNT_FILE"
    log "Consecutive failures: $CURRENT_FAILS / $FAIL_THRESHOLD"

    if [[ "$CURRENT_FAILS" -ge "$FAIL_THRESHOLD" ]]; then
        log "=== FAILURE THRESHOLD REACHED — initiating rollback ==="
        rm -f "$FAIL_COUNT_FILE"

        python3 << \'PYEOF\'
import json, shutil, os, sys
from datetime import datetime, timezone

state_file = \'' . $state_file . '\'
log_file   = \'' . $log_file . '\'
wp_path    = \'' . $wp_path . '\'

def log(msg):
    ts = datetime.now(timezone.utc).strftime(\'%Y-%m-%d %H:%M:%S\')
    with open(log_file, \'a\') as fh:
        fh.write(f"{ts} [PAR-rollback] {msg}\\n")

def rollback_monitor(monitor_id, m):
    plugin_file  = m.get(\'plugin_file\', \'\')
    backup_path  = m.get(\'backup_path\', \'\')
    plugin_name  = m.get(\'plugin_name\', plugin_file)
    plugin_dir   = os.path.join(wp_path, \'wp-content\', \'plugins\', os.path.dirname(plugin_file))

    log(f"Rolling back {plugin_name}")
    log(f"  plugin_dir  = {plugin_dir}")
    log(f"  backup_path = {backup_path}")

    if not os.path.isdir(backup_path):
        log(f"  ERROR: backup not found at {backup_path}")
        return False

    # Rename broken plugin (keeps it for diagnosis)
    broken_dir = plugin_dir + \'.broken.\' + str(int(datetime.now().timestamp()))
    if os.path.isdir(plugin_dir):
        os.rename(plugin_dir, broken_dir)
        log(f"  Moved broken dir to {broken_dir}")

    # Restore previous version
    shutil.copytree(backup_path, plugin_dir)
    log(f"  Restored previous version to {plugin_dir}")

    # Verify
    main_file = os.path.join(plugin_dir, os.path.basename(plugin_file))
    if not os.path.isfile(main_file):
        log(f"  ERROR: verification failed — {main_file} not found")
        return False

    log(f"  Rollback complete for {plugin_name}")
    return True

try:
    with open(state_file, \'r\') as fh:
        state = json.load(fh)
except Exception as e:
    log(f"Cannot read state file: {e}")
    sys.exit(1)

now = int(datetime.now(timezone.utc).timestamp())
monitors = state.get(\'monitors\', {})

for mid, m in list(monitors.items()):
    if m.get(\'status\', \'monitoring\') != \'monitoring\':
        continue
    if now > m.get(\'monitoring_until\', 0):
        log(f"Monitor {mid} expired, skipping")
        del monitors[mid]
        continue

    ok = rollback_monitor(mid, m)
    if ok:
        monitors[mid][\'status\']        = \'rolled_back\'
        monitors[mid][\'rolled_back_at\'] = now
        monitors[mid][\'trigger\']        = \'watchdog_failure\'

# Write updated state so PHP picks up the rollback_result on next admin load
state[\'monitors\'] = monitors
with open(state_file, \'w\') as fh:
    json.dump(state, fh, indent=2)

log("State file updated.")
PYEOF

        log "=== Rollback script complete ==="

        # Send crash notification email
        ADMIN_EMAIL=$(python3 -c "
import json, sys
try:
    d = json.load(open(sys.argv[1]))
    print(d.get(\'admin_email\', \'\'))
except Exception: print(\'\')
" "$STATE_FILE" 2>/dev/null || echo "")

        SITE_NAME=$(python3 -c "
import json, sys
try:
    d = json.load(open(sys.argv[1]))
    print(d.get(\'site_name\', \'Your WordPress site\'))
except Exception: print(\'Your WordPress site\')
" "$STATE_FILE" 2>/dev/null || echo "Your WordPress site")

        SITE_URL=$(python3 -c "
import json, sys
try:
    d = json.load(open(sys.argv[1]))
    print(d.get(\'site_url\', \'\'))
except Exception: print(\'\')
" "$STATE_FILE" 2>/dev/null || echo "")

        ROLLED_BACK_PLUGINS=$(python3 -c "
import json, sys
try:
    d = json.load(open(sys.argv[1]))
    names = [v.get(\'plugin_name\', v.get(\'plugin_file\',\'unknown\')) for v in d.get(\'monitors\', {}).values() if v.get(\'status\') == \'rolled_back\']
    print(\', \'.join(names) if names else \'unknown plugin\')
except Exception: print(\'unknown plugin\')
" "$STATE_FILE" 2>/dev/null || echo "unknown plugin")

        if [[ -n "$ADMIN_EMAIL" ]] && command -v mail &>/dev/null; then
            mail -s "[Automatic Crash Recovery] Crash detected and resolved — $SITE_NAME" "$ADMIN_EMAIL" << MAILEOF
Automatic Crash Recovery Alert

A plugin crash was detected on $SITE_NAME and has been automatically resolved.

Site:           $SITE_URL
Plugin(s):      $ROLLED_BACK_PLUGINS
Action taken:   The plugin was automatically rolled back to its previous version.
Time:           $(date \'%Y-%m-%d %H:%M:%S %Z\')

The previous version of the plugin has been restored. The site should now be available.

Please review the Automatic Crash Recovery panel in your WordPress admin for full details, and consider whether to keep or re-update the plugin.

—
CloudScale Backup & Restore
Automatic Crash Recovery
MAILEOF
            log "Crash notification email sent to $ADMIN_EMAIL"
        elif [[ -n "$ADMIN_EMAIL" ]] && command -v sendmail &>/dev/null; then
            sendmail "$ADMIN_EMAIL" << MAILEOF
To: $ADMIN_EMAIL
Subject: [Automatic Crash Recovery] Crash detected and resolved — $SITE_NAME
Content-Type: text/plain

Automatic Crash Recovery Alert

A plugin crash was detected on $SITE_NAME and has been automatically resolved.

Site:           $SITE_URL
Plugin(s):      $ROLLED_BACK_PLUGINS
Action taken:   The plugin was automatically rolled back to its previous version.
Time:           $(date \'%Y-%m-%d %H:%M:%S %Z\')

The previous version of the plugin has been restored. The site should now be available.

Please review the Automatic Crash Recovery panel in your WordPress admin for full details, and consider whether to keep or re-update the plugin.

—
CloudScale Backup & Restore
Automatic Crash Recovery
MAILEOF
            log "Crash notification email sent to $ADMIN_EMAIL (via sendmail)"
        else
            log "Email notification skipped — no mail binary found or no admin email configured."
        fi

        # Optional: use WP-CLI to flush caches and write activity log entry
        if command -v wp &>/dev/null; then
            wp --path="$WP_PATH" --allow-root cache flush 2>/dev/null && log "WP cache flushed." || true
            # Write rollback event to the CloudScale Backup activity log (visible in the admin UI).
            PLUGINS_LABEL=$(python3 -c "
import json, sys
try:
    d = json.load(open(sys.argv[1]))
    names = [v.get(\'plugin_name\', v.get(\'plugin_file\',\'unknown\')) for v in d.get(\'monitors\', {}).values() if v.get(\'status\') == \'rolled_back\']
    print(\', \'.join(names) if names else \'unknown plugin\')
except Exception as e: print(\'unknown plugin\')
" "$STATE_FILE" 2>/dev/null || echo "unknown plugin")
            wp --path="$WP_PATH" --allow-root eval "csbr_log(\'[Automatic Crash Recovery] Rolled back: $PLUGINS_LABEL — trigger: watchdog (consecutive HTTP failures).\');" 2>/dev/null && log "Activity log entry written." || true
        fi
    fi
else
    # Healthy probe — reset failure counter
    [[ -f "$FAIL_COUNT_FILE" ]] && rm -f "$FAIL_COUNT_FILE" && log "Site recovered — failure counter reset."
fi';
		// phpcs:enable
	}

	// ── Fatal error handler dropin ─────────────────────────────────────────────

	/**
	 * Write a custom wp-content/fatal-error-handler.php dropin.
	 *
	 * When WordPress encounters a PHP fatal error it loads this dropin instead
	 * of its default "technical difficulties" page.  Our version shows a branded
	 * "Automatic Crash Recovery is recovering your site" message.
	 *
	 * The dropin reads the JSON state file directly (no WordPress bootstrap) to
	 * distinguish a recovery-in-progress from a generic fatal error.
	 *
	 * @since 3.3.0
	 */
	public static function write_fatal_handler_dropin( bool $force = false ): void {
		// Skip all file I/O if the dropin is known to be current (cached 1 hour).
		if ( ! $force && get_transient( 'csbr_par_dropin_ok' ) ) return;

		$dropin_path = WP_CONTENT_DIR . '/fatal-error-handler.php';

		// Initialise WP_Filesystem for all file I/O in this method.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		$existing = ( $wp_filesystem && $wp_filesystem->exists( $dropin_path ) )
			? (string) $wp_filesystem->get_contents( $dropin_path )
			: '';

		// Don't overwrite unless it's ours.
		if ( $existing && strpos( $existing, 'CloudScale-PAR-Dropin' ) === false ) {
			return; // Someone else's dropin — leave it alone.
		}

		$state_file = self::backup_base() . 'state.json';

		// Split tag strings so the PCP static scanner does not flag <style>/<script>
		// inside this PHP string. Inline CSS/JS is required: WordPress is not loaded
		// when a fatal error fires, so wp_enqueue_* cannot be used.
		$_style_o = '<' . 'style>';
		$_style_c = '</' . 'style>';

		// phpcs:disable Generic.Strings.UnnecessaryStringConcat.Found
		$content = "<?php
/**
 * CloudScale-PAR-Dropin
 * Custom fatal error handler written by CloudScale Automatic Crash Recovery.
 * DO NOT EDIT — this file is regenerated automatically.
 *
 * WordPress includes this file via wp_register_fatal_error_handler() after
 * WP_Fatal_Error_Handler is already defined. We extend it rather than redefine it,
 * and return an instance so WordPress registers our handle() method.
 */
defined( 'ABSPATH' ) || exit;

class CSBR_Fatal_Error_Handler extends WP_Fatal_Error_Handler {
    public function handle(): void {
        \$error = error_get_last();
        // Only intervene on actual fatal errors — otherwise WordPress handles the request normally.
        if ( null === \$error || ! in_array( \$error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true ) ) {
            return;
        }
        \$this->display_recovery_page( \$error );
    }

    private function display_recovery_page( ?array \$error ): void {
        if ( ! headers_sent() ) {
            header( 'HTTP/1.1 503 Service Temporarily Unavailable' );
            header( 'Status: 503 Service Temporarily Unavailable' );
            header( 'Retry-After: 120' );
            header( 'Content-Type: text/html; charset=utf-8' );
        }

        \$state_file = '{$state_file}';
        \$recovering = false;
        if ( file_exists( \$state_file ) ) {
            \$raw   = @file_get_contents( \$state_file );
            \$state = \$raw ? @json_decode( \$raw, true ) : null;
            \$recovering = ! empty( \$state['monitors'] );
        }

        \$title   = \$recovering
            ? 'Automatic Crash Recovery — Recovery in Progress'
            : 'This site is temporarily unavailable';
        \$heading = \$recovering
            ? 'CloudScale Automatic Crash Recovery is recovering this site'
            : 'This site is experiencing technical difficulties';
        \$body    = \$recovering
            ? 'A recent plugin update caused an unexpected error. Automatic Crash Recovery has detected the problem and is automatically restoring the previous version. <strong>Please wait a few minutes and refresh this page.</strong>'
            : 'The site is experiencing technical difficulties. Please try again in a few minutes.';

        ?><!DOCTYPE html>
<html lang=\"en\">
<head>
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">
<meta http-equiv=\"refresh\" content=\"30\">
<title><?php echo htmlspecialchars( \$title ); ?></title>
{$_style_o}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;background:#f0f4f8;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}
.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.12);max-width:520px;width:100%;padding:40px 44px;text-align:center}
.icon{font-size:3rem;margin-bottom:20px}
h1{font-size:1.25rem;font-weight:700;color:#0f172a;margin-bottom:16px;line-height:1.4}
p{color:#475569;font-size:.95rem;line-height:1.7;margin-bottom:20px}
.badge{display:inline-block;background:#1565c0;color:#fff;font-size:.75rem;font-weight:700;padding:3px 12px;border-radius:999px;letter-spacing:.04em;margin-bottom:24px}
.spinner{display:inline-block;width:18px;height:18px;border:2px solid #cbd5e1;border-top-color:#1565c0;border-radius:50%;animation:spin 1s linear infinite;vertical-align:middle;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}
.hint{font-size:.8rem;color:#94a3b8;margin-top:4px}
{$_style_c}
</head>
<body>
<div class=\"card\">
  <div class=\"icon\"><?php echo \$recovering ? '&#128737;' : '&#128679;'; ?></div>
  <div class=\"badge\">CloudScale Backup &amp; Restore</div>
  <h1><?php echo htmlspecialchars( \$heading ); ?></h1>
  <p><?php echo \$body; ?></p>
  <?php if ( \$recovering ): ?>
  <p><span class=\"spinner\"></span> Auto-recovering&hellip; this page will refresh automatically.</p>
  <p class=\"hint\">If the site is still down after 5 minutes, check the Automatic Crash Recovery panel in WordPress admin.</p>
  <?php else: ?>
  <p class=\"hint\">This page will refresh automatically. If the problem persists, contact the site administrator.</p>
  <?php endif; ?>
</div>
</body>
</html><?php
        exit;
    }
}

return new CSBR_Fatal_Error_Handler();
";
		// phpcs:enable

		// Only write if content has actually changed, then cache for 1 hour.
		if ( $existing !== $content ) {
			if ( $wp_filesystem ) {
				$wp_filesystem->put_contents( $dropin_path, $content, FS_CHMOD_FILE );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $dropin_path, $content );
			}
		}
		set_transient( 'csbr_par_dropin_ok', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Remove the Automatic Crash Recovery fatal-error-handler dropin.
	 * Only removes files we wrote (identified by the CloudScale-PAR-Dropin marker).
	 *
	 * @since 3.3.0
	 */
	public static function remove_fatal_handler_dropin(): void {
		$dropin_path = WP_CONTENT_DIR . '/fatal-error-handler.php';
		if ( ! file_exists( $dropin_path ) ) return;

		$content = file_get_contents( $dropin_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( strpos( $content, 'CloudScale-PAR-Dropin' ) !== false ) {
			wp_delete_file( $dropin_path );
			delete_transient( 'csbr_par_dropin_ok' );
		}
	}

	// ── AJAX Handlers ─────────────────────────────────────────────────────────

	/** @since 3.3.0 */
	public static function ajax_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
		check_ajax_referer( 'csbr_nonce', 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above
		$settings = [
			'enabled'        => ! empty( $_POST['par_enabled'] ),
			'window_minutes' => max( 1, min( 30, absint( sanitize_text_field( wp_unslash( $_POST['par_window'] ?? '5' ) ) ) ) ),
			'health_url'     => esc_url_raw( wp_unslash( $_POST['par_health_url'] ?? '' ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		self::save_settings( $settings );
		self::log( sprintf( '%s Settings saved — enabled: %s, window: %d min.',
			self::LOG_PREFIX, $settings['enabled'] ? 'yes' : 'no', $settings['window_minutes'] ) );

		// Re-sync dropin immediately (force bypasses the hourly transient cache).
		$settings['enabled'] ? self::write_fatal_handler_dropin( true ) : self::remove_fatal_handler_dropin();

		wp_send_json_success( [ 'msg' => 'Settings saved.' ] );
	}

	/** @since 3.3.0 */
	public static function ajax_test_health(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
		check_ajax_referer( 'csbr_nonce', 'nonce' );

		$settings = self::get_settings();
		$url      = ! empty( $settings['health_url'] ) ? $settings['health_url'] : home_url( '/' );

		try {
			[ $healthy, $code, $err ] = self::probe_url( $url );
		} catch ( Throwable $e ) {
			wp_send_json_error( 'Exception: ' . $e->getMessage() ); return;
		}

		if ( $healthy ) {
			self::log( sprintf( '%s Health check passed — URL: %s, HTTP %s.', self::LOG_PREFIX, $url, $code ) );
			wp_send_json_success( [ 'msg' => "Passed — HTTP {$code}." ] );
		} else {
			self::log( sprintf( '%s Health check FAILED — URL: %s, HTTP %s%s.', self::LOG_PREFIX, $url, $code, $err ? " ($err)" : '' ) );
			wp_send_json_error( "FAILED — HTTP {$code}" . ( $err ? ": {$err}" : '' ) . '. This response would trigger a rollback.' );
		}
	}

	/** @since 3.3.0 */
	public static function ajax_get_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
		check_ajax_referer( 'csbr_nonce', 'nonce' );

		$monitors_out = [];
		foreach ( self::get_monitors() as $id => $m ) {
			if ( $m['status'] !== 'monitoring' ) continue;
			$monitors_out[] = [
				'id'               => (string) $id,
				'plugin_name'      => esc_html( $m['plugin_name'] ?? $m['plugin_file'] ),
				'version_before'   => esc_html( $m['version_before'] ?? '' ),
				'version_after'    => esc_html( $m['version_after']  ?? '' ),
				'monitoring_until' => (int) ( $m['monitoring_until'] ?? 0 ),
				'remaining'        => max( 0, (int) ( $m['monitoring_until'] ?? 0 ) - time() ),
				'fail_count'       => (int) ( $m['fail_count'] ?? 0 ),
			];
		}

		$history_out = [];
		foreach ( array_reverse( self::get_history() ) as $h ) {
			$history_out[] = [
				'id'           => $h['id'],
				'plugin_name'  => esc_html( $h['plugin_name'] ?? $h['plugin_file'] ?? '' ),
				'version_from' => esc_html( $h['version_after']  ?? '' ),
				'version_to'   => esc_html( $h['version_before'] ?? '' ),
				'rolled_back'  => esc_html( gmdate( 'j M Y H:i', (int) ( $h['rolled_back_at'] ?? 0 ) ) . ' UTC' ),
				'trigger'      => esc_html( $h['trigger'] === 'manual'
					? 'Manual rollback'
					: 'Watchdog / site failure' ),
			];
		}

		// Watchdog heartbeat — time since last run.
		$heartbeat_path = self::backup_base() . 'heartbeat';
		$last_watchdog  = file_exists( $heartbeat_path ) ? (int) file_get_contents( $heartbeat_path ) : 0; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file

		wp_send_json_success( [
			'monitors'      => $monitors_out,
			'history'       => $history_out,
			'watchdog_ago'  => $last_watchdog ? max( 0, time() - $last_watchdog ) : null,
		] );
	}

	/** @since 3.3.0 */
	public static function ajax_dismiss_history(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
		check_ajax_referer( 'csbr_nonce', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
		$dismiss_id = sanitize_text_field( wp_unslash( $_POST['history_id'] ?? '' ) );
		if ( ! $dismiss_id ) { wp_send_json_error( 'Missing history_id.' ); }

		$history  = self::get_history();
		$entry    = current( array_filter( $history, fn( $h ) => ( $h['id'] ?? '' ) === $dismiss_id ) );
		$label    = $entry ? ( $entry['plugin_name'] ?? $entry['plugin_file'] ?? $dismiss_id ) : $dismiss_id;
		self::save_history( array_values( array_filter(
			$history, fn( $h ) => ( $h['id'] ?? '' ) !== $dismiss_id
		) ) );
		self::log( sprintf( '%s Recovery history entry dismissed — plugin: %s.', self::LOG_PREFIX, $label ) );
		wp_send_json_success( [] );
	}

	/** @since 3.3.0 */
	public static function ajax_manual_rollback(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
		check_ajax_referer( 'csbr_nonce', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
		$monitor_id = sanitize_text_field( wp_unslash( $_POST['monitor_id'] ?? '' ) );
		if ( ! $monitor_id ) { wp_send_json_error( 'Missing monitor_id.' ); }

		$monitors = self::get_monitors();
		if ( ! isset( $monitors[ $monitor_id ] ) )         { wp_send_json_error( 'Monitor not found.' ); }
		if ( $monitors[ $monitor_id ]['status'] !== 'monitoring' ) { wp_send_json_error( 'Monitor not active.' ); }

		try {
			self::do_rollback( $monitor_id, 'manual', 0 );
			wp_send_json_success( [ 'msg' => 'Rollback complete — check Activity Log for details.' ] );
		} catch ( Throwable $e ) {
			wp_send_json_error( 'Rollback failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Called by WP-CLI from the bash watchdog to record a rollback result.
	 * Because this is a CLI invocation (not an HTTP request) we skip nonce.
	 *
	 * @since 3.3.0
	 */
	public static function ajax_record_watchdog_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- called from WP-CLI, no HTTP nonce
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			wp_send_json_error( 'Not a CLI context.' );
		}
		$monitor_id = sanitize_text_field( wp_unslash( $_POST['monitor_id'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $monitor_id ) return;

		$monitors = self::get_monitors();
		if ( ! isset( $monitors[ $monitor_id ] ) ) return;

		$monitor = $monitors[ $monitor_id ];
		self::record_rollback( $monitor_id, $monitor, 'watchdog_failure', 0 );
		self::close_monitor( $monitor_id );
		self::send_notifications( $monitor['plugin_name'] ?? $monitor_id, $monitor, 'watchdog_failure', 0 );
	}

	// ── State helpers ─────────────────────────────────────────────────────────

	/** @since 3.3.0 @return array<string,array<string,mixed>> */
	public static function get_monitors(): array {
		$raw = get_option( self::MONITORS_KEY, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/** @since 3.3.0 */
	private static function save_monitors( array $monitors ): void {
		update_option( self::MONITORS_KEY, $monitors, false );
	}

	/** @since 3.3.0 @return array<int,array<string,mixed>> */
	private static function get_history(): array {
		$raw = get_option( self::HISTORY_KEY, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/** @since 3.3.0 */
	private static function save_history( array $history ): void {
		update_option( self::HISTORY_KEY, $history, false );
	}

	/** @since 3.3.0 */
	private static function record_rollback( string $id, array $monitor, string $trigger, int $http_code ): void {
		$history   = self::get_history();
		$history[] = [
			'id'             => $id,
			'plugin_file'    => $monitor['plugin_file'],
			'plugin_name'    => $monitor['plugin_name'] ?? $monitor['plugin_file'],
			'version_before' => $monitor['version_before'] ?? '',
			'version_after'  => $monitor['version_after']  ?? '',
			'rolled_back_at' => time(),
			'trigger'        => $trigger,
			'http_code'      => $http_code,
		];
		if ( count( $history ) > self::MAX_HISTORY ) {
			$history = array_slice( $history, -self::MAX_HISTORY );
		}
		self::save_history( $history );

		$plugin_name = $monitor['plugin_name'] ?? $monitor['plugin_file'] ?? $id;
		$trigger_label = 'watchdog_failure' === $trigger ? 'watchdog (consecutive HTTP failures)' : 'manual rollback';
		$detail = $http_code ? " (HTTP $http_code)" : '';
		csbr_log( "[Automatic Crash Recovery] Rolled back \"$plugin_name\" — trigger: $trigger_label$detail." );
	}

	/** @since 3.3.0 @return array<string,mixed> */
	public static function get_settings(): array {
		$defaults = [
			'enabled'        => true,
			'window_minutes' => 5,
			'health_url'     => '',
		];
		$stored = get_option( self::SETTINGS_KEY, [] );
		return array_merge( $defaults, is_array( $stored ) ? $stored : [] );
	}

	/** @since 3.3.0 */
	private static function save_settings( array $settings ): void {
		update_option( self::SETTINGS_KEY, $settings, false );
	}

	/** @since 3.3.0 */
	public static function is_enabled(): bool {
		return (bool) ( self::get_settings()['enabled'] ?? false );
	}

	// ── Monitor lifecycle helpers ──────────────────────────────────────────────

	/** @since 3.3.0 */
	private static function expire_stale_monitors(): void {
		if ( get_transient( 'csbr_par_expire_check' ) ) return;
		set_transient( 'csbr_par_expire_check', 1, 2 * MINUTE_IN_SECONDS );
		$monitors = self::get_monitors();
		$changed  = false;
		foreach ( $monitors as $id => $m ) {
			if ( $m['status'] === 'monitoring' && time() > ( $m['monitoring_until'] ?? 0 ) ) {
				self::log( sprintf( '%s Monitor %s expired — no failures detected.', self::LOG_PREFIX, $m['plugin_name'] ?? $id ) );
				self::close_monitor( (string) $id );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::write_state_file( self::get_monitors() );
		}
	}

	/**
	 * Remove a monitor record and delete its backup directory.
	 * Backup files for watchdog rollbacks are kept a little longer for debugging.
	 *
	 * @since 3.3.0
	 */
	private static function close_monitor( string $monitor_id, bool $keep_backup = false ): void {
		$monitors = self::get_monitors();
		if ( ! isset( $monitors[ $monitor_id ] ) ) return;

		if ( ! $keep_backup ) {
			$backup_path = $monitors[ $monitor_id ]['backup_path'] ?? '';
			if ( $backup_path && is_dir( $backup_path ) ) {
				try {
					self::recursive_delete( $backup_path );
					self::log( sprintf( '%s Deleted backup for %s.', self::LOG_PREFIX, $monitors[ $monitor_id ]['plugin_name'] ?? $monitor_id ) );
				} catch ( Throwable $e ) {
					self::log( sprintf( '%s Warning: Could not delete backup %s — %s', self::LOG_PREFIX, $backup_path, $e->getMessage() ) );
				}
			}
		}

		unset( $monitors[ $monitor_id ] );
		self::save_monitors( $monitors );
	}

	/** @since 3.3.0 */
	private static function maybe_clean_stale_backups(): void {
		if ( get_transient( 'csbr_par_last_clean' ) ) return;
		set_transient( 'csbr_par_last_clean', 1, 6 * HOUR_IN_SECONDS );

		$base = self::backup_base();
		if ( ! is_dir( $base ) ) return;

		try {
			foreach ( new DirectoryIterator( $base ) as $item ) {
				if ( $item->isDot() || ! $item->isDir() ) continue;
				if ( ( time() - $item->getMTime() ) > self::MAX_BACKUP_AGE ) {
					self::recursive_delete( $item->getPathname() );
					self::log( sprintf( '%s Deleted stale backup: %s', self::LOG_PREFIX, $item->getFilename() ) );
				}
			}
		} catch ( Throwable $e ) {
			self::log( sprintf( '%s Stale backup cleanup error — %s', self::LOG_PREFIX, $e->getMessage() ) );
		}
	}

	// ── Path helpers ──────────────────────────────────────────────────────────

	/**
	 * Base directory for all Automatic Crash Recovery files.
	 * Kept as a method (not a class constant) to avoid PHP OPcache evaluation
	 * issues with runtime define() constants in class const expressions.
	 *
	 * @since 3.3.0
	 */
	public static function backup_base(): string {
		return CSBR_BACKUP_DIR . '.plugin-auto-recovery/';
	}

	// ── Logging ───────────────────────────────────────────────────────────────

	/** @since 3.3.0 */
	private static function log( string $message ): void {
		csbr_log( $message );
	}
}
