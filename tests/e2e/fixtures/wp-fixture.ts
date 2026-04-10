import { expect, test as base, type BrowserContext, type Page } from '@playwright/test';
import { getWpLoginPath } from '../utils/wp-auth';

type ConsentMap = Record<string, string>;

type WPFixtures = {
  wpBaseURL: string;
  adminUser: string;
  adminPass: string;
  loginAsAdmin: (page: Page) => Promise<void>;
  getConsentCookie: (context: BrowserContext) => Promise<{ name: string; value: string } | undefined>;
  parseConsentCookie: (raw: string) => ConsentMap;
  getNonTechnicalCookies: (context: BrowserContext) => Promise<Array<{ name: string; value: string }>>;
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
      await page.goto(url, { waitUntil: 'commit', timeout: 60_000 });
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

export async function completeAdminLogin(page: Page, wpBaseURL: string, adminUser: string, adminPass: string): Promise<void> {
	const loginPath = getWpLoginPath();
  await gotoResilient(page, `${wpBaseURL}${loginPath}`);

  if (page.url().includes('/wp-admin/')) {
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

export const test = base.extend<WPFixtures>({
  wpBaseURL: async ({}, use) => { // biome-ignore lint/style/noEmptyPattern: Playwright fixture API requires destructured first argument
    await use(process.env.WP_BASE_URL ?? 'http://localhost:9998');
  },

  adminUser: async ({}, use) => { // biome-ignore lint/style/noEmptyPattern: Playwright fixture API requires destructured first argument
    await use(process.env.WP_ADMIN_USER ?? 'admin');
  },

  adminPass: async ({}, use) => { // biome-ignore lint/style/noEmptyPattern: Playwright fixture API requires destructured first argument
    await use(process.env.WP_ADMIN_PASS ?? 'admin');
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
