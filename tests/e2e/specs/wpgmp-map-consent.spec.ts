/**
 * E2E — a WP Maps (wp-google-map-plugin) Google map is gated like any embed.
 *
 * Asked on the wordpress.org forum ("WP Maps support"): before consent the map
 * was an empty box with no notice, and the only way to get one was WP Maps'
 * own server-side "Cookies Acceptance" gate plus a code snippet, because WP
 * Maps does not know this plugin. WP Maps prints an empty
 * <div class="wpgmp_map" id="mapN"> and draws it from JavaScript when the Maps
 * API calls back `wpgmpInitMap`. FAZ blocked the API, correctly, but put
 * nothing where the map goes.
 *
 * This spec builds the markup WP Maps 5.0.3 prints — a Google map and an
 * OpenStreetMap one in their containers, the API script with its
 * `callback=wpgmpInitMap` query, and an initializer standing in for WP Maps'
 * maps.js — and runs it through the real server-side blocker and the shipped
 * script. Google is routed to a stub that only counts the request and calls
 * back, so the run is hermetic. The initializer records whether the map was
 * VISIBLE when it was drawn: a Google map initialised in a hidden box renders
 * at zero size, so revealing it after the callback would not be enough.
 */
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

function lastLine(out: string): string {
  return out.trim().split('\n').pop() || '';
}

type Probe = { ctx: BrowserContext; googleRequests: () => number };

async function hermeticContext(browser: Browser): Promise<Probe> {
  const ctx = await browser.newContext();
  let requests = 0;
  await ctx.route(/^https:\/\/maps\.google\.com\/maps\/api\/js/, (route) => {
    requests += 1;
    const callback = new URL(route.request().url()).searchParams.get('callback') || '';
    route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: `window.google={maps:{}};if(typeof window[${JSON.stringify(callback)}]==='function'){window[${JSON.stringify(callback)}]();}`,
    });
  });
  return { ctx, googleRequests: () => requests };
}

async function openPage(page: Page, url: string): Promise<void> {
  await page.goto(`${url}${url.includes('?') ? '&' : '?'}n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 10_000 });
}

const placeholder = (page: Page) => page.locator('[data-faz-service="google-maps"]').filter({ has: page.locator('[data-faz-accept]') });
const googleMap = (page: Page) => page.locator('#map1');
const leafletMap = (page: Page) => page.locator('#map2');

function container(id: number, provider: string): string {
  return `<div class="wpgmp_map_container wpgmp-map-provider-${provider} wpgmp-map-${id}" rel="map${id}" data-plugin-version="5.0.3">`
    + `<div class="wpgmp_map_parent"><div class="wpgmp_map " style="width:100%;height:300px" id="map${id}" data-map-id="${id}"></div></div></div>`;
}

// WP Maps' wpgmpInitMap(), reduced to what matters here: it draws every
// .wpgmp_map on the page, and records whether the box had a size then.
const INIT = "function wpgmpInitMap(){document.querySelectorAll('.wpgmp_map').forEach(function(m){m.setAttribute('data-map-drawn',m.offsetHeight>0?'visible':'hidden');});}";

function pageContent(): string {
  return (
    '<!-- wp:html -->' +
    container(1, 'google') +
    container(2, 'leaflet') +
    `<script>${INIT}</script>` +
    '<script id="wpgmp-google-api-js" src="https://maps.google.com/maps/api/js?key=E2E&amp;loading=async&amp;callback=wpgmpInitMap&amp;ver=5.0.3"></script>' +
    '<!-- /wp:html -->'
  );
}

test.describe('WP Maps Google map: placeholder before consent, map after', () => {
  let url = '';
  let id = '';

  test.beforeAll(() => {
    id = lastLine(wpEval(
      "echo wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'FAZ E2E WP Maps map'," +
      `'post_content'=>${JSON.stringify(pageContent()).replace(/\$/g, '\\$')}));`,
    )).replace(/\D/g, '');
    url = lastLine(wpEval(`echo get_permalink(${id});`));
  });

  test.afterAll(() => {
    if (id) wpEval(`wp_delete_post(${id}, true);`);
  });

  test('before consent the Google map shows a placeholder and Google gets no request', async ({ browser }) => {
    const { ctx, googleRequests } = await hermeticContext(browser);
    const page = await ctx.newPage();
    try {
      await openPage(page, url);
      await expect(placeholder(page), 'a notice with an Accept button sits where the Google map goes').toHaveCount(1);
      await expect(googleMap(page), 'the map box waits behind the placeholder').toBeHidden();
      await expect(leafletMap(page), 'the OpenStreetMap map is not a Google map and is left alone').not.toHaveAttribute('data-faz-category', /.*/);
      await page.waitForTimeout(500);
      expect(googleRequests(), 'the Maps API is not requested before consent').toBe(0);
      await expect(googleMap(page)).not.toHaveAttribute('data-map-drawn', /.*/);
    } finally {
      await ctx.close();
    }
  });

  test('Accept on the placeholder loads the API and WP Maps draws a visible map', async ({ browser }) => {
    const { ctx, googleRequests } = await hermeticContext(browser);
    const page = await ctx.newPage();
    try {
      await openPage(page, url);
      await placeholder(page).locator('[data-faz-accept]').first().click();
      await expect(googleMap(page), 'the map is drawn after the click, in a box that has a size').toHaveAttribute('data-map-drawn', 'visible', { timeout: 10_000 });
      await expect(placeholder(page)).toHaveCount(0);
      expect(googleRequests(), 'the API is requested once consent is given').toBeGreaterThan(0);
    } finally {
      await ctx.close();
    }
  });

  test('Reject keeps the placeholder and Google gets no request', async ({ browser }) => {
    const { ctx, googleRequests } = await hermeticContext(browser);
    const page = await ctx.newPage();
    try {
      await openPage(page, url);
      await page.locator('[data-faz-tag="reject-button"]').first().click();
      await page.waitForTimeout(1000);
      await expect(placeholder(page)).toHaveCount(1);
      await expect(googleMap(page)).toBeHidden();
      expect(googleRequests(), 'nothing reaches Google after a refusal').toBe(0);
    } finally {
      await ctx.close();
    }
  });
});
