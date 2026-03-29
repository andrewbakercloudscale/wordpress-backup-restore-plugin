/* CloudScale Free Backup & Restore — Admin Script v3.3.0 */
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

    // Cloud schedule enable/disable
    function applyCloudScheduleState(on) {
        $('#cs-cloud-schedule-controls').prop('disabled', !on);
        $('#cs-cloud-off-notice').toggle(!on);
    }
    applyCloudScheduleState($('#cs-cloud-schedule-enabled').is(':checked'));
    $('#cs-cloud-schedule-enabled').on('change', function () {
        applyCloudScheduleState($(this).is(':checked'));
    });

    // Cloud Backup Delay — live preview of run time
    function updateCloudDelayPreview() {
        var localH  = parseInt($('#cs-run-hour').val()  || '3', 10);
        var localM  = parseInt($('#cs-run-minute').val() || '0', 10);
        var delay   = parseInt($('#cs-cloud-backup-delay').val() || '30', 10);
        var $prev   = $('#cs-cloud-delay-preview');
        if (isNaN(delay) || delay < 1) { $prev.text(''); return; }
        var total   = (localH * 60 + localM + delay) % 1440;
        var h = Math.floor(total / 60);
        var m = total % 60;
        $prev.text('\u2192 runs at ' + String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0'));
    }
    updateCloudDelayPreview();
    $('#cs-cloud-backup-delay, #cs-run-hour, #cs-run-minute').on('input change', updateCloudDelayPreview);

    // ================================================================
    // Tab switching
    // ================================================================

    (function () {
        var STORAGE_KEY = 'cs_active_tab';
        var cloudAutoRefreshDone = false;
        function switchTab(tab) {
            $('.cs-tab').removeClass('cs-tab--active');
            $('.cs-tab[data-tab="' + tab + '"]').addClass('cs-tab--active');
            $('.cs-tab-panel').hide();
            $('#cs-tab-' + tab).show();
            try { localStorage.setItem(STORAGE_KEY, tab); } catch(e) { /* localStorage unavailable — silently ignored */ }
            // History panel is always visible (below tabs) — no auto-refresh here
        }
        var saved = 'local';
        try { saved = localStorage.getItem(STORAGE_KEY) || 'local'; } catch(e) { /* localStorage unavailable — silently ignored */ }
        switchTab(saved);
        $('.cs-tab').on('click', function () { switchTab($(this).data('tab')); });
    })();

    // ================================================================
    // Unified Backup History panel — provider dropdown switcher
    // ================================================================

    (function () {
        var HIST_KEY = 'cs_history_source';
        var providers = ['s3', 'ami', 'gdrive', 'dropbox'];
        var histAutoRefreshed = {};

        function switchHistoryPane(src) {
            providers.forEach(function (p) {
                var pane = document.getElementById('cs-hist-pane-' + p);
                var act  = document.getElementById('cs-hist-act-' + p);
                if (pane) pane.style.display = (p === src) ? '' : 'none';
                if (act)  act.style.display  = (p === src) ? 'flex' : 'none';
            });
            var sel = document.getElementById('cs-history-source');
            if (sel && sel.value !== src) sel.value = src;
            try { localStorage.setItem(HIST_KEY, src); } catch(e) { /* localStorage unavailable */ }

            // Auto-populate cloud panes the first time they are shown (if empty)
            if (!histAutoRefreshed[src]) {
                histAutoRefreshed[src] = true;
                setTimeout(function () {
                    if (src === 's3' && document.querySelectorAll('#cs-s3h-tbody tr').length === 0) {
                        if (window.csS3HistoryRefresh) csS3HistoryRefresh();
                    }
                    if (src === 'gdrive' && document.querySelectorAll('#cs-gd-tbody tr').length === 0) {
                        if (window.csGDriveHistoryRefresh) csGDriveHistoryRefresh();
                    }
                    if (src === 'dropbox' && document.querySelectorAll('#cs-db-tbody tr').length === 0) {
                        if (window.csDropboxHistoryRefresh) csDropboxHistoryRefresh();
                    }
                }, 300);
            }
        }

        var savedSrc = 's3';
        try { savedSrc = localStorage.getItem(HIST_KEY) || 's3'; } catch(e) { /* localStorage unavailable */ }
        if (savedSrc === 'local') savedSrc = 's3'; // local option removed
        switchHistoryPane(savedSrc);

        var sel = document.getElementById('cs-history-source');
        if (sel) {
            sel.addEventListener('change', function () { switchHistoryPane(this.value); });
        }
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

    function csCollectBackupData() {
        return {
            action:          'cs_start_backup',
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
        };
    }

    function csDoStartBackup($btn) {
        var $prog = $('#cs-backup-progress');
        $('#cs-cloud-space-warn').hide();
        $btn.prop('disabled', true).text('Starting backup...');
        $prog.show();
        progress('cs-backup-fill', 'cs-backup-msg', 'Starting backup job\u2026', 'running');
        $.ajax({
            url: CS.ajax_url, method: 'POST', timeout: 15000, data: csCollectBackupData(),
            success: function (res) {
                if (res.success) {
                    csStartBackupPoll(res.data.job_id, $btn, $prog);
                } else {
                    progress('cs-backup-fill', 'cs-backup-msg', '\u2717 Error: ' + res.data, 'error');
                    $btn.prop('disabled', false).text('\u25b6 Create Local Backup Now');
                }
            },
            error: function (xhr, status) {
                progress('cs-backup-fill', 'cs-backup-msg', '\u2717 Could not start backup (' + status + '). Check server error log.', 'error');
                $btn.prop('disabled', false).text('\u25b6 Create Local Backup Now');
            }
        });
    }

    function csRenderSpaceRow(provider, info) {
        var id = 'cs-space-row-' + provider;
        var label = info.label || provider;
        var html;
        if (!info.ok && info.free_mb !== null) {
            html = '<div id="' + id + '" style="margin-bottom:6px;">' +
                '<strong style="color:#bf360c;">\u26a0 ' + label + ':</strong> ' +
                info.free_mb + ' MB free, backup needs ~' + info.est_mb + ' MB &nbsp;' +
                '<button type="button" class="button button-small cs-delete-oldest-btn" data-provider="' + provider + '" data-est-mb="' + info.est_mb + '" ' +
                'style="font-size:0.78rem;padding:1px 8px;vertical-align:middle;">Free space on ' + label + '</button>' +
                ' <span class="cs-del-status-' + provider + '" style="font-size:0.78rem;margin-left:4px;"></span></div>';
        } else if (info.free_mb !== null) {
            html = '<div id="' + id + '" style="margin-bottom:4px;color:#2e7d32;">' +
                '\u2713 ' + label + ': ' + info.free_mb + ' MB free \u2014 OK</div>';
        } else {
            html = '<div id="' + id + '" style="margin-bottom:4px;color:#555;">' +
                label + ': space unknown \u2014 proceeding</div>';
        }
        return html;
    }

    $('#cs-run-backup').on('click', function () {
        var anyChecked = $('#cs-include-db, #cs-include-media, #cs-include-plugins, #cs-include-themes, ' +
            '#cs-include-mu, #cs-include-languages, #cs-include-dropins, ' +
            '#cs-include-htaccess, #cs-include-wpconfig')
            .is(':checked');
        if (!anyChecked) {
            csAlert('&#9888;', 'No Components Selected',
                'Please select at least one component to include in the backup.',
                '<p>Check one or more items — <strong>Database</strong>, <strong>Media</strong>, <strong>Plugins</strong>, <strong>Themes</strong>, or other files — before starting the backup.</p>');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Checking cloud storage\u2026');

        $.ajax({
            url: CS.ajax_url, method: 'POST', timeout: 30000,
            data: { action: 'cs_cloud_space_check', nonce: CS.nonce },
            success: function (res) {
                if (!res.success || $.isEmptyObject(res.data)) {
                    csDoStartBackup($btn);
                    return;
                }
                var hasWarning = false;
                $.each(res.data, function (p, info) { if (!info.ok) hasWarning = true; });
                if (!hasWarning) { csDoStartBackup($btn); return; }

                // Show warning panel
                var rows = '';
                $.each(res.data, function (p, info) { rows += csRenderSpaceRow(p, info); });
                $('#cs-cloud-space-rows').html(rows);
                $('#cs-cloud-space-warn').show();
                $btn.prop('disabled', false).text('\u25b6 Create Local Backup Now');
            },
            error: function () { csDoStartBackup($btn); }
        });
    });

    // "Run Backup Anyway" from the space warning panel
    $('#cs-run-backup-anyway').on('click', function () {
        csDoStartBackup($('#cs-run-backup'));
    });

    // Cancel from the space warning panel
    $('#cs-space-check-cancel').on('click', function () {
        $('#cs-cloud-space-warn').hide();
    });

    // "Delete oldest" buttons inside the space warning panel
    $(document).on('click', '.cs-delete-oldest-btn', function () {
        var $btn      = $(this);
        var provider  = $btn.data('provider');
        var estMb     = parseFloat($btn.data('est-mb') || '0');
        var $status   = $('.cs-del-status-' + provider);
        $btn.prop('disabled', true);
        $status.text('Freeing space\u2026');
        $.ajax({
            url: CS.ajax_url, method: 'POST', timeout: 120000,
            data: { action: 'cs_delete_oldest_cloud', nonce: CS.nonce, provider: provider, need_mb: estMb },
            success: function (res) {
                if (res.success) {
                    var d = res.data;
                    var countText = d.count > 1 ? d.count + ' backups deleted' : 'Deleted ' + (d.deleted || 'backup');
                    var freeText  = d.free_mb !== null ? d.free_mb + ' MB free' : 'space updated';
                    $btn.closest('div').replaceWith(
                        '<div id="cs-space-row-' + provider + '" style="margin-bottom:6px;color:#2e7d32;">' +
                        '\u2713 ' + countText + ' \u2014 ' + freeText + '</div>'
                    );
                    var stillWarning = $('#cs-cloud-space-rows').find('.cs-delete-oldest-btn').length > 0;
                    if (!stillWarning) {
                        setTimeout(function () { csDoStartBackup($('#cs-run-backup')); }, 800);
                    }
                } else {
                    $status.text('\u2717 ' + (res.data || 'Delete failed'));
                    $btn.prop('disabled', false);
                }
            },
            error: function () {
                $status.text('\u2717 Request failed');
                $btn.prop('disabled', false);
            }
        });
    });

    function csStartBackupPoll(jobId, $btn, $prog) {
        var elapsed = 0;
        progress('cs-backup-fill', 'cs-backup-msg', 'Backup running\u2026 (0s)', 'running');

        var timer = setInterval(function () {
            elapsed += 1;
            progress('cs-backup-fill', 'cs-backup-msg', 'Backup running\u2026 (' + elapsed + 's)', 'running');

            $.ajax({
                url: CS.ajax_url, method: 'POST', timeout: 15000,
                data: { action: 'cs_backup_status', nonce: CS.nonce, job_id: jobId },
                success: function (res) {
                    if (!res.success) {
                        clearInterval(timer);
                        progress('cs-backup-fill', 'cs-backup-msg', '\u2717 ' + res.data, 'error');
                        $btn.prop('disabled', false).text('\u25b6 Create Local Backup Now');
                        return;
                    }
                    var d = res.data;
                    if (d.status === 'complete') {
                        clearInterval(timer);
                        var msg = '\u2713 Backup complete: ' + d.filename;
                        if (d.s3_msg)      { msg += '<br><span class="' + (d.s3_ok      ? 'cs-s3-ok' : 'cs-s3-error') + '">' + d.s3_msg      + '</span>'; }
                        if (d.gdrive_msg)  { msg += '<br><span class="' + (d.gdrive_ok  ? 'cs-s3-ok' : 'cs-s3-error') + '">' + d.gdrive_msg  + '</span>'; }
                        if (d.dropbox_msg) { msg += '<br><span class="' + (d.dropbox_ok ? 'cs-s3-ok' : 'cs-s3-error') + '">' + d.dropbox_msg + '</span>'; }
                        progress('cs-backup-fill', 'cs-backup-msg', msg, 'done');
                        var anyFail = (d.s3_msg && !d.s3_ok) || (d.gdrive_msg && !d.gdrive_ok) || (d.dropbox_msg && !d.dropbox_ok);
                        setTimeout(function () { location.reload(); }, anyFail ? 6000 : 1800);
                    } else if (d.status === 'error') {
                        clearInterval(timer);
                        progress('cs-backup-fill', 'cs-backup-msg', '\u2717 ' + (d.message || 'Backup failed'), 'error');
                        $btn.prop('disabled', false).text('\u25b6 Create Local Backup Now');
                    }
                    // status === 'queued' or 'running' — keep polling
                },
                error: function () {
                    // Network glitch during poll — keep trying
                }
            });
        }, 1000);
    }

    // Space-check wrapper for Copy Last Backup to Cloud buttons.
    // Checks quota for the given provider before starting the sync.
    // provider: 'gdrive' or 'dropbox'  startAction: AJAX action  msgFn: card message fn
    window.csCheckSpaceThenSync = function (provider, startAction, msgFn) {
        msgFn('Checking storage space\u2026', true);
        $.ajax({
            url: CS.ajax_url, method: 'POST', timeout: 30000,
            data: { action: 'cs_cloud_space_check', nonce: CS.nonce, provider: provider },
            success: function (res) {
                var info = res.success && res.data && res.data[provider] ? res.data[provider] : null;
                if (!info || info.ok) {
                    csStartSyncPoll(startAction, msgFn);
                    return;
                }
                // Space warning — show inline with delete + continue options
                var label = info.label || provider;
                var msg = '\u26a0 ' + label + ': ' + info.free_mb + ' MB free, backup needs ~' + info.est_mb + ' MB. &nbsp;' +
                    '<button type="button" class="button button-small" id="cs-sync-del-' + provider + '" ' +
                    'style="font-size:0.78rem;padding:1px 8px;vertical-align:middle;">Delete oldest</button>' +
                    ' &nbsp;<button type="button" class="button button-small" id="cs-sync-anyway-' + provider + '" ' +
                    'style="font-size:0.78rem;padding:1px 8px;vertical-align:middle;">Sync anyway</button>';
                msgFn(msg, false);

                $('#cs-sync-del-' + provider).one('click', function () {
                    msgFn('Freeing space on ' + label + '\u2026', true);
                    $.ajax({
                        url: CS.ajax_url, method: 'POST', timeout: 120000,
                        data: { action: 'cs_delete_oldest_cloud', nonce: CS.nonce, provider: provider, need_mb: info.est_mb },
                        success: function (r) {
                            if (r.success) {
                                var n = r.data.count > 1 ? r.data.count + ' backups deleted' : 'Deleted ' + (r.data.deleted || 'backup');
                                msgFn('\u2713 ' + n + ' (' + (r.data.free_mb || '?') + ' MB free). Starting sync\u2026', true);
                                setTimeout(function () { csStartSyncPoll(startAction, msgFn); }, 600);
                            } else {
                                msgFn('\u2717 Delete failed: ' + (r.data || 'Unknown error'), false);
                            }
                        },
                        error: function () { msgFn('\u2717 Delete request failed', false); }
                    });
                });

                $('#cs-sync-anyway-' + provider).one('click', function () {
                    csStartSyncPoll(startAction, msgFn);
                });
            },
            error: function () { csStartSyncPoll(startAction, msgFn); }
        });
    };

    // ── Single shared timer for all cloud sync jobs ──────────────────────
    // One setInterval drives all pending jobs; one AJAX call per tick covers
    // every outstanding job so timers never compete or jump.
    var _csSyncJobs        = {};  // { jobId: { msgFn, elapsed } }
    var _csSyncTimer       = null;
    var _csConsecPollErrors = 0;

    function csRegisterSyncJob(jobId, msgFn) {
        _csSyncJobs[jobId] = { msgFn: msgFn, elapsed: 0 };
        if (!_csSyncTimer) {
            _csSyncTimer = setInterval(csTickSyncJobs, 1000);
        }
    }

    function csTickSyncJobs() {
        var ids = Object.keys(_csSyncJobs);
        if (!ids.length) {
            clearInterval(_csSyncTimer);
            _csSyncTimer = null;
            return;
        }
        // Increment every counter and refresh the display before the AJAX returns
        ids.forEach(function (id) {
            var job = _csSyncJobs[id];
            job.elapsed++;
            if (job.elapsed >= 300) {
                job.msgFn('Syncing\u2026 (' + job.elapsed + 's) \u2014 still running, safe to navigate away', true);
            } else {
                job.msgFn('Syncing\u2026 (' + job.elapsed + 's)', true);
            }
        });
        // Single AJAX covers all pending jobs
        $.ajax({
            url: CS.ajax_url, method: 'POST', timeout: 15000,
            data: { action: 'cs_batch_job_status', nonce: CS.nonce, job_ids: ids.join(',') },
            success: function (res) {
                _csConsecPollErrors = 0;
                if (!res.success) return;
                $.each(res.data, function (id, d) {
                    var job = _csSyncJobs[id];
                    if (!job) return;
                    if (d.status === 'complete') {
                        job.msgFn('\u2713 ' + (d.message || 'Synced'), true);
                        delete _csSyncJobs[id];
                    } else if (d.status === 'error') {
                        job.msgFn('\u2717 ' + (d.message || 'Sync failed'), false);
                        delete _csSyncJobs[id];
                    } else if (d.status === 'not_found') {
                        job.msgFn('\u2717 Job expired or not found', false);
                        delete _csSyncJobs[id];
                    }
                    // queued/running — leave in map
                });
            },
            error: function () {
                _csConsecPollErrors++;
                if (_csConsecPollErrors >= 5) {
                    Object.keys(_csSyncJobs).forEach(function (id) {
                        _csSyncJobs[id].msgFn('\u2717 Cannot reach server \u2014 sync may still be running in background. Refresh page to check.', false);
                        delete _csSyncJobs[id];
                    });
                    clearInterval(_csSyncTimer);
                    _csSyncTimer = null;
                }
            }
        });
    }

    // Shared entry point for all Copy Last Backup to Cloud buttons
    window.csStartSyncPoll = function (startAction, msgFn) {
        msgFn('Starting sync\u2026', true);
        $.ajax({
            url: CS.ajax_url, method: 'POST', timeout: 15000,
            data: { action: startAction, nonce: CS.nonce },
            success: function (res) {
                if (!res.success) { msgFn('\u2717 ' + (res.data || 'Failed to start'), false); return; }
                msgFn('Syncing\u2026 (0s)', true);
                csRegisterSyncJob(res.data.job_id, msgFn);
            },
            error: function (xhr) {
                window._csLastRawError = xhr.responseText;
                msgFn('\u2717 Server error \u2014 <button type="button" class="button button-small" onclick="csShowRawError()" style="vertical-align:middle;font-size:0.78rem;padding:1px 8px;margin-left:4px;">View details</button>', false);
            }
        });
    };


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
        var $row = $(this).closest('tr');
        var $btn = $(this);
        csConfirm('&#128465;', 'Delete Local Backup',
            '<span style="color:#c62828;">This backup file will be permanently deleted from the server.</span>',
            '<p>File: <strong>' + csEscHtml(file) + '</strong></p>' +
            '<p>The file is removed from disk immediately. Cloud copies (S3, Google Drive, Dropbox) are <strong>not affected</strong>.</p>' +
            '<p>This action <strong>cannot be undone</strong>.</p>',
            function () {
                $btn.prop('disabled', true).removeClass('cs-icon-btn--red').addClass('cs-icon-btn--orange');
                $.post(CS.ajax_url, { action: 'cs_delete_backup', nonce: CS.nonce, file: file },
                    function (res) {
                        if (res.success) {
                            $row.fadeOut(250, function () { $(this).remove(); });
                        } else {
                            csAlert('&#10007;', 'Delete Failed', 'The backup file could not be deleted.',
                                '<p>' + csEscHtml(res.data) + '</p>');
                            $btn.prop('disabled', false).removeClass('cs-icon-btn--orange').addClass('cs-icon-btn--red');
                        }
                    }
                );
            }, 'Delete');
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
                    setTimeout(function () { location.reload(); }, 1500);
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

    $('#cs-restore-file').on('change', function () {
        var name = this.files && this.files[0] ? this.files[0].name : 'No file chosen';
        $('#cs-restore-file-name').text(name);
    });

    $('#cs-restore-upload-btn').on('click', function () {
        var file  = $('#cs-restore-file')[0].files[0];
        var $btn  = $(this);
        var $prog = $('#cs-restore-upload-progress');

        if (!file) {
            csAlert('&#9888;', 'No File Selected', 'Please choose a backup file before restoring.',
                '<p>Click <strong>Choose File</strong> and select a <code>.zip</code> or <code>.sql</code> backup from your computer.</p>');
            return;
        }

        var ext = file.name.split('.').pop().toLowerCase();
        if (ext !== 'zip' && ext !== 'sql') {
            csAlert('&#9888;', 'Unsupported File Type', 'Only <code>.zip</code> and <code>.sql</code> files can be restored.',
                '<p>The selected file <strong>' + csEscHtml(file.name) + '</strong> is not a supported format.</p>' +
                '<p>Accepted: <strong>.zip</strong> (full backup archive) or <strong>.sql</strong> (database dump only).</p>');
            return;
        }

        csConfirm('&#8629;', 'Restore from Uploaded File',
            '<span style="color:#e65100;font-weight:600;">This will overwrite the entire database with the uploaded file.</span>',
            '<p>Restoring <strong>' + csEscHtml(file.name) + '</strong> will:</p>' +
            '<ul style="margin:6px 0 10px 18px;line-height:1.8;">' +
            '<li>Put the site into <strong>maintenance mode</strong></li>' +
            '<li><strong>Drop all current database tables</strong> and replace them with the backup</li>' +
            '<li>Bring the site back online automatically when complete</li>' +
            '</ul>' +
            '<p>Take a server snapshot or AMI first. This action <strong>cannot be undone</strong>.</p>',
            function () {
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
                            progress('cs-restore-upload-fill', 'cs-restore-upload-msg', '&#10003; ' + res.data, 'done');
                            setTimeout(function () { location.reload(); }, 1500);
                        } else {
                            progress('cs-restore-upload-fill', 'cs-restore-upload-msg', '&#10007; ' + res.data, 'error');
                            $btn.prop('disabled', false).text('↩ Restore from Upload');
                        }
                    },
                    error: function (xhr, status) {
                        progress('cs-restore-upload-fill', 'cs-restore-upload-msg', '&#10007; Upload failed: ' + status, 'error');
                        $btn.prop('disabled', false).text('↩ Restore from Upload');
                    }
                });
            }, 'Restore', 'background:#e65100;border-color:#bf360c;color:#fff;');
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
                    csAlert('&#10007;', 'Save Failed', 'The retention settings could not be saved.',
                        '<p>' + csEscHtml(res.data) + '</p>');
                }
            }
        ).always(function () {
            $btn.prop('disabled', false).text('Save Retention');
        });
    });

    // ================================================================
    // Activity Log Viewer
    // ================================================================

    var _csLogSince = 0;
    var _csLogTimer = null;

    function csLogEntry(entry) {
        var d   = new Date(entry.t * 1000);
        var ts  = ('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2)+':'+('0'+d.getSeconds()).slice(-2);
        var msg = entry.m.replace(/\[CloudScale Backup\]\s*/i, '');
        var col = '#9e9e9e';
        if (/error|fail|skip|abort|not enough|denied/i.test(msg))  col = '#ef9a9a';
        else if (/\bok\b|complet|synced|creat|delet.*ok/i.test(msg)) col = '#a5d6a7';
        else if (/start|running|attempt|retry/i.test(msg))           col = '#80cbc4';
        return '<div style="color:' + col + ';white-space:pre-wrap;word-break:break-all;">' +
               '<span style="color:#546e7a;">[' + ts + ']</span> ' +
               $('<span>').text(msg).html() + '</div>';
    }

    function csLoadLog(since) {
        $.ajax({
            url: CS.ajax_url, method: 'POST', timeout: 10000,
            data: { action: 'cs_get_activity_log', nonce: CS.nonce, since: since || 0 },
            success: function (res) {
                if (!res.success) return;
                var entries = res.data.entries || [];
                _csLogSince = res.data.server_time || _csLogSince;
                if (entries.length) {
                    var html = '';
                    entries.forEach(function (e) { html += csLogEntry(e); });
                    if (since) {
                        $('#cs-log-entries').append(html);
                    } else {
                        $('#cs-log-entries').html(html);
                    }
                    $('#cs-log-empty').hide();
                    var body = document.getElementById('cs-log-body');
                    if (body) body.scrollTop = body.scrollHeight;
                } else if (!since) {
                    $('#cs-log-entries').empty();
                    $('#cs-log-empty').show();
                }
                var count = $('#cs-log-entries > div').length;
                $('#cs-log-status').text(count ? count + ' entries' : '');
            }
        });
    }

    $('#cs-log-refresh').on('click', function () { csLoadLog(0); });

    $('#cs-log-clear').on('click', function () {
        $.ajax({
            url: CS.ajax_url, method: 'POST', timeout: 10000,
            data: { action: 'cs_clear_activity_log', nonce: CS.nonce },
            success: function () {
                _csLogSince = 0;
                $('#cs-log-entries').empty();
                $('#cs-log-empty').show();
                $('#cs-log-status').text('');
            }
        });
    });

    csLoadLog(0);
    _csLogTimer = setInterval(function () { csLoadLog(_csLogSince); }, 5000);

});

// ================================================================
// Explain modal — shared helper and per-section functions
// ================================================================

function csDetectErrorType(raw) {
    if (/no space left|disk quota|ENOSPC|quota.?exceeded|out of.?space/i.test(raw))
        return { colour: '#e65100', msg: 'Disk / storage space error \u2014 the server ran out of space. The plugin will attempt to free space automatically on the next run.' };
    if (/errorcode_504|504|gateway.?time.?out/i.test(raw))
        return { colour: '#c62828', msg: '504 Gateway Timeout \u2014 the backup took too long and Cloudflare killed the request. Consider running the backup via WP-Cron (scheduled) rather than the browser, or increasing your Cloudflare timeout.' };
    if (/503|service.?unavailable/i.test(raw))
        return { colour: '#c62828', msg: '503 Service Unavailable \u2014 the server was temporarily unable to handle the request.' };
    if (/502|bad.?gateway/i.test(raw))
        return { colour: '#c62828', msg: '502 Bad Gateway \u2014 the upstream server returned an invalid response.' };
    if (/500|internal.?server.?error/i.test(raw))
        return { colour: '#c62828', msg: '500 Internal Server Error \u2014 a PHP fatal error occurred. Check the server error log for details.' };
    if (/403|forbidden/i.test(raw))
        return { colour: '#c62828', msg: '403 Forbidden \u2014 the request was blocked. Your session may have expired \u2014 try refreshing the page.' };
    if (/login|wp-login/i.test(raw))
        return { colour: '#c62828', msg: 'Redirected to login page \u2014 your session expired. Refresh the page and log in again.' };
    return { colour: '#c62828', msg: 'The server returned a non-JSON response (likely a PHP fatal error or redirect).' };
}

window.csShowRawError = function () {
    var raw = window._csLastRawError || '(no content)';
    var info = csDetectErrorType(raw);
    csShowExplain('Server Error Response',
        '<p style="margin:0 0 10px;color:' + info.colour + ';font-size:0.85rem;font-weight:600;">' + info.msg + '</p>' +
        '<div id="cs-error-frame-wrap" style="width:100%;height:52vh;border:1px solid #ddd;border-radius:4px;overflow:hidden;"></div>'
    );
    // Widen modal for rendered content
    var modal = document.getElementById('cs-explain-modal');
    if (modal) modal.style.maxWidth = '820px';
    var wrap = document.getElementById('cs-error-frame-wrap');
    if (wrap) {
        var iframe = document.createElement('iframe');
        iframe.setAttribute('sandbox', 'allow-same-origin');
        iframe.style.cssText = 'width:100%;height:100%;border:none;background:#fff;display:block;';
        iframe.srcdoc = raw;
        wrap.appendChild(iframe);
    }
};

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
    // Reset width and scroll on every open so each modal starts clean
    $('#cs-explain-modal').css('max-width', '540px');
    $('#cs-explain-body').css({'max-height': '65vh', 'overflow-y': 'auto', 'padding-right': '6px'});
    $('#cs-explain-title').text(title);
    $('#cs-explain-body').html(body);

    // Inject copy buttons into every block <code> snippet
    document.querySelectorAll('#cs-explain-body code').forEach(function (code) {
        if (code.style.display !== 'block') return;
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;';
        code.parentNode.insertBefore(wrap, code);
        wrap.appendChild(code);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'Copy';
        btn.style.cssText = 'position:absolute;top:6px;right:6px;padding:2px 8px;font-size:0.7rem;' +
            'background:rgba(255,255,255,0.15);color:#ccc;border:1px solid rgba(255,255,255,0.3);' +
            'border-radius:3px;cursor:pointer;font-family:inherit;line-height:1.4;';
        btn.addEventListener('click', function () {
            var text = code.textContent || code.innerText;
            var self = this;
            function done(ok) {
                self.textContent = ok ? 'Copied!' : 'Failed';
                self.style.color  = ok ? '#4caf50' : '#ef5350';
                setTimeout(function () { self.textContent = 'Copy'; self.style.color = '#ccc'; }, 1500);
            }
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () { done(true); }).catch(function () { done(false); });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;opacity:0;';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(true); } catch (e) { done(false); }
                document.body.removeChild(ta);
            }
        });
        wrap.appendChild(btn);
    });

    $('#cs-explain-overlay, #cs-explain-modal').show();
}

function csEscHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function csDialog(icon, title, subtitle, body, buttonsHtml) {
    var $ = window.jQuery;
    if (!$('#cs-dialog-overlay').length) {
        $('body').append(
            '<div id="cs-dialog-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:99998;"></div>' +
            '<div id="cs-dialog-modal" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:10px;padding:28px 32px;max-width:520px;width:92%;z-index:99999;box-shadow:0 8px 32px rgba(0,0,0,0.25);font-family:inherit;">' +
            '<div id="cs-dialog-title" style="font-size:1.15rem;font-weight:700;margin:0 0 6px;color:#1a1a1a;"></div>' +
            '<div id="cs-dialog-subtitle" style="font-size:0.92rem;margin:0 0 16px;line-height:1.5;"></div>' +
            '<div id="cs-dialog-body" style="font-size:0.88rem;line-height:1.65;color:#444;margin-bottom:22px;"></div>' +
            '<div id="cs-dialog-buttons" style="display:flex;gap:10px;justify-content:flex-end;"></div>' +
            '</div>'
        );
    }
    $('#cs-dialog-title').html((icon ? icon + '&nbsp;&nbsp;' : '') + csEscHtml(title));
    $('#cs-dialog-subtitle').html(subtitle);
    $('#cs-dialog-body').html(body);
    $('#cs-dialog-buttons').html(buttonsHtml);
    $('#cs-dialog-overlay, #cs-dialog-modal').show();
}

function csAlert(icon, title, subtitle, body) {
    var $ = window.jQuery;
    csDialog(icon, title, subtitle, body,
        '<button type="button" id="cs-dialog-ok" class="button button-primary" style="padding:7px 24px;font-size:0.92rem;border-radius:6px;">OK</button>'
    );
    $('#cs-dialog-ok').off('click').on('click', function () { $('#cs-dialog-overlay, #cs-dialog-modal').hide(); });
    $('#cs-dialog-overlay').off('click').on('click', function () { $('#cs-dialog-overlay, #cs-dialog-modal').hide(); });
}

function csConfirm(icon, title, subtitle, body, onConfirm, confirmLabel, confirmStyle) {
    var $ = window.jQuery;
    var lbl   = confirmLabel || 'Confirm';
    var style = confirmStyle  || 'background:#c62828;border-color:#b71c1c;color:#fff;';
    csDialog(icon, title, subtitle, body,
        '<button type="button" id="cs-dialog-cancel" class="button" style="padding:7px 24px;font-size:0.92rem;border-radius:6px;">Cancel</button>' +
        '<button type="button" id="cs-dialog-ok" class="button" style="padding:7px 24px;font-size:0.92rem;border-radius:6px;' + style + '">' + csEscHtml(lbl) + '</button>'
    );
    $('#cs-dialog-cancel').off('click').on('click', function () { $('#cs-dialog-overlay, #cs-dialog-modal').hide(); });
    $('#cs-dialog-overlay').off('click').on('click', function () { $('#cs-dialog-overlay, #cs-dialog-modal').hide(); });
    $('#cs-dialog-ok').off('click').on('click', function () {
        $('#cs-dialog-overlay, #cs-dialog-modal').hide();
        onConfirm();
    });
}

window.csScheduleExplain = function () {
    csShowExplain('Local Backup Schedule',
        '<p>Automatically runs a file backup on the days and time you choose. The backup packages your selected components (database, media, plugins, themes, etc.) into a single <code>.zip</code> stored on the server.</p>' +
        '<p><strong>Days</strong> — tick one or more days of the week. You can pick multiple days, e.g. Monday, Wednesday, Friday.</p>' +
        '<p><strong>Components</strong> — choose what to include in each scheduled backup. Manual backups always let you choose individually regardless of this setting.</p>' +
        '<p><strong>Time</strong> — when the backup fires (server time). Backups run via WP-Cron, which triggers on the next page load at or after the scheduled time. On low-traffic sites add a real server cron for accurate timing: <code>*/5 * * * * wget -q -O- yoursite.com/wp-cron.php</code></p>' +
        '<p>Leave all days unchecked or disable the toggle to turn off automatic local backups and run manually only.</p>'
    );
};

window.csCloudScheduleExplain = function () {
    csShowExplain('Cloud Backup Settings',
        '<p><strong>What is being backed up?</strong> S3, Google Drive, and Dropbox each copy the <strong>most recent local backup zip</strong> off-site. The cloud backup does not run a new backup — it takes whatever zip was produced by your last local backup run and uploads it. This means <strong>cloud backup days should match (or follow) your local backup days</strong> so there is always a fresh zip to upload.</p>' +
        '<p>The <strong>Cloud Backup Delay</strong> adds a buffer after the local backup time before the cloud tasks fire — set it long enough for your local backup to finish (default 30 min). Example: local backup at 03:00, delay 30 min → cloud tasks fire at 03:30.</p>' +
        '<hr style="margin:10px 0;border:none;border-top:1px solid #e0e0e0;">' +
        '<p><strong>Include in cloud backup</strong> — choose which destinations run on each scheduled day:</p>' +
        '<ul style="margin:6px 0 10px 18px;padding:0;">' +
        '<li><strong>AWS EC2 AMI Snapshot</strong> — creates a full disk-level image of this entire server in AWS (OS, files, database). Not dependent on a local zip. Requires AWS CLI and an IAM role (see AMI Explain).</li>' +
        '<li><strong>AWS S3 Remote Backup</strong> — uploads the most recent local backup zip to your S3 bucket. Requires AWS CLI and a configured bucket.</li>' +
        '<li><strong>Google Drive Backup</strong> — uploads the most recent local backup zip to Google Drive via rclone. Requires rclone and a configured Drive remote.</li>' +
        '<li><strong>Dropbox Backup (Beta)</strong> — uploads the most recent local backup zip to Dropbox via rclone. Requires rclone and a configured Dropbox remote (see Dropbox Explain).</li>' +
        '</ul>' +
        '<p><strong>Tip:</strong> Set cloud backup days to the same days as your local backup, or the day after. If cloud runs on a day with no new local backup, it will re-upload the previous zip.</p>' +
        '<p><strong>Max Cloud Backups to Keep</strong> — applies to S3, Google Drive, Dropbox, and AMIs independently. Once the limit is reached the oldest is deleted automatically. <strong>Golden Images</strong> are excluded and never auto-deleted.</p>' +
        '<p style="margin:0;color:#555;font-size:0.87rem;">Execution order: AMI snapshot → S3 → Google Drive → Dropbox. Runs via WP-Cron.</p>'
    );
};

window.csRetentionExplain = function () {
    csShowExplain('Retention & Storage',
        '<p>Controls how many local backup zips are kept on disk. After every backup (scheduled or manual) the oldest files beyond this limit are deleted automatically. This applies to local backups only — cloud retention is set in Cloud Backup Settings.</p>' +
        '<p><strong>Filename prefix</strong> — prepended to every backup zip name (e.g. <code>mysite_f12.zip</code>). Changing it does not affect existing backups.</p>' +
        '<p><strong>Storage estimate</strong> — based on the size of your most recent backup multiplied by your retention count. If the estimate exceeds free disk space the counter turns red — lower retention or free up space before the next backup.</p>'
    );
};

window.csS3Explain = function () {
    var $ = window.jQuery;
    setTimeout(function () { $('#cs-explain-modal').css('max-width', '620px'); }, 0);
    csShowExplain('AWS S3 Remote Backup',
        '<p>After every local backup, the most recent zip is automatically copied to your S3 bucket. Requires AWS CLI on the server with <code>s3:PutObject</code>, <code>s3:ListBucket</code>, and <code>s3:DeleteObject</code> permissions.</p>' +
        '<p><strong>[Enter Bucket Name]</strong> — bucket name only (no <code>s3://</code>). <strong>[Enter Path Prefix]</strong> — optional subfolder, e.g. <code>backups/prod/</code>. Leave blank for bucket root.</p>' +
        '<hr style="margin:10px 0;border:none;border-top:1px solid #e0e0e0;">' +
        '<p><strong>Buttons:</strong></p>' +
        '<ul style="margin:4px 0 10px 18px;padding:0;">' +
        '<li><strong>Save AWS S3 Settings</strong> — saves the bucket name and prefix.</li>' +
        '<li><strong>Test Connection</strong> — writes a small test file to S3 and verifies it succeeds. Confirms credentials and permissions are correct.</li>' +
        '<li><strong>Diagnose</strong> — shows detailed AWS CLI version, bucket, and last-sync info to help debug connection problems.</li>' +
        '<li><strong>Copy Last Backup to Cloud</strong> — immediately uploads the <em>most recent local backup zip</em> to S3. Use this after running a manual backup, or to push a backup on demand without waiting for the schedule. Does not create a new backup — it copies whatever zip is already on the server.</li>' +
        '</ul>' +
        '<p>If an automatic sync fails, the plugin retries once after 5 minutes.</p>' +
        '<hr style="margin:10px 0;border:none;border-top:1px solid #e0e0e0;">' +
        '<p><strong>Cloud Backup History</strong> — lists every zip currently in your bucket:</p>' +
        '<ul style="margin:4px 0 8px 18px;padding:0;">' +
        '<li><strong>Sync from S3</strong> — queries AWS S3 live. Picks up backups made before this screen existed and removes anything deleted directly in S3.</li>' +
        '<li><strong>Tag (Edit)</strong> — attach a free-text label, e.g. "pre-upgrade". Persists across syncs.</li>' +
        '<li><strong>&#11088; Golden Image</strong> — permanently protected, never auto-deleted, does not count towards the max limit.</li>' +
        '<li><strong>&#8659; Download</strong> — streams the zip directly to your browser from S3.</li>' +
        '<li><strong>&#128465; Delete</strong> — permanently removes the file from S3. Cannot be undone.</li>' +
        '</ul>'
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

    csShowExplain('AWS S3 Diagnostics', '<table style="width:100%;border-collapse:collapse;">' + rows + '</table>');
};

window.csCloudScheduleSave = function () {
    var $ = window.jQuery;
    // Use native DOM to collect checked days
    var days = [];
    document.querySelectorAll('.cs-ami-day-check').forEach(function (el) {
        if (el.checked) days.push(el.value);
    });
    var daysStr = days.join(',');
var $msg = $('#cs-cloud-schedule-msg');

    // Validate delay >= 15 mins
    var delay = parseInt($('#cs-cloud-backup-delay').val() || '30', 10);
    if (isNaN(delay) || delay < 15) {
        $msg.text('\u26A0 Minimum delay is 15 minutes.').css('color', '#c62828').show();
        return;
    }

    $msg.text('Saving\u2026').css('color', '#888').show();
    $.ajax({
        url: CS.ajax_url,
        method: 'POST',
        traditional: true,
        data: {
            action:                 'cs_save_cloud_schedule',
            nonce:                  CS.nonce,
            cloud_schedule_enabled: $('#cs-cloud-schedule-enabled').is(':checked') ? '1' : '0',
            ami_schedule_days:      daysStr,
            cloud_backup_delay:     delay,
            ami_sync_enabled:       $('#cs-cloud-ami-enabled').is(':checked')      ? '1' : '0',
            s3_sync_enabled:        $('#cs-cloud-s3-enabled').is(':checked')       ? '1' : '0',
            gdrive_sync_enabled:    $('#cs-cloud-gdrive-enabled').is(':checked')   ? '1' : '0',
            dropbox_sync_enabled:   $('#cs-cloud-dropbox-enabled').is(':checked')  ? '1' : '0',
            cloud_max:              parseInt($('#cs-ami-max').val() || '10', 10),
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
    setTimeout(function () {
        var $ = window.jQuery;
        $('#cs-explain-modal').css('max-width', '680px');
        $('#cs-explain-body').css({'max-height': '65vh', 'overflow-y': 'auto', 'padding-right': '6px'});
    }, 10);

    function cmd(text) {
        return '<code style="display:block;background:#1e1e1e;color:#d4d4d4;padding:8px 12px;border-radius:4px;margin:4px 0 10px;font-size:0.82rem;white-space:pre;">' + text + '</code>';
    }
    function h(text) { return '<p style="margin:14px 0 4px;font-weight:700;font-size:0.93rem;">' + text + '</p>'; }
    function hr() { return '<hr style="margin:12px 0;border:none;border-top:1px solid #e0e0e0;">'; }

    csShowExplain('AWS EC2 AMI Snapshot — Setup Guide',
        '<p>Creates a full Amazon Machine Image (AMI) of this EC2 instance — a disk-level snapshot of the entire server including OS, web server config, PHP, and all files. Unlike a file backup, an AMI lets you recover an unbootable or completely broken server.</p>' +

        hr() +
        h('Step 1 — Install the AWS CLI on the server') +
        '<p style="margin:0 0 4px;font-size:0.85rem;font-weight:600;">Amazon Linux 2023 / Amazon Linux 2:</p>' +
        cmd('sudo dnf install -y awscli\n# or on older AL2:\nsudo yum install -y awscli') +
        '<p style="margin:0 0 4px;font-size:0.85rem;font-weight:600;">Ubuntu / Debian (ARM):</p>' +
        cmd('curl "https://awscli.amazonaws.com/awscli-exe-linux-aarch64.zip" -o awscliv2.zip\nunzip awscliv2.zip && sudo ./aws/install') +
        '<p style="margin:0 0 4px;font-size:0.85rem;font-weight:600;">Ubuntu / Debian (x86_64):</p>' +
        cmd('curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o awscliv2.zip\nunzip awscliv2.zip && sudo ./aws/install') +
        '<p style="margin:0 0 4px;font-size:0.85rem;font-weight:600;">Verify:</p>' +
        cmd('aws --version') +

        hr() +
        h('Step 2 — Grant AWS credentials (choose one method)') +

        '<p style="margin:8px 0 8px;font-size:0.88rem;font-weight:700;background:#c62828;color:#fff;padding:6px 12px;border-radius:4px;">Option A — IAM Instance Role (recommended, no keys on disk)</p>' +
        '<ol style="margin:0 0 10px 18px;padding:0;font-size:0.88rem;">' +
        '<li>AWS Console → <strong>IAM → Roles → Create role</strong></li>' +
        '<li>Trusted entity: <strong>AWS service → EC2</strong> → Next</li>' +
        '<li>Skip managed policies → <strong>Create role</strong> with a name (e.g. <code>ec2-ami-backup</code>)</li>' +
        '<li>Open the new role → <strong>Add permissions → Create inline policy</strong> → JSON tab → paste:</li>' +
        '</ol>' +
        cmd('{\n  "Version": "2012-10-17",\n  "Statement": [{\n    "Effect": "Allow",\n    "Action": [\n      "ec2:CreateImage",\n      "ec2:DescribeImages",\n      "ec2:DeregisterImage",\n      "ec2:CreateReplaceRootVolumeTask",\n      "ec2:DescribeReplaceRootVolumeTasks",\n      "ec2:RebootInstances"\n    ],\n    "Resource": "*"\n  }]\n}') +
        '<ol style="margin:0 0 10px 18px;padding:0;font-size:0.88rem;" start="5">' +
        '<li>Name the policy (e.g. <code>ec2-ami-backup-policy</code>) → <strong>Create policy</strong></li>' +
        '<li>EC2 Console → select your instance → <strong>Actions → Security → Modify IAM role</strong> → select the role → <strong>Update IAM role</strong></li>' +
        '<li>Verify (no keys needed):</li>' +
        '</ol>' +
        cmd('aws sts get-caller-identity') +

        '<p style="margin:14px 0 8px;font-size:0.88rem;font-weight:700;background:#c62828;color:#fff;padding:6px 12px;border-radius:4px;">Option B — IAM User with access keys (if not on EC2 or role not available)</p>' +
        '<ol style="margin:0 0 10px 18px;padding:0;font-size:0.88rem;">' +
        '<li>AWS Console → <strong>IAM → Users → Create user</strong> (e.g. <code>cloudscale-ami</code>)</li>' +
        '<li>Attach the same inline policy as Option A</li>' +
        '<li>Open the user → <strong>Security credentials → Create access key</strong> → choose <em>Application running on AWS compute</em> or <em>Other</em></li>' +
        '<li>Copy the <strong>Access Key ID</strong> and <strong>Secret Access Key</strong> — shown once only</li>' +
        '<li>SSH into the server and configure as the web server user:</li>' +
        '</ol>' +
        cmd('# Run as the user PHP executes under (apache or www-data)\nsudo -u apache aws configure\n\n# Enter when prompted:\nAWS Access Key ID:     AKIA...\nAWS Secret Access Key: your-secret-key\nDefault region name:   af-south-1   ← your region\nDefault output format: json') +
        '<p style="margin:0 0 4px;font-size:0.85rem;color:#555;">Credentials are stored in <code>/usr/share/httpd/.aws/credentials</code> (apache) or <code>/var/www/.aws/credentials</code> (www-data). Ensure the file is readable only by the web user (<code>chmod 600</code>).</p>' +
        '<p style="margin:4px 0 4px;font-size:0.85rem;font-weight:600;">Verify:</p>' +
        cmd('sudo -u apache aws sts get-caller-identity') +

        hr() +
        h('Step 3 — Configure this plugin') +
        '<ol style="margin:0 0 10px 18px;padding:0;font-size:0.88rem;">' +
        '<li>Enter an AMI name prefix in <strong>[Enter Name Prefix]</strong> (e.g. <code>prod-web01</code>)</li>' +
        '<li>If the detected region is wrong, enter it in <strong>[Enter Region Override]</strong> (e.g. <code>af-south-1</code>)</li>' +
        '<li>Click <strong>Save AMI Settings</strong></li>' +
        '<li>Click <strong>Create AMI Now</strong> to test — status shows <em>pending</em> then <em>available</em> after 5–15 min</li>' +
        '</ol>' +

        hr() +
        '<p><strong>Snapshot table actions:</strong></p>' +
        '<ul style="margin:4px 0 8px 18px;padding:0;font-size:0.88rem;">' +
        '<li><strong>Tag (Edit)</strong> — attach a free-text label, e.g. "pre-upgrade".</li>' +
        '<li><strong>&#11088; Golden Image</strong> — permanently protected, never auto-deleted, does not count towards the max limit.</li>' +
        '<li><strong>Refresh (&#8635;)</strong> — re-queries AWS for current state (pending → available).</li>' +
        '<li><strong>Restore</strong> — replaces the root volume. <strong>All changes since snapshot are permanently lost.</strong> Server reboots.</li>' +
        '<li><strong>&#128465; Delete</strong> — deregisters the AMI from AWS.</li>' +
        '</ul>'
    );
};

window.csRepairTables = function () {
    var $ = window.jQuery;
    var $btn = $('#cs-repair-btn');
    var $msg = $('#cs-repair-msg');
    $btn.prop('disabled', true).text('Repairing\u2026');
    $msg.text('').css('color', '');
    $.post(CS.ajax_url, { action: 'cs_repair_tables', nonce: CS.nonce }, function (res) {
        if (res.success) {
            $msg.text('\u2713 ' + res.data).css('color', '#2e7d32');
        } else {
            $msg.text('\u2717 ' + (res.data || 'Repair failed')).css('color', '#c62828');
        }
        $btn.prop('disabled', false).text('Run Repair Now');
    }).fail(function () {
        $msg.text('\u2717 Request failed').css('color', '#c62828');
        $btn.prop('disabled', false).text('Run Repair Now');
    });
};

window.csRepairExplain = function () {
    csShowExplain('Table Overhead Repair',
        '<p>MySQL (InnoDB) tables accumulate <strong>fragmentation</strong> over time — wasted space left behind after rows are deleted or updated. The overhead figure is the total unused bytes across all tables in your database.</p>' +
        '<ul style="margin:8px 0 8px 18px;padding:0;">' +
        '<li><strong style="color:#2e7d32;">Green (&#8804; 24 MB)</strong> — normal; no action needed.</li>' +
        '<li><strong style="color:#e65100;">Amber (&gt; 24 MB, &lt; 52 MB)</strong> — worth running a repair when convenient.</li>' +
        '<li><strong style="color:#c62828;">Red (&#8805; 52 MB)</strong> — high fragmentation; run repair now to reclaim space and improve performance.</li>' +
        '</ul>' +
        '<p><strong>Run Repair Now</strong> runs <code>OPTIMIZE TABLE</code> on every table with overhead. Tables stay readable during the operation — no downtime. On large databases it may take a minute or two.</p>'
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
    csShowExplain('Create Local Backup Now',
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
    csShowExplain('Local Backup History',
        '<p>All backup zips currently stored on the server, newest first. Use these to restore, download, or manage retention.</p>' +
        '<p><strong>Action icons (left column):</strong></p>' +
        '<ul style="margin:4px 0 10px 18px;padding:0;">' +
        '<li><strong>&#8659; Download</strong> (blue arrow) — saves the zip to your local machine for off-site storage.</li>' +
        '<li><strong>&#8635; Restore</strong> (green arrow) — drops all existing database tables and reimports from this backup. Only shown on Full and DB-only backups. The site enters maintenance mode during the restore and comes back online automatically.</li>' +
        '<li><strong>&#128465; Delete</strong> (red bin) — permanently removes the file from the server. Cannot be undone.</li>' +
        '</ul>' +
        '<p><strong>Row colours:</strong> The newest backup is highlighted green (<em>latest</em>). A <strong style="color:#b71c1c;">Delete Soon</strong> badge marks the oldest kept backup when you are at your retention limit — it will be deleted when the next backup runs. Rows highlighted orange are already over-limit and will be deleted immediately on the next backup run.</p>' +
        '<p><strong>Type column:</strong> <em>Full</em> = all components, <em>DB</em> = database only, or a custom combination of what was selected at backup time.</p>' +
        '<p><strong>Cloud sync columns</strong> (S3 / GDrive / Dropbox): shown when a cloud provider is configured. A green tick means this backup was successfully copied to that provider. A red cross means the sync failed (hover for the error). A dash means no sync has been attempted for this file yet. Configure cloud providers and schedules in the <strong>Cloud Backups</strong> tab.</p>' +
        '<p>Backups are stored in <code>cloudscale-backups/</code> inside <code>wp-content/</code>. The directory is protected by <code>.htaccess</code> to block direct browser access.</p>'
    );
};

window.csCopyBackupPath = function () {
    var pathEl = document.getElementById('cs-backup-path');
    var btn    = document.getElementById('cs-copy-path');
    if (!pathEl || !btn) return;
    var path = pathEl.textContent;
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
    if (!el) return;
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
        catch (e) { window._csLastRawError = xhr.responseText; onDone({ success: false, data: 'Server error \u2014 <button type="button" class="button button-small" onclick="csShowRawError()" style="vertical-align:middle;font-size:0.78rem;padding:1px 8px;margin-left:4px;">View details</button>' }); }
    };
    xhr.onerror = function () { onDone({ success: false, data: 'Network error' }); };
    xhr.send(params);
}

/**
 * Mark a cloud provider as configured in the "Include in cloud backup" checklist.
 * Enables the checkbox, auto-checks it, and removes the "Not configured" label.
 */
function csMarkProviderConfigured(checkboxId) {
    var cb = document.getElementById(checkboxId);
    if (!cb || !cb.disabled) return; // already enabled
    cb.disabled = false;
    cb.checked  = true;
    var label = cb.closest ? cb.closest('label') : cb.parentElement;
    if (label) {
        label.style.opacity = '';
        label.querySelectorAll('span').forEach(function (span) {
            if (span.textContent.indexOf('Not configured') !== -1) span.remove();
        });
    }
}

window.csS3Save = function () {
    var bucket = document.getElementById('cs-s3-bucket').value.trim();
    var prefix = document.getElementById('cs-s3-prefix').value.trim() || 'backups/';
    csS3Msg('Saving...', true);
    csS3Post('cs_save_s3',
        'bucket=' + encodeURIComponent(bucket) + '&prefix=' + encodeURIComponent(prefix),
        function (res) {
            csS3Msg(res.success ? '&#10003; Saved' : '&#10007; ' + res.data, res.success);
            if (res.success && bucket) csMarkProviderConfigured('cs-cloud-s3-enabled');
        }
    );
};

window.csS3Test = function () {
    csS3Msg('Testing\u2026', true);
    csS3Post('cs_test_s3', '', function (res) {
        csS3Msg((res.success ? '&#10003; ' : '&#10007; ') + res.data, res.success);
        if (res.success) csMarkProviderConfigured('cs-cloud-s3-enabled');
    });
};

window.csS3SyncLatest = function () {
    csStartSyncPoll('cs_start_sync_s3', csS3Msg);
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
            else csAlert('&#10007;', 'S3 Sync Failed', 'The file could not be uploaded to AWS S3.',
                '<p>' + csEscHtml(res.data || 'Unknown error') + '</p>');
        }
    });
};

// ================================================================
// Google Drive card functions
// ================================================================

function csGDriveMsg(text, ok) {
    var el = document.getElementById('cs-gdrive-msg');
    if (!el) return;
    el.innerHTML = text;
    el.style.color = ok ? '#2e7d32' : '#c62828';
}

function csGDrivePost(action, extra, onDone) {
    var params = 'action=' + action + '&nonce=' + encodeURIComponent(CS.nonce);
    if (extra) params += '&' + extra;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', CS.ajax_url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        try { onDone(JSON.parse(xhr.responseText)); }
        catch (e) { window._csLastRawError = xhr.responseText; onDone({ success: false, data: 'Server error \u2014 <button type="button" class="button button-small" onclick="csShowRawError()" style="vertical-align:middle;font-size:0.78rem;padding:1px 8px;margin-left:4px;">View details</button>' }); }
    };
    xhr.onerror = function () { onDone({ success: false, data: 'Network error' }); };
    xhr.send(params);
}

window.csGDriveSave = function () {
    var remoteEl = document.getElementById('cs-gdrive-remote');
    var pathEl   = document.getElementById('cs-gdrive-path');
    if (!remoteEl || !pathEl) return;
    var remote = remoteEl.value.trim();
    var path   = pathEl.value.trim() || 'cloudscale-backups/';
    csGDriveMsg('Saving\u2026', true);
    csGDrivePost('cs_save_gdrive',
        'remote=' + encodeURIComponent(remote) + '&path=' + encodeURIComponent(path),
        function (res) {
            csGDriveMsg(res.success ? '&#10003; Saved' : '&#10007; ' + res.data, res.success);
            if (res.success && remote) csMarkProviderConfigured('cs-cloud-gdrive-enabled');
        }
    );
};

window.csGDriveTest = function () {
    csGDriveMsg('Testing\u2026', true);
    csGDrivePost('cs_test_gdrive', '', function (res) {
        csGDriveMsg((res.success ? '&#10003; ' : '&#10007; ') + res.data, res.success);
        if (res.success) csMarkProviderConfigured('cs-cloud-gdrive-enabled');
    });
};

window.csGDriveSyncLatest = function () {
    csCheckSpaceThenSync('gdrive', 'cs_start_sync_gdrive', csGDriveMsg);
};

window.csGDriveExplain = function () {
    // Widen the modal for this long guide
    var $ = window.jQuery;
    setTimeout(function () {
        $('#cs-explain-modal').css('max-width', '680px');
        $('#cs-explain-body').css({'max-height': '65vh', 'overflow-y': 'auto', 'padding-right': '6px'});
    }, 10);

    function cmd(text) {
        return '<code style="display:block;background:#1e1e1e;color:#d4d4d4;padding:8px 12px;border-radius:4px;margin:4px 0 10px;font-size:0.82rem;white-space:pre;">' + text + '</code>';
    }
    function note(text) {
        return '<p style="margin:0 0 10px;color:#555;font-size:0.88rem;">' + text + '</p>';
    }
    function h(text) {
        return '<p style="margin:14px 0 4px;font-weight:700;font-size:0.93rem;">' + text + '</p>';
    }
    function hr() { return '<hr style="margin:12px 0;border:none;border-top:1px solid #e0e0e0;">'; }

    csShowExplain('Google Drive Backup — Setup Guide',
        '<p style="margin:0 0 8px;">After every local backup, the most recent backup zip is automatically copied to your Google Drive via <strong>rclone</strong>. Setup takes about 5 minutes and only needs to be done once.</p>' +
        '<p style="margin:0 0 4px;"><strong>Buttons:</strong></p>' +
        '<ul style="margin:0 0 10px 18px;padding:0;">' +
        '<li><strong>Save Drive Settings</strong> — saves the rclone remote name and destination folder.</li>' +
        '<li><strong>Test Connection</strong> — runs <code>rclone lsd</code> to verify the remote exists and is reachable.</li>' +
        '<li><strong>Diagnose</strong> — shows rclone version, remote name, and last-sync details to help debug problems.</li>' +
        '<li><strong>Copy Last Backup to Cloud</strong> — immediately copies the <em>most recent local backup zip</em> to Google Drive. Use this after a manual backup or to push on demand without waiting for the schedule. Does not create a new backup — it copies whatever zip is already on the server.</li>' +
        '</ul>' +
        '<hr style="margin:10px 0;border:none;border-top:1px solid #e0e0e0;">' +
        '<p style="margin:0 0 4px;"><strong>Cloud Backup History</strong> — lists every zip currently on your Drive. Actions:</p>' +
        '<ul style="margin:0 0 10px 18px;padding:0;">' +
        '<li><strong>Sync from Google Drive</strong> — queries Drive live. Picks up backups made before this screen existed and removes anything deleted directly in Drive.</li>' +
        '<li><strong>Tag (Edit)</strong> — attach a free-text label to any file, e.g. "pre-upgrade".</li>' +
        '<li><strong>&#11088; Golden Image</strong> — mark up to 4 files as permanently protected. Never auto-deleted, do not count towards <em>Max Cloud Backups to Keep</em>.</li>' +
        '<li><strong>&#8659; Download</strong> — uses rclone to pull the file from Drive to a temp file, then streams it to your browser.</li>' +
        '<li><strong>&#128465; Delete</strong> — permanently removes the file from Google Drive. Cannot be undone.</li>' +
        '</ul>' +
        hr() +

        h('Step 1 — Install rclone on the server') +
        note('SSH in, then run:') +
        cmd('curl -fsSL https://rclone.org/install.sh | sudo bash') +
        note('You\'ll see a long install log ending with:') +
        '<code style="display:block;background:#1e1e1e;color:#98c379;padding:8px 12px;border-radius:4px;margin:4px 0 10px;font-size:0.82rem;">rclone v1.73.2 has successfully installed.\nNow run "rclone config" for setup.</code>' +

        hr() +
        h('Step 2 — Fix apache home directory permissions') +
        note('rclone saves its config in apache\'s home folder. Run these two commands first or it will fail silently:') +
        cmd('sudo mkdir -p /usr/share/httpd/.config/rclone\nsudo chown -R apache:apache /usr/share/httpd/.config\nsudo chmod 700 /usr/share/httpd/.config/rclone\nsudo chown apache:apache /usr/share/httpd\nsudo chmod 755 /usr/share/httpd') +

        hr() +
        h('Step 3 — Run the setup wizard as the apache user') +
        cmd('sudo -u apache rclone config') +
        note('You\'ll see:') +
        '<code style="display:block;background:#1e1e1e;color:#d4d4d4;padding:8px 12px;border-radius:4px;margin:4px 0 10px;font-size:0.82rem;">2026/03/17 23:51:30 NOTICE: Config file "/usr/share/httpd/.rclone.conf" not found - using defaults\nNo remotes found, make a new one?\nn) New remote\ns) Set configuration password\nq) Quit config</code>' +

        hr() +
        h('Step 4 — Answer every prompt') +
        '<table style="width:100%;border-collapse:collapse;font-size:0.87rem;margin-bottom:10px;">' +
        '<tr style="background:#f5f5f5;"><th style="padding:5px 8px;text-align:left;font-weight:600;">Prompt</th><th style="padding:5px 8px;text-align:left;font-weight:600;">Type</th></tr>' +
        '<tr><td style="padding:5px 8px;border-top:1px solid #eee;"><em>n/s/q</em></td><td style="padding:5px 8px;border-top:1px solid #eee;"><code>n</code></td></tr>' +
        '<tr style="background:#fafafa;"><td style="padding:5px 8px;border-top:1px solid #eee;">name&gt;</td><td style="padding:5px 8px;border-top:1px solid #eee;"><code>gdrive</code></td></tr>' +
        '<tr><td style="padding:5px 8px;border-top:1px solid #eee;">Storage&gt;</td><td style="padding:5px 8px;border-top:1px solid #eee;"><code>24</code> (Google Drive)</td></tr>' +
        '<tr style="background:#fafafa;"><td style="padding:5px 8px;border-top:1px solid #eee;">client_id&gt;</td><td style="padding:5px 8px;border-top:1px solid #eee;">Enter (blank)</td></tr>' +
        '<tr><td style="padding:5px 8px;border-top:1px solid #eee;">client_secret&gt;</td><td style="padding:5px 8px;border-top:1px solid #eee;">Enter (blank)</td></tr>' +
        '<tr style="background:#fafafa;"><td style="padding:5px 8px;border-top:1px solid #eee;">scope&gt;</td><td style="padding:5px 8px;border-top:1px solid #eee;"><code>1</code> (full access)</td></tr>' +
        '<tr><td style="padding:5px 8px;border-top:1px solid #eee;">service_account_file&gt;</td><td style="padding:5px 8px;border-top:1px solid #eee;">Enter (blank)</td></tr>' +
        '<tr style="background:#fafafa;"><td style="padding:5px 8px;border-top:1px solid #eee;">Edit advanced config?</td><td style="padding:5px 8px;border-top:1px solid #eee;"><code>n</code></td></tr>' +
        '<tr><td style="padding:5px 8px;border-top:1px solid #eee;">Use web browser?</td><td style="padding:5px 8px;border-top:1px solid #eee;"><code>n</code> (no browser on server)</td></tr>' +
        '</table>' +

        hr() +
        h('Step 5 — Authorise on your laptop') +
        note('The server will print: <em>Execute the following on the machine with the web browser:</em>') +
        note('Open a <strong>new terminal on your laptop</strong> (leave the SSH session open). If rclone is not installed on your laptop, install it first:') +
        cmd('brew install rclone') +
        note('Then run:') +
        cmd('rclone authorize "drive"') +
        note('Your browser opens — sign in to Google, click Allow. The laptop terminal prints:') +
        '<code style="display:block;background:#1e1e1e;color:#d4d4d4;padding:8px 12px;border-radius:4px;margin:4px 0 6px;font-size:0.78rem;">Paste the following into your remote machine ---&gt;\n{"access_token":"ya29.YOUR_ACCESS_TOKEN","token_type":"Bearer","refresh_token":"YOUR_REFRESH_TOKEN","expiry":"2026-03-18T01:01:25+02:00","expires_in":3599}\n&lt;---End paste</code>' +
        note('Copy <strong>the entire JSON block</strong> (from <code>{</code> to <code>}</code>) and paste it into the SSH session at the <code>config_token&gt;</code> prompt.') +

        hr() +
        h('Step 6 — Finish the wizard') +
        note('Answer <code>n</code> to "Configure as Shared Drive", <code>y</code> to confirm, <code>q</code> to quit.') +
        note('You should see: <em>Current remotes: gdrive / drive</em>') +

        hr() +
        h('⚠ Fixing a permission error (if you see it)') +
        note('If the wizard prints <em>permission denied</em> when saving config, run these commands on the server then redo from Step 3:') +
        cmd('sudo mkdir -p /usr/share/httpd/.config/rclone\nsudo bash -c \'cat > /usr/share/httpd/.config/rclone/rclone.conf << \\\'EOF\\\'\n[gdrive]\ntype = drive\nscope = drive\ntoken = PASTE_YOUR_JSON_TOKEN_HERE\nEOF\'\nsudo chown apache:apache /usr/share/httpd/.config/rclone/rclone.conf\nsudo chmod 600 /usr/share/httpd/.config/rclone/rclone.conf') +
        note('Replace <code>PASTE_YOUR_JSON_TOKEN_HERE</code> with the full JSON token from Step 5.') +

        hr() +
        h('Step 7 — Verify connection') +
        note('A working connection lists your Drive folders:') +
        '<code style="display:block;background:#1e1e1e;color:#d4d4d4;padding:8px 12px;border-radius:4px;margin:4px 0 10px;font-size:0.78rem;white-space:pre;">           0 2017-07-10 14:08:48        -1 AWS\n           0 2019-06-10 11:29:32        -1 Diskstation\n           0 2018-10-15 11:07:22        -1 House\n           0 2025-05-26 09:25:56        -1 Saved from Chrome\n           0 2016-07-09 12:03:19        -1 Tax</code>' +

        hr() +
        h('Step 8 — Save settings here') +
        note('Enter your rclone remote name in the <em>[Enter Remote Name]</em> field (e.g. <code>gdrive</code>), enter a destination folder in <em>[Enter Folder Path]</em> (e.g. <code>cloudscale-backups/</code>), click <em>Save Drive Settings</em>, then <em>Test Connection</em>.')
    );
};

window.csGDriveDiagnose = function () {
    var d = window.CS_GDRIVE_DIAG || {};
    var ok  = '<span style="color:#2e7d32;font-weight:700;">&#10003;</span>';
    var err = '<span style="color:#c62828;font-weight:700;">&#10007;</span>';

    function row(icon, label, value, desc) {
        return '<tr>' +
            '<td style="padding:6px 8px 2px 0;white-space:nowrap;vertical-align:top;">' + icon + ' <strong>' + label + '</strong></td>' +
            '<td style="padding:6px 0 2px 8px;vertical-align:top;"><code style="word-break:break-all;">' + value + '</code></td>' +
            '</tr>' +
            '<tr><td colspan="2" style="padding:0 0 10px 20px;font-size:0.82rem;color:#666;">' + desc + '</td></tr>';
    }

    var rows = row(
        d.rclone_found ? ok : err,
        'rclone',
        d.rclone_version || 'Not found',
        'rclone binary on the server. Required for Google Drive sync. Install with: <code>curl https://rclone.org/install.sh | sudo bash</code>'
    );
    rows += row(
        d.remote ? ok : err,
        'Remote',
        d.remote || 'Not configured',
        'The rclone remote name. Configure with <code>rclone config</code> run as the web server user.'
    );
    if (d.remote) {
        rows += row(
            ok,
            'Destination',
            d.remote + ':' + d.path,
            'Full rclone path where backup zips are copied after each backup.'
        );
        rows += row(
            d.synced > 0 ? ok : err,
            'Backups synced',
            d.synced + ' of ' + d.total,
            d.synced === d.total
                ? 'All backups have been successfully synced to Google Drive.'
                : 'Some backups have not been synced. Run a backup to trigger a sync.'
        );
        rows += row(
            d.last_fmt ? ok : err,
            'Last sync',
            d.last_fmt || 'Never',
            'When the most recent successful upload to Google Drive completed.'
        );
    }

    csShowExplain('Google Drive Diagnostics', '<table style="width:100%;border-collapse:collapse;">' + rows + '</table>');
};

// ================================================================
// AMI card functions
// ================================================================

function csAmiMsg(text, ok) {
    // Write to both: settings card msg (always visible when using the card) and
    // history panel msg (visible when AMI pane is selected). Using || would write
    // to the hidden history-panel element and show nothing when the AMI pane is inactive.
    var color = ok ? '#2e7d32' : '#c62828';
    ['cs-ami-settings-msg', 'cs-ami-msg'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.innerHTML = text; el.style.color = color; }
    });
}

function csAmiPost(action, extra, onDone) {
    csS3Post(action, extra, onDone); // same transport, reuse helper
}

window.csAmiSave = function () {
    var prefixEl = document.getElementById('cs-ami-prefix');
    var rebootEl = document.getElementById('cs-ami-reboot');
    var regionEl = document.getElementById('cs-ami-region-override');
    if (!prefixEl || !rebootEl || !regionEl) return;
    var prefix         = prefixEl.value.trim();
    var reboot         = rebootEl.checked ? '1' : '0';
    var regionOverride = regionEl.value.trim();
    csAmiMsg('Saving...', true);
    csAmiPost('cs_save_ami',
        'prefix=' + encodeURIComponent(prefix) +
        '&reboot=' + reboot +
        '&region_override=' + encodeURIComponent(regionOverride),
        function (res) {
            csAmiMsg(res.success ? '&#10003; Saved' : '&#10007; ' + res.data, res.success);
            if (res.success && prefix) csMarkProviderConfigured('cs-cloud-ami-enabled');
        }
    );
};

window.csAmiCreate = function () {
    var prefixEl = document.getElementById('cs-ami-prefix');
    var rebootEl = document.getElementById('cs-ami-reboot');
    if (!prefixEl || !rebootEl) return;
    var prefix = prefixEl.value.trim();
    if (!prefix) { csAmiMsg('&#10007; Enter an AMI name prefix first.', false); return; }
    var reboot = rebootEl.checked;
    csConfirm('&#128247;', 'Create AMI Snapshot',
        'A new AMI will be created from the current state of this EC2 instance.',
        '<p>The AMI will be named using the prefix <strong>' + csEscHtml(prefix) + '</strong> and stored in your AWS account.</p>' +
        (reboot ? '<p><strong style="color:#c62828;">&#9888; The instance will be REBOOTED.</strong> This causes brief downtime — ensure you can tolerate an interruption before proceeding.</p>' : '') +
        '<p>AMI creation runs in the background. The table refreshes automatically when complete.</p>',
        function () {
            csAmiMsg('Creating AMI\u2026 this may take a moment.', true);
            csAmiPost('cs_create_ami', '', function (res) {
                if (res.success) {
                    csAmiMsg('&#10003; ' + (res.data.message || 'AMI created'), true);
                    setTimeout(function () { location.reload(); }, 2000);
                } else {
                    csAmiMsg('&#10007; ' + (res.data || 'AMI creation failed'), false);
                }
            });
        },
        reboot ? 'Create AMI (Reboot)' : 'Create AMI',
        reboot ? 'background:#c62828;border-color:#b71c1c;color:#fff;' : 'background:#2e7d32;border-color:#1b5e20;color:#fff;');
};

window.csAmiStatus = function () {
    csAmiMsg('Checking\u2026', true);
    csAmiPost('cs_ami_status', '', function (res) {
        csAmiMsg((res.success ? '&#10003; ' + res.data : '&#10007; ' + (res.data || 'Could not check status')), res.success);
    });
};

function csAmiRefreshAll() {
    var btn  = document.getElementById('cs-ami-refresh-all');
    var rows = Array.from(document.querySelectorAll('[id^="cs-ami-row-"]'));
    var total = rows.length;

    if (total === 0) {
        csAmiMsg('No AMIs to refresh', true);
        return;
    }

    if (btn) { btn.disabled = true; btn.innerHTML = '&#8635; Refresh All'; }

    var current = 0;

    function updateRow(amiId, state, name) {
        var stateCell   = document.getElementById('cs-ami-state-' + amiId);
        var actionsCell = document.getElementById('cs-ami-actions-' + amiId);
        var row         = document.getElementById('cs-ami-row-' + amiId);
        if (!stateCell) return;
        var safeName = (name || '').replace(/'/g, '');
        if (state === 'deleted in AWS') {
            if (row) row.querySelectorAll('td:not(:last-child)').forEach(function (td) { td.style.opacity = '0.45'; });
            stateCell.innerHTML = '<span style="color:#999;font-weight:600;">&#128465; deleted in AWS</span>';
            if (actionsCell) actionsCell.innerHTML = '<button type="button" onclick="csAmiDelete(\'' + amiId + '\',\'' + safeName + '\',true)" class="button button-small" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Remove</button>';
        } else {
            if (row) { row.querySelectorAll('td').forEach(function (td) { td.style.opacity = ''; }); row.classList.remove('cs-row-golden'); }
            var color = state === 'available' ? '#2e7d32' : (state === 'pending' ? '#e65100' : '#757575');
            var icon  = state === 'available' ? '&#10003;' : (state === 'pending' ? '&#9203;' : '&#10007;');
            stateCell.innerHTML = '<span style="color:' + color + ';font-weight:600;">' + icon + ' ' + state + '</span>';
            var isGolden    = row && row.dataset && row.dataset.golden === '1';
            var goldenStyle = isGolden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '';
            var goldenTitle = isGolden ? 'Remove Golden Image' : 'Mark as Golden Image';
            var goldenBtn   = '<button type="button" onclick="csAmiSetGolden(\'' + amiId + '\')" class="button button-small" id="cs-ami-golden-btn-' + amiId + '" data-golden="' + (isGolden ? '1' : '0') + '" title="' + goldenTitle + '" style="min-width:0;padding:2px 6px;margin-bottom:3px;' + goldenStyle + '">&#11088;</button>';
            var restoreBtn  = state === 'available'
                ? '<button type="button" onclick="csAmiRestore(\'' + amiId + '\',\'' + safeName + '\')" class="button button-small" title="Restore server to this AMI snapshot" style="min-width:0;padding:2px 8px;color:#1a237e;border-color:#1a237e;margin-bottom:3px;">&#8617; Restore</button> '
                : '';
            var svgRefresh  = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';
            var refreshBtn  = '<button type="button" onclick="csAmiRefreshOne(\'' + amiId + '\')" class="button button-small" title="Refresh this AMI state from AWS" style="min-width:0;padding:2px 6px;margin-bottom:3px;">' + svgRefresh + '</button>';
            if (actionsCell) { actionsCell.style.whiteSpace = 'nowrap'; actionsCell.style.verticalAlign = 'middle'; actionsCell.innerHTML = refreshBtn + goldenBtn + restoreBtn + '<button type="button" onclick="csAmiDelete(\'' + amiId + '\',\'' + safeName + '\',false)" class="button button-small" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Delete</button>'; }
        }
    }

    function processNext() {
        if (current >= total) {
            if (btn) { btn.disabled = false; btn.innerHTML = '&#8635; Refresh All'; }
            csAmiMsg('&#10003; Refreshed ' + total + ' AMI' + (total !== 1 ? 's' : ''), true);
            return;
        }
        var amiId = rows[current].id.replace('cs-ami-row-', '');
        current++;
        csAmiMsg('Refreshing ' + current + ' of ' + total + '\u2026', true);

        var stateCell = document.getElementById('cs-ami-state-' + amiId);
        if (stateCell) stateCell.innerHTML = '<span style="color:#888;">&#8635; checking\u2026</span>';

        csAmiPost('cs_ami_status', 'ami_id=' + encodeURIComponent(amiId), function (res) {
            if (res.success && res.data && res.data.state) {
                updateRow(amiId, res.data.state, res.data.name || '');
            }
            processNext();
        });
    }

    processNext();
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
        csConfirm('&#128465;', 'Remove Log Entry',
            'This record will be removed from the AMI history table.',
            '<p>AMI: <strong>' + csEscHtml(label) + '</strong></p>' +
            '<p>This AMI has already been deleted in AWS. Removing it cleans up the local log — no changes are made in AWS.</p>',
            function () {
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
            }, 'Remove');
        return;
    }
    csConfirm('&#128465;', 'Deregister AMI',
        '<span style="color:#c62828;">This AMI will be permanently deregistered in AWS.</span>',
        '<p>AMI: <strong>' + csEscHtml(label) + '</strong></p>' +
        '<p>Deregistering removes the AMI from your AWS account. The instance can no longer be launched from this image.</p>' +
        '<p><strong>Note:</strong> Associated EBS snapshots are <strong>not deleted automatically</strong> — remove them manually in the AWS console if needed.</p>' +
        '<p>This action <strong>cannot be undone</strong>.</p>',
        function () {
            csAmiMsg('Deregistering ' + amiId + '\u2026', true);
            // Show pending state immediately
            var stateCell = document.getElementById('cs-ami-state-' + amiId);
            if (stateCell) stateCell.innerHTML = '<span style="color:#e65100;font-weight:600;">&#9203; Pending Delete\u2026</span>';
            csAmiPost('cs_deregister_ami', 'ami_id=' + encodeURIComponent(amiId), function (res) {
                if (res.success) {
                    csAmiMsg('&#10003; Delete requested — status will update in 15 minutes', true);
                    // Keep row visible with pending state; cron will clean up
                    var actionsCell = document.getElementById('cs-ami-actions-' + amiId);
                    if (actionsCell) actionsCell.innerHTML = '<span style="color:#888;font-size:0.82rem;">Pending\u2026</span>';
                } else {
                    // Revert state on failure
                    if (stateCell) stateCell.innerHTML = '<span style="color:#c62828;font-weight:600;">&#10007; Delete failed</span>';
                    csAmiMsg('&#10007; ' + (res.data || 'Deregister failed'), false);
                }
            });
        }, 'Deregister');
};

window.csAmiRestore = function (amiId, amiName) {
    var $ = window.jQuery;
    var modalId = 'cs-ami-restore-modal';
    $('#cs-ami-restore-overlay, #' + modalId).remove();

    var safeName = $('<span>').text(amiName || amiId).html();
    var safeId   = $('<span>').text(amiId).html();

    $('body').append(
        '<div id="cs-ami-restore-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:99998;"></div>' +
        '<div id="' + modalId + '" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);' +
            'background:#fff;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,0.35);' +
            'z-index:99999;padding:28px 32px;max-width:540px;width:92vw;">' +
            '<div style="background:#b71c1c;color:#fff;border-radius:7px;padding:14px 16px;' +
                'margin-bottom:18px;display:flex;align-items:flex-start;gap:10px;">' +
                '<span style="font-size:1.6rem;line-height:1;flex-shrink:0;">&#9888;</span>' +
                '<div>' +
                    '<strong style="font-size:1rem;display:block;margin-bottom:5px;">WARNING: All recent changes will be permanently lost</strong>' +
                    '<span style="font-size:0.88rem;line-height:1.55;">' +
                        'Restoring to this AMI snapshot replaces the entire root volume of this EC2 instance ' +
                        'with a fresh copy from the snapshot. <strong>Every change made since this snapshot was taken — ' +
                        'files, database, uploads, OS configuration — will be gone forever.</strong> ' +
                        'The server will reboot as part of the restore process.' +
                    '</span>' +
                '</div>' +
            '</div>' +
            '<p style="margin:0 0 4px;font-size:0.9rem;"><strong>Snapshot:</strong> ' + safeName + '</p>' +
            '<p style="margin:0 0 16px;font-size:0.9rem;"><strong>AMI ID:</strong> <code>' + safeId + '</code></p>' +
            '<label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:0.9rem;margin-bottom:20px;">' +
                '<input type="checkbox" id="cs-ami-restore-chk" style="margin-top:3px;flex-shrink:0;">' +
                '<span>I understand that <strong>all changes since this snapshot will be permanently lost</strong> ' +
                'and the server will reboot. I want to proceed.</span>' +
            '</label>' +
            '<div style="display:flex;gap:10px;justify-content:flex-end;">' +
                '<button type="button" id="cs-ami-restore-cancel" class="button" style="min-width:80px;">Cancel</button>' +
                '<button type="button" id="cs-ami-restore-go" class="button button-primary" disabled ' +
                    'style="background:#b71c1c!important;border-color:#b71c1c!important;min-width:130px;">' +
                    '&#8617; Restore Now' +
                '</button>' +
            '</div>' +
            '<p id="cs-ami-restore-status" style="margin:12px 0 0;font-size:0.85rem;font-weight:600;min-height:1.3em;"></p>' +
        '</div>'
    );

    function close() {
        $('#cs-ami-restore-overlay, #' + modalId).remove();
    }

    $('#cs-ami-restore-overlay, #cs-ami-restore-cancel').on('click', close);

    $('#cs-ami-restore-chk').on('change', function () {
        $('#cs-ami-restore-go').prop('disabled', !this.checked);
    });

    $('#cs-ami-restore-go').on('click', function () {
        var $btn    = $(this);
        var $status = $('#cs-ami-restore-status');
        $btn.prop('disabled', true).text('Sending request\u2026');
        $status.css('color', '#1565c0').text('Sending restore request to AWS\u2026');

        csAmiPost('cs_ami_restore', 'ami_id=' + encodeURIComponent(amiId), function (res) {
            if (res.success) {
                $status.css('color', '#2e7d32').html(
                    '&#10003; Restore task created (task ID: ' + (res.data && res.data.task_id ? res.data.task_id : 'n/a') + '). ' +
                    'The server will reboot shortly. <strong>This page will become unreachable during the reboot.</strong>'
                );
                $btn.text('Done');
                $('#cs-ami-restore-cancel').text('Close');
                csAmiMsg('&#10003; AMI restore task initiated \u2014 server rebooting', true);
            } else {
                $status.css('color', '#c62828').text('\u2717 ' + (res.data || 'Restore failed'));
                $btn.prop('disabled', false).text('&#8617; Restore Now');
                $('#cs-ami-restore-chk').prop('checked', false);
            }
        });
    });
};

// ================================================================
// AMI tag and golden image functions
// ================================================================

window.csAmiTagEdit = function (amiId, currentTag) {
    var $ = window.jQuery;
    var cell = document.getElementById('cs-ami-tag-cell-' + amiId);
    if (!cell) return;
    var saved = cell.innerHTML;
    // Use querySelectorAll to avoid jQuery CSS-selector escaping issues with hyphens
    cell.innerHTML =
        '<input type="text" value="' + $('<span>').text(currentTag).html() + '" ' +
        'style="width:90px;font-size:0.8rem;padding:1px 4px;vertical-align:middle;" maxlength="40"> ' +
        '<button type="button" class="button button-small" style="padding:1px 6px;font-size:0.78rem;">Save</button> ' +
        '<button type="button" class="button button-small" style="padding:1px 6px;font-size:0.78rem;">\u00d7</button>';
    var btns      = cell.querySelectorAll('button');
    var inp       = cell.querySelector('input');
    var saveBtn   = btns[0];
    var cancelBtn = btns[1];
    if (inp) inp.focus();
    cancelBtn.addEventListener('click', function () { cell.innerHTML = saved; });
    var doSave = function () {
        var tag = inp ? inp.value.trim().substring(0, 40) : '';
        csAmiPost('cs_ami_set_tag', 'ami_id=' + encodeURIComponent(amiId) + '&tag=' + encodeURIComponent(tag), function (res) {
            if (res.success) {
                var tagJs = tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                cell.innerHTML =
                    '<span class="cs-ami-tag-text">' + (tag ? $('<span>').text(tag).html() : '<span class="cs-muted-text">No tag</span>') + '</span> ' +
                    '<button type="button" onclick="csAmiTagEdit(\'' + amiId + '\',\'' + tagJs + '\')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>';
                csAmiMsg('\u2713 Tag saved', true);
            } else {
                cell.innerHTML = saved;
                csAmiMsg('\u2717 ' + (res.data || 'Save failed'), false);
            }
        });
    };
    saveBtn.addEventListener('click', doSave);
    if (inp) {
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter')  { doSave(); }
            if (e.key === 'Escape') { cell.innerHTML = saved; }
        });
    }
};

window.csAmiSetGolden = function (amiId) {
    csAmiPost('cs_ami_set_golden', 'ami_id=' + encodeURIComponent(amiId), function (res) {
        if (res.success) {
            var isGolden = !!(res.data && res.data.golden);
            var btn = document.getElementById('cs-ami-golden-btn-' + amiId);
            if (btn) {
                btn.dataset.golden = isGolden ? '1' : '0';
                btn.title = isGolden ? 'Remove Golden Image' : 'Mark as Golden Image';
                btn.style.color       = isGolden ? '#f57f17' : '';
                btn.style.borderColor = isGolden ? '#f57f17' : '';
                btn.style.fontWeight  = isGolden ? '700'     : '';
            }
            var row = document.getElementById('cs-ami-row-' + amiId);
            if (row) {
                row.classList.toggle('cs-row-golden', isGolden);
                
                
                var star = row.querySelector('.cs-ami-golden-star');
                if (star) star.style.display = isGolden ? '' : 'none';
            }
            csAmiUpdateGoldenCount();
            csAmiMsg(isGolden ? '&#11088; Marked as golden image' : 'Golden image removed', true);
        } else {
            csAmiMsg('\u2717 ' + (res.data || 'Failed'), false);
        }
    });
};

function csAmiUpdateGoldenCount() {
    var el = document.getElementById('cs-ami-golden-count');
    if (!el) return;
    var goldenCount   = document.querySelectorAll('[id^="cs-ami-golden-btn-"][data-golden="1"]').length;
    var totalRows     = document.querySelectorAll('#cs-ami-tbody tr[id^="cs-ami-row-"]').length;
    var nonGolden     = totalRows - goldenCount;
    var amiMax        = (window.CS && CS.ami_max) ? parseInt(CS.ami_max, 10) : '?';
    el.innerHTML = '&#11088; ' + goldenCount + ' / 4 golden\u2003' + nonGolden + ' of ' + amiMax + ' max backups';
}

window.csAmiRemoveFailed = function (name) {
    csConfirm('&#128465;', 'Remove Failed Record',
        'This entry will be removed from the AMI history table.',
        '<p>AMI name: <strong>' + csEscHtml(name) + '</strong></p>' +
        '<p>This record represents a failed AMI creation attempt. Removing it cleans up the log — no changes are made in AWS.</p>',
        function () {
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
        }, 'Remove');
};

// ================================================================
// S3 History functions
// ================================================================

function csS3HMsg(text, ok) {
    var el = document.getElementById('cs-s3h-msg');
    if (!el) return;
    el.textContent = text;
    el.style.color = ok ? '#2e7d32' : '#c62828';
}

function csS3HPost(action, extra, onDone) {
    csS3Post(action, extra, onDone); // reuse S3 transport
}

function csS3HUpdateGoldenCount() {
    var el = document.getElementById('cs-s3h-golden-count');
    if (!el) return;
    var goldenCount = document.querySelectorAll('[id^="cs-s3h-golden-btn-"][data-golden="1"]').length;
    var totalRows   = document.querySelectorAll('#cs-s3h-tbody tr').length;
    var nonGolden   = totalRows - goldenCount;
    var s3Max       = (window.CS && CS.ami_max) ? parseInt(CS.ami_max, 10) : '?';
    el.innerHTML = '&#11088; ' + goldenCount + ' / 4 golden\u2003' + nonGolden + ' of ' + s3Max + ' max backups';
}

window.csS3HistoryRefresh = function () {
    var btn = document.getElementById('cs-s3h-refresh-btn');
    if (btn) { btn.disabled = true; btn.textContent = '\u23f3 Querying S3\u2026'; }
    csS3HMsg('Refreshing\u2026', true);
    csS3HPost('cs_s3_refresh_history', '', function (res) {
        if (btn) { btn.disabled = false; btn.innerHTML = '&#8635; Refresh'; }
        if (!res.success) {
            csS3HMsg('\u2717 ' + (res.data || 'Refresh failed'), false);
            return;
        }
        var files  = (res.data && res.data.files) ? res.data.files : (res.data || []);
        var count  = (res.data && res.data.count != null) ? res.data.count : files.length;
        var cEl    = document.getElementById('cs-s3-count-val');
        if (cEl) cEl.innerHTML = count + ' in bucket &nbsp;&middot;&nbsp; ' + cEl.innerHTML.replace(/.*·\s*/, '');
        var tbody  = document.getElementById('cs-s3h-tbody');
        var table  = document.getElementById('cs-s3h-table');
        var $ = window.jQuery;
        if (!tbody) {
            // Table doesn't exist yet — reload page to show it
            csS3HMsg('\u2713 Done — reloading\u2026', true);
            setTimeout(function () { location.reload(); }, 1200);
            return;
        }
        tbody.innerHTML = '';
        files.forEach(function (sf) {
            var name      = sf.name || '';
            // keyE must NOT include dots (jQuery $() treats '.' as class selector)
            var keyE      = name.replace(/[^a-zA-Z0-9_\-]/g, '_');
            var nameJs    = name.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            var tag       = sf.tag || '';
            var tagJs     = tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            var isGolden  = !!sf.golden;
            var goldenD   = isGolden ? '1' : '0';
            var goldenSt  = isGolden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '';
            var goldenTit = isGolden ? 'Remove Golden Image' : 'Mark as Golden Image';
            var dlUrl     = CS.admin_post_url + '?action=cs_s3_download&file=' + encodeURIComponent(name) + '&nonce=' + CS.nonce;
            var dlBtn     = '<a href="' + dlUrl + '" class="button button-small" style="min-width:0;padding:2px 6px;margin-bottom:3px;text-decoration:none;display:inline-block;">&#8659; Download</a> ';
            var tr = document.createElement('tr');
            tr.id = 'cs-s3h-row-' + keyE;
            if (isGolden) tr.className = 'cs-row-golden';
            tr.innerHTML =
                '<td>' + $('<span>').text(name).html() + '<span class="cs-s3h-golden-star"' + (isGolden ? '' : ' style="display:none;"') + '> &#11088;</span></td>' +
                '<td id="cs-s3h-tag-cell-' + keyE + '" style="white-space:nowrap;">' +
                    '<span class="cs-s3h-tag-text">' + (tag ? $('<span>').text(tag).html() : '<span class="cs-muted-text">No tag</span>') + '</span> ' +
                    '<button type="button" onclick="csS3HistoryTagEdit(\'' + nameJs + '\',\'' + tagJs + '\')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>' +
                '</td>' +
                '<td>' + $('<span>').text(sf.size_fmt || '\u2014').html() + '</td>' +
                '<td>' + $('<span>').text(sf.date_fmt || (sf.time ? new Date(sf.time * 1000).toLocaleDateString() : '\u2014')).html() + '</td>' +
                '<td id="cs-s3h-actions-' + keyE + '" style="white-space:nowrap;vertical-align:middle;">' +
                    '<button type="button" onclick="csS3HistorySetGolden(\'' + nameJs + '\')" class="button button-small" id="cs-s3h-golden-btn-' + keyE + '" data-golden="' + goldenD + '" title="' + goldenTit + '" style="min-width:0;padding:2px 6px;margin-bottom:3px;' + goldenSt + '">&#11088;</button> ' +
                    dlBtn +
                    '<button type="button" onclick="csS3HistoryDelete(\'' + nameJs + '\')" class="button button-small" title="Delete from AWS S3" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Delete</button>' +
                '</td>';
            tbody.appendChild(tr);
        });
        csS3HMsg('\u2713 ' + files.length + ' file' + (files.length !== 1 ? 's' : '') + ' found', true);
        csS3HUpdateGoldenCount();
    });
};

window.csS3HistoryTagEdit = function (filename, currentTag) {
    var $ = window.jQuery;
    // keyE must NOT include dots — jQuery $() selector treats '.' as class selector
    var keyE = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
    var cell = document.getElementById('cs-s3h-tag-cell-' + keyE);
    if (!cell) return;
    var saved = cell.innerHTML;
    // Use querySelectorAll — avoids jQuery CSS-selector dot/special-char escaping bug
    cell.innerHTML =
        '<input type="text" value="' + $('<span>').text(currentTag).html() + '" ' +
        'style="width:90px;font-size:0.8rem;padding:1px 4px;vertical-align:middle;" maxlength="40"> ' +
        '<button type="button" class="button button-small" style="padding:1px 6px;font-size:0.78rem;">Save</button> ' +
        '<button type="button" class="button button-small" style="padding:1px 6px;font-size:0.78rem;">\u00d7</button>';
    var btns      = cell.querySelectorAll('button');
    var inp       = cell.querySelector('input');
    var saveBtn   = btns[0];
    var cancelBtn = btns[1];
    if (inp) inp.focus();
    cancelBtn.addEventListener('click', function () { cell.innerHTML = saved; });
    var filenameJs = filename.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    var doSave = function () {
        var tag = inp ? inp.value.trim().substring(0, 40) : '';
        csS3HPost('cs_s3_set_tag', 'filename=' + encodeURIComponent(filename) + '&tag=' + encodeURIComponent(tag), function (res) {
            if (res.success) {
                var tagJs = tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                cell.innerHTML =
                    '<span class="cs-s3h-tag-text">' + (tag ? $('<span>').text(tag).html() : '<span class="cs-muted-text">No tag</span>') + '</span> ' +
                    '<button type="button" onclick="csS3HistoryTagEdit(\'' + filenameJs + '\',\'' + tagJs + '\')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>';
                csS3HMsg('\u2713 Tag saved', true);
            } else {
                cell.innerHTML = saved;
                csS3HMsg('\u2717 ' + (res.data || 'Save failed'), false);
            }
        });
    };
    saveBtn.addEventListener('click', doSave);
    if (inp) {
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter')  { doSave(); }
            if (e.key === 'Escape') { cell.innerHTML = saved; }
        });
    }
};

window.csS3HistorySetGolden = function (filename) {
    var keyE = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
    csS3HPost('cs_s3_set_golden', 'filename=' + encodeURIComponent(filename), function (res) {
        if (res.success) {
            var isGolden = !!(res.data && res.data.golden);
            var btn = document.getElementById('cs-s3h-golden-btn-' + keyE);
            if (btn) {
                btn.dataset.golden    = isGolden ? '1' : '0';
                btn.title             = isGolden ? 'Remove Golden Image' : 'Mark as Golden Image';
                btn.style.color       = isGolden ? '#f57f17' : '';
                btn.style.borderColor = isGolden ? '#f57f17' : '';
                btn.style.fontWeight  = isGolden ? '700' : '';
            }
            var row = document.getElementById('cs-s3h-row-' + keyE);
            if (row) {
                row.classList.toggle('cs-row-golden', isGolden);
                
                
                var star = row.querySelector('.cs-s3h-golden-star');
                if (star) star.style.display = isGolden ? '' : 'none';
            }
            csS3HUpdateGoldenCount();
            csS3HMsg(isGolden ? '&#11088; Marked as golden image' : 'Golden image removed', true);
        } else {
            csS3HMsg('\u2717 ' + (res.data || 'Failed'), false);
        }
    });
};

window.csS3HistoryDelete = function (filename) {
    csConfirm('&#128465;', 'Delete from AWS S3',
        '<span style="color:#c62828;">This file will be permanently deleted from your S3 bucket.</span>',
        '<p>File: <strong>' + csEscHtml(filename) + '</strong></p>' +
        '<p>The file will be removed from S3 immediately. Your local backup copy on this server is <strong>not affected</strong>.</p>' +
        '<p>This action <strong>cannot be undone</strong>.</p>',
        function () {
            var keyE = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
            csS3HMsg('Deleting\u2026', true);
            csS3HPost('cs_s3_delete_remote', 'filename=' + encodeURIComponent(filename), function (res) {
                if (res.success) {
                    csS3HMsg('\u2713 Deleted', true);
                    var row = document.getElementById('cs-s3h-row-' + keyE);
                    if (row) row.remove();
                    csS3HUpdateGoldenCount();
                    var cEl = document.getElementById('cs-s3-count-val');
                    if (cEl) { var m = cEl.innerHTML.match(/^(\d+)/); if (m) cEl.innerHTML = cEl.innerHTML.replace(/^\d+/, Math.max(0, parseInt(m[1], 10) - 1)); }
                } else {
                    csS3HMsg('\u2717 ' + (res.data || 'Delete failed'), false);
                }
            });
        }, 'Delete');
};

window.csS3HistoryPull = function (filename) {
    var keyE   = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
    var actEl  = document.getElementById('cs-s3h-actions-' + keyE);
    csS3HMsg('Pulling from S3\u2026', true);
    csS3HPost('cs_s3_pull', 'filename=' + encodeURIComponent(filename), function (res) {
        if (res.success) {
            csS3HMsg('\u2713 Pulled — reload to see it in Backup History', true);
            // Remove the Pull button and update Local column
            var row = document.getElementById('cs-s3h-row-' + keyE);
            if (row) {
                row.classList.toggle('cs-row-golden', isGolden);
                var localCell = row.querySelector('td:nth-child(5)');
                if (localCell) localCell.innerHTML = '<span style="color:#2e7d32;font-weight:600;">&#10003;</span>';
                var pullBtn = actEl ? actEl.querySelector('button[onclick*="csS3HistoryPull"]') : null;
                if (pullBtn) pullBtn.remove();
            }
        } else {
            csS3HMsg('\u2717 ' + (res.data || 'Pull failed'), false);
        }
    });
};

// ================================================================
// Google Drive History functions
// ================================================================

function csGDriveHMsg(text, ok) {
    var el = document.getElementById('cs-gd-msg');
    if (!el) return;
    el.textContent = text;
    el.style.color = ok ? '#2e7d32' : '#c62828';
}

function csGDriveHPost(action, extra, onDone) {
    csGDrivePost(action, extra, onDone); // reuse GDrive transport
}

function csGDriveHUpdateGoldenCount() {
    var el = document.getElementById('cs-gd-golden-count');
    if (!el) return;
    var goldenCount = document.querySelectorAll('[id^="cs-gd-golden-btn-"][data-golden="1"]').length;
    var totalRows   = document.querySelectorAll('#cs-gd-tbody tr').length;
    var nonGolden   = totalRows - goldenCount;
    var maxB        = (window.CS && CS.ami_max) ? parseInt(CS.ami_max, 10) : '?';
    el.innerHTML = '&#11088; ' + goldenCount + ' / 4 golden\u2003' + nonGolden + ' of ' + maxB + ' max backups';
}

window.csGDriveHistoryRefresh = function () {
    var $ = window.jQuery;
    var btn = document.getElementById('cs-gd-refresh-btn');
    if (btn) { btn.disabled = true; btn.textContent = '\u23f3 Querying Drive\u2026'; }
    csGDriveHMsg('Refreshing\u2026', true);
    csGDriveHPost('cs_gdrive_refresh_history', '', function (res) {
        if (btn) { btn.disabled = false; btn.innerHTML = '&#8635; Refresh'; }
        if (!res.success) {
            csGDriveHMsg('\u2717 ' + (res.data || 'Refresh failed'), false);
            return;
        }
        var files = (res.data && res.data.files) ? res.data.files : (res.data || []);
        var count = (res.data && res.data.count != null) ? res.data.count : files.length;
        var cEl   = document.getElementById('cs-gdrive-count-val');
        if (cEl) cEl.innerHTML = count + ' in Drive &nbsp;&middot;&nbsp; ' + cEl.innerHTML.replace(/.*·\s*/, '');
        var tbody = document.getElementById('cs-gd-tbody');
        if (!tbody) {
            csGDriveHMsg('\u2713 Done — reloading\u2026', true);
            setTimeout(function () { location.reload(); }, 1200);
            return;
        }
        tbody.innerHTML = '';
        files.forEach(function (gf) {
            var name      = gf.name || '';
            var keyE      = name.replace(/[^a-zA-Z0-9_\-]/g, '_');
            var nameJs    = name.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            var tag       = gf.tag || '';
            var tagJs     = tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            var isGolden  = !!gf.golden;
            var goldenD   = isGolden ? '1' : '0';
            var goldenSt  = isGolden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '';
            var goldenTit = isGolden ? 'Remove Golden Image' : 'Mark as Golden Image';
            var dlUrl     = CS.admin_post_url + '?action=cs_gdrive_download&file=' + encodeURIComponent(name) + '&nonce=' + CS.nonce;
            var tr = document.createElement('tr');
            tr.id = 'cs-gd-row-' + keyE;
            if (isGolden) tr.className = 'cs-row-golden';
            if (isGolden) {
                tr.style.background = 'linear-gradient(90deg,#fff8e1 0%,#fff 80%)';
                tr.style.borderLeft = '3px solid #f9a825';
            }
            tr.innerHTML =
                '<td>' + $('<span>').text(name).html() + '<span class="cs-gd-golden-star"' + (isGolden ? '' : ' style="display:none;"') + '> &#11088;</span></td>' +
                '<td id="cs-gd-tag-cell-' + keyE + '" style="white-space:nowrap;">' +
                    '<span class="cs-gd-tag-text">' + (tag ? $('<span>').text(tag).html() : '<span class="cs-muted-text">No tag</span>') + '</span> ' +
                    '<button type="button" onclick="csGDriveHistoryTagEdit(\'' + nameJs + '\',\'' + tagJs + '\')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>' +
                '</td>' +
                '<td>' + $('<span>').text(gf.size_fmt || '\u2014').html() + '</td>' +
                '<td>' + $('<span>').text(gf.date_fmt || (gf.time ? new Date(gf.time * 1000).toLocaleDateString() : '\u2014')).html() + '</td>' +
                '<td id="cs-gd-actions-' + keyE + '" style="white-space:nowrap;vertical-align:middle;">' +
                    '<button type="button" onclick="csGDriveHistorySetGolden(\'' + nameJs + '\')" class="button button-small" id="cs-gd-golden-btn-' + keyE + '" data-golden="' + goldenD + '" title="' + goldenTit + '" style="min-width:0;padding:2px 6px;margin-bottom:3px;' + goldenSt + '">&#11088;</button> ' +
                    '<a href="' + dlUrl + '" class="button button-small" style="min-width:0;padding:2px 6px;margin-bottom:3px;text-decoration:none;display:inline-block;">&#8659; Download</a> ' +
                    '<button type="button" onclick="csGDriveHistoryDelete(\'' + nameJs + '\')" class="button button-small" title="Delete from Google Drive" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Delete</button>' +
                '</td>';
            tbody.appendChild(tr);
        });
        csGDriveHMsg('\u2713 ' + files.length + ' file' + (files.length !== 1 ? 's' : '') + ' found', true);
        csGDriveHUpdateGoldenCount();
    });
};

window.csGDriveHistoryTagEdit = function (filename, currentTag) {
    var $ = window.jQuery;
    var keyE = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
    var cell = document.getElementById('cs-gd-tag-cell-' + keyE);
    if (!cell) return;
    var saved = cell.innerHTML;
    cell.innerHTML =
        '<input type="text" value="' + $('<span>').text(currentTag).html() + '" ' +
        'style="width:90px;font-size:0.8rem;padding:1px 4px;vertical-align:middle;" maxlength="40"> ' +
        '<button type="button" class="button button-small" style="padding:1px 6px;font-size:0.78rem;">Save</button> ' +
        '<button type="button" class="button button-small" style="padding:1px 6px;font-size:0.78rem;">\u00d7</button>';
    var btns      = cell.querySelectorAll('button');
    var inp       = cell.querySelector('input');
    var saveBtn   = btns[0];
    var cancelBtn = btns[1];
    if (inp) inp.focus();
    cancelBtn.addEventListener('click', function () { cell.innerHTML = saved; });
    var filenameJs = filename.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    var doSave = function () {
        var tag = inp ? inp.value.trim().substring(0, 40) : '';
        csGDriveHPost('cs_gdrive_set_tag', 'filename=' + encodeURIComponent(filename) + '&tag=' + encodeURIComponent(tag), function (res) {
            if (res.success) {
                var tagJs = tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                cell.innerHTML =
                    '<span class="cs-gd-tag-text">' + (tag ? $('<span>').text(tag).html() : '<span class="cs-muted-text">No tag</span>') + '</span> ' +
                    '<button type="button" onclick="csGDriveHistoryTagEdit(\'' + filenameJs + '\',\'' + tagJs + '\')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>';
                csGDriveHMsg('\u2713 Tag saved', true);
            } else {
                cell.innerHTML = saved;
                csGDriveHMsg('\u2717 ' + (res.data || 'Save failed'), false);
            }
        });
    };
    saveBtn.addEventListener('click', doSave);
    if (inp) {
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter')  { doSave(); }
            if (e.key === 'Escape') { cell.innerHTML = saved; }
        });
    }
};

window.csGDriveHistorySetGolden = function (filename) {
    var keyE = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
    csGDriveHPost('cs_gdrive_set_golden', 'filename=' + encodeURIComponent(filename), function (res) {
        if (res.success) {
            var isGolden = !!(res.data && res.data.golden);
            var btn = document.getElementById('cs-gd-golden-btn-' + keyE);
            if (btn) {
                btn.dataset.golden    = isGolden ? '1' : '0';
                btn.title             = isGolden ? 'Remove Golden Image' : 'Mark as Golden Image';
                btn.style.color       = isGolden ? '#f57f17' : '';
                btn.style.borderColor = isGolden ? '#f57f17' : '';
                btn.style.fontWeight  = isGolden ? '700' : '';
            }
            var row = document.getElementById('cs-gd-row-' + keyE);
            if (row) {
                row.classList.toggle('cs-row-golden', isGolden);
                
                
                var star = row.querySelector('.cs-gd-golden-star');
                if (star) star.style.display = isGolden ? '' : 'none';
            }
            csGDriveHUpdateGoldenCount();
            csGDriveHMsg(isGolden ? '&#11088; Marked as golden image' : 'Golden image removed', true);
        } else {
            csGDriveHMsg('\u2717 ' + (res.data || 'Failed'), false);
        }
    });
};

window.csGDriveHistoryDelete = function (filename) {
    csConfirm('&#128465;', 'Delete from Google Drive',
        '<span style="color:#c62828;">This file will be permanently deleted from your Google Drive.</span>',
        '<p>File: <strong>' + csEscHtml(filename) + '</strong></p>' +
        '<p>The file will be removed from Google Drive immediately. Your local backup copy on this server is <strong>not affected</strong>.</p>' +
        '<p>This action <strong>cannot be undone</strong>.</p>',
        function () {
            var keyE = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
            csGDriveHMsg('Deleting\u2026', true);
            csGDriveHPost('cs_gdrive_delete_remote', 'filename=' + encodeURIComponent(filename), function (res) {
                if (res.success) {
                    csGDriveHMsg('\u2713 Deleted', true);
                    var row = document.getElementById('cs-gd-row-' + keyE);
                    if (row) row.remove();
                    csGDriveHUpdateGoldenCount();
                    var cEl = document.getElementById('cs-gdrive-count-val');
                    if (cEl) { var m = cEl.innerHTML.match(/^(\d+)/); if (m) cEl.innerHTML = cEl.innerHTML.replace(/^\d+/, Math.max(0, parseInt(m[1], 10) - 1)); }
                } else {
                    csGDriveHMsg('\u2717 ' + (res.data || 'Delete failed'), false);
                }
            });
        }, 'Delete');
};

// ================================================================
// Dropbox — settings card helpers
// ================================================================

function csDropboxMsg(text, ok) {
    var el = document.getElementById('cs-dropbox-msg');
    if (el) { el.innerHTML = text; el.style.color = ok ? '#2e7d32' : '#c62828'; }
}

function csDropboxPost(action, extra, onDone) {
    csGDrivePost(action, extra, onDone); // reuse transport (same AJAX endpoint pattern)
}

window.csDropboxExplain = function () {
    var $ = window.jQuery;
    setTimeout(function () {
        $('#cs-explain-modal').css('max-width', '640px');
        $('#cs-explain-body').css({'max-height': '65vh', 'overflow-y': 'auto', 'padding-right': '6px'});
    }, 10);
    function cmd(text) {
        return '<code style="display:block;background:#1e1e1e;color:#d4d4d4;padding:8px 12px;border-radius:4px;margin:4px 0 10px;font-size:0.82rem;white-space:pre;">' + text + '</code>';
    }
    function h(text) { return '<p style="margin:14px 0 4px;font-weight:700;font-size:0.93rem;">' + text + '</p>'; }
    function hr() { return '<hr style="margin:12px 0;border:none;border-top:1px solid #e0e0e0;">'; }
    csShowExplain('Dropbox Backup — Setup Guide',
        '<p style="margin:0 0 8px;">After every local backup, the most recent backup zip is automatically copied to your Dropbox via <strong>rclone</strong>. This uses the same rclone tool as Google Drive — if you already have rclone installed you just need to add a Dropbox remote.</p>' +
        '<p style="margin:0 0 4px;"><strong>Buttons:</strong></p>' +
        '<ul style="margin:0 0 10px 18px;padding:0;">' +
        '<li><strong>Save Dropbox Settings</strong> — saves the rclone remote name and destination folder.</li>' +
        '<li><strong>Test Connection</strong> — runs <code>rclone lsd</code> to verify the remote exists and is reachable.</li>' +
        '<li><strong>Diagnose</strong> — shows rclone version, remote name, and troubleshooting tips.</li>' +
        '<li><strong>Copy Last Backup to Cloud</strong> — immediately copies the <em>most recent local backup zip</em> to Dropbox. Use this after a manual backup or to push on demand without waiting for the schedule. Does not create a new backup — it copies whatever zip is already on the server.</li>' +
        '</ul>' +
        hr() +
        h('Step 1 — Install rclone (if not already installed)') +
        cmd('curl -fsSL https://rclone.org/install.sh | sudo bash') +
        hr() +
        h('Step 2 — Fix apache home directory permissions') +
        cmd('sudo mkdir -p /usr/share/httpd/.config/rclone\nsudo chown -R apache:apache /usr/share/httpd/.config\nsudo chmod 700 /usr/share/httpd/.config/rclone\nsudo chown apache:apache /usr/share/httpd\nsudo chmod 755 /usr/share/httpd') +
        hr() +
        h('Step 3 — Run the setup wizard as apache') +
        cmd('sudo -u apache rclone config') +
        '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;margin:6px 0 10px;">' +
        '<thead><tr>' +
        '<th style="text-align:left;padding:4px 8px;background:#f5f5f5;border:1px solid #e0e0e0;width:55%;">Prompt</th>' +
        '<th style="text-align:left;padding:4px 8px;background:#f5f5f5;border:1px solid #e0e0e0;">Type this</th>' +
        '</tr></thead><tbody>' +
        '<tr><td style="padding:4px 8px;border:1px solid #e0e0e0;">e/n/d/r/c/s/q&gt;</td><td style="padding:4px 8px;border:1px solid #e0e0e0;"><code>n</code> (New remote)</td></tr>' +
        '<tr><td style="padding:4px 8px;border:1px solid #e0e0e0;">name&gt;</td><td style="padding:4px 8px;border:1px solid #e0e0e0;"><code>dropbox</code></td></tr>' +
        '<tr><td style="padding:4px 8px;border:1px solid #e0e0e0;">Storage&gt;</td><td style="padding:4px 8px;border:1px solid #e0e0e0;"><code>15</code> (Dropbox)</td></tr>' +
        '<tr><td style="padding:4px 8px;border:1px solid #e0e0e0;">client_id&gt;</td><td style="padding:4px 8px;border:1px solid #e0e0e0;">Enter (leave blank)</td></tr>' +
        '<tr><td style="padding:4px 8px;border:1px solid #e0e0e0;">client_secret&gt;</td><td style="padding:4px 8px;border:1px solid #e0e0e0;">Enter (leave blank)</td></tr>' +
        '<tr><td style="padding:4px 8px;border:1px solid #e0e0e0;">Edit advanced config?</td><td style="padding:4px 8px;border:1px solid #e0e0e0;"><code>n</code></td></tr>' +
        '<tr style="background:#fff8e1;"><td style="padding:4px 8px;border:1px solid #e0e0e0;">Use web browser to automatically authenticate? / Use auto config?</td><td style="padding:4px 8px;border:1px solid #e0e0e0;"><code>n</code> — server has no browser</td></tr>' +
        '<tr style="background:#fff8e1;"><td style="padding:4px 8px;border:1px solid #e0e0e0;">config_token&gt;</td><td style="padding:4px 8px;border:1px solid #e0e0e0;">Paste token from laptop (see below)</td></tr>' +
        '<tr><td style="padding:4px 8px;border:1px solid #e0e0e0;">Keep this "dropbox" remote? y/e/d&gt;</td><td style="padding:4px 8px;border:1px solid #e0e0e0;"><code>y</code></td></tr>' +
        '<tr><td style="padding:4px 8px;border:1px solid #e0e0e0;">e/n/d/r/c/s/q&gt;</td><td style="padding:4px 8px;border:1px solid #e0e0e0;"><code>q</code> (Quit)</td></tr>' +
        '</tbody></table>' +
        '<p style="margin:0 0 6px;">For the highlighted <strong>config_token&gt;</strong> step, run this on your <strong>laptop</strong>:</p>' +
        cmd('rclone authorize "dropbox"') +
        '<p style="margin:0 0 8px;">A browser window opens — log in and authorise Dropbox. The browser shows <strong>"Success! All done. Please go back to rclone."</strong> Rclone prints a long token like <code>{"access_token":"..."}</code> — copy the entire thing and paste it at the <strong>config_token&gt;</strong> prompt on the server.</p>' +
        hr() +
        h('Step 4 — Enter remote name above and save') +
        '<p style="margin:0 0 8px;">Enter your remote name in <strong>[Enter Remote Name]</strong> (e.g. <code>dropbox</code>) and a destination folder in <strong>[Enter Folder Path]</strong> (e.g. <code>cloudscale-backups/</code>), then click <strong>Save Dropbox Settings</strong> and <strong>Test Connection</strong>.</p>' +
        '<p style="margin:0;font-size:0.85rem;color:#555;">Full documentation: <a href="https://rclone.org/dropbox/" target="_blank" rel="noopener">rclone.org/dropbox</a></p>'
    );
};

window.csDropboxSave = function () {
    var remoteEl = document.getElementById('cs-dropbox-remote');
    var pathEl   = document.getElementById('cs-dropbox-path');
    if (!remoteEl || !pathEl) return;
    var remote = remoteEl.value.trim();
    var path   = pathEl.value.trim() || 'cloudscale-backups/';
    csDropboxMsg('Saving\u2026', true);
    csDropboxPost('cs_save_dropbox', 'remote=' + encodeURIComponent(remote) + '&path=' + encodeURIComponent(path),
        function (res) {
            csDropboxMsg(res.success ? '\u2713 Saved' : '\u2717 ' + res.data, res.success);
            if (res.success && remote) csMarkProviderConfigured('cs-cloud-dropbox-enabled');
        }
    );
};

window.csDropboxTest = function () {
    csDropboxMsg('Testing\u2026', true);
    csDropboxPost('cs_test_dropbox', '', function (res) {
        csDropboxMsg(res.success ? '\u2713 ' + res.data : '\u2717 ' + (res.data || 'Connection failed'), res.success);
        if (res.success) csMarkProviderConfigured('cs-cloud-dropbox-enabled');
    });
};

window.csDropboxDiagnose = function () {
    csShowExplain('Dropbox Diagnose',
        '<p>Run <strong>Test Connection</strong> first. If it fails, check:</p>' +
        '<ul style="margin:8px 0 0 18px;padding:0;">' +
        '<li>rclone is installed: <code>which rclone</code></li>' +
        '<li>A Dropbox remote exists: <code>sudo -u apache rclone listremotes</code></li>' +
        '<li>The remote name matches what you entered above</li>' +
        '<li>The apache user can write to the rclone config: <code>ls -la /usr/share/httpd/.config/rclone/</code></li>' +
        '</ul>'
    );
};

window.csDropboxSyncLatest = function () {
    csCheckSpaceThenSync('dropbox', 'cs_start_sync_dropbox', csDropboxMsg);
};

// ================================================================
// Dropbox — Backup History helpers
// ================================================================

function csDropboxHMsg(text, ok) {
    var el = document.getElementById('cs-db-msg');
    if (el) { el.textContent = text; el.style.color = ok ? '#2e7d32' : '#c62828'; }
}

function csDropboxHPost(action, extra, onDone) {
    csGDrivePost(action, extra, onDone); // reuse transport
}

function csDropboxHUpdateGoldenCount() {
    var el = document.getElementById('cs-db-golden-count');
    if (!el) return;
    var rows    = document.querySelectorAll('#cs-db-tbody tr');
    var golden  = document.querySelectorAll('#cs-db-tbody tr.cs-row-golden').length;
    var regular = rows.length - golden;
    var m       = el.innerHTML.match(/\/\s*(\d+)\s*max/);
    var max     = m ? m[1] : '?';
    el.innerHTML = '\u2b50 ' + golden + ' / 4 golden&emsp;' + regular + ' / ' + max + ' max backups';
}

window.csDropboxHistoryRefresh = function () {
    var $ = window.jQuery;
    var btn = document.getElementById('cs-db-refresh-btn');
    if (btn) { btn.disabled = true; btn.textContent = '\u23f3 Querying Dropbox\u2026'; }
    csDropboxHMsg('Refreshing\u2026', true);
    csDropboxHPost('cs_dropbox_refresh_history', '', function (res) {
        if (btn) { btn.disabled = false; btn.innerHTML = '&#8635; Refresh'; }
        if (!res.success) {
            csDropboxHMsg('\u2717 ' + (res.data || 'Refresh failed'), false);
            return;
        }
        var files = (res.data && res.data.files) ? res.data.files : (res.data || []);
        var count = (res.data && res.data.count != null) ? res.data.count : files.length;
        var cEl   = document.getElementById('cs-dropbox-count-val');
        if (cEl) cEl.innerHTML = count + ' in Dropbox &nbsp;&middot;&nbsp; ' + cEl.innerHTML.replace(/.*·\s*/, '');
        var tbody = document.getElementById('cs-db-tbody');
        var wrap  = document.getElementById('cs-db-table-wrap');
        var empty = document.getElementById('cs-db-empty');
        if (!tbody) { csDropboxHMsg('\u2717 Table element missing — reload page', false); return; }
        tbody.innerHTML = '';
        if (wrap)  wrap.style.display  = files.length ? '' : 'none';
        if (empty) empty.style.display = files.length ? 'none' : '';
        files.forEach(function (dbf) {
            var name      = dbf.name || '';
            var keyE      = name.replace(/[^a-zA-Z0-9_\-]/g, '_');
            var nameJs    = name.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            var tag       = dbf.tag || '';
            var tagJs     = tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            var isGolden  = !!dbf.golden;
            var goldenD   = isGolden ? '1' : '0';
            var goldenSt  = isGolden ? 'color:#f57f17;border-color:#f57f17;font-weight:700;' : '';
            var goldenTit = isGolden ? 'Remove Golden Image' : 'Mark as Golden Image';
            var dlUrl     = CS.admin_post_url + '?action=cs_dropbox_download&file=' + encodeURIComponent(name) + '&nonce=' + CS.nonce;
            var tr = document.createElement('tr');
            tr.id = 'cs-db-row-' + keyE;
            if (isGolden) { tr.className = 'cs-row-golden'; tr.style.background = 'linear-gradient(90deg,#fff8e1 0%,#fff 80%)'; tr.style.borderLeft = '3px solid #f9a825'; }
            tr.innerHTML =
                '<td>' + $('<span>').text(name).html() + '<span class="cs-db-golden-star"' + (isGolden ? '' : ' style="display:none;"') + '> &#11088;</span></td>' +
                '<td id="cs-db-tag-cell-' + keyE + '" style="white-space:nowrap;">' +
                    '<span class="cs-db-tag-text">' + (tag ? $('<span>').text(tag).html() : '<span class="cs-muted-text">No tag</span>') + '</span> ' +
                    '<button type="button" onclick="csDropboxHistoryTagEdit(\'' + nameJs + '\',\'' + tagJs + '\')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>' +
                '</td>' +
                '<td>' + $('<span>').text(dbf.size_fmt || '\u2014').html() + '</td>' +
                '<td>' + $('<span>').text(dbf.date_fmt || (dbf.time ? new Date(dbf.time * 1000).toLocaleDateString() : '\u2014')).html() + '</td>' +
                '<td id="cs-db-actions-' + keyE + '" style="white-space:nowrap;vertical-align:middle;">' +
                    '<button type="button" onclick="csDropboxHistorySetGolden(\'' + nameJs + '\')" class="button button-small" id="cs-db-golden-btn-' + keyE + '" data-golden="' + goldenD + '" title="' + goldenTit + '" style="min-width:0;padding:2px 6px;margin-bottom:3px;' + goldenSt + '">&#11088;</button> ' +
                    '<a href="' + dlUrl + '" class="button button-small" style="min-width:0;padding:2px 6px;margin-bottom:3px;text-decoration:none;display:inline-block;">&#8659; Download</a> ' +
                    '<button type="button" onclick="csDropboxHistoryDelete(\'' + nameJs + '\')" class="button button-small" title="Delete from Dropbox" style="min-width:0;padding:2px 8px;color:#c62828;border-color:#c62828;">&#128465; Delete</button>' +
                '</td>';
            tbody.appendChild(tr);
        });
        csDropboxHMsg('\u2713 ' + files.length + ' file' + (files.length !== 1 ? 's' : '') + ' found', true);
        csDropboxHUpdateGoldenCount();
    });
};

window.csDropboxHistoryTagEdit = function (filename, currentTag) {
    var $ = window.jQuery;
    var keyE = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
    var cell = document.getElementById('cs-db-tag-cell-' + keyE);
    if (!cell) return;
    var saved = cell.innerHTML;
    cell.innerHTML =
        '<input type="text" value="' + $('<span>').text(currentTag).html() + '" ' +
        'style="width:90px;font-size:0.8rem;padding:1px 4px;vertical-align:middle;" maxlength="40"> ' +
        '<button type="button" class="button button-small" style="padding:1px 6px;font-size:0.78rem;">Save</button> ' +
        '<button type="button" class="button button-small" style="padding:1px 6px;font-size:0.78rem;">\u00d7</button>';
    var btns      = cell.querySelectorAll('button');
    var inp       = cell.querySelector('input');
    var saveBtn   = btns[0];
    var cancelBtn = btns[1];
    if (inp) inp.focus();
    cancelBtn.addEventListener('click', function () { cell.innerHTML = saved; });
    var filenameJs = filename.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    var doSave = function () {
        var tag = inp ? inp.value.trim().substring(0, 40) : '';
        csDropboxHPost('cs_dropbox_set_tag', 'filename=' + encodeURIComponent(filename) + '&tag=' + encodeURIComponent(tag), function (res) {
            if (res.success) {
                var tagJs = tag.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                cell.innerHTML =
                    '<span class="cs-db-tag-text">' + (tag ? $('<span>').text(tag).html() : '<span class="cs-muted-text">No tag</span>') + '</span> ' +
                    '<button type="button" onclick="csDropboxHistoryTagEdit(\'' + filenameJs + '\',\'' + tagJs + '\')" class="button button-small" style="min-width:0;padding:1px 5px;font-size:0.75rem;vertical-align:middle;">Edit</button>';
                csDropboxHMsg('\u2713 Tag saved', true);
            } else {
                cell.innerHTML = saved;
                csDropboxHMsg('\u2717 ' + (res.data || 'Save failed'), false);
            }
        });
    };
    saveBtn.addEventListener('click', doSave);
    if (inp) {
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter')  { doSave(); }
            if (e.key === 'Escape') { cell.innerHTML = saved; }
        });
    }
};

window.csDropboxHistorySetGolden = function (filename) {
    var keyE = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
    csDropboxHPost('cs_dropbox_set_golden', 'filename=' + encodeURIComponent(filename), function (res) {
        if (res.success) {
            var isGolden = !!(res.data && res.data.golden);
            var btn = document.getElementById('cs-db-golden-btn-' + keyE);
            if (btn) {
                btn.dataset.golden    = isGolden ? '1' : '0';
                btn.title             = isGolden ? 'Remove Golden Image' : 'Mark as Golden Image';
                btn.style.color       = isGolden ? '#f57f17' : '';
                btn.style.borderColor = isGolden ? '#f57f17' : '';
                btn.style.fontWeight  = isGolden ? '700' : '';
            }
            var row = document.getElementById('cs-db-row-' + keyE);
            if (row) {
                row.classList.toggle('cs-row-golden', isGolden);
                var star = row.querySelector('.cs-db-golden-star');
                if (star) star.style.display = isGolden ? '' : 'none';
            }
            csDropboxHUpdateGoldenCount();
            csDropboxHMsg(isGolden ? '&#11088; Marked as golden image' : 'Golden image removed', true);
        } else {
            csDropboxHMsg('\u2717 ' + (res.data || 'Failed'), false);
        }
    });
};

window.csDropboxHistoryDelete = function (filename) {
    csConfirm('&#128465;', 'Delete from Dropbox',
        '<span style="color:#c62828;">This file will be permanently deleted from your Dropbox.</span>',
        '<p>File: <strong>' + csEscHtml(filename) + '</strong></p>' +
        '<p>The file will be removed from Dropbox immediately. Your local backup copy on this server is <strong>not affected</strong>.</p>' +
        '<p>This action <strong>cannot be undone</strong>.</p>',
        function () {
            var keyE = filename.replace(/[^a-zA-Z0-9_\-]/g, '_');
            csDropboxHMsg('Deleting\u2026', true);
            csDropboxHPost('cs_dropbox_delete_remote', 'filename=' + encodeURIComponent(filename), function (res) {
                if (res.success) {
                    csDropboxHMsg('\u2713 Deleted', true);
                    var row = document.getElementById('cs-db-row-' + keyE);
                    if (row) row.remove();
                    csDropboxHUpdateGoldenCount();
                    var cEl = document.getElementById('cs-dropbox-count-val');
                    if (cEl) { var m = cEl.innerHTML.match(/^(\d+)/); if (m) cEl.innerHTML = cEl.innerHTML.replace(/^\d+/, Math.max(0, parseInt(m[1], 10) - 1)); }
                } else {
                    csDropboxHMsg('\u2717 ' + (res.data || 'Delete failed'), false);
                }
            });
        }, 'Delete');
};
