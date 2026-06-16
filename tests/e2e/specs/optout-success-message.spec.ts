/**
 * Opt-out success message (US state laws / CCPA).
 *
 * When a visitor confirms a "Do Not Sell / Share" opt-out, the popup no longer
 * just disappears: it shows a confirmation message ("Your opt-out preference
 * has been honored.") with an accessible live region, a countdown subtext, and
 * auto-closes after the countdown. This mirrors the confirmation UX shipped by
 * modern US-state-law CMPs and makes the opt-out outcome explicit.
 *
 * Coverage:
 *   - After opt-out + confirm: success message visible, action buttons hidden,
 *     headline text resolved from the banner content, countdown running.
 *   - Closing the popup while the success message shows dismisses immediately
 *     (consent already saved) and the banner stays gone.
 *   - Confirming WITHOUT opting out (checkbox unchecked) keeps the legacy
 *     behaviour: normal save + immediate close, no success message.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

const T = 8000;

/** Switch the default banner to CCPA and overlay the ccpa.json config defaults. */
function setDefaultBannerToCcpa(): string {
  return wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $original_settings_json = $row->settings;
    $settings = json_decode( $row->settings, true );
    if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
    $settings['settings']['applicableLaw'] = 'ccpa';
    $ccpa_path = trailingslashit( WP_PLUGIN_DIR ) . 'faz-cookie-manager/admin/modules/banners/includes/configs/ccpa.json';
    if ( file_exists( $ccpa_path ) ) {
      $ccpa_defaults = json_decode( file_get_contents( $ccpa_path ), true );
      if ( is_array( $ccpa_defaults ) && isset( $ccpa_defaults['config'] ) ) {
        $existing_config = isset( $settings['config'] ) && is_array( $settings['config'] ) ? $settings['config'] : array();
        $settings['config'] = array_replace_recursive( $existing_config, $ccpa_defaults['config'] );
      }
    }
    $wpdb->update(
      $wpdb->prefix . 'faz_banners',
      array( 'settings' => wp_json_encode( $settings ) ),
      array( 'banner_id' => $row->banner_id ),
      array( '%s' ),
      array( '%d' )
    );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo wp_json_encode( array( 'banner_id' => $row->banner_id, 'original_settings' => $original_settings_json ) );
  `).trim();
}

/** Restore the banner settings blob captured by setDefaultBannerToCcpa(). */
function restoreBanner(meta: { banner_id?: number; original_settings?: string }): void {
  if (!meta || typeof meta.original_settings !== 'string' || !meta.banner_id) return;
  const b64 = Buffer.from(meta.original_settings, 'utf8').toString('base64');
  wpEval(`
    global $wpdb;
    $settings = base64_decode( '${b64}' );
    $wpdb->update(
      $wpdb->prefix . 'faz_banners',
      array( 'settings' => $settings ),
      array( 'banner_id' => ${meta.banner_id} ),
      array( '%s' ),
      array( '%d' )
    );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
  `);
}

test.beforeAll(() => {
  resetDefaultBannerState();
});

test.describe('Opt-out success message (CCPA)', () => {
  test.beforeEach(async ({ page }) => {
    await page.context().clearCookies();
  });

  test('opt-out + confirm shows the success message, runs the countdown, then close dismisses', async ({ page, wpBaseURL }) => {
    const meta = JSON.parse(setDefaultBannerToCcpa());
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();

      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      const banner = page.locator('.faz-consent-container').first();
      await expect(banner, 'first-visit CCPA banner shows').toBeVisible({ timeout: T });

      // Open the opt-out popup, opt out, confirm.
      await page.locator('[data-faz-tag="donotsell-button"]').first().click();
      const popup = page.locator('[data-faz-tag="optout-popup"]').first();
      await expect(popup, 'opt-out popup opens').toBeVisible({ timeout: T });
      await page.locator('#fazCCPAOptOut').check();
      await page.locator('[data-faz-tag="optout-confirm-button"]').first().click();

      // Success message replaces the action buttons.
      const success = page.locator('[data-faz-tag="optout-success"]').first();
      await expect(success, 'success message becomes visible').toBeVisible({ timeout: T });
      await expect(success, 'headline resolved from banner content')
        .toContainText('opt-out preference has been honored', { ignoreCase: true });
      await expect(page.locator('[data-faz-tag="optout-buttons"]').first(), 'action buttons hidden while success shows').toBeHidden();

      // Countdown is running: the visible seconds value decreases over time.
      const subtext = page.locator('[data-faz-tag="optout-success-subtext"]').first();
      const first = (await subtext.innerText()).match(/\d+/)?.[0];
      expect(first, 'countdown shows a starting number').toBeTruthy();
      await page.waitForTimeout(2200);
      const second = (await subtext.innerText()).match(/\d+/)?.[0];
      expect(Number(second), 'countdown decremented').toBeLessThan(Number(first));

      // The opt-out was persisted (action recorded) even though the banner is
      // still on screen during the countdown.
      const action = await page.evaluate(() =>
        (document.cookie.match(/fazcookie-consent=([^;]+)/)?.[1] ?? '').includes('action%3Ayes')
        || decodeURIComponent(document.cookie).includes('action:yes'));
      expect(action, 'consent cookie records the opt-out (action:yes)').toBeTruthy();

      // Closing while the success shows dismisses the countdown immediately.
      await page.locator('[data-faz-tag="optout-close"]').first().click();
      await expect(banner, 'banner gone after dismissing the success message').toBeHidden({ timeout: T });
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });

  test('confirm WITHOUT opting out keeps the legacy immediate-close behaviour', async ({ page, wpBaseURL }) => {
    const meta = JSON.parse(setDefaultBannerToCcpa());
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();

      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      const banner = page.locator('.faz-consent-container').first();
      await expect(banner).toBeVisible({ timeout: T });

      await page.locator('[data-faz-tag="donotsell-button"]').first().click();
      const popup = page.locator('[data-faz-tag="optout-popup"]').first();
      await expect(popup).toBeVisible({ timeout: T });

      // Leave the opt-out checkbox UNCHECKED, then confirm.
      await page.locator('[data-faz-tag="optout-confirm-button"]').first().click();

      // No success message; banner closes right away.
      await expect(page.locator('[data-faz-tag="optout-success"]').first(), 'no success message when not opted out').toBeHidden();
      await expect(banner, 'banner closes immediately on a non-opt-out confirm').toBeHidden({ timeout: T });
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });
});
