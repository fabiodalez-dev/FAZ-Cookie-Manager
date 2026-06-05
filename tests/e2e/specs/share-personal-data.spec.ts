/**
 * E2E — separate CPRA "share" flag in the category editor (1.17.2).
 *
 * CPRA §1798.140 distinguishes a "sale" from "sharing" (cross-context
 * behavioural advertising); both are covered by the combined "Do Not Sell or
 * Share" opt-out. The Cookies admin page now exposes per-category Sell / Share
 * toggles, persisted to the new share_personal_data column. The "necessary"
 * category — exempt from the opt-out by definition — shows no toggles.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

function marketingShareFlag(): string {
  return wpEval(
    `global $wpdb;$t=$wpdb->prefix.'faz_cookie_categories';` +
    `echo (string)$wpdb->get_var("SELECT share_personal_data FROM $t WHERE slug='marketing'");`,
  ).trim().split('\n').pop() || '';
}

test.describe('CPRA sale/sharing flags in the category editor (1.17.2)', () => {
  test.afterAll(() => {
    // Restore marketing to shared+sold (the seeded default).
    wpEval(
      `global $wpdb;$t=$wpdb->prefix.'faz_cookie_categories';` +
      `$wpdb->update($t,array('sell_personal_data'=>1,'share_personal_data'=>1),array('slug'=>'marketing'));` +
      `\\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance()->delete_cache();`,
    );
  });

  test('editor shows Sell/Share toggles for opt-out-able categories, none for necessary', async ({ page, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#faz-category-edit-rows tr[data-cat-id]', { timeout: 10_000 });
    await page.waitForTimeout(800);

    const necessary = page.locator('#faz-category-edit-rows tr[data-cat-id]', { has: page.locator('code', { hasText: /^necessary$/ }) });
    await expect(necessary.locator('.faz-cat-edit-sell')).toHaveCount(0);
    await expect(necessary.locator('.faz-cat-edit-share')).toHaveCount(0);

    const marketing = page.locator('#faz-category-edit-rows tr[data-cat-id]', { has: page.locator('code', { hasText: /^marketing$/ }) });
    await expect(marketing.locator('.faz-cat-edit-sell')).toHaveCount(1);
    await expect(marketing.locator('.faz-cat-edit-share')).toHaveCount(1);
  });

  test('unchecking Share and saving persists share_personal_data=0', async ({ page, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#faz-category-edit-rows tr[data-cat-id]', { timeout: 10_000 });
    await page.waitForTimeout(800);

    const marketing = page.locator('#faz-category-edit-rows tr[data-cat-id]', { has: page.locator('code', { hasText: /^marketing$/ }) });
    const shareCb = marketing.locator('.faz-cat-edit-share');
    await expect(shareCb).toBeChecked();
    await shareCb.uncheck();
    await page.click('#faz-save-categories');
    await page.waitForTimeout(1500);

    expect(marketingShareFlag(), 'marketing share_personal_data must persist as 0 after unchecking Share').toBe('0');
  });
});
