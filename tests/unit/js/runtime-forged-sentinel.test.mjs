/**
 * A forged `data-faz-*` park attribute does not exempt a live URL from the
 * runtime's park helper.
 *
 * _fazParkResourceElementIfBlocked() skipped poster, srcset, object data, the
 * SVG href, the legacy background attribute, link href and imagesrcset when
 * the matching data-faz-* key was already present, as if the element had been
 * parked. Markup written after the handoff can carry that key next to a live
 * URL; the server stopped trusting such sentinels ("a bare sentinel does not
 * exempt"), the runtime did not. A forged data-faz-category of an allowed
 * category must not label a parked URL either.
 *
 * Run: node tests/unit/js/runtime-forged-sentinel.test.mjs
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';

const code = readFileSync(new URL('../../../frontend/js/script.js', import.meta.url), 'utf8');
let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}\n       expected: ${JSON.stringify(expected)}  actual: ${JSON.stringify(actual)}`); }
}
const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { runScripts: 'outside-only', url: 'http://site.test/' });
const w = dom.window;
w._fazConfig = {
  _block: '1',
  _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'marketing', isNecessary: false }],
  _services: [], _providersToBlock: [{ re: 'ytimg.com', categories: ['marketing'] }, { re: 'youtube.com/embed', categories: ['marketing'] }],
  _userWhitelist: [], i18n: {},
};
w.fazcookie = { _fazGetFromStore: () => undefined };
w.console.error = () => {};
const add = w.document.addEventListener.bind(w.document);
w.document.addEventListener = (t, ...r) => (t === 'DOMContentLoaded' ? undefined : add(t, ...r));
w.eval(code);
w.document.addEventListener = add;

const Y = 'https://i.ytimg.com/vi/ID/hq.jpg';
w.document.body.innerHTML = [
  `<video id="poster" data-faz-poster="x" poster="${Y}"></video>`,
  `<img id="srcset" data-faz-srcset="x" srcset="${Y} 1x">`,
  `<object id="data" data-faz-data="x" data="https://www.youtube.com/embed/ID"></object>`,
  `<svg><image id="svg" data-faz-href="x" href="${Y}"></image></svg>`,
  `<table id="bg" data-faz-background="x" background="${Y}"></table>`,
  `<link id="href" rel="stylesheet" data-faz-href="x" href="https://i.ytimg.com/a.css">`,
  `<link id="iss" rel="preload" as="image" data-faz-imagesrcset="x" imagesrcset="${Y} 1x">`,
  `<video id="label" data-faz-category="necessary" poster="${Y}"></video>`,
].join('');
await new Promise((r) => setTimeout(r, 10));
const get = (id, at) => w.document.getElementById(id).getAttribute(at);
eq('a forged data-faz-poster does not keep a live provider poster', get('poster', 'poster'), null);
eq('a forged data-faz-srcset does not keep a live provider srcset', get('srcset', 'srcset'), null);
eq('a forged data-faz-data does not keep a live provider object', get('data', 'data'), null);
eq('a forged data-faz-href does not keep a live SVG href', get('svg', 'href'), null);
eq('a forged data-faz-background does not keep a live background', get('bg', 'background'), null);
eq('a forged data-faz-href does not keep a live stylesheet', get('href', 'href'), null);
eq('a forged data-faz-imagesrcset does not keep a live preload candidate', get('iss', 'imagesrcset'), null);
eq('the parked poster keeps its real URL for the restore pass', get('poster', 'data-faz-poster'), Y);
eq('a forged allowed category does not label a parked provider URL', get('label', 'data-faz-category'), 'marketing');
console.log(`\n  runtime-forged-sentinel: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
