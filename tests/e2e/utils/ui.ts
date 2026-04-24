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
          // Between `isVisible()` and `click()` the element can detach;
          // without an explicit timeout Playwright falls back to the
          // 30 s default action timeout, silently busting the caller's
          // overall budget. Cap it to whatever is left on the deadline
          // (with a floor so a one-shot click that arrives right at the
          // deadline still has a chance to land).
          const remaining = Math.max(50, deadline - Date.now());
          await el.click({ timeout: remaining });
          return true;
        }
      }
    }
    await page.waitForTimeout(50);
  } while (Date.now() < deadline);

  return false;
}
