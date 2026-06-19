import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';

/**
 * Issue #135 — granular per-cookie consent toggles.
 *
 * Enables banner_control.per_service_consent + per_cookie_consent via the REST
 * settings endpoint, then drives the frontend preference center to verify the
 * nested per-cookie toggles render, sync with their category, persist as
 * override-only `ck.<service>.<cookie-name>` entries, and shred a denied cookie when
 * the visitor saves. Settings are restored afterwards.
 */

type FazSettings = Record<string, unknown>;

/** Read the REST nonce exposed to the admin page via window.fazConfig. */
async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => window.fazConfig?.api?.nonce ?? '');
}

/** Fetch the current plugin settings object via the REST settings endpoint. */
async function getSettings(page: Page, nonce: string): Promise<FazSettings> {
  const res = await page.request.get('/?rest_route=/faz/v1/settings/', {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.status()).toBe(200);
  return (await res.json()) as FazSettings;
}

/** Persist a (partial) settings payload via the REST settings endpoint. */
async function postSettings(page: Page, nonce: string, payload: FazSettings): Promise<void> {
  const res = await page.request.post('/?rest_route=/faz/v1/settings/', {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: payload,
  });
  expect(res.status(), `settings update status ${res.status()}`).toBe(200);
}

// Re-enabled once per-cookie consent gained server-side ck.* enforcement
// (Frontend::shred_non_consented_cookies reads the same ck.* tokens via
// resolve_service_cookie_decision) and its admin toggle was ungated.
test.describe('Per-cookie consent (issue #135)', () => {
  test.describe.configure({ mode: 'serial' });

  let original: FazSettings | null = null;

  test('renders, syncs, persists and shreds per-cookie consent', async ({ page, browser, loginAsAdmin }) => {
    // --- enable the feature via REST ---
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);
    expect(nonce.length).toBeGreaterThan(0);

    original = await getSettings(page, nonce);
    const bannerControl = { ...(original.banner_control as Record<string, unknown> | undefined) };
    await postSettings(page, nonce, {
      banner_control: { ...bannerControl, per_service_consent: true, per_cookie_consent: true },
    });

    // --- fresh visitor context (no consent cookie) ---
    const ctx = await browser.newContext();
    const fp = await ctx.newPage();
    await fp.goto('/', { waitUntil: 'domcontentloaded' });
    await fp.waitForTimeout(1000);

    // open preference center
    await fp.evaluate(() => {
      const re = /custom|setting|preferen|gestisci|impost/i;
      const b = Array.from(document.querySelectorAll('button,a')).find(
        (x) => re.test(x.textContent || '') || re.test(x.getAttribute('aria-label') || ''),
      ) as HTMLElement | undefined;
      if (b) b.click();
    });
    await fp.waitForTimeout(600);
    await fp.evaluate(() =>
      document.querySelectorAll('[class*="accordion-header"]').forEach((h) => (h as HTMLElement).click()),
    );
    await fp.waitForTimeout(400);

    // nested per-cookie toggles present
    const counts = await fp.evaluate(() => ({
      svc: document.querySelectorAll('.faz-service-toggle').length,
      ck: document.querySelectorAll('.faz-cookie-toggle').length,
      style: !!document.getElementById('faz-cookie-toggle-styles'),
    }));
    expect(counts.svc).toBeGreaterThan(0);
    expect(counts.ck).toBeGreaterThan(0);
    expect(counts.style).toBe(true);

    // enable a category that has cookies; the cascade checks nested cookies
    const picked = await fp.evaluate(() => {
      // Pick the first per-cookie toggle whose cookie is actually SHREDDABLE.
      // Always-allowed / user-whitelisted cookies (e.g. the Stripe payment
      // gateway's __stripe_mid) are exempt from shredding by design — removing
      // them would break payment flows — so a per-cookie denial on one can
      // never satisfy the "denied cookie is shredded on save" assertion below.
      // Test environments that expose a gateway service render its toggle
      // first, so an unfiltered querySelector would pick the one cookie the
      // shredder must NOT touch.
      const toggles = Array.from(document.querySelectorAll('.faz-cookie-toggle'));
      const isWhitelisted = (window as unknown as { _fazIsCookieWhitelisted?: (n: string) => boolean })._fazIsCookieWhitelisted;
      const ck =
        toggles.find((el) => {
          const nm = el.getAttribute('data-cookie-name') || '';
          return nm && !(typeof isWhitelisted === 'function' && isWhitelisted(nm));
        }) || toggles[0];
      if (!ck) return null;
      const cat = ck.getAttribute('data-category') || '';
      const svc = ck.getAttribute('data-service') || '';
      const idx = ck.getAttribute('data-cookie-index') || '';
      const name = ck.getAttribute('data-cookie-name') || '';
      ['fazSwitch', 'fazCategoryDirect'].forEach((p) => {
        const el = document.getElementById(p + cat) as HTMLInputElement | null;
        if (el) {
          el.checked = true;
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
      return { cat, svc, idx, name };
    });
    expect(picked, 'a service with cookies exists').not.toBeNull();
    const target = picked as { cat: string; svc: string; idx: string; name: string };
    await fp.waitForTimeout(300);

    const sync = await fp.evaluate((p) => {
      const els = Array.from(
        document.querySelectorAll('.faz-cookie-toggle[data-category="' + p.cat + '"]'),
      ) as HTMLInputElement[];
      return { total: els.length, checked: els.filter((e) => e.checked).length };
    }, target);
    expect(sync.total).toBeGreaterThan(0);
    expect(sync.checked, 'category→cookie sync checks every nested cookie').toBe(sync.total);

    // opt one cookie out, plant it, save
    await fp.evaluate((p) => {
      const t = document.querySelector(
        '.faz-cookie-toggle[data-service="' + p.svc + '"][data-cookie-index="' + p.idx + '"]',
      ) as HTMLInputElement | null;
      if (t) {
        t.checked = false;
        t.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, target);
    await fp.evaluate((p) => {
      document.cookie = p.name + '=planted; path=/';
    }, target);

    await fp.evaluate(() => {
      const re = /save|salva|conferma|my choices|preferenz|selezione/i;
      const b = Array.from(document.querySelectorAll('button,a')).find((x) =>
        re.test(x.textContent || ''),
      ) as HTMLElement | undefined;
      if (b) b.click();
    });
    await fp.waitForTimeout(900);

    // persistence (override-only) + category accepted
    const cookies = await ctx.cookies();
    const consent = cookies.find((c) => c.name === 'fazcookie-consent');
    const decoded = consent ? decodeURIComponent(consent.value) : '';
    expect(decoded).toContain(target.cat + ':yes');
    expect(decoded).toContain('ck.' + target.svc + '.' + target.name + ':no');

    // enforcement: the denied cookie was shredded inside the save action
    const stillThere = await fp.evaluate((p) => document.cookie.indexOf(p.name + '=') !== -1, target);
    expect(stillThere, 'denied cookie is shredded on save').toBe(false);

    // server-side enforcement persists across requests: re-plant the denied
    // cookie so the browser sends it on the next request, reload, and confirm
    // the send_headers shredder removes it again while the ck.*:no choice holds.
    await fp.evaluate((p) => {
      document.cookie = p.name + '=replanted; path=/';
    }, target);
    await fp.goto('/', { waitUntil: 'domcontentloaded' });
    await fp.waitForTimeout(900);
    const afterReload = await fp.evaluate((p) => document.cookie.indexOf(p.name + '=') !== -1, target);
    expect(afterReload, 'denied cookie stays shredded across a reload').toBe(false);
    const cookies2 = await ctx.cookies();
    const consent2 = cookies2.find((c) => c.name === 'fazcookie-consent');
    const decoded2 = consent2 ? decodeURIComponent(consent2.value) : '';
    expect(decoded2, 'per-cookie denial persists after reload').toContain(
      'ck.' + target.svc + '.' + target.name + ':no',
    );

    await ctx.close();

    // restore the original banner_control settings (admin page + nonce still valid)
    if (original?.banner_control) {
      await postSettings(page, nonce, { banner_control: original.banner_control as FazSettings });
    }
  });
});
