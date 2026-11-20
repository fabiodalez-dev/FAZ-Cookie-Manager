/**
 * The runtime restores a signed park made by another response once the
 * park-proofs route vouches for it.
 *
 * Markup a page fetches later (infinite scroll, "load more") is parked by its
 * own response, under its own key, so `_fazParkProven()` refused it and its
 * style, background or object data stayed parked after consent until a
 * reload. The server signs what it parks (data-faz-park-sig); the restore
 * pass now sends such a tag to POST /faz/v1/park-proofs and, for a tag the
 * route confirms and whose values have not changed since, gives it this
 * page's key and restores it. Unsigned (author-written) proofs are never
 * sent and a refusal leaves the tag parked. A temporary failure is retried
 * once without relaxing any of the proof checks.
 *
 * fetch() is a stub standing in for the route; what it answers is decided
 * per test, by signature.
 *
 * Run: node tests/unit/js/park-proof-verify.test.mjs
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const runtimeCode = readFileSync(resolve(HERE, '../../../frontend/js/script.js'), 'utf8');
const SITE = 'http://site.test/';
const ENDPOINT = 'http://site.test/wp-json/faz/v1/park-proofs';
const GOOD = 'a'.repeat(64);
const BAD = 'b'.repeat(64);

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
const ok = (label, cond) => eq(label, !!cond, true);
const settle = (ms = 30) => new Promise((r) => setTimeout(r, ms));

function page(body, { endpoint = ENDPOINT, answer = 'by-signature', beforeAnswer = null } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html><head></head><body>${body}</body></html>`, { runScripts: 'outside-only', url: SITE });
  const { window } = dom;
  const calls = [];
  window.fetch = (url, init) => {
    const sent = JSON.parse(init.body);
    calls.push({ url: String(url), method: init.method, items: sent.items });
    if (answer === 'reject' || (answer === 'offline-once' && calls.length === 1)) {
      return Promise.reject(new Error('offline'));
    }
    if (beforeAnswer) beforeAnswer(window);
    const valid = sent.items.map((it) => it.sig === GOOD);
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ valid }) });
  };
  window._fazConfig = {
    _block: '1',
    _srcdocKey: 'page-key',
    _parkProofEndpoint: endpoint,
    _categories: [
      { slug: 'necessary', isNecessary: true },
      { slug: 'marketing', isNecessary: false },
    ],
    _services: [],
    _providersToBlock: [{ re: 'tracker.test', categories: ['marketing'], service: 'tracker' }],
    _userWhitelist: [],
    _perServiceConsent: false,
    _perCookieConsent: false,
    _aggressiveCssUrlBlocking: false,
    i18n: {},
  };
  window.fazcookie = { _fazGetFromStore: () => undefined };
  const realAdd = window.document.addEventListener.bind(window.document);
  window.document.addEventListener = (type, ...rest) => (type === 'DOMContentLoaded' ? undefined : realAdd(type, ...rest));
  window.eval(runtimeCode);
  window.document.addEventListener = realAdd;
  return { window, calls };
}
const consent = (w) => { w.fazcookie._fazGetFromStore = (k) => ({ marketing: 'yes', necessary: 'yes' }[k]); };
const restore = (w) => w.eval('_fazUnblockServerSide()');

const CSS = 'background:url(https://tracker.test/hero.jpg)';
const styleTag = (sig, key = 'ajax-response-key') =>
  `<div id="h" data-faz-category="marketing" data-faz-park="style" data-faz-park-key="${key}"${sig ? ` data-faz-park-sig="${sig}"` : ''} data-faz-style="${CSS}" style="">x</div>`;

console.log('1. a signed style parked by another response');
{
  const { window: w, calls } = page(styleTag(GOOD));
  const h = w.document.getElementById('h');
  restore(w);
  await settle();
  eq('premise: before consent nothing is asked', calls.length, 0);
  consent(w);
  restore(w);
  eq('premise: the foreign key is not proven by itself', h.getAttribute('style'), '');
  await settle();
  eq('on consent the route is asked once', calls.length, 1);
  eq('at the configured endpoint', calls[0] && calls[0].url, ENDPOINT);
  eq('with POST', calls[0] && calls[0].method, 'POST');
  eq('sending the names, the values as read and the signature', JSON.stringify(calls[0] && calls[0].items), JSON.stringify([{ names: ['style'], values: [CSS], sig: GOOD }]));
  eq('once confirmed the style is restored', h.getAttribute('style'), CSS);
  eq('and the parked copy removed', h.getAttribute('data-faz-style'), null);
  eq('under this page\'s key', h.getAttribute('data-faz-park-key'), 'page-key');
}

console.log('\n2. refused, unsigned, failed or changed: stays parked');
{
  const { window: w, calls } = page(styleTag(BAD));
  consent(w);
  restore(w);
  await settle();
  const h = w.document.getElementById('h');
  eq('a signature the route refuses is asked about', calls.length, 1);
  eq('and the style stays parked', h.getAttribute('data-faz-style'), CSS);
  eq('with its own key', h.getAttribute('data-faz-park-key'), 'ajax-response-key');
  restore(w);
  await settle();
  eq('a tag is asked about once, not on every restore', calls.length, 1);
}
{
  const { window: w, calls } = page(styleTag(''));
  consent(w);
  restore(w);
  await settle();
  eq('an author-written proof without a signature is never sent', calls.length, 0);
  eq('and stays parked', w.document.getElementById('h').getAttribute('data-faz-style'), CSS);
}
{
  const { window: w, calls } = page(styleTag(GOOD), { answer: 'reject' });
  consent(w);
  restore(w);
  await settle(600);
  restore(w);
  await settle();
  eq('a persistent failure is retried only once', calls.length, 2);
  eq('a repeated failure leaves the style parked', w.document.getElementById('h').getAttribute('style'), '');
}
{
  const { window: w, calls } = page(styleTag(GOOD), { answer: 'offline-once' });
  consent(w);
  restore(w);
  await settle(600);
  eq('a temporary failure is retried once', calls.length, 2);
  eq('a valid proof received on retry restores the style', w.document.getElementById('h').getAttribute('style'), CSS);
}
{
  const { window: w } = page(styleTag(GOOD), {
    beforeAnswer: (win) => win.document.getElementById('h').setAttribute('data-faz-style', 'background:url(https://evil.test/x)'),
  });
  consent(w);
  restore(w);
  await settle();
  const h = w.document.getElementById('h');
  eq('a value changed while the route answered is not restored', h.getAttribute('style'), '');
  eq('and keeps its own key', h.getAttribute('data-faz-park-key'), 'ajax-response-key');
}
{
  const { window: w, calls } = page(styleTag(GOOD), { endpoint: '' });
  consent(w);
  restore(w);
  await settle();
  eq('without an endpoint in the config nothing is sent', calls.length, 0);
}
{
  const { window: w, calls } = page(styleTag(GOOD, 'page-key'));
  consent(w);
  restore(w);
  await settle();
  eq('a park under this page\'s own key needs no request', calls.length, 0);
  eq('and is restored as before', w.document.getElementById('h').getAttribute('style'), CSS);
}

console.log('\n3. object data and background');
{
  const URL_ = 'https://tracker.test/embed/ID';
  const BG = 'https://tracker.test/bg.png';
  const { window: w, calls } = page(
    `<object id="o" data-faz-category="marketing" data-faz-park="data" data-faz-park-key="ajax-response-key" data-faz-park-sig="${GOOD}" data-faz-data="${URL_}"></object>`
    + `<table><tr><td id="t" data-faz-category="marketing" data-faz-park="background" data-faz-park-key="ajax-response-key" data-faz-park-sig="${GOOD}" data-faz-background="${BG}">x</td></tr></table>`,
  );
  consent(w);
  restore(w);
  await settle();
  eq('both are asked about in one request', calls.length === 1 && calls[0].items.length, 2);
  eq('a confirmed <object data> is restored', w.document.getElementById('o').getAttribute('data'), URL_);
  eq('a confirmed background is restored', w.document.getElementById('t').getAttribute('background'), BG);
}

console.log(`\npark proof verify: ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
