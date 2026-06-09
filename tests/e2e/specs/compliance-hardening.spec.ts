import { expect, test } from '../fixtures/wp-fixture';
import { deactivatePluginsExcept, wpEval } from '../utils/wp-env';

/**
 * Browser-observable compliance checks for the 2026-06 hardening pass
 * (branch compliance/full-review-hardening). Pure-logic / config assertions
 * live in tests/unit/test-compliance-hardening.php; this file covers behaviour
 * that only manifests in a real browser:
 *
 *   - Global Privacy Control (GPC) auto-opt-out and banner suppression
 *   - GPC gated behind the per-banner respectGPC toggle
 *   - Cookie Policy heading exposes aria-level (axe-critical WCAG 4.1.2 fix)
 *
 * GPC is injected via addInitScript because navigator.globalPrivacyControl is
 * a read-only accessor; defineProperty installs it before any plugin script
 * runs, exactly as Brave / Firefox / DuckDuckGo would.
 */

// Installs navigator.globalPrivacyControl === true before page scripts run.
const INJECT_GPC = () => {
  Object.defineProperty(navigator, 'globalPrivacyControl', {
    configurable: true,
    get: () => true,
  });
};

// Toggle behaviours.respectGPC on every banner via the plugin's own save path
// so the transient-backed banner cache is invalidated (a raw $wpdb->update
// leaves the cached copy stale — the frontend would keep reading the old
// value). Also busts the rendered-template cache.
function setRespectGpc(enabled: boolean): void {
  const flag = enabled ? 'true' : 'false';
  wpEval(`
    $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
    global $wpdb;
    $ids = $wpdb->get_col( "SELECT banner_id FROM {$wpdb->prefix}faz_banners" );
    foreach ( (array) $ids as $id ) {
      $banner = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( (int) $id );
      $s = $banner->get_settings();
      if ( ! is_array( $s ) ) { continue; }
      if ( ! isset( $s['behaviours'] ) || ! is_array( $s['behaviours'] ) ) { $s['behaviours'] = array(); }
      $s['behaviours']['respectGPC'] = array( 'status' => ${flag} );
      $banner->set_settings( $s );
      $banner->update( $banner );
    }
    $ctrl->delete_cache();
    delete_option( 'faz_banner_template' );
    echo 'ok';
  `);
}

const OPTIONAL_EXCLUDED = ['consentid', 'consent', 'action', 'necessary', '__scope.banner', '__scope.law', '__scope.fp'];

test.describe('Compliance hardening — GPC honoring (enabled)', () => {
  test.beforeAll(() => {
    deactivatePluginsExcept(['faz-cookie-manager']);
    setRespectGpc(true);
  });

  test.afterAll(() => {
    setRespectGpc(false);
  });

  test('GPC signal auto-applies opt-out and suppresses the banner', async ({ page, context, getConsentCookie, parseConsentCookie }) => {
    await context.clearCookies();
    await page.addInitScript(INJECT_GPC);
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // GPC is a legally-valid opt-out — the first-layer banner must not block.
    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeHidden({ timeout: 10_000 });

    // An opt-out (reject) consent decision was recorded automatically.
    const consent = await getConsentCookie(context);
    expect(consent, 'GPC must persist a consent cookie without a click').toBeDefined();
    const parsed = parseConsentCookie(consent!.value);
    expect(parsed.action).toBe('yes');
    expect(parsed.necessary).toBe('yes');
    // GDPR reject path sets consent:no.
    expect(parsed.consent).toBe('no');
    // At least one non-necessary category denied.
    const optional = Object.entries(parsed).filter(([key]) => !OPTIONAL_EXCLUDED.includes(key));
    expect(optional.length, 'banner must expose optional categories').toBeGreaterThan(0);
    expect(optional.some(([, value]) => value === 'no'), 'a non-necessary category must be denied under GPC').toBeTruthy();
  });

  test('GPC opt-out blocks non-technical cookies', async ({ page, context, getNonTechnicalCookies }) => {
    await context.clearCookies();
    await page.addInitScript(INJECT_GPC);
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    // Give any (wrongly) unblocked trackers a tick to set cookies.
    await page.waitForTimeout(1000);
    const nonTechnical = await getNonTechnicalCookies(context);
    expect(nonTechnical, `No non-technical cookie may be set under a GPC opt-out: ${JSON.stringify(nonTechnical)}`).toHaveLength(0);
  });

  test('without a GPC signal the banner is still shown (regression)', async ({ page, context }) => {
    await context.clearCookies();
    // No INJECT_GPC here.
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('Compliance hardening — GPC honoring (disabled)', () => {
  test.beforeAll(() => {
    deactivatePluginsExcept(['faz-cookie-manager']);
    setRespectGpc(false);
  });

  test('GPC is ignored when respectGPC is off — banner is shown', async ({ page, context }) => {
    await context.clearCookies();
    await page.addInitScript(INJECT_GPC);
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const notice = page.locator('[data-faz-tag="notice"]');
    await expect(notice, 'with respectGPC off, GPC must not auto-dismiss the banner').toBeVisible({ timeout: 10_000 });
  });
});

test.describe('Compliance hardening — Cookie Policy accessibility', () => {
  let policyUrl = '';

  test.beforeAll(() => {
    deactivatePluginsExcept(['faz-cookie-manager']);
    policyUrl = wpEval(`
      $existing = get_page_by_path( 'faz-compliance-policy-test' );
      if ( $existing ) {
        $id = $existing->ID;
      } else {
        $id = wp_insert_post( array(
          'post_title'   => 'FAZ Compliance Policy Test',
          'post_name'    => 'faz-compliance-policy-test',
          'post_status'  => 'publish',
          'post_type'    => 'page',
          'post_content' => '[faz_cookie_policy_complete]',
        ) );
      }
      echo get_permalink( $id );
    `).trim();
  });

  test('category-name heading exposes aria-level (WCAG 4.1.2 / axe-critical)', async ({ page }) => {
    expect(policyUrl).toMatch(/^https?:\/\//);
    await page.goto(policyUrl, { waitUntil: 'domcontentloaded' });

    const headings = page.locator('.faz-cookie-policy-category-name[role="heading"]');
    const count = await headings.count();
    expect(count, 'cookie policy must render category headings').toBeGreaterThan(0);

    // Every heading-role span must carry an aria-level (the kses fix).
    for (let i = 0; i < count; i++) {
      const level = await headings.nth(i).getAttribute('aria-level');
      expect(level, 'role="heading" must carry aria-level').toBeTruthy();
      expect(Number(level)).toBeGreaterThan(0);
    }
  });

  test('no heading-role element is left without aria-level', async ({ page }) => {
    await page.goto(policyUrl, { waitUntil: 'domcontentloaded' });
    const orphanHeadings = await page.evaluate(() => {
      const nodes = Array.from(document.querySelectorAll('[role="heading"]'));
      return nodes.filter((n) => !n.hasAttribute('aria-level')).length;
    });
    expect(orphanHeadings, 'every ARIA heading must declare its level').toBe(0);
  });
});
