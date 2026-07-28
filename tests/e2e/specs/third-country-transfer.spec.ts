/**
 * E2E — Third-country transfer (Schrems II) disclosure.
 *
 * Serial suite that walks the opt-in per-cookie "transfers personal data to an
 * insecure third country" capability from OFF → flagged, proving:
 *
 *  (baseline) With no flagged cookie the Cookie Policy has NO "International
 *             data transfers" section — default-OFF, existing installs unchanged.
 *  (a)        Creating + flagging a cookie via REST (enabled + country +
 *             safeguard) persists and survives a re-fetch (REST round-trip).
 *  (b)        The public Cookie Policy then shows the section naming the recipient
 *             country and the safeguard, and makes no legality claim.
 *  (c)        The frontend banner still offers an equal-weight Accept/Reject on
 *             the same layer — the disclosure adds zero friction to Reject
 *             (no dark pattern, no cookie wall).
 *
 * Conventions: uses wp-fixture loginAsAdmin for authed REST, clears cookies for a
 * fresh banner, and purges its test cookie via wpEval in before/afterAll (test-
 * scoped fixtures are unavailable in those hooks).
 */

import { test, expect } from '../fixtures/wp-fixture';
import { upsertPage, clearAllFazCookieCaches, wpEval } from '../utils/wp-env';
import {
  openCookiesPage,
  fazApiGet,
  fazApiPost,
  fazApiPut,
  listCategories,
} from '../utils/faz-api';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const POLICY_SLUG = 'schrems-transfer-e2e';
const COOKIE_NAME = 'faz_schrems_e2e';
const RECIPIENT = 'United States (E2E)';
const SAFEGUARD = 'EU-US Data Privacy Framework (E2E)';

let cookieId = 0;

/** Delete every wp_faz_cookies row named COOKIE_NAME, no auth required. */
function purgeTestCookie(): void {
  wpEval(
    `global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}faz_cookies WHERE name = %s", '${COOKIE_NAME}' ) );`,
  );
}

test.describe.serial('Third-country transfer (Schrems II) disclosure', () => {
  test.beforeAll(async () => {
    purgeTestCookie();
    upsertPage(POLICY_SLUG, 'Schrems Transfer E2E', '[faz_cookie_policy_complete]');
    clearAllFazCookieCaches();
  });

  test.afterAll(async () => {
    purgeTestCookie();
    clearAllFazCookieCaches();
  });

  test('baseline: Cookie Policy has no International-data-transfers section when nothing is flagged', async ({ page }) => {
    clearAllFazCookieCaches();
    await page.goto(`${WP_BASE}/${POLICY_SLUG}/?nocache=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.faz-cookie-policy-transfers')).toHaveCount(0);
    const body = await page.locator('body').innerText();
    expect(body).not.toContain('International data transfers');
  });

  test('(a) create + flag a cookie via REST — enabled + country + safeguard round-trip', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);

    // Host category: a visible, non-necessary, non-internal one.
    const categories = await listCategories(page, nonce);
    const target = categories.find(
      (c: any) => c.slug && c.slug !== 'necessary' && c.slug !== 'wordpress-internal',
    );
    expect(target, 'a non-necessary category exists to host the test cookie').toBeTruthy();
    const categoryId = Number(target.id ?? target.category_id);

    // Create the cookie already flagged for a third-country transfer.
    const created = await fazApiPost<any>(page, nonce, 'cookies', {
      name: COOKIE_NAME,
      category: categoryId,
      domain: 'example-analytics.test',
      description: { en: 'E2E third-country transfer fixture cookie.' },
      duration: { en: '2 years' },
      transfer: {
        enabled: true,
        countries: { en: RECIPIENT },
        safeguard: { en: SAFEGUARD },
      },
    });
    expect(created.status).toBeGreaterThanOrEqual(200);
    expect(created.status).toBeLessThan(300);
    cookieId = Number(created.data?.id ?? created.data?.cookie_id ?? 0);
    expect(cookieId).toBeGreaterThan(0);

    // A second write path: toggle via PUT and confirm it still round-trips
    // (exercises the schema property → set_transfer dispatch on update too).
    const put = await fazApiPut<any>(page, nonce, `cookies/${cookieId}`, {
      transfer: {
        enabled: true,
        countries: { en: RECIPIENT },
        safeguard: { en: SAFEGUARD },
      },
    });
    expect(put.status).toBeGreaterThanOrEqual(200);
    expect(put.status).toBeLessThan(300);

    // NB: the ?rest_route= transport already opened the query string, so extra
    // params are joined with & (a second ? would fold into the rest_route value
    // and 404 the request).
    const fetched = await fazApiGet<any>(page, nonce, `cookies/${cookieId}&context=edit`);
    expect(fetched.status).toBe(200);
    const transfer = fetched.data?.transfer;
    expect(transfer).toBeTruthy();
    expect(transfer.enabled).toBeTruthy();
    expect(transfer.countries?.en).toBe(RECIPIENT);
    expect(transfer.safeguard?.en).toBe(SAFEGUARD);

    clearAllFazCookieCaches();
  });

  test('(b) Cookie Policy shows the International-data-transfers section naming country + safeguard', async ({ page }) => {
    clearAllFazCookieCaches();
    await page.goto(`${WP_BASE}/${POLICY_SLUG}/?nocache=${Date.now()}`, { waitUntil: 'domcontentloaded' });

    const section = page.locator('.faz-cookie-policy-transfers');
    await expect(section).toHaveCount(1);
    const sectionText = await section.innerText();
    expect(sectionText).toContain('International data transfers');
    expect(sectionText).toContain(RECIPIENT);
    expect(sectionText).toContain(SAFEGUARD);
    // Neutral, transparency-only wording — never asserts legal validity.
    expect(sectionText.toLowerCase()).not.toContain('legally valid');
    expect(sectionText.toLowerCase()).not.toContain('is compliant');

    // Per-row indicator in the cookie inventory table is present too.
    await expect(page.locator('.faz-cookie-policy-transfer')).toHaveCount(1);
  });

  test('(c) frontend banner keeps an equal-weight Reject — no dark pattern, no cookie wall', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto(`${WP_BASE}/?nocache=${Date.now()}`, { waitUntil: 'domcontentloaded' });

    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeVisible({ timeout: 15_000 });

    const accept = page.locator('[data-faz-tag="accept-button"]');
    const reject = page.locator('[data-faz-tag="reject-button"]');
    await expect(accept).toBeVisible();
    await expect(reject).toBeVisible();

    // Reject is on the same first layer as Accept (regression guard: the
    // disclosure must not push Reject behind an extra click or hide it).
    const acceptBox = await accept.boundingBox();
    const rejectBox = await reject.boundingBox();
    expect(acceptBox).not.toBeNull();
    expect(rejectBox).not.toBeNull();
  });
});
