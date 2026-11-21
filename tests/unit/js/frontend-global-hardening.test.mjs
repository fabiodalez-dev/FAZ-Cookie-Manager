/**
 * JS unit test (jsdom) — hardening of the frontend runtime's globals and
 * message receivers (security audit 2026-11, section 5).
 *
 * Pinned here:
 *   - DOM clobbering of window.fazcookie: post content is parsed before the
 *     deferred runtime runs, and two anchors `id="fazcookie"` (one with
 *     name="_fazInitDone") turned window.fazcookie into an HTMLCollection whose
 *     init flag was truthy, so _fazInit never ran and the banner disappeared.
 *     A deferred bootstrap reading `_fazConsentStore` through the same trick
 *     stood down with no runtime behind it.
 *   - DOM clobbering of window._fazStaticConfig: an element with that id
 *     skipped the recovery and defeated the fail-closed guard, so the runtime
 *     started without its provider rules instead of standing down.
 *   - Selectors built from service ids and category slugs are escaped: a quote
 *     in a placeholder's category no longer throws a SyntaxError.
 *   - The consent-forwarding receiver listens only on the framed bridge URL
 *     and only to its parent window.
 *   - A TCF listener id relayed through __tcfapiLocator can only be removed by
 *     the window that registered it; same-page callers are unaffected.
 *
 * Run: node tests/unit/js/frontend-global-hardening.test.mjs
 */
import { JSDOM, VirtualConsole } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const runtime = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
const boot = readFileSync(resolve(HERE, '../../../frontend/js/bootstrap.js'), 'utf8');
const cmp = readFileSync(resolve(HERE, '../../../frontend/js/tcf-cmp.js'), 'utf8');

let passed = 0;
let failed = 0;
function ok(label, cond) {
  if (cond) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

function page(body, { url = 'https://site.test/', config = {} } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head></head><body>${body || ''}</body></html>`, {
    runScripts: 'outside-only', url, virtualConsole: new VirtualConsole(),
  });
  const w = dom.window;
  w._fazConfig = Object.assign({
    _block: true,
    _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'marketing', isNecessary: false }],
    _services: [],
    _providersToBlock: [],
    _userWhitelist: [],
    i18n: {},
  }, config);
  const log = { warn: [], error: [] };
  w.console.warn = function () { log.warn.push(Array.prototype.join.call(arguments, ' ')); };
  w.console.error = function () { log.error.push(Array.prototype.join.call(arguments, ' ')); };
  w.console.info = () => {};
  return { w, dom, log };
}

// Evaluate the runtime with document.readyState forced to "loading", so the
// DomReady callback is captured instead of run.
function evalRuntimeCapturingInit(w) {
  Object.defineProperty(w.document, 'readyState', { get: () => 'loading', configurable: true });
  let init = null;
  const realAdd = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (type, cb, ...rest) => {
    if (type === 'DOMContentLoaded' && !init) { init = cb; return undefined; }
    return realAdd(type, cb, ...rest);
  };
  try { w.eval(runtime); } finally { w.document.addEventListener = realAdd; }
  return init;
}

const CLOBBER_INIT = '<a id="fazcookie"></a><a id="fazcookie" name="_fazInitDone"></a>';

console.log('\nwindow.fazcookie cannot be clobbered by post content');
{
  const { w } = page(CLOBBER_INIT);
  ok('fixture: the document answers window.fazcookie before the runtime runs',
    w.fazcookie && Object.prototype.toString.call(w.fazcookie) === '[object HTMLCollection]' && !!w.fazcookie._fazInitDone);
  let timers = 0;
  const init = evalRuntimeCapturingInit(w);
  const realSetTimeout = w.setTimeout;
  w.setTimeout = () => { timers += 1; return 0; };
  ok('the DomReady init callback was captured', typeof init === 'function');
  if (typeof init === 'function') { try { await init(); } catch (e) { /* minimal fixture */ } }
  w.setTimeout = realSetTimeout;
  ok('window.fazcookie is the runtime\'s own plain object', Object.prototype.toString.call(w.fazcookie) === '[object Object]');
  ok('its consent store is a Map', Object.prototype.toString.call(w.fazcookie._fazConsentStore) === '[object Map]');
  ok('_fazInit ran despite the clobbering anchors (it scheduled its watchdog)', timers >= 1);
  ok('and the init flag is the runtime\'s own', w.fazcookie._fazInitDone === true);
}
{
  const { w } = page();
  w.fazcookie = { _fazInitDone: true, marker: 1 };
  const init = evalRuntimeCapturingInit(w);
  let timers = 0;
  w.setTimeout = () => { timers += 1; return 0; };
  if (typeof init === 'function') { try { await init(); } catch (e) { /* minimal fixture */ } }
  ok('control: an existing plain window.fazcookie is kept', w.fazcookie.marker === 1);
  ok('control: and its init flag still stops a second init', timers === 0);
}
{
  const { w } = page('<a id="fazcookie"></a><a id="fazcookie" name="_fazConsentStore"></a>');
  Object.defineProperty(w.document, 'readyState', { value: 'interactive', configurable: true });
  w.eval(boot);
  ok('a bootstrap deferred past clobbering post content still arms', !!w._fazBootstrap && typeof w._fazBootstrap.start === 'function');
}
{
  const { w } = page();
  w.fazcookie = { _fazConsentStore: new w.Map() };
  w.eval(boot);
  ok('control: a real consent store still makes the bootstrap step aside', w._fazBootstrap === undefined);
}

console.log('\nwindow._fazStaticConfig cannot be clobbered by post content');
{
  const { w, log } = page('<div id="_fazStaticConfig"></div>', { config: { _staticConfigRequired: true } });
  ok('fixture: the document answers window._fazStaticConfig', !!w._fazStaticConfig && typeof w._fazStaticConfig === 'object');
  try { w.eval(runtime); } catch (e) { /* stands down */ }
  ok('the runtime stands down instead of starting without its provider rules', w._fazRuntimeStarted !== true);
  ok('and says the static configuration is missing', log.warn.some((l) => l.indexOf('static configuration file failed to load') !== -1));
}
{
  const { w } = page('<div id="_fazStaticConfig"></div>', {
    config: { _staticConfigRequired: true, _staticConfigUrl: 'https://site.test/wp-content/uploads/faz-cookie-manager/assets/config-' + 'a'.repeat(32) + '.js' },
  });
  const requests = [];
  function FakeXHR() { this.status = 0; this.responseText = ''; }
  FakeXHR.prototype.open = function (m, u, a) { requests.push({ m, u, a }); };
  FakeXHR.prototype.send = function () {
    this.status = 200;
    this.responseText = 'window._fazStaticConfig={"_providersToBlock":[{"re":"connect.facebook.net","categories":["marketing"]}],"_cookieCategoryMap":{"_fbp":"marketing"}};';
  };
  FakeXHR.prototype.getResponseHeader = function (n) { return /content-type/i.test(n) ? 'application/javascript' : null; };
  w.XMLHttpRequest = FakeXHR;
  delete w._fazConfig._providersToBlock; // offloaded to the static file
  try { w.eval(runtime); } catch (e) { /* no banner DOM */ }
  ok('with a recovery URL, the clobbered global is not trusted and the file is fetched again', requests.length === 1);
  ok('the recovered rules are merged and the runtime starts', w._fazRuntimeStarted === true
    && Array.isArray(w._fazConfig._providersToBlock) && w._fazConfig._providersToBlock.length === 1);
}
{
  const { w } = page();
  w.eval('window._fazStaticConfig={"_providersToBlock":[],"_cookieCategoryMap":{"_ga":"analytics"}};');
  w._fazConfig._staticConfigRequired = true;
  try { w.eval(runtime); } catch (e) { /* no banner DOM */ }
  ok('control: a static file that executed is merged and the runtime starts', w._fazRuntimeStarted === true
    && w._fazConfig._cookieCategoryMap && w._fazConfig._cookieCategoryMap._ga === 'analytics');
}

console.log('\nselectors built from ids and slugs are escaped');
{
  const { w } = page('', { config: { _perServiceConsent: true, _services: [{ id: 'yt', category: 'mark"]eting' }] } });
  try { w.eval(runtime); } catch (e) { /* no banner DOM */ }
  let err = null;
  try { w._fazAcceptService('yt', 'mark"]eting'); } catch (e) { err = e; }
  ok('accepting a service whose category holds a quote does not throw a selector SyntaxError',
    !(err && /SyntaxError|not a valid selector/i.test(String(err && (err.name + ' ' + err.message)))));
  ok('the escaped value still selects the element it names', typeof w._fazCssAttrValue === 'function' && (() => {
    const el = w.document.createElement('input');
    el.className = 'faz-service-toggle';
    el.setAttribute('data-category', 'a"b\\c]\n');
    w.document.body.appendChild(el);
    return w.document.querySelector('.faz-service-toggle[data-category="' + w._fazCssAttrValue('a"b\\c]\n') + '"]') === el;
  })());
  ok('the fallback without CSS.escape selects it too', typeof w._fazCssAttrValue === 'function' && (() => {
    const saved = w.CSS;
    try { w.CSS = undefined; } catch (e) { /* ignore */ }
    const v = w._fazCssAttrValue('x"y\\z\u0001');
    w.CSS = saved;
    const el = w.document.createElement('span');
    el.setAttribute('data-x', 'x"y\\z\u0001');
    w.document.body.appendChild(el);
    return w.document.querySelector('[data-x="' + v + '"]') === el;
  })());
}

console.log('\nconsent forwarding is received only by the framed bridge, from its parent');
const FORWARD = 'consentid:abc,consent:yes,action:yes,necessary:yes,marketing:yes';
function forwardPage({ url, framed, sourceIsParent }) {
  const { w } = page('', { url, config: { _consentForwarding: { enabled: true, targets: ['https://partner.test/'] } } });
  const parentWin = { postMessage() {} };
  const otherWin = { postMessage() {} };
  if (framed) Object.defineProperty(w, 'parent', { value: parentWin, configurable: true });
  try { w.eval(runtime); } catch (e) { /* no banner DOM */ }
  w.dispatchEvent(new w.MessageEvent('message', {
    data: { type: 'faz_consent_forward', consent: FORWARD },
    origin: 'https://partner.test',
    source: framed ? (sourceIsParent ? parentWin : otherWin) : otherWin,
  }));
  return /(?:^|;\s*)fazcookie-consent=/.test(w.document.cookie);
}
ok('the bridge frame accepts a forward from its parent', forwardPage({ url: 'https://site.test/?faz_consent_forward=1', framed: true, sourceIsParent: true }) === true);
ok('a top-level page ignores a forward from an allowed origin', forwardPage({ url: 'https://site.test/shop/', framed: false }) === false);
ok('a top-level page at the bridge URL ignores it too', forwardPage({ url: 'https://site.test/?faz_consent_forward=1', framed: false }) === false);
ok('the bridge frame ignores a window that is not its parent', forwardPage({ url: 'https://site.test/?faz_consent_forward=1', framed: true, sourceIsParent: false }) === false);
ok('a framed page that is not the bridge ignores its parent', forwardPage({ url: 'https://site.test/blog/', framed: true, sourceIsParent: true }) === false);

console.log('\nTCF: a relayed listener is removed only by the window that registered it');
function cmpPage() {
  const { w } = page();
  w._fazTcfConfig = { cmpId: 400, cmpVersion: 1, gvlVersion: 100, vendors: {}, selectedVendors: [], consentRevision: 1, purposeOneTreatment: false };
  w.eval(cmp);
  return w;
}
function relay(w, source, call) {
  const replies = [];
  source.postMessage = (msg) => replies.push(msg.__tcfapiReturn);
  w.dispatchEvent(new w.MessageEvent('message', { data: { __tcfapiCall: call }, origin: 'https://ads.test', source }));
  return replies;
}
{
  const w = cmpPage();
  let localId = null;
  w.__tcfapi('addEventListener', 2, (d) => { if (localId === null) localId = d.listenerId; });
  const frameA = {};
  const frameB = {};
  const regA = relay(w, frameA, { command: 'addEventListener', version: 2, callId: 'a1' });
  const idA = regA[0] && regA[0].returnValue && regA[0].returnValue.listenerId;
  ok('fixture: a local and a relayed listener are registered', typeof localId === 'number' && typeof idA === 'number' && idA !== localId);
  const b1 = relay(w, frameB, { command: 'removeEventListener', version: 2, callId: 'b1', parameter: localId });
  ok('another frame cannot remove the page\'s listener', b1[0] && b1[0].returnValue === false);
  const b2 = relay(w, frameB, { command: 'removeEventListener', version: 2, callId: 'b2', parameter: idA });
  ok('another frame cannot remove frame A\'s listener', b2[0] && b2[0].returnValue === false);
  let localRemoveOfA = null;
  w.__tcfapi('removeEventListener', 2, (r) => { localRemoveOfA = r; }, idA);
  ok('the page cannot remove frame A\'s relayed listener either', localRemoveOfA === false);
  const a2 = relay(w, frameA, { command: 'removeEventListener', version: 2, callId: 'a2', parameter: idA });
  ok('frame A removes its own listener', a2[0] && a2[0].returnValue === true);
  let localRemove = null;
  w.__tcfapi('removeEventListener', 2, (r) => { localRemove = r; }, localId);
  ok('the page removes its own listener (IAB API unchanged for same-page callers)', localRemove === true);
  let selfPosted = null;
  w.__tcfapi('addEventListener', 2, (d) => { if (selfPosted === null) selfPosted = d.listenerId; });
  const self = relay(w, w, { command: 'removeEventListener', version: 2, callId: 's1', parameter: selfPosted });
  ok('a message the page posts to itself counts as the page', self[0] && self[0].returnValue === true);
}

console.log(`\n  frontend-global-hardening: ${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);
