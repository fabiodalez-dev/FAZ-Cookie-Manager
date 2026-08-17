/**
 * Release verification — an actual 330+-page browser crawl.
 *
 * This is intentionally separate from the fast session-unit coverage. It runs
 * the shipped FAZ.scanEngine, lets it discover 330 real WordPress permalinks,
 * dispatches every one through its real two-iframe pool, observes a cookie on
 * the tail page, and completes the real scans/import request.
 *
 * The historical production failure took about 92 minutes. Keeping CI asleep
 * for that wall-clock duration would add cost without exercising another code
 * path, so this test compresses only the idle clock: after discovery it shortens
 * the live transient to 12 seconds and accelerates the engine's five-minute
 * heartbeat to five seconds. The crawl itself is not mocked — all 330 iframe
 * jobs and their settle checkpoints run. Import therefore succeeds only if the
 * heartbeat emitted by the real engine keeps the real server session alive for
 * the entire crawl.
 *
 * Two response regimes run in the same crawl, deliberately:
 *  - most pages are fulfilled from the route handler, modelling a fully
 *    page-cached site where a scanned page never boots PHP;
 *  - every REAL_PAGE_EVERY-th page is passed through to the real server, so
 *    the server-side capture path (the shutdown observer reading
 *    headers_list() on a faz_scanning request) is exercised WHILE the long
 *    crawl is in flight. Fulfilling all 330 left that path at zero
 *    invocations for the whole test, which made the page-cached regime the
 *    only one covered.
 */

import { expect, test } from '../fixtures/wp-fixture';
import { openCookiesPage } from '../utils/faz-api';
import { setLabToken, wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const PAGE_COUNT = 330;
const SEEDED_PAGE_COUNT = 340;
const TAIL_COOKIE = 'faz_long_crawl_tail';
// Every Nth scanned document is served by the REAL server instead of being
// fulfilled from the route handler. Fulfilling all 330 models a fully
// page-cached site — a real and common regime — but in it no scanned page
// ever boots PHP, so the server-side capture path (the shutdown observer that
// reads headers_list() on a faz_scanning request) runs zero times for the
// whole crawl. Sampling restores that path under long-crawl conditions at a
// fraction of the wall clock: the heartbeat still needs all 330 iterations to
// be proven, the observer only needs to be shown working while they happen.
const REAL_PAGE_EVERY = 10;
// Emitted by faz-e2e-scan-lab's send_header_cookie() on the sampled pages.
// HttpOnly and set by PHP, so it cannot be observed client-side: reaching the
// imported set proves the server-side observation path ran.
//
// The name embeds the lab token, which other specs rewrite for their own runs
// and do not always restore. Pinning it here (and restoring the previous value
// in cleanup) is what makes the expected name knowable: reading whatever the
// option happened to hold would make this assertion depend on execution order.
const LAB_TOKEN = 'longcrawl';
const SERVER_OBSERVED_COOKIE = `_faz_lab_http_${LAB_TOKEN}`;

type ScanResult = {
  pagesScanned: number;
  cookies: Array<{ name?: string }>;
  metrics: { scannedUrls?: string[]; pagesScanned?: number };
  importResult: {
    pages_scanned?: number;
    cookie_names?: string[];
    enrichment_pending?: boolean;
    enrichment_urls?: number;
  };
};

function seedLongCrawlPagesAndSnapshot(): string {
  return wpEval(`
    global $wpdb;
    $snapshot = array(
      'history_exists' => false !== get_option( 'faz_scan_history', false ),
      'history'        => get_option( 'faz_scan_history', array() ),
      'counter_exists' => false !== get_option( 'faz_scan_counter', false ),
      'counter'        => get_option( 'faz_scan_counter', 0 ),
      'details_exists' => false !== get_option( 'faz_scan_details', false ),
      'details'        => get_option( 'faz_scan_details', array() ),
    );
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_name LIKE 'faz-long-crawl-%'" );
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_name LIKE 'faz-lab-headers-%'" );
    $now = current_time( 'mysql' );
    for ( $i = 0; $i < ${SEEDED_PAGE_COUNT}; ++$i ) {
      // Sampled pages carry the scan-lab 'headers' scenario slug so that the
      // ones we let through to the real server emit a genuine PHP Set-Cookie.
      // The -%03d suffix is stripped by the fixture's scenario matcher.
      $slug = ( $i > 0 && 0 === $i % ${REAL_PAGE_EVERY} )
        ? sprintf( 'faz-lab-headers-%03d', $i )
        : sprintf( 'faz-long-crawl-%03d', $i );
      $wpdb->insert(
        $wpdb->posts,
        array(
          'post_author'           => 1,
          'post_date'             => $now,
          'post_date_gmt'         => get_gmt_from_date( $now ),
          'post_content'          => '<p>FAZ long crawl fixture</p>',
          'post_title'            => 'FAZ long crawl ' . $i,
          'post_excerpt'          => '',
          'post_status'           => 'publish',
          'comment_status'        => 'closed',
          'ping_status'           => 'closed',
          'post_password'         => '',
          'post_name'             => $slug,
          'to_ping'               => '',
          'pinged'                => '',
          'post_modified'         => $now,
          'post_modified_gmt'     => get_gmt_from_date( $now ),
          'post_content_filtered' => '',
          'post_parent'           => 0,
          'guid'                  => home_url( '/' . $slug . '/' ),
          'menu_order'            => 0,
          'post_type'             => 'page',
          'post_mime_type'        => '',
          'comment_count'         => 0,
        )
      );
    }
    clean_post_cache( 0 );
    wp_cache_flush();
    echo base64_encode( serialize( $snapshot ) );
  `).trim();
}

function shortenLiveSession(token: string): void {
  expect(token).toMatch(/^[a-f0-9]{32}$/);
  const result = wpEval(`
    $key = '_transient_timeout_faz_scan_session_' . hash( 'sha256', '${token}' );
    if ( false === get_option( $key, false ) ) {
      echo 'missing';
    } else {
      update_option( $key, time() + 12 );
      echo 'shortened';
    }
  `).trim();
  expect(result, 'the live browser-scan transient must exist before its idle window is compressed').toBe('shortened');
}

function cleanupLongCrawl(snapshot: string): void {
  expect(snapshot).toMatch(/^[A-Za-z0-9+/=]+$/);
  wpEval(`
    global $wpdb;
    wp_clear_scheduled_hook( 'faz_async_httponly_cookie_check' );
    delete_option( 'faz_httponly_scan_urls' );
    delete_option( 'faz_httponly_scan_lock' );
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_name LIKE 'faz-long-crawl-%'" );
    // Only the numbered instances this spec seeded. The bare 'faz-lab-headers'
    // page belongs to the other scan-lab specs and must survive.
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_name LIKE 'faz-lab-headers-%'" );
    $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => '${TAIL_COOKIE}' ), array( '%s' ) );
    $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => '${SERVER_OBSERVED_COOKIE}' ), array( '%s' ) );
    $state = unserialize( base64_decode( '${snapshot}' ) );
    if ( is_array( $state ) ) {
      ! empty( $state['history_exists'] )
        ? update_option( 'faz_scan_history', $state['history'], false )
        : delete_option( 'faz_scan_history' );
      ! empty( $state['counter_exists'] )
        ? update_option( 'faz_scan_counter', $state['counter'], false )
        : delete_option( 'faz_scan_counter' );
      ! empty( $state['details_exists'] )
        ? update_option( 'faz_scan_details', $state['details'], false )
        : delete_option( 'faz_scan_details' );
    }
    $wpdb->query(
      "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_faz_scan_session_%'"
      . " OR option_name LIKE '_transient_timeout_faz_scan_session_%'"
      . " OR option_name LIKE '_transient_faz_scan_active_%'"
      . " OR option_name LIKE '_transient_timeout_faz_scan_active_%'"
    );
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
        '_faz_scan_cookie_observation'
      )
    );
    wp_cache_flush();
  `);
}

test.describe('Release verify — 330-page crawl with sliding capture lifetime', () => {
  test('the real scan engine visits and imports at least 330 pages after the original idle deadline', async ({ page, loginAsAdmin }) => {
    test.setTimeout(15 * 60_000);
    // Pin the lab token BEFORE seeding so the sampled pages emit a cookie whose
    // name this spec can predict. The previous value is restored in cleanup.
    const previousLabToken = wpEval(
      `echo (string) get_option( 'faz_e2e_scan_lab_token', '' );`
    ).trim();
    setLabToken(LAB_TOKEN);
    const snapshot = seedLongCrawlPagesAndSnapshot();
    let scanDocuments = 0;
    let heartbeatRequests = 0;
    let tailWasServed = false;
    let realPageLoads = 0;

    page.on('request', (request) => {
      if (request.url().includes('/scans/heartbeat')) {
        heartbeatRequests += 1;
      }
    });

    await page.route('**/*', async (route) => {
      const request = route.request();
      let url: URL;
      try {
        url = new URL(request.url());
      } catch {
        await route.continue();
        return;
      }
      if (request.resourceType() !== 'document' || url.searchParams.get('faz_scanning') !== '1') {
        await route.continue();
        return;
      }

      scanDocuments += 1;
      const emitTailCookie = scanDocuments === PAGE_COUNT;
      tailWasServed ||= emitTailCookie;

      // Sampling is decided by the URL, never by the document counter: the
      // engine's dispatch order is its own business and need not match the
      // seeding order, so a counter-based rule would let arbitrary pages
      // through. The tail page is excluded unconditionally — it carries the
      // client-side cookie probe and must be the synthetic response.
      if ( ! emitTailCookie && url.pathname.includes('faz-lab-headers-') ) {
        realPageLoads += 1;
        await route.continue();
        return;
      }

      await route.fulfill({
        status: 200,
        contentType: 'text/html; charset=utf-8',
        body: `<!doctype html><html><body><p>cached page ${scanDocuments}</p>${
          emitTailCookie
            ? `<script>document.cookie=${JSON.stringify(`${TAIL_COOKIE}=seen; Path=/; SameSite=Lax`)};</script>`
            : ''
        }</body></html>`,
      });
    });

    try {
      await openCookiesPage(page, loginAsAdmin);
      await page.evaluate((pageCount) => {
        localStorage.removeItem('faz_scan_fingerprint');
        const nativeSetInterval = window.setInterval.bind(window);
        (window as any).__fazLongCrawlNativeSetInterval = nativeSetInterval;
        window.setInterval = ((handler: TimerHandler, timeout?: number, ...args: any[]) =>
          nativeSetInterval(handler, timeout === 300_000 ? 5_000 : timeout, ...args)) as typeof window.setInterval;
        (window as any).__fazLongCrawlPromise = (window as any).FAZ.scanEngine.run({ maxPages: pageCount }, {});
      }, PAGE_COUNT);

      let marker = '';
      await expect.poll(async () => {
        const cookies = await page.context().cookies(WP_BASE);
        marker = cookies.find((cookie) => cookie.name === 'faz_scan_session')?.value ?? '';
        return marker;
      }, { timeout: 30_000, message: 'scans/discover never issued its HttpOnly marker' }).toMatch(/^[a-f0-9]{32}$/);
      shortenLiveSession(marker);

      const result = await page.evaluate<ScanResult>(() => (window as any).__fazLongCrawlPromise);

      // WooCommerce priority URLs may be prepended even though discovery was
      // asked for 330 DB pages. They are legitimate additional work; the
      // invariant is that no discovered URL is silently dropped.
      expect(result.pagesScanned).toBeGreaterThanOrEqual(PAGE_COUNT);
      expect(result.metrics.pagesScanned).toBe(result.pagesScanned);
      expect(result.metrics.scannedUrls).toHaveLength(result.pagesScanned);
      expect(scanDocuments).toBe(result.pagesScanned);
      expect(tailWasServed, 'the tail-page cookie probe must run on page 330').toBe(true);
      expect(heartbeatRequests, 'the real scan engine must renew the compressed session during the crawl').toBeGreaterThan(0);
      expect(result.cookies.map((cookie) => cookie.name)).toContain(TAIL_COOKIE);
      expect(result.importResult.pages_scanned).toBe(result.pagesScanned);
      expect(result.importResult.cookie_names).toContain(TAIL_COOKIE);
      expect(result.importResult.enrichment_pending).toBe(true);
      expect(result.importResult.enrichment_urls).toBe(result.pagesScanned);

      // A meaningful slice of the crawl really booted PHP. Asserted as a
      // number, not a boolean: one stray real load would satisfy "> 0" while
      // leaving the path essentially untested.
      expect(
        realPageLoads,
        'the sampled pages must have reached the real server — with none of them the server-side capture path is untested',
      ).toBeGreaterThanOrEqual(Math.floor(PAGE_COUNT / REAL_PAGE_EVERY) - 1);

      // And the observer actually recorded them. This cookie is HttpOnly and
      // emitted by PHP, so no client-side path could have produced it: its
      // presence in the imported set is proof the shutdown observer ran while
      // the 330-page crawl was in flight, not merely that the pages loaded.
      expect(
        result.importResult.cookie_names,
        `${SERVER_OBSERVED_COOKIE} was set by PHP on the sampled pages but never observed — the server-side capture path did not run during the long crawl`,
      ).toContain(SERVER_OBSERVED_COOKIE);
    } finally {
      await page.unroute('**/*').catch(() => {});
      await page.context().clearCookies().catch(() => {});
      if (previousLabToken) {
        setLabToken(previousLabToken);
      }
      cleanupLongCrawl(snapshot);
    }
  });
});
