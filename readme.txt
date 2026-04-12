=== CloudScale Backup & Restore ===
Contributors: andrewjbaker
Tags: backup, restore, database, scheduled backup, maintenance mode
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 3.2.353
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress backup and restore. Backs up database, media, plugins and themes into a single zip. No timeouts, no limits.

== Description ==

Most backup plugins fail on large or busy sites. They hit PHP memory limits, execution timeouts, or external storage quotas. **CloudScale Backup & Restore** is built differently.

It uses native server tools (`mysqldump` and `mysql` CLI) where available for fast, lock-free dumps and restores with no PHP overhead at all. When those tools are not available, it falls back to a robust PHP-streamed implementation that reads the database in chunks and never loads the whole thing into memory at once.

**What it backs up**

Choose any combination of:

* Full WordPress database — all tables, all data
* Media uploads folder (`/wp-content/uploads/`)
* Plugins folder (`/wp-content/plugins/`)
* Themes folder (`/wp-content/themes/`)

Each backup is packaged as a single `.zip` file with a descriptive filename that reflects exactly what it contains.

**Key features**

* Run manual backups with one click — choose which components to include each time
* Configurable automatic scheduling — set your interval in days (1, 7, 30, or any number) and the exact hour of day to run
* Configurable retention — keep the last N backups; older ones are deleted automatically after each run
* Full backup history — view all stored backups with filename, type label, size, creation date, and age
* Download any backup directly from the WordPress admin
* Restore the database from any stored backup or by uploading a `.zip` or `.sql` file
* Safe restore workflow — puts the site into WordPress maintenance mode before restoring and removes it after
* Explicit snapshot warning before any restore operation — requires confirmation before proceeding
* System info panel — shows which backup and restore method will be used on your server, memory limits, and backup storage path
* Backup directory protected from direct web access with `.htaccess` deny-all
* All passwords passed to CLI tools via environment variable, not shell arguments

**Automatic backups are off by default.** Run your first backup manually to confirm everything works on your server, then configure a schedule if you need one.

**Smart method detection**

The plugin inspects your server on first use and picks the best available method automatically.

For database backup:

* If `mysqldump` is available: uses `--single-transaction --quick --lock-tables=false` for a fast, non-locking, consistent dump. No PHP execution time involved.
* If not available: PHP streamed fallback reads each table in 500-row chunks. Never loads the full database into memory.

For database restore:

* If the `mysql` CLI client is available: pipes the SQL directly into the database. Fast and handles files of any size.
* If not available: PHP streamed fallback parses SQL statement by statement with a character-level parser that correctly handles quoted strings, escaped characters, and multi-line `INSERT` statements.

The System Info card on the plugin page always shows you which method will be used on your server.

**Backup file naming**

Backup filenames describe their contents exactly, making them easy to identify without opening them:

* `backup_full_2026-02-21_03-00-00.zip` — all four components
* `backup_db_2026-02-21_14-35-00.zip` — database only
* `backup_db-media-plugins_2026-02-21_09-10-00.zip` — three components
* `backup_plugins-themes_2026-02-21_22-00-00.zip` — files only, no database

**Restore safety**

Clicking Restore DB opens a confirmation modal that:

1. Shows the exact backup file name and creation date you are restoring from
2. Displays a warning box explaining what will happen step by step
3. Requires you to tick a checkbox: *"I have taken a server snapshot and understand this will overwrite the live database"*
4. Only enables the Restore button after that checkbox is ticked

During restore, WordPress's native `.maintenance` file is created so visitors see the standard maintenance page. It is always removed when restore finishes, whether the restore succeeded or failed. The plugin header shows a live badge indicating whether the site is online or in maintenance mode.

**Backup zip contents**

Each zip includes a `backup-meta.json` with metadata: plugin version, creation timestamp, WordPress version, site URL, table prefix, and which components were included. This makes it easy to verify a backup without restoring it.

== Installation ==

**Option 1 — WordPress admin (recommended)**

1. Download `cloudscale-backup.zip`
2. In your WordPress admin, go to **Plugins > Add New Plugin > Upload Plugin**
3. Select the zip file and click **Install Now**
4. Click **Activate Plugin**
5. Go to **Tools > CloudScale Backup & Restore**

**Option 2 — Manual via FTP/SFTP**

1. Unzip `cloudscale-backup.zip`
2. Upload the `cloudscale-backup` folder to `/wp-content/plugins/`
3. Activate via **Plugins > Installed Plugins**
4. Go to **Tools > CloudScale Backup & Restore**

== Frequently Asked Questions ==

= Where are backups stored? =

In `/wp-content/cloudscale-backups/`. This directory is created automatically on activation and protected with an `.htaccess` deny-all rule. Download backups using the Download button in the admin panel, which uses a nonce-secured handler.

= Will it time out on large sites? =

No. The plugin sets `set_time_limit(0)` and `ignore_user_abort(true)` for all backup and restore operations. If `mysqldump` is available on your server, the database dump runs entirely outside PHP and is not subject to PHP time limits at all. Check the System Info card to see which method your server will use.

= What PHP version is required? =

PHP 8.1 or higher. The plugin uses typed parameters, `match` expressions, `str_contains()`, and first-class callable syntax introduced in PHP 8.0/8.1.

= Is ZipArchive required? =

Yes. The `ZipArchive` extension is needed to create and read backup zip files. It is bundled with PHP on the vast majority of shared hosting environments. If it is missing, contact your host and ask them to enable the `zip` PHP extension.

= How does the scheduling work? =

The plugin registers a custom WordPress cron interval based on the number of days you configure. For reliable scheduling, your server should have a real system cron job pointing at `wp-cron.php` rather than relying on WordPress's visitor-triggered pseudo-cron. Most managed WordPress hosts configure this automatically. Your server's current time and timezone are shown on the settings page so you can pick the right hour.

= Can I restore just the database and keep my current media? =

Yes. The restore function extracts `database.sql` from the backup zip and imports it. Media, plugin, and theme files inside the zip are not automatically restored — you can unzip the backup manually and extract only the folders you need. This prevents accidentally overwriting files you have added since the backup.

= Can I restore on a different server or after a domain change? =

Yes. The restore imports the SQL as-is. If the database contains hardcoded URLs from the old domain, run a search-replace using WP-CLI after restoring:

`wp search-replace 'olddomain.com' 'newdomain.com' --path=/path/to/wordpress`

= The restore failed. Is the site broken? =

The plugin removes maintenance mode even when a restore fails, so your site will be accessible. Check the plugin page to confirm the maintenance badge is gone. Errors are logged to your server's PHP error log. If the database is in a partial state, restore from a server snapshot or use phpMyAdmin or Adminer to assess the database directly.

= Can I trigger a backup from WP-CLI or a system cron job? =

Yes. Use WP-CLI to trigger a backup from the command line:

`wp eval 'csbr_create_backup(true, true, true, true); csbr_enforce_retention();' --path=/path/to/wordpress`

Adjust the four boolean arguments (`$include_db`, `$include_media`, `$include_plugins`, `$include_themes`) as needed.

= What is inside the backup zip? =

Each zip may contain:

* `database.sql` — complete SQL dump of all WordPress tables
* `uploads/` — full media uploads directory tree
* `plugins/` — full plugins directory tree
* `themes/` — full themes directory tree
* `backup-meta.json` — metadata including plugin version, creation timestamp, WordPress version, site URL, table prefix, and which components were backed up

= Can I use this to migrate my site to a new host? =

Yes. Run a full backup on the old site, install WordPress on the new host, install and activate this plugin, then use Restore from Upload to import the database. Copy the `uploads/`, `plugins/`, and `themes/` folders manually from the zip if needed, or use the backup of those folders.

== Screenshots ==

1. Schedule and settings panel showing configurable backup interval in days, run-at hour, retention count, folder sizes, and system information including detected backup and restore methods.
2. Manual backup panel with individual component checkboxes and live progress bar, plus the full backup history table showing stored backups with type badges, age, and Download / Restore DB / Delete actions.

== Changelog ==

= 3.2.257 =
* FIX: PCP compliance — `cs_admin_page()` now independently checks `current_user_can('manage_options')`
* FIX: PCP compliance — `wp_unslash()` added to `$_POST['cs_action']` and `$_POST['schedule_enabled']`; `phpcs:ignore` annotations added
* FIX: PCP compliance — `data-free-bytes` attribute annotated with `phpcs:ignore EscapeOutput.OutputNotEscaped`
* FIX: `uninstall.php` — Dropbox options now cleaned up on plugin delete
* FIX: Dropbox history pane infinite reload loop resolved
* FIX: AMI Save/Create buttons now show feedback message correctly
* UX: Copy buttons on all Explain modal code blocks; Dropbox setup wizard guide; italic placeholders

= 3.2.1 =
* Renamed internal constants with CS_BACKUP_ prefix to avoid collisions with other plugins
* Replaced @unlink(), @copy(), @rmdir() with wp_delete_file(), copy(), rmdir() per WordPress coding standards

= 3.2.0 =
* NEW: Split backup scheduling into two independent cron events — file backup and AMI snapshot each have their own day picker and time selector
* NEW: Configurable backup filename prefix (default: bkup), set in the Retention card
* NEW: S3 sync auto-retry — on failure a single cron event fires 5 minutes later; UI shows pending state and a manual Retry button
* FIX: Scheduled backup run hour never saved due to missing name attribute on the hour select
* FIX: Full+ backup type badge now renders distinctly from Full (separate CSS rule)
* AMI explain modal updated to document the two-schedule architecture; reboot defaults clarified (off = crash-consistent, no downtime)

= 2.74.2 =
* FIX: AMI creation failed with "Character sets beyond ASCII are not supported" due to em dash in description
* AMI description now stripped to printable ASCII only via regex
* AMI name now sanitised to AWS allowed characters (alphanumeric, hyphens, underscores, dots, slashes, parens) and capped at 128 chars
* Replaced sanitize_file_name with stricter AWS specific character filter

= 2.74.1 =
* FIX: AMI panel could vanish if IMDS endpoint was unreachable or curl failed during page render
* All IMDS calls now wrapped in try/catch with error suppression so failures cannot break the admin page
* Added curl_init availability check before attempting IMDS calls
* IMDS results cached in WordPress transients (1 hour TTL) to avoid repeated metadata calls on every page load
* AMI panel init block wrapped in top level try/catch — falls back to empty state on any error

= 2.74.0 =
* NEW: EC2 AMI Snapshot panel — create full machine images of the hosting instance directly from the plugin
* AMI name uses configurable prefix with automatic yyyyMMdd_HHmm timestamp suffix
* Optional instance reboot for filesystem consistent snapshots
* AMI creation history log with status tracking (last 5 shown, 20 stored)
* Check Status button to poll AMI state from AWS
* Explain modal with IAM policy requirements and restore instructions
* Auto detection of EC2 instance ID and region via IMDS (v1 and v2)
* Save/create/status operations via self contained AJAX handlers

= 1.0.0 =
* Initial public release of CloudScale Backup & Restore
* Manual and scheduled backup of database, media uploads, plugins folder, and themes folder
* Configurable schedule interval in days with specific run hour
* Configurable retention with automatic cleanup of oldest backups
* Backup history table with type labels, sizes, dates, and ages
* Download any backup from the admin panel
* Restore from stored backup or uploaded .zip / .sql file
* WordPress maintenance mode enabled during restore and always removed after
* Restore confirmation modal with snapshot warning and checkbox gate
* Smart detection of mysqldump and mysql CLI for native backup and restore
* PHP streamed fallback for environments without CLI tool access
* Backup directory protected with .htaccess deny-all
* System info panel showing detected methods, memory limits, and backup path

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== External services ==

This plugin optionally connects to third-party services when those features are configured by the site administrator. No data is sent to any external service unless the administrator has explicitly enabled the relevant integration.

= Amazon S3 (optional cloud backup) =
When S3 settings are configured, the plugin uploads backup zip files to the
administrator's own S3 bucket after each backup run. Only the backup zip file
is transmitted (database, media, plugins, and/or themes as selected).
Terms of Service: https://aws.amazon.com/service-terms/
Privacy Policy: https://aws.amazon.com/privacy/

= Google Drive via rclone (optional cloud backup) =
When Google Drive settings are configured, the plugin transfers backup zip files
to the administrator's own Google Drive using the rclone tool installed on the
server. Only the backup zip file is transmitted.
Terms of Service: https://policies.google.com/terms
Privacy Policy: https://policies.google.com/privacy

= Dropbox via rclone (optional cloud backup) =
When Dropbox settings are configured, the plugin transfers backup zip files to
the administrator's own Dropbox account using the rclone tool installed on the
server. Only the backup zip file is transmitted. No data is sent unless the
administrator has explicitly configured a Dropbox remote in rclone.
Terms of Service: https://www.dropbox.com/terms
Privacy Policy: https://www.dropbox.com/privacy

= Microsoft OneDrive via rclone (optional cloud backup) =
When OneDrive settings are configured, the plugin transfers backup zip files to
the administrator's own Microsoft OneDrive using the rclone tool installed on
the server. Only the backup zip file is transmitted. No data is sent unless the
administrator has explicitly configured an OneDrive remote in rclone.
Terms of Service: https://www.microsoft.com/en-us/servicesagreement/
Privacy Policy: https://privacy.microsoft.com/en-us/privacystatement

= AWS EC2 Instance Metadata Service (AMI snapshot feature only) =
When the AMI snapshot feature is used, the plugin reads instance metadata
(instance ID and region) from the local EC2 Instance Metadata Service at
http://169.254.169.254. This address is only reachable from within an EC2
instance and no data leaves the server at this step. AMI creation requests are
then issued via the AWS CLI installed on the server.

The plugin's AMI setup guide displays download URLs for the AWS CLI installer
(https://awscli.amazonaws.com/). These URLs are shown as copy-paste
installation commands for the site administrator to run manually. The plugin
itself does not download or contact awscli.amazonaws.com at any time.
Terms of Service: https://aws.amazon.com/service-terms/
Privacy Policy: https://aws.amazon.com/privacy/

= Automatic Crash Recovery — health check probe (optional) =
When Automatic Crash Recovery is enabled, the plugin periodically sends an HTTP
request to the administrator-configured health check URL to verify the site is
responding. The URL defaults to the site's own home URL. No personal data is
transmitted — the request is a plain GET with no authentication payload. The
same probe is made when the administrator clicks "Test Health Check" in the
plugin settings. If a system-cron watchdog script is installed on the server,
that script also probes this URL independently via curl. The health check URL
is set by the administrator and is never shared with any third party.
No Terms of Service or Privacy Policy apply (the request goes to a URL you own).

= Twilio (optional SMS crash alerts) =
When Twilio SMS alerting is configured, the plugin sends an SMS notification to
the administrator's phone number via the Twilio Messaging API whenever Automatic
Crash Recovery rolls back a plugin. Only the alert text, the configured "from"
number, and the configured "to" number are transmitted. No backup data or
personal user data is sent. No data is transmitted unless Twilio credentials
have been explicitly entered and the SMS feature is enabled.
Terms of Service: https://www.twilio.com/en-us/legal/tos
Privacy Policy: https://www.twilio.com/en-us/legal/privacy

= ntfy (optional push notifications) =
When ntfy is enabled, the plugin sends a push notification to the
administrator-configured ntfy topic URL on backup, restore, and plugin rollback
events. The notification contains the site name and a brief status message (e.g.
"Backup completed" or "Plugin rolled back"). No backup data, no personal user
data, and no authentication credentials are transmitted in the notification
payload. No data is sent unless ntfy has been explicitly enabled and a topic URL
entered by the administrator.

The plugin defaults to the hosted ntfy.sh service, but any self-hosted ntfy
server URL may be entered instead. When using the hosted ntfy.sh service:
Terms of Service: https://ntfy.sh/tos
Privacy Policy: https://ntfy.sh/privacy

== Files written outside the plugin folder ==

The Automatic Crash Recovery feature writes a single file outside the plugin
folder when it is enabled: `wp-content/fatal-error-handler.php`. This is a
WordPress-recognised drop-in file (documented in the WordPress Developer
Handbook under `get_dropins()`). WordPress core checks for this file on every
request; if present, it replaces the default fatal-error screen with a custom
handler. The plugin uses this mechanism to show a branded recovery page to
visitors while a crash is being fixed, instead of a blank white screen.

The file is written using the WordPress Filesystem API (WP_Filesystem). It is
only written when the feature is enabled, only overwritten if it was written by
this plugin (identified by an internal marker), and is deleted when the feature
is disabled or the plugin is uninstalled. No personal data is stored in this file.

== Privacy Policy ==

CloudScale Backup & Restore does not collect, transmit, or store any personal data. All backups are stored locally in `/wp-content/cloudscale-backups/`. No telemetry or analytics are sent by this plugin. Optional cloud backup features (S3, Google Drive, Dropbox, OneDrive, AMI snapshots) transmit data only when explicitly configured by the site administrator — see the External services section above.
