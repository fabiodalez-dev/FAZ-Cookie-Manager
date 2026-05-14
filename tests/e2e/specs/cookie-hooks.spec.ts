/**
 * Verifies that the granular cookie lifecycle hooks fire correctly:
 *   - faz_after_create_cookie  (Cookie_Controller::create_item)
 *   - faz_after_delete_cookie  (Cookie_Controller::delete_item + Cookies_API::bulk_delete)
 *
 * Observable side-effect: each hook calls delete_transient('faz_cookie_scripts_map').
 * The tests prime the transient, trigger the operation via REST, then assert
 * the transient was cleared.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const SCRIPTS_MAP_TRANSIENT = 'faz_cookie_scripts_map';

function primeScriptsMapTransient(): void {
  wpEval(`set_transient( '${SCRIPTS_MAP_TRANSIENT}', array( 'primed' => true ), HOUR_IN_SECONDS );`);
}

function isScriptsMapTransientPresent(): boolean {
  const result = wpEval(`echo get_transient( '${SCRIPTS_MAP_TRANSIENT}' ) !== false ? '1' : '0';`);
  return result.trim() === '1';
}

test.describe('Cookie lifecycle hooks (F006)', () => {
  test.beforeAll(() => {
    wpEval(`delete_transient( '${SCRIPTS_MAP_TRANSIENT}' );`);
  });

  test('faz_after_create_cookie fires and clears scripts-map transient', async ({
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });

    primeScriptsMapTransient();
    expect(isScriptsMapTransientPresent()).toBe(true);

    const categoryId: number = await page.evaluate(async () => {
      const nonce = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '';
      const resp = await fetch('/?rest_route=/faz/v1/categories/', {
        headers: { 'X-WP-Nonce': nonce },
      });
      const categories = (await resp.json().catch(() => [])) as Array<{ id: number }>;
      return categories.length > 0 ? categories[0].id : 0;
    });

    const createStatus: number = await page.evaluate(async (catId: number) => {
      const nonce = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '';
      const resp = await fetch('/?rest_route=/faz/v1/cookies/', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: '_faz_hook_test_create', category: catId }),
      });
      return resp.status;
    }, categoryId);

    expect(createStatus).toBe(200);
    expect(isScriptsMapTransientPresent()).toBe(false);

    wpEval(`
      global $wpdb;
      $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => '_faz_hook_test_create' ), array( '%s' ) );
    `);
  });

  test('faz_after_delete_cookie (single) fires and clears scripts-map transient', async ({
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });

    const cookieId: number = await page.evaluate(async () => {
      const nonce = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '';
      const cats = await fetch('/?rest_route=/faz/v1/categories/', { headers: { 'X-WP-Nonce': nonce } });
      const categories = (await cats.json().catch(() => [])) as Array<{ id: number }>;
      const catId = categories.length > 0 ? categories[0].id : 0;
      const resp = await fetch('/?rest_route=/faz/v1/cookies/', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: '_faz_hook_test_delete', category: catId }),
      });
      const created = (await resp.json().catch(() => null)) as { id?: number } | null;
      return created?.id ?? 0;
    });

    expect(cookieId).toBeGreaterThan(0);

    primeScriptsMapTransient();
    expect(isScriptsMapTransientPresent()).toBe(true);

    const deleteStatus: number = await page.evaluate(async (id: number) => {
      const nonce = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '';
      const resp = await fetch(`/wp-json/faz/v1/cookies/${id}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': nonce },
      });
      return resp.status;
    }, cookieId);

    expect(deleteStatus).toBe(200);
    expect(isScriptsMapTransientPresent()).toBe(false);
  });

  test('bulk_update persists opt_in_script / opt_out_script (F010 regression)', async ({
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });

    // Create a fresh cookie to bulk-update.
    const cookieId: number = await page.evaluate(async () => {
      const nonce = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '';
      const cats = await fetch('/?rest_route=/faz/v1/categories/', { headers: { 'X-WP-Nonce': nonce } });
      const categories = (await cats.json().catch(() => [])) as Array<{ id: number }>;
      const catId = categories.length > 0 ? categories[0].id : 0;
      const resp = await fetch('/?rest_route=/faz/v1/cookies/', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: '_faz_hook_test_bulkupdate_scripts', category: catId }),
      });
      const created = (await resp.json().catch(() => null)) as { id?: number } | null;
      return created?.id ?? 0;
    });

    expect(cookieId).toBeGreaterThan(0);

    const optInJs  = "/* faz-test */ console.log('faz-opt-in-bulk');";
    const optOutJs = "/* faz-test */ console.log('faz-opt-out-bulk');";

    // POST /faz/v1/cookies/bulk-update with the script fields set.
    const bulkStatus: number = await page.evaluate(async (payload: { id: number; in: string; out: string }) => {
      const nonce = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '';
      const resp = await fetch('/?rest_route=/faz/v1/cookies/bulk-update', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          cookies: [
            {
              id: payload.id,
              opt_in_script: payload.in,
              opt_out_script: payload.out,
            },
          ],
        }),
      });
      return resp.status;
    }, { id: cookieId, in: optInJs, out: optOutJs });

    expect(bulkStatus).toBe(200);

    // Round-trip: GET ?context=edit must now reflect the persisted scripts.
    const persisted = await page.evaluate(async (id: number) => {
      const nonce = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '';
      const resp = await fetch(`/?rest_route=/faz/v1/cookies/${id}&context=edit`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      return (await resp.json().catch(() => ({}))) as { opt_in_script?: string; opt_out_script?: string };
    }, cookieId);

    expect(persisted.opt_in_script).toBe(optInJs);
    expect(persisted.opt_out_script).toBe(optOutJs);

    // Cleanup
    wpEval(`
      global $wpdb;
      $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => '_faz_hook_test_bulkupdate_scripts' ), array( '%s' ) );
    `);
  });

  test('faz_after_delete_cookie (bulk) fires and clears scripts-map transient', async ({
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });

    const createdIds: number[] = await page.evaluate(async () => {
      const nonce = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '';
      const cats = await fetch('/?rest_route=/faz/v1/categories/', { headers: { 'X-WP-Nonce': nonce } });
      const categories = (await cats.json().catch(() => [])) as Array<{ id: number }>;
      const catId = categories.length > 0 ? categories[0].id : 0;

      const ids: number[] = [];
      for (const suffix of ['_a', '_b']) {
        const resp = await fetch('/?rest_route=/faz/v1/cookies/', {
          method: 'POST',
          headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
          body: JSON.stringify({ name: `_faz_hook_test_bulk${suffix}`, category: catId }),
        });
        const created = (await resp.json().catch(() => null)) as { id?: number } | null;
        if (created?.id) {
          ids.push(created.id);
        }
      }
      return ids;
    });

    expect(createdIds.length).toBe(2);

    primeScriptsMapTransient();
    expect(isScriptsMapTransientPresent()).toBe(true);

    const bulkStatus: number = await page.evaluate(async (ids: number[]) => {
      const nonce = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '';
      const resp = await fetch('/?rest_route=/faz/v1/cookies/bulk-delete', {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids }),
      });
      return resp.status;
    }, createdIds);

    expect(bulkStatus).toBe(200);
    expect(isScriptsMapTransientPresent()).toBe(false);
  });
});
