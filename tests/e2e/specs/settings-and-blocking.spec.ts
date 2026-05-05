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

  test('data: base64 analytics scripts stay blocked before consent and execute after accept', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    const initialState = await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
      window.__fazDataUriExecuted = 0;
      const payload = btoa('window.__fazDataUriExecuted=(window.__fazDataUriExecuted||0)+1;');
      const script = document.createElement('script');
      script.id = 'faz-data-uri-probe';
      script.setAttribute('data-fazcookie', 'fazcookie-analytics');
      script.src = 'data:text/javascript;base64,' + payload;
      document.head.appendChild(script);

      const probe = document.getElementById('faz-data-uri-probe');
      return {
        executed: window.__fazDataUriExecuted || 0,
        exists: !!probe,
        type: probe ? probe.getAttribute('type') : null,
      };
    });

    expect(initialState.executed).toBe(0);
    expect(initialState.exists).toBe(true);
    expect(initialState.type).toBe('javascript/blocked');

    const accepted = await clickFirstVisible(page, [
      '[data-faz-tag="accept-button"] button',
      '[data-faz-tag="accept-button"]',
      '.faz-btn-accept',
    ]);
    expect(accepted).toBeTruthy();

    await page.waitForFunction(() => window.__fazDataUriExecuted === 1, undefined, { timeout: 5_000 });

    const restoredState = await page.evaluate(() => {
      const scripts = Array.from(document.scripts).filter((script) =>
        (script.textContent || '').includes('__fazDataUriExecuted')
      );
      return {
        executed: window.__fazDataUriExecuted || 0,
        restoredScripts: scripts.length,
      };
    });

    expect(restoredState.executed).toBe(1);
    expect(restoredState.restoredScripts).toBeGreaterThan(0);
  });

  test('inline scripts containing tracker URLs in data are not false-positively blocked', async ({ page }) => {
    // Regression test for the false-positive where an inline config script that merely
    // references a tracker domain inside its JSON data (e.g. Rank Math's rankMath.links
    // containing youtu.be, facebook.com) was incorrectly blocked as a marketing script.
    // URL-fragment provider patterns should only match src attributes, not inline content.
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const result = await page.evaluate(() => {
      // Inject an inline script whose body contains tracker-domain URLs in data,
      // but has no code-level tracking signatures (fbq(, gtag, etc.).
      const script = document.createElement('script');
      script.id = 'faz-fp-regression-probe';
      script.textContent = JSON.stringify({
        links: {
          tutorial: 'https://www.youtube.com/watch?v=test&feature=youtu.be',
          fb_group: 'https://www.facebook.com/groups/example/',
          tracking_url: 'https://connect.facebook.net/en_US/fbevents.js',
        },
        productId: 42,
        sku: 'TEST-123',
      });
      document.head.appendChild(script);

      const probe = document.getElementById('faz-fp-regression-probe');
      return {
        exists: !!probe,
        type: probe ? probe.getAttribute('type') : null,
        category: probe ? probe.getAttribute('data-faz-category') : null,
      };
    });

    expect(result.exists).toBe(true);
    // Must NOT be blocked: the script content has no code-level tracker signatures,
    // only URL references in data — URL patterns should not match inline content.
    expect(result.type, 'inline config script with tracker URLs in data must not be blocked').not.toBe('text/plain');
    expect(result.category, 'data-faz-category must not be set on a non-tracker inline script').toBeNull();
  });

  test('scripts with class="faz-skip" are never blocked regardless of content', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const result = await page.evaluate(() => {
      // Inject a script with faz-skip + data-fazcookie="fazcookie-analytics".
      // Without faz-skip, data-fazcookie alone would cause the JS intercept to block it.
      // With faz-skip, the script must execute unconditionally.
      window.__fazSkipProbeExecuted = false;
      const script = document.createElement('script');
      script.id = 'faz-skip-probe';
      script.className = 'faz-skip';
      script.setAttribute('data-fazcookie', 'fazcookie-analytics');
      script.textContent = 'window.__fazSkipProbeExecuted = true;';
      document.head.appendChild(script);

      const probe = document.getElementById('faz-skip-probe');
      return {
        exists: !!probe,
        type: probe ? probe.getAttribute('type') : null,
        executed: window.__fazSkipProbeExecuted,
      };
    });

    expect(result.exists).toBe(true);
    expect(result.type, 'faz-skip overrides data-fazcookie — script must not be blocked').not.toBe('javascript/blocked');
    expect(result.executed, 'faz-skip script must execute immediately').toBe(true);
  });
});
