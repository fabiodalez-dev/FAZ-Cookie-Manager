/**
 * Per-service consent — canonical reusable suite (25 tests).
 *
 * One cohesive, deterministic suite covering the whole per-service consent
 * surface for the 1.20.0 release: reveal (present-aware, no over-disclosure),
 * decision precedence (ck > svc > category), enforcement (block / restore /
 * faz-skip), persistence + withdrawal (GDPR Art. 7(3)), per-cookie third-party
 * handling, and correctness edge cases (boundary matching, recognised-service
 * accept).
 *
 * Determinism strategy: enforcement and precedence are exercised by SEEDING the
 * `fazcookie-consent` cookie (addCookies) and asserting the resulting DOM, not
 * by clicking through the save flow — seeding is reproducible and fast. Reveal
 * and a11y are exercised by opening the preference center. All pages are seeded
 * once in beforeAll via wp-cli and reused.
 *
 * Reuses tests/e2e/utils/wp-env (WP_PATH, wp, wpEval) and the wp-fixture
 * loginAsAdmin. Requires WP_PATH (wp-cli) — skips otherwise.
 */

import { test, expect } from '../fixtures/wp-fixture';
import type { Page, BrowserContext } from '@playwright/test';
import { WP_PATH, wp, wpEval } from '../utils/wp-env';
import { clickFirstVisible } from '../utils/ui';

type FazSettings = Record<string, unknown>;

const YT = 'https://www.youtube.com/embed/M7lc1UVf-VE';
const VIMEO = 'https://player.vimeo.com/video/76979871';
// Dailymotion is in the catalogue (marketing) but NOT embedded by the test
// stack → a clean "absent" provider for over-disclosure + runtime-inject tests.
const DAILY = 'https://www.dailymotion.com/embed/video/x7tgad0';

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
  expect(res.status(), `settings update ${res.status()}`).toBe(200);
}
async function openPreferenceCenter(page: Page): Promise<void> {
  const opened = await clickFirstVisible(page, [
    '[data-faz-tag="settings-button"] button',
    '[data-faz-tag="settings-button"]',
    '.faz-btn-customize',
  ]);
  expect(opened, 'preference-center button reachable').toBeTruthy();
  await expect(page.locator('[data-faz-tag="detail"]')).toBeVisible({ timeout: 5000 });
}
async function fazReady(page: Page): Promise<void> {
  await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 15000 });
}
/** Open the preference center on a page that already has a recorded consent
 *  choice (action:yes → the banner is gone, so the revisit widget is used). */
async function openPreferencesAfterConsent(page: Page): Promise<void> {
  await page.evaluate(() => {
    const w = window as unknown as { revisitFazConsent?: () => void };
    if (typeof w.revisitFazConsent === 'function') w.revisitFazConsent();
  });
  const detail = page.locator('[data-faz-tag="detail"]');
  if (await detail.isVisible().catch(() => false)) return;
  await openPreferenceCenter(page);
}
/** Seed a consent cookie on a context so the next visit boots with that state. */
async function seedConsent(ctx: BrowserContext, url: string, value: string): Promise<void> {
  await ctx.addCookies([{ name: 'fazcookie-consent', value, url, sameSite: 'Lax' }]);
}

test.describe('Per-service consent — canonical suite (25)', () => {
  test.skip(!WP_PATH, 'requires WP_PATH to seed pages via wp-cli');
  // Independent tests (own context + own seeded cookie); no serial mode so a
  // single failure never skips the rest. The dev stack is slow, so the runner's
  // retries absorb cold first-attempts; the beforeAll warms the caches.

  let original: FazSettings | null = null;
  let staticUrl = '';
  let cleanUrl = '';
  let lightboxUrl = '';
  let staticId = '';
  let cleanId = '';
  let lightboxId = '';
  let rev = '1';

  const mkPage = (title: string, slug: string, content: string): { id: string; url: string } => {
    const id = wp(['post', 'create', '--post_type=page', '--post_status=publish',
      `--post_title=${title}`, `--post_name=${slug}`, `--post_content=${content}`, '--porcelain'],
      { allowRetry: false }).replace(/\D/g, '');
    return { id, url: wp(['post', 'get', id, '--field=url']) };
  };

  test.beforeAll(async ({ browser, loginAsAdmin }) => {
    const admin = await browser.newPage();
    await loginAsAdmin(admin);
    await admin.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(admin);
    expect(nonce.length).toBeGreaterThan(0);
    original = await getSettings(admin, nonce);
    await postSettings(admin, nonce, {
      banner_control: {
        ...(original.banner_control as Record<string, unknown> | undefined),
        per_service_consent: true,
        per_cookie_consent: true,
      },
    });
    await admin.close();

    const s = mkPage('PS Suite static', 'ps-suite-static',
      `<h1>static</h1><iframe width="560" height="315" src="${YT}" title="YouTube"></iframe><iframe width="640" height="360" src="${VIMEO}" title="Vimeo"></iframe>`);
    staticId = s.id; staticUrl = s.url;
    const c = mkPage('PS Suite clean', 'ps-suite-clean', '<h1>clean</h1><p>no embeds</p>');
    cleanId = c.id; cleanUrl = c.url;
    const l = mkPage('PS Suite lightbox', 'ps-suite-lightbox',
      '<h1>lightbox</h1><a class="et_pb_lightbox_video" href="https://www.youtube.com/watch?v=M7lc1UVf-VE">Play</a>');
    lightboxId = l.id; lightboxUrl = l.url;
    rev = wpEval('echo faz_get_consent_revision();').trim() || '1';

    // Warm the caches: the first hit on a freshly-deployed plugin regenerates
    // the banner template + primes OPcache, which can blow a cold test's
    // timeout. Prime each seeded page once up-front so the real tests are fast.
    const warm = await browser.newContext();
    const wpg = await warm.newPage();
    for (const u of [staticUrl, cleanUrl, lightboxUrl]) {
      await wpg.goto(u, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await wpg.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 20000 }).catch(() => {});
    }
    await warm.close();
  });

  test.afterAll(async ({ browser, loginAsAdmin }) => {
    for (const id of [staticId, cleanId, lightboxId]) {
      if (id) wp(['post', 'delete', id, '--force'], { allowRetry: false });
    }
    if (!original?.banner_control) return;
    const admin = await browser.newPage();
    await loginAsAdmin(admin);
    await admin.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(admin);
    await postSettings(admin, nonce, { banner_control: original.banner_control as FazSettings });
    await admin.close();
  });

  // Helpers reading frontend state.
  const visibleServiceIds = (page: Page) => page.evaluate(() =>
    ((window as unknown as { _fazConfig?: { _services?: Array<{ id?: string }> } })._fazConfig?._services ?? []).map((s) => s.id));
  const consentCookie = async (ctx: BrowserContext): Promise<string> =>
    (await ctx.cookies()).find((c) => c.name === 'fazcookie-consent')?.value ?? '';

  // ─────────────────────────── A. Reveal & no over-disclosure (1–5) ───────────────────────────

  test('01. static YouTube + Vimeo embeds are blocked into placeholders before consent', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await expect(page.locator('.faz-placeholder[data-faz-service="youtube"]')).toHaveCount(1);
    await expect(page.locator('.faz-placeholder[data-faz-service="vimeo"]')).toHaveCount(1);
    await expect(page.locator('iframe[src*="youtube.com/embed"]')).toHaveCount(0);
    await expect(page.locator('iframe[src*="player.vimeo.com"]')).toHaveCount(0);
    await ctx.close();
  });

  test('02. both present providers reveal a per-service toggle in the preference center', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await openPreferenceCenter(page);
    await expect(page.locator('.faz-service-toggle[data-service="youtube"]')).toHaveCount(1);
    await expect(page.locator('.faz-service-toggle[data-service="vimeo"]')).toHaveCount(1);
    expect(await page.locator('.faz-service-toggle[data-service="youtube"]').getAttribute('data-category')).toBe('marketing');
    await ctx.close();
  });

  test('03. no over-disclosure: a catalogue provider absent from the page gets no toggle', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    expect(await visibleServiceIds(page)).not.toContain('dailymotion');
    await openPreferenceCenter(page);
    await expect(page.locator('.faz-service-toggle[data-service="dailymotion"]')).toHaveCount(0);
    await ctx.close();
  });

  test('04. a JS-injected embed reveals its toggle at runtime (MutationObserver)', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(cleanUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await openPreferenceCenter(page);
    await expect(page.locator('.faz-service-toggle[data-service="dailymotion"]')).toHaveCount(0);
    await page.evaluate((src) => {
      const f = document.createElement('iframe'); f.src = src; f.width = '640'; f.height = '360';
      document.body.appendChild(f);
    }, DAILY);
    await expect.poll(() => page.locator('.faz-service-toggle[data-service="dailymotion"]').count(), { timeout: 8000 }).toBe(1);
    expect(await page.locator('.faz-service-toggle[data-service="dailymotion"]').getAttribute('data-category')).toBe('marketing');
    await ctx.close();
  });

  test('05. a page-builder lightbox video link reveals its toggle on click', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(lightboxUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await clickFirstVisible(page, ['a.et_pb_lightbox_video']);
    await expect.poll(() => page.locator('.faz-service-toggle[data-service="youtube"]').count(), { timeout: 8000 }).toBe(1);
    await ctx.close();
  });

  // ─────────────────────────── B. Decision precedence (6–10) ───────────────────────────

  test('06. accepting the Marketing category checks all its service toggles', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await openPreferenceCenter(page);
    const flipped = await page.evaluate(() => {
      const el = (document.getElementById('fazSwitchmarketing') || document.getElementById('fazCategoryDirectmarketing')) as HTMLInputElement | null;
      if (!el) return false; el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); return true;
    });
    expect(flipped).toBe(true);
    await expect(page.locator('.faz-service-toggle[data-service="youtube"]')).toBeChecked();
    await expect(page.locator('.faz-service-toggle[data-service="vimeo"]')).toBeChecked();
    await ctx.close();
  });

  test('07. rejecting the Marketing category unchecks all its service toggles (cascade)', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await openPreferenceCenter(page);
    await page.evaluate(() => {
      const el = (document.getElementById('fazSwitchmarketing') || document.getElementById('fazCategoryDirectmarketing')) as HTMLInputElement | null;
      if (el) { el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); el.checked = false; el.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    await expect(page.locator('.faz-service-toggle[data-service="youtube"]')).not.toBeChecked();
    await expect(page.locator('.faz-service-toggle[data-service="vimeo"]')).not.toBeChecked();
    await ctx.close();
  });

  test('08. granular wins: svc.youtube:yes inside a denied Marketing category allows YouTube, Vimeo stays blocked', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:yes,svc.vimeo:no`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await expect(page.locator('iframe[src*="youtube.com/embed"]')).toHaveCount(1);
    await expect(page.locator('.faz-placeholder[data-faz-service="youtube"]')).toHaveCount(0);
    await expect(page.locator('iframe[src*="player.vimeo.com"]')).toHaveCount(0);
    await expect(page.locator('.faz-placeholder[data-faz-service="vimeo"]')).toHaveCount(1);
    await ctx.close();
  });

  test('09. svc.youtube:no inside an allowed Marketing category blocks YouTube while Vimeo runs', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:yes,rev:${rev},svc.youtube:no`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await expect(page.locator('.faz-placeholder[data-faz-service="youtube"]')).toHaveCount(1);
    await expect(page.locator('iframe[src*="youtube.com/embed"]')).toHaveCount(0);
    await expect(page.locator('iframe[src*="player.vimeo.com"]')).toHaveCount(1);
    await ctx.close();
  });

  test('10. a service with no explicit decision falls back to its category (marketing:yes → both run)', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:yes,rev:${rev}`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await expect(page.locator('iframe[src*="youtube.com/embed"]')).toHaveCount(1);
    await expect(page.locator('iframe[src*="player.vimeo.com"]')).toHaveCount(1);
    await ctx.close();
  });

  // ─────────────────────────── C. Enforcement / blocking (11–14) ───────────────────────────

  test('11. reject-all keeps every embed blocked (ePrivacy: no third-party iframe loads)', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:no,rev:${rev}`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await expect(page.locator('iframe[src*="youtube.com/embed"]')).toHaveCount(0);
    await expect(page.locator('iframe[src*="player.vimeo.com"]')).toHaveCount(0);
    await expect(page.locator('.faz-placeholder[data-faz-service="youtube"]')).toHaveCount(1);
    await ctx.close();
  });

  test('12. a just-consented embed stays restored and is not re-blocked by the observer', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:yes`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    const yt = page.locator('iframe[src*="youtube.com/embed"]');
    await expect(yt).toHaveCount(1);
    // Give the runtime MutationObserver a window to (wrongly) re-block it.
    await page.waitForTimeout(1200);
    await expect(yt, 'consented embed is not re-blocked').toHaveCount(1);
    await expect(page.locator('.faz-placeholder[data-faz-service="youtube"]'), 'no placeholder re-appears for the consented service').toHaveCount(0);
    await ctx.close();
  });

  test('13. selective consent persists across a reload (youtube allowed, vimeo blocked)', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:yes,svc.vimeo:no`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await page.reload({ waitUntil: 'domcontentloaded' }); await fazReady(page);
    await expect(page.locator('iframe[src*="youtube.com/embed"]')).toHaveCount(1);
    await expect(page.locator('.faz-placeholder[data-faz-service="vimeo"]')).toHaveCount(1);
    await ctx.close();
  });

  test('14. a block-first provider injected at runtime is allowed when its svc cookie grants it (granular allow)', async ({ browser }) => {
    const ctx = await browser.newContext();
    // Marketing denied, but Dailymotion explicitly granted. An embed that is
    // injected at runtime (never scanner-detected) must be allowed by svc.* even
    // though its category is blocked — the runtime MutationObserver honours the
    // per-service grant rather than re-blocking under the denied category.
    await seedConsent(ctx, cleanUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.dailymotion:yes`);
    const page = await ctx.newPage();
    await page.goto(cleanUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await page.evaluate((src) => {
      const f = document.createElement('iframe'); f.src = src; f.width = '640'; f.height = '360';
      document.body.appendChild(f);
    }, DAILY);
    // Give the observer a window to (wrongly) block it; svc.dailymotion:yes must keep it live.
    await page.waitForTimeout(1200);
    await expect(page.locator('iframe[src*="dailymotion.com"]'), 'granted block-first embed stays live').toHaveCount(1);
    await ctx.close();
  });

  // ─────────────────────────── D. Persistence & withdrawal (15–18) ───────────────────────────

  test('15. an explicitly-decided service stays visible for withdrawal on a page WITHOUT its embed (Art. 7(3))', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, cleanUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:yes`);
    const page = await ctx.newPage();
    await page.goto(cleanUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await page.evaluate(() => {
      const w = window as unknown as { revisitFazConsent?: () => void };
      if (typeof w.revisitFazConsent === 'function') w.revisitFazConsent();
    });
    await expect(page.locator('.faz-service-toggle[data-service="youtube"]')).toHaveCount(1);
    await ctx.close();
  });

  test('16. a decided service toggle reflects its stored state on load (yes → checked, no → unchecked)', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:yes,svc.vimeo:no`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await openPreferencesAfterConsent(page); // action:yes → banner gone, use revisit
    const yt = page.locator('.faz-service-toggle[data-service="youtube"]');
    const vi = page.locator('.faz-service-toggle[data-service="vimeo"]');
    await expect(yt).toHaveCount(1);
    // Poll the checked state — _fazUpdateServiceToggleStates can settle a tick
    // after the panel opens, which a bare toBeChecked() can race under load.
    await expect.poll(() => yt.isChecked(), { timeout: 8000 }).toBe(true);
    await expect.poll(() => vi.isChecked(), { timeout: 8000 }).toBe(false);
    await ctx.close();
  });

  test('17. the consent cookie carries the svc.* token for an explicit decision', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:yes,svc.vimeo:no`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    const cookie = await consentCookie(ctx);
    expect(cookie).toContain('svc.youtube:yes');
    expect(cookie).toContain('svc.vimeo:no');
    await ctx.close();
  });

  test('18. withdrawing an accepted service (svc.youtube yes → no) re-blocks it on the next load', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:yes`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await expect(page.locator('iframe[src*="youtube.com/embed"]')).toHaveCount(1);
    // Simulate a withdrawal: rewrite the cookie to svc.youtube:no, reload.
    await ctx.clearCookies();
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:no`);
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await expect(page.locator('.faz-placeholder[data-faz-service="youtube"]')).toHaveCount(1);
    await expect(page.locator('iframe[src*="youtube.com/embed"]')).toHaveCount(0);
    await ctx.close();
  });

  // ─────────────────────────── E. Per-cookie third-party (19–21) ───────────────────────────

  test('19. per-cookie rows appear for a service when per-cookie consent is enabled', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await openPreferenceCenter(page);
    expect(await page.locator('.faz-cookie-toggle[data-service="youtube"]').count()).toBeGreaterThan(0);
    await ctx.close();
  });

  test('20. third-party per-cookie toggles are DISABLED (enforced by blocking, not deletion)', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await openPreferenceCenter(page);
    const ck = page.locator('.faz-cookie-toggle[data-service="youtube"]');
    const n = await ck.count();
    expect(n).toBeGreaterThan(0);
    for (let i = 0; i < n; i++) {
      await expect(ck.nth(i)).toBeDisabled();
      await expect(ck.nth(i)).toHaveAttribute('aria-disabled', 'true');
    }
    // the service-level toggle stays interactive
    await expect(page.locator('.faz-service-toggle[data-service="youtube"]')).not.toBeDisabled();
    await ctx.close();
  });

  test('21. the third-party note is present and linked to the disabled rows via aria-describedby', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await openPreferenceCenter(page);
    await expect(page.locator('.faz-cookie-list-note[data-faz-service="youtube"]')).toHaveCount(1);
    const noteId = await page.locator('.faz-cookie-list-note[data-faz-service="youtube"]').getAttribute('id');
    expect(noteId, 'note has a stable id').toBeTruthy();
    const describedby = await page.locator('.faz-cookie-toggle[data-service="youtube"]').first().getAttribute('aria-describedby');
    expect(describedby, 'disabled cookie toggle references the note').toBe(noteId);
    await ctx.close();
  });

  // ─────────────────────────── F. Correctness & edge cases (22–25) ───────────────────────────

  test('22. accepting a service via its placeholder records the svc.* decision (recognised id)', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    const decided = await page.evaluate(() => {
      const fz = (window as unknown as { fazcookie?: { _fazSetInStore?: (k: string, v: string) => void; _fazGetFromStore?: (k: string) => string } }).fazcookie;
      if (fz && typeof fz._fazSetInStore === 'function') fz._fazSetInStore('svc.youtube', 'yes');
      return fz && typeof fz._fazGetFromStore === 'function' ? fz._fazGetFromStore('svc.youtube') : '';
    });
    expect(decided, 'svc.youtube is a recognised service and persists in the store').toBe('yes');
    await ctx.close();
  });

  test('23. both present services are flagged third_party in the client store', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    const flags = await page.evaluate(() => {
      const svcs = (window as unknown as { _fazConfig?: { _services?: Array<{ id?: string; third_party?: boolean }> } })._fazConfig?._services ?? [];
      return Object.fromEntries(svcs.filter((s) => s.id === 'youtube' || s.id === 'vimeo').map((s) => [s.id, s.third_party]));
    });
    expect(flags).toEqual({ youtube: true, vimeo: true });
    await ctx.close();
  });

  test('24. the _serviceCatalogue is present and keyed (UI source), with marketing providers', async ({ browser }) => {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    const cat = await page.evaluate(() => {
      const c = (window as unknown as { _fazConfig?: { _serviceCatalogue?: Record<string, { category?: string }> } })._fazConfig?._serviceCatalogue;
      if (!c || typeof c !== 'object') return null;
      return { hasYoutube: !!c.youtube, hasVimeo: !!c.vimeo, youtubeCat: c.youtube?.category };
    });
    expect(cat, 'catalogue is exposed as a keyed object').not.toBeNull();
    expect(cat!.hasYoutube).toBe(true);
    expect(cat!.hasVimeo).toBe(true);
    expect(cat!.youtubeCat).toBe('marketing');
    await ctx.close();
  });

  test('25. multi-service deny-wins: an explicit svc.*:no is honoured even alongside an allowed sibling', async ({ browser }) => {
    const ctx = await browser.newContext();
    // marketing allowed, but youtube explicitly denied → youtube blocked, vimeo runs.
    await seedConsent(ctx, staticUrl, `action:yes,necessary:yes,marketing:yes,rev:${rev},svc.youtube:no,svc.vimeo:yes`);
    const page = await ctx.newPage();
    await page.goto(staticUrl, { waitUntil: 'domcontentloaded' }); await fazReady(page);
    await expect(page.locator('.faz-placeholder[data-faz-service="youtube"]')).toHaveCount(1);
    await expect(page.locator('iframe[src*="youtube.com/embed"]')).toHaveCount(0);
    await expect(page.locator('iframe[src*="player.vimeo.com"]')).toHaveCount(1);
    await ctx.close();
  });
});
