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
function loadFrontend({ gpc = true, cookie = '', revision = 1, ageGate = false } = {}) {
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
    _perServiceConsent: true, _perCookieConsent: false, _rootDomain: '', _consentRevision: revision,
    _ageGate: ageGate ? { enabled: true, minAge: 16 } : { enabled: false },
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
// Accept THROUGH the shipped placeholder handler, the way a visitor does.
// Calling window._fazAcceptService directly no longer mints an exception: it
// is a public global any script on the page can call, and an exception is a
// consent, so it takes a click on that service's own blocked-content card.
function acceptOnPlaceholder(w, serviceId, category) {
  w.eval('_fazWatchBannerElement()');
  const btn = w.document.createElement('button');
  btn.setAttribute('data-faz-accept', category);
  btn.setAttribute('data-faz-accept-service', serviceId);
  w.document.body.appendChild(btn);
  btn.click();
}
const cookieValue = (w) => {
  const part = w.document.cookie.split('; ').find((c) => c.startsWith('fazcookie-consent='));
  return part ? part.substring('fazcookie-consent='.length) : '';
};

console.log('GPC embed exception (jsdom)');

// (a) the click works under GPC, and only for that service.
const w = loadFrontend();
w.eval('_fazApplyGpcOptOut()');
acceptOnPlaceholder(w, 'google-maps', 'functional');
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
// The cookie is checked, not just the store: the store is rebuilt from the
// cookie on the next load, so a marker deleted in memory and left in the
// cookie comes straight back — and did, until the deletion learned to persist.
const w4 = loadFrontend({ gpc: false, cookie: saved });
w4.eval("_fazAcceptCookies('custom', true)");
check('(e) without GPC the marker is pruned', get(w4, 'gpcx.google-maps') === undefined || get(w4, 'gpcx.google-maps') === '');
check('(e) and the pruning reaches the cookie, not only the store',
  !decodeURIComponent(cookieValue(w4)).includes('gpcx.google-maps'));

// (e2) a marker that outlived its signal is not honoured on the next load, and
// leaves the cookie. Otherwise a GPC opt-out asserted later would find the
// service already excused, with no new act from the visitor.
const w4b = loadFrontend({ gpc: false, cookie: saved });
check('(e2) with GPC off the stored marker is not loaded', get(w4b, 'gpcx.google-maps') !== '1');
w4b.eval("_fazAcceptCookies('custom', true)");
const w4c = loadFrontend({ cookie: cookieValue(w4b) });
w4c.eval('_fazApplyGpcOptOut()');
check('(e2) so GPC asserted later still binds that service', get(w4c, 'svc.google-maps') !== 'yes');

// (h) unticking the service in the preference centre withdraws the exception.
// Withdrawal must be as easy as the grant. Under GPC the category is forced to
// "no", so an unticked toggle matches its category and no svc.<id>:no is ever
// written — the re-assert used to read that absence as "no denial" and put the
// grant straight back, leaving Reject All as the only way out.
const w7 = loadFrontend({ cookie: saved });
w7.eval('_fazApplyGpcOptOut()');
w7.document.body.innerHTML =
  '<input type="checkbox" class="faz-service-toggle" data-service="google-maps" data-category="functional">';
w7.eval("_fazAcceptCookies('custom', true)");
check('(h) an untick withdraws the granted service', get(w7, 'svc.google-maps') !== 'yes');
check('(h) and its marker', get(w7, 'gpcx.google-maps') !== '1');
check('(h) the withdrawal reaches the cookie', !decodeURIComponent(cookieValue(w7)).includes('svc.google-maps:yes'));

// (h2) control: with the toggle still ticked, a save keeps the exception.
const w8 = loadFrontend({ cookie: saved });
w8.eval('_fazApplyGpcOptOut()');
w8.document.body.innerHTML =
  '<input type="checkbox" class="faz-service-toggle" data-service="google-maps" data-category="functional" checked>';
w8.eval("_fazAcceptCookies('custom', true)");
check('(h2) a save with the toggle still ticked keeps the service', get(w8, 'svc.google-maps') === 'yes');
check('(h2) and its marker', get(w8, 'gpcx.google-maps') === '1');

// (i) the marker only excuses a service this site exposes. A hand-edited
// cookie must not be able to keep an arbitrary provider through the binding
// clear, nor put a gpc_exception for it into the consent log.
const w9 = loadFrontend();
w9.fazcookie._fazConsentStore.set('svc.not-a-service-here', 'yes');
w9.fazcookie._fazConsentStore.set('gpcx.not-a-service-here', '1');
w9.eval('_fazApplyGpcOptOut()');
check('(i) a forged marker for an unknown service is not honoured', get(w9, 'svc.not-a-service-here') !== 'yes');

// (f) a standing Do Not Sell request keeps winning over the embed click.
const w5 = loadFrontend();
w5.document.cookie = 'fazcookie-dnsmpi=1; path=/';
w5.eval('_fazApplyGpcOptOut()');
acceptOnPlaceholder(w5, 'google-maps', 'functional');
check('(f) with a Do Not Sell request, the click does not create an exception', get(w5, 'gpcx.google-maps') !== '1');
check('(f) and the service is not granted', get(w5, 'svc.google-maps') !== 'yes');

// (g) a rejection clears the exception.
const w6 = loadFrontend({ cookie: saved });
w6.eval("_fazAcceptCookies('reject', true)");
check('(g) Reject removes the granted service', get(w6, 'svc.google-maps') !== 'yes');
check('(g) and its marker', get(w6, 'gpcx.google-maps') !== '1');

// (k) a consent revision bump wipes the record, exception and marker included.
// The visitor is asked again from scratch, so nothing may quietly carry over.
const w12 = loadFrontend({ cookie: saved, revision: 5 });
check('(k) a revision bump clears the granted service', get(w12, 'svc.google-maps') !== 'yes');
check('(k) and its marker', get(w12, 'gpcx.google-maps') !== '1');
// _fazInvalidateStoredConsent() keeps the key and empties it; "not yes" would
// also accept "no", which is truthy and reads as a recorded action.
check('(k) and the recorded action', (get(w12, 'action') || '') === '');
w12.eval('_fazApplyGpcOptOut()');
check('(k) so the service is bound by GPC again', get(w12, 'svc.google-maps') !== 'yes');

// (l) the age gate parks the click; ticking the box must replay it AS a
// placeholder click. The replay runs long after the handler reset the flag, so
// without carrying the origin on the parked entry the replayed grant would not
// be an exception and the binding pass would drop it — the embed would stay
// blocked for a visitor who did everything asked of them.
const w13 = loadFrontend({ ageGate: true });
w13.eval('_fazApplyGpcOptOut()');
w13.eval('_fazWatchBannerElement()');
w13.document.body.innerHTML =
  '<button data-faz-accept="functional" data-faz-accept-service="google-maps"></button>' +
  '<input type="checkbox" class="faz-age-confirm-cb">';
w13.document.querySelector('[data-faz-accept]').click();
check('(l) the age gate parks the click instead of granting it', get(w13, 'svc.google-maps') !== 'yes');
w13.document.querySelector('.faz-age-confirm-cb').checked = true;
w13.eval('_fazResumePendingAgeGatedGrant()');
check('(l) ticking the age box replays it as an embed click', get(w13, 'svc.google-maps') === 'yes');
check('(l) so the exception is minted', get(w13, 'gpcx.google-maps') === '1');
check('(l) and the category stays denied', get(w13, 'functional') === 'no');

// (j) a script calling the public API directly gets no exception: the grant is
// still made and then cleared by the binding pass, exactly as before 1.31.0.
const w10 = loadFrontend();
w10.eval('_fazApplyGpcOptOut()');
w10.eval("window._fazAcceptService('google-maps', 'functional')");
check('(j) a direct API call mints no GPC exception marker', get(w10, 'gpcx.google-maps') !== '1');
const w11 = loadFrontend({ cookie: cookieValue(w10) });
w11.eval('_fazApplyGpcOptOut()');
check('(j) and the grant does not survive the next GPC pass', get(w11, 'svc.google-maps') !== 'yes');

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
