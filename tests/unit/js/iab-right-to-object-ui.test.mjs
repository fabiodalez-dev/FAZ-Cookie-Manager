/**
 * JS unit test (jsdom) — the IAB section of the preference center must expose
 * a Right-to-Object control per purpose and per vendor, and persist it.
 *
 * Until now the section rendered one "Vendor consent" switch per vendor and
 * listed legitimate-interest purposes as read-only text. A visitor had no way
 * to object, so tcf-cmp.js had nothing to read and signalled LI for every
 * selected vendor regardless of what the visitor did (IAB TCF Policies,
 * Appendix B, second layer; GDPR Art. 21).
 *
 * The record lives in the `fazVendorLI` cookie: `p<purposeId>:yes|no` for the
 * purpose switches, `<vendorId>:yes|no` for the vendor switches, `no` meaning
 * the visitor objected. Reject All objects to all of them; a vendor switched
 * off also loses its LI.
 *
 * Run: node tests/unit/js/iab-right-to-object-ui.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');
const CODE = readFileSync(SCRIPT_PATH, 'utf8');

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

function boot({ cookies = [] } = {}) {
  const dom = new JSDOM(
    `<!DOCTYPE html><html><body>
       <div class="faz-consent-container" id="faz-consent">
         <div class="faz-consent-bar" data-faz-tag="notice"></div>
         <div class="faz-modal">
           <div class="faz-preference-body-wrapper">
             <div class="faz-accordion-wrapper"></div>
           </div>
         </div>
       </div>
     </body></html>`,
    { runScripts: 'outside-only', url: 'http://localhost/' },
  );
  const { window } = dom;
  cookies.forEach((c) => { window.document.cookie = c; });

  window._fazConfig = {
    _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'marketing' }],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    _i18n: {},
    _expiry: 180,
    _iabEnabled: true,
    _iabVendors: [
      { id: 755, name: 'Acme Ads', purposes: [1, 3, 4], legIntPurposes: [2, 7], features: [] },
      { id: 12, name: 'Consent Only Co', purposes: [1, 3], legIntPurposes: [], features: [] },
    ],
    _iabPurposes: [
      { id: 1, name: 'Store and/or access information on a device' },
      { id: 2, name: 'Use limited data to select advertising' },
      { id: 3, name: 'Create profiles for personalised advertising' },
      { id: 4, name: 'Use profiles to select personalised advertising' },
      { id: 7, name: 'Measure advertising performance' },
    ],
    _bannerConfig: {
      settings: { type: 'box', preferenceCenterType: 'popup', applicableLaw: 'gdpr' },
      config: {},
    },
  };

  Object.defineProperty(window.document, 'readyState', { get: () => 'loading', configurable: true });
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, cb, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, cb, ...rest);
  };
  window.setTimeout = () => 0;
  window.requestAnimationFrame = (cb) => { cb(); return 0; };
  window.cancelAnimationFrame = () => {};
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(CODE);
  window.document.addEventListener = realAdd;
  return window;
}

/** fazVendorLI pairs as a map, or null when the cookie is absent. */
function readLI(window) {
  const m = window.document.cookie.match(/fazVendorLI=([^;]+)/);
  if (!m) return null;
  const out = {};
  m[1].split(',').forEach((pair) => {
    const idx = pair.indexOf(':');
    out[pair.slice(0, idx)] = pair.slice(idx + 1);
  });
  return out;
}

function run() {
  console.log('\nIAB preference center: Right-to-Object switches');

  // 1. Rendering: a switch per LI purpose (union across vendors) and a switch
  //    per vendor that declares LI purposes. A consent-only vendor has nothing
  //    to object to, so it gets none.
  {
    const w = boot();
    w._fazRenderVendorSection();
    const d = w.document;
    eq('a Right-to-Object switch is rendered for LI purpose 2', !!d.getElementById('fazPurposeLISwitch2'), true);
    eq('a Right-to-Object switch is rendered for LI purpose 7', !!d.getElementById('fazPurposeLISwitch7'), true);
    eq('no switch is rendered for a consent-only purpose (3)', !!d.getElementById('fazPurposeLISwitch3'), false);
    eq('a per-vendor LI switch is rendered for the LI vendor', !!d.getElementById('fazVendorLISwitch755'), true);
    eq('no per-vendor LI switch for a vendor without LI purposes', !!d.getElementById('fazVendorLISwitch12'), false);
    const p2 = d.getElementById('fazPurposeLISwitch2');
    // Never pre-ticked: a switch that arrives on decides for the visitor.
    eq('a fresh visitor sees the LI switch off (not pre-ticked)', p2 ? p2.checked : null, false);
    const v755 = d.getElementById('fazVendorLISwitch755');
    eq('and the per-vendor LI switch off as well', v755 ? v755.checked : null, false);
    eq('the purpose switch is labelled as a legitimate-interest control',
      p2 ? /legitimate interest/i.test(p2.getAttribute('aria-label') || '') : false, true);
    eq('the vendor consent switch is still there', !!d.getElementById('fazVendorSwitch755'), true);
  }

  // 2. Reject All objects to everything.
  {
    const w = boot();
    w._fazRenderVendorSection();
    w._fazSaveVendorConsent('reject');
    const li = readLI(w);
    eq('Reject All writes the fazVendorLI record', li !== null, true);
    eq('Reject All objects to LI purpose 2', li && li.p2, 'no');
    eq('Reject All objects to LI purpose 7', li && li.p7, 'no');
    eq('Reject All objects to the vendor LI', li && li['755'], 'no');
  }

  // 3. Accept All leaves LI established (no objection recorded).
  {
    const w = boot();
    w._fazRenderVendorSection();
    w._fazSaveVendorConsent('all');
    const li = readLI(w);
    eq('Accept All records LI purpose 2 as not objected', li && li.p2, 'yes');
    // Only objected vendors are written (compact record): absent = not objected.
    eq('Accept All records the vendor LI as not objected', li !== null && li['755'] !== 'no', true);
  }

  // 4. Custom save reads the switches: object to purpose 2, keep the rest.
  //    The visitor turns on what they keep; nothing arrives on.
  {
    const w = boot();
    w._fazRenderVendorSection();
    w.document.getElementById('fazVendorSwitch755').checked = true;
    w.document.getElementById('fazPurposeLISwitch2').checked = false;
    w.document.getElementById('fazPurposeLISwitch7').checked = true;
    w.document.getElementById('fazVendorLISwitch755').checked = true;
    w._fazSaveVendorConsent('custom');
    const li = readLI(w);
    eq('custom save: purpose 2 objection is recorded', li && li.p2, 'no');
    eq('custom save: purpose 7 stays established', li && li.p7, 'yes');
    eq('custom save: vendor LI stays established', li !== null && li['755'] !== 'no', true);
  }

  // 4b. Saving without touching the switches records an objection: the
  //     default the visitor saw (off) is what they saved.
  {
    const w = boot();
    w._fazRenderVendorSection();
    w.document.getElementById('fazVendorSwitch755').checked = true;
    w._fazSaveVendorConsent('custom');
    const li = readLI(w);
    eq('untouched custom save: purpose 2 is objected', li && li.p2, 'no');
    eq('untouched custom save: vendor LI is objected', li && li['755'], 'no');
  }

  // 5. A vendor switched OFF loses LI as well, whatever its LI switch says.
  {
    const w = boot();
    w._fazRenderVendorSection();
    w.document.getElementById('fazVendorSwitch755').checked = false;
    w.document.getElementById('fazVendorLISwitch755').checked = true;
    w._fazSaveVendorConsent('custom');
    const li = readLI(w);
    const vc = w.document.cookie.match(/fazVendorConsent=([^;]+)/);
    eq('vendor off: consent record says no', vc ? vc[1].indexOf('755:no') !== -1 : false, true);
    eq('vendor off: LI record says objected', li && li['755'], 'no');
  }

  // 6. Reopening the preference center restores the recorded objections.
  {
    const w = boot({ cookies: [
      'fazcookie-consent=' + encodeURIComponent('consentid:abc,consent:yes,action:yes,necessary:yes,marketing:yes'),
      'fazVendorConsent=755:yes,12:yes',
      'fazVendorLI=p2:no,p7:yes,755:yes',
    ] });
    w._fazRenderVendorSection();
    const d = w.document;
    eq('reopen: objected purpose switch is off', d.getElementById('fazPurposeLISwitch2').checked, false);
    eq('reopen: non-objected purpose switch is on', d.getElementById('fazPurposeLISwitch7').checked, true);
    // Then the record changes underneath (another tab objected to vendor LI)
    // and the sync helper is called, as the revisit widget does.
    d.cookie = 'fazVendorLI=p2:no,p7:no,755:no';
    w._fazUpdateVendorCheckboxStates();
    eq('sync: purpose 7 switch follows the record', d.getElementById('fazPurposeLISwitch7').checked, false);
    eq('sync: vendor LI switch follows the record', d.getElementById('fazVendorLISwitch755').checked, false);
  }

  // 7. A legacy Reject All (consent recorded, no LI record yet) is shown as
  //    objected — the same reading tcf-cmp.js applies to the signal.
  {
    const w = boot({ cookies: [
      'fazcookie-consent=' + encodeURIComponent('consentid:abc,consent:yes,action:yes,necessary:yes,marketing:no'),
      'fazVendorConsent=755:no,12:no',
    ] });
    w._fazRenderVendorSection();
    eq('legacy Reject All: LI purpose switch renders as objected', w.document.getElementById('fazPurposeLISwitch2').checked, false);
    eq('legacy Reject All: vendor LI switch renders as objected', w.document.getElementById('fazVendorLISwitch755').checked, false);
  }

  console.log(`\n  iab-right-to-object-ui: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
