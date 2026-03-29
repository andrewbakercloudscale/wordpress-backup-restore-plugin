'use strict';
const helpLib = require('REPO_BASE/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Free Backup and Restore',
    pluginDesc: 'The only WordPress backup plugin that is 100% free — including restore. UpdraftPlus, BackupBuddy, and Duplicator all charge $70–$200 per year the moment you need to actually recover your site. CloudScale gives you scheduled backups, one-click restore, S3 cloud sync, Google Drive sync, Dropbox sync, and AWS EC2 AMI snapshots at zero cost, forever. No upsell, no premium tier, no surprises.',
    pageTitle:  'CloudScale Free Backup and Restore: Online Help',
    pageSlug:   'backup-restore-help',
    downloadUrl: 'https://your-s3-bucket.s3.af-south-1.amazonaws.com/cloudscale-backup.zip',
    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-backup`,

    pluginFile: `${__dirname}/../cloudscale-backup.php`,

    sections: [
        {
            id: 'activity-log', label: 'Activity Log', file: 'panel-activity-log.png', tab: 'local',
            elementSelector: '#cs-log-panel',
        },
        { id: 'schedule',       label: 'Backup Schedule',           file: 'panel-schedule.png',       tab: 'local', elementSelector: '#cs-tab-local .cs-card--blue'    },
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
        { id: 's3',             label: 'AWS S3',                     file: 'panel-s3.png',             tab: 'cloud', elementSelector: '.cs-card--pink'                  },
        { id: 'ami',            label: 'AWS EC2 AMI Snapshot',       file: 'panel-ami.png',            tab: 'cloud', elementSelector: '.cs-card--indigo'                },
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
<li>Backup job queued, starting, completed, or failed — with the zip filename and size.</li>
<li>Cloud sync jobs: queued, starting, complete, or failed — for S3, Google Drive, Dropbox, and AMI.</li>
<li>AMI state transitions (<em>pending</em> → <em>available</em>) detected during background polling.</li>
<li>Retention deletions — when old local or cloud backups are removed automatically.</li>
<li>Space recovery events — when oldest cloud backups are deleted to free quota.</li>
<li>Errors and skipped operations (e.g. sync skipped because a provider is not configured).</li>
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
<p><strong>Save Schedule</strong> — saves the schedule configuration and registers or updates the WordPress cron event. For production sites, supplement WP-Cron with a real system cron to ensure the schedule fires regardless of traffic: <code>* * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron &gt; /dev/null</code></p>`,

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
<p>The <strong>Restore from Uploaded File</strong> card (red) lets you restore your site from a backup file stored on your computer — for example a backup downloaded from a different server.</p>
<p>Click <strong>Choose File</strong> and select either:</p>
<ul>
<li>A <code>.zip</code> created by this plugin — restores all components included in that backup.</li>
<li>A raw <code>.sql</code> file — restores the database only, leaving files untouched.</li>
</ul>
<p>Then click <strong>Restore from Upload</strong>. The file is uploaded to the server first, then the normal restore sequence runs. A progress bar tracks both stages. Keep the tab open until the process completes.</p>
<p><strong>Cross-server migration:</strong> after restoring a backup from a different domain, run: <code>wp search-replace 'https://oldsite.com' 'https://newsite.com' --all-tables</code></p>`,

        'restore': `
<p>Clicking <strong>Restore</strong> next to any backup opens a confirmation modal. The plugin activates <strong>Maintenance Mode</strong> for the entire restore, serving HTTP 503 to frontend visitors. wp-admin remains accessible to administrators.</p>
<p>The modal requires you to tick a confirmation checkbox before <strong>Restore Now</strong> activates. Take an EC2 AMI or hosting snapshot first — if anything goes wrong you can roll back instantly.</p>
<p><strong>Full restore sequence:</strong></p>
<ol>
<li>Maintenance mode activated — <code>wp-content/.maintenance</code> written to disk.</li>
<li>The backup zip is extracted to a temporary directory inside <code>wp-content/</code>.</li>
<li>The database is replaced: existing tables matching the backup's table prefix are dropped and recreated from the <code>.sql</code> dump. Tables with a different prefix are left untouched.</li>
<li>The <code>uploads/</code> directory is replaced with the backup's media files.</li>
<li>The <code>plugins/</code> and active theme directories are replaced.</li>
<li>Any other included components (<code>.htaccess</code>, <code>wp-config.php</code>, mu-plugins, etc.) are restored.</li>
<li>Maintenance mode deactivated — <code>.maintenance</code> deleted.</li>
<li>OPcache flushed so PHP immediately uses the restored files.</li>
</ol>
<p><strong>Important:</strong> a restore is irreversible. Any changes made after the backup date are permanently lost. Always create a fresh backup immediately before restoring.</p>
<p><strong>If a restore fails mid-way</strong> — maintenance mode remains active. Manually delete <code>wp-content/.maintenance</code> via SFTP or SSH to bring the site back online, then inspect <code>wp-content/debug.log</code>.</p>`,

        'cloud-schedule': `
<p>The <strong>Cloud Backup Settings</strong> card (blue) is the master control for all automated cloud syncing. It lives at the top of the <strong>Cloud Backups</strong> tab.</p>
<p><strong>Cloud backup days</strong> — the days on which cloud sync runs. On each selected day the plugin runs providers in this fixed order: AMI snapshot → AWS S3 → Google Drive → Dropbox. Each provider is independent — a failure on one does not prevent the others from running.</p>
<p><strong>Cloud Backup Delay</strong> — minutes after the local backup completes before cloud sync begins. Minimum 15 minutes. This ensures the local zip is fully written and closed before upload starts. The scheduled cloud sync time is shown when a schedule is configured.</p>
<p><strong>Include in cloud backup</strong> — checkboxes to enable each provider individually:</p>
<ul>
<li><strong>AWS S3</strong> — uploads the backup zip to your S3 bucket using the AWS CLI.</li>
<li><strong>Google Drive</strong> — copies the backup zip to Google Drive using rclone.</li>
<li><strong>Dropbox</strong> — copies the backup zip to Dropbox using rclone.</li>
<li><strong>AWS EC2 AMI Snapshot</strong> — creates an Amazon Machine Image of the entire EC2 server.</li>
</ul>
<p>Providers that have not been configured in their cards below are greyed out and cannot be enabled here. All four providers can be active simultaneously — the backup zip is uploaded to each in turn.</p>
<p><strong>Max Cloud Backups to Keep</strong> — the retention limit applied to each cloud destination independently. When this limit is exceeded after a sync, the oldest non-golden backup is deleted automatically. Applies to S3 objects, Google Drive files, Dropbox files, and AMI snapshots. Golden images are exempt from this limit.</p>
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

        'cloud-history': `
<p>The <strong>Cloud Backup History</strong> panel (teal, at the bottom of the Cloud Backups tab) gives you a unified view of all backups stored across your configured cloud providers. Use the <strong>View history for</strong> dropdown to switch between AWS S3, AWS EC2 AMI Snapshots, Google Drive, and Dropbox.</p>
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
