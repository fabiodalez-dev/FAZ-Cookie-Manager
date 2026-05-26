/**
 * E2E — 1.16.3 redundant Geo-routing admin warning.
 *
 * Covers the UX gap reported by James (englishtruffles.co.uk) on the
 * "Performance Impact???" wp.org thread: enabling Geo-routing with
 * default_behavior=no_banner while no banner carries target_countries
 * silently kills the CDN cache for zero functional benefit.
 *
 * The notice fires on every FAZ admin page when:
 *   - settings.geolocation.geo_targeting = true
 *   - settings.geolocation.default_behavior = "no_banner"
 *   - settings.iab.enabled = false
 *   - has_country_dependent_banners() returns false
 *   - at least one banner is configured
 *
 * Tests:
 *   1. Warning appears with all guards matched.
 *   2. "Disable Geo-routing now" AJAX flips geo_targeting off and the
 *      warning disappears on next page load.
 *   3. Warning does NOT appear when IAB TCF is enabled (geo no-cache is
 *      justified anyway).
 *   4. Warning does NOT appear when default_behavior is "show_banner"
 *      (no no-cache emitted).
 *   5. Warning does NOT appear when geo_targeting is off (the obvious case).
 */

import { test, expect, type Page } from '../fixtures/wp-fixture';

const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager';
const NOTICE_ID = 'faz-redundant-geo-routing-notice';

let adminPage: Page;

async function setSettings(page: Page, patch: Record<string, unknown>): Promise<void> {
  // Drive via WP-CLI through page.request would be possible but we
  // already have a faz/v1/settings route protected by nonce; using
  // the option directly is simpler for test setup. Persist via a
  // dedicated REST helper if one existed — for now, drive the option
  // through WP-CLI exec from the test harness's WP_PATH.
  // Note: the spec invokes wp shell out of band; here we POST through
  // the existing settings endpoint to stay self-contained.
  await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(
    () => typeof (window as unknown as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce === 'string',
    undefined,
    { timeout: 10_000 },
  );
  const nonce = await page.evaluate(
    () => (window as unknown as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '',
  );
  // Fetch current settings to merge into rather than overwrite.
  const getRes = await page.request.get('/wp-json/faz/v1/settings/', { headers: { 'X-WP-Nonce': nonce } });
  const current = (await getRes.json()) as Record<string, unknown>;
  // Deep-merge patch.
  function merge(a: any, b: any): any {
    if (b === null || typeof b !== 'object' || Array.isArray(b)) return b;
    const out: any = { ...(a ?? {}) };
    for (const k of Object.keys(b)) out[k] = merge(out[k], b[k]);
    return out;
  }
  const next = merge(current, patch);
  const putRes = await page.request.post('/wp-json/faz/v1/settings/', {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: next,
  });
  expect(putRes.ok(), `settings POST ${putRes.status()}: ${(await putRes.text()).slice(0, 200)}`).toBeTruthy();
}

async function clearDismissal(page: Page): Promise<void> {
  // Test setup drives wp transient delete via REST eval would be more
  // elegant; here we just navigate to the admin page after the option
  // change so the next notice render evaluates the live state. The
  // dismissal transient is short-lived (30 days) so cross-test pollution
  // is possible — neutralise it by always running the "warning expected"
  // assertions before the dismiss flow.
  await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
}

test.describe('1.16.3 — Redundant Geo-routing admin warning', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(async ({ page, loginAsAdmin }) => {
    adminPage = page;
    await loginAsAdmin(adminPage);
  });

  test('1. Warning appears when geo_targeting=on + no_banner + no target_countries + iab off', async () => {
    await setSettings(adminPage, {
      geolocation: { geo_targeting: true, default_behavior: 'no_banner' },
      iab: { enabled: false },
    });
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    const notice = adminPage.locator(`#${NOTICE_ID}`);
    await expect(notice).toBeVisible({ timeout: 10_000 });
    await expect(notice).toContainText(/Geo-routing is on but has no effect/i);
    // CTA buttons.
    await expect(notice.locator('#faz-disable-redundant-geo-routing')).toBeVisible();
    await expect(notice.locator('a.button:has-text("Open Geo-routing settings")')).toBeVisible();
  });

  test('2. "Disable Geo-routing now" flips the setting and clears the warning', async () => {
    // (test 1 left the matched state in place)
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    const notice = adminPage.locator(`#${NOTICE_ID}`);
    await expect(notice).toBeVisible();
    // Click and wait for the inline confirmation copy to replace the action paragraph.
    await adminPage.locator('#faz-disable-redundant-geo-routing').click();
    await expect(notice).toContainText(/Geo-routing disabled/i, { timeout: 10_000 });
    // Reload — guard should evaluate to false now, notice gone.
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await expect(adminPage.locator(`#${NOTICE_ID}`)).toHaveCount(0);
  });

  test('3. Warning does NOT appear when IAB TCF is enabled (no-cache is justified)', async () => {
    await setSettings(adminPage, {
      geolocation: { geo_targeting: true, default_behavior: 'no_banner' },
      iab: { enabled: true },
    });
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await expect(adminPage.locator(`#${NOTICE_ID}`)).toHaveCount(0);
  });

  test('4. Warning does NOT appear when default_behavior is "show_banner"', async () => {
    await setSettings(adminPage, {
      geolocation: { geo_targeting: true, default_behavior: 'show_banner' },
      iab: { enabled: false },
    });
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await expect(adminPage.locator(`#${NOTICE_ID}`)).toHaveCount(0);
  });

  test('5. Warning does NOT appear when geo_targeting is off', async () => {
    await setSettings(adminPage, {
      geolocation: { geo_targeting: false, default_behavior: 'no_banner' },
      iab: { enabled: false },
    });
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await expect(adminPage.locator(`#${NOTICE_ID}`)).toHaveCount(0);
  });
});
