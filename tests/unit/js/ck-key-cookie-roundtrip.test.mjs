/**
 * A per-cookie decision on an unusual cookie name survives the consent cookie
 * and reaches the consent logger under the key the cookie holds.
 *
 * The consent log lost such decisions (a name with a space or a non-ASCII
 * letter). The fix is on the server: the controller percent-encodes the key
 * it stores (tests/unit/test-consentlog-ck-key-encoding-php.php). It was NOT
 * made in _fazCkKey(), and this suite pins why that function has to stay as
 * it is:
 *
 *   - the key it builds is the key already stored in visitors' browsers; a
 *     different escaping would no longer find a recorded refusal, and the
 *     cookie would fall back to its service or category — possibly "yes";
 *   - the server reads the same cookie through sanitize_text_field(), which
 *     removes percent-encoded octets, so every additional escape would be
 *     stripped before Frontend::resolve_service_cookie_decision() could match
 *     the key.
 *
 * So this is a guard on the format, not a test that failed before the fix: it
 * passes on both sides of it. It runs the shipped _fazCkKey() (extracted from
 * script.js), writes the key into a real cookie the way the runtime does,
 * parses it back the way the runtime does, and runs the shipped logger fold
 * (extracted from class-frontend.php) over it.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
const PHP = readFileSync(resolve(HERE, '../../../frontend/class-frontend.php'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

const fnMatch = SCRIPT.match(/function _fazCkKey\(serviceId, cookieName\) \{[\s\S]*?\n\}/);
const foldLine = PHP.split('\n').find((l) => l.includes("ck.indexOf('svc.')===0") && l.trim().startsWith('"try{'));
const readLine = PHP.split('\n').find((l) => l.trim().startsWith('"function readCookie'));
if (!fnMatch || !foldLine || !readLine) {
  console.log('  \x1b[31mFAIL\x1b[0m _fazCkKey() or the consent-log fold snippet was not found in the sources');
  process.exit(1);
}
const fold = foldLine.trim().replace(/^"/, '').replace(/"\s*\.\s*$/, '');
const readCookie = readLine.trim().slice(1, -3);

/** Store `<key>:no` in a real cookie, then read it back both ways. */
function roundTrip(serviceId, cookieName) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only', url: 'https://ck.example.test/',
  });
  const w = dom.window;
  w.eval(fnMatch[0]);
  const key = w._fazCkKey(serviceId, cookieName);
  // The runtime joins `key:value` pairs with commas and URI-encodes the value.
  const value = ['consentid:x', 'consent:yes', 'necessary:yes', `svc.${serviceId}:yes`, `${key}:no`, 'analytics:yes'].join(',');
  w.document.cookie = `fazcookie-consent=${encodeURIComponent(value)}; path=/`;
  // script.js: decode once, split on commas, first colon separates the value.
  const raw = decodeURIComponent(w.document.cookie.split('fazcookie-consent=')[1] || '');
  const parsed = {};
  raw.split(',').forEach((pair) => {
    const sep = pair.indexOf(':');
    if (sep > 0) parsed[pair.substring(0, sep)] = pair.substring(sep + 1);
  });
  const logged = w.eval(`(function(){var _fazConsentLog={};${readCookie}var c={};${fold}return c;})()`);
  return { key, parsed, logged };
}

console.log('per-cookie key: cookie round-trip and logger fold (jsdom)');

const names = [
  ['a plain name', '_ga'],
  ['a name with dots and a wildcard', '_pk_ses.*'],
  ['a name with a space', 'my cookie'],
  ['a non-ASCII name', 'sessión'],
  ['a name with a quote and a backslash', 'a"b\\c'],
  ['a name with a colon', 'a:b'],
  ['a name with a comma', 'a,b'],
  ['a name with a percent sign', '50%off'],
  ['a name that looks already escaped', 'a%3Ab'],
];

names.forEach(([label, name]) => {
  const r = roundTrip('acme', name);
  check(`${label}: the refusal is read back under the key _fazCkKey() builds`, r.parsed[r.key] === 'no');
  check(`${label}: the pairs around it are intact`, r.parsed.analytics === 'yes' && r.parsed['svc.acme'] === 'yes' && r.parsed.necessary === 'yes');
  check(`${label}: the logger posts the refusal under that same key`, r.logged[r.key] === 'no' && r.logged['svc.acme'] === 'yes');
});

// The stored format. A change here orphans the decisions already in browsers.
const dom = new JSDOM('', { runScripts: 'outside-only' });
dom.window.eval(fnMatch[0]);
const k = dom.window._fazCkKey;
check('only %, : and , are escaped in the name', k('acme', 'a%b:c,d') === 'ck.acme.a%25b%3Ac%2Cd');
check('a space is stored as it is (the server encodes it for the log)', k('acme', 'my cookie') === 'ck.acme.my cookie');
check('a non-ASCII letter is stored as it is', k('acme', 'sessión') === 'ck.acme.sessión');
check('distinct names never share a key', new Set(names.map(([, n]) => k('acme', n))).size === names.length);

console.log(`\nper-cookie key round-trip: ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
