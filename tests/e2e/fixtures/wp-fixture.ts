import { expect, test as base, type BrowserContext, type Page } from '@playwright/test';

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
      await page.goto(`${wpBaseURL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
      await page.locator('#user_login').fill(adminUser);
      await page.locator('#user_pass').fill(adminPass);
      await page.locator('#wp-submit').click();

      await expect(page).toHaveURL(/\/wp-admin\//);
      await expect(page.locator('#wpadminbar')).toBeVisible();
      await expect(page.locator('#loginform')).toHaveCount(0);
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
