# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
