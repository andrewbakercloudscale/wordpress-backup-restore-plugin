/* CloudScale Free Backup & Restore — Admin Script v3.2.4 */
jQuery(function ($) {
    'use strict';

    // ================================================================
    // Helpers
    // ================================================================

    function progress(fillId, msgId, msg, state) {
        var $fill = $('#' + fillId);
        var $msg  = $('#' + msgId);
        if (msg) $msg.text(msg);
        $fill.removeClass('cs-done cs-error');
        if (state === 'done')  $fill.addClass('cs-done');
        if (state === 'error') $fill.addClass('cs-error');
    }

    // ================================================================
    // Schedule toggle — fieldset[disabled] handles all child controls
    // ================================================================

    function applyScheduleState(on) {
        $('#cs-schedule-controls').prop('disabled', !on);
        $('#cs-off-notice').toggle(!on);
    }

    applyScheduleState($('#cs-schedule-enabled').is(':checked'));

    $('#cs-schedule-enabled').on('change', function () {
        applyScheduleState($(this).is(':checked'));
    });

    // ================================================================
    // Backup size calculator — updates total as checkboxes change
    // ================================================================

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '~unknown';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return bytes.toFixed(i === 0 ? 0 : 2) + ' ' + units[i];
    }

    function updateBackupTotal() {
        var sizes = window.CS_BACKUP_SIZES || {};

        // Sum raw uncompressed filesystem sizes
        var total = 0;
        var unknown = false;

        if ($('#cs-include-db').is(':checked')) {
            if (sizes.db > 0) total += sizes.db; else unknown = true;
        }
        if ($('#cs-include-media').is(':checked'))     total += sizes.media     || 0;
        if ($('#cs-include-plugins').is(':checked'))   total += sizes.plugins   || 0;
        if ($('#cs-include-themes').is(':checked'))    total += sizes.themes    || 0;
        if ($('#cs-include-mu').is(':checked'))        total += sizes.mu        || 0;
        if ($('#cs-include-languages').is(':checked')) total += sizes.languages || 0;
        if ($('#cs-include-dropins').is(':checked'))   total += sizes.dropins   || 0;
        if ($('#cs-include-htaccess').is(':checked'))  total += sizes.htaccess  || 0;
        if ($('#cs-include-wpconfig').is(':checked'))  total += sizes.wpconfig  || 0;

        var totalText;
        if (total === 0 && unknown) {
            totalText = '~unknown';
        } else if (total === 0) {
            totalText = '—';
        } else {
            var uncompressed = (unknown ? '~' : '') + formatBytes(total);
            if (sizes.latest > 0) {
                // We have a real prior backup — use its compression ratio to estimate zip size
                // Ratio = last zip size / last uncompressed total (approximated from current filesystem)
                // Simpler: just show actual last zip size as the compressed estimate
                totalText = uncompressed + ' uncompressed'
                    + ' <span class="cs-est-note">/ ~' + formatBytes(sizes.latest) + ' zipped (based on last backup)</span>';
            } else {
                // No prior backup — rough 50% compression guess for text-heavy sites
                var guessed = Math.round(total * 0.5);
                totalText = uncompressed + ' uncompressed'
                    + ' <span class="cs-est-note">/ ~' + formatBytes(guessed) + ' zipped (est.)</span>';
            }
        }
        $('#cs-total-size').html(totalText);

        // Warn if estimated total exceeds free space
        var free = sizes.free || 0;
        var $warnRow = $('#cs-space-warn-row');
        var $warn    = $('#cs-space-warn');

        if (free > 0 && total > 0) {
            if (total > free) {
                $warn.text('⚠ Estimated backup exceeds free disk space — backup may fail.');
                $warnRow.show();
            } else if (total > free * 0.8) {
                $warn.text('⚠ Estimated backup will use over 80% of remaining free space.');
                $warnRow.show();
            } else {
                $warnRow.hide();
            }
        } else {
            $warnRow.hide();
        }
    }

    // Run on load and whenever a backup option checkbox changes
    updateBackupTotal();
    $('#cs-include-db, #cs-include-media, #cs-include-plugins, #cs-include-themes, ' +
       '#cs-include-mu, #cs-include-languages, #cs-include-dropins, #cs-include-htaccess, #cs-include-wpconfig')
        .on('change', updateBackupTotal);

    // ================================================================
    // Run Backup
    // ================================================================

    $('#cs-run-backup').on('click', function () {
        var anyChecked = $('#cs-include-db, #cs-include-media, #cs-include-plugins, #cs-include-themes, ' +
            '#cs-include-mu, #cs-include-languages, #cs-include-dropins, #cs-include-htaccess, #cs-include-wpconfig')
            .is(':checked');
        if (!anyChecked) {
            alert('Please select at least one backup option.');
            return;
        }

        var $btn  = $(this);
        var $prog = $('#cs-backup-progress');

        $btn.prop('disabled', true).text('Running backup...');
        $prog.show();
        progress('cs-backup-fill', 'cs-backup-msg', 'Backup in progress — this may take a few minutes for large sites...', 'running');

        $.ajax({
            url:    CS.ajax_url,
            method: 'POST',
            timeout: 0,
            data: {
                action:          'cs_run_backup',
                nonce:           CS.nonce,
                include_db:        $('#cs-include-db').is(':checked')        ? 1 : 0,
                include_media:     $('#cs-include-media').is(':checked')     ? 1 : 0,
                include_plugins:   $('#cs-include-plugins').is(':checked')   ? 1 : 0,
                include_themes:    $('#cs-include-themes').is(':checked')    ? 1 : 0,
                include_mu:        $('#cs-include-mu').is(':checked')        ? 1 : 0,
                include_languages: $('#cs-include-languages').is(':checked') ? 1 : 0,
                include_dropins:   $('#cs-include-dropins').is(':checked')   ? 1 : 0,
                include_htaccess:  $('#cs-include-htaccess').is(':checked')  ? 1 : 0,
                include_wpconfig:  $('#cs-include-wpconfig').is(':checked')  ? 1 : 0,
            },
            success: function (res) {
                if (res.success) {
                    var msg = '✓ ' + res.data.message;
                    if (res.data.s3_msg) {
                        var s3class = res.data.s3_ok ? 'cs-s3-ok' : 'cs-s3-error';
                        msg += '<br><span class="' + s3class + '">' + res.data.s3_msg + '</span>';
                    }
                    progress('cs-backup-fill', 'cs-backup-msg', msg, 'done');
                    var delay = (res.data.s3_msg && !res.data.s3_ok) ? 6000 : 1800;
                    setTimeout(function () { location.reload(); }, delay);
                } else {
                    progress('cs-backup-fill', 'cs-backup-msg', '✗ Error: ' + res.data, 'error');
                    $btn.prop('disabled', false).text('▶ Run Backup Now');
                }
            },
            error: function (xhr, status) {
                progress('cs-backup-fill', 'cs-backup-msg', '✗ Request failed (' + status + '). Check server error log.', 'error');
                $btn.prop('disabled', false).text('▶ Run Backup Now');
            }
        });
    });

    // ================================================================
    // Delete Backup
    // ================================================================

    $(document).on('click', '.cs-delete-btn', function () {
        var file = $(this).data('file');
        if (!confirm('Delete backup:\n\n' + file + '\n\nThis cannot be undone.')) return;

        var $row = $(this).closest('tr');
        var $btn = $(this);
        $btn.prop('disabled', true).removeClass('cs-icon-btn--red').addClass('cs-icon-btn--orange');

        $.post(CS.ajax_url, { action: 'cs_delete_backup', nonce: CS.nonce, file: file },
            function (res) {
                if (res.success) {
                    $row.fadeOut(250, function () { $(this).remove(); });
                } else {
                    alert('Error: ' + res.data);
                    $btn.prop('disabled', false).removeClass('cs-icon-btn--orange').addClass('cs-icon-btn--red');
                }
            }
        );
    });

    // ================================================================
    // Restore Modal — open
    // ================================================================

    var restoreFile = '';

    $(document).on('click', '.cs-restore-btn', function () {
        restoreFile = $(this).data('file');
        var date    = $(this).data('date');

        $('#cs-modal-filename').text(restoreFile);
        $('#cs-modal-date').text(date);
        $('#cs-confirm-snapshot').prop('checked', false);
        $('#cs-modal-confirm').prop('disabled', true);
        $('#cs-modal-progress').hide();
        progress('cs-modal-fill', 'cs-modal-progress-msg', 'Enabling maintenance mode...', 'running');
        $('#cs-modal-overlay, #cs-restore-modal').show();
    });

    $('#cs-confirm-snapshot').on('change', function () {
        $('#cs-modal-confirm').prop('disabled', !this.checked);
    });

    $('#cs-modal-cancel').on('click', function () {
        $('#cs-modal-overlay, #cs-restore-modal').hide();
        restoreFile = '';
    });

    $('#cs-modal-overlay').on('click', function () {
        if (!$('#cs-modal-progress').is(':visible')) {
            $('#cs-modal-overlay, #cs-restore-modal').hide();
            restoreFile = '';
        }
    });

    // ================================================================
    // Restore Modal — confirm and execute
    // ================================================================

    $('#cs-modal-confirm').on('click', function () {
        var $confirm = $(this);
        var $cancel  = $('#cs-modal-cancel');
        var $prog    = $('#cs-modal-progress');

        $confirm.prop('disabled', true).text('Restoring...');
        $cancel.prop('disabled', true);
        $('#cs-confirm-snapshot').prop('disabled', true);
        $prog.show();

        progress('cs-modal-fill', 'cs-modal-progress-msg', 'Step 1/3: Enabling maintenance mode...', 'running');

        setTimeout(function () {
            progress('cs-modal-fill', 'cs-modal-progress-msg', 'Step 2/3: Dropping tables and restoring database — do not close this window...', 'running');
        }, 1200);

        $.ajax({
            url:    CS.ajax_url,
            method: 'POST',
            timeout: 0,
            data: { action: 'cs_restore_backup', nonce: CS.nonce, file: restoreFile },
            success: function (res) {
                if (res.success) {
                    progress('cs-modal-fill', 'cs-modal-progress-msg', 'Step 3/3: ✓ ' + res.data, 'done');
                    setTimeout(function () { alert('Restore complete! The page will reload.'); location.reload(); }, 1500);
                } else {
                    progress('cs-modal-fill', 'cs-modal-progress-msg', '✗ ' + res.data, 'error');
                    $cancel.prop('disabled', false).text('Close');
                }
            },
            error: function (xhr, status) {
                progress('cs-modal-fill', 'cs-modal-progress-msg', '✗ Request error: ' + status + '. Check server error log.', 'error');
                $cancel.prop('disabled', false).text('Close');
            }
        });
    });

    // ================================================================
    // Restore from Upload
    // ================================================================

    $('#cs-restore-upload-btn').on('click', function () {
        var file = $('#cs-restore-file')[0].files[0];
        if (!file) { alert('Please select a backup file to upload.'); return; }

        var ext = file.name.split('.').pop().toLowerCase();
        if (ext !== 'zip' && ext !== 'sql') { alert('Only .zip or .sql files are accepted.'); return; }

        if (!confirm('RESTORE DATABASE from uploaded file:\n\n' + file.name + '\n\n' +
            'This will put the site into maintenance mode, drop all tables, restore from the uploaded file, then bring the site back online.\n\n' +
            'Have you taken a server snapshot?\n\nClick OK to proceed or Cancel to abort.')) return;

        var $btn  = $(this);
        var $prog = $('#cs-restore-upload-progress');

        $btn.prop('disabled', true).text('Restoring...');
        $prog.show();
        progress('cs-restore-upload-fill', 'cs-restore-upload-msg', 'Uploading and restoring — do not close this window...', 'running');

        var fd = new FormData();
        fd.append('action', 'cs_restore_upload');
        fd.append('nonce',  CS.nonce);
        fd.append('backup_file', file);

        $.ajax({
            url: CS.ajax_url, method: 'POST', data: fd,
            processData: false, contentType: false, timeout: 0,
            success: function (res) {
                if (res.success) {
                    progress('cs-restore-upload-fill', 'cs-restore-upload-msg', '✓ ' + res.data, 'done');
                    setTimeout(function () { alert('Restore complete! The page will reload.'); location.reload(); }, 1500);
                } else {
                    progress('cs-restore-upload-fill', 'cs-restore-upload-msg', '✗ ' + res.data, 'error');
                    $btn.prop('disabled', false).text('↩ Restore from Upload');
                }
            },
            error: function (xhr, status) {
                progress('cs-restore-upload-fill', 'cs-restore-upload-msg', '✗ Upload failed: ' + status, 'error');
                $btn.prop('disabled', false).text('↩ Restore from Upload');
            }
        });
    });

    // ================================================================
    // Retention storage checker
    // Sizes stored on a known element to avoid fragile DOM traversal
    // ================================================================

    var $retCard    = $('#cs-retention-storage');
    var retLatest   = parseInt($retCard.data('latest-size') || 0, 10);
    var retFree     = parseInt($retCard.data('free-bytes')  || 0, 10);

    function checkRetentionStorage() {
        if (retLatest <= 0) return;   // no baseline — keep server message

        var count    = parseInt($('#cs-retention').val(), 10) || 0;
        if (count < 1) return;

        var needed   = count * retLatest;
        var over     = retFree > 0 && needed > retFree;

        // Update estimated storage row
        $('#cs-retention-est')
            .text(formatBytes(needed))
            .toggleClass('cs-retention-est--over', over);

        // Traffic light dot
        $('#cs-retention-tl')
            .removeClass('cs-ret-tl--green cs-ret-tl--red')
            .addClass(over ? 'cs-ret-tl--red' : 'cs-ret-tl--green');

        // Input highlight
        $('#cs-retention').toggleClass('cs-retention-over', over);

        // Warning banner
        $('#cs-retention-warn').toggle(over);
    }

    // Bind — keyup catches every single keystroke including backspace
    $('#cs-retention').on('input keyup change', checkRetentionStorage);

    // ================================================================
    // Save Retention
    // ================================================================

    $('#cs-save-retention').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Saving...');

        $.post(CS.ajax_url, { action: 'cs_save_retention', nonce: CS.nonce, retention: $('#cs-retention').val(), backup_prefix: $('#cs-backup-prefix').val() },
            function (res) {
                if (res.success) {
                    $('#cs-retention-saved').show().delay(2500).fadeOut();
                } else {
                    alert('Error: ' + res.data);
                }
            }
        ).always(function () {
            $btn.prop('disabled', false).text('Save Retention');
        });
    });

});

// ================================================================
// Explain modal — shared helper and per-section functions
// ================================================================

function csShowExplain(title, body) {
    var $ = window.jQuery;
    var modalId = 'cs-explain-modal';
    if (!$('#' + modalId).length) {
        $('body').append(
            '<div id="cs-explain-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:99998;"></div>' +
            '<div id="cs-explain-modal" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);' +
            'background:#fff;border-radius:10px;padding:28px 32px;max-width:540px;width:90%;z-index:99999;' +
            'box-shadow:0 8px 32px rgba(0,0,0,0.25);font-family:inherit;">' +
            '<h2 id="cs-explain-title" style="margin:0 0 14px;font-size:1.05rem;"></h2>' +
            '<div id="cs-explain-body" style="font-size:0.9rem;line-height:1.6;color:#333;"></div>' +
            '<button id="cs-explain-close" class="button button-primary" style="margin-top:20px;">Got it</button>' +
            '</div>'
        );
        $('#cs-explain-close, #cs-explain-overlay').on('click', function () {
            $('#cs-explain-overlay, #cs-explain-modal').hide();
        });
    }
    $('#cs-explain-title').text(title);
    $('#cs-explain-body').html(body);
    $('#cs-explain-overlay, #cs-explain-modal').show();
}

window.csScheduleExplain = function () {
    csShowExplain('Backup Schedule',
        '<p>Configure when automatic backups run. Choose a day of the week and a time — the plugin creates a backup at that point every week.</p>' +
        '<p>Backups run via WP-Cron, which fires on the next page load at or after the scheduled time. On low-traffic sites you can add a real server cron job to keep timing accurate.</p>' +
        '<p>Disable the schedule here if you prefer to run backups manually only.</p>'
    );
};

window.csRetentionExplain = function () {
    csShowExplain('Retention & Storage',
        '<p>Set how many backup files to keep on disk. After each backup the oldest files beyond this limit are deleted automatically.</p>' +
        '<p>The storage estimate shows how much space your chosen retention count will use, based on the size of your most recent backup. Keep retention low on servers with limited disk space.</p>'
    );
};

window.csS3Explain = function () {
    csShowExplain('S3 Remote Backup',
        '<p>After each backup the zip file is synced to your AWS S3 bucket using the AWS CLI. This gives you an off-site copy that survives server failure.</p>' +
        '<p><strong>Requirements:</strong> AWS CLI must be installed on the server and configured with credentials that have <code>s3:PutObject</code> and <code>s3:ListBucket</code> permissions on the target bucket.</p>' +
        '<p>Set a bucket name and optional path prefix (e.g. <code>my-bucket/backups/</code>). Failed syncs are retried automatically.</p>'
    );
};

window.csAmiExplain = function () {
    csShowExplain('EC2 AMI Snapshot',
        '<p>Creates a full Amazon Machine Image (AMI) of this EC2 instance — a complete disk-level snapshot you can use to launch a new instance or roll back the entire server.</p>' +
        '<p><strong>Requirements:</strong> AWS CLI installed, with <code>ec2:CreateImage</code>, <code>ec2:DescribeImages</code>, <code>ec2:DeregisterImage</code>, and <code>ec2:RebootInstances</code> IAM permissions.</p>' +
        '<p>AMI creation happens asynchronously. The plugin polls AWS every 10 minutes and updates the status log when the image becomes available.</p>'
    );
};

window.csSystemExplain = function () {
    csShowExplain('System Info',
        '<p>A snapshot of your server environment relevant to backup operations: PHP version, available disk space, max execution time, memory limit, and whether key tools like <code>mysqldump</code> and the AWS CLI are present.</p>' +
        '<p>Use this to diagnose backup failures — for example, a low <code>max_execution_time</code> can cause timeouts on large sites.</p>'
    );
};

window.csBackupExplain = function () {
    csShowExplain('Run Backup Now',
        '<p>Run a one-off backup immediately. Choose which components to include:</p>' +
        '<ul style="margin:8px 0 8px 18px;padding:0;">' +
        '<li><strong>Database</strong> — all MySQL tables via <code>mysqldump</code></li>' +
        '<li><strong>Media</strong> — your <code>wp-content/uploads/</code> folder</li>' +
        '<li><strong>Plugins / Themes</strong> — <code>wp-content/plugins/</code> and <code>wp-content/themes/</code></li>' +
        '<li><strong>Must-Use Plugins</strong> — <code>wp-content/mu-plugins/</code></li>' +
        '<li><strong>Languages / Drop-ins</strong> — translation files and drop-in files</li>' +
        '<li><strong>.htaccess / wp-config.php</strong> — server and WordPress config files</li>' +
        '</ul>' +
        '<p>Everything is packaged into a single <code>.zip</code> stored on the server. If S3 is configured the zip is synced off-site immediately after.</p>'
    );
};

window.csHistoryExplain = function () {
    csShowExplain('Backup History',
        '<p>All backups stored on this server. For each backup you can:</p>' +
        '<ul style="margin:8px 0 8px 18px;padding:0;">' +
        '<li><strong>Download</strong> — save the zip to your local machine</li>' +
        '<li><strong>Restore</strong> — drop and reimport the database from this backup (Full and DB backups only)</li>' +
        '<li><strong>Delete</strong> — permanently remove the file from the server</li>' +
        '</ul>' +
        '<p>Backups are stored as zip files on the server. The storage path is shown at the bottom of this panel. The directory is protected by <code>.htaccess</code> to prevent direct browser access.</p>'
    );
};

window.csCopyBackupPath = function () {
    var path = document.getElementById('cs-backup-path').textContent;
    var btn  = document.getElementById('cs-copy-path');
    navigator.clipboard.writeText(path).then(function () {
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = 'Copy'; }, 2000);
    }).catch(function () {
        // Fallback for older browsers
        var ta = document.createElement('textarea');
        ta.value = path;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = 'Copy'; }, 2000);
    });
};

window.csRestoreExplain = function () {
    csShowExplain('Restore from Uploaded File',
        '<p>Upload a backup zip (created by this plugin) or a raw <code>.sql</code> file from your local machine to restore the database.</p>' +
        '<p><strong>What happens:</strong> The site is put into maintenance mode, all database tables are dropped, the SQL is imported, then maintenance mode is lifted.</p>' +
        '<p><strong>Take a server snapshot before restoring.</strong> A database restore is irreversible — if something goes wrong a snapshot lets you recover instantly.</p>'
    );
};
