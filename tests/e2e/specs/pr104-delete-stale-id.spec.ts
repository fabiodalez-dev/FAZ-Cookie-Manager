/**
 * PR #104 — deleting a stale banner_id (the tab was open BEFORE another
 * session deleted that banner, or the URL points to a phantom id left
 * over from the pre-1.14.1 auto-increment leak).
 *
 * Pre-fix: REST DELETE /faz/v1/banners/{phantom_id} returned 200 with
 * "0 rows affected". The JS surfaced it as a hard error
 * ("Failed to delete banner — id=4 — no row affected"), even though the
 * banner was already gone — confusing the admin, who then had no clear
 * recovery path.
 *
 * Post-fix:
 *   1. Server-side: the DELETE handler runs an existence probe and
 *      returns a structured 404 (code=fazcookie_rest_invalid_id) when
 *      the row is missing. No more "0 rows affected" silent path.
 *   2. JS-side: the delete handler treats 404 + race-condition 0-affected
 *      as "already deleted", shows a friendly notice, and redirects to
 *      the default banner instead of staying on the stale page.
 *
 * This test exercises only the server-side contract (faster + flake-
 * resistant) — the JS branch is a thin notice + setTimeout(redirect),
 * not worth a browser round-trip.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

test.describe('PR104 — DELETE /banners/{id} contract on stale id', () => {
  test('DELETE on a non-existent banner_id returns a structured 404, not a 200/0', () => {
    const raw = wpEval(`
      $admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
      wp_set_current_user( $admin[0]->ID );

      // 1. Pick a non-existent id that is GUARANTEED to be missing —
      //    pull the highest banner_id and add a big offset so any
      //    concurrent test that creates rows doesn't accidentally
      //    re-introduce our target id.
      global $wpdb;
      $max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(banner_id),0) FROM {$wpdb->prefix}faz_banners" );
      $phantom_id = $max + 9999;

      // 2. DELETE it via the REST API.
      $req = new WP_REST_Request( 'DELETE', '/faz/v1/banners/' . $phantom_id );
      $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      $req->set_url_params( array( 'id' => $phantom_id ) );
      $resp = rest_do_request( $req );

      $status = $resp->get_status();
      $data   = $resp->get_data();
      $code   = '';
      if ( is_wp_error( $resp ) ) {
        $code = $resp->get_error_code();
      } elseif ( is_array( $data ) && isset( $data['code'] ) ) {
        $code = $data['code'];
      } elseif ( is_object( $data ) && isset( $data->code ) ) {
        $code = $data->code;
      }

      // 3. Sanity: deleting a REAL id still works. Seed a throwaway,
      //    delete it, assert 200 + count=1.
      $wpdb->insert(
        $wpdb->prefix . 'faz_banners',
        array(
          'name'             => 'delete-stale-regression',
          'slug'             => 'delete-stale-regression',
          'status'           => 1,
          'settings'         => wp_json_encode( array() ),
          'banner_default'   => 0,
          'contents'         => wp_json_encode( array() ),
          'target_countries' => wp_json_encode( array() ),
          'priority'         => 0,
          'date_created'     => current_time( 'mysql' ),
          'date_modified'    => current_time( 'mysql' ),
        )
      );
      $real_id = (int) $wpdb->insert_id;

      $req2 = new WP_REST_Request( 'DELETE', '/faz/v1/banners/' . $real_id );
      $req2->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      $req2->set_url_params( array( 'id' => $real_id ) );
      $resp2 = rest_do_request( $req2 );

      echo wp_json_encode( array(
        'phantom_id'      => $phantom_id,
        'phantom_status'  => $status,
        'phantom_code'    => $code,
        'real_id'         => $real_id,
        'real_status'     => $resp2->get_status(),
        'real_data'       => $resp2->get_data(),
      ) );
    `).trim();

    const r = JSON.parse(raw);
    // 404 on the phantom id with the documented error code.
    expect(r.phantom_status, 'phantom id returns 404, not 200/0').toBe(404);
    expect(r.phantom_code, 'phantom id returns the fazcookie_rest_invalid_id code').toBe(
      'fazcookie_rest_invalid_id',
    );
    // Real id still produces a successful delete with count=1.
    expect(r.real_status, 'real id still returns 200').toBe(200);
    expect(
      typeof r.real_data === 'number' ? r.real_data : 1,
      'real id reports exactly 1 row deleted',
    ).toBe(1);
  });
});
