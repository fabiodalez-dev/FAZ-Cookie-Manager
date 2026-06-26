import { test, expect } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { wp, wpEval } from '../utils/wp-env';

/**
 * Manual service registration (#161).
 *
 * The automated scanner can miss embedded services (caching, lazy-load), which
 * leaves their cookies undeclared domain-wide. This feature lets an admin pick a
 * known provider from the built-in catalogue and register its cookies into
 * wp_faz_cookies (discovered=1) so they are declared on every page without a
 * scan, and feed the Cookie Policy generator.
 *
 * Drives the real REST endpoints (faz/v1/cookies/catalogue-services and
 * /register-service) with an admin nonce, plus a UI presence check of the
 * "Add Service" control on the Cookies admin page. Serial; cleans up the test
 * provider's cookies before and after so it is reusable in isolation or in-suite.
 */

const YT_COOKIES = ['YSC', 'VISITOR_INFO1_LIVE', 'LOGIN_INFO'];

async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => window.fazConfig?.api?.nonce ?? '');
}

function storedYouTubeCookies(): Array<{ name: string; domain: string; discovered: string }> {
  // Query the persisted rows directly so the test verifies the saved-record
  // contract (discovered=1, domain-scoped), not just the rendered banner HTML.
  const inList = YT_COOKIES.map((n) => `'${n}'`).join(',');
  try {
    const json = wpEval(
      `global $wpdb; echo json_encode($wpdb->get_results("SELECT name, domain, discovered FROM {$wpdb->prefix}faz_cookies WHERE name IN (${inList})", ARRAY_A));`,
    );
    const parsed = JSON.parse(json);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function deleteYouTubeCookies(): void {
  // Best-effort cleanup via WP-CLI so the "added > 0" assertions are deterministic.
  const inList = YT_COOKIES.map((n) => `'${n}'`).join(',');
  try {
    wp(['eval', `global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}faz_cookies WHERE name IN (${inList})"); delete_transient('faz_cookie_scripts_map'); delete_option('faz_banner_template');`]);
  } catch {
    /* best-effort */
  }
}

test.describe('Manual service registration (#161)', () => {
  test.describe.configure({ mode: 'serial' });

  let admin: Page;
  let nonce = '';

  test.beforeAll(async ({ browser, loginAsAdmin }) => {
    deleteYouTubeCookies();
    admin = await browser.newPage();
    await loginAsAdmin(admin);
    await admin.goto('/wp-admin/admin.php?page=faz-cookie-manager-cookies', { waitUntil: 'domcontentloaded' });
    nonce = await getAdminNonce(admin);
    expect(nonce.length).toBeGreaterThan(0);
  });

  test.afterAll(async () => {
    deleteYouTubeCookies();
    if (admin) await admin.close();
  });

  test('1. catalogue-services returns the built-in providers (incl. YouTube)', async () => {
    const res = await admin.request.get('/?rest_route=/faz/v1/cookies/catalogue-services', {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(res.status()).toBe(200);
    const body = (await res.json()) as { services: Array<{ id: string; label: string; category: string; cookie_count: number; registered: boolean }> };
    expect(Array.isArray(body.services)).toBe(true);
    expect(body.services.length).toBeGreaterThan(10);
    const yt = body.services.find((s) => s.id === 'youtube');
    expect(yt, 'YouTube should be in the catalogue').toBeTruthy();
    expect(yt!.category).toBe('marketing');
    expect(yt!.cookie_count).toBeGreaterThan(0);
    expect(yt!.registered, 'YouTube should be unregistered after cleanup').toBe(false);
  });

  test('2. register-service adds the provider cookies (discovered, domain-wide)', async () => {
    const res = await admin.request.post('/?rest_route=/faz/v1/cookies/register-service', {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: { service_id: 'youtube' },
    });
    expect(res.status()).toBe(200);
    const body = (await res.json()) as { service: { id: string }; requested: number; added: number; category: string };
    expect(body.service.id).toBe('youtube');
    expect(body.category).toBe('marketing');
    expect(body.added).toBeGreaterThan(0);

    // Saved-record contract: all three concrete YouTube cookies must be
    // persisted as discovered, domain-scoped rows (#161 goal) — not merely
    // surfaced by name in the banner HTML.
    const stored = storedYouTubeCookies();
    expect(stored.map((c) => c.name).sort()).toEqual([...YT_COOKIES].sort());
    stored.forEach((c) => {
      expect(Number(c.discovered)).toBe(1);
      expect(c.domain).toBe('youtube.com');
    });

    // The registered cookies are now declared in the banner store on a plain
    // page that carries no YouTube embed (domain-wide transparency).
    const html = await admin.request.get('/', { headers: { 'User-Agent': 'Mozilla/5.0 (manual-service-e2e)' } }).then((r) => r.text());
    expect(html).toContain('YSC');
    expect(html).toContain('VISITOR_INFO1_LIVE');
    expect(html).toContain('LOGIN_INFO');
  });

  test('3. registering again is idempotent and the catalogue flags it registered', async () => {
    const again = await admin.request.post('/?rest_route=/faz/v1/cookies/register-service', {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: { service_id: 'youtube' },
    });
    expect(again.status()).toBe(200);
    expect(((await again.json()) as { added: number }).added).toBe(0);

    const cat = await admin.request.get('/?rest_route=/faz/v1/cookies/catalogue-services', { headers: { 'X-WP-Nonce': nonce } });
    const yt = ((await cat.json()) as { services: Array<{ id: string; registered: boolean }> }).services.find((s) => s.id === 'youtube');
    expect(yt!.registered).toBe(true);
  });

  test('4. an unknown service id is rejected', async () => {
    const res = await admin.request.post('/?rest_route=/faz/v1/cookies/register-service', {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: { service_id: 'totally_unknown_provider_xyz' },
    });
    expect(res.status()).toBe(404);
  });

  test('5. the Cookies admin page exposes the Add Service control and populates it', async () => {
    await admin.goto('/wp-admin/admin.php?page=faz-cookie-manager-cookies', { waitUntil: 'domcontentloaded' });
    const addBtn = admin.locator('#faz-add-service-btn');
    await expect(addBtn).toBeVisible();
    await addBtn.click();
    // The catalogue loads lazily on first open; the select gains real options.
    await expect.poll(async () => admin.locator('#faz-service-select option').count(), { timeout: 8000 }).toBeGreaterThan(1);
  });
});
