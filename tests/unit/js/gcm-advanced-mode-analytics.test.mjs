/**
 * JS unit test (jsdom) — in Advanced Consent Mode the analytics signal is the
 * only control, so it must follow the category of the tag that is running.
 *
 * Basic mode and Advanced mode need DIFFERENT answers from the same merge, and
 * that is the whole point of this file:
 *
 *   Basic    — `analytics` tags are hard-blocked by the script gate. Granting
 *              `performance` (an analytics-class category) is a consent to
 *              analytics-class storage, and the signal cannot let anything
 *              through that the gate is holding. Granted-wins is correct.
 *
 *   Advanced — Consent-Mode-aware Google tags are deliberately NOT blocked, so
 *              they can send modeled pings (class-frontend.php,
 *              is_gcm_managed_script). GA4 is classified `analytics`. With
 *              granted-wins, a visitor who DENIED Analytics and granted
 *              Performance had GA4 loaded and told analytics_storage: granted,
 *              and it wrote its cookies against that denial.
 *
 * A single rule cannot serve both, which is why an earlier attempt to make the
 * merge most-restrictive everywhere broke the basic-mode contract pinned by
 * gcm-tcf.spec.ts (`custom-performance-only`) and had to be reverted. This suite
 * asserts both modes so neither can be traded for the other again.
 *
 * Run: node tests/unit/js/gcm-advanced-mode-analytics.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const GCM_PATH = resolve(HERE, '../../../frontend/js/gcm.js');

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

/** Run gcm.js against a real consent cookie and return the last consent update. */
function analyticsSignal(cookie, advanced, updatedCookie = null) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  window.document.cookie = `fazcookie-consent=${encodeURIComponent(cookie)}`;
  // gcm.js defines its own gtag() that pushes onto the data layer, so the data
  // layer — what Google actually consumes — is what gets read back.
  window.dataLayer = [];
  window._fazGcm = {
    status: true,
    advanced_mode: !!advanced,
    consentRevision: 1,
    default_settings: [],
  };
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(readFileSync(GCM_PATH, 'utf8'));

  if (updatedCookie !== null) {
    window.document.cookie = `fazcookie-consent=${encodeURIComponent(updatedCookie)}`;
    window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_update'));
  }

  const updates = window.dataLayer
    .map((a) => Array.prototype.slice.call(a))
    .filter((c) => c[0] === 'consent' && c[1] === 'update');
  return updates.length ? updates[updates.length - 1][2].analytics_storage : null;
}

const DENY_ANALYTICS_ALLOW_PERF =
  'consentid:x,consent:yes,action:yes,necessary:yes,analytics:no,performance:yes,marketing:no';
const ALLOW_ANALYTICS_DENY_PERF =
  'consentid:x,consent:yes,action:yes,necessary:yes,analytics:yes,performance:no,marketing:no';
const ONLY_PERFORMANCE =
  'consentid:x,consent:yes,action:yes,necessary:yes,performance:yes,marketing:no';
const ALLOW_BOTH =
  'consentid:x,consent:yes,action:yes,necessary:yes,analytics:yes,performance:yes,marketing:no';

function run() {
  // --- Basic mode: the existing contract, unchanged -------------------------
  eq(
    'basic: performance alone grants analytics_storage (script gate still blocks the tags)',
    analyticsSignal(DENY_ANALYTICS_ALLOW_PERF, false),
    'granted',
  );
  eq(
    'basic: a site offering only performance still works',
    analyticsSignal(ONLY_PERFORMANCE, false),
    'granted',
  );

  // --- Advanced mode: the analytics category decides ------------------------
  eq(
    'advanced: Analytics DENIED is honoured even when Performance is granted',
    analyticsSignal(DENY_ANALYTICS_ALLOW_PERF, true),
    'denied',
  );
  eq(
    'advanced: Analytics granted is honoured even when Performance is denied',
    analyticsSignal(ALLOW_ANALYTICS_DENY_PERF, true),
    'granted',
  );
  // The guard that keeps this from becoming "advanced mode always denies": an
  // install that never offers an `analytics` category must still work off its
  // analytics-class slug.
  eq(
    'advanced: a site offering only performance is not silently denied',
    analyticsSignal(ONLY_PERFORMANCE, true),
    'granted',
  );
  eq(
    'advanced: both granted is granted',
    analyticsSignal(ALLOW_BOTH, true),
    'granted',
  );
  eq(
    'advanced: a live update follows a later Analytics grant',
    analyticsSignal(DENY_ANALYTICS_ALLOW_PERF, true, ALLOW_ANALYTICS_DENY_PERF),
    'granted',
  );
  eq(
    'advanced: a live update follows a later Analytics withdrawal',
    analyticsSignal(ALLOW_ANALYTICS_DENY_PERF, true, DENY_ANALYTICS_ALLOW_PERF),
    'denied',
  );

  console.log(`\n  gcm-advanced-mode-analytics: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
