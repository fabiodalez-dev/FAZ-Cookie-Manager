/**
 * JS unit test (jsdom) — runtime defects found by an independent chromium
 * review of the merged tree, all in frontend/js/script.js.
 *
 * CSS half:
 *   1. A URL split across two append() arguments, or two consecutive
 *      appendChild() calls, is judged on the RESULTING text: the per-argument
 *      gate saw no URL in either piece, wrote the marker, and left the live
 *      text fetching.
 *   2. The CSS tokenizer's own spellings: `@import"…"` with no whitespace,
 *      the string form of image-set(), `u\72l(`, `\75 rl(`, and an escape
 *      inside the host. Each fetched; a sheet that cannot be neutralised in
 *      place is blocked whole.
 *   6. CSSStyleSheet.addRule and CSSGroupingRule.insertRule are gated like
 *      CSSStyleSheet.insertRule.
 *   7. A CSS url() is judged on host+path, the way policy.url judges it for
 *      the bootstrap, so `?u=tracker.test` in a query string is not a match
 *      and the two layers agree on the neutralised form.
 *
 * Routes half:
 *   3. setAttributeNS with a non-xlink prefix on the xlink namespace is the
 *      same attribute; the gate keys on namespace + local name and the sweep
 *      reads it through getAttributeNS.
 *   4. The mutation observer parks SVG image/use/feImage, [background] and
 *      iframe[srcdoc] built from markup after the handoff.
 *   5. srcdoc markup is searched for URLs after entities and `\/` are decoded.
 *
 * Run: node tests/unit/js/runtime-css-and-routes.test.mjs
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
function ok(label, cond) {
  eq(label, !!cond, true);
}

const SITE = 'http://site.test/';
const SVG_NS = 'http://www.w3.org/2000/svg';
const XLINK_NS = 'http://www.w3.org/1999/xlink';
const INERT = 'data:application/octet-stream,';
const runtimeCode = readFileSync(SCRIPT_PATH, 'utf8');

function runtimeConfig() {
  return {
    _block: '1',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'functional', isNecessary: false },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [
      { re: 'tracker.test', categories: ['marketing'], service: 'tracker' },
    ],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    _aggressiveCssUrlBlocking: true,
    i18n: {},
  };
}
function runtime({ body = '', head = '' } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head>${head}</head><body>${body}</body></html>`, {
    runScripts: 'outside-only',
    url: SITE,
  });
  const { window } = dom;
  window._fazConfig = runtimeConfig();
  window.fazcookie = { _fazGetFromStore: () => undefined };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(runtimeCode);
  window.document.addEventListener = realAdd;
  return window;
}
function consent(window, map) {
  window.fazcookie._fazGetFromStore = (k) => (k in map ? map[k] : undefined);
}
const tick = () => new Promise((r) => setTimeout(r, 0));

console.log('\ncss: a URL split across writes is judged on the resulting text');

// 1a — two append() arguments.
{
  const w = runtime();
  const s = w.document.createElement('style');
  w.document.head.appendChild(s);
  s.append('.p{background-image:u', 'rl(https://tracker.test/c.png)}');
  ok('append of two halves: the live text names no tracker', s.textContent.indexOf('tracker.test') === -1);
  ok('and holds the inert URL instead', s.textContent.indexOf(INERT) !== -1);
  ok('with the original parked in data-faz-css', !!s.getAttribute('data-faz-css'));
  consent(w, { marketing: 'yes' });
  w.eval('_fazUnblockServerSide()');
  eq('and restored whole on consent', s.textContent, '.p{background-image:url(https://tracker.test/c.png)}');
}
// 1b — two consecutive appendChild() calls.
{
  const w = runtime();
  const s = w.document.createElement('style');
  w.document.head.appendChild(s);
  s.appendChild(w.document.createTextNode('.p{background-image:u'));
  s.appendChild(w.document.createTextNode('rl(https://tracker.test/d.png)}'));
  ok('appendChild twice: the live text names no tracker', s.textContent.indexOf('tracker.test') === -1);
  ok('and holds the inert URL instead', s.textContent.indexOf(INERT) !== -1);
  ok('with the original parked', !!s.getAttribute('data-faz-css'));
}
// 1c — control: two halves that name nothing blocked stay as written.
{
  const w = runtime();
  const s = w.document.createElement('style');
  w.document.head.appendChild(s);
  s.append('.p{background-image:u', 'rl(/local.png)}');
  eq('a split same-origin URL is left alone', s.textContent, '.p{background-image:url(/local.png)}');
  eq('and not parked', s.getAttribute('data-faz-css'), null);
}

console.log('\ncss: the tokenizer\'s own spellings');

const CSS_SHAPES = [
  ['@import with no whitespace', '@import"https://tracker.test/c.css";body{color:red}'],
  ['image-set string form', '.p{background-image:image-set("https://tracker.test/c.png" 1x)}'],
  ['u\\72l( escape in the function name', '.p{background:u\\72l(https://tracker.test/c.png)}'],
  ['\\75 rl( escape in the function name', '.p{background:\\75 rl(https://tracker.test/c.png)}'],
  ['an escape inside the host', '.p{background:url(https://tracker\\.test/c.png)}'],
];
for (const [label, css] of CSS_SHAPES) {
  const w = runtime();
  const s = w.document.createElement('style');
  s.textContent = css;
  ok(`${label}: the live text names no tracker`, s.textContent.indexOf('tracker') === -1);
  ok(`${label}: the original is parked`, !!s.getAttribute('data-faz-css'));
  w.document.head.appendChild(s);
  consent(w, { marketing: 'yes' });
  w.eval('_fazUnblockServerSide()');
  eq(`${label}: restored on consent`, s.textContent, css);
}
{
  const w = runtime();
  const s = w.document.createElement('style');
  s.textContent = '.p{background:url("/local.png")}@import"/local.css";';
  eq('control: a same-origin url() and @import are untouched', s.textContent, '.p{background:url("/local.png")}@import"/local.css";');
  eq('and not parked', s.getAttribute('data-faz-css'), null);
}

console.log('\ncss: the other CSSOM entry points');

{
  const w = runtime({ head: '<style id="s">@media screen{.a{color:red}}</style>' });
  const sheet = w.document.getElementById('s').sheet;
  if (sheet && typeof sheet.addRule === 'function') {
    sheet.addRule('.x', 'background:url(https://tracker.test/r.png)');
    const text = Array.from(sheet.cssRules).map((r) => r.cssText).join(' ');
    ok('addRule: the inserted rule names no tracker', text.indexOf('tracker.test') === -1);
  } else {
    console.log('  skip addRule: not supported by this jsdom');
  }
  const group = sheet && sheet.cssRules[0];
  if (group && typeof group.insertRule === 'function') {
    group.insertRule('.y{background:url(https://tracker.test/g.png)}', 0);
    const inner = Array.from(group.cssRules).map((r) => r.cssText).join(' ');
    ok('CSSGroupingRule.insertRule: the inserted rule names no tracker', inner.indexOf('tracker.test') === -1);
  } else {
    console.log('  skip CSSGroupingRule.insertRule: not supported by this jsdom');
  }
}

console.log('\ncss: the URL is judged on host and path, as policy.url judges it');

{
  const w = runtime();
  const decoy = '.p{background:url(https://cdn.site.test/p.png?u=tracker.test)}';
  eq('a query string naming the tracker is not a match', w.eval(`_fazNeutralizeCssUrls(${JSON.stringify(decoy)})`), decoy);
  const real = '.p{background:url(https://tracker.test/p.png?x=1)}';
  ok('while the tracker host still is', w.eval(`_fazNeutralizeCssUrls(${JSON.stringify(real)})`).indexOf(INERT) !== -1);
  const s = w.document.createElement('style');
  s.textContent = decoy;
  eq('so a style carrying the decoy is left live and unparked', s.getAttribute('data-faz-css'), null);
}

console.log('\nroutes: the xlink namespace under another prefix');

// 3 — the gate keys on namespace + local name. jsdom has no SVGImageElement,
// so the real gate function is installed on SVGElement.prototype here.
{
  const w = runtime();
  w.eval('_fazGateResourceSetAttribute(SVGElement.prototype, { urlAttr: "href", urlAltAttr: "xlink:href", parkAttr: "data-faz-href", hideOnPark: false, srcset: false, ns: true })');
  const img = w.document.createElementNS(SVG_NS, 'image');
  img.setAttributeNS(XLINK_NS, 'p:href', 'https://tracker.test/p.svg');
  eq('setAttributeNS(xlink, "p:href"): the namespaced attribute is not written', img.getAttributeNS(XLINK_NS, 'href'), null);
  eq('it is parked', img.getAttribute('data-faz-href'), 'https://tracker.test/p.svg');
  const plain = w.document.createElementNS(SVG_NS, 'image');
  plain.setAttributeNS(null, 'href', 'https://tracker.test/n.svg');
  eq('setAttributeNS(null, "href") is the SVG2 attribute and is gated too', plain.getAttribute('href'), null);
  eq('and parked', plain.getAttribute('data-faz-href'), 'https://tracker.test/n.svg');
  const local = w.document.createElementNS(SVG_NS, 'image');
  local.setAttributeNS(XLINK_NS, 'p:href', '/local.svg');
  eq('a same-origin one under the prefix is written', local.getAttributeNS(XLINK_NS, 'href'), '/local.svg');
}
// 3b — the sweep reads the attribute through its namespace, whatever prefix.
{
  const w = runtime();
  const img = w.document.createElementNS(SVG_NS, 'image');
  img.setAttributeNS(XLINK_NS, 'p:href', 'https://tracker.test/s.svg'); // no gate on jsdom's SVGElement
  w.document.body.appendChild(img);
  w.eval('_fazParkResourceElementIfBlocked')(img);
  eq('the sweep removes a prefixed xlink:href', img.getAttributeNS(XLINK_NS, 'href'), null);
  eq('and parks it', img.getAttribute('data-faz-href'), 'https://tracker.test/s.svg');
  consent(w, { marketing: 'yes' });
  w.eval('_fazUnblockServerSide()');
  eq('restored as the SVG2 href on consent', img.getAttribute('href'), 'https://tracker.test/s.svg');
}

console.log('\nroutes: markup built after the handoff');

// 4 — the observer's element set.
{
  const w = runtime({ body: '<div id="h"></div>' });
  const h = w.document.getElementById('h');
  h.innerHTML = '<svg><image id="i" href="https://tracker.test/c.svg"/><use id="u" href="https://tracker.test/s.svg#i"/><feImage id="f" href="https://tracker.test/f.png"/></svg>'
    + '<table id="t" background="https://tracker.test/b.png"><tr><td>x</td></tr></table>'
    + '<iframe id="d" srcdoc="<img src=&quot;https://tracker.test/d.png&quot;>"></iframe>'
    + '<img id="m" src="https://tracker.test/i.png">';
  await tick();
  const byId = (id) => w.document.getElementById(id);
  eq('control: a sibling <img> is parked', byId('m').getAttribute('data-faz-src'), 'https://tracker.test/i.png');
  eq('an SVG <image> built from markup has no live href', byId('i').getAttribute('href'), null);
  eq('and is parked', byId('i').getAttribute('data-faz-href'), 'https://tracker.test/c.svg');
  eq('<use> likewise', byId('u').getAttribute('data-faz-href'), 'https://tracker.test/s.svg#i');
  eq('<feImage> likewise', byId('f').getAttribute('data-faz-href'), 'https://tracker.test/f.png');
  eq('a [background] element is parked', byId('t').getAttribute('data-faz-background'), 'https://tracker.test/b.png');
  eq('with no live background', byId('t').getAttribute('background'), null);
  eq('an <iframe srcdoc> is parked', byId('d').getAttribute('data-faz-srcdoc'), '<img src="https://tracker.test/d.png">');
  eq('with no live srcdoc', byId('d').getAttribute('srcdoc'), null);
}
// 4b — the same, nested in a wrapper the page builder inserts.
{
  const w = runtime({ body: '<div id="h"></div>' });
  const wrap = w.document.createElement('div');
  wrap.innerHTML = '<p><svg><image id="n" href="https://tracker.test/n.svg"/></svg></p>';
  w.document.getElementById('h').appendChild(wrap);
  await tick();
  eq('an SVG image inside an inserted wrapper is parked', w.document.getElementById('n').getAttribute('data-faz-href'), 'https://tracker.test/n.svg');
}

console.log('\nroutes: a srcdoc URL spelled through entities or escaped slashes');

// 5 — the markup is searched after decoding.
{
  const w = runtime();
  const a = w.document.createElement('iframe');
  a.srcdoc = '<img src="https:&#x2F;&#x2F;tracker.test/p.png">';
  eq('an entity-encoded // is seen', a.getAttribute('srcdoc'), null);
  eq('and the document parked', a.getAttribute('data-faz-srcdoc'), '<img src="https:&#x2F;&#x2F;tracker.test/p.png">');
  const b = w.document.createElement('iframe');
  b.srcdoc = '<script>var u="https:\\/\\/tracker.test/x.js";</script>';
  eq('a JSON-escaped // is seen', b.getAttribute('srcdoc'), null);
  const c = w.document.createElement('iframe');
  c.srcdoc = '<p>https:&#x2F;&#x2F;site.test/own</p>';
  eq('control: a same-origin URL through entities is applied', c.getAttribute('srcdoc'), '<p>https:&#x2F;&#x2F;site.test/own</p>');
}

console.log('\nruntime: a URL is matched as the engine resolves it (server parity)');

// The server normalises a URL before matching: every `\` is a `/` for a
// special scheme, a scheme takes its two slashes, and the host is what
// follows the authority's last `@`. The runtime matched the raw string.
{
  const w = runtime();
  const matches = (u) => w.eval(`_fazMatchingProviders(${JSON.stringify(u)}).length`);
  const blocks = (u) => w.eval(`_fazImgShouldBlock(null, ${JSON.stringify(u)})`);
  const endpoint = (u) => w.eval(`_fazExtractEndpoint(${JSON.stringify(u)})`);
  eq('userinfo before the host: matched', matches('https://site.test@tracker.test/p'), 1);
  eq('and blocked', blocks('https://site.test@tracker.test/p'), true);
  eq('scheme followed by backslashes: matched', matches('https:\\\\tracker.test/p'), 1);
  eq('and blocked', blocks('https:\\\\tracker.test/p'), true);
  eq('scheme-relative backslashes: matched', matches('\\\\tracker.test/p'), 1);
  eq('and blocked', blocks('\\\\tracker.test/p'), true);
  eq('and its endpoint is the host and path', endpoint('\\\\tracker.test/p'), 'tracker.test/p');
  eq('a backslash-closed authority: the host is site.test, not matched', matches('https://site.test\\@tracker.test/p'), 0);
  eq('and not blocked', blocks('https://site.test\\@tracker.test/p'), false);
  eq('a tracker host in a query is not matched', matches('/local.png?u=me@tracker.test'), 0);
  eq('control: the plain tracker URL still matches', matches('https://tracker.test/p'), 1);
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
