/**
 * Without geographic routing the consent scope is decided by the LAW alone.
 *
 * A single-banner site serves the same banner to everybody. Ending an A/B test,
 * re-creating the banner under a new slug or rotating the AUTH salts (which
 * changes __scope.fp) does not put a different question to the visitor, so it
 * must not re-prompt the whole audience. A change of law does. With geo routing
 * (country-dependent output or the cached jurisdiction shell) banner, law and
 * fingerprint all still decide.
 *
 * Runs the real script.js runtime and the real tcf-cmp.js CMP; gcm.js is
 * covered by gcm-scope-invalidation.test.mjs and the server by
 * test-consent-scope-server-php.php.
 *
 * Run: node tests/unit/js/scope-single-banner.test.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const runtime = readFileSync(new URL('../../../frontend/js/script.js', import.meta.url), 'utf8');
const cmp = readFileSync(new URL('../../../frontend/js/tcf-cmp.js', import.meta.url), 'utf8');
let passed = 0;

const CURRENT_FP = 'current-fingerprint';
const base = {
  _bannerSlug: 'main-2',
  _activeLaw: 'gdpr',
  _scopeFingerprint: CURRENT_FP,
  _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'marketing', isNecessary: false }],
  _bannerConfig: { settings: { applicableLaw: 'gdpr' } },
};
const RENAMED = 'action:yes,consent:yes,necessary:yes,marketing:yes,__scope.banner:main-1,__scope.law:gdpr,__scope.fp:old-fingerprint';
const ROTATED = 'action:yes,consent:yes,necessary:yes,marketing:yes,__scope.banner:main-2,__scope.law:gdpr,__scope.fp:pre-rotation';
const OTHER_LAW = 'action:yes,consent:yes,necessary:yes,marketing:yes,__scope.banner:main-2,__scope.law:ccpa,__scope.fp:' + CURRENT_FP;

function runtimeKeeps(cookie, overrides) {
  const w = new JSDOM('', { runScripts: 'outside-only', url: 'https://site.test/' }).window;
  w._fazConfig = Object.assign({}, base, overrides);
  w.document.cookie = 'fazcookie-consent=' + encodeURIComponent(cookie);
  const add = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...args) => (type === 'DOMContentLoaded' ? undefined : add(type, ...args));
  w.console.error = () => {};
  w.eval(runtime);
  const kept = w.fazcookie._fazGetFromStore('action') === 'yes' && w.fazcookie._fazGetFromStore('marketing') === 'yes';
  w.close();
  return kept;
}

function cmpGrants(cookie, overrides) {
  const w = new JSDOM('', { runScripts: 'outside-only', url: 'https://site.test/' }).window;
  w._fazConfig = Object.assign({}, base, overrides);
  w._fazTcfConfig = { cmpId: 400, gvlVersion: 100, selectedVendors: [1], vendors: { 1: { name: 'Vendor', purposes: [1, 3], legIntPurposes: [] } } };
  w.document.cookie = 'fazcookie-consent=' + encodeURIComponent(cookie);
  w.document.cookie = 'fazVendorConsent=1:yes';
  w.eval(cmp);
  let granted = null;
  w.__tcfapi('getTCData', 2, (d) => { granted = d.vendor.consents[1] === true; });
  w.close();
  return granted;
}

const cases = [
  ['single banner: a new banner slug with the same law keeps the consent', RENAMED, { _geoRouting: '' }, true],
  ['single banner: a fingerprint from rotated AUTH salts keeps the consent', ROTATED, { _geoRouting: '' }, true],
  ['single banner: a different law invalidates it', OTHER_LAW, { _geoRouting: '' }, false],
  ['single banner, strict mode: a record without a fingerprint is refused', 'action:yes,consent:yes,necessary:yes,marketing:yes,__scope.banner:main-1,__scope.law:gdpr', { _geoRouting: '', _strictScopeFp: '1' }, false],
  ['geo routing: the renamed-banner record is refused (full check)', RENAMED, { _geoRouting: '1' }, false],
  ['geo routing: a foreign fingerprint is refused (full check)', ROTATED, { _geoRouting: '1' }, false],
  ['multisite single banner: a record from the previous banner slug stands', RENAMED, { _geoRouting: '', _siteScopeBound: '1' }, true],
  ['multisite single banner: same banner and law with a foreign fingerprint is another site', ROTATED, { _geoRouting: '', _siteScopeBound: '1' }, false],
];
for (const [label, cookie, overrides, expected] of cases) {
  assert.equal(runtimeKeeps(cookie, overrides), expected, 'script.js: ' + label); ++passed;
  console.log('  PASS script.js: ' + label);
  assert.equal(cmpGrants(cookie, overrides), expected, 'tcf-cmp.js: ' + label); ++passed;
  console.log('  PASS tcf-cmp.js: ' + label);
}
console.log(`scope-single-banner: ${passed} passed, 0 failed`);
