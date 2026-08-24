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

console.log(`\n  geo-bootstrap-order: ${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);
