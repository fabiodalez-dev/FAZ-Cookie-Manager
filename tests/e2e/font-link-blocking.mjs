/**
 * Regression for runtime-injected stylesheet blocking (Web Font Loader / Google
 * Fonts) — baga report.
 *
 * Web Font Loader (webfont.js) loads Google Fonts by creating a <link>, setting
 * its `href` PROPERTY to a fonts.googleapis.com URL, and appending it — all at
 * runtime, after the page HTML the server-side blocker saw. FAZ's script/iframe
 * blocker never intercepted that, so the font CSS (and the visitor's IP reaching
 * Google) went out with consent denied. The HTMLLinkElement `href` setter is now
 * gated: a cross-origin stylesheet whose URL matches a blocked provider in a
 * denied category is parked (URL → data-faz-href, no fetch) until consent, then
 * restored by the standard link[data-faz-href] pass.
 *
 * Asserts, on faz-test with no consent:
 *   - a runtime-injected Google Fonts <link> is parked (no request fires);
 *   - a same-origin stylesheet is untouched;
 *   - accept-all restores and loads the parked stylesheet.
 *
 * Run: WP_BASE_URL=http://127.0.0.1:9998 node tests/e2e/font-link-blocking.mjs
 */

import { chromium } from '@playwright/test';

const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const GF = 'https://fonts.googleapis.com/css?family=Open+Sans:400,700';

let failures = 0;
function assert(name, cond) { console.log(`  ${cond ? 'PASS' : 'FAIL'} ${name}`); if (!cond) failures++; }

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const fontReqs = [];
page.on('request', (r) => { if (/fonts\.(googleapis|gstatic)\.com/.test(r.url())) fontReqs.push(r.url()); });

try {
  await page.goto(`${WP}/?nocache=fontlink`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForFunction(() => window.fazcookie && window.fazcookie._diag && window.fazcookie._diag().ready, { timeout: 8000 });

  const pre = await page.evaluate(() => ({
    block: (window._fazConfig || {})._block,
    consent: window.fazcookie._diag().hasConsentCookie,
    gfKnown: ((window._fazConfig || {})._providersToBlock || []).some((p) => /fonts\.googleapis/i.test(p.re || '')),
  }));
  assert('precondition: blocking on, no consent, Google Fonts is a known provider', pre.block === '1' && !pre.consent && pre.gfKnown);

  // Web Font Loader shape: create <link>, set href via property, append — at runtime.
  const injected = await page.evaluate(async (gf) => {
    const wfl = document.createElement('link');
    wfl.rel = 'stylesheet';
    wfl.id = 'wfl-gfont';
    wfl.href = gf;                 // property setter — the gated path
    document.head.appendChild(wfl);
    // A same-origin stylesheet must be untouched.
    const local = document.createElement('link');
    local.rel = 'stylesheet';
    local.id = 'local-css';
    local.href = location.origin + '/wp-content/themes/x/style.css';
    document.head.appendChild(local);
    await new Promise((r) => setTimeout(r, 400));
    const w = document.getElementById('wfl-gfont');
    const l = document.getElementById('local-css');
    return {
      gfParked: w.getAttribute('data-faz-href') === gf,
      gfHrefEmpty: !w.getAttribute('href'),
      gfCat: w.getAttribute('data-faz-category'),
      localUntouched: !l.getAttribute('data-faz-href') && !!l.getAttribute('href'),
    };
  }, GF);

  assert('runtime Google Fonts <link> parked (data-faz-href, no href)', injected.gfParked && injected.gfHrefEmpty);
  assert('parked stylesheet tagged with the provider category', injected.gfCat === 'functional');
  assert('same-origin stylesheet untouched (fast path)', injected.localUntouched);
  assert('zero network requests to fonts.googleapis before consent', fontReqs.length === 0);

  // Accept → restore.
  const restored = await page.evaluate(async () => {
    const btn = document.querySelector('[data-faz-tag="accept-button"], .faz-accept-all, #faz-accept-all');
    if (btn) btn.click();
    await new Promise((r) => setTimeout(r, 1200));
    const w = document.getElementById('wfl-gfont');
    return { loaded: (w.getAttribute('href') || '').includes('fonts.googleapis'), cleared: !w.getAttribute('data-faz-href'), consent: window.fazcookie._diag().hasConsentCookie };
  });
  assert('accept-all restores the parked stylesheet (href reloaded, data-faz-href cleared)', restored.consent && restored.loaded && restored.cleared);
} finally {
  await browser.close();
}

console.log(`\n=== ${failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'} ===`);
process.exit(failures === 0 ? 0 : 1);
