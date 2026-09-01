/**
 * JS unit test (jsdom) — each preference trigger must name the panel IT opens.
 *
 * On a "Both" banner the settings button opens `detail` and the Do-Not-Sell
 * button opens `optout-popup`. An earlier version handed both triggers the id of
 * whichever panel happened to be active, so one of them named a dialog it does
 * not control until its own first click.
 *
 * Pinned here rather than in the a11y E2E spec on purpose: the shipped default
 * banner has donotSell and optoutPopup DISABLED, so the Do-Not-Sell trigger is
 * not in the DOM at all and a regression in that pairing cannot fail any
 * end-to-end assertion. Reproducing it there would mean reconfiguring the shared
 * banner fixture for one case; in jsdom the "Both" DOM can be built exactly.
 *
 * Run: node tests/unit/js/preference-trigger-aria-links.test.mjs
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
    console.log(`       expected: ${JSON.stringify(expected)}  actual: ${JSON.stringify(actual)}`);
  }
}

/**
 * Build a banner DOM and run the real linker.
 *
 * @param {object} opts
 * @param {boolean} opts.detail  render the GDPR detail panel
 * @param {boolean} opts.optout  render the CCPA opt-out panel
 * @param {string}  opts.law     active law
 */
function link({ detail = true, optout = true, law = 'gdpr' } = {}) {
  const panels =
    (detail ? '<div data-faz-tag="detail" class="faz-preference-center">detail</div>' : '') +
    (optout ? '<div data-faz-tag="optout-popup" class="faz-optout-popup">opt-out</div>' : '');
  const dom = new JSDOM(
    `<!DOCTYPE html><html><body>
       <div class="faz-consent-container" id="faz-consent">
         <div class="faz-consent-bar" data-faz-tag="notice">
           <button data-faz-tag="settings-button">settings</button>
           <button data-faz-tag="donotsell-button">do not sell</button>
         </div>
         <div class="faz-modal">${panels}</div>
       </div>
     </body></html>`,
    { runScripts: 'outside-only', url: 'http://localhost/' },
  );
  const { window } = dom;
  window._fazConfig = {
    _categories: [{ slug: 'necessary', isNecessary: true }],
    _services: [], _providersToBlock: [], _userWhitelist: [], i18n: {},
    _bannerConfig: {
      settings: { type: 'box', preferenceCenterType: 'popup', applicableLaw: law },
      config: {},
    },
  };
  Object.defineProperty(window.document, 'readyState', { get: () => 'loading', configurable: true });
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (t, cb, ...r) => (t === 'DOMContentLoaded' ? undefined : realAdd(t, cb, ...r));
  window.setTimeout = () => 0;
  window.requestAnimationFrame = (cb) => { cb(); return 0; };
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.addEventListener = realAdd;
  window.fazcookie._fazLinkPreferenceTriggers();

  const read = (tag) => {
    const btn = window.document.querySelector(`[data-faz-tag="${tag}"]`);
    const id = btn ? btn.getAttribute('aria-controls') : null;
    const target = id ? window.document.getElementById(id) : null;
    return {
      controls: id,
      haspopup: btn ? btn.getAttribute('aria-haspopup') : null,
      targetTag: target ? target.getAttribute('data-faz-tag') : null,
    };
  };
  return { settings: read('settings-button'), donotsell: read('donotsell-button') };
}

function run() {
  // 1. "Both": each trigger must resolve to ITS OWN panel, not to a shared one.
  {
    const r = link({ detail: true, optout: true });
    eq('both: settings-button resolves to the detail panel', r.settings.targetTag, 'detail');
    eq('both: donotsell-button resolves to the opt-out panel', r.donotsell.targetTag, 'optout-popup');
    eq('both: the two triggers do NOT share one id', r.settings.controls === r.donotsell.controls, false);
    eq('both: settings advertises a dialog', r.settings.haspopup, 'dialog');
    eq('both: donotsell advertises a dialog', r.donotsell.haspopup, 'dialog');
  }

  // 2. GDPR-only: no opt-out panel exists, so its trigger must be left alone
  //    rather than pointed at a missing id — a dangling aria-controls is worse
  //    for a screen reader than no relationship at all.
  {
    const r = link({ detail: true, optout: false });
    eq('gdpr-only: settings still resolves to detail', r.settings.targetTag, 'detail');
    eq('gdpr-only: donotsell gets no dangling aria-controls', r.donotsell.controls, null);
  }

  // 3. CCPA-only: the mirror case.
  {
    const r = link({ detail: false, optout: true, law: 'ccpa' });
    eq('ccpa-only: donotsell resolves to the opt-out panel', r.donotsell.targetTag, 'optout-popup');
    eq('ccpa-only: settings gets no dangling aria-controls', r.settings.controls, null);
  }

  console.log(`\n  preference-trigger-aria-links: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
