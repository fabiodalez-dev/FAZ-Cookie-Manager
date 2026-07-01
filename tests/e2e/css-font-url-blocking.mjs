/**
 * Regression for inline CSS url() font blocking.
 *
 * A theme can emit @font-face directly in an inline <style> tag:
 *   src: url(https://fonts.gstatic.com/...) format("woff2")
 * That load is performed by the CSS engine, not by <link href>, so the
 * HTMLLinkElement href gate cannot intercept it. This test verifies the
 * server-side style pass neutralizes the font URL before Chromium parses the
 * CSS, and the frontend restores the original CSS after consent.
 *
 * Run:
 *   WP_BASE_URL=http://127.0.0.1:9998 WP_PATH=/Users/fabio/Sites/faz-test \
 *     node tests/e2e/css-font-url-blocking.mjs
 */

import { execFileSync } from 'node:child_process';
import { chromium } from '@playwright/test';

const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const WP_PATH = process.env.WP_PATH;
if (!WP_PATH) {
  console.error('WP_PATH not set: export WP_PATH to the WordPress install root.');
  process.exit(1);
}

const SLUG = `css-font-url-blocking-${Date.now()}`;
const FONT_URL = 'https://fonts.gstatic.com/s/roboto/v30/KFOmCnqEu92Fr1Mu4mxK.woff2';
const CSS = `@font-face{font-family:"FazLeak";src:url("${FONT_URL}") format("woff2");font-weight:400;} .faz-font-probe{font-family:"FazLeak", sans-serif;}`;

function wp(args) {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

let failures = 0;
function assert(name, cond) {
  console.log(`  ${cond ? 'PASS' : 'FAIL'} ${name}`);
  if (!cond) failures++;
}

const setupPhp = `kses_remove_filters();
$content = '<style id="faz-inline-font-probe">${CSS.replace(/'/g, "\\'")}</style><p class="faz-font-probe">Font probe</p>';
$post = array('post_title'=>'CSS Font URL Blocking','post_name'=>'${SLUG}','post_status'=>'publish','post_type'=>'page','post_content'=>$content);
echo wp_insert_post($post);`;

let postId = '0';
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const fontRequests = [];

page.on('request', (request) => {
  if (request.url().includes('fonts.gstatic.com')) fontRequests.push(request.url());
});
await page.route('https://fonts.gstatic.com/**', (route) => {
  route.fulfill({ status: 204, body: '' });
});

try {
  postId = wp(['eval', setupPhp]);
  wp(['eval', "do_action('faz_clear_cache');"]);

  const html = await fetch(`${WP}/${SLUG}/`).then((r) => r.text());
  assert('server HTML parks the original CSS in data-faz-css', html.includes('data-faz-css='));
  assert('server HTML removes the live fonts.gstatic url from style text', !html.includes(`src:url("${FONT_URL}")`));
  assert('server HTML uses an inert data URL in the live CSS', html.includes('data:application/octet-stream,'));

  await page.goto(`${WP}/${SLUG}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForFunction(() => window.fazcookie && window.fazcookie._diag && window.fazcookie._diag().ready, { timeout: 8000 });
  await page.waitForTimeout(800);

  const parked = await page.evaluate((fontUrl) => {
    const style = document.getElementById('faz-inline-font-probe');
    return {
      hasConsent: window.fazcookie._diag().hasConsentCookie,
      parked: !!style?.getAttribute('data-faz-css'),
      category: style?.getAttribute('data-faz-category') || '',
      liveHasFontUrl: !!style?.textContent?.includes(fontUrl),
      liveHasInertUrl: !!style?.textContent?.includes('data:application/octet-stream,'),
    };
  }, FONT_URL);
  assert('precondition: no consent cookie', parked.hasConsent === false);
  assert('inline style remains parked in the DOM', parked.parked === true);
  assert('inline style is tagged functional', parked.category === 'functional');
  assert('live CSS does not contain fonts.gstatic before consent', parked.liveHasFontUrl === false);
  assert('live CSS contains inert URL before consent', parked.liveHasInertUrl === true);
  assert('zero fonts.gstatic requests before consent', fontRequests.length === 0);

  await page.evaluate(() => {
    const btn = document.querySelector('[data-faz-tag="accept-button"], .faz-accept-all, #faz-accept-all');
    if (!btn) throw new Error('accept button not found');
    btn.click();
  });
  await page.waitForTimeout(800);

  const restored = await page.evaluate((fontUrl) => {
    const style = document.getElementById('faz-inline-font-probe');
    return {
      hasConsent: window.fazcookie._diag().hasConsentCookie,
      parked: !!style?.getAttribute('data-faz-css'),
      liveHasFontUrl: !!style?.textContent?.includes(fontUrl),
    };
  }, FONT_URL);
  assert('accept-all records consent', restored.hasConsent === true);
  assert('accept-all clears data-faz-css', restored.parked === false);
  assert('accept-all restores original fonts.gstatic CSS URL', restored.liveHasFontUrl === true);
} finally {
  await browser.close();
  if (postId && postId !== '0') {
    try { wp(['post', 'delete', postId, '--force']); } catch { /* ignore */ }
  }
}

console.log(`\n=== ${failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'} ===`);
process.exit(failures === 0 ? 0 : 1);
