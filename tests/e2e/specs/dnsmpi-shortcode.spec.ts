/**
 * E2E tests for DNSMPI shortcode fixes applied in the adamsreview below-gate pass:
 *
 *   DNSMPI-UI-01  Legal text: intro uses "US resident" language, not "California resident"
 *   DNSMPI-UI-02  Form visibility: form stays visible on server error; hides only on success
 *   DNSMPI-UI-03  Accessibility: aria-busy set on button during submit; cleared on error/catch
 *   DNSMPI-UI-04  Accessibility: focus moves to success notice after successful opt-out
 */

import { expect } from '@playwright/test';
import { test } from '../fixtures/wp-fixture';
import { upsertPage, wpEval } from '../utils/wp-env';

// ── Constants ────────────────────────────────────────────────────────────────

const SLUG = 'faz-e2e-dnsmpi-ux';

// ── Helpers ──────────────────────────────────────────────────────────────────

function clearRateLimit(): void {
  // Clean every rate-limit / atomic-lock option produced by both the
  // opt-out path (handle_optout: faz_dnsmpi_lock_*) and the rescind path
  // (handle_rescind: faz_dnsmpi_rsc_lock_* + faz_dnsmpi_rsc_rl_*). Without
  // the rescind keys, a rescind in test N can throttle the rescind path in
  // test N+1 with "Too many requests" and produce non-deterministic CI
  // failures whenever two rescind-touching specs run back-to-back.
  wpEval(`
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_faz_dnsmpi_%' OR option_name LIKE '_transient_faz_dnsmpi_rsc_%' OR option_name LIKE 'faz_dnsmpi_lock_%' OR option_name LIKE 'faz_dnsmpi_rsc_lock_%' OR option_name LIKE 'faz_dnsmpi_rsc_rl_%'" );
  `);
}

function clearOptoutLogs(): void {
  wpEval(`
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->prefix}faz_consent_logs WHERE status = 'dnsmpi_optout'" );
  `);
}

// ── Suite setup ──────────────────────────────────────────────────────────────

let pageUrl = '';

test.beforeAll(() => {
  upsertPage(SLUG, 'FAZ DNSMPI UX', '[faz_do_not_sell]');
  pageUrl = wpEval(`
    $p = get_page_by_path( '${SLUG}', OBJECT, 'page' );
    echo $p ? get_permalink( $p->ID ) : '';
  `).trim();
  if (!pageUrl) {
    throw new Error(`Could not resolve permalink for slug "${SLUG}". Enable pretty permalinks.`);
  }
});

test.afterAll(() => {
  clearRateLimit();
  clearOptoutLogs();
});

// Pre-accept the consent banner and clear any opt-out cookie so the form renders.
test.beforeEach(async ({ page, wpBaseURL }) => {
  await page.context().clearCookies();
  const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
  const domain = new URL(wpBaseURL).hostname;
  await page.context().addCookies([{
    name:     'fazcookie-consent',
    value:    `consentid%3Ae2eux%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2Cmarketing%3Ayes%2Crev%3A${rev}`,
    domain,
    path:     '/',
    sameSite: 'Lax',
  }]);
});

// ── DNSMPI-UI-01: Legal text ─────────────────────────────────────────────────

test.describe('DNSMPI-UI-01 — "US resident" intro text', () => {
  test('intro paragraph says "US resident in a state with applicable privacy laws"', async ({ page }) => {
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

    const text = await page.locator('.faz-dnsmpi-wrap p').first().textContent() ?? '';
    expect(text).toContain('US resident in a state with applicable privacy laws');
    expect(text).not.toContain('California resident');
  });
});

// ── DNSMPI-UI-02: Form visibility ────────────────────────────────────────────

test.describe('DNSMPI-UI-02 — form visibility after submit', () => {
  test.describe.configure({ mode: 'serial' });

  test('form remains visible when server returns an error', async ({ page }) => {
    await page.route('**/admin-ajax.php', async (route) => {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ success: false, data: 'Test error from route' }),
      });
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dnsmpi-btn', { timeout: 10_000 });

    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    await page.waitForTimeout(200);

    // Form must remain visible after an error response.
    const formVisible = await page.locator('.faz-dnsmpi-form').isVisible();
    expect(formVisible, 'form must remain visible on server error').toBe(true);

    // Notice must be visible and carry the error class.
    await expect(page.locator('.faz-dnsmpi-notice')).toBeVisible();
    const cls = await page.locator('.faz-dnsmpi-notice').getAttribute('class') ?? '';
    expect(cls).toContain('error');

    await page.unrouteAll();
  });

  test('form is hidden and notice visible when server returns success', async ({ page }) => {
    await page.route('**/admin-ajax.php', async (route) => {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { message: 'Opt-out received.' } }),
      });
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dnsmpi-btn', { timeout: 10_000 });

    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    await page.waitForTimeout(200);

    // Form must be hidden on success.
    const formVisible = await page.locator('.faz-dnsmpi-form').isVisible();
    expect(formVisible, 'form must be hidden after successful opt-out').toBe(false);

    // Success notice must be visible.
    await expect(page.locator('.faz-dnsmpi-notice')).toBeVisible();
    const cls = await page.locator('.faz-dnsmpi-notice').getAttribute('class') ?? '';
    expect(cls).toContain('success');

    await page.unrouteAll();
  });
});

// ── DNSMPI-UI-03: aria-busy ──────────────────────────────────────────────────

test.describe('DNSMPI-UI-03 — aria-busy on submit button', () => {
  test.describe.configure({ mode: 'serial' });

  test('button has aria-busy="true" while request is in flight', async ({ page }) => {
    // Use a deferred route so we can observe the intermediate state.
    let resolve!: (body: string) => void;
    const responseReady = new Promise<string>((r) => { resolve = r; });

    await page.route('**/admin-ajax.php', async (route) => {
      const body = await responseReady;
      await route.fulfill({ contentType: 'application/json', body });
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dnsmpi-btn', { timeout: 10_000 });

    // Click submit — fetch starts, route holds.
    void page.locator('.faz-dnsmpi-btn').click();

    // Give the JS handler a moment to run and set aria-busy before we check.
    await page.waitForTimeout(150);

    const ariaBusy = await page.locator('.faz-dnsmpi-btn').getAttribute('aria-busy');
    expect(ariaBusy, 'button must have aria-busy="true" while request is in flight').toBe('true');

    // Resolve with an error so the button is restored — this also completes the test cleanly.
    resolve(JSON.stringify({ success: false, data: 'Cancelled.' }));
    await page.waitForResponse('**/admin-ajax.php');

    await page.unrouteAll();
  });

  test('aria-busy is cleared on the button after an error response', async ({ page }) => {
    await page.route('**/admin-ajax.php', async (route) => {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ success: false, data: 'Error response' }),
      });
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dnsmpi-btn', { timeout: 10_000 });

    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    await page.waitForTimeout(200);

    const ariaBusy = await page.locator('.faz-dnsmpi-btn').getAttribute('aria-busy');
    expect(ariaBusy, 'aria-busy must be "false" after error response').toBe('false');

    await page.unrouteAll();
  });

  test('aria-busy is cleared on the button when a network error occurs', async ({ page }) => {
    await page.route('**/admin-ajax.php', (route) => route.abort('failed'));

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dnsmpi-btn', { timeout: 10_000 });

    // The network abort triggers the .catch() handler in faz-dnsmpi.js.
    void page.locator('.faz-dnsmpi-btn').click();
    await page.waitForTimeout(500);

    const ariaBusy = await page.locator('.faz-dnsmpi-btn').getAttribute('aria-busy');
    expect(ariaBusy, 'aria-busy must be "false" after network failure').toBe('false');

    await page.unrouteAll();
  });
});

// ── DNSMPI-UI-04: Focus management ──────────────────────────────────────────

test.describe('DNSMPI-UI-04 — focus moves to notice after successful opt-out', () => {
  test('document.activeElement is the success notice after opt-out', async ({ page }) => {
    await page.route('**/admin-ajax.php', async (route) => {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { message: 'Opted out.' } }),
      });
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dnsmpi-btn', { timeout: 10_000 });

    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    await page.waitForTimeout(200);

    // Check that the active element is inside .faz-dnsmpi-notice (or IS the notice).
    const isNoticeFocused = await page.evaluate(() => {
      const notice = document.querySelector('.faz-dnsmpi-notice');
      return notice !== null && (document.activeElement === notice || notice.contains(document.activeElement));
    });

    expect(isNoticeFocused, 'keyboard focus must land on the success notice after opt-out').toBe(true);

    await page.unrouteAll();
  });
});
