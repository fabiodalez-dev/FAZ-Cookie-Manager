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
 *   - settings.geolocation.target_regions = [] (no region selected — the
 *     global geo gate in Frontend::is_geo_banner_disabled() has nothing to
 *     split visitors on, so the no-store header buys nothing)
 *   - settings.iab.enabled = false
 *   - has_country_dependent_banners() returns false
 *   - at least one banner is configured
 *
 * When target_regions IS populated the banner genuinely varies by visitor
 * country (in-region visitors see it, others don't), so the no-store is
 * justified and the warning must NOT fire (test 6).
 *
 * Tests:
 *   1. Warning appears with all guards matched (no target regions).
 *   2. "Disable Geo-routing now" AJAX flips geo_targeting off and the
 *      warning disappears on next page load.
 *   3. Warning does NOT appear when IAB TCF is enabled (geo no-cache is
 *      justified anyway).
 *   4. Warning does NOT appear when default_behavior is "show_banner"
 *      (no no-cache emitted).
 *   5. Warning does NOT appear when geo_targeting is off (the obvious case).
 *   6. Warning does NOT appear when target_regions is populated (the geo
 *      gate is doing real per-country work — no-store is justified).
 */

import { test, expect, type Page } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager';
const NOTICE_ID = 'faz-redundant-geo-routing-notice';

let adminPage: Page;

async function setSettings(page: Page, patch: Record<string, unknown>): Promise<void> {
  // Persist settings through the existing nonce-protected faz/v1/settings
  // REST route (GET current → deep-merge patch → POST). This keeps the spec
  // self-contained without shelling out to WP-CLI.
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

test.describe('1.16.3 — Redundant Geo-routing admin warning', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(() => {
    // The "warning appears" tests require has_country_dependent_banners()
    // to be false (no banner carries a target_countries list). The
    // multi-banner-geo-routing spec runs earlier and restores banner_id=2
    // with target_countries=["US"] from its snapshot, which would make the
    // guard short-circuit and the notice never render. Clear every banner's
    // target_countries here so this spec is self-contained regardless of
    // what ran before. (Rows are left in place — only the column is reset —
    // so the geo suite's banner_id=2-exists presupposition still holds.)
    wpEval(`
      global $wpdb;
      $wpdb->query( "UPDATE {$wpdb->prefix}faz_banners SET target_countries = '[]'" );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    `);
  });

  test.beforeEach(async ({ page, loginAsAdmin }) => {
    adminPage = page;
    await loginAsAdmin(adminPage);
  });

  test.afterAll(() => {
    // This spec toggles the global geo gate ON (geo_targeting + no_banner)
    // to exercise the redundant-routing notice. Left behind, that state
    // makes Frontend::is_geo_banner_disabled() suppress the banner for
    // out-of-region visitors, which breaks later geo specs that assume a
    // neutral global geo config (e.g. multi-banner-geo-routing GEO-19's
    // US-visitor AMP resolution). Reset it here so the spec is a good
    // citizen regardless of run order. global-setup also resets this at
    // the start of every run as defence-in-depth.
    wpEval(`
      $s = get_option( 'faz_settings', array() );
      if ( ! is_array( $s ) ) { $s = array(); }
      if ( ! isset( $s['geolocation'] ) || ! is_array( $s['geolocation'] ) ) { $s['geolocation'] = array(); }
      $s['geolocation']['geo_targeting'] = false;
      update_option( 'faz_settings', $s );
      delete_transient( 'faz_dismiss_redundant_geo_routing' );
    `);
  });

  test('1. Warning appears when geo_targeting=on + no_banner + no target regions + no target_countries + iab off', async () => {
    await setSettings(adminPage, {
      geolocation: { geo_targeting: true, default_behavior: 'no_banner', target_regions: [] },
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

  test('6. Warning does NOT appear when target_regions is populated (geo gate is doing real work)', async () => {
    // Everything else matches the redundant config, but a non-empty
    // target_regions means is_geo_banner_disabled() genuinely hides the
    // banner outside EU/UK — the page output varies by country and the
    // no-store header is justified, so the warning must stay hidden.
    await setSettings(adminPage, {
      geolocation: { geo_targeting: true, default_behavior: 'no_banner', target_regions: ['eu', 'uk'] },
      iab: { enabled: false },
    });
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await expect(adminPage.locator(`#${NOTICE_ID}`)).toHaveCount(0);
  });
});
