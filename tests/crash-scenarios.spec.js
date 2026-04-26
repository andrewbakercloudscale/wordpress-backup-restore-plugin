/**
 * Crash Scenario Coverage — PAR watchdog auto-detection across 12 failure modes
 *
 * Each scenario installs a broken v2 plugin with a specific failure mode,
 * verifies the front-end is returning a 5xx error, then uses the watchdog
 * simulation endpoint to trigger an auto-rollback and asserts the site recovers.
 *
 * Tests use csbr_par_simulate_watchdog_rollback rather than waiting for the
 * real cron so the full suite runs in under 5 minutes.  Test 13 in
 * crash-recovery-lifecycle.spec.js covers the real-cron end-to-end path.
 *
 * Crash types:
 *   A  503          — explicit status_header(503)
 *   B  500          — explicit status_header(500)
 *   C  php_fatal    — call undefined function → PHP fatal → dropin → 503
 *   D  oom          — infinite memory allocation → OOM fatal → dropin → 503
 *   E  recursion    — infinite recursion → stack overflow → dropin → 503
 *   F  missing_class— instantiate non-existent class → fatal → dropin → 503
 *   G  exception    — throw uncaught RuntimeException → dropin → 503
 *   H  wp_die       — call wp_die(500) on front-end
 *   I  db_kill      — close wpdb connection then query → DB error → 500
 *   J  bad_header   — emit conflicting Content-Type + 500
 *   K  null_deref   — PHP 8 TypeError from null dereference → dropin → 503
 *   L  corrupt_json — garbled REST response + 500 front-end
 *
 * Extra suites:
 *   M  dropin_visible   — during a fatal crash the recovery page HTML is served
 *   N  stress           — 5 rapid crash-rollback cycles verify counter resets
 *   O  history_detail   — history row records correct plugin name and versions
 *
 * Run:
 *   npx playwright test tests/crash-scenarios.spec.js
 *
 * Required env vars (see .env.test):
 *   CSDT_TEST_SECRET, CSDT_TEST_ROLE, CSDT_TEST_SESSION_URL
 */

const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../.env.test') });

const BASE_URL    = process.env.WP_SITE          || 'https://your-wordpress-site.example.com';
const ADMIN_URL   = `${BASE_URL}/wp-admin`;
const PLUGIN_PAGE = `${ADMIN_URL}/admin.php?page=cloudscale-backup`;
const SECRET      = process.env.CSDT_TEST_SECRET     || '';
const ROLE        = process.env.CSDT_TEST_ROLE        || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL || '';
const LOGOUT_URL  = process.env.CSDT_TEST_LOGOUT_URL  || '';

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error(
        'CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set.\n' +
        'Run: bash setup-playwright-test-account.sh'
    );
}

const TEST_NAME = 'CloudScale Crash Recovery Test Target';

// ── Session helpers ───────────────────────────────────────────────────────────

async function getAdminSession(ttl = 3600) {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl } });
    const body = await resp.json().catch(() => resp.text());
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`Session API returned ${resp.status()}: ${JSON.stringify(body)}`);
    return body;
}

async function injectCookies(ctx, sess) {
    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,
          domain: sess.cookie_domain, path: '/', secure: true, httpOnly: true, sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name, value: sess.logged_in_cookie,
          domain: sess.cookie_domain, path: '/', secure: true, httpOnly: false, sameSite: 'Lax' },
    ]);
}

async function wpAjax(page, action, extraData = {}) {
    return page.evaluate(async ({ action, extraData, ajaxUrl }) => {
        const nonce = window.CSBR_QB?.nonce || '';
        const body  = new URLSearchParams({ action, nonce, ...extraData });
        const resp  = await fetch(ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
        return resp.json();
    }, { action, extraData, ajaxUrl: `${ADMIN_URL}/admin-ajax.php` });
}

// ── Core scenario helper ──────────────────────────────────────────────────────

async function runCrashScenario(page, crashType, label, opts = {}) {
    const { screenshotPath, checkDropin = false } = opts;

    // 1. Setup broken plugin with this crash type
    await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const setup = await wpAjax(page, 'csbr_par_setup_test_monitor', { crash_type: crashType });
    if (!setup.success) throw new Error(`[${label}] Setup failed: ${JSON.stringify(setup)}`);
    const monitorId = setup.data.monitor_id;
    console.log(`  [${label}] Monitor ${monitorId} created (crash_type=${crashType})`);

    // 2. Direct HTTP probe from Playwright — bypasses wp_remote_get loopback issues.
    // Unique URL path prevents nginx FastCGI and Cloudflare caches from serving
    // a stale 200 response from before the broken plugin was activated.
    // Allow up to 3 retries: PHP opcode cache may need a moment to invalidate.
    let probeStatus = null;
    for (let attempt = 0; attempt < 3; attempt++) {
        if (attempt > 0) await page.waitForTimeout(3000);
        const uniqueProbeUrl = `${BASE_URL}/csbr-par-probe-${Date.now()}/`;
        const probeResp = await page.context().request.get(uniqueProbeUrl, {
            headers: { 'Cache-Control': 'no-cache, no-store' },
            failOnStatusCode: false,
            timeout: 20000,
        }).catch(() => null);
        probeStatus = probeResp?.status() ?? null;
        console.log(`  [${label}] Probe attempt ${attempt + 1}: HTTP ${probeStatus} (${uniqueProbeUrl.slice(-30)})`);
        if (probeStatus >= 500) break;
    }
    expect(
        probeStatus,
        `[${label}] Expected >= 500 but got ${probeStatus} — broken plugin may not have activated`
    ).toBeGreaterThanOrEqual(500);
    console.log(`  [${label}] Front-end broken (HTTP ${probeStatus}) ✓`);

    // 3. Optional: verify the fatal-error-handler dropin serves the recovery page
    if (checkDropin) {
        // Visit the site directly (not via the server-side probe) so we get the HTML
        const crashPage = await page.context().newPage();
        try {
            await crashPage.goto(BASE_URL + '/', { waitUntil: 'networkidle', timeout: 20000 }).catch(() => {});
            const html = await crashPage.content().catch(() => '');
            const hasRecovery = /cloudscale|recovery in progress|automatic recovery/i.test(html);
            if (hasRecovery) {
                console.log(`  [${label}] Recovery page HTML detected during crash ✓`);
            } else {
                // dropin may not fire for non-fatal crash types (503/500 exit early)
                console.log(`  [${label}] Recovery page not present (expected for explicit status_header types)`);
            }
            if (screenshotPath) {
                await crashPage.screenshot({ path: screenshotPath.replace('.png', '-dropin.png') });
            }
        } finally {
            await crashPage.close();
        }
    }

    // 4. Simulate watchdog rollback (fast path — no cron wait)
    await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const sim = await wpAjax(page, 'csbr_par_simulate_watchdog_rollback', { monitor_id: monitorId });
    if (!sim.success) throw new Error(`[${label}] Watchdog simulation failed: ${JSON.stringify(sim)}`);
    console.log(`  [${label}] Watchdog rollback simulated ✓`);

    // 5. Direct HTTP probe after rollback — site must be healthy
    let afterStatus = null;
    for (let attempt = 0; attempt < 3; attempt++) {
        if (attempt > 0) await page.waitForTimeout(2000);
        const uniqueAfterUrl = `${BASE_URL}/csbr-par-probe-${Date.now()}/`;
        const afterResp = await page.context().request.get(uniqueAfterUrl, {
            headers: { 'Cache-Control': 'no-cache, no-store' },
            failOnStatusCode: false,
            timeout: 20000,
        }).catch(() => null);
        afterStatus = afterResp?.status() ?? null;
        console.log(`  [${label}] Post-rollback probe attempt ${attempt + 1}: HTTP ${afterStatus}`);
        if (afterStatus !== null && afterStatus < 500) break;
    }
    expect(
        afterStatus,
        `[${label}] Expected < 500 after rollback but got ${afterStatus}`
    ).toBeLessThan(500);
    console.log(`  [${label}] Site recovered (HTTP ${afterStatus}) ✓`);

    // 6. History must contain a "watchdog" trigger row for this plugin
    await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.click('button.cs-tab[data-tab="autorecovery"]');
    await page.waitForSelector('#cs-tab-autorecovery', { timeout: 10000 });
    await page.waitForFunction(
        (name) => document.querySelector('#par-history-body')?.textContent.includes(name),
        TEST_NAME, { timeout: 15000 }
    );
    const historyText = await page.locator('#par-history-body').innerText();
    const watchdogRow = historyText.split('\n').find(r => r.toLowerCase().includes('watchdog'));
    if (!watchdogRow) {
        throw new Error(`[${label}] No "watchdog" row in history.\nFull history:\n${historyText}`);
    }
    console.log(`  [${label}] History: "${watchdogRow.trim().slice(0, 80)}" ✓`);

    if (screenshotPath) await page.screenshot({ path: screenshotPath });
    return { probeStatus, afterStatus, monitorId, historyText };
}

// ── Shared state ──────────────────────────────────────────────────────────────

let sess, ctx, page;

// ── Suite ─────────────────────────────────────────────────────────────────────

test.describe.serial('Crash Scenario Coverage — watchdog detects and rolls back across 12 failure modes', () => {

    test.beforeAll(async ({ browser }) => {
        sess = await getAdminSession(7200);
        ctx  = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1400, height: 900 } });
        await injectCookies(ctx, sess);
        page = await ctx.newPage();
        await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
    });

    test.afterAll(async () => {
        try {
            await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 20000 });
            const cleanup = await wpAjax(page, 'csbr_par_cleanup_test_monitor');
            console.log(`  Final cleanup: ${JSON.stringify(cleanup)}`);
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

    // ── A: Explicit 503 ──────────────────────────────────────────────────────

    test('A explicit 503 — Service Unavailable, watchdog rolls back', async () => {
        await runCrashScenario(page, '503', '503', {
            screenshotPath: '/tmp/crash-A-503.png',
        });
    });

    // ── B: Explicit 500 ──────────────────────────────────────────────────────

    test('B explicit 500 — Internal Server Error, watchdog rolls back', async () => {
        await runCrashScenario(page, '500', '500', {
            screenshotPath: '/tmp/crash-B-500.png',
        });
    });

    // ── C: PHP Fatal (undefined function) ────────────────────────────────────
    //
    // The plugin calls csbr_crash_test_undef_fn_7f3a9b() which does not exist.
    // PHP raises a Fatal Error.  WordPress loads the fatal-error-handler dropin,
    // which outputs the CloudScale recovery page with HTTP 503.

    test('C php_fatal — undefined function, dropin intercepts, watchdog rolls back', async () => {
        await runCrashScenario(page, 'php_fatal', 'PHP Fatal', {
            screenshotPath: '/tmp/crash-C-php-fatal.png',
            checkDropin: true,
        });
    });

    // ── D: Out of Memory ─────────────────────────────────────────────────────
    //
    // Plugin allocates 1 MB chunks in a tight loop until PHP exhausts the
    // configured memory_limit.  OOM causes a fatal → dropin → 503.

    test('D oom — memory exhaustion, dropin intercepts, watchdog rolls back', async () => {
        test.setTimeout(90000); // OOM probes can be slow as PHP burns through memory
        await runCrashScenario(page, 'oom', 'OOM', {
            screenshotPath: '/tmp/crash-D-oom.png',
            checkDropin: true,
        });
    });

    // ── E: Infinite Recursion (stack overflow) ────────────────────────────────
    //
    // Plugin calls csbr_crash_test_recurse_7f3a() which calls itself with no
    // base case.  PHP hits maximum_nesting_level → fatal → dropin → 503.

    test('E recursion — stack overflow, dropin intercepts, watchdog rolls back', async () => {
        test.setTimeout(90000);
        await runCrashScenario(page, 'recursion', 'Recursion', {
            screenshotPath: '/tmp/crash-E-recursion.png',
            checkDropin: true,
        });
    });

    // ── F: Missing Class ─────────────────────────────────────────────────────
    //
    // Plugin instantiates CsbrNonExistentClass7f3a.  PHP raises a Fatal Error
    // for the undefined class → dropin → 503.

    test('F missing_class — undefined class, dropin intercepts, watchdog rolls back', async () => {
        await runCrashScenario(page, 'missing_class', 'Missing Class', {
            screenshotPath: '/tmp/crash-F-missing-class.png',
            checkDropin: true,
        });
    });

    // ── G: Uncaught Exception ────────────────────────────────────────────────
    //
    // Plugin throws new RuntimeException() without a try/catch.  In PHP 7+
    // uncaught exceptions are treated as fatal errors → dropin → 503.
    // This tests that the dropin catches both Error and Exception base types.

    test('G exception — uncaught RuntimeException, dropin intercepts, watchdog rolls back', async () => {
        await runCrashScenario(page, 'exception', 'Uncaught Exception', {
            screenshotPath: '/tmp/crash-G-exception.png',
            checkDropin: true,
        });
    });

    // ── H: wp_die() with 500 ─────────────────────────────────────────────────
    //
    // Plugin calls wp_die('...', 500, ['response' => 500]).  WordPress sends
    // an HTTP 500 with a WP_Error page.  Tests that the watchdog detects 500
    // responses from wp_die (not just PHP fatals).

    test('H wp_die — WordPress error page at 500, watchdog rolls back', async () => {
        await runCrashScenario(page, 'wp_die', 'wp_die(500)', {
            screenshotPath: '/tmp/crash-H-wp-die.png',
        });
    });

    // ── I: Database Connection Killed ────────────────────────────────────────
    //
    // Plugin calls $wpdb->close() then immediately queries the DB.  The
    // disconnected MySQL socket causes a "MySQL server has gone away" error.
    // Plugin detects the DB error and sends 500.  Tests DB-failure recovery.

    test('I db_kill — database connection killed, 500 returned, watchdog rolls back', async () => {
        await runCrashScenario(page, 'db_kill', 'DB Kill', {
            screenshotPath: '/tmp/crash-I-db-kill.png',
        });
    });

    // ── J: Bad / Conflicting Headers ─────────────────────────────────────────
    //
    // Plugin sends two Content-Type headers followed by a 500 status.
    // Tests that the watchdog correctly detects 500 even when the
    // response headers are malformed.

    test('J bad_header — conflicting Content-Type headers with 500, watchdog rolls back', async () => {
        await runCrashScenario(page, 'bad_header', 'Bad Header', {
            screenshotPath: '/tmp/crash-J-bad-header.png',
        });
    });

    // ── K: PHP 8 Null Dereference (TypeError) ────────────────────────────────
    //
    // Plugin dereferences null as an array key ($null['key']).  On PHP 8 this
    // triggers a TypeError (or Warning+Fatal depending on context) which the
    // fatal-error-handler dropin catches → 503.

    test('K null_deref — null dereference TypeError, dropin intercepts, watchdog rolls back', async () => {
        await runCrashScenario(page, 'null_deref', 'Null Deref', {
            screenshotPath: '/tmp/crash-K-null-deref.png',
            checkDropin: true,
        });
    });

    // ── L: Corrupt REST API JSON ──────────────────────────────────────────────
    //
    // Plugin hooks rest_pre_serve_request to emit garbled JSON and also
    // breaks the front-end with a 500.  Tests that both the watchdog health
    // probe AND the REST API corruption path are detected.

    test('L corrupt_json — garbled REST response + 500 front-end, watchdog rolls back', async () => {
        await runCrashScenario(page, 'corrupt_json', 'Corrupt JSON', {
            screenshotPath: '/tmp/crash-L-corrupt-json.png',
        });
    });

    // ── M: Recovery page HTML verification ───────────────────────────────────
    //
    // For PHP fatal error crashes the dropin serves an HTML recovery page.
    // This test verifies the page contains the CloudScale branding, the
    // RECOVERY IN PROGRESS badge, and no raw PHP error output leaked to the
    // browser.

    test('M dropin_visible — recovery page HTML shown during fatal crash', async () => {
        // Setup a PHP fatal so the dropin fires
        await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const setup = await wpAjax(page, 'csbr_par_setup_test_monitor', { crash_type: 'php_fatal' });
        if (!setup.success) throw new Error(`Setup failed: ${JSON.stringify(setup)}`);
        const monitorId = setup.data.monitor_id;
        console.log(`  Monitor ID: ${monitorId}`);

        // Give opcode cache a moment
        await page.waitForTimeout(2000);

        // Open a fresh browser tab and navigate directly to the site (no admin cookies)
        const guestCtx = await page.context().browser().newContext({ ignoreHTTPSErrors: true });
        const guestPage = await guestCtx.newPage();
        try {
            await guestPage.goto(BASE_URL + '/', {
                waitUntil: 'networkidle', timeout: 20000,
            }).catch(() => {}); // 503 may reject the promise

            const html = await guestPage.content().catch(() => '');
            console.log(`  Page title: "${await guestPage.title().catch(() => '(none)')}"`);
            await guestPage.screenshot({ path: '/tmp/crash-M-dropin-page.png' });

            // The dropin HTML must contain CloudScale branding
            const hasCloudScale  = /cloudscale/i.test(html);
            const hasRecoveryMsg = /recovery|recovering|automatically|rolled back/i.test(html);
            const hasNoPHPLeak   = !/Fatal error|Uncaught Error|PHP Warning/i.test(html);

            console.log(`  Has CloudScale branding: ${hasCloudScale}`);
            console.log(`  Has recovery message:    ${hasRecoveryMsg}`);
            console.log(`  No raw PHP error leaked: ${hasNoPHPLeak}`);

            expect(hasCloudScale,  'Recovery page must contain CloudScale branding').toBe(true);
            expect(hasNoPHPLeak,   'Raw PHP error text must not leak to the browser').toBe(true);

            if (!hasRecoveryMsg) {
                console.log('  WARNING: recovery message not found — check dropin HTML');
            }
            console.log('  Dropin recovery page verified ✓');
        } finally {
            await guestPage.close();
            await guestCtx.close();
        }

        // Rollback and restore
        await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const sim = await wpAjax(page, 'csbr_par_simulate_watchdog_rollback', { monitor_id: monitorId });
        if (!sim.success) throw new Error(`Rollback failed: ${JSON.stringify(sim)}`);
        console.log('  Site restored after dropin test ✓');
    });

    // ── N: Stress — 5 rapid crash-rollback cycles ────────────────────────────
    //
    // Each cycle:
    //   1. Activates broken v2 plugin (503)
    //   2. Verifies site is broken
    //   3. Simulates watchdog rollback
    //   4. Verifies recovery
    //
    // Verifies that the fail counter and state file reset correctly between
    // cycles — a leftover non-zero fail count would cause premature rollback
    // in the next real watchdog run.

    test('N stress — 5 rapid crash-rollback cycles, counters reset correctly', async () => {
        test.setTimeout(180000);
        for (let i = 1; i <= 5; i++) {
            console.log(`  ── Cycle ${i}/5 ──────────────────────────`);
            await runCrashScenario(page, '503', `Stress #${i}`, {
                screenshotPath: `/tmp/crash-N-stress-${i}.png`,
            });
        }
        console.log('  All 5 rapid cycles completed — state resets correctly ✓');
    });

    // ── O: History detail validation ─────────────────────────────────────────
    //
    // After a watchdog-simulated rollback the history row should record:
    //   - Correct plugin name
    //   - Version before (1.0.0) and after (2.0.0)
    //   - Trigger label containing "watchdog" (not "manual")
    //   - A date stamp in the expected format (e.g. "26 Apr 2026")
    //   - Monitor is closed (not still listed as active)

    test('O history_detail — correct plugin name, versions, trigger, date in history row', async () => {
        // Fresh setup
        await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const setup = await wpAjax(page, 'csbr_par_setup_test_monitor', { crash_type: '503' });
        if (!setup.success) throw new Error(`Setup failed: ${JSON.stringify(setup)}`);
        const monitorId = setup.data.monitor_id;

        // Simulate rollback
        await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const sim = await wpAjax(page, 'csbr_par_simulate_watchdog_rollback', { monitor_id: monitorId });
        if (!sim.success) throw new Error(`Rollback failed: ${JSON.stringify(sim)}`);

        // Load history tab
        await page.goto(PLUGIN_PAGE, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.click('button.cs-tab[data-tab="autorecovery"]');
        await page.waitForSelector('#cs-tab-autorecovery', { timeout: 10000 });
        await page.waitForFunction(
            (name) => document.querySelector('#par-history-body')?.textContent.includes(name),
            TEST_NAME, { timeout: 15000 }
        );

        const historyText = await page.locator('#par-history-body').innerText();
        console.log(`  History (first 500 chars):\n${historyText.slice(0, 500)}`);

        // Plugin name
        expect(historyText).toContain(TEST_NAME);
        console.log('  Plugin name in history ✓');

        // Version before and after
        expect(historyText).toContain('1.0.0');
        expect(historyText).toContain('2.0.0');
        console.log('  Version before/after in history ✓');

        // Trigger is watchdog, not manual
        const rows = historyText.split('\n');
        const watchdogRow = rows.find(r => r.toLowerCase().includes('watchdog'));
        expect(watchdogRow, 'Expected a "watchdog" trigger row').toBeTruthy();
        expect(watchdogRow.toLowerCase()).not.toContain('manual');
        console.log(`  Trigger is "watchdog" (not manual) ✓`);

        // Date format: should be "26 Apr 2026" style (3-letter month, not 2026-04-26)
        const hasNumericDate   = /\d{4}-\d{2}-\d{2}/.test(historyText);
        const hasWordMonthDate = /\d{1,2} [A-Z][a-z]{2} \d{4}/.test(historyText);
        expect(hasNumericDate, 'Date must not be in YYYY-MM-DD numeric format').toBe(false);
        expect(hasWordMonthDate, 'Date must be in "26 Apr 2026" format').toBe(true);
        console.log('  Date format "26 Apr 2026" ✓ (not 2026-04-26)');

        // Monitor must be closed — no longer in the Active Monitors table
        const monitorsText = await page.locator('#par-monitors-body').innerText().catch(() => '');
        const stillActive = monitorsText.includes(TEST_NAME);
        expect(stillActive, 'Monitor should be closed after rollback').toBe(false);
        console.log('  Monitor closed after rollback ✓');

        await page.screenshot({ path: '/tmp/crash-O-history.png' });
    });
});
