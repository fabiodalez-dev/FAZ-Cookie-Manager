/**
 * The consent log must carry the TC string of the decision it documents.
 *
 * The inline consent logger (a JavaScript string built in
 * frontend/class-frontend.php and printed 'before' the runtime) listens for
 * `fazcookie_consent_update` and posts the euconsent-v2 cookie as `tc_string`.
 * tcf-cmp.js registers its own listener on the same event when the deferred
 * bundle runs — later — and that listener is the only thing that writes (or
 * clears) euconsent-v2 after a decision. Listeners fire in registration order,
 * so a synchronous cookie read inside the logger saw the cookie as it stood
 * BEFORE the decision: the first Accept logged an empty string, and a Reject
 * that followed logged the Accept-All string while the cookie was being
 * cleared. GDPR Art. 5(2)/7(1): a withdrawal row whose TCF proof decodes to
 * every purpose granted is a self-contradictory record.
 *
 * This test runs the shipped snippet (extracted verbatim from the PHP) and the
 * real tcf-cmp.js in page order, and asserts that what is logged is what was
 * written for the same decision.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const CMP_CODE = readFileSync(resolve(HERE, '../../../frontend/js/tcf-cmp.js'), 'utf8');
const PHP = readFileSync(resolve(HERE, '../../../frontend/class-frontend.php'), 'utf8');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) { console.log(`  \x1b[32mPASS\x1b[0m ${label}`); passed++; }
  else { console.log(`  \x1b[31mFAIL\x1b[0m ${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`); failed++; }
}

/**
 * The logger is a concatenation of double-quoted PHP literals, one per line,
 * interleaved with `//` comment lines. Drop the comments, take the literal on
 * each remaining line, join. No literal in the block uses a PHP escape, so the
 * raw text is the JavaScript that ships.
 */
function extractInlineLogger(php) {
  const startMarker = '$inline_js = "document.addEventListener(\'fazcookie_consent_update\'';
  const start = php.indexOf(startMarker);
  if (start === -1) return null;
  const endMarker = '"});";';
  const end = php.indexOf(endMarker, start);
  if (end === -1) return null;
  const block = php.slice(start + '$inline_js = '.length, end + endMarker.length - 1);
  const parts = [];
  for (const rawLine of block.split('\n')) {
    const line = rawLine.trim();
    if (!line || line.startsWith('//')) continue;
    const m = line.match(/^"((?:[^"\\]|\\.)*)"/);
    if (!m) return null;
    parts.push(m[1]);
  }
  return parts.join('');
}

const LOGGER_CODE = extractInlineLogger(PHP);
if (!LOGGER_CODE) {
  console.log('  \x1b[31mFAIL\x1b[0m the inline consent-log listener was not found in class-frontend.php');
  process.exit(1);
}

/**
 * Boot a page in WordPress order: the logger at parse time, then the CMP.
 * fetch is replaced by a recorder; the returned `posted` array fills with the
 * decoded JSON bodies in the order they were sent.
 */
function boot() {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://log.example.test/page/',
  });
  const { window } = dom;
  const posted = [];
  window.fetch = (_url, opts) => {
    posted.push(JSON.parse(opts.body));
    return Promise.resolve({ ok: true });
  };
  window._fazConsentLog = {
    restUrl: 'https://log.example.test/wp-json/faz/v1/consent-log',
    token: 't',
    bannerSlug: 'default',
    policyRevision: 1,
    pageUrl: 'https://log.example.test/page/',
  };
  window._fazTcfConfig = {
    cmpId: 400, cmpVersion: 1, gvlVersion: 100,
    vendors: {}, selectedVendors: [], consentRevision: 1, purposeOneTreatment: false,
  };
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(LOGGER_CODE);
  window.eval(CMP_CODE);
  return { window, posted };
}

const ALL = ['necessary', 'functional', 'analytics', 'performance', 'marketing'];

function decide(window, action) {
  const yes = action === 'reject' ? [] : ALL;
  const no = action === 'reject' ? ALL.filter((c) => c !== 'necessary') : [];
  const pairs = ['consentid:abc', `consent:${action === 'reject' ? 'no' : 'yes'}`, 'action:yes', 'necessary:yes']
    .concat(ALL.filter((c) => c !== 'necessary').map((c) => `${c}:${yes.includes(c) ? 'yes' : 'no'}`));
  window.document.cookie = 'fazcookie-consent=' + encodeURIComponent(pairs.join(','));
  window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_update', {
    detail: { action, accepted: yes, rejected: no },
  }));
}

function euconsent(window) {
  const m = window.document.cookie.match(/euconsent-v2=([^;]+)/);
  return m ? m[1] : '';
}

/** Let every listener of the dispatch and any microtask it queued settle. */
const settle = () => new Promise((r) => setTimeout(r, 0));

function purposeBits(tcString) {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  const bits = [];
  for (const ch of tcString.split('.')[0]) {
    const v = chars.indexOf(ch);
    for (let b = 5; b >= 0; b--) bits.push((v >> b) & 1);
  }
  // PurposesConsent: 24 bits at offset 152 of the core segment.
  return bits.slice(152, 152 + 11).join('');
}

console.log('\nconsent log tc_string matches the TC string written for the same decision (jsdom, logger extracted from class-frontend.php)');

const { window, posted } = boot();

// Premise: the logger is registered first and the CMP's writer second, which
// is the page order the bug depends on. If the CMP wrote nothing, every
// assertion below would be comparing empty strings.
decide(window, 'all');
await settle();
const acceptCookie = euconsent(window);
eq('premise: Accept All made the CMP write a non-empty euconsent-v2 cookie', acceptCookie.length > 0, true);
eq('premise: one consent-log row was posted for Accept All', posted.length, 1);
eq('premise: the row records the decision as accepted', posted[0] && posted[0].status, 'accepted');
eq('Accept All: the logged tc_string is the TC string written for this decision', posted[0] && posted[0].tc_string, acceptCookie);
eq('Accept All: the logged TC string grants the purposes the visitor granted', posted[0] && purposeBits(posted[0].tc_string || 'A'), purposeBits(acceptCookie));

decide(window, 'reject');
await settle();
const rejectCookie = euconsent(window);
eq('premise: Reject All made the CMP clear euconsent-v2 (nothing left to persist)', rejectCookie, '');
eq('premise: a second row was posted for Reject All', posted.length, 2);
eq('premise: the row records the decision as rejected', posted[1] && posted[1].status, 'rejected');
eq('Reject All: the logged tc_string is what the cookie holds after this decision', posted[1] && posted[1].tc_string, rejectCookie);
eq('Reject All: the row does not carry the previous Accept-All string', posted[1] && posted[1].tc_string === acceptCookie, false);

// The rest of the row is unchanged by the ordering fix.
eq('consent_id is still read from the consent cookie', posted[1] && posted[1].consent_id, 'abc');
eq('categories still reflect the event detail', posted[1] && posted[1].categories && posted[1].categories.marketing, 'no');
eq('the page identity is still the baked one', posted[1] && posted[1].url, 'https://log.example.test/page/');

// An init event is not a decision and must not be logged at all.
window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_update', { detail: { action: 'init' } }));
await settle();
eq('an init event posts nothing', posted.length, 2);

console.log(`\nconsent-log-tc-string: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
