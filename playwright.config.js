const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests',
    timeout: 120000,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
    use: {
        baseURL: 'https://andrewbaker.ninja',
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        headless: true,
        viewport: { width: 1400, height: 900 },
    },
    projects: [
        { name: 'chromium', use: { browserName: 'chromium' } },
    ],
});
