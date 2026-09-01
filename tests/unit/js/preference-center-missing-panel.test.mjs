/**
 * JS unit test (jsdom) — _fazShowPreferenceCenter() must not report success
 * when the panel the trigger asked for is not on the page.
 *
 * The `classic` template is the only one that ships no `optout-popup`, and it
 * also forces `pushdown`. In pushdown, _fazGetPreferenceCenter() answers with
 * the banner CONTAINER — which always exists — so on a CCPA banner the function
 * returned true while opening nothing the visitor asked for. Reporting success
 * is what did the damage: the [faz_cookie_settings] handler only falls back to
 * _fazShowBanner() when it gets false, so the button looked dead again, which
 * is the whole subject of #253.
 *
 * This is an E2E-hostile case — it needs a classic + CCPA banner with a
 * recorded consent — so it is pinned here, where the DOM can be built exactly.
 *
 * Run: node tests/unit/js/preference-center-missing-panel.test.mjs
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

/**
 * @param {object}  opts
 * @param {string}  opts.law           'ccpa' | 'gdpr'
 * @param {boolean} opts.withOptout    render the optout-popup panel
 */
function boot({ law = 'ccpa', withOptout = false } = {}) {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  // A classic banner: the preference wrapper is EMBEDDED in the container, and
  // the container is what _fazGetPreferenceCenter() returns under pushdown.
  const optout = withOptout ? '<div data-faz-tag="optout-popup" class="faz-optout-popup">opt-out</div>' : '';
  const dom = new JSDOM(
    `<!DOCTYPE html><html><body>
       <div class="faz-consent-container" id="faz-consent">
         <div class="faz-consent-bar" data-faz-tag="notice">
           <button data-faz-tag="settings-button">settings</button>
         </div>
         <div class="faz-preference-wrapper">
           <div data-faz-tag="detail" class="faz-preference-center">detail</div>
           ${optout}
         </div>
       </div>
     </body></html>`,
    { runScripts: 'outside-only', url: 'http://localhost/' },
  );
  const { window } = dom;

  window._fazConfig = {
    _categories: [{ slug: 'necessary', isNecessary: true }],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    i18n: {},
    // script.js aliases the store to window._fazConfig itself (`const _fazStore
    // = window._fazConfig`), and the banner shape lives under _bannerConfig —
    // normally written during init, which this harness skips on purpose.
    // _fazGetLaw() reads settings.applicableLaw, NOT config.applicableLaw —
    // putting it on `config` made the CCPA case resolve as GDPR and the test
    // reported a pass for the wrong reason on the first run.
    _bannerConfig: {
      settings: { type: 'classic', preferenceCenterType: 'pushdown', applicableLaw: law },
      config: {},
    },
  };

  Object.defineProperty(window.document, 'readyState', { get: () => 'loading', configurable: true });
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, cb, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, cb, ...rest);
  };
  window.setTimeout = () => 0;
  // jsdom ships no rAF; the success path focuses into the opened panel through
  // it. Run the callback synchronously so the happy cases complete.
  window.requestAnimationFrame = (cb) => { cb(); return 0; };
  window.cancelAnimationFrame = () => {};
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(code);
  window.document.addEventListener = realAdd;
  return window;
}

function run() {
  // 1. CCPA + classic (no optout-popup) -> must report FAILURE so the caller
  //    can fall back to re-showing the banner.
  {
    const window = boot({ law: 'ccpa', withOptout: false });
    const fn = window.fazcookie && window.fazcookie._fazShowPreferenceCenter;
    if (typeof fn !== 'function') {
      console.log('  \x1b[31mFAIL\x1b[0m _fazShowPreferenceCenter is not exposed for the test');
      failed += 1;
    } else {
      const banner = window.document.getElementById('faz-consent');
      banner.classList.add('faz-hide');
      eq('CCPA on a classic banner: the missing opt-out panel is reported, not claimed as opened', fn(), false);
      eq('the failed open leaves the hidden banner hidden for the caller fallback', banner.classList.contains('faz-hide'), true);
      eq('the failed open does not mark an absent panel expanded', banner.classList.contains('faz-consent-bar-expand'), false);
    }
  }

  // 2. The same banner WITH the panel present -> must still succeed. Guards the
  //    other direction: a check that only ever returns false would also pass
  //    case 1.
  {
    const window = boot({ law: 'ccpa', withOptout: true });
    const fn = window.fazcookie._fazShowPreferenceCenter;
    eq('CCPA with the opt-out panel present: still opens', fn(), true);
  }

  // 3. GDPR resolves to `detail`, which every template carries — the guard must
  //    never fire here, or classic + GDPR stops opening at all.
  {
    const window = boot({ law: 'gdpr', withOptout: false });
    const fn = window.fazcookie._fazShowPreferenceCenter;
    eq('GDPR on the same classic banner: unaffected', fn(), true);
  }

  console.log(`\n  preference-center-missing-panel: ${passed} passed, ${failed} failed`);
  if (failed > 0) process.exit(1);
}

run();
