/**
 * JS unit test (jsdom) — vendor consent is only ever what the visitor recorded.
 *
 * buildVendorConsent() used to fall back, for any selected vendor absent from
 * the explicit fazVendorConsent record, to "consented if every consent purpose
 * the vendor declares is consented". The vendor list is server-side state that
 * changes without touching the visitor's record: the operator ticks a new
 * vendor, the first GVL download auto-selects thirty, or TCF is switched on
 * after visitors already accepted (then no record exists at all). Every such
 * vendor was minted a positive consent bit from a category choice made before
 * it was ever disclosed, and euconsent-v2 was rewritten on the next pageview —
 * while the preference center showed the same vendor unticked.
 *
 * GDPR Art. 4(11)/7 and EDPB 05/2020 §64: consent is informed only for the
 * recipients actually disclosed. Absence from the record now means no consent.
 *
 * Also pinned here: a vendor id that the current GVL no longer carries gets no
 * consent even when the record says yes (the `!v` guard runs before the
 * cookie), so a de-registered vendor can never be signalled as consented.
 *
 * Run: node tests/unit/js/tcf-vendor-consent-explicit-only.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/tcf-cmp.js');
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

const ACCEPT = 'consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,performance:yes,marketing:yes';

function boot({ selectedVendors, vendors, vendorConsent = null }) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  window.document.cookie = `fazcookie-consent=${encodeURIComponent(ACCEPT)}`;
  if (vendorConsent !== null) window.document.cookie = `fazVendorConsent=${vendorConsent}`;

  window._fazTcfConfig = {
    cmpId: 400, cmpVersion: 1, gvlVersion: 100,
    selectedVendors, vendors, consentRevision: 1, purposeOneTreatment: false,
  };
  window.console.error = () => {};
  window.console.warn = () => {};
  window.eval(CODE);

  let data = null;
  window.__tcfapi('getTCData', 2, (d, ok) => { if (ok) data = d; });
  return data;
}

const V = { name: 'V', purposes: [1, 3, 4], legIntPurposes: [], features: [] };

function run() {
  console.log('\nVendor consent: explicit record only');

  // 1. The visitor accepted when vendors 1 and 2 were disclosed; vendor 3 was
  //    added later. Its consent purposes are all granted at category level,
  //    which is exactly what the old derivation keyed on.
  {
    const d = boot({ selectedVendors: [1, 2, 3], vendors: { 1: V, 2: V, 3: V }, vendorConsent: '1:yes,2:yes' });
    eq('recorded vendor 1 is consented', d.vendor.consents['1'], true);
    eq('recorded vendor 2 is consented', d.vendor.consents['2'], true);
    eq('vendor 3, never disclosed to this visitor, is NOT consented', d.vendor.consents['3'] === true, false);
  }

  // 2. TCF switched on after the visitor accepted: no record exists at all.
  //    Every vendor is "new" and none may be signalled as consented.
  {
    const d = boot({ selectedVendors: [1, 2, 3], vendors: { 1: V, 2: V, 3: V } });
    const granted = Object.keys(d.vendor.consents).filter((k) => d.vendor.consents[k] === true);
    eq('no vendor record at all: no vendor is consented', granted.join(','), '');
  }

  // 3. The record is authoritative in both directions.
  {
    const d = boot({ selectedVendors: [1, 2], vendors: { 1: V, 2: V }, vendorConsent: '1:yes,2:no' });
    eq('record yes → consented', d.vendor.consents['1'], true);
    eq('record no → not consented, even with every purpose granted', d.vendor.consents['2'] === true, false);
  }

  // 4. A vendor id the GVL in use no longer carries: the record says yes, the
  //    vendor does not exist. No positive signal for a de-registered vendor.
  {
    const d = boot({ selectedVendors: [5], vendors: {}, vendorConsent: '5:yes' });
    eq('vendor absent from the GVL is never consented, whatever the record says', d.vendor.consents['5'] === true, false);
  }

  console.log(`\n  tcf-vendor-consent-explicit-only: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
