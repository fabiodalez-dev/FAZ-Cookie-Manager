/**
 * Cookies page: recycle-bin age disclosure and jar-only cookie disclosure.
 *
 * Both defects were the same shape — a value the server computes and sends,
 * that no product surface reads. Source-text checks cannot see that: the field
 * exists, the endpoint returns it, and nothing is missing until you look for it
 * in the DOM. So this suite boots the real cookies.js in jsdom and asserts on
 * rendered nodes.
 *
 * Run: node tests/unit/js/cookies-restore-and-jar-disclosure.test.mjs
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
 * The two entry points under test are private to the IIFE, so they are hoisted
 * onto the window the same way the existing stale-safety suite hoists startScan.
 */
function boot({ batches = [], scanResult = null, deletedBatchesFails = false } = {}) {
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
  const instrumented = SCRIPT.replace(
    'FAZ.ready(function () {',
    'window.__fazCookiesTest = { startScan: startScan, updateRestoreBar: updateRestoreBar, updateJarOnlyBar: updateJarOnlyBar }; FAZ.ready(function () {'
  );
  window.fazConfig = { i18n: {} };
  window.FAZ = {
    ready() {},
    btnLoading() {},
    notify(message, type) { notifications.push({ message, type }); },
    get(endpoint) {
      if (endpoint === 'cookies/deleted-batches') {
        return deletedBatchesFails
          ? Promise.reject(new Error('boom'))
          : Promise.resolve({ batches, batch_count: batches.length });
      }
      return Promise.resolve([]);
    },
    post() { return Promise.resolve({ restored: 0 }); },
    scanEngine: {
      run() { return Promise.resolve(scanResult); },
      diagnosticsHint() { return ''; },
    },
  };
  window.eval(instrumented);
  return { window, document: window.document, notifications };
}

async function flush() {
  for (let i = 0; i < 8; i += 1) await Promise.resolve();
}

console.log('cookies page restore-age + jar-only disclosure');

// ── Defect C: the recycle bin reports how old the batch is ────────────────
{
  const app = boot({ batches: [{ index: 0, count: 4, deleted_at: 1700000000, deleted_at_human: '8 months' }] });
  app.window.__fazCookiesTest.updateRestoreBar();
  await flush();
  const bar = app.document.getElementById('faz-restore-bar');
  const text = bar.textContent || '';
  check('01 the age the server computed is rendered beside the count', text.includes('8 months'));
  check('02 the count is still rendered', text.includes('4'));
  check('03 the batch is no longer described as merely "recently deleted"', !/recently deleted/.test(text));
  check('04 the undo control is still offered', bar.querySelector('.faz-restore-deleted') !== null);
}

{
  // A bin written before this change carries no age. The bar must degrade to
  // the old wording rather than printing "(deleted  ago)".
  const app = boot({ batches: [{ index: 0, count: 2, deleted_at: 0, deleted_at_human: '' }] });
  app.window.__fazCookiesTest.updateRestoreBar();
  await flush();
  const text = app.document.getElementById('faz-restore-bar').textContent || '';
  check('05 a batch with no recorded age falls back to the unqualified wording', text.includes('recently deleted') && text.includes('2'));
  check('06 and never renders an empty age', !text.includes('deleted  ago') && !text.includes('(deleted )'));
}

{
  const app = boot({ batches: [] });
  app.window.__fazCookiesTest.updateRestoreBar();
  await flush();
  const bar = app.document.getElementById('faz-restore-bar');
  check('07 an empty bin renders no undo affordance at all', bar.textContent === '' && bar.style.display === 'none');
}

{
  const app = boot({ deletedBatchesFails: true });
  app.window.__fazCookiesTest.updateRestoreBar();
  await flush();
  const bar = app.document.getElementById('faz-restore-bar');
  check('08 a failed read hides the bar rather than breaking the page', bar.textContent === '' && bar.style.display === 'none');
}

// ── Defect A: jar-only cookies are disclosed after a scan ─────────────────
const COMPLETE_RUN = {
  total: 3,
  pagesScanned: 20,
  cookies: [],
  diagnostics: { totalIssues: 0 },
  incremental: false,
};

{
  const app = boot({
    scanResult: Object.assign({}, COMPLETE_RUN, {
      importResult: { jar_only_cookies: ['tk_ai', 'wp-settings-1'], jar_only_count: 2 },
    }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const bar = app.document.getElementById('faz-jar-bar');
  const items = Array.from(bar.querySelectorAll('li')).map((li) => li.textContent);
  check('09 a scan that withheld cookies says so', bar.style.display !== 'none' && (bar.textContent || '').includes('2'));
  check('10 every withheld name is listed', items.length === 2 && items.includes('tk_ai') && items.includes('wp-settings-1'));
  check('11 the list is collapsed behind a summary, not dumped inline', bar.querySelector('details > summary') !== null);
}

{
  // The server list is the authoritative record of what the import withheld.
  // When both are present it must win: the engine's own array is the raw
  // pre-import view and can disagree.
  const app = boot({
    scanResult: Object.assign({}, COMPLETE_RUN, {
      jarCookies: [{ name: 'client_only' }],
      importResult: { jar_only_cookies: ['server_only'], jar_only_count: 1 },
    }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const items = Array.from(app.document.querySelectorAll('#faz-jar-bar li')).map((li) => li.textContent);
  check('12 the server list is preferred over the client array', items.length === 1 && items[0] === 'server_only');
}

{
  // An import response without the field (older server, partial response) must
  // still disclose something rather than silently nothing.
  const app = boot({
    scanResult: Object.assign({}, COMPLETE_RUN, {
      jarCookies: [{ name: 'tk_qs' }, { name: 'tk_qs' }, { name: '  ' }],
      importResult: {},
    }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const items = Array.from(app.document.querySelectorAll('#faz-jar-bar li')).map((li) => li.textContent);
  check('13 the engine array is used when the server did not report one', items.length === 1 && items[0] === 'tk_qs');
}

{
  const app = boot({
    scanResult: Object.assign({}, COMPLETE_RUN, { jarCookies: [], importResult: { jar_only_cookies: [] } }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const bar = app.document.getElementById('faz-jar-bar');
  check('14 a scan that withheld nothing shows no disclosure', bar.textContent === '' && bar.style.display === 'none');
}

{
  // The bar must clear itself: a stale list describing a finished run is a
  // claim about the current one.
  const app = boot({
    scanResult: Object.assign({}, COMPLETE_RUN, { importResult: { jar_only_cookies: ['tk_ai'] } }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  check('15 precondition: the first scan rendered a disclosure', app.document.querySelectorAll('#faz-jar-bar li').length === 1);
  app.window.__fazCookiesTest.updateJarOnlyBar({ importResult: { jar_only_cookies: [] } });
  const bar = app.document.getElementById('faz-jar-bar');
  check('16 a later scan with nothing withheld clears the previous list', bar.textContent === '' && bar.style.display === 'none');
}

{
  // Cookie names come from a scanned page's jar and are attacker-influenceable.
  const app = boot({
    scanResult: Object.assign({}, COMPLETE_RUN, {
      importResult: { jar_only_cookies: ['<img src=x onerror=alert(1)>'] },
    }),
  });
  app.window.__fazCookiesTest.startScan(0);
  await flush();
  const bar = app.document.getElementById('faz-jar-bar');
  check('17 a hostile name is rendered as text, never parsed as markup',
    bar.querySelector('img') === null && (bar.textContent || '').includes('<img src=x onerror=alert(1)>'));
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
