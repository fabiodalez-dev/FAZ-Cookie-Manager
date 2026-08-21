/**
 * Release verification — admin surfaces and cross-cutting fixes.
 *
 * Four separate findings, two tests each, all exercised against the INSTALLED
 * release through the running site (admin UI, HTTP front end, and the site's
 * own stored state via WP-CLI) — never against repo source text.
 *
 *  1. Expiry clamp notice (banner admin, General tab)
 *     - the `data-template` attribute used to carry the PHP source indentation
 *       into the rendered notice (multi-line echo inside the attribute);
 *     - when the runtime bounds a stored value the editor stayed silent: a
 *       banner storing 30 days under CCPA displayed 30 while serving 365.
 *     Contract read from admin/views/banner.php (faz-b-expiry / faz-b-expiry-hint,
 *     data-template written by esc_attr__ on the attribute's own line) and
 *     admin/assets/js/pages/banner.js::syncConsentExpiryConstraints() — clamp
 *     path on law change (data-notice="clamp"), runtime stored-vs-served
 *     disclosure on field change (data-notice="runtime"), CCPA bounds 365..3650,
 *     GDPR/Both bounds 1..182, and withdrawal when the numbers agree.
 *
 *  2. System Status table (Recently Blocked Server Cookies)
 *     Four data columns used to render with NO header row on CSS built for
 *     two-column label:value tables. admin/views/system-status.php now gives
 *     that one table `faz-status-table-data` plus a <thead> (Cookie / Category /
 *     Request Path / Blocked At); the sibling label:value tables must NOT have
 *     gained a header row.
 *
 *  3. Request path retention
 *     Frontend::record_blocked_server_cookies() (frontend/class-frontend.php)
 *     now stores `substr( strtok( REQUEST_URI, '?' ), 0, 255 )` — the PATH only —
 *     in the 24h `faz_recent_blocked_server_cookies` transient, because query
 *     strings carry personal data (?email=, order_key, reset keys) and the
 *     diagnostic is written by anonymous visitors and rendered in admin.
 *     Observable surface: the faz-e2e-scan-lab fixture's admin-ajax endpoint
 *     (action=faz_e2e_scan_ajax_cookie) emits `brikpanel_vid`. The guard does
 *     not consult Cookie_Database, so each test seeds the analytics catalogue
 *     row it needs and removes it afterwards (same discipline as
 *     release-verify-guard-standdown.spec.ts).
 *
 *  4. Region map single source (issue #238)
 *     Frontend::is_country_in_regions() and AMP_Consent::country_in_regions()
 *     were documented mirrors that had drifted (the AMP copy missed 'za').
 *     Both now delegate to faz_country_in_regions() over the one table in
 *     faz_region_map() (includes/class-utils.php), whose 'eu' preset
 *     deliberately EXCLUDES GB (UK GDPR has its own 'uk' bucket) and includes
 *     the EEA (IS/LI/NO). Exercised over live HTTP via the geo gate
 *     (Frontend::is_geo_banner_disabled + the faz-e2e-audit-lab fixture's
 *     ?faz_e2e_trust_cf=1&faz_e2e_cf_country=XX CF-header injection) and, for
 *     the AMP twin that has no HTTP surface without the AMP plugin, via
 *     reflection on the installed classes.
 */
import type { Browser, Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { fazApiDelete, fazApiGet, fazApiPost, findCategoryId, listCookies, openSettingsPage } from '../utils/faz-api';
import { ensureFixturePlugin, listActivePluginFiles, restoreActivePluginFiles, WP_PATH, wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const ANON_UA = 'Mozilla/5.0 (faz-release-verify-admin-misc)';
const BLOCKED_TRANSIENT = 'faz_recent_blocked_server_cookies';
const AJAX_PATH = '/wp-admin/admin-ajax.php';
/** Sentinel meaning "the transient did not exist" in snapshots. */
const ABSENT = '__faz_e2e_absent__';
/** Marker that must NEVER survive into the stored diagnostic. */
const QUERY_MARKER = 'FAZPATHLEAK';
const GUARD_COOKIE = 'brikpanel_vid';

type FazSettings = Record<string, any>;
type BlockedEntry = { name: string; category: string; request: string; blocked_at: number };

// ── WP-CLI transient snapshot/restore ────────────────────────────────────────

function snapshotTransient(name: string): string {
  return wpEval(`$v = get_transient( '${name}' ); echo wp_json_encode( false === $v ? '${ABSENT}' : $v );`);
}

function restoreTransient(name: string, snapshotJson: string): void {
  const parsed = JSON.parse(snapshotJson) as unknown;
  if (parsed === ABSENT) {
    wpEval(`delete_transient( '${name}' );`);
    return;
  }
  const b64 = Buffer.from(snapshotJson, 'utf8').toString('base64');
  wpEval(`set_transient( '${name}', json_decode( base64_decode( '${b64}' ), true ), DAY_IN_SECONDS );`);
}

/** Soft on purpose: thrown from finally it would mask the real failure. */
function assertTransientRestored(name: string, snapshotJson: string): void {
  const now = snapshotTransient(name);
  expect.soft(JSON.parse(now), `transient ${name} restored to its pre-test value`).toEqual(JSON.parse(snapshotJson));
}

function seedBlockedEntries(entries: BlockedEntry[]): void {
  const b64 = Buffer.from(JSON.stringify(entries), 'utf8').toString('base64');
  wpEval(`set_transient( '${BLOCKED_TRANSIENT}', json_decode( base64_decode( '${b64}' ), true ), DAY_IN_SECONDS );`);
}

function readBlockedEntries(): BlockedEntry[] {
  const raw = wpEval(`$v = get_transient( '${BLOCKED_TRANSIENT}' ); echo wp_json_encode( is_array( $v ) ? array_values( $v ) : array() );`);
  const parsed = JSON.parse(raw || '[]') as unknown;
  return Array.isArray(parsed) ? (parsed as BlockedEntry[]) : [];
}

// ── REST settings snapshot/apply/restore (guard-standdown discipline) ────────

async function snapshotSettings(page: Page, nonce: string): Promise<FazSettings> {
  const read = await fazApiGet<FazSettings>(page, nonce, 'settings');
  expect(read.status, 'settings GET must succeed before this test may mutate anything').toBe(200);
  const settings = read.data;
  expect(settings, 'settings GET returned no body').toBeTruthy();
  expect(settings.code, 'settings GET returned a WP_Error, not a settings snapshot').toBeUndefined();
  return settings;
}

async function applySettings(page: Page, nonce: string, payload: Record<string, unknown>): Promise<void> {
  const applied = await fazApiPost(page, nonce, 'settings', payload);
  expect(applied.status, 'the test configuration was not applied — every assertion below would be meaningless').toBe(200);
}

/** Restore exactly the groups the caller mutated. Soft: see assertTransientRestored. */
async function restoreSettingsGroups(page: Page, nonce: string, original: FazSettings, groups: string[]): Promise<void> {
  const payload: Record<string, unknown> = {};
  for (const group of groups) {
    payload[group] = original[group];
  }
  const restored = await fazApiPost(page, nonce, 'settings', payload);
  expect.soft(restored.status, `settings groups [${groups.join(', ')}] were NOT restored — later specs run against a mutated site`).toBe(200);
}

// ── Banner admin page ────────────────────────────────────────────────────────

async function openBannerGeneralTab(page: Page, loginAsAdmin: (page: Page) => Promise<void>): Promise<void> {
  await loginAsAdmin(page);
  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  // Wait for populateSettings to fill the form — the fields exist before the
  // banner data arrives, and interacting earlier gets overwritten.
  await page.waitForFunction(() => {
    const el = document.getElementById('faz-b-type') as HTMLSelectElement | null;
    return !!el && el.value !== '';
  }, { timeout: 15_000 });
  await page.click('#faz-banner-tabs button[data-tab="general"]');
  await expect(page.locator('#faz-b-expiry')).toBeVisible();
}

async function setExpiryValue(page: Page, value: string): Promise<void> {
  await page.fill('#faz-b-expiry', value);
  // fill() does not blur; the notice logic listens on 'change'.
  await page.locator('#faz-b-expiry').dispatchEvent('change');
}

// ── Anonymous HTTP probes ────────────────────────────────────────────────────

async function anonFrontHtml(browser: Browser, path: string): Promise<string> {
  const ctx = await browser.newContext({ userAgent: ANON_UA });
  try {
    const res = await ctx.request.get(`${WP_BASE}${path}`, { headers: { 'User-Agent': ANON_UA } });
    expect(res.status(), `front-end GET ${path}`).toBeLessThan(400);
    return await res.text();
  } finally {
    await ctx.close();
  }
}

const hasBannerAssets = (html: string): boolean => /faz-cookie-manager\/frontend\/js\/script(\.min)?\.js/.test(html);

/**
 * Trigger a REAL server-side cookie block as an anonymous visitor whose
 * request carries a personal-data-shaped query string. The scan-lab fixture
 * emits `brikpanel_vid` (analytics) from admin-ajax; with the guard opted in
 * the header is stripped and the block is recorded. The echoed cookie name is
 * the positive anchor that makes every negative assertion capable of failing.
 */
async function probeBlockedCookieWithQueryString(browser: Browser): Promise<{ setCookie: string; emitted: string }> {
  const ctx = await browser.newContext({ userAgent: ANON_UA });
  try {
    const query = `action=faz_e2e_scan_ajax_cookie&probe_email=leak%40example.com&order_key=wc_order_${QUERY_MARKER}`;
    const res = await ctx.request.get(`${WP_BASE}${AJAX_PATH}?${query}`);
    expect(res.status(), 'admin-ajax fixture endpoint must answer 200 — anything else means faz-e2e-scan-lab is not active').toBe(200);
    const body = await res.json();
    expect(body?.success, 'fixture AJAX handler must report success').toBe(true);
    return {
      setCookie: res.headers()['set-cookie'] ?? '',
      emitted: String(body?.data?.cookie ?? ''),
    };
  } finally {
    await ctx.close();
  }
}

/** Enable the Set-Cookie guard in a consent-bearing configuration and clear the diagnostic log. */
async function armServerCookieGuard(page: Page, nonce: string, original: FazSettings): Promise<void> {
  await applySettings(page, nonce, {
    script_blocking: { ...(original.script_blocking ?? {}), block_server_cookies: true },
    banner_control: { ...(original.banner_control ?? {}), status: true, cache_compatibility: false },
  });
  wpEval(`delete_transient( '${BLOCKED_TRANSIENT}' );`);
}

/**
 * Ensure the guard probe is classifiable by the admin catalogue.
 *
 * The enforcement helper deliberately does not consult the Open Cookie
 * Database, so recognising brikpanel_vid there is insufficient. Return only an
 * id created by this test; a pre-existing operator row must never be deleted by
 * cleanup.
 */
async function ensureGuardProbeRow(page: Page, nonce: string): Promise<number | null> {
  const domain = new URL(WP_BASE).hostname;
  const existing = (await listCookies(page, nonce)).find(
    (cookie: any) => String(cookie?.name ?? '') === GUARD_COOKIE && String(cookie?.domain ?? '') === domain,
  );
  if (existing) {
    return null;
  }

  const analyticsId = await findCategoryId(page, nonce, 'analytics');
  const created = await fazApiPost<Record<string, unknown>>(page, nonce, 'cookies', {
    name: GUARD_COOKIE,
    slug: GUARD_COOKIE,
    domain,
    category: analyticsId,
    duration: { en: '1 year' },
    description: { en: 'Release-verify server-cookie guard probe' },
  });
  expect([200, 201], `guard probe catalogue row was not created (status ${created.status})`).toContain(created.status);

  const row = (await listCookies(page, nonce)).find(
    (cookie: any) => String(cookie?.name ?? '') === GUARD_COOKIE && String(cookie?.domain ?? '') === domain,
  );
  const id = Number(row?.id ?? row?.cookie_id ?? 0);
  expect(id, 'created guard probe row returned no id').toBeGreaterThan(0);
  return id;
}

async function removeGuardProbeRow(page: Page, nonce: string, id: number | null): Promise<void> {
  if (!id) return;
  const deleted = await fazApiDelete(page, nonce, `cookies/${id}`);
  expect.soft([200, 204], 'the test-created guard probe row was not removed').toContain(deleted.status);
}

// ── Region surfaces via reflection on the INSTALLED classes ──────────────────

type RegionProbe = Record<string, { frontend: boolean; amp: boolean }>;

function probeRegionSurfaces(): RegionProbe {
  const raw = wpEval(`
    if ( ! class_exists( '\\FazCookie\\Frontend\\Frontend' ) || ! class_exists( '\\FazCookie\\Frontend\\AMP_Consent' ) ) {
      echo wp_json_encode( array( 'error' => 'installed plugin does not expose Frontend/AMP_Consent' ) );
    } else {
      $sets      = array( array( 'eu' ), array( 'uk' ), array( 'us' ), array( 'za' ), array( 'eu', 'uk' ), array( 'za', 'uk' ) );
      $countries = array( 'ZA', 'GB', 'DE', 'FR', 'US', 'IS', 'NO', 'LI', 'CH', 'IT', 'BR', 'JP' );
      $frontend  = ( new ReflectionClass( '\\FazCookie\\Frontend\\Frontend' ) )->newInstanceWithoutConstructor();
      $fm        = new ReflectionMethod( '\\FazCookie\\Frontend\\Frontend', 'is_country_in_regions' );
      $fm->setAccessible( true );
      $am = new ReflectionMethod( '\\FazCookie\\Frontend\\AMP_Consent', 'country_in_regions' );
      $am->setAccessible( true );
      $pairs = array();
      foreach ( $sets as $set ) {
        foreach ( $countries as $c ) {
          $key           = implode( '+', $set ) . '|' . $c;
          $pairs[ $key ] = array(
            'frontend' => (bool) $fm->invoke( $frontend, $c, $set ),
            'amp'      => (bool) $am->invoke( null, $c, $set ),
          );
        }
      }
      echo wp_json_encode( $pairs );
    }
  `);
  const parsed = JSON.parse(raw) as RegionProbe & { error?: string };
  expect((parsed as any).error, 'region-surface reflection probe ran against the installed plugin').toBeUndefined();
  return parsed;
}

/** Positive anchor for the HTTP geo path: prove the CF-country injection resolves. */
async function assertGeoInjectionWorks(browser: Browser): Promise<void> {
  const ctx = await browser.newContext({ userAgent: ANON_UA });
  try {
    const res = await ctx.request.get(`${WP_BASE}/?faz_e2e_geo_probe=1&faz_e2e_trust_cf=1&faz_e2e_cf_country=DE`);
    expect(res.status(), 'geo probe endpoint (faz-e2e-audit-lab) must answer').toBe(200);
    const body = await res.json();
    expect(body?.country, 'CF-country injection must resolve — if this is empty the geo assertions below would pass vacuously (check for a leftover faz-e2e-force-no-geo mu-plugin)').toBe('DE');
  } finally {
    await ctx.close();
  }
}

test.describe('Release verify — admin surfaces and cross-cutting fixes', () => {
  let initialActivePluginFiles: string[] | null = null;

  test.beforeAll(() => {
    // Tests 5-8 observe the site through the scan-lab (server Set-Cookie
    // emitter) and audit-lab (CF-country injection) fixture plugins. With
    // WP_PATH we deploy + activate them and restore the original active set
    // afterwards; without WP_PATH the probes hard-fail with clear messages.
    if (!WP_PATH) return;
    initialActivePluginFiles = listActivePluginFiles();
    ensureFixturePlugin('faz-e2e-scan-lab');
    ensureFixturePlugin('faz-e2e-audit-lab');
  });

  test.afterAll(() => {
    if (initialActivePluginFiles) {
      restoreActivePluginFiles(initialActivePluginFiles);
    }
  });

  // ── Finding 1: expiry clamp notice ─────────────────────────────────────────

  test('changing the regulation rewrites an out-of-bounds lifetime and says so with both numbers, in clean prose — and the notice is withdrawn when nothing moved', async ({ page, loginAsAdmin }) => {
    // Nothing here is saved: the banner form is mutated in-page only, so there
    // is no persisted state to snapshot or restore.
    await openBannerGeneralTab(page, loginAsAdmin);
    const hint = page.locator('#faz-b-expiry-hint');

    // The template the clamp notice is built from must be a clean single-line
    // sentence. In the broken build the attribute carried the PHP source's
    // newline + tab indentation, which banner.js writes into the notice as-is.
    const tpl = await hint.getAttribute('data-template');
    expect(tpl, 'the clamp notice template attribute exists').toBeTruthy();
    expect(tpl!).toContain('%1$d');
    expect(tpl!).toContain('%2$d');
    expect(tpl!, 'template carries no literal newline/tab/indentation from the PHP source').not.toMatch(/[\n\t]|\s{2,}/);
    expect(tpl!, 'template has no leading/trailing whitespace').toBe(tpl!.trim());

    // Park a value that is legal under GDPR but below the CCPA floor…
    await page.selectOption('#faz-b-law', 'gdpr');
    await setExpiryValue(page, '30');

    // …then switch the regulation. The clamp must move 30 → 365 AND disclose
    // exactly that move.
    await page.selectOption('#faz-b-law', 'ccpa');
    await expect(page.locator('#faz-b-expiry'), 'CCPA floor rewrites the field to 365').toHaveValue('365');
    await expect(hint, 'the rewrite is announced, not silent').toBeVisible();
    await expect(hint).toHaveAttribute('data-notice', 'clamp');
    const clampText = (await hint.textContent()) ?? '';
    expect(clampText, 'notice names the value the admin had').toContain('30');
    expect(clampText, 'notice names the value it was changed to').toContain('365');
    expect(clampText, 'placeholders were substituted').not.toContain('%1$d');
    expect(clampText, 'rendered notice carries no template indentation').not.toMatch(/[\n\t]|\s{2,}/);

    // Withdrawal: a law change that moves nothing must clear the notice — a
    // stale clamp explanation with numbers that no longer describe the field
    // is the other half of the finding.
    await setExpiryValue(page, '100');
    await page.selectOption('#faz-b-law', 'gdpr_ccpa'); // bounds 1..182: 100 stays put
    await expect(page.locator('#faz-b-expiry')).toHaveValue('100');
    await expect(hint, 'no move → no notice').toBeHidden();
    expect(((await hint.textContent()) ?? '').trim(), 'withdrawn notice leaves no stale text behind').toBe('');
  });

  test('a stored lifetime the runtime will bound is disclosed as stored-vs-served the moment it is typed, and the disclosure is withdrawn when the numbers agree', async ({ page, loginAsAdmin }) => {
    await openBannerGeneralTab(page, loginAsAdmin);
    const hint = page.locator('#faz-b-expiry-hint');
    const expiry = page.locator('#faz-b-expiry');

    await page.selectOption('#faz-b-law', 'ccpa');

    // Typing 30 under CCPA: the field must keep the admin's number (the form
    // may not lie about persisted state) while the notice discloses that
    // visitors are served 365 — the exact "displayed 30 while serving 365"
    // silence this finding was about.
    await setExpiryValue(page, '30');
    await expect(expiry, 'the typed value is left exactly as entered').toHaveValue('30');
    await expect(hint, 'the stored/served divergence is disclosed').toBeVisible();
    await expect(hint).toHaveAttribute('data-notice', 'runtime');
    const runtimeText = (await hint.textContent()) ?? '';
    expect(runtimeText, 'disclosure names the stored number').toContain('30');
    expect(runtimeText, 'disclosure names the served number').toContain('365');
    expect(runtimeText, 'disclosure is clean prose').not.toMatch(/[\n\t]|\s{2,}/);

    // When stored and served agree the disclosure must go — a permanent
    // warning on a correct value teaches admins to ignore it.
    await setExpiryValue(page, '400');
    await expect(expiry).toHaveValue('400');
    await expect(hint, 'numbers agree → the disclosure is withdrawn').toBeHidden();
    expect(((await hint.textContent()) ?? '').trim()).toBe('');
  });

  // ── Finding 2: System Status table headers ─────────────────────────────────

  test('Recently Blocked Server Cookies renders as a labelled data table: a header row with one th per data column', async ({ page, loginAsAdmin }) => {
    const transientSnapshot = snapshotTransient(BLOCKED_TRANSIENT);
    try {
      // Seed two rows so the table (not the empty-state paragraph) renders.
      const now = Math.floor(Date.now() / 1000);
      seedBlockedEntries([
        { name: '_faz_e2e_status_a', category: 'analytics', request: '/checkout/', blocked_at: now - 60 },
        { name: '_faz_e2e_status_b', category: 'marketing', request: '/', blocked_at: now },
      ]);

      await loginAsAdmin(page);
      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-system-status`, { waitUntil: 'domcontentloaded' });

      const dataTable = page.locator('table.faz-status-table.faz-status-table-data');
      await expect(dataTable, 'the blocked-cookies table renders when entries exist').toHaveCount(1);

      const headerCells = dataTable.locator('thead th');
      const headerCount = await headerCells.count();
      expect(headerCount, 'the data table has a header row').toBeGreaterThan(0);

      const firstRowCells = dataTable.locator('tbody tr').first().locator('td');
      await expect(firstRowCells, 'seeded row rendered').not.toHaveCount(0);
      expect(headerCount, 'one header cell per data column — captionless columns were the defect').toBe(await firstRowCells.count());
      expect(headerCount, 'the four documented columns').toBe(4);

      const labels = (await headerCells.allTextContents()).map((t) => t.trim());
      expect(labels).toContain('Cookie');
      expect(labels).toContain('Request Path');

      // The seeded content really is what the cells carry (the header is for
      // THIS data, not a decorative row).
      await expect(dataTable.locator('tbody tr').filter({ hasText: '_faz_e2e_status_a' })).toHaveCount(1);
    } finally {
      restoreTransient(BLOCKED_TRANSIENT, transientSnapshot);
      assertTransientRestored(BLOCKED_TRANSIENT, transientSnapshot);
    }
  });

  test('the label:value status tables on the same page did NOT inherit the data-table header row', async ({ page, loginAsAdmin }) => {
    // Read-only: whatever the transient holds, the sibling tables render.
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-system-status`, { waitUntil: 'domcontentloaded' });

    const pairTables = page.locator('table.faz-status-table:not(.faz-status-table-data)');
    const pairCount = await pairTables.count();
    // Environment, Plugin Configuration, Database, Cron Jobs at minimum. If
    // this drops the selector went stale and every assertion below is vacuous.
    expect(pairCount, 'the four sibling label:value tables are present').toBeGreaterThanOrEqual(4);

    for (let i = 0; i < pairCount; i += 1) {
      const tbl = pairTables.nth(i);
      await expect(tbl.locator('thead'), `label:value table #${i} must not gain a header row — its CSS bolds/sizes the first column as a row label`).toHaveCount(0);
      await expect(tbl.locator('th'), `label:value table #${i} has no th cells`).toHaveCount(0);
      // And it still IS the two-column shape the bare .faz-status-table CSS is built for.
      await expect(tbl.locator('tr').first().locator('td')).toHaveCount(2);
    }
  });

  // ── Finding 3: request path retention ──────────────────────────────────────

  test('a blocked Set-Cookie diagnostic stores the request PATH only — the query string of the triggering request never reaches the 24h store', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    const transientSnapshot = snapshotTransient(BLOCKED_TRANSIENT);
    let probeRowId: number | null = null;
    try {
      probeRowId = await ensureGuardProbeRow(page, nonce);
      await armServerCookieGuard(page, nonce, original);

      const probe = await probeBlockedCookieWithQueryString(browser);
      // Positive anchors: the handler really emitted the analytics cookie and
      // the guard really stripped it — without both, an empty transient below
      // would "pass" while proving nothing.
      expect(probe.emitted).toBe('brikpanel_vid');
      expect(probe.setCookie, 'guard must strip the unconsented analytics cookie for the diagnostic to be recorded at all').not.toContain('brikpanel_vid=');

      const entries = readBlockedEntries();
      const entry = entries.find((e) => e.name === 'brikpanel_vid');
      expect(entry, 'the block was recorded in the diagnostic transient').toBeTruthy();
      expect(entry!.request, 'stored request is exactly the path of the triggering request').toBe(AJAX_PATH);
      expect(entry!.request).not.toContain('?');
      expect(entry!.request).not.toContain(QUERY_MARKER);
      // Belt and braces: the marker appears NOWHERE in the stored structure —
      // not smuggled into another field either.
      expect(JSON.stringify(entries)).not.toContain(QUERY_MARKER);
      expect(JSON.stringify(entries)).not.toContain('leak%40example.com');
      expect(entry!.category, 'category travels with the diagnostic').toBe('analytics');
      expect(entry!.blocked_at, 'timestamp recorded').toBeGreaterThan(0);
    } finally {
      restoreTransient(BLOCKED_TRANSIENT, transientSnapshot);
      assertTransientRestored(BLOCKED_TRANSIENT, transientSnapshot);
      await restoreSettingsGroups(page, nonce, original, ['script_blocking', 'banner_control']);
      await removeGuardProbeRow(page, nonce, probeRowId);
    }
  });

  test('the System Status page shows a really-blocked cookie with its path and never the query string it arrived with', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    const transientSnapshot = snapshotTransient(BLOCKED_TRANSIENT);
    let probeRowId: number | null = null;
    try {
      probeRowId = await ensureGuardProbeRow(page, nonce);
      await armServerCookieGuard(page, nonce, original);

      const probe = await probeBlockedCookieWithQueryString(browser);
      expect(probe.emitted).toBe('brikpanel_vid');
      expect(probe.setCookie).not.toContain('brikpanel_vid=');

      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-system-status`, { waitUntil: 'domcontentloaded' });

      const row = page
        .locator('table.faz-status-table-data tbody tr')
        .filter({ hasText: 'brikpanel_vid' })
        .first();
      await expect(row, 'the real block surfaces in the admin diagnostic table').toBeVisible();
      await expect(row.locator('td').first(), 'the Cookie column names the blocked cookie').toContainText('brikpanel_vid');

      const pathCell = row.locator('td').nth(2);
      await expect(pathCell, 'the Request Path column carries the path the cookie was blocked on').toHaveText(AJAX_PATH);

      // The personal-data-shaped query string must be absent from the whole
      // rendered page, not merely from one cell.
      const pageHtml = await page.content();
      expect(pageHtml).not.toContain(QUERY_MARKER);
    } finally {
      restoreTransient(BLOCKED_TRANSIENT, transientSnapshot);
      assertTransientRestored(BLOCKED_TRANSIENT, transientSnapshot);
      await restoreSettingsGroups(page, nonce, original, ['script_blocking', 'banner_control']);
      await removeGuardProbeRow(page, nonce, probeRowId);
    }
  });

  // ── Finding 4: region map single source (issue #238) ───────────────────────

  test('a ZA visitor is inside the za region preset on the live geo gate: the banner is served to ZA and withheld from a non-target country', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    try {
      await assertGeoInjectionWorks(browser);

      await applySettings(page, nonce, {
        banner_control: { ...(original.banner_control ?? {}), status: true, cache_compatibility: false },
        geolocation: {
          ...(original.geolocation ?? {}),
          geo_targeting: true,
          target_regions: ['za'],
          default_behavior: 'no_banner',
        },
      });

      const zaHtml = await anonFrontHtml(browser, '/?faz_e2e_trust_cf=1&faz_e2e_cf_country=ZA');
      expect(hasBannerAssets(zaHtml), 'ZA matches the za preset → the banner bootstrap is served').toBe(true);

      // The negative half is what makes the positive meaningful: the same
      // configuration must withhold the banner from a country outside the
      // preset, proving the gate is judging the region table and not simply
      // serving everyone.
      const frHtml = await anonFrontHtml(browser, '/?faz_e2e_trust_cf=1&faz_e2e_cf_country=FR');
      expect(hasBannerAssets(frHtml), 'FR is outside the za preset → no banner under default_behavior=no_banner').toBe(false);
    } finally {
      await restoreSettingsGroups(page, nonce, original, ['banner_control', 'geolocation']);
    }
  });

  test('Frontend and AMP region checks agree on every probe, and GB stays outside the eu preset on both surfaces and on the live gate', async ({ page, browser, loginAsAdmin }) => {
    // Surface agreement: the two formerly hand-copied tables (the AMP one had
    // drifted — no 'za') must now answer identically everywhere. Runs against
    // the INSTALLED classes via reflection because the AMP twin has no HTTP
    // surface without the AMP plugin.
    const matrix = probeRegionSurfaces();
    const keys = Object.keys(matrix);
    expect(keys.length, 'the probe matrix actually ran').toBeGreaterThan(0);
    for (const key of keys) {
      expect(matrix[key].frontend, `surfaces disagree on ${key} — the single region table is not single`).toBe(matrix[key].amp);
    }

    // Pin the semantic anchors of the shared table on both surfaces at once
    // (agreement above makes one assertion cover both).
    expect(matrix['za|ZA'].frontend, 'za preset contains ZA').toBe(true);
    expect(matrix['eu|ZA'].frontend, 'ZA is not in eu').toBe(false);
    expect(matrix['eu|GB'].frontend, 'GB must NOT be in the eu preset (UK GDPR has its own bucket)').toBe(false);
    expect(matrix['uk|GB'].frontend, 'GB is the uk preset').toBe(true);
    expect(matrix['eu|DE'].frontend).toBe(true);
    expect(matrix['eu|IS'].frontend, 'EEA (Iceland) is in eu').toBe(true);
    expect(matrix['eu|NO'].frontend, 'EEA (Norway) is in eu').toBe(true);
    expect(matrix['eu+uk|GB'].frontend, 'multi-region set: GB matches via uk').toBe(true);
    expect(matrix['za+uk|ZA'].frontend, 'multi-region set: ZA matches via za').toBe(true);
    expect(matrix['us|FR'].frontend).toBe(false);

    // And the eu/uk boundary holds on the LIVE HTTP gate, not only under
    // reflection: with eu as the only target and no_banner elsewhere, a DE
    // visitor gets the banner and a GB visitor does not.
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    try {
      await assertGeoInjectionWorks(browser);
      await applySettings(page, nonce, {
        banner_control: { ...(original.banner_control ?? {}), status: true, cache_compatibility: false },
        geolocation: {
          ...(original.geolocation ?? {}),
          geo_targeting: true,
          target_regions: ['eu'],
          default_behavior: 'no_banner',
        },
      });

      const deHtml = await anonFrontHtml(browser, '/?faz_e2e_trust_cf=1&faz_e2e_cf_country=DE');
      expect(hasBannerAssets(deHtml), 'DE is in eu → banner served').toBe(true);

      const gbHtml = await anonFrontHtml(browser, '/?faz_e2e_trust_cf=1&faz_e2e_cf_country=GB');
      expect(hasBannerAssets(gbHtml), 'GB is NOT in eu → banner withheld — red here means GB leaked into the eu table').toBe(false);
    } finally {
      await restoreSettingsGroups(page, nonce, original, ['banner_control', 'geolocation']);
    }
  });
});
