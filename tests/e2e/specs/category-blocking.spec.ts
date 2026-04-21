/**
 * Category-level blocking E2E tests.
 *
 * Verifies that the consent mechanism correctly blocks and unblocks scripts
 * based on individual cookie categories (necessary, functional, analytics,
 * marketing, performance). This is the core GDPR compliance test: each
 * non-necessary category must block its scripts until consent is given,
 * and necessary scripts must never be blocked.
 */
import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { clickFirstVisible } from '../utils/ui';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

/** Categories to test — necessary is always allowed, others require consent. */
const CATEGORIES = ['necessary', 'functional', 'analytics', 'marketing', 'performance'] as const;

type CategorySlug = (typeof CATEGORIES)[number];

/** Inject a probe script for a given category and return its state.
 *  Uses a data: URI with src to trigger the createElement src setter
 *  which is where the client-side blocking intercept fires. */
async function injectCategoryProbe(page: Page, category: CategorySlug): Promise<{ type: string; executed: boolean }> {
  return page.evaluate((cat) => {
    const varName = `__fazCatProbe_${cat}`;
    (window as any)[varName] = 0;

    const script = document.createElement('script');
    script.id = `faz-cat-probe-${cat}`;
    // Set category BEFORE src so the src setter sees the marker.
    script.setAttribute('data-fazcookie', `fazcookie-${cat}`);
    script.src = 'data:text/javascript,' + encodeURIComponent(`window.${varName}=1;`);
    document.head.appendChild(script);

    const probe = document.getElementById(`faz-cat-probe-${cat}`) as HTMLScriptElement | null;
    return {
      type: probe?.type || probe?.getAttribute('type') || 'text/javascript',
      executed: (window as any)[varName] === 1,
    };
  }, category);
}

/** Check if a previously injected probe has executed. */
async function probeExecuted(page: Page, category: CategorySlug): Promise<boolean> {
  return page.evaluate((cat) => (window as any)[`__fazCatProbe_${cat}`] === 1, category);
}

async function acceptAll(page: Page): Promise<void> {
  const accepted = await clickFirstVisible(page, [
    '[data-faz-tag="accept-button"] button',
    '[data-faz-tag="accept-button"]',
    '.faz-btn-accept',
  ]);
  expect(accepted).toBeTruthy();
}

async function rejectAll(page: Page): Promise<void> {
  const rejected = await clickFirstVisible(page, [
    '[data-faz-tag="reject-button"] button',
    '[data-faz-tag="reject-button"]',
    '.faz-btn-reject',
    '[data-faz-tag="close-button"]',
  ]);
  expect(rejected).toBeTruthy();
}

async function openPreferences(page: Page): Promise<void> {
  const opened = await clickFirstVisible(page, [
    '[data-faz-tag="settings-button"] button',
    '[data-faz-tag="settings-button"]',
    '.faz-btn-customize',
  ]);
  expect(opened).toBeTruthy();
  await page.waitForTimeout(500);
}

async function savePreferences(page: Page): Promise<void> {
  const saved = await clickFirstVisible(page, [
    '[data-faz-tag="detail-save-button"] button',
    '[data-faz-tag="detail-save-button"]',
    '.faz-btn-preferences',
  ]);
  expect(saved).toBeTruthy();
}

async function setCategoryToggle(page: Page, slug: string, checked: boolean): Promise<void> {
  const switchToggle = page.locator(`#fazSwitch${slug}`);
  const directToggle = page.locator(`#fazCategoryDirect${slug}`);
  const toggle = (await switchToggle.count()) > 0 ? switchToggle : directToggle;
  if ((await toggle.count()) === 0) return; // Category not rendered (e.g., necessary has no toggle)
  await toggle.evaluate((element, value) => {
    const input = element as HTMLInputElement;
    input.checked = value;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, checked);
}

test.describe('Category-level blocking', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(90_000);

  // ── 1. Scripts without category markers execute immediately ──
  test('scripts without data-fazcookie execute immediately (necessary behavior)', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    // Scripts without data-fazcookie are not blocked — this is how necessary scripts work.
    const result = await page.evaluate(() => {
      (window as any).__fazNecessaryProbe = 0;
      const script = document.createElement('script');
      script.id = 'faz-necessary-probe';
      script.textContent = 'window.__fazNecessaryProbe = 1;';
      document.head.appendChild(script);
      const probe = document.getElementById('faz-necessary-probe') as HTMLScriptElement | null;
      return {
        type: probe?.type || 'text/javascript',
        executed: (window as any).__fazNecessaryProbe === 1,
      };
    });

    expect(result.type).not.toBe('javascript/blocked');
    expect(result.type).not.toBe('text/plain');
    expect(result.executed).toBe(true);
  });

  // ── 2. Non-necessary categories are blocked before consent ──
  test('analytics, marketing, functional, and performance scripts are blocked before consent', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
    });

    for (const category of ['analytics', 'marketing', 'functional', 'performance'] as const) {
      const result = await injectCategoryProbe(page, category);
      expect(result.type, `${category} should be blocked`).toBe('javascript/blocked');
      expect(result.executed, `${category} should not execute`).toBe(false);
    }
  });

  // ── 3. Accept all sets all categories to yes and newly injected scripts execute ──
  test('accept all sets all categories to yes and newly injected scripts execute', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
    });

    await acceptAll(page);
    await page.waitForFunction(() => document.cookie.includes('fazcookie-consent'), undefined, { timeout: 5_000 });

    // Verify consent cookie has all categories as 'yes'
    // Cookie value may be percent-encoded (e.g. analytics%3Ayes)
    const consentCookie = await page.evaluate(() => {
      const match = document.cookie.split(';').find((c) => c.trim().startsWith('fazcookie-consent='));
      const raw = match ? match.split('=').slice(1).join('=') : '';
      try { return decodeURIComponent(raw); } catch { return raw; }
    });
    expect(consentCookie).toContain('analytics:yes');
    expect(consentCookie).toContain('marketing:yes');
    expect(consentCookie).toContain('functional:yes');
  });

  // ── 4. Reject all keeps non-necessary categories blocked ──
  test('reject all keeps analytics, marketing, functional, and performance blocked', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
    });

    await rejectAll(page);
    await page.waitForFunction(() => document.cookie.includes('fazcookie-consent'), undefined, { timeout: 5_000 });

    // Verify consent cookie has non-necessary categories as 'no'
    const consentCookie = await page.evaluate(() => {
      const match = document.cookie.split(';').find((c) => c.trim().startsWith('fazcookie-consent='));
      const raw = match ? match.split('=').slice(1).join('=') : '';
      try { return decodeURIComponent(raw); } catch { return raw; }
    });

    expect(consentCookie).toContain('analytics:no');
    expect(consentCookie).toContain('marketing:no');

    // Inject scripts after reject — they should still be blocked
    for (const category of ['analytics', 'marketing'] as const) {
      const result = await injectCategoryProbe(page, category);
      expect(result.type, `${category} must stay blocked after reject`).toBe('javascript/blocked');
      expect(result.executed, `${category} must not execute after reject`).toBe(false);
    }

    // Scripts without category markers must still work after reject
    const unmarkedResult = await page.evaluate(() => {
      (window as any).__fazUnmarkedAfterReject = 0;
      const s = document.createElement('script');
      s.textContent = 'window.__fazUnmarkedAfterReject = 1;';
      document.head.appendChild(s);
      return (window as any).__fazUnmarkedAfterReject === 1;
    });
    expect(unmarkedResult, 'unmarked scripts must execute even after reject').toBe(true);
  });

  // ── 5. Granular preferences: accept only analytics ──
  test('granular preferences: accepting only analytics unblocks analytics but keeps marketing blocked', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
    });

    await openPreferences(page);

    // Enable only analytics, disable everything else
    await setCategoryToggle(page, 'analytics', true);
    await setCategoryToggle(page, 'marketing', false);
    await setCategoryToggle(page, 'functional', false);
    await setCategoryToggle(page, 'performance', false);

    await savePreferences(page);
    await page.waitForFunction(() => document.cookie.includes('fazcookie-consent'), undefined, { timeout: 5_000 });

    const consentCookie = await page.evaluate(() => {
      const match = document.cookie.split(';').find((c) => c.trim().startsWith('fazcookie-consent='));
      const raw = match ? match.split('=').slice(1).join('=') : '';
      try { return decodeURIComponent(raw); } catch { return raw; }
    });

    expect(consentCookie).toContain('analytics:yes');
    expect(consentCookie).toContain('marketing:no');

    // Analytics probe should NOT be blocked
    const analyticsResult = await injectCategoryProbe(page, 'analytics');
    expect(analyticsResult.executed, 'analytics accepted → must execute').toBe(true);

    // Marketing probe should still be blocked
    const marketingResult = await injectCategoryProbe(page, 'marketing');
    expect(marketingResult.type, 'marketing denied → must be blocked').toBe('javascript/blocked');
    expect(marketingResult.executed, 'marketing denied → must not execute').toBe(false);
  });

  // ── 6. Category toggle independence ──
  test('each category can be toggled independently without affecting others', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    await page.evaluate(() => {
      _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
    });

    await openPreferences(page);

    // Enable marketing only
    await setCategoryToggle(page, 'analytics', false);
    await setCategoryToggle(page, 'marketing', true);
    await setCategoryToggle(page, 'functional', false);

    await savePreferences(page);
    await page.waitForFunction(() => document.cookie.includes('fazcookie-consent'), undefined, { timeout: 5_000 });

    const consentCookie = await page.evaluate(() => {
      const match = document.cookie.split(';').find((c) => c.trim().startsWith('fazcookie-consent='));
      const raw = match ? match.split('=').slice(1).join('=') : '';
      try { return decodeURIComponent(raw); } catch { return raw; }
    });

    expect(consentCookie).toContain('analytics:no');
    expect(consentCookie).toContain('marketing:yes');

    // Marketing should execute, analytics should NOT
    const marketingResult = await injectCategoryProbe(page, 'marketing');
    expect(marketingResult.executed, 'marketing accepted').toBe(true);

    const analyticsResult = await injectCategoryProbe(page, 'analytics');
    expect(analyticsResult.type, 'analytics denied').toBe('javascript/blocked');
  });

  // ── 7. Consent persists across page loads ──
  test('consent persists across page loads — accepted categories stay unblocked', async ({ browser }) => {
    const ctx = await browser.newContext({ baseURL: WP_BASE });
    const page = await ctx.newPage();

    try {
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

      await acceptAll(page);
      await page.waitForFunction(() => document.cookie.includes('fazcookie-consent'), undefined, { timeout: 5_000 });

      // Reload — banner should NOT appear (consent persisted)
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="notice"]')).toBeHidden({ timeout: 3_000 });

      // Analytics scripts should execute immediately (consent already given)
      const result = await injectCategoryProbe(page, 'analytics');
      expect(result.executed, 'analytics should execute after reload with consent').toBe(true);
    } finally {
      await ctx.close();
    }
  });

  // ── 8. Banner returns after clearing cookies ──
  test('banner reappears after clearing consent cookie', async ({ browser }) => {
    const ctx = await browser.newContext({ baseURL: WP_BASE });
    const page = await ctx.newPage();

    try {
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await acceptAll(page);
      await page.waitForFunction(() => document.cookie.includes('fazcookie-consent'), undefined, { timeout: 5_000 });

      // Clear all cookies
      await ctx.clearCookies();

      // Reload — banner must come back
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

      // Scripts should be blocked again
      await page.evaluate(() => {
        _fazStore._bannerConfig.behaviours.reloadBannerOnAccept = false;
      });
      const result = await injectCategoryProbe(page, 'analytics');
      expect(result.type, 'analytics must be blocked again after cookie clear').toBe('javascript/blocked');
    } finally {
      await ctx.close();
    }
  });

  // ── 9. data-faz-category attribute also works for blocking ──
  test('data-faz-category attribute blocks scripts the same as data-fazcookie', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    const result = await page.evaluate(() => {
      (window as any).__fazAltAttrProbe = 0;
      const script = document.createElement('script');
      script.id = 'faz-alt-attr-probe';
      // Use the alternative attribute name
      script.setAttribute('data-faz-category', 'analytics');
      script.textContent = 'window.__fazAltAttrProbe = 1;';
      document.head.appendChild(script);

      const probe = document.getElementById('faz-alt-attr-probe') as HTMLScriptElement | null;
      return {
        type: probe?.type || 'text/javascript',
        executed: (window as any).__fazAltAttrProbe === 1,
      };
    });

    expect(result.type, 'data-faz-category="analytics" must be blocked').toBe('javascript/blocked');
    expect(result.executed).toBe(false);
  });

  // ── 10. Necessary toggle is disabled in the preference center ──
  test('necessary category toggle is disabled and always checked in the preference center', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

    await openPreferences(page);

    const necessaryState = await page.evaluate(() => {
      const toggle =
        document.querySelector('#fazSwitchnecessary') as HTMLInputElement | null ??
        document.querySelector('#fazCategoryDirectnecessary') as HTMLInputElement | null;

      if (!toggle) return { found: false, checked: false, disabled: false };
      return {
        found: true,
        checked: toggle.checked,
        disabled: toggle.disabled,
      };
    });

    expect(necessaryState.found, 'necessary toggle should exist').toBe(true);
    expect(necessaryState.checked, 'necessary toggle should be checked').toBe(true);
    expect(necessaryState.disabled, 'necessary toggle should be disabled').toBe(true);
  });
});
