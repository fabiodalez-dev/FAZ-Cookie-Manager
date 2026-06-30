import { expect, test } from '../fixtures/wp-fixture';
import { clickFirstVisible } from '../utils/ui';
import { deactivatePluginsExcept } from '../utils/wp-env';

/**
 * Advanced resource-src blocking (#163 img-tile + #167 Bricks-lazy iframe).
 *
 * Map widgets (Leaflet / OpenStreetMap, Bricks Map, …) draw themselves by
 * loading map tiles as runtime-injected <img>, which the script/iframe/fetch
 * blocker never intercepted. The HTMLImageElement `src` setter is now gated:
 * a cross-origin image whose URL matches a blocked provider in a denied
 * category is parked (URL → data-faz-src, no request) until consent, then
 * restored by the standard img[data-faz-src] pass.
 *
 * These are deterministic, dependency-free checks (no CDN / Leaflet load);
 * the full real-Leaflet end-to-end repro lives in tests/e2e/img-tile-blocking.mjs.
 */

const OSM_TILE = 'https://tile.openstreetmap.org/17/69083/45877.png';

test.describe('Advanced resource-src blocking (#163 img-tile + #167 Bricks-lazy iframe)', () => {
  test.beforeAll(() => {
    deactivatePluginsExcept(['faz-cookie-manager']);
  });

  // Wait until FAZ's frontend blocker is initialised (banner present) before
  // injecting images, so the src-setter override is in place.
  async function waitForFaz(page: import('@playwright/test').Page) {
    // Park-before-consent assertions are stateful: a prior test that accepted
    // would leave a consent cookie behind and make the pre-consent cases flaky.
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    await page.waitForFunction(() => !!(window as any).fazcookie?._diag?.().ready, undefined, { timeout: 8000 });
  }

  test('1. a blocked-provider tile <img> is parked before consent and fires no request', async ({ page }) => {
    const tileRequests: string[] = [];
    page.on('request', (r) => { if (r.url().includes('tile.openstreetmap')) tileRequests.push(r.url()); });

    await waitForFaz(page);

    const result = await page.evaluate(async (url) => {
      const img = new Image();
      img.src = url;
      document.body.appendChild(img);
      await new Promise((r) => setTimeout(r, 250));
      return {
        parked: img.getAttribute('data-faz-src') === url,
        srcEmpty: !img.getAttribute('src'),
        category: img.getAttribute('data-faz-category'),
      };
    }, OSM_TILE);

    expect(result.parked, 'tile URL should be parked in data-faz-src').toBe(true);
    expect(result.srcEmpty, 'no src should be set (no load)').toBe(true);
    expect(result.category).toBe('functional');
    expect(tileRequests, `no tile request should fire pre-consent, got: ${JSON.stringify(tileRequests)}`).toHaveLength(0);
  });

  test('2. same-origin images are untouched (fast path — no false positives)', async ({ page }) => {
    await waitForFaz(page);

    const result = await page.evaluate(async () => {
      const sameAbs = new Image(); sameAbs.src = location.origin + '/wp-content/uploads/x.png';
      const rel = new Image(); rel.src = '/wp-content/uploads/y.png';
      const data = new Image(); data.src = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
      await new Promise((r) => setTimeout(r, 100));
      return {
        sameAbsUntouched: !sameAbs.getAttribute('data-faz-src') && !!sameAbs.getAttribute('src'),
        relUntouched: !rel.getAttribute('data-faz-src') && !!rel.getAttribute('src'),
        dataUntouched: !data.getAttribute('data-faz-src') && !!data.getAttribute('src'),
      };
    });

    expect(result.sameAbsUntouched, 'same-origin absolute image untouched').toBe(true);
    expect(result.relUntouched, 'relative image untouched').toBe(true);
    expect(result.dataUntouched, 'data: image untouched').toBe(true);
  });

  test('3. a non-provider cross-origin image is not blocked', async ({ page }) => {
    await waitForFaz(page);

    const result = await page.evaluate(async () => {
      const img = new Image();
      img.src = 'https://example.com/some/photo.jpg';
      await new Promise((r) => setTimeout(r, 100));
      return { untouched: !img.getAttribute('data-faz-src') && !!img.getAttribute('src') };
    });

    expect(result.untouched, 'a cross-origin image that matches no blocked provider must load normally').toBe(true);
  });

  test('4. accept-all restores the parked tile and loads it', async ({ page }) => {
    await waitForFaz(page);

    // Park a tile pre-consent.
    const parked = await page.evaluate(async (url) => {
      const img = new Image();
      img.id = 'faz-tile-restore-probe';
      img.src = url;
      document.body.appendChild(img);
      await new Promise((r) => setTimeout(r, 200));
      return img.getAttribute('data-faz-src') === url;
    }, OSM_TILE);
    expect(parked, 'tile parked before consent').toBe(true);

    const accepted = await clickFirstVisible(page, [
      '[data-faz-tag="accept-button"] button',
      '[data-faz-tag="accept-button"]',
      '.faz-btn-accept',
    ]);
    expect(accepted).toBeTruthy();

    const restored = await page.evaluate(async (url) => {
      await new Promise((r) => setTimeout(r, 1200));
      const img = document.getElementById('faz-tile-restore-probe') as HTMLImageElement | null;
      return img ? { loaded: img.getAttribute('src') === url, cleared: !img.getAttribute('data-faz-src') } : { loaded: false, cleared: false };
    }, OSM_TILE);

    expect(restored.loaded, 'src restored to the tile URL on consent').toBe(true);
    expect(restored.cleared, 'data-faz-src cleared after restore').toBe(true);
  });

  // #167 — Bricks' native lazy-load parks the embed URL in data-src and does
  // `iframe.src = data-src` at runtime, which the same src-setter gate now
  // catches on the iframe prototype.
  test('5. a Bricks-lazy iframe (iframe.src set at runtime) is parked + hidden, no request', async ({ page }) => {
    const ytRequests: string[] = [];
    page.on('request', (r) => { if (/youtube|nocookie/.test(r.url())) ytRequests.push(r.url()); });

    await waitForFaz(page);

    const result = await page.evaluate(async () => {
      const f = document.createElement('iframe');
      f.className = 'bricks-lazy-hidden';
      f.setAttribute('data-src', 'https://www.youtube-nocookie.com/embed/NL2UmY9oKow?rel=0');
      document.body.appendChild(f);
      f.src = f.getAttribute('data-src')!; // Bricks lazy-load assignment
      await new Promise((r) => setTimeout(r, 300));
      return {
        parked: f.getAttribute('data-faz-src') === 'https://www.youtube-nocookie.com/embed/NL2UmY9oKow?rel=0',
        srcEmpty: !f.getAttribute('src'),
        hidden: f.classList.contains('faz-hidden'),
        category: f.getAttribute('data-faz-category'),
      };
    });

    expect(result.parked, 'the runtime-assigned iframe src is parked in data-faz-src').toBe(true);
    expect(result.srcEmpty, 'no src is set (no load)').toBe(true);
    expect(result.hidden, 'parked iframe is hidden').toBe(true);
    expect(result.category).toBe('marketing');
    expect(ytRequests, `no youtube request should fire pre-consent, got: ${JSON.stringify(ytRequests)}`).toHaveLength(0);
  });

  test('6. accept-all restores the parked Bricks-lazy iframe', async ({ page }) => {
    await waitForFaz(page);

    const parked = await page.evaluate(async () => {
      const f = document.createElement('iframe');
      f.id = 'faz-iframe-restore-probe';
      f.className = 'bricks-lazy-hidden';
      const url = 'https://www.youtube-nocookie.com/embed/NL2UmY9oKow?rel=0';
      document.body.appendChild(f);
      f.src = url;
      await new Promise((r) => setTimeout(r, 200));
      return f.getAttribute('data-faz-src') === url;
    });
    expect(parked, 'iframe parked before consent').toBe(true);

    const accepted = await clickFirstVisible(page, [
      '[data-faz-tag="accept-button"] button',
      '[data-faz-tag="accept-button"]',
      '.faz-btn-accept',
    ]);
    expect(accepted).toBeTruthy();

    const restored = await page.evaluate(async () => {
      await new Promise((r) => setTimeout(r, 1200));
      const f = document.getElementById('faz-iframe-restore-probe') as HTMLIFrameElement | null;
      return f ? { loaded: /youtube-nocookie/.test(f.getAttribute('src') || ''), cleared: !f.getAttribute('data-faz-src'), shown: !f.classList.contains('faz-hidden') } : { loaded: false, cleared: false, shown: false };
    });

    expect(restored.loaded, 'iframe src restored on consent').toBe(true);
    expect(restored.cleared, 'data-faz-src cleared after restore').toBe(true);
    expect(restored.shown, 'faz-hidden cleared after restore').toBe(true);
  });
});
