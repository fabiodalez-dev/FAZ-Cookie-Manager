/**
 * JS unit test (jsdom) — the four high-priority leads of the 2026-11 security
 * audit, three of which live in the frontend runtime.
 *
 *   1. #fazBannerTemplate clobbering: post content (wp_kses_post keeps `id`)
 *      could ship <div id="fazBannerTemplate"> before the footer <template>,
 *      and getElementById() read the author's markup as the consent banner.
 *   2. Forged placeholder buttons: wp_kses_post keeps data-* on every tag, so
 *      any element carrying data-faz-accept granted consent on click. Only a
 *      server-built button, stamped with the response-local key, may.
 *   7. Author-written data-faz-data / data-faz-style / data-faz-background:
 *      restored into <object data>, style and background, which post kses
 *      refuses. Only a park this response made (response key + name) is.
 *   4. Context-blind <style> rewrite: the markup wrappers matched "<style>"
 *      text inside a quoted attribute value and rewrote it with quotes and
 *      `>`, turning attribute text into live markup (mutation XSS). Both the
 *      runtime gate (script.js) and the bootstrap's safety release
 *      (bootstrap.js parkMarkup) are pinned.
 *
 * (Lead 3, the double-encoded cookie-meta import, is a PHP test:
 * tests/unit/test-cookie-meta-double-encoded-php.php.)
 *
 * Run: node tests/unit/js/security-audit-high-priority.test.mjs
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const runtimeCode = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
const bootCode = readFileSync(resolve(HERE, '../../../frontend/js/bootstrap.js'), 'utf8');

let passed = 0;
let failed = 0;
function ok(label, cond) {
  if (cond) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}`); }
}
function safe(fn, fallback) { try { return fn(); } catch (e) { return fallback; } }

const KEY = 'test-response-key';

function runtime({ body = '', aggressive = false } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head></head><body>${body}</body></html>`, {
    runScripts: 'outside-only', url: 'https://site.test/',
  });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _srcdocKey: KEY,
    _categories: [
      { slug: 'necessary', isNecessary: true, ccpaDoNotSell: false, defaultConsent: { gdpr: true, ccpa: true } },
      { slug: 'functional', isNecessary: false, ccpaDoNotSell: false, defaultConsent: { gdpr: false, ccpa: true } },
      { slug: 'marketing', isNecessary: false, ccpaDoNotSell: false, defaultConsent: { gdpr: false, ccpa: true } },
    ],
    _services: [],
    _providersToBlock: [{ re: 'tracker.test', categories: ['marketing'], service: 'tracker' }],
    _cookieCategoryMap: {}, _whitelistedCookiePatterns: [], _userWhitelist: [],
    _perServiceConsent: false, _perCookieConsent: false, _rootDomain: '', _consentRevision: 1,
    _ageGate: { enabled: false },
    _aggressiveCssUrlBlocking: aggressive,
    _bannerConfig: { settings: { applicableLaw: 'gdpr' }, behaviours: { respectGPC: false }, config: { revisitConsent: { status: false } } },
    _shortCodes: [],
    i18n: {},
  };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  window.eval(runtimeCode);
  window.document.addEventListener = realAdd;
  return window;
}
const get = (w, k) => safe(() => w.fazcookie._fazConsentStore.get(k), undefined);

// ---------------------------------------------------------------- 1
console.log('\n1. the banner is read from the plugin\'s own template, not from post content');
{
  const forged = '<div id="fazBannerTemplate" hidden><div data-faz-tag="notice"><button data-faz-tag="accept-button">Reject All</button></div></div>';
  const real = '<template id="fazBannerTemplate"><div class="faz-consent-container">REAL-BANNER</div></template>';
  const w = runtime({ body: `<article>${forged}</article><footer>${real}</footer>` });
  const html = safe(() => w.eval('_fazReadBannerTemplate(_fazFindBannerTemplate())'), '');
  ok('the footer <template> wins over an earlier post-content element with the same id', html.indexOf('REAL-BANNER') !== -1);
  ok('the author\'s forged "Reject All" accept-button is never read', html.indexOf('Reject All') === -1);
}
{
  const w = runtime({ body: '<div id="fazBannerTemplate">FORGED</div>' });
  ok('a page with only a forged element has no banner template', safe(() => w.eval('_fazFindBannerTemplate()'), 'missing') === null);
}
{
  const w = runtime({ body: '<div id="fazBannerTemplate">FORGED</div><script type="text/template" id="fazBannerTemplate"><div>LEGACY<\\/div></script>' });
  const html = safe(() => w.eval('_fazReadBannerTemplate(_fazFindBannerTemplate())'), '');
  ok('the legacy <script type="text/template"> form is still read (pages cached before the update)', html.indexOf('LEGACY</div>') !== -1);
}

// ---------------------------------------------------------------- 2
console.log('\n2. only a server-built placeholder button grants consent');
function clickButton(w, attrs) {
  safe(() => w.eval('_fazWatchBannerElement()'));
  const btn = w.document.createElement('a');
  btn.href = '#';
  Object.entries(attrs).forEach(([k, v]) => btn.setAttribute(k, v));
  btn.textContent = 'Continue reading';
  w.document.body.appendChild(btn);
  btn.click();
}
{
  const w = runtime();
  clickButton(w, { 'data-faz-accept': 'marketing' });
  ok('a post-content link carrying data-faz-accept does not grant the category', get(w, 'marketing') !== 'yes');
}
{
  const w = runtime();
  clickButton(w, { 'data-faz-accept': 'marketing', 'data-faz-accept-key': 'guessed-or-stale' });
  ok('a wrong key does not grant it either', get(w, 'marketing') !== 'yes');
}
{
  const w = runtime();
  clickButton(w, { 'data-faz-accept': 'marketing', 'data-faz-accept-key': KEY });
  ok('control: the server-built button (response key) still grants the category', get(w, 'marketing') === 'yes');
}

// ---------------------------------------------------------------- 4
console.log('\n4a. runtime markup gate: only real <style> elements are rewritten');
const ATTR_PAYLOAD = '<abbr title="<style>u{background:url(//tracker.test/x)}<img src=x onerror=window.__pwned=1></style>">t</abbr>';
{
  const w = runtime({ aggressive: true });
  const d = w.document.createElement('div');
  d.innerHTML = ATTR_PAYLOAD;
  ok('"<style>" text inside a quoted attribute does not turn into a live <img>', d.querySelector('img') === null);
  ok('and the attribute keeps its original text', safe(() => d.querySelector('abbr').getAttribute('title'), '') === '<style>u{background:url(//tracker.test/x)}<img src=x onerror=window.__pwned=1></style>');
}
{
  const w = runtime({ aggressive: true });
  const d = w.document.createElement('div');
  d.insertAdjacentHTML('beforeend', ATTR_PAYLOAD);
  ok('same through insertAdjacentHTML', d.querySelector('img') === null);
}
{
  const w = runtime({ aggressive: true });
  const d = w.document.createElement('div');
  d.innerHTML = '<!-- <style>.p{background:url(https://tracker.test/c.png)}</style> --><b>ok</b>';
  ok('a <style> inside a comment is left as comment text', d.firstChild && d.firstChild.nodeType === 8 && d.firstChild.data.indexOf('tracker.test') !== -1);
}
{
  const w = runtime({ aggressive: true });
  const d = w.document.createElement('div');
  d.innerHTML = '<style media="a>b">.p{background:url(https://tracker.test/a.png)}</style><i>after</i>';
  const s = d.querySelector('style');
  ok('control: a real <style> (with ">" inside a quoted attribute) is still parked', !!s && !!s.getAttribute('data-faz-css'));
  ok('its live text no longer names the tracker', !!s && s.textContent.indexOf('tracker.test') === -1);
  ok('its own attributes are kept intact', !!s && s.getAttribute('media') === 'a>b');
  ok('and the markup after it is unchanged', !!d.querySelector('i') && d.querySelector('i').textContent === 'after');
}

// Markup the HTML parser does NOT read as a RAWTEXT <style>, while a scanner
// can: a foreign <style> (MathML/SVG) parses its content as markup, and the
// url("…") the rewrite writes then closes a quoted attribute early.
const SVG_NS = 'http://www.w3.org/2000/svg';
const DESYNC = {
  // </svg> inside <math> is ignored by the parser; a counter took it as a close.
  stray: '<math></svg><style><a title="url(https://tracker.test/x)</style><img src=x onerror=window.__pwned=1>">t</a></style></math>',
  // <!--<script> inside script data moves where </script> ends the script.
  script: '<script><!--<script></script><style>a{background:url(//tracker.test/<script>)}</style></script><img src=x onerror=window.__pwned=1>',
  // innerHTML on an SVG element parses in foreign content from the first byte.
  svgCtx: '<style><a title="url(https://tracker.test/x)</style><image href=x onerror=window.__pwned=1>">t</a></style>',
};
{
  // Control: without any wrapper, none of these strings carries live markup.
  const plain = new JSDOM('<!DOCTYPE html><body></body>').window.document;
  const d1 = plain.createElement('div'); d1.innerHTML = DESYNC.stray;
  const d2 = plain.createElement('div'); d2.innerHTML = DESYNC.script;
  const s3 = plain.createElementNS(SVG_NS, 'svg'); s3.innerHTML = DESYNC.svgCtx;
  ok('control: the parser alone builds no <img>/<image> from these strings', !d1.querySelector('img') && !d2.querySelector('img') && !s3.querySelector('image'));
}
console.log('\n4c. runtime markup gate: no rewrite where the parser and the scanner can disagree');
{
  const w = runtime({ aggressive: true });
  const d = w.document.createElement('div');
  d.innerHTML = DESYNC.stray;
  ok('a stray </svg> inside <math> does not make the foreign <style> look like a real one', d.querySelector('img') === null);
}
{
  const w = runtime({ aggressive: true });
  const d = w.document.createElement('div');
  d.innerHTML = DESYNC.script;
  ok('an escaped <!--<script> in script data does not end the script early after the rewrite', d.querySelector('img') === null);
}
{
  const w = runtime({ aggressive: true });
  const s = w.document.createElementNS(SVG_NS, 'svg');
  s.innerHTML = DESYNC.svgCtx;
  ok('innerHTML on an SVG element (foreign context) is not rewritten into live markup', s.querySelector('image') === null);
}
{
  const w = runtime({ aggressive: true });
  const s = w.document.createElementNS(SVG_NS, 'svg');
  w.document.body.appendChild(s);
  s.insertAdjacentHTML('beforeend', DESYNC.svgCtx);
  ok('same through insertAdjacentHTML inside an SVG element', s.querySelector('image') === null);
}
{
  const w = runtime({ aggressive: true });
  const d = w.document.createElement('div');
  d.innerHTML = '<style>.p{background:url(https://tracker.test/a.png)}</style><svg><path d="M0 0"/></svg>';
  const s = d.querySelector('style');
  ok('control: a real <style> before an inline SVG is still parked', !!s && !!s.getAttribute('data-faz-css'));
}

// Sheets the browser applies in or after foreign content: parked where the
// HTML and the foreign reading of the string agree (no "<" or "&" inside).
const FOREIGN_SHEETS = [
  ['an SVG <style>', '<svg><style>@import url(https://tracker.test/a.css);</style></svg>', null],
  ['a <style> in <foreignObject> (HTML integration point)', '<svg><foreignObject><style>.p{background:url(https://tracker.test/b.png)}</style></foreignObject></svg>', null],
  ['a <style> after a breakout tag closed the <svg>', '<svg><p>x</p><style>.p{background:url(https://tracker.test/c.png)}</style>', null],
  ['a <style> written into an SVG element (foreign context)', '<style>.p{background:url(https://tracker.test/d.png)}</style>', 'svg'],
];
function parkedIn(w, html, ctx, host) {
  const el = ctx === 'svg' ? w.document.createElementNS(SVG_NS, 'svg') : w.document.createElement('div');
  if (host) w.document.body.appendChild(el);
  el.innerHTML = html;
  const st = el.querySelector('style');
  return !!st && !!st.getAttribute('data-faz-css');
}
for (const [label, html, ctx] of FOREIGN_SHEETS) {
  ok(`${label} is still parked`, parkedIn(runtime({ aggressive: true }), html, ctx));
}

console.log('\n4b. bootstrap safety release: the same rule');
await (async () => {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: 'http://site.test/' });
  const w = dom.window;
  w._fazConfig = { _block: true, _categories: [], _providersToBlock: [] };
  Object.defineProperty(w.document, 'readyState', { value: 'loading', configurable: true });
  w.eval(bootCode);
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 50));
  const released = w.document.documentElement.className.indexOf('faz-consent-runtime-missing') !== -1;
  ok('precondition: the safety release is active (runtime missing)', released);
  const d = w.document.createElement('div');
  d.innerHTML = '<abbr title="<style>u{background:url(//a.example/x)}<img src=x onerror=window.__pwned=1></style>">t</abbr>';
  ok('"<style>" text inside a quoted attribute does not turn into a live <img>', d.querySelector('img') === null);
  const d2 = w.document.createElement('div');
  d2.innerHTML = '<style>.p{background:url(//a.example/x.png)}</style>';
  const s = d2.querySelector('style');
  ok('control: a real cross-origin <style> is still parked on the safety release', !!s && !!s.getAttribute('data-faz-css'));
  const x = (v) => v.replace(/tracker\.test/g, 'a.example');
  const d3 = w.document.createElement('div');
  d3.innerHTML = x(DESYNC.stray);
  ok('a stray </svg> inside <math> does not make the foreign <style> look like a real one', d3.querySelector('img') === null);
  const d4 = w.document.createElement('div');
  d4.innerHTML = x(DESYNC.script);
  ok('an escaped <!--<script> in script data does not end the script early after the rewrite', d4.querySelector('img') === null);
  const s5 = w.document.createElementNS(SVG_NS, 'svg');
  s5.innerHTML = x(DESYNC.svgCtx);
  ok('innerHTML on an SVG element (foreign context) is not rewritten into live markup', s5.querySelector('image') === null);
  const s6 = w.document.createElementNS(SVG_NS, 'svg');
  w.document.body.appendChild(s6);
  s6.insertAdjacentHTML('beforeend', x(DESYNC.svgCtx));
  ok('same through insertAdjacentHTML inside an SVG element', s6.querySelector('image') === null);
  for (const [label, html, ctx] of FOREIGN_SHEETS) {
    ok(`${label} is still parked`, parkedIn(w, x(html), ctx, true));
  }
})();

// ---------------------------------------------------------------- 7
console.log('\n7. author-written data-faz-* parks are not restored into kses-guarded sinks');
function restoreWith(body) {
  const w = runtime({ body });
  w.fazcookie = w.fazcookie || {};
  w.fazcookie._fazGetFromStore = (k) => ({ marketing: 'yes', functional: 'yes', necessary: 'yes' }[k]);
  safe(() => w.eval('_fazUnblockServerSide()'));
  return w;
}
{
  const w = restoreWith('<object id="o" type="application/pdf" data="https://site.test/x.pdf" data-faz-data="https://attacker.example/frame" data-faz-category="necessary"></object>');
  ok('an author-written data-faz-data does not replace <object data>', w.document.getElementById('o').getAttribute('data') === 'https://site.test/x.pdf');
}
{
  const w = restoreWith('<p id="p" data-faz-style="position:fixed;top:0;left:0;width:100%;height:100%" data-faz-category="necessary">x</p>');
  ok('an author-written data-faz-style is not written into style', !w.document.getElementById('p').getAttribute('style'));
}
{
  const w = restoreWith('<table><tr><td id="c" data-faz-background="https://attacker.example/b.png" data-faz-category="necessary">x</td></tr></table>');
  ok('an author-written data-faz-background is not restored', !w.document.getElementById('c').getAttribute('background'));
}
{
  const w = restoreWith('<p id="p" data-faz-park="style" data-faz-park-key="author-guess" data-faz-style="color:red" data-faz-category="necessary">x</p>');
  ok('a proof with the wrong key is refused', !w.document.getElementById('p').getAttribute('style'));
}
{
  const w = restoreWith(`<p id="p" data-faz-park="style" data-faz-park-key="${KEY}" data-faz-style="color:red" data-faz-category="marketing">x</p>`);
  ok('control: a park this response made is restored on consent', w.document.getElementById('p').getAttribute('style') === 'color:red');
}
{
  const w = restoreWith(`<p id="p" data-faz-park="background" data-faz-park-key="${KEY}" data-faz-style="color:red" data-faz-category="marketing">x</p>`);
  ok('a proof for another attribute does not cover style', !w.document.getElementById('p').getAttribute('style'));
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
