/**
 * E2E tests for DSAR shortcode client-side fixes applied in the adamsreview below-gate pass:
 *
 *   DSAR-VAL-03  Per-field missing list: error message identifies which fields are absent
 *   DSAR-VAL-04  Email format validation: client-side regex rejects malformed emails
 */

import { expect } from '@playwright/test';
import { test } from '../fixtures/wp-fixture';
import { upsertPage, wpEval } from '../utils/wp-env';

// ── Constants ────────────────────────────────────────────────────────────────

const SLUG = 'faz-e2e-dsar-val';

// ── Suite setup ──────────────────────────────────────────────────────────────

let pageUrl = '';

test.beforeAll(() => {
  upsertPage(SLUG, 'FAZ DSAR Validation', '[faz_dsar_form]');
  pageUrl = wpEval(`
    $p = get_page_by_path( '${SLUG}', OBJECT, 'page' );
    echo $p ? get_permalink( $p->ID ) : '';
  `).trim();
  if (!pageUrl) {
    throw new Error(`Could not resolve permalink for slug "${SLUG}". Enable pretty permalinks.`);
  }
});

// Pre-accept the consent banner to avoid it overlapping the form.
test.beforeEach(async ({ page, wpBaseURL }) => {
  await page.context().clearCookies();
  const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
  const domain = new URL(wpBaseURL).hostname;
  await page.context().addCookies([{
    name:     'fazcookie-consent',
    value:    `consentid%3Ae2eval%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2Cmarketing%3Ayes%2Crev%3A${rev}`,
    domain,
    path:     '/',
    sameSite: 'Lax',
  }]);
});

// ── DSAR-VAL-03: Per-field missing list ──────────────────────────────────────

test.describe('DSAR-VAL-03 — per-field missing list in validation error', () => {
  test.describe.configure({ mode: 'serial' });

  test('submitting with only name filled lists Email and Request type as missing', async ({ page }) => {
    // Intercept any AJAX call — a network request should NOT be made for
    // client-side validation failures. If this route is hit, the test fails.
    let networkCallMade = false;
    await page.route('**/admin-ajax.php', async (route) => {
      networkCallMade = true;
      await route.abort();
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });

    // Fill only the name field; leave email and type empty.
    await page.locator('[name="dsar_name"]').fill('Test User');
    // Leave [name="dsar_email"] and [name="dsar_type"] empty (select default = "").

    await page.locator('.faz-dsar-btn').click();
    // Validation fires synchronously — give the JS a moment to update the DOM.
    await page.waitForTimeout(100);

    // Notice must be visible with the error message.
    const notice = page.locator('.faz-dsar-notice');
    await expect(notice).toBeVisible();

    const text = await notice.textContent() ?? '';
    expect(text, 'error must contain "Missing:"').toContain('Missing:');
    expect(text, 'error must list "Email"').toContain('Email');
    expect(text, 'error must list "Request type"').toContain('Request type');

    expect(networkCallMade, 'no AJAX request must be made for a validation failure').toBe(false);
    await page.unrouteAll();
  });

  test('submitting with only email filled lists Name and Request type as missing', async ({ page }) => {
    let networkCallMade = false;
    await page.route('**/admin-ajax.php', async (route) => {
      networkCallMade = true;
      await route.abort();
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });

    await page.locator('[name="dsar_email"]').fill('test@example.com');
    // Leave name and type empty.

    await page.locator('.faz-dsar-btn').click();
    await page.waitForTimeout(100);

    const text = await page.locator('.faz-dsar-notice').textContent() ?? '';
    expect(text).toContain('Missing:');
    expect(text).toContain('Name');
    expect(text).toContain('Request type');

    expect(networkCallMade).toBe(false);
    await page.unrouteAll();
  });

  test('submitting with all fields filled triggers no validation error (passes client validation)', async ({ page }) => {
    // Route the AJAX call to return a success so the form submits cleanly.
    await page.route('**/admin-ajax.php', async (route) => {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { message: 'Request received.' } }),
      });
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });

    await page.locator('[name="dsar_name"]').fill('Full Name');
    await page.locator('[name="dsar_email"]').fill('valid@example.com');
    await page.locator('[name="dsar_type"]').selectOption('access');

    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dsar-btn').click(),
    ]);
    await page.waitForTimeout(200);

    // On success the form hides and the notice shows with class "success".
    const cls = await page.locator('.faz-dsar-notice').getAttribute('class') ?? '';
    expect(cls, 'notice must have "success" class when all fields are valid').toContain('success');

    await page.unrouteAll();
  });
});

// ── DSAR-VAL-04: Email format validation ─────────────────────────────────────

test.describe('DSAR-VAL-04 — client-side email format validation', () => {
  test.describe.configure({ mode: 'serial' });

  const invalidEmails = [
    'not-an-email',
    'missing@domain',
    '@nodomain.com',
    'spaces in@email.com',
    'double@@at.com',
  ];

  for (const badEmail of invalidEmails) {
    test(`"${badEmail}" is rejected before network call`, async ({ page }) => {
      let networkCallMade = false;
      await page.route('**/admin-ajax.php', async (route) => {
        networkCallMade = true;
        await route.abort();
      });

      await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });

      await page.locator('[name="dsar_name"]').fill('Test User');
      await page.locator('[name="dsar_email"]').fill(badEmail);
      await page.locator('[name="dsar_type"]').selectOption('access');

      await page.locator('.faz-dsar-btn').click();
      await page.waitForTimeout(100);

      const notice = page.locator('.faz-dsar-notice');
      await expect(notice).toBeVisible();

      const text = await notice.textContent() ?? '';
      expect(text.toLowerCase(), `"${badEmail}" must trigger email validation error`).toContain('email');

      expect(networkCallMade, 'no AJAX call must be made when email is invalid').toBe(false);
      await page.unrouteAll();
    });
  }

  test('valid email "user@example.com" passes format check and reaches network', async ({ page }) => {
    let networkCallMade = false;
    await page.route('**/admin-ajax.php', async (route) => {
      networkCallMade = true;
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { message: 'Done.' } }),
      });
    });

    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.faz-dsar-form', { timeout: 10_000 });

    await page.locator('[name="dsar_name"]').fill('Test User');
    await page.locator('[name="dsar_email"]').fill('user@example.com');
    await page.locator('[name="dsar_type"]').selectOption('access');

    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dsar-btn').click(),
    ]);

    expect(networkCallMade, 'valid email must allow the AJAX call to proceed').toBe(true);
    await page.unrouteAll();
  });
});
