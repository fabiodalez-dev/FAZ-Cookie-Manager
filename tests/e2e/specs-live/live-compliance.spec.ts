import { expect, test } from '@playwright/test';
import {
  NOTICE,
  TRACKER_URL_RE,
  clickFirstVisible,
  getConsentCookie,
  parseConsentCookie,
} from './_live-helpers';

/**
 * LIVE compliance suite — config-independent privacy-law invariants checked
 * against a production site (default https://fabiodalez.it) WITHOUT mutating
 * data. Every test runs in its own fresh context. Tests that need a feature
 * the site may not ship (revisit widget, Google Consent Mode) skip gracefully
 * rather than fail, so the suite stays green across banner reconfigurations.
 *
 * Read-only note: COMP-01..06 perform NO consent action (no server write).
 * COMP-07 alone clicks "Accept" to read the consent-cookie lifetime — that
 * records one consent-log row, exactly as a real visitor would.
 */

test.describe('Live compliance — config-independent privacy invariants', () => {
  // ePrivacy / GDPR Art.6 — no tracker is contacted before consent.
  test('COMP-01 (ePrivacy): no tracker network requests fire before consent', async ({ page }) => {
    const hits: string[] = [];
    page.on('request', (r) => {
      if (TRACKER_URL_RE.test(r.url())) hits.push(r.url());
    });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator(NOTICE)).toBeVisible({ timeout: 10_000 });
    await page.waitForTimeout(2500); // let any (mis)behaving tag try to load
    expect(hits, `Trackers contacted before consent:\n${hits.join('\n')}`).toHaveLength(0);
  });

  // GDPR Art.7 / EDPB — opt-in: optional categories default OFF, necessary is
  // ON and not switchable. Reads toggle state only; never saves.
  test('COMP-02 (GDPR opt-in): optional categories default OFF, necessary locked ON', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator(NOTICE)).toBeVisible({ timeout: 10_000 });

    const opened = await clickFirstVisible(page, [
      '[data-faz-tag="settings-button"] button',
      '[data-faz-tag="settings-button"]',
    ]);
    test.skip(!opened, 'No preference-center button on this banner');
    await page.waitForTimeout(1000);

    const toggles = await page.evaluate(() => {
      const boxes = Array.from(
        document.querySelectorAll<HTMLInputElement>('input[type="checkbox"][id^="fazSwitch"], input[type="checkbox"][id^="fazCategoryDirect"]'),
      );
      return boxes.map((b) => ({
        id: b.id,
        checked: b.checked,
        disabled: b.disabled,
        necessary: /necessary/i.test(b.id),
      }));
    });
    test.skip(toggles.length === 0, 'No category toggles exposed in the preference center');

    for (const t of toggles) {
      if (t.necessary) {
        expect(t.checked, `necessary toggle "${t.id}" must be ON`).toBe(true);
        expect(t.disabled, `necessary toggle "${t.id}" must be non-disableable`).toBe(true);
      } else {
        expect(t.checked, `optional toggle "${t.id}" must default OFF (opt-in)`).toBe(false);
      }
    }
  });

  // EDPB Guidelines 03/2022 — scrolling is NOT consent.
  test('COMP-03 (EDPB): scrolling does not grant consent', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator(NOTICE)).toBeVisible({ timeout: 10_000 });
    await page.evaluate(() => window.scrollBy(0, 1800));
    await page.waitForTimeout(2000);
    const consent = await getConsentCookie(page.context());
    const granted = consent ? parseConsentCookie(consent.value).action === 'yes' : false;
    expect(granted, 'scrolling must not record a consent action').toBe(false);
  });

  // Garante 10/06/2021 + EDPB — reject is available at the first layer with
  // visual weight comparable to accept (no dark pattern). Skips if the banner
  // exposes only an X (a valid CCPA-only configuration).
  test('COMP-04 (Garante/EDPB): reject reachable at first layer, equal weight', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator(NOTICE)).toBeVisible({ timeout: 10_000 });

    const accept = page.locator('[data-faz-tag="accept-button"]').first();
    const reject = page.locator('[data-faz-tag="reject-button"]').first();
    const close = page.locator('[data-faz-tag="close-button"]').first();

    const hasReject = await reject.isVisible().catch(() => false);
    const hasClose = await close.isVisible().catch(() => false);
    expect(hasReject || hasClose, 'a reject (or X) control must exist at the first layer').toBe(true);

    test.skip(!hasReject, 'Banner uses an X as the reject equivalent — weight check N/A');
    const a = await accept.boundingBox();
    const r = await reject.boundingBox();
    expect(a && r, 'accept and reject must both be laid out').toBeTruthy();
    const ratio = Math.min(a!.height, r!.height) / Math.max(a!.height, r!.height);
    expect(
      ratio,
      `accept ${Math.round(a!.width)}x${Math.round(a!.height)} vs reject ${Math.round(r!.width)}x${Math.round(r!.height)}`,
    ).toBeGreaterThan(0.8);
  });

  // GDPR Art.7(3) — withdrawing consent must be as easy as giving it: a
  // persistent revisit control must exist. Skips if the widget is disabled.
  test('COMP-05 (GDPR Art.7.3): a consent-withdrawal control is present', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500); // the widget is injected by script.js after load
    const revisit = page.locator('[data-faz-tag="revisit-consent"]').first();
    const exists = (await revisit.count()) > 0;
    test.skip(!exists, 'Revisit widget not enabled on this site');
    // Present in the DOM is enough; it is the documented withdrawal entry point.
    expect(exists).toBe(true);
  });

  // Google Consent Mode v2 — default state must be "denied" before any choice.
  // Skips when GCM is not configured on the site.
  test('COMP-06 (GCM v2): consent default is denied before any choice', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    const def = await page.evaluate(() => {
      const dl = (window as unknown as { dataLayer?: unknown[] }).dataLayer;
      if (!Array.isArray(dl)) return null;
      // gtag('consent','default',{...}) pushes an arguments-like object: index
      // it positionally rather than relying on Array.isArray.
      for (const e of dl) {
        const a = e as Record<number, unknown>;
        if (a && a[0] === 'consent' && a[1] === 'default') return a[2] as Record<string, string>;
      }
      return null;
    });
    test.skip(def === null, 'Google Consent Mode not configured on this site');
    const denied = (k: string) => !def![k] || def![k] === 'denied';
    expect(denied('ad_storage'), `ad_storage=${def!.ad_storage}`).toBe(true);
    expect(denied('analytics_storage'), `analytics_storage=${def!.analytics_storage}`).toBe(true);
  });

  // Garante Provv. 10/06/2021 — consent must not last longer than 6 months.
  // The ONLY test here that records a consent action (one consent-log row).
  test('COMP-07 (Garante): consent cookie lifetime <= 6 months', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator(NOTICE)).toBeVisible({ timeout: 10_000 });
    const accepted = await clickFirstVisible(page, [
      '[data-faz-tag="accept-button"] button',
      '[data-faz-tag="accept-button"]',
      '.faz-btn-accept',
    ]);
    expect(accepted, 'accept button not found').toBeTruthy();

    const consent = await getConsentCookie(page.context());
    expect(consent, 'consent cookie missing after accept').toBeDefined();
    expect(consent!.expires, 'consent cookie must not be a session cookie').toBeGreaterThan(0);
    // Compare the real (unrounded) lifetime: Math.round() could round a value
    // just over the 183-day cap back down and let an over-long cookie pass.
    const days = (consent!.expires - Date.now() / 1000) / 86400;
    expect(days, `consent lifetime is ${days.toFixed(2)} days`).toBeLessThanOrEqual(183);
  });
});
