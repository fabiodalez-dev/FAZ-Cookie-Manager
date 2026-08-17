import type { Browser } from '@playwright/test';
import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import {
  fazApiDelete,
  fazApiGet,
  fazApiPost,
  findCategoryId,
  openSettingsPage,
} from '../utils/faz-api';

/**
 * Release verification — server-side cookie shredder (installed ZIP, running site).
 *
 * The shredder's classification was widened (admin catalogue + Known_Providers,
 * with Cookie_Database/OCD behind the faz_shred_uses_cookie_database filter)
 * while its DELETION authority was narrowed. These tests pin the five load-
 * bearing properties of that trade against the live install:
 *
 *  - `uncategorized` fails permissive (frontend/class-frontend.php,
 *    is_cookie_allowed(): '' | necessary | wordpress-internal | uncategorized
 *    all return true — the gooloo.de / commit ad72cd3 incident);
 *  - the internal-cookie allowlist (is_wp_internal_cookie → wpdiscuz_nonce_*,
 *    _litespeed_*, lscache_vary…) is shared with enforcement via
 *    is_always_allowed_cookie_name(), so a cookie the plugin refuses to SHOW
 *    can never be DELETED;
 *  - a cookie catalogued into a genuinely blocked category is still shredded
 *    pre-consent — the narrowing must not have gutted enforcement;
 *  - the whole layer is gated on the script_blocking.block_server_cookies
 *    opt-in (server_cookie_guard_enabled()) and does nothing when off;
 *  - script_blocking.whitelist_patterns entries are honoured verbatim as
 *    cookie-NAME patterns (compute_whitelisted_cookie_patterns()), the
 *    supported remedy for a false positive.
 *
 * Every test seeds real browser cookies in a fresh context and lets the
 * template_redirect shredder answer with real Set-Cookie deletion headers —
 * nothing here asserts source text.
 */

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const COOKIE_HOST = new URL(WP_BASE).hostname;
const PROBE_VALUE = 'faz-release-verify-probe';

type FazSettings = Record<string, any>;

/**
 * Read the full settings snapshot, hard-failing on transport errors and on
 * WP_Error-shaped bodies. A silent failure here is the worst possible outcome:
 * the finally block would "restore" a WP_Error object over the live settings.
 */
async function readSettingsSnapshot(page: Page, nonce: string): Promise<FazSettings> {
  const read = await fazApiGet<FazSettings>(page, nonce, 'settings');
  expect(read.status, 'settings GET failed — refusing to proceed without a snapshot').toBe(200);
  expect(read.data, 'settings GET returned no body').toBeTruthy();
  expect(typeof read.data).toBe('object');
  expect(
    (read.data as FazSettings).code,
    'settings GET returned a WP_Error, not a settings snapshot',
  ).toBeUndefined();
  return read.data;
}

async function applyScriptBlocking(page: Page, nonce: string, scriptBlocking: Record<string, unknown>): Promise<void> {
  const applied = await fazApiPost(page, nonce, 'settings', { script_blocking: scriptBlocking });
  expect(applied.status, 'script_blocking settings write was not applied').toBe(200);
}

/**
 * Restore in a finally block: soft on purpose — a hard throw here would
 * replace the real failure from the try block — but still turns the test red
 * when the site is left mutated, which is exactly what we want surfaced.
 */
async function restoreScriptBlocking(page: Page, nonce: string, scriptBlocking: unknown): Promise<void> {
  const restored = await fazApiPost(page, nonce, 'settings', {
    script_blocking: scriptBlocking as Record<string, unknown>,
  });
  expect
    .soft(restored.status, 'script_blocking was NOT restored — later specs run against a mutated site')
    .toBe(200);
}

/** Create an admin-catalogue cookie row and return its id (hard-fails on error). */
async function createCatalogueRow(page: Page, nonce: string, name: string, categoryId: number): Promise<number> {
  const created = await fazApiPost<Record<string, unknown>>(page, nonce, 'cookies', {
    name,
    slug: name,
    domain: COOKIE_HOST,
    category: categoryId,
    duration: { en: 'session' },
    description: { en: 'release-verify shredder probe row' },
  });
  expect([200, 201], `catalogue row "${name}" was not created (status ${created.status})`).toContain(created.status);
  const id = Number((created.data as Record<string, unknown>)?.id ?? 0);
  expect(id, `catalogue row "${name}" returned no id: ${JSON.stringify(created.data)}`).toBeGreaterThan(0);
  return id;
}

/** Delete a catalogue row in cleanup. Soft: must not mask the try-block failure. */
async function removeCatalogueRow(page: Page, nonce: string, id: number): Promise<void> {
  if (!id) {
    return;
  }
  const deleted = await fazApiDelete(page, nonce, `cookies/${id}`);
  expect
    .soft(deleted.status, `catalogue row ${id} was NOT removed — it leaks into every later spec`)
    .toBeLessThan(300);
}

/** The banner bootstrap in the served HTML — proof a consent context exists. */
const hasBannerAssets = (html: string): boolean =>
  /faz-cookie-manager\/frontend\/js\/script(\.min)?\.js/.test(html);

/**
 * Seed the given cookie names into a brand-new context (no consent cookie →
 * pre-consent visitor), fetch the front page over RAW HTTP, and report which
 * cookies survived the server's response.
 *
 * Deliberately NOT a page load. `script.js` carries its own client-side
 * sweeper (_fazDeleteCookie over the category map, script.js:5780ff) which
 * deletes any catalogued cookie in a blocked category on EVERY visit — it is
 * the plugin's core consent enforcement, is reversible, and is entirely
 * independent of the script_blocking.block_server_cookies opt-in this file is
 * about. A browser-based probe therefore cannot tell the two layers apart: it
 * reports "deleted" even when the server sent nothing at all, and would
 * indict the server guard for the client's work. An APIRequestContext runs no
 * JavaScript while still applying Set-Cookie to the shared cookie jar, so what
 * remains here is exactly what the template_redirect shredder decided.
 *
 * The banner bootstrap is asserted present first: the shredder only arms when
 * a consent context exists (server_cookie_guard_has_consent_context()), so a
 * page without it would make every "survived" assertion vacuous.
 */
async function survivorsAfterFreshVisit(browser: Browser, names: string[]): Promise<Set<string>> {
  const context = await browser.newContext();
  try {
    await context.addCookies(
      names.map((name) => ({ name, value: PROBE_VALUE, domain: COOKIE_HOST, path: '/' })),
    );
    const response = await context.request.get(`${WP_BASE}/?faz_relverify=${Date.now()}`);
    expect(response.status(), 'front page did not answer — the shredder was never reached').toBe(200);
    expect(
      hasBannerAssets(await response.text()),
      'consent banner bootstrap absent for the fresh visitor — no consent context, shredder never armed',
    ).toBe(true);
    const cookies = await context.cookies(WP_BASE);
    return new Set(cookies.map((cookie) => cookie.name));
  } finally {
    await context.close();
  }
}

test.describe('release verify — server-side cookie shredder', () => {
  test('an uncategorized catalogue entry survives pre-consent — unknown never means deletable', async ({ page, browser, loginAsAdmin }) => {
    test.setTimeout(120_000);
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const snapshot = await readSettingsSnapshot(page, nonce);
    let uncatRowId = 0;
    let controlRowId = 0;
    try {
      const uncategorizedId = await findCategoryId(page, nonce, 'uncategorized');
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      uncatRowId = await createCatalogueRow(page, nonce, 'faz_relverify_uncat', uncategorizedId);
      controlRowId = await createCatalogueRow(page, nonce, 'faz_relverify_uncat_ctrl', analyticsId);
      await applyScriptBlocking(page, nonce, {
        ...(snapshot.script_blocking ?? {}),
        block_server_cookies: true,
      });

      const survivors = await survivorsAfterFreshVisit(browser, [
        'faz_relverify_uncat',
        'faz_relverify_uncat_ctrl',
      ]);

      // Control first: if the analytics row was NOT shredded, the guard never
      // ran and the survival assertion below would pass for the wrong reason.
      expect(
        survivors.has('faz_relverify_uncat_ctrl'),
        'control failure: the catalogued analytics cookie was not shredded, so this test cannot distinguish "uncategorized is safe" from "the shredder never ran"',
      ).toBe(false);
      expect(
        survivors.has('faz_relverify_uncat'),
        'REGRESSION: a cookie catalogued as uncategorized was deleted pre-consent — "I do not know what this is" means "delete it" again (gooloo.de / commit ad72cd3)',
      ).toBe(true);
    } finally {
      await restoreScriptBlocking(page, nonce, snapshot.script_blocking);
      await removeCatalogueRow(page, nonce, uncatRowId);
      await removeCatalogueRow(page, nonce, controlRowId);
    }
  });

  test('the internal-cookie allowlist shields wpdiscuz_nonce_* even when catalogued into a blocked category', async ({ page, browser, loginAsAdmin }) => {
    test.setTimeout(120_000);
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const snapshot = await readSettingsSnapshot(page, nonce);
    let wpdiscuzRowId = 0;
    let controlRowId = 0;
    try {
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      // Worst-case setup: an admin (or a bad import) catalogues the nonce
      // cookie into a category that is blocked pre-consent. The shared
      // allowlist (is_always_allowed_cookie_name → is_wp_internal_cookie)
      // must still win over that classification.
      wpdiscuzRowId = await createCatalogueRow(page, nonce, 'wpdiscuz_nonce_fazprobe', analyticsId);
      controlRowId = await createCatalogueRow(page, nonce, 'faz_relverify_wpd_ctrl', analyticsId);
      await applyScriptBlocking(page, nonce, {
        ...(snapshot.script_blocking ?? {}),
        block_server_cookies: true,
      });

      const survivors = await survivorsAfterFreshVisit(browser, [
        'wpdiscuz_nonce_fazprobe',
        'faz_relverify_wpd_ctrl',
      ]);

      expect(
        survivors.has('faz_relverify_wpd_ctrl'),
        'control failure: the sibling analytics cookie was not shredded, so the wpdiscuz survival below proves nothing about the allowlist',
      ).toBe(false);
      expect(
        survivors.has('wpdiscuz_nonce_fazprobe'),
        'REGRESSION: a wpdiscuz_nonce_* cookie was shredded despite the internal-cookie allowlist — comment CSRF nonces would 403 again (the exact gooloo.de failure)',
      ).toBe(true);
    } finally {
      await restoreScriptBlocking(page, nonce, snapshot.script_blocking);
      await removeCatalogueRow(page, nonce, wpdiscuzRowId);
      await removeCatalogueRow(page, nonce, controlRowId);
    }
  });

  test('a catalogued analytics cookie is still shredded pre-consent while unknown bystanders survive', async ({ page, browser, loginAsAdmin }) => {
    test.setTimeout(120_000);
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const snapshot = await readSettingsSnapshot(page, nonce);
    let trackerRowId = 0;
    try {
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      trackerRowId = await createCatalogueRow(page, nonce, 'faz_relverify_tracker', analyticsId);
      await applyScriptBlocking(page, nonce, {
        ...(snapshot.script_blocking ?? {}),
        block_server_cookies: true,
      });

      // faz_relverify_bystander exists in NO tier — not catalogued, not in
      // Known_Providers, not in the OCD. It must fall through every
      // classifier to the fail-permissive default and survive.
      const survivors = await survivorsAfterFreshVisit(browser, [
        'faz_relverify_tracker',
        'faz_relverify_bystander',
      ]);

      expect(
        survivors.has('faz_relverify_tracker'),
        'REGRESSION: the narrowing gutted enforcement — a cookie the admin catalogued into a blocked category survived pre-consent',
      ).toBe(false);
      expect(
        survivors.has('faz_relverify_bystander'),
        'REGRESSION: a completely unknown cookie was deleted — the shredder is destroying names no classifier recognises',
      ).toBe(true);
    } finally {
      await restoreScriptBlocking(page, nonce, snapshot.script_blocking);
      await removeCatalogueRow(page, nonce, trackerRowId);
    }
  });

  test('with block_server_cookies off the shredder deletes nothing, even catalogued trackers', async ({ page, browser, loginAsAdmin }) => {
    test.setTimeout(120_000);
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const snapshot = await readSettingsSnapshot(page, nonce);
    let trackerRowId = 0;
    try {
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      trackerRowId = await createCatalogueRow(page, nonce, 'faz_relverify_optout', analyticsId);
      // Explicitly OFF — the shipped default. If a leaky earlier spec left it
      // on, this write re-establishes the state under test either way.
      await applyScriptBlocking(page, nonce, {
        ...(snapshot.script_blocking ?? {}),
        block_server_cookies: false,
      });

      const survivors = await survivorsAfterFreshVisit(browser, ['faz_relverify_optout']);

      expect(
        survivors.has('faz_relverify_optout'),
        'REGRESSION: the server-side shredder deleted a cookie while block_server_cookies was OFF — the opt-in gate is not being honoured and upgrades would turn destructive enforcement on underneath operators',
      ).toBe(true);
    } finally {
      await restoreScriptBlocking(page, nonce, snapshot.script_blocking);
      await removeCatalogueRow(page, nonce, trackerRowId);
    }
  });

  test('an admin whitelist entry naming a cookie exempts exactly that cookie from shredding', async ({ page, browser, loginAsAdmin }) => {
    test.setTimeout(120_000);
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const snapshot = await readSettingsSnapshot(page, nonce);
    let whitelistedRowId = 0;
    let siblingRowId = 0;
    try {
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      whitelistedRowId = await createCatalogueRow(page, nonce, 'faz_relverify_wl', analyticsId);
      siblingRowId = await createCatalogueRow(page, nonce, 'faz_relverify_wl_sib', analyticsId);
      const originalPatterns = Array.isArray(snapshot.script_blocking?.whitelist_patterns)
        ? snapshot.script_blocking.whitelist_patterns
        : [];
      await applyScriptBlocking(page, nonce, {
        ...(snapshot.script_blocking ?? {}),
        block_server_cookies: true,
        // The verbatim cookie-NAME remedy: compute_whitelisted_cookie_patterns()
        // emits each entry as a name pattern, so an admin can rescue a false
        // positive by exact name without touching the catalogue.
        whitelist_patterns: [...originalPatterns, 'faz_relverify_wl'],
      });

      const survivors = await survivorsAfterFreshVisit(browser, [
        'faz_relverify_wl',
        'faz_relverify_wl_sib',
      ]);

      expect(
        survivors.has('faz_relverify_wl_sib'),
        'control failure: the non-whitelisted sibling was not shredded, so the whitelist assertion below cannot tell an exemption from a dead shredder',
      ).toBe(false);
      expect(
        survivors.has('faz_relverify_wl'),
        'REGRESSION: the whitelist_patterns entry naming this cookie did not exempt it — operators have lost the supported remedy for shredder false positives',
      ).toBe(true);
    } finally {
      await restoreScriptBlocking(page, nonce, snapshot.script_blocking);
      await removeCatalogueRow(page, nonce, whitelistedRowId);
      await removeCatalogueRow(page, nonce, siblingRowId);
    }
  });

  test('Open Cookie Database classification alone cannot authorise deletion', async ({ page, browser, loginAsAdmin }) => {
    test.setTimeout(120_000);
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const snapshot = await readSettingsSnapshot(page, nonce);
    let controlRowId = 0;
    try {
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      controlRowId = await createCatalogueRow(page, nonce, 'faz_relverify_ocd_ctrl', analyticsId);
      await applyScriptBlocking(page, nonce, {
        ...(snapshot.script_blocking ?? {}),
        block_server_cookies: true,
      });

      // ablyft_exps is an exact-name Analytics entry in the bundled
      // open-cookie-database.json (provider ABlyft), absent from both the
      // admin catalogue and Known_Providers. With the
      // faz_shred_uses_cookie_database filter at its default (false) the
      // enforceable classifier must return '' for it and let it live.
      const survivors = await survivorsAfterFreshVisit(browser, [
        'ablyft_exps',
        'faz_relverify_ocd_ctrl',
      ]);

      expect(
        survivors.has('faz_relverify_ocd_ctrl'),
        'control failure: the catalogued analytics cookie was not shredded, so ablyft_exps surviving proves nothing about the OCD gate',
      ).toBe(false);
      expect(
        survivors.has('ablyft_exps'),
        'REGRESSION: a cookie known ONLY to the Open Cookie Database was deleted — the 6,754-entry third-party dataset is authorising destruction again without the opt-in filter',
      ).toBe(true);
    } finally {
      await restoreScriptBlocking(page, nonce, snapshot.script_blocking);
      await removeCatalogueRow(page, nonce, controlRowId);
    }
  });

  test('a consented visitor keeps their catalogued analytics cookie — enforcement follows consent', async ({ page, browser, loginAsAdmin }) => {
    test.setTimeout(120_000);
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const snapshot = await readSettingsSnapshot(page, nonce);
    let trackerRowId = 0;
    try {
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      trackerRowId = await createCatalogueRow(page, nonce, 'faz_relverify_consented', analyticsId);
      await applyScriptBlocking(page, nonce, {
        ...(snapshot.script_blocking ?? {}),
        block_server_cookies: true,
      });

      const context = await browser.newContext();
      try {
        const visitor = await context.newPage();
        await visitor.goto(`${WP_BASE}/?faz_relverify=${Date.now()}`, { waitUntil: 'domcontentloaded' });
        const acceptBtn = visitor.locator('[data-faz-tag="accept-button"]').first();
        await expect(acceptBtn, 'banner did not render — cannot record consent').toBeVisible({ timeout: 15_000 });
        await acceptBtn.click();
        await visitor.waitForFunction(
          () => document.cookie.includes('fazcookie-consent='),
          { timeout: 10_000 },
        );

        // The visitor now legitimately holds an analytics cookie.
        await context.addCookies([
          { name: 'faz_relverify_consented', value: PROBE_VALUE, domain: COOKIE_HOST, path: '/' },
        ]);
        await visitor.goto(`${WP_BASE}/?faz_relverify=after-${Date.now()}`, { waitUntil: 'domcontentloaded' });

        const cookieNames = new Set((await context.cookies(WP_BASE)).map((cookie) => cookie.name));
        expect(
          cookieNames.has('fazcookie-consent'),
          'consent cookie disappeared across the reload — the accept never stuck, so the survival below is meaningless',
        ).toBe(true);
        expect(
          cookieNames.has('faz_relverify_consented'),
          'REGRESSION: a CONSENTED visitor had their analytics cookie shredded — the guard is enforcing against consent (the cache-compat-style always-blocked failure mode)',
        ).toBe(true);
      } finally {
        await context.close();
      }
    } finally {
      await restoreScriptBlocking(page, nonce, snapshot.script_blocking);
      await removeCatalogueRow(page, nonce, trackerRowId);
    }
  });

  test('recategorising a catalogued cookie to necessary immediately stops it being shredded', async ({ page, browser, loginAsAdmin }) => {
    test.setTimeout(150_000);
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const snapshot = await readSettingsSnapshot(page, nonce);
    let rowId = 0;
    try {
      const analyticsId = await findCategoryId(page, nonce, 'analytics');
      const necessaryId = await findCategoryId(page, nonce, 'necessary');
      rowId = await createCatalogueRow(page, nonce, 'faz_relverify_reclass', analyticsId);
      await applyScriptBlocking(page, nonce, {
        ...(snapshot.script_blocking ?? {}),
        block_server_cookies: true,
      });

      // Phase 1 — catalogued as analytics: shredded pre-consent. This is the
      // control that proves phase 2 measures the recategorisation and not a
      // shredder that never ran.
      const beforeReclass = await survivorsAfterFreshVisit(browser, ['faz_relverify_reclass']);
      expect(
        beforeReclass.has('faz_relverify_reclass'),
        'control failure: the analytics-catalogued cookie was not shredded, so phase 2 cannot show the recategorisation changed anything',
      ).toBe(false);

      // Phase 2 — the admin corrects the classification to `necessary`. The
      // enforcement map must pick this up NOW (faz_after_update_cookie busts
      // the faz_server_cookie_category_map_v2 transient), not an hour later.
      const updated = await fazApiPost(page, nonce, `cookies/${rowId}`, {
        name: 'faz_relverify_reclass',
        slug: 'faz_relverify_reclass',
        domain: COOKIE_HOST,
        category: necessaryId,
        duration: { en: 'session' },
        description: { en: 'release-verify shredder probe row (reclassified)' },
      });
      expect(updated.status, 'recategorisation write failed — phase 2 would retest phase 1').toBe(200);

      const afterReclass = await survivorsAfterFreshVisit(browser, ['faz_relverify_reclass']);
      expect(
        afterReclass.has('faz_relverify_reclass'),
        'REGRESSION: a cookie the admin recategorised to `necessary` was still shredded — either necessary is not exempt or the enforcement map is serving a stale classification',
      ).toBe(true);
    } finally {
      await restoreScriptBlocking(page, nonce, snapshot.script_blocking);
      await removeCatalogueRow(page, nonce, rowId);
    }
  });
});
