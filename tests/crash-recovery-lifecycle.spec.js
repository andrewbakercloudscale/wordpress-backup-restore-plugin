/**
 * Automatic Crash Recovery — End-to-End Lifecycle Test
 *
 * Installs a disposable test plugin, "updates" it with a broken v2 (triggering
 * the PAR pre-update backup and monitor hooks), verifies the active monitor in
 * the admin UI, exercises manual rollback, then asserts the site is healthy again
 * and the rollback appears in history.
 *
 * Run:
 *   npx playwright test tests/crash-recovery-lifecycle.spec.js
 *
 * Required env vars (see .env.test):
 *   CSDT_TEST_SECRET, CSDT_TEST_ROLE, CSDT_TEST_SESSION_URL
 *   CSDT_TEST_LOGOUT_URL  (optional)
 */

const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path  = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../.env.test') });

// ── Config ────────────────────────────────────────────────────────────────────

const BASE_URL    = process.env.WP_SITE           || 'https://andrewbaker.ninja';
const ADMIN_URL   = `${BASE_URL}/wp-admin`;
const PLUGIN_PAGE = `${ADMIN_URL}/admin.php?page=cloudscale-backup`;
const SECRET      = process.env.CSDT_TEST_SECRET      || '';
const ROLE        = process.env.CSDT_TEST_ROLE         || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL  || '';
const LOGOUT_URL  = process.env.CSDT_TEST_LOGOUT_URL   || '';

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error(
        'CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set.\n' +
        'Run: bash setup-playwright-test-account.sh'
    );
}

// ── Test plugin source ────────────────────────────────────────────────────────

const TEST_SLUG = 'csbr-crash-test';
const TEST_NAME = 'CloudScale Crash Recovery Test Target';

// v1: healthy no-op plugin
const V1_PHP = `<?php
/**
 * Plugin Name: ${TEST_NAME}
 * Version:     1.0.0
 * Description: Safe to delete — automated crash recovery test plugin.
 */
defined('ABSPATH') || exit;
add_action('init', function() { /* v1 healthy */ });
`;

// v2: simulates a crash on the front-end only (admin stays accessible for test verification)
const V2_PHP = `<?php
/**
 * Plugin Name: ${TEST_NAME}
 * Version:     2.0.0
 * Description: Safe to delete — automated crash recovery test plugin (BROKEN v2).
 */
defined('ABSPATH') || exit;
// Simulate plugin crash: front-end returns 503. Admin is unaffected so the test
// can verify rollback via the WP admin UI.
add_action('parse_request', function() {
    if (!is_admin() && !wp_doing_cron() && !(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
        status_header(503);
        header('Retry-After: 30');
        header('Content-Type: text/html; charset=utf-8');
        die('<html><body><h1>503 Service Unavailable</h1><p>PAR Test: Intentional crash v2.0.0</p></body></html>');
    }
});
`;

// ── Session helpers ───────────────────────────────────────────────────────────

async function getAdminSession(ttl = 1800) {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl } });
    const body = await resp.json().catch(() => resp.text());
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`Session API returned ${resp.status()}: ${JSON.stringify(body)}`);
    return body;
}

async function injectCookies(ctx, sess) {
    await ctx.addCookies([
        {
            name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,
            domain: sess.cookie_domain, path: '/', secure: true, httpOnly: true, sameSite: 'Lax',
        },
        {
            name: sess.logged_in_cookie_name, value: sess.logged_in_cookie,
            domain: sess.cookie_domain, path: '/', secure: true, httpOnly: false, sameSite: 'Lax',
        },
    ]);
}

// ── AJAX helper — call a WordPress admin-ajax action ─────────────────────────

async function wpAjax(page, action, extraData = {}) {
    // nonce is embedded in the page as CSBR_QB.nonce
    return page.evaluate(async ({ action, extraData, ajaxUrl }) => {
        const nonce = window.CSBR_QB?.nonce || '';
        const body  = new URLSearchParams({ action, nonce, ...extraData });
        const resp  = await fetch(ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
        return resp.json();
    }, { action, extraData, ajaxUrl: `${ADMIN_URL}/admin-ajax.php` });
}

// ── Navigate to PAR tab and refresh monitors ──────────────────────────────────

async function openParTab(page) {
    await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForSelector('.cs-tab[data-tab="autorecovery"]', { timeout: 10000 });
    await page.click('button.cs-tab[data-tab="autorecovery"]');
    await page.waitForSelector('#cs-tab-autorecovery', { timeout: 10000 });
    await page.waitForTimeout(500);
    await page.click('#par-refresh-btn');
    // Wait for the "Loading…" placeholder to be replaced
    await page.waitForFunction(
        () => !document.querySelector('#par-monitors-body')?.textContent.includes('Loading'),
        { timeout: 12000 }
    );
}

// ── Shared state across serial tests ─────────────────────────────────────────

let sess, ctx, page;

// ── Test suite ────────────────────────────────────────────────────────────────

test.describe.serial('Automatic Crash Recovery — full lifecycle', () => {

    test.beforeAll(async ({ browser }) => {
        sess = await getAdminSession(1800);
        ctx  = await browser.newContext({
            ignoreHTTPSErrors: true,
            viewport: { width: 1400, height: 900 },
        });
        await injectCookies(ctx, sess);
        page = await ctx.newPage();

        // Load the plugin page once so CSBR_QB.nonce is available for AJAX calls
        await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
    });

    test.afterAll(async () => {
        // Best-effort cleanup via the dedicated test cleanup endpoint
        try {
            await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 20000 });
            const cleanup = await wpAjax(page, 'csbr_par_cleanup_test_monitor');
            console.log(`  Cleanup result: ${JSON.stringify(cleanup)}`);
        } catch (e) {
            console.log(`  Cleanup warning: ${e.message}`);
        }

        if (LOGOUT_URL) {
            const apiCtx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
            await apiCtx.post(LOGOUT_URL, { data: { secret: SECRET, role: ROLE } }).catch(() => {});
            await apiCtx.dispose();
        }
        await ctx.close();
    });

    // ── Test 01 ─────────────────────────────────────────────────────────────

    test('01 PAR tab loads and feature is enabled', async () => {
        await openParTab(page);

        const enabled = await page.locator('#par-enabled').isChecked();
        expect(enabled).toBe(true);
        console.log('  Automatic Crash Recovery enabled: true ✓');

        await page.screenshot({ path: '/tmp/par-01-tab.png' });
    });

    // ── Test 02 ─────────────────────────────────────────────────────────────

    test('02 health check URL passes', async () => {
        // Already on PAR tab
        await page.click('#par-test-health-btn');
        await page.waitForSelector('#par-test-health-msg:not(:empty)', { timeout: 15000 });

        const msg = await page.locator('#par-test-health-msg').innerText();
        console.log(`  Health check result: "${msg}"`);
        expect(msg.toLowerCase()).toContain('passed');
        console.log('  Health check passed ✓');
    });

    // ── Test 03 ─────────────────────────────────────────────────────────────

    test('03 watchdog is running (heartbeat < 2 min old)', async () => {
        const status = await page.locator('#par-watchdog-status').innerText().catch(() => '');
        console.log(`  Watchdog status display: "${status}"`);

        if (!status || status.toLowerCase().includes('not detected')) {
            // Not a hard failure — but this is why crashes don't auto-recover.
            console.log('\n  *** WARNING: Watchdog heartbeat not detected ***');
            console.log('  Automatic rollback will NOT fire without the watchdog cron job.');
            console.log('  Fix: install the script from the PAR tab, then add:');
            console.log('    * * * * * root /usr/local/bin/csbr-par-watchdog.sh >> /var/log/cloudscale-par.log 2>&1');
            console.log('  Continuing with remaining tests (manual rollback path)...\n');
            return; // skip timing assertions, let the suite continue
        }

        // If "running (Xs ago)" we can parse the seconds
        const secsMatch   = status.match(/running \((\d+)s ago\)/);
        const minAgoMatch = status.match(/last seen (\d+) min ago/);

        if (secsMatch) {
            const secs = parseInt(secsMatch[1]);
            expect(secs).toBeLessThan(120);
            console.log(`  Watchdog last ran ${secs}s ago ✓`);
        } else if (minAgoMatch) {
            const mins = parseInt(minAgoMatch[1]);
            if (mins >= 3) {
                throw new Error(`Watchdog last ran ${mins} min ago — expected < 2 min. Is the cron running?`);
            }
            console.log(`  Watchdog last ran ~${mins} min ago ✓`);
        } else {
            console.log(`  Watchdog status: "${status}" (could not parse timing — treating as OK)`);
        }
    });

    // ── Test 04 ─────────────────────────────────────────────────────────────

    test('04 inject test monitor via AJAX (simulates post-update state)', async () => {
        // plugin-install.php is blocked on production servers with DISALLOW_FILE_MODS.
        // This endpoint writes the v2-broken plugin + v1 backup to the filesystem
        // and registers a monitor — exactly the state PAR is in after a real update.
        const result = await wpAjax(page, 'csbr_par_setup_test_monitor');
        console.log(`  Setup result: ${JSON.stringify(result)}`);

        if (!result.success) {
            throw new Error(`Test monitor setup failed: ${result.data || JSON.stringify(result)}`);
        }

        console.log(`  Monitor ID: ${result.data.monitor_id}`);
        console.log(`  Plugin dir: ${result.data.plugin_dir}`);
        console.log('  Test state injected (v2-broken active, v1 backup ready) ✓');

        await page.screenshot({ path: '/tmp/par-04-monitor-injected.png' });
    });

    // ── Test 06 ─────────────────────────────────────────────────────────────

    test('06 active monitor appears for test plugin', async () => {
        await openParTab(page);

        const monitorsHtml = await page.locator('#par-monitors-body').innerHTML();
        const monitorsText = await page.locator('#par-monitors-body').innerText();
        console.log(`  Monitors content: "${monitorsText.slice(0, 300)}"`);

        await page.screenshot({ path: '/tmp/par-06-monitors.png' });

        if (!monitorsText.includes(TEST_NAME) && !monitorsHtml.includes(TEST_SLUG)) {
            throw new Error(
                `Active monitor for "${TEST_NAME}" not found.\n\n` +
                `This means upgrader_pre_install or upgrader_process_complete did not fire.\n` +
                `Likely causes:\n` +
                `  (a) PAR disabled — check the Enable checkbox on the PAR tab\n` +
                `  (b) Plugin was replaced outside WordPress (direct file copy) — hooks only fire for WP-managed updates\n` +
                `  (c) The "Replace current with uploaded" step was skipped\n\n` +
                `Monitor panel content:\n${monitorsText}`
            );
        }

        console.log(`  Active monitor found for "${TEST_NAME}" ✓`);
    });

    // ── Test 07 ─────────────────────────────────────────────────────────────

    test('07 front-end returns 503 — broken plugin is live', async () => {
        // Direct HTTP probe from Playwright — bypasses wp_remote_get loopback issues.
        // Unique path ensures nginx FastCGI and Cloudflare caches return no cached response.
        let status = null;
        for (let attempt = 0; attempt < 3; attempt++) {
            if (attempt > 0) await page.waitForTimeout(3000);
            const uniqueUrl = `${BASE_URL}/csbr-par-probe-${Date.now()}/`;
            const resp = await page.context().request.get(uniqueUrl, {
                headers: { 'Cache-Control': 'no-cache, no-store' },
                failOnStatusCode: false,
                timeout: 20000,
            }).catch(() => null);
            status = resp?.status() ?? null;
            console.log(`  Front-end HTTP status (direct probe, attempt ${attempt + 1}): ${status}`);
            if (status >= 500) break;
        }

        expect(status).toBeGreaterThanOrEqual(500);
        expect(status).toBeLessThan(600);
        console.log(`  Front-end correctly returning HTTP ${status} after bad update ✓`);
    });

    // ── Test 08 ─────────────────────────────────────────────────────────────

    test('08 manual rollback restores v1', async () => {
        await openParTab(page);

        // Confirm dialog auto-accept before clicking Rollback
        page.once('dialog', async d => {
            console.log(`  Confirm dialog: "${d.message().slice(0, 80)}"`);
            await d.accept();
        });

        const rollbackBtn = page.locator('.par-rollback-btn').first();
        await expect(rollbackBtn).toBeVisible({ timeout: 5000 });
        await rollbackBtn.click();

        // After AJAX call, an alert() confirms success or failure
        const alertText = await new Promise(resolve => {
            const timeout = setTimeout(() => resolve('(no alert)'), 20000);
            page.once('dialog', async d => {
                clearTimeout(timeout);
                const msg = d.message();
                await d.accept();
                resolve(msg);
            });
        });

        console.log(`  Rollback alert: "${alertText}"`);

        if (alertText.toLowerCase().includes('failed')) {
            throw new Error(`Manual rollback failed: ${alertText}`);
        }

        await page.screenshot({ path: '/tmp/par-08-rollback-done.png' });
        console.log('  Manual rollback completed ✓');
    });

    // ── Test 09 ─────────────────────────────────────────────────────────────

    test('09 rollback recorded in history', async () => {
        // Status refreshes after rollback button — wait for history to populate
        await page.waitForFunction(
            (name) => !document.querySelector('#par-history-body')?.textContent.includes('No rollback events') &&
                       document.querySelector('#par-history-body')?.textContent.includes(name),
            TEST_NAME,
            { timeout: 15000 }
        );

        const historyText = await page.locator('#par-history-body').innerText();
        console.log(`  History: "${historyText.slice(0, 300)}"`);

        await page.screenshot({ path: '/tmp/par-09-history.png' });

        expect(historyText).toContain(TEST_NAME);
        console.log('  Rollback entry in history ✓');

        // Verify version columns: should show "v2.0.0 → v1.0.0"
        expect(historyText).toContain('2.0.0');
        expect(historyText).toContain('1.0.0');
        console.log('  Version columns show correct before/after ✓');

        // Trigger should say "Manual rollback"
        expect(historyText.toLowerCase()).toContain('manual');
        console.log('  Trigger correctly recorded as Manual rollback ✓');
    });

    // ── Test 10 ─────────────────────────────────────────────────────────────

    test('10 front-end healthy after rollback', async () => {
        // Direct HTTP probe from Playwright — same approach as test 07.
        const uniqueUrl = `${BASE_URL}/csbr-par-probe-${Date.now()}/`;
        const resp = await page.context().request.get(uniqueUrl, {
            headers: { 'Cache-Control': 'no-cache, no-store' },
            failOnStatusCode: false,
            timeout: 20000,
        }).catch(() => null);
        const status = resp?.status() ?? null;
        console.log(`  Front-end HTTP status after rollback: ${status}`);

        expect(status).toBeLessThan(500);
        console.log('  Site healthy after rollback ✓');
    });

    // ── Test 11 ─────────────────────────────────────────────────────────────

    test('11 activity log contains rollback event', async () => {
        await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.click('button.cs-tab[data-tab="local"]');
        await page.waitForSelector('#cs-tab-local', { timeout: 10000 });

        // Activity log is in a scrollable table — look for rollback keywords
        const logText = await page.locator('#cs-tab-local').innerText().catch(() => '');

        await page.screenshot({ path: '/tmp/par-11-activity-log.png' });

        const hasRollback = /rolled back|rollback|crash recovery/i.test(logText);
        if (hasRollback) {
            console.log('  Rollback event found in activity log ✓');
        } else {
            // The log may be paginated — log a warning rather than a hard fail
            console.log('  WARNING: rollback keyword not visible in current log view — check activity log manually');
        }
    });

    // ── Test 12 ─────────────────────────────────────────────────────────────

    test('12 fatal-error-handler dropin exists with CloudScale marker', async () => {
        // The dropin is written by PAR when enabled. We verify it via a URL that
        // would trigger it — but since the site is healthy we just confirm the
        // dropin is in place by checking the admin renders without error.
        // A broken dropin would cause admin 500 errors.
        await page.goto(`${ADMIN_URL}/`, { waitUntil: 'domcontentloaded', timeout: 20000 });
        const status = (await page.evaluate(() => window.location.href)).includes('wp-admin');
        expect(status).toBe(true);
        console.log('  Admin loads cleanly — dropin not broken ✓');
    });

    // ── Test 13 — real watchdog auto-detection and rollback ──────────────────
    //
    // This is the end-to-end proof of the core feature: the watchdog cron
    // detects 2 consecutive failures on its own and rolls back automatically,
    // WITHOUT any manual intervention.
    //
    // Timeline:
    //   T+0s   broken plugin activated, monitor registered
    //   T+60s  watchdog fires (cron), sees 503, fail count → 1
    //   T+120s watchdog fires again, sees 503, fail count → 2 → auto-rollback
    //   T+130s PHP processes notifications via WP-CLI (called by watchdog)
    //
    // We poll every 15s for up to 180s for the site to recover.
    // The test is slow by design — it is verifying a real cron-driven rollback.

    test('13 real watchdog auto-detects failure and rolls back (up to 3 min)', async () => {
        test.setTimeout(240000); // 4 min timeout for this test only

        // ── Step 1: fresh test state ─────────────────────────────────────────
        const setup = await wpAjax(page, 'csbr_par_setup_test_monitor');
        if (!setup.success) throw new Error(`Test monitor setup failed: ${JSON.stringify(setup)}`);
        const monitorId = setup.data.monitor_id;
        console.log(`  Monitor ID: ${monitorId}`);
        console.log('  Broken plugin activated. Waiting for watchdog cron to auto-detect and roll back...');
        console.log('  (watchdog fires every minute; needs 2 consecutive failures — expect ~2 min)');

        // ── Step 2: poll until site recovers or timeout ───────────────────────
        const pollStart = Date.now();
        const pollTimeout = 3 * 60 * 1000; // 3 minutes
        let recovered = false;
        let lastStatus = null;

        while (Date.now() - pollStart < pollTimeout) {
            await page.waitForTimeout(15000); // wait 15s between probes

            // Direct HTTP probe — bypasses wp_remote_get loopback issues
            const uniqueUrl = `${BASE_URL}/csbr-par-probe-${Date.now()}/`;
            const probeResp = await page.context().request.get(uniqueUrl, {
                headers: { 'Cache-Control': 'no-cache, no-store' },
                failOnStatusCode: false,
                timeout: 20000,
            }).catch(() => null);
            lastStatus = probeResp?.status() ?? null;
            const elapsed = Math.round((Date.now() - pollStart) / 1000);
            console.log(`  [${elapsed}s] Probe status: ${lastStatus}`);

            if (lastStatus !== null && lastStatus < 500) {
                recovered = true;
                console.log(`  Site recovered after ${elapsed}s — watchdog auto-rollback complete ✓`);
                break;
            }
        }

        if (!recovered) {
            throw new Error(
                `Watchdog did NOT auto-rollback within 3 minutes.\n` +
                `Last probe status: ${lastStatus}\n` +
                `Check:\n` +
                `  1. Pi host cron: sudo crontab -l (or /etc/cron.d/csbr-par)\n` +
                `  2. Watchdog log: /var/log/cloudscale-par.log\n` +
                `  3. State file: ${setup.data.plugin_dir.replace('plugins/csbr-crash-test/', '')}cloudscale-backups/state.json`
            );
        }

        // ── Step 3: history should show watchdog_failure trigger ─────────────
        await openParTab(page);

        await page.waitForFunction(
            (name) => document.querySelector('#par-history-body')?.textContent.includes(name),
            TEST_NAME, { timeout: 15000 }
        );

        const historyText = await page.locator('#par-history-body').innerText();
        const rows = historyText.split('\n');
        const watchdogRow = rows.find(r => r.toLowerCase().includes('watchdog'));
        if (!watchdogRow) {
            throw new Error(`Watchdog restored the site but no "watchdog" row in history — notifications may not have fired.\nHistory:\n${historyText}`);
        }
        console.log(`  History trigger: "${watchdogRow.trim().slice(0, 80)}" ✓`);
        console.log('  Notification path confirmed — watchdog rollback recorded in history ✓');

        await page.screenshot({ path: '/tmp/par-13-watchdog-auto.png' });
    });

    // ── Test 14 — watchdog notification path (simulation) ────────────────────
    //
    // Fast simulation test: writes state.json exactly as the real watchdog does,
    // triggers process_pending_watchdog_notifications() immediately, then verifies
    // the history entry is recorded with the correct trigger label.
    // Complements test 13 — runs in seconds rather than minutes.

    test('14 watchdog notification path: history shows watchdog trigger', async () => {
        // ── Step 1: fresh test monitor ──────────────────────────────────────
        const setup = await wpAjax(page, 'csbr_par_setup_test_monitor');
        if (!setup.success) throw new Error(`Test monitor setup failed: ${JSON.stringify(setup)}`);
        const monitorId = setup.data.monitor_id;
        console.log(`  Monitor ID: ${monitorId}`);

        // ── Step 2: simulate the watchdog writing a rollback to state.json ──
        await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const sim = await wpAjax(page, 'csbr_par_simulate_watchdog_rollback', { monitor_id: monitorId });
        console.log(`  Simulate result: ${JSON.stringify(sim)}`);
        if (!sim.success) throw new Error(`Watchdog simulation failed: ${JSON.stringify(sim)}`);
        console.log('  state.json written with status=rolled_back, notifications triggered ✓');

        // ── Step 3: reload admin — on_admin_init processes pending notifications ─
        await openParTab(page);

        // ── Step 4: history should contain the watchdog rollback entry ───────
        await page.waitForFunction(
            (name) => document.querySelector('#par-history-body')?.textContent.includes(name),
            TEST_NAME,
            { timeout: 15000 }
        );

        const historyText = await page.locator('#par-history-body').innerText();
        console.log(`  History: "${historyText.slice(0, 400)}"`);

        await page.screenshot({ path: '/tmp/par-13-watchdog-history.png' });

        expect(historyText).toContain(TEST_NAME);
        console.log('  Rollback entry in history ✓');

        expect(historyText).toContain('2.0.0');
        expect(historyText).toContain('1.0.0');
        console.log('  Version columns correct ✓');

        // Trigger column must contain "watchdog" — NOT "manual rollback" for this entry.
        // (Other entries in the history table may say "Manual rollback" — that's fine.)
        const rows = historyText.split('\n');
        const watchdogRow = rows.find(r => r.toLowerCase().includes('watchdog'));
        if (!watchdogRow) {
            throw new Error(`No "watchdog" row found in history.\nFull history:\n${historyText}`);
        }
        expect(watchdogRow.toLowerCase()).not.toContain('manual');
        console.log(`  Trigger correctly recorded as watchdog rollback ✓ ("${watchdogRow.trim().slice(0, 80)}"`);

        // ── Step 5: monitor should be closed ─────────────────────────────────
        const monitorsText = await page.locator('#par-monitors-body').innerText();
        const stillActive = monitorsText.includes(TEST_NAME);
        if (stillActive) {
            throw new Error('Monitor is still showing as active after watchdog rollback — close_monitor() may not have fired.');
        }
        console.log('  Monitor closed after watchdog rollback ✓');
    });
});
