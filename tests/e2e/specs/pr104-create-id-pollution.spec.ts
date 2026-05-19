/**
 * PR #104 — regression for the post-create banner_id pollution bug.
 *
 * Symptom (prod, fabiodalez.it 2026-05-18): admin creates a new banner via
 * the "+ New banner" modal, the REST POST succeeds (row appears in
 * wp_faz_banners with the correct auto-increment id), but the REST
 * response carries an id from a wholly unrelated table — e.g. 2,513,570
 * which matched wp_options.auto_increment at the time. The admin gets
 * redirected to ?banner_id=2513570, a row that does not exist, and sees
 * an empty editor.
 *
 * Root cause: Controller::create_item() re-read $wpdb->insert_id AFTER
 * $banner->save(). save() goes through the update path (the row already
 * exists from the line-167 $wpdb->insert), and the downstream cache /
 * option / transient writes triggered by the wp_options inserts inside
 * do_action('faz_after_update_banner') and any wp_cache backend that
 * upserts via INSERT pollute $wpdb->insert_id with the auto-increment of
 * an unrelated table.
 *
 * Fix: drop the second `$banner->set_id( $wpdb->insert_id )` — the
 * earlier `$banner->set_id( $id )` (where $id = $wpdb->insert_id captured
 * IMMEDIATELY after the faz_banners insert) is already correct.
 *
 * Regression guard:
 *   1. Capture the next expected banner_id (faz_banners.AUTO_INCREMENT).
 *   2. Bump wp_options.AUTO_INCREMENT to a number far above faz_banners
 *      so any leakage is visible.
 *   3. POST a new banner via the REST API as an admin.
 *   4. Assert the response id matches the DB row id (NOT wp_options).
 *   5. Cleanup.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

test.describe('PR104 — banner-create REST id pollution', () => {
  test('POST /faz/v1/banners returns the actual banners-table id, not a leaked auto_increment', () => {
    const raw = wpEval(`
      global $wpdb;
      $admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
      wp_set_current_user( $admin[0]->ID );

      // 1. Snapshot the faz_banners next-id so we know what id the new row
      //    will get.
      $banners_table = $wpdb->prefix . 'faz_banners';
      $next_banner_id = (int) $wpdb->get_var(
        "SELECT auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$banners_table}'"
      );

      // 2. Insert a placeholder row into wp_options so wp_options.AUTO_INCREMENT
      //    jumps far above faz_banners. If create_item() re-reads
      //    $wpdb->insert_id post-save, the leaked id will match (or be
      //    near) wp_options.AUTO_INCREMENT and the assertion will fail.
      $marker_name = 'faz_test_id_pollution_marker_' . time();
      add_option( $marker_name, 'marker' );
      $wp_options_ai = (int) $wpdb->get_var(
        "SELECT auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$wpdb->options}'"
      );

      // 3. POST through the REST API with the same payload shape the JS
      //    modal uses (configs + contents from the active banner).
      $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $configs = $ctrl->get_default_configs( 'gdpr' );

      $req = new WP_REST_Request( 'POST', '/faz/v1/banners' );
      $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      $req->set_param( 'name', 'pollution-regression' );
      $req->set_param( 'status', true );
      $req->set_param( 'default', false );
      $req->set_param( 'properties', $configs );
      $req->set_param( 'contents', array() );
      $req->set_param( 'target_countries', array( 'DE' ) );
      $req->set_param( 'priority', 0 );
      $resp = rest_do_request( $req );

      $status = $resp->get_status();
      $data   = $resp->get_data();
      $returned_id = is_object( $data ) ? (int) $data->id : (int) $data['id'];

      // 4. Confirm the row landed at the expected auto-increment slot.
      $row_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT banner_id FROM {$banners_table} WHERE name = %s ORDER BY banner_id DESC LIMIT 1",
        'pollution-regression'
      ) );

      // 5. Cleanup — order matters: delete the test banner AND the marker.
      $wpdb->delete( $banners_table, array( 'banner_id' => $row_id ) );
      delete_option( $marker_name );

      echo wp_json_encode( array(
        'status'           => $status,
        'next_banner_id'   => $next_banner_id,
        'wp_options_ai'    => $wp_options_ai,
        'returned_id'      => $returned_id,
        'row_id_in_table'  => $row_id,
      ) );
    `).trim();

    const r = JSON.parse(raw);
    expect(r.status, 'REST POST should succeed').toBe(200);
    // The exact id depends on the auto_increment at test time, but it
    // MUST match the row's banner_id and MUST NOT be anywhere near the
    // wp_options auto-increment.
    expect(
      r.returned_id,
      `REST returned id=${r.returned_id} but actual DB row id=${r.row_id_in_table}`
    ).toBe(r.row_id_in_table);
    expect(
      r.returned_id,
      `id leaked from wp_options (returned=${r.returned_id}, wp_options.AI=${r.wp_options_ai})`
    ).toBeLessThan(r.wp_options_ai);
    // Sanity: the returned id is the expected faz_banners next slot
    // (or a near successor, since other tests can have created rows in
    // between).
    expect(r.returned_id, 'returned id is the faz_banners auto-increment slot').toBeGreaterThanOrEqual(
      r.next_banner_id
    );
  });
});
