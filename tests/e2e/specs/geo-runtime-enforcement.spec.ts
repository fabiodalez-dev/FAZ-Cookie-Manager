/**
 * Geo-routing runtime enforcement (flag `faz_geo_ruleset_runtime`).
 *
 * Regression guard for the review that caught PR #140: the PHP side produced
 * correct data structures, but the JS that CONSUMES them ignored the ruleset
 * (analytics/marketing got unblocked on first visit because the binary-law path
 * left them "yes"). This spec asserts the actual browser EFFECT, not the PHP
 * data:
 *
 *   - With the flag on and the visitor routed to an opt-in ruleset (POPIA/ZA),
 *     a server-side blocked analytics script stays `type="text/plain"` on the
 *     very first visit (the script does NOT run).
 *   - `_fazStore._runtimeGeo` is true and `_activeLaw` is the ruleset-model law
 *     ('gdpr'), proving banner selection followed the ruleset.
 *   - After the visitor accepts, the same script unblocks and runs — so the
 *     block is consent-gated, not permanently broken.
 *
 * Setup is via a temporary mu-plugin (enables the flag, forces the country, and
 * injects the probe script) written in beforeAll and removed in afterAll, so no
 * global plugin/option state leaks to other specs.
 */
import { mkdirSync, writeFileSync, rmSync, existsSync } from 'fs';
import { join } from 'path';
import { expect, test } from '../fixtures/wp-fixture';
import { clickFirstVisible } from '../utils/ui';
import { WP_PATH, clearAllFazCookieCaches } from '../utils/wp-env';

const MU_DIR = join(WP_PATH, 'wp-content', 'mu-plugins');
const MU_FILE = join(MU_DIR, 'faz-e2e-geo-runtime.php');

const MU_PHP = `<?php
/**
 * E2E-only: force the geo-runtime flag, pin the visitor country to South Africa
 * (POPIA — an opt-in ruleset), and inject a server-side blocked analytics probe.
 * Removed by the spec's afterAll.
 */
add_filter( 'faz_geo_ruleset_runtime', '__return_true' );
add_filter( 'faz_geo_admin_override_country', function () { return 'ZA'; } );
add_filter( 'faz_visitor_country', function () { return 'ZA'; } );
add_action( 'wp_footer', function () {
	echo '<script type="text/plain" data-faz-category="analytics" id="faz-e2e-analytics">window.__fazAnalyticsRan = true;</script>';
}, 5 );
`;

test.describe('geo-runtime enforcement (flag on, POPIA/ZA opt-in)', () => {
  test.beforeAll(() => {
    if (!existsSync(MU_DIR)) {
      mkdirSync(MU_DIR, { recursive: true });
    }
    writeFileSync(MU_FILE, MU_PHP, 'utf8');
    clearAllFazCookieCaches();
  });

  test.afterAll(() => {
    if (existsSync(MU_FILE)) {
      rmSync(MU_FILE);
    }
    clearAllFazCookieCaches();
  });

  test('blocked analytics stays text/plain on first visit; runtime store reflects the ruleset', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // The runtime ruleset is applied: store flags it and the enforced law is the
    // POPIA model (opt-in → 'gdpr'), proving banner selection followed the ruleset.
    // The localized store is the global `_fazConfig` (script.js aliases it to the
    // module-scoped const `_fazStore`, which is NOT a window global).
    const store = await page.evaluate(() => ({
      runtimeGeo: !!(window as any)._fazConfig?._runtimeGeo,
      activeLaw: (window as any)._fazConfig?._activeLaw,
    }));
    expect(store.runtimeGeo).toBe(true);
    expect(store.activeLaw).toBe('gdpr');

    // The probe analytics script must NOT have executed (still blocked).
    const ranBefore = await page.evaluate(() => (window as any).__fazAnalyticsRan === true);
    expect(ranBefore).toBe(false);

    const probeType = await page.getAttribute('#faz-e2e-analytics', 'type');
    expect(probeType).toBe('text/plain');
  });

  test('accepting unblocks the analytics script (block is consent-gated)', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const accepted = await clickFirstVisible(page, [
      '[data-faz-tag="accept-button"] button',
      '[data-faz-tag="accept-button"]',
      '.faz-btn-accept',
    ]);
    expect(accepted).toBeTruthy();

    // After consent the analytics category is granted, so the previously blocked
    // script is cloned and executed.
    await expect
      .poll(async () => page.evaluate(() => (window as any).__fazAnalyticsRan === true), { timeout: 10_000 })
      .toBe(true);
  });
});
