/**
 * A visitor whose browser sends Global Privacy Control can still load a blocked
 * embed by clicking Accept on it — and nothing else is loosened.
 *
 * Reported as "strange behaviour in Firefox" on an Italian GDPR site. It was
 * not Firefox: Brave, Zen and Waterfox send GPC, and GPC is a binding opt-out
 * of sale/sharing. The embed's Accept button wrote svc.<id>:yes and the same
 * save removed it again as a sale/share bypass, so the button did nothing.
 *
 * The exception is deliberately narrow. These checks pin both halves: the
 * clicked service loads and survives a reload, and GPC still binds the
 * category, every other sale/share service and Accept All.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');
const SCRIPT = readFileSync(SCRIPT_PATH, 'utf8');
let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

// Mirrors the reporter's site: GDPR, functional flagged as sale/share (the
// pre-1.17.2 schema default), per-service consent on.
function loadFrontend({ gpc = true, cookie = '' } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only', url: 'https://villa.example.test/',
  });
  const { window } = dom;
  Object.defineProperty(window.navigator, 'globalPrivacyControl', { configurable: true, value: gpc });
  if (cookie) window.document.cookie = `fazcookie-consent=${cookie}; path=/`;
  window._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true, ccpaDoNotSell: false, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'functional', isNecessary: false, ccpaDoNotSell: true, defaultConsent: { gdpr: false, ccpa: true } },
      { slug: 'marketing', isNecessary: false, ccpaDoNotSell: true, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    _services: [
      { id: 'google-maps', category: 'functional' },
      { id: 'ads', category: 'marketing' },
    ],
    _providersToBlock: [], _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: true, _perCookieConsent: false, _rootDomain: '',
    _bannerConfig: { settings: { applicableLaw: 'gdpr' }, behaviours: { respectGPC: false }, config: { revisitConsent: { status: false } } },
    _shortCodes: [],
    i18n: {},
  };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest);
  window.eval(SCRIPT);
  window.document.addEventListener = realAdd;
  return window;
}

const get = (w, k) => w.fazcookie._fazConsentStore.get(k);
const cookieValue = (w) => {
  const part = w.document.cookie.split('; ').find((c) => c.startsWith('fazcookie-consent='));
  return part ? part.substring('fazcookie-consent='.length) : '';
};

console.log('GPC embed exception (jsdom)');

// (a) the click works under GPC, and only for that service.
const w = loadFrontend();
w.eval('_fazApplyGpcOptOut()');
w.eval("window._fazAcceptService('google-maps', 'functional')");
check('(a) the clicked service is granted under GPC', get(w, 'svc.google-maps') === 'yes');
check('(a) and marked as a GPC exception', get(w, 'gpcx.google-maps') === '1');
check('(a) the CATEGORY stays denied — only the service is excused', get(w, 'functional') === 'no');
check('(a) GPC is still recorded', get(w, 'gpc') === '1');

// (b) Accept All still cannot re-grant sale/share, and does not revoke the map.
w.eval("_fazAcceptCookies('all', true)");
check('(b) Accept All leaves the marketing category denied', get(w, 'marketing') === 'no');
check('(b) Accept All leaves the functional category denied', get(w, 'functional') === 'no');
check('(b) an unmarked sale/share service is not granted by Accept All', get(w, 'svc.ads') !== 'yes');
check('(b) the map the visitor opened is not silently revoked by Accept All', get(w, 'svc.google-maps') === 'yes');

// (c) the grant survives a reload and the init-time GPC re-application.
const saved = cookieValue(w);
check('(c) the marker is persisted in the consent cookie', decodeURIComponent(saved).includes('gpcx.google-maps:1'));
const w2 = loadFrontend({ cookie: saved });
w2.eval('_fazApplyGpcOptOut()');
check('(c) after reload + GPC re-application the service is still granted', get(w2, 'svc.google-maps') === 'yes');
check('(c) and still marked', get(w2, 'gpcx.google-maps') === '1');
check('(c) and the category is still denied', get(w2, 'functional') === 'no');

// (d) a grant WITHOUT the marker is still treated as a bypass.
const w3 = loadFrontend();
w3.fazcookie._fazConsentStore.set('svc.ads', 'yes');
w3.eval('_fazApplyGpcOptOut()');
check('(d) an unmarked svc grant in a sale/share category is still removed', get(w3, 'svc.ads') !== 'yes');

// (e) once GPC is gone the marker is pruned; the grant remains an ordinary one.
const w4 = loadFrontend({ gpc: false, cookie: saved });
w4.eval("_fazAcceptCookies('custom', true)");
check('(e) without GPC the marker is pruned', get(w4, 'gpcx.google-maps') === undefined || get(w4, 'gpcx.google-maps') === '');

// (f) a standing Do Not Sell request keeps winning over the embed click.
const w5 = loadFrontend();
w5.document.cookie = 'fazcookie-dnsmpi=1; path=/';
w5.eval('_fazApplyGpcOptOut()');
w5.eval("window._fazAcceptService('google-maps', 'functional')");
check('(f) with a Do Not Sell request, the click does not create an exception', get(w5, 'gpcx.google-maps') !== '1');
check('(f) and the service is not granted', get(w5, 'svc.google-maps') !== 'yes');

// (g) a rejection clears the exception.
const w6 = loadFrontend({ cookie: saved });
w6.eval("_fazAcceptCookies('reject', true)");
check('(g) Reject removes the granted service', get(w6, 'svc.google-maps') !== 'yes');
check('(g) and its marker', get(w6, 'gpcx.google-maps') !== '1');

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
