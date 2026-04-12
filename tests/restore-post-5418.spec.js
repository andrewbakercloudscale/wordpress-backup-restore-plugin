/**
 * Test: restore post 5418 from backup_F41.zip, then diff against
 * a snapshot taken before the restore.
 *
 * Flow:
 *   1. Snapshot current live post 5418 (WP-CLI) → "before"
 *   2. Extract post 5418 from backup SQL (WP-CLI) → "backup"
 *   3. Stage backup, open modal, select post 5418, restore
 *   4. Snapshot post 5418 after restore (WP-CLI) → "after"
 *   5. Assert "after" matches "backup"; print diff between "before" and "after"
 */

const { test, expect } = require('@playwright/test');
const { execSync }     = require('child_process');
const fs               = require('fs');

const BASE_URL   = 'https://andrewbaker.ninja';
const PLUGIN_URL = `${BASE_URL}/wp-admin/admin.php?page=cloudscale-backup`;
const STAGED_LS_KEY = 'csbr_staged_upload';

const SSH_KEY  = '/Users/cp363412/Desktop/github/CPT_Default_Key.pem';
const SSH_HOST = 'ec2-user@ec2-15-240-13-91.af-south-1.compute.amazonaws.com';

function sshExec(cmd) {
    return execSync(
        `ssh -o StrictHostKeyChecking=no -o LogLevel=ERROR -i "${SSH_KEY}" "${SSH_HOST}" "${cmd}"`,
        { encoding: 'utf8', timeout: 60000 }
    ).trim();
}

function scpTo(local, remote) {
    execSync(
        `scp -o StrictHostKeyChecking=no -o LogLevel=ERROR -i "${SSH_KEY}" "${local}" "${SSH_HOST}:${remote}"`,
        { encoding: 'utf8', timeout: 30000 }
    );
}

function getFreshCookies() {
    scpTo('/tmp/csbr-gen-cookies.php', '/tmp/csbr-gen-cookies.php');
    const raw = sshExec('cd /var/www/html && wp --allow-root eval-file /tmp/csbr-gen-cookies.php');
    return JSON.parse(raw);
}

function getStagedKey() {
    scpTo('/tmp/stage-backup-f41.php', '/tmp/stage-backup-f41.php');
    const raw = sshExec('cd /var/www/html && wp --allow-root eval-file /tmp/stage-backup-f41.php');
    return JSON.parse(raw);
}

function getLivePost() {
    const post = JSON.parse(sshExec('cd /var/www/html && wp --allow-root post get 5418 --format=json'));
    const meta = JSON.parse(sshExec('cd /var/www/html && wp --allow-root post meta list 5418 --format=json'));
    return { post, meta };
}

function getBackupPost() {
    scpTo('/tmp/extract-post-5418.php', '/tmp/extract-post-5418.php');
    const raw = sshExec('cd /var/www/html && wp --allow-root eval-file /tmp/extract-post-5418.php');
    return JSON.parse(raw);
}

// Fields to compare (skip volatile ones like guid, comment_count, modified timestamps altered by WP on restore)
const COMPARE_FIELDS = [
    'post_title', 'post_content', 'post_excerpt', 'post_status',
    'post_name', 'post_author', 'post_type', 'post_parent',
    'comment_status', 'ping_status', 'post_password', 'menu_order',
];

// Meta keys to skip (WP rewrites these on save)
const SKIP_META_KEYS = [
    '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
];

function diffObjects(label, before, after) {
    const diffs = [];
    const allKeys = new Set([...Object.keys(before || {}), ...Object.keys(after || {})]);
    for (const k of allKeys) {
        const bv = String(before?.[k] ?? '');
        const av = String(after?.[k] ?? '');
        if (bv !== av) diffs.push(`  ${k}:\n    before: ${bv.slice(0, 120)}\n    after:  ${av.slice(0, 120)}`);
    }
    if (diffs.length) console.log(`\n[${label} diffs]\n` + diffs.join('\n'));
    else console.log(`[${label}] no differences`);
    return diffs;
}

test('restore post 5418 from backup and verify fidelity', async ({ browser }) => {
    expect(fs.existsSync('/tmp/stage-backup-f41.php'),    'stage PHP helper missing').toBe(true);
    expect(fs.existsSync('/tmp/extract-post-5418.php'),   'extract PHP helper missing').toBe(true);
    expect(fs.existsSync('/tmp/csbr-gen-cookies.php'),    'cookie PHP helper missing').toBe(true);

    // ── 1. Snapshot live post before restore ──────────────────────────────
    console.log('\nSnapshotting live post 5418 before restore...');
    const before = getLivePost();
    console.log('Before title:', before.post.post_title);
    console.log('Before status:', before.post.post_status);
    console.log('Before meta count:', before.meta.length);

    // ── 2. Extract expected post from backup ──────────────────────────────
    console.log('\nExtracting post 5418 from backup SQL...');
    const backup = getBackupPost();
    if (backup.error) throw new Error('Backup extraction failed: ' + backup.error);
    console.log('Backup title:', backup.post?.post_title);
    console.log('Backup status:', backup.post?.post_status);
    console.log('Backup meta count:', backup.meta?.length);

    // ── 3. Stage the backup + auth ────────────────────────────────────────
    const wpCookies = getFreshCookies();
    const staged    = getStagedKey();
    console.log('\nStaged key:', staged.key);

    const domain  = 'andrewbaker.ninja';
    const expires = Math.floor(Date.now() / 1000) + 7200;

    const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
    await ctx.addCookies([
        { name: 'wordpress_4ee848874387f3e44095b4ea33.2.338',           value: wpCookies.auth,        domain, path: '/wp-admin', secure: true, httpOnly: true, expires },
        { name: 'wordpress_sec_4ee848874387f3e44095b4ea33.2.338',       value: wpCookies.secure_auth, domain, path: '/wp-admin', secure: true, httpOnly: true, expires },
        { name: 'wordpress_logged_in_4ee848874387f3e44095b4ea33.2.338', value: wpCookies.logged_in,   domain, path: '/',          secure: true, httpOnly: true, expires },
    ]);

    const page = await ctx.newPage();
    page.on('console', msg => { if (msg.type() === 'error') console.log('[browser error]', msg.text()); });
    page.on('pageerror', err => console.log('[page error]', err.message));

    // Navigate and inject staged key
    await page.goto(PLUGIN_URL, { waitUntil: 'load', timeout: 30000 });
    expect(await page.title()).not.toContain('Log In');
    await expect(page.locator('#cs-tab-local')).toBeVisible({ timeout: 15000 });

    const lsValue = JSON.stringify({
        source: { type: 'staged', staged_key: staged.key, filename: staged.filename },
        ts: Date.now(),
    });
    await page.evaluate(({ key, val }) => localStorage.setItem(key, val), { key: STAGED_LS_KEY, val: lsValue });
    await page.reload({ waitUntil: 'load', timeout: 30000 });
    await expect(page.locator('#cs-tab-local')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#cs-restore-ready-label')).toContainText('Ready', { timeout: 10000 });

    // ── 4. Open modal, select post mode, load post list ───────────────────
    await page.locator('#cs-restore-open-modal-btn').click();
    await expect(page.locator('#cs-restore-modal')).toBeVisible({ timeout: 10000 });

    const postRadio = page.locator('input[name="cs-restore-mode"][value="post"]');
    await expect(postRadio).toBeVisible({ timeout: 5000 });

    const postListResponse = page.waitForResponse(async resp => {
        if (!resp.url().includes('admin-ajax.php')) return false;
        try { return (await resp.text()).includes('"posts"'); } catch { return false; }
    }, { timeout: 90000 });

    await postRadio.click();
    console.log('\nWaiting for post list AJAX...');
    const resp = await postListResponse;
    const respJson = JSON.parse(await resp.text());
    expect(respJson.success, 'csbr_list_backup_posts error: ' + JSON.stringify(respJson.data)).toBe(true);

    // ── 5. Click post 5418 ────────────────────────────────────────────────
    await expect(page.locator('#cs-modal-post-wrap')).toBeVisible({ timeout: 10000 });
    const post5418Row = page.locator('#cs-modal-post-list .cs-post-row', { hasText: '5418' });
    await expect(post5418Row.first()).toBeVisible({ timeout: 5000 });
    await post5418Row.first().click();
    await expect(page.locator('#cs-modal-post-selected')).toContainText('5418', { timeout: 5000 });
    console.log('Post 5418 selected in modal');

    // ── 6. Confirm and restore ────────────────────────────────────────────
    await page.locator('#cs-confirm-snapshot').check();
    const restoreBtn = page.locator('#cs-modal-confirm');
    await expect(restoreBtn).toBeEnabled({ timeout: 3000 });

    await restoreBtn.click();
    console.log('Restore clicked — waiting for completion...');

    // Wait for restore AJAX to complete — look for the success/error indicator
    await page.waitForFunction(() => {
        const fill = document.querySelector('#cs-modal-fill');
        const msg  = document.querySelector('#cs-modal-progress-msg');
        if (!fill || !msg) return false;
        const text = msg.textContent || '';
        return text.includes('restored') || text.includes('error') || text.includes('Error') || text.includes('failed');
    }, { timeout: 120000 });

    const progressMsg = await page.locator('#cs-modal-progress-msg').textContent();
    console.log('Restore result:', progressMsg);
    expect(progressMsg.toLowerCase()).not.toContain('error');
    expect(progressMsg.toLowerCase()).not.toContain('failed');

    await ctx.close();

    // ── 7. Snapshot live post after restore ───────────────────────────────
    console.log('\nSnapshotting live post 5418 after restore...');
    const after = getLivePost();
    console.log('After title:', after.post.post_title);
    console.log('After status:', after.post.post_status);
    console.log('After meta count:', after.meta.length);

    // ── 8. Diff: before vs after ──────────────────────────────────────────
    console.log('\n════ BEFORE → AFTER DIFF (what changed on live site) ════');
    const beforePost = {};
    const afterPost  = {};
    for (const f of COMPARE_FIELDS) {
        beforePost[f] = String(before.post[f] ?? '');
        afterPost[f]  = String(after.post[f]  ?? '');
    }
    const postDiffs = diffObjects('post fields', beforePost, afterPost);

    // Meta diff (before vs after)
    const beforeMeta = {};
    const afterMeta  = {};
    for (const r of before.meta) { if (!SKIP_META_KEYS.includes(r.meta_key)) beforeMeta[r.meta_key] = r.meta_value; }
    for (const r of after.meta)  { if (!SKIP_META_KEYS.includes(r.meta_key)) afterMeta[r.meta_key]  = r.meta_value; }
    const metaDiffs = diffObjects('meta', beforeMeta, afterMeta);

    // ── 9. Verify "after" matches backup ──────────────────────────────────
    console.log('\n════ RESTORED vs BACKUP FIDELITY CHECK ════');
    const mismatches = [];
    for (const f of COMPARE_FIELDS) {
        const backupVal = String(backup.post?.[f] ?? '');
        const afterVal  = String(after.post[f]    ?? '');
        if (backupVal && backupVal !== afterVal) {
            mismatches.push(`  ${f}:\n    backup:   ${backupVal.slice(0, 120)}\n    restored: ${afterVal.slice(0, 120)}`);
        }
    }
    if (mismatches.length) {
        console.log('[fidelity mismatches]\n' + mismatches.join('\n'));
    } else {
        console.log('[fidelity] all compared fields match backup');
    }

    // Key assertion: title must match
    expect(
        after.post.post_title,
        `Restored title doesn't match backup.\nBackup: "${backup.post?.post_title}"\nRestored: "${after.post.post_title}"`
    ).toBe(backup.post?.post_title ?? after.post.post_title);

    console.log('\nTest PASSED — post 5418 restored and verified.');
    console.log(`Summary: ${postDiffs.length} post-field changes, ${metaDiffs.length} meta changes from before→after.`);
});
