/**
 * The opt-out popup must tell the truth about a Global Privacy Control signal.
 *
 * Reported on the wp.org forum ("Opt-out Preferences Message"): the popup said
 * "we detected a Global Privacy Control signal ... you cannot change this
 * setting" while the Do Not Sell checkbox stayed editable and unticked.
 *
 * Two defects:
 *   1. The geo runtime enables the GPC note for every visitor of a GPC
 *      jurisdiction (California), so a browser that sends no signal was told
 *      one had been detected. The page can be cached, so only the browser can
 *      decide: the note must be dropped when navigator.globalPrivacyControl is
 *      not true.
 *   2. With the signal present the plugin already enforces the opt-out
 *      (gpc:1, sale/share categories denied), but the checkbox read the
 *      `consent` key, which the signal writes as "no". It must show the opt-out
 *      in force — ticked — and be locked, before and after a save and reload.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';
import type { Browser, BrowserContext, Page } from '@playwright/test';

const CA = '/?faz_geo=US&faz_region=CA';
// Iowa's ruleset neither honours nor requires GPC.
const IOWA = '/?faz_geo=US&faz_region=IA';
const TIMEOUT = 8000;

let bannerId = 0;
let originalSettings = '';

test.beforeAll(() => {
  // Same setup as ccpa-revisit-popup-direct.spec.ts: the default banner in
  // CCPA mode with the ccpa.json config overlaid, so the Do Not Sell button and
  // the opt-out popup are rendered.
  const meta = JSON.parse(wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    $s = json_decode( $row->settings, true );
    if ( ! isset( $s['settings'] ) || ! is_array( $s['settings'] ) ) { $s['settings'] = array(); }
    $s['settings']['applicableLaw'] = 'ccpa';
    $ccpa = json_decode( file_get_contents( trailingslashit( WP_PLUGIN_DIR ) . 'faz-cookie-manager/admin/modules/banners/includes/configs/ccpa.json' ), true );
    $s['config'] = array_replace_recursive( isset( $s['config'] ) && is_array( $s['config'] ) ? $s['config'] : array(), $ccpa['config'] );
    // ccpa.json ships respectGPC on; the geo runtime can only turn it on, for
    // rulesets that honour GPC. Starting from off makes it a probe of that.
    if ( ! isset( $s['behaviours'] ) || ! is_array( $s['behaviours'] ) ) { $s['behaviours'] = array(); }
    $s['behaviours']['respectGPC'] = array( 'status' => false );
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => wp_json_encode( $s ) ), array( 'banner_id' => $row->banner_id ), array( '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo wp_json_encode( array( 'id' => (int) $row->banner_id, 'orig' => base64_encode( $row->settings ) ) );
  `).trim());
  bannerId = meta.id;
  originalSettings = meta.orig;
});

test.afterAll(() => {
  if (!bannerId) return;
  wpEval(`
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => base64_decode( '${originalSettings}' ) ), array( 'banner_id' => ${bannerId} ), array( '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
  `);
});

async function visitor(browser: Browser, gpc: boolean): Promise<{ ctx: BrowserContext; page: Page }> {
  const ctx = await browser.newContext(gpc ? { extraHTTPHeaders: { 'Sec-GPC': '1' } } : {});
  if (gpc) {
    await ctx.addInitScript(() => {
      Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', { get: () => true, configurable: true });
    });
  }
  return { ctx, page: await ctx.newPage() };
}

async function openOptoutPopup(page: Page) {
  const donotsell = page.locator('[data-faz-tag="donotsell-button"]:visible').first();
  if (await donotsell.count()) await donotsell.click();
  else await page.locator('[data-faz-tag="revisit-consent"]').first().click();
  await expect(page.locator('[data-faz-tag="optout-popup"]').first()).toBeVisible({ timeout: TIMEOUT });
}

async function consentCookie(ctx: BrowserContext): Promise<string> {
  const c = (await ctx.cookies()).find((k) => k.name === 'fazcookie-consent');
  return c ? decodeURIComponent(c.value) : '';
}

test.describe('Opt-out popup and Global Privacy Control', () => {
  test('without a GPC signal the popup does not claim one was detected', async ({ browser, wpBaseURL }) => {
    const { ctx, page } = await visitor(browser, false);
    try {
      await page.goto(wpBaseURL + CA, { waitUntil: 'domcontentloaded' });
      await openOptoutPopup(page);

      await expect(page.locator('[data-faz-tag="optout-gpc-option"]'), 'GPC note absent without the signal').toHaveCount(0);
      const box = page.locator('#fazCCPAOptOut');
      await expect(box).toBeEnabled();
      await expect(box).not.toBeChecked();

      // The box still works for an ordinary visitor.
      await box.check();
      await page.locator('[data-faz-tag="optout-confirm-button"]').first().click();
      await expect.poll(() => consentCookie(ctx), { timeout: TIMEOUT }).toContain('consent:yes');
    } finally {
      await ctx.close();
    }
  });

  test('with a GPC signal the opt-out is shown ticked and locked, and stays so after a save and reload', async ({ browser, wpBaseURL }) => {
    const { ctx, page } = await visitor(browser, true);
    try {
      await page.goto(wpBaseURL + CA, { waitUntil: 'domcontentloaded' });
      // The other side of the Iowa probe: California's ruleset turns it on.
      expect(
        await page.evaluate(() => (window as any)._fazConfig?._bannerConfig?.behaviours?.respectGPC),
        'California resolved to a ruleset that honours GPC',
      ).toBe(true);
      await expect.poll(() => consentCookie(ctx), { timeout: TIMEOUT }).toContain('gpc:1');
      await openOptoutPopup(page);

      await expect(page.locator('[data-faz-tag="optout-gpc-option"]').first(), 'GPC note shown with the signal').toBeVisible();
      const box = page.locator('#fazCCPAOptOut');
      await expect(box, 'the opt-out in force is shown').toBeChecked();
      await expect(box, 'the note says it cannot be changed').toBeDisabled();

      // Clicking the label must not flip it either.
      await page.locator('[data-faz-tag="optout-option-title"]').first().click();
      await expect(box).toBeChecked();

      await page.locator('[data-faz-tag="optout-confirm-button"]').first().click();
      await expect.poll(() => consentCookie(ctx), { timeout: TIMEOUT }).toContain('consent:yes');
      expect(await consentCookie(ctx)).toContain('marketing:no');

      // Closing the popup runs _fazResetOptoutSuccessMessage(), which hands the
      // box back; with the signal it must stay locked when the popup reopens.
      await page.locator('[data-faz-tag="optout-close"]').first().click();
      await expect(page.locator('[data-faz-tag="optout-popup"]').first()).toBeHidden({ timeout: TIMEOUT });
      await openOptoutPopup(page);
      await expect(box).toBeChecked();
      await expect(box).toBeDisabled();

      await page.reload({ waitUntil: 'domcontentloaded' });
      await openOptoutPopup(page);
      await expect(box).toBeChecked();
      await expect(box).toBeDisabled();
      await expect(page.locator('[data-faz-tag="optout-gpc-option"]').first()).toBeVisible();
    } finally {
      await ctx.close();
    }
  });

  test('outside a GPC jurisdiction the locked box still comes with the note that explains it', async ({ browser, wpBaseURL }) => {
    // The client enforces the signal wherever the visitor is, so the lock
    // applies here too; only the geo runtime used to enable the note.
    // gpcOption is not part of the template's cache key, so a template built
    // for California would otherwise be reused here and hide the difference.
    wpEval(`delete_option( 'faz_banner_template' ); faz_clear_banner_template_cache();`);
    const { ctx, page } = await visitor(browser, true);
    try {
      await page.goto(wpBaseURL + IOWA, { waitUntil: 'domcontentloaded' });
      // The page does not expose the ruleset id; what this case needs is a
      // ruleset that does not honour GPC. The banner starts with respectGPC
      // off (beforeAll) and the runtime turns it on only for such a ruleset,
      // as California's does.
      expect(
        await page.evaluate(() => (window as any)._fazConfig?._bannerConfig?.behaviours?.respectGPC),
        'Iowa resolved to a ruleset that does not honour GPC',
      ).toBe(false);
      await expect.poll(() => consentCookie(ctx), { timeout: TIMEOUT }).toContain('gpc:1');
      await openOptoutPopup(page);

      const box = page.locator('#fazCCPAOptOut');
      await expect(box).toBeChecked();
      await expect(box).toBeDisabled();
      await expect(page.locator('[data-faz-tag="optout-gpc-option"]').first(), 'the lock is explained').toBeVisible();
    } finally {
      await ctx.close();
    }
  });
});
