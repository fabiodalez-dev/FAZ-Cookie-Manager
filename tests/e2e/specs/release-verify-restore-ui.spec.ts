/**
 * Release verification — the recycle-bin restore UI and the jar-only
 * disclosure bar, exercised against the INSTALLED release build.
 *
 * Finding under verification: "the safety net exists server-side and no UI
 * calls it" — every bulk delete snapshots the removed rows into a bounded
 * recycle bin (`faz_cookies_recycle_bin`, 3 batches), but historically
 * nothing in the product could reach `POST cookies/restore-deleted`.
 * The claimed fix is a readable route (`GET cookies/deleted-batches`) plus
 * a persistent bar (`#faz-restore-bar`, modelled on the stale bar — NOT a
 * toast) in the Cookies admin page, rebuilt from server state on every page
 * load. Companion finding: "the scanner withholds names correctly then
 * never tells anyone" — jar-only cookie names are now disclosed in
 * `#faz-jar-bar` after a scan, rendered as text (never markup).
 *
 * Server contract read from production source:
 *  - admin/modules/cookies/api/class-cookies-api.php
 *      bulk_delete()        → snapshots BEFORE deleting, returns
 *                             { deleted, restorable, refused }
 *      get_deleted_batches()→ { batches: [{ index, count, deleted_at,
 *                             deleted_at_human }], batch_count }
 *      restore_deleted()    → { restored } on success; 404 with
 *                             code=faz_nothing_to_restore on an empty bin
 *  - admin/assets/js/pages/cookies.js
 *      updateRestoreBar()   → runs on DOMContentLoaded AND after a bulk
 *                             delete; reads cookies/deleted-batches
 *      updateJarOnlyBar()   → renders #faz-jar-bar from
 *                             importResult.jar_only_cookies via textContent
 *  - admin/views/cookies.php → #faz-restore-bar / #faz-jar-bar markup
 *
 * These tests assert against the running site (DOM + REST of the deployed
 * plugin), never against repo source text.
 */

import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import {
  deleteCookiesByPrefix,
  fazApiGet,
  fazApiPost,
  findCategoryId,
  listCookies,
  listCookiesUntil,
  openCookiesPage,
} from '../utils/faz-api';
import { WP_PATH, wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const BIN_OPTION = 'faz_cookies_recycle_bin';
const ABSENT = 'ABSENT';

type DeletedBatches = {
  batches: Array<{ index: number; count: number; deleted_at: number; deleted_at_human: string }>;
  batch_count: number;
};

type BulkDeleteResult = { deleted: number; restorable: number; refused: number };

/**
 * Read the recycle-bin option as an opaque, comparable token.
 * 'ABSENT' when the option row does not exist at all.
 */
function readBinRaw(): string {
  return wpEval(`
    $v = get_option( '${BIN_OPTION}', '__FAZ_E2E_ABSENT__' );
    if ( '__FAZ_E2E_ABSENT__' === $v ) { echo '${ABSENT}'; }
    else { echo base64_encode( (string) wp_json_encode( $v ) ); }
  `).trim();
}

/** Put the recycle bin back exactly as `readBinRaw()` captured it. */
function writeBinRaw(raw: string): void {
  if (raw === ABSENT) {
    wpEval(`delete_option( '${BIN_OPTION}' ); echo 'ok';`);
    return;
  }
  wpEval(`
    $v = json_decode( base64_decode( '${JSON.stringify(raw).slice(1, -1)}' ), true );
    update_option( '${BIN_OPTION}', is_array( $v ) ? $v : array(), false );
    echo 'ok';
  `);
}

/** Restore the bin and PROVE the restore landed — a silent miss here poisons later specs. */
function restoreBinAndAssert(snapshot: string): void {
  writeBinRaw(snapshot);
  expect(readBinRaw()).toBe(snapshot);
}

async function getDeletedBatches(page: Page, nonce: string): Promise<DeletedBatches> {
  const response = await fazApiGet<DeletedBatches>(page, nonce, 'cookies/deleted-batches');
  expect(response.status).toBe(200);
  expect(Array.isArray(response.data.batches)).toBe(true);
  return response.data;
}

/**
 * Seed `count` cookie rows through the deployed REST API and return their ids.
 * Names are `${prefix}-a`, `${prefix}-b`, … — unique per test run so cleanup
 * by prefix can never touch anyone else's rows.
 */
async function seedCookies(page: Page, nonce: string, prefix: string, count: number): Promise<{ ids: number[]; names: string[] }> {
  const categoryId = await findCategoryId(page, nonce, 'analytics');
  const names: string[] = [];
  for (let i = 0; i < count; i += 1) {
    const name = `${prefix}-${String.fromCharCode(97 + i)}`;
    const created = await fazApiPost<any>(page, nonce, 'cookies', {
      category: categoryId,
      description: { en: 'Release-verify restore-UI seed cookie' },
      discovered: false,
      domain: '127.0.0.1',
      duration: { en: '1 year' },
      name,
      slug: name,
      type: 0,
      url_pattern: '',
    });
    expect([200, 201]).toContain(created.status);
    names.push(name);
  }
  // Resolve ids from the authoritative list rather than trusting the create
  // response shape — the helpers already normalise id vs cookie_id.
  const all = await listCookies(page, nonce);
  const ids = names.map((name) => {
    const row = all.find((cookie: any) => String(cookie?.name ?? '') === name);
    expect(row, `seeded cookie "${name}" must be listed by GET cookies`).toBeTruthy();
    return Number(row.id ?? row.cookie_id);
  });
  ids.forEach((id) => expect(id).toBeGreaterThan(0));
  return { ids, names };
}

async function gotoCookiesPage(page: Page): Promise<void> {
  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
}

test.describe('Release verify — recycle-bin restore UI', () => {
  test.setTimeout(150_000);

  test('a bulk delete performed in the Cookies page immediately offers a persistent Undo bar, not a toast', async ({ page, loginAsAdmin }) => {
    test.skip(!WP_PATH, 'requires WP_PATH to snapshot/restore the recycle-bin option via wp-cli');
    const binSnapshot = readBinRaw();
    const prefix = `faz-e2e-restoreui-${Date.now()}`;
    const nonce = await openCookiesPage(page, loginAsAdmin);
    try {
      const seeded = await seedCookies(page, nonce, prefix, 2);

      // Render the seeded rows, then drive the delete through the real UI:
      // row checkboxes → bulk bar → Delete Selected → FAZ.confirm modal.
      await page.reload({ waitUntil: 'domcontentloaded' });
      for (const id of seeded.ids) {
        const checkbox = page.locator(`.faz-cookie-check[value="${id}"]`);
        await expect(checkbox).toBeVisible({ timeout: 20_000 });
        await checkbox.check();
      }
      await expect(page.locator('#faz-bulk-bar')).toBeVisible();
      await expect(page.locator('#faz-bulk-bar .faz-bulk-count')).toHaveText('2 selected');
      await page.click('#faz-bulk-delete-btn');
      await page.locator('.faz-modal-backdrop button.faz-btn-danger', { hasText: 'Confirm' }).click();

      // The affordance must appear NOW, in the same page, without any reload
      // — and it is a bar in the document flow, not a FAZ.notify toast.
      const bar = page.locator('#faz-restore-bar');
      await expect(bar).toBeVisible({ timeout: 20_000 });
      await expect(bar).toContainText(/2 (recently )?deleted cookie\(s\) can still be restored/);
      await expect(bar.locator('button.faz-restore-deleted')).toHaveText('Undo delete');

      // And the rows really left the table (the delete was not cosmetic).
      for (const id of seeded.ids) {
        await expect(page.locator(`.faz-cookie-check[value="${id}"]`)).toHaveCount(0, { timeout: 20_000 });
      }
      const remaining = await listCookies(page, nonce);
      for (const name of seeded.names) {
        expect(remaining.some((cookie: any) => String(cookie?.name ?? '') === name)).toBe(false);
      }
    } finally {
      restoreBinAndAssert(binSnapshot);
      await deleteCookiesByPrefix(page, nonce, prefix);
    }
  });

  test('the Undo affordance is rebuilt from server state and survives a full page reload', async ({ page, loginAsAdmin }) => {
    test.skip(!WP_PATH, 'requires WP_PATH to snapshot/restore the recycle-bin option via wp-cli');
    const binSnapshot = readBinRaw();
    const prefix = `faz-e2e-restorepersist-${Date.now()}`;
    const nonce = await openCookiesPage(page, loginAsAdmin);
    try {
      const seeded = await seedCookies(page, nonce, prefix, 2);
      const deleted = await fazApiPost<BulkDeleteResult>(page, nonce, 'cookies/bulk-delete', { ids: seeded.ids });
      expect(deleted.status).toBe(200);
      expect(deleted.data.deleted).toBe(2);
      // `restorable` is the server's promise that a VERIFIED snapshot exists.
      expect(deleted.data.restorable).toBe(2);

      // A completely fresh navigation — no in-page state can carry over, so a
      // visible bar here proves it is rebuilt from GET cookies/deleted-batches.
      await gotoCookiesPage(page);
      const bar = page.locator('#faz-restore-bar');
      await expect(bar).toBeVisible({ timeout: 20_000 });
      await expect(bar).toContainText(/2 (recently )?deleted cookie\(s\) can still be restored/);

      // This is the property a toast-only undo would fail: reload again and
      // the affordance must STILL be there.
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(bar).toBeVisible({ timeout: 20_000 });
      await expect(bar).toContainText(/2 (recently )?deleted cookie\(s\) can still be restored/);
      await expect(bar.locator('button.faz-restore-deleted')).toBeVisible();

      // The read route itself reports the batch, newest first, with an age.
      const batches = await getDeletedBatches(page, nonce);
      expect(batches.batch_count).toBeGreaterThan(0);
      expect(batches.batches[0].count).toBe(2);
      expect(batches.batches[0].deleted_at).toBeGreaterThan(0);
      expect(batches.batches[0].deleted_at_human.length).toBeGreaterThan(0);
    } finally {
      restoreBinAndAssert(binSnapshot);
      await deleteCookiesByPrefix(page, nonce, prefix);
    }
  });

  test('clicking Undo actually restores the deleted rows — they reappear in the table and the batch is consumed', async ({ page, loginAsAdmin }) => {
    test.skip(!WP_PATH, 'requires WP_PATH to snapshot/restore the recycle-bin option via wp-cli');
    const binSnapshot = readBinRaw();
    const prefix = `faz-e2e-restoreclick-${Date.now()}`;
    const nonce = await openCookiesPage(page, loginAsAdmin);
    try {
      const seeded = await seedCookies(page, nonce, prefix, 2);
      const deleted = await fazApiPost<BulkDeleteResult>(page, nonce, 'cookies/bulk-delete', { ids: seeded.ids });
      expect(deleted.status).toBe(200);
      expect(deleted.data.restorable).toBe(2);
      const batchesBefore = await getDeletedBatches(page, nonce);

      await gotoCookiesPage(page);
      const undo = page.locator('#faz-restore-bar button.faz-restore-deleted');
      await expect(undo).toBeVisible({ timeout: 20_000 });
      await undo.click();

      // Success is announced…
      await expect(page.locator('.faz-toast.faz-toast-success', { hasText: '2 cookie(s) restored.' }))
        .toBeVisible({ timeout: 20_000 });

      // …and, more importantly, the rows are really back: in the refreshed
      // table AND through the REST list (poll — the table refetches async).
      const restored = await listCookiesUntil(page, nonce, seeded.names, 30_000);
      expect(restored.length).toBeGreaterThanOrEqual(2);
      for (const name of seeded.names) {
        await expect(
          page.locator('#faz-cookies-tbody strong', { hasText: name }).first()
        ).toBeVisible({ timeout: 20_000 });
      }

      // The consumed batch must leave the bin — otherwise a second click
      // would "restore" duplicates forever.
      const batchesAfter = await getDeletedBatches(page, nonce);
      expect(batchesAfter.batch_count).toBe(batchesBefore.batch_count - 1);
    } finally {
      restoreBinAndAssert(binSnapshot);
      await deleteCookiesByPrefix(page, nonce, prefix);
    }
  });

  test('an empty recycle bin hides the affordance, and a restore attempt yields a friendly message — never a raw error', async ({ page, loginAsAdmin }) => {
    test.skip(!WP_PATH, 'requires WP_PATH to empty and restore the recycle-bin option via wp-cli');
    const binSnapshot = readBinRaw();
    const prefix = `faz-e2e-restoreempty-${Date.now()}`;
    const nonce = await openCookiesPage(page, loginAsAdmin);
    try {
      // Arrange a bar on screen backed by a real batch…
      const seeded = await seedCookies(page, nonce, prefix, 1);
      const deleted = await fazApiPost<BulkDeleteResult>(page, nonce, 'cookies/bulk-delete', { ids: seeded.ids });
      expect(deleted.status).toBe(200);
      expect(deleted.data.restorable).toBe(1);
      await gotoCookiesPage(page);
      const bar = page.locator('#faz-restore-bar');
      await expect(bar).toBeVisible({ timeout: 20_000 });

      // …then empty the bin behind the page's back (another tab / another
      // admin already restored — the exact race the friendly path exists for).
      writeBinRaw(ABSENT);

      // The REST contract for the empty bin: a STRUCTURED 404, not a 500.
      const batches = await getDeletedBatches(page, nonce);
      expect(batches.batch_count).toBe(0);
      const attempt = await fazApiPost<any>(page, nonce, 'cookies/restore-deleted', {});
      expect(attempt.status).toBe(404);
      expect(attempt.data?.code).toBe('faz_nothing_to_restore');
      expect(String(attempt.data?.message ?? '')).toMatch(/restore/i);

      // The UI path: clicking the now-stale Undo surfaces a calm, translated
      // sentence (info toast) — and the bar then withdraws itself.
      await bar.locator('button.faz-restore-deleted').click();
      await expect(page.locator('.faz-toast.faz-toast-info', { hasText: 'There is nothing left to restore.' }))
        .toBeVisible({ timeout: 20_000 });
      await expect(bar).toBeHidden({ timeout: 20_000 });

      // And on a fresh load with an empty bin the affordance never appears.
      // The bar starts hidden in the markup, so asserting absence is only
      // meaningful AFTER the page's own deleted-batches read has settled —
      // otherwise this would pass vacuously while the fetch is in flight.
      const batchesRead = page.waitForResponse(
        (resp) => resp.url().includes('deleted-batches'),
        { timeout: 30_000 }
      );
      await page.reload({ waitUntil: 'domcontentloaded' });
      const readResp = await batchesRead;
      expect(readResp.status()).toBe(200);
      await expect(page.locator('#faz-cookies-tbody tr').first()).toBeVisible({ timeout: 20_000 });
      await expect(bar).toBeHidden();
      await expect(page.locator('#faz-restore-bar button.faz-restore-deleted')).toHaveCount(0);
    } finally {
      restoreBinAndAssert(binSnapshot);
      await deleteCookiesByPrefix(page, nonce, prefix);
    }
  });
});

test.describe('Release verify — jar-only disclosure after a scan', () => {
  // A real in-browser crawl of the site: discover + 10 pages + import.
  test.setTimeout(300_000);

  test('withheld jar-only cookies are disclosed after a scan, names rendered as text and never as markup', async ({ page, loginAsAdmin }) => {
    // A markup-shaped name a hostile page could plant in the visitor jar.
    // Chromium accepts it (verified against this very site): if any layer
    // renders it via innerHTML a <b> element materialises inside the bar.
    const hostileName = '<b>faz-e2e-jarxss</b>';
    const disclosedFragment = 'faz-e2e-jarxss';

    const nonce = await openCookiesPage(page, loginAsAdmin);

    // Plant the hostile cookie in the browser jar BEFORE the scan starts, so
    // the engine's jar-baseline classifies it as unattributable (jar-only).
    await page.evaluate((name) => {
      document.cookie = `${name}=1; path=/`;
    }, hostileName);

    // Force a full (non-incremental) crawl, mirroring scan-progress.spec.ts.
    await page.evaluate(() => {
      try { localStorage.removeItem('faz_scan_fingerprint'); } catch (_e) { /* no-op */ }
    });

    // Quick scan (10 pages) through the real dropdown.
    await page.click('#faz-scan-btn');
    await page.click('.faz-dropdown-item[data-depth="10"]');
    await expect(page.locator('.faz-scan-status')).toBeVisible({ timeout: 10_000 });
    await page.waitForFunction(
      () => !document.querySelector('.faz-scan-progress-wrap'),
      undefined,
      { timeout: 240_000 }
    );

    // The disclosure bar must be up: the scan ran in a logged-in browser, so
    // the jar necessarily held at least our seeded cookie when it started.
    const jarBar = page.locator('#faz-jar-bar');
    await expect(jarBar).toBeVisible({ timeout: 30_000 });
    await expect(jarBar).toContainText(/\d+ cookie\(s\) were already in your browser when the scan started/);

    // Collapsed by default, disclosed on demand.
    const details = jarBar.locator('details.faz-jar-details');
    await expect(details.locator('summary')).toHaveText('Show the names');
    await details.locator('summary').click();

    // The withheld name is DISCLOSED — the whole point of the fix —
    // and it is disclosed as text.
    await expect(details.locator('li', { hasText: disclosedFragment }).first()).toBeVisible();

    // The hostile shape was neutralised end-to-end: no element was minted
    // from it anywhere inside the bar, by either the server echo or the
    // client renderer.
    await expect(jarBar.locator('b, i, img, svg, script')).toHaveCount(0);
    const barHtml = await jarBar.evaluate((el) => el.innerHTML);
    expect(barHtml).not.toContain('<b>');

    // And the withholding itself still holds: the jar-only name was never
    // imported into the public declaration under either its raw or its
    // sanitised spelling.
    const declared = await listCookies(page, nonce);
    const declaredNames = declared.map((cookie: any) => String(cookie?.name ?? ''));
    expect(declaredNames).not.toContain(hostileName);
    expect(declaredNames).not.toContain(disclosedFragment);

    // Hygiene: expire the seeded jar cookie (the context is per-test, but a
    // retry reuses the same origin storage on some runners — be explicit).
    await page.evaluate((name) => {
      document.cookie = `${name}=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
    }, hostileName);
  });
});
