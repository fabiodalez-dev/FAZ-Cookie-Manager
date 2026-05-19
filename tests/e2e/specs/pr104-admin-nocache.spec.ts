/**
 * PR #104 — admin pages must NEVER end up in a shared cache.
 *
 * Reported on prod (fabiodalez.it 2026-05-18, 1.14.1): after creating /
 * deleting banners, the admin saw stale state on subsequent loads.
 * Root cause: LiteSpeed Cache (and a handful of upstream proxies) can
 * cache /wp-admin/ responses when the cookie-keyed exemption doesn't
 * fire, e.g. when the request comes through a cPanel reverse proxy
 * that strips the wordpress_logged_in_ cookie from the cache key.
 *
 * Defence: Admin::render_page() now emits an aggressive nocache stack
 *   - WP core nocache_headers()
 *   - explicit Cache-Control: no-store, no-cache, must-revalidate, max-age=0
 *   - X-LiteSpeed-Cache-Control: no-cache
 *   - DONOTCACHEPAGE / DONOTCACHEOBJECT / DONOTCACHEDB constants
 *   - litespeed_control_set_nocache action
 *
 * This test pings every admin page registered by the plugin and asserts
 * the response carries the no-cache primitives. Catches both a future
 * accidental removal of the headers AND a route that bypasses
 * render_page() (e.g. a new admin page registered via a different
 * callback).
 */

import { test, expect } from '../fixtures/wp-fixture';

const ADMIN_PAGES = [
  'faz-cookie-manager',
  'faz-cookie-manager-banner',
  'faz-cookie-manager-cookies',
  'faz-cookie-manager-consent-logs',
  'faz-cookie-manager-gcm',
  'faz-cookie-manager-languages',
  'faz-cookie-manager-settings',
];

test.describe('PR104 — admin pages emit no-cache headers', () => {
  test('every plugin admin page sets Cache-Control: no-store + LiteSpeed bypass', async ({
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);

    for (const slug of ADMIN_PAGES) {
      const resp = await page.context().request.get(
        `${wpBaseURL}/wp-admin/admin.php?page=${slug}`,
      );
      // Status: either 200 (we have access) or 403 if a page is hidden by
      // capability — either way the headers should be set BEFORE the
      // capability check fires. We accept both.
      const cc = (resp.headers()['cache-control'] || '').toLowerCase();
      const ls = (resp.headers()['x-litespeed-cache-control'] || '').toLowerCase();
      expect(
        cc,
        `${slug}: Cache-Control should forbid shared/proxy caching (got "${cc}")`,
      ).toContain('no-store');
      expect(
        cc,
        `${slug}: Cache-Control should set max-age=0 (got "${cc}")`,
      ).toContain('max-age=0');
      // LSCache accepts comma-separated tokens (no-cache, no-vary, no-store, …).
      // We assert ONLY that the no-cache directive is present — the rest may
      // legitimately vary (e.g. when the rest_pre_dispatch hook appends
      // no-vary on REST namespaces or when Frontend::send_geo_cache_headers
      // contributes extra directives on country-dependent installs).
      expect(
        ls,
        `${slug}: X-LiteSpeed-Cache-Control must include "no-cache" (got "${ls}")`,
      ).toContain('no-cache');
    }
  });
});
