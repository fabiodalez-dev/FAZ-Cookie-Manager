/**
 * "Dim the page behind the banner" overlay (settings.softCookieWall).
 *
 * Product decision pinned by these tests: the overlay is a VISUAL CUE ONLY.
 * It greys the page to draw attention to the banner but must never block
 * interaction — otherwise it becomes a cookie wall, and a cookie wall that
 * conditions access on a consent choice fails the GDPR/EDPB "freely given"
 * requirement (Guidelines 05/2020 §39). The overlay is therefore rendered with
 * `pointer-events: none` and `aria-hidden="true"`, and is stripped for the
 * Classic layout regardless of how the flag reached the database.
 *
 * If a future change removes `pointer-events: none` (turning the dimmer into a
 * real blocker) or lets a Classic banner keep the overlay, these tests fail —
 * on purpose. The behaviour is a compliance contract, not an incidental detail.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

/** Force the default banner to a given type + softCookieWall flag under GDPR,
 *  returning the original settings JSON so the test can restore it. */
function seedDefaultBanner(type: 'box' | 'banner' | 'classic', softCookieWall: boolean): string {
  const safeType = ['box', 'banner', 'classic'].includes(type) ? type : 'box';
  const wall = softCookieWall ? 'true' : 'false';
  return wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $original = $row->settings;
    $settings = json_decode( $row->settings, true );
    if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
    $settings['settings']['applicableLaw']  = 'gdpr';
    $settings['settings']['type']           = '${safeType}';
    $settings['settings']['softCookieWall'] = ${wall};
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

test.describe('Soft cookie wall overlay', () => {
  test('overlay is a non-blocking visual cue and is removed on consent', async ({ page, context }) => {
    const meta = JSON.parse(seedDefaultBanner('box', true));
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();
      await context.clearCookies();

      await page.goto(WP_BASE, { waitUntil: 'domcontentloaded', timeout: 45_000 });

      // The overlay is injected when the banner is shown.
      const wall = page.locator('#faz-cookie-wall');
      await expect(wall, 'overlay injected with the banner').toHaveCount(1);
      await expect(page.locator('.faz-consent-container'), 'banner is shown').toBeVisible();

      // Compliance contract: the overlay never blocks interaction.
      const probe = await page.evaluate(() => {
        const el = document.getElementById('faz-cookie-wall');
        const cs = el ? getComputedStyle(el) : null;
        // Hit-test a corner clear of the banner. With pointer-events:none the
        // overlay is transparent to hit-testing, so this must NOT be the wall.
        const hit = document.elementFromPoint(5, 5) as HTMLElement | null;
        const container = document.querySelector('.faz-consent-container') as HTMLElement | null;
        return {
          pointerEvents: cs ? cs.pointerEvents : null,
          ariaHidden: el ? el.getAttribute('aria-hidden') : null,
          wallZ: cs ? parseInt(cs.zIndex || '0', 10) : 0,
          bannerZ: container ? parseInt(getComputedStyle(container).zIndex || '0', 10) : 0,
          hitIsWall: hit ? hit.id === 'faz-cookie-wall' : false,
          // WordPress core's block-border rule `:where([style*="border-color"])`
          // matches the container (its inline style carries the …-border-color
          // CSS variables) and would paint a spurious solid currentColor border.
          // The plugin resets border-style on #faz-consent; assert it stuck.
          containerBorderStyle: container ? getComputedStyle(container).borderStyle : null,
        };
      });
      expect(probe.pointerEvents, 'overlay must not capture pointer events').toBe('none');
      expect(probe.ariaHidden, 'overlay hidden from assistive tech').toBe('true');
      expect(probe.hitIsWall, 'the page under the overlay stays hit-testable').toBe(false);
      expect(probe.bannerZ, 'banner sits above the overlay').toBeGreaterThan(probe.wallZ);
      expect(probe.containerBorderStyle, 'no spurious WP block-border on the banner container').toBe('none');

      // Making a choice removes the overlay.
      await page.locator('[data-faz-tag="accept-button"]').first().click();
      await expect(wall, 'overlay removed after consent').toHaveCount(0);
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });

  test('Classic layout never renders the overlay, even when the flag is set directly in the DB', async ({ page, context }) => {
    // Simulate a direct DB / REST write that bypasses the editor's client-side
    // reset: type=classic AND softCookieWall=true. The server guard
    // (apply_runtime_layout_compatibility + the frontend data pipeline) must
    // strip it so no overlay reaches a Classic banner.
    const meta = JSON.parse(seedDefaultBanner('classic', true));
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();
      await context.clearCookies();

      await page.goto(WP_BASE, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      await page.waitForFunction(
        () => !!(window as unknown as { _fazConfig?: { _bannerConfig?: unknown } })._fazConfig?._bannerConfig,
        { timeout: 10_000 },
      );

      const flag = await page.evaluate(() => {
        const cfg = (window as unknown as {
          _fazConfig: { _bannerConfig: { settings: { type: string; softCookieWall?: boolean } } };
        })._fazConfig._bannerConfig;
        return { type: cfg.settings.type, softCookieWall: cfg.settings.softCookieWall };
      });
      expect(flag.type, 'served as a Classic layout').toBe('classic');
      expect(flag.softCookieWall, 'overlay flag stripped for Classic').toBeFalsy();
      await expect(page.locator('#faz-cookie-wall'), 'no overlay rendered on Classic').toHaveCount(0);
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });
});
