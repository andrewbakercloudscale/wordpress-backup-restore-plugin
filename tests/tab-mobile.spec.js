const { test, request: playwrightRequest } = require('@playwright/test');
const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../.env.test') });

const BASE_URL    = process.env.WP_SITE || 'https://your-wordpress-site.example.com';
const PLUGIN_URL  = `${BASE_URL}/wp-admin/admin.php?page=cloudscale-backup`;
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

async function getAdminSession(ttl = 1200) {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl } });
    const body = await resp.json().catch(() => resp.text());
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`test-session API returned ${resp.status()}: ${JSON.stringify(body)}`);
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

test.afterAll(async () => {
    if (!LOGOUT_URL) return;
    const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    await ctx.post(LOGOUT_URL, { data: { secret: SECRET, role: ROLE } }).catch(() => {});
    await ctx.dispose();
});

test('tab bar on mobile 390px', async ({ browser }) => {
    const sess = await getAdminSession();

    const ctx = await browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2,
        isMobile: true,
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(PLUGIN_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForSelector('.cs-tab-bar', { timeout: 10000 });
    await page.waitForTimeout(800);

    // Screenshot the full tab bar area
    await page.locator('.cs-tab-bar').screenshot({ path: '/tmp/tab-bar-mobile.png' });

    // Check all tabs and their y-positions — all should be on the same row
    const tabs = await page.locator('.cs-tab').all();
    console.log('Tab count:', tabs.length);
    let maxY = -Infinity, minY = Infinity;
    for (const tab of tabs) {
        const box  = await tab.boundingBox();
        const text = await tab.textContent();
        console.log(`Tab "${text.trim()}" box:`, JSON.stringify(box));
        if (box) {
            if (box.y < minY) minY = box.y;
            if (box.y > maxY) maxY = box.y;
        }
    }

    const ySpread = maxY - minY;
    console.log(`Y spread across tabs: ${ySpread}px (pass = < 10px)`);
    if (ySpread >= 10) {
        throw new Error(`Tab bar is wrapping: y spread is ${ySpread}px. Third tab is on a different row.`);
    }

    await ctx.close();
});
