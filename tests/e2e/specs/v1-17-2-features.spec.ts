/**
 * E2E — 1.17.2 feature & fix suite.
 *
 * 17 browser-level tests, one per contract the 1.17.2 work introduced.
 * Each provisions a real published page carrying the relevant shortcode
 * (idempotently, so the file survives a DB rebuild / fresh CI install)
 * and exercises the true public render path, plus frontend interaction
 * tests for the revisit button and the Global Privacy Control opt-out.
 *
 *  1.  Smart-quote lang   → [faz_cookie_policy_complete lang=”it”] (curly) renders Italian, not English.
 *  2.  Straight-quote     → [faz_cookie_policy_complete lang="it"] renders Italian (control).
 *  3.  Unquoted lang=bg   → Bulgarian policy renders.
 *  4.  Quoted lang="bg"   → Bulgarian title + "last updated" label present.
 *  5.  Date localization  → Italian policy date uses an Italian month name, never an English one.
 *  6.  Bulgarian date     → Bulgarian policy date uses a Cyrillic month + " г." suffix.
 *  7.  Smart-quote juris  → jurisdiction=”ccpa-california” (curly) renders the CCPA policy.
 *  7b. Underscore locale  → lang="pt_BR" survives the attribute cleanup (renders Portuguese).
 *  8.  Revisit shortcode  → [faz_cookie_settings] renders the button with the open-preferences hook.
 *  9.  Custom text/class  → text/class attributes honoured and sanitised.
 * 10.  Button styled      → the button carries the banner primary-button styling, not raw browser chrome.
 * 11.  Button opens center → clicking the [faz_cookie_settings] button opens the preference center.
 * 12.  Warn, no silent no-op → button warns when no preference center is present.
 * 13.  Pushdown ARIA       → repeated button clicks keep aria-expanded true (no desync).
 * 14.  Suppressed banner   → shortcode trigger binds + warns when the banner template is absent at init.
 * 15.  GPC opt-out        → navigator.globalPrivacyControl auto-applies a reject and suppresses the banner.
 * 15b. GPC absent         → no signal ⇒ banner shows, no auto consent recorded.
 * 16.  Policy title       → policy omits its own H1 by default; show_title="true" restores it.
 */

import { test, expect, type Page } from '../fixtures/wp-fixture';
import { upsertPage, wpEval } from '../utils/wp-env';

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
  ptUnderscore: { slug: 'faz-v172-pt-underscore', sc: `[faz_cookie_policy_complete lang="pt_BR" jurisdiction="lgpd-brazil"]` },
  settings:     { slug: 'faz-v172-settings',      sc: `[faz_cookie_settings]` },
  settingsCust: { slug: 'faz-v172-settings-cust', sc: `[faz_cookie_settings text="Gestisci cookie" class="my-revisit-btn"]` },
  titleHidden:  { slug: 'faz-v172-title-hidden',  sc: `[faz_cookie_policy_complete lang="it"]` },
  titleShown:   { slug: 'faz-v172-title-shown',   sc: `[faz_cookie_policy_complete lang="it" show_title="true"]` },
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

  test('7b. underscore locale lang="pt_BR" survives the attribute cleanup (renders Portuguese)', async ({ page, wpBaseURL }) => {
    // The smart-quote cleanup must keep "_" so pt_BR normalises to pt-BR instead
    // of collapsing to "ptBR" and falling back to the default language.
    const text = await policyText(page, wpBaseURL, PAGES.ptUnderscore.slug);
    expect(text, 'pt_BR collapsed to ptBR and fell back to default language').toContain('Política de Cookies');
    expect(text, 'English leaked into the pt_BR policy').not.toContain('Last updated:');
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

  test('13. pushdown: repeated button clicks keep aria-expanded true (no ARIA desync)', async ({ page, context, wpBaseURL }) => {
    // In pushdown mode _fazShowPreferenceCenter() previously TOGGLED aria-expanded,
    // so a 2nd click of the [faz_cookie_settings] button flipped it to "false"
    // while the panel stayed visually open. The fix forces "true" on open.

    // Capture the active banner's EXACT settings blob (base64-encoded to avoid
    // any JSON-quoting issues round-tripping through wpEval) so the shared
    // fixture is restored byte-for-byte in the finally — never to a hardcoded
    // baseline or a re-encoded approximation. Then switch to a pushdown layout.
    const flushBannerCache =
      ` $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%faz_banner_template%' OR option_name LIKE '_transient_faz_%'");`;
    const capture = wpEval(
      `global $wpdb; $t = $wpdb->prefix . 'faz_banners';` +
        ` $row = $wpdb->get_row("SELECT settings FROM $t WHERE banner_id=1");` +
        ` echo 'BLOB:' . ($row ? base64_encode($row->settings) : '');`,
    );
    const origBlobB64 = (capture.match(/BLOB:([A-Za-z0-9+/=]*)/) || [, ''])[1];
    expect(origBlobB64.length, 'could not read the original banner settings blob').toBeGreaterThan(0);

    // Switch banner_id=1 to classic (which forces the pushdown preference center).
    wpEval(
      `global $wpdb; $t = $wpdb->prefix . 'faz_banners';` +
        ` $row = $wpdb->get_row("SELECT settings FROM $t WHERE banner_id=1");` +
        ` $s = json_decode($row->settings, true);` +
        ` $s['settings']['type'] = 'classic'; $s['settings']['preferenceCenterType'] = 'pushdown';` +
        ` $wpdb->update($t, array('settings' => wp_json_encode($s)), array('banner_id' => 1));` +
        flushBannerCache,
    );

    try {
      await context.clearCookies();
      await page.goto(`${wpBaseURL}/${PAGES.settings.slug}/?n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
      // Dismiss the first-visit banner; let the precondition FAIL loudly if it
      // never appears (otherwise the post-consent path below is untested).
      await page.locator('[data-faz-tag="accept-button"]').first().click({ timeout: 15_000 });
      await page.waitForTimeout(800);

      const settingsBtn = page.locator('[data-faz-tag="settings-button"]').first();
      const revisitBtn = page.locator('button.faz-cookie-settings-btn').first();

      await revisitBtn.click();
      await page.waitForTimeout(400);
      expect(await settingsBtn.getAttribute('aria-expanded'), 'aria-expanded should be true after opening').toBe('true');

      await revisitBtn.click(); // 2nd click — must NOT flip aria-expanded to false
      await page.waitForTimeout(400);
      expect(
        await settingsBtn.getAttribute('aria-expanded'),
        'aria-expanded desynced to false on the 2nd click while the panel stayed open',
      ).toBe('true');
    } finally {
      // Restore the EXACT original settings blob (byte-for-byte), so the shared
      // banner_id=1 fixture is left exactly as it was found.
      wpEval(
        `global $wpdb; $t = $wpdb->prefix . 'faz_banners';` +
          ` $wpdb->update($t, array('settings' => base64_decode('${origBlobB64}')), array('banner_id' => 1));` +
          flushBannerCache,
      );
    }
  });

  test('14. shortcode trigger binds + warns even when the banner template is ABSENT at init', async ({ page, context, wpBaseURL }) => {
    // Real suppressed-UI case (PMP-exempt member / empty template cache): the
    // server enqueues script.js but banner_html() does NOT print the
    // <template id="fazBannerTemplate">. _fazRenderBanner() then early-returns
    // and _fazRegisterListeners() (the banner-dependent listeners) never runs —
    // but the [faz_cookie_settings] handler must STILL bind (it lives in
    // _fazRegisterShortcodeTriggers(), called unconditionally from
    // _fazInitOperations()) and log the diagnostic warning. This differs from
    // test 12, which removes the DOM AFTER listeners are already registered.
    await context.clearCookies();
    // Strip the banner template (a <script id="fazBannerTemplate" type="text/template">)
    // from the HTML before the page scripts run, so _fazRenderBanner() early-returns.
    await page.route(`**/${PAGES.settings.slug}/**`, async (route) => {
      const resp = await route.fetch();
      const stripped = (await resp.text()).replace(
        /<script id="fazBannerTemplate"[^>]*>[\s\S]*?<\/script>/,
        '',
      );
      await route.fulfill({ response: resp, body: stripped });
    });

    const warnings: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'warning') warnings.push(msg.text());
    });

    await page.goto(`${wpBaseURL}/${PAGES.settings.slug}/?n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    // The banner template is gone — the consent banner can't render at all.
    await expect(page.locator('#fazBannerTemplate')).toHaveCount(0);

    // The in-page shortcode button is still present and its handler must be bound.
    await page.locator('button.faz-cookie-settings-btn').first().click();
    await page.waitForTimeout(500);
    expect(
      warnings.some((w) => w.includes('FAZ Cookie Manager') && w.includes('no consent preference center')),
      'shortcode trigger did not bind / warn when the banner template was absent at init (regression of the PMP-exempt suppressed-UI fix)',
    ).toBe(true);
  });
});

// ─────────────────────────────────────────────────────────────────────────
// 15. Global Privacy Control (GPC) honouring.
//
// The "Respect Global Privacy Control" banner toggle was previously inert —
// saved in admin but never surfaced to the frontend, so navigator.global-
// PrivacyControl was never read. These tests provision the toggle on the
// active banner and assert the signal now drives an automatic opt-out.
// ─────────────────────────────────────────────────────────────────────────
test.describe('GPC — Global Privacy Control honouring (1.17.2)', () => {
  // Flip behaviours.respectGPC.status on the default banner and bump the
  // banner-controller cache epoch so the change is served immediately.
  function setRespectGPC(enabled: boolean): void {
    wpEval(
      `global $wpdb; $t=$wpdb->prefix.'faz_banners';` +
      `$id=(int)$wpdb->get_var("SELECT banner_id FROM $t WHERE banner_default=1 LIMIT 1");` +
      `if(!$id){$id=(int)$wpdb->get_var("SELECT banner_id FROM $t WHERE status=1 LIMIT 1");}` +
      `if($id){$s=json_decode($wpdb->get_var($wpdb->prepare("SELECT settings FROM $t WHERE banner_id=%d",$id)),true);` +
      `if(!is_array($s))$s=array();if(!isset($s['behaviours'])||!is_array($s['behaviours']))$s['behaviours']=array();` +
      `$s['behaviours']['respectGPC']=array('status'=>` + (enabled ? 'true' : 'false') + `);` +
      `$wpdb->update($t,array('settings'=>wp_json_encode($s)),array('banner_id'=>$id));}` +
      `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
      `delete_option('faz_banner_template');`,
    );
  }

  test.beforeAll(() => setRespectGPC(true));
  test.afterAll(() => setRespectGPC(false));

  test('GPC signal auto-applies an opt-out and suppresses the banner', async ({ browser, wpBaseURL }) => {
    const context = await browser.newContext();
    // Assert the GPC DOM signal before any page script runs.
    await context.addInitScript(() => {
      Object.defineProperty(navigator, 'globalPrivacyControl', { get: () => true, configurable: true });
    });
    const page = await context.newPage();
    await page.goto(`${wpBaseURL}/?n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    // Banner must NOT be shown — the opt-out is already recorded.
    const bannerVisible = await page.evaluate(() => {
      const b = document.getElementById('faz-consent') || document.querySelector('.faz-consent-bar');
      if (!b) return false;
      const cs = getComputedStyle(b);
      return cs.display !== 'none' && cs.visibility !== 'hidden' && (b as HTMLElement).offsetParent !== null;
    });
    expect(bannerVisible, 'banner should be suppressed when GPC drove an opt-out').toBe(false);

    // Consent cookie records a reject with the gpc marker; non-necessary denied.
    const consent = await page.evaluate(() => {
      const raw = document.cookie.split(';').map((s) => s.trim()).find((s) => s.startsWith('fazcookie-consent='));
      if (!raw) return null;
      const out: Record<string, string> = {};
      decodeURIComponent(raw.substring('fazcookie-consent='.length)).split(',').forEach((p) => {
        const i = p.indexOf(':');
        if (i > -1) out[p.slice(0, i).trim()] = p.slice(i + 1).trim();
      });
      return out;
    });
    expect(consent, 'GPC opt-out should write the consent cookie').not.toBeNull();
    expect(consent!.action).toBe('yes');
    expect(consent!.gpc).toBe('1');
    expect(consent!.necessary).toBe('yes');
    expect(consent!.marketing).toBe('no');

    await context.close();
  });

  test('without a GPC signal the banner still appears (no auto opt-out)', async ({ browser, wpBaseURL }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(`${wpBaseURL}/?n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    const bannerVisible = await page.evaluate(() => {
      const b = document.getElementById('faz-consent') || document.querySelector('.faz-consent-bar');
      if (!b) return false;
      const cs = getComputedStyle(b);
      return cs.display !== 'none' && cs.visibility !== 'hidden';
    });
    expect(bannerVisible, 'banner should appear normally with no GPC signal').toBe(true);

    const acted = await page.evaluate(() => {
      const raw = document.cookie.split(';').map((s) => s.trim()).find((s) => s.startsWith('fazcookie-consent='));
      return !!(raw && /(?:^|,)action:yes(?:,|$)/.test(decodeURIComponent(raw)));
    });
    expect(acted, 'no consent should be auto-recorded without GPC').toBe(false);

    await context.close();
  });
});

// ─────────────────────────────────────────────────────────────────────────
// 16. Cookie-policy title is dropped by default (the WP page provides it).
// ─────────────────────────────────────────────────────────────────────────
test.describe('Cookie policy leading title (1.17.2)', () => {
  test('policy omits its own H1 by default so it does not duplicate the page title', async ({ page, wpBaseURL }) => {
    await page.goto(`${wpBaseURL}/${PAGES.titleHidden.slug}/`, { waitUntil: 'domcontentloaded' });
    const article = page.locator(ARTICLE).first();
    await expect(article).toBeVisible();
    // No <h1> inside the generated policy.
    await expect(article.locator('h1')).toHaveCount(0);
    // …but the document still names itself in the intro prose.
    await expect(article).toContainText('Cookie Policy');
  });

  test('show_title="true" restores the policy H1', async ({ page, wpBaseURL }) => {
    await page.goto(`${wpBaseURL}/${PAGES.titleShown.slug}/`, { waitUntil: 'domcontentloaded' });
    const article = page.locator(ARTICLE).first();
    await expect(article).toBeVisible();
    await expect(article.locator('h1')).toHaveCount(1);
  });
});
