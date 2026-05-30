/**
 * E2E — GVL vendor auto-detect from scanned cookie domains.
 *
 * Niharika ("Literally Perfect" review on wp.org) asked for a way to
 * pre-populate the IAB Global Vendor List selection from the cookie
 * scanner's domain inventory, instead of having to tick a 700+ row
 * vendor table by hand. The backend lands as
 * GET /faz/v1/gvl/suggest — this spec asserts the contract:
 *
 *   1. Endpoint responds 200 with the documented shape.
 *   2. With ad-tech cookie domains in wp_faz_cookies, the response
 *      surfaces vendor IDs from the curated domain → vendor map
 *      (admin/modules/gvl/data/domain-to-vendor.json) that ALSO exist
 *      in the live GVL. Stale / not-in-GVL IDs are filtered out
 *      silently.
 *   3. Suffix matching is exact-domain only (`.notlinkedin.com` MUST
 *      NOT match `linkedin.com`).
 *   4. Subdomain match: `.m.linkedin.com` DOES match the `linkedin.com`
 *      map entry (dot-prefix suffix logic, vendor 804).
 *   5. `newly_suggested` excludes IDs already in
 *      faz_gvl_selected_vendors; `already_selected` mirrors them.
 *   6. Read-only — calling /suggest never mutates
 *      faz_gvl_selected_vendors. Persistence remains the existing
 *      POST /selected route.
 *   7. Zero matching cookies → empty arrays, not an error.
 *   8. discovered=0 rows (manually-added cookies) are ignored — only
 *      scanner-observed rows feed suggestions.
 *
 * Vendor IDs used as fixtures are real IAB GVL entries:
 *   - 755 → Google Advertising Products (.googletagmanager.com)
 *   - 804 → LinkedIn Ireland (.linkedin.com)
 *   - 986 → TikTok Information Technologies UK (.tiktok.com)
 */

import { type Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';
import { acquireCookiesTableLock, releaseCookiesTableLock } from '../utils/db-lock';

const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager';
const REST_BASE = '/wp-json/faz/v1/gvl';

let adminPage: Page;
let nonce = '';

/**
 * Drop any test cookies the spec planted in prior runs so each
 * assertion starts from a known cookie inventory. wpEval is the
 * project's only WP-CLI-backed shell helper exported from wp-env;
 * raw $wpdb->query() through it is the established pattern in the
 * other specs that need direct DB manipulation outside the REST
 * surface.
 */
function resetCookies(): void {
  wpEval(
    `global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}faz_cookies WHERE slug LIKE 'auto-detect-%'");`,
  );
}

function plantCookies(
  rows: Array<{ name: string; slug: string; domain: string }>,
  discovered: 0 | 1 = 1,
): void {
  if (rows.length === 0) return;
  // Hand-rolled multi-row insert via $wpdb->insert to keep the
  // fixtures legible. `discovered` defaults to 1 because the suggest
  // helper restricts its domain SELECT to scanner-discovered rows
  // (CodeRabbit PR #127 review), so the common-case fixture must
  // mirror what the live scanner would produce. Pass discovered=0 to
  // simulate a manually-added cookie (the Cookies admin page default),
  // which the helper must ignore — test 8 exercises that path.
  for (const r of rows) {
    const code = `global $wpdb; $wpdb->insert(
      $wpdb->prefix . 'faz_cookies',
      array(
        'name'       => ${JSON.stringify(r.name)},
        'slug'       => ${JSON.stringify(r.slug)},
        'domain'     => ${JSON.stringify(r.domain)},
        'category'   => 0,
        'type'       => 'http',
        'discovered' => ${discovered},
      ),
      array('%s','%s','%s','%d','%s','%d')
    );`;
    wpEval(code);
  }
}

async function suggest(): Promise<{
  vendor_ids: number[];
  already_selected: number[];
  newly_suggested: number[];
  gvl_available: boolean;
}> {
  const res = await adminPage.request.get(`${REST_BASE}/suggest`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.ok(), `suggest ${res.status()}: ${(await res.text()).slice(0, 200)}`).toBeTruthy();
  return res.json();
}

test.describe('GVL vendor auto-detect from cookies', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async () => {
    // This hook deliberately blocks until the sibling cookie-policy spec
    // (the only other holder) releases the mutex, which can take that
    // whole spec's runtime under parallel workers — well past the
    // default 45s hook timeout. Raise the hook budget above the lock's
    // own acquire timeout so the wait is loud-on-deadlock, not flaky.
    test.setTimeout(660_000);
    // Hold the shared-table mutex for the whole spec so this suite's
    // table-scoped reads never race the cookie-policy spec's discovered-
    // row wipe under parallel CI workers. Released in afterAll. See
    // utils/db-lock.ts for the rationale.
    await acquireCookiesTableLock();
    // Force-download GVL once for the whole spec (idempotent: skipped
    // if already present). Without GVL data the `gvl_available` flag
    // is false and the suggester returns an empty list by design.
    // wpEval is synchronous (WP-CLI subprocess), so no await needed.
    wpEval(
      `$g = \\FazCookie\\Includes\\Gvl::get_instance(); if (!$g->has_data()) { $g->download(); }`,
    );
  });

  test.beforeEach(async ({ page, loginAsAdmin }) => {
    adminPage = page;
    await loginAsAdmin(adminPage);
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await adminPage.waitForFunction(
      () => typeof (window as unknown as { fazConfig?: { api?: { nonce?: string } } })
        .fazConfig?.api?.nonce === 'string',
      undefined,
      { timeout: 15_000 },
    );
    nonce = await adminPage.evaluate(
      () => (window as unknown as { fazConfig?: { api?: { nonce?: string } } })
        .fazConfig?.api?.nonce ?? '',
    );
    expect(nonce.length).toBeGreaterThan(0);
    resetCookies();
  });

  test.afterAll(async () => {
    resetCookies();
    releaseCookiesTableLock();
  });

  test('1. Endpoint returns the documented shape with all keys present', async () => {
    const r = await suggest();
    expect(r).toHaveProperty('vendor_ids');
    expect(r).toHaveProperty('already_selected');
    expect(r).toHaveProperty('newly_suggested');
    expect(r).toHaveProperty('gvl_available');
    expect(Array.isArray(r.vendor_ids)).toBeTruthy();
    expect(Array.isArray(r.already_selected)).toBeTruthy();
    expect(Array.isArray(r.newly_suggested)).toBeTruthy();
    expect(typeof r.gvl_available).toBe('boolean');
  });

  test('2. With Google + LinkedIn + TikTok cookies planted, those vendor IDs surface', async () => {
    plantCookies([
      { name: 'auto_ga', slug: 'auto-detect-ga', domain: '.googletagmanager.com' },
      { name: 'auto_li', slug: 'auto-detect-li', domain: '.linkedin.com' },
      { name: 'auto_tt', slug: 'auto-detect-tt', domain: '.tiktok.com' },
    ]);
    const r = await suggest();
    expect(r.gvl_available).toBe(true);
    // 755 = Google Advertising Products, 804 = LinkedIn Ireland,
    // 986 = TikTok Information Technologies UK (real IAB GVL IDs).
    expect(r.vendor_ids).toEqual(expect.arrayContaining([755, 804, 986]));
    // Output is sorted unique.
    expect([...r.vendor_ids].sort((a, b) => a - b)).toEqual(r.vendor_ids);
  });

  test('3. Suffix match guard: ".notlinkedin.com" does NOT trigger a LinkedIn match', async () => {
    // Plant a domain that contains "linkedin.com" as a non-suffix substring.
    // The Gvl::suggest_vendor_ids_from_scanned_cookies() helper guards
    // against this with a "." prefix check on the suffix candidate.
    // linkedin.com IS in domain-to-vendor.json (mapped to vendor 804), so
    // this is a true adversarial input: without the dot-prefix guard, a
    // naive substring/endsWith check on `notlinkedin.com` would match
    // `linkedin.com` and falsely surface vendor 804. The assertion that
    // vendor_ids === [] is therefore exercising the guard, not passing
    // trivially because the suffix is absent from the map.
    plantCookies([
      { name: 'auto_evil', slug: 'auto-detect-evil', domain: '.notlinkedin.com' },
    ]);
    const r = await suggest();
    expect(r.vendor_ids).toEqual([]);
  });

  test('4. Subdomain match: ".m.linkedin.com" still matches the linkedin.com map entry', async () => {
    plantCookies([
      { name: 'auto_sub', slug: 'auto-detect-sub', domain: '.m.linkedin.com' },
    ]);
    const r = await suggest();
    expect(r.vendor_ids).toContain(804);
  });

  test('5. already_selected vs newly_suggested split is correct', async () => {
    plantCookies([
      { name: 'auto_ga', slug: 'auto-detect-ga', domain: '.googletagmanager.com' },
      { name: 'auto_li', slug: 'auto-detect-li', domain: '.linkedin.com' },
    ]);
    // Pre-select Google (755) only.
    wpEval(`update_option('faz_gvl_selected_vendors', array(755), false);`);
    try {
      const r = await suggest();
      expect(r.vendor_ids).toEqual(expect.arrayContaining([755, 804]));
      expect(r.already_selected).toEqual([755]);
      expect(r.newly_suggested).toEqual([804]);
    } finally {
      // Clean up the option so other tests see a fresh state.
      wpEval(`delete_option('faz_gvl_selected_vendors');`);
    }
  });

  test('6. Read-only: calling /suggest does NOT mutate faz_gvl_selected_vendors', async () => {
    plantCookies([
      { name: 'auto_ga', slug: 'auto-detect-ga', domain: '.googletagmanager.com' },
    ]);
    wpEval(`delete_option('faz_gvl_selected_vendors');`);
    await suggest();
    const stored = wpEval(
      `echo wp_json_encode(get_option('faz_gvl_selected_vendors', 'NOT_SET'));`,
    );
    expect(stored.trim()).toBe('"NOT_SET"');
  });

  test('7. With zero matching cookies, response is empty arrays — not an error', async () => {
    plantCookies([
      { name: 'auto_noise', slug: 'auto-detect-noise', domain: '.example.org' },
    ]);
    const r = await suggest();
    expect(r.gvl_available).toBe(true);
    expect(r.vendor_ids).toEqual([]);
    expect(r.already_selected).toEqual([]);
    expect(r.newly_suggested).toEqual([]);
  });

  test('8. discovered=0 rows are ignored — only scanner-discovered cookies feed suggestions', async () => {
    // Manually-added cookies (discovered=0, the default when the
    // admin adds a row from the Cookies admin page) MUST NOT
    // surface a vendor suggestion: the auto-detect feature is
    // explicitly about what the SCANNER observed on the live site.
    // CodeRabbit PR #127 review flagged this as the cookie schema's
    // discovered column is the contract boundary between
    // scanner-observed and admin-curated rows.
    plantCookies(
      [{ name: 'manual_ga', slug: 'auto-detect-manual', domain: '.googletagmanager.com' }],
      0,
    );
    const r = await suggest();
    expect(r.vendor_ids, 'manually-added discovered=0 cookies leaked into the suggestion').toEqual([]);
  });
});
