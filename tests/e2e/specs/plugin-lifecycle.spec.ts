import { expect, test } from '../fixtures/wp-fixture';
import { execFileSync } from 'node:child_process';

/**
 * Plugin lifecycle tests — upgrade (deactivate → activate) and fresh install
 * (deactivate → delete → re-upload → activate).
 *
 * These tests verify that:
 * - Migrations run correctly on re-activation (upgrade path)
 * - A clean install from scratch creates all DB tables and default data
 * - The frontend banner works after both paths
 */

const PLUGIN_SLUG = 'faz-cookie-manager';
const PLUGIN_FILE = `${PLUGIN_SLUG}/faz-cookie-manager.php`;
const PLUGINS_PAGE = '/wp-admin/plugins.php';

// Source and deploy paths — configurable via env vars for CI portability.
const SOURCE_PATH = process.env.FAZ_PLUGIN_SOURCE_PATH ?? `${process.cwd()}/`;
const DEPLOY_PATH = process.env.FAZ_PLUGIN_DEPLOY_PATH ?? '';

if (!DEPLOY_PATH) {
  throw new Error(
    'FAZ_PLUGIN_DEPLOY_PATH environment variable is required for lifecycle tests.\n' +
    'Example: FAZ_PLUGIN_DEPLOY_PATH=/path/to/wp-content/plugins/faz-cookie-manager/',
  );
}

/** Helper: check if the plugin row has WordPress "active" class (not "inactive"). */
function isPluginActive(rowClass: string | null): boolean {
  if (!rowClass) return false;
  // WP uses "active" for active, "inactive" for deactivated.
  // Split on whitespace and check for exact match.
  return rowClass.split(/\s+/).includes('active');
}

/** Validate DEPLOY_PATH before destructive filesystem ops. */
function assertSafeDeployPath(): void {
  if (!DEPLOY_PATH || DEPLOY_PATH === '/' || !DEPLOY_PATH.includes('plugins')) {
    throw new Error(`Refusing to delete: DEPLOY_PATH appears unsafe: "${DEPLOY_PATH}"`);
  }
}

/** Helper: ensure the plugin is present and activated, handling any prior state. */
async function ensurePluginActive(page: import('@playwright/test').Page, wpBaseURL: string): Promise<void> {
  // Re-deploy in case a previous run deleted the plugin files
  try {
    execFileSync('rsync', ['-a', '--delete', SOURCE_PATH, DEPLOY_PATH], { timeout: 30000 });
  } catch (error) {
    throw new Error(
      `Failed to deploy plugin files via rsync.\n` +
      `SOURCE_PATH: ${SOURCE_PATH}\nDEPLOY_PATH: ${DEPLOY_PATH}\n` +
      `Original error: ${error}`,
    );
  }

  await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
  const pluginRow = page.locator(`tr[data-plugin="${PLUGIN_FILE}"]`);

  if (await pluginRow.count() === 0) {
    // Plugin files missing — reload after rsync
    await page.reload({ waitUntil: 'domcontentloaded' });
  }

  await expect(pluginRow).toBeVisible();
  const rowClass = await pluginRow.getAttribute('class');
  if (!isPluginActive(rowClass)) {
    await pluginRow.locator('span.activate a').click();
    await page.waitForLoadState('domcontentloaded');
  }
}

test.describe.serial('Plugin lifecycle', () => {

  // These tests are slow because they deactivate/delete/reinstall the plugin.
  test.setTimeout(120_000);

  test('upgrade path: deactivate → reactivate preserves data and runs migrations', async ({
    page, wpBaseURL, loginAsAdmin,
  }) => {
    await loginAsAdmin(page);

    // --- Ensure plugin is active before starting ---
    await ensurePluginActive(page, wpBaseURL);

    // --- Verify we have data: at least one cookie category exists ---
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const categoriesBefore = await page.evaluate(async () => {
      const nonce = window.fazConfig?.api?.nonce ?? '';
      const res = await fetch('/?rest_route=/faz/v1/cookies/categories/', {
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!res.ok) return null;
      return res.json();
    });
    expect(Array.isArray(categoriesBefore)).toBeTruthy();
    expect(categoriesBefore!.length).toBeGreaterThan(0);
    const categoryCountBefore = categoriesBefore!.length;

    // --- Deactivate ---
    await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
    const deactivateLink = page.locator(`tr[data-plugin="${PLUGIN_FILE}"] a[href*="action=deactivate"]`);
    await deactivateLink.click();
    await page.waitForLoadState('domcontentloaded');

    // Verify deactivated — WP class should be "inactive", NOT "active"
    await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
    const rowClassAfterDeactivate = await page.locator(`tr[data-plugin="${PLUGIN_FILE}"]`).getAttribute('class');
    expect(isPluginActive(rowClassAfterDeactivate)).toBe(false);

    // --- Reactivate ---
    const activateLink = page.locator(`tr[data-plugin="${PLUGIN_FILE}"] span.activate a`);
    await activateLink.click();
    await page.waitForLoadState('domcontentloaded');

    // Verify activated
    await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
    const rowClassAfterActivate = await page.locator(`tr[data-plugin="${PLUGIN_FILE}"]`).getAttribute('class');
    expect(isPluginActive(rowClassAfterActivate)).toBe(true);

    // --- Verify data is still there (categories preserved) ---
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const categoriesAfter = await page.evaluate(async () => {
      const nonce = window.fazConfig?.api?.nonce ?? '';
      const res = await fetch('/?rest_route=/faz/v1/cookies/categories/', {
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!res.ok) return null;
      return res.json();
    });
    expect(Array.isArray(categoriesAfter)).toBeTruthy();
    expect(categoriesAfter!.length).toBe(categoryCountBefore);

    // --- Verify admin pages load ---
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#wpadminbar')).toBeVisible();

    // --- Verify frontend banner works ---
    // Use the same page — PHP built-in server is single-threaded and can't
    // serve two concurrent requests (admin page + frontend page).
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#fazBannerTemplate')).toBeAttached();
  });

  test('fresh install: deactivate → delete → reinstall works from scratch', async ({
    page, wpBaseURL, loginAsAdmin,
  }) => {
    await loginAsAdmin(page);

    // --- Ensure plugin is present and active first ---
    await ensurePluginActive(page, wpBaseURL);

    // --- Step 1: Deactivate via WP admin ---
    await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
    const deactivateLink = page.locator(`tr[data-plugin="${PLUGIN_FILE}"] a[href*="action=deactivate"]`);
    await expect(deactivateLink).toBeVisible();
    await deactivateLink.click();
    await page.waitForLoadState('domcontentloaded');

    // Verify deactivated
    await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
    const rowAfterDeactivate = await page.locator(`tr[data-plugin="${PLUGIN_FILE}"]`).getAttribute('class');
    expect(isPluginActive(rowAfterDeactivate)).toBe(false);

    // --- Step 2: Delete plugin files from disk ---
    // Note: this does NOT run uninstall.php (that requires WP's own delete flow).
    // DB tables/options from the previous install may persist, which is fine —
    // the activation hook must handle both fresh and pre-existing DB states.
    assertSafeDeployPath();
    execFileSync('rm', ['-rf', DEPLOY_PATH], { timeout: 10000 });

    // --- Step 3: Verify plugin is gone ---
    await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
    const pluginGone = await page.locator(`tr[data-plugin="${PLUGIN_FILE}"]`).count();
    expect(pluginGone).toBe(0);

    // --- Step 4: Re-deploy plugin files via rsync (simulates upload/install) ---
    execFileSync('rsync', ['-a', '--delete', SOURCE_PATH, DEPLOY_PATH], {
      timeout: 30000,
    });

    // --- Step 5: Verify plugin appears in list (inactive) ---
    await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
    const reinstalledRow = page.locator(`tr[data-plugin="${PLUGIN_FILE}"]`);
    await expect(reinstalledRow).toBeVisible();
    const rowClass = await reinstalledRow.getAttribute('class');
    expect(isPluginActive(rowClass)).toBe(false);

    // --- Step 6: Activate ---
    await reinstalledRow.locator('span.activate a').click();
    await page.waitForLoadState('domcontentloaded');

    // Verify activated
    await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
    const activatedClass = await page.locator(`tr[data-plugin="${PLUGIN_FILE}"]`).getAttribute('class');
    expect(isPluginActive(activatedClass)).toBe(true);

    // --- Step 7: Verify DB tables created and default categories present ---
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const categories = await page.evaluate(async () => {
      const nonce = window.fazConfig?.api?.nonce ?? '';
      const res = await fetch('/?rest_route=/faz/v1/cookies/categories/', {
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!res.ok) return null;
      return res.json();
    });
    expect(categories).not.toBeNull();
    expect(Array.isArray(categories)).toBeTruthy();

    // Verify all 4 default category slugs are present
    const slugs = new Set(
      categories!.map((c: { slug?: string }) => c.slug).filter(Boolean),
    );
    for (const expected of ['necessary', 'functional', 'analytics', 'marketing']) {
      expect(slugs.has(expected), `Missing default category: ${expected}`).toBe(true);
    }

    // --- Step 8: Verify admin dashboard loads ---
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#wpadminbar')).toBeVisible();

    // --- Step 9: Verify frontend banner works from scratch ---
    await page.context().clearCookies();
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#fazBannerTemplate')).toBeAttached();

    // --- Step 10: Verify settings API works ---
    // Re-login since we cleared cookies
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const settingsOk = await page.evaluate(async () => {
      const nonce = window.fazConfig?.api?.nonce ?? '';
      const res = await fetch('/?rest_route=/faz/v1/settings/', {
        headers: { 'X-WP-Nonce': nonce },
      });
      return res.ok;
    });
    expect(settingsOk).toBeTruthy();
  });
});
