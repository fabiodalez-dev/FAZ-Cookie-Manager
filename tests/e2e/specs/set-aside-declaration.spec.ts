import { expect, test } from '../fixtures/wp-fixture';
import { fazApiPost, getAdminNonce } from '../utils/faz-api';
import { wpEval } from '../utils/wp-env';

/**
 * #243 — an administrator can declare what the scan could only set aside.
 *
 * A browser scan runs from one logged-in session and cannot tell whether a
 * cookie it saw there also reaches visitors. `_lscache_vary` is the issue's own
 * example: never received by an anonymous visitor on a plain blog, received by
 * every one of them on a shop with a cart. Same cookie, opposite correctness,
 * decided by configuration no crawl observes.
 *
 * The unit suite covers the decision logic. What it structurally cannot cover
 * is the wiring this spec exists for: the REST route being registered, the
 * button reaching it, and — the part that makes the feature real rather than
 * decorative — the declared cookie actually arriving in what a visitor is
 * served. `_lscache_vary` sits on Frontend::is_wp_internal_cookie()'s exact
 * list, so a declaration that wrote only the catalogue row would store
 * something, report success, and be shown to nobody.
 *
 * The before/after pair around the same visitor page is the point. Asserting
 * only the "after" would pass against a build that never suppressed the cookie
 * in the first place, which is precisely the regression that would break every
 * existing install's banner.
 */

const COOKIE_NAME = '_lscache_vary';
const SET_ASIDE_OPTION = 'faz_scan_set_aside_cookies';
const DECLARED_OPTION = 'faz_declared_internal_cookies';

/** Names visible in the banner payload the visitor's page actually received. */
async function declaredCookieNames(page: import('@playwright/test').Page): Promise<string[]> {
  return page.evaluate(() => {
    const categories = (window as any)._fazConfig?._categories ?? [];
    const names: string[] = [];
    for (const category of categories) {
      for (const cookie of (Array.isArray(category.cookies) ? category.cookies : [])) {
        names.push(String(cookie.cookieID || ''));
      }
    }
    return names;
  });
}

function resetFixture(): void {
  // Delete any catalogue row for the name so the run starts from "undeclared"
  // whatever a previous scan or spec left behind.
  wpEval(
    `$rows = $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT cookie_id FROM {$GLOBALS['wpdb']->prefix}faz_cookies WHERE name = %s", '${COOKIE_NAME}' ) );
     foreach ( $rows as $row_id ) { $GLOBALS['wpdb']->delete( "{$GLOBALS['wpdb']->prefix}faz_cookies", array( 'cookie_id' => (int) $row_id ), array( '%d' ) ); }
     delete_option( '${DECLARED_OPTION}' );
     update_option( '${SET_ASIDE_OPTION}', array( array( 'name' => '${COOKIE_NAME}', 'domain' => 'shop.example', 'duration' => '2 days', 'source' => 'admin-runtime' ) ), false );
     delete_transient( 'faz_server_cookie_category_map_v2' );
     delete_option( 'faz_banner_template' );`
  );
}

function cleanUpFixture(): void {
  wpEval(
    `$rows = $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT cookie_id FROM {$GLOBALS['wpdb']->prefix}faz_cookies WHERE name = %s", '${COOKIE_NAME}' ) );
     foreach ( $rows as $row_id ) { $GLOBALS['wpdb']->delete( "{$GLOBALS['wpdb']->prefix}faz_cookies", array( 'cookie_id' => (int) $row_id ), array( '%d' ) ); }
     delete_option( '${DECLARED_OPTION}' );
     delete_option( '${SET_ASIDE_OPTION}' );
     delete_transient( 'faz_server_cookie_category_map_v2' );
     delete_option( 'faz_banner_template' );`
  );
}

test.describe('#243 set-aside declaration', () => {
  test.beforeEach(() => {
    resetFixture();
  });

  test.afterEach(() => {
    cleanUpFixture();
  });

  test('a set-aside cookie is hidden until declared, then reaches the visitor', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    // ---- before: the default is unchanged, and that is load-bearing --------
    const beforeContext = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const visitor = await beforeContext.newPage();
      await visitor.goto(`${wpBaseURL}/`, { waitUntil: 'domcontentloaded' });
      await expect(visitor.locator('[data-faz-tag="notice"]')).toBeVisible();
      expect(await declaredCookieNames(visitor)).not.toContain(COOKIE_NAME);
    } finally {
      await beforeContext.close();
    }

    // ---- the administrator decides ----------------------------------------
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });

    const bar = page.locator('#faz-set-aside-bar');
    await expect(bar).toBeVisible();
    // The measured attributes are the reason the row is kept at all: without
    // them the only offer the product could make was "retype it by hand".
    await expect(bar).toContainText(COOKIE_NAME);
    await expect(bar).toContainText('shop.example');
    await expect(bar).toContainText('2 days');

    await bar.getByRole('button', { name: /declare this cookie/i }).first().click();
    await expect(bar.locator(`li:has-text("${COOKIE_NAME}")`)).toHaveCount(0);

    // ---- after: the declaration is visible where it has to be -------------
    const afterContext = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const visitor = await afterContext.newPage();
      await visitor.goto(`${wpBaseURL}/`, { waitUntil: 'domcontentloaded' });
      await expect(visitor.locator('[data-faz-tag="notice"]')).toBeVisible();
      // Reload inside the poll. `declaredCookieNames` reads the snapshot the
      // page was served, so without a fresh request every attempt re-reads the
      // same bytes: the retry window looks like patience and is really one
      // assertion repeated. The declaration invalidates server-side caches, so
      // what has to be observed is a NEW response, not the old one again.
      await expect
        .poll(async () => {
          await visitor.reload({ waitUntil: 'domcontentloaded' });
          return (await declaredCookieNames(visitor)).includes(COOKIE_NAME);
        }, { timeout: 15_000 })
        .toBe(true);
    } finally {
      await afterContext.close();
    }
  });

  test('an authentication cookie is refused even when the route is called directly', async ({ page, loginAsAdmin, wpBaseURL }) => {
    // The UI never offers these — remember_set_aside_cookies() drops them
    // before they reach the bucket — so the guard that matters is the one at
    // the write. Seeding the option directly is the only way to reach it, and
    // is exactly the shape a hand-crafted request would take.
    wpEval(
      `update_option( '${SET_ASIDE_OPTION}', array( array( 'name' => 'wordpress_logged_in_deadbeef', 'domain' => 'shop.example', 'duration' => 'session' ) ), false );`
    );

    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });
    const nonce = await getAdminNonce(page);
    const response = await fazApiPost(page, nonce, 'scans/set-aside/declare', {
      name: 'wordpress_logged_in_deadbeef',
    });

    expect(response.status).toBe(400);

    // Refusing is only half of it. The override option must stay unwritten, or
    // a later read would lift the display guard for an authentication cookie
    // that no logged-out visitor ever receives.
    const declared = wpEval(`echo wp_json_encode( get_option( '${DECLARED_OPTION}', array() ) );`).trim();
    expect(declared === '[]' || declared === 'false' || declared === '""').toBe(true);
  });
});
