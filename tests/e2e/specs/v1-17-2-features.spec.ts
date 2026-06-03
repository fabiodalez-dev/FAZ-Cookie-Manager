/**
 * E2E — 1.17.2 feature & fix suite.
 *
 * Ten browser-level tests, one per contract the 1.17.2 work introduced.
 * Each provisions a real published page carrying the relevant shortcode
 * (idempotently, so the file survives a DB rebuild / fresh CI install)
 * and exercises the true public render path, plus one frontend
 * interaction test for the revisit button.
 *
 *  1. Smart-quote lang  → [faz_cookie_policy_complete lang=”it”] (curly) renders Italian, not English.
 *  2. Straight-quote     → [faz_cookie_policy_complete lang="it"] renders Italian (control).
 *  3. Unquoted lang=bg   → Bulgarian policy renders.
 *  4. Quoted lang="bg"   → Bulgarian title + "last updated" label present.
 *  5. Date localization   → Italian policy date uses an Italian month name, never an English one.
 *  6. Bulgarian date      → Bulgarian policy date uses a Cyrillic month + " г." suffix.
 *  7. Smart-quote juris   → jurisdiction=”ccpa-california” (curly) renders the CCPA policy.
 *  8. Revisit shortcode   → [faz_cookie_settings] renders the button with the open-preferences hook.
 *  9. Custom text/class    → text/class attributes honoured and sanitised.
 * 10. Button opens center  → clicking the [faz_cookie_settings] button opens the preference center.
 */

import { test, expect, type Page } from '../fixtures/wp-fixture';
import { upsertPage } from '../utils/wp-env';

// Curly / smart quotes the WordPress block & visual editors substitute
// for straight quotes — the exact bytes that broke lang resolution.
const LQ = '“'; // “
const RQ = '”'; // ”

const PAGES = {
  itCurly:      { slug: 'faz-v172-it-curly',      sc: `[faz_cookie_policy_complete lang=${LQ}it${RQ}]` },
  itStraight:   { slug: 'faz-v172-it-straight',   sc: `[faz_cookie_policy_complete lang="it"]` },
  bgUnquoted:   { slug: 'faz-v172-bg-unquoted',   sc: `[faz_cookie_policy_complete lang=bg]` },
  bgQuoted:     { slug: 'faz-v172-bg-quoted',     sc: `[faz_cookie_policy_complete lang="bg"]` },
  ccpaCurly:    { slug: 'faz-v172-ccpa-curly',    sc: `[faz_cookie_policy_complete lang="en" jurisdiction=${LQ}ccpa-california${RQ}]` },
  settings:     { slug: 'faz-v172-settings',      sc: `[faz_cookie_settings]` },
  settingsCust: { slug: 'faz-v172-settings-cust', sc: `[faz_cookie_settings text="Gestisci cookie" class="my-revisit-btn"]` },
} as const;

const IT_MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
const EN_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const BG_MONTHS = ['януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември'];

const ARTICLE = 'article.faz-cookie-policy';

test.beforeAll(() => {
  for (const { slug, sc } of Object.values(PAGES)) {
    upsertPage(slug, `FAZ 1.17.2 ${slug}`, sc);
  }
});

async function policyText(page: Page, baseURL: string, slug: string): Promise<string> {
  await page.goto(`${baseURL}/${slug}/`, { waitUntil: 'domcontentloaded' });
  const article = page.locator(ARTICLE).first();
  await expect(article, `policy article not rendered on /${slug}/`).toBeVisible({ timeout: 15_000 });
  return (await article.innerText()).trim();
}

test.describe('1.17.2 — Cookie Policy language & date', () => {
  test('1. curly-quoted lang=”it” renders Italian, not English (smart-quote fix)', async ({ page, wpBaseURL }) => {
    const text = await policyText(page, wpBaseURL, PAGES.itCurly.slug);
    expect(text, 'Italian "Ultimo aggiornamento" label missing — smart quotes still break lang').toContain('Ultimo aggiornamento');
    expect(text, 'fell back to English despite lang=it (curly quotes not stripped)').not.toContain('Last updated:');
  });

  test('2. straight-quoted lang="it" renders Italian (control)', async ({ page, wpBaseURL }) => {
    const text = await policyText(page, wpBaseURL, PAGES.itStraight.slug);
    expect(text).toContain('Ultimo aggiornamento');
    expect(text).not.toContain('Last updated:');
  });

  test('3. unquoted lang=bg renders the Bulgarian policy', async ({ page, wpBaseURL }) => {
    const text = await policyText(page, wpBaseURL, PAGES.bgUnquoted.slug);
    expect(text, 'Bulgarian policy title missing').toContain('Политика за бисквитки');
  });

  test('4. quoted lang="bg" renders Bulgarian "last updated" label', async ({ page, wpBaseURL }) => {
    const text = await policyText(page, wpBaseURL, PAGES.bgQuoted.slug);
    expect(text).toContain('Политика за бисквитки');
    expect(text, 'Bulgarian "last updated" label missing').toContain('Последна актуализация');
    expect(text, 'English month/label leaked into Bulgarian policy').not.toContain('Last updated:');
  });

  test('5. Italian policy date uses an Italian month name, never an English one', async ({ page, wpBaseURL }) => {
    const text = await policyText(page, wpBaseURL, PAGES.itStraight.slug);
    const line = (text.split('\n').find((l) => l.includes('Ultimo aggiornamento')) ?? '').toLowerCase();
    expect(line, 'no "Ultimo aggiornamento" date line found').not.toEqual('');
    expect(IT_MONTHS.some((m) => line.includes(m)), `date line has no Italian month: "${line}"`).toBe(true);
    expect(EN_MONTHS.some((m) => line.includes(m.toLowerCase())), `English month leaked into Italian date: "${line}"`).toBe(false);
  });

  test('6. Bulgarian policy date uses a Cyrillic month + " г." suffix', async ({ page, wpBaseURL }) => {
    const text = await policyText(page, wpBaseURL, PAGES.bgQuoted.slug);
    const line = text.split('\n').find((l) => l.includes('Последна актуализация')) ?? '';
    expect(line, 'no Bulgarian date line found').not.toEqual('');
    expect(BG_MONTHS.some((m) => line.includes(m)), `date line has no Bulgarian month: "${line}"`).toBe(true);
    expect(line, 'Bulgarian year suffix " г." missing').toContain(' г.');
  });

  test('7. curly-quoted jurisdiction=”ccpa-california” renders the CCPA policy', async ({ page, wpBaseURL }) => {
    const text = await policyText(page, wpBaseURL, PAGES.ccpaCurly.slug);
    expect(text, 'jurisdiction smart quotes not stripped — CCPA policy not selected').toContain('California Consumer Privacy Act');
  });
});

test.describe('1.17.2 — [faz_cookie_settings] revisit shortcode', () => {
  test('8. renders a button carrying the open-preferences hook', async ({ page, wpBaseURL }) => {
    await page.goto(`${wpBaseURL}/${PAGES.settings.slug}/`, { waitUntil: 'domcontentloaded' });
    const btn = page.locator('button.faz-cookie-settings-btn[data-faz-open-preferences]').first();
    await expect(btn, 'revisit button not rendered').toBeVisible({ timeout: 15_000 });
    await expect(btn).toHaveText(/Manage consent preferences/i);
  });

  test('9. custom text and sanitised class are honoured', async ({ page, wpBaseURL }) => {
    await page.goto(`${wpBaseURL}/${PAGES.settingsCust.slug}/`, { waitUntil: 'domcontentloaded' });
    const btn = page.locator('button.faz-cookie-settings-btn.my-revisit-btn').first();
    await expect(btn, 'custom class not applied').toBeVisible({ timeout: 15_000 });
    await expect(btn, 'custom text not applied').toHaveText('Gestisci cookie');
  });

  test('10. button is styled like the banner primary button (not raw browser chrome)', async ({ page, wpBaseURL }) => {
    await page.goto(`${wpBaseURL}/${PAGES.settings.slug}/`, { waitUntil: 'domcontentloaded' });
    const btn = page.locator('button.faz-cookie-settings-btn').first();
    await expect(btn).toBeVisible({ timeout: 15_000 });
    const style = await btn.evaluate((el) => {
      const s = getComputedStyle(el);
      return {
        bg: s.backgroundColor,
        color: s.color,
        borderStyle: s.borderStyle,
        borderWidth: s.borderTopWidth,
        padding: `${s.paddingTop} ${s.paddingRight}`,
        fontWeight: s.fontWeight,
      };
    });
    // Defaults inherited from the accept-button vars / .faz-btn base (gdpr.json
    // ships #1863dc / #fff). Proves the shortcode button picks up the banner
    // button styling rather than the browser's default grey chrome.
    expect(style.bg).toBe('rgb(24, 99, 220)');
    expect(style.color).toBe('rgb(255, 255, 255)');
    expect(style.borderStyle).toBe('solid');
    expect(style.borderWidth).toBe('2px');
    expect(style.padding).toBe('8px 27px');
    expect(style.fontWeight).toBe('500');
  });

  test('11. clicking the button opens the preference center (after consent)', async ({ page, context, wpBaseURL }) => {
    await context.clearCookies();
    await page.goto(`${wpBaseURL}/${PAGES.settings.slug}/`, { waitUntil: 'domcontentloaded' });

    // Dismiss the first-visit banner so we prove the button works post-consent.
    const accept = page.locator('[data-faz-tag="accept-button"]').first();
    await accept.waitFor({ state: 'visible', timeout: 15_000 });
    await accept.click();
    await expect(page.locator('[data-faz-tag="notice"]').first()).toBeHidden({ timeout: 8_000 });

    // The revisit button re-opens the preference center.
    await page.locator('button.faz-cookie-settings-btn[data-faz-open-preferences]').first().click();
    await expect(
      page.locator('[data-faz-tag="detail"]').first(),
      'preference center did not open from the revisit button',
    ).toBeVisible({ timeout: 8_000 });
  });

  test('12. button warns (no silent no-op) when no preference center is present', async ({ page, context, wpBaseURL }) => {
    await context.clearCookies();
    await page.goto(`${wpBaseURL}/${PAGES.settings.slug}/`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-faz-tag="accept-button"]').first().waitFor({ state: 'visible', timeout: 15_000 });

    // Simulate the "banner UI suppressed" case: strip the preference-center DOM
    // (#faz-consent + every .faz-modal) so the button has nothing to open.
    await page.evaluate(() => {
      document.querySelectorAll('#faz-consent, .faz-modal').forEach((el) => el.remove());
    });

    const warnings: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'warning') warnings.push(msg.text());
    });

    await page.locator('button.faz-cookie-settings-btn[data-faz-open-preferences]').first().click();
    await page.waitForTimeout(500);

    expect(
      warnings.some((w) => w.includes('FAZ Cookie Manager') && w.includes('no consent preference center')),
      'expected a console.warn diagnostic instead of a silent no-op',
    ).toBe(true);
  });
});
