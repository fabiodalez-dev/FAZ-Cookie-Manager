/**
 * JS unit test (jsdom) — `_fazMatchingProviders()` memo (#309).
 *
 * On a real homepage the matcher ran ~1,000 times for 69 unique URLs (a review
 * widget re-assigned one star icon 665 times), and every call lowercased all
 * ~1,000 patterns again. It now memoises, per URL, WHICH entries match. These
 * checks pin the contract that makes the memo safe:
 *
 *   1. Same answer as the uncached matcher (case-insensitive, boundary-aware).
 *   2. A repeated URL does not re-scan the list.
 *   3. Appending a pattern (what _fazAddProviderToList does) invalidates it.
 *   4. Replacing the list invalidates it.
 *   5. A category rewritten in place on a matched entry still reaches callers
 *      (the memo holds references, never consent decisions).
 *   6. Callers get a copy: mutating a returned array cannot poison the memo.
 *   7. A long data: URI is matched but not kept as a key.
 *   8. Past 1000 distinct URLs the memo stays bounded and answers stay right.
 *
 * Run: node tests/unit/js/provider-match-memo.test.mjs
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
      { slug: 'analytics', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [
      { re: 'connect.facebook.net', categories: ['marketing'] },
      { re: 'Google-Analytics.com', categories: ['analytics'] },
      { re: 'cdn.trustindex.io', categories: ['marketing'] },
      { re: '', categories: ['marketing'] },
    ],
  };
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
const res = (u) => win.eval(`_fazMatchingProviders(${JSON.stringify(u)}).map(function (p) { return p.re; })`);

console.log('\n_fazMatchingProviders — same answers as before\n');
eq('matches a provider host', res('https://connect.facebook.net/en_US/fbevents.js'), ['connect.facebook.net']);
eq('pattern case is ignored', res('https://www.google-analytics.com/analytics.js'), ['Google-Analytics.com']);
eq('target case is ignored', res('HTTPS://CONNECT.FACEBOOK.NET/x.js'), ['connect.facebook.net']);
eq('a longer host label is not a match', res('https://connect.facebook.network/x.js'), []);
eq('a URL with no provider matches nothing', res('https://example.test/logo.png'), []);
eq('an empty pattern never matches', res('https://example.test/'), []);
eq('a non-string target matches nothing', win.eval('_fazMatchingProviders(null).length'), 0);

console.log('\nThe memo\n');
const star = 'https://cdn.trustindex.io/assets/platform/Google/star/f.svg';
win.eval(`
  window.__fazReads = 0;
  window.__fazList = window._fazConfig._providersToBlock;
  window.__fazSpied = new Proxy(window.__fazList, {
    get: function (t, k) { if (/^\\d+$/.test(String(k))) window.__fazReads++; return t[k]; }
  });
  window._fazConfig._providersToBlock = window.__fazSpied;
`);
res(star);
const readsAfterFirst = win.eval('window.__fazReads');
for (let i = 0; i < 50; i++) res(star);
eq('a repeated URL does not re-scan the pattern list', win.eval('window.__fazReads'), readsAfterFirst);
eq('the repeated URL still gets the same answer', res(star), ['cdn.trustindex.io']);
win.eval('window._fazConfig._providersToBlock = window.__fazList;');

eq('before the append, a new host is unmatched', res('https://tracker.example/p.js'), []);
win.eval(`window._fazConfig._providersToBlock.push({ re: 'tracker.example', categories: ['marketing'] });`);
eq('appending a pattern invalidates the memo', res('https://tracker.example/p.js'), ['tracker.example']);

win.eval(`window._fazConfig._providersToBlock = [{ re: 'other.example', categories: ['analytics'] }];`);
eq('replacing the list invalidates the memo', res('https://connect.facebook.net/x.js'), []);
eq('the replacement list is used', res('https://other.example/x.js'), ['other.example']);

win.eval(`window._fazConfig._providersToBlock = [{ re: 'cdn.example', categories: ['analytics'] }];`);
win.eval(`_fazMatchingProviders('https://cdn.example/a.js');`);
win.eval(`window._fazConfig._providersToBlock[0].categories = ['marketing'];`);
eq(
  'an in-place category change reaches the next caller',
  win.eval(`_fazMatchingProviders('https://cdn.example/a.js')[0].categories.join(',')`),
  'marketing',
);

win.eval(`_fazMatchingProviders('https://cdn.example/a.js').length = 0;`);
eq('mutating a returned array does not poison the memo', res('https://cdn.example/a.js'), ['cdn.example']);

const bigDataUri = 'data:text/javascript,' + encodeURIComponent('/*' + 'x'.repeat(4000) + '*/ fetch("https://cdn.example/b.js")');
eq('a long data: URI is still matched', res(bigDataUri), ['cdn.example']);
eq(
  'but it is not kept as a memo key',
  win.eval(`_fazMatchState.cache.has(${JSON.stringify(bigDataUri)})`),
  false,
);

console.log('\nThe memo cap\n');
// A page that keeps generating distinct URLs (cache-busting query strings,
// infinite scroll, ad rotations) must not grow the memo without bound. Past
// _FAZ_MATCH_CACHE_MAX_ENTRIES the memo is dropped and refilled, and answers
// keep coming from a real scan, so they stay correct across the reset.
eq('the cap is 1000 entries', win.eval('_FAZ_MATCH_CACHE_MAX_ENTRIES'), 1000);
win.eval(`window._fazConfig._providersToBlock = [
  { re: 'connect.facebook.net', categories: ['marketing'] },
  { re: 'google-analytics.com', categories: ['analytics'] },
];`);
const capResult = win.eval(`(function () {
  var maxSize = 0, wrong = [], total = 2600;
  for (var i = 0; i < total; i++) {
    var kind = i % 3;
    var url = kind === 0 ? 'https://connect.facebook.net/sdk.js?v=' + i
      : kind === 1 ? 'https://www.google-analytics.com/collect?cid=' + i
      : 'https://example.test/img-' + i + '.png';
    var expected = kind === 0 ? 'connect.facebook.net' : kind === 1 ? 'google-analytics.com' : '';
    var got = _fazMatchingProviders(url).map(function (p) { return p.re; }).join(',');
    if (got !== expected && wrong.length < 5) wrong.push(url + ' -> ' + got);
    if (_fazMatchState.cache.size > maxSize) maxSize = _fazMatchState.cache.size;
  }
  var early = _fazMatchingProviders('https://connect.facebook.net/sdk.js?v=0').map(function (p) { return p.re; });
  return { maxSize: maxSize, finalSize: _fazMatchState.cache.size, wrong: wrong, early: early, total: total };
})()`);
eq('more than 1000 distinct cacheable URLs were checked', capResult.total > 1000, true);
eq('the memo never holds more than 1000 entries', capResult.maxSize <= 1000, true);
eq('the memo actually filled up to the cap before resetting', capResult.maxSize, 1000);
eq('the memo is still bounded at the end', capResult.finalSize <= 1000, true);
eq('every answer stayed correct across the resets', capResult.wrong, []);
eq('a URL evicted by a reset still gets the right answer', capResult.early, ['connect.facebook.net']);

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed ? 1 : 0);
