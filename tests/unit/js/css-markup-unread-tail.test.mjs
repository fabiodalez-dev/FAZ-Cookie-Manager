/**
 * JS unit test (jsdom) — the markup CSS gate ("aggressive CSS URL blocking")
 * no longer passes through what it could not read.
 *
 * _fazRewriteRealStyleTags() (script.js) and its twin rewriteRealStyleTags()
 * (bootstrap.js, safety release) walk a markup string with the tokenizer's
 * rules and stop wherever the HTML and the foreign reading of the string may
 * differ, or a construct is unterminated. They used to return everything from
 * that point on unchanged, so a later <style>@import url(tracker)</style> in
 * the same string reached the CSS engine before consent — and so did a
 * <style> with no end tag, which the parser closes at the end of the string.
 *
 * What is pinned here:
 *   - a blocked URL in a <style> past the point the walk stopped does not
 *     stay fetchable (held in place, or the string is cut before the sheet);
 *   - the content after it is kept wherever the URL could be held in place;
 *   - the hold only ever INSERTS inert characters, so it cannot turn
 *     sanitised markup into live markup (differential check against the
 *     parser alone);
 *   - consent hands the held sheet back;
 *   - a tail with nothing to block, and a site with the option off, are
 *     untouched.
 *
 * Run: node tests/unit/js/css-markup-unread-tail.test.mjs
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

const SVG_NS = 'http://www.w3.org/2000/svg';
const HELD = 'about:invalid#faz-held:';

function runtime({ aggressive = true } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', {
    runScripts: 'outside-only', url: 'https://site.test/',
  });
  const { window } = dom;
  window._fazConfig = {
    _block: '1',
    _srcdocKey: 'k',
    _categories: [
      { slug: 'necessary', isNecessary: true, ccpaDoNotSell: false, defaultConsent: { gdpr: true, ccpa: true } },
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

async function safetyRelease() {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: 'http://site.test/' });
  const w = dom.window;
  w._fazConfig = { _block: true, _categories: [], _providersToBlock: [] };
  Object.defineProperty(w.document, 'readyState', { value: 'loading', configurable: true });
  w.eval(bootCode);
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  await new Promise((r) => setTimeout(r, 50));
  return w;
}

// The text of every <style> under `root` that the CSS engine would still read
// a tracker URL from: a held URL is an about: URL and names nobody.
function liveSheets(root) {
  return Array.from(root.querySelectorAll('style')).map((s) => s.textContent);
}
function leaks(root) {
  return liveSheets(root).some((css) => {
    const withoutHeld = css.split(HELD).map((part, i) => (i === 0 ? part : part.replace(/^[^\s)"']*/, ''))).join('');
    return /tracker\.test/.test(withoutHeld);
  });
}
// What a mutation XSS would add: an element that loads or runs something, or
// any event-handler attribute.
function danger(root) {
  let n = root.querySelectorAll('img,image,iframe,script,object,embed,video,audio,link,base,form').length;
  root.querySelectorAll('*').forEach((el) => {
    Array.from(el.attributes).forEach((a) => { if (/^on/i.test(a.name) || /^(href|src|srcdoc|action|formaction)$/i.test(a.name)) n += 1; });
  });
  return n;
}
function write(w, html, { ctx = 'div', via = 'innerHTML', attach = false } = {}) {
  const doc = ctx === 'inert' ? w.document.implementation.createHTMLDocument('') : w.document;
  let el;
  if (ctx === 'svg') el = doc.createElementNS(SVG_NS, 'svg');
  else if (ctx === 'inert') el = doc.body;
  else el = doc.createElement('div');
  if (attach && ctx !== 'inert') w.document.body.appendChild(el);
  if (via === 'insertAdjacentHTML') el.insertAdjacentHTML('beforeend', html);
  else if (via === 'outerHTML') {
    const child = doc.createElement('span');
    el.appendChild(child);
    child.outerHTML = html;
  } else el.innerHTML = html;
  return el;
}

// An inline SVG whose <style> is wrapped in CDATA: what vector editors export,
// and markup in foreign content, where the walk stops.
const SVG_CDATA = '<svg><style><![CDATA[.a{fill:red}]]></style><path d="M0 0"/></svg>';

// [label, html, options, content kept?]
const LEAKS = [
  ['a <style> with no end tag (the parser closes it at the end of the string)',
    '<p class="before">b</p><style>@import url(https://tracker.test/a.css);', {}, false],
  ['a <style> whose end tag is never closed',
    '<p class="before">b</p><style>@import url(https://tracker.test/a2.css);</style ', {}, false],
  ['a <style> after an inline SVG with a CDATA sheet',
    SVG_CDATA + '<style>@import url(https://tracker.test/b.css);</style><p class="after">kept</p>', {}, true],
  ['the same, with a quoted url() and a background image',
    SVG_CDATA + '<style>.p{background:url("https://tracker.test/c.png")}</style><p class="after">kept</p>', {}, true],
  ['the same, with a query string ("=" and "&") in the URL',
    SVG_CDATA + '<style>@import url(https://tracker.test/css2?family=Roboto&display=swap);</style><p class="after">kept</p>', {}, true],
  ['the same, with the string form of @import',
    SVG_CDATA + '<style>@import "https://tracker.test/d.css";</style><p class="after">kept</p>', {}, true],
  ['the same, protocol-relative',
    SVG_CDATA + '<style>@import url(//tracker.test/e.css);</style><p class="after">kept</p>', {}, true],
  ['a <style> after an SVG sheet with markup in it',
    '<svg><style>.a{fill:red}<b></b></style></svg><style>.p{background:url(https://tracker.test/f.png)}</style><p class="after">kept</p>', {}, true],
  ['the SVG sheet with markup in it, itself',
    '<svg><style>@import url(https://tracker.test/g.css);<b></b></style></svg><p class="after">kept</p>', {}, true],
  ['a <style> after a script that left the double-escaped state',
    '<script><!--<script></script></script><style>@import url(https://tracker.test/h.css);</style><p class="after">kept</p>', {}, true],
  ['a <style> after <noscript>, written into a document without a window',
    '<noscript><p>x</p></noscript><style>@import url(https://tracker.test/i.css);</style><p class="after">kept</p>', { ctx: 'inert' }, true],
  ['a <style> after a CDATA sheet, through insertAdjacentHTML',
    SVG_CDATA + '<style>@import url(https://tracker.test/j.css);</style><p class="after">kept</p>', { via: 'insertAdjacentHTML', attach: true }, true],
  ['a <style> after a CDATA sheet, through outerHTML',
    SVG_CDATA + '<style>@import url(https://tracker.test/k.css);</style><p class="after">kept</p>', { via: 'outerHTML', attach: true }, true],
  ['a <style> after a CDATA sheet, written into an SVG element',
    '<style><![CDATA[.a{fill:red}]]></style><style>@import url(https://tracker.test/l.css);</style>', { ctx: 'svg' }, false],
];

// Spellings no in-place hold can reach: the string is cut before the sheet,
// and what came before it stays.
const CUT = [
  ['a comment between @import and its string',
    SVG_CDATA + '<p class="before">b</p><style>@import/**/"https://tracker.test/m.css";</style><p class="after">a</p>'],
  ['a CSS escape in the function name',
    SVG_CDATA + '<p class="before">b</p><style>.p{background:u\\72l(https://tracker.test/n.png)}</style><p class="after">a</p>'],
  ['an image-set() string',
    SVG_CDATA + '<p class="before">b</p><style>.p{background:image-set("https://tracker.test/o.png" 1x)}</style><p class="after">a</p>'],
  ['a URL spelled with character references in a second SVG sheet',
    '<p class="before">b</p><svg><style><![CDATA[.a{fill:red}]]></style><style>@import url(&#47;&#47;tracker.test/p.css);</style></svg>'],
  ['a url( left open at the end of the string',
    '<p class="before">b</p><style>.p{background:url(https://tracker.test/q.png'],
];

// Strings a rewrite must not make live. Each is harmless to the parser alone;
// the first three are the shapes that break a hold which replaces a URL or
// writes quotes (a quote removed or added moves where an attribute ends).
const MXSS = [
  ['a quote inside the matched URL closes an attribute if the URL is replaced',
    SVG_CDATA + '<style>a{}</style><a title=\'url("https://tracker.test/\' data-x=")\' onmouseover=window.__pwned=1 x=\'">t</a>'],
  ['a url() inside a double-quoted attribute value',
    SVG_CDATA + '<style>a{}</style><p title="url(https://tracker.test/x) onmouseover=window.__pwned=1 ">t</p>'],
  ['a quoted url() right after a closing attribute quote',
    SVG_CDATA + '<style>a{}</style><p title="url("https://tracker.test/a")=" onmouseover=window.__pwned=1 ">t</p>'],
  ['@import string inside a single-quoted attribute value',
    SVG_CDATA + '<style>a{}</style><p title=\'@import "https://tracker.test/y.css" onmouseover=window.__pwned=1 \'>t</p>'],
  ['a foreign <style> holding an attribute that hides markup',
    '<math></svg><style><a title="url(https://tracker.test/x)</style><img src=x onerror=window.__pwned=1>">t</a></style></math>'],
  ['a <style> inside a comment after the stop',
    SVG_CDATA + '<!-- <style>@import url(https://tracker.test/z.css);</style> --><b title="--><img src=x onerror=window.__pwned=1>">t</b>'],
  ['"<style" text inside an attribute, cut there',
    SVG_CDATA + '<p title="<style >@import/**/\'https://tracker.test/w.css\'"><b title="<img src=x onerror=window.__pwned=1>">t</b></p>'],
];

function plainDanger(html, opts = {}) {
  const w = new JSDOM('<!DOCTYPE html><body></body>', { url: 'https://site.test/' }).window;
  return danger(write(w, html, opts));
}

function suite(label, makeWindow, host) {
  const x = (s) => s.replace(/tracker\.test/g, host);
  const leaksHere = (root) => leaks({ querySelectorAll: (q) => Array.from(root.querySelectorAll(q)).map((s) => ({ textContent: s.textContent.split(host).join('tracker.test') })) });

  console.log(`\n${label}: a blocked URL past the point the walk stopped is not fetchable`);
  for (const [name, html, opts, kept] of LEAKS) {
    const w = makeWindow();
    const el = write(w, x(html), opts);
    ok(`${name}: no tracker URL is left live`, !leaksHere(el));
    if (kept) ok(`${name}: the content after it is kept`, !!el.querySelector('.after'));
    if (/class="before"/.test(html)) ok(`${name}: the content before it is kept`, !!el.querySelector('.before'));
  }

  console.log(`\n${label}: a spelling that cannot be held in place cuts the string before the sheet`);
  for (const [name, html] of CUT) {
    const w = makeWindow();
    const el = write(w, x(html));
    ok(`${name}: no tracker URL is left live`, !leaksHere(el));
    ok(`${name}: the content before the sheet is kept`, !!el.querySelector('.before'));
  }

  console.log(`\n${label}: the hold never makes markup live`);
  for (const [name, html] of MXSS) {
    const w = makeWindow();
    const el = write(w, x(html));
    ok(`${name}`, danger(el) <= plainDanger(x(html)) && !w.__pwned);
  }

  console.log(`\n${label}: nothing to block, nothing changed`);
  {
    const html = SVG_CDATA + '<style>.p{background:url(/local.png)}</style><p class="after">kept</p>';
    const w = makeWindow();
    const el = write(w, html);
    const plain = write(new JSDOM('<!DOCTYPE html><body></body>', { url: 'https://site.test/' }).window, html);
    ok('a tail whose sheets name only the site itself is delivered as written', el.innerHTML === plain.innerHTML);
  }
}

suite('runtime', () => runtime(), 'tracker.test');

console.log('\nruntime: consent hands a held sheet back');
{
  const w = runtime();
  const el = write(w, SVG_CDATA + '<style id="held">@import url(https://tracker.test/b.css);.x{color:red}</style>', { attach: true });
  const st = el.querySelector('#held');
  ok('precondition: the sheet is held, with the rest of its rules as written', !!st && st.textContent.indexOf(HELD) !== -1 && st.textContent.indexOf('.x{color:red}') !== -1);
  safe(() => w.eval('_fazUnblockServerSide()'));
  ok('without consent the restore pass leaves it held', st.textContent.indexOf(HELD) !== -1);
  w.fazcookie = w.fazcookie || {};
  w.fazcookie._fazGetFromStore = (k) => ({ marketing: 'yes', necessary: 'yes' }[k]);
  safe(() => w.eval('_fazUnblockServerSide()'));
  ok('with consent the original text is back', st.textContent === '@import url(https://tracker.test/b.css);.x{color:red}');
}

console.log('\nruntime: the option off leaves markup alone');
{
  const html = SVG_CDATA + '<style>@import url(https://tracker.test/b.css);</style>';
  const w = runtime({ aggressive: false });
  const el = write(w, html);
  const plain = write(new JSDOM('<!DOCTYPE html><body></body>', { url: 'https://site.test/' }).window, html);
  ok('with aggressive CSS URL blocking off the string is not rewritten', el.innerHTML === plain.innerHTML);
}

const released = await safetyRelease();
ok('\nprecondition: the bootstrap safety release is active (runtime missing)', released.document.documentElement.className.indexOf('faz-consent-runtime-missing') !== -1);
suite('bootstrap safety release', () => released, 'a.example');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
