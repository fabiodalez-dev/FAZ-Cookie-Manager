import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { clickFirstVisible } from '../utils/ui';
import { wpEval } from '../utils/wp-env';

/**
 * E2E — administrator-declared cookies as consent services (#360).
 *
 * A cookie added by hand under Cookies → Functional (a booking widget, a chat
 * loader) now:
 *   1. is reported in window.getFazConsent().services under a stable service id
 *      (derived from slug/name, exposed read-only by the REST API), with its
 *      category's consent — whether or not per-service consent is on;
 *   2. can flag its opt-in script to run again on EVERY later page load while the
 *      category stays accepted, not only at the accept click;
 *   3. can carry the provider's privacy-policy URL, rendered as a link under the
 *      cookie row in the banner preference centre.
 *
 * The cookie is created through the REST API in beforeAll and removed in
 * afterAll, so the suite leaves the inventory as it found it.
 */

const COOKIE_NAME = '_faz_e2e_360_bk';
const POLICY_URL = 'https://provider.example/privacy-360';
const OPT_IN = "window._fazE2E360 = (window._fazE2E360 || 0) + 1;";

async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '');
}

async function acceptAll(page: Page): Promise<void> {
  const acceptBtn = page.locator('[data-faz-tag="accept-button"]');
  await acceptBtn.waitFor({ state: 'visible', timeout: 10_000 });
  await acceptBtn.click();
  await page.waitForFunction(() => document.cookie.includes('fazcookie-consent='), { timeout: 5_000 });
}

function readServices(page: Page): Promise<Record<string, boolean>> {
  return page.evaluate(() => {
    const api = (window as Record<string, unknown> & { getFazConsent?: () => { services: Record<string, boolean> } }).getFazConsent;
    return api ? api().services : {};
  });
}

function readCounter(page: Page): Promise<number | undefined> {
  return page.evaluate(() => (window as Record<string, unknown>)._fazE2E360 as number | undefined);
}

test.describe('Declared cookies as consent services (#360)', () => {
  let adminPage: Page;
  let nonce = '';
  let baseURL = '';
  let cookieId = 0;
  let functionalCategoryId = 0;

  async function updateCookie(payload: Record<string, unknown>): Promise<void> {
    const res = await adminPage.request.post(`${baseURL}/?rest_route=/faz/v1/cookies/${cookieId}`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: payload,
    });
    expect(res.status(), 'updateCookie: unexpected status').toBe(200);
  }

  /**
   * Delete through the REST route as a POST with the method override header.
   * A literal HTTP DELETE on `/?rest_route=…` is answered 405 by the nginx
   * front of the test stack before WordPress sees it, so a spec that relied on
   * it left one row per run behind — and every leftover row re-registered the
   * same opt-in script and the same policy link, which is exactly what this
   * spec counts.
   */
  async function deleteCookieById(id: number): Promise<void> {
    const res = await adminPage.request.post(`${baseURL}/?rest_route=/faz/v1/cookies/${id}`, {
      headers: { 'X-WP-Nonce': nonce, 'X-HTTP-Method-Override': 'DELETE' },
    });
    expect(res.status(), `deleteCookie(${id}): unexpected status`).toBe(200);
  }

  type SettingsTree = Record<string, unknown>;
  async function getSettings(): Promise<SettingsTree> {
    const res = await adminPage.request.get(`${baseURL}/?rest_route=/faz/v1/settings/`, { headers: { 'X-WP-Nonce': nonce } });
    expect(res.status()).toBe(200);
    return (await res.json()) as SettingsTree;
  }
  async function setPerServiceConsent(on: boolean): Promise<void> {
    const current = await getSettings();
    const bannerControl = { ...((current.banner_control as SettingsTree) ?? {}), per_service_consent: on };
    const res = await adminPage.request.post(`${baseURL}/?rest_route=/faz/v1/settings/`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: { ...current, banner_control: bannerControl },
    });
    expect(res.status(), 'settings save').toBe(200);
  }

  /** Remove rows a crashed earlier run may have left, so counts start from zero. */
  function purgeLeftovers(): void {
    wpEval(`
      global $wpdb;
      $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}faz_cookies WHERE name = %s", '${COOKIE_NAME}' ) );
      do_action( 'faz_after_delete_cookie' );
      do_action( 'faz_after_update_cookie' );
      echo 'ok';
    `);
  }

  test.beforeAll(async ({ browser, wpBaseURL, loginAsAdmin }) => {
    baseURL = wpBaseURL;
    adminPage = await browser.newPage();
    adminPage.setDefaultNavigationTimeout(30_000);
    await loginAsAdmin(adminPage);
    await adminPage.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    nonce = await getAdminNonce(adminPage);
    expect(nonce.length, 'nonce must be non-empty').toBeGreaterThan(0);

    functionalCategoryId = parseInt(
      wpEval(`
        global $wpdb;
        echo (int) $wpdb->get_var(
          $wpdb->prepare( "SELECT category_id FROM {$wpdb->prefix}faz_cookie_categories WHERE slug = %s", 'functional' )
        );
      `).trim(),
      10,
    );
    expect(functionalCategoryId, 'functional category must exist in DB').toBeGreaterThan(0);

    purgeLeftovers();
    const res = await adminPage.request.post(`${baseURL}/?rest_route=/faz/v1/cookies`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: {
        name: COOKIE_NAME,
        domain: '127.0.0.1',
        category: functionalCategoryId,
        duration: { en: 'session' },
        description: { en: 'E2E #360 booking widget cookie' },
        opt_in_script: OPT_IN,
        opt_in_run_each_load: true,
        policy_url: POLICY_URL,
      },
    });
    const body = await res.json() as Record<string, unknown>;
    cookieId = typeof body.id === 'number' ? body.id : 0;
    expect(cookieId, `createCookie: got no id — response: ${JSON.stringify(body)}`).toBeGreaterThan(0);
  });

  test.afterAll(async () => {
    if (cookieId) {
      await deleteCookieById(cookieId);
    }
    await adminPage.close();
  });

  test('REST exposes the derived service_id, the policy URL and the run-each-load flag', async () => {
    const res = await adminPage.request.get(`${baseURL}/?rest_route=/faz/v1/cookies/${cookieId}&context=edit`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(res.status()).toBe(200);
    const body = await res.json() as Record<string, unknown>;
    // No slug was posted: the id derives from the name (the admin form never sets a slug).
    expect(body.service_id, 'service_id derived from the cookie name').toBe(COOKIE_NAME);
    expect(body.policy_url).toBe(POLICY_URL);
    expect(body.opt_in_run_each_load).toBe(true);
    expect(body.opt_in_script).toBe(OPT_IN);
  });

  test('a javascript: policy URL never persists', async () => {
    await updateCookie({ policy_url: 'javascript:alert(1)' });
    try {
      const res = await adminPage.request.get(`${baseURL}/?rest_route=/faz/v1/cookies/${cookieId}&context=edit`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      const body = await res.json() as Record<string, unknown>;
      expect(body.policy_url).toBe('');
    } finally {
      await updateCookie({ policy_url: POLICY_URL });
    }
  });

  test('getFazConsent().services reports the declared cookie with its category consent (per-service off)', async ({ page, wpBaseURL }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });

    const declared = await page.evaluate(() => {
      const cfg = (window as Record<string, unknown> & { _fazConfig?: { _declaredServices?: Array<{ id: string; category: string }> } })._fazConfig;
      return cfg?._declaredServices ?? [];
    });
    expect(declared.some((s) => s.id === COOKIE_NAME && s.category === 'functional'), '_declaredServices carries the hand-declared cookie').toBe(true);

    const before = await readServices(page);
    expect(before, 'listed before consent').toHaveProperty(COOKIE_NAME, false);

    await acceptAll(page);
    const after = await readServices(page);
    expect(after, 'accepted with its category').toHaveProperty(COOKIE_NAME, true);
  });

  test('the flagged opt-in script runs at the accept click and again on every later page load', async ({ page, wpBaseURL }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    expect(await readCounter(page), 'nothing runs before consent').toBeUndefined();

    await acceptAll(page);
    await page.waitForFunction(() => typeof (window as Record<string, unknown>)._fazE2E360 === 'number', { timeout: 5_000 });
    expect(await readCounter(page), 'runs exactly once at the accept click (no double fire)').toBe(1);

    // A fresh navigation with the consent cookie: the flagged script replays.
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof (window as Record<string, unknown>)._fazE2E360 === 'number', { timeout: 5_000 });
    expect(await readCounter(page), 'replayed once on the next page load').toBe(1);

    // Turn the flag off: the next page load must not replay it any more.
    await updateCookie({ opt_in_run_each_load: false });
    try {
      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => !!(window as Record<string, unknown> & { fazcookie?: { _fazInitDone?: boolean } }).fazcookie?._fazInitDone, { timeout: 10_000 });
      // Give any (wrong) replay a moment to happen before asserting it did not.
      await page.waitForTimeout(500);
      expect(await readCounter(page), 'without the flag the script does not run on page load').toBeUndefined();
    } finally {
      await updateCookie({ opt_in_run_each_load: true });
    }
  });

  test('a run-each-load flag stored as the string "yes" (settings import) still puts the script in the persistent list', async ({ page, wpBaseURL }) => {
    // Write the raw meta column the way an import can leave it, bypassing the
    // REST sanitiser, then bust the scripts-map transient.
    wpEval(`
      global $wpdb;
      $t    = $wpdb->prefix . 'faz_cookies';
      $meta = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$t} WHERE cookie_id = %d", ${cookieId} ) ), true );
      $meta['opt_in_run_each_load'] = 'yes';
      $wpdb->update( $t, array( 'meta' => wp_json_encode( $meta ) ), array( 'cookie_id' => ${cookieId} ) );
      do_action( 'faz_after_update_cookie' );
      echo 'ok';
    `);
    try {
      await page.context().clearCookies();
      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      const persistent = await page.evaluate(() => {
        type Entry = string | { code: string; service: string };
        const cfg = (window as Record<string, unknown> & { _fazConfig?: { _cookieScripts?: Record<string, { opt_in_persistent?: Entry[] }> } })._fazConfig;
        return (cfg?._cookieScripts?.functional?.opt_in_persistent ?? []).map((e) => (typeof e === 'string' ? e : e.code));
      });
      expect(persistent, '"yes" is read as on, like Cookie::get_meta() reads it').toContain(OPT_IN);
    } finally {
      await updateCookie({ opt_in_run_each_load: true });
    }
  });

  test('with per-service consent on, a denied declared service is not replayed although its category is granted', async ({ page, wpBaseURL }) => {
    const before = await getSettings();
    const wasOn = !!((before.banner_control as Record<string, unknown> | undefined)?.per_service_consent);
    await setPerServiceConsent(true);
    try {
      const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
      const domain = new URL(wpBaseURL).hostname;
      const pairs = (svc: 'yes' | 'no') =>
        `consentid:e2e-360,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,performance:yes,uncategorized:yes,marketing:yes,svc.${COOKIE_NAME}:${svc},rev:${rev}`;

      // Functional granted, the booking service denied.
      await page.context().clearCookies();
      await page.context().addCookies([{ name: 'fazcookie-consent', value: encodeURIComponent(pairs('no')), domain, path: '/', sameSite: 'Lax' }]);
      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => !!(window as Record<string, unknown> & { fazcookie?: { _fazInitDone?: boolean } }).fazcookie?._fazInitDone, { timeout: 10_000 });
      await page.waitForTimeout(500);
      expect(await readCounter(page), 'denied service: no replay').toBeUndefined();
      expect(await readServices(page), 'denied service: reported false').toHaveProperty(COOKIE_NAME, false);

      // Same category state, the service granted: it replays.
      await page.context().clearCookies();
      await page.context().addCookies([{ name: 'fazcookie-consent', value: encodeURIComponent(pairs('yes')), domain, path: '/', sameSite: 'Lax' }]);
      await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => typeof (window as Record<string, unknown>)._fazE2E360 === 'number', { timeout: 5_000 });
      expect(await readCounter(page), 'granted service: replayed once').toBe(1);
      expect(await readServices(page), 'granted service: reported true').toHaveProperty(COOKIE_NAME, true);
    } finally {
      await setPerServiceConsent(wasOn);
    }
  });

  test('the provider privacy-policy link renders under the cookie in the preference centre', async ({ page, wpBaseURL }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-faz-tag="notice"]').waitFor({ state: 'visible', timeout: 10_000 });

    const opened = await clickFirstVisible(page, [
      '[data-faz-tag="settings-button"] button',
      '[data-faz-tag="settings-button"]',
      '.faz-btn-customize',
    ]);
    expect(opened, 'preference centre opens').toBe(true);

    const link = page.locator(`a.faz-audit-policy-link[href="${POLICY_URL}"]`);
    await expect(link, 'one link for the declared cookie').toHaveCount(1);
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', /noopener/);
    await expect(link).toHaveAttribute('rel', /noreferrer/);
  });
});
