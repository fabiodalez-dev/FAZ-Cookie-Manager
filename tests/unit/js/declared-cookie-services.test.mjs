/**
 * JS unit test (jsdom) — administrator-declared cookies as consent services,
 * and opt-in scripts flagged "run on every page load" (#360).
 *
 * Two runtime defects reported in the issue:
 *
 *   1. A cookie declared by hand under Cookies → Functional had no entry in
 *      window.getFazConsent().services, so a theme could not ask the runtime
 *      whether THAT widget was accepted. The server now ships such cookies as
 *      `_declaredServices`, whether or not per-service consent is on, and the
 *      public map has to report them under their service id with the
 *      category's consent.
 *   2. The per-cookie opt-in script fired once, on the accept click, and never
 *      again. With the admin flag the server also puts it in
 *      `_cookieScripts.<cat>.opt_in_persistent`; the bootstrap must replay that
 *      list for a RETURNING visitor whose category is accepted — and nothing
 *      else: not on a first visit, not for a rejected category, not the
 *      unflagged `opt_in` scripts, and never twice on the page where consent is
 *      given (the transition path already runs `opt_in`).
 *
 * Runs the real frontend/js/script.js with its DOMContentLoaded bootstrap
 * neutralised, then drives _fazInitOperations() / _fazAcceptCookies() itself.
 * `runScripts: 'dangerously'` is needed so the <script> elements
 * _fazRunScript() injects actually execute in jsdom; the evidence is the
 * window globals those scripts set.
 *
 * Run: node tests/unit/js/declared-cookie-services.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, cond) {
  if (cond) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

const PAGE = '<!DOCTYPE html><html><head></head><body>'
  + '<div id="faz-consent"><div class="faz-consent-container"><div data-faz-tag="notice">'
  + '<div class="faz-notice-btn-wrapper" data-faz-tag="notice-buttons"><button type="button">OK</button></div>'
  + '</div></div></div></body></html>';

const record = (pairs) => `fazcookie-consent=${encodeURIComponent(pairs)}; path=/`;

/**
 * A page with one declared "booking widget" cookie in `functional` whose opt-in
 * script is flagged persistent, one unflagged opt-in script in `analytics`,
 * and a persistent script in `marketing` (so a rejected category can be checked).
 */
function page({ cookies = [], declared = true, perService = false, preview = false, gpc = false } = {}) {
  const dom = new JSDOM(PAGE, { runScripts: 'dangerously', url: 'https://shop.example.test/' + (preview ? '?faz_preview=true' : '') });
  const { window: w } = dom;
  Object.defineProperty(w.navigator, 'globalPrivacyControl', { configurable: true, value: gpc });
  for (const c of cookies) w.document.cookie = c;
  w._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'functional', isNecessary: false, defaultConsent: { gdpr: false, ccpa: true } },
      { slug: 'analytics', isNecessary: false, defaultConsent: { gdpr: false, ccpa: true } },
      { slug: 'marketing', isNecessary: false, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    // Entries carry their owning declared service ({code, service}); the
    // analytics one is a bare string, the shape of a map cached before #360.
    _cookieScripts: {
      functional: {
        opt_in: [{ code: 'window.__bk = (window.__bk || 0) + 1;', service: 'bk_session' }],
        opt_out: [{ code: 'window.__bkOut = (window.__bkOut || 0) + 1;', service: 'bk_session' }],
        opt_in_persistent: [{ code: 'window.__bk = (window.__bk || 0) + 1;', service: 'bk_session' }],
      },
      analytics: {
        opt_in: ['window.__ga = (window.__ga || 0) + 1;'],
        opt_out: [],
        opt_in_persistent: [],
      },
      marketing: {
        opt_in: [{ code: 'window.__ads = (window.__ads || 0) + 1;', service: '' }],
        opt_out: [],
        opt_in_persistent: [{ code: 'window.__ads = (window.__ads || 0) + 1;', service: '' }],
      },
    },
    _services: [], _providersToBlock: [], _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: perService, _perCookieConsent: false, _rootDomain: '', _activeLaw: 'gdpr', _bannerSlug: 'gdpr', _expiry: 180,
    _bannerConfig: { settings: { applicableLaw: 'gdpr' }, behaviours: { respectGPC: false }, config: { revisitConsent: { status: false } } },
    _shortCodes: [], i18n: {}, _i18n: {},
  };
  if (declared) {
    const svc = { id: 'bk_session', label: 'bk_session', category: 'functional', patterns: [], cookies: ['bk_session'], third_party: false, declared: true };
    w._fazConfig._declaredServices = [svc];
    // With per-service consent on the server also lists it as a visible toggle.
    if (perService) w._fazConfig._services = [svc];
  }
  const realAdd = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  w.eval(SCRIPT);
  w.document.addEventListener = realAdd;
  return w;
}

console.log('declared cookies as services + persistent opt-in scripts (#360, jsdom)');

// ---------------------------------------------------------------------------
// Returning visitor: functional + analytics accepted, marketing rejected.
// ---------------------------------------------------------------------------
{
  const w = page({ cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:no')] });
  w.eval('_fazInitOperations()');
  check('returning visitor: the flagged opt-in script runs once on page load', w.__bk === 1);
  check('returning visitor: an UNflagged opt-in script does not run on page load', w.__ga === undefined);
  check('returning visitor: a flagged script in a REJECTED category does not run', w.__ads === undefined);
  // A second bootstrap on the same page (optimiser double-init) must not replay.
  w.eval('_fazRunPersistentOptInScripts()');
  check('returning visitor: the replay is once per page load (idempotent)', w.__bk === 1);

  const services = w.getFazConsent().services;
  check('getFazConsent().services lists the declared cookie under its service id', Object.prototype.hasOwnProperty.call(services, 'bk_session'));
  check('…and reports it as accepted (its category is accepted)', services.bk_session === true);
}

// ---------------------------------------------------------------------------
// Returning visitor who rejected functional: no replay, service reads false.
// ---------------------------------------------------------------------------
{
  const w = page({ cookies: [record('consentid:abc,consent:no,action:yes,necessary:yes,functional:no,analytics:no,marketing:no')] });
  w.eval('_fazInitOperations()');
  check('rejected category: the flagged script does not run on page load', w.__bk === undefined);
  check('rejected category: getFazConsent().services reports the declared cookie as false', w.getFazConsent().services.bk_session === false);
}

// ---------------------------------------------------------------------------
// First visit: nothing runs before consent; the accept click runs opt_in ONCE
// even though the same script is also in opt_in_persistent.
// ---------------------------------------------------------------------------
{
  const w = page();
  w.eval('_fazInitOperations()');
  check('first visit: no script runs before consent', w.__bk === undefined && w.__ga === undefined && w.__ads === undefined);
  check('first visit: the declared cookie is listed but not accepted', w.getFazConsent().services.bk_session === false);
  // The accept button's handler (_fazAcceptReject closure): record, then run
  // the after-consent pass that executes the per-cookie scripts.
  w.eval('_fazAcceptCookies("all"); _fazAfterConsent();');
  check('accept click: the flagged script runs exactly once (transition path only, no double fire)', w.__bk === 1);
  check('accept click: the unflagged script runs once too', w.__ga === 1);
  check('accept click: the public map now reports the declared cookie as accepted', w.getFazConsent().services.bk_session === true);
}

// ---------------------------------------------------------------------------
// Per-service consent ON: the category is granted but the declared service is
// denied (svc.bk_session:no). Neither the replay nor the accept transition may
// run its script; an entry with no owner (scanner row) stays category-gated.
// ---------------------------------------------------------------------------
{
  const w = page({ perService: true, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes,svc.bk_session:no')] });
  w.eval('_fazInitOperations()');
  check('per-service on, service denied: the flagged script is NOT replayed although its category is granted', w.__bk === undefined);
  check('per-service on, service denied: an owner-less flagged script in a granted category still replays', w.__ads === 1);
  check('per-service on, service denied: getFazConsent().services reports the declared cookie as false', w.getFazConsent().services.bk_session === false);
  check('per-service on, service denied: getFazCookieConsent() reports it false too', w.getFazCookieConsent('bk_session') === false);
  // The accept transition (category went from not-accepted to accepted) must
  // honour the same denial.
  w.eval('_fazExecuteConsentScripts([])');
  check('per-service on, service denied: the accept transition skips the denied owner', w.__bk === undefined);
  check('per-service on, service denied: …but runs the owner-less and the legacy bare-string entries', w.__ads === 2 && w.__ga === 1);
}
{
  const w = page({ perService: true, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:no,marketing:no,svc.bk_session:yes')] });
  w.eval('_fazInitOperations()');
  check('per-service on, service granted: the flagged script replays once', w.__bk === 1);
  check('per-service on, service granted: the public map reports it true', w.getFazConsent().services.bk_session === true);
}

// ---------------------------------------------------------------------------
// Per-service consent ON: the category was already accepted and only the owning
// service flips no → yes in the preference centre. The accept transition alone
// (category-based) would never run the script; the service flip must.
// ---------------------------------------------------------------------------
{
  const w = page({ perService: true, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes,svc.bk_session:no')] });
  w.eval('_fazInitOperations()');
  check('service flip: nothing replays while the service is denied', w.__bk === undefined);
  // The bootstrap replayed the owner-less flagged marketing script once; the
  // analytics bare string is unflagged and did not run.
  const adsAfterBoot = w.__ads;
  // What _fazAcceptCookies() does before mutating the store: snapshot the
  // effective per-service consent, then the visitor's new choice lands.
  w.eval('_fazServicesBeforeConsent = _fazGetServiceConsentSnapshot(); fazcookie._fazSetInStore("svc.bk_session", "yes");');
  w.eval('_fazExecuteConsentScripts(["functional", "analytics", "marketing"])');
  check('service flip no → yes under an already-accepted category runs the owner\'s opt-in script once', w.__bk === 1);
  check('service flip: owner-less and bare-string entries in already-accepted categories do NOT run (no category transition)', w.__ads === adsAfterBoot && w.__ga === undefined);
  // The same action again with the service already yes before: no re-run.
  w.eval('_fazServicesBeforeConsent = _fazGetServiceConsentSnapshot();');
  w.eval('_fazExecuteConsentScripts(["functional", "analytics", "marketing"])');
  check('service already yes before the action: the script does not run again', w.__bk === 1);
}

// ---------------------------------------------------------------------------
// Per-service consent OFF with a stale svc.<id>:no token left over from when it
// was on: the category decides, like the server, so the script replays, the
// accept transition runs it, and the public map reports the category consent.
// ---------------------------------------------------------------------------
{
  const w = page({ perService: false, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes,svc.bk_session:no')] });
  // The token is also forced into the in-memory store: whichever way a stale
  // svc.* value reaches the runtime, the gate must not read it while the
  // option is off.
  w.eval('fazcookie._fazSetInStore("svc.bk_session", "no"); _fazInitOperations()');
  check('per-service off, stale svc:no: the flagged script still replays (category granted)', w.__bk === 1);
  check('per-service off, stale svc:no: getFazConsent().services follows the category, not the token', w.getFazConsent().services.bk_session === true);
  check('per-service off, stale svc:no: getFazCookieConsent() agrees with getFazConsent().services', w.getFazCookieConsent('bk_session') === true);
  w.eval('_fazExecuteConsentScripts([])');
  check('per-service off, stale svc:no: the accept transition runs it too', w.__bk === 2);
}

// ---------------------------------------------------------------------------
// Replay on the other bootstrap branches: never in preview, yes under GPC for
// a category GPC leaves granted.
// ---------------------------------------------------------------------------
{
  const w = page({ preview: true, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes')] });
  w.eval('_fazInitOperations()');
  check('preview (?faz_preview=true): the flagged script is not replayed', w.__bk === undefined && w.__ads === undefined);
}
{
  const w = page({ gpc: true, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes')] });
  w.eval('_fazInitOperations()');
  check('GPC with a stored action: the flagged script replays for a category GPC leaves granted', w.__bk === 1);
}

// ---------------------------------------------------------------------------
// #365 review: a record a signal wrote (GPC, Do Not Sell) that the visitor never
// answered carries `undecided:1`. It holds jurisdiction defaults, not a choice,
// so nothing replays — with the signal still on, or gone.
// ---------------------------------------------------------------------------
{
  const w = page({ gpc: true, cookies: [record('consentid:abc,consent:yes,action:yes,undecided:1,necessary:yes,functional:yes,analytics:yes,marketing:no')] });
  w.eval('_fazInitOperations()');
  check('undecided record, GPC on: the flagged script is NOT replayed (no one answered)', w.__bk === undefined && w.__ads === undefined);
}
{
  const w = page({ cookies: [record('consentid:abc,consent:yes,action:yes,undecided:1,necessary:yes,functional:yes,analytics:yes,marketing:yes')] });
  w.eval('_fazInitOperations()');
  check('undecided record, signal gone: the flagged script is NOT replayed either', w.__bk === undefined && w.__ads === undefined);
}

// ---------------------------------------------------------------------------
// #365 review: revoking only the owning service, with its category still
// granted, is a rejection for that script — its opt-out cleanup runs once.
// ---------------------------------------------------------------------------
{
  const w = page({ perService: true, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes,svc.bk_session:yes')] });
  w.eval('_fazInitOperations()');
  w.eval('_fazServicesBeforeConsent = _fazGetServiceConsentSnapshot(); fazcookie._fazSetInStore("svc.bk_session", "no");');
  w.eval('_fazExecuteConsentScripts(["functional", "analytics", "marketing"])');
  check('service revoked under a granted category: the owner\'s opt-out script runs once', w.__bkOut === 1);
  w.eval('_fazServicesBeforeConsent = _fazGetServiceConsentSnapshot();');
  w.eval('_fazExecuteConsentScripts(["functional", "analytics", "marketing"])');
  check('service already denied before the action: the opt-out does not run again', w.__bkOut === 1);
}
{
  const w = page({ perService: true, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes,svc.bk_session:yes')] });
  w.eval('_fazInitOperations()');
  w.eval('_fazServicesBeforeConsent = _fazGetServiceConsentSnapshot();');
  w.eval('_fazExecuteConsentScripts(["functional", "analytics", "marketing"])');
  check('service still granted: no opt-out runs', w.__bkOut === undefined);
}
{
  const w = page({ perService: false, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes')] });
  w.eval('_fazInitOperations()');
  w.eval('_fazServicesBeforeConsent = _fazGetServiceConsentSnapshot(); fazcookie._fazSetInStore("svc.bk_session", "no");');
  w.eval('_fazExecuteConsentScripts(["functional", "analytics", "marketing"])');
  check('per-service off: a svc token never triggers an opt-out (the category decides)', w.__bkOut === undefined);
}

// ---------------------------------------------------------------------------
// No declared cookies and per-service off: the map is empty, as documented.
// ---------------------------------------------------------------------------
{
  const w = page({ declared: false, cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes')] });
  w.eval('_fazInitOperations()');
  check('no declared cookies, per-service off: services is an empty map', Object.keys(w.getFazConsent().services).length === 0);
}

// ---------------------------------------------------------------------------
// A scripts map cached before the key existed must not throw.
// ---------------------------------------------------------------------------
{
  const w = page({ cookies: [record('consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes')] });
  delete w._fazConfig._cookieScripts.functional.opt_in_persistent;
  let threw = false;
  try { w.eval('_fazInitOperations()'); } catch (e) { threw = true; }
  check('a legacy _cookieScripts entry without opt_in_persistent is tolerated', !threw && w.__bk === undefined && w.__ads === 1);
}

console.log(`\ndeclared-cookie-services: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
