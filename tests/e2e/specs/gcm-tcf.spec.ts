import { expect, test } from '../fixtures/wp-fixture';

test.describe('GCM and IAB TCF behavior', () => {
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
    expect(tcf.ping.apiVersion).toBe('2.2');
  });
});
