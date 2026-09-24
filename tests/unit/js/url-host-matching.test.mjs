/**
 * JS unit test (jsdom) — two URL decisions that were made on substrings.
 *
 * `_fazIsGcmManaged()` answers "Consent Mode governs this tag, so it may load
 * BEFORE consent". It compared with indexOf(), so any URL that merely contained
 * one of the Google ad domains answered yes: a tracker at
 * `https://tracker.example/?redirect=doubleclick.net`, or a look-alike host such
 * as `doubleclick.net.evil.example`. On a consent plugin that is the one wrong
 * answer that matters — a tracker running before the visitor has said anything,
 * with nothing on screen to show it.
 *
 * `_fazIsAllowedScheme()` gates every restored src/href against a scheme
 * allow-list. It took everything before the FIRST colon as the scheme, wherever
 * that colon sat, so a relative URL carrying one — `/img/a.jpg?v=12:30` — was
 * read as the scheme `/img/a.jpg?v=12` and refused: the resource stayed parked
 * after consent and nothing said why.
 *
 * Both are exercised as the real functions in frontend/js/script.js, loaded in
 * jsdom with the DOMContentLoaded bootstrap neutralised, exactly as
 * img-iframe-src-gate.test.mjs does it.
 *
 * Run: node tests/unit/js/url-host-matching.test.mjs
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
const managed = (u) => win.eval(`_fazIsGcmManaged(${JSON.stringify(u)})`);
const allowed = (u) => win.eval(`_fazIsAllowedScheme(${JSON.stringify(u)})`);

console.log('\n_fazIsGcmManaged — the host is the question, not the string\n');

// The real tags. These must keep loading before consent: that is the whole
// point of Advanced Consent Mode.
eq('gtag.js is managed', managed('https://www.googletagmanager.com/gtag/js?id=G-X'), true);
eq('googleadservices is managed', managed('https://www.googleadservices.com/pagead/conversion.js'), true);
eq('a doubleclick subdomain is managed', managed('https://stats.g.doubleclick.net/dc.js'), true);
eq('googlesyndication is managed', managed('https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js'), true);
eq('a protocol-relative URL is still read as a host', managed('//pagead2.googlesyndication.com/pagead/js/adsbygoogle.js'), true);

// Every one of these answered true before the fix.
eq('the name in a query string is not the host', managed('https://tracker.example/pixel.js?redirect=doubleclick.net'), false);
eq('a host that merely starts with it is not it', managed('https://doubleclick.net.evil.example/x.js'), false);
eq('a host that merely ends with it is not it', managed('https://notdoubleclick.net/x.js'), false);
eq('nor is it a path segment', managed('https://cdn.example/doubleclick.net/x.js'), false);
eq('nor an encoded parameter', managed('https://cdn.example/js?u=https%3A%2F%2Fgoogleadservices.com'), false);

// The GTM container stays blocked, which the path check is what preserves.
eq('the GTM container is still not managed', managed('https://www.googletagmanager.com/gtm.js?id=GTM-X'), false);
eq('an unrelated googletagmanager path is not managed', managed('https://www.googletagmanager.com/ns.html?id=GTM-X'), false);
eq('an empty URL is not managed', managed(''), false);

console.log('\n_fazIsAllowedScheme — a scheme is a scheme, not "everything before a colon"\n');

eq('https passes', allowed('https://example.test/a.js'), true);
eq('http passes', allowed('http://example.test/a.js'), true);
eq('a protocol-relative URL passes', allowed('//example.test/a.js'), true);
eq('a plain relative URL passes', allowed('/img/a.jpg'), true);

// The over-block this fixes: a colon in the query or the path is not a scheme.
eq('a colon in the query is not a scheme', allowed('/img/a.jpg?v=12:30'), true);
eq('a colon in the path is not a scheme', allowed('/img/12:30/a.jpg'), true);
eq('a colon in a bare filename is not a scheme', allowed('a:b.jpg'), false);

// And the sink stays closed. A browser strips whitespace and control characters
// before it reads the scheme, so the obfuscated forms have to be refused too.
eq('javascript: is refused', allowed('javascript:alert(1)'), false);
eq('mixed case is refused', allowed('JaVaScRiPt:alert(1)'), false);
eq('a tab inside the scheme is refused', allowed('java\tscript:alert(1)'), false);
eq('a newline inside the scheme is refused', allowed('java\nscript:alert(1)'), false);
eq('leading whitespace does not hide it', allowed('  javascript:alert(1)'), false);
eq('a NUL does not hide it', allowed('java\u0000script:alert(1)'), false);
eq('data: is refused', allowed('data:text/html,<script>alert(1)</script>'), false);
eq('vbscript: is refused', allowed('vbscript:msgbox(1)'), false);
eq('an empty value is refused', allowed(''), false);

console.log(`\nPassed: ${passed}; Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
