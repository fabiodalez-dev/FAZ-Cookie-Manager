/**
 * JS unit test (jsdom) — the [faz_do_not_sell] page survives a full-page cache.
 *
 * The server now renders one document for every visitor: the opt-out form
 * live, and the "already opted out / withdraw" state inert inside a
 * <template>. Two things are therefore the client's job, and both are pinned
 * here against the REAL frontend/js/faz-dnsmpi.js:
 *
 *   - which state the visitor sees is decided from document.cookie — the one
 *     signal a shared cache never freezes — so a cached page primed by an
 *     opted-out visitor still shows everybody else the opt-out control, and
 *     an opted-out visitor on a page primed by anyone else still sees the
 *     withdraw control. The DOM holds exactly one state at any time;
 *   - a nonce baked into cached markup expires after 12 to 24 hours, and
 *     "refresh the page" then returns the same nonce. When the server answers
 *     with the stale_nonce code the handler fetches fresh nonces from the
 *     no-store endpoint and resends ONCE, so the visitor's click still lands.
 *
 * Run: node tests/unit/js/dnsmpi-form-cache-state.test.mjs
 */

import { JSDOM, VirtualConsole } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/faz-dnsmpi.js');
const SOURCE = readFileSync(SCRIPT_PATH, 'utf8');

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

/** The shortcode's markup exactly as includes/class-do-not-sell-shortcode.php renders it. */
function markup({ optoutNonce = 'baked-optout', rescindNonce = 'baked-rescind' } = {}) {
  return `
    <div class="faz-dnsmpi-wrap">
      <h3>Do Not Sell or Share My Personal Information</h3>
      <div class="faz-dnsmpi-state" data-faz-dnsmpi-state="optout">
        <p>As a US resident in a state with applicable privacy laws, you have the right to opt out.</p>
        <form class="faz-dnsmpi-form">
          <input type="hidden" name="action" value="faz_dnsmpi_optout">
          <input type="hidden" name="nonce"  value="${optoutNonce}">
          <button type="submit" class="faz-dnsmpi-btn">Submit Opt-Out Request</button>
        </form>
        <div class="faz-dnsmpi-notice" role="status" aria-live="polite" aria-atomic="true" style="display:none;"></div>
      </div>
      <template class="faz-dnsmpi-rescind-template">
        <div class="faz-dnsmpi-state" data-faz-dnsmpi-state="rescind">
          <div class="faz-dnsmpi-notice success">You have already submitted an opt-out request.</div>
          <p>Changed your mind? You can withdraw your opt-out at any time.</p>
          <form class="faz-dnsmpi-rescind-form">
            <input type="hidden" name="action" value="faz_dnsmpi_rescind">
            <input type="hidden" name="nonce"  value="${rescindNonce}">
            <button type="submit" class="faz-dnsmpi-btn faz-dnsmpi-rescind-btn">Withdraw opt-out / opt back in</button>
          </form>
          <div class="faz-dnsmpi-notice" role="status" aria-live="polite" aria-atomic="true" style="display:none;"></div>
        </div>
      </template>
    </div>`;
}

/**
 * A page as the browser sees it: the shortcode markup already in the body
 * (or an empty body, for the injected case), the cookie the visitor carries,
 * and a fetch() that answers as admin-ajax.php would.
 */
function mount({ body = markup(), cookie = '', fetchImpl = null } = {}) {
  // jsdom reports window.location.reload() as "not implemented"; the success
  // path schedules one, and that is not what is under test here.
  const virtualConsole = new VirtualConsole();
  virtualConsole.on('jsdomError', () => {});

  const dom = new JSDOM(`<!DOCTYPE html><html><body>${body}</body></html>`, {
    runScripts: 'outside-only',
    url: 'https://example.test/do-not-sell/',
    virtualConsole,
  });
  const { window } = dom;
  if (cookie) {
    window.document.cookie = cookie;
  }
  window.fazDnsmpiConfig = {
    ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
    rescindAction: 'faz_dnsmpi_rescind',
    nonceAction: 'faz_dnsmpi_nonce',
    cookieName: 'fazcookie-dnsmpi',
    successMsg: 'Your opt-out request has been received.',
    rescindSuccess: 'Your opt-out has been withdrawn.',
    errMsg: 'An error occurred. Please try again.',
    netMsg: 'Network error. Please try again.',
  };

  const calls = [];
  window.fetch = function (url, options) {
    const entry = { url: String(url), method: (options && options.method) || 'GET', body: {} };
    if (options && options.body && typeof options.body.entries === 'function') {
      for (const [k, v] of options.body.entries()) { entry.body[k] = v; }
    }
    calls.push(entry);
    const answer = fetchImpl ? fetchImpl(entry, calls.length) : { success: true, data: { message: 'ok' } };
    return Promise.resolve({ json: () => Promise.resolve(answer) });
  };

  window.eval(SOURCE);
  return { window, calls };
}

/** The handler defers to DOMContentLoaded while the document is loading. */
function ready(window) {
  return new Promise((r) => {
    if (window.document.readyState !== 'loading') { r(); return; }
    window.document.addEventListener('DOMContentLoaded', () => r());
  });
}

const tick = (ms = 0) => new Promise((r) => setTimeout(r, ms));

async function settle(window, predicate, limitMs = 1500) {
  const deadline = Date.now() + limitMs;
  while (Date.now() < deadline) {
    if (predicate()) return true;
    await tick(5);
  }
  return predicate();
}

// ── 1. State is chosen from the visitor's own cookie ─────────────────────────

console.log('— the visible state follows document.cookie, not the cached render');

{
  const { window } = mount();
  await ready(window);
  const doc = window.document;
  check('no cookie: the opt-out form is live', doc.querySelector('.faz-dnsmpi-form') !== null);
  check('no cookie: the rescind form is not in the DOM', doc.querySelector('.faz-dnsmpi-rescind-form') === null);
  check('no cookie: the standing "already opted out" notice is not in the DOM', doc.querySelector('.faz-dnsmpi-notice.success') === null);
  check('no cookie: the template is left in place, inert', doc.querySelector('template.faz-dnsmpi-rescind-template') !== null);
}

{
  // The page a cache primed with the opt-out state, served to a visitor who
  // has already opted out: the client must give them the withdraw control.
  const { window } = mount({ cookie: 'fazcookie-dnsmpi=1;path=/' });
  await ready(window);
  const doc = window.document;
  check('opt-out cookie: the rescind form is swapped into the DOM', doc.querySelector('.faz-dnsmpi-rescind-form') !== null);
  check('opt-out cookie: the opt-out form is gone', doc.querySelector('.faz-dnsmpi-form') === null);
  check('opt-out cookie: exactly one state is live', doc.querySelectorAll('.faz-dnsmpi-state').length === 1);
  const notice = doc.querySelector('.faz-dnsmpi-notice.success');
  check('opt-out cookie: the standing notice is shown', notice !== null && /already submitted/.test(notice.textContent));
  const nonce = doc.querySelector('.faz-dnsmpi-rescind-form input[name="nonce"]');
  check('opt-out cookie: the rescind form carries its own nonce', nonce !== null && nonce.value === 'baked-rescind');
  check('opt-out cookie: the swapped state keeps its label', doc.querySelector('.faz-dnsmpi-state').getAttribute('data-faz-dnsmpi-state') === 'rescind');
}

{
  // Any other cookie value, or another cookie with a similar name, is not an opt-out.
  const { window } = mount({ cookie: 'fazcookie-dnsmpi-other=1;path=/' });
  await ready(window);
  check('a look-alike cookie does not count as an opt-out', window.document.querySelector('.faz-dnsmpi-form') !== null);
}

{
  // The exact cookie name with a value other than 1: the server only honours '1'.
  const { window } = mount({ cookie: 'fazcookie-dnsmpi=0;path=/' });
  await ready(window);
  const doc = window.document;
  check('fazcookie-dnsmpi=0 does not count as an opt-out: the opt-out form stays live', doc.querySelector('.faz-dnsmpi-form') !== null);
  check('fazcookie-dnsmpi=0: the rescind form is not swapped in', doc.querySelector('.faz-dnsmpi-rescind-form') === null);
}

{
  // A page builder injecting the shortcode HTML after load.
  const { window } = mount({ body: '', cookie: 'fazcookie-dnsmpi=1;path=/' });
  await ready(window);
  const holder = window.document.createElement('div');
  holder.innerHTML = markup();
  window.document.body.appendChild(holder);
  const swapped = await settle(window, () => window.document.querySelector('.faz-dnsmpi-rescind-form') !== null);
  check('markup injected after load is reconciled with the cookie too', swapped);
  check('and the injected opt-out form is gone', window.document.querySelector('.faz-dnsmpi-form') === null);
}

// ── 2. A stale nonce is retried once with fresh ones ─────────────────────────

console.log('— a stale nonce from a cached page is refreshed and the click still lands');

{
  const { window, calls } = mount({
    fetchImpl(entry) {
      if (entry.method === 'GET' && entry.url.indexOf('action=faz_dnsmpi_nonce') !== -1) {
        return { success: true, data: { optout: 'fresh-optout', rescind: 'fresh-rescind' } };
      }
      if (entry.body.nonce === 'baked-optout') {
        return { success: false, data: { message: 'Invalid security token. Please refresh the page and try again.', code: 'stale_nonce' } };
      }
      if (entry.body.nonce === 'fresh-optout') {
        return { success: true, data: { message: 'Your opt-out request has been received.' } };
      }
      return { success: false, data: 'unexpected nonce ' + entry.body.nonce };
    },
  });
  await ready(window);
  const doc = window.document;
  const form = doc.querySelector('.faz-dnsmpi-form');
  form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  const done = await settle(window, () => calls.length >= 3 && /success/.test(doc.querySelector('.faz-dnsmpi-state .faz-dnsmpi-notice:not(.success), .faz-dnsmpi-state .faz-dnsmpi-notice').className));
  check('three requests: the stale post, the nonce refresh, the resend', done && calls.length === 3);
  check('the first post carried the baked nonce', calls[0] && calls[0].method === 'POST' && calls[0].body.nonce === 'baked-optout');
  check('the refresh is a GET on the nonce action, same origin', calls[1] && calls[1].method === 'GET' && calls[1].url.indexOf('admin-ajax.php?action=faz_dnsmpi_nonce') !== -1);
  check('the resend carries the fresh opt-out nonce', calls[2] && calls[2].method === 'POST' && calls[2].body.nonce === 'fresh-optout' && calls[2].body.action === 'faz_dnsmpi_optout');
  const notice = doc.querySelector('.faz-dnsmpi-notice');
  check('the visitor sees the success notice, not the stale-token error', notice && /success/.test(notice.className) && /received/.test(notice.textContent));
  check('the form is hidden as on any success', form.style.display === 'none');
  check('the form now holds the fresh nonce for good measure', form.querySelector('input[name="nonce"]').value === 'fresh-optout');
  check('the rescind template was refreshed too, for the state the reload may swap in',
    doc.querySelector('template.faz-dnsmpi-rescind-template').content.querySelector('input[name="nonce"]').value === 'fresh-rescind');
}

{
  // The rescind form, swapped in for an opted-out visitor, recovers the same way.
  const { window, calls } = mount({
    cookie: 'fazcookie-dnsmpi=1;path=/',
    fetchImpl(entry) {
      if (entry.method === 'GET') {
        return { success: true, data: { optout: 'fresh-optout', rescind: 'fresh-rescind' } };
      }
      if (entry.body.nonce === 'baked-rescind') {
        return { success: false, data: { message: 'Invalid security token.', code: 'stale_nonce' } };
      }
      return { success: true, data: { message: 'Your opt-out has been withdrawn.' } };
    },
  });
  await ready(window);
  const form = window.document.querySelector('.faz-dnsmpi-rescind-form');
  check('rescind: the form is live for the opted-out visitor', form !== null);
  if (form) {
    form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  }
  const done = await settle(window, () => calls.length >= 3);
  check('rescind: three requests as well', done && calls.length === 3);
  check('rescind: the resend carries the fresh RESCIND nonce, not the opt-out one', calls[2] && calls[2].body.nonce === 'fresh-rescind' && calls[2].body.action === 'faz_dnsmpi_rescind');
}

{
  // Only ONE retry: a server that keeps refusing must not be hammered, and the
  // visitor must get the message rather than a spinner.
  const { window, calls } = mount({
    fetchImpl(entry) {
      if (entry.method === 'GET') {
        return { success: true, data: { optout: 'fresh-optout', rescind: 'fresh-rescind' } };
      }
      return { success: false, data: { message: 'Invalid security token. Please refresh the page and try again.', code: 'stale_nonce' } };
    },
  });
  await ready(window);
  const doc = window.document;
  const form = doc.querySelector('.faz-dnsmpi-form');
  form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  await settle(window, () => calls.length >= 3 && doc.querySelector('.faz-dnsmpi-notice').style.display === 'block');
  await tick(50);
  check('a second refusal is not retried again', calls.length === 3);
  const notice = doc.querySelector('.faz-dnsmpi-notice');
  check('the visitor is shown the server message', notice && /error/.test(notice.className) && /Invalid security token/.test(notice.textContent));
  const btn = form.querySelector('button');
  check('and the button is usable again', btn.disabled === false && btn.getAttribute('aria-busy') === 'false');
}

{
  // Any other refusal (rate limit, same-origin, validation) is NOT a nonce
  // problem and must not trigger a refresh: one request, the message shown.
  const { window, calls } = mount({
    fetchImpl() {
      return { success: false, data: 'Too many requests. Please wait 1 minute before submitting again.' };
    },
  });
  await ready(window);
  const doc = window.document;
  const form = doc.querySelector('.faz-dnsmpi-form');
  form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  await settle(window, () => doc.querySelector('.faz-dnsmpi-notice').style.display === 'block');
  await tick(50);
  check('a non-nonce refusal is not retried', calls.length === 1);
  check('and its message is shown unchanged', /Too many requests/.test(doc.querySelector('.faz-dnsmpi-notice').textContent));
}

{
  // The refresh itself failing (network, cache in front of admin-ajax.php):
  // fall back to the original message, button restored.
  const { window, calls } = mount({
    fetchImpl(entry) {
      if (entry.method === 'GET') {
        return { success: false, data: 'nope' };
      }
      return { success: false, data: { message: 'Invalid security token. Please refresh the page and try again.', code: 'stale_nonce' } };
    },
  });
  await ready(window);
  const doc = window.document;
  const form = doc.querySelector('.faz-dnsmpi-form');
  form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  await settle(window, () => doc.querySelector('.faz-dnsmpi-notice').style.display === 'block');
  await tick(50);
  check('a failed refresh stops after the refresh', calls.length === 2);
  check('and the original message is shown', /Invalid security token/.test(doc.querySelector('.faz-dnsmpi-notice').textContent));
  check('with the button released', form.querySelector('button').disabled === false);
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
