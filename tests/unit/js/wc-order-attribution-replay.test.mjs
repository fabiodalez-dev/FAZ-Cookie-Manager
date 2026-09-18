/**
 * JS unit test (jsdom) — WooCommerce Order Attribution after consent.
 *
 * WooCommerce's order-attribution.js runs on page load and calls
 * setOrderTracking( params.allowTracking ). When sourcebuster.js (window.sbjs)
 * is not there yet, that call records `params.allowTracking = allow` and
 * returns without initialising anything. The consent-change event WooCommerce
 * listens to (wp_listen_for_consent_change, or a plain page load without the
 * WP Consent API) is dispatched synchronously, while FAZ restores the blocked
 * sourcebuster.js by inserting a new <script src>, which executes later. So
 * on the page where the visitor accepts, WooCommerce asks for tracking before
 * the library exists, and nothing asks again once it does: no sbjs_* cookies,
 * the landing page's UTM parameters are lost, and the order is attributed to
 * "Unknown". Reported on the wordpress.org forum against 1.31.0.
 *
 * The fix replays WooCommerce's own call once the restored script has loaded,
 * and only when WooCommerce itself already decided to allow tracking — FAZ
 * never overrides that decision, it just re-runs it when the dependency lands.
 *
 * Loads the REAL frontend/js/script.js with its DOMContentLoaded bootstrap
 * neutralised, the same harness as img-iframe-src-gate.test.mjs.
 *
 * Run: node tests/unit/js/wc-order-attribution-replay.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/script.js');
const SBJS_SRC = 'https://shop.example/wp-content/plugins/woocommerce/assets/js/sourcebuster/sourcebuster.min.js';
const OTHER_SRC = 'https://www.googletagmanager.com/gtag/js?id=G-TEST';

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

function loadFrontend() {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'https://shop.example/landing/?utm_source=test',
  });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
  window.fazcookie = { _fazGetFromStore: () => undefined };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  window.eval(code);
  window.document.addEventListener = realAdd;
  return window;
}

// A stand-in for WooCommerce's wc_order_attribution: records every call and
// mirrors the real early return (allowTracking is stored, nothing else happens
// while sbjs is missing).
function installWooCommerce(w, allowTracking) {
  const calls = [];
  w.wc_order_attribution = {
    params: { allowTracking },
    setOrderTracking(allow) {
      this.params.allowTracking = allow;
      calls.push({ allow, sbjsPresent: typeof w.sbjs !== 'undefined' });
    },
  };
  return calls;
}

// Restore a server-blocked <script src> the way _fazUnblockServerSide does and
// return the clone, so a test can decide when it "loads".
function restore(w, src) {
  const blocked = w.document.createElement('script');
  blocked.setAttribute('type', 'text/plain');
  blocked.setAttribute('data-faz-category', 'marketing');
  blocked.setAttribute('src', src);
  w.document.body.appendChild(blocked);
  return w.eval('_fazBuildRestoredScript')(blocked);
}
function fireLoad(w, el) {
  el.dispatchEvent(new w.Event('load'));
}

console.log('WooCommerce Order Attribution replay after consent (jsdom)');

// 1. The reported case: WooCommerce already asked for tracking, sourcebuster
//    arrives afterwards → WooCommerce's call is replayed exactly once.
{
  const w = loadFrontend();
  const calls = installWooCommerce(w, true);
  const clone = restore(w, SBJS_SRC);
  w.sbjs = { init() {} };
  fireLoad(w, clone);
  eq('allowTracking true + sourcebuster lands → setOrderTracking replayed once', calls.length, 1);
  eq('…with allow = true', calls[0] && calls[0].allow, true);
  eq('…after sbjs exists', calls[0] && calls[0].sbjsPresent, true);
}

// 2. WooCommerce decided NOT to track (no marketing consent in the WP Consent
//    API, or the site owner's wc_order_attribution_allow_tracking filter) →
//    FAZ must not override it.
{
  const w = loadFrontend();
  const calls = installWooCommerce(w, false);
  const clone = restore(w, SBJS_SRC);
  w.sbjs = { init() {} };
  fireLoad(w, clone);
  eq('allowTracking false → no replay (WooCommerce decides, not FAZ)', calls.length, 0);
}

// 3. sourcebuster was already on the page before this restore (return visit,
//    not blocked) → WooCommerce initialised it itself; replaying would count a
//    second page view in sbjs_session.
{
  const w = loadFrontend();
  const calls = installWooCommerce(w, true);
  w.sbjs = { init() {} };
  const clone = restore(w, SBJS_SRC);
  fireLoad(w, clone);
  eq('sbjs already present before the restore → no replay', calls.length, 0);
}

// 4. Several restored scripts load after sourcebuster → replay only once.
{
  const w = loadFrontend();
  const calls = installWooCommerce(w, true);
  const a = restore(w, OTHER_SRC);
  const b = restore(w, SBJS_SRC);
  const c = restore(w, OTHER_SRC + '&x=2');
  w.sbjs = { init() {} };
  fireLoad(w, a);
  fireLoad(w, b);
  fireLoad(w, c);
  eq('three restored scripts load after sbjs → exactly one replay', calls.length, 1);
}

// 5. A restored script loads BEFORE sourcebuster does → nothing yet; the replay
//    still happens when sourcebuster itself lands.
{
  const w = loadFrontend();
  const calls = installWooCommerce(w, true);
  const other = restore(w, OTHER_SRC);
  const sb = restore(w, SBJS_SRC);
  fireLoad(w, other);
  eq('unrelated script loads first (no sbjs yet) → no replay yet', calls.length, 0);
  w.sbjs = { init() {} };
  fireLoad(w, sb);
  eq('…then sourcebuster lands → replayed once', calls.length, 1);
}

// 6. No WooCommerce on the page → nothing happens, nothing throws.
{
  const w = loadFrontend();
  const clone = restore(w, SBJS_SRC);
  w.sbjs = { init() {} };
  let threw = false;
  try { fireLoad(w, clone); } catch (e) { threw = true; }
  eq('no wc_order_attribution on the page → no error', threw, false);
}

// 7. WooCommerce's setOrderTracking throwing must not break FAZ.
{
  const w = loadFrontend();
  w.wc_order_attribution = { params: { allowTracking: true }, setOrderTracking() { throw new Error('boom'); } };
  const clone = restore(w, SBJS_SRC);
  w.sbjs = { init() {} };
  let threw = false;
  try { fireLoad(w, clone); } catch (e) { threw = true; }
  eq('a throwing setOrderTracking is contained', threw, false);
}

// 8. Which consent unlocks sourcebuster. WooCommerce gates order attribution on
//    the WP Consent API's `marketing` category (WPConsentAPI::$consent_category);
//    blocking the library under `analytics` meant a visitor who accepted only
//    marketing never got attribution, and one who accepted only analytics
//    loaded a library WooCommerce then refused to start. The glue script stays
//    `necessary`: without sourcebuster it has nothing to initialise.
{
  const providers = JSON.parse(readFileSync(resolve(HERE, '../../../includes/data/known-providers.json'), 'utf8'));
  const template = JSON.parse(readFileSync(resolve(HERE, '../../../admin/modules/cookies/includes/blocker-templates/sourcebuster.json'), 'utf8'));
  eq('known provider sourcebuster is gated on marketing', providers.sourcebuster && providers.sourcebuster.category, 'marketing');
  eq('blocker template sourcebuster is gated on marketing', template.category, 'marketing');
  eq('WooCommerce Order Attribution glue stays necessary', providers['woocommerce-attribution'] && providers['woocommerce-attribution'].category, 'necessary');
}

console.log(`\n${failed === 0 ? '\x1b[32m' : '\x1b[31m'}${passed} passed, ${failed} failed\x1b[0m`);
process.exit(failed === 0 ? 0 : 1);
