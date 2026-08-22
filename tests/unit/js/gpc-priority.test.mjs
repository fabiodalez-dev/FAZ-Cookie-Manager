/**
 * GPC must override conflicting sale/share grants regardless of the banner
 * toggle, while preserving unrelated consent and remaining idempotent.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');
let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

function loadFrontend() {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: 'https://shop.example.test/' });
  const { window } = dom;
  Object.defineProperty(window.navigator, 'globalPrivacyControl', { configurable: true, value: true });
  window._fazConfig = {
    _runtimeGeo: true,
    _categories: [
      { slug: 'necessary', isNecessary: true, ccpaDoNotSell: false, defaultFromRuleset: true, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'marketing', isNecessary: false, ccpaDoNotSell: true, defaultFromRuleset: true, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'analytics', isNecessary: false, ccpaDoNotSell: false, defaultFromRuleset: true, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'profiling', isNecessary: false, ccpaDoNotSell: false, defaultFromRuleset: true, requiresSeparateOptIn: true, defaultConsent: { gdpr: false, ccpa: false } },
    ],
    _services: [
      { id: 'ads', category: 'marketing' },
      { id: 'maps', category: 'analytics' },
    ],
    _providersToBlock: [], _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: true, _perCookieConsent: false, _rootDomain: '',
    // Deliberately false: the browser signal must not depend on this toggle.
    _bannerConfig: { settings: { applicableLaw: 'gdpr' }, behaviours: { respectGPC: false } },
    i18n: {},
  };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest);
  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.addEventListener = realAdd;
  return window;
}

console.log('GPC priority and targeted revocation (jsdom)');
{
  const window = loadFrontend();
  check('GPC is detected even when the publisher toggle is false', window.eval('_fazGpcActive()') === true);
  const store = window.fazcookie._fazConsentStore;
  store.set('action', 'yes'); store.set('consent', 'yes');
  store.set('necessary', 'yes'); store.set('marketing', 'yes'); store.set('analytics', 'yes'); store.set('profiling', 'yes');
  store.set('svc.ads', 'yes'); store.set('svc.maps', 'yes');
  let events = 0;
  window.document.addEventListener('fazcookie_consent_update', () => { events += 1; });
  check('the first application reports a changed state', window.eval('_fazApplyGpcOptOut()') === true);
  check('the sale/share category is revoked over prior consent', store.get('marketing') === 'no');
  check('an unrelated analytics grant is preserved', store.get('analytics') === 'yes');
  check('an unrelated sensitive choice is preserved (GPC is not blanket GDPR reject)', store.get('profiling') === 'yes');
  check('sale/share service override is removed', store.has('svc.ads') === false);
  check('unrelated service override is preserved', store.get('svc.maps') === 'yes');
  check('the audit marker is persisted', store.get('gpc') === '1');
  check('one update event is emitted', events === 1);
  check('reapplying the same signal is idempotent', window.eval('_fazApplyGpcOptOut()') === false && events === 1);
}

{
  const window = loadFrontend();
  window.eval('_fazSeedInitialState(); _fazApplyGpcOptOut();');
  const store = window.fazcookie._fazConsentStore;
  check('fresh jurisdiction-granted sale/share default is overridden', store.get('marketing') === 'no');
  check('fresh non-sale jurisdiction grant remains available', store.get('analytics') === 'yes');
  check('fresh separate sensitive opt-in remains denied', store.get('profiling') === 'no');
}

{
  const window = loadFrontend();
  window.eval('_fazSeedInitialState(); _fazAcceptCookies("all", true);');
  const store = window.fazcookie._fazConsentStore;
  check('Accept All grants an ordinary optional purpose', store.get('analytics') === 'yes');
  check('Accept All cannot bundle a category requiring separate opt-in', store.get('profiling') === 'no');
  check('Accept All cannot override a currently asserted GPC signal', store.get('marketing') === 'no');
  check('the GPC audit marker survives an explicit consent action', store.get('gpc') === '1');
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
