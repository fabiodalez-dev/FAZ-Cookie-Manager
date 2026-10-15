/**
 * JS unit test (jsdom) — the CMP must honour the Right to Object on
 * legitimate-interest processing (IAB TCF Policies, Appendix B; GDPR Art. 21).
 *
 * buildPurposeLI() used to set the LI bit for every purpose a selected vendor
 * declared under legitimate interest, with no user input at all, and
 * buildVendorLI() followed. The preference center rendered LI as read-only
 * text, so nothing a visitor did — not even Reject All, not even switching the
 * vendor off — could ever produce a negative LI signal. Reproduced before the
 * fix: Reject All with a vendor declaring legIntPurposes [2,7,9,10] wrote
 * euconsent-v2 with PurposesLI 010000101100000000000000 and VendorLI=1.
 *
 * Objections are recorded by script.js in the `fazVendorLI` cookie:
 * `p<purposeId>:yes|no` for purposes and `<vendorId>:yes|no` for vendors,
 * where `no` means the visitor objected. These cases drive the real CMP through
 * its cookies and read the LI bits back through __tcfapi, so they fail if the
 * derivation stops consulting the record.
 *
 * Run: node tests/unit/js/tcf-right-to-object.test.mjs
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
const REJECT = 'consentid:abc,consent:yes,action:yes,necessary:yes,functional:no,analytics:no,performance:no,marketing:no';

/**
 * Boot the real tcf-cmp.js with one LI-declaring vendor (755) selected and the
 * given cookies already in the jar, exactly as a returning pageview sees them.
 * Returns the TCData the CMP publishes plus whether euconsent-v2 was written.
 */
function boot({ consent, vendorConsent = null, vendorLI = null, extraVendors = {} }) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;

  window.document.cookie = `fazcookie-consent=${encodeURIComponent(consent)}`;
  if (vendorConsent !== null) window.document.cookie = `fazVendorConsent=${vendorConsent}`;
  if (vendorLI !== null) window.document.cookie = `fazVendorLI=${vendorLI}`;

  const vendors = Object.assign({
    755: { name: 'Acme Ads', purposes: [1, 3, 4], legIntPurposes: [2, 7, 9, 10], features: [] },
  }, extraVendors);

  window._fazTcfConfig = {
    cmpId: 400,
    cmpVersion: 1,
    gvlVersion: 100,
    selectedVendors: Object.keys(vendors).map(Number),
    vendors,
    consentRevision: 1,
    purposeOneTreatment: false,
  };
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(CODE);

  let data = null;
  window.__tcfapi('getTCData', 2, (d, ok) => { if (ok) data = d; });
  return {
    purposeLI: data.purpose.legitimateInterests,
    vendorLI: data.vendor.legitimateInterests,
    vendorConsents: data.vendor.consents,
    tcCookieWritten: window.document.cookie.indexOf('euconsent-v2=') !== -1,
  };
}

const liOn = (li) => ['2', '7', '9', '10'].filter((p) => li[p] === true).join(',');

function run() {
  console.log('\nRight to Object: LI bits follow the visitor, not the vendor list');

  // 1. Reject All from a visitor who has no objection record yet (a cookie
  //    written before this version existed). Reject All is the EDPB/CNIL
  //    reading of "object to everything": no LI purpose, no LI vendor, and
  //    nothing left to persist in euconsent-v2.
  {
    const r = boot({ consent: REJECT, vendorConsent: '755:no' });
    eq('Reject All (legacy, no LI record): no LI purpose is established', liOn(r.purposeLI), '');
    eq('Reject All (legacy, no LI record): vendor LI is off', r.vendorLI['755'] === true, false);
    eq('Reject All (legacy, no LI record): euconsent-v2 is not written on load', r.tcCookieWritten, false);
  }

  // 2. Reject All with the explicit objection record script.js now writes.
  {
    const r = boot({ consent: REJECT, vendorConsent: '755:no', vendorLI: 'p2:no,p7:no,p9:no,p10:no,755:no' });
    eq('Reject All (explicit objections): no LI purpose is established', liOn(r.purposeLI), '');
    eq('Reject All (explicit objections): vendor LI is off', r.vendorLI['755'] === true, false);
    eq('Reject All (explicit objections): euconsent-v2 is not written on load', r.tcCookieWritten, false);
  }

  // 3. Accept All with no objection: LI stays established. Guards the other
  //    direction — a rule that always cleared LI would pass 1 and 2 alone.
  {
    const r = boot({ consent: ACCEPT, vendorConsent: '755:yes', vendorLI: 'p2:yes,p7:yes,p9:yes,p10:yes,755:yes' });
    eq('Accept All, no objection: every declared LI purpose is established', liOn(r.purposeLI), '2,7,9,10');
    eq('Accept All, no objection: vendor LI is on', r.vendorLI['755'], true);
    eq('Accept All, no objection: euconsent-v2 is written', r.tcCookieWritten, true);
  }

  // 4. A legacy Accept All (no LI record) keeps the TCF default: LI is
  //    established until the visitor objects.
  {
    const r = boot({ consent: ACCEPT, vendorConsent: '755:yes' });
    eq('Accept All (legacy, no LI record): LI default is preserved', liOn(r.purposeLI), '2,7,9,10');
    eq('Accept All (legacy, no LI record): vendor LI is on', r.vendorLI['755'], true);
  }

  // 5. Per-purpose objection: the visitor consented to everything but objected
  //    to purpose 2. Only that bit goes down; the vendor keeps LI on 7/9/10.
  {
    const r = boot({ consent: ACCEPT, vendorConsent: '755:yes', vendorLI: 'p2:no,p7:yes,p9:yes,p10:yes,755:yes' });
    eq('purpose-level objection: purpose 2 LI is cleared', r.purposeLI['2'] === true, false);
    eq('purpose-level objection: the other LI purposes stay established', liOn(r.purposeLI), '7,9,10');
    eq('purpose-level objection: vendor still has LI on the remaining purposes', r.vendorLI['755'], true);
  }

  // 6. Per-vendor objection: everything granted, but the visitor objected to
  //    this vendor's LI. With no other vendor declaring LI, the purpose bits
  //    have nobody left to be established for.
  {
    const r = boot({ consent: ACCEPT, vendorConsent: '755:yes', vendorLI: 'p2:yes,p7:yes,p9:yes,p10:yes,755:no' });
    eq('vendor-level objection: vendor LI is off', r.vendorLI['755'] === true, false);
    eq('vendor-level objection: no purpose LI remains with that vendor objected', liOn(r.purposeLI), '');
  }

  // 7. Switching the vendor OFF in the preference center also ends its LI:
  //    a visitor who turned a vendor off did not mean "but keep processing
  //    my data under legitimate interest".
  {
    const r = boot({ consent: ACCEPT, vendorConsent: '755:no', vendorLI: 'p2:yes,p7:yes,p9:yes,p10:yes,755:yes' });
    eq('vendor switched off: vendor consent is off', r.vendorConsents['755'] === true, false);
    eq('vendor switched off: vendor LI is off too', r.vendorLI['755'] === true, false);
    eq('vendor switched off: no purpose LI remains', liOn(r.purposeLI), '');
  }

  // 8. Purpose LI is per purpose across vendors: a second vendor declaring
  //    only purpose 8 keeps 8 established while 755's purposes are objected.
  {
    const r = boot({
      consent: ACCEPT,
      vendorConsent: '755:yes,800:yes',
      vendorLI: 'p2:no,p7:no,p9:no,p10:no,p8:yes,755:yes,800:yes',
      extraVendors: { 800: { name: 'Measure Co', purposes: [1], legIntPurposes: [8], features: [] } },
    });
    eq('mixed vendors: purpose 8 stays established for the vendor nobody objected to', r.purposeLI['8'], true);
    eq('mixed vendors: vendor 800 keeps LI', r.vendorLI['800'], true);
    eq('mixed vendors: vendor 755 loses LI once all its LI purposes are objected', r.vendorLI['755'] === true, false);
  }

  console.log(`\n  tcf-right-to-object: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
