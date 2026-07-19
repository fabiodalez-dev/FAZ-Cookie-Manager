/**
 * FlyingPress cache-purge integration — issue #125 + PR #186 behavioural contract.
 *
 * These tests run against a REAL FlyingPress install (the commercial plugin,
 * licence-activated on the dev box). The whole describe auto-skips when
 * FlyingPress is not active, so the suite stays green on CI / other machines
 * where FlyingPress is absent (same pattern as gcm-tcf.spec.ts).
 *
 * What is pinned here — both the behaviour the user reported in #125 and the
 * behaviour the PR #186 review (grounded in the real FlyingPress 5.5.0 source)
 * settled on:
 *
 *   1  [precondition]  FlyingPress actually caches an anonymous page.
 *   2  [#125]          a FAZ banner save invalidates the cached page (HIT→MISS).
 *   3  [#125]          stale markup is no longer served after a save (content proof).
 *   4  [F2]            the purge is HTML-only — FlyingPress's minified CSS/JS
 *                      assets survive a save (purge_pages, not purge_everything).
 *   5  [F1]            a save does NOT trigger FlyingPress's full-site preload
 *                      crawl (the preload queue stays empty).
 *   6  [hooks]         a cookie CRUD save also purges.
 *   7  [hooks]         a settings save also purges.
 *   8  [bridge]        a front-end request injects the consent keywords into
 *                      FlyingPress's per-request delay-exclude config (5.x reflection).
 *   9  [F3]            the runtime injection never leaks into FlyingPress's
 *                      persisted config (the stored option stays empty).
 *   10 [is_cacheable]  country-dependent output vetoes FlyingPress page caching.
 *   11 [upgrade]       the always-run Activator purge clears FlyingPress even
 *                      when the deferred admin cache module is not loaded.
 *
 * The `X-Faz-Fp-*` headers used by tests 8/9 are emitted by the test-only
 * fixture plugin tests/e2e/fixtures/plugins/faz-e2e-fp-probe, which exposes
 * FlyingPress's per-request in-memory config (invisible to a separate wp-cli
 * process) as response headers.
 */
import { test, expect } from '../fixtures/wp-fixture';
import { type APIRequestContext } from '@playwright/test';
import { wp, wpEval, upsertPage, ensureFixturePlugin, isPluginActive } from '../utils/wp-env';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { acquireSharedWordPressLock, releaseSharedWordPressLock } from '../utils/shared-wordpress-lock';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const UA = { 'User-Agent': 'Mozilla/5.0 (FAZ-E2E FlyingPress)' };

const TEST_PAGE_SLUG = 'faz-fp-cache-test';
const MARKER_ALPHA = 'FAZ-FP-MARKER-ALPHA';
const MARKER_BETA = 'FAZ-FP-MARKER-BETA';

let flyingPressActive = false;
let weActivatedFlyingPress = false;
let probeWasActive = false;
let lockHeld = false;
let testPageId = 0;
let testPageUrl = '';

test.describe.configure({ mode: 'serial' });

/** Is the (commercial) FlyingPress plugin present on disk? False on CI / clean machines. */
function fpInstalled(): boolean {
  try {
    // `wp plugin is-installed` exits 0 when installed, non-zero otherwise
    // (the wp() helper throws on non-zero).
    wp(['plugin', 'is-installed', 'flying-press']);
    return true;
  } catch {
    return false;
  }
}

/** class_exists() probe — true only once FlyingPress is loaded (i.e. activated). */
function fpActive(): boolean {
  try {
    return (
      wpEval(
        `echo ( class_exists('\\\\FlyingPress\\\\Config') && class_exists('\\\\FlyingPress\\\\Purge') ) ? '1' : '0';`,
      ).trim() === '1'
    );
  } catch {
    return false;
  }
}

/** Count *.html.gz files anywhere under the FlyingPress cache dir (the cached pages). */
function htmlGzCount(): number {
  return Number(
    wpEval(`
      $dir = defined('FLYING_PRESS_CACHE_DIR') ? FLYING_PRESS_CACHE_DIR : WP_CONTENT_DIR . '/cache/flying-press/';
      $n = 0;
      if ( is_dir( $dir ) ) {
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $f ) { if ( substr( $f->getFilename(), -8 ) === '.html.gz' ) { $n++; } }
      }
      echo $n;
    `).trim(),
  );
}

/** Count generated minified CSS assets at the cache-dir root (FlyingPress's optimised assets). */
function assetCssCount(): number {
  return Number(
    wpEval(`
      $dir = defined('FLYING_PRESS_CACHE_DIR') ? FLYING_PRESS_CACHE_DIR : WP_CONTENT_DIR . '/cache/flying-press/';
      echo is_dir( $dir ) ? count( glob( $dir . '*.css' ) ) : 0;
    `).trim(),
  );
}

/** Row count of FlyingPress's preload queue table (populated only by a preload crawl). */
function queueRows(): number {
  return Number(
    wpEval(`
      global $wpdb;
      $t = $wpdb->prefix . 'flying_press_queue';
      $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
      echo $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) : 0;
    `).trim(),
  );
}

function clearQueue(): void {
  wpEval(`
    global $wpdb;
    $t = $wpdb->prefix . 'flying_press_queue';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
      $wpdb->query( "DELETE FROM {$t}" );
    }
  `);
}

/** Fire a FAZ CRUD/purge hook after the same REST module bootstrap used by saves. */
function fireHook(hook: string): void {
  wpEval(`do_action('rest_api_init'); do_action('${hook}');`);
}

async function cacheState(request: APIRequestContext, url: string): Promise<string> {
  const resp = await request.get(url, { headers: UA });
  return (resp.headers()['x-flying-press-cache'] ?? '').toUpperCase();
}

/** Hit a URL until FlyingPress reports a cache HIT (first hit generates, second serves). */
async function primeCache(request: APIRequestContext, url: string): Promise<void> {
  for (let i = 0; i < 8; i += 1) {
    if ((await cacheState(request, url)) === 'HIT') {
      return;
    }
    await new Promise((r) => setTimeout(r, 300));
  }
  throw new Error(`FlyingPress never reported a cache HIT for ${url}`);
}

test.beforeAll(async ({}, testInfo) => {
  testInfo.setTimeout(41 * 60_000);
  await acquireSharedWordPressLock();
  lockHeld = true;
  probeWasActive = isPluginActive('faz-e2e-fp-probe');

  // Self-provision FlyingPress for the duration of THIS spec file only. When
  // the plugin is installed (dev box) the tests run as part of the suite;
  // when it's absent (CI / other machines) they auto-skip. afterAll tears it
  // back down so FlyingPress's page cache never lingers for other specs —
  // this file activating it globally is the reason it must clean up after
  // itself (the suite runs fullyParallel:false, 1 worker locally, and CI has
  // no FlyingPress to activate, so no concurrent spec sees it mid-flight).
  if (!fpInstalled()) {
    flyingPressActive = false;
    return;
  }
  if (!fpActive()) {
    wp(['plugin', 'activate', 'flying-press']);
    weActivatedFlyingPress = true;
  }
  flyingPressActive = fpActive();
  if (!flyingPressActive) {
    return;
  }
  // Deterministic default banner (immune to a prior spec leaving classic/CCPA state).
  resetDefaultBannerState();
  // The probe plugin exposes FlyingPress's per-request runtime config as headers.
  ensureFixturePlugin('faz-e2e-fp-probe');
  // A dedicated, static page keeps the cache-state assertions free of
  // WooCommerce cart-fragment noise on the homepage.
  testPageId = upsertPage(TEST_PAGE_SLUG, 'FAZ FP Cache Test', MARKER_ALPHA);
  testPageUrl = wpEval(`echo get_permalink( ${testPageId} );`).trim();
});

test.afterAll(() => {
  try {
    // Restore only the plugin state this spec changed. A developer who started
    // with FlyingPress or the probe active must get the same state back.
    if (flyingPressActive) {
      try {
        wpEval(`if ( class_exists( '\\\\FlyingPress\\\\Purge' ) ) { \\FlyingPress\\Purge::purge_everything(); }`);
      } catch {
        /* best-effort */
      }
    }
    if (!probeWasActive && isPluginActive('faz-e2e-fp-probe')) {
      try {
        wp(['plugin', 'deactivate', 'faz-e2e-fp-probe']);
      } catch {
        /* best-effort */
      }
    }
    if (weActivatedFlyingPress && isPluginActive('flying-press')) {
      try {
        wp(['plugin', 'deactivate', 'flying-press']);
      } catch {
        /* best-effort */
      }
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
});

test.beforeEach(() => {
  test.skip(!flyingPressActive, 'FlyingPress is not installed on this environment');
});

test.describe('FlyingPress cache purge (#125 / PR #186)', () => {
  test('01 FlyingPress caches an anonymous page (HIT + .html.gz on disk)', async ({ request }) => {
    await primeCache(request, testPageUrl);
    expect(await cacheState(request, testPageUrl)).toBe('HIT');
    expect(htmlGzCount()).toBeGreaterThan(0);
  });

  test('02 [#125] a banner save invalidates the cached page (HIT → MISS)', async ({ request }) => {
    await primeCache(request, testPageUrl);
    expect(await cacheState(request, testPageUrl)).toBe('HIT');

    fireHook('faz_after_update_banner');

    // purge_pages() deletes the .html.gz synchronously, so the very next
    // anonymous request must regenerate (MISS) instead of serving the stale page.
    expect(await cacheState(request, testPageUrl)).toBe('MISS');
  });

  test('03 [#125] stale markup is no longer served after a save', async ({ request }) => {
    await primeCache(request, testPageUrl);
    const cached = await (await request.get(testPageUrl, { headers: UA })).text();
    expect(cached).toContain(MARKER_ALPHA);

    // Mutate the content DIRECTLY in the DB so FlyingPress's own AutoPurge
    // (which hooks wp_update_post) does NOT fire — this isolates FAZ's purge
    // as the only thing that can make the change visible.
    wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->posts, array( 'post_content' => '${MARKER_BETA}' ), array( 'ID' => ${testPageId} ) );
      clean_post_cache( ${testPageId} );
    `);

    // The cached page still serves the OLD marker — proving the cache is stale.
    const stillStale = await (await request.get(testPageUrl, { headers: UA })).text();
    expect(stillStale).toContain(MARKER_ALPHA);
    expect(stillStale).not.toContain(MARKER_BETA);

    // A FAZ save purges FlyingPress → the fresh markup is finally served.
    fireHook('faz_after_update_banner');
    const fresh = await (await request.get(testPageUrl, { headers: UA })).text();
    expect(fresh).toContain(MARKER_BETA);

    // Restore the page content for the rest of the suite.
    wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->posts, array( 'post_content' => '${MARKER_ALPHA}' ), array( 'ID' => ${testPageId} ) );
      clean_post_cache( ${testPageId} );
    `);
    fireHook('faz_after_update_banner');
  });

  test('04 [F2] the purge is HTML-only — FlyingPress minified assets survive', async ({ request }) => {
    // Prime the homepage too: it pulls the theme + WooCommerce stylesheets, so
    // FlyingPress generates root-level minified CSS assets we can watch survive.
    await primeCache(request, WP_BASE + '/');
    await primeCache(request, testPageUrl);

    const assetsBefore = assetCssCount();
    expect(assetsBefore).toBeGreaterThan(0);
    expect(htmlGzCount()).toBeGreaterThan(0);

    fireHook('faz_after_update_banner');

    // HTML pages are gone (purge_pages), but the generated assets are untouched.
    // purge_everything() would have wiped these too — this is the regression guard.
    expect(htmlGzCount()).toBe(0);
    expect(assetCssCount()).toBeGreaterThanOrEqual(assetsBefore);
  });

  test('05 [F1] a save does NOT trigger a full-site preload crawl', async ({ request }) => {
    clearQueue();
    await primeCache(request, testPageUrl);
    expect(queueRows()).toBe(0);

    fireHook('faz_after_update_banner');

    // A real Preload::preload_cache() would enqueue home + every post/term/author
    // URL (200+ rows on this install). The adapter must purge only — queue stays empty.
    expect(queueRows()).toBe(0);
  });

  test('06 a cookie CRUD save also purges (HIT → MISS)', async ({ request }) => {
    await primeCache(request, testPageUrl);
    expect(await cacheState(request, testPageUrl)).toBe('HIT');

    fireHook('faz_after_create_cookie');

    expect(await cacheState(request, testPageUrl)).toBe('MISS');
  });

  test('07 a settings save also purges (HIT → MISS)', async ({ request }) => {
    await primeCache(request, testPageUrl);
    expect(await cacheState(request, testPageUrl)).toBe('HIT');

    fireHook('faz_after_update_settings');

    expect(await cacheState(request, testPageUrl)).toBe('MISS');
  });

  test('08 [bridge] a front-end request injects the consent keywords into the runtime delay-exclude config', async ({
    request,
  }) => {
    // The ?fazprobe query string dodges FlyingPress's page cache, so the probe
    // observes a freshly-processed request where the FAZ reflection bridge ran.
    const resp = await request.get(`${testPageUrl}?fazprobe=1`, { headers: UA });
    const runtime = JSON.parse(resp.headers()['x-faz-fp-runtime-excludes'] ?? 'null');
    expect(Array.isArray(runtime)).toBe(true);
    expect(runtime).toContain('faz-cookie-manager');
    expect(runtime).toContain('faz-fw');
  });

  test('09 [F3] the runtime injection never leaks into FlyingPress\'s persisted config', async ({ request }) => {
    // Several front-end requests, each running the in-memory injection.
    for (let i = 0; i < 3; i += 1) {
      await request.get(`${testPageUrl}?fazprobe=${i}`, { headers: UA });
    }
    // The probe's stored-config header must stay empty…
    const resp = await request.get(`${testPageUrl}?fazprobe=final`, { headers: UA });
    const stored = JSON.parse(resp.headers()['x-faz-fp-stored-excludes'] ?? 'null');
    expect(stored).toEqual([]);

    // …and so must the option read straight from the DB.
    const dbStored = wpEval(`
      $opt = get_option( 'FLYING_PRESS_CONFIG' );
      $ex = ( is_array( $opt ) && isset( $opt['js_delay_excludes'] ) ) ? $opt['js_delay_excludes'] : array();
      echo wp_json_encode( $ex );
    `).trim();
    expect(JSON.parse(dbStored)).toEqual([]);
  });

  test('10 [is_cacheable] country-dependent output vetoes FlyingPress page caching', async () => {
    // Filter is registered by FAZ.
    const registered = wpEval(`echo has_filter( 'flying_press_is_cacheable' ) !== false ? '1' : '0';`).trim();
    expect(registered).toBe('1');

    // Default (invariant output) — normal caching is preserved.
    const whenInvariant = wpEval(`echo apply_filters( 'flying_press_is_cacheable', true ) ? '1' : '0';`).trim();
    expect(whenInvariant).toBe('1');

    // Country-dependent output — FlyingPress caching is vetoed. Run in a
    // separate wp-cli process so is_country_dependent_output()'s per-request
    // memoization doesn't carry the invariant result over.
    const whenCountryDependent = wpEval(`
      add_filter( 'faz_country_dependent_banner_output', '__return_true' );
      echo apply_filters( 'flying_press_is_cacheable', true ) ? '1' : '0';
    `).trim();
    expect(whenCountryDependent).toBe('0');
  });

  test('11 [upgrade] the Activator purge matrix invalidates FlyingPress HTML', async ({ request }) => {
    await primeCache(request, testPageUrl);
    expect(await cacheState(request, testPageUrl)).toBe('HIT');

    // Version upgrades can run on a frontend/Dashboard request before the
    // deferred admin cache-service module registers faz_after_activate.
    wpEval(`\\FazCookie\\Includes\\Activator::purge_page_caches();`);

    expect(await cacheState(request, testPageUrl)).toBe('MISS');
  });
});
