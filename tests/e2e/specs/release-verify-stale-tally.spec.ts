/**
 * Release verification — consecutive-miss safeguard for stale-cookie deletion.
 *
 * What was broken in earlier builds: the missed-scan tally was computed,
 * persisted and returned over REST but READ BY NOBODY — `bulk_delete()` never
 * consulted it, so the destructive "Delete all stale" flow ran on a single-scan
 * client diff. Separately the completeness gate ignored scan DEPTH, so a
 * 20-page scan of a large site counted as full-site evidence, and the server
 * keyed the tally on raw `name|domain` while the client lowercased and
 * stripped leading dots — two key formats whose intersection is always empty.
 *
 * These tests exercise the INSTALLED plugin over REST:
 *  - `cookies/bulk-delete` with `reason=stale` refuses ids whose canonical key
 *    has not earned MISSED_SCANS_THRESHOLD (=2) consecutive complete misses,
 *    and reports a `refused` count;
 *  - the SAME id without the reason is deleted normally (the unscoped admin
 *    path must not be gated);
 *  - `scans/import` marks a depth-capped run incomplete (`scan_was_complete`)
 *    and does not advance the tally, while a full-depth run does and surfaces
 *    the earned keys in `deletable_stale_keys`;
 *  - canonicalization: a tally key seeded in legacy raw form (mixed case,
 *    leading dot, `:port`) still intersects with a differently-cased row.
 *
 * Server contract read from:
 *  - admin/modules/cookies/api/class-cookies-api.php  (bulk_delete, reason arg)
 *  - admin/modules/scanner/includes/class-controller.php
 *    (record_scan_observations, scan_coverage_is_complete, canonical_key,
 *     deletable_stale_keys, MISSED_SCANS_OPTION = faz_cookie_missed_scans,
 *     MISSED_SCANS_THRESHOLD = 2)
 *  - admin/modules/scanner/api/class-api.php  (import_cookies response fields)
 */
import { expect, test } from '../fixtures/wp-fixture';
import { fazApiDelete, fazApiPost, listCategories, listCookies, openCookiesPage } from '../utils/faz-api';
import { clearAllFazCookieCaches, resetScanState, wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const SITE_HOST = new URL(WP_BASE).hostname;

const MISSED_SCANS_OPTION = 'faz_cookie_missed_scans';
const RECYCLE_BIN_OPTION = 'faz_cookies_recycle_bin';
/** Sentinel meaning "the option row did not exist" in snapshots. */
const ABSENT = '__faz_e2e_absent__';

type BulkDeleteResponse = {
  deleted: number;
  restorable: number;
  refused: number;
};

type ImportResponse = {
  scan_id: number;
  total_cookies: number;
  cookie_names: string[];
  scan_was_complete: boolean;
  deletable_stale_keys: string[];
};

function makeToken(prefix: string): string {
  const random = Math.random().toString(36).slice(2, 8);
  return `${prefix}_${Date.now().toString(36)}_${random}`.toLowerCase();
}

function makeScanId(): string {
  return Array.from({ length: 32 }, () => Math.floor(Math.random() * 16).toString(16)).join('');
}

function phpStringLiteral(value: string): string {
  return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

/** Snapshot an option as JSON text; ABSENT sentinel when the row is missing. */
function snapshotOption(name: string): string {
  return wpEval(`echo wp_json_encode( get_option( ${phpStringLiteral(name)}, ${phpStringLiteral(ABSENT)} ) );`);
}

/** Restore an option to a prior snapshot (deleting it if it did not exist). */
function restoreOption(name: string, snapshotJson: string): void {
  const parsed = JSON.parse(snapshotJson) as unknown;
  if (parsed === ABSENT) {
    wpEval(`delete_option( ${phpStringLiteral(name)} );`);
    return;
  }
  const b64 = Buffer.from(snapshotJson, 'utf8').toString('base64');
  wpEval(`update_option( ${phpStringLiteral(name)}, json_decode( base64_decode( '${b64}' ), true ), false );`);
}

/** Assert the restore actually landed: current stored value === snapshot. */
function assertOptionRestored(name: string, snapshotJson: string): void {
  const now = wpEval(`echo wp_json_encode( get_option( ${phpStringLiteral(name)}, ${phpStringLiteral(ABSENT)} ) );`);
  expect(JSON.parse(now), `option ${name} restored to its pre-test value`).toEqual(JSON.parse(snapshotJson));
}

/** Merge entries into the missed-scan tally option (keys exactly as given). */
function seedTally(entries: Record<string, number>): void {
  const b64 = Buffer.from(JSON.stringify(entries), 'utf8').toString('base64');
  wpEval(`
    $counts = get_option( ${phpStringLiteral(MISSED_SCANS_OPTION)}, array() );
    if ( ! is_array( $counts ) ) { $counts = array(); }
    foreach ( (array) json_decode( base64_decode( '${b64}' ), true ) as $key => $count ) {
      $counts[ (string) $key ] = (int) $count;
    }
    update_option( ${phpStringLiteral(MISSED_SCANS_OPTION)}, $counts, false );
  `);
}

function readTally(): Record<string, number> {
  const raw = wpEval(`echo wp_json_encode( get_option( ${phpStringLiteral(MISSED_SCANS_OPTION)}, array() ) );`);
  const parsed = raw ? (JSON.parse(raw) as unknown) : [];
  return Array.isArray(parsed) ? {} : (parsed as Record<string, number>);
}

/** Same canonical form as Scanner_Controller::canonical_key() — the contract under test. */
function canonicalKey(name: string, domain: string): string {
  const cName = name.trim().toLowerCase();
  const cDomain = domain
    .trim()
    .toLowerCase()
    .replace(/^\.+/, '')
    .replace(/:\d+$/, '');
  return `${cName}|${cDomain}`;
}

async function pickWritableCategoryId(page: Parameters<typeof openCookiesPage>[0], nonce: string): Promise<number> {
  const categories = await listCategories(page, nonce);
  const target = categories.find(
    (c: any) => c.slug && c.slug !== 'necessary' && c.slug !== 'wordpress-internal',
  );
  expect(target, 'a non-necessary category exists to host the test cookies').toBeTruthy();
  return Number(target.id ?? target.category_id);
}

async function createCookieRow(
  page: Parameters<typeof openCookiesPage>[0],
  nonce: string,
  categoryId: number,
  name: string,
  domain: string,
): Promise<number> {
  const created = await fazApiPost<any>(page, nonce, 'cookies', {
    name,
    category: categoryId,
    domain,
    description: { en: 'Release-verify stale-tally fixture cookie.' },
    duration: { en: 'session' },
  });
  expect(created.status, `cookie ${name} created`).toBeGreaterThanOrEqual(200);
  expect(created.status).toBeLessThan(300);
  const id = Number(created.data?.id ?? created.data?.cookie_id ?? 0);
  expect(id, `cookie ${name} has a persisted id`).toBeGreaterThan(0);
  return id;
}

/** Flip the `discovered` flag so record_scan_observations() judges the row. */
function markDiscovered(id: number): void {
  wpEval(`
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'faz_cookies', array( 'discovered' => 1 ), array( 'cookie_id' => ${Number(id)} ) );
  `);
  clearAllFazCookieCaches();
}

/** Best-effort row cleanup that works even after a partial test failure. */
function purgeCookieRowsByNames(names: string[]): void {
  if (names.length === 0) {
    return;
  }
  const b64 = Buffer.from(JSON.stringify(names), 'utf8').toString('base64');
  wpEval(`
    global $wpdb;
    foreach ( (array) json_decode( base64_decode( '${b64}' ), true ) as $name ) {
      $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}faz_cookies WHERE name = %s", (string) $name ) );
    }
  `);
  clearAllFazCookieCaches();
}

async function cookieNameExists(page: Parameters<typeof openCookiesPage>[0], nonce: string, name: string): Promise<boolean> {
  const rows = await listCookies(page, nonce);
  const lower = name.toLowerCase();
  return rows.some((row: any) => String(row?.name ?? '').toLowerCase() === lower);
}

async function bulkDelete(
  page: Parameters<typeof openCookiesPage>[0],
  nonce: string,
  ids: number[],
  reason?: string,
): Promise<BulkDeleteResponse> {
  const payload: Record<string, unknown> = { ids };
  if (reason !== undefined) {
    payload.reason = reason;
  }
  const response = await fazApiPost<BulkDeleteResponse>(page, nonce, 'cookies/bulk-delete', payload);
  expect(response.status, `bulk-delete (reason=${reason ?? 'none'}) succeeds at transport level`).toBe(200);
  return response.data;
}

/**
 * Establish a browser-scan capture session (scans/discover) and immediately
 * import a synthetic result for it — the same two-call sequence the admin
 * scanner UI performs, minus the crawling.
 */
async function importSyntheticScan(
  page: Parameters<typeof openCookiesPage>[0],
  nonce: string,
  payload: Record<string, unknown>,
): Promise<ImportResponse> {
  const scanId = makeScanId();
  const discover = await fazApiPost<any>(page, nonce, 'scans/discover', {
    max_pages: 1,
    fingerprint: '',
    scan_id: scanId,
  });
  expect(discover.status, 'scans/discover opens a capture session').toBe(200);
  const response = await fazApiPost<ImportResponse>(page, nonce, 'scans/import', { ...payload, scan_id: scanId });
  expect(response.status, 'scans/import accepted the payload').toBe(200);
  return response.data;
}

test.describe('Release verify: stale-tally safeguard on destructive deletion', () => {
  test('a stale-scoped purge refuses a row the tally has not earned, reports it, and leaves the row in place', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const name = makeToken('_faz_e2e_stale_refuse');
    const tallySnapshot = snapshotOption(MISSED_SCANS_OPTION);
    const binSnapshot = snapshotOption(RECYCLE_BIN_OPTION);
    let cookieId = 0;
    try {
      const categoryId = await pickWritableCategoryId(page, nonce);
      cookieId = await createCookieRow(page, nonce, categoryId, name, SITE_HOST);

      // Precondition capable of failing: the token is random, so its key must
      // not be in the earned tally. If it were, the refusal below is vacuous.
      expect(readTally()[canonicalKey(name, SITE_HOST)]).toBeUndefined();

      const result = await bulkDelete(page, nonce, [cookieId], 'stale');
      expect(result.deleted, 'nothing may be deleted on a single-scan claim').toBe(0);
      expect(result.refused, 'the refusal is reported, not silent').toBe(1);
      expect(result.restorable, 'no undo snapshot for a refused row').toBe(0);

      // The destructive action really did not happen.
      expect(await cookieNameExists(page, nonce, name), 'refused row survives in the catalogue').toBe(true);
    } finally {
      purgeCookieRowsByNames([name]);
      restoreOption(MISSED_SCANS_OPTION, tallySnapshot);
      restoreOption(RECYCLE_BIN_OPTION, binSnapshot);
      assertOptionRestored(MISSED_SCANS_OPTION, tallySnapshot);
      assertOptionRestored(RECYCLE_BIN_OPTION, binSnapshot);
    }
  });

  test('the id a stale purge refuses is still deletable through the unscoped admin path — the gate must not leak onto hand-selected deletes', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const name = makeToken('_faz_e2e_unscoped');
    const tallySnapshot = snapshotOption(MISSED_SCANS_OPTION);
    const binSnapshot = snapshotOption(RECYCLE_BIN_OPTION);
    try {
      const categoryId = await pickWritableCategoryId(page, nonce);
      const cookieId = await createCookieRow(page, nonce, categoryId, name, SITE_HOST);

      // First prove the stale gate refuses this very id...
      const scoped = await bulkDelete(page, nonce, [cookieId], 'stale');
      expect(scoped.deleted).toBe(0);
      expect(scoped.refused).toBe(1);
      expect(await cookieNameExists(page, nonce, name)).toBe(true);

      // ...then that the SAME id, without the reason, deletes normally. A
      // blanket gate here would strip admins of the ability to delete
      // hand-added / never-scanned rows — a regression, and this goes red.
      const unscoped = await bulkDelete(page, nonce, [cookieId]);
      expect(unscoped.deleted, 'unscoped multi-select delete still works').toBe(1);
      expect(unscoped.refused, 'no refusals outside the stale scope').toBe(0);
      expect(unscoped.restorable, 'unscoped delete is undoable').toBe(1);
      expect(await cookieNameExists(page, nonce, name), 'row is gone after the unscoped delete').toBe(false);
    } finally {
      purgeCookieRowsByNames([name]);
      restoreOption(MISSED_SCANS_OPTION, tallySnapshot);
      restoreOption(RECYCLE_BIN_OPTION, binSnapshot);
      assertOptionRestored(MISSED_SCANS_OPTION, tallySnapshot);
      assertOptionRestored(RECYCLE_BIN_OPTION, binSnapshot);
    }
  });

  test('a row missed by enough consecutive complete scans IS deleted by the stale purge — the earned list authorizes, not just refuses', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const name = makeToken('_faz_e2e_earned');
    const tallySnapshot = snapshotOption(MISSED_SCANS_OPTION);
    const binSnapshot = snapshotOption(RECYCLE_BIN_OPTION);
    try {
      const categoryId = await pickWritableCategoryId(page, nonce);
      const cookieId = await createCookieRow(page, nonce, categoryId, name, SITE_HOST);

      // Two consecutive complete misses = MISSED_SCANS_THRESHOLD.
      seedTally({ [canonicalKey(name, SITE_HOST)]: 2 });

      const result = await bulkDelete(page, nonce, [cookieId], 'stale');
      expect(result.deleted, 'an earned row is deleted by the scoped purge').toBe(1);
      expect(result.refused, 'no spurious refusal for an earned row').toBe(0);
      expect(result.restorable, 'the earned delete is undoable').toBe(1);
      expect(await cookieNameExists(page, nonce, name), 'earned row is gone from the catalogue').toBe(false);
    } finally {
      purgeCookieRowsByNames([name]);
      restoreOption(MISSED_SCANS_OPTION, tallySnapshot);
      restoreOption(RECYCLE_BIN_OPTION, binSnapshot);
      assertOptionRestored(MISSED_SCANS_OPTION, tallySnapshot);
      assertOptionRestored(RECYCLE_BIN_OPTION, binSnapshot);
    }
  });

  test('canonicalization is real: a legacy tally key with mixed case, a leading dot and a :port still authorizes a differently-cased row', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const token = makeToken('canon');
    // Row and tally deliberately share NO raw byte form: raw intersection is
    // empty, canonical intersection is exactly one key. Under the naive
    // wiring (server keyed raw name|domain) this delete is refused → red.
    const rowName = `_FAZ_E2E_${token.toUpperCase()}`;
    const rowDomain = 'example.com';
    const legacyTallyKey = `_faz_e2e_${token}|.EXAMPLE.COM:8443`;
    const tallySnapshot = snapshotOption(MISSED_SCANS_OPTION);
    const binSnapshot = snapshotOption(RECYCLE_BIN_OPTION);
    try {
      // Sanity on the fixture itself: the two forms must canonicalize to the
      // same key, or this test would test nothing.
      expect(canonicalKey(rowName, rowDomain)).toBe(canonicalKey(`_faz_e2e_${token}`, '.EXAMPLE.COM:8443'));

      const categoryId = await pickWritableCategoryId(page, nonce);
      const cookieId = await createCookieRow(page, nonce, categoryId, rowName, rowDomain);
      seedTally({ [legacyTallyKey]: 2 });

      const result = await bulkDelete(page, nonce, [cookieId], 'stale');
      expect(result.deleted, 'legacy-keyed tally entry intersects the canonical row key').toBe(1);
      expect(result.refused, 'canonical match means no refusal — zero here proves the two key builders agree').toBe(0);
      expect(await cookieNameExists(page, nonce, rowName)).toBe(false);
    } finally {
      purgeCookieRowsByNames([rowName]);
      restoreOption(MISSED_SCANS_OPTION, tallySnapshot);
      restoreOption(RECYCLE_BIN_OPTION, binSnapshot);
      assertOptionRestored(MISSED_SCANS_OPTION, tallySnapshot);
      assertOptionRestored(RECYCLE_BIN_OPTION, binSnapshot);
    }
  });

  test('a depth-capped scan import is not full-site evidence: scan_was_complete=false, the tally does not advance, and no deletion is offered', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const name = makeToken('_faz_e2e_depthcap');
    const key = canonicalKey(name, SITE_HOST);
    const tallySnapshot = snapshotOption(MISSED_SCANS_OPTION);
    try {
      resetScanState();
      const categoryId = await pickWritableCategoryId(page, nonce);
      const cookieId = await createCookieRow(page, nonce, categoryId, name, SITE_HOST);
      markDiscovered(cookieId);
      // One prior complete miss: one more would cross the threshold.
      seedTally({ [key]: 1 });

      // A 20-page-capped run that finished. It is a healthy run — but not a
      // full-site one, and its silence about our row is not evidence.
      const result = await importSyntheticScan(page, nonce, {
        cookies: [],
        scripts: [],
        pages_scanned: 2,
        metrics: { pagesScanned: 2, maxPages: 20, isFullScan: false },
      });

      expect(result.scan_was_complete, 'depth-capped run must not be reported complete').toBe(false);
      expect(Array.isArray(result.deletable_stale_keys), 'earned list travels on the import response').toBe(true);
      expect(result.deletable_stale_keys, 'no deletion offered off depth-capped evidence').not.toContain(key);
      // The behavioural core: the persisted tally did not move.
      expect(readTally()[key], 'tally must stay at 1 after an incomplete scan').toBe(1);
    } finally {
      purgeCookieRowsByNames([name]);
      restoreOption(MISSED_SCANS_OPTION, tallySnapshot);
      assertOptionRestored(MISSED_SCANS_OPTION, tallySnapshot);
      resetScanState();
    }
  });

  test('a full-depth complete scan that misses a discovered row advances its tally and, at the threshold, surfaces the key in deletable_stale_keys', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const missedName = makeToken('_faz_e2e_fullmiss');
    const observedName = makeToken('_faz_e2e_observed');
    const key = canonicalKey(missedName, SITE_HOST);
    const tallySnapshot = snapshotOption(MISSED_SCANS_OPTION);
    try {
      resetScanState();
      const categoryId = await pickWritableCategoryId(page, nonce);
      const cookieId = await createCookieRow(page, nonce, categoryId, missedName, SITE_HOST);
      markDiscovered(cookieId);
      seedTally({ [key]: 1 });

      // Full-depth (maxPages=0 + isFullScan), healthy, and the scan observes a
      // DIFFERENT cookie — so the seeded row accrues its second miss.
      const result = await importSyntheticScan(page, nonce, {
        cookies: [
          { name: observedName, domain: SITE_HOST, category: 'uncategorized', source: 'browser', duration: 'session' },
        ],
        scripts: [],
        pages_scanned: 1,
        // diagnosticsIssues is what the real engine sends and what the server
        // now requires: a run with timed-out iframes or a failed server-scan is
        // degraded, and degraded coverage must not advance the deletion tally.
        // Fail-closed on absence, so a healthy run has to say so explicitly.
        metrics: { pagesScanned: 1, maxPages: 0, isFullScan: true, diagnosticsIssues: 0 },
      });

      expect(result.scan_was_complete, 'full-depth healthy run counts as complete').toBe(true);
      expect(result.cookie_names, 'the observed cookie was persisted by the import').toContain(observedName);
      expect(readTally()[key], 'second consecutive complete miss recorded').toBe(2);
      expect(result.deletable_stale_keys, 'threshold reached → key earns deletability, in canonical form, over REST').toContain(key);

      // …and the same run, degraded, must NOT. The client gate has always
      // required diagnostics.totalIssues === 0 before offering the stale
      // action; the server could not test it because the number never crossed
      // the wire, so a crawl with timed-out iframes read here as full-site
      // evidence and advanced the tally toward deletion of cookies it simply
      // never reached.
      const degraded = await importSyntheticScan(page, nonce, {
        cookies: [],
        scripts: [],
        pages_scanned: 1,
        metrics: { pagesScanned: 1, maxPages: 0, isFullScan: true, diagnosticsIssues: 4 },
      });
      expect(
        degraded.scan_was_complete,
        'a full-depth run that hit capture issues is NOT complete coverage — it must not earn deletability',
      ).toBe(false);
    } finally {
      purgeCookieRowsByNames([missedName, observedName]);
      restoreOption(MISSED_SCANS_OPTION, tallySnapshot);
      assertOptionRestored(MISSED_SCANS_OPTION, tallySnapshot);
      resetScanState();
    }
  });

  test('a mixed stale batch is gated per row: the earned id is deleted, the unearned id is refused and survives', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const earnedName = makeToken('_faz_e2e_mix_earned');
    const freshName = makeToken('_faz_e2e_mix_fresh');
    const tallySnapshot = snapshotOption(MISSED_SCANS_OPTION);
    const binSnapshot = snapshotOption(RECYCLE_BIN_OPTION);
    try {
      const categoryId = await pickWritableCategoryId(page, nonce);
      const earnedId = await createCookieRow(page, nonce, categoryId, earnedName, SITE_HOST);
      const freshId = await createCookieRow(page, nonce, categoryId, freshName, SITE_HOST);
      seedTally({ [canonicalKey(earnedName, SITE_HOST)]: 2 });

      const result = await bulkDelete(page, nonce, [earnedId, freshId], 'stale');
      expect(result.deleted, 'exactly the earned row is deleted').toBe(1);
      expect(result.refused, 'exactly the unearned row is refused').toBe(1);
      expect(result.restorable, 'only the deleted row is snapshotted for undo').toBe(1);

      expect(await cookieNameExists(page, nonce, earnedName), 'earned row gone').toBe(false);
      expect(await cookieNameExists(page, nonce, freshName), 'unearned row survives the same batch').toBe(true);
    } finally {
      purgeCookieRowsByNames([earnedName, freshName]);
      restoreOption(MISSED_SCANS_OPTION, tallySnapshot);
      restoreOption(RECYCLE_BIN_OPTION, binSnapshot);
      assertOptionRestored(MISSED_SCANS_OPTION, tallySnapshot);
      assertOptionRestored(RECYCLE_BIN_OPTION, binSnapshot);
    }
  });
});
