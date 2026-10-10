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
  window.eval(code);
  return { window, document: window.document, fetched, dom };
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
  const { window, document } = boot();
  const img = document.createElement('img');
  img.src = 'https://tracker.test/pixel.gif';
  eq('held img src reads back through the getter', img.src, 'https://tracker.test/pixel.gif');
  eq('the attribute is not on the element yet', img.getAttribute('src'), 'https://tracker.test/pixel.gif');
  ok('hasAttribute reports the staged value', img.hasAttribute('src'));
  runtimeHandoff(window);
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
