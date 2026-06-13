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

    // Use auto-waiting assertions throughout so a read never races the UI
    // update that selectOption() triggers. The <option> disabled state goes
    // through expect.poll(() => classicOpt.isDisabled()): toBeDisabled() and
    // toHaveJSProperty('disabled', …) don't reliably reflect an <option>'s
    // disabled state in Playwright, while isDisabled() does — poll keeps it
    // auto-waiting. The hint/value checks use the native auto-waiting matchers.
    const classicDisabled = (val: boolean, msg: string) =>
      expect.poll(() => classicOpt.isDisabled(), { message: msg }).toBe(val);

    // Baseline: GDPR + Classic is a valid combination.
    await page.selectOption('#faz-b-law', 'gdpr');
    await page.selectOption('#faz-b-type', 'classic');
    await classicDisabled(false, 'Classic allowed under GDPR');
    await expect(hint, 'no CCPA hint under GDPR').toBeHidden();

    // Switch to pure CCPA → Classic must become unavailable and the current
    // Classic selection must migrate to Box so the opt-out popup exists.
    await page.selectOption('#faz-b-law', 'ccpa');
    await classicDisabled(true, 'Classic disabled under CCPA');
    await expect(page.locator('#faz-b-type'), 'Classic migrated to Box').toHaveValue('box');
    await expect(hint, 'CCPA incompatibility hint shown').toBeVisible();

    // "Both GDPR + US State Laws" maps to applicableLaw=gdpr (detail preference
    // center, which Classic has), so Classic stays available there.
    await page.selectOption('#faz-b-law', 'gdpr_ccpa');
    await classicDisabled(false, 'Classic allowed under gdpr_ccpa');
    await expect(hint, 'hint hidden under gdpr_ccpa').toBeHidden();
  });
});
