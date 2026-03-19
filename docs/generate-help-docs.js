'use strict';
const helpLib = require('/Users/cp363412/Desktop/github/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Free Backup and Restore',
    pluginDesc: 'No-nonsense WordPress backup and restore. Backs up database, media, plugins and themes into a single zip. Scheduled or manual, with safe restore and maintenance mode. Completely free, no subscriptions.',
    pageTitle:  'Help & Documentation — Backup & Restore',
    pageSlug:   'backup-restore-help',
    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-backup`,

    sections: [
        { id: 'backup-list',   label: 'Backup List',           file: 'panel-backup-list.png'   },
        { id: 'create-backup', label: 'Create a Backup',        file: 'panel-create-backup.png' },
        { id: 'restore',       label: 'Restore & Maintenance',  file: 'panel-restore.png'       },
    ],

    docs: {
        'backup-list': `
<p>The <strong>Backup List</strong> shows all backups stored on your server. Each backup is a single zip file containing your database, media uploads, plugins, and themes.</p>
<ul>
<li><strong>Date / time</strong> — when the backup was created.</li>
<li><strong>Size</strong> — the zip file size. Useful for monitoring storage usage.</li>
<li><strong>Type</strong> — Manual (triggered by you) or Scheduled (automatic).</li>
<li><strong>Download</strong> — download the backup zip to your local machine for offsite storage.</li>
<li><strong>Restore</strong> — restore your site to the state at the time of this backup. See the Restore section below.</li>
<li><strong>Delete</strong> — permanently delete a backup to free up disk space.</li>
</ul>`,

        'create-backup': `
<p>Click <strong>Create Backup Now</strong> to immediately create a full backup of your WordPress site. The backup includes:</p>
<ul>
<li>Your complete WordPress database (all posts, pages, settings, users)</li>
<li>The <code>wp-content/uploads/</code> media library</li>
<li>All active plugins</li>
<li>Your active theme</li>
</ul>
<p><strong>Scheduled backups</strong> — configure automatic backups to run daily, twice-daily, or weekly. Backups are stored on the same server as your WordPress installation. For offsite backup, download each zip to your local machine or configure S3 storage.</p>
<p><strong>Retention</strong> — set the maximum number of backups to keep. When the limit is reached, the oldest backup is automatically deleted when a new one is created.</p>`,

        'restore': `
<p><strong>Restore</strong> replaces your current site with the contents of a backup zip. The plugin puts your site into <strong>Maintenance Mode</strong> during the restore, showing a "Down for maintenance" page to visitors.</p>
<p>The restore process:</p>
<ol>
<li>Maintenance mode is activated</li>
<li>The backup zip is extracted</li>
<li>The database is restored from the backup SQL dump</li>
<li>Files (media, plugins, theme) are restored</li>
<li>Maintenance mode is deactivated</li>
</ol>
<p><strong>Important:</strong> Always download a fresh backup before restoring. A restore is irreversible — any changes made after the backup date will be lost.</p>`,
    },
}).catch(err => { console.error('ERROR:', err.message); process.exit(1); });
