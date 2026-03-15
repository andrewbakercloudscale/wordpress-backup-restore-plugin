# WordPress Backup and Restore Plugin

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple) ![License](https://img.shields.io/badge/License-GPLv2-green) ![Version](https://img.shields.io/badge/Version-2.76.0-orange)

No nonsense WordPress backup and restore. Backs up your database, media, plugins, and themes into a single zip file. Scheduled or manual, with safe restore and maintenance mode. Completely free.

No subscriptions. No cloud storage dependencies. No accounts. No upsells.

> Full write up with screenshots: [CloudScale Free Backup and Restore](https://andrewbaker.ninja/2026/02/24/cloudscale-free-backup-and-restore-a-wordpress-backup-plugin-that-does-exactly-what-it-says/)

## What It Backs Up

### Core

- WordPress database (all posts, pages, settings, users, comments)
- Media uploads (`wp-content/uploads`)
- Plugins folder (`wp-content/plugins`)
- Themes folder (`wp-content/themes`)

### Other (shown only if present on your server)

- Must use plugins (`wp-content/mu-plugins`)
- Languages and translation files (`wp-content/languages`)
- wp-content dropin files (`object-cache.php`, `db.php`, `advanced-cache.php`)
- `.htaccess` (Apache rewrite rules and custom security directives)
- `wp-config.php` (optional, unchecked by default, flagged with a warning)

Each backup is a single zip containing a `database.sql` dump, the selected folders, and a `backup-meta.json` recording plugin version, WordPress version, site URL, table prefix, and what was included.

## How It Works

### Database Dump

The plugin detects whether `mysqldump` is available on your server. If it is, it uses native `mysqldump` for speed and reliability. If not (common on shared hosting), it falls back to a pure PHP implementation that streams through every table and writes compatible SQL.

### File Backup

Files are added using PHP ZipArchive extension. The plugin walks each selected directory recursively and streams directly in PHP with no `exec()` calls and no timeout risk.

### Backup Location

Backups are stored in `wp-content/cloudscale-backups/`. On first run the plugin creates this directory with an `.htaccess` file containing `Deny from all` to prevent direct web access. A direct URL to a backup zip returns a 403.

### Scheduled Backups

Uses WordPress Cron (`wp_cron`). Pick which days and what hour (server time), and the plugin registers a recurring event. On low traffic sites the backup may run a few minutes after the scheduled time. For exact timing, add a real server cron job that hits `wp-cron.php` directly.

### Retention System

Controls how many backups to keep (default: 8, range: 1 to 9,999). After each backup, the plugin deletes the oldest files beyond your limit. A live storage estimate with traffic light indicator (green, amber, red) shows whether your disk space is comfortable, getting tight, or at risk.

## Requirements

- WordPress 6.0 or higher
- PHP 8.1 or higher
- ZipArchive PHP extension (available on virtually every PHP installation)

## Installation

1. Download the latest release zip from the [Releases](../../releases) page
2. In WordPress admin go to **Plugins > Add New > Upload Plugin**
3. Upload the zip file, click **Install Now**, then **Activate Plugin**
4. Go to **Tools > CloudScale Backup and Restore**

No API keys. No account creation. No configuration wizard.

### Upgrading

Deactivate > Delete > Upload zip > Activate.

## Setup

### 1. Set Your Schedule

Under the Backup Schedule card, enable automatic backups and tick the days you want. Default is Monday, Wednesday, and Friday at 03:00 server time. Click **Save Schedule**.

### 2. Set Your Retention

Under Retention and Storage, decide how many backups to keep. Watch the storage estimate. A reasonable starting point is 10 backups: nearly two weeks of daily coverage or about a month of Mon/Wed/Fri.

### 3. Run Your First Backup

Under Manual Backup, tick the components you want and click **Run Backup Now**. On most sites the first full backup takes 30 seconds to a few minutes depending on media library size.

## Restoring a Backup

The Backup History card lists all backups with filename, size, age, and type. Each backup has **Download** and **Restore** actions.

**Download** streams the zip to your browser for offsite storage or migration to a new server.

**Restore** unpacks the zip in place. The database is restored from the SQL dump (using native `mysql` CLI if available, otherwise PHP). File directories are extracted to their original locations. The plugin reads `backup-meta.json` to verify compatibility before proceeding.

## What It Deliberately Does Not Do

**No remote storage.** Backups are stored locally. If your server dies, your backups die with it. Periodically download backups to your own machine or push them to S3, Backblaze, or wherever.

**No multisite support.** Designed for standard single site WordPress installations.

**No incremental backups.** Every backup is a full backup. This keeps the code simple and the restore process reliable.

## A Note on wp-config.php

The plugin can optionally back up `wp-config.php`, but it is unchecked by default and flagged with a warning. This file contains database credentials and secret keys.

Include it in occasional deliberate full disaster recovery backups that you keep securely. Leave it unchecked in daily automated backups.

## License

GPLv2 or later. See [LICENSE](LICENSE) for the full text.

## Author

[Andrew Baker](https://andrewbaker.ninja/) - CIO at Capitec Bank, South Africa.
