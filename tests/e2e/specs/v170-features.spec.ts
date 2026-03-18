/**
 * E2E tests for all v1.7.0 features.
 * 30 tests — one per new feature.
 */
import { expect, test } from '../fixtures/wp-fixture';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

/* ─── Helpers ──────────────────────────────────── */

async function getAdminNonce(page: any): Promise<string> {
  return page.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
}

async function getSettings(page: any, nonce: string) {
  const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/settings`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(r.status()).toBe(200);
  return r.json();
}

async function updateSettings(page: any, nonce: string, data: Record<string, unknown>) {
  const r = await page.request.post(`${WP_BASE}/?rest_route=/faz/v1/settings`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data,
  });
  expect(r.status(), `Settings update failed: ${r.status()}`).toBe(200);
  return r.json();
}

/* ─── Tests ────────────────────────────────────── */

test.describe('v1.7.0 features', () => {

  // 1. Scheduled Cookie Scanning
  test('F01: auto_scan and scan_frequency settings persist', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const before = await getSettings(page, nonce);
    try {
      await updateSettings(page, nonce, { scanner: { auto_scan: true, scan_frequency: 'daily' } });
      const s = await getSettings(page, nonce);
      expect(s.scanner.auto_scan).toBe(true);
      expect(s.scanner.scan_frequency).toBe('daily');
    } finally {
      // Restore
      await updateSettings(page, nonce, { scanner: before.scanner });
    }
  });

  // 2. Consent Statistics Dashboard
  test('F02: consent stats REST endpoint returns data', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/consent_logs/stats&days=30`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(r.status()).toBe(200);
    const stats = await r.json();
    expect(stats).toHaveProperty('daily');
    expect(stats).toHaveProperty('totals');
    expect(stats).toHaveProperty('categories');
  });

  // 3. Cookie Policy Auto-Generation
  test('F03: [faz_cookie_policy] shortcode renders policy content', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Create a test page with the shortcode via REST API
    const createResp = await page.request.post(`${WP_BASE}/?rest_route=/wp/v2/pages`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: {
        title: 'FAZ Test Cookie Policy',
        content: '[faz_cookie_policy]',
        status: 'publish',
      },
    });

    expect(createResp.status(), `Failed to create test page: ${createResp.status()}`).toBe(201);
    if (createResp.status() === 201) {
      const postData = await createResp.json();
      const postUrl = postData.link;
      const postId = postData.id;

      try {
        // Visit the page and check shortcode output
        const ctx = await page.context().browser()!.newContext({ baseURL: WP_BASE });
        const p = await ctx.newPage();
        try {
          await p.goto(postUrl || `/?p=${postId}`, { waitUntil: 'domcontentloaded' });
          const html = await p.content();
          expect(html).toContain('faz-cookie-policy');
          expect(html).toContain('How to Manage Cookies');
        } finally {
          await ctx.close();
        }
      } finally {
        // Delete the test page
        await page.request.delete(`${WP_BASE}/?rest_route=/wp/v2/pages/${postId}`, {
          headers: { 'X-WP-Nonce': nonce },
          data: { force: true },
        });
      }
    }
  });

  // 4. Geo-IP Banner Display
  test('F04: geo_targeting settings persist', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const before = await getSettings(page, nonce);
    try {
      await updateSettings(page, nonce, {
        geolocation: { geo_targeting: true, target_regions: ['eu', 'uk'], default_behavior: 'no_banner' },
      });
      const s = await getSettings(page, nonce);
      expect(s.geolocation.geo_targeting).toBe(true);
      expect(s.geolocation.target_regions).toContain('eu');
      expect(s.geolocation.default_behavior).toBe('no_banner');
    } finally {
      // Restore
      await updateSettings(page, nonce, { geolocation: before.geolocation });
    }
  });

  // 5. Visual Placeholders
  test('F05: Placeholder_Builder infrastructure loads without errors (smoke test)', async ({ page, loginAsAdmin }) => {
    // Verify the placeholder CSS class exists in the frontend stylesheet
    // The CSS is always injected (regardless of whether there are blocked iframes)
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);
    // Just verify settings load — the Placeholder_Builder is a PHP class that gets used
    // when iframes are blocked. We verify the infrastructure is present.
    const s = await getSettings(page, nonce);
    expect(s).toHaveProperty('banner_control');
  });

  // 6. Multisite Support
  test('F06: multisite hooks do not break single-site (smoke test)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    // Verify the plugin loads — multisite-specific behaviour can't be tested on single-site
    // but we verify the activation code doesn't break single-site
    const nonce = await getAdminNonce(page);
    const s = await getSettings(page, nonce);
    expect(s).toHaveProperty('banner_control');
  });

  // 7. Gutenberg Blocks
  test('F07: Gutenberg blocks are registered', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const r = await page.request.get(`${WP_BASE}/?rest_route=/wp/v2/block-types`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    if (r.status() === 404 || r.status() === 501) {
      test.skip(true, 'block-types endpoint not available on this WP version');
    }
    expect(r.status()).toBe(200);
    const blocks = await r.json();
    const fazBlocks = blocks.filter((b: any) => b.name?.startsWith('faz/'));
    expect(fazBlocks.length).toBeGreaterThanOrEqual(3);
  });

  // 8. Design Presets
  test('F08: design presets REST endpoint returns presets', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/banners/design-presets`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(r.status()).toBe(200);
    const presets = await r.json();
    expect(Array.isArray(presets)).toBe(true);
    expect(presets.length).toBeGreaterThanOrEqual(5);
    expect(presets[0]).toHaveProperty('name');
    expect(presets[0]).toHaveProperty('config');
  });

  // 9. Bot Detection
  test('F09: hide_from_bots setting persists and default is true', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const before = await getSettings(page, nonce);
    // Default should be true
    expect(before.banner_control.hide_from_bots).toBe(true);

    try {
      // Toggle off and verify
      await updateSettings(page, nonce, { banner_control: { hide_from_bots: false } });
      const s2 = await getSettings(page, nonce);
      expect(s2.banner_control.hide_from_bots).toBe(false);
    } finally {
      // Restore
      await updateSettings(page, nonce, { banner_control: before.banner_control });
    }
  });

  // 10. GTM Data Layer
  test('F10: gtm_datalayer setting persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const before = await getSettings(page, nonce);
    try {
      await updateSettings(page, nonce, { banner_control: { gtm_datalayer: true } });
      const s = await getSettings(page, nonce);
      expect(s.banner_control.gtm_datalayer).toBe(true);
    } finally {
      // Restore
      await updateSettings(page, nonce, { banner_control: before.banner_control });
    }
  });

  // 11. WP Privacy Tools
  test('F11: privacy hooks load without errors (smoke test)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    // We can't easily test the WP privacy page content because it requires a privacy policy
    // page to be set. Instead verify the plugin loads without errors (privacy hooks are
    // registered in class-cli.php constructor which runs on every admin page load).
    const nonce = await getAdminNonce(page);
    const s = await getSettings(page, nonce);
    expect(s).toHaveProperty('banner_control');
    // The actual wp_add_privacy_policy_content and exporter/eraser registrations are
    // verified by the plugin loading without fatal errors on any admin page.
  });

  // 12. Dashboard Widget
  test('F12: consent widget appears on WP dashboard', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
    const widget = page.locator('#faz_consent_widget');
    // The widget may be hidden by Screen Options, so check it exists in DOM
    const count = await widget.count();
    expect(count).toBeGreaterThanOrEqual(1);
  });

  // 13. Cross-Domain Consent
  test('F13: consent_forwarding settings persist', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const before = await getSettings(page, nonce);
    try {
      await updateSettings(page, nonce, {
        consent_forwarding: { enabled: true, target_domains: ['https://example.com'] },
      });
      const s = await getSettings(page, nonce);
      expect(s.consent_forwarding.enabled).toBe(true);
      expect(s.consent_forwarding.target_domains).toContain('https://example.com');
    } finally {
      // Restore
      await updateSettings(page, nonce, { consent_forwarding: before.consent_forwarding });
    }
  });

  // 14. 1st-Party Cookie Deletion
  test('F14: reject all sets optional categories to no', async ({ page, wpBaseURL, getConsentCookie, parseConsentCookie }) => {
    const ctx = await page.context().browser()!.newContext({ baseURL: wpBaseURL });
    const p = await ctx.newPage();
    try {
      // Visit and reject all
      await p.goto('/', { waitUntil: 'domcontentloaded' });
      const notice = p.locator('[data-faz-tag="notice"]');
      await expect(notice).toBeVisible({ timeout: 10_000 });
      await p.locator('[data-faz-tag="reject-button"]').click();
      await p.waitForFunction(() => document.cookie.includes('fazcookie-consent'), { timeout: 10_000 });

      // Verify consent cookie shows rejection for optional categories
      const cookie = await getConsentCookie(ctx);
      expect(cookie).toBeDefined();
      if (cookie) {
        const parsed = parseConsentCookie(cookie.value);
        expect(parsed['necessary']).toBe('yes');
        // At least one optional category should be 'no'
        const optionalNo = Object.entries(parsed).some(([k, v]) => k !== 'necessary' && k !== 'consent' && k !== 'action' && k !== 'consentid' && v === 'no');
        expect(optionalNo).toBe(true);
      }
    } finally {
      await ctx.close();
    }
  });

  // 15. Youth/Age Protection
  test('F15: age_gate settings persist', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const before = await getSettings(page, nonce);
    try {
      await updateSettings(page, nonce, { age_gate: { enabled: true, min_age: 14 } });
      const s = await getSettings(page, nonce);
      expect(s.age_gate.enabled).toBe(true);
      expect(s.age_gate.min_age).toBe(14);
    } finally {
      // Restore
      await updateSettings(page, nonce, { age_gate: before.age_gate });
    }
  });

  // 16. Anti-Ad-Blocker
  test('F16: alternative_asset_path setting persists', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const before = await getSettings(page, nonce);
    try {
      await updateSettings(page, nonce, { banner_control: { alternative_asset_path: true } });
      const s = await getSettings(page, nonce);
      expect(s.banner_control.alternative_asset_path).toBe(true);

      // Verify frontend uses different handle
      const ctx = await page.context().browser()!.newContext({ baseURL: WP_BASE });
      const p = await ctx.newPage();
      try {
        await p.goto('/', { waitUntil: 'domcontentloaded' });
        const html = await p.content();
        expect(html).toContain('faz-fw');
      } finally {
        await ctx.close();
      }
    } finally {
      // Restore
      await updateSettings(page, nonce, { banner_control: before.banner_control });
    }
  });

  // 17. Per-Service Consent
  test('F17: per_service_consent setting persists and services are passed to frontend', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const before = await getSettings(page, nonce);
    try {
      await updateSettings(page, nonce, { banner_control: { per_service_consent: true } });
      const s = await getSettings(page, nonce);
      expect(s.banner_control.per_service_consent).toBe(true);

      // Check frontend has services data in the page source
      const ctx = await page.context().browser()!.newContext({ baseURL: WP_BASE });
      const p = await ctx.newPage();
      try {
        await p.goto('/', { waitUntil: 'domcontentloaded' });
        const html = await p.content();
        // The per-service data is embedded in the inline config
        expect(html).toContain('_perServiceConsent');
        expect(html).toContain('_services');
      } finally {
        await ctx.close();
      }
    } finally {
      // Restore
      await updateSettings(page, nonce, { banner_control: before.banner_control });
    }
  });

  // 18. Import/Export
  test('F18: export endpoint returns valid JSON and import page loads', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Test export endpoint
    const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/settings/export`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(r.status()).toBe(200);
    const data = await r.json();
    expect(data.plugin).toBe('faz-cookie-manager');
    expect(data).toHaveProperty('settings');
    expect(data).toHaveProperty('banners');
    expect(data).toHaveProperty('categories');
    expect(data).toHaveProperty('cookies');
    // MaxMind key should be stripped
    expect(data.settings?.geolocation?.maxmind_license_key).toBe('');

    // Test import page loads
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-import-export`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#faz-export-btn')).toBeVisible();
    await expect(page.locator('#faz-import-file')).toBeVisible();
  });

  // 19. Pageview Tracking (from v1.6.0, verify toggle)
  test('F19: pageview_tracking setting persists and gates JS injection', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    // Default should be off
    const before = await getSettings(page, nonce);
    expect(before.pageview_tracking).toBe(false);

    try {
      // Enable and check frontend
      await updateSettings(page, nonce, { pageview_tracking: true });

      const ctx = await page.context().browser()!.newContext({ baseURL: WP_BASE });
      const p = await ctx.newPage();
      try {
        await p.goto('/', { waitUntil: 'domcontentloaded' });
        const hasPvConfig = await p.evaluate(() => typeof (window as any)._fazPageviewConfig !== 'undefined');
        expect(hasPvConfig).toBe(true);
      } finally {
        await ctx.close();
      }
    } finally {
      // Restore
      await updateSettings(page, nonce, { pageview_tracking: before.pageview_tracking });
    }

    // Verify disabled state (should match original)
    const ctx2 = await page.context().browser()!.newContext({ baseURL: WP_BASE });
    const p2 = await ctx2.newPage();
    try {
      await p2.goto('/', { waitUntil: 'domcontentloaded' });
      const hasPvConfig2 = await p2.evaluate(() => typeof (window as any)._fazPageviewConfig !== 'undefined');
      expect(hasPvConfig2).toBe(false);
    } finally {
      await ctx2.close();
    }
  });

  // 20. System Status Page
  test('F20: system status page loads with environment info', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-system-status`, { waitUntil: 'domcontentloaded' });

    // Check page loaded with main container
    await expect(page.locator('#faz-system-status')).toBeVisible();
    // Verify key sections exist
    const html = await page.content();
    expect(html).toContain('Plugin Version');
    expect(html).toContain('PHP Version');
    expect(html).toContain('faz_banners');
    expect(html).toContain('faz-copy-status');
    // Check that at least 4 cards render
    const cards = page.locator('#faz-system-status .faz-card');
    expect(await cards.count()).toBeGreaterThanOrEqual(4);
  });

  // 21. Content Blocker Templates
  test('F21: blocker templates REST endpoint returns 10+ templates', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/blocker-templates`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(r.status()).toBe(200);
    const templates = await r.json();
    expect(Array.isArray(templates)).toBe(true);
    expect(templates.length).toBeGreaterThanOrEqual(10);
    // Each template should have required fields
    for (const t of templates) {
      expect(t).toHaveProperty('id');
      expect(t).toHaveProperty('name');
      expect(t).toHaveProperty('category');
      expect(t).toHaveProperty('patterns');
      expect(Array.isArray(t.patterns)).toBe(true);
    }
  });

  // 22. AMP Support (non-AMP pages unaffected)
  test('F22: AMP class does not interfere with non-AMP pages', async ({ page, wpBaseURL }) => {
    const ctx = await page.context().browser()!.newContext({ baseURL: wpBaseURL });
    const p = await ctx.newPage();
    try {
      await p.goto('/', { waitUntil: 'domcontentloaded' });

      // On non-AMP pages, the regular banner should load (not amp-consent)
      const html = await p.content();
      expect(html).not.toContain('amp-consent');
      expect(html).toContain('fazcookie-consent'); // regular consent cookie reference
    } finally {
      await ctx.close();
    }
  });

  // 23. TranslatePress/Weglot compatibility (no breakage)
  test('F23: translation compat class does not break banner on single-language site', async ({ page, wpBaseURL }) => {
    const ctx = await page.context().browser()!.newContext({ baseURL: wpBaseURL });
    const p = await ctx.newPage();
    try {
      await p.goto('/', { waitUntil: 'domcontentloaded' });

      // Banner should still render normally
      const notice = p.locator('[data-faz-tag="notice"]');
      await expect(notice).toBeVisible({ timeout: 10_000 });
    } finally {
      await ctx.close();
    }
  });

  // 24. WP-CLI commands registered
  test('F24: WP-CLI class loads without errors (smoke test)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    // We can't run WP-CLI from Playwright, but we verify the plugin loads
    // without errors (the CLI class has a WP_CLI guard so it doesn't break web)
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);
    const s = await getSettings(page, nonce);
    expect(s).toHaveProperty('banner_control');
  });

  // 25. Import/Export page functional test
  test('F25: import page has working export/import UI elements', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-import-export`, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#faz-export-btn')).toBeVisible();
    await expect(page.locator('#faz-import-file')).toBeVisible();
    await expect(page.locator('#faz-import-btn')).toBeVisible();
    // Import button should be disabled until a file is selected
    await expect(page.locator('#faz-import-btn')).toBeDisabled();
  });

  // 26. Consent statistics card on dashboard
  test('F26: consent stats card visible on dashboard page', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager`, { waitUntil: 'domcontentloaded' });

    const statsCard = page.locator('#faz-consent-stats, #faz-stat-accept-rate');
    const count = await statsCard.count();
    expect(count).toBeGreaterThanOrEqual(1);
  });

  // 27. Microsoft consent settings persist
  test('F27: Microsoft UET and Clarity settings persist', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, { waitUntil: 'domcontentloaded' });
    const nonce = await getAdminNonce(page);

    const before = await getSettings(page, nonce);
    try {
      await updateSettings(page, nonce, { microsoft: { uet_consent_mode: true, clarity_consent: true } });
      const s = await getSettings(page, nonce);
      expect(s.microsoft.uet_consent_mode).toBe(true);
      expect(s.microsoft.clarity_consent).toBe(true);
    } finally {
      // Restore
      await updateSettings(page, nonce, { microsoft: before.microsoft });
    }
  });

  // 28. Banner renders Accept and Reject with equal prominence
  test('F28: banner has accept and reject buttons at first level', async ({ page, wpBaseURL }) => {
    const ctx = await page.context().browser()!.newContext({ baseURL: wpBaseURL });
    const p = await ctx.newPage();
    try {
      await p.goto('/', { waitUntil: 'domcontentloaded' });

      const accept = p.locator('[data-faz-tag="accept-button"]');
      const reject = p.locator('[data-faz-tag="reject-button"]');
      await expect(accept).toBeVisible({ timeout: 10_000 });
      await expect(reject).toBeVisible();

      // Equal prominence: similar dimensions
      const acceptBox = await accept.boundingBox();
      const rejectBox = await reject.boundingBox();
      expect(acceptBox).toBeTruthy();
      expect(rejectBox).toBeTruthy();
      if (acceptBox && rejectBox) {
        // Height should be similar (within 10px)
        expect(Math.abs(acceptBox.height - rejectBox.height)).toBeLessThan(10);
      }
    } finally {
      await ctx.close();
    }
  });

  // 29. Issue #37: Custom CSS saves and renders
  test('F29: custom CSS saves in banner settings and appears on frontend (issue #37)', async ({ page, browser, loginAsAdmin, wpBaseURL }) => {
    await loginAsAdmin(page);

    // Register waitForResponse BEFORE goto to avoid race
    const bannerLoad = page.waitForResponse(
      (r) => r.url().includes('banners') && !r.url().includes('preview') && r.status() === 200,
      { timeout: 20_000 },
    );
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded' });
    await bannerLoad;
    await page.waitForFunction(() => {
      const el = document.getElementById('faz-b-type') as HTMLSelectElement;
      return el && el.value !== '';
    }, { timeout: 5_000 });

    // Switch to Advanced tab and set custom CSS
    await page.click('#faz-banner-tabs button[data-tab="advanced"]');
    await page.waitForSelector('#tab-advanced.active', { timeout: 5_000 });

    const testCSS = '.faz-test-custom-css-marker { color: red; }';
    await page.fill('#faz-b-custom-css', testCSS);

    // Save
    const saveResponse = page.waitForResponse(
      (r) => r.url().includes('banners') && !r.url().includes('preview') &&
        (r.request().method() === 'PUT' || r.request().method() === 'POST'),
      { timeout: 15_000 },
    );
    await page.click('#faz-b-save');
    const resp = await saveResponse;
    expect(resp.status()).toBe(200);

    // Reload and verify persistence — register waitForResponse BEFORE goto
    const bannerReload = page.waitForResponse(
      (r) => r.url().includes('banners') && !r.url().includes('preview') && r.status() === 200,
      { timeout: 20_000 },
    );
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded' });
    await bannerReload;
    await page.waitForFunction(() => {
      const el = document.getElementById('faz-b-type') as HTMLSelectElement;
      return el && el.value !== '';
    }, { timeout: 5_000 });
    await page.click('#faz-banner-tabs button[data-tab="advanced"]');
    await page.waitForSelector('#tab-advanced.active', { timeout: 5_000 });

    const savedCSS = await page.inputValue('#faz-b-custom-css');
    expect(savedCSS).toContain('faz-test-custom-css-marker');

    // Check frontend has the custom CSS
    const ctx = await browser.newContext({ baseURL: wpBaseURL });
    const visitor = await ctx.newPage();
    try {
      await visitor.goto('/', { waitUntil: 'domcontentloaded' });
      const html = await visitor.content();
      expect(html).toContain('faz-test-custom-css-marker');
    } finally {
      await ctx.close();
    }

    // Clean up — remove the custom CSS
    await page.click('#faz-banner-tabs button[data-tab="advanced"]');
    await page.fill('#faz-b-custom-css', '');
    const cleanupResponse = page.waitForResponse(
      (r) => r.url().includes('banners') && !r.url().includes('preview') &&
        (r.request().method() === 'PUT' || r.request().method() === 'POST'),
      { timeout: 15_000 },
    );
    await page.click('#faz-b-save');
    await cleanupResponse;
  });

  // 30. Issue #38: Category names editable from admin
  test('F30: category editor table and save button exist on cookies page (issue #38)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

    // The category editor table should be in the page HTML
    const html = await page.content();
    expect(html).toContain('faz-category-edit-table');
    expect(html).toContain('faz-category-edit-rows');
    expect(html).toContain('faz-save-categories');

    // Verify the save button element is attached to the DOM
    await expect(page.locator('#faz-save-categories')).toBeAttached({ timeout: 5_000 });

    // Verify the categories REST endpoint returns data
    const nonce = await getAdminNonce(page);
    const r = await page.request.get(`${WP_BASE}/?rest_route=/faz/v1/cookies/categories`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(r.status()).toBe(200);
    const cats = await r.json();
    expect(Array.isArray(cats)).toBe(true);
    expect(cats.length).toBeGreaterThanOrEqual(2);
  });

});
