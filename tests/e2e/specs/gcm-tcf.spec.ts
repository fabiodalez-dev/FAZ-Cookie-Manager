import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { clickFirstVisible } from '../utils/ui';
import { wpEval } from '../utils/wp-env';

type GcmLayerEntry = [string, unknown?, unknown?];
type GcmScenario = {
  name: string;
  choose: (page: Page) => Promise<void>;
  expected: Record<string, string>;
  npa: number[];
  addtl: string;
};

function parseCookieConsentTcString(tcString: string | undefined | null): { created: number; lastUpdated: number } | null {
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

async function readGcmLayer(page: Page) {
  return page.evaluate(() => {
    const normalize = (entry: unknown): unknown[] | null => {
      if (!entry) {
        return null;
      }
      try {
        if (typeof (entry as { length?: unknown }).length === 'number') {
          return Array.prototype.slice.call(entry);
        }
      } catch {
        // Ignore and fall through to the object shape below.
      }
      if (Array.isArray(entry)) {
        return entry;
      }
      if (typeof entry === 'object' && Object.prototype.hasOwnProperty.call(entry, '0')) {
        const e = entry as Record<number, unknown>;
        return [e[0], e[1], e[2]].filter((value) => typeof value !== 'undefined');
      }
      return null;
    };

    const dlName =
      (window.fazSettings && typeof window.fazSettings.dataLayerName === 'string'
        ? window.fazSettings.dataLayerName
        : '') || 'dataLayer';
    const layer = ((window as Record<string, unknown>)[dlName] || []) as unknown[];
    const relevant = layer
      .map(normalize)
      .filter((entry): entry is GcmLayerEntry => Array.isArray(entry) && (entry[0] === 'consent' || entry[0] === 'set'));

    return {
      relevant,
      defaults: relevant.filter((entry) => entry[0] === 'consent' && entry[1] === 'default'),
      updates: relevant.filter((entry) => entry[0] === 'consent' && entry[1] === 'update'),
      npaSets: relevant
        .filter((entry) => entry[0] === 'set' && entry[1] && typeof entry[1] === 'object' && Object.prototype.hasOwnProperty.call(entry[1], 'npa'))
        .map((entry) => (entry[1] as { npa: number }).npa),
      addtlConsent: relevant
        .filter((entry) => entry[0] === 'set' && entry[1] === 'addtl_consent')
        .map((entry) => entry[2]),
      decodedConsent: decodeURIComponent((document.cookie.match(/(?:^|; )fazcookie-consent=([^;]+)/) || [,''])[1] || ''),
      bannerVisible: Array.from(document.querySelectorAll('#faz-consent,.faz-consent-container,.faz-modal')).some((el) => {
        const style = window.getComputedStyle(el);
        return !!(el as HTMLElement).offsetWidth || !!(el as HTMLElement).offsetHeight || el.getClientRects().length > 0
          ? style.display !== 'none' && style.visibility !== 'hidden'
          : false;
      }),
    };
  });
}

function expectBaselineDefault(layer: Awaited<ReturnType<typeof readGcmLayer>>) {
  expect(layer.defaults).toHaveLength(1);
  const defaults = layer.defaults[0][2] as Record<string, unknown>;
  expect(defaults).toMatchObject({
    ad_storage: 'denied',
    analytics_storage: 'denied',
    functionality_storage: 'denied',
    personalization_storage: 'denied',
    security_storage: 'granted',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    wait_for_update: 500,
  });
  for (const key of ['ad_storage', 'analytics_storage', 'functionality_storage', 'personalization_storage', 'ad_user_data', 'ad_personalization']) {
    expect(defaults[key], `consent default must not grant ${key}`).not.toBe('granted');
  }
}

function expectConsentUpdate(layer: Awaited<ReturnType<typeof readGcmLayer>>, expected: Record<string, string>) {
  expect(layer.defaults).toHaveLength(1);
  expectBaselineDefault(layer);
  expect(layer.updates.length).toBeGreaterThanOrEqual(1);
  const update = layer.updates[layer.updates.length - 1][2] as Record<string, unknown>;
  expect(update).toMatchObject(expected);
  expect(update).not.toHaveProperty('wait_for_update');
}

test.describe('GCM and IAB TCF behavior', () => {
  test.describe.configure({ mode: 'serial' });

  test('GCM default consent is denied when feature is enabled', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const gcm = await page.evaluate(() => {
      // Resolve dataLayer name: plugin may use a custom name via fazSettings.
      const dlName =
        (window.fazSettings && typeof window.fazSettings.dataLayerName === 'string'
          ? window.fazSettings.dataLayerName
          : '') || 'dataLayer';
      const dl = (window as Record<string, unknown>)[dlName];

      // Use _fazGcm as the authoritative FAZ-specific GCM indicator.
      // Generic signals (gtag, dataLayer, google_tag_data) are unreliable
      // because other plugins (e.g. GTM4WP) create window.dataLayer
      // independently, causing false-positive active detection even when
      // FAZ's GCM module is disabled.
      const active =
        typeof (window as Record<string, unknown>)._fazGcm === 'object' &&
        (window as Record<string, unknown>)._fazGcm !== null;
      if (!active) {
        return { active: false };
      }

      const entries = [...((dl as unknown[]) || [])];
      // dataLayer entries from gtag() are Arguments objects (not real arrays),
      // so we use bracket notation instead of Array.isArray().
      const found = entries.find((entry: unknown) => {
        if (!entry || typeof entry !== 'object') {
          return false;
        }
        const e = entry as Record<number, unknown>;
        return e[0] === 'consent' && e[1] === 'default';
      });

      return {
        active: true,
        defaults: found ? (found as Record<number, unknown>)[2] : null,
      };
    });

    test.skip(!gcm.active, 'GCM not enabled in current plugin settings');

    expect(gcm.defaults).toBeTruthy();
    expect(gcm.defaults.ad_storage).toBe('denied');
    expect(gcm.defaults.analytics_storage).toBe('denied');
  });

  test('GCM restores stored consent with consent update, never a second default (#149)', async ({ browser, wpBaseURL }) => {
    const rawGcmSettings = wpEval(`echo wp_json_encode( get_option( 'faz_gcm_settings', array() ) );`);
    const gcmSettingsB64 = Buffer.from(rawGcmSettings, 'utf8').toString('base64');

    const configureGcm = () => {
      wpEval(`
        update_option( 'faz_gcm_settings', array(
          'status' => true,
          'default_settings' => array(
            array(
              'ad_storage' => 'denied',
              'analytics_storage' => 'denied',
              'ad_user_data' => 'denied',
              'ad_personalization' => 'denied',
              'functionality_storage' => 'denied',
              'personalization_storage' => 'denied',
              'security_storage' => 'granted',
              'analytics' => 'denied',
              'marketing' => 'denied',
              'functional' => 'denied',
              'necessary' => 'granted',
              'regions' => 'All',
            ),
          ),
          'wait_for_update' => 500,
          'url_passthrough' => true,
          'ads_data_redaction' => true,
          'gacm_enabled' => true,
          'gacm_provider_ids' => '89,91,128',
          'non_personalized_ads_fallback' => true,
        ), false );
        wp_cache_delete( 'faz_gcm_settings', 'options' );
        if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
          \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
        }
      `);
    };

    const restoreGcm = () => {
      wpEval(`
        $restored = json_decode( base64_decode( '${gcmSettingsB64}' ), true );
        update_option( 'faz_gcm_settings', is_array( $restored ) ? $restored : array(), false );
        wp_cache_delete( 'faz_gcm_settings', 'options' );
        if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
          \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
        }
      `);
    };

    const scenarios: GcmScenario[] = [
      {
        name: 'accept-all',
        choose: async (page) => {
          await page.getByRole('button', { name: /Accept All/i }).click();
        },
        expected: {
          ad_storage: 'granted',
          analytics_storage: 'granted',
          functionality_storage: 'granted',
          personalization_storage: 'granted',
          security_storage: 'granted',
          ad_user_data: 'granted',
          ad_personalization: 'granted',
        },
        npa: [1, 0],
        addtl: '1~89.91.128',
      },
      {
        name: 'reject-all',
        choose: async (page) => {
          await page.getByRole('button', { name: /Reject All/i }).click();
        },
        expected: {
          ad_storage: 'denied',
          analytics_storage: 'denied',
          functionality_storage: 'denied',
          personalization_storage: 'denied',
          security_storage: 'granted',
          ad_user_data: 'denied',
          ad_personalization: 'denied',
        },
        npa: [1],
        addtl: '1~',
      },
      {
        name: 'custom-marketing-only',
        choose: async (page) => {
          await page.getByRole('button', { name: /Customize/i }).click();
          await page.locator('#fazSwitchmarketing').evaluate((el: HTMLInputElement) => {
            el.checked = true;
            el.dispatchEvent(new Event('change', { bubbles: true }));
          });
          await page.getByRole('button', { name: /Save My Preferences/i }).click();
        },
        expected: {
          ad_storage: 'granted',
          analytics_storage: 'denied',
          functionality_storage: 'denied',
          personalization_storage: 'denied',
          security_storage: 'granted',
          ad_user_data: 'granted',
          ad_personalization: 'granted',
        },
        npa: [1, 0],
        addtl: '1~89.91.128',
      },
      {
        name: 'custom-performance-only',
        choose: async (page) => {
          await page.getByRole('button', { name: /Customize/i }).click();
          await page.locator('#fazSwitchperformance').evaluate((el: HTMLInputElement) => {
            el.checked = true;
            el.dispatchEvent(new Event('change', { bubbles: true }));
          });
          await page.getByRole('button', { name: /Save My Preferences/i }).click();
        },
        expected: {
          ad_storage: 'denied',
          analytics_storage: 'granted',
          functionality_storage: 'denied',
          personalization_storage: 'denied',
          security_storage: 'granted',
          ad_user_data: 'denied',
          ad_personalization: 'denied',
        },
        npa: [1],
        addtl: '1~',
      },
    ];

    const assertScenario = async (scenario: typeof scenarios[number]) => {
      const context = await browser.newContext();
      const page = await context.newPage();
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      const initial = await readGcmLayer(page);
      expectBaselineDefault(initial);
      expect(initial.updates).toHaveLength(0);
      expect(initial.npaSets).toContain(1);
      expect(initial.addtlConsent).toContain('1~');

      await scenario.choose(page);
      await page.waitForFunction(() => document.cookie.includes('fazcookie-consent='));
      const afterChoice = await readGcmLayer(page);
      expectConsentUpdate(afterChoice, scenario.expected);
      expect(afterChoice.npaSets).toEqual(expect.arrayContaining(scenario.npa));
      expect(afterChoice.addtlConsent).toContain(scenario.addtl);

      const cookies = await context.cookies();
      await context.close();

      const returningContext = await browser.newContext();
      await returningContext.addCookies(cookies.filter((cookie) => cookie.name === 'fazcookie-consent'));
      const returningPage = await returningContext.newPage();
      await returningPage.goto('/', { waitUntil: 'domcontentloaded' });
      const returning = await readGcmLayer(returningPage);
      expect(returning.updates, `${scenario.name}: returning visitor must emit exactly one consent update`).toHaveLength(1);
      expectConsentUpdate(returning, scenario.expected);
      expect(returning.npaSets).toEqual(expect.arrayContaining(scenario.npa));
      expect(returning.addtlConsent).toContain(scenario.addtl);
      await returningContext.close();
    };

    try {
      configureGcm();
      for (const scenario of scenarios) {
        await assertScenario(scenario);
      }

      const staleContext = await browser.newContext();
      await staleContext.addCookies([
        {
          name: 'fazcookie-consent',
          value: encodeURIComponent('consentid:stale,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,performance:yes,uncategorized:yes,marketing:yes,rev:1'),
          domain: new URL(wpBaseURL).hostname,
          path: '/',
          sameSite: 'Lax',
        },
      ]);
      const stalePage = await staleContext.newPage();
      await stalePage.goto('/', { waitUntil: 'domcontentloaded' });
      const stale = await readGcmLayer(stalePage);
      expectBaselineDefault(stale);
      expect(stale.updates, 'stale consent cookie must not be restored with consent update').toHaveLength(0);
      expect(stale.npaSets).toContain(1);
      expect(stale.addtlConsent).toContain('1~');
      expect(stale.bannerVisible).toBe(true);
      await staleContext.close();
    } finally {
      restoreGcm();
    }
  });

  test('TCF API responds when enabled', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const tcf = await page.evaluate(async () => {
      if (typeof window.__tcfapi !== 'function') {
        return { available: false };
      }

      const ping = await new Promise((resolve) => {
        window.__tcfapi('ping', 2, (data) => resolve(data));
      });

      return {
        available: true,
        ping,
      };
    });

    test.skip(!tcf.available, 'IAB TCF not enabled in current plugin settings');

    expect(tcf.ping).toBeTruthy();
    expect(tcf.ping.cmpLoaded).toBeTruthy();
    expect(typeof tcf.ping.gdprApplies).toBe('boolean');
    expect(tcf.ping.apiVersion).toBe('2.3');
  });

  test('TCF preserves timestamps on getTCData and clears euconsent-v2 after reject', async ({ page, browser }) => {
    const rawSettings = wpEval(`echo wp_json_encode( get_option( 'faz_settings', array() ) );`);
    const originalSettings = JSON.parse(rawSettings) as Record<string, unknown>;
    const settingsB64 = Buffer.from(rawSettings, 'utf8').toString('base64');

    const rawBannerTemplate = wpEval(`
      echo wp_json_encode( array(
        'exists' => false !== get_option( 'faz_banner_template', false ),
        'value'  => get_option( 'faz_banner_template', null ),
      ) );
    `);
    const originalBannerTemplate = JSON.parse(rawBannerTemplate) as { exists: boolean; value: unknown };
    const bannerTemplateB64 = Buffer.from(rawBannerTemplate, 'utf8').toString('base64');

    // Use a fresh browser context so consent cookies from prior serial tests
    // cannot leak into this test's consent state.
    const freshContext = await browser.newContext();
    const freshPage = await freshContext.newPage();

    try {
      wpEval(`
        $s = get_option( 'faz_settings', array() );
        if ( ! is_array( $s ) ) {
          $s = array();
        }
        if ( empty( $s['iab'] ) || ! is_array( $s['iab'] ) ) {
          $s['iab'] = array();
        }
        $s['iab']['enabled'] = true;
        $s['iab']['cmp_id'] = 123;
        $s['iab']['purpose_one_treatment'] = false;
        update_option( 'faz_settings', $s );
        delete_option( 'faz_banner_template' );
      `);

      await freshContext.clearCookies();
      await freshPage.goto('/', { waitUntil: 'domcontentloaded' });
      await expect(freshPage.locator('[data-faz-tag="notice"]')).toBeVisible();
      await freshPage.evaluate(() => {
        _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
      });
      await freshPage.waitForFunction(() => typeof window.__tcfapi === 'function', undefined, { timeout: 5_000 });

      const initial = await freshPage.evaluate(async () => {
        if (typeof window.__tcfapi !== 'function') {
          return { available: false };
        }
        const ping = await new Promise((resolve) => {
          window.__tcfapi('ping', 2, (data) => resolve(data));
        });
        return { available: true, ping };
      });

      expect(initial.available).toBe(true);
      expect(initial.ping).toBeTruthy();
      expect(initial.ping.cmpLoaded).toBeTruthy();
      expect(initial.ping.cmpStatus).toBe('loaded');
      expect(initial.ping.apiVersion).toBe('2.3');

      const accepted = await clickFirstVisible(freshPage, [
        '[data-faz-tag="accept-button"] button',
        '[data-faz-tag="accept-button"]',
        '.faz-btn-accept',
      ]);
      expect(accepted).toBeTruthy();

      await freshPage.waitForFunction(() => document.cookie.includes('euconsent-v2='), undefined, { timeout: 5_000 });

      const acceptedState = await freshPage.evaluate(async () => {
        const getTcData = () =>
          new Promise((resolve) => {
            window.__tcfapi('getTCData', 2, (data) => resolve(data));
          });
        const readCookieTc = () => document.cookie.match(/euconsent-v2=([^;]+)/)?.[1] || '';

        const cookieTc = readCookieTc();
        const first = await getTcData();
        await new Promise((resolve) => setTimeout(resolve, 1_200));
        const second = await getTcData();

        return {
          cookieTc,
          firstTc: first?.tcString || '',
          secondTc: second?.tcString || '',
        };
      });

      const cookieTs = parseCookieConsentTcString(acceptedState.cookieTc);
      const firstTs = parseCookieConsentTcString(acceptedState.firstTc);
      const secondTs = parseCookieConsentTcString(acceptedState.secondTc);

      expect(cookieTs).not.toBeNull();
      expect(firstTs).not.toBeNull();
      expect(secondTs).not.toBeNull();
      expect(firstTs).toEqual(cookieTs);
      expect(secondTs).toEqual(cookieTs);

      await freshPage.evaluate(() => {
        if (typeof window.revisitFazConsent === 'function') {
          window.revisitFazConsent();
        }
      });
      await expect(freshPage.locator('[data-faz-tag="notice"]')).toBeVisible();

      const rejected = await clickFirstVisible(freshPage, [
        '[data-faz-tag="reject-button"] button',
        '[data-faz-tag="reject-button"]',
        '.faz-btn-reject',
        '[data-faz-tag="close-button"]',
      ]);
      expect(rejected).toBeTruthy();

      await freshPage.waitForFunction(() => !document.cookie.includes('euconsent-v2='), undefined, { timeout: 5_000 });

      const rejectedState = await freshPage.evaluate(() => ({
        euconsentPresent: document.cookie.includes('euconsent-v2='),
      }));
      expect(rejectedState.euconsentPresent).toBe(false);
    } finally {
      await freshContext.clearCookies();
      await freshContext.close();
      wpEval(`
        $restored = json_decode( base64_decode( '${settingsB64}' ), true );
        update_option( 'faz_settings', is_array( $restored ) ? $restored : array() );
        if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
          \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
        }
        $banner_snapshot = json_decode( base64_decode( '${bannerTemplateB64}' ), true );
        if ( is_array( $banner_snapshot ) && ! empty( $banner_snapshot['exists'] ) ) {
          update_option( 'faz_banner_template', $banner_snapshot['value'] );
        } else {
          delete_option( 'faz_banner_template' );
        }
      `);
    }
  });
});
