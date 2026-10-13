import { expect, test } from '../fixtures/wp-fixture';
import { clickFirstVisible } from '../utils/ui';
import {
  deactivatePluginsExcept,
  listActivePluginFiles,
  restoreActivePluginFiles,
} from '../utils/wp-env';

test.describe('Frontend consent flow', () => {
  let suiteActivePluginFiles: string[] | null = null;

  // deactivatePluginsExcept() rewrites active_plugins and nothing puts it
  // back: every plugin this spec switches off stays off for the rest of the
  // run. Both this file and img-tile-blocking.spec.ts sit early in the
  // alphabet, so the specs that come later found the site stripped — the
  // faz-e2e-audit-lab fixture among them, and the third-party plugins whose
  // absence turned pr-regression's Koko Analytics check and
  // smash-balloon-inert-rule into silent skips. Save the list before
  // narrowing it and restore it afterwards.
  test.beforeAll(() => {
    suiteActivePluginFiles = listActivePluginFiles();
    deactivatePluginsExcept(['faz-cookie-manager']);
  });

  test.afterAll(() => {
    if (suiteActivePluginFiles !== null) {
      restoreActivePluginFiles(suiteActivePluginFiles);
      suiteActivePluginFiles = null;
    }
  });

  test('shows banner on first visit and blocks non-technical cookies before consent', async ({ page, getNonTechnicalCookies }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeVisible();

    const nonTechnical = await getNonTechnicalCookies(page.context());
    expect(nonTechnical, `Unexpected non-technical cookies before consent: ${JSON.stringify(nonTechnical)}`).toHaveLength(0);
  });

  test('Accept all persists consent and hides banner after reload', async ({ page, getConsentCookie, parseConsentCookie }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const accepted = await clickFirstVisible(page, [
      '[data-faz-tag="accept-button"] button',
      '[data-faz-tag="accept-button"]',
      '.faz-btn-accept',
    ]);
    expect(accepted).toBeTruthy();

    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeHidden({ timeout: 10_000 });

    const consent = await getConsentCookie(page.context());
    expect(consent).toBeDefined();
    const parsed = parseConsentCookie(consent!.value);
    expect(parsed.consent).toBe('yes');
    expect(parsed.necessary).toBe('yes');

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(notice).toBeHidden({ timeout: 10_000 });
  });

  test('Reject all keeps optional categories disabled', async ({ page, getConsentCookie, parseConsentCookie }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const rejected = await clickFirstVisible(page, [
      '[data-faz-tag="reject-button"] button',
      '[data-faz-tag="reject-button"]',
      '.faz-btn-reject',
      '[data-faz-tag="close-button"]',
    ]);
    expect(rejected).toBeTruthy();

    const consent = await getConsentCookie(page.context());
    expect(consent).toBeDefined();

    const parsed = parseConsentCookie(consent!.value);
    expect(parsed.necessary).toBe('yes');

    const optionalKeys = Object.keys(parsed).filter((key) => !['consentid', 'consent', 'action', 'necessary'].includes(key));
    for (const key of optionalKeys) {
      expect(parsed[key], `Category ${key} should not be granted after reject`).not.toBe('yes');
    }
  });

});
