/**
 * ping.displayStatus must describe the banner that is actually on screen.
 *
 * `fazcookie_banner_loaded` is a ONE-SHOT event: script.js fires it once, when
 * it reveals the banner. tcf-cmp.js only ever learned the banner was up by
 * listening for it, and it is enqueued with script.js as a dependency — so
 * since the runtime moved to ordered `defer`, script.js can find the DOM
 * already parsed, reveal the banner and fire the event during its own
 * execution, before tcf-cmp.js has run at all. The listener is then registered
 * for an event that will never come again, and the CMP answers `hidden` for a
 * banner the visitor is looking at, for the rest of the page view.
 *
 * That is a TCF API violation and not a cosmetic one: `displayStatus` is what a
 * vendor polls to learn the UI is up, and `cmpuishown` is the event it waits
 * for. Both were lost. script.js already records `window._fazBannerLoaded`
 * before dispatching, with a comment saying it is there so that late-running
 * enhancers can recover the state; nothing was reading it.
 *
 * Measured against the deployed build before the fix: banner visible, flag
 * true, `ping` → hidden.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT_PATH = resolve(HERE, '../../../frontend/js/tcf-cmp.js');
const CODE = readFileSync(SCRIPT_PATH, 'utf8');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) { console.log(`  \x1b[32mPASS\x1b[0m ${label}`); passed++; }
  else { console.log(`  \x1b[31mFAIL\x1b[0m ${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`); failed++; }
}

/**
 * Boot the real CMP. `bannerUpBefore` reproduces the deferred-load ordering:
 * the banner is revealed and the one-shot event fired BEFORE the CMP script
 * runs, exactly as ordered defer makes possible.
 */
function boot({ bannerUpBefore = false } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  window._fazTcfConfig = {
    cmpId: 400, cmpVersion: 1, gvlVersion: 100,
    vendors: [], consentRevision: 1, purposeOneTreatment: false,
  };
  window.console.error = () => {};
  window.console.warn = () => {};

  if (bannerUpBefore) {
    // Precisely what script.js does, in this order.
    window._fazBannerLoaded = true;
    window.document.dispatchEvent(new window.CustomEvent('fazcookie_banner_loaded'));
  }

  window.eval(CODE);
  return window;
}

const ping = (w) => {
  let out = null;
  w.__tcfapi('ping', 2, (d) => { out = d; });
  return out;
};

console.log('\nping.displayStatus follows the banner, not the load order');

// 1. The deferred-load ordering: the event is gone before the CMP exists.
{
  const w = boot({ bannerUpBefore: true });
  // Premise, asserted: the state script.js leaves behind really is there, so a
  // pass below cannot come from the flag being absent and the check skipped.
  eq('premise: the runtime recorded _fazBannerLoaded before dispatching', w._fazBannerLoaded, true);
  eq('a banner revealed before the CMP loaded reads as visible', ping(w).displayStatus, 'visible');
}

// 2. Registration still reports `tcloaded`, and that is correct: the events
//    describe TRANSITIONS, and a listener added after the reveal missed that
//    one. `ping` is the documented way to ask what the UI is doing now, and it
//    is the thing that was lying. Reporting cmpuishown on registration instead
//    broke T03c and T09 of the IAB compliance suite, which pin this on purpose.
{
  const w = boot({ bannerUpBefore: true });
  const events = [];
  w.__tcfapi('addEventListener', 2, (data) => { if (data && data.eventStatus) events.push(data.eventStatus); });
  eq('registration reports tcloaded, not the UI transition it missed', events[0], 'tcloaded');
  eq('and ping, which asks about now, says the UI is up', ping(w).displayStatus, 'visible');
}

// 3. The ordinary ordering still works — the listener path is not replaced.
{
  const w = boot();
  eq('before the banner is revealed it reads as hidden', ping(w).displayStatus, 'hidden');
  w.document.dispatchEvent(new w.CustomEvent('fazcookie_banner_loaded'));
  eq('the live event still marks it visible', ping(w).displayStatus, 'visible');
}

// 4. A recorded decision closes the UI again, whichever way it was opened.
{
  const w = boot({ bannerUpBefore: true });
  w.document.dispatchEvent(new w.CustomEvent('fazcookie_consent_update', { detail: { action: 'yes' } }));
  eq('a consent decision marks it hidden again', ping(w).displayStatus, 'hidden');
}

// 5. The init event is not a decision and must not close the UI.
{
  const w = boot({ bannerUpBefore: true });
  w.document.dispatchEvent(new w.CustomEvent('fazcookie_consent_update', { detail: { action: 'init' } }));
  eq('an init event leaves it visible', ping(w).displayStatus, 'visible');
}

console.log(`\ntcf-display-status: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
