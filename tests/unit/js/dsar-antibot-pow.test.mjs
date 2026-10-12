/**
 * JS unit test (jsdom) — the DSAR form's proof-of-work solver.
 *
 * Loads the REAL frontend/js/faz-dsar.js into a document holding a form as the
 * shortcode renders it, dispatches a submit, and inspects what the handler
 * posted. What is pinned:
 *
 *   - the hashing is actually SHA-256 (checked against node's crypto, because
 *     a hand-rolled hash that is subtly wrong would make every answer the
 *     server rejects look like a browser bug);
 *   - a challenge is solved and the answer satisfies the server's rule, which
 *     is `SHA-256(salt + n)` beginning with `difficulty` hex zeros;
 *   - the search is bounded by wall clock, and running out of budget sends the
 *     submission WITHOUT an answer rather than hanging the form — the server
 *     accepts that, and must, because this form exercises GDPR Art. 15-22;
 *   - a form with no challenge submits exactly as before.
 *
 * Run: node tests/unit/js/dsar-antibot-pow.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/faz-dsar.js');
const MIN_PATH = resolve(HERE, '../../../frontend/js/faz-dsar.min.js');
const SOURCE = readFileSync(SCRIPT_PATH, 'utf8');
const MINIFIED = readFileSync(MIN_PATH, 'utf8');

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

function sha256(str) {
  return createHash('sha256').update(str, 'latin1').digest('hex');
}

/**
 * A document holding the form exactly as includes/class-dsar-shortcode.php
 * renders it, with the challenge fields present only when asked for.
 */
function mount({ salt = '', difficulty = 0, source = SOURCE } = {}) {
  const challenge = salt
    ? `<input type="hidden" name="faz_pow_salt" value="${salt}">
       <input type="hidden" name="faz_pow_diff" value="${difficulty}">
       <input type="hidden" name="faz_pow_n" value="">`
    : '';

  const dom = new JSDOM(
    `<!DOCTYPE html><html><body>
      <div class="faz-dsar-wrap" id="faz-dsar-1">
        <form class="faz-dsar-form" method="post" action="/wp-admin/admin-ajax.php" novalidate>
          <input type="hidden" name="action" value="faz_dsar_submit">
          <input type="hidden" name="nonce" value="abc123">
          <input type="hidden" name="faz_dsar_t" value="1760000000">
          <input type="hidden" name="faz_dsar_s" value="deadbeef">
          ${challenge}
          <div class="faz-dsar-honeypot" aria-hidden="true">
            <input type="text" name="faz_hp_0123456789ab" tabindex="-1" autocomplete="off" aria-hidden="true">
          </div>
          <input type="text" name="dsar_name" value="Jane Doe">
          <input type="email" name="dsar_email" value="jane@example.test">
          <select name="dsar_type"><option value="erasure" selected>Erasure</option></select>
          <button type="submit" class="faz-dsar-btn">Send</button>
        </form>
        <div class="faz-dsar-notice" style="display:none" role="status" tabindex="-1"></div>
      </div>
    </body></html>`,
    { runScripts: 'outside-only', url: 'https://example.test/data-request/' }
  );

  const { window } = dom;
  window.fazDsarConfig = { ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php' };

  let captured = null;
  let resolveSent;
  const sent = new Promise((r) => { resolveSent = r; });
  window.fetch = function (url, options) {
    const body = options.body;
    captured = {};
    for (const [k, v] of body.entries()) { captured[k] = v; }
    resolveSent();
    return Promise.resolve({ json: () => Promise.resolve({ success: true, data: { message: 'ok' } }) });
  };

  window.eval(source);

  const form = window.document.querySelector('.faz-dsar-form');
  return {
    window,
    form,
    submit() {
      const t0 = Date.now();
      form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
      return sent.then(() => ({ posted: captured, ms: Date.now() - t0 }));
    },
  };
}

// ── 1. The hash the whole gate rests on ──────────────────────────────────────
//
// The solver is not exported, so it is exercised through the only door the
// shipped file has: a challenge whose answer is known in advance. Difficulty 1
// is satisfied by one hash in 16, so the first n the solver returns is
// verifiable against node's crypto — if the implementation were not SHA-256,
// no returned n would ever check out.

console.log('— the solver answers a challenge, and the answer is real SHA-256');

{
  const salt = 'aQ7bR2cS9dT4eU1f';
  const { posted } = await mount({ salt, difficulty: 1 }).submit();
  check('a challenged form posts an answer', typeof posted.faz_pow_n === 'string' && posted.faz_pow_n !== '');
  check('the answer is a plain decimal integer', /^[0-9]{1,20}$/.test(posted.faz_pow_n));
  check(
    'and SHA-256(salt + n) really begins with the required hex zeros',
    sha256(salt + posted.faz_pow_n).startsWith('0')
  );
  check('the challenge it answers is the one the server signed', posted.faz_pow_salt === salt);
  check('the stamp travels back untouched', posted.faz_dsar_t === '1760000000' && posted.faz_dsar_s === 'deadbeef');
  check('the honeypot is posted, empty, as every browser does', posted.faz_hp_0123456789ab === '');
}

// Independent check of the hash across inputs of different block lengths —
// the padding is where a hand-rolled SHA-256 goes wrong, and it goes wrong
// silently: every answer would be rejected by the server and the form would
// look broken for reasons no log would explain.
{
  const salt = 'bX3cY8dZ1eA6fB2g';
  const { posted } = await mount({ salt: salt.repeat(4), difficulty: 1 }).submit();
  check(
    'an input spanning more than one 64-byte block hashes correctly too',
    sha256(salt.repeat(4) + posted.faz_pow_n).startsWith('0')
  );
}

// ── 2. The shipped difficulty, end to end ────────────────────────────────────

console.log('— the shipped difficulty of 4 costs a fraction of a second');

{
  const times = [];
  for (let i = 0; i < 5; i++) {
    const salt = 'cZ4dA9eB2fC7gD3h'.slice(0, 15) + i;
    const { posted, ms } = await mount({ salt, difficulty: 4 }).submit();
    check(
      `run ${i + 1}: the answer has four leading hex zeros (${ms} ms)`,
      sha256(salt + posted.faz_pow_n).startsWith('0000')
    );
    times.push(ms);
  }
  const mean = Math.round(times.reduce((a, b) => a + b, 0) / times.length);
  console.log(`    mean ${mean} ms over ${times.length} runs (worst ${Math.max(...times)} ms)`);
  check('every solve stayed inside the 2000 ms client budget', Math.max(...times) < 2000);
}

// ── 3. Fail-open is the behaviour, not the fallback ──────────────────────────

console.log('— when the solver cannot win, the submission still goes out');

{
  // Difficulty 6 is ~16 million hashes: the budget runs out first, by design.
  // The submission must still be sent, with an empty answer, because the
  // server accepts one — a form that exercises a legal right may not freeze
  // and may not silently drop a request.
  const { posted, ms } = await mount({ salt: 'dA5eB1fC6gD2hE8i', difficulty: 6 }).submit();
  check('the search gives up rather than grinding on', ms < 4000);
  check('the submission is sent anyway', posted.action === 'faz_dsar_submit');
  check('with an empty answer, which the server accepts', posted.faz_pow_n === '');
  console.log(`    gave up after ${ms} ms and submitted without an answer`);
}

{
  // No challenge at all: the pre-existing behaviour, unchanged.
  const { posted } = await mount().submit();
  check('an unchallenged form submits as before', posted.dsar_email === 'jane@example.test');
  check('and posts no answer field', !('faz_pow_n' in posted));
}

// ── 4. The user-visible flow is untouched ────────────────────────────────────

console.log('— validation and the submit button still behave');

{
  const mounted = mount({ salt: 'eB6fC2gD7hE3iF9j', difficulty: 2 });
  const btn = mounted.form.querySelector('button');
  mounted.form.querySelector('[name="dsar_email"]').value = '';
  mounted.form.dispatchEvent(new mounted.window.Event('submit', { bubbles: true, cancelable: true }));
  check('a missing required field is still caught before any hashing', btn.disabled === false);
  check('and the field is marked invalid', mounted.form.querySelector('[name="dsar_email"]').getAttribute('aria-invalid') === 'true');

  mounted.form.querySelector('[name="dsar_email"]').value = 'jane@example.test';
  const { posted } = await mounted.submit();
  check('correcting it submits, challenge solved', /^[0-9]+$/.test(posted.faz_pow_n));
  check('the button was disabled for the duration', btn.disabled === true);
}

// ── 5. The build the frontend actually serves ────────────────────────────────
//
// class-frontend.php / faz_asset_suffix() serve faz-dsar.min.js wherever it
// exists, so a stale or mangled minified build would mean every visitor's
// answer is rejected while the source file tests clean.

console.log('— the minified build solves the same challenge');

{
  const salt = 'fC7gD3hE9iF5jG1k';
  const { posted, ms } = await mount({ salt, difficulty: 4, source: MINIFIED }).submit();
  check('the minified handler posts an answer', /^[0-9]{1,20}$/.test(posted.faz_pow_n));
  check(
    `and it satisfies the server's rule (${ms} ms)`,
    sha256(salt + posted.faz_pow_n).startsWith('0000')
  );
  const { posted: plain } = await mount({ source: MINIFIED }).submit();
  check('an unchallenged form still submits from the minified build', plain.dsar_email === 'jane@example.test');
}

console.log('');
if (failed) {
  console.log(`FAILED: ${failed} of ${passed + failed}`);
  process.exit(1);
}
console.log(`ALL PASS: ${passed} assertions passed`);
process.exit(0);
