import type { Page } from '@playwright/test';

export async function clickFirstVisible(page: Page, selectors: string[], timeoutMs = 2_000): Promise<boolean> {
  const deadline = Date.now() + timeoutMs;
  do {
    for (const selector of selectors) {
      const loc = page.locator(selector);
      const count = await loc.count();
      for (let i = 0; i < count; i++) {
        const el = loc.nth(i);
        if (await el.isVisible().catch(() => false)) {
          await el.click();
          return true;
        }
      }
    }
    await page.waitForTimeout(50);
  } while (Date.now() < deadline);

  return false;
}
