/**
 * PR #104 post-review follow-up regressions (1.14.0).
 *
 * Covers the five fixes landed AFTER the main PR104 review + CodeRabbit
 * pass, in response to direct user feedback:
 *
 *   1. Inline banner rename — toolbar input PUTs to the REST API and
 *      the new name actually lands in the DB.
 *   2. Delete "×" hardening — DELETE response carries the affected
 *      row count and a post-delete GET no longer lists the banner.
 *   3. Consent-model selector — the /banners/configs endpoint exposes
 *      BOTH paradigms (gdpr + ccpa) so the new-banner modal can offer
 *      them.
 *   4. faz_country_to_language() — country→language mapping helper
 *      returns the expected ISO-639-1 codes and degrades gracefully
 *      on unknown / empty input.
 *   5. faz_use_country_language_fallback filter — the opt-in fallback
 *      kicks in only when the filter is ON AND the resolved language
 *      is among the admin's selected languages.
 *
 * All assertions go through wpEval (PHP-level reflection) so the tests
 * stay fast and deterministic — no live HTTP, no admin-page automation.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

/* ================================================================== *
 * 1. Inline banner rename
 * ================================================================== */

test.describe('PR104-FU — inline banner rename (REST PUT)', () => {
  test('PUT /banners/{id} with a changed name persists to the DB and round-trips back', () => {
    const result = wpEval(`
      $admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
      wp_set_current_user( ! empty( $admin_ids ) ? (int) $admin_ids[0] : 0 );

      $b = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      $bid = $b ? (int) $b->get_id() : 0;
      if ( $bid <= 0 ) { echo wp_json_encode( array( 'error' => 'NO_BANNER' ) ); return; }
      $original = $b->get_name();
      $renamed  = 'PR104-FU rename ' . time();

      $req = new WP_REST_Request( 'PUT', '/faz/v1/banners/' . $bid );
      $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      $req->set_param( 'name', $renamed );
      $req->set_param( 'status', $b->get_status() );
      $req->set_param( 'default', $b->get_default() );
      $req->set_param( 'properties', $b->get_settings() );
      $req->set_param( 'contents', $b->get_contents() );
      $res = rest_do_request( $req );
      $put_status = $res->get_status();

      $after = ( new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $bid ) )->get_name();

      // Restore the original name so the rest of the suite is unaffected.
      $b2 = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $bid );
      $req2 = new WP_REST_Request( 'PUT', '/faz/v1/banners/' . $bid );
      $req2->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      $req2->set_param( 'name', $original );
      $req2->set_param( 'status', $b2->get_status() );
      $req2->set_param( 'default', $b2->get_default() );
      $req2->set_param( 'properties', $b2->get_settings() );
      $req2->set_param( 'contents', $b2->get_contents() );
      rest_do_request( $req2 );

      echo wp_json_encode( array( 'put_status' => $put_status, 'renamed' => $renamed, 'after' => $after ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.put_status, 'PUT returns 200').toBe(200);
    expect(data.after, 'new name persisted in DB after PUT').toBe(data.renamed);
  });
});

/* ================================================================== *
 * 2. Delete "×" hardening — row count + post-verify
 * ================================================================== */

test.describe('PR104-FU — delete hardening', () => {
  test('DELETE /banners/{id} reports the affected row count and removes the row', () => {
    const result = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      $admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
      wp_set_current_user( ! empty( $admin_ids ) ? (int) $admin_ids[0] : 0 );

      // Seed a throwaway secondary banner so the test never touches the
      // user's real CCPA / GDPR rows. Cloned from the active banner so
      // the row has a valid settings/contents shape.
      $active = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      $now = current_time( 'mysql' );
      $wpdb->insert( $table, array(
        'name'             => 'PR104-FU delete probe',
        'slug'             => 'pr104-fu-delete-probe',
        'status'           => 0,
        'settings'         => wp_json_encode( $active->get_settings() ),
        'contents'         => wp_json_encode( $active->get_contents() ),
        'banner_default'   => 0,
        'target_countries' => wp_json_encode( array() ),
        'priority'         => 0,
        'date_created'     => $now,
        'date_modified'    => $now,
      ) );
      $probe_id = (int) $wpdb->insert_id;
      if ( $probe_id <= 0 ) { echo wp_json_encode( array( 'error' => 'SEED_FAIL' ) ); return; }

      $req = new WP_REST_Request( 'DELETE', '/faz/v1/banners/' . $probe_id );
      $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      $req->set_url_params( array( 'id' => $probe_id ) );
      $res = rest_do_request( $req );
      $body = $res->get_data();

      $still_there = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE banner_id = %d", $probe_id )
      );

      echo wp_json_encode( array(
        'delete_status' => $res->get_status(),
        'row_count'     => $body,
        'still_there'   => $still_there,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.delete_status, 'DELETE responds 200').toBe(200);
    expect(Number(data.row_count), 'response body reports >=1 row affected — frontend uses this').toBeGreaterThanOrEqual(1);
    expect(data.still_there, 'banner row is gone from the DB after the DELETE').toBe(0);
  });

  test('DELETE /banners/{id} promotes a fallback when the deleted row was the sole default', () => {
    const result = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      $admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
      wp_set_current_user( ! empty( $admin_ids ) ? (int) $admin_ids[0] : 0 );

      $controller = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $active = $controller->get_active_banner();
      if ( ! $active ) { echo wp_json_encode( array( 'error' => 'NO_ACTIVE' ) ); return; }
      $active_id = (int) $active->get_id();
      $now = current_time( 'mysql' );

      $wpdb->insert( $table, array(
        'name'             => 'PR104-FU delete default',
        'slug'             => 'pr104-fu-delete-default',
        'status'           => 1,
        'settings'         => wp_json_encode( $active->get_settings() ),
        'contents'         => wp_json_encode( $active->get_contents() ),
        'banner_default'   => 1,
        'target_countries' => wp_json_encode( array() ),
        'priority'         => 0,
        'date_created'     => $now,
        'date_modified'    => $now,
      ) );
      $default_id = (int) $wpdb->insert_id;

      $wpdb->insert( $table, array(
        'name'             => 'PR104-FU delete fallback',
        'slug'             => 'pr104-fu-delete-fallback',
        'status'           => 1,
        'settings'         => wp_json_encode( $active->get_settings() ),
        'contents'         => wp_json_encode( $active->get_contents() ),
        'banner_default'   => 0,
        'target_countries' => wp_json_encode( array() ),
        'priority'         => 0,
        'date_created'     => $now,
        'date_modified'    => $now,
      ) );
      $fallback_id = (int) $wpdb->insert_id;
      if ( $default_id <= 0 || $fallback_id <= 0 ) { echo wp_json_encode( array( 'error' => 'SEED_FAIL' ) ); return; }

      $wpdb->query( "UPDATE {$table} SET banner_default = 0" );
      $wpdb->update( $table, array( 'banner_default' => 1 ), array( 'banner_id' => $default_id ) );
      $controller->delete_cache();

      $req = new WP_REST_Request( 'DELETE', '/faz/v1/banners/' . $default_id );
      $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      $req->set_url_params( array( 'id' => $default_id ) );
      $res = rest_do_request( $req );

      $defaults_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE banner_default = 1" );
      $promoted_id = (int) $wpdb->get_var( "SELECT banner_id FROM {$table} WHERE banner_default = 1 LIMIT 1" );
      $deleted_still_there = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE banner_id = %d", $default_id )
      );

      $wpdb->delete( $table, array( 'banner_id' => $fallback_id ), array( '%d' ) );
      $wpdb->query( "UPDATE {$table} SET banner_default = 0" );
      $wpdb->update( $table, array( 'banner_default' => 1 ), array( 'banner_id' => $active_id ) );
      $controller->delete_cache();

      echo wp_json_encode( array(
        'delete_status' => $res->get_status(),
        'defaults_after' => $defaults_after,
        'promoted_id' => $promoted_id,
        'deleted_still_there' => $deleted_still_there,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.delete_status, 'DELETE responds 200').toBe(200);
    expect(data.deleted_still_there, 'deleted default row is gone').toBe(0);
    expect(data.defaults_after, 'deleting the sole default still leaves exactly one default').toBe(1);
    expect(data.promoted_id, 'some surviving row was promoted to default').toBeGreaterThan(0);
  });
});

/* ================================================================== *
 * 3. Consent model selector / sources for the "+ New banner" modal
 * ================================================================== */

test.describe('PR104-FU — /banners/configs feeds the consent-model selector', () => {
  test('GET /banners/configs returns both gdpr and ccpa default config trees', () => {
    const result = wpEval(`
      $admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
      wp_set_current_user( ! empty( $admin_ids ) ? (int) $admin_ids[0] : 0 );
      $req = new WP_REST_Request( 'GET', '/faz/v1/banners/configs' );
      $res = rest_do_request( $req );
      $data = $res->get_data();
      echo wp_json_encode( array(
        'status'      => $res->get_status(),
        'has_gdpr'    => is_array( $data ) && isset( $data['gdpr'] ) && is_array( $data['gdpr'] ),
        'has_ccpa'    => is_array( $data ) && isset( $data['ccpa'] ) && is_array( $data['ccpa'] ),
        'gdpr_law'   => isset( $data['gdpr']['settings']['applicableLaw'] ) ? $data['gdpr']['settings']['applicableLaw'] : null,
        'ccpa_law'   => isset( $data['ccpa']['settings']['applicableLaw'] ) ? $data['ccpa']['settings']['applicableLaw'] : null,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.status, 'configs endpoint responds 200').toBe(200);
    expect(data.has_gdpr, 'gdpr default config tree is present').toBe(true);
    expect(data.has_ccpa, 'ccpa default config tree is present').toBe(true);
    expect(data.gdpr_law, 'gdpr tree carries applicableLaw=gdpr').toBe('gdpr');
    expect(data.ccpa_law, 'ccpa tree carries applicableLaw=ccpa').toBe('ccpa');
  });
});

/* ================================================================== *
 * 4. faz_country_to_language() helper
 * ================================================================== */

test.describe('PR104-FU — faz_country_to_language map', () => {
  test('returns the primary ISO-639-1 language for representative countries and empties for unknown', () => {
    const result = wpEval(`
      echo wp_json_encode( array(
        'IT' => faz_country_to_language( 'IT' ),
        'DE' => faz_country_to_language( 'DE' ),
        'FR' => faz_country_to_language( 'FR' ),
        'BR' => faz_country_to_language( 'BR' ),
        'US' => faz_country_to_language( 'US' ),
        'GB' => faz_country_to_language( 'GB' ),
        'JP' => faz_country_to_language( 'JP' ),
        'CH' => faz_country_to_language( 'CH' ),
        'ZZ' => faz_country_to_language( 'ZZ' ),
        ''   => faz_country_to_language( '' ),
        'it' => faz_country_to_language( 'it' ),
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.IT, 'IT → it').toBe('it');
    expect(data.DE, 'DE → de').toBe('de');
    expect(data.FR, 'FR → fr').toBe('fr');
    expect(data.BR, 'BR → pt').toBe('pt');
    expect(data.US, 'US → en').toBe('en');
    expect(data.GB, 'GB → en').toBe('en');
    expect(data.JP, 'JP → ja').toBe('ja');
    expect(data.CH, 'CH → de (most-spoken default; override via filter for FR/IT sites)').toBe('de');
    expect(data.ZZ, 'unmapped country returns empty string').toBe('');
    expect(data[''], 'empty country returns empty string').toBe('');
    expect(data.it, 'lower-case input is normalised to upper-case before lookup').toBe('it');
  });

  test('faz_country_to_language filter lets callers override individual mappings', () => {
    const result = wpEval(`
      $closure = function ( $lang, $country ) {
        return 'CH' === $country ? 'fr' : $lang;
      };
      add_filter( 'faz_country_to_language', $closure, 10, 2 );
      $ch = faz_country_to_language( 'CH' );
      $de = faz_country_to_language( 'DE' );
      remove_filter( 'faz_country_to_language', $closure, 10 );
      echo wp_json_encode( array( 'ch' => $ch, 'de' => $de ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.ch, 'filter override applied: CH → fr').toBe('fr');
    expect(data.de, 'unrelated country untouched: DE → de').toBe('de');
  });
});

/* ================================================================== *
 * 6. promote_fallback_default — at-least-one default invariant
 * ================================================================== */

test.describe('PR104-FU — promote_fallback_default', () => {
  test('Controller::update_item promotes a peer when the only default is un-toggled', () => {
    const result = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';

      // Seed: ensure two rows exist and exactly one carries the default
      // flag. Use direct $wpdb writes so the seed is independent of any
      // sanitize / model logic that could mask the bug.
      $active = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      if ( ! $active ) { echo wp_json_encode( array( 'error' => 'NO_ACTIVE' ) ); return; }
      $active_id = (int) $active->get_id();
      $peer_id   = (int) $wpdb->get_var(
        $wpdb->prepare(
          "SELECT banner_id FROM {$table} WHERE banner_id <> %d ORDER BY banner_id ASC LIMIT 1",
          $active_id
        )
      );
      $created_peer = false;
      if ( $peer_id <= 0 ) {
        $now = current_time( 'mysql' );
        $wpdb->insert( $table, array(
          'name'             => 'PR104-FU promote peer',
          'slug'             => 'pr104-fu-promote-peer',
          'status'           => 1,
          'settings'         => wp_json_encode( $active->get_settings() ),
          'contents'         => wp_json_encode( $active->get_contents() ),
          'banner_default'   => 0,
          'target_countries' => wp_json_encode( array() ),
          'priority'         => 0,
          'date_created'     => $now,
          'date_modified'    => $now,
        ) );
        $peer_id = (int) $wpdb->insert_id;
        $created_peer = true;
      }
      // Reset to a clean "active is the sole default" state. Direct $wpdb
      // writes bypass the Controller cache, so we MUST invalidate it
      // explicitly — otherwise update_item's "new Banner(id)" snapshot
      // (which seeds was_default) reads the stale cached value and the
      // promote branch never fires.
      $wpdb->query( "UPDATE {$table} SET banner_default = 0" );
      $wpdb->update( $table, array( 'banner_default' => 1 ), array( 'banner_id' => $active_id ) );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $defaults_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE banner_default = 1" );

      // Now un-toggle the active banner's default flag through update_item.
      // Pre-fix this left the DB with zero defaults; post-fix the
      // promote_fallback_default branch promotes the peer.
      $b = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $active_id );
      $b->set_default( false );
      $b->save();

      $defaults_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE banner_default = 1" );
      $peer_default   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT banner_default FROM {$table} WHERE banner_id = %d", $peer_id ) );
      $active_default = (int) $wpdb->get_var( $wpdb->prepare( "SELECT banner_default FROM {$table} WHERE banner_id = %d", $active_id ) );

      // Restore the canonical (active = default) shape.
      $wpdb->query( "UPDATE {$table} SET banner_default = 0" );
      $wpdb->update( $table, array( 'banner_default' => 1 ), array( 'banner_id' => $active_id ) );
      if ( $created_peer ) {
        $wpdb->delete( $table, array( 'banner_id' => $peer_id ), array( '%d' ) );
      }
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      echo wp_json_encode( array(
        'defaults_before' => $defaults_before,
        'defaults_after'  => $defaults_after,
        'peer_default'    => $peer_default,
        'active_default'  => $active_default,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.defaults_before, 'seed: exactly one default').toBe(1);
    expect(data.defaults_after, 'after un-toggling the sole default, promote_fallback_default kicks in → still exactly one').toBe(1);
    expect(data.active_default, 'caller row really lost its default flag').toBe(0);
    expect(data.peer_default, 'the peer row was promoted to default').toBe(1);
  });
});

/* ================================================================== *
 * 7. update_db_350 migration end-to-end (existing install upgrade)
 * ================================================================== */

test.describe('PR104-FU — update_db_350 migrates existing installs', () => {
  test('simulates a pre-1.14.0 install (no target_countries, no priority, zero defaults) and confirms the migration repairs every invariant', () => {
    const result = wpEval(`
      global $wpdb;
      $table  = $wpdb->prefix . 'faz_banners';
      $schema = $wpdb->get_var( 'SELECT DATABASE()' );

      // Simulate the pre-1.14.0 schema: drop both new columns.
      @$wpdb->query( "ALTER TABLE {$table} DROP COLUMN target_countries" );
      @$wpdb->query( "ALTER TABLE {$table} DROP COLUMN priority" );

      // Simulate a pre-1.14.0 row-state with NO default banner: the
      // single-banner installs that pre-date the default-flag invariant
      // never set banner_default and would fall into the picker's
      // status_default = null bucket post-upgrade.
      $wpdb->query( "UPDATE {$table} SET banner_default = 0" );

      $tc_before  = (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $schema, $table, 'target_countries'
      ) );
      $pr_before  = (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $schema, $table, 'priority'
      ) );
      $def_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE banner_default = 1" );

      // Run the migration.
      \\FazCookie\\Includes\\Activator::update_db_350();

      $tc_after  = (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $schema, $table, 'target_countries'
      ) );
      $pr_after  = (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $schema, $table, 'priority'
      ) );
      $def_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE banner_default = 1" );

      // Backfill: every row should now have target_countries = '[]' (not NULL / not '').
      $empty_targets = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE target_countries = '[]'" );
      $null_or_blank = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE target_countries IS NULL OR target_countries = ''" );
      $total_rows    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

      echo wp_json_encode( array(
        'tc_before' => $tc_before,
        'pr_before' => $pr_before,
        'tc_after'  => $tc_after,
        'pr_after'  => $pr_after,
        'def_before'   => $def_before,
        'def_after'    => $def_after,
        'total'        => $total_rows,
        'empty_targets'   => $empty_targets,
        'null_or_blank'   => $null_or_blank,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.tc_before, 'simulated install lacks target_countries').toBe(0);
    expect(data.pr_before, 'simulated install lacks priority').toBe(0);
    expect(data.def_before, 'simulated install has zero default banners').toBe(0);

    expect(data.tc_after, 'migration added target_countries column').toBe(1);
    expect(data.pr_after, 'migration added priority column').toBe(1);
    expect(data.def_after, 'migration promoted exactly one banner to default').toBe(1);

    expect(data.empty_targets, 'every row backfilled with target_countries=[]').toBe(data.total);
    expect(data.null_or_blank, 'no row left NULL or empty').toBe(0);
  });
});

/* ================================================================== *
 * 5. faz_use_country_language_fallback opt-in
 * ================================================================== */

test.describe('PR104-FU — country→language fallback respects the opt-in filter + selected_languages gate', () => {
  test('off by default; on with selected lang → returns country lang; on without selected lang → returns default', () => {
    const result = wpEval(`
      $prev = get_option( 'faz_settings', null );

      // Force a non-localhost IP so Geolocation::get_country() does not
      // short-circuit, and a deterministic visitor country via filter so
      // the test never depends on MaxMind/ip-api availability.
      $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
      delete_transient( 'faz_geo_' . md5( '8.8.8.8' ) );
      $country_closure = function () { return 'IT'; };
      add_filter( 'faz_visitor_country', $country_closure );

      // Case A — fallback filter OFF (default). No multilingual plugin
      // is active here, so faz_current_language must return the site
      // default (en) regardless of the visitor's country.
      $s = is_array( $prev ) ? $prev : array();
      if ( ! isset( $s['languages'] ) ) { $s['languages'] = array(); }
      $s['languages']['selected'] = array( 'en', 'it' );
      update_option( 'faz_settings', $s );
      faz_current_language( true ); // reset static cache
      $off = faz_current_language();

      // Case B — fallback filter ON, IT is in selected languages.
      // Expect the country mapping (IT → it) to win.
      $fallback_closure = function () { return true; };
      add_filter( 'faz_use_country_language_fallback', $fallback_closure );
      faz_current_language( true );
      $on_with_lang = faz_current_language();

      // Case C — fallback filter ON, IT NOT in selected languages.
      // Expect the gate to kick in and fall back to site default.
      $s['languages']['selected'] = array( 'en' );
      update_option( 'faz_settings', $s );
      faz_current_language( true );
      $on_without_lang = faz_current_language();

      // Cleanup.
      remove_filter( 'faz_use_country_language_fallback', $fallback_closure );
      remove_filter( 'faz_visitor_country', $country_closure );
      unset( $_SERVER['REMOTE_ADDR'] );
      delete_transient( 'faz_geo_' . md5( '8.8.8.8' ) );
      if ( null === $prev ) { delete_option( 'faz_settings' ); } else { update_option( 'faz_settings', $prev ); }
      faz_current_language( true );

      echo wp_json_encode( array(
        'off'              => $off,
        'on_with_lang'     => $on_with_lang,
        'on_without_lang'  => $on_without_lang,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.off, 'fallback off → site default language, regardless of visitor country').toBe('en');
    expect(data.on_with_lang, 'fallback on AND it in selected → it (country mapping wins)').toBe('it');
    expect(data.on_without_lang, 'fallback on but it NOT in selected → site default (selected_languages gate)').toBe('en');
  });
});

/* ================================================================== *
 * 8. has_country_dependent_banners epoch-based invalidation (issue #109)
 * ================================================================== */

test.describe('PR104-FU — has_country_dependent_banners cache invalidation', () => {
  test('memoization uses an epoch-versioned cache key that delete_cache bumps deterministically', () => {
    const result = wpEval(`
      $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();

      // Reset the epoch to a known floor so the assertions don't depend
      // on whatever value previous tests left behind. F305 (1.14.3+):
      // the epoch is stored as a microsecond-precision float STRING
      // from sprintf('%.6F', microtime(true)), so seed/read as string
      // and compare with bccomp() — two delete_cache() calls within
      // the same wall-clock second still produce strictly-greater
      // values that an int cast would collapse.
      update_option( 'faz_banner_cache_epoch', '0', false );
      $ctrl->delete_cache();
      $epoch_after_first_delete = (string) get_option( 'faz_banner_cache_epoch', '0' );

      // Seed the cache for the current epoch.
      $first  = $ctrl->has_country_dependent_banners();
      $cached_after_first = wp_cache_get( 'faz_has_country_dependent_banners_v' . $epoch_after_first_delete, 'faz_banners' );

      // delete_cache must bump the epoch — the OLD cache key is now
      // unreferenced (would expire via TTL), and any subsequent read
      // queries a DIFFERENT key, forcing a recompute on every node.
      $ctrl->delete_cache();
      $epoch_after_second_delete = (string) get_option( 'faz_banner_cache_epoch', '0' );
      $second = $ctrl->has_country_dependent_banners();
      $cached_under_old_key = wp_cache_get( 'faz_has_country_dependent_banners_v' . $epoch_after_first_delete, 'faz_banners' );
      $cached_under_new_key = wp_cache_get( 'faz_has_country_dependent_banners_v' . $epoch_after_second_delete, 'faz_banners' );

      echo wp_json_encode( array(
        'first'                       => (bool) $first,
        'second'                      => (bool) $second,
        'epoch_after_first_delete'    => $epoch_after_first_delete,
        'epoch_after_second_delete'   => $epoch_after_second_delete,
        // bccomp returns 1 when arg1 > arg2 — string-safe ordering at
        // 6-decimal precision (matches sprintf('%.6F', ...) output).
        'epoch_strictly_greater'      => bccomp( $epoch_after_second_delete, $epoch_after_first_delete, 6 ) === 1,
        'cached_after_first_seed'     => $cached_after_first !== false,
        'old_key_still_cached'        => $cached_under_old_key !== false,
        'new_key_freshly_cached'      => $cached_under_new_key !== false,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.first, 'first call resolves to a concrete bool').toBe(data.second);
    expect(data.cached_after_first_seed, 'first call seeded the cache for its epoch').toBe(true);
    expect(data.epoch_strictly_greater, 'delete_cache bumps the epoch (microsecond-precision string compare)').toBe(true);
    expect(data.new_key_freshly_cached, 'new epoch key carries the post-invalidation value').toBe(true);
    // Old-key visibility is incidental — wp_cache_set kept it under the old
    // epoch's key, which is now unreferenced and will TTL out. The
    // assertion that matters is that the NEW key is independent.
  });

  test('legacy transient is swept on delete_cache for pre-fix 1.14.0 backward compat', () => {
    const result = wpEval(`
      // Plant the legacy transient that pre-fix 1.14.0 wrote.
      set_transient( 'faz_has_country_dependent_banners', 1, HOUR_IN_SECONDS );
      $present_before = get_transient( 'faz_has_country_dependent_banners' );

      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      $present_after = get_transient( 'faz_has_country_dependent_banners' );

      echo wp_json_encode( array(
        'present_before' => $present_before !== false,
        'present_after'  => $present_after  !== false,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.present_before, 'sanity: legacy transient was seeded').toBe(true);
    expect(data.present_after, 'delete_cache sweeps the legacy transient').toBe(false);
  });
});
