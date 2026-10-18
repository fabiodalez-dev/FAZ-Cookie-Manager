/**
 * JS unit test (jsdom) — while the consent runtime downloads, the bootstrap's
 * network holds hand the native exactly what they judged.
 *
 * The runtime's gates were fixed for this (network-gate-judged-target.test.mjs)
 * but the bootstrap kept the old shape: its fetch hold judged `input.url` by
 * duck typing and passed `input` on, its sendBeacon hold judged the input and
 * passed it on, its XMLHttpRequest.open passed the object to the native and
 * send() judged a second String(url), and the WebSocket hold did the same. An
 * object whose stringification has state (the site's own API on the first
 * reading, a tracker on the next) or an object with an own `url` that is not a
 * genuine Request was judged on one URL and sent to another. On the safety
 * release (the runtime never arrives) the bootstrap's judgement is the only
 * one, so the request went out.
 *
 * The input is now read once, when the call is made, the way the native reads
 * it: a genuine Request (brand-checked) travels as itself, a URL as its href,
 * anything else as its string; that string is what is judged at release and
 * what the native receives. An input that cannot be stringified fails the call
 * the way the native does.
 *
 * Run: node tests/unit/js/bootstrap-network-judged-target.test.mjs
 */

import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const code = readFileSync(resolve(HERE, '../../../frontend/js/bootstrap.js'), 'utf8');

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

const SITE = 'http://site.test/';
const OWN_API = SITE + 'wp-json/faz/v1/ping';
const TRACKER = 'https://tracker.test/collect';

/** The first reading answers `first`, every later one `then`. */
function flipping(first, then) {
  let calls = 0;
  return { toString() { calls += 1; return calls === 1 ? first : then; } };
}

function boot() {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: SITE });
  const { window } = dom;
  Object.defineProperty(window.document, 'readyState', { value: 'loading', configurable: true });
  window._fazConfig = { _block: true };
  window.console.warn = () => {}; // the safety release explains itself on every boot
  window.Request = Request;
  window.Response = Response;
  const reached = { fetch: [], fetchRaw: [], beacon: [], xhr: [], ws: [] };
  // Each recorder reads its input the way the engine does: fetch a Request by
  // its URL and anything else by stringifying it; the others stringify.
  const asEngine = (x) => (x instanceof Request ? x.url : String(x));
  window.fetch = function (input) { reached.fetchRaw.push(input); reached.fetch.push(asEngine(input)); return Promise.resolve({ ok: true, statusText: 'network' }); };
  window.navigator.sendBeacon = function (url) { reached.beacon.push(String(url)); return true; };
  window.XMLHttpRequest.prototype.open = function (method, url) { this.__url = String(url); };
  window.XMLHttpRequest.prototype.send = function () { reached.xhr.push(this.__url); };
  function WS(url) { reached.ws.push(String(url)); this.readyState = 0; }
  WS.prototype = Object.create(window.EventTarget.prototype);
  window.WebSocket = WS;
  window.eval(code);
  return { window, reached };
}

/** The runtime never arrives: DOMContentLoaded applies the safety policy. */
async function safetyRelease(window) {
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 0));
}

console.log('bootstrap holds: the native receives what was judged (safety release)');

{
  const { window, reached } = boot();
  const pending = window.fetch(flipping(OWN_API, TRACKER));
  await safetyRelease(window);
  const res = await pending;
  eq('fetch(stateful object): released as the site\'s own API', res.statusText, 'network');
  eq('and sent to the URL that was judged, not the tracker', reached.fetch[0], OWN_API);
}

{
  const { window, reached } = boot();
  // fetch() stringifies anything that is not a genuine Request.
  const pending = window.fetch({ url: OWN_API, toString() { return TRACKER; } });
  await safetyRelease(window);
  const res = await pending;
  eq('fetch({url: own, toString: tracker}) is judged by what fetch reads', res.statusText, 'Blocked by consent');
  eq('and never reaches the network', reached.fetch.length, 0);
}

{
  const { window, reached } = boot();
  const fake = Object.create(window.Request.prototype, { url: { value: OWN_API }, toString: { value() { return TRACKER; } } });
  const p1 = window.fetch(fake);
  const real = new window.Request(OWN_API, { method: 'POST', body: 'x' });
  const p2 = window.fetch(real);
  await safetyRelease(window);
  eq('a counterfeit Request is judged by its string form', (await p1).statusText, 'Blocked by consent');
  await p2;
  eq('a genuine Request is released', reached.fetch.length, 1);
  eq('as the Request object itself', reached.fetchRaw[0], real);
}

{
  const { window, reached } = boot();
  const href = new window.URL(OWN_API);
  const pending = window.fetch(href);
  href.href = TRACKER; // mutated after the call: the native read it at call time
  await safetyRelease(window);
  await pending;
  eq('fetch(URL) is judged and sent as the href it had when called', reached.fetch[0], OWN_API);
}

{
  const { window, reached } = boot();
  let thrown = 0;
  const throwsOnce = { toString() { thrown += 1; if (thrown === 1) throw new TypeError('no'); return SITE + 'later'; } };
  const outcome = window.fetch(throwsOnce).then(() => 'resolved', () => 'rejected');
  await safetyRelease(window);
  eq('fetch(input whose toString throws) rejects as the native does', await Promise.race([outcome, new Promise((r) => setTimeout(() => r('pending'), 50))]), 'rejected');
  eq('and nothing reaches the network', reached.fetch.length, 0);
}

{
  const { window, reached } = boot();
  window.navigator.sendBeacon(flipping(OWN_API, TRACKER), 'x');
  await safetyRelease(window);
  eq('sendBeacon(stateful object) sends to the judged URL', reached.beacon[0], OWN_API);
  let threw = false;
  try { window.navigator.sendBeacon({ toString() { throw new TypeError('no'); } }, 'x'); } catch (e) { threw = true; }
  eq('sendBeacon(unstringifiable) throws as the native does', threw, true);
}

{
  const { window, reached } = boot();
  // The native open() reads the tracker; a second reading in send() said own.
  const x = new window.XMLHttpRequest();
  x.open('POST', flipping(TRACKER, OWN_API), true);
  x.send();
  await safetyRelease(window);
  eq('xhr.open(stateful object) to a tracker is not sent on a second, first-party reading', reached.xhr.length, 0);

  const y = new window.XMLHttpRequest();
  y.open('POST', flipping(OWN_API, TRACKER), true);
  y.send();
  eq('xhr to the judged first-party URL goes out to that URL', reached.xhr[0], OWN_API);
}

{
  const { window, reached } = boot();
  new window.WebSocket(flipping('ws://site.test/live', 'wss://tracker.test/x'));
  await safetyRelease(window);
  eq('new WebSocket(stateful object) connects to the judged URL', reached.ws[0], 'ws://site.test/live');
}

console.log(`\nbootstrap-network-judged-target: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
