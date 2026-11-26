/**
 * JS unit test (jsdom) — a hand-declared cookie's toggle blocks the script
 * that sets it (#360, 1.40.0 compliance review M3).
 *
 * THE DEFECT: with per-service consent on, every cookie the administrator
 * declares by hand gets a toggle in the preference centre, but the service was
 * shipped with `patterns: []`. A visitor who accepted Functional and switched
 * the "booking_sess" toggle off still got the booking widget's script — the
 * blocker judged it by category alone — which called the third party and set
 * the cookie again. The refusal deleted the cookie by name and nothing else.
 *
 * THE FIX, client half: the server now ships the row's url_pattern in the
 * declared service's `patterns` and names the service on the matching
 * _providersToBlock entry, so _fazShouldBlockProvider() answers the
 * per-service "no". When the pattern is one a catalogue provider also owns,
 * the entry can name only that provider; the declared owner is asked as well.
 *
 * Runs the real frontend/js/script.js with its DOMContentLoaded bootstrap
 * neutralised.
 *
 * Run: node tests/unit/js/declared-service-script-block.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, cond) {
  if (cond) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

const record = (pairs) => `fazcookie-consent=${encodeURIComponent(pairs)}; path=/`;
const BOOKING = 'https://widget.booking.example/loader.js';
const YOUTUBE = 'https://www.youtube.com/embed/abc';

/**
 * Per-service consent on. "booking_sess" is declared by hand in Functional and
 * names widget.booking.example as its script. "yt_helper" is declared in
 * Functional too and names youtube.com/embed — a pattern the catalogue's
 * YouTube service already owns, so the entry carries `service: 'youtube'`.
 *
 * @param {string}  consent       Consent record pairs.
 * @param {boolean} withPatterns  false = the pre-fix payload (`patterns: []`,
 *                                no owner on the booking entry).
 */
function page(consent, withPatterns = true) {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: 'https://shop.example.test/' });
  const { window: w } = dom;
  w.document.cookie = record(consent);
  const booking = { id: 'booking_sess', label: 'booking_sess', category: 'functional', patterns: withPatterns ? ['widget.booking.example'] : [], cookies: ['booking_sess'], third_party: false, declared: true };
  const helper = { id: 'yt_helper', label: 'yt_helper', category: 'functional', patterns: withPatterns ? ['youtube.com/embed'] : [], cookies: ['yt_helper'], third_party: false, declared: true };
  const youtube = { id: 'youtube', label: 'YouTube', category: 'functional', patterns: ['youtube.com/embed'], cookies: ['VISITOR_INFO1_LIVE'], third_party: true };
  const plain = { id: 'plain_cookie', label: 'plain_cookie', category: 'functional', patterns: [], cookies: ['plain_cookie'], third_party: false, declared: true };
  const bookingEntry = { re: 'widget.booking.example', categories: ['functional'] };
  // What get_store_data() emits: the row's url_pattern under its category,
  // owned by the declared service.
  if (withPatterns) bookingEntry.service = 'booking_sess';
  w._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'functional', isNecessary: false, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    _services: [youtube, booking, helper, plain],
    _declaredServices: [booking, helper, plain],
    _providersToBlock: [
      bookingEntry,
      // A pattern shared with a catalogue provider names the provider only.
      { re: 'youtube.com/embed', categories: ['functional'], service: 'youtube' },
    ],
    _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: true, _perCookieConsent: false, _rootDomain: '', _activeLaw: 'gdpr', _bannerSlug: 'gdpr', _expiry: 180,
    _bannerConfig: { settings: { applicableLaw: 'gdpr' }, behaviours: { respectGPC: false }, config: { revisitConsent: { status: false } } },
    _shortCodes: [], i18n: {}, _i18n: {},
  };
  const realAdd = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  w.eval(SCRIPT);
  w.document.addEventListener = realAdd;
  return w;
}

const blocks = (w, url) => w.eval(`_fazShouldBlockProvider(${JSON.stringify(url)})`);
const BASE = 'consentid:abc,consent:yes,action:yes,necessary:yes,';

console.log('a declared cookie toggle blocks the script that sets it (M3, jsdom)');

{
  const w = page(BASE + 'functional:yes,svc.booking_sess:no');
  check('Functional accepted, booking_sess off: the booking script is blocked', blocks(w, BOOKING) === true);
  check('…and the other Functional provider keeps loading', blocks(w, YOUTUBE) === false);
}
{
  // The payload 1.40.0 shipped: this is the reported defect, kept as the
  // control that shows what the pattern changes.
  const w = page(BASE + 'functional:yes,svc.booking_sess:no', false);
  check('control — without the pattern the same refusal does not reach the script', blocks(w, BOOKING) === false);
}
{
  const w = page(BASE + 'functional:yes');
  check('Functional accepted, no per-service choice: the booking script loads', blocks(w, BOOKING) === false);
}
{
  const w = page(BASE + 'functional:no,svc.booking_sess:yes');
  check('Functional rejected, booking_sess on: the booking script loads (explicit yes)', blocks(w, BOOKING) === false);
  check('…and the rest of Functional stays blocked', blocks(w, YOUTUBE) === true);
}
{
  // The entry names the catalogue provider; the declared owner is asked too.
  const w = page(BASE + 'functional:yes,svc.yt_helper:no');
  check('a declared "no" on a pattern a catalogue provider also owns still blocks', blocks(w, YOUTUBE) === true);
  check('declared owners of a pattern are read from _services', JSON.stringify(w.eval('_fazGetDeclaredPatternOwners("youtube.com/embed")')) === '["yt_helper"]');
  check('a pattern with no declared owner has none', w.eval('_fazGetDeclaredPatternOwners("widget.nobody.example")').length === 0);
}
{
  // The catalogue provider's own answer is unchanged by the declared owner.
  const w = page(BASE + 'functional:yes,svc.youtube:no');
  check('the catalogue provider\'s own "no" still blocks its pattern', blocks(w, YOUTUBE) === true);
  const w2 = page(BASE + 'functional:no,svc.youtube:yes');
  check('the catalogue provider\'s own "yes" still releases its pattern', blocks(w2, YOUTUBE) === false);
}

console.log(`\ndeclared-service-script-block: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
