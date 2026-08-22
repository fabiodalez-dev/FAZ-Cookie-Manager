/**
 * Already-open capture session — visibility after reload, explicit stop.
 *
 * A browser crawl lives in its tab, but its capture session on the server does
 * not: reload the Cookies page mid-scan (or lose the tab while the abort
 * beacon is blocked) and the per-user session lock stays for up to fifteen
 * minutes, previously discoverable only as a bare `faz_browser_scan_in_progress`
 * 409 with no way out short of deleting transients over SSH.
 *
 * Contract under test (deployed source):
 *  - GET scans/session (admin/modules/scanner/api/class-api.php) reports the
 *    CALLER'S own session from Controller::describe_browser_scan_session():
 *    only server-side truth (started_at, last_activity = max of created_at /
 *    touched_at / newest observation, observation count) — never a fabricated
 *    client progress counter.
 *  - The Cookies page (admin/assets/js/pages/cookies.js) renders that state in
 *    #faz-scan-session-panel: pulsing while something still drives the session,
 *    amber/stalled (`faz-scan-progress-held`) once nothing has touched it, with
 *    an explicit "End this scan" button. Ending is NEVER automatic.
 *  - scans/abort (Controller::abort_browser_scan_session) releases the session
 *    via the marker cookie when the request still carries it, and otherwise via
 *    the user-keyed active record — but only for the CURRENT user, and only
 *    when the caller presents that session's own scan_id.
 *  - A live session with a different scan_id still 409s at scans/discover:
 *    the server never reclaims or clears a live session on its own.
 *
 * State discipline: every test clears the scan-session transients and its
 * seeded observations in `finally`, so no per-user lock leaks into later specs.
 */
import { randomBytes } from 'node:crypto';
import { expect, test } from '../fixtures/wp-fixture';
import { fazApiGet, fazApiPost, openCookiesPage } from '../utils/faz-api';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const ADMIN_LOGIN = process.env.WP_ADMIN_USER ?? 'admin';
const SEEDED_OBSERVATION_NAME = 'faz_session_resume_probe';

/** Mint an id exactly the shape createScanId() produces client-side. */
function randomScanId(): string {
  return randomBytes(16).toString('hex');
}

function adminUserId(): number {
  const raw = wpEval(`echo (int) get_user_by( 'login', '${ADMIN_LOGIN}' )->ID;`);
  const id = Number.parseInt(raw.trim(), 10);
  if (!Number.isInteger(id) || id <= 0) {
    throw new Error(`Could not resolve admin user id for login "${ADMIN_LOGIN}" (got ${JSON.stringify(raw)})`);
  }
  return id;
}

/** Remove browser-scan session transients (ours or a previous spec's leak). */
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
 * Seed a capture session exactly as an interrupted crawl leaves it: active
 * record + session transient + one captured observation — and, crucially, NO
 * marker cookie in this browser (a different browser/profile, or an evicted
 * cookie). `idleS` backdates every activity signal, so idleS=600 is a session
 * nothing has touched in ten minutes (stalled) and idleS=5 one still warm.
 */
function seedCaptureSession(
  uid: number,
  token: string,
  scanId: string,
  opts: { idleS?: number; state?: 'held' } = {},
): void {
  const idleS = opts.idleS ?? 600;
  const stateField = opts.state === 'held' ? `'state' => 'held', 'held_at' => time() - ${idleS},` : '';
  const seeded = wpEval(`
    $uid = ${uid};
    $token = '${token}';
    $scan_id = '${scanId}';
    set_transient(
      'faz_scan_active_' . $uid,
      array( 'token' => $token, 'scan_id' => $scan_id, 'created_at' => time() - ${idleS}, ${stateField} ),
      900
    );
    set_transient(
      'faz_scan_session_' . hash( 'sha256', $token ),
      array( 'user_id' => $uid, 'scan_id' => $scan_id, 'created_at' => time() - ${idleS}, 'touched_at' => time() - ${idleS} ),
      900
    );
    add_user_meta(
      $uid,
      '_faz_scan_cookie_observation',
      array( 'token' => $token, 'observed_at' => time() - ${idleS}, 'name' => '${SEEDED_OBSERVATION_NAME}', 'request_path' => '/' ),
      false
    );
    echo 'seeded';
  `);
  expect(seeded.trim()).toBe('seeded');
}

function countSeededObservations(uid: number): number {
  const raw = wpEval(`
    $count = 0;
    foreach ( (array) get_user_meta( ${uid}, '_faz_scan_cookie_observation', false ) as $row ) {
      if ( is_array( $row ) && isset( $row['name'] ) && '${SEEDED_OBSERVATION_NAME}' === $row['name'] ) { $count++; }
    }
    echo $count;
  `);
  return Number.parseInt(raw.trim(), 10);
}

function clearSeededObservations(uid: number): void {
  wpEval(`
    foreach ( (array) get_user_meta( ${uid}, '_faz_scan_cookie_observation', false ) as $row ) {
      if ( is_array( $row ) && isset( $row['name'] ) && '${SEEDED_OBSERVATION_NAME}' === $row['name'] ) {
        delete_user_meta( ${uid}, '_faz_scan_cookie_observation', $row );
      }
    }
    echo 'cleared';
  `);
}

test.describe('Scan session resume — reload visibility and explicit stop', () => {
  test('a stalled session appears on page load and can be ended without the marker cookie', async ({ page, loginAsAdmin }) => {
    // The exact incident: the session exists server-side, this browser has no
    // marker cookie (session was seeded, not started here), and a new scan is
    // refused. Pre-fix the 409 was the whole story; now the page must SHOW the
    // session, show it as stalled (nothing has touched it in 10 minutes), and
    // the End button — through the user-keyed fallback, since the cookie path
    // cannot match — must release it and unblock scanning.
    clearBrowserScanSessionState();
    const uid = adminUserId();
    const token = randomScanId();
    const scanId = randomScanId();

    try {
      seedCaptureSession(uid, token, scanId, { idleS: 600 });
      const nonce = await openCookiesPage(page, loginAsAdmin);

      const panel = page.locator('#faz-scan-session-panel');
      await expect(panel, 'the open session must be visible on page load').toBeVisible({ timeout: 10_000 });
      // Ten silent minutes must be presented as stalled, not as a crawl in
      // progress — the amber held styling is that distinction.
      await expect(panel).toHaveClass(/faz-scan-progress-held/);
      await expect(page.locator('#faz-scan-session-end')).toBeVisible();

      // The refusal the administrator used to hit blind, still intact.
      const refused = await fazApiPost<{ code?: string }>(page, nonce, 'scans/discover', {
        max_pages: 5,
        scan_id: randomScanId(),
      });
      expect(refused.status).toBe(409);
      expect(refused.data.code).toBe('faz_browser_scan_in_progress');

      // Explicit human action — the only sanctioned way out.
      await page.locator('#faz-scan-session-end').click();
      await expect(panel).toHaveCount(0, { timeout: 10_000 });
      await expect.poll(() => countBrowserScanSessionRows(), { timeout: 10_000 }).toBe(0);
      // Ending discards the capture: the seeded observation must be gone too.
      expect(countSeededObservations(uid)).toBe(0);

      // ...and scanning works again.
      const freshScanId = randomScanId();
      const discover = await fazApiPost<{ urls?: string[] }>(page, nonce, 'scans/discover', {
        max_pages: 5,
        scan_id: freshScanId,
      });
      expect(discover.status, 'a new scan must start once the stuck session is ended').toBe(200);

      const release = await fazApiPost<{ aborted: boolean }>(page, nonce, 'scans/abort', { scan_id: freshScanId });
      expect(release.status).toBe(200);
      expect(release.data.aborted).toBe(true);
    } finally {
      clearSeededObservations(uid);
      clearBrowserScanSessionState();
      await page.context().clearCookies();
    }
  });

  test('a recently-active session is shown as live and a competing scan is still refused — never auto-cleared', async ({ page, loginAsAdmin }) => {
    // The restraint that must survive this feature: a session with recent
    // activity may be another tab genuinely crawling. The page may show it,
    // but nothing — not the panel, not a competing discover — may take it
    // away without the human asking.
    clearBrowserScanSessionState();
    const uid = adminUserId();
    const token = randomScanId();
    const scanId = randomScanId();

    try {
      seedCaptureSession(uid, token, scanId, { idleS: 5 });
      const nonce = await openCookiesPage(page, loginAsAdmin);

      const panel = page.locator('#faz-scan-session-panel');
      await expect(panel).toBeVisible({ timeout: 10_000 });
      // 5s of silence is inside the stall horizon: presented as capturing.
      await expect(panel).not.toHaveClass(/faz-scan-progress-held/);

      const refused = await fazApiPost<{ code?: string }>(page, nonce, 'scans/discover', {
        max_pages: 5,
        scan_id: randomScanId(),
      });
      expect(refused.status).toBe(409);
      expect(refused.data.code).toBe('faz_browser_scan_in_progress');

      // Neither the refusal nor the page's own polling stole the session.
      expect(countBrowserScanSessionRows()).toBe(2);
      const described = await fazApiGet<{ active: boolean; scan_id?: string }>(page, nonce, 'scans/session');
      expect(described.status).toBe(200);
      expect(described.data.active).toBe(true);
      expect(described.data.scan_id).toBe(scanId);
      expect(countSeededObservations(uid)).toBe(1);
    } finally {
      clearSeededObservations(uid);
      clearBrowserScanSessionState();
      await page.context().clearCookies();
    }
  });

  test('the cookie-less release path only answers to the session\'s own scan id', async ({ page, loginAsAdmin }) => {
    // The fallback must be a key, not a crowbar: without the marker cookie,
    // abort releases the caller's session only when the caller names it by
    // its own scan_id — a well-formed stranger id releases nothing.
    clearBrowserScanSessionState();
    const uid = adminUserId();
    const token = randomScanId();
    const scanId = randomScanId();

    try {
      seedCaptureSession(uid, token, scanId, { idleS: 600 });
      const nonce = await openCookiesPage(page, loginAsAdmin);

      const wrongId = await fazApiPost<{ aborted: boolean }>(page, nonce, 'scans/abort', { scan_id: randomScanId() });
      expect(wrongId.status).toBe(200);
      expect(wrongId.data.aborted).toBe(false);
      expect(countBrowserScanSessionRows(), 'a stranger scan id must release nothing').toBe(2);
      expect(countSeededObservations(uid)).toBe(1);

      const ownId = await fazApiPost<{ aborted: boolean }>(page, nonce, 'scans/abort', { scan_id: scanId });
      expect(ownId.status).toBe(200);
      expect(ownId.data.aborted).toBe(true);
      expect(countBrowserScanSessionRows()).toBe(0);
      expect(countSeededObservations(uid)).toBe(0);
    } finally {
      clearSeededObservations(uid);
      clearBrowserScanSessionState();
      await page.context().clearCookies();
    }
  });

  test('scans/session is closed to anonymous callers', async ({ request }) => {
    // The route names the caller's session (including its scan_id — the abort
    // handle). It must be exactly as closed as every other scanner route.
    const response = await request.get(`${WP_BASE}/?rest_route=/faz/v1/scans/session`);
    expect([401, 403]).toContain(response.status());
    const body = (await response.json()) as { code?: string };
    expect(body.code).not.toBe('rest_no_route');
  });

  test('scans/session reports only what the server actually knows', async ({ page, loginAsAdmin }) => {
    // The honesty contract: started_at / last_activity / observations are the
    // server's own records; there is no client page counter to resurrect, so
    // none is invented. Also pins the i18n key the panel's stop button uses.
    clearBrowserScanSessionState();
    const uid = adminUserId();
    const token = randomScanId();
    const scanId = randomScanId();

    try {
      seedCaptureSession(uid, token, scanId, { idleS: 120 });
      const nonce = await openCookiesPage(page, loginAsAdmin);

      const described = await fazApiGet<{
        active: boolean;
        state?: string;
        scan_id?: string;
        started_at?: number;
        last_activity?: number;
        observations?: number;
        server_time?: number;
      }>(page, nonce, 'scans/session');
      expect(described.status).toBe(200);
      expect(described.data.active).toBe(true);
      expect(described.data.state).toBe('live');
      expect(described.data.scan_id).toBe(scanId);
      expect(described.data.started_at).toBeGreaterThan(0);
      expect(described.data.last_activity).toBeGreaterThanOrEqual(described.data.started_at ?? 0);
      expect(described.data.observations).toBe(1);
      expect(described.data.server_time).toBeGreaterThanOrEqual(described.data.last_activity ?? 0);

      const i18nKey = await page.evaluate(
        () => (window as any).fazConfig?.i18n?.cookies?.endActiveScan ?? '',
      );
      expect(i18nKey, 'cookies.endActiveScan must be registered for the stop button').not.toBe('');
    } finally {
      clearSeededObservations(uid);
      clearBrowserScanSessionState();
      await page.context().clearCookies();
    }
  });

  test('a held session neither blocks a new scan nor summons the panel', async ({ page, loginAsAdmin }) => {
    // Held evidence never locks anyone out (start_browser_scan_session
    // reclaims it), so showing it as a blocking session would invite the
    // administrator to "end" something that was never in the way.
    clearBrowserScanSessionState();
    const uid = adminUserId();
    const token = randomScanId();
    const scanId = randomScanId();

    try {
      seedCaptureSession(uid, token, scanId, { idleS: 60, state: 'held' });
      const nonce = await openCookiesPage(page, loginAsAdmin);

      // Give the page's session check time to have run, then require absence.
      await page.waitForTimeout(2000);
      await expect(page.locator('#faz-scan-session-panel')).toHaveCount(0);

      const freshScanId = randomScanId();
      const discover = await fazApiPost<{ urls?: string[] }>(page, nonce, 'scans/discover', {
        max_pages: 5,
        scan_id: freshScanId,
      });
      expect(discover.status, 'a held session must be reclaimed, not 409ed against').toBe(200);
      // Reclaiming discarded the held capture.
      expect(countSeededObservations(uid)).toBe(0);

      const release = await fazApiPost<{ aborted: boolean }>(page, nonce, 'scans/abort', { scan_id: freshScanId });
      expect(release.status).toBe(200);
      expect(release.data.aborted).toBe(true);
    } finally {
      clearSeededObservations(uid);
      clearBrowserScanSessionState();
      await page.context().clearCookies();
    }
  });

  test('reload mid-crawl with the abort beacon blocked: the dead end resolves from the UI', async ({ page, loginAsAdmin }) => {
    // The real incident, driven end to end: a crawl started from the UI, the
    // tab's pagehide abort beacon suppressed (exactly what ad blockers and
    // browser shields do), the page reloaded mid-crawl. The session survives
    // the reload; the reloaded page must show it and end it on request —
    // this time through the marker-cookie path, since the same browser still
    // carries the httpOnly token — and a new crawl must then start.
    test.setTimeout(240_000);
    clearBrowserScanSessionState();

    try {
      await page.addInitScript(() => {
        // An ad blocker swallowing the abort beacon: reports success, sends nothing.
        navigator.sendBeacon = () => true;
      });
      await openCookiesPage(page, loginAsAdmin);
      await page.evaluate(() => localStorage.removeItem('faz_scan_fingerprint'));

      // Start a quick scan from the real dropdown.
      await page.click('#faz-scan-btn');
      await page.click('#faz-scan-dropdown [data-depth="10"]');

      // Session open on the server = discover committed, crawl underway.
      await expect.poll(() => countBrowserScanSessionRows(), { timeout: 30_000 }).toBeGreaterThanOrEqual(2);

      // Abandon the crawl. The blocked beacon means the session survives.
      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
      expect(countBrowserScanSessionRows()).toBeGreaterThanOrEqual(2);

      // The reloaded page surfaces the orphaned session...
      const panel = page.locator('#faz-scan-session-panel');
      await expect(panel).toBeVisible({ timeout: 15_000 });

      // ...and ends it on explicit request (marker-cookie path).
      await page.locator('#faz-scan-session-end').click();
      await expect(panel).toHaveCount(0, { timeout: 10_000 });
      await expect.poll(() => countBrowserScanSessionRows(), { timeout: 10_000 }).toBe(0);

      // The next scan starts instead of 409ing.
      await page.click('#faz-scan-btn');
      await page.click('#faz-scan-dropdown [data-depth="10"]');
      const progress = page.locator('.faz-scan-progress-wrap:not(.faz-scan-session-wrap)');
      await expect(progress).toBeVisible({ timeout: 15_000 });
      await expect.poll(() => countBrowserScanSessionRows(), { timeout: 30_000 }).toBeGreaterThanOrEqual(2);

      // Wind the run down quickly: Stop imports the partial result and
      // finishes the session, leaving nothing for later specs.
      await page.locator('.faz-scan-stop').click();
      await expect(progress).toHaveCount(0, { timeout: 180_000 });
      await expect.poll(() => countBrowserScanSessionRows(), { timeout: 15_000 }).toBe(0);
    } finally {
      clearBrowserScanSessionState();
      await page.context().clearCookies();
    }
  });
});
