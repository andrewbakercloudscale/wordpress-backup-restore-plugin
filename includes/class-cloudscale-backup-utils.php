<?php
/**
 * Shared utility helpers for CloudScale Free Backup and Restore.
 *
 * Single source of truth for pure helper logic used across the plugin.
 * The main plugin file delegates to this class; new helpers belong here first.
 *
 * @package CloudScale_Backup
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Utility helpers for CloudScale Free Backup and Restore.
 *
 * All methods are static — no instantiation required.
 *
 * @since 1.0.0
 */
class CloudScale_Backup_Utils {

	/**
	 * Write a message to the PHP error log when WP_DEBUG is enabled.
	 *
	 * Silenced in production so debug output never reaches live server logs.
	 *
	 * @since 1.0.0
	 * @param string $message Message to write to the PHP error log.
	 * @return void
	 */
	public static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
		}
	}

	/**
	 * Format a byte count as a human-readable string (B / KB / MB / GB).
	 *
	 * @since 1.0.0
	 * @param int $bytes Size in bytes.
	 * @return string Formatted string, e.g. '12.5 MB'.
	 */
	public static function format_size( int $bytes ): string {
		if ( $bytes >= 1073741824 ) {
			return round( $bytes / 1073741824, 2 ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 2 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 2 ) . ' KB';
		}
		return $bytes . ' B';
	}

	/**
	 * Return a human-readable relative age string for a Unix timestamp.
	 *
	 * @since 1.0.0
	 * @param int $timestamp Unix timestamp to describe.
	 * @return string E.g. '5 min ago', '3 hr ago', '12 days ago', or a formatted date.
	 */
	public static function human_age( int $timestamp ): string {
		$diff = time() - $timestamp;
		if ( $diff < 3600 ) {
			return round( $diff / 60 ) . ' min ago';
		}
		if ( $diff < 86400 ) {
			return round( $diff / 3600 ) . ' hr ago';
		}
		if ( $diff < 604800 ) {
			return round( $diff / 86400 ) . ' days ago';
		}
		return wp_date( 'j M Y', $timestamp );
	}

	/**
	 * Return the total size in bytes of all files under a directory, recursively.
	 *
	 * @since 1.0.0
	 * @param string $dir Absolute path to the directory.
	 * @return int Total size in bytes, or 0 if the directory does not exist.
	 */
	public static function dir_size( string $dir ): int {
		$size = 0;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		foreach (
			new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
			) as $f
		) {
			$size += $f->getSize();
		}
		return $size;
	}

	/**
	 * Parse a DB_HOST value into a [host, port] pair.
	 *
	 * @since 1.0.0
	 * @param string $host Raw DB_HOST value, e.g. 'localhost' or '127.0.0.1:3307'.
	 * @return array{string, string} Two-element array: [hostname, port].
	 */
	public static function parse_db_host( string $host ): array {
		$port = '3306';
		if ( str_contains( $host, ':' ) ) {
			[ $host, $port ] = explode( ':', $host, 2 );
		}
		return [ $host, $port ];
	}
}
