/**
 * Created and LastUpdated in the TC string must be equal and day-level.
 *
 * IAB TCF v2 TC String format, Core String, "What happened to Created and
 * LastUpdated?" (December 2021): both fields hold the same value, the UTC
 * midnight of the day the string was last updated, in deciseconds —
 * Math.round(Date.UTC(y, m, d) / 100). The rule exists so the string stops
 * carrying a sub-day timestamp that works as fingerprinting entropy, and so a
 * consent record names a day, not an instant.
 *
 * tcf-cmp.js encoded both as Math.round(Date.now() / 100) and, on every
 * decision after the first, preserved Created from the previous cookie while
 * refreshing LastUpdated. Every string failed the precision rule; every
 * re-consent failed the equality rule.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const CMP_CODE = readFileSync(resolve(HERE, '../../../frontend/js/tcf-cmp.js'), 'utf8');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) { console.log(`  \x1b[32mPASS\x1b[0m ${label}`); passed++; }
  else { console.log(`  \x1b[31mFAIL\x1b[0m ${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`); failed++; }
}

const CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
const DAY_DS = 864000; // deciseconds in one day

function pushBits(bits, value, length) {
  for (let i = length - 1; i >= 0; i--) {
    bits.push(Math.floor(value / Math.pow(2, i)) % 2);
  }
}

/**
 * Build a minimal core segment: version 2, the two timestamps, then zeros,
 * long enough for the CMP's readTcTimestamps() to accept it. Only the two
 * timestamp fields are read back from it.
 */
function legacyTcString(created, lastUpdated) {
  const bits = [];
  pushBits(bits, 2, 6);
  pushBits(bits, created, 36);
  pushBits(bits, lastUpdated, 36);
  while (bits.length < 230) bits.push(0);
  while (bits.length % 6 !== 0) bits.push(0);
  let out = '';
  for (let i = 0; i < bits.length; i += 6) {
    let v = 0;
    for (let b = 0; b < 6; b++) v = (v << 1) | bits[i + b];
    out += CHARS.charAt(v);
  }
  return out + '.IAAA';
}

function decodeTimestamps(tcString) {
  const bits = [];
  for (const ch of (tcString || '').split('.')[0]) {
    const v = CHARS.indexOf(ch);
    for (let b = 5; b >= 0; b--) bits.push((v >> b) & 1);
  }
  const read = (offset, length) => {
    let value = 0;
    for (let i = 0; i < length; i++) value = (value * 2) + (bits[offset + i] || 0);
    return value;
  };
  return { created: read(6, 36), lastUpdated: read(42, 36) };
}

function utcMidnightDs(ms) {
  const d = new Date(ms);
  return Math.round(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()) / 100);
}

const ACCEPT_ALL = 'consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,performance:yes,marketing:yes';

/**
 * Boot the real CMP. `euconsent` seeds a pre-existing TC cookie (a returning
 * visitor); `consent` seeds the FAZ consent cookie.
 */
function boot({ euconsent = null, consent = null } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  if (consent) window.document.cookie = 'fazcookie-consent=' + encodeURIComponent(consent);
  if (euconsent) window.document.cookie = 'euconsent-v2=' + euconsent;
  window._fazTcfConfig = {
    cmpId: 400, cmpVersion: 1, gvlVersion: 100,
    vendors: {}, selectedVendors: [], consentRevision: 1, purposeOneTreatment: false,
  };
  window.console.error = () => {};
  window.console.warn = () => {};
  window.eval(CMP_CODE);
  return window;
}

function cookieTc(window) {
  const m = window.document.cookie.match(/euconsent-v2=([^;]+)/);
  return m ? m[1] : '';
}

function getTcString(window) {
  let out = '';
  window.__tcfapi('getTCData', 2, (d) => { out = d.tcString; });
  return out;
}

function decideAcceptAll(window) {
  window.document.cookie = 'fazcookie-consent=' + encodeURIComponent(ACCEPT_ALL);
  window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_update', {
    detail: { action: 'all', accepted: ['necessary', 'functional', 'analytics', 'performance', 'marketing'], rejected: [] },
  }));
}

console.log('\nTC string Created/LastUpdated are equal and day-level (jsdom, real tcf-cmp.js)');

const today = utcMidnightDs(Date.now());

// 1. First decision ever: both fields are today's UTC midnight.
{
  const w = boot();
  decideAcceptAll(w);
  const ts = decodeTimestamps(cookieTc(w));
  eq('first decision: Created equals LastUpdated', ts.created, ts.lastUpdated);
  eq('first decision: LastUpdated is a whole UTC day', ts.lastUpdated % DAY_DS, 0);
  eq('first decision: the day is today (UTC)', ts.lastUpdated, today);
}

// 2. A later decision by a returning visitor: Created is NOT preserved from
//    the earlier string — both fields move to the day of this update.
{
  const twentyDaysAgoAt0512 = today - (20 * DAY_DS) + (5 * 36000) + (12 * 600) + 7; // decisecond precision, as older releases wrote it
  const w = boot({ euconsent: legacyTcString(twentyDaysAgoAt0512, twentyDaysAgoAt0512), consent: ACCEPT_ALL });
  decideAcceptAll(w);
  const ts = decodeTimestamps(cookieTc(w));
  eq('re-consent: Created equals LastUpdated', ts.created, ts.lastUpdated);
  eq('re-consent: Created is not the twenty-day-old value', ts.created === twentyDaysAgoAt0512, false);
  eq('re-consent: both are today (UTC)', ts.created, today);
  eq('re-consent: getTCData returns the string that was written', getTcString(w), cookieTc(w));
}

// 3. A page load that only re-reads a conformant string keeps its day: a
//    re-read is not an update and must not move the timestamp forward.
{
  const threeDaysAgo = today - (3 * DAY_DS);
  const w = boot({ euconsent: legacyTcString(threeDaysAgo, threeDaysAgo), consent: ACCEPT_ALL });
  const ts = decodeTimestamps(getTcString(w));
  eq('re-read: Created is kept', ts.created, threeDaysAgo);
  eq('re-read: LastUpdated is kept', ts.lastUpdated, threeDaysAgo);
  eq('re-read: the cookie rewritten at load carries the same values', decodeTimestamps(cookieTc(w)).lastUpdated, threeDaysAgo);
}

// 4. A string written by an earlier release (decisecond precision, Created
//    older than LastUpdated) is brought into shape on re-read, on the day it
//    was last updated, without claiming a new update.
{
  const createdLegacy = today - (30 * DAY_DS) + 123457;
  const updatedLegacy = today - (4 * DAY_DS) + 654321;
  const w = boot({ euconsent: legacyTcString(createdLegacy, updatedLegacy), consent: ACCEPT_ALL });
  const ts = decodeTimestamps(getTcString(w));
  eq('legacy re-read: Created equals LastUpdated', ts.created, ts.lastUpdated);
  eq('legacy re-read: the value is the UTC midnight of the last update', ts.lastUpdated, today - (4 * DAY_DS));
}

// 5. Before any decision, the string handed to vendors on tcloaded is also
//    day-level (it is a string, so it must be well-formed).
{
  const w = boot();
  const ts = decodeTimestamps(getTcString(w));
  eq('pre-decision string: Created equals LastUpdated', ts.created, ts.lastUpdated);
  eq('pre-decision string: day-level', ts.created % DAY_DS, 0);
}

console.log(`\ntcf-timestamps-day-level: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
