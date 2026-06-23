import { expect, test } from '../fixtures/wp-fixture';
import type { BrowserContext, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { clickFirstVisible } from '../utils/ui';

/**
 * Per-service consent (1.18.3) — regression coverage for the three
 * correctness gaps the 1.18.2 hotfix flagged, now fixed:
 *
 *  - P2  : the service toggle list is sourced from the cookies actually
 *          DETECTED on the site (wp_faz_cookies), not the full provider
 *          catalogue.
 *  - P1-4: the fazcookie-consent cookie is hard-capped under the 4 KB
 *          browser limit, dropping low-priority per-service / per-cookie
 *          overrides rather than letting the browser silently truncate it.
 *  - P1-3: the per-service (svc.*) decisions are written to the consent
 *          log for GDPR accountability.
 *
 * Each test drives the REAL shipped code: P2 hits the PHP store payload,
 * P1-4 calls the shipped (minified) _fazSetInStore in the browser, and
 * P1-3 intercepts the live consent-log POST. Settings are restored after.
 */

type FazSettings = Record<string, unknown>;

async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => window.fazConfig?.api?.nonce ?? '');
}

async function getSettings(page: Page, nonce: string): Promise<FazSettings> {
  const res = await page.request.get('/?rest_route=/faz/v1/settings/', {
    headers: { 'X-WP-Nonce': nonce },
  });
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

/** Non-necessary providers in the shipped catalogue — the upper bound for _services. */
function catalogueNonNecessaryCount(): number {
  const jsonPath = fileURLToPath(
    new URL('../../../includes/data/known-providers.json', import.meta.url),
  );
  const data = JSON.parse(readFileSync(jsonPath, 'utf8')) as Record<
    string,
    { category?: string }
  >;
  return Object.values(data).filter((p) => p && p.category && p.category !== 'necessary').length;
}

test.describe('Per-service consent (1.18.3)', () => {
  test.describe.configure({ mode: 'serial' });

  let original: FazSettings | null = null;
  let nonce = '';

  test.beforeAll(async ({ browser, loginAsAdmin }) => {
    const page = await browser.newPage();
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', {
      waitUntil: 'domcontentloaded',
    });
    nonce = await getAdminNonce(page);
    expect(nonce.length).toBeGreaterThan(0);
    original = await getSettings(page, nonce);
    const bannerControl = { ...(original.banner_control as Record<string, unknown> | undefined) };
    await postSettings(page, nonce, {
      banner_control: { ...bannerControl, per_service_consent: true },
    });
    await page.close();
  });

  test.afterAll(async ({ browser, loginAsAdmin }) => {
    if (!original?.banner_control) return;
    const page = await browser.newPage();
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', {
      waitUntil: 'domcontentloaded',
    });
    const n = await getAdminNonce(page);
    await postSettings(page, n, { banner_control: original.banner_control as FazSettings });
    await page.close();
  });

  test('P2 — _services is filtered to present providers, never the full catalogue', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    // Let the frontend run: the visible list is scanner-detected cookies UNION
    // the providers actually blocked on the page (server placeholders +
    // JS-injected embeds the MutationObserver catches — #134/#146). Either way it
    // must never become the whole catalogue.
    // No .catch(): the fail-open watchdog forces faz-ready within 2500ms, so an
    // 8s timeout here means the frontend genuinely failed to initialise — that
    // must fail the test, not be silently swallowed.
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });

    const services = await page.evaluate(
      () => (window as unknown as { _fazConfig?: { _services?: Array<Record<string, unknown>> } })._fazConfig?._services ?? [],
    );

    // The feature is on, so the list must be present and well-formed.
    expect(Array.isArray(services)).toBe(true);
    for (const svc of services) {
      expect(typeof svc.id).toBe('string');
      expect(typeof svc.category).toBe('string');
      expect(Array.isArray(svc.cookies)).toBe(true);
    }

    // The core P2 guarantee: the list is FILTERED to providers relevant to this
    // page — strictly smaller than the full non-necessary catalogue. It is never
    // the whole ~160-entry catalogue dumped into the preference center (the
    // over-disclosure + cookie-bloat regression P2 guards against).
    const catalogue = catalogueNonNecessaryCount();
    expect(catalogue).toBeGreaterThan(services.length);

    await ctx.close();
  });

  test('P1-4 — consent cookie stays under the 4 KB browser limit', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // Drive the REAL shipped _fazSetInStore with a flood of svc.* overrides
    // that diverge from any category (so the redundant-entry filter keeps
    // them), then read back the cookie the browser actually stored.
    const result = await page.evaluate(() => {
      const fz = (window as unknown as { fazcookie?: Record<string, (k: string, v: string) => void> }).fazcookie;
      if (!fz || typeof fz._fazSetInStore !== 'function') {
        return { ok: false, len: -1, hasCore: false, hasCriticalDeny: false, hasCookieOverride: false };
      }
      fz._fazSetInStore('analytics', 'yes'); // a core/category entry that must survive
      for (let i = 0; i < 300; i++) {
        fz._fazSetInStore('svc.flood-provider-with-a-long-id-' + i, 'yes');
      }
      fz._fazSetInStore('svc.critical-deny', 'no');
      fz._fazSetInStore('ck.low-priority.example', 'no');
      const m = document.cookie.match(/fazcookie-consent=([^;]+)/);
      const raw = m ? m[1] : '';
      const decoded = decodeURIComponent(raw);
      return {
        ok: true,
        len: raw.length,
        hasCore: decoded.indexOf('analytics:yes') !== -1,
        hasCriticalDeny: decoded.indexOf('svc.critical-deny:no') !== -1,
        hasCookieOverride: decoded.indexOf('ck.low-priority.example:no') !== -1,
      };
    });

    expect(result.ok, '_fazSetInStore is exposed on window.fazcookie').toBe(true);
    // The browser stored a non-empty cookie (it was NOT silently dropped for
    // exceeding 4 KB) and it is comfortably under the limit.
    expect(result.len).toBeGreaterThan(0);
    expect(result.len).toBeLessThan(4096);
    // Core/category entries are never sacrificed to the cap.
    expect(result.hasCore, 'core category entry survives the cap').toBe(true);
    expect(result.hasCriticalDeny, 'explicit service denials have priority').toBe(true);
    expect(result.hasCookieOverride, 'cookie overrides are dropped before service decisions').toBe(false);

    await ctx.close();
  });

  test('P1-3 — svc.* decisions are sent to the consent log', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // Capture the consent-log POST body. The inline logger reads svc.* from
    // the consent cookie and folds it into `categories`.
    let logged: Record<string, unknown> | null = null;
    await page.route('**/faz/v1/consent', async (route) => {
      try {
        logged = route.request().postDataJSON() as Record<string, unknown>;
      } catch {
        logged = null;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    });

    await page.evaluate(() => {
      const fz = (window as unknown as { fazcookie?: Record<string, (k: string, v: string) => void> }).fazcookie;
      if (fz && typeof fz._fazSetInStore === 'function') {
        fz._fazSetInStore('analytics', 'yes');
        fz._fazSetInStore('svc.google-analytics', 'no'); // diverges → persisted to cookie
      }
      document.dispatchEvent(
        new CustomEvent('fazcookie_consent_update', {
          detail: { action: 'custom', accepted: ['analytics'], rejected: ['marketing'] },
        }),
      );
    });

    await expect.poll(() => logged !== null, { timeout: 5000 }).toBe(true);
    const cats = (logged as unknown as { categories?: Record<string, string> }).categories ?? {};
    expect(cats['svc.google-analytics'], 'per-service decision is in the logged record').toBe('no');
    // The category-level summary is still present alongside it.
    expect(cats['analytics']).toBe('yes');

    await ctx.close();
  });

  test('category-only mode hides and disables per-service consent', async ({
    browser,
    loginAsAdmin,
  }) => {
    const adminPage = await browser.newPage();
    let visitorContext: BrowserContext | null = null;

    await loginAsAdmin(adminPage);
    await adminPage.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', {
      waitUntil: 'domcontentloaded',
    });
    const adminNonce = await getAdminNonce(adminPage);
    expect(adminNonce.length).toBeGreaterThan(0);

    try {
      const current = await getSettings(adminPage, adminNonce);
      const bannerControl = {
        ...(current.banner_control as Record<string, unknown> | undefined),
        per_service_consent: false,
      };
      await postSettings(adminPage, adminNonce, { banner_control: bannerControl });

      visitorContext = await browser.newContext();
      const visitorPage = await visitorContext.newPage();
      await visitorPage.goto('/', { waitUntil: 'domcontentloaded' });

      const mode = await visitorPage.evaluate(() => {
        const config = (window as unknown as {
          _fazConfig?: { _perServiceConsent?: boolean; _services?: unknown };
        })._fazConfig;
        return {
          enabled: config?._perServiceConsent === true,
          hasServices: Array.isArray(config?._services),
        };
      });
      expect(mode.enabled).toBe(false);
      expect(mode.hasServices).toBe(false);

      const opened = await clickFirstVisible(visitorPage, [
        '[data-faz-tag="settings-button"] button',
        '[data-faz-tag="settings-button"]',
        '.faz-btn-customize',
      ]);
      expect(opened).toBeTruthy();
      await expect(visitorPage.locator('[data-faz-tag="detail"]')).toBeVisible();
      expect(await visitorPage.locator('.faz-service-toggle').count()).toBe(0);
      expect(
        await visitorPage.locator('input[id^="fazSwitch"], input[id^="fazCategoryDirect"]').count(),
      ).toBeGreaterThan(0);
    } finally {
      const current = await getSettings(adminPage, adminNonce);
      const bannerControl = {
        ...(current.banner_control as Record<string, unknown> | undefined),
        per_service_consent: true,
      };
      await postSettings(adminPage, adminNonce, { banner_control: bannerControl });
      if (visitorContext) await visitorContext.close();
      await adminPage.close();
    }
  });
});
