import { randomBytes } from 'node:crypto';
import type { BrowserContext } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { clickFirstVisible } from '../utils/ui';
import {
  deleteCookiesByNames,
  fazApiGet,
  fazApiPost,
  findCategoryId,
  listCookies,
  openCookiesPage,
  openSettingsPage,
} from '../utils/faz-api';
import {
  deactivatePluginsExcept,
  enableProviderMatrixCustomScenario,
  enableProviderMatrixWooScenario,
  ensureFixturePlugin,
  ensureProviderMatrixPage,
  ensureWooCommerceLabData,
  listActivePluginFiles,
  readProviderMatrixHits,
  readProviderMatrixUrl,
  readWooUrls,
  restoreActivePluginFiles,
  resetProviderMatrixState,
  wpEval,
} from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const IS_PHP_BUILT_IN_E2E = (process.env.FAZ_E2E_SERVER ?? 'php-built-in').toLowerCase() === 'php-built-in';

/**
 * Does any captured script URL come from this host?
 *
 * A substring test is what CodeQL flags here, and it is right to: `.includes(
 * 'doubleclick.net')` also matches `https://example.com/?ref=doubleclick.net`,
 * so the assertion could pass on a URL that has nothing to do with the provider
 * and the test would still look green. Parsing the host and matching it (or a
 * subdomain of it) is what the assertion actually means.
 *
 * Non-URL values are skipped rather than throwing: the scan legitimately reports
 * script handles and relative paths alongside absolute URLs.
 */
function hasScriptFromHost(scripts: string[], host: string, pathContains?: string): boolean {
  return scripts.some((value) => {
    let parsed: URL;
    try {
      parsed = new URL(value, WP_BASE);
    } catch {
      return false;
    }
    const matchesHost = parsed.hostname === host || parsed.hostname.endsWith(`.${host}`);
    if (!matchesHost) {
      return false;
    }
    return pathContains === undefined || parsed.pathname.includes(pathContains);
  });
}

type SettingsPayload = Record<string, any>;
type ScanCookie = { name: string; domain?: string; category?: string };
type ScanSignalPayload = {
  cookies: Array<{ name: string; domain: string; source: 'browser' }>;
  scripts: string[];
};

const MATRIX_COOKIE_NAMES = [
  '_ga',
  '_gid',
  '_fbp',
  '_fbc',
  '_uetsid',
  '_uetvid',
  'MUID',
  '_clck',
  '_clsk',
  '_hjSessionUser_123',
  '_hjSession_123',
  'li_sugr',
  'bcookie',
  'lidc',
  '__stripe_mid',
  '__stripe_sid',
  'distinct_id',
  'hubspotutk',
  '__hssc',
  '__hssrc',
  '__hstc',
  'guest_id',
  'personalization_id',
  '_ttp',
  'tt_webid',
  '_pin_unauth',
  '_scid',
  'sc_at',
  '_faz_custom_provider',
];

const REPRESENTATIVE_CATEGORIES = [
  { category: 'analytics', name: '_ga' },
  { category: 'analytics', name: '_clck' },
  { category: 'marketing', name: '_fbp' },
  { category: 'marketing', name: 'hubspotutk' },
  { category: 'functional', name: '__stripe_mid' },
];

function addQuery(url: string, key: string, value: string): string {
  const next = new URL(url);
  next.searchParams.set(key, value);
  return next.toString();
}

function uniqueStrings(values: string[]): string[] {
  return Array.from(new Set(values));
}

async function getSettings(page: Parameters<typeof openSettingsPage>[0], nonce: string): Promise<SettingsPayload> {
  const response = await fazApiGet<SettingsPayload>(page, nonce, 'settings');
  expect(response.status).toBe(200);
  return response.data;
}

async function postSettings(page: Parameters<typeof openSettingsPage>[0], nonce: string, payload: SettingsPayload): Promise<void> {
  const response = await fazApiPost<SettingsPayload>(page, nonce, 'settings', payload);
  expect(response.status).toBe(200);
}

async function collectMatrixSignals(
  page: Parameters<typeof openCookiesPage>[0],
  matrixUrl: string,
  scanId: string,
): Promise<ScanSignalPayload> {
  const scanUrl = addQuery(addQuery(matrixUrl, 'faz_scanning', '1'), 'faz_scan_id', scanId);
  await page.goto(scanUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(
    () =>
      document.cookie.includes('_ga=') &&
      document.cookie.includes('_fbp=') &&
      document.cookie.includes('__stripe_mid='),
    null,
    { timeout: 20_000 },
  );

  return page.evaluate(() => {
    const scripts = Array.from(
      document.querySelectorAll('script[src], script[data-src], script[data-litespeed-src], iframe[src], iframe[data-src]'),
    )
      .map((node) => node.getAttribute('src') || node.getAttribute('data-src') || node.getAttribute('data-litespeed-src') || '')
      .filter((value): value is string => Boolean(value));

    const hostname = window.location.hostname;
    const cookies = document.cookie
      .split(';')
      .map((chunk) => chunk.trim())
      .filter(Boolean)
      .map((chunk) => chunk.split('=')[0]?.trim())
      .filter((name): name is string => Boolean(name))
      .map((name) => ({ name, domain: hostname, source: 'browser' as const }));

    return {
      cookies,
      scripts,
    };
  });
}

async function startBrowserScanSession(
  page: Parameters<typeof openCookiesPage>[0],
  nonce: string,
): Promise<string> {
  const scanId = randomBytes(16).toString('hex');
  const discover = await fazApiPost<any>(page, nonce, 'scans/discover', {
    max_pages: 1,
    fingerprint: '',
    scan_id: scanId,
  });
  expect(discover.status, `scans/discover refused provider-matrix session: ${JSON.stringify(discover.data)}`).toBe(200);
  return scanId;
}

async function abortBrowserScanSession(
  page: Parameters<typeof openCookiesPage>[0],
  nonce: string,
  scanId: string,
): Promise<void> {
  try {
    await fazApiPost(page, nonce, 'scans/abort', { scan_id: scanId });
  } catch {
    // Best-effort cleanup for a failed assertion/network request. A successful
    // import already closed the session, so abort is intentionally idempotent.
  }
}

async function waitForCookie(page: Parameters<typeof openCookiesPage>[0], name: string): Promise<void> {
  await page.waitForFunction(
    (cookieName) => document.cookie.split(';').some((chunk) => chunk.trim().startsWith(`${cookieName}=`)),
    name,
    { timeout: 20_000 },
  );
}

async function browserCookieNames(page: Parameters<typeof openCookiesPage>[0]): Promise<string[]> {
  return page.evaluate(() =>
    document.cookie
      .split(';')
      .map((chunk) => chunk.trim())
      .filter(Boolean)
      .map((chunk) => chunk.split('=')[0]?.trim())
      .filter((name): name is string => Boolean(name)),
  );
}

async function blockedMatrixScriptCount(page: Parameters<typeof openCookiesPage>[0]): Promise<number> {
  return page.locator('script[type="text/plain"][data-faz-category]').count();
}

async function gotoFrontend(page: Parameters<typeof openCookiesPage>[0], url: string): Promise<void> {
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.locator('body').waitFor({ state: 'visible' });
}

async function acceptAll(page: Parameters<typeof openCookiesPage>[0]): Promise<void> {
  const accepted = await clickFirstVisible(page, [
    '[data-faz-tag="accept-button"] button',
    '[data-faz-tag="accept-button"]',
    '.faz-btn-accept',
  ]);
  expect(accepted).toBeTruthy();
}

async function rejectAll(page: Parameters<typeof openCookiesPage>[0]): Promise<void> {
  const rejected = await clickFirstVisible(page, [
    '[data-faz-tag="reject-button"] button',
    '[data-faz-tag="reject-button"]',
    '.faz-btn-reject',
    '[data-faz-tag="close-button"]',
  ]);
  expect(rejected).toBeTruthy();
}

async function setConsentCookie(context: BrowserContext, url: string, values: Record<string, string>): Promise<void> {
  const value = Object.entries(values)
    .map(([key, entry]) => `${key}:${entry}`)
    .join(',');

  await context.addCookies([
    {
      name: 'fazcookie-consent',
      sameSite: 'Lax',
      url,
      value,
    },
  ]);
}

function directCollectUrl(path: string): string {
  return `${WP_BASE}/faz-e2e-provider-collect/${path}`;
}

async function runDirectFetch(page: Parameters<typeof openCookiesPage>[0], url: string): Promise<void> {
  await page.evaluate(async (targetUrl) => {
    await fetch(targetUrl, { credentials: 'same-origin', method: 'POST' });
  }, url);
}

async function runDirectXhr(page: Parameters<typeof openCookiesPage>[0], url: string): Promise<void> {
  await page.evaluate(
    async (targetUrl) =>
      new Promise<void>((resolve) => {
        const xhr = new XMLHttpRequest();
        let settled = false;
        const done = () => {
          if (settled) {
            return;
          }
          settled = true;
          resolve();
        };

        xhr.addEventListener('load', done);
        xhr.addEventListener('loadend', done);
        xhr.addEventListener('error', done);
        xhr.addEventListener('abort', done);
        xhr.onreadystatechange = () => {
          if (xhr.readyState === 4) {
            done();
          }
        };
        xhr.open('POST', targetUrl, true);
        xhr.send('1');
        window.setTimeout(done, 1500);
      }),
    url,
  );
}

async function runDirectBeacon(page: Parameters<typeof openCookiesPage>[0], url: string): Promise<boolean> {
  return page.evaluate((targetUrl) => navigator.sendBeacon(targetUrl, '1'), url);
}

test.describe('Provider matrix scan and blocking', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(300_000);

  let matrixUrl = '';
  let initialActivePluginFiles: string[] = [];

  test.beforeAll(async () => {
    initialActivePluginFiles = listActivePluginFiles();
    deactivatePluginsExcept([
      'faz-cookie-manager',
      'faz-e2e-provider-matrix',
      'faz-e2e-scan-lab',
      'faz-e2e-woo-lab',
      'woocommerce',
    ]);
    ensureFixturePlugin('faz-e2e-provider-matrix');
    ensureProviderMatrixPage();
    matrixUrl = readProviderMatrixUrl();
    if (!matrixUrl) {
      throw new Error('Provider matrix page URL could not be resolved.');
    }
  });

  test.afterAll(async () => {
    restoreActivePluginFiles(initialActivePluginFiles);
  });

  test.beforeEach(async () => {
    resetProviderMatrixState();
  });

  test('01. browser-import categorizes representative cookies from the provider matrix page', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    await deleteCookiesByNames(page, nonce, MATRIX_COOKIE_NAMES);

    const scanId = await startBrowserScanSession(page, nonce);
    try {
      const signals = await collectMatrixSignals(page, matrixUrl, scanId);
      const result = await fazApiPost<any>(page, nonce, 'scans/import', {
        scan_id: scanId,
        cookies: signals.cookies,
        metrics: { source: 'provider-matrix' },
        pages_scanned: 1,
        scripts: signals.scripts,
      });

      expect(result.status).toBe(200);
      expect(result.data.total_cookies).toBeGreaterThanOrEqual(12);

      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      const marketingId = await findCategoryId(page, nonce, 'marketing');
      const functionalId = await findCategoryId(page, nonce, 'functional');
      const cookies = await listCookies(page, nonce);

      for (const expected of REPRESENTATIVE_CATEGORIES) {
        const row = cookies.find((entry: any) => String(entry.name ?? '').toLowerCase() === expected.name.toLowerCase());
        expect(row, `Missing cookie ${expected.name}`).toBeTruthy();

        const expectedCategory =
          expected.category === 'analytics'
            ? analyticsId
            : expected.category === 'marketing'
              ? marketingId
              : functionalId;

        expect(Number(row.category)).toBe(expectedCategory);
      }
    } finally {
      await abortBrowserScanSession(page, nonce, scanId);
      await deleteCookiesByNames(page, nonce, MATRIX_COOKIE_NAMES);
    }
  });

  test('02. browser-import collects plugin-specific signatures and deduplicates overlapping cookie names', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    await deleteCookiesByNames(page, nonce, MATRIX_COOKIE_NAMES);

    const scanId = await startBrowserScanSession(page, nonce);
    try {
      const signals = await collectMatrixSignals(page, matrixUrl, scanId);
      const uniqueScripts = uniqueStrings(signals.scripts);

      expect(uniqueScripts.some((value) => value.includes('monsterinsights-frontend-script'))).toBe(true);
      expect(uniqueScripts.some((value) => value.includes('facebook-for-woocommerce'))).toBe(true);
      expect(uniqueScripts.some((value) => value.includes('exactmetrics-frontend-script'))).toBe(true);
      expect(uniqueScripts.some((value) => value.includes('gtm4wp/container-code'))).toBe(true);
      expect(uniqueScripts.some((value) => value.includes('pixel-caffeine/build/frontend.js'))).toBe(true);

      const result = await fazApiPost<any>(page, nonce, 'scans/import', {
        scan_id: scanId,
        cookies: signals.cookies,
        metrics: { source: 'provider-matrix-signatures' },
        pages_scanned: 1,
        scripts: signals.scripts,
      });
      expect(result.status).toBe(200);

      const cookies = await listCookies(page, nonce);
      expect(cookies.filter((entry: any) => String(entry.name ?? '').toLowerCase() === '_ga').length).toBe(1);
      expect(cookies.filter((entry: any) => String(entry.name ?? '').toLowerCase() === '_fbp').length).toBe(1);
    } finally {
      await abortBrowserScanSession(page, nonce, scanId);
      await deleteCookiesByNames(page, nonce, MATRIX_COOKIE_NAMES);
    }
  });

  test('03. server-scan infers known cookies from the matrix provider URLs', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const response = await fazApiPost<{ cookies: ScanCookie[]; scripts: string[] }>(page, nonce, 'scans/server-scan', {
      // Exercise the same-origin/port SSRF boundary the product enforces. The
      // provider-matrix fixture page already contains live, deferred and iframe
      // signatures, so a random localhost port is both unnecessary and invalid.
      url: matrixUrl,
    });

    expect(response.status, JSON.stringify(response.data)).toBe(200);
    const names = response.data.cookies.map((cookie) => cookie.name);

    for (const name of ['_ga', '_fbp', '_uetsid', '_clck', 'li_sugr', '__stripe_mid', 'distinct_id', 'hubspotutk', 'guest_id', '_ttp', '_pin_unauth', '_scid']) {
      expect(names).toContain(name);
    }

    for (const cookie of response.data.cookies.filter((entry) => ['_ga', '_fbp', '__stripe_mid'].includes(entry.name))) {
      expect(cookie.domain).toBe(new URL(WP_BASE).hostname);
    }
  });

  test('04. server-scan also captures deferred and iframe-based provider signatures', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const response = await fazApiPost<{ cookies: ScanCookie[]; scripts: string[] }>(page, nonce, 'scans/server-scan', {
      url: matrixUrl,
    });

    expect(response.status, JSON.stringify(response.data)).toBe(200);
    const scripts = response.data.scripts;

    expect(hasScriptFromHost(scripts, 'doubleclick.net')).toBe(true);
    expect(hasScriptFromHost(scripts, 'youtube.com', '/embed')).toBe(true);
    expect(scripts.some((value) => value.includes('exactmetrics-frontend-script'))).toBe(true);
    expect(scripts.some((value) => value.includes('pixel-caffeine/build/frontend.js'))).toBe(true);
  });

  test('05. pre-consent the blocker stops matrix provider scripts and no matrix cookies are set', async ({ page }) => {
    test.skip(IS_PHP_BUILT_IN_E2E, 'Fixture page is_singular() is unreliable on the PHP built-in server.');
    await gotoFrontend(page, matrixUrl);
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    // The fixture page injects provider scripts; at least 1 must be blocked.
    // Stripe is always-whitelisted and custom fixtures depend on is_singular().
    expect(await blockedMatrixScriptCount(page)).toBeGreaterThanOrEqual(1);

    const cookieNames = await browserCookieNames(page);
    // __stripe_mid excluded: Stripe is always-allowed and may set cookies pre-consent.
    for (const cookieName of ['_ga', '_fbp', 'hubspotutk', '_ttp']) {
      expect(cookieNames).not.toContain(cookieName);
    }

    // Stripe is always-whitelisted (payment gateway) and may execute pre-consent.
    // Exclude it from the "no hits" assertion.
    const preConsentHits = readProviderMatrixHits();
    delete preConsentHits['stripe'];
    expect(preConsentHits).toEqual({});
  });

  test('06. accept all unblocks the matrix scripts and emits representative cookies and hits', async ({ page }) => {
    test.skip(IS_PHP_BUILT_IN_E2E, 'Fixture page is_singular() is unreliable on the PHP built-in server.');
    await page.context().clearCookies();
    await gotoFrontend(page, matrixUrl);
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 10_000 });
    await acceptAll(page);

    await waitForCookie(page, '_ga');
    await waitForCookie(page, '_fbp');
    await waitForCookie(page, '__stripe_mid');

    expect(await blockedMatrixScriptCount(page)).toBe(0);

    const hits = readProviderMatrixHits();
    expect(hits['ga-monsterinsights']).toBeGreaterThanOrEqual(1);
    expect(hits['facebook-pixel']).toBeGreaterThanOrEqual(1);
    expect(hits['stripe']).toBeGreaterThanOrEqual(1);
  });

  test('07. reject all keeps the matrix scripts blocked and prevents cookie creation', async ({ page }) => {
    test.skip(IS_PHP_BUILT_IN_E2E, 'Fixture page is_singular() is unreliable on the PHP built-in server.');
    await gotoFrontend(page, matrixUrl);
    await rejectAll(page);
    await page.waitForTimeout(1000);

    // Soft check: at least one `text/plain` blocked script should still
    // be in the DOM after reject. An exact count is intentionally NOT
    // asserted because `_fazMutationObserver` physically `node.remove()`s
    // some <script src> nodes into the backup queue during init, so the
    // visible DOM count is timing-dependent and legitimately drops below
    // the server-rendered count without implying a blocking regression.
    // The real contract is carried by the two assertions below — no
    // matrix cookies set, no provider hits — which together prove that
    // nothing non-necessary executed.
    expect(await blockedMatrixScriptCount(page)).toBeGreaterThanOrEqual(1);

    const cookieNames = await browserCookieNames(page);
    for (const cookieName of ['_ga', '_fbp', 'hubspotutk']) {
      expect(cookieNames).not.toContain(cookieName);
    }

    // Stripe is always-whitelisted and may execute regardless of consent.
    const rejectHits = readProviderMatrixHits();
    delete rejectHits['stripe'];
    expect(rejectHits).toEqual({});
  });

  test('08. user whitelist patterns can allow one provider while the rest remain blocked', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);

    try {
      await postSettings(page, nonce, {
        script_blocking: {
          ...(original.script_blocking ?? {}),
          whitelist_patterns: ['connect.facebook.net'],
        },
      });

      const visitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const visitorPage = await visitor.newPage();
        await gotoFrontend(visitorPage, matrixUrl);

        await waitForCookie(visitorPage, '_fbp');
        const cookieNames = await browserCookieNames(visitorPage);
        expect(cookieNames).toContain('_fbp');
        expect(cookieNames).not.toContain('_ga');
      } finally {
        await visitor.close();
      }
    } finally {
      await postSettings(page, nonce, {
        script_blocking: original.script_blocking ?? {},
      });
    }
  });

  test('09. custom blocking rules can stop an unknown local provider signature', async ({ page, browser, loginAsAdmin }) => {
    resetProviderMatrixState({ clearFixtureCustomRules: true });
    enableProviderMatrixCustomScenario();
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);

    try {
      const baselineVisitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const baselinePage = await baselineVisitor.newPage();
        await gotoFrontend(baselinePage, matrixUrl);
        await waitForCookie(baselinePage, '_faz_custom_provider');
        expect(await browserCookieNames(baselinePage)).toContain('_faz_custom_provider');
      } finally {
        await baselineVisitor.close();
      }

      await postSettings(page, nonce, {
        script_blocking: {
          ...(original.script_blocking ?? {}),
          custom_rules: [{ category: 'analytics', pattern: 'faz-lab-custom-provider.js' }],
        },
      });

      const visitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        const visitorPage = await visitor.newPage();
        await gotoFrontend(visitorPage, matrixUrl);

        const cookieNames = await browserCookieNames(visitorPage);
        expect(cookieNames).not.toContain('_faz_custom_provider');
      } finally {
        await visitor.close();
      }
    } finally {
      await postSettings(page, nonce, {
        script_blocking: original.script_blocking ?? {},
      });
    }
  });

  test('10. fetch requests to provider-like endpoints are dropped before consent and allowed after accept', async ({ page }) => {
    await page.context().clearCookies();
    await gotoFrontend(page, matrixUrl);
    const target = directCollectUrl('googletagmanager.com/gtag/js');

    await runDirectFetch(page, target);
    await page.waitForTimeout(500);
    expect(readProviderMatrixHits()['googletagmanager.com/gtag/js'] ?? 0).toBe(0);

    await acceptAll(page);
    await runDirectFetch(page, target);
    await page.waitForTimeout(500);
    expect(readProviderMatrixHits()['googletagmanager.com/gtag/js'] ?? 0).toBeGreaterThanOrEqual(1);
  });

  test('11. XMLHttpRequest requests to provider-like endpoints follow the same consent gating', async ({ page }) => {
    await page.context().clearCookies();
    await gotoFrontend(page, matrixUrl);
    const target = directCollectUrl('clarity.ms/tag/faz-matrix.js');

    await runDirectXhr(page, target);
    await page.waitForTimeout(500);
    expect(readProviderMatrixHits()['clarity.ms/tag/faz-matrix.js'] ?? 0).toBe(0);

    await acceptAll(page);
    await runDirectXhr(page, target);
    await page.waitForTimeout(500);
    expect(readProviderMatrixHits()['clarity.ms/tag/faz-matrix.js'] ?? 0).toBeGreaterThanOrEqual(1);
  });

  test('12. sendBeacon requests to provider-like endpoints are also gated by consent', async ({ page }) => {
    // Snapshot and clear whitelist_patterns: if connect.facebook.net is whitelisted in the DB,
    // _fazIsUserWhitelisted() returns true and the sendBeacon interceptor skips blocking.
    const snap = wpEval(`echo wp_json_encode( get_option( 'faz_settings', array() ) );`);
    const snapEncoded = Buffer.from(snap, 'utf8').toString('base64');
    wpEval(`
      $s = get_option( 'faz_settings', array() );
      if ( ! is_array( $s ) ) { $s = array(); }
      if ( ! isset( $s['script_blocking'] ) || ! is_array( $s['script_blocking'] ) ) {
        $s['script_blocking'] = array();
      }
      $s['script_blocking']['whitelist_patterns'] = array();
      update_option( 'faz_settings', $s );
      if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
        \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
      }
    `);
    try {
      await page.context().clearCookies();
      await gotoFrontend(page, matrixUrl);
      const target = directCollectUrl('connect.facebook.net/en_US/fbevents.js');

      expect(await runDirectBeacon(page, target)).toBe(true);
      await page.waitForTimeout(500);
      expect(readProviderMatrixHits()['connect.facebook.net/en_US/fbevents.js'] ?? 0).toBe(0);

      await acceptAll(page);
      expect(await runDirectBeacon(page, target)).toBe(true);
      await page.waitForTimeout(750);
      expect(readProviderMatrixHits()['connect.facebook.net/en_US/fbevents.js'] ?? 0).toBeGreaterThanOrEqual(1);
    } finally {
      wpEval(`
        $s = json_decode( base64_decode( '${snapEncoded}' ), true );
        update_option( 'faz_settings', is_array( $s ) ? $s : array() );
        if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
          \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
        }
      `);
    }
  });

  // Parked in 1.18.2 while per-service consent was force-disabled; the
  // feature came back and these pass again (re-enabled 2026-07-29).
  test('13. per-service consent can allow Google Analytics while keeping Clarity blocked', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);
    // Defensive parse: same pattern as inline-script-filter.spec.ts. Without
    // it, a non-numeric consent_revision (e.g. stringified "abc") produces
    // rev:NaN in the seeded cookie and the frontend invalidates it, silently
    // turning this into a first-visit test instead of a returning visitor.
    const parsedRevision = parseInt(String(original.general?.consent_revision ?? 1).trim(), 10);
    const revision = String(Number.isFinite(parsedRevision) && parsedRevision > 0 ? parsedRevision : 1);

    try {
      await postSettings(page, nonce, {
        banner_control: {
          ...(original.banner_control ?? {}),
          per_service_consent: true,
        },
      });

      const visitor = await browser.newContext({ baseURL: WP_BASE });
      try {
        await setConsentCookie(visitor, matrixUrl, {
          action: 'custom',
          analytics: 'yes',
          consent: 'yes',
          functional: 'no',
          marketing: 'no',
          necessary: 'yes',
          rev: revision,
          'svc.clarity': 'no',
          'svc.google-analytics': 'yes',
        });

        const visitorPage = await visitor.newPage();
        await gotoFrontend(visitorPage, matrixUrl);

        await waitForCookie(visitorPage, '_ga');
        const cookieNames = await browserCookieNames(visitorPage);
        expect(cookieNames).toContain('_ga');
        expect(cookieNames).not.toContain('_clck');
        expect(cookieNames).not.toContain('_fbp');
      } finally {
        await visitor.close();
      }
    } finally {
      await postSettings(page, nonce, {
        banner_control: original.banner_control ?? {},
      });
    }
  });

  test('14. WooCommerce checkout keeps Stripe gateway scripts usable before consent', async ({ page, browser }) => {
    enableProviderMatrixWooScenario();
    ensureWooCommerceLabData();
    const wooUrls = readWooUrls();

    const visitor = await browser.newContext({ baseURL: WP_BASE });
    try {
      const visitorPage = await visitor.newPage();
      await gotoFrontend(visitorPage, wooUrls.checkout);

      await waitForCookie(visitorPage, '__stripe_mid');
      expect(await visitorPage.locator('script[type="text/plain"][src*="js.stripe.com"]').count()).toBe(0);
    } finally {
      await visitor.close();
    }
  });

  test('15. explicitly enabled Stripe is allowed on non-checkout pages', async ({ page, browser, loginAsAdmin }) => {
    test.skip(IS_PHP_BUILT_IN_E2E, 'Fixture page is_singular() is unreliable on the PHP built-in server.');
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await getSettings(page, nonce);
    const visitor = await browser.newContext({ baseURL: WP_BASE });
    try {
      await postSettings(page, nonce, {
        script_blocking: {
          ...(original.script_blocking ?? {}),
          payment_gateways: {
            ...(original.script_blocking?.payment_gateways ?? {}),
            stripe: true,
          },
        },
      });
      resetProviderMatrixState();
      const visitorPage = await visitor.newPage();
      await gotoFrontend(visitorPage, matrixUrl);

      await expect
        .poll(() => readProviderMatrixHits().stripe ?? 0, {
          timeout: 10_000,
          message: 'Explicitly enabled Stripe should execute on a non-checkout page.',
        })
        .toBeGreaterThanOrEqual(1);

      expect(await visitorPage.locator('script[type="text/plain"][src*="js.stripe.com"]').count()).toBe(0);
    } finally {
      await visitor.close();
      await postSettings(page, nonce, { script_blocking: original.script_blocking ?? {} });
    }
  });
});
