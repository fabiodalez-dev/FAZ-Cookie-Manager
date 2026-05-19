/**
 * PR #104 — REST routes under /faz/v1/* must NEVER be served by LSCache /
 * private cache. Reported on prod (fabiodalez.it, 1.14.1): a POST /banners
 * + immediate GET /banners returned the pre-POST list because LiteSpeed
 * had stored the previous GET response under "private cache" mode. Cure:
 * a global rest_pre_dispatch hook in faz-cookie-manager.php fires the
 * `litespeed_control_set_nocache` action and emits both the LSCache and
 * standard Cache-Control opt-out headers before the route runs.
 */
import { test, expect } from '../fixtures/wp-fixture';

test.describe('PR104 — REST routes never cache (LSCache opt-out)', () => {
  test('GET /faz/v1/banners emits no-store + X-LiteSpeed-Cache-Control: no-cache', async ({
    page,
    wpBaseURL,
    loginAsAdmin,
  }) => {
    await loginAsAdmin(page);
    const resp = await page.context().request.get(`${wpBaseURL}/wp-json/faz/v1/banners`);
    const cc = (resp.headers()['cache-control'] || '').toLowerCase();
    const ls = (resp.headers()['x-litespeed-cache-control'] || '').toLowerCase();
    expect(cc, 'Cache-Control should forbid storage').toContain('no-store');
    expect(cc, 'Cache-Control max-age=0').toContain('max-age=0');
    expect(ls, 'X-LiteSpeed-Cache-Control must opt out').toContain('no-cache');
  });
});
