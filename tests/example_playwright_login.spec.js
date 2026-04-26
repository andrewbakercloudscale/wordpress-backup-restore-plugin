/**
 * example_playwright_login
 *
 * Demonstrates the test-session API login flow:
 * - Get session via CSDT test-session API
 * - Inject cookies into browser context
 * - Verify wp-admin is accessible
 * - Logout via WP admin bar
 * - Verify auth cookie is cleared
 */

const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../.env.test') });

const BASE_URL    = process.env.WP_SITE             || 'https://your-wordpress-site.example.com';
const SECRET      = process.env.CSDT_TEST_SECRET    || '';
const ROLE        = process.env.CSDT_TEST_ROLE       || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL || '';
const LOGOUT_URL  = process.env.CSDT_TEST_LOGOUT_URL  || '';

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

// ── Test ─────────────────────────────────────────────────────────────────────

test('test-session API → inject cookies → verify wp-admin → logout → verify cleared', async ({ browser }) => {

    // 1. Get session via test-session API
    console.log('Getting session via test-session API...');
    const sess = await getAdminSession();
    console.log(`  Session obtained for role: ${ROLE}`);

    // 2. Open browser context, inject session cookies
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    // 3. Navigate to wp-admin and verify we are logged in
    await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    console.log(`  After login, URL: ${page.url()}`);

    expect(page.url()).toContain('/wp-admin');
    await page.waitForSelector('#wpadminbar', { timeout: 5000 });
    console.log('  Logged in successfully ✓');

    await page.screenshot({ path: 'tests/example_playwright_login_loggedin.png' });

    // 4. Logout via the WP logout URL embedded in the admin bar
    const logoutUrl = await page.evaluate(() => {
        const a = document.querySelector('#wp-admin-bar-logout a');
        return a ? a.href : null;
    });

    if (logoutUrl) {
        await page.goto(logoutUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
        console.log(`  Logout URL visited: ${page.url()}`);
    } else {
        // Fallback: navigate to logout endpoint directly
        await page.goto(`${BASE_URL}/wp-login.php?action=logout`, { waitUntil: 'domcontentloaded', timeout: 15000 });
        const confirmBtn = page.locator('a[href*="action=logout"]');
        if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await confirmBtn.click();
            await page.waitForLoadState('domcontentloaded', { timeout: 10000 });
        }
        console.log(`  Logout fallback URL: ${page.url()}`);
    }

    await page.screenshot({ path: 'tests/example_playwright_login_loggedout.png' });

    // 5. Verify the auth cookie is gone from the browser context
    const postLogoutCookies = await ctx.cookies();
    const stillLoggedIn = postLogoutCookies.some(c => c.name.startsWith('wordpress_logged_in_'));
    expect(stillLoggedIn, 'Expected auth cookie to be cleared after logout').toBe(false);
    console.log('  Logged out successfully ✓');

    await ctx.close();

    // 6. Clean up the API session
    await logoutTestUser();
});
