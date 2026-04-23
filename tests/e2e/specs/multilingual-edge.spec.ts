import { completeAdminLogin, expect, test } from '../fixtures/wp-fixture';
import {
  emulateNavigatorLanguages,
  readFazConfig,
  restoreLanguages,
  setSelectedLanguages,
  waitForBannerReady,
  type LanguagesSnapshot,
} from '../utils/multilingual';

/**
 * Multilingual edge-case coverage (post-audit 2026-04-23).
 *
 * Covers gaps identified by the graph audit over the issue #67 / PR #68
 * work:
 *   - `send_vary_header` is a cache-safety choke point — if it silently
 *     stops emitting `Vary: Accept-Language`, CDNs would start serving the
 *     wrong language to users and the #67 regression reappears.
 *   - `_fazResolveBrowserLanguage` has three decision branches (exact
 *     match / region stripping / no match) that the existing happy-path
 *     spec only probes implicitly.
 *   - `_fazMaybeSwapLanguage` must be a no-op when the detected language
 *     already matches the server default, otherwise a redundant REST fetch
 *     fires on every page load.
 *
 * We only select languages whose .mo file ships inside `languages/` so the
 * REST endpoint can return a real translated payload — otherwise the swap
 * observed from the frontend would be indistinguishable from the default
 * language, and the test would assert on a no-op. `de` is bundled
 * (languages/faz-cookie-manager-de_DE.mo) and keeps the region-strip
 * assertion meaningful (de-AT → de).
 */

const SUITE_SELECTED = ['en', 'it', 'de'];
const SUITE_DEFAULT = 'en';

test.describe.serial('Multilingual edge cases', () => {
  let snapshot: LanguagesSnapshot | null = null;

  test.beforeAll(async ({ browser, request }) => {
    const wpBaseURL = process.env.WP_BASE_URL ?? 'http://localhost:9998';
    const adminUser = process.env.WP_ADMIN_USER ?? 'admin';
    const adminPass = process.env.WP_ADMIN_PASS ?? 'admin';

    const context = await browser.newContext();
    const page = await context.newPage();
    await completeAdminLogin(page, wpBaseURL, adminUser, adminPass);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    snapshot = await setSelectedLanguages(page, SUITE_SELECTED, SUITE_DEFAULT);
    await context.close();

    // Warm up the frontend after the language change: the first request that
    // hits the homepage after `faz_settings` changes is responsible for
    // regenerating the cached banner template and priming the REST layer.
    // Without this a per-test `goto` can race the regeneration and see a
    // stale config for a handful of ms, which was the exact failure mode
    // observed for the send_vary_header assertion during the first pass.
    await request.get(wpBaseURL, {
      headers: { 'Accept-Language': 'en-US,en;q=0.9' },
    });
  });

  test.afterAll(async ({ browser }) => {
    if (!snapshot) return;
    const wpBaseURL = process.env.WP_BASE_URL ?? 'http://localhost:9998';
    const adminUser = process.env.WP_ADMIN_USER ?? 'admin';
    const adminPass = process.env.WP_ADMIN_PASS ?? 'admin';

    const context = await browser.newContext();
    const page = await context.newPage();
    await completeAdminLogin(page, wpBaseURL, adminUser, adminPass);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    await restoreLanguages(page, snapshot);
    await context.close();
  });

  test('send_vary_header emits "Vary: Accept-Language" when browser detection is active', async ({ request, wpBaseURL }) => {
    // Fresh request from a non-admin, non-AJAX context — send_headers fires
    // and the hook should append Accept-Language to Vary.
    const response = await request.get(wpBaseURL, {
      headers: { 'Accept-Language': 'en-US,en;q=0.9' },
    });
    const vary = response.headers()['vary'] ?? '';
    expect(vary, 'Vary header must contain Accept-Language for CDN correctness').toMatch(/Accept-Language/i);
  });

  test('_fazResolveBrowserLanguage strips region when only the base language is available', async ({ browser, wpBaseURL }) => {
    // navigator.languages[0] = 'de-AT'. Plugin has 'de' selected but not
    // 'de-AT'. After the base split the resolver must return 'de' and the
    // swap must rewrite _fazStore._language accordingly.
    const context = await browser.newContext();
    await emulateNavigatorLanguages(context, ['de-AT', 'en-US']);
    const page = await context.newPage();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await waitForBannerReady(page, 10_000, 'de');
    const cfg = await readFazConfig(page);
    expect(cfg?._language, 'region stripping must resolve de-AT → de').toBe('de');
    await context.close();
  });

  test('_fazResolveBrowserLanguage falls back to server default when no navigator language matches', async ({ browser, wpBaseURL }) => {
    // None of the preferred languages exist in _availableLanguages; the
    // resolver returns null and the swap is skipped, leaving _language
    // at the server-rendered default ('en').
    const context = await browser.newContext();
    await emulateNavigatorLanguages(context, ['ja-JP', 'ko-KR']);
    const page = await context.newPage();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await waitForBannerReady(page, 10_000);
    const cfg = await readFazConfig(page);
    expect(cfg?._language, 'unmatched navigator.languages must leave _language at server default').toBe(SUITE_DEFAULT);
    await context.close();
  });

  test('_fazMaybeSwapLanguage does not fetch when navigator language matches server default', async ({ browser, wpBaseURL }) => {
    // navigator.languages[0] resolves to 'en' which already equals the
    // server default. The swap must short-circuit (`detected === _language`)
    // without hitting /faz/v1/banner/ — otherwise every page load would
    // pay a redundant REST round-trip.
    const context = await browser.newContext();
    await emulateNavigatorLanguages(context, ['en-US', 'en']);
    const page = await context.newPage();
    const bannerFetches: string[] = [];
    page.on('request', (req) => {
      if (req.url().includes('/faz/v1/banner/')) {
        bannerFetches.push(req.url());
      }
    });
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await waitForBannerReady(page, 10_000);
    // Give the async swap a beat to fire if it was going to.
    await page.waitForTimeout(750);
    expect(bannerFetches, 'no banner REST fetch expected when language already matches').toHaveLength(0);
    await context.close();
  });
});
