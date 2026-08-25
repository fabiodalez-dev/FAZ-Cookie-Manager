/**
 * Isolated multisite verification.
 *
 * Run through scripts/run-multisite-scanner-e2e.sh. The runner creates a fresh
 * subdirectory network and a child site in a disposable database, network-
 * activates FAZ plus the third-party scan fixture, and removes everything in a
 * trap. This spec then proves that a delayed PHP AJAX Set-Cookie from the child
 * site is captured and catalogued in that child only.
 */

import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { expect, test } from '../fixtures/wp-fixture';
import { fazApiPost, findCategoryId, listCookiesUntil, openCookiesPage } from '../utils/faz-api';

const WP_BASE = process.env.WP_BASE_URL ?? '';
const WP_MAIN = process.env.FAZ_MULTISITE_MAIN_URL ?? '';
const WP_PATH = process.env.WP_PATH ?? '';

// This file is discovered by the normal Playwright config as well, but its
// topology exists only inside scripts/run-multisite-scanner-e2e.sh. Keep the
// ordinary release gate green and explicit: the dedicated runner supplies both
// the /child base URL and the main-site URL, while a normal single-site run
// records one intentional skip instead of failing before the first assertion.
test.skip(
  !WP_MAIN || !WP_BASE.includes('/child'),
  'Run through npm run test:e2e:multisite (isolated disposable network).',
);

function wpSiteEval(siteURL: string, code: string): string {
  if (!WP_PATH || !siteURL) {
    throw new Error('WP_PATH and both multisite URLs must be supplied by the isolated runner.');
  }
  return execFileSync('wp', [`--path=${WP_PATH}`, `--url=${siteURL}`, 'eval', code], {
    encoding: 'utf8',
    env: {
      ...process.env,
      WP_CLI_PHP_ARGS: '-d error_reporting=E_ERROR -d display_errors=0',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 60_000,
  }).trim();
}

function scanId(): string {
  return randomBytes(16).toString('hex');
}

test('multisite child captures a one-year HttpOnly cookie from another plugin without leaking it to the main site', async ({ page, loginAsAdmin }) => {
  expect(WP_BASE).toContain('/child');
  expect(WP_MAIN).toBeTruthy();

  const topology = JSON.parse(wpSiteEval(WP_BASE, `
    global $wpdb;
    echo wp_json_encode( array(
      'multisite' => is_multisite(),
      'blog_id'   => get_current_blog_id(),
      'prefix'    => $wpdb->prefix,
      'network'   => is_plugin_active_for_network( 'faz-cookie-manager/faz-cookie-manager.php' ),
    ) );
  `)) as { multisite: boolean; blog_id: number; prefix: string; network: boolean };
  expect(topology.multisite).toBe(true);
  expect(topology.blog_id).toBeGreaterThan(1);
  expect(topology.prefix).toMatch(/_\d+_$/);
  expect(topology.network).toBe(true);

  wpSiteEval(WP_BASE, `
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => 'brikpanel_vid' ), array( '%s' ) );
    delete_option( 'faz_httponly_scan_urls' );
    delete_option( 'faz_httponly_scan_lock' );
    wp_clear_scheduled_hook( 'faz_async_httponly_cookie_check' );
  `);

  const nonce = await openCookiesPage(page, loginAsAdmin);
  const id = scanId();
  const discover = await fazApiPost<{ urls: string[] }>(page, nonce, 'scans/discover', {
    max_pages: 1,
    scan_id: id,
  });
  expect(discover.status).toBe(200);

  const ajax = page.waitForResponse((response) => response.url().includes('action=faz_e2e_scan_ajax_cookie'));
  const scannedURL = `${WP_BASE}/faz-lab-ajax-httponly/?faz_scanning=1&faz_scan_id=${id}`;
  await page.goto(scannedURL, { waitUntil: 'domcontentloaded' });
  const ajaxResponse = await ajax;
  expect(ajaxResponse.status()).toBe(200);
  // Set-Cookie is a forbidden browser response header, so Chromium does not
  // expose it through Response.headers(). The browser jar is the authoritative
  // observable: it includes HttpOnly and the calculated Max-Age deadline.
  const browserCookie = (await page.context().cookies(WP_BASE)).find((cookie) => cookie.name === 'brikpanel_vid');
  expect(browserCookie).toBeTruthy();
  expect(browserCookie?.httpOnly).toBe(true);
  expect(browserCookie?.expires ?? 0).toBeGreaterThan(Math.floor(Date.now() / 1000) + 360 * 86_400);

  const imported = await fazApiPost<{ cookie_names: string[] }>(page, nonce, 'scans/import', {
    scan_id: id,
    cookies: [],
    pages_scanned: 1,
    scanned_urls: [scannedURL],
    scripts: [],
    metrics: { pagesScanned: 1, maxPages: 1, isFullScan: false },
  });
  expect(imported.status).toBe(200);
  expect(imported.data.cookie_names).toContain('brikpanel_vid');

  const rows = await listCookiesUntil(page, nonce, ['brikpanel_vid'], 60_000);
  const row = rows.find((cookie: any) => cookie.name === 'brikpanel_vid');
  const analyticsID = await findCategoryId(page, nonce, 'analytics');
  expect(Number(row?.category)).toBe(analyticsID);
  expect(row?.duration?.en ?? Object.values(row?.duration ?? {})[0]).toMatch(/1 year/i);

  const childCount = Number(wpSiteEval(WP_BASE, `
    global $wpdb;
    echo (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookies WHERE name = %s",
      'brikpanel_vid'
    ) );
  `));
  const mainCount = Number(wpSiteEval(WP_MAIN, `
    global $wpdb;
    echo (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookies WHERE name = %s",
      'brikpanel_vid'
    ) );
  `));
  expect(childCount).toBe(1);
  expect(mainCount).toBe(0);
});
