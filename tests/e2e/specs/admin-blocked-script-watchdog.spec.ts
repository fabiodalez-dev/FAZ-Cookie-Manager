import { test, expect } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';

/**
 * Blocked-script watchdog (support topic "Cookie Policy saving issue").
 *
 * The Cookie Policy and Cookies admin pages are driven by per-page scripts
 * whose filenames contain "cookie" (cookie-policy.js / cookies.js). Some ad
 * blockers and browser privacy shields (e.g. Brave Shield) match those names
 * and block the file, leaving the page silently inert — Save/Preview and the
 * buttons do nothing with no explanation. The page already refuses the native
 * form submit (no blank-page data loss), but gave no feedback.
 *
 * Each view now renders a hidden, server-translated `notice notice-error` plus a
 * tiny INLINE watchdog: the page script sets a `window.faz*Booted` flag the
 * instant it runs; if the flag is still unset shortly after load (the script was
 * blocked), the inline script reveals the notice. Inline first-party script is
 * not blocked the way the external .js file is, so the message always reaches
 * the user.
 *
 * These tests drive the real behaviour: abort the page script request to
 * simulate the blocker (notice must appear), and load it normally (notice must
 * stay hidden, flag set). Reusable + self-contained — no fixtures created, no
 * settings mutated.
 */

const PAGES = [
  {
    name: 'Cookie Policy',
    url: '/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy',
    script: '**/pages/cookie-policy.js*',
    notice: '#faz-cp-script-blocked',
    flag: 'fazCpBooted',
  },
  {
    name: 'Cookies',
    url: '/wp-admin/admin.php?page=faz-cookie-manager-cookies',
    script: '**/pages/cookies.js*',
    notice: '#faz-cookies-script-blocked',
    flag: 'fazCookiesBooted',
  },
] as const;

async function flagSet(page: Page, flag: string): Promise<boolean> {
  return page.evaluate((f) => Boolean((window as unknown as Record<string, unknown>)[f]), flag);
}

test.describe('Admin blocked-script watchdog', () => {
  test.describe.configure({ mode: 'serial' });

  for (const p of PAGES) {
    test(`${p.name}: blocked page script reveals the actionable notice`, async ({ page, loginAsAdmin }) => {
      await loginAsAdmin(page);
      // Simulate an ad blocker / shield dropping the per-page script by name.
      await page.route(p.script, (route) => route.abort());
      await page.goto(p.url, { waitUntil: 'domcontentloaded' });
      // The script never ran, so its boot flag stays unset…
      expect(await flagSet(page, p.flag)).toBe(false);
      // …and the inline watchdog reveals the notice (2.5s timeout + margin).
      await expect(page.locator(p.notice)).toBeVisible({ timeout: 6000 });
      await expect(page.locator(`${p.notice} p`)).toContainText(/did not load|will not work/i);
    });

    test(`${p.name}: when the script loads, the notice stays hidden`, async ({ page, loginAsAdmin }) => {
      await loginAsAdmin(page);
      await page.goto(p.url, { waitUntil: 'domcontentloaded' });
      // The page script ran and set its boot flag.
      await expect.poll(() => flagSet(page, p.flag), { timeout: 6000 }).toBe(true);
      // Give the watchdog's 2.5s timer time to (wrongly) fire, then confirm the
      // notice is still hidden — the flag suppresses it.
      await page.waitForTimeout(3000);
      await expect(page.locator(p.notice)).toBeHidden();
    });
  }
});
