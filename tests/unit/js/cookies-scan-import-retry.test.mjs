/**
 * Cookies page: held-import retry flow (hold-and-retry after a failed save).
 *
 * When the browser crawl succeeds but the server cannot SAVE the results, the
 * server holds the captured evidence and the page must offer a recovery panel
 * instead of throwing the crawl away. Every branch of that flow is private to
 * startScan's closure, so this suite boots the real cookies.js in jsdom and
 * drives the flow through startScan with a scriptable scan-engine stub — the
 * same technique as cookies-stale-safety and cookies-restore-and-jar-disclosure.
 *
 * Run: node tests/unit/js/cookies-scan-import-retry.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = readFileSync(resolve(HERE, '../../../admin/assets/js/pages/cookies.js'), 'utf8');

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

/**
 * Boot the page script without running its DOM-ready bootstrap.
 *
 * `engineRuns` scripts FAZ.scanEngine.run: each call shifts the next entry and
 * invokes it with (opts, hooks). Entries build their promise lazily so a
 * rejection that is never reached cannot leak an unhandled-rejection warning.
 */
function boot({ engineRuns = [] } = {}) {
  const dom = new JSDOM(`<!doctype html><html><body>
    <div><button id="faz-scan-btn">Scan Site</button><div id="faz-scan-dropdown"></div></div>
    <div id="faz-stale-bar"></div>
    <div id="faz-restore-bar"></div>
    <div id="faz-jar-bar"></div>
    <ul id="faz-cat-list"></ul>
    <input id="faz-select-all-cookies" type="checkbox">
    <div id="faz-bulk-bar"></div>
    <table><tbody id="faz-cookies-tbody"></tbody></table>
  </body></html>`, { runScripts: 'outside-only', url: 'https://example.test/wp-admin/admin.php?page=faz-cookies' });
  const { window } = dom;
  const notifications = [];
  const runCalls = [];
  const instrumented = SCRIPT.replace(
    'FAZ.ready(function () {',
    'window.__fazCookiesTest = { startScan: startScan }; FAZ.ready(function () {'
  );
  window.fazConfig = { i18n: {} };
  window.FAZ = {
    ready() {},
    btnLoading() {},
    notify(message, type) { notifications.push({ message, type }); },
    get() { return Promise.resolve([]); },
    post() { return Promise.resolve({}); },
    scanEngine: {
      run(opts, hooks) {
        runCalls.push(opts);
        const next = engineRuns.shift();
        if (!next) { return new Promise(function () {}); }
        return next(opts, hooks);
      },
      diagnosticsHint() { return ''; },
    },
  };
  window.eval(instrumented);
  return { window, document: window.document, notifications, runCalls };
}

async function flush() {
  for (let i = 0; i < 12; i += 1) await Promise.resolve();
}

/** A complete, healthy run the success handler accepts without complaint. */
function okResult(importResult = {}) {
  return { total: 3, pagesScanned: 20, cookies: [], diagnostics: { totalIssues: 0 }, incremental: false, importResult };
}

function heldError(extra = {}) {
  return Object.assign(new Error('The server could not save the scan results.'), { sessionHeld: true }, extra);
}

const wrapper = (doc) => doc.querySelector('.faz-scan-progress-wrap');
const panel = (doc) => doc.querySelector('.faz-scan-held');
const retryButton = (doc) => {
  const p = panel(doc);
  return p ? p.querySelector('.faz-btn-primary') : null;
};
const dismissButton = (doc) => {
  const p = panel(doc);
  if (!p) return null;
  const buttons = p.querySelectorAll('button');
  return buttons.length > 1 ? buttons[buttons.length - 1] : null;
};

console.log('cookies page held-import retry flow');

// ── A held failure keeps the UI up and offers recovery ────────────────────
{
  const err = heldError({ retryImport() { return Promise.resolve(okResult()); } });
  const app = boot({ engineRuns: [() => Promise.reject(err)] });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  check('01 a held import failure keeps the progress wrapper mounted',
    wrapper(app.document) !== null && wrapper(app.document).parentNode !== null);
  check('02 the recovery panel is rendered with both a Retry and a Discard control',
    panel(app.document) !== null && retryButton(app.document) !== null && dismissButton(app.document) !== null);
  const statusEl = app.document.querySelector('.faz-scan-status');
  check('03 the run is labelled "Not saved" and the Stop control is withdrawn',
    statusEl !== null && statusEl.textContent === 'Not saved'
    && app.document.querySelector('.faz-scan-stop') === null);
  const explain = app.document.querySelector('.faz-scan-held-text');
  check('04 with a resubmittable payload the offer promises a save-only retry',
    explain !== null && explain.textContent.includes('does not scan the site again')
    && retryButton(app.document).textContent === 'Retry import'
    && !retryButton(app.document).textContent.includes('re-scans'));
  check('05 no toast is fired while the panel is up — the terminal path was not taken',
    app.notifications.length === 0);
  const detail = app.document.querySelector('.faz-scan-held-detail');
  check('06 the failure detail is shown to the administrator as text',
    detail !== null && detail.textContent === 'The server could not save the scan results.');
}

// ── Retry re-imports WITHOUT re-crawling when retryImport is available ────
{
  let retries = 0;
  const err = heldError({
    retryImport() { retries += 1; return Promise.resolve(okResult()); },
  });
  const app = boot({ engineRuns: [() => Promise.reject(err)] });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  retryButton(app.document).click();
  await flush();
  check('07 clicking Retry resubmits the held payload once and never re-runs the crawl engine',
    retries === 1 && app.runCalls.length === 1);
  check('08 a successful retry tears the panel down and reports the scan as complete',
    panel(app.document) === null && wrapper(app.document) === null
    && app.notifications.length === 1
    && app.notifications[0].type === 'success'
    && app.notifications[0].message.includes('Scan complete'));
}

// ── The scanId fallback is honest about re-crawling ───────────────────────
{
  const err = heldError({ scanId: 'held-scan-123' });
  const app = boot({
    engineRuns: [
      () => Promise.reject(err),
      () => Promise.resolve(okResult()),
    ],
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const explain = app.document.querySelector('.faz-scan-held-text');
  check('09 without retryImport the offer is labelled as re-scanning and never promises otherwise',
    retryButton(app.document).textContent.includes('re-scans the site')
    && explain !== null
    && !explain.textContent.includes('does not scan the site again')
    && explain.textContent.includes('walk the pages again'));
  retryButton(app.document).click();
  await flush();
  check('10 the fallback Retry re-enters the held capture session by its own scan id',
    app.runCalls.length === 2 && app.runCalls[1].scanId === 'held-scan-123');
  check('11 the fallback retry also completes: panel gone, success reported',
    panel(app.document) === null && app.notifications.length === 1
    && app.notifications[0].type === 'success');
}

// ── NON-held failures still take the terminal path ────────────────────────
// The load-bearing negative: the server no longer holds the evidence, so a
// Retry button here would only 409. Never offer one.
for (const [label, errFactory] of [
  ['12 a failure without sessionHeld is terminal: wrapper removed, error toast, no panel',
    () => new Error('Crawl failed: network down')],
  ['13 sessionHeld:false is terminal too',
    () => Object.assign(new Error('save failed'), { sessionHeld: false })],
  ['14 a loosely-truthy sessionHeld (1) is not trusted — held means the server said true',
    () => Object.assign(new Error('save failed'), { sessionHeld: 1 })],
]) {
  const app = boot({ engineRuns: [() => Promise.reject(errFactory())] });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  check(label,
    wrapper(app.document) === null
    && panel(app.document) === null
    && app.notifications.length === 1
    && app.notifications[0].type === 'error');
}

// ── A retry that fails again ──────────────────────────────────────────────
{
  // Still held: the evidence survived, so the offer must come back.
  const secondErr = heldError({ retryImport() { return Promise.resolve(okResult()); } });
  const firstErr = heldError({ retryImport() { return Promise.reject(secondErr); } });
  const app = boot({ engineRuns: [() => Promise.reject(firstErr)] });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  retryButton(app.document).click();
  await flush();
  const freshRetry = retryButton(app.document);
  check('15 a retry that fails while still held offers the retry again, re-enabled',
    wrapper(app.document) !== null && panel(app.document) !== null
    && freshRetry !== null && freshRetry.disabled === false
    && app.notifications.length === 0);
}

{
  // No longer held: the evidence is gone, so this becomes terminal.
  const goneErr = new Error('The held session expired.');
  const firstErr = heldError({ retryImport() { return Promise.reject(goneErr); } });
  const app = boot({ engineRuns: [() => Promise.reject(firstErr)] });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  retryButton(app.document).click();
  await flush();
  check('16 a retry whose failure is no longer held falls through to the terminal path',
    wrapper(app.document) === null && panel(app.document) === null
    && app.notifications.length === 1
    && app.notifications[0].type === 'error'
    && app.notifications[0].message === 'The held session expired.');
}

// ── Discard abandons the held evidence deliberately ───────────────────────
{
  const err = heldError({ retryImport() { return Promise.resolve(okResult()); } });
  const app = boot({ engineRuns: [() => Promise.reject(err)] });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  dismissButton(app.document).click();
  await flush();
  check('17 Discard closes the panel and surfaces the original failure as an error toast',
    wrapper(app.document) === null && panel(app.document) === null
    && app.notifications.length === 1
    && app.notifications[0].type === 'error'
    && app.notifications[0].message === 'The server could not save the scan results.');
}

// ── duplicate:true reports "already saved", never a fresh import ──────────
{
  // The natural producer of a duplicate is exactly this flow: the first
  // submission saved but its response was lost, and the resubmission is
  // answered from the record. Reporting it as a fresh scan would double-count.
  const err = heldError({
    retryImport() { return Promise.resolve(okResult({ duplicate: true })); },
  });
  const app = boot({ engineRuns: [() => Promise.reject(err)] });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  retryButton(app.document).click();
  await flush();
  const msg = app.notifications[0] ? app.notifications[0].message : '';
  check('18 a duplicate import is reported as already on record, with the on-record counts',
    msg.includes('Already saved') && msg.includes('Nothing was saved twice')
    && msg.includes('3') && msg.includes('20'));
  check('19 the duplicate is not announced as a fresh completed scan, and only once',
    !msg.includes('Scan complete') && app.notifications.length === 1);
}

{
  // Control: a genuinely fresh import keeps the fresh-scan wording.
  const app = boot({ engineRuns: [() => Promise.resolve(okResult())] });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  check('20 a non-duplicate import still reads as a completed scan',
    app.notifications.length === 1
    && app.notifications[0].message.includes('Scan complete')
    && !app.notifications[0].message.includes('Already saved'));
}

// ── Starting a new scan sweeps any lingering held panel ───────────────────
{
  const err = heldError({ retryImport() { return Promise.resolve(okResult()); } });
  const app = boot({
    engineRuns: [
      () => Promise.reject(err),
      () => new Promise(function () {}), // second scan stays in flight
    ],
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  check('21 precondition: the first scan left its held panel mounted', panel(app.document) !== null);
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const wrappers = app.document.querySelectorAll('.faz-scan-progress-wrap');
  check('22 a new scan removes the lingering wrapper — exactly one progress UI on screen',
    wrappers.length === 1);
  check('23 no stale, now-void Retry offer survives next to the new progress bar',
    panel(app.document) === null && retryButton(app.document) === null);
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 && passed === 23 ? 0 : 1);
