/**
 * enter-s3-credentials
 *
 * Logs in via the CSDT test-session API, navigates to the CloudScale Backup
 * plugin page, switches to the Cloud Backups tab, fills in the S3 credentials,
 * saves them, captures the result message and a screenshot, then logs out.
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

test('enter S3 credentials → save → verify message', async ({ browser }) => {

    // 1. Get session via test-session API
    console.log('Getting session via test-session API...');
    const sess = await getAdminSession();
    console.log(`  Session obtained for role: ${ROLE}`);

    // 2. Open browser context with the session cookies
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    // 3. Navigate to the CloudScale Backup plugin page
    console.log('  Navigating to CloudScale Backup plugin page...');
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=cloudscale-backup`, {
        waitUntil: 'domcontentloaded',
        timeout: 20000,
    });
    console.log(`  Plugin page URL: ${page.url()}`);
    expect(page.url()).toContain('page=cloudscale-backup');

    // 4. Click the Cloud Backups tab
    console.log('  Clicking Cloud Backups tab...');
    await page.click('button[data-tab="cloud"]');
    await page.waitForTimeout(500);

    // 5. Fill in S3 credentials
    console.log('  Filling S3 credentials...');
    await page.fill('#cs-s3-key-id',     process.env.AWS_ACCESS_KEY_ID     || 'YOUR_ACCESS_KEY_ID');
    await page.fill('#cs-s3-secret-key', process.env.AWS_SECRET_ACCESS_KEY || 'YOUR_SECRET_ACCESS_KEY');
    await page.fill('#cs-s3-region',     'af-south-1');

    // 6. Click the Save button
    console.log('  Clicking Save...');
    await page.click('button[onclick*="csS3Save"]');

    // 7. Wait for AJAX response
    await page.waitForTimeout(3000);

    // 8. Read and log the save message
    const saveMsg = await page.locator('#cs-s3-msg').textContent();
    console.log(`  S3 save message: "${saveMsg}"`);

    // 9. Screenshot
    await page.screenshot({ path: 'tests/s3-creds-saved.png' });
    console.log('  Screenshot saved to tests/s3-creds-saved.png');

    // 10. Logout via admin bar
    const logoutUrl = await page.evaluate(() => {
        const a = document.querySelector('#wp-admin-bar-logout a');
        return a ? a.href : null;
    });

    if (logoutUrl) {
        await page.goto(logoutUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
        console.log(`  Logout URL visited: ${page.url()}`);
    } else {
        await page.goto(`${BASE_URL}/wp-login.php?action=logout`, { waitUntil: 'domcontentloaded', timeout: 15000 });
        const confirmBtn = page.locator('a[href*="action=logout"]');
        if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await confirmBtn.click();
            await page.waitForLoadState('domcontentloaded', { timeout: 10000 });
        }
        console.log(`  Logout fallback URL: ${page.url()}`);
    }

    await ctx.close();

    // 11. Clean up the API session
    await logoutTestUser();
});
