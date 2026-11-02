/**
 * An opt-in visitor on a site whose only banner is opt-out (#377).
 *
 * Geo-Targeting is on by default. With one CCPA banner, every visitor whose
 * ruleset is opt-in (the EU, the UK, a US visitor without a resolvable state,
 * and anyone whose country cannot be detected) used to get no banner at all:
 * load_banner() kept the CCPA banner for blocking and hid its UI. A forum
 * report showed "3 pageviews, 0 banner views" on such a site.
 *
 * Now, by default, that visitor sees the same banner rendered as an opt-in
 * one. Settings > Geolocation > missing_law_banner = main_banner shows the
 * CCPA banner as it is instead, under its own rules.
 *
 * faz-test's dev mu-plugin fakes CF-IPCountry: ?faz_geo=DE is a German
 * visitor, ?faz_geo=US&faz_region=CA a Californian one.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

const TIMEOUT = 10000;

let snapshot = '';

function setMode(mode: 'gdpr_version' | 'main_banner'): void {
  wpEval(`
    $s = get_option( 'faz_settings', array() );
    $s['geolocation']['geo_targeting']      = true;
    $s['geolocation']['missing_law_banner'] = '${mode}';
    // A visitor outside the target regions must still get a banner here,
    // or the Californian case measures the region filter instead.
    $s['geolocation']['default_behavior']   = 'show_banner';
    update_option( 'faz_settings', $s );
    wp_cache_delete( 'faz_settings', 'options' );
    \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
    faz_clear_banner_template_cache();
  `);
}

async function visit(page, url: string) {
  await page.context().clearCookies();
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  const banner = page.locator('.faz-consent-container').first();
  await expect(banner, 'a banner is on screen').toBeVisible({ timeout: TIMEOUT });
  return banner;
}

test.describe.serial('Opt-in visitors when the only banner is opt-out (#377)', () => {
  test.beforeAll(() => {
    resetDefaultBannerState();
    // One active banner, set to CCPA with the CCPA defaults laid over its
    // config, as the editor does when the law is switched. Everything is
    // restored from this snapshot afterwards.
    snapshot = wpEval(`
      global $wpdb;
      $t    = $wpdb->prefix . 'faz_banners';
      $rows = $wpdb->get_results( "SELECT banner_id, status, banner_default, settings FROM {$t}", ARRAY_A );
      $snap = array( 'rows' => $rows, 'faz_settings' => get_option( 'faz_settings', array() ) );
      $default = null;
      foreach ( $rows as $row ) {
        if ( 1 === (int) $row['banner_default'] && null === $default ) {
          $default = $row;
        }
      }
      if ( ! $default ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); return; }
      $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 0 WHERE banner_id <> %d", $default['banner_id'] ) );
      $settings = json_decode( $default['settings'], true );
      $settings['settings']['applicableLaw'] = 'ccpa';
      $ccpa = json_decode( file_get_contents( trailingslashit( WP_PLUGIN_DIR ) . 'faz-cookie-manager/admin/modules/banners/includes/configs/ccpa.json' ), true );
      $settings['config'] = array_replace_recursive( isset( $settings['config'] ) ? $settings['config'] : array(), $ccpa['config'] );
      $wpdb->update( $t, array( 'settings' => wp_json_encode( $settings ), 'status' => 1 ), array( 'banner_id' => $default['banner_id'] ) );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      faz_clear_banner_template_cache();
      echo base64_encode( wp_json_encode( $snap ) );
    `).trim();
    expect(snapshot.startsWith('{'), 'the install has a default banner').toBe(false);
    setMode('gdpr_version');
  });

  test.afterAll(() => {
    if (!snapshot) return;
    wpEval(`
      global $wpdb;
      $snap = json_decode( base64_decode( '${snapshot}' ), true );
      foreach ( $snap['rows'] as $row ) {
        $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'status' => (int) $row['status'], 'settings' => $row['settings'] ), array( 'banner_id' => (int) $row['banner_id'] ) );
      }
      update_option( 'faz_settings', $snap['faz_settings'] );
      wp_cache_delete( 'faz_settings', 'options' );
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      faz_clear_banner_template_cache();
    `);
  });

  test('a German visitor sees the CCPA banner rendered as an opt-in banner', async ({ page, wpBaseURL }) => {
    const banner = await visit(page, `${wpBaseURL}/?faz_geo=DE`);
    await expect(banner.locator('[data-faz-tag="accept-button"]').first(), 'Accept is offered').toBeVisible();
    await expect(banner.locator('[data-faz-tag="reject-button"]').first(), 'Reject is offered').toBeVisible();
    await expect(page.locator('[data-faz-tag="donotsell-button"]:visible'), 'no Do Not Sell link').toHaveCount(0);
    expect(await page.evaluate(() => (window as any)._fazConfig._activeLaw), 'the page runs under the opt-in law').toBe('gdpr');

    await banner.locator('[data-faz-tag="accept-button"]').first().click();
    await expect(banner, 'accepting closes the banner').toBeHidden({ timeout: TIMEOUT });
    const consent = (await page.context().cookies()).find((c) => c.name === 'fazcookie-consent');
    expect(decodeURIComponent(consent?.value ?? ''), 'the choice is recorded').toContain('action:yes');
  });

  test('a Californian visitor still gets the CCPA banner', async ({ page, wpBaseURL }) => {
    await visit(page, `${wpBaseURL}/?faz_geo=US&faz_region=CA`);
    await expect(page.locator('[data-faz-tag="donotsell-button"]').first(), 'Do Not Sell is offered').toBeVisible();
    expect(await page.evaluate(() => (window as any)._fazConfig._activeLaw)).toBe('ccpa');
  });

  test('with "show your banner as it is", the German visitor gets the CCPA banner and its rules', async ({ page, wpBaseURL }) => {
    setMode('main_banner');
    try {
      await visit(page, `${wpBaseURL}/?faz_geo=DE`);
      await expect(page.locator('[data-faz-tag="donotsell-button"]').first(), 'Do Not Sell is offered').toBeVisible();
      const config = await page.evaluate(() => {
        const c = (window as any)._fazConfig;
        return { law: c._activeLaw, runtimeGeo: c._runtimeGeo };
      });
      expect(config.law, 'the page runs under the banner\'s own law').toBe('ccpa');
      expect(config.runtimeGeo, 'no opt-in ruleset is applied under an opt-out banner').toBeFalsy();
    } finally {
      setMode('gdpr_version');
    }
  });

  test('the choice is offered under Geo-Targeting and saved', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    const select = page.locator('select[data-path="geolocation.missing_law_banner"]');
    await expect(select, 'the setting is shown while Geo-Targeting is on').toBeVisible({ timeout: TIMEOUT });
    await expect(select).toHaveValue('gdpr_version');
    await select.selectOption('main_banner');
    const saved = page.waitForResponse((response) =>
      response.url().includes('faz/v1/settings') && response.request().method() === 'POST',
    );
    await page.locator('#faz-settings-save').click();
    expect((await saved).status()).toBe(200);
    const stored = wpEval(`$s = get_option( 'faz_settings', array() ); echo $s['geolocation']['missing_law_banner'];`).trim();
    expect(stored, 'the choice reached the database').toBe('main_banner');
    setMode('gdpr_version');
  });
});
