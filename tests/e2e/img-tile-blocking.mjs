/**
 * Regression for img-tile blocking (#163).
 *
 * Map widgets (Leaflet / OpenStreetMap, Bricks Map, …) draw themselves by
 * loading map tiles as runtime-injected <img>, which FAZ's script/iframe/
 * fetch blocker never intercepted. The HTMLImageElement `src` setter is now
 * gated: a cross-origin image whose URL matches a blocked provider in a
 * denied category is parked (URL → data-faz-src, no network request) until
 * consent, then restored by the standard img[data-faz-src] pass.
 *
 * Asserts, on faz-test with no consent:
 *   - an OpenStreetMap tile <img> is parked (no request fires);
 *   - a same-origin image is untouched (fast path — no per-image scan);
 *   - a non-provider cross-origin image is untouched;
 *   - a real Leaflet OSM map loads zero tiles before consent;
 *   - accept-all restores and loads the parked tiles.
 *
 * Run: WP_BASE_URL=http://127.0.0.1:9998 node tests/e2e/img-tile-blocking.mjs
 */

import { chromium } from '@playwright/test';

const WP = process.env.WP_BASE_URL || 'http://127.0.0.1:9998';

let failures = 0;
function assert(name, cond) { console.log(`  ${cond ? 'PASS' : 'FAIL'} ${name}`); if (!cond) failures++; }

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const tileRequests = [];
page.on('request', (r) => { if (r.url().includes('tile.openstreetmap')) tileRequests.push(r.url()); });

try {
  await page.goto(`${WP}/?nocache=imgtile`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForFunction(() => window.fazcookie && window.fazcookie._diag && window.fazcookie._diag().ready, { timeout: 8000 });

  const ready = await page.evaluate(() => ({
    block: (window._fazConfig || {})._block,
    consent: window.fazcookie._diag().hasConsentCookie,
    osmKnown: ((window._fazConfig || {})._providersToBlock || []).some((p) => /openstreetmap/i.test(p.re || '')),
  }));
  assert('precondition: blocking on, no consent, OSM is a known provider', ready.block === '1' && !ready.consent && ready.osmKnown);

  // Synthetic image checks
  const synth = await page.evaluate(async () => {
    const mk = (u) => { const i = new Image(); i.src = u; return i; };
    const tile = mk('https://tile.openstreetmap.org/17/69083/45877.png');
    const same = mk(location.origin + '/wp-content/x-local.png');
    const other = mk('https://example.com/photo.jpg');
    await new Promise((r) => setTimeout(r, 200));
    return {
      tileParked: !!tile.getAttribute('data-faz-src') && tile.getAttribute('src') === '' ? true : (!tile.src),
      tileCat: tile.getAttribute('data-faz-category'),
      sameUntouched: !same.getAttribute('data-faz-src'),
      otherUntouched: !other.getAttribute('data-faz-src'),
    };
  });
  assert('OSM tile <img> parked (data-faz-src, no src) pre-consent', synth.tileParked === true);
  assert('parked tile tagged with the provider category', synth.tileCat === 'functional');
  assert('same-origin image untouched (fast path)', synth.sameUntouched);
  assert('non-provider cross-origin image untouched', synth.otherUntouched);

  // Real Leaflet map
  const leaf = await page.evaluate(async () => {
    await new Promise((res, rej) => {
      const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(css);
      const js = document.createElement('script'); js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; js.onload = res; js.onerror = rej; document.head.appendChild(js);
    });
    const div = document.createElement('div'); div.id = 'faz-osm-test'; div.style.cssText = 'width:400px;height:300px'; document.body.appendChild(div);
    const map = window.L.map('faz-osm-test').setView([45.07, 7.69], 13);
    window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    await new Promise((r) => setTimeout(r, 1500));
    const tiles = Array.from(document.querySelectorAll('#faz-osm-test img'));
    return { count: tiles.length, parked: tiles.filter((t) => t.getAttribute('data-faz-src')).length, loaded: tiles.filter((t) => (t.src || '').includes('tile.openstreetmap')).length };
  });
  assert('Leaflet created tiles', leaf.count > 0);
  assert('all Leaflet OSM tiles parked, none loaded pre-consent', leaf.parked === leaf.count && leaf.loaded === 0);
  assert('zero network requests to tile.openstreetmap before consent', tileRequests.length === 0);

  // Accept → restore
  const restored = await page.evaluate(async () => {
    const btn = document.querySelector('[data-faz-tag="accept-button"], .faz-accept-all, #faz-accept-all');
    if (btn) btn.click();
    await new Promise((r) => setTimeout(r, 1500));
    const tiles = Array.from(document.querySelectorAll('#faz-osm-test img'));
    return { loaded: tiles.filter((t) => (t.src || '').includes('tile.openstreetmap')).length, parked: tiles.filter((t) => t.getAttribute('data-faz-src')).length, consent: window.fazcookie._diag().hasConsentCookie };
  });
  assert('accept-all restores parked tiles (src reloaded, data-faz-src cleared)', restored.consent && restored.loaded > 0 && restored.parked === 0);
} finally {
  await browser.close();
}

console.log(`\n=== ${failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'} ===`);
process.exit(failures === 0 ? 0 : 1);
