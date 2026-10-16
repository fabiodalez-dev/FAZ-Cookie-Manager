/**
 * JS unit test (jsdom) — the fazVendorConsent reader in script.js and its PHP
 * twin faz_parse_vendor_consent_record() agree on every vector.
 *
 * script.js re-prompts a decided visitor whose record does not disclose every
 * selected TCF vendor (_fazVendorRecordStale), and the server now reads the
 * same cookie as absent on the same page view (faz_is_vendor_record_stale(),
 * so the accepted categories' trackers are not rendered executable one page
 * before the re-prompt). Two parsers deciding one question must read the
 * record identically, or one layer re-prompts while the other ships trackers.
 *
 * The vectors live in tests/unit/fixtures/vendor-record-vectors.json, which
 * tests/unit/test-vendor-record-stale-php.php reads too: this side checks the
 * real script.js functions against it, that side the PHP functions.
 *
 * Run: node tests/unit/js/vendor-record-parity.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
const VECTORS = JSON.parse(readFileSync(resolve(HERE, '../fixtures/vendor-record-vectors.json'), 'utf8'));

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a === e) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    console.log(`       expected: ${e}`);
    console.log(`       actual:   ${a}`);
  }
}

const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: 'https://site.test/' });
const { window } = dom;
window._fazConfig = { _categories: [{ slug: 'necessary', isNecessary: true }], _services: [], _providersToBlock: [], i18n: {} };
window.fazcookie = { _fazGetFromStore: () => undefined };
const realAdd = window.document.addEventListener.bind(window.document);
window.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
window.eval(SCRIPT);
window.document.addEventListener = realAdd;

const parse = window.eval('_fazParseVendorRecord');
const decode = window.eval('_fazDecodeVendorBits');
const encode = window.eval('_fazEncodeVendorBits');

/** The fixture's normal form: sorted id lists, or null. */
function normal(record) {
  if (!record) return null;
  const ids = (map, pick) => Object.keys(map).map(Number).filter((id) => pick(map[id])).sort((x, y) => x - y);
  return { disclosed: ids(record.disclosed, (v) => v === true), consent: ids(record.consent, (v) => v === true) };
}

console.log('fazVendorConsent: script.js reads every fixture vector as expected');
VECTORS.records.forEach((v) => eq(`record ${JSON.stringify(v.raw)}`, normal(parse(v.raw)), v.expected));

console.log('vendor bitfields: decode and encode match the fixture');
VECTORS.bitfields.forEach((v) => {
  const got = decode(v.text);
  eq(`decode ${JSON.stringify(v.text)}`, got === null ? null : Array.from(got), v.ids);
  if (v.ids !== null) eq(`encode ${JSON.stringify(v.ids)}`, encode(v.ids), v.text.replace(/A+$/, ''));
});

console.log(`vendor-record-parity: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
