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
 *      exempting the move would run it with no verdict.
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

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
