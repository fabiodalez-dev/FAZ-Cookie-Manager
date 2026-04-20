import type { APIResponse, Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import {
  deleteCookiesByPrefix,
  fazApiDelete,
  fazApiGet,
  fazApiPost,
  findCategoryId,
  openCookiesPage,
  openSettingsPage,
} from '../utils/faz-api';
import { clickFirstVisible } from '../utils/ui';
import {
  ensureFixturePlugin,
  ensureProviderMatrixPage,
  ensureWooCommerceLabData,
  readProviderMatrixHits,
  readProviderMatrixUrl,
  resetProviderMatrixState,
  wpEval,
} from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

type ConsentLogConfig = {
  bannerSlug: string;
  policyRevision: number;
  restUrl: string;
  token: string;
};

type TcTimestamps = {
  created: number;
  lastUpdated: number;
};

function b64(value: string): string {
  return Buffer.from(value, 'utf8').toString('base64');
}

function parseJson<T>(raw: string): T {
  return JSON.parse(raw) as T;
}

function parseTcString(tcString: string | undefined | null): TcTimestamps | null {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  const core = (tcString || '').split('.')[0];
  if (!core) {
    return null;
  }

  const bits: number[] = [];
  for (const ch of core) {
    const value = chars.indexOf(ch);
    if (value === -1) {
      return null;
    }
    for (let bit = 5; bit >= 0; bit -= 1) {
      bits.push((value >> bit) & 1);
    }
  }

  const readBits = (offset: number, length: number): number => {
    let value = 0;
    for (let i = 0; i < length; i += 1) {
      value = (value * 2) + (bits[offset + i] || 0);
    }
    return value;
  };

  return {
    created: readBits(6, 36),
    lastUpdated: readBits(42, 36),
  };
}

function backupSettingsOption(): string {
  return b64(wpEval(`echo wp_json_encode( get_option( 'faz_settings', array() ) );`));
}

function restoreSettingsOption(encoded: string): void {
  wpEval(`
    $restored = json_decode( base64_decode( '${encoded}' ), true );
    update_option( 'faz_settings', is_array( $restored ) ? $restored : array() );
    echo 'ok';
  `);
}

function enableConsentLogging(): void {
  wpEval(`
    $settings = get_option( 'faz_settings', array() );
    if ( ! is_array( $settings ) ) {
      $settings = array();
    }
    if ( empty( $settings['consent_logs'] ) || ! is_array( $settings['consent_logs'] ) ) {
      $settings['consent_logs'] = array();
    }
    $settings['consent_logs']['status'] = true;
    update_option( 'faz_settings', $settings );
    echo 'ok';
  `);
}

function configureIab(options: { enabled: boolean; purposeOneTreatment?: boolean }): void {
  const payload = b64(JSON.stringify(options));
  wpEval(`
    $opts = json_decode( base64_decode( '${payload}' ), true );
    $settings = get_option( 'faz_settings', array() );
    if ( ! is_array( $settings ) ) {
      $settings = array();
    }
    if ( empty( $settings['iab'] ) || ! is_array( $settings['iab'] ) ) {
      $settings['iab'] = array();
    }
    $settings['iab']['enabled'] = ! empty( $opts['enabled'] );
    $settings['iab']['cmp_id'] = 123;
    $settings['iab']['purpose_one_treatment'] = ! empty( $opts['purposeOneTreatment'] );
    update_option( 'faz_settings', $settings );
    delete_option( 'faz_banner_template' );
    echo 'ok';
  `);
}

function backupDefaultBannerSettings(): string {
  return wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_banners';
    $settings = $wpdb->get_var( "SELECT settings FROM {$table} WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1" );
    echo base64_encode( (string) $settings );
  `);
}

function restoreDefaultBannerSettings(encoded: string): void {
  wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_banners';
    $row = $wpdb->get_row( "SELECT banner_id FROM {$table} WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1" );
    if ( $row ) {
      $wpdb->update(
        $table,
        array( 'settings' => base64_decode( '${encoded}' ) ),
        array( 'banner_id' => (int) $row->banner_id ),
        array( '%s' ),
        array( '%d' )
      );
    }
    delete_option( 'faz_banner_template' );
    if ( class_exists( 'FazCookie\\\\Admin\\\\Modules\\\\Banners\\\\Includes\\\\Controller' ) ) {
      FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    }
    do_action( 'faz_after_update_banner' );
    echo 'ok';
  `);
}

function setDefaultBannerLayout(layout: { position?: string; preferenceCenterType?: string; type?: string }): void {
  const payload = b64(JSON.stringify(layout));
  wpEval(`
    global $wpdb;
    $layout = json_decode( base64_decode( '${payload}' ), true );
    $table = $wpdb->prefix . 'faz_banners';
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$table} WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1" );
    if ( ! $row ) {
      throw new Exception( 'Default banner not found.' );
    }
    $settings = json_decode( $row->settings, true );
    if ( ! is_array( $settings ) ) {
      $settings = array();
    }
    if ( empty( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) {
      $settings['settings'] = array();
    }
    foreach ( array( 'type', 'position', 'preferenceCenterType' ) as $key ) {
      if ( isset( $layout[ $key ] ) ) {
        $settings['settings'][ $key ] = $layout[ $key ];
      }
    }
    $wpdb->update(
      $table,
      array( 'settings' => wp_json_encode( $settings ) ),
      array( 'banner_id' => (int) $row->banner_id ),
      array( '%s' ),
      array( '%d' )
    );
    delete_option( 'faz_banner_template' );
    if ( class_exists( 'FazCookie\\\\Admin\\\\Modules\\\\Banners\\\\Includes\\\\Controller' ) ) {
      FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    }
    do_action( 'faz_after_update_banner' );
    echo 'ok';
  `);
}

function clearConsentLogs(): void {
  wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_consent_logs';
    $wpdb->query( "DELETE FROM {$table}" );
    echo 'ok';
  `);
}

function clearConsentThrottle(scope: 'all' | 'consent' | 'ip' = 'all'): void {
  const regex =
    scope === 'ip'
      ? '^_transient(_timeout)?_faz_consent_ip_'
      : scope === 'consent'
        ? '^_transient(_timeout)?_faz_consent_[0-9a-f]{8}_'
        : '^_transient(_timeout)?_faz_consent';

  wpEval(`
    global $wpdb;
    $regex = base64_decode( '${b64(regex)}' );
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name REGEXP %s",
        $regex
      )
    );
    if ( function_exists( 'wp_cache_flush' ) ) {
      wp_cache_flush();
    }
    echo 'ok';
  `);
}

function readLastConsentLogRow(): {
  banner_slug: string;
  consent_id: string;
  policy_revision: number;
  url: string;
  user_agent: string;
} {
  const raw = wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_consent_logs';
    $row = $wpdb->get_row(
      "SELECT consent_id, user_agent, url, banner_slug, policy_revision
       FROM {$table}
       ORDER BY log_id DESC
       LIMIT 1",
      ARRAY_A
    );
    echo wp_json_encode( $row ? $row : array() );
  `);

  return raw
    ? parseJson<{
        banner_slug: string;
        consent_id: string;
        policy_revision: number;
        url: string;
        user_agent: string;
      }>(raw)
    : {
        banner_slug: '',
        consent_id: '',
        policy_revision: 0,
        url: '',
        user_agent: '',
      };
}

function computeExpectedUserAgentHash(userAgent: string): string {
  return wpEval(`
    echo hash( 'sha256', ${JSON.stringify(userAgent)} . wp_salt( 'auth' ) );
  `);
}

async function acceptAll(page: Page): Promise<void> {
  const accepted = await clickFirstVisible(page, [
    '[data-faz-tag="accept-button"] button',
    '[data-faz-tag="accept-button"]',
    '.faz-btn-accept',
    '[data-faz-tag="detail-accept-button"] button',
    '[data-faz-tag="detail-accept-button"]',
  ]);
  expect(accepted).toBeTruthy();
}

async function rejectAll(page: Page): Promise<void> {
  const rejected = await clickFirstVisible(page, [
    '[data-faz-tag="reject-button"] button',
    '[data-faz-tag="reject-button"]',
    '.faz-btn-reject',
    '[data-faz-tag="detail-reject-button"] button',
    '[data-faz-tag="detail-reject-button"]',
    '[data-faz-tag="close-button"]',
  ]);
  expect(rejected).toBeTruthy();
}

async function openPreferenceCenter(page: Page): Promise<void> {
  const opened = await clickFirstVisible(page, [
    '[data-faz-tag="settings-button"] button',
    '[data-faz-tag="settings-button"]',
    '.faz-btn-customize',
  ]);
  expect(opened).toBeTruthy();
}

async function savePreferences(page: Page): Promise<void> {
  const saved = await clickFirstVisible(page, [
    '[data-faz-tag="detail-save-button"] button',
    '[data-faz-tag="detail-save-button"]',
    '.faz-btn-preferences',
  ]);
  expect(saved).toBeTruthy();
}

async function closePreferenceCenter(page: Page): Promise<void> {
  const closed = await clickFirstVisible(page, [
    '[data-faz-tag="detail-close"] button',
    '[data-faz-tag="detail-close"]',
    '[data-faz-tag="optout-close"]',
  ]);
  expect(closed).toBeTruthy();
}

async function setCategoryToggle(page: Page, slug: string, checked: boolean): Promise<void> {
  const switchToggle = page.locator(`#fazSwitch${slug}`);
  const directToggle = page.locator(`#fazCategoryDirect${slug}`);
  const toggle = (await switchToggle.count()) > 0 ? switchToggle : directToggle;
  await expect(toggle).toHaveCount(1);
  await toggle.evaluate((element, value) => {
    const input = element as HTMLInputElement;
    input.checked = value;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, checked);
}

async function waitForConsentLogConfig(page: Page): Promise<ConsentLogConfig> {
  await page.waitForFunction(() => typeof (window as any)._fazConsentLog !== 'undefined', undefined, { timeout: 10_000 });
  const config = await page.evaluate(() => (window as any)._fazConsentLog);
  expect(config).toBeTruthy();
  return {
    bannerSlug: String(config.bannerSlug || ''),
    policyRevision: Number(config.policyRevision || 1),
    restUrl: String(config.restUrl || ''),
    token: String(config.token || ''),
  };
}

async function saveBannerFromAdmin(page: Page): Promise<void> {
  const responsePromise = page.waitForResponse(
    (response) =>
      response.url().includes('banners') &&
      !response.url().includes('preview') &&
      (response.request().method() === 'PUT' || response.request().method() === 'POST'),
    { timeout: 30_000 },
  );

  await page.click('#faz-b-save');
  const response = await responsePromise;
  expect(response.status()).toBe(200);
}

function readHeaderNumber(response: APIResponse, name: string): number {
  const value = response.headers()[name.toLowerCase()];
  if (!value) {
    throw new Error(`Missing response header "${name}".`);
  }
  return Number(value);
}

function readAuditProbe(key: string): { cookie_queries: number; settings_reads: number } {
  const raw = wpEval(`
    $probe = get_option( 'faz_e2e_audit_probe_${key}', array() );
    echo wp_json_encode( is_array( $probe ) ? $probe : array() );
  `);

  const parsed = raw
    ? parseJson<{ cookie_queries?: number; settings_reads?: number }>(raw)
    : {};

  return {
    cookie_queries: Number(parsed.cookie_queries || 0),
    settings_reads: Number(parsed.settings_reads || 0),
  };
}

test.describe('PR audit regressions (2026-04-19)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(240_000);

  let providerMatrixUrl = '';

  test.beforeAll(async () => {
    ensureFixturePlugin('faz-e2e-audit-lab');
    ensureFixturePlugin('faz-e2e-provider-matrix');
    ensureFixturePlugin('faz-e2e-woo-lab');
    ensureProviderMatrixPage();
    providerMatrixUrl = readProviderMatrixUrl();
    resetProviderMatrixState();
    ensureWooCommerceLabData();
  });

  test('data: URI scripts are blocked when the decoded payload matches a provider signature', async ({ page }) => {
    await page.goto('/?faz_audit_case=data-uri-provider', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    const beforeConsent = await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
      (window as any).__fazAuditDataUriProviderHit = 0;

      const payload = btoa(
        '/* connect.facebook.net/en_US/fbevents.js */ window.__fazAuditDataUriProviderHit=(window.__fazAuditDataUriProviderHit||0)+1;'
      );

      const script = document.createElement('script');
      script.id = 'faz-audit-data-uri-provider';
      script.src = `data:text/javascript;base64,${payload}`;
      document.head.appendChild(script);

      const probe = document.getElementById('faz-audit-data-uri-provider');
      return {
        executed: (window as any).__fazAuditDataUriProviderHit || 0,
        type: probe?.getAttribute('type') || '',
      };
    });

    expect(beforeConsent.executed).toBe(0);
    expect(beforeConsent.type).toBe('javascript/blocked');

    await acceptAll(page);
    await page.waitForFunction(() => (window as any).__fazAuditDataUriProviderHit === 1, undefined, { timeout: 10_000 });
  });

  test('Stripe remains always-allowed before consent even on non-checkout pages', async ({ page }) => {
    resetProviderMatrixState();

    await page.goto(providerMatrixUrl, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    await expect
      .poll(() => readProviderMatrixHits().stripe ?? 0, {
        timeout: 10_000,
        message: 'Stripe provider matrix hit should be recorded before consent on a non-checkout page.',
      })
      .toBeGreaterThan(0);

    await expect(page.locator('script[type="text/plain"][src*="js.stripe.com"]')).toHaveCount(0);
  });

  test('WooCommerce wc-ajax responses stay valid JSON while the frontend output buffer is active', async ({ page }) => {
    const response = await page.request.get(`${WP_BASE}/?wc-ajax=get_refreshed_fragments`, {
      headers: {
        Accept: 'application/json',
      },
    });

    expect(response.status()).toBe(200);
    expect(response.headers()['content-type'] || '').toContain('application/json');

    const raw = await response.text();
    expect(raw.trim().startsWith('{')).toBe(true);
    expect(raw).not.toContain('text/plain');
    expect(raw).not.toContain('faz-cookie-manager');

    const parsed = JSON.parse(raw) as { cart_hash?: string; fragments?: Record<string, string> };
    expect(parsed).toHaveProperty('fragments');
    expect(typeof parsed.cart_hash).toBe('string');
  });

  test('frontend cookie metadata is sanitized before being localized to JavaScript', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const analyticsId = await findCategoryId(page, nonce, 'analytics');
    const prefix = `faz-audit-localize-${Date.now()}`;

    try {
      const createResponse = await fazApiPost<any>(page, nonce, 'cookies', {
        category: analyticsId,
        description: { en: 'Audit cookie' },
        discovered: false,
        domain: `</script><svg>${prefix}.example.com`,
        duration: { en: '1 year' },
        name: `${prefix}</script><script>window.__fazLocalizedXss=1</script>`,
        slug: prefix,
        type: 0,
        url_pattern: `</script><img src=x onerror=window.__fazLocalizedXss=1>//${prefix}`,
      });

      expect([200, 201]).toContain(createResponse.status);

      const visitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const visitorPage = await visitor.newPage();
        await visitorPage.goto(`/?faz_audit_case=localize-${prefix}`, { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.locator('[data-faz-tag="notice"]')).toBeVisible();

        const localizedCookie = await visitorPage.evaluate((needle) => {
          const categories = (window as any)._fazConfig?._categories ?? [];
          for (const category of categories) {
            const cookies = Array.isArray(category.cookies) ? category.cookies : [];
            for (const cookie of cookies) {
              if (String(cookie.cookieID || '').includes(needle)) {
                return cookie;
              }
            }
          }
          return null;
        }, prefix);

        expect(localizedCookie).toBeTruthy();
        expect(String(localizedCookie.cookieID)).not.toMatch(/[<>]/);
        expect(String(localizedCookie.domain)).not.toMatch(/[<>]/);
        expect(String(localizedCookie.provider)).not.toMatch(/[<>]/);

        const injected = await visitorPage.evaluate(() => (window as any).__fazLocalizedXss || 0);
        expect(injected).toBe(0);
      } finally {
        await visitor.close();
      }
    } finally {
      await deleteCookiesByPrefix(page, nonce, prefix);
    }
  });

  test('focus trapping includes non-button form controls for both banner and classic pushdown layouts', async ({ browser }) => {
    const originalBannerSettings = backupDefaultBannerSettings();

    try {
      setDefaultBannerLayout({ position: 'bottom-left', preferenceCenterType: 'popup', type: 'box' });

      const boxVisitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const page = await boxVisitor.newPage();
        await page.goto('/?faz_audit_case=focus-box', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

        const bannerState = await page.evaluate(() => {
          const notice = document.querySelector('[data-faz-tag="notice"]');
          if (!notice) {
            return null;
          }

          const selector =
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([disabled]):not([tabindex="-1"])';

          const input = document.createElement('input');
          input.id = 'faz-audit-box-input';

          const select = document.createElement('select');
          select.id = 'faz-audit-box-select';
          select.innerHTML = '<option>One</option><option>Two</option>';

          const details = document.createElement('details');
          const summary = document.createElement('summary');
          summary.id = 'faz-audit-box-summary';
          summary.textContent = 'Audit summary';
          details.appendChild(summary);

          notice.prepend(input);
          notice.appendChild(select);
          notice.appendChild(details);

          _fazLoopFocus();

          return {
            firstTag: _fazGetFocusableElements('notice')[0]?.tagName || '',
            lastTag: _fazGetFocusableElements('notice')[1]?.tagName || '',
            tags: Array.from(notice.querySelectorAll(selector)).map((element) => element.tagName),
          };
        });

        expect(bannerState.firstTag).toBe('INPUT');
        expect(bannerState.lastTag).toBe('SUMMARY');
        expect(bannerState.tags).toEqual(expect.arrayContaining(['INPUT', 'SELECT', 'SUMMARY']));

        await page.locator('#faz-audit-box-summary').focus();
        await page.locator('#faz-audit-box-summary').evaluate((element) => {
          element.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'Tab' }));
        });
        await expect(page.locator('#faz-audit-box-input')).toBeFocused();
      } finally {
        await boxVisitor.close();
      }

      setDefaultBannerLayout({ position: 'top', preferenceCenterType: 'pushdown', type: 'classic' });

      const classicVisitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const page = await classicVisitor.newPage();
        await page.goto('/?faz_audit_case=focus-classic', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

        await openPreferenceCenter(page);

        const classicState = await page.evaluate(() => {
          const detail = document.querySelector('[data-faz-tag="detail"]') || document.querySelector('.faz-preference-center');
          if (!detail) {
            return null;
          }

          const selector =
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([disabled]):not([tabindex="-1"])';

          const textarea = document.createElement('textarea');
          textarea.id = 'faz-audit-classic-textarea';

          const details = document.createElement('details');
          const summary = document.createElement('summary');
          summary.id = 'faz-audit-classic-summary';
          summary.textContent = 'Audit summary';
          details.appendChild(summary);

          detail.prepend(textarea);
          detail.appendChild(details);

          _fazLoopFocus();

          return {
            firstTag: _fazGetFocusableElements('detail')[0]?.tagName || '',
            lastTag: _fazGetFocusableElements('detail')[1]?.tagName || '',
            tags: Array.from(detail.querySelectorAll(selector)).map((element) => element.tagName),
          };
        });

        expect(classicState.firstTag).toBe('TEXTAREA');
        expect(classicState.lastTag).toBe('SUMMARY');
        expect(classicState.tags).toEqual(expect.arrayContaining(['TEXTAREA', 'SUMMARY']));

        await page.locator('#faz-audit-classic-summary').focus();
        await page.locator('#faz-audit-classic-summary').evaluate((element) => {
          element.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'Tab' }));
        });
        await expect(page.locator('#faz-audit-classic-textarea')).toBeFocused();
      } finally {
        await classicVisitor.close();
      }
    } finally {
      restoreDefaultBannerSettings(originalBannerSettings);
    }
  });

  test('pushdown preference center uses localized aria labels and restores focus to the trigger on close', async ({ browser }) => {
    const originalBannerSettings = backupDefaultBannerSettings();

    try {
      setDefaultBannerLayout({ position: 'top', preferenceCenterType: 'pushdown', type: 'classic' });

      const visitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const page = await visitor.newPage();
        await page.goto('/?faz_audit_case=pushdown-a11y', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

        const settingsButton = page.locator('[data-faz-tag="settings-button"] button, [data-faz-tag="settings-button"]').first();
        await settingsButton.focus();
        await settingsButton.click();

        await expect
          .poll(
            () =>
              page.evaluate(() => {
                const container = document.querySelector('.faz-consent-container.faz-consent-bar-expand');
                const trigger = document.querySelector('[data-faz-tag="settings-button"] button, [data-faz-tag="settings-button"]');
                const active = document.activeElement;
                return !!container && !!active && container.contains(active) && active !== trigger;
              }),
            { timeout: 5_000 },
          )
          .toBe(true);

        const openState = await page.evaluate(() => {
          const prefCenter = document.querySelector('.faz-preference-center');
          const expected = (window as any)._fazConfig?._i18n?.customise_consent_preferences_label || '';
          return {
            ariaLabel: prefCenter?.getAttribute('aria-label') || '',
            expected,
          };
        });

        expect(openState.ariaLabel).toBe(openState.expected);

        await page.evaluate(() => {
          if (typeof (window as any)._fazTogglePreferenceCenter === 'function') {
            (window as any)._fazTogglePreferenceCenter();
            return;
          }
          _fazTogglePreferenceCenter();
        });
        await expect(settingsButton).toBeFocused();
      } finally {
        await visitor.close();
      }
    } finally {
      restoreDefaultBannerSettings(originalBannerSettings);
    }
  });

  test('TCF Purpose 1 is forced off when purposeOneTreatment is enabled', async ({ browser }) => {
    const originalSettings = backupSettingsOption();

    try {
      configureIab({ enabled: true, purposeOneTreatment: true });

      const visitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const page = await visitor.newPage();
        await page.goto('/?faz_audit_case=tcf-purpose-one', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

        await page.evaluate(() => {
          _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
        });

        await acceptAll(page);

        const tcData = await page.evaluate(
          async () =>
            new Promise<any>((resolve) => {
              window.__tcfapi('getTCData', 2, (data: any) => resolve(data));
            }),
        );

        expect(tcData.purposeOneTreatment).toBe(true);
        expect(tcData.purpose?.consents?.['1']).toBe(false);
      } finally {
        await visitor.close();
      }
    } finally {
      restoreSettingsOption(originalSettings);
    }
  });

  test('euconsent-v2 is removed when consent is withdrawn', async ({ browser }) => {
    const originalSettings = backupSettingsOption();

    try {
      configureIab({ enabled: true, purposeOneTreatment: false });

      const visitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const page = await visitor.newPage();
        await page.goto('/?faz_audit_case=tcf-withdraw', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

        await page.evaluate(() => {
          _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
        });

        await acceptAll(page);
        await page.waitForFunction(() => document.cookie.includes('euconsent-v2='), undefined, { timeout: 10_000 });

        await page.evaluate(() => {
          if (typeof window.revisitFazConsent === 'function') {
            window.revisitFazConsent();
          }
          _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
        });
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

        await rejectAll(page);
        await page.waitForFunction(() => !document.cookie.includes('euconsent-v2='), undefined, { timeout: 10_000 });
      } finally {
        await visitor.close();
      }
    } finally {
      restoreSettingsOption(originalSettings);
    }
  });

  test('TCF preserves created timestamp, advances lastUpdated on real changes, and finishes with cmpStatus=loaded', async ({ browser }) => {
    const originalSettings = backupSettingsOption();

    try {
      configureIab({ enabled: true, purposeOneTreatment: false });

      const visitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const page = await visitor.newPage();
        await page.goto('/?faz_audit_case=tcf-timestamps', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

        await page.evaluate(() => {
          _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
        });

        await acceptAll(page);

        const initialTc = await page.evaluate(
          async () =>
            new Promise<any>((resolve) => {
              window.__tcfapi('getTCData', 2, (data: any) => resolve(data));
            }),
        );

        const initialTs = parseTcString(initialTc.tcString);
        expect(initialTs).not.toBeNull();

        await page.waitForTimeout(1200);
        await page.evaluate(() => {
          if (typeof window.revisitFazConsent === 'function') {
            window.revisitFazConsent();
          }
        });
        await openPreferenceCenter(page);
        await setCategoryToggle(page, 'analytics', false);
        await savePreferences(page);
        await page.waitForFunction(() => typeof (window as any).__tcfapi === 'function', undefined, { timeout: 10_000 });

        const updatedTc = await page.evaluate(async () => {
          const ping = await new Promise<any>((resolve) => {
            window.__tcfapi('ping', 2, (data: any) => resolve(data));
          });
          const tcData = await new Promise<any>((resolve) => {
            window.__tcfapi('getTCData', 2, (data: any) => resolve(data));
          });
          return { ping, tcData };
        });

        const updatedTs = parseTcString(updatedTc.tcData.tcString);
        expect(updatedTs).not.toBeNull();
        expect(updatedTc.ping.cmpStatus).toBe('loaded');
        expect(updatedTs?.created).toBe(initialTs?.created);
        expect((updatedTs?.lastUpdated ?? 0) > (initialTs?.lastUpdated ?? 0)).toBe(true);
      } finally {
        await visitor.close();
      }
    } finally {
      restoreSettingsOption(originalSettings);
    }
  });

  test('consent logging enforces both the 10s IP throttle and the 300s consent_id throttle', async ({ page }) => {
    const originalSettings = backupSettingsOption();

    try {
      enableConsentLogging();
      clearConsentLogs();
      clearConsentThrottle('all');

      await page.goto('/?faz_audit_case=consent-throttle', { waitUntil: 'domcontentloaded' });
      const config = await waitForConsentLogConfig(page);

      const first = await page.request.post(config.restUrl, {
        data: {
          categories: { analytics: 'yes' },
          consent_id: 'faz-throttle-a',
          policy_revision: config.policyRevision,
          status: 'accepted',
          token: config.token,
          url: `${WP_BASE}/throttle-one?x=1#frag`,
        },
      });
      expect(first.status()).toBe(200);
      expect(await first.json()).not.toHaveProperty('throttled', true);

      clearConsentThrottle('ip');

      const sameConsentAgain = await page.request.post(config.restUrl, {
        data: {
          categories: { analytics: 'yes' },
          consent_id: 'faz-throttle-a',
          policy_revision: config.policyRevision,
          status: 'accepted',
          token: config.token,
          url: `${WP_BASE}/throttle-two`,
        },
      });
      expect(sameConsentAgain.status()).toBe(200);
      expect(await sameConsentAgain.json()).toEqual({ throttled: true });

      clearConsentThrottle('all');

      const secondConsent = await page.request.post(config.restUrl, {
        data: {
          categories: { analytics: 'yes' },
          consent_id: 'faz-throttle-b',
          policy_revision: config.policyRevision,
          status: 'accepted',
          token: config.token,
          url: `${WP_BASE}/throttle-three`,
        },
      });
      expect(secondConsent.status()).toBe(200);
      expect(await secondConsent.json()).not.toHaveProperty('throttled', true);

      clearConsentThrottle('consent');

      const ipBucketOnly = await page.request.post(config.restUrl, {
        data: {
          categories: { analytics: 'yes' },
          consent_id: 'faz-throttle-c',
          policy_revision: config.policyRevision,
          status: 'accepted',
          token: config.token,
          url: `${WP_BASE}/throttle-four`,
        },
      });
      expect(ipBucketOnly.status()).toBe(200);
      expect(await ipBucketOnly.json()).toEqual({ throttled: true });
    } finally {
      clearConsentLogs();
      clearConsentThrottle('all');
      restoreSettingsOption(originalSettings);
    }
  });

  test('consent logs hash the user agent and store URLs without query strings or fragments', async ({ page }) => {
    const originalSettings = backupSettingsOption();
    const userAgent = 'FAZ E2E Consent Logger/1.0';

    try {
      enableConsentLogging();
      clearConsentLogs();
      clearConsentThrottle('all');

      await page.goto('/?faz_audit_case=consent-hash', { waitUntil: 'domcontentloaded' });
      const config = await waitForConsentLogConfig(page);

      const response = await page.request.post(config.restUrl, {
        headers: {
          'User-Agent': userAgent,
        },
        data: {
          categories: { analytics: 'yes' },
          consent_id: `faz-log-hash-${Date.now()}`,
          policy_revision: config.policyRevision,
          status: 'accepted',
          token: config.token,
          url: `${WP_BASE}/consent-proof?foo=bar#baz`,
        },
      });

      expect(response.status()).toBe(200);
      expect(await response.json()).not.toHaveProperty('throttled', true);

      const row = readLastConsentLogRow();
      expect(row.user_agent).toBe(computeExpectedUserAgentHash(userAgent));
      expect(row.url).toBe(`${WP_BASE}/consent-proof`);
    } finally {
      clearConsentLogs();
      clearConsentThrottle('all');
      restoreSettingsOption(originalSettings);
    }
  });

  test('consent log schema and persisted rows include banner_slug and policy_revision', async ({ page }) => {
    const originalSettings = backupSettingsOption();

    try {
      enableConsentLogging();
      clearConsentLogs();
      clearConsentThrottle('all');

      await page.goto('/?faz_audit_case=consent-schema', { waitUntil: 'domcontentloaded' });
      const config = await waitForConsentLogConfig(page);

      const columnProbe = parseJson<Array<{ Field: string }>>(
        wpEval(`
          global $wpdb;
          $table = $wpdb->prefix . 'faz_consent_logs';
          echo wp_json_encode( $wpdb->get_results( "SHOW COLUMNS FROM {$table} WHERE Field IN ('banner_slug','policy_revision')", ARRAY_A ) );
        `),
      );
      expect(columnProbe.map((item) => item.Field).sort()).toEqual(['banner_slug', 'policy_revision']);

      const response = await page.request.post(config.restUrl, {
        data: {
          banner_slug: config.bannerSlug,
          categories: { analytics: 'yes' },
          consent_id: `faz-log-schema-${Date.now()}`,
          policy_revision: config.policyRevision,
          status: 'accepted',
          token: config.token,
          url: `${WP_BASE}/consent-schema`,
        },
      });

      expect(response.status()).toBe(200);
      expect(await response.json()).not.toHaveProperty('throttled', true);

      const row = readLastConsentLogRow();
      expect(row.banner_slug).toBe(config.bannerSlug);
      expect(Number(row.policy_revision)).toBe(config.policyRevision);
    } finally {
      clearConsentLogs();
      clearConsentThrottle('all');
      restoreSettingsOption(originalSettings);
    }
  });

  test('geolocation trusts CF-IPCountry only through the shared filter and treats GB as EU', async ({ page }) => {
    const withoutTrust = await page.request.get('/?faz_e2e_geo_probe=1&faz_e2e_cf_country=GB');
    expect(withoutTrust.status()).toBe(200);
    expect(await withoutTrust.json()).toEqual({ country: '', is_eu: false });

    const withTrust = await page.request.get(
      '/?faz_e2e_geo_probe=1&faz_e2e_trust_cf=1&faz_e2e_trust_proxy=1&faz_e2e_cf_country=GB&faz_e2e_forwarded_ip=8.8.8.8',
    );
    expect(withTrust.status()).toBe(200);
    expect(await withTrust.json()).toEqual({ country: 'GB', is_eu: true });
  });

  test('banner table indexes are present and deleting a category reassigns cookies instead of leaving orphans', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const prefix = `faz-audit-db-${Date.now()}`;
    const createdCategoryIds: number[] = [];
    const createdCookieIds: number[] = [];

    try {
      const indexNames = parseJson<Array<{ Key_name: string }>>(
        wpEval(`
          global $wpdb;
          $table = $wpdb->prefix . 'faz_banners';
          echo wp_json_encode( $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ) );
        `),
      ).map((row) => row.Key_name);

      expect(indexNames).toContain('slug');
      expect(indexNames).toContain('status');

      const uncategorizedId = await findCategoryId(page, nonce, 'uncategorized');

      const categoryResponse = await fazApiPost<any>(page, nonce, 'cookies/categories', {
        description: { en: 'Audit category' },
        name: { en: `${prefix} category` },
        prior_consent: false,
        priority: 50,
        sell_personal_data: false,
        slug: prefix,
        visibility: true,
      });
      expect([200, 201]).toContain(categoryResponse.status);

      const categoryId = Number(categoryResponse.data.id ?? categoryResponse.data.category_id);
      expect(categoryId).toBeGreaterThan(0);
      createdCategoryIds.push(categoryId);

      const cookieResponse = await fazApiPost<any>(page, nonce, 'cookies', {
        category: categoryId,
        description: { en: 'Audit DB cookie' },
        discovered: false,
        domain: '.example.com',
        duration: { en: '1 year' },
        name: `${prefix}_cookie`,
        slug: `${prefix}_cookie`,
        type: 0,
        url_pattern: `${prefix}.example.com`,
      });
      expect([200, 201]).toContain(cookieResponse.status);

      const cookieId = Number(cookieResponse.data.id ?? cookieResponse.data.cookie_id);
      expect(cookieId).toBeGreaterThan(0);
      createdCookieIds.push(cookieId);

      const directDeleteResult = wpEval(`
        $category = new FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie_Categories( ${categoryId} );
        if ( ! $category->get_id() ) {
          throw new Exception( 'Category not found for direct delete.' );
        }
        $category->delete();
        echo 'ok';
      `);
      expect(directDeleteResult).toBe('ok');
      createdCategoryIds.length = 0;

      const cookieAfterDelete = await fazApiGet<any>(page, nonce, `cookies/${cookieId}`);
      expect(cookieAfterDelete.status).toBe(200);
      expect(Number(cookieAfterDelete.data.category)).toBe(uncategorizedId);
    } finally {
      for (const cookieId of createdCookieIds) {
        await fazApiDelete(page, nonce, `cookies/${cookieId}`).catch(() => ({ status: 0 }));
      }
      for (const categoryId of createdCategoryIds) {
        await fazApiDelete(page, nonce, `cookies/categories/${categoryId}`).catch(() => ({ status: 0 }));
      }
    }
  });

  test('banner admin preserves hidden brand-logo fields across save and reload', async ({ page, loginAsAdmin }) => {
    const originalBannerSettings = backupDefaultBannerSettings();

    try {
      await loginAsAdmin(page);
      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded' });

      await page.waitForFunction(() => {
        const select = document.getElementById('faz-b-type') as HTMLSelectElement | null;
        return !!select && select.value !== '';
      }, undefined, { timeout: 15_000 });

      const logoUrl = `https://example.com/faz-audit-logo-${Date.now()}.png`;

      await page.evaluate((nextUrl) => {
        const toggle = document.querySelector('#faz-b-brandlogo-toggle input[type="checkbox"]') as HTMLInputElement | null;
        if (toggle && !toggle.checked) {
          toggle.checked = true;
          toggle.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const hidden = document.getElementById('faz-b-brandlogo-url') as HTMLInputElement | null;
        if (hidden) {
          hidden.value = nextUrl;
          hidden.dispatchEvent(new Event('input', { bubbles: true }));
          hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }, logoUrl);

      await saveBannerFromAdmin(page);

      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => {
        const select = document.getElementById('faz-b-type') as HTMLSelectElement | null;
        return !!select && select.value !== '';
      }, undefined, { timeout: 15_000 });

      await expect(page.locator('#faz-b-brandlogo-url')).toHaveValue(logoUrl);
    } finally {
      restoreDefaultBannerSettings(originalBannerSettings);
    }
  });

  test('performance probes show faz_settings memoization, no faz_cookies N+1, and a11y.min.js being served', async ({ page, loginAsAdmin }) => {
    const originalSettings = backupSettingsOption();
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const prefix = `faz-audit-perf-${Date.now()}`;
    const categoriesProbeKey = `${prefix}-categories`;
    const frontendProbeKey = `${prefix}-frontend`;
    const createdCategoryIds: number[] = [];

    try {
      wpEval(`
        $settings = get_option( 'faz_settings', array() );
        if ( ! is_array( $settings ) ) {
          $settings = array();
        }
        if ( empty( $settings['banner_control'] ) || ! is_array( $settings['banner_control'] ) ) {
          $settings['banner_control'] = array();
        }
        $settings['banner_control']['alternative_asset_path'] = false;
        update_option( 'faz_settings', $settings );
        echo 'ok';
      `);

      for (let i = 0; i < 3; i += 1) {
        const slug = `${prefix}-${i}`;
        const categoryResponse = await fazApiPost<any>(page, nonce, 'cookies/categories', {
          description: { en: `Perf category ${i}` },
          name: { en: `Perf category ${i}` },
          prior_consent: false,
          priority: 100 + i,
          sell_personal_data: false,
          slug,
          visibility: true,
        });
        expect([200, 201]).toContain(categoryResponse.status);

        const categoryId = Number(categoryResponse.data.id ?? categoryResponse.data.category_id);
        createdCategoryIds.push(categoryId);

        const cookieResponse = await fazApiPost<any>(page, nonce, 'cookies', {
          category: categoryId,
          description: { en: `Perf cookie ${i}` },
          discovered: false,
          domain: '.example.com',
          duration: { en: '1 year' },
          name: `${slug}_cookie`,
          slug: `${slug}_cookie`,
          type: 0,
          url_pattern: `${slug}.example.com`,
        });
        expect([200, 201]).toContain(cookieResponse.status);
      }

      const categoriesResponse = await page.request.get(`/?rest_route=/faz/v1/cookies/categories&faz_e2e_audit_headers=1&faz_e2e_probe_key=${categoriesProbeKey}`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      expect(categoriesResponse.status()).toBe(200);

      const cookieQueryCount = readAuditProbe(categoriesProbeKey).cookie_queries;
      expect(cookieQueryCount).toBeLessThanOrEqual(2);

      const categoriesPayload = (await categoriesResponse.json()) as Array<{ cookie_list?: unknown[]; slug?: string }>;
      const perfCategories = categoriesPayload.filter((item) => String(item.slug || '').startsWith(prefix));
      expect(perfCategories).toHaveLength(3);
      expect(perfCategories.every((item) => Array.isArray(item.cookie_list) && item.cookie_list.length === 1)).toBe(true);

      const frontendResponse = await page.request.get(`/?faz_e2e_audit_headers=1&faz_e2e_probe_key=${frontendProbeKey}&faz_audit_case=perf-front`, {
        headers: {
          Accept: 'text/html',
        },
      });
      expect(frontendResponse.status()).toBe(200);

      const settingsReads = readAuditProbe(frontendProbeKey).settings_reads;
      expect(settingsReads).toBeLessThanOrEqual(2);

      await page.goto('/?faz_audit_case=perf-front-script', { waitUntil: 'domcontentloaded' });
      const a11yScriptLoaded = await page.evaluate(() =>
        Array.from(document.scripts).some((script) => /\/frontend\/js\/a11y\.min\.js(\?|$)/.test(script.src)),
      );
      expect(a11yScriptLoaded).toBe(true);
    } finally {
      await deleteCookiesByPrefix(page, nonce, `${prefix}-`);
      for (const categoryId of createdCategoryIds) {
        await fazApiDelete(page, nonce, `cookies/categories/${categoryId}`).catch(() => ({ status: 0 }));
      }
      restoreSettingsOption(originalSettings);
    }
  });
});
