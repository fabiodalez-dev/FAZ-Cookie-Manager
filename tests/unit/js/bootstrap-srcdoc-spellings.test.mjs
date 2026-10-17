/**
 * JS unit test (jsdom) — the bootstrap reads every spelling of a host inside an
 * iframe's srcdoc that the WHATWG parser reads, not only a literal `//`.
 *
 * For a special scheme a backslash IS a slash to the URL parser, and any run
 * of slashes after `https:` is ignored: `https:\\tracker.test/px`,
 * `\\tracker.test/px`, `/\tracker.test/px` and `\/tracker.test/px` all fetch
 * from tracker.test, and `https:tracker.test/px` does on an http: page. The
 * server (process_iframe_srcdoc_tag) and the runtime (_fazMarkupUrls) both
 * read those spellings; the bootstrap's urlsIn() matched `(?:https?:)?//`
 * only, found nothing, and `[].every(local)` is true — so a srcdoc naming a
 * tracker that way was judged same-origin: released on the safety release
 * (where the bootstrap is the only client-side defence) and left unparked by
 * the DOM backstop during the download window.
 *
 * Harness as in bootstrap-unwatched-routes: a fresh document per case, the
 * page at http://site.test/, DOMContentLoaded dispatched by the test for the
 * safety release.
 *
 * Run: node tests/unit/js/bootstrap-srcdoc-spellings.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const BOOTSTRAP_PATH = resolve(HERE, '../../../frontend/js/bootstrap.js');
const bootstrapCode = readFileSync(BOOTSTRAP_PATH, 'utf8');

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

const SITE = 'http://site.test/';

function boot() {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><div id="w"></div></body></html>', { runScripts: 'outside-only', url: SITE });
  const { window } = dom;
  Object.defineProperty(window.document, 'readyState', { value: 'loading', configurable: true });
  window._fazConfig = { _block: true, _srcdocKey: 'test-response-proof' };
  const nativeGetAttribute = window.Element.prototype.getAttribute;
  window.eval(bootstrapCode);
  return { window, document: window.document, nativeGetAttribute };
}
async function release(window) {
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 0));
}

// Each spelling resolves to https://tracker.test/px (or http:// for the
// scheme-less ones) against http://site.test/ — checked with the URL parser
// itself, so the expectation is the engine's, not this file's.
const SPELLINGS = [
  ['backslashes after the scheme', '<img src=https:\\\\tracker.test/px>'],
  ['a backslash pair with no scheme', '<img src=\\\\tracker.test/px>'],
  ['slash-backslash with no scheme', '<img src=/\\tracker.test/px>'],
  ['backslash-slash with no scheme', '<img src=\\/tracker.test/px>'],
  ['one slash after the scheme', '<img src=https:/tracker.test/px>'],
  ['no slash after the scheme', '<img src=https:tracker.test/px>'],
  ['a tab inside the slashes', '<img src="https:/\t/tracker.test/px">'],
];
for (const [, markup] of SPELLINGS) {
  const raw = /src=(?:"([^"]*)"|(\S+?)>)/.exec(markup);
  const url = raw[1] !== undefined ? raw[1] : raw[2];
  const resolved = new URL(url.replace(/\t/g, ''), SITE);
  eq('premise: ' + JSON.stringify(url) + ' names tracker.test to the URL parser', resolved.host, 'tracker.test');
}

console.log('\nsafety release: the setter route');
for (const [label, markup] of SPELLINGS) {
  const { window, document, nativeGetAttribute } = boot();
  const frame = document.createElement('iframe');
  frame.setAttribute('srcdoc', markup);
  document.getElementById('w').appendChild(frame);
  await release(window);
  eq('srcdoc with ' + label + ' is parked on the safety release', nativeGetAttribute.call(frame, 'srcdoc'), null);
  eq('with the markup kept where the runtime restores from', nativeGetAttribute.call(frame, 'data-faz-srcdoc'), markup);
}
{
  const { window, document, nativeGetAttribute } = boot();
  const frame = document.createElement('iframe');
  frame.srcdoc = '<img src=https:\\\\tracker.test/px>';
  document.getElementById('w').appendChild(frame);
  await release(window);
  eq('the srcdoc PROPERTY with a backslash spelling is parked too', nativeGetAttribute.call(frame, 'srcdoc'), null);
}

console.log('\nsafety release: what the site itself names is released');
for (const [label, markup] of [
  ['a plain https URL (control)', '<img src="https://tracker.test/px">'],
]) {
  const { window, document, nativeGetAttribute } = boot();
  const frame = document.createElement('iframe');
  frame.setAttribute('srcdoc', markup);
  await release(window);
  eq('control: ' + label + ' is parked', nativeGetAttribute.call(frame, 'srcdoc'), null);
}
for (const [label, markup] of [
  ['a relative path', '<p>hi</p><img src="/local.png">'],
  ['the site\'s own host spelled with backslashes', '<img src=\\\\site.test/own.png>'],
  ['the site\'s own host with one slash after the scheme', '<img src=http:/site.test/own.png>'],
  ['a backslash in a path, not an authority', '<img src="/img/a\\b.png">'],
  ['no URL at all', '<p>hello</p>'],
]) {
  const { window, document, nativeGetAttribute } = boot();
  const frame = document.createElement('iframe');
  frame.setAttribute('srcdoc', markup);
  await release(window);
  eq('a srcdoc naming ' + label + ' is released', nativeGetAttribute.call(frame, 'srcdoc'), markup);
  eq('and not parked', nativeGetAttribute.call(frame, 'data-faz-srcdoc'), null);
}

console.log('\ndownload window: the DOM backstop parks a markup-sunk frame');
for (const [label, markup] of SPELLINGS) {
  const { document, nativeGetAttribute } = boot();
  const escaped = markup.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
  document.getElementById('w').innerHTML = '<iframe srcdoc="' + escaped + '"></iframe>';
  await new Promise((r) => setTimeout(r, 0));
  const frame = document.querySelector('iframe');
  eq('an innerHTML frame whose srcdoc has ' + label + ' is parked by the backstop', nativeGetAttribute.call(frame, 'srcdoc'), null);
  eq('with the markup kept', nativeGetAttribute.call(frame, 'data-faz-srcdoc'), markup);
}
{
  const { document, nativeGetAttribute } = boot();
  document.getElementById('w').innerHTML = '<iframe srcdoc="&lt;img src=/local.png&gt;"></iframe>';
  await new Promise((r) => setTimeout(r, 0));
  const frame = document.querySelector('iframe');
  eq('an innerHTML frame naming only the site is left alone', nativeGetAttribute.call(frame, 'srcdoc'), '<img src=/local.png>');
}

console.log('\nsafety release: a backstop park stays parked instead of looping');
// The backstop parked, then asked live.url() about the whole srcdoc (markup
// read as a relative path: "not blocked") and re-applied it; the attribute
// record of the re-application parked it again, and so on without end. The
// same with a srcset list asked as one string. On the shipped code these two
// cases hang the process; here they must finish parked.
{
  const { document, nativeGetAttribute } = boot();
  document.getElementById('w').innerHTML = '<iframe srcdoc="&lt;img src=&quot;https://tracker.test/px&quot;&gt;"></iframe>';
  await new Promise((r) => setTimeout(r, 0));
  const frame = document.querySelector('iframe');
  eq('a plain https:// pixel inside an innerHTML srcdoc is parked on the safety release', nativeGetAttribute.call(frame, 'srcdoc'), null);
  eq('under the placeholder category', nativeGetAttribute.call(frame, 'data-faz-category'), 'marketing');
  eq('and the frame is hidden like a runtime-parked one', frame.classList.contains('faz-hidden'), true);
}
{
  const { document, nativeGetAttribute } = boot();
  document.getElementById('w').innerHTML = '<img srcset="/a.png 1x, https://tracker.test/b.png 2x">';
  await new Promise((r) => setTimeout(r, 0));
  const img = document.querySelector('img');
  eq('a srcset with one remote candidate is parked on the safety release', nativeGetAttribute.call(img, 'srcset'), null);
  eq('with the list kept', nativeGetAttribute.call(img, 'data-faz-srcset'), '/a.png 1x, https://tracker.test/b.png 2x');
}

console.log('\nhandoff: a backstop park is judged by the runtime\'s markup rule, not as a URL');
// jsdom fires its own DOMContentLoaded on the microtask queue; a case that
// needs the HANDOFF side of a backstop park silences it before the bootstrap
// registers its listeners.
function bootHeld() {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><div id="w"></div></body></html>', { runScripts: 'outside-only', url: SITE });
  const { window } = dom;
  Object.defineProperty(window.document, 'readyState', { value: 'loading', configurable: true });
  window._fazConfig = { _block: true, _srcdocKey: 'test-response-proof' };
  const nativeGetAttribute = window.Element.prototype.getAttribute;
  window.document.addEventListener('DOMContentLoaded', function (event) { event.stopImmediatePropagation(); }, true);
  window.eval(bootstrapCode);
  return { window, document: window.document, nativeGetAttribute };
}
function handoff(window, markupHook) {
  const asked = { url: [], markup: [] };
  const policy = {
    script() {},
    node() {},
    url(u) { asked.url.push(String(u)); return /tracker\.test/.test(String(u)); },
    category() { return 'functional'; },
  };
  if (markupHook) policy.markup = (el, m) => { asked.markup.push(String(m)); return /tracker\.test/.test(String(m)) ? 'analytics' : ''; };
  window._fazBootstrap.start();
  window._fazBootstrap.finish(policy);
  return asked;
}
{
  const { window, document, nativeGetAttribute } = bootHeld();
  document.getElementById('w').innerHTML = '<iframe id="t" srcdoc="&lt;img src=https:\\\\tracker.test/px&gt;"></iframe>'
    + '<iframe id="own" srcdoc="&lt;img src=http://site.test/own.png&gt;"></iframe>';
  await new Promise((r) => setTimeout(r, 0));
  eq('premise: the tracker frame is parked by the backstop', nativeGetAttribute.call(document.getElementById('t'), 'srcdoc'), null);
  eq('premise: the frame naming the site is not', nativeGetAttribute.call(document.getElementById('own'), 'srcdoc'), '<img src=http://site.test/own.png>');
  const asked = handoff(window, true);
  eq('the runtime is asked about the MARKUP', asked.markup.length, 1);
  eq('and never about the markup as a URL', asked.url.some((u) => u.indexOf('<img') !== -1), false);
  eq('the tracker frame stays parked', nativeGetAttribute.call(document.getElementById('t'), 'srcdoc'), null);
  eq('under the category the runtime named', nativeGetAttribute.call(document.getElementById('t'), 'data-faz-category'), 'analytics');
  eq('with the markup kept for the restore pass', nativeGetAttribute.call(document.getElementById('t'), 'data-faz-srcdoc'), '<img src=https:\\\\tracker.test/px>');
}
{
  const { window, document, nativeGetAttribute } = bootHeld();
  document.getElementById('w').innerHTML = '<iframe srcdoc="&lt;img src=https://cdn.the-site.com/hero.jpg&gt;"></iframe>';
  await new Promise((r) => setTimeout(r, 0));
  const frame = document.querySelector('iframe');
  eq('premise: a cross-origin non-provider frame is parked by origin', nativeGetAttribute.call(frame, 'srcdoc'), null);
  handoff(window, true);
  eq('a frame the runtime does not block is handed back live', nativeGetAttribute.call(frame, 'srcdoc'), '<img src=https://cdn.the-site.com/hero.jpg>');
  eq('and unparked', nativeGetAttribute.call(frame, 'data-faz-srcdoc'), null);
  eq('and visible again', frame.classList.contains('faz-hidden'), false);
}
{
  const { window, document, nativeGetAttribute } = bootHeld();
  document.getElementById('w').innerHTML = '<iframe srcdoc="&lt;img src=https://cdn.the-site.com/hero.jpg&gt;"></iframe>';
  await new Promise((r) => setTimeout(r, 0));
  const frame = document.querySelector('iframe');
  handoff(window, false);
  eq('a runtime with no markup hook cannot judge a srcdoc: the frame stays parked (fail-closed)', nativeGetAttribute.call(frame, 'srcdoc'), null);
}
{
  const { window, document, nativeGetAttribute } = bootHeld();
  document.getElementById('w').innerHTML = '<img id="mixed" srcset="/a.png 1x, https://tracker.test/b.png 2x">'
    + '<img id="cdn" srcset="/a.png 1x, https://cdn.the-site.com/b.png 2x">';
  await new Promise((r) => setTimeout(r, 0));
  eq('premise: both lists are parked by origin', nativeGetAttribute.call(document.getElementById('mixed'), 'srcset') === null && nativeGetAttribute.call(document.getElementById('cdn'), 'srcset') === null, true);
  const asked = handoff(window, true);
  eq('the runtime is asked candidate by candidate, never about the list as one URL', asked.url.some((u) => u.indexOf(',') !== -1), false);
  eq('the list with a tracker candidate stays parked', nativeGetAttribute.call(document.getElementById('mixed'), 'srcset'), null);
  eq('the list the runtime does not block is handed back', nativeGetAttribute.call(document.getElementById('cdn'), 'srcset'), '/a.png 1x, https://cdn.the-site.com/b.png 2x');
}

console.log('\nboth files: the real runtime judges a backstop-parked srcdoc at the handoff and restores it on consent');
{
  const runtimeCode = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
  const { window, document, nativeGetAttribute } = bootHeld();
  window._fazConfig = {
    _block: '1',
    _srcdocKey: 'test-response-proof',
    _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'marketing', isNecessary: false }],
    _services: [],
    _providersToBlock: [{ re: 'tracker.test', categories: ['marketing'], service: 'tracker' }],
    _userWhitelist: [], _perServiceConsent: false, _perCookieConsent: false, i18n: {},
  };
  window.fazcookie = { _fazGetFromStore: () => undefined };
  document.getElementById('w').innerHTML = '<iframe id="t" srcdoc="&lt;img src=https:\\\\tracker.test/px&gt;"></iframe>'
    + '<iframe id="cdn" srcdoc="&lt;img src=https://cdn.the-site.com/hero.jpg&gt;"></iframe>';
  await new Promise((r) => setTimeout(r, 0));
  eq('premise: both frames are parked by origin during the window', nativeGetAttribute.call(document.getElementById('t'), 'srcdoc') === null && nativeGetAttribute.call(document.getElementById('cdn'), 'srcdoc') === null, true);
  const realAdd = document.addEventListener.bind(document);
  document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  window.eval(runtimeCode); // start() + finish() with the real policy
  document.addEventListener = realAdd;
  const t = document.getElementById('t');
  eq('the tracker frame stays parked after the real handoff', nativeGetAttribute.call(t, 'srcdoc'), null);
  eq('under the provider\'s category', nativeGetAttribute.call(t, 'data-faz-category'), 'marketing');
  eq('the non-provider frame is handed back live', nativeGetAttribute.call(document.getElementById('cdn'), 'srcdoc'), '<img src=https://cdn.the-site.com/hero.jpg>');
  window.fazcookie._fazGetFromStore = (k) => (k === 'marketing' ? 'yes' : undefined);
  window.eval('_fazUnblockServerSide()');
  eq('and the restore pass hands the tracker frame back on consent', nativeGetAttribute.call(t, 'srcdoc'), '<img src=https:\\\\tracker.test/px>');
  eq('visible again', t.classList.contains('faz-hidden'), false);
}

console.log('\nsafety release: a lite-embed facade (live srcdoc) stays visible');
// The engine ignores src while srcdoc is present, so parking a remote src on a
// frame whose srcdoc is first-party leaves it showing that document: the
// "lite embed" facade (a thumbnail and a play button) that stands in for the
// player. The server and the runtime (_fazHasLiveSrcdoc) leave such a frame
// visible; the bootstrap hid it, and the facade vanished whenever the
// bootstrap was the one that parked.
{
  const { document, nativeGetAttribute } = boot();
  document.getElementById('w').innerHTML = '<iframe src="https://tracker.test/embed" srcdoc="&lt;a href=&quot;/watch&quot;&gt;&lt;img src=&quot;/thumb.jpg&quot;&gt;&lt;/a&gt;"></iframe>';
  await new Promise((r) => setTimeout(r, 0));
  const frame = document.querySelector('iframe');
  eq('the remote src is parked', nativeGetAttribute.call(frame, 'data-faz-src'), 'https://tracker.test/embed');
  eq('the first-party srcdoc stays live', nativeGetAttribute.call(frame, 'srcdoc'), '<a href="/watch"><img src="/thumb.jpg"></a>');
  eq('and the facade stays visible', frame.classList.contains('faz-hidden'), false);
}
{
  const { document, nativeGetAttribute } = boot();
  document.getElementById('w').innerHTML = '<iframe src="https://tracker.test/embed" srcdoc="&lt;img src=&quot;https://tracker.test/px&quot;&gt;"></iframe>';
  await new Promise((r) => setTimeout(r, 0));
  const frame = document.querySelector('iframe');
  eq('premise: both are parked', nativeGetAttribute.call(frame, 'srcdoc') === null && !!nativeGetAttribute.call(frame, 'data-faz-src'), true);
  eq('a frame left with nothing to show is hidden', frame.classList.contains('faz-hidden'), true);
}

console.log(`\nbootstrap-srcdoc-spellings: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
