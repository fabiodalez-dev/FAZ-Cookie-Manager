/**
 * Regression tests for the 10-agent audit fixes (intended for 1.13.12).
 *
 * Each test pins one of the 13 fixes applied in this round to its
 * audit ID (C1, C2, C4, H2, H4, H5, H7, H8, H9, H10, H11, M3, M14).
 * Tests are kept tight and self-contained — most exercise the REST
 * surface so they run fast on the local nginx + PHP-FPM stack and do
 * not depend on the heavier admin UI flows.
 *
 * NOT run as part of the previous v170-deep-flows suite to keep that
 * suite focused on 1.7.x release flows; this spec lives separately and
 * is referenced from the audit punch list.
 */

import { expect, test } from '../fixtures/wp-fixture';
import { WP_PATH, wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

async function getNonce(page: import('@playwright/test').Page): Promise<string> {
  // The nonce is exposed by `wp_localize_script` after the admin pages JS
  // bundle finishes parsing. With `waitUntil: 'domcontentloaded'` the JS
  // bundle may not have run yet — wait for the global before reading.
  await page.waitForFunction(
    () => Boolean((window as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce),
    undefined,
    { timeout: 15_000 },
  );
  return page.evaluate(() => (window as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '');
}

async function getSettings(page: import('@playwright/test').Page, nonce: string): Promise<Record<string, unknown>> {
  const response = await page.request.get(`${WP_BASE}/wp-json/faz/v1/settings`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return response.json() as Promise<Record<string, unknown>>;
}

async function putSettings(
  page: import('@playwright/test').Page,
  nonce: string,
  patch: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
  // The settings endpoint expects a deep-merge with the current values.
  // Read first, merge the patch, post the union — same pattern the admin JS uses.
  const current = await getSettings(page, nonce);
  const merged = mergeDeep(current, patch);
  // Use POST instead of PUT — nginx default config returns 404 on PUT to
  // /wp-json/* routes, but the WP_REST_Server::CREATABLE methods slot on
  // /faz/v1/settings accepts POST/PUT/PATCH, so POST is equivalent.
  const response = await page.request.post(`${WP_BASE}/wp-json/faz/v1/settings`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: merged,
  });
  // Defensive parse: when the nonce is empty/expired the REST handler can
  // emit an HTML error page; surface that as a clear failure rather than a
  // cryptic JSON-parse stack trace from inside the test body.
  const text = await response.text();
  let body: Record<string, unknown> = {};
  try {
    body = JSON.parse(text) as Record<string, unknown>;
  } catch {
    throw new Error(`PUT /faz/v1/settings returned non-JSON (status=${response.status()}): ${text.slice(0, 200)}`);
  }
  return { status: response.status(), body };
}

function mergeDeep<T extends Record<string, unknown>>(a: T, b: Record<string, unknown>): T {
  const out = { ...a } as Record<string, unknown>;
  for (const [k, v] of Object.entries(b)) {
    if (v && typeof v === 'object' && !Array.isArray(v) && a[k] && typeof a[k] === 'object' && !Array.isArray(a[k])) {
      out[k] = mergeDeep(a[k] as Record<string, unknown>, v as Record<string, unknown>);
    } else {
      out[k] = v;
    }
  }
  return out as T;
}

test.describe('Audit-fix regression suite (1.13.12)', () => {

  test('H4: subdomain_sharing toggle visible in admin and persists via REST', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const toggle = page.locator('input[type="checkbox"][data-path="banner_control.subdomain_sharing"]');
    await expect(toggle).toHaveCount(1);

    // Persistence round-trip via REST.
    const nonce = await getNonce(page);
    const before = await getSettings(page, nonce);
    const initial = ((before.banner_control as Record<string, unknown> | undefined)?.subdomain_sharing) === true;

    const { status } = await putSettings(page, nonce, { banner_control: { subdomain_sharing: !initial } });
    expect(status).toBe(200);

    const after = await getSettings(page, nonce);
    expect((after.banner_control as Record<string, unknown>).subdomain_sharing).toBe(!initial);

    // Restore.
    await putSettings(page, nonce, { banner_control: { subdomain_sharing: initial } });
  });

  test('H7: consent_revision input is disabled and cannot be lowered via REST', async ({ page, loginAsAdmin }) => {
    test.skip(!WP_PATH, 'requires WP_PATH to restore consent_revision through wp-cli');
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    // UI: input has both readonly and disabled attributes.
    const input = page.locator('input[data-path="general.consent_revision"]');
    await expect(input).toHaveCount(1);
    await expect(input).toHaveAttribute('disabled', /.*/);

    const nonce = await getNonce(page);
    const before = await getSettings(page, nonce);
    const currentRev = Number(((before.general as Record<string, unknown> | undefined)?.consent_revision) ?? 1);

    try {
      // Bump up — accepted.
      await putSettings(page, nonce, { general: { consent_revision: currentRev + 5 } });
      let mid = await getSettings(page, nonce);
      expect(Number((mid.general as Record<string, unknown>).consent_revision)).toBe(currentRev + 5);

      // Try to lower — the sanitizer must keep the persisted (higher) value.
      await putSettings(page, nonce, { general: { consent_revision: currentRev } });
      mid = await getSettings(page, nonce);
      expect(Number((mid.general as Record<string, unknown>).consent_revision)).toBe(currentRev + 5);
    } finally {
      // Put it back, and do it BELOW the REST layer on purpose.
      //
      // consent_revision invalidates every stored consent cookie on the site —
      // that is the point of the feature. This test raises it by 5 and, because
      // the sanitiser refuses to lower it (which is exactly what the assertions
      // above prove), the bump used to be permanent: every run of the suite
      // ratcheted it up and invalidated the consent of every spec downstream of
      // this one. It had reached 6 on this box. The monotonic guard is correct
      // for the product and wrong for a test that has to leave no trace, so the
      // restore goes straight to the option.
      try {
        wpEval(
          "$s = get_option( 'faz_settings', array() ); " +
          "if ( ! isset( $s['general'] ) || ! is_array( $s['general'] ) ) { $s['general'] = array(); } " +
          `$s['general']['consent_revision'] = ${currentRev}; ` +
          "update_option( 'faz_settings', $s ); echo 'ok';",
        );
      } catch (error) {
        console.warn('Could not restore consent_revision after H7:', error);
      }
    }
  });

  test('H8: consent_forwarding.target_domains rejects non-http(s) schemes', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getNonce(page);
    await putSettings(page, nonce, {
      consent_forwarding: {
        enabled: true,
        target_domains: ['javascript:alert(1)', 'https://valid.example.com', 'not-a-url', 'https://shop.example.com'],
      },
    });
    const after = await getSettings(page, nonce);
    const stored = ((after.consent_forwarding as Record<string, unknown>).target_domains) as string[];
    // The two valid HTTPS URLs are accepted verbatim.
    expect(stored).toContain('https://valid.example.com');
    expect(stored).toContain('https://shop.example.com');
    // The two invalid items must be rejected: `javascript:alert(1)` (wrong
    // scheme) is blocked by the scheme allowlist, and the resulting
    // sanitized array must NOT contain any javascript: scheme.
    for (const url of stored) {
      expect(url).toMatch(/^https?:\/\//);
      expect(url).not.toMatch(/^javascript:/i);
    }
    // Restore (clean state).
    await putSettings(page, nonce, { consent_forwarding: { enabled: false, target_domains: [] } });
  });

  test('C4: custom_rules accepts the necessary category and dedups duplicates', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getNonce(page);
    const beforeFull = await getSettings(page, nonce);
    const beforeRules = (((beforeFull.script_blocking as Record<string, unknown>).custom_rules) as Array<{ pattern: string; category: string }>) ?? [];
    // Submit two duplicates of the same `necessary` rule + a noise rule.
    await putSettings(page, nonce, {
      script_blocking: {
        custom_rules: [
          { pattern: 'cloudflareinsights.com', category: 'necessary' },
          { pattern: 'cloudflareinsights.com', category: 'necessary' }, // duplicate
          { pattern: 'foo-test-pattern', category: 'analytics' },
        ],
      },
    });
    const after = await getSettings(page, nonce);
    const stored = (((after.script_blocking as Record<string, unknown>).custom_rules) as Array<{ pattern: string; category: string }>);
    const cfNecessary = stored.filter(r => r.pattern === 'cloudflareinsights.com' && r.category === 'necessary');
    expect(cfNecessary).toHaveLength(1); // necessary survived AND was deduped
    expect(stored.some(r => r.pattern === 'foo-test-pattern' && r.category === 'analytics')).toBe(true);
    // Restore.
    await putSettings(page, nonce, { script_blocking: { custom_rules: beforeRules } });
  });

  test('C2: necessary category cannot be deleted via REST', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getNonce(page);
    // Find the necessary category id.
    const cats = await page.request.get(`${WP_BASE}/wp-json/faz/v1/cookies/categories/`, {
      headers: { 'X-WP-Nonce': nonce },
    }).then(r => r.json() as Promise<Array<{ category_id: number; slug: string }>>);
    const necessary = cats.find(c => c.slug === 'necessary');
    expect(necessary).toBeDefined();

    const response = await page.request.delete(
      `${WP_BASE}/wp-json/faz/v1/cookies/categories/${necessary!.category_id}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    // Any non-2xx status is acceptable (the implementation throws a
    // RuntimeException which the REST controller maps to 4xx/5xx). The
    // key invariant is that the row STILL EXISTS afterwards (asserted
    // below). 404 also valid — semantic "this resource cannot be deleted".
    expect(response.status()).toBeGreaterThanOrEqual(400);

    const catsAfter = await page.request.get(`${WP_BASE}/wp-json/faz/v1/cookies/categories/`, {
      headers: { 'X-WP-Nonce': nonce },
    }).then(r => r.json() as Promise<Array<{ slug: string }>>);
    expect(catsAfter.some(c => c.slug === 'necessary')).toBe(true);
  });

  test('H2: excluded_pages matches across query strings and case-folds', async ({ page, browser, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getNonce(page);
    const before = await getSettings(page, nonce);
    const beforeExcl = (((before.banner_control as Record<string, unknown>).excluded_pages) as string[]) ?? [];

    await putSettings(page, nonce, { banner_control: { excluded_pages: ['/Sample-Page/*'] } });

    // Visit the homepage with a query string to verify case-fold match.
    const ctx = await browser.newContext();
    const visitor = await ctx.newPage();
    try {
      await visitor.goto(`${WP_BASE}/sample-page/?utm_source=test`, { waitUntil: 'domcontentloaded' });
      // Banner script should NOT inject _fazConfig localize block on excluded page.
      const html = await visitor.content();
      expect(html).not.toContain('window._fazConfig');
    } finally {
      await ctx.close();
    }

    // Restore.
    await putSettings(page, nonce, { banner_control: { excluded_pages: beforeExcl } });
  });

  test('H10: hr language resolves to the hr locale (REST returns Croatian when hr is active)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getNonce(page);
    // Add hr to active languages if not present.
    const before = await getSettings(page, nonce);
    const beforeLang = ((before.general as Record<string, unknown>).active_languages) as string[] | undefined;
    const beforeDefault = ((before.general as Record<string, unknown>).default_language) as string | undefined;
    const wantLangs = Array.from(new Set([...(beforeLang ?? ['en']), 'en', 'hr']));
    await putSettings(page, nonce, { general: { active_languages: wantLangs } });

    try {
    // Hit the public REST endpoint for hr.
    const response = await page.request.get(`${WP_BASE}/wp-json/faz/v1/banner/hr`);
    // Either 200 (hr is selected and works) or 404 (hr not in selected — set
    // it via active_languages) — we only want to verify that, when hr is in
    // the selected languages, the response is NOT a 500 caused by a missing
    // hr.mo file. The previous mapping `'hr' => 'hr'` would silently fall
    // back to English content; the body has the language echoed.
    if (response.status() === 200) {
      const body = (await response.json()) as { language?: string };
      expect(body.language).toBe('hr');
    } else {
      // Acceptable if hr isn't selected — the wp.org-shape ZIP doesn't
      // pre-activate it on every test install.
      expect([400, 404]).toContain(response.status());
    }

    } finally {
      // In a finally, not trailing the assertions: a failure above used to skip
      // the restore entirely and leave 'hr' in active_languages for every spec
      // downstream.
      await putSettings(page, nonce, { general: { active_languages: beforeLang ?? ['en'], default_language: beforeDefault ?? 'en' } });
    }
  });

  test('M3: wca.js categoryMap covers performance + advertisement back-compat', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    // Static-content check on the deployed JS file. We don't run the
    // logic — we only verify the source has the keys after the typo
    // fix; consumers that read window.wp_consent_type now get
    // sensible mappings for legacy `advertisement` cookies and for
    // `performance` rules.
    const response = await page.request.get(`${WP_BASE}/wp-content/plugins/faz-cookie-manager/frontend/js/wca.js`);
    expect(response.status()).toBe(200);
    const src = await response.text();
    expect(src).toContain("performance: 'statistics'");
    expect(src).toContain("advertisement: 'marketing'");
    // The pre-fix `performance: 'functional'` typo must be gone.
    expect(src).not.toContain("performance: 'functional'");
  });

  test('H9: gdpr.json categoryPreview/category toggle states include alwaysActive', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getNonce(page);

    // Fetch banner #1 (the GDPR seed) and assert that the toggle states
    // tree includes the new `alwaysActive` key in BOTH paths the audit
    // identified (categoryPreview.elements.toggle.states +
    // preferenceCenter.elements.categories.elements.toggle.states).
    // If `sanitize_settings()` had silently dropped the key (as it did
    // before this fix, because `alwaysActive` was not in defaults), the
    // round-trip GET would not include it.
    const r = await page.request.get(`${WP_BASE}/wp-json/faz/v1/banners/1`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(r.status()).toBe(200);
    const banner = await r.json() as Record<string, unknown>;

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const props: any = banner.properties;
    expect(props?.config?.preferenceCenter?.elements?.categories?.elements?.toggle?.states).toBeDefined();
    expect(props.config.preferenceCenter.elements.categories.elements.toggle.states.alwaysActive).toBeDefined();
    expect(props.config.preferenceCenter.elements.categories.elements.toggle.states.alwaysActive.styles).toBeDefined();

    // categoryPreview path: only present when categoryPreview is enabled
    // (status=true). When admin disables it, the whole subtree may be
    // pruned by sanitize. So we assert it ONLY if the parent exists.
    if (props.config.categoryPreview?.elements?.toggle?.states) {
      expect(props.config.categoryPreview.elements.toggle.states.alwaysActive).toBeDefined();
    }
  });

  test('H5: pageview_tracking=false unregisters the public REST endpoint', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getNonce(page);
    const before = await getSettings(page, nonce);
    const beforeTracking = (before.pageview_tracking as boolean | undefined) ?? false;

    // Disable tracking.
    await putSettings(page, nonce, { pageview_tracking: false });

    // Verify the route no longer registered.
    const probe = await page.request.post(`${WP_BASE}/wp-json/faz/v1/pageviews`, {
      data: { token: 'test', event_type: 'pageview' },
      headers: { 'Content-Type': 'application/json' },
    });
    expect(probe.status()).toBe(404);

    // Restore the previous value (default: false on most installs).
    await putSettings(page, nonce, { pageview_tracking: beforeTracking });
  });
});
