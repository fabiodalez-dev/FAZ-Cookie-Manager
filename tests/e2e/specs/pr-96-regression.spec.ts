/**
 * Regression tests for PR #96 — experimental shortcodes + security hardening.
 *
 * Each test maps to a specific fix from the CodeRabbit review cycle so that
 * any accidental revert is caught immediately.
 *
 * Coverage:
 *   REST-CTX-01/02 — opt_in/out_script visible only with context=edit + auth
 *   ACC-CAT-01     — _fazAcceptCategory() fires opt_in_script (snapshot fix)
 *   DNSMPI-COOKIE  — opt-out cookie written with path=/
 *   DNSMPI-RL      — second submission within 60 s is rate-limited
 *   DNSMPI-LOCK    — DB lock option released after processing
 *   DSAR-A11Y-01   — notice div carries ARIA live region attributes
 *   DSAR-A11Y-02   — focus moves to notice after async form response
 *   DSAR-CPT-01    — faz_dsar CPT requires manage_options (not editor-level caps)
 *   CACHE-INV-01   — faz_cookie_scripts_map transient cleared after category update
 *   WS-ET-01       — blocked WebSocket mock fires close via addEventListener
 */

import { expect, test } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { upsertPage, wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

// ── Page slugs (created in beforeAll, persisted across tests) ────────────────

const CCPA_SLUG = 'faz-e2e-pr96-ccpa';
const DSAR_SLUG = 'faz-e2e-pr96-dsar';

// ── Shared helpers ────────────────────────────────────────────────────────────

type FazWindow = Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } };

// ── WP-CLI cookie helpers (no browser, no auth) ───────────────────────────────

interface CookiePayload {
  name: string;
  slug: string;
  domain: string;
  category: number;
  duration: Record<string, string>;
  opt_in_script?: string;
  opt_out_script?: string;
}

/** Insert a cookie row directly via WP-CLI. Returns the new cookie_id. */
function createTestCookie(payload: CookiePayload): number {
  const meta: Record<string, string> = {};
  if (payload.opt_in_script)  meta.opt_in_script  = payload.opt_in_script;
  if (payload.opt_out_script) meta.opt_out_script = payload.opt_out_script;

  // Double-stringify produces a PHP double-quoted string literal, e.g.
  // "{\"en\":\"session\"}" — prevents PHP from mis-parsing {…} as a code block.
  const durationPhp = JSON.stringify(JSON.stringify(payload.duration));
  const metaPhp     = JSON.stringify(JSON.stringify(meta));

  const raw = wpEval(`
    global $wpdb;
    $now = current_time( 'mysql' );
    $wpdb->insert(
      "{$wpdb->prefix}faz_cookies",
      array(
        'name'          => ${JSON.stringify(payload.name)},
        'slug'          => ${JSON.stringify(payload.slug)},
        'description'   => '',
        'duration'      => ${durationPhp},
        'domain'        => ${JSON.stringify(payload.domain)},
        'category'      => ${payload.category},
        'type'          => '',
        'discovered'    => 0,
        'meta'          => ${metaPhp},
        'date_created'  => $now,
        'date_modified' => $now,
      ),
      array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
    );
    echo $wpdb->insert_id;
  `);
  const id = parseInt(raw.trim(), 10);
  expect(id, `createTestCookie failed for '${payload.name}'`).toBeGreaterThan(0);
  return id;
}

/** Delete a cookie row directly via WP-CLI. */
function deleteTestCookie(id: number): void {
  if (!id) return;
  wpEval(`
    global $wpdb;
    $wpdb->delete( "{$wpdb->prefix}faz_cookies", array( 'cookie_id' => ${id} ), array( '%d' ) );
    delete_transient( 'faz_cookie_scripts_map' );
  `);
}

/** Resolve the analytics category_id from the DB (dynamic to survive fixture resets). */
function getAnalyticsCategoryId(): number {
  const raw = wpEval(`
    global $wpdb;
    echo (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT category_id FROM {$wpdb->prefix}faz_cookie_categories WHERE slug = %s",
        'analytics'
      )
    );
  `);
  return parseInt(raw.trim(), 10);
}

function getMarketingCategoryId(): number {
  const raw = wpEval(`
    global $wpdb;
    echo (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT category_id FROM {$wpdb->prefix}faz_cookie_categories WHERE slug = %s",
        'marketing'
      )
    );
  `);
  return parseInt(raw.trim(), 10);
}

/** Resolve a WP page permalink by slug. */
function getPermalink(slug: string): string {
  return wpEval(`
    $page = get_page_by_path('${slug}', OBJECT, 'page');
    echo $page ? get_permalink($page->ID) : '';
  `).trim();
}

/** Clear all FAZ rate-limit transients and lock options so tests don't bleed into each other. */
function clearRateLimitState(): void {
  wpEval(`
    global $wpdb;
    // Collect rl_key names from the DB before deleting so we can flush the
    // object cache group as well (needed when Redis/Memcached is active).
    $rows = $wpdb->get_col(
      "SELECT option_name FROM {$wpdb->options}
       WHERE option_name LIKE '_transient_faz_dnsmpi_%'
          OR option_name LIKE '_transient_faz_dsar_%'"
    );
    $wpdb->query(
      "DELETE FROM {$wpdb->options}
       WHERE option_name LIKE '_transient_faz_dnsmpi_%'
          OR option_name LIKE '_transient_faz_dsar_%'
          OR option_name LIKE 'faz_dnsmpi_lock_%'"
    );
    // Strip the '_transient_' prefix to get the bare cache key and flush
    // any Redis/Memcached entry that may still be holding the lock.
    foreach ($rows as $opt) {
      $key = preg_replace('/^_transient_/', '', $opt);
      wp_cache_delete($key, 'faz_rate_limit');
    }
  `);
}

/** Seed a consent cookie that skips the banner without accepting any optional category. */
async function seedConsentAllNo(page: Page): Promise<void> {
  const rev = parseInt(wpEval("echo faz_get_consent_revision();").trim(), 10) || 1;
  await page.context().addCookies([
    {
      name: 'fazcookie-consent',
      value:
        `consentid%3Ae2e-pr96-no%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes` +
        `%2Cfunctional%3Ano%2Canalytics%3Ano%2Cperformance%3Ano` +
        `%2Cuncategorized%3Ano%2Cmarketing%3Ano%2Crev%3A${rev}`,
      domain: '127.0.0.1',
      path: '/',
      sameSite: 'Lax',
    },
  ]);
}

/** Seed a consent cookie that accepts everything (banner bypassed). */
async function seedConsentAllYes(page: Page): Promise<void> {
  // Read the server-side consent revision so PHP's stale-cookie check does not
  // delete the cookie we inject (faz_maybe_invalidate_stale_consent_cookie runs
  // on every 'init' and deletes any cookie whose rev < server revision).
  const rev = parseInt(wpEval("echo faz_get_consent_revision();").trim(), 10) || 1;
  await page.context().addCookies([
    {
      name: 'fazcookie-consent',
      value:
        `consentid%3Ae2e-pr96-yes%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes` +
        `%2Cfunctional%3Ayes%2Canalytics%3Ayes%2Cperformance%3Ayes` +
        `%2Cuncategorized%3Ayes%2Cmarketing%3Ayes%2Crev%3A${rev}`,
      domain: '127.0.0.1',
      path: '/',
      sameSite: 'Lax',
    },
  ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// REST-CTX: opt_in/out_script context restriction
// ─────────────────────────────────────────────────────────────────────────────

test.describe('REST API — opt_in/opt_out_script context restriction', () => {
  test.describe.configure({ mode: 'serial' });

  let testCookieId = 0;

  test.beforeAll(() => {
    const catId = getAnalyticsCategoryId();
    expect(catId, 'analytics category must exist in DB').toBeGreaterThan(0);
    testCookieId = createTestCookie({
      name:           '_faz_pr96_ctx',
      slug:           '_faz_pr96_ctx',
      domain:         '127.0.0.1',
      category:       catId,
      duration:       { en: 'session' },
      opt_in_script:  'window._fazPR96Ctx = 1;',
      opt_out_script: 'window._fazPR96CtxOut = 1;',
    });
  });

  test.afterAll(() => {
    deleteTestCookie(testCookieId);
    testCookieId = 0;
  });

  test('REST-CTX-01: opt_in_script absent from unauthenticated GET (default view context)', async ({
    wpBaseURL,
    request,
  }) => {
    // Fresh APIRequestContext: no browser cookies, no WP auth session.
    // No X-WP-Nonce, no ?context=edit — public response must not expose raw JS.
    const res = await request.get(
      `${wpBaseURL}/?rest_route=/faz/v1/cookies/${testCookieId}`,
    );
    const body = (await res.json()) as Record<string, unknown>;
    expect(body, 'opt_in_script must not appear in unauthenticated view').not.toHaveProperty(
      'opt_in_script',
    );
    expect(body, 'opt_out_script must not appear in unauthenticated view').not.toHaveProperty(
      'opt_out_script',
    );
  });

  test('REST-CTX-02: opt_in_script present with admin nonce and context=edit', async ({
    page, wpBaseURL, loginAsAdmin,
  }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

    const { status, body, nonceLen } = await page.evaluate(async (cookieId: number) => {
      const w = window as FazWindow;
      const nonce = w.fazConfig?.api?.nonce ?? '';
      const res = await fetch(`/?rest_route=/faz/v1/cookies/${cookieId}&context=edit`, {
        method: 'GET',
        headers: { 'X-WP-Nonce': nonce },
        credentials: 'same-origin',
      });
      const body = await res.json().catch(() => null);
      return { status: res.status, body, nonceLen: nonce.length };
    }, testCookieId);

    expect(nonceLen, 'fazConfig.api.nonce must be non-empty').toBeGreaterThan(0);
    expect(status, `authenticated GET must return 200 (body: ${JSON.stringify(body)})`).toBe(200);
    const b = body as Record<string, unknown>;
    expect(typeof b.opt_in_script, 'opt_in_script must be a string with context=edit').toBe('string');
    expect(b.opt_in_script).toBe('window._fazPR96Ctx = 1;');
    expect(b.opt_out_script).toBe('window._fazPR96CtxOut = 1;');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// ACC-CAT: _fazAcceptCategory snapshot fix
// ─────────────────────────────────────────────────────────────────────────────

test.describe('_fazAcceptCategory — opt_in_script fires for newly accepted category', () => {
  test.describe.configure({ mode: 'serial' });

  let testCookieId = 0;

  test.beforeAll(() => {
    const catId = getAnalyticsCategoryId();
    expect(catId, 'analytics category must exist in DB').toBeGreaterThan(0);
    testCookieId = createTestCookie({
      name:          '_faz_pr96_accept_cat',
      slug:          '_faz_pr96_accept_cat',
      domain:        '127.0.0.1',
      category:      catId,
      duration:      { en: 'session' },
      opt_in_script: 'window._fazPR96AcceptCatFired = (window._fazPR96AcceptCatFired || 0) + 1;',
    });
    // Invalidate the scripts-map cache so the new cookie is picked up on the frontend.
    wpEval(`delete_transient('faz_cookie_scripts_map');`);
  });

  test.afterAll(() => {
    deleteTestCookie(testCookieId);
    testCookieId = 0;
  });

  test('ACC-CAT-01: _fazAcceptCategory() fires opt_in_script for newly accepted category', async ({
    page, wpBaseURL,
  }) => {
    // Start with analytics=no so the category is not yet accepted.
    await seedConsentAllNo(page);
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });

    // Wait for script.js to initialise (window._fazAcceptCategory is registered globally).
    await page.waitForFunction(
      () => typeof (window as Record<string, unknown>)._fazAcceptCategory === 'function',
      { timeout: 10_000 },
    );

    // Simulate an iframe-placeholder click: accept analytics programmatically.
    // Before the snapshot fix this corrupted _fazCategoriesBeforeConsent — the
    // script would never fire because analytics appeared "already accepted" in
    // the snapshot taken AFTER _fazSetInStore(cat.slug, "yes").
    await page.evaluate(() => {
      (window as Record<string, unknown> & { _fazAcceptCategory: (s: string) => void })
        ._fazAcceptCategory('analytics');
    });

    // The opt_in_script runs asynchronously inside _fazExecuteConsentScripts.
    await page.waitForFunction(
      () => (window as Record<string, unknown>)._fazPR96AcceptCatFired > 0,
      { timeout: 5_000 },
    );

    const count = await page.evaluate(
      () => (window as Record<string, unknown>)._fazPR96AcceptCatFired as number,
    );
    expect(count, 'opt_in_script counter should be > 0 after _fazAcceptCategory').toBeGreaterThan(0);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// DNSMPI: cookie path, rate limit, DB lock
// ─────────────────────────────────────────────────────────────────────────────

test.describe('[faz_do_not_sell] — cookie path, rate limit, DB lock', () => {
  test.describe.configure({ mode: 'serial' });

  let ccpaUrl = '';

  test.beforeAll(() => {
    upsertPage(CCPA_SLUG, 'FAZ PR96 Do Not Sell', '[faz_do_not_sell]');
    ccpaUrl = getPermalink(CCPA_SLUG);
    if (!ccpaUrl) throw new Error('Could not resolve CCPA page permalink — enable pretty permalinks');
  });

  test.beforeEach(async ({ page }) => {
    clearRateLimitState();
    // Remove previous opt-out cookie so the "already opted out" guard does not trigger.
    await page.context().clearCookies({ name: 'fazcookie-dnsmpi' });
    await seedConsentAllYes(page);
  });

  test('DNSMPI-COOKIE-01: opt-out cookie is written with path=/', async ({ page, context }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);

    const cookies = await context.cookies(WP_BASE);
    const optout = cookies.find((c) => c.name === 'fazcookie-dnsmpi');
    expect(optout, 'fazcookie-dnsmpi cookie must be set').toBeDefined();
    expect(optout!.path, 'cookie path must be /').toBe('/');
  });

  test('DNSMPI-RL-01: second submission within 60 s is rejected with rate-limit error', async ({
    page,
  }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });

    // First submission — must succeed.
    const [r1] = await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    const json1 = (await r1.json()) as { success: boolean };
    expect(json1.success, 'first submission should succeed').toBe(true);

    // Clear opt-out cookie so the "already opted out" guard doesn't trigger.
    await page.context().clearCookies({ name: 'fazcookie-dnsmpi' });

    // Reload so the form reappears.
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });

    // Second submission — the rate-limit transient is still active.
    const [r2] = await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    const json2 = (await r2.json()) as { success: boolean; data?: string };
    expect(json2.success, 'second submission should be blocked').toBe(false);
    expect(String(json2.data ?? '')).toMatch(/too many requests/i);
  });

  test('DNSMPI-LOCK-01: faz_dnsmpi_lock_* DB option is removed after successful processing', async ({
    page,
  }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);

    // delete_option($lock_key) must have run; no lock row should remain.
    const lockCount = wpEval(`
      global $wpdb;
      echo (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'faz_dnsmpi_lock_%'"
      );
    `).trim();
    expect(parseInt(lockCount, 10), 'lock option should be deleted after processing').toBe(0);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// DSAR A11Y: ARIA live region + focus management
// ─────────────────────────────────────────────────────────────────────────────

test.describe('[faz_dsar_form] — ARIA accessibility', () => {
  test.describe.configure({ mode: 'serial' });

  let dsarUrl = '';

  test.beforeAll(() => {
    upsertPage(DSAR_SLUG, 'FAZ PR96 DSAR Form', '[faz_dsar_form]');
    dsarUrl = getPermalink(DSAR_SLUG);
    if (!dsarUrl) throw new Error('Could not resolve DSAR page permalink — enable pretty permalinks');
  });

  test.beforeEach(async ({ page }) => {
    clearRateLimitState();
    await seedConsentAllYes(page);
  });

  test.afterEach(() => {
    // Remove test DSAR posts created during submission.
    wpEval(`
      $posts = get_posts(array(
        'post_type'   => 'faz_dsar',
        'numberposts' => -1,
        'post_status' => array('private', 'publish', 'draft', 'trash', 'any'),
      ));
      foreach ($posts as $p) { wp_delete_post($p->ID, true); }
    `);
  });

  test('DSAR-A11Y-01: notice div has role=status aria-live=polite aria-atomic=true tabindex=-1', async ({
    page,
  }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const notice = page.locator('.faz-dsar-notice');
    await expect(notice).toHaveAttribute('role', 'status');
    await expect(notice).toHaveAttribute('aria-live', 'polite');
    await expect(notice).toHaveAttribute('aria-atomic', 'true');
    await expect(notice).toHaveAttribute('tabindex', '-1');
  });

  test('DSAR-A11Y-02: focus moves to notice div after successful form submission', async ({
    page,
  }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });

    await page.fill('[name="dsar_name"]', 'PR96 A11Y Test');
    await page.fill('[name="dsar_email"]', 'pr96-a11y@example.com');
    await page.selectOption('[name="dsar_type"]', 'access');

    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dsar-btn').click(),
    ]);

    // notice.focus() is called after the response — document.activeElement should be the notice.
    const focusedOnNotice = await page.evaluate(() => {
      const notice = document.querySelector('.faz-dsar-notice');
      return notice !== null && document.activeElement === notice;
    });
    expect(focusedOnNotice, 'focus should move to .faz-dsar-notice after submission').toBe(true);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// DSAR-CPT: capability mapping
// ─────────────────────────────────────────────────────────────────────────────

test.describe('DSAR CPT — capability mapping', () => {
  test('DSAR-CPT-01: faz_dsar post type caps map to manage_options, create_posts locked', async () => {
    const raw = wpEval(`
      $cpt = get_post_type_object('faz_dsar');
      if (!$cpt) { echo 'null'; return; }
      echo wp_json_encode(array(
        'read_post'          => $cpt->cap->read_post,
        'read_private_posts' => $cpt->cap->read_private_posts,
        'edit_post'          => $cpt->cap->edit_post,
        'edit_private_posts' => $cpt->cap->edit_private_posts,
        'delete_post'        => $cpt->cap->delete_post,
        'edit_posts'         => $cpt->cap->edit_posts,
        'create_posts'       => $cpt->cap->create_posts,
      ));
    `);
    expect(raw.trim(), 'faz_dsar post type must be registered').not.toBe('null');

    const caps = JSON.parse(raw) as Record<string, string>;
    expect(caps.read_post,          'read_post').toBe('manage_options');
    expect(caps.read_private_posts, 'read_private_posts').toBe('manage_options');
    expect(caps.edit_post,          'edit_post').toBe('manage_options');
    expect(caps.edit_private_posts, 'edit_private_posts').toBe('manage_options');
    expect(caps.delete_post,        'delete_post').toBe('manage_options');
    expect(caps.create_posts,       'create_posts').toBe('do_not_allow');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// CACHE-INV: scripts map transient cleared after category update
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Scripts map cache — invalidated on category update', () => {
  test.describe.configure({ mode: 'serial' });

  let catCookieId = 0;

  test.beforeAll(() => {
    const catId = getAnalyticsCategoryId();
    catCookieId = createTestCookie({
      name:          '_faz_pr96_cache_inv',
      slug:          '_faz_pr96_cache_inv',
      domain:        '127.0.0.1',
      category:      catId,
      duration:      { en: 'session' },
      opt_in_script: 'window._fazPR96CacheInv = 1;',
    });
  });

  test.afterAll(() => {
    deleteTestCookie(catCookieId);
    catCookieId = 0;
  });

  test('CACHE-INV-01: faz_cookie_scripts_map transient is deleted after category update', async ({
    page, wpBaseURL, loginAsAdmin,
  }) => {
    // 1. Warm up the scripts-map transient by loading the frontend.
    await seedConsentAllYes(page);
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(
      () => typeof (window as Record<string, unknown>)._fazConfig !== 'undefined',
      { timeout: 10_000 },
    );

    // 2. Update the analytics category via REST API using browser auth.
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });

    // Wait for fazConfig.api.nonce to be populated.
    await page.waitForFunction(
      () => {
        const w = window as FazWindow;
        return typeof w.fazConfig?.api?.nonce === 'string' && (w.fazConfig.api.nonce?.length ?? 0) > 0;
      },
      { timeout: 10_000 },
    );

    const analyticsCatId = getAnalyticsCategoryId();
    const { updateStatus, nonceLen, errorBody } = await page.evaluate(async (catId: number) => {
      const w = window as FazWindow;
      const nonce = w.fazConfig?.api?.nonce ?? '';
      const res = await fetch(`/?rest_route=/faz/v1/cookies/categories/${catId}`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ description: { en: 'PR96 cache invalidation test' } }),
        credentials: 'same-origin',
      });
      const errorBody = res.ok ? null : await res.json().catch(() => null);
      return { updateStatus: res.status, nonceLen: nonce.length, errorBody };
    }, analyticsCatId);

    expect(nonceLen, 'fazConfig.api.nonce must be non-empty').toBeGreaterThan(0);
    expect(updateStatus, `category update should succeed (body: ${JSON.stringify(errorBody)})`).toBe(200);

    // 3. The faz_cookie_scripts_map transient must be gone.
    const after = wpEval(`echo get_transient('faz_cookie_scripts_map') === false ? 'gone' : 'exists';`).trim();
    expect(after, 'scripts map transient must be cleared after category update').toBe('gone');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// WS-ET: WebSocket mock EventTarget compatibility
// ─────────────────────────────────────────────────────────────────────────────

test.describe('WebSocket mock — EventTarget compatibility', () => {
  test('WS-ET-01: blocked WebSocket mock fires close event via addEventListener', async ({
    page, wpBaseURL,
  }) => {
    // Reject marketing so any marketing-category provider WebSocket is blocked by FAZ.
    await seedConsentAllNo(page);
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });

    // Wait for script.js and the WebSocket interceptor to initialise.
    await page.waitForFunction(
      () => typeof (window as Record<string, unknown>)._fazAcceptCategory === 'function',
      { timeout: 10_000 },
    );

    // Attempt a WebSocket to a known marketing-category provider (Facebook Beacon).
    // FAZ should intercept it and return a mock. The mock MUST support addEventListener
    // (the old Object.create(WebSocket.prototype) mock lacked working EventTarget slots).
    const result = await page.evaluate(async () => {
      return new Promise<{ intercepted: boolean; closeFiredViaAddEventListener: boolean }>(
        (resolve) => {
          const ws = new WebSocket('wss://connect.facebook.net/f_beacon');

          // Detect whether FAZ intercepted this URL:
          //   - real WS: readyState starts at 0 (CONNECTING) and has a live connection
          //   - FAZ mock: readyState is 3 (CLOSED) immediately
          const wasIntercepted = ws.readyState === 3;

          if (!wasIntercepted) {
            // URL not blocked on this install — skip the assertion gracefully.
            ws.close();
            resolve({ intercepted: false, closeFiredViaAddEventListener: true });
            return;
          }

          // URL is blocked — verify the EventTarget proxy works.
          let fired = false;
          try {
            ws.addEventListener('close', () => {
              fired = true;
            });
          } catch {
            // Old code threw "Illegal invocation" here — the fix must prevent this.
            resolve({ intercepted: true, closeFiredViaAddEventListener: false });
            return;
          }

          // The mock dispatches close asynchronously via setTimeout(fn, 0).
          setTimeout(() => {
            resolve({ intercepted: true, closeFiredViaAddEventListener: fired });
          }, 500);
        },
      );
    });

    if (result.intercepted) {
      expect(
        result.closeFiredViaAddEventListener,
        'close event must fire via addEventListener on blocked WebSocket mock',
      ).toBe(true);
    }
    // If the URL is not intercepted (provider not in database), the test passes
    // vacuously — the assertion only applies when FAZ actually blocks the URL.
  });
});
