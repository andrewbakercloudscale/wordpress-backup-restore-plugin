/* CloudScale Automatic Crash Recovery — Admin Script v1.0.0 */
jQuery(function ($) {
    'use strict';

    // ── Helpers ───────────────────────────────────────────────────────────────

    function parMsg(id, text, ok) {
        var $el = $('#' + id);
        $el.text(text).css('color', ok ? '#2e7d32' : '#b71c1c');
        if (ok) setTimeout(function () { $el.text(''); }, 4000);
    }

    function parPost(action, data, onSuccess, onError) {
        return $.ajax({
            url:      CSBR.ajax_url,
            method:   'POST',
            dataType: 'json',
            timeout:  15000,
            data:     $.extend({ action: action, nonce: CSBR.nonce }, data),
        }).done(function (res) {
            if (res && res.success) { onSuccess(res.data); }
            else                    { onError((res && res.data) ? res.data : 'Error.'); }
        }).fail(function (xhr, status) {
            onError(status === 'timeout' ? 'Request timed out.' : 'Network error (' + status + ').');
        });
    }

    // ── Toggle controls ────────────────────────────────────────────────────────

    function applyEnabledState(on) {
        $('#par-main-controls').toggle(on);
    }
    applyEnabledState($('#par-enabled').is(':checked'));
    $('#par-enabled').on('change', function () {
        applyEnabledState($(this).is(':checked'));
    });

    // ── Save settings ─────────────────────────────────────────────────────────

    $('#par-save-btn').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Saving…');
        parPost('csbr_par_save_settings', {
            par_enabled:    $('#par-enabled').is(':checked') ? '1' : '',
            par_window:     $('#par-window').val(),
            par_health_url: $('#par-health-url').val(),
        }, function (d) {
            $btn.prop('disabled', false).text('Save Automatic Crash Recovery Settings');
            parMsg('par-save-msg', d.msg || 'Saved.', true);
        }, function (err) {
            $btn.prop('disabled', false).text('Save Automatic Crash Recovery Settings');
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
        var $btn = $('#par-refresh-btn');
        $btn.prop('disabled', true).text('⟳ Refreshing…');
        parPost('csbr_par_get_status', {}, function (d) {
            $btn.prop('disabled', false).text('⟳ Refresh');
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
        }, function (err) {
            $btn.prop('disabled', false).text('⟳ Refresh');
            var msg = '<p style="color:#b71c1c;font-size:0.88rem;">Could not load status — ' + err + '</p>';
            $('#par-monitors-body').html(msg);
            $('#par-history-body').html(msg);
        });
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
            $body.html('<p style="color:#78909c;font-size:0.88rem;">No active monitors. Automatic Crash Recovery will start monitoring automatically when a plugin is updated.</p>');
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
        csShowExplain('Automatic Crash Recovery', [
            '<div style="background:#fff3e0;border-left:4px solid #f57c00;padding:12px 16px;border-radius:0 6px 6px 0;margin-bottom:16px;">',
            '<strong style="color:#e65100;">Did you know?</strong> Plugins are the single most common cause of WordPress site crashes. ',
            'A bad update can introduce a PHP fatal error that takes your entire site offline — often at night or over a weekend ',
            'when you are not watching. Automatic Crash Recovery detects this within minutes and restores the previous version automatically.',
            '</div>',
            '<p>Automatic Crash Recovery automatically backs up each plugin directory before WordPress applies an update, ',
            'then watches your site for failures. If something goes wrong it rolls back to the previous version — ',
            'without any manual intervention.</p>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">How it works</p>',
            '<ol style="margin:0 0 16px 1.2em;padding:0;line-height:1.9;">',
            '<li><strong>Pre-update backup</strong> — the plugin directory is copied to a secure location on the server ',
            'the moment WordPress begins an update (before the new files are placed).</li>',
            '<li><strong>Monitoring window</strong> — after the update completes, the system-cron watchdog probes the ',
            'health check URL every minute for the configured window (default 10 minutes).</li>',
            '<li><strong>Automatic rollback</strong> — two consecutive probe failures (5xx error or connection timeout) ',
            'trigger a rollback: the broken plugin directory is renamed and the backup is copied back. ',
            'This happens entirely outside of WordPress, so it works even during a PHP fatal error.</li>',
            '<li><strong>Notification</strong> — on the next WordPress page load after recovery, the rollback is recorded ',
            'in the Rollback History card. An email is sent to the address configured in the Notifications card. ',
            'If Twilio SMS is configured, an SMS is sent. If ntfy is configured, a push notification is sent.</li>',
            '<li><strong>Branded recovery page</strong> — while the site is in a crash state, visitors see a ',
            'branded CloudScale recovery page instead of a white screen of death. If the watchdog has detected ',
            'the problem and is rolling back, the page reads: <em>"CloudScale Automatic Crash Recovery is recovering ',
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
            '<li><strong>Enable Automatic Crash Recovery</strong> — turn the feature on or off. When disabled, no backups are ',
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

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">Rollback notifications</p>',
            '<p>All notification channels configured in the <strong>Notifications card</strong> (Local Backups tab) ',
            'will fire when a rollback occurs, as long as the "Plugin rollbacks" event is enabled on each channel.</p>',
            '<ul style="margin:0 0 16px 1.2em;line-height:1.9;">',
            '<li><strong>Email</strong> — always available. Configure the recipient address and enable the ',
            '"Plugin rollbacks" event in the Notifications card.</li>',
            '<li><strong>SMS via Twilio</strong> — optional. Requires a ',
            '<a href="https://www.twilio.com" target="_blank">Twilio</a> account. Enter your Account SID, Auth Token, ',
            'send-from number, and destination number, then enable "Plugin rollbacks". ',
            'Click <strong>Send Test SMS</strong> to verify credentials before saving.</li>',
            '<li><strong>Push via ntfy</strong> — optional. Enter your ntfy topic URL and enable "Plugin rollbacks". ',
            'Works with the hosted ntfy.sh service or any self-hosted ntfy server. ',
            'Click <strong>Send Test</strong> to verify before saving.</li>',
            '</ul>',
        ].join(''));
    };

    window.csParExplainWatchdog = function () {
        csShowExplain('Watchdog Script — System Cron Setup', [
            '<p>The watchdog is a bash script that runs on your server every minute via the system cron. ',
            'It is the engine that actually detects crashes and triggers rollbacks — completely independent of WordPress.</p>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">Why system cron, not WP-Cron?</p>',
            '<p>If a plugin update causes a PHP fatal error, WordPress crashes entirely — wp-cron.php never fires. ',
            'The system-cron watchdog runs every minute regardless of WordPress health. It can detect the problem and ',
            'restore the previous plugin version before most visitors ever see an error.</p>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">Setup steps</p>',
            '<ol style="margin:0 0 16px 1.2em;padding:0;line-height:1.9;">',
            '<li><strong>Copy the script</strong> — click the Copy button next to the script box, then paste it into ',
            '<code>/usr/local/bin/csbr-par-watchdog.sh</code> on your server and make it executable with ',
            '<code>sudo chmod +x /usr/local/bin/csbr-par-watchdog.sh</code>.</li>',
            '<li><strong>Add the cron line</strong> — run <code>sudo crontab -e</code> and paste in the cron line shown. ',
            'This schedules the script to run as root every minute.</li>',
            '<li><strong>Verify</strong> — within 2 minutes the Watchdog status indicator in this card header should ',
            'turn green and show "running (Xs ago)". Amber means it has not run recently — check the cron job.',
            '</li>',
            '</ol>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">What the script does each minute</p>',
            '<ul style="margin:0 0 16px 1.2em;line-height:1.9;">',
            '<li>Reads the active monitors from a state file written by this plugin.</li>',
            '<li>Probes the health check URL. A 5xx response or connection failure counts as a failure.</li>',
            '<li>If two consecutive failures are detected within the monitoring window, it renames the broken plugin ',
            'directory and copies the pre-update backup back into place — no WordPress or WP-CLI required.</li>',
            '<li>Sends an email notification and optionally flushes the WP cache via WP-CLI.</li>',
            '<li>Updates the state file so this admin panel reflects the rollback on your next page load.</li>',
            '</ul>',

            '<p style="font-size:0.85rem;color:#64748b;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;">',
            '<strong>Note:</strong> the script must be re-generated and re-installed whenever the backup directory path ',
            'changes (e.g. if you move WordPress). Click <strong>Copy</strong> and re-paste to update it on the server.',
            '</p>',
        ].join(''));
    };

    window.csParExplainMonitors = function () {
        csShowExplain('Active Monitors', [
            '<p>A monitor is created automatically each time a plugin is updated. It tracks that plugin for the ',
            'configured monitoring window (default 10 minutes) and triggers a rollback if the site fails health checks.</p>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">What each column means</p>',
            '<ul style="margin:0 0 16px 1.2em;line-height:1.9;">',
            '<li><strong>Plugin</strong> — the plugin that was just updated.</li>',
            '<li><strong>Version</strong> — the version before the update (left) and after (right). ',
            'If a rollback fires, the plugin is restored to the "before" version.</li>',
            '<li><strong>Time Remaining</strong> — countdown to when this monitor expires. Once it reaches zero the ',
            'watchdog stops probing for this plugin. Any red fail count shows how many consecutive probe failures ',
            'have been detected so far — a second failure would trigger a rollback.</li>',
            '<li><strong>Roll Back Now</strong> — manually trigger an immediate rollback without waiting for the ',
            'watchdog to detect failures. Use this if you spot a problem before the watchdog does.</li>',
            '</ul>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">Normal flow</p>',
            '<p>During a healthy update the monitor appears here, counts down for the configured window, then disappears. ',
            'No action is needed. The monitor is only acting if a failure is detected.</p>',

            '<p style="font-size:0.85rem;color:#64748b;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;">',
            '<strong>Tip:</strong> click <strong>Refresh</strong> at any time to reload the current monitor state. ',
            'While at least one monitor is active the panel also auto-refreshes every 15 seconds.',
            '</p>',
        ].join(''));
    };

    window.csParExplainHistory = function () {
        csShowExplain('Rollback History', [
            '<p>Every time Automatic Crash Recovery rolls back a plugin — whether triggered by the watchdog or manually — ',
            'an entry is added here. History is kept for up to 50 events.</p>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">What each column means</p>',
            '<ul style="margin:0 0 16px 1.2em;line-height:1.9;">',
            '<li><strong>Plugin</strong> — the plugin that was rolled back.</li>',
            '<li><strong>Versions</strong> — the failed version (left) and the version it was restored to (right).</li>',
            '<li><strong>Rolled Back</strong> — the date and time the rollback completed (UTC).</li>',
            '<li><strong>Trigger</strong> — <em>Watchdog / site failure</em> means the system cron detected consecutive ',
            'HTTP failures and acted automatically. <em>Manual rollback</em> means an admin clicked Roll Back Now.</li>',
            '<li><strong>Dismiss</strong> — removes the entry from this list. It does not undo the rollback or ',
            'reactivate the updated plugin — it only clears the record from the admin view.</li>',
            '</ul>',

            '<p style="margin:0 0 10px;font-size:1.05em;font-weight:800;color:#0f172a;">What to do after a rollback</p>',
            '<ul style="margin:0 0 16px 1.2em;line-height:1.9;">',
            '<li>Check your site is working correctly at the version shown in the "Versions" column.</li>',
            '<li>Check the plugin author\'s changelog or support forum for reports of the same issue.</li>',
            '<li>Wait for a patched release, then re-update. Automatic Crash Recovery will monitor the new update too.</li>',
            '<li>Each rollback also appears in the Activity Log (main Backup tab) and an email is sent to the admin address.</li>',
            '</ul>',
        ].join(''));
    };

});

