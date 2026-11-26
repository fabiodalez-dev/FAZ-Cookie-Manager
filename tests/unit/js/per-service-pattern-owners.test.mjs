/**
 * JS unit test (jsdom) — every owner of a pattern is asked, and a refusal
 * outlives a grant, an exemption and the end of the consent save (PR #412
 * review).
 *
 * Four paths let a script through although the visitor had refused a service
 * that names its URL, while the server — which matches every owner of a
 * pattern and lets any refusal win — held the same script:
 *
 *  1. A _providersToBlock entry carries ONE service. With two services on one
 *     pattern, only the one named on the entry was read: its "yes" released
 *     the URL over the other's "no".
 *  2. An element's own data-faz-service grant returned before the target was
 *     looked at, so a hand-declared owner of the same URL was never asked.
 *  3. Under Advanced Consent Mode a dynamic gtag.js script left on the
 *     exemption before any per-service refusal for its URL was read.
 *  4. With every category granted the consent save removed the
 *     createElement guard and the observer, so a refused service's script
 *     injected afterwards loaded.
 *
 * Runs the real frontend/js/script.js with its DOMContentLoaded bootstrap
 * neutralised.
 *
 * Run: node tests/unit/js/per-service-pattern-owners.test.mjs
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
const SESSION = 'https://widget.sess.example/loader.js';
const YOUTUBE = 'https://www.youtube.com/embed/abc';
const GTAG = 'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234';

/**
 * Per-service consent on.
 *  - booking_a / booking_b: two scanner-detected services (neither declared by
 *    hand) on one pattern; the entry can name only the first.
 *  - youtube (Functional) and the hand-declared yt_helper (Analytics) share
 *    youtube.com/embed; the entry names the catalogue provider.
 *  - google-analytics and the hand-declared gtag_helper share the gtag.js
 *    loader.
 *  - booking_sess: hand-declared, alone on its pattern.
 *
 * @param {string}  consent      Consent record pairs.
 * @param {boolean} gcmAdvanced  Advanced Consent Mode on.
 */
function page(consent, gcmAdvanced = false) {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: 'https://shop.example.test/' });
  const { window: w } = dom;
  w.document.cookie = record(consent);
  const declared = (id, category, pattern) => ({ id, label: id, category, patterns: [pattern], cookies: [id], third_party: false, declared: true });
  const detected = (id, category, pattern) => ({ id, label: id, category, patterns: [pattern], cookies: [], third_party: true });
  const helper = declared('yt_helper', 'analytics', 'youtube.com/embed');
  const gtagHelper = declared('gtag_helper', 'analytics', 'googletagmanager.com/gtag/js');
  const session = declared('booking_sess', 'functional', 'widget.sess.example');
  w._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'functional', isNecessary: false, defaultConsent: { gdpr: false, ccpa: true } },
      { slug: 'analytics', isNecessary: false, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    _services: [
      detected('booking_a', 'functional', 'widget.booking.example'),
      detected('booking_b', 'functional', 'widget.booking.example'),
      detected('youtube', 'functional', 'youtube.com/embed'),
      detected('google-analytics', 'analytics', 'googletagmanager.com/gtag/js'),
      helper, gtagHelper, session,
    ],
    _declaredServices: [helper, gtagHelper, session],
    _providersToBlock: [
      { re: 'widget.booking.example', categories: ['functional'], service: 'booking_a' },
      { re: 'youtube.com/embed', categories: ['functional'], service: 'youtube' },
      { re: 'googletagmanager.com/gtag/js', categories: ['analytics'], service: 'google-analytics' },
      { re: 'widget.sess.example', categories: ['functional'], service: 'booking_sess' },
    ],
    _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: true, _perCookieConsent: false, _rootDomain: '', _activeLaw: 'gdpr', _bannerSlug: 'gdpr', _expiry: 180,
    _gcmAdvanced: gcmAdvanced,
    _bannerConfig: { settings: { applicableLaw: 'gdpr' }, behaviours: { respectGPC: false, reloadBannerOnAccept: false }, config: { revisitConsent: { status: false } } },
    _shortCodes: [], i18n: {}, _i18n: {},
  };
  // The runtime keeps its createElement backup and its observer in `let`
  // bindings an eval cannot reach afterwards, so both are caught on the way in.
  w.__nativeCreateElement = w.document.createElement;
  w.__observerDisconnected = false;
  const NativeObserver = w.MutationObserver;
  w.MutationObserver = class extends NativeObserver {
    constructor(callback) { super(callback); this.__fazNodeList = !!callback && callback.name === '_fazMutationObserver'; }
    disconnect() { if (this.__fazNodeList) w.__observerDisconnected = true; super.disconnect(); }
  };
  const realAdd = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  w.eval(SCRIPT);
  w.document.addEventListener = realAdd;
  return w;
}

const blocksUrl = (w, url) => w.eval(`_fazShouldBlockProvider(${JSON.stringify(url)})`);
const blocksResource = (w, category, url, service) => w.eval(`_fazShouldBlockResource(${JSON.stringify(category)}, ${JSON.stringify(url)}, ${JSON.stringify(service)})`);
/** _fazShouldChangeType() for a script created at runtime, as the src setter asks it. */
function blocksDynamic(w, url, service) {
  w.__el = w.__nativeCreateElement.call(w.document, 'script');
  if (service) w.__el.setAttribute('data-faz-service', service);
  return w.eval(`_fazShouldChangeType(window.__el, ${JSON.stringify(url)})`);
}
const BASE = 'consentid:abc,consent:yes,action:yes,necessary:yes,';

console.log('1. every owner of a shared pattern is asked, not only the one on the entry');
{
  const w = page(BASE + 'functional:yes,analytics:yes,svc.booking_a:yes,svc.booking_b:no');
  check('booking_a on, booking_b off: the shared script is blocked', blocksUrl(w, BOOKING) === true);
  check('…and the target answer is the refusal', w.eval(`_fazGetServiceConsentForTarget(${JSON.stringify(BOOKING)})`) === 'no');
}
{
  const w = page(BASE + 'functional:yes,analytics:yes,svc.booking_a:no,svc.booking_b:yes');
  check('the refusal of the owner named on the entry still blocks', blocksUrl(w, BOOKING) === true);
}
{
  const w = page(BASE + 'functional:no,analytics:no,svc.booking_b:yes');
  check('a grant from the owner the entry does not name releases a denied category', blocksUrl(w, BOOKING) === false);
}
{
  const w = page(BASE + 'functional:no,analytics:no,svc.booking_a:yes');
  check('a grant with no refusal beside it still releases the script', blocksUrl(w, BOOKING) === false);
}

console.log('2. an element grant does not outrank a declared owner\'s refusal of the same URL');
{
  // Different categories, opposite overrides: Functional denied with YouTube
  // switched on, Analytics granted with the declared helper switched off.
  const w = page(BASE + 'functional:no,analytics:yes,svc.youtube:yes,svc.yt_helper:no');
  check('parked element tagged youtube: the declared helper\'s "no" keeps it parked', blocksResource(w, 'functional', YOUTUBE, 'youtube') === true);
  check('dynamic script tagged youtube: same refusal, same answer', blocksDynamic(w, YOUTUBE, 'youtube') === true);
}
{
  const w = page(BASE + 'functional:no,analytics:yes,svc.youtube:yes');
  check('no declared owner refused: the element grant is kept (parked element)', blocksResource(w, 'functional', YOUTUBE, 'youtube') === false);
  check('no declared owner refused: the element grant is kept (dynamic script)', blocksDynamic(w, YOUTUBE, 'youtube') === false);
}
{
  const w = page(BASE + 'functional:no,analytics:no,svc.youtube:yes,svc.yt_helper:yes');
  check('both owners granted under denied categories: the element loads', blocksResource(w, 'functional', YOUTUBE, 'youtube') === false);
}

console.log('3. Advanced Consent Mode does not exempt a URL whose service was refused');
{
  const w = page(BASE + 'functional:yes,analytics:yes,svc.gtag_helper:no', true);
  check('dynamic gtag.js, declared owner off: blocked before the exemption', blocksDynamic(w, GTAG, '') === true);
  check('…as the parked-resource path already answered', blocksResource(w, '', GTAG, '') === true);
}
{
  const w = page(BASE + 'functional:yes,analytics:yes,svc.google-analytics:no', true);
  check('dynamic gtag.js, catalogue owner off: blocked before the exemption', blocksDynamic(w, GTAG, '') === true);
}
{
  const w = page(BASE + 'functional:no,analytics:no', true);
  check('no per-service refusal: the Advanced exemption still lets gtag.js load', blocksDynamic(w, GTAG, '') === false);
}
{
  const w = page(BASE + 'functional:no,analytics:no', false);
  check('control — without Advanced mode the denied category blocks it', blocksDynamic(w, GTAG, '') === true);
}

console.log('4. the interceptors stay while a service that names a pattern is refused');
/** Run the consent save, then inject a script the way a page script would. */
function saveThenInject(w, url) {
  let threw = '';
  try { w.eval('_fazAfterConsent()'); } catch (e) { threw = String(e && e.message || e); }
  const guardRemoved = w.document.createElement === w.__nativeCreateElement;
  const s = w.document.createElement('script');
  s.src = url;
  return { threw, disconnected: w.__observerDisconnected, guardRemoved, type: s.getAttribute('type') };
}
{
  const w = page(BASE + 'functional:yes,analytics:yes,svc.booking_sess:no');
  const r = saveThenInject(w, SESSION);
  check('the consent save ran to the end', r.threw === '');
  check('every category granted, booking_sess off: the createElement guard stays', r.guardRemoved === false);
  check('…the observer stays connected', r.disconnected === false);
  check('…and a booking script injected after the save is parked', r.type === 'javascript/blocked');
}
{
  const w = page(BASE + 'functional:yes,analytics:yes');
  const r = saveThenInject(w, SESSION);
  check('control — the save ran to the end', r.threw === '');
  check('control — nothing refused: the guard is removed', r.guardRemoved === true);
  check('control — and the observer disconnected', r.disconnected === true);
  check('control — the injected script is left alone', r.type !== 'javascript/blocked');
}

console.log(`\nper-service-pattern-owners: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
