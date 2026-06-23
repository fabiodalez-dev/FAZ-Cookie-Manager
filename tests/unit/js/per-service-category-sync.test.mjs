/**
 * JS unit test (jsdom) — regression guard for the per-service category->service
 * sync registration bug (#134/#146, CodeRabbit PR #157 finding #4).
 *
 * THE BUG (pre-fix): _fazRenderServiceToggles() registered the "category toggle
 * change -> mirror onto its service toggles" listeners AFTER an early return
 * that fires when the first render has zero services. On a block-first / pristine
 * install a category can legitimately have no services at first paint, yet a
 * provider gets injected LATER (a runtime-blocked embed). With the listener never
 * bound, rejecting that category did NOT deselect the injected service toggle, so
 * at save time svc.<id>:yes persisted under a denied category — service consent
 * silently overriding the category revocation (GDPR Art. 7(3)).
 *
 * Why this lives in jsdom and not Playwright: the bug only manifests when the
 * service's category has ZERO services at first render. Every page on the dev
 * WordPress stack exposes 19+ scanner-detected services (marketing included), so
 * the early-return path is never taken there and an E2E test cannot reproduce it
 * without wiping the scan DB (forbidden on the shared dev instance). jsdom lets
 * us drive _fazRenderServiceToggles() with a controlled empty _services list.
 *
 * The test loads the REAL frontend/js/script.js (source of truth), neutralising
 * only its DOMContentLoaded auto-bootstrap so _fazInit() never runs. It then
 * exercises the actual shipped _fazRenderServiceToggles / _fazBindServiceCategorySync.
 *
 * Run: node tests/unit/js/per-service-category-sync.test.mjs   (npm run test:unit:js)
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');

let passed = 0;
let failed = 0;
function check(label, cond) {
  if (cond) {
    passed += 1;
    console.log(`  [32mPASS[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  [31mFAIL[0m ${label}`);
  }
}

/**
 * Build a fresh jsdom window with script.js loaded and its auto-bootstrap
 * neutralised. Returns { window } with the internal functions available as
 * globals (Annex-B block-scoped function declarations hoist to global scope in
 * the sloppy-mode classic script).
 */
function loadFrontend() {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const html = `<!DOCTYPE html><html><body>
    <div id="fazDetailCategorymarketing"><div class="faz-accordion-body"></div></div>
    <input type="checkbox" id="fazSwitchmarketing">
  </body></html>`;
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;

  // Minimal localized config: per-service ON, a non-necessary Marketing category,
  // and crucially an EMPTY _services list — the exact condition that triggered
  // the bug's early return.
  window._fazConfig = {
    _perServiceConsent: '1',
    _perCookieConsent: false,
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'marketing', isNecessary: false, name: 'Marketing' },
    ],
    _services: [],
    _serviceCatalogue: {
      dailymotion: { id: 'dailymotion', label: 'Dailymotion', category: 'marketing', cookies: [], third_party: true },
    },
    i18n: {},
  };

  // Drop the DOMContentLoaded registration so _fazDomReady never runs _fazInit()
  // (which needs the full banner template / cookie jar and is irrelevant here).
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(code);
  window.document.addEventListener = realAdd;
  return window;
}

/** Append a service toggle to the Marketing accordion, as a runtime reveal would. */
function injectMarketingServiceToggle(window, serviceId, checked) {
  const body = window.document.querySelector('#fazDetailCategorymarketing .faz-accordion-body');
  const input = window.document.createElement('input');
  input.type = 'checkbox';
  input.className = 'faz-service-toggle';
  input.setAttribute('data-service', serviceId);
  input.setAttribute('data-category', 'marketing');
  input.checked = !!checked;
  body.appendChild(input);
  return input;
}

console.log('per-service category->service sync (regression #4, jsdom)');

// ---------------------------------------------------------------------------
// Test 1 — render() binds the category sync even when the first render is empty.
// ---------------------------------------------------------------------------
{
  const window = loadFrontend();
  window.eval('_fazRenderServiceToggles()');
  const cat = window.document.getElementById('fazSwitchmarketing');
  check(
    'render() with empty _services still marks the Marketing category toggle as sync-bound',
    cat.getAttribute('data-faz-service-sync-bound') === '1',
  );

  // Behavioural proof: a service toggle injected AFTER the empty render must
  // follow a category rejection. Pre-fix the listener was never bound, so this
  // toggle would stay checked under a denied category (svc.<id>:yes survives).
  const svc = injectMarketingServiceToggle(window, 'dailymotion', true);
  cat.checked = false;
  cat.dispatchEvent(new window.Event('change', { bubbles: true }));
  check(
    'rejecting the Marketing category deselects the runtime-injected service toggle',
    svc.checked === false,
  );

  // And accepting it again re-selects (sync works both directions).
  cat.checked = true;
  cat.dispatchEvent(new window.Event('change', { bubbles: true }));
  check(
    'accepting the Marketing category re-selects the service toggle',
    svc.checked === true,
  );
}

// ---------------------------------------------------------------------------
// Test 2 — _fazBindServiceCategorySync is idempotent (no double-bind).
// ---------------------------------------------------------------------------
{
  const window = loadFrontend();
  // Bind twice (render + a later inject both call it for the same category).
  window.eval('_fazBindServiceCategorySync({ slug: "marketing" })');
  window.eval('_fazBindServiceCategorySync({ slug: "marketing" })');
  const cat = window.document.getElementById('fazSwitchmarketing');
  check(
    'double bind leaves the sync-bound marker set exactly once',
    cat.getAttribute('data-faz-service-sync-bound') === '1',
  );

  // If the listener were attached twice, both would run on one change — still a
  // single boolean outcome, so we assert behaviour stays correct (no throw, the
  // toggle follows the category) rather than counting invocations.
  const svc = injectMarketingServiceToggle(window, 'vimeo', true);
  cat.checked = false;
  cat.dispatchEvent(new window.Event('change', { bubbles: true }));
  check('idempotent bind still propagates a category rejection exactly once', svc.checked === false);
}

// ---------------------------------------------------------------------------
// Test 3 — the Necessary category is never wired (it can't be rejected).
// ---------------------------------------------------------------------------
{
  const window = loadFrontend();
  // Necessary has no toggle id in our fixture; binding must be a safe no-op.
  let threw = false;
  try {
    window.eval('_fazBindServiceCategorySync({ slug: "necessary", isNecessary: true })');
  } catch (_e) {
    threw = true;
  }
  check('binding the Necessary category is a safe no-op (no throw)', threw === false);
}

console.log(`\n${failed === 0 ? '[32m' : '[31m'}${passed} passed, ${failed} failed[0m`);
process.exit(failed === 0 ? 0 : 1);
