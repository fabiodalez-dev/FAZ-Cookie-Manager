import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { wpEval } from '../utils/wp-env';

/**
 * E2E tests for two features implemented in feat/experimental-features:
 *
 * 1. Web Storage cookie shredder — _fazCleanupRevokedCookies() now also
 *    removes matching localStorage and sessionStorage keys when a category
 *    is rejected or revoked.
 *
 * 2. Per-cookie opt-in/out scripts — admins can define JavaScript snippets
 *    per cookie that execute when the cookie's category transitions between
 *    accepted and rejected states.
 */

// ── Helpers ──────────────────────────────────────────────────────────────────

async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '');
}

async function createCookie(
  page: Page,
  nonce: string,
  baseURL: string,
  payload: Record<string, unknown>,
): Promise<number> {
  const res = await page.request.post(`${baseURL}/?rest_route=/faz/v1/cookies`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: payload,
  });
  const body = await res.json() as Record<string, unknown>;
  const id = typeof body.id === 'number' ? body.id : 0;
  expect(id, `createCookie: got no id — response: ${JSON.stringify(body)}`).toBeGreaterThan(0);
  return id;
}

async function deleteCookie(page: Page, nonce: string, baseURL: string, id: number): Promise<void> {
  await page.request.delete(`${baseURL}/?rest_route=/faz/v1/cookies/${id}`, {
    headers: { 'X-WP-Nonce': nonce },
  });
}

async function updateCookie(
  page: Page,
  nonce: string,
  baseURL: string,
  id: number,
  payload: Record<string, unknown>,
): Promise<void> {
  const res = await page.request.post(`${baseURL}/?rest_route=/faz/v1/cookies/${id}`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: payload,
  });
  expect(res.status(), 'updateCookie: unexpected status').toBe(200);
}

/** Accept all consent on the current page. */
async function acceptAll(page: Page): Promise<void> {
  const acceptBtn = page.locator('[data-faz-tag="accept-button"]');
  await acceptBtn.waitFor({ state: 'visible', timeout: 10_000 });
  await acceptBtn.click();
}

/** Reject all consent on the current page. */
async function rejectAll(page: Page): Promise<void> {
  const rejectBtn = page.locator('[data-faz-tag="reject-button"]');
  await rejectBtn.waitFor({ state: 'visible', timeout: 10_000 });
  await rejectBtn.click();
}

/**
 * Wait for the fazcookie-consent cookie to appear as a signal that _fazAfterConsent
 * has completed (and therefore the Web Storage shredder has run).
 * Use after clearCookies() + a consent action; resolves as soon as the cookie is set.
 */
async function waitForConsentCookie(page: Page): Promise<void> {
  await page.waitForFunction(
    () => document.cookie.includes('fazcookie-consent='),
    { timeout: 5_000 },
  );
}

/** Pre-seed a consent cookie so the banner does not block interactions. */
async function setConsentCookie(page: Page, wpBaseURL: string): Promise<void> {
  await page.context().addCookies([{
    name:     'fazcookie-consent',
    value:    'consentid%3Ae2e-test%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2Cfunctional%3Ayes%2Canalytics%3Ayes%2Cperformance%3Ayes%2Cuncategorized%3Ayes%2Cmarketing%3Ayes%2Crev%3A5',
    domain:   '127.0.0.1',
    path:     '/',
    sameSite: 'Lax',
  }]);
}

// ── Web Storage Shredder tests ─────────────────────────────────────────────

test.describe('Web Storage cookie shredder', () => {

  // Ensure each test starts with clean storage regardless of assertion failures.
  test.afterEach(async ({ page }) => {
    await page.evaluate(() => {
      localStorage.clear();
      sessionStorage.clear();
    });
  });

  test('localStorage key matching a blocked category pattern is removed on reject', async ({
    page, wpBaseURL,
  }) => {
    // Load page fresh (no consent) so the banner shows.
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });

    // Accept all first so _cookieCategoryMap is available in the store.
    await acceptAll(page);

    // Inject a localStorage key matching the _ga pattern (analytics category).
    await page.evaluate(() => { localStorage.setItem('_ga', 'GA1.2.test'); });
    expect(await page.evaluate(() => localStorage.getItem('_ga'))).toBe('GA1.2.test');

    // Navigate back fresh (consent cookie persists); now open preference center.
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });

    // Reload with the banner visible by clearing the consent cookie.
    await page.context().clearCookies();
    await page.evaluate(() => { localStorage.setItem('_ga', 'GA1.2.test'); });
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });

    await rejectAll(page);
    await waitForConsentCookie(page);

    const gaAfter = await page.evaluate(() => localStorage.getItem('_ga'));
    expect(gaAfter, '_ga should be removed after rejecting analytics').toBeNull();
  });

  test('sessionStorage key matching a blocked pattern is removed on reject', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { sessionStorage.setItem('_ga', 'GA1.2.sess'); });

    await rejectAll(page);
    await waitForConsentCookie(page);

    const after = await page.evaluate(() => sessionStorage.getItem('_ga'));
    expect(after, '_ga in sessionStorage should be removed on reject').toBeNull();
  });

  test('localStorage key that does NOT match any pattern is preserved on reject', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { localStorage.setItem('my_app_theme', 'dark'); });

    await rejectAll(page);
    await waitForConsentCookie(page);

    const after = await page.evaluate(() => localStorage.getItem('my_app_theme'));
    expect(after, 'non-tracking localStorage key must survive reject').toBe('dark');
  });

  test('fazcookie-consent localStorage key is never deleted by shredder', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    // Simulate another script that stored the consent string in localStorage.
    await page.evaluate(() => { localStorage.setItem('fazcookie-consent', 'test-value'); });

    await rejectAll(page);
    await waitForConsentCookie(page);

    const after = await page.evaluate(() => localStorage.getItem('fazcookie-consent'));
    expect(after, 'fazcookie-consent must never be deleted').toBe('test-value');

    // Cleanup
    await page.evaluate(() => { localStorage.removeItem('fazcookie-consent'); });
  });

  test('wildcard pattern _ga_* removes matching localStorage keys', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => {
      localStorage.setItem('_ga_ABCDEF123', 'value1');
      localStorage.setItem('_ga_ZYXWVUT987', 'value2');
    });

    await rejectAll(page);
    await waitForConsentCookie(page);

    const val1 = await page.evaluate(() => localStorage.getItem('_ga_ABCDEF123'));
    const val2 = await page.evaluate(() => localStorage.getItem('_ga_ZYXWVUT987'));
    expect(val1, '_ga_ABCDEF123 should be removed').toBeNull();
    expect(val2, '_ga_ZYXWVUT987 should be removed').toBeNull();
  });

  test('multiple matching localStorage keys are all removed', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => {
      localStorage.setItem('_ga',     'ga-val');
      localStorage.setItem('_gid',    'gid-val');
      localStorage.setItem('_gcl_au', 'gcl-val');
    });

    await rejectAll(page);
    await waitForConsentCookie(page);

    const ga  = await page.evaluate(() => localStorage.getItem('_ga'));
    const gid = await page.evaluate(() => localStorage.getItem('_gid'));
    const gcl = await page.evaluate(() => localStorage.getItem('_gcl_au'));
    expect(ga,  '_ga removed').toBeNull();
    expect(gid, '_gid removed').toBeNull();
    expect(gcl, '_gcl_au removed').toBeNull();
  });

  test('localStorage not cleared when category is accepted', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { localStorage.setItem('_ga', 'GA1.2.keep'); });

    await acceptAll(page);
    await waitForConsentCookie(page);

    const after = await page.evaluate(() => localStorage.getItem('_ga'));
    expect(after, '_ga should NOT be removed when analytics is accepted').toBe('GA1.2.keep');
  });

  test('shredder survives an empty localStorage without errors', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { localStorage.clear(); sessionStorage.clear(); });

    // Should not throw — reject should complete normally.
    await rejectAll(page);
    await waitForConsentCookie(page);
    await expect(page.locator('#fazBannerTemplate')).toBeAttached();
  });

  test('sessionStorage key is preserved when its category remains accepted', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { sessionStorage.setItem('_ga', 'keep-me'); });

    await acceptAll(page);
    await waitForConsentCookie(page);

    const after = await page.evaluate(() => sessionStorage.getItem('_ga'));
    expect(after, '_ga stays in sessionStorage when analytics accepted').toBe('keep-me');
  });

  test('unrecognised key in sessionStorage is preserved on reject', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { sessionStorage.setItem('totally_custom_key_xyz', '123'); });

    await rejectAll(page);
    await waitForConsentCookie(page);

    const after = await page.evaluate(() => sessionStorage.getItem('totally_custom_key_xyz'));
    expect(after, 'unrelated sessionStorage key preserved').toBe('123');

    await page.evaluate(() => { sessionStorage.removeItem('totally_custom_key_xyz'); });
  });

  test('both localStorage and sessionStorage are cleaned in a single reject action', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => {
      localStorage.setItem('_ga',  'ls-val');
      sessionStorage.setItem('_ga', 'ss-val');
    });

    await rejectAll(page);
    await waitForConsentCookie(page);

    const ls = await page.evaluate(() => localStorage.getItem('_ga'));
    const ss = await page.evaluate(() => sessionStorage.getItem('_ga'));
    expect(ls, '_ga removed from localStorage').toBeNull();
    expect(ss, '_ga removed from sessionStorage').toBeNull();
  });
});

// ── Per-cookie opt-in/out scripts ─────────────────────────────────────────

test.describe('Per-cookie opt-in/out consent scripts', () => {
  // These tests use the REST API to create/modify cookies and verify script execution.
  test.describe.configure({ mode: 'serial' });

  let adminPage: Page;
  let nonce: string;
  let baseURL = '';
  /** ID of the analytics cookie created for these tests. */
  let testCookieId = 0;
  /** ID of a second analytics cookie for multi-cookie tests. */
  let testCookieId2 = 0;
  /** Resolved analytics category_id (dynamic to handle category recreations). */
  let analyticsCategoryId = 0;

  test.beforeAll(async ({ browser, wpBaseURL, loginAsAdmin }) => {
    baseURL = wpBaseURL;
    adminPage = await browser.newPage();
    adminPage.setDefaultNavigationTimeout(30_000);
    await loginAsAdmin(adminPage);
    await adminPage.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });
    nonce = await getAdminNonce(adminPage);
    expect(nonce.length, 'nonce must be non-empty').toBeGreaterThan(0);

    // Resolve the analytics category ID dynamically to avoid hardcoding.
    analyticsCategoryId = parseInt(
      wpEval(`
        global $wpdb;
        echo (int) $wpdb->get_var(
          $wpdb->prepare(
            "SELECT category_id FROM {$wpdb->prefix}faz_cookie_categories WHERE slug = %s",
            'analytics'
          )
        );
      `).trim(),
      10,
    );
    expect(analyticsCategoryId, 'analytics category must exist in DB').toBeGreaterThan(0);

    testCookieId = await createCookie(adminPage, nonce, wpBaseURL, {
      name:            '_faz_e2e_script_test',
      slug:            '_faz_e2e_script_test',
      domain:          '127.0.0.1',
      category:        analyticsCategoryId,
      duration:        { en: 'session' },
      description:     { en: 'E2E opt-in/out script test cookie' },
      opt_in_script:   "window._fazE2EOptIn = (window._fazE2EOptIn || 0) + 1;",
      opt_out_script:  "window._fazE2EOptOut = (window._fazE2EOptOut || 0) + 1;",
    });

    testCookieId2 = await createCookie(adminPage, nonce, wpBaseURL, {
      name:            '_faz_e2e_script_test2',
      slug:            '_faz_e2e_script_test2',
      domain:          '127.0.0.1',
      category:        analyticsCategoryId,
      duration:        { en: 'session' },
      description:     { en: 'E2E second script test cookie' },
      opt_in_script:   "window._fazE2EOptIn2 = true;",
      opt_out_script:  "window._fazE2EOptOut2 = true;",
    });
  });

  test.afterAll(async () => {
    if (testCookieId)  await deleteCookie(adminPage, nonce, baseURL, testCookieId);
    if (testCookieId2) await deleteCookie(adminPage, nonce, baseURL, testCookieId2);
    await adminPage.close();
  });

  // ── REST API schema ────────────────────────────────────────────────────

  test('cookies REST endpoint returns opt_in_script field', async ({ wpBaseURL }) => {
    const res = await adminPage.request.get(
      `${wpBaseURL}/?rest_route=/faz/v1/cookies/${testCookieId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    expect(res.status()).toBe(200);
    const body = await res.json() as Record<string, unknown>;
    expect(typeof body.opt_in_script).toBe('string');
  });

  test('cookies REST endpoint returns opt_out_script field', async ({ wpBaseURL }) => {
    const res = await adminPage.request.get(
      `${wpBaseURL}/?rest_route=/faz/v1/cookies/${testCookieId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    expect(res.status()).toBe(200);
    const body = await res.json() as Record<string, unknown>;
    expect(typeof body.opt_out_script).toBe('string');
  });

  test('opt_in_script value is preserved through API round-trip', async ({ wpBaseURL }) => {
    const res = await adminPage.request.get(
      `${wpBaseURL}/?rest_route=/faz/v1/cookies/${testCookieId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    const body = await res.json() as Record<string, unknown>;
    expect(body.opt_in_script).toBe("window._fazE2EOptIn = (window._fazE2EOptIn || 0) + 1;");
  });

  test('opt_out_script value is preserved through API round-trip', async ({ wpBaseURL }) => {
    const res = await adminPage.request.get(
      `${wpBaseURL}/?rest_route=/faz/v1/cookies/${testCookieId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    const body = await res.json() as Record<string, unknown>;
    expect(body.opt_out_script).toBe("window._fazE2EOptOut = (window._fazE2EOptOut || 0) + 1;");
  });

  test('opt_in_script can be updated via POST to the cookie endpoint', async ({ wpBaseURL }) => {
    await updateCookie(adminPage, nonce, baseURL, testCookieId, {
      opt_in_script: "window._fazE2EOptInV2 = true;",
    });
    const res = await adminPage.request.get(
      `${wpBaseURL}/?rest_route=/faz/v1/cookies/${testCookieId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    const body = await res.json() as Record<string, unknown>;
    expect(body.opt_in_script).toBe("window._fazE2EOptInV2 = true;");

    // Restore original for subsequent tests.
    await updateCookie(adminPage, nonce, baseURL, testCookieId, {
      opt_in_script: "window._fazE2EOptIn = (window._fazE2EOptIn || 0) + 1;",
    });
  });

  test('other cookie fields are not lost when updating only opt_in_script', async ({
    wpBaseURL,
  }) => {
    await updateCookie(adminPage, nonce, baseURL, testCookieId, {
      opt_in_script: "window._fazPreserveTest = 1;",
    });
    const res = await adminPage.request.get(
      `${wpBaseURL}/?rest_route=/faz/v1/cookies/${testCookieId}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    const body = await res.json() as Record<string, unknown>;
    expect(body.name, 'name preserved').toBe('_faz_e2e_script_test');
    expect(body.opt_out_script, 'opt_out_script preserved').toBe(
      "window._fazE2EOptOut = (window._fazE2EOptOut || 0) + 1;",
    );

    // Restore.
    await updateCookie(adminPage, nonce, baseURL, testCookieId, {
      opt_in_script: "window._fazE2EOptIn = (window._fazE2EOptIn || 0) + 1;",
    });
  });

  test('new cookie without scripts defaults opt_in_script and opt_out_script to empty string', async ({
    wpBaseURL,
  }) => {
    // Create a cookie that intentionally omits both script fields.
    const tmpId = await createCookie(adminPage, nonce, wpBaseURL, {
      name:        '_faz_e2e_no_scripts',
      slug:        '_faz_e2e_no_scripts',
      domain:      '127.0.0.1',
      category:    analyticsCategoryId,
      duration:    { en: 'session' },
      description: { en: 'cookie without scripts' },
    });
    try {
      const res = await adminPage.request.get(
        `${wpBaseURL}/?rest_route=/faz/v1/cookies/${tmpId}&context=edit`,
        { headers: { 'X-WP-Nonce': nonce } },
      );
      const body = await res.json() as Record<string, unknown>;
      expect(body.opt_in_script  ?? '').toBe('');
      expect(body.opt_out_script ?? '').toBe('');
    } finally {
      await deleteCookie(adminPage, nonce, wpBaseURL, tmpId);
    }
  });

  // ── Frontend store ─────────────────────────────────────────────────────

  test('_cookieScripts key appears in frontend store when scripts exist', async ({
    page, wpBaseURL,
  }) => {
    await setConsentCookie(page, wpBaseURL);
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    const scripts = await page.evaluate(
      () => (window as Record<string, unknown> & { _fazConfig?: Record<string, unknown> })._fazConfig?._cookieScripts,
    );
    expect(scripts, '_cookieScripts should be present in store').toBeTruthy();
  });

  test('_cookieScripts is grouped by category slug', async ({ page, wpBaseURL }) => {
    await setConsentCookie(page, wpBaseURL);
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    const scripts = await page.evaluate(
      () => (window as Record<string, unknown> & { _fazConfig?: Record<string, unknown> })._fazConfig?._cookieScripts as Record<string, unknown> | undefined,
    );
    expect(scripts && typeof scripts === 'object').toBeTruthy();
    expect('analytics' in (scripts ?? {}), 'analytics slug present in _cookieScripts').toBeTruthy();
  });

  test('_cookieScripts.analytics.opt_in contains the test script', async ({
    page, wpBaseURL,
  }) => {
    await setConsentCookie(page, wpBaseURL);
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    type Scripts = { opt_in: string[]; opt_out: string[] };
    const optInScripts = await page.evaluate(() => {
      const s = (window as Record<string, unknown> & { _fazConfig?: { _cookieScripts?: Record<string, Scripts> } })
        ._fazConfig?._cookieScripts?.['analytics']?.opt_in ?? [];
      return s;
    });
    expect(Array.isArray(optInScripts)).toBeTruthy();
    const hasExpected = optInScripts.some((s: string) => s.includes('_fazE2EOptIn'));
    expect(hasExpected, 'opt_in array contains test script').toBeTruthy();
  });

  // ── Script execution ───────────────────────────────────────────────────

  test('opt_in_script executes when analytics category transitions to accepted', async ({
    page, wpBaseURL,
  }) => {
    // Start with no consent so the banner shows.
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });

    // Reset the global counter before acting.
    await page.evaluate(() => { delete (window as Record<string, unknown>)._fazE2EOptIn; });

    await acceptAll(page);
    await page.waitForFunction(
      () => typeof (window as Record<string, unknown>)._fazE2EOptIn === 'number',
      { timeout: 5_000 },
    );

    const counter = await page.evaluate(
      () => (window as Record<string, unknown>)._fazE2EOptIn,
    );
    expect(counter, 'opt_in_script should have incremented _fazE2EOptIn').toBeGreaterThan(0);
  });

  test('opt_out_script executes when analytics is rejected after prior accept', async ({
    page, wpBaseURL,
  }) => {
    // Use localStorage-based evidence: survives the page reload that the plugin
    // triggers when a previously-accepted category is revoked.
    await updateCookie(adminPage, nonce, baseURL, testCookieId, {
      opt_out_script: "localStorage.setItem('_fazE2EOptOutFired','1');",
    });

    try {
      // Step 1: fresh page, accept all → consent cookie analytics:yes.
      await page.context().clearCookies();
      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      await page.evaluate(() => localStorage.removeItem('_fazE2EOptOutFired'));
      await acceptAll(page);
      await waitForConsentCookie(page);

      // Step 2: reload WITH the consent cookie so _fazCategoriesBeforeConsent
      // includes 'analytics' when the next consent action fires.
      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      // Wait for the revisit widget to be ready (confirms script.js has initialised).
      await page.locator('[data-faz-tag="revisit-consent"] button, .faz-btn-revisit').first().waitFor({ state: 'visible', timeout: 5_000 });

      // Step 3: re-open the banner via the revisit widget, wait for it to appear,
      // then click "Reject All".
      const revisitBtn = page.locator('[data-faz-tag="revisit-consent"] button, .faz-btn-revisit').first();
      await revisitBtn.click({ timeout: 5_000 });
      // Wait for the reject button to become visible inside the re-opened banner.
      const rejectBtn = page.locator('[data-faz-tag="reject-button"]');
      await rejectBtn.waitFor({ state: 'visible', timeout: 5_000 });

      // Clicking reject triggers opt_out → localStorage → page reload.
      // Promise.all is the Playwright-recommended pattern: starting waitForNavigation
      // BEFORE the click avoids the race condition of a separation between the two.
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10_000 }),
        rejectBtn.click(),
      ]);
      // Wait for the localStorage evidence to be written (opt_out_script runs before reload).
      await page.waitForFunction(
        () => localStorage.getItem('_fazE2EOptOutFired') !== null,
        { timeout: 5_000 },
      );

      // Step 4: verify the localStorage evidence.
      const fired = await page.evaluate(() => localStorage.getItem('_fazE2EOptOutFired'));
      expect(fired, 'opt_out_script should have written to localStorage before the reload').toBe('1');
    } finally {
      await updateCookie(adminPage, nonce, baseURL, testCookieId, {
        opt_out_script: "window._fazE2EOptOut = (window._fazE2EOptOut || 0) + 1;",
      });
      await page.evaluate(() => localStorage.removeItem('_fazE2EOptOutFired'));
    }
  });

  test('opt_in_script does not execute when rejecting (no accept transition)', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { delete (window as Record<string, unknown>)._fazE2EOptIn; });

    await rejectAll(page);
    await waitForConsentCookie(page);

    const counter = await page.evaluate(
      () => (window as Record<string, unknown>)._fazE2EOptIn,
    );
    // When rejecting from a fresh (no prior consent) page, there is no
    // accepted→accepted transition, so opt_in must NOT have fired.
    expect(counter ?? 0, 'opt_in must NOT fire on a reject without prior accept').toBe(0);
  });

  test('second test cookie opt_in_script also executes on accept', async ({
    page, wpBaseURL,
  }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => {
      delete (window as Record<string, unknown>)._fazE2EOptIn2;
    });

    await acceptAll(page);
    await page.waitForFunction(
      () => (window as Record<string, unknown>)._fazE2EOptIn2 === true,
      { timeout: 5_000 },
    );

    const fired = await page.evaluate(
      () => (window as Record<string, unknown>)._fazE2EOptIn2,
    );
    expect(fired, '_fazE2EOptIn2 from second test cookie should be true').toBe(true);
  });

  test('a broken opt_in_script does not abort the consent flow', async ({
    page, wpBaseURL,
  }) => {
    // Temporarily set a broken script.
    await updateCookie(adminPage, nonce, baseURL, testCookieId, {
      opt_in_script: "INVALID SYNTAX {{{{{{",
    });

    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });

    // Should not throw — consent should complete normally.
    await acceptAll(page);
    await waitForConsentCookie(page);

    // Banner should be gone (consent was recorded despite script error).
    const banner = page.locator('[data-faz-tag="notice"]');
    const bannerVisible = await banner.isVisible().catch(() => false);
    expect(bannerVisible, 'banner should be hidden after accept despite script error').toBe(false);

    // Restore working script.
    await updateCookie(adminPage, nonce, baseURL, testCookieId, {
      opt_in_script: "window._fazE2EOptIn = (window._fazE2EOptIn || 0) + 1;",
    });
  });

  // ── Admin UI ───────────────────────────────────────────────────────────
  // The cookies table uses id="faz-cookies-table" / id="faz-cookies-tbody"
  // and is populated via AJAX fetch (initial tbody shows a "Loading…"
  // placeholder td.faz-empty). We wait for an Edit button to appear before
  // interacting. The edit button has class "faz-btn faz-btn-outline faz-btn-sm"
  // with text "Edit" — NOT a pencil icon button.

  async function waitForCookiesLoaded() {
    await adminPage
      .locator('#faz-cookies-tbody button', { hasText: 'Edit' })
      .first()
      .waitFor({ state: 'visible', timeout: 15_000 });
  }

  async function openFirstCookieModal() {
    await waitForCookiesLoaded();
    await adminPage
      .locator('#faz-cookies-tbody button', { hasText: 'Edit' })
      .first()
      .click();
  }

  test('opt_in_script textarea is visible in the cookie edit modal', async ({
    wpBaseURL,
  }) => {
    test.setTimeout(60_000);
    await adminPage.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });
    await openFirstCookieModal();
    await adminPage.waitForSelector('[data-field="opt_in_script"]', { timeout: 5_000 });
    await expect(adminPage.locator('[data-field="opt_in_script"]')).toBeVisible();
  });

  test('opt_out_script textarea is visible in the cookie edit modal', async ({
    wpBaseURL,
  }) => {
    test.setTimeout(60_000);
    await adminPage.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });
    await openFirstCookieModal();
    await adminPage.waitForSelector('[data-field="opt_out_script"]', { timeout: 5_000 });
    await expect(adminPage.locator('[data-field="opt_out_script"]')).toBeVisible();
  });

  test('opt_in_script textarea is pre-filled with saved value in edit modal', async ({
    wpBaseURL,
  }) => {
    test.setTimeout(60_000);
    await adminPage.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });

    await waitForCookiesLoaded();

    // Click the Edit button in the row that shows our test cookie name.
    const testRow = adminPage
      .locator('#faz-cookies-tbody tr', { hasText: '_faz_e2e_script_test' })
      .first();
    await testRow.locator('button', { hasText: 'Edit' }).click();

    await adminPage.waitForSelector('[data-field="opt_in_script"]', { timeout: 5_000 });
    const val = await adminPage.locator('[data-field="opt_in_script"]').inputValue();
    expect(val).toContain('_fazE2EOptIn');
  });
});
