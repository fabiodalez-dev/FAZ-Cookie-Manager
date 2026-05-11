/**
 * E2E regression tests for security and quality fixes applied in the
 * CodeRabbit review of feat/experimental-features:
 *
 *  DSAR-SEC-01  SMTP header injection: name with CRLF is sanitized
 *  DSAR-SEC-02  Rate limiting: second DSAR submission blocked within 60 s
 *  CCPA-SEC-01  Rate limiting: second CCPA opt-out blocked within 60 s
 *  CCPA-SEC-02  httponly: fazcookie-dnsmpi is not readable by JS
 *  CCPA-DATA-01 Data quality: categories column stored as '' not NULL
 *  REST-ML-01   maxLength: opt_in_script > 10 000 chars rejected (400)
 *  REST-ML-02   maxLength: opt_out_script > 10 000 chars rejected (400)
 *  CACHE-01     Transient: _cookieScripts rebuilt from DB after deletion
 *  DSAR-VAL-01  Validation: invalid email returns wp_send_json_error
 *  DSAR-VAL-02  Validation: empty name returns wp_send_json_error
 */

import { expect } from '@playwright/test';
import { test } from '../fixtures/wp-fixture';
import { upsertPage, wpEval } from '../utils/wp-env';

// ─── Helpers ─────────────────────────────────────────────────────────────────

const CCPA_SLUG = 'faz-e2e-sqf-ccpa';
const DSAR_SLUG = 'faz-e2e-sqf-dsar';
const AJAX_URL_PATH = '/wp-admin/admin-ajax.php';

function getPermalink(slug: string): string {
  return wpEval(`
    $page = get_page_by_path( '${slug}', OBJECT, 'page' );
    echo $page ? get_permalink( $page->ID ) : '';
  `).trim();
}

function clearDsarPosts(): void {
  wpEval(`
    $posts = get_posts(array('post_type'=>'faz_dsar','numberposts'=>-1,'post_status'=>'private'));
    foreach($posts as $p){ wp_delete_post($p->ID, true); }
  `);
}

function clearOptoutLogs(): void {
  wpEval(`
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->prefix}faz_consent_logs WHERE status = 'dnsmpi_optout'");
  `);
}

function clearRateLimitTransients(): void {
  wpEval(`
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_faz_dsar_rl_%' OR option_name LIKE '_transient_faz_dnsmpi_rl_%'");
  `);
}

// ─── Suite setup ─────────────────────────────────────────────────────────────

let ccpaUrl = '';
let dsarUrl = '';

test.beforeAll(() => {
  upsertPage(CCPA_SLUG, 'FAZ SQF CCPA', '[faz_do_not_sell]');
  upsertPage(DSAR_SLUG, 'FAZ SQF DSAR', '[faz_dsar_form]');
  ccpaUrl = getPermalink(CCPA_SLUG);
  dsarUrl = getPermalink(DSAR_SLUG);
  if (!ccpaUrl || !dsarUrl) {
    throw new Error(`Could not resolve permalinks. ccpaUrl=${ccpaUrl} dsarUrl=${dsarUrl}. Ensure pretty permalinks are on.`);
  }
});

test.afterAll(() => {
  clearDsarPosts();
  clearOptoutLogs();
  clearRateLimitTransients();
});

// Pre-accept the consent banner so it doesn't interfere with form tests.
test.beforeEach(async ({ page, wpBaseURL }) => {
  const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
  const domain = new URL(wpBaseURL).hostname;
  await page.context().addCookies([{
    name:     'fazcookie-consent',
    value:    `consentid%3Ae2e-sqf%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2Cfunctional%3Ayes%2Canalytics%3Ayes%2Cperformance%3Ayes%2Cuncategorized%3Ayes%2Cmarketing%3Ayes%2Crev%3A${rev}`,
    domain,
    path:     '/',
    sameSite: 'Lax',
  }]);
});

// ─── DSAR security ────────────────────────────────────────────────────────────

test.describe('DSAR form — security fixes', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(() => clearRateLimitTransients());
  test.afterEach(() => {
    clearDsarPosts();
    clearRateLimitTransients();
  });

  test('DSAR-SEC-01: name with CRLF is sanitized and submission succeeds', async ({
    page, wpBaseURL,
  }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const nonce = await page.locator('form.faz-dsar-form input[name="nonce"]').inputValue();

    // Attempt to inject a second SMTP header via name field.
    const injectedName = "Legit Name\r\nX-Injected-Header: malicious";

    const res = await page.request.post(`${wpBaseURL}${AJAX_URL_PATH}`, {
      form: {
        action:       'faz_dsar_submit',
        nonce,
        dsar_name:    injectedName,
        dsar_email:   'test-sqf@example.com',
        dsar_type:    'access',
        dsar_message: '',
      },
    });

    const body = await res.json() as { success: boolean };
    // The server must accept the request — only the Reply-To header value is sanitized.
    expect(body.success, 'submission with CRLF name should succeed').toBe(true);

    // Verify the stored post title contains the name part before the CRLF
    // and does NOT contain raw CRLF sequences (sanitize_text_field strips them).
    const title = wpEval(`
      $posts = get_posts(array('post_type'=>'faz_dsar','numberposts'=>1,'orderby'=>'date','order'=>'DESC','post_status'=>'private'));
      echo $posts ? $posts[0]->post_title : '';
    `).trim();

    expect(title, 'post title should contain the sanitized name').toContain('Legit Name');
    expect(title, 'CRLF must not appear in stored post title').not.toContain('\r');
    expect(title, 'CRLF must not appear in stored post title').not.toContain('\n');
  });

  test('DSAR-SEC-02: rate limit blocks second submission within 60 seconds', async ({
    page, wpBaseURL,
  }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const nonce = await page.locator('form.faz-dsar-form input[name="nonce"]').inputValue();

    const formPayload = {
      action:       'faz_dsar_submit',
      nonce,
      dsar_name:    'Rate Limit Test',
      dsar_email:   'ratelimit@example.com',
      dsar_type:    'erasure',
      dsar_message: '',
    };

    // First submission — should succeed.
    const res1 = await page.request.post(`${wpBaseURL}${AJAX_URL_PATH}`, { form: formPayload });
    const body1 = await res1.json() as { success: boolean };
    expect(body1.success, 'first DSAR submission should succeed').toBe(true);

    // Second submission immediately after — must be blocked by the rate limiter.
    const res2 = await page.request.post(`${wpBaseURL}${AJAX_URL_PATH}`, { form: formPayload });
    const body2 = await res2.json() as { success: boolean; data?: string };
    expect(body2.success, 'second DSAR submission within 60 s must be blocked').toBe(false);
    expect(String(body2.data ?? '').toLowerCase(), 'error should mention too many requests').toContain('too many');
  });
});

// ─── CCPA security ────────────────────────────────────────────────────────────

test.describe('CCPA opt-out form — security fixes', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(() => clearRateLimitTransients());
  test.afterEach(() => {
    clearOptoutLogs();
    clearRateLimitTransients();
  });

  test('CCPA-SEC-01: rate limit blocks second opt-out within 60 seconds', async ({
    page, wpBaseURL,
  }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    const nonce = await page.locator('form.faz-dnsmpi-form input[name="nonce"]').inputValue();

    const formPayload = { action: 'faz_dnsmpi_optout', nonce };

    const res1 = await page.request.post(`${wpBaseURL}${AJAX_URL_PATH}`, { form: formPayload });
    const body1 = await res1.json() as { success: boolean };
    expect(body1.success, 'first CCPA opt-out should succeed').toBe(true);

    // Second opt-out same IP — must be rate-limited.
    // Navigate again to get a fresh nonce (previous nonce still valid; WordPress nonces
    // are time-window-based, not single-use).
    await page.context().clearCookies();
    await page.context().addCookies([{
      name:     'fazcookie-consent',
      value:    'consentid%3Ae2e-sqf%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes',
      domain:   '127.0.0.1',
      path:     '/',
      sameSite: 'Lax',
    }]);
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    const nonce2 = await page.locator('form.faz-dnsmpi-form input[name="nonce"]').inputValue().catch(() => nonce);

    const res2 = await page.request.post(`${wpBaseURL}${AJAX_URL_PATH}`, {
      form: { action: 'faz_dnsmpi_optout', nonce: nonce2 },
    });
    const body2 = await res2.json() as { success: boolean; data?: string };
    expect(body2.success, 'second CCPA opt-out within 60 s must be blocked').toBe(false);
    expect(String(body2.data ?? '').toLowerCase()).toContain('too many');
  });

  test('CCPA-SEC-02: opt-out cookie fazcookie-dnsmpi is httponly', async ({
    page, wpBaseURL,
  }) => {
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });
    const nonce = await page.locator('form.faz-dnsmpi-form input[name="nonce"]').inputValue();

    // Submit opt-out via the page's fetch handler so Set-Cookie is captured.
    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    await page.waitForTimeout(200);

    // Playwright exposes httpOnly via context.cookies() using CDP.
    const cookies = await page.context().cookies(`${wpBaseURL}/`);
    const optOutCookie = cookies.find(c => c.name === 'fazcookie-dnsmpi');

    expect(optOutCookie, 'fazcookie-dnsmpi must be set after opt-out').toBeTruthy();
    expect(optOutCookie!.httpOnly, 'fazcookie-dnsmpi must have httpOnly=true').toBe(true);

    // Also verify the cookie is NOT readable from JavaScript.
    const jsReadable = await page.evaluate(() => document.cookie.includes('fazcookie-dnsmpi'));
    expect(jsReadable, 'httponly cookie must not be accessible from document.cookie').toBe(false);
    void nonce;
  });
});

// ─── CCPA data quality ────────────────────────────────────────────────────────

test.describe('CCPA opt-out — data quality fix', () => {

  test.afterEach(() => {
    clearOptoutLogs();
    clearRateLimitTransients();
  });

  test('CCPA-DATA-01: opt-out consent log stores empty string for categories column', async ({
    page, wpBaseURL,
  }) => {
    clearRateLimitTransients();
    await page.goto(ccpaUrl, { waitUntil: 'domcontentloaded' });

    await Promise.all([
      page.waitForResponse('**/admin-ajax.php'),
      page.locator('.faz-dnsmpi-btn').click(),
    ]);
    await page.waitForTimeout(200);

    const result = wpEval(`
      global $wpdb;
      $row = $wpdb->get_row("SELECT categories FROM {$wpdb->prefix}faz_consent_logs WHERE status = 'dnsmpi_optout' ORDER BY log_id DESC LIMIT 1");
      echo $row ? var_export($row->categories, true) : 'NOT_FOUND';
    `).trim();

    // Must be an empty string '' — NOT NULL (which would be represented as 'NULL' in var_export).
    expect(result, 'categories column must be stored as empty string').toBe("''");
    void wpBaseURL;
  });
});

// ─── REST API maxLength enforcement ──────────────────────────────────────────

test.describe('REST API — opt_in/opt_out_script maxLength enforcement', () => {
  test.describe.configure({ mode: 'serial' });

  let analyticsCatId = 0;

  test.beforeAll(() => {
    analyticsCatId = parseInt(
      wpEval(`
        global $wpdb;
        echo (int) $wpdb->get_var(
          $wpdb->prepare(
            "SELECT category_id FROM {$wpdb->prefix}faz_cookie_categories WHERE slug = %s",
            'analytics'
          )
        );
      `).trim(),
      10,
    );
    expect(analyticsCatId, 'analytics category must exist in DB').toBeGreaterThan(0);
  });

  test('REST-ML-01: opt_in_script longer than 10 000 chars is rejected with 400', () => {
    // Use rest_do_request to exercise the full WP REST validation stack
    // (permission check + schema maxLength validation) without browser auth.
    const raw = wpEval(`
      wp_set_current_user(1);
      $nonce   = wp_create_nonce('wp_rest');
      $request = new WP_REST_Request('POST', '/faz/v1/cookies');
      $request->set_header('X-WP-Nonce', $nonce);
      $request['name']          = '_faz_ml_test';
      $request['slug']          = '_faz_ml_test';
      $request['domain']        = '127.0.0.1';
      $request['category']      = ${analyticsCatId};
      $request['duration']      = array('en' => 'session');
      $request['description']   = array('en' => 'maxLength test');
      $request['opt_in_script'] = str_repeat('x', 10001);
      $response = rest_do_request($request);
      echo $response->get_status();
    `);
    expect(
      parseInt(raw.trim(), 10),
      'REST must reject opt_in_script > 10 000 chars with HTTP 400',
    ).toBe(400);
  });

  test('REST-ML-02: opt_out_script longer than 10 000 chars is rejected with 400', () => {
    const raw = wpEval(`
      wp_set_current_user(1);
      $nonce   = wp_create_nonce('wp_rest');
      $request = new WP_REST_Request('POST', '/faz/v1/cookies');
      $request->set_header('X-WP-Nonce', $nonce);
      $request['name']           = '_faz_ml_test2';
      $request['slug']           = '_faz_ml_test2';
      $request['domain']         = '127.0.0.1';
      $request['category']       = ${analyticsCatId};
      $request['duration']       = array('en' => 'session');
      $request['description']    = array('en' => 'maxLength test 2');
      $request['opt_in_script']  = '';
      $request['opt_out_script'] = str_repeat('x', 10001);
      $response = rest_do_request($request);
      echo $response->get_status();
    `);
    expect(
      parseInt(raw.trim(), 10),
      'REST must reject opt_out_script > 10 000 chars with HTTP 400',
    ).toBe(400);
  });
});

// ─── Transient cache ─────────────────────────────────────────────────────────

test.describe('_cookieScripts transient cache invalidation', () => {
  test.describe.configure({ mode: 'serial' });

  let testCookieId = 0;

  test.beforeAll(() => {
    const analyticsCatId = parseInt(
      wpEval(`
        global $wpdb;
        echo (int) $wpdb->get_var(
          $wpdb->prepare(
            "SELECT category_id FROM {$wpdb->prefix}faz_cookie_categories WHERE slug = %s",
            'analytics'
          )
        );
      `).trim(),
      10,
    );
    expect(analyticsCatId, 'analytics category must exist in the DB').toBeGreaterThan(0);

    // Insert the test cookie directly via WP-CLI (avoids browser auth complexity).
    // Double-stringify produces a PHP string literal so PHP doesn't mis-parse {…}.
    const durationPhp = JSON.stringify(JSON.stringify({ en: 'session' }));
    const metaPhp = JSON.stringify(JSON.stringify({ opt_in_script: 'window._fazCacheE2E = true;' }));

    const raw = wpEval(`
      global $wpdb;
      $now = current_time('mysql');
      $wpdb->insert(
        "{$wpdb->prefix}faz_cookies",
        array(
          'name'          => '_faz_cache_test',
          'slug'          => '_faz_cache_test',
          'description'   => '',
          'duration'      => ${durationPhp},
          'domain'        => '127.0.0.1',
          'category'      => ${analyticsCatId},
          'type'          => '',
          'discovered'    => 0,
          'meta'          => ${metaPhp},
          'date_created'  => $now,
          'date_modified' => $now,
        ),
        array('%s','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s')
      );
      echo $wpdb->insert_id;
    `);
    testCookieId = parseInt(raw.trim(), 10);
    expect(testCookieId, 'test cookie must have been created').toBeGreaterThan(0);
    // Ensure the scripts-map transient is cleared so the new cookie is visible.
    wpEval(`delete_transient('faz_cookie_scripts_map');`);
  });

  test.afterAll(() => {
    if (testCookieId) {
      wpEval(`
        global $wpdb;
        $wpdb->delete(
          "{$wpdb->prefix}faz_cookies",
          array('cookie_id' => ${testCookieId}),
          array('%d')
        );
      `);
    }
    wpEval(`delete_transient('faz_cookie_scripts_map');`);
  });

  test('CACHE-01: _cookieScripts is rebuilt from DB after transient deletion', async ({ page, wpBaseURL }) => {
    expect(testCookieId, 'test cookie must have been created').toBeGreaterThan(0);

    // First load — populates the transient.
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    const firstLoad = await page.evaluate(
      () => (window as Record<string, unknown> & { _fazConfig?: { _cookieScripts?: unknown } })._fazConfig?._cookieScripts,
    );
    expect(firstLoad, '_cookieScripts must be present after first page load').toBeTruthy();

    // Delete the transient server-side — simulates expiry or cache flush.
    wpEval(`delete_transient('faz_cookie_scripts_map');`);

    // Second load — must rebuild from DB and produce identical data.
    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    const secondLoad = await page.evaluate(
      () => (window as Record<string, unknown> & { _fazConfig?: { _cookieScripts?: unknown } })._fazConfig?._cookieScripts,
    );

    expect(secondLoad, '_cookieScripts must be present after transient deletion').toBeTruthy();
    expect(JSON.stringify(secondLoad)).toEqual(JSON.stringify(firstLoad));
  });
});

// ─── DSAR input validation ────────────────────────────────────────────────────

test.describe('DSAR form — input validation', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(() => clearRateLimitTransients());
  test.afterEach(() => {
    clearDsarPosts();
    clearRateLimitTransients();
  });

  test('DSAR-VAL-01: submission with invalid email returns wp_send_json_error', async ({
    page, wpBaseURL,
  }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const nonce = await page.locator('form.faz-dsar-form input[name="nonce"]').inputValue();

    const res = await page.request.post(`${wpBaseURL}${AJAX_URL_PATH}`, {
      form: {
        action:       'faz_dsar_submit',
        nonce,
        dsar_name:    'Valid Name',
        dsar_email:   'not-an-email',
        dsar_type:    'access',
        dsar_message: '',
      },
    });

    const body = await res.json() as { success: boolean; data?: string };
    expect(body.success, 'invalid email must cause an error response').toBe(false);
    expect(body.data, 'error message must be non-empty').toBeTruthy();
  });

  test('DSAR-VAL-02: submission with empty name returns wp_send_json_error', async ({
    page, wpBaseURL,
  }) => {
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });
    const nonce = await page.locator('form.faz-dsar-form input[name="nonce"]').inputValue();

    const res = await page.request.post(`${wpBaseURL}${AJAX_URL_PATH}`, {
      form: {
        action:       'faz_dsar_submit',
        nonce,
        dsar_name:    '',
        dsar_email:   'valid@example.com',
        dsar_type:    'erasure',
        dsar_message: '',
      },
    });

    const body = await res.json() as { success: boolean; data?: string };
    expect(body.success, 'empty name must cause an error response').toBe(false);
    expect(body.data, 'error message must be non-empty').toBeTruthy();
  });
});
