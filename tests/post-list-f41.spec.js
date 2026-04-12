/**
 * Test: verify post 5418 is visible in backup_F41.zip post list.
 * Does NOT restore anything.
 *
 * Strategy: pre-stage the backup on the server via WP-CLI (avoids 450MB upload
 * each test run). Inject the staged key into localStorage so the UI treats it
 * as already uploaded.
 *
 * Auth: cookies generated directly via WP-CLI to bypass 2FA.
 */

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');

const BASE_URL   = 'https://your-wordpress-site.example.com';
const PLUGIN_URL = `${BASE_URL}/wp-admin/admin.php?page=cloudscale-backup`;
const STAGED_LS_KEY = 'csbr_staged_upload';

const SSH_KEY  = 'REPO_BASE/CPT_Default_Key.pem';
const SSH_HOST = 'ec2-user@your-ec2-host.af-south-1.compute.amazonaws.com';

function sshExec(cmd) {
    return execSync(
        `ssh -o StrictHostKeyChecking=no -o LogLevel=ERROR -i "${SSH_KEY}" "${SSH_HOST}" "${cmd}"`,
        { encoding: 'utf8', timeout: 30000 }
    ).trim();
}

function getFreshCookies() {
    fs.writeFileSync('/tmp/csbr-gen-cookies.php',
        '<?php\necho json_encode(["auth"=>wp_generate_auth_cookie(141,time()+7200,"auth"),"secure_auth"=>wp_generate_auth_cookie(141,time()+7200,"secure_auth"),"logged_in"=>wp_generate_auth_cookie(141,time()+7200,"logged_in")]);'
    );
    execSync(`scp -o StrictHostKeyChecking=no -o LogLevel=ERROR -i "${SSH_KEY}" /tmp/csbr-gen-cookies.php "${SSH_HOST}:/tmp/csbr-gen-cookies.php"`, { encoding: 'utf8' });
    const raw = sshExec('cd /var/www/html && wp --allow-root eval-file /tmp/csbr-gen-cookies.php');
    return JSON.parse(raw);
}

function getStagedKey() {
    // The backup file is pre-copied to the server at csbr-staging/backup_F41_test.zip.
    // Create a transient for it and return the key.
    execSync(`scp -o StrictHostKeyChecking=no -o LogLevel=ERROR -i "${SSH_KEY}" /tmp/stage-backup-f41.php "${SSH_HOST}:/tmp/stage-backup-f41.php"`, { encoding: 'utf8' });
    const raw = sshExec('cd /var/www/html && wp --allow-root eval-file /tmp/stage-backup-f41.php');
    return JSON.parse(raw);
}

test('post 5418 visible in backup_F41.zip post list', async ({ browser }) => {
    expect(fs.existsSync('/tmp/stage-backup-f41.php'), 'stage PHP helper missing').toBe(true);

    // Get fresh WP auth cookies and a staged backup key
    const wpCookies = getFreshCookies();
    const staged    = getStagedKey();
    console.log('Staged key:', staged.key, '— file size:', staged.size);

    const domain  = 'andrewbaker.ninja';
    const expires = Math.floor(Date.now() / 1000) + 7200;

    const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
    await ctx.addCookies([
        { name: 'wordpress_4ee848874387f3e44095b4ea33.2.338',           value: wpCookies.auth,         domain, path: '/wp-admin', secure: true, httpOnly: true, expires },
        { name: 'wordpress_sec_4ee848874387f3e44095b4ea33.2.338',       value: wpCookies.secure_auth,  domain, path: '/wp-admin', secure: true, httpOnly: true, expires },
        { name: 'wordpress_logged_in_4ee848874387f3e44095b4ea33.2.338', value: wpCookies.logged_in,    domain, path: '/',          secure: true, httpOnly: true, expires },
    ]);

    const page = await ctx.newPage();

    page.on('console', msg => {
        const t = msg.type();
        if (t === 'error' || t === 'warning') console.log(`[browser ${t}]`, msg.text());
    });
    page.on('pageerror', err => console.log('[page error]', err.message));

    // Navigate to plugin page
    await page.goto(PLUGIN_URL, { waitUntil: 'load', timeout: 30000 });
    const title = await page.title();
    console.log('Page title:', title);
    expect(title).not.toContain('Log In');
    expect(title).not.toContain('2FA');
    await expect(page.locator('#cs-tab-local')).toBeVisible({ timeout: 15000 });
    console.log('Plugin page loaded OK');

    // Inject the staged backup into localStorage so the UI treats it as uploaded
    const lsValue = JSON.stringify({
        source: {
            type:       'staged',
            staged_key: staged.key,
            filename:   staged.filename,
        },
        ts: Date.now(),
    });
    await page.evaluate(({ key, val }) => {
        localStorage.setItem(key, val);
    }, { key: STAGED_LS_KEY, val: lsValue });

    // Reload so the page picks up the localStorage state
    await page.reload({ waitUntil: 'load', timeout: 30000 });
    await expect(page.locator('#cs-tab-local')).toBeVisible({ timeout: 10000 });

    // Check that ready label reflects the staged backup
    const readyLabel = page.locator('#cs-restore-ready-label');
    await expect(readyLabel).toContainText('Ready', { timeout: 10000 });
    console.log('Ready label:', await readyLabel.textContent());

    // Click "View Restore Options"
    const viewBtn = page.locator('#cs-restore-open-modal-btn');
    await expect(viewBtn).toBeEnabled({ timeout: 5000 });
    await viewBtn.click();

    // Modal opens
    await expect(page.locator('#cs-restore-modal')).toBeVisible({ timeout: 10000 });
    console.log('Modal opened OK');

    // Select "Single post or page" mode
    const postRadio = page.locator('input[name="cs-restore-mode"][value="post"]');
    await expect(postRadio).toBeVisible({ timeout: 5000 });

    // Intercept the specific csbr_list_backup_posts AJAX response
    const postListResponse = page.waitForResponse(async resp => {
        if (!resp.url().includes('admin-ajax.php')) return false;
        try {
            const txt = await resp.text();
            return txt.includes('"posts"');
        } catch { return false; }
    }, { timeout: 90000 });

    await postRadio.click();
    console.log('Radio clicked, waiting for AJAX...');

    const resp = await postListResponse;
    const respText = await resp.text();
    console.log('AJAX status:', resp.status(), '— body preview:', respText.slice(0, 400));

    // Parse response
    let respJson;
    try { respJson = JSON.parse(respText); } catch(e) { throw new Error('Non-JSON response: ' + respText.slice(0, 200)); }
    expect(respJson.success, 'csbr_list_backup_posts returned error: ' + JSON.stringify(respJson.data)).toBe(true);

    // Wait for post list to render
    await expect(page.locator('#cs-modal-post-wrap')).toBeVisible({ timeout: 10000 });
    const postList = page.locator('#cs-modal-post-list');

    const allRows = postList.locator('.cs-post-row');
    const rowCount = await allRows.count();
    console.log('Total post rows:', rowCount);

    for (let i = 0; i < Math.min(5, rowCount); i++) {
        console.log('  Row ' + i + ':', await allRows.nth(i).textContent());
    }

    // Assert post 5418 is in the list
    const post5418 = postList.locator('.cs-post-row', { hasText: '5418' });
    await expect(post5418.first()).toBeVisible({ timeout: 5000 });
    console.log('Post 5418:', await post5418.first().textContent());

    // Close modal without restoring
    await page.locator('#cs-modal-cancel').click();
    await expect(page.locator('#cs-restore-modal')).toBeHidden({ timeout: 5000 });
    console.log('Test PASSED — post 5418 visible, nothing restored.');

    await ctx.close();
});
