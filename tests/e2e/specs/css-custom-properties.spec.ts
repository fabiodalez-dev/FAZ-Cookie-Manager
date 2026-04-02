import { expect, test } from '../fixtures/wp-fixture';

async function openVisitorPage(browser: any, baseURL: string) {
  const ctx = await browser.newContext({
    baseURL,
    locale: 'en-US',
    extraHTTPHeaders: { 'Accept-Language': 'en-US' },
  });
  const page = await ctx.newPage();
  await page.goto('/', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  return { page, ctx };
}

test.describe('CSS Custom Properties', () => {
  test('banner elements have no inline style attributes', async ({ browser, wpBaseURL }) => {
    const { page, ctx } = await openVisitorPage(browser, wpBaseURL);
    try {
      const notice = page.locator('[data-faz-tag="notice"]');
      await expect(notice).toBeVisible({ timeout: 15_000 });

      // No [data-faz-tag] element inside #faz-consent should have a style= attribute
      const elementsWithInlineStyle = await page.evaluate(() => {
        const consent = document.getElementById('faz-consent');
        if (!consent) return [];
        return Array.from(consent.querySelectorAll('[data-faz-tag]'))
          .filter(el => el.getAttribute('style') !== null && el.getAttribute('style') !== '')
          .map(el => el.getAttribute('data-faz-tag'));
      });
      expect(elementsWithInlineStyle, 'Elements with inline styles: ' + elementsWithInlineStyle.join(', ')).toEqual([]);
    } finally {
      await ctx.close();
    }
  });

  test('CSS custom properties are set on #faz-consent', async ({ browser, wpBaseURL }) => {
    const { page, ctx } = await openVisitorPage(browser, wpBaseURL);
    try {
      await page.locator('[data-faz-tag="notice"]').waitFor({ state: 'visible', timeout: 15_000 });

      const acceptBgVar = await page.evaluate(() => {
        const consent = document.getElementById('faz-consent');
        if (!consent) return null;
        return getComputedStyle(consent).getPropertyValue('--faz-accept-button-background-color').trim();
      });
      // Must be a valid hex colour (any value from the admin settings)
      expect(acceptBgVar).toMatch(/^#[0-9a-fA-F]{3,8}$/);

      const noticeBgVar = await page.evaluate(() => {
        const consent = document.getElementById('faz-consent');
        if (!consent) return null;
        return getComputedStyle(consent).getPropertyValue('--faz-notice-background-color').trim();
      });
      expect(noticeBgVar).toMatch(/^#[0-9a-fA-F]{3,8}$/);
    } finally {
      await ctx.close();
    }
  });

  test('accept button computed color comes from CSS var (not inline style)', async ({ browser, wpBaseURL }) => {
    const { page, ctx } = await openVisitorPage(browser, wpBaseURL);
    try {
      await page.locator('[data-faz-tag="notice"]').waitFor({ state: 'visible', timeout: 15_000 });

      const acceptBtn = page.locator('[data-faz-tag="accept-button"]').first();
      await expect(acceptBtn).toBeVisible({ timeout: 5_000 });

      // Should have no inline style
      const inlineStyle = await acceptBtn.getAttribute('style');
      expect(inlineStyle ?? '').toBe('');

      // Computed color should be a valid RGB value (from CSS var, not inline style)
      const computed = await acceptBtn.evaluate((el) => getComputedStyle(el).color);
      expect(computed).toMatch(/^rgb\(\d+,\s*\d+,\s*\d+\)$/);
    } finally {
      await ctx.close();
    }
  });

  test('no inline style attributes on any element inside #faz-consent (including preference center)', async ({ browser, wpBaseURL }) => {
    const { page, ctx } = await openVisitorPage(browser, wpBaseURL);
    try {
      const notice = page.locator('[data-faz-tag="notice"]');
      await expect(notice).toBeVisible({ timeout: 15_000 });

      // Open the preference center to expose .faz-always-active and .faz-footer-shadow.
      await page.locator('[data-faz-tag="settings-button"]').click();
      await page.locator('[data-faz-tag="detail"]').waitFor({ state: 'visible', timeout: 10_000 });

      const elementsWithInlineStyle = await page.evaluate(() => {
        const consent = document.getElementById('faz-consent');
        if (!consent) return [];
        return Array.from(consent.querySelectorAll('*'))
          .filter(el => {
            const s = el.getAttribute('style');
            return s !== null && s.trim() !== '';
          })
          .map(el => `<${el.tagName.toLowerCase()} class="${el.className}" style="${el.getAttribute('style')}">`);
      });
      expect(
        elementsWithInlineStyle,
        'Elements with inline styles:\n' + elementsWithInlineStyle.join('\n')
      ).toEqual([]);
    } finally {
      await ctx.close();
    }
  });
});
