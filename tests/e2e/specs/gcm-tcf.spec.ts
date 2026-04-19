import { expect, test } from '../fixtures/wp-fixture';
import { clickFirstVisible } from '../utils/ui';
import { wpEval } from '../utils/wp-env';

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

      // Check multiple indicators: gtag function, dataLayer (standard or custom), or google_tag_data.
      const hasGtag = typeof window.gtag === 'function';
      const hasDataLayer = Array.isArray(dl);
      const hasGoogleTagData =
        typeof window.google_tag_data === 'object' &&
        window.google_tag_data !== null &&
        typeof window.google_tag_data.ics === 'object';

      const active = hasGtag || hasDataLayer || hasGoogleTagData;
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

  test('TCF preserves timestamps on getTCData and clears euconsent-v2 after reject', async ({ page }) => {
    const originalSettings = JSON.parse(
      wpEval(`echo wp_json_encode( get_option( 'faz_settings', array() ) );`)
    ) as Record<string, unknown>;

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

      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
      await page.evaluate(() => {
        _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
      });
      await page.waitForFunction(() => typeof window.__tcfapi === 'function', undefined, { timeout: 5_000 });

      const initial = await page.evaluate(async () => {
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

      const accepted = await clickFirstVisible(page, [
        '[data-faz-tag="accept-button"] button',
        '[data-faz-tag="accept-button"]',
        '.faz-btn-accept',
      ]);
      expect(accepted).toBeTruthy();

      await page.waitForFunction(() => document.cookie.includes('euconsent-v2='), undefined, { timeout: 5_000 });

      const acceptedState = await page.evaluate(async () => {
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

      await page.evaluate(() => {
        if (typeof window.revisitFazConsent === 'function') {
          window.revisitFazConsent();
        }
      });
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

      const rejected = await clickFirstVisible(page, [
        '[data-faz-tag="reject-button"] button',
        '[data-faz-tag="reject-button"]',
        '.faz-btn-reject',
        '[data-faz-tag="close-button"]',
      ]);
      expect(rejected).toBeTruthy();

      await page.waitForFunction(() => !document.cookie.includes('euconsent-v2='), undefined, { timeout: 5_000 });

      const rejectedState = await page.evaluate(() => ({
        euconsentPresent: document.cookie.includes('euconsent-v2='),
      }));
      expect(rejectedState.euconsentPresent).toBe(false);
    } finally {
      const encodedSettings = JSON.stringify(originalSettings);
      wpEval(`
        $restored = json_decode( wp_unslash( ${JSON.stringify(encodedSettings)} ), true );
        update_option( 'faz_settings', is_array( $restored ) ? $restored : array() );
        delete_option( 'faz_banner_template' );
      `);
    }
  });
});
