/**
 * Runtime regressions found while reviewing PR #189.
 *
 * Proves that A/B experimentation cannot cross a legal/geo boundary, creates
 * no experiment cookie before consent, survives a client-side language swap,
 * becomes sticky through the existing consent scope after a decision, and that
 * the opt-in anti-adblock guard works against a real author-level !important
 * cosmetic rule.
 */

import { expect, test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const BASE_URL = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const VARIANT_A = 'faz-ab-e2e-a';
const VARIANT_B = 'faz-ab-e2e-b';
const VARIANT_CCPA = 'faz-ab-e2e-ccpa';
const VARIANT_US = 'faz-ab-e2e-us';

let settingsSnapshot = '';
let bannersSnapshot = '';

function clearRuntimeCaches(): void {
  wpEval(`
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
      faz_clear_banner_template_cache();
    }
    delete_option( 'faz_banner_template' );
  `);
}

function configureRuntime(variants: string[], adblockResilience = false): void {
  const encodedVariants = Buffer.from(JSON.stringify(variants), 'utf8').toString('base64');
  wpEval(`
    $settings = get_option( 'faz_settings', array() );
    if ( ! is_array( $settings ) ) { $settings = array(); }
    if ( ! isset( $settings['banner_control'] ) || ! is_array( $settings['banner_control'] ) ) {
      $settings['banner_control'] = array();
    }
    $variants = json_decode( base64_decode( '${encodedVariants}' ), true );
    $settings['banner_control']['status'] = true;
    $settings['banner_control']['cache_compatibility'] = false;
    $settings['banner_control']['adblock_resilience'] = ${adblockResilience ? 'true' : 'false'};
    $settings['banner_control']['ab_test'] = array(
      'status'   => ${variants.length >= 2 ? 'true' : 'false'},
      'variants' => is_array( $variants ) ? $variants : array(),
    );
    $settings['languages'] = array(
      'default'  => 'en',
      'selected' => array( 'en', 'it' ),
    );
    if ( ! isset( $settings['geolocation'] ) || ! is_array( $settings['geolocation'] ) ) {
      $settings['geolocation'] = array();
    }
    $settings['geolocation']['geo_targeting'] = false;
    if ( ! isset( $settings['age_gate'] ) || ! is_array( $settings['age_gate'] ) ) {
      $settings['age_gate'] = array();
    }
    $settings['age_gate']['enabled'] = false;
    // The sticky-assignment test needs the consent cookie but not a DB log row.
    if ( ! isset( $settings['consent_logs'] ) || ! is_array( $settings['consent_logs'] ) ) {
      $settings['consent_logs'] = array();
    }
    $settings['consent_logs']['status'] = false;
    update_option( 'faz_settings', $settings );
  `);
  clearRuntimeCaches();
}

test.describe.serial('PR #189 competitor-parity runtime invariants', () => {
  test.beforeAll(() => {
    settingsSnapshot = wpEval(`echo base64_encode( serialize( get_option( 'faz_settings', array() ) ) );`).trim();
    bannersSnapshot = wpEval(`
      global $wpdb;
      echo base64_encode( wp_json_encode( $wpdb->get_results( "SELECT * FROM {\$wpdb->prefix}faz_banners ORDER BY banner_id ASC", ARRAY_A ) ) );
    `).trim();

    wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      $fixture_settings = get_option( 'faz_settings', array() );
      if ( ! is_array( $fixture_settings ) ) { $fixture_settings = array(); }
      $fixture_settings['languages'] = array( 'default' => 'en', 'selected' => array( 'en', 'it' ) );
      update_option( 'faz_settings', $fixture_settings );
      faz_current_language( true );
      $source = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      if ( ! $source ) {
        throw new \\RuntimeException( 'No active banner available for the PR #189 fixtures.' );
      }
      $gdpr = $source->get_settings();
      if ( ! is_array( $gdpr ) ) { $gdpr = array(); }
      if ( ! isset( $gdpr['settings'] ) || ! is_array( $gdpr['settings'] ) ) { $gdpr['settings'] = array(); }
      $gdpr['settings']['applicableLaw'] = 'gdpr';
      $gdpr['settings']['type'] = 'box';
      $gdpr['settings']['preferenceCenterType'] = 'popup';
      $gdpr['settings']['languages'] = array( 'default' => 'en', 'selected' => array( 'en', 'it' ) );
      if ( ! isset( $gdpr['config']['notice']['elements']['buttons']['elements'] ) ) {
        $gdpr['config']['notice']['elements']['buttons']['elements'] = array();
      }
      foreach ( array( 'accept', 'reject', 'settings', 'readMore' ) as $button ) {
        if ( ! isset( $gdpr['config']['notice']['elements']['buttons']['elements'][ $button ] ) ) {
          $gdpr['config']['notice']['elements']['buttons']['elements'][ $button ] = array();
        }
        $gdpr['config']['notice']['elements']['buttons']['elements'][ $button ]['status'] = true;
      }
      if ( ! isset( $gdpr['config']['notice']['elements']['buttons']['elements']['donotSell'] ) ) {
        $gdpr['config']['notice']['elements']['buttons']['elements']['donotSell'] = array();
      }
      $gdpr['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] = false;
      if ( ! isset( $gdpr['config']['optoutPopup'] ) || ! is_array( $gdpr['config']['optoutPopup'] ) ) {
        $gdpr['config']['optoutPopup'] = array();
      }
      $gdpr['config']['optoutPopup']['status'] = false;

      $ccpa = $gdpr;
      $ccpa['settings']['applicableLaw'] = 'ccpa';
      $ccpa['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] = true;
      $ccpa['config']['optoutPopup']['status'] = true;
      $contents = $source->get_contents();
      $now = current_time( 'mysql' );

      $wpdb->query( "DELETE FROM {$table}" );
      $rows = array(
        array( 'name' => 'A/B E2E A', 'marker' => 'A', 'slug' => '${VARIANT_A}', 'settings' => $gdpr, 'default' => 1, 'targets' => array() ),
        array( 'name' => 'A/B E2E B', 'marker' => 'B', 'slug' => '${VARIANT_B}', 'settings' => $gdpr, 'default' => 0, 'targets' => array() ),
        array( 'name' => 'A/B E2E CCPA', 'marker' => 'CCPA', 'slug' => '${VARIANT_CCPA}', 'settings' => $ccpa, 'default' => 0, 'targets' => array() ),
        array( 'name' => 'A/B E2E US', 'marker' => 'US', 'slug' => '${VARIANT_US}', 'settings' => $gdpr, 'default' => 0, 'targets' => array( 'US' ) ),
      );
      foreach ( $rows as $row ) {
        $row_contents = $contents;
        foreach ( array( 'en' => 'EN', 'it' => 'IT' ) as $lang => $language_marker ) {
          if ( ! isset( $row_contents[ $lang ] ) || ! is_array( $row_contents[ $lang ] ) ) {
            $row_contents[ $lang ] = array();
          }
          if ( ! isset( $row_contents[ $lang ]['notice']['elements'] ) || ! is_array( $row_contents[ $lang ]['notice']['elements'] ) ) {
            $row_contents[ $lang ]['notice']['elements'] = array();
          }
          $row_contents[ $lang ]['notice']['elements']['title'] = $row['marker'] . ' ' . $language_marker;
        }
        $wpdb->insert( $table, array(
          'name'             => $row['name'],
          'slug'             => $row['slug'],
          'status'           => 1,
          'settings'         => wp_json_encode( $row['settings'] ),
          'contents'         => wp_json_encode( $row_contents ),
          'banner_default'   => $row['default'],
          'target_countries' => wp_json_encode( $row['targets'] ),
          'priority'         => 0,
          'date_created'     => $now,
          'date_modified'    => $now,
        ) );
      }
    `);
    configureRuntime([VARIANT_A, VARIANT_B]);
  });

  test.afterAll(() => {
    wpEval(`
      global $wpdb;
      $settings = unserialize( base64_decode( '${settingsSnapshot}' ) );
      update_option( 'faz_settings', is_array( $settings ) ? $settings : array() );
      $rows = json_decode( base64_decode( '${bannersSnapshot}' ), true );
      $table = $wpdb->prefix . 'faz_banners';
      $wpdb->query( "DELETE FROM {$table}" );
      if ( is_array( $rows ) ) {
        foreach ( $rows as $row ) {
          if ( is_array( $row ) ) { $wpdb->insert( $table, $row ); }
        }
      }
    `);
    clearRuntimeCaches();
  });

  test('A/B ignores incompatible-law and country-ineligible variants', async ({ browser }) => {
    configureRuntime([VARIANT_A, VARIANT_CCPA, VARIANT_US]);
    const context = await browser.newContext({ baseURL: BASE_URL });
    const page = await context.newPage();
    try {
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => typeof (window as any)._fazConfig?._bannerSlug === 'string');
      expect(await page.evaluate(() => (window as any)._fazConfig._bannerSlug)).toBe(VARIANT_A);
      const cookies = await context.cookies(BASE_URL);
      expect(cookies.some((cookie) => cookie.name === 'fazcookie-abvariant')).toBe(false);
      expect(cookies.some((cookie) => cookie.name === 'fazcookie-consent')).toBe(false);

      // A crafted language-swap request cannot use the public endpoint to
      // cross from the routed GDPR model into a CCPA banner.
      const incompatibleSwap = await page.request.get(`${BASE_URL}/?rest_route=/faz/v1/banner/it&banner=${VARIANT_CCPA}`);
      expect(incompatibleSwap.status()).toBe(200);
      expect((await incompatibleSwap.json()).bannerSlug).toBe(VARIANT_A);
    } finally {
      await context.close();
    }
  });

  test('A/B writes no pre-consent experiment cookie and reuses the consent scope after a choice', async ({ browser }) => {
    configureRuntime([VARIANT_A, VARIANT_B]);
    const context = await browser.newContext({ baseURL: BASE_URL });
    const page = await context.newPage();
    try {
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => typeof (window as any)._fazConfig?._bannerSlug === 'string');
      const firstSlug = await page.evaluate(() => (window as any)._fazConfig._bannerSlug as string);
      expect([VARIANT_A, VARIANT_B]).toContain(firstSlug);

      let cookies = await context.cookies(BASE_URL);
      expect(cookies.some((cookie) => cookie.name === 'fazcookie-abvariant')).toBe(false);
      expect(cookies.some((cookie) => cookie.name === 'fazcookie-consent')).toBe(false);

      const reject = page.locator('[data-faz-tag="reject-button"]').first();
      await expect(reject).toBeVisible();
      await reject.click();
      await expect.poll(async () => (await context.cookies(BASE_URL)).some((cookie) => cookie.name === 'fazcookie-consent')).toBe(true);

      cookies = await context.cookies(BASE_URL);
      const consent = cookies.find((cookie) => cookie.name === 'fazcookie-consent');
      expect(decodeURIComponent(consent?.value ?? '')).toContain(`__scope.banner:${firstSlug}`);
      expect(cookies.some((cookie) => cookie.name === 'fazcookie-abvariant')).toBe(false);

      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => typeof (window as any)._fazConfig?._bannerSlug === 'string');
      expect(await page.evaluate(() => (window as any)._fazConfig._bannerSlug)).toBe(firstSlug);
    } finally {
      await context.close();
    }
  });

  test('A/B language swap keeps the chosen banner and serves that banner translation', async ({ browser }) => {
    configureRuntime([VARIANT_A, VARIANT_B]);
    const context = await browser.newContext({ baseURL: BASE_URL, locale: 'it-IT' });
    const page = await context.newPage();
    let requestedVariant = '';
    page.on('request', (request) => {
      if (request.url().includes('/faz/v1/banner/it')) {
        requestedVariant = new URL(request.url()).searchParams.get('banner') ?? '';
      }
    });
    try {
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => (window as any)._fazConfig?._swapResolved === true);
      const config = await page.evaluate(() => ({
        language: (window as any)._fazConfig?._language,
        slug: (window as any)._fazConfig?._bannerSlug,
      }));
      expect(config.language).toBe('it');
      expect([VARIANT_A, VARIANT_B]).toContain(requestedVariant);
      expect(config.slug).toBe(requestedVariant);
      const expectedTitle = requestedVariant === VARIANT_A ? 'A IT' : 'B IT';
      await expect(page.locator('[data-faz-tag="title"]').first()).toHaveText(expectedTitle);
      expect((await context.cookies(BASE_URL)).some((cookie) => cookie.name === 'fazcookie-abvariant')).toBe(false);
    } finally {
      await context.close();
    }
  });

  test('wizard updates only the default banner across multiple languages and laws', () => {
    const raw = wpEval(`
      $onboarding = new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Onboarding();
      $onboarding->apply_law_to_default_banner( 'both' );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      $controller = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $a = $controller->get_active_banner_by_slug( '${VARIANT_A}' );
      $b = $controller->get_active_banner_by_slug( '${VARIANT_B}' );
      $ccpa = $controller->get_active_banner_by_slug( '${VARIANT_CCPA}' );
      $us = $controller->get_active_banner_by_slug( '${VARIANT_US}' );
      $a_settings = $a->get_settings();
      $ccpa_settings = $ccpa->get_settings();
      $a_contents = $a->get_contents();
      $ccpa_contents = $ccpa->get_contents();
      $result = array(
        'default_law' => $a->get_law(),
        'default_dns' => ! empty( $a_settings['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] ),
        'a_en' => $a_contents['en']['notice']['elements']['title'] ?? '',
        'a_it' => $a_contents['it']['notice']['elements']['title'] ?? '',
        'ccpa_law' => $ccpa->get_law(),
        'ccpa_dns' => ! empty( $ccpa_settings['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] ),
        'ccpa_en' => $ccpa_contents['en']['notice']['elements']['title'] ?? '',
        'ccpa_it' => $ccpa_contents['it']['notice']['elements']['title'] ?? '',
        'peer_laws' => array( $b->get_law(), $us->get_law() ),
      );
      // Restore the default fixture for the remaining runtime tests.
      $onboarding->apply_law_to_default_banner( 'gdpr' );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      echo wp_json_encode( $result );
    `).trim();
    const state = JSON.parse(raw);
    expect(state).toMatchObject({
      default_law: 'gdpr',
      default_dns: true,
      a_en: 'A EN',
      a_it: 'A IT',
      ccpa_law: 'ccpa',
      ccpa_dns: true,
      ccpa_en: 'CCPA EN',
      ccpa_it: 'CCPA IT',
      peer_laws: ['gdpr', 'gdpr'],
    });
  });

  test('wizard creates a canonical multilingual CCPA fallback when no banner exists', () => {
    const raw = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      $backup = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY banner_id ASC", ARRAY_A );
      $result = array();
      try {
        $wpdb->query( "DELETE FROM {$table}" );
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
        $applied = ( new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Onboarding() )->apply_law_to_default_banner( 'ccpa' );
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
        $created = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
        $settings = $created ? $created->get_settings() : array();
        $contents = $created ? $created->get_contents() : array();
        $result = array(
          'success' => true === $applied,
          'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
          'status' => $created ? $created->get_status() : false,
          'default' => $created ? $created->get_default() : false,
          'law' => $created ? $created->get_law() : '',
          'expiry' => $settings['settings']['consentExpiry']['value'] ?? 0,
          'accept' => ! empty( $settings['config']['notice']['elements']['buttons']['elements']['accept']['status'] ),
          'reject' => ! empty( $settings['config']['notice']['elements']['buttons']['elements']['reject']['status'] ),
          'dns' => ! empty( $settings['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] ),
          'languages' => array_keys( $contents ),
        );
      } finally {
        $wpdb->query( "DELETE FROM {$table}" );
        foreach ( $backup as $row ) {
          $wpdb->insert( $table, $row );
        }
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
        if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
      }
      echo wp_json_encode( $result );
    `).trim();
    const state = JSON.parse(raw);
    expect(state).toMatchObject({
      success: true,
      count: 1,
      status: true,
      default: true,
      law: 'ccpa',
      expiry: '365',
      accept: false,
      reject: false,
      dns: true,
    });
    expect(state.languages).toEqual(expect.arrayContaining(['en', 'it']));
  });

  test('wizard preserves a country-specific banner and creates a global fallback when none exists', () => {
    const raw = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      $backup = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY banner_id ASC", ARRAY_A );
      $result = array();
      try {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE slug <> %s", '${VARIANT_US}' ) );
        $wpdb->update(
          $table,
          array( 'status' => 1, 'banner_default' => 0, 'target_countries' => wp_json_encode( array( 'US' ) ) ),
          array( 'slug' => '${VARIANT_US}' ),
          array( '%d', '%d', '%s' ),
          array( '%s' )
        );
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
        $applied = ( new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Onboarding() )->apply_law_to_default_banner( 'ccpa' );
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
        $controller = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
        $targeted = $controller->get_active_banner_by_slug( '${VARIANT_US}' );
        $global = $controller->get_active_banner();
        $targeted_contents = $targeted ? $targeted->get_contents() : array();
        $global_settings = $global ? $global->get_settings() : array();
        $result = array(
          'success' => true === $applied,
          'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
          'targeted_law' => $targeted ? $targeted->get_law() : '',
          'targeted_countries' => $targeted ? $targeted->get_target_countries() : array(),
          'targeted_default' => $targeted ? $targeted->get_default() : true,
          'targeted_en' => $targeted_contents['en']['notice']['elements']['title'] ?? '',
          'targeted_it' => $targeted_contents['it']['notice']['elements']['title'] ?? '',
          'global_slug' => $global ? $global->get_slug() : '',
          'global_law' => $global ? $global->get_law() : '',
          'global_default' => $global ? $global->get_default() : false,
          'global_targets' => $global ? $global->get_target_countries() : array( 'unexpected' ),
          'global_dns' => ! empty( $global_settings['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] ),
        );
      } finally {
        $wpdb->query( "DELETE FROM {$table}" );
        foreach ( $backup as $row ) {
          $wpdb->insert( $table, $row );
        }
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
        if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
      }
      echo wp_json_encode( $result );
    `).trim();
    const state = JSON.parse(raw);
    expect(state).toMatchObject({
      success: true,
      count: 2,
      targeted_law: 'gdpr',
      targeted_countries: ['US'],
      targeted_default: false,
      targeted_en: 'US EN',
      targeted_it: 'US IT',
      global_law: 'ccpa',
      global_default: true,
      global_targets: [],
      global_dns: true,
    });
    expect(state.global_slug).not.toBe(VARIANT_US);
  });

  test('anti-adblock resilience restores a notice hidden by a cosmetic !important rule', async ({ browser }) => {
    configureRuntime([], true);
    const context = await browser.newContext({ baseURL: BASE_URL });
    const page = await context.newPage();
    try {
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await page.addStyleTag({ content: '#faz-consent { display: none !important; visibility: hidden !important; }' });
      const banner = page.locator('.faz-consent-container').first();
      await expect(banner).toBeAttached();
      expect(await banner.evaluate((node) => getComputedStyle(node).display)).toBe('none');
      await expect(banner).toBeVisible({ timeout: 4_000 });
      expect(await page.locator('#faz-consent').getAttribute('data-faz-reasserted')).toBe('1');
    } finally {
      await context.close();
    }
  });
});
