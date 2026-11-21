/**
 * JS unit test (jsdom) — the localized configuration globals resist DOM
 * clobbering (security audit 2026-11, section 5, follow-up).
 *
 * When the localize block is missing (an optimiser dropped or delayed it) and
 * a script runs after post content, a name no script defined is answered by
 * the document: `<a id="_fazConfig">` makes window._fazConfig an element, and
 * two anchors sharing that id (one named `_block`) make `_fazConfig._block` one
 * too. Each is truthy, so the runtime, the bootstrap, GCM and the TCF CMP ran
 * on markup instead of standing down. Only a plain object counts now.
 *
 * Run: node tests/unit/js/frontend-config-clobbering.test.mjs
 */
import { JSDOM, VirtualConsole } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SRC = (name) => readFileSync(resolve(HERE, '../../../frontend/js/' + name), 'utf8');
const runtime = SRC('script.js');
const boot = SRC('bootstrap.js');
const gcm = SRC('gcm.js');
const cmp = SRC('tcf-cmp.js');

let passed = 0;
let failed = 0;
function ok(label, cond) {
  if (cond) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

function page(body) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head></head><body>${body || ''}</body></html>`, {
    runScripts: 'outside-only', url: 'https://site.test/', virtualConsole: new VirtualConsole(),
  });
  const w = dom.window;
  w.console.warn = () => {};
  w.console.error = () => {};
  w.console.info = () => {};
  return w;
}
const isElementLike = (v) => !!v && typeof v === 'object' && Object.prototype.toString.call(v) !== '[object Object]';

console.log('\nscript.js: window._fazConfig');
{
  const w = page('<a id="_fazConfig"></a>');
  ok('fixture: the document answers window._fazConfig', isElementLike(w._fazConfig));
  try { w.eval(runtime); } catch (e) { /* stands down */ }
  ok('after the runtime loads, window._fazConfig is no longer the element', !isElementLike(w._fazConfig));
  ok('and the runtime did not start on it', w._fazRuntimeStarted !== true);
}
{
  const w = page('<a id="_fazConfig"></a>');
  w.eval('window._fazCfg = { "_block": true, "marker": 7 };');
  try { w.eval(runtime); } catch (e) { /* no banner DOM */ }
  ok('a plain _fazCfg payload still becomes _fazConfig, over the element', w._fazConfig && w._fazConfig.marker === 7);
}
{
  const w = page('');
  w.eval('window._fazConfig = { "marker": 3 };');
  try { w.eval(runtime); } catch (e) { /* no banner DOM */ }
  ok('control: a localized plain _fazConfig is kept', w._fazConfig && w._fazConfig.marker === 3);
}

console.log('\nbootstrap.js: window._fazConfig._block');
{
  const w = page('<a id="_fazConfig"></a><a id="_fazConfig" name="_block"></a>');
  ok('fixture: _fazConfig._block is answered by markup', !!(w._fazConfig && w._fazConfig._block));
  w.eval(boot);
  ok('a deferred bootstrap does not arm on a clobbered configuration', w._fazBootstrap === undefined);
}
{
  const w = page('');
  w.eval('window._fazConfig = { "_block": true };');
  w.eval(boot);
  ok('control: a plain configuration with _block still arms it', !!w._fazBootstrap);
}

console.log('\ngcm.js: window._fazGcm');
{
  const w = page('<a id="_fazGcm"></a>');
  ok('fixture: the document answers window._fazGcm', isElementLike(w._fazGcm));
  try { w.eval(gcm); } catch (e) { /* stands down */ }
  ok('GCM does not run on the element (nothing reaches dataLayer, no gtag stub)', !w.dataLayer && typeof w.gtag === 'undefined');
}
{
  const w = page('');
  w.eval('window._fazGcm = { "default_settings": [], "wait_for_update": 500 };');
  try { w.eval(gcm); } catch (e) { /* partial env */ }
  ok('control: a plain _fazGcm still runs GCM', Array.isArray(w.dataLayer) || typeof w.gtag === 'function');
}

console.log('\ntcf-cmp.js: window._fazTcfConfig');
{
  const w = page('<a id="_fazTcfConfig"></a><a id="_fazTcfConfig" name="cmpId"></a>');
  ok('fixture: _fazTcfConfig.cmpId is answered by markup', !!(w._fazTcfConfig && w._fazTcfConfig.cmpId));
  try { w.eval(cmp); } catch (e) { /* partial env */ }
  let ping = null;
  try { w.__tcfapi('ping', 2, (r) => { ping = r; }); } catch (e) { /* no api */ }
  ok('the CMP does not report an element as its CMP id', !!ping && typeof ping.cmpId === 'number');
}

console.log(`\n  frontend-config-clobbering: ${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);
