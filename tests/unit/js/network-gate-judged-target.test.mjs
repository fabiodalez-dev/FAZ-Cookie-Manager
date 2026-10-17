/**
 * JS unit test (jsdom) — a network gate hands the native exactly what it
 * judged.
 *
 * The runtime's fetch / sendBeacon / XMLHttpRequest.open / WebSocket gates
 * decided on a string read from the input, and then passed the input itself
 * to the native, which reads it again. An object whose stringification has
 * state — a toString() that answers the site's own API on the first call and
 * a tracker on the second — was allowed as the first URL and sent to the
 * second. An object with an own `url` property was judged by that property,
 * while fetch() stringifies anything that is not a genuine Request. And a
 * toString() that throws once made the gate pass the input through unjudged.
 *
 * Now the native receives the judged string, except for a genuine Request
 * (brand-checked, not duck-typed), whose URL cannot change; a URL object is
 * reduced to its href when judged.
 *
 * Every native is replaced by a recorder BEFORE script.js is evaluated; the
 * recorders stringify what they receive the way the engine would, so "what
 * reached the network" is the second reading the attack relies on.
 *
 * Run: node tests/unit/js/network-gate-judged-target.test.mjs
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

const SITE = 'https://site.test/';
const GA = 'https://www.google-analytics.com/g/collect?v=2&tid=G-X';
const OWN_API = SITE + 'wp-json/faz/v1/ping';

/** First stringification: the site's own API. Every later one: the tracker. */
function flipping() {
  let calls = 0;
  return { toString() { calls += 1; return calls === 1 ? OWN_API : GA; } };
}

function loadFrontend() {
  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { runScripts: 'outside-only', url: SITE });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'analytics', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [{ re: 'google-analytics.com', categories: ['analytics'], service: 'google-analytics' }],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
  window.fazcookie = { _fazGetFromStore: () => undefined };
  window.Response = Response;
  window.Request = Request;
  const reached = { fetch: [], fetchRaw: [], beacon: [], xhr: [], ws: [] };
  // Each recorder reads its input the way the engine does: a Request by its
  // URL, anything else by stringifying it.
  const asEngine = (x) => (x instanceof Request ? x.url : String(x));
  window.fetch = function (input) { reached.fetchRaw.push(input); reached.fetch.push(asEngine(input)); return Promise.resolve({ ok: true, statusText: 'network' }); };
  window.navigator.sendBeacon = function (url) { reached.beacon.push(String(url)); return true; };
  window.XMLHttpRequest.prototype.open = function (method, url) { this.__url = String(url); };
  window.XMLHttpRequest.prototype.send = function () { reached.xhr.push(this.__url); };
  function WS(url) { reached.ws.push(String(url)); this.readyState = 0; }
  WS.prototype = Object.create(window.EventTarget.prototype);
  window.WebSocket = WS;
  window.CloseEvent = window.CloseEvent || window.Event;

  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.addEventListener = realAdd;
  return { window, reached };
}

console.log('network gates: the native receives what was judged');

{
  const { window, reached } = loadFrontend();
  const res = await window.fetch(flipping());
  eq('fetch(stateful object): allowed as the own API', res.statusText, 'network');
  eq('and the request goes to the URL that was judged, not the tracker', reached.fetch[0], OWN_API);
}

{
  const { window, reached } = loadFrontend();
  // fetch() stringifies a non-Request; the gate used to read `.url` instead.
  const res = await window.fetch({ url: OWN_API, toString() { return GA; } });
  eq('fetch({url: own, toString: tracker}) is judged by what fetch reads', res.statusText, 'Blocked by consent');
  eq('and never reaches the network', reached.fetch.length, 0);
}

{
  const { window, reached } = loadFrontend();
  // Built on Request.prototype, but not a Request: no brand, so not trusted.
  const fake = Object.create(window.Request.prototype, {
    url: { value: OWN_API },
    toString: { value() { return GA; } },
  });
  const res = await window.fetch(fake);
  eq('a counterfeit Request is judged by its string form', res.statusText, 'Blocked by consent');
  eq('and never reaches the network', reached.fetch.length, 0);
  // A genuine Request still travels as itself (method, headers, body intact).
  const real = new window.Request(OWN_API, { method: 'POST', body: 'x' });
  await window.fetch(real);
  eq('a genuine Request still goes out', reached.fetch[0], OWN_API);
  eq('as the Request object itself, not a string', reached.fetchRaw[0], real);
}

{
  const { window, reached } = loadFrontend();
  let thrown = 0;
  const throwsOnce = { toString() { thrown += 1; if (thrown === 1) throw new TypeError('no'); return GA; } };
  let rejected = false;
  await window.fetch(throwsOnce).catch(() => { rejected = true; });
  eq('fetch(input whose toString throws) rejects instead of passing it on unjudged', rejected, true);
  eq('and nothing reaches the network', reached.fetch.length, 0);
}

{
  const { window, reached } = loadFrontend();
  window.navigator.sendBeacon(flipping(), 'x');
  eq('sendBeacon(stateful object) sends to the judged URL', reached.beacon[0], OWN_API);
}

{
  const { window, reached } = loadFrontend();
  const x = new window.XMLHttpRequest();
  x.open('POST', flipping(), true);
  x.send();
  eq('xhr.open(stateful object) opens the judged URL', reached.xhr[0], OWN_API);
}

{
  const { window, reached } = loadFrontend();
  new window.WebSocket({ n: 0, toString() { this.n += 1; return this.n === 1 ? 'wss://site.test/live' : 'wss://www.google-analytics.com/x'; } });
  eq('new WebSocket(stateful object) connects to the judged URL', reached.ws[0], 'wss://site.test/live');
}

console.log(`\nnetwork-gate-judged-target: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
