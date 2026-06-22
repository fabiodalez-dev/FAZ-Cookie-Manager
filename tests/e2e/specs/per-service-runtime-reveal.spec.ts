import { test, expect } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { clickFirstVisible } from '../utils/ui';

/**
 * Per-service toggle reveal on block-first sites (#134/#146).
 *
 * The bug: the visible per-service toggle list (`_fazConfig._services`) was
 * sourced ONLY from scanner-detected cookies. On a block-first site a provider
 * is blocked before it can set a cookie, so the scanner never sees it — and a
 * JS-injected embed (e.g. a YouTube iframe_api player) is never in the server
 * HTML the scanner fetches at all. Net: `_services` stays empty and no toggle
 * ever appears, exactly on the plugin's primary use case (reported on
 * criptasemantica.it). Enforcement always worked (broad enforceable set), so
 * selective consent applied with no UI to drive it.
 *
 * The fix ships a presentation `_serviceCatalogue` (enforceable providers in
 * active categories, with label+cookies) and reveals a toggle for any provider
 * the page ACTUALLY blocks — server-rendered placeholders (data-faz-service)
 * AND JS-injected embeds the MutationObserver blocks at runtime. Present-aware,
 * so it never dumps the whole catalogue.
 *
 * Provider choice is deliberate and decoupled from the test stack's scan state:
 *  - Vimeo  (marketing, iframe) — NOT embedded by the test theme → used to
 *    prove a runtime-injected embed reveals its toggle.
 *  - Dailymotion (marketing) — NOT embedded anywhere → used to prove we never
 *    over-disclose a catalogue provider that isn't present.
 */

const WP_PATH = process.env.WP_PATH || '';
const YT = 'https://www.youtube.com/embed/dQw4w9WgXcQ';
// Inject/lazy target: Dailymotion is in the catalogue (marketing, iframe) but is
// NOT loaded by any plugin on the dev stack, so it's a clean "absent" provider —
// unlike Vimeo/YouTube, whose scripts the demo tracking plugins block globally.
const DAILY_SRC = 'https://www.dailymotion.com/embed/video/x7tgad0';

function wp(args: string[]): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();
}

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

async function openPreferenceCenter(page: Page): Promise<void> {
  const opened = await clickFirstVisible(page, [
    '[data-faz-tag="settings-button"] button',
    '[data-faz-tag="settings-button"]',
    '.faz-btn-customize',
  ]);
  expect(opened, 'preference-center button is reachable').toBeTruthy();
  await expect(page.locator('[data-faz-tag="detail"]')).toBeVisible({ timeout: 5000 });
}

test.describe('Per-service runtime reveal on block-first sites (#134/#146)', () => {
  test.skip(!WP_PATH, 'requires WP_PATH to toggle settings + seed pages via wp-cli');
  test.describe.configure({ mode: 'serial' });

  let original: FazSettings | null = null;
  let nonce = '';
  let staticUrl = '';
  let staticPostId = '';
  let lazyUrl = '';
  let lazyPostId = '';
  let lightboxUrl = '';
  let lightboxPostId = '';

  test.beforeAll(async ({ browser, loginAsAdmin }) => {
    const page = await browser.newPage();
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    nonce = await getAdminNonce(page);
    expect(nonce.length).toBeGreaterThan(0);
    original = await getSettings(page, nonce);
    const bc = { ...(original.banner_control as Record<string, unknown> | undefined) };
    await postSettings(page, nonce, {
      banner_control: { ...bc, per_service_consent: true, per_cookie_consent: true },
    });
    await page.close();

    // A page whose server HTML carries a static YouTube iframe — the plugin
    // blocks it server-side into a placeholder carrying data-faz-service.
    staticPostId = wp([
      'post', 'create', '--post_type=page', '--post_status=publish',
      '--post_title=FAZ E2E reveal static',
      `--post_content=<iframe width="560" height="315" src="${YT}" title="YouTube"></iframe>`,
      '--porcelain',
    ]).replace(/\D/g, '');
    staticUrl = wp(['post', 'get', staticPostId, '--field=url']);

    // A page whose Vimeo embed is LAZY: the URL lives in data-src (never a live
    // <iframe src>), so the server doesn't placeholder it and the MutationObserver
    // never fires — only the DOM-scan pre-reveal can surface it.
    lazyPostId = wp([
      'post', 'create', '--post_type=page', '--post_status=publish',
      '--post_title=FAZ E2E reveal lazy',
      `--post_content=<iframe data-src="${DAILY_SRC}" width="640" height="360" loading="lazy"></iframe>`,
      '--porcelain',
    ]).replace(/\D/g, '');
    lazyUrl = wp(['post', 'get', lazyPostId, '--field=url']);

    // A page-builder LIGHTBOX link: the URL is a WATCH-style href (not an embed
    // iframe), opened in a modal on click. The provider must still reveal its
    // toggle. Divi's .et_pb_lightbox_video reads the href as the video URL.
    lightboxPostId = wp([
      'post', 'create', '--post_type=page', '--post_status=publish',
      '--post_title=FAZ E2E reveal lightbox',
      '--post_content=<a class="et_pb_lightbox_video" href="https://www.dailymotion.com/video/x7tgad0">Play video</a>',
      '--porcelain',
    ]).replace(/\D/g, '');
    lightboxUrl = wp(['post', 'get', lightboxPostId, '--field=url']);
  });

  test.afterAll(async ({ browser, loginAsAdmin }) => {
    if (staticPostId) wp(['post', 'delete', staticPostId, '--force']);
    if (lazyPostId) wp(['post', 'delete', lazyPostId, '--force']);
    if (lightboxPostId) wp(['post', 'delete', lightboxPostId, '--force']);
    if (!original?.banner_control) return;
    const page = await browser.newPage();
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    const n = await getAdminNonce(page);
    await postSettings(page, n, { banner_control: original.banner_control as FazSettings });
    await page.close();
  });

  test('server ships a _serviceCatalogue of enforceable providers (presentation-only)', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' });

    const cfg = await page.evaluate(() => {
      const c = (window as unknown as {
        _fazConfig?: { _perServiceConsent?: string; _serviceCatalogue?: Record<string, { category?: string; cookies?: unknown }> };
      })._fazConfig;
      const cat = c?._serviceCatalogue;
      return {
        perService: c?._perServiceConsent,
        hasCatalogue: !!cat && typeof cat === 'object',
        count: cat ? Object.keys(cat).length : -1,
        youtube: cat?.youtube ?? null,
        vimeo: cat?.vimeo ?? null,
      };
    });

    expect(cfg.perService).toBe('1');
    expect(cfg.hasCatalogue).toBe(true);
    expect(cfg.count).toBeGreaterThan(1);
    // Marketing is active → these enforceable providers must be in the catalogue
    // with their category and a cookies array, ready for client-side reveal.
    expect(cfg.youtube, 'YouTube in catalogue').not.toBeNull();
    expect((cfg.youtube as { category?: string }).category).toBe('marketing');
    expect(Array.isArray((cfg.youtube as { cookies?: unknown }).cookies)).toBe(true);
    expect(cfg.vimeo, 'Vimeo in catalogue').not.toBeNull();
    expect((cfg.vimeo as { category?: string }).category).toBe('marketing');

    await ctx.close();
  });

  test('JS-injected embed reveals its per-service toggle (the criptasemantica case)', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });

    // Dailymotion is NOT embedded on this page → no toggle yet (no over-disclosure).
    await openPreferenceCenter(page);
    expect(
      await page.evaluate(() => (window as unknown as { _fazConfig?: { _services?: Array<{ id?: string }> } })._fazConfig?._services?.some((s) => s && s.id === 'dailymotion') ?? false),
    ).toBe(false);
    expect(await page.locator('.faz-service-toggle[data-service="dailymotion"]').count()).toBe(0);

    // Inject a Dailymotion iframe AFTER load — the MutationObserver path that a
    // server-side scanner can never see.
    await page.evaluate((src) => {
      const f = document.createElement('iframe');
      f.width = '640';
      f.height = '360';
      f.src = src;
      document.body.appendChild(f);
    }, DAILY_SRC);

    // The block decision folds the service into the visible list…
    await expect
      .poll(
        () =>
          page.evaluate(() => {
            const s = (window as unknown as { _fazConfig?: { _services?: Array<{ id?: string }> } })._fazConfig?._services;
            return Array.isArray(s) ? s.some((x) => x && x.id === 'dailymotion') : false;
          }),
        { timeout: 8000 },
      )
      .toBe(true);

    // …and injects its toggle live into the Marketing accordion.
    const toggle = page.locator('.faz-service-toggle[data-service="dailymotion"]');
    await expect(toggle).toHaveCount(1);
    expect(await toggle.getAttribute('data-category')).toBe('marketing');

    // a11y: a toggle revealed at runtime is labelled and lives in a polite
    // live region so screen-reader users perceive it (review F2/F3).
    expect(await toggle.getAttribute('aria-label')).toBeTruthy();
    const liveRegion = page.locator('.faz-service-list[data-faz-category="marketing"][aria-live="polite"]');
    await expect(liveRegion).toHaveCount(1);
    await expect(liveRegion.locator('.faz-service-toggle[data-service="dailymotion"]')).toHaveCount(1);

    // Third-party embed: the per-cookie list carries the clarifying note (its
    // cookies live on the embed's domain, so they can't be shredded individually
    // — only the service-level block enforces).
    await expect(page.locator('.faz-cookie-list-note[data-faz-service="dailymotion"]')).toHaveCount(1);
    // …and its per-cookie toggles are DISABLED (no false affordance — they can't
    // delete a cross-origin cookie), while the service toggle stays interactive.
    const embedCkToggles = page.locator('.faz-cookie-toggle[data-service="dailymotion"]');
    const ckCount = await embedCkToggles.count();
    expect(ckCount).toBeGreaterThan(0);
    for (let i = 0; i < ckCount; i++) {
      expect(await embedCkToggles.nth(i).isDisabled()).toBe(true);
    }
    expect(await toggle.isDisabled()).toBe(false);

    // The blocked iframe is neutralised (enforcement still works).
    expect(await page.locator('iframe[src*="dailymotion.com"]').count()).toBe(0);

    // Toggling it writes an explicit svc.dailymotion decision (the enforcement seam).
    const decided = await page.evaluate(() => {
      const fz = (window as unknown as { fazcookie?: { _fazSetInStore?: (k: string, v: string) => void; _fazGetFromStore?: (k: string) => string } }).fazcookie;
      if (fz && typeof fz._fazSetInStore === 'function') fz._fazSetInStore('svc.dailymotion', 'no');
      return fz && typeof fz._fazGetFromStore === 'function' ? fz._fazGetFromStore('svc.dailymotion') : '';
    });
    expect(decided).toBe('no');

    await ctx.close();
  });

  test('static server-blocked embed reveals its toggle via data-faz-service', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });

    // The server replaced the static iframe with a placeholder carrying the id.
    await expect(page.locator('.faz-placeholder[data-faz-service="youtube"]')).toHaveCount(1);

    await openPreferenceCenter(page);
    const toggle = page.locator('.faz-service-toggle[data-service="youtube"]');
    await expect(toggle).toHaveCount(1);
    expect(await toggle.getAttribute('data-category')).toBe('marketing');

    await ctx.close();
  });

  test('no over-disclosure: a catalogue provider not present on the page gets no toggle', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });

    await openPreferenceCenter(page);

    // SoundCloud IS in the catalogue but is embedded/loaded nowhere here.
    const inCatalogue = await page.evaluate(
      () => !!(window as unknown as { _fazConfig?: { _serviceCatalogue?: Record<string, unknown> } })._fazConfig?._serviceCatalogue?.soundcloud,
    );
    expect(inCatalogue, 'soundcloud is a catalogue provider').toBe(true);
    expect(await page.locator('.faz-service-toggle[data-service="soundcloud"]').count()).toBe(0);

    // We DO show toggles for providers actually present (proves the list isn't
    // simply empty) and the category-level fallback is intact.
    expect(await page.locator('.faz-service-toggle[data-service="youtube"]').count()).toBe(1);
    expect(
      await page.locator('input[id^="fazSwitch"], input[id^="fazCategoryDirect"]').count(),
    ).toBeGreaterThan(0);

    await ctx.close();
  });

  test('lazy/below-fold embed (data-src) reveals its toggle without scrolling', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(lazyUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });

    // The embed is purely lazy: its URL is in data-src, it has no live src, and
    // we never scroll — so neither the server nor the MutationObserver touched it.
    expect(await page.locator('iframe[data-src*="dailymotion.com"]').count()).toBe(1);
    expect(await page.locator('iframe[src*="dailymotion.com"]').count()).toBe(0);

    // Opening the preference center runs the DOM-scan pre-reveal, which resolves
    // the lazy URL to its provider and surfaces the toggle up-front.
    await openPreferenceCenter(page);
    const toggle = page.locator('.faz-service-toggle[data-service="dailymotion"]');
    await expect(toggle).toHaveCount(1);
    expect(await toggle.getAttribute('data-category')).toBe('marketing');

    await ctx.close();
  });

  test('page-builder lightbox link (watch URL) reveals its toggle on click', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(lightboxUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });

    // Not embedded as an iframe — only a lightbox <a> with a watch-style href —
    // so it's absent until the visitor clicks to open the modal.
    expect(
      await page.evaluate(() => {
        const s = (window as unknown as { _fazConfig?: { _services?: Array<{ id?: string }> } })._fazConfig?._services;
        return Array.isArray(s) ? s.some((x) => x && x.id === 'dailymotion') : false;
      }),
      'Dailymotion is absent before the lightbox link is clicked',
    ).toBe(false);

    // Dispatch the click directly on the link: the capture-phase document
    // interceptor receives it with target=link regardless of the consent
    // overlay's hit-test (a real .click() races the banner overlay at first
    // visit). The interceptor blocks the modal, marks the element, and reveals
    // the provider's toggle.
    await page.evaluate(() => {
      const link = document.querySelector('a.et_pb_lightbox_video');
      if (link) link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    });

    await expect
      .poll(
        () =>
          page.evaluate(() => {
            const s = (window as unknown as { _fazConfig?: { _services?: Array<{ id?: string }> } })._fazConfig?._services;
            return Array.isArray(s) ? s.some((x) => x && x.id === 'dailymotion') : false;
          }),
        { timeout: 8000 },
      )
      .toBe(true);

    // The link itself is marked service-aware, so a later sync also picks it up.
    expect(await page.locator('a.et_pb_lightbox_video[data-faz-service="dailymotion"]').count()).toBe(1);

    await openPreferenceCenter(page);
    await expect(page.locator('.faz-service-toggle[data-service="dailymotion"]')).toHaveCount(1);

    await ctx.close();
  });

  test('a decided service stays visible for withdrawal on a page WITHOUT its embed', async ({ browser }) => {
    // The criptasemantica regression: after accepting a block-first service the
    // embed loads unblocked → no placeholder → the client-side reveal is gone →
    // `_services` empties and the toggle vanishes, so the grant can't be
    // withdrawn (GDPR Art.7(3)). The server-side svc.* augmentation keeps any
    // EXPLICITLY-decided service in the visible list site-wide, independent of a
    // placeholder. Dailymotion is not embedded on staticUrl (only YouTube is),
    // so a toggle here can ONLY come from the persisted svc.dailymotion decision.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    // First load to read the live policy revision — the server discards a
    // consent cookie whose rev is stale, so it must carry the current one.
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' });
    const rev = await page.evaluate(
      () => String((window as unknown as { _fazConsentLog?: { policyRevision?: string } })._fazConsentLog?.policyRevision ?? '1'),
    );
    await ctx.addCookies([
      {
        name: 'fazcookie-consent',
        value: `action:yes,necessary:yes,marketing:yes,rev:${rev},svc.dailymotion:no`,
        url: staticUrl,
        sameSite: 'Lax',
      },
    ]);
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });

    // Server-side: the decided service is in the store even with no embed here.
    const dbg = await page.evaluate(() => ({
      cookie: document.cookie,
      ids: ((window as unknown as { _fazConfig?: { _services?: Array<{ id?: string }> } })._fazConfig?._services ?? []).map((s) => s && s.id),
    }));
    expect(dbg.ids, `rev=${rev} state=${JSON.stringify(dbg)}`).toContain('dailymotion');

    // The visitor has already consented (action:yes → banner hidden), so the
    // preference center is reopened via the revisit entry point, not the
    // first-layer settings button. The decided service's toggle must be there so
    // the choice can be reviewed and WITHDRAWN (re-enabled).
    await page.evaluate(() => {
      const w = window as unknown as { revisitFazConsent?: () => void };
      if (typeof w.revisitFazConsent === 'function') w.revisitFazConsent();
    });
    const toggle = page.locator('.faz-service-toggle[data-service="dailymotion"]');
    await expect(toggle).toHaveCount(1);
    expect(await toggle.isChecked()).toBe(false); // svc.dailymotion:no → unchecked, but PRESENT

    await ctx.close();
  });
});
