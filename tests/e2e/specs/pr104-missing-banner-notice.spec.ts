/**
 * PR #104 — missing-banner notice (companion to the create-id-pollution fix).
 *
 * After the auto-increment leak fix (commit 4e89a61) the banner-create flow
 * no longer redirects to phantom ids — but old bookmarks, stale links from
 * other CMSes, and the inevitable "I deleted that banner yesterday and now
 * the URL 404s" case all still hit the same surface: GET
 * /faz/v1/banners/{nonexistent_id} → 404 → JS catch.
 *
 * Pre-fix, the catch fired a transient toast ("Failed to load banner
 * settings") and left the editor half-rendered. Post-fix, the JS detects
 * the 404 specifically (err.code === fazcookie_rest_invalid_id) and shows
 * an in-page #faz-banner-missing notice with a CTA that links to the
 * default banner (or the first existing row if no default is set).
 *
 * This test drives the admin to ?banner_id=2513570 (the canonical
 * "this is what the auto-increment leak used to produce" id) and asserts
 * the recovery UI.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

test.describe('PR104 — phantom banner_id recovery UI', () => {
  test('visiting ?banner_id=2513570 shows the missing-banner notice + working default CTA', async ({
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);

    // Capture the actual default banner id so we can assert the CTA href.
    const defaultId = Number(
      wpEval(`
        global $wpdb;
        $row = $wpdb->get_row( "SELECT banner_id FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1" );
        if ( ! $row ) {
          $row = $wpdb->get_row( "SELECT banner_id FROM {$wpdb->prefix}faz_banners ORDER BY banner_id ASC LIMIT 1" );
        }
        echo $row ? (int) $row->banner_id : 0;
      `).trim(),
    );
    expect(defaultId, 'install has at least one banner to recover to').toBeGreaterThan(0);

    await page.goto(
      `${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-banner&banner_id=2513570`,
      { waitUntil: 'domcontentloaded' },
    );

    // The notice container should be visible.
    const notice = page.locator('#faz-banner-missing');
    await expect(notice, 'missing-banner notice is shown').toBeVisible({ timeout: 10000 });

    // The bad id from the URL is surfaced verbatim.
    await expect(
      page.locator('#faz-banner-missing-id'),
      'notice quotes the offending banner id',
    ).toHaveText('#2513570');

    // The editor body + tabs are hidden so the admin doesn't see a
    // half-rendered form.
    await expect(page.locator('#faz-banner-body'), 'editor body hidden').toBeHidden();
    await expect(page.locator('#faz-banner-tabs'), 'tabs hidden').toBeHidden();
    await expect(page.locator('#faz-b-switcher'), 'banner switcher hidden').toBeHidden();

    // Give the JS time to resolve the fallback href via GET /banners.
    await page.waitForFunction(
      (id) => {
        const a = document.getElementById('faz-banner-missing-default') as HTMLAnchorElement | null;
        return !!a && a.href.includes('banner_id=' + id);
      },
      defaultId,
      { timeout: 5000 },
    );
    const cta = page.locator('#faz-banner-missing-default');
    const href = await cta.getAttribute('href');
    expect(href, `CTA points at banner_id=${defaultId}`).toContain(`banner_id=${defaultId}`);
    expect(href, 'CTA preserves the admin page slug').toContain('page=faz-cookie-manager-banner');

    // Clicking the CTA navigates to the default banner editor — the notice
    // is no longer present (the editor mounts normally for an existing id).
    await Promise.all([page.waitForLoadState('domcontentloaded'), cta.click()]);
    await expect(
      page.locator('#faz-banner-missing'),
      'notice disappears after navigating to a valid banner',
    ).toBeHidden({ timeout: 10000 });
    await expect(
      page.locator('#faz-banner-body'),
      'editor body visible on the default banner',
    ).toBeVisible();
  });
});
