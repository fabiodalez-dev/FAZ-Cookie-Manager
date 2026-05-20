/**
 * CCPA revisit → opt-out popup direct (1-click UX).
 *
 * Background: pre-1.14.4 the floating revisit widget always opened the
 * full banner, even in CCPA mode where the only meaningful preference
 * is the binary "Do Not Sell My Personal Information" toggle. This
 * forced 2 clicks to change the opt-out status (revisit → banner →
 * Do Not Sell button → popup). Modern CCPA UIs (Termly, Iubenda,
 * Cookiebot, CookieYes 2024+) open the opt-out preferences popup
 * directly on revisit.
 *
 * The fix in _revisitFazConsent guards on:
 *   - _fazGetLaw() === 'ccpa'
 *   - _fazGetFromStore('action') is truthy (i.e. the visitor has
 *     already made a choice — first-time visitors MUST still see the
 *     full banner for compliance)
 *
 * Negative coverage: GDPR mode revisit MUST still show the banner.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const REVISIT_TIMEOUT = 8000;

test.describe('CCPA revisit → opt-out popup (1-click UX, 1.14.4+)', () => {
  test.beforeEach(async ({ page }) => {
    // Fresh state per test — no consent cookie, no action recorded.
    await page.context().clearCookies();
  });

  test('CCPA mode + action recorded → revisit opens the opt-out popup directly (no banner)', async ({ page, wpBaseURL }) => {
    // Force the default banner to ccpa law so an anonymous visitor
    // gets the CCPA flow regardless of detected country. Cleaned up
    // in afterAll-equivalent at end of test.
    const originalLaw = wpEval(`
      global $wpdb;
      $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
      if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
      $settings = json_decode( $row->settings, true );
      $previous = isset( $settings['applicableLaw'] ) ? $settings['applicableLaw'] : '';
      $settings['applicableLaw'] = 'ccpa';
      $wpdb->update(
        $wpdb->prefix . 'faz_banners',
        array( 'settings' => wp_json_encode( $settings ) ),
        array( 'banner_id' => $row->banner_id ),
        array( '%s' ),
        array( '%d' )
      );
      delete_option( 'faz_banner_template' );
      echo wp_json_encode( array( 'banner_id' => $row->banner_id, 'previous' => $previous ) );
    `).trim();

    try {
      const meta = JSON.parse(originalLaw);
      expect(meta.error, 'install has a default banner').toBeUndefined();

      // 1. First visit — banner must be present (no action recorded yet)
      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      const banner = page.locator('.faz-consent-container').first();
      await expect(banner, 'first-visit banner shows for visitors without action').toBeVisible({ timeout: REVISIT_TIMEOUT });

      // 2. Click the Do Not Sell button on the banner to open the popup
      await page.locator('[data-faz-tag="donotsell-button"]').first().click();
      const popup = page.locator('[data-faz-tag="optout-popup"]').first();
      await expect(popup, 'opt-out popup opens after Do Not Sell button click').toBeVisible({ timeout: REVISIT_TIMEOUT });

      // 3. Check the Do Not Sell checkbox + Confirm — records `action`
      const checkbox = page.locator('#fazCCPAOptOut');
      await checkbox.check();
      await page.locator('[data-faz-tag="optout-confirm-button"]').first().click();

      // 4. Banner + popup should now be hidden. Revisit widget should be visible.
      await expect(banner, 'banner closes after confirm').toBeHidden({ timeout: REVISIT_TIMEOUT });
      const revisitWidget = page.locator('[data-faz-tag="revisit-consent"]').first();
      await expect(revisitWidget, 'revisit floating widget appears after action').toBeVisible({ timeout: REVISIT_TIMEOUT });

      // 5. THE CORE ASSERTION — click revisit, the OPT-OUT POPUP opens
      //    directly (not the banner).
      await revisitWidget.locator('.faz-btn-revisit').click();
      await expect(popup, 'revisit click opens opt-out popup DIRECTLY (no banner intermediate)').toBeVisible({ timeout: REVISIT_TIMEOUT });

      // 6. Banner stays hidden — the user did not see it again.
      await expect(banner, 'banner is NOT re-shown on revisit in CCPA mode').toBeHidden();

      // 7. The checkbox reflects the previously stored choice (checked = opted out)
      await expect(checkbox, 'checkbox preset to current state on re-open').toBeChecked();

      // 8. Uncheck + Confirm — opt back in
      await checkbox.uncheck();
      await page.locator('[data-faz-tag="optout-confirm-button"]').first().click();
      await expect(popup, 'popup closes after opt-in-back confirm').toBeHidden({ timeout: REVISIT_TIMEOUT });
    } finally {
      // Restore original banner law
      try {
        const meta = JSON.parse(originalLaw);
        if (!meta.error && meta.previous !== undefined) {
          wpEval(`
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT settings FROM {$wpdb->prefix}faz_banners WHERE banner_id = %d", ${parseInt(meta.banner_id, 10)} ) );
            if ( $row ) {
              $settings = json_decode( $row->settings, true );
              $settings['applicableLaw'] = ${JSON.stringify(meta.previous)};
              $wpdb->update(
                $wpdb->prefix . 'faz_banners',
                array( 'settings' => wp_json_encode( $settings ) ),
                array( 'banner_id' => ${parseInt(meta.banner_id, 10)} ),
                array( '%s' ),
                array( '%d' )
              );
              delete_option( 'faz_banner_template' );
            }
          `);
        }
      } catch (e) {
        // Cleanup failures shouldn't fail the test.
      }
    }
  });

  test('GDPR mode regression: revisit still shows the banner (negative coverage)', async ({ page, wpBaseURL }) => {
    // GDPR is the default in the dev fixture — no setup needed beyond
    // making sure the banner is GDPR-law.
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container').first();
    await expect(banner, 'GDPR banner shows on first visit').toBeVisible({ timeout: REVISIT_TIMEOUT });

    // Record `action` by clicking Accept All
    await page.locator('[data-faz-tag="accept-button"]').first().click();
    await expect(banner, 'banner closes after Accept').toBeHidden({ timeout: REVISIT_TIMEOUT });

    // Click revisit — banner MUST re-open (GDPR has a full preference center
    // accessible from the banner; we don't skip it).
    const revisitWidget = page.locator('[data-faz-tag="revisit-consent"]').first();
    await expect(revisitWidget).toBeVisible({ timeout: REVISIT_TIMEOUT });
    await revisitWidget.locator('.faz-btn-revisit').click();
    await expect(banner, 'GDPR revisit re-opens the banner (1-click shortcut is CCPA-only)').toBeVisible({ timeout: REVISIT_TIMEOUT });

    // Opt-out popup MUST NOT be present in GDPR mode (DOM may not even render it)
    const popup = page.locator('[data-faz-tag="optout-popup"]').first();
    await expect(popup, 'opt-out popup is not the GDPR revisit target').toBeHidden();
  });
});
