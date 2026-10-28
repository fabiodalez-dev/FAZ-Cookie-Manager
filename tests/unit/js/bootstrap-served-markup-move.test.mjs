/**
 * JS unit test (jsdom) — moving the server's own markup is not held.
 *
 * Production, 1.34.3, Divi + WooCommerce (wp.org forum, "WooCommerce product
 * images not showing"): every product gallery was empty. Divi's Salvattore
 * regroups any `[data-columns]` element into columns while the page parses:
 * it pulls the children out with Range.extractContents() and appends them
 * back inside new column <div>s. The final append carried the gallery's
 * <img>, so the bootstrap held it until the runtime arrived. Divi's next line
 * serialized the gallery through outerHTML — with the column still empty —
 * and the held images later replayed into the detached original.
 *
 * Those images were the PARSER's: the server-side blocker judged them and
 * their request had already left. Holding the move bought nothing.
 *
 * Cases:
 *   a. the Salvattore sequence on parser-inserted markup: the image is in the
 *      column synchronously, and an outerHTML taken on the next line has it;
 *   b. an image a SCRIPT built (createElement + src) is still held;
 *   c. an image from a DOMParser document is still held;
 *   d. a script the server parked, turned executable and moved by a page
 *      script, is still held: a parked script is not "already started", so
 *      exempting the move would run it with no verdict;
 *   e. a server <iframe> detached, its src rewritten through the Attr node
 *      (a route the setters and setAttribute do not see) and inserted back
 *      is still held: the exemption is for the URL the server judged, not
 *      for the element — and the same holds for a relative src whose
 *      document base a script rewrote (a <base> to another host, or a
 *      pushState() under a relative <base>), while a history.replaceState()
 *      that only rewrites the query (utm_* stripping) leaves the exemption
 *      alone;
 *   f. the other insertion paths the exemption reaches — Range.insertNode(),
 *      insertAdjacentElement() and the ParentNode methods (append, prepend,
 *      before, after, replaceWith, replaceChildren) — move a server image on
 *      the same turn and still hold one a script built;
 *   g. attribute signatures cannot collide across embedded newlines;
 *   h. a changed fetching mode (link rel, input type) requires a new verdict;
 *   i. a parser image adopted into another document, re-inserted after this
 *      page's <base> moved to another host, is held: the record resolves
 *      against the page it is inserted into, not the adopting document.
 *
 * The "parser" is the native appendChild captured before the bootstrap runs:
 * no wrapper sees the call and no sink tags the node, which is exactly how a
 * parser insertion looks to the bootstrap's observer.
 *
 * Point FAZ_BOOTSTRAP_UNDER_TEST at another copy of bootstrap.js to run the
 * cases against it.
 *
 * Run: node tests/unit/js/bootstrap-served-markup-move.test.mjs
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
const GALLERY = '<div class="woocommerce-product-gallery" data-columns="4">'
  + '<div class="woocommerce-product-gallery__wrapper"><div class="woocommerce-product-gallery__image">'
  + '<a href="/wp-content/uploads/p.jpg"><img class="wp-post-image" src="/wp-content/uploads/p-600x450.jpg"'
  + ' srcset="/wp-content/uploads/p-600x450.jpg 600w"></a></div></div></div>';

// Same shape as bootstrap-held-insertion's boot(): the document stays
// 'loading' and jsdom's own DOMContentLoaded is silenced, so the bootstrap
// keeps holding until a case releases it.
function boot() {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><main id="main"></main></body></html>', {
    runScripts: 'outside-only',
    url: SITE,
  });
  const { window } = dom;
  Object.defineProperty(window.document, 'readyState', { configurable: true, get: () => 'loading' });
  window.document.addEventListener('DOMContentLoaded', function (event) {
    if (!event.__fromTest) event.stopImmediatePropagation();
  }, true);
  window._fazConfig = { _block: true };
  window.fetch = function () { return Promise.resolve({ ok: true }); };
  window.console.warn = function () {};
  window.console.error = function () {};
  const nativeAppend = window.Node.prototype.appendChild;
  const nativeHTML = Object.getOwnPropertyDescriptor(window.Element.prototype, 'innerHTML').set;
  window.eval(code);
  // The parser: markup built in a detached template, inserted natively.
  function parse(html) {
    const holder = window.document.createElement('div');
    nativeHTML.call(holder, html);
    const node = holder.firstElementChild;
    nativeAppend.call(window.document.getElementById('main'), node);
    return node;
  }
  return { window, document: window.document, parse };
}
// The observer's records are delivered on a microtask; a parser-run script
// starts only after that checkpoint.
const checkpoint = () => new Promise((r) => setTimeout(r, 0));

// Salvattore's registerGrid() + addColumns(), reduced to the DOM calls it makes.
function salvattore(document, grid) {
  const range = document.createRange();
  range.selectNodeContents(grid);
  const staging = document.createElement('div');
  staging.appendChild(range.extractContents());
  const column = document.createElement('div');
  column.className = 'column';
  const items = document.createDocumentFragment();
  Array.prototype.slice.call(staging.children).forEach((child) => items.appendChild(child));
  column.appendChild(items);
  const columns = document.createDocumentFragment();
  columns.appendChild(column);
  grid.appendChild(columns);
}

console.log('a. Salvattore regrouping the server\'s gallery');
{
  const { document, parse } = boot();
  const grid = parse(GALLERY);
  await checkpoint();
  salvattore(document, grid);
  ok('the image is in the column on the same turn', grid.querySelector('.column img.wp-post-image'));
  // Divi's next line: rename data-columns through outerHTML.
  grid.outerHTML = grid.outerHTML.replace('data-columns', 'data-cols');
  const after = document.querySelector('.woocommerce-product-gallery');
  ok('the gallery Divi re-inserted still holds the image', after && after.querySelector('img.wp-post-image'));
}

console.log('b. an image a script built is still held');
{
  const { document, parse } = boot();
  parse('<div id="box"></div>');
  await checkpoint();
  const img = document.createElement('img');
  img.setAttribute('src', 'https://tracker.example/p.gif');
  document.getElementById('box').appendChild(img);
  eq('not in the document until the runtime answers', document.getElementById('box').contains(img), false);
}

console.log('c. an image from a DOMParser document is still held');
{
  const { window, document, parse } = boot();
  parse('<div id="box"></div>');
  await checkpoint();
  const foreign = new window.DOMParser().parseFromString('<img src="https://tracker.example/p.gif">', 'text/html');
  const img = foreign.body.firstChild;
  document.getElementById('box').appendChild(img);
  eq('not in the document until the runtime answers', document.getElementById('box').contains(img), false);
}

console.log('d. a server-parked script flipped back and moved is still held');
{
  const { document, parse } = boot();
  const box = parse('<div id="box"><script type="text/plain" data-faz-category="marketing" src="https://tracker.example/t.js"></script></div>');
  await checkpoint();
  const script = box.querySelector('script');
  script.setAttribute('type', 'text/javascript');
  const dest = document.createElement('div');
  document.getElementById('main').appendChild(dest);
  dest.appendChild(script);
  eq('not in the document until the runtime answers', dest.contains(script), false);
}

console.log('e. a server iframe whose src was rewritten while detached is still held');
{
  const { document, parse } = boot();
  const box = parse('<div id="box"><iframe src="https://video.example/embed/1"></iframe></div>');
  await checkpoint();
  const frame = box.querySelector('iframe');
  box.removeChild(frame);
  frame.getAttributeNode('src').value = 'https://tracker.example/frame';
  box.appendChild(frame);
  eq('not in the document until the runtime answers', box.contains(frame), false);
  const kept = parse('<div id="kept"><iframe src="https://video.example/embed/2"></iframe></div>');
  await checkpoint();
  const same = kept.querySelector('iframe');
  const dest = document.createElement('div');
  document.getElementById('main').appendChild(dest);
  dest.appendChild(same);
  eq('an unchanged server iframe still moves on the same turn', dest.contains(same), true);
}
{
  const { document, parse } = boot();
  const box = parse('<div id="box"><iframe src="widget.html"></iframe></div>');
  await checkpoint();
  const frame = box.querySelector('iframe');
  const base = document.createElement('base');
  base.setAttribute('href', 'https://tracker.example/');
  document.head.appendChild(base);
  box.removeChild(frame);
  box.appendChild(frame);
  eq('a relative src re-resolved against a rewritten <base> is held', box.contains(frame), false);
}
{
  const { window, document, parse } = boot();
  const box = parse('<div id="box"><img src="p.jpg"></div>');
  await checkpoint();
  window.history.replaceState(null, '', '/?utm_source=x');
  const img = box.querySelector('img');
  const dest = document.createElement('div');
  document.getElementById('main').appendChild(dest);
  dest.appendChild(img);
  eq('a same-origin history.replaceState() does not hold a server image', dest.contains(img), true);
}
{
  const { window, document, parse } = boot();
  const base = document.createElement('base');
  base.setAttribute('href', 'assets/');
  window.Node.prototype.appendChild.call(document.head, base);
  const box = parse('<div id="box"><iframe src="widget.html"></iframe></div>');
  await checkpoint();
  window.history.pushState(null, '', '/other/');
  const frame = box.querySelector('iframe');
  box.removeChild(frame);
  box.appendChild(frame);
  eq('a pushState() under a relative <base> holds a relative server iframe', box.contains(frame), false);
}

{
  const { document, parse } = boot();
  const base = document.createElement('base');
  base.setAttribute('href', '/allowed.html');
  document.head.appendChild(base);
  const frame = parse('<iframe src="?pixel"></iframe>');
  await checkpoint();
  frame.remove();
  await checkpoint();
  base.setAttribute('href', '/denied.html');
  document.getElementById('main').appendChild(frame);
  eq('a query-relative src is held when the base filename changes', frame.isConnected, false);
}

for (const name of ['use', 'image', 'feImage']) {
  const { window, document, parse } = boot();
  window.history.replaceState(null, '', '/?utm_source=x');
  const svg = parse(`<svg><${name} href="#icon"></${name}></svg>`);
  await checkpoint();
  window.history.replaceState(null, '', '/');
  const dest = document.createElement('div');
  document.getElementById('main').appendChild(dest);
  dest.appendChild(svg);
  eq(`${name}: a local SVG fragment still moves after query stripping`, dest.contains(svg), true);
}

{
  const { document, parse } = boot();
  const svg = parse('<svg><use href="#icon"></use></svg>');
  await checkpoint();
  const base = document.createElement('base');
  base.href = 'https://tracker.example/';
  document.head.appendChild(base);
  const dest = document.createElement('div');
  document.getElementById('main').appendChild(dest);
  dest.appendChild(svg);
  eq('an SVG fragment redirected to another document is still held', dest.contains(svg), false);
}
{
  const { window, document, parse } = boot();
  window.history.replaceState(null, '', '/?utm_source=x');
  const frame = parse('<iframe src="#section"></iframe>');
  await checkpoint();
  frame.remove();
  window.history.replaceState(null, '', '/');
  document.getElementById('main').appendChild(frame);
  eq('an iframe fragment still compares its complete destination', frame.isConnected, false);
}

console.log('f. the other insertion paths: server image moves, script image held');
{
  const paths = {
    'Range.insertNode': (document, dest, img) => {
      const range = document.createRange();
      range.selectNodeContents(dest);
      range.insertNode(img);
    },
    'insertAdjacentElement': (document, dest, img) => dest.insertAdjacentElement('beforeend', img),
    'append': (document, dest, img) => dest.append(img),
    'prepend': (document, dest, img) => dest.prepend(img),
    'before': (document, dest, img) => dest.firstChild.before(img),
    'after': (document, dest, img) => dest.firstChild.after(img),
    'replaceWith': (document, dest, img) => dest.firstChild.replaceWith(img),
    'replaceChildren': (document, dest, img) => dest.replaceChildren(img),
  };
  for (const [name, insert] of Object.entries(paths)) {
    const { document, parse } = boot();
    const box = parse('<div><img src="/wp-content/uploads/p.jpg"></div>');
    await checkpoint();
    const served = box.querySelector('img');
    const dest = document.createElement('div');
    dest.appendChild(document.createElement('span'));
    document.getElementById('main').appendChild(dest);
    insert(document, dest, served);
    eq(`${name}: the server image is moved on the same turn`, dest.contains(served), true);
    const built = document.createElement('img');
    built.setAttribute('src', 'https://tracker.example/p.gif');
    const other = document.createElement('div');
    other.appendChild(document.createElement('span'));
    document.getElementById('main').appendChild(other);
    insert(document, other, built);
    eq(`${name}: a script-built image is held`, other.contains(built), false);
  }
}

console.log('g. attribute signatures cannot collide across embedded newlines');
for (const insert of ['appendChild', 'append', 'insertAdjacentElement', 'Range.insertNode']) {
  const { document, parse } = boot();
  // One parser attribute must never compare equal to two different attributes.
  const frame = parse('<iframe background="/empty\nsrc=https://tracker.example/frame"></iframe>');
  await checkpoint();
  frame.remove();
  // Once the removal record has drained, edits of the detached node produce
  // no document observer records. Reinsertion must judge the changed URLs.
  await checkpoint();
  frame.getAttributeNode('background').value = '/empty';
  const src = document.createAttribute('src');
  src.value = 'https://tracker.example/frame';
  frame.setAttributeNode(src);
  const dest = document.getElementById('main');
  if (insert === 'Range.insertNode') {
    const range = document.createRange();
    range.selectNodeContents(dest);
    range.insertNode(frame);
  } else if (insert === 'insertAdjacentElement') {
    dest.insertAdjacentElement('beforeend', frame);
  } else {
    dest[insert](frame);
  }
  eq(`${insert}: a colliding signature cannot exempt the new src`, frame.isConnected, false);
}

console.log('h. a changed fetching mode requires a new verdict');
for (const sample of [
  { html: '<link rel="canonical" href="https://tracker.example/style.css">', key: 'rel', value: 'stylesheet' },
  { html: '<input type="text" background="/unused" src="https://tracker.example/pixel">', key: 'type', value: 'image' },
]) {
  const { document, parse } = boot();
  const node = parse(sample.html);
  await checkpoint();
  node.remove();
  await checkpoint();
  node.setAttribute(sample.key, sample.value);
  document.getElementById('main').appendChild(node);
  eq(`${node.tagName}: changing ${sample.key} invalidates the parser verdict`, node.isConnected, false);
}
{
  const { document, parse } = boot();
  const link = parse('<link rel="stylesheet" href="/theme.css">');
  await checkpoint();
  const dest = document.createElement('div');
  document.getElementById('main').appendChild(dest);
  dest.appendChild(link);
  eq('an unchanged parser stylesheet still moves immediately', dest.contains(link), true);
}

console.log('i. an adopted parser image re-inserted after a <base> change is held');
for (const route of ['DOMParser', 'createHTMLDocument']) {
  const { window, document, parse } = boot();
  const box = parse('<div><img src="p.jpg"></div>');
  await checkpoint();
  const img = box.querySelector('img');
  const other = route === 'DOMParser'
    ? new window.DOMParser().parseFromString('<!DOCTYPE html><html><body></body></html>', 'text/html')
    : document.implementation.createHTMLDocument('');
  other.adoptNode(img);
  const base = document.createElement('base');
  base.setAttribute('href', 'https://tracker.example/');
  document.head.appendChild(base);
  box.appendChild(img);
  eq(`${route}: not in the document until the runtime answers`, box.contains(img), false);
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
