/**
 * JS unit test (jsdom) — merging two categories onto one signal must be
 * most-restrictive, in every emitter.
 *
 * `analytics` and `performance` both ship in the default category set and both
 * map to the analytics-class signal. Every emitter used granted-wins:
 *
 *   gcm.js               analytics === "granted" || performance === "granted"
 *   microsoft-consent.js hasAny(cats, ['analytics','performance'])
 *   wca.js               per-category writes, last one wins on `statistics`
 *
 * So a visitor who DENIED Analytics and allowed Performance was signalled
 * analytics-granted to Google, Microsoft and every WP Consent API consumer. In
 * Advanced Consent Mode the Google stack is deliberately not blocked, so GA4
 * then actually wrote its cookies against a denial.
 *
 * The `||` was itself a fix — "performance" used to be dropped entirely — which
 * is why these cases assert BOTH directions: a rule that always denied would
 * satisfy the regression case on its own and re-break the original bug.
 *
 * Run: node tests/unit/js/analytics-signal-merge.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../../../frontend/js');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    console.log(`       expected: ${JSON.stringify(expected)}`);
    console.log(`       actual:   ${JSON.stringify(actual)}`);
  }
}

/* ---------------------------------------------------------------- gcm.js -- */

/** Drive gcm.js through a real consent cookie and return the last consent update. */
function gcmAnalyticsFor(cookie) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  window.document.cookie = `fazcookie-consent=${encodeURIComponent(cookie)}`;

  // gcm.js defines its OWN gtag() that pushes onto the data layer, so a stubbed
  // window.gtag is never called — read the data layer, which is what Google
  // itself consumes.
  window.dataLayer = [];
  window._fazGcm = { status: true, advanced: false, consentRevision: 1, regions: [] };
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(readFileSync(resolve(ROOT, 'gcm.js'), 'utf8'));

  const updates = window.dataLayer
    .map((a) => Array.prototype.slice.call(a))
    .filter((c) => c[0] === 'consent' && c[1] === 'update');
  const last = updates.length ? updates[updates.length - 1][2] : null;
  return last ? last.analytics_storage : null;
}

/* --------------------------------------------------- microsoft-consent.js -- */

/** Drive microsoft-consent.js with an accepted/rejected pair. */
function microsoftAnalyticsFor(accepted, rejected) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  window._fazMicrosoftUET = true;
  window._fazMicrosoftClarity = false;
  window.uetq = [];
  window.console.error = () => {};

  window.eval(readFileSync(resolve(ROOT, 'microsoft-consent.js'), 'utf8'));

  window.document.dispatchEvent(
    new window.CustomEvent('fazcookie_consent_update', { detail: { accepted, rejected } }),
  );
  // uetq is a FLAT queue: push('consent','update',state) appends three separate
  // entries, so scan for the ('consent','update') pair and take what follows.
  let state = null;
  for (let i = 0; i + 2 < window.uetq.length; i += 1) {
    if (window.uetq[i] === 'consent' && window.uetq[i + 1] === 'update') {
      state = window.uetq[i + 2];
    }
  }
  return state ? state.analytics_storage : null;
}

/* ---------------------------------------------------------------- wca.js -- */

/** Drive wca.js and return the status it wrote for the `statistics` purpose. */
function wcaStatisticsFor(categories) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  const written = {};
  window.wp_set_consent = (purpose, status) => { written[purpose] = status; };
  window._fazGsk = false;
  window.console.error = () => {};
  // wca.js reads the consent state through window.getFazConsent(), which
  // script.js publishes. Stub it: this suite is about how categories are merged
  // onto a purpose, not about how the store is hydrated.
  window.getFazConsent = () => ({
    categories,
    activeLaw: 'gdpr',
    isUserActionCompleted: true,
  });

  window.eval(readFileSync(resolve(ROOT, 'wca.js'), 'utf8'));

  window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_update'));
  return written.statistics || null;
}

function run() {
  const DENY_ANALYTICS_ALLOW_PERF =
    'consentid:x,consent:yes,action:yes,necessary:yes,analytics:no,performance:yes,marketing:no';
  const ALLOW_BOTH =
    'consentid:x,consent:yes,action:yes,necessary:yes,analytics:yes,performance:yes,marketing:no';
  const ONLY_ANALYTICS_ALLOWED =
    'consentid:x,consent:yes,action:yes,necessary:yes,analytics:yes,marketing:no';

  // --- gcm.js -------------------------------------------------------------
  eq(
    'gcm: Analytics denied + Performance allowed => analytics_storage DENIED',
    gcmAnalyticsFor(DENY_ANALYTICS_ALLOW_PERF),
    'denied',
  );
  eq(
    'gcm: both allowed => analytics_storage granted',
    gcmAnalyticsFor(ALLOW_BOTH),
    'granted',
  );
  // The direction the `||` was introduced to fix: a site that offers only
  // "analytics" must not be denied because "performance" is absent.
  eq(
    'gcm: site with no performance category still grants on analytics alone',
    gcmAnalyticsFor(ONLY_ANALYTICS_ALLOWED),
    'granted',
  );

  // --- microsoft-consent.js -----------------------------------------------
  eq(
    'uet: Analytics denied + Performance allowed => analytics_storage DENIED',
    microsoftAnalyticsFor(['necessary', 'performance'], ['analytics', 'marketing']),
    'denied',
  );
  eq(
    'uet: both allowed => analytics_storage granted',
    microsoftAnalyticsFor(['necessary', 'analytics', 'performance'], ['marketing']),
    'granted',
  );
  eq(
    'uet: site with no performance category still grants on analytics alone',
    microsoftAnalyticsFor(['necessary', 'analytics'], ['marketing']),
    'granted',
  );

  // --- wca.js -------------------------------------------------------------
  eq(
    'wca: Analytics denied + Performance allowed => statistics DENY',
    wcaStatisticsFor({ necessary: true, analytics: false, performance: true, marketing: false }),
    'deny',
  );
  eq(
    'wca: both allowed => statistics allow',
    wcaStatisticsFor({ necessary: true, analytics: true, performance: true, marketing: false }),
    'allow',
  );
  eq(
    'wca: site with no performance category still allows on analytics alone',
    wcaStatisticsFor({ necessary: true, analytics: true, marketing: false }),
    'allow',
  );

  console.log(`\n  analytics-signal-merge: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
