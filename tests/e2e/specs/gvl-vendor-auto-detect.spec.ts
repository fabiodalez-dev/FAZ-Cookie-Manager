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
 *   3. Suffix matching is exact-domain only (`.notfacebook.com` MUST
 *      NOT match `facebook.com`).
 *   4. `newly_suggested` excludes IDs already in
 *      faz_gvl_selected_vendors; `already_selected` mirrors them.
 *   5. Read-only — calling /suggest never mutates
 *      faz_gvl_selected_vendors. Persistence remains the existing
 *      POST /selected route.
 */

import { type Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

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

function plantCookies(rows: Array<{ name: string; slug: string; domain: string }>): void {
  if (rows.length === 0) return;
  // Hand-rolled multi-row insert via $wpdb->prepare to keep the
  // test fixtures legible. The fields are hard-coded in the spec
  // (no test-runner user input flows through here) so the prepare
  // is belt-and-braces rather than load-bearing security.
  for (const r of rows) {
    const code = `global $wpdb; $wpdb->insert(
      $wpdb->prefix . 'faz_cookies',
      array(
        'name'   => ${JSON.stringify(r.name)},
        'slug'   => ${JSON.stringify(r.slug)},
        'domain' => ${JSON.stringify(r.domain)},
        'category' => 0,
        'type'   => 'http',
      ),
      array('%s','%s','%s','%d','%s')
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

  test.beforeAll(() => {
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
    expect(r.vendor_ids).toEqual(expect.arrayContaining([755, 804, 986]));
    // Output is sorted unique.
    expect([...r.vendor_ids].sort((a, b) => a - b)).toEqual(r.vendor_ids);
  });

  test('3. Suffix match guard: ".notfacebook.com" does NOT trigger a Facebook match', async () => {
    // Plant a domain that contains "facebook.com" as a non-suffix substring.
    // The Gvl::suggest_vendor_ids_from_scanned_cookies() helper guards
    // against this with a "." prefix check on the suffix candidate.
    plantCookies([
      { name: 'auto_evil', slug: 'auto-detect-evil', domain: '.notfacebook.com' },
    ]);
    const r = await suggest();
    // facebook.com isn't in the mapping anyway (Meta isn't a TCF vendor),
    // but the assertion is still meaningful: the helper must NOT match
    // ".notfacebook.com" against any candidate suffix in the map. So
    // the response should have zero vendor_ids for THIS cookie alone.
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
});
