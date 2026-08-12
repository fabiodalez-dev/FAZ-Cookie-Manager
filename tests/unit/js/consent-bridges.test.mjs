/**
 * JS unit test (jsdom) — the two consent bridges must report a RETURNING
 * visitor, not just a deciding one.
 *
 * This is the defect the branch exists for. `fazcookie_consent_update` fires
 * when somebody makes a choice; it does not fire on the pages after that, when
 * the choice is already in the cookie. So on every page but the first:
 *
 *   - wca.js told WP-Consent-API-aware plugins nothing at all, leaving them on
 *     their own default, which is deny. A visitor who had accepted analytics was
 *     reported as having refused it for the rest of the session.
 *   - microsoft-consent.js pushed `default: denied` to UET at load and never
 *     followed it with an update, and never called clarity('consent').
 *
 * `fazcookie_consent_ready` closes that, and these tests pin it — including the
 * duplicate-suppression that stops a first visit, where BOTH events arrive with
 * identical values, from reporting the same state twice. Suppressing a repeat
 * matters because pushing it again re-dispatches wp_consent_type_defined, and
 * third-party listeners on that hook are not ours to reason about.
 *
 * The bridges are loaded as real files with their dependencies faked, so what is
 * asserted is the shipped behaviour rather than a description of it.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const WCA = resolve(HERE, '../../../frontend/js/wca.js');
const MS = resolve(HERE, '../../../frontend/js/microsoft-consent.js');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
  }
}
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/** A jsdom window with the WP Consent API faked and its calls recorded. */
function loadWca(consent) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://example.test/',
  });
  const { window } = dom;
  window.calls = [];
  window.typeEvents = 0;
  window.wp_set_consent = (cat, status) => window.calls.push(`${cat}:${status}`);
  window.getFazConsent = () => consent;
  window._fazGsk = false;
  window.document.addEventListener('wp_consent_type_defined', () => {
    window.typeEvents += 1;
  });
  // The file is a module-scoped script in production; `const` at top level in
  // an eval would leak differently, so it is wrapped exactly as the browser
  // scopes it.
  window.eval(`(function(){${readFileSync(WCA, 'utf8')}})()`);
  return window;
}

/** A jsdom window with UET and Clarity faked. */
function loadMicrosoft(opts = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://example.test/',
  });
  const { window } = dom;
  window.uetq = [];
  window.clarityCalls = [];
  if (opts.uet !== false) {
    window._fazMicrosoftUET = true;
  }
  if (opts.clarity) {
    window._fazMicrosoftClarity = true;
    window.clarity = (...args) => window.clarityCalls.push(args.join(','));
  }
  window.eval(readFileSync(MS, 'utf8'));
  return window;
}

function fire(window, type, accepted) {
  const ev = new window.CustomEvent(type, { detail: { accepted } });
  window.document.dispatchEvent(ev);
}

console.log('consent bridges — returning visitors are reported too (jsdom)');

// ── wca.js ────────────────────────────────────────────────────────────────
// 1-4. The ready event alone must produce a full report. Before this, a
// returning visitor produced NOTHING here.
{
  const w = loadWca({
    activeLaw: 'gdpr',
    isUserActionCompleted: true,
    categories: { necessary: true, analytics: true, marketing: false, functional: true },
  });
  fire(w, 'fazcookie_consent_ready', ['necessary', 'analytics', 'functional']);
  check('wca: the ready event alone reports consent', w.calls.length > 0);
  check('wca: analytics maps to BOTH statistics categories', same(
    w.calls.filter((c) => c.startsWith('statistics')),
    ['statistics:allow', 'statistics-anonymous:allow'],
  ));
  check('wca: a denied category is reported as deny, not omitted', w.calls.includes('marketing:deny'));
  check('wca: functional maps to preferences', w.calls.includes('preferences:allow'));
}

// 5-6. The consent TYPE travels with it, and CCPA is opt-out.
{
  const w = loadWca({ activeLaw: 'ccpa', isUserActionCompleted: true, categories: { analytics: false } });
  fire(w, 'fazcookie_consent_ready', []);
  check('wca: CCPA sets the opt-out consent type', w.wp_consent_type === 'optout');
  check('wca: and announces it once', w.typeEvents === 1);
}
{
  const w = loadWca({ activeLaw: null, isUserActionCompleted: false, categories: { analytics: false } });
  fire(w, 'fazcookie_consent_ready', []);
  check('wca: an unknown law falls back to opt-in, the stricter model', w.wp_consent_type === 'optin');
}

// 7-9. First visit: both events arrive with identical values. One state is one
// report — a repeat would re-dispatch wp_consent_type_defined at listeners we
// do not control.
{
  const w = loadWca({ activeLaw: 'gdpr', isUserActionCompleted: true, categories: { analytics: true } });
  fire(w, 'fazcookie_consent_ready', ['analytics']);
  const afterFirst = w.calls.length;
  fire(w, 'fazcookie_consent_update', ['analytics']);
  check('wca: an identical second event is suppressed', w.calls.length === afterFirst);
  check('wca: and does not re-announce the consent type', w.typeEvents === 1);
}
// 10. A genuine change must still get through the same guard.
{
  const consent = { activeLaw: 'gdpr', isUserActionCompleted: true, categories: { analytics: false } };
  const w = loadWca(consent);
  fire(w, 'fazcookie_consent_ready', []);
  consent.categories = { analytics: true };
  fire(w, 'fazcookie_consent_update', ['analytics']);
  check('wca: a real change is reported despite the repeat guard', w.calls.includes('statistics:allow'));
}
// 11. Unknown categories are skipped rather than mapped to something arbitrary.
{
  const w = loadWca({ activeLaw: 'gdpr', isUserActionCompleted: true, categories: { unknown_cat: true } });
  fire(w, 'fazcookie_consent_ready', ['unknown_cat']);
  check('wca: an unmapped category is not guessed at', w.calls.length === 0);
}
// 12. Back-compat: cookies written before the advertisement→marketing rename.
{
  const w = loadWca({ activeLaw: 'gdpr', isUserActionCompleted: true, categories: { advertisement: true } });
  fire(w, 'fazcookie_consent_ready', ['advertisement']);
  check('wca: the pre-rename advertisement slug still maps to marketing', w.calls.includes('marketing:allow'));
}

// ── microsoft-consent.js ──────────────────────────────────────────────────
// 13-15. UET must hear the returning visitor's grant, not only the load-time
// default denial.
{
  const w = loadMicrosoft();
  const before = w.uetq.length;
  fire(w, 'fazcookie_consent_ready', ['marketing', 'analytics']);
  // uetq.push('consent', 'update', {...}) appends THREE items, not one object —
  // Microsoft's queue is positional, so the slice is compared whole.
  const pushes = w.uetq.slice(before);
  check('uet: the ready event produces a consent update', pushes.length === 3 && pushes[1] === 'update');
  check('uet: a granted marketing category becomes ad_storage granted', same(pushes, ['consent', 'update', { ad_storage: 'granted', analytics_storage: 'granted' }]));
}
{
  const w = loadMicrosoft();
  const before = w.uetq.length;
  fire(w, 'fazcookie_consent_ready', ['necessary']);
  check('uet: necessary-only is reported as denied on both storages', same(w.uetq.slice(before), ['consent', 'update', { ad_storage: 'denied', analytics_storage: 'denied' }]));
}
// 16-17. The same duplicate suppression, and a real change still lands.
{
  const w = loadMicrosoft();
  fire(w, 'fazcookie_consent_ready', ['analytics']);
  const afterFirst = w.uetq.length;
  fire(w, 'fazcookie_consent_update', ['analytics']);
  check('uet: an identical second event is suppressed', w.uetq.length === afterFirst);
  fire(w, 'fazcookie_consent_update', ['analytics', 'marketing']);
  check('uet: a real change is still pushed', w.uetq.length === afterFirst + 3);
}
// 18. A malformed event must not throw or invent a grant.
{
  const w = loadMicrosoft();
  const before = w.uetq.length;
  let threw = false;
  try {
    w.document.dispatchEvent(new w.CustomEvent('fazcookie_consent_ready'));
  } catch (e) {
    threw = true;
  }
  check('uet: an event with no detail neither throws nor grants', !threw && same(w.uetq.slice(before), ['consent', 'update', { ad_storage: 'denied', analytics_storage: 'denied' }]));
}
// 19-21. Clarity.
{
  const w = loadMicrosoft({ clarity: true });
  fire(w, 'fazcookie_consent_ready', ['analytics']);
  check('clarity: consent is granted for a returning visitor with analytics', w.clarityCalls.includes('consent'));
}
{
  const w = loadMicrosoft({ clarity: true });
  fire(w, 'fazcookie_consent_ready', ['marketing']);
  check('clarity: marketing alone does not grant it', w.clarityCalls.length === 0);
}
{
  const w = loadMicrosoft({ clarity: true });
  fire(w, 'fazcookie_consent_ready', []);
  check('clarity: an empty acceptance grants nothing', w.clarityCalls.length === 0);
}
// 22. Clarity absent must not break the UET path, and vice versa.
{
  const w = loadMicrosoft({ clarity: false });
  let threw = false;
  try {
    fire(w, 'fazcookie_consent_ready', ['analytics']);
  } catch (e) {
    threw = true;
  }
  check('the UET path works with Clarity switched off', !threw && w.uetq.length > 0);
}

// 23-25. Both bridges listen for BOTH events. Asserted structurally as well as
// behaviourally: a future edit that drops the ready listener would restore the
// original defect silently, since the update event alone still passes every
// behavioural test above that fires it.
{
  const wcaSrc = readFileSync(WCA, 'utf8');
  const msSrc = readFileSync(MS, 'utf8');
  check('wca still subscribes to the ready event', wcaSrc.includes('"fazcookie_consent_ready"') || wcaSrc.includes("'fazcookie_consent_ready'"));
  check('microsoft UET still subscribes to the ready event', (msSrc.match(/fazcookie_consent_ready/g) || []).length >= 2);
  check('and both still subscribe to the update event', wcaSrc.includes('fazcookie_consent_update') && msSrc.includes('fazcookie_consent_update'));
}

// ── a bridge that loads AFTER the announcement ─────────────────────────────
// Both files load as their own request and are outside the minification
// pipeline, so a page optimiser that defers or reorders them — or simply a
// slower network for one file — lands them after script.js has already
// announced. Listening alone then misses the only announcement there is, and
// the visitor is back to being reported as denied. The state is recorded on
// window before dispatch so catching up is a read.
{
  const w = loadWca({
    activeLaw: 'gdpr',
    isUserActionCompleted: true,
    categories: { analytics: true, marketing: false },
  });
  // Simulate: the event already fired before this file was evaluated.
  w.calls.length = 0;
  w._fazConsentReady = { accepted: ['analytics'], rejected: ['marketing'], action: 'init' };
  w.eval(`(function(){${readFileSync(WCA, 'utf8')}})()`);
  check('wca: a late-loading bridge recovers the announcement it missed', w.calls.length > 0);
  check('wca: and reports the same categories', w.calls.includes('statistics:allow') && w.calls.includes('marketing:deny'));
}
{
  const w = loadMicrosoft();
  const before = w.uetq.length;
  w._fazConsentReady = { accepted: ['marketing', 'analytics'], action: 'init' };
  w.eval(readFileSync(MS, 'utf8'));
  const pushes = w.uetq.slice(before);
  check('uet: a late-loading bridge recovers it too', pushes.length >= 3 && pushes.includes('update'));
}
{
  const w = loadMicrosoft({ clarity: true });
  w.clarityCalls.length = 0;
  w._fazConsentReady = { accepted: ['analytics'], action: 'init' };
  w.eval(readFileSync(MS, 'utf8'));
  check('clarity: recovers it as well', w.clarityCalls.includes('consent'));
}
// And catching up must not double-report when the listener DID fire.
{
  const w = loadWca({ activeLaw: 'gdpr', isUserActionCompleted: true, categories: { analytics: true } });
  fire(w, 'fazcookie_consent_ready', ['analytics']);
  const afterEvent = w.calls.length;
  w._fazConsentReady = { accepted: ['analytics'], action: 'init' };
  fire(w, 'fazcookie_consent_update', ['analytics']);
  check('wca: catching up does not double-report an identical state', w.calls.length === afterEvent);
}

// script.js must record the state before dispatching, or none of the above can
// work. Asserted on the source: the ordering is the contract.
{
  const src = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
  const fn = src.slice(src.indexOf('function _fazFireConsentReadyEvent'));
  const body = fn.slice(0, fn.indexOf('\n}') + 2);
  const recordAt = body.indexOf('window._fazConsentReady = detail');
  const fireAt = body.indexOf('dispatchEvent');
  check('script.js records the state BEFORE dispatching it', recordAt !== -1 && fireAt !== -1 && recordAt < fireAt);
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
