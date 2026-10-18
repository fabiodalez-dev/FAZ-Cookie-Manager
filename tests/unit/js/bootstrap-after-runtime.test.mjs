/**
 * JS unit test (jsdom) — a bootstrap that runs AFTER the runtime stands down.
 *
 * The bootstrap is printed inline before the deferred runtime, and on an
 * ordinary page it runs first. An optimiser that rewrites inline scripts into
 * deferred ones (LiteSpeed "Load JS Deferred" ignoring the opt-out attributes,
 * or a delay-until-interaction mode that releases scripts out of order) can
 * run it after the runtime instead. The runtime then never saw a bootstrap,
 * so it never called start()/finish(); the bootstrap installed its
 * interceptors on top of the runtime's own gates, released itself as if the
 * runtime were missing, and stayed in that state for the whole page view.
 *
 * Pinned here: when the runtime has already started, the bootstrap installs
 * nothing, and the runtime announces that it has started before anything a
 * late bootstrap could read.
 *
 * Run: node tests/unit/js/bootstrap-after-runtime.test.mjs
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const boot = readFileSync(resolve(HERE, '../../../frontend/js/bootstrap.js'), 'utf8');
const runtime = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');

let passed = 0;
let failed = 0;
function ok(label, cond) {
  if (cond) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}

function page() {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: 'http://site.test/' });
  const w = dom.window;
  w._fazConfig = { _block: true, _categories: [], _providersToBlock: [] };
  return w;
}

console.log('\nthe runtime announces itself');
{
  const w = page();
  try { w.eval(runtime); } catch (e) { /* no banner DOM in this fixture */ }
  ok('a runtime that has a store marks itself as started', w._fazRuntimeStarted === true);
}
{
  const w = page();
  w._fazConfig._staticConfigRequired = true; // the static file never arrived
  try { w.eval(runtime); } catch (e) { /* no store, nothing to mount */ }
  ok('a runtime without its store does not claim to have started (the bootstrap must still protect the page)', w._fazRuntimeStarted !== true);
}

console.log('\na bootstrap that arrives after the runtime installs nothing');
{
  const w = page();
  const natives = {
    setAttribute: w.Element.prototype.setAttribute,
    appendChild: w.Node.prototype.appendChild,
    fetch: w.fetch,
    write: w.Document.prototype.write,
  };
  w._fazRuntimeStarted = true;
  w.eval(boot);
  ok('no handoff object is published', w._fazBootstrap === undefined);
  ok('setAttribute is the native', w.Element.prototype.setAttribute === natives.setAttribute);
  ok('appendChild is the native', w.Node.prototype.appendChild === natives.appendChild);
  ok('fetch is the native', w.fetch === natives.fetch);
  ok('document.write is the native', w.Document.prototype.write === natives.write);
  await new Promise((r) => setTimeout(r, 0));
  ok('and no runtime-missing marker is written', w.document.documentElement.className.indexOf('faz-consent-runtime-missing') === -1);
}

console.log('\ncontrol: before the runtime the bootstrap still arms');
{
  const w = page();
  Object.defineProperty(w.document, 'readyState', { value: 'loading', configurable: true });
  const native = w.Element.prototype.setAttribute;
  w.eval(boot);
  ok('the handoff object is published', typeof w._fazBootstrap === 'object' && w._fazBootstrap !== null);
  ok('setAttribute is wrapped', w.Element.prototype.setAttribute !== native);
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
