import { completeAdminLogin, expect, test } from '../fixtures/wp-fixture';
import {
  emulateNavigatorLanguages,
  fetchBannerPayload,
  readFazConfig,
  restoreLanguages,
  setSelectedLanguages,
  waitForBannerReady,
  type LanguagesSnapshot,
} from '../utils/multilingual';

/**
 * Issue #67 — Multilingual banner is cache-safe and swaps client-side.
 *
 * These tests exercise the full language-detection pipeline:
 *   - the server must render the banner in the *default* language
 *     regardless of Accept-Language (cacheable output),
 *   - the REST endpoint /faz/v1/banner/{lang} must return language-
 *     specific payloads for every selected language,
 *   - script.js must perform a navigator.languages-based swap on load
 *     when the browser's preferred language differs from the server
 *     default.
 *
 * The tests configure a multilingual setup (en + it) in `test.beforeAll`
 * and restore the original snapshot in `test.afterAll`, so they are
 * reusable on any FAZ install regardless of its current language config.
 */

// Languages provisioned for the duration of the suite. Any two plugin
// languages work — we pick en+it because they are bundled by default.
const SUITE_SELECTED = ['en', 'it'];
const SUITE_DEFAULT = 'en';

test.describe.serial('Issue #67 — multilingual banner', () => {
  let snapshot: LanguagesSnapshot | null = null;

  test.beforeAll(async ({ browser }) => {
    // Provision the suite's language configuration once. Using the raw
    // `completeAdminLogin` helper keeps the provisioning outside of the
    // per-test fixture scope (loginAsAdmin is test-scoped only).
    const wpBaseURL = process.env.WP_BASE_URL ?? 'http://localhost:9998';
    const adminUser = process.env.WP_ADMIN_USER ?? 'admin';
    const adminPass = process.env.WP_ADMIN_PASS ?? 'admin';

    const context = await browser.newContext();
    const page = await context.newPage();
    await completeAdminLogin(page, wpBaseURL, adminUser, adminPass);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    snapshot = await setSelectedLanguages(page, SUITE_SELECTED, SUITE_DEFAULT);
    await context.close();
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

  test('1. Server HTML is cache-safe — identical response for any Accept-Language', async ({ request, wpBaseURL }) => {
    // Extract just the chunk of the HTML the banner store ends up in.
    // Comparing full pages would be brittle because nonces, timestamps
    // and random IDs differ across requests. Instead we isolate the
    // language field of the localized config.
    const extractLanguage = (html: string): string | null => {
      const match = html.match(/"_language"\s*:\s*"([^"]+)"/);
      return match ? match[1] : null;
    };

    const itFirst = await request.get(wpBaseURL, {
      headers: { 'Accept-Language': 'it-IT,it;q=0.9,en;q=0.5' },
    });
    const enFirst = await request.get(wpBaseURL, {
      headers: { 'Accept-Language': 'en-US,en;q=0.9' },
    });

    expect(itFirst.status()).toBe(200);
    expect(enFirst.status()).toBe(200);

    // Vary: Accept-Language must be emitted whenever browser detection is
    // active so shared caches (Cloudflare, LiteSpeed, WP Rocket, …) key
    // variants per language when they honour the header. This asserts the
    // complementary half of the PR — the body is cacheable AND the
    // downstream cache knows it should segment by Accept-Language.
    const itVary = (itFirst.headers().vary ?? '').toLowerCase();
    const enVary = (enFirst.headers().vary ?? '').toLowerCase();
    expect(itVary).toContain('accept-language');
    expect(enVary).toContain('accept-language');

    const itLang = extractLanguage(await itFirst.text());
    const enLang = extractLanguage(await enFirst.text());

    // Both requests must pin _language to the configured default — never
    // to whatever the visitor's Accept-Language header says. This is the
    // core invariant that makes the banner safe to cache.
    expect(itLang).toBe(SUITE_DEFAULT);
    expect(enLang).toBe(SUITE_DEFAULT);
  });

  test('2. REST endpoint returns language-specific payload for every selected language', async ({ request, wpBaseURL }) => {
    const bodies: Record<string, any> = {};
    for (const lang of SUITE_SELECTED) {
      const { status, body } = await fetchBannerPayload(request, wpBaseURL, lang);
      expect(status, `Expected 200 for /banner/${lang}`).toBe(200);
      expect(body).toMatchObject({
        language: lang,
        html: expect.any(String),
      });
      expect(Array.isArray(body.shortCodes)).toBe(true);
      expect(Array.isArray(body.categories)).toBe(true);
      expect(typeof body.i18n).toBe('object');
      bodies[lang] = body;
    }
    // Language field must be echoed distinctly per request — proves the
    // endpoint actually routes the `lang` parameter rather than always
    // returning the default.
    const uniqueLanguages = new Set(Object.values(bodies).map((b) => b.language));
    expect(uniqueLanguages.size).toBe(SUITE_SELECTED.length);
  });

  test('3. navigator.languages override triggers a client-side swap to the matching language', async ({ browser, wpBaseURL }) => {
    // A non-default language from SUITE_SELECTED to prove the swap moved
    // _fazStore._language away from the server-rendered default.
    const target = SUITE_SELECTED.find((l) => l !== SUITE_DEFAULT) ?? SUITE_SELECTED[0];

    const context = await browser.newContext();
    await emulateNavigatorLanguages(context, [`${target}-XX`, target, SUITE_DEFAULT]);
    const page = await context.newPage();

    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    // Wait deterministically for the async swap to finish — passing the
    // target language makes the poll settle only after _fazStore._language
    // has been rewritten, not just after the script has loaded. 10s is
    // generous enough that a slow REST round-trip (PHP-FPM spinning up,
    // first-render template regeneration, or CI under load) does not
    // produce a false-fail while still catching real regressions where
    // the swap never happens.
    await waitForBannerReady(page, 10_000, target);

    const cfg = await readFazConfig(page);
    expect(cfg).not.toBeNull();
    expect(cfg!._availableLanguages).toEqual(expect.arrayContaining(SUITE_SELECTED));
    // Core assertion: the JS pipeline picked up the emulated preference
    // and rewrote _fazStore._language to the detected language.
    expect(cfg!._language).toBe(target);

    await context.close();
  });

  test('4. Unsupported navigator.languages falls back to the server default', async ({ browser, wpBaseURL }) => {
    // Japanese and Arabic are deliberately absent from SUITE_SELECTED.
    // The swap pipeline must treat these as "no match" and keep the
    // server-rendered default.
    const context = await browser.newContext();
    await emulateNavigatorLanguages(context, ['ja-JP', 'ja', 'ar']);
    const page = await context.newPage();

    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    // For the fallback path the expected language is the default — the
    // swap should be a no-op so waiting for SUITE_DEFAULT confirms both
    // that the script ran and that nothing rewrote _language.
    await waitForBannerReady(page, 3000, SUITE_DEFAULT);

    const cfg = await readFazConfig(page);
    expect(cfg).not.toBeNull();
    expect(cfg!._language).toBe(SUITE_DEFAULT);

    await context.close();
  });
});
