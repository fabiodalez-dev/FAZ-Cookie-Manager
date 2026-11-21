/**
 * E2E tests for Cookie REST API security fixes applied in the adamsreview below-gate pass:
 *
 *   COOKIE-SEC-01  sanitize_script_field: non-admin user gets 403 WP_Error (not silent preserve)
 *   COOKIE-DATA-01 get_prepared_data: opt_in_script / opt_out_script absent from base data
 *   COOKIE-DATA-02 get_script_data: script fields available on explicit call
 *   COOKIE-DATA-03 REST API edit context: script fields present for admin via get_formatted_item_data
 */

import { expect } from '@playwright/test';
import { test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

// ── Helpers ──────────────────────────────────────────────────────────────────

function getFirstCookieId(): number {
  const raw = wpEval(`
    global $wpdb;
    echo (int) $wpdb->get_var( "SELECT cookie_id FROM {$wpdb->prefix}faz_cookies LIMIT 1" );
  `).trim();
  return parseInt(raw, 10);
}

// ── COOKIE-SEC-01 ─────────────────────────────────────────────────────────────

test.describe('COOKIE-SEC-01 — non-admin user gets 403 when saving script fields', () => {
  test.describe.configure({ mode: 'serial' });

  let cookieId = 0;
  let subscriberId = 0;

  test.beforeAll(() => {
    cookieId = getFirstCookieId();

    // Create a one-off subscriber for this test; clean up in afterAll.
    const raw = wpEval(`
      $uid = wp_insert_user( array(
        'user_login' => 'faz_e2e_sub_' . substr( uniqid(), -8 ),
        'user_pass'  => 'password',
        'role'       => 'subscriber',
      ) );
      echo is_wp_error( $uid ) ? 0 : (int) $uid;
    `).trim();
    subscriberId = parseInt(raw, 10);
  });

  test.afterAll(() => {
    if (subscriberId) {
      wpEval(`wp_delete_user( ${subscriberId} );`);
    }
  });

  test('subscriber PUT with opt_in_script returns HTTP 403', () => {
    expect(cookieId, 'a cookie must exist in the DB to run this test').toBeGreaterThan(0);
    expect(subscriberId, 'subscriber user must have been created').toBeGreaterThan(0);

    const status = wpEval(`
      wp_set_current_user( ${subscriberId} );
      $nonce = wp_create_nonce( 'wp_rest' );
      $req = new WP_REST_Request( 'PUT', '/faz/v1/cookies/${cookieId}' );
      $req->set_header( 'X-WP-Nonce', $nonce );
      $req->set_param( 'opt_in_script', 'alert(1)' );
      $resp = rest_do_request( $req );
      echo $resp->get_status();
    `).trim();

    expect(parseInt(status, 10), 'subscriber opt_in_script update must return 403').toBe(403);
  });

  test('subscriber PUT with opt_out_script returns HTTP 403', () => {
    expect(cookieId).toBeGreaterThan(0);
    expect(subscriberId).toBeGreaterThan(0);

    const status = wpEval(`
      wp_set_current_user( ${subscriberId} );
      $nonce = wp_create_nonce( 'wp_rest' );
      $req = new WP_REST_Request( 'PUT', '/faz/v1/cookies/${cookieId}' );
      $req->set_header( 'X-WP-Nonce', $nonce );
      $req->set_param( 'opt_out_script', 'alert(2)' );
      $resp = rest_do_request( $req );
      echo $resp->get_status();
    `).trim();

    expect(parseInt(status, 10), 'subscriber opt_out_script update must return 403').toBe(403);
  });

  test('admin PUT with opt_in_script is accepted (does not return 403)', () => {
    // Sanity-check: the admin (user ID 1) must NOT be rejected.
    expect(cookieId).toBeGreaterThan(0);

    const status = wpEval(`
      wp_set_current_user( 1 );
      $nonce = wp_create_nonce( 'wp_rest' );
      $req = new WP_REST_Request( 'PUT', '/faz/v1/cookies/${cookieId}' );
      $req->set_header( 'X-WP-Nonce', $nonce );
      $req->set_param( 'opt_in_script', '' );
      $resp = rest_do_request( $req );
      echo $resp->get_status();
    `).trim();

    const code = parseInt(status, 10);
    expect(code, 'admin opt_in_script update must not return 403').not.toBe(403);
    // 200 or 201 are both valid success codes.
    expect(code).toBeGreaterThanOrEqual(200);
    expect(code).toBeLessThan(300);
  });
});

// ── COOKIE-SEC-02 ─────────────────────────────────────────────────────────────
//
// A user with manage_options but without unfiltered_html (a multisite site
// admin, DISALLOW_UNFILTERED_HTML). The refusal must be a 403 from the
// permission check: WordPress sanitises before the permission callback and
// turns a sanitiser's WP_Error into a 400, so a sanitiser-level gate answered
// 400. Sending back the script the cookie already stores is not a write of
// new JS and must not block that user from editing the other fields.

test.describe('COOKIE-SEC-02 — manage_options without unfiltered_html', () => {
  test.describe.configure({ mode: 'serial' });

  let cookieId = 0;
  let savedMeta = '';
  const noUnfiltered = `add_filter( 'map_meta_cap', function ( $caps, $cap ) { return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps; }, 10, 2 );`;

  function put(params: Record<string, string>, asLowPriv: boolean): string {
    const sets = Object.entries(params)
      .map(([k, v]) => `$req->set_param( ${JSON.stringify(k)}, ${JSON.stringify(v)} );`)
      .join('\n');
    return wpEval(`
      ${asLowPriv ? noUnfiltered : ''}
      wp_set_current_user( 1 );
      $req = new WP_REST_Request( 'PUT', '/faz/v1/cookies/${cookieId}' );
      $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      ${sets}
      echo rest_do_request( $req )->get_status();
    `).trim();
  }

  function storedScript(): string {
    return wpEval(`
      global $wpdb;
      $m = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$wpdb->prefix}faz_cookies WHERE cookie_id = %d", ${cookieId} ) ), true );
      echo is_array( $m ) && isset( $m['opt_in_script'] ) ? $m['opt_in_script'] : '';
    `).trim();
  }

  test.beforeAll(() => {
    cookieId = getFirstCookieId();
    savedMeta = wpEval(`
      global $wpdb;
      echo base64_encode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$wpdb->prefix}faz_cookies WHERE cookie_id = %d", ${cookieId} ) ) );
    `).trim();
  });

  test.afterAll(() => {
    if (cookieId) {
      wpEval(`
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}faz_cookies", array( 'meta' => base64_decode( '${savedMeta}' ) ), array( 'cookie_id' => ${cookieId} ) );
        $wpdb->delete( "{$wpdb->prefix}faz_cookies", array( 'name' => 'faz_sec02_probe' ) );
        if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
      `);
    }
  });

  test('an administrator stores a script', () => {
    expect(cookieId).toBeGreaterThan(0);
    const code = parseInt(put({ opt_in_script: 'window.fazSec02 = 1;' }, false), 10);
    expect(code).toBeGreaterThanOrEqual(200);
    expect(code).toBeLessThan(300);
    expect(storedScript()).toBe('window.fazSec02 = 1;');
  });

  test('a new script from that user is refused with 403, not 400', () => {
    expect(parseInt(put({ opt_in_script: 'alert(3)' }, true), 10)).toBe(403);
    expect(storedScript()).toBe('window.fazSec02 = 1;');
  });

  test('a create with a script from that user is refused with 403', () => {
    const status = wpEval(`
      ${noUnfiltered}
      wp_set_current_user( 1 );
      $req = new WP_REST_Request( 'POST', '/faz/v1/cookies' );
      $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      $req->set_param( 'name', 'faz_sec02_probe' );
      $req->set_param( 'opt_in_script', 'alert(4)' );
      echo rest_do_request( $req )->get_status();
    `).trim();
    expect(parseInt(status, 10)).toBe(403);
  });

  test('the stored script sent back unchanged does not block the save, and survives it', () => {
    const code = parseInt(put({ opt_in_script: 'window.fazSec02 = 1;', opt_out_script: '' }, true), 10);
    expect(code).toBeGreaterThanOrEqual(200);
    expect(code).toBeLessThan(300);
    expect(storedScript()).toBe('window.fazSec02 = 1;');
  });
});

// ── COOKIE-DATA-01 / 02 / 03 ─────────────────────────────────────────────────

test.describe('COOKIE-DATA — script field isolation in Cookie class', () => {
  test.describe.configure({ mode: 'serial' });

  let cookieId = 0;

  test.beforeAll(() => {
    cookieId = getFirstCookieId();
  });

  test('COOKIE-DATA-01: get_prepared_data() does not include opt_in_script or opt_out_script', () => {
    expect(cookieId).toBeGreaterThan(0);

    const raw = wpEval(`
      $cookie = new \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie( ${cookieId} );
      echo wp_json_encode( array_keys( $cookie->get_prepared_data() ) );
    `).trim();

    const keys: string[] = JSON.parse(raw);
    expect(keys, 'get_prepared_data() must not contain opt_in_script').not.toContain('opt_in_script');
    expect(keys, 'get_prepared_data() must not contain opt_out_script').not.toContain('opt_out_script');

    // Sanity-check: core fields must still be present.
    expect(keys).toContain('id');
    expect(keys).toContain('name');
    expect(keys).toContain('slug');
  });

  test('COOKIE-DATA-02: get_script_data() returns only opt_in_script and opt_out_script', () => {
    expect(cookieId).toBeGreaterThan(0);

    const raw = wpEval(`
      $cookie = new \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie( ${cookieId} );
      echo wp_json_encode( array_keys( $cookie->get_script_data() ) );
    `).trim();

    const keys: string[] = JSON.parse(raw);
    expect(keys, 'get_script_data() must contain opt_in_script').toContain('opt_in_script');
    expect(keys, 'get_script_data() must contain opt_out_script').toContain('opt_out_script');

    // The method must NOT return unrelated fields.
    expect(keys, 'get_script_data() must not contain id').not.toContain('id');
    expect(keys, 'get_script_data() must not contain name').not.toContain('name');
  });

  test('COOKIE-DATA-03: REST GET /faz/v1/cookies/{id}?context=edit includes script fields for admin', () => {
    expect(cookieId).toBeGreaterThan(0);

    const raw = wpEval(`
      wp_set_current_user( 1 );
      $req = new WP_REST_Request( 'GET', '/faz/v1/cookies/${cookieId}' );
      $req->set_param( 'context', 'edit' );
      $resp = rest_do_request( $req );
      $data = $resp->get_data();
      echo wp_json_encode( array_keys( is_array( $data ) ? $data : array() ) );
    `).trim();

    const keys: string[] = JSON.parse(raw);
    expect(keys, 'REST edit context must include opt_in_script for admin').toContain('opt_in_script');
    expect(keys, 'REST edit context must include opt_out_script for admin').toContain('opt_out_script');
  });

  test('COOKIE-DATA-04: REST GET /faz/v1/cookies/{id} (view context) excludes script fields', () => {
    expect(cookieId).toBeGreaterThan(0);

    // Public / view context should not expose raw JS — the REST schema registers
    // opt_in_script / opt_out_script with context: ['edit'] so WP strips them
    // from the response automatically when context=view.
    const raw = wpEval(`
      wp_set_current_user( 0 );
      $req = new WP_REST_Request( 'GET', '/faz/v1/cookies/${cookieId}' );
      $req->set_param( 'context', 'view' );
      $resp = rest_do_request( $req );
      $data = $resp->get_data();
      echo wp_json_encode( array_keys( is_array( $data ) ? $data : array() ) );
    `).trim();

    // Either: response is 401 (unauthenticated) or the keys array lacks the script fields.
    let keys: string[] = [];
    try { keys = JSON.parse(raw); } catch { /* 401 → empty body or WP_Error serialised */ }
    expect(keys, 'REST view context must not contain opt_in_script').not.toContain('opt_in_script');
    expect(keys, 'REST view context must not contain opt_out_script').not.toContain('opt_out_script');
  });
});
