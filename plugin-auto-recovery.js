/* CloudScale Plugin Auto Recovery — Admin Script v1.0.0 */
(function ($) {
    'use strict';

    // ── Helpers ───────────────────────────────────────────────────────────────

    function parMsg(id, text, ok) {
        var $el = $('#' + id);
        $el.text(text).css('color', ok ? '#2e7d32' : '#b71c1c');
        if (ok) setTimeout(function () { $el.text(''); }, 4000);
    }

    function parPost(action, data, onSuccess, onError) {
        return $.post(CSBR.ajax_url, $.extend({ action: action, nonce: CSBR.nonce }, data))
            .done(function (res) {
                if (res.success) { onSuccess(res.data); }
                else             { onError(res.data || 'Error.'); }
            })
            .fail(function () { onError('Network error.'); });
    }

    // ── Toggle controls ────────────────────────────────────────────────────────

    function applyEnabledState(on) {
        $('#par-main-controls').toggle(on);
    }
    applyEnabledState($('#par-enabled').is(':checked'));
    $('#par-enabled').on('change', function () {
        applyEnabledState($(this).is(':checked'));
    });

    $('#par-sms-enabled').on('change', function () {
        $('#par-sms-controls').toggle($(this).is(':checked'));
    });

    // ── Save settings ─────────────────────────────────────────────────────────

    $('#par-save-btn').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Saving…');
        parPost('csbr_par_save_settings', {
            par_enabled:      $('#par-enabled').is(':checked') ? '1' : '',
            par_window:       $('#par-window').val(),
            par_health_url:   $('#par-health-url').val(),
            par_sms_enabled:  $('#par-sms-enabled').is(':checked') ? '1' : '',
            par_twilio_sid:   $('#par-twilio-sid').val(),
            par_twilio_token: $('#par-twilio-token').val(),
            par_twilio_from:  $('#par-twilio-from').val(),
            par_twilio_to:    $('#par-twilio-to').val(),
        }, function (d) {
            $btn.prop('disabled', false).text('Save Plugin Auto Recovery Settings');
            parMsg('par-save-msg', d.msg || 'Saved.', true);
        }, function (err) {
            $btn.prop('disabled', false).text('Save Plugin Auto Recovery Settings');
            parMsg('par-save-msg', err, false);
        });
    });

    // ── Test health check ─────────────────────────────────────────────────────

    $('#par-test-health-btn').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Testing…');
        parPost('csbr_par_test_health', {}, function (d) {
            $btn.prop('disabled', false).text('Test Health Check');
            parMsg('par-test-health-msg', d.msg, true);
        }, function (err) {
            $btn.prop('disabled', false).text('Test Health Check');
            parMsg('par-test-health-msg', err, false);
        });
    });

    // ── Test SMS ──────────────────────────────────────────────────────────────

    $('#par-test-sms-btn').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Sending…');
        parPost('csbr_par_test_sms', {
            par_twilio_sid:   $('#par-twilio-sid').val(),
            par_twilio_token: $('#par-twilio-token').val(),
            par_twilio_from:  $('#par-twilio-from').val(),
            par_twilio_to:    $('#par-twilio-to').val(),
        }, function (d) {
            $btn.prop('disabled', false).text('Send Test SMS');
            parMsg('par-test-sms-msg', d.msg || 'Sent.', true);
        }, function (err) {
            $btn.prop('disabled', false).text('Send Test SMS');
            parMsg('par-test-sms-msg', err, false);
        });
    });

    // ── Copy buttons ──────────────────────────────────────────────────────────

    $('#par-copy-script-btn').on('click', function () {
        var text = $('#par-watchdog-script').text();
        navigator.clipboard.writeText(text).then(function () {
            $('#par-copy-script-btn').text('Copied!');
            setTimeout(function () { $('#par-copy-script-btn').text('Copy'); }, 2000);
        }).catch(function () {
            // Fallback for older browsers
            var $ta = $('<textarea>').css({ position: 'absolute', left: '-9999px' }).val(text).appendTo('body');
            $ta[0].select();
            document.execCommand('copy');
            $ta.remove();
            $('#par-copy-script-btn').text('Copied!');
            setTimeout(function () { $('#par-copy-script-btn').text('Copy'); }, 2000);
        });
    });

    $('#par-copy-cron-btn').on('click', function () {
        var text = $('#par-cron-line').text();
        navigator.clipboard.writeText(text).then(function () {
            $('#par-copy-cron-btn').text('Copied!');
            setTimeout(function () { $('#par-copy-cron-btn').text('Copy'); }, 2000);
        }).catch(function () {
            var $ta = $('<textarea>').css({ position: 'absolute', left: '-9999px' }).val(text).appendTo('body');
            $ta[0].select();
            document.execCommand('copy');
            $ta.remove();
            $('#par-copy-cron-btn').text('Copied!');
            setTimeout(function () { $('#par-copy-cron-btn').text('Copy'); }, 2000);
        });
    });

    // ── Status panel ──────────────────────────────────────────────────────────

    var parPollTimer    = null;
    var parCountdownInt = null;

    function parLoadStatus() {
        parPost('csbr_par_get_status', {}, function (d) {
            parRenderMonitors(d.monitors || []);
            parRenderHistory(d.history   || []);
            parRenderWatchdogStatus(d.watchdog_ago);

            // Poll every 15 s while there are active monitors.
            if ((d.monitors || []).length > 0) {
                if (!parPollTimer) {
                    parPollTimer = setInterval(parLoadStatus, 15000);
                }
            } else {
                clearInterval(parPollTimer);
                parPollTimer = null;
            }
        }, function () { /* silently ignore status load errors */ });
    }

    function parRenderWatchdogStatus(agoSeconds) {
        var $el = $('#par-watchdog-status');
        if (agoSeconds === null || agoSeconds === undefined) {
            $el.text('Watchdog: not detected').css('color', '#ffb74d');
            return;
        }
        if (agoSeconds < 90) {
            $el.text('Watchdog: running (' + agoSeconds + 's ago)').css('color', '#80cbc4');
        } else {
            $el.text('Watchdog: last seen ' + Math.round(agoSeconds / 60) + ' min ago').css('color', '#ffb74d');
        }
    }

    function parRenderMonitors(monitors) {
        var $body = $('#par-monitors-body');
        if (!monitors.length) {
            $body.html('<p style="color:#78909c;font-size:0.88rem;">No active monitors. Plugin Auto Recovery will start monitoring automatically when a plugin is updated.</p>');
            clearInterval(parCountdownInt);
            return;
        }

        var rows = monitors.map(function (m) {
            var failBadge = m.fail_count > 0
                ? ' <span style="color:#b71c1c;font-size:0.8rem;font-weight:700;">(' + m.fail_count + ' fail' + (m.fail_count > 1 ? 's' : '') + ')</span>'
                : '';
            return '<tr>' +
                '<td style="padding:8px 10px;">' + m.plugin_name + '</td>' +
                '<td style="padding:8px 10px;font-size:0.83rem;">v' + m.version_before + ' &rarr; v' + m.version_after + '</td>' +
                '<td style="padding:8px 10px;">' +
                    '<span class="par-countdown" data-until="' + m.monitoring_until + '"></span>' + failBadge +
                '</td>' +
                '<td style="padding:8px 10px;white-space:nowrap;">' +
                    '<button type="button" class="button par-rollback-btn" data-id="' + m.id + '" style="font-size:0.8rem;">Roll Back Now</button>' +
                '</td>' +
            '</tr>';
        });

        $body.html(
            '<div class="cs-table-wrap">' +
            '<table class="widefat cs-table" style="font-size:0.85rem;">' +
            '<thead><tr><th>Plugin</th><th>Version</th><th>Time Remaining</th><th>Action</th></tr></thead>' +
            '<tbody>' + rows.join('') + '</tbody>' +
            '</table></div>'
        );

        parTickCountdowns();
    }

    function parTickCountdowns() {
        clearInterval(parCountdownInt);
        parCountdownInt = setInterval(function () {
            $('.par-countdown').each(function () {
                var until = parseInt($(this).data('until'), 10);
                var rem   = Math.max(0, until - Math.floor(Date.now() / 1000));
                var mins  = Math.floor(rem / 60);
                var secs  = rem % 60;
                if (rem > 0) {
                    $(this).text(mins + ':' + (secs < 10 ? '0' : '') + secs + ' remaining');
                } else {
                    $(this).text('Window expired — closing…');
                }
            });
        }, 1000);
    }

    function parRenderHistory(history) {
        var $body = $('#par-history-body');
        if (!history.length) {
            $body.html('<p style="color:#78909c;font-size:0.88rem;">No rollback events recorded yet.</p>');
            return;
        }
        var rows = history.map(function (h) {
            return '<tr>' +
                '<td style="padding:8px 10px;">' + h.plugin_name + '</td>' +
                '<td style="padding:8px 10px;font-size:0.83rem;">v' + h.version_from + ' &rarr; v' + h.version_to + '</td>' +
                '<td style="padding:8px 10px;font-size:0.83rem;">' + h.rolled_back + '</td>' +
                '<td style="padding:8px 10px;font-size:0.83rem;">' + h.trigger + '</td>' +
                '<td style="padding:8px 10px;">' +
                    '<button type="button" class="button-link par-dismiss-btn" data-id="' + h.id + '" style="color:#999;font-size:0.8rem;">Dismiss</button>' +
                '</td>' +
            '</tr>';
        });
        $body.html(
            '<div class="cs-table-wrap">' +
            '<table class="widefat cs-table" style="font-size:0.85rem;">' +
            '<thead><tr><th>Plugin</th><th>Versions</th><th>Rolled Back</th><th>Trigger</th><th></th></tr></thead>' +
            '<tbody>' + rows.join('') + '</tbody>' +
            '</table></div>'
        );
    }

    // ── Manual rollback ───────────────────────────────────────────────────────

    $(document).on('click', '.par-rollback-btn', function () {
        var id   = $(this).data('id');
        var $btn = $(this);
        // eslint-disable-next-line no-alert
        if (!window.confirm('Roll back this plugin now?\n\nThe current (updated) version will be replaced with the pre-update backup. This cannot be undone.')) return;
        $btn.prop('disabled', true).text('Rolling back…');
        parPost('csbr_par_manual_rollback', { monitor_id: id }, function (d) {
            // eslint-disable-next-line no-alert
            alert(d.msg || 'Rollback complete.');
            parLoadStatus();
        }, function (err) {
            // eslint-disable-next-line no-alert
            alert('Rollback failed: ' + err);
            $btn.prop('disabled', false).text('Roll Back Now');
        });
    });

    // ── Dismiss history ───────────────────────────────────────────────────────

    $(document).on('click', '.par-dismiss-btn', function () {
        var id   = $(this).data('id');
        var $row = $(this).closest('tr');
        parPost('csbr_par_dismiss_history', { history_id: id }, function () {
            $row.fadeOut(300, function () { $row.remove(); });
        }, function () { /* silently ignore */ });
    });

    // ── Refresh button ────────────────────────────────────────────────────────

    $('#par-refresh-btn').on('click', parLoadStatus);

    // ── Load on tab switch / page load ────────────────────────────────────────

    // Hook into tab button click so status loads when the user navigates to this tab.
    $('.cs-tab[data-tab="autorecovery"]').on('click', function () {
        setTimeout(parLoadStatus, 100);
    });

    // If the page opened directly on the autorecovery tab, load now.
    if ($('#cs-tab-autorecovery').is(':visible')) {
        parLoadStatus();
    }

    // ── Explain modal ─────────────────────────────────────────────────────────

    window.csParExplain = function () {
        csShowExplain('Plugin Auto Recovery', [
            '<div style="background:#fff3e0;border-left:4px solid #f57c00;padding:12px 16px;border-radius:0 6px 6px 0;margin-bottom:16px;">',
            '<strong style="color:#e65100;">Did you know?</strong> Plugins are the single most common cause of WordPress site crashes. ',
            'A bad update can introduce a PHP fatal error that takes your entire site offline — often at night or over a weekend ',
            'when you are not watching. Plugin Auto Recovery detects this within minutes and restores the previous version automatically.',
            '</div>',
            '<p>Plugin Auto Recovery automatically backs up each plugin directory before WordPress applies an update, ',
            'then watches your site for failures. If something goes wrong it rolls back to the previous version — ',
            'without any manual intervention.</p>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">How it works</p>',
            '<ol style="margin:0 0 16px 1.2em;padding:0;line-height:1.9;">',
            '<li><strong>Pre-update backup</strong> — the plugin directory is copied to a secure location on the server ',
            'the moment WordPress begins an update (before the new files are placed).</li>',
            '<li><strong>Monitoring window</strong> — after the update completes, the system-cron watchdog probes the ',
            'health check URL every minute for the configured window (default 5 minutes).</li>',
            '<li><strong>Automatic rollback</strong> — two consecutive probe failures (5xx error or connection timeout) ',
            'trigger a rollback: the broken plugin directory is renamed and the backup is copied back. ',
            'This happens entirely outside of WordPress, so it works even during a PHP fatal error.</li>',
            '<li><strong>Notification</strong> — on the next WordPress page load after recovery, the rollback is recorded ',
            'in the Rollback History card and an email is sent to the WordPress admin address. If Twilio SMS is ',
            'configured, an SMS is sent as well.</li>',
            '<li><strong>Branded recovery page</strong> — while the site is in a crash state, visitors see a ',
            'branded CloudScale recovery page instead of a white screen of death. If the watchdog has detected ',
            'the problem and is rolling back, the page reads: <em>"CloudScale Plugin Auto Recovery is recovering ',
            'this site — please wait a few minutes and try again"</em> with a spinner and auto-refresh. ',
            'If it is a generic fatal error with no active recovery, visitors see a polite maintenance message. ',
            'Either way, no raw PHP errors or blank pages are shown to the public.</li>',
            '</ol>',
            '<p style="font-size:0.85rem;color:#64748b;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;margin-bottom:16px;">',
            '<strong>Note:</strong> the recovery page covers PHP fatal errors that occur after WordPress begins loading. ',
            'A syntax error so severe that PHP cannot parse the file at all (a compile-time error) may bypass it and ',
            'show a raw 500 — but this is rare. Runtime fatal errors, which are by far the most common plugin crash type, ',
            'are always caught.',
            '</p>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">Settings</p>',
            '<ul style="margin:0 0 16px 1.2em;line-height:1.9;">',
            '<li><strong>Enable Plugin Auto Recovery</strong> — turn the feature on or off. When disabled, no backups are ',
            'taken before updates and no monitoring occurs.</li>',
            '<li><strong>Monitoring window</strong> — how many minutes after an update the watchdog actively probes the ',
            'site. Increase this for sites that take longer to stabilise after an update (e.g. sites that run cache ',
            'warming or build steps on deploy). Maximum 30 minutes.</li>',
            '<li><strong>Health check URL</strong> — the URL the watchdog fetches each minute. Leave blank to use the ',
            'site home URL. A 5xx response or connection failure is treated as unhealthy; 4xx responses are treated as ',
            'healthy (the server is up, just returning an expected error).</li>',
            '<li><strong>Test Health Check</strong> — fetches the URL immediately and shows the HTTP status code.',
            ' Use this to confirm the watchdog can reach your site before relying on it.</li>',
            '</ul>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">Watchdog setup</p>',
            '<p>The watchdog is a bash script that must be installed on the server and run every minute via the ',
            'system cron (root\'s crontab). Copy the script shown in the <strong>Watchdog Script</strong> card, ',
            'save it to <code>/usr/local/bin/csbr-par-watchdog.sh</code>, make it executable, then add the cron line ',
            'to root\'s crontab with <code>sudo crontab -e</code>.</p>',
            '<p><strong>Why system cron and not WP-Cron?</strong> If a plugin update causes a PHP fatal error, ',
            'WordPress crashes completely — wp-cron.php never fires. A system-cron job runs every minute regardless ',
            'of WordPress health and can detect and recover from the problem before any visitor notices.</p>',
            '<p>The Watchdog status indicator in the card header turns green when the script has run in the last ',
            '90 seconds. If it shows amber ("last seen N min ago"), check that the cron job is still active.',
            '</p>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">SMS alerts via Twilio</p>',
            '<p>Enable SMS alerts to receive a text message whenever a rollback occurs. You will need a ',
            '<a href="https://www.twilio.com" target="_blank">Twilio</a> account (free trial available). ',
            'Enter your Account SID, Auth Token, the Twilio phone number you want to send from, and the ',
            'destination number. Click <strong>Send Test SMS</strong> to verify the credentials before saving.</p>',
            '<p>Email notifications are always sent on rollback using the address configured in the ',
            '<strong>Backup Schedule</strong> card. SMS is optional and in addition to email.</p>',
        ].join(''));
    };

}(jQuery));
