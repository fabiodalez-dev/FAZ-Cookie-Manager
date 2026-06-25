import { test, expect } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { ensureFixturePlugin, listActivePluginFiles, restoreActivePluginFiles, upsertPage, wp } from '../utils/wp-env';

/**
 * Cache Compatibility Mode (issue #158) — end-to-end header behaviour.
 *
 * The plugin only cache-busts a page whose banner output varies by visitor
 * country (IAB TCF, geo-targeting, country-targeted banners, runtime geo). The
 * new banner_control.cache_compatibility toggle forces the output to be treated
 * as NON country-dependent, so the server stops emitting the no-cache/no-store
 * stack and the DONOTCACHEPAGE constant — keeping the HTML cacheable by
 * LiteSpeed/QUIC.cloud/Varnish while the banner runs client-side.
 *
 * This suite drives the REAL header path: it flips IAB on (a country-dependent
 * trigger) and reads the front-end response headers from an ANONYMOUS context,
 * with the toggle off (cache-bust active) and on (cacheable). Serial + restores
 * the original settings in afterAll, so it is reusable in isolation or in-suite.
 */

const BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const CACHE_FIXTURE_SLUG = 'faz-cache-compat-provider';

type FazSettings = Record<string, unknown>;

async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => window.fazConfig?.api?.nonce ?? '');
}
async function getSettings(page: Page, nonce: string): Promise<FazSettings> {
  const res = await page.request.get('/?rest_route=/faz/v1/settings/', { headers: { 'X-WP-Nonce': nonce } });
  expect(res.status()).toBe(200);
  return (await res.json()) as FazSettings;
}
async function postSettings(page: Page, nonce: string, payload: FazSettings): Promise<void> {
  const res = await page.request.post('/?rest_route=/faz/v1/settings/', {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: payload,
  });
  expect(res.status(), `settings update status ${res.status()}`).toBe(200);
}

/** Read front-end response headers as an anonymous visitor (no admin cookies). */
async function anonHeaders(browser: import('@playwright/test').Browser): Promise<Record<string, string>> {
  const ctx = await browser.newContext();
  try {
    const res = await ctx.request.get(BASE + '/', { headers: { 'User-Agent': 'Mozilla/5.0 (cache-compat-e2e)' } });
    return res.headers();
  } finally {
    await ctx.close();
  }
}

async function anonHtml(
  browser: import('@playwright/test').Browser,
  path: string,
  consentCookie?: string
): Promise<string> {
  const ctx = await browser.newContext();
  try {
    if (consentCookie) {
      await ctx.addCookies([
        {
          name: 'fazcookie-consent',
          value: encodeURIComponent(consentCookie),
          url: BASE,
          expires: Math.floor(Date.now() / 1000) + 3600,
        },
      ]);
    }
    const res = await ctx.request.get(BASE + path, { headers: { 'User-Agent': 'Mozilla/5.0 (cache-compat-html-e2e)' } });
    expect(res.status()).toBeLessThan(400);
    return await res.text();
  } finally {
    await ctx.close();
  }
}

test.describe('Cache Compatibility Mode (issue #158)', () => {
  test.describe.configure({ mode: 'serial' });

  let admin: Page;
  let nonce = '';
  let originalBannerControl: Record<string, unknown> = {};
  let originalIab: Record<string, unknown> = {};
  let originalActivePluginFiles: string[] | null = null;
  let fixtureId = 0;

  async function applyState(opts: { cacheCompat: boolean; iab: boolean }): Promise<void> {
    await postSettings(admin, nonce, {
      banner_control: { ...originalBannerControl, status: true, cache_compatibility: opts.cacheCompat },
      iab: { ...originalIab, enabled: opts.iab },
    });
  }

  test.beforeAll(async ({ browser, loginAsAdmin }) => {
    originalActivePluginFiles = listActivePluginFiles();
    ensureFixturePlugin('faz-e2e-audit-lab');
    fixtureId = upsertPage(
      CACHE_FIXTURE_SLUG,
      'FAZ Cache Compatibility Provider',
      '<p>FAZ cache compatibility provider fixture.</p><script src="https://www.googletagmanager.com/gtag/js?id=G-FAZCACHE"></script>'
    );

    admin = await browser.newPage();
    await loginAsAdmin(admin);
    await admin.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    nonce = await getAdminNonce(admin);
    expect(nonce.length).toBeGreaterThan(0);
    const current = await getSettings(admin, nonce);
    originalBannerControl = { ...(current.banner_control as Record<string, unknown> | undefined) };
    originalIab = { ...(current.iab as Record<string, unknown> | undefined) };
  });

  test.afterAll(async () => {
    if (nonce) {
      await postSettings(admin, nonce, { banner_control: originalBannerControl, iab: originalIab });
    }
    if (fixtureId) {
      try {
        wp(['post', 'delete', String(fixtureId), '--force']);
      } catch {
        /* best-effort cleanup */
      }
    }
    if (originalActivePluginFiles !== null) {
      restoreActivePluginFiles(originalActivePluginFiles);
    }
    await admin.close();
  });

  test('1. default (no country-dependent trigger) is already cacheable', async ({ browser }) => {
    await applyState({ cacheCompat: false, iab: false });
    const h = await anonHeaders(browser);
    expect(h['x-litespeed-cache-control']).toBeUndefined();
    expect(h['cache-control'] ?? '').not.toContain('no-store');
  });

  test('2. IAB on + cache-compat OFF → cache-bust headers ARE emitted (control)', async ({ browser }) => {
    await applyState({ cacheCompat: false, iab: true });
    const h = await anonHeaders(browser);
    expect(h['x-litespeed-cache-control']).toBe('no-cache');
    expect(h['cache-control'] ?? '').toContain('no-store');
    expect(h['pragma'] ?? '').toContain('no-cache');
  });

  test('3. IAB on + cache-compat ON → NO X-LiteSpeed no-cache header (cacheable)', async ({ browser }) => {
    await applyState({ cacheCompat: true, iab: true });
    const h = await anonHeaders(browser);
    expect(h['x-litespeed-cache-control']).toBeUndefined();
  });

  test('4. IAB on + cache-compat ON → Cache-Control has no no-store/no-cache', async ({ browser }) => {
    await applyState({ cacheCompat: true, iab: true });
    const h = await anonHeaders(browser);
    const cc = h['cache-control'] ?? '';
    expect(cc).not.toContain('no-store');
    expect(h['pragma'] ?? '').not.toContain('no-cache');
  });

  test('5. cache-compat ON still ships the banner (consent stays client-side)', async ({ browser }) => {
    await applyState({ cacheCompat: true, iab: true });
    const ctx = await browser.newContext();
    try {
      const page = await ctx.newPage();
      await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });
      // The banner config is localized and the consent runtime is present — the
      // cacheable HTML still carries everything the client needs.
      const hasConfig = await page.evaluate(() => typeof (window as unknown as { _fazConfig?: unknown })._fazConfig === 'object');
      expect(hasConfig).toBe(true);
    } finally {
      await ctx.close();
    }
  });

  test('6. toggling cache-compat back OFF restores the cache-bust (reversible)', async ({ browser }) => {
    await applyState({ cacheCompat: false, iab: true });
    const h = await anonHeaders(browser);
    expect(h['x-litespeed-cache-control']).toBe('no-cache');
  });

  test('7. cache-compat ON keeps third-party HTML blocked even for a consenting visitor', async ({ browser }) => {
    await applyState({ cacheCompat: true, iab: true });
    const fixturePath = `/${CACHE_FIXTURE_SLUG}/`;
    const consentedCookie = [
      'consent:yes',
      'action:yes',
      'necessary:yes',
      'analytics:yes',
      'marketing:yes',
      'functional:yes',
      '__scope.banner:cache-compat',
      '__scope.law:gdpr',
    ].join(',');

    const noConsentHtml = await anonHtml(browser, fixturePath);
    const consentedHtml = await anonHtml(browser, fixturePath, consentedCookie);
    const gtagTags = (html: string) => Array.from(html.matchAll(/<script\b[^>]*googletagmanager\.com\/gtag\/js[^>]*>/gi), (match) => match[0]);
    const isBlockedAnalyticsTag = (tag: string) =>
      /type=["']text\/plain["']/i.test(tag) && /data-faz-category=["']analytics["']/i.test(tag);

    const noConsentTags = gtagTags(noConsentHtml);
    const consentedTags = gtagTags(consentedHtml);
    expect(noConsentTags.length).toBeGreaterThan(0);
    expect(consentedTags.length).toBeGreaterThan(0);
    expect(noConsentTags.every(isBlockedAnalyticsTag)).toBe(true);
    expect(consentedTags.every(isBlockedAnalyticsTag)).toBe(true);
  });

  // Regression for the gooloo.de report (#158): a full-page-cached site shares
  // ONE rendered copy between crawlers and humans. With hide_from_bots on, a
  // crawler request (or LiteSpeed's own cache-warming crawler) used to produce a
  // banner-less copy that then got cached and served to every visitor — banner
  // permanently hidden, trackers unblocked. Under Cache Compatibility Mode the
  // render must be visitor-invariant, so the bot-skip is suppressed.
  test('8. cache-compat ON makes the render bot-invariant — banner enqueued for crawlers too (#158)', async ({ browser }) => {
    const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    const hasFazScript = (html: string) => /faz-cookie-manager\/frontend\/js\/script(\.min)?\.js/.test(html);
    const fetchAsBot = async (): Promise<string> => {
      const ctx = await browser.newContext({ userAgent: GOOGLEBOT });
      try {
        const res = await ctx.request.get(BASE + '/', { headers: { 'User-Agent': GOOGLEBOT } });
        expect(res.status()).toBeLessThan(400);
        return await res.text();
      } finally {
        await ctx.close();
      }
    };

    // cache-compat OFF + hide_from_bots ON: the bot render skips the banner
    // bootstrap (unchanged legacy behaviour — safe because the page is not cached).
    await postSettings(admin, nonce, {
      banner_control: { ...originalBannerControl, status: true, hide_from_bots: true, cache_compatibility: false },
      iab: { ...originalIab, enabled: false },
    });
    expect(hasFazScript(await fetchAsBot()), 'bot render WITHOUT cache-compat should skip FAZ').toBe(false);

    // cache-compat ON: the bot-skip is suppressed so the cacheable copy a crawler
    // generates still ships script.min.js — humans served that copy get the banner.
    await postSettings(admin, nonce, {
      banner_control: { ...originalBannerControl, status: true, hide_from_bots: true, cache_compatibility: true },
      iab: { ...originalIab, enabled: false },
    });
    expect(hasFazScript(await fetchAsBot()), 'bot render WITH cache-compat must include FAZ').toBe(true);
  });

  test('9. cache-compat ON keeps TCF config country-invariant and conservative (#158)', async ({ browser }) => {
    const readTcfConfig = async (country: string): Promise<Record<string, unknown>> => {
      const html = await anonHtml(browser, `/?faz_e2e_trust_cf=1&faz_e2e_cf_country=${country}`);
      const match = html.match(/window\._fazTcfConfig=(\{.*?\});/s);
      expect(match, `TCF config should be emitted for ${country}`).not.toBeNull();
      return JSON.parse(match?.[1] ?? '{}') as Record<string, unknown>;
    };

    await postSettings(admin, nonce, {
      banner_control: { ...originalBannerControl, status: true, cache_compatibility: true },
      iab: { ...originalIab, enabled: true, cmp_id: 2 },
    });

    const usConfig = await readTcfConfig('US');
    const deConfig = await readTcfConfig('DE');

    expect(usConfig).toEqual(deConfig);
    expect(usConfig.gdprApplies).toBe(true);
  });
});
