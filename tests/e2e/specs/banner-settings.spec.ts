import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';

/* ─── Helpers ──────────────────────────────────────────────── */

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
}

async function getBanner(page: Page, nonce: string, id = 1) {
  const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/banners/${id}`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(r.status()).toBe(200);
  return r.json();
}

async function updateBanner(page: Page, nonce: string, id: number, payload: Record<string, unknown>) {
  const r = await page.request.put(`${WP_BASE}/?rest_route=/faz/v1/banners/${id}`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: payload,
  });
  expect(r.status(), `Banner update failed: ${r.status()}`).toBe(200);
  return r.json();
}

/** Open a fresh visitor page (no cookies/session).
 *  Sets Accept-Language to the plugin's default language so that the
 *  frontend renders in the same language the admin saved texts in. */
async function openVisitorPage(browser: any, baseURL: string, path = '/', locale = 'en-US') {
  const ctx = await browser.newContext({ baseURL, locale, extraHTTPHeaders: { 'Accept-Language': locale } });
  const page = await ctx.newPage();
  await page.goto(path, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  return { page, ctx };
}

/** Navigate to the Cookie Banner admin page and wait for banner data to fully load.
 *  With REST preloading, the banner API response comes from the middleware cache
 *  (no network request). We wait for populateSettings to fill the form. */
async function goToBannerPage(page: Page) {
  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
    waitUntil: 'domcontentloaded',
    timeout: 45_000,
  });
  // Wait for populateSettings to finish filling the form
  await page.waitForFunction(
    () => {
      const el = document.getElementById('faz-b-type') as HTMLSelectElement;
      return el && el.value !== '';
    },
    { timeout: 10_000 },
  );
}

/** Click a tab button in the banner admin page. */
async function clickTab(page: Page, tabName: string) {
  await page.click(`#faz-banner-tabs button[data-tab="${tabName}"]`);
  await page.waitForSelector(`#tab-${tabName}.active`, { timeout: 5_000 });
}

/** Set a select value using Playwright's native selectOption. */
async function setSelect(page: Page, id: string, value: string) {
  await page.selectOption(`#${id}`, value);
}

/** Set an input value using Playwright's fill. */
async function setInput(page: Page, id: string, value: string) {
  await page.fill(`#${id}`, value);
}

/** Set a wp_editor value, using TinyMCE when available and textarea fallback otherwise. */
async function setRichText(page: Page, id: string, value: string) {
  await page.evaluate(
    ([editorId, nextValue]) => {
      const textarea = document.getElementById(editorId) as HTMLTextAreaElement | null;
      const editor = (window as any).tinyMCE?.get(editorId);

      if (editor) {
        editor.setContent(nextValue);
        editor.save();
      }

      if (textarea) {
        textarea.value = nextValue;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.dispatchEvent(new Event('change', { bubbles: true }));
      }
    },
    [id, value] as [string, string],
  );
}

/** Set a toggle checkbox. */
async function setToggle(page: Page, toggleId: string, checked: boolean) {
  await page.evaluate(
    ([elId, state]) => {
      const label = document.getElementById(elId);
      const cb = label?.querySelector('input[type="checkbox"]') as HTMLInputElement | null;
      if (cb && cb.checked !== state) {
        cb.checked = state;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
      }
    },
    [toggleId, checked] as [string, boolean],
  );
}

/** Read a toggle checkbox state. */
async function getToggle(page: Page, toggleId: string): Promise<boolean> {
  return page.evaluate((elId) => {
    const label = document.getElementById(elId);
    const cb = label?.querySelector('input[type="checkbox"]') as HTMLInputElement | null;
    return cb?.checked ?? false;
  }, toggleId);
}

/** Set a color input via the hex text field. */
async function setColorHex(page: Page, hexInputId: string, hexValue: string) {
  await page.evaluate(
    ([elId, val]) => {
      const hexEl = document.getElementById(elId) as HTMLInputElement;
      if (hexEl) {
        hexEl.value = val;
        hexEl.dispatchEvent(new Event('input', { bubbles: true }));
        hexEl.dispatchEvent(new Event('change', { bubbles: true }));
      }
      // Also set the paired color picker
      const colorId = elId.replace('-hex', '');
      const colorEl = document.getElementById(colorId) as HTMLInputElement;
      if (colorEl) {
        colorEl.value = val;
        colorEl.dispatchEvent(new Event('input', { bubbles: true }));
      }
    },
    [hexInputId, hexValue],
  );
}

/** Click the Save button and wait for the save to complete. */
async function saveBanner(page: Page) {
  // Wait for the real save; exclude /banners/preview which fires from live preview.
  const responsePromise = page.waitForResponse(
    (r) =>
      r.url().includes('banners') &&
      !r.url().includes('preview') &&
      (r.request().method() === 'PUT' || r.request().method() === 'POST'),
    { timeout: 30_000 },
  );
  await page.click('#faz-b-save');
  const response = await responsePromise;
  expect(response.status()).toBe(200);
  // Wait for the success toast to confirm save completed
  await page.waitForSelector('.faz-toast-success', { state: 'visible', timeout: 10_000 }).catch(() => {});
}

/** Read a select value. */
async function getSelectValue(page: Page, id: string): Promise<string> {
  return page.evaluate((elId) => {
    const el = document.getElementById(elId) as HTMLSelectElement | null;
    return el?.value ?? '';
  }, id);
}

/** Read an input value. */
async function getInputValue(page: Page, id: string): Promise<string> {
  return page.evaluate((elId) => {
    const el = document.getElementById(elId) as HTMLInputElement | null;
    return el?.value ?? '';
  }, id);
}

/** Read a wp_editor value, preferring TinyMCE content and falling back to the textarea value. */
async function getRichTextValue(page: Page, id: string): Promise<string> {
  return page.evaluate((editorId) => {
    const editor = (window as any).tinyMCE?.get(editorId);
    if (editor) {
      const apiValue = typeof editor.getContent === 'function' ? editor.getContent() : '';
      if (apiValue) {
        return apiValue.trim();
      }

      const body = typeof editor.getBody === 'function' ? editor.getBody() : null;
      if (body?.innerHTML) {
        return body.innerHTML.trim();
      }
    }
    const textarea = document.getElementById(editorId) as HTMLTextAreaElement | null;
    return textarea?.value?.trim() ?? '';
  }, id);
}

/* ─── Tests ────────────────────────────────────────────────── */

test.describe('Banner settings: persistence and frontend reflection', () => {
  test.describe.configure({ mode: 'serial' });

  let originalBanner: any;
  let nonce: string;

  test.beforeAll(async ({ browser }) => {
    // Capture original banner data so we can restore it after all tests
    const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:9998';
    const ctx = await browser.newContext({ baseURL });
    const page = await ctx.newPage();
    await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
    await page.locator('#user_login').fill(process.env.WP_ADMIN_USER ?? 'admin');
    await page.locator('#user_pass').fill(process.env.WP_ADMIN_PASS ?? 'admin');
    await page.locator('#wp-submit').click();
    await expect(page).toHaveURL(/\/wp-admin\//, { timeout: 20_000 });

    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-banner', {
      waitUntil: 'domcontentloaded',
    });
    nonce = await getAdminNonce(page);
    originalBanner = await getBanner(page, nonce);
    await ctx.close();
  });

  test.afterAll(async ({ browser }) => {
    if (!originalBanner) return;
    const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:9998';
    const ctx = await browser.newContext({ baseURL });
    const page = await ctx.newPage();
    await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
    await page.locator('#user_login').fill(process.env.WP_ADMIN_USER ?? 'admin');
    await page.locator('#user_pass').fill(process.env.WP_ADMIN_PASS ?? 'admin');
    await page.locator('#wp-submit').click();
    await expect(page).toHaveURL(/\/wp-admin\//, { timeout: 20_000 });

    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-banner', {
      waitUntil: 'domcontentloaded',
    });
    const n = await getAdminNonce(page);
    await updateBanner(page, n, 1, {
      name: originalBanner.name,
      status: originalBanner.status,
      default: originalBanner.default,
      properties: originalBanner.properties,
      contents: originalBanner.contents,
    });
    await ctx.close();
  });

  // ─── General Tab ───────────────────────────────────────

  test('General: banner type persists and reflects on frontend', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    // Set to Full-width Banner
    await setSelect(page, 'faz-b-type', 'banner');
    await setSelect(page, 'faz-b-position', 'bottom');
    await saveBanner(page);

    // Reload admin and verify persistence
    await goToBannerPage(page);
    expect(await getSelectValue(page, 'faz-b-type')).toBe('banner');
    expect(await getSelectValue(page, 'faz-b-position')).toBe('bottom');

    // Check frontend
    const visitor = await openVisitorPage(browser, wpBaseURL);
    try {
      const banner = visitor.page.locator('.faz-consent-container');
      await expect(banner).toBeVisible({ timeout: 10_000 });
      // Full-width banner uses faz-classic-bottom or faz-bottom position class
      const classes = await banner.getAttribute('class') ?? '';
      expect(classes).toMatch(/bottom/i);
    } finally {
      await visitor.ctx.close();
    }

    // Switch to Box type
    await setSelect(page, 'faz-b-type', 'box');
    await setSelect(page, 'faz-b-position', 'bottom-right');
    await saveBanner(page);

    const visitor2 = await openVisitorPage(browser, wpBaseURL);
    try {
      const banner = visitor2.page.locator('.faz-consent-container');
      await expect(banner).toBeVisible({ timeout: 10_000 });
      const classes = await banner.getAttribute('class') ?? '';
      expect(classes).toMatch(/bottom-right/i);
    } finally {
      await visitor2.ctx.close();
    }
  });

  test('General: admin preview renders inside a real frontend iframe', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    const previewFrame = page.locator('#faz-b-preview-frame');
    await expect(previewFrame).toBeVisible();
    await expect(previewFrame).toHaveAttribute('src', /faz_banner_preview=1/);

    await page.waitForFunction(() => {
      const frame = document.getElementById('faz-b-preview-frame') as HTMLIFrameElement | null;
      if (!frame || !frame.contentWindow) return false;
      try {
        const href = frame.contentWindow.location.href || '';
        return href.includes('faz_banner_preview=1') && !href.includes('/wp-admin/');
      } catch (_unused) {
        return false;
      }
    }, { timeout: 15_000 });

    await expect(
      page.frameLocator('#faz-b-preview-frame').locator('#faz-b-preview-root .faz-consent-container'),
    ).toBeVisible({ timeout: 15_000 });
  });

  test('General: classic type forces pushdown and persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    await setSelect(page, 'faz-b-type', 'classic');
    await saveBanner(page);

    // Reload and verify
    await goToBannerPage(page);
    expect(await getSelectValue(page, 'faz-b-type')).toBe('classic');
    // Classic forces pushdown
    expect(await getSelectValue(page, 'faz-b-pref-type')).toBe('pushdown');

    // Restore to box
    await setSelect(page, 'faz-b-type', 'box');
    await saveBanner(page);
  });

  test('General: theme switch persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    await setSelect(page, 'faz-b-theme', 'dark');
    await saveBanner(page);

    await goToBannerPage(page);
    expect(await getSelectValue(page, 'faz-b-theme')).toBe('dark');

    // Restore light
    await setSelect(page, 'faz-b-theme', 'light');
    await saveBanner(page);
  });

  test('General: preference center type persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    await setSelect(page, 'faz-b-pref-type', 'sidebar');
    await saveBanner(page);

    await goToBannerPage(page);
    expect(await getSelectValue(page, 'faz-b-pref-type')).toBe('sidebar');

    await setSelect(page, 'faz-b-pref-type', 'popup');
    await saveBanner(page);
  });

  test('General: regulation setting persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    // CCPA is stored directly as applicableLaw
    await setSelect(page, 'faz-b-law', 'ccpa');
    await saveBanner(page);

    await goToBannerPage(page);
    expect(await getSelectValue(page, 'faz-b-law')).toBe('ccpa');

    // Restore GDPR
    await setSelect(page, 'faz-b-law', 'gdpr');
    await saveBanner(page);

    await goToBannerPage(page);
    expect(await getSelectValue(page, 'faz-b-law')).toBe('gdpr');
  });

  test('General: consent expiry persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    await setInput(page, 'faz-b-expiry', '90');
    await saveBanner(page);

    await goToBannerPage(page);
    expect(await getInputValue(page, 'faz-b-expiry')).toBe('90');

    // Restore
    await setInput(page, 'faz-b-expiry', '180');
    await saveBanner(page);
  });

  // ─── Content Tab ───────────────────────────────────────

  test('Content: text fields persist and reflect on frontend', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'content');

    const testTitle = 'E2E Test Privacy Title';
    const testAcceptLabel = 'Allow Cookies';
    const testRejectLabel = 'Deny Cookies';
    const testSettingsLabel = 'Preferences';

    await setInput(page, 'faz-b-notice-title', testTitle);
    await setInput(page, 'faz-b-btn-accept-label', testAcceptLabel);
    await setInput(page, 'faz-b-btn-reject-label', testRejectLabel);
    await setInput(page, 'faz-b-btn-settings-label', testSettingsLabel);
    await saveBanner(page);

    // Verify persistence
    await goToBannerPage(page);
    await clickTab(page, 'content');
    expect(await getInputValue(page, 'faz-b-notice-title')).toBe(testTitle);
    expect(await getInputValue(page, 'faz-b-btn-accept-label')).toBe(testAcceptLabel);
    expect(await getInputValue(page, 'faz-b-btn-reject-label')).toBe(testRejectLabel);
    expect(await getInputValue(page, 'faz-b-btn-settings-label')).toBe(testSettingsLabel);

    // Verify on frontend
    const visitor = await openVisitorPage(browser, wpBaseURL);
    try {
      await expect(visitor.page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 10_000 });

      const titleText = await visitor.page.locator('[data-faz-tag="title"]').textContent();
      expect(titleText?.trim()).toBe(testTitle);

      const acceptText = await visitor.page.locator('[data-faz-tag="accept-button"]').textContent();
      expect(acceptText?.trim()).toBe(testAcceptLabel);

      const rejectText = await visitor.page.locator('[data-faz-tag="reject-button"]').textContent();
      expect(rejectText?.trim()).toBe(testRejectLabel);

      const settingsText = await visitor.page.locator('[data-faz-tag="settings-button"]').textContent();
      expect(settingsText?.trim()).toBe(testSettingsLabel);
    } finally {
      await visitor.ctx.close();
    }

    // Restore defaults
    await setInput(page, 'faz-b-notice-title', 'We value your privacy');
    await setInput(page, 'faz-b-btn-accept-label', 'Accept All');
    await setInput(page, 'faz-b-btn-reject-label', 'Reject All');
    await setInput(page, 'faz-b-btn-settings-label', 'Customize');
    await saveBanner(page);
  });

  test('Content: text survives tab switch (issue #18 regression)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'content');

    const draftTitle = 'Draft tab switch title';
    const draftDesc = '<p>Draft tab switch description that should survive the tab change.</p>';

    await setInput(page, 'faz-b-notice-title', draftTitle);
    await setRichText(page, 'faz-b-notice-desc', draftDesc);

    // Switch to General tab and change type (triggers change event)
    await clickTab(page, 'general');
    await setSelect(page, 'faz-b-type', 'banner');
    // Switch back to Content
    await clickTab(page, 'content');

    // Verify unsaved content was preserved across tab switches.
    expect(await getInputValue(page, 'faz-b-notice-title')).toBe(draftTitle);
    expect(await getRichTextValue(page, 'faz-b-notice-desc')).toBe(draftDesc);
  });

  // ─── Colours Tab ───────────────────────────────────────

  test('Colours: notice colours persist and reflect on frontend', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'colours');

    const testBg = '#2d3748';
    const testTitleColor = '#e2e8f0';
    const testDescColor = '#a0aec0';

    await setColorHex(page, 'faz-b-notice-bg-hex', testBg);
    await setColorHex(page, 'faz-b-title-color-hex', testTitleColor);
    await setColorHex(page, 'faz-b-desc-color-hex', testDescColor);
    await saveBanner(page);

    // Verify persistence
    await goToBannerPage(page);
    await clickTab(page, 'colours');
    expect(await getInputValue(page, 'faz-b-notice-bg-hex')).toBe(testBg);
    expect(await getInputValue(page, 'faz-b-title-color-hex')).toBe(testTitleColor);
    expect(await getInputValue(page, 'faz-b-desc-color-hex')).toBe(testDescColor);

    // Verify on frontend
    const visitor = await openVisitorPage(browser, wpBaseURL);
    try {
      const notice = visitor.page.locator('[data-faz-tag="notice"]');
      await expect(notice).toBeVisible({ timeout: 10_000 });

      const bgColor = await notice.evaluate((el) => getComputedStyle(el).backgroundColor);
      // #2d3748 = rgb(45, 55, 72)
      expect(bgColor).toContain('45');

      const title = visitor.page.locator('[data-faz-tag="title"]');
      const titleColor = await title.evaluate((el) => getComputedStyle(el).color);
      // #e2e8f0 = rgb(226, 232, 240)
      expect(titleColor).toContain('226');
    } finally {
      await visitor.ctx.close();
    }

    // Restore via theme reset (light) — theme select is on the General tab
    await clickTab(page, 'general');
    await setSelect(page, 'faz-b-theme', 'light');
    await page.evaluate(() => {
      const el = document.getElementById('faz-b-theme');
      el?.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await saveBanner(page);
  });

  test('Colours: button colours persist', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'colours');

    const testAcceptBg = '#10b981';
    const testRejectBg = '#ef4444';
    const testSettingsBg = '#6366f1';

    await setColorHex(page, 'faz-b-accept-bg-hex', testAcceptBg);
    await setColorHex(page, 'faz-b-reject-bg-hex', testRejectBg);
    await setColorHex(page, 'faz-b-settings-bg-hex', testSettingsBg);
    await saveBanner(page);

    // Verify persistence
    await goToBannerPage(page);
    await clickTab(page, 'colours');
    expect(await getInputValue(page, 'faz-b-accept-bg-hex')).toBe(testAcceptBg);
    expect(await getInputValue(page, 'faz-b-reject-bg-hex')).toBe(testRejectBg);
    expect(await getInputValue(page, 'faz-b-settings-bg-hex')).toBe(testSettingsBg);

    // Restore light theme — theme select is on the General tab
    await clickTab(page, 'general');
    await setSelect(page, 'faz-b-theme', 'light');
    await page.evaluate(() => {
      document.getElementById('faz-b-theme')?.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await saveBanner(page);
  });

  test('Colours: revisit widget colours persist', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'colours');

    await setColorHex(page, 'faz-b-revisit-bg-hex', '#1e40af');
    await setColorHex(page, 'faz-b-revisit-icon-hex', '#fbbf24');
    await saveBanner(page);

    await goToBannerPage(page);
    await clickTab(page, 'colours');
    expect(await getInputValue(page, 'faz-b-revisit-bg-hex')).toBe('#1e40af');
    expect(await getInputValue(page, 'faz-b-revisit-icon-hex')).toBe('#fbbf24');

    // Restore
    await setColorHex(page, 'faz-b-revisit-bg-hex', '#0056a7');
    await setColorHex(page, 'faz-b-revisit-icon-hex', '#ffffff');
    await saveBanner(page);
  });

  test('Colours: link text colour persists and reflects on frontend', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    const nonce = await getAdminNonce(page);
    const testLinkColor = '#ff0000';

    // Seed a deterministic <a> in the banner description so the computed-style
    // check always has a target element on the frontend.
    const banner = await getBanner(page, nonce);
    const origDesc = banner.contents?.description ?? '';
    banner.contents = banner.contents ?? {};
    banner.contents.description = origDesc.replace(
      /<\/p>\s*$/,
      ' <a href="#">link-color-probe</a></p>',
    ) || origDesc + ' <a href="#">link-color-probe</a>';
    await updateBanner(page, nonce, banner.id, {
      name: banner.name, status: banner.status, default: banner.default,
      properties: banner.properties, contents: banner.contents,
    });

    // Exercise the admin UI flow: set colour via the colour picker, then save
    await goToBannerPage(page);
    await clickTab(page, 'colours');
    await setColorHex(page, 'faz-b-link-color-hex', testLinkColor);
    await saveBanner(page);

    // Verify persistence: reload and check the input still has our value
    await goToBannerPage(page);
    await clickTab(page, 'colours');
    expect(await getInputValue(page, 'faz-b-link-color-hex')).toBe(testLinkColor);

    // Also verify via API that the full path was saved
    const updated = await getBanner(page, nonce);
    const savedColor = updated.properties?.config?.accessibilityOverrides?.elements?.manualLinks?.styles?.color;
    expect(savedColor).toBe(testLinkColor);

    // Verify on frontend
    const visitor = await openVisitorPage(browser, wpBaseURL);
    try {
      const notice = visitor.page.locator('[data-faz-tag="notice"]');
      await expect(notice).toBeVisible({ timeout: 10_000 });

      // Check the page source contains the manualLinks config with our color
      const html = await visitor.page.content();
      expect(html).toContain('"manualLinks"');
      expect(html.toLowerCase()).toContain(testLinkColor);

      // Verify JS applied the computed color to links in the notice
      const link = visitor.page.locator('[data-faz-tag="notice"] a:not([data-faz-tag="readmore-button"])').first();
      if (await link.count() > 0) {
        const computedColor = await link.evaluate((el) => getComputedStyle(el).color);
        // #ff0000 = rgb(255, 0, 0)
        expect(computedColor).toContain('255');
      }
    } finally {
      await visitor.ctx.close();
    }

    // Restore original description and theme
    const restoreBanner = await getBanner(page, nonce);
    restoreBanner.contents.description = origDesc;
    await updateBanner(page, nonce, restoreBanner.id, {
      name: restoreBanner.name, status: restoreBanner.status,
      default: restoreBanner.default, properties: restoreBanner.properties,
      contents: restoreBanner.contents,
    });
    await clickTab(page, 'general');
    await setSelect(page, 'faz-b-theme', 'light');
    await page.evaluate(() => {
      document.getElementById('faz-b-theme')?.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await saveBanner(page);
  });

  test('Colours: Do Not Sell text colour persists and reflects on frontend (issue #34)', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    const nonce = await getAdminNonce(page);
    const testDnsColor = '#00cc66';

    // 1. Switch regulation to CCPA so the Do Not Sell button + colour row appear
    await setSelect(page, 'faz-b-law', 'ccpa');
    await saveBanner(page);

    // 2. Set custom Do Not Sell colour via the admin UI (CCPA law auto-enables the button)
    await goToBannerPage(page);
    await clickTab(page, 'colours');
    // Wait for the DNS colour row to be visible (depends on law=ccpa)
    await page.waitForSelector('#faz-donotsell-color-row', { state: 'visible', timeout: 5_000 });
    await setColorHex(page, 'faz-b-donotsell-text-hex', testDnsColor);
    await saveBanner(page);

    // 3. Verify persistence: reload and check the input still has our value
    await goToBannerPage(page);
    await clickTab(page, 'colours');
    await page.waitForSelector('#faz-donotsell-color-row', { state: 'visible', timeout: 5_000 });
    expect(await getInputValue(page, 'faz-b-donotsell-text-hex')).toBe(testDnsColor);

    // 4. Verify via API that the config path was saved
    const updated = await getBanner(page, nonce);
    const savedColor = updated.properties?.config?.notice?.elements?.buttons?.elements?.donotSell?.styles?.color;
    expect(savedColor).toBe(testDnsColor);

    // 5. Verify on frontend: the Do Not Sell button should have the custom colour
    const visitor = await openVisitorPage(browser, wpBaseURL);
    try {
      const banner = visitor.page.locator('[data-faz-tag="notice"]');
      await expect(banner).toBeVisible({ timeout: 10_000 });

      const dnsButton = visitor.page.locator('[data-faz-tag="donotsell-button"]');
      await expect(dnsButton).toBeVisible({ timeout: 5_000 });
      const computedColor = await dnsButton.evaluate((el) => getComputedStyle(el).color);
      // #00cc66 = rgb(0, 204, 102)
      expect(computedColor).toBe('rgb(0, 204, 102)');
    } finally {
      await visitor.ctx.close();
    }

    // 6. Restore to GDPR
    await goToBannerPage(page);
    await setSelect(page, 'faz-b-law', 'gdpr');
    await saveBanner(page);
  });

  // ─── Buttons Tab ───────────────────────────────────────

  test('Buttons: visibility toggles persist and reflect on frontend', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'buttons');

    // Disable reject button
    await setToggle(page, 'faz-b-reject-toggle', false);
    await saveBanner(page);

    // Verify persistence
    await goToBannerPage(page);
    await clickTab(page, 'buttons');
    expect(await getToggle(page, 'faz-b-reject-toggle')).toBe(false);
    expect(await getToggle(page, 'faz-b-accept-toggle')).toBe(true);

    // Verify on frontend: reject button should be hidden
    const visitor = await openVisitorPage(browser, wpBaseURL);
    try {
      await expect(visitor.page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 10_000 });
      await expect(visitor.page.locator('[data-faz-tag="accept-button"]')).toBeVisible();
      // Reject button should not be visible
      const rejectCount = await visitor.page.locator('[data-faz-tag="reject-button"]').count();
      if (rejectCount > 0) {
        await expect(visitor.page.locator('[data-faz-tag="reject-button"]')).toBeHidden();
      }
    } finally {
      await visitor.ctx.close();
    }

    // Re-enable reject button
    await clickTab(page, 'buttons');
    await setToggle(page, 'faz-b-reject-toggle', true);
    await saveBanner(page);

    // Verify restored on frontend
    const visitor2 = await openVisitorPage(browser, wpBaseURL);
    try {
      await expect(visitor2.page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 10_000 });
      await expect(visitor2.page.locator('[data-faz-tag="reject-button"]')).toBeVisible();
    } finally {
      await visitor2.ctx.close();
    }
  });

  test('Buttons: close button toggle persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'buttons');

    await setToggle(page, 'faz-b-close-toggle', false);
    await saveBanner(page);

    await goToBannerPage(page);
    await clickTab(page, 'buttons');
    expect(await getToggle(page, 'faz-b-close-toggle')).toBe(false);

    // Restore
    await setToggle(page, 'faz-b-close-toggle', true);
    await saveBanner(page);
  });

  test('Buttons: read more toggle persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'buttons');

    const original = await getToggle(page, 'faz-b-readmore-toggle');
    await setToggle(page, 'faz-b-readmore-toggle', !original);
    await saveBanner(page);

    await goToBannerPage(page);
    await clickTab(page, 'buttons');
    expect(await getToggle(page, 'faz-b-readmore-toggle')).toBe(!original);

    // Restore
    await setToggle(page, 'faz-b-readmore-toggle', original);
    await saveBanner(page);
  });

  // ─── Preference Center Tab ─────────────────────────────

  test('Preferences: text fields persist and reflect on frontend', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'preferences');

    const testPrefTitle = 'E2E Preference Title';
    const testPrefAccept = 'Allow Everything';
    const testPrefSave = 'Save Choices';
    const testPrefReject = 'Deny Everything';

    await setInput(page, 'faz-b-pref-title', testPrefTitle);
    await setInput(page, 'faz-b-pref-accept', testPrefAccept);
    await setInput(page, 'faz-b-pref-save', testPrefSave);
    await setInput(page, 'faz-b-pref-reject', testPrefReject);
    await saveBanner(page);

    // Verify persistence
    await goToBannerPage(page);
    await clickTab(page, 'preferences');
    expect(await getInputValue(page, 'faz-b-pref-title')).toBe(testPrefTitle);
    expect(await getInputValue(page, 'faz-b-pref-accept')).toBe(testPrefAccept);
    expect(await getInputValue(page, 'faz-b-pref-save')).toBe(testPrefSave);
    expect(await getInputValue(page, 'faz-b-pref-reject')).toBe(testPrefReject);

    // Verify on frontend: open preference center and check title
    const visitor = await openVisitorPage(browser, wpBaseURL);
    try {
      await expect(visitor.page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 10_000 });

      // Click the settings/customize button to open preference center
      const settingsBtn = visitor.page.locator('[data-faz-tag="settings-button"]');
      if (await settingsBtn.isVisible()) {
        await settingsBtn.click();
        await visitor.page.waitForSelector('[data-faz-tag="detail-title"]', { state: 'visible', timeout: 5_000 });

        const prefTitle = visitor.page.locator('[data-faz-tag="detail-title"]');
        if (await prefTitle.count() > 0) {
          const text = await prefTitle.textContent();
          expect(text?.trim()).toBe(testPrefTitle);
        }
      }
    } finally {
      await visitor.ctx.close();
    }

    // Restore
    await clickTab(page, 'preferences');
    await setInput(page, 'faz-b-pref-title', 'Customize consent preferences');
    await setInput(page, 'faz-b-pref-accept', 'Accept All');
    await setInput(page, 'faz-b-pref-save', 'Save My Preferences');
    await setInput(page, 'faz-b-pref-reject', 'Reject All');
    await saveBanner(page);
  });

  test('Preferences: audit table toggle persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'preferences');

    const original = await getToggle(page, 'faz-b-audit-toggle');
    await setToggle(page, 'faz-b-audit-toggle', !original);
    await saveBanner(page);

    await goToBannerPage(page);
    await clickTab(page, 'preferences');
    expect(await getToggle(page, 'faz-b-audit-toggle')).toBe(!original);

    // Restore
    await setToggle(page, 'faz-b-audit-toggle', original);
    await saveBanner(page);
  });

  // ─── Advanced Tab ──────────────────────────────────────

  test('Advanced: revisit consent settings persist and reflect on frontend', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'advanced');

    // Enable revisit + set position
    await setToggle(page, 'faz-b-revisit-toggle', true);
    await setSelect(page, 'faz-b-revisit-position', 'bottom-right');
    await setInput(page, 'faz-b-revisit-title', 'E2E Consent Widget');
    await saveBanner(page);

    // Verify persistence
    await goToBannerPage(page);
    await clickTab(page, 'advanced');
    expect(await getToggle(page, 'faz-b-revisit-toggle')).toBe(true);
    expect(await getSelectValue(page, 'faz-b-revisit-position')).toBe('bottom-right');
    expect(await getInputValue(page, 'faz-b-revisit-title')).toBe('E2E Consent Widget');

    // Verify on frontend: accept consent first, then check revisit widget
    const visitor = await openVisitorPage(browser, wpBaseURL);
    try {
      await expect(visitor.page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 20_000 });
      // Accept to dismiss banner and show revisit widget
      const acceptBtn = visitor.page.locator('[data-faz-tag="accept-button"]');
      if (await acceptBtn.isVisible()) {
        await acceptBtn.click();
        // Wait for the banner to hide after accept
        await visitor.page.waitForSelector('[data-faz-tag="notice"]', { state: 'hidden', timeout: 5_000 }).catch(() => {});
      }
      const revisitWidget = visitor.page.locator('[data-faz-tag="revisit-consent"]');
      if (await revisitWidget.count() > 0) {
        await expect(revisitWidget).toBeVisible({ timeout: 5_000 });
      }
    } finally {
      await visitor.ctx.close();
    }

    // Restore
    await setInput(page, 'faz-b-revisit-title', 'Consent Preferences');
    await setSelect(page, 'faz-b-revisit-position', 'bottom-left');
    await saveBanner(page);
  });

  test('Advanced: behaviour toggles persist', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    await clickTab(page, 'advanced');

    const origReload = await getToggle(page, 'faz-b-reload-toggle');
    const origGPC = await getToggle(page, 'faz-b-gpc-toggle');

    await setToggle(page, 'faz-b-reload-toggle', !origReload);
    await setToggle(page, 'faz-b-gpc-toggle', !origGPC);
    await saveBanner(page);

    await goToBannerPage(page);
    await clickTab(page, 'advanced');
    expect(await getToggle(page, 'faz-b-reload-toggle')).toBe(!origReload);
    expect(await getToggle(page, 'faz-b-gpc-toggle')).toBe(!origGPC);

    // Restore
    await setToggle(page, 'faz-b-reload-toggle', origReload);
    await setToggle(page, 'faz-b-gpc-toggle', origGPC);
    await saveBanner(page);
  });

  // ─── Cross-tab Integration ─────────────────────────────

  test('Cross-tab: changing all settings in one session persists correctly', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    // General
    await setSelect(page, 'faz-b-type', 'banner');
    await setSelect(page, 'faz-b-position', 'top');
    await setSelect(page, 'faz-b-pref-type', 'sidebar');
    await setInput(page, 'faz-b-expiry', '30');

    // Content
    await clickTab(page, 'content');
    await setInput(page, 'faz-b-notice-title', 'Cross-tab Test Title');
    await setInput(page, 'faz-b-btn-accept-label', 'CT Accept');

    // Colours
    await clickTab(page, 'colours');
    await setColorHex(page, 'faz-b-notice-bg-hex', '#1a202c');

    // Buttons
    await clickTab(page, 'buttons');
    await setToggle(page, 'faz-b-settings-toggle', true);

    // Preferences
    await clickTab(page, 'preferences');
    await setInput(page, 'faz-b-pref-title', 'CT Preferences');

    // Advanced
    await clickTab(page, 'advanced');
    await setToggle(page, 'faz-b-revisit-toggle', true);

    // Save once
    await saveBanner(page);

    // Reload and verify ALL settings across ALL tabs
    await goToBannerPage(page);

    // General tab (active by default)
    expect(await getSelectValue(page, 'faz-b-type')).toBe('banner');
    expect(await getSelectValue(page, 'faz-b-position')).toBe('top');
    expect(await getSelectValue(page, 'faz-b-pref-type')).toBe('sidebar');
    expect(await getInputValue(page, 'faz-b-expiry')).toBe('30');

    // Content
    await clickTab(page, 'content');
    expect(await getInputValue(page, 'faz-b-notice-title')).toBe('Cross-tab Test Title');
    expect(await getInputValue(page, 'faz-b-btn-accept-label')).toBe('CT Accept');

    // Colours
    await clickTab(page, 'colours');
    expect(await getInputValue(page, 'faz-b-notice-bg-hex')).toBe('#1a202c');

    // Buttons
    await clickTab(page, 'buttons');
    expect(await getToggle(page, 'faz-b-settings-toggle')).toBe(true);

    // Preferences
    await clickTab(page, 'preferences');
    expect(await getInputValue(page, 'faz-b-pref-title')).toBe('CT Preferences');

    // Advanced
    await clickTab(page, 'advanced');
    expect(await getToggle(page, 'faz-b-revisit-toggle')).toBe(true);

    // Verify frontend reflects cross-tab changes
    const visitor = await openVisitorPage(browser, wpBaseURL);
    try {
      const notice = visitor.page.locator('[data-faz-tag="notice"]');
      await expect(notice).toBeVisible({ timeout: 10_000 });

      // Check title text
      const titleText = await visitor.page.locator('[data-faz-tag="title"]').textContent();
      expect(titleText?.trim()).toBe('Cross-tab Test Title');

      // Check accept button text
      const acceptText = await visitor.page.locator('[data-faz-tag="accept-button"]').textContent();
      expect(acceptText?.trim()).toBe('CT Accept');

      // Check background colour (rgb(26, 32, 44) = #1a202c)
      const bgColor = await notice.evaluate((el) => getComputedStyle(el).backgroundColor);
      expect(bgColor).toContain('26');
    } finally {
      await visitor.ctx.close();
    }
  });

  // ─── API-level verification ────────────────────────────

  test('API: banner data round-trips correctly via REST', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);
    const n = await getAdminNonce(page);

    const banner = await getBanner(page, n);
    expect(banner.id).toBeDefined();
    expect(banner.properties).toBeDefined();
    expect(banner.contents).toBeDefined();
    expect(banner.properties.settings).toBeDefined();
    expect(banner.properties.settings.type).toBeDefined();

    // Update via API and read back
    const updatedType = banner.properties.settings.type === 'box' ? 'banner' : 'box';
    const modified = JSON.parse(JSON.stringify(banner));
    modified.properties.settings.type = updatedType;

    const result = await updateBanner(page, n, 1, {
      name: modified.name,
      status: modified.status,
      default: modified.default,
      properties: modified.properties,
      contents: modified.contents,
    });

    expect(result.properties.settings.type).toBe(updatedType);

    // Read back independently
    const readBack = await getBanner(page, n);
    expect(readBack.properties.settings.type).toBe(updatedType);

    // Restore original
    await updateBanner(page, n, 1, {
      name: banner.name,
      status: banner.status,
      default: banner.default,
      properties: banner.properties,
      contents: banner.contents,
    });
  });

  // ─── Intentionally empty text fields ──────────────────────

  test('Empty banner title stays empty on frontend (no en.json fallback)', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    // Navigate to any admin page to get the nonce — skip goToBannerPage
    // since preloading serves the banner API from cache (no network response to wait for).
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    const n = await getAdminNonce(page);
    const banner = await getBanner(page, n);

    // Deep-copy and clear the notice title in base and all language layers
    const modified = JSON.parse(JSON.stringify(banner));
    if (modified.contents?.notice?.elements) {
      modified.contents.notice.elements.title = '';
    }
    for (const lang of Object.keys(modified.contents || {})) {
      if (modified.contents[lang]?.notice?.elements) {
        modified.contents[lang].notice.elements.title = '';
      }
    }

    await updateBanner(page, n, banner.id, {
      name: modified.name,
      status: modified.status,
      default: modified.default,
      properties: modified.properties,
      contents: modified.contents,
    });

    try {
      const visitor = await openVisitorPage(browser, wpBaseURL);
      try {
        await expect(visitor.page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 10_000 });

        // The title element should either be absent or empty — NOT the en.json default
        const titleEl = visitor.page.locator('[data-faz-tag="title"]');
        const titleCount = await titleEl.count();
        if (titleCount > 0) {
          const titleText = await titleEl.textContent();
          expect(titleText?.trim(), 'Title should be empty, not filled by en.json fallback').toBe('');
        }
        // If count is 0, the template correctly omitted the empty element — also valid
      } finally {
        await visitor.ctx.close();
      }
    } finally {
      // Restore original
      await updateBanner(page, n, banner.id, {
        name: banner.name,
        status: banner.status,
        default: banner.default,
        properties: banner.properties,
        contents: banner.contents,
      });
    }
  });
});
