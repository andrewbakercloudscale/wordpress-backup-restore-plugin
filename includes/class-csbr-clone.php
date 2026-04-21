<?php
/**
 * CloudScale Backup & Restore — Clone / Staging Restore Module
 *
 * Completely separate from the main plugin. Excluded from release builds until ready.
 * Hooks into the main plugin via actions defined in cloudscale-backup.php:
 *
 *   csbr_after_scheduled_backup — fires after local backup + cloud sync complete
 *   csbr_admin_tab_buttons      — fires inside the tab bar (adds Clone tab button)
 *   csbr_admin_tab_panels       — fires after built-in tab panels (adds Clone tab content)
 *
 * @package CloudScaleBackupRestore
 * @since   3.2.314
 */

defined( 'ABSPATH' ) || exit;

// ============================================================
// Constants
// ============================================================

/** Directory where default clone scripts are written on first use. */
define( 'CSBR_CLONE_SCRIPTS_DIR', WP_CONTENT_DIR . '/cloudscale-backups/clone-scripts/' );

/** Main entry-point script called after every clone restore. */
define( 'CSBR_CLONE_MAIN_SCRIPT', CSBR_CLONE_SCRIPTS_DIR . 'post-restore.sh' );

// ============================================================
// Bootstrap — hook into main plugin
// ============================================================

add_action( 'csbr_after_scheduled_backup', 'csbr_clone_run_scheduled',    10, 1 );
add_action( 'csbr_admin_tab_buttons',      'csbr_clone_render_tab_button', 10    );
add_action( 'csbr_admin_tab_panels',       'csbr_clone_render_tab_panel',  10    );

// AJAX handlers
add_action( 'wp_ajax_csbr_clone_save_targets',  'csbr_clone_ajax_save_targets'  );
add_action( 'wp_ajax_csbr_clone_verify_config', 'csbr_clone_ajax_verify_config' );
add_action( 'wp_ajax_csbr_clone_now',           'csbr_clone_ajax_clone_now'     );

// ============================================================
// Data helpers
// ============================================================

/**
 * Return all clone targets from options.
 *
 * @return array<int, array>
 */
function csbr_clone_get_targets(): array {
    return (array) get_option( 'csbr_clone_targets', [] );
}

/**
 * Persist clone targets.
 *
 * @param array $targets
 */
function csbr_clone_save_targets( array $targets ): void {
    update_option( 'csbr_clone_targets', array_values( $targets ), false );
}

// ============================================================
// wp-config.php scanner — find a valid default
// ============================================================

/**
 * Scan common WordPress install locations and return the path to the first
 * wp-config.php that parses successfully (has literal DB_NAME define).
 * Returns empty string if none found.
 */
function csbr_clone_find_valid_wpconfig(): string {
    $abspath = rtrim( ABSPATH, '/' );
    $parent  = dirname( $abspath );

    $candidates = array_unique( array_filter( [
        // Siblings of the current install (most likely staging locations)
        $parent . '/staging/wp-config.php',
        $parent . '/dev/wp-config.php',
        $parent . '/test/wp-config.php',
        $parent . '/testing/wp-config.php',
        $parent . '/clone/wp-config.php',
        // Current install itself
        $abspath . '/wp-config.php',
        // Common server roots
        '/var/www/html/staging/wp-config.php',
        '/var/www/staging/wp-config.php',
        '/srv/www/staging/wp-config.php',
        '/srv/staging/wp-config.php',
        '/home/*/public_html/staging/wp-config.php',
    ] ) );

    // Expand any glob patterns
    $expanded = [];
    foreach ( $candidates as $c ) {
        if ( strpos( $c, '*' ) !== false ) {
            foreach ( glob( $c ) ?: [] as $g ) { $expanded[] = $g; }
        } else {
            $expanded[] = $c;
        }
    }

    foreach ( $expanded as $path ) {
        if ( ! file_exists( $path ) ) continue;
        $result = csbr_clone_parse_wpconfig( $path );
        if ( $result['ok'] ) return $path;
    }
    return '';
}

// ============================================================
// wp-config.php credential parser
// ============================================================

/**
 * Parse DB credentials and table_prefix from an arbitrary wp-config.php file.
 * Uses regex — does NOT include/execute the file.
 * The file must exist on the current server filesystem.
 *
 * @param  string $path Absolute path to a wp-config.php on this server.
 * @return array{ok: bool, error?: string, db_name?: string, db_user?: string, db_password?: string, db_host?: string, table_prefix?: string}
 */
function csbr_clone_parse_wpconfig( string $path ): array {
    $real = realpath( $path );
    if ( ! $real ) {
        return [ 'ok' => false, 'error' => 'File not found: ' . $path ];
    }
    // Must be on this server (realpath resolved) and readable
    if ( ! is_readable( $real ) ) {
        return [ 'ok' => false, 'error' => 'File not readable: ' . $real ];
    }
    $contents = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local file
    if ( $contents === false ) {
        return [ 'ok' => false, 'error' => 'Could not read file.' ];
    }

    $result = [ 'ok' => true ];

    // Extract define( 'KEY', 'value' ) — handles:
    //   define( 'DB_NAME', 'literal' )
    //   define( 'DB_NAME', getenv_docker( 'ENV_VAR', 'default' ) )
    //   define( 'DB_NAME', getenv( 'ENV_VAR' ) )
    foreach ( [
        'DB_NAME'     => 'db_name',
        'DB_USER'     => 'db_user',
        'DB_PASSWORD' => 'db_password',
        'DB_HOST'     => 'db_host',
    ] as $const => $key ) {
        $qc = preg_quote( $const, '/' );
        // Literal string value
        if ( preg_match( "/define\s*\(\s*['\"]" . $qc . "['\"]\s*,\s*['\"]([^'\"]*)['\"\s*]\)/", $contents, $m ) ) {
            $result[ $key ] = $m[1];
        // getenv_docker( 'ENV_VAR', 'default' ) — use the default value
        } elseif ( preg_match( "/define\s*\(\s*['\"]" . $qc . "['\"]\s*,\s*getenv_docker\s*\(\s*['\"][^'\"]*['\"]\s*,\s*['\"]([^'\"]*)['\"\s*]\)\s*\)/", $contents, $m ) ) {
            $result[ $key ] = $m[1];
        // getenv( 'ENV_VAR' ) — value unknown at parse time, use env var name as placeholder
        } elseif ( preg_match( "/define\s*\(\s*['\"]" . $qc . "['\"]\s*,\s*getenv\s*\(\s*['\"]([^'\"]*)['\"\s*]\)\s*\)/", $contents, $m ) ) {
            $result[ $key ] = '${' . $m[1] . '}'; // placeholder so required-field check passes
        }
    }

    // table_prefix is a variable assignment: $table_prefix = 'wp_';
    if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $contents, $m ) ) {
        $result['table_prefix'] = $m[1];
    }

    $required = [ 'db_name', 'db_user', 'db_host' ];
    foreach ( $required as $k ) {
        if ( empty( $result[ $k ] ) ) {
            return [ 'ok' => false, 'error' => 'Could not find ' . strtoupper( str_replace( 'db_', 'DB_', $k ) ) . ' in file.' ];
        }
    }

    return $result;
}

// ============================================================
// Default post-restore scripts generator
// ============================================================

/**
 * All parameters passed to every script as environment variables:
 *
 *   CSBR_TARGET_DIR    — absolute path of the restored WordPress install
 *   CSBR_TARGET_URL    — URL configured for this clone target
 *   CSBR_SOURCE_URL    — original site URL (before URL rewrite)
 *   CSBR_TARGET_NAME   — name/tag of this clone target
 *   CSBR_TARGET_DB     — target database name
 *   CSBR_TARGET_DB_HOST — target database host
 *   CSBR_TARGET_DB_USER — target database user
 *   CSBR_BACKUP_FILE   — absolute path of the backup ZIP that was restored
 *   CSBR_BACKUP_DATE   — ISO-8601 timestamp of when the backup was created
 *   CSBR_WP_VERSION    — WordPress version string from backup metadata
 */

/**
 * Write default clone scripts to CSBR_CLONE_SCRIPTS_DIR on first use.
 * Never overwrites an existing file so user customisations are preserved.
 */
function csbr_clone_ensure_default_scripts(): void {
    if ( ! is_dir( CSBR_CLONE_SCRIPTS_DIR ) ) {
        wp_mkdir_p( CSBR_CLONE_SCRIPTS_DIR );
    }

    $scripts = csbr_clone_default_scripts();
    foreach ( $scripts as $filename => $content ) {
        $path = CSBR_CLONE_SCRIPTS_DIR . $filename;
        if ( ! file_exists( $path ) ) {
            file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            chmod( $path, 0755 );
        }
    }
}

/**
 * Return the set of default scripts as filename => content.
 *
 * @return array<string, string>
 */
function csbr_clone_default_scripts(): array {
    return [

        // ── Main entry point ─────────────────────────────────────────────────
        'post-restore.sh' => <<<'BASH'
#!/bin/bash
# =============================================================================
# CloudScale Backup & Restore — Clone post-restore entry point
# =============================================================================
# Called automatically after every successful clone restore.
# Edit this file or any of the helper scripts in this directory.
#
# Available environment variables (set by the plugin):
#   CSBR_TARGET_DIR      Absolute path of restored WordPress install
#   CSBR_TARGET_URL      URL configured for this clone target
#   CSBR_SOURCE_URL      Original site URL (before URL rewrite)
#   CSBR_TARGET_NAME     Name/tag of this clone target
#   CSBR_TARGET_DB       Target database name
#   CSBR_TARGET_DB_HOST  Target database host
#   CSBR_TARGET_DB_USER  Target database user
#   CSBR_BACKUP_FILE     Absolute path of the backup ZIP restored from
#   CSBR_BACKUP_DATE     ISO-8601 timestamp of the backup
#   CSBR_WP_VERSION      WordPress version in the backup
#
# Exit 0 on success. Non-zero exit marks the clone as FAILED and triggers
# a failure notification.
# =============================================================================

set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "[post-restore] === Clone restore post-processing ==="
echo "[post-restore] Target:  $CSBR_TARGET_NAME"
echo "[post-restore] Dir:     $CSBR_TARGET_DIR"
echo "[post-restore] URL:     $CSBR_TARGET_URL"
echo "[post-restore] DB:      $CSBR_TARGET_DB @ $CSBR_TARGET_DB_HOST"
echo "[post-restore] Backup:  $CSBR_BACKUP_FILE ($CSBR_BACKUP_DATE)"

# ── Step 1: Flush caches ──────────────────────────────────────────────────────
bash "$SCRIPT_DIR/flush-caches.sh"

# ── Step 2: Fix file permissions ─────────────────────────────────────────────
bash "$SCRIPT_DIR/fix-permissions.sh"

# ── Step 3: Set up web server virtual host ───────────────────────────────────
# bash "$SCRIPT_DIR/setup-vhost.sh"

# ── Step 4: Set up Cloudflare tunnel ─────────────────────────────────────────
# bash "$SCRIPT_DIR/setup-cloudflare-tunnel.sh"

# ── Step 5: Custom steps ─────────────────────────────────────────────────────
# Add anything else here, or create new scripts and call them above.

echo "[post-restore] === Done ==="
exit 0
BASH,

        // ── Cache flush ──────────────────────────────────────────────────────
        'flush-caches.sh' => <<<'BASH'
#!/bin/bash
# =============================================================================
# CloudScale Clone — flush-caches.sh
# Flushes WordPress object cache and any opcode caches.
# Edit as needed. All CSBR_* env vars are available.
# =============================================================================

set -e
echo "[flush-caches] Flushing caches for: $CSBR_TARGET_NAME"

# WP-CLI object cache flush (if wp available)
if command -v wp &>/dev/null; then
    wp --path="$CSBR_TARGET_DIR" cache flush --allow-root 2>/dev/null && \
        echo "[flush-caches] WP object cache flushed." || \
        echo "[flush-caches] WP cache flush skipped (non-fatal)."
fi

# PHP OpCache reset via CLI
if command -v php &>/dev/null; then
    php -r "if(function_exists('opcache_reset')) { opcache_reset(); echo \"opcache reset\n\"; }" 2>/dev/null || true
fi

echo "[flush-caches] Done."
exit 0
BASH,

        // ── File permissions ─────────────────────────────────────────────────
        'fix-permissions.sh' => <<<'BASH'
#!/bin/bash
# =============================================================================
# CloudScale Clone — fix-permissions.sh
# Sets correct ownership and permissions on the target WordPress install.
# Edit WEB_USER to match your web server user (apache, www-data, nginx, etc).
# =============================================================================

set -e
WEB_USER="apache"   # ← change if your server uses www-data or nginx

echo "[fix-permissions] Setting permissions in: $CSBR_TARGET_DIR"

chown -R "$WEB_USER:$WEB_USER" "$CSBR_TARGET_DIR" 2>/dev/null || \
    echo "[fix-permissions] chown skipped (may not have permission — non-fatal)."

find "$CSBR_TARGET_DIR" -type d -exec chmod 755 {} \; 2>/dev/null || true
find "$CSBR_TARGET_DIR" -type f -exec chmod 644 {} \; 2>/dev/null || true

# wp-config.php should be tighter
[ -f "$CSBR_TARGET_DIR/wp-config.php" ] && chmod 600 "$CSBR_TARGET_DIR/wp-config.php" || true

echo "[fix-permissions] Done."
exit 0
BASH,

        // ── Apache vhost ─────────────────────────────────────────────────────
        'setup-vhost.sh' => <<<'BASH'
#!/bin/bash
# =============================================================================
# CloudScale Clone — setup-vhost.sh
# Creates or updates an Apache virtual host for the clone target.
# Called from post-restore.sh — uncomment that line to enable.
# =============================================================================

set -e
DOMAIN="${CSBR_TARGET_URL#*://}"   # strip scheme
DOMAIN="${DOMAIN%%/*}"              # strip path
VHOST_FILE="/etc/httpd/conf.d/clone-${CSBR_TARGET_NAME}.conf"

echo "[setup-vhost] Configuring vhost for: $DOMAIN"

cat > "$VHOST_FILE" <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $CSBR_TARGET_DIR
    <Directory $CSBR_TARGET_DIR>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog  /var/log/httpd/${DOMAIN}-error.log
    CustomLog /var/log/httpd/${DOMAIN}-access.log combined
</VirtualHost>
EOF

echo "[setup-vhost] Wrote $VHOST_FILE"

# Reload Apache (adjust for your distro: httpd / apache2)
systemctl reload httpd 2>/dev/null || systemctl reload apache2 2>/dev/null || true

echo "[setup-vhost] Done."
exit 0
BASH,

        // ── Cloudflare tunnel ────────────────────────────────────────────────
        'setup-cloudflare-tunnel.sh' => <<<'BASH'
#!/bin/bash
# =============================================================================
# CloudScale Clone — setup-cloudflare-tunnel.sh
# Routes a Cloudflare tunnel hostname to the clone target.
# Called from post-restore.sh — uncomment that line to enable.
#
# Prerequisites:
#   - cloudflared installed and authenticated: cloudflared tunnel login
#   - A named tunnel already created: cloudflared tunnel create my-tunnel
#   - Set TUNNEL_NAME and any credentials below.
# =============================================================================

set -e

TUNNEL_NAME="my-tunnel"    # ← your Cloudflare tunnel name
DOMAIN="${CSBR_TARGET_URL#*://}"
DOMAIN="${DOMAIN%%/*}"

echo "[cf-tunnel] Routing $TUNNEL_NAME → $DOMAIN"

if ! command -v cloudflared &>/dev/null; then
    echo "[cf-tunnel] cloudflared not found — skipping." >&2
    exit 0
fi

cloudflared tunnel route dns "$TUNNEL_NAME" "$DOMAIN" 2>&1 || true

echo "[cf-tunnel] Done. DNS may take a few minutes to propagate."
exit 0
BASH,

    ]; // end $scripts
}

// ============================================================
// ZIP validation
// ============================================================

/**
 * Validate that a backup ZIP contains the components required for a full clone.
 * Returns array of missing component labels (empty = valid).
 *
 * @param  string $zip_path Absolute path to backup ZIP.
 * @return string[] List of missing component descriptions.
 */
function csbr_clone_validate_zip( string $zip_path ): array {
    $missing = [];
    $zip     = new ZipArchive();
    if ( $zip->open( $zip_path ) !== true ) {
        return [ 'Cannot open backup ZIP.' ];
    }

    $required = [
        'database.sql'         => 'Database (database.sql)',
        'core/wp-includes/'    => 'WordPress core (wp-includes)',
        'plugins/'             => 'Plugins',
        'themes/'              => 'Themes',
    ];

    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = $zip->getNameIndex( $i );
        foreach ( $required as $prefix => $label ) {
            if ( str_starts_with( $name, $prefix ) || $name === rtrim( $prefix, '/' ) ) {
                unset( $required[ $prefix ] );
            }
        }
        if ( empty( $required ) ) break;
    }
    $zip->close();

    return array_values( $required );
}

// ============================================================
// Serialization-safe search-replace
// ============================================================

/**
 * Replace $old with $new in a possibly-serialized PHP string,
 * recalculating s:N: byte-length prefixes after replacement.
 *
 * @param  string $old
 * @param  string $new
 * @param  string $data Raw cell value from the DB.
 * @return string       Updated value.
 */
function csbr_clone_replace_value( string $old, string $new, string $data ): string {
    if ( $old === $new || strpos( $data, $old ) === false ) {
        return $data;
    }
    // If it looks serialized, unserialize → replace recursively → re-serialize
    if ( is_serialized( $data ) ) {
        $unserialized = @unserialize( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional
        if ( $unserialized !== false || $data === serialize( false ) ) {
            $replaced = csbr_clone_replace_recursive( $old, $new, $unserialized );
            $reserialized = serialize( $replaced );
            // Verify re-serialization round-trips cleanly
            if ( @unserialize( $reserialized ) !== false || $reserialized === serialize( false ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                return $reserialized;
            }
        }
    }
    // Plain string replace
    return str_replace( $old, $new, $data );
}

/**
 * Recursively replace $old with $new in any PHP value (array/object/string).
 */
function csbr_clone_replace_recursive( string $old, string $new, mixed $data ): mixed {
    if ( is_string( $data ) ) {
        return str_replace( $old, $new, $data );
    }
    if ( is_array( $data ) ) {
        $out = [];
        foreach ( $data as $k => $v ) {
            $out[ csbr_clone_replace_recursive( $old, $new, $k ) ] = csbr_clone_replace_recursive( $old, $new, $v );
        }
        return $out;
    }
    if ( is_object( $data ) ) {
        foreach ( get_object_vars( $data ) as $k => $v ) {
            $data->$k = csbr_clone_replace_recursive( $old, $new, $v );
        }
        return $data;
    }
    return $data;
}

/**
 * Run search-replace across DB tables for a clone target.
 * Connects to the TARGET database using provided credentials.
 *
 * @param string $old_url   Source site URL (no trailing slash).
 * @param string $new_url   Target site URL (no trailing slash).
 * @param array  $db_creds  DB credentials from csbr_clone_parse_wpconfig().
 */
function csbr_clone_search_replace( string $old_url, string $new_url, array $db_creds ): void {
    if ( $old_url === $new_url ) return;

    $prefix = $db_creds['table_prefix'] ?? 'wp_';
    $host   = $db_creds['db_host']     ?? 'localhost';
    $name   = $db_creds['db_name']     ?? '';
    $user   = $db_creds['db_user']     ?? '';
    $pass   = $db_creds['db_password'] ?? '';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connecting to external DB, not current WP DB
    $dbh = new mysqli( $host, $user, $pass, $name );
    if ( $dbh->connect_error ) {
        csbr_log( '[CSBR Clone] Search-replace DB connect failed: ' . $dbh->connect_error );
        return;
    }
    $dbh->set_charset( 'utf8mb4' );

    $tables = [
        $prefix . 'options'  => [ 'option_value' ],
        $prefix . 'posts'    => [ 'post_content', 'post_excerpt', 'guid' ],
        $prefix . 'postmeta' => [ 'meta_value' ],
        $prefix . 'usermeta' => [ 'meta_value' ],
        $prefix . 'termmeta' => [ 'meta_value' ],
    ];

    foreach ( $tables as $table => $columns ) {
        // Check table exists
        $res = $dbh->query( 'SHOW TABLES LIKE \'' . $dbh->real_escape_string( $table ) . '\'' );
        if ( ! $res || $res->num_rows === 0 ) continue;

        // Determine primary key
        $pk  = 'id';
        $res = $dbh->query( 'SHOW KEYS FROM `' . $dbh->real_escape_string( $table ) . '` WHERE Key_name = \'PRIMARY\'' );
        if ( $res && $res->num_rows > 0 ) {
            $pk = $res->fetch_assoc()['Column_name'];
        }

        foreach ( $columns as $col ) {
            $col_esc = $dbh->real_escape_string( $col );
            $tbl_esc = $dbh->real_escape_string( $table );
            $pk_esc  = $dbh->real_escape_string( $pk );

            // Fetch rows containing old URL
            $rows = $dbh->query(
                "SELECT `{$pk_esc}`, `{$col_esc}` FROM `{$tbl_esc}` WHERE `{$col_esc}` LIKE '%" . $dbh->real_escape_string( $old_url ) . "%'"
            );
            if ( ! $rows ) continue;

            while ( $row = $rows->fetch_assoc() ) {
                $updated = csbr_clone_replace_value( $old_url, $new_url, (string) $row[ $col ] );
                if ( $updated === $row[ $col ] ) continue;
                $dbh->query(
                    "UPDATE `{$tbl_esc}` SET `{$col_esc}` = '" . $dbh->real_escape_string( $updated ) . "' WHERE `{$pk_esc}` = '" . $dbh->real_escape_string( $row[ $pk ] ) . "'"
                );
            }
        }
    }
    $dbh->close();
    csbr_log( '[CSBR Clone] URL search-replace complete: ' . $old_url . ' → ' . $new_url );
}

// ============================================================
// Core clone restore executor
// ============================================================

/**
 * Execute a full clone restore for a single target.
 *
 * @param  array  $target   Clone target config array.
 * @param  string $zip_path Absolute path to backup ZIP to restore from.
 * @return array{ok: bool, error?: string, steps: array<string>}
 */
function csbr_execute_clone_restore( array $target, string $zip_path ): array {
    $steps  = [];
    $t_dir  = rtrim( $target['target_dir'] ?? '', '/' );
    $t_url  = rtrim( $target['target_url'] ?? '', '/' );
    $cfg    = $target['wpconfig_path']      ?? '';
    $script = $target['post_restore_script'] ?? '';
    $name   = $target['name']               ?? 'unnamed';

    csbr_log( '[CSBR Clone] Starting clone restore for target: ' . $name );

    // ── Step 1: Parse target wp-config.php ───────────────────────────────────
    $creds = csbr_clone_parse_wpconfig( $cfg );
    if ( ! $creds['ok'] ) {
        return [ 'ok' => false, 'error' => 'wp-config parse failed: ' . $creds['error'], 'steps' => $steps ];
    }
    $steps[] = 'Parsed wp-config.php — DB: ' . $creds['db_name'] . ' @ ' . $creds['db_host'];
    csbr_log( '[CSBR Clone] ' . end( $steps ) );

    // ── Step 2: Validate ZIP has required components ──────────────────────────
    $missing = csbr_clone_validate_zip( $zip_path );
    if ( $missing ) {
        return [ 'ok' => false, 'error' => 'Backup ZIP missing: ' . implode( ', ', $missing ), 'steps' => $steps ];
    }
    $steps[] = 'ZIP validation passed.';
    csbr_log( '[CSBR Clone] ' . end( $steps ) );

    // ── Step 3: Validate target directory ────────────────────────────────────
    if ( ! is_dir( $t_dir ) ) {
        return [ 'ok' => false, 'error' => 'Target directory does not exist: ' . $t_dir, 'steps' => $steps ];
    }
    if ( ! is_writable( $t_dir ) ) {
        return [ 'ok' => false, 'error' => 'Target directory is not writable: ' . $t_dir, 'steps' => $steps ];
    }
    $steps[] = 'Target directory OK: ' . $t_dir;

    // ── Step 4: Create database if needed ────────────────────────────────────
    $db_create = csbr_clone_create_database_if_needed( $creds );
    if ( ! $db_create['ok'] ) {
        return [ 'ok' => false, 'error' => 'DB create failed: ' . $db_create['error'], 'steps' => $steps ];
    }
    $steps[] = $db_create['created'] ? 'Created database: ' . $creds['db_name'] : 'Database exists: ' . $creds['db_name'];
    csbr_log( '[CSBR Clone] ' . end( $steps ) );

    // ── Step 5: Wipe existing target content ─────────────────────────────────
    $wipe = csbr_clone_wipe_target( $t_dir, $creds );
    if ( ! $wipe['ok'] ) {
        return [ 'ok' => false, 'error' => 'Wipe failed: ' . $wipe['error'], 'steps' => $steps ];
    }
    $steps[] = 'Wiped target DB and wp-content directories.';
    csbr_log( '[CSBR Clone] ' . end( $steps ) );

    // ── Step 6: Extract ZIP to target directory ───────────────────────────────
    $extract = csbr_clone_extract_zip( $zip_path, $t_dir );
    if ( ! $extract['ok'] ) {
        return [ 'ok' => false, 'error' => 'Extract failed: ' . $extract['error'], 'steps' => $steps ];
    }
    $steps[] = 'Extracted backup ZIP to target directory.';
    csbr_log( '[CSBR Clone] ' . end( $steps ) );

    // ── Step 7: Copy wp-config.php to target ─────────────────────────────────
    if ( ! copy( realpath( $cfg ), $t_dir . '/wp-config.php' ) ) {
        return [ 'ok' => false, 'error' => 'Failed to copy wp-config.php to target.', 'steps' => $steps ];
    }
    $steps[] = 'Copied wp-config.php to target.';

    // ── Step 8: Import database ───────────────────────────────────────────────
    $import = csbr_clone_import_database( $zip_path, $creds );
    if ( ! $import['ok'] ) {
        return [ 'ok' => false, 'error' => 'DB import failed: ' . $import['error'], 'steps' => $steps ];
    }
    $steps[] = 'Database imported successfully.';
    csbr_log( '[CSBR Clone] ' . end( $steps ) );

    // ── Step 9: URL search-replace ────────────────────────────────────────────
    $source_url = rtrim( get_site_url(), '/' );
    if ( $t_url && $t_url !== $source_url ) {
        csbr_clone_search_replace( $source_url, $t_url, $creds );
        $steps[] = 'URL rewrite complete: ' . $source_url . ' → ' . $t_url;
    } else {
        $steps[] = 'URL rewrite skipped (same URL as source).';
    }

    // ── Step 10: Run post-restore script ─────────────────────────────────────
    // Use per-target script if set, otherwise fall back to the default main script.
    $run_script = $script ?: CSBR_CLONE_MAIN_SCRIPT;

    // Read backup metadata for richer env vars
    $meta        = csbr_clone_read_backup_meta( $zip_path );
    $env = [
        'CSBR_TARGET_DIR'      => $t_dir,
        'CSBR_TARGET_URL'      => $t_url,
        'CSBR_SOURCE_URL'      => $source_url,
        'CSBR_TARGET_NAME'     => $name,
        'CSBR_TARGET_DB'       => $creds['db_name']     ?? '',
        'CSBR_TARGET_DB_HOST'  => $creds['db_host']     ?? '',
        'CSBR_TARGET_DB_USER'  => $creds['db_user']     ?? '',
        'CSBR_BACKUP_FILE'     => $zip_path,
        'CSBR_BACKUP_DATE'     => $meta['created']      ?? '',
        'CSBR_WP_VERSION'      => $meta['wp_version']   ?? '',
    ];
    $env_prefix = '';
    foreach ( $env as $k => $v ) {
        $env_prefix .= $k . '=' . escapeshellarg( (string) $v ) . ' ';
    }

    if ( file_exists( $run_script ) && is_executable( $run_script ) ) {
        $cmd = $env_prefix . escapeshellarg( $run_script ) . ' 2>&1';
        exec( $cmd, $out_lines, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- intentional post-restore hook
        $output = implode( "\n", $out_lines );
        if ( $code !== 0 ) {
            csbr_log( '[CSBR Clone] Post-restore script exited ' . $code . ': ' . substr( $output, 0, 500 ) );
            return [ 'ok' => false, 'error' => 'Post-restore script failed (exit ' . $code . '): ' . substr( $output, 0, 200 ), 'steps' => $steps ];
        }
        $steps[] = 'Post-restore script ran OK: ' . basename( $run_script );
        csbr_log( '[CSBR Clone] Post-restore script OK — ' . substr( $output, 0, 200 ) );
    } else {
        csbr_log( '[CSBR Clone] Post-restore script not found or not executable: ' . $run_script );
        $steps[] = 'Post-restore script skipped (not found/not executable): ' . basename( $run_script );
    }

    csbr_log( '[CSBR Clone] Clone restore complete for target: ' . $name );
    return [ 'ok' => true, 'steps' => $steps ];
}

// ============================================================
// DB helpers
// ============================================================

/**
 * Create the target database if it doesn't already exist.
 *
 * @return array{ok: bool, created: bool, error?: string}
 */
function csbr_clone_create_database_if_needed( array $creds ): array {
    $host = $creds['db_host']     ?? 'localhost';
    $user = $creds['db_user']     ?? '';
    $pass = $creds['db_password'] ?? '';
    $name = $creds['db_name']     ?? '';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connecting to external/target DB
    $dbh = new mysqli( $host, $user, $pass );
    if ( $dbh->connect_error ) {
        return [ 'ok' => false, 'created' => false, 'error' => $dbh->connect_error ];
    }
    $db_esc = $dbh->real_escape_string( $name );
    $res    = $dbh->query( "CREATE DATABASE IF NOT EXISTS `{$db_esc}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
    $created = $res && $dbh->affected_rows > 0;
    $dbh->close();
    return [ 'ok' => (bool) $res, 'created' => $created, 'error' => $res ? '' : 'CREATE DATABASE failed.' ];
}

/**
 * Drop all tables in the target DB and clear wp-content subdirs.
 *
 * @return array{ok: bool, error?: string}
 */
function csbr_clone_wipe_target( string $target_dir, array $creds ): array {
    $host = $creds['db_host']     ?? 'localhost';
    $user = $creds['db_user']     ?? '';
    $pass = $creds['db_password'] ?? '';
    $name = $creds['db_name']     ?? '';

    // Drop all tables
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- target DB
    $dbh = new mysqli( $host, $user, $pass, $name );
    if ( $dbh->connect_error ) {
        return [ 'ok' => false, 'error' => $dbh->connect_error ];
    }
    $dbh->query( 'SET FOREIGN_KEY_CHECKS = 0' );
    $tables = $dbh->query( 'SHOW TABLES' );
    if ( $tables ) {
        while ( $row = $tables->fetch_row() ) {
            $dbh->query( 'DROP TABLE IF EXISTS `' . $dbh->real_escape_string( $row[0] ) . '`' );
        }
    }
    $dbh->query( 'SET FOREIGN_KEY_CHECKS = 1' );
    $dbh->close();

    // Wipe wp-content subdirectories that the ZIP will repopulate
    $wc = $target_dir . '/wp-content';
    foreach ( [ 'uploads', 'plugins', 'themes', 'mu-plugins', 'languages' ] as $subdir ) {
        $path = $wc . '/' . $subdir;
        if ( is_dir( $path ) ) {
            csbr_clone_rmdir_recursive( $path );
        }
    }

    return [ 'ok' => true ];
}

/**
 * Recursively delete a directory.
 */
function csbr_clone_rmdir_recursive( string $dir ): void {
    if ( ! is_dir( $dir ) ) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $items as $item ) {
        $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- filesystem ops on external path
    }
    rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * Extract ZIP contents to target directory, mapping ZIP paths to filesystem paths.
 *
 * ZIP layout → filesystem:
 *   core/wp-admin/    → {target}/wp-admin/
 *   core/wp-includes/ → {target}/wp-includes/
 *   core/*.php        → {target}/*.php
 *   core/wp-content-index.php → {target}/wp-content/index.php
 *   uploads/          → {target}/wp-content/uploads/
 *   plugins/          → {target}/wp-content/plugins/
 *   themes/           → {target}/wp-content/themes/
 *   mu-plugins/       → {target}/wp-content/mu-plugins/
 *   languages/        → {target}/wp-content/languages/
 *   dropins/          → {target}/wp-content/
 *   .htaccess         → {target}/.htaccess
 *   (database.sql, backup-meta.json, wp-config.php skipped here)
 *
 * @return array{ok: bool, error?: string}
 */
function csbr_clone_extract_zip( string $zip_path, string $target_dir ): array {
    $zip = new ZipArchive();
    if ( $zip->open( $zip_path ) !== true ) {
        return [ 'ok' => false, 'error' => 'Cannot open ZIP.' ];
    }

    $enc_pwd = (string) get_option( 'csbr_encrypt_password', '' );
    if ( $enc_pwd !== '' ) {
        $zip->setPassword( $enc_pwd );
    }

    $skip = [ 'database.sql', 'backup-meta.json', 'wp-config.php' ];

    // Map of ZIP prefix → filesystem destination
    $map = [
        'core/wp-admin/'    => $target_dir . '/wp-admin/',
        'core/wp-includes/' => $target_dir . '/wp-includes/',
        'uploads/'          => $target_dir . '/wp-content/uploads/',
        'plugins/'          => $target_dir . '/wp-content/plugins/',
        'themes/'           => $target_dir . '/wp-content/themes/',
        'mu-plugins/'       => $target_dir . '/wp-content/mu-plugins/',
        'languages/'        => $target_dir . '/wp-content/languages/',
    ];

    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = $zip->getNameIndex( $i );

        // Skip files handled elsewhere
        if ( in_array( $name, $skip, true ) ) continue;
        // Skip directory entries
        if ( str_ends_with( $name, '/' ) ) continue;

        // Determine destination
        $dest = null;

        // core/*.php → root PHP files
        if ( preg_match( '#^core/([^/]+\.php)$#', $name, $m ) ) {
            if ( $m[1] === 'wp-content-index.php' ) {
                $dest = $target_dir . '/wp-content/index.php';
            } else {
                $dest = $target_dir . '/' . $m[1];
            }
        } elseif ( $name === '.htaccess' ) {
            $dest = $target_dir . '/.htaccess';
        } elseif ( str_starts_with( $name, 'dropins/' ) ) {
            $dest = $target_dir . '/wp-content/' . substr( $name, strlen( 'dropins/' ) );
        } else {
            foreach ( $map as $prefix => $dir ) {
                if ( str_starts_with( $name, $prefix ) ) {
                    $dest = $dir . substr( $name, strlen( $prefix ) );
                    break;
                }
            }
        }

        if ( $dest === null ) continue; // unknown ZIP entry — skip

        // Ensure parent directory exists
        $parent = dirname( $dest );
        if ( ! is_dir( $parent ) ) {
            wp_mkdir_p( $parent );
        }

        $data = $zip->getFromIndex( $i );
        if ( $data === false ) {
            $zip->close();
            return [ 'ok' => false, 'error' => 'Failed to read ZIP entry: ' . $name ];
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions -- writing to external target path
        file_put_contents( $dest, $data );
    }

    $zip->close();
    return [ 'ok' => true ];
}

/**
 * Read backup-meta.json from the ZIP and return it as an array.
 *
 * @param  string $zip_path
 * @return array
 */
function csbr_clone_read_backup_meta( string $zip_path ): array {
    $zip = new ZipArchive();
    if ( $zip->open( $zip_path ) !== true ) return [];
    $json = $zip->getFromName( 'backup-meta.json' );
    $zip->close();
    if ( $json === false ) return [];
    return (array) json_decode( $json, true );
}

/**
 * Extract database.sql from the ZIP and import it into the target DB via mysql CLI.
 *
 * @return array{ok: bool, error?: string}
 */
function csbr_clone_import_database( string $zip_path, array $creds ): array {
    $zip = new ZipArchive();
    if ( $zip->open( $zip_path ) !== true ) {
        return [ 'ok' => false, 'error' => 'Cannot open ZIP.' ];
    }

    $enc_pwd = (string) get_option( 'csbr_encrypt_password', '' );
    if ( $enc_pwd !== '' ) {
        $zip->setPassword( $enc_pwd );
    }

    $sql = $zip->getFromName( 'database.sql' );
    $zip->close();

    if ( $sql === false ) {
        return [ 'ok' => false, 'error' => 'database.sql not found in ZIP.' ];
    }

    // Write to temp file and import via mysql CLI
    $tmp = wp_tempnam( 'csbr_clone_db' ) . '.sql';
    // phpcs:ignore WordPress.WP.AlternativeFunctions -- temp file
    file_put_contents( $tmp, $sql );

    [$host, $port] = csbr_parse_db_host( $creds['db_host'] ?? 'localhost' );
    $pass = $creds['db_password'] ?? '';
    $user = $creds['db_user']     ?? '';
    $name = $creds['db_name']     ?? '';

    $cmd  = sprintf(
        'MYSQL_PWD=%s mysql -h %s -P %s -u %s %s < %s 2>&1',
        escapeshellarg( $pass ),
        escapeshellarg( $host ),
        escapeshellarg( (string) $port ),
        escapeshellarg( $user ),
        escapeshellarg( $name ),
        escapeshellarg( $tmp )
    );
    exec( $cmd, $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- mysql CLI import
    unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions

    if ( $code !== 0 ) {
        return [ 'ok' => false, 'error' => implode( ' ', $out ) ];
    }
    return [ 'ok' => true ];
}

// ============================================================
// Scheduled clone runner
// ============================================================

/**
 * Run scheduled clone restores for all enabled targets whose schedule matches today.
 * Hooked to csbr_after_scheduled_backup.
 *
 * @param string $filename Backup filename (basename).
 */
function csbr_clone_run_scheduled( string $filename ): void {
    $targets = csbr_clone_get_targets();
    if ( empty( $targets ) ) return;

    $today    = (int) ( new DateTime( 'now', wp_timezone() ) )->format( 'N' ); // 1=Mon…7=Sun
    $zip_path = CSBR_BACKUP_DIR . $filename;

    if ( ! file_exists( $zip_path ) ) {
        csbr_log( '[CSBR Clone] Scheduled clone skipped — ZIP not found: ' . $zip_path );
        return;
    }

    foreach ( $targets as $i => $target ) {
        if ( empty( $target['enabled'] ) ) continue;

        $days = array_map( 'intval', (array) ( $target['schedule_days'] ?? [] ) );
        if ( ! empty( $days ) && ! in_array( $today, $days, true ) ) continue;

        $name   = $target['name'] ?? 'target #' . ( $i + 1 );
        $result = csbr_execute_clone_restore( $target, $zip_path );

        // Update last run status
        $targets[ $i ]['last_run']    = time();
        $targets[ $i ]['last_result'] = $result['ok'] ? 'ok' : 'failed';
        $targets[ $i ]['last_error']  = $result['ok'] ? '' : ( $result['error'] ?? '' );

        // Notification
        $body = ( $result['ok'] ? "Clone restore completed successfully." : "Clone restore FAILED." )
            . "\n\nTarget: {$name}"
            . "\nZIP: {$filename}"
            . ( $result['ok'] ? '' : "\nError: " . ( $result['error'] ?? 'unknown' ) )
            . "\n\nSteps:\n" . implode( "\n", array_map( fn( $s ) => '  ' . $s, $result['steps'] ?? [] ) )
            . "\n\nSite: " . home_url() . "\n";

        csbr_send_backup_notification( $result['ok'], 'Clone: ' . $name, $body );
    }

    csbr_clone_save_targets( $targets );
}

// ============================================================
// AJAX handlers
// ============================================================

/**
 * Save all clone targets from the UI.
 */
function csbr_clone_ajax_save_targets(): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );

    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $raw = json_decode( wp_unslash( $_POST['targets'] ?? '[]' ), true );
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

    if ( ! is_array( $raw ) ) {
        wp_send_json_error( 'Invalid data.' );
    }

    $clean = [];
    foreach ( $raw as $t ) {
        if ( empty( $t['target_dir'] ) || empty( $t['wpconfig_path'] ) ) continue;
        $clean[] = [
            'id'                  => sanitize_key( $t['id'] ?? uniqid( 'ct_', true ) ),
            'name'                => sanitize_text_field( $t['name'] ?? '' ),
            'target_dir'          => sanitize_text_field( $t['target_dir'] ?? '' ),
            'target_url'          => esc_url_raw( $t['target_url'] ?? '' ),
            'wpconfig_path'       => sanitize_text_field( $t['wpconfig_path'] ?? '' ),
            'post_restore_script' => sanitize_text_field( $t['post_restore_script'] ?? '' ),
            'schedule_days'       => array_map( 'intval', (array) ( $t['schedule_days'] ?? [] ) ),
            'enabled'             => ! empty( $t['enabled'] ),
            'last_run'            => (int) ( $t['last_run'] ?? 0 ),
            'last_result'         => sanitize_key( $t['last_result'] ?? '' ),
            'last_error'          => sanitize_text_field( $t['last_error'] ?? '' ),
        ];
    }

    csbr_clone_save_targets( $clean );
    csbr_clone_ensure_default_scripts();
    csbr_log( '[CSBR Clone] Clone targets saved — ' . count( $clean ) . ' target(s).' );
    wp_send_json_success( [ 'count' => count( $clean ), 'scripts_dir' => CSBR_CLONE_SCRIPTS_DIR ] );
}

/**
 * Verify a wp-config.php path and return what credentials were found.
 */
function csbr_clone_ajax_verify_config(): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $path   = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
    $result = csbr_clone_parse_wpconfig( $path );
    if ( ! $result['ok'] ) {
        wp_send_json_error( $result['error'] );
    }
    wp_send_json_success( [
        'db_name'      => $result['db_name']      ?? '',
        'db_host'      => $result['db_host']       ?? '',
        'table_prefix' => $result['table_prefix']  ?? 'wp_',
    ] );
}

/**
 * Manually trigger a clone restore for a specific target.
 */
function csbr_clone_ajax_clone_now(): void {
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
    check_ajax_referer( 'csbr_nonce', 'nonce' );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $target_id = sanitize_key( wp_unslash( $_POST['target_id'] ?? '' ) );

    $targets = csbr_clone_get_targets();
    $target  = null;
    $idx     = null;
    foreach ( $targets as $i => $t ) {
        if ( ( $t['id'] ?? '' ) === $target_id ) {
            $target = $t;
            $idx    = $i;
            break;
        }
    }
    if ( $target === null ) {
        wp_send_json_error( 'Target not found — save your target first using the Save button, then click Clone Now.' );
    }

    // Find latest local backup ZIP
    $zips = glob( CSBR_BACKUP_DIR . '*.zip' ) ?: [];
    if ( empty( $zips ) ) {
        wp_send_json_error( 'No local backup found to clone from.' );
    }
    usort( $zips, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );
    $zip_path = $zips[0];

    $result = csbr_execute_clone_restore( $target, $zip_path );

    $targets[ $idx ]['last_run']    = time();
    $targets[ $idx ]['last_result'] = $result['ok'] ? 'ok' : 'failed';
    $targets[ $idx ]['last_error']  = $result['ok'] ? '' : ( $result['error'] ?? '' );
    csbr_clone_save_targets( $targets );

    if ( $result['ok'] ) {
        wp_send_json_success( [ 'steps' => $result['steps'] ] );
    } else {
        wp_send_json_error( $result['error'] . ' — Steps: ' . implode( ' | ', $result['steps'] ?? [] ) );
    }
}

// ============================================================
// UI — Clone tab button + panel
// ============================================================

/**
 * Inject the Clone tab button into the tab bar.
 */
function csbr_clone_render_tab_button(): void {
    echo '<button class="cs-tab" data-tab="clone">&#128260; Clone Targets</button>';
}

/**
 * Render the full Clone tab panel.
 */
function csbr_clone_render_tab_panel(): void {
    $targets  = csbr_clone_get_targets();
    $days_map = [ 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' ];
    $scripts_exist = is_dir( CSBR_CLONE_SCRIPTS_DIR ) && file_exists( CSBR_CLONE_MAIN_SCRIPT );
    ?>
    <div id="cs-tab-clone" class="cs-tab-panel" style="display:none">
    <hr style="border:none;border-top:3px solid #37474f;margin:18px 0 16px;">
    <?php csbr_clone_render_card_inner( $targets, $days_map, $scripts_exist ); ?>
    </div>
    <?php
}

/**
 * Render the Clone Targets card content (used inside the tab panel).
 */
function csbr_clone_render_card_inner( array $targets, array $days_map, bool $scripts_exist ): void {
    ?>
    <div class="cs-card cs-full" id="cs-clone-card">
        <div class="cs-card-stripe" style="background:linear-gradient(135deg,#1a237e 0%,#283593 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
            <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128260; Clone Targets</h2>
            <button type="button" onclick="csCloneExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; Explain&hellip;</button>
        </div>

        <p class="cs-help" style="margin:0 0 16px;">After each scheduled backup, the plugin automatically restores a fresh clone to each enabled target on its scheduled days — ideal for staging, destructive plugin testing, or DR. Each clone fully replaces the target.</p>

        <?php if ( ! $scripts_exist ): ?>
        <div style="background:#fff8e1;border:1px solid #f9a825;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:0.85rem;">
            <strong>Clone scripts not yet generated.</strong> Save a target to create them at:<br>
            <code style="font-size:0.82rem;"><?php echo esc_html( CSBR_CLONE_SCRIPTS_DIR ); ?></code>
        </div>
        <?php else: ?>
        <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:0.85rem;">
            <strong>Clone scripts:</strong> <code style="font-size:0.82rem;"><?php echo esc_html( CSBR_CLONE_SCRIPTS_DIR ); ?></code><br>
            <span style="color:#555;">Edit <code>post-restore.sh</code> to customise steps. Sub-scripts: <code>flush-caches.sh</code>, <code>fix-permissions.sh</code>, <code>setup-vhost.sh</code>, <code>setup-cloudflare-tunnel.sh</code>. Files are never overwritten once created.</span>
        </div>
        <?php endif; ?>

        <!-- Datalist of known valid wp-config.php paths for autocomplete -->
        <datalist id="csbr-cfg-suggestions">
        <?php
        // Only suggest paths that actually exist (scanner already validated the first one)
        $csbr_cfg_suggestions = array_filter( array_unique( array_filter( [
            $csbr_clone_found_cfg, // validated by scanner
            // Non-existent candidates shown as guidance
            dirname( rtrim( ABSPATH, '/' ) ) . '/staging/wp-config.php',
            dirname( rtrim( ABSPATH, '/' ) ) . '/dev/wp-config.php',
        ] ) ) );
        foreach ( $csbr_cfg_suggestions as $csbr_s ):
        ?>
            <option value="<?php echo esc_attr( $csbr_s ); ?>">
        <?php endforeach; ?>
        </datalist>

        <!-- Target list -->
        <div id="cs-clone-target-list">
        <?php foreach ( $targets as $i => $t ): ?>
            <?php csbr_clone_render_target_row( $t, $i, $days_map ); ?>
        <?php endforeach; ?>
        </div>

        <div style="margin-top:14px;">
            <button type="button" class="button" onclick="csCloneAddTarget()">+ Add Clone Target</button>
        </div>
    </div>

    <script>
    <?php $csbr_clone_found_cfg = csbr_clone_find_valid_wpconfig(); ?>
    window.CSBR_CLONE_DEFAULTS = {
        wpbase:      '<?php echo esc_js( rtrim( ABSPATH, '/' ) ); ?>',
        default_dir: '<?php echo esc_js( dirname( rtrim( ABSPATH, '/' ) ) . '/staging' ); ?>',
        default_cfg: '<?php echo esc_js( $csbr_clone_found_cfg ); ?>',
        site_url:    '<?php echo esc_js( rtrim( home_url(), '/' ) ); ?>'
    };
    (function(){
        var dayNames = {1:'Mon',2:'Tue',3:'Wed',4:'Thu',5:'Fri',6:'Sat',7:'Sun'};

        window.csCloneExplain = function() {
            csShowExplain('Clone Targets',
                '<p>Each clone target is a separate WordPress environment that gets a fresh restore after your scheduled backup runs on the selected days.</p>' +
                '<p><strong>How it works:</strong></p><ol>' +
                '<li>Your scheduled local backup completes as normal.</li>' +
                '<li>For each enabled target whose day matches today, the plugin:<br>' +
                '&nbsp;&nbsp;• Wipes the target database and wp-content directories<br>' +
                '&nbsp;&nbsp;• Extracts the backup ZIP to the target directory<br>' +
                '&nbsp;&nbsp;• Imports the database to the target DB<br>' +
                '&nbsp;&nbsp;• Rewrites all URLs to the target URL<br>' +
                '&nbsp;&nbsp;• Runs the post-restore script (if set)</li></ol>' +
                '<p><strong>Post-restore scripts:</strong> A set of default scripts is generated at:<br>' +
                '<code><?php echo esc_js( CSBR_CLONE_SCRIPTS_DIR ); ?></code><br>' +
                'The entry point is <code>post-restore.sh</code>. Sub-scripts handle cache flushing, file permissions, Apache vhost setup, and Cloudflare tunnel routing. Uncomment the relevant lines in <code>post-restore.sh</code> to activate each step. Files are never overwritten once created.</p>' +
                '<p><strong>wp-config.php:</strong> Point each target at a wp-config.php already on this server. The plugin reads the DB credentials from it and copies it to the target directory after restore.</p>' +
                '<p><strong>Prerequisites:</strong> The target directory must exist and WordPress core must be included in the backup ZIP (enable <em>WP Core files</em> in backup settings). The target database will be created automatically if it does not exist.</p>'
            );
        };

        window.csCloneAutoUrl = function(nameInput) {
            var row = nameInput.closest('.cs-clone-target');
            var urlInput = row.querySelector('.ct-url');
            if (!urlInput) return;
            // Only auto-fill if the URL field is still empty or was previously auto-filled
            if (urlInput.value.trim() && urlInput.dataset.autoFilled !== '1') return;
            var name = nameInput.value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            if (!name) { urlInput.value = ''; urlInput.dataset.autoFilled = '1'; return; }
            var d = window.CSBR_CLONE_DEFAULTS || {};
            var siteUrl = d.site_url || '';
            if (!siteUrl) return;
            // Extract protocol + domain: https://your-wordpress-site.example.com → https:// + andrewbaker.ninja
            var m = siteUrl.match(/^(https?:\/\/)(.+)$/);
            if (!m) return;
            urlInput.value = m[1] + name + '.' + m[2];
            urlInput.dataset.autoFilled = '1';
        };

        window.csCloneAutoCfg = function(dirInput) {
            var row = dirInput.closest('.cs-clone-target');
            var cfgInput = row.querySelector('.ct-cfg');
            if (!cfgInput) return;
            // Only auto-fill if the current value is still the default (current site's config)
            var defaults = window.CSBR_CLONE_DEFAULTS || {};
            var cur = cfgInput.value.trim();
            if (cur && cur !== (defaults.default_cfg || '')) return; // user has customised it
            var dir = dirInput.value.trim().replace(/\/+$/, '');
            if (dir) cfgInput.value = dir + '/wp-config.php';
        };

        window.csCloneAddTarget = function() {
            var idx = document.querySelectorAll('.cs-clone-target').length;
            var d = window.CSBR_CLONE_DEFAULTS || {};
            var tpl = csCloneBuildTargetHTML({
                id: 'ct_' + Date.now(),
                name: '', target_dir: d.default_dir || '', target_url: '',
                wpconfig_path: d.default_cfg || '', post_restore_script: '',
                schedule_days: [], enabled: true,
                last_run: 0, last_result: '', last_error: ''
            }, idx, true);
            document.getElementById('cs-clone-target-list').insertAdjacentHTML('beforeend', tpl);
        };

        function csCloneRowToData(row) {
            var days = [];
            row.querySelectorAll('input[type=checkbox][data-day]').forEach(function(cb) {
                if (cb.checked) days.push(parseInt(cb.getAttribute('data-day'), 10));
            });
            return {
                id:                  row.dataset.id,
                name:                row.querySelector('.ct-name').value.trim(),
                target_dir:          row.querySelector('.ct-dir').value.trim(),
                target_url:          row.querySelector('.ct-url').value.trim(),
                wpconfig_path:       row.querySelector('.ct-cfg').value.trim(),
                post_restore_script: row.querySelector('.ct-script').value.trim(),
                schedule_days:       days,
                enabled:             row.querySelector('.ct-enabled').checked,
                last_run:            parseInt(row.dataset.lastRun || 0, 10),
                last_result:         row.dataset.lastResult || '',
                last_error:          row.dataset.lastError  || ''
            };
        }

        window.csCloneSaveOne = function(btn) {
            var row = btn.closest('.cs-clone-target');
            var fb  = row.querySelector('.ct-clone-now-msg');
            fb.textContent = 'Saving\u2026'; fb.style.color = '#666';
            // Collect all rows (need to save complete list)
            var targets = [];
            document.querySelectorAll('.cs-clone-target').forEach(function(r) { targets.push(csCloneRowToData(r)); });
            jQuery.post(ajaxurl, { action: 'csbr_clone_save_targets', nonce: CSBR.nonce, targets: JSON.stringify(targets) })
                .done(function(r) {
                    if (r.success) {
                        fb.textContent = '\u2714 Saved'; fb.style.color = '#2e7d32';
                        setTimeout(function() { csCloneToggle(row.querySelector('.ct-toggle-btn')); }, 600);
                    } else {
                        fb.textContent = '\u2718 ' + (r.data || 'Error'); fb.style.color = '#c62828';
                    }
                }).fail(function() { fb.textContent = 'Network error.'; fb.style.color = '#c62828'; });
        };

        window.csCloneSaveAll = function() {
            var targets = [];
            document.querySelectorAll('.cs-clone-target').forEach(function(r) { targets.push(csCloneRowToData(r)); });
            jQuery.post(ajaxurl, { action: 'csbr_clone_save_targets', nonce: CSBR.nonce, targets: JSON.stringify(targets) })
                .done(function(r) {}) .fail(function() {});
        };

        window.csCloneVerifyConfig = function(btn) {
            var row   = btn.closest('.cs-clone-target');
            var path  = row.querySelector('.ct-cfg').value.trim();
            var fb    = row.querySelector('.ct-cfg-feedback');
            if (!path) { fb.textContent = 'Enter a path first.'; fb.style.color='#c62828'; return; }
            fb.textContent = 'Checking\u2026'; fb.style.color='#666';
            jQuery.post(ajaxurl, { action:'csbr_clone_verify_config', nonce:CSBR.nonce, path:path })
                .done(function(r) {
                    if (r.success) {
                        fb.textContent = '\u2714 DB: ' + r.data.db_name + ' @ ' + r.data.db_host + ' (prefix: ' + r.data.table_prefix + ')';
                        fb.style.color = '#2e7d32';
                    } else {
                        fb.textContent = '\u2718 ' + r.data;
                        fb.style.color = '#c62828';
                    }
                }).fail(function(){ fb.textContent='Network error.'; fb.style.color='#c62828'; });
        };

        window.csCloneNow = function(btn) {
            var row = btn.closest('.cs-clone-target');
            var fb  = row.querySelector('.ct-clone-now-msg');
            btn.disabled = true;
            fb.textContent = 'Saving\u2026'; fb.style.color = '#666';
            var targets = [];
            document.querySelectorAll('.cs-clone-target').forEach(function(r) { targets.push(csCloneRowToData(r)); });
            jQuery.post(ajaxurl, { action: 'csbr_clone_save_targets', nonce: CSBR.nonce, targets: JSON.stringify(targets) })
                .done(function(sr) {
                    if (!sr.success) {
                        btn.disabled = false;
                        fb.textContent = '\u2718 Save failed: ' + (sr.data || 'unknown'); fb.style.color = '#c62828';
                        return;
                    }
                    fb.textContent = 'Cloning\u2026 (this may take a few minutes)'; fb.style.color = '#1565c0';
                    jQuery.post(ajaxurl, { action: 'csbr_clone_now', nonce: CSBR.nonce, target_id: row.dataset.id })
                        .done(function(r) {
                            btn.disabled = false;
                            fb.textContent = r.success ? '\u2714 Clone complete.' : '\u2718 ' + r.data;
                            fb.style.color  = r.success ? '#2e7d32' : '#c62828';
                        }).fail(function() { btn.disabled=false; fb.textContent='Network error.'; fb.style.color='#c62828'; });
                }).fail(function() { btn.disabled=false; fb.textContent='Network error.'; fb.style.color='#c62828'; });
        };

        window.csCloneToggle = function(btn) {
            var target = btn.closest('.cs-clone-target');
            var body   = target.querySelector('.ct-body');
            var isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'block';
            btn.textContent    = isOpen ? '\u25bc Edit' : '\u25b2 Close';
        };

        window.csCloneRemoveTarget = function(btn) {
            if (!confirm('Remove this clone target?')) return;
            btn.closest('.cs-clone-target').remove();
        };

        window.csCloneBuildTargetHTML = function(t, idx, expanded) {
            var daysHtml = '';
            for (var d = 1; d <= 7; d++) {
                var chk = (t.schedule_days || []).indexOf(d) !== -1 ? 'checked' : '';
                daysHtml += '<label style="margin-right:8px;font-size:0.82rem;"><input type="checkbox" data-day="'+d+'" '+chk+'> '+dayNames[d]+'</label>';
            }
            var lastRun = t.last_run ? new Date(t.last_run * 1000).toLocaleString() : 'Never';
            var statusBadge = '<span style="color:#90a4ae;font-size:0.78rem;white-space:nowrap;">Never run</span>';
            var errorLine = '';
            if (t.last_result === 'ok') {
                statusBadge = '<span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:3px;font-size:0.75rem;font-weight:700;white-space:nowrap;">\u2714 OK</span>';
            } else if (t.last_result === 'failed') {
                statusBadge = '<span style="background:#fce4ec;color:#c62828;padding:2px 8px;border-radius:3px;font-size:0.75rem;font-weight:700;white-space:nowrap;">\u2718 FAILED</span>';
                if (t.last_error) errorLine = '<div style="color:#c62828;font-size:0.8rem;margin-top:3px;">'+csbrEscHtml(t.last_error.substring(0,200))+'</div>';
            }
            var bodyDisplay  = expanded ? 'block' : 'none';
            var toggleLabel  = expanded ? '\u25b2 Close' : '\u25bc Edit';
            return '<div class="cs-clone-target" style="margin-bottom:8px;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;" data-id="'+csbrEscAttr(t.id||'')+'" data-last-run="'+(t.last_run||0)+'" data-last-result="'+csbrEscAttr(t.last_result||'')+'" data-last-error="'+csbrEscAttr(t.last_error||'')+'">' +
                '<div class="ct-header" style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f8f9fa;flex-wrap:wrap;">' +
                    '<label style="display:flex;align-items:center;gap:5px;font-size:0.82rem;font-weight:600;white-space:nowrap;cursor:pointer;">' +
                        '<input type="checkbox" class="ct-enabled" '+(t.enabled ? 'checked' : '')+'> On' +
                    '</label>' +
                    '<input type="text" class="ct-name regular-text" placeholder="Environment name" value="'+csbrEscAttr(t.name||'')+'" style="flex:1;min-width:120px;max-width:220px;font-weight:600;" oninput="csCloneAutoUrl(this)">' +
                    statusBadge +
                    '<div style="display:flex;gap:6px;margin-left:auto;">' +
                        '<button type="button" class="button button-small ct-toggle-btn" onclick="csCloneToggle(this)">'+toggleLabel+'</button>' +
                        '<button type="button" class="button button-small" style="color:#c62828;border-color:#c62828;" onclick="csCloneRemoveTarget(this)">Delete</button>' +
                    '</div>' +
                '</div>' +
                '<div class="ct-body" style="display:'+bodyDisplay+';padding:16px;">' +
                    '<div class="cs-field-row" style="margin-bottom:8px;"><label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:3px;">Target directory</label>' +
                        '<input type="text" class="ct-dir large-text" placeholder="/var/www/html/staging" value="'+csbrEscAttr(t.target_dir||'')+'" style="width:100%;max-width:480px;" oninput="csCloneAutoCfg(this)">' +
                        '<p class="cs-help" style="margin:2px 0 0;">Absolute path where WordPress is installed on this server. Must already have WP core files.</p></div>' +
                    '<div class="cs-field-row" style="margin-bottom:8px;"><label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:3px;">Target URL</label>' +
                        '<input type="text" class="ct-url regular-text" placeholder="https://staging.example.com" value="'+csbrEscAttr(t.target_url||'')+'" style="width:100%;max-width:380px;" oninput="this.dataset.autoFilled=\'0\'">' +
                        '<p class="cs-help" style="margin:2px 0 0;">All URLs in the restored database will be rewritten to this.</p></div>' +
                    '<div class="cs-field-row" style="margin-bottom:8px;"><label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:3px;">wp-config.php path</label>' +
                        '<div style="display:flex;gap:6px;align-items:center;">' +
                            '<input type="text" class="ct-cfg large-text" placeholder="/var/www/staging/wp-config.php" list="csbr-cfg-suggestions" value="'+csbrEscAttr(t.wpconfig_path||'')+'" style="flex:1;max-width:420px;">' +
                            '<button type="button" class="button" onclick="csCloneVerifyConfig(this)">Verify</button>' +
                        '</div>' +
                        '<span class="ct-cfg-feedback" style="font-size:0.82rem;display:block;margin-top:3px;"></span>' +
                        '<p class="cs-help" style="margin:2px 0 0;">Must exist on this server. DB credentials and table prefix are read from this file.</p></div>' +
                    '<div class="cs-field-row" style="margin-bottom:8px;"><label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:3px;">Post-restore script <span style="font-weight:400;color:#666;">(optional)</span></label>' +
                        '<input type="text" class="ct-script large-text" placeholder="Leave blank to use default script" value="'+csbrEscAttr(t.post_restore_script||'')+'" style="width:100%;max-width:480px;">' +
                        '<p class="cs-help" style="margin:2px 0 0;">Absolute path to a shell script. Leave blank to use the default script.</p></div>' +
                    '<div style="margin-bottom:8px;"><label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:4px;">Schedule days</label>' +
                        '<div>'+daysHtml+'</div>' +
                        '<p class="cs-help" style="margin:2px 0 0;">Clone runs after the local backup on these days. Leave all unchecked to never run automatically.</p></div>' +
                    '<div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid #e9ecef;">' +
                        '<button type="button" class="button button-primary" onclick="csCloneSaveOne(this)">Save</button>' +
                        '<button type="button" class="button" style="background:#1565c0!important;color:#fff!important;border-color:#0d47a1!important;" onclick="csCloneNow(this)">Clone Now</button>' +
                        '<button type="button" class="button ct-toggle-btn" onclick="csCloneToggle(this)">Cancel</button>' +
                        '<span class="ct-clone-now-msg" style="font-size:0.83rem;font-weight:600;"></span>' +
                        '<div style="margin-left:auto;text-align:right;font-size:0.8rem;color:#90a4ae;">Last run: '+csbrEscHtml(lastRun)+errorLine+'</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        };

        function csbrEscHtml(s) { var d=document.createElement('div'); d.appendChild(document.createTextNode(s||'')); return d.innerHTML; }
        function csbrEscAttr(s) { return csbrEscHtml(s||'').replace(/"/g,'&quot;'); }
    })();
    </script>
    <?php
}

/**
 * Render a single target row (server-side, for initial page load).
 *
 * @param array  $t        Target config.
 * @param int    $i        Index.
 * @param array  $days_map Day number → label.
 */
function csbr_clone_render_target_row( array $t, int $i, array $days_map ): void {
    $last_run = $t['last_run'] ? wp_date( 'j M Y H:i', (int) $t['last_run'] ) : 'Never';
    $status   = $t['last_result'] ?? '';
    ?>
    <div class="cs-clone-target"
         style="margin-bottom:8px;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;"
         data-id="<?php echo esc_attr( $t['id'] ?? '' ); ?>"
         data-last-run="<?php echo esc_attr( (string) ( $t['last_run'] ?? 0 ) ); ?>"
         data-last-result="<?php echo esc_attr( $status ); ?>"
         data-last-error="<?php echo esc_attr( $t['last_error'] ?? '' ); ?>">

        <!-- Header (always visible) -->
        <div class="ct-header" style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f8f9fa;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:5px;font-size:0.82rem;font-weight:600;white-space:nowrap;cursor:pointer;">
                <input type="checkbox" class="ct-enabled" <?php checked( ! empty( $t['enabled'] ) ); ?>> On
            </label>
            <input type="text" class="ct-name regular-text" placeholder="Environment name"
                   value="<?php echo esc_attr( $t['name'] ?? '' ); ?>"
                   style="flex:1;min-width:120px;max-width:220px;font-weight:600;"
                   oninput="csCloneAutoUrl(this)">
            <?php if ( $status === 'ok' ): ?>
                <span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:3px;font-size:0.75rem;font-weight:700;white-space:nowrap;">&#10004; OK</span>
            <?php elseif ( $status === 'failed' ): ?>
                <span style="background:#fce4ec;color:#c62828;padding:2px 8px;border-radius:3px;font-size:0.75rem;font-weight:700;white-space:nowrap;">&#10008; FAILED</span>
            <?php else: ?>
                <span style="color:#90a4ae;font-size:0.78rem;white-space:nowrap;">Never run</span>
            <?php endif; ?>
            <div style="display:flex;gap:6px;margin-left:auto;">
                <button type="button" class="button button-small ct-toggle-btn"
                        onclick="csCloneToggle(this)">&#9660; Edit</button>
                <button type="button" class="button button-small"
                        style="color:#c62828;border-color:#c62828;"
                        onclick="csCloneRemoveTarget(this)">Delete</button>
            </div>
        </div>

        <!-- Body (collapsed by default) -->
        <div class="ct-body" style="display:none;padding:16px;">

            <div class="cs-field-row" style="margin-bottom:8px;">
                <label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:3px;">Target directory</label>
                <input type="text" class="ct-dir large-text" placeholder="/var/www/html/staging"
                       value="<?php echo esc_attr( $t['target_dir'] ?? '' ); ?>"
                       style="width:100%;max-width:480px;" oninput="csCloneAutoCfg(this)">
                <p class="cs-help" style="margin:2px 0 0;">Absolute path where WordPress is installed on this server. Must already have WP core files.</p>
            </div>

            <div class="cs-field-row" style="margin-bottom:8px;">
                <label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:3px;">Target URL</label>
                <input type="text" class="ct-url regular-text" placeholder="https://staging.example.com"
                       value="<?php echo esc_attr( $t['target_url'] ?? '' ); ?>"
                       style="width:100%;max-width:380px;" oninput="this.dataset.autoFilled='0'">
                <p class="cs-help" style="margin:2px 0 0;">All URLs in the restored database will be rewritten to this.</p>
            </div>

            <div class="cs-field-row" style="margin-bottom:8px;">
                <label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:3px;">wp-config.php path</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="text" class="ct-cfg large-text" placeholder="/var/www/staging/wp-config.php"
                           list="csbr-cfg-suggestions"
                           value="<?php echo esc_attr( $t['wpconfig_path'] ?? '' ); ?>" style="flex:1;max-width:420px;">
                    <button type="button" class="button" onclick="csCloneVerifyConfig(this)">Verify</button>
                </div>
                <span class="ct-cfg-feedback" style="font-size:0.82rem;display:block;margin-top:3px;"></span>
                <p class="cs-help" style="margin:2px 0 0;">Must exist on this server. DB credentials and table prefix are read from this file.</p>
            </div>

            <div class="cs-field-row" style="margin-bottom:8px;">
                <label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:3px;">
                    Post-restore script <span style="font-weight:400;color:#666;">(optional)</span>
                </label>
                <input type="text" class="ct-script large-text" placeholder="Leave blank to use default script"
                       value="<?php echo esc_attr( $t['post_restore_script'] ?? '' ); ?>" style="width:100%;max-width:480px;">
                <p class="cs-help" style="margin:2px 0 0;">Absolute path to a shell script. Leave blank to use the default script.</p>
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:4px;">Schedule days</label>
                <div>
                    <?php for ( $d = 1; $d <= 7; $d++ ): ?>
                        <label style="margin-right:8px;font-size:0.82rem;">
                            <input type="checkbox" data-day="<?php echo $d; ?>"
                                   <?php checked( in_array( $d, (array) ( $t['schedule_days'] ?? [] ), true ) ); ?>>
                            <?php echo esc_html( $days_map[ $d ] ); ?>
                        </label>
                    <?php endfor; ?>
                </div>
                <p class="cs-help" style="margin:2px 0 0;">Clone runs after the local backup on these days. Leave all unchecked to never run automatically.</p>
            </div>

            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid #e9ecef;">
                <button type="button" class="button button-primary"
                        onclick="csCloneSaveOne(this)">Save</button>
                <button type="button" class="button"
                        style="background:#1565c0!important;color:#fff!important;border-color:#0d47a1!important;"
                        onclick="csCloneNow(this)">Clone Now</button>
                <button type="button" class="button ct-toggle-btn"
                        onclick="csCloneToggle(this)">Cancel</button>
                <span class="ct-clone-now-msg" style="font-size:0.83rem;font-weight:600;"></span>
                <div style="margin-left:auto;text-align:right;font-size:0.8rem;color:#90a4ae;">
                    Last run: <?php echo esc_html( $last_run ); ?>
                    <?php if ( $status === 'failed' && ! empty( $t['last_error'] ) ): ?>
                        <div style="color:#c62828;font-size:0.8rem;margin-top:2px;"><?php echo esc_html( substr( $t['last_error'], 0, 200 ) ); ?></div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- .ct-body -->
    </div>
    <?php
}
