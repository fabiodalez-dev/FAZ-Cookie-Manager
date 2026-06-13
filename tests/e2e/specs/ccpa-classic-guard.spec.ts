/**
 * Compliance guard: the Classic banner layout must not be selectable for a
 * pure-CCPA banner.
 *
 * A pure-CCPA banner serves the "Do Not Sell or Share My Personal Information"
 * opt-out, whose toggle lives in the optout-popup. The Classic template type
 * does not render that popup, so a Classic + CCPA banner exposes a "Do Not Sell"
 * link that opens nothing — a non-functional opt-out, which fails the CCPA/CPRA
 * requirement that the link lead to a working opt-out. The admin therefore
 * disables the Classic option whenever the law is CCPA (gdpr_ccpa maps to
 * applicableLaw=gdpr and uses the detail preference center, which Classic does
 * have, so it stays allowed).
 */

import { test, expect } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

async function goToBannerPage(page: Page) {
  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
    waitUntil: 'domcontentloaded',
    timeout: 45_000,
  });
  await page.waitForFunction(
    () => {
      const el = document.getElementById('faz-b-type') as HTMLSelectElement | null;
      return !!el && el.value !== '';
    },
    { timeout: 10_000 },
  );
}

test.describe('CCPA + Classic layout guard', () => {
  test('CCPA law disables Classic and migrates a Classic selection to Box', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await goToBannerPage(page);

    const classicOpt = page.locator('#faz-b-type option[value="classic"]');
    const hint = page.locator('#faz-b-type-ccpa-hint');

    // Baseline: GDPR + Classic is a valid combination.
    await page.selectOption('#faz-b-law', 'gdpr');
    await page.selectOption('#faz-b-type', 'classic');
    expect(await classicOpt.isDisabled(), 'Classic allowed under GDPR').toBe(false);
    await expect(hint, 'no CCPA hint under GDPR').toBeHidden();

    // Switch to pure CCPA → Classic must become unavailable and the current
    // Classic selection must migrate to Box so the opt-out popup exists.
    await page.selectOption('#faz-b-law', 'ccpa');
    expect(await classicOpt.isDisabled(), 'Classic disabled under CCPA').toBe(true);
    expect(await page.locator('#faz-b-type').inputValue(), 'Classic migrated to Box').toBe('box');
    await expect(hint, 'CCPA incompatibility hint shown').toBeVisible();

    // "Both GDPR + US State Laws" maps to applicableLaw=gdpr (detail preference
    // center, which Classic has), so Classic stays available there.
    await page.selectOption('#faz-b-law', 'gdpr_ccpa');
    expect(await classicOpt.isDisabled(), 'Classic allowed under gdpr_ccpa').toBe(false);
    await expect(hint, 'hint hidden under gdpr_ccpa').toBeHidden();
  });
});
