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
function boot({ ready = 'loading', seed = '', holdDcl = false } = {}) {
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
  const nativeDocument = {
    write: window.Document.prototype.write,
    writeln: window.Document.prototype.writeln,
    open: window.Document.prototype.open,
    createElement: window.Document.prototype.createElement,
  };
  if (holdDcl) {
    // jsdom fires its own DOMContentLoaded on the microtask queue, which is
    // also where the observer delivers its records — so by the time a backstop
    // park exists the safety release has already decided, and a handoff after
    // it is ignored by design. A case that needs the HANDOFF side of a backstop
    // park silences jsdom's event here, before the bootstrap registers its own
    // listeners; the one release() dispatches is marked and let through.
    window.document.addEventListener('DOMContentLoaded', function (event) {
      if (!event.__fromTest) event.stopImmediatePropagation();
    }, true);
  }
  window.eval(code);
  return { window, document: window.document, fetched, dom, nativeGetAttribute, nativeSetAttribute, nativeAppendChild, nativeDocument };
}

// The handoff the real runtime performs once its own interceptors are in place.
// This policy refuses nothing, which is the consent-granted shape: every held
// operation replays live under it.
function runtimeHandoff(window) {
  window._fazBootstrap.start();
  window._fazBootstrap.finish({
    script: function () {},
    node: function () {},
    url: function () { return false; },
  });
}

// The same handoff with consent REFUSED for one tracker origin: the shape the
// real policy has (script, node, url, category), answering the way
// _fazParkResourceElementIfBlocked and _fazShouldBlockProvider answer for it.
// runtimeHandoff() above says "nothing is blocked", which is why a route that
// replayed live at the handoff could never turn a case red under it.
function refusingHandoff(window, blocked = /tracker\.test/) {
  window._fazBootstrap.start();
  window._fazBootstrap.finish({
    script: function () {},
    node: function (el) {
      const src = el.getAttribute && el.getAttribute('src');
      if (src && blocked.test(src)) {
        el.setAttribute('data-faz-src', src);
        el.setAttribute('data-faz-category', 'marketing');
        el.removeAttribute('src');
      }
    },
    url: function (url) { return blocked.test(String(url)); },
    category: function () { return 'marketing'; },
  });
}
// What a parked sheet holds, decoded the way the runtime decodes it.
function parkedCss(el) {
  return Buffer.from(String(el.getAttribute('data-faz-css') || ''), 'base64').toString('utf8');
}
// Is a fetchable tracker URL live in ANY <style> of the document?
function trackerLive(document) {
  return Array.from(document.querySelectorAll('style')).some((s) => s.textContent.indexOf('tracker.test') !== -1);
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
  const event = new window.Event('DOMContentLoaded');
  event.__fromTest = true;
  window.document.dispatchEvent(event);
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

console.log('\nbootstrap: a Range reaches the DOM through neither Node nor ParentNode');

// Range.insertNode and Range.surroundContents judged resource ELEMENTS and
// nothing else, and `style` is deliberately not in the resource list — so this
// was the one insertion route into a <style> that no CSS gate stood in front
// of. Every other route has a case in the section above; this one completes the
// same split URL the same way and had to be held the same way.
{
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  const range = document.createRange();
  range.setStart(style.firstChild, style.firstChild.data.length);
  range.collapse(true);
  range.insertNode(document.createTextNode('rl(https://tracker.test/r1.png)}'));
  ok('Range.insertNode completing a URL inside <style> is held',
    style.textContent.indexOf('tracker.test') === -1);
  runtimeHandoff(window);
  ok('and it applies at handoff', style.textContent.indexOf('tracker.test') !== -1);
}

{
  // The release side of the same route: with no runtime, no style text that
  // could fetch is applied at all.
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  const range = document.createRange();
  range.setStart(style.firstChild, style.firstChild.data.length);
  range.collapse(true);
  range.insertNode(document.createTextNode('rl(https://tracker.test/r2.png)}'));
  await release(window);
  ok('Range.insertNode: the safety release does not complete the URL',
    style.textContent.indexOf('tracker.test') === -1);
}

{
  // surroundContents is held on the same owner rule. Its new parent's own
  // children are discarded by the spec, so this is the conservative direction
  // on purpose: it costs a replay, and a rule per method is how this surface
  // was wrong repeatedly.
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  const wrapper = document.createElement('span');
  wrapper.textContent = 'rl(https://tracker.test/r3.png)}';
  const range = document.createRange();
  range.selectNodeContents(style);
  range.surroundContents(wrapper);
  ok('Range.surroundContents with URL-carrying text is held',
    style.textContent.indexOf('tracker.test') === -1);
}

{
  // The other error direction: a Range outside any <style> carries no CSS, so
  // ordinary page work must stay synchronous. Holding it would delay content
  // that can never fetch anything.
  const { window, document } = boot();
  const list = document.getElementById('list');
  list.appendChild(document.createTextNode('one'));
  const range = document.createRange();
  range.setStart(list.firstChild, 3);
  range.collapse(true);
  range.insertNode(document.createTextNode(' url(two)'));
  ok('a Range insertion outside a <style> is not held',
    list.textContent.indexOf('url(two)') !== -1);
}

console.log('\nbootstrap: a <style> can also ARRIVE with its CSS already in it');

// Every gate above asks whether the CONTAINER is a <style>, and the text-entry
// setters cover the ways a style grows text. What was left was a style built
// where no setter runs, then inserted. Both routes below were measured fetching
// on insertion, and both are ordinary DOM.
{
  const { window, document } = boot();
  const range = document.createRange();
  range.selectNodeContents(document.body);
  const frag = range.createContextualFragment('<style>@import url(https://tracker.test/f1.css);</style>');
  document.head.appendChild(frag);
  ok('a <style> arriving inside a createContextualFragment is held',
    document.head.textContent.indexOf('tracker.test') === -1);
  runtimeHandoff(window);
  ok('and it applies at handoff', document.head.textContent.indexOf('tracker.test') !== -1);
}

{
  const { window, document } = boot();
  const range = document.createRange();
  range.selectNodeContents(document.body);
  const frag = range.createContextualFragment('<style>@import url(https://tracker.test/f2.css);</style>');
  document.head.appendChild(frag);
  await release(window);
  ok('the safety release does not apply it either',
    document.head.textContent.indexOf('tracker.test') === -1);
}

{
  // surroundContents with a fresh <style> as the new parent turns existing page
  // text into a stylesheet. The text passed through no setter, and the owner is
  // not a <style> before the call — so neither of the two rules above sees it.
  const { window, document } = boot();
  const p = document.getElementById('list');
  p.appendChild(document.createTextNode('@import url(https://tracker.test/f3.css);'));
  const range = document.createRange();
  range.selectNodeContents(p);
  range.surroundContents(document.createElement('style'));
  const sheet = p.querySelector('style');
  ok('wrapping page text in a new <style> is held', !sheet || sheet.textContent.indexOf('tracker.test') === -1);
  runtimeHandoff(window);
  ok('and the wrap applies at handoff',
    !!p.querySelector('style') && p.querySelector('style').textContent.indexOf('tracker.test') !== -1);
}

{
  // A <style> with no URL in it fetches nothing and must not be delayed.
  const { window, document } = boot();
  const plain = document.createElement('style');
  plain.textContent = '.a{color:red}';
  document.head.appendChild(plain);
  ok('a <style> that cannot fetch is inserted immediately',
    document.head.textContent.indexOf('color:red') !== -1);
}

console.log('\nbootstrap: a <style> is a <style> in any namespace, and a range is mutable');

{
  // A range is MUTABLE. The owner was resolved when insertNode was called and
  // the insertion happens at replay, against the boundary as it stands THEN —
  // so validating the captured owner while inserting into the live one is a
  // validator/consumer split. Reachable with an innocuous half: `rl(https://…)}`
  // contains no url(, @import or image-set, so it passes on its own, and the
  // other <style> supplies the beginning. Measured completing the URL before
  // the owner was recomputed at replay.
  const { window, document } = boot({ seed: '<style id="a">.x{/*</style><style id="b">.p{background-image:u</style>' });
  const a = document.getElementById('a');
  const b = document.getElementById('b');
  const range = document.createRange();
  range.setStart(a.firstChild, a.firstChild.data.length);
  range.collapse(true);
  range.insertNode(document.createTextNode('rl(https://tracker.test/m1.png)}'));
  ok('premise: the insertion is held, so there is a window at all',
    a.textContent.indexOf('tracker.test') === -1);
  a.textContent = '';
  eq('premise: the first <style> is emptied through its own gated setter', a.textContent, '');
  range.setStart(b.firstChild, b.firstChild.data.length);
  range.collapse(true);
  await release(window);
  ok('moving the range to another <style> while held does not complete the URL',
    b.textContent.indexOf('tracker.test') === -1);
}

{
  // Range.prototype.toString is the engine's own answer for the text a range
  // spans, and it was reached through the page's property: `() => ''` turned the
  // surroundContents check into "no CSS here".
  const { window, document } = boot();
  window.Range.prototype.toString = function () { return ''; };
  const host = document.getElementById('list');
  host.appendChild(document.createTextNode('@import url(https://tracker.test/m2.css);'));
  const range = document.createRange();
  range.selectNodeContents(host);
  range.surroundContents(document.createElement('style'));
  const sheet = host.querySelector('style');
  ok('a shadowed Range.prototype.toString does not open the surround route',
    !sheet || sheet.textContent.indexOf('tracker.test') === -1);
}

const SVGNS = 'http://www.w3.org/2000/svg';

{
  // nodeName is uppercase only for HTML elements, so `=== 'STYLE'` answered no
  // for an SVG <style> — which browsers apply to the whole document, @import
  // included. Both directions were measured landing immediately.
  const { window, document } = boot();
  const svg = document.createElementNS(SVGNS, 'svg');
  const sheet = document.createElementNS(SVGNS, 'style');
  svg.appendChild(sheet);
  document.body.appendChild(svg);
  eq('premise: an SVG <style> does not report nodeName STYLE', sheet.nodeName, 'style');
  sheet.appendChild(document.createTextNode('@import url(https://tracker.test/m3.css);'));
  ok('CSS text appended into an SVG <style> is held',
    sheet.textContent.indexOf('tracker.test') === -1);
  runtimeHandoff(window);
  ok('and it applies at handoff', sheet.textContent.indexOf('tracker.test') !== -1);
}

{
  // The same element arriving with its text already in it, inserted bare —
  // the branch that asks about the node rather than the container.
  const { window, document } = boot();
  const sheet = document.createElementNS(SVGNS, 'style');
  sheet.appendChild(document.createTextNode('@import url(https://tracker.test/m4.css);'));
  document.body.appendChild(sheet);
  ok('a bare SVG <style> carrying CSS is held',
    document.body.textContent.indexOf('m4.css') === -1);
  runtimeHandoff(window);
  ok('and it applies at handoff', document.body.textContent.indexOf('m4.css') !== -1);
}

{
  // The other error direction, for the namespace too.
  const { window, document } = boot();
  const sheet = document.createElementNS(SVGNS, 'style');
  sheet.appendChild(document.createTextNode('.a{fill:red}'));
  document.body.appendChild(sheet);
  ok('an SVG <style> that cannot fetch is inserted immediately',
    document.body.textContent.indexOf('fill:red') !== -1);
}

console.log('\nhandoff: what the runtime refuses lands parked, not live and not dropped');

// Every "and it applies at handoff" above runs under a policy that refuses
// nothing. Under one that refuses the tracker, three routes replayed LIVE
// through the captured natives, and the runtime judged none of them: it gates
// the HTMLStyleElement interface with the style as PARENT, has no Range gate,
// no SVG <style> gate, and no observer branch for an inserted <style>. Measured
// in Chromium with consent refused: the stylesheet fetched right after the
// handoff. The contract now: a refused sheet lands in the document in the
// runtime's own parked form — neutralised text, the original in data-faz-css,
// a category — so the restore pass hands it back on the consent that allows
// it, and no fetchable tracker URL is live anywhere.
{
  // Route 1 — Range.insertNode completing a URL inside a connected <style>.
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  const range = document.createRange();
  range.setStart(style.firstChild, style.firstChild.data.length);
  range.collapse(true);
  range.insertNode(document.createTextNode('rl(https://tracker.test/r1.png)}'));
  refusingHandoff(window);
  ok('Range.insertNode: the refused URL is not live after the handoff', !trackerLive(document));
  eq('the owner is parked with the text the engine would have been given',
    parkedCss(style), '.p{background-image:url(https://tracker.test/r1.png)}');
  eq('and carries the category the restore selectors need', style.getAttribute('data-faz-category'), 'marketing');
}

{
  // The boundary in the MIDDLE of the text: the node is spliced in where the
  // range points, not appended, and the marker holds exactly that string. Read
  // in the other order the URL would not even be seen.
  const { window, document } = boot({ seed: '<style id="seed">.p{background-image:u}</style>' });
  const style = document.getElementById('seed');
  const range = document.createRange();
  range.setStart(style.firstChild, style.firstChild.data.indexOf('}'));
  range.collapse(true);
  range.insertNode(document.createTextNode('rl(https://tracker.test/mid.png)'));
  refusingHandoff(window);
  ok('a mid-text Range insertion is judged on the spliced text', !trackerLive(document));
  eq('and parked as the spliced text', parkedCss(style), '.p{background-image:url(https://tracker.test/mid.png)}');
}

{
  // Route 1, the other method — page text wrapped into a fresh <style>.
  const { window, document } = boot();
  const host = document.getElementById('list');
  host.appendChild(document.createTextNode('@import url(https://tracker.test/f3.css);'));
  const range = document.createRange();
  range.selectNodeContents(host);
  range.surroundContents(document.createElement('style'));
  refusingHandoff(window);
  const sheet = host.querySelector('style');
  ok('Range.surroundContents: the <style> is in the document', !!sheet);
  ok('with no live tracker URL', !trackerLive(document));
  eq('and the wrapped text parked in it', sheet && parkedCss(sheet), '@import url(https://tracker.test/f3.css);');
  eq('the page text it replaced is gone, as the wrap would have moved it', host.childNodes.length, 1);
}

{
  // Route 2 — a pre-filled <style> arriving inside a fragment, via appendChild.
  const { window, document } = boot();
  const range = document.createRange();
  range.selectNodeContents(document.body);
  const frag = range.createContextualFragment('<style id="frag">.a{background-image:url(https://tracker.test/frag-css.png)}</style>');
  document.head.appendChild(frag);
  refusingHandoff(window);
  const sheet = document.getElementById('frag');
  ok('appendChild of a fragment: the <style> is in the document', !!sheet);
  ok('with no live tracker URL', !trackerLive(document));
  eq('and its CSS parked', sheet && parkedCss(sheet), '.a{background-image:url(https://tracker.test/frag-css.png)}');
}

{
  // Route 2 through insertAdjacentElement and append, the other two entries.
  const { window, document } = boot();
  const viaAdjacent = document.createElement('style');
  viaAdjacent.id = 'adjacent';
  viaAdjacent.appendChild(document.createTextNode('.a{background:url(https://tracker.test/adj.png)}'));
  document.body.insertAdjacentElement('beforeend', viaAdjacent);
  const viaAppend = document.createElement('style');
  viaAppend.id = 'appended';
  viaAppend.appendChild(document.createTextNode('.b{background:url(https://tracker.test/app.png)}'));
  document.head.append(viaAppend);
  refusingHandoff(window);
  ok('insertAdjacentElement: the <style> lands', !!document.getElementById('adjacent'));
  ok('append: the <style> lands', !!document.getElementById('appended'));
  ok('neither with a live tracker URL', !trackerLive(document));
  eq('insertAdjacentElement: parked', parkedCss(viaAdjacent), '.a{background:url(https://tracker.test/adj.png)}');
  eq('append: parked', parkedCss(viaAppend), '.b{background:url(https://tracker.test/app.png)}');
}

{
  // Route 3 — the SVG <style>, both shapes: arriving with its text, and
  // growing text through a method the runtime never wrapped for that
  // interface.
  const { window, document } = boot();
  const bare = document.createElementNS(SVGNS, 'style');
  bare.appendChild(document.createTextNode('@import url(https://tracker.test/svg-css.css);'));
  document.body.appendChild(bare);
  const svg = document.createElementNS(SVGNS, 'svg');
  const grown = document.createElementNS(SVGNS, 'style');
  svg.appendChild(grown);
  document.body.appendChild(svg);
  grown.appendChild(document.createTextNode('.c{fill:url(https://tracker.test/svg-fill.svg#g)}'));
  refusingHandoff(window);
  ok('a bare SVG <style> lands', bare.parentNode === document.body);
  ok('and so does the text appended into one', grown.parentNode === svg);
  ok('with no live tracker URL in any <style>', !trackerLive(document));
  eq('bare: parked', parkedCss(bare), '@import url(https://tracker.test/svg-css.css);');
  eq('grown: parked', parkedCss(grown), '.c{fill:url(https://tracker.test/svg-fill.svg#g)}');
}

{
  // What "parked" means: the runtime's own form, not an emptied element. A
  // sheet that mixes the site's icon with a tracker pixel keeps the icon, the
  // tracker URL is replaced by the inert one the runtime uses, and the whole
  // original is in the marker — so the runtime's bookkeeping adopts it as a
  // sheet it parked itself.
  const { window, document } = boot();
  const mixed = document.createElement('style');
  mixed.appendChild(document.createTextNode('.i{background:url(/icon.png)} .t{background:url(https://tracker.test/px.png)}'));
  document.head.appendChild(mixed);
  refusingHandoff(window);
  ok("the site's own rule stays live", mixed.textContent.indexOf('url(/icon.png)') !== -1);
  ok('the refused one is inert', mixed.textContent.indexOf('url("data:application/octet-stream,")') !== -1);
  ok('and not live', mixed.textContent.indexOf('tracker.test') === -1);
  eq('the original is whole in the marker', parkedCss(mixed), '.i{background:url(/icon.png)} .t{background:url(https://tracker.test/px.png)}');
}

{
  // The other direction, explicitly: the same three routes under a policy that
  // allows the URL deliver the CSS live, with no marker left behind.
  const { window, document } = boot({ seed: SPLIT_SEED });
  const style = document.getElementById('seed');
  const range = document.createRange();
  range.setStart(style.firstChild, style.firstChild.data.length);
  range.collapse(true);
  range.insertNode(document.createTextNode('rl(https://tracker.test/ok.png)}'));
  const frag = document.createRange().createContextualFragment('<style id="ok-frag">.a{background:url(https://tracker.test/ok2.png)}</style>');
  document.head.appendChild(frag);
  const svgSheet = document.createElementNS(SVGNS, 'style');
  svgSheet.appendChild(document.createTextNode('@import url(https://tracker.test/ok3.css);'));
  document.body.appendChild(svgSheet);
  refusingHandoff(window, /other\.test/);
  ok('Range: live when allowed', style.textContent.indexOf('tracker.test/ok.png') !== -1);
  ok('fragment: live when allowed', document.getElementById('ok-frag').textContent.indexOf('ok2.png') !== -1);
  ok('SVG: live when allowed', svgSheet.textContent.indexOf('ok3.css') !== -1);
  eq('and nothing is marked as parked', document.querySelectorAll('style[data-faz-css]').length, 0);
}

console.log('\nsafety release: a subtree is delivered, its sheets judged one by one');

// A subtree carrying a <style> with a comment or a same-origin url() was held
// whole (the hold is deliberately broad) and on the safety release refused
// whole — so a widget cloned from a <template> with its own <style> and a
// <span> of cart text never appeared, where on main it had. Now the subtree is
// inserted, and each sheet in it is judged by origin: first-party content is
// left as written, a cross-origin sheet lands parked.
const WIDGET_CSS = '/* widget */ .cart{background:url(/cart.png)}';
const WIDGET_TEMPLATE = '<template id="widget"><style id="widget-css">' + WIDGET_CSS + '</style><span id="total">cart total</span></template>';

{
  // The template is the server's markup; what the script does is clone it
  // into the page, which is the subtree route.
  const { window, document } = boot({ seed: WIDGET_TEMPLATE });
  const tpl = document.getElementById('widget');
  document.body.appendChild(document.importNode(tpl.content, true));
  eq('premise: the widget is held while the runtime downloads', document.getElementById('total'), null);
  await release(window);
  ok('the widget is present after the safety release', !!document.getElementById('total'));
  eq('with its first-party stylesheet intact',
    document.getElementById('widget-css') && document.getElementById('widget-css').textContent, WIDGET_CSS);
  eq('and not marked as parked', document.querySelectorAll('style[data-faz-css]').length, 0);
}

{
  // The same widget written as markup — innerHTML is how a <template> is
  // usually filled, and the string sinks were refusing the whole string on
  // the same broad question.
  const { window, document } = boot();
  const host = document.getElementById('list');
  host.innerHTML = '<style id="widget-css">' + WIDGET_CSS + '</style><span id="total">cart total</span>';
  eq('premise: the markup is held while the runtime downloads', document.getElementById('total'), null);
  await release(window);
  ok('the markup is present after the safety release', !!document.getElementById('total'));
  eq('with its first-party stylesheet intact',
    document.getElementById('widget-css') && document.getElementById('widget-css').textContent, WIDGET_CSS);
}

{
  // The cross-origin half of the same rule: present, parked, recoverable.
  const { window, document, nativeGetAttribute } = boot({
    seed: '<template id="tracked"><style id="tracked-css">.px{background:url(https://tracker.test/widget.png)}</style><span id="copy">copy</span></template>',
  });
  document.body.appendChild(document.importNode(document.getElementById('tracked').content, true));
  await release(window);
  ok('the widget is present', !!document.getElementById('copy'));
  const sheet = document.getElementById('tracked-css');
  ok('its sheet is in the document', !!sheet);
  ok('with no live tracker URL', !trackerLive(document));
  eq('and the original parked where the runtime restores from',
    Buffer.from(String(nativeGetAttribute.call(sheet, 'data-faz-css')), 'base64').toString('utf8'),
    '.px{background:url(https://tracker.test/widget.png)}');
}

console.log('\nbootstrap: the backstop covers stylesheets and shadow trees too');

{
  // A <style> that reached the document through a route with no wrapper of its
  // own — here a tagged fragment inserted natively, which is how the tests
  // reproduce a sink nobody wrapped. Its @import is already gone; what parking
  // buys is every LAZY request in that sheet (background-image, @font-face src,
  // cursor, mask), fetched a frame later when something matching renders.
  const { window, document, nativeAppendChild, nativeGetAttribute } = boot();
  const frag = document.createRange().createContextualFragment('<style id="backstop">.a{background-image:url(https://tracker.test/n1.png)}</style>');
  nativeAppendChild.call(document.head, frag);
  await Promise.resolve();
  await Promise.resolve();
  const sheet = document.getElementById('backstop');
  eq('the sheet the backstop caught is emptied', sheet.textContent, '');
  const parked = nativeGetAttribute.call(sheet, 'data-faz-css');
  ok('and parked under the attribute the server and the runtime already use', !!parked);
  // Delayed, not destroyed — asserted here at the data level, because the live
  // restore cannot be exercised in jsdom at all: the observer delivers on the
  // microtask queue and so does jsdom's DOMContentLoaded, so by the time a
  // parked sheet exists the safety release has already decided, and a handoff
  // after it is ignored by design. The live round trip — parked, handed back at
  // the handoff, then judged by the runtime's own CSS gate with consent granted
  // and refused — is pinned against three real engines in
  // tests/browser-intent/written-style-and-shadow.spec.ts.
  eq('with the original intact, which is what makes the park reversible',
    Buffer.from(String(parked), 'base64').toString('utf8'),
    '.a{background-image:url(https://tracker.test/n1.png)}');
}

{
  // With no runtime there is no consent decision, so a sheet that could fetch
  // stays parked for the page view — the stance every other CSS route takes.
  const { window, document, nativeAppendChild, nativeGetAttribute } = boot();
  const frag = document.createRange().createContextualFragment('<style id="held">.a{background-image:url(https://tracker.test/n2.png)}</style>');
  nativeAppendChild.call(document.head, frag);
  await release(window);
  const sheet = document.getElementById('held');
  eq('the safety release leaves it parked', sheet.textContent, '');
  ok('with the original still recoverable from the attribute',
    !!nativeGetAttribute.call(sheet, 'data-faz-css'));
}

// The handoff side of a backstop park. The restore task wrote the text through
// the live property — the runtime's own setter by then — and dropped the
// marker AFTER the write. With consent refused the runtime's setter neutralises
// the text and rewrites data-faz-css as its own; the drop then deleted the
// runtime's marker, and _fazUnblockServerSide never found the sheet again on a
// later accept. The marker now survives a refusing handoff.
const BACKSTOP_CSS = '.a{background-image:url(https://tracker.test/n3.png)}';
const INERT = 'url("data:application/octet-stream,")';
// Park a sheet the way the backstop does: a tagged fragment inserted natively,
// delivered on the microtask queue.
async function backstopPark(document, nativeAppendChild, id, css) {
  const frag = document.createRange().createContextualFragment('<style id="' + id + '">' + css + '</style>');
  nativeAppendChild.call(document.head, frag);
  await Promise.resolve();
  await Promise.resolve();
  return document.getElementById(id);
}
// The runtime's textContent gate on HTMLStyleElement.prototype, reduced to
// what matters here: a blocked URL is neutralised and the marker rewritten,
// anything else is written natively with the marker removed. Installed between
// start() and finish(), where the real runtime installs its own.
function installRuntimeStyleGate(window, blocked = /tracker\.test/) {
  const desc = Object.getOwnPropertyDescriptor(window.Node.prototype, 'textContent');
  Object.defineProperty(window.HTMLStyleElement.prototype, 'textContent', {
    configurable: true,
    get: desc.get,
    set: function (value) {
      const css = String(value == null ? '' : value);
      if (blocked.test(css)) {
        desc.set.call(this, css.replace(/url\([^)]*\)/g, INERT));
        this.setAttribute('data-faz-css', Buffer.from(css, 'utf8').toString('base64'));
        this.setAttribute('data-faz-category', 'marketing');
        return;
      }
      desc.set.call(this, css);
      this.removeAttribute('data-faz-css');
    },
  });
}
function refusingHandoffWithGate(window) {
  window._fazBootstrap.start();
  installRuntimeStyleGate(window);
  window._fazBootstrap.finish({
    script: function () {},
    node: function () {},
    url: function (url) { return /tracker\.test/.test(String(url)); },
    category: function () { return 'marketing'; },
  });
}

{
  const { window, document, nativeAppendChild } = boot({ holdDcl: true });
  const sheet = await backstopPark(document, nativeAppendChild, 'refused', BACKSTOP_CSS);
  eq('premise: the backstop parked it', sheet.textContent, '');
  refusingHandoffWithGate(window);
  ok('after a refusing handoff the tracker URL is not live', sheet.textContent.indexOf('tracker.test') === -1);
  eq('and data-faz-css is still on the sheet, decoding to the original', parkedCss(sheet), BACKSTOP_CSS);
  eq('with the category the restore selectors need', sheet.getAttribute('data-faz-category'), 'marketing');
}

{
  // The other direction with the same gate in place: allowed CSS comes back
  // live and the marker is gone.
  const { window, document, nativeAppendChild } = boot({ holdDcl: true });
  const sheet = await backstopPark(document, nativeAppendChild, 'allowed', '.b{background-image:url(https://cdn.test/n4.png)}');
  eq('premise: the backstop parked it', sheet.textContent, '');
  refusingHandoffWithGate(window);
  eq('an allowed sheet is live after the handoff', sheet.textContent, '.b{background-image:url(https://cdn.test/n4.png)}');
  eq('with no marker left on it', sheet.getAttribute('data-faz-css'), null);
}

// The restore task wrote the parked text unconditionally. If the page rewrote
// the sheet with safe text while the park waited, the handoff put the old text
// back over the new one — and left a marker that a later restore pass would
// have used to resurrect the old text once more.
{
  const { window, document, nativeAppendChild } = boot({ holdDcl: true });
  const sheet = await backstopPark(document, nativeAppendChild, 'rewritten', BACKSTOP_CSS);
  eq('premise: the backstop parked it', sheet.textContent, '');
  // Through the gated setter, as a page would: safe text, applied at once.
  sheet.textContent = '.x{color:blue}';
  eq('premise: the page rewrote it while the park waited', sheet.textContent, '.x{color:blue}');
  runtimeHandoff(window);
  eq("the handoff leaves the page's text alone", sheet.textContent, '.x{color:blue}');
  eq('and takes its stale marker with it', sheet.getAttribute('data-faz-css'), null);
}

{
  // And the server's own stylesheet is left exactly as it was sent: untagged,
  // inserted while the document parses, which is the parser's signature. The
  // server-side pass already rewrote what had to be rewritten, and emptying it
  // here would cost a flash of unstyled content for nothing.
  const { window, document, nativeAppendChild } = boot();
  eq('premise: the document is parsing', document.readyState, 'loading');
  const sheet = document.createElement('style');
  nativeAppendChild.call(sheet, document.createTextNode('.a{background-image:url(/logo.png)}'));
  nativeAppendChild.call(document.head, sheet);
  await new Promise((r) => setTimeout(r, 0));
  ok('a server-rendered <style> is not touched', sheet.textContent.indexOf('logo.png') !== -1);
}

// Once a document.write has withdrawn the parser exemption, every <style> the
// parser builds after it reaches the backstop — and the backstop asked the
// broad question meant for script output, which a theme's comment or an
// icon-font escape answers "yes" to. Measured: after one written iframe, the
// theme sheet below was "" before the handoff and still "" after the safety
// release. A parser-built sheet has no URL to hide; it is judged on the URLs
// it names, by origin. A theme sheet stays live; a tracker @import is still
// held.
const THEME_CSS = '/* theme */ body{color:#123} .icon:before{content:"\\f101"}';
// What a write leaves behind in jsdom: the document reset and rebuilt from the
// written markup. The observer watches the document itself, so it survives.
function writeThen(document, nativeAppendChild, css) {
  document.write('<iframe src="https://ads.test/x"></iframe>');
  const sheet = document.createElement('style');
  nativeAppendChild.call(sheet, document.createTextNode(css));
  nativeAppendChild.call(document.head || document.documentElement, sheet);
  return sheet;
}

{
  const { window, document, nativeAppendChild } = boot();
  const sheet = writeThen(document, nativeAppendChild, THEME_CSS);
  // Microtasks only: the observer delivers on them, while a macrotask would
  // let jsdom fire its own DOMContentLoaded and the safety release would
  // decide before the handoff below could.
  await Promise.resolve();
  await Promise.resolve();
  eq('after a write, a first-party sheet with a comment and an icon escape stays live', sheet.textContent, THEME_CSS);
  // Never parked, so no task holds its text: whichever of the handoff and
  // jsdom's own DOMContentLoaded comes first, the sheet is not theirs to touch.
  runtimeHandoff(window);
  eq('and keeps its text once the queue drains', sheet.textContent, THEME_CSS);
  eq('with no marker on it', sheet.getAttribute('data-faz-css'), null);
}

{
  const { window, document, nativeAppendChild } = boot();
  const sheet = writeThen(document, nativeAppendChild, THEME_CSS);
  await new Promise((r) => setTimeout(r, 0));
  eq('premise: live before the release', sheet.textContent, THEME_CSS);
  await release(window);
  eq('and keeps its text through the safety release', sheet.textContent, THEME_CSS);
}

{
  const { window, document, nativeAppendChild, nativeGetAttribute } = boot();
  const sheet = writeThen(document, nativeAppendChild, '@import url(https://tracker.test/after-write.css);');
  await new Promise((r) => setTimeout(r, 0));
  eq('a parser-built sheet naming a cross-origin @import after a write is still held', sheet.textContent, '');
  ok('and parked', !!nativeGetAttribute.call(sheet, 'data-faz-css'));
}

{
  // The broad question is still the one asked of a sheet a SCRIPT sink built:
  // there the string was in the script's hands, and an escape can spell a
  // URL a regex does not see.
  const { window, document, nativeAppendChild, nativeGetAttribute } = boot();
  const frag = document.createRange().createContextualFragment('<style id="escaped">.p{background-image:u\\72 l(https://tracker.test/esc.png)}</style>');
  nativeAppendChild.call(document.head, frag);
  await Promise.resolve();
  await Promise.resolve();
  const sheet = document.getElementById('escaped');
  eq('a sink-built sheet hiding a URL behind an escape is still held', sheet.textContent, '');
  ok('and parked', !!nativeGetAttribute.call(sheet, 'data-faz-css'));
}

{
  // A shadow root is a SEPARATE tree: the observer on the document never
  // reaches it, and the markup sinks' tag pass did not cover its innerHTML
  // either, so `root.innerHTML = '<img src=tracker>'` had nothing in front of
  // it at all. Watched now as each root is created.
  const { window, document, nativeGetAttribute } = boot();
  // A <div>, not the <ul> the other cases use: attachShadow throws
  // NotSupportedError on an element that cannot host a shadow tree.
  const host = document.createElement('div');
  document.body.appendChild(host);
  if (typeof host.attachShadow === 'function') {
    const root = host.attachShadow({ mode: 'open' });
    root.innerHTML = '<img id="shadow-img" src="https://tracker.test/shadow.gif">';
    await Promise.resolve();
    await Promise.resolve();
    const img = root.getElementById ? root.getElementById('shadow-img') : root.querySelector('img');
    ok('premise: the markup built an element inside the shadow tree', !!img);
    eq('its live src is parked', nativeGetAttribute.call(img, 'src'), null);
    eq('under the attribute the runtime restores from',
      nativeGetAttribute.call(img, 'data-faz-src'), 'https://tracker.test/shadow.gif');
  } else {
    ok('premise: this engine has no attachShadow, so there is no tree to watch', true);
  }
}

{
  // The CSS half of the same tree: a shadow root's innerHTML is its own
  // descriptor on its own interface, so the gate on Element.prototype never
  // saw it.
  const { window, document } = boot();
  const host = document.createElement('div');
  document.body.appendChild(host);
  if (typeof host.attachShadow === 'function') {
    const root = host.attachShadow({ mode: 'open' });
    root.innerHTML = '<style>@import url(https://tracker.test/shadow.css);</style>';
    eq('a <style> written into a shadow root is held', root.querySelectorAll('style').length, 0);
    runtimeHandoff(window);
    eq('and applies at the handoff', root.querySelectorAll('style').length, 1);
  } else {
    ok('premise: no attachShadow on this engine', true);
  }
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

  // What makes that safe is not a phase check — the two natives above are the
  // only way to reach the document without being judged, and a page script
  // has neither. Through the wrapped API, which is the route it does have, the
  // same element is held whatever the document's phase.
  Object.defineProperty(document, 'readyState', { value: 'interactive', configurable: true });
  const late = document.createElement('img');
  late.src = 'https://tracker.test/late.gif';
  document.getElementById('list').appendChild(late);
  await new Promise((r) => setTimeout(r, 0));
  eq('the route a page script actually has is judged, parsing or not',
    nativeGetAttribute.call(late, 'src'), null);
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
  const { window, nativeDocument } = boot();
  // On Document.PROTOTYPE, not on the document: an instance wrapper is skipped
  // by Document.prototype.write.call(document, …), which is one property
  // lookup away. Same for createElement, whose wrapper feeds the script gate.
  ['write', 'writeln', 'open', 'createElement'].forEach(function (name) {
    ok('Document.prototype.' + name + ' is wrapped, so a prototype call cannot skip it',
      window.Document.prototype[name] !== nativeDocument[name]);
  });
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

{
  // Range.insertNode and Range.surroundContents reach the document without
  // touching Node or ParentNode, so they were the one insertion route that
  // arrived unjudged — and the exemption for untagged insertions is sound only
  // because no such route exists.
  const { window, document, nativeSetAttribute, nativeGetAttribute } = boot();
  const img = document.createElement('img');
  nativeSetAttribute.call(img, 'src', 'https://tracker.test/range.gif');
  const range = document.createRange();
  range.selectNodeContents(document.getElementById('list'));
  range.insertNode(img);
  eq('a resource inserted through Range.insertNode is held out of the document', img.parentNode, null);
  await release(window);
  eq('and parked on the safety release', nativeGetAttribute.call(img, 'data-faz-src'), 'https://tracker.test/range.gif');
  eq('with no live src', nativeGetAttribute.call(img, 'src'), null);
}

{
  // A Range insertion carrying nothing to judge keeps its synchronous
  // semantics, as every other wrapper here does.
  const { window, document } = boot();
  const plain = document.createElement('span');
  const range = document.createRange();
  range.selectNodeContents(document.getElementById('list'));
  range.insertNode(plain);
  eq('a Range insertion with no resource is not held', plain.parentNode && plain.parentNode.id, 'list');
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
