/**
 * JS unit test (jsdom) — guards the #158 work: FAZ's script blocker must NOT
 * intercept native WP ES modules / importmaps (Interactivity API) or
 * optimiser-deferred placeholders (LiteSpeed / WP Rocket "Delay JS") that are
 * NOT trackers, while STILL blocking a tracker — even one shipped as
 * type="module" or deferred by a caching layer.
 *
 * The exemption is gated on the tracker decision (category / known provider /
 * per-service), not on the type string or a spoofable src substring:
 *   - a genuine module (no blocked category, no known provider) → left intact
 *     (the Interactivity API keeps working);
 *   - a module / classic tracker carrying a blocked category → still blocked
 *     (closes the consent-bypass — #158 review F004/F013/F005);
 *   - the type setter judges the VALUE being assigned, so a module→runnable or
 *     placeholder→runnable reassignment can't slip a tracker through (F003);
 *   - LiteSpeed/WP Rocket placeholders are left to the optimiser and re-blocked
 *     when their type flips back to a runnable value.
 *
 * Loads the REAL frontend/js/script.js with its DOMContentLoaded bootstrap
 * neutralised. _fazStore = window._fazConfig and ref = window.fazcookie are
 * captured at eval time, so the harness seeds them first.
 *
 * Run: node tests/unit/js/script-module-litespeed-exempt.test.mjs
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

function loadFrontend() {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  // Block-first store: marketing is non-necessary and there is no consent
  // cookie, so _fazIsCategoryToBeBlocked('marketing') === true.
  window._fazConfig = {
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
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

console.log('script-module / litespeed blocking gate (jsdom, #158)');
const w = loadFrontend();
const ev = (expr) => w.eval(expr);

// ---------------------------------------------------------------------------
// _fazIsDeferredPlaceholderType — only the non-executing caching placeholders.
// ---------------------------------------------------------------------------
console.log('\n_fazIsDeferredPlaceholderType()');
eq('litespeed/javascript → placeholder', ev('_fazIsDeferredPlaceholderType("litespeed/javascript")'), true);
eq('rocketlazyloadjs → placeholder', ev('_fazIsDeferredPlaceholderType("rocketlazyloadjs")'), true);
eq('module → NOT placeholder', ev('_fazIsDeferredPlaceholderType("module")'), false);
eq('importmap → NOT placeholder', ev('_fazIsDeferredPlaceholderType("importmap")'), false);
eq('text/javascript → NOT placeholder', ev('_fazIsDeferredPlaceholderType("text/javascript")'), false);
eq('empty → NOT placeholder', ev('_fazIsDeferredPlaceholderType("")'), false);

// ---------------------------------------------------------------------------
// _fazShouldChangeType — tracker decision gates the exemption.
// ---------------------------------------------------------------------------
console.log('\n_fazShouldChangeType()');
const elem = (attrs) => `{ classList:{contains:function(){return false;}}, src:"", getAttribute:function(k){ return (${JSON.stringify(attrs)})[k] || null; } }`;
// genuine module, no blocked category, no provider → left intact (false)
eq('module, no category → exempt (false)', ev(`_fazShouldChangeType(${elem({ type: 'module' })})`), false);
eq('importmap, no category → exempt (false)', ev(`_fazShouldChangeType(${elem({ type: 'importmap' })})`), false);
// a module carrying a blocked category IS a tracker → blocked (true)
eq('module + marketing category → block (true)', ev(`_fazShouldChangeType(${elem({ type: 'module', 'data-faz-category': 'marketing' })})`), true);
// classic tracker still blocked
eq('classic text/javascript + marketing → block (true)', ev(`_fazShouldChangeType(${elem({ type: 'text/javascript', 'data-faz-category': 'marketing' })})`), true);
// optimiser placeholder is left to the caching layer even when it is a tracker
eq('litespeed placeholder + marketing → left to optimiser (false)', ev(`_fazShouldChangeType(${elem({ type: 'litespeed/javascript', 'data-faz-category': 'marketing' })})`), false);
// typeOverride wins over the committed attribute (F003): stale 'module' attr,
// but the value being assigned is a runnable type on a marketing tracker
eq('stale module attr + typeOverride text/javascript + marketing → block (true)', ev(`_fazShouldChangeType(${elem({ type: 'module', 'data-faz-category': 'marketing' })}, undefined, "text/javascript")`), true);
// typeOverride placeholder → still left to optimiser
eq('typeOverride litespeed placeholder → left to optimiser (false)', ev(`_fazShouldChangeType(${elem({ 'data-faz-category': 'marketing' })}, undefined, "litespeed/javascript")`), false);

// ---------------------------------------------------------------------------
// document.createElement override — end-to-end behaviour.
// ---------------------------------------------------------------------------
console.log('\ndocument.createElement override');
// genuine module (no blocked category) keeps type="module" — Interactivity API.
eq('module with no blocked category stays module',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("type","module"); return s.getAttribute("type"); })()`),
  'module');
eq('importmap with no blocked category stays importmap',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("type","importmap"); return s.getAttribute("type"); })()`),
  'importmap');
eq('litespeed placeholder (no category) stays litespeed/javascript',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("type","litespeed/javascript"); return s.getAttribute("type"); })()`),
  'litespeed/javascript');
// a module tracker (marketing category) IS now blocked — closes the bypass.
eq('module tagged marketing is blocked (javascript/blocked)',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("data-faz-category","marketing"); s.setAttribute("type","module"); return s.getAttribute("type"); })()`),
  'javascript/blocked');
// classic marketing tracker still blocked.
eq('classic marketing tracker is blocked',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("data-faz-category","marketing"); s.setAttribute("type","text/javascript"); return s.getAttribute("type"); })()`),
  'javascript/blocked');
// F003: module→runnable reassignment on a tracker cannot evade (stays blocked).
eq('marketing module then text/javascript stays blocked',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("data-faz-category","marketing"); s.setAttribute("type","module"); s.setAttribute("type","text/javascript"); return s.getAttribute("type"); })()`),
  'javascript/blocked');
// F013 (type-setter half): a deferred placeholder tracker flips to runnable → blocked.
eq('marketing litespeed placeholder then runnable type is blocked',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("data-faz-category","marketing"); s.setAttribute("type","litespeed/javascript"); s.setAttribute("type","text/javascript"); return s.getAttribute("type"); })()`),
  'javascript/blocked');
// type="" is a CLASSIC executable script, not a stale placeholder: a marketing
// tracker flipped from a placeholder to type="" must be blocked (CodeRabbit #160).
eq('marketing placeholder then type="" is blocked',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("data-faz-category","marketing"); s.setAttribute("type","litespeed/javascript"); s.setAttribute("type",""); return s.getAttribute("type"); })()`),
  'javascript/blocked');
// a non-tracker reassigned to type="" stays "" (not resurrected as a placeholder).
eq('non-tracker type="" stays empty',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("type","module"); s.setAttribute("type",""); return s.getAttribute("type"); })()`),
  '');
// src getter still returns the resolved absolute URL (native semantics).
eq('src getter returns the resolved absolute URL on a non-blocked module',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("type","module"); s.setAttribute("src","sub/app.js"); return s.src; })()`),
  'http://localhost/sub/app.js');

// ---------------------------------------------------------------------
// MutationObserver safety net — the REAL LiteSpeed path.
//
// The per-element type/src gate is installed INSIDE document.createElement, so
// it only covers scripts JS created. A LiteSpeed-deferred tracker is
// server-rendered: it arrives in the cached HTML and is built by the parser, so
// it carries no per-element gate. When the optimiser later flips its type in
// place, the observer's attribute branch (attributeFilter:["type"]) is the ONLY
// thing that can re-block it — hence this coverage.
//
// MutationObserver records are delivered ASYNCHRONOUSLY (microtask), so every
// mutation below is followed by a flush. Asserting synchronously would read the
// pre-observer state (type still runnable, node still in the DOM) and look
// exactly like a bypass — a false negative, not a real one.
// ---------------------------------------------------------------------
const flush = () => new Promise((r) => setTimeout(r, 0));

{
  const w = loadFrontend();
  w.document.body.innerHTML =
    '<script type="litespeed/javascript" data-faz-category="marketing" src="https://connect.facebook.net/en_US/fbevents.js"><\/script>';
  await flush();
  const s = w.document.querySelector('script[src*="fbevents"]');
  eq('server-rendered litespeed placeholder is left to the optimiser',
    s.getAttribute('type'), 'litespeed/javascript');

  // The optimiser wakes it up in place (native setAttribute — no gate here).
  s.setAttribute('type', 'text/javascript');
  await flush();
  eq('optimiser waking a server-rendered tracker → observer re-blocks it',
    s.getAttribute('type'), 'javascript/blocked');
  eq('re-blocked woken tracker is detached from the document',
    s.parentNode, null);
}

{
  // A genuine deferred script (no blocked category, not a known provider) must
  // survive the wake-up untouched — the observer must not over-block.
  const w = loadFrontend();
  w.document.body.innerHTML =
    '<script type="litespeed/javascript" src="https://example.com/theme-bundle.js"><\/script>';
  await flush();
  const s = w.document.querySelector('script[src*="theme-bundle"]');
  s.setAttribute('type', 'text/javascript');
  await flush();
  eq('non-tracker woken from a placeholder stays runnable',
    s.getAttribute('type'), 'text/javascript');
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
