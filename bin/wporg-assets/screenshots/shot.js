// node shot.js <out.png> <path> [width] [height] [fullPage] [user|-] [pass]
// env: EVAL (JS run before the shot), CLIP "x,y,w,h", FILL "selector|value;selector|value"
const { chromium } = require('playwright');
const HOST = 'http://harbor-sailing.example.com';
(async () => {
  const [out, path, w = '1400', h = '900', full = '0', user = 'admin', pass = 'admin-pass-123'] = process.argv.slice(2);
  const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP harbor-sailing.example.com 127.0.0.1'] });
  const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  if (user !== '-') {
    await page.goto(HOST + '/wp-login.php');
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  }
  await page.goto(HOST + path, { waitUntil: 'networkidle' });
  if (process.env.FILL) {
    for (const pair of process.env.FILL.split(';')) {
      const i = pair.indexOf('|');
      await page.fill(pair.slice(0, i), pair.slice(i + 1));
    }
  }
  if (process.env.EVAL) await page.evaluate(process.env.EVAL);
  await page.waitForTimeout(400);
  const opts = { path: out, fullPage: full === '1' };
  if (process.env.CLIP) {
    const [x, y, cw, ch] = process.env.CLIP.split(',').map(Number);
    opts.clip = { x, y, width: cw, height: ch };
  }
  await page.screenshot(opts);
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
