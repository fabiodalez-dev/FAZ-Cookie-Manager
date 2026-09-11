/**
 * E2E — a visitor sending Global Privacy Control can open a blocked map by
 * clicking Accept on it, and nothing else is loosened.
 *
 * Reported as "strange behaviour in Firefox" on a GDPR site: the Maps
 * placeholder's Accept button did nothing. Brave, Zen and Waterfox send GPC,
 * GPC opts the visitor out of every category flagged sale/share, and on that
 * site Functional was still flagged (the pre-1.17.2 default). The click wrote
 * svc.google-maps:yes and the same save removed it again as a sale/share
 * bypass.
 *
 * This spec reproduces the reporter's configuration on the real stack — the
 * placeholder rendered by PHP, the click handled by the shipped script, the
 * reload served by the server-side blocker with a Sec-GPC header — because the
 * bug lived exactly where those three meet. The jsdom suites
 * (gpc-embed-exception, consent-liveness-matrix) cover the rule itself.
 */
import type { BrowserContext, Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const MAPS_SRC = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2800!2d11.9!3d45.4';

function lastLine(out: string): string {
  return out.trim().split('\n').pop() || '';
}

async function gpcContext(browser: import('@playwright/test').Browser): Promise<BrowserContext> {
  // Sec-GPC reaches the server-side blocker; navigator.globalPrivacyControl
  // reaches script.js. Real GPC browsers send both.
  const ctx = await browser.newContext({ extraHTTPHeaders: { 'Sec-GPC': '1' } });
  await ctx.addInitScript(() => {
    Object.defineProperty(navigator, 'globalPrivacyControl', { get: () => true, configurable: true });
  });
  // Keep the run hermetic: the iframe gets its real URL, Google gets no request.
  await ctx.route(/^https:\/\/www\.google\.com\/maps\//, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>map</body></html>' }));
  return ctx;
}

async function openPage(page: Page, url: string): Promise<void> {
  await page.goto(`${url}${url.includes('?') ? '&' : '?'}n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), undefined, { timeout: 10_000 });
}

const placeholder = (page: Page) => page.locator('.faz-placeholder[data-faz-service="google-maps"]');
const liveMap = (page: Page) => page.locator('iframe[src^="https://www.google.com/maps/embed"]');

test.describe('GPC: Accept on a blocked embed grants that service only', () => {
  let snapshot = '';
  let url = '';
  let postId = '';

  test.beforeAll(() => {
    // Snapshot what this spec changes, then apply the reporter's configuration:
    // Functional flagged sale/share, per-service consent on.
    snapshot = lastLine(wpEval(
      `global $wpdb;$t=$wpdb->prefix.'faz_cookie_categories';` +
      `$r=$wpdb->get_row("SELECT sell_personal_data s,share_personal_data h FROM $t WHERE slug='functional'");` +
      `$o=get_option('faz_settings',array());` +
      `echo wp_json_encode(array('s'=>(int)$r->s,'h'=>(int)$r->h,'ps'=>!empty($o['banner_control']['per_service_consent'])));`,
    ));
    wpEval(
      `global $wpdb;$t=$wpdb->prefix.'faz_cookie_categories';` +
      `$wpdb->update($t,array('sell_personal_data'=>1,'share_personal_data'=>1),array('slug'=>'functional'));` +
      `\\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance()->delete_cache();` +
      `$o=get_option('faz_settings',array());$o['banner_control']['per_service_consent']=true;update_option('faz_settings',$o);` +
      `delete_option('faz_banner_template');`,
    );
    postId = lastLine(wpEval(
      `echo wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'FAZ E2E GPC map',` +
      `'post_content'=>'<iframe src="${MAPS_SRC}" width="600" height="450" title="Map"></iframe>'));`,
    )).replace(/\D/g, '');
    url = lastLine(wpEval(`echo get_permalink(${postId});`));
  });

  test.afterAll(() => {
    if (postId) wpEval(`wp_delete_post(${postId}, true);`);
    if (!snapshot) return;
    const s = JSON.parse(snapshot) as { s: number; h: number; ps: boolean };
    wpEval(
      `global $wpdb;$t=$wpdb->prefix.'faz_cookie_categories';` +
      `$wpdb->update($t,array('sell_personal_data'=>${s.s},'share_personal_data'=>${s.h}),array('slug'=>'functional'));` +
      `\\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance()->delete_cache();` +
      `$o=get_option('faz_settings',array());$o['banner_control']['per_service_consent']=${s.ps ? 'true' : 'false'};update_option('faz_settings',$o);` +
      `delete_option('faz_banner_template');`,
    );
  });

  test('the map opens on the click and is still open after a reload', async ({ browser, getConsentCookie, parseConsentCookie }) => {
    const ctx = await gpcContext(browser);
    const page = await ctx.newPage();
    try {
      await openPage(page, url);
      await expect(placeholder(page), 'a GPC visitor first sees the map blocked').toHaveCount(1);
      await expect(liveMap(page)).toHaveCount(0);

      await placeholder(page).locator('[data-faz-accept]').first().click();

      // Same page: this is where the bug showed — the click did nothing.
      await expect(liveMap(page), 'the map loads on the click').toHaveCount(1, { timeout: 10_000 });
      await expect(placeholder(page)).toHaveCount(0);

      const parsed = parseConsentCookie((await getConsentCookie(ctx))!.value);
      expect(parsed['svc.google-maps'], 'the service is granted').toBe('yes');
      expect(parsed['gpcx.google-maps'], 'and marked as a GPC exception').toBe('1');
      expect(parsed.functional, 'the CATEGORY stays denied').toBe('no');
      expect(parsed.gpc, 'GPC is still recorded').toBe('1');

      // Reload: script.js re-applies GPC at init and the server-side blocker
      // sees Sec-GPC. Before the fix the grant was wiped here.
      await openPage(page, url);
      await expect(liveMap(page), 'the map is still open after a reload').toHaveCount(1, { timeout: 10_000 });
      await expect(placeholder(page)).toHaveCount(0);
      const after = parseConsentCookie((await getConsentCookie(ctx))!.value);
      expect(after['svc.google-maps']).toBe('yes');
      expect(after['gpcx.google-maps']).toBe('1');
    } finally {
      await ctx.close();
    }
  });

  test('Accept All on the banner does not open the map under GPC', async ({ browser, getConsentCookie, parseConsentCookie }) => {
    // The other half of the rule: GPC still binds the category, so a broad
    // Accept All cannot re-grant it. Only an Accept on the embed itself can.
    const ctx = await gpcContext(browser);
    const page = await ctx.newPage();
    try {
      await openPage(page, url);
      const accept = page.locator('[data-faz-tag="accept-button"]').first();
      await expect(accept).toBeVisible({ timeout: 10_000 });
      await accept.click();
      await page.waitForTimeout(800);

      await expect(placeholder(page), 'the map stays blocked after Accept All').toHaveCount(1);
      await expect(liveMap(page)).toHaveCount(0);
      const parsed = parseConsentCookie((await getConsentCookie(ctx))!.value);
      expect(parsed.functional).toBe('no');
      expect(parsed['svc.google-maps'] === 'yes').toBeFalsy();

      await openPage(page, url);
      await expect(placeholder(page), 'and after a reload').toHaveCount(1);
    } finally {
      await ctx.close();
    }
  });
});
