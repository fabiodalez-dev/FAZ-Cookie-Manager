import { test, expect } from '../fixtures/wp-fixture';
import type { BrowserContext, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { clickFirstVisible } from '../utils/ui';
import { WP_PATH } from '../utils/wp-env';

/**
 * Per-service consent — EDGE-CASE integration suite (reusable).
 *
 * Fills gaps the existing per-service specs don't cover, with two "particular
 * service" scenarios that exercise real server blocking + runtime reveal +
 * enforcement end-to-end:
 *
 *   A. youtube-nocookie.com — the privacy-friendly YouTube domain. It is a
 *      DISTINCT pattern ("youtube-nocookie.com/embed") that must still resolve
 *      to the single "youtube" service: blocked into a placeholder, revealed as
 *      one toggle, and unblocked by a youtube consent. A boundary/alias bug here
 *      would silently leak the embed (the youtube.com vs youtube-nocookie.com
 *      boundary is unit-guarded in test-per-service-gateway-boundary-php.php and
 *      tests/unit/js/per-service-boundary-ckkey.test.mjs; this is the live proof).
 *
 *   B. Multiple embeds of the SAME provider on one page — each iframe must be
 *      blocked independently (not just the first), reveal a single shared
 *      toggle, and ALL unblock together when the service is consented. A
 *      "first-match-only" blocking bug would leave later embeds live before
 *      consent.
 *
 * Determinism: every test seeds the `fazcookie-consent` cookie (addCookies)
 * with the live revision and asserts the resulting DOM — no flaky UI save flow.
 * Self-contained: pages are created in beforeAll and deleted in afterAll;
 * per_service_consent is enabled in beforeAll and the original settings restored
 * in afterAll. Safe to run in isolation or as part of the full suite.
 */

const NOCOOKIE = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';
const YT_A = 'https://www.youtube.com/embed/M7lc1UVf-VE';
const YT_B = 'https://www.youtube.com/embed/dQw4w9WgXcQ';

function wp(args: string[]): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();
}

function wpEval(php: string): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, 'eval', php], { encoding: 'utf8' }).trim();
}

/** `wp post create --porcelain` should print only the new ID; fail loudly otherwise. */
function wpCreatePostId(args: string[]): string {
  const rawId = wp(args);
  if (!/^\d+$/.test(rawId)) {
    throw new Error(`Expected a numeric post ID from "wp post create", got: ${JSON.stringify(rawId)}`);
  }
  return rawId;
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

async function seedConsent(ctx: BrowserContext, url: string, value: string): Promise<void> {
  await ctx.addCookies([{ name: 'fazcookie-consent', value, url, sameSite: 'Lax' }]);
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

async function waitFazReady(page: Page): Promise<void> {
  await page.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 8000 });
}

test.describe('Per-service consent — edge cases (nocookie alias + multi-embed)', () => {
  test.skip(!WP_PATH, 'requires WP_PATH to toggle settings + seed pages via wp-cli');
  test.describe.configure({ mode: 'serial' });

  let original: FazSettings | null = null;
  let nonce = '';
  let rev = '1';
  let nocookieUrl = '';
  let nocookiePostId = '';
  let multiUrl = '';
  let multiPostId = '';

  test.beforeAll(async ({ browser, loginAsAdmin }) => {
    const page = await browser.newPage();
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    nonce = await getAdminNonce(page);
    expect(nonce.length).toBeGreaterThan(0);
    original = await getSettings(page, nonce);
    const bc = { ...(original.banner_control as Record<string, unknown> | undefined) };
    await postSettings(page, nonce, { banner_control: { ...bc, per_service_consent: true } });
    await page.close();

    rev = wpEval('echo faz_get_consent_revision();').trim() || '1';

    // A page with a single youtube-nocookie.com embed.
    nocookiePostId = wpCreatePostId([
      'post', 'create', '--post_type=page', '--post_status=publish',
      '--post_title=FAZ E2E nocookie embed',
      `--post_content=<iframe width="560" height="315" src="${NOCOOKIE}" title="YouTube nocookie"></iframe>`,
      '--porcelain',
    ]);
    nocookieUrl = wp(['post', 'get', nocookiePostId, '--field=url']);

    // A page with TWO distinct youtube.com embeds (same provider).
    multiPostId = wpCreatePostId([
      'post', 'create', '--post_type=page', '--post_status=publish',
      '--post_title=FAZ E2E multi youtube',
      `--post_content=<iframe width="560" height="315" src="${YT_A}" title="A"></iframe><p>between</p><iframe width="560" height="315" src="${YT_B}" title="B"></iframe>`,
      '--porcelain',
    ]);
    multiUrl = wp(['post', 'get', multiPostId, '--field=url']);

    // Warm both pages so the first real assertion isn't racing a cold cache.
    const warm = await browser.newContext();
    const wpg = await warm.newPage();
    for (const u of [nocookieUrl, multiUrl]) {
      await wpg.goto(u, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await wpg.waitForFunction(() => document.documentElement.classList.contains('faz-ready'), { timeout: 20000 }).catch(() => {});
    }
    await warm.close();
  });

  test.afterAll(async ({ browser, loginAsAdmin }) => {
    if (nocookiePostId) wp(['post', 'delete', nocookiePostId, '--force']);
    if (multiPostId) wp(['post', 'delete', multiPostId, '--force']);
    if (!original?.banner_control) return;
    const page = await browser.newPage();
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    const n = await getAdminNonce(page);
    await postSettings(page, n, { banner_control: original.banner_control as FazSettings });
    await page.close();
  });

  // ── A. youtube-nocookie.com alias ──────────────────────────────────────

  test('A1 nocookie embed is blocked into a placeholder before consent', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(nocookieUrl, { waitUntil: 'domcontentloaded' });
    await waitFazReady(page);
    // The live nocookie iframe is gone, replaced by a youtube placeholder.
    expect(await page.locator('iframe[src*="youtube-nocookie.com"]').count()).toBe(0);
    expect(await page.locator('.faz-placeholder[data-faz-service="youtube"]').count()).toBeGreaterThan(0);
    await ctx.close();
  });

  test('A2 nocookie embed reveals exactly one youtube toggle in the preference center', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(nocookieUrl, { waitUntil: 'domcontentloaded' });
    await waitFazReady(page);
    await openPreferenceCenter(page);
    // The distinct nocookie pattern resolves to the single "youtube" service.
    const inServices = await page.evaluate(
      () => (window as unknown as { _fazConfig?: { _services?: Array<{ id?: string }> } })._fazConfig?._services?.some((s) => s && s.id === 'youtube') ?? false,
    );
    expect(inServices).toBe(true);
    expect(await page.locator('.faz-service-toggle[data-service="youtube"]').count()).toBe(1);
    await ctx.close();
  });

  test('A3 nocookie embed goes live when youtube is consented (marketing:yes)', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, nocookieUrl, `action:yes,necessary:yes,marketing:yes,rev:${rev}`);
    const page = await ctx.newPage();
    await page.goto(nocookieUrl, { waitUntil: 'domcontentloaded' });
    await waitFazReady(page);
    await expect.poll(() => page.locator('iframe[src*="youtube-nocookie.com"]').count(), { timeout: 8000 }).toBeGreaterThan(0);
    await ctx.close();
  });

  test('A4 nocookie embed stays blocked under reject-all (marketing:no)', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, nocookieUrl, `action:yes,necessary:yes,marketing:no,rev:${rev}`);
    const page = await ctx.newPage();
    await page.goto(nocookieUrl, { waitUntil: 'domcontentloaded' });
    await waitFazReady(page);
    expect(await page.locator('iframe[src*="youtube-nocookie.com"]').count()).toBe(0);
    expect(await page.locator('.faz-placeholder[data-faz-service="youtube"]').count()).toBeGreaterThan(0);
    await ctx.close();
  });

  test('A5 a granular svc.youtube:yes unblocks the nocookie embed under a denied category', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, nocookieUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:yes`);
    const page = await ctx.newPage();
    await page.goto(nocookieUrl, { waitUntil: 'domcontentloaded' });
    await waitFazReady(page);
    await expect.poll(() => page.locator('iframe[src*="youtube-nocookie.com"]').count(), { timeout: 8000 }).toBeGreaterThan(0);
    await ctx.close();
  });

  // ── B. Multiple embeds of the same provider ────────────────────────────

  test('B1 both same-provider embeds are blocked independently before consent', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(multiUrl, { waitUntil: 'domcontentloaded' });
    await waitFazReady(page);
    // Neither live iframe survives; BOTH are placeholdered (not just the first).
    expect(await page.locator('iframe[src*="youtube.com/embed"]').count()).toBe(0);
    expect(await page.locator('.faz-placeholder[data-faz-service="youtube"]').count()).toBe(2);
    await ctx.close();
  });

  test('B2 the two embeds reveal a single shared youtube toggle (no duplicate)', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(multiUrl, { waitUntil: 'domcontentloaded' });
    await waitFazReady(page);
    await openPreferenceCenter(page);
    // One service, one toggle — two embeds must not produce two toggles.
    expect(await page.locator('.faz-service-toggle[data-service="youtube"]').count()).toBe(1);
    await ctx.close();
  });

  test('B3 both same-provider embeds go live together when youtube is consented', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, multiUrl, `action:yes,necessary:yes,marketing:no,rev:${rev},svc.youtube:yes`);
    const page = await ctx.newPage();
    await page.goto(multiUrl, { waitUntil: 'domcontentloaded' });
    await waitFazReady(page);
    // ALL instances unblock — a "first-match-only" restore bug would leave one blocked.
    await expect.poll(() => page.locator('iframe[src*="youtube.com/embed"]').count(), { timeout: 8000 }).toBe(2);
    await ctx.close();
  });

  test('B4 both same-provider embeds stay blocked under reject-all', async ({ browser }) => {
    const ctx = await browser.newContext();
    await seedConsent(ctx, multiUrl, `action:yes,necessary:yes,marketing:no,rev:${rev}`);
    const page = await ctx.newPage();
    await page.goto(multiUrl, { waitUntil: 'domcontentloaded' });
    await waitFazReady(page);
    expect(await page.locator('iframe[src*="youtube.com/embed"]').count()).toBe(0);
    expect(await page.locator('.faz-placeholder[data-faz-service="youtube"]').count()).toBe(2);
    await ctx.close();
  });
});
