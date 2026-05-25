/**
 * E2E tests for experimental shortcodes introduced in the feat/experimental-features branch.
 *
 * Covers:
 *   - [faz_do_not_sell]  — CCPA "Do Not Sell My Personal Information" opt-out form (tests 1-12)
 *   - [faz_dsar_form]    — GDPR Data Subject Access Request form (tests 13-25)
 *
 * Setup: two WordPress pages are created in beforeAll via WP-CLI and torn down
 * in afterAll.  The pages use the plugin shortcodes as their entire content.
 */

import { expect, type Page } from '@playwright/test';
import { test } from '../fixtures/wp-fixture';
import { upsertPage, wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

// Match the specific FAZ admin-ajax response and ignore concurrent admin-ajax
// traffic from analytics/tracking plugins loaded on the test site (burst, IAWP,
// independent-analytics, wp-slimstat, etc.) whose non-JSON responses would
// otherwise be captured by a generic waitForResponse('**/admin-ajax.php')
// matcher.
//
// faz-dsar.js submits via `new FormData(form)` which serialises as
// multipart/form-data, so the request body has the shape
//   ------WebKitFormBoundaryXXX
//   Content-Disposition: form-data; name="action"
//
//   faz_dsar_submit
//   ------WebKitFormBoundaryXXX
//   …
// A plain `action=<value>` substring search would never match that — we look
// for `name="action"` followed by the action token instead, and also accept
// the URL-encoded form for callers that POST with application/x-www-form-urlencoded.
function waitForFazAjax(page: Page, action: string) {
  // Escape regex metacharacters in the action token before interpolating it
  // into the multipart matcher. Current call sites pass safe identifiers
  // (`faz_dsar_submit`, `faz_dnsmpi_optout`) but treating the parameter as
  // untrusted input keeps the helper future-proof.
  const escapedAction = action.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  // URL-encoded body: anchor with a word-boundary suffix so an action like
  // `faz_dsar` doesn't false-positive a longer `faz_dsar_submit_v2`. The
  // `action=` token may appear at the start of the body OR after a previous
  // pair separated by `&` — start-of-string or `&` are the only valid
  // prefixes in `application/x-www-form-urlencoded`.
  const urlEncodedRe = new RegExp(`(?:^|&)action=${escapedAction}(?:&|$)`);
  // multipart/form-data: locate the form-data part with name="action" and
  // check the value that follows the blank line. The `\b` suffix ensures the
  // action token ends at a word boundary (CRLF, end-of-part marker, …).
  const multipartRe = new RegExp(`name="action"\\s*\\r?\\n\\s*\\r?\\n\\s*${escapedAction}\\b`);
  return page.waitForResponse((r) => {
    if (!r.url().includes('admin-ajax.php')) {
      return false;
    }
    const body = r.request().postData() ?? '';
    return urlEncodedRe.test(body) || multipartRe.test(body);
  });
}

const CCPA_SLUG = 'faz-e2e-do-not-sell';
const DSAR_SLUG = 'faz-e2e-dsar-form';

function getPermalink(slug: string): string {
  return wpEval(`
    $page = get_page_by_path( '${slug}', OBJECT, 'page' );
    echo $page ? get_permalink( $page->ID ) : '';
  `).trim();
}

function deleteDsarPosts(): void {
  wpEval(`
    $posts = get_posts( array( 'post_type' => 'faz_dsar', 'numberposts' => -1, 'post_status' => 'private' ) );
    foreach ( $posts as $post ) { wp_delete_post( $post->ID, true ); }
  `);
}

function clearOptoutLogs(): void {
  wpEval(`
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'faz_consent_logs', array( 'status' => 'dnsmpi_optout' ), array( '%s' ) );
  `);
}

function clearRateLimitTransients(): void {
  wpEval(`
    global $wpdb;
    $rows = $wpdb->get_col(
      "SELECT option_name FROM {$wpdb->options}
       WHERE option_name LIKE '_transient_faz_dsar_%'
          OR option_name LIKE '_transient_faz_dnsmpi_%'"
    );
    $wpdb->query(
      "DELETE FROM {$wpdb->options}
       WHERE option_name LIKE '_transient_faz_dsar_%'
          OR option_name LIKE '_transient_faz_dnsmpi_%'
          OR option_name LIKE 'faz_dnsmpi_lock_%'"
    );
    foreach ( $rows as $opt ) {
      $key = preg_replace( '/^_transient_/', '', $opt );
      wp_cache_delete( $key, 'faz_rate_limit' );
    }
  `);
}

let ccpaUrl = '';
let dsarUrl = '';

// ─── Suite setup ─────────────────────────────────────────────────────────────

test.beforeAll(() => {
  upsertPage(CCPA_SLUG, 'FAZ E2E Do Not Sell', '[faz_do_not_sell]');
  upsertPage(DSAR_SLUG, 'FAZ E2E DSAR Form', '[faz_dsar_form]');
  ccpaUrl = getPermalink(CCPA_SLUG);
  dsarUrl = getPermalink(DSAR_SLUG);

  if (!ccpaUrl || !dsarUrl) {
    throw new Error(`Could not resolve permalinks. ccpaUrl=${ccpaUrl} dsarUrl=${dsarUrl}. Ensure pretty permalinks are enabled.`);
  }
});

test.afterAll(() => {
  deleteDsarPosts();
  clearOptoutLogs();
  clearRateLimitTransients();
});

// ─── [faz_do_not_sell] ───────────────────────────────────────────────────────

test.describe('[faz_do_not_sell] CCPA opt-out form', () => {

  // Pre-accept the consent banner so it does not cover the form or trap Tab focus.
  // Also clear any stale rate-limit transients so submission tests don't block each other.
  test.beforeEach(async ({ page, wpBaseURL }) => {
    clearRateLimitTransients();
    const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
    const domain = new URL(wpBaseURL).hostname;
    await page.context().addCookies([{
      name:     'fazcookie-consent',
      value:    `consentid%3Ae2e-ccpa-test%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2Cfunctional%3Ayes%2Canalytics%3Ayes%2Cperformance%3Ayes%2Cuncategorized%3Ayes%2Cmarketing%3Ayes%2Crev%3A${rev}`,
      domain,
      path:     '/',
      sameSite: 'Lax',
    }]);
  });

  test('CCPA-01: form renders with default title and submit button', async ({ page }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    const wrap = page.locator('.faz-dnsmpi-wrap');
    await expect(wrap).toBeVisible();
    await expect(wrap.locator('h3')).toContainText('Do Not Sell My Personal Information');
    await expect(wrap.locator('button[type="submit"].faz-dnsmpi-btn')).toBeVisible();
  });

  test('CCPA-02: form renders with custom title and button via attributes', async ({ page }) => {
    upsertPage('faz-e2e-do-not-sell-custom', 'FAZ E2E CCPA Custom', '[faz_do_not_sell title="Opt Out Now" button="Submit My Request"]');
    const customUrl = getPermalink('faz-e2e-do-not-sell-custom');
    await page.goto(customUrl, { waitUntil: 'domcontentloaded' });
    const wrap = page.locator('.faz-dnsmpi-wrap');
    await expect(wrap.locator('h3')).toContainText('Opt Out Now');
    await expect(wrap.locator('button[type="submit"]')).toContainText('Submit My Request');
  });

  test('CCPA-03: form contains hidden action and nonce fields', async ({ page }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    const form = page.locator('.faz-dnsmpi-form');
    await expect(form.locator('input[name="action"][value="faz_dnsmpi_optout"]')).toBeAttached();
    await expect(form.locator('input[name="nonce"]')).toBeAttached();
    const nonce = await form.locator('input[name="nonce"]').getAttribute('value');
    expect(nonce).toBeTruthy();
    expect(nonce!.length).toBeGreaterThan(5);
  });

  test('CCPA-04: submit button is enabled before submission', async ({ page }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    const btn = page.locator('.faz-dnsmpi-btn');
    await expect(btn).toBeEnabled();
  });

  test('CCPA-05: successful form submission shows success notice', async ({ page }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    const [response] = await Promise.all([
      waitForFazAjax(page, 'faz_dnsmpi_optout'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    const json = await response.json() as { success: boolean; data?: { message?: string } };
    expect(json.success).toBe(true);
    const notice = page.locator('.faz-dnsmpi-notice.success');
    await expect(notice).toBeVisible();
    await expect(notice).toContainText('opt-out');
  });

  test('CCPA-06: after successful submission the form is hidden', async ({ page }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    await Promise.all([
      waitForFazAjax(page, 'faz_dnsmpi_optout'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    await expect(page.locator('.faz-dnsmpi-form')).toBeHidden();
  });

  test('CCPA-07: opt-out cookie is set after submission', async ({ page, context }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    await Promise.all([
      waitForFazAjax(page, 'faz_dnsmpi_optout'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    const cookies = await context.cookies(WP_BASE);
    const optout = cookies.find((c) => c.name === 'fazcookie-dnsmpi');
    expect(optout).toBeDefined();
    expect(optout!.value).toBe('1');
    expect(optout!.expires).toBeGreaterThan(Date.now() / 1000 + 86400 * 364);
  });

  test('CCPA-08: visitor with opt-out cookie sees confirmation instead of form', async ({ page, context }) => {
    await context.addCookies([{
      name: 'fazcookie-dnsmpi',
      value: '1',
      url: WP_BASE,
    }]);
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.faz-dnsmpi-form')).not.toBeAttached();
    await expect(page.locator('.faz-dnsmpi-notice.success')).toBeVisible();
  });

  test('CCPA-09: request with tampered (empty) nonce is rejected', async ({ page }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="nonce"]').evaluate((el: HTMLInputElement) => { el.value = 'invalid-nonce'; });
    const [response] = await Promise.all([
      waitForFazAjax(page, 'faz_dnsmpi_optout'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    const json = await response.json() as { success: boolean };
    expect(json.success).toBe(false);
  });

  test('CCPA-10: button is disabled while request is in flight', async ({ page }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    const btn = page.locator('.faz-dnsmpi-btn');
    const responsePromise = waitForFazAjax(page, 'faz_dnsmpi_optout');
    await btn.click();
    await expect(btn).toBeDisabled();
    await responsePromise;
  });

  test('CCPA-11: form is responsive at 375px viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    const wrap = page.locator('.faz-dnsmpi-wrap');
    await expect(wrap).toBeVisible();
    const box = await wrap.boundingBox();
    expect(box).toBeTruthy();
    expect(box!.width).toBeLessThanOrEqual(375);
  });

  test('CCPA-12: submit button is reachable via keyboard Tab + Enter', async ({ page }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('.faz-dnsmpi-wrap').click();
    await page.keyboard.press('Tab');
    const focused = await page.evaluate(() => (document.activeElement as HTMLElement | null)?.className ?? '');
    expect(focused).toContain('faz-dnsmpi-btn');
  });

});

// ─── [faz_dsar_form] ─────────────────────────────────────────────────────────

test.describe('[faz_dsar_form] GDPR DSAR form', () => {

  // Pre-accept the consent banner so it does not cover the form.
  // The banner (faz-box-bottom-left) sits at the bottom of the viewport and
  // intercepts clicks on the DSAR submit button which lands at ~Y=513 in a
  // 1280x720 viewport.  Setting the fazcookie-consent cookie before each
  // navigation keeps the banner out of the DOM for all DSAR tests.
  // Also clear any stale rate-limit transients so submission tests don't block each other.
  test.beforeEach(async ({ page, wpBaseURL }) => {
    clearRateLimitTransients();
    const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
    const domain = new URL(wpBaseURL).hostname;
    await page.context().addCookies([{
      name:     'fazcookie-consent',
      value:    `consentid%3Ae2e-dsar-test%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2Cfunctional%3Ayes%2Canalytics%3Ayes%2Cperformance%3Ayes%2Cuncategorized%3Ayes%2Cmarketing%3Ayes%2Crev%3A${rev}`,
      domain,
      path:     '/',
      sameSite: 'Lax',
    }]);
  });

  test('DSAR-01: form renders with all required fields visible', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const form = page.locator('.faz-dsar-form');
    await expect(form).toBeVisible();
    await expect(form.locator('input[name="dsar_name"]')).toBeVisible();
    await expect(form.locator('input[name="dsar_email"]')).toBeVisible();
    await expect(form.locator('select[name="dsar_type"]')).toBeVisible();
    await expect(form.locator('textarea[name="dsar_message"]')).toBeVisible();
  });

  test('DSAR-02: dropdown contains all 6 GDPR right options', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const select = page.locator('select[name="dsar_type"]');
    const options = await select.locator('option[value]').all();
    const values = await Promise.all(options.map((o) => o.getAttribute('value')));
    const expected = ['access', 'erasure', 'portability', 'rectify', 'restrict', 'object'];
    for (const v of expected) {
      expect(values).toContain(v);
    }
  });

  test('DSAR-03: form contains hidden action and nonce fields', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const form = page.locator('.faz-dsar-form');
    await expect(form.locator('input[name="action"][value="faz_dsar_submit"]')).toBeAttached();
    const nonce = await form.locator('input[name="nonce"]').getAttribute('value');
    expect(nonce).toBeTruthy();
    expect(nonce!.length).toBeGreaterThan(5);
  });

  test('DSAR-04: honeypot field is present in DOM but visually hidden', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const hp = page.locator('.faz-dsar-honeypot');
    await expect(hp).toBeAttached();
    await expect(hp).toBeHidden();
    await expect(hp.locator('input[name="faz_hp_name"]')).toBeAttached();
  });

  test('DSAR-05: custom button label via attribute is reflected in DOM', async ({ page }) => {
    upsertPage('faz-e2e-dsar-custom', 'FAZ E2E DSAR Custom', '[faz_dsar_form button="Submit My Request"]');
    const customUrl = getPermalink('faz-e2e-dsar-custom');
    await page.goto(customUrl, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('button.faz-dsar-btn')).toContainText('Submit My Request');
  });

  test('DSAR-06: submitting empty form shows client-side validation error', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('button.faz-dsar-btn').click();
    const notice = page.locator('.faz-dsar-notice.error');
    await expect(notice).toBeVisible();
    await expect(notice).toContainText('required');
  });

  test('DSAR-07: missing name triggers validation error without AJAX call', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="dsar_email"]').fill('test@example.com');
    await page.locator('select[name="dsar_type"]').selectOption('access');
    let ajaxFired = false;
    page.on('request', (req) => { if (req.url().includes('admin-ajax.php')) { ajaxFired = true; } });
    await page.locator('button.faz-dsar-btn').click();
    await expect(page.locator('.faz-dsar-notice.error')).toBeVisible();
    expect(ajaxFired).toBe(false);
  });

  test('DSAR-08: missing email triggers validation error without AJAX call', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="dsar_name"]').fill('Test User');
    await page.locator('select[name="dsar_type"]').selectOption('erasure');
    let ajaxFired = false;
    page.on('request', (req) => { if (req.url().includes('admin-ajax.php')) { ajaxFired = true; } });
    await page.locator('button.faz-dsar-btn').click();
    await expect(page.locator('.faz-dsar-notice.error')).toBeVisible();
    expect(ajaxFired).toBe(false);
  });

  test('DSAR-09: missing request type triggers validation error without AJAX call', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="dsar_name"]').fill('Test User');
    await page.locator('input[name="dsar_email"]').fill('test@example.com');
    let ajaxFired = false;
    page.on('request', (req) => { if (req.url().includes('admin-ajax.php')) { ajaxFired = true; } });
    await page.locator('button.faz-dsar-btn').click();
    await expect(page.locator('.faz-dsar-notice.error')).toBeVisible();
    expect(ajaxFired).toBe(false);
  });

  test('DSAR-10: successful submission shows success notice', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="dsar_name"]').fill('Jane Doe');
    await page.locator('input[name="dsar_email"]').fill('jane@example.com');
    await page.locator('select[name="dsar_type"]').selectOption('access');
    const [response] = await Promise.all([
      waitForFazAjax(page, 'faz_dsar_submit'),
      page.locator('button.faz-dsar-btn').click(),
    ]);
    const json = await response.json() as { success: boolean; data?: { message?: string } };
    expect(json.success).toBe(true);
    const notice = page.locator('.faz-dsar-notice.success');
    await expect(notice).toBeVisible();
    await expect(notice).toContainText('30 days');
  });

  test('DSAR-11: after successful submission the form is hidden', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="dsar_name"]').fill('John Smith');
    await page.locator('input[name="dsar_email"]').fill('john@example.com');
    await page.locator('select[name="dsar_type"]').selectOption('erasure');
    await Promise.all([
      waitForFazAjax(page, 'faz_dsar_submit'),
      page.locator('button.faz-dsar-btn').click(),
    ]);
    await expect(page.locator('.faz-dsar-form')).toBeHidden();
  });

  test('DSAR-12: successful submission creates a faz_dsar private post', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const email = `e2e-${Date.now()}@example.com`;
    await page.locator('input[name="dsar_name"]').fill('E2E Tester');
    await page.locator('input[name="dsar_email"]').fill(email);
    await page.locator('select[name="dsar_type"]').selectOption('portability');
    await Promise.all([
      waitForFazAjax(page, 'faz_dsar_submit'),
      page.locator('button.faz-dsar-btn').click(),
    ]);
    const emailB64 = Buffer.from(email, 'utf8').toString('base64');
    const found = wpEval(`
      $email = base64_decode( '${emailB64}' );
      $posts = get_posts( array( 'post_type' => 'faz_dsar', 'numberposts' => 5, 'post_status' => 'private', 'orderby' => 'date', 'order' => 'DESC' ) );
      $found = false;
      foreach ( $posts as $p ) {
        if ( get_post_meta( $p->ID, '_dsar_email', true ) === $email ) {
          $found = true; break;
        }
      }
      echo $found ? 'yes' : 'no';
    `).trim();
    expect(found).toBe('yes');
  });

  test('DSAR-13: request with tampered nonce is rejected server-side', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="nonce"]').evaluate((el: HTMLInputElement) => { el.value = 'bad-nonce-value'; });
    await page.locator('input[name="dsar_name"]').fill('Hacker');
    await page.locator('input[name="dsar_email"]').fill('hacker@example.com');
    await page.locator('select[name="dsar_type"]').selectOption('access');
    const [response] = await Promise.all([
      waitForFazAjax(page, 'faz_dsar_submit'),
      page.locator('button.faz-dsar-btn').click(),
    ]);
    const json = await response.json() as { success: boolean };
    expect(json.success).toBe(false);
  });

  test('DSAR-14: honeypot field filled by bot causes server rejection', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="faz_hp_name"]').evaluate((el: HTMLInputElement) => { el.value = 'bot-content'; });
    await page.locator('input[name="dsar_name"]').fill('Bot User');
    await page.locator('input[name="dsar_email"]').fill('bot@example.com');
    await page.locator('select[name="dsar_type"]').selectOption('erasure');
    const [response] = await Promise.all([
      waitForFazAjax(page, 'faz_dsar_submit'),
      page.locator('button.faz-dsar-btn').click(),
    ]);
    const json = await response.json() as { success: boolean };
    expect(json.success).toBe(false);
  });

  test('DSAR-15: optional message textarea accepts and submits content', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="dsar_name"]').fill('Detail User');
    await page.locator('input[name="dsar_email"]').fill('detail@example.com');
    await page.locator('select[name="dsar_type"]').selectOption('rectify');
    await page.locator('textarea[name="dsar_message"]').fill('Please correct my date of birth.');
    const [response] = await Promise.all([
      waitForFazAjax(page, 'faz_dsar_submit'),
      page.locator('button.faz-dsar-btn').click(),
    ]);
    const json = await response.json() as { success: boolean };
    expect(json.success).toBe(true);
  });

  test('DSAR-16: form is responsive at 375px viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const form = page.locator('.faz-dsar-wrap');
    await expect(form).toBeVisible();
    const box = await form.boundingBox();
    expect(box).toBeTruthy();
    expect(box!.width).toBeLessThanOrEqual(375);
  });

  test('DSAR-17: all form fields reachable via keyboard Tab navigation', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="dsar_name"]').click();
    const fieldNames = ['dsar_name', 'dsar_email', 'dsar_type', 'dsar_message'];
    for (const name of fieldNames) {
      const focused = await page.evaluate(() => (document.activeElement as HTMLInputElement | null)?.name ?? '');
      expect(focused).toBe(name);
      await page.keyboard.press('Tab');
    }
  });

  test('DSAR-18: invalid request type is rejected server-side', async ({ page }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="dsar_name"]').fill('Attacker');
    await page.locator('input[name="dsar_email"]').fill('attacker@example.com');
    await page.locator('select[name="dsar_type"]').evaluate((el: HTMLSelectElement) => {
      const opt = document.createElement('option');
      opt.value = 'DROP TABLE';
      opt.text = 'Injection';
      el.appendChild(opt);
      el.value = 'DROP TABLE';
    });
    const [response] = await Promise.all([
      waitForFazAjax(page, 'faz_dsar_submit'),
      page.locator('button.faz-dsar-btn').click(),
    ]);
    const json = await response.json() as { success: boolean };
    expect(json.success).toBe(false);
  });

  test('DSAR-19: each of the 6 request types submits successfully', async ({ page }) => {
    const types = ['access', 'erasure', 'portability', 'rectify', 'restrict', 'object'];
    for (const type of types) {
      // Clear per-IP rate-limit transient before each iteration so every type can succeed.
      clearRateLimitTransients();
      await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
      await page.locator('input[name="dsar_name"]').fill('Loop Tester');
      await page.locator('input[name="dsar_email"]').fill(`loop-${type}@example.com`);
      await page.locator('select[name="dsar_type"]').selectOption(type);
      const [response] = await Promise.all([
        waitForFazAjax(page, 'faz_dsar_submit'),
        page.locator('button.faz-dsar-btn').click(),
      ]);
      const json = await response.json() as { success: boolean };
      expect(json.success, `Request type "${type}" should succeed`).toBe(true);
    }
  });

  test('DSAR-20: server rejects invalid email address format', async ({ page }) => {
    // Load the page to obtain a fresh nonce. Client-side JS (F028) now validates
    // email format before any fetch, so we bypass the form handler entirely and
    // POST directly to admin-ajax.php — this tests the server-side is_email()
    // check independently of client-side validation.
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const nonce = await page.locator('input[name="nonce"]').inputValue();
    const res = await page.request.post(`${WP_BASE}/wp-admin/admin-ajax.php`, {
      form: {
        action:       'faz_dsar_submit',
        nonce,
        dsar_name:    'Bad Email User',
        dsar_email:   'not-an-email',
        dsar_type:    'access',
        dsar_message: '',
      },
    });
    const json = await res.json() as { success: boolean };
    expect(json.success).toBe(false);
  });

});
