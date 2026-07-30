/**
 * E2E — the cached banner survives a change of site address (issue #195).
 *
 * The rendered banner is cached in `faz_banner_template` with absolute URLs
 * to this plugin's own assets. Those carry whatever origin the site had when
 * the template was built, so moving the site — a localhost build restored on
 * a live server, staging promoted to production — leaves the cache asking the
 * previous host for the revisit icon. Browsers surface that as a
 * private-network access prompt when the stale origin is localhost.
 *
 * Two independent defences, tested here separately because each covers a case
 * the other cannot:
 *
 *  1. `update_option_siteurl` / `_home` drop the cache. Covers a deliberate
 *     move (Settings → General, WP-CLI, multisite domain edit).
 *  2. A render-time repair rewrites a stale asset origin and persists the fix.
 *     Covers a restored database, which rewrites the siteurl row directly and
 *     so never fires the hook — the exact path in the issue report.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const PLUGIN_PATH = '/wp-content/plugins/faz-cookie-manager/';
const STALE_ORIGIN = 'https://localhost';

/** Force a cached template to exist, then report how it is keyed. */
function primeTemplateCache(): void {
  wpEval(`delete_option('faz_banner_template');`);
}

test.describe.serial('Site address change (#195)', () => {
  test.afterAll(() => {
    // Leave a clean, freshly-built cache for whatever runs next.
    wpEval(`delete_option('faz_banner_template');`);
  });

  test('changing siteurl drops the cached banner template', async ({ page }) => {
    primeTemplateCache();
    await page.goto(`${WP_BASE}/?_cb=${Date.now()}`, { waitUntil: 'domcontentloaded' });

    const cached = wpEval(`echo is_array( get_option('faz_banner_template') ) ? 'yes' : 'no';`).trim();
    expect(cached, 'a visit builds the template cache').toBe('yes');

    const afterChange = wpEval(`
      $orig = get_option('siteurl');
      update_option('siteurl', 'http://address-change-probe.test');
      $state = ( false === get_option('faz_banner_template') ) ? 'cleared' : 'kept';
      update_option('siteurl', $orig);
      echo $state;
    `).trim();
    expect(afterChange, 'the address change invalidates the cache').toBe('cleared');

    const restored = wpEval(`echo get_option('siteurl');`).trim();
    expect(restored, 'the probe restored the real siteurl').toContain('9998');
  });

  test('a template cached under a previous address is repaired at render time', async ({ page }) => {
    primeTemplateCache();
    await page.goto(`${WP_BASE}/?_cb=${Date.now()}`, { waitUntil: 'domcontentloaded' });

    // Simulate what a restored backup leaves behind: siteurl already points at
    // the new address (the migration tool rewrote it), while the cached HTML
    // still carries the origin of the machine the template was built on.
    const poisoned = wpEval(`
      $key = 'faz_banner_template';
      $t   = get_option( $key );
      $current = rtrim( home_url(), '/' ) . '${PLUGIN_PATH}';
      $n = 0;
      foreach ( (array) $t as $lang => $tpl ) {
        if ( empty( $tpl['html'] ) ) { continue; }
        $swapped = str_replace( $current, '${STALE_ORIGIN}${PLUGIN_PATH}', $tpl['html'] );
        if ( $swapped !== $tpl['html'] ) { $t[ $lang ]['html'] = $swapped; $n++; }
      }
      update_option( $key, $t );
      echo $n;
    `).trim();
    expect(Number(poisoned), 'the fixture actually planted a stale origin').toBeGreaterThan(0);

    // A single front-end request must serve correct URLs…
    const res = await page.goto(`${WP_BASE}/?_cb=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    const html = (await res?.text()) ?? '';
    expect(html, 'no asset is requested from the previous origin').not.toContain(STALE_ORIGIN);
    expect(html, 'the revisit icon is served from this site').toContain(`${PLUGIN_PATH}frontend/images/revisit.svg`);

    // …and must persist the repair, so the cost is paid once.
    const remaining = wpEval(`
      $n = 0;
      foreach ( (array) get_option('faz_banner_template') as $tpl ) {
        $n += substr_count( isset( $tpl['html'] ) ? $tpl['html'] : '', '${STALE_ORIGIN}' );
      }
      echo $n;
    `).trim();
    expect(Number(remaining), 'the stored template no longer holds the stale origin').toBe(0);
  });
});
