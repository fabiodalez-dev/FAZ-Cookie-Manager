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
    upsertPage('cookie-policy-e2e', 'Cookie Policy E2E', '[faz_cookie_policy_complete]');
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
      // Delete the option seeded by beforeAll so downstream specs see a
      // clean Cookie Policy state. Without this, any later spec that
      // renders [faz_cookie_policy_complete] or reads the option via the
      // REST API would see ACME Privacy S.r.l. / privacy@acme-fixture.test
      // fixture data instead of an empty/default install state.
      delete_option('faz_cookie_policy_data');
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

    // Wait for writeForm() (the REST GET .then handler in cookie-policy.js)
    // to have hydrated the form with the persisted FAKE_DATA seed. The
    // render at line 172 only verifies renderServicesList() ran; the
    // hydration is a separate async step that runs after the GET resolves.
    // If we tick `.checked` before writeForm fires, writeForm will race in
    // afterwards and reset our ticks back to the persisted seed
    // (`[ga4,cf,recaptcha]`) before submit serializes — the exact failure
    // observed in long full-suite runs where the GET is slower under load.
    // FAKE_DATA seeds 3 services, so wait for any checkbox to be :checked.
    await adminPage.waitForFunction(
      () => document.querySelectorAll('#cp-services-list input[type=checkbox]:checked').length > 0,
      undefined,
      { timeout: 5000 },
    );

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

  test('6b. legacy [faz_cookie_policy] shortcode still registered + renders alongside [faz_cookie_policy_complete]', () => {
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

    // The generator's shortcode (renamed from _v2 → _complete for human
    // readability) renders independently.
    const completeOut = wpEval(`echo do_shortcode('[faz_cookie_policy_complete lang="en"]');`).trim();
    expect(completeOut, 'complete shortcode renders').toMatch(/<article class="faz-cookie-policy"/);
    expect(completeOut, 'complete emits disclaimer').toContain('faz-cookie-policy-disclaimer');
  });

  test('6. 6 languages × 3 jurisdictions matrix renders without leftover tokens', async ({ page }) => {
    // We exercise the shortcode directly via WP eval (avoids creating 18 pages).
    const langs = ['en', 'it', 'fr', 'de', 'es', 'pt-BR'];
    const jurisdictions = ['gdpr-strict', 'ccpa-california', 'lgpd-brazil'];

    for (const j of jurisdictions) {
      for (const l of langs) {
        const out = wpEval(`echo do_shortcode('[faz_cookie_policy_complete lang="${l}" jurisdiction="${j}"]');`).trim();

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

  test('7. P2-A regression: COOKIE_CATEGORIES HTML survives markdown_to_html() without nested <p>', () => {
    // Repro of the CodeRabbit P2 finding: the cookie-list block must not
    // be re-parsed by the line-based markdown_to_html(). The cookie list
    // is rendered as an HTML5 <details> accordion wrapping a
    // <table class="faz-cookie-policy-table"> (the flat <dl> layout was
    // dropped in 1.16.2 per Gooloo feedback — see Renderer::render()).
    // Symptoms of the bug pre-fix, adapted to the table markup:
    //   - `<p>` wrapping closing tags: `<p>...</td></p>`
    //   - inline tags (`<small>`, `<strong>`) wrapped in their own `<p>`
    //   - stray closing `</p>` after `</table>`
    // The fix is the two-pass sentinel substitution in Renderer::render().
    const html = wpEval(`
      // Ensure at least one cookie exists in inventory so build_cookie_list_html
      // produces a non-empty <table>. Column names must match the live schema:
      // cookie_name / cookie_domain / cookie_duration / cookie_description.
      global $wpdb;
      // Self-contained fixture: the renderer only emits a category block (and
      // thus a <table>) for categories that exist in faz_cookie_categories.
      // A sibling lifecycle spec can empty that table mid-run, so guarantee the
      // probe's category (id 1) exists before rendering. Insert-if-missing only
      // — never clobber a real category, and leave it in place (a 'necessary'
      // category is the normal baseline, so restoring it un-pollutes the run).
      $cat_table = $wpdb->prefix . 'faz_cookie_categories';
      $has_cat1  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM \`{$cat_table}\` WHERE category_id = %d", 1 ) );
      if ( ! $has_cat1 ) {
        $now = current_time( 'mysql' );
        $wpdb->insert( $cat_table, array(
          'category_id'        => 1,
          'name'               => wp_json_encode( array( 'en' => 'Necessary' ) ),
          'slug'               => 'necessary',
          'description'        => wp_json_encode( array( 'en' => '<p>Necessary cookies.</p>' ) ),
          'prior_consent'      => 1,
          'visibility'         => 1,
          'priority'           => 0,
          'sell_personal_data' => 0,
          'meta'               => null,
          'date_created'       => $now,
          'date_modified'      => $now,
        ) );
      }
      $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => 'faz_p2_probe' ) );
      // Use real schema column names: name/domain/duration/description/category.
      $wpdb->insert( $wpdb->prefix . 'faz_cookies', array(
        'name'        => 'faz_p2_probe',
        'slug'        => 'faz_p2_probe',
        'category'    => 1,
        'duration'    => 'Session',
        'domain'      => 'example.com',
        'description' => '<strong>Probe</strong> for P2-A regression.',
      ) );
      // Bust the per-language cookie-list cache so the next render reflects the probe.
      wp_cache_delete( 'faz_cookie_policy_list_en', 'faz_cookie_policy' );
      wp_cache_flush();
      $rendered = do_shortcode( '[faz_cookie_policy_complete]' );
      echo $rendered;
    `);

    // Regression assertions: no invalid nesting patterns introduced by the
    // line-based markdown parser around the cookie list (now a <table>).
    expect(html, 'closing </table> must not be followed by a stray </p>').not.toMatch(/<\/table>\s*<\/p>/);
    expect(html, 'closing </td> must not be inside a <p>').not.toMatch(/<p>[^<]*<\/td>/);
    expect(html, 'closing </tr> must not be inside a <p>').not.toMatch(/<p>[^<]*<\/tr>/);
    expect(html, '<table> must not be wrapped in its own <p>').not.toMatch(/<p>\s*<table[\s>]/);
    expect(html, '<small> must not be wrapped in its own <p>').not.toMatch(/<p>\s*<small>/);
    // Positive checks: the structural HTML did make it through unmangled.
    expect(html, 'the cookie-list <table> block is present').toMatch(/<table class="faz-cookie-policy-table">/);
    expect(html, 'the <details> accordion wrapper survived').toMatch(/<details class="faz-cookie-policy-details">/);
    expect(html, '<tbody> / </table> still close cleanly').toMatch(/<\/tbody>\s*<\/table>/);
    expect(html, 'the probe cookie row rendered inside the table').toContain('faz_p2_probe');

    // Probe cleanup.
    wpEval(`
      global $wpdb;
      $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => 'faz_p2_probe' ) );
      wp_cache_delete( 'faz_cookie_policy_list_en', 'faz_cookie_policy' );
    `);
  });

  test('8. P2-B regression: policy_version_hash is stable when only LAST_UPDATED_DATE drifts', () => {
    // Repro of the CodeRabbit P2 finding: LAST_UPDATED_DATE is computed via
    // current_time() and is display-only. It must NOT influence the hash,
    // otherwise the data-faz-policy-version drifts daily and produces false
    // material-change signals downstream.
    const out = wpEval(`
      $template_path = ''; // empty path → deterministic 'no-template' sha
      $base = array(
        'COMPANY_NAME'      => 'Acme',
        'JURISDICTION_NAME' => 'GDPR',
        'COOKIE_CATEGORIES' => '<dl><dt>x</dt><dd>y</dd></dl>',
        'LAST_UPDATED_DATE' => '2026-01-01',
      );
      $alt = $base;
      $alt['LAST_UPDATED_DATE'] = '2027-12-31';
      $h1 = \\FazCookie\\Admin\\Modules\\Cookie_Policy_Generator\\Includes\\Generator::policy_version_hash( $template_path, $base );
      $h2 = \\FazCookie\\Admin\\Modules\\Cookie_Policy_Generator\\Includes\\Generator::policy_version_hash( $template_path, $alt );
      // Sanity: a real material change MUST shift the hash.
      $material = $base;
      $material['COMPANY_NAME'] = 'Changed Inc.';
      $h3 = \\FazCookie\\Admin\\Modules\\Cookie_Policy_Generator\\Includes\\Generator::policy_version_hash( $template_path, $material );
      echo wp_json_encode( array(
        'stable_same_day'  => $h1,
        'stable_year_diff' => $h2,
        'material_change'  => $h3,
      ) );
    `).trim();
    const data = JSON.parse(out) as { stable_same_day: string; stable_year_diff: string; material_change: string };
    expect(data.stable_year_diff, 'hash MUST be identical when only LAST_UPDATED_DATE drifts').toBe(data.stable_same_day);
    expect(data.material_change, 'hash MUST change on real content edits (COMPANY_NAME)').not.toBe(data.stable_same_day);
  });
});
