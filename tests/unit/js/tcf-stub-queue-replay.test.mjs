/**
 * Commands queued on the pre-CMP `__tcfapi` stub must be executed when the
 * CMP loads.
 *
 * class-frontend.php prints an inline stub in <head> so that ad scripts can
 * call `__tcfapi` during the HTML parse, while tcf-cmp.js itself is enqueued
 * deferred behind script.js. The stub answers `ping` itself and pushes every
 * other call onto `__tcfapi.a` — as its `arguments` object, exactly like the
 * IAB reference stub. The IAB TCF v2 CMP API requires the CMP to run that
 * queue once it replaces the stub: a vendor that registered a listener early
 * must receive `tcloaded`, and later `useractioncomplete` when the visitor
 * decides.
 *
 * The replay loop in tcf-cmp.js guarded each entry with Array.isArray(). An
 * `arguments` object is `[object Arguments]`, not an Array, so every queued
 * command was silently dropped: no callback, no listener registration, no TC
 * string ever delivered to that vendor. The stub and the replay are two layers
 * of one contract, so this test uses the stub text extracted verbatim from
 * class-frontend.php rather than a hand-written imitation.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const CMP_CODE = readFileSync(resolve(HERE, '../../../frontend/js/tcf-cmp.js'), 'utf8');
const PHP = readFileSync(resolve(HERE, '../../../frontend/class-frontend.php'), 'utf8');

let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) { console.log(`  \x1b[32mPASS\x1b[0m ${label}`); passed++; }
  else { console.log(`  \x1b[31mFAIL\x1b[0m ${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`); failed++; }
}

// The stub is a single-quoted PHP literal assigned to $tcf_stub.
const stubMatch = PHP.match(/\$tcf_stub = '((?:[^'\\]|\\.)*)';/);
if (!stubMatch) {
  console.log('  \x1b[31mFAIL\x1b[0m the __tcfapi stub was not found in class-frontend.php');
  process.exit(1);
}
const STUB_CODE = stubMatch[1];

/**
 * Boot a page the way WordPress orders it: stub inline in <head>, then the
 * caller queues commands (a vendor tag during the parse), then the deferred
 * CMP runs. `beforeCmp` receives the window while only the stub is installed.
 */
function boot(beforeCmp) {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
    runScripts: 'outside-only',
    url: 'http://localhost/',
  });
  const { window } = dom;
  window._fazTcfConfig = {
    cmpId: 400, cmpVersion: 1, gvlVersion: 100,
    vendors: {}, selectedVendors: [], consentRevision: 1, purposeOneTreatment: false,
  };
  window.console.error = () => {};
  window.console.warn = () => {};

  window.eval(STUB_CODE);
  beforeCmp(window);
  window.eval(CMP_CODE);
  return window;
}

function acceptAll(window) {
  window.document.cookie = 'fazcookie-consent=' + encodeURIComponent(
    'consentid:abc,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,performance:yes,marketing:yes'
  );
  window.document.dispatchEvent(new window.CustomEvent('fazcookie_consent_update', {
    detail: { action: 'all', accepted: ['necessary', 'functional', 'analytics', 'performance', 'marketing'], rejected: [] },
  }));
}

console.log('\n__tcfapi stub queue is replayed by the CMP (stub extracted from class-frontend.php)');

// 1. The stub's own contract, asserted so a pass below cannot come from a
//    queue that was never filled.
{
  let pingBefore = null;
  let queueLength = -1;
  let entryIsArray = null;
  let entryTag = '';
  boot((w) => {
    w.__tcfapi('ping', 2, (d) => { pingBefore = d; });
    w.__tcfapi('addEventListener', 2, () => {});
    w.__tcfapi('getTCData', 2, () => {});
    queueLength = w.__tcfapi.a.length;
    entryIsArray = Array.isArray(w.__tcfapi.a[0]);
    entryTag = Object.prototype.toString.call(w.__tcfapi.a[0]);
  });
  eq('premise: the stub answers ping itself with cmpStatus=stub', pingBefore && pingBefore.cmpStatus, 'stub');
  eq('premise: ping is not queued, the two other commands are', queueLength, 2);
  eq('premise: the stub queues its arguments object, which is not an Array', entryIsArray, false);
  eq('premise: the queued entry is [object Arguments]', entryTag, '[object Arguments]');
}

// 2. A listener registered through the stub is served when the CMP loads,
//    and keeps receiving events afterwards.
{
  const events = [];
  let tcDataFromQueue = null;
  const w = boot((win) => {
    win.__tcfapi('addEventListener', 2, (data, success) => {
      if (success && data && data.eventStatus) events.push(data.eventStatus);
    });
    win.__tcfapi('getTCData', 2, (data, success) => { if (success) tcDataFromQueue = data; });
  });
  eq('a queued addEventListener receives tcloaded once the CMP has loaded', events[0], 'tcloaded');
  eq('a queued getTCData receives TCData', !!(tcDataFromQueue && typeof tcDataFromQueue.tcString === 'string'), true);
  eq('the queued getTCData answer reports the loaded CMP', tcDataFromQueue && tcDataFromQueue.cmpId, 400);

  acceptAll(w);
  eq('the queued listener is registered, so the decision reaches it as useractioncomplete', events[events.length - 1], 'useractioncomplete');
  eq('exactly one registration event and one decision event were delivered', events.join(','), 'tcloaded,useractioncomplete');
}

// 3. A queued ping that the stub could not answer (queued after the stub was
//    bypassed, e.g. a 4-argument call) is still answered from the loaded CMP.
{
  let pingAfter = null;
  boot((win) => {
    // Push an entry straight onto the queue, the way a stub variant that
    // queues every command would.
    win.__tcfapi.a.push(['ping', 2, (d) => { pingAfter = d; }]);
  });
  eq('an Array-shaped queue entry is still replayed', pingAfter && pingAfter.cmpStatus, 'loaded');
}

// 4. Garbage in the queue must not stop the replay or throw.
{
  const events = [];
  let threw = false;
  try {
    boot((win) => {
      win.__tcfapi.a.push(null);
      win.__tcfapi.a.push('ping');
      win.__tcfapi.a.push(42);
      win.__tcfapi('addEventListener', 2, (data) => { if (data && data.eventStatus) events.push(data.eventStatus); });
    });
  } catch (_e) {
    threw = true;
  }
  eq('malformed entries are skipped without throwing', threw, false);
  eq('and the valid command after them is still replayed', events[0], 'tcloaded');
}

// 5. The control case the finding relied on: a listener added after the CMP
//    loaded always worked, so the notify path itself is not what is under test.
{
  const w = boot(() => {});
  const events = [];
  w.__tcfapi('addEventListener', 2, (data) => { if (data && data.eventStatus) events.push(data.eventStatus); });
  acceptAll(w);
  eq('control: a listener registered after load receives tcloaded then useractioncomplete', events.join(','), 'tcloaded,useractioncomplete');
}

console.log(`\ntcf-stub-queue-replay: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
