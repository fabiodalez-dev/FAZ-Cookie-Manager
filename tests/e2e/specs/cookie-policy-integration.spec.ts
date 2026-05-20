/**
 * E2E — Cookie Policy Generator integration (Spec 002).
 *
 * 6 tests covering:
 *  1. Page renders with top-nav, brand, page header, and Cookie Policy
 *     marked current.
 *  2. Page uses .faz-card layout (visual integration with the rest of
 *     the admin UI — same width, same chrome as Settings/Banner).
 *  3. Admin form Save round-trip persists via REST.
 *  4. Preview button produces a non-empty rendered policy with the
 *     hardcoded disclaimer.
 *  5. Shortcode renders the saved policy on a public page (GDPR-strict /
 *     CCPA / LGPD switching).
 *  6. Six languages × jurisdictions matrix renders without {{...}}
 *     leftovers.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval, upsertPage } from '../utils/wp-env';

const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy';

/**
 * Fixture data for a fake company — used across all save / shortcode tests.
 */
const FAKE_DATA = {
  jurisdiction: 'gdpr-strict',
  company: {
    name:     'ACME Privacy S.r.l.',
    address:  'Via Roma 1, 00100 Roma, Italy',
    email:    'privacy@acme-fixture.test',
    registry: 'IT12345678901',
  },
  dpo: {
    name:  'Mario Rossi',
    email: 'dpo@acme-fixture.test',
  },
  third_party_services: ['ga4', 'cf', 'recaptcha'],
  retention_months: 12,
  privacy_policy_url: 'https://acme-fixture.test/privacy',
};

test.describe('Cookie Policy Generator — admin integration (Spec 002)', () => {

  test.beforeAll(async () => {
    // Seed settings via PHP eval (avoids quoting headaches with WP-CLI option update).
    const fakeJson = JSON.stringify(FAKE_DATA).replace(/'/g, "\\'");
    wpEval(`update_option('faz_cookie_policy_data', json_decode('${fakeJson}', true));`);
    // Make sure a public page exists with the shortcode for the frontend test.
    upsertPage('cookie-policy-e2e', 'Cookie Policy E2E', '[faz_cookie_policy_v2]');
  });

  test.afterAll(async () => {
    wpEval(`
      $page_id = get_posts(array(
        'name'      => 'cookie-policy-e2e',
        'post_type' => 'page',
        'fields'    => 'ids',
        'numberposts' => 1,
        'post_status' => 'any',
      ));
      if (!empty($page_id)) { wp_delete_post((int) $page_id[0], true); }
    `);
  });

  test('1. Page is wired into faz-top-nav + appears as current item', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    const adminPage = page;
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });

    // Top nav exists.
    const nav = adminPage.locator('nav.faz-top-nav');
    await expect(nav, 'faz-top-nav present').toBeVisible();

    // Brand block + Cookie Policy link.
    await expect(nav.locator('.faz-top-nav-brand'), 'brand block present').toBeVisible();
    const cpLink = nav.locator('a', { hasText: 'Cookie Policy' });
    await expect(cpLink, 'Cookie Policy link in nav').toBeVisible();

    // Current page indicator.
    const currentLi = nav.locator('li.current a');
    await expect(currentLi, 'current nav item').toHaveText(/Cookie Policy/);

    // Page header shows the page title.
    const h1 = adminPage.locator('.faz-page-header h1');
    await expect(h1, 'page H1 from base.php').toContainText('Cookie Policy');
  });

  test('2. Page uses .faz-card layout (matches Settings/Banner width)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    const adminPage = page;
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });

    const cards = adminPage.locator('#faz-cookie-policy-app .faz-card');
    const cardCount = await cards.count();
    expect(cardCount, '>=7 .faz-card blocks (sections + actions + embed)').toBeGreaterThanOrEqual(7);

    // Compare width to Settings to confirm same chrome.
    const cpWidth = await adminPage
      .locator('#faz-page-content')
      .first()
      .evaluate(el => el.getBoundingClientRect().width);

    await adminPage.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
    const settingsWidth = await adminPage
      .locator('#faz-page-content')
      .first()
      .evaluate(el => el.getBoundingClientRect().width);

    expect(Math.abs(cpWidth - settingsWidth), 'cookie-policy width matches settings').toBeLessThan(50);
  });

  test('3. Admin form load + save round-trip', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    const adminPage = page;
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });

    // Wait for the JS to populate the form from REST.
    await adminPage.waitForFunction(
      () => {
        const el = document.getElementById('cp-company-name') as HTMLInputElement | null;
        return el && el.value && el.value.length > 0;
      },
      undefined,
      { timeout: 5000 },
    );

    const companyName = await adminPage.locator('#cp-company-name').inputValue();
    expect(companyName, 'company name loaded from seed').toContain('ACME Privacy');

    // Change a value + save.
    await adminPage.fill('#cp-company-name', 'ACME Privacy V2');
    await adminPage.click('form#faz-cookie-policy-form button[type=submit]');

    await expect(
      adminPage.locator('#cp-save-status'),
      'save status shows success',
    ).toContainText(/Saved/i, { timeout: 5000 });

    // Re-load to confirm DB persistence.
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.waitForFunction(
      () => {
        const el = document.getElementById('cp-company-name') as HTMLInputElement | null;
        return el && el.value === 'ACME Privacy V2';
      },
      undefined,
      { timeout: 5000 },
    );

    // Restore original company name for downstream tests.
    await adminPage.fill('#cp-company-name', FAKE_DATA.company.name);
    await adminPage.click('form#faz-cookie-policy-form button[type=submit]');
    await expect(adminPage.locator('#cp-save-status')).toContainText(/Saved/i);
  });

  test('3b. Third-party service checkboxes round-trip (regression for CodeRabbit #121 finding)', async ({ page, loginAsAdmin }) => {
    // The expanded ~80-service catalogue (PR #121) added checkboxes that
    // were initially created without a `name` attribute. readForm() filters
    // on `input[name],select[name],textarea[name]`, so the checkboxes were
    // never serialized and third_party_services stayed empty in the saved
    // option regardless of how many boxes the user ticked. This test pins
    // the fix: checking 3 services, saving, reloading, then asserting both
    // (a) the DOM reflects the persisted state and (b) the WP option carries
    // the expected slugs.
    await loginAsAdmin(page);
    const adminPage = page;
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });

    // Wait for the renderServicesList() call to populate the list (deferred
    // until after the initial REST load, like the rest of the form).
    await adminPage.waitForFunction(
      () => document.querySelectorAll('#cp-services-list input[type=checkbox]').length > 10,
      undefined,
      { timeout: 5000 },
    );

    // Sanity-check the name attribute is set — the actual fix.
    const sampleName = await adminPage.evaluate(() =>
      (document.querySelector('#cp-services-list input[type=checkbox]') as HTMLInputElement | null)?.name,
    );
    expect(sampleName, 'each service checkbox must carry a `name` so readForm picks it up').toBe('third_party_services[]');

    // Clear any seeded state from beforeAll, then tick exactly 3 well-known
    // services covering 3 different group categories (analytics, CDN, anti-bot).
    await adminPage.evaluate(() => {
      document.querySelectorAll<HTMLInputElement>('#cp-services-list input[type=checkbox]').forEach((cb) => {
        cb.checked = false;
      });
    });
    await adminPage.evaluate(() => {
      const ids = ['matomo', 'cloudfront', 'turnstile'];
      ids.forEach((id) => {
        const cb = document.querySelector<HTMLInputElement>(`#cp-services-list input[data-service-id="${id}"]`);
        if (cb) { cb.checked = true; }
      });
    });

    // Save.
    await adminPage.click('form#faz-cookie-policy-form button[type=submit]');
    await expect(adminPage.locator('#cp-save-status'), 'save status reports success').toContainText(/Saved/i, { timeout: 5000 });

    // Verify the wp_options row reflects the new selection (cuts through any
    // client-state echo bug — the DB is the ground truth).
    const persistedRaw = wpEval(`
      $opt = get_option('faz_cookie_policy_data', array());
      echo wp_json_encode( isset($opt['third_party_services']) ? $opt['third_party_services'] : null );
    `).trim();
    const persisted = JSON.parse(persistedRaw) as string[] | null;
    expect(persisted, 'option.third_party_services persisted as a list').toBeInstanceOf(Array);
    expect(persisted!.sort(), 'exactly the 3 services we ticked').toEqual(['cloudfront', 'matomo', 'turnstile']);

    // Reload and assert writeForm restores the same state.
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await adminPage.waitForFunction(
      () => document.querySelectorAll('#cp-services-list input[type=checkbox]:checked').length > 0,
      undefined,
      { timeout: 5000 },
    );
    const checkedAfterReload = await adminPage.evaluate(() =>
      Array.from(document.querySelectorAll<HTMLInputElement>('#cp-services-list input[type=checkbox]:checked'))
        .map((cb) => cb.dataset.serviceId || '')
        .sort(),
    );
    expect(checkedAfterReload, 'DOM reflects persisted selection after reload').toEqual(['cloudfront', 'matomo', 'turnstile']);

    // The save above serialised the entire form back to the option (not
    // just third_party_services), so a partial restore would leave
    // downstream tests with whatever the form happened to contain.
    // Reseed the full FAKE_DATA payload to match beforeAll.
    const restoreJson = JSON.stringify(FAKE_DATA).replace(/'/g, "\\'");
    wpEval(`update_option('faz_cookie_policy_data', json_decode('${restoreJson}', true));`);
  });

  test('4. Preview button renders policy with disclaimer (no leftover {{...}})', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    const adminPage = page;
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await adminPage.waitForFunction(
      () => {
        const el = document.getElementById('cp-company-name') as HTMLInputElement | null;
        return !!(el && el.value);
      },
      undefined,
      { timeout: 5000 },
    );

    await adminPage.click('#cp-preview-btn');

    // Preview lands in a sandboxed iframe.
    const modal = adminPage.locator('#cp-preview-modal');
    await expect(modal).toBeVisible({ timeout: 5000 });

    const iframe = adminPage.frameLocator('#cp-preview-modal iframe');
    await expect(iframe.locator('article.faz-cookie-policy')).toBeVisible({ timeout: 5000 });

    // Disclaimer present (FR-04, non-removable).
    await expect(iframe.locator('.faz-cookie-policy-disclaimer')).toBeVisible();
    await expect(
      iframe.locator('.faz-cookie-policy-disclaimer'),
    ).toContainText(/legal advice|consulenza legale|conseil juridique|Rechtsberatung|asesoramiento legal|aconselhamento jurídico/);

    // Company name flowed through.
    const bodyText = await iframe.locator('body').textContent();
    expect(bodyText, 'company name present').toContain('ACME Privacy');

    // No leftover placeholders.
    expect(bodyText, 'no leftover {{...}} tokens').not.toMatch(/\{\{[A-Z_][A-Z0-9_]*\}\}/);
  });

  test('5. Shortcode renders saved policy on public page', async ({ page }) => {
    // Public page, not adminPage — fresh context.
    await page.context().clearCookies();

    const siteUrl = (process.env.WP_BASE_URL || 'http://127.0.0.1:9998').replace(/\/$/, '');
    // Resolve the seeded page id with explicit guard: indexing [0] on an
    // empty get_posts() array would yield null and produce
    // /?page_id= (no id) — an invalid URL that masks the real failure.
    const pageId = wpEval(
      `$ids = get_posts(array('name'=>'cookie-policy-e2e','post_type'=>'page','fields'=>'ids')); echo (int) (isset($ids[0]) ? $ids[0] : 0);`,
    ).trim();
    expect(pageId, 'cookie-policy-e2e page id seeded by beforeAll').toMatch(/^[1-9]\d*$/);
    const policyUrl = `${siteUrl}/?page_id=${pageId}`;

    await page.goto(policyUrl, { waitUntil: 'domcontentloaded' });

    // The article must render in the body content.
    const article = page.locator('article.faz-cookie-policy');
    await expect(article).toBeVisible();

    // Company data flowed through (seeded in beforeAll).
    await expect(article).toContainText('ACME Privacy');
    await expect(article).toContainText('privacy@acme-fixture.test');

    // Disclaimer footer present.
    await expect(article.locator('.faz-cookie-policy-disclaimer')).toBeVisible();

    // Policy version exposed as data-faz-policy-version on the article
    // (HTML5-clean — <meta> inside <body> would be dropped by browsers).
    // When the shortcode runs inside the_content (which is AFTER wp_head),
    // the <head> meta tag is intentionally NOT emitted; the data-attribute
    // is the canonical surface.
    await expect(article).toHaveAttribute('data-faz-policy-version', /^[0-9a-f]+\.[0-9a-f]+$/);
  });

  test('6b. legacy [faz_cookie_policy] shortcode still registered + renders alongside v2', () => {
    // Regression guard for PR #116 review feedback (2026-05-20): the
    // generator must NOT shadow the long-standing faz_cookie_policy
    // shortcode (with site_name / contact / show_table attributes
    // and the "How to Manage Cookies" section). Both shortcodes
    // must coexist.
    const legacyOut = wpEval(
      `echo do_shortcode('[faz_cookie_policy site_name="QA Cookie Site" contact="qa@example.com" show_table="no"]');`,
    ).trim();
    // Legacy strings that the older regression tests assert against.
    expect(legacyOut, 'legacy shortcode renders').toMatch(/<div class="faz-cookie-policy"/);
    expect(legacyOut, 'legacy "How to Manage Cookies" section').toContain('Manage Cookies');
    expect(legacyOut, 'legacy site_name substituted').toContain('QA Cookie Site');
    expect(legacyOut, 'legacy contact substituted').toContain('qa@example.com');
    // show_table="no" should hide the "Cookies We Use" section.
    expect(legacyOut, 'show_table=no hides table section').not.toContain('Cookies We Use');

    // v2 shortcode renders independently.
    const v2Out = wpEval(`echo do_shortcode('[faz_cookie_policy_v2 lang="en"]');`).trim();
    expect(v2Out, 'v2 shortcode renders').toMatch(/<article class="faz-cookie-policy"/);
    expect(v2Out, 'v2 emits disclaimer').toContain('faz-cookie-policy-disclaimer');
  });

  test('6. 6 languages × 3 jurisdictions matrix renders without leftover tokens', async ({ page }) => {
    // We exercise the shortcode directly via WP eval (avoids creating 18 pages).
    const langs = ['en', 'it', 'fr', 'de', 'es', 'pt-BR'];
    const jurisdictions = ['gdpr-strict', 'ccpa-california', 'lgpd-brazil'];

    for (const j of jurisdictions) {
      for (const l of langs) {
        const out = wpEval(`echo do_shortcode('[faz_cookie_policy_v2 lang="${l}" jurisdiction="${j}"]');`).trim();

        // Sanity: non-trivial output (>= 400 chars).
        expect(out.length, `policy ${j}/${l} length`).toBeGreaterThanOrEqual(400);

        // Has <article> wrapper.
        expect(out, `${j}/${l} wraps in <article>`).toMatch(/<article class="faz-cookie-policy"/);

        // Has the disclaimer.
        expect(out, `${j}/${l} has disclaimer`).toMatch(/faz-cookie-policy-disclaimer/);

        // No leftover placeholders ANYWHERE.
        expect(out, `${j}/${l} has no leftover {{TOKEN}}`).not.toMatch(/\{\{[A-Z_][A-Z0-9_]*\}\}/);

        // Jurisdiction-specific clauses.
        if (j === 'gdpr-strict') {
          expect(out, `${j}/${l} mentions GDPR`).toMatch(/GDPR|RGPD|DSGVO|Reg.*2016\/679|Reg\.\s*UE\s*2016/);
        }
        if (j === 'ccpa-california') {
          expect(out, `${j}/${l} mentions CCPA`).toMatch(/CCPA|CPRA/);
        }
        if (j === 'lgpd-brazil') {
          expect(out, `${j}/${l} mentions LGPD`).toMatch(/LGPD/);
          expect(out, `${j}/${l} mentions Encarregado`).toMatch(/Encarregado|DPO|Datenschutzbeauftragter|D[ée]l[ée]gu[ée]|Delegado/);
        }
      }
    }
  });
});
