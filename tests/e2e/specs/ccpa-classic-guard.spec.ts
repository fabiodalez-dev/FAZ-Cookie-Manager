/**
 * Compliance guard: layouts backed by the Classic template must not be used
 * when the banner exposes the Do-Not-Sell control.
 *
 * A pure-CCPA banner serves the "Do Not Sell or Share My Personal Information"
 * opt-out, whose toggle lives in the optout-popup. The Classic template type
 * does not render that popup, so a Classic + CCPA banner exposes a "Do Not Sell"
 * link that opens nothing — a non-functional opt-out, which fails the CCPA/CPRA
 * requirement that the link lead to a working opt-out. This includes both the
 * explicit Classic type and the legacy Full-width + Pushdown combination.
 */

import { test, expect } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

/** Force the default banner to a saved CCPA layout that uses the Classic
 *  template and return the original settings JSON for restore. */
function seedCcpaClassicTemplate(type: 'classic' | 'banner'): string {
  const safeType = type === 'banner' ? 'banner' : 'classic';
  return wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $original = $row->settings;
    $settings = json_decode( $row->settings, true );
    if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
    $settings['settings']['applicableLaw'] = 'ccpa';
    $settings['settings']['type'] = '${safeType}';
    $settings['settings']['preferenceCenterType'] = 'pushdown';
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => wp_json_encode( $settings ) ), array( 'banner_id' => $row->banner_id ), array( '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo wp_json_encode( array( 'banner_id' => $row->banner_id, 'original' => $original ) );
  `).trim();
}

/** Legacy "Both" banners stored applicableLaw=gdpr and only enabled the
 * nested button config. The editor must still recognise that representation. */
function seedLegacyBothClassicBanner(): string {
  return wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $original = $row->settings;
    $settings = json_decode( $row->settings, true );
    if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
    $settings['settings']['applicableLaw'] = 'gdpr';
    $settings['settings']['type'] = 'classic';
    $settings['settings']['preferenceCenterType'] = 'pushdown';
    unset( $settings['config']['notice']['elements']['donotSell'] );
    $settings['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] = true;
    $settings['config']['optoutPopup']['status'] = false;
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => wp_json_encode( $settings ) ), array( 'banner_id' => $row->banner_id ), array( '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo wp_json_encode( array( 'banner_id' => $row->banner_id, 'original' => $original ) );
  `).trim();
}

function restoreBanner(meta: { banner_id?: number; original?: string }): void {
  if (!meta || typeof meta.original !== 'string' || !meta.banner_id) return;
  const b64 = Buffer.from(meta.original, 'utf8').toString('base64');
  wpEval(`
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => base64_decode( '${b64}' ) ), array( 'banner_id' => ${meta.banner_id} ), array( '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
  `);
}

async function goToBannerPage(page: Page) {
  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
    waitUntil: 'domcontentloaded',
    timeout: 45_000,
  });
  await page.waitForFunction(
    () => {
      const el = document.getElementById('faz-b-type') as HTMLSelectElement | null;
      return !!el && el.value !== '';
    },
    { timeout: 10_000 },
  );
}

test.describe('CCPA + Classic layout guard', () => {
  test('CCPA law disables Classic and migrates a Classic selection to Box', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    const classicOpt = page.locator('#faz-b-type option[value="classic"]');
    const pushdownOpt = page.locator('#faz-b-pref-type option[value="pushdown"]');
    const hint = page.locator('#faz-b-type-ccpa-hint');

    // Use auto-waiting assertions throughout so a read never races the UI
    // update that selectOption() triggers. The <option> disabled state goes
    // through expect.poll(() => classicOpt.isDisabled()): toBeDisabled() and
    // toHaveJSProperty('disabled', …) don't reliably reflect an <option>'s
    // disabled state in Playwright, while isDisabled() does — poll keeps it
    // auto-waiting. The hint/value checks use the native auto-waiting matchers.
    const classicDisabled = (val: boolean, msg: string) =>
      expect.poll(() => classicOpt.isDisabled(), { message: msg }).toBe(val);

    // Baseline: GDPR + Classic is a valid combination.
    await page.selectOption('#faz-b-law', 'gdpr');
    await page.selectOption('#faz-b-type', 'classic');
    await classicDisabled(false, 'Classic allowed under GDPR');
    await expect(hint, 'no CCPA hint under GDPR').toBeHidden();

    // Switch to pure CCPA → Classic must become unavailable and the current
    // Classic selection must migrate to Box so the opt-out popup exists.
    await page.selectOption('#faz-b-law', 'ccpa');
    await classicDisabled(true, 'Classic disabled under CCPA');
    await expect(page.locator('#faz-b-type'), 'Classic migrated to Box').toHaveValue('box');
    await expect(hint, 'CCPA incompatibility hint shown').toBeVisible();

    // Full-width + Pushdown is also backed by the Classic template. Keep the
    // Full-width layout but prevent/migrate Pushdown to Popup.
    await page.selectOption('#faz-b-type', 'banner');
    await expect.poll(() => pushdownOpt.isDisabled(), { message: 'Pushdown disabled for Full-width CCPA' }).toBe(true);
    await expect(page.locator('#faz-b-pref-type'), 'compatible popup retained').toHaveValue('popup');

    // "Both GDPR + US State Laws" keeps the Do-Not-Sell button on (donotSell
    // stays enabled), and Classic has no opt-out popup for it to open — so
    // Classic must be forbidden under gdpr_ccpa too, not just pure CCPA.
    await page.selectOption('#faz-b-law', 'gdpr_ccpa');
    await classicDisabled(true, 'Classic disabled under gdpr_ccpa (Both)');
    await expect(hint, 'incompatibility hint shown under gdpr_ccpa').toBeVisible();

    // Back to pure GDPR → no Do-Not-Sell button, so Classic is allowed again.
    await page.selectOption('#faz-b-law', 'gdpr');
    await classicDisabled(false, 'Classic allowed again under GDPR');
    await expect(hint, 'hint hidden under GDPR').toBeHidden();
  });

  test('a saved Classic + CCPA banner is migrated to Box on initial load', async ({ page, loginAsAdmin }) => {
    // Regression for the guard only firing on law-change / preset-apply: an
    // existing Classic + CCPA banner (saved before the guard) must be migrated
    // to Box when it opens in the editor, via syncClassicLawCompat() in
    // loadBanner(). Without that call the admin could re-save the invalid,
    // opt-out-less combination unknowingly.
    const meta = JSON.parse(seedCcpaClassicTemplate('classic'));
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();

      await loginAsAdmin(page);
      await goToBannerPage(page);

      // The law loaded as CCPA, and the saved Classic type must have been
      // migrated to Box on load (not left as the invalid Classic).
      await expect(page.locator('#faz-b-law'), 'law loaded as CCPA').toHaveValue('ccpa');
      await expect(page.locator('#faz-b-type'), 'Classic migrated to Box on load').toHaveValue('box');
      await expect(page.locator('#faz-b-type-ccpa-hint'), 'CCPA hint shown on load').toBeVisible();
      await expect
        .poll(() => page.locator('#faz-b-type option[value="classic"]').isDisabled(), { message: 'Classic disabled on load' })
        .toBe(true);
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });

  test('a saved Full-width + Pushdown CCPA banner is migrated to Popup on load', async ({ page, loginAsAdmin }) => {
    const meta = JSON.parse(seedCcpaClassicTemplate('banner'));
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();

      await loginAsAdmin(page);
      await goToBannerPage(page);

      await expect(page.locator('#faz-b-law')).toHaveValue('ccpa');
      await expect(page.locator('#faz-b-type'), 'Full-width layout preserved').toHaveValue('banner');
      await expect(page.locator('#faz-b-pref-type'), 'Pushdown migrated to Popup').toHaveValue('popup');
      await expect
        .poll(() => page.locator('#faz-b-pref-type option[value="pushdown"]').isDisabled(), { message: 'Pushdown disabled' })
        .toBe(true);
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });

  test('a legacy nested Do-Not-Sell flag is loaded as Both and migrated from Classic', async ({ page, loginAsAdmin }) => {
    const meta = JSON.parse(seedLegacyBothClassicBanner());
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();

      await loginAsAdmin(page);
      await goToBannerPage(page);

      await expect(page.locator('#faz-b-law'), 'legacy flag resolves to Both').toHaveValue('gdpr_ccpa');
      await expect(page.locator('#faz-b-type'), 'Classic migrated to Box').toHaveValue('box');
      await expect(page.locator('#faz-b-pref-type'), 'popup-capable preference center').toHaveValue('popup');
      await expect
        .poll(() => page.locator('#faz-b-type option[value="classic"]').isDisabled(), { message: 'Classic disabled for Both' })
        .toBe(true);
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });

  test('frontend repairs a legacy Classic + CCPA banner without an admin visit', async ({ page }) => {
    const meta = JSON.parse(seedCcpaClassicTemplate('classic'));
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();

      await page.goto(WP_BASE, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      await page.waitForFunction(
        () => !!(window as unknown as { _fazConfig?: { _bannerConfig?: unknown } })._fazConfig?._bannerConfig,
        { timeout: 10_000 },
      );

      const effective = await page.evaluate(() => {
        const config = (window as unknown as {
          _fazConfig: {
            _bannerConfig: {
              settings: { type: string; preferenceCenterType: string };
            };
          };
        })._fazConfig._bannerConfig;
        return {
          type: config.settings.type,
          preferenceCenterType: config.settings.preferenceCenterType,
        };
      });
      expect(effective).toEqual({ type: 'box', preferenceCenterType: 'popup' });
      await expect(page.locator('[data-faz-tag="optout-popup"]'), 'working opt-out popup rendered').toHaveCount(1);
      const doNotSell = page.locator('[data-faz-tag="donotsell-button"]').first();
      await expect(doNotSell, 'Do-Not-Sell entry point rendered').toBeVisible();
      await doNotSell.click();
      await expect(
        page.locator('[data-faz-tag="optout-popup"]').locator('xpath=ancestor::*[contains(@class,"faz-modal")][1]'),
        'Do-Not-Sell opens its opt-out modal',
      ).toHaveClass(/faz-modal-open/);
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });
});
