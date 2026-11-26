/**
 * Notice background opacity and text alignment, end to end.
 *
 * Requested on the wp.org forum ("More Control than Colours"): opacity and
 * text alignment bound to the plugin rather than the theme. Driven the way an
 * administrator does it — Colours tab, save, template regeneration — and read
 * from the computed style of the banner a visitor receives, because the banner
 * HTML and CSS are cached in faz_banner_template. The shape checks are in
 * tests/unit/test-banner-opacity-text-align-php.php.
 */
import type { Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';

const EDITOR = '/wp-admin/admin.php?page=faz-cookie-manager-banner';

async function saveBanner(page: Page) {
  const responsePromise = page.waitForResponse(
    (r) =>
      r.url().includes('banners') &&
      !r.url().includes('preview') &&
      (r.request().method() === 'PUT' || r.request().method() === 'POST'),
    { timeout: 30_000 },
  );
  await page.click('#faz-b-save');
  const response = await responsePromise;
  if (response.status() !== 200) {
    throw new Error(`Banner save failed (${response.status()}): ${await response.text()}`);
  }
  await page.waitForSelector('.faz-toast-success', { state: 'visible', timeout: 10_000 }).catch(() => {});
}

async function openColours(page: Page) {
  // loadBanner() fills the form asynchronously (the banner GET is served by
  // apiFetch's preloading middleware, so no request reaches the network): a
  // field filled before it runs is overwritten, and a save sent before it is a
  // no-op that never issues the PUT. The banner name is filled after the
  // settings, so a non-empty name means the form is ready.
  await page.goto(EDITOR, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#faz-b-name')).not.toHaveValue('', { timeout: 30_000 });
  await page.click('button.faz-tab[data-tab="colours"]');
  await expect(page.locator('#faz-b-notice-opacity')).toBeVisible();
}

test.beforeAll(() => {
  resetDefaultBannerState();
});

test('notice opacity and text alignment set in the admin reach the rendered banner', async ({ page, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await openColours(page);
  const previousOpacity = await page.locator('#faz-b-notice-opacity').inputValue();
  const previousAlign = await page.locator('#faz-b-text-align').inputValue();
  try {
    await page.locator('#faz-b-notice-opacity').fill('80');
    await page.locator('#faz-b-text-align').selectOption('center');
    await saveBanner(page);

    // The values come back in the editor after a reload, so they were stored.
    await openColours(page);
    await expect(page.locator('#faz-b-notice-opacity')).toHaveValue('80');
    await expect(page.locator('#faz-b-text-align')).toHaveValue('center');

    const visitor = await page.context().browser()!.newContext();
    try {
      const front = await visitor.newPage();
      await front.goto('/', { waitUntil: 'domcontentloaded' });
      const bar = front.locator('.faz-consent-container .faz-consent-bar').first();
      await expect(bar).toBeVisible();

      // Only the background is translucent; the notice itself is not faded.
      await expect.poll(() => bar.evaluate((el) => getComputedStyle(el).backgroundColor)).toMatch(/^rgba\(\d+, \d+, \d+, 0\.8\)$/);
      expect(await bar.evaluate((el) => getComputedStyle(el).opacity)).toBe('1');

      for (const selector of ['.faz-title', '.faz-notice-des']) {
        const el = front.locator(`.faz-consent-container ${selector}`).first();
        await expect.poll(() => el.evaluate((n) => getComputedStyle(n).textAlign), { message: selector }).toBe('center');
      }
    } finally {
      await visitor.close();
    }
  } finally {
    await openColours(page);
    await page.locator('#faz-b-notice-opacity').fill(previousOpacity);
    await page.locator('#faz-b-text-align').selectOption(previousAlign);
    await saveBanner(page);
  }
});

test('an untouched banner keeps a solid background and the template alignment', async ({ browser, baseURL }) => {
  const visitor = await browser.newContext();
  try {
    const front = await visitor.newPage();
    await front.goto((baseURL || '') + '/', { waitUntil: 'domcontentloaded' });
    const bar = front.locator('.faz-consent-container .faz-consent-bar').first();
    await expect(bar).toBeVisible();
    expect(await bar.evaluate((el) => getComputedStyle(el).backgroundColor)).toMatch(/^rgb\(/);
    const css = await front.evaluate(() => Array.from(document.querySelectorAll('style')).map((s) => s.textContent || '').join('\n'));
    expect(css).not.toContain('--faz-notice-text-align');
  } finally {
    await visitor.close();
  }
});
