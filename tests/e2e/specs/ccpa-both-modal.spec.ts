/**
 * Frontend: a "Both" (GDPR + US State Laws) banner renders BOTH the GDPR detail
 * preference center and the CCPA opt-out popup inside the SAME .faz-modal. The
 * panel shown must match the trigger — Do-Not-Sell → opt-out popup, Settings →
 * detail — and the other panel must be hidden, with focus on the active one.
 * Before this, opening either control revealed both stacked panels with focus
 * and ARIA on the GDPR panel.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

/** Force the default banner to a "Both" Box banner (GDPR law + Do-Not-Sell on +
 *  opt-out popup), returning the original settings JSON for restore. */
function seedBothBanner(): string {
  return wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $original = $row->settings;
    $s = json_decode( $row->settings, true );
    if ( ! isset( $s['settings'] ) || ! is_array( $s['settings'] ) ) { $s['settings'] = array(); }
    $s['settings']['applicableLaw']        = 'gdpr';
    $s['settings']['type']                 = 'box';
    $s['settings']['preferenceCenterType'] = 'popup';
    if ( ! isset( $s['config']['notice']['elements']['buttons']['elements']['donotSell'] ) || ! is_array( $s['config']['notice']['elements']['buttons']['elements']['donotSell'] ) ) {
      $s['config']['notice']['elements']['buttons']['elements']['donotSell'] = array();
    }
    $s['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] = true;
    if ( ! isset( $s['config']['optoutPopup'] ) || ! is_array( $s['config']['optoutPopup'] ) ) { $s['config']['optoutPopup'] = array(); }
    $s['config']['optoutPopup']['status'] = true;
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => wp_json_encode( $s ) ), array( 'banner_id' => $row->banner_id ), array( '%s' ), array( '%d' ) );
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

test.describe('"Both" banner — modal panel matches the trigger', () => {
  test('Do-Not-Sell opens ONLY the opt-out popup, with focus inside it', async ({ page }) => {
    const meta = JSON.parse(seedBothBanner());
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();
      await page.context().clearCookies();
      await page.goto(WP_BASE, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      await page.waitForFunction(
        () => !!(window as unknown as { _fazConfig?: { _bannerConfig?: unknown } })._fazConfig?._bannerConfig,
        { timeout: 10_000 },
      );

      const detail = page.locator('[data-faz-tag="detail"]');
      const optout = page.locator('[data-faz-tag="optout-popup"]');

      await page.click('[data-faz-tag="donotsell-button"]');

      // The opt-out popup is shown; the GDPR detail panel is explicitly hidden.
      await expect(optout, 'opt-out popup visible').not.toHaveClass(/faz-hide/);
      await expect(detail, 'GDPR detail panel hidden').toHaveClass(/faz-hide/);
      await expect(
        optout.locator('xpath=ancestor::*[contains(@class,"faz-modal")][1]'),
        'opt-out popup modal is open',
      ).toHaveClass(/faz-modal-open/);

      // Focus (and therefore ARIA) lands inside the opt-out popup, not the detail panel.
      const focusInOptout = await page.evaluate(() => {
        const el = document.activeElement;
        return !!(el && el.closest('[data-faz-tag="optout-popup"]'));
      });
      expect(focusInOptout, 'focus is inside the opt-out popup').toBe(true);
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });

  test('Settings opens ONLY the GDPR detail panel', async ({ page }) => {
    const meta = JSON.parse(seedBothBanner());
    let cleanupErr: unknown;
    try {
      expect(meta.error).toBeUndefined();
      await page.context().clearCookies();
      await page.goto(WP_BASE, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      await page.waitForFunction(
        () => !!(window as unknown as { _fazConfig?: { _bannerConfig?: unknown } })._fazConfig?._bannerConfig,
        { timeout: 10_000 },
      );

      const detail = page.locator('[data-faz-tag="detail"]');
      const optout = page.locator('[data-faz-tag="optout-popup"]');

      await page.click('[data-faz-tag="settings-button"]');

      await expect(detail, 'GDPR detail panel visible').not.toHaveClass(/faz-hide/);
      await expect(optout, 'opt-out popup hidden').toHaveClass(/faz-hide/);
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });
});
