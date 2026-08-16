/**
 * Cookie inventory stale-marking safety regression checks.
 *
 * Run: node tests/unit/js/cookies-stale-safety.test.mjs
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

function boot({ previousCookies, scanResult, maxPages = 0 }) {
  const dom = new JSDOM(`<!doctype html><html><body>
    <div><button id="faz-scan-btn">Scan Site</button><div id="faz-scan-dropdown"></div></div>
    <div id="faz-stale-bar"></div>
    <ul id="faz-cat-list"></ul>
    <input id="faz-select-all-cookies" type="checkbox">
    <div id="faz-bulk-bar"></div>
    <table><tbody id="faz-cookies-tbody"></tbody></table>
  </body></html>`, { runScripts: 'outside-only', url: 'https://example.test/wp-admin/admin.php?page=faz-cookies' });
  const { window } = dom;
  const notifications = [];
  // Do not run the page boot sequence: this test targets the private scan
  // completion path and supplies only the DOM nodes it owns.
  const instrumented = SCRIPT.replace('FAZ.ready(function () {', 'window.__fazCookiesTest = { startScan: startScan }; FAZ.ready(function () {');
  window.fazConfig = { i18n: {} };
  window.FAZ = {
    ready() {},
    btnLoading() {},
    notify(message, type) { notifications.push({ message, type }); },
    get(endpoint) {
      return Promise.resolve(endpoint === 'cookies' ? previousCookies : []);
    },
    scanEngine: {
      run() { return Promise.resolve(scanResult); },
      diagnosticsHint() { return ''; },
    },
  };
  window.eval(instrumented);
  return { window, document: window.document, notifications, maxPages };
}

async function flush() {
  for (let i = 0; i < 6; i += 1) await Promise.resolve();
}

console.log('cookie stale-marking scan coverage safety (20 checks)');

const ENRICHMENT_NOTICE = 'Server-header enrichment is still running in the background';

const previousCookies = [{ id: 7, name: '_delayed_tracker', domain: 'example.test', discovered: 1 }];

// The consecutive-miss tally the server returns with the import. A cookie is
// only offered for deletion when it is BOTH absent from this complete scan and
// present here, so every case that expects a stale bar has to supply it.
const EARNED = { deletable_stale_keys: ['_delayed_tracker|example.test'] };

{
  const app = boot({
    previousCookies,
    scanResult: { total: 0, pagesScanned: 19, cookies: [], diagnostics: { totalIssues: 1 }, incremental: false, importResult: EARNED },
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const message = app.notifications[0]?.message || '';
  check('01 a scan with page diagnostics warns that coverage is incomplete', message.includes('Scan coverage was incomplete'));
  check('02 a scan with page diagnostics exposes no stale-cookie delete controls', !message.includes('stale cookie(s) highlighted')
    && app.document.querySelector('.faz-cookie-stale') === null
    && app.document.querySelector('.faz-stale-delete-all') === null);
}

{
  const app = boot({
    previousCookies,
    scanResult: { total: 0, pagesScanned: 20, cookies: [], diagnostics: { totalIssues: 0 }, incremental: false, importResult: EARNED },
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const message = app.notifications[0]?.message || '';
  check('03 a healthy full scan preserves stale-cookie identification', message.includes('1 stale cookie(s) highlighted')
    && app.document.querySelector('.faz-cookie-stale') !== null
    && app.document.querySelector('.faz-stale-delete-all') !== null);
  check('04 a healthy full scan does not report incomplete coverage', !message.includes('Scan coverage was incomplete'));
}

for (const [label, scanResult] of [
  ['05 missing diagnostics fail closed', { total: 0, pagesScanned: 20, cookies: [], incremental: false }],
  ['06 malformed diagnostics fail closed', { total: 0, pagesScanned: 20, cookies: [], diagnostics: { totalIssues: '0' }, incremental: false }],
  ['07 incremental scans fail closed', { total: 0, pagesScanned: 20, cookies: [], diagnostics: { totalIssues: 0 }, incremental: true }],
  ['08 early-stopped scans fail closed', { total: 0, pagesScanned: 12, cookies: [], diagnostics: { totalIssues: 0 }, incremental: false, earlyStopReason: 'no-new-findings' }],
  ['09 missing incremental state fails closed', { total: 0, pagesScanned: 20, cookies: [], diagnostics: { totalIssues: 0 } }],
]) {
  const app = boot({ previousCookies, scanResult });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const message = app.notifications[0]?.message || '';
  check(label, message.includes('Scan coverage was incomplete')
    && !message.includes('stale cookie(s) highlighted')
    && app.document.querySelector('.faz-cookie-stale') === null
    && app.document.querySelector('.faz-stale-delete-all') === null);
}

{
  const app = boot({
    previousCookies,
    maxPages: 10,
    scanResult: { total: 0, pagesScanned: 10, cookies: [], diagnostics: { totalIssues: 0 }, incremental: false, importResult: EARNED },
  });
  app.window.__fazCookiesTest.startScan(app.maxPages);
  await flush();
  const message = app.notifications[0]?.message || '';
  check('10 depth-capped scans expose no stale-cookie delete controls', message.includes('Scan coverage was incomplete')
    && app.document.querySelector('.faz-cookie-stale') === null
    && app.document.querySelector('.faz-stale-delete-all') === null);
}

// The server keeps enriching the browser findings with response-header data
// after the import returns, so the summary has to say so. The flag travels on
// importResult, which the cases above never populate.
{
  const healthyScan = { total: 0, pagesScanned: 20, cookies: [], diagnostics: { totalIssues: 0 }, incremental: false };

  const pending = boot({
    previousCookies,
    scanResult: Object.assign({}, healthyScan, { importResult: Object.assign({ enrichment_pending: true }, EARNED) }),
  });
  pending.window.__fazCookiesTest.startScan(0);
  await flush();
  check('11 a pending server enrichment is announced in the scan summary',
    (pending.notifications[0]?.message || '').includes(ENRICHMENT_NOTICE));

  const settled = boot({
    previousCookies,
    scanResult: Object.assign({}, healthyScan, { importResult: Object.assign({ enrichment_pending: false }, EARNED) }),
  });
  settled.window.__fazCookiesTest.startScan(0);
  await flush();
  check('12 a settled import does not announce a pending enrichment',
    !(settled.notifications[0]?.message || '').includes(ENRICHMENT_NOTICE));
}

/* ── The consecutive-miss tally, wired ─────────────────────────────────
 *
 * A single complete scan that did not observe a cookie proves nothing: a site
 * that delays its JavaScript until interaction never fires its trackers inside
 * a passive iframe, and flow-only cookies (checkout, login) are never reached.
 * The server counts consecutive misses and reports which entries have earned
 * deletion; the page may offer only the intersection of that list with its own
 * single-scan diff.
 *
 * These cases are behavioural on purpose. The property that shipped broken was
 * "the field has a consumer", and only a test that goes red when the consumer
 * is deleted can hold it — a source grep for the field name cannot.
 */
const healthyFullScan = { total: 0, pagesScanned: 20, cookies: [], diagnostics: { totalIssues: 0 }, incremental: false };

{
  // Tally at 1: seen missing once, not yet deletable.
  const app = boot({
    previousCookies,
    scanResult: Object.assign({}, healthyFullScan, { importResult: { deletable_stale_keys: [] } }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const message = app.notifications[0]?.message || '';
  check('13 a cookie missed by ONE complete scan is not offered for deletion',
    !message.includes('stale cookie(s) highlighted')
    && app.document.querySelector('.faz-cookie-stale') === null
    && app.document.querySelector('.faz-stale-delete-all') === null);
  check('14 and the scan is still reported as complete coverage',
    !message.includes('Scan coverage was incomplete'));
}

{
  // Tally at the threshold: the same absence is now actionable.
  const app = boot({
    previousCookies,
    scanResult: Object.assign({}, healthyFullScan, { importResult: EARNED }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  check('15 once the entry has earned deletability it IS offered',
    (app.notifications[0]?.message || '').includes('1 stale cookie(s) highlighted')
    && app.document.querySelector('.faz-stale-delete-all') !== null);
}

{
  // A response with no tally field at all — an older server, or one that lost
  // the field. Fail closed: the action deletes rows from the public cookie
  // declaration, so "unknown" must never read as "earned".
  const app = boot({
    previousCookies,
    scanResult: Object.assign({}, healthyFullScan, { importResult: {} }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  check('16 a response carrying no tally offers nothing for deletion',
    app.document.querySelector('.faz-stale-delete-all') === null);
}

{
  // THE silent-failure mode this fix exists to avoid. Cookie domains routinely
  // carry a leading dot and names arrive in mixed case; the server keys its
  // tally one way and the page keys its diff another. If the two are not folded
  // to one canonical form the intersection is empty forever — the stale bar
  // never appears, and the feature LOOKS wired while doing nothing, which is
  // strictly worse than an obviously dead field. The tally below is delivered
  // in raw, uncanonicalized form on purpose.
  const app = boot({
    previousCookies: [{ id: 9, name: '_Delayed_Tracker', domain: '.Example.TEST:8443', discovered: 1 }],
    scanResult: Object.assign({}, healthyFullScan, {
      importResult: { deletable_stale_keys: ['  _Delayed_Tracker  |.Example.TEST:8443'] },
    }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  check('17 a leading-dot, ported, mixed-case identity still intersects the tally',
    (app.notifications[0]?.message || '').includes('1 stale cookie(s) highlighted')
    && app.document.querySelector('.faz-stale-delete-all') !== null);
}

{
  // Same name, different domain. The key is the pair, so this must NOT match.
  const app = boot({
    previousCookies: [{ id: 11, name: '_delayed_tracker', domain: 'example.test', discovered: 1 }],
    scanResult: Object.assign({}, healthyFullScan, {
      importResult: { deletable_stale_keys: ['_delayed_tracker|other.test'] },
    }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  check('18 the tally is keyed on name AND domain, not the name alone',
    app.document.querySelector('.faz-stale-delete-all') === null);
}

/* ── Cancellation is a new way to be incomplete ─────────────────────── */
{
  const app = boot({
    previousCookies,
    scanResult: Object.assign({}, healthyFullScan, { pagesScanned: 8, stoppedReason: 'cancelled', importResult: EARNED }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const message = app.notifications[0]?.message || '';
  check('19 a cancelled crawl is treated as incomplete coverage',
    message.includes('Scan coverage was incomplete')
    && app.document.querySelector('.faz-cookie-stale') === null
    && app.document.querySelector('.faz-stale-delete-all') === null);
  check('20 and the summary says the run was stopped', message.includes('stopped by you'));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 && passed === 20 ? 0 : 1);
