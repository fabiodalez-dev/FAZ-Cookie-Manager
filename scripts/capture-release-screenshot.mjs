/**
 * capture-release-screenshot.mjs — grab the image that goes on the release post.
 *
 * Shoots the consent banner as a visitor actually sees it, on the local test
 * site, at a 1200x630 social-card ratio. The banner is the plugin's only
 * visitor-facing surface, so it is the honest thing to show: an admin screen
 * would illustrate the settings page rather than the product.
 *
 * Usage:
 *   node scripts/capture-release-screenshot.mjs --out=/tmp/faz-1.28.0.png \
 *        [--url=http://127.0.0.1:9998] [--admin] [--width=1200] [--height=630]
 *
 * --admin shoots the plugin dashboard instead, for releases whose story is an
 * admin one. Either way the file is written to --out and the path echoed.
 *
 * Exits non-zero when the target is unreachable or the banner never appears —
 * a release post with a broken or blank image is worse than a loud failure,
 * and publish-release-post.sh treats a non-zero exit as fatal.
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const arg = (name, fallback = '') => {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : fallback;
};
const flag = (name) => process.argv.includes(`--${name}`);

const OUT = arg('out');
const BASE = arg('url', process.env.WP_BASE_URL || 'http://127.0.0.1:9998');
const WIDTH = Number(arg('width', '1200'));
const HEIGHT = Number(arg('height', '630'));
const ADMIN = flag('admin');

if (!OUT) {
  console.error('ERROR: --out=<path.png> is required');
  process.exit(1);
}

mkdirSync(dirname(OUT), { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: WIDTH, height: HEIGHT },
  deviceScaleFactor: 2, // retina: the post is read on high-DPI screens
});
const page = await context.newPage();

try {
  if (ADMIN) {
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', process.env.WP_ADMIN_USER || 'admin');
    await page.fill('#user_pass', process.env.WP_ADMIN_PASS || 'admin');
    await page.click('#wp-submit');
    await page.waitForLoadState('domcontentloaded');
    await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager`, {
      waitUntil: 'domcontentloaded',
    });
    await page.locator('#faz-app, .wrap, #wpbody-content').first().waitFor({ timeout: 20_000 });
  } else {
    // A visitor with no prior decision: clearing cookies is what makes the
    // banner appear at all, and forgetting it is the classic way to ship a
    // screenshot of an empty page.
    await context.clearCookies();
    await page.goto(`${BASE}/?faz-shot=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-faz-tag="notice"]').waitFor({ state: 'visible', timeout: 20_000 });

    // Blank the page behind the banner. The test site's front page is a list of
    // E2E fixture pages ("FAZ Cookie Policy E2E" a hundred times over), and a
    // release post illustrated with my own test litter is worse than no image.
    // Only the page BEHIND is hidden — the banner is the plugin's real rendered
    // output, untouched, so the shot still shows what a visitor sees.
    await page.addStyleTag({
      content: `
        html, body { background: #f4f5f7 !important; }
        body > *:not(#faz-consent):not(.faz-consent-container):not([data-faz-tag="notice"]) {
          visibility: hidden !important;
        }
        #faz-consent, .faz-consent-container, [data-faz-tag="notice"] { visibility: visible !important; }
        [data-faz-tag="notice"] {
          position: fixed !important;
          top: 50% !important; left: 50% !important;
          transform: translate(-50%, -50%) !important;
          margin: 0 !important;
          box-shadow: 0 24px 60px rgba(16, 24, 40, .18) !important;
        }
      `,
    });
    // Let the entry animation and the injected layout settle so the shot is not
    // a half-faded banner mid-transition.
    await page.waitForTimeout(700);
  }

  await page.screenshot({ path: OUT });
  console.log(OUT);
} catch (err) {
  console.error(`ERROR: could not capture the screenshot — ${err.message}`);
  console.error(`  target: ${BASE}${ADMIN ? ' (admin dashboard)' : ' (frontend banner)'}`);
  console.error('  Is the local test site up? nginx + PHP-FPM on 127.0.0.1:9998.');
  process.exit(1);
} finally {
  await browser.close();
}
