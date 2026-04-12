/**
 * example_playwright_login
 *
 * Creates a temporary WordPress account, logs in via the browser,
 * verifies the session, logs out, then deletes the account.
 *
 * Uses SSH + PHP to create/delete the WP user (no WP-CLI required).
 * Uses the custom login URL /your-login-slug/ (not /wp-admin/).
 */

const { test, expect } = require('@playwright/test');
const { execSync }      = require('child_process');

const BASE_URL  = 'https://your-wordpress-site.example.com';
const LOGIN_URL = `${BASE_URL}/your-login-slug/`;
const PI_KEY    = `${process.env.HOME}/.ssh/pi_key`;
const PI_HOST   = 'pi@andrew-pi-5.local';

const TEMP_USER = 'pw_temp_test';
const TEMP_PASS = 'TmpPW2026!$x';
const TEMP_EMAIL = 'pw_temp_test@example-playwright.local';

/** Run a PHP snippet inside the WordPress container via SSH (uses a temp file to avoid quoting issues) */
function wpPhp(phpCode) {
    // Write PHP to a temp file on the Pi, copy into the container, execute, then clean up
    const tmpFile = `/tmp/pw_test_${Date.now()}.php`;
    const phpWithTag = `<?php\n${phpCode}`;
    // Use printf to write the file (avoids echo quoting issues with special chars)
    const writeCmd = `ssh -i ${PI_KEY} -o StrictHostKeyChecking=no -o ConnectTimeout=10 ${PI_HOST} `
        + `"cat > ${tmpFile}"`;
    execSync(writeCmd, { input: phpWithTag, timeout: 10000 });
    const runCmd = `ssh -i ${PI_KEY} -o StrictHostKeyChecking=no -o ConnectTimeout=10 ${PI_HOST} `
        + `"docker cp ${tmpFile} pi_wordpress:${tmpFile} && docker exec pi_wordpress php ${tmpFile}; docker exec pi_wordpress rm -f ${tmpFile}; rm -f ${tmpFile}"`;
    return execSync(runCmd, { timeout: 20000 }).toString().trim();
}

/** Create the temp user and exclude them from WP 2FA enforcement, returning its ID */
function createTempUser() {
    const php = [
        "require('/var/www/html/wp-load.php');",
        `$id = wp_create_user('${TEMP_USER}', '${TEMP_PASS}', '${TEMP_EMAIL}');`,
        "if (is_wp_error($id)) { echo 'ERROR:' . $id->get_error_message(); exit; }",
        // Give administrator role so wp-admin is accessible; subscriber role redirects to frontend
        "$u = new WP_User($id); $u->set_role('administrator');",
        // Set wp_2fa_enforcement_state = 'excluded' so WP 2FA skips enforcement for this user.
        // WP 2FA reads this meta before running any policy checks — 'excluded' bypasses the 2FA setup redirect.
        "update_user_meta($id, 'wp_2fa_enforcement_state', 'excluded');",
        "echo 'ID:' . $id;",
    ].join("\n");
    const out = wpPhp(php);
    const match = out.match(/ID:(\d+)/);
    if (!match) throw new Error('Failed to create temp user: ' + out);
    return parseInt(match[1], 10);
}

/** Delete the temp user */
function deleteTempUser(userId) {
    const php = [
        "require('/var/www/html/wp-load.php');",
        "require_once(ABSPATH . 'wp-admin/includes/user.php');",
        `wp_delete_user(${userId});`,
        "echo 'DELETED';",
    ].join("\n");
    wpPhp(php);
}

/** Cleanup any leftover temp user from a previous run */
function cleanupExistingTempUser() {
    const php = [
        "require('/var/www/html/wp-load.php');",
        "require_once(ABSPATH . 'wp-admin/includes/user.php');",
        `$u = get_user_by('login', '${TEMP_USER}');`,
        "if ($u) { wp_delete_user($u->ID); echo 'CLEANED:' . $u->ID; } else { echo 'NONE'; }",
    ].join("\n");
    return wpPhp(php);
}

// ── Test ─────────────────────────────────────────────────────────────────────

test('create temp account → login → verify → logout → delete', async ({ browser, request: api }) => {

    // 1. Create the temp WordPress user
    console.log('Creating temp user...');
    let userId;
    try {
        userId = createTempUser();
        console.log(`  Temp user created: ${TEMP_USER} (ID ${userId})`);
    } catch (err) {
        // If user already exists from a previous failed run, clean it up first
        console.warn('  Create failed — attempting cleanup of existing user, then retry');
        const cleaned = cleanupExistingTempUser();
        console.log(`  Cleanup result: ${cleaned}`);
        userId = createTempUser();
        console.log(`  Temp user created after cleanup: ${TEMP_USER} (ID ${userId})`);
    }

    // 2. Login via API request context (same pattern used across test suite)
    await api.get(LOGIN_URL);
    await api.post(LOGIN_URL, {
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Origin': BASE_URL,
            'Referer': LOGIN_URL,
        },
        data: `log=${encodeURIComponent(TEMP_USER)}&pwd=${encodeURIComponent(TEMP_PASS)}&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1`,
    });
    const state   = await api.storageState();
    const cookies = state.cookies.map(c => ({ ...c, domain: 'andrewbaker.ninja', secure: true }));

    const hasAuthCookie = cookies.some(c => c.name.startsWith('wordpress_logged_in_'));
    console.log(`  Auth cookie present: ${hasAuthCookie}`);
    expect(hasAuthCookie, 'Expected WordPress auth cookie after login').toBe(true);

    // 3. Open browser with the session cookies and verify we are logged in
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await ctx.addCookies(cookies);
    const page = await ctx.newPage();

    await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    console.log(`  After login, URL: ${page.url()}`);

    // Verify we landed on wp-admin (not 404 or login redirect)
    expect(page.url()).toContain('/wp-admin');
    // Verify WP admin chrome is present
    await page.waitForSelector('#wpadminbar', { timeout: 5000 });
    console.log('  Logged in successfully ✓');

    await page.screenshot({ path: 'tests/example_playwright_login_loggedin.png' });

    // 4. Logout via the WP logout URL
    const logoutUrl = await page.evaluate(() => {
        // wp_logout_url is embedded in the admin bar
        const a = document.querySelector('#wp-admin-bar-logout a');
        return a ? a.href : null;
    });

    if (logoutUrl) {
        await page.goto(logoutUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
        console.log(`  Logout URL visited: ${page.url()}`);
    } else {
        // Fallback: navigate to logout endpoint directly
        await page.goto(`${BASE_URL}/wp-login.php?action=logout`, { waitUntil: 'domcontentloaded', timeout: 15000 });
        // WP may show a confirmation — click it if present
        const confirmBtn = page.locator('a[href*="action=logout"]');
        if (await confirmBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await confirmBtn.click();
            await page.waitForLoadState('domcontentloaded', { timeout: 10000 });
        }
        console.log(`  Logout fallback URL: ${page.url()}`);
    }

    await page.screenshot({ path: 'tests/example_playwright_login_loggedout.png' });

    // Verify the auth cookie is gone
    const postLogoutCookies = await ctx.cookies();
    const stillLoggedIn = postLogoutCookies.some(c => c.name.startsWith('wordpress_logged_in_'));
    expect(stillLoggedIn, 'Expected auth cookie to be cleared after logout').toBe(false);
    console.log('  Logged out successfully ✓');

    await ctx.close();

    // 5. Delete the temp user
    console.log(`  Deleting temp user ID ${userId}...`);
    deleteTempUser(userId);
    console.log('  Temp user deleted ✓');
});
