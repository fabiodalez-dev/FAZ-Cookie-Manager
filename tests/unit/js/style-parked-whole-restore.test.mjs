/**
 * JS unit test (jsdom) — the runtime's half of the contract bootstrap.js parks
 * against.
 *
 * bootstrap.js can decide that a <style> must not be applied at all and park it
 * WHOLE: the live text becomes one empty text node and the original CSS moves
 * into data-faz-css. That shape only means something if the runtime, once it
 * loads, can read it back — adopt the original from the marker, and keep the
 * marker through its own re-sync so the restore pass finds it on consent.
 *
 * Two things broke that. Adoption was refused unless the live text equalled the
 * marker's neutralised form, which an empty text node never does, so a sheet
 * parked whole was never adopted. And the re-sync keeps the marker only while
 * the ORIGINAL still reads as blocking, a question the runtime's extractor asked
 * on the raw text alone — so every spelling that hides a URL behind a CSS escape
 * (`u\72l(`, an escaped space, an escape in the host) read as clean and the
 * marker was removed. The sheet then stayed inert for the rest of the page view
 * and was never restored: it fails closed, but the visitor who consented does
 * not get their stylesheet back.
 *
 * Loads the REAL frontend/js/script.js with its DOMContentLoaded bootstrap
 * neutralised, and calls the internals as Annex-B globals.
 *
 * Run: node tests/unit/js/style-parked-whole-restore.test.mjs
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

function loadFrontend() {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://site.test/',
  });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [{ re: 'tracker.test', categories: ['marketing'] }],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    _aggressiveCssUrlBlocking: true,
    i18n: {},
  };
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

console.log('a sheet parked whole is readable by the runtime that finds it');

const w = loadFrontend();
const shouldBlock = (css) => w.eval(`_fazCssShouldBlock(${JSON.stringify(css)})`);

// ---------------------------------------------------------------------------
// The question the re-sync asks: does the ORIGINAL still read as blocking?
// Every spelling here names the same third party and the engine fetches it.
// ---------------------------------------------------------------------------
console.log('\n_fazCssShouldBlock() — the spellings the engine resolves');

eq('a plain url() is blocking', shouldBlock('.p{background-image:url(https://tracker.test/a.png)}'), true);
eq('an escape in the function name is blocking', shouldBlock('.p{background-image:u\\72l(https://tracker.test/b.png)}'), true);
eq('a hex escape with its trailing space is blocking', shouldBlock('.p{background-image:\\75 rl(https://tracker.test/c.png)}'), true);
eq('an escape in the host is blocking', shouldBlock('.p{background-image:url(https://tracker\\.test/d.png)}'), true);
eq('an escaped space in an unquoted url( is blocking', shouldBlock('.p{background-image:url(https://tracker.test/e\\ 1.png)}'), true);
eq('a hex-escaped space in an unquoted url( is blocking', shouldBlock('.p{background-image:url(https://tracker.test/e\\20 2.png)}'), true);
eq(
  'comment markers held inside strings do not erase the token between them',
  shouldBlock('.a{content:"/*"}.p{background-image:u\\72l(https://tracker.test/f.png)}.b{content:"*/"}'),
  true
);
eq('@import with no whitespace is blocking', shouldBlock('@import"https://tracker.test/g.css";'), true);

// The controls the narrow question exists for: holding these is what asking the
// broad question of every sheet used to cost a theme.
console.log('\n_fazCssShouldBlock() — what must stay live');

eq('a comment and an icon-font escape name no URL', shouldBlock('/* theme */ body{color:#123} .icon:before{content:"\\f101"}'), false);
eq('a same-origin url() is not blocking', shouldBlock('.p{background-image:url(https://site.test/own.png)}'), false);
eq('a relative url() is not blocking', shouldBlock('.p{background-image:url(/wp-content/own.png)}'), false);
eq('a data: url() is not blocking', shouldBlock('.p{background-image:url("data:image/gif;base64,R0lGODlhAQABAAAAACw=")}'), false);
eq('an unrelated third party is not blocking', shouldBlock('.p{background-image:url(https://example.com/ok.png)}'), false);
eq('an escaped comment marker alone is not a URL', shouldBlock('.a{content:"\\2f\\2a"} .b{color:red}'), false);

// ---------------------------------------------------------------------------
// The shape bootstrap.js leaves behind, read back through the runtime's own
// adopt + re-sync pair.
// ---------------------------------------------------------------------------
console.log('\nadopt and re-sync on the shape bootstrap.js parks');

function parkedWhole(window, original) {
  const style = window.document.createElement('style');
  window.document.head.appendChild(style);
  // What emptySheet() (bootstrap.js) builds: exactly one EMPTY text node, with
  // the original in the marker. Appended as a node so none of the runtime's
  // text setters is the thing under test here.
  style.appendChild(window.document.createTextNode(''));
  style.setAttribute('data-faz-css', Buffer.from(original, 'utf8').toString('base64'));
  style.setAttribute('data-faz-category', 'marketing');
  return style;
}
function markerCss(style) {
  const encoded = style.getAttribute('data-faz-css');
  return encoded ? Buffer.from(encoded, 'base64').toString('utf8') : '';
}

{
  // A form neutralise() cannot rewrite one token at a time, which is exactly
  // when bootstrap.js parks a sheet whole.
  const ORIGINAL = '@import"https://tracker.test/h1.css";';
  const style = parkedWhole(w, ORIGINAL);
  w.eval('_fazPrimeStyleCssMarkers(document.head.lastChild)');
  eq('a sheet parked whole has its original adopted', w.eval('_fazStyleOriginalCss(document.head.lastChild)'), ORIGINAL);
  w.eval('_fazSyncStyleCssAttribute(document.head.lastChild)');
  eq('and the re-sync keeps the marker', markerCss(style), ORIGINAL);
}

{
  // The spelling the runtime could not see at all: adoption AND the re-sync
  // both have to hold, or the marker is gone and consent restores nothing.
  const ORIGINAL = '.p{background-image:u\\72l(https://tracker.test/h2.png)}';
  const style = parkedWhole(w, ORIGINAL);
  w.eval('_fazPrimeStyleCssMarkers(document.head.lastChild)');
  eq('a sheet parked whole for an escaped URL is adopted too', w.eval('_fazStyleOriginalCss(document.head.lastChild)'), ORIGINAL);
  w.eval('_fazSyncStyleCssAttribute(document.head.lastChild)');
  eq('and keeps its marker through the re-sync', markerCss(style), ORIGINAL);
}

{
  // The page's own first append through the runtime's gate: the original must
  // survive it, with the appended text alongside.
  const ORIGINAL = '.p{background-image:u\\72l(https://tracker.test/h3.png)}';
  const style = parkedWhole(w, ORIGINAL);
  w.eval('_fazPrimeStyleCssMarkers(document.head.lastChild)');
  style.appendChild(w.document.createTextNode('.z{color:blue}'));
  w.eval('_fazSyncStyleCssAttribute(document.head.lastChild)');
  ok("after the page's first append the marker is still there", !!style.getAttribute('data-faz-css'));
  eq('and still carries the original', markerCss(style).indexOf(ORIGINAL), 0);
}

{
  // The guard the equality was there for, which must not be lost: a live text
  // that is DIFFERENT CSS is the author's, not a park, so a stale marker beside
  // it is not adopted and the blocked original is never resurrected.
  const ORIGINAL = '.p{background-image:url(https://tracker.test/stale.png)}';
  const style = w.document.createElement('style');
  w.document.head.appendChild(style);
  style.appendChild(w.document.createTextNode('body{color:red}'));
  style.setAttribute('data-faz-css', Buffer.from(ORIGINAL, 'utf8').toString('base64'));
  w.eval('_fazPrimeStyleCssMarkers(document.head.lastChild)');
  eq('a stale marker beside the author’s own CSS is not adopted', w.eval('_fazStyleOriginalCss(document.head.lastChild)'), 'body{color:red}');
  w.eval('_fazSyncStyleCssAttribute(document.head.lastChild)');
  eq('and the re-sync drops it', style.getAttribute('data-faz-css'), null);
}

// ---------------------------------------------------------------------------
// The text setters park a sheet whole too, and they have to leave the original
// somewhere. Writing the empty string through textContent removes every child,
// so there was no text node left to attach the original to, and the re-sync
// then rebuilt the marker from nothing and dropped it.
// ---------------------------------------------------------------------------
console.log('\nthe text setters keep the original when they park whole');

for (const [label, prop] of [['textContent', 'textContent'], ['innerHTML', 'innerHTML']]) {
  const ORIGINAL = '.p{background-image:u\\72l(https://tracker.test/t-' + prop + '.png)}';
  const style = w.document.createElement('style');
  w.document.head.appendChild(style);
  style[prop] = ORIGINAL;
  eq(prop + ' leaves nothing live', style.childNodes.length === 1 ? (style.childNodes[0].nodeValue || '') : '', '');
  eq(prop + ' keeps the original in the marker', markerCss(style), ORIGINAL);
}

// ---------------------------------------------------------------------------
// insertRule: the one gated CSS route that can THROW, and therefore the one
// where a catch-all fallback is a leak. Once the rule is refused, whatever
// happens next, the original must not be what lands in the sheet.
// ---------------------------------------------------------------------------
console.log('\ninsertRule never falls back to the rule it refused');

function insertedRules(style) {
  const out = [];
  const sheet = style.sheet;
  if (!sheet) return out;
  for (let i = 0; i < sheet.cssRules.length; i += 1) out.push(sheet.cssRules[i].cssText || '');
  return out;
}

{
  const style = w.document.createElement('style');
  w.document.head.appendChild(style);
  ok('premise: jsdom gave the <style> a sheet', !!style.sheet);
  // A plain url(): neutralisable one token at a time, so the inert form lands
  // and the tracker URL is nowhere in the sheet.
  style.sheet.insertRule('.a{background-image:url(https://tracker.test/r1.png)}', 0);
  const after = insertedRules(style).join(' ');
  ok('a plain blocked url() is inserted neutralised', after.indexOf('tracker.test') === -1);
}

{
  const style = w.document.createElement('style');
  w.document.head.appendChild(style);
  // The shape that neutralises to nothing: an escape the rewriters cannot
  // reach. insertRule('') throws, and the old catch sent the ORIGINAL to the
  // native method — the single outcome this gate exists to prevent.
  const RULE = '.p{background-image:u\\72l(https://tracker.test/r2.png)}';
  let threw = false;
  let idx;
  try { idx = style.sheet.insertRule(RULE, 0); } catch (_e) { threw = true; }
  ok('the call does not throw at the caller', !threw);
  const after = insertedRules(style).join(' ');
  ok('and the refused rule is not what landed', after.indexOf('tracker.test') === -1);
  // The decisive half. A gate that inserts NOTHING reads the same as one that
  // inserted the original in every "is the tracker there" check, and in a real
  // engine insertRule('') throws — which is what used to send the untouched
  // original to the native method. So the refusal has to leave an inert rule
  // in the slot the caller asked for, and answer with its index.
  eq('a refused rule still occupies its slot', style.sheet.cssRules.length, 1);
  eq('and the call answers with that index', idx, 0);
  // And the slot holds the INERT rule, not the refused one. Checking only for
  // the tracker's host is not enough here: jsdom's CSS parser silently drops a
  // declaration whose value it cannot parse, so the original lands as `.p { }`
  // and reads clean while a real engine keeps the escape and fetches it.
  const landed = (style.sheet.cssRules[0].cssText || '').replace(/\s+/g, '');
  ok('and that slot holds an inert rule, not the refused selector', landed.indexOf('@medianotall') === 0);
}

{
  const style = w.document.createElement('style');
  w.document.head.appendChild(style);
  // A rule that names no blocked URL is untouched: the gate is not a filter on
  // everything, and a caller reading the returned index must still get one.
  const idx = style.sheet.insertRule('.ok{color:red}', 0);
  eq('an unblocked rule keeps its index', idx, 0);
  ok('and lands as written', insertedRules(style).join(' ').indexOf('color: red') !== -1
    || insertedRules(style).join(' ').indexOf('color:red') !== -1);
}

console.log('');
if (failed === 0) {
  console.log(`\x1b[32mstyle-parked-whole-restore: ${passed} passed, 0 failed\x1b[0m`);
  process.exit(0);
}
console.log(`\x1b[31mstyle-parked-whole-restore: ${passed} passed, ${failed} failed\x1b[0m`);
process.exit(1);
