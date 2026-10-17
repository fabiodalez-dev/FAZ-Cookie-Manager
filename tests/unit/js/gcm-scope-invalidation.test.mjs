/**
 * JS unit test (jsdom) — gcm.js must not restore the grants of a consent cookie
 * recorded under another banner/law scope, and must fall back to the denied
 * baseline when the runtime invalidates the record it already restored.
 *
 * gcm.js runs synchronously in <head>, before the deferred runtime. It used to
 * validate the cookie by `rev` alone, so a CCPA-acknowledged record
 * (everything "yes") arriving under the GDPR banner was pushed to Google as
 * `consent update` granted; script.js then found the scope mismatch, deleted
 * the cookie and dispatched fazcookie_consent_update — whose gcm.js listener
 * re-read a cookie that no longer existed and deliberately left the granted
 * state standing for the whole page view.
 *
 * Run: node tests/unit/js/gcm-scope-invalidation.test.mjs
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

// What the GDPR banner publishes. wp_localize_script() stringifies scalars, so
// booleans arrive as "1" / "" exactly as they do in production.
const EU_FP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const US_FP = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
function euConfig(overrides = {}) {
  return Object.assign(
    {
      _consentRevision: '1',
      _geoRouting: '1',
      _geoBootstrap: '',
      _strictScopeFp: '',
      _bannerSlug: 'eu-1',
      _activeLaw: 'gdpr',
      _scopeFingerprint: EU_FP,
    },
    overrides,
  );
}

const GRANTS = 'consentid:x,consent:no,action:yes,necessary:yes,analytics:yes,marketing:yes,functional:yes';
const CCPA_US = `${GRANTS},__scope.banner:us-2,__scope.law:ccpa,__scope.fp:${US_FP}`;
const GDPR_EU = `${GRANTS},__scope.banner:eu-1,__scope.law:gdpr,__scope.fp:${EU_FP}`;
const PMP = 'action:auto,consent:no,necessary:yes,analytics:no,marketing:no,rev:1,source:pmp';

/**
 * Run gcm.js with a cookie and a config; optionally change the cookie and fire
 * the runtime's event. Returns every `consent update` pushed to the data layer.
 */
function run(cookie, config, { after = undefined, gacm = false } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  if (cookie !== null) {
    window.document.cookie = `fazcookie-consent=${encodeURIComponent(cookie)}`;
  }
  window.dataLayer = [];
  window._fazConfig = config;
  window._fazGcm = {
    status: true,
    advanced_mode: false,
    default_settings: [],
    gacm_enabled: gacm,
    gacm_provider_ids: gacm ? '123,456' : '',
  };
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(readFileSync(GCM_PATH, 'utf8'));

  if (after !== undefined) {
    if (after === null) {
      // _fazInvalidateStoredConsent() deletes the cookie before the event.
      window.document.cookie = 'fazcookie-consent=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    } else {
      window.document.cookie = `fazcookie-consent=${encodeURIComponent(after)}`;
    }
    window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_update'));
  }

  const calls = window.dataLayer.map((a) => Array.prototype.slice.call(a));
  return {
    updates: calls.filter((c) => c[0] === 'consent' && c[1] === 'update').map((c) => c[2]),
    sets: calls.filter((c) => c[0] === 'set'),
  };
}

function last(updates) {
  return updates.length ? updates[updates.length - 1] : null;
}

function main() {
  // --- The restore must apply the same scope judgement as the runtime ------
  let r = run(CCPA_US, euConfig());
  eq('CCPA-scoped record under the GDPR banner: no grants are restored', r.updates.length, 0);

  r = run(GDPR_EU, euConfig());
  eq('same scope: the stored grants are restored', last(r.updates) && last(r.updates).ad_storage, 'granted');

  r = run(CCPA_US, euConfig({ _bannerSlug: 'us-2', _activeLaw: 'ccpa', _scopeFingerprint: US_FP }));
  eq('back under the US banner the CCPA record is restored again', last(r.updates) && last(r.updates).ad_storage, 'granted');

  r = run(CCPA_US, euConfig({ _geoRouting: '' }));
  eq('single-banner install (_geoRouting off): scope is not compared, as in script.js', last(r.updates) && last(r.updates).ad_storage, 'granted');

  r = run(CCPA_US, euConfig({ _geoBootstrap: '1' }));
  eq('cached jurisdiction shell advertises a placeholder scope: check deferred, as in script.js', last(r.updates) && last(r.updates).ad_storage, 'granted');

  r = run(GRANTS, euConfig());
  eq('pre-1.14 cookie without scope keys is honoured (upgrade case)', last(r.updates) && last(r.updates).ad_storage, 'granted');

  r = run(GRANTS, euConfig({ _strictScopeFp: '1' }));
  eq('strict fingerprint mode refuses a cookie without __scope.fp', r.updates.length, 0);

  r = run(`${GRANTS},__scope.banner:eu-1,__scope.law:gdpr,__scope.fp:${US_FP}`, euConfig());
  eq('hand-edited banner/law with a foreign fingerprint is refused', r.updates.length, 0);

  r = run(`${GRANTS},law:ccpa`, euConfig());
  eq('the legacy unprefixed law slot is honoured on read', r.updates.length, 0);

  r = run(`${GRANTS},banner:yes,law:no`, euConfig());
  eq('legacy slots holding a category decision are not scope values', last(r.updates) && last(r.updates).ad_storage, 'granted');

  r = run(`${PMP},__scope.law:ccpa`, euConfig());
  eq('the PMP necessary-only state is valid under every scope (its denied state is pushed)', last(r.updates) && last(r.updates).ad_storage, 'denied');

  r = run(`${PMP.replace('marketing:no', 'marketing:yes')},__scope.law:ccpa`, euConfig());
  eq('a forged PMP marker with an optional grant cannot bypass the law scope', r.updates.length, 0);

  // --- Invalidation after a restore must reach Google on the same page -----
  r = run(GDPR_EU, euConfig({ _consentRevision: '1' }), { after: null });
  eq('restore, then the runtime deletes the cookie: two updates were pushed', r.updates.length, 2);
  eq('... and the last one is the denied baseline (ad_storage)', last(r.updates) && last(r.updates).ad_storage, 'denied');
  eq('... (analytics_storage)', last(r.updates) && last(r.updates).analytics_storage, 'denied');
  eq('... (functionality_storage)', last(r.updates) && last(r.updates).functionality_storage, 'denied');
  eq('... while security_storage stays granted', last(r.updates) && last(r.updates).security_storage, 'granted');

  r = run(GDPR_EU, euConfig(), { after: null, gacm: true });
  const addtl = r.sets.filter((c) => c[1] === 'addtl_consent').map((c) => c[2]);
  eq('GACM: the provider list is emptied with the grants', addtl[addtl.length - 1], '1~');
  eq('GACM: it had been populated by the restore', addtl[0], '1~123.456');

  r = run(null, euConfig(), { after: null });
  eq('first visit, event with no cookie: nothing restored, nothing pushed (baseline default stands)', r.updates.length, 0);

  r = run(GDPR_EU, euConfig(), { after: `${GRANTS.replace('marketing:yes', 'marketing:no')},__scope.banner:eu-1,__scope.law:gdpr,__scope.fp:${EU_FP}` });
  eq('a live withdrawal still follows the new cookie', last(r.updates) && last(r.updates).ad_storage, 'denied');
  eq('... without touching what was granted', last(r.updates) && last(r.updates).analytics_storage, 'granted');

  console.log(`\n  gcm-scope-invalidation: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

main();
