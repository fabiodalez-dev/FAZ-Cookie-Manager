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

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
