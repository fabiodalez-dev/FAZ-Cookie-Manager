/**
 * JS unit test (jsdom) — anti-adblock banner resilience guard (opt-in).
 *
 * Some ad-block cosmetic filter lists (e.g. EasyList Cookie) inject rules such
 * as `##[class*=consent]` / `##[id*=cookie]` that hide any element whose
 * class/id contains "cookie"/"consent". That matches FAZ's own
 * `.faz-consent-container`, suppressing a LEGALLY REQUIRED notice. When the
 * admin enables `banner_control.adblock_resilience`, the server emits
 * `_fazConfig._adblockResilience = true`, and `_fazInit()` schedules ONE
 * deferred `_fazAdblockResilienceCheck()` that re-asserts the banner's
 * visibility if (and only if) an external rule has hidden it.
 *
 * This test loads the real frontend/js/script.js in jsdom and calls the exposed
 * `fazcookie._fazAdblockResilienceCheck()` directly, stubbing
 * `window.getComputedStyle` to simulate the cosmetic-filter state (jsdom's
 * cascade support is partial, so we test the guard's LOGIC deterministically,
 * not the browser's native cascade). Cases:
 *   1. resilience ON + no action + container reported display:none + no
 *      faz-hide -> guard sets inline display/visibility/opacity !important.
 *   2. resilience OFF -> no inline styles touched.
 *   3. action already recorded -> no-op (respects the visitor's decision).
 *   4. container has faz-hide (FAZ's own hidden state) -> no-op.
 *   5. getComputedStyle reports visible -> no-op.
 *   6. after a re-assert, _fazClearAdblockReassert(container) removes the inline
 *      props so a subsequent dismissal (.faz-hide) can hide the banner.
 *
 * Run: node tests/unit/js/adblock-resilience-guard.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) {
    passed += 1;
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
    console.log(`       expected: ${JSON.stringify(expected)}`);
    console.log(`       actual:   ${JSON.stringify(actual)}`);
  }
}
function ok(label, cond) {
  eq(label, !!cond, true);
}

// Build a fresh jsdom + evaluated script.js per case so state never leaks.
// `computed` is the fake getComputedStyle result the cosmetic filter would
// produce; `withBanner` controls whether the banner DOM is present.
function boot({ resilience = true, computed = { display: 'none', visibility: 'visible', opacity: '1' }, withBanner = true } = {}) {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const banner = withBanner
    ? '<div class="faz-consent-container"><div class="faz-consent-bar" data-faz-tag="notice">notice</div></div>'
    : '';
  const dom = new JSDOM(`<!DOCTYPE html><html><body>${banner}</body></html>`, {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;

  window._fazConfig = {
    _categories: [{ slug: 'necessary', isNecessary: true }],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    i18n: {},
  };
  if (resilience) {
    window._fazConfig._adblockResilience = true;
  }

  // Prevent the DomReady bootstrap from running _fazInit: force "loading" and
  // swallow the DOMContentLoaded registration. We call the guard by hand.
  Object.defineProperty(window.document, 'readyState', {
    get: () => 'loading',
    configurable: true,
  });
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, cb, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, cb, ...rest);
  };
  window.setTimeout = () => 0; // no real timers
  window.console.error = () => {};
  window.console.warn = () => {};

  // Deterministic stand-in for the cosmetic-filter cascade.
  window.getComputedStyle = () => computed;

  window.eval(code);
  window.document.addEventListener = realAdd;
  return window;
}

function inlineDisplay(el) {
  return el.style.getPropertyValue('display');
}

function run() {
  // --- Case 1: resilience ON, hidden by filter, no action -> re-assert. ---
  {
    const window = boot({ resilience: true, computed: { display: 'none', visibility: 'visible', opacity: '1' } });
    const notice = window.document.querySelector('.faz-consent-container');
    window.fazcookie._fazAdblockResilienceCheck();
    eq('case1: display re-asserted to block', inlineDisplay(notice), 'block');
    eq('case1: display marked !important', notice.style.getPropertyPriority('display'), 'important');
    eq('case1: visibility re-asserted', notice.style.getPropertyValue('visibility'), 'visible');
    eq('case1: opacity re-asserted', notice.style.getPropertyValue('opacity'), '1');
    eq('case1: reasserted data flag set', notice.dataset.fazReasserted, '1');
  }

  // --- Case 2: resilience OFF -> nothing touched. ---
  {
    const window = boot({ resilience: false, computed: { display: 'none', visibility: 'visible', opacity: '1' } });
    const notice = window.document.querySelector('.faz-consent-container');
    window.fazcookie._fazAdblockResilienceCheck();
    eq('case2: no inline display when resilience OFF', inlineDisplay(notice), '');
    eq('case2: no reasserted flag when resilience OFF', notice.dataset.fazReasserted, undefined);
  }

  // --- Case 3: action already recorded -> no-op (respect the decision). ---
  {
    const window = boot({ resilience: true, computed: { display: 'none', visibility: 'visible', opacity: '1' } });
    const notice = window.document.querySelector('.faz-consent-container');
    window.fazcookie._fazSetInStore('action', 'accept');
    window.fazcookie._fazAdblockResilienceCheck();
    eq('case3: no re-assert after the visitor decided', inlineDisplay(notice), '');
  }

  // --- Case 4: FAZ's own faz-hide present -> do not fight it. ---
  {
    const window = boot({ resilience: true, computed: { display: 'none', visibility: 'visible', opacity: '1' } });
    const notice = window.document.querySelector('.faz-consent-container');
    notice.classList.add('faz-hide');
    window.fazcookie._fazAdblockResilienceCheck();
    eq('case4: no re-assert when FAZ itself hid the banner', inlineDisplay(notice), '');
  }

  // --- Case 5: getComputedStyle reports visible -> no-op. ---
  {
    const window = boot({ resilience: true, computed: { display: 'block', visibility: 'visible', opacity: '1' } });
    const notice = window.document.querySelector('.faz-consent-container');
    window.fazcookie._fazAdblockResilienceCheck();
    eq('case5: no re-assert when banner is already visible', inlineDisplay(notice), '');
    eq('case5: no reasserted flag when visible', notice.dataset.fazReasserted, undefined);
  }

  // --- Case 5b: banner not on the page -> no-op, no throw. ---
  {
    const window = boot({ resilience: true, withBanner: false });
    let threw = false;
    try { window.fazcookie._fazAdblockResilienceCheck(); } catch (e) { threw = true; }
    ok('case5b: no throw when banner absent', !threw);
  }

  // --- Case 6: clear removes inline props so a later dismissal can hide it. ---
  {
    const window = boot({ resilience: true, computed: { display: 'none', visibility: 'visible', opacity: '1' } });
    const notice = window.document.querySelector('.faz-consent-container');
    window.fazcookie._fazAdblockResilienceCheck();
    eq('case6: precondition — re-assert applied', inlineDisplay(notice), 'block');
    window.fazcookie._fazClearAdblockReassert(notice);
    eq('case6: display prop removed', inlineDisplay(notice), '');
    eq('case6: visibility prop removed', notice.style.getPropertyValue('visibility'), '');
    eq('case6: opacity prop removed', notice.style.getPropertyValue('opacity'), '');
    eq('case6: reasserted flag cleared', notice.dataset.fazReasserted, undefined);
    // With the inline override gone, .faz-hide's display:none can win again.
    notice.classList.add('faz-hide');
    ok('case6: banner can be hidden again after clear', notice.classList.contains('faz-hide') && inlineDisplay(notice) === '');
  }

  console.log(`\n  adblock-resilience-guard: ${passed} passed, ${failed} failed`);
  if (failed > 0) {
    process.exit(1);
  }
}

run();
