/**
 * E2E — a Bricks Google Maps element is gated like any other embed.
 *
 * Reported on the wordpress.org forum ("Google Maps cookie"): on a Bricks site
 * the map stayed an empty box with no notice above it. Bricks renders the map
 * as an empty <div class="brxe-map" data-bricks-map-options="…"> and draws it
 * from JavaScript once the Maps API calls back `bricksMap`. FAZ blocked the API
 * script, correctly, but had nothing to put in the div, so visitors saw a blank
 * space and no way to accept.
 *
 * This spec builds the same markup Bricks prints — the element, the API script
 * with its `callback=bricksMap` query, and an initializer standing in for
 * Bricks' map.min.js — and runs it through the real server-side blocker and the
 * shipped script. Google is routed to a stub that only records the request and
 * calls the callback, so the run is hermetic and a request before consent is
 * visible as a count. The jsdom suite (bricks-map-consent.test.mjs) covers the
 * retry guards; this is the path a visitor walks.
 */
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const INITIALIZER = '/faz-e2e-bricks-map-initializer.js';

function lastLine(out: string): string {
  return out.trim().split('\n').pop() || '';
}

// Bricks' own initializer, reduced to what matters here: it draws every map
// whose options are readable, and ignores the ones FAZ has parked.
const DRAW = "window.bricksMap=function(){document.querySelectorAll('.brxe-map[data-bricks-map-options]').forEach(function(m){m.setAttribute('data-map-drawn','1');});};";

type Probe = { ctx: BrowserContext; googleRequests: () => number };

async function hermeticContext(browser: Browser): Promise<Probe> {
  const ctx = await browser.newContext();
  let requests = 0;
  await ctx.route(/^https:\/\/maps\.googleapis\.com\/maps\/api\/js/, (route) => {
    requests += 1;
    const callback = new URL(route.request().url()).searchParams.get('callback') || '';
    route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: `window.google={maps:{}};if(typeof window[${JSON.stringify(callback)}]==='function'){window[${JSON.stringify(callback)}]();}`,
    });
  });
  await ctx.route(new RegExp(`${INITIALIZER.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}$`), (route) =>
    route.fulfill({ status: 200, contentType: 'application/javascript', body: DRAW }),
  );
  return { ctx, googleRequests: () => requests };
}

async function openPage(page: Page, url: string): Promise<void> {
  await page.goto(`${url}${url.includes('?') ? '&' : '?'}n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 10_000 });
}

const placeholder = (page: Page) => page.locator('[data-faz-service="google-maps"]').filter({ has: page.locator('[data-faz-accept]') });
const mapElement = (page: Page) => page.locator('.brxe-map');

function pageContent(initializerInline: boolean): string {
  const options = '{&quot;zoom&quot;:12,&quot;addresses&quot;:[{&quot;address&quot;:&quot;Cologne&quot;}]}';
  return (
    '<!-- wp:html -->' +
    `<div id="brxe-e2emap" data-script-id="e2emap" class="brxe-map" style="height:300px" data-bricks-map-options="${options}"></div>` +
    (initializerInline ? `<script>${DRAW}</script>` : '') +
    '<script id="bricks-google-maps-js" src="https://maps.googleapis.com/maps/api/js?callback=bricksMap&amp;loading=async"></script>' +
    '<!-- /wp:html -->'
  );
}

test.describe('Bricks Google Maps: placeholder before consent, map after', () => {
  const pages: Record<string, string> = {};
  const ids: string[] = [];

  test.beforeAll(() => {
    for (const [key, inline] of [['ready', true], ['delayed', false]] as const) {
      const id = lastLine(wpEval(
        `echo wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'FAZ E2E Bricks map ${key}',` +
        `'post_content'=>${JSON.stringify(pageContent(inline)).replace(/\$/g, '\\$')}));`,
      )).replace(/\D/g, '');
      ids.push(id);
      pages[key] = lastLine(wpEval(`echo get_permalink(${id});`));
    }
  });

  test.afterAll(() => {
    for (const id of ids) if (id) wpEval(`wp_delete_post(${id}, true);`);
  });

  test('before consent the element shows a placeholder and Google gets no request', async ({ browser }) => {
    const { ctx, googleRequests } = await hermeticContext(browser);
    const page = await ctx.newPage();
    try {
      await openPage(page, pages.ready);
      await expect(placeholder(page), 'a notice with an Accept button sits where the map goes').toHaveCount(1);
      await expect(mapElement(page)).toHaveAttribute('data-faz-bricks-map-options', /zoom/);
      await expect(mapElement(page), 'Bricks cannot find the options it would draw from').not.toHaveAttribute('data-bricks-map-options', /.*/);
      await page.waitForTimeout(500);
      expect(googleRequests(), 'the Maps API is not requested before consent').toBe(0);
      await expect(mapElement(page)).not.toHaveAttribute('data-map-drawn', '1');
    } finally {
      await ctx.close();
    }
  });

  test('Accept on the placeholder loads the API and Bricks draws the map', async ({ browser }) => {
    const { ctx, googleRequests } = await hermeticContext(browser);
    const page = await ctx.newPage();
    try {
      await openPage(page, pages.ready);
      await placeholder(page).locator('[data-faz-accept]').first().click();
      await expect(mapElement(page), 'the map is drawn on the click').toHaveAttribute('data-map-drawn', '1', { timeout: 10_000 });
      await expect(placeholder(page)).toHaveCount(0);
      expect(googleRequests(), 'the API is requested once consent is given').toBeGreaterThan(0);
    } finally {
      await ctx.close();
    }
  });

  test('an initializer delayed past Google\'s callback (WP Rocket) still draws the map', async ({ browser }) => {
    const { ctx } = await hermeticContext(browser);
    const page = await ctx.newPage();
    try {
      await openPage(page, pages.delayed);
      await placeholder(page).locator('[data-faz-accept]').first().click();
      // Google has called back with no bricksMap on the page yet.
      await page.waitForFunction(() => !!(window as unknown as { google?: unknown }).google, undefined, { timeout: 10_000 });
      await expect(mapElement(page)).not.toHaveAttribute('data-map-drawn', '1');
      // Now the delayed map.min.js arrives, under Bricks' own handle id.
      await page.evaluate((src) => {
        const s = document.createElement('script');
        s.id = 'bricks-map-js';
        s.src = src;
        document.body.appendChild(s);
      }, INITIALIZER);
      await expect(mapElement(page), 'FAZ retries Bricks\' initializer once it exists').toHaveAttribute('data-map-drawn', '1', { timeout: 10_000 });
    } finally {
      await ctx.close();
    }
  });
});
