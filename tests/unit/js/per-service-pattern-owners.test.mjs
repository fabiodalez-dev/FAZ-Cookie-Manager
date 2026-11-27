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
 * @param {object}  [opts]
 * @param {string[]} [opts.omit]           Service ids left out of _services
 *   (and _declaredServices) while their _providersToBlock entry stays.
 * @param {object[]} [opts.extraServices]  Rows appended to _services.
 * @param {object[]} [opts.extraProviders] Entries appended to _providersToBlock.
 */
function page(consent, gcmAdvanced = false, { omit = [], extraServices = [], extraProviders = [] } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: 'https://shop.example.test/' });
  const { window: w } = dom;
  w.document.cookie = record(consent);
  const declared = (id, category, pattern) => ({ id, label: id, category, patterns: [pattern], cookies: [id], third_party: false, declared: true });
  const detected = (id, category, pattern) => ({ id, label: id, category, patterns: [pattern], cookies: [], third_party: true });
  const helper = declared('yt_helper', 'analytics', 'youtube.com/embed');
  const gtagHelper = declared('gtag_helper', 'analytics', 'googletagmanager.com/gtag/js');
  const session = declared('booking_sess', 'functional', 'widget.sess.example');
  const kept = (svc) => omit.indexOf(svc.id) === -1;
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
    ].filter(kept).concat(extraServices),
    _declaredServices: [helper, gtagHelper, session].filter(kept),
    _providersToBlock: [
      { re: 'widget.booking.example', categories: ['functional'], service: 'booking_a' },
      { re: 'youtube.com/embed', categories: ['functional'], service: 'youtube' },
      { re: 'googletagmanager.com/gtag/js', categories: ['analytics'], service: 'google-analytics' },
      { re: 'widget.sess.example', categories: ['functional'], service: 'booking_sess' },
    ].concat(extraProviders),
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

console.log('5. a pattern spelled like an Object.prototype member is an ordinary key');
// The pattern map was a plain object, and the patterns are what an
// administrator typed: "constructor" read as the inherited Object function,
// indexOf was called on it, the build threw and left a half-built map cached.
// A _providersToBlock entry whose `re` no service owned ("toString") then read
// the inherited function too, and (psMap[re] || []).slice() threw.
{
  const odd = (id, pattern) => ({ id, label: id, category: 'analytics', patterns: [pattern], cookies: [id], third_party: false, declared: true });
  const opts = {
    extraServices: [odd('ctor_svc', 'constructor'), odd('proto_svc', '__proto__')],
    extraProviders: [
      { re: 'constructor', categories: ['analytics'], service: 'ctor_svc' },
      { re: 'toString', categories: ['analytics'], service: '' },
    ],
  };
  const w = page(BASE + 'functional:yes,analytics:yes,svc.ctor_svc:no', false, opts);
  let threw = '';
  let owners = null;
  try { owners = w.eval('_fazGetPatternServiceMap()["constructor"]'); } catch (e) { threw = String(e && e.message || e); }
  check('the pattern map builds without throwing', threw === '');
  check('"constructor" maps to the service that names it', Array.isArray(owners) && owners.length === 1 && owners[0] === 'ctor_svc');
  check('"__proto__" is a key of its own, too', w.eval('Array.isArray(_fazGetPatternServiceMap()["__proto__"]) && _fazGetPatternServiceMap()["__proto__"][0] === "proto_svc"'));
  check('a key nobody declared ("toString") is absent, not inherited', w.eval('_fazGetPatternServiceMap()["toString"]') === undefined);
  let answer = 'threw';
  try { answer = w.eval('_fazGetServiceConsentForTarget("https://cdn.example.test/tostring/x.js")'); } catch (e) { answer = 'threw'; }
  check('an entry whose pattern no service owns resolves without throwing', answer === '');
  let blocked = 'threw';
  try { blocked = blocksUrl(w, 'https://cdn.example.test/constructor/x.js'); } catch (e) { blocked = 'threw'; }
  check('the refusal of the service named "constructor" blocks its script', blocked === true);
  let refusedAny = 'threw';
  try { refusedAny = w.eval('_fazAnyPatternServiceRefused()'); } catch (e) { refusedAny = 'threw'; }
  check('…and keeps the interceptors in place', refusedAny === true);
}

console.log('6. the service named on an entry is asked when no _services row lists it');
// An enforceable provider the scanner never saw (a block-first site, an embed
// injected at runtime) has a _providersToBlock entry and no _services row, so
// the pattern map has no owner for its pattern. Its svc.<id> choice is read
// through the id on the entry, which _fazGetServiceConsentForTarget() adds to
// the owners the map lists. #134/#146
{
  const omit = { omit: ['booking_sess'] };
  {
    const w = page(BASE + 'functional:no,analytics:no,svc.booking_sess:yes', false, omit);
    check('precondition: no _services row, so the pattern map has no owner for it', w.eval('_fazGetPatternServiceMap()["widget.sess.example"]') === undefined);
    check('precondition: the entry still names the service', w._fazConfig._providersToBlock.some((p) => p.service === 'booking_sess'));
    check('the grant on the entry\'s service is read', w.eval(`_fazGetServiceConsentForTarget(${JSON.stringify(SESSION)})`) === 'yes');
    check('…and releases the script under a denied category', blocksUrl(w, SESSION) === false);
  }
  {
    const w = page(BASE + 'functional:yes,analytics:yes,svc.booking_sess:no', false, omit);
    check('the refusal on the entry\'s service is read', w.eval(`_fazGetServiceConsentForTarget(${JSON.stringify(SESSION)})`) === 'no');
    check('…and blocks the script under a granted category', blocksUrl(w, SESSION) === true);
    const r = saveThenInject(w, SESSION);
    check('every category granted, the unlisted service off: the save ran to the end', r.threw === '');
    check('…the createElement guard stays', r.guardRemoved === false);
    check('…the observer stays connected', r.disconnected === false);
    check('…and its script injected after the save is parked', r.type === 'javascript/blocked');
  }
}

console.log('7. the plugin\'s own Google tag honours a declared owner\'s refusal of its URL');
// _fazShouldBlockScriptNode() judges the plugin's own gtag.js by its own
// provider (google-analytics / google-ads) and never by URL, so the Ads loader
// is not held by an Analytics refusal (#371 review). That also skipped the
// hand-declared gtag_helper, which names the same URL: its "no" was never read
// and the restore queue released the tag. Only declared owners are asked, as
// for an element's own data-faz-service grant.
{
  const ADS = 'https://www.googletagmanager.com/gtag/js?id=AW-12345678';
  /**
   * A gtag.js script element as Google_Tag::render() prints it.
   * @param {object} o
   */
  function ownTag(w, { src = GTAG, marker = 'google-analytics', category = '', service = null, executed = false } = {}) {
    const el = w.__nativeCreateElement.call(w.document, 'script');
    el.setAttribute('data-faz-google-tag', marker);
    if (service !== null) el.setAttribute('data-faz-service', service);
    if (category) el.setAttribute('data-faz-category', category);
    if (executed) el.setAttribute('data-faz-executed', '1');
    el.setAttribute('src', src);
    w.__own = el;
    return w.eval(`_fazOwnGoogleTagProvider(window.__own)`);
  }
  const blocksNode = (w, category, target, service) => w.eval(`_fazShouldBlockScriptNode(window.__own, ${JSON.stringify(category)}, ${JSON.stringify(target)}, ${JSON.stringify(service)})`);
  const opts = { extraServices: [{ id: 'google-ads', label: 'google-ads', category: 'functional', patterns: ['googleadservices.example'], cookies: [], third_party: true }] };

  {
    const w = page(BASE + 'functional:yes,analytics:yes,svc.google-analytics:yes,svc.gtag_helper:no', false, opts);
    check('precondition: the parked copy is recognised as the plugin\'s own tag', ownTag(w, { category: 'analytics', service: 'google-analytics' }) === 'google-analytics');
    check('parked own tag, its own service on, the declared owner off: it stays parked', blocksNode(w, 'analytics', GTAG, 'google-analytics') === true);
    ownTag(w, { service: 'google-analytics', executed: true });
    check('…and the clone the restore queue released is held again', blocksNode(w, '', GTAG, 'google-analytics') === true);
  }
  {
    const w = page(BASE + 'functional:yes,analytics:yes,svc.google-analytics:yes', false, opts);
    ownTag(w, { category: 'analytics', service: 'google-analytics' });
    check('control — no declared owner refused: the parked own tag is released', blocksNode(w, 'analytics', GTAG, 'google-analytics') === false);
    ownTag(w, { service: 'google-analytics', executed: true });
    check('control — and the released clone stays running', blocksNode(w, '', GTAG, 'google-analytics') === false);
  }
  {
    const w = page(BASE + 'functional:no,analytics:no,svc.gtag_helper:no', true, opts);
    ownTag(w);
    check('Advanced mode, live own tag, the declared owner off: held, no exemption over the refusal', blocksNode(w, '', GTAG, '') === true);
  }
  {
    const w = page(BASE + 'functional:no,analytics:no', true, opts);
    ownTag(w);
    check('control — Advanced mode, nothing refused: the exemption still lets the live tag run', blocksNode(w, '', GTAG, '') === false);
  }
  {
    // The catalogue distinction stays: the Ads loader matches the
    // google-analytics pattern, and an Analytics refusal does not hold it.
    const w = page(BASE + 'functional:yes,analytics:no,svc.google-analytics:no', false, opts);
    check('precondition: the Ads loader is recognised as the plugin\'s own Ads tag', ownTag(w, { src: ADS, marker: 'google-ads', category: 'functional', service: 'google-ads' }) === 'google-ads');
    check('own Ads tag under a granted category, google-analytics off: released', blocksNode(w, 'functional', ADS, 'google-ads') === false);
  }
}

console.log(`\nper-service-pattern-owners: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
