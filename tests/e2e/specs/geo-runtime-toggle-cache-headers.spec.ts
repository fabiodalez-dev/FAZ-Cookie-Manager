/**
 * E2E — Settings > Geolocation > Geo-Targeting drives the page-cache headers.
 *
 * Two wordpress.org support reports (LiteSpeed, FlyingPress) shared one root
 * cause: switching Geo-Targeting OFF in the admin did not switch the
 * jurisdiction runtime off. Geo_Runtime::is_enabled() defaulted to true, so
 * Frontend::is_country_dependent_output() stayed true and every front-end
 * response carried `Cache-Control: no-store, no-cache, must-revalidate,
 * max-age=0` plus `Pragma: no-cache`. Page caching never engaged, and the only
 * cure was hand-writing
 * `add_filter( 'faz_geo_ruleset_runtime', '__return_false' )` in a theme.
 *
 * Nothing in the suite could see that, and the reason is worth stating because
 * it is the reason this file exists. The audit-lab fixture forced the
 * `faz_geo_ruleset_runtime` filter to a constant on every request, so the cache
 * suites proved how caching behaves GIVEN a forced runtime state — never that
 * the admin toggle produces that state. The fixture now takes a third mode
 * (option `faz_e2e_geo_runtime_defer`) in which it registers no filter at all,
 * which is what lets this spec observe the toggle itself.
 *
 * What is asserted, on the REAL response headers of `/` rather than on page
 * content, from an anonymous context:
 *
 *   1. Geo-Targeting OFF -> no FAZ-owned
 *      `X-LiteSpeed-Cache-Control: no-cache`. Generic Cache-Control/Pragma
 *      cannot be used for this half: an unrelated active plugin may start a PHP
 *      session and PHP itself then emits the same generic no-store headers.
 *   2. Geo-Targeting ON -> the LiteSpeed signal and both generic blocking
 *      headers are present. Enforcement genuinely varies the document by
 *      visitor country, so the cache-bust is correct.
 *
 * `geolocation.default_behavior` is pinned to `show_banner` for the duration.
 * With the site's usual `no_banner`, is_country_dependent_output() would ALSO
 * return true through its own geo-gate branch, and step 2 would pass even if the
 * runtime flag were dead — the assertion would be measuring the wrong thing.
 * Pinned to `show_banner`, the runtime flag is the only remaining cause.
 *
 * Everything mutated (the geolocation settings block, the defer option) is
 * restored in a `finally`, so a mid-test throw cannot leak into other specs.
 *
 * Unit counterpart: tests/unit/test-geo-runtime-ui-gate-php.php.
 */

import { test, expect } from '../fixtures/wp-fixture';
import type { Browser } from '@playwright/test';
import { deleteOption, ensureFixturePlugin, setOption, wpEval } from '../utils/wp-env';

const BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

/** Option that puts faz-e2e-audit-lab into defer mode (registers no filter). */
const DEFER_OPTION = 'faz_e2e_geo_runtime_defer';

/**
 * Front-end response headers for `/` as an anonymous visitor.
 *
 * A fresh context per call keeps admin cookies out (a logged-in request takes a
 * different no-cache path in the plugin and would mask the result), and the
 * cache-buster keeps any upstream or Playwright-side reuse from answering with a
 * response captured under the previous setting.
 */
async function anonHomeHeaders(browser: Browser, label: string): Promise<Record<string, string>> {
  const ctx = await browser.newContext();
  try {
    const res = await ctx.request.get(`${BASE}/?faz_e2e_cb=${label}-${Date.now()}-${Math.random().toString(36).slice(2)}`, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (geo-runtime-toggle-e2e)',
        'Cache-Control': 'no-cache',
        Pragma: 'no-cache',
      },
    });
    expect(res.status(), `${label}: unexpected status ${res.status()}`).toBeLessThan(400);
    return res.headers();
  } finally {
    await ctx.close();
  }
}

/** Read the current settings straight from WordPress, independent of the browser. */
function readGeolocationSettings(): string {
  return wpEval(`
    $s = get_option( 'faz_settings', array() );
    if ( ! is_array( $s ) ) { $s = array(); }
    echo wp_json_encode( isset( $s['geolocation'] ) ? $s['geolocation'] : array() );
  `);
}

/**
 * Write the geolocation block back verbatim.
 *
 * Restoration goes through WP-CLI rather than the REST route the test uses to
 * SET the value: the restore has to work even when the browser context that
 * held the admin nonce is the thing that just failed.
 */
function writeGeolocationSettings(json: string): void {
  const encoded = Buffer.from(json, 'utf8').toString('base64');
  wpEval(`
    $s = get_option( 'faz_settings', array() );
    if ( ! is_array( $s ) ) { $s = array(); }
    $geo = json_decode( base64_decode( '${encoded}' ), true );
    $s['geolocation'] = is_array( $geo ) ? $geo : array();
    update_option( 'faz_settings', $s );
  `);
}

test.describe('Geo-Targeting toggle drives the page-cache headers', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });

  test.beforeAll(() => {
    // Re-sync the fixture from the repo before relying on defer mode: the
    // preflight only rsyncs fixtures it has to ACTIVATE, so an already-active
    // audit-lab can be an older copy that has no defer branch — in which case
    // the filter would still be forced and step 1 would fail for a reason that
    // has nothing to do with the plugin.
    ensureFixturePlugin('faz-e2e-audit-lab');
  });

  test('GEO-CACHE-01: Geo-Targeting off leaves the page cacheable; on emits the cache-bust', async ({
    browser,
    page,
    loginAsAdmin,
  }) => {
    // One test does an admin login, two REST saves and two anonymous front-end
    // round trips. The suite default (45s) is enough when the machine is idle
    // and not when it is not, and a timeout here reads as a product failure
    // rather than as the scheduling accident it is.
    test.setTimeout(120_000);

    const originalGeolocation = readGeolocationSettings();
    let deferWasSet = false;

    try {
      // Defer mode: audit-lab stops forcing faz_geo_ruleset_runtime, so
      // Geo_Runtime::is_enabled() answers from the admin setting.
      setOption(DEFER_OPTION, '1');
      deferWasSet = true;

      await loginAsAdmin(page);
      await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(
        () => typeof (window as unknown as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce === 'string',
        undefined,
        { timeout: 20_000 },
      );
      const nonce = await page.evaluate(
        () => (window as unknown as { fazConfig?: { api?: { nonce?: string } } }).fazConfig?.api?.nonce ?? '',
      );
      expect(nonce, 'admin REST nonce').not.toBe('');

      // Save through the same nonce-protected REST route the Settings screen
      // uses, so the toggle is exercised the way a publisher flips it.
      const saveGeoTargeting = async (enabled: boolean): Promise<void> => {
        const getRes = await page.request.get('/wp-json/faz/v1/settings/', { headers: { 'X-WP-Nonce': nonce } });
        expect(getRes.ok(), `settings GET ${getRes.status()}`).toBeTruthy();
        const current = (await getRes.json()) as Record<string, unknown>;
        const geolocation = { ...((current.geolocation as Record<string, unknown>) ?? {}) };
        geolocation.geo_targeting = enabled;
        // See the file header: pinning show_banner removes the OTHER branch of
        // is_country_dependent_output() that would answer for the runtime flag.
        geolocation.default_behavior = 'show_banner';
        const postRes = await page.request.post('/wp-json/faz/v1/settings/', {
          headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
          data: { ...current, geolocation },
        });
        expect(postRes.ok(), `settings POST ${postRes.status()}: ${(await postRes.text()).slice(0, 200)}`).toBeTruthy();

        const stored = JSON.parse(readGeolocationSettings()) as Record<string, unknown>;
        expect(Boolean(stored.geo_targeting), `geo_targeting persisted as ${enabled}`).toBe(enabled);
      };

      // ── 1. Geo-Targeting OFF — the page must stay cacheable ──────────────
      await saveGeoTargeting(false);
      const offHeaders = await anonHomeHeaders(browser, 'geo-off');
      // Use the FAZ-specific header as the negative proof. Generic no-store /
      // Pragma headers are not attributable here: another active test plugin
      // can start PHP's session, whose cache limiter emits those same values.
      expect(
        offHeaders['x-litespeed-cache-control'] ?? '',
        'X-LiteSpeed-Cache-Control should be absent while Geo-Targeting is off',
      ).not.toContain('no-cache');

      // ── 2. Geo-Targeting ON — the cache-bust must come back ──────────────
      await saveGeoTargeting(true);
      const onHeaders = await anonHomeHeaders(browser, 'geo-on');
      const onCacheControl = onHeaders['cache-control'] ?? '';
      expect(
        onHeaders['x-litespeed-cache-control'] ?? '',
        'FAZ must restore the LiteSpeed cache-bypass signal while Geo-Targeting is on',
      ).toContain('no-cache');
      expect(
        onCacheControl,
        `Geo-Targeting is on, so the response varies by visitor country and must not be cached — got Cache-Control: "${onCacheControl}"`,
      ).toContain('no-store');
      expect(onCacheControl, `Cache-Control: "${onCacheControl}"`).toContain('no-cache');
      expect(onHeaders.pragma ?? '', `Pragma: "${onHeaders.pragma ?? ''}"`).toContain('no-cache');
    } finally {
      // Order matters only in that both must happen: restore the settings even
      // if deleting the option throws, and vice versa.
      try {
        writeGeolocationSettings(originalGeolocation);
      } finally {
        if (deferWasSet) {
          deleteOption(DEFER_OPTION);
        }
      }
    }
  });
});
