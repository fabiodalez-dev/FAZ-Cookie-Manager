/**
 * Regression for the opt-in aggressive inline CSS URL blocker.
 *
 * The standard blocker covers server-rendered <style> tags and direct
 * HTMLStyleElement writes. When the advanced setting is enabled, broader
 * runtime channels are also gated: Element.innerHTML, insertAdjacentHTML,
 * CharacterData edits inside <style>, and Constructable Stylesheets.
 *
 * Run:
 *   WP_BASE_URL=http://127.0.0.1:9998 WP_PATH=/Users/fabio/Sites/faz-test \
 *     node tests/e2e/aggressive-css-url-blocking-enabled.mjs
 */

import { execFileSync } from 'node:child_process';
import { chromium } from '@playwright/test';

const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';
const WP_PATH = process.env.WP_PATH;
if (!WP_PATH) {
  console.error('WP_PATH not set: export WP_PATH to the WordPress install root.');
  process.exit(1);
}

const FONT_URL = 'https://fonts.gstatic.com/s/roboto/v30/KFOmCnqEu92Fr1Mu4mxK.woff2';

function wp(args) {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

function enableAggressiveMode() {
  return wp(['eval', `
$settings = get_option('faz_settings', array());
if (! is_array($settings)) {
	$settings = array();
}
if (! isset($settings['script_blocking']) || ! is_array($settings['script_blocking'])) {
	$settings['script_blocking'] = array();
}
$settings['script_blocking']['aggressive_css_url_blocking'] = true;
update_option('faz_settings', $settings);
do_action('faz_clear_cache');
echo ! empty($settings['script_blocking']['aggressive_css_url_blocking']) ? '1' : '0';
`]);
}

function restoreSettings(encodedSettings) {
  if (!encodedSettings) return;
  wp(['eval', `
$settings = json_decode(base64_decode('${encodedSettings}'), true);
update_option('faz_settings', is_array($settings) ? $settings : array());
do_action('faz_clear_cache');
`]);
}

let failures = 0;
function assert(name, cond) {
  console.log(`  ${cond ? 'PASS' : 'FAIL'} ${name}`);
  if (!cond) failures++;
}

let originalSettings = '';
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const page = await context.newPage();
const fontRequests = [];

page.on('request', (request) => {
  if (request.url().includes('fonts.gstatic.com')) fontRequests.push(request.url());
});
await page.route('https://fonts.gstatic.com/**', (route) => {
  route.fulfill({ status: 204, body: '' });
});

try {
  originalSettings = wp(['eval', "echo base64_encode(wp_json_encode(get_option('faz_settings', array()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));"]);
  assert('WP setting can be enabled', enableAggressiveMode() === '1');

  await page.goto(`${WP}/?nocache=aggressive-css-${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForFunction(() => window.fazcookie && window.fazcookie._diag && window.fazcookie._diag().ready, { timeout: 8000 });

  const precondition = await page.evaluate(() => ({
    active: !!((window._fazConfig || {})._aggressiveCssUrlBlocking),
    block: String((window._fazConfig || {})._block),
    hasConsent: window.fazcookie._diag().hasConsentCookie,
  }));
  assert('localized config exposes aggressive CSS URL blocking', precondition.active === true);
  assert('precondition: blocker is active without consent', precondition.block === '1' && precondition.hasConsent === false);

  const injected = await page.evaluate((fontUrl) => {
    const urls = {
      inner: `${fontUrl}?inner`,
      adjacent: `${fontUrl}?adjacent`,
      char: `${fontUrl}?char`,
      sheet: `${fontUrl}?sheet`,
    };

    function styleState(id, url) {
      const style = document.getElementById(id);
      const text = style ? style.textContent || '' : '';
      return {
        exists: !!style,
        parked: !!(style && style.getAttribute('data-faz-css')),
        category: style ? style.getAttribute('data-faz-category') || '' : '',
        hasFont: text.includes(url),
        hasInert: text.includes('data:application/octet-stream,'),
      };
    }

    const innerHost = document.createElement('div');
    document.body.appendChild(innerHost);
    innerHost.innerHTML = `<style id="faz-aggr-inner">.faz-aggr-inner{background-image:url("${urls.inner}")}</style><div class="faz-aggr-inner">inner</div>`;

    document.body.insertAdjacentHTML(
      'beforeend',
      `<style id="faz-aggr-adjacent">.faz-aggr-adjacent{background-image:url("${urls.adjacent}")}</style><div class="faz-aggr-adjacent">adjacent</div>`
    );

    const charStyle = document.createElement('style');
    charStyle.id = 'faz-aggr-char';
    charStyle.appendChild(document.createTextNode('.faz-aggr-char{color:red;}'));
    document.head.appendChild(charStyle);
    charStyle.firstChild.appendData(`.faz-aggr-char{background-image:url("${urls.char}")}`);

    const sheetState = { supported: !!(window.CSSStyleSheet && 'adoptedStyleSheets' in document) };
    if (sheetState.supported) {
      const sheet = new CSSStyleSheet();
      sheet.replaceSync(`.faz-aggr-sheet{background-image:url("${urls.sheet}")}`);
      document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet];
      window.__fazAggressiveCssSheet = sheet;
      const cssText = Array.from(sheet.cssRules).map((rule) => rule.cssText).join('\n');
      sheetState.hasFont = cssText.includes(urls.sheet);
      sheetState.hasInert = cssText.includes('data:application/octet-stream,');
    }

    return {
      inner: styleState('faz-aggr-inner', urls.inner),
      adjacent: styleState('faz-aggr-adjacent', urls.adjacent),
      char: styleState('faz-aggr-char', urls.char),
      sheet: sheetState,
    };
  }, FONT_URL);

  assert('Element.innerHTML style URL is parked', injected.inner.exists && injected.inner.parked && injected.inner.category === 'functional');
  assert('Element.innerHTML style URL is neutralized before consent', injected.inner.hasFont === false && injected.inner.hasInert === true);
  assert('insertAdjacentHTML style URL is parked', injected.adjacent.exists && injected.adjacent.parked && injected.adjacent.category === 'functional');
  assert('insertAdjacentHTML style URL is neutralized before consent', injected.adjacent.hasFont === false && injected.adjacent.hasInert === true);
  assert('CharacterData style URL is parked', injected.char.exists && injected.char.parked && injected.char.category === 'functional');
  assert('CharacterData style URL is neutralized before consent', injected.char.hasFont === false && injected.char.hasInert === true);
  assert('Constructable Stylesheet URL is neutralized before consent', !injected.sheet.supported || (injected.sheet.hasFont === false && injected.sheet.hasInert === true));

  await page.waitForTimeout(800);
  assert('zero fonts.gstatic requests before consent with aggressive mode enabled', fontRequests.length === 0);

  await page.evaluate(() => {
    const btn = document.querySelector('[data-faz-tag="accept-button"], .faz-accept-all, #faz-accept-all');
    if (!btn) throw new Error('accept button not found');
    btn.click();
  });
  await page.waitForTimeout(800);

  const restored = await page.evaluate((fontUrl) => {
    const urls = {
      inner: `${fontUrl}?inner`,
      adjacent: `${fontUrl}?adjacent`,
      char: `${fontUrl}?char`,
      sheet: `${fontUrl}?sheet`,
    };

    function styleState(id, url) {
      const style = document.getElementById(id);
      const text = style ? style.textContent || '' : '';
      return {
        exists: !!style,
        parked: !!(style && style.getAttribute('data-faz-css')),
        hasFont: text.includes(url),
        hasInert: text.includes('data:application/octet-stream,'),
      };
    }

    const sheetState = { supported: !!(window.__fazAggressiveCssSheet && window.__fazAggressiveCssSheet.cssRules) };
    if (sheetState.supported) {
      const cssText = Array.from(window.__fazAggressiveCssSheet.cssRules).map((rule) => rule.cssText).join('\n');
      sheetState.hasFont = cssText.includes(urls.sheet);
      sheetState.hasInert = cssText.includes('data:application/octet-stream,');
    }

    return {
      hasConsent: window.fazcookie._diag().hasConsentCookie,
      inner: styleState('faz-aggr-inner', urls.inner),
      adjacent: styleState('faz-aggr-adjacent', urls.adjacent),
      char: styleState('faz-aggr-char', urls.char),
      sheet: sheetState,
    };
  }, FONT_URL);

  assert('accept-all records consent', restored.hasConsent === true);
  assert('Element.innerHTML CSS URL is restored after consent', restored.inner.exists && !restored.inner.parked && restored.inner.hasFont && !restored.inner.hasInert);
  assert('insertAdjacentHTML CSS URL is restored after consent', restored.adjacent.exists && !restored.adjacent.parked && restored.adjacent.hasFont && !restored.adjacent.hasInert);
  assert('CharacterData CSS URL is restored after consent', restored.char.exists && !restored.char.parked && restored.char.hasFont && !restored.char.hasInert);
  assert('Constructable Stylesheet URL is restored after consent', !restored.sheet.supported || (restored.sheet.hasFont && !restored.sheet.hasInert));
} finally {
  await browser.close();
  try { restoreSettings(originalSettings); } catch (e) { console.error('Failed to restore faz_settings:', e.message); failures++; }
}

console.log(`\n=== ${failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'} ===`);
process.exit(failures === 0 ? 0 : 1);
