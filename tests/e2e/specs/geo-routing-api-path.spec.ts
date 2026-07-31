/**
 * Issue #198 — the shared FAZ client prepends only `faz/v1/`, while the
 * geo-routing endpoints live below `faz/v1/geo/`. The raw-fetch fallback was
 * already correct; this browser test pins the normal FAZ-client branch.
 */

import { test, expect } from '../fixtures/wp-fixture';

const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-geo-routing';

test('geo-routing uses the module REST base through the normal FAZ client', async ({ page, loginAsAdmin }) => {
  await loginAsAdmin(page);

  const seen: Array<{ url: string; status: number }> = [];
  page.on('response', (response) => {
    if (response.url().includes('/wp-json/faz/v1/')) {
      seen.push({ url: response.url(), status: response.status() });
    }
  });

  await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#faz-geo-status-content .faz-loading')).toHaveCount(0);
  await expect(page.locator('#faz-geo-status-content .faz-geo-error')).toHaveCount(0);

  expect(
    seen.some(({ url, status }) => url.includes('/wp-json/faz/v1/geo/status') && status === 200),
    `expected a successful /faz/v1/geo/status request, saw ${JSON.stringify(seen)}`,
  ).toBe(true);
  expect(
    seen.some(({ url }) => /\/wp-json\/faz\/v1\/status(?:[/?]|$)/.test(url)),
    'the broken unscoped /faz/v1/status path must never be requested',
  ).toBe(false);
});
