/**
 * Cache-safe jurisdiction bootstrap — client ordering and fail-closed contract.
 *
 * Loads the real frontend/js/script.js in jsdom. The heavyweight banner
 * decorators are replaced only after evaluation so these checks isolate the
 * security boundary: _fazInitOperations() (which mounts the UI and performs
 * the first unblock pass) may not run until the live no-store fetch settles.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');
const SOURCE = readFileSync(SCRIPT_PATH, 'utf8');
const INSTRUMENTED_SOURCE = SOURCE.replace(
  '_fazDomReady(async function () {',
  `window.__fazTestInit = _fazInit;
window.__fazTestShouldBlockResource = _fazShouldBlockResource;
// Captured BEFORE __fazTestIsolateInit() swaps the binding for a no-op, so the
// watchdog-delay checks can exercise the real scheduler.
window.__fazTestRealWatchdog = _fazScheduleBannerWatchdog;
window.__fazTestIsolateInit = function () {
  _fazScheduleBannerWatchdog = function () {};
  _fazRunDeadCookieCleanup = function () {};
  _fazWatchBannerElement = function () {};
  _fazScheduleAdblockGuard = function () {};
  _fazScheduleDeadCookieCleanup = function () {};
  _fazInitOperations = function () {
    window.__fazInitOperationsCalls.push({
      law: _fazStore._activeLaw,
      slug: _fazStore._bannerSlug,
      marketingDefault: _fazStore._categories[1].defaultConsent.ccpa
    });
  };
};
_fazDomReady(async function () {`,
);
if (INSTRUMENTED_SOURCE === SOURCE) {
  throw new Error('Could not instrument the real _fazInit boundary.');
}

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

function strictConfig() {
  return {
    _block: '1',
    _geoBootstrap: true,
    _geoBootstrapTimeout: 500,
    _bannerEndpoint: 'https://example.test/wp-json/faz/v1/banner/',
    _language: 'en',
    _defaultLanguage: 'en',
    _availableLanguages: ['en'],
    _languageMap: {},
    _browserDetect: false,
    _activeLaw: 'gdpr',
    _bannerSlug: 'strict-gdpr',
    _runtimeGeo: true,
    _geoRouting: true,
    _scopeFingerprint: 'strict-fingerprint',
    _expiry: 180,
    _bannerConfig: { settings: { applicableLaw: 'gdpr' }, config: {} },
    _tags: [],
    _shortCodes: [],
    _categories: [
      {
        slug: 'necessary',
        isNecessary: true,
        defaultConsent: { gdpr: true, ccpa: true },
        defaultFromRuleset: true,
      },
      {
        slug: 'marketing',
        isNecessary: false,
        defaultConsent: { gdpr: false, ccpa: false },
        defaultFromRuleset: true,
      },
    ],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    _serviceCatalogue: {},
    _perServiceConsent: false,
    _perCookieConsent: false,
    _consentRevision: 1,
    _i18n: {},
  };
}

function liveCcpaPayload() {
  return {
    language: 'en',
    bannerSlug: 'live-ccpa',
    activeLaw: 'ccpa',
    bannerConfig: { settings: { applicableLaw: 'ccpa' }, config: {} },
    tags: [{ tag: 'accept-button', styles: { color: '#fff' } }],
    scopeFingerprint: 'live-fingerprint',
    consentExpiry: 365,
    runtimeGeo: true,
    html: '<div id="faz-consent" data-faz-tag="notice">Live CCPA</div>',
    styles: '#faz-consent{color:rgb(1,2,3)}',
    shortCodes: [],
    categories: [
      {
        slug: 'necessary',
        isNecessary: true,
        defaultConsent: { gdpr: true, ccpa: true },
        defaultFromRuleset: true,
      },
      {
        slug: 'marketing',
        isNecessary: false,
        defaultConsent: { gdpr: true, ccpa: true },
        defaultFromRuleset: true,
      },
    ],
    i18n: { privacy_region_label: 'Your privacy choices' },
  };
}

function loadFrontend(fetchImpl, consentCookie = '') {
  const dom = new JSDOM(
    '<!doctype html><html><head></head><body><script id="fazBannerTemplate" type="text/template">Strict GDPR</script></body></html>',
    { runScripts: 'outside-only', url: 'https://example.test/' },
  );
  const { window } = dom;
  window._fazConfig = strictConfig();
  window.fetch = fetchImpl;
  if (consentCookie) {
    window.document.cookie = `fazcookie-consent=${encodeURIComponent(consentCookie)}; path=/`;
  }

  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(INSTRUMENTED_SOURCE);
  window.document.addEventListener = realAdd;

  // Keep _fazInit() real; replace only unrelated work after script evaluation.
  window.__fazInitOperationsCalls = [];
  window.__fazTestIsolateInit();
  return window;
}

const RETURNING_CCPA_CONSENT = [
  'consentid:returning-ccpa',
  'consent:yes',
  'action:yes',
  '__scope.banner:live-ccpa',
  '__scope.law:ccpa',
  '__scope.fp:live-fingerprint',
  'necessary:yes',
  'marketing:yes',
  'rev:1',
].join(',');

// Same visitor, but scoped to the strict shell itself — used by the
// endpoint-less case, where the shell IS the final answer and the stored grant
// is therefore in scope and must survive.
const RETURNING_GDPR_CONSENT = [
  'consentid:returning-gdpr',
  'consent:yes',
  'action:yes',
  '__scope.banner:strict-gdpr',
  '__scope.law:gdpr',
  '__scope.fp:strict-fingerprint',
  'necessary:yes',
  'marketing:yes',
  'rev:1',
].join(',');

console.log('cache-safe jurisdiction bootstrap ordering (jsdom)');

// A pending jurisdiction request must hold back mount + first unblock. Once it
// resolves, every banner-scoped field is replaced before operations begin.
{
  let release;
  let fetchArgs;
  const window = loadFrontend((url, options) => {
    fetchArgs = { url, options };
    return new Promise((resolveResponse) => {
      release = () => resolveResponse({ ok: true, json: async () => liveCcpaPayload() });
    });
  });

  const init = window.__fazTestInit();
  await Promise.resolve();
  check('mount/unblock operations are held while jurisdiction is unresolved', window.__fazInitOperationsCalls.length === 0);
  check('the jurisdiction fetch explicitly bypasses browser HTTP caches', fetchArgs.options.cache === 'no-store');
  check('the request targets the public banner endpoint', fetchArgs.url === 'https://example.test/wp-json/faz/v1/banner/en?banner=strict-gdpr');

  release();
  await init;
  const applied = window.__fazInitOperationsCalls[0];
  check('operations run exactly once after the live decision', window.__fazInitOperationsCalls.length === 1);
  check('operations observe the live CCPA law/banner, never the strict placeholder', applied.law === 'ccpa' && applied.slug === 'live-ccpa');
  check('live jurisdiction category defaults are installed before operations', applied.marketingDefault === true);
  check('the live scope fingerprint and expiry replace the shell values', window._fazConfig._scopeFingerprint === 'live-fingerprint' && window._fazConfig._expiry === 365);
  check('the banner template is atomically replaced before render', window.document.getElementById('fazBannerTemplate').textContent.includes('Live CCPA'));
  check('the endpoint stylesheet is installed as a non-optimizable override', window.document.getElementById('faz-jurisdiction-bootstrap-styles')?.textContent.includes('rgb(1,2,3)'));
  check('diagnostics report a successful live bootstrap', window.fazcookie._diag().geoBootstrapResolved === 'live');
}

// A returning CCPA visitor's stored consent belongs to the live payload, not
// the strict cache placeholder. Preserve it across bootstrap, while treating
// its grants as untrusted until the live scope has actually been reconciled.
{
  let release;
  const window = loadFrontend(() => new Promise((resolveResponse) => {
    release = () => resolveResponse({ ok: true, json: async () => liveCcpaPayload() });
  }), RETURNING_CCPA_CONSENT);

  check('returning CCPA consent is not invalidated against the strict placeholder', window.fazcookie._fazGetFromStore('action') === 'yes');
  check('a stored grant remains blocked while jurisdiction is pending', window.__fazTestShouldBlockResource('marketing', '', '') === true);
  const init = window.__fazTestInit();
  await Promise.resolve();
  check('pending bootstrap still has not released the returning visitor grant', window.__fazTestShouldBlockResource('marketing', '', '') === true);
  release();
  await init;
  check('matching live CCPA scope preserves the existing consent', window.fazcookie._fazGetFromStore('action') === 'yes' && window.fazcookie._fazGetFromStore('marketing') === 'yes');
  check('the preserved grant becomes usable only after live reconciliation', window.__fazTestShouldBlockResource('marketing', '', '') === false);
  check('matching scope leaves the consent cookie intact', window.document.cookie.includes('fazcookie-consent='));
}

// Network failure is one-way safe: the bounded bootstrap records fallback and
// continues with the original GDPR shell. It must never synthesize CCPA state,
// and a stored consent for the relaxed scope must not survive that fallback.
{
  const window = loadFrontend(async () => {
    throw new Error('simulated network failure');
  }, RETURNING_CCPA_CONSENT);
  await window.__fazTestInit();
  const applied = window.__fazInitOperationsCalls[0];
  check('a failed bootstrap still proceeds exactly once with a usable shell', window.__fazInitOperationsCalls.length === 1);
  check('network failure keeps the strict GDPR law and banner', applied.law === 'gdpr' && applied.slug === 'strict-gdpr');
  check('network failure keeps optional marketing denied', applied.marketingDefault === false);
  check('network failure invalidates a stale CCPA action before operations', window.fazcookie._fazGetFromStore('action') === '');
  check('network failure removes the stale scoped consent cookie', !window.document.cookie.includes('fazcookie-consent='));
  check('diagnostics make the strict fallback explicit', window.fazcookie._diag().geoBootstrapResolved === 'strict-fallback');
  check('a failed request never installs live override CSS', !window.document.getElementById('faz-jurisdiction-bootstrap-styles'));
}

// HTTP success alone is not a jurisdiction decision. A partial payload must
// take the same strict fallback as a transport failure, without applying any
// of its relaxed fields.
{
  const window = loadFrontend(async () => ({
    ok: true,
    json: async () => ({ language: 'en', bannerSlug: 'live-ccpa', activeLaw: 'ccpa' }),
  }), RETURNING_CCPA_CONSENT);
  await window.__fazTestInit();
  const applied = window.__fazInitOperationsCalls[0];
  check('a partial 200 payload keeps the strict GDPR shell', applied.law === 'gdpr' && applied.slug === 'strict-gdpr');
  check('a partial 200 payload keeps optional marketing denied', applied.marketingDefault === false);
  check('a partial 200 payload invalidates stale out-of-scope consent', window.fazcookie._fazGetFromStore('action') === '');
  check('a partial 200 payload removes the stale consent cookie', !window.document.cookie.includes('fazcookie-consent='));
  check('a partial 200 payload records strict fallback', window.fazcookie._diag().geoBootstrapResolved === 'strict-fallback');
  check('a partial 200 payload never installs live override CSS', !window.document.getElementById('faz-jurisdiction-bootstrap-styles'));
}

// A bootstrap page with NO banner endpoint (a "disable REST API" plugin
// filtering rest_url() to empty, or a faz_store_data filter dropping the key)
// used to return from _fazBootstrapJurisdiction() without recording a verdict.
// Since the pending gate only ever clears through that function, it stayed
// pending for the life of the page and every non-necessary category stayed
// blocked — consent recorded, nothing ever unblocked. The early return must
// resolve to the same defined fail-closed state as a network failure.
{
  let fetchCalls = 0;
  const window = loadFrontend(async () => {
    fetchCalls += 1;
    throw new Error('the endpoint-less path must never reach the network');
  }, RETURNING_GDPR_CONSENT);
  window._fazConfig._bannerEndpoint = '';
  check('a missing endpoint leaves the gate pending before init', window.__fazTestShouldBlockResource('marketing', '', '') === true);
  await window.__fazTestInit();
  const applied = window.__fazInitOperationsCalls[0];
  check('a missing endpoint never issues a request', fetchCalls === 0);
  check('a missing endpoint still runs operations exactly once', window.__fazInitOperationsCalls.length === 1);
  check('a missing endpoint keeps the strict GDPR shell', applied.law === 'gdpr' && applied.slug === 'strict-gdpr');
  check('a missing endpoint resolves the gate to the fail-closed state', window.fazcookie._diag().geoBootstrapResolved === 'strict-fallback');
  check('an in-scope grant is honoured once the gate resolves', window.__fazTestShouldBlockResource('marketing', '', '') === false);
  check('an in-scope consent cookie survives the endpoint-less fallback', window.document.cookie.includes('fazcookie-consent='));
}

// The fail-open watchdog is armed BEFORE the bootstrap await it protects, so
// its delay has to outlast that await. The bootstrap budget is publisher-tunable
// to 5000ms (faz_geo_bootstrap_timeout_ms); a hardcoded 2500ms net fired while
// the await was still pending and left _fazInitOperations() unguarded.
{
  const window = loadFrontend(async () => ({ ok: true, json: async () => liveCcpaPayload() }));
  const delays = [];
  window.setTimeout = (fn, ms) => {
    delays.push(ms);
    return 0;
  };

  window._fazConfig._geoBootstrap = true;
  window._fazConfig._geoBootstrapTimeout = 5000;
  window.__fazTestRealWatchdog();
  check('a 5000ms bootstrap budget pushes the watchdog past it', delays[0] === 6000);

  window._fazConfig._geoBootstrapTimeout = 3000;
  window.__fazTestRealWatchdog();
  check('the watchdog tracks an intermediate raised budget', delays[1] === 4000);

  window._fazConfig._geoBootstrapTimeout = 500;
  window.__fazTestRealWatchdog();
  check('a short budget never shortens the watchdog below its 2500ms floor', delays[2] === 2500);

  window._fazConfig._geoBootstrapTimeout = 'not-a-number';
  window.__fazTestRealWatchdog();
  check('an unparseable budget falls back to the same 1500ms default the bootstrap uses', delays[3] === 2500);

  window._fazConfig._geoBootstrap = false;
  window._fazConfig._geoBootstrapTimeout = 5000;
  window.__fazTestRealWatchdog();
  check('non-bootstrap pages keep the original 2500ms delay exactly', delays[4] === 2500);
}

console.log(`\n  geo-bootstrap-order: ${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);
