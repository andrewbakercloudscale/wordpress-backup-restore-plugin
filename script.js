/* CloudScale Free Backup & Restore — Admin Script v2.34 */
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
    // Save Schedule
    // ================================================================

    $('#cs-save-schedule').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Saving...');

        // Temporarily remove disabled from fieldset so we can read checkbox state
        // A disabled fieldset greys out inputs but their .checked property still
        // reflects visual state — HOWEVER some browsers reset checked on disable
        var $fieldset = $('#cs-schedule-controls');
        var wasDisabled = $fieldset.prop('disabled');
        $fieldset.prop('disabled', false);

        var days = [];
        document.querySelectorAll('.cs-day-check').forEach(function(cb) {
            if (cb.checked) days.push(parseInt(cb.value, 10));
        });

        // Restore fieldset state
        $fieldset.prop('disabled', wasDisabled);

        var daysStr = days.join(',');
        console.log('[CS] Saving days:', days, '| joined:', daysStr, '| enabled:', $('#cs-schedule-enabled').is(':checked'));

        var postData = {
            action:    'cs_save_schedule',
            nonce:     CS.nonce,
            enabled:   $('#cs-schedule-enabled').is(':checked') ? 1 : 0,
            run_hour:  $('#cs-run-hour').val(),
            run_days:  daysStr,
        };

        console.log('[CS] Full postData:', postData);

        $.ajax({
            url:     CS.ajax_url,
            type:    'POST',
            data:    postData,
            success: function (res) {
                console.log('[CS] Save response:', res);
                if (res.success) {
                    $btn.text('✓ Saved').removeClass('button-primary').addClass('button-secondary');
                    setTimeout(function () {
                        $btn.text('Save Schedule').removeClass('button-secondary').addClass('button-primary');
                        $btn.prop('disabled', false);
                    }, 2000);
                } else {
                    alert('Save error: ' + JSON.stringify(res.data));
                    $btn.prop('disabled', false).text('Save Schedule');
                }
            },
            error: function (xhr) {
                alert('Save failed. Status: ' + xhr.status + ' Response: ' + xhr.responseText.substring(0, 200));
                $btn.prop('disabled', false).text('Save Schedule');
            }
        });
    });

    // Debug button — reads back what is actually in the DB right now
    $('#cs-debug-schedule').on('click', function () {
        $.post(CS.ajax_url, { action: 'cs_debug_schedule', nonce: CS.nonce }, function (res) {
            if (res.success) {
                var d = res.data;
                alert(
                    'DB cs_run_days: ' + JSON.stringify(d.cs_run_days) + '\n' +
                    'DB raw: ' + d.cs_run_days_raw + '\n' +
                    'DB saved flag: ' + d.cs_run_days_saved + '\n' +
                    'DB enabled: ' + d.cs_schedule_enabled + '\n' +
                    'DB hour: ' + d.cs_run_hour + '\n' +
                    'cs_get_run_days(): ' + JSON.stringify(d.cs_get_run_days) + '\n' +
                    'Last POST run_days: ' + JSON.stringify(d.POST.run_days)
                );
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

        $.post(CS.ajax_url, { action: 'cs_save_retention', nonce: CS.nonce, retention: $('#cs-retention').val() },
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
