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
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    await page.evaluate(() => {
      window.__fazBlockedCounter = 0;
      window.__fazLastScript = '';

      const payload = encodeURIComponent("window.__fazBlockedCounter=(window.__fazBlockedCounter||0)+1;window.__fazLastScript='before-consent';");
      const script = document.createElement('script');
      script.setAttribute('data-fazcookie', 'fazcookie-analytics');
      script.src = `data:text/javascript,${payload}`;
      document.head.appendChild(script);
    });

    await page.waitForTimeout(800);

    const beforeConsentState = await page.evaluate(() => ({
      counter: window.__fazBlockedCounter || 0,
      marker: window.__fazLastScript || '',
    }));

    expect(beforeConsentState.counter).toBe(0);
    expect(beforeConsentState.marker).toBe('');

    const accepted = await clickFirstVisible(page, [
      '[data-faz-tag="accept-button"] button',
      '[data-faz-tag="accept-button"]',
      '.faz-btn-accept',
    ]);
    expect(accepted).toBeTruthy();

    await expect.poll(async () => {
      return page.evaluate(() => window.__fazBlockedCounter || 0);
    }, { timeout: 10_000 }).toBe(1);

    const afterUnblockState = await page.evaluate(() => ({
      counter: window.__fazBlockedCounter || 0,
      marker: window.__fazLastScript || '',
    }));
    expect(afterUnblockState.marker).toBe('before-consent');

    await page.evaluate(() => {
      const payload = encodeURIComponent("window.__fazBlockedCounter=(window.__fazBlockedCounter||0)+1;window.__fazLastScript='after-consent';");
      const script = document.createElement('script');
      script.setAttribute('data-fazcookie', 'fazcookie-analytics');
      script.src = `data:text/javascript,${payload}`;
      document.head.appendChild(script);
    });

    await expect.poll(async () => {
      return page.evaluate(() => window.__fazBlockedCounter || 0);
    }, { timeout: 10_000 }).toBe(2);

    const finalState = await page.evaluate(() => ({
      counter: window.__fazBlockedCounter || 0,
      marker: window.__fazLastScript || '',
    }));

    expect(finalState.counter).toBe(2);
    expect(finalState.marker).toBe('after-consent');
  });
});
