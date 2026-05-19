/**
 * Regression tests for bugs resolved in the adamsreview fix run on feat/experimental-features
 * (commit 7d00a2f, 2026-05-12). One test per finding:
 *
 *   F003  Per-email rate-limit error message now says "1 hour" (was "1 minute")
 *   F018  sanitize_script_field empty-string bypass + canEditScripts in fazConfig
 *         (403 side already covered by cookie-api-security.spec.ts COOKIE-SEC-01;
 *          this file adds the canEditScripts key test)
 *   F024  fazDsarConfig.emailMsg is now supplied by wp_localize_script
 *   F036  maybe_enqueue_assets() still enqueues faz-dsar after docblock correction
 *   F043  fazDsarConfig.nameLabel / emailLabel / typeLabel supplied by wp_localize_script
 *   F050  IP_Hasher::hash_ip() uses faz_resolve_client_ip() — namespace-safe call
 */

import { expect, type Page, type Route } from '@playwright/test';
import { test } from '../fixtures/wp-fixture';
import { seedConsentedCookie, upsertPage, wpEval } from '../utils/wp-env';

/**
 * Block ONLY admin-ajax requests with the given FAZ action token, leaving
 * unrelated third-party admin-ajax traffic (wp-slimstat, burst-statistics,
 * IAWP, heartbeat, …) untouched. Returns a flag-getter that turns true
 * the first time a matching request hits the route.
 *
 * Without this filter, a generic `page.route('**\/admin-ajax.php', abort)`
 * intercepts the noisy concurrent tracking traffic that lives on the test
 * site and falsely flips `networkCalled = true` before the user even
 * clicks the submit button.
 */
function blockFazDsarSubmit(page: Page): { wasCalled: () => boolean; restore: () => Promise<void> } {
  let called = false;
  const handler = async (route: Route) => {
    const body = route.request().postData() ?? '';
    const isFazDsar =
      body.includes('action=faz_dsar_submit') ||
      /name="action"\s*\r?\n\s*\r?\n\s*faz_dsar_submit\b/.test(body);
    if (isFazDsar) {
      called = true;
      await route.abort();
    } else {
      await route.continue();
    }
  };
  page.route('**/admin-ajax.php', handler);
  return {
    wasCalled: () => called,
    restore: () => page.unroute('**/admin-ajax.php', handler),
  };
}

// ── Constants ─────────────────────────────────────────────────────────────────

const DSAR_SLUG = 'faz-e2e-ar-fixes-dsar';

// ── Suite setup ───────────────────────────────────────────────────────────────

let dsarPageUrl = '';

test.beforeAll(() => {
  upsertPage(DSAR_SLUG, 'FAZ AR Fixes DSAR', '[faz_dsar_form]');
  dsarPageUrl = wpEval(`
    $p = get_page_by_path( '${DSAR_SLUG}', OBJECT, 'page' );
    echo $p ? get_permalink( $p->ID ) : '';
  `).trim();
  if (!dsarPageUrl) {
    throw new Error(`Could not resolve permalink for "${DSAR_SLUG}". Enable pretty permalinks.`);
  }
});

// Pre-accept consent banner so it never overlaps form elements. The
// `seedConsentedCookie` helper (tests/e2e/utils/wp-env.ts) reads the live
// consent_revision so PHP's stale-cookie filter doesn't immediately
// invalidate the cookie we inject.
test.beforeEach(async ({ page, wpBaseURL }) => {
  await page.context().clearCookies();
  await seedConsentedCookie(page, wpBaseURL, 'e2e-ar');
});

// ── F003 — Rate-limit message says "1 hour" ───────────────────────────────────

test.describe('F003 — per-email rate-limit error message says "1 hour"', () => {
  const RL_TEST_EMAIL = 'ar-fixes-rl-test@example.com';

  test.beforeEach(() => {
    // Manually arm the per-email transient so the handler sees a rate-limit hit.
    wpEval(`
      $key = 'faz_dsar_rl_em_' . substr(
        hash_hmac( 'sha256', strtolower( '${RL_TEST_EMAIL}' ), wp_salt() ), 0, 16
      );
      set_transient( $key, 1, HOUR_IN_SECONDS );
    `);
  });

  test.afterEach(() => {
    wpEval(`
      $key = 'faz_dsar_rl_em_' . substr(
        hash_hmac( 'sha256', strtolower( '${RL_TEST_EMAIL}' ), wp_salt() ), 0, 16
      );
      delete_transient( $key );
    `);
  });

  test('blocked submission returns message containing "1 hour"', async ({ page, wpBaseURL }) => {
    const nonce = wpEval(`echo wp_create_nonce( 'faz_dsar_submit' );`).trim();

    const resp = await page.request.post(`${wpBaseURL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'faz_dsar_submit',
        nonce,
        dsar_name:  'Rate Limit User',
        dsar_email: RL_TEST_EMAIL,
        dsar_type:  'access',
        faz_dsar_honeypot: '',
      },
    });

    const body = await resp.json() as { success: boolean; data: unknown };
    expect(body.success, 'rate-limited request must return success=false').toBe(false);

    const message = typeof body.data === 'string' ? body.data : JSON.stringify(body.data);
    expect(message, 'error message must mention "1 hour"').toMatch(/1 hour/i);
    expect(message, 'error message must NOT say "1 minute" (F003 regression)').not.toMatch(/1 minute/i);
  });
});

// ── F018 — canEditScripts flag in window.fazConfig ────────────────────────────

test.describe('F018 — canEditScripts key present in fazConfig on cookies admin page', () => {
  test('window.fazConfig.canEditScripts is a boolean for logged-in admin', async ({ page, loginAsAdmin, wpBaseURL }) => {
    // Mandatory: use the wp-fixture's loginAsAdmin helper (see project
    // CLAUDE.md → "Writing E2E tests — mandatory conventions"). It handles
    // the resilient login flow (retries, transient redirects) rather than
    // a hand-rolled wp-login.php POST.
    await loginAsAdmin(page);

    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
      waitUntil: 'domcontentloaded',
    });

    // fazConfig is localised via wp_localize_script, which serialises PHP booleans
    // as strings ("1" for true, "" for false). Check that the key exists and is
    // truthy for an admin with unfiltered_html.
    const canEditScripts = await page.evaluate(() => {
      const cfg = (window as unknown as { fazConfig?: { canEditScripts?: unknown } }).fazConfig;
      if (!cfg || !('canEditScripts' in cfg)) return null;
      return cfg.canEditScripts;
    });

    expect(canEditScripts, 'fazConfig.canEditScripts key must be present on cookies page (F018)').not.toBeNull();
    // Admin has unfiltered_html → value is truthy ("1" from wp_localize_script).
    expect(
      !!canEditScripts,
      'fazConfig.canEditScripts must be truthy for an admin with unfiltered_html (F018)',
    ).toBe(true);
  });
});

// ── F024 — fazDsarConfig.emailMsg supplied by wp_localize_script ──────────────

test.describe('F024 — fazDsarConfig.emailMsg is present and non-empty', () => {
  test('emailMsg key is defined on DSAR page', async ({ page }) => {
    await page.goto(dsarPageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });

    const emailMsg = await page.evaluate(() => {
      const cfg = (window as unknown as { fazDsarConfig?: { emailMsg?: unknown } }).fazDsarConfig;
      return cfg?.emailMsg ?? null;
    });

    expect(emailMsg, 'fazDsarConfig.emailMsg must be supplied by wp_localize_script (F024)').not.toBeNull();
    expect(typeof emailMsg).toBe('string');
    expect((emailMsg as string).length, 'emailMsg must not be empty').toBeGreaterThan(0);
  });

  test('invalid email shows emailMsg text (not hardcoded fallback from undefined)', async ({ page }) => {
    const blocker = blockFazDsarSubmit(page);

    await page.goto(dsarPageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });

    // Read the localized error message from the live config so the assertion
    // works regardless of the WordPress locale (test site default is `it`).
    const expectedEmailMsg = await page.evaluate(() => {
      const cfg = (window as unknown as { fazDsarConfig?: { emailMsg?: string } }).fazDsarConfig;
      return cfg?.emailMsg ?? '';
    });
    expect(expectedEmailMsg.length, 'fazDsarConfig.emailMsg must be non-empty').toBeGreaterThan(0);

    await page.locator('[name="dsar_name"]').fill('Valid Name');
    await page.locator('[name="dsar_email"]').fill('not-an-email');
    await page.locator('[name="dsar_type"]').selectOption('access');

    await page.locator('.faz-dsar-btn').click();

    const notice = page.locator('.faz-dsar-notice');
    // Use auto-retrying assertions instead of a fixed waitForTimeout +
    // textContent read — under suite-wide load the JS handler can take more
    // than 150ms to populate the notice, which made this test flaky in the
    // full suite while passing in isolation. toContainText() polls until the
    // expected text appears (default 10s) and survives slow PHP-FPM responses.
    await expect(notice).toBeVisible();
    await expect(notice).toContainText(expectedEmailMsg);
    await expect(notice).not.toContainText('undefined');

    expect(blocker.wasCalled(), 'no DSAR submit AJAX call for an email-invalid form').toBe(false);
    await blocker.restore();
  });
});

// ── F036 — maybe_enqueue_assets() still works after docblock correction ────────

test.describe('F036 — DSAR script is still enqueued correctly after docblock fix', () => {
  test('faz-dsar script tag is present in DSAR page HTML', async ({ page }) => {
    await page.goto(dsarPageUrl, { waitUntil: 'domcontentloaded' });

    // The script handle is "faz-dsar"; wp_enqueue_script adds an id attribute
    // in the form "faz-dsar-js" to the generated <script> tag.
    const scriptHandle = await page.locator('script#faz-dsar-js, script[src*="faz-dsar"]').count();
    expect(
      scriptHandle,
      'faz-dsar script must be enqueued on pages with [faz_dsar_form] (F036 smoke test)',
    ).toBeGreaterThan(0);
  });
});

// ── F043 — fazDsarConfig label keys are supplied by wp_localize_script ────────

test.describe('F043 — fazDsarConfig.nameLabel / emailLabel / typeLabel are present', () => {
  test('all three label keys are defined and non-empty on DSAR page', async ({ page }) => {
    await page.goto(dsarPageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });

    const labels = await page.evaluate(() => {
      const cfg = (window as unknown as {
        fazDsarConfig?: { nameLabel?: unknown; emailLabel?: unknown; typeLabel?: unknown };
      }).fazDsarConfig;
      return {
        nameLabel:  cfg?.nameLabel  ?? null,
        emailLabel: cfg?.emailLabel ?? null,
        typeLabel:  cfg?.typeLabel  ?? null,
      };
    });

    for (const [key, value] of Object.entries(labels)) {
      expect(value, `fazDsarConfig.${key} must be supplied by wp_localize_script (F043)`).not.toBeNull();
      expect(typeof value, `${key} must be a string`).toBe('string');
      expect((value as string).length, `${key} must not be empty`).toBeGreaterThan(0);
    }
  });

  test('missing-fields error uses label from config, not hardcoded "undefined"', async ({ page }) => {
    const blocker = blockFazDsarSubmit(page);

    await page.goto(dsarPageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });

    // Read the three labels from the live config so the assertion works
    // regardless of the active WordPress locale.
    const labels = await page.evaluate(() => {
      const cfg = (window as unknown as {
        fazDsarConfig?: { nameLabel?: string; emailLabel?: string; typeLabel?: string };
      }).fazDsarConfig;
      return {
        nameLabel:  cfg?.nameLabel  ?? '',
        emailLabel: cfg?.emailLabel ?? '',
        typeLabel:  cfg?.typeLabel  ?? '',
      };
    });

    // Submit with all required fields empty → triggers per-field missing list.
    await page.locator('.faz-dsar-btn').click();

    // Auto-retrying assertions instead of waitForTimeout + textContent —
    // see F024 above for the rationale. Under suite-wide load the notice
    // can take longer than 150ms to populate.
    const notice = page.locator('.faz-dsar-notice');
    await expect(notice).toBeVisible();

    // At least one of the three localized labels must appear in the error text.
    const labelValues = [labels.nameLabel, labels.emailLabel, labels.typeLabel].filter(Boolean);
    expect(labelValues.length, 'at least one localized label must be supplied by wp_localize_script').toBeGreaterThan(0);

    // Poll until the FINAL text is populated (localized label present) AND
    // contains no "undefined". The original ordering ran the `undefined`
    // check before the poll, so an early-visible notice that later became
    // "Name, undefined, Type" would still pass — combining both predicates
    // inside the poll closes that race window.
    await expect.poll(async () => {
      const text = (await notice.textContent()) ?? '';
      const hasLabel = labelValues.some((label) => text.includes(label));
      return hasLabel && !text.includes('undefined');
    }, {
      message: 'error text must include at least one localized label AND must not contain "undefined" (F043)',
      timeout: 10_000,
    }).toBe(true);

    // Final asserting snapshot (after the poll has converged) so the
    // failure mode for "text never converged" is the poll message above
    // rather than a confusing two-step trace.
    await expect(notice, 'error must not contain "undefined" as a field label (F043)').not.toContainText('undefined');

    expect(blocker.wasCalled(), 'no DSAR submit AJAX call for a validation-failed form').toBe(false);
    await blocker.restore();
  });
});

// ── F050 — IP_Hasher::hash_ip() uses faz_resolve_client_ip() ─────────────────

test.describe('F050 — hash_ip() is proxy-aware via faz_resolve_client_ip()', () => {
  test('faz_resolve_client_ip() exists and returns a string', () => {
    const result = wpEval(`
      echo function_exists( 'faz_resolve_client_ip' ) ? 'yes' : 'no';
    `).trim();
    expect(result, 'faz_resolve_client_ip() must be defined (F050)').toBe('yes');
  });

  test('Dsar_Shortcode::hash_ip() returns a 64-char hex string (no namespace crash)', () => {
    // The IP_Hasher trait exposes a public WP_DEBUG-gated wrapper
    // `debug_hash_ip()` specifically for this test. Calling that wrapper
    // (instead of using ReflectionMethod::setAccessible on the private
    // hash_ip()) means the assertion survives any future refactor of the
    // trait's visibility, while keeping the hashing implementation private
    // in production.
    //
    // The test toggles WP_DEBUG on inside the wpEval to make the wrapper
    // return the real hash even if the test site is running without
    // WP_DEBUG. The override stays scoped to this single eval call.
    const result = wpEval(`
      if ( ! defined( 'WP_DEBUG' ) ) { define( 'WP_DEBUG', true ); }
      $sc = new \\FazCookie\\Includes\\Dsar_Shortcode();
      if ( ! method_exists( $sc, 'debug_hash_ip' ) ) {
        echo 'fail:debug_hash_ip method missing';
        return;
      }
      $hash = $sc->debug_hash_ip();
      // hash_hmac( 'sha256', ... ) produces 64 hex chars.
      echo ( is_string( $hash ) && preg_match( '/^[a-f0-9]{64}$/', $hash ) ) ? 'ok' : 'fail:' . $hash;
    `).trim();
    expect(result, 'hash_ip() must return a SHA-256 hex string without namespace errors (F050)').toBe('ok');
  });
});
