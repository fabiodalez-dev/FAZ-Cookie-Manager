import { expect, test as base, type BrowserContext, type Page } from '@playwright/test';
import { getWpLoginPath } from '../utils/wp-auth';

type ConsentMap = Record<string, string>;

/**
 * Worker-scoped credential bundle.
 *
 * Worker scope is required because Playwright's `test.beforeAll` /
 * `test.afterAll` hooks cannot receive test-scoped fixtures; previously
 * each spec re-read `process.env.WP_BASE_URL` / `WP_ADMIN_USER` /
 * `WP_ADMIN_PASS` / `FAZ_PLUGIN_DEPLOY_PATH` with duplicated defaults.
 * Centralising the resolution here keeps the defaults in one place and
 * lets future changes (random ports, token auth, docker URLs) land
 * without touching every spec.
 */
export type WpCreds = {
  baseURL: string;
  adminUser: string;
  adminPass: string;
  deployPath: string | null;
};

type WPFixtures = {
  wpBaseURL: string;
  adminUser: string;
  adminPass: string;
  loginAsAdmin: (page: Page) => Promise<void>;
  getConsentCookie: (context: BrowserContext) => Promise<{ name: string; value: string } | undefined>;
  parseConsentCookie: (raw: string) => ConsentMap;
  getNonTechnicalCookies: (context: BrowserContext) => Promise<Array<{ name: string; value: string }>>;
};

type WPWorkerFixtures = {
  wpCreds: WpCreds;
};

const TECHNICAL_COOKIE_RE = [
  /^wordpress_/i,
  /^wp-settings/i,
  /^PHPSESSID$/i,
  /^wordpress_test_cookie$/i,
  /^wp_lang$/i,
  /^fazcookie-consent$/,
  /^fazVendorConsent$/,
  /^euconsent-v2$/,
];

const isTechnicalCookie = (name: string): boolean => TECHNICAL_COOKIE_RE.some((re) => re.test(name));

/**
 * How long the whole login is allowed to take, and the slice each attempt gets.
 *
 * These used to be independent of the test budget, and that made the retry loops
 * decorative: one page.goto was allowed 60s plus a 60s load wait — 120s — inside
 * a 90s test. The outer budget killed the run before a second attempt could
 * start, so a slow login never retried; it just died, and reported
 * "Test timeout exceeded" pointing at whatever line happened to be awaiting.
 *
 * Measured cost of that: a release-verification run produced 14 red tests across
 * six specs that have nothing to do with authentication. The first failure was a
 * login (`#wpadminbar` never appeared); everything after it was a spec operating
 * on a page it believed was wp-admin — saves that silently did nothing, reads
 * that returned pre-existing values. Hours to attribute, and nothing wrong with
 * the product.
 *
 * A shared deadline fixes the shape rather than the number: several fast
 * attempts now fit where one slow attempt used to consume everything, and when
 * the budget really is exhausted the error names the login instead of a timeout
 * on an unrelated locator.
 */
const LOGIN_TOTAL_BUDGET_MS = 60_000;
const LOGIN_ATTEMPT_MS = 12_000;

/** Milliseconds left before `deadline`, floored at 1 so callers never pass 0 (= no timeout). */
function remaining(deadline: number, cap = LOGIN_ATTEMPT_MS): number {
  return Math.max(1, Math.min(cap, deadline - Date.now()));
}

async function gotoResilient(page: Page, url: string, deadline: number): Promise<void> {
  let lastError: unknown;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    if (Date.now() >= deadline) break;
    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: remaining(deadline) });
      await page
        .waitForLoadState('domcontentloaded', { timeout: remaining(deadline, 5_000) })
        .catch(() => {
          // Some WordPress/plugin combinations keep requests open longer than needed.
        });
      return;
    } catch (error) {
      lastError = error;
    }
  }
  throw lastError ?? new Error(`gotoResilient: budget exhausted before reaching ${url}`);
}

/**
 * Single login attempt. Throws when WP issues a `reauth=1` redirect
 * because the existing session cookie was invalidated (typical when a
 * previous spec rotated wp_salt, mutated user_meta sessions, or changed
 * banner_default which the plugin treats as an auth-impacting change).
 * The caller wraps this in a retry that clears cookies between attempts.
 */
async function attemptAdminLogin(page: Page, wpBaseURL: string, adminUser: string, adminPass: string, deadline: number): Promise<void> {
  const loginPath = getWpLoginPath();
  await gotoResilient(page, `${wpBaseURL}${loginPath}`, deadline);

  // Lucky path: existing session cookie still valid and WP redirected
  // straight to /wp-admin/. NB: a `reauth=1` URL landing on wp-login.php
  // is NOT this branch — WP keeps the URL on /wp-login.php while the
  // partial cookie is rejected, so we fall through to fill below.
  if (page.url().includes('/wp-admin/') && !page.url().includes('reauth=')) {
    await expect(page.locator('#wpadminbar')).toBeVisible({ timeout: remaining(deadline) });
    return;
  }

  await expect(page.locator('#user_login')).toBeVisible({ timeout: remaining(deadline) });
  // The login document may still be settling after a slow reauth redirect.
  // Locator.fill() then repeats actionability checks until the whole test
  // times out even though the fields are already visible. Set the native
  // values and dispatch the events WordPress/browser integrations expect.
  await page.locator('#user_login').evaluate((input: HTMLInputElement, value: string) => {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, adminUser);
  await page.locator('#user_pass').evaluate((input: HTMLInputElement, value: string) => {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, adminPass);

  // A Locator click waits for the navigation it initiates using the global
  // 15-second action timeout. On the shared compatibility site WordPress can
  // authenticate successfully but take longer than that to finish the admin
  // redirect, so the click throws even though the navigation budget below is
  // deliberately 60 seconds. Trigger the native click without Playwright's
  // implicit navigation wait and let the explicit waiter own that budget.
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: remaining(deadline) }).catch(() => {
      // Some plugin combinations keep the request open after auth succeeds.
    }),
    page.locator('#wp-submit').evaluate((button: HTMLInputElement) => button.click()),
  ]);

  if (page.url().includes('/wp-admin/')) {
    await expect(page.locator('#wpadminbar')).toBeVisible({ timeout: remaining(deadline) });
    await expect(page.locator('#loginform')).toHaveCount(0);
    return;
  }

  const cookies = await page.context().cookies(wpBaseURL);
  const hasLoggedCookie = cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_'));
  if (hasLoggedCookie) {
    await gotoResilient(page, `${wpBaseURL}/wp-admin/`, deadline);
    await expect(page).toHaveURL(/\/wp-admin\//, { timeout: remaining(deadline) });
    await expect(page.locator('#wpadminbar')).toBeVisible({ timeout: remaining(deadline) });
    await expect(page.locator('#loginform')).toHaveCount(0);
    return;
  }

  const loginError = await page.locator('#login_error').textContent().catch(() => '');
  throw new Error(`WordPress admin login failed. URL=${page.url()} error=${loginError ?? 'n/a'}`);
}

export async function completeAdminLogin(page: Page, wpBaseURL: string, adminUser: string, adminPass: string): Promise<void> {
  // Up to 2 attempts. The first usually succeeds; the second is needed
  // when a previous spec invalidated the cookie WP is now trying to
  // reuse (salt rotation, session-meta invalidation, banner_default
  // mutations — all visible in the wp7-compat-full.log as the
  // `URL=...reauth=1 error=` shape at log line 114, 1066 onwards).
  //
  // Between attempts we clear cookies for the WP base URL so the second
  // try is a true fresh login rather than another reauth roundtrip
  // against the same stale cookie. Pattern matches Playwright's own
  // recommended retry-on-flaky-auth approach.
  // One budget for the whole login, shared by every attempt below.
  const deadline = Date.now() + LOGIN_TOTAL_BUDGET_MS;
  let lastError: unknown;
  let attempts = 0;
  // Bounded by the DEADLINE, not by a fixed count. Two was the old cap, and with
  // 12s attempts inside a 60s budget it would leave most of the budget unused —
  // the opposite of the original bug but the same mistake: a number picked
  // without reference to the one that governs it. MAX_LOGIN_ATTEMPTS is only a
  // runaway guard.
  const MAX_LOGIN_ATTEMPTS = 4;
  while (attempts < MAX_LOGIN_ATTEMPTS && Date.now() < deadline) {
    attempts += 1;
    try {
      await attemptAdminLogin(page, wpBaseURL, adminUser, adminPass, deadline);
      return;
    } catch (error) {
      lastError = error;
      try {
        await page.context().clearCookies();
      } catch {
        // Non-fatal — the retry will still reach wp-login.php and WP
        // will issue fresh cookies on a successful POST.
      }
    }
  }
  // Name the login. The whole point of a budget is that an exhausted one reports
  // itself, instead of surfacing later as a timeout on whatever locator the spec
  // happened to be awaiting when the page was never wp-admin.
  const detail = lastError instanceof Error ? lastError.message : String(lastError ?? 'no attempt completed');
  throw new Error(`admin login failed after ${attempts} attempt(s) within ${LOGIN_TOTAL_BUDGET_MS}ms: ${detail}`);
}

export const test = base.extend<WPFixtures, WPWorkerFixtures>({
  wpCreds: [
    async ({}, use) => { // biome-ignore lint/style/noEmptyPattern: Playwright fixture API requires destructured first argument
      await use({
        baseURL: process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998',
        adminUser: process.env.WP_ADMIN_USER ?? 'admin',
        adminPass: process.env.WP_ADMIN_PASS ?? 'admin',
        deployPath: process.env.FAZ_PLUGIN_DEPLOY_PATH ?? null,
      });
    },
    { scope: 'worker' },
  ],

  wpBaseURL: async ({ wpCreds }, use) => {
    await use(wpCreds.baseURL);
  },

  adminUser: async ({ wpCreds }, use) => {
    await use(wpCreds.adminUser);
  },

  adminPass: async ({ wpCreds }, use) => {
    await use(wpCreds.adminPass);
  },

  loginAsAdmin: async ({ wpBaseURL, adminUser, adminPass }, use) => {
    await use(async (page: Page) => {
      await completeAdminLogin(page, wpBaseURL, adminUser, adminPass);
    });
  },

  getConsentCookie: async ({ wpBaseURL }, use) => {
    await use(async (context: BrowserContext) => {
      const cookies = await context.cookies(wpBaseURL);
      const consent = cookies.find((cookie) => cookie.name === 'fazcookie-consent');
      if (!consent) {
        return undefined;
      }
      return {
        name: consent.name,
        value: consent.value,
      };
    });
  },

  parseConsentCookie: async ({}, use) => { // biome-ignore lint/style/noEmptyPattern: Playwright fixture API requires destructured first argument
    await use((raw: string) => {
      const parsed: ConsentMap = {};
      let decoded: string;
      try {
        decoded = decodeURIComponent(raw);
      } catch {
        decoded = raw;
      }
      for (const chunk of decoded.split(',')) {
        const [key, ...rest] = chunk.split(':');
        if (!key) {
          continue;
        }
        parsed[key.trim()] = rest.join(':').trim();
      }
      return parsed;
    });
  },

  getNonTechnicalCookies: async ({ wpBaseURL }, use) => {
    await use(async (context: BrowserContext) => {
      const cookies = await context.cookies(wpBaseURL);
      return cookies
        .filter((cookie) => !isTechnicalCookie(cookie.name))
        .map((cookie) => ({ name: cookie.name, value: cookie.value }));
    });
  },
});

export { expect };
