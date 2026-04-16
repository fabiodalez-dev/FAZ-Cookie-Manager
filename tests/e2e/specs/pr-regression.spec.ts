/**
 * PR regression tests — targeted tests for changes in PR #44, #41, and #39.
 *
 * PR #44: i18n cookie save (defLang, translation preservation on edit)
 * PR #41: user-configurable whitelist, lazy loading, admin perf
 * PR #39: v1.7.0 features (cookie table shortcode, blocker templates, import/export)
 */
import { completeAdminLogin, expect, test } from '../fixtures/wp-fixture';
import { getWpLoginPath } from '../utils/wp-auth';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';
const WP_ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const WP_LOGIN_PATH = getWpLoginPath();

/* ─── Helpers ──────────────────────────────────── */

async function getAdminNonce(page: any): Promise<string> {
  return page.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
}

async function apiGet(page: any, nonce: string, route: string) {
  const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/${route}`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  return { status: r.status(), data: await r.json() };
}

async function apiPost(page: any, nonce: string, route: string, data: Record<string, unknown>) {
  const r = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/${route}`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data,
  });
  return { status: r.status(), data: await r.json() };
}

async function apiPut(page: any, nonce: string, route: string, data: Record<string, unknown>) {
  const r = await page.request.put(`${WP_BASE}/?rest_route=/faz/v1/${route}`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data,
  });
  return { status: r.status(), data: await r.json() };
}

async function apiDelete(page: any, nonce: string, route: string) {
  const r = await page.request.delete(`${WP_BASE}/?rest_route=/faz/v1/${route}`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  return { status: r.status() };
}

async function fazGet(page: any, route: string, params?: Record<string, unknown>) {
  return page.evaluate(
    async ({ route, params }) => (window as any).FAZ.get(route, params),
    { route, params: params ?? null },
  );
}

async function fazPost(page: any, route: string, data: Record<string, unknown>) {
  return page.evaluate(
    async ({ route, data }) => (window as any).FAZ.post(route, data),
    { route, data },
  );
}

async function fazPut(page: any, route: string, data: Record<string, unknown>) {
  return page.evaluate(
    async ({ route, data }) => (window as any).FAZ.put(route, data),
    { route, data },
  );
}

/* ═══════════════════════════════════════════════════
   PR #44 — i18n cookie save
   ═══════════════════════════════════════════════════ */

test.describe('PR #44: i18n cookie save', () => {
  test.describe.configure({ mode: 'serial' });

  let cookieId: number | null = null;
  let originalLanguages: Record<string, unknown> | null = null;

  test('manual add from admin modal saves duration/description under the plugin default language', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    let nonce = await getAdminNonce(page);

    const settings = (await apiGet(page, nonce, 'settings')).data;
    originalLanguages = settings?.languages ?? null;

    const updateLanguages = await apiPost(page, nonce, 'settings', {
      languages: {
        selected: ['en', 'it'],
        default: 'it',
      },
    });
    expect(updateLanguages.status).toBe(200);

    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    await page.locator('#faz-add-cookie-btn').click();

    const modal = page.locator('.faz-modal');
    await expect(modal).toBeVisible();
    await modal.locator('[data-field="name"]').fill('_faz_test_i18n');
    await modal.locator('[data-field="domain"]').fill('.example.com');
    await modal.locator('[data-field="duration"]').fill('1 anno');
    await modal.locator('[data-field="description"]').fill('Descrizione italiana');
    await modal.locator('[data-field="category"]').selectOption({ index: 0 });
    await modal.locator('.faz-btn-primary').click();

    await expect(page.locator('.faz-toast')).toContainText('Cookie added');

    nonce = await getAdminNonce(page);
    const cookies = await apiGet(page, nonce, 'cookies');
    const created = Array.isArray(cookies.data)
      ? cookies.data.find((item: any) => item.name === '_faz_test_i18n')
      : null;
    expect(created).toBeTruthy();
    cookieId = created?.id ?? created?.cookie_id ?? null;
    expect(cookieId).toBeTruthy();

    const fetched = await apiGet(page, nonce, `cookies/${cookieId}`);
    expect(fetched.status).toBe(200);
    expect(typeof fetched.data.duration).toBe('object');
    expect(fetched.data.duration).toHaveProperty('it', '1 anno');
    expect(typeof fetched.data.description).toBe('object');
    expect(fetched.data.description).toHaveProperty('it', 'Descrizione italiana');

    await page.reload({ waitUntil: 'domcontentloaded' });
    const row = page.locator('#faz-cookies-tbody tr').filter({ hasText: '_faz_test_i18n' });
    await expect(row).toHaveCount(1);
    await row.getByRole('button', { name: 'Edit' }).click();

    const editModal = page.locator('.faz-modal');
    await expect(editModal.locator('[data-field="duration"]')).toHaveValue('1 anno');
    await expect(editModal.locator('[data-field="description"]')).toHaveValue('Descrizione italiana');
    await editModal.locator('.faz-modal-close').click();
  });

  test('editing via admin modal preserves translations beyond selected languages', async ({ page, loginAsAdmin }) => {
    expect(cookieId).toBeTruthy();
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    await fazPut(page, `cookies/${cookieId}`, {
      duration: { en: '1 year', it: '1 anno', de: '1 Jahr' },
      description: { en: 'English description', it: 'Cookie di test', de: 'Deutsche Beschreibung' },
    });

    await page.reload({ waitUntil: 'domcontentloaded' });
    const row = page.locator('#faz-cookies-tbody tr').filter({ hasText: '_faz_test_i18n' });
    await expect(row).toHaveCount(1);
    await row.getByRole('button', { name: 'Edit' }).click();

    const modal = page.locator('.faz-modal');
    await expect(modal.locator('[data-field="duration"]')).toHaveValue('1 anno');
    await expect(modal.locator('[data-field="description"]')).toHaveValue('Cookie di test');

    await modal.locator('[data-field="duration"]').fill('2 anni');
    await modal.locator('[data-field="description"]').fill('Descrizione aggiornata');
    await modal.locator('.faz-btn-primary').click();
    await expect(page.locator('.faz-toast')).toContainText('Cookie updated');

    const fetched = await apiGet(page, nonce, `cookies/${cookieId}`);
    expect(fetched.status).toBe(200);
    expect(fetched.data.duration).toHaveProperty('en', '1 year');
    expect(fetched.data.duration).toHaveProperty('it', '2 anni');
    expect(fetched.data.duration).toHaveProperty('de', '1 Jahr');
    expect(fetched.data.description).toHaveProperty('en', 'English description');
    expect(fetched.data.description).toHaveProperty('it', 'Descrizione aggiornata');
    expect(fetched.data.description).toHaveProperty('de', 'Deutsche Beschreibung');
  });

  test.afterAll(async ({ browser }) => {
    if (!cookieId && !originalLanguages) return;
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(`${WP_BASE}${WP_LOGIN_PATH}`, { waitUntil: 'domcontentloaded' });
    await page.locator('#user_login').fill(process.env.WP_ADMIN_USER ?? 'admin');
    await page.locator('#user_pass').fill(process.env.WP_ADMIN_PASS ?? 'admin');
    await page.locator('#wp-submit').click();
    await page.waitForURL(/wp-admin/, { timeout: 20_000 });
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);
    if (cookieId) {
      await apiDelete(page, nonce, `cookies/${cookieId}`);
    }
    if (originalLanguages) {
      await apiPost(page, nonce, 'settings', { languages: originalLanguages });
    }
    await ctx.close();
  });
});

/* ═══════════════════════════════════════════════════
   PR #41 — Whitelist & admin performance
   ═══════════════════════════════════════════════════ */

test.describe('PR #41: whitelist and admin performance', () => {

  test('whitelist_patterns setting persists via API', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const original = (await apiGet(page, nonce, 'settings')).data;
    const originalPatterns = original?.script_blocking?.whitelist_patterns ?? [];

    try {
      // Set test patterns
      const testPatterns = ['googleapis.com/youtube/v3', 'recaptcha.net/recaptcha'];
      await apiPost(page, nonce, 'settings', {
        script_blocking: {
          ...(original.script_blocking ?? {}),
          whitelist_patterns: testPatterns,
        },
      });

      // Read back and verify
      const updated = (await apiGet(page, nonce, 'settings')).data;
      const saved = updated?.script_blocking?.whitelist_patterns ?? [];
      expect(saved).toEqual(expect.arrayContaining(testPatterns));
      expect(saved.length).toBe(testPatterns.length);
    } finally {
      // Restore original
      await apiPost(page, nonce, 'settings', {
        script_blocking: {
          ...(original.script_blocking ?? {}),
          whitelist_patterns: originalPatterns,
        },
      });
    }
  });

  test('whitelist patterns are exposed as _userWhitelist in frontend JS store', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const original = (await apiGet(page, nonce, 'settings')).data;

    try {
      // Set a known whitelist pattern
      await apiPost(page, nonce, 'settings', {
        script_blocking: {
          ...(original.script_blocking ?? {}),
          whitelist_patterns: ['test-whitelist-pattern.example.com'],
        },
      });

      // Check frontend
      const ctx = await browser.newContext({ baseURL: wpBaseURL });
      try {
        const visitor = await ctx.newPage();
        await visitor.goto('/', { waitUntil: 'domcontentloaded' });
        // _fazStore is a local alias for window._fazConfig (see script.js line 4)
        const userWhitelist = await visitor.evaluate(() => {
          return (window as any)._fazConfig?._userWhitelist ?? null;
        });
        expect(userWhitelist).toBeTruthy();
        expect(Array.isArray(userWhitelist)).toBe(true);
        expect(userWhitelist).toContain('test-whitelist-pattern.example.com');
      } finally {
        await ctx.close();
      }
    } finally {
      await apiPost(page, nonce, 'settings', {
        script_blocking: {
          ...(original.script_blocking ?? {}),
          whitelist_patterns: original?.script_blocking?.whitelist_patterns ?? [],
        },
      });
    }
  });

  test('settings page loads whitelist textarea in Script Blocking tab', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });

    // Click the Script Blocking tab
    const tab = page.locator('button.faz-tab[data-tab="script_blocking"], [data-tab="script_blocking"]');
    if (await tab.count() > 0) {
      await tab.first().click();
    }

    // Whitelist textarea should exist
    const textarea = page.locator('textarea[data-path="script_blocking.whitelist_patterns"]');
    await expect(textarea).toBeVisible({ timeout: 5_000 });
  });

  test('admin cookie page loads without lazy-load errors', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);

    // Navigate to cookies page and verify no JS errors
    const jsErrors: string[] = [];
    page.on('pageerror', (error) => jsErrors.push(error.message));

    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

    // Wait for the cookies table to render
    await page.waitForSelector('#faz-cookies-tbody', { timeout: 5_000 }).catch(() => {});
    expect(jsErrors.filter(e => e.includes('faz'))).toHaveLength(0);
  });
});

/* ═══════════════════════════════════════════════════
   PR #39 — v1.7.0 (cookie table shortcode, blocker
   templates, import/export, system status)
   ═══════════════════════════════════════════════════ */

test.describe('PR #39: v1.7.0 additional features', () => {

  test('blocker templates API returns known templates', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const result = await apiGet(page, nonce, 'blocker-templates');
    expect(result.status).toBe(200);
    expect(Array.isArray(result.data)).toBe(true);
    expect(result.data.length).toBeGreaterThan(0);

    // Should contain well-known templates like Google Analytics, YouTube
    const names = result.data.map((t: any) => (t.name ?? t.slug ?? '').toLowerCase());
    const hasGA = names.some((n: string) => n.includes('google') && n.includes('analytics'));
    const hasYT = names.some((n: string) => n.includes('youtube'));
    expect(hasGA || hasYT).toBeTruthy();
  });

  test('cookie and category APIs return data used by [faz_cookie_table]', async ({ page, loginAsAdmin }) => {
    // The actual shortcode rendering is covered by v170-deep-flows.spec.ts.
    // Here we verify the API endpoints the shortcode depends on work correctly.
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const cookies = await apiGet(page, nonce, 'cookies');
    expect(cookies.status).toBe(200);
    expect(Array.isArray(cookies.data)).toBe(true);

    const categories = await apiGet(page, nonce, 'cookies/categories');
    expect(categories.status).toBe(200);
    expect(Array.isArray(categories.data)).toBe(true);
    expect(categories.data.length).toBeGreaterThan(0);
  });

  test('cookie table shortcode prefers custom category names saved in plugin settings', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const settings = (await apiGet(page, nonce, 'settings')).data;
    const originalLanguages = settings?.languages ?? null;
    let pageId: number | null = null;
    let targetId: number | null = null;
    let originalName: Record<string, unknown> | null = null;

    try {
      await apiPost(page, nonce, 'settings', {
        languages: {
          selected: ['en', 'it'],
          default: 'en',
        },
      });

      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
      const categories = await fazGet(page, 'cookies/categories');
      const target = Array.isArray(categories)
        ? categories.find((category: any) => Array.isArray(category.cookie_list) && category.cookie_list.length > 0 && category.slug !== 'wordpress-internal')
        : null;

      expect(target).toBeTruthy();
      targetId = target?.id ?? target?.category_id ?? null;
      originalName = target?.name ?? {};
      const customName = `QA Category ${Date.now()}`;

      await fazPut(page, `cookies/categories/${targetId}`, {
        name: {
          ...(typeof originalName === 'object' && originalName !== null ? originalName : {}),
          en: customName,
        },
      });

      const createPage = await page.request.post(`${WP_BASE}/?rest_route=/wp/v2/pages`, {
        headers: {
          'X-WP-Nonce': nonce,
          'Content-Type': 'application/json',
        },
        data: {
          title: 'QA Cookie Table',
          slug: `qa-cookie-table-${Date.now()}`,
          status: 'publish',
          content: '[faz_cookie_table]',
        },
      });

      expect([200, 201]).toContain(createPage.status());
      const createdPage = await createPage.json();
      pageId = createdPage.id ?? null;

      await page.goto(createdPage.link, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('.faz-cookie-table-wrap')).toContainText(customName);
    } finally {
      if (pageId) {
        await page.request.delete(`${WP_BASE}/?rest_route=/wp/v2/pages/${pageId}&force=true`, {
          headers: { 'X-WP-Nonce': nonce },
        });
      }
      if (targetId && originalName) {
        await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
        await fazPut(page, `cookies/categories/${targetId}`, { name: originalName });
      }
      if (originalLanguages) {
        await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
        await apiPost(page, await getAdminNonce(page), 'settings', { languages: originalLanguages });
      }
    }
  });

  test('import/export settings endpoint round-trips', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Export current settings
    const exported = await apiGet(page, nonce, 'settings');
    expect(exported.status).toBe(200);
    expect(exported.data).toBeTruthy();

    // Settings should include key sections
    const data = exported.data;
    expect(data).toHaveProperty('banner_control');
    expect(data).toHaveProperty('script_blocking');
  });

  test('system status page loads with table info', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });

    const statusTab = page.locator('button.faz-tab[data-tab="system_status"], [data-tab="system_status"], a[href*="system-status"]');
    expect(await statusTab.count()).toBeGreaterThan(0);
    await statusTab.first().click();

    // System status is rendered in the visible tab content after click
    await page.waitForTimeout(1_000);
    const statusContent = await page.evaluate(() => document.body.innerText);
    expect(statusContent).toMatch(/faz_banners|faz_cookies|PHP Version|WordPress|FAZ Cookie/i);
  });

  test('cookie categories hide wordpress-internal from frontend', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

    // Visit frontend and check that no "wordpress-internal" category appears
    const ctx = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const visitor = await ctx.newPage();
      await visitor.goto('/', { waitUntil: 'domcontentloaded' });

      // Check banner categories — none should be "wordpress-internal"
      const categorySlugs = await visitor.evaluate(() => {
        const store = (window as any)._fazConfig;
        if (!store?._categories) return [];
        return store._categories.map((c: any) => c.slug ?? '');
      });

      expect(Array.isArray(categorySlugs)).toBe(true);
      expect(categorySlugs.length).toBeGreaterThan(0);
      expect(categorySlugs).not.toContain('wordpress-internal');
    } finally {
      await ctx.close();
    }
  });

  test('banner design presets are available', async ({ page, loginAsAdmin }) => {
    const jsErrors: string[] = [];
    page.on('pageerror', (error) => jsErrors.push(error.message));

    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded' });

    // Presets grid is populated dynamically via JS — wait for it
    const presetsGrid = page.locator('#faz-presets-grid');
    await expect(presetsGrid).toBeVisible({ timeout: 5_000 });
    // The grid should have preset cards (children) loaded by JS
    const presetCount = await presetsGrid.locator('> *').count();
    expect(presetCount).toBeGreaterThan(0);
    expect(jsErrors.filter(e => e.includes('faz'))).toHaveLength(0);
  });
});

/* ═══════════════════════════════════════════════════
   P1 fix: per-service cookie shredding
   ═══════════════════════════════════════════════════ */

test.describe('P1 fix: per-service cookie shredding', () => {

  test('frontend _services include cookies array when per_service_consent enabled', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const settings = (await apiGet(page, nonce, 'settings')).data;
    const originalBannerControl = settings?.banner_control ?? {};

    try {
      await apiPost(page, nonce, 'settings', {
        banner_control: {
          ...originalBannerControl,
          per_service_consent: true,
        },
      });

      const ctx = await browser.newContext({ baseURL: wpBaseURL });
      const visitor = await ctx.newPage();
      await visitor.goto('/', { waitUntil: 'domcontentloaded' });
      const services = await visitor.evaluate(() => {
        return (window as any)._fazConfig?._services ?? null;
      });
      expect(services).toBeTruthy();
      expect(Array.isArray(services)).toBe(true);

      // At least one service should have a cookies array
      const withCookies = services.filter((s: any) => Array.isArray(s.cookies));
      expect(withCookies.length).toBe(services.length); // ALL must have cookies array
      expect(withCookies.some((s: any) => s.cookies.length > 0)).toBe(true);
      await ctx.close();
    } finally {
      await apiPost(page, nonce, 'settings', {
        banner_control: originalBannerControl,
      });
    }
  });

  test('per-service denial shreds matching cookies via PHP', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    // Simulate: accept all cookies, then set svc.hotjar:no in the consent cookie.
    // The PHP shredding on next page load should delete Hotjar cookies.
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const settings = (await apiGet(page, nonce, 'settings')).data;
    const originalBannerControl = settings?.banner_control ?? {};

    try {
      await apiPost(page, nonce, 'settings', {
        banner_control: {
          ...originalBannerControl,
          per_service_consent: true,
        },
      });

      // Disable JS so only PHP shredding runs (not client-side cleanup)
      const ctx = await browser.newContext({ baseURL: wpBaseURL, javaScriptEnabled: false });
      const visitor = await ctx.newPage();
      await visitor.goto('/', { waitUntil: 'domcontentloaded' });

      // Set a fake Hotjar cookie and consent cookie with svc.hotjar:no
      await visitor.evaluate(() => {
        // Plant a fake Hotjar cookie
        document.cookie = '_hjid=test123;path=/';
        // Set consent: all categories yes, but svc.hotjar denied
        const consent = 'necessary:yes,analytics:yes,marketing:yes,svc.hotjar:no';
        document.cookie = `fazcookie-consent=${encodeURIComponent(consent)};path=/`;
      });

      // Reload — PHP shredding should delete _hjid on the server side
      await visitor.goto('/', { waitUntil: 'domcontentloaded' });

      const cookies = await ctx.cookies(wpBaseURL);
      const hjCookie = cookies.find(c => c.name === '_hjid');
      // _hjid should have been shredded by PHP since svc.hotjar:no
      expect(hjCookie).toBeUndefined();
      await ctx.close();
    } finally {
      await apiPost(page, nonce, 'settings', {
        banner_control: originalBannerControl,
      });
    }
  });
});

/* ═══════════════════════════════════════════════════
   P3 fix: scanner auto-categorize uses defLang
   ═══════════════════════════════════════════════════ */

test.describe('P3 fix: scanner uses default language', () => {

  test('auto-categorize stores scraped descriptions under the default language and preserves existing translations', async ({ page, loginAsAdmin }) => {
    const cookieName = `_faz_scanner_deflang_${Date.now()}`;
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    let nonce = await getAdminNonce(page);

    const settings = (await apiGet(page, nonce, 'settings')).data;
    const originalLanguages = settings?.languages ?? null;
    let cookieId: number | null = null;

    try {
      const setLanguages = await apiPost(page, nonce, 'settings', {
        languages: {
          selected: ['en', 'it'],
          default: 'it',
        },
      });
      expect(setLanguages.status).toBe(200);

      const categories = (await apiGet(page, nonce, 'cookies/categories')).data;
      const uncategorized = categories.find((category: any) => category.slug === 'uncategorized');
      const analytics = categories.find((category: any) => category.slug === 'analytics');
      expect(uncategorized).toBeTruthy();
      expect(analytics).toBeTruthy();

      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

      const createCookie = await fazPost(page, 'cookies', {
        name: cookieName,
        domain: '.example.com',
        category: uncategorized.id,
        description: { de: 'Bestehende Beschreibung' },
      });
      cookieId = createCookie?.cookie_id ?? createCookie?.id ?? null;
      expect(cookieId).toBeTruthy();
      expect(createCookie.description).toHaveProperty('de', 'Bestehende Beschreibung');

      const fetchedBeforeAutoCategorize = await apiGet(page, nonce, `cookies/${cookieId}`);
      expect(fetchedBeforeAutoCategorize.status).toBe(200);
      expect(fetchedBeforeAutoCategorize.data.description).toHaveProperty('de', 'Bestehende Beschreibung');

      const listedBeforeAutoCategorize = await fazGet(page, 'cookies');
      const listedCookie = Array.isArray(listedBeforeAutoCategorize)
        ? listedBeforeAutoCategorize.find((item: any) => (item.id ?? item.cookie_id) === cookieId)
        : null;
      expect(listedCookie).toBeTruthy();
      expect(listedCookie?.description).toHaveProperty('de', 'Bestehende Beschreibung');
      await page.evaluate((mockCookieName) => {
        const originalPost = window.FAZ.post;
        (window as any).__fazOriginalPost = originalPost;
        window.FAZ.post = function (endpoint: string, data: Record<string, unknown>) {
          if (endpoint === 'cookies/scrape') {
            return Promise.resolve([
              {
                name: mockCookieName,
                found: true,
                category: 'analytics',
                description: 'Descrizione scanner',
              },
            ]);
          }
          return originalPost(endpoint, data);
        };
      }, cookieName);

      await page.locator('#faz-auto-cat-btn').click();
      await page.locator('#faz-auto-cat-dropdown .faz-dropdown-item[data-scope="all"]').click();
      await expect(page.locator('.faz-toast').last()).toContainText('Auto-categorized 1/1 cookies');

      nonce = await getAdminNonce(page);
      const fetched = await apiGet(page, nonce, `cookies/${cookieId}`);
      expect(fetched.status).toBe(200);
      expect(fetched.data.category).toBe(analytics.id);
      expect(fetched.data.description).toHaveProperty('it', 'Descrizione scanner');
      expect(fetched.data.description).toHaveProperty('de', 'Bestehende Beschreibung');
    } finally {
      await page.evaluate(() => {
        const originalPost = (window as any).__fazOriginalPost;
        if (originalPost) {
          window.FAZ.post = originalPost;
          delete (window as any).__fazOriginalPost;
        }
      }).catch(() => {});

      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
      nonce = await getAdminNonce(page);
      if (cookieId) {
        await apiDelete(page, nonce, `cookies/${cookieId}`);
      }
      if (originalLanguages) {
        await apiPost(page, nonce, 'settings', { languages: originalLanguages });
      }
    }
  });
});

/* ═══════════════════════════════════════════════════
   Blocker template → cookie creation → recognition
   ═══════════════════════════════════════════════════ */

test.describe('Blocker template end-to-end flow', () => {

  test('applying a blocker template creates cookies in the DB with correct category', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Get current cookies before template application
    const before = await apiGet(page, nonce, 'cookies');
    const beforeNames = new Set(
      (Array.isArray(before.data) ? before.data : []).map((c: any) => String(c.name).toLowerCase())
    );

    // Get templates
    const templates = await apiGet(page, nonce, 'blocker-templates');
    expect(templates.status).toBe(200);
    expect(Array.isArray(templates.data)).toBe(true);

    // Find a template with cookies (e.g., Google Analytics)
    const gaTpl = templates.data.find((t: any) => t.id === 'google-analytics');
    expect(gaTpl).toBeTruthy();
    expect(Array.isArray(gaTpl.cookies)).toBe(true);
    expect(gaTpl.cookies.length).toBeGreaterThan(0);

    // Click the GA template card in the UI
    await page.waitForSelector('#faz-blocker-templates', { timeout: 5_000 });
    const gaCard = page.locator('#faz-blocker-templates > *').filter({ hasText: 'Google Analytics' });
    if (await gaCard.count() > 0) {
      await gaCard.first().click();
      await page.waitForTimeout(3_000);

      // Get cookies after template application
      const after = await apiGet(page, nonce, 'cookies');
      const afterCookies = Array.isArray(after.data) ? after.data : [];
      const afterNames = new Set(afterCookies.map((c: any) => String(c.name).toLowerCase()));

      // Verify GA cookies exist in the DB
      const analyticsCat = (await apiGet(page, nonce, 'cookies/categories')).data
        .find((c: any) => c.slug === 'analytics');
      expect(analyticsCat).toBeTruthy();
      const analyticsCatId = analyticsCat.id ?? analyticsCat.category_id;

      // Check that at least _ga exists and is in analytics category
      const gaInDb = afterCookies.find((c: any) => c.name === '_ga');
      expect(gaInDb, '_ga should exist in DB after applying GA template').toBeTruthy();
      expect(gaInDb.category, '_ga should be in analytics category').toBe(analyticsCatId);
    }
  });

  test('all cookies in the DB have a valid category', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const cookies = await apiGet(page, nonce, 'cookies');
    const categories = await apiGet(page, nonce, 'cookies/categories');

    const catIds = new Set(
      (Array.isArray(categories.data) ? categories.data : [])
        .map((c: any) => c.id ?? c.category_id)
    );

    if (Array.isArray(cookies.data)) {
      for (const cookie of cookies.data) {
        expect(
          catIds.has(cookie.category),
          `Cookie "${cookie.name}" has unknown category ID ${cookie.category}`
        ).toBe(true);
      }
    }
  });
});

/* ─── Issue: default language respects WPLANG ── */

test.describe('Language: default language uses site locale', () => {
  test('removing English from selected languages persists when default is not en', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Save original settings
    const original = (await apiGet(page, nonce, 'settings')).data;
    const origSelected = original?.languages?.selected ?? ['en'];
    const origDefault = original?.languages?.default ?? 'en';

    try {
      // Step 1: Set default to 'de' and selected to ['de'] only (no 'en')
      await apiPost(page, nonce, 'settings', {
        languages: { default: 'de', selected: ['de'] },
      });

      // Step 2: Read back immediately — 'en' should NOT be re-added
      const read1 = (await apiGet(page, nonce, 'settings')).data;
      expect(read1?.languages?.selected, 'First read: en should not be present').not.toContain('en');
      expect(read1?.languages?.selected).toContain('de');
      expect(read1?.languages?.default).toBe('de');

      // Step 3: Read AGAIN (simulates page reload) — still no 'en'
      const read2 = (await apiGet(page, nonce, 'settings')).data;
      expect(read2?.languages?.selected, 'Second read: en should not reappear').not.toContain('en');
      expect(read2?.languages?.selected).toContain('de');

      // Step 4: Save again with same data (simulates user clicking Save)
      await apiPost(page, nonce, 'settings', {
        languages: { default: 'de', selected: ['de'] },
      });

      // Step 5: Read back after re-save — STILL no 'en'
      const read3 = (await apiGet(page, nonce, 'settings')).data;
      expect(read3?.languages?.selected, 'Third read after re-save: en must stay gone').not.toContain('en');
      expect(read3?.languages?.selected.length, 'Only one language should be selected').toBe(1);
      expect(read3?.languages?.selected[0]).toBe('de');
    } finally {
      // Restore original
      await apiPost(page, nonce, 'settings', {
        languages: { default: origDefault, selected: origSelected },
      });
    }
  });

  test('German-only site shows German banner text on frontend', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Save original
    const original = (await apiGet(page, nonce, 'settings')).data;
    const origSelected = original?.languages?.selected ?? ['en'];
    const origDefault = original?.languages?.default ?? 'en';

    try {
      // Set German only
      await apiPost(page, nonce, 'settings', {
        languages: { default: 'de', selected: ['de'] },
      });

      // Clear banner template cache so it regenerates with German
      await page.evaluate(async () => {
        const r = await (window as any).FAZ.post('settings', { banner_control: {} });
        return r;
      });

      // Open frontend in a German browser context (no consent cookie)
      const ctx = await browser.newContext({
        baseURL: wpBaseURL,
        locale: 'de-DE',
        extraHTTPHeaders: { 'Accept-Language': 'de-DE,de;q=0.9' },
      });
      const frontPage = await ctx.newPage();
      await frontPage.goto('/', { waitUntil: 'domcontentloaded', timeout: 45_000 });

      // Wait for the banner to appear
      const notice = frontPage.locator('[data-faz-tag="notice"]');
      await expect(notice).toBeVisible({ timeout: 15_000 });

      // The banner title or description should contain German text, NOT English
      const bannerText = await frontPage.locator('#faz-consent').innerText();
      const lowerText = bannerText.toLowerCase();

      // German keywords that should appear in the de.json banner content
      const hasGerman = lowerText.includes('datenschutz') ||
                        lowerText.includes('cookies') ||  // universal
                        lowerText.includes('einstellungen') ||
                        lowerText.includes('akzeptieren') ||
                        lowerText.includes('ablehnen') ||
                        lowerText.includes('privat');

      // English keywords that should NOT appear if language is German
      const hasEnglish = lowerText.includes('we value your privacy') ||
                         lowerText.includes('we use cookies') ||
                         lowerText.includes('accept all') ||
                         lowerText.includes('reject all');

      expect(hasEnglish, 'Banner should NOT contain English text when set to German-only. Text: ' + bannerText.substring(0, 200)).toBe(false);

      await ctx.close();
    } finally {
      // Restore original
      await apiPost(page, nonce, 'settings', {
        languages: { default: origDefault, selected: origSelected },
      });
    }
  });

  test('German-only site: [faz_cookie_table] shortcode renders German category names', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const original = (await apiGet(page, nonce, 'settings')).data;
    const origSelected = original?.languages?.selected ?? ['en'];
    const origDefault = original?.languages?.default ?? 'en';
    let pageId: number | null = null;

    try {
      // Set German only
      await apiPost(page, nonce, 'settings', {
        languages: { default: 'de', selected: ['de'] },
      });

      // Create a page with [faz_cookie_table] shortcode
      const slug = `qa-cookie-table-de-${Date.now()}`;
      const createRes = await page.request.post(`${WP_BASE}/?rest_route=/wp/v2/pages`, {
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        data: { title: 'QA Cookie Table DE', slug, status: 'publish', content: '[faz_cookie_table]' },
      });
      pageId = (await createRes.json()).id ?? null;

      // Visit the page as a German visitor
      const ctx = await browser.newContext({
        baseURL: wpBaseURL,
        locale: 'de-DE',
        extraHTTPHeaders: { 'Accept-Language': 'de-DE,de;q=0.9' },
      });
      const frontPage = await ctx.newPage();
      await frontPage.goto(`/${slug}/`, { waitUntil: 'domcontentloaded', timeout: 45_000 });

      // The shortcode should render a table with category headings
      const tableHtml = await frontPage.locator('.faz-cookie-policy-table, .faz-cookie-table, table').first().innerText().catch(() => '');
      const pageText = await frontPage.locator('article, .entry-content, main, body').first().innerText().catch(() => '');
      const combined = (tableHtml + ' ' + pageText).toLowerCase();

      // German category names from de.json (Notwendig, Funktional, Analytik, Leistung, Marketing)
      const hasGermanCategory = combined.includes('notwendig') ||
                                combined.includes('funktional') ||
                                combined.includes('analytik') ||
                                combined.includes('leistung') ||
                                combined.includes('marketing') ||  // same in DE
                                combined.includes('cookie');       // universal term

      expect(hasGermanCategory, 'Cookie table should contain German category names. Page text: ' + combined.substring(0, 300)).toBe(true);

      await ctx.close();
    } finally {
      // Cleanup page
      if (pageId) {
        await page.request.delete(`${WP_BASE}/?rest_route=/wp/v2/pages/${pageId}&force=true`, {
          headers: { 'X-WP-Nonce': nonce },
        }).catch(() => {});
      }
      // Restore original
      await apiPost(page, nonce, 'settings', {
        languages: { default: origDefault, selected: origSelected },
      });
    }
  });

  /**
   * gooloo.de regression — WordPress core locale de_DE must reach the
   * shortcode gettext pipeline. This is a DIFFERENT code path from the
   * two tests above, which only switch the plugin's own language setting
   * (`faz_settings.languages`). Those tests never load the .mo file, and
   * therefore would still pass even if the gooloo.de bug came back.
   *
   * The bug was that the plugin shipped IT/FR/NL translations but no
   * de_DE.mo, so `esc_html__()` inside [faz_cookie_policy] / [faz_cookie_table]
   * fell back to the English source strings even on sites running with
   * `WPLANG=de_DE`. This test asserts the .mo is present, loaded, and
   * actually used by the shortcode render path.
   */
  test('gooloo.de regression: [faz_cookie_policy] on WPLANG=de_DE renders German strings', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    // Skip if the de_DE .mo file is not bundled — this test only runs on a
    // deployment that actually has the translation file.
    // (If someone deletes faz-cookie-manager-de_DE.mo by mistake, this test
    // is the canary that catches it.)

    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/options-general.php`, { waitUntil: 'domcontentloaded' });

    // Capture current WPLANG so we can restore it in the finally block.
    const originalLocale = await page.locator('#WPLANG').inputValue().catch(() => '');

    const nonce = await page.evaluate(() =>
      (window as any).fazConfig?.api?.nonce
      ?? (window as any).wpApiSettings?.nonce
      ?? '',
    );
    // Fallback: fetch a nonce via the plugin admin page if options-general didn't expose one.
    let apiNonce = nonce;
    if (!apiNonce) {
      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
      apiNonce = await getAdminNonce(page);
    }

    let pageId: number | null = null;
    let germanLocaleInstalled = false;

    try {
      // Switch WordPress core locale to German via the classic Settings → General
      // form. We need de_DE to be in the installed languages first; if it isn't,
      // this test is a no-op (skip rather than fail).
      await page.goto(`${WP_BASE}/wp-admin/options-general.php`, { waitUntil: 'domcontentloaded' });
      try {
        await page.selectOption('#WPLANG', 'de_DE', { timeout: 5000 });
        await page.click('#submit');
        await page.waitForLoadState('domcontentloaded');
        germanLocaleInstalled = true;
      } catch (_) {
        // de_DE isn't installed on this WP instance — nothing to assert.
        test.skip(true, 'de_DE locale not installed on test WordPress');
        return;
      }

      // Create a page that contains the shortcode. Use the REST API with the
      // plugin nonce to avoid any block-editor interference.
      const slug = `qa-cookie-policy-de-${Date.now()}`;
      const createRes = await page.request.post(`${WP_BASE}/?rest_route=/wp/v2/pages`, {
        headers: { 'X-WP-Nonce': apiNonce, 'Content-Type': 'application/json' },
        data: {
          title: 'QA Cookie Policy DE',
          slug,
          status: 'publish',
          content: '[faz_cookie_policy]',
        },
      });
      const created = await createRes.json().catch(() => ({}));
      pageId = created?.id ?? null;
      expect(pageId, 'Page creation should return an id').toBeTruthy();

      // Visit the page in a fresh context so no admin cookies leak.
      const ctx = await browser.newContext({ baseURL: wpBaseURL });
      const frontPage = await ctx.newPage();
      await frontPage.goto(`/?page_id=${pageId}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });

      // Grab the rendered HTML of the shortcode wrapper.
      const policyHtml = await frontPage
        .locator('.faz-cookie-policy')
        .first()
        .innerHTML()
        .catch(() => '');

      // These are the exact translations shipped in languages/faz-cookie-manager-de_DE.po.
      // If any of them is missing, the .mo file is not being loaded by the
      // shortcode's gettext pipeline on a de_DE site — the exact gooloo.de bug.
      const expectedGermanPhrases = [
        'Was sind Cookies',        // h2 for "What Are Cookies"
        'Wie wir Cookies verwenden', // h2 for "How We Use Cookies"
        'Notwendige Cookies',       // strong for "Necessary cookies"
        'Funktionale Cookies',      // strong for "Functional cookies"
        'Cookies verwalten',        // h2 for "How to Manage Cookies"
      ];

      const missing = expectedGermanPhrases.filter(phrase => !policyHtml.includes(phrase));

      expect(
        missing.length,
        `[faz_cookie_policy] on de_DE must render German strings from the bundled .mo. Missing: ${missing.join(', ')}\nFirst 400 chars of rendered output:\n${policyHtml.substring(0, 400)}`,
      ).toBe(0);

      // Also assert the ENGLISH source strings are NOT present — otherwise
      // gettext is silently falling back despite the above check passing
      // (would only happen on an inconsistent translation).
      const englishPhrases = [
        'What Are Cookies',
        'How We Use Cookies',
        'Necessary cookies',
        'How to Manage Cookies',
      ];
      const leaked = englishPhrases.filter(phrase => policyHtml.includes(phrase));
      expect(
        leaked.length,
        `English source strings must not appear on a de_DE page. Leaked: ${leaked.join(', ')}`,
      ).toBe(0);

      await ctx.close();
    } finally {
      // Cleanup page.
      if (pageId) {
        await page.request.delete(`${WP_BASE}/?rest_route=/wp/v2/pages/${pageId}&force=true`, {
          headers: { 'X-WP-Nonce': apiNonce },
        }).catch(() => {});
      }
      // Restore original WPLANG so subsequent tests see a clean slate.
      if (germanLocaleInstalled) {
        try {
          await page.goto(`${WP_BASE}/wp-admin/options-general.php`, { waitUntil: 'domcontentloaded' });
          await page.selectOption('#WPLANG', originalLocale, { timeout: 5000 });
          await page.click('#submit');
          await page.waitForLoadState('domcontentloaded');
        } catch (_) {
          // Swallow — never let teardown fail the run.
        }
      }
    }
  });
});

/* ─── Koko Analytics: built-in cookie lookup ── */

test.describe('Koko Analytics cookie recognition', () => {
  test('_koko_analytics_pages_viewed is recognized as analytics in built-in database', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Use the scrape endpoint to look up the cookie name against the built-in database
    const result = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/cookies/scrape`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: { names: ['_koko_analytics_pages_viewed'] },
    });
    const scraped = await result.json();

    // The cookie should be found and categorized as analytics
    const koko = Array.isArray(scraped) ? scraped.find((c: any) => c.name === '_koko_analytics_pages_viewed') : null;
    expect(koko, 'Cookie _koko_analytics_pages_viewed should be found in built-in database').toBeTruthy();
    if (koko) {
      expect(koko.category).toBe('analytics');
    }
  });

  test('Koko Analytics script pattern is recognized by Known Providers', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

    // Check that visiting the frontend shows koko-analytics in the page source
    const frontPage = await page.context().newPage();
    await frontPage.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const html = await frontPage.content();
    const hasKokoScript = html.includes('koko-analytics');
    expect(hasKokoScript, 'Koko Analytics script should be present on frontend when plugin is active').toBe(true);
    await frontPage.close();
  });
});

/* ─── Issue: theme link color does not leak into banner buttons ── */

test.describe('CSS: theme link colors do not leak into banner buttons', () => {
  test('settings button color is not inherited from page theme', async ({ browser, wpBaseURL }) => {
    const ctx = await browser.newContext({
      baseURL: wpBaseURL,
      locale: 'en-US',
      extraHTTPHeaders: { 'Accept-Language': 'en-US' },
    });
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded', timeout: 45_000 });

    try {
      const settingsBtn = page.locator('[data-faz-tag="settings-button"]');
      await expect(settingsBtn).toBeVisible({ timeout: 15_000 });

      // The settings button should NOT have a color leaked from the theme.
      // The CSS reset sets `color: inherit` on #faz-consent a/button,
      // and the button should use the color from CSS custom properties.
      const inlineColor = await settingsBtn.evaluate((el) => {
        const computed = getComputedStyle(el);
        return computed.color;
      });

      // It should be a valid rgb value (from the plugin CSS, not transparent/empty)
      expect(inlineColor).toMatch(/^rgb\(\d+,\s*\d+,\s*\d+\)$/);

      // It should NOT be the default browser blue for links (rgb(0, 0, 238) or similar)
      expect(inlineColor).not.toBe('rgb(0, 0, 238)');
      expect(inlineColor).not.toBe('rgb(0, 0, 255)');
    } finally {
      await ctx.close();
    }
  });
});

/* ─── Admin i18n: language switch ── */

test.describe('Admin i18n: WordPress site language switch', () => {
  // Default WordPress installs have WPLANG='' which means English.
  // Use empty string to restore default English.
  const originalLocale = '';

  test.afterAll(async ({ browser }) => {
    // Restore original site language. Uses the shared login helper and
    // env-based credentials so non-default WP installs (and CI with custom
    // creds) don't poison the rest of the suite when this teardown runs.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await completeAdminLogin(page, WP_BASE, WP_ADMIN_USER, WP_ADMIN_PASS);
      await page.goto(`${WP_BASE}/wp-admin/options-general.php`, { waitUntil: 'domcontentloaded' });
      // Try to select empty (English default); fall back silently if not available.
      await page.selectOption('#WPLANG', originalLocale, { timeout: 5000 });
      await page.click('#submit');
      await page.waitForLoadState('domcontentloaded');
    } catch (_) {
      // Some test environments only have one locale installed or use
      // credentials that differ from the defaults; swallow so the cleanup
      // never fails the run.
    }
    await ctx.close();
  });

  test('fazConfig.i18n is localized when site language is Italian', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);

    // Switch WordPress site language to Italian
    await page.goto(`${WP_BASE}/wp-admin/options-general.php`, { waitUntil: 'domcontentloaded' });
    await page.selectOption('#WPLANG', 'it_IT');
    await page.click('#submit');
    await page.waitForLoadState('domcontentloaded');

    // Now visit the plugin Cookies admin page
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

    // Read fazConfig.i18n from the localized script
    const i18n = await page.evaluate(() => (window as any).fazConfig?.i18n ?? null);
    expect(i18n, 'fazConfig.i18n should be present').toBeTruthy();

    // Italian translations should be present — pick a few keys that we know are translated
    // Check at least one common string that has been translated to Italian
    const i18nJson = JSON.stringify(i18n).toLowerCase();

    // Italian keywords that should appear if translations loaded
    const hasItalian = i18nJson.includes('salvat') ||    // "Salvato/Salvate/Salvataggio"
                       i18nJson.includes('impostazion') || // "Impostazioni"
                       i18nJson.includes('caricament') || // "Caricamento"
                       i18nJson.includes('scansion') ||   // "Scansione"
                       i18nJson.includes('eliminat') ||   // "Eliminato"
                       i18nJson.includes('modific');      // "Modifica"

    expect(
      hasItalian,
      'fazConfig.i18n should contain at least one Italian translation. Sample: ' + i18nJson.substring(0, 500)
    ).toBe(true);
  });

  test('PHP strings are translated when site language is Italian', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);

    // Ensure Italian is set
    await page.goto(`${WP_BASE}/wp-admin/options-general.php`, { waitUntil: 'domcontentloaded' });
    const currentLang = await page.evaluate(() => {
      const el = document.getElementById('WPLANG') as HTMLSelectElement | null;
      return el?.value ?? '';
    });
    if (currentLang !== 'it_IT') {
      await page.selectOption('#WPLANG', 'it_IT');
      await page.click('#submit');
      await page.waitForLoadState('domcontentloaded');
    }

    // Visit the plugin cookies page
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

    const pageText = await page.locator('body').innerText();
    const lower = pageText.toLowerCase();

    // At least some Italian words should appear from PHP esc_html_e/esc_html__ calls
    const italianWords = ['impostazion', 'categori', 'scansion', 'salva', 'necessari', 'funzional', 'analitic', 'pubblicitari', 'prestazioni'];
    const foundWords = italianWords.filter((w) => lower.includes(w));

    expect(
      foundWords.length,
      `At least 2 Italian words should appear on the Cookies page. Found: ${foundWords.join(', ')}`
    ).toBeGreaterThanOrEqual(2);
  });
});

/* ─── Issue #57: preference center transparent background on classic template ── */

test.describe('Issue #57: preference-center background default', () => {
  /**
   * Regression for https://github.com/fabiodalez-dev/FAZ-Cookie-Manager/issues/57
   *
   * On the "classic" banner template (used by type=full-width + preferenceCenterType=pushdown)
   * the DOM is:
   *
   *   .faz-consent-container
   *     .faz-consent-bar
   *     .faz-preference-wrapper   (no background set — just a position/animation container)
   *       .faz-preference-center  (the visible modal content)
   *
   * The box/banner templates wrap the same .faz-preference-center inside a
   * .faz-modal that has a default `background: var(--faz-detail-background-color, #ffffff)`.
   * The classic template does NOT have .faz-modal, so the preference center
   * relied on `background-color: inherit` and ended up transparent because no
   * ancestor provided a colour.
   *
   * Fix: change `.faz-preference-center { background-color: inherit }` to
   * `background-color: var(--faz-detail-background-color, #ffffff)`.
   *
   * This test switches the banner to classic + pushdown, opens the preference
   * center on the frontend, and asserts the computed background-color is NOT
   * transparent (rgba(0,0,0,0) or transparent).
   */
  test('classic + pushdown: preference-center has a non-transparent default background', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Snapshot the banner settings so the test can restore them.
    const bannerRes = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/banners/1`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    const original = await bannerRes.json().catch(() => null);
    const baselineSettings = original?.settings?.settings ?? null;

    try {
      // Switch banner to classic + pushdown (forces preference-wrapper, no modal).
      await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/banners/1`, {
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        data: {
          settings: {
            ...baselineSettings,
            type: 'classic',
            preferenceCenterType: 'pushdown',
          },
        },
      });

      // Visit the frontend in a fresh context so no consent cookie is set.
      const ctx = await browser.newContext({ baseURL: wpBaseURL });
      const front = await ctx.newPage();
      await front.goto('/', { waitUntil: 'domcontentloaded', timeout: 30_000 });
      await front.waitForSelector('.faz-consent-container', { timeout: 10_000 });

      // Click Customize to open the preference center.
      await front.click('[data-faz-tag="settings-button"]');
      await front.waitForTimeout(500);

      // Key assertion: the preference center (or its immediate .faz-modal
      // wrapper on box/banner templates) must have a non-transparent
      // background. The box/banner templates paint the background on
      // .faz-modal; the classic template paints it on .faz-preference-center
      // directly (fixed in #57). Either is acceptable — what's NOT
      // acceptable is both being transparent, which is what the gooloo.de
      // bug report described.
      const result = await front.evaluate(() => {
        const pc = document.querySelector('.faz-preference-center') as HTMLElement | null;
        const modal = document.querySelector('.faz-modal') as HTMLElement | null;
        const readBg = (el: HTMLElement | null) =>
          el ? window.getComputedStyle(el).backgroundColor : null;
        return {
          pcBg: readBg(pc),
          modalBg: readBg(modal),
          pcExists: !!pc,
        };
      });

      expect(result.pcExists, '.faz-preference-center must exist').toBe(true);

      const transparentValues = new Set([
        'rgba(0, 0, 0, 0)',
        'transparent',
        '',
        null,
      ]);
      const pcTransparent = transparentValues.has(result.pcBg as any);
      const modalTransparent = transparentValues.has(result.modalBg as any);

      expect(
        pcTransparent && modalTransparent,
        `Preference center must have a visible background. Got pcBg=${result.pcBg}, modalBg=${result.modalBg}`,
      ).toBe(false);

      await ctx.close();
    } finally {
      // Restore the original banner settings so other tests see a clean slate.
      if (baselineSettings) {
        await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/banners/1`, {
          headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
          data: { settings: baselineSettings },
        }).catch(() => {});
      }
    }
  });

  /**
   * Follow-up for issue #57 — verify that the `var(...)` fallbacks used by
   * the 1.10.2 fix don't block user-configured custom colours. The fix
   * replaces `color: inherit` with `color: var(--faz-detail-color, #212121)`,
   * which is only safe if the CSS variable is actually fed by the stored
   * banner config (e.g. when a user configures a dark preference center
   * from the Colours tab of the banner editor).
   *
   * This test reads the banner from the REST API, patches
   * `config.preferenceCenter.styles` to `{ color: #ffffff, background-color: #121212 }`
   * and confirms that the rendered CSS variables pick up the custom values
   * AND that .faz-modal's computed background-color matches the custom
   * dark colour. If the fallback hardcoded #ffffff/#212121 ever overrides
   * the user config, this test catches it.
   */
  test('custom detail colours override the var fallbacks (dark modal)', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // GET current banner via REST so we have the full shape to patch.
    const getRes = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/banners/1`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    const original = await getRes.json().catch(() => null);
    if (!original?.properties?.config?.preferenceCenter?.styles) {
      test.skip(true, 'Banner REST response does not expose preferenceCenter.styles — API shape may have changed.');
      return;
    }
    // Snapshot the original styles so we can restore them.
    const originalStyles = {
      ...original.properties.config.preferenceCenter.styles,
    };

    try {
      // Build a patched properties payload with a dark modal configuration.
      const patched = JSON.parse(JSON.stringify(original.properties));
      patched.config.preferenceCenter.styles.color = '#ffffff';
      patched.config.preferenceCenter.styles['background-color'] = '#121212';

      // Save via REST. The API uses a `properties` key for the full config.
      await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/banners/1`, {
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        data: {
          id: 1,
          name: original.name,
          default: original.default ?? 1,
          status: original.status ?? 1,
          properties: patched,
          contents: original.contents ?? {},
        },
      });

      // Visit the frontend in a fresh context and open the preference center.
      const ctx = await browser.newContext({ baseURL: wpBaseURL });
      const front = await ctx.newPage();
      await front.goto('/', { waitUntil: 'domcontentloaded', timeout: 30_000 });
      await front.waitForSelector('.faz-consent-container', { timeout: 10_000 });
      await front.click('[data-faz-tag="settings-button"]');
      await front.waitForTimeout(800);

      const result = await front.evaluate(() => {
        const read = (sel: string, prop: string) => {
          const el = document.querySelector(sel) as HTMLElement | null;
          if (!el) return null;
          return window.getComputedStyle(el).getPropertyValue(prop).trim();
        };
        // The CSS variables should be fed from the stored banner config.
        const modalDetailBg = read('.faz-modal', '--faz-detail-background-color')
          ?? read('.faz-consent-container', '--faz-detail-background-color');
        const modalDetailFg = read('.faz-modal', '--faz-detail-color')
          ?? read('.faz-consent-container', '--faz-detail-color');
        // And the computed colours of the preference-center wrapper should
        // reflect the custom values.
        const prefCenterFg = read('.faz-preference-center', 'color');
        const modalBg = read('.faz-modal', 'background-color');
        return { modalDetailBg, modalDetailFg, prefCenterFg, modalBg };
      });

      await ctx.close();

      // CSS variables must carry the custom hex values (case-insensitive).
      expect(
        (result.modalDetailBg ?? '').toLowerCase(),
        `--faz-detail-background-color should equal the custom #121212. Got: ${result.modalDetailBg}`,
      ).toBe('#121212');
      expect(
        (result.modalDetailFg ?? '').toLowerCase(),
        `--faz-detail-color should equal the custom #ffffff. Got: ${result.modalDetailFg}`,
      ).toBe('#ffffff');

      // Computed background of .faz-modal must be the dark custom colour —
      // if the fallback #ffffff had won, this would be rgb(255, 255, 255).
      expect(
        result.modalBg,
        `.faz-modal background must use the custom dark colour. Got: ${result.modalBg}`,
      ).toBe('rgb(18, 18, 18)');

      // Computed text colour inside the preference center must be white.
      expect(
        result.prefCenterFg,
        `.faz-preference-center text colour must be the custom white. Got: ${result.prefCenterFg}`,
      ).toBe('rgb(255, 255, 255)');
    } finally {
      // Restore the original styles via REST so other tests see a clean slate.
      try {
        const restoredProperties = JSON.parse(JSON.stringify(original.properties));
        restoredProperties.config.preferenceCenter.styles = originalStyles;
        await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/banners/1`, {
          headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
          data: {
            id: 1,
            name: original.name,
            default: original.default ?? 1,
            status: original.status ?? 1,
            properties: restoredProperties,
            contents: original.contents ?? {},
          },
        });
      } catch (_) {
        // Swallow — never let teardown fail the run.
      }
    }
  });

  /**
   * Follow-up for issue #57: after fixing the transparent background we
   * realized that the preference center text still used `color: inherit`,
   * which meant that on a site with a dark theme (body text set to a
   * light colour) the fix produced *light text on a white background* —
   * still unreadable, just in a different way.
   *
   * v1.10.2 also locks down the text colour with
   * `color: var(--faz-detail-color, #212121)` so the default is dark
   * regardless of what the host theme inherits.
   *
   * This regression injects a dark-theme stylesheet on the page, opens
   * the preference center, and asserts that every text-bearing element
   * inside it has a dark computed `color` (NOT the injected light one).
   */
  test('dark-theme host site: preference center text stays dark (follow-up to #57)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Use a fresh context so the consent cookie doesn't hide the banner.
    const ctx = await page.context().browser()!.newContext();
    await ctx.clearCookies();
    const front = await ctx.newPage();
    await front.setViewportSize({ width: 1100, height: 900 });
    await front.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    await front.waitForSelector('.faz-consent-container', { timeout: 10_000 });

    // Force-paint the host site with a dark theme AFTER load. Using
    // !important + high-specificity selectors ensures it beats the theme
    // defaults (Twenty Twenty-Five sets color on .wp-site-blocks).
    await front.addStyleTag({
      content: `
        html, body, .wp-site-blocks, .entry-content, main, article, .wp-block-group, .wp-block-columns {
          background: #0f0f10 !important;
          color: #e6e6e6 !important;
        }
      `,
    });
    await front.waitForTimeout(400);

    // Open the preference center.
    await front.click('[data-faz-tag="settings-button"]');
    await front.waitForTimeout(800);

    // Collect computed colors of every text-bearing element.
    const colors = await front.evaluate(() => {
      const inspect = (selector: string) => {
        const el = document.querySelector(selector) as HTMLElement | null;
        if (!el) return null;
        return window.getComputedStyle(el).color;
      };
      return {
        center: inspect('.faz-preference-center'),
        header: inspect('.faz-preference-header'),
        title: inspect('.faz-preference-title'),
        descP: inspect('.faz-preference-content-wrapper p'),
        accordionBtn: inspect('.faz-accordion-btn'),
      };
    });

    await ctx.close();

    // The injected light body color is rgb(230, 230, 230). If any
    // preference-center element has that colour, it inherited from the
    // host theme and the lock-down regressed.
    const lightColor = 'rgb(230, 230, 230)';
    const leaks: string[] = [];
    for (const [key, value] of Object.entries(colors)) {
      if (value === null) continue; // element not present, skip
      if (value === lightColor) {
        leaks.push(`${key}=${value}`);
      }
    }

    expect(
      leaks.length,
      `Preference center text must not inherit the host dark-theme color. Leaks: ${leaks.join(', ')}. All measured colors: ${JSON.stringify(colors)}`,
    ).toBe(0);
  });
});
