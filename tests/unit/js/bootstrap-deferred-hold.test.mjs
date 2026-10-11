/**
 * JS unit test (jsdom) — the inline bootstrap that holds resources while the
 * deferred consent runtime downloads.
 *
 * The bootstrap is the only thing standing between a visitor and an unguarded
 * page during that window, so these cases pin the behaviour that is easy to
 * regress and impossible to see from the outside: what it holds, what it lets
 * through, and above all what happens when the runtime never arrives.
 *
 * Cases:
 *   1. A cross-origin img src is held, and reads back through the getter as if
 *      it had been applied (libraries check `el.src` right after setting it).
 *   2. Sibling order survives the hold: an insertion carrying a resource and a
 *      plain one afterwards replay in the order they were called.
 *   3. insertBefore whose reference node was removed while held still lands in
 *      the same parent instead of throwing and dropping the node.
 *   4. A synchronous XHR to the site's own absolute URL proceeds; a
 *      cross-origin one fails closed.
 *   5. insertAdjacentHTML carrying <style> with url() is held — the runtime
 *      gates that surface, so the download window must not reopen it.
 *   6. SAFETY RELEASE — the runtime never arrives: same-origin fetch runs,
 *      cross-origin fetch is refused, and a held cross-origin img is parked in
 *      data-faz-src rather than fetched. Without this the whole site's
 *      first-party JavaScript stayed dead with the trackers.
 *
 * Run: node tests/unit/js/bootstrap-deferred-hold.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const BOOTSTRAP_PATH = resolve(HERE, '../../../frontend/js/bootstrap.js');

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
const code = readFileSync(BOOTSTRAP_PATH, 'utf8');

// A fresh document per case: the bootstrap patches prototypes, so leaking one
// instance into the next would make later cases pass for the wrong reason.
function boot({ ready = 'loading', seed = '' } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head>${seed}</head><body><ul id="list"></ul></body></html>`, {
    runScripts: 'outside-only',
    url: SITE,
  });
  const { window } = dom;
  Object.defineProperty(window.document, 'readyState', { value: ready, configurable: true });
  window._fazConfig = { _block: true };
  const fetched = [];
  window.fetch = function (input) {
    fetched.push(String(input && input.url ? input.url : input));
    return Promise.resolve({ ok: true });
  };
  // Captured BEFORE the bootstrap patches the prototype. Its shimmed
  // getAttribute answers with the staged value, so only the native one can
  // tell whether the attribute actually reached the element.
  const nativeGetAttribute = window.Element.prototype.getAttribute;
  // The parser builds an element and inserts it without touching any wrapper.
  // These two natives, captured before the patching, are the only way to
  // reproduce that from a test: everything else in the window is gated.
  const nativeSetAttribute = window.Element.prototype.setAttribute;
  const nativeAppendChild = window.Node.prototype.appendChild;
  window.eval(code);
  return { window, document: window.document, fetched, dom, nativeGetAttribute, nativeSetAttribute, nativeAppendChild };
}

// The handoff the real runtime performs once its own interceptors are in place.
function runtimeHandoff(window) {
  window._fazBootstrap.start();
  window._fazBootstrap.finish({
    script: function () {},
    node: function () {},
    url: function () { return false; },
  });
}

console.log('\nbootstrap: what is held while the runtime downloads');

// 1 — a cross-origin src is held but reads back as applied.
{
  const { window, document, nativeGetAttribute } = boot();
  const img = document.createElement('img');
  img.src = 'https://tracker.test/pixel.gif';
  eq('held img src reads back through the getter', img.src, 'https://tracker.test/pixel.gif');
  eq('the attribute is not on the element yet', nativeGetAttribute.call(img, 'src'), null);
  ok('hasAttribute reports the staged value', img.hasAttribute('src'));
  runtimeHandoff(window);
  // The same native probe, now that the handoff has applied the attribute: it
  // answers differently on either side of the hold, so neither reading is a
  // constant that would pass whatever the bootstrap did.
  eq('and the native attribute appears once the runtime takes over',
    nativeGetAttribute.call(img, 'src'), 'https://tracker.test/pixel.gif');
}

// 2 — sibling order survives the hold.
{
  const { window, document } = boot();
  const list = document.getElementById('list');
  // Build the subtree with innerHTML: an appendChild here would itself be
  // held, so the <li> would reach the list carrying no resource and the
  // ordering path under test would never be taken.
  const withImg = document.createElement('li');
  withImg.id = 'first';
  withImg.innerHTML = '<img src="https://tracker.test/a.gif">';
  const plain = document.createElement('li');
  plain.id = 'second';
  list.appendChild(withImg);
  list.appendChild(plain);
  runtimeHandoff(window);
  eq('the insertion carrying a resource stays first', list.children[0] && list.children[0].id, 'first');
  eq('the plain sibling queued behind it', list.children[1] && list.children[1].id, 'second');
}

// 3 — a reference node removed while held does not drop the insertion.
{
  const { window, document } = boot();
  const list = document.getElementById('list');
  const ref = document.createElement('li');
  ref.id = 'ref';
  window._fazBootstrap && null;
  list.appendChild(ref);
  const held = document.createElement('li');
  held.id = 'held';
  held.appendChild(document.createElement('img'));
  list.insertBefore(held, ref);
  ref.remove();
  runtimeHandoff(window);
  ok('the node survived its reference being removed', !!document.getElementById('held'));
  eq('and it landed in the intended parent', document.getElementById('held').parentNode.id, 'list');
}

// 4 — synchronous XHR: same-origin proceeds, cross-origin fails closed.
{
  const { window } = boot();
  // Do not replace send(): that would remove the very interceptor under test.
  // The observable difference is whether send() throws before reaching the
  // network, so assert on the exception alone.
  let sameOriginError = '';
  const same = new window.XMLHttpRequest();
  same.open('GET', SITE + 'wp-admin/admin-ajax.php', false);
  try { same.send(); } catch (e) { sameOriginError = e.name; }
  ok('an absolute same-origin sync XHR is not refused by the bootstrap', sameOriginError !== 'InvalidStateError');

  let threw = '';
  const remote = new window.XMLHttpRequest();
  remote.open('GET', 'https://tracker.test/collect', false);
  try { remote.send(); } catch (e) { threw = e.name; }
  eq('a cross-origin sync XHR fails closed', threw, 'InvalidStateError');
  runtimeHandoff(window);
}

// 5 — insertAdjacentHTML carrying <style> with url() is held.
{
  const { window, document } = boot();
  const list = document.getElementById('list');
  list.insertAdjacentHTML('beforeend', '<style>@import url(https://tracker.test/a.css);</style>');
  eq('the style element is not inserted yet', list.querySelectorAll('style').length, 0);
  list.insertAdjacentHTML('beforeend', '<style>.a{color:red}</style>');
  eq('a style without a URL is inserted immediately', list.querySelectorAll('style').length, 1);
  runtimeHandoff(window);
  eq('the held style lands at handoff', list.querySelectorAll('style').length, 2);
}

console.log('\nbootstrap: the runtime never arrives (safety release)');

// 6 — the release path. Nothing calls start()/finish(): DOMContentLoaded fires
// with the queue still full, which is what a filter list or a 404 produces.
{
  const { window, document, fetched } = boot();
  const img = document.createElement('img');
  img.src = 'https://tracker.test/pixel.gif';
  document.body.appendChild(img);

  window.fetch(SITE + 'wp-json/cart');
  window.fetch('https://tracker.test/collect');
  eq('nothing reached the network while held', fetched.length, 0);

  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 0));

  eq('same-origin request released', fetched.length, 1);
  ok('and it is the first-party one', fetched[0].indexOf('wp-json/cart') !== -1);
  ok('the cross-origin request was never sent', fetched.every((u) => u.indexOf('tracker.test') === -1));

  const released = document.querySelector('img');
  eq('the cross-origin image is parked, not loaded', released.getAttribute('src'), null);
  eq('its URL is kept where the runtime looks for it', released.getAttribute('data-faz-src'), 'https://tracker.test/pixel.gif');
}

console.log('\nbootstrap: CSS that a regex would miss and an engine would fetch');

// A fragment can complete a URL that neither half contains. Validating the
// argument instead of the resulting text let that through.
{
  // The partial URL is in the document before the bootstrap installs, so the
  // text node really is inside <style> when appendData runs — building it with
  // appendChild would be held itself and the hook under test never reached.
  const { window, document } = boot({ seed: '<style id="seed">.p{background-image:url(https://tracker.test</style>' });
  const node = document.getElementById('seed').firstChild;
  // Carries no URL of its own, yet completes one already in the node.
  node.appendData('/pixel.png)}');
  ok('a fragment that completes a cross-origin URL is held, not applied',
    node.data.indexOf('/pixel.png') === -1);
  runtimeHandoff(window);
  ok('and it is applied once the runtime takes over', node.data.indexOf('/pixel.png') !== -1);
}

// The release rule for CSS is "could this fetch anything", not "is the URL I
// managed to parse same-origin": escapes and comments hide URLs from a regex
// while the engine still resolves them.
{
  const { window, document } = boot();
  const style = document.createElement('style');
  document.head.appendChild(style);
  style.textContent = '.p{background-image:u\\72 l(https://tracker.test/a.png)}';
  eq('style text with a CSS escape is held', style.textContent, '');
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 0));
  eq('and the safety release does not apply it either', style.textContent, '');
}

// A ")" is legal inside a quoted CSS URL, so url("…/a)b") is one URL and not a
// closed url() followed by junk. Every regex that parses the URL out of the
// text stops at that paren, reads a prefix, and judges the remainder by it —
// which is why the release rule asks whether the text could fetch at all.
// Routed through insertRule, the CSSOM path that carries no element text.
{
  const { window, document } = boot();
  const style = document.createElement('style');
  document.head.appendChild(style);
  const sheet = style.sheet;
  sheet.insertRule('.p{background-image:url("https://tracker.test/a)b")}', 0);
  eq('a quoted URL containing ")" is held', sheet.cssRules.length, 0);
  runtimeHandoff(window);
  eq('and the normal handoff applies it', sheet.cssRules.length, 1);
}

{
  const { window, document } = boot();
  const style = document.createElement('style');
  document.head.appendChild(style);
  const sheet = style.sheet;
  sheet.insertRule('.p{background-image:url("https://tracker.test/a)b")}', 0);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 0));
  eq('the safety release does not apply it either', sheet.cssRules.length, 0);
}

// deleteData adds no text at all — it was handed a length — yet removing what
// split a URL makes it valid. The route was not intercepted before.
{
  const { window, document } = boot({ seed: '<style id="seed">.p{background-image:url(https:/*x*/ /tracker.test/a.png)}</style>' });
  const node = document.getElementById('seed').firstChild;
  const before = node.data;
  node.deleteData(node.data.indexOf('/*'), 7);
  eq('a deletion that would reveal a URL is held', node.data, before);
  runtimeHandoff(window);
  ok('and it applies once the runtime takes over', node.data !== before);
}

// A URL spelled across the boundary: neither the element's text nor the
// fragment contains "url(" on its own, so judging either side alone missed it.
{
  const { window, document } = boot({ seed: '<style id="seed">.p{background-image:u</style>' });
  const node = document.getElementById('seed').firstChild;
  node.appendData('rl(https://tracker.test/a.png)}');
  ok('a URL split across element and fragment is held',
    node.data.indexOf('tracker.test') === -1);
  runtimeHandoff(window);
  ok('and it applies at handoff', node.data.indexOf('tracker.test') !== -1);
}

// The same split, through a route that cannot compute a resulting string the
// way the character-data methods do: here the element's own text is the only
// place the first half of the URL exists.
{
  const { window, document } = boot({ seed: '<style id="seed">.p{background-image:u</style>' });
  const style = document.getElementById('seed');
  style.insertAdjacentText('beforeend', 'rl(https://tracker.test/x.png)}');
  ok('insertAdjacentText completing a URL from the element text is held',
    style.textContent.indexOf('tracker.test') === -1);
  runtimeHandoff(window);
  ok('and it applies at handoff', style.textContent.indexOf('tracker.test') !== -1);
}

console.log('\nbootstrap: hold and release judge the same text (six style routes)');

// The hold rule concatenated the element's existing text with the incoming
// fragment; the release rule judged the fragment alone. So a fragment that
// completes a URL the <style> already half-holds passed the release check and
// was fetched. Six routes had that shape, and no existing case could see any of
// them: every cross-boundary test above stops at runtimeHandoff, where the
// policy sets no sameOriginOnly and the release check is unconditionally true.
// These dispatch DOMContentLoaded instead, which is the only path where the
// release rule does anything.
const SPLIT_SEED = '<style id="seed">.p{background-image:u</style>';

async function release(window) {
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 0));
}

// Route 1 — appendChild of a text node into the <style>.
{
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  style.appendChild(document.createTextNode('rl(https://tracker.test/a1.png)}'));
  await release(window);
  ok('appendChild: the safety release does not complete the URL',
    style.textContent.indexOf('tracker.test') === -1);
}

// Route 2 — the ParentNode mixin, one argument.
{
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  style.append('rl(https://tracker.test/a2.png)}');
  await release(window);
  ok('append: the safety release does not complete the URL',
    style.textContent.indexOf('tracker.test') === -1);
}

// Route 3 — insertAdjacentText.
{
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  style.insertAdjacentText('beforeend', 'rl(https://tracker.test/a3.png)}');
  await release(window);
  ok('insertAdjacentText: the safety release does not complete the URL',
    style.textContent.indexOf('tracker.test') === -1);
}

// Route 4 — the character-data setter.
{
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  const node = style.firstChild;
  node.data = '.p{background-image:url(https://tracker.test/a4.png)}';
  await release(window);
  ok('the data setter: the safety release does not apply the URL',
    style.textContent.indexOf('tracker.test') === -1);
}

// Route 5 — appendData, whose release used to ask about the fragment.
{
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  style.firstChild.appendData('rl(https://tracker.test/a5.png)}');
  await release(window);
  ok('appendData: the safety release does not complete the URL',
    style.textContent.indexOf('tracker.test') === -1);
}

// Route 6 — CharacterData.replaceWith, previously released per argument.
{
  const { window, document } = boot({ seed: '<style id="seed">x</style>' });
  const style = document.getElementById('seed');
  style.firstChild.replaceWith('.p{background-image:url(https://tracker.test/a6.png)}');
  await release(window);
  ok('CharacterData.replaceWith: the safety release does not apply the URL',
    style.textContent.indexOf('tracker.test') === -1);
}

// Two gaps that do not need a failed runtime at all: they leak during the
// ordinary hold window, which makes them worse than the six above.
{
  // Judging each argument separately: neither half carries "url(" alone.
  const { window, document } = boot();
  const style = document.createElement('style');
  document.head.appendChild(style);
  style.append('.q{background-image:u', 'rl(https://tracker.test/b1.png)}');
  ok('a URL split across two arguments of one append is held',
    style.textContent.indexOf('tracker.test') === -1);
  runtimeHandoff(window);
  ok('and it applies at handoff', style.textContent.indexOf('tracker.test') !== -1);
}

{
  // prepend inserts in FRONT, so the junction that matters is
  // fragment + existing text — the opposite order from append.
  const { window, document } = boot({ seed: '<style id="seed">l(https://tracker.test/b2.png)}</style>' });
  const style = document.getElementById('seed');
  style.prepend('.p{background-image:ur');
  ok('prepend completing a URL from the front is held',
    style.textContent.indexOf('background-image') === -1);
  runtimeHandoff(window);
  ok('and it applies at handoff', style.textContent.indexOf('background-image') !== -1);
}

{
  // CharacterData.before/after was unpatched: only replaceWith had a block.
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  style.firstChild.after('rl(https://tracker.test/b3.png)}');
  ok('a sibling inserted after the text node inside <style> is held',
    style.textContent.indexOf('tracker.test') === -1);
  runtimeHandoff(window);
  ok('and it applies at handoff', style.textContent.indexOf('tracker.test') !== -1);
}

{
  // Removing the node that split a URL reveals it — the same reason deleteData
  // is intercepted. Built with splitText, which is not an intercepted route, so
  // the three text nodes exist before the method under test is called.
  const { window, document } = boot({ seed: '<style id="seed">.p{background-image:url(https:/*x*/ /tracker.test/b4.png)}</style>' });
  const style = document.getElementById('seed');
  const head = style.firstChild;
  const at = head.data.indexOf('/*x*/');
  const middle = head.splitText(at);
  middle.splitText(5);
  eq('premise: the style now holds three text nodes', style.childNodes.length, 3);
  const before = style.textContent;
  style.removeChild(middle);
  eq('removing the comment node that split the URL is held', style.textContent, before);
  runtimeHandoff(window);
  ok('and the removal applies at handoff', style.textContent !== before);
}

{
  // The other error direction. before/after/replaceWith insert SIBLINGS, so a
  // string placed after the <style> tag never becomes CSS and must not be held:
  // testing the element itself held it, which delayed ordinary page content.
  const { window, document } = boot();
  const style = document.createElement('style');
  document.head.appendChild(style);
  style.after('url(https://tracker.test/not-css.png)');
  ok('text placed AFTER a <style> tag is not CSS and is not held',
    document.head.textContent.indexOf('not-css.png') !== -1);
}

console.log('\nbootstrap: srcset and imagesrcset are candidate lists, not URLs');

// Resolving the whole list as one URL answers about the FIRST candidate only —
// commas and spaces percent-encode into the path — so a list beginning with a
// relative URL read as same-origin and the tracker candidate was released.
{
  const { window, document } = boot();
  const img = document.createElement('img');
  document.body.appendChild(img);
  img.srcset = '/local.png 1x, https://tracker.test/px.png 2x';
  await release(window);
  eq('a list with a cross-origin candidate is not applied', img.getAttribute('srcset'), null);
  eq('it is parked under the key the runtime restores lists from',
    img.getAttribute('data-faz-srcset'), '/local.png 1x, https://tracker.test/px.png 2x');
  ok('and not under the single-URL key', img.getAttribute('data-faz-src') === null);
}

{
  const { window, document } = boot();
  const img = document.createElement('img');
  document.body.appendChild(img);
  img.srcset = '/a.png 1x, /b.png 2x';
  await release(window);
  eq('an all-same-origin list is released intact', img.getAttribute('srcset'), '/a.png 1x, /b.png 2x');
}

{
  const { window, document } = boot();
  const source = document.createElement('source');
  document.body.appendChild(source);
  source.setAttribute('srcset', '/local.png 1x, https://tracker.test/px.png 2x');
  await release(window);
  eq('setAttribute("srcset") with a remote candidate is parked', source.getAttribute('srcset'), null);
  ok('under its own list key', source.getAttribute('data-faz-srcset') !== null);
}

{
  const { window, document } = boot();
  const link = document.createElement('link');
  link.setAttribute('rel', 'preload');
  document.head.appendChild(link);
  link.setAttribute('imagesrcset', '/local.png 1x, https://tracker.test/px.png 2x');
  await release(window);
  eq('imagesrcset with a remote candidate is parked', link.getAttribute('imagesrcset'), null);
  ok('under data-faz-imagesrcset', link.getAttribute('data-faz-imagesrcset') !== null);
}

console.log('\nbootstrap: what is parked has to be restorable');

// Every runtime restore selector requires BOTH the parking attribute and a
// category: img[data-faz-src][data-faz-category], link[data-faz-href]
// [data-faz-category]. Parking without one, or collapsing two attributes onto
// one key, turned a delayed resource into a dead one.
{
  const { window, document } = boot();
  const img = document.createElement('img');
  document.body.appendChild(img);
  img.src = 'https://tracker.test/one.gif';
  img.srcset = '/local.png 1x, https://tracker.test/two.gif 2x';
  await release(window);
  eq('src keeps its own key', img.getAttribute('data-faz-src'), 'https://tracker.test/one.gif');
  ok('srcset does not overwrite it', img.getAttribute('data-faz-srcset') !== null);
  ok('and the src park carries a category the restore pass can select on',
    !!img.getAttribute('data-faz-category'));
  ok('while the list carries its own, so the two cannot collide',
    !!img.getAttribute('data-faz-srcset-category'));
}

{
  const { window, document } = boot();
  const link = document.createElement('link');
  link.setAttribute('rel', 'stylesheet');
  document.head.appendChild(link);
  link.href = 'https://tracker.test/a.css';
  await release(window);
  eq('a parked link uses data-faz-href', link.getAttribute('data-faz-href'), 'https://tracker.test/a.css');
  ok('and carries a category', !!link.getAttribute('data-faz-category'));
}

// The normal handoff is where the category can be real: only the runtime has
// provider rules, so the bootstrap asks it.
{
  const { window, document } = boot();
  const img = document.createElement('img');
  document.body.appendChild(img);
  img.src = 'https://tracker.test/named.gif';
  window._fazBootstrap.start();
  window._fazBootstrap.finish({
    script: function () {},
    node: function () {},
    url: function () { return true; },
    sameOriginOnly: true,
    park: null,
    category: function () { return 'analytics'; }
  });
  eq('the runtime names the category for a resource it refuses',
    img.getAttribute('data-faz-category'), 'analytics');
}

console.log('\nbootstrap: markup that never passes through a setter');

// innerHTML, outerHTML, insertAdjacentHTML and document.write reach the parser,
// not a setter, so no wrapper in this file can see them. Before the runtime was
// deferred its own MutationObserver covered them, armed in <head>; deferring it
// armed it after the document had parsed and left the whole download window
// with no coverage at all. The backstop here asks the same question of the DOM.
{
  const { window, document } = boot();
  document.getElementById('list').innerHTML = '<img src="https://tracker.test/innerhtml.gif">';
  await new Promise((r) => setTimeout(r, 0));
  const img = document.querySelector('#list img');
  ok('premise: the element really was created', !!img);
  eq('an img injected through innerHTML has no live src', img.getAttribute('src'), null);
  eq('its URL is parked where the runtime looks', img.getAttribute('data-faz-src'), 'https://tracker.test/innerhtml.gif');
  ok('and it carries a category', !!img.getAttribute('data-faz-category'));
}

{
  const { window, document } = boot();
  document.getElementById('list').insertAdjacentHTML('beforeend', '<iframe src="https://tracker.test/iah.html"></iframe>');
  await new Promise((r) => setTimeout(r, 0));
  const frame = document.querySelector('#list iframe');
  ok('premise: the iframe really was created', !!frame);
  eq('an iframe injected through insertAdjacentHTML has no live src', frame.getAttribute('src'), null);
  ok('it is parked', !!frame.getAttribute('data-faz-src'));
  ok('and hidden, as the runtime hides a parked frame', frame.classList.contains('faz-hidden'));
}

{
  const { window, document } = boot();
  const host = document.createElement('div');
  document.body.appendChild(host);
  host.outerHTML = '<div id="replaced"><img src="https://tracker.test/outerhtml.gif"></div>';
  await new Promise((r) => setTimeout(r, 0));
  const img = document.querySelector('#replaced img');
  ok('premise: the replacement really happened', !!img);
  eq('an img injected through outerHTML has no live src', img.getAttribute('src'), null);
}

{
  // Same-origin markup is untouched: the backstop must not hold the site's own
  // images, or it would be a page-wide regression dressed up as a fix.
  const { window, document } = boot();
  document.getElementById('list').innerHTML = '<img src="/local.gif">';
  await new Promise((r) => setTimeout(r, 0));
  const img = document.querySelector('#list img');
  eq('a same-origin img injected through innerHTML keeps its src', img.getAttribute('src'), '/local.gif');
  eq('and is not parked', img.getAttribute('data-faz-src'), null);
}

{
  // The wider element set the observer covers for free, and which the setter
  // list had no entry for at all.
  const { window, document } = boot();
  document.getElementById('list').innerHTML =
    '<video src="https://tracker.test/v.mp4"></video>'
    + '<audio src="https://tracker.test/a.mp3"></audio>'
    + '<embed src="https://tracker.test/e.swf">'
    + '<object data="https://tracker.test/o.bin"></object>'
    + '<input type="image" src="https://tracker.test/i.gif">';
  await new Promise((r) => setTimeout(r, 0));
  eq('video is parked', document.querySelector('video').getAttribute('src'), null);
  eq('audio is parked', document.querySelector('audio').getAttribute('src'), null);
  eq('embed is parked', document.querySelector('embed').getAttribute('src'), null);
  eq('object[data] is parked', document.querySelector('object').getAttribute('data'), null);
  eq('input[type=image] is parked', document.querySelector('input').getAttribute('src'), null);
}

{
  // The other half of the same question: what the SERVER rendered.
  //
  // The observer is armed in <head>, so it also sees every node the parser
  // builds from the page's own HTML. Judging those by origin — all this file
  // can do — stripped the src off a static CDN image until the handoff, which
  // fetched it twice and took the LCP element down with it, and blocked
  // nothing: the parser had already started that request. The server-side
  // blocker is the authority for server markup and had already rewritten
  // whatever needed blocking.
  const { window, document, nativeGetAttribute, nativeSetAttribute, nativeAppendChild } = boot();
  const hero = document.createElement('img');
  nativeSetAttribute.call(hero, 'src', 'https://cdn.test/hero.jpg');
  nativeAppendChild.call(document.getElementById('list'), hero);
  await new Promise((r) => setTimeout(r, 0));
  eq('a parser-built cross-origin img keeps its src',
    nativeGetAttribute.call(hero, 'src'), 'https://cdn.test/hero.jpg');
  eq('and is not parked', nativeGetAttribute.call(hero, 'data-faz-src'), null);

  // Not a blanket exemption for untagged insertions: it holds only while the
  // parser can still be the one inserting. Once parsing is over, every record
  // is a script's doing again and the backstop applies to all of them —
  // including sinks nobody wrapped.
  Object.defineProperty(document, 'readyState', { value: 'interactive', configurable: true });
  const late = document.createElement('img');
  nativeSetAttribute.call(late, 'src', 'https://tracker.test/late.gif');
  nativeAppendChild.call(document.getElementById('list'), late);
  await new Promise((r) => setTimeout(r, 0));
  eq('the same insertion after parsing is parked',
    nativeGetAttribute.call(late, 'src'), null);
  eq('with its URL kept', nativeGetAttribute.call(late, 'data-faz-src'), 'https://tracker.test/late.gif');
}

{
  // The exemption must not be an allowlist that fails open on the one sink
  // nobody wrapped. document.write is that sink: its markup goes to the
  // parser's input stream, so there are no nodes to tag when it returns, and
  // the answer is to stop claiming the distinction at all — one written
  // resource element and every insertion is judged again.
  //
  // That contract cannot be exercised here: jsdom has already closed the
  // parser, so write() reopens the document and replaces documentElement,
  // which detaches the observer and would make this pass for the wrong
  // reason. It is pinned in tests/browser-intent/deferred-runtime.spec.ts
  // against a real parser instead. What IS checkable here is that the wrapper
  // is installed on both names, because the flag it sets is the whole gate.
  const { window, document } = boot();
  ok('document.write is wrapped', document.write !== window.Document.prototype.write);
  ok('document.writeln is wrapped', document.writeln !== window.Document.prototype.writeln);
}

{
  // And the parser exemption must not become a bypass for a script sink used
  // while the document is still parsing — an inline script in the server HTML
  // is exactly that, and it is the common case in this window.
  const { window, document, nativeGetAttribute } = boot();
  eq('premise: the document is parsing', document.readyState, 'loading');
  document.getElementById('list').innerHTML = '<img src="https://tracker.test/sink.gif">';
  const frag = document.createRange().createContextualFragment('<img src="https://tracker.test/range.gif">');
  document.body.appendChild(frag);
  await new Promise((r) => setTimeout(r, 0));
  eq('innerHTML during parsing is still judged',
    nativeGetAttribute.call(document.querySelector('#list img'), 'src'), null);
  const ranged = Array.prototype.slice.call(document.querySelectorAll('img'))
    .filter((el) => nativeGetAttribute.call(el, 'data-faz-src') === 'https://tracker.test/range.gif');
  eq('createContextualFragment during parsing is still judged', ranged.length, 1);
}

console.log('\nbootstrap: the safety release is not the end of enforcement');

// The release used to restore every native API and install nothing in its
// place, so from DOMContentLoaded on there was no client-side enforcement at
// all — while the warning it printed said third-party resources stayed parked.
{
  const { window, document, fetched } = boot();
  await release(window);

  const late = document.createElement('img');
  document.body.appendChild(late);
  late.src = 'https://tracker.test/after-release.gif';
  eq('a cross-origin src assigned AFTER the release is still not applied', late.getAttribute('src'), null);
  eq('it is parked instead', late.getAttribute('data-faz-src'), 'https://tracker.test/after-release.gif');

  const ours = document.createElement('img');
  document.body.appendChild(ours);
  ours.src = '/local-after-release.gif';
  eq('a same-origin src assigned after the release goes through', ours.getAttribute('src'), '/local-after-release.gif');

  const before = fetched.length;
  window.fetch('https://tracker.test/after-release');
  window.fetch(SITE + 'wp-json/after-release');
  await new Promise((r) => setTimeout(r, 0));
  eq('one of the two late requests was sent', fetched.length, before + 1);
  ok('and it is the first-party one', fetched[fetched.length - 1].indexOf('wp-json/after-release') !== -1);
}

console.log('\nbootstrap: API contracts the wrappers must not break');

// CSSStyleSheet.replace() resolves with the sheet, and callers chain on it.
{
  const { window, document } = boot();
  const sheet = new window.CSSStyleSheet();
  const promise = sheet.replace('.p{background-image:url(https://tracker.test/r.png)}');
  await release(window);
  const resolved = await promise;
  eq('a refused replace() still resolves with the sheet', resolved, sheet);
}

// data:, blob: and about: make no network request; judging them by origin
// called them remote ("null") and refused a site's own inline SVG.
{
  const { window } = boot();
  const xhr = new window.XMLHttpRequest();
  xhr.open('GET', 'data:text/plain,hello', false);
  let threw = '';
  try { xhr.send(); } catch (e) { threw = e.name || 'threw'; }
  ok('a synchronous data: XHR is not refused as cross-origin', threw !== 'InvalidStateError');
}

// A ws:// URL never equals an http(s) origin, so a same-origin socket was
// always refused while fetch and XHR let the identical host through.
{
  const sockets = [];
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: SITE });
  const { window } = dom;
  Object.defineProperty(window.document, 'readyState', { value: 'loading', configurable: true });
  window._fazConfig = { _block: true };
  window.WebSocket = function (url) { sockets.push(String(url)); };
  window.WebSocket.prototype = { addEventListener: function () {}, close: function () {}, send: function () {} };
  window.eval(code);
  new window.WebSocket('ws://site.test/live');
  new window.WebSocket('wss://tracker.test/live');
  eq('no socket connected while held', sockets.length, 0);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 0));
  eq('the same-origin socket connects on release', sockets.length, 1);
  ok('and it is the first-party one', sockets[0].indexOf('site.test') !== -1);
}

// An inline <script> with no src and no FAZ blocking attribute is deliberately
// not held: an inline script's one guarantee is that it has run by the time
// appendChild returns, and nothing here — nor in the runtime, whose gate keys on
// those same attributes — can read a body and tell a tracker snippet from a page
// builder's configuration. Holding it broke reading back an inline
// configuration on the very next line, an ordinary pattern.
{
  const { window, document } = boot();
  const inline = document.createElement('script');
  inline.textContent = 'window.__fazInlineRan = true;';
  document.body.appendChild(inline);
  ok('a plain inline script still runs synchronously on insertion',
    document.body.contains(inline));
  // One carrying the plugin's own category marker IS held, because then the
  // runtime's script gate has something to decide on.
  const tagged = document.createElement('script');
  tagged.setAttribute('data-faz-category', 'marketing');
  tagged.textContent = 'window.__fazTaggedRan = true;';
  document.body.appendChild(tagged);
  ok('a category-tagged inline script is held', !document.body.contains(tagged));
  const seen = [];
  window._fazBootstrap.start();
  window._fazBootstrap.finish({
    script: function (el) { seen.push(el.nodeName); return el; },
    node: function (el) { seen.push('node:' + el.nodeName); },
    url: function () { return false; }
  });
  ok('and it reaches the handoff policy', seen.length > 0);
}

// A second handoff must say so rather than return in silence: a drain that did
// nothing looks exactly like a drain that worked.
{
  const { window } = boot();
  const warnings = [];
  window.console.warn = function () { warnings.push(Array.prototype.join.call(arguments, ' ')); };
  runtimeHandoff(window);
  window._fazBootstrap.finish({ script: function () {}, node: function () {}, url: function () { return false; } });
  ok('a repeated handoff is reported', warnings.some((line) => line.indexOf('already drained') !== -1));
}

console.log('\nbootstrap: same-origin is decided by the URL parser, not by a prefix');

// A scheme allowlist matched on the raw string put this check and the engine's
// URL parser into disagreement, and the disagreement was exploitable:
// `blob:https://tracker.example/uuid` BEGINS with an allowed scheme while its
// resolved origin is a third party, so the string test called it same-origin and
// the safety release let it through. Decided on the resolved origin now.
{
  const { window, document } = boot();
  const img = document.createElement('img');
  document.body.appendChild(img);
  img.src = 'blob:https://tracker.test/abcd';
  await release(window);
  eq('a blob: URL carrying a third-party origin is not applied', img.getAttribute('src'), null);
  eq('it is parked', img.getAttribute('data-faz-src'), 'blob:https://tracker.test/abcd');
}

{
  // The other direction: a genuine same-origin blob — what URL.createObjectURL
  // hands back — must still be released, or a site's own object URLs break.
  const { window, document } = boot();
  const img = document.createElement('img');
  document.body.appendChild(img);
  img.src = 'blob:' + SITE + 'abcd';
  await release(window);
  eq('a same-origin blob: URL is released', img.getAttribute('src'), 'blob:' + SITE + 'abcd');
}

{
  // `javascript:` runs code rather than fetching and its resolved origin is
  // "null", so it has no business in a "makes no network request" allowlist.
  // It stays held, which is what this file did before the list was added.
  const { window, document } = boot();
  const frame = document.createElement('iframe');
  document.body.appendChild(frame);
  frame.src = 'javascript:void 0';
  await release(window);
  eq('a javascript: URL is not released by the safety policy', frame.getAttribute('src'), null);
}

{
  // data: and about: carry no network destination at all. Judging them by
  // origin called them remote ("null") and refused a site's own inline SVG.
  const { window, document } = boot();
  const a = document.createElement('img');
  const b = document.createElement('iframe');
  document.body.appendChild(a);
  document.body.appendChild(b);
  a.src = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
  b.src = 'about:blank';
  await release(window);
  eq('a data: URL is released', a.getAttribute('src'), 'data:image/gif;base64,R0lGODlhAQABAAAAACw=');
  eq('about:blank is released', b.getAttribute('src'), 'about:blank');
}

console.log('\nbootstrap: a consent-tagged inline script never runs without consent');

// The script gate read a URL to decide, so an inline script had none and the
// check returned early — the script then replayed and executed on the safety
// release, with no consent decision anywhere. On the one route where the
// publisher said explicitly that the code is gated, that is a bypass.
// Reproduced in Chromium before the fix.
{
  const { window, document } = boot();
  const tagged = document.createElement('script');
  tagged.setAttribute('data-faz-category', 'marketing');
  tagged.textContent = 'window.__fazTaggedRan = true;';
  document.body.appendChild(tagged);
  await release(window);
  eq('it is stamped inert rather than executed', tagged.getAttribute('type'), 'javascript/blocked');
  ok('and marked as ours, so the restore pass can re-enable it later',
    tagged.getAttribute('data-faz-blocked-by-us') === '1');
}

console.log('\nhandoff: the policy matches URLs the way the runtime does');

// The policy handed to finish() used to match the raw URL string, while the
// runtime's own interceptors match hostname+path via _fazExtractEndpoint. A
// first-party request whose query happened to carry a blocked domain was
// therefore refused before consent and allowed after it. The helper is at
// module scope now so both paths share one rule.
{
  const script = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: SITE });
  dom.window._fazConfig = { _categories: [], _block: true, _providersToBlock: [] };
  try { dom.window.eval(script); } catch (e) { /* no banner DOM in this fixture; the helper is already defined */ }
  const endpoint = dom.window._fazExtractEndpoint;
  ok('_fazExtractEndpoint is reachable at module scope', typeof endpoint === 'function');
  eq('a first-party URL carrying a blocked domain in its query is not an endpoint',
    endpoint('/wp-json/x?return=https://www.facebook.com/'), '');
  eq('a real third-party URL still normalises to hostname+path',
    endpoint('https://connect.facebook.net/en_US/fbevents.js'), 'connect.facebook.net/en_US/fbevents.js');
  eq('a protocol-relative URL normalises too',
    endpoint('//connect.facebook.net/x.js'), 'connect.facebook.net/x.js');
}

console.log('');
console.log(`bootstrap-deferred-hold: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
