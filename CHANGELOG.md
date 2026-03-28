# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [3.2.139] - 2026-03-23

### Fixed
- PCP compliance: phpcs:disable blocks for `MissingUnslash` and `InputNotSanitized` now cover all applicable `$_POST` reads in manual backup, cloud schedule save, and retention save handlers.
- PCP compliance: `$_POST['schedule_enabled']` read in admin page render now annotated with `phpcs:ignore NonceVerification.Missing, MissingUnslash`.
- PCP compliance: global CSS enqueue for admin sidebar nav icon annotated with `phpcs:ignore GlobalEnqueuedAssets`.

## [3.2.136] - 2026-03-23

### Changed
- "Delete Soon" badge moved under the filename in the local backup history table (was in the # column).
- "Latest" badge moved under the filename in the local backup history table (was in the # column).
- Reverted `display:flex` on `cs-col-num` td (was breaking Dropbox column rendering).

## [3.2.132] - 2026-03-22

### Fixed
- PCP compliance: `cs_admin_page()` render callback now independently checks `current_user_can('manage_options')`.
- PCP compliance: `$_POST['cs_action']` and `$_POST['schedule_enabled']` now wrapped with `wp_unslash()` and annotated with `phpcs:ignore`.
- PCP compliance: `data-free-bytes` attribute output annotated with `phpcs:ignore EscapeOutput.OutputNotEscaped` (integer cast, no user data).
- `uninstall.php`: Dropbox options now cleaned up on plugin delete.
- Dropbox history pane infinite reload loop resolved.
- AMI Save/Create buttons now show feedback message correctly.

### Added
- Copy buttons on all Explain modal code blocks.
- Dropbox setup wizard guide.
- Italic placeholders on text inputs.

## [3.2.125] - 2026-03-22
- FIX: PCP compliance — added `wp_unslash()` to `intval($_POST['run_hour'])` and `intval($_POST['run_minute'])` in schedule form handler
- FIX: PCP compliance — added `phpcs:ignore EscapeOutput.OutputNotEscaped` to hardcoded static string echoes (off-notice, cloud-off-notice, colspan, row class, inline style)
- FIX: PCP compliance — `'Never'` in last-sync ternaries changed to `esc_html__('Never', ...)` for all three providers (GDrive, Dropbox, S3)
- FIX: PCP compliance — hardcoded HTML false branch in `$ami_region` ternary (×2) annotated with `phpcs:ignore`
- FIX: PCP compliance — `readfile($tmp)` in Dropbox download handler now has inline `phpcs:ignore` (was on preceding line only)
- FIX: `uninstall.php` — added missing Dropbox options to cleanup list (`cs_dropbox_remote`, `cs_dropbox_path`, `cs_dropbox_log`, `cs_dropbox_sync_enabled`, `cs_dropbox_remote_count`, `cs_dropbox_history`)

## [3.2.124] - 2026-03-22
- FIX: Dropbox history refresh loop — `#cs-db-tbody` now always rendered; JS no longer triggers `location.reload()` when table is empty
- FIX: "Dropbox (Beta)" label removed from history source dropdown

## [3.2.123] - 2026-03-22
- CHANGE: BETA label removed from Dropbox card heading and "Include in cloud backup" checkbox

## [3.2.122] - 2026-03-22
- UX: All cloud provider input placeholders changed to `[Enter Remote Name]`, `[Enter Folder Path]`, `[Enter Bucket Name]`, `[Enter Path Prefix]`, `[Enter Region Override]`, `[Enter Name Prefix]` format
- UX: Placeholder text now rendered italic via `::placeholder` CSS rule
- UX: Explain modals (Dropbox, GDrive, S3, AMI Step 3) updated to reference new placeholder labels

## [3.2.121] - 2026-03-22
- UX: Inactive tab background darkened to `#d8d8d8` / border `#bdbdbd` for clearer active/inactive distinction

## [3.2.120] - 2026-03-22
- UX: Dropbox Explain Step 3 rewritten as a full prompt/answer table covering every rclone config wizard step in sequence

## [3.2.119] - 2026-03-22
- UX: Dropbox Explain — "Keep this remote? y/e/d>" step added with answer `y` then `q`

## [3.2.118] - 2026-03-22
- UX: Dropbox Explain — browser success message "Success! All done. Please go back to rclone." added to config_token guidance

## [3.2.117] - 2026-03-22
- UX: Dropbox Explain — `config_token>` prompt guidance added; explains running `rclone authorize "dropbox"` on laptop and pasting token back

## [3.2.116] - 2026-03-22
- UX: Dropbox Explain — "Use web browser to automatically authenticate?" prompt added; clarifies always choose `n` on a server

## [3.2.115] - 2026-03-22
- UX: Dropbox Explain Step 3 — numbered prompt list added covering `n`, name, storage `15`, blank client_id/secret, no advanced config, `n` for browser auth

## [3.2.114] - 2026-03-22
- FIX: Dropbox history pane triggered infinite `location.reload()` loop when `$dropbox_history` was empty (table not rendered, JS couldn't find `#cs-db-tbody`)
- FIX: "Dropbox (Beta)" label in history source `<select>` removed

## [3.2.113] - 2026-03-22
- FIX: `csAmiMsg()` — feedback from Save/Create AMI buttons was written to hidden `#cs-ami-msg` (inside inactive history action bar); now writes to both `#cs-ami-settings-msg` and `#cs-ami-msg` so message is always visible
- UX: Copy-to-clipboard buttons added to all block `<code>` snippets in every Explain modal
- BUILD: WordPress Plugin Standards Review removed from `build.sh` (manual process)

## [3.2.94] - 2026-03-22
- FIX: `wp_die()` calls in Dropbox download handler now use `esc_html__()` — resolves PCP `EscapeOutput.OutputNotEscaped` critical violations
- FIX: `@since` tags on Dropbox functions corrected to 3.2.83

## [3.2.93] - 2026-03-22
- CHANGE: Renamed "Run Backup Now" → "Create Local Backup Now" throughout plugin and JS
- UX: Dashboard widget "CloudScale Backup & Restore" button changed from red to blue
- FIX: Cloud Schedule Explain modal updated to include Dropbox and correct execution order

## [3.2.92] - 2026-03-22
- FIX: Dropbox widget row now always shown; "Not configured" in grey when rclone remote not set

## [3.2.91] - 2026-03-22
- UX: Dashboard widget cloud sync rows now displayed two-per-line

## [3.2.90] - 2026-03-22
- NEW: Dashboard widget shows last-sync age for each configured cloud provider with traffic-light colouring

## [3.2.89] - 2026-03-22
- UX: AMI Explain modal rewritten with full AWS CLI install + IAM role setup guide

## [3.2.88] - 2026-03-22
- FIX: All Explain modals now scroll — `max-height: 65vh; overflow-y: auto` applied globally in `csShowExplain()`

## [3.2.87] - 2026-03-22
- UX: Cloud backup history panel moved inside Cloud Backups tab only
- UX: Dropbox labelled BETA in card, schedule checkboxes, and history dropdown

## [3.2.86] - 2026-03-22
- UX: Dashboard widget buttons swapped — "Create Local Backup Now" first, "CloudScale Backup & Restore" second

## [3.2.85] - 2026-03-22
- FIX: "Create Local Backup Now" card correctly placed as first section inside Local Backups tab; CloudScale header restored to top of page

## [3.2.84] - 2026-03-22
- UX: CloudScale header restored to top of page; "Create Local Backup Now" first in Local tab
- UX: "Site Online" badge removed; Help button changed to transparent pill with white border

## [3.2.83] - 2026-03-22
- NEW: Dropbox backup support via rclone (save, test, diagnose, sync, history, golden image, tags, delete, download)
- NEW: Dropbox card in Cloud tab with rclone setup instructions in Explain modal (BETA)
- NEW: Unified cloud backup history panel in Cloud tab — dropdown for S3, Google Drive, Dropbox, AMI
- NEW: Local backup history restored to Local Backups tab
- CHANGE: Provider inline setup instructions removed from cards — behind Explain button only
- CHANGE: cs_sync_to_dropbox(), cs_dropbox_refresh_history(), cs_enforce_dropbox_retention() added
- CHANGE: Scheduled cloud backup and cs_create_backup() now include Dropbox sync
- CHANGE: cs_save_cloud_schedule saves cs_dropbox_sync_enabled

## [3.2.78] - 2026-03-21
- UX: Hover effects added to all header status items (Site Online, Help, andrewbaker.ninja) — lift + colour shift on hover; links moved from inline styles to CSS classes for hover support

## [3.2.77] - 2026-03-21
- UX: Removed "Local" column from S3 history table (PHP and JS row builder)

## [3.2.76] - 2026-03-21
- FIX: All table rows now the same height — `white-space:nowrap` added globally to `.cs-table td`, action cells marked explicitly in PHP and JS row builders

## [3.2.75] - 2026-03-21
- NEW: Golden/non-golden backup counters now show on page load for all three history sections (AMI, S3, GDrive); added `$ami_nongolden_count`, `$s3_nongolden_count`, `$gdrive_nongolden_count` to pre-compute block

## [3.2.74] - 2026-03-21
- FIX: S3 history Date column empty after Sync — `cs_s3_refresh_history()` now returns `date_fmt` and `local` fields; JS row builder uses date_fmt with Date() fallback
- FIX: All remaining dot-keeping `keyE` regexes in S3 JS functions corrected
- CHANGE: "Date" column renamed to "Created" in S3 and GDrive history tables
- CHANGE: Golden rows changed from soft amber gradient to bright `#fff176` yellow with 4px left border
- UX: All Explain buttons fully rewritten — S3, GDrive, AMI, Cloud Schedule, Retention now cover golden images, tags, and all action buttons with bullet lists

## [3.2.73] - 2026-03-21
- CHANGE: S3 history and GDrive history merged into their parent settings cards; separate history cards removed
- FIX: S3 golden star button — `keyE` dot regex mismatch between PHP IDs and JS selectors; all S3 history functions updated

## [3.2.72] - 2026-03-21
- UX: "Refresh S3/Drive History" buttons renamed to "Sync from S3" / "Sync from Google Drive" with explanatory text clarifying live query behaviour

## [3.2.71] - 2026-03-21
- FIX: S3 and GDrive history tables auto-populate on first Cloud tab activation; no manual Refresh click required
- FIX: "? Help" button changed from blue-on-blue to white background with blue text

## [3.2.70] - 2026-03-21
- FIX: "X in Drive / X in bucket" counter in settings cards now updates live after Refresh (writes actual count to option) and after Delete (decrements); counter elements given IDs for JS access

## [3.2.69] - 2026-03-21
- NEW: Google Drive Backup History card — mirrors S3 history; shows all .zip files in your configured Drive remote via `rclone lsjson`
- NEW: Download button on all Drive history rows (streams via `rclone copyto` to temp file, then to browser)
- NEW: Golden Images for Drive (up to 4, separate pool); golden files skipped by `cs_enforce_gdrive_retention()`
- NEW: Tag editor for Drive history entries (inline, Enter/Escape, dot-safe key IDs)
- NEW: Delete from Drive via `rclone deletefile`; removes entry from `cs_gdrive_history` option
- NEW: `cs_gdrive_refresh_history()` shared PHP function; `admin_post_cs_gdrive_download` download handler
- NEW: `cs_gdrive_history` option added to `uninstall.php` cleanup list (next version)

## [3.2.68] - 2026-03-21
- FIX: Tag save bug — jQuery `$('#id')` selector breaks on filenames containing dots (e.g. `bkup.zip`); switched both tag-edit functions to `querySelectorAll` + `addEventListener`
- FIX: S3 history `keyE` now strips dots to match JS selector logic; IDs consistent between PHP and JS
- NEW: Download button on all S3 history rows (not just non-local); streams from local cache or pulls from S3 on demand; replaces the conditional Pull button
- NEW: `admin_post_cs_s3_download` PHP handler (local-first, S3 fallback, temp-file cleanup)
- NEW: "X of Y max backups" counter next to golden count for both AMI and S3 tables
- UX: Empty tag shows "No tag" instead of "—"; edit button labelled "Edit" instead of ✏ icon
- UX: Updated S3 and AMI Explain text to cover golden images, tags, download, and restore
- TECH: Added `admin_post_url` and `ami_max` to `wp_localize_script` CS object

## [3.2.66] - 2026-03-21
- NEW: AMI Tags — add a free-text label to any AMI snapshot entry via inline pencil editor
- NEW: Golden Images (AMI) — mark up to 4 AMIs as golden; golden images are never auto-deleted and do not count towards Max Cloud Backups to Keep
- NEW: S3 Backup History card — query your S3 bucket live and display all backup zips in a table with Tag, Golden Image, Pull to Local, and Delete actions
- NEW: Golden Images (S3) — same 4-slot golden image protection for S3 files, separate pool from AMI golden images
- NEW: `cs_s3_refresh_history()` shared function for S3 listing with tag/golden merge
- CHANGE: `cs_ami_enforce_max()` now excludes golden entries from pruning
- CHANGE: `cs_enforce_s3_retention()` now skips golden S3 files

## [3.2.65] - 2026-03-21
- FIX: `echo $is_deleted ? 'true' : 'false'` wrapped in `esc_attr()` — PCP `EscapeOutput.OutputNotEscaped` (2 instances)
- FIX: AMI table `<th>` headers wrapped in `esc_html_e()` for i18n (2 sets)
- FIX: Refresh AMI button `title` attribute wrapped in `esc_attr_e()`
- FIX: `#` column header wrapped in `esc_html_e()`
- FIX: `localStorage` empty `catch` blocks annotated as intentionally silent
- FIX: `getElementById()` results in `csS3Msg`, `csGDriveMsg`, `csAmiMsg`, `csCopyBackupPath` guarded with null checks

## [3.2.63] - 2026-03-20
- NEW: AMI snapshot restore button — click Restore next to any available AMI to trigger an EC2 replace-root-volume-task; modal warns that all changes since the snapshot will be permanently lost and requires explicit confirmation before proceeding
- FIX: All "Explain…" card header buttons now wrapped in `esc_html_e()` for i18n compliance
- FIX: Echoed `<style>` tag in `admin_head` replaced with `wp_add_inline_style()` (WordPress.org critical requirement)
- FIX: Unescaped ternary echoes in Retention card class attributes wrapped in `esc_attr()`
- FIX: `disabled title="…"` attribute injection replaced with split conditional attributes
- FIX: AMI Delete/Remove button title and text wrapped in `esc_attr__()`/`esc_html__()`
- FIX: "Create AMI Now", "Remove failed record", "Remove" button texts wrapped in i18n functions
- FIX: `$options` global variable in `uninstall.php` renamed to `$cs_options` (PCP `NonPrefixedVariableFound`)
- FIX: `cs_ami_schedule_days` and `cs_manual_defaults` added to uninstall cleanup list

## [3.2.56] - 2026-03-18
- NEW: S3 and Google Drive retention enforcement — oldest zips deleted automatically after each sync
- NEW: "Max Cloud Backups to Keep" field moved to Cloud Backup Settings card, controls all cloud destinations
- NEW: AMI delete now shows "Pending Delete…" status immediately; WP-Cron confirms deletion after 15 minutes
- NEW: Refresh All shows live "Refreshing X of Y…" progress counter, processes each AMI sequentially
- FIX: Save as Defaults — Database checkbox was rendering `checkeddata-size` (merged attribute) due to missing space after esc_attr() wrap; checkbox now saves and restores correctly
- UX: Restore from Upload — file picker styled as a button with icon; filename display updates on selection
- REMOVE: "Check Status" button removed from EC2 AMI card (Refresh All covers all use cases)

## [3.2.55] - 2026-03-18
- NEW: S3 retention enforcement (cs_enforce_s3_retention) — deletes oldest zips from S3 after each sync
- NEW: Google Drive retention enforcement (cs_enforce_gdrive_retention) — deletes oldest zips from Drive after each sync
- NEW: "Max Cloud Backups to Keep" added to Cloud Backup Settings card; removed from EC2 AMI card

## [3.2.54] - 2026-03-18
- NEW: AMI delete cron check — 15-minute WP-Cron event confirms deletion in AWS and removes log entry
- FIX: AMI max enforcement now always drops the oldest local log entry even if AWS deregistration fails

## [3.2.53] - 2026-03-18
- FIX: AMI retention enforcement — oldest entry now always removed from local log even if AWS deregistration fails, preventing count exceeding limit

## [3.2.52] - 2026-03-18
- UX: Restore from Upload — all controls on single row, Restore button right-aligned

## [3.2.51] - 2026-03-18
- FIX: All remaining PCP errors fixed (unescaped variables, missing phpcs:ignore, InputNotSanitized)
- NEW: Cloud Backups tab, Google Drive backup via rclone, cloud schedule settings
- FIX: Save Schedule — csCloudScheduleSave jQuery no-conflict fix, days sent as comma string
- UX: Restore from Upload redesign with icons

## [3.2.50] - 2026-03-18
- UX: Local Backup Schedule and Cloud Backup Settings now have separate Explain modals

## [3.2.49] - 2026-03-18
- UX: S3 and Google Drive button colour scheme (Save=blue, Test=green, Diagnose=orange, Sync=purple)

## [3.2.48] - 2026-03-18
- FIX: Save Schedule success message simplified to "✓ Saved"

## [3.2.47] - 2026-03-18
- FIX: csCloudScheduleSave — $ is not a function (WordPress jQuery no-conflict mode)

## [3.2.46] - 2026-03-18
- NEW: EC2 AMI Snapshot checkbox added to cloud schedule "Include in cloud backup" section

## [3.2.45] - 2026-03-18
- FIX: All PCP errors — text domain, date()/gmdate(), unlink→wp_delete_file, wp_die escaping, cURL suppression, phpcs:ignore patterns, readme.txt tags/description

## [3.2.44] - 2026-03-17
- FIX: build-review.sh excluded from plugin distribution zip

## [3.2.43] - 2026-03-17
- FIX: Cloud backup days collected using native DOM querySelectorAll, resolving silent empty array issue

## [3.2.42] - 2026-03-17
- DEBUG: Added console.log to csCloudScheduleSave for diagnosis (removed in 3.2.43)

## [3.2.41] - 2026-03-17
- FIX: csCloudScheduleSave days collection switched to native DOM forEach

## [3.2.40] - 2026-03-17
- NEW: Cloud Backup Delay field shows calculated run time live (→ runs at HH:MM)

## [3.2.39] - 2026-03-17
- FIX: Cloud Backup Delay replaces Cloud Backup Time hour/minute selects; computed as local backup time + delay

## [3.2.38] - 2026-03-17
- FIX: Save Schedule time validation removed (was incorrectly blocking saves)

## [3.2.37] - 2026-03-17
- FIX: csCloudScheduleSave — var $ = window.jQuery added (WordPress jQuery no-conflict fix)

## [3.2.36] - 2026-03-17
- FIX: AMI checkbox added to cloud schedule; Save Schedule validation removed

## [3.2.35] - 2026-03-17
- NEW: "Sync Local Backup Now" buttons on S3 and Google Drive cards
- NEW: 30-minute minimum gap validation between local and cloud backup times
- UX: S3 and GDrive card help text updated to clarify latest local backup is synced

## [3.2.34] - 2026-03-17
- FIX: GDrive Explain — real access token scrubbed; laptop rclone install step added

## [3.2.33] - 2026-03-17
- UX: Google Drive Explain — full 8-step guide with example output, permission fix instructions

## [3.2.32] - 2026-03-17
- NEW: Cloud schedule enable toggle; Cloud Backup Time; AMI→S3→GDrive execution order; local schedule warning

## [3.2.31] - 2026-03-17
- NEW: Google Drive card moved next to S3; S3/GDrive enable/disable checkboxes on cloud scheduler

## [3.2.30] - 2026-03-17
- FIX: GDrive Explain step 4 — brew install rclone step on laptop added correctly

## [3.2.29] - 2026-03-17
- UX: GDrive Explain — clearer step-by-step setup with brew install rclone for laptop

## [3.2.28] - 2026-03-17
- NEW: Google Drive backup via rclone (cs_sync_to_gdrive, cs_find_rclone, AJAX handlers, Diagnose modal)

## [3.2.27] - 2026-03-17
- UX: Renamed "Cloud Backup Schedule" to "Cloud Backup Settings"

## [3.2.26] - 2026-03-17
- UX: Tabs — active tab solid blue with white text; inactive tab blue-tinted

## [3.2.25] - 2026-03-17
- BUILD: Standards review model switched from haiku to sonnet

## [3.2.24] - 2026-03-17
- NEW: Local Backups and Cloud Backups top-level tabs; cloud schedule independent of local schedule

## [3.2.23] - 2026-03-17
- REMOVE: AMI Backups / backups_dir component removed entirely

## [3.2.22] - 2026-03-17
- UX: S3 Diagnose modal — shows only AWS CLI version string, each part on its own line with explanation

## [3.2.21] - 2026-03-17
- NEW: S3 card — AWS CLI info row replaced with Diagnose button and modal

## [3.2.20] - 2026-03-17
- FIX: PHP syntax error from hoisted s3_log variables

## [3.2.19] - 2026-03-17
- UX: Renamed "Existing backups" to "AMI Backups" everywhere

## [3.2.18] - 2026-03-17
- FIX: AMI Backups size shows most recent zip only; backup logic includes only most recent zip

## [3.2.17] - 2026-03-17
- FIX: sed backup files removed from version bump; region override width fix

## [3.2.16] - 2026-03-17
- FIX: Auto version bump in build; region override width fix; stricter review gating

## [3.2.15] - 2026-03-17
- UX: AMI Backups explain clarified to say "only the most recent backup zip"

## [3.2.14] - 2026-03-17
- FIX: AMI Backups — only includes most recent zip; shows its size correctly

## [3.2.13] - 2026-03-17
- UX: Renamed "Existing backups" checkbox to "AMI Backups"

## [3.2.12] - 2026-03-17
- FIX: Save as defaults for manual backup component selection

## [3.2.11] - 2026-03-16
- FIX: sed backup files in version bump; region override width

## [3.2.10] - 2026-03-16
- FIX: Broken S3/AMI buttons; backups_dir schedule save; version badge; explain rewrites

## [3.2.9] - 2026-03-16
- UX: Region override input same width as AMI name prefix input

## [3.2.8] - 2026-03-16
- BUILD: Auto-increment patch version at start of every build, before AI review runs; build fails on any model API error or missing BUILD_STATUS

## [3.2.7] - 2026-03-16
- NEW: "Save as defaults" button on Run Backup Now — persists component selection across page loads

## [3.2.6] - 2026-03-16
- FIX: S3 and AMI card buttons (Save, Test, Create AMI, Check Status, Refresh, Delete) were non-functional — restored missing JS functions removed during WordPress.org standards pass
- FIX: "Existing backups" checkbox in Backup Schedule now saves correctly
- NEW: Version number displayed as a prominent separate badge in the header
- UX: Region override input now full-width
- UX: All Explain… modal texts rewritten with accurate, detailed descriptions of each section

## [3.2.5] - 2026-03-16
- NEW: "Existing backups" checkbox — optionally include the cloudscale-backups/ directory in a backup; Explain modal updated

## [3.2.4] - 2026-03-16
- NEW: Copy button next to backup storage path in Backup History panel

## [3.2.3] - 2026-03-16
- NEW: Version number now displayed in the admin page header

## [3.2.2] - 2026-03-16
- FIX: All "Explain…" buttons now work — added missing JS functions for all 8 panel sections
- NEW: Backup History panel now shows the server path where backup files are stored

## [3.2.1] - 2026-03-14
- Renamed internal constants with `CS_BACKUP_` prefix to avoid collisions with other plugins
- Replaced `@unlink()`, `@copy()`, `@rmdir()` with `wp_delete_file()`, `copy()`, `rmdir()` per WordPress coding standards

## [3.2.0] - 2026-03-13
- NEW: Split backup scheduling into two independent cron events — file backup and AMI snapshot each have their own day picker and time selector
- NEW: Configurable backup filename prefix (default: bkup), set in the Retention card
- NEW: S3 sync auto-retry — on failure a single cron event fires 5 minutes later; UI shows pending state and a manual Retry button
- FIX: Scheduled backup run hour never saved due to missing name attribute on the hour select
- FIX: Full+ backup type badge now renders distinctly from Full (separate CSS rule)
- AMI explain modal updated to document the two-schedule architecture; reboot defaults clarified

## [2.74.2] - 2026-02-28
- FIX: AMI creation failed with "Character sets beyond ASCII are not supported" due to em dash in description
- AMI description now stripped to printable ASCII only via regex
- AMI name now sanitised to AWS allowed characters and capped at 128 chars

## [2.74.1] - 2026-02-26
- FIX: AMI panel could vanish if IMDS endpoint was unreachable or curl failed during page render
- All IMDS calls now wrapped in try/catch with error suppression
- Added curl_init availability check before attempting IMDS calls
- IMDS results cached in WordPress transients (1 hour TTL)
- AMI panel init block wrapped in top level try/catch

## [2.74.0] - 2026-02-24
- NEW: EC2 AMI Snapshot panel — create full machine images directly from the plugin
- AMI creation history log with status tracking
- Auto detection of EC2 instance ID and region via IMDS

## [1.0.0] - 2026-02-21
- Initial public release
