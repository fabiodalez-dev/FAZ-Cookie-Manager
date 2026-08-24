/**
 * Cache-safe jurisdiction bootstrap (opt-in).
 *
 * One globally cacheable response contains a strict GDPR shell with every
 * optional resource inert. The browser then asks the public no-store banner
 * endpoint for its live jurisdiction before the banner mounts or the first
 * unblock pass runs. These tests use two active global banners and a real
 * blocked inline script, so the assertions can distinguish the strict shell
 * from the live CCPA result instead of passing vacuously on a one-banner site.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { type APIRequestContext } from '@playwright/test';
import { isPluginActive, upsertPage, wp, wpEval } from '../utils/wp-env';
import { acquireSharedWordPressLock, releaseSharedWordPressLock } from '../utils/shared-wordpress-lock';

const BASE_URL = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const MU_NAME = 'faz-e2e-cache-geo-bootstrap.php';
const LEGACY_MU_NAME = 'faz-e2e-law-vary-optin.php';
const PAGE_SLUG = 'faz-cache-geo-bootstrap';
const STRICT_MARKER = 'STRICT GDPR E2E';
const LIVE_MARKER = 'LIVE CCPA E2E';
const PROBE_ID = 'faz-e2e-bootstrap-optional';
const UA = 'Mozilla/5.0 (FAZ E2E cache-safe geo bootstrap)';

let lockHeld = false;
let settingsSnapshot = '';
let bannersSnapshot = '';
let runtimeOffSnapshot = '';
let pageUrl = '';
let flyingPressAvailable = false;
let weActivatedFlyingPress = false;
let consentRevision = 1;

test.describe.configure({ mode: 'serial' });

function requestHeaders(country: 'IT' | 'US', region = ''): Record<string, string> {
  return {
    'User-Agent': UA,
    'X-FAZ-E2E-Country': country,
    ...(region ? { 'X-FAZ-E2E-Region': region } : {}),
  };
}

function writeMu(): void {
  wpEval(`
    $code = <<<'FAZPHP'
<?php
/** Test-only, request-scoped bootstrap + jurisdiction fixture. */
$faz_e2e_country = isset( $_SERVER['HTTP_X_FAZ_E2E_COUNTRY'] )
	? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $_SERVER['HTTP_X_FAZ_E2E_COUNTRY'] ) )
	: '';
$faz_e2e_region = isset( $_SERVER['HTTP_X_FAZ_E2E_REGION'] )
	? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $_SERVER['HTTP_X_FAZ_E2E_REGION'] ) )
	: '';

// FlyingPress writes the cache through a server-originated preload that does
// not carry the visitor header. It must still render the same strict shell.
$faz_e2e_preload = ! empty( $_SERVER['HTTP_X_FLYING_PRESS_PRELOAD'] );
// Exercise the opt-in name already shipped by the abandoned two-key design;
// production maps it onto the new one-shell bootstrap for upgrade safety.
add_filter( 'faz_cache_vary_by_law', function ( $enabled ) use ( $faz_e2e_country, $faz_e2e_preload ) {
	return $faz_e2e_country || $faz_e2e_preload ? true : $enabled;
}, PHP_INT_MAX );

if ( $faz_e2e_country ) {
	// Override the dev fake-CF mu-plugin after every mu-plugin has loaded.
	add_action( 'muplugins_loaded', function () use ( $faz_e2e_country, $faz_e2e_region ) {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = $faz_e2e_country;
		if ( $faz_e2e_region ) {
			$_SERVER['HTTP_CF_REGION_CODE'] = $faz_e2e_region;
		} else {
			unset( $_SERVER['HTTP_CF_REGION_CODE'] );
		}
	}, PHP_INT_MAX );
	add_filter( 'faz_trust_cf_ipcountry_header', '__return_true', PHP_INT_MAX );
	add_filter( 'faz_visitor_country', function () use ( $faz_e2e_country ) { return $faz_e2e_country; }, PHP_INT_MAX );
	add_filter( 'faz_geo_admin_override_country', function () use ( $faz_e2e_country ) { return $faz_e2e_country; }, PHP_INT_MAX );
	add_filter( 'faz_visitor_region', function () use ( $faz_e2e_country, $faz_e2e_region ) {
		return $faz_e2e_region ? $faz_e2e_country . '-' . $faz_e2e_region : '';
	}, PHP_INT_MAX );

}

if ( $faz_e2e_country || $faz_e2e_preload ) {
	// Already inert in every cacheable shell, including FlyingPress's
	// headerless preload. A successful California bootstrap may restore it; a
	// failed bootstrap must leave it text/plain and unexecuted.
	add_action( 'wp_footer', function () {
		echo '<script id="${PROBE_ID}" type="text/plain" data-faz-category="marketing">window.__fazBootstrapOptionalRan = true;</script>';
	}, 5 );
}
FAZPHP;
    if ( ! is_dir( WP_CONTENT_DIR . '/mu-plugins' ) ) {
      wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
    }
    file_put_contents( WP_CONTENT_DIR . '/mu-plugins/${MU_NAME}', $code );
    @unlink( WP_CONTENT_DIR . '/mu-plugins/${LEGACY_MU_NAME}' );
  `);
}

function removeMu(): void {
  wpEval(`
    @unlink( WP_CONTENT_DIR . '/mu-plugins/${MU_NAME}' );
    @unlink( WP_CONTENT_DIR . '/mu-plugins/${LEGACY_MU_NAME}' );
  `);
}

function clearCaches(): void {
  wpEval(`
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
    if ( class_exists( '\\FlyingPress\\Purge' ) ) { \\FlyingPress\\Purge::purge_everything(); }
  `);
}

function privateCall(method: string): string {
  return wpEval(`
    $frontend = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.27.0' );
    $method = new ReflectionMethod( $frontend, '${method}' );
    $method->setAccessible( true );
    echo var_export( $method->invoke( $frontend ), true );
  `).trim();
}

async function cacheState(request: APIRequestContext, country: 'IT' | 'US' = 'US'): Promise<string> {
  const response = await request.get(pageUrl, { headers: requestHeaders(country, country === 'US' ? 'CA' : '') });
  return (response.headers()['x-flying-press-cache'] ?? '').toUpperCase();
}

async function primeCache(request: APIRequestContext): Promise<void> {
  let seen = '';
  for (let attempt = 0; attempt < 24; attempt += 1) {
    seen = await cacheState(request, 'US');
    if (seen === 'HIT') return;
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`FlyingPress did not produce a HIT within 12s; last state: ${seen || '(header absent)'}`);
}

test.beforeAll(async ({}, testInfo) => {
  testInfo.setTimeout(4 * 60_000);
  await acquireSharedWordPressLock();
  lockHeld = true;

  settingsSnapshot = wpEval(`echo base64_encode( serialize( get_option( 'faz_settings', array() ) ) );`).trim();
  consentRevision = Math.max(1, Number(wpEval(`
    $settings = get_option( 'faz_settings', array() );
    echo isset( $settings['general']['consent_revision'] )
      ? max( 1, absint( $settings['general']['consent_revision'] ) )
      : 1;
  `).trim()) || 1);
  runtimeOffSnapshot = wpEval(`echo base64_encode( serialize( get_option( 'faz_e2e_geo_runtime_off', null ) ) );`).trim();
  bannersSnapshot = wpEval(`
    global $wpdb;
    echo base64_encode( wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}faz_banners ORDER BY banner_id ASC", ARRAY_A ) ) );
  `).trim();

  try {
    wp(['plugin', 'is-installed', 'flying-press']);
    if (!isPluginActive('flying-press')) {
      wp(['plugin', 'activate', 'flying-press']);
      weActivatedFlyingPress = true;
    }
    flyingPressAvailable = isPluginActive('flying-press');
  } catch {
    flyingPressAvailable = false;
  }

  writeMu();
  wpEval(`
    global $wpdb;
    delete_option( 'faz_e2e_geo_runtime_off' );
    $settings = get_option( 'faz_settings', array() );
    if ( ! is_array( $settings ) ) { $settings = array(); }
    if ( ! isset( $settings['banner_control'] ) || ! is_array( $settings['banner_control'] ) ) { $settings['banner_control'] = array(); }
    if ( ! isset( $settings['geolocation'] ) || ! is_array( $settings['geolocation'] ) ) { $settings['geolocation'] = array(); }
    if ( ! isset( $settings['iab'] ) || ! is_array( $settings['iab'] ) ) { $settings['iab'] = array(); }
    $settings['banner_control']['status'] = true;
    $settings['banner_control']['cache_compatibility'] = false;
    $settings['banner_control']['hide_from_bots'] = false;
    $settings['banner_control']['ab_test'] = array( 'status' => false, 'variants' => array() );
    $settings['geolocation']['geo_targeting'] = false;
    $settings['iab']['enabled'] = false;
    $settings['languages'] = array( 'default' => 'en', 'selected' => array( 'en' ) );
    update_option( 'faz_settings', $settings );
    faz_current_language( true );

    $controller = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
    $source = $controller->get_active_banner();
    if ( ! $source ) { throw new \\RuntimeException( 'No source banner for cache bootstrap fixture.' ); }
    $gdpr = $source->get_settings();
    $contents = $source->get_contents();
    if ( ! isset( $gdpr['settings'] ) || ! is_array( $gdpr['settings'] ) ) { $gdpr['settings'] = array(); }
    $gdpr['settings']['applicableLaw'] = 'gdpr';
    $gdpr['settings']['ruleSet'] = array( array( 'code' => 'ALL' ) );
    $ccpa = $gdpr;
    $ccpa['settings']['applicableLaw'] = 'ccpa';
    if ( ! isset( $ccpa['config']['notice']['elements']['buttons']['elements']['donotSell'] ) ) {
      $ccpa['config']['notice']['elements']['buttons']['elements']['donotSell'] = array();
    }
    $ccpa['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] = true;
    if ( ! isset( $ccpa['config']['optoutPopup'] ) || ! is_array( $ccpa['config']['optoutPopup'] ) ) { $ccpa['config']['optoutPopup'] = array(); }
    $ccpa['config']['optoutPopup']['status'] = true;

    $gdpr_contents = $contents;
    $ccpa_contents = $contents;
    foreach ( array( 'en' ) as $language ) {
      if ( ! isset( $gdpr_contents[$language]['notice']['elements'] ) || ! is_array( $gdpr_contents[$language]['notice']['elements'] ) ) {
        $gdpr_contents[$language]['notice']['elements'] = array();
      }
      if ( ! isset( $ccpa_contents[$language]['notice']['elements'] ) || ! is_array( $ccpa_contents[$language]['notice']['elements'] ) ) {
        $ccpa_contents[$language]['notice']['elements'] = array();
      }
      $gdpr_contents[$language]['notice']['elements']['title'] = '${STRICT_MARKER}';
      $ccpa_contents[$language]['notice']['elements']['title'] = '${LIVE_MARKER}';
    }

    $table = $wpdb->prefix . 'faz_banners';
    $wpdb->query( "DELETE FROM {$table}" );
    $now = current_time( 'mysql' );
    $wpdb->insert( $table, array(
      'name' => 'Strict GDPR shell', 'slug' => 'faz-strict-gdpr-shell', 'status' => 1,
      'settings' => wp_json_encode( $gdpr ), 'contents' => wp_json_encode( $gdpr_contents ),
      'banner_default' => 1, 'target_countries' => '[]', 'priority' => 0,
      'date_created' => $now, 'date_modified' => $now,
    ) );
    $wpdb->insert( $table, array(
      'name' => 'Live CCPA banner', 'slug' => 'faz-live-ccpa', 'status' => 1,
      'settings' => wp_json_encode( $ccpa ), 'contents' => wp_json_encode( $ccpa_contents ),
      'banner_default' => 0, 'target_countries' => '[]', 'priority' => 0,
      'date_created' => $now, 'date_modified' => $now,
    ) );
  `);

  const pageId = upsertPage(PAGE_SLUG, 'FAZ cache-safe geo bootstrap', '<p>Cache-safe geo bootstrap fixture.</p>');
  pageUrl = wpEval(`echo get_permalink( ${pageId} );`).trim();
  clearCaches();

  if (flyingPressAvailable) {
    // Rebuild the pre-WordPress drop-in after removing the abandoned cookie
    // filter. This also proves the resulting cache key has no jurisdiction
    // cookie baked into it.
    wpEval(`if ( class_exists( '\\FlyingPress\\AdvancedCache' ) ) { \\FlyingPress\\AdvancedCache::add_advanced_cache(); }`);
  }
});

test.afterAll(() => {
  const cleanupErrors: Error[] = [];
  const cleanup = (label: string, action: () => void): void => {
    try {
      action();
    } catch (error) {
      const detail = error instanceof Error ? error.message : String(error);
      cleanupErrors.push(new Error(`${label}: ${detail}`));
    }
  };
  try {
    cleanup('remove cache bootstrap fixture', () => {
      removeMu();
    });
    const restoreState = `
      global $wpdb;
      $settings = unserialize( base64_decode( '${settingsSnapshot}' ) );
      update_option( 'faz_settings', is_array( $settings ) ? $settings : array() );
      $runtime_off = unserialize( base64_decode( '${runtimeOffSnapshot}' ) );
      if ( null === $runtime_off ) { delete_option( 'faz_e2e_geo_runtime_off' ); }
      else { update_option( 'faz_e2e_geo_runtime_off', $runtime_off ); }
      $rows = json_decode( base64_decode( '${bannersSnapshot}' ), true );
      $table = $wpdb->prefix . 'faz_banners';
      $wpdb->query( "DELETE FROM {$table}" );
      if ( is_array( $rows ) ) {
        foreach ( $rows as $row ) { if ( is_array( $row ) ) { $wpdb->insert( $table, $row ); } }
      }
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
      if ( class_exists( '\\FlyingPress\\Purge' ) ) { \\FlyingPress\\Purge::purge_everything(); }
    `;
    cleanup('restore WordPress banner and runtime state', () => {
      try {
        wpEval(restoreState);
      } catch (primaryError) {
        try {
          wp(['eval', restoreState]);
        } catch (fallbackError) {
          throw new Error(`primary=${String(primaryError)}; fallback=${String(fallbackError)}`);
        }
      }
    });
    cleanup('repair the FlyingPress drop-in', () => {
      wpEval(`if ( class_exists( '\\FlyingPress\\AdvancedCache' ) ) { \\FazCookie\\Includes\\CLI::remove_legacy_flyingpress_law_vary(); }`);
    });
    if (weActivatedFlyingPress && isPluginActive('flying-press')) {
      cleanup('restore FlyingPress activation state', () => {
        wp(['plugin', 'deactivate', 'flying-press']);
      });
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
  if (cleanupErrors.length > 0) {
    throw new AggregateError(cleanupErrors, 'Cache bootstrap E2E cleanup failed');
  }
});

test('strict shell is visitor-invariant; live endpoint is jurisdictional and no-store', async ({ request }) => {
  expect(privateCall('is_geo_bootstrap_cache_active')).toBe('false'); // wp-cli carries neither test header nor preload marker.

  const californiaShell = await request.get(pageUrl, { headers: requestHeaders('US', 'CA') });
  const italyShell = await request.get(pageUrl, { headers: requestHeaders('IT') });
  const caHtml = await californiaShell.text();
  const itHtml = await italyShell.text();

  for (const html of [caHtml, itHtml]) {
    expect(html).toMatch(/"_geoBootstrap":(?:true|"1")/);
    expect(html).toContain('"applicableLaw":"gdpr"');
    expect(html).toContain(STRICT_MARKER);
    expect(html).not.toContain('"applicableLaw":"ccpa"');
  }
  const shellCookies = californiaShell.headersArray()
    .filter((header) => header.name.toLowerCase() === 'set-cookie')
    .map((header) => header.value)
    .join('; ');
  expect(shellCookies).not.toContain('faz-law');

  const endpoint = `${BASE_URL}/wp-json/faz/v1/banner/en`;
  const caLive = await request.get(endpoint, { headers: requestHeaders('US', 'CA') });
  const itLive = await request.get(endpoint, { headers: requestHeaders('IT') });
  expect(caLive.ok()).toBe(true);
  expect(itLive.ok()).toBe(true);
  const caPayload = await caLive.json();
  const itPayload = await itLive.json();
  expect(caPayload.activeLaw).toBe('ccpa');
  expect(caPayload.bannerSlug).toBe('faz-live-ccpa');
  expect(caPayload.html).toContain(LIVE_MARKER);
  expect(caPayload.bannerConfig.settings.applicableLaw).toBe('ccpa');
  expect(Array.isArray(caPayload.tags)).toBe(true);
  expect(typeof caPayload.scopeFingerprint).toBe('string');
  expect(itPayload.activeLaw).toBe('gdpr');
  expect(itPayload.html).toContain(STRICT_MARKER);
  expect(caLive.headers()['cache-control'].toLowerCase()).toContain('no-store');
  expect(caLive.headers()['cdn-cache-control'].toLowerCase()).toBe('no-store');
  expect(caLive.headers()['cloudflare-cdn-cache-control'].toLowerCase()).toBe('no-store');
  expect(caLive.headers()['x-faz-jurisdiction']).toBe('live');
});

test('California resolves before mount and only then releases allowed optional code', async ({ browser }) => {
  const context = await browser.newContext({ baseURL: BASE_URL, extraHTTPHeaders: requestHeaders('US', 'CA') });
  const page = await context.newPage();
  try {
    const liveResponsePromise = page.waitForResponse((response) => response.url().includes('/wp-json/faz/v1/banner/en'));
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    const liveResponse = await liveResponsePromise;
    expect(liveResponse.status()).toBe(200);
    await page.waitForFunction(() => (window as any).fazcookie?._diag?.().geoBootstrapResolved === 'live');
    await page.waitForFunction(() => (window as any).__fazBootstrapOptionalRan === true);

    const state = await page.evaluate(() => ({
      law: (window as any).getFazConsent?.().activeLaw,
      slug: (window as any)._fazConfig?._bannerSlug,
      resolved: (window as any).fazcookie?._diag?.().geoBootstrapResolved,
      optionalRan: (window as any).__fazBootstrapOptionalRan === true,
    }));
    expect(state).toEqual({ law: 'ccpa', slug: 'faz-live-ccpa', resolved: 'live', optionalRan: true });
    await expect(page.locator('[data-faz-tag="notice"]').first()).toContainText(LIVE_MARKER);
    expect((await context.cookies(BASE_URL)).some((cookie) => cookie.name === 'faz-law')).toBe(false);
  } finally {
    await context.close();
  }
});

test('a returning California consent survives the strict cache placeholder and matches the live scope', async ({ browser, request }) => {
  const endpoint = `${BASE_URL}/wp-json/faz/v1/banner/en`;
  const liveResponse = await request.get(endpoint, { headers: requestHeaders('US', 'CA') });
  expect(liveResponse.ok()).toBe(true);
  const livePayload = await liveResponse.json();
  expect(livePayload.activeLaw).toBe('ccpa');

  const consent = [
    'consentid:returning-california',
    'consent:yes',
    'action:yes',
    `__scope.banner:${livePayload.bannerSlug}`,
    '__scope.law:ccpa',
    `__scope.fp:${livePayload.scopeFingerprint}`,
    'necessary:yes',
    'marketing:yes',
    `rev:${consentRevision}`,
  ].join(',');
  const context = await browser.newContext({ baseURL: BASE_URL, extraHTTPHeaders: requestHeaders('US', 'CA') });
  await context.addCookies([{ name: 'fazcookie-consent', value: encodeURIComponent(consent), url: BASE_URL }]);
  const page = await context.newPage();
  let releaseLiveRequest!: () => void;
  let markLiveRequestSeen!: () => void;
  const liveRequestGate = new Promise<void>((resolve) => { releaseLiveRequest = resolve; });
  const liveRequestSeen = new Promise<void>((resolve) => { markLiveRequestSeen = resolve; });
  await page.route('**/wp-json/faz/v1/banner/**', async (route) => {
    markLiveRequestSeen();
    await liveRequestGate;
    await route.continue();
  });
  try {
    const navigation = page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await liveRequestSeen;
    const pending = await page.evaluate(() => ({
      action: (window as any).fazcookie?._fazGetFromStore?.('action'),
      marketing: (window as any).fazcookie?._fazGetFromStore?.('marketing'),
      optionalRan: (window as any).__fazBootstrapOptionalRan === true,
      resolved: (window as any).fazcookie?._diag?.().geoBootstrapResolved,
    }));
    expect(pending).toEqual({ action: 'yes', marketing: 'yes', optionalRan: false, resolved: '' });
    releaseLiveRequest();
    await navigation;
    await page.waitForFunction(() => (window as any).fazcookie?._diag?.().geoBootstrapResolved === 'live');
    await page.waitForFunction(() => (window as any).__fazBootstrapOptionalRan === true);
    const state = await page.evaluate(() => ({
      law: (window as any).getFazConsent?.().activeLaw,
      action: (window as any).fazcookie?._fazGetFromStore?.('action'),
      marketing: (window as any).fazcookie?._fazGetFromStore?.('marketing'),
    }));
    expect(state).toEqual({
      law: 'ccpa',
      action: 'yes',
      marketing: 'yes',
    });
    const stored = (await context.cookies(BASE_URL)).find((cookie) => cookie.name === 'fazcookie-consent');
    expect(decodeURIComponent(stored?.value ?? '')).toContain(`__scope.fp:${livePayload.scopeFingerprint}`);
  } finally {
    releaseLiveRequest();
    await context.close();
  }
});

test('endpoint failure is fail-closed: GDPR UI remains and stale CCPA consent is invalidated', async ({ browser, request }) => {
  const liveResponse = await request.get(`${BASE_URL}/wp-json/faz/v1/banner/en`, { headers: requestHeaders('US', 'CA') });
  const livePayload = await liveResponse.json();
  const staleConsent = [
    'consentid:stale-california',
    'consent:yes',
    'action:yes',
    `__scope.banner:${livePayload.bannerSlug}`,
    '__scope.law:ccpa',
    `__scope.fp:${livePayload.scopeFingerprint}`,
    'necessary:yes',
    'marketing:yes',
    `rev:${consentRevision}`,
  ].join(',');
  const context = await browser.newContext({ baseURL: BASE_URL, extraHTTPHeaders: requestHeaders('US', 'CA') });
  await context.addCookies([{ name: 'fazcookie-consent', value: encodeURIComponent(staleConsent), url: BASE_URL }]);
  const page = await context.newPage();
  await page.route('**/wp-json/faz/v1/banner/**', (route) => route.abort('failed'));
  try {
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => (window as any).fazcookie?._diag?.().geoBootstrapResolved === 'strict-fallback');
    const state = await page.evaluate(() => ({
      law: (window as any).getFazConsent?.().activeLaw,
      slug: (window as any)._fazConfig?._bannerSlug,
      action: (window as any).fazcookie?._fazGetFromStore?.('action'),
      optionalRan: (window as any).__fazBootstrapOptionalRan === true,
      runnableProbe: (() => {
        const probe = document.getElementById('${PROBE_ID}');
        if (!probe) return false;
        const type = (probe.getAttribute('type') || '').toLowerCase();
        return type !== 'text/plain' && type !== 'javascript/blocked';
      })(),
    }));
    expect(state).toEqual({
      law: 'gdpr',
      slug: 'faz-strict-gdpr-shell',
      action: '',
      optionalRan: false,
      runnableProbe: false,
    });
    expect((await context.cookies(BASE_URL)).some((cookie) => cookie.name === 'fazcookie-consent')).toBe(false);
    await expect(page.locator('[data-faz-tag="notice"]').first()).toContainText(STRICT_MARKER);
  } finally {
    await context.close();
  }
});

test('real FlyingPress HIT serves the same strict shell, then California bootstraps live', async ({ browser, request }) => {
  test.skip(!flyingPressAvailable, 'FlyingPress is not installed on this environment');
  try {
    const legacyWasBaked = wpEval(`
    $inject = static function ( $cookies ) {
      $cookies = is_array( $cookies ) ? $cookies : array();
      $cookies[] = 'faz-law';
      return array_values( array_unique( $cookies ) );
    };
    add_filter( 'flying_press_cache_include_cookies', $inject, PHP_INT_MAX );
    \\FlyingPress\\AdvancedCache::add_advanced_cache();
    remove_filter( 'flying_press_cache_include_cookies', $inject, PHP_INT_MAX );
    $file = WP_CONTENT_DIR . ( class_exists( 'Atomic_Persistent_Data' ) ? '/flying-press-advanced-cache.php' : '/advanced-cache.php' );
    $contents = is_readable( $file ) ? file_get_contents( $file ) : '';
    echo false !== strpos( (string) $contents, 'faz-law' ) ? '1' : '0';
  `).trim();
    expect(legacyWasBaked).toBe('1');
    wpEval(`\\FazCookie\\Includes\\CLI::remove_legacy_flyingpress_law_vary();`);

    clearCaches();
    await primeCache(request);

    const caHit = await request.get(pageUrl, { headers: requestHeaders('US', 'CA') });
    const itHit = await request.get(pageUrl, { headers: requestHeaders('IT') });
    expect((caHit.headers()['x-flying-press-cache'] ?? '').toUpperCase()).toBe('HIT');
    expect((itHit.headers()['x-flying-press-cache'] ?? '').toUpperCase()).toBe('HIT');
    const caHtml = await caHit.text();
    const itHtml = await itHit.text();
    expect(caHtml).toBe(itHtml);
    expect(caHtml).toContain('"applicableLaw":"gdpr"');
    expect(caHtml).toContain(STRICT_MARKER);

    const dropinHasLegacyCookie = wpEval(`
      $file = WP_CONTENT_DIR . ( class_exists( 'Atomic_Persistent_Data' ) ? '/flying-press-advanced-cache.php' : '/advanced-cache.php' );
      $contents = is_readable( $file ) ? file_get_contents( $file ) : '';
      echo false !== strpos( (string) $contents, 'faz-law' ) ? '1' : '0';
    `).trim();
    expect(dropinHasLegacyCookie).toBe('0');

    const context = await browser.newContext({ baseURL: BASE_URL, extraHTTPHeaders: requestHeaders('US', 'CA') });
    const page = await context.newPage();
    try {
      const navigation = await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
      expect((navigation?.headers()['x-flying-press-cache'] ?? '').toUpperCase()).toBe('HIT');
      await page.waitForFunction(() => (window as any).fazcookie?._diag?.().geoBootstrapResolved === 'live');
      await page.waitForFunction(() => (window as any).__fazBootstrapOptionalRan === true);
      await expect(page.locator('[data-faz-tag="notice"]').first()).toContainText(LIVE_MARKER);
      expect(await page.evaluate(() => (window as any).getFazConsent?.().activeLaw)).toBe('ccpa');
      expect(await page.evaluate(() => (window as any).__fazBootstrapOptionalRan === true)).toBe(true);
    } finally {
      await context.close();
    }
  } finally {
    // The legacy key lives in a pre-WordPress generated drop-in. Repair it even
    // when any assertion above fails so later specs never inherit fragmentation.
    wpEval(`if ( class_exists( '\\FlyingPress\\AdvancedCache' ) ) { \\FazCookie\\Includes\\CLI::remove_legacy_flyingpress_law_vary(); }`);
  }
});
