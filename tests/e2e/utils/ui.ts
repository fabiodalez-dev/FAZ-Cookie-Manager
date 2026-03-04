import type { Page } from '@playwright/test';

export async function clickFirstVisible(page: Page, selectors: string[]): Promise<boolean> {
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
  return false;
}
