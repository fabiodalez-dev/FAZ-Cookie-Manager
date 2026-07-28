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
  // The Sale/Sharing column only renders when an active banner exposes a
  // Do-Not-Sell surface (Banner_Controller::has_do_not_sell_surface) — on a
  // pure-GDPR site the flags drive nothing visitor-facing and the editor
  // hides them. Enable the Do-Not-Sell entry point on the default banner for
  // the toggle tests, snapshotting the prior settings for restore.
  let bannerSnapshot = '';

  test.beforeAll(() => {
    bannerSnapshot = wpEval(
      `global $wpdb;` +
      `echo base64_encode((string)$wpdb->get_var("SELECT settings FROM {$wpdb->prefix}faz_banners WHERE banner_default=1 LIMIT 1"));`,
    ).trim().split('\n').pop() || '';
    wpEval(
      `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
      `$row=$wpdb->get_row("SELECT banner_id, settings FROM $t WHERE banner_default=1 LIMIT 1");` +
      `$s=json_decode($row->settings,true);` +
      `$s['config']['notice']['elements']['buttons']['elements']['donotSell']['status']=true;` +
      `$s['config']['notice']['elements']['buttons']['elements']['donotSell']['tag']='donotsell-button';` +
      `$wpdb->update($t,array('settings'=>wp_json_encode($s)),array('banner_id'=>(int)$row->banner_id));` +
      `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
      `faz_clear_banner_template_cache();` +
      `echo 'dns-enabled';`,
    );
  });

  test.afterAll(() => {
    // Restore marketing to shared+sold (the seeded default) and put the
    // default banner's settings back exactly as they were.
    wpEval(
      `global $wpdb;$t=$wpdb->prefix.'faz_cookie_categories';` +
      `$wpdb->update($t,array('sell_personal_data'=>1,'share_personal_data'=>1),array('slug'=>'marketing'));` +
      `\\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance()->delete_cache();`,
    );
    if (bannerSnapshot) {
      wpEval(
        `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
        `$wpdb->update($t,array('settings'=>base64_decode('${bannerSnapshot}')),array('banner_default'=>1));` +
        `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
        `faz_clear_banner_template_cache();` +
        `echo 'restored';`,
      );
    }
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

  test('column is hidden on a pure-GDPR site (no active Do-Not-Sell surface)', async ({ page, loginAsAdmin, wpBaseURL }) => {
    // Reset the marketing share flag the previous test set to 0, so the
    // preservation assertion below is meaningful.
    wpEval(
      `global $wpdb;$t=$wpdb->prefix.'faz_cookie_categories';` +
      `$wpdb->update($t,array('share_personal_data'=>1),array('slug'=>'marketing'));` +
      `\\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance()->delete_cache();` +
      `echo 'flag-reset';`,
    );
    // Temporarily disable the Do-Not-Sell entry point enabled in beforeAll:
    // with no active banner exposing the opt-out, the editor must render
    // WITHOUT the Sale/Sharing column (flags preserved server-side).
    wpEval(
      `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
      `$row=$wpdb->get_row("SELECT banner_id, settings FROM $t WHERE banner_default=1 LIMIT 1");` +
      `$s=json_decode($row->settings,true);` +
      `$s['config']['notice']['elements']['buttons']['elements']['donotSell']['status']=false;` +
      `$s['settings']['applicableLaw']='gdpr';` +
      `$wpdb->update($t,array('settings'=>wp_json_encode($s)),array('banner_id'=>(int)$row->banner_id));` +
      `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
      `echo 'dns-disabled';`,
    );
    try {
      await loginAsAdmin(page);
      await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#faz-category-edit-rows tr[data-cat-id]', { timeout: 10_000 });
      await page.waitForTimeout(800);

      await expect(page.locator('#faz-category-edit-table')).toHaveAttribute('data-show-ccpa', '0');
      await expect(page.locator('#faz-category-edit-rows .faz-cat-edit-sell')).toHaveCount(0);
      await expect(page.locator('#faz-category-edit-rows .faz-cat-edit-share')).toHaveCount(0);
      // Stored flags survive a save without the column (JS only PUTs flags
      // for rows that render the toggles).
      expect(marketingShareFlag(), 'share flag must be preserved while the column is hidden').toBe('1');
    } finally {
      // Re-enable for any later spec ordering (afterAll restores the snapshot anyway).
      wpEval(
        `global $wpdb;$t=$wpdb->prefix.'faz_banners';` +
        `$row=$wpdb->get_row("SELECT banner_id, settings FROM $t WHERE banner_default=1 LIMIT 1");` +
        `$s=json_decode($row->settings,true);` +
        `$s['config']['notice']['elements']['buttons']['elements']['donotSell']['status']=true;` +
        `$wpdb->update($t,array('settings'=>wp_json_encode($s)),array('banner_id'=>(int)$row->banner_id));` +
        `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
        `echo 're-enabled';`,
      );
    }
  });
});
