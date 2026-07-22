/**
 * JS unit test (jsdom) — youth / age-appropriate consent gate (GDPR Art. 8).
 *
 * The gate is an OPT-IN, inline age-confirmation checkbox rendered above the
 * accept surfaces. It gates ONLY the accept/partial path — Reject is never
 * gated — and never disables the Accept button (equal weight, EDPB 03/2022).
 *
 * This test loads the REAL frontend/js/script.js (source of truth), neutralising
 * only its DOMContentLoaded auto-bootstrap so _fazInit() never runs, then drives
 * the actual shipped _fazRenderAgeConfirmations / _fazIsAgeAffirmed /
 * _fazAcceptCookies against a controlled _fazStore._ageGate.
 *
 * Why jsdom and not Playwright: the accept-path branch matters only with a
 * controlled _ageGate + a minimal store; the E2E suite covers the full render on
 * the WordPress stack. Here we assert the pure gate logic + DOM injection.
 *
 * Run: node tests/unit/js/age-confirmation.test.mjs   (npm run test:unit:js)
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
    console.log(`  \x1b[32mPASS\x1b[0m ${label}`);
  } else {
    failed += 1;
    console.log(`  \x1b[31mFAIL\x1b[0m ${label}`);
  }
}

/**
 * Build a fresh jsdom window with script.js loaded and its auto-bootstrap
 * neutralised. A minimal banner DOM with the two accept surfaces
 * (notice-buttons + detail-buttons) is provided so the injector has real
 * containers to render into. `ageGate` seeds _fazStore._ageGate.
 */
function loadFrontend(ageGate) {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  // The notice surface lives inside a .faz-consent-container so _fazGetBanner()
  // resolves and _fazShowBanner()/_fazHideBanner() can toggle its faz-hide state
  // — needed to exercise the out-of-banner re-reveal branch.
  const html = `<!DOCTYPE html><html><body>
    <div id="faz-consent">
      <div class="faz-consent-container">
        <div data-faz-tag="notice"></div>
        <div class="faz-notice-btn-wrapper" data-faz-tag="notice-buttons"></div>
      </div>
      <div class="faz-modal">
        <div class="faz-prefrence-btn-wrapper" data-faz-tag="detail-buttons"></div>
      </div>
    </div>
  </body></html>`;
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
  const { window } = dom;

  // A minimal store: the age gate config under test, one non-necessary
  // category so _fazAcceptCookies has something to iterate, and the state the
  // accept path reads. The REAL consent store (ref._fazConsentStore, a Map)
  // works in jsdom — cookie writes go to jsdom's document.cookie — so no store
  // stubbing is needed; ref is a `const` and unreachable from a separate eval.
  window._fazConfig = {
    _ageGate: ageGate,
    _categories: [
      { slug: 'necessary', isNecessary: true, defaultConsent: { gdpr: true, ccpa: true }, cookies: [] },
      { slug: 'analytics', isNecessary: false, defaultConsent: { gdpr: false, ccpa: false }, cookies: [] },
    ],
    _activeLaw: 'gdpr',
    _bannerSlug: 'gdpr',
    _expiry: 180,
    _i18n: {},
    _bannerConfig: { config: {}, behaviours: {} },
  };

  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(code);
  window.document.addEventListener = realAdd;

  return window;
}

console.log('youth / age-appropriate consent gate (GDPR Art. 8, jsdom)');

// ---------------------------------------------------------------------------
// (d) The checkbox renders unchecked, once per surface, above the buttons.
// ---------------------------------------------------------------------------
{
  const window = loadFrontend({ enabled: true, minAge: 16 });
  window.eval('_fazRenderAgeConfirmations()');
  const boxes = window.document.querySelectorAll('.faz-age-confirm-cb');
  check('renders one checkbox per accept surface (notice + detail)', boxes.length === 2);
  check('every checkbox renders UNCHECKED (no pre-check)', Array.prototype.every.call(boxes, (b) => b.checked === false));
  const noticeGroup = window.document.querySelector('[data-faz-tag="notice-buttons"]');
  const prev = noticeGroup.previousElementSibling;
  check('the row is inserted ABOVE the button group', !!prev && prev.classList.contains('faz-age-confirm'));
}

// ---------------------------------------------------------------------------
// (f) Re-injection is idempotent — a second render adds no duplicate row.
// ---------------------------------------------------------------------------
{
  const window = loadFrontend({ enabled: true, minAge: 16 });
  window.eval('_fazRenderAgeConfirmations()');
  window.eval('_fazRenderAgeConfirmations()'); // e.g. Rocket-Loader double init
  const rows = window.document.querySelectorAll('.faz-age-confirm');
  check('double render leaves exactly one row per surface (idempotent)', rows.length === 2);
}

// ---------------------------------------------------------------------------
// (a) Accept with no affirmation → returns false, no action written, error shown.
// ---------------------------------------------------------------------------
{
  const window = loadFrontend({ enabled: true, minAge: 16 });
  window.eval('_fazRenderAgeConfirmations()');
  let firedDetail = null;
  window.document.addEventListener('fazcookie_consent_update', (e) => { firedDetail = e.detail; });
  const result = window.eval('_fazAcceptCookies("all")');
  check('accept without affirmation returns false', result === false);
  check('no consent event fires when the gate blocks', firedDetail === null);
  const action = window.fazcookie._fazGetFromStore('action');
  check('no action:yes is written to the store when gated', action === undefined || action === '');
  const err = window.document.querySelector('.faz-age-confirm-error');
  check('the inline validation message is revealed (hidden attribute removed)', err && !err.hasAttribute('hidden'));
}

// ---------------------------------------------------------------------------
// (b) Tick then accept → returns true, action:yes, event carries ageAffirmed.
// ---------------------------------------------------------------------------
{
  const window = loadFrontend({ enabled: true, minAge: 16 });
  window.eval('_fazRenderAgeConfirmations()');
  let firedDetail = null;
  window.document.addEventListener('fazcookie_consent_update', (e) => { firedDetail = e.detail; });
  // Tick one surface's checkbox — _fazIsAgeAffirmed reads any checked box.
  const box = window.document.querySelector('.faz-age-confirm-cb');
  box.checked = true;
  box.dispatchEvent(new window.Event('change', { bubbles: true }));
  const result = window.eval('_fazAcceptCookies("all")');
  check('accept after affirmation returns true', result === true);
  check('the store records action:yes', window.fazcookie._fazGetFromStore('action') === 'yes');
  check('the consent event carries detail.ageAffirmed === true', firedDetail && firedDetail.ageAffirmed === true);
  // Ticking one surface mirrors onto the other (single affirmation covers both).
  const boxes = window.document.querySelectorAll('.faz-age-confirm-cb');
  check('ticking one checkbox mirrors onto the other surface', Array.prototype.every.call(boxes, (b) => b.checked === true));
}

// ---------------------------------------------------------------------------
// (c) Reject with the gate on and no affirmation → ungated, records rejection.
// ---------------------------------------------------------------------------
{
  const window = loadFrontend({ enabled: true, minAge: 16 });
  window.eval('_fazRenderAgeConfirmations()');
  let firedDetail = null;
  window.document.addEventListener('fazcookie_consent_update', (e) => { firedDetail = e.detail; });
  const result = window.eval('_fazAcceptCookies("reject")');
  check('reject is never gated (returns true without affirmation)', result === true);
  check('reject records action:yes / consent:no', window.fazcookie._fazGetFromStore('action') === 'yes' && window.fazcookie._fazGetFromStore('consent') === 'no');
  check('reject does NOT set ageAffirmed on the event', firedDetail && firedDetail.ageAffirmed !== true);
}

// ---------------------------------------------------------------------------
// (e) minAge falls back to 16 when _ageGate omits it.
// ---------------------------------------------------------------------------
{
  const window = loadFrontend({ enabled: true }); // no minAge
  const minAge = window.eval('_fazAgeGateMinAge()');
  check('minAge falls back to 16 when absent', minAge === 16);
  window.eval('_fazRenderAgeConfirmations()');
  const label = window.document.querySelector('.faz-age-confirm-label');
  check('the fallback label mentions the default age 16', label && label.textContent.indexOf('16') !== -1);
}

// ---------------------------------------------------------------------------
// (g) Gated accept from a blocked-embed placeholder AFTER the banner is hidden
//     → the banner is re-revealed so a reachable age checkbox exists (no
//     dead-end: an invisible error on a non-interactable checkbox would leave
//     the visitor with no control to affirm their age). Regression guard for
//     the out-of-banner accept path (_fazAcceptCategory / _fazAcceptService).
// ---------------------------------------------------------------------------
{
  const window = loadFrontend({ enabled: true, minAge: 16 });
  window.eval('_fazRenderAgeConfirmations()');
  // Simulate the post-dismiss state: the banner is hidden (faz-hide) while its
  // injected checkbox stays in the DOM but non-interactable. A placeholder
  // click now drives a gated accept via the public _fazAcceptCategory entry.
  const container = window.document.querySelector('.faz-consent-container');
  container.classList.add('faz-hide');
  window.eval('window._fazAcceptCategory("analytics")');
  const action = window.fazcookie._fazGetFromStore('action');
  check('placeholder accept is gated — no action written', action === undefined || action === '');
  check('the hidden banner is re-revealed so the age checkbox is reachable', !container.classList.contains('faz-hide'));
  const err = window.document.querySelector('.faz-age-confirm-error');
  check('the validation message is shown after a gated placeholder accept', err && !err.hasAttribute('hidden'));
}

// ---------------------------------------------------------------------------
// (h) The CCPA "Do Not Sell" opt-out is an UNGATED accept: an opt-out is a
//     withdrawal/rejection of sale and must never sit behind the age gate.
//     _fazHandleOptoutConfirm() calls _fazAcceptCookies("custom", true).
// ---------------------------------------------------------------------------
{
  const window = loadFrontend({ enabled: true, minAge: 16 });
  window.eval('_fazRenderAgeConfirmations()');
  const result = window.eval('_fazAcceptCookies("custom", true)');
  check('an ungated opt-out is NOT blocked by the age gate (returns true)', result === true);
  check('the ungated opt-out records action:yes without affirmation', window.fazcookie._fazGetFromStore('action') === 'yes');
  const err = window.document.querySelector('.faz-age-confirm-error');
  check('no validation error is shown for the ungated opt-out', err && err.hasAttribute('hidden'));
}

// ---------------------------------------------------------------------------
// Gate disabled → no row rendered, accept is ungated.
// ---------------------------------------------------------------------------
{
  const window = loadFrontend({ enabled: false, minAge: 16 });
  window.eval('_fazRenderAgeConfirmations()');
  check('no row renders when the gate is disabled', window.document.querySelectorAll('.faz-age-confirm').length === 0);
  check('accept is ungated when the gate is disabled', window.eval('_fazAcceptCookies("all")') === true);
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
