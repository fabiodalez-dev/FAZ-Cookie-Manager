/**
 * JS unit test (jsdom) — restoring inline scripts after consent, without eval().
 *
 * The restore path used to run blocked inline payloads through indirect eval().
 * That works, and it is refused outright by any site serving a CSP without
 * 'unsafe-eval' — so on exactly the strictest sites, consented scripts never
 * ran. #228 replaced it with the correct mechanism: build a fresh <script> and
 * let the browser execute it on insertion.
 *
 * These tests pin the two things that mechanism has to get right and that no
 * existing test covered, both of which fail silently — a refused script and an
 * unrestored data block each report nothing to the page:
 *
 *   1. The CSP nonce has to be carried over through the IDL property. Once an
 *      element is connected, the browser CLEARS the nonce content attribute and
 *      keeps the value only on `.nonce`, deliberately, so a CSS attribute
 *      selector cannot read it. An attribute-copy loop therefore produces a
 *      clone with no nonce, blocked by the very policy this change exists to
 *      survive.
 *
 *   2. A JSON payload still has to be UNBLOCKED even though it is never
 *      executed. application/ld+json is structured data; left at the
 *      placeholder type it is gone from the DOM's point of view for good. The
 *      pre-#228 code restored the type before it ever inspected the payload, so
 *      this branch inherited the restoration for free — delegating the build
 *      moved it out of reach.
 *
 * Execution itself is not asserted here: jsdom runs with scripts disabled, so
 * what is checked is the DOM contract the browser then acts on. The E2E suite
 * covers the running.
 *
 * Point FAZ_SCRIPT_PATH at another copy of script.js to run these against it —
 * used to confirm they fail against the unfixed version rather than passing by
 * construction.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = process.env.FAZ_SCRIPT_PATH
  || resolve(HERE, '../../../frontend/js/script.js');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
  }
}

/** Load the real script.js with its DOMContentLoaded bootstrap neutralised. */
function loadFrontend() {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
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
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
  // Consent GRANTED: restoration only ever runs after the visitor has accepted,
  // and the live MutationObserver would otherwise re-block every script this
  // test inserts — rewriting the type back to the placeholder and making the
  // assertions measure the blocker instead of the restore path.
  window.fazcookie = { _fazGetFromStore: () => 'yes' };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  // window.eval is the harness loading the plugin's own source into the jsdom
  // realm — the same mechanism every other test in tests/unit/js/ uses. The
  // input is a file from this repository, not runtime data, and check 13 below
  // asserts that the shipped restore path contains no eval of its own.
  window.eval(code);
  window.document.addEventListener = realAdd;
  return window;
}

/**
 * A blocked <script> exactly as the server-side gate leaves it, held in a
 * DETACHED parent.
 *
 * Deliberately not attached to the document: the live MutationObserver rewrites
 * the type of anything inserted into the page back to the placeholder, so an
 * attached fixture would measure the blocker rather than the restore path.
 * A detached parent still gives replaceChild() something to replace, and keeps
 * each case isolated from the ones before it.
 *
 * @return {{script: Element, holder: Element}}
 */
function blocked(w, { originalType, text, nonce }) {
  const holder = w.document.createElement('div');
  const s = w.document.createElement('script');
  s.setAttribute('type', 'javascript/blocked');
  s.setAttribute('data-faz-original-type', originalType);
  s.setAttribute('data-faz-category', 'analytics');
  s.textContent = text;
  if (nonce) {
    s.nonce = nonce;
  }
  holder.appendChild(s);
  return { script: s, holder };
}

console.log('inline script restore after consent — CSP-safe, no eval (jsdom)');

const w = loadFrontend();

// ── 1-4. A JSON payload is data: unblocked, never executed. ────────────────
{
  const { script: s, holder } = blocked(w, {
    originalType: 'application/ld+json',
    text: '{"@context":"https://schema.org","@type":"Organization"}',
  });
  w._fazRestoreInlineScript(s, []);
  const live = holder.querySelector('script');

  check('json: the declared type comes back', live.getAttribute('type') === 'application/ld+json');
  check('json: the category marker is removed', live.getAttribute('data-faz-category') === null);
  check('json: the original-type marker is removed', live.getAttribute('data-faz-original-type') === null);
  // Not replaced: there is nothing to execute, so the node stays put and its
  // payload is untouched.
  check('json: the element is unblocked in place, payload intact', live === s && live.textContent.indexOf('schema.org') !== -1);
}

// ── 5-8. Executable code is rebuilt as a fresh node the browser will run. ──
{
  const { script: s, holder } = blocked(w, { originalType: 'text/javascript', text: 'window.__fazRan = 1;' });
  w._fazRestoreInlineScript(s, []);
  const live = holder.querySelector('script');

  check('code: the node is replaced by a fresh script', live !== s);
  check('code: the fresh script carries the runnable type', (live.getAttribute('type') || live.type) === 'text/javascript');
  check('code: the payload survives the rebuild', live.textContent === 'window.__fazRan = 1;');
  check('code: FAZ markers do not travel to the clone', live.getAttribute('data-faz-category') === null && live.getAttribute('data-faz-original-type') === null);
}

// ── 9. The CSP nonce must survive, or the strict sites this fixes stay broken.
{
  const { script: s, holder } = blocked(w, { originalType: 'text/javascript', text: 'window.__fazNonced = 1;', nonce: 'r4nd0m-nonce' });
  w._fazRestoreInlineScript(s, []);
  const live = holder.querySelector('script');
  check('nonce: carried to the replacement through the IDL property', live.nonce === 'r4nd0m-nonce');
}

// ── 10-11. Restoring twice must not run the payload twice. ────────────────
{
  const { script: s, holder } = blocked(w, { originalType: 'text/javascript', text: 'window.__fazTwice = (window.__fazTwice||0)+1;' });
  w._fazRestoreInlineScript(s, []);
  const first = holder.querySelector('script');
  check('guard: the restored script is marked executed', first.getAttribute('data-faz-executed') === '1');
  w._fazRestoreInlineScript(first, []);
  check('guard: a second restore is a no-op', holder.querySelectorAll('script').length === 1);
}

// ── 12. A detached node still gets restored rather than dropped. ──────────
{
  const s = w.document.createElement('script');
  s.setAttribute('type', 'text/plain');
  s.setAttribute('data-faz-original-type', 'text/javascript');
  s.textContent = 'window.__fazOrphan = 1;';
  w._fazRestoreInlineScript(s, []);
  const inHead = Array.from(w.document.head.querySelectorAll('script'))
    .some((el) => el.textContent === 'window.__fazOrphan = 1;');
  check('orphan: a script with no parent is appended rather than lost', inHead);
}

// ── 13. eval() is gone from the restore path, structurally. ───────────────
{
  const src = readFileSync(SCRIPT_PATH, 'utf8');
  const fn = src.slice(src.indexOf('function _fazRestoreInlineScript('));
  const body = fn.slice(0, fn.indexOf('\nfunction '));
  check('no indirect eval remains in the restore path', !/\(\s*0\s*,\s*eval\s*\)/.test(body));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
