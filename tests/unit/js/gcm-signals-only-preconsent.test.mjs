/**
 * JS unit test (jsdom) — with Google Consent Mode switched off and a built-in
 * Google tag configured, the Consent Mode state follows the banner's own
 * pre-consent state.
 *
 * In that setup gcm.js is loaded only so the plugin's own tag never runs
 * without consent signals. It pushed an all-denied `consent default` and sent a
 * `consent update` only from the consent cookie. Under an opt-out banner
 * (CCPA) the runtime seeds every category to "yes" in memory, writes NO cookie
 * (ePrivacy: no identifier before an action) and releases the parked tag — so
 * a visitor who never touched the notice kept GA4/Ads denied for the whole
 * visit, although notice-only is exactly the state an opt-out law allows.
 *
 * Pinned here:
 *   1. signals-only + opt-out first visit (`fazcookie_consent_update`, action
 *      'init', every category accepted, no cookie) -> ONE `consent update`,
 *      granted.
 *   2. The same event under an opt-in banner (everything rejected) -> denied.
 *   3. A pre-ticked opt-out (marketing rejected in the init detail) keeps the
 *      three ad signals denied while analytics is granted.
 *   4. A valid cookie always wins over the init detail (GPC / Do Not Sell
 *      write one before the event).
 *   5. GCM switched ON is untouched: the admin's region defaults stand and a
 *      first visit sends no update.
 *   6. A late-loaded gcm.js reads the state the runtime already announced
 *      (window._fazConsentReady).
 *   7. The frontend tells gcm.js which mode it is in.
 *
 * Run: node tests/unit/js/gcm-signals-only-preconsent.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const GCM = readFileSync(resolve(HERE, '../../../frontend/js/gcm.js'), 'utf8');
const FRONTEND = readFileSync(resolve(HERE, '../../../frontend/class-frontend.php'), 'utf8');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    console.log(`       expected: ${JSON.stringify(expected)}  actual: ${JSON.stringify(actual)}`);
  }
}

const ALL = ['necessary', 'functional', 'analytics', 'performance', 'marketing'];

function run({ signalsOnly = true, cookie = '', detail = null, readyBeforeLoad = null, events = ['update'] } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  if (cookie) window.document.cookie = `fazcookie-consent=${encodeURIComponent(cookie)}`;
  window.dataLayer = [];
  window._fazConfig = { _consentRevision: '1' };
  window._fazGcm = {
    status: !signalsOnly,
    signals_only: signalsOnly,
    advanced_mode: false,
    default_settings: [{
      regions: 'All',
      necessary: 'granted',
      functional: 'denied',
      analytics: 'denied',
      marketing: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
    }],
  };
  if (readyBeforeLoad) window._fazConsentReady = readyBeforeLoad;
  window.eval(GCM);
  if (detail) {
    for (const name of events) {
      window.document.dispatchEvent(new window.CustomEvent(`fazcookie_consent_${name}`, { detail }));
    }
  }
  const calls = window.dataLayer.map((a) => Array.prototype.slice.call(a));
  const updates = calls.filter((c) => c[0] === 'consent' && c[1] === 'update').map((c) => c[2]);
  const defaults = calls.filter((c) => c[0] === 'consent' && c[1] === 'default').map((c) => c[2]);
  return { updates, defaults, last: updates[updates.length - 1] };
}

console.log('gcm.js: signals-only mode follows the banner pre-consent state');

// 1. Opt-out banner, first visit, nobody touched the notice.
{
  // The runtime fires the change event and then the ready event on load.
  const r = run({ detail: { accepted: ALL, rejected: [], action: 'init' }, events: ['update', 'ready'] });
  eq('one denied consent default is still pushed first', r.defaults.length === 1 && r.defaults[0].ad_storage, 'denied');
  eq('opt-out first visit: exactly one consent update', r.updates.length, 1);
  eq('opt-out first visit: analytics_storage granted', r.last && r.last.analytics_storage, 'granted');
  eq('opt-out first visit: ad_storage granted', r.last && r.last.ad_storage, 'granted');
  eq('opt-out first visit: ad_user_data granted', r.last && r.last.ad_user_data, 'granted');
  eq('opt-out first visit: ad_personalization granted', r.last && r.last.ad_personalization, 'granted');
  eq('opt-out first visit: functionality_storage granted', r.last && r.last.functionality_storage, 'granted');
}

// 2. Opt-in banner: the same event carries everything rejected.
{
  const r = run({ detail: { accepted: ['necessary'], rejected: ALL.slice(1), action: 'init' } });
  const anyGranted = r.updates.some((u) => ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization', 'functionality_storage', 'personalization_storage'].some((k) => u[k] === 'granted'));
  eq('opt-in first visit: nothing is granted', anyGranted, false);
}

// 3. Opt-out banner with the "do not sell" box pre-ticked: marketing is "no".
{
  const r = run({ detail: { accepted: ['necessary', 'functional', 'analytics'], rejected: ['marketing'], action: 'init' } });
  eq('pre-ticked opt-out: analytics_storage granted', r.last && r.last.analytics_storage, 'granted');
  eq('pre-ticked opt-out: ad_storage denied', r.last && r.last.ad_storage, 'denied');
  eq('pre-ticked opt-out: ad_user_data denied', r.last && r.last.ad_user_data, 'denied');
  eq('pre-ticked opt-out: ad_personalization denied', r.last && r.last.ad_personalization, 'denied');
}

// 4. A stored record wins over the event detail (GPC, Do Not Sell and every
//    banner action write the cookie before they fire the event).
{
  const r = run({
    cookie: 'consentid:x,consent:no,action:yes,gpc:1,necessary:yes,functional:yes,analytics:yes,marketing:no',
    detail: { accepted: ALL, rejected: [], action: 'init' },
  });
  eq('cookie wins: ad_storage denied', r.last && r.last.ad_storage, 'denied');
  eq('cookie wins: ad_user_data denied', r.last && r.last.ad_user_data, 'denied');
  eq('cookie wins: analytics_storage granted', r.last && r.last.analytics_storage, 'granted');
}

// 5. A live change that leaves no cookie is not a pre-consent state.
{
  const r = run({ detail: { accepted: ALL, rejected: [], action: 'custom' } });
  eq('non-init event without a cookie: no update', r.updates.length, 0);
}

// 6. GCM switched on: the configured region defaults stand on a first visit.
{
  const r = run({ signalsOnly: false, detail: { accepted: ALL, rejected: [], action: 'init' }, events: ['update', 'ready'] });
  eq('GCM on: a first visit sends no consent update', r.updates.length, 0);
}

// 7. gcm.js arriving after the runtime announced the state.
{
  const r = run({ readyBeforeLoad: { accepted: ALL, rejected: [], action: 'init' } });
  eq('late load: the announced pre-consent state is applied once', r.updates.length, 1);
  eq('late load: ad_storage granted', r.last && r.last.ad_storage, 'granted');
  const stored = run({ readyBeforeLoad: { accepted: ALL, rejected: [], action: 'restore' } });
  eq('late load: a restore without a readable cookie grants nothing', stored.updates.length, 0);
}

// 8. The frontend tells gcm.js it is in signals-only mode.
eq(
  'class-frontend.php: the payload carries signals_only from Gcm_Settings::sends_signals_only()',
  /\$gcm\['signals_only'\]\s*=\s*\$this->gcm_settings->sends_signals_only\(\);/.test(FRONTEND),
  true
);

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
