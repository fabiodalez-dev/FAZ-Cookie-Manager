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
 * `_fazReadBannerTemplate()` undoes the server-side `</` → `<\/` escape of the
 * banner template (Frontend::escape_template_end_tags()), which keeps an HTML4
 * page rewrite (DOMDocument::loadHTML) from cutting the template at its first
 * closing tag. Unescaped content — what the geo bootstrap writes client-side —
 * must come back unchanged.
 *
 * Both are the real functions in frontend/js/script.js, loaded in jsdom with
 * the DOMContentLoaded bootstrap neutralised, as url-host-matching.test.mjs
 * does it.
 *
 * Run: node tests/unit/js/banner-late-load-template.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
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

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed ? 1 : 0);
