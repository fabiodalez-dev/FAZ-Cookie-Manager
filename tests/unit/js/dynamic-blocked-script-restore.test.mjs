/** Scripts this runtime parked in place are reconsidered on consent (#349).
 *
 * The bootstrap handoff (and the createElement guard, when the observer lets a
 * script through) parks a script IN the document as
 * type="javascript/blocked" + data-faz-blocked-by-us, without data-fazcookie:
 * a dynamically inserted GTM loader (GTM4WP) is the reported case. The restore
 * selector only matched javascript/blocked together with data-fazcookie, so
 * such a script was never queued and stayed dead after consent. The marker
 * must trigger re-evaluation only: the permission check still decides.
 *
 * FAZ_SCRIPT_PATH allows verification against the previous implementation.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = process.env.FAZ_SCRIPT_PATH
  || resolve(HERE, '../../../frontend/js/script.js');

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

const GTM = 'https://www.googletagmanager.com/gtm.js?id=GTM-TEST';

/** Load the real script.js with its DOMContentLoaded bootstrap neutralised. */
function loadFrontend(analytics) {
  const code = readFileSync(SCRIPT_PATH, 'utf8');
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _activeLaw: 'gdpr',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'analytics', isNecessary: false },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [{ re: 'googletagmanager.com/gtm.js', categories: ['analytics'] }],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
  window.fazcookie = {};
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => {
    if (type === 'DOMContentLoaded') return undefined;
    return realAdd(type, ...rest);
  };
  // window.eval is the harness loading the plugin's own source into the jsdom
  // realm. Script loading/execution is controlled by the test harness.
  window.eval(code);
  window.document.addEventListener = realAdd;
  window.fazcookie._fazConsentStore.set('consent', 'yes');
  window.fazcookie._fazConsentStore.set('analytics', analytics);
  window.fazcookie._fazConsentStore.set('marketing', 'no');
  return window;
}

/**
 * A script parked in place the way the bootstrap handoff leaves it. Built from
 * parsed markup, not the runtime's guarded createElement: the guard's own src
 * setter would release an allowed script at creation and hide the restore
 * pass under test.
 */
function parked(w, id, attrs, text) {
  const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
  const attrHtml = Object.entries(attrs).map(([k, v]) => ` ${k}="${esc(v)}"`).join('');
  w.document.body.insertAdjacentHTML('beforeend', `<script id="${id}"${attrHtml}>${text || ''}</script>`);
  return () => w.document.getElementById(id);
}

{
  const w = loadFrontend('yes');
  const get = parked(w, 'gtm', { type: 'javascript/blocked', 'data-faz-blocked-by-us': '1', src: GTM });
  w._fazUnblockServerSide();
  check('an allowed marked script without data-fazcookie is restored', get().type === 'text/javascript');
  check('the restored script keeps its src', get().getAttribute('src') === GTM);
  check('the restored script no longer carries the parking marker', !get().hasAttribute('data-faz-blocked-by-us'));
  const restored = get();
  restored.dispatchEvent(new w.Event('load'));
  w._fazUnblockServerSide();
  check('a repeated consent update does not insert it twice', get() === restored
    && w.document.querySelectorAll('script[src*="gtm.js"]').length === 1);
  w.close();
}
{
  const w = loadFrontend('no');
  const get = parked(w, 'gtm', { type: 'javascript/blocked', 'data-faz-blocked-by-us': '1', src: GTM });
  w._fazUnblockServerSide();
  check('a denied marked script stays blocked', get().type === 'javascript/blocked');
  w.fazcookie._fazConsentStore.set('analytics', 'yes');
  w._fazUnblockServerSide();
  check('denied -> granted releases it without a reload', get().type === 'text/javascript');
  w.close();
}
{
  // The bootstrap parks a consent-tagged script with its category kept.
  const w = loadFrontend('yes');
  const get = parked(w, 'tagged', {
    type: 'javascript/blocked', 'data-faz-blocked-by-us': '1', 'data-faz-category': 'marketing',
  }, 'window.__tagged = true;');
  w._fazUnblockServerSide();
  check('a marked script tagged with a denied category stays blocked', get().type === 'javascript/blocked');
  w.fazcookie._fazConsentStore.set('marketing', 'yes');
  w._fazUnblockServerSide();
  check('and is released once that category is granted', get().type === 'text/javascript');
  w.close();
}
{
  const w = loadFrontend('yes');
  const get = parked(w, 'mod', {
    type: 'javascript/blocked', 'data-faz-blocked-by-us': '1', 'data-faz-original-type': 'module', src: GTM,
  });
  w._fazUnblockServerSide();
  check('the original type saved at parking time is restored', get().type === 'module');
  w.close();
}
{
  // Another tool's javascript/blocked is not ours to release.
  const w = loadFrontend('yes');
  const get = parked(w, 'foreign', { type: 'javascript/blocked', src: 'https://example.test/other.js' });
  w._fazUnblockServerSide();
  check('a javascript/blocked script without our marker is left alone', get().type === 'javascript/blocked');
  w.close();
}
{
  // The existing paths keep working.
  const w = loadFrontend('yes');
  const a = parked(w, 'legacy', { type: 'javascript/blocked', 'data-fazcookie': 'fazcookie-analytics' }, 'window.__legacy = 1;');
  const b = parked(w, 'server', { type: 'text/plain', 'data-faz-category': 'analytics' }, 'window.__server = 1;');
  w._fazUnblockServerSide();
  check('data-fazcookie restoration still works', a().type === 'text/javascript');
  check('server-parked text/plain restoration still works', b().type === 'text/javascript');
  w.close();
}

console.log(`\ndynamic-blocked-script-restore: ${passed} passed, ${failed} failed`);
if (failed) process.exit(1);
