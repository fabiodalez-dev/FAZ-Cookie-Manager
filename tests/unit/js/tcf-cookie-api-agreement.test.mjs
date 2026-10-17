/**
 * The euconsent-v2 cookie and the TCF API must carry the same TC string.
 *
 * A vendor reads the visitor's choice in one of two ways: the euconsent-v2
 * cookie (server-side and pixel integrations) or __tcfapi (getTCData, or the
 * useractioncomplete event of a listener). If the two disagree, a vendor is
 * acting on a choice the visitor did not make.
 *
 * The sequence pinned here is the one of the E2E test "TCF writes Created and
 * LastUpdated..." in pr-2026-04-19-audit.spec.ts: Accept All, reopen the
 * preferences, switch analytics off, Save. script.js writes the consent
 * records (fazcookie-consent, fazVendorLI, fazVendorConsent) and only THEN
 * dispatches fazcookie_consent_update (_fazAcceptCookies: _fazSaveVendorConsent
 * before _fazFireEvent); the helpers below keep that order. The checks:
 *
 *  - inside the useractioncomplete callback, in the same task, the cookie
 *    already holds the string the event delivers;
 *  - getTCData returns that same string at once, after the microtasks and
 *    after a macrotask;
 *  - one decision is one useractioncomplete, and one euconsent-v2 cookie.
 *
 * Switching off a category the visitor had granted makes script.js reload the
 * page (_fazAfterConsent: a running script cannot be unloaded). The last block
 * replays that reload: the stub from class-frontend.php answers `ping` with
 * cmpStatus "stub" until the CMP replaces it — the IAB-specified answer, and
 * the reason the E2E test, which polled straight through the reload, read
 * "stub" or lost its execution context — and a getTCData queued on the stub is
 * answered with the string the cookie holds.
 */
import { JSDOM, CookieJar } from 'jsdom';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const CMP_CODE = readFileSync(resolve(HERE, '../../../frontend/js/tcf-cmp.js'), 'utf8');
const PHP = readFileSync(resolve(HERE, '../../../frontend/class-frontend.php'), 'utf8');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) { console.log(`  \x1b[32mPASS\x1b[0m ${label}`); passed++; }
  else { console.log(`  \x1b[31mFAIL\x1b[0m ${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`); failed++; }
}

const stubMatch = PHP.match(/\$tcf_stub = '((?:[^'\\]|\\.)*)';/);
if (!stubMatch) {
  console.log('  \x1b[31mFAIL\x1b[0m the __tcfapi stub was not found in class-frontend.php');
  process.exit(1);
}
const STUB_CODE = stubMatch[1];

const URL = 'http://localhost/';
const CATEGORIES = ['necessary', 'functional', 'analytics', 'performance', 'marketing'];

// Two disclosed vendors with legitimate interest, so the vendor consent and
// the Right-to-Object records both feed the string.
const TCF_CONFIG = {
  cmpId: 400, cmpVersion: 1, gvlVersion: 100, consentRevision: 1, purposeOneTreatment: false,
  selectedVendors: [1, 8],
  vendors: {
    1: { id: 1, name: 'Ads vendor', purposes: [1, 2, 3, 4], legIntPurposes: [7] },
    8: { id: 8, name: 'Measurement vendor', purposes: [1, 9], legIntPurposes: [8, 10] },
  },
};

/** script.js _fazEncodeVendorBits. */
function vendorBits(ids) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  const set = {};
  let max = 0;
  ids.forEach((id) => { set[id] = true; if (id > max) max = id; });
  let out = '';
  for (let base = 1; base <= max; base += 6) {
    let value = 0;
    for (let bit = 0; bit < 6; bit++) value = (value << 1) | (set[base + bit] ? 1 : 0);
    out += alphabet.charAt(value);
  }
  return out.replace(/A+$/, '');
}

/**
 * One page view: the stub inline in <head>, `beforeCmp` (a vendor tag running
 * during the parse), then the deferred CMP. Pages built on the same jar share
 * cookies, which is what a reload is.
 */
function page(jar, beforeCmp) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: URL, cookieJar: jar });
  const { window } = dom;
  window._fazTcfConfig = JSON.parse(JSON.stringify(TCF_CONFIG));
  window.console.error = () => {};
  window.console.warn = () => {};
  window.eval(STUB_CODE);
  if (beforeCmp) beforeCmp(window);
  window.eval(CMP_CODE);
  return window;
}

function tcCookies(window) {
  return window.document.cookie.split(/;\s*/).filter((c) => c.indexOf('euconsent-v2=') === 0).map((c) => c.substring(13));
}

function cookieTc(window) {
  const all = tcCookies(window);
  return all.length ? all[0] : '';
}

function getTcString(window) {
  let out = null;
  window.__tcfapi('getTCData', 2, (d) => { out = d.tcString; });
  return out;
}

/** Records every event a listener receives, with the cookie read in the callback. */
function listen(window) {
  const events = [];
  window.__tcfapi('addEventListener', 2, (data, ok) => {
    if (!ok) return;
    events.push({ status: data.eventStatus, tcString: data.tcString, cookieInCallback: cookieTc(window) });
  });
  return events;
}

/**
 * Persist a decision in script.js's order, then announce it.
 * `granted` = categories set to yes, `vendors` = vendors consented.
 */
function decide(window, action, granted, vendors) {
  const doc = window.document;
  const pairs = ['consentid:abc', 'consent:yes', 'action:yes'].concat(
    CATEGORIES.map((slug) => slug + ':' + (slug === 'necessary' || granted.indexOf(slug) !== -1 ? 'yes' : 'no'))
  );
  doc.cookie = 'fazcookie-consent=' + encodeURIComponent(pairs.join(',')) + ';path=/;SameSite=Lax';
  const disclosed = TCF_CONFIG.selectedVendors;
  const objected = disclosed.filter((id) => vendors.indexOf(id) === -1);
  const li = ['p7:yes', 'p8:yes', 'p10:yes'].concat(objected.length ? ['v:' + vendorBits(objected)] : []);
  doc.cookie = 'fazVendorLI=' + li.join(',') + ';path=/;SameSite=Lax';
  const consented = vendors.length === disclosed.length ? '*' : vendorBits(vendors);
  doc.cookie = 'fazVendorConsent=d:' + vendorBits(disclosed) + ',c:' + consented + ';path=/;SameSite=Lax';
  doc.dispatchEvent(new window.CustomEvent('fazcookie_consent_update', {
    detail: { action, accepted: CATEGORIES.filter((s) => s === 'necessary' || granted.indexOf(s) !== -1), rejected: CATEGORIES.filter((s) => s !== 'necessary' && granted.indexOf(s) === -1) },
  }));
}

const settle = () => new Promise((r) => setTimeout(r, 0));

console.log('\neuconsent-v2 and __tcfapi agree after Accept All -> revisit -> analytics off -> Save (jsdom, real tcf-cmp.js)');

const jar = new CookieJar();
const pageA = page(jar);
const eventsA = listen(pageA);

// 1. Accept All.
decide(pageA, 'all', ['functional', 'analytics', 'performance', 'marketing'], [1, 8]);
const acceptUac = eventsA.filter((e) => e.status === 'useractioncomplete');
eq('accept all: exactly one useractioncomplete', acceptUac.length, 1);
const acceptString = acceptUac.length ? acceptUac[0].tcString : '';
eq('accept all: the cookie already holds the delivered string inside the callback', acceptUac.length ? acceptUac[0].cookieInCallback : null, acceptString);
eq('accept all: getTCData returns the cookie string', getTcString(pageA), cookieTc(pageA));

// 2. Revisit: the banner is shown again. Showing it decides nothing, so the
//    stored string must not move.
pageA.window._fazBannerLoaded = true;
pageA.document.dispatchEvent(new pageA.CustomEvent('fazcookie_banner_loaded'));
eq('revisit: cmpuishown is announced', eventsA.some((e) => e.status === 'cmpuishown'), true);
eq('revisit: the cookie is untouched by showing the UI', cookieTc(pageA), acceptString);

// 3. Analytics off, Save My Preferences (custom). Vendor 8 measures, so the
//    visitor leaves it off too.
const before = eventsA.length;
decide(pageA, 'custom', ['functional', 'performance', 'marketing'], [1]);
const saveUac = eventsA.slice(before).filter((e) => e.status === 'useractioncomplete');
eq('save: exactly one useractioncomplete', saveUac.length, 1);
const savedString = saveUac.length ? saveUac[0].tcString : '';
eq('save: the delivered string is a new one (Purpose 10 and vendor 8 cleared)', savedString !== '' && savedString !== acceptString, true);
eq('save: inside the callback the cookie already holds the delivered string', saveUac.length ? saveUac[0].cookieInCallback : null, savedString);
eq('save: getTCData in the same task returns the delivered string', getTcString(pageA), savedString);
eq('save: one euconsent-v2 cookie, not two', tcCookies(pageA).length, 1);

await Promise.resolve();
await Promise.resolve();
eq('save: after the microtasks, cookie and getTCData still agree', getTcString(pageA) === cookieTc(pageA) && cookieTc(pageA) === savedString, true);
await settle();
eq('save: after a macrotask, cookie and getTCData still agree', getTcString(pageA) === cookieTc(pageA) && cookieTc(pageA) === savedString, true);
eq('save: no further useractioncomplete followed', eventsA.slice(before).filter((e) => e.status === 'useractioncomplete').length, 1);

// 4. The revocation reload. A vendor tag pings and asks for the TC data while
//    only the stub is there.
const early = {};
const pageB = page(jar, (w) => {
  w.__tcfapi('ping', 2, (p) => { early.ping = p.cmpStatus; });
  w.__tcfapi('getTCData', 2, (d) => { early.tcString = d.tcString; early.cookieInCallback = cookieTc(w); });
  early.answeredBeforeCmp = 'tcString' in early;
});
eq('reload: the stub answers ping with cmpStatus "stub" before the CMP runs', early.ping, 'stub');
eq('reload: the stub queues getTCData instead of answering it', early.answeredBeforeCmp, false);
eq('reload: the queued getTCData is answered with the saved string', early.tcString, savedString);
eq('reload: and with the string the cookie holds at that moment', early.cookieInCallback, savedString);
let lateStatus = '';
pageB.__tcfapi('ping', 2, (p) => { lateStatus = p.cmpStatus; });
eq('reload: once the CMP has replaced the stub, ping says loaded', lateStatus, 'loaded');
eq('reload: the cookie rewritten at load is the saved string', cookieTc(pageB), savedString);
eq('reload: still one euconsent-v2 cookie (the load-time write replaced the decision, not shadowed it)', tcCookies(pageB).length, 1);
eq('reload: getTCData agrees with the cookie', getTcString(pageB), cookieTc(pageB));

console.log(`\ntcf-cookie-api-agreement: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
