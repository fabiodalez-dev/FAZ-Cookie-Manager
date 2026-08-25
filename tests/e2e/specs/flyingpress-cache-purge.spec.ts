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
 *   1  [support]       FlyingPress caches an anonymous page without FAZ's
 *                      former no-store / LiteSpeed-bypass response headers.
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
 *
 * HOW TO RUN THIS FILE
 * --------------------
 *     npm run test:e2e:flyingpress
 *
 * These tests need the WordPress install to themselves (they activate
 * FlyingPress globally, and its page cache would then serve stale HTML to
 * whatever spec runs beside them), so they gate on
 * `testInfo.config.workers === 1` and SKIP otherwise. Both `npm run test:e2e`
 * and the file-only command above pass `--workers=1`, so the documented full
 * gate executes all 11 FlyingPress tests under CI=1 instead of silently
 * skipping them. The dedicated command remains the faster targeted check.
 *
 * `test.describe.configure({ mode: 'serial' })` does NOT help here: it orders
 * the tests inside this file, it does not stop another FILE running next to it.
 * Playwright has no per-project/per-file worker cap either, so a second
 * invocation is the mechanism.
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
let fpExclusiveRun = false;
let fpConfiguredWorkers = 0;
let weActivatedFlyingPress = false;
let probeWasActive = false;
let auditLabWasActive = false;
let lockHeld = false;
let settingsSnapshot = '';
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

/**
 * Hit a URL until FlyingPress reports a cache HIT (first hit generates, second
 * serves).
 *
 * FlyingPress writes the cache file on shutdown, after the response is already
 * on the wire, so the HIT is never immediate. The old budget here was 8 tries
 * 300ms apart — about 2.1s, which held when this spec ran alone and did not
 * when the whole suite was loading the same PHP-FPM pool: test 06 alone takes
 * ~4.6s idle. It failed the full run and passed in isolation, which is the
 * signature of a budget tuned on an idle box.
 *
 * The error also has to say what it saw. "never reported a HIT" is the same
 * message whether the header read MISS (caching works, we were impatient),
 * BYPASS (something vetoed the cache) or nothing at all (FlyingPress is not
 * serving this request) — three different bugs behind one sentence.
 */
async function primeCache(request: APIRequestContext, url: string): Promise<void> {
  let seen = '';
  for (let i = 0; i < 24; i += 1) {
    seen = await cacheState(request, url);
    if (seen === 'HIT') {
      return;
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(
    `FlyingPress never reported a cache HIT for ${url} within 12s; last x-flying-press-cache was "${seen || '(header absent)'}"`
  );
}

test.beforeAll(async ({}, testInfo) => {
  testInfo.setTimeout(41 * 60_000);
  await acquireSharedWordPressLock();
  lockHeld = true;
  probeWasActive = isPluginActive('faz-e2e-fp-probe');
  auditLabWasActive = isPluginActive('faz-e2e-audit-lab');
  settingsSnapshot = wpEval(`echo base64_encode( serialize( get_option( 'faz_settings', array() ) ) );`).trim();

  // This suite must exercise the production UI path, not the historical
  // faz_geo_ruleset_runtime test escape hatch. The baseline audit-lab fixture
  // pins that filter true for unrelated geo specs, so remove only that fixture
  // while this exclusive suite runs, then set exactly the reporter's UI state:
  // Geo-Targeting OFF + Cache Compatibility Mode ON. A real FlyingPress HIT
  // below now proves the UI state alone removes the cache veto.
  fpConfiguredWorkers = testInfo.config.workers;
  fpExclusiveRun = fpConfiguredWorkers === 1;
  if (fpExclusiveRun && auditLabWasActive) {
    wp(['plugin', 'deactivate', 'faz-e2e-audit-lab']);
  }
  if (fpExclusiveRun) {
    wpEval(`
      $settings = get_option( 'faz_settings', array() );
      if ( ! is_array( $settings ) ) { $settings = array(); }
      if ( ! isset( $settings['geolocation'] ) || ! is_array( $settings['geolocation'] ) ) { $settings['geolocation'] = array(); }
      if ( ! isset( $settings['banner_control'] ) || ! is_array( $settings['banner_control'] ) ) { $settings['banner_control'] = array(); }
      $settings['geolocation']['geo_targeting'] = false;
      $settings['banner_control']['cache_compatibility'] = true;
      update_option( 'faz_settings', $settings );
    `);
  }

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
  const cleanupErrors: Error[] = [];
  const cleanup = (label: string, action: () => void): void => {
    try {
      action();
    } catch (error) {
      const detail = error instanceof Error ? error.message : String(error);
      cleanupErrors.push(new Error(`${label}: ${detail}`));
    }
  };
  try {
    if (fpExclusiveRun && settingsSnapshot) {
      const restoreSettings = `
        $settings = unserialize( base64_decode( '${settingsSnapshot}' ) );
        update_option( 'faz_settings', is_array( $settings ) ? $settings : array() );
      `;
      cleanup('restore FAZ settings', () => {
        try {
          wpEval(restoreSettings);
        } catch (primaryError) {
          try {
            wp(['eval', restoreSettings]);
          } catch (fallbackError) {
            throw new Error(`primary=${String(primaryError)}; fallback=${String(fallbackError)}`);
          }
        }
      });
    }
    // Restore only the plugin state this spec changed. A developer who started
    // with FlyingPress or the probe active must get the same state back.
    if (flyingPressActive) {
      cleanup('purge FlyingPress cache', () => {
        wpEval(`if ( class_exists( '\\\\FlyingPress\\\\Purge' ) ) { \\FlyingPress\\Purge::purge_everything(); }`);
      });
    }
    if (!probeWasActive && isPluginActive('faz-e2e-fp-probe')) {
      cleanup('deactivate the FlyingPress probe', () => {
        wp(['plugin', 'deactivate', 'faz-e2e-fp-probe']);
      });
    }
    if (auditLabWasActive && !isPluginActive('faz-e2e-audit-lab')) {
      cleanup('restore audit-lab activation state', () => {
        wp(['plugin', 'activate', 'faz-e2e-audit-lab']);
      });
    }
    if (weActivatedFlyingPress && isPluginActive('flying-press')) {
      cleanup('restore FlyingPress activation state', () => {
        wp(['plugin', 'deactivate', 'flying-press']);
      });
    }
  } finally {
    if (lockHeld) {
      releaseSharedWordPressLock();
      lockHeld = false;
    }
  }
  if (cleanupErrors.length > 0) {
    throw new AggregateError(cleanupErrors, 'FlyingPress E2E cleanup failed');
  }
});

test.beforeEach(() => {
  test.skip(!flyingPressActive, 'FlyingPress is not installed on this environment');
  // Skipped rather than failed when the run is parallel: the jurisdiction
  // runtime cannot be switched off without affecting the file running beside
  // this one, so a HIT is unreachable. A red here would be reporting the
  // harness's own constraint as a product fault.
  //
  // The message names the exact command on purpose. A skip that only says
  // "needs an exclusive run" reads as a machine limitation and gets ignored;
  // this one is an instruction, and following it works in every environment
  // including CI=1 (a CLI --workers=1 overrides the config's isCI ? 2 : 1).
  test.skip(
    !fpExclusiveRun,
    `this file was run with workers=${String(fpConfiguredWorkers)} — the FlyingPress suite needs the ` +
      'install to itself. Run `npm run test:e2e:flyingpress` (adds --workers=1) to execute it.'
  );
});

test.describe('FlyingPress cache purge (#125 / PR #186)', () => {
  test('01 FlyingPress caches an anonymous page (HIT + .html.gz on disk)', async ({ request }) => {
    await primeCache(request, testPageUrl);
    const hit = await request.get(testPageUrl, { headers: UA });
    expect((hit.headers()['x-flying-press-cache'] ?? '').toUpperCase()).toBe('HIT');
    // WordPress core may retain `no-cache, must-revalidate` on this local stack
    // even on a FlyingPress HIT. FAZ's harmful contribution was `no-store` plus
    // the explicit LiteSpeed bypass header; both must be absent.
    expect(hit.headers()['cache-control'] ?? '').not.toMatch(/no-store/i);
    expect(hit.headers()['x-litespeed-cache-control'] ?? '').not.toMatch(/no-cache/i);
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

    // The reporter's UI state alone disables jurisdiction routing; no custom
    // faz_geo_ruleset_runtime snippet is installed in this suite.
    const runtimeFromUi = wpEval(`echo \\FazCookie\\Frontend\\Includes\\Geo_Runtime::is_enabled() ? '1' : '0';`).trim();
    expect(runtimeFromUi).toBe('0');

    // Invariant output — normal caching is preserved.
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
