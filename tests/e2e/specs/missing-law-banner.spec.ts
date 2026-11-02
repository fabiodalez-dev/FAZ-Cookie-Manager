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
 * one. A legacy missing_law_banner = main_banner preference cannot waive
 * the visitor's opt-in rules.
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
    $s['geolocation']['cache_geo_bootstrap'] = false;
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

  test('AMP and the classic GDPR rendition recognize each other\'s consent', async ({ page, wpBaseURL }) => {
    const amp = JSON.parse(wpEval(`
      $banner = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      $slug = $banner->get_slug();
      echo wp_json_encode( array(
        'slug' => $slug,
        'scope' => \\FazCookie\\Frontend\\AMP_Consent_Rest::scope_token( $slug ),
        'instance' => \\FazCookie\\Frontend\\AMP_Consent_Rest::instance_id( $slug ),
      ) );
    `));
    const endpoint = (route: string) => `${wpBaseURL}/wp-json/faz/v1/amp-consent/${route}?banner=${encodeURIComponent(amp.slug)}&scope=${encodeURIComponent(amp.scope)}&faz_geo=DE`;
    const headers = { 'AMP-Same-Origin': 'true', 'Content-Type': 'text/plain;charset=utf-8' };
    await visit(page, `${wpBaseURL}/?faz_geo=DE`);
    const updated = await page.request.post(endpoint('update'), {
      headers,
      data: JSON.stringify({ consentInstanceId: amp.instance, consentStateValue: 'accepted' }),
    });
    expect(updated.status()).toBe(200);
    const ampCookie = (await page.context().cookies()).find((cookie) => cookie.name === 'fazcookie-consent');
    expect(decodeURIComponent(ampCookie?.value ?? '')).toContain('__scope.law:gdpr');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('.faz-consent-container').first(), 'classic does not re-prompt an AMP decision').toBeHidden();
    expect(await page.evaluate(() => (window as any).fazcookie._fazGetFromStore('action'))).toBe('yes');

    const classicBanner = await visit(page, `${wpBaseURL}/?faz_geo=DE`);
    await classicBanner.locator('[data-faz-tag="reject-button"]').first().click();
    await expect(classicBanner).toBeHidden();
    const checked = await page.request.post(endpoint('check'), {
      headers,
      data: JSON.stringify({ consentInstanceId: amp.instance, consentStateValue: 'unknown' }),
    });
    expect(checked.status()).toBe(200);
    expect((await checked.json()).consentStateValue, 'AMP recognizes a classic rejection').toBe('rejected');
  });

  test('the GDPR rendition keeps each language policy URL and the requested A/B variant', async ({ page, request, wpBaseURL }) => {
    const saved = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      $row = $wpdb->get_row( "SELECT * FROM {$table} WHERE banner_default = 1 ORDER BY banner_id LIMIT 1", ARRAY_A );
      $original = array( 'settings' => get_option( 'faz_settings', array() ), 'contents' => $row['contents'], 'id' => $row['banner_id'] );
      $contents = json_decode( $row['contents'], true );
      if ( ! is_array( $contents ) ) { $contents = array(); }
      $contents['en']['notice']['elements']['privacyLink'] = 'https://legal.example/en/cookies';
      $contents['it']['notice']['elements']['privacyLink'] = '/informativa-cookie-e2e/';
      $row['contents'] = wp_json_encode( $contents );
      $wpdb->update( $table, array( 'contents' => $row['contents'] ), array( 'banner_id' => $row['banner_id'] ) );
      $default_slug = $row['slug'];
      unset( $row['banner_id'] );
      $row['slug'] = 'faz-missing-law-variant';
      $row['name'] = 'Missing law variant';
      $row['banner_default'] = 0;
      $wpdb->insert( $table, $row );
      $original['variant_id'] = $wpdb->insert_id;
      $settings = $original['settings'];
      $settings['languages']['selected'] = array( 'en', 'it' );
      $settings['banner_control']['ab_test'] = array( 'status' => true, 'variants' => array( $default_slug, $row['slug'] ) );
      update_option( 'faz_settings', $settings );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      faz_clear_banner_template_cache();
      echo base64_encode( wp_json_encode( $original ) );
    `).trim();
    try {
      for (const [lang, url] of [['en', 'https://legal.example/en/cookies'], ['it', '/informativa-cookie-e2e/']]) {
        const response = await request.get(`${wpBaseURL}/wp-json/faz/v1/banner/${lang}?banner=faz-missing-law-variant&faz_geo=DE`);
        expect(response.status()).toBe(200);
        const payload = await response.json();
        expect(payload.bannerSlug, 'a language swap preserves the selected A/B peer').toBe('faz-missing-law-variant');
        expect(payload.activeLaw).toBe('gdpr');
        const readMore = payload.shortCodes.find((code: { key: string }) => code.key === 'faz_readmore');
        expect(readMore.status, 'the GDPR rendition offers the policy link').toBe(true);
        expect(readMore.content, 'the policy link remains configured for this language').toContain(url);
        expect(payload.html, 'the rendition has a real translated Reject label').toMatch(lang === 'it' ? /Rifiuta/ : /Reject/);
      }
      await visit(page, `${wpBaseURL}/?faz_geo=DE`);
      await expect(page.locator('[data-faz-tag="readmore-button"]').first()).toHaveAttribute('href', 'https://legal.example/en/cookies');
    } finally {
      wpEval(`
        global $wpdb;
        $saved = json_decode( base64_decode( '${saved}' ), true );
        $table = $wpdb->prefix . 'faz_banners';
        $wpdb->delete( $table, array( 'banner_id' => (int) $saved['variant_id'] ) );
        $wpdb->update( $table, array( 'contents' => $saved['contents'] ), array( 'banner_id' => (int) $saved['id'] ) );
        update_option( 'faz_settings', $saved['settings'] );
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
        faz_clear_banner_template_cache();
      `);
    }
  });

  test('a legacy main_banner preference cannot waive a German visitor\'s opt-in rules', async ({ page, wpBaseURL }) => {
    setMode('main_banner');
    try {
      await visit(page, `${wpBaseURL}/?faz_geo=DE`);
      await expect(page.locator('[data-faz-tag="reject-button"]').first(), 'Reject remains available').toBeVisible();
      await expect(page.locator('[data-faz-tag="donotsell-button"]:visible')).toHaveCount(0);
      const config = await page.evaluate(() => {
        const c = (window as any)._fazConfig;
        return { law: c._activeLaw, runtimeGeo: c._runtimeGeo };
      });
      expect(config.law, 'the page uses the opt-in rendition').toBe('gdpr');
      expect(config.runtimeGeo, 'the visitor\'s jurisdiction stays authoritative').toBeTruthy();
    } finally {
      setMode('gdpr_version');
    }
  });

  test('a country-restricted GDPR banner does not leave other opt-in visitors without a banner', async ({ page, wpBaseURL }) => {
    const variantId = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      $row = $wpdb->get_row( "SELECT * FROM {$table} WHERE banner_default = 1 ORDER BY banner_id LIMIT 1", ARRAY_A );
      unset( $row['banner_id'] );
      $row['name'] = 'German GDPR';
      $row['slug'] = 'faz-gdpr-germany-only';
      $row['banner_default'] = 0;
      $row['target_countries'] = wp_json_encode( array( 'DE' ) );
      $row['settings'] = file_get_contents( trailingslashit( WP_PLUGIN_DIR ) . 'faz-cookie-manager/admin/modules/banners/includes/configs/gdpr.json' );
      $wpdb->insert( $table, $row );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      faz_clear_banner_template_cache();
      echo (int) $wpdb->insert_id;
    `).trim();
    expect(Number(variantId)).toBeGreaterThan(0);
    try {
      for (const country of ['DE', 'BR', 'XX']) {
        const banner = await visit(page, `${wpBaseURL}/?faz_geo=${country}`);
        await expect(banner.locator('[data-faz-tag="reject-button"]').first()).toBeVisible();
        const config = await page.evaluate(() => ({ law: (window as any)._fazConfig._activeLaw, slug: (window as any)._fazConfig._bannerSlug }));
        expect(config.law, `${country}: an opt-in banner remains usable`).toBe('gdpr');
        if (country === 'DE') expect(config.slug).toBe('faz-gdpr-germany-only');
        else expect(config.slug).not.toBe('faz-gdpr-germany-only');
      }
    } finally {
      wpEval(`
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'faz_banners', array( 'banner_id' => (int) '${variantId}' ) );
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
        faz_clear_banner_template_cache();
      `);
    }
  });

  test('the WordPress dashboard offers an admin-only on-site check without resetting consent', async ({ page, browser, loginAsAdmin, wpBaseURL }, testInfo) => {
    const banner = await visit(page, `${wpBaseURL}/?faz_geo=DE`);
    await banner.locator('[data-faz-tag="reject-button"]').first().click();
    await expect(banner).toBeHidden();
    const savedConsent = (await page.context().cookies()).find((cookie) => cookie.name === 'fazcookie-consent');
    expect(savedConsent).toBeTruthy();
    await loginAsAdmin(page);
    await page.goto('/wp-admin/index.php', { waitUntil: 'domcontentloaded' });
    const notice = page.locator('#faz-optin-fallback-notice');
    await expect(notice, 'the explanation is visible outside the FAZ settings pages too').toBeVisible();
    await notice.screenshot({ path: testInfo.outputPath('fallback-notice.png') });
    const link = await notice.locator('a[href*="faz_banner_test=1"]').getAttribute('href');
    expect(link).toContain('_faz_test_nonce=');
    const checkUrl = `${link}&faz_geo=DE`;
    const response = await page.goto(checkUrl, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.faz-consent-container').first(), 'the administrator can see the real banner even after rejecting').toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('on-site-banner-check.png') });
    expect(response?.headers()['cache-control']).toContain('no-store');
    expect(await page.evaluate(() => !!(window as any)._fazConfig._adminBannerTest)).toBe(true);
    expect(await page.evaluate(() => (window as any).fazcookie._fazGetFromStore('marketing'))).toBe('no');
    expect(await page.evaluate(() => typeof (window as any)._fazConsentLog), 'the check does not record test choices as visitor consent').toBe('undefined');
    expect((await page.context().cookies()).find((cookie) => cookie.name === 'fazcookie-consent')?.value).toBe(savedConsent!.value);

    const anonymous = await browser.newContext({ baseURL: wpBaseURL });
    try {
      await anonymous.addCookies([savedConsent!]);
      const visitor = await anonymous.newPage();
      await visitor.goto(checkUrl, { waitUntil: 'domcontentloaded' });
      await visitor.waitForFunction(() => (window as any)._fazConfig?._swapResolved === true);
      expect(await visitor.evaluate(() => !!(window as any)._fazConfig._adminBannerTest), 'a copied admin check link cannot force the banner for a visitor').toBe(false);
      await expect(visitor.locator('.faz-consent-container').first()).toBeHidden();
    } finally {
      await anonymous.close();
    }
  });

  test('settings explain the opt-in rendition and normalize a saved legacy waiver', async ({ page, loginAsAdmin }) => {
    setMode('main_banner');
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#faz-missing-law-banner-info'), 'the fallback is explained while Geo-Targeting is on').toBeVisible({ timeout: TIMEOUT });
    await expect(page.locator('select[data-path="geolocation.missing_law_banner"]'), 'settings cannot offer an opt-out waiver').toHaveCount(0);
    const saved = page.waitForResponse((response) =>
      response.url().includes('faz/v1/settings') && response.request().method() === 'POST',
    );
    await page.locator('#faz-settings-save').click();
    expect((await saved).status()).toBe(200);
    const stored = wpEval(`$s = get_option( 'faz_settings', array() ); echo $s['geolocation']['missing_law_banner'];`).trim();
    expect(stored, 'saving settings retires the old waiver in the database').toBe('gdpr_version');
    setMode('gdpr_version');
  });
});
