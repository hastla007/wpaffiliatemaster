// Renders the WordPress.org icon and banner PNGs into .wordpress-org/ (deviceScaleFactor 1, exact sizes).
//   node bin/wporg-assets/render.js        (needs the playwright package with Chromium)
// Then optimise: python3 bin/wporg-assets/optimize.py
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const here = __dirname;
const out = path.join(here, '..', '..', '.wordpress-org');
const svg = fs.readFileSync(path.join(here, 'icon.svg'), 'utf8');

(async () => {
  const browser = await chromium.launch();
  for (const size of [128, 256]) {
    const page = await browser.newPage({ viewport: { width: size, height: size }, deviceScaleFactor: 1 });
    await page.setContent(`<html><body style="margin:0;background:transparent">${svg.replace('width="256" height="256"', `width="${size}" height="${size}"`)}</body></html>`);
    await page.screenshot({ path: path.join(out, `icon-${size}x${size}.png`), omitBackground: true, clip: { x: 0, y: 0, width: size, height: size } });
    await page.close();
  }
  const banner = 'file://' + path.join(here, 'banner.html');
  for (const [w, h] of [[772, 250], [1544, 500]]) {
    const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    await page.goto(`${banner}?w=${w}`);
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: path.join(out, `banner-${w}x${h}.png`), clip: { x: 0, y: 0, width: w, height: h } });
    await page.close();
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
