/* CloudScale Free Backup & Restore — Admin Script v3.2.27 */
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
    // Tab switching
    // ================================================================

    (function () {
        var STORAGE_KEY = 'cs_active_tab';
        function switchTab(tab) {
            $('.cs-tab').removeClass('cs-tab--active');
            $('.cs-tab[data-tab="' + tab + '"]').addClass('cs-tab--active');
            $('.cs-tab-panel').hide();
            $('#cs-tab-' + tab).show();
            try { localStorage.setItem(STORAGE_KEY, tab); } catch(e) {}
        }
        var saved = 'local';
        try { saved = localStorage.getItem(STORAGE_KEY) || 'local'; } catch(e) {}
        switchTab(saved);
        $('.cs-tab').on('click', function () { switchTab($(this).data('tab')); });
    })();

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
        if ($('#cs-include-dropins').is(':checked'))      total += sizes.dropins      || 0;
        if ($('#cs-include-htaccess').is(':checked'))    total += sizes.htaccess     || 0;
        if ($('#cs-include-wpconfig').is(':checked'))    total += sizes.wpconfig     || 0;

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
       '#cs-include-mu, #cs-include-languages, #cs-include-dropins, ' +
       '#cs-include-htaccess, #cs-include-wpconfig')
        .on('change', updateBackupTotal);

    // ================================================================
    // Run Backup
    // ================================================================

    $('#cs-run-backup').on('click', function () {
        var anyChecked = $('#cs-include-db, #cs-include-media, #cs-include-plugins, #cs-include-themes, ' +
            '#cs-include-mu, #cs-include-languages, #cs-include-dropins, ' +
            '#cs-include-htaccess, #cs-include-wpconfig')
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
                include_dropins:     $('#cs-include-dropins').is(':checked')     ? 1 : 0,
                include_htaccess:    $('#cs-include-htaccess').is(':checked')    ? 1 : 0,
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
    // Save manual backup defaults
    // ================================================================

    $('#cs-save-manual-defaults').on('click', function () {
        var $btn = $(this);
        var $msg = $('#cs-manual-defaults-msg');

        var components = [];
        if ($('#cs-include-db').is(':checked'))          components.push('db');
        if ($('#cs-include-media').is(':checked'))       components.push('media');
        if ($('#cs-include-plugins').is(':checked'))     components.push('plugins');
        if ($('#cs-include-themes').is(':checked'))      components.push('themes');
        if ($('#cs-include-mu').is(':checked'))          components.push('mu');
        if ($('#cs-include-languages').is(':checked'))   components.push('languages');
        if ($('#cs-include-dropins').is(':checked'))     components.push('dropins');
        if ($('#cs-include-htaccess').is(':checked'))    components.push('htaccess');
        if ($('#cs-include-wpconfig').is(':checked'))    components.push('wpconfig');

        $btn.prop('disabled', true);
        $msg.text('Saving…').css('color', '#888').show();

        $.post(CS.ajax_url, { action: 'cs_save_manual_defaults', nonce: CS.nonce, components: components },
            function (res) {
                if (res.success) {
                    $msg.text('✓ Saved').css('color', '#2e7d32');
                } else {
                    $msg.text('✗ ' + res.data).css('color', '#c62828');
                }
            }
        ).fail(function () {
            $msg.text('✗ Request failed').css('color', '#c62828');
        }).always(function () {
            $btn.prop('disabled', false);
            setTimeout(function () { $msg.fadeOut(); }, 3000);
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
        '<p>Two independent backup types, each with their own day and time schedule:</p>' +
        '<ul style="margin:8px 0 8px 18px;padding:0;">' +
        '<li><strong>File backup</strong> — packages your chosen components (database, media, plugins, themes, etc.) into a single <code>.zip</code> stored on the server. If S3 is configured the zip is synced off-site straight after.</li>' +
        '<li><strong>AMI snapshot</strong> — creates a full Amazon Machine Image of this EC2 instance via the AWS CLI: a complete disk-level snapshot you can use to launch a new server or roll back everything. Requires AWS CLI and <code>ec2:CreateImage</code> / <code>ec2:RebootInstances</code> IAM permissions.</li>' +
        '</ul>' +
        '<p>For each type, tick the days of the week it should run and set the time. You can pick multiple days — for example Monday, Wednesday, Friday for file backups and Sunday for AMI snapshots.</p>' +
        '<p>Backups fire via WP-Cron, which triggers on the next page load at or after the scheduled time. On low-traffic sites add a real server cron (<code>*/5 * * * * wget -q -O- yoursite.com/wp-cron.php</code>) for accurate timing.</p>' +
        '<p>Leave all days unchecked (or disable the toggle) to turn off automatic backups and run manually only.</p>'
    );
};

window.csRetentionExplain = function () {
    csShowExplain('Retention & Storage',
        '<p>Controls how many backup zips are kept on disk. After every backup (scheduled or manual) the oldest files beyond this limit are deleted automatically.</p>' +
        '<p><strong>Filename prefix</strong> — the prefix is prepended to every backup zip name (e.g. <code>mysite_f12.zip</code>). Changing it does not affect existing backups.</p>' +
        '<p><strong>Storage estimate</strong> — based on the size of your most recent backup multiplied by your retention count. If the estimate exceeds free disk space the counter turns red — lower retention or free up space before the next backup.</p>'
    );
};

window.csS3Explain = function () {
    csShowExplain('S3 Remote Backup',
        '<p>After every backup (scheduled or manual) the zip is copied to your AWS S3 bucket — an off-site copy that survives server failure or data loss.</p>' +
        '<p><strong>Requirements:</strong> AWS CLI installed on the server with credentials granting <code>s3:PutObject</code> and <code>s3:ListBucket</code> on the target bucket. Use the <em>Test connection</em> button to verify before relying on it.</p>' +
        '<p><strong>Bucket</strong> — just the bucket name (e.g. <code>my-backups</code>). <strong>Path prefix</strong> — optional subfolder inside the bucket (e.g. <code>backups/prod/</code>). Leave prefix blank to put files in the bucket root.</p>' +
        '<p>If a sync fails, the plugin retries automatically after 5 minutes. Sync status for each file is shown in the Backup History table.</p>'
    );
};

window.csS3Diagnose = function () {
    var d = window.CS_S3_DIAG || {};
    var ok  = '<span style="color:#2e7d32;font-weight:700;">&#10003;</span>';
    var err = '<span style="color:#c62828;font-weight:700;">&#10007;</span>';

    function row(icon, label, value, desc) {
        return '<tr>' +
            '<td style="padding:6px 8px 2px 0;white-space:nowrap;vertical-align:top;">' + icon + ' <strong>' + label + '</strong></td>' +
            '<td style="padding:6px 0 2px 8px;vertical-align:top;"><code style="word-break:break-all;">' + value + '</code></td>' +
            '</tr>' +
            '<tr><td colspan="2" style="padding:0 0 10px 20px;font-size:0.82rem;color:#666;">' + desc + '</td></tr>';
    }

    var rows = '';
    if (d.aws_found) {
        // Parse "aws-cli/2.33.29 Python/3.13.11 Linux/6.1.x exe/aarch64.amzn.2023" into parts
        var parts = (d.aws_version || '').split(' ');
        var labels = {
            'aws-cli':  ['AWS CLI version',  'The version of the AWS CLI installed on the server.'],
            'Python':   ['Python version',   'The Python runtime the AWS CLI is built on.'],
            'Linux':    ['Kernel',           'The Linux kernel version and distribution running on this server.'],
            'exe':      ['Architecture',     'The CPU architecture and platform the AWS CLI executable is compiled for.']
        };
        parts.forEach(function (part) {
            var slash = part.indexOf('/');
            if (slash === -1) return;
            var key   = part.slice(0, slash);
            var val   = part.slice(slash + 1);
            var meta  = labels[key] || [key, ''];
            rows += row(ok, meta[0], val, meta[1]);
        });
    } else {
        rows += row(err, 'AWS CLI', 'Not found', 'AWS CLI is not installed or not in a standard path on the server. Required for S3 sync and EC2 AMI creation.');
    }

    csShowExplain('S3 Diagnostics', '<table style="width:100%;border-collapse:collapse;">' + rows + '</table>');
};

window.csCloudScheduleSave = function () {
    var days = [];
    $('.cs-ami-day-check:checked').each(function () { days.push($(this).val()); });
    var $msg = $('#cs-cloud-schedule-msg');
    $msg.text('Saving\u2026').css('color', '#888').show();
    $.ajax({
        url: CS.ajax_url,
        method: 'POST',
        traditional: true,
        data: {
            action:             'cs_save_cloud_schedule',
            nonce:              CS.nonce,
            ami_schedule_days:  days,
            ami_run_hour:       $('#cs-ami-run-hour').val(),
            ami_run_minute:     $('#cs-ami-run-minute').val(),
        },
        success: function (res) {
            if (res.success) {
                $msg.text('\u2713 Saved: ' + res.data).css('color', '#2e7d32');
            } else {
                $msg.text('\u2717 ' + res.data).css('color', '#c62828');
            }
        },
        error: function () { $msg.text('\u2717 Request failed').css('color', '#c62828'); }
    });
};

window.csAmiExplain = function () {
    csShowExplain('EC2 AMI Snapshot',
        '<p>Creates a full Amazon Machine Image (AMI) of this EC2 instance — a disk-level snapshot of the entire server you can use to launch a replacement instance or roll back to a known-good state.</p>' +
        '<p>Unlike a file backup (which only captures WordPress files and the database), an AMI captures the whole disk: OS, web server config, PHP, every file. It is the safest recovery option if the server itself becomes unrecoverable.</p>' +
        '<p><strong>Requirements:</strong> AWS CLI installed on this instance, with IAM permissions: <code>ec2:CreateImage</code>, <code>ec2:DescribeImages</code>, <code>ec2:DeregisterImage</code>, <code>ec2:RebootInstances</code>. Set an AMI name prefix to identify snapshots.</p>' +
        '<p>⚠ AMI creation briefly reboots the instance by default. Schedule it during low-traffic hours.</p>' +
        '<p>Creation is asynchronous — the plugin polls AWS every 10 minutes and logs when the image becomes available. Old AMIs beyond the retention limit are deregistered automatically.</p>'
    );
};

window.csSystemExplain = function () {
    csShowExplain('System Info',
        '<p>A live snapshot of your server environment. Use it to diagnose backup problems before they happen:</p>' +
        '<ul style="margin:8px 0 8px 18px;padding:0;">' +
        '<li><strong>mysqldump</strong> — must be present and executable for database backups to work.</li>' +
        '<li><strong>AWS CLI</strong> — required for S3 sync and AMI snapshots.</li>' +
        '<li><strong>max_execution_time / memory_limit</strong> — low values cause timeouts or out-of-memory errors on large sites. Set to <code>0</code> (unlimited) in <code>php.ini</code> or via <code>wp-config.php</code> if backups are failing.</li>' +
        '<li><strong>Free disk space</strong> — must comfortably exceed your backup size × retention count.</li>' +
        '</ul>'
    );
};

window.csBackupExplain = function () {
    csShowExplain('Run Backup Now',
        '<p>Creates a backup zip immediately with the components you select. Sizes shown are uncompressed estimates — the final zip is typically smaller.</p>' +
        '<ul style="margin:8px 0 8px 18px;padding:0;">' +
        '<li><strong>Database</strong> — full SQL dump of all MySQL tables via <code>mysqldump</code>. Required for a restorable backup.</li>' +
        '<li><strong>Media uploads</strong> — <code>wp-content/uploads/</code>. Often the largest component on media-heavy sites.</li>' +
        '<li><strong>Plugins / Themes</strong> — <code>wp-content/plugins/</code> and <code>wp-content/themes/</code>. Safe to omit if you can reinstall from WordPress.org.</li>' +
        '<li><strong>Must-use plugins</strong> — <code>wp-content/mu-plugins/</code>. Usually small; include if you have custom mu-plugins.</li>' +
        '<li><strong>Languages</strong> — <code>wp-content/languages/</code> translation files.</li>' +
        '<li><strong>Dropins</strong> — special files like <code>object-cache.php</code> in <code>wp-content/</code>.</li>' +
        '<li><strong>.htaccess</strong> — Apache rewrite rules and server config.</li>' +
        '<li><strong>wp-config.php</strong> — contains database credentials. Handle the resulting zip with care.</li>' +
        '</ul>' +
        '<p>The zip is stored on the server. If S3 is configured it is synced off-site immediately after.</p>'
    );
};

window.csHistoryExplain = function () {
    csShowExplain('Backup History',
        '<p>All backup zips currently stored on the server, newest first. For each entry:</p>' +
        '<ul style="margin:8px 0 8px 18px;padding:0;">' +
        '<li><strong>Download</strong> — save the zip to your local machine for off-site storage.</li>' +
        '<li><strong>Restore</strong> — drops all database tables and reimports from this backup. Only available on Full and DB-only backups. The site enters maintenance mode during the restore and comes back online automatically.</li>' +
        '<li><strong>Delete</strong> — permanently removes the file from the server (cannot be undone).</li>' +
        '</ul>' +
        '<p>The <strong>Type</strong> column shows what was included: <em>Full</em> (all components), <em>DB</em> (database only), or a custom combination. The <strong>S3</strong> column shows sync status if S3 is configured.</p>' +
        '<p>Backups are stored in <code>cloudscale-backups/</code> inside <code>wp-content/</code>. The directory is protected by <code>.htaccess</code> to block direct browser access.</p>'
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
        '<p>Upload a backup zip from this plugin or a raw <code>.sql</code> file from your local machine to restore the database on this server.</p>' +
        '<p><strong>What happens:</strong> The site enters maintenance mode → all existing database tables are dropped → the SQL is imported → maintenance mode is lifted automatically.</p>' +
        '<p><strong>Accepted files:</strong> <code>.zip</code> (must contain a <code>.sql</code> dump inside, as created by this plugin) or a plain <code>.sql</code> file.</p>' +
        '<p><strong>This is irreversible.</strong> Take an AMI snapshot or download a fresh backup before restoring. If the import fails for any reason, maintenance mode is removed and the error is shown — but your previous database content will be gone.</p>'
    );
};

// ================================================================
// S3 card functions
// ================================================================

function csS3Msg(text, ok) {
    var el = document.getElementById('cs-s3-msg');
    el.innerHTML = text;
    el.style.color = ok ? '#2e7d32' : '#c62828';
}

function csS3Post(action, extra, onDone) {
    var params = 'action=' + action + '&nonce=' + encodeURIComponent(CS.nonce);
    if (extra) params += '&' + extra;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', CS.ajax_url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        try { onDone(JSON.parse(xhr.responseText)); }
        catch (e) { onDone({ success: false, data: 'Bad response: ' + xhr.responseText.substring(0, 100) }); }
    };
    xhr.onerror = function () { onDone({ success: false, data: 'Network error' }); };
    xhr.send(params);
}

window.csS3Save = function () {
    var bucket = document.getElementById('cs-s3-bucket').value.trim();
    var prefix = document.getElementById('cs-s3-prefix').value.trim() || 'backups/';
    csS3Msg('Saving...', true);
    csS3Post('cs_save_s3',
        'bucket=' + encodeURIComponent(bucket) + '&prefix=' + encodeURIComponent(prefix),
        function (res) { csS3Msg(res.success ? '&#10003; Saved' : '&#10007; ' + res.data, res.success); }
    );
};

window.csS3Test = function () {
    csS3Msg('Testing...', true);
    csS3Post('cs_test_s3', '', function (res) {
        csS3Msg((res.success ? '&#10003; ' : '&#10007; ') + res.data, res.success);
    });
};

window.csS3SyncFile = function (btn, filename) {
    btn.disabled = true;
    btn.textContent = '…';
    csS3Post('cs_s3_sync_file', 'filename=' + encodeURIComponent(filename), function (res) {
        if (res.success) {
            var td = btn.closest ? btn.closest('td') : btn.parentNode;
            if (td) td.innerHTML = '<span style="color:#2e7d32;font-size:16px;">&#10003;</span>';
        } else {
            btn.disabled = false;
            btn.textContent = '↑ Retry';
            var errEl = btn.previousElementSibling;
            if (errEl) errEl.textContent = res.data || 'Sync failed';
            else alert('S3 sync failed: ' + (res.data || 'Unknown error'));
        }
    });
};

// ================================================================
// AMI card functions
// ================================================================

function csAmiMsg(text, ok) {
    var el = document.getElementById('cs-ami-msg');
    el.innerHTML = text;
    el.style.color = ok ? '#2e7d32' : '#c62828';
}

function csAmiPost(action, extra, onDone) {
    csS3Post(action, extra, onDone); // same transport, reuse helper
}

window.csAmiSave = function () {
    var prefix         = document.getElementById('cs-ami-prefix').value.trim();
    var reboot         = document.getElementById('cs-ami-reboot').checked ? '1' : '0';
    var regionOverride = document.getElementById('cs-ami-region-override').value.trim();
    var amiMax         = parseInt(document.getElementById('cs-ami-max').value, 10) || 10;
    csAmiMsg('Saving...', true);
    csAmiPost('cs_save_ami',
        'prefix=' + encodeURIComponent(prefix) +
        '&reboot=' + reboot +
        '&region_override=' + encodeURIComponent(regionOverride) +
        '&ami_max=' + amiMax,
        function (res) { csAmiMsg(res.success ? '&#10003; Saved' : '&#10007; ' + res.data, res.success); }
    );
};

window.csAmiCreate = function () {
    var prefix = document.getElementById('cs-ami-prefix').value.trim();
    if (!prefix) { csAmiMsg('&#10007; Enter an AMI name prefix first.', false); return; }
    var reboot = document.getElementById('cs-ami-reboot').checked;
    var msg = 'Create an AMI snapshot of this instance now?';
    if (reboot) msg += '\n\nWARNING: The instance will be REBOOTED. This will cause brief downtime.';
    if (!confirm(msg)) return;
    csAmiMsg('Creating AMI\u2026 this may take a moment.', true);
    csAmiPost('cs_create_ami', '', function (res) {
        if (res.success) {
            csAmiMsg('&#10003; ' + (res.data.message || 'AMI created'), true);
            setTimeout(function () { location.reload(); }, 2000);
        } else {
            csAmiMsg('&#10007; ' + (res.data || 'AMI creation failed'), false);
        }
    });
};

window.csAmiStatus = function () {
    csAmiMsg('Checking\u2026', true);
    csAmiPost('cs_ami_status', '', function (res) {
        csAmiMsg((res.success ? '&#10003; ' + res.data : '&#10007; ' + (res.data || 'Could not check status')), res.success);
    });
};

function csAmiRefreshAll() {
    var btn = document.getElementById('cs-ami-refresh-all');
    if (btn) { btn.disabled = true; btn.textContent = '\u23f3 Refreshing\u2026'; }
    csAmiMsg('Refreshing all AMI states\u2026', true);
    csAmiPost('cs_ami_refresh_all', '', function (res) {
        if (btn) { btn.disabled = false; btn.innerHTML = '&#8635; Refresh All'; }
        if (!res.success) { csAmiMsg('&#10007; ' + (res.data || 'Refresh failed'), false); return; }
        var results = res.data.results || [];
        var updated = 0;
        results.forEach(function (r) {
            var amiId       = r.ami_id;
            var state       = r.state;
            var stateCell   = document.getElementById('cs-ami-state-' + amiId);
            var actionsCell = document.getElementById('cs-ami-actions-' + amiId);
            var row         = document.getElementById('cs-ami-row-' + amiId);
            if (!stateCell) return;
            updated++;
            if (state === 'deleted in AWS') {
                if (row) row.querySelectorAll('td:not(:last-child)').forEach(function (td) { td.style.opacity = '0.45'; });
                stateCell.innerHTML = '<span style="color:#999;font-weight:600;">&#128465; deleted in AWS</span>';
                if (actionsCell) actionsCell.innerHTML = '<button type="button" onclick="csAmiDelete(\'' + amiId + '\',\'' + r.name.replace(/'/g, '') + '\',true)" class="button button-small" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Remove</button>';
            } else {
                if (row) row.querySelectorAll('td').forEach(function (td) { td.style.opacity = ''; });
                var color = state === 'available' ? '#2e7d32' : (state === 'pending' ? '#e65100' : '#757575');
                var icon  = state === 'available' ? '&#10003;' : (state === 'pending' ? '&#9203;' : '&#10007;');
                stateCell.innerHTML = '<span style="color:' + color + ';font-weight:600;">' + icon + ' ' + state + '</span>';
                if (actionsCell) actionsCell.innerHTML = '<button type="button" onclick="csAmiDelete(\'' + amiId + '\',\'' + r.name.replace(/'/g, '') + '\',false)" class="button button-small" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Delete</button>';
            }
        });
        csAmiMsg('&#10003; Refreshed ' + updated + ' AMI' + (updated !== 1 ? 's' : ''), true);
    });
}

window.csAmiResetAndRefresh = function () {
    var btn = document.getElementById('cs-ami-refresh-all');
    if (btn) { btn.disabled = true; btn.textContent = '\u23f3 Refreshing\u2026'; }
    csAmiMsg('Resetting and refreshing\u2026', true);
    csAmiPost('cs_ami_reset_deleted', '', function (res) {
        if (!res.success) {
            if (btn) { btn.disabled = false; btn.innerHTML = '&#8635; Refresh All'; }
            csAmiMsg('&#10007; Reset failed: ' + res.data, false);
            return;
        }
        csAmiRefreshAll();
    });
};

window.csAmiRefreshOne = function (amiId) {
    var stateCell = document.getElementById('cs-ami-state-' + amiId);
    if (stateCell) stateCell.innerHTML = '<span style="color:#888;">&#8635; checking\u2026</span>';
    csAmiPost('cs_ami_status', 'ami_id=' + encodeURIComponent(amiId), function (res) {
        if (!stateCell) return;
        if (res.success && res.data && res.data.state) {
            var state = res.data.state;
            var color = state === 'available' ? '#2e7d32' : (state === 'pending' ? '#e65100' : '#757575');
            var icon  = state === 'available' ? '&#10003;' : (state === 'pending' ? '&#9203;' : '&#10007;');
            stateCell.innerHTML = '<span style="color:' + color + ';font-weight:600;">' + icon + ' ' + state + '</span>';
        } else {
            stateCell.innerHTML = '<span style="color:#c62828;">&#10007; ' + (res.data || 'error') + '</span>';
        }
    });
};

window.csAmiDelete = function (amiId, amiName, alreadyDeleted) {
    var label = amiName ? amiName + ' (' + amiId + ')' : amiId;
    if (alreadyDeleted) {
        if (!confirm('Remove this record from the log?\n\n' + label + '\n\nThis AMI has already been deleted in AWS.')) return;
        csAmiMsg('Removing record\u2026', true);
        csAmiPost('cs_ami_remove_record', 'ami_id=' + encodeURIComponent(amiId), function (res) {
            if (res.success) {
                csAmiMsg('&#10003; Record removed', true);
                var row = document.getElementById('cs-ami-row-' + amiId);
                if (row) row.remove();
            } else {
                csAmiMsg('&#10007; ' + (res.data || 'Remove failed'), false);
            }
        });
        return;
    }
    if (!confirm('Deregister (delete) this AMI?\n\n' + label + '\n\nAssociated EBS snapshots will NOT be deleted automatically.')) return;
    csAmiMsg('Deregistering ' + amiId + '\u2026', true);
    csAmiPost('cs_deregister_ami', 'ami_id=' + encodeURIComponent(amiId), function (res) {
        if (res.success) {
            csAmiMsg('&#10003; ' + (res.data || 'AMI deregistered'), true);
            var row = document.getElementById('cs-ami-row-' + amiId);
            if (row) row.remove();
        } else {
            csAmiMsg('&#10007; ' + (res.data || 'Deregister failed'), false);
        }
    });
};

window.csAmiRemoveFailed = function (name) {
    if (!confirm('Remove this failed record from the log?\n\n' + name)) return;
    csAmiMsg('Removing\u2026', true);
    csAmiPost('cs_ami_remove_failed', 'name=' + encodeURIComponent(name), function (res) {
        if (res.success) {
            csAmiMsg('&#10003; Record removed', true);
            document.querySelectorAll('#cs-ami-tbody tr').forEach(function (row) {
                var cells = row.querySelectorAll('td');
                if (cells.length && cells[0].textContent.trim() === name) row.remove();
            });
        } else {
            csAmiMsg('&#10007; ' + (res.data || 'Remove failed'), false);
        }
    });
};
