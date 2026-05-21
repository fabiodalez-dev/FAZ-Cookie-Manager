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

async function gotoResilient(page: Page, url: string): Promise<void> {
  let lastError: unknown;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
      await page.waitForLoadState('domcontentloaded', { timeout: 60_000 }).catch(() => {
        // Some WordPress/plugin combinations keep requests open longer than needed.
      });
      return;
    } catch (error) {
      lastError = error;
    }
  }
  throw lastError;
}

/**
 * Single login attempt. Throws when WP issues a `reauth=1` redirect
 * because the existing session cookie was invalidated (typical when a
 * previous spec rotated wp_salt, mutated user_meta sessions, or changed
 * banner_default which the plugin treats as an auth-impacting change).
 * The caller wraps this in a retry that clears cookies between attempts.
 */
async function attemptAdminLogin(page: Page, wpBaseURL: string, adminUser: string, adminPass: string): Promise<void> {
  const loginPath = getWpLoginPath();
  await gotoResilient(page, `${wpBaseURL}${loginPath}`);

  // Lucky path: existing session cookie still valid and WP redirected
  // straight to /wp-admin/. NB: a `reauth=1` URL landing on wp-login.php
  // is NOT this branch — WP keeps the URL on /wp-login.php while the
  // partial cookie is rejected, so we fall through to fill below.
  if (page.url().includes('/wp-admin/') && !page.url().includes('reauth=')) {
    await expect(page.locator('#wpadminbar')).toBeVisible();
    return;
  }

  await expect(page.locator('#user_login')).toBeVisible({ timeout: 20_000 });
  await page.locator('#user_login').fill(adminUser);
  await page.locator('#user_pass').fill(adminPass);

  await Promise.all([
    page.locator('#wp-submit').click(),
    page.waitForLoadState('domcontentloaded', { timeout: 60_000 }).catch(() => {
      // Some plugin combinations keep the request open after auth succeeds.
    }),
  ]);

  if (page.url().includes('/wp-admin/')) {
    await expect(page.locator('#wpadminbar')).toBeVisible();
    await expect(page.locator('#loginform')).toHaveCount(0);
    return;
  }

  const cookies = await page.context().cookies(wpBaseURL);
  const hasLoggedCookie = cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_'));
  if (hasLoggedCookie) {
    await gotoResilient(page, `${wpBaseURL}/wp-admin/`);
    await expect(page).toHaveURL(/\/wp-admin\//, { timeout: 20_000 });
    await expect(page.locator('#wpadminbar')).toBeVisible();
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
  let lastError: unknown;
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    try {
      await attemptAdminLogin(page, wpBaseURL, adminUser, adminPass);
      return;
    } catch (error) {
      lastError = error;
      if (attempt < 2) {
        try {
          await page.context().clearCookies();
        } catch {
          // Non-fatal — the retry will still reach wp-login.php and WP
          // will issue fresh cookies on a successful POST.
        }
      }
    }
  }
  throw lastError instanceof Error ? lastError : new Error(String(lastError));
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
