import { test, expect } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { clickFirstVisible } from '../utils/ui';
import { upsertPage, wp } from '../utils/wp-env';

/**
 * Footer legal links — opt-in, consent-invariant, cache-safe.
 *
 * The feature prints a small <nav class="faz-legal-links"> on wp_footer listing
 * the published pages the admin selected in Settings. The contract this suite
 * defends:
 *
 *   1. OFF by default / when disabled → the nav is absent entirely.
 *   2. ON with a selected page → the nav renders with that page's link, using
 *      the custom label when one is stored.
 *   3. The markup is BYTE-IDENTICAL before and after the visitor consents.
 *      That is the whole reason the renderer is forbidden from reading
 *      $_COOKIE / consent state / geo: Cache Compatibility Mode promises one
 *      cached variant per URL, and a footer that varied by consent would be the
 *      first exception to it.
 *
 * Serial, and afterAll restores whatever legal_links value the site had.
 */

const BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const FIXTURE_SLUG = 'faz-legal-links-fixture';

type FazSettings = Record<string, unknown>;

async function getAdminNonce(page: Page): Promise<string> {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  return page.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
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

test.describe('Footer legal links', () => {
  test.describe.configure({ mode: 'serial' });

  let admin: Page;
  let nonce = '';
  let originalLegalLinks: Record<string, unknown> = {};
  let fixtureId = 0;

  test.beforeAll(async ({ browser, loginAsAdmin }) => {
    fixtureId = upsertPage(FIXTURE_SLUG, 'FAZ Legal Links Fixture', '<p>Legal links fixture page.</p>');
    expect(fixtureId).toBeGreaterThan(0);

    admin = await browser.newPage();
    await loginAsAdmin(admin);
    await admin.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    nonce = await getAdminNonce(admin);
    expect(nonce.length).toBeGreaterThan(0);

    const current = await getSettings(admin, nonce);
    originalLegalLinks = { ...(current.legal_links as Record<string, unknown> | undefined) };
  });

  test.afterAll(async () => {
    if (nonce) {
      await postSettings(admin, nonce, { legal_links: originalLegalLinks });
    }
    if (fixtureId) {
      try {
        wp(['post', 'delete', String(fixtureId), '--force']);
      } catch {
        // Best effort — a leftover fixture page harms nothing.
      }
    }
    await admin?.close();
  });

  test('renders the nav when enabled, and its HTML does not change with consent', async ({ browser }) => {
    await postSettings(admin, nonce, {
      legal_links: { enabled: true, link_items: [{ page_id: fixtureId, label: 'Privacy stuff' }] },
    });

    const context = await browser.newContext();
    try {
      await context.clearCookies();
      const page = await context.newPage();
      await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });

      const nav = page.locator('nav.faz-legal-links');
      await expect(nav).toHaveCount(1);
      const link = nav.locator('a', { hasText: 'Privacy stuff' });
      await expect(link).toHaveCount(1);
      await expect(link).toHaveAttribute('href', new RegExp(FIXTURE_SLUG));

      // Snapshot the markup BEFORE any consent decision exists.
      const before = await nav.evaluate((el) => el.outerHTML);

      const accepted = await clickFirstVisible(page, [
        '[data-faz-tag="accept-button"] button',
        '[data-faz-tag="accept-button"]',
        '.faz-btn-accept',
      ]);
      expect(accepted, 'accept button not found — cannot verify consent invariance').toBeTruthy();

      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(nav).toHaveCount(1);
      const after = await nav.evaluate((el) => el.outerHTML);

      expect(after, 'footer legal links must be byte-identical before and after consent').toBe(before);
    } finally {
      await context.close();
    }
  });

  test('renders nothing when the toggle is off', async ({ browser }) => {
    await postSettings(admin, nonce, {
      legal_links: { enabled: false, link_items: [{ page_id: fixtureId, label: 'Privacy stuff' }] },
    });

    const context = await browser.newContext();
    try {
      await context.clearCookies();
      const page = await context.newPage();
      await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('nav.faz-legal-links')).toHaveCount(0);
    } finally {
      await context.close();
    }
  });

  test('renders nothing when enabled with no pages selected (no empty nav)', async ({ browser }) => {
    await postSettings(admin, nonce, { legal_links: { enabled: true, link_items: [] } });

    const context = await browser.newContext();
    try {
      await context.clearCookies();
      const page = await context.newPage();
      await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('nav.faz-legal-links')).toHaveCount(0);
    } finally {
      await context.close();
    }
  });
});
