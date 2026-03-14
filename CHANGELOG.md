# Changelog

## 3.2.1
- Renamed internal constants with `CS_BACKUP_` prefix to avoid collisions with other plugins
- Replaced `@unlink()`, `@copy()`, `@rmdir()` with `wp_delete_file()`, `copy()`, `rmdir()` per WordPress coding standards

## 3.2.0
- NEW: Split backup scheduling into two independent cron events — file backup and AMI snapshot each have their own day picker and time selector
- NEW: Configurable backup filename prefix (default: bkup), set in the Retention card
- NEW: S3 sync auto-retry — on failure a single cron event fires 5 minutes later; UI shows pending state and a manual Retry button
- FIX: Scheduled backup run hour never saved due to missing name attribute on the hour select
- FIX: Full+ backup type badge now renders distinctly from Full (separate CSS rule)
- AMI explain modal updated to document the two-schedule architecture; reboot defaults clarified

## 2.74.2
- FIX: AMI creation failed with "Character sets beyond ASCII are not supported" due to em dash in description
- AMI description now stripped to printable ASCII only via regex
- AMI name now sanitised to AWS allowed characters and capped at 128 chars

## 2.74.1
- FIX: AMI panel could vanish if IMDS endpoint was unreachable or curl failed during page render
- All IMDS calls now wrapped in try/catch with error suppression
- Added curl_init availability check before attempting IMDS calls
- IMDS results cached in WordPress transients (1 hour TTL)
- AMI panel init block wrapped in top level try/catch

## 2.74.0
- NEW: EC2 AMI Snapshot panel — create full machine images directly from the plugin
- AMI creation history log with status tracking
- Auto detection of EC2 instance ID and region via IMDS

## 1.0.0
- Initial public release
