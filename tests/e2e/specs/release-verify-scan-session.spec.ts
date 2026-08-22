/**
 * Release verification — browser-scan session lifetime and capture scope.
 *
 * Verifies, against the INSTALLED release build (not the working tree), the
 * fixes for the scan-session defect pair:
 *
 *  - BROWSER_SCAN_TTL used to be opened once at scans/discover and never
 *    refreshed, so a long crawl hard-409'd at scans/import and lost all its
 *    work. Claimed fix: the TTL now SLIDES (capture path + a scans/heartbeat
 *    fallback route) under an absolute-age ceiling (BROWSER_SCAN_MAX_AGE).
 *  - A malformed scan_id at scans/import used to travel into the session
 *    check and come back as a 409 "session mismatch" instead of a 400 at the
 *    route boundary. Claimed fix: the routes declare a validated `scan_id`
 *    argument (32 lowercase hex, validated BEFORE sanitize_key runs).
 *
 * Contract read from the deployed source:
 *  - admin/modules/scanner/api/class-api.php — routes scans/discover,
 *    scans/import, scans/heartbeat, scans/abort all share
 *    `create_item_permissions_check` (manage_options + faz_verify_nonce,
 *    includes/class-rest-controller.php) and a required `scan_id` arg with
 *    `validate_scan_id` (^[a-f0-9]{32}$ → 400 faz_invalid_browser_scan_id).
 *  - admin/modules/scanner/includes/class-controller.php —
 *    BROWSER_SCAN_TTL=900 (idle window), BROWSER_SCAN_MAX_AGE=21600 (ceiling),
 *    session transient `faz_scan_session_<sha256(token)>`, per-user active
 *    lock `faz_scan_active_<uid>`, httpOnly marker cookie `faz_scan_session`.
 *    touch_browser_scan_session() refuses a scan_id that does not own the
 *    session and refuses any session whose created_at exceeds the ceiling.
 *
 * State discipline: every session a test opens is released in `finally`
 * through the real scans/abort route (status asserted), and the ephemeral
 * scan-session transients are verified gone so no per-user "scan already in
 * progress" lock (TTL up to 15 minutes) leaks into later specs.
 */
import { randomBytes } from 'node:crypto';
import { expect, test } from '../fixtures/wp-fixture';
import { fazApiPost, openCookiesPage } from '../utils/faz-api';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

/** Mint a scan id exactly the shape createScanId() produces client-side. */
function randomScanId(): string {
  return randomBytes(16).toString('hex');
}

/**
 * Remove any leftover browser-scan session state (ours or a previous
 * spec's). These transients are pure ephemeral scan state — the same rows
 * utils/wp-env.ts#resetScanState clears — so deleting them is cleanup, not
 * a settings mutation. Without this, a session leaked by an earlier spec
 * holds the per-user active lock and our scans/discover would 409.
 */
function clearBrowserScanSessionState(): void {
  wpEval(`
    global $wpdb;
    $wpdb->query(
      "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_faz_scan_session_%'"
      . " OR option_name LIKE '_transient_timeout_faz_scan_session_%'"
      . " OR option_name LIKE '_transient_faz_scan_active_%'"
      . " OR option_name LIKE '_transient_timeout_faz_scan_active_%'"
    );
    wp_cache_flush();
    echo 'cleared';
  `);
}

/** How many browser-scan session/active transient rows currently exist. */
function countBrowserScanSessionRows(): number {
  const raw = wpEval(`
    global $wpdb;
    echo (int) $wpdb->get_var(
      "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_faz_scan_session_%'"
      . " OR option_name LIKE '_transient_faz_scan_active_%'"
    );
  `);
  return Number.parseInt(raw.trim(), 10);
}

/**
 * Read the httpOnly scan marker issued by scans/discover. Playwright's
 * context jar exposes httpOnly cookies, and page.request shares that jar,
 * so the marker rides along on every subsequent REST call — exactly like
 * the real scanner. The shape is asserted before the token is ever
 * interpolated into PHP.
 */
async function readScanMarkerToken(page: import('@playwright/test').Page): Promise<string> {
  const cookies = await page.context().cookies(WP_BASE);
  const marker = cookies.find((cookie) => cookie.name === 'faz_scan_session');
  expect(marker, 'scans/discover must set the httpOnly faz_scan_session marker').toBeTruthy();
  const token = marker?.value ?? '';
  expect(token).toMatch(/^[a-f0-9]{32}$/);
  return token;
}

/** DB expiry (unix ts) of the session transient for a marker token. 0 = absent. */
function readSessionTransientTimeout(token: string): number {
  const raw = wpEval(`
    echo (int) get_option( '_transient_timeout_faz_scan_session_' . hash( 'sha256', '${token}' ) );
  `);
  return Number.parseInt(raw.trim(), 10);
}

/** Open a capture session through the real discover route and return its marker token. */
async function openCaptureSession(page: import('@playwright/test').Page, nonce: string, scanId: string): Promise<string> {
  const discover = await fazApiPost<{ urls: string[] }>(page, nonce, 'scans/discover', {
    max_pages: 5,
    scan_id: scanId,
  });
  expect(discover.status, `scans/discover refused: ${JSON.stringify(discover.data)}`).toBe(200);
  expect(Array.isArray(discover.data.urls)).toBe(true);
  return readScanMarkerToken(page);
}

/**
 * Release a session through the real scans/abort route and assert the
 * release actually happened — both at the transport (200) and in the DB
 * (no session/active transients left behind for later specs to trip on).
 */
async function abortCaptureSession(page: import('@playwright/test').Page, nonce: string, scanId: string): Promise<void> {
  const abort = await fazApiPost<{ aborted: boolean }>(page, nonce, 'scans/abort', { scan_id: scanId });
  expect(abort.status, 'session cleanup via scans/abort must succeed').toBe(200);
  expect(abort.data.aborted, 'scans/abort must report the session as released').toBe(true);
  expect(countBrowserScanSessionRows(), 'no scan-session transients may leak past this test').toBe(0);
  await page.context().clearCookies();
}

test.describe('Release verify — browser-scan session TTL and import boundary', () => {
  test('scans/heartbeat is registered and refuses unauthenticated callers with the same gate as scans/import', async ({ request }) => {
    // `request` is Playwright's per-test APIRequestContext: no WP cookies, no
    // nonce — a genuinely anonymous caller. The scan id is deliberately
    // well-formed so the only thing being refused is the caller, not the
    // parameter, whatever order permission/validation runs in.
    const scanId = randomScanId();

    const call = async (route: string) => {
      const response = await request.post(`${WP_BASE}/?rest_route=/faz/v1/scans/${route}`, {
        data: { scan_id: scanId },
        headers: { 'Content-Type': 'application/json' },
      });
      return { status: response.status(), data: (await response.json()) as { code?: string } };
    };

    const heartbeat = await call('heartbeat');
    const importCall = await call('import');

    // Route must EXIST: a 404/rest_no_route here means the release ZIP
    // shipped without the heartbeat fallback and the sliding-TTL fix is
    // dead on any fully page-cached site.
    expect(heartbeat.status).not.toBe(404);
    expect(heartbeat.data.code).not.toBe('rest_no_route');

    // ...and must be CLOSED to anonymous callers, exactly like import:
    // heartbeat renews a lock scoped to an administrator's capture session,
    // so an open route would let anyone keep any admin's scan lock alive.
    expect([401, 403]).toContain(heartbeat.status);
    expect([401, 403]).toContain(importCall.status);
    expect(heartbeat.status).toBe(importCall.status);
    expect(heartbeat.data.code).toBe(importCall.data.code);
  });

  test('a live capture session renews only for the scan id that opened it', async ({ page, loginAsAdmin }) => {
    clearBrowserScanSessionState();
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const ownerScanId = randomScanId();
    const strangerScanId = randomScanId();

    try {
      await openCaptureSession(page, nonce, ownerScanId);

      // A different (well-formed) scan id presented against the live session
      // must NOT slide the window. If this renewed, a second tab — or a
      // replayed request — could keep another scan's lock alive forever.
      const stranger = await fazApiPost<{ renewed: boolean; expires_in: number }>(page, nonce, 'scans/heartbeat', {
        scan_id: strangerScanId,
      });
      expect(stranger.status).toBe(200);
      expect(stranger.data.renewed).toBe(false);

      // Positive control: the OWNING scan id renews the same session over
      // the same transport. Without this, the refusal above could be a
      // broken route passing for a strict one.
      const owner = await fazApiPost<{ renewed: boolean; expires_in: number }>(page, nonce, 'scans/heartbeat', {
        scan_id: ownerScanId,
      });
      expect(owner.status).toBe(200);
      expect(owner.data.renewed).toBe(true);
      expect(owner.data.expires_in).toBeGreaterThan(0);
    } finally {
      await abortCaptureSession(page, nonce, ownerScanId);
    }
  });

  test('a heartbeat slides the idle window, so a kept-alive session outlives its nominal expiry', async ({ page, loginAsAdmin }) => {
    // The real idle window is 900s — un-testable directly. Instead the
    // window is shrunk in the DB to a few seconds, a heartbeat is sent, and
    // the session must (a) show a re-extended expiry in the DB and (b) still
    // be renewable AFTER the shrunk window has wall-clock expired. If the
    // heartbeat did not slide the TTL, step (b) goes red — which is exactly
    // the pre-fix behaviour that discarded 92-minute crawls.
    test.setTimeout(120_000);
    clearBrowserScanSessionState();
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const scanId = randomScanId();

    try {
      const token = await openCaptureSession(page, nonce, scanId);

      const initialTimeout = readSessionTransientTimeout(token);
      expect(
        initialTimeout,
        'session transient not found in wp_options — is a persistent object cache active on the test site?'
      ).toBeGreaterThan(0);

      // Shrink the idle window to ~8s from now, as if the crawl had nearly
      // exhausted it.
      wpEval(`
        update_option( '_transient_timeout_faz_scan_session_' . hash( 'sha256', '${token}' ), time() + 8 );
        echo 'shrunk';
      `);
      const shrunkTimeout = readSessionTransientTimeout(token);
      expect(shrunkTimeout).toBeLessThan(Math.floor(Date.now() / 1000) + 30);

      // Keep-alive. This is the sliding-TTL fix under test.
      const beat = await fazApiPost<{ renewed: boolean; expires_in: number }>(page, nonce, 'scans/heartbeat', {
        scan_id: scanId,
      });
      expect(beat.status).toBe(200);
      expect(beat.data.renewed).toBe(true);

      // (a) The DB expiry must have been pushed well past the shrunk window
      // — a full fresh idle window, not a no-op.
      const renewedTimeout = readSessionTransientTimeout(token);
      expect(renewedTimeout).toBeGreaterThan(shrunkTimeout);
      expect(renewedTimeout).toBeGreaterThan(Math.floor(Date.now() / 1000) + 600);

      // (b) Outlive the nominal (shrunk) window on the wall clock, then
      // prove the session is still alive and owned. Without the slide, the
      // transient dies at +8s and this heartbeat reports renewed=false.
      await page.waitForTimeout(10_000);
      const afterExpiryBeat = await fazApiPost<{ renewed: boolean }>(page, nonce, 'scans/heartbeat', {
        scan_id: scanId,
      });
      expect(afterExpiryBeat.status).toBe(200);
      expect(afterExpiryBeat.data.renewed).toBe(true);
    } finally {
      await abortCaptureSession(page, nonce, scanId);
    }
  });

  test('the sliding window has an absolute-age ceiling: a session past BROWSER_SCAN_MAX_AGE cannot be renewed', async ({ page, loginAsAdmin }) => {
    // A sliding idle timeout with no ceiling turns a wedged tab into a
    // permanent scan lock. touch_browser_scan_session() carries created_at
    // forward untouched and compares it against BROWSER_SCAN_MAX_AGE
    // (21600s); backdating created_at past the ceiling must make renewal
    // impossible even for the OWNING scan id.
    clearBrowserScanSessionState();
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const scanId = randomScanId();

    try {
      const token = await openCaptureSession(page, nonce, scanId);

      // Positive control first: fresh session, owning id → renewable.
      const freshBeat = await fazApiPost<{ renewed: boolean }>(page, nonce, 'scans/heartbeat', { scan_id: scanId });
      expect(freshBeat.status).toBe(200);
      expect(freshBeat.data.renewed).toBe(true);

      // Backdate the session's birth past the six-hour ceiling.
      const backdated = wpEval(`
        $key = '_transient_faz_scan_session_' . hash( 'sha256', '${token}' );
        $session = get_option( $key );
        if ( is_array( $session ) && isset( $session['created_at'] ) ) {
          $session['created_at'] = time() - ( 21600 + 300 );
          update_option( $key, $session );
          echo 'backdated';
        } else {
          echo 'missing';
        }
      `);
      expect(backdated.trim()).toBe('backdated');

      const ancientBeat = await fazApiPost<{ renewed: boolean }>(page, nonce, 'scans/heartbeat', { scan_id: scanId });
      expect(ancientBeat.status).toBe(200);
      expect(ancientBeat.data.renewed).toBe(false);
    } finally {
      // abort still matches on token+scan_id (the ceiling gates RENEWAL,
      // not release), so the lock is torn down normally.
      await abortCaptureSession(page, nonce, scanId);
    }
  });

  test('the installed scan engine retries a transient import with the same scan id and payload', async ({ page, loginAsAdmin }) => {
    test.setTimeout(180_000);
    clearBrowserScanSessionState();
    await openCookiesPage(page, loginAsAdmin);
    await page.evaluate(() => localStorage.removeItem('faz_scan_fingerprint'));

    let discoverCalls = 0;
    const importPayloads: Array<Record<string, unknown>> = [];
    const endpointIn = (rawURL: string, endpoint: string): boolean => {
      let decoded = rawURL;
      try { decoded = decodeURIComponent(rawURL); } catch {}
      return decoded.includes(`/wp-json/faz/v1/scans/${endpoint}`)
        || decoded.includes(`rest_route=/faz/v1/scans/${endpoint}`);
    };

    await page.route('**/*', async (route) => {
      const request = route.request();
      if (endpointIn(request.url(), 'discover')) {
        discoverCalls += 1;
        const response = await route.fetch();
        const body = await response.json() as Record<string, unknown>;
        await route.fulfill({
          response,
          json: {
            ...body,
            urls: [`${WP_BASE}/`],
            priority_urls: [],
            total: 1,
            incremental: false,
          },
        });
        return;
      }
      if (endpointIn(request.url(), 'import')) {
        importPayloads.push(request.postDataJSON() as Record<string, unknown>);
        if (importPayloads.length === 1) {
          await route.fulfill({
            status: 500,
            contentType: 'application/json',
            json: {
              code: 'faz_scan_import_failed',
              message: 'Induced transient persistence failure',
              data: { status: 500 },
            },
          });
          return;
        }
      }
      await route.continue();
    });

    try {
      const result = await page.evaluate(async () => {
        const engine = (window as any).FAZ?.scanEngine;
        if (!engine?.run) throw new Error('FAZ.scanEngine is not loaded on the Cookies page');
        return engine.run({ maxPages: 1 }, {});
      });

      expect(result.importResult).toBeTruthy();
      expect(discoverCalls, 'retry must not start another crawl/session').toBe(1);
      expect(importPayloads).toHaveLength(2);
      expect(importPayloads[0].scan_id).toMatch(/^[a-f0-9]{32}$/);
      expect(importPayloads[1].scan_id).toBe(importPayloads[0].scan_id);
      expect(importPayloads[1]).toEqual(importPayloads[0]);
      expect(countBrowserScanSessionRows(), 'successful retried import must close the session').toBe(0);
    } finally {
      await page.unroute('**/*');
      clearBrowserScanSessionState();
      await page.context().clearCookies();
    }
  });

  test('scans/import rejects a malformed scan_id as 400 at the route boundary, never as 409', async ({ page, loginAsAdmin }) => {
    // Pre-fix, a malformed id travelled into the session check and came back
    // as a 409 "session mismatch" — indistinguishable, to an administrator,
    // from an expired scan. The route now declares its args: validate_scan_id
    // runs on the RAW value (before sanitize_key), so every shape below must
    // die at the boundary as a 400.
    const nonce = await openCookiesPage(page, loginAsAdmin);

    const malformedIds = [
      'not-a-valid-id',
      'deadbeef', // truncated hex
      randomScanId().toUpperCase(), // right length, wrong case
      `${'a'.repeat(32)}!`, // becomes well-formed only AFTER sanitize_key — must still be refused
    ];

    for (const badId of malformedIds) {
      const response = await fazApiPost<{ code?: string }>(page, nonce, 'scans/import', {
        cookies: [],
        pages_scanned: 1,
        scan_id: badId,
        scanned_urls: [],
        scripts: [],
      });
      expect(response.status, `scan_id ${JSON.stringify(badId)} must be a 400, got ${response.status}`).toBe(400);
      expect(response.status).not.toBe(409);
      // The refusal must be the arg-validation one, not a session-shaped error.
      expect(response.data.code).toBe('rest_invalid_param');
    }
  });

  test('scans/import refuses a request that carries no scan_id at all', async ({ page, loginAsAdmin }) => {
    // scan_id is declared `required` on the route. An import with no id has
    // no session to belong to; accepting it would re-open the door to
    // provenance-free merges of whatever the observer captured.
    const nonce = await openCookiesPage(page, loginAsAdmin);

    const response = await fazApiPost<{ code?: string }>(page, nonce, 'scans/import', {
      cookies: [{ name: 'faz_release_verify_ghost', domain: '127.0.0.1', duration: 'session' }],
      pages_scanned: 1,
    });

    expect(response.status).toBe(400);
    expect(response.status).not.toBe(409);
    expect(response.data.code).toBe('rest_missing_callback_param');
  });
});
