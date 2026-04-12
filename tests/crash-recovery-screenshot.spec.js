const { test } = require('@playwright/test');

test('recovery page mobile', async ({ browser }) => {
  const page = await browser.newPage({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 3,
    isMobile: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
  });
  await page.goto('https://your-wordpress-site.example.com/?nocache=' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.screenshot({ path: '/tmp/recovery-page-mobile.png', fullPage: false });
  const title = await page.title();
  console.log('Title:', title);
  await page.close();
});
