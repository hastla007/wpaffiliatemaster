// node import.js <out.png> <csv> : uploads the CSV on the import page and shoots the check result.
const { chromium } = require('playwright');
(async () => {
  const [out, csv, h = '1000'] = process.argv.slice(2);
  const HOST = 'http://harbor-sailing.example.com';
  const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP harbor-sailing.example.com 127.0.0.1'] });
  const ctx = await browser.newContext({ viewport: { width: 1600, height: +h }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  await page.goto(HOST + '/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin-pass-123');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  await page.goto(HOST + '/wp-admin/admin.php?page=wpac-import', { waitUntil: 'networkidle' });
  await page.setInputFiles('#wpac_csv', csv);
  await Promise.all([page.waitForNavigation(), page.locator('#wpac_csv').evaluate(el => el.form.requestSubmit())]);
  await page.waitForTimeout(400);
  await page.screenshot({ path: out, fullPage: process.env.FULL === '1' });
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
