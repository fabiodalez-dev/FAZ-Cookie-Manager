/**
 * imagesrcset is a CANDIDATE LIST, and a list cannot be judged by its first URL.
 *
 * The first version of the fix passed the whole attribute to _fazImgShouldBlock(),
 * which bails on anything that looks same-origin. "/local.jpg 1x,
 * https://tracker/px 2x" begins with "/", so it returned false and the tracker
 * candidate was never examined — the tag was parked-eligible on paper and
 * loading in fact.
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const HERE = dirname(fileURLToPath(import.meta.url));
const SCRIPT = resolve(HERE, '../../../frontend/js/script.js');
let passed = 0, failed = 0;
const ok = (label, cond) => { if (cond) { passed++; console.log(`  PASS ${label}`); } else { failed++; console.log(`  FAIL ${label}`); } };

const dom = new JSDOM('<!doctype html><html><body></body></html>', { runScripts: 'outside-only', url: 'https://site.example/' });
const { window } = dom;
window._fazConfig = {
  _block: true,
  _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'marketing' }],
  _services: [],
  // Shape the runtime actually reads: { re, categories }, matched against the
  // provider target with a boundary check.
  _providersToBlock: [{ re: 'tracker.example', categories: ['marketing'] }],
  _userWhitelist: [], i18n: {},
  _bannerConfig: { settings: { type: 'box', preferenceCenterType: 'popup', applicableLaw: 'gdpr' }, config: {} },
};
Object.defineProperty(window.document, 'readyState', { get: () => 'loading', configurable: true });
const realAdd = window.document.addEventListener.bind(window.document);
window.document.addEventListener = (t, cb, ...r) => (t === 'DOMContentLoaded' ? undefined : realAdd(t, cb, ...r));
window.setTimeout = () => 0;
window.requestAnimationFrame = (cb) => { cb(); return 0; };
window.console.error = () => {}; window.console.warn = () => {};
window.eval(readFileSync(SCRIPT, 'utf8'));
window.document.addEventListener = realAdd;

const el = window.document.createElement('link');

// The shape that used to slip through: a same-origin FIRST candidate hiding a
// third-party one behind it.
const mixed = '/local.jpg 1x, https://tracker.example/px.png 2x';
ok(
  'a mixed candidate list is recognised as blocked (single-URL check said no)',
  !!window.fazcookie._fazSrcsetBlockedCategory(el, mixed),
);
ok(
  'and the single-URL check really does miss it — this is why the helper matters',
  window.fazcookie._fazImgShouldBlock(el, mixed) === false,
);
ok(
  'the blocked candidate decides the category, not the first one',
  window.fazcookie._fazSrcsetBlockedCategory(el, mixed) === 'marketing',
);
// A list that is entirely local must not be blocked.
ok(
  'an all-local list stays allowed',
  window.fazcookie._fazSrcsetBlockedCategory(el, '/a.jpg 1x, /b.jpg 2x') === '',
);
// Order must not matter.
ok(
  'the tracker is found when it comes first too',
  !!window.fazcookie._fazSrcsetBlockedCategory(el, 'https://tracker.example/px.png 1x, /local.jpg 2x'),
);

console.log(`\n  imagesrcset candidate list: ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
