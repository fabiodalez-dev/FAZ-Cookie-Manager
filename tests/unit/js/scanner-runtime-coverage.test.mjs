/**
 * Browser scanner runtime-coverage regression checks.
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ENGINE = resolve(HERE, '../../../admin/assets/js/modules/scan-engine.js');
const SOURCE = readFileSync(ENGINE, 'utf8');

// scanSingleUrl is a closure inside the module, so the only way to drive it is
// to splice an export in beside the public one. String.replace() is silent when
// its anchor is gone: it hands back the source untouched and the suite dies
// several statements later on `undefined.scanSingleUrl`, which reads like a
// scanner bug. Say what actually happened, here, where it is still obvious.
const ANCHOR = 'window.FAZ.scanEngine = {';
const anchorCount = SOURCE.split(ANCHOR).length - 1;
if (anchorCount !== 1) {
  console.error(
    `Cannot instrument the scan engine: expected exactly one \`${ANCHOR}\` in ${ENGINE}, found ${anchorCount}. `
    + 'The test harness reaches scanSingleUrl through that line — update the anchor to match the module.',
  );
  process.exit(1);
}
const SCRIPT = SOURCE.replace(
  ANCHOR,
  `window.__fazScanCoverageTest = { scanSingleUrl: scanSingleUrl }; ${ANCHOR}`,
);

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

const dom = new JSDOM('<!doctype html><div id="faz-scan-frame"></div>', {
  runScripts: 'outside-only',
  url: 'https://example.test/wp-admin/admin.php?page=faz-cookies',
});
const { window } = dom;
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
function runTimer(delay) {
  const timer = timers.find((item) => item.active && item.delay === delay);
  if (!timer) return false;
  timer.active = false;
  timer.callback();
  return true;
}

window.fazConfig = { i18n: {} };
window.FAZ = {};
window.eval(SCRIPT);

let result;
window.__fazScanCoverageTest.scanSingleUrl(
  'https://example.test/product/',
  (value) => { result = value; },
  { loadTimeoutMs: 15000, settleTimeoutMs: 3800, scanId: 'a'.repeat(32) },
);

const iframe = window.document.querySelector('#faz-scan-frame iframe');
const frameDocument = iframe.contentDocument;
frameDocument.open();
frameDocument.write(`<!doctype html><html><body>
  <script data-rocket-src="https://cdn.tracker.test/delayed.js"></script>
  <iframe data-lazy-src="https://video.tracker.test/embed"></iframe>
</body></html>`);
frameDocument.close();

Object.defineProperty(iframe.contentWindow.performance, 'getEntriesByType', {
  configurable: true,
  value(type) {
    return type === 'resource'
      ? [
        { name: 'https://pixel.tracker.test/collect?cache=one', initiatorType: 'beacon' },
        { name: 'https://pixel.tracker.test/collect?cache=two', initiatorType: 'fetch' },
      ]
      : [];
  },
});
iframe.contentWindow.scrollTo = () => {};

let interactions = 0;
iframe.contentWindow.addEventListener('pointermove', () => {
  interactions += 1;
  window.setTimeout(() => { frameDocument.cookie = 'interaction_cookie=1; path=/'; }, 2500);
});

iframe.dispatchEvent(new window.Event('load'));
check('01 scanner renders a realistic desktop viewport', iframe.style.width === '1365px' && iframe.style.height === '900px');
check('02 controlled interaction is dispatched after the first read', interactions > 0);
check('03 scanner does not finish at the first stable checkpoint', runTimer(1500) && result === undefined);
check('04 scanner still observes a cookie created after 2.5 seconds', runTimer(2500) && result === undefined);
check('05 scanner keeps the final post-interaction observation window', runTimer(1800) && result !== undefined);
check('06 interaction-created cookies are collected', result.cookies.some((cookie) => cookie.name === 'interaction_cookie'));
check('07 optimizer-deferred script attributes are collected', result.scripts.some((url) => url === 'https://cdn.tracker.test/delayed.js'));
check('08 lazy iframe attributes are collected', result.scripts.some((url) => url === 'https://video.tracker.test/embed'));
check('09 cache-busted runtime resources are canonicalized and deduplicated', result.scripts.filter((url) => url === 'https://pixel.tracker.test/collect').length === 1);

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 && passed === 9 ? 0 : 1);
