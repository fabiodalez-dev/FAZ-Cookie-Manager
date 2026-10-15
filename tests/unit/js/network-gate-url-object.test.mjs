/**
 * JS unit test (jsdom) — the runtime's network gates judge a URL OBJECT the
 * way they judge the same URL as a string.
 *
 * fetch(), navigator.sendBeacon(), XMLHttpRequest.open() and the WebSocket
 * constructor all accept a WHATWG URL instance (fetch and WebSocket natively,
 * the other two through WebIDL's USVString coercion). The gates read
 * `input.url` — a Request has it, a URL does not (it has `href`) — or required
 * a string outright, so a tracker that built its endpoint with `new URL()`
 * resolved to an empty endpoint and took the passthrough branch while the
 * identical string was dropped. The bootstrap's hold window stringifies the
 * same input (local() does String(url)), so the two layers disagreed, and a
 * call held during the download window was replayed into a runtime gate that
 * let it through.
 *
 * Every native is replaced by a recorder BEFORE script.js is evaluated, so the
 * gates wrap the recorders and "reached the network" is a list the test owns.
 *
 * Run: node tests/unit/js/network-gate-url-object.test.mjs
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
const GA = 'https://www.google-analytics.com/g/collect?v=2&tid=G-X&dl=https%3A%2F%2Fsite.test%2F';
const HOTJAR_WS = 'wss://ws.hotjar.com/api/v2/client/ws';
const OWN_API = SITE + 'wp-json/faz/v1/ping';

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
    _providersToBlock: [
      { re: 'google-analytics.com', categories: ['analytics'], service: 'google-analytics' },
      { re: 'hotjar.com', categories: ['analytics'], service: 'hotjar' },
    ],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    i18n: {},
  };
  // No consent recorded for any key: analytics is denied.
  window.fazcookie = { _fazGetFromStore: () => undefined };

  // jsdom has no Response/Request; the gate builds a Response when it blocks
  // a fetch, and a Request is one of the inputs under test. Node's own.
  window.Response = Response;
  window.Request = Request;
  const reached = { fetch: [], beacon: [], xhr: [], ws: [] };
  window.fetch = function (input) { reached.fetch.push(input); return Promise.resolve({ ok: true, statusText: 'network' }); };
  window.navigator.sendBeacon = function (url) { reached.beacon.push(url); return true; };
  // jsdom's XHR would try the network; a bare recorder keeps the shape the
  // gate needs (open stores the URL, send is where a blocked request is faked).
  window.XMLHttpRequest.prototype.open = function (method, url) { this.__url = url; };
  window.XMLHttpRequest.prototype.send = function () { reached.xhr.push(this.__url); };
  function WS(url) { reached.ws.push(url); this.readyState = 0; }
  WS.prototype = Object.create(window.EventTarget.prototype);
  window.WebSocket = WS;
  window.CloseEvent = window.CloseEvent || window.Event;

  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  window.eval(readFileSync(SCRIPT_PATH, 'utf8'));
  window.document.addEventListener = realAdd;
  return { window, reached };
}

console.log('network gates: a URL object is judged like the same string');

{
  const { window, reached } = loadFrontend();
  // Premise: the string form is dropped, so the provider rule is in force.
  const asString = await window.fetch(GA);
  eq('premise: fetch(string) to the tracker is blocked', asString.statusText, 'Blocked by consent');
  eq('premise: and never reaches the network', reached.fetch.length, 0);

  const asUrl = await window.fetch(new window.URL(GA));
  eq('fetch(new URL(tracker)) is blocked too', asUrl.statusText, 'Blocked by consent');
  eq('and never reaches the network', reached.fetch.length, 0);

  const own = await window.fetch(new window.URL(OWN_API));
  eq('fetch(new URL(own api)) still goes out', own.statusText, 'network');
  eq('with the URL object handed to the native unchanged', reached.fetch[0] instanceof window.URL, true);
  // A Request keeps working: it has .url and always did.
  const req = new window.Request(GA);
  const asRequest = await window.fetch(req);
  eq('fetch(Request) to the tracker is blocked (unchanged)', asRequest.statusText, 'Blocked by consent');
}

{
  const { window, reached } = loadFrontend();
  window.navigator.sendBeacon(GA);
  eq('premise: sendBeacon(string) to the tracker is dropped', reached.beacon.length, 0);
  window.navigator.sendBeacon(new window.URL(GA));
  eq('sendBeacon(new URL(tracker)) is dropped too', reached.beacon.length, 0);
  window.navigator.sendBeacon(new window.URL(OWN_API));
  eq('sendBeacon(new URL(own api)) goes out', reached.beacon.length, 1);
}

{
  const { window, reached } = loadFrontend();
  const a = new window.XMLHttpRequest();
  a.open('POST', GA); a.send();
  eq('premise: xhr.open(string) to the tracker is blocked', reached.xhr.length, 0);
  eq('premise: and the request is marked', a._fazBlocked, true);
  const b = new window.XMLHttpRequest();
  b.open('POST', new window.URL(GA)); b.send();
  eq('xhr.open(new URL(tracker)) is blocked too', reached.xhr.length, 0);
  eq('and the request is marked', b._fazBlocked, true);
  const c = new window.XMLHttpRequest();
  c.open('GET', new window.URL(OWN_API)); c.send();
  eq('xhr.open(new URL(own api)) goes out', reached.xhr.length, 1);
}

{
  const { window, reached } = loadFrontend();
  const s1 = new window.WebSocket(HOTJAR_WS);
  eq('premise: new WebSocket(string) to the tracker is refused', reached.ws.length, 0);
  eq('premise: the mock is CLOSED', s1.readyState, 3);
  const s2 = new window.WebSocket(new window.URL(HOTJAR_WS));
  eq('new WebSocket(new URL(tracker)) is refused too', reached.ws.length, 0);
  eq('and the mock is CLOSED', s2.readyState, 3);
  new window.WebSocket(new window.URL('wss://site.test/live'));
  eq('new WebSocket(new URL(own host)) connects', reached.ws.length, 1);
}

console.log(`\nnetwork-gate-url-object: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
