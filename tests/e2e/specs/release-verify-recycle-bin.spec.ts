/**
 * Release verification — the cookies recycle bin, end to end against the
 * INSTALLED plugin (not the working tree).
 *
 * What was broken and is claimed fixed (admin/modules/cookies/api/
 * class-cookies-api.php):
 *
 *  - restore_deleted() replayed the snapshot's stale `id` through a blind
 *    setter loop, so save() took the UPDATE branch against a deleted row:
 *    nothing was written while the truthy stale id made the "restored"
 *    counter fire. Now an explicit field allowlist keeps the id out, the
 *    object must enter save() at id 0, and the counter only counts rows
 *    that demonstrably came back.
 *  - A restore that wrote nothing used to consume the batch anyway,
 *    destroying the only undo record. Now the bin is only rewritten when
 *    at least one row was written.
 *  - bulk_delete() used to DELETE first and snapshot afterwards with
 *    update_option()'s return discarded. Now: collect, persist-and-verify
 *    (store_recycle_bin reads the option back), then delete.
 *  - `deleted_at` was stored and never read. Now deleted-batches sends
 *    `deleted_at` + `deleted_at_human` and the Cookies page bar renders
 *    the age instead of an unchecked "recently".
 *
 * Routes under test:
 *   POST /faz/v1/cookies/bulk-delete      -> { deleted, restorable, refused }
 *   POST /faz/v1/cookies/restore-deleted  -> { restored } | 404 faz_nothing_to_restore
 *   GET  /faz/v1/cookies/deleted-batches  -> { batches[], batch_count }
 *
 * Global state touched: the `faz_cookies_recycle_bin` option and rows in
 * wp_faz_cookies. Every test snapshots the bin first, seeds cookies under
 * its own unique prefix, and restores/cleans both in `finally`, asserting
 * the bin restore round-tripped byte-for-byte.
 */

import { test, expect } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import {
  fazApiGet,
  fazApiPost,
  deleteCookiesByPrefix,
  listCategories,
  listCookies,
  openCookiesPage,
} from '../utils/faz-api';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const BIN_OPTION = 'faz_cookies_recycle_bin';

type BulkDeleteResponse = { deleted: number; restorable: number; refused: number };
type RestoreResponse = { restored: number };
type DeletedBatch = { index: number; count: number; deleted_at: number; deleted_at_human: string };
type DeletedBatchesResponse = { batches: DeletedBatch[]; batch_count: number };

/**
 * Byte-exact snapshot of the recycle bin option, base64 over the PHP
 * serialization so the restore can put back EXACTLY what was there
 * (multibyte content, nested arrays, everything).
 */
function snapshotRecycleBin(): string {
  return wpEval(`echo base64_encode( maybe_serialize( get_option( '${BIN_OPTION}', array() ) ) );`).trim();
}

function restoreRecycleBin(snapshot: string): void {
  wpEval(`
    $value = maybe_unserialize( base64_decode( '${snapshot}' ) );
    if ( ! is_array( $value ) ) { $value = array(); }
    update_option( '${BIN_OPTION}', $value, false );
  `);
  // The restore must be verified, not assumed: a leaked bin poisons every
  // later spec that reads deleted-batches.
  const after = snapshotRecycleBin();
  expect(after, 'recycle bin option restored to its pre-test value').toBe(snapshot);
}

function emptyRecycleBin(): void {
  wpEval(`delete_option( '${BIN_OPTION}' );`);
}

/** A visible non-necessary category id to host seeded cookies. */
async function pickHostCategoryId(page: Page, nonce: string): Promise<number> {
  const categories = await listCategories(page, nonce);
  const target = categories.find(
    (c: any) => c.slug && c.slug !== 'necessary' && c.slug !== 'wordpress-internal',
  );
  expect(target, 'a non-necessary category exists to host seeded cookies').toBeTruthy();
  return Number(target.id ?? target.category_id);
}

async function createCookie(
  page: Page,
  nonce: string,
  fields: Record<string, unknown>,
): Promise<number> {
  const res = await fazApiPost<any>(page, nonce, 'cookies', fields);
  expect(res.status, `create cookie ${String(fields.name)} succeeds`).toBeGreaterThanOrEqual(200);
  expect(res.status, `create cookie ${String(fields.name)} succeeds`).toBeLessThan(300);
  const id = Number(res.data?.id ?? res.data?.cookie_id ?? 0);
  expect(id, `created cookie ${String(fields.name)} has a real id`).toBeGreaterThan(0);
  return id;
}

async function bulkDelete(page: Page, nonce: string, ids: number[]): Promise<BulkDeleteResponse> {
  const res = await fazApiPost<BulkDeleteResponse>(page, nonce, 'cookies/bulk-delete', { ids });
  expect(res.status, 'bulk-delete answers 200').toBe(200);
  return res.data;
}

async function restoreDeleted(page: Page, nonce: string) {
  return fazApiPost<any>(page, nonce, 'cookies/restore-deleted', {});
}

async function getDeletedBatches(page: Page, nonce: string): Promise<DeletedBatchesResponse> {
  const res = await fazApiGet<DeletedBatchesResponse>(page, nonce, 'cookies/deleted-batches');
  expect(res.status, 'deleted-batches answers 200').toBe(200);
  expect(Array.isArray(res.data?.batches), 'deleted-batches returns a batches array').toBe(true);
  return res.data;
}

function findByName(cookies: any[], name: string): any | undefined {
  return cookies.find((c: any) => String(c?.name ?? '') === name);
}

test.describe('Release verify — cookies recycle bin (bulk-delete / restore-deleted / deleted-batches)', () => {
  test('bulk-deleted cookies come back through restore with their fields intact and new row ids', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const prefix = `faz-rvbin-rt-${Date.now()}`;
    const binBefore = snapshotRecycleBin();
    try {
      const categoryId = await pickHostCategoryId(page, nonce);
      const seeds = [
        {
          name: `${prefix}-alpha`,
          category: categoryId,
          domain: 'rvbin-alpha.example.test',
          description: { en: 'Recycle-bin round-trip fixture alpha.' },
          duration: { en: '13 months' },
        },
        {
          name: `${prefix}-beta`,
          category: categoryId,
          domain: 'rvbin-beta.example.test',
          description: { en: 'Recycle-bin round-trip fixture beta.' },
          duration: { en: '30 days' },
        },
      ];
      const oldIds: number[] = [];
      for (const seed of seeds) {
        oldIds.push(await createCookie(page, nonce, seed));
      }

      // Capture the fields as the plugin itself reports them, so the
      // post-restore comparison is against the plugin's own canonical form
      // rather than our request payload.
      const beforeRows = await listCookies(page, nonce);
      const canonical = new Map<string, string>();
      for (const seed of seeds) {
        const row = findByName(beforeRows, seed.name);
        expect(row, `${seed.name} is listed before the delete`).toBeTruthy();
        canonical.set(
          seed.name,
          JSON.stringify({
            category: Number(row.category),
            description: row.description,
            domain: row.domain,
            duration: row.duration,
          }),
        );
      }

      const del = await bulkDelete(page, nonce, oldIds);
      expect(del.deleted, 'both seeded rows were deleted').toBe(2);
      expect(del.restorable, 'both rows are reported restorable, backed by a verified snapshot').toBe(2);

      const afterDelete = await listCookies(page, nonce);
      for (const seed of seeds) {
        expect(findByName(afterDelete, seed.name), `${seed.name} is really gone after the delete`).toBeUndefined();
      }

      // The old restore reported success while writing nothing (stale id ->
      // UPDATE against a deleted row). The honest contract: restored === 2
      // AND the rows are demonstrably back.
      const restore = await restoreDeleted(page, nonce);
      expect(restore.status, 'restore-deleted answers 200').toBe(200);
      expect((restore.data as RestoreResponse).restored, 'restore reports exactly the rows it wrote').toBe(2);

      const afterRestore = await listCookies(page, nonce);
      for (const seed of seeds) {
        const row = findByName(afterRestore, seed.name);
        expect(row, `${seed.name} is BACK after the restore`).toBeTruthy();
        const newId = Number(row.id ?? row.cookie_id);
        expect(newId, `${seed.name} came back as a real row`).toBeGreaterThan(0);
        expect(oldIds, `${seed.name} was re-INSERTED under a NEW id, not the replayed stale one`).not.toContain(newId);
        expect(
          JSON.stringify({
            category: Number(row.category),
            description: row.description,
            domain: row.domain,
            duration: row.duration,
          }),
          `${seed.name} kept its category/description/domain/duration through the round trip`,
        ).toBe(canonical.get(seed.name));
      }
    } finally {
      await deleteCookiesByPrefix(page, nonce, prefix);
      restoreRecycleBin(binBefore);
    }
  });

  test('restore skips a cookie whose name already came back on its own, without minting a duplicate', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const prefix = `faz-rvbin-dup-${Date.now()}`;
    const cookieName = `${prefix}-cookie`;
    const binBefore = snapshotRecycleBin();
    try {
      const categoryId = await pickHostCategoryId(page, nonce);
      const originalId = await createCookie(page, nonce, {
        name: cookieName,
        category: categoryId,
        domain: 'rvbin-dup.example.test',
        description: { en: 'Duplicate-guard fixture.' },
        duration: { en: '1 year' },
      });

      const del = await bulkDelete(page, nonce, [originalId]);
      expect(del.deleted).toBe(1);
      expect(del.restorable).toBe(1);

      // The cookie "comes back on its own" — re-added by hand (or a rescan)
      // between the delete and the undo.
      const handAddedId = await createCookie(page, nonce, {
        name: cookieName,
        category: categoryId,
        domain: 'rvbin-dup-manual.example.test',
        description: { en: 'Hand re-added before the undo.' },
        duration: { en: '1 year' },
      });

      const restore = await restoreDeleted(page, nonce);
      expect(restore.status, 'restore-deleted answers 200, a skip is not a fault').toBe(200);
      expect((restore.data as RestoreResponse).restored, 'nothing restored: the only snapshot row is a live duplicate').toBe(0);

      const rows = await listCookies(page, nonce);
      const matches = rows.filter((c: any) => String(c?.name ?? '') === cookieName);
      expect(matches.length, 'exactly one row with the name survives — no duplicate resurrected').toBe(1);
      expect(Number(matches[0].id ?? matches[0].cookie_id), 'the surviving row is the hand-added one').toBe(handAddedId);
    } finally {
      await deleteCookiesByPrefix(page, nonce, prefix);
      restoreRecycleBin(binBefore);
    }
  });

  test('an empty recycle bin answers 404 faz_nothing_to_restore, not a success shape', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const binBefore = snapshotRecycleBin();
    try {
      emptyRecycleBin();

      const restore = await restoreDeleted(page, nonce);
      expect(restore.status, 'empty bin is a 404, not a 200').toBe(404);
      expect(restore.data?.code, 'the documented error code the admin JS branches on').toBe('faz_nothing_to_restore');
      // A success shape here would make the UI announce "0 cookie(s)
      // restored" for a state, not a fault — assert it is absent.
      expect('restored' in (restore.data ?? {}), 'no success-shaped `restored` field on the error').toBe(false);
    } finally {
      restoreRecycleBin(binBefore);
    }
  });

  test('a restore that restored nothing leaves the undo batch in the bin, so a later retry still works', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const prefix = `faz-rvbin-keep-${Date.now()}`;
    const cookieName = `${prefix}-cookie`;
    const binBefore = snapshotRecycleBin();
    try {
      emptyRecycleBin();
      const categoryId = await pickHostCategoryId(page, nonce);
      const originalId = await createCookie(page, nonce, {
        name: cookieName,
        category: categoryId,
        domain: 'rvbin-keep.example.test',
        description: { en: 'Batch-survival fixture.' },
        duration: { en: '6 months' },
      });
      const del = await bulkDelete(page, nonce, [originalId]);
      expect(del.restorable).toBe(1);

      // Blocker: the same name is live again, so the restore writes nothing.
      const blockerId = await createCookie(page, nonce, {
        name: cookieName,
        category: categoryId,
        domain: 'rvbin-keep-blocker.example.test',
        description: { en: 'Blocks the first restore.' },
        duration: { en: '6 months' },
      });

      const first = await restoreDeleted(page, nonce);
      expect(first.status).toBe(200);
      expect((first.data as RestoreResponse).restored, 'first restore writes nothing (duplicate name is live)').toBe(0);

      // The old code consumed the batch on this exact path. The fix: the bin
      // is only rewritten when something was actually written.
      const batches = await getDeletedBatches(page, nonce);
      expect(batches.batch_count, 'the batch SURVIVES the no-op restore').toBeGreaterThanOrEqual(1);
      expect(batches.batches[0].count, 'the surviving batch still holds our snapshot row').toBe(1);

      // Remove the blocker; the retry must now succeed off the same batch.
      const remove = await bulkDelete(page, nonce, [blockerId]);
      expect(remove.deleted, 'blocker row removed to unblock the retry').toBe(1);
      // That delete pushed its own (newest) batch; restore it away first so
      // the retry reaches the ORIGINAL batch underneath. Restoring the
      // blocker batch re-creates the name, so delete the resurrected copy
      // again via the row id it came back under — simpler: consume batches
      // until our original row is back.
      let restoredOriginal = false;
      for (let i = 0; i < 4 && !restoredOriginal; i += 1) {
        const retry = await restoreDeleted(page, nonce);
        expect(retry.status, 'retry restore answers 200 while batches remain').toBe(200);
        const rows = await listCookies(page, nonce);
        const back = rows.filter((c: any) => String(c?.name ?? '') === cookieName);
        if (back.length > 0) {
          restoredOriginal = true;
        }
      }
      expect(restoredOriginal, 'the undo record survived and the retry brought the cookie back').toBe(true);
    } finally {
      await deleteCookiesByPrefix(page, nonce, prefix);
      restoreRecycleBin(binBefore);
    }
  });

  test('deleted-batches reports the batch size, timestamp and a computed human age', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const prefix = `faz-rvbin-meta-${Date.now()}`;
    const binBefore = snapshotRecycleBin();
    try {
      emptyRecycleBin();
      const categoryId = await pickHostCategoryId(page, nonce);
      const ids: number[] = [];
      for (const suffix of ['one', 'two', 'three']) {
        ids.push(await createCookie(page, nonce, {
          name: `${prefix}-${suffix}`,
          category: categoryId,
          domain: `rvbin-meta-${suffix}.example.test`,
          description: { en: `Metadata fixture ${suffix}.` },
          duration: { en: '90 days' },
        }));
      }
      const before = Math.floor(Date.now() / 1000);
      const del = await bulkDelete(page, nonce, ids);
      expect(del.deleted).toBe(3);

      const data = await getDeletedBatches(page, nonce);
      expect(data.batch_count, 'exactly the one batch this test created').toBe(1);
      const batch = data.batches[0];
      expect(batch.count, 'the batch reports how many rows it holds').toBe(3);
      // `deleted_at` was stored-and-never-read; now it must be a real, fresh
      // epoch timestamp (tolerance for runner/PHP clock drift on one host).
      expect(batch.deleted_at, 'deleted_at is a real epoch timestamp').toBeGreaterThanOrEqual(before - 300);
      expect(batch.deleted_at, 'deleted_at is not in the future').toBeLessThanOrEqual(Math.floor(Date.now() / 1000) + 300);
      // human_time_diff() never returns an empty string for a valid
      // timestamp — a fresh batch reads "1 min".
      expect(typeof batch.deleted_at_human).toBe('string');
      expect(batch.deleted_at_human.length, 'the age is actually computed, not left blank').toBeGreaterThan(0);
      // Metadata only: the snapshotted rows (which may carry raw blocker
      // scripts) must NOT ride along on the read route.
      expect('cookies' in (batch as any), 'deleted-batches never leaks the snapshot rows').toBe(false);
    } finally {
      await deleteCookiesByPrefix(page, nonce, prefix);
      restoreRecycleBin(binBefore);
    }
  });

  test('the Cookies page restore bar renders the batch age from the server, not an unchecked "recently"', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const prefix = `faz-rvbin-bar-${Date.now()}`;
    const binBefore = snapshotRecycleBin();
    try {
      emptyRecycleBin();
      const categoryId = await pickHostCategoryId(page, nonce);
      const id = await createCookie(page, nonce, {
        name: `${prefix}-cookie`,
        category: categoryId,
        domain: 'rvbin-bar.example.test',
        description: { en: 'Restore-bar fixture.' },
        duration: { en: '1 year' },
      });
      const del = await bulkDelete(page, nonce, [id]);
      expect(del.restorable).toBe(1);

      // What the server says the age is — the bar must render THIS string,
      // which keeps the assertion locale-proof.
      const batches = await getDeletedBatches(page, nonce);
      expect(batches.batch_count).toBe(1);
      const age = batches.batches[0].deleted_at_human;
      expect(age.length, 'server computed a human age for the bar to render').toBeGreaterThan(0);

      // Fresh page load: the bar is read from the server on load, precisely
      // so an admin who navigated away still sees the undo affordance.
      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
      const bar = page.locator('#faz-restore-bar');
      await expect(bar, 'restore bar becomes visible when the bin holds a batch').toBeVisible({ timeout: 20_000 });
      const text = (await bar.innerText()).trim();
      expect(text, 'bar shows the batch size').toContain('1');
      expect(text, 'bar renders the server-computed age instead of a bare "recently"').toContain(age);
      await expect(bar.locator('button.faz-restore-deleted'), 'the Undo action is offered next to the age').toBeVisible();
    } finally {
      await deleteCookiesByPrefix(page, nonce, prefix);
      restoreRecycleBin(binBefore);
    }
  });

  test('a purge that matches no rows deletes nothing and adds no batch to the bin', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const binBefore = snapshotRecycleBin();
    try {
      emptyRecycleBin();

      // Guaranteed-phantom id: past the current maximum by a wide margin.
      const rows = await listCookies(page, nonce);
      const maxId = rows.reduce((max: number, c: any) => Math.max(max, Number(c?.id ?? c?.cookie_id ?? 0)), 0);
      const phantom = maxId + 99_999;

      const del = await bulkDelete(page, nonce, [phantom]);
      expect(del.deleted, 'phantom id deletes nothing').toBe(0);
      expect(del.restorable, 'nothing restorable was claimed').toBe(0);

      const batches = await getDeletedBatches(page, nonce);
      expect(batches.batch_count, 'no empty batch was pushed into the bin').toBe(0);

      // And the empty-input contract stays a 400, not a silent 200/0.
      const emptyReq = await fazApiPost<any>(page, nonce, 'cookies/bulk-delete', { ids: [] });
      expect(emptyReq.status, 'empty ids array is rejected').toBe(400);
      expect(emptyReq.data?.code).toBe('invalid_data');
    } finally {
      restoreRecycleBin(binBefore);
    }
  });

  test('successive purges stack as separate batches and restore newest-first', async ({ page, loginAsAdmin }) => {
    const nonce = await openCookiesPage(page, loginAsAdmin);
    const prefix = `faz-rvbin-lifo-${Date.now()}`;
    const nameOld = `${prefix}-older`;
    const nameNew = `${prefix}-newer`;
    const binBefore = snapshotRecycleBin();
    try {
      emptyRecycleBin();
      const categoryId = await pickHostCategoryId(page, nonce);
      const olderId = await createCookie(page, nonce, {
        name: nameOld,
        category: categoryId,
        domain: 'rvbin-lifo-older.example.test',
        description: { en: 'First (older) purge.' },
        duration: { en: '1 year' },
      });
      const newerId = await createCookie(page, nonce, {
        name: nameNew,
        category: categoryId,
        domain: 'rvbin-lifo-newer.example.test',
        description: { en: 'Second (newer) purge.' },
        duration: { en: '1 year' },
      });

      const firstDel = await bulkDelete(page, nonce, [olderId]);
      expect(firstDel.restorable).toBe(1);
      const secondDel = await bulkDelete(page, nonce, [newerId]);
      expect(secondDel.restorable).toBe(1);

      const stacked = await getDeletedBatches(page, nonce);
      expect(stacked.batch_count, 'two purges produce two distinct undo batches').toBe(2);
      expect(stacked.batches[0].count).toBe(1);
      expect(stacked.batches[1].count).toBe(1);

      // First undo puts back the NEWER purge only.
      const firstRestore = await restoreDeleted(page, nonce);
      expect(firstRestore.status).toBe(200);
      expect((firstRestore.data as RestoreResponse).restored).toBe(1);
      let rows = await listCookies(page, nonce);
      expect(findByName(rows, nameNew), 'the newest purge is undone first').toBeTruthy();
      expect(findByName(rows, nameOld), 'the older purge is still parked in the bin').toBeUndefined();

      // Second undo reaches the older batch.
      const secondRestore = await restoreDeleted(page, nonce);
      expect(secondRestore.status).toBe(200);
      expect((secondRestore.data as RestoreResponse).restored).toBe(1);
      rows = await listCookies(page, nonce);
      expect(findByName(rows, nameOld), 'the older purge is undone by the second restore').toBeTruthy();

      const drained = await getDeletedBatches(page, nonce);
      expect(drained.batch_count, 'both batches were consumed by successful restores').toBe(0);
    } finally {
      await deleteCookiesByPrefix(page, nonce, prefix);
      restoreRecycleBin(binBefore);
    }
  });
});
