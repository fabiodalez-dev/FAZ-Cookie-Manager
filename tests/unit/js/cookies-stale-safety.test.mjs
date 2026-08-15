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

console.log('cookie stale-marking scan coverage safety (12 checks)');

const ENRICHMENT_NOTICE = 'Server-header enrichment is still running in the background';

const previousCookies = [{ id: 7, name: '_delayed_tracker', domain: 'example.test', discovered: 1 }];

{
  const app = boot({
    previousCookies,
    scanResult: { total: 0, pagesScanned: 19, cookies: [], diagnostics: { totalIssues: 1 }, incremental: false },
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
    scanResult: { total: 0, pagesScanned: 20, cookies: [], diagnostics: { totalIssues: 0 }, incremental: false },
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
    scanResult: { total: 0, pagesScanned: 10, cookies: [], diagnostics: { totalIssues: 0 }, incremental: false },
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
    scanResult: Object.assign({}, healthyScan, { importResult: { enrichment_pending: true } }),
  });
  pending.window.__fazCookiesTest.startScan(0);
  await flush();
  check('11 a pending server enrichment is announced in the scan summary',
    (pending.notifications[0]?.message || '').includes(ENRICHMENT_NOTICE));

  const settled = boot({
    previousCookies,
    scanResult: Object.assign({}, healthyScan, { importResult: { enrichment_pending: false } }),
  });
  settled.window.__fazCookiesTest.startScan(0);
  await flush();
  check('12 a settled import does not announce a pending enrichment',
    !(settled.notifications[0]?.message || '').includes(ENRICHMENT_NOTICE));
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 && passed === 12 ? 0 : 1);
