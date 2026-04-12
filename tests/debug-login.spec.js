const { test, expect, request } = require('@playwright/test');

const BASE_URL   = 'https://andrewbaker.ninja';
const PLUGIN_URL = `${BASE_URL}/wp-admin/admin.php?page=cloudscale-backup`;
const LOGIN_URL  = `${BASE_URL}/cleanshirt/`;
const USERNAME   = 'playwright';
const PASSWORD   = 'TestPW2026!';

/** Login via API request context (bypasses bot detection), return cookies for browser injection */
async function wpLogin(apiContext) {
    // First GET to pick up the testcookie
    const getResp = await apiContext.get(LOGIN_URL);
    const testCookieHeader = getResp.headers()['set-cookie'] || '';

    // POST login
    const resp = await apiContext.post(LOGIN_URL, {
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Origin': BASE_URL,
            'Referer': LOGIN_URL,
        },
        data: `log=${encodeURIComponent(USERNAME)}&pwd=${encodeURIComponent(PASSWORD)}&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1`,
    });

    console.log('Login POST status:', resp.status());
    const cookies = await apiContext.storageState();
    return cookies.cookies;
}

test('debug: API login then plugin page', async ({ browser, request: apiContext }) => {
    // Login via Playwright API request
    const cookies = await wpLogin(apiContext);
    console.log('Got cookies:', cookies.map(c => c.name + '=' + c.value.substring(0, 30) + '...'));

    // Create browser context with those cookies
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    await context.addCookies(cookies.map(c => ({
        ...c,
        domain: 'andrewbaker.ninja',
        secure: true,
    })));

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
});
