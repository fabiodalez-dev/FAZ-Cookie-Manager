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
    _srcdocKey: 'test-response-proof',
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

console.log('\nbootstrap: the SVG script, the prefixed xlink spelling, and an encoded srcdoc');

// 5 — an SVG <script href> inside a held subtree. The safety handler for
// scripts read src alone and returned on "no URL" without parking, while
// resources() had admitted the element for its href: the subtree landed on
// the safety release with the script's href live, and the script ran.
{
  const { window, document, nativeGetAttribute } = boot({
    body: '<template id="t"><img src="/local.png"><svg><script href="https://tracker.test/x.js"></script></svg></template>',
  });
  const tpl = document.getElementById('t');
  document.body.appendChild(document.importNode(tpl.content, true));
  eq('premise: the subtree is held', document.querySelector('svg script'), null);
  await release(window);
  const script = document.querySelector('svg script');
  ok('the subtree lands on the safety release', !!script);
  eq('the SVG script href is parked, not live', nativeGetAttribute.call(script, 'href'), null);
  eq('under the parked name the runtime restores from', nativeGetAttribute.call(script, 'data-faz-href'), 'https://tracker.test/x.js');
  eq('and the script is stamped inert', nativeGetAttribute.call(script, 'type'), 'javascript/blocked');
  eq('the same-origin image next to it is released', nativeGetAttribute.call(document.querySelector('img'), 'src'), '/local.png');
}

// 6 — the xlink href under a prefix nobody wrapped. The engine resolves the
// attribute by namespace and local name; the gate compared the qualified name
// and let `p:href` through, and the sweep's getAttribute('xlink:href')
// answered null for it.
{
  const { window, document, nativeGetAttribute } = boot();
  const image = document.createElementNS(SVG_NS, 'image');
  image.setAttributeNS(XLINK_NS, 'p:href', 'https://tracker.test/p.png');
  eq('a prefixed xlink href is staged, not applied',
    window.Element.prototype.getAttributeNS.call(image, XLINK_NS, 'href'), null);
  await release(window);
  eq('and parked on the safety release', nativeGetAttribute.call(image, 'data-faz-href'), 'https://tracker.test/p.png');
  eq('with no live attribute in the namespace', window.Element.prototype.getAttributeNS.call(image, XLINK_NS, 'href'), null);
}
{
  // A parsed one with the prefix, caught by the backstop and parked through
  // the namespace-aware accessors.
  const { window, document, nativeGetAttribute } = boot();
  const svg = document.createElementNS(SVG_NS, 'svg');
  const image = document.createElementNS(SVG_NS, 'image');
  window.Element.prototype.setAttributeNS.call(image, XLINK_NS, 'q:href', 'https://tracker.test/q.png');
  svg.appendChild(image);
  document.body.appendChild(svg);
  await release(window);
  eq('a prefixed xlink href carried by a subtree is parked', nativeGetAttribute.call(image, 'data-faz-href'), 'https://tracker.test/q.png');
  eq('and removed from the namespace', window.Element.prototype.getAttributeNS.call(image, XLINK_NS, 'href'), null);
}

// 7 — a srcdoc that spells its `//` through an entity or a JSON escape: the
// parser decodes the entity before the sub-document sees it, and the literal
// search for `//` saw neither.
for (const [label, markup] of [
  ['numeric entities', '<img src="https:&#47;&#47;tracker.test/e.png">'],
  ['hex entities', '<img src="https:&#x2F;&#x2F;tracker.test/h.png">'],
  ['named entities', '<img src="https&colon;&sol;&sol;tracker.test/n.png">'],
  ['a JSON escape', '<img src="https:\\/\\/tracker.test/j.png">'],
]) {
  const { window, document, nativeGetAttribute } = boot();
  const frame = document.createElement('iframe');
  frame.setAttribute('srcdoc', markup);
  await release(window);
  eq('srcdoc with ' + label + ' is parked on the safety release', nativeGetAttribute.call(frame, 'srcdoc'), null);
  eq('with the markup kept where the runtime restores from', nativeGetAttribute.call(frame, 'data-faz-srcdoc'), markup);
}
{
  const { window, document, nativeGetAttribute } = boot();
  const frame = document.createElement('iframe');
  frame.setAttribute('srcdoc', '<p>hi</p><img src="/local.png">');
  await release(window);
  eq('a srcdoc naming only the site itself is released', nativeGetAttribute.call(frame, 'srcdoc'), '<p>hi</p><img src="/local.png">');
}

console.log('\nruntime: the srcdoc and background IDL routes');

const DOC = '<p>hi</p><img src="https://tracker.test/in-doc.png">';

// 5 — iframe.srcdoc = markup, assigned before the frame is appended.
{
  const w = runtimeOnly();
  const f = w.document.createElement('iframe');
  f.srcdoc = DOC;
  eq('a srcdoc naming a blocked provider is not applied', f.getAttribute('srcdoc'), null);
  eq('it is parked whole', f.getAttribute('data-faz-srcdoc'), DOC);
  eq('under the provider category', f.getAttribute('data-faz-category'), 'marketing');
  ok('and the frame is hidden like a parked-src frame', f.classList.contains('faz-hidden'));
  w.document.body.appendChild(f);
  eq('appending it afterwards changes nothing', f.getAttribute('srcdoc'), null);
}
// 5b — the other order: appended first, assigned after.
{
  const w = runtimeOnly();
  const g = w.document.createElement('iframe');
  w.document.body.appendChild(g);
  g.srcdoc = DOC;
  eq('assigned after append: still not applied', g.getAttribute('srcdoc'), null);
  eq('and parked', g.getAttribute('data-faz-srcdoc'), DOC);
}
// 5c — a server-rendered frame found by id once the runtime is up.
{
  const w = runtimeOnly({ body: '<iframe id="server"></iframe>' });
  const el = w.document.getElementById('server');
  el.srcdoc = DOC;
  eq('a server-rendered frame assigned by id: not applied', el.getAttribute('srcdoc'), null);
  eq('and parked', el.getAttribute('data-faz-srcdoc'), DOC);
}
// 5d — the other error direction.
{
  const w = runtimeOnly();
  const f = w.document.createElement('iframe');
  f.srcdoc = '<p>first-party</p><img src="/local.png">';
  eq('a srcdoc naming nothing blocked is applied', f.getAttribute('srcdoc'), '<p>first-party</p><img src="/local.png">');
  eq('and not parked', f.getAttribute('data-faz-srcdoc'), null);
}

// 6 — document.body.background through the property.
{
  const w = runtimeOnly();
  w.document.body.background = 'https://tile.openstreetmap.org/1/1/1.png';
  eq('body.background to a blocked provider is not applied', w.document.body.getAttribute('background'), null);
  eq('it is parked', w.document.body.getAttribute('data-faz-background'), 'https://tile.openstreetmap.org/1/1/1.png');
  eq('under the provider category', w.document.body.getAttribute('data-faz-category'), 'functional');
  w.document.body.background = '/paper.png';
  eq('a first-party one is applied', w.document.body.getAttribute('background'), '/paper.png');
}

console.log('\nhandoff: a srcdoc held by the bootstrap meets the runtime gate');

// 7 — the bootstrap replays `el.srcdoc = value` on the live setter at handoff.
{
  const { window, document, nativeGetAttribute } = boot();
  const f = document.createElement('iframe');
  f.srcdoc = DOC;
  document.body.appendChild(f);
  const g = document.createElement('iframe');
  document.body.appendChild(g);
  g.srcdoc = DOC;
  eq('premise: both are held during the window', nativeGetAttribute.call(f, 'srcdoc') || nativeGetAttribute.call(g, 'srcdoc'), null);
  loadRuntime(window);
  eq('after the handoff the first is not applied', nativeGetAttribute.call(f, 'srcdoc'), null);
  eq('but parked', nativeGetAttribute.call(f, 'data-faz-srcdoc'), DOC);
  eq('the second is not applied either', nativeGetAttribute.call(g, 'srcdoc'), null);
  eq('and parked', nativeGetAttribute.call(g, 'data-faz-srcdoc'), DOC);
  eq('under the category the runtime names', nativeGetAttribute.call(g, 'data-faz-category'), 'marketing');
}

console.log('\nruntime: parked is restorable');

// 8 — feImage: swept at startup, restored on consent.
{
  const w = runtimeOnly({ body: '<svg><filter><feImage href="https://tracker.test/fe.png"/></filter></svg>' });
  const fe = w.document.querySelector('feImage');
  eq('a parsed feImage is parked by the startup sweep', fe.getAttribute('data-faz-href'), 'https://tracker.test/fe.png');
  eq('with no live href', fe.getAttribute('href'), null);
  consent(w, { marketing: 'yes' });
  w.eval('_fazUnblockServerSide()');
  eq('and restored as href on consent', fe.getAttribute('href'), 'https://tracker.test/fe.png');
  eq('with the parked name gone', fe.getAttribute('data-faz-href'), null);
}
// 8b — both spellings on one <image>: parked once, restored as href.
{
  const w = runtimeOnly({ body: '<svg><image href="https://tracker.test/a.png" xlink:href="https://tracker.test/a.png"/></svg>' });
  const img = w.document.querySelector('image');
  eq('href is removed', img.getAttribute('href'), null);
  eq('xlink:href is removed', img.getAttribute('xlink:href'), null);
  eq('one parked attribute', img.getAttribute('data-faz-href'), 'https://tracker.test/a.png');
  consent(w, { marketing: 'yes' });
  w.eval('_fazUnblockServerSide()');
  eq('restored as the SVG2 href', img.getAttribute('href'), 'https://tracker.test/a.png');
}
// 8c — a parked srcdoc frame comes back visible.
{
  const w = runtimeOnly({ body: '<iframe id="p" class="faz-hidden" data-faz-srcdoc-key="test-response-proof" data-faz-category="marketing" data-faz-srcdoc="&lt;p&gt;x&lt;/p&gt;&lt;img src=&quot;https://tracker.test/d.png&quot;&gt;"></iframe>' });
  const el = w.document.getElementById('p');
  consent(w, { marketing: 'yes' });
  w.eval('_fazUnblockServerSide()');
  eq('srcdoc is restored on consent', el.getAttribute('srcdoc'), '<p>x</p><img src="https://tracker.test/d.png">');
  eq('the parked name is gone', el.getAttribute('data-faz-srcdoc'), null);
  ok('and the frame is visible again', !el.classList.contains('faz-hidden'));
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
