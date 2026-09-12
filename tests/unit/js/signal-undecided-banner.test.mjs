/**
 * A privacy signal answers one question; the banner still asks the rest.
 *
 * GPC and a Do Not Sell request each create a consent record on the first page
 * (action:yes, the binding opt-out, the audit trail). The init path then read
 * that record as the visitor's decision: from the second page on the banner
 * was removed and only the revisit icon remained — "the banner doesn't appear,
 * only the cookie icon", reported from Zen and Waterfox. Every comment in that
 * path said the opposite: the signal is targeted, not blanket consent, so the
 * notice must stay available until the visitor makes a choice.
 *
 * The same missing restore made _fazApplyGpcOptOut() report a change on EVERY
 * page (the gpc marker was not reloaded into the store), so
 * fazcookie_consent_update — consent-log POST, GCM update, TCF
 * useractioncomplete — fired once per page view for every GPC visitor.
 *
 * These checks run the real _fazInitOperations() against a minimal banner
 * container and observe what the real show/remove functions do to it.
 *
 * The rule is not the same under both laws, and that difference is the point.
 * Under an opt-in law (GDPR) the signal answers the sale/sharing question only,
 * so the notice stays available until the visitor answers the rest. Under an
 * opt-out law (CCPA/CPRA) the signal IS the consumer's decision, and 11 CCR
 * §7026(k) tells the business to wait twelve months before asking an opted-out
 * consumer to opt back in — re-showing the notice every page would be exactly
 * that solicitation, so there the record counts as decided.
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

/** One page load: returns the window after init, with banner/event spies. */
function page({ gpc = false, dnsmpi = false, law = 'gdpr', cookie = '' } = {}) {
  // A minimal banner container: the real _fazShowBanner()/_fazRemoveBanner()
  // act on it. They cannot be replaced by spies — script.js runs inside a block
  // (the double-init guard), so its internal calls bind to block-scoped
  // functions, not to the window copies a test could reassign.
  const dom = new JSDOM('<!DOCTYPE html><html><body><div class="faz-consent-container"><div data-faz-tag="notice"><button type="button">OK</button></div></div></body></html>', {
    runScripts: 'outside-only', url: 'https://signal.example.test/',
  });
  const { window: w } = dom;
  Object.defineProperty(w.navigator, 'globalPrivacyControl', { configurable: true, value: gpc });
  if (cookie) w.document.cookie = `fazcookie-consent=${cookie}; path=/`;
  if (dnsmpi) w.document.cookie = 'fazcookie-dnsmpi=1; path=/';
  w._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true, ccpaDoNotSell: false, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'functional', isNecessary: false, ccpaDoNotSell: false, defaultConsent: { gdpr: false, ccpa: true } },
      { slug: 'marketing', isNecessary: false, ccpaDoNotSell: true, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    _services: [], _providersToBlock: [], _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: false, _perCookieConsent: false, _rootDomain: '',
    _bannerConfig: { settings: { applicableLaw: law }, behaviours: { respectGPC: false }, config: { revisitConsent: { status: false } } },
    _shortCodes: [], i18n: {},
  };
  const realAdd = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  w.eval(SCRIPT);
  w.document.addEventListener = realAdd;

  const spy = { loaded: 0, updates: 0 };
  w.document.addEventListener('fazcookie_banner_loaded', () => { spy.loaded += 1; });
  w.document.addEventListener('fazcookie_consent_update', () => { spy.updates += 1; });
  w.eval('_fazInitOperations()');
  spy.hidden = w.document.querySelector('.faz-consent-container').classList.contains('faz-hide');
  return { w, spy };
}
const cookieOf = (w) => {
  const part = w.document.cookie.split('; ').find((c) => c.startsWith('fazcookie-consent='));
  return part ? part.substring('fazcookie-consent='.length) : '';
};
const pairs = (w) => Object.fromEntries(decodeURIComponent(cookieOf(w)).split(',').map((p) => {
  const i = p.indexOf(':'); return [p.slice(0, i), p.slice(i + 1)];
}));
// shown: _fazShowBanner() ran (it alone fires fazcookie_banner_loaded) and the
// container is visible. removed: _fazRemoveBanner() hid it and nothing showed it.
const banner = (spy) => (spy.loaded > 0 && !spy.hidden ? 'shown' : spy.loaded === 0 && spy.hidden ? 'removed' : `loaded=${spy.loaded} hidden=${spy.hidden}`);

console.log('signal-created records stay undecided (jsdom, real _fazInitOperations)');

for (const law of ['gdpr', 'ccpa']) {
  // --- GPC ---------------------------------------------------------------
  const p1 = page({ gpc: true, law });
  check(`[${law}] GPC, first page: banner shown`, banner(p1.spy) === 'shown');
  check(`[${law}] GPC, first page: the record is written and marked undecided`, pairs(p1.w).action === 'yes' && pairs(p1.w).undecided === '1' && pairs(p1.w).gpc === '1');
  check(`[${law}] GPC, first page: one consent update (the opt-out itself)`, p1.spy.updates === 1);

  const reoffered = law === 'gdpr';   // opt-in law: the banner still has a question to ask
  const p2 = page({ gpc: true, law, cookie: cookieOf(p1.w) });
  check(`[${law}] GPC, second page: banner ${reoffered ? 'STILL shown — the visitor has not answered it' : 'removed — under an opt-out law the signal is the decision'}`,
    banner(p2.spy) === (reoffered ? 'shown' : 'removed'));
  check(`[${law}] GPC, second page: no consent update — nothing changed`, p2.spy.updates === 0);
  check(`[${law}] GPC, second page: still undecided, opt-out still recorded`, pairs(p2.w).undecided === '1' && pairs(p2.w).marketing === 'no');

  const p3 = page({ gpc: true, law, cookie: cookieOf(p2.w) });
  check(`[${law}] GPC, third page: unchanged from the second`, banner(p3.spy) === (reoffered ? 'shown' : 'removed'));

  // The visitor answers: Reject.
  p3.w.eval("_fazAcceptCookies('reject', true)");
  check(`[${law}] GPC, after Reject: undecided is cleared from the cookie`, pairs(p3.w).undecided === undefined);
  const p4 = page({ gpc: true, law, cookie: cookieOf(p3.w) });
  check(`[${law}] GPC, after Reject: next page removes the banner`, banner(p4.spy) === 'removed');
  check(`[${law}] GPC, after Reject: GPC still binds`, pairs(p4.w).marketing === 'no' && pairs(p4.w).gpc === '1');

  // The visitor answers: Accept All — also a decision.
  const q = page({ gpc: true, law, cookie: cookieOf(p2.w) });
  q.w.eval("_fazAcceptCookies('all', true)");
  const q2 = page({ gpc: true, law, cookie: cookieOf(q.w) });
  check(`[${law}] GPC, after Accept All: next page removes the banner`, banner(q2.spy) === 'removed');
  check(`[${law}] GPC, after Accept All: marketing still denied under GPC`, pairs(q2.w).marketing === 'no');

  // --- GPC switched off after the signal created the record ---------------
  const off = page({ gpc: false, law, cookie: cookieOf(p2.w) });
  check(`[${law}] GPC switched off, undecided record: banner ${reoffered ? 'offered' : 'not re-offered'}`,
    banner(off.spy) === (reoffered ? 'shown' : 'removed'));
  check(`[${law}] GPC switched off: the stored state is kept, not re-seeded`, off.w.fazcookie._fazConsentStore.get('marketing') === 'no');
  off.w.eval("_fazAcceptCookies('reject', true)");
  check(`[${law}] GPC switched off: the gpc marker is not carried into a new choice`, pairs(off.w).gpc === undefined);

  // --- Do Not Sell request ------------------------------------------------
  const d1 = page({ dnsmpi: true, law });
  check(`[${law}] Do Not Sell, first page: banner shown, record undecided`, banner(d1.spy) === 'shown' && pairs(d1.w).undecided === '1' && pairs(d1.w).dnsmpi === '1');
  const d2 = page({ dnsmpi: true, law, cookie: cookieOf(d1.w) });
  check(`[${law}] Do Not Sell, second page: banner ${reoffered ? 'STILL shown' : 'removed (opt-out law)'}`,
    banner(d2.spy) === (reoffered ? 'shown' : 'removed'));
  check(`[${law}] Do Not Sell, second page: no consent update`, d2.spy.updates === 0);
  d2.w.eval("_fazAcceptCookies('all', true)");
  const d3 = page({ dnsmpi: true, law, cookie: cookieOf(d2.w) });
  check(`[${law}] Do Not Sell, after Accept All: banner removed, sale/share still denied`, banner(d3.spy) === 'removed' && pairs(d3.w).marketing === 'no');

  // --- Control: no signal behaves exactly as before -----------------------
  const c1 = page({ law });
  check(`[${law}] no signal, first page: banner shown, nothing written`, banner(c1.spy) === 'shown' && cookieOf(c1.w) === '');
  c1.w.eval("_fazAcceptCookies('reject', true)");
  check(`[${law}] no signal: an ordinary decision never carries undecided`, pairs(c1.w).undecided === undefined);
  const c2 = page({ law, cookie: cookieOf(c1.w) });
  check(`[${law}] no signal, after a decision: banner removed`, banner(c2.spy) === 'removed');
}

// A decision made before the signal existed is still a decision.
const before = page({ law: 'gdpr' });
before.w.eval("_fazAcceptCookies('reject', true)");
const later = page({ gpc: true, law: 'gdpr', cookie: cookieOf(before.w) });
check('a decision made before GPC was switched on stays a decision (banner removed)', banner(later.spy) === 'removed');
check('and GPC does not turn it into an undecided record', pairs(later.w).undecided === undefined);

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
