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
// The settle schedule is two checkpoints, and the SECOND one is variable: a
// page that produced nothing new between load and the first checkpoint is
// finalised at DELAYED_SCRIPT_WINDOW_MS (3000ms post-load) instead of paying
// the whole budget. Naming the expected delay here rather than hard-coding
// finalWait keeps this suite pinned to the property that matters — the window
// stays open past the 1-3s in which optimizers restore delayed scripts — and
// not to whichever arithmetic produces it.
const FIRST_CHECKPOINT = 1500;                     // settleTimeoutMs 3800 -> firstWait.
const DELAYED_SCRIPT_WINDOW = 3000;
const STABLE_REMAINDER = DELAYED_SCRIPT_WINDOW - FIRST_CHECKPOINT;

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
        // Extension-less pixel loaded via new Image() — the reason `img` stays
        // in the allow-list and the extension test does the rejecting.
        { name: 'https://www.facebook.com/tr?id=1&ev=PageView', initiatorType: 'img' },
        // Passive assets whose URL contains a provider pattern. Importing any of
        // these fabricates cookie declarations for a service the page never
        // loaded (ytimg -> YSC, vimeocdn -> vuid, googleads -> IDE).
        { name: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', initiatorType: 'img' },
        { name: 'https://i.vimeocdn.com/video/1234567-abc_640x360.jpg', initiatorType: 'img' },
        { name: 'https://example.test/wp-content/uploads/2024/01/googleads-guide.png', initiatorType: 'img' },
        { name: 'https://example.test/wp-content/themes/acme/css/slimstat.css', initiatorType: 'css' },
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
check('03 scanner does not finish at the first stable checkpoint', runTimer(FIRST_CHECKPOINT) && result === undefined);
check('04 scanner still observes a cookie created after 2.5 seconds', runTimer(2500) && result === undefined);
// A stable page is finalised at the delayed-script window, not at the first
// checkpoint: 3000ms post-load is past the 2500ms cookie above, which is the
// whole point. Shortening the schedule to finish at 1500ms would turn checks
// 04 and 06 red, which is the guard this suite exists to be.
check('05 scanner keeps the observation window open across the delayed-script window',
  STABLE_REMAINDER > 0 && FIRST_CHECKPOINT + STABLE_REMAINDER > 2500 && runTimer(STABLE_REMAINDER) && result !== undefined);
check('06 interaction-created cookies are collected', result.cookies.some((cookie) => cookie.name === 'interaction_cookie'));
check('07 optimizer-deferred script attributes are collected', result.scripts.some((url) => url === 'https://cdn.tracker.test/delayed.js'));
check('08 lazy iframe attributes are collected', result.scripts.some((url) => url === 'https://video.tracker.test/embed'));
check('09 cache-busted runtime resources are canonicalized and deduplicated', result.scripts.filter((url) => url === 'https://pixel.tracker.test/collect').length === 1);
// Host questions are asked of the parsed hostname, never of the raw string.
// `url.includes('ytimg.com')` is true of https://evil.test/?r=ytimg.com as well
// as of the fixture, so it is both a CodeQL alert (js/incomplete-url-substring-
// sanitization) and a genuinely weaker assertion than the one intended: what
// these checks mean is "no resource FROM that host was imported".
function parsedUrl(value) {
  try {
    return new URL(value);
  } catch {
    return null;
  }
}
function importedFromHost(host) {
  return result.scripts.some((url) => {
    const parsed = parsedUrl(url);
    if (parsed === null) return false;
    return parsed.hostname === host || parsed.hostname.endsWith(`.${host}`);
  });
}
check('10 extension-less img beacons are still imported', result.scripts.some((url) => {
  const parsed = parsedUrl(url);
  // Equivalent to the exact 'https://www.facebook.com/tr' this asserted before:
  // the query string must be gone, which is the canonicalization being pinned.
  return parsed !== null
    && parsed.protocol === 'https:'
    && parsed.hostname === 'www.facebook.com'
    && parsed.pathname === '/tr'
    && parsed.search === '';
}));
check('11 ytimg thumbnails do not fabricate YouTube cookies', !importedFromHost('ytimg.com'));
check('12 vimeocdn thumbnails do not fabricate Vimeo cookies', !importedFromHost('vimeocdn.com'));
check('13 uploaded images do not fabricate ad cookies', !result.scripts.some((url) => url.includes('googleads-guide')));
check('14 stylesheets are not imported as scripts', !result.scripts.some((url) => url.endsWith('.css')));

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 && passed === 14 ? 0 : 1);
