/**
 * JS unit test (jsdom) — PublisherCC must never be invented.
 *
 * tcf-cmp.js fell back to the literal "IT" (both in the TC string core
 * segment and in tcData.publisherCC) whenever the server sent no country.
 * The TC String specification reserves "AA" for an unknown publisher country;
 * asserting a specific, usually wrong, country of establishment misstates the
 * controller's jurisdiction in a signed consent record.
 *
 * Run: node tests/unit/js/tcf-publisher-cc.test.mjs
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

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
function coreBits(tcString) {
  const core = tcString.split('.')[0];
  const bits = [];
  for (const ch of core) {
    const v = B64.indexOf(ch);
    for (let i = 5; i >= 0; i--) bits.push((v >> i) & 1);
  }
  return bits;
}
function readBits(bits, offset, len) {
  let v = 0;
  for (let i = 0; i < len; i++) v = v * 2 + (bits[offset + i] || 0);
  return v;
}
// Version(6) Created(36) LastUpdated(36) CmpId(12) CmpVersion(12) ConsentScreen(6)
// ConsentLanguage(12) VendorListVersion(12) TcfPolicyVersion(6) IsServiceSpecific(1)
// UseNonStandardTexts(1) SpecialFeatureOptIns(12) PurposesConsent(24) PurposesLI(24)
// PurposeOneTreatment(1) = 201 → PublisherCC at bits 201-212.
const PUBLISHER_CC_OFFSET = 201;
function publisherCCFromString(tcString) {
  const bits = coreBits(tcString);
  const a = readBits(bits, PUBLISHER_CC_OFFSET, 6);
  const b = readBits(bits, PUBLISHER_CC_OFFSET + 6, 6);
  return String.fromCharCode(65 + a) + String.fromCharCode(65 + b);
}

function boot(cfg) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  window.document.cookie = 'fazcookie-consent=' + encodeURIComponent(
    'consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,performance:yes,marketing:yes',
  );
  window._fazTcfConfig = Object.assign({
    cmpId: 400, cmpVersion: 1, gvlVersion: 100, vendors: {}, selectedVendors: [],
    consentRevision: 1, purposeOneTreatment: false,
  }, cfg);
  window.console.error = () => {};
  window.console.warn = () => {};
  window.eval(CODE);
  let data = null;
  window.__tcfapi('getTCData', 2, (d, ok) => { if (ok) data = d; });
  return data;
}

function run() {
  console.log('\nPublisherCC: unknown is "AA", never a hard-coded country');

  {
    const d = boot({});
    eq('no publisherCC from the server: tcData reports AA', d.publisherCC, 'AA');
    eq('no publisherCC from the server: the TC string encodes AA', publisherCCFromString(d.tcString), 'AA');
  }
  {
    const d = boot({ publisherCC: 'DE' });
    eq('a configured country is passed through to tcData', d.publisherCC, 'DE');
    eq('a configured country is encoded in the TC string', publisherCCFromString(d.tcString), 'DE');
  }

  console.log(`\n  tcf-publisher-cc: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
