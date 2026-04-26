const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../.env.test') });

const BASE_URL    = process.env.WP_SITE             || 'https://your-wordpress-site.example.com';
const SECRET      = process.env.CSDT_TEST_SECRET    || '';
const ROLE        = process.env.CSDT_TEST_ROLE       || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL || '';
const LOGOUT_URL  = process.env.CSDT_TEST_LOGOUT_URL  || '';

const PLUGIN_URL = `${BASE_URL}/wp-admin/admin.php?page=cloudscale-backup`;

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set.\nRun: bash setup-playwright-test-account.sh');
}

async function getAdminSession(ttl = 900) {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl } });
    const body = await resp.json().catch(() => resp.text());
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`test-session API: ${resp.status()} ${JSON.stringify(body)}`);
    return body;
}

async function injectCookies(ctx, sess) {
    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,  domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,    domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: false, sameSite: 'Lax' },
    ]);
}

async function logoutTestUser() {
    if (!LOGOUT_URL) return;
    try {
        const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
        await ctx.post(LOGOUT_URL, { data: { secret: SECRET, role: ROLE } });
        await ctx.dispose();
    } catch {}
}

test('debug: API login then plugin page', async ({ browser }) => {
    // Get session via test-session API
    const sess = await getAdminSession();
    console.log('Got session cookies:', [sess.secure_auth_cookie_name, sess.logged_in_cookie_name]);

    // Create browser context with those cookies
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(context, sess);

    const page = await context.newPage();
    page.on('console', msg => {
        if (msg.type() === 'error') console.warn(`[JS ERROR] ${msg.text()}`);
    });

    await page.goto(PLUGIN_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
    console.log('Plugin URL:', page.url());
    await page.screenshot({ path: 'tests/debug-screenshot.png', fullPage: true });

    const title = await page.title();
    console.log('Page title:', title);

    // Check CSBR global
    const jsGlobal = await page.evaluate(() => {
        if (typeof window.CSBR !== 'undefined') return 'CSBR: ' + JSON.stringify(Object.keys(window.CSBR));
        if (typeof window.CS   !== 'undefined') return 'OLD CS: ' + JSON.stringify(Object.keys(window.CS));
        return 'NO CSBR/CS GLOBAL FOUND';
    });
    console.log('JS global:', jsGlobal);

    // Dump tabs
    const tabs = await page.locator('button[data-tab]').all();
    console.log(`Tabs (${tabs.length}):`);
    for (const t of tabs) {
        console.log('  data-tab =', await t.getAttribute('data-tab'), '|', (await t.textContent()).trim());
    }

    // Dump buttons
    const btns = await page.locator('button[id], button[onclick]').all();
    console.log(`\nButtons (${btns.length}):`);
    for (const b of btns) {
        const id = await b.getAttribute('id') || '';
        const oc = (await b.getAttribute('onclick') || '').substring(0, 70);
        const txt = (await b.textContent()).trim().substring(0, 30);
        console.log(`  id="${id}" onclick="${oc}" text="${txt}"`);
    }

    // Check for PHP errors
    const bodyText = await page.locator('body').textContent();
    if (/Fatal error|Parse error/i.test(bodyText)) {
        const idx = bodyText.search(/Fatal error|Parse error/i);
        console.error('PHP ERROR:', bodyText.substring(idx, idx + 200));
    } else {
        console.log('No PHP fatal errors detected');
    }

    // Check inputs
    const inputs = await page.locator('input[id]').all();
    console.log(`\nInputs (${inputs.length}):`);
    for (const inp of inputs) {
        const id  = await inp.getAttribute('id') || '';
        const val = await inp.inputValue().catch(() => '');
        const typ = await inp.getAttribute('type') || '';
        console.log(`  id="${id}" type="${typ}" value="${val.substring(0, 40)}"`);
    }

    await context.close();
    await logoutTestUser();
});
