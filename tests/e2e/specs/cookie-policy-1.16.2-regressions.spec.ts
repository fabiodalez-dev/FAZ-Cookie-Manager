/**
 * E2E — Cookie Policy Generator 1.16.2 regression suite.
 *
 * Mirror of the 10 things 1.16.2 fixes/changes. Each test asserts the
 * minimal contract the change is supposed to satisfy. Kept narrow so the
 * file can run in well under a minute without pulling the full
 * cookie-policy-integration suite.
 *
 * Bugs covered:
 *   #5 URL preview leak  → current_url() strips query / fragment
 *   #4 Accordion + table → <details>/<summary>/<table> structure
 *   #4 Layout fix         → category-name + count on the same baseline
 *   #7 Disclaimer hide    → disclaimer.show=false → not rendered
 *   #7 Disclaimer text    → custom text + <div> wrapper (no <footer>)
 *   #7 Disclaimer default → still <div>, never <footer>
 *   #1 Empty fields skip  → no orphan "**Label:**" lines
 *   #3 Google Ads allow   → 'gads' survives sanitize_settings round-trip
 *   #2 DPO acronym (DE)   → "(DSB)" stripped from German DPO line
 *   #6 EDPB removed (EN)  → "European Data Protection Board" stripped
 *   wp-internal exclusion → wp-settings-* never appears in policy
 */

import { test, expect, type Page } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy';
const REST_BASE = '/wp-json/faz/v1/cookie-policy';
// The public "Cookie Policy" page (http://127.0.0.1:9998/policy/) hosts the
// real [faz_cookie_policy_complete] shortcode — these regression tests visit
// it as a public page to exercise the true render path. It is provisioned
// idempotently in beforeAll (see ensurePolicyPage) so the suite survives a
// DB rebuild / a fresh CI install where the seed page is absent, instead of
// silently failing with an empty article.
const POLICY_PUBLIC_PATH = '/policy/';
const POLICY_PAGE_SLUG = 'policy';
const POLICY_SHORTCODE = '[faz_cookie_policy_complete]';

/**
 * Ensure a published page at /policy/ containing the cookie-policy shortcode
 * exists. Idempotent: if the page already exists it only repairs the slug /
 * status / content if they drifted, so re-runs never create duplicates.
 */
function ensurePolicyPage(): void {
  wpEval(`
    $slug = ${JSON.stringify(POLICY_PAGE_SLUG)};
    $content = ${JSON.stringify(POLICY_SHORTCODE)};
    $existing = get_page_by_path( $slug, OBJECT, 'page' );
    if ( $existing instanceof WP_Post ) {
      $needs = array();
      if ( $existing->post_status !== 'publish' ) { $needs['post_status'] = 'publish'; }
      if ( strpos( (string) $existing->post_content, 'faz_cookie_policy' ) === false ) { $needs['post_content'] = $content; }
      if ( $needs ) { $needs['ID'] = $existing->ID; wp_update_post( $needs ); }
      echo 'policy_page=' . $existing->ID;
    } else {
      $id = wp_insert_post( array(
        'post_title'   => 'Cookie Policy',
        'post_name'    => $slug,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => $content,
      ) );
      echo 'policy_page=' . ( is_wp_error( $id ) ? 'ERR:' . $id->get_error_message() : $id );
    }
  `);
}

// Module-scope auth state, populated by beforeEach. Matches the pattern
// in settings-options-behavior.spec.ts so the X-WP-Nonce header rides
// the same authenticated context the fixture's loginAsAdmin sets up.
let adminPage: Page;
let nonce = '';

/**
 * Build a minimal settings payload for the /preview endpoint. The
 * caller passes only the fields it cares about; defaults fill the rest
 * so sanitize_settings doesn't reject the payload.
 */
function previewSettings(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    jurisdiction: 'gdpr-strict',
    default_lang: '',
    company: {
      name: 'ACME Test',
      address: 'Via Test 1',
      email: 'test@acme.test',
      registry: '',
    },
    dpo: { name: '', email: '', address: '' },
    third_party_services: [],
    retention_months: 12,
    privacy_policy_url: '',
    disclaimer: { show: true, text: '' },
    ...overrides,
  };
}

async function callPreview(body: Record<string, unknown>): Promise<string> {
  const res = await adminPage.request.post(`${REST_BASE}/preview`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: body,
  });
  const status = res.status();
  const text = await res.text();
  expect(res.ok(), `preview ${status}: ${text.slice(0, 200)}`).toBeTruthy();
  let json: { html?: string };
  try {
    json = JSON.parse(text) as { html?: string };
  } catch {
    throw new Error(`preview returned non-JSON: ${text.slice(0, 200)}`);
  }
  return String(json.html || '');
}

test.describe('Cookie Policy 1.16.2 — regression suite', () => {
  // serial: nonce lives in a module-scope var that beforeEach refreshes
  // per test; running in parallel would race the auth state across
  // workers. Sequential keeps the fixture deterministic.
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(() => {
    // Self-provision the public /policy/ page so the visit-the-public-page
    // tests (#5, #4-Layout, wp-internal exclusion) render the real shortcode
    // even on a freshly-rebuilt DB where the seed page is missing.
    ensurePolicyPage();
  });

  test.beforeEach(async ({ page, loginAsAdmin }) => {
    adminPage = page;
    await loginAsAdmin(adminPage);
    await adminPage.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await adminPage.waitForFunction(
      () => typeof (window as unknown as { fazConfig?: { api?: { nonce?: string } } })
        .fazConfig?.api?.nonce === 'string',
      undefined,
      { timeout: 15_000 },
    );
    nonce = await adminPage.evaluate(
      () => (window as unknown as { fazConfig?: { api?: { nonce?: string } } })
        .fazConfig?.api?.nonce ?? '',
    );
    expect(nonce.length, 'X-WP-Nonce empty — fazConfig.api.nonce not set').toBeGreaterThan(0);
  });

  test('#5 URL query string stripped from {{COOKIE_POLICY_URL}}', async ({ wpBaseURL }) => {
    // Visit the public policy page WITH a query string; the renderer's
    // current_url() helper builds {{COOKIE_POLICY_URL}} from REQUEST_URI,
    // so anything the host attaches must NOT surface inside the rendered
    // policy text. The Gooloo bug triggered this via WP preview-mode
    // params (?preview_id=&preview_nonce=) but those are 403-protected
    // for unauthenticated visitors. The fix is symmetric — it strips
    // EVERY query string, so we exercise the same codepath with innocuous
    // tracking params that don't need auth.
    const visitor = await adminPage.context().browser()!.newContext();
    const visitorPage = await visitor.newPage();
    try {
      const noisy = '?utm_source=faz-1162-test&utm_medium=email&fbclid=ABC123XYZ&_ga=GA1.test';
      await visitorPage.goto(`${wpBaseURL}${POLICY_PUBLIC_PATH}${noisy}`, { waitUntil: 'domcontentloaded' });
      const articleHtml = await visitorPage.locator('article.faz-cookie-policy').innerHTML().catch(() => '');
      expect(articleHtml.length, 'policy article not rendered on /policy/ page').toBeGreaterThan(100);
      // The rendered policy text mentions COOKIE_POLICY_URL (the canonical
      // URL of the page); it must NOT contain any of our noisy params.
      expect(articleHtml, 'utm_source leaked into rendered policy').not.toContain('utm_source=');
      expect(articleHtml, 'fbclid leaked into rendered policy').not.toContain('fbclid=');
      expect(articleHtml, '_ga leaked into rendered policy').not.toContain('_ga=');
      // Stronger structural assertion: any /policy/ URL inside the article
      // must end at the trailing slash (no `?` query suffix).
      const urlMatches = articleHtml.match(/https?:\/\/[^"'\s<)]+/g) || [];
      const policyUrls = urlMatches.filter((u) => u.includes('/policy'));
      expect(policyUrls.length, 'no policy URL placeholder found in rendered HTML').toBeGreaterThan(0);
      for (const u of policyUrls) {
        expect(u, `policy URL still contains query string: ${u}`).not.toMatch(/\?/);
      }
    } finally {
      await visitor.close();
    }
  });

  test('#4 Accordion + table — <details>/<summary>/<table.faz-cookie-policy-table> rendered', async () => {
    const html = await callPreview({ settings: previewSettings() });
    expect(html).toContain('<details class="faz-cookie-policy-details"');
    expect(html).toContain('<summary class="faz-cookie-policy-summary">');
    expect(html).toContain('<table class="faz-cookie-policy-table">');
    // Old layout sanity: no leftover <dl>/<dt>/<dd> from the 1.16.0 path.
    expect(html).not.toMatch(/<dl>\s*<dt>/);
  });

  test('#4 Layout — summary keeps name + count on the same baseline (single row)', async ({ wpBaseURL }) => {
    // Use a fresh unauthenticated context so the admin toolbar styles
    // don't shift the layout in the measurement.
    const visitor = await adminPage.context().browser()!.newContext();
    const page = await visitor.newPage();
    try {
      await page.goto(`${wpBaseURL}${POLICY_PUBLIC_PATH}`, { waitUntil: 'domcontentloaded' });
        const layout = await page.evaluate(() => {
        const summary = document.querySelector('details.faz-cookie-policy-details > summary.faz-cookie-policy-summary');
        if (!summary) return null;
        const name = summary.querySelector('.faz-cookie-policy-category-name') as HTMLElement | null;
        const count = summary.querySelector('.faz-cookie-policy-count') as HTMLElement | null;
        if (!name || !count) return null;
        const nr = name.getBoundingClientRect();
        const cr = count.getBoundingClientRect();
        return {
          deltaTop: Math.abs(nr.top - cr.top),
          nameDisplay: getComputedStyle(name).display,
          countDisplay: getComputedStyle(count).display,
        };
      });
      expect(layout, 'no accordion summary found on the policy page').not.toBeNull();
      expect(layout!.deltaTop, `name and count differ by ${layout!.deltaTop}px on the Y axis`).toBeLessThan(5);
      expect(layout!.nameDisplay).not.toBe('block');
      expect(layout!.countDisplay).not.toBe('block');
    } finally {
      await visitor.close();
    }
  });

  test('#7 Disclaimer hidden — disclaimer.show=false skips the block entirely', async () => {
    const html = await callPreview({
      settings: previewSettings({ disclaimer: { show: false, text: '' } }),
    });
    expect(html, 'disclaimer rendered despite show=false').not.toContain('faz-cookie-policy-disclaimer');
  });

  test('#7 Disclaimer custom text — wraps user content in <div>, never <footer>', async () => {
    const marker = 'FAZ-1162-CUSTOM-DISCLAIMER-MARKER';
    const html = await callPreview({
      settings: previewSettings({ disclaimer: { show: true, text: `Hello ${marker} world` } }),
    });
    expect(html).toContain(marker);
    expect(html).toMatch(/<div class="faz-cookie-policy-disclaimer">/);
    expect(html, 'legacy <footer> wrapper leaked').not.toMatch(/<footer class="faz-cookie-policy-disclaimer"/);
  });

  test('#7 Disclaimer default — <div> wrapper still used, no <footer>', async () => {
    const html = await callPreview({ settings: previewSettings() });
    expect(html).toContain('<div class="faz-cookie-policy-disclaimer">');
    expect(html).not.toContain('<footer class="faz-cookie-policy-disclaimer"');
  });

  test('#1 Empty fields — orphan "**Register:**" / "**DPO:**" lines stripped', async () => {
    const html = await callPreview({
      settings: previewSettings({
        default_lang: 'en',
        company: { name: 'ACME', address: 'Via Test', email: 'a@b.test', registry: '' },
        dpo: { name: '', email: '', address: '' },
      }),
    });
    // No leftover label-only list items.
    expect(html, 'empty Registry / VAT line leaked').not.toMatch(/<li><strong>Registry \/ VAT:<\/strong>\s*<\/li>/);
    expect(html, 'empty DPO line leaked').not.toMatch(/<li><strong>Data Protection Officer[^<]*<\/strong>\s*(—|-)?\s*<\/li>/);
    expect(html, 'orphan label "Registry / VAT:" survived').not.toMatch(/Registry \/ VAT:\s*<\/(li|p)>/);
  });

  test('#3 Google Ads allowlisted — gads survives sanitize_settings round-trip', async () => {
    const html = await callPreview({
      settings: previewSettings({ third_party_services: ['gads', 'meta', 'criteo', 'NOPE_FAKE'] }),
    });
    // Allowed: gads, meta, criteo. Rejected: NOPE_FAKE.
    expect(html, 'Google Ads dropped from allowlist').toContain('Google Ads');
    expect(html).toContain('Meta (Facebook) Pixel');
    expect(html).toContain('Criteo');
    expect(html, 'unknown service NOT dropped').not.toContain('NOPE_FAKE');
  });

  test('#2 DPO acronym (DE) — "(DSB)" stripped from German GDPR template', async () => {
    const html = await callPreview({
      settings: previewSettings({
        default_lang: 'de',
        dpo: { name: 'Max Mustermann', email: 'dpo@acme.test', address: '' },
      }),
    });
    expect(html, 'German template still says "(DSB)"').not.toContain('(DSB)');
    expect(html, 'German Datenschutzbeauftragter label missing').toContain('Datenschutzbeauftragter');
  });

  test('#6 EDPB removed (EN) — "European Data Protection Board" stripped from GDPR EN template', async () => {
    const html = await callPreview({
      settings: previewSettings({ default_lang: 'en' }),
    });
    expect(html, 'EDPB sentence still rendered in EN policy').not.toContain('European Data Protection Board');
    // The "Supervisory authority" H2 header should also be gone.
    expect(html, 'Supervisory authority section header still rendered').not.toMatch(/<h2>\s*Supervisory authority\s*<\/h2>/);
  });

  test('wp-internal cookies excluded from the rendered policy', async ({ wpBaseURL }) => {
    const visitor = await adminPage.context().browser()!.newContext();
    const page = await visitor.newPage();
    try {
      await page.goto(`${wpBaseURL}${POLICY_PUBLIC_PATH}`, { waitUntil: 'domcontentloaded' });
      const articleHtml = await page.locator('article.faz-cookie-policy').innerHTML().catch(() => '');
      expect(articleHtml.length, 'policy article not rendered').toBeGreaterThan(100);
      expect(articleHtml, 'wp-settings-1 leaked into public policy').not.toContain('wp-settings-1');
      expect(articleHtml, 'wp-settings-time-* leaked into public policy').not.toContain('wp-settings-time-');
      // The dedicated admin category must not appear either.
      expect(articleHtml, 'wordpress-internal category leaked into public policy').not.toContain('wordpress-internal');
    } finally {
      await visitor.close();
    }
  });
});
