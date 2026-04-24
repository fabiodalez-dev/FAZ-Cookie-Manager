import type { ChildProcessWithoutNullStreams } from 'node:child_process';
import { expect, test } from '../fixtures/wp-fixture';
import {
  deleteCookiesByNames,
  deleteCookiesByPrefix,
  fazApiGet,
  fazApiPost,
  findCategoryId,
  listCookies,
  openCookiesPage,
  openSettingsPage,
} from '../utils/faz-api';
import { startServerScanLab, stopServerScanLab } from '../utils/server-scan-lab';
import {
  deactivatePluginsExcept,
  disableLabFlags,
  enableWooLabScenario,
  ensureFixturePlugin,
  ensureScanLabPages,
  ensureWooCommerceLabData,
  resetScanState,
  setLabToken,
  touchPosts,
  wpEval,
} from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';
const SERVER_SCAN_PORT = Number(process.env.FAZ_SERVER_SCAN_LAB_PORT ?? 10080);
const SERVER_SCAN_BASE = `http://127.0.0.1:${SERVER_SCAN_PORT}`;
const SITE_HOST = new URL(WP_BASE).hostname;
const COMMON_KNOWN_COOKIE_NAMES = [
  '_ga',
  '_gid',
  '_gat',
  '_gcl_au',
  '_fbp',
  '_fbc',
  'fr',
  'datr',
  'sb',
  'YSC',
  'VISITOR_INFO1_LIVE',
  '__stripe_mid',
  '__stripe_sid',
];

type DiscoverResponse = {
  urls: string[];
  priority_urls: string[];
  total: number;
  fingerprint: string;
  incremental: boolean;
};

type ServerScanCookie = {
  name: string;
  domain?: string;
  category?: string;
  description?: string;
  duration?: string;
};

type ServerScanResponse = {
  cookies: ServerScanCookie[];
  scripts: string[];
};

type ImportResponse = {
  scan_id: number;
  total_cookies: number;
  pages_scanned: number;
  cookie_names: string[];
};

type CookieRow = {
  id?: number;
  cookie_id?: number;
  name: string;
  category: number;
  domain?: string;
  discovered?: boolean;
  description?: Record<string, string>;
  duration?: Record<string, string>;
};

function decodeUrl(url: string): string {
  try {
    return decodeURIComponent(url);
  } catch {
    return url;
  }
}

function makeToken(prefix: string): string {
  const random = Math.random().toString(36).slice(2, 8);
  return `${prefix}-${Date.now().toString(36)}-${random}`.toLowerCase();
}

function findCookie(cookies: CookieRow[], name: string): CookieRow | undefined {
  const lower = name.toLowerCase();
  return cookies.find((cookie) => String(cookie.name ?? '').toLowerCase() === lower);
}

function phpStringLiteral(value: string): string {
  return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function readOptionJson<T>(optionName: string): T {
  const raw = wpEval(`echo wp_json_encode( get_option( ${phpStringLiteral(optionName)}, array() ) );`);
  return raw ? (JSON.parse(raw) as T) : ([] as unknown as T);
}

function readWooUrls(): { cart: string; checkout: string; myaccount: string; product: string; shop: string } {
  const raw = wpEval(`
    $urls = array();
    foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $key ) {
      $id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $key ) : 0;
      $urls[ $key ] = $id > 0 ? get_permalink( $id ) : '';
    }
    $product = get_page_by_path( 'faz-lab-woo-product', OBJECT, 'product' );
    $urls['product'] = $product ? get_permalink( $product ) : '';
    echo wp_json_encode( $urls );
  `);

  return JSON.parse(raw) as { cart: string; checkout: string; myaccount: string; product: string; shop: string };
}

async function cleanupLabCookies(page: Parameters<typeof openCookiesPage>[0], nonce: string, extraNames: string[] = []): Promise<void> {
  await deleteCookiesByPrefix(page, nonce, '_faz_lab_');
  await deleteCookiesByNames(page, nonce, [...COMMON_KNOWN_COOKIE_NAMES, ...extraNames]);
}

async function discoverUrls(page: Parameters<typeof openCookiesPage>[0], nonce: string, maxPages: number, fingerprint = ''): Promise<DiscoverResponse> {
  const response = await fazApiPost<DiscoverResponse>(page, nonce, 'scans/discover', {
    max_pages: maxPages,
    fingerprint,
  });
  expect(response.status).toBe(200);
  return response.data;
}

async function serverScan(page: Parameters<typeof openCookiesPage>[0], nonce: string, scenario: string, token: string): Promise<ServerScanResponse> {
  const response = await fazApiPost<ServerScanResponse>(page, nonce, 'scans/server-scan', {
    url: `${SERVER_SCAN_BASE}/?scenario=${scenario}&token=${token}`,
  });
  expect(response.status).toBe(200);
  return response.data;
}

async function importScan(page: Parameters<typeof openCookiesPage>[0], nonce: string, payload: Record<string, unknown>): Promise<ImportResponse> {
  const response = await fazApiPost<ImportResponse>(page, nonce, 'scans/import', payload);
  expect(response.status).toBe(200);
  return response.data;
}

async function listCookiesWithRetry(page: Parameters<typeof openCookiesPage>[0], nonce: string, attempts = 3): Promise<CookieRow[]> {
  let lastError: unknown;
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    try {
      return (await listCookies(page, nonce)) as CookieRow[];
    } catch (error) {
      lastError = error;
      await page.waitForTimeout(1000 * (attempt + 1));
    }
  }
  throw lastError;
}

async function runQuickScan(page: Parameters<typeof openCookiesPage>[0], depth = 10): Promise<DiscoverResponse> {
  await page.evaluate(() => {
    try {
      localStorage.removeItem('faz_scan_fingerprint');
    } catch {
      // Ignore localStorage failures in restrictive browsers.
    }
  });

  const discoverPromise = page.waitForResponse(
    (response) => {
      if (response.status() !== 200) return false;
      const decoded = decodeUrl(response.url());
      // Pretty permalinks emit `/wp-json/faz/v1/scans/discover` while the
      // legacy plain permalink format emits `?rest_route=/faz/v1/scans/discover`.
      // Match either so the test is permalink-setup agnostic.
      return decoded.includes('rest_route=/faz/v1/scans/discover')
        || decoded.includes('/wp-json/faz/v1/scans/discover');
    },
  );

  await page.locator('#faz-scan-btn').click();
  await page.locator(`#faz-scan-dropdown .faz-dropdown-item[data-depth="${depth}"]`).click();

  const discoverResponse = await discoverPromise;
  const discoverData = (await discoverResponse.json()) as DiscoverResponse;

  await page.waitForFunction(() => !document.querySelector('.faz-scan-progress-wrap'), null, { timeout: 180_000 });
  await expect(page.locator('.faz-toast').last()).toContainText('Scan complete', { timeout: 20_000 });

  return discoverData;
}

async function installScrapeMock(page: Parameters<typeof openCookiesPage>[0], results: Array<Record<string, unknown>>): Promise<void> {
  await page.evaluate((mockResults) => {
    const win = window as any;
    if (!win.__fazOriginalPost) {
      win.__fazOriginalPost = window.FAZ.post;
    }
    window.FAZ.post = function (route: string, data: Record<string, unknown>) {
      if (route === 'cookies/scrape') {
        return Promise.resolve(mockResults);
      }
      return win.__fazOriginalPost(route, data);
    };
  }, results);
}

async function restoreScrapeMock(page: Parameters<typeof openCookiesPage>[0]): Promise<void> {
  await page.evaluate(() => {
    const win = window as any;
    if (win.__fazOriginalPost) {
      window.FAZ.post = win.__fazOriginalPost;
      delete win.__fazOriginalPost;
    }
  }).catch(() => {});
}

async function installPutTracker(page: Parameters<typeof openCookiesPage>[0], delayMs = 150): Promise<void> {
  await page.evaluate((delay) => {
    const win = window as any;
    if (!win.__fazOriginalPut) {
      win.__fazOriginalPut = window.FAZ.put;
    }
    win.__fazPutMetrics = { active: 0, count: 0, max: 0 };
    window.FAZ.put = function (route: string, data: Record<string, unknown>) {
      win.__fazPutMetrics.count += 1;
      win.__fazPutMetrics.active += 1;
      win.__fazPutMetrics.max = Math.max(win.__fazPutMetrics.max, win.__fazPutMetrics.active);

      return new Promise((resolve) => setTimeout(resolve, delay))
        .then(() => win.__fazOriginalPut(route, data))
        .finally(() => {
          win.__fazPutMetrics.active -= 1;
        });
    };
  }, delayMs);
}

async function readPutTracker(page: Parameters<typeof openCookiesPage>[0]): Promise<{ active: number; count: number; max: number }> {
  return page.evaluate(() => (window as any).__fazPutMetrics ?? { active: 0, count: 0, max: 0 });
}

async function restorePutTracker(page: Parameters<typeof openCookiesPage>[0]): Promise<void> {
  await page.evaluate(() => {
    const win = window as any;
    if (win.__fazOriginalPut) {
      window.FAZ.put = win.__fazOriginalPut;
      delete win.__fazOriginalPut;
    }
    delete win.__fazPutMetrics;
  }).catch(() => {});
}

async function createCookie(
  page: Parameters<typeof openCookiesPage>[0],
  nonce: string,
  data: Record<string, unknown>,
): Promise<number> {
  const response = await fazApiPost<any>(page, nonce, 'cookies', data);
  expect(response.status).toBe(200);
  return Number(response.data.id ?? response.data.cookie_id);
}

test.describe('Deep scan and catalog flows', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(300_000);

  let serverLab: ChildProcessWithoutNullStreams | null = null;

  test.beforeAll(async () => {
    deactivatePluginsExcept([
      'faz-cookie-manager',
      'faz-e2e-provider-matrix',
      'faz-e2e-scan-lab',
      'faz-e2e-woo-lab',
      'woocommerce',
    ]);
    ensureFixturePlugin('faz-e2e-scan-lab');
    ensureFixturePlugin('faz-e2e-woo-lab');
    ensureScanLabPages();
    disableLabFlags();
    resetScanState();
    serverLab = await startServerScanLab(SERVER_SCAN_PORT);
  });

  test.beforeEach(async () => {
    disableLabFlags();
    resetScanState();
    setLabToken('baseline');
  });

  test.afterAll(async () => {
    disableLabFlags();
    resetScanState();
    await stopServerScanLab(serverLab);
    wpEval(`
      global $wpdb;
      $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}faz_cookies WHERE name LIKE %s", '_faz_lab_%' ) );
      \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie_Controller::get_instance()->delete_cache();
      \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance()->delete_cache();
    `);
  });

  test('01. discover total matches the unique URL set returned by the API', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const result = await discoverUrls(page, nonce, 10);
    const uniqueCount = new Set([...(result.urls || []), ...(result.priority_urls || [])]).size;

    expect(result.total).toBe(uniqueCount);
    expect(result.fingerprint).toBeTruthy();
    expect(result.urls.length).toBeGreaterThan(0);
  });

  test('02. discover fingerprint is stable across repeated requests when content does not change', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);

    const first = await discoverUrls(page, nonce, 10);
    const second = await discoverUrls(page, nonce, 10);

    expect(first.fingerprint).toBeTruthy();
    expect(second.fingerprint).toBe(first.fingerprint);
    expect(second.incremental).toBe(false);
  });

  test('03. discover becomes incremental when the client sends the current fingerprint back', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);

    const first = await discoverUrls(page, nonce, 10);
    const second = await discoverUrls(page, nonce, 10, first.fingerprint);

    expect(second.incremental).toBe(true);
    expect(second.fingerprint).toBe(first.fingerprint);
    expect(second.urls.length).toBeGreaterThan(0);
    expect(second.urls[0]).toBe(`${WP_BASE}/`);
  });

  test('04. discover invalidates the fingerprint when published content changes', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);

    const first = await discoverUrls(page, nonce, 10);
    touchPosts('page', ['faz-lab-js-basic']);
    const second = await discoverUrls(page, nonce, 10, first.fingerprint);

    expect(second.incremental).toBe(false);
    expect(second.fingerprint).not.toBe(first.fingerprint);
  });

  test('05. browser scan imports a unique JavaScript cookie from the scan lab page', async ({ page, loginAsAdmin }) => {
    const token = makeToken('js-basic');
    setLabToken(token);
    touchPosts('page', ['faz-lab-js-basic']);

    const nonce = await openCookiesPage(page, loginAsAdmin);
    await cleanupLabCookies(page, nonce);
    await runQuickScan(page, 100);

    const cookies = await listCookiesWithRetry(page, nonce);
    const cookie = findCookie(cookies, `_faz_lab_js_basic_${token}`);

    expect(cookie).toBeTruthy();
    expect(cookie?.discovered).toBe(true);
  });

  test('06. browser scan waits long enough to import delayed JavaScript cookies', async ({ page, loginAsAdmin }) => {
    const token = makeToken('js-delayed');
    setLabToken(token);
    touchPosts('page', ['faz-lab-js-delayed']);

    const nonce = await openCookiesPage(page, loginAsAdmin);
    await cleanupLabCookies(page, nonce);
    await runQuickScan(page, 100);

    const cookies = await listCookiesWithRetry(page, nonce);
    expect(findCookie(cookies, `_faz_lab_js_delayed_${token}`)).toBeTruthy();
  });

  test('07. browser scan deduplicates the same cookie seen on multiple pages', async ({ page, loginAsAdmin }) => {
    const token = makeToken('js-dupe');
    setLabToken(token);
    touchPosts('page', ['faz-lab-js-dupe-a', 'faz-lab-js-dupe-b']);

    const nonce = await openCookiesPage(page, loginAsAdmin);
    await cleanupLabCookies(page, nonce);
    await runQuickScan(page, 100);

    const cookies = await listCookiesWithRetry(page, nonce);
    const matches = cookies.filter((cookie) => cookie.name === `_faz_lab_dupe_${token}`);

    expect(matches).toHaveLength(1);
  });

  test('08. server-side fallback parses Set-Cookie headers', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('header');
    const sanitizedToken = token.replace(/[^a-z0-9_]/gi, '');

    const result = await serverScan(page, nonce, 'headers', token);
    const cookie = result.cookies.find((item) => item.name === `_faz_lab_http_${sanitizedToken}`);

    expect(cookie).toBeTruthy();
    expect(result.scripts).toHaveLength(0);
  });

  test('09. server-side fallback extracts script src URLs and infers GTM cookies', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('src-ga');

    const result = await serverScan(page, nonce, 'src-ga', token);

    expect(result.scripts).toContain('https://www.googletagmanager.com/gtag/js?id=G-LAB');
    expect(result.cookies.map((cookie) => cookie.name)).toEqual(expect.arrayContaining(['_ga', '_gid', '_gat', '_gcl_au']));
  });

  test('10. server-side fallback extracts deferred data-src scripts', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('data-src');

    const result = await serverScan(page, nonce, 'data-src-ga', token);

    expect(result.scripts).toContain('https://www.googletagmanager.com/gtag/js?id=G-LAB');
    expect(result.cookies.map((cookie) => cookie.name)).toEqual(expect.arrayContaining(['_ga', '_gid', '_gat', '_gcl_au']));
  });

  test('11. server-side fallback extracts LiteSpeed-deferred scripts', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('litespeed');

    const result = await serverScan(page, nonce, 'litespeed-fb', token);

    expect(result.scripts).toContain('https://connect.facebook.net/en_US/fbevents.js');
    expect(result.cookies.map((cookie) => cookie.name)).toEqual(expect.arrayContaining(['_fbp', '_fbc', 'fr']));
  });

  test('12. server-side fallback extracts iframe src URLs and infers embed cookies', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('iframe');

    const result = await serverScan(page, nonce, 'iframe-youtube', token);

    expect(result.scripts).toContain('https://www.youtube.com/embed/dQw4w9WgXcQ');
    expect(result.cookies.map((cookie) => cookie.name)).toEqual(expect.arrayContaining(['YSC', 'VISITOR_INFO1_LIVE']));
  });

  test('13. inferred server-scan cookies use the site host rather than the third-party script host', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('domain');

    const result = await serverScan(page, nonce, 'src-ga', token);
    const inferred = result.cookies.find((cookie) => cookie.name === '_ga');

    expect(inferred).toBeTruthy();
    expect(inferred?.domain).toBe(SITE_HOST);
  });

  test('14. import deduplicates duplicate cookie names in a single scan payload', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('import-dupe');
    const cookieName = `_faz_lab_import_dupe_${token}`;

    await cleanupLabCookies(page, nonce);
    const result = await importScan(page, nonce, {
      cookies: [
        { name: cookieName, domain: SITE_HOST, category: 'uncategorized', source: 'browser' },
        { name: cookieName, domain: SITE_HOST, category: 'uncategorized', source: 'browser' },
      ],
      pages_scanned: 2,
      scripts: [],
      metrics: { pagesScanned: 2, cookiesFound: 2 },
    });

    const cookies = await listCookiesWithRetry(page, nonce);
    const matches = cookies.filter((cookie) => cookie.name === cookieName);

    expect(result.total_cookies).toBe(1);
    expect(matches).toHaveLength(1);
  });

  test('15. import keeps unknown cookies uncategorized by default', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('unknown');
    const name = `_faz_lab_unknown_${token}`;
    const uncategorizedId = await findCategoryId(page, nonce, 'uncategorized');

    await cleanupLabCookies(page, nonce);
    await importScan(page, nonce, {
      cookies: [{ name, domain: SITE_HOST, category: 'uncategorized', source: 'browser' }],
      pages_scanned: 1,
      scripts: [],
    });

    const cookies = await listCookiesWithRetry(page, nonce);
    const created = findCookie(cookies, name);

    expect(created).toBeTruthy();
    expect(created?.category).toBe(uncategorizedId);
  });

  test('16. import matches wildcard known-cookie patterns such as _ga_<property>', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('ga4');
    const name = `_ga_${token.replace(/-/g, '')}`;
    const analyticsId = await findCategoryId(page, nonce, 'analytics');

    await cleanupLabCookies(page, nonce);
    await importScan(page, nonce, {
      cookies: [{ name, domain: SITE_HOST, category: 'uncategorized', source: 'browser' }],
      pages_scanned: 1,
      scripts: [],
    });

    const cookies = await listCookiesWithRetry(page, nonce);
    const created = findCookie(cookies, name);

    expect(created).toBeTruthy();
    expect(created?.category).toBe(analyticsId);
    expect(created?.duration?.en ?? Object.values(created?.duration ?? {})[0]).toContain('2 year');
  });

  test('17. import enriches exact known cookies such as _fbp with category, description, and duration', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const marketingId = await findCategoryId(page, nonce, 'marketing');

    await cleanupLabCookies(page, nonce);
    await importScan(page, nonce, {
      cookies: [{ name: '_fbp', domain: SITE_HOST, category: 'uncategorized', description: '', duration: 'session', source: 'browser' }],
      pages_scanned: 1,
      scripts: [],
    });

    const cookies = await listCookiesWithRetry(page, nonce);
    const created = findCookie(cookies, '_fbp');

    expect(created).toBeTruthy();
    expect(created?.category).toBe(marketingId);
    expect(created?.description?.en ?? Object.values(created?.description ?? {})[0]).toMatch(/facebook pixel/i);
    expect(created?.duration?.en ?? Object.values(created?.duration ?? {})[0]).toMatch(/month|year/i);
  });

  test('18. import merges Cookie_Database script inference for GTM scripts', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);

    await cleanupLabCookies(page, nonce);
    const result = await importScan(page, nonce, {
      cookies: [],
      pages_scanned: 1,
      scripts: ['https://www.googletagmanager.com/gtag/js?id=G-FAZLAB'],
      metrics: { pagesScanned: 1, scriptsFound: 1 },
    });

    const cookies = await listCookiesWithRetry(page, nonce);
    const inferredNames = ['_ga', '_gid', '_gat', '_gcl_au'];

    expect(result.cookie_names).toEqual(expect.arrayContaining(inferredNames));
    for (const name of inferredNames) {
      expect(findCookie(cookies, name)).toBeTruthy();
    }
  });

  test('19. import merges Known Providers inference beyond the Cookie_Database defaults', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);

    await cleanupLabCookies(page, nonce);
    const result = await importScan(page, nonce, {
      cookies: [],
      pages_scanned: 1,
      scripts: ['https://connect.facebook.net/en_US/fbevents.js'],
      metrics: { pagesScanned: 1, scriptsFound: 1 },
    });

    const cookies = await listCookiesWithRetry(page, nonce);
    const providerOnlyNames = ['datr', 'sb'];

    expect(result.cookie_names).toEqual(expect.arrayContaining(providerOnlyNames));
    for (const name of providerOnlyNames) {
      expect(findCookie(cookies, name)).toBeTruthy();
    }
  });

  test('20. import persists scan history metrics and pages_scanned in WordPress options', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('metrics');

    await cleanupLabCookies(page, nonce);
    const result = await importScan(page, nonce, {
      cookies: [{ name: `_faz_lab_metrics_${token}`, domain: SITE_HOST, category: 'uncategorized', source: 'browser' }],
      pages_scanned: 4,
      scripts: ['https://www.googletagmanager.com/gtag/js?id=G-FAZMETRICS'],
      metrics: {
        discoverMs: 15,
        scanMs: 1200,
        importMs: 250,
        urlsDiscovered: 4,
        cookiesFound: 1,
        scriptsFound: 1,
        earlyStopReason: 'none',
        pagesScanned: 4,
        incremental: false,
      },
    });

    const history = readOptionJson<any[]>('faz_scan_history');
    const latest = history[history.length - 1];
    const info = await fazApiGet<any>(page, nonce, 'scans/info');

    expect(result.pages_scanned).toBe(4);
    expect(latest.pages_scanned).toBe(4);
    expect(latest.metrics.pagesScanned).toBe(4);
    expect(latest.metrics.scanMs).toBe(1200);
    expect(info.status).toBe(200);
    expect(info.data.pages_scanned).toBe(4);
  });

  test('21. auto-categorize with uncategorized-only skips cookies that already have a category', async ({ page, loginAsAdmin }) => {
    const settingsNonce = await openSettingsPage(page, loginAsAdmin);
    const originalSettings = (await fazApiGet<any>(page, settingsNonce, 'settings')).data;

    try {
      await fazApiPost(page, settingsNonce, 'settings', {
        languages: { selected: ['en'], default: 'en' },
      });

      const nonce = await openCookiesPage(page, loginAsAdmin);
      await cleanupLabCookies(page, nonce);

      const uncategorizedId = await findCategoryId(page, nonce, 'uncategorized');
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      const necessaryId = await findCategoryId(page, nonce, 'necessary');

      const token = makeToken('autocat-uncat');
      const uncatName = `_faz_lab_autocat_uncat_${token}`;
      const fixedName = `_faz_lab_autocat_fixed_${token}`;

      const uncatId = await createCookie(page, nonce, { name: uncatName, category: uncategorizedId, description: {}, duration: {}, domain: SITE_HOST });
      const fixedId = await createCookie(page, nonce, { name: fixedName, category: necessaryId, description: {}, duration: {}, domain: SITE_HOST });

      await installScrapeMock(page, [
        { name: uncatName, found: true, category: 'analytics', description: 'Auto analytics' },
        { name: fixedName, found: true, category: 'analytics', description: 'Should not change' },
      ]);

      await page.locator('#faz-auto-cat-btn').click();
      await page.locator('#faz-auto-cat-dropdown .faz-dropdown-item[data-scope="uncategorized"]').click();
      await expect(page.locator('.faz-toast').last()).toContainText('Auto-categorized 1/1 cookies');

      const updatedUncat = await fazApiGet<any>(page, nonce, `cookies/${uncatId}`);
      const updatedFixed = await fazApiGet<any>(page, nonce, `cookies/${fixedId}`);

      expect(updatedUncat.data.category).toBe(analyticsId);
      expect(updatedFixed.data.category).toBe(necessaryId);
    } finally {
      await restoreScrapeMock(page);
      await fazApiPost(page, settingsNonce, 'settings', { languages: originalSettings.languages });
    }
  });

  test('22. auto-categorize with scope=all updates every matching cookie', async ({ page, loginAsAdmin }) => {
    const settingsNonce = await openSettingsPage(page, loginAsAdmin);
    const originalSettings = (await fazApiGet<any>(page, settingsNonce, 'settings')).data;

    try {
      await fazApiPost(page, settingsNonce, 'settings', {
        languages: { selected: ['en'], default: 'en' },
      });

      const nonce = await openCookiesPage(page, loginAsAdmin);
      await cleanupLabCookies(page, nonce);

      const uncategorizedId = await findCategoryId(page, nonce, 'uncategorized');
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      const marketingId = await findCategoryId(page, nonce, 'marketing');
      const necessaryId = await findCategoryId(page, nonce, 'necessary');

      const token = makeToken('autocat-all');
      const firstName = `_faz_lab_autocat_all_a_${token}`;
      const secondName = `_faz_lab_autocat_all_b_${token}`;

      const firstId = await createCookie(page, nonce, { name: firstName, category: uncategorizedId, description: {}, duration: {}, domain: SITE_HOST });
      const secondId = await createCookie(page, nonce, { name: secondName, category: necessaryId, description: {}, duration: {}, domain: SITE_HOST });

      await installScrapeMock(page, [
        { name: firstName, found: true, category: 'analytics', description: 'Analytics desc' },
        { name: secondName, found: true, category: 'marketing', description: 'Marketing desc' },
      ]);

      await page.locator('#faz-auto-cat-btn').click();
      await page.locator('#faz-auto-cat-dropdown .faz-dropdown-item[data-scope="all"]').click();
      await expect(page.locator('.faz-toast').last()).toContainText('Auto-categorized 2/2 cookies');

      const first = await fazApiGet<any>(page, nonce, `cookies/${firstId}`);
      const second = await fazApiGet<any>(page, nonce, `cookies/${secondId}`);

      expect(first.data.category).toBe(analyticsId);
      expect(second.data.category).toBe(marketingId);
    } finally {
      await restoreScrapeMock(page);
      await fazApiPost(page, settingsNonce, 'settings', { languages: originalSettings.languages });
    }
  });

  test('23. auto-categorize stores the scraped description under the default language and preserves existing translations', async ({ page, loginAsAdmin }) => {
    const settingsNonce = await openSettingsPage(page, loginAsAdmin);
    const originalSettings = (await fazApiGet<any>(page, settingsNonce, 'settings')).data;

    try {
      await fazApiPost(page, settingsNonce, 'settings', {
        languages: { selected: ['en', 'it'], default: 'it' },
      });

      const nonce = await openCookiesPage(page, loginAsAdmin);
      await cleanupLabCookies(page, nonce);

      const uncategorizedId = await findCategoryId(page, nonce, 'uncategorized');
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      const token = makeToken('autocat-lang');
      const name = `_faz_lab_autocat_lang_${token}`;
      const cookieId = await createCookie(page, nonce, {
        name,
        category: uncategorizedId,
        description: { de: 'Bestehende Beschreibung' },
        duration: {},
        domain: SITE_HOST,
      });

      await installScrapeMock(page, [
        { name, found: true, category: 'analytics', description: 'Descrizione scanner' },
      ]);

      await page.locator('#faz-auto-cat-btn').click();
      await page.locator('#faz-auto-cat-dropdown .faz-dropdown-item[data-scope="all"]').click();
      await expect(page.locator('.faz-toast').last()).toContainText('Auto-categorized 1/1 cookies');

      const updated = await fazApiGet<any>(page, nonce, `cookies/${cookieId}`);

      expect(updated.data.category).toBe(analyticsId);
      expect(updated.data.description).toHaveProperty('it', 'Descrizione scanner');
      expect(updated.data.description).toHaveProperty('de', 'Bestehende Beschreibung');
    } finally {
      await restoreScrapeMock(page);
      await fazApiPost(page, settingsNonce, 'settings', { languages: originalSettings.languages });
    }
  });

  test('24. auto-categorize performs cookie updates sequentially instead of issuing parallel PUT requests', async ({ page, loginAsAdmin }) => {
    const settingsNonce = await openSettingsPage(page, loginAsAdmin);
    const originalSettings = (await fazApiGet<any>(page, settingsNonce, 'settings')).data;

    try {
      await fazApiPost(page, settingsNonce, 'settings', {
        languages: { selected: ['en'], default: 'en' },
      });

      const nonce = await openCookiesPage(page, loginAsAdmin);
      await cleanupLabCookies(page, nonce);

      const uncategorizedId = await findCategoryId(page, nonce, 'uncategorized');
      const token = makeToken('autocat-serial');
      const names = [
        `_faz_lab_autocat_serial_a_${token}`,
        `_faz_lab_autocat_serial_b_${token}`,
        `_faz_lab_autocat_serial_c_${token}`,
      ];

      for (const name of names) {
        await createCookie(page, nonce, { name, category: uncategorizedId, description: {}, duration: {}, domain: SITE_HOST });
      }

      await installScrapeMock(page, names.map((name) => ({
        name,
        found: true,
        category: 'analytics',
        description: `Description for ${name}`,
      })));

      await installPutTracker(page, 150);

      await page.locator('#faz-auto-cat-btn').click();
      await page.locator('#faz-auto-cat-dropdown .faz-dropdown-item[data-scope="all"]').click();
      await expect(page.locator('.faz-toast').last()).toContainText('Auto-categorized 3/3 cookies');

      const metrics = await readPutTracker(page);

      expect(metrics.count).toBe(3);
      expect(metrics.max).toBe(1);
    } finally {
      await restorePutTracker(page);
      await restoreScrapeMock(page);
      await fazApiPost(page, settingsNonce, 'settings', { languages: originalSettings.languages });
    }
  });

  test('25. WooCommerce priority discovery surfaces checkout/account pages and the scan captures Woo-specific cookies', async ({ page, loginAsAdmin }) => {
    test.slow();
    ensureWooCommerceLabData();
    enableWooLabScenario();
    const wooUrls = readWooUrls();

    const token = makeToken('woo');
    setLabToken(token);
    touchPosts('page', ['shop', 'cart', 'checkout', 'my-account']);
    touchPosts('product', ['faz-lab-woo-product']);

    const nonce = await openCookiesPage(page, loginAsAdmin);
    await cleanupLabCookies(page, nonce);

    const discover = await discoverUrls(page, nonce, 100);
    const combinedUrls = [...discover.urls, ...discover.priority_urls];

    expect(combinedUrls).toEqual(expect.arrayContaining([
      wooUrls.shop,
      wooUrls.cart,
      wooUrls.checkout,
      wooUrls.myaccount,
      wooUrls.product,
    ]));

    await runQuickScan(page, 100);

    const cookies = await listCookiesWithRetry(page, nonce);
    const names = cookies.map((cookie) => cookie.name);

    expect(names).toEqual(expect.arrayContaining([
      `_faz_lab_wc_shop_${token}`,
      `_faz_lab_wc_product_${token}`,
      `_faz_lab_wc_cart_${token}`,
      `_faz_lab_wc_account_${token}`,
      '_GRECAPTCHA',
    ]));
  });
});
