/**
 * JS unit test (jsdom) — guards the #163 (map-tile <img>) + #167 (Bricks-lazy
 * <iframe>) work: FAZ now gates the `src` SETTER on HTMLImageElement and
 * HTMLIFrameElement, so a cross-origin resource whose URL matches a blocked
 * provider in a denied category is PARKED (URL → data-faz-src, no request)
 * before consent, then restored by the standard data-faz-src pass.
 *
 * These checks exercise the REAL decision/gate functions in frontend/js/script.js
 * with no browser and no network — they cover the fast-path bail-outs (same
 * origin / relative / data: / blob:), the provider-match decision, the
 * whitelist + faz-skip escape hatches, per-category and per-service consent,
 * and the end-to-end setter override on both prototypes (img stays visible,
 * iframe is hidden on park).
 *
 * Loads the REAL frontend/js/script.js with its DOMContentLoaded bootstrap
 * neutralised. _fazStore = window._fazConfig and ref = window.fazcookie are
 * captured at eval time (script.js:7 / :34), so the harness seeds them first
 * and mutates them in place between scenarios.
 *
 * Run: node tests/unit/js/img-iframe-src-gate.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');

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

const OSM = 'https://tile.openstreetmap.org/17/69083/45877.png';
const YT = 'https://www.youtube-nocookie.com/embed/NL2UmY9oKow?rel=0';

function loadFrontend() {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  // Block-first store: functional + marketing are non-necessary and there is no
  // consent cookie, so both categories are blocked until consent.
  window._fazConfig = {
    _block: '1',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'functional', isNecessary: false },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: [
      { id: 'youtube', patterns: ['youtube-nocookie.com', 'youtube.com'] },
      { id: 'openstreetmap', patterns: ['tile.openstreetmap.org'] },
    ],
    _providersToBlock: [
      { re: 'tile.openstreetmap.org', categories: ['functional'], service: 'openstreetmap' },
      { re: 'youtube-nocookie.com', categories: ['marketing'], service: 'youtube' },
      { re: 'youtube.com', categories: ['marketing'], service: 'youtube' },
      // A URL that matches two providers with diverging categories — the first
      // matched is functional, a later one marketing. Exercises _fazImgCategory
      // returning the category that actually triggers the block. (#168 review)
      { re: 'multi-cdn.example', categories: ['functional'], service: 'multi-a' },
      { re: 'multi-cdn.example/ads', categories: ['marketing'], service: 'multi-b' },
      // Google Fonts — exercises the <link> href gate (Web Font Loader). #163 review / baga
      { re: 'fonts.googleapis.com', categories: ['functional'], service: 'google-fonts' },
      { re: 'fonts.gstatic.com', categories: ['functional'], service: 'google-fonts' },
    ],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
  // Default: no consent recorded for any key.
  window.fazcookie = { _fazGetFromStore: () => undefined };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(code);
  window.document.addEventListener = realAdd;
  return window;
}

console.log('img / iframe src-setter blocking gate (jsdom, #163 + #167)');
const w = loadFrontend();
const cfg = w._fazConfig;

// Mutate consent in place; ref captured the same fazcookie object reference.
function setConsent(map) {
  w.fazcookie._fazGetFromStore = (k) => (k in map ? map[k] : undefined);
}
function resetConsent() {
  w.fazcookie._fazGetFromStore = () => undefined;
}

// Build a parked/loaded probe for a freshly-created element.
function probe(tag, url) {
  const el = w.document.createElement(tag);
  el.src = url;
  return {
    parked: el.getAttribute('data-faz-src'),
    src: el.getAttribute('src') || '',
    category: el.getAttribute('data-faz-category'),
    hidden: el.classList.contains('faz-hidden'),
  };
}

// ---------------------------------------------------------------------------
// _fazImgShouldBlock — the gate decision (fast paths + provider match).
// ---------------------------------------------------------------------------
console.log('\n_fazImgShouldBlock() — decision');
const sb = (url, el) => w.eval(`_fazImgShouldBlock(${el || 'null'}, ${JSON.stringify(url)})`);
eq('cross-origin OSM tile (functional, denied) → block', sb(OSM), true);
eq('cross-origin YouTube embed (marketing, denied) → block', sb(YT), true);
eq('same-origin absolute image → bail (false)', sb('http://localhost/wp-content/uploads/x.png'), false);
eq('root-relative path → bail (false)', sb('/wp-content/uploads/y.png'), false);
eq('bare relative path (no //) → bail (false)', sb('photo.png'), false);
eq('data: URI → bail (false)', sb('data:image/gif;base64,R0lGODlhAQABAAAAACw='), false);
eq('blob: URL → bail (false)', sb('blob:http://localhost/abc-123'), false);
eq('empty string → bail (false)', sb(''), false);
eq('non-string url → bail (false)', w.eval('_fazImgShouldBlock(null, 12345)'), false);
eq('cross-origin non-provider image → not blocked', sb('https://example.com/some/photo.jpg'), false);
eq('protocol-relative provider URL → block', sb('//tile.openstreetmap.org/5/1/2.png'), true);

console.log('\n_fazImgShouldBlock() — escape hatches');
eq('element carrying faz-skip class → not blocked',
  sb(OSM, '{ classList:{ contains:function(c){ return c==="faz-skip"; } } }'), false);
// Whitelist the OSM host → not blocked; restore afterwards.
cfg._userWhitelist = ['tile.openstreetmap.org'];
eq('user-whitelisted provider URL → not blocked', sb(OSM), false);
cfg._userWhitelist = [];
// _block off → never blocks, even for a known provider.
cfg._block = '';
eq('blocking globally off → not blocked', sb(OSM), false);
cfg._block = '1';

console.log('\n_fazImgShouldBlock() — consent gating');
setConsent({ functional: 'yes' });
eq('OSM tile after functional consent → not blocked', sb(OSM), false);
eq('YouTube still blocked (marketing denied) when only functional consented', sb(YT), true);
resetConsent();
// Per-service: an explicit svc.<id>:yes releases the provider even pre-category.
cfg._perServiceConsent = true;
setConsent({ 'svc.youtube': 'yes' });
eq('per-service svc.youtube:yes → YouTube not blocked', sb(YT), false);
setConsent({ 'svc.youtube': 'no' });
eq('per-service svc.youtube:no → YouTube blocked', sb(YT), true);
resetConsent();
cfg._perServiceConsent = false;

// ---------------------------------------------------------------------------
// _fazImgCategory — the tag written onto a parked resource.
// ---------------------------------------------------------------------------
console.log('\n_fazImgCategory() — category tagging');
const cat = (url) => w.eval(`_fazImgCategory(${JSON.stringify(url)})`);
eq('OSM tile → functional', cat(OSM), 'functional');
eq('YouTube embed → marketing', cat(YT), 'marketing');
eq('non-provider URL → functional default', cat('https://example.com/x.jpg'), 'functional');
// #168 review: multi-provider URL — tag the category that actually blocks.
// Block-first (no consent): the first matched denied category wins.
eq('multi-provider URL (block-first) → first denied category', cat('https://multi-cdn.example/ads/x.png'), 'functional');
// functional consented, marketing denied → must tag marketing (the real block
// reason), not the allowed functional, so the restore pass can't break it.
setConsent({ functional: 'yes' });
eq('multi-provider URL (functional consented) → denied marketing category', cat('https://multi-cdn.example/ads/x.png'), 'marketing');
resetConsent();

// ---------------------------------------------------------------------------
// HTMLImageElement.src override — end-to-end (img stays visible on park).
// ---------------------------------------------------------------------------
console.log('\nHTMLImageElement.src override');
let r = probe('img', OSM);
eq('img: blocked tile is parked in data-faz-src', r.parked, OSM);
eq('img: blocked tile has no src (no request)', r.src, '');
eq('img: parked tile tagged functional', r.category, 'functional');
eq('img: parked tile is NOT hidden (layout preserved)', r.hidden, false);
r = probe('img', 'http://localhost/wp-content/uploads/local.png');
eq('img: same-origin image loads (src set)', r.src, 'http://localhost/wp-content/uploads/local.png');
eq('img: same-origin image not parked', r.parked, null);
r = probe('img', 'https://example.com/photo.jpg');
eq('img: non-provider cross-origin image loads', r.src, 'https://example.com/photo.jpg');
eq('img: non-provider image not parked', r.parked, null);
// getter still returns the native resolved absolute URL on a non-blocked load.
eq('img: src getter returns resolved absolute URL',
  w.eval('(function(){ var i=document.createElement("img"); i.src="sub/pic.png"; return i.src; })()'),
  'http://localhost/sub/pic.png');
// #168 review: documented scope boundary — this gate intercepts the `src`
// PROPERTY setter only. A runtime setAttribute('src', …) is out of scope here
// (server-rendered markup is still handled by the output-buffer blocking; a
// runtime setAttribute on a main-document element is not intercepted).
const viaAttr = w.document.createElement('img');
viaAttr.setAttribute('src', OSM);
eq('img: setAttribute("src") is outside this gate (documented boundary)', viaAttr.getAttribute('data-faz-src'), null);

// F1 (review): the restore re-park guard depends on the gate leaving the native
// src empty when it blocks. With functional consented but marketing still denied,
// a multi-provider URL must STILL park (marketing denied) and leave src empty —
// so the restore pass's `if (!el.getAttribute("src")) return` keeps it parked and
// recoverable instead of stripping data-faz-src and bricking the element.
setConsent({ functional: 'yes' });
const repark = w.document.createElement('img');
repark.src = 'https://multi-cdn.example/ads/tile.png';
eq('F1: partial-consent multi-provider img stays parked (native src empty)', repark.getAttribute('src') || '', '');
eq('F1: partial-consent multi-provider img keeps data-faz-src (recoverable)', repark.getAttribute('data-faz-src'), 'https://multi-cdn.example/ads/tile.png');
eq('F1: parked element tagged with the still-denied category (marketing)', repark.getAttribute('data-faz-category'), 'marketing');
resetConsent();

// ---------------------------------------------------------------------------
// HTMLIFrameElement.src override — end-to-end (iframe hidden on park, #167).
// ---------------------------------------------------------------------------
console.log('\nHTMLIFrameElement.src override');
r = probe('iframe', YT);
eq('iframe: blocked embed is parked in data-faz-src', r.parked, YT);
eq('iframe: blocked embed has no src (no request)', r.src, '');
eq('iframe: parked embed tagged marketing', r.category, 'marketing');
eq('iframe: parked embed IS hidden (faz-hidden)', r.hidden, true);
// Bricks lazy-load shape: data-src present, then runtime `iframe.src = data-src`.
const lazy = w.eval(`(function(){
  var f = document.createElement('iframe');
  f.className = 'bricks-lazy-hidden';
  f.setAttribute('data-src', ${JSON.stringify(YT)});
  f.src = f.getAttribute('data-src');
  return { parked: f.getAttribute('data-faz-src'), src: f.getAttribute('src') || '', hidden: f.classList.contains('faz-hidden') };
})()`);
eq('iframe: Bricks-lazy runtime src assignment is parked', lazy.parked, YT);
eq('iframe: Bricks-lazy iframe fires no request (no src)', lazy.src, '');
eq('iframe: Bricks-lazy iframe hidden', lazy.hidden, true);
r = probe('iframe', '/embedded/local.html');
eq('iframe: same-origin relative src loads', r.src, '/embedded/local.html');
eq('iframe: same-origin iframe not parked', r.parked, null);
r = probe('iframe', 'data:text/html,<p>hi</p>');
eq('iframe: data: URI loads (fast path)', r.src, 'data:text/html,<p>hi</p>');

// ---------------------------------------------------------------------------
// HTMLLinkElement.href override — runtime stylesheet injection (Web Font
// Loader / Google Fonts). Parked into data-faz-href, restored by the standard
// link[data-faz-href] pass. (baga report)
// ---------------------------------------------------------------------------
console.log('\nHTMLLinkElement.href override (Web Font Loader)');
const GF = 'https://fonts.googleapis.com/css?family=Roboto:400,700';
function linkProbe(url, fn) {
  const el = w.document.createElement('link');
  el.rel = 'stylesheet';
  if (fn) fn(el);
  el.href = url;
  return { parked: el.getAttribute('data-faz-href'), href: el.getAttribute('href') || '', category: el.getAttribute('data-faz-category') };
}
let lp = linkProbe(GF);
eq('link: blocked Google Fonts stylesheet is parked in data-faz-href', lp.parked, GF);
eq('link: parked stylesheet has no href (no fetch)', lp.href, '');
eq('link: parked stylesheet tagged functional', lp.category, 'functional');
// Web Font Loader shape: create link, set rel + href via property, append.
const wfl = w.eval(`(function(){
  var l = document.createElement('link');
  l.rel = 'stylesheet';
  l.href = ${JSON.stringify(GF)};
  document.head.appendChild(l);
  return { parked: l.getAttribute('data-faz-href'), href: l.getAttribute('href') || '' };
})()`);
eq('link: Web Font Loader runtime href assignment is parked', wfl.parked, GF);
eq('link: Web Font Loader stylesheet fires no fetch (no href)', wfl.href, '');
// same-origin / relative / non-provider stylesheets load normally.
lp = linkProbe('http://localhost/wp-content/themes/x/style.css');
eq('link: same-origin stylesheet loads (href set)', lp.href, 'http://localhost/wp-content/themes/x/style.css');
eq('link: same-origin stylesheet not parked', lp.parked, null);
lp = linkProbe('https://cdn.example.com/lib/some.css');
eq('link: non-provider cross-origin stylesheet loads', lp.href, 'https://cdn.example.com/lib/some.css');
eq('link: non-provider stylesheet not parked', lp.parked, null);
// consent for functional → the Google Fonts stylesheet is no longer blocked.
setConsent({ functional: 'yes' });
lp = linkProbe(GF);
eq('link: Google Fonts stylesheet after functional consent loads', lp.href, GF);
eq('link: consented stylesheet not parked', lp.parked, null);
resetConsent();
// documented scope boundary: setAttribute('href', …) is the property gate's blind spot.
const linkAttr = w.document.createElement('link');
linkAttr.rel = 'stylesheet';
linkAttr.setAttribute('href', GF);
eq('link: setAttribute("href") is outside this gate (documented boundary)', linkAttr.getAttribute('data-faz-href'), null);

// --- 5 added edge-case tests for the href gate (escape hatches + edge URLs) ---
console.log('\nHTMLLinkElement.href override — edge cases (added)');
// 1. faz-skip escape hatch on a <link>.
lp = linkProbe(GF, (el) => el.classList.add('faz-skip'));
eq('link: faz-skip stylesheet is not parked (escape hatch)', lp.parked, null);
// 2. user-whitelisted provider URL on a <link>.
cfg._userWhitelist = ['fonts.googleapis.com'];
lp = linkProbe(GF);
eq('link: user-whitelisted Google Fonts stylesheet is not parked', lp.parked, null);
cfg._userWhitelist = [];
// 3. blocking globally off → not parked even for a known provider.
cfg._block = '';
lp = linkProbe(GF);
eq('link: blocking globally off → stylesheet not parked', lp.parked, null);
cfg._block = '1';
// 4. protocol-relative provider stylesheet is cross-origin → parked.
lp = linkProbe('//fonts.googleapis.com/css?family=Lato');
eq('link: protocol-relative Google Fonts stylesheet is parked', lp.parked, '//fonts.googleapis.com/css?family=Lato');
// 5. fonts.gstatic.com (font-file host) stylesheet/preload is also a blocked provider.
lp = linkProbe('https://fonts.gstatic.com/s/roboto/v30/font.woff2');
eq('link: fonts.gstatic.com resource is parked', lp.parked, 'https://fonts.gstatic.com/s/roboto/v30/font.woff2');

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
