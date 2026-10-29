/**
 * JS unit test (jsdom) — the runtime recovers a static configuration file
 * that did not apply, and says precisely why when it cannot.
 *
 * The content-hashed uploads/faz-cookie-manager/assets/config-<md5>.js is a
 * deferred dependency of script.min.js and defines window._fazStaticConfig.
 * In the field it arrived empty (a once-truncated copy the browser caches as
 * immutable), was refused (a non-JS Content-Type with nosniff), was answered
 * by a staging-protection HTML page, or was delayed by an optimiser past the
 * runtime. The runtime then stood down for the page view with one generic
 * warning, and a WooCommerce checkout lost its PayPal / Google Pay buttons
 * (wp.org support thread "WooCommerce Express Checkout 3rd party buttons do
 * not appear"; the Network tab showed a 200 from disk cache).
 *
 * Pinned here:
 *   - a missing global with a reachable, valid file: the runtime re-fetches it
 *     synchronously (cache-busted), parses it WITHOUT eval, merges it and
 *     starts; one console.info names the likely cause;
 *   - an empty body, an HTML body, a 404, a network error, a payload that is
 *     not the expected assignment: the runtime still stands down (fail-closed)
 *     and the warning names the case, the status, the type and the length;
 *   - no URL anywhere: the old behaviour and the old warning, no request;
 *   - only the page's own content-hashed config file is ever requested;
 *   - the bundle/PHP version skew warning;
 *   - the bootstrap's DOMContentLoaded message says "loaded but could not
 *     start" for a runtime that stood down, and "did not load" otherwise.
 *
 * Run: node tests/unit/js/static-config-recovery.test.mjs
 */
import { JSDOM, VirtualConsole } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const runtime = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
const boot = readFileSync(resolve(HERE, '../../../frontend/js/bootstrap.js'), 'utf8');
const BUILD = (runtime.match(/const _FAZ_BUILD = '([^']+)'/) || [])[1] || '';
const VERSION = BUILD.split('+')[0];

let passed = 0;
let failed = 0;
function ok(label, cond) {
  if (cond) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

const HASH = '5b050d332b08ce945e0123456789abcd';
const CONFIG_URL = `https://shop.test/wp-content/uploads/faz-cookie-manager/assets/config-${HASH}.js`;
const GOOD_BODY = 'window._fazStaticConfig={"_providersToBlock":[{"re":"connect.facebook.net","categories":["marketing"]}],"_cookieCategoryMap":{"_fbp":"marketing"}};';

// `response` is { status, body, type, nosniff } or { throws: true }.
function page({ inlineUrl = CONFIG_URL, tag = 'src', response = null, version = VERSION } = {}) {
  const tagMarkup = tag === 'src'
    ? `<script defer id="faz-cookie-manager-static-config-js" src="${CONFIG_URL}"></script>`
    : tag === 'delayed'
      ? `<script type="pmdelayedscript" id="faz-cookie-manager-static-config-js" data-pmdelayedscript="${CONFIG_URL}"></script>`
      : tag === 'anonymous'
        ? `<script src="${CONFIG_URL}?_litespeed_rm_qs=0"></script>`
        : '';
  const dom = new JSDOM(`<!DOCTYPE html><html><head>${tagMarkup}</head><body></body></html>`, { runScripts: 'outside-only', url: 'https://shop.test/checkout/', virtualConsole: new VirtualConsole() });
  const w = dom.window;
  const log = { warn: [], info: [], requests: [] };
  w.console.warn = function () { log.warn.push(Array.prototype.join.call(arguments, ' ')); };
  w.console.info = function () { log.info.push(Array.prototype.join.call(arguments, ' ')); };
  w.__evaluated = false;
  function FakeXHR() { this.status = 0; this.responseText = ''; this._headers = {}; }
  FakeXHR.prototype.open = function (method, url, async) { this._req = { method, url, async }; };
  FakeXHR.prototype.setRequestHeader = function () {};
  FakeXHR.prototype.send = function () {
    log.requests.push(this._req);
    if (!response || response.throws) throw new Error('NetworkError');
    this.status = response.status;
    this.responseText = response.body;
    this._headers = { 'content-type': response.type || '', 'x-content-type-options': response.nosniff ? 'nosniff' : '' };
  };
  FakeXHR.prototype.getResponseHeader = function (name) { return this._headers[String(name).toLowerCase()] || null; };
  w.XMLHttpRequest = FakeXHR;
  w._fazConfig = { _block: true, _categories: [], _staticConfigRequired: true, _version: version };
  if (inlineUrl) w._fazConfig._staticConfigUrl = inlineUrl;
  return { w, log };
}
function run(w) {
  try { w.eval(runtime); } catch (e) { /* no banner DOM in this fixture */ }
}
const staticWarning = (log) => log.warn.find((l) => l.indexOf('static configuration file failed to load') !== -1) || '';

console.log('\nrecovery: a valid file that had not applied');
{
  const { w, log } = page({ response: { status: 200, body: GOOD_BODY, type: 'application/javascript' } });
  run(w);
  ok('exactly one request was made', log.requests.length === 1);
  ok('it is synchronous', log.requests[0] && log.requests[0].async === false);
  ok('it targets the config file with a cache-busting query', log.requests[0] && log.requests[0].url.indexOf(CONFIG_URL + '?faz-recover=') === 0);
  ok('the runtime starts', w._fazRuntimeStarted === true);
  ok('the static payload is merged into _fazConfig', w._fazConfig._cookieCategoryMap && w._fazConfig._cookieCategoryMap._fbp === 'marketing'
    && Array.isArray(w._fazConfig._providersToBlock) && w._fazConfig._providersToBlock.length === 1);
  ok('window._fazStaticConfig is set', w._fazStaticConfig && typeof w._fazStaticConfig === 'object');
  ok('no stand-down warning', staticWarning(log) === '');
  ok('one console.info explains the re-fetch', log.info.filter((l) => l.indexOf('fetched again') !== -1).length === 1);
  ok('and names the empty-cached-copy / out-of-order cause for an ordinary tag', log.info.some((l) => l.indexOf('empty or truncated') !== -1 && l.indexOf('Delay/Defer') !== -1));
}
{
  const { w, log } = page({ inlineUrl: '', tag: 'delayed', response: { status: 200, body: GOOD_BODY, type: 'text/javascript' } });
  run(w);
  ok('without the inline URL, the delayed tag\'s parked URL is used', log.requests.length === 1 && log.requests[0].url.indexOf(CONFIG_URL) === 0);
  ok('the runtime starts', w._fazRuntimeStarted === true);
  ok('the info names the optimiser that delayed the tag', log.info.some((l) => l.indexOf('delayed or re-typed') !== -1));
}
{
  const { w, log } = page({ inlineUrl: '', tag: 'anonymous', response: { status: 200, body: GOOD_BODY + '\n', type: 'application/javascript; charset=utf-8' } });
  run(w);
  ok('a tag found by its uploads URL alone (no id) is used, existing query kept', log.requests.length === 1 && log.requests[0].url.indexOf(CONFIG_URL + '?_litespeed_rm_qs=0&faz-recover=') === 0);
  ok('the runtime starts', w._fazRuntimeStarted === true);
}
{
  const { w, log } = page({ tag: '', response: { status: 200, body: GOOD_BODY, type: 'text/plain', nosniff: true } });
  run(w);
  ok('a valid body served as text/plain still recovers', w._fazRuntimeStarted === true);
  ok('and the info names the Content-Type and nosniff', log.info.some((l) => l.indexOf('"text/plain"') !== -1 && l.indexOf('nosniff') !== -1));
}

console.log('\nfailure: the runtime stands down and says why');
{
  const { w, log } = page({ response: { status: 200, body: '', type: 'application/javascript' } });
  run(w);
  ok('empty body: the runtime stands down', w._fazRuntimeStarted !== true && !w._fazStaticConfig);
  ok('the warning names the empty file and the remedy', /EMPTY/.test(staticWarning(log)) && staticWarning(log).indexOf('wp-content/uploads/faz-cookie-manager/assets/') !== -1);
  ok('and carries status, type and length', staticWarning(log).indexOf('HTTP 200') !== -1 && staticWarning(log).indexOf('"application/javascript"') !== -1 && staticWarning(log).indexOf('0 bytes') !== -1);
  ok('the stub object is still exposed', w.fazcookie && typeof w.fazcookie === 'object' && !w.fazcookie._fazConsentStore);
}
{
  const { w, log } = page({ response: { status: 200, body: '<!DOCTYPE html><html><body>Please log in</body></html>', type: 'text/html; charset=UTF-8' } });
  run(w);
  ok('HTML body: the runtime stands down', w._fazRuntimeStarted !== true);
  ok('the warning names the interception', staticWarning(log).indexOf('HTML page') !== -1 && staticWarning(log).indexOf('staging-protection') !== -1);
}
{
  const { w, log } = page({ response: { status: 404, body: 'Not found', type: 'text/plain' } });
  run(w);
  ok('404: the runtime stands down', w._fazRuntimeStarted !== true);
  ok('the warning says the file does not exist', staticWarning(log).indexOf('HTTP 404') !== -1 && staticWarning(log).indexOf('does not exist') !== -1);
}
{
  const { w, log } = page({ response: { throws: true } });
  let threw = false;
  try { w.eval(runtime); } catch (e) { threw = true; }
  ok('a network error does not throw out of the runtime', !threw);
  ok('the runtime stands down', w._fazRuntimeStarted !== true);
  ok('the warning names the network failure', staticWarning(log).indexOf('did not complete') !== -1);
}
{
  const { w, log } = page({ response: { status: 200, body: 'window._fazStaticConfig=(window.__evaluated=true,{});', type: 'application/javascript' } });
  run(w);
  ok('a payload that is not plain JSON is never executed', w.__evaluated === false);
  ok('it is refused as malformed and the runtime stands down', w._fazRuntimeStarted !== true && staticWarning(log).indexOf('does not hold the expected configuration') !== -1);
}
{
  const { w, log } = page({ response: { status: 200, body: GOOD_BODY.slice(0, 60), type: 'application/javascript' } });
  run(w);
  ok('a truncated file is refused (fail-closed)', w._fazRuntimeStarted !== true && staticWarning(log).indexOf('truncated') !== -1);
}
{
  const { w, log } = page({ response: { status: 200, body: 'window._fazStaticConfig=[1,2];', type: 'application/javascript' } });
  run(w);
  ok('a non-object payload is refused', w._fazRuntimeStarted !== true && staticWarning(log) !== '');
}

console.log('\nno URL, or not a config URL: no request');
{
  const { w, log } = page({ inlineUrl: '', tag: '', response: { status: 200, body: GOOD_BODY } });
  run(w);
  ok('no request is made', log.requests.length === 0);
  ok('the runtime stands down as before', w._fazRuntimeStarted !== true);
  ok('with the old pointer to the Network tab', staticWarning(log).indexOf('Check the Network tab') !== -1);
}
{
  const { w, log } = page({ inlineUrl: 'https://tracker.test/collect.js', tag: '', response: { status: 200, body: GOOD_BODY } });
  run(w);
  ok('an inline URL that does not name a config file is never fetched', log.requests.length === 0 && w._fazRuntimeStarted !== true);
}
{
  const { w, log } = page({ response: { status: 200, body: GOOD_BODY } });
  w._fazStaticConfig = { _providersToBlock: [] };
  run(w);
  ok('a healthy page (the global is there) makes no request', log.requests.length === 0 && w._fazRuntimeStarted === true && log.info.length === 0);
}
{
  const { w, log } = page({ response: { status: 200, body: GOOD_BODY } });
  delete w._fazConfig._staticConfigRequired;
  run(w);
  ok('an inline-only config (no static file required) makes no request', log.requests.length === 0 && w._fazRuntimeStarted === true);
}

console.log('\nversion skew between the bundle and the PHP side');
{
  ok('premise: the build marker is readable from the top of script.js', /^\d+\.\d+\.\d+/.test(VERSION));
  const { w, log } = page({ version: '1.28.1', response: { status: 200, body: GOOD_BODY } });
  w._fazStaticConfig = {};
  run(w);
  ok('a different PHP version is reported once', log.warn.filter((l) => l.indexOf(`runtime bundle is ${VERSION} but the PHP side reports 1.28.1`) !== -1).length === 1);
  ok('it is diagnostic only: the runtime still starts', w._fazRuntimeStarted === true);
}
{
  const { w, log } = page({ response: { status: 200, body: GOOD_BODY } });
  w._fazStaticConfig = {};
  run(w);
  ok('matching versions print nothing', !log.warn.some((l) => l.indexOf('runtime bundle is') !== -1));
}
{
  const { w, log } = page({ version: '1.28.1', response: { status: 200, body: '' } });
  run(w);
  ok('the skew is reported on a page where the runtime stands down too', log.warn.some((l) => l.indexOf('PHP side reports 1.28.1') !== -1) && w._fazRuntimeStarted !== true);
}

console.log('\nthe bootstrap\'s DOMContentLoaded message');
async function domReady(w) {
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 0));
}
{
  const { w, log } = page({ response: { status: 200, body: '' } });
  Object.defineProperty(w.document, 'readyState', { value: 'loading', configurable: true });
  w.eval(boot);
  run(w);
  await domReady(w);
  ok('a runtime that stood down is reported as loaded but not started', log.warn.some((l) => l.indexOf('loaded but could not start') !== -1));
  ok('not as missing', !log.warn.some((l) => l.indexOf('did not load before DOMContentLoaded') !== -1));
}
{
  const { w, log } = page({ response: { status: 200, body: '' } });
  Object.defineProperty(w.document, 'readyState', { value: 'loading', configurable: true });
  w.eval(boot);
  await domReady(w);
  ok('control: with no runtime at all the message is still "did not load"', log.warn.some((l) => l.indexOf('did not load before DOMContentLoaded') !== -1));
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
