/**
 * The inline consent logger folds granular decisions from the consent cookie
 * into the logged record. It is a JavaScript string built inside PHP
 * (frontend/class-frontend.php), so no other test ever executes it; this one
 * extracts the shipped snippet verbatim and runs it against real cookies.
 *
 * What it pins: svc.* and ck.* decisions are logged as they are; a GPC
 * exception marker (gpcx.<id>:1) is logged as the audit key
 * meta.gpc_exception.<id>:yes, because the log accepts only yes/no values and
 * a raw gpcx.<id>:1 would be silently dropped by the server sanitiser — the
 * record would then show a sale/share service granted under GPC with nothing
 * saying why.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const PHP = readFileSync(resolve(HERE, '../../../frontend/class-frontend.php'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

// The fold is the one PHP string literal that reads the consent cookie and
// writes svc.* keys into the categories map `c`.
const line = PHP.split('\n').find((l) => l.includes("ck.indexOf('svc.')===0") && l.trim().startsWith('"try{'));
if (!line) {
  console.log('  \x1b[31mFAIL\x1b[0m the consent-log fold snippet was not found in class-frontend.php');
  process.exit(1);
}
const snippet = line.trim().replace(/^"/, '').replace(/"\s*\.\s*$/, '');

function fold(cookieValue) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only', url: 'https://log.example.test/',
  });
  dom.window.document.cookie = `fazcookie-consent=${encodeURIComponent(cookieValue)}; path=/`;
  return dom.window.eval(`(function(){var c={};${snippet}return c;})()`);
}

console.log('consent-log fold (jsdom, snippet extracted from class-frontend.php)');

const c = fold('consentid:x,consent:yes,action:yes,necessary:yes,functional:no,gpc:1,svc.google-maps:yes,gpcx.google-maps:1,svc.ads:no,ck.ads._gcl:no');
check('svc.* grants are logged as they are', c['svc.google-maps'] === 'yes');
check('svc.* denials are logged as they are', c['svc.ads'] === 'no');
check('ck.* decisions are logged as they are', c['ck.ads._gcl'] === 'no');
check('a GPC exception is logged as meta.gpc_exception.<id>:yes', c['meta.gpc_exception.google-maps'] === 'yes');
check('the raw gpcx.* key is not logged (the server would drop its value)', !('gpcx.google-maps' in c));
check('category and control keys are not folded from the cookie', !('functional' in c) && !('gpc' in c) && !('consent' in c));

// A record a privacy signal created, with the banner still unanswered, must be
// distinguishable in the log from a visitor's partial save: both are stored
// with status "partial".
const sig = fold('consentid:x,consent:no,action:yes,undecided:1,gpc:1,necessary:yes,functional:no');
check('a signal-created record is logged as such', sig['meta.signal_only'] === 'yes');
check('and the raw undecided key is not logged', !('undecided' in sig));
const answered = fold('consentid:x,consent:no,action:yes,gpc:1,necessary:yes,functional:no');
check('a record the visitor answered carries no such key', !('meta.signal_only' in answered));

const d = fold('consent:yes,action:yes,gpcx.google-maps:0,svc.google-maps:yes');
check('a marker whose value is not 1 is not logged as an exception', !('meta.gpc_exception.google-maps' in d));

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
