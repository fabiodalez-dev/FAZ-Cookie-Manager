/**
 * JS unit test (jsdom) — guards the #163 (map-tile <img>), #167 (Bricks-lazy
 * <iframe>), runtime stylesheet, and inline CSS-url work. FAZ gates the `src`
 * SETTER on HTMLImageElement/HTMLIFrameElement, `href` on HTMLLinkElement, and
 * the common HTMLStyleElement text insertion paths, so a cross-origin resource
 * whose URL matches a blocked provider in a denied category is PARKED before
 * consent and restored by the standard unblock pass.
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
const GFONT = 'https://fonts.gstatic.com/s/roboto/v30/KFOmCnqEu92Fr1Mu4mxK.woff2';

function loadFrontend(options = {}) {
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
    _aggressiveCssUrlBlocking: options.aggressiveCssUrlBlocking !== false,
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

// ---------------------------------------------------------------------------
// HTMLStyleElement text gates — inline CSS url() / @font-face font files.
// ---------------------------------------------------------------------------
console.log('\nHTMLStyleElement inline CSS-url gate');
const css = `@font-face{font-family:"FazLeak";src:url("${GFONT}") format("woff2");} .probe{font-family:"FazLeak";color:#123}`;
const st = w.document.createElement('style');
st.textContent = css;
eq('style: original CSS is parked in data-faz-css', typeof st.getAttribute('data-faz-css'), 'string');
eq('style: parked CSS tagged functional', st.getAttribute('data-faz-category'), 'functional');
eq('style: live CSS no longer contains fonts.gstatic pre-consent', st.textContent.includes('fonts.gstatic.com'), false);
eq('style: live CSS contains an inert replacement URL', st.textContent.includes('data:application/octet-stream,'), true);

const importStyle = w.document.createElement('style');
importStyle.appendChild(w.document.createTextNode(`@import "https://fonts.googleapis.com/css?family=Roboto"; body{color:red}`));
eq('style.appendChild: @import string is parked', typeof importStyle.getAttribute('data-faz-css'), 'string');
eq('style.appendChild: live CSS no longer contains fonts.googleapis pre-consent', importStyle.textContent.includes('fonts.googleapis.com'), false);

setConsent({ functional: 'yes' });
w.document.head.appendChild(st);
w.eval('_fazUnblockServerSide()');
eq('style: consent restores original CSS text', st.textContent, css);
eq('style: consent clears data-faz-css', st.getAttribute('data-faz-css'), null);
resetConsent();

// --- review fixes (F1-style rework of the style gate) ---
console.log('\nHTMLStyleElement gate — review fixes');
// A: appendChild mutates the passed node in place, preserving identity + return value (no clone).
const idStyle = w.document.createElement('style');
const tn = w.document.createTextNode('@font-face{src:url("' + GFONT + '")}');
const ret = idStyle.appendChild(tn);
eq('A: appendChild returns the exact node passed (identity)', ret === tn, true);
eq('A: the passed node is connected (parentNode set, not a clone)', tn.parentNode === idStyle, true);
eq('A: node value neutralized in place', tn.nodeValue.includes('fonts.gstatic.com'), false);
// E: replaceChild is gated too.
const rcStyle = w.document.createElement('style');
const oldNode = w.document.createTextNode('body{color:green}');
rcStyle.appendChild(oldNode);
rcStyle.replaceChild(w.document.createTextNode('@import "https://fonts.googleapis.com/css?family=Lato";'), oldNode);
eq('E: replaceChild neutralizes a blocked @import', rcStyle.textContent.includes('fonts.googleapis.com'), false);
eq('E: replaceChild parks the original in data-faz-css', typeof rcStyle.getAttribute('data-faz-css'), 'string');
// B: the gated methods stay writable (reassignment must not throw in strict mode).
let writable = true;
try { const s = w.HTMLStyleElement.prototype.appendChild; w.HTMLStyleElement.prototype.appendChild = s; } catch (e) { writable = false; }
eq('B: gated style methods are writable (no strict-mode throw on reassign)', writable, true);
// F: multiple blocked chunks accumulate — restore rebuilds ALL of them, not just the last.
function restoreStyleCss(style) {
  setConsent({ functional: 'yes' });
  w.document.head.appendChild(style);
  w.eval('_fazUnblockServerSide()');
  const out = style.textContent;
  resetConsent();
  return out;
}

const cum = w.document.createElement('style');
const cssA = '@font-face{font-family:A;src:url("' + GFONT + '")}';
const cssB = '@font-face{font-family:B;src:url("https://fonts.gstatic.com/s/roboto/v30/second.woff2")}';
const cssImport = '@import "https://fonts.googleapis.com/css?family=X";';
const cssSafe = '.safe-local{color:#123}';
cum.appendChild(w.document.createTextNode(cssA));
cum.appendChild(w.document.createTextNode(cssSafe));
cum.appendChild(w.document.createTextNode(cssImport));
const cumRestored = restoreStyleCss(cum);
eq('F: cumulative restore rebuilds the first blocked chunk (gstatic)', cum.textContent.includes('fonts.gstatic.com'), true);
eq('F: cumulative restore rebuilds the second blocked chunk (googleapis @import)', cum.textContent.includes('fonts.googleapis.com'), true);
eq('F: cumulative restore keeps benign chunks appended while parked', cumRestored, cssA + cssSafe + cssImport);

const setterReplace = w.document.createElement('style');
setterReplace.textContent = cssA;
setterReplace.textContent = cssB;
eq('F2: second textContent assignment replaces the prior parked original', restoreStyleCss(setterReplace), cssB);

const replaceStyle = w.document.createElement('style');
const replacedNode = w.document.createTextNode(cssA);
replaceStyle.appendChild(replacedNode);
replaceStyle.replaceChild(w.document.createTextNode(cssB), replacedNode);
eq('F2: replaceChild restore does not resurrect the replaced node', restoreStyleCss(replaceStyle), cssB);

const orderStyle = w.document.createElement('style');
const orderRef = w.document.createTextNode(cssB);
orderStyle.appendChild(orderRef);
orderStyle.insertBefore(w.document.createTextNode(cssImport), orderRef);
eq('F2: insertBefore restore preserves DOM order', restoreStyleCss(orderStyle), cssImport + cssB);

const dataSetterStyle = w.document.createElement('style');
const dataSetterNode = w.document.createTextNode(cssA);
dataSetterStyle.appendChild(dataSetterNode);
dataSetterNode.data = cssB;
eq('F3: CharacterData.data replacement updates the parked original', restoreStyleCss(dataSetterStyle), cssB);

const appendDataStyle = w.document.createElement('style');
const appendDataNode = w.document.createTextNode(cssA);
appendDataStyle.appendChild(appendDataNode);
appendDataNode.appendData(cssImport);
eq('F3: CharacterData.appendData preserves appended blocked CSS on restore', restoreStyleCss(appendDataStyle), cssA + cssImport);

const insertDataStyle = w.document.createElement('style');
const insertDataNode = w.document.createTextNode(cssB);
insertDataStyle.appendChild(insertDataNode);
insertDataNode.insertData(0, cssImport);
eq('F3: CharacterData.insertData preserves insertion order on restore', restoreStyleCss(insertDataStyle), cssImport + cssB);

const htmlBox = w.document.createElement('div');
htmlBox.innerHTML = '<style id="faz-innerhtml-style">' + cssA + '</style>';
const innerHtmlStyle = htmlBox.querySelector('#faz-innerhtml-style');
eq('F4: parent innerHTML neutralizes style CSS before insertion', innerHtmlStyle.textContent.includes('fonts.gstatic.com'), false);
eq('F4: parent innerHTML parks original style CSS', typeof innerHtmlStyle.getAttribute('data-faz-css'), 'string');
eq('F4: parent innerHTML style restores original CSS after consent', restoreStyleCss(innerHtmlStyle), cssA);

const adjacentBox = w.document.createElement('div');
adjacentBox.insertAdjacentHTML('beforeend', '<style id="faz-adjacent-style">' + cssImport + '</style>');
const adjacentStyle = adjacentBox.querySelector('#faz-adjacent-style');
eq('F4: insertAdjacentHTML neutralizes and restores blocked style CSS', restoreStyleCss(adjacentStyle), cssImport);

// ---------------------------------------------------------------------------
// Review coverage: blast-radius (global Element.innerHTML/CharacterData gates),
// no-false-positives, scope breadth, DOM-follow restore edges, and opt-in mode.
// ---------------------------------------------------------------------------
console.log('\nStyle gate — review coverage');
const doc = w.document;
const mkStyle = (c) => { const s = doc.createElement('style'); s.textContent = c; return s; };

// R1-R4 — Element.innerHTML global gate must not touch non-blocked content.
const rb1 = doc.createElement('div'); rb1.innerHTML = '<p id="rp">hi</p>';
eq('R1: innerHTML plain markup left intact (element present)', !!rb1.querySelector('#rp'), true);
eq('R2: innerHTML plain markup not parked', rb1.innerHTML.indexOf('data-faz-css') === -1, true);
const rb2 = doc.createElement('div'); rb2.innerHTML = '<style id="rb">.ok{color:red}</style>';
eq('R3: innerHTML benign <style> left intact', rb2.querySelector('#rb').textContent.indexOf('.ok{color:red}') !== -1, true);
eq('R4: innerHTML benign <style> not parked', rb2.querySelector('#rb').getAttribute('data-faz-css'), null);

// R5-R7 — innerHTML with a blocked <style>: neutralized live, restores on consent.
const rb3 = doc.createElement('div'); rb3.innerHTML = '<style id="rbk">' + cssImport + '</style>';
const rb3s = rb3.querySelector('#rbk');
eq('R5: innerHTML blocked <style> neutralized live', rb3s.textContent.includes('fonts.googleapis.com'), false);
eq('R6: innerHTML blocked <style> parked', typeof rb3s.getAttribute('data-faz-css'), 'string');
eq('R7: innerHTML blocked <style> restores original', restoreStyleCss(rb3s), cssImport);

// R8-R9 — insertAdjacentHTML no-false-positive + position preserved.
const rb5 = doc.createElement('div'); rb5.innerHTML = '<i>keep</i>';
rb5.insertAdjacentHTML('afterbegin', '<b id="ri">bold</b>');
eq('R8: insertAdjacentHTML non-style left intact', !!rb5.querySelector('#ri'), true);
eq('R9: insertAdjacentHTML preserves position (afterbegin first)', rb5.firstElementChild.id, 'ri');

// R10 — CharacterData.data on a text node OUTSIDE a <style> passes through untouched.
const plainDiv = doc.createElement('div'); const ptn = doc.createTextNode('start'); plainDiv.appendChild(ptn);
ptn.data = '@import "https://fonts.googleapis.com/css";';
eq('R10: CharacterData.data outside <style> is untouched', ptn.data, '@import "https://fonts.googleapis.com/css";');

// R11-R12 — replaceData inside a <style> neutralizes + restores.
const rdStyle = mkStyle('.safe{color:#123}');
rdStyle.firstChild.replaceData(0, rdStyle.firstChild.length, cssImport);
eq('R11: replaceData neutralizes blocked CSS live', rdStyle.textContent.includes('fonts.googleapis.com'), false);
eq('R12: replaceData restores original CSS', restoreStyleCss(rdStyle), cssImport);

// R13-R14 — deleteData / return-value contract on a <style> text node.
const ddStyle = mkStyle('.a{color:red}');
ddStyle.firstChild.deleteData(0, 3);
eq('R13: deleteData on a benign style leaves it un-parked', ddStyle.getAttribute('data-faz-css'), null);
const cdStyle = mkStyle('.z{color:red}');
eq('R14: gated appendData returns undefined (native contract)', cdStyle.firstChild.appendData('.q{color:blue}'), undefined);

// R15-R16 — benign appendData stays live, style not parked.
const adBenign = mkStyle('.a{color:red}');
adBenign.firstChild.appendData('.later{color:green}');
eq('R15: benign appendData stays live', adBenign.textContent.includes('.later{color:green}'), true);
eq('R16: benign style not parked', adBenign.getAttribute('data-faz-css'), null);

// R17-R18 — scope breadth: any blocked-provider url() (not just @font-face).
eq('R17: background-image url() to a blocked provider is neutralized',
  mkStyle('.bg{background-image:url("' + GFONT + '")}').textContent.includes('fonts.gstatic.com'), false);
eq('R18: cursor url() to a blocked provider is neutralized',
  mkStyle('.cur{cursor:url("' + GFONT + '"),auto}').textContent.includes('fonts.gstatic.com'), false);

// R19-R21 — no-false-positive: non-provider, data:, and untouched styles.
const np = mkStyle('.np{background:url("https://cdn.example.com/x.png")}');
eq('R19: non-provider url() is not neutralized', np.textContent.includes('cdn.example.com/x.png'), true);
eq('R20: non-provider style not parked', np.getAttribute('data-faz-css'), null);
eq('R21: data: url() is not neutralized',
  mkStyle('.d{background:url("data:image/gif;base64,R0lGODlhAQABAAAAACw=")}').textContent.includes('data:image/gif'), true);

// R22 — blocking globally off → blocked CSS is not neutralized.
cfg._block = '';
eq('R22: blocking globally off → blocked CSS not neutralized',
  mkStyle(cssImport).textContent.includes('fonts.googleapis.com'), true);
cfg._block = '1';

// R23 — a user-whitelisted provider is not neutralized.
cfg._userWhitelist = ['fonts.gstatic.com'];
eq('R23: whitelisted provider url() is not neutralized',
  mkStyle('.w{background-image:url("' + GFONT + '")}').textContent.includes('fonts.gstatic.com'), true);
cfg._userWhitelist = [];

// R24 — replaceChild with an orphan oldNode: native passthrough, gate not corrupted.
const rc2 = mkStyle('.x{color:red}');
try { rc2.replaceChild(doc.createTextNode('.y{color:blue}'), doc.createTextNode('orphan')); } catch (e) { /* native NotFoundError is fine */ }
eq('R24: replaceChild with orphan oldNode does not corrupt the style', rc2.textContent.includes('.x{color:red}'), true);

// R25 — DOM-follow: a removed blocked node is not resurrected on restore.
const rem = doc.createElement('style');
rem.appendChild(doc.createTextNode(cssSafe));
const drop = doc.createTextNode(cssImport);
rem.appendChild(drop);
rem.removeChild(drop);
eq('R25: removed blocked node is not resurrected on restore', restoreStyleCss(rem).includes('fonts.googleapis.com'), false);

// R26-R29 — Constructable Stylesheets (adoptedStyleSheets) gate.
console.log('\nConstructable Stylesheet gate (adoptedStyleSheets)');
const csSheet = new w.CSSStyleSheet();
csSheet.replaceSync('.cs{background-image:url("' + GFONT + '")}');
eq('R26: replaceSync neutralizes a blocked provider url()', csSheet.cssRules[0].cssText.includes('fonts.gstatic.com'), false);
eq('R27: the blocked constructable sheet is tracked for restore', w.eval('_fazTrackedSheets.length') >= 1, true);
setConsent({ functional: 'yes' });
w.eval('_fazUnblockServerSide()');
eq('R28: consent restores the original sheet CSS', csSheet.cssRules[0].cssText.includes('fonts.gstatic.com'), true);
eq('R28b: restored sheet is untracked', w.eval('_fazTrackedSheets.length'), 0);
resetConsent();
const benignSheet = new w.CSSStyleSheet();
benignSheet.replaceSync('.b{color:red}');
eq('R29: benign replaceSync is not tracked', w.eval('_fazTrackedSheets.length'), 0);
eq('R29b: benign replaceSync is not neutralized', benignSheet.cssRules[0].cssText.includes('red'), true);

// R30-R33 — aggressive CSS-url mode is opt-in. Baseline still gates direct
// HTMLStyleElement writes, but global HTML-string and Constructable Stylesheet
// hooks are not installed when the setting is false/default.
console.log('\nAggressive CSS URL blocking setting');
const wStd = loadFrontend({ aggressiveCssUrlBlocking: false });
const stdStyle = wStd.document.createElement('style');
stdStyle.textContent = cssA;
eq('R30: standard mode still blocks direct HTMLStyleElement textContent', stdStyle.textContent.includes('fonts.gstatic.com'), false);
const stdBox = wStd.document.createElement('div');
stdBox.innerHTML = '<style id="std-style">' + cssA + '</style>';
eq('R31: standard mode does not hook parent innerHTML style insertion', stdBox.querySelector('#std-style').textContent.includes('fonts.gstatic.com'), true);
const stdAdjacent = wStd.document.createElement('div');
stdAdjacent.insertAdjacentHTML('beforeend', '<style id="std-adjacent">' + cssImport + '</style>');
eq('R32: standard mode does not hook insertAdjacentHTML style insertion', stdAdjacent.querySelector('#std-adjacent').textContent.includes('fonts.googleapis.com'), true);
const stdSheet = new wStd.CSSStyleSheet();
stdSheet.replaceSync('.std{background-image:url("' + GFONT + '")}');
eq('R33: standard mode does not hook Constructable Stylesheets', stdSheet.cssRules[0].cssText.includes('fonts.gstatic.com'), true);

// RF1 / RF2 — per-branch review regressions.
console.log('\nReview-fix regressions');
// RF1: a benign textContent overwrite after a prior blocked write must not
// resurrect the old blocked CSS on restore (the single-node prime heuristic must
// not re-attribute the stale data-faz-css to the fresh benign node).
const ovStyle = w.document.createElement('style');
ovStyle.textContent = cssA;                        // blocked (gstatic) -> parked
ovStyle.textContent = '.benign-after{color:#0a0}'; // benign overwrite, fresh node + stale attr
eq('RF1: benign overwrite after a blocked write does not resurrect old CSS on restore', restoreStyleCss(ovStyle), '.benign-after{color:#0a0}');
// RF2: two blocked Constructable Stylesheets must BOTH restore — the gate's
// re-entrant untrack splices _fazTrackedSheets mid-walk, so the restore loop
// must iterate a snapshot or it skips every other sheet.
const sh1 = new w.CSSStyleSheet(); sh1.replaceSync('.s1{background-image:url("' + GFONT + '")}');
const sh2 = new w.CSSStyleSheet(); sh2.replaceSync('.s2{background-image:url("https://fonts.gstatic.com/s2.woff2")}');
eq('RF2: both constructable sheets tracked', w.eval('_fazTrackedSheets.length') >= 2, true);
setConsent({ functional: 'yes' });
w.eval('_fazUnblockServerSide()');
eq('RF2: first constructable sheet restored', sh1.cssRules[0].cssText.includes('fonts.gstatic.com'), true);
eq('RF2: second constructable sheet restored (not skipped by mid-iteration splice)', sh2.cssRules[0].cssText.includes('fonts.gstatic.com'), true);
resetConsent();

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
