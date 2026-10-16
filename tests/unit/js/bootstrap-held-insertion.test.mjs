/**
 * JS unit test (jsdom) — a held insertion moves its node NOW.
 *
 * Production, 1.34.1 behind LiteSpeed: pages froze half-rendered and the tab
 * ran out of memory, and the symptom went away with "Ad-blocker compatibility
 * mode" (no bootstrap). The bootstrap's insertion wrappers queued an insertion
 * that carried a resource and returned as if it had happened, leaving the node
 * where it was. So the most ordinary DOM idiom there is,
 *
 *     while (src.firstChild) dst.appendChild(src.firstChild);
 *
 * never ended: src.firstChild stayed the same node and every turn queued one
 * more task, synchronously. The runtime's own _fazRenderBanner() is written
 * that way, and LiteSpeed "Remove Query Strings" made the browser run the
 * 1.34.0 runtime (which never hands over to the bootstrap) under 1.34.1 markup
 * — so it ran while the bootstrap was still pending, on a template with a logo.
 *
 * Cases:
 *   a. the loop terminates for appendChild, from a DOMParser document and from
 *      this one, and the children arrive in their original order — also when
 *      the fragment they were moved into is itself inserted while they wait;
 *   b. the same for append, insertBefore, insertAdjacentElement,
 *      replaceChildren, replaceWith (a lazy loader's querySelector loop) and
 *      removeChild out of a <style>, on the hold and on the safety release;
 *   c. the fuse: the queue never grows past its limit, and on the safety
 *      release created scripts are no longer retained;
 *   d. a namespace name (xmlns) is not a request.
 *
 * Every loop below is bounded by a counter, so the unfixed code FAILS here
 * instead of hanging the runner. Point FAZ_BOOTSTRAP_UNDER_TEST at another
 * copy of bootstrap.js to run the cases against it.
 *
 * Run: node tests/unit/js/bootstrap-held-insertion.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../../..');
const BOOTSTRAP_PATH = process.env.FAZ_BOOTSTRAP_UNDER_TEST || resolve(ROOT, 'frontend/js/bootstrap.js');
const code = readFileSync(BOOTSTRAP_PATH, 'utf8');

let passed = 0;
let failed = 0;
let skipped = 0;
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
function ok(label, cond) { eq(label, !!cond, true); }

const SITE = 'http://site.test/';
const MAX = 200; // far more turns than any loop here needs; reaching it is the bug

// A fresh document per case, the way bootstrap-deferred-hold builds it. jsdom
// fires its own DOMContentLoaded on the microtask queue, which would release
// the bootstrap before a case could observe the hold; it is silenced, and the
// one a case dispatches itself is marked.
function boot({ ready = 'loading', body = '<ul id="list"></ul>', head = '' } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head>${head}</head><body>${body}</body></html>`, {
    runScripts: 'outside-only',
    url: SITE,
  });
  const { window } = dom;
  let state = ready;
  Object.defineProperty(window.document, 'readyState', { configurable: true, get: () => state });
  window.document.addEventListener('DOMContentLoaded', function (event) {
    if (!event.__fromTest) event.stopImmediatePropagation();
  }, true);
  window._fazConfig = { _block: true };
  const fetched = [];
  window.fetch = function (input) {
    fetched.push(String(input && input.url ? input.url : input));
    return Promise.resolve({ ok: true });
  };
  const warnings = [];
  window.console.warn = function () { warnings.push(Array.prototype.join.call(arguments, ' ')); };
  window.console.error = function () {};
  const nativeGetAttribute = window.Element.prototype.getAttribute;
  window.eval(code);
  return {
    window, document: window.document, fetched, warnings, nativeGetAttribute,
    setReady(value) { state = value; },
  };
}
function handoff(window, policy = {}) {
  window._fazBootstrap.start();
  window._fazBootstrap.finish(Object.assign({
    script: function () {},
    node: function () {},
    url: function () { return false; },
  }, policy));
}
async function release(window) {
  const event = new window.Event('DOMContentLoaded');
  event.__fromTest = true;
  window.document.dispatchEvent(event);
  await new Promise((r) => setTimeout(r, 0));
}
const ids = (parent) => Array.prototype.map.call(parent.childNodes, (n) => n.id || (n.nodeType === 3 ? '#' + n.data : n.nodeName));
// Each case runs on its own: against code that still has the bug a case can
// throw half-way (an element that never arrived), and that must count as a
// failure of that case, not end the run before the others report.
async function scenario(fn) {
  try { await fn(); } catch (error) {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m the case threw: ${error && error.message}`);
  }
}

console.log('\na. the loop that moves children until the source is empty ends');

// The shape of _fazRenderBanner(): DOMParser body → fragment, the first child
// carrying a third-party image.
const TEMPLATE = '<div id="one"><img src="https://third.example/x.png"></div><p id="two">two</p><div id="three"><img src="https://third.example/y.png"></div><span id="four">four</span>';

await scenario(async () => {
  const { window, document } = boot();
  const doc = new window.DOMParser().parseFromString(TEMPLATE, 'text/html');
  const fragment = document.createDocumentFragment();
  let turns = 0;
  while (doc.body.firstChild && turns < MAX) { fragment.appendChild(doc.body.firstChild); turns += 1; }
  eq('appendChild from a DOMParser body: one turn per child', turns, 4);
  eq('the source is empty, as the call promised', doc.body.childNodes.length, 0);
  const img = doc.querySelector('img') || fragment.querySelector('img');
  ok('a held image is NOT adopted into this document while it waits (adoption is what fetches)',
    !img || img.ownerDocument !== document);
  handoff(window);
  eq('after the handoff the fragment holds the children in their original order',
    ids(fragment).join(','), 'one,two,three,four');
});

await scenario(async () => {
  // The rest of _fazRenderBanner(): the fragment is inserted into the page
  // while the children moved into it are still waiting. They must land where
  // the fragment went, not in an orphan.
  const { window, document } = boot({ body: '<p id="content">content</p>' });
  const doc = new window.DOMParser().parseFromString(TEMPLATE, 'text/html');
  const fragment = document.createDocumentFragment();
  let turns = 0;
  while (doc.body.firstChild && turns < MAX) { fragment.appendChild(doc.body.firstChild); turns += 1; }
  document.body.insertBefore(fragment, document.body.firstChild);
  eq('the loop ended', turns, 4);
  handoff(window);
  eq('the fragment\'s children reach the page, at the top, in order',
    ids(document.body).join(','), 'one,two,three,four,content');
});

await scenario(async () => {
  // Same, but released by the safety path instead of a runtime.
  const { window, document, nativeGetAttribute } = boot({ body: '<p id="content">content</p>' });
  const doc = new window.DOMParser().parseFromString(TEMPLATE, 'text/html');
  const fragment = document.createDocumentFragment();
  let turns = 0;
  while (doc.body.firstChild && turns < MAX) { fragment.appendChild(doc.body.firstChild); turns += 1; }
  document.body.insertBefore(fragment, document.body.firstChild);
  eq('the loop ended', turns, 4);
  await release(window);
  eq('the safety release delivers them in order', ids(document.body).join(','), 'one,two,three,four,content');
  eq('with the third-party image parked, not loaded', nativeGetAttribute.call(document.querySelector('#one img'), 'src'), null);
});

await scenario(async () => {
  // From this document: a plain list being moved.
  const { window, document } = boot({ body: '<div id="src"><img id="i1" src="https://third.example/1.png"><b id="b1"></b><img id="i2" src="https://third.example/2.png"></div><div id="dst"></div>' });
  const src = document.getElementById('src');
  const dst = document.getElementById('dst');
  let turns = 0;
  while (src.firstChild && turns < MAX) { dst.appendChild(src.firstChild); turns += 1; }
  eq('appendChild within the document: the loop ended', turns, 3);
  handoff(window);
  eq('and the order survived', ids(dst).join(','), 'i1,b1,i2');
});

await scenario(async () => {
  // A node moved by the page while its insertion waits goes where the page
  // put it last, not where the held call meant it to go.
  const { window, document } = boot({ body: '<div id="a"></div><div id="b"></div>' });
  const img = document.createElement('img');
  img.src = 'https://third.example/z.png';
  img.id = 'moved';
  const b = document.getElementById('b');
  document.getElementById('a').appendChild(img);
  img.remove(); // no parent the page knows of any more
  b.appendChild(document.createElement('i'));
  handoff(window);
  eq('a node the page removed while it waited is not reinserted', document.getElementById('moved'), null);
});

await scenario(async () => {
  // Removing a child the page gave this parent through a held call is a
  // removal of a child, as far as the page knows.
  const { window, document } = boot();
  const list = document.getElementById('list');
  const li = document.createElement('li');
  li.innerHTML = '<img src="https://third.example/r.png">';
  list.appendChild(li);
  let threw = '';
  try { list.removeChild(li); } catch (e) { threw = e.name; }
  eq('removeChild of a pending child does not throw', threw, '');
  handoff(window);
  eq('and the child never arrives', list.children.length, 0);
});

console.log('\nb. the same idiom through every other insertion route');

await scenario(async () => {
  const { window, document } = boot({ body: '<div id="src"><img id="i1" src="https://third.example/1.png"><b id="b1"></b><img id="i2" src="https://third.example/2.png"></div>' });
  const src = document.getElementById('src');
  const fragment = document.createDocumentFragment();
  let turns = 0;
  while (src.firstChild && turns < MAX) { fragment.append(src.firstChild); turns += 1; }
  eq('append: the loop ended', turns, 3);
  handoff(window);
  eq('append: original order', ids(fragment).join(','), 'i1,b1,i2');
});

await scenario(async () => {
  const { window, document } = boot({ body: '<div id="src"><img id="i1" src="https://third.example/1.png"><b id="b1"></b><img id="i2" src="https://third.example/2.png"></div><div id="dst"><hr id="end"></div>' });
  const src = document.getElementById('src');
  const dst = document.getElementById('dst');
  const end = document.getElementById('end');
  let turns = 0;
  while (src.firstChild && turns < MAX) { dst.insertBefore(src.firstChild, end); turns += 1; }
  eq('insertBefore: the loop ended', turns, 3);
  handoff(window);
  eq('insertBefore: original order, before the reference node', ids(dst).join(','), 'i1,b1,i2,end');
});

await scenario(async () => {
  const { window, document } = boot({ body: '<div id="src"><img id="i1" src="https://third.example/1.png"><b id="b1"></b><img id="i2" src="https://third.example/2.png"></div><div id="dst"></div>' });
  const src = document.getElementById('src');
  const dst = document.getElementById('dst');
  let turns = 0;
  while (src.firstElementChild && turns < MAX) { dst.insertAdjacentElement('beforeend', src.firstElementChild); turns += 1; }
  eq('insertAdjacentElement: the loop ended', turns, 3);
  handoff(window);
  eq('insertAdjacentElement: original order', ids(dst).join(','), 'i1,b1,i2');
});

await scenario(async () => {
  const { window, document } = boot({ body: '<div id="src"><img id="i1" src="https://third.example/1.png"><b id="b1"></b><img id="i2" src="https://third.example/2.png"></div><div id="dst"><s id="old"></s></div>' });
  const src = document.getElementById('src');
  const dst = document.getElementById('dst');
  dst.replaceChildren.apply(dst, Array.prototype.slice.call(src.childNodes));
  eq('replaceChildren: the nodes left their source at once', src.childNodes.length, 0);
  eq('replaceChildren: and the old children left the target', document.getElementById('old'), null);
  handoff(window);
  eq('replaceChildren: original order', ids(dst).join(','), 'i1,b1,i2');
});

await scenario(async () => {
  // A lazy loader: replace every placeholder until none is left.
  const { window, document } = boot({ body: '<div id="host"><i class="lazy" id="p1"></i><b id="mid"></b><i class="lazy" id="p2"></i></div>' });
  let turns = 0;
  let el;
  while ((el = document.querySelector('.lazy')) && turns < MAX) {
    const img = document.createElement('img');
    img.id = 'img-' + el.id;
    img.src = 'https://third.example/' + el.id + '.png';
    el.replaceWith(img);
    turns += 1;
  }
  eq('replaceWith in a querySelector loop: the loop ended', turns, 2);
  handoff(window);
  eq('replaceWith: each image where its placeholder was', ids(document.getElementById('host')).join(','), 'img-p1,mid,img-p2');
});

await scenario(async () => {
  // A node passed to its own replaceWith stays, with the others after it.
  const { window, document } = boot({ body: '<div id="host"><i id="keep"></i><b id="after"></b></div>' });
  const keep = document.getElementById('keep');
  const img = document.createElement('img');
  img.id = 'img';
  img.src = 'https://third.example/k.png';
  let threw = '';
  try { keep.replaceWith(keep, img); } catch (e) { threw = e.name; }
  eq('replaceWith listing the node itself does not throw', threw, '');
  handoff(window);
  eq('and lands as the specification orders it', ids(document.getElementById('host')).join(','), 'keep,img,after');
});

await scenario(async () => {
  const { window, document } = boot();
  const list = document.getElementById('list');
  const doc = new window.DOMParser().parseFromString(TEMPLATE, 'text/html');
  let turns = 0;
  while (doc.body.firstChild && turns < MAX) { list.appendChild(doc.body.firstChild); turns += 1; }
  await release(window);
  eq('safety release: the appendChild loop ended', turns, 4);
  // Once released, every insertion decides at once; the same loop runs live.
  const doc2 = new window.DOMParser().parseFromString(TEMPLATE.replace(/id="/g, 'id="x'), 'text/html');
  turns = 0;
  while (doc2.body.firstChild && turns < MAX) { list.appendChild(doc2.body.firstChild); turns += 1; }
  eq('after the release the loop ends too', turns, 4);
  eq('and every child arrived, in order', ids(list).join(','), 'one,two,three,four,xone,xtwo,xthree,xfour');
});

// Removing out of a <style>: the held removal was the same bug in reverse.
const SPLIT_SHEET = '<style id="sheet">.p{background-image:url(https:/*x*/ /tracker.test/b.png)}</style>';
function splitThree(document) {
  const style = document.getElementById('sheet');
  const head = style.firstChild;
  const middle = head.splitText(head.data.indexOf('/*x*/'));
  middle.splitText(5);
  return style;
}

await scenario(async () => {
  const { window, document } = boot({ head: SPLIT_SHEET });
  const style = splitThree(document);
  let turns = 0;
  while (style.firstChild && turns < MAX) { style.removeChild(style.firstChild); turns += 1; }
  eq('removeChild out of a <style>: the loop ended', turns, 3);
  eq('the sheet is empty', style.childNodes.length, 0);
  style.appendChild(document.createTextNode('.a{color:red}'));
  handoff(window);
  eq('and holds what the page wrote after it, not the resurrected original', style.textContent, '.a{color:red}');
  eq('with its type back', style.getAttribute('type'), null);
});

await scenario(async () => {
  // The intermediate state is the one that must not fetch: the comment that
  // split the URL is removed while the head and tail remain.
  const { window, document } = boot({ head: SPLIT_SHEET });
  const style = splitThree(document);
  const middle = style.childNodes[1];
  middle.remove();
  eq('remove(): the comment node leaves at once', middle.parentNode, null);
  eq('while the revealed URL sits in a sheet the engine does not build', style.sheet, null);
  handoff(window, { url: (u) => /tracker\.test/.test(String(u)), category: () => 'marketing' });
  ok('a refusing runtime parks the remainder instead of applying it', style.textContent.indexOf('tracker.test') === -1);
  ok('keeping the original for the restore pass', String(style.getAttribute('data-faz-css') || '') !== '');
});

await scenario(async () => {
  const { window, document } = boot({ head: SPLIT_SHEET });
  await release(window);
  const style = splitThree(document);
  let turns = 0;
  while (style.firstChild && turns < MAX) { style.removeChild(style.firstChild); turns += 1; }
  // On this path the remainder is judged at once and parked in one piece, so
  // the page may see fewer children than it split — what matters is the end.
  ok('safety release: removing out of a <style> ends too', turns < MAX && style.childNodes.length === 0);
  ok('and nothing third-party was left live in it on the way', style.textContent.indexOf('tracker.test') === -1);
});

console.log('\nc. the fuse');

await scenario(async () => {
  const { window, warnings, fetched } = boot();
  for (let i = 0; i < 5200; i += 1) window.fetch('https://tracker.test/beacon?' + i);
  ok('past the limit the bootstrap says so', warnings.some((l) => l.indexOf('More than 5000 operations') !== -1));
  eq('and releases under the safety policy', window._fazBootstrap.released, true);
  window.fetch(SITE + 'wp-json/after');
  await Promise.resolve();
  ok('from there a request is decided at once, not queued', fetched.indexOf(SITE + 'wp-json/after') !== -1);
  ok('third-party requests stay refused', fetched.every((u) => u.indexOf('tracker.test') === -1));
});

await scenario(async () => {
  // On the safety release nothing drains the created-scripts list, so it must
  // not be filled: a late runtime is asked about none of them.
  const { window, document } = boot({ ready: 'complete' });
  await Promise.resolve();
  await new Promise((r) => setTimeout(r, 0));
  eq('premise: released', window._fazBootstrap.released, true);
  for (let i = 0; i < 20; i += 1) document.createElement('script');
  let asked = 0;
  handoff(window, { script: function () { asked += 1; } });
  eq('scripts created after the safety release are not retained', asked, 0);
});

console.log('\nd. a namespace name is not a request');

await scenario(async () => {
  const { window, document, nativeGetAttribute } = boot();
  const icon = document.createElement('iframe');
  icon.srcdoc = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><path d="M0 0h1"/></svg>';
  document.body.appendChild(icon);
  const tracked = document.createElement('iframe');
  tracked.srcdoc = '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://tracker.test/p.png"/></svg>';
  document.body.appendChild(tracked);
  const trick = document.createElement('iframe');
  trick.srcdoc = '<p> xmlns=\'</p><img src="https://tracker.test/x.png">\'</p>';
  document.body.appendChild(trick);
  await release(window);
  ok('an SVG that only names its namespaces is applied on the safety release', String(nativeGetAttribute.call(icon, 'srcdoc') || '').indexOf('<svg') === 0);
  eq('one that also names a third party stays parked', nativeGetAttribute.call(tracked, 'srcdoc'), null);
  eq('and an attribute-shaped string outside a tag hides nothing', nativeGetAttribute.call(trick, 'srcdoc'), null);
});

console.log('');
console.log(`bootstrap-held-insertion: ${passed} passed, ${failed} failed${skipped ? `, ${skipped} skipped` : ''}`);
process.exit(failed === 0 ? 0 : 1);
