/**
 * JS unit test (jsdom) — the TCF vendor records hold a real vendor list, and a
 * vendor list that grew since the visitor chose asks the visitor again.
 *
 * fazVendorConsent carried one `id:yes|no` pair per selected vendor, about nine
 * bytes each, and _fazSaveVendorConsent() did not write it at all past 3800
 * bytes. faz_gvl_selected_vendors has no limit, so with ~420 vendors Accept All
 * left NO record — and tcf-cmp.js, which (correctly) consents a vendor only
 * when the record says so, signalled no vendor consent at all. Nothing asked
 * the visitor again either: the banner stayed closed for the life of the
 * consent cookie. The same silent state followed every visitor who accepted
 * before TCF was switched on, and every vendor the operator added later.
 *
 * The record is now a bitfield of the vendors disclosed plus a bitfield (or
 * `*`) of the ones consented, bounded by the highest vendor id rather than by
 * the count; fazVendorLI carries its objected vendors the same way. A visitor
 * who already acted is asked again when a selected vendor is missing from the
 * disclosed set, through the same invalidation a consent revision uses. A
 * vendor removed from the list asks nothing new and does not.
 *
 * Run: node tests/unit/js/tcf-vendor-record-compact.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
const TCF = readFileSync(resolve(HERE, '../../../frontend/js/tcf-cmp.js'), 'utf8');

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

const B64URL = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
/** Independent encoder for fixtures: bit id-1, MSB first, six bits per char. */
function bits(ids) {
  const set = new Set(ids);
  const max = Math.max(0, ...ids);
  let out = '';
  for (let base = 1; base <= max; base += 6) {
    let v = 0;
    for (let b = 0; b < 6; b += 1) v = (v << 1) | (set.has(base + b) ? 1 : 0);
    out += B64URL.charAt(v);
  }
  return out.replace(/A+$/, '');
}

// 600 vendors spread over ids 3..1201, the way GVL ids are spread: the old
// per-pair record for them is ~6000 bytes, well past its 3800 cap.
const IDS = Array.from({ length: 600 }, (_, i) => 3 + 2 * i);
const LI_PURPOSES = [2, 7];
function vendorsFor(ids) {
  return ids.map((id) => ({ id, name: `V${id}`, purposes: [1, 3, 4], legIntPurposes: LI_PURPOSES, features: [] }));
}
function gvlFor(ids) {
  const out = {};
  ids.forEach((id) => { out[id] = { name: `V${id}`, purposes: [1, 3, 4], legIntPurposes: LI_PURPOSES, features: [], specialFeatures: [] }; });
  return out;
}

const ACCEPTED = 'consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes';

/**
 * Evaluate the real script.js. `cookies` are present before it runs (a
 * returning visitor); the DOMContentLoaded bootstrap is neutralised, so only
 * the module start-up runs — which is where a consent revision invalidates.
 */
function bootRuntime({ ids = IDS, cookies = [], iabEnabled = true, law = 'gdpr' } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;
  cookies.forEach((c) => { window.document.cookie = c; });
  window._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'functional' },
      { slug: 'analytics' },
      { slug: 'marketing' },
    ],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    _i18n: {},
    _expiry: 180,
    _activeLaw: law,
    _iabEnabled: iabEnabled,
    _iabVendors: vendorsFor(ids),
    _bannerConfig: { settings: { type: 'box', preferenceCenterType: 'popup', applicableLaw: law }, config: {} },
  };
  Object.defineProperty(window.document, 'readyState', { get: () => 'loading', configurable: true });
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, cb, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, cb, ...rest));
  window.setTimeout = () => 0;
  window.console.error = () => {};
  window.console.warn = () => {};
  window.eval(SCRIPT);
  window.document.addEventListener = realAdd;
  return window;
}

/** Load tcf-cmp.js into the same window and return getTCData(). */
function tcData(window, ids = IDS) {
  window._fazTcfConfig = {
    cmpId: 400, gvlVersion: 100, selectedVendors: ids, vendors: gvlFor(ids), purposeOneTreatment: false,
  };
  window.eval(TCF);
  let data = null;
  window.__tcfapi('getTCData', 2, (d, ok) => { if (ok) data = d; });
  return data;
}

function count(map) {
  return Object.keys(map || {}).filter((k) => map[k] === true).length;
}

/** Record a visitor choice the way _fazAcceptCookies() does, then the vendor records. */
function decide(window, choice) {
  const set = window.fazcookie._fazSetInStore;
  set('consentid', 'abc');
  set('action', 'yes');
  set('consent', choice === 'reject' ? 'no' : 'yes');
  ['necessary', 'functional', 'analytics', 'marketing'].forEach((slug) => {
    set(slug, slug === 'necessary' || choice !== 'reject' ? 'yes' : 'no');
  });
  window._fazSaveVendorConsent(choice);
}

function cookieValue(window, name) {
  const m = window.document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));
  return m ? m[1] : null;
}

function run() {
  console.log('\nTCF vendor records: compact, complete, and re-asked when the list grows');

  // 1. Accept All with 600 selected vendors: every one of them is consented.
  {
    const w = bootRuntime();
    decide(w, 'all');
    const record = cookieValue(w, 'fazVendorConsent');
    eq('Accept All with 600 vendors writes a vendor record', record !== null, true);
    eq('the record stays within its 1200-byte budget', record !== null && record.length <= 1200, true);
    const d = tcData(w);
    eq('getTCData reports all 600 vendors consented', count(d && d.vendor.consents), 600);
    eq('and legitimate interest established for all 600', count(d && d.vendor.legitimateInterests), 600);
    eq('the TC string itself was persisted', cookieValue(w, 'euconsent-v2') !== null, true);
    // The whole Cookie header the site receives must stay under the common
    // per-line server limit (Apache LimitRequestFieldSize 8190, nginx 8k).
    const header = w.document.cookie.length;
    eq(`the FAZ cookies together stay well under an 8 KB header (${header} bytes)`, header < 6000, true);
  }

  // 2. Reject All with 600 vendors: nothing consented, every LI objected, and
  //    the LI record still fits (the per-pair one did not and fell back).
  {
    const w = bootRuntime();
    decide(w, 'reject');
    const li = cookieValue(w, 'fazVendorLI');
    eq('Reject All writes an LI record within budget', li !== null && li.length <= 1200, true);
    const d = tcData(w);
    eq('Reject All: no vendor consented', count(d && d.vendor.consents), 0);
    eq('Reject All: no vendor LI established', count(d && d.vendor.legitimateInterests), 0);
  }

  // 3. A custom choice over 600 vendors survives exactly, consent and objection:
  //    every even-indexed vendor on, and of those every fourth with its LI
  //    objected while the LI purposes stay established. The per-pair LI record
  //    for this does not fit, and its fallback dropped the vendor objections —
  //    the objected vendors kept LI.
  {
    const w = bootRuntime();
    const doc = w.document;
    const on = new Set();
    const objected = new Set();
    IDS.forEach((id, i) => {
      const cb = doc.createElement('input'); cb.type = 'checkbox'; cb.id = `fazVendorSwitch${id}`;
      cb.checked = i % 2 === 0; if (cb.checked) on.add(id);
      const li = doc.createElement('input'); li.type = 'checkbox'; li.id = `fazVendorLISwitch${id}`;
      li.checked = !(i % 4 === 0); if (cb.checked && !li.checked) objected.add(id);
      doc.body.appendChild(cb); doc.body.appendChild(li);
    });
    LI_PURPOSES.forEach((pid) => {
      const p = doc.createElement('input'); p.type = 'checkbox'; p.id = `fazPurposeLISwitch${pid}`; p.checked = true;
      doc.body.appendChild(p);
    });
    decide(w, 'custom');
    const d = tcData(w);
    const consents = d ? d.vendor.consents : {};
    eq('custom: exactly the 300 switched-on vendors are consented', count(consents), 300);
    eq('custom: a switched-on vendor is consented', consents[String(IDS[0])], true);
    eq('custom: a switched-off vendor is not', consents[String(IDS[1])] === true, false);
    const liMap = d ? d.vendor.legitimateInterests : {};
    eq(`custom: the ${objected.size} vendor-level LI objections all hold`,
      [...objected].every((id) => liMap[String(id)] !== true), true);
    eq('custom: a vendor kept on without objection keeps LI', liMap[String(IDS[2])], true);
  }

  // 4. The format of earlier releases is still read, by both layers.
  {
    const w = bootRuntime({
      ids: [3, 5],
      cookies: [`fazcookie-consent=${encodeURIComponent(ACCEPTED)}`, 'fazVendorConsent=3:yes,5:no'],
    });
    const rt = w._fazReadVendorConsent();
    eq('legacy record: the runtime reads vendor 3 as consented', rt[3], true);
    eq('legacy record: and vendor 5 as refused', rt[5], false);
    eq('legacy record covering the list: the consent stands', w.fazcookie._fazGetFromStore('action'), 'yes');
    const d = tcData(w, [3, 5]);
    eq('legacy record: tcf-cmp signals vendor 3 consented', d.vendor.consents['3'], true);
    eq('legacy record: and vendor 5 not', d.vendor.consents['5'] === true, false);
  }

  // 5. The list grew since the visitor chose: they are asked again.
  {
    const w = bootRuntime({
      ids: [1, 3, 5, 7],
      cookies: [`fazcookie-consent=${encodeURIComponent(ACCEPTED)}`, `fazVendorConsent=d:${bits([1, 3, 5])},c:*`],
    });
    eq('a selected vendor missing from the record re-opens the decision', w.fazcookie._fazGetFromStore('action'), '');
    eq('and the stored consent cookie is gone', cookieValue(w, 'fazcookie-consent'), null);
    eq('and so is the stale vendor record', cookieValue(w, 'fazVendorConsent'), null);
  }

  // 6. Same list, and a list that only lost a vendor: nothing to ask.
  {
    const same = bootRuntime({
      ids: [1, 3, 5],
      cookies: [`fazcookie-consent=${encodeURIComponent(ACCEPTED)}`, `fazVendorConsent=d:${bits([1, 3, 5])},c:*`],
    });
    eq('unchanged vendor list: the consent stands', same.fazcookie._fazGetFromStore('action'), 'yes');
    const shrunk = bootRuntime({
      ids: [1, 5],
      cookies: [`fazcookie-consent=${encodeURIComponent(ACCEPTED)}`, `fazVendorConsent=d:${bits([1, 3, 5])},c:*`],
    });
    eq('a vendor removed from the list: the consent stands', shrunk.fazcookie._fazGetFromStore('action'), 'yes');
    const d = tcData(shrunk, [1, 5]);
    eq('and the remaining vendors keep their consent', count(d.vendor.consents), 2);
  }

  // 7. A legacy record missing a vendor counts the same as a compact one.
  {
    const w = bootRuntime({
      ids: [3, 5, 9],
      cookies: [`fazcookie-consent=${encodeURIComponent(ACCEPTED)}`, 'fazVendorConsent=3:yes,5:no'],
    });
    eq('legacy record without a selected vendor re-opens the decision', w.fazcookie._fazGetFromStore('action'), '');
  }

  // 8. Accepted before TCF was switched on: no record at all.
  {
    const w = bootRuntime({ cookies: [`fazcookie-consent=${encodeURIComponent(ACCEPTED)}`] });
    eq('no vendor record for a visitor who acted: the decision is re-opened', w.fazcookie._fazGetFromStore('action'), '');
  }

  // 9. Where the check must stay silent.
  {
    const off = bootRuntime({ iabEnabled: false, cookies: [`fazcookie-consent=${encodeURIComponent(ACCEPTED)}`] });
    eq('TCF off: no vendor record needed', off.fazcookie._fazGetFromStore('action'), 'yes');
    const ccpa = bootRuntime({ law: 'ccpa', cookies: [`fazcookie-consent=${encodeURIComponent(ACCEPTED)}`] });
    eq('opt-out law (CCPA): the earlier decision is not re-solicited', ccpa.fazcookie._fazGetFromStore('action'), 'yes');
    const undecided = bootRuntime({
      cookies: [`fazcookie-consent=${encodeURIComponent('consentid:abc,consent:no,action:yes,undecided:1,necessary:yes,marketing:no')}`],
    });
    eq('a signal-created undecided record is left alone', undecided.fazcookie._fazGetFromStore('action'), 'yes');
    const first = bootRuntime();
    eq('a first visit has nothing to invalidate', first.fazcookie._fazGetFromStore('action') || '', '');
  }

  // 10. A damaged compact record is no record: fail closed, and re-asked.
  {
    const w = bootRuntime({
      ids: [1],
      cookies: [`fazcookie-consent=${encodeURIComponent(ACCEPTED)}`, 'fazVendorConsent=d:!!,c:*'],
    });
    eq('damaged record: re-opened like a missing one', w.fazcookie._fazGetFromStore('action'), '');
  }

  console.log(`\n  tcf-vendor-record-compact: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
