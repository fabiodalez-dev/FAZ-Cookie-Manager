/**
 * JS unit test (jsdom) — when the runtime invalidates a record gcm.js already
 * restored, Google is sent back to the configured defaults, not to a global
 * denial.
 *
 * gcm.js emits the admin's region rows as `consent default`, then restores a
 * returning visitor's record with `consent update`. If the runtime then
 * invalidates that record (revision bump, scope change, TCF vendor list), the
 * listener resets to a baseline — which was a hard-coded global denial. On an
 * install whose defaults grant a signal everywhere (an "All" row with
 * analytics_storage granted, say), a visitor whose record merely went stale
 * had that signal withdrawn: a state they were never in before the restore.
 *
 * `consent update` cannot be region-scoped and gcm.js does not know the
 * visitor's region, so each signal goes back to the value every applicable
 * default row agrees on, denied where they disagree. Advanced Consent Mode's
 * default is a single global denial and stays the baseline there.
 *
 * Run: node tests/unit/js/gcm-baseline-region-defaults.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const GCM = readFileSync(resolve(HERE, '../../../frontend/js/gcm.js'), 'utf8');

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

const GRANTS = 'consentid:x,consent:yes,action:yes,necessary:yes,analytics:yes,marketing:yes,functional:yes';

function row(regions, overrides = {}) {
  return Object.assign({
    regions,
    necessary: 'granted',
    functional: 'denied',
    analytics: 'denied',
    marketing: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
  }, overrides);
}

/** Restore a granted record, then invalidate it the way the runtime does. */
function invalidateAfterRestore(defaultSettings, { advanced = false, npa = false } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  window.document.cookie = `fazcookie-consent=${encodeURIComponent(GRANTS)}`;
  window.dataLayer = [];
  window._fazConfig = { _consentRevision: '1' };
  window._fazGcm = {
    status: true,
    advanced_mode: advanced,
    default_settings: defaultSettings,
    non_personalized_ads_fallback: npa,
  };
  window.eval(GCM);
  const before = window.dataLayer.length;
  window.document.cookie = 'fazcookie-consent=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_update'));
  const calls = window.dataLayer.map((a) => Array.prototype.slice.call(a));
  const updates = calls.filter((c) => c[0] === 'consent' && c[1] === 'update').map((c) => c[2]);
  const after = calls.slice(before);
  return { baseline: updates[updates.length - 1], restored: updates[0], after };
}

console.log('gcm.js: the invalidation baseline follows the configured defaults');

// 1. One "All" row granting analytics and functionality.
{
  const r = invalidateAfterRestore([row('All', { analytics: 'granted', functional: 'granted' })]);
  eq('premise: the record was restored granted', r.restored && r.restored.ad_storage, 'granted');
  eq('baseline: analytics_storage back to its default (granted)', r.baseline && r.baseline.analytics_storage, 'granted');
  eq('baseline: functionality_storage back to its default (granted)', r.baseline && r.baseline.functionality_storage, 'granted');
  eq('baseline: personalization_storage follows functional', r.baseline && r.baseline.personalization_storage, 'granted');
  eq('baseline: ad_storage back to its default (denied)', r.baseline && r.baseline.ad_storage, 'denied');
  eq('baseline: ad_user_data denied', r.baseline && r.baseline.ad_user_data, 'denied');
  eq('baseline: security_storage granted', r.baseline && r.baseline.security_storage, 'granted');
}

// 2. Rows that disagree: the visitor may be in either, so most restrictive.
{
  const r = invalidateAfterRestore([row('US', { analytics: 'granted' }), row('All')]);
  eq('US grants analytics, All denies it: the baseline denies', r.baseline && r.baseline.analytics_storage, 'denied');
  const r2 = invalidateAfterRestore([row('US', { analytics: 'granted' }), row('All', { analytics: 'granted' })]);
  eq('US and All both grant analytics: the baseline grants', r2.baseline && r2.baseline.analytics_storage, 'granted');
}

// 3. Only a region row: everywhere else the built-in denied default applies.
{
  const r = invalidateAfterRestore([row('US', { analytics: 'granted' })]);
  eq('US row only (built-in denial elsewhere): the baseline denies', r.baseline && r.baseline.analytics_storage, 'denied');
  const none = invalidateAfterRestore([]);
  eq('no rows at all: the built-in denial', none.baseline && none.baseline.analytics_storage, 'denied');
  eq('no rows at all: security_storage granted', none.baseline && none.baseline.security_storage, 'granted');
}

// 4. Advanced Consent Mode: the inline default is a single global denial.
{
  const r = invalidateAfterRestore([row('All', { analytics: 'granted' })], { advanced: true });
  eq('advanced mode: baseline is the global denied default, whatever the rows say', r.baseline && r.baseline.analytics_storage, 'denied');
}

// 5. The legacy-tag npa signal goes back with ad_storage.
{
  const r = invalidateAfterRestore([row('All', { analytics: 'granted' })], { npa: true });
  const npa = r.after.filter((c) => c[0] === 'set' && c[1] && typeof c[1] === 'object' && 'npa' in c[1]).map((c) => c[1].npa);
  eq('non-personalized-ads fallback: npa back to 1 with ad_storage denied', npa[npa.length - 1], 1);
}

console.log(`\ngcm-baseline-region-defaults: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
