/**
 * JS unit test (jsdom) — the [faz_do_not_sell] opt-out must be honoured by the
 * CLIENT, and the user whitelist must not substring-match bare tokens
 * against URLs.
 *
 * Two defects pinned here:
 *
 * 1. The DNSMPI cookie used to be httponly, so script.js could not see it: the
 *    server shipped sell/share scripts blocked, but the stored consent still
 *    said "yes" and _fazUnblockServerSide restored them one tick later. The
 *    cookie is now JS-readable and _fazApplyDnsmpiOptOut() reconciles the
 *    store with it on boot — even over a prior stored consent, because the
 *    form opt-out postdates it (Cal. Civ. Code §1798.120).
 *
 * 2. _fazIsUserWhitelisted substring-matched EVERY whitelist token against the
 *    URL, so a short bare entry like "js" disabled client-side blocking
 *    wholesale. URL matching is now reserved for URL-fragment patterns
 *    (contain "." or "/", at least 3 chars), mirroring the server-side
 *    matches_whitelist_pattern() contract; bare tokens only match element
 *    id/class attributes, whole-word.
 *
 * Loads the real frontend/js/script.js with automatic DOMContentLoaded
 * bootstrap disabled, then exercises the shipped implementations.
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
  if (condition) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
  }
}

function loadFrontend({ userWhitelist = [], applicableLaw = 'ccpa' } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://shop.example.test/',
  });
  const { window } = dom;
  window._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true, ccpaDoNotSell: false, defaultConsent: { gdpr: true, ccpa: true } },
      // Sold/shared — the category a Do-Not-Sell opt-out must revoke.
      { slug: 'marketing', isNecessary: false, ccpaDoNotSell: true, defaultConsent: { gdpr: false, ccpa: false } },
      // Neither sold nor shared — CCPA-exempt, must survive the opt-out.
      { slug: 'functional', isNecessary: false, ccpaDoNotSell: false, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    _services: [
      { id: 'facebook', category: 'marketing' },
      { id: 'maps', category: 'functional' },
    ],
    _providersToBlock: [],
    _cookieCategoryMap: {},
    _whitelistedCookiePatterns: [],
    _userWhitelist: userWhitelist,
    _perServiceConsent: false,
    _perCookieConsent: false,
    _rootDomain: '',
    _bannerConfig: { settings: { applicableLaw } },
    i18n: {},
  };

  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.addEventListener = realAdd;
  return window;
}

console.log('DNSMPI client-side opt-out + user-whitelist matching (jsdom)');

// 1. Cookie detection.
{
  const window = loadFrontend();
  check('no dnsmpi cookie: detector reports inactive', window.eval('_fazDnsmpiCookieActive()') === false);
  window.document.cookie = 'fazcookie-dnsmpi=1;path=/';
  check('dnsmpi cookie present: detector reports active', window.eval('_fazDnsmpiCookieActive()') === true);
}

// 2. A standing opt-out revokes a granted sell/share category — and only that.
{
  const window = loadFrontend();
  window.document.cookie = 'fazcookie-dnsmpi=1;path=/';
  window.fazcookie._fazConsentStore.set('action', 'yes');
  window.fazcookie._fazConsentStore.set('marketing', 'yes');
  window.fazcookie._fazConsentStore.set('functional', 'yes');
  let events = 0;
  window.document.addEventListener('fazcookie_consent_update', () => { events += 1; });
  window.eval('_fazApplyDnsmpiOptOut()');
  check('sell/share category is revoked over the prior stored consent', window.fazcookie._fazConsentStore.get('marketing') === 'no');
  check('CCPA-exempt category keeps its grant', window.fazcookie._fazConsentStore.get('functional') === 'yes');
  check('the recorded state carries the dnsmpi audit marker', window.fazcookie._fazConsentStore.get('dnsmpi') === '1');
  check('one consent event is fired so integrations see the revocation', events === 1);

  // 3. Idempotent: a second boot with the store already reconciled is silent.
  window.eval('_fazApplyDnsmpiOptOut()');
  check('a second application is a no-op (no duplicate event)', events === 1);
}

// 4. A fresh visitor still gets a persistent, granular-safe opt-out.
{
  const window = loadFrontend();
  window.document.cookie = 'fazcookie-dnsmpi=1;path=/';
  window.fazcookie._fazConsentStore.set('svc.facebook', 'yes');
  window.fazcookie._fazConsentStore.set('ck.facebook._fbp', 'yes');
  window.fazcookie._fazConsentStore.set('svc.maps', 'yes');
  window.fazcookie._fazConsentStore.set('ck.maps.locale', 'yes');
  let events = 0;
  window.document.addEventListener('fazcookie_consent_update', () => { events += 1; });
  window.eval('_fazApplyDnsmpiOptOut()');
  check('fresh DNSMPI writes an auditable action', window.fazcookie._fazConsentStore.get('action') === 'yes');
  check('fresh DNSMPI writes its audit marker', window.fazcookie._fazConsentStore.get('dnsmpi') === '1');
  check('fresh DNSMPI denies the sell/share category', window.fazcookie._fazConsentStore.get('marketing') === 'no');
  check('fresh DNSMPI clears an allowed service override', !window.fazcookie._fazConsentStore.has('svc.facebook'));
  check('fresh DNSMPI clears an allowed cookie override', !window.fazcookie._fazConsentStore.has('ck.facebook._fbp'));
  check('fresh DNSMPI preserves an exempt service override', window.fazcookie._fazConsentStore.get('svc.maps') === 'yes');
  check('fresh DNSMPI preserves an exempt cookie override', window.fazcookie._fazConsentStore.get('ck.maps.locale') === 'yes');
  check('fresh DNSMPI emits the consent update once', events === 1);
}

// 5. A "Both" banner keeps categories outside sale/share usable.
{
  const window = loadFrontend({ applicableLaw: 'gdpr_ccpa' });
  window.document.cookie = 'fazcookie-dnsmpi=1;path=/';
  window.fazcookie._fazConsentStore.set('action', 'yes');
  window.fazcookie._fazConsentStore.set('consent', 'yes');
  window.fazcookie._fazConsentStore.set('functional', 'yes');
  window.fazcookie._fazConsentStore.set('marketing', 'yes');
  window.eval('_fazApplyDnsmpiOptOut()');
  check('Both: functional grant survives the targeted opt-out', window.fazcookie._fazConsentStore.get('functional') === 'yes');
  check('Both: marketing remains denied', window.fazcookie._fazConsentStore.get('marketing') === 'no');
  check('Both: global GDPR consent is not rewritten into a blanket rejection', window.fazcookie._fazConsentStore.get('consent') === 'yes');
}

// 6. User whitelist: bare tokens must not substring-match URLs.
{
  const window = loadFrontend({ userWhitelist: ['js', 'recaptcha', 'googleapis.com/maps'] });
  check(
    'a short bare token no longer matches every URL',
    window.eval('_fazIsUserWhitelisted("https://tracker.example/js/tracker.js", null)') === false
  );
  check(
    'a bare token does not match URLs at all',
    window.eval('_fazIsUserWhitelisted("https://example.com/recaptcha/api.js", null)') === false
  );
  check(
    'a URL-fragment pattern (contains a dot) still matches',
    window.eval('_fazIsUserWhitelisted("https://maps.googleapis.com/maps/api/js", null)') === true
  );
  const el = window.document.createElement('script');
  el.setAttribute('class', 'recaptcha');
  window.__testEl = el;
  check(
    'a bare token still matches an element class, whole-word',
    window.eval('_fazIsUserWhitelisted("https://example.com/anything.js", window.__testEl)') === true
  );
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
