/**
 * JS unit test (jsdom) — parity guards for two pure per-service helpers in
 * frontend/js/script.js whose logic is duplicated on the PHP server side:
 *
 *   - _fazCkKey()              mirror of Frontend::ck_escape_cookie_name()
 *   - _fazHasProviderBoundary() mirror of Frontend::has_provider_boundary()
 *
 * Why parity matters: the boundary delimiter sets and the ck.* escaping order
 * MUST be byte-identical between PHP and JS, otherwise a cookie blocked on the
 * client is shredded under a different key on the server (or a provider matched
 * client-side is missed server-side). The PHP side is asserted in
 * tests/unit/test-per-service-gateway-boundary-php.php and
 * tests/unit/test-percookie-php.php; this file pins the JS side to the SAME
 * expected outputs so a one-sided edit fails loudly.
 *
 * The test loads the REAL shipped script.js (source of truth), neutralising only
 * its DOMContentLoaded auto-bootstrap so _fazInit() never runs. Both helpers are
 * pure (no store / DOM dependency) and hoist to global scope as Annex-B
 * block-scoped function declarations in the sloppy-mode classic script.
 *
 * Run: node tests/unit/js/per-service-boundary-ckkey.test.mjs   (npm run test:unit:js)
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

/** Load script.js in jsdom with its DOMContentLoaded bootstrap neutralised. */
function loadFrontend() {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  // Minimal config so script.js eval doesn't throw; these helpers don't use it.
  window._fazConfig = { _categories: [], _services: [], i18n: {} };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(code);
  window.document.addEventListener = realAdd;
  return window;
}

console.log('per-service ck.* escaping + provider boundary parity (jsdom)');

const w = loadFrontend();
const ckKey = (s, c) => w.eval(`_fazCkKey(${JSON.stringify(s)}, ${JSON.stringify(c)})`);
const boundary = (t, i, l) => w.eval(`_fazHasProviderBoundary(${JSON.stringify(t)}, ${i}, ${l})`);

// ---------------------------------------------------------------------------
// _fazCkKey() — must match Frontend::ck_escape_cookie_name() exactly.
// ---------------------------------------------------------------------------
console.log('\n_fazCkKey()');
eq('plain name unchanged', ckKey('youtube', 'YSC'), 'ck.youtube.YSC');
eq('colon -> %3A', ckKey('x', 'a:b'), 'ck.x.a%3Ab');
eq('comma -> %2C', ckKey('x', 'a,b'), 'ck.x.a%2Cb');
eq('percent -> %25', ckKey('x', 'a%b'), 'ck.x.a%25b');
// Percent must be escaped FIRST; a literal "%3A" must become "%253A", not "%3A".
eq('literal %3A double-escapes (percent first)', ckKey('x', '%3A'), 'ck.x.%253A');
// Dots are NOT escaped — parity with the PHP _pk_id.1 case in test-percookie-php.
eq('dotted cookie name keeps its dots', ckKey('matomo', '_pk_id.1'), 'ck.matomo._pk_id.1');

// ---------------------------------------------------------------------------
// _fazHasProviderBoundary(target, index, length) — mirror of PHP boundary set.
// Indices below are the real offsets of the pattern inside the target string.
// ---------------------------------------------------------------------------
console.log('\n_fazHasProviderBoundary()');
// "https://www.youtube.com/embed" — "youtube.com" at index 12, len 11.
// before '.', after '/'  → boundary present.
eq('real youtube embed: . before, / after → true', boundary('https://www.youtube.com/embed', 12, 11), true);
// "https://notyoutube.com/x" — "youtube.com" at index 11; char before is 't'.
eq('notyoutube.com: t before → false', boundary('https://notyoutube.com/x', 11, 11), false);
// "connect.facebook.net" — "facebook.net" at index 8, len 12; match ends at EOS.
eq('end-of-string after-guard skipped → true', boundary('connect.facebook.net', 8, 12), true);
// "xfacebook.net" — "facebook.net" at index 1; char before is 'x'.
eq('xfacebook.net: x before → false', boundary('xfacebook.net', 1, 12), false);
// "youtube.com/embed" — pattern at index 0; before-guard skipped, after '/'.
eq('index 0 before-guard skipped, / after → true', boundary('youtube.com/embed', 0, 11), true);
// "youtube-nocookie.com" — if "youtube.com" were (wrongly) located here, the
// char after would be a domain char; assert a non-boundary after rejects.
eq('non-boundary after char (letter) → false', boundary('ayoutube.comX', 1, 11), false);

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
