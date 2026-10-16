/**
 * JS unit test (jsdom) — the runtime's half of three server/runtime parity
 * rules on parked resources.
 *
 *   1. A parked `data:` src is RESTORABLE. The server parks an <iframe> whose
 *      data: payload names a provider (a sub-document loading a tracker, which
 *      nothing gates once parsed) and the restore refused every scheme but
 *      http(s): the frame stayed parked after consent, hidden, with no
 *      placeholder left to say why. The iframe and <img>-family restores now
 *      accept data: — a resource, not a script sink: the frame gets an opaque
 *      origin, an image never runs it, and the value came from the page's own
 *      markup, which could have written it live. javascript: stays refused,
 *      and <embed>, which can load a document in the page, stays strict.
 *   2. An inline `style` attribute the server neutralised (original in
 *      data-faz-style) is handed back on consent by a restore branch that did
 *      not exist, under the same rule as a parked <style>: only when no URL in
 *      the original is still blocked. data-faz-category is kept while another
 *      parked key on the element still needs it.
 *   3. The lite-embed frame (allowed srcdoc, blocked src): the runtime parks the
 *      src, keeps the srcdoc live and leaves the frame VISIBLE — it shows the
 *      allowed facade, not the parked src — which is the server's rule too,
 *      and restore 2b hands the src back on consent. A frame whose srcdoc is
 *      parked as well is still hidden. Pinned here so the two layers cannot
 *      drift apart again unnoticed.
 *
 * Loads the REAL frontend/js/script.js with its DOMContentLoaded bootstrap
 * neutralised, and calls the internals as Annex-B globals.
 *
 * Run: node tests/unit/js/server-park-restore-parity.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');
const runtimeCode = readFileSync(SCRIPT_PATH, 'utf8');
const SITE = 'http://site.test/';

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
    _aggressiveCssUrlBlocking: false,
    i18n: {},
  };
}
function runtimeOnly({ body = '' } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head></head><body>${body}</body></html>`, {
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
function restore(window) {
  window.eval('_fazUnblockServerSide()');
}

const DATA_DOC = 'data:text/html,%3Cscript%20src=%22https://tracker.test/t.js%22%3E%3C/script%3E';
const DATA_SVG = 'data:image/svg+xml,%3Csvg%3E%3Cimage%20href=%22https://tracker.test/px.png%22/%3E%3C/svg%3E';
const TRACKER = 'https://tracker.test/embed/ID';

console.log('1. a parked data: src is restorable');

// 1a — the placeholder path: the server wraps a parked frame in a <template>.
{
  const w = runtimeOnly({
    body: `<div class="faz-placeholder" data-faz-category="marketing" data-faz-service=""><template class="faz-placeholder-content"><iframe class="faz-hidden" data-faz-category="marketing" data-faz-src="${DATA_DOC}" width="300"></iframe></template></div>`,
  });
  restore(w);
  ok('premise: before consent the placeholder stands', !!w.document.querySelector('.faz-placeholder'));
  consent(w, { marketing: 'yes' });
  restore(w);
  const f = w.document.querySelector('iframe');
  eq('on consent the placeholder is gone', w.document.querySelector('.faz-placeholder'), null);
  eq('and the data: frame has its src back', f && f.getAttribute('src'), DATA_DOC);
  eq('with the parked key removed', f && f.getAttribute('data-faz-src'), null);
  ok('and the frame visible', f && !f.classList.contains('faz-hidden'));
}
// 1b — a standalone parked frame.
{
  const w = runtimeOnly({ body: `<iframe id="f" class="faz-hidden" data-faz-category="marketing" data-faz-src="${DATA_DOC}"></iframe>` });
  const f = w.document.getElementById('f');
  restore(w);
  eq('premise: before consent the frame stays parked', f.getAttribute('src'), null);
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('a standalone data: frame is restored on consent', f.getAttribute('src'), DATA_DOC);
  ok('and shown', !f.classList.contains('faz-hidden'));
}
// 1c — an image.
{
  const w = runtimeOnly({ body: `<img id="i" data-faz-category="marketing" data-faz-src="${DATA_SVG}" alt="">` });
  const i = w.document.getElementById('i');
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('a parked data: image is restored on consent', i.getAttribute('src'), DATA_SVG);
  eq('with the parked key removed', i.getAttribute('data-faz-src'), null);
}
// 1d — what stays refused.
{
  const w = runtimeOnly({
    body: '<iframe id="j" data-faz-category="marketing" data-faz-src="javascript:alert(1)"></iframe>'
      + '<img id="k" data-faz-category="marketing" data-faz-src="java&#9;script:alert(1)">'
      + `<embed id="e" data-faz-category="marketing" data-faz-src="${DATA_DOC}">`
      + '<iframe id="v" data-faz-category="marketing" data-faz-src="vbscript:msgbox(1)"></iframe>',
  });
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('javascript: on a frame is still refused', w.document.getElementById('j').getAttribute('src'), null);
  eq('an obfuscated javascript: on an image is still refused', w.document.getElementById('k').getAttribute('src'), null);
  eq('a data: document on <embed> is still refused: it can load a document in the page', w.document.getElementById('e').getAttribute('src'), null);
  eq('any other scheme is still refused', w.document.getElementById('v').getAttribute('src'), null);
}

console.log('\n2. a neutralised inline style attribute is handed back');

const ORIG = 'background-image:url(https://tracker.test/p.png);min-height:300px';
// 2a — the common shape: the live attribute carries the neutralised copy.
{
  const w = runtimeOnly({
    body: `<div id="h" data-faz-category="marketing" data-faz-style="${ORIG}" style="background-image:url(&quot;data:application/octet-stream,&quot;);min-height:300px">x</div>`,
  });
  const el = w.document.getElementById('h');
  restore(w);
  eq('before consent the live style keeps the inert url()', el.getAttribute('style'), 'background-image:url("data:application/octet-stream,");min-height:300px');
  eq('and the original stays parked', el.getAttribute('data-faz-style'), ORIG);
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('on consent the original declarations are live again', el.getAttribute('style'), ORIG);
  eq('the parked copy is gone', el.getAttribute('data-faz-style'), null);
  eq('and so is the category label, nothing else being parked on the element', el.getAttribute('data-faz-category'), null);
}
// 2b — parked WHOLE (a form the in-place rewrite could not reach): no live style at all.
{
  const w = runtimeOnly({
    body: '<div id="h" data-faz-category="marketing" data-faz-style="background:u\\72l(https://tracker.test/a.png)">x</div>',
  });
  const el = w.document.getElementById('h');
  restore(w);
  eq('before consent a whole-parked attribute stays parked', el.getAttribute('style'), null);
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('on consent it is handed back', el.getAttribute('style'), 'background:u\\72l(https://tracker.test/a.png)');
}
// 2c — two providers in one value: both categories have to be allowed.
{
  const mixed = 'background:url(https://tracker.test/a.png),url(https://tile.openstreetmap.org/1/1/1.png)';
  const w = runtimeOnly({ body: `<div id="h" data-faz-category="marketing" data-faz-style="${mixed}" style="">x</div>` });
  const el = w.document.getElementById('h');
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('one accepted category does not release a value that also names a denied provider', el.getAttribute('data-faz-style'), mixed);
  consent(w, { marketing: 'yes', functional: 'yes' });
  restore(w);
  eq('both accepted: restored', el.getAttribute('style'), mixed);
}
// 2d — another parked key on the same element keeps the category label.
{
  const w = runtimeOnly({
    body: `<img id="m" data-faz-category="marketing" data-faz-src="https://tile.openstreetmap.org/x.png" data-faz-style="background:url(https://tracker.test/a.png)">`,
  });
  const el = w.document.getElementById('m');
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('the style is restored', el.getAttribute('style'), 'background:url(https://tracker.test/a.png)');
  eq('the src, whose provider is still denied, stays parked', el.getAttribute('data-faz-src'), 'https://tile.openstreetmap.org/x.png');
  eq('and keeps the category label its own restore selects on', el.getAttribute('data-faz-category'), 'marketing');
}

console.log('\n3. the lite-embed frame: one rule in both layers');

// 3a — a frame the parser built, met by the startup sweep.
{
  const w = runtimeOnly({ body: `<iframe id="f" srcdoc="<button class=play>Play</button>" src="${TRACKER}"></iframe>` });
  const f = w.document.getElementById('f');
  eq('the startup sweep parks the blocked src', f.getAttribute('data-faz-src'), TRACKER);
  eq('with no live src for the facade\'s Play button to activate', f.getAttribute('src'), null);
  eq('the allowed srcdoc stays live', f.getAttribute('srcdoc'), '<button class=play>Play</button>');
  ok('and the frame stays visible: it shows the allowed facade', !f.classList.contains('faz-hidden'));
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('on consent the src is handed back', f.getAttribute('src'), TRACKER);
  ok('the frame is visible again', !f.classList.contains('faz-hidden'));
  eq('and the srcdoc is still there', f.getAttribute('srcdoc'), '<button class=play>Play</button>');
}
// 3b — the shape the server now emits for the same frame is left alone by the
// sweep and restored by the same branch: the two layers converge.
{
  const w = runtimeOnly({ body: `<iframe id="f" data-faz-category="marketing" srcdoc="<button class=play>Play</button>" data-faz-src="${TRACKER}"></iframe>` });
  const f = w.document.getElementById('f');
  eq('a server-parked lite frame is not re-judged', f.getAttribute('data-faz-src'), TRACKER);
  eq('its srcdoc is untouched', f.getAttribute('srcdoc'), '<button class=play>Play</button>');
  ok('and the sweep does not hide it', !f.classList.contains('faz-hidden'));
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('and restore 2b hands its src back', f.getAttribute('src'), TRACKER);
  ok('visible', !f.classList.contains('faz-hidden'));
}

// 3c — the same frame built by a script, through either setter: src is parked
// and the frame keeps showing its facade. Without a srcdoc it is hidden as
// before, and so is a frame whose srcdoc is parked too.
{
  const w = runtimeOnly();
  const d = w.document;
  const viaProp = d.createElement('iframe');
  viaProp.setAttribute('srcdoc', '<button class=play>Play</button>');
  viaProp.src = TRACKER;
  eq('property setter: src is parked', viaProp.getAttribute('data-faz-src'), TRACKER);
  ok('property setter: a frame with a live srcdoc stays visible', !viaProp.classList.contains('faz-hidden'));
  const viaAttr = d.createElement('iframe');
  viaAttr.setAttribute('srcdoc', '<button class=play>Play</button>');
  viaAttr.setAttribute('src', TRACKER);
  eq('setAttribute: src is parked', viaAttr.getAttribute('data-faz-src'), TRACKER);
  ok('setAttribute: a frame with a live srcdoc stays visible', !viaAttr.classList.contains('faz-hidden'));
  const bare = d.createElement('iframe');
  bare.src = TRACKER;
  ok('a frame with no srcdoc is still hidden when its src is parked', bare.classList.contains('faz-hidden'));
}
{
  const w = runtimeOnly({ body: `<iframe id="f" srcdoc="<img src=https://tracker.test/p.png>" src="${TRACKER}"></iframe>` });
  const f = w.document.getElementById('f');
  ok('a frame whose srcdoc is parked as well is hidden', f.classList.contains('faz-hidden') && f.getAttribute('data-faz-srcdoc') && !f.hasAttribute('srcdoc'));
}

console.log('\n4. the server\'s media shapes restore through the existing branches');
{
  const w = runtimeOnly({
    body: `<video id="v" data-faz-category="marketing" data-faz-src="https://tracker.test/v.mp4" data-faz-poster="https://tracker.test/p.jpg" controls></video>`
      + `<object id="o" data-faz-category="marketing" data-faz-data="${TRACKER}"></object>`
      + `<picture><source id="s" data-faz-srcset="https://tracker.test/px.png 1x" data-faz-srcset-category="marketing"></picture>`,
  });
  consent(w, { marketing: 'yes' });
  restore(w);
  eq('video src', w.document.getElementById('v').getAttribute('src'), 'https://tracker.test/v.mp4');
  eq('video poster', w.document.getElementById('v').getAttribute('poster'), 'https://tracker.test/p.jpg');
  eq('object data', w.document.getElementById('o').getAttribute('data'), TRACKER);
  eq('source srcset', w.document.getElementById('s').getAttribute('srcset'), 'https://tracker.test/px.png 1x');
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
