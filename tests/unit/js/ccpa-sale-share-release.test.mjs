/**
 * JS unit test (jsdom) — the runtime's half of the CCPA sale/share parking.
 *
 * Under a CCPA-law banner the server now parks the sale/sharing categories in
 * the shared render (text/plain + data-faz-category, data-faz-src), because a
 * cached copy is replayed to visitors whose Sec-GPC or Do Not Sell signal the
 * server never saw. The opt-out law still lets those scripts run for a visitor
 * with no signal, so the runtime must release them on its first pass, and
 * must never release them when a signal or a recorded opt-out is present:
 *
 *   1. no signal, first visit: released by _fazInitOperations() itself;
 *   2. navigator.globalPrivacyControl === true: stays parked;
 *   3. the `fazcookie-dnsmpi=1` cookie: stays parked;
 *   4. an opt-out recorded in the consent cookie: stays parked, restore pass
 *      included;
 *   5. a recorded grant: released by the restore pass.
 *
 * Runs the real _fazInitOperations() of frontend/js/script.js on a page shaped
 * like the server's output, with the DOMContentLoaded bootstrap neutralised.
 *
 * Run: node tests/unit/js/ccpa-sale-share-release.test.mjs
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

// The parked shapes process_output_buffer() writes for a sale/share category.
const PAGE = '<!DOCTYPE html><html><head>'
  + '<script type="text/plain" data-faz-category="marketing">window.__pixelInline = 1;</script>'
  + '<script data-faz-category="marketing" type="text/plain" src="https://connect.facebook.net/en_US/fbevents.js"></script>'
  + '</head><body>'
  + '<div class="faz-consent-container"><div data-faz-tag="notice"><button type="button">OK</button></div></div>'
  + '<img data-faz-category="marketing" data-faz-src="https://www.facebook.com/tr?id=1&ev=PageView">'
  + '</body></html>';

function page({ gpc = false, cookies = [] } = {}) {
  const dom = new JSDOM(PAGE, { runScripts: 'outside-only', url: 'https://shop.example.test/' });
  const { window: w } = dom;
  Object.defineProperty(w.navigator, 'globalPrivacyControl', { configurable: true, value: gpc });
  for (const c of cookies) w.document.cookie = c;
  w._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true, ccpaDoNotSell: false, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'marketing', isNecessary: false, ccpaDoNotSell: true, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    _services: [], _providersToBlock: [], _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: false, _perCookieConsent: false, _rootDomain: '',
    _bannerConfig: { settings: { applicableLaw: 'ccpa' }, behaviours: { respectGPC: false }, config: { revisitConsent: { status: false } } },
    _shortCodes: [], i18n: {},
  };
  const realAdd = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  w.eval(SCRIPT);
  w.document.addEventListener = realAdd;
  w.eval('_fazInitOperations()');
  return w;
}
const scripts = (w) => [...w.document.querySelectorAll('script')];
const inlineReleased = (w) => scripts(w).some((s) => s.textContent.includes('__pixelInline') && s.type !== 'text/plain');
const srcReleased = (w) => scripts(w).some((s) => (s.getAttribute('src') || '').includes('fbevents.js') && s.type !== 'text/plain');
const pixelReleased = (w) => (w.document.querySelector('img').getAttribute('src') || '').includes('facebook.com/tr');
const noneReleased = (w) => !inlineReleased(w) && !srcReleased(w) && !pixelReleased(w);
const record = (pairs) => `fazcookie-consent=${encodeURIComponent(pairs)}; path=/`;

console.log('CCPA sale/share parking: the runtime releases it only without a signal (jsdom)');

{
  const w = page();
  check('no signal, first visit: the parked inline script is released at boot', inlineReleased(w));
  check('no signal, first visit: the parked external script is released at boot', srcReleased(w));
  check('no signal, first visit: the parked pixel gets its src back', pixelReleased(w));
}
{
  const w = page({ gpc: true });
  check('navigator.globalPrivacyControl: nothing released', noneReleased(w));
  w.eval('_fazUnblock()');
  check('navigator.globalPrivacyControl: nothing released by a later restore pass either', noneReleased(w));
}
{
  const w = page({ cookies: ['fazcookie-dnsmpi=1; path=/'] });
  check('Do Not Sell cookie: nothing released', noneReleased(w));
  w.eval('_fazUnblock()');
  check('Do Not Sell cookie: nothing released by a later restore pass either', noneReleased(w));
}
{
  const w = page({ cookies: [record('consentid:abc,consent:no,action:yes,necessary:yes,marketing:no')] });
  w.eval('_fazUnblock()'); // what _fazScheduleRestoreUnblock() runs on its timers
  check('an opt-out recorded in the consent cookie: nothing released', noneReleased(w));
}
{
  const w = page({ cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,marketing:yes')] });
  w.eval('_fazUnblock()');
  check('a recorded grant: the restore pass releases the parked script', inlineReleased(w) && srcReleased(w));
  check('a recorded grant: and the pixel', pixelReleased(w));
}

console.log(`\nccpa-sale-share-release: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
