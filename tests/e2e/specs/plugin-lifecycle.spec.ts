import { expect, test } from '../fixtures/wp-fixture';
import { execFileSync } from 'node:child_process';
import { basename, join as joinPath } from 'node:path';
import {
  readFileSync,
  readdirSync,
  statSync,
  mkdtempSync,
  rmSync,
} from 'node:fs';
import {
  deactivatePluginsExcept,
  listActivePluginFiles,
  restoreActivePluginFiles,
  wp,
  wpEval,
  WP_PATH,
} from '../utils/wp-env';

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
// Dev-only paths that never belong in the deployed plugin. `.git` is the
// critical one: its fsmonitor--daemon.ipc UNIX socket makes `rsync -a`
// abort with `mkstempsock: Invalid argument`. Mirrors CLAUDE.md's deploy.
const RSYNC_EXCLUDES = [
  '--exclude=.git', '--exclude=node_modules', '--exclude=graphify-out',
  '--exclude=.code-review-graph', '--exclude=.serena', '--exclude=tests/e2e/reports',
];

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
  const base = basename(DEPLOY_PATH.replace(/\/+$/, ''));
  if (!DEPLOY_PATH || DEPLOY_PATH === '/' || !DEPLOY_PATH.includes('plugins') || base !== PLUGIN_SLUG) {
    throw new Error(`Refusing to delete: DEPLOY_PATH appears unsafe: "${DEPLOY_PATH}"`);
  }
}

/** Helper: ensure the plugin is present and activated, handling any prior state. */
async function ensurePluginActive(page: import('@playwright/test').Page, wpBaseURL: string): Promise<void> {
  // Re-deploy in case a previous run deleted the plugin files. Exclude the
  // dev-only directories that never belong in the deployed plugin — most
  // importantly `.git`, whose fsmonitor--daemon.ipc UNIX socket makes rsync
  // abort with `mkstempsock: Invalid argument`. Mirrors the canonical deploy
  // excludes in CLAUDE.md.
  try {
    execFileSync('rsync', ['-a', '--delete', ...RSYNC_EXCLUDES, SOURCE_PATH, DEPLOY_PATH], { timeout: 30000 });
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
    const categoriesBefore = await page.evaluate(async (base) => {
      const nonce = window.fazConfig?.api?.nonce ?? '';
      const res = await fetch(`${base}/?rest_route=/faz/v1/cookies/categories/`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!res.ok) return null;
      return res.json();
    }, wpBaseURL);
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
    const categoriesAfter = await page.evaluate(async (base) => {
      const nonce = window.fazConfig?.api?.nonce ?? '';
      const res = await fetch(`${base}/?rest_route=/faz/v1/cookies/categories/`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!res.ok) return null;
      return res.json();
    }, wpBaseURL);
    expect(Array.isArray(categoriesAfter)).toBeTruthy();
    // Activation may restore previously-deleted required categories (e.g.
    // wordpress-internal), so the count can be >= the pre-deactivation count.
    expect(categoriesAfter!.length).toBeGreaterThanOrEqual(categoryCountBefore);
    // All slugs that existed before must still be present after reactivation.
    const slugsBefore = new Set(categoriesBefore!.map((c: { slug?: string }) => c.slug).filter(Boolean));
    const slugsAfter  = new Set(categoriesAfter!.map((c: { slug?: string }) => c.slug).filter(Boolean));
    for (const slug of slugsBefore) {
      expect(slugsAfter.has(slug), `Category slug "${slug}" lost after reactivation`).toBe(true);
    }

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
    // This only removes files — it does NOT exercise uninstall.php (which requires
    // WP's filesystem-delete flow and wp-cli). DB tables/options from the previous
    // install will persist, which is intentional: this test verifies the activation
    // hook handles re-activation with pre-existing DB state (the most common
    // real-world upgrade scenario).
    assertSafeDeployPath();
    execFileSync('rm', ['-rf', DEPLOY_PATH], { timeout: 10000 });

    // --- Step 3: Verify plugin is gone ---
    await page.goto(`${wpBaseURL}${PLUGINS_PAGE}`, { waitUntil: 'domcontentloaded' });
    const pluginGone = await page.locator(`tr[data-plugin="${PLUGIN_FILE}"]`).count();
    expect(pluginGone).toBe(0);

    // --- Step 4: Re-deploy plugin files via rsync (simulates upload/install) ---
    execFileSync('rsync', ['-a', '--delete', ...RSYNC_EXCLUDES, SOURCE_PATH, DEPLOY_PATH], {
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
    const categories = await page.evaluate(async (base) => {
      const nonce = window.fazConfig?.api?.nonce ?? '';
      const res = await fetch(`${base}/?rest_route=/faz/v1/cookies/categories/`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!res.ok) return null;
      return res.json();
    }, wpBaseURL);
    expect(categories).not.toBeNull();
    expect(Array.isArray(categories)).toBeTruthy();

    // Verify all default category slugs from en.json are present
    const slugs = new Set(
      categories!.map((c: { slug?: string }) => c.slug).filter(Boolean),
    );
    for (const expected of [
      'necessary', 'functional', 'analytics', 'performance',
      'uncategorized', 'wordpress-internal', 'marketing',
    ]) {
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
    const settingsOk = await page.evaluate(async (base) => {
      const nonce = window.fazConfig?.api?.nonce ?? '';
      const res = await fetch(`${base}/?rest_route=/faz/v1/settings/`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      return res.ok;
    }, wpBaseURL);
    expect(settingsOk).toBeTruthy();
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// Deeper lifecycle paths — true fresh install on a clean DB, cross-version
// upgrade, and uninstall.php execution.
//
// The pre-existing "Plugin lifecycle" describe above only covers the
// reactivation-with-pre-existing-data path (the common upgrade case). These
// three tests fill the remaining gaps that historically bit users on slow
// bootstraps (1.13.13 / 1.13.14 Playground crashes — wp_salt() not yet
// available when maybe_create_table() fired) and on the wp.org "first
// install" path where no `wp_faz_*` table or `faz_*` option exists yet.
//
// Each test mutates global plugin state (tables, options, files) and then
// restores it in afterAll so the remaining ~540 tests in the suite see a
// healthy active plugin afterwards.
// ─────────────────────────────────────────────────────────────────────────────

test.describe.serial('Plugin lifecycle — deep paths', () => {
  test.setTimeout(180_000);

  // Snapshots captured in beforeAll, restored in afterAll. Without these,
  // a mid-run failure would leave the test site without the plugin
  // active or with FAZ_VERSION out of sync.
  let snapshotFazVersion = '';
  let snapshotRemoveDataOnUninstall = false;
  let snapshotCookieScriptsMapPresent = false;
  let originalActivePluginFilesBefore: string[] = [];

  test.beforeAll(() => {
    // Verify wp-cli is reachable before we touch anything.
    if (!WP_PATH) {
      throw new Error('WP_PATH env var is required for deep-lifecycle tests.');
    }
    originalActivePluginFilesBefore = listActivePluginFiles();
    deactivatePluginsExcept([PLUGIN_SLUG]);
    try {
      wp(['plugin', 'activate', PLUGIN_SLUG]);
    } catch (_e) { /* may already be active */ }
    snapshotFazVersion = wpEval(`echo (string) get_option( 'faz_version', '' );`).trim();
    snapshotRemoveDataOnUninstall = wpEval(`
      $s = get_option( 'faz_settings', array() );
      echo ! empty( $s['general']['remove_data_on_uninstall'] ) ? '1' : '0';
    `).trim() === '1';
    snapshotCookieScriptsMapPresent = wpEval(`echo false !== get_transient( 'faz_cookie_scripts_map' ) ? '1' : '0';`).trim() === '1';
  });

  test.afterAll(() => {
    // Re-install + re-activate so the rest of the suite sees a clean state.
    // execSync — not Playwright — because we may be in a state where the
    // plugin is fully uninstalled (no admin pages, no REST endpoints).
    try {
      execFileSync('rsync', ['-a', '--delete', ...RSYNC_EXCLUDES, SOURCE_PATH, DEPLOY_PATH], { timeout: 30000 });
    } catch (_e) { /* best-effort */ }
    try {
      wp(['plugin', 'activate', PLUGIN_SLUG]);
    } catch (_e) { /* may already be active */ }
    // Force-run the activator so DB tables are guaranteed present.
    wpEval(`
      if ( class_exists( '\\\\FazCookie\\\\Includes\\\\Activator' ) ) {
        \\FazCookie\\Includes\\Activator::install();
      }
    `);
    // Restore remove_data_on_uninstall to its pre-test value.
    wpEval(`
      $s = get_option( 'faz_settings', array() );
      if ( ! isset( $s['general'] ) || ! is_array( $s['general'] ) ) { $s['general'] = array(); }
      $s['general']['remove_data_on_uninstall'] = ${snapshotRemoveDataOnUninstall ? 'true' : 'false'};
      update_option( 'faz_settings', $s );
    `);
    restoreActivePluginFiles(originalActivePluginFilesBefore);
  });

  // ───────────────────────────────────────────────────────────────────────────
  // 1. True fresh install — drop every `wp_faz_*` table + delete every
  //    `faz_*` option, then re-run `Activator::install()` and verify the
  //    activator creates the schema from scratch.
  //
  //    This is the wp.org "fresh install on a brand-new WordPress site"
  //    path: no prior tables, no settings, no banner template cache.
  //    The pre-existing "fresh install" test deletes files only, so DB
  //    state survives — masking any "table not yet created" regression.
  //    Caught the 1.13.13/14 Playground crashes were exactly this shape.
  // ───────────────────────────────────────────────────────────────────────────
  test('true fresh install: dropping wp_faz_* tables + faz_* options and re-running Activator::install() creates the schema from scratch', () => {
    // Deactivate the plugin first. Two-process WP-CLI invocations work like
    // this: each `wpEval` spawns a new wp-cli process → WP bootstraps →
    // active plugins fire `plugins_loaded` → controllers that have
    // `maybe_create_table()` on `plugins_loaded` (the 1.13.14 fix) silently
    // recreate the tables we just dropped, before we get a chance to assert
    // they're gone. Deactivating the plugin removes its `plugins_loaded`
    // listeners, so the DROP TABLE actually sticks across wpEval calls.
    wp(['plugin', 'deactivate', PLUGIN_SLUG]);

    // Drop every plugin table + delete every plugin option in one wpEval call.
    // We use $wpdb->query() directly because dbDelta can't drop tables; the
    // operation is intentional and bounded to plugin-prefixed names.
    wpEval(`
      global $wpdb;
      $tables = array( 'banners', 'cookies', 'cookie_categories', 'consent_logs', 'pageviews' );
      foreach ( $tables as $t ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}faz_{$t}" );
      }
      // Clean every faz_* option so Activator runs on an empty slate.
      $faz_option_prefix = $wpdb->esc_like( 'faz_' ) . '%';
      $opts = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $faz_option_prefix ) );
      foreach ( $opts as $opt ) { delete_option( $opt ); }
      // Clear scripts-map / banner-template transients so the next install
      // can't pick up stale serialised data.
      delete_transient( 'faz_cookie_scripts_map' );
      delete_site_transient( 'faz_first_time_install' );
      delete_site_transient( '_faz_first_time_install' );
      delete_option( 'faz_banner_template' );
    `);

    // Confirm the slate is actually clean before we run the activator.
    const beforeInstall = JSON.parse(wpEval(`
      global $wpdb;
      $tables = array_map( function( $t ) use ( $wpdb ) { return $wpdb->prefix . 'faz_' . $t; }, array( 'banners', 'cookies', 'cookie_categories', 'consent_logs', 'pageviews' ) );
      $exist = array();
      foreach ( $tables as $t ) {
        $exist[ $t ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
      }
      $faz_option_prefix = $wpdb->esc_like( 'faz_' ) . '%';
      $opts_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $faz_option_prefix ) );
      echo wp_json_encode( array( 'tables' => $exist, 'opts_count' => $opts_count ) );
    `).trim()) as { tables: Record<string, boolean>; opts_count: number };
    for (const [tbl, present] of Object.entries(beforeInstall.tables)) {
      expect(present, `pre-install: ${tbl} must be DROPPED`).toBe(false);
    }
    expect(beforeInstall.opts_count, 'pre-install: no faz_* option must remain').toBe(0);

    // Re-activate via WP-CLI. `plugin activate` triggers the WordPress
    // activation lifecycle: register_activation_hook → Activator::install
    // (which in turn calls install_all_tables, seeds default categories,
    // bumps faz_version, and fires faz_after_activate). This is the
    // canonical "fresh install" code path — same one the wp.org install
    // / Playground / manual upload all hit.
    wp(['plugin', 'activate', PLUGIN_SLUG]);
    const fazVersionAfter = wpEval(`echo (string) get_option( 'faz_version', '' );`).trim();
    expect(fazVersionAfter, 'Activator::install() must bump faz_version').not.toBe('');

    // The constant must match — i.e. the install picked up the CURRENT
    // FAZ_VERSION (not a stale value snuck into the DB).
    const constVersion = wpEval(`echo defined( 'FAZ_VERSION' ) ? FAZ_VERSION : '';`).trim();
    expect(fazVersionAfter, 'faz_version option must match FAZ_VERSION const').toBe(constVersion);

    // Verify every plugin table now exists.
    const afterInstall = JSON.parse(wpEval(`
      global $wpdb;
      $tables = array_map( function( $t ) use ( $wpdb ) { return $wpdb->prefix . 'faz_' . $t; }, array( 'banners', 'cookies', 'cookie_categories', 'consent_logs', 'pageviews' ) );
      $exist = array();
      foreach ( $tables as $t ) {
        $exist[ $t ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
      }
      echo wp_json_encode( $exist );
    `).trim()) as Record<string, boolean>;
    for (const [tbl, present] of Object.entries(afterInstall)) {
      expect(present, `${tbl} must be CREATED by Activator::install()`).toBe(true);
    }

    // Verify the seven canonical default categories are seeded.
    const categories = JSON.parse(wpEval(`
      global $wpdb;
      $rows = $wpdb->get_col( "SELECT slug FROM {$wpdb->prefix}faz_cookie_categories" );
      echo wp_json_encode( $rows );
    `).trim()) as string[];
    for (const expected of ['necessary', 'functional', 'analytics', 'performance', 'uncategorized', 'wordpress-internal', 'marketing']) {
      expect(categories, `default category ${expected} must be seeded`).toContain(expected);
    }

    // Verify settings exist with a default banner_control.status.
    const settingsShape = wpEval(`
      $s = get_option( 'faz_settings', null );
      echo is_array( $s ) && isset( $s['banner_control'] ) ? 'ok' : 'fail';
    `).trim();
    expect(settingsShape, 'faz_settings must be seeded with a banner_control section').toBe('ok');
  });

  // ───────────────────────────────────────────────────────────────────────────
  // 2. Cross-version upgrade — set faz_version to an older version, then
  //    call `Activator::check_version()` (the hook that runs on every
  //    admin request and detects a version skew). The check must trigger
  //    `Activator::install()`, which bumps `faz_version` to the current
  //    `FAZ_VERSION` constant. Pre-existing data must survive intact.
  // ───────────────────────────────────────────────────────────────────────────
  test('cross-version upgrade: setting faz_version to 1.12.0 triggers check_version → install on the next admin request', () => {
    // Everything happens inside ONE wpEval. Two reasons:
    //
    // 1. `Activator::init()` registers `check_version` on the `init` hook
    //    with priority 5 (see class-activator.php). The hook fires on
    //    every WordPress bootstrap — including the one wp-cli does to
    //    run a second wpEval. If we set faz_version=1.12.0 in one call
    //    and read it back in a second, the second's `init` hook detects
    //    the version skew, runs install(), and bumps the option BEFORE
    //    our read can see the stale value — we'd never get to assert
    //    on `1.12.0`.
    //
    // 2. The test is fundamentally about state-flow inside one request,
    //    so collapsing everything inline mirrors the real-world boot
    //    order: set option → bootstrap hooks see skew → install() runs
    //    → option bumped.
    //
    // The PHP script:
    //   a. captures the FAZ_VERSION const + current category count + adds
    //      a marker category row (proves user data survives the migration)
    //   b. writes faz_version=1.12.0
    //   c. captures the "before" state to prove we set the stale value
    //   d. calls Activator::check_version() (same path the init hook uses)
    //   e. captures the "after" state
    //   f. cleans up the marker row
    //   g. calls check_version() once more to assert idempotence
    //   h. emits JSON for the TS assertions
    const markerCategorySlug = '_faz_e2e_upgrade_marker';
    const out = wpEval(`
      global $wpdb;
      $current_const = defined( 'FAZ_VERSION' ) ? FAZ_VERSION : '';
      $cats_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories" );
      $wpdb->insert(
        $wpdb->prefix . 'faz_cookie_categories',
        array( 'name' => 'E2E Upgrade Marker', 'slug' => '${markerCategorySlug}' ),
        array( '%s', '%s' )
      );

      update_option( 'faz_version', '1.12.0' );
      $stale = (string) get_option( 'faz_version' );

      \\FazCookie\\Includes\\Activator::check_version();
      $upgraded = (string) get_option( 'faz_version' );
      $cats_total_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories" );
      $marker_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories WHERE slug = %s",
        '${markerCategorySlug}'
      ) );

      // Clean up the marker before asserting so the next test runs on
      // the same default-seed shape it started with.
      $wpdb->delete(
        $wpdb->prefix . 'faz_cookie_categories',
        array( 'slug' => '${markerCategorySlug}' ),
        array( '%s' )
      );

      // Idempotence — second call must NOT change faz_version.
      \\FazCookie\\Includes\\Activator::check_version();
      $idempotent_after = (string) get_option( 'faz_version' );

      echo wp_json_encode( array(
        'current_const'       => $current_const,
        'cats_before'         => $cats_before,
        'stale'               => $stale,
        'upgraded'            => $upgraded,
        'cats_total_after'    => $cats_total_after,
        'marker_count'        => $marker_count,
        'idempotent_after'    => $idempotent_after,
      ) );
    `).trim();

    const r = JSON.parse(out) as {
      current_const: string;
      cats_before: number;
      stale: string;
      upgraded: string;
      cats_total_after: number;
      marker_count: number;
      idempotent_after: string;
    };

    expect(r.current_const, 'FAZ_VERSION const must be defined').not.toBe('');
    expect(r.cats_before, 'pre-condition: default categories must exist').toBeGreaterThan(0);
    expect(r.stale, 'pre-condition: faz_version must be the stale value before check_version()').toBe('1.12.0');
    expect(r.upgraded, 'cross-version upgrade must bump faz_version to FAZ_VERSION').toBe(r.current_const);
    expect(r.marker_count, 'user-created category must survive the upgrade migration').toBe(1);
    expect(r.cats_total_after, 'category count must stay >= pre-upgrade (defaults may be re-ensured)').toBeGreaterThanOrEqual(r.cats_before + 1);
    expect(r.idempotent_after, 'check_version() must be idempotent at FAZ_VERSION').toBe(r.current_const);
  });

  // ───────────────────────────────────────────────────────────────────────────
  // 3. uninstall.php execution — flip `remove_data_on_uninstall` to true,
  //    run `wp plugin uninstall faz-cookie-manager`, and verify every
  //    `wp_faz_*` table is dropped and every `faz_*` option is deleted.
  //    The afterAll above re-installs the plugin so the rest of the suite
  //    sees a clean active state.
  // ───────────────────────────────────────────────────────────────────────────
  test('uninstall.php with remove_data_on_uninstall=true drops every wp_faz_* table and deletes every faz_* option', () => {
    // Ensure the plugin is currently active. uninstall.php runs only when
    // WP-CLI's `plugin uninstall` is invoked on a deactivated plugin
    // (WP guards against uninstall of active plugins). The default state
    // at the start of this describe is "active and installed".
    const stateBefore = JSON.parse(wpEval(`
      global $wpdb;
      $tables = array_map( function( $t ) use ( $wpdb ) { return $wpdb->prefix . 'faz_' . $t; }, array( 'banners', 'cookies', 'cookie_categories', 'consent_logs', 'pageviews' ) );
      $exist = array();
      foreach ( $tables as $t ) {
        $exist[ $t ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
      }
      $faz_option_prefix = $wpdb->esc_like( 'faz_' ) . '%';
      $opts_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $faz_option_prefix ) );
      echo wp_json_encode( array( 'tables' => $exist, 'opts_count' => $opts_count ) );
    `).trim()) as { tables: Record<string, boolean>; opts_count: number };
    for (const [tbl, present] of Object.entries(stateBefore.tables)) {
      expect(present, `pre-uninstall: ${tbl} must exist`).toBe(true);
    }
    expect(stateBefore.opts_count, 'pre-uninstall: faz_* options must exist').toBeGreaterThan(0);

    // Flip the opt-in. Default is false (per `class-settings.php`) which is
    // why the wp.org submission spec requires explicit consent for cleanup.
    wpEval(`
      $s = get_option( 'faz_settings', array() );
      if ( ! isset( $s['general'] ) || ! is_array( $s['general'] ) ) { $s['general'] = array(); }
      $s['general']['remove_data_on_uninstall'] = true;
      update_option( 'faz_settings', $s );
    `);

    // Deactivate via WP-CLI, then uninstall. wp-cli's `plugin uninstall`
    // runs uninstall.php in the same PHP process so all option-delete /
    // table-drop calls land before we read the DB state.
    wp(['plugin', 'deactivate', PLUGIN_SLUG]);
    wp(['plugin', 'uninstall', PLUGIN_SLUG, '--skip-delete']);
    // `--skip-delete` keeps the files on disk so the afterAll re-rsync
    // doesn't have to re-clone the source — the goal of THIS test is the
    // DB-side uninstall.php side-effects, not file removal (which the
    // pre-existing "fresh install" test already covers).

    // Verify every plugin table is gone.
    const stateAfter = JSON.parse(wpEval(`
      global $wpdb;
      $tables = array_map( function( $t ) use ( $wpdb ) { return $wpdb->prefix . 'faz_' . $t; }, array( 'banners', 'cookies', 'cookie_categories', 'consent_logs', 'pageviews' ) );
      $exist = array();
      foreach ( $tables as $t ) {
        $exist[ $t ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
      }
      $faz_option_prefix = $wpdb->esc_like( 'faz_' ) . '%';
      $opts_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $faz_option_prefix ) );
      // Locks created by Do_Not_Sell_Shortcode / DSAR_Shortcode also live
      // as options, so they must be gone too.
      $lock_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'faz_dnsmpi_%' OR option_name LIKE 'faz_dsar_%'" );
      echo wp_json_encode( array( 'tables' => $exist, 'opts_count' => $opts_count, 'lock_count' => $lock_count ) );
    `).trim()) as { tables: Record<string, boolean>; opts_count: number; lock_count: number };

    for (const [tbl, present] of Object.entries(stateAfter.tables)) {
      expect(present, `post-uninstall: ${tbl} must be DROPPED`).toBe(false);
    }
    expect(stateAfter.opts_count, 'post-uninstall: every faz_* option must be deleted').toBe(0);
    expect(stateAfter.lock_count, 'post-uninstall: DNSMPI / DSAR rate-limit locks must be cleaned up').toBe(0);
  });

  // ───────────────────────────────────────────────────────────────────────────
  // 4. `Activator::run_pending_migrations()` — version-gated migrator + idempotence.
  //
  //    Runs only when `faz_migrations_version` differs from `MIGRATIONS_VERSION`
  //    (currently 2026.03.19.1). The seven migrations inside are individually
  //    idempotent (ensure_uncategorized_category / ensure_wordpress_internal_category
  //    no-op when the row exists; seed_default_whitelist only writes when the
  //    list is empty), but the test pins both the version-gate AND the
  //    "second call is a no-op" property — anyone refactoring the migrator
  //    has to keep both invariants alive.
  // ───────────────────────────────────────────────────────────────────────────
  test('run_pending_migrations: version gate fires once, second call is a no-op', () => {
    // Idempotent setup. The preceding "uninstall.php" test leaves the DB
    // entirely empty (tables dropped, options deleted, plugin deactivated),
    // and the describe's afterAll only re-installs at the very end of the
    // suite — so this test, running between, must restore its own
    // pre-conditions. Re-deploy files, re-activate via WP-CLI, and force
    // Activator::install() so the schema + default categories + faz_version
    // are guaranteed present before the migration assertions below.
    try {
      execFileSync('rsync', ['-a', '--delete', ...RSYNC_EXCLUDES, SOURCE_PATH, DEPLOY_PATH], { timeout: 30000 });
    } catch (_e) { /* best-effort */ }
    try { wp(['plugin', 'activate', PLUGIN_SLUG]); } catch (_e) { /* may already be active */ }
    wpEval(`
      if ( class_exists( '\\\\FazCookie\\\\Includes\\\\Activator' ) ) {
        \\FazCookie\\Includes\\Activator::install();
      }
    `);

    const out = wpEval(`
      global $wpdb;

      $migrations_version_const = (new \\ReflectionClass( '\\\\FazCookie\\\\Includes\\\\Activator' ))
        ->getConstant( 'MIGRATIONS_VERSION' );

      // Snapshot the current state so we can prove no duplicate side
      // effects on the second call.
      $cats_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories" );
      $whitelist_before = (array) ( get_option( 'faz_settings', array() )['script_blocking']['whitelist_patterns'] ?? array() );

      // Force the gate open by resetting the stored migrations version.
      update_option( 'faz_migrations_version', '0.0.0', false );

      // First call: should run every migration and bump the option.
      \\FazCookie\\Includes\\Activator::run_pending_migrations();
      $version_after_first = (string) get_option( 'faz_migrations_version' );
      $cats_after_first = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories" );

      // Second call: gate should short-circuit (return before running anything).
      // We prove the early-return by re-checking the version + category count
      // stays equal — a non-idempotent migration would either re-create rows
      // or re-fire fix_uncategorized_prior_consent / fix_brand_logo_path /
      // fix_banner_gdpr_defaults and leave a visible delta.
      \\FazCookie\\Includes\\Activator::run_pending_migrations();
      $version_after_second = (string) get_option( 'faz_migrations_version' );
      $cats_after_second = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories" );

      // Verify the seven default categories are still present (the
      // ensure_* helpers are exercised on every first call to
      // run_pending_migrations, and would silently no-op if the rows
      // were missing — but here the assertion is that the migration
      // PATH ran, so the rows are guaranteed to be there).
      $expected_slugs = array( 'necessary', 'functional', 'analytics', 'performance', 'uncategorized', 'wordpress-internal', 'marketing' );
      $present_slugs = $wpdb->get_col( $wpdb->prepare(
        "SELECT slug FROM {$wpdb->prefix}faz_cookie_categories WHERE slug IN ('" . implode( "','", $expected_slugs ) . "')",
        array()
      ) );

      echo wp_json_encode( array(
        'migrations_version_const' => $migrations_version_const,
        'version_after_first'      => $version_after_first,
        'version_after_second'     => $version_after_second,
        'cats_after_first'         => $cats_after_first,
        'cats_after_second'        => $cats_after_second,
        'default_slugs_present'    => array_values( $present_slugs ),
      ) );
    `).trim();

    const r = JSON.parse(out) as {
      migrations_version_const: string;
      version_after_first: string;
      version_after_second: string;
      cats_after_first: number;
      cats_after_second: number;
      default_slugs_present: string[];
    };

    expect(r.migrations_version_const, 'MIGRATIONS_VERSION const must be exposed').not.toBe('');
    expect(r.version_after_first, 'first call must bump faz_migrations_version to MIGRATIONS_VERSION').toBe(r.migrations_version_const);
    expect(r.version_after_second, 'second call must be a no-op — version stays equal').toBe(r.migrations_version_const);
    expect(r.cats_after_second, 'second call must NOT duplicate or alter category rows').toBe(r.cats_after_first);
    for (const expected of ['necessary', 'functional', 'analytics', 'performance', 'uncategorized', 'wordpress-internal', 'marketing']) {
      expect(r.default_slugs_present, `default slug ${expected} must survive run_pending_migrations()`).toContain(expected);
    }
  });

  // ───────────────────────────────────────────────────────────────────────────
  // 5. Playground / boot-order safety — static analysis.
  //
  //    The 1.13.13/14 crash that triggered the whole "test on Playground
  //    before SVN" lesson was a `wp_salt()` call inside a controller's
  //    `__construct()`. Playground's WASM bootstrap loads plugins before
  //    `pluggable.php`, so `wp_salt()` was undefined at that point — a
  //    fatal that nginx+PHP-FPM never reproduces (it loads pluggable
  //    earlier).
  //
  //    This static-analysis test grep's the PHP source for two patterns
  //    and fails if either re-appears:
  //
  //    a) `wp_salt(` calls inside files / blocks not guarded by
  //       `function_exists( 'wp_salt' )`.
  //    b) `maybe_create_table` / `dbDelta` calls inside an autoloaded
  //       class's `__construct` method (rather than deferred to a
  //       `plugins_loaded` / `init` hook callback).
  //
  //    The test is fast (filesystem walk + regex) and runs in every
  //    suite — every PR has to keep these invariants alive.
  //
  //    For the online Playground smoke test, see RUN_PLAYGROUND_TEST=1
  //    `playground-compat.spec.ts` (separate file — opt-in because the
  //    Playground WASM bootstrap takes ~30s and depends on external
  //    network).
  // ───────────────────────────────────────────────────────────────────────────
  test('Playground boot-order safety: no wp_salt() / maybe_create_table in unguarded __construct', () => {
    // Walk the plugin source tree and scan every PHP file. Use Node's fs
    // synchronously — this is a static check, no need for parallelism.
    // `require` is not available in ESM mode under tsx, so this uses the
    // module-scope `readdirSync`/`readFileSync` imports at the top of the file.
    const PLUGIN_ROOT = SOURCE_PATH.replace(/\/$/, '');

    const PHP_FILES: string[] = [];
    function walk(dir: string): void {
      for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = joinPath(dir, entry.name);
        // Skip vendor / tests / build-output dirs — they don't ship to
        // Playground.
        if (entry.isDirectory()) {
          if (['node_modules', 'vendor', '.git', 'tests', 'graphify-out', '.code-review-graph', '.symdex', '.serena'].includes(entry.name)) continue;
          walk(full);
        } else if (entry.isFile() && entry.name.endsWith('.php')) {
          PHP_FILES.push(full);
        }
      }
    }
    walk(PLUGIN_ROOT);

    const wpSaltOffenders: Array<{ file: string; line: number; snippet: string }> = [];
    const tableCreateInCtor: Array<{ file: string; line: number; snippet: string }> = [];

    // Helper: returns true if the line is purely a PHP comment (single-line,
    // multi-line continuation, or docblock). We skip these so a comment that
    // textually mentions `wp_salt()` for explanatory purposes doesn't flag.
    function isCommentLine(line: string): boolean {
      const t = line.trimStart();
      return t.startsWith('//') || t.startsWith('#') || t.startsWith('/*') || t.startsWith('*');
    }

    // Helper: returns true if the line (or its small window) carries a
    // `function_exists( 'wp_salt' )` guard. We accept either inline or
    // within ±5 lines of context — a wider window risks false negatives
    // for cleanly-guarded code.
    function isWpSaltGuarded(lines: string[], lineIdx: number): boolean {
      const start = Math.max(0, lineIdx - 5);
      const end = Math.min(lines.length, lineIdx + 1);
      const window = lines.slice(start, end).join('\n');
      return /function_exists\(\s*['"]wp_salt['"]\s*\)/.test(window);
    }

    // Helper: returns true if the line is *inside* an autoloaded class's
    // `__construct` method. We walk back from `lineIdx` looking for the
    // closest enclosing `function __construct` and stop at the first
    // function/closing brace at the same indentation. This is the
    // ONLY case we actually want to flag — runtime methods invoked by
    // hook callbacks (e.g. an AJAX handler that calls hash_ip()) are
    // safe because WP is fully bootstrapped by then.
    function isInsideConstruct(lines: string[], lineIdx: number): boolean {
      for (let i = lineIdx; i >= 0; i--) {
        const l = lines[i];
        if (/public\s+function\s+__construct\s*\(/.test(l)) return true;
        // Any other `function ` declaration means we left the constructor.
        if (/\bfunction\s+(?!__construct)\w+\s*\(/.test(l)) return false;
      }
      return false;
    }

    for (const file of PHP_FILES) {
      const src = readFileSync(file, 'utf8');
      const lines = src.split('\n');
      for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (isCommentLine(line)) continue;

        // (a) wp_salt() called from inside a __construct WITHOUT a
        // function_exists('wp_salt') guard. This is exactly the
        // Playground crash shape from 1.13.13/14 — anything else
        // (runtime call inside a hook callback, guarded call in a
        // late-bootstrap migration) is safe and not flagged here.
        if (
          /\bwp_salt\s*\(/.test(line)
          && !/function_exists\(\s*['"]wp_salt['"]\s*\)/.test(line)
          && isInsideConstruct(lines, i)
          && !isWpSaltGuarded(lines, i)
        ) {
          wpSaltOffenders.push({ file: file.replace(PLUGIN_ROOT + '/', ''), line: i + 1, snippet: line.trim() });
        }

        // (b) maybe_create_table / dbDelta inside a class constructor.
        if (/(maybe_create_table|dbDelta)\s*\(/.test(line) && isInsideConstruct(lines, i)) {
          tableCreateInCtor.push({ file: file.replace(PLUGIN_ROOT + '/', ''), line: i + 1, snippet: line.trim() });
        }
      }
    }

    expect(
      wpSaltOffenders,
      `wp_salt() called inside a class __construct without function_exists('wp_salt') guard — would crash on Playground (commit 9a0e3ae fix). Offenders:\n${JSON.stringify(wpSaltOffenders, null, 2)}`,
    ).toEqual([]);
    expect(
      tableCreateInCtor,
      `maybe_create_table() / dbDelta() called inside __construct — would crash on Playground (commit 9a0e3ae fix). Defer to plugins_loaded or init hook. Offenders:\n${JSON.stringify(tableCreateInCtor, null, 2)}`,
    ).toEqual([]);
  });

  // ───────────────────────────────────────────────────────────────────────────
  // 6. scripts/svn-release.sh — smoke test.
  //
  //    Not a full integration test (no real SVN commit), but enough to catch:
  //    - Bash syntax errors before they reach a live release.
  //    - Missing --dry-run, --no-tag, --version flags.
  //    - Pre-flight validation that bails on the right error messages
  //      (missing ZIP, wrong version, etc).
  //
  //    `--version=99.99.99` with a non-existent ZIP triggers the pre-flight
  //    "ZIP not found" branch — same code path that would catch a
  //    forgot-to-build mistake on a real release. The exit code must be
  //    non-zero and the stderr must mention the ZIP path.
  // ───────────────────────────────────────────────────────────────────────────
  test('svn-release.sh smoke test: syntax-clean, validates --version, fails on missing ZIP', () => {
    const PLUGIN_ROOT = SOURCE_PATH.replace(/\/$/, '');
    const svnRelease = joinPath(PLUGIN_ROOT, 'scripts/svn-release.sh');

    // 1. File exists and is executable.
    const stat = statSync(svnRelease);
    expect(stat.isFile(), 'scripts/svn-release.sh must exist as a file').toBe(true);
    // S_IXUSR mask — 0o100.
    expect((stat.mode & 0o100) !== 0, 'scripts/svn-release.sh must have the user-execute bit set').toBe(true);

    // 2. Bash syntax check (no-execute).
    try {
      execFileSync('bash', ['-n', svnRelease], { stdio: 'pipe' });
    } catch (err) {
      const e = err as { stderr?: Buffer };
      throw new Error(`bash -n syntax error in svn-release.sh:\n${e.stderr?.toString() ?? String(err)}`);
    }

    // 3. Invoked without --version, must reject and exit non-zero.
    // Use a tempdir as PROJECT_ROOT so the script can't accidentally touch
    // the real source tree even if its pre-flight let us through.
    const tmpdir = mkdtempSync('/tmp/faz-svn-test-');
    try {
      let exitCode = 0;
      let stderr = '';
      try {
        execFileSync('bash', [svnRelease], { stdio: 'pipe', env: { ...process.env, PROJECT_ROOT: tmpdir } });
      } catch (err) {
        const e = err as { status?: number; stderr?: Buffer };
        exitCode = e.status ?? 0;
        stderr = e.stderr?.toString() ?? '';
      }
      expect(exitCode, 'must reject invocation without --version').not.toBe(0);
      expect(stderr, 'rejection must mention --version').toMatch(/version/i);

      // 4. Invoked with a syntactically-valid --version but no matching ZIP →
      // must fail with the "wp.org-shape ZIP not found" pre-flight error.
      // This proves the early-return logic still gates the release path.
      let preflightExitCode = 0;
      let preflightStderr = '';
      try {
        execFileSync('bash', [svnRelease, '--version=99.99.99', '--dry-run'], {
          stdio: 'pipe',
          env: { ...process.env, PROJECT_ROOT: tmpdir },
        });
      } catch (err) {
        const e = err as { status?: number; stderr?: Buffer; stdout?: Buffer };
        preflightExitCode = e.status ?? 0;
        preflightStderr = (e.stderr?.toString() ?? '') + (e.stdout?.toString() ?? '');
      }
      expect(preflightExitCode, 'must fail when the ZIP does not exist').not.toBe(0);
      expect(preflightStderr, 'pre-flight failure must mention the missing ZIP path').toMatch(/zip|build-release/i);
    } finally {
      rmSync(tmpdir, { recursive: true, force: true });
    }
  });
});
