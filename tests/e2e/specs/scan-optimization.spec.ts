/**
 * E2E tests for scan optimization PR (perf/scan-optimization).
 *
 * 7 tests covering:
 * 1. OCD auto-download on activation
 * 2. WooCommerce priority URLs in discover response
 * 3. Script inference uses site domain (not script host)
 * 4. Scanner debug mode toggle
 * 5. Debug log download endpoint
 * 6. Auto-categorize serialization (no parallel PUTs)
 * 7. Remove data on uninstall setting (default OFF)
 */
import { createServer, type Server } from 'node:http';
import { expect, test } from '../fixtures/wp-fixture';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

async function startServerScanFixture(): Promise<{ server: Server; url: string }> {
  const server = createServer((_req, res) => {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(`
      <!doctype html>
      <html lang="en">
        <head>
          <meta charset="utf-8" />
          <title>Scan Fixture</title>
          <script src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script>
        </head>
        <body>
          <h1>Scan fixture</h1>
        </body>
      </html>
    `);
  });

  await new Promise<void>((resolve, reject) => {
    server.listen(0, '127.0.0.1', () => resolve());
    server.once('error', reject);
  });

  const address = server.address();
  if (!address || typeof address === 'string') {
    await new Promise<void>((resolve, reject) => server.close((error) => (error ? reject(error) : resolve())));
    throw new Error('Failed to resolve fixture server address');
  }

  return {
    server,
    url: `http://127.0.0.1:${address.port}/`,
  };
}

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

test.describe('Scan optimization features', () => {

  test('T1: OCD definitions are available (auto-downloaded or pre-existing)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Check definitions metadata
    const result = await apiGet(page, nonce, 'cookies/definitions');
    expect(result.status).toBe(200);
    expect(result.data).toBeTruthy();
    // Should have definitions with a count > 0
    const count = result.data?.count ?? result.data?.total ?? 0;
    expect(count, 'OCD should have definitions loaded').toBeGreaterThan(0);
  });

  test('T2: discover endpoint returns priority_urls field', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const result = await apiPost(page, nonce, 'scans/discover', { max_pages: 5 });
    expect(result.status).toBe(200);

    // Response must include the new priority_urls field (backward compat)
    expect(result.data).toHaveProperty('urls');
    expect(result.data).toHaveProperty('priority_urls');
    expect(result.data).toHaveProperty('total');
    expect(Array.isArray(result.data.urls)).toBe(true);
    expect(Array.isArray(result.data.priority_urls)).toBe(true);

    // Total should be urls + unique priority_urls
    const totalExpected = result.data.urls.length + result.data.priority_urls.length;
    expect(result.data.total).toBe(totalExpected);
  });

  test('T3: script inference uses site domain in Cookie_Database lookup_scripts', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);
    const fixture = await startServerScanFixture();

    try {
      const result = await apiPost(page, nonce, 'scans/server-scan', {
        url: fixture.url,
      });
      expect(result.status).toBe(200);
      expect(Array.isArray(result.data.cookies)).toBe(true);

      const ga = result.data.cookies.find((r: any) => r.name === '_ga');
      expect(ga).toBeTruthy();
      expect(ga.category).toBe('analytics');
      expect(ga.description).toBeTruthy();
      expect(ga.domain).toBe(new URL(WP_BASE).hostname);
    } finally {
      await new Promise<void>((resolve, reject) => fixture.server.close((error) => (error ? reject(error) : resolve())));
    }
  });

  test('T4: scanner debug mode toggle persists via settings API', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const original = (await apiGet(page, nonce, 'settings')).data;
    const originalDebug = original?.scanner?.debug_mode ?? false;

    try {
      // Enable debug mode
      await apiPost(page, nonce, 'settings', {
        scanner: { ...(original.scanner ?? {}), debug_mode: true },
      });
      const updated = (await apiGet(page, nonce, 'settings')).data;
      expect(updated.scanner.debug_mode).toBe(true);

      // Disable debug mode
      await apiPost(page, nonce, 'settings', {
        scanner: { ...(original.scanner ?? {}), debug_mode: false },
      });
      const reverted = (await apiGet(page, nonce, 'settings')).data;
      expect(reverted.scanner.debug_mode).toBe(false);
    } finally {
      await apiPost(page, nonce, 'settings', {
        scanner: { ...(original.scanner ?? {}), debug_mode: originalDebug },
      });
    }
  });

  test('T5: debug-log endpoint returns log data when debug mode enabled', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const original = (await apiGet(page, nonce, 'settings')).data;

    try {
      // Enable debug mode
      await apiPost(page, nonce, 'settings', {
        scanner: { ...(original.scanner ?? {}), debug_mode: true },
      });

      // Get debug log
      const logResult = await apiGet(page, nonce, 'scans/debug-log');
      expect(logResult.status).toBe(200);
      expect(logResult.data).toHaveProperty('log');
      expect(logResult.data).toHaveProperty('enabled');
      expect(logResult.data.enabled).toBe(true);
      expect(typeof logResult.data.log).toBe('string');
    } finally {
      await apiPost(page, nonce, 'settings', {
        scanner: { ...(original.scanner ?? {}), debug_mode: original?.scanner?.debug_mode ?? false },
      });
    }
  });

  test('T6: auto-categorize scrape endpoint returns results for known cookies', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Scrape known cookie names — should return categories
    const result = await apiPost(page, nonce, 'cookies/scrape', {
      names: ['_ga', '_fbp', '_hjid', '_GRECAPTCHA', 'unknown_cookie_xyz'],
    });
    expect(result.status).toBe(200);
    expect(Array.isArray(result.data)).toBe(true);

    const resultMap = new Map(result.data.map((r: any) => [r.name, r]));

    // _ga should be found as analytics
    const ga = resultMap.get('_ga') as any;
    expect(ga?.found).toBeTruthy();
    expect(ga?.category).toBe('analytics');

    // _GRECAPTCHA should be found as necessary
    const recaptcha = resultMap.get('_GRECAPTCHA') as any;
    expect(recaptcha?.found).toBeTruthy();
    expect(recaptcha?.category).toBe('necessary');

    // unknown_cookie_xyz should NOT be found
    const unknown = resultMap.get('unknown_cookie_xyz') as any;
    expect(unknown?.found).toBeFalsy();
  });

  test('T7: remove_data_on_uninstall setting defaults to false and persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const settings = (await apiGet(page, nonce, 'settings')).data;

    // Default should be false (data preserved on uninstall)
    const removeData = settings?.general?.remove_data_on_uninstall ?? false;
    expect(removeData).toBe(false);

    // Toggle it on and verify persistence
    try {
      await apiPost(page, nonce, 'settings', {
        general: { remove_data_on_uninstall: true },
      });
      const updated = (await apiGet(page, nonce, 'settings')).data;
      expect(updated.general.remove_data_on_uninstall).toBe(true);
    } finally {
      // Always restore to false (safe default)
      await apiPost(page, nonce, 'settings', {
        general: { remove_data_on_uninstall: false },
      });
    }
  });
});
