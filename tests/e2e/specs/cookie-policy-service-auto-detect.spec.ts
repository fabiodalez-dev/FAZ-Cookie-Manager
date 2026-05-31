/**
 * E2E — Cookie Policy Third-party services auto-detect from scanned cookies.
 *
 * Gooloo feedback on 1.16.2 (wp.org support thread): the Drittanbieter
 * table in the rendered policy was empty because the Third-party services
 * tab in admin had nothing ticked — even though the cookie scanner had
 * already surfaced Google Ads, TikTok, YouTube etc. in the cookie list.
 * Two independent flows with no bridge between them; the UI gave the
 * admin no signal that the ticks were the missing piece.
 *
 * This spec covers the bridge added in PR #127 (extending the IAB GVL
 * auto-detect pattern into the Cookie Policy generator):
 *
 *   1. GET /faz/v1/cookie-policy/suggest-services returns the documented
 *      shape with all four keys present.
 *   2. With known third-party cookie domains in wp_faz_cookies, the
 *      response surfaces service IDs from the curated map
 *      (admin/modules/cookie-policy-generator/data/domain-to-service.json)
 *      that ALSO exist in the API allowlist (sanitize_settings).
 *   3. Suffix-match guard: '.notlinkedin.com' MUST NOT match 'linkedin.com'
 *      (same dot-prefix invariant we just hardened on the GVL side).
 *   4. already_selected vs. newly_suggested split mirrors what is already
 *      in faz_cookie_policy_data['third_party_services'].
 *   5. Read-only — /suggest-services never mutates faz_cookie_policy_data.
 *   6. discovered=0 rows (manually-added cookies) are ignored — only
 *      scanner-observed rows feed suggestions.
 *   7. GET /detected-services returns the same scan-derived set without
 *      the partition (powers the "Detected" badge in the UI).
 *
 * NB: wpEval below is a WP-CLI subprocess helper (eval-string) for
 * database fixturing — it is NOT JavaScript eval() and the
 * security-reminder hook flags it as a false positive.
 */

import { type Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';
import { acquireCookiesTableLock, releaseCookiesTableLock } from '../utils/db-lock';

const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy';
const REST_BASE = '/wp-json/faz/v1/cookie-policy';

let adminPage: Page;
let nonce = '';

function resetCookies(): void {
  wpEval(
    `global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}faz_cookies WHERE slug LIKE 'cp-auto-%'");`,
  );
}

function plantCookies(rows: Array<{ name: string; slug: string; domain: string }>): void {
  if (rows.length === 0) return;
  for (const r of rows) {
    const code = `global $wpdb; $wpdb->insert(
      $wpdb->prefix . 'faz_cookies',
      array(
        'name'       => ${JSON.stringify(r.name)},
        'slug'       => ${JSON.stringify(r.slug)},
        'domain'     => ${JSON.stringify(r.domain)},
        'category'   => 0,
        'type'       => 'http',
        'discovered' => 1,
      ),
      array('%s','%s','%s','%d','%s','%d')
    );`;
    wpEval(code);
  }
}

async function suggest(): Promise<{
  service_ids: string[];
  already_selected: string[];
  newly_suggested: string[];
  scan_available: boolean;
}> {
  const res = await adminPage.request.get(`${REST_BASE}/suggest-services`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.ok(), `suggest ${res.status()}: ${(await res.text()).slice(0, 200)}`).toBeTruthy();
  return res.json();
}

async function detected(): Promise<{
  service_ids: string[];
  scan_available: boolean;
}> {
  const res = await adminPage.request.get(`${REST_BASE}/detected-services`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.ok(), `detected ${res.status()}: ${(await res.text()).slice(0, 200)}`).toBeTruthy();
  return res.json();
}

test.describe('Cookie Policy third-party auto-detect from cookies', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async () => {
    // Deliberately blocks until the sibling gvl spec releases the mutex,
    // which can take that whole spec's runtime under parallel workers —
    // past the default 45s hook timeout. Raise the hook budget above the
    // lock's acquire timeout so the wait is loud-on-deadlock, not flaky.
    test.setTimeout(660_000);
    // Hold the shared-table mutex for the whole spec. Tests 16 and 20
    // wipe every discovered row in wp_faz_cookies (then restore) to
    // assert the zero-inventory path; under parallel CI workers that
    // window must not overlap the gvl spec's table-scoped reads.
    // Released in afterAll. See utils/db-lock.ts for the rationale.
    await acquireCookiesTableLock();
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
    wpEval(
      `$d = (array) get_option('faz_cookie_policy_data', array()); $d['third_party_services'] = array(); update_option('faz_cookie_policy_data', $d, false);`,
    );
  });

  test.afterAll(async () => {
    resetCookies();
    releaseCookiesTableLock();
  });

  test('1. /suggest-services returns the documented shape with all four keys present', async () => {
    const r = await suggest();
    expect(r).toHaveProperty('service_ids');
    expect(r).toHaveProperty('already_selected');
    expect(r).toHaveProperty('newly_suggested');
    expect(r).toHaveProperty('scan_available');
    expect(Array.isArray(r.service_ids)).toBeTruthy();
    expect(Array.isArray(r.already_selected)).toBeTruthy();
    expect(Array.isArray(r.newly_suggested)).toBeTruthy();
    expect(typeof r.scan_available).toBe('boolean');
  });

  test('2. With Google Tag Manager + TikTok + YouTube cookies planted, those service IDs surface', async () => {
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-gtm', domain: '.googletagmanager.com' },
      { name: 'cp_tt',  slug: 'cp-auto-tt',  domain: '.tiktok.com' },
      { name: 'cp_yt',  slug: 'cp-auto-yt',  domain: '.youtube.com' },
    ]);
    const r = await suggest();
    expect(r.scan_available).toBe(true);
    expect(r.service_ids).toEqual(expect.arrayContaining(['gtm', 'tiktok', 'youtube']));
    expect([...r.service_ids].sort()).toEqual(r.service_ids);
  });

  test('3. Suffix-match guard: ".notlinkedin.com" does NOT trigger a LinkedIn match', async () => {
    plantCookies([
      { name: 'cp_evil', slug: 'cp-auto-evil', domain: '.notlinkedin.com' },
    ]);
    const r = await suggest();
    expect(r.service_ids).toEqual([]);
  });

  test('4. Subdomain match: ".m.linkedin.com" still matches the linkedin.com entry', async () => {
    plantCookies([
      { name: 'cp_sub', slug: 'cp-auto-sub', domain: '.m.linkedin.com' },
    ]);
    const r = await suggest();
    expect(r.service_ids).toContain('linkedin');
  });

  test('5. already_selected vs newly_suggested split is correct', async () => {
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-gtm', domain: '.googletagmanager.com' },
      { name: 'cp_li',  slug: 'cp-auto-li',  domain: '.linkedin.com' },
    ]);
    wpEval(
      `$d = (array) get_option('faz_cookie_policy_data', array()); $d['third_party_services'] = array('gtm'); update_option('faz_cookie_policy_data', $d, false);`,
    );
    const r = await suggest();
    expect(r.service_ids).toEqual(expect.arrayContaining(['gtm', 'linkedin']));
    expect(r.already_selected).toEqual(['gtm']);
    expect(r.newly_suggested).toEqual(['linkedin']);
  });

  test('6. Read-only: calling /suggest-services does NOT mutate faz_cookie_policy_data', async () => {
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-gtm', domain: '.googletagmanager.com' },
    ]);
    wpEval(
      `$d = (array) get_option('faz_cookie_policy_data', array()); $d['third_party_services'] = array(); update_option('faz_cookie_policy_data', $d, false);`,
    );
    await suggest();
    const after = wpEval(
      `$d = (array) get_option('faz_cookie_policy_data', array()); echo wp_json_encode($d['third_party_services'] ?? null);`,
    ).trim();
    expect(after).toBe('[]');
  });

  test('7. discovered=0 rows (manually-added cookies) are ignored', async () => {
    wpEval(`global $wpdb; $wpdb->insert(
      $wpdb->prefix . 'faz_cookies',
      array(
        'name'       => 'manual_gtm',
        'slug'       => 'cp-auto-manual',
        'domain'     => '.googletagmanager.com',
        'category'   => 0,
        'type'       => 'http',
        'discovered' => 0,
      ),
      array('%s','%s','%s','%d','%s','%d')
    );`);
    const r = await suggest();
    expect(r.service_ids, 'manually-added cookies leaked into the suggestion').toEqual([]);
  });

  test('8. With zero matching cookies, response is empty arrays — not an error', async () => {
    plantCookies([
      { name: 'cp_noise', slug: 'cp-auto-noise', domain: '.example.org' },
    ]);
    const r = await suggest();
    expect(r.scan_available).toBe(true);
    expect(r.service_ids).toEqual([]);
    expect(r.already_selected).toEqual([]);
    expect(r.newly_suggested).toEqual([]);
  });

  test('9. /detected-services returns the same scan set without the partition', async () => {
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-gtm', domain: '.googletagmanager.com' },
      { name: 'cp_st',  slug: 'cp-auto-st',  domain: '.js.stripe.com' },
    ]);
    const r = await detected();
    expect(r).toHaveProperty('service_ids');
    expect(r).toHaveProperty('scan_available');
    expect(r.scan_available).toBe(true);
    expect(r.service_ids).toEqual(expect.arrayContaining(['gtm', 'stripe']));
    expect([...r.service_ids].sort()).toEqual(r.service_ids);
  });

  test('10. Admin UI: clicking Auto-detect button pre-ticks matching checkboxes', async () => {
    plantCookies([
      { name: 'cp_gads', slug: 'cp-auto-gads', domain: '.googleadservices.com' },
      { name: 'cp_yt',   slug: 'cp-auto-yt',   domain: '.youtube.com' },
    ]);
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    const summary = adminPage.locator('summary', { hasText: 'Third-party services' });
    await summary.click();
    await adminPage.waitForSelector(
      '#cp-services-list input[type=checkbox][data-service-id="gads"]',
      { timeout: 5_000 },
    );
    const beforeChecked = await adminPage.$$eval(
      '#cp-services-list input[type=checkbox]:checked',
      (els) => els.length,
    );
    expect(beforeChecked).toBe(0);

    await adminPage.click('#cp-services-auto-detect');
    await expect(adminPage.locator('#cp-services-auto-detect-status')).toContainText(
      /Pre-ticked \d+ new service\(s\), \d+ were already selected\. Click Save to commit\./i,
      { timeout: 10_000 },
    );

    expect(
      await adminPage.locator('#cp-services-list input[data-service-id="gads"]').isChecked(),
    ).toBe(true);
    expect(
      await adminPage.locator('#cp-services-list input[data-service-id="youtube"]').isChecked(),
    ).toBe(true);
    expect(
      await adminPage.locator('#cp-services-list input[data-service-id="paypal"]').isChecked(),
    ).toBe(false);

    const persistedBefore = wpEval(
      `$d = (array) get_option('faz_cookie_policy_data', array()); echo wp_json_encode($d['third_party_services'] ?? array());`,
    ).trim();
    expect(persistedBefore).toBe('[]');
  });

  // ============================================================
  // Extended coverage (tests 11–25). Each test is self-contained:
  // beforeEach wipes scanned cookies + saved selection, so the
  // suite is order-independent and safe to re-run / shard.
  // ============================================================

  test('11. Bundled domain-to-service.json parses cleanly and every service ID is in the API allowlist', async () => {
    // Cross-checks the PHP allowlist against the JSON map server-side
    // via wpEval — keeps the assertion authoritative (any drift between
    // the map and sanitize_settings's allowlist fails the test). Reading
    // the file from the test process would assert against the source
    // tree but not against the deployed plugin code that's serving
    // requests; wpEval mirrors what the running plugin sees.
    const result = wpEval(`
      $file = WP_PLUGIN_DIR . '/faz-cookie-manager/admin/modules/cookie-policy-generator/data/domain-to-service.json';
      $json = json_decode(file_get_contents($file), true);
      if (!is_array($json) || empty($json['mappings'])) { echo 'BAD_JSON'; exit; }
      $domains = array_keys($json['mappings']);
      $sids    = array();
      foreach ($json['mappings'] as $d) { foreach ((array) $d as $s) { $sids[$s] = true; } }
      $src     = file_get_contents(WP_PLUGIN_DIR . '/faz-cookie-manager/admin/modules/cookie-policy-generator/api/class-cookie-policy-api.php');
      preg_match('/\\$allowed_services = array\\((.*?)\\);/s', $src, $m);
      preg_match_all("/'([a-z0-9_]+)'/", $m[1], $hits);
      $allowed = array_flip($hits[1]);
      $unknown = array_diff_key($sids, $allowed);
      echo wp_json_encode(array(
        'domains_count' => count($domains),
        'sids_count'    => count($sids),
        'unknown'       => array_keys($unknown),
      ));
    `).trim();
    const parsed = JSON.parse(result);
    expect(parsed.domains_count).toBeGreaterThanOrEqual(50);
    expect(parsed.sids_count).toBeGreaterThanOrEqual(40);
    expect(parsed.unknown, `Map contains service IDs missing from sanitize_settings allowlist: ${parsed.unknown.join(', ')}`).toEqual([]);
  });

  test('12. Exact-match domain (no leading dot in the cookie row) still resolves to a service ID', async () => {
    // The helper does ltrim('.', $domain) before lookup, so a cookie
    // stored with bare 'googletagmanager.com' (no leading dot) must
    // still resolve. Some scanners (and some sites' Set-Cookie headers)
    // omit the leading dot on host-only cookies.
    plantCookies([
      { name: 'cp_bare', slug: 'cp-auto-bare', domain: 'googletagmanager.com' },
    ]);
    const r = await suggest();
    expect(r.service_ids).toContain('gtm');
  });

  test('13. Mixed-case domain (.LinkedIn.COM) normalises to lowercase and matches', async () => {
    plantCookies([
      { name: 'cp_case', slug: 'cp-auto-case', domain: '.LinkedIn.COM' },
    ]);
    const r = await suggest();
    expect(r.service_ids).toContain('linkedin');
  });

  test('14. Multiple cookies on the same mapped domain dedupe to one service ID', async () => {
    plantCookies([
      { name: '_ga',   slug: 'cp-auto-ga1', domain: '.google-analytics.com' },
      { name: '_gid',  slug: 'cp-auto-ga2', domain: '.google-analytics.com' },
      { name: '_gat',  slug: 'cp-auto-ga3', domain: '.google-analytics.com' },
    ]);
    const r = await suggest();
    const ga4Count = r.service_ids.filter((s) => s === 'ga4').length;
    expect(ga4Count, 'duplicate cookies on same domain should produce one service ID, not three').toBe(1);
  });

  test('15. Cookies from multiple categories surface as multiple service IDs (analytics + ad + embed + payment)', async () => {
    plantCookies([
      { name: 'cp_ga',  slug: 'cp-auto-mix-ga', domain: '.google-analytics.com' },
      { name: 'cp_tt',  slug: 'cp-auto-mix-tt', domain: '.tiktok.com' },
      { name: 'cp_yt',  slug: 'cp-auto-mix-yt', domain: '.youtube.com' },
      { name: 'cp_st',  slug: 'cp-auto-mix-st', domain: '.js.stripe.com' },
      { name: 'cp_cdn', slug: 'cp-auto-mix-cdn', domain: '.b-cdn.net' },
    ]);
    const r = await suggest();
    expect(r.service_ids).toEqual(expect.arrayContaining(['ga4', 'tiktok', 'youtube', 'stripe', 'bunnycdn']));
  });

  test('16. /detected-services with zero discovered cookies returns scan_available=false', async () => {
    // beforeEach already resetCookies()'d the test slugs, but real rows
    // from prior unrelated specs may still exist. Wipe everything to
    // assert the zero-rows path deterministically, then restore.
    const backup = wpEval(
      `global $wpdb; echo wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}faz_cookies WHERE discovered = 1", ARRAY_A));`,
    ).trim();
    wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}faz_cookies WHERE discovered = 1");`);
    try {
      const r = await detected();
      expect(r.scan_available).toBe(false);
      expect(r.service_ids).toEqual([]);
    } finally {
      // Restore so subsequent tests in this or other specs aren't affected.
      if (backup && backup !== '[]' && backup !== 'null') {
        wpEval(`
          global $wpdb;
          $rows = json_decode(${JSON.stringify(backup)}, true);
          if (is_array($rows)) {
            foreach ($rows as $row) {
              unset($row['id']);
              $wpdb->insert($wpdb->prefix . 'faz_cookies', $row);
            }
          }
        `);
      }
    }
  });

  test('17. Unauthenticated request to /suggest-services returns 401 — admin gate enforced', async ({ browser }) => {
    // Fresh context = no admin cookie. Bare request must be rejected.
    // Derive baseURL from the live admin page so the test stays portable
    // across local (nginx :9998), CI (random port), and any future
    // dynamic-port test harness. Hardcoding 'http://127.0.0.1:9998' here
    // breaks the moment WP_BASE_URL points elsewhere — CodeRabbit PR #127
    // review (2026-05-27) flagged this.
    const baseURL = new URL(adminPage.url()).origin;
    const anonCtx = await browser.newContext();
    const res = await anonCtx.request.get(baseURL + REST_BASE + '/suggest-services');
    // WP REST returns 401 (rest_forbidden) for cap-failed requests when
    // the user is unauthenticated. Some configs return 403 instead — accept either.
    expect([401, 403]).toContain(res.status());
    await anonCtx.close();
  });

  test('18. POST on /suggest-services is rejected — only GET is registered', async () => {
    // Sanity-check the verb whitelist. POST should hit a method-not-allowed
    // (405) or no-route (404) response, NOT 200.
    const res = await adminPage.request.post(`${REST_BASE}/suggest-services`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: { junk: true },
    });
    expect([404, 405]).toContain(res.status());
  });

  test('19. Auto-detect with NO matching cookies shows the "No matching services found" status', async () => {
    plantCookies([
      { name: 'cp_x', slug: 'cp-auto-nomatch', domain: '.example.org' },
    ]);
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.locator('summary', { hasText: 'Third-party services' }).click();
    await adminPage.waitForSelector('#cp-services-auto-detect', { timeout: 5_000 });
    await adminPage.click('#cp-services-auto-detect');
    await expect(adminPage.locator('#cp-services-auto-detect-status')).toContainText(
      /No matching services found/i,
      { timeout: 10_000 },
    );
    // No checkboxes ticked.
    const checkedCount = await adminPage.$$eval(
      '#cp-services-list input[type=checkbox]:checked',
      (els) => els.length,
    );
    expect(checkedCount).toBe(0);
  });

  test('20. Auto-detect when scanner has zero rows shows the "Run the cookie scanner first" hint', async () => {
    // Wipe ALL discovered rows so scan_available is false. Restore in finally.
    const backup = wpEval(
      `global $wpdb; echo wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}faz_cookies WHERE discovered = 1", ARRAY_A));`,
    ).trim();
    wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}faz_cookies WHERE discovered = 1");`);
    try {
      await adminPage.reload({ waitUntil: 'domcontentloaded' });
      await adminPage.locator('summary', { hasText: 'Third-party services' }).click();
      await adminPage.waitForSelector('#cp-services-auto-detect', { timeout: 5_000 });
      await adminPage.click('#cp-services-auto-detect');
      await expect(adminPage.locator('#cp-services-auto-detect-status')).toContainText(
        /Run the cookie scanner first/i,
        { timeout: 10_000 },
      );
    } finally {
      if (backup && backup !== '[]' && backup !== 'null') {
        wpEval(`
          global $wpdb;
          $rows = json_decode(${JSON.stringify(backup)}, true);
          if (is_array($rows)) {
            foreach ($rows as $row) {
              unset($row['id']);
              $wpdb->insert($wpdb->prefix . 'faz_cookies', $row);
            }
          }
        `);
      }
    }
  });

  test('21. End-to-end save flow: auto-detect → submit form → option persisted with the suggested IDs', async () => {
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-save-gtm', domain: '.googletagmanager.com' },
      { name: 'cp_st',  slug: 'cp-auto-save-st',  domain: '.js.stripe.com' },
    ]);
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.locator('summary', { hasText: 'Third-party services' }).click();
    await adminPage.waitForSelector('#cp-services-auto-detect', { timeout: 5_000 });
    await adminPage.click('#cp-services-auto-detect');
    await expect(adminPage.locator('#cp-services-auto-detect-status')).toContainText(
      /Pre-ticked \d+ new service\(s\), \d+ were already selected/i,
      { timeout: 10_000 },
    );
    // Confirm the form submit button exists and submit.
    await adminPage.click('button[type=submit]', { timeout: 5_000 });
    await expect(adminPage.locator('#cp-save-status')).toContainText(/Saved/i, { timeout: 10_000 });
    // Option should now contain BOTH IDs.
    const persisted = wpEval(
      `$d = (array) get_option('faz_cookie_policy_data', array()); echo wp_json_encode($d['third_party_services'] ?? array());`,
    ).trim();
    const arr = JSON.parse(persisted);
    expect(arr).toEqual(expect.arrayContaining(['gtm', 'stripe']));
  });

  test('22. Persistence: after save, refreshing the page restores the same ticked checkboxes', async () => {
    // Pre-seed the option directly to skip the auto-detect path — this
    // test is about the load-from-storage round trip.
    wpEval(
      `$d = (array) get_option('faz_cookie_policy_data', array()); $d['third_party_services'] = array('gads', 'tiktok'); update_option('faz_cookie_policy_data', $d, false);`,
    );
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.locator('summary', { hasText: 'Third-party services' }).click();
    await adminPage.waitForSelector('#cp-services-list input[data-service-id="gads"]', { timeout: 5_000 });
    // writeForm() runs after the parallel /settings+/detected fetches resolve.
    // Give it a beat — explicit wait on the box becoming checked.
    await expect(adminPage.locator('#cp-services-list input[data-service-id="gads"]')).toBeChecked({ timeout: 10_000 });
    await expect(adminPage.locator('#cp-services-list input[data-service-id="tiktok"]')).toBeChecked();
    // Unrelated boxes stay off.
    await expect(adminPage.locator('#cp-services-list input[data-service-id="youtube"]')).not.toBeChecked();
  });

  test('23. "Detected" badge appears only next to checkboxes for services the scanner has observed', async () => {
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-bd-gtm', domain: '.googletagmanager.com' },
      { name: 'cp_st',  slug: 'cp-auto-bd-st',  domain: '.js.stripe.com' },
    ]);
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.locator('summary', { hasText: 'Third-party services' }).click();
    // Wait for the re-render after /detected-services resolves: GTM's
    // label should now carry the badge.
    await expect(
      adminPage.locator('#cp-services-list label:has(input[data-service-id="gtm"]) .faz-svc-detected-badge'),
    ).toBeVisible({ timeout: 10_000 });
    await expect(
      adminPage.locator('#cp-services-list label:has(input[data-service-id="stripe"]) .faz-svc-detected-badge'),
    ).toBeVisible();
    // A service NOT planted (paypal) must not carry the badge.
    await expect(
      adminPage.locator('#cp-services-list label:has(input[data-service-id="paypal"]) .faz-svc-detected-badge'),
    ).toHaveCount(0);
  });

  test('24. Race-guard: rapid double click does not paint stale state — final status reflects the latest run', async () => {
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-race-gtm', domain: '.googletagmanager.com' },
    ]);
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.locator('summary', { hasText: 'Third-party services' }).click();
    await adminPage.waitForSelector('#cp-services-auto-detect', { timeout: 5_000 });
    // Two clicks back-to-back — the second invocation must win.
    // autoDetectRequestId increments per click; the stale .then() bails
    // before painting. We assert that, after both resolve, exactly ONE
    // success status is visible (not "Scanning…" hung from the first).
    await Promise.all([
      adminPage.click('#cp-services-auto-detect'),
      adminPage.click('#cp-services-auto-detect', { force: true }),
    ]);
    await expect(adminPage.locator('#cp-services-auto-detect-status')).toContainText(
      /Pre-ticked \d+ new service\(s\), \d+ were already selected|No matching services found|Scanning cookie inventory/i,
      { timeout: 15_000 },
    );
    // Allow the second response to settle, then re-check that the final
    // state is one of the terminal messages (not the in-flight "Scanning…").
    await adminPage.waitForFunction(
      () => {
        const el = document.getElementById('cp-services-auto-detect-status');
        return el !== null && !/Scanning/i.test(el.textContent || '');
      },
      undefined,
      { timeout: 10_000 },
    );
    // Button is re-enabled when both invocations have resolved.
    await expect(adminPage.locator('#cp-services-auto-detect')).toBeEnabled({ timeout: 5_000 });
  });

  test('25. Pre-existing admin selection is preserved: 5 non-scannered services stay ticked when Auto-detect adds a new one and Save is clicked', async () => {
    // Scenario: an admin already configured Third-party services manually
    // before this feature shipped — five services with no cookie footprint
    // on the live site (auth0, square, akismet, pardot, fcm). Then the
    // scanner observes a new domain (googletagmanager.com → gtm). Auto-
    // detect should propose ONLY the new one and SAVE must persist all
    // six together — pre-existing selections never get dropped.
    //
    // This is the composition test #5 + #10 + #22 implies but doesn't
    // assert end-to-end. Migration story for 1.16.4 hinges on it.
    const preExisting = ['auth0', 'square', 'akismet', 'pardot', 'fcm'];
    // JSON-bridge the array into PHP via json_decode — keeps the fixture
    // legible even as the seed list grows.
    wpEval(
      `$d = (array) get_option('faz_cookie_policy_data', array()); $d['third_party_services'] = json_decode(${JSON.stringify(JSON.stringify(preExisting))}, true); update_option('faz_cookie_policy_data', $d, false);`,
    );
    // Sanity: option was seeded with exactly the five entries.
    const seeded = JSON.parse(
      wpEval(
        `$d = (array) get_option('faz_cookie_policy_data', array()); echo wp_json_encode($d['third_party_services'] ?? array());`,
      ).trim(),
    );
    expect(seeded.sort()).toEqual([...preExisting].sort());

    // Plant a single discoverable cookie the curated map will resolve.
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-mig-gtm', domain: '.googletagmanager.com' },
    ]);

    // Reload so the admin page reads the freshly-seeded option AND the
    // freshly-planted scanner row.
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.locator('summary', { hasText: 'Third-party services' }).click();
    await adminPage.waitForSelector('#cp-services-list input[data-service-id="auth0"]', { timeout: 5_000 });

    // All five pre-existing checkboxes must be ticked at load time
    // (writeForm() runs after the parallel /settings+/detected-services
    // fetches resolve — the second renderServicesList() pass repaints
    // the labels with badges, then writeForm restores the checked state).
    for (const sid of preExisting) {
      await expect(
        adminPage.locator(`#cp-services-list input[data-service-id="${sid}"]`),
      ).toBeChecked({ timeout: 10_000 });
    }
    // GTM must NOT be ticked yet — the scanner saw the cookie but the
    // admin never accepted the suggestion.
    await expect(adminPage.locator('#cp-services-list input[data-service-id="gtm"]')).not.toBeChecked();

    // Click Auto-detect. Status should say "Pre-ticked 1 new service(s), 0
    // were already selected" — GTM is genuinely new, none of the five
    // pre-existing services have a cookie domain mapped to them in
    // domain-to-service.json.
    await adminPage.click('#cp-services-auto-detect');
    await expect(adminPage.locator('#cp-services-auto-detect-status')).toContainText(
      /Pre-ticked 1 new service\(s\), 0 were already selected\. Click Save to commit\./i,
      { timeout: 10_000 },
    );

    // All five pre-existing checkboxes STILL ticked — auto-detect must
    // not destick anything. GTM now ticked.
    for (const sid of preExisting) {
      await expect(
        adminPage.locator(`#cp-services-list input[data-service-id="${sid}"]`),
      ).toBeChecked();
    }
    await expect(adminPage.locator('#cp-services-list input[data-service-id="gtm"]')).toBeChecked();

    // Pre-save invariant: the option still holds ONLY the five originals
    // — deferred-save means the DOM tick is ephemeral until submit.
    const preSave = JSON.parse(
      wpEval(
        `$d = (array) get_option('faz_cookie_policy_data', array()); echo wp_json_encode($d['third_party_services'] ?? array());`,
      ).trim(),
    );
    expect(preSave.sort()).toEqual([...preExisting].sort());

    // Submit the form.
    await adminPage.click('button[type=submit]', { timeout: 5_000 });
    await expect(adminPage.locator('#cp-save-status')).toContainText(/Saved/i, { timeout: 10_000 });

    // Post-save: option contains exactly the six expected service IDs
    // — the five originals (preserved) plus GTM (newly accepted).
    const persisted = JSON.parse(
      wpEval(
        `$d = (array) get_option('faz_cookie_policy_data', array()); echo wp_json_encode($d['third_party_services'] ?? array());`,
      ).trim(),
    );
    expect(persisted, `expected pre-existing selection preserved + gtm added; got: ${persisted.join(', ')}`).toHaveLength(6);
    expect(persisted).toEqual(expect.arrayContaining([...preExisting, 'gtm']));
    // No phantom entries — array length matches the expected set exactly.
    const phantom = persisted.filter((s: string) => ![...preExisting, 'gtm'].includes(s));
    expect(phantom).toEqual([]);
  });

  test('26. Map loader caches the file read — repeated suggest calls produce identical responses without per-call I/O', async () => {
    // We can't directly observe disk I/O from E2E, but we CAN assert
    // that two back-to-back /suggest-services calls return identical
    // payloads (same order, same dedup, same allowlist filtering). If
    // the static cache regressed and a write between calls mutated the
    // file, payloads would diverge. This is the closest E2E proxy.
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-cache-gtm', domain: '.googletagmanager.com' },
      { name: 'cp_li',  slug: 'cp-auto-cache-li',  domain: '.linkedin.com' },
      { name: 'cp_yt',  slug: 'cp-auto-cache-yt',  domain: '.youtube.com' },
    ]);
    const a = await suggest();
    const b = await suggest();
    expect(b.service_ids).toEqual(a.service_ids);
    expect(b.already_selected).toEqual(a.already_selected);
    expect(b.newly_suggested).toEqual(a.newly_suggested);
    expect(b.scan_available).toEqual(a.scan_available);
  });

  test('27. F009: Auto-detect does NOT re-tick a detected service the admin unticked in-session (and still ticks the others)', async () => {
    // Two detected-but-unsaved services. The admin manually adds then
    // removes one (gtm) before saving; the other (stripe) is left alone.
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-f009-gtm', domain: '.googletagmanager.com' },
      { name: 'cp_st',  slug: 'cp-auto-f009-st',  domain: '.js.stripe.com' },
    ]);
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.locator('summary', { hasText: 'Third-party services' }).click();
    // Hydration must finish (writeForm clears the in-session untick map and
    // enables the button) before we interact, or the manual untick we record
    // would be wiped.
    await expect(adminPage.locator('#cp-services-auto-detect')).toBeEnabled({ timeout: 10_000 });

    const gtm = adminPage.locator('#cp-services-list input[data-service-id="gtm"]');
    const stripe = adminPage.locator('#cp-services-list input[data-service-id="stripe"]');
    // Both start unchecked — they are detected (newly) but not saved.
    await expect(gtm).not.toBeChecked();
    await expect(stripe).not.toBeChecked();

    // Admin manually ticks gtm, then unticks it — a deliberate in-session
    // removal. Each click fires 'change', which the delegated listener records.
    await gtm.click();
    await expect(gtm).toBeChecked();
    await gtm.click();
    await expect(gtm).not.toBeChecked();

    // Run Auto-detect.
    await adminPage.click('#cp-services-auto-detect');
    // gtm stays UNCHECKED (skipped — the admin removed it); stripe IS ticked
    // (auto-detect still works for services the admin didn't touch).
    await expect(stripe).toBeChecked({ timeout: 10_000 });
    await expect(gtm).not.toBeChecked();
    // Status reflects only the one actually pre-ticked, not the raw count.
    await expect(adminPage.locator('#cp-services-auto-detect-status')).toContainText(
      /Pre-ticked 1 new service\(s\)/i,
      { timeout: 10_000 },
    );
  });

  test('28. F009: a successful save resets the baseline — a later Auto-detect re-suggests the removed service', async () => {
    plantCookies([
      { name: 'cp_gtm', slug: 'cp-auto-f009b-gtm', domain: '.googletagmanager.com' },
    ]);
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.locator('summary', { hasText: 'Third-party services' }).click();
    await expect(adminPage.locator('#cp-services-auto-detect')).toBeEnabled({ timeout: 10_000 });

    const gtm = adminPage.locator('#cp-services-list input[data-service-id="gtm"]');
    await expect(gtm).not.toBeChecked();

    // Tick then untick gtm → in-session removal recorded. Await the checked
    // state BETWEEN the two clicks so each toggle's 'change' event is
    // processed before the next (a rapid double-click can drop the second
    // 'change', leaving the untick unrecorded).
    await gtm.click();
    await expect(gtm).toBeChecked();
    await gtm.click();
    await expect(gtm).not.toBeChecked();

    // First Auto-detect: gtm is skipped, nothing pre-ticked.
    await adminPage.click('#cp-services-auto-detect');
    await expect(adminPage.locator('#cp-services-auto-detect-status')).toContainText(
      /left unticked/i,
      { timeout: 10_000 },
    );
    await expect(gtm).not.toBeChecked();

    // Save (persists third_party_services = [] — gtm stays unselected).
    await adminPage.click('button[type=submit]');
    await expect(adminPage.locator('#cp-save-status')).toContainText(/Saved/i, { timeout: 10_000 });

    // Saved state is the new baseline: the in-session untick is cleared, so a
    // second Auto-detect re-suggests gtm and ticks it.
    await adminPage.click('#cp-services-auto-detect');
    await expect(gtm).toBeChecked({ timeout: 10_000 });
    await expect(adminPage.locator('#cp-services-auto-detect-status')).toContainText(
      /Pre-ticked 1 new service\(s\)/i,
      { timeout: 10_000 },
    );
  });
});
