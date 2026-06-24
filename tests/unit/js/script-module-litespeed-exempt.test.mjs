/**
 * JS unit test (jsdom) — guards the #158 follow-up: FAZ's script blocker must
 * NOT intercept native WP ES modules / importmaps (WP Interactivity API) or
 * optimiser-deferred scripts (LiteSpeed / WP Rocket "Delay JS"), while STILL
 * blocking a real tracker. Covers:
 *   - _fazIsExemptScriptType / _fazIsExemptScript (the exemption predicate)
 *   - _fazShouldChangeType short-circuits to false for exempt scripts
 *   - the document.createElement override: module/importmap/litespeed types are
 *     never rewritten to "javascript/blocked"; a marketing-tagged tracker still
 *     is; and the src getter returns the resolved absolute URL (native
 *     semantics) instead of the raw attribute.
 *
 * Loads the REAL frontend/js/script.js with its DOMContentLoaded bootstrap
 * neutralised. _fazStore = window._fazConfig and ref = window.fazcookie are
 * captured at eval time (script.js:7,34), so the harness seeds them first.
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

console.log('script-module / litespeed exemption (jsdom, #158 follow-up)');
const w = loadFrontend();
const ev = (expr) => w.eval(expr);

// ---------------------------------------------------------------------------
// _fazIsExemptScriptType — the type-string predicate.
// ---------------------------------------------------------------------------
console.log('\n_fazIsExemptScriptType()');
eq('module → exempt', ev('_fazIsExemptScriptType("module")'), true);
eq('importmap → exempt', ev('_fazIsExemptScriptType("importmap")'), true);
eq('application/importmap+json → exempt', ev('_fazIsExemptScriptType("application/importmap+json")'), true);
eq('litespeed/javascript → exempt', ev('_fazIsExemptScriptType("litespeed/javascript")'), true);
eq('rocketlazyloadjs → exempt', ev('_fazIsExemptScriptType("rocketlazyloadjs")'), true);
eq('text/javascript → NOT exempt', ev('_fazIsExemptScriptType("text/javascript")'), false);
eq('empty → NOT exempt', ev('_fazIsExemptScriptType("")'), false);

// ---------------------------------------------------------------------------
// _fazIsExemptScript — the node predicate (stub nodes, no createElement path).
// ---------------------------------------------------------------------------
console.log('\n_fazIsExemptScript()');
const stub = (attrs) => `_fazIsExemptScript({ getAttribute: function(k){ return (${JSON.stringify(attrs)})[k] || null; } })`;
eq('type=module node → exempt', ev(stub({ type: 'module' })), true);
eq('WP core script-modules src → exempt', ev(stub({ src: 'http://x/wp-includes/js/dist/script-modules/block-library/navigation/view.min.js' })), true);
eq('WP interactivity src → exempt', ev(stub({ src: 'http://x/wp-includes/js/dist/interactivity.min.js' })), true);
eq('ordinary tracker script → NOT exempt', ev(stub({ type: 'text/javascript', src: 'https://t.example.com/a.js' })), false);

// ---------------------------------------------------------------------------
// _fazShouldChangeType — exempt scripts never block, real tracker does.
// ---------------------------------------------------------------------------
console.log('\n_fazShouldChangeType()');
const elem = (attrs) => `{ classList:{contains:function(){return false;}}, src:"", getAttribute:function(k){ return (${JSON.stringify(attrs)})[k] || null; } }`;
eq('marketing + text/javascript → block (true)', ev(`_fazShouldChangeType(${elem({ 'data-faz-category': 'marketing', type: 'text/javascript' })})`), true);
eq('marketing + type=module → exempt (false)', ev(`_fazShouldChangeType(${elem({ 'data-faz-category': 'marketing', type: 'module' })})`), false);
eq('WP core module src, no category → exempt (false)', ev(`_fazShouldChangeType(${elem({ src: 'http://x/wp-includes/js/dist/script-modules/a.js', type: '' })})`), false);

// ---------------------------------------------------------------------------
// document.createElement override — end-to-end behaviour.
// ---------------------------------------------------------------------------
console.log('\ndocument.createElement override');
// A module script tagged into a blocked category must KEEP type="module".
eq('module type is never rewritten, even with a blocked category tag',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("type","module"); s.setAttribute("data-faz-category","marketing"); return s.getAttribute("type"); })()`),
  'module');
// importmap likewise.
eq('importmap type is never rewritten',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("type","importmap"); return s.getAttribute("type"); })()`),
  'importmap');
// LiteSpeed-deferred type likewise.
eq('litespeed/javascript type is never rewritten',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("type","litespeed/javascript"); return s.getAttribute("type"); })()`),
  'litespeed/javascript');
// A real tracker (marketing category) IS still blocked.
eq('a marketing-tagged classic tracker is still blocked',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("data-faz-category","marketing"); s.setAttribute("type","text/javascript"); return s.getAttribute("type"); })()`),
  'javascript/blocked');
// src getter returns the resolved absolute URL (native semantics).
eq('src getter returns the resolved absolute URL on an exempt script',
  ev(`(function(){ var s=document.createElement("script"); s.setAttribute("type","module"); s.setAttribute("src","sub/app.js"); return s.src; })()`),
  'http://localhost/sub/app.js');

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
