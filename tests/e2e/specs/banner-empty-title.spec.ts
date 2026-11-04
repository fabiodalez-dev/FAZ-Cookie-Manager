/**
 * An empty notice title is not rendered.
 *
 * Reported on the wp.org forum ("More Control than Colours"): with the title
 * cleared in the Content tab the banner still carried an empty
 * `<p class="faz-title" role="heading" aria-level="2">`, so its bottom margin
 * pushed the description down and screen readers met a heading with no text.
 * Driven the way an administrator does it — Content tab, save, template
 * regeneration — and read from the banner a visitor receives.
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

async function openContent(page: Page) {
  // The form is filled by the banner GET that loadBanner() sends on init: a
  // field filled before it lands is overwritten, and a save sent before it is
  // a no-op that never issues the PUT.
  const loaded = page.waitForResponse(
    (r) => /banners(\/|%2F)\d+/i.test(r.url()) && r.request().method() === 'GET' && r.ok(),
    { timeout: 30_000 },
  );
  await page.goto(EDITOR, { waitUntil: 'domcontentloaded' });
  await loaded;
  await page.click('button.faz-tab[data-tab="content"]');
  await expect(page.locator('#faz-b-notice-title')).toBeVisible();
}

async function visitorBanner(page: Page) {
  const visitor = await page.context().browser()!.newContext();
  const front = await visitor.newPage();
  await front.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(front.locator('.faz-consent-container .faz-notice-des').first()).toBeVisible();
  return { visitor, front };
}

test.beforeAll(() => {
  resetDefaultBannerState();
});

for (const [label, value] of [['empty', ''], ['whitespace-only', '   ']] as const) {
  test(`an ${label} notice title leaves no heading in the banner`, async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await openContent(page);
    const previous = await page.locator('#faz-b-notice-title').inputValue();
    try {
      await page.locator('#faz-b-notice-title').fill(value);
      await saveBanner(page);

      const { visitor, front } = await visitorBanner(page);
      try {
        await expect(front.locator('.faz-consent-container [data-faz-tag="title"]')).toHaveCount(0);
        const container = front.locator('.faz-consent-container').first();
        await expect(container.getByRole('heading')).toHaveCount(0);
        // The dialog is still named: by its aria-label, not by a missing title.
        // role="dialog" is a11y.js's mark that it has run.
        await expect(container).toHaveAttribute('role', 'dialog');
        expect(await container.getAttribute('aria-labelledby')).toBeNull();
        expect(await container.getAttribute('aria-label')).toBeTruthy();
      } finally {
        await visitor.close();
      }
    } finally {
      await openContent(page);
      await page.locator('#faz-b-notice-title').fill(previous);
      await saveBanner(page);
    }
  });
}

test('a notice title with text is still rendered as the heading', async ({ page, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await openContent(page);
  const previous = await page.locator('#faz-b-notice-title').inputValue();
  try {
    await page.locator('#faz-b-notice-title').fill('Cookies on this site');
    await saveBanner(page);

    const { visitor, front } = await visitorBanner(page);
    try {
      const container = front.locator('.faz-consent-container').first();
      const title = container.getByRole('heading', { name: 'Cookies on this site' });
      await expect(title).toBeVisible();
      await expect(title).toHaveAttribute('id', 'faz-banner-title');
      await expect(container).toHaveAttribute('aria-labelledby', 'faz-banner-title');
    } finally {
      await visitor.close();
    }
  } finally {
    await openContent(page);
    await page.locator('#faz-b-notice-title').fill(previous);
    await saveBanner(page);
  }
});
