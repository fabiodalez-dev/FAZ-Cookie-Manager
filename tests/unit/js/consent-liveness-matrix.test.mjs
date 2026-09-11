/**
 * Consent liveness matrix: a consent the visitor gave stays alive under every
 * combination of options, and dies only where a binding opt-out requires it.
 *
 * Why a matrix. The GPC embed bug was not a wrong rule; it was two correct
 * rules colliding. "An embed click grants that service" and "GPC removes
 * sale/share grants" each did what it said, and together they wrote a consent
 * and erased it in the same save. Every existing test exercised one rule at a
 * time, so none could see it. The only way to catch that class of defect is to
 * cross the options and compare each cell against a single statement of what
 * must happen.
 *
 * The oracle (expected()) is that statement. It is deliberately short, so that
 * when a cell disagrees it is obvious whether the product or the rule is wrong:
 *
 *   A consent the visitor gives stays alive across reloads and later actions,
 *   unless it falls in a sale/share category AND a binding opt-out is active:
 *     - a Do Not Sell request (DNSMPI) always binds;
 *     - GPC binds, except for a service the visitor explicitly accepted on
 *       its own blocked embed (per-service consent on).
 *   Nothing the visitor did NOT accept is ever granted as a side effect.
 *
 * Dimensions: law (gdpr/ccpa) × GPC × per-service consent × DNSMPI × whether
 * the embed's category is flagged sale/share = 32 configurations, each run
 * through three scenarios.
 *
 * Every scenario is observed TWICE: immediately after the action, and after a
 * reload. The first version checked only after the reload, and a mutation that
 * made a Do Not Sell request non-binding stayed green — the reload re-applies
 * the opt-out and silently repairs a violation committed at save time. But the
 * page the visitor is looking at is the one before the reload, and that is
 * where a tracker would fire. A reload that heals an error hides it from any
 * test that only looks afterwards.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');

let passed = 0;
let failed = 0;
const failures = [];
function check(label, condition) {
  if (condition) { passed += 1; return; }
  failed += 1; failures.push(label);
}

function load(cfg, cookie = '') {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only', url: 'https://matrix.example.test/',
  });
  const { window } = dom;
  Object.defineProperty(window.navigator, 'globalPrivacyControl', { configurable: true, value: cfg.gpc });
  if (cookie) window.document.cookie = `fazcookie-consent=${cookie}; path=/`;
  if (cfg.dnsmpi) window.document.cookie = 'fazcookie-dnsmpi=1; path=/';
  window._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true, ccpaDoNotSell: false, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'functional', isNecessary: false, ccpaDoNotSell: cfg.saleShare, defaultConsent: { gdpr: false, ccpa: true } },
      { slug: 'marketing', isNecessary: false, ccpaDoNotSell: true, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    _services: [
      { id: 'google-maps', category: 'functional' },
      { id: 'ads', category: 'marketing' },
    ],
    _providersToBlock: [], _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: cfg.perService, _perCookieConsent: false, _rootDomain: '',
    _bannerConfig: {
      settings: { applicableLaw: cfg.law },
      behaviours: { respectGPC: false },
      config: { revisitConsent: { status: false } },
    },
    _shortCodes: [],
    i18n: {},
  };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest);
  window.eval(SCRIPT);
  window.document.addEventListener = realAdd;
  return window;
}

// The consent-affecting half of _fazInitOperations, in its order.
function initFresh(w, cfg) {
  if (cfg.dnsmpi) w.eval('_fazSeedInitialState(); _fazApplyDnsmpiOptOut()');
  if (cfg.gpc) w.eval('_fazSeedInitialState(); _fazApplyGpcOptOut()');
  if (!cfg.dnsmpi && !cfg.gpc) w.eval('_fazSeedInitialState()');
}
function reinit(w, cfg) {
  if (cfg.dnsmpi) w.eval('_fazApplyDnsmpiOptOut()');
  if (cfg.gpc) w.eval('_fazApplyGpcOptOut()');
}
function reload(w, cfg) {
  const part = w.document.cookie.split('; ').find((c) => c.startsWith('fazcookie-consent='));
  const w2 = load(cfg, part ? part.substring('fazcookie-consent='.length) : '');
  reinit(w2, cfg);
  return w2;
}
const get = (w, k) => w.fazcookie._fazConsentStore.get(k);

function embedClick(w, cfg) {
  if (cfg.perService) w.eval("window._fazAcceptService('google-maps', 'functional')");
  else w.eval("window._fazAcceptCategory('functional')");
}
// "Alive" means the embed actually loads, and it is asked of the product's own
// blocking decision rather than inferred from a store key. An earlier version
// checked svc.google-maps === 'yes' and reported five false failures: after an
// Accept All the service override is cleared and the map loads through the
// granted category, which is correct and visible, just not via that key.
const MAPS = 'https://www.google.com/maps/embed?pb=1';
function embedAlive(w) {
  return w.eval(`!_fazShouldBlockResource('functional', '${MAPS}', 'google-maps')`) === true;
}

// ------------------------------------------------------------------ oracle --
function expected(cfg, scenario) {
  const binding = cfg.gpc || cfg.dnsmpi;
  if (scenario === 'acceptAll') {
    return { embed: !(cfg.saleShare && binding), marketing: !binding };
  }
  // embed click, then (optionally) Accept All
  const embed = cfg.perService
    ? !(cfg.saleShare && cfg.dnsmpi)           // GPC is excepted for the clicked service
    : !(cfg.saleShare && binding);             // a category grant stays bound
  return { embed, marketing: scenario === 'embedThenAll' ? !binding : false };
}

const LAWS = ['gdpr', 'ccpa'];
const BOOL = [false, true];
let configs = 0;

for (const law of LAWS) for (const gpc of BOOL) for (const perService of BOOL)
for (const dnsmpi of BOOL) for (const saleShare of BOOL) {
  const cfg = { law, gpc, perService, dnsmpi, saleShare };
  const tag = `law=${law} gpc=${+gpc} perService=${+perService} dnsmpi=${+dnsmpi} functionalSaleShare=${+saleShare}`;
  configs += 1;

  // Scenario 1 — Accept All on the banner, then reload.
  {
    const w = load(cfg); initFresh(w, cfg);
    w.eval("_fazAcceptCookies('all', true)");
    const exp = expected(cfg, 'acceptAll');
    check(`[acceptAll, same page] ${tag} → functional alive=${exp.embed}`, embedAlive(w) === exp.embed);
    check(`[acceptAll, same page] ${tag} → marketing alive=${exp.marketing}`, (get(w, 'marketing') === 'yes') === exp.marketing);
    const w2 = reload(w, cfg);
    const emb = embedAlive(w2);
    check(`[acceptAll] ${tag} → functional alive=${exp.embed}`, emb === exp.embed);
    check(`[acceptAll] ${tag} → marketing alive=${exp.marketing}`, (get(w2, 'marketing') === 'yes') === exp.marketing);
  }

  // Scenario 2 — click Accept on the blocked embed, then reload.
  {
    const w = load(cfg); initFresh(w, cfg);
    // What the click is NOT about, measured before it. Under CCPA marketing is
    // already allowed until the visitor opts out — that is the law's model,
    // not a side effect — so "never granted" would be wrong there. The
    // invariant that holds under both laws is that the click changes nothing
    // it did not ask for.
    const marketingBefore = get(w, 'marketing') === 'yes';
    const adsBefore = get(w, 'svc.ads') === 'yes';
    embedClick(w, cfg);
    const exp = expected(cfg, 'embed');
    check(`[embed, same page] ${tag} → embed alive=${exp.embed}`, embedAlive(w) === exp.embed);
    check(`[embed, same page] ${tag} → no marketing side effect`, !(get(w, 'marketing') === 'yes' && !marketingBefore));
    const w2 = reload(w, cfg);
    check(`[embed] ${tag} → embed alive=${exp.embed}`, embedAlive(w2) === exp.embed);
    check(`[embed] ${tag} → the click does not grant marketing as a side effect`,
      !(get(w2, 'marketing') === 'yes' && !marketingBefore));
    check(`[embed] ${tag} → the click does not grant another service as a side effect`,
      !(get(w2, 'svc.ads') === 'yes' && !adsBefore));
  }

  // Scenario 3 — embed click, then Accept All, then reload: a broader later
  // consent must not revoke what the visitor explicitly opened.
  {
    const w = load(cfg); initFresh(w, cfg);
    embedClick(w, cfg);
    w.eval("_fazAcceptCookies('all', true)");
    const exp = expected(cfg, 'embedThenAll');
    check(`[embed+all, same page] ${tag} → embed alive=${exp.embed}`, embedAlive(w) === exp.embed);
    check(`[embed+all, same page] ${tag} → marketing alive=${exp.marketing}`, (get(w, 'marketing') === 'yes') === exp.marketing);
    const w2 = reload(w, cfg);
    check(`[embed+all] ${tag} → embed alive=${exp.embed}`, embedAlive(w2) === exp.embed);
    check(`[embed+all] ${tag} → marketing alive=${exp.marketing}`, (get(w2, 'marketing') === 'yes') === exp.marketing);
  }
  // Scenario 4 — a service grant that did NOT come from an embed click: given
  // before the browser started sending GPC, or through a service toggle. The
  // exception must not extend to it. This is the half of the rule that says a
  // consent dies, and without it no scenario above could fail on a mutation
  // that excused every service grant instead of only the clicked one.
  //
  // The grant is written into the cookie directly, as an earlier visit would
  // have left it. Setting it on the store and saving does not work: a custom
  // save rebuilds the service keys from the preference-centre toggles, and
  // the first draft of this scenario reported fourteen false failures because
  // its seed never reached the cookie at all.
  if (cfg.perService) {
    const seed = load({ ...cfg, gpc: false, dnsmpi: false });
    seed.eval('_fazSeedInitialState()');
    seed.eval("_fazAcceptCookies('custom', true)");
    const part = seed.document.cookie.split('; ').find((c) => c.startsWith('fazcookie-consent='));
    const prior = part.substring('fazcookie-consent='.length) + encodeURIComponent(',svc.google-maps:yes,svc.ads:yes');
    const w2 = load(cfg, prior);
    reinit(w2, cfg);
    const binding = cfg.gpc || cfg.dnsmpi;
    // Unmarked, so no GPC exception: bound like any other sale/share grant.
    check(`[prior grant] ${tag} → maps alive=${!(cfg.saleShare && binding)}`,
      (get(w2, 'svc.google-maps') === 'yes') === !(cfg.saleShare && binding));
    check(`[prior grant] ${tag} → ads alive=${!binding}`,
      (get(w2, 'svc.ads') === 'yes') === !binding);
  }
}

console.log(`consent liveness matrix (jsdom): ${configs} configurations × 4 scenarios`);
if (failures.length) {
  console.log('\n  cells that disagree with the oracle:');
  failures.forEach((f) => console.log(`  \x1b[31mFAIL\x1b[0m ${f}`));
}
console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
