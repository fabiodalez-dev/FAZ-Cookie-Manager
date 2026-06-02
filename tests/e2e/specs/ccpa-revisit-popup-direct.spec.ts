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
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

const REVISIT_TIMEOUT = 8000;

test.beforeAll(() => {
  // Self-provision the default box+popup GDPR banner so this spec is immune
  // to a prior full-suite spec leaving the shared banner in classic/pushdown
  // or CCPA mode (see utils/seed-defaults.ts).
  resetDefaultBannerState();
});

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
      $original_settings_json = $row->settings;
      $settings = json_decode( $row->settings, true );
      // applicableLaw lives at $properties['settings']['applicableLaw'], NOT at the
      // top-level. Banner::get_settings() reads the nested path; writing to the
      // top-level is silently dropped by sanitize_settings() and the live banner
      // renders the unchanged (gdpr) default. This nesting fix landed alongside
      // the cache-invalidation fix in PR fix/e2e-test-stale-assertions.
      if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
      $previous = isset( $settings['settings']['applicableLaw'] ) ? $settings['settings']['applicableLaw'] : '';
      $settings['settings']['applicableLaw'] = 'ccpa';

      // Switching the law alone does NOT promote per-element config defaults:
      // sanitize_settings() prefers the existing DB value over the ccpa.json
      // default. A banner originally created with gdpr defaults carries
      // config.* values that disable every CCPA-only element (donotSell,
      // optoutPopup + its dozen nested optout-* elements). Patching them one
      // by one was attempted and rotted on every new optout-* element added.
      //
      // The realistic admin-UI flow is: switch law, then load that law
      // defaults into config. Mimic it here: overlay the ccpa.json config
      // block on top of the banner config so all the CCPA-only elements get
      // their status=true defaults. The merge keeps any banner-specific
      // styling tweaks the admin made on top.
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
      // Controller::get_items() caches the decoded banner rows in
      // wp_cache_get(..., 'faz_banners') with a 5-minute TTL. A raw $wpdb->update
      // bypasses Banner::update() which would normally call delete_cache(). On a
      // PHP-FPM stack workers can hold the stale cache across requests; bump the
      // epoch so the next HTTP request loads the freshly-saved row.
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      faz_clear_banner_template_cache();
      // Return the ORIGINAL settings JSON in full so the cleanup hook can
      // restore the entire blob (not just applicableLaw). The CCPA setup
      // overlays the ccpa.json config block to enable the donotSell button
      // and the optoutPopup tree; restoring only applicableLaw would leak
      // those status=true flags into the GDPR test that runs next.
      echo wp_json_encode( array(
        'banner_id'         => $row->banner_id,
        'previous'          => $previous,
        'original_settings' => $original_settings_json,
      ) );
    `).trim();

    // CodeRabbit follow-up (Biome noUnsafeFinally): capture any cleanup
    // error in a local variable instead of throwing inside finally.
    // Throwing in finally masks the original test failure when both the
    // body and the cleanup fail. We rethrow AFTER finally so the body
    // error always propagates first; cleanup errors surface only when
    // the body succeeded.
    let cleanupErr: unknown = undefined;

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
        if (!meta.error && typeof meta.original_settings === 'string') {
          // Restore the full settings JSON to the pre-mutation snapshot. A
          // partial restore (only applicableLaw) would leak the CCPA-overlaid
          // config tree (donotSell.status=true, optoutPopup.status=true, ...)
          // into the next test, breaking the GDPR negative-coverage test.
          // base64 round-trip avoids PHP $variable interpolation in double-quoted
          // templates — see wp-env.ts::restoreActivePluginFiles for the same idiom.
          const encoded = Buffer.from(meta.original_settings, 'utf8').toString('base64');
          wpEval(`
            global $wpdb;
            $settings_json = base64_decode( '${encoded}' );
            $wpdb->update(
              $wpdb->prefix . 'faz_banners',
              array( 'settings' => $settings_json ),
              array( 'banner_id' => ${parseInt(meta.banner_id, 10)} ),
              array( '%s' ),
              array( '%d' )
            );
            \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
            faz_clear_banner_template_cache();
          `);
        }
      } catch (e) {
        // CodeRabbit#1: do NOT silence cleanup failures. Capture here,
        // rethrow AFTER finally (noUnsafeFinally compliance — avoids
        // masking the body's original error).
        cleanupErr = e;
      }
    }
    if (cleanupErr !== undefined) {
      throw new Error(`CCPA cleanup rollback failed: ${String(cleanupErr)}`);
    }
  });

  test('GDPR mode regression: revisit still shows the banner (negative coverage)', async ({ page, wpBaseURL }) => {
    // CodeRabbit#2: do not depend on the dev-fixture default. Force the
    // default banner to GDPR law explicitly at the start, restore the
    // prior value in finally. This makes the test deterministic
    // regardless of test ordering, fixture state, or parallel runs.
    const originalLaw = wpEval(`
      global $wpdb;
      $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
      if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
      $original_settings_json = $row->settings;
      $settings = json_decode( $row->settings, true );
      // Nested path + cache bump: see comment on the CCPA setup above.
      if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
      $previous = isset( $settings['settings']['applicableLaw'] ) ? $settings['settings']['applicableLaw'] : '';
      $settings['settings']['applicableLaw'] = 'gdpr';

      // Same reasoning as CCPA setup: overlay gdpr.json config defaults so a
      // banner whose per-element status flags drifted off in a previous test
      // (or admin action) still renders Accept / Reject / Settings buttons
      // the test relies on. The merge keeps any banner-specific styling on
      // top of the freshly-applied status=true flags.
      $gdpr_path = trailingslashit( WP_PLUGIN_DIR ) . 'faz-cookie-manager/admin/modules/banners/includes/configs/gdpr.json';
      if ( file_exists( $gdpr_path ) ) {
        $gdpr_defaults = json_decode( file_get_contents( $gdpr_path ), true );
        if ( is_array( $gdpr_defaults ) && isset( $gdpr_defaults['config'] ) ) {
          $existing_config = isset( $settings['config'] ) && is_array( $settings['config'] ) ? $settings['config'] : array();
          $settings['config'] = array_replace_recursive( $existing_config, $gdpr_defaults['config'] );
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
      echo wp_json_encode( array(
        'banner_id'         => $row->banner_id,
        'previous'          => $previous,
        'original_settings' => $original_settings_json,
      ) );
    `).trim();

    // CodeRabbit follow-up (Biome noUnsafeFinally): see CCPA test above.
    let cleanupErr: unknown = undefined;

    try {
      const meta = JSON.parse(originalLaw);
      expect(meta.error, 'install has a default banner').toBeUndefined();

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
    } finally {
      // Restore original banner law — surface failures (CodeRabbit#1 pattern)
      try {
        const meta = JSON.parse(originalLaw);
        if (!meta.error && typeof meta.original_settings === 'string') {
          // Restore the full pre-mutation settings blob. The GDPR setup
          // also overlays gdpr.json config defaults, so a partial restore
          // (only applicableLaw) would leak the freshly-promoted status=true
          // flags into other tests in the suite.
          // base64 round-trip avoids PHP $variable interpolation in double-quoted
          // templates — see wp-env.ts::restoreActivePluginFiles for the same idiom.
          const encoded = Buffer.from(meta.original_settings, 'utf8').toString('base64');
          wpEval(`
            global $wpdb;
            $settings_json = base64_decode( '${encoded}' );
            $wpdb->update(
              $wpdb->prefix . 'faz_banners',
              array( 'settings' => $settings_json ),
              array( 'banner_id' => ${parseInt(meta.banner_id, 10)} ),
              array( '%s' ),
              array( '%d' )
            );
            \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
            faz_clear_banner_template_cache();
          `);
        }
      } catch (e) {
        // Capture; rethrow after finally (noUnsafeFinally compliance).
        cleanupErr = e;
      }
    }
    if (cleanupErr !== undefined) {
      throw new Error(`GDPR cleanup rollback failed: ${String(cleanupErr)}`);
    }
  });
});
