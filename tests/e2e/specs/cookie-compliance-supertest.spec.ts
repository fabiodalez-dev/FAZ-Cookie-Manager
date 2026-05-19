import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, realpathSync, writeFileSync } from 'node:fs';
import { join, sep } from 'node:path';
import { expect, test } from '../fixtures/wp-fixture';
import {
  activatePlugins,
  deactivatePluginsExcept,
  listActivePluginFiles,
  restoreActivePluginFiles,
  upsertPage,
  wp,
  WP_PATH,
  wpEval,
} from '../utils/wp-env';

type ConsentMap = Record<string, string>;

const PLUGIN_SLUG = 'faz-cookie-manager';
const COMPLIANCE_PAGE_SLUG = 'faz-cookie-compliance-supertest';
const COMPLIANCE_COOKIE = '_faz_compliance_analytics';
const COMPLIANCE_SCRIPT = 'faz-e2e-compliance-inline-provider.js';
const COMPLIANCE_RULE_PATTERN = COMPLIANCE_SCRIPT;

let initialActivePluginFiles: string[] = [];
let originalSettings = '';
let originalBannerRow = '';
let complianceUrl = '';
let complianceScriptUrl = '';

function b64(value: string): string {
  return Buffer.from(value, 'utf8').toString('base64');
}

function fromB64(value: string): string {
  return Buffer.from(value, 'base64').toString('utf8');
}

function installLocalPlugin(): void {
  if (!WP_PATH) {
    throw new Error(
      'WP_PATH is required for cookie-compliance-supertest. Example: ' +
      'WP_PATH=/path/to/wordpress WP_BASE_URL=http://127.0.0.1:9998 ' +
      'npm run test:e2e -- tests/e2e/specs/cookie-compliance-supertest.spec.ts',
    );
  }

  const source = realpathSync(process.env.FAZ_PLUGIN_SOURCE_PATH ?? process.cwd());
  const target = process.env.FAZ_PLUGIN_DEPLOY_PATH
    ?? join(WP_PATH, 'wp-content', 'plugins', PLUGIN_SLUG);
  const targetReal = existsSync(target) ? realpathSync(target) : target;

  if (targetReal !== source) {
    if (targetReal.startsWith(`${source}${sep}`)) {
      throw new Error(`Refusing to rsync into a child of the source tree: ${targetReal}`);
    }
    mkdirSync(target, { recursive: true });
    execFileSync('rsync', [
      '-a',
      '--delete',
      '--exclude=.git',
      '--exclude=node_modules',
      '--exclude=test-results',
      '--exclude=tests/e2e/reports',
      '--exclude=graphify-out',
      `${source}/`,
      `${target}/`,
    ], { stdio: ['ignore', 'ignore', 'pipe'], timeout: 60_000 });
  }

  wp(['plugin', 'activate', PLUGIN_SLUG]);
}

function snapshotState(): void {
  originalSettings = b64(wpEval(`echo wp_json_encode( get_option( 'faz_settings', array() ) );`));
  originalBannerRow = b64(wpEval(`
    global $wpdb;
    $row = $wpdb->get_row(
      "SELECT banner_id, status, settings, contents FROM {$wpdb->prefix}faz_banners WHERE status = 1 ORDER BY banner_default DESC, banner_id ASC LIMIT 1",
      ARRAY_A
    );
    echo wp_json_encode( $row ? $row : array() );
  `));
}

function restoreState(): void {
  if (originalSettings) {
    const settingsJson = JSON.stringify(fromB64(originalSettings));
    wpEval(`
      $settings = json_decode( ${settingsJson}, true );
      if ( is_array( $settings ) ) {
        update_option( 'faz_settings', $settings, false );
      }
      if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
        \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
      }
    `);
  }

  if (originalBannerRow) {
    const rowJson = JSON.stringify(fromB64(originalBannerRow));
    wpEval(`
      global $wpdb;
      $row = json_decode( ${rowJson}, true );
      if ( is_array( $row ) && ! empty( $row['banner_id'] ) ) {
        $wpdb->update(
          $wpdb->prefix . 'faz_banners',
          array(
            'status'   => absint( $row['status'] ?? 1 ),
            'settings' => (string) ( $row['settings'] ?? '' ),
            'contents' => (string) ( $row['contents'] ?? '' ),
          ),
          array( 'banner_id' => absint( $row['banner_id'] ) ),
          array( '%d', '%s', '%s' ),
          array( '%d' )
        );
      }
      if ( class_exists( '\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller' ) ) {
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      }
    `);
  }
}

function configureStrictCookieCompliance(): void {
  wpEval(`
    global $wpdb;

    $settings = get_option( 'faz_settings', array() );
    if ( ! is_array( $settings ) ) {
      $settings = array();
    }

    $settings['languages'] = array(
      'selected' => array( 'en' ),
      'default'  => 'en',
    );
    $settings['banner_control'] = array_merge(
      isset( $settings['banner_control'] ) && is_array( $settings['banner_control'] ) ? $settings['banner_control'] : array(),
      array(
        'status'                 => true,
        'hide_from_bots'         => false,
        'gtm_datalayer'          => false,
        'alternative_asset_path' => false,
        'per_service_consent'    => false,
        'subdomain_sharing'      => false,
        'excluded_pages'         => array(),
      )
    );
    $settings['pageview_tracking'] = false;
    $settings['consent_forwarding'] = array(
      'enabled'        => false,
      'target_domains' => array(),
    );
    $settings['age_gate'] = array(
      'enabled' => false,
      'min_age' => 16,
    );
    $settings['iab'] = array_merge(
      isset( $settings['iab'] ) && is_array( $settings['iab'] ) ? $settings['iab'] : array(),
      array( 'enabled' => false )
    );
    $settings['microsoft'] = array(
      'uet_consent_mode' => false,
      'clarity_consent'  => false,
    );
    $settings['consent_logs'] = array(
      'status'    => true,
      'retention' => 12,
    );
    $settings['script_blocking'] = array_merge(
      isset( $settings['script_blocking'] ) && is_array( $settings['script_blocking'] ) ? $settings['script_blocking'] : array(),
      array(
        'excluded_pages'     => array(),
        'whitelist_patterns' => array(),
        'custom_rules'       => array(
          array(
            'pattern'  => '${COMPLIANCE_RULE_PATTERN}',
            'category' => 'analytics',
          ),
        ),
      )
    );
    if ( empty( $settings['general'] ) || ! is_array( $settings['general'] ) ) {
      $settings['general'] = array();
    }
    $settings['general']['consent_revision'] = max( 1, absint( $settings['general']['consent_revision'] ?? 1 ) );
    update_option( 'faz_settings', $settings, false );

    $banner = $wpdb->get_row(
      "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE status = 1 ORDER BY banner_default DESC, banner_id ASC LIMIT 1",
      ARRAY_A
    );
    if ( ! $banner ) {
      $banner = $wpdb->get_row(
        "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners ORDER BY banner_default DESC, banner_id ASC LIMIT 1",
        ARRAY_A
      );
      if ( $banner ) {
        $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'status' => 1 ), array( 'banner_id' => absint( $banner['banner_id'] ) ), array( '%d' ), array( '%d' ) );
      }
    }
    if ( $banner ) {
      $banner_settings = json_decode( (string) $banner['settings'], true );
      if ( ! is_array( $banner_settings ) ) {
        $banner_settings = array();
      }
      if ( empty( $banner_settings['settings'] ) || ! is_array( $banner_settings['settings'] ) ) {
        $banner_settings['settings'] = array();
      }
      $banner_settings['settings']['applicableLaw'] = 'gdpr';
      $banner_settings['settings']['consentExpiry'] = array(
        'status' => true,
        'value'  => 180,
      );
      $wpdb->update(
        $wpdb->prefix . 'faz_banners',
        array( 'settings' => wp_json_encode( $banner_settings ) ),
        array( 'banner_id' => absint( $banner['banner_id'] ) ),
        array( '%s' ),
        array( '%d' )
      );
    }

    delete_transient( 'faz_cookie_scripts_map' );
    if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'banners' );
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'cookies' );
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'categories' );
    }
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
      faz_clear_banner_template_cache();
    }
  `);
}

function createComplianceFixture(): void {
  const uploadDir = JSON.parse(wpEval(`
    $u = wp_upload_dir();
    echo wp_json_encode( array( 'basedir' => $u['basedir'], 'baseurl' => $u['baseurl'] ) );
  `)) as { basedir: string; baseurl: string };
  mkdirSync(uploadDir.basedir, { recursive: true });
  writeFileSync(
    join(uploadDir.basedir, COMPLIANCE_SCRIPT),
    `window.__fazComplianceAnalyticsLoaded = (window.__fazComplianceAnalyticsLoaded || 0) + 1;\ndocument.cookie = '${COMPLIANCE_COOKIE}=1;path=/;SameSite=Lax';\n`,
    'utf8',
  );
  complianceScriptUrl = `${uploadDir.baseurl.replace(/\/$/, '')}/${COMPLIANCE_SCRIPT}`;

  upsertPage(
    COMPLIANCE_PAGE_SLUG,
    'FAZ Cookie Compliance Supertest',
    [
      '<h1>FAZ Cookie Compliance Supertest</h1>',
      '<p>This page contains one analytics-classified local script.</p>',
    ].join('\n'),
  );
  complianceUrl = new URL(`/${COMPLIANCE_PAGE_SLUG}/`, process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998').toString();
}

function parseConsentCookie(raw: string): ConsentMap {
  const decoded = decodeURIComponent(raw);
  const result: ConsentMap = {};
  for (const item of decoded.split(',')) {
    const [key, ...rest] = item.split(':');
    if (key) {
      result[key] = rest.join(':');
    }
  }
  return result;
}

async function clickConsent(page: import('@playwright/test').Page, kind: 'accept' | 'reject'): Promise<void> {
  const selectors = kind === 'accept'
    ? ['[data-faz-tag="accept-button"] button', '[data-faz-tag="accept-button"]', '.faz-btn-accept']
    : ['[data-faz-tag="reject-button"] button', '[data-faz-tag="reject-button"]', '.faz-btn-reject'];
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    if (await locator.isVisible().catch(() => false)) {
      await locator.click();
      return;
    }
  }
  throw new Error(`Unable to click ${kind} consent button.`);
}

async function injectComplianceScript(page: import('@playwright/test').Page): Promise<{
  cookieSet: boolean;
  loaded: number;
  type: string | null;
}> {
  return page.evaluate(async ({ cookieName, src }) => {
    const script = document.createElement('script');
    script.src = `${src}?t=${Date.now()}`;
    document.head.appendChild(script);
    await new Promise((resolve) => window.setTimeout(resolve, 250));
    return {
      cookieSet: document.cookie.includes(`${cookieName}=`),
      loaded: (window as unknown as { __fazComplianceAnalyticsLoaded?: number }).__fazComplianceAnalyticsLoaded ?? 0,
      type: script.getAttribute('type'),
    };
  }, { cookieName: COMPLIANCE_COOKIE, src: complianceScriptUrl });
}

test.describe.serial('Cookie compliance supertest', () => {
  test.setTimeout(180_000);

  test.beforeAll(() => {
    initialActivePluginFiles = listActivePluginFiles();
    installLocalPlugin();
    deactivatePluginsExcept([PLUGIN_SLUG]);
    activatePlugins([PLUGIN_SLUG]);
    snapshotState();
    configureStrictCookieCompliance();
    createComplianceFixture();
  });

  test.afterAll(() => {
    restoreState();
    restoreActivePluginFiles(initialActivePluginFiles);
  });

  test('GDPR cookie rules hold across first visit, reject, accept, and consent revision', async ({
    browser,
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await page.evaluate(() => (window as unknown as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '');
    expect(nonce.length).toBeGreaterThan(0);

    const settingsResponse = await page.request.get(`${wpBaseURL}/?rest_route=/faz/v1/settings/`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(settingsResponse.status()).toBe(200);
    const settings = await settingsResponse.json() as Record<string, any>;
    expect(settings.banner_control.status).toBe(true);
    expect(settings.pageview_tracking).toBe(false);
    expect(settings.consent_forwarding.enabled).toBe(false);
    expect(settings.iab.enabled).toBe(false);
    expect(settings.script_blocking.custom_rules).toContainEqual({
      pattern: COMPLIANCE_RULE_PATTERN,
      category: 'analytics',
    });

    const firstContext = await browser.newContext({ baseURL: wpBaseURL, locale: 'en-US' });
    const firstPage = await firstContext.newPage();
    await firstPage.goto(complianceUrl, { waitUntil: 'domcontentloaded' });
    await expect(firstPage).toHaveURL(new RegExp(`${COMPLIANCE_PAGE_SLUG}/?$`));

    await expect(firstPage.locator('[data-faz-tag="notice"]')).toBeVisible();
    await expect(firstPage.locator('[data-faz-tag="reject-button"]')).toBeVisible();
    await expect(firstPage.locator('[data-faz-tag="close-button"]')).toHaveCount(0);

    const firstConfig = await firstPage.evaluate(() => {
      const cfg = (window as unknown as { _fazConfig?: any })._fazConfig;
      return {
        consentExpiry: cfg?._expiry,
        law: cfg?._bannerConfig?.settings?.applicableLaw,
        pageviewConfigType: typeof (window as unknown as { _fazPageviewConfig?: unknown })._fazPageviewConfig,
        loaded: (window as unknown as { __fazComplianceAnalyticsLoaded?: number }).__fazComplianceAnalyticsLoaded ?? 0,
      };
    });
    expect(firstConfig.law).toBe('gdpr');
    expect(Number(firstConfig.consentExpiry)).toBeLessThanOrEqual(180);
    expect(firstConfig.pageviewConfigType).toBe('undefined');
    expect(firstConfig.loaded).toBe(0);

    const blockedProbe = await injectComplianceScript(firstPage);
    expect(blockedProbe.type).toBe('javascript/blocked');
    expect(blockedProbe.loaded).toBe(0);
    expect(blockedProbe.cookieSet).toBe(false);

    let cookies = await firstContext.cookies(wpBaseURL);
    expect(cookies.find((cookie) => cookie.name === 'fazcookie-consent')).toBeUndefined();
    expect(cookies.find((cookie) => cookie.name === COMPLIANCE_COOKIE)).toBeUndefined();

    await clickConsent(firstPage, 'reject');
    await firstPage.waitForFunction(() => document.cookie.includes('fazcookie-consent='));
    cookies = await firstContext.cookies(wpBaseURL);
    const rejected = parseConsentCookie(cookies.find((cookie) => cookie.name === 'fazcookie-consent')?.value ?? '');
    expect(rejected.action).toBe('yes');
    expect(rejected.consent).toBe('no');
    expect(rejected.necessary).toBe('yes');
    expect(rejected.analytics).toBe('no');
    expect(rejected.marketing).toBe('no');
    expect(cookies.find((cookie) => cookie.name === COMPLIANCE_COOKIE)).toBeUndefined();
    await firstContext.close();

    const acceptContext = await browser.newContext({ baseURL: wpBaseURL, locale: 'en-US' });
    const acceptPage = await acceptContext.newPage();
    await acceptPage.goto(complianceUrl, { waitUntil: 'domcontentloaded' });
    await clickConsent(acceptPage, 'accept');
    await injectComplianceScript(acceptPage);
    await acceptPage.waitForFunction((name) => document.cookie.includes(`${name}=`), COMPLIANCE_COOKIE);

    cookies = await acceptContext.cookies(wpBaseURL);
    const accepted = parseConsentCookie(cookies.find((cookie) => cookie.name === 'fazcookie-consent')?.value ?? '');
    expect(accepted.action).toBe('yes');
    expect(accepted.consent).toBe('yes');
    expect(accepted.necessary).toBe('yes');
    expect(accepted.analytics).toBe('yes');
    expect(accepted.marketing).toBe('yes');
    expect(cookies.find((cookie) => cookie.name === COMPLIANCE_COOKIE)?.value).toBe('1');

    wpEval(`
      $settings = get_option( 'faz_settings', array() );
      if ( empty( $settings['general'] ) || ! is_array( $settings['general'] ) ) {
        $settings['general'] = array();
      }
      $settings['general']['consent_revision'] = max( 1, absint( $settings['general']['consent_revision'] ?? 1 ) ) + 1;
      update_option( 'faz_settings', $settings, false );
      if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
        \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
      }
    `);
    await acceptPage.reload({ waitUntil: 'domcontentloaded' });
    await expect(acceptPage.locator('[data-faz-tag="notice"]')).toBeVisible();
    cookies = await acceptContext.cookies(wpBaseURL);
    expect(cookies.find((cookie) => cookie.name === 'fazcookie-consent')?.value ?? '').not.toContain(accepted.rev ?? '');
    await acceptContext.close();
  });
});
