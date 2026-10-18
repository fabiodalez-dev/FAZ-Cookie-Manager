/**
 * JS unit test (jsdom) — a parking type is never recorded as a script's
 * original type.
 *
 * The createElement guard snapshots a script's type into
 * data-faz-original-type the first time it blocks it, so the restore can give
 * the author's type back (type="module" must come back as a module). It
 * excluded only its own javascript/blocked. A script whose type was text/plain
 * at that moment — the form the server and the bootstrap park a script in, and
 * the documented way to mark one for opt-in — had text/plain recorded, the
 * restore wrote text/plain back, and the restored clone was inert: consent
 * given, script never run. The mutation observer and the bootstrap handoff
 * already exclude text/plain for exactly this reason.
 *
 * Run: node tests/unit/js/script-parking-type-not-original.test.mjs
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

function loadFrontend() {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: 'https://site.test/' });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'analytics', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    i18n: {},
  };
  Object.defineProperty(window.document, 'readyState', { get: () => 'loading', configurable: true });
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  window.setTimeout = () => 0;
  window.console.warn = () => {};
  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.addEventListener = realAdd;
  return window;
}

/** The visitor consents to analytics, then the blocked script is rebuilt. */
function restore(w, s) {
  w.fazcookie._fazSetInStore('analytics', 'yes');
  return w._fazBuildRestoredScript(s).type;
}

console.log('createElement guard: parking types are not original types');

// 1. text/plain set first, category tag second (setAttribute path).
{
  const w = loadFrontend();
  const s = w.document.createElement('script');
  s.setAttribute('type', 'text/plain');
  s.setAttribute('data-fazcookie', 'fazcookie-analytics');
  eq('premise: the tagged script is blocked (analytics not consented)', s.getAttribute('type'), 'javascript/blocked');
  eq('text/plain is not recorded as the original type', s.getAttribute('data-faz-original-type'), null);
  eq('the restore produces a runnable script', restore(w, s), 'text/javascript');
}

// 2. Category tag first, then type = text/plain through the property setter.
{
  const w = loadFrontend();
  const s = w.document.createElement('script');
  s.setAttribute('data-fazcookie', 'fazcookie-analytics');
  s.type = 'text/plain';
  eq('premise: blocked', s.getAttribute('type'), 'javascript/blocked');
  eq('text/plain assigned to a blocked script is not recorded either', s.getAttribute('data-faz-original-type'), null);
  eq('and restores runnable', restore(w, s), 'text/javascript');
}

// 3. Case and spacing do not smuggle it in.
{
  const w = loadFrontend();
  const s = w.document.createElement('script');
  s.setAttribute('type', ' Text/Plain ');
  s.setAttribute('data-fazcookie', 'fazcookie-analytics');
  // Without the block the attribute is never written, and the check below
  // would pass for the wrong reason.
  eq('premise: blocked', s.getAttribute('type'), 'javascript/blocked');
  eq('" Text/Plain " is a parking type too', s.getAttribute('data-faz-original-type'), null);
  eq('and restores runnable', restore(w, s), 'text/javascript');
}

// 4. A real author type is still remembered and given back.
{
  const w = loadFrontend();
  const s = w.document.createElement('script');
  s.setAttribute('type', 'module');
  s.setAttribute('data-fazcookie', 'fazcookie-analytics');
  eq('type="module" is recorded as the original', s.getAttribute('data-faz-original-type'), 'module');
  eq('and restored as a module', restore(w, s), 'module');
}

console.log(`\nscript-parking-type-not-original: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
