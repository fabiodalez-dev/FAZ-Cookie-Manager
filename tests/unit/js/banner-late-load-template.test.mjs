/**
 * JS unit test (jsdom) — the two halves of the WPSpeed fix in script.js.
 *
 * `_fazDomReady()` used to call back synchronously when the document was
 * already parsed. A late script.js (defer, async, a combined bundle, a "delay
 * JS" replay) then ran the whole init in the middle of the file's evaluation,
 * before the `const` declarations further down existed, and the banner
 * rendered half-decorated ("Cannot access '_fazFocusLoopHandlers' before
 * initialization"). It now defers to a microtask in that case, and still
 * waits for DOMContentLoaded while the document is loading.
 *
 * The banner template is now a <template id="fazBannerTemplate"> (it was a
 * <script type="text/template"> with every `</` written as `<\/`, which a
 * translation proxy mangled). `_fazReadBannerTemplate()` reads either form —
 * the script form still arrives from pages cached before the update — and
 * `_fazWriteBannerTemplate()` stores the geo bootstrap's markup in the form
 * the element needs. The <template> must survive an HTML4 page rewrite
 * (DOMDocument::loadHTML, run here through the real php binary) as the same
 * DOM a browser builds from the original.
 *
 * Both are the real functions in frontend/js/script.js, loaded in jsdom with
 * the DOMContentLoaded bootstrap neutralised, as url-host-matching.test.mjs
 * does it.
 *
 * Run: node tests/unit/js/banner-late-load-template.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (JSON.stringify(actual) === JSON.stringify(expected)) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    console.log(`       expected: ${JSON.stringify(expected)}`);
    console.log(`       actual:   ${JSON.stringify(actual)}`);
  }
}

function loadFrontend() {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://example.test/a-page/',
  });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [],
  };
  // jsdom is still 'loading' while the file evaluates, so the bootstrap goes
  // through the DOMContentLoaded branch, which is dropped here.
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(code);
  window.document.addEventListener = realAdd;
  return window;
}

const win = loadFrontend();

let readyState = 'loading';
Object.defineProperty(win.document, 'readyState', { configurable: true, get: () => readyState });

const nextTimer = () => new Promise((r) => win.setTimeout(r, 0));

async function lateLoadOrder(state) {
  readyState = state;
  const order = [];
  win.setTimeout(() => order.push('timer'), 0);
  win.eval('_fazDomReady')(() => order.push('callback'));
  order.push('sync');
  const calledSynchronously = order.includes('callback');
  await nextTimer();
  await nextTimer();
  return { calledSynchronously, order };
}

console.log('\n_fazDomReady — document already parsed (late script.js)\n');

for (const state of ['interactive', 'complete']) {
  const r = await lateLoadOrder(state);
  eq(`readyState '${state}': not called synchronously`, r.calledSynchronously, false);
  eq(
    `readyState '${state}': called in a microtask, after the current evaluation and before any timer`,
    r.order,
    ['sync', 'callback', 'timer'],
  );
}

{
  readyState = 'complete';
  let calls = 0;
  win.eval('_fazDomReady')(() => { calls += 1; });
  await nextTimer();
  await nextTimer();
  eq('late load: called exactly once', calls, 1);
}

console.log('\n_fazDomReady — document still loading\n');

{
  readyState = 'loading';
  let calls = 0;
  win.eval('_fazDomReady')(() => { calls += 1; });
  await Promise.resolve();
  await nextTimer();
  eq('loading: not called before DOMContentLoaded, not even after a microtask and a timer', calls, 0);
  readyState = 'interactive';
  win.document.dispatchEvent(new win.Event('DOMContentLoaded'));
  eq('loading: called on DOMContentLoaded', calls, 1);
  await nextTimer();
  eq('loading: called once, not again from a microtask', calls, 1);
}

console.log('\n_fazReadBannerTemplate — undo the server-side `</` escape\n');

function readTemplate(text) {
  const tpl = win.document.createElement('script');
  tpl.type = 'text/template';
  tpl.id = 'fazBannerTemplate';
  tpl.textContent = text;
  return win.eval('_fazReadBannerTemplate')(tpl);
}

eq(
  'escaped closing tags come back as `</`',
  readTemplate('<div class="faz-consent-bar"><p class="faz-title">Cookies<\\/p><button>Accept<\\/button><\\/div>'),
  '<div class="faz-consent-bar"><p class="faz-title">Cookies</p><button>Accept</button></div>',
);
eq('a single escaped `<\\/div>` becomes `</div>`', readTemplate('<div><\\/div>'), '<div></div>');
const plain = '<div class="faz-consent-bar"><p>Cookies</p><a href="https://example.test/a/b">Policy</a></div>';
eq('unescaped content (geo bootstrap) is unchanged', readTemplate(plain), plain);
eq('a backslash-slash that does not follow `<` is left alone', readTemplate('<p>path a\\/b<\\/p>'), '<p>path a\\/b</p>');
eq('an empty template reads as an empty string', readTemplate(''), '');

console.log('\n#fazBannerTemplate as a <template> element\n');

const markup = '<div class="faz-consent-bar" id="faz-consent"><p class="faz-title">Cookies</p>'
  + '<button class="faz-btn faz-btn-accept">Accept</button><img src="https://example.test/i.svg" alt=""></div>';

function templateWith(html) {
  const tpl = win.document.createElement('template');
  tpl.id = 'fazBannerTemplate';
  tpl.innerHTML = html;
  win.document.body.appendChild(tpl);
  return tpl;
}

{
  const tpl = templateWith(markup);
  eq('reads back the markup it holds', win.eval('_fazReadBannerTemplate')(tpl), markup);
  eq('its content is not in the document (no duplicate #faz-consent)', win.document.getElementById('faz-consent'), null);
  tpl.remove();
}
{
  const tpl = templateWith('<p>a <code>&lt;\\/p&gt;</code></p>');
  eq('a literal `<\\/` in a <template> is not rewritten', win.eval('_fazReadBannerTemplate')(tpl), '<p>a <code>&lt;\\/p&gt;</code></p>');
  tpl.remove();
}
{
  const tpl = templateWith('<div>old</div>');
  win.eval('_fazWriteBannerTemplate')(tpl, markup);
  eq('the geo payload writer stores parsed markup, read back unchanged', win.eval('_fazReadBannerTemplate')(tpl), markup);
  eq('the writer leaves no text node in the template content', [...tpl.content.childNodes].some((n) => n.nodeType === 3), false);
  eq('the written content is still not in the document', win.document.getElementById('faz-consent'), null);
  tpl.remove();
}
{
  const legacy = win.document.createElement('script');
  legacy.type = 'text/template';
  win.eval('_fazWriteBannerTemplate')(legacy, markup);
  eq('the legacy script form keeps textContent', legacy.textContent, markup);
}

console.log('\nThe real templates after an HTML4 (libxml) page rewrite build the same DOM\n');

const shipped = JSON.parse(readFileSync(resolve(HERE, '../../../admin/modules/banners/includes/templates/6.2.0/template.json'), 'utf8'));
const PHP_ROUND_TRIP = `
$in = stream_get_contents( STDIN );
$page = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><template id="fazBannerTemplate">' . $in . '</template><p id="after">x</p></body></html>';
$d = new DOMDocument(); libxml_use_internal_errors( true );
$d->loadHTML( $page );
$out = $d->saveHTML( $d->documentElement );
echo preg_match( '#<template id="fazBannerTemplate">(.*)</template>#s', $out, $m ) ? $m[1] : '';
`;
function domOf(html) {
  const tpl = win.document.createElement('template');
  tpl.innerHTML = html;
  return tpl.innerHTML.replace(/\s+/g, ' ');
}
let php = true;
try { execFileSync('php', ['-v'], { stdio: 'ignore' }); } catch { php = false; }
if (!php) {
  console.log('  (php not available — DOMDocument round trip skipped)');
} else {
  for (const entry of shipped) {
    if (!entry || !entry.html) continue;
    const text = entry.html.replace(/\[faz_[a-z_]+\]/g, 'Příliš žluťoučký kůň «Café»');
    const after = execFileSync('php', ['-r', PHP_ROUND_TRIP], { input: text }).toString('utf8');
    eq(`${entry.type}: same DOM before and after the DOMDocument rewrite`, domOf(after) === domOf(text), true);
  }
}

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed ? 1 : 0);
