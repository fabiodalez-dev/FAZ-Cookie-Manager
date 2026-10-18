/**
 * A "lite embed" inserted at runtime keeps its facade.
 *
 * A lite embed is an <iframe srcdoc="…play button…" src="https://provider/…">:
 * while srcdoc is present the engine ignores src and shows the srcdoc
 * document, so parking src is enough and there is nothing to hide. The server
 * applies that rule, and the src setter does too; the runtime observer did not:
 * a frame built after the handoff had its src parked and was then removed and
 * replaced by the consent placeholder, so the site's own facade disappeared.
 *
 * Run: node tests/unit/js/lite-embed-runtime-frame.test.mjs
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';

const code = readFileSync(new URL('../../../frontend/js/script.js', import.meta.url), 'utf8');
const TRACKER = 'https://tracker.test/embed/ID';
let passed = 0;
let failed = 0;
function eq(label, actual, expected) {
  if (actual === expected) { passed += 1; console.log(`  \x1b[32mPASS\x1b[0m ${label}`); }
  else { failed += 1; console.log(`  \x1b[31mFAIL\x1b[0m ${label}\n       expected: ${JSON.stringify(expected)}  actual: ${JSON.stringify(actual)}`); }
}
function runtime() {
  const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><div id="w"></div></body></html>', { runScripts: 'outside-only', url: 'http://site.test/' });
  const w = dom.window;
  w._fazConfig = {
    _block: '1', _srcdocKey: 'k',
    _categories: [{ slug: 'necessary', isNecessary: true }, { slug: 'marketing', isNecessary: false }],
    _services: [], _providersToBlock: [{ re: 'tracker.test', categories: ['marketing'], service: 'tracker' }],
    _userWhitelist: [], _perServiceConsent: false, _perCookieConsent: false,
    _bannerConfig: { settings: { applicableLaw: 'gdpr' }, behaviours: {}, config: { videoPlaceholder: { status: true, styles: {} } } },
    i18n: {}, _placeholderText: 'x',
    _shortCodes: [{ key: 'faz_video_placeholder', content: '<div class="faz-iframe-placeholder" id="[UNIQUEID]">consent</div>' }],
  };
  w.fazcookie = { _fazGetFromStore: () => undefined };
  w.requestAnimationFrame = (cb) => setTimeout(cb, 0);
  w.console.error = () => {};
  const add = w.document.addEventListener.bind(w.document);
  w.document.addEventListener = (t, ...r) => (t === 'DOMContentLoaded' ? undefined : add(t, ...r));
  w.eval(code);
  w.document.addEventListener = add;
  return w;
}
const tick = () => new Promise((r) => setTimeout(r, 20));

for (const [label, insert] of [
  ['innerHTML', (w) => { w.document.getElementById('w').innerHTML = `<iframe srcdoc="&lt;button&gt;Play&lt;/button&gt;" src="${TRACKER}"></iframe>`; }],
  ['createElement + appendChild', (w) => { const f = w.document.createElement('iframe'); f.setAttribute('srcdoc', '<button>Play</button>'); f.src = TRACKER; w.document.getElementById('w').appendChild(f); }],
]) {
  const w = runtime();
  await tick();
  insert(w);
  await tick();
  const f = w.document.querySelector('#w iframe');
  eq(label + ': the lite-embed frame stays in the page', !!(f && f.isConnected), true);
  eq(label + ': with its facade', f && f.getAttribute('srcdoc'), '<button>Play</button>');
  eq(label + ': and no live provider src', f && f.getAttribute('src'), null);
  eq(label + ': the provider src is parked for consent', f && f.getAttribute('data-faz-src'), TRACKER);
  eq(label + ': no consent placeholder replaces it', !!w.document.querySelector('.faz-iframe-placeholder'), false);
}
{
  // Control: a plain provider frame is still replaced by the placeholder.
  const w = runtime();
  await tick();
  w.document.getElementById('w').innerHTML = `<iframe src="${TRACKER}"></iframe>`;
  await tick();
  eq('control: a frame with no srcdoc still gets the placeholder', !!w.document.querySelector('.faz-iframe-placeholder'), true);
}
console.log(`\n  lite-embed-runtime-frame: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
