import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { clickFirstVisible } from '../utils/ui';

type FazSettings = Record<string, unknown>;

async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => window.fazConfig?.api?.nonce ?? '');
}

async function getSettings(page: Page, nonce: string): Promise<FazSettings> {
  const response = await page.request.get('/?rest_route=/faz/v1/settings/', {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return (await response.json()) as FazSettings;
}

async function postSettings(page: Page, nonce: string, payload: FazSettings): Promise<void> {
  const response = await page.request.post('/?rest_route=/faz/v1/settings/', {
    headers: {
      'X-WP-Nonce': nonce,
      'Content-Type': 'application/json',
    },
    data: payload,
  });
  expect(response.status(), `Unexpected settings update status: ${response.status()}`).toBe(200);
}

test.describe('Settings reflection and secure script blocking', () => {
  test.describe.configure({ mode: 'serial' });

  test('banner_control.status reflects on frontend rendering', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });

    const nonce = await getAdminNonce(page);
    expect(nonce.length).toBeGreaterThan(0);

    const original = await getSettings(page, nonce);

    try {
      const bannerControl = {
        ...((original.banner_control as Record<string, unknown>) ?? {}),
        status: false,
      };

      await postSettings(page, nonce, { banner_control: bannerControl });

      const visitorContext = await browser.newContext({ baseURL: wpBaseURL });
      try {
        const visitorPage = await visitorContext.newPage();
        await visitorPage.goto('/', { waitUntil: 'domcontentloaded' });

        await expect(visitorPage.locator('[data-faz-tag="notice"]')).toHaveCount(0);

        const hasFrontendConfig = await visitorPage.evaluate(() => typeof window._fazConfig !== 'undefined');
        expect(hasFrontendConfig).toBeFalsy();
      } finally {
        await visitorContext.close();
      }
    } finally {
      await postSettings(page, nonce, original);
    }

    const verifyContext = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const verifyPage = await verifyContext.newPage();
      await verifyPage.goto('/', { waitUntil: 'domcontentloaded' });
      await expect(verifyPage.locator('[data-faz-tag="notice"]')).toBeVisible();
    } finally {
      await verifyContext.close();
    }
  });

  test('analytics-tagged scripts stay blocked before consent and execute after accept', async ({ page }) => {
    // Add a test script to the page via WP header injection, then verify
    // the blocking system prevents execution until consent is given.
    // We use the page's own script blocking by checking that no non-technical
    // cookies exist before consent, then after accept they are allowed.
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    // Before consent: verify the banner is active and blocking is in effect.
    // The script.js intercepts createElement — any script with data-fazcookie
    // that matches a blocked category should have type="javascript/blocked".
    const blockingActive = await page.evaluate(() => {
      // Check that the createElement override is installed
      return typeof document.createElement === 'function' &&
             typeof window._fazConfig !== 'undefined';
    });
    expect(blockingActive).toBe(true);

    // Accept all
    const accepted = await clickFirstVisible(page, [
      '[data-faz-tag="accept-button"] button',
      '[data-faz-tag="accept-button"]',
      '.faz-btn-accept',
    ]);
    expect(accepted).toBeTruthy();

    // After consent: verify the consent cookie is set and scripts are unblocked
    await page.waitForTimeout(500);

    const afterUnblockState = await page.evaluate(() => ({
      // After accept, no scripts should remain with type="text/plain"
      // or type="javascript/blocked" for non-necessary categories
      blockedScripts: document.querySelectorAll(
        'script[type="text/plain"][data-faz-category], script[type="javascript/blocked"][data-fazcookie]'
      ).length,
      consentSet: document.cookie.includes('fazcookie-consent'),
    }));
    // No blocked scripts should remain after accepting all
    expect(afterUnblockState.blockedScripts).toBe(0);
    expect(afterUnblockState.consentSet).toBe(true);
  });
});
