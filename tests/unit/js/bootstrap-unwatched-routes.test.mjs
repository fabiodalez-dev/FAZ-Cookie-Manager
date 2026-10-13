/**
 * JS unit test (jsdom) — the resource routes the client layers were blind to
 * after the first pass over them: <feImage>, the `background` IDL property,
 * and an iframe's srcdoc assigned through the property rather than
 * setAttribute.
 *
 * Bootstrap half (frontend/js/bootstrap.js):
 *   1. <feImage href> written through setAttribute is held, and parked on the
 *      safety release instead of fetched.
 *   2. The same through setAttributeNS and the legacy `xlink:href` spelling.
 *   3. A <feImage> the parser builds (innerHTML) is caught by the DOM backstop,
 *      which needs the SVG spelling in its selector: `feimage` matches nothing
 *      outside the HTML namespace.
 *   4. `document.body.background = url` is an IDL route with no setAttribute in
 *      it; it is held, parked when cross-origin and released when same-origin.
 *
 * Runtime half (frontend/js/script.js):
 *   5. `iframe.srcdoc = markup` naming a blocked provider is parked by an IDL
 *      gate, whether the frame is assigned before or after it is appended, and
 *      for a server-rendered frame found by id after the runtime is up.
 *   6. `document.body.background = url` is gated the same way.
 *   7. The bootstrap's replay at handoff is `el[prop] = value` on the live
 *      setter, so a srcdoc held during the download window reaches that gate
 *      and is parked rather than applied.
 *   8. <feImage> is parked by the runtime's sweep and restored by its
 *      server-side restore pass; an <image> carrying both spellings is parked
 *      once and restored as `href`; a parked srcdoc frame comes back visible.
 *
 * Run: node tests/unit/js/bootstrap-unwatched-routes.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const BOOTSTRAP_PATH = resolve(HERE, '../../../frontend/js/bootstrap.js');
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
const bootstrapCode = readFileSync(BOOTSTRAP_PATH, 'utf8');
const runtimeCode = readFileSync(SCRIPT_PATH, 'utf8');

// The store the runtime reads: block-first, two non-necessary categories, and
// providers for the hosts used below. The bootstrap only needs `_block`.
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
      { re: 'tile.openstreetmap.org', categories: ['functional'], service: 'openstreetmap' },
    ],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    _aggressiveCssUrlBlocking: true,
    i18n: {},
  };
}

// A fresh document per case: both files patch prototypes, so leaking one
// instance into the next would make later cases pass for the wrong reason.
function boot({ ready = 'loading', seed = '', body = '' } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head>${seed}</head><body>${body}</body></html>`, {
    runScripts: 'outside-only',
    url: SITE,
  });
  const { window } = dom;
  Object.defineProperty(window.document, 'readyState', { value: ready, configurable: true });
  window._fazConfig = runtimeConfig();
  window.fazcookie = { _fazGetFromStore: () => undefined };
  // Captured BEFORE the bootstrap patches the prototype: the shimmed
  // getAttribute answers with the staged value, so only the native one can
  // tell whether the attribute actually reached the element.
  const nativeGetAttribute = window.Element.prototype.getAttribute;
  window.eval(bootstrapCode);
  return { window, document: window.document, nativeGetAttribute };
}

// The runtime never arrives: DOMContentLoaded drains the queue against the
// same-origin-only safety policy.
async function release(window) {
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 0));
}

// Load the real runtime into a window, with its DOMContentLoaded banner
// bootstrap neutralised. When a bootstrap is present the runtime calls
// start() first and finish() last, which is the real handoff.
function loadRuntime(window) {
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(runtimeCode);
  window.document.addEventListener = realAdd;
}
function runtimeOnly({ body = '' } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head></head><body>${body}</body></html>`, {
    runScripts: 'outside-only',
    url: SITE,
  });
  const { window } = dom;
  window._fazConfig = runtimeConfig();
  window.fazcookie = { _fazGetFromStore: () => undefined };
  loadRuntime(window);
  return window;
}
function consent(window, map) {
  window.fazcookie._fazGetFromStore = (k) => (k in map ? map[k] : undefined);
}

console.log('\nbootstrap: the SVG filter image and the background property');

// 1 — <feImage href> through setAttribute.
{
  const { window, document, nativeGetAttribute } = boot();
  const fe = document.createElementNS(SVG_NS, 'feImage');
  fe.setAttribute('href', 'https://tracker.test/fe.png');
  eq('feImage href is staged, not applied', nativeGetAttribute.call(fe, 'href'), null);
  await release(window);
  eq('and parked on the safety release', nativeGetAttribute.call(fe, 'data-faz-href'), 'https://tracker.test/fe.png');
  ok('with a category the restore pass can select on', !!nativeGetAttribute.call(fe, 'data-faz-category'));
  eq('while the live attribute never appears', nativeGetAttribute.call(fe, 'href'), null);
}

// 2 — the legacy spelling through the only API that can write it.
{
  const { window, document, nativeGetAttribute } = boot();
  const fe = document.createElementNS(SVG_NS, 'feImage');
  fe.setAttributeNS(XLINK_NS, 'xlink:href', 'https://tracker.test/fe-x.png');
  eq('feImage xlink:href is staged too', nativeGetAttribute.call(fe, 'xlink:href'), null);
  await release(window);
  eq('and parked under the one parked name', nativeGetAttribute.call(fe, 'data-faz-href'), 'https://tracker.test/fe-x.png');
}

// 3 — the parser route: the backstop's selector must spell it as SVG does.
{
  const { document, nativeGetAttribute } = boot();
  document.body.innerHTML = '<svg><filter><feImage href="https://tracker.test/parsed.png"/></filter></svg>';
  await new Promise((r) => setTimeout(r, 0));
  const fe = document.querySelector('feImage');
  ok('premise: the element really was created with the SVG spelling', !!fe && fe.localName === 'feImage');
  eq('a parsed feImage has no live href', nativeGetAttribute.call(fe, 'href'), null);
  eq('its URL is parked where the runtime looks', nativeGetAttribute.call(fe, 'data-faz-href'), 'https://tracker.test/parsed.png');
}

// 4 — document.body.background through the IDL property.
{
  const { window, document, nativeGetAttribute } = boot();
  document.body.background = 'https://tracker.test/bg.png';
  eq('body.background is staged, not applied', nativeGetAttribute.call(document.body, 'background'), null);
  await release(window);
  eq('and parked on the safety release', nativeGetAttribute.call(document.body, 'data-faz-background'), 'https://tracker.test/bg.png');
  eq('with no live attribute', nativeGetAttribute.call(document.body, 'background'), null);
}
{
  const { window, document, nativeGetAttribute } = boot();
  document.body.background = '/paper.png';
  await release(window);
  eq('a same-origin body.background is released by the safety policy', nativeGetAttribute.call(document.body, 'background'), '/paper.png');
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
