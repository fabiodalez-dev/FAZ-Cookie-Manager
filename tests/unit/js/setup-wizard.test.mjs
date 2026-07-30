/**
 * Guided setup wizard (jsdom) — 33 behavioural regression checks.
 *
 * Loads the real admin/assets/js/pages/setup.js and exercises navigation,
 * review rendering, the exact onboarding payload, duplicate-submit protection,
 * and every quick-scan outcome without a WordPress/browser dependency.
 *
 * Run: node tests/unit/js/setup-wizard.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../admin/assets/js/pages/setup.js'), 'utf8');

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

function markup() {
  const progress = [1, 2, 3, 4, 5, 6, 7, 8]
    .map((n) => `<li class="faz-wizard-progress-item${n === 1 ? ' is-active' : ''}" data-progress="${n}"></li>`)
    .join('');
  return `<!doctype html><html><body>
    <div id="faz-setup" data-dashboard-url="https://example.test/untrusted">
      <ol>${progress}</ol>
      <section class="faz-wizard-step is-active" data-step="1">
        <label class="faz-setup-law-card is-selected">
          <input type="radio" name="faz-setup-law" value="gdpr" data-expiry="180 days" data-buttons="Equal Accept and Reject" checked>
          <span class="faz-setup-law-title">GDPR</span>
          <span class="faz-setup-law-effect">Opt-in</span>
        </label>
        <label class="faz-setup-law-card">
          <input type="radio" name="faz-setup-law" value="ccpa" data-expiry="365 days" data-buttons="Do Not Sell instead of GDPR buttons">
          <span class="faz-setup-law-title">CCPA</span>
          <span class="faz-setup-law-effect">Opt-out</span>
        </label>
        <label class="faz-setup-law-card">
          <input type="radio" name="faz-setup-law" value="popia" data-expiry="180 days" data-buttons="Equal Accept and Reject">
          <span class="faz-setup-law-title">POPIA</span>
          <span class="faz-setup-law-effect">Opt-in</span>
        </label>
        <label class="faz-setup-law-card">
          <input type="radio" name="faz-setup-law" value="both" data-expiry="180 days" data-buttons="Equal buttons plus US opt-out">
          <span class="faz-setup-law-title">Both</span>
          <span class="faz-setup-law-effect">Opt-in plus Do Not Sell</span>
        </label>
      </section>
      <section class="faz-wizard-step" data-step="2" hidden>
        <select id="faz-setup-lang"><option value="en" selected>English</option><option value="it">Italian</option></select>
      </section>
      <section class="faz-wizard-step" data-step="3" hidden>
        <label class="faz-setup-toggle-row">
          <input type="checkbox" id="faz-setup-bc-per_service_consent" data-bc-key="per_service_consent">
          <span class="faz-setup-toggle-body"><span class="faz-setup-toggle-label">Per-service toggles</span></span>
        </label>
      </section>
      <section class="faz-wizard-step" data-step="4" hidden>
        <label class="faz-setup-toggle-row"><input type="checkbox" id="faz-setup-gcm"><span class="faz-setup-toggle-body"><span class="faz-setup-toggle-label">GCM v2</span></span></label>
        <label class="faz-setup-toggle-row"><input type="checkbox" id="faz-setup-ms-uet"><span class="faz-setup-toggle-body"><span class="faz-setup-toggle-label">UET</span></span></label>
        <label class="faz-setup-toggle-row"><input type="checkbox" id="faz-setup-ms-clarity"><span class="faz-setup-toggle-body"><span class="faz-setup-toggle-label">Clarity</span></span></label>
      </section>
      <section class="faz-wizard-step" data-step="5" hidden>
        <label class="faz-setup-toggle-row"><input type="checkbox" id="faz-setup-tcf"><span class="faz-setup-toggle-body"><span class="faz-setup-toggle-label">TCF</span></span></label>
        <input type="number" id="faz-setup-tcf-cmpid">
        <input type="text" id="faz-setup-tcf-cc">
        <p id="faz-setup-tcf-error" hidden>CMP ID required</p>
      </section>
      <section class="faz-wizard-step" data-step="6" hidden>
        <input type="checkbox" id="faz-setup-geo">
        <label class="faz-setup-region-chip"><input type="checkbox" name="faz-setup-geo-region" value="eu" checked>EU</label>
        <label class="faz-setup-region-chip"><input type="checkbox" name="faz-setup-geo-region" value="uk" checked>UK</label>
        <label class="faz-setup-region-chip"><input type="checkbox" name="faz-setup-geo-region" value="us">US</label>
        <label class="faz-setup-region-chip"><input type="checkbox" name="faz-setup-geo-region" value="za">South Africa</label>
        <select id="faz-setup-geo-behavior"><option value="show_banner" selected>Show</option><option value="no_banner">Hide</option></select>
      </section>
      <section class="faz-wizard-step" data-step="7" hidden>
        <button type="button" id="faz-setup-scan-btn">Scan</button>
        <div id="faz-setup-scan-progress" hidden><div class="faz-setup-scan-bar"></div></div>
        <p id="faz-setup-scan-status"></p>
        <div id="faz-setup-payments" hidden><div id="faz-setup-payments-list"></div></div>
      </section>
      <section class="faz-wizard-step" data-step="8" hidden>
        <ul id="faz-setup-review"
          data-label-law="Law"
          data-label-effect="Model"
          data-label-expiry="Expiry"
          data-label-language="Language"
          data-label-options="Options"
          data-label-geo="Geo"
          data-label-payments="Payments"
          data-logging="Logging on"></ul>
      </section>
      <button type="button" id="faz-setup-back" hidden>Back</button>
      <button type="button" id="faz-setup-next">Next</button>
      <button type="button" id="faz-setup-finish" hidden>Finish</button>
    </div>
  </body></html>`;
}

/** Click Next `times` times (the wizard has 8 steps; 7 clicks reach the review). */
function clickNext(document, times) {
  for (let i = 0; i < times; i++) {
    document.getElementById('faz-setup-next').click();
  }
}

function boot({ post, get, scanRun } = {}) {
  const dom = new JSDOM(markup(), {
    runScripts: 'outside-only',
    url: 'https://example.test/wp-admin/admin.php?page=faz-cookie-manager-setup',
  });
  const { window } = dom;
  const calls = { post: [], get: [], notify: [] };
  const timers = [];
  let nextTimer = 1;

  window.setTimeout = (callback, delay = 0) => {
    const timer = { id: nextTimer++, callback, delay, active: true };
    timers.push(timer);
    return timer.id;
  };
  window.clearTimeout = (id) => {
    const timer = timers.find((item) => item.id === id);
    if (timer) timer.active = false;
  };
  window.fazConfig = { i18n: {} };
  window.FAZ = {
    ready(callback) { callback(); },
    post(endpoint, payload) {
      calls.post.push({ endpoint, payload });
      return post ? post(endpoint, payload, calls) : Promise.resolve({});
    },
    get(endpoint) {
      calls.get.push(endpoint);
      return get ? get(endpoint, calls) : Promise.resolve({});
    },
    notify(message, type) { calls.notify.push({ message, type }); },
  };
  if (scanRun) {
    window.FAZ.scanEngine = { run: scanRun };
  }
  window.eval(SCRIPT);

  return {
    window,
    document: window.document,
    calls,
    timers,
    runTimer(delay) {
      const timer = timers.find((item) => item.active && item.delay === delay);
      if (!timer) return false;
      timer.active = false;
      timer.callback();
      return true;
    },
  };
}

async function flush() {
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
}

console.log('guided setup wizard (33 checks)');

// Navigation, selection, and review rendering (14 checks).
{
  const app = boot();
  const { document } = app;
  const step = (number) => document.querySelector(`.faz-wizard-step[data-step="${number}"]`);
  const progress = (number) => document.querySelector(`.faz-wizard-progress-item[data-progress="${number}"]`);

  check('01 default jurisdiction is GDPR', document.querySelector('input[value="gdpr"]').checked);
  check('02 wizard starts on step 1', !step(1).hidden && step(2).hidden && step(3).hidden);
  check('03 Back is hidden on the first step', document.getElementById('faz-setup-back').hidden);

  document.getElementById('faz-setup-next').click();
  check('04 Next advances to the optional scan step', !step(2).hidden && step(1).hidden);
  check('05 completed progress item is marked done', progress(1).classList.contains('is-done'));
  check('06 Back becomes available after advancing', !document.getElementById('faz-setup-back').hidden);
  document.getElementById('faz-setup-back').click();
  check('07 Back returns to the jurisdiction step', !step(1).hidden && step(2).hidden);

  const both = document.querySelector('input[value="both"]');
  both.checked = true;
  both.dispatchEvent(new app.window.Event('change', { bubbles: true }));
  check('08 selecting Both updates the selected card', both.closest('.faz-setup-law-card').classList.contains('is-selected'));

  // Malicious-looking translated text must remain text in the review.
  both.closest('.faz-setup-law-card').querySelector('.faz-setup-law-effect').textContent = '<img src=x onerror=alert(1)>';
  clickNext(document, 7);
  const review = document.getElementById('faz-setup-review');
  check('09 seven Next clicks reach the review step (8-step wizard)', !step(8).hidden);
  check('10 Next is hidden on the final step', document.getElementById('faz-setup-next').hidden);
  check('11 Finish is visible on the final step', !document.getElementById('faz-setup-finish').hidden);
  check('12 review contains the six promised configuration rows', review.children.length === 6);
  check('13 review reflects the selected law, expiry, and exact controls', review.textContent.includes('Both') && review.textContent.includes('180 days') && review.textContent.includes('Equal buttons plus US opt-out'));
  check('14 review text is never reinterpreted as HTML', review.querySelector('img') === null && review.textContent.includes('<img'));
}

// Exact Finish payload, duplicate-submit guard, and success handling (6 checks).
{
  let resolveFinish;
  const app = boot({
    post(endpoint) {
      if (endpoint === 'settings/onboarding') {
        return new Promise((resolvePromise) => { resolveFinish = resolvePromise; });
      }
      return Promise.resolve({});
    },
  });
  const { document } = app;
  const both = document.querySelector('input[value="both"]');
  both.checked = true;
  both.dispatchEvent(new app.window.Event('change', { bubbles: true }));
  clickNext(document, 7);
  const finish = document.getElementById('faz-setup-finish');
  finish.click();

  check('15 Finish posts to the onboarding endpoint', app.calls.post[0]?.endpoint === 'settings/onboarding');
  const sent = app.calls.post[0]?.payload || {};
  check('16 Finish sends the law plus the structured option groups', sent.law === 'both'
    && sent.banner_control && sent.banner_control.per_service_consent === false
    && sent.gcm && sent.gcm.enabled === false
    && sent.microsoft && sent.iab && sent.iab.enabled === false
    && sent.geolocation && sent.geolocation.geo_targeting === false
    && !('payment_gateways' in sent));
  check('17 Finish disables navigation while saving', finish.disabled && document.getElementById('faz-setup-back').disabled);
  finish.click();
  check('18 a second Finish click cannot submit twice', app.calls.post.length === 1);

  resolveFinish({ success: true, law: 'both' });
  await flush();
  check('19 successful save emits the completion notice', app.calls.notify.some((item) => item.type === 'success'));
  check('20 successful save schedules the fixed dashboard redirect after 700ms', app.timers.some((timer) => timer.active && timer.delay === 700));
}

// Optional scan: shared-engine progress/completion, diagnostics failure, and
// blocked/missing engine (10 checks).
{
  let resolveScan;
  let receivedOptions;
  let receivedHooks;
  const app = boot({
    scanRun(options, hooks) {
      receivedOptions = options;
      receivedHooks = hooks;
      return new Promise((resolvePromise) => { resolveScan = resolvePromise; });
    },
  });
  const button = app.document.getElementById('faz-setup-scan-btn');
  const status = app.document.getElementById('faz-setup-scan-status');
  const progress = app.document.getElementById('faz-setup-scan-progress');
  button.click();
  check('21 scan invokes the shared engine with the canonical 20-page request', receivedOptions?.maxPages === 20);
  check('22 scan button disables immediately', button.disabled);
  check('23 scan exposes the starting status immediately', status.textContent.includes('Starting scan'));
  receivedHooks.status('3/20 pages | 4 cookies');
  receivedHooks.progress(35);
  check('24 engine status is rendered in the wizard', status.textContent.includes('3/20 pages'));
  check('25 engine progress is determinate and accessible', progress.getAttribute('aria-valuenow') === '35'
    && progress.querySelector('.faz-setup-scan-bar').style.width === '35%');
  resolveScan({ total: 4, diagnostics: { totalIssues: 0 } });
  await flush();
  check('26 completed scan reports the discovered-cookie count', status.textContent.includes('4 cookies found'));
  check('27 completed scan re-enables its button and hides progress', !button.disabled && progress.hidden);
}

{
  const failure = new Error('Public site and admin origins differ.');
  const app = boot({
    scanRun() { return Promise.reject(failure); },
  });
  const button = app.document.getElementById('faz-setup-scan-btn');
  button.click();
  await flush();
  check('28 browser-engine failure is non-blocking and visible', !button.disabled
    && app.document.getElementById('faz-setup-scan-status').textContent.includes('origins differ')
    && app.calls.notify.some((item) => item.type === 'error'));
}

{
  const app = boot();
  const button = app.document.getElementById('faz-setup-scan-btn');
  button.click();
  check('29 a missing or blocked engine leaves the optional scan usable', !button.disabled);
  check('30 a missing or blocked engine explains the fallback', app.document.getElementById('faz-setup-scan-status').textContent.includes('could not load'));
}

// Jurisdiction-aware geo defaults, with explicit user edits taking precedence.
{
  const app = boot();
  const { document, window } = app;
  const selectedRegions = () => Array.from(document.querySelectorAll('input[name="faz-setup-geo-region"]:checked'))
    .map((input) => input.value);
  const chooseLaw = (law) => {
    const input = document.querySelector(`input[name="faz-setup-law"][value="${law}"]`);
    input.checked = true;
    input.dispatchEvent(new window.Event('change', { bubbles: true }));
  };

  chooseLaw('ccpa');
  check('31 CCPA selection defaults geo targeting to the US', JSON.stringify(selectedRegions()) === JSON.stringify(['us']));
  chooseLaw('popia');
  check('32 POPIA selection defaults geo targeting to South Africa', JSON.stringify(selectedRegions()) === JSON.stringify(['za']));

  const uk = document.querySelector('input[name="faz-setup-geo-region"][value="uk"]');
  uk.checked = true;
  uk.dispatchEvent(new window.Event('change', { bubbles: true }));
  chooseLaw('both');
  check('33 a manual region edit is preserved across later law changes', JSON.stringify(selectedRegions()) === JSON.stringify(['uk', 'za']));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 && passed === 33 ? 0 : 1);
