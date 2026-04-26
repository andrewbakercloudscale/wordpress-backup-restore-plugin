const { test, request: playwrightRequest } = require('@playwright/test');
const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../.env.test') });

const BASE_URL    = process.env.WP_SITE || 'https://andrewbaker.ninja';
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

    // Measure actual computed styles for each tab
    const tabData = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('.cs-tab')).map(el => {
            const s = window.getComputedStyle(el);
            return {
                text:       el.textContent.trim(),
                color:      s.color,
                background: s.backgroundColor,
                fontSize:   s.fontSize,
                isActive:   el.classList.contains('cs-tab--active'),
            };
        });
    });

    // Helper: parse rgb(r,g,b) → relative luminance
    function luminance(rgbStr) {
        const m = rgbStr.match(/(\d+),\s*(\d+),\s*(\d+)/);
        if (!m) return 0;
        return [1, 2, 3].reduce((lum, i) => {
            const c = parseInt(m[i]) / 255;
            return lum + (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)) * [0.2126, 0.7152, 0.0722][i - 1];
        }, 0);
    }
    function contrast(fg, bg) {
        const l1 = luminance(fg), l2 = luminance(bg);
        const [hi, lo] = l1 > l2 ? [l1, l2] : [l2, l1];
        return (hi + 0.05) / (lo + 0.05);
    }

    console.log('\nTab computed styles:');
    for (const t of tabData) {
        const ratio = contrast(t.color, t.background);
        const pass  = ratio >= 4.5 ? '✓ PASS' : '✗ FAIL';
        console.log(`  "${t.text}" [${t.isActive ? 'active' : 'inactive'}] color:${t.color} bg:${t.background} contrast:${ratio.toFixed(1)}:1 ${pass}`);
        if (ratio < 4.5) {
            throw new Error(`Tab "${t.text}" contrast ratio ${ratio.toFixed(1)}:1 is below WCAG AA (4.5:1). color=${t.color} bg=${t.background}`);
        }
    }

    // Check tabs are horizontal — all on the same row
    const tabs = await page.locator('.cs-tab').all();
    const boxes = [];
    for (const tab of tabs) { const b = await tab.boundingBox(); if (b) boxes.push(b); }
    const ySpread = Math.max(...boxes.map(b => b.y)) - Math.min(...boxes.map(b => b.y));
    console.log(`\nY spread: ${ySpread}px (pass = < 10px)`);
    if (ySpread >= 10) throw new Error(`Tabs are not horizontal — y spread is ${ySpread}px`);
    console.log('All 3 tabs on same row ✓');

    await ctx.close();
});
