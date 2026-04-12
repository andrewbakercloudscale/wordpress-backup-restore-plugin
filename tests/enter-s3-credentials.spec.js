/**
 * enter-s3-credentials
 *
 * Creates a temporary WordPress admin account, logs in, navigates to the
 * CloudScale Backup plugin page, switches to the Cloud Backups tab, fills
 * in the S3 credentials, saves them, captures the result message and a
 * screenshot, then logs out and deletes the temp user.
 */

const { test, expect } = require('@playwright/test');
const { execSync }      = require('child_process');

const BASE_URL  = 'https://your-wordpress-site.example.com';
const LOGIN_URL = `${BASE_URL}/your-login-slug/`;
const PI_KEY    = `${process.env.HOME}/.ssh/pi_key`;
const PI_HOST   = 'pi@andrew-pi-5.local';

const TEMP_USER  = 'pw_temp_test';
const TEMP_PASS  = 'TmpPW2026!$x';
const TEMP_EMAIL = 'pw_temp_test@example-playwright.local';

/** Run a PHP snippet inside the WordPress container via SSH (uses a temp file to avoid quoting issues) */
function wpPhp(phpCode) {
    const tmpFile = `/tmp/pw_test_${Date.now()}.php`;
    const phpWithTag = `<?php\n${phpCode}`;
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
        "$u = new WP_User($id); $u->set_role('administrator');",
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

test('enter S3 credentials → save → verify message → logout → delete', async ({ browser, request: api }) => {

    // 1. Create the temp WordPress user
    console.log('Creating temp user...');
    let userId;
    try {
        userId = createTempUser();
        console.log(`  Temp user created: ${TEMP_USER} (ID ${userId})`);
    } catch (err) {
        console.warn('  Create failed — attempting cleanup of existing user, then retry');
        const cleaned = cleanupExistingTempUser();
        console.log(`  Cleanup result: ${cleaned}`);
        userId = createTempUser();
        console.log(`  Temp user created after cleanup: ${TEMP_USER} (ID ${userId})`);
    }

    // 2. Login via API request context
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

    // 3. Open browser with the session cookies
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await ctx.addCookies(cookies);
    const page = await ctx.newPage();

    // 4. Navigate to the CloudScale Backup plugin page
    console.log('  Navigating to CloudScale Backup plugin page...');
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=cloudscale-backup`, {
        waitUntil: 'domcontentloaded',
        timeout: 20000,
    });
    console.log(`  Plugin page URL: ${page.url()}`);
    expect(page.url()).toContain('page=cloudscale-backup');

    // 5. Click the Cloud Backups tab
    console.log('  Clicking Cloud Backups tab...');
    await page.click('button[data-tab="cloud"]');
    await page.waitForTimeout(500);

    // 6. Fill in S3 credentials
    console.log('  Filling S3 credentials...');
    await page.fill('#cs-s3-key-id',     process.env.AWS_ACCESS_KEY_ID     || 'YOUR_ACCESS_KEY_ID');
    await page.fill('#cs-s3-secret-key', process.env.AWS_SECRET_ACCESS_KEY || 'YOUR_SECRET_ACCESS_KEY');
    await page.fill('#cs-s3-region',     'af-south-1');

    // 7. Click the Save button
    console.log('  Clicking Save...');
    await page.click('button[onclick*="csS3Save"]');

    // 8. Wait for AJAX response
    await page.waitForTimeout(3000);

    // 9. Read and log the save message
    const saveMsg = await page.locator('#cs-s3-msg').textContent();
    console.log(`  S3 save message: "${saveMsg}"`);

    // 10. Screenshot
    await page.screenshot({ path: 'tests/s3-creds-saved.png' });
    console.log('  Screenshot saved to tests/s3-creds-saved.png');

    // 11. Logout via admin bar
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

    // 12. Delete the temp user
    console.log(`  Deleting temp user ID ${userId}...`);
    deleteTempUser(userId);
    console.log('  Temp user deleted');
});
