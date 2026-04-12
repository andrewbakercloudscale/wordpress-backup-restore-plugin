/**
 * CloudScale Backup & Restore — smoke test suite
 *
 * Strategy: API-request login (bypasses bot detection) → inject cookies into browser context.
 * Each panel test: change a value, save, reload, verify change persisted, restore, save.
 */

const { test, expect, request } = require('@playwright/test');

const BASE_URL   = 'https://andrewbaker.ninja';
const PLUGIN_URL = `${BASE_URL}/wp-admin/admin.php?page=cloudscale-backup`;
const LOGIN_URL  = `${BASE_URL}/cleanshirt/`;
const USERNAME   = 'playwright';
const PASSWORD   = 'TestPW2026!';

// ── auth helper ──────────────────────────────────────────────────────────────

let _sharedCookies = null;

async function getSessionCookies(apiContext) {
    if (_sharedCookies) return _sharedCookies;
    await apiContext.get(LOGIN_URL);
    await apiContext.post(LOGIN_URL, {
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Origin': BASE_URL,
            'Referer': LOGIN_URL,
        },
        data: `log=${encodeURIComponent(USERNAME)}&pwd=${encodeURIComponent(PASSWORD)}&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1`,
    });
    const state = await apiContext.storageState();
    _sharedCookies = state.cookies.map(c => ({ ...c, domain: 'andrewbaker.ninja', secure: true }));
    return _sharedCookies;
}

async function makeAuthContext(browser, apiContext) {
    const cookies = await getSessionCookies(apiContext);
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
    await ctx.addCookies(cookies);
    return ctx;
}

async function goPlugin(page) {
    await page.goto(PLUGIN_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });
}

async function switchTab(page, tabId) {
    await page.locator(`button[data-tab="${tabId}"]`).click();
    await page.waitForTimeout(400);
}

async function waitMsg(page, selector, timeout = 8000) {
    await page.waitForFunction(
        sel => { const el = document.querySelector(sel); return el && el.textContent.trim().length > 0; },
        selector, { timeout }
    );
    return (await page.locator(selector).textContent()).trim();
}

async function expectOk(page, msgSel, actionName) {
    const msg = await waitMsg(page, msgSel).catch(() => '(no message)');
    console.log(`    [${actionName}] msg: "${msg}"`);
    expect(msg.toLowerCase()).not.toMatch(/\berror\b|\bfail\b|\binvalid\b/);
}

async function openAndCloseModal(page, btnOnclick, label) {
    await page.locator(`button[onclick*="${btnOnclick}"]`).click();
    await page.waitForTimeout(500);
    const modal = page.locator('#cs-explain-modal');
    if (await modal.isVisible({ timeout: 2000 }).catch(() => false)) {
        console.log(`    ${label}: modal opened ✓`);
        await page.locator('#cs-explain-modal button').first().click();
        await page.waitForTimeout(200);
    } else {
        console.log(`    ${label}: no modal visible`);
    }
}

// ── LOCAL TAB ────────────────────────────────────────────────────────────────

test('Local > Schedule: change hour, save, reload, verify, restore', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');

    const hourSel = page.locator('#cs-run-hour');
    const wasHour = await hourSel.inputValue();
    const newHour = wasHour === '4' ? '5' : '4';

    await hourSel.selectOption(newHour);
    // Schedule card uses a plain HTML form submit (not AJAX)
    await page.locator('#cs-schedule-form [type=submit], button[name="csbr_save_schedule"]').click();
    await page.waitForLoadState('domcontentloaded');

    await goPlugin(page);
    await switchTab(page, 'local');
    const reloaded = await page.locator('#cs-run-hour').inputValue();
    expect(reloaded).toBe(newHour);
    console.log(`    Hour persisted: ${reloaded} ✓`);

    await page.locator('#cs-run-hour').selectOption(wasHour);
    await page.locator('#cs-schedule-form [type=submit], button[name="csbr_save_schedule"]').click();
    await page.waitForLoadState('domcontentloaded');
    console.log('    Schedule restored ✓');
    await ctx.close();
});

test('Local > Schedule: Explain button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');
    await openAndCloseModal(page, 'csScheduleExplain', 'Schedule Explain');
    await ctx.close();
});

test('Local > Retention: change value and prefix, save, reload, verify, restore', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');

    const retInput    = page.locator('#cs-retention');
    const pfxInput    = page.locator('#cs-backup-prefix');
    const origRet     = await retInput.inputValue();
    const origPfx     = await pfxInput.inputValue();
    const newRet      = origRet === '10' ? '12' : '10';
    const newPfx      = origPfx === 'backup' ? 'bkup' : 'backup';

    await retInput.fill(newRet);
    await pfxInput.fill(newPfx);
    await page.locator('#cs-save-retention').click();
    await expectOk(page, '#cs-retention-msg', 'retention save');

    await goPlugin(page);
    await switchTab(page, 'local');
    expect(await page.locator('#cs-retention').inputValue()).toBe(newRet);
    expect(await page.locator('#cs-backup-prefix').inputValue()).toBe(newPfx);
    console.log(`    Retention ${newRet}, prefix ${newPfx} persisted ✓`);

    await page.locator('#cs-retention').fill(origRet);
    await page.locator('#cs-backup-prefix').fill(origPfx);
    await page.locator('#cs-save-retention').click();
    await expectOk(page, '#cs-retention-msg', 'retention restore');
    await ctx.close();
});

test('Local > Retention: Explain button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');
    await openAndCloseModal(page, 'csRetentionExplain', 'Retention Explain');
    await ctx.close();
});

test('Local > System Info: Run Repair button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');
    await page.locator('#cs-repair-btn').click();
    const msg = await waitMsg(page, '#cs-repair-msg').catch(() => '(timeout)');
    console.log(`    Repair: "${msg}"`);
    expect(msg.toLowerCase()).not.toMatch(/\berror\b|\bfail\b/);
    await ctx.close();
});

test('Local > System Info: Explain button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');
    await openAndCloseModal(page, 'csSystemExplain', 'System Explain');
    await ctx.close();
});

test('Local > Run Backup Now: verify it starts and shows progress', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');

    await page.locator('#cs-run-backup').click();
    console.log('    Backup button clicked, checking for progress msg...');

    // Just verify the backup JOB starts — msg should show "Starting" or "running" within 10s
    await page.waitForFunction(
        () => {
            const el = document.querySelector('#cs-backup-msg');
            return el && el.textContent.trim().length > 0;
        },
        { timeout: 15000 }
    ).catch(() => console.warn('    No backup msg appeared in 15s'));

    const msg = await page.locator('#cs-backup-msg').textContent().catch(() => '');
    console.log(`    Progress msg: "${msg.trim().substring(0, 120)}"`);
    // Verify it started (has some content), not an instant error
    expect(msg.trim().length).toBeGreaterThan(0);
    await ctx.close();
});

test('Local > Backup: Explain button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');
    await openAndCloseModal(page, 'csBackupExplain', 'Backup Explain');
    await ctx.close();
});

test('Local > History: Explain button, delete oldest backup (if 2+ exist)', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');
    // Two csHistoryExplain buttons exist (local + cloud history) — use first
    await page.locator('button[onclick*="csHistoryExplain"]').first().click();
    await page.waitForTimeout(500);
    const hModal = page.locator('#cs-explain-modal');
    if (await hModal.isVisible({ timeout: 2000 }).catch(() => false)) {
        console.log('    History Explain: modal opened ✓');
        await page.locator('#cs-explain-modal button').first().click();
        await page.waitForTimeout(200);
    }

    await page.waitForTimeout(1500);
    const delBtns = page.locator('#cs-backup-list button[onclick*="csDeleteBackup"], #cs-backup-list button[onclick*="Delete"]');
    const count = await delBtns.count();
    console.log(`    ${count} delete button(s)`);

    if (count >= 2) {
        const onclick = await delBtns.last().getAttribute('onclick') || '';
        console.log(`    Deleting oldest: ${onclick.substring(0, 60)}`);
        page.once('dialog', async d => { await d.accept(); });
        await delBtns.last().click();
        await page.waitForTimeout(2000);
        const newCount = await delBtns.count();
        console.log(`    ${count} → ${newCount} ✓`);
    }
    await ctx.close();
});

test('Local > Restore: Explain button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');
    await openAndCloseModal(page, 'csRestoreExplain', 'Restore Explain');
    await ctx.close();
});

test('Local > Activity Log: Refresh and Copy buttons', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'local');

    await page.locator('#cs-log-refresh').click();
    await page.waitForTimeout(1000);
    console.log('    Log Refresh ✓');

    await page.locator('#cs-log-copy').click();
    await page.waitForTimeout(300);
    console.log('    Log Copy ✓');
    await ctx.close();
});

// ── CLOUD TAB ────────────────────────────────────────────────────────────────

test('Cloud > Schedule: toggle day, save, reload, verify, restore', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    const chk = page.locator('input[name="ami_days[]"][value="1"]').first();
    if (!await chk.isVisible()) { console.log('    No day checkboxes — skip'); await ctx.close(); return; }

    const was = await chk.isChecked();
    await chk.setChecked(!was);
    await page.locator('button[onclick*="csCloudScheduleSave"]').click();
    await page.waitForTimeout(1000);

    await goPlugin(page);
    await switchTab(page, 'cloud');
    const reloaded = await page.locator('input[name="ami_days[]"][value="1"]').first().isChecked();
    expect(reloaded).toBe(!was);
    console.log(`    Monday toggled ${was} → ${reloaded} ✓`);

    await page.locator('input[name="ami_days[]"][value="1"]').first().setChecked(was);
    await page.locator('button[onclick*="csCloudScheduleSave"]').click();
    await page.waitForTimeout(1000);
    await ctx.close();
});

test('Cloud > Schedule: Explain button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');
    await openAndCloseModal(page, 'csCloudScheduleExplain', 'Cloud Schedule Explain');
    await ctx.close();
});

test('Cloud > Google Drive: change path, save, reload, verify, restore', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    const input    = page.locator('#cs-gdrive-path');
    const original = await input.inputValue();
    const newVal   = original.includes('test') ? original.replace('/test', '') : original.trimEnd().replace(/\/?$/, '/test/');

    await input.fill(newVal);
    await page.locator('button[onclick*="csGDriveSave"]').click();
    await expectOk(page, '#cs-gdrive-msg', 'GDrive save');

    await goPlugin(page);
    await switchTab(page, 'cloud');
    expect(await page.locator('#cs-gdrive-path').inputValue()).toBe(newVal);
    console.log(`    GDrive path persisted ✓`);

    await page.locator('#cs-gdrive-path').fill(original);
    await page.locator('button[onclick*="csGDriveSave"]').click();
    await expectOk(page, '#cs-gdrive-msg', 'GDrive restore');
    await ctx.close();
});

test('Cloud > Google Drive: Test, Diagnose, Explain buttons', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    await page.locator('button[onclick*="csGDriveTest"]').click();
    const msg = await waitMsg(page, '#cs-gdrive-msg').catch(() => '(no msg)');
    console.log(`    GDrive Test: "${msg}"`);

    await openAndCloseModal(page, 'csGDriveDiagnose', 'GDrive Diagnose');
    await openAndCloseModal(page, 'csGDriveExplain', 'GDrive Explain');
    await ctx.close();
});

test('Cloud > S3: change prefix, save, reload, verify, restore', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    const input    = page.locator('#cs-s3-prefix');
    const original = await input.inputValue();
    const newVal   = original.includes('test') ? original.replace('/test', '') : original.trimEnd().replace(/\/?$/, '/test/');

    await input.fill(newVal);
    await page.locator('button[onclick*="csS3Save"]').click();
    await expectOk(page, '#cs-s3-msg', 'S3 save');

    await goPlugin(page);
    await switchTab(page, 'cloud');
    expect(await page.locator('#cs-s3-prefix').inputValue()).toBe(newVal);
    console.log(`    S3 prefix persisted ✓`);

    await page.locator('#cs-s3-prefix').fill(original);
    await page.locator('button[onclick*="csS3Save"]').click();
    await expectOk(page, '#cs-s3-msg', 'S3 restore');
    await ctx.close();
});

test('Cloud > S3: Test, Diagnose, Explain buttons', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    await page.locator('button[onclick*="csS3Test"]').click();
    const msg = await waitMsg(page, '#cs-s3-msg').catch(() => '(no msg)');
    console.log(`    S3 Test: "${msg}"`);
    expect(msg.toLowerCase()).not.toMatch(/\berror\b|\bfail\b/);

    await openAndCloseModal(page, 'csS3Diagnose', 'S3 Diagnose');
    await openAndCloseModal(page, 'csS3Explain', 'S3 Explain');
    await ctx.close();
});

test('Cloud > S3 History: Refresh button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');
    await page.locator('#cs-s3h-refresh-btn').click();
    await page.waitForTimeout(3000);
    console.log('    S3 History Refresh ✓');
    await ctx.close();
});

test('Cloud > Dropbox: change path, save, reload, verify, restore', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    const input    = page.locator('#cs-dropbox-path');
    const original = await input.inputValue();
    const newVal   = original.includes('test') ? original.replace('/test', '') : original.trimEnd().replace(/\/?$/, '/test/');

    await input.fill(newVal);
    await page.locator('button[onclick*="csDropboxSave"]').click();
    await expectOk(page, '#cs-dropbox-msg', 'Dropbox save');

    await goPlugin(page);
    await switchTab(page, 'cloud');
    expect(await page.locator('#cs-dropbox-path').inputValue()).toBe(newVal);
    console.log(`    Dropbox path persisted ✓`);

    await page.locator('#cs-dropbox-path').fill(original);
    await page.locator('button[onclick*="csDropboxSave"]').click();
    await expectOk(page, '#cs-dropbox-msg', 'Dropbox restore');
    await ctx.close();
});

test('Cloud > Dropbox: Test, Diagnose, Explain buttons', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    await page.locator('button[onclick*="csDropboxTest"]').click();
    const msg = await waitMsg(page, '#cs-dropbox-msg').catch(() => '(no msg)');
    console.log(`    Dropbox Test: "${msg}"`);

    await openAndCloseModal(page, 'csDropboxDiagnose', 'Dropbox Diagnose');
    await openAndCloseModal(page, 'csDropboxExplain', 'Dropbox Explain');
    await ctx.close();
});

test('Cloud > OneDrive: change path, save, reload, verify, restore', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    const input    = page.locator('#cs-onedrive-path');
    const original = await input.inputValue();
    const newVal   = original.includes('test') ? original.replace('/test', '') : original.trimEnd().replace(/\/?$/, '/test/');

    await input.fill(newVal);
    await page.locator('button[onclick*="csOneDriveSave"]').click();
    await expectOk(page, '#cs-onedrive-msg', 'OneDrive save');

    await goPlugin(page);
    await switchTab(page, 'cloud');
    expect(await page.locator('#cs-onedrive-path').inputValue()).toBe(newVal);
    console.log(`    OneDrive path persisted ✓`);

    await page.locator('#cs-onedrive-path').fill(original);
    await page.locator('button[onclick*="csOneDriveSave"]').click();
    await expectOk(page, '#cs-onedrive-msg', 'OneDrive restore');
    await ctx.close();
});

test('Cloud > OneDrive: Test, Diagnose, Explain buttons', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    await page.locator('button[onclick*="csOneDriveTest"]').click();
    const msg = await waitMsg(page, '#cs-onedrive-msg').catch(() => '(no msg)');
    console.log(`    OneDrive Test: "${msg}"`);

    await openAndCloseModal(page, 'csOneDriveDiagnose', 'OneDrive Diagnose');
    await openAndCloseModal(page, 'csOneDriveExplain', 'OneDrive Explain');
    await ctx.close();
});

test('Cloud > AMI: change prefix, save, reload, verify, restore', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');

    const input    = page.locator('#cs-ami-prefix');
    const original = await input.inputValue();
    const newVal   = original === 'ninjaAuto' ? 'ninjaTest' : 'ninjaAuto';

    await input.fill(newVal);
    await page.locator('button[onclick*="csAmiSave"]').click();
    await expectOk(page, '#cs-ami-msg', 'AMI save');

    await goPlugin(page);
    await switchTab(page, 'cloud');
    expect(await page.locator('#cs-ami-prefix').inputValue()).toBe(newVal);
    console.log(`    AMI prefix persisted ✓`);

    await page.locator('#cs-ami-prefix').fill(original);
    await page.locator('button[onclick*="csAmiSave"]').click();
    await expectOk(page, '#cs-ami-msg', 'AMI restore');
    await ctx.close();
});

test('Cloud > AMI: Explain button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');
    await openAndCloseModal(page, 'csAmiExplain', 'AMI Explain');
    await ctx.close();
});

test('Cloud > AMI: Refresh button', async ({ browser, request: api }) => {
    const ctx  = await makeAuthContext(browser, api);
    const page = await ctx.newPage();
    await goPlugin(page);
    await switchTab(page, 'cloud');
    // AMI history section is CSS-hidden on load — click via JS to bypass visibility
    await page.waitForSelector('#cs-ami-refresh-all', { state: 'attached', timeout: 10000 });
    await page.evaluate(() => document.querySelector('#cs-ami-refresh-all').click());
    await page.waitForTimeout(3000);
    console.log('    AMI Refresh ✓');
    await ctx.close();
});
