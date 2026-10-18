/**
 * JS unit test (jsdom) — the DSAR form refreshes its nonce and stamp.
 *
 * [faz_dsar_form] bakes an anonymous nonce (12 to 24 h) and a signed stamp
 * (24 h) into the page. The server-side cache veto only reaches a form in a
 * singular post's content; a form in a widget, a builder or block template or
 * a synced pattern, or a page an edge cache keeps anyway, was served stale and
 * after a day every submission was refused — "reload the page" returned the
 * same copy. The script now fetches current values from the no-store refresh
 * action when the visitor starts on the form and writes them over the cached
 * ones before sending.
 *
 * Loads the REAL frontend/js/faz-dsar.js (the source; the minified build is
 * regenerated at release) and pins: one refresh per form, started by
 * engagement or by the submit; every signed field replaced together; the
 * honeypot renamed; a proof of work solved against the NEW challenge; the
 * dwell honoured before sending; fields the server no longer signs removed; a
 * failed refresh falling back to the markup's values and retried next time;
 * and no refresh at all when the config does not name the action.
 *
 * Run: node tests/unit/js/dsar-cache-refresh.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SOURCE = readFileSync(resolve(HERE, '../../../frontend/js/faz-dsar.js'), 'utf8');

let passed = 0;
let failed = 0;
function check(label, condition) {
  if (condition) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}
const sha256 = (s) => createHash('sha256').update(s, 'latin1').digest('hex');
const settle = async () => { for (let i = 0; i < 10; i++) await new Promise((r) => setTimeout(r, 0)); };

const STALE_HP = 'faz_hp_0123456789ab';
const FRESH = { nonce: 'fresh-nonce', t: 1760086400, sig: 'freshsig', hp: 'faz_hp_ffffeeeedddd', salt: 'Zz9Yy8Xx7Ww6Vv5U', diff: 1, wait: 0 };

/**
 * A form as the shortcode renders it, from a copy a cache kept for days:
 * every value is stale. `refresh` scripts the GET; POSTs are captured.
 */
function mount({ refresh, post = null, withAction = true, pow = true } = {}) {
  const challenge = pow
    ? `<input type="hidden" name="faz_pow_salt" value="oldsaltoldsalt00">
       <input type="hidden" name="faz_pow_diff" value="1">
       <input type="hidden" name="faz_pow_n" value="">`
    : '';
  const dom = new JSDOM(
    `<!DOCTYPE html><html><body>
      <div class="faz-dsar-wrap">
        <form class="faz-dsar-form" method="post" action="/wp-admin/admin-ajax.php" novalidate>
          <input type="hidden" name="action" value="faz_dsar_submit">
          <input type="hidden" name="nonce" value="stale-nonce">
          <input type="hidden" name="faz_dsar_t" value="1759800000">
          <input type="hidden" name="faz_dsar_s" value="stalesig">
          ${challenge}
          <div class="faz-dsar-honeypot" aria-hidden="true">
            <input type="text" name="${STALE_HP}" tabindex="-1" autocomplete="off" aria-hidden="true">
          </div>
          <input type="text" name="dsar_name" value="Jane Doe">
          <input type="email" name="dsar_email" value="jane@example.test">
          <select name="dsar_type"><option value="erasure" selected>Erasure</option></select>
          <button type="submit" class="faz-dsar-btn">Send</button>
        </form>
        <div class="faz-dsar-notice" style="display:none" role="status" tabindex="-1"></div>
      </div>
    </body></html>`,
    { runScripts: 'outside-only', url: 'https://example.test/any-page-with-a-widget/' }
  );
  const { window } = dom;
  window.fazDsarConfig = { ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php', ...(withAction ? { refreshAction: 'faz_dsar_refresh' } : {}) };
  const gets = [];
  const posts = [];
  let resolvePost;
  let sent = new Promise((r) => { resolvePost = r; });
  window.fetch = (url, options = {}) => {
    if (!options.method || options.method === 'GET') {
      gets.push({ url: String(url), cache: options.cache || '', at: Date.now() });
      return refresh(gets.length);
    }
    const captured = {};
    for (const [k, v] of options.body.entries()) { captured[k] = v; }
    captured.__at = Date.now();
    posts.push(captured);
    resolvePost();
    const reply = post ? post(captured, posts.length) : { success: true, data: { message: 'ok' } };
    return Promise.resolve({ json: () => Promise.resolve(reply) });
  };
  window.eval(SOURCE);
  const form = window.document.querySelector('.faz-dsar-form');
  return {
    window, form, gets, posts,
    submit() {
      sent = new Promise((r) => { resolvePost = r; });
      form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
      return sent;
    },
  };
}
const answer = (data) => () => Promise.resolve({ json: () => Promise.resolve({ success: true, data }) });

console.log('— a form a cache kept for days still submits current values');
{
  const m = mount({ refresh: answer(FRESH) });
  m.form.querySelector('[name="dsar_name"]').dispatchEvent(new m.window.FocusEvent('focusin', { bubbles: true }));
  m.form.querySelector('[name="dsar_email"]').dispatchEvent(new m.window.FocusEvent('focusin', { bubbles: true }));
  await settle();
  check('engaging with the form starts ONE refresh', m.gets.length === 1);
  check('from the refresh action, past every cache', m.gets[0] && m.gets[0].url.indexOf('action=faz_dsar_refresh') !== -1 && m.gets[0].cache === 'no-store');
  await m.submit();
  const p = m.posts[0] || {};
  check('the submit does not refresh a second time', m.gets.length === 1);
  check('the fresh nonce is posted', p.nonce === 'fresh-nonce');
  check('with the fresh stamp, time and signature together', p.faz_dsar_t === String(FRESH.t) && p.faz_dsar_s === 'freshsig');
  check('the honeypot carries the name derived from the new stamp', p[FRESH.hp] === '' && !(STALE_HP in p));
  check('the proof of work answers the NEW challenge', p.faz_pow_salt === FRESH.salt && /^[0-9]+$/.test(p.faz_pow_n || '') && sha256(FRESH.salt + p.faz_pow_n).startsWith('0'));
}

console.log('— a submit with no prior engagement refreshes first, then waits out the dwell');
{
  const m = mount({ refresh: answer({ ...FRESH, wait: 1 }) });
  await m.submit();
  const p = m.posts[0] || {};
  check('the refresh happened before the POST', m.gets.length === 1 && p.nonce === 'fresh-nonce');
  check(`and the POST waited the dwell the server counts from the new stamp (${p.__at - (m.gets[0] ? m.gets[0].at : 0)} ms)`, m.gets[0] && p.__at - m.gets[0].at >= 1000);
}

console.log('— fields the server no longer signs are removed, not left stale');
{
  const m = mount({ refresh: answer({ nonce: 'fresh-nonce', t: 1760086400, sig: '', hp: 'faz_hp_name', salt: '', diff: 0, wait: 0 }) });
  await m.submit();
  const p = m.posts[0] || {};
  check('no stamp is posted when the fresh one is unsigned', !('faz_dsar_t' in p) && !('faz_dsar_s' in p));
  check('and no challenge when the gate is now off', !('faz_pow_salt' in p) && !('faz_pow_diff' in p) && !('faz_pow_n' in p));
}

console.log('— a failed refresh changes nothing and is tried again');
{
  // The server answers as it does for a copy a cache kept past the nonce's
  // life: the stale values are refused, the form stays on screen and the
  // button comes back, and the visitor's next click must refresh again.
  let n = 0;
  const m = mount({
    refresh: () => { n += 1; return n === 1 ? Promise.reject(new Error('offline')) : answer(FRESH)(); },
    post: (p) => (p.nonce === 'fresh-nonce'
      ? { success: true, data: { message: 'ok' } }
      : { success: false, data: { message: 'Security check failed. Please refresh the page and try again.' } }),
  });
  const btn = m.form.querySelector('button');
  await m.submit();
  const first = m.posts[0] || {};
  check('the markup\'s own values are posted when the refresh fails', first.nonce === 'stale-nonce' && first.faz_dsar_s === 'stalesig' && STALE_HP in first);
  for (let i = 0; i < 50 && btn.disabled; i++) await settle();
  check('the refusal gives the visitor the button back', btn.disabled === false);
  check('and leaves the form on screen', m.form.style.display !== 'none');
  await m.submit();
  const second = m.posts[1] || {};
  check('the next submit tries the refresh again', m.gets.length === 2);
  check('and posts the fresh values', second.nonce === 'fresh-nonce' && second.faz_dsar_s === 'freshsig');
  await settle();
  check('which the server accepts: the form is replaced by the confirmation', m.form.style.display === 'none');
}

console.log('— without the refresh action the form behaves as before');
{
  const m = mount({ withAction: false, refresh: answer(FRESH) });
  m.form.querySelector('[name="dsar_name"]').dispatchEvent(new m.window.FocusEvent('focusin', { bubbles: true }));
  await m.submit();
  check('no refresh request is made', m.gets.length === 0);
  check('and the markup\'s values are posted', (m.posts[0] || {}).nonce === 'stale-nonce');
}

console.log('');
if (failed) {
  console.log(`FAILED: ${failed} of ${passed + failed}`);
  process.exit(1);
}
console.log(`ALL PASS: ${passed} assertions passed`);
process.exit(0);
