'use strict';
const helpLib = require('REPO_BASE/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Backup & Restore — Free WordPress Backup Plugin with One-Click Restore &amp; Cloud Sync',
    pluginDesc: 'The only WordPress backup plugin that is 100% free — including full site restore. UpdraftPlus, BackupBuddy, and Duplicator all charge $70–$200/year the moment you need to actually recover your site. CloudScale Backup & Restore gives you automatic scheduled backups (hourly, daily, weekly), one-click full site restore, database-only backups, and cloud sync to Amazon S3, Google Drive, Dropbox, and Microsoft OneDrive. Supports AWS EC2 AMI snapshots for server-level disaster recovery. Takes a pre-update snapshot before plugin or theme updates. Email alerts on success or failure. Restore to a different domain for migrations. Exclude files or directories. Completely free, open source, no subscription, no premium tier, no upsells.',
    seoTitle:   'CloudScale Backup & Restore | Free WordPress Backup Plugin — S3, Google Drive, OneDrive',
    seoDesc:    'Free WordPress backup with one-click restore, scheduled backups, Amazon S3, Google Drive, Dropbox & OneDrive sync, AMI snapshots, pre-update backups. Beats UpdraftPlus & BackupBuddy — no paid tier.',
    schema: {
        '@context': 'https://schema.org',
        '@type': 'SoftwareApplication',
        name: 'CloudScale Backup & Restore',
        operatingSystem: 'WordPress',
        applicationCategory: 'WebApplication',
        offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
        softwareVersion: '3.2.420',
        downloadUrl: 'https://your-s3-bucket.s3.af-south-1.amazonaws.com/cloudscale-backup.zip',
        url: 'https://github.com/andrewbakercloudscale/wordpress-backup-restore-plugin',
    },
    pageTitle:  'CloudScale Backup & Restore — Free WordPress Backup Plugin with One-Click Restore, S3 & Cloud Sync',
    pageSlug:   'backup-restore-help',
    downloadUrl: 'https://your-s3-bucket.s3.af-south-1.amazonaws.com/cloudscale-backup.zip',
    repoUrl:     'https://github.com/andrewbakercloudscale/wordpress-backup-restore-plugin',
    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-backup`,

    pluginFile: `${__dirname}/../cloudscale-backup.php`,
    logoFile:   `${__dirname}/../CloudScale.png`,

    sections: [
        {
            id: 'activity-log', label: 'Activity Log', file: 'panel-activity-log.png', tab: 'local',
            elementSelector: '#cs-log-panel',
        },
        { id: 'schedule',       label: 'Backup Schedule',           file: 'panel-schedule.png',       tab: 'local', elementSelector: '#cs-tab-local .cs-card--blue'    },
        { id: 'notifications',  label: 'Notifications',              file: 'panel-notifications.png',  tab: 'local', elementSelector: '#cs-notifications-card'           },
        { id: 'retention',      label: 'Retention & Storage',        file: 'panel-retention.png',      tab: 'local', elementSelector: '.cs-card--green'                 },
        { id: 'system-info',    label: 'System Info',                file: 'panel-system-info.png',    tab: 'local', elementSelector: '.cs-card--purple'                },
        { id: 'create-backup',  label: 'Create Local Backup Now',    file: 'panel-create-backup.png',  tab: 'local', elementSelector: '.cs-card--orange'                },
        { id: 'backup-list',    label: 'Local Backup History',       file: 'panel-backup-list.png',    tab: 'local', elementSelector: '#cs-tab-local .cs-card--teal', trimRows: true },
        { id: 'restore-upload', label: 'Restore from Uploaded File', file: 'panel-restore-upload.png', tab: 'local', elementSelector: '.cs-card--red'                   },
        {
            id: 'restore', label: 'Restore', file: 'panel-restore.png', tab: 'local',
            elementSelector: '#cs-restore-modal .cs-modal-box',
            jsBeforeShot: () => {
                const m = document.getElementById('cs-restore-modal');
                const o = document.getElementById('cs-modal-overlay');
                const fn = document.getElementById('cs-modal-filename');
                const dt = document.getElementById('cs-modal-date');
                if (m) { m.style.display = 'flex'; m.style.position = 'relative'; m.style.zIndex = '1'; }
                if (o) o.style.display = 'none';
                if (fn) fn.textContent = 'mysite_f12.zip';
                if (dt) dt.textContent = '22 Mar 2026 14:30';
            },
            jsAfterShot: () => {
                const m = document.getElementById('cs-restore-modal');
                const o = document.getElementById('cs-modal-overlay');
                if (m) { m.style.display = 'none'; m.style.position = ''; m.style.zIndex = ''; }
                if (o) o.style.display = 'none';
            },
        },
        { id: 'cloud-schedule', label: 'Cloud Backup Settings',      file: 'panel-cloud-schedule.png', tab: 'cloud', elementSelector: '#cs-tab-cloud .cs-card--blue'   },
        { id: 'google-drive',   label: 'Google Drive',               file: 'panel-gdrive.png',         tab: 'cloud', elementSelector: '.cs-card--gdrive'                },
        { id: 'dropbox',        label: 'Dropbox',                    file: 'panel-dropbox.png',        tab: 'cloud', elementSelector: '.cs-card--dropbox'               },
        { id: 'onedrive',       label: 'Microsoft OneDrive',         file: 'panel-onedrive.png',       tab: 'cloud', elementSelector: '.cs-card--onedrive'              },
        { id: 's3',             label: 'AWS S3',                     file: 'panel-s3.png',             tab: 'cloud', elementSelector: '.cs-card--pink'                  },
        { id: 'ami',            label: 'AWS EC2 AMI Snapshot',       file: 'panel-ami.png',            tab: 'cloud', elementSelector: '.cs-card--indigo'                },
        { id: 'plugin-auto-recovery', label: 'Automatic Crash Recovery', file: 'panel-plugin-auto-recovery.png', tab: 'autorecovery', elementSelector: '#cs-tab-autorecovery .cs-card--blue' },
        {
            id: 'cloud-history', label: 'Cloud Backup History', file: 'panel-cloud-history.png', tab: 'cloud',
            elementSelector: '#cs-history-panel',
            jsBeforeShot: () => {
                // Show S3 pane so the table is visible in the screenshot
                const s3 = document.getElementById('cs-hist-pane-s3');
                if (s3) s3.style.display = '';
                const acts = document.querySelectorAll('.cs-hist-act');
                acts.forEach(a => { a.style.display = 'none'; });
                const s3act = document.getElementById('cs-hist-act-s3');
                if (s3act) s3act.style.display = 'flex';
            },
        },
    ],

    docs: {
        'activity-log': `
<p>The <strong>Activity Log</strong> panel appears at the top of the plugin admin page, above the Local Backups and Cloud Backups tabs. It gives you a real-time, timestamped record of everything the plugin does — useful for verifying that scheduled jobs ran, diagnosing sync failures, or copying a log dump for support.</p>
<p><strong>What gets logged:</strong></p>
<ul>
<li>Backup created — filename and size (e.g. <code>bkup_f42.zip (18.3 MB)</code>).</li>
<li>Scheduled backup starting — components included (database, media, plugins, etc.).</li>
<li>Cloud sync result per provider — S3, Google Drive, Dropbox, and OneDrive each log success or failure immediately after a backup.</li>
<li>Manual cloud sync — when you click Sync Latest for any provider.</li>
<li>Cloud connection tests — pass or fail result for S3, Google Drive, Dropbox, and OneDrive.</li>
<li>Settings saved — local schedule, retention, S3, Google Drive, Dropbox, OneDrive, AMI, and cloud schedule settings all log when saved.</li>
<li>AMI creation started — AMI ID, name, and instance ID logged on success; error details logged on failure.</li>
<li>AMI state transitions (<em>pending</em> → <em>available</em>) detected during background polling.</li>
<li>Retention deletions — each local backup deleted by the retention policy is logged with the retention limit.</li>
<li>Selective restore initiated and completed — filename and table list logged at start and finish.</li>
<li>Full restore and restore-from-upload — start and completion (or failure) with filename.</li>
<li>Backup downloaded — logged when an admin downloads a backup zip.</li>
<li>Backup deleted — filename logged when an admin deletes a backup.</li>
<li>Automatic Crash Recovery events — pre-update backup, monitoring start, rollback triggered (with trigger reason and HTTP code), rollback complete.</li>
<li>Plugin activated and deactivated.</li>
<li>Errors and exceptions — all caught exceptions log the message and context before returning an error to the UI.</li>
</ul>
<p><strong>Entry colours:</strong></p>
<ul>
<li><span style="background:#1e272e;color:#a5d6a7;padding:1px 6px;border-radius:3px;font-family:monospace;font-size:.9em;">Green</span> — success: backup completed, sync OK, deletion confirmed.</li>
<li><span style="background:#1e272e;color:#80cbc4;padding:1px 6px;border-radius:3px;font-family:monospace;font-size:.9em;">Teal</span> — in-progress: starting, running, retrying.</li>
<li><span style="background:#1e272e;color:#ef9a9a;padding:1px 6px;border-radius:3px;font-family:monospace;font-size:.9em;">Red</span> — error: failed, skipped, aborted, access denied.</li>
<li><span style="background:#1e272e;color:#9e9e9e;padding:1px 6px;border-radius:3px;font-family:monospace;font-size:.9em;">Grey</span> — informational: all other events.</li>
</ul>
<p><strong>Controls:</strong></p>
<ul>
<li><strong>Copy</strong> — copies all visible log lines (with timestamps) to the clipboard as plain text. Paste directly into a support ticket or bug report.</li>
<li><strong>Refresh</strong> — manually reloads the log. The panel also auto-refreshes every 5 seconds while the page is open.</li>
<li><strong>Clear</strong> — permanently erases all log entries from the database. Cannot be undone.</li>
</ul>
<p>The log displays up to 100 entries and auto-scrolls to the newest entry on each refresh. Entries are stored in <code>wp_options</code> (key <code>cs_activity_log</code>). Background sync workers write temporary per-job entries (keys like <code>cs_log_*</code>) which are merged into the main log on the next load.</p>`,

        'schedule': `
<p>The <strong>Backup Schedule</strong> card (blue) configures automatic, unattended backups. Enable the checkbox at the top to activate the schedule — all controls are disabled when the schedule is off.</p>
<p><strong>File backup days</strong> — select one or more days of the week on which the scheduled backup will run. The backup fires once per day at the configured time on each selected day.</p>
<p><strong>Include in scheduled backup</strong> — choose which components are included each time the scheduled backup runs:</p>
<ul>
<li><strong>Database</strong> — full SQL dump of all WordPress tables (always recommended).</li>
<li><strong>Media uploads</strong> — <code>wp-content/uploads/</code> including all image sizes.</li>
<li><strong>Plugins</strong> — <code>wp-content/plugins/</code> (all installed plugins).</li>
<li><strong>Themes</strong> — <code>wp-content/themes/</code> (all theme directories).</li>
<li><strong>Must-use plugins</strong> — <code>wp-content/mu-plugins/</code>.</li>
<li><strong>Languages</strong> — <code>wp-content/languages/</code>.</li>
<li><strong>Dropins</strong> — drop-in files such as <code>object-cache.php</code> in <code>wp-content/</code>.</li>
<li><strong>.htaccess</strong> — the root <code>.htaccess</code> rewrite rules file.</li>
<li><strong>wp-config.php</strong> — contains database credentials and secret keys. Marked with a credentials warning badge. Include only if you intentionally want config backed up; do not restore this file to a different server without editing the credentials first.</li>
</ul>
<p>Manual backups always let you choose components individually at run time, regardless of what is saved here.</p>
<p><strong>File backup time</strong> — the hour and minute (server time) at which the scheduled backup fires. The current server time and timezone are shown inline. The next scheduled run is displayed below the time picker once a schedule is saved.</p>
<p><strong>Run Table Repairs automatically</strong> — when enabled, the plugin runs <code>OPTIMIZE TABLE</code> on any InnoDB tables that have accumulated overhead immediately after each scheduled backup completes. This is equivalent to clicking <em>Optimize tables</em> in phpMyAdmin and can recover disk space from deleted posts, spam comments, and transient accumulation. It has no effect on tables that do not need it.</p>
<p><strong>Show Backup Now button in admin toolbar</strong> — adds a one-click backup button to the top WordPress admin bar on every admin page. Clicking it runs an immediate backup using the default component selection and shows a live progress indicator in the toolbar.</p>
<p><strong>Save Schedule</strong> — saves the schedule configuration and registers or updates the WordPress cron event. For production sites, supplement WP-Cron with a real system cron to ensure the schedule fires regardless of traffic: <code>* * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron &gt; /dev/null</code></p>
<p>Email, SMS, and push notification settings are configured in the <strong>Notifications card</strong> (purple, directly below this card). Enable the relevant channels there to be alerted after each backup or restore completes.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Backup Encryption</p>
<p>The <strong>Encrypt backups (AES-256)</strong> checkbox password-protects every backup zip with AES-256 encryption. When enabled, a <strong>Password</strong> field appears — enter a strong password and click <strong>Save Schedule</strong>. Every subsequent backup will be encrypted with that password. Encrypted backups show a padlock icon in the Local Backup History table.</p>
<p><strong>Important things to know before enabling encryption:</strong></p>
<ul>
<li>The password is required to restore the backup. If you lose it, the backup cannot be decrypted.</li>
<li>The password is stored in the WordPress database. Anyone with database access can read it. Encryption primarily protects backup zips that leave the server — for example, files downloaded to your computer or synced to cloud storage.</li>
<li>If you change or clear the password, existing encrypted backups cannot be restored using the new password. Keep a record of the password that was active when each backup was created, or run new unencrypted backups before clearing the password.</li>
<li>Encryption requires PHP's <code>libzip</code> to be compiled with AES-256 support (<code>ZipArchive::EM_AES_256</code>). If your server's libzip does not support it, a red error badge appears and the backups are created without encryption. Upgrade libzip (typically via <code>sudo dnf upgrade php-zip</code> on Amazon Linux or <code>sudo apt upgrade php-zip</code> on Ubuntu) to enable this feature.</li>
</ul>`,

        'notifications': `
<p>The <strong>Notifications card</strong> (purple) configures all alerts sent after backup, restore, and plugin rollback events. Three channels are available simultaneously — enable any combination. All settings are saved with a single <strong>Save Notification Settings</strong> button at the bottom of the card.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Email</p>
<p>Enable <strong>Email</strong> to send an email after every backup or restore operation.</p>
<ul>
<li><strong>Send to</strong> — the address that receives notifications. Leave blank to use the WordPress admin email (<code>Settings → General → Administration email address</code>).</li>
<li><strong>Success &amp; failures</strong> — sends an email after every operation regardless of outcome. The most reliable option for unattended monitoring.</li>
<li><strong>Failures only</strong> — silent on success; sends an email only when something goes wrong. Good for set-it-and-forget-it installs where you only want to hear bad news.</li>
<li><strong>Success only</strong> — confirmation after every successful backup. Useful during initial setup to confirm the schedule is firing.</li>
<li><strong>Plugin rollbacks</strong> — sends an email whenever Automatic Crash Recovery rolls back a plugin update.</li>
</ul>
<p><strong>Send Test</strong> — sends a test message immediately to the configured address and reports success or failure inline. If email delivery is not working, a diagnostic banner appears explaining the likely cause:</p>
<ul>
<li><em>All outbound SMTP ports blocked</em> — your hosting provider is blocking ports 587, 465, and 25. WordPress uses the server's local <code>sendmail</code> binary to deliver email, so if those ports are blocked, nothing gets through. Install a plugin like <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank">WP Mail SMTP</a> to route mail through an external provider such as Gmail, SendGrid, or Mailgun.</li>
<li><em>SMTP ports open but no local mail agent</em> — the server can reach mail servers, but no local MTA (Postfix, Sendmail, Exim) is installed. WordPress has no way to hand off the email. Use WP Mail SMTP.</li>
<li><em>Postfix running but no relay configured</em> — Postfix is installed but will attempt direct delivery on port 25, which most ISPs and cloud providers block to prevent spam. Configure Postfix to relay through your email provider, or use WP Mail SMTP.</li>
</ul>
<p>Any <code>wp_mail()</code> failure is also written to the Activity Log so you can see the exact error message without checking server logs.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">SMS via Twilio</p>
<p>Enable <strong>SMS via Twilio</strong> to receive a text message alert. You will need a <a href="https://www.twilio.com" target="_blank">Twilio</a> account — a free trial is available with no credit card required for testing.</p>
<ul>
<li><strong>Account SID</strong> — found on your Twilio console dashboard (starts with <code>AC</code>).</li>
<li><strong>Auth Token</strong> — the secret token shown alongside the Account SID.</li>
<li><strong>From number</strong> — your Twilio phone number in E.164 format (e.g. <code>+12025551234</code>).</li>
<li><strong>To number</strong> — the destination mobile number in E.164 format (e.g. <code>+12025556789</code>).</li>
</ul>
<p><strong>When to send SMS:</strong></p>
<ul>
<li><strong>Backup &amp; restore</strong> — enable to receive SMS after backup and restore operations, with a sub-filter for <em>All results</em>, <em>Failures only</em>, or <em>Successes only</em>.</li>
<li><strong>Plugin rollbacks</strong> — enable to receive an SMS whenever Automatic Crash Recovery rolls back a plugin update.</li>
</ul>
<p><strong>Send Test</strong> — immediately sends a test SMS to verify credentials before saving.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Push via ntfy</p>
<p>Enable <strong>Push via ntfy</strong> for instant push notifications to your phone. <a href="https://ntfy.sh" target="_blank">ntfy.sh</a> is completely free with no account needed — install the ntfy app on your phone, enter a unique topic name here (e.g. <code>my-site-backups-a7x9</code>), and subscribe to that topic in the app. Alerts arrive within seconds.</p>
<ul>
<li><strong>Topic</strong> — a unique string appended to <code>https://ntfy.sh/</code>. Choose something non-guessable to prevent unauthorised subscriptions. The full URL is shown inline as you type.</li>
</ul>
<p><strong>When to send push:</strong></p>
<ul>
<li><strong>Backup &amp; restore</strong> — enable to receive push notifications after backup and restore operations, with a sub-filter for <em>All results</em>, <em>Failures only</em>, or <em>Successes only</em>.</li>
<li><strong>Plugin rollbacks</strong> — enable to receive a push notification whenever Automatic Crash Recovery rolls back a plugin update.</li>
</ul>
<p><strong>Send Test</strong> — immediately sends a test notification to your ntfy topic to verify connectivity before saving.</p>`,

        'retention': `
<p>The <strong>Retention &amp; Storage</strong> card (green) controls how many backups are kept on-server and lets you customise backup filenames.</p>
<p><strong>Backup filename prefix</strong> — a short string prepended to every backup filename, e.g. <code>mysite</code> produces <code>mysite_f12.zip</code>. Lowercase letters, numbers, and hyphens only. Changing this prefix does not affect existing backup files.</p>
<p><strong>Keep last N backups</strong> — when a new backup is created and the on-server count exceeds this limit, the oldest backup is deleted automatically. The storage estimate updates in real time as you type:</p>
<ul>
<li><strong>Estimated storage needed</strong> — the retention count multiplied by the size of the most recent backup. Shown in red when it exceeds available disk space.</li>
<li><strong>Disk free space</strong> — current free space on the partition containing the backup directory. Shown with a green / amber / red traffic-light indicator.</li>
</ul>
<p>The card also shows a live storage breakdown: backup storage used, media uploads folder size, plugins folder size, and themes folder size.</p>
<p>Recommended: keep 3–7 backups on-server and download at least one offsite weekly.</p>`,

        'system-info': `
<p>The <strong>System Info</strong> card (purple) displays read-only diagnostics about your server environment. Use this to confirm the plugin can create backups correctly before relying on automated schedules.</p>
<ul>
<li><strong>Backup method</strong> — <em>mysqldump (binary)</em> when the binary is found on <code>$PATH</code>, or <em>PHP (PDO)</em> as a pure-PHP fallback. <code>mysqldump</code> is faster and more reliable for large databases.</li>
<li><strong>Restore method</strong> — <em>mysql (binary)</em> or PHP PDO fallback.</li>
<li><strong>PHP memory limit</strong> — current <code>memory_limit</code> from <code>php.ini</code>. Large sites may require 256M or more for the zip operation to complete.</li>
<li><strong>Max execution time</strong> — current <code>max_execution_time</code>. Shown as "Unlimited" when set to <code>0</code>. For sites with &gt;1 GB of uploads, set this to at least 300 seconds to prevent timeouts.</li>
<li><strong>Database</strong> — MySQL / MariaDB version string.</li>
<li><strong>Total backups stored</strong> — count of <code>.zip</code> files currently in the backup directory.</li>
<li><strong>Backup directory</strong> — full filesystem path to <code>wp-content/cloudscale-backups/</code>.</li>
<li><strong>Disk free space</strong> — free space with a traffic-light indicator: green (&gt;20% free), amber (10–20%), red (&lt;10%).</li>
<li><strong>Latest backup size</strong> — used as the baseline for the storage estimate in the Retention card.</li>
<li><strong>Percentage free space</strong> — graphical disk bar showing used vs free space.</li>
</ul>`,

        'create-backup': `
<div style="background:#f0f9ff;border-left:4px solid #0e6b8f;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:28px;">
<p style="margin:0 0 10px;font-size:1.3em;font-weight:800;color:#0f172a;">Why CloudScale Free Backup and Restore?</p>
<p style="margin:0 0 10px;">Most WordPress backup plugins lure you in with a free tier — and then lock the restore button behind a paywall. UpdraftPlus Premium costs $70/year. BackupBuddy starts at $80/year. Duplicator Pro is $69/year. All of them charge you at exactly the moment you are most desperate: when your site is broken and you need to get it back.</p>
<p style="margin:0 0 10px;"><strong>CloudScale is free. Not "free with paid restore." Free.</strong> Scheduled or manual backups, one-click restore, maintenance mode, S3 cloud sync, Google Drive sync, Dropbox sync, and EC2 AMI snapshots — all included, no subscription required, source code on GitHub.</p>
<p style="margin:0;">Your backups are stored on your server and can be automatically synced to Amazon S3, Google Drive, or Dropbox so a server failure never means data loss. And if the entire server dies, AMI snapshots let you spin up a replacement in minutes.</p>
</div>
<p>The <strong>Create Local Backup Now</strong> card (orange) lets you run an immediate backup with full control over which components are included. Select components in the two columns, then click <strong>Create Local Backup Now</strong>. Keep the browser tab open until the progress bar completes.</p>
<p><strong>Core components:</strong></p>
<ul>
<li><strong>Database</strong> — complete SQL dump including all tables with the configured <code>$table_prefix</code>.</li>
<li><strong>Media uploads</strong> — <code>wp-content/uploads/</code>, including all generated image sizes.</li>
<li><strong>Plugins</strong> — <code>wp-content/plugins/</code> (all installed plugins, not just active ones).</li>
<li><strong>Themes</strong> — <code>wp-content/themes/</code>.</li>
</ul>
<p><strong>Other components:</strong></p>
<ul>
<li><strong>Must-use plugins</strong> — <code>wp-content/mu-plugins/</code>.</li>
<li><strong>Languages</strong> — <code>wp-content/languages/</code>.</li>
<li><strong>Dropins</strong> — <code>object-cache.php</code> and other drop-ins in <code>wp-content/</code>.</li>
<li><strong>.htaccess</strong> — the root Apache rewrite rules file.</li>
<li><strong>wp-config.php</strong> — contains environment-specific credentials. Do not restore this file to a different server without updating the credentials it contains.</li>
</ul>
<p>Each component shows its current disk size. The <strong>Estimated backup size</strong> and <strong>Disk free space</strong> update in real time as you tick and untick components.</p>
<p><strong>Save as defaults</strong> — saves the current checkbox selection as the default for future manual backups.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Cloud storage pre-check</p>
<p>When you click <strong>Create Local Backup Now</strong>, the plugin first checks whether any configured cloud providers (Google Drive and Dropbox) have enough free space to receive the backup. The button text changes to <em>Checking cloud storage…</em> during this check. AWS S3 does not impose a quota limit so it is not checked.</p>
<p><strong>If all providers have sufficient space</strong> — the backup starts immediately, no action needed.</p>
<p><strong>If a provider is running low</strong> — a yellow warning panel appears below the backup button showing, for each affected provider, the exact MB free vs the estimated MB needed. You then have three options:</p>
<ul>
<li><strong>Free space on [Provider]</strong> — deletes the oldest non-golden backup(s) from that provider one by one until enough space is freed (with a 15% safety margin), then reports the new free space. Golden images are never deleted by this action. Once space is freed, click <strong>Create Local Backup Now</strong> again to proceed, or use <em>Run Backup Anyway</em>.</li>
<li><strong>Run Backup Anyway</strong> — starts the backup regardless. The local backup will be created successfully. If a cloud provider genuinely has no space, the sync step will fail and the failure will be recorded in the Local Backup History table and the Activity Log.</li>
<li><strong>Cancel</strong> — dismisses the warning panel without starting a backup.</li>
</ul>
<p>If the cloud provider cannot be reached during the check (network error, rclone not configured, etc.), the backup starts immediately without waiting.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Disk space alerts</p>
<p>If your server disk is running low, a banner alert appears above the tab bar:</p>
<ul>
<li><strong>Warning (amber)</strong> — disk is at 10–20% free. The banner shows free space and an estimate of how many more backups will fit. Consider reducing the retention count or removing old backups.</li>
<li><strong>Critical (red)</strong> — disk is below 10% free. Free up space or move old backups off-server immediately — the next backup may fail if insufficient space remains.</li>
</ul>`,

        'backup-list': `
<p>The <strong>Local Backups</strong> card (teal) shows every backup zip currently stored on your server.</p>
<ul>
<li><strong>Date / time</strong> — exact timestamp when the backup completed, in your WordPress timezone.</li>
<li><strong>Size</strong> — zip file size. A typical site produces backups of 200 MB–2 GB.</li>
<li><strong>Type</strong> — <em>Manual</em> (triggered by you) or <em>Scheduled</em> (automatic).</li>
<li><strong>Cloud sync columns</strong> — a column appears for each configured provider (S3, Google Drive, Dropbox). A green tick means successfully synced; a red cross means sync failed (hover for the error); a dash means no sync was attempted.</li>
<li><strong>Download</strong> — streams the zip directly to your browser. Always keep at least one backup offsite.</li>
<li><strong>Restore</strong> — opens the restore confirmation modal for that backup.</li>
<li><strong>Delete</strong> — permanently removes the zip. Cannot be undone.</li>
</ul>
<p><strong>Row colours:</strong> The newest backup is highlighted green (<em>latest</em>). A <strong style="color:#b71c1c;">Delete Soon</strong> badge marks the oldest kept backup when you are at your retention limit — it will be deleted when the next backup runs. Rows highlighted orange are already over-limit and will be deleted immediately on the next backup run.</p>
<p>The backup directory path is shown at the bottom with a <strong>Copy</strong> button. Backups are stored in <code>wp-content/cloudscale-backups/</code>.</p>`,

        'restore-upload': `
<p>The <strong>Restore from Uploaded File</strong> card (red) lets you restore your site from a backup file stored on your computer — for example a backup downloaded from a cloud provider or a different server.</p>
<p>Click <strong>Choose File</strong> and select either:</p>
<ul>
<li>A <code>.zip</code> created by this plugin — restores all components included in that backup.</li>
<li>A raw <code>.sql</code> file — restores the database only, leaving files untouched.</li>
</ul>
<p>Click <strong>Restore from Upload</strong>. The file is staged to the server, then the standard restore modal opens — giving you the same two options as a local backup restore:</p>
<ul>
<li><strong>Full database</strong> — drops all current tables and recreates them from the uploaded backup.</li>
<li><strong>Specific tables only</strong> — choose which tables to restore from the uploaded file, leaving all others untouched.</li>
</ul>
<p>This makes it possible to do a selective restore on a backup downloaded from S3, Google Drive, Dropbox, or OneDrive — download the file to your computer, upload it here, then restore just the tables you need.</p>
<p><strong>Cross-server migration:</strong> after restoring a backup from a different domain, run: <code>wp search-replace 'https://oldsite.com' 'https://newsite.com' --all-tables</code></p>`,

        'restore': `
<p>Clicking <strong>Restore</strong> next to any backup opens a unified restore modal. Choose how you want to restore before confirming:</p>
<p><strong>Full database</strong> — restores the entire database. The plugin activates <strong>Maintenance Mode</strong> for the duration, serving HTTP 503 to frontend visitors while wp-admin stays accessible to administrators.</p>
<ol>
<li>Maintenance mode activated — <code>wp-content/.maintenance</code> written to disk.</li>
<li>The backup zip is extracted to a temporary directory inside <code>wp-content/</code>.</li>
<li>The database is replaced: all existing tables matching the backup's table prefix are dropped and recreated from the <code>.sql</code> dump.</li>
<li>The <code>uploads/</code>, <code>plugins/</code>, and active theme directories are replaced (if included in the backup).</li>
<li>Any other included components (<code>.htaccess</code>, <code>wp-config.php</code>, mu-plugins, etc.) are restored.</li>
<li>Maintenance mode deactivated — <code>.maintenance</code> deleted. OPcache flushed.</li>
</ol>
<p><strong>Specific tables only</strong> — restores only the tables you select. The plugin lists every table found in the backup; tick the ones you want. Only those tables are dropped and recreated — all others remain untouched. No maintenance mode is used.</p>
<p>Both modes require you to tick a confirmation checkbox before <strong>Restore Now</strong> activates. Take an EC2 AMI or hosting snapshot first — if anything goes wrong you can roll back instantly.</p>
<p><strong>Important:</strong> a full restore is irreversible. Any changes made after the backup date are permanently lost.</p>
<p><strong>If a full restore fails mid-way</strong> — maintenance mode remains active. Manually delete <code>wp-content/.maintenance</code> via SFTP or SSH to bring the site back online, then inspect <code>wp-content/debug.log</code>.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Which tables to choose for a partial restore</p>
<p>The <em>Specific tables only</em> mode is useful when you only need to recover one piece of the site without reverting everything else. The table names below assume the default <code>wp_</code> prefix — if your install uses a different prefix (e.g. <code>abc_</code>), substitute it accordingly.</p>
<p><strong>Posts, pages, and custom post types</strong></p>
<ul>
<li><code>wp_posts</code> — every post, page, revision, menu item, and custom post type record.</li>
<li><code>wp_postmeta</code> — all post metadata (custom fields, featured image IDs, ACF data, etc.). Always restore this together with <code>wp_posts</code>.</li>
</ul>
<p><strong>Comments</strong></p>
<ul>
<li><code>wp_comments</code> — all comment records including spam, pending, and approved.</li>
<li><code>wp_commentmeta</code> — comment metadata. Always restore together with <code>wp_comments</code>.</li>
</ul>
<p><strong>Users and login data</strong></p>
<ul>
<li><code>wp_users</code> — usernames, email addresses, and hashed passwords.</li>
<li><code>wp_usermeta</code> — user roles, preferences, and metadata. Always restore together with <code>wp_users</code>.</li>
</ul>
<p><strong>Site settings and plugin configuration</strong></p>
<ul>
<li><code>wp_options</code> — site URL, theme settings, plugin configuration, widget data, and transient cache. Restoring this table rolls back <em>all</em> plugin settings to the backup date, not just one plugin. If you only need to recover a single plugin's settings, a full database restore is usually safer.</li>
</ul>
<p><strong>WooCommerce orders and customers</strong></p>
<ul>
<li><code>wp_posts</code> + <code>wp_postmeta</code> — WooCommerce stores legacy orders as a custom post type. Restore both tables.</li>
<li><code>wp_wc_orders</code> + <code>wp_wc_order_items</code> + <code>wp_wc_order_operational_data</code> + <code>wp_wc_order_addresses</code> + <code>wp_wc_order_stats</code> — if HPOS (High-Performance Order Storage) is enabled, WooCommerce uses these dedicated order tables instead. Restore all of them together.</li>
</ul>
<p><strong>Email logs (Contact Form 7, Gravity Forms, Fluent Forms, newsletters)</strong></p>
<p>These plugins store form submissions and email logs in their own tables. Look for tables with the plugin name in them — for example <code>wp_cf7_*</code> for Contact Form 7 add-ons, <code>wp_gf_*</code> for Gravity Forms, <code>wp_fluentform_*</code> for Fluent Forms, or <code>wp_newsletter_*</code> for newsletter plugins. Select all tables belonging to the plugin you want to recover. Because these tables are self-contained, restoring them does not affect posts, users, or any other part of the site.</p>
<p><strong>The safest approach</strong> — before doing any partial restore on a live site, take a fresh backup first. That way if the partial restore causes unexpected problems, you can immediately do a full restore back to the moment before you started.</p>`,

        'cloud-schedule': `
<p>The <strong>Cloud Backup Settings</strong> card (blue) is the master control for all automated cloud syncing. It lives at the top of the <strong>Cloud Backups</strong> tab.</p>
<p><strong>Cloud backup days</strong> — the days on which cloud sync runs. On each selected day the plugin runs providers in this fixed order: AMI snapshot → AWS S3 → Google Drive → Dropbox → OneDrive. Each provider is independent — a failure on one does not prevent the others from running.</p>
<p><strong>Cloud Backup Delay</strong> — minutes after the local backup completes before cloud sync begins. Minimum 15 minutes. This ensures the local zip is fully written and closed before upload starts. The scheduled cloud sync time is shown when a schedule is configured.</p>
<p><strong>Include in cloud backup</strong> — checkboxes to enable each provider individually:</p>
<ul>
<li><strong>AWS S3</strong> — uploads the backup zip to your S3 bucket using the AWS CLI.</li>
<li><strong>Google Drive</strong> — copies the backup zip to Google Drive using rclone.</li>
<li><strong>Dropbox</strong> — copies the backup zip to Dropbox using rclone.</li>
<li><strong>Microsoft OneDrive</strong> — copies the backup zip to OneDrive using rclone.</li>
<li><strong>AWS EC2 AMI Snapshot</strong> — creates an Amazon Machine Image of the entire EC2 server.</li>
</ul>
<p>Providers that have not been configured in their cards below are greyed out and cannot be enabled here. All five providers can be active simultaneously — the backup zip is uploaded to each in turn.</p>
<p><strong>Max Cloud Backups to Keep</strong> — the retention limit applied to each cloud destination independently. When this limit is exceeded after a sync, the oldest non-golden backup is deleted automatically. Applies to S3 objects, Google Drive files, Dropbox files, OneDrive files, and AMI snapshots. Golden images are exempt from this limit.</p>
<p>If the local backup schedule is disabled while cloud sync is enabled, a warning banner appears at the top of the page — cloud sync will upload the most recent existing backup, but no new local backups will be created first.</p>`,

        'google-drive': `
<p>The <strong>Google Drive Backup</strong> card uses <a href="https://rclone.org" target="_blank">rclone</a> to copy backup zips to Google Drive after every local backup. Setup takes about 5 minutes and only needs to be done once.</p>
<p><strong>rclone remote name</strong> — the name you gave the remote during <code>rclone config</code>, e.g. <code>gdrive</code>. Case-sensitive.</p>
<p><strong>Destination folder</strong> — path inside Google Drive where backups are copied. Trailing slash required (e.g. <code>cloudscale-backups/</code>). Leave blank for the Drive root.</p>
<p><strong>Test Connection</strong> — runs <code>rclone lsd</code> and reports success or the error message.</p>
<p><strong>Diagnose</strong> — shows rclone version, remote name, and authentication errors.</p>
<p><strong>Copy Last Backup to Cloud</strong> — immediately copies the most recent local backup zip to Drive, outside of any schedule. Does not create a new backup — it copies whatever zip is already on the server. Before starting, the plugin checks how much free space Google Drive has:</p>
<ul>
<li>If Drive has enough space, the sync starts immediately.</li>
<li>If Drive is running low, an inline warning appears showing MB free vs MB needed, with two options: <strong>Delete oldest</strong> (removes the oldest non-golden backup from Drive to make room, then starts the sync) or <strong>Sync anyway</strong> (proceeds regardless).</li>
</ul>
<p>When configured, the card shows the full destination path, number of backups in Drive, and time of last sync.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 16px;font-size:1.1em;font-weight:800;color:#0f172a;">Google Drive Setup Guide</p>
<p><strong>Step 1 — Install rclone on the server</strong></p>
<p>SSH into your server, then run:</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 16px;">curl -fsSL https://rclone.org/install.sh | sudo bash</pre>
<p>The install log ends with: <code>rclone v1.x.x has successfully installed. Now run "rclone config" for setup.</code></p>
<p><strong>Step 2 — Fix apache home directory permissions</strong></p>
<p>rclone saves its config in apache's home folder. Run these commands or it will fail silently:</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 16px;">sudo mkdir -p /usr/share/httpd/.config/rclone
sudo chown -R apache:apache /usr/share/httpd/.config
sudo chmod 700 /usr/share/httpd/.config/rclone
sudo chown apache:apache /usr/share/httpd
sudo chmod 755 /usr/share/httpd</pre>
<p><strong>Step 3 — Run the setup wizard as the apache user</strong></p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">sudo -u apache rclone config</pre>
<p>Answer each prompt as follows:</p>
<table style="width:100%;border-collapse:collapse;font-size:.9em;margin:0 0 16px;">
<thead><tr style="background:#f1f5f9;"><th style="padding:7px 10px;text-align:left;border:1px solid #e2e8f0;">Prompt</th><th style="padding:7px 10px;text-align:left;border:1px solid #e2e8f0;">Type</th></tr></thead>
<tbody>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n/s/q</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n</code> (New remote)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>name&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>gdrive</code></td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>Storage&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>drive</code> or the number for Google Drive</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>client_id&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;">Enter (leave blank)</td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>client_secret&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;">Enter (leave blank)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>scope&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>1</code> (full access)</td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>service_account_file&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;">Enter (leave blank)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;">Edit advanced config?</td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n</code></td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;">Use web browser to authenticate?</td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n</code> (no browser on server)</td></tr>
</tbody></table>
<p><strong>Step 4 — Authorise on your laptop</strong></p>
<p>The server prints: <em>Execute the following on the machine with the web browser.</em> Open a <strong>new terminal on your laptop</strong> (leave the SSH session open). Install rclone on your laptop if needed (<code>brew install rclone</code>), then run:</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">rclone authorize "drive"</pre>
<p>Your browser opens — sign in to Google and click Allow. The laptop terminal prints a JSON token block. Copy the <strong>entire JSON</strong> (from <code>{</code> to <code>}</code>) and paste it into the SSH session at the <code>config_token&gt;</code> prompt.</p>
<p><strong>Step 5 — Finish the wizard</strong></p>
<p>Answer <code>n</code> to "Configure as Shared Drive", <code>y</code> to confirm the remote, then <code>q</code> to quit. You should see: <em>Current remotes: gdrive / drive</em>.</p>
<p><strong>Step 6 — Save settings in the plugin</strong></p>
<p>Enter <code>gdrive</code> in the <em>rclone remote name</em> field, your destination folder in <em>Destination folder</em> (e.g. <code>cloudscale-backups/</code>), click <strong>Save Drive Settings</strong>, then <strong>Test Connection</strong>.</p>
<p><strong>Permission error?</strong> If the wizard prints <em>permission denied</em> when saving config, write the config file manually:</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 8px;">sudo bash -c 'cat > /usr/share/httpd/.config/rclone/rclone.conf &lt;&lt; EOF
[gdrive]
type = drive
scope = drive
token = PASTE_YOUR_JSON_TOKEN_HERE
EOF'
sudo chown apache:apache /usr/share/httpd/.config/rclone/rclone.conf
sudo chmod 600 /usr/share/httpd/.config/rclone/rclone.conf</pre>
<p>Replace <code>PASTE_YOUR_JSON_TOKEN_HERE</code> with the full JSON token from Step 4, then re-run <strong>Test Connection</strong>.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Running out of Google Drive space</p>
<p>Google Drive free tier provides 15 GB shared across Drive, Gmail, and Photos. If your backups are large, you may run out of quota. The plugin detects this in two places:</p>
<ul>
<li><strong>Before a manual backup</strong> — the cloud space pre-check (see <em>Create Local Backup Now</em>) warns you and offers a <em>Free space on Google Drive</em> button.</li>
<li><strong>Before a Copy Last Backup to Cloud sync</strong> — an inline warning appears on the card with <em>Delete oldest</em> and <em>Sync anyway</em> options.</li>
</ul>
<p>The <em>Delete oldest</em> / <em>Free space</em> action removes the oldest non-golden backup from Google Drive one file at a time until the required space (plus a 15% buffer) is available. Golden images are never deleted automatically. If all deletable backups have been removed and there is still not enough space, you will need to free up quota manually in Google Drive, upgrade your Google One storage, or reduce your retention count.</p>
<p>Note: the plugin computes free space as <em>total quota − used quota</em> rather than trusting Google's reported free field, which can be inaccurate when the account is over quota.</p>`,

        'dropbox': `
<p>The <strong>Dropbox Backup</strong> card uses <a href="https://rclone.org" target="_blank">rclone</a> to copy backup zips to Dropbox after every local backup. If you already set up rclone for Google Drive, skip straight to Step 3 — you just need to add a new remote.</p>
<p><strong>rclone remote name</strong> — the name you gave the remote during <code>rclone config</code>, e.g. <code>dropbox</code>.</p>
<p><strong>Destination folder</strong> — path inside Dropbox where backups are copied. Trailing slash required (e.g. <code>cloudscale-backups/</code>). Leave blank for the Dropbox root.</p>
<p><strong>Test Connection</strong> — verifies rclone can reach Dropbox with the configured remote.</p>
<p><strong>Diagnose</strong> — shows rclone version, remote name, and troubleshooting tips.</p>
<p><strong>Copy Last Backup to Cloud</strong> — immediately copies the most recent local backup zip to Dropbox, outside of any schedule. Does not create a new backup — it copies whatever zip is already on the server. Before starting, the plugin checks how much free space Dropbox has:</p>
<ul>
<li>If Dropbox has enough space, the sync starts immediately.</li>
<li>If Dropbox is running low, an inline warning appears showing MB free vs MB needed, with two options: <strong>Delete oldest</strong> (removes the oldest non-golden backup from Dropbox to make room, then starts the sync) or <strong>Sync anyway</strong> (proceeds regardless).</li>
</ul>
<p>When configured, the card shows the destination path, number of backups in Dropbox, and time of last sync.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 16px;font-size:1.1em;font-weight:800;color:#0f172a;">Dropbox Setup Guide</p>
<p><strong>Step 1 — Install rclone on the server (if not already installed)</strong></p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 16px;">curl -fsSL https://rclone.org/install.sh | sudo bash</pre>
<p><strong>Step 2 — Fix apache home directory permissions</strong></p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 16px;">sudo mkdir -p /usr/share/httpd/.config/rclone
sudo chown -R apache:apache /usr/share/httpd/.config
sudo chmod 700 /usr/share/httpd/.config/rclone
sudo chown apache:apache /usr/share/httpd
sudo chmod 755 /usr/share/httpd</pre>
<p><strong>Step 3 — Run the setup wizard as the apache user</strong></p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">sudo -u apache rclone config</pre>
<p>Answer each prompt as follows:</p>
<table style="width:100%;border-collapse:collapse;font-size:.9em;margin:0 0 16px;">
<thead><tr style="background:#f1f5f9;"><th style="padding:7px 10px;text-align:left;border:1px solid #e2e8f0;">Prompt</th><th style="padding:7px 10px;text-align:left;border:1px solid #e2e8f0;">Type</th></tr></thead>
<tbody>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>e/n/d/r/c/s/q&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n</code> (New remote)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>name&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>dropbox</code></td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>Storage&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>dropbox</code> or the number for Dropbox</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>client_id&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;">Enter (leave blank)</td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>client_secret&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;">Enter (leave blank)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;">Edit advanced config?</td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n</code></td></tr>
<tr style="background:#fff8e1;"><td style="padding:6px 10px;border:1px solid #e2e8f0;">Use web browser / Use auto config?</td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n</code> (no browser on server)</td></tr>
<tr style="background:#fff8e1;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>config_token&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;">Paste token from laptop (see below)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;">Keep this "dropbox" remote? y/e/d&gt;</td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>y</code></td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>e/n/d/r/c/s/q&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>q</code> (Quit)</td></tr>
</tbody></table>
<p>For the highlighted <strong>config_token&gt;</strong> step, run this on your <strong>laptop</strong> (install rclone first with <code>brew install rclone</code> if needed):</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">rclone authorize "dropbox"</pre>
<p>A browser window opens — log in and authorise Dropbox. The browser shows <em>"Success! All done. Please go back to rclone."</em> Rclone prints a long token like <code>{"access_token":"..."}</code> — copy the entire JSON and paste it at the <code>config_token&gt;</code> prompt on the server.</p>
<p><strong>Step 4 — Save settings in the plugin</strong></p>
<p>Enter <code>dropbox</code> in the <em>rclone remote name</em> field and your destination folder (e.g. <code>cloudscale-backups/</code>), click <strong>Save Dropbox Settings</strong>, then <strong>Test Connection</strong>.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Running out of Dropbox space</p>
<p>Dropbox Basic provides 2 GB free. Backup zips for a typical WordPress site are 200 MB–2 GB, so you may fill Dropbox quickly unless you keep your retention count low or upgrade your Dropbox plan.</p>
<p>The plugin detects a Dropbox quota problem in two places:</p>
<ul>
<li><strong>Before a manual backup</strong> — the cloud space pre-check warns you and offers a <em>Free space on Dropbox</em> button.</li>
<li><strong>Before a Copy Last Backup to Cloud sync</strong> — an inline warning appears on the card with <em>Delete oldest</em> and <em>Sync anyway</em> options.</li>
</ul>
<p>The <em>Delete oldest</em> / <em>Free space</em> action removes the oldest non-golden backup from Dropbox one file at a time until the required space (plus a 15% buffer) is available. Golden images are never deleted. If all deletable backups have been removed and there is still not enough space, you will need to free up Dropbox quota manually, upgrade your plan, or switch to a higher-quota provider such as Google Drive or S3.</p>
<p>Note: when a Dropbox account is over quota, Dropbox returns an extremely large number as the "free space" field. The plugin works around this by computing free space as <em>total quota − used quota</em> to detect the over-quota state reliably.</p>`,

        'onedrive': `
<p>The <strong>Microsoft OneDrive Backup</strong> card uses <a href="https://rclone.org" target="_blank">rclone</a> to copy backup zips to OneDrive after every local backup. If you already set up rclone for Google Drive or Dropbox, skip straight to Step 3 — you just need to add a new remote.</p>
<p><strong>rclone remote name</strong> — the name you gave the remote during <code>rclone config</code>, e.g. <code>onedrive</code>.</p>
<p><strong>Destination folder</strong> — path inside OneDrive where backups are copied. Trailing slash required (e.g. <code>cloudscale-backups/</code>). Leave blank for the OneDrive root.</p>
<p><strong>Test Connection</strong> — verifies rclone can reach OneDrive with the configured remote.</p>
<p><strong>Diagnose</strong> — shows rclone version, remote name, and troubleshooting tips.</p>
<p><strong>Copy Last Backup to Cloud</strong> — immediately copies the most recent local backup zip to OneDrive, outside of any schedule. Before starting, the plugin checks how much free OneDrive space is available:</p>
<ul>
<li>If OneDrive has enough space, the sync starts immediately.</li>
<li>If OneDrive is running low, an inline warning appears with <strong>Delete oldest</strong> and <strong>Sync anyway</strong> options.</li>
</ul>
<p>When configured, the card shows the destination path, number of backups in OneDrive, and time of last sync.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 16px;font-size:1.1em;font-weight:800;color:#0f172a;">OneDrive Setup Guide</p>
<p><strong>Step 1 — Install rclone on the server (if not already installed)</strong></p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 16px;">curl -fsSL https://rclone.org/install.sh | sudo bash</pre>
<p><strong>Step 2 — Fix apache home directory permissions</strong></p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 16px;">sudo mkdir -p /usr/share/httpd/.config/rclone
sudo chown -R apache:apache /usr/share/httpd/.config
sudo chmod 700 /usr/share/httpd/.config/rclone
sudo chown apache:apache /usr/share/httpd
sudo chmod 755 /usr/share/httpd</pre>
<p><strong>Step 3 — Run the setup wizard as the apache user</strong></p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">sudo -u apache rclone config</pre>
<p>Answer each prompt as follows:</p>
<table style="width:100%;border-collapse:collapse;font-size:.9em;margin:0 0 16px;">
<thead><tr style="background:#f1f5f9;"><th style="padding:7px 10px;text-align:left;border:1px solid #e2e8f0;">Prompt</th><th style="padding:7px 10px;text-align:left;border:1px solid #e2e8f0;">Type</th></tr></thead>
<tbody>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>e/n/d/r/c/s/q&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n</code> (New remote)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>name&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>onedrive</code></td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>Storage&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>onedrive</code> or the number for Microsoft OneDrive</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>client_id&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;">Enter (leave blank)</td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>client_secret&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;">Enter (leave blank)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>region&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>global</code> (Microsoft Cloud Global)</td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;">Edit advanced config?</td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n</code></td></tr>
<tr style="background:#fff8e1;"><td style="padding:6px 10px;border:1px solid #e2e8f0;">Use web browser / Use auto config?</td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>n</code> (no browser on server)</td></tr>
<tr style="background:#fff8e1;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>config_token&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;">Paste token from laptop (see below)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>config_type&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>onedrive</code> (OneDrive Personal) or <code>business</code></td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;">Select drive</td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>0</code> (your default drive)</td></tr>
<tr style="background:#f8fafc;"><td style="padding:6px 10px;border:1px solid #e2e8f0;">Keep this "onedrive" remote? y/e/d&gt;</td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>y</code></td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>e/n/d/r/c/s/q&gt;</code></td><td style="padding:6px 10px;border:1px solid #e2e8f0;"><code>q</code> (Quit)</td></tr>
</tbody></table>
<p>For the highlighted <strong>config_token&gt;</strong> step, run this on your <strong>laptop</strong> (install rclone first with <code>brew install rclone</code> if needed):</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">rclone authorize "onedrive"</pre>
<p>A browser window opens — sign in to your Microsoft account and click Accept. The browser shows <em>"Success! All done. Please go back to rclone."</em> Rclone prints a long token like <code>{"access_token":"..."}</code> — copy the entire JSON and paste it at the <code>config_token&gt;</code> prompt on the server.</p>
<p><strong>Step 4 — Save settings in the plugin</strong></p>
<p>Enter <code>onedrive</code> in the <em>rclone remote name</em> field and your destination folder (e.g. <code>cloudscale-backups/</code>), click <strong>Save OneDrive Settings</strong>, then <strong>Test Connection</strong>.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Running out of OneDrive space</p>
<p>Microsoft 365 personal plans include 1–5 TB. Free Microsoft accounts include 5 GB. The plugin detects an OneDrive quota problem in two places:</p>
<ul>
<li><strong>Before a manual backup</strong> — the cloud space pre-check warns you and offers a <em>Free space on OneDrive</em> button.</li>
<li><strong>Before a Copy Last Backup to Cloud sync</strong> — an inline warning appears on the card with <em>Delete oldest</em> and <em>Sync anyway</em> options.</li>
</ul>
<p>The <em>Delete oldest</em> / <em>Free space</em> action removes the oldest non-golden backup from OneDrive one file at a time until the required space (plus a 15% buffer) is available. Golden images are never deleted automatically.</p>`,

        's3': `
<p>The <strong>AWS S3 Remote Backup</strong> card uses the AWS CLI to upload backup zips to an S3 bucket after every local backup. Requires the AWS CLI on the server and IAM credentials with <code>s3:PutObject</code>, <code>s3:ListBucket</code>, and <code>s3:DeleteObject</code> permissions on the target bucket.</p>
<p><strong>S3 Bucket name</strong> — bucket name only, no <code>s3://</code> prefix (e.g. <code>my-wp-backups</code>).</p>
<p><strong>Key prefix (folder)</strong> — path prefix applied to all uploaded objects. Trailing slash required (e.g. <code>backups/</code>). Use <code>/</code> to store at the bucket root.</p>
<p><strong>Test Connection</strong> — writes a small test file to the bucket and verifies it succeeds, confirming credentials and permissions are correct.</p>
<p><strong>Diagnose</strong> — shows AWS CLI version, bucket, and last-sync details to help debug connection problems.</p>
<p><strong>Copy Last Backup to Cloud</strong> — immediately copies the most recent local backup zip to S3, outside of any schedule. Does not create a new backup — it copies whatever zip is already on the server. S3 has no practical storage quota for most accounts, so no pre-flight space check is performed.</p>
<p>When configured, the card shows the full S3 destination path (<code>s3://bucket/prefix/</code>), number of objects in the bucket, and time of last upload. If an automatic sync fails, the plugin retries once after 5 minutes.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 16px;font-size:1.1em;font-weight:800;color:#0f172a;">AWS CLI Setup Guide</p>
<p><strong>Step 1 — Install the AWS CLI on the server</strong></p>
<p>Amazon Linux 2023 / Amazon Linux 2:</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">sudo dnf install -y awscli</pre>
<p>Ubuntu / Debian (ARM64):</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">curl "https://awscli.amazonaws.com/awscli-exe-linux-aarch64.zip" -o awscliv2.zip
unzip awscliv2.zip && sudo ./aws/install</pre>
<p>Ubuntu / Debian (x86_64):</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o awscliv2.zip
unzip awscliv2.zip && sudo ./aws/install</pre>
<p>Verify: <code>aws --version</code></p>
<p><strong>Step 2 — Grant AWS credentials</strong></p>
<p><strong>Option A — IAM Instance Role (recommended — no keys on disk)</strong></p>
<ol>
<li>AWS Console → <strong>IAM → Roles → Create role</strong></li>
<li>Trusted entity: <strong>AWS service → EC2</strong> → Next</li>
<li>Skip managed policies → <strong>Create role</strong> (e.g. name it <code>ec2-s3-backup</code>)</li>
<li>Open the new role → <strong>Add permissions → Create inline policy</strong> → JSON tab → paste:</li>
</ol>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.82em;overflow-x:auto;margin:0 0 12px;">{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": ["s3:PutObject","s3:ListBucket","s3:DeleteObject","s3:GetObject"],
    "Resource": [
      "arn:aws:s3:::YOUR-BUCKET-NAME",
      "arn:aws:s3:::YOUR-BUCKET-NAME/*"
    ]
  }]
}</pre>
<ol start="5">
<li>Name the policy (e.g. <code>ec2-s3-backup-policy</code>) → <strong>Create policy</strong></li>
<li>EC2 Console → select your instance → <strong>Actions → Security → Modify IAM role</strong> → select the role → <strong>Update IAM role</strong></li>
<li>Verify (no keys needed): <code>aws sts get-caller-identity</code></li>
</ol>
<p><strong>Option B — IAM User with access keys</strong></p>
<ol>
<li>AWS Console → <strong>IAM → Users → Create user</strong> (e.g. <code>cloudscale-s3</code>)</li>
<li>Attach the same inline policy as Option A (scoped to your bucket)</li>
<li>Open the user → <strong>Security credentials → Create access key</strong> → copy the Access Key ID and Secret Access Key</li>
<li>SSH into the server and configure as the web server user:</li>
</ol>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">sudo -u apache aws configure
# Enter when prompted:
AWS Access Key ID:     AKIA...
AWS Secret Access Key: your-secret-key
Default region name:   af-south-1
Default output format: json</pre>
<p>Credentials are stored in <code>/usr/share/httpd/.aws/credentials</code> (apache) or <code>/var/www/.aws/credentials</code> (www-data). Verify with: <code>sudo -u apache aws sts get-caller-identity</code></p>
<p><strong>Step 3 — Save settings in the plugin</strong></p>
<p>Enter your bucket name and prefix, click <strong>Save AWS S3 Settings</strong>, then <strong>Test Connection</strong>.</p>`,

        'plugin-auto-recovery': `
<div style="background:#fff3e0;border-left:4px solid #f57c00;padding:14px 18px;border-radius:0 8px 8px 0;margin-bottom:20px;">
<strong style="color:#e65100;">Did you know?</strong> Plugins are the single most common cause of WordPress site crashes. A bad update can introduce a PHP fatal error that takes your entire site offline — often at night or over a weekend when you are not watching. Automatic Crash Recovery detects this within minutes and restores the previous version automatically.
</div>
<p><strong>Automatic Crash Recovery</strong> automatically backs up each plugin directory before WordPress applies an update, then watches your site for failures. If something goes wrong, it rolls back to the previous version — with no manual intervention required.</p>
<p style="margin:20px 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">How it works</p>
<ol>
<li><strong>Pre-update backup</strong> — the moment WordPress begins updating a plugin, the current plugin directory is copied to a secure location on the server before any new files are placed.</li>
<li><strong>Monitoring window</strong> — after the update completes, a system-cron watchdog script probes the health check URL once per minute for the configured window (default 10 minutes).</li>
<li><strong>Automatic rollback</strong> — two consecutive probe failures (5xx error or connection timeout) trigger a rollback. The broken plugin directory is renamed and the backup is copied back. This happens entirely outside of WordPress, so it works even during a PHP fatal error.</li>
<li><strong>Notification</strong> — on the next WordPress page load after recovery, the rollback is recorded in the Rollback History card and an email is sent to the WordPress admin address. If Twilio SMS is configured, an SMS is sent as well.</li>
<li><strong>Branded recovery page</strong> — while the site is in a crash state, visitors see a branded "Automatic Crash Recovery is recovering this site" page with a live spinner instead of a white screen of death.</li>
</ol>
<div style="text-align:center;margin:20px 0;">
<img src="https://your-s3-bucket.s3.af-south-1.amazonaws.com/docs/recovery-page-mobile.png" alt="CloudScale Automatic Crash Recovery — branded recovery page shown to visitors while the site recovers" style="max-width:320px;width:100%;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.15);">
<p style="margin:8px 0 0;font-size:.82rem;color:#64748b;">What visitors see while Automatic Crash Recovery is restoring the site — a branded page with a live spinner that auto-refreshes every 30 seconds.</p>
</div>
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Settings card</p>
<p>The blue Settings card contains all configuration for this feature. An <strong>Explain…</strong> button in the card header opens a full feature overview modal.</p>
<ul>
<li><strong>Enable Automatic Crash Recovery</strong> — turn the feature on or off. When disabled, no backups are taken before updates and no monitoring occurs. Enabled by default.</li>
<li><strong>Monitoring window</strong> — how many minutes after an update the watchdog actively probes the site. Increase this for sites that take longer to stabilise after a plugin update (for example, sites that run cache warming or build steps). Maximum 30 minutes.</li>
<li><strong>Health check URL</strong> — the URL the watchdog fetches each minute to check site health. Leave blank to use the site home URL. A 5xx response or connection failure is treated as unhealthy; 4xx responses (including 404) are treated as healthy — the server is up even if the page is missing.</li>
<li><strong>Test Health Check</strong> — fetches the health URL immediately from the server and shows the HTTP status code. Use this to confirm the watchdog can reach your site before relying on it.</li>
</ul>
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Watchdog Script card</p>
<p>The watchdog is a bash script that runs every minute via the server's system cron (root's crontab). It operates completely independently of WordPress and can detect and fix problems even when the entire site is returning errors. An <strong>Explain…</strong> button in the card header explains the setup in detail. The <strong>Watchdog</strong> status indicator turns green once the script has run in the last 90 seconds; amber means it has not run recently.</p>
<p><strong>Step 1</strong> — copy the script from the card using the <strong>Copy</strong> button.</p>
<p><strong>Step 2</strong> — paste it onto the server:</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">sudo tee /usr/local/bin/csbr-par-watchdog.sh &lt;&lt;'EOF'
(paste script here)
EOF
sudo chmod +x /usr/local/bin/csbr-par-watchdog.sh</pre>
<p><strong>Step 3</strong> — add the cron line to root's crontab:</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">sudo crontab -e</pre>
<p>Paste this line and save:</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">* * * * * root /usr/local/bin/csbr-par-watchdog.sh &gt;&gt; /var/log/cloudscale-par.log 2&gt;&amp;1</pre>
<p><strong>Why system cron and not WP-Cron?</strong> If a plugin update causes a PHP fatal error, WordPress crashes completely — <code>wp-cron.php</code> never fires. A system cron job running every minute operates outside of WordPress entirely and can detect and recover from the problem before any visitor notices.</p>
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Active Monitors card</p>
<p>After each plugin update a monitor is created automatically. It appears in the <strong>Active Monitors</strong> table with a live countdown timer. The table auto-refreshes every 15 seconds while monitors are active, and you can reload it at any time with the <strong>Refresh</strong> button. An <strong>Explain…</strong> button describes what each column means.</p>
<ul>
<li><strong>Plugin</strong> — the plugin being monitored.</li>
<li><strong>Version</strong> — the version before the update (left) and after (right). A rollback restores the "before" version.</li>
<li><strong>Time Remaining</strong> — countdown to when this monitor expires. A red fail badge shows consecutive probe failures detected so far — a second failure triggers a rollback.</li>
<li><strong>Roll Back Now</strong> — manually trigger an immediate rollback without waiting for the watchdog. Use this if you spot a problem before the watchdog does.</li>
</ul>
<p>During a healthy update the monitor appears, counts down, then disappears automatically. No action is needed unless a problem is detected.</p>
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Rollback History card</p>
<p>Every automatic and manual rollback is recorded here. An <strong>Explain…</strong> button describes each column and what to do after a rollback.</p>
<ul>
<li><strong>Plugin</strong> — the plugin that was rolled back.</li>
<li><strong>Versions</strong> — the failed version (left) and the version it was restored to (right).</li>
<li><strong>Rolled Back</strong> — date and time the rollback completed (UTC).</li>
<li><strong>Trigger</strong> — <em>Watchdog / site failure</em> (automatic) or <em>Manual rollback</em> (admin-initiated).</li>
<li><strong>Dismiss</strong> — removes the entry from this list. Does not undo the rollback or reactivate the updated version.</li>
</ul>
<p>Each rollback also writes an entry to the Activity Log and sends a notification via whichever channels are enabled in the Notifications card.</p>
<p style="margin:0 0 10px;font-size:1.1em;font-weight:800;color:#0f172a;">Rollback notifications</p>
<p>Email, SMS (Twilio), and push (ntfy) alerts for plugin rollbacks are all configured in the <strong>Notifications card</strong> on the Local Backups tab. Each channel has a <strong>Plugin rollbacks</strong> checkbox — enable it on any channel you want to be alerted on when a rollback occurs. This means you can receive a rollback SMS without subscribing to backup success/failure SMS, or vice versa.</p>`,

        'cloud-history': `
<p>The <strong>Cloud Backup History</strong> panel (teal, at the bottom of the Cloud Backups tab) gives you a unified view of all backups stored across your configured cloud providers. Use the <strong>View history for</strong> dropdown to switch between AWS S3, AWS EC2 AMI Snapshots, Google Drive, Dropbox, and Microsoft OneDrive.</p>
<p>Each provider's history table queries the cloud service live when you click <strong>Refresh</strong>, so it reflects the true current state — including backups created outside of this plugin and files deleted directly in the cloud console.</p>
<p><strong>Columns:</strong></p>
<ul>
<li><strong>File Name / AMI Name</strong> — the filename in S3, Google Drive, or Dropbox, or the AMI name for EC2 snapshots. The most recent entry is marked with a <em>latest</em> badge.</li>
<li><strong>Tag</strong> — an optional free-text label you can attach to any backup for identification (e.g. "pre-launch", "client handover"). Click <strong>Edit</strong> to set or change the tag inline.</li>
<li><strong>Size</strong> — file size as reported by the cloud provider (S3, Drive, Dropbox) or N/A for AMI snapshots.</li>
<li><strong>Created</strong> — the date and time the backup was uploaded or the AMI was created.</li>
</ul>
<p><strong>Row actions:</strong></p>
<ul>
<li><strong>Golden image</strong> (star icon) — marks a backup as a golden image, protecting it permanently from automatic deletion by the <em>Max Cloud Backups to Keep</em> retention limit. Up to 4 golden images are tracked per provider. Golden rows are highlighted in yellow.</li>
<li><strong>Download</strong> — downloads the backup zip directly from S3, Google Drive, or Dropbox to your browser. Not available for AMI snapshots (use the AWS console to restore those).</li>
<li><strong>Delete</strong> — permanently deletes the file from the cloud provider. Cannot be undone.</li>
</ul>
<p>The header bar shows a golden image counter (<em>N / 4 golden</em>) and the current non-golden count against your retention limit (<em>N / max backups</em>). The oldest non-golden backup is flagged with a <strong>Delete Soon</strong> badge when the retention limit is about to be exceeded — it will be removed automatically on the next cloud backup run.</p>`,

        'ami': `
<p>The <strong>AWS EC2 AMI Snapshot</strong> card creates an Amazon Machine Image of the EC2 instance your WordPress site runs on — a complete disk-level snapshot of the entire server including OS, web server config, PHP, and all files. Unlike a file backup, an AMI lets you recover an unbootable or completely broken server. Requires the AWS CLI with <code>ec2:CreateImage</code>, <code>ec2:DescribeImages</code>, <code>ec2:DeregisterImage</code>, and <code>ec2:RebootInstances</code> permissions.</p>
<p><strong>Instance ID / Region</strong> — detected automatically via the EC2 Instance Metadata Service (IMDS). If not running on EC2, or if IMDS is unavailable, AMI creation is disabled.</p>
<p><strong>Region override</strong> — set this if the auto-detected region is wrong or Unknown (e.g. <code>af-south-1</code>).</p>
<p><strong>AMI name prefix</strong> — prepended to each AMI name (e.g. <code>prod-web01</code> produces <code>prod-web01_20260227_1430</code>). The oldest non-golden AMI is deregistered automatically when <em>Max Cloud Backups to Keep</em> is exceeded.</p>
<p><strong>Reboot instance after AMI creation</strong> — rebooting ensures filesystem consistency. Without reboot the AMI is crash-consistent (safe for most workloads). Marked with a downtime warning badge.</p>
<p><strong>Create AMI Now</strong> — triggers an immediate AMI snapshot outside of any schedule. Status shows <em>pending</em> then <em>available</em> after 5–15 minutes. AMI state is polled automatically in the background — you do not need to keep the page open, but you can monitor progress in the Activity Log.</p>
<p>The <strong>AMI history table</strong> lists all tracked AMIs with name, tag, AMI ID, creation date, and state. Row actions:</p>
<ul>
<li><strong>Refresh</strong> — queries AWS for the current state (useful for AMIs still in <em>pending</em>).</li>
<li><strong>Golden image</strong> (star) — permanently protects an AMI from automatic deletion. Does not count towards the retention limit. Up to 4 golden images per provider.</li>
<li><strong>Restore</strong> — available for <em>available</em> AMIs; replaces the root volume. All changes since the snapshot are permanently lost. The server reboots.</li>
<li><strong>Delete</strong> — deregisters the AMI from AWS and removes the record.</li>
</ul>
<p>The <strong>Delete Soon</strong> badge appears on the oldest non-golden AMI when the retention limit is about to be exceeded — it will be deregistered automatically on the next cloud backup run.</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
<p style="margin:0 0 16px;font-size:1.1em;font-weight:800;color:#0f172a;">AWS CLI Setup Guide</p>
<p>If you already configured the AWS CLI for S3, you can reuse it — just extend the IAM policy with the EC2 permissions below.</p>
<p><strong>Step 1 — Install the AWS CLI on the server</strong></p>
<p>Amazon Linux 2023 / Amazon Linux 2:</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">sudo dnf install -y awscli</pre>
<p>Ubuntu / Debian (ARM64):</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">curl "https://awscli.amazonaws.com/awscli-exe-linux-aarch64.zip" -o awscliv2.zip
unzip awscliv2.zip && sudo ./aws/install</pre>
<p>Ubuntu / Debian (x86_64):</p>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o awscliv2.zip
unzip awscliv2.zip && sudo ./aws/install</pre>
<p>Verify: <code>aws --version</code></p>
<p><strong>Step 2 — Grant AWS credentials</strong></p>
<p><strong>Option A — IAM Instance Role (recommended — no keys on disk)</strong></p>
<ol>
<li>AWS Console → <strong>IAM → Roles → Create role</strong></li>
<li>Trusted entity: <strong>AWS service → EC2</strong> → Next</li>
<li>Skip managed policies → <strong>Create role</strong> (e.g. name it <code>ec2-ami-backup</code>)</li>
<li>Open the new role → <strong>Add permissions → Create inline policy</strong> → JSON tab → paste:</li>
</ol>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.82em;overflow-x:auto;margin:0 0 12px;">{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": [
      "ec2:CreateImage",
      "ec2:DescribeImages",
      "ec2:DeregisterImage",
      "ec2:CreateReplaceRootVolumeTask",
      "ec2:DescribeReplaceRootVolumeTasks",
      "ec2:RebootInstances"
    ],
    "Resource": "*"
  }]
}</pre>
<ol start="5">
<li>Name the policy (e.g. <code>ec2-ami-backup-policy</code>) → <strong>Create policy</strong></li>
<li>EC2 Console → select your instance → <strong>Actions → Security → Modify IAM role</strong> → select the role → <strong>Update IAM role</strong></li>
<li>Verify: <code>aws sts get-caller-identity</code></li>
</ol>
<p><strong>Option B — IAM User with access keys</strong></p>
<ol>
<li>AWS Console → <strong>IAM → Users → Create user</strong> (e.g. <code>cloudscale-ami</code>)</li>
<li>Attach the same inline policy as Option A</li>
<li>Open the user → <strong>Security credentials → Create access key</strong> → copy the Access Key ID and Secret Access Key (shown once only)</li>
<li>SSH into the server and configure as the web server user:</li>
</ol>
<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:.85em;overflow-x:auto;margin:0 0 12px;">sudo -u apache aws configure
# Enter when prompted:
AWS Access Key ID:     AKIA...
AWS Secret Access Key: your-secret-key
Default region name:   af-south-1
Default output format: json</pre>
<p>Verify: <code>sudo -u apache aws sts get-caller-identity</code></p>
<p><strong>Step 3 — Configure this plugin</strong></p>
<ol>
<li>Enter an AMI name prefix (e.g. <code>prod-web01</code>)</li>
<li>If the detected region is wrong, enter it in <strong>Region override</strong> (e.g. <code>af-south-1</code>)</li>
<li>Click <strong>Save AWS EC2 AMI Settings</strong></li>
<li>Click <strong>Create AMI Now</strong> to test — status shows <em>pending</em> then <em>available</em> after 5–15 minutes</li>
</ol>`,
    },
}).catch(err => { console.error('ERROR:', err.message); process.exit(1); });
