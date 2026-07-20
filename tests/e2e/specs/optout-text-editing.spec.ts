/**
 * Issue #187 — the "Opt-out Preferences" (Do Not Sell) modal text is editable
 * from the admin.
 *
 * Before this fix the opt-out popup title/description/toggle-label were only ever
 * the bundled defaults: no admin field wrote optoutPopup.elements.*, so a CCPA /
 * US State Laws publisher could not change the copy a visitor sees after clicking
 * "Do Not Sell or Share My Personal Information". This spec proves the new
 * "Opt-out (Do Not Sell) Text" card on the Preference Center tab:
 *   1. is hidden for a GDPR banner and revealed for CCPA / Both (law-gated), and
 *   2. round-trips its description through a save (store → persist → populate).
 *
 * Restores the banner law to gdpr in afterAll so the shared faz-test banner is
 * left in its default (GDPR) state for the other suites.
 */
import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';

const MARKER = 'Issue187 custom opt-out copy';

async function openBanner(page: Page, wpBaseURL: string): Promise<void> {
  await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForSelector('#faz-b-law', { timeout: 15_000 });
  // loadBanner() + TinyMCE init are async after DOM ready.
  await page.waitForTimeout(2500);
}

async function setLaw(page: Page, value: 'gdpr' | 'ccpa' | 'gdpr_ccpa'): Promise<void> {
  // #faz-b-law lives on the General tab; it is not actionable while another tab
  // panel is showing, so make General active first.
  await page.click('.faz-tab[data-tab="general"]');
  await page.waitForTimeout(300);
  await page.selectOption('#faz-b-law', value);
  await page.dispatchEvent('#faz-b-law', 'change');
  await page.waitForTimeout(400);
}

async function gotoPreferencesTab(page: Page): Promise<void> {
  await page.click('.faz-tab[data-tab="preferences"]');
  await page.waitForTimeout(600);
}

test.describe.configure({ mode: 'serial' });

test.describe('Opt-out (Do Not Sell) text is editable (#187)', () => {
  test.afterAll(async ({ browser, wpBaseURL, loginAsAdmin }) => {
    // Leave the shared banner on the default GDPR law + clear the marker copy.
    const page = await browser.newPage();
    try {
      await loginAsAdmin(page);
      await openBanner(page, wpBaseURL);
      await setLaw(page, 'gdpr');
      await gotoPreferencesTab(page);
      await page.evaluate(() => {
        const w = window as unknown as { tinyMCE?: { get(id: string): { setContent(c: string): void } | null } };
        w.tinyMCE?.get('faz-b-optout-desc')?.setContent('');
        const ta = document.getElementById('faz-b-optout-desc') as HTMLTextAreaElement | null;
        if (ta) ta.value = '';
      });
      await page.click('#faz-b-save');
      await page.waitForTimeout(2500);
    } finally {
      await page.close();
    }
  });

  test('the opt-out text card is law-gated (hidden for GDPR, shown for Both)', async ({
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);
    await openBanner(page, wpBaseURL);

    await setLaw(page, 'gdpr');
    await gotoPreferencesTab(page);
    await expect(page.locator('#faz-optout-text-card')).toBeHidden();

    await setLaw(page, 'gdpr_ccpa');
    // setLaw() activates the General tab; the card lives on the Preference
    // Center tab, so return there before asserting it is now revealed.
    await gotoPreferencesTab(page);
    await expect(page.locator('#faz-optout-text-card')).toBeVisible();
    // The title + toggle-label fields live inside the revealed card.
    await expect(page.locator('#faz-b-optout-title')).toBeVisible();
  });

  test('the opt-out description round-trips through a save', async ({ page, wpBaseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await openBanner(page, wpBaseURL);
    await setLaw(page, 'gdpr_ccpa');
    await gotoPreferencesTab(page);

    // Type into the TinyMCE editor (with a textarea fallback), exactly as an admin would.
    const via = await page.evaluate((marker) => {
      const w = window as unknown as {
        tinyMCE?: { get(id: string): { setContent(c: string): void } | null };
      };
      const ed = w.tinyMCE?.get('faz-b-optout-desc');
      const html = `<p>${marker}</p>`;
      if (ed) {
        ed.setContent(html);
        const ta = document.getElementById('faz-b-optout-desc') as HTMLTextAreaElement | null;
        if (ta) ta.value = html;
        return 'tinymce';
      }
      const ta = document.getElementById('faz-b-optout-desc') as HTMLTextAreaElement | null;
      if (ta) {
        ta.value = html;
        return 'textarea';
      }
      return 'none';
    }, MARKER);
    expect(via).not.toBe('none');

    await page.click('#faz-b-save');
    await page.waitForTimeout(2500);

    // Reload → populateContents() must read optoutPopup.elements.description back.
    await openBanner(page, wpBaseURL);
    await setLaw(page, 'gdpr_ccpa');
    await gotoPreferencesTab(page);

    const persisted = await page.evaluate(() => {
      const w = window as unknown as {
        tinyMCE?: { get(id: string): { getContent(): string } | null };
      };
      const ed = w.tinyMCE?.get('faz-b-optout-desc');
      if (ed) return ed.getContent();
      return (document.getElementById('faz-b-optout-desc') as HTMLTextAreaElement | null)?.value ?? '';
    });
    expect(persisted).toContain(MARKER);
  });
});
