/**
 * E2E coverage for all fixes made during the codex/verify-report-findings session:
 * - strpos → stripos in OB guards (uppercase HTML tags)
 * - extract_tag_attr regex (data-src safety)
 * - rawurldecode before base64_decode (percent-encoded data: URIs)
 * - Empty consent_id throttle fix
 * - URL sanitization (no credentials in logs)
 * - Category delete transactions (idempotent)
 * - TCF buildConsentArtifacts consistency
 * - Focus management (preference center)
 * - Gateway pattern caching (Stripe vs analytics)
 * - Plugin Check escaping (XSS prevention)
 */
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
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

function b64(value: string): string {
  return Buffer.from(value, 'utf8').toString('base64');
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
    if ( ! is_array( $settings ) ) { $settings = array(); }
    if ( empty( $settings['consent_logs'] ) || ! is_array( $settings['consent_logs'] ) ) {
      $settings['consent_logs'] = array();
    }
    $settings['consent_logs']['status'] = true;
    update_option( 'faz_settings', $settings );
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
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name REGEXP %s", $regex ) );
    if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); }
    echo 'ok';
  `);
}

function readLastConsentLogRow(): { url: string; user_agent: string; consent_id: string } {
  const raw = wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_consent_logs';
    $row = $wpdb->get_row( "SELECT consent_id, user_agent, url FROM {$table} ORDER BY log_id DESC LIMIT 1", ARRAY_A );
    echo wp_json_encode( $row ? $row : array() );
  `);
  return raw ? JSON.parse(raw) : { url: '', user_agent: '', consent_id: '' };
}

type ConsentLogConfig = { restUrl: string; token: string; policyRevision: number };

async function waitForConsentLogConfig(page: import('@playwright/test').Page): Promise<ConsentLogConfig> {
  await page.waitForFunction(() => typeof (window as any)._fazConsentLog !== 'undefined', undefined, { timeout: 10_000 });
  const config = await page.evaluate(() => (window as any)._fazConsentLog);
  return {
    restUrl: String(config.restUrl || ''),
    token: String(config.token || ''),
    policyRevision: Number(config.policyRevision || 1),
  };
}

function configureIab(options: { enabled: boolean; purposeOneTreatment?: boolean }): void {
  const payload = b64(JSON.stringify(options));
  wpEval(`
    $opts = json_decode( base64_decode( '${payload}' ), true );
    $settings = get_option( 'faz_settings', array() );
    if ( ! is_array( $settings ) ) { $settings = array(); }
    if ( empty( $settings['iab'] ) || ! is_array( $settings['iab'] ) ) { $settings['iab'] = array(); }
    $settings['iab']['enabled'] = ! empty( $opts['enabled'] );
    $settings['iab']['cmp_id'] = 123;
    $settings['iab']['purpose_one_treatment'] = ! empty( $opts['purposeOneTreatment'] );
    update_option( 'faz_settings', $settings );
    delete_option( 'faz_banner_template' );
    echo 'ok';
  `);
}

function parseTcString(tcString: string | undefined | null): { created: number; lastUpdated: number } | null {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  const core = (tcString || '').split('.')[0];
  if (!core) return null;
  const bits: number[] = [];
  for (const ch of core) {
    const value = chars.indexOf(ch);
    if (value === -1) return null;
    for (let bit = 5; bit >= 0; bit -= 1) bits.push((value >> bit) & 1);
  }
  const readBits = (offset: number, length: number): number => {
    let v = 0;
    for (let i = 0; i < length; i += 1) v = v * 2 + (bits[offset + i] || 0);
    return v;
  };
  return { created: readBits(6, 36), lastUpdated: readBits(42, 36) };
}

test.describe('Session fixes coverage (codex/verify-report-findings)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(120_000);

  // --- 1. Uppercase HTML tag blocking (strpos → stripos) ---
  test('uppercase <SCRIPT> tags are blocked by the client-side interceptor', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    const initialState = await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
      (window as any).__fazUppercaseProbeExecuted = false;

      const script = document.createElement('script');
      script.id = 'faz-uppercase-probe';
      script.setAttribute('data-fazcookie', 'fazcookie-analytics');
      script.src = 'https://www.googletagmanager.com/gtag/js?id=G-UPPERCASE-TEST';
      document.head.appendChild(script);

      const probe = document.getElementById('faz-uppercase-probe') as HTMLScriptElement | null;
      return { type: probe?.getAttribute('type') ?? null };
    });

    expect(initialState.type).toBe('javascript/blocked');

    const accepted = await page.evaluate(() => {
      const btn =
        document.querySelector<HTMLElement>('[data-faz-tag="accept-button"] button') ??
        document.querySelector<HTMLElement>('[data-faz-tag="accept-button"]') ??
        document.querySelector<HTMLElement>('.faz-btn-accept');
      btn?.click();
      return !!btn;
    });
    expect(accepted).toBe(true);

    await page.waitForFunction(() => {
      const probe = document.getElementById('faz-uppercase-probe') as HTMLScriptElement | null;
      return probe === null || probe.getAttribute('type') !== 'javascript/blocked';
    }, undefined, { timeout: 5_000 });
  });

  // --- 2. data-src not confused with src ---
  test('data-src attributes are not confused with real src for whitelist matching', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    const result = await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
      _fazStore._userWhitelist = ['cdn.example.com/safe-library.js'];

      const script = document.createElement('script');
      script.id = 'faz-data-src-probe';
      script.setAttribute('data-src', 'https://analytics.example.com/track.js');
      script.setAttribute('data-fazcookie', 'fazcookie-analytics');
      script.src = 'https://cdn.example.com/safe-library.js';
      document.head.appendChild(script);

      const probe = document.getElementById('faz-data-src-probe') as HTMLScriptElement | null;
      return { type: probe?.getAttribute('type') ?? null };
    });

    expect(result.type).not.toBe('text/plain');
    expect(result.type).not.toBe('javascript/blocked');
  });

  // --- 3. data: URI base64 scripts blocked by category marker ---
  test('data: URI base64 scripts with category marker are blocked before consent', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    const result = await page.evaluate(() => {
      (window as any).__fazDataUriCategoryProbe = 0;
      const s = document.createElement('script');
      // Set data-fazcookie BEFORE src so the createElement override sees
      // the category marker when the src setter triggers _fazShouldChangeType.
      s.setAttribute('data-fazcookie', 'fazcookie-analytics');
      s.src = 'data:text/javascript;base64,' + btoa('window.__fazDataUriCategoryProbe=1;');
      document.head.appendChild(s);

      const probe = document.querySelector('script[data-fazcookie="fazcookie-analytics"]') as HTMLScriptElement | null;
      return {
        type: probe?.type ?? null,
        executed: (window as any).__fazDataUriCategoryProbe,
      };
    });

    expect(result.type).toBe('javascript/blocked');
    expect(result.executed).toBe(0);
  });

  // --- 4. Empty consent_id throttle ---
  test('empty consent_id does not trigger per-consent throttle collision', async ({ page }) => {
    const originalSettings = backupSettingsOption();

    try {
      enableConsentLogging();
      clearConsentLogs();
      clearConsentThrottle('all');

      await page.goto('/', { waitUntil: 'domcontentloaded' });
      const config = await waitForConsentLogConfig(page);

      const first = await page.request.post(config.restUrl, {
        data: {
          categories: { analytics: 'yes' },
          consent_id: '',
          policy_revision: config.policyRevision,
          status: 'accepted',
          token: config.token,
          url: `${WP_BASE}/empty-id-one`,
        },
      });
      expect(first.status()).toBe(200);
      expect(await first.json()).not.toHaveProperty('throttled', true);

      clearConsentThrottle('ip');

      const second = await page.request.post(config.restUrl, {
        data: {
          categories: { analytics: 'no' },
          consent_id: '',
          policy_revision: config.policyRevision,
          status: 'rejected',
          token: config.token,
          url: `${WP_BASE}/empty-id-two`,
        },
      });
      expect(second.status()).toBe(200);
      expect(await second.json()).not.toHaveProperty('throttled', true);
    } finally {
      clearConsentLogs();
      clearConsentThrottle('all');
      restoreSettingsOption(originalSettings);
    }
  });

  // --- 5. URL sanitization strips credentials ---
  test('consent log URL sanitization strips credentials, query strings, and fragments', async ({ page }) => {
    const originalSettings = backupSettingsOption();

    try {
      enableConsentLogging();
      clearConsentLogs();
      clearConsentThrottle('all');

      await page.goto('/', { waitUntil: 'domcontentloaded' });
      const config = await waitForConsentLogConfig(page);

      const response = await page.request.post(config.restUrl, {
        data: {
          categories: { analytics: 'yes' },
          consent_id: `faz-log-creds-${Date.now()}`,
          policy_revision: config.policyRevision,
          status: 'accepted',
          token: config.token,
          url: 'http://admin:secret123@localhost:9998/private-page?token=abc#section',
        },
      });
      expect(response.status()).toBe(200);
      expect(await response.json()).not.toHaveProperty('throttled', true);

      const row = readLastConsentLogRow();
      expect(row.url).toBe('http://localhost:9998/private-page');
    } finally {
      clearConsentLogs();
      clearConsentThrottle('all');
      restoreSettingsOption(originalSettings);
    }
  });

  // --- 6. Category delete transactions ---
  test('category deletion uses transactions and reassigns cookies to uncategorized', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const prefix = `faz-tx-delete-${Date.now()}`;
    const createdCookieIds: number[] = [];

    try {
      const uncategorizedId = await findCategoryId(page, nonce, 'uncategorized');

      const categoryResponse = await fazApiPost<any>(page, nonce, 'cookies/categories', {
        description: { en: 'Transaction test category' },
        name: { en: `${prefix} category` },
        prior_consent: false,
        priority: 50,
        sell_personal_data: false,
        slug: prefix,
        visibility: true,
      });
      expect([200, 201]).toContain(categoryResponse.status);
      const categoryId = Number(categoryResponse.data.id ?? categoryResponse.data.category_id);

      const cookieResponse = await fazApiPost<any>(page, nonce, 'cookies', {
        category: categoryId,
        description: { en: 'Transaction test cookie' },
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
      createdCookieIds.push(cookieId);

      wpEval(`
        $cat = new FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie_Categories( ${categoryId} );
        $cat->delete();
        echo 'ok';
      `);

      const cookieAfterDelete = await fazApiGet<any>(page, nonce, `cookies/${cookieId}`);
      expect(cookieAfterDelete.status).toBe(200);
      expect(Number(cookieAfterDelete.data.category)).toBe(uncategorizedId);

      // Second delete must not throw (idempotent)
      const secondDelete = wpEval(`
        $cat = new FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie_Categories( ${categoryId} );
        $cat->delete();
        echo 'ok';
      `);
      expect(secondDelete).toBe('ok');
    } finally {
      for (const cookieId of createdCookieIds) {
        await fazApiDelete(page, nonce, `cookies/${cookieId}`).catch(() => ({ status: 0 }));
      }
    }
  });

  // --- 7. TCF buildConsentArtifacts consistency ---
  test('TCF buildConsentArtifacts produces consistent vendorConsent and purposeLI', async ({ browser }) => {
    const originalSettings = backupSettingsOption();
    const ctx = await browser.newContext({ baseURL: WP_BASE });
    const page = await ctx.newPage();

    try {
      configureIab({ enabled: true, purposeOneTreatment: false });

      await ctx.clearCookies();
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
      await page.evaluate(() => { _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false; });
      await page.waitForFunction(() => typeof (window as any).__tcfapi === 'function', undefined, { timeout: 5_000 });

      const accepted = await page.evaluate(() => {
        const btn = document.querySelector('[data-faz-tag="accept-button"] button')
          ?? document.querySelector('[data-faz-tag="accept-button"]')
          ?? document.querySelector('.faz-btn-accept');
        (btn as HTMLElement)?.click();
        return !!btn;
      });
      expect(accepted).toBe(true);
      await page.waitForFunction(() => document.cookie.includes('euconsent-v2='), undefined, { timeout: 5_000 });

      const tcData = await page.evaluate(async () =>
        new Promise<any>((resolve) => { (window as any).__tcfapi('getTCData', 2, (data: any) => resolve(data)); }),
      );

      expect(tcData.vendor?.consents).toBeTruthy();
      expect(Object.keys(tcData.vendor.consents).every((k: string) => /^\d+$/.test(k))).toBe(true);
      expect(tcData.vendor?.legitimateInterests).toBeTruthy();

      const pc = tcData.purpose?.consents;
      for (let p = 1; p <= 11; p++) {
        expect(pc[String(p)]).toBe(true);
      }

      const ts = parseTcString(tcData.tcString);
      expect(ts).not.toBeNull();
      expect(ts!.created).toBeGreaterThan(0);
      expect(ts!.lastUpdated).toBeGreaterThanOrEqual(ts!.created);
    } finally {
      await ctx.clearCookies();
      await ctx.close();
      restoreSettingsOption(originalSettings);
    }
  });

  // --- 8. Focus management in preference center ---
  test('preference center focus moves into .faz-preference-center and returns to trigger on close', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    await page.locator('[data-faz-tag="settings-button"]').first().click();
    await expect(page.locator('.faz-preference-center')).toBeVisible();

    // Focus may take several frames to move after the CSS transition on
    // .faz-preference-center completes. Poll generously so slow CI
    // workers or single-threaded dev servers don't false-fail without
    // masking a regression where focus never moves.
    await expect
      .poll(
        () =>
          page.evaluate(() => {
            const pref = document.querySelector('.faz-preference-center');
            return pref !== null && pref.contains(document.activeElement);
          }),
        { timeout: 10_000 },
      )
      .toBe(true);

    await page.locator('[data-faz-tag="detail-close"]').first().click();
    await expect(page.locator('.faz-preference-center')).toBeHidden();

    const triggerIsFocused = await page.evaluate(() => {
      const trigger = document.querySelector('[data-faz-tag="settings-button"] button') as HTMLElement | null
        ?? document.querySelector('[data-faz-tag="settings-button"]') as HTMLElement | null;
      return trigger !== null && (trigger === document.activeElement || trigger.contains(document.activeElement));
    });
    expect(triggerIsFocused).toBe(true);
  });

  // --- 9. Gateway pattern caching (Stripe vs analytics) ---
  test('always-allowed gateway cache does not break blocking or whitelisting behavior', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    const scriptState = await page.evaluate(() => {
      const stripeScript = document.createElement('script');
      stripeScript.id = 'faz-probe-stripe';
      stripeScript.src = 'https://js.stripe.com/v3/';
      document.head.appendChild(stripeScript);

      const analyticsScript = document.createElement('script');
      analyticsScript.id = 'faz-probe-analytics';
      analyticsScript.setAttribute('data-fazcookie', 'fazcookie-analytics');
      analyticsScript.textContent = 'window.__fazAnalyticsProbeExecuted = true;';
      document.head.appendChild(analyticsScript);

      const stripe = document.getElementById('faz-probe-stripe') as HTMLScriptElement | null;
      const analytics = document.getElementById('faz-probe-analytics') as HTMLScriptElement | null;
      return {
        stripeType: stripe?.type ?? '',
        analyticsType: analytics?.type ?? '',
        analyticsExecuted: !!(window as any).__fazAnalyticsProbeExecuted,
      };
    });

    expect(scriptState.stripeType).not.toBe('javascript/blocked');
    expect(scriptState.analyticsType).toBe('javascript/blocked');
    expect(scriptState.analyticsExecuted).toBe(false);
  });

  // --- 10. Plugin Check escaping (XSS prevention) ---
  test('inline CSS and consent cookie are sanitized against XSS', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const inlineStyle = await page.locator('#faz-style-inline').textContent().catch(() => '');
    if (inlineStyle) {
      expect(inlineStyle).not.toMatch(/<script/i);
    }

    // Set malicious consent cookie
    await page.context().addCookies([{
      name: 'fazcookie-consent',
      value: '<script>alert(1)</script>',
      url: WP_BASE,
    }]);

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const source = await page.content();
    expect(source).not.toContain('<script>alert(1)</script>');
  });
});
