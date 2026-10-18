/**
 * JS unit test (jsdom) — the WP Consent API verdict follows the banner the
 * REST payload installs.
 *
 * The page ships `_fazConfig._wpConsentType` (the server's
 * filter_wp_consent_type() verdict) and wca.js declares it, the stricter of
 * verdict and banner label winning. A cached strict shell (the jurisdiction
 * bootstrap) carries `optin`; when the live no-store payload resolves a CCPA
 * banner, _fazApplyBannerPayload() replaced the law, the banner and the
 * categories but not the verdict, so wca.js kept declaring opt-in for an
 * opt-out visitor for the rest of the page view. The payload now carries
 * `wpConsentType` and the store takes it; wca.js re-applies when only the
 * verdict changed.
 *
 * Both halves load the real shipped files.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
const WCA = readFileSync(resolve(HERE, '../../../frontend/js/wca.js'), 'utf8');

const INSTRUMENTED = SCRIPT.replace(
  '_fazDomReady(async function () {',
  'window.__fazApplyBannerPayload = _fazApplyBannerPayload;\n_fazDomReady(async function () {',
);
if (INSTRUMENTED === SCRIPT) {
  throw new Error('Could not expose _fazApplyBannerPayload.');
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

function shellConfig() {
  return {
    _block: '1',
    _language: 'en',
    _defaultLanguage: 'en',
    _availableLanguages: ['en'],
    _languageMap: {},
    _browserDetect: false,
    _activeLaw: 'gdpr',
    _bannerSlug: 'strict-gdpr',
    _wpConsentType: 'optin',
    _expiry: 180,
    _bannerConfig: { settings: { applicableLaw: 'gdpr' }, config: {} },
    _tags: [],
    _shortCodes: [],
    _categories: [
      { slug: 'necessary', isNecessary: true, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'marketing', isNecessary: false, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    _serviceCatalogue: {},
    _consentRevision: 1,
    _i18n: {},
  };
}

function loadScript() {
  const dom = new JSDOM(
    '<!doctype html><html><head></head><body><script id="fazBannerTemplate" type="text/template">Strict</script></body></html>',
    { runScripts: 'outside-only', url: 'https://example.test/' },
  );
  const { window } = dom;
  window._fazConfig = shellConfig();
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  window.eval(INSTRUMENTED);
  window.document.addEventListener = realAdd;
  return window;
}

console.log('WP Consent API verdict from the banner REST payload (jsdom)');

{
  const w = loadScript();
  w.__fazApplyBannerPayload({ activeLaw: 'ccpa', bannerSlug: 'live-ccpa', wpConsentType: 'optout' });
  check('the live payload replaces the shell verdict in the store wca.js reads', w._fazConfig._wpConsentType === 'optout');
  w.__fazApplyBannerPayload({ activeLaw: 'ccpa', wpConsentType: 'granted' });
  check('a value other than optin/optout is ignored', w._fazConfig._wpConsentType === 'optout');
  w.__fazApplyBannerPayload({ activeLaw: 'gdpr' });
  check('a payload without the field (older server) leaves the verdict as it is', w._fazConfig._wpConsentType === 'optout');
}

{
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: 'https://example.test/' });
  const { window } = dom;
  const consent = { activeLaw: 'ccpa', isUserActionCompleted: false, categories: { necessary: true, marketing: true } };
  window.getFazConsent = () => consent;
  window.wp_set_consent = () => {};
  window._fazGsk = false;
  window._fazConfig = { _wpConsentType: 'optin' };
  window.eval(`(function(){${WCA}})()`);
  const fire = () => window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_ready', { detail: { accepted: ['necessary', 'marketing'] } }));
  fire();
  check('shell verdict optin on a ccpa label: declared optin', window.wp_consent_type === 'optin');
  window._fazConfig._wpConsentType = 'optout';
  fire();
  check('same law and categories, new verdict: wca.js re-declares (optout)', window.wp_consent_type === 'optout');
}

console.log(`wp-consent-type-rest-payload: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
