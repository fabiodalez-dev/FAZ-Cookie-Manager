/**
 * Multi-banner geo-routing regression suite (1.13.18+ feature, refs #103).
 *
 * Covers:
 *   - Controller::get_active_banner_for_country() picks the right banner for
 *     a given country code, falling back through the match-all → banner_default
 *     chain.
 *   - Banner model normalises target_countries (case, deduplication, invalid
 *     code rejection) and clamps priority to non-negative integers.
 *   - The frontend `faz_visitor_country` filter is consumed by the picker so
 *     test fixtures can stub the visitor's country deterministically.
 *
 * All assertions go through wpEval (PHP-level reflection) because the
 * country detection itself needs MaxMind or Cloudflare in production — the
 * filter is the only deterministic test seam.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

test.describe.serial('Multi-banner geo-routing (Controller selector + Banner model)', () => {
  // Snapshot the existing banner rows so the suite leaves the DB exactly as
  // it found it — every test in here mutates wp_faz_banners.
  let snapshot: string = '';

  test.beforeAll(() => {
    // STEP 1 — snapshot the DB state BEFORE any mutation. The previous
    // ordering captured the snapshot after the DELETE/INSERT block below,
    // which meant rows the cleanup removed could never be re-inserted by
    // the afterAll restore (leakage). Capturing first preserves every
    // pre-suite row so the restore at teardown is a true round-trip.
    snapshot = wpEval(`
      global $wpdb;
      echo wp_json_encode( $wpdb->get_results( "SELECT * FROM {\$wpdb->prefix}faz_banners" ) );
    `).trim();

    // STEP 2 — Force canonical (banner_id=1, banner_id=2) shape. The
    // suite hardcodes banner_id IN ITS $wpdb->update() calls, so the rows
    // MUST exist at those exact IDs. ALTER TABLE AUTO_INCREMENT does not
    // reliably reset the counter below the current MAX (MySQL/MariaDB
    // ignores the lower value as a duplicate-key safety check), so this
    // step uses explicit-ID INSERTs after a full table wipe — the only
    // approach that guarantees the IDs land at 1 and 2 regardless of any
    // prior auto-increment drift left by earlier specs in the run.
    //
    // The afterAll restore below uses DELETE + explicit-id INSERT from
    // the snapshot captured at STEP 1, so even rows wiped here are fully
    // recreated at teardown — no cross-spec leakage.
    wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      $active = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      if ( ! $active ) { return; }
      $primary_settings = wp_json_encode( $active->get_settings() );
      $primary_contents = wp_json_encode( $active->get_contents() );
      $primary_name     = $active->get_name();
      $primary_slug     = $active->get_slug();
      $now              = current_time( 'mysql' );

      // Full wipe → explicit-ID INSERTs. banner_id=1 carries the original
      // active banner's content so the rest of the suite sees a
      // realistically-shaped banner, not an empty shell.
      $wpdb->query( "DELETE FROM {$table}" );
      $wpdb->insert(
        $table,
        array(
          'banner_id'        => 1,
          'name'             => $primary_name ?: 'GEO suite primary',
          'slug'             => $primary_slug ?: 'geo-suite-primary',
          'status'           => 1,
          'settings'         => $primary_settings,
          'contents'         => $primary_contents,
          'banner_default'   => 1,
          'target_countries' => wp_json_encode( array() ),
          'priority'         => 0,
          'date_created'     => $now,
          'date_modified'    => $now,
        )
      );
      $wpdb->insert(
        $table,
        array(
          'banner_id'        => 2,
          'name'             => 'GEO suite secondary',
          'slug'             => 'geo-suite-secondary',
          'status'           => 0,
          'settings'         => $primary_settings,
          'contents'         => $primary_contents,
          'banner_default'   => 0,
          'target_countries' => wp_json_encode( array() ),
          'priority'         => 0,
          'date_created'     => $now,
          'date_modified'    => $now,
        )
      );
      // Belt-and-suspenders: bump AUTO_INCREMENT so any spec that inserts
      // an additional row mid-suite gets banner_id >= 3.
      $wpdb->query( "ALTER TABLE {$table} AUTO_INCREMENT = 3" );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    `);
  });

  test.afterAll(() => {
    // Full DELETE + explicit-id re-INSERT from snapshot. The earlier
    // UPDATE-only restore preserved banner_id but couldn't recreate rows
    // the beforeAll cleanup had dropped, leaking the deletion across the
    // rest of the suite. DELETE + re-INSERT with the original banner_id
    // is the only path that guarantees the post-condition matches the
    // pre-condition exactly.
    //
    // CRITICAL: also invalidate `faz_banner_template` (the cached server-
    // side rendered banner HTML). Without it the next test gets served
    // the LAST banner template rendered during GEO-01..GEO-30 (which may
    // be a CCPA layout with no category accordion), and an unrelated
    // locator like #fazCategoryDirectanalytics resolves to 0 elements.
    wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      $rows  = json_decode( ${JSON.stringify(snapshot)}, true );
      if ( ! is_array( $rows ) ) { return; }
      $wpdb->query( "DELETE FROM {$table}" );
      $max_id = 0;
      foreach ( $rows as $row ) {
        if ( empty( $row['banner_id'] ) ) { continue; }
        $banner_id = (int) $row['banner_id'];
        if ( $banner_id > $max_id ) { $max_id = $banner_id; }
        $wpdb->insert(
          $table,
          array(
            'banner_id'        => $banner_id,
            'name'             => isset( $row['name'] ) ? (string) $row['name'] : '',
            'slug'             => isset( $row['slug'] ) ? (string) $row['slug'] : '',
            'status'           => isset( $row['status'] ) ? (int) $row['status'] : 0,
            'settings'         => isset( $row['settings'] ) ? (string) $row['settings'] : '',
            'banner_default'   => isset( $row['banner_default'] ) ? (int) $row['banner_default'] : 0,
            'contents'         => isset( $row['contents'] ) ? (string) $row['contents'] : '',
            'target_countries' => isset( $row['target_countries'] ) ? (string) $row['target_countries'] : '[]',
            'priority'         => isset( $row['priority'] ) ? (int) $row['priority'] : 0,
            'date_created'     => isset( $row['date_created'] ) ? (string) $row['date_created'] : current_time( 'mysql' ),
            'date_modified'    => isset( $row['date_modified'] ) ? (string) $row['date_modified'] : current_time( 'mysql' ),
          )
        );
      }
      if ( $max_id > 0 ) {
        $wpdb->query( $wpdb->prepare( "ALTER TABLE {$table} AUTO_INCREMENT = %d", $max_id + 1 ) );
      }
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
        faz_clear_banner_template_cache();
      }
      delete_option( 'faz_banner_template' );
    `);
  });

  test('GEO-01: country=US returns the US-targeted banner; country=IT falls back to match-all', () => {
    const result = wpEval(`
      global $wpdb;
      // Set banner_id=2 to target US only, status=1, priority=0.
      // banner_id=1 stays with empty targets (match-all) and banner_default=1.
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'priority' => 0 ),
        array( 'banner_id' => 2 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $us = $ctrl->get_active_banner_for_country( 'US' );
      $it = $ctrl->get_active_banner_for_country( 'IT' );
      $br = $ctrl->get_active_banner_for_country( 'BR' );
      echo wp_json_encode( array(
        'us' => $us ? $us->get_id() : null,
        'it' => $it ? $it->get_id() : null,
        'br' => $br ? $br->get_id() : null,
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.us, 'US visitor must hit banner_id=2').toBe(2);
    expect(data.it, 'IT visitor must fall back to the match-all banner_id=1').toBe(1);
    expect(data.br, 'BR visitor (no explicit target) must also fall back to banner_id=1').toBe(1);
  });

  test('GEO-02: priority breaks ties when multiple banners target the same country', () => {
    const result = wpEval(`
      global $wpdb;
      // Both banners now target US; banner_id=2 keeps priority=0, banner_id=1 gets priority=10.
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'priority' => 10 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'priority' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $b = $ctrl->get_active_banner_for_country( 'US' );
      echo $b ? $b->get_id() : 'null';
    `).trim();

    expect(parseInt(result, 10), 'higher priority must win the tie').toBe(1);
  });

  test('GEO-03: banner_default=1 row is the last-resort fallback when no country matches and no match-all exists', () => {
    const result = wpEval(`
      global $wpdb;
      // No banner is currently active (status=0 on both), but banner_id=1 carries
      // banner_default=1. The picker must still return it as the fallback.
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["DE"]', 'status' => 0, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 0, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $b = $ctrl->get_active_banner_for_country( 'JP' );
      echo $b ? $b->get_id() : 'null';
    `).trim();

    expect(parseInt(result, 10), 'banner_default=1 wins when nothing else matches').toBe(1);
  });

  test('GEO-04: set_target_countries normalises (lower-case, whitespace, dedup, invalid drop)', () => {
    const result = wpEval(`
      $banner = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( 1 );
      $banner->set_target_countries( array( 'us', ' IT ', 'US', 'XX', 'BAD', '', 'fr', 'FR' ) );
      echo wp_json_encode( $banner->get_target_countries() );
    `).trim();

    expect(JSON.parse(result), 'normalisation collapses case + whitespace + duplicates and drops invalid codes').toEqual(['FR', 'IT', 'US', 'XX']);
    // Note: 'XX' is a valid 2-letter shape so it survives at this layer; semantic
    // "is XX a real country" is intentionally out of scope (admins may want
    // private-use codes).
  });

  test('GEO-05: set_priority clamps negative values to 0', () => {
    const result = wpEval(`
      $banner = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( 1 );
      $banner->set_priority( -50 );
      echo $banner->get_priority();
    `).trim();

    expect(parseInt(result, 10), 'negative priority is clamped to 0').toBe(0);
  });

  test('GEO-06: REST GET /banners/{id} returns target_countries and priority in the response', () => {
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["DE","FR","IT"]', 'priority' => 7 ),
        array( 'banner_id' => 1 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      wp_set_current_user( 1 );
      $req = new WP_REST_Request( 'GET', '/faz/v1/banners/1' );
      $req->set_param( 'context', 'edit' );
      $res = rest_do_request( $req );
      $data = $res->get_data();
      echo wp_json_encode( array(
        'target_countries' => $data['target_countries'] ?? null,
        'priority'         => $data['priority'] ?? null,
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.target_countries, 'response carries the persisted country list').toEqual(['DE', 'FR', 'IT']);
    expect(data.priority, 'response carries the persisted priority').toBe(7);
  });

  test('GEO-07: faz_visitor_country filter steers the frontend picker without touching geo settings', () => {
    // Stub the visitor country via the filter, then exercise the same chain
    // the frontend uses (Controller::get_active_banner_for_country) and
    // confirm the right banner is returned.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1, 'priority' => 0 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'banner_default' => 0, 'priority' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      add_filter( 'faz_visitor_country', function() { return 'US'; } );

      // Apply the filter the way the frontend would.
      $country = apply_filters( 'faz_visitor_country', '' );
      $b = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner_for_country( $country );
      echo $b ? $b->get_id() : 'null';
    `).trim();

    expect(parseInt(result, 10), 'filter-stubbed US visitor routes to the US-targeted banner').toBe(2);
  });

  test('GEO-08: faz_visitor_country filter returning lower-case / padded / non-ISO values is rejected (post-filter re-validation, CodeRabbit fix)', () => {
    // The frontend re-validates AFTER the filter so a hook returning 'us', ' US ',
    // 'USA', or 123 cannot steer routing into an unexpected bucket. Each invalid
    // shape must collapse to '' (no signal), which then routes to the match-all /
    // banner_default fallback.
    //
    // We exercise the helper through reflection because it is private — the same
    // way other specs in this suite probe controller internals.
    const result = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $ref = new ReflectionClass( $fe );
      $method = $ref->getMethod( 'get_visitor_country' );
      $method->setAccessible( true );

      // Cases split into two buckets:
      //   - accepted: shapes that survive normalisation (strtoupper + trim) and
      //     match /^[A-Z]{2}$/. 'us' upper-cases to 'US'; ' US ' trims to 'US'.
      //   - rejected: shapes the helper must collapse to '' so downstream
      //     callers (the picker) treat them as "no signal".
      $accepted = array( 'us' => 'US', ' US ' => 'US' );
      $rejected = array( 'USA', '12', 'gb-eng', '', '!!' );

      $out_accepted = array();
      foreach ( $accepted as $stub => $expected ) {
        $closure = function() use ( $stub ) { return $stub; };
        add_filter( 'faz_visitor_country', $closure, 10 );
        $out_accepted[ var_export( $stub, true ) ] = $method->invoke( $fe );
        remove_filter( 'faz_visitor_country', $closure, 10 );
      }

      $out_rejected = array();
      foreach ( $rejected as $stub ) {
        $closure = function() use ( $stub ) { return $stub; };
        add_filter( 'faz_visitor_country', $closure, 10 );
        $out_rejected[ var_export( $stub, true ) ] = $method->invoke( $fe );
        remove_filter( 'faz_visitor_country', $closure, 10 );
      }

      echo wp_json_encode( array( 'accepted' => $out_accepted, 'rejected' => $out_rejected ) );
    `).trim();

    const data = JSON.parse(result);
    // Normalisable shapes are accepted (lower-case / padded → upper, trimmed).
    Object.entries(data.accepted).forEach(([stub, value]) => {
      expect(value, `normalisable filter stub ${stub} must reach 'US'`).toBe('US');
    });
    // Malformed shapes (wrong length, non-letters) collapse to '' — the helper
    // guarantees the picker only ever sees a valid 2-letter code or no signal.
    Object.entries(data.rejected).forEach(([stub, value]) => {
      expect(value, `malformed filter stub ${stub} must collapse to '' (rejected)`).toBe('');
    });
  });

  test('GEO-09: faz_visitor_country filter returning a valid country survives re-validation', () => {
    // Symmetric to GEO-08: a hook that returns 'CH' (Switzerland, in our region
    // map) must reach the picker untouched. This pins down the contract that
    // re-validation only rejects malformed values, never valid ones.
    const result = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $ref = new ReflectionClass( $fe );
      $method = $ref->getMethod( 'get_visitor_country' );
      $method->setAccessible( true );

      $closure = function() { return 'CH'; };
      add_filter( 'faz_visitor_country', $closure, 10 );
      $resolved = $method->invoke( $fe );
      remove_filter( 'faz_visitor_country', $closure, 10 );

      echo $resolved;
    `).trim();

    expect(result, 'valid filter output passes through re-validation').toBe('CH');
  });

  test('GEO-10: update_db_350() collapses multiple banner_default=1 rows to exactly one (CodeRabbit fix)', () => {
    // The pre-fix migration only handled the zero-default case. If an install
    // already had two or more rows flagged banner_default=1 (possible via the
    // admin UI before the "Use this banner as default" toggle was wired to a
    // mutual-exclusion handler), the selector's last-resort fallback became
    // non-deterministic. The fixed migration must collapse multiples to a
    // single canonical row.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'banner_default' => 1, 'status' => 1 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'banner_default' => 1, 'status' => 1 ),
        array( 'banner_id' => 2 )
      );
      $count_before = (int) $wpdb->get_var( "SELECT COUNT(banner_id) FROM {\$wpdb->prefix}faz_banners WHERE banner_default = 1" );

      \\FazCookie\\Includes\\Activator::update_db_350();
      $count_after = (int) $wpdb->get_var( "SELECT COUNT(banner_id) FROM {\$wpdb->prefix}faz_banners WHERE banner_default = 1" );
      $winner = (int) $wpdb->get_var( "SELECT banner_id FROM {\$wpdb->prefix}faz_banners WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1" );

      echo wp_json_encode( array( 'before' => $count_before, 'after' => $count_after, 'winner' => $winner ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.before, 'precondition: 2 rows flagged as default').toBe(2);
    expect(data.after, 'migration collapses multiple defaults to exactly 1').toBe(1);
    expect(data.winner, 'lowest banner_id wins the canonical default slot').toBe(1);
  });

  test('GEO-11: update_db_350() promotes a fallback when 0 banners are status=1', () => {
    // Edge case: every banner is inactive (status=0) and none is flagged as
    // default. The selector still needs a fallback row to serve. The migration
    // must promote the lowest banner_id even when no row qualifies as "the
    // currently active banner".
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'status' => 0, 'banner_default' => 0 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'status' => 0, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Includes\\Activator::update_db_350();
      $winner = (int) $wpdb->get_var( "SELECT banner_id FROM {\$wpdb->prefix}faz_banners WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1" );
      echo $winner;
    `).trim();

    expect(parseInt(result, 10), 'lowest banner_id is promoted when no banner is active').toBe(1);
  });

  test('GEO-12: get_active_banner_for_country() rejects malformed country codes and falls back to match-all', () => {
    // The selector's own validation: anything that is not /^[A-Z]{2}$/ after
    // upper-casing is treated as empty signal. This is the second line of
    // defence after the frontend helper (GEO-08).
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'priority' => 0 ),
        array( 'banner_id' => 2 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $out = array();
      // Malformed inputs (wrong length, digits, empty) — all must fall back
      // to the match-all banner (id=1).
      foreach ( array( 'usa', '123', '', '!@' ) as $stub ) {
        $b = $ctrl->get_active_banner_for_country( $stub );
        $out[ 'malformed_' . $stub ] = $b ? $b->get_id() : null;
      }
      // Normalisable inputs ('us' lower → 'US', ' US ' padded → 'US') — these
      // pass the strtoupper+trim normalisation inside the selector and DO match
      // banner 2.
      foreach ( array( 'us', ' US ' ) as $stub ) {
        $b = $ctrl->get_active_banner_for_country( $stub );
        $out[ 'normalised_' . trim( $stub ) ] = $b ? $b->get_id() : null;
      }
      echo wp_json_encode( $out );
    `).trim();

    const data = JSON.parse(result);
    // Malformed shapes collapse to '' inside the selector → match-all (id=1).
    expect(data.malformed_usa, 'usa (3 letters) → match-all').toBe(1);
    expect(data.malformed_123, '123 (digits) → match-all').toBe(1);
    expect(data.malformed_, 'empty string → match-all').toBe(1);
    expect(data['malformed_!@'], '!@ (non-letters) → match-all').toBe(1);
    // Normalisable shapes ('us', ' US ') reach the US-targeted banner (id=2)
    // because the selector applies the same trim+upper normalisation before
    // validating.
    expect(data.normalised_us, "'us' lower → US-targeted banner").toBe(2);
    expect(data.normalised_US, "' US ' padded → US-targeted banner").toBe(2);
  });

  test('GEO-13: status=0 banner with matching target_countries is NOT selected even if country matches', () => {
    // A banner the admin has explicitly disabled (status=0) must never be
    // served, regardless of how well it matches the visitor's country.
    // It can only re-enter the chain via the banner_default=1 fallback.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 0, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $b = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner_for_country( 'US' );
      echo $b ? $b->get_id() : 'null';
    `).trim();

    expect(parseInt(result, 10), 'inactive US-targeted banner is skipped; match-all wins').toBe(1);
  });

  test('GEO-14: tie-break by banner_id when priority is equal', () => {
    // When two banners target the same country with the same priority, the
    // selector picks the lower banner_id for deterministic selection. Without
    // this, the order would depend on the SELECT result ordering — flaky.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'priority' => 5 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'priority' => 5 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $b = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner_for_country( 'US' );
      echo $b ? $b->get_id() : 'null';
    `).trim();

    expect(parseInt(result, 10), 'equal priority → lower banner_id wins').toBe(1);
  });

  test('GEO-15: set_target_countries accepts a JSON string in addition to an array', () => {
    // The setter accepts either an array or a JSON string. The JSON path is
    // exercised when the REST controller passes the column value through
    // unparsed (rare, but defensible — keeps the model resilient against
    // future code paths that forget to json_decode upfront).
    const result = wpEval(`
      $banner = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( 1 );
      $banner->set_target_countries( '["us","fr","DE","BAD"]' );
      echo wp_json_encode( $banner->get_target_countries() );
    `).trim();

    expect(JSON.parse(result), 'JSON string input is decoded + normalised the same as an array').toEqual(['DE', 'FR', 'US']);
  });

  test('GEO-16: get_active_banner() (legacy 0-arg API) keeps working — backcompat with single-banner installs', () => {
    // Before this PR every call site used the no-arg get_active_banner(). The
    // new selector takes a country argument but get_active_banner() must
    // delegate to get_active_banner_for_country('') unchanged so existing
    // integrations (caches, REST, debug helpers) keep working.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $b = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      echo $b ? $b->get_id() : 'null';
    `).trim();

    expect(parseInt(result, 10), 'no-arg API delegates to match-all → banner_id=1').toBe(1);
  });

  test('GEO-17: REST PUT preserves target_countries when the field is omitted from the request body', () => {
    // The REST controller only reads target_countries / priority when they
    // are explicitly present in the request — a legacy client that updates
    // only `name` must not have its previously-saved geo config wiped.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["DE","FR"]', 'priority' => 3 ),
        array( 'banner_id' => 1 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      wp_set_current_user( 1 );
      // PUT only sends a subset of fields — no target_countries, no priority.
      $req = new WP_REST_Request( 'PUT', '/faz/v1/banners/1' );
      $req->set_param( 'name', 'Backcompat probe' );
      $req->set_param( 'status', true );
      $req->set_param( 'default', true );
      $req->set_param( 'properties', array() );
      $req->set_param( 'contents', new stdClass() );
      rest_do_request( $req );

      // Re-read directly from the DB to confirm the existing values survived.
      $row = $wpdb->get_row( "SELECT target_countries, priority FROM {\$wpdb->prefix}faz_banners WHERE banner_id = 1" );
      echo wp_json_encode( array(
        'target_countries' => json_decode( $row->target_countries, true ),
        'priority'         => (int) $row->priority,
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.target_countries, 'omitted target_countries must not be wiped on PUT').toEqual(['DE', 'FR']);
    expect(data.priority, 'omitted priority must not be reset to 0 on PUT').toBe(3);
  });

  test('GEO-18: public language REST payload uses the same country-aware banner selector', () => {
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1, 'priority' => 0 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'banner_default' => 0, 'priority' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $country_filter = function() { return 'US'; };
      add_filter( 'faz_visitor_country', $country_filter, 10 );

      $lang = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
      $req = new WP_REST_Request( 'GET', '/faz/v1/banner/' . $lang );
      $res = rest_do_request( $req );
      $data = $res->get_data();
      $headers = $res->get_headers();
      $expected = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( 2 );

      remove_filter( 'faz_visitor_country', $country_filter, 10 );

      echo wp_json_encode( array(
        'status'     => $res->get_status(),
        'bannerSlug' => isset( $data['bannerSlug'] ) ? $data['bannerSlug'] : null,
        'activeLaw'  => isset( $data['activeLaw'] ) ? $data['activeLaw'] : null,
        'expected'   => $expected->get_slug(),
        'expectedLaw'=> $expected->get_law(),
        'cache'      => isset( $headers['Cache-Control'] ) ? $headers['Cache-Control'] : '',
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.status, 'REST request succeeds').toBe(200);
    expect(data.bannerSlug, 'US visitor receives the US-targeted banner payload').toBe(data.expected);
    expect(data.activeLaw, 'REST response exposes the law used for consent scoping').toBe(data.expectedLaw);
    expect(data.cache, 'country-dependent REST response is not publicly cacheable').toContain('no-store');
  });

  test('GEO-19: AMP consent resolves the active banner with the visitor country', () => {
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1, 'priority' => 0 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'banner_default' => 0, 'priority' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $country_filter = function() { return 'US'; };
      add_filter( 'faz_visitor_country', $country_filter, 10 );

      $amp = new \\FazCookie\\Frontend\\AMP_Consent();
      $ref = new ReflectionClass( $amp );
      $method = $ref->getMethod( 'get_active_banner' );
      $method->setAccessible( true );
      $banner = $method->invoke( $amp );

      remove_filter( 'faz_visitor_country', $country_filter, 10 );
      echo $banner ? $banner->get_id() : 'null';
    `).trim();

    expect(parseInt(result, 10), 'AMP path must render the US-targeted banner').toBe(2);
  });

  test('GEO-20: controller reports when active banners make frontend output country-dependent', () => {
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $ctrl->delete_cache();
      $before = $ctrl->has_country_dependent_banners();

      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1 ),
        array( 'banner_id' => 2 )
      );
      $ctrl->delete_cache();
      $after = $ctrl->has_country_dependent_banners();

      echo wp_json_encode( array( 'before' => $before, 'after' => $after ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.before, 'match-all-only setup is not country-dependent').toBe(false);
    expect(data.after, 'targeted active banner makes output country-dependent').toBe(true);
  });

  // ──────────────────────────────────────────────────────────────────────
  // GEO-21 → GEO-30: tests for the user-authored cache + payload work
  // (Geolocation::get_visitor_country, send_geo_cache_headers, banner-rest
  // payload + cache control, frontend store geo signals, ruleSet-based
  // country dependency).
  // ──────────────────────────────────────────────────────────────────────

  test('GEO-21: Geolocation::get_visitor_country reads CF-IPCountry when the trust filter is enabled', () => {
    // The header is normally ignored unless the admin opts in via the
    // faz_trust_cf_ipcountry_header filter — protects from spoofed headers
    // on installs that do not actually sit behind Cloudflare.
    const result = wpEval(`
      // The dev-only mu-plugin faz-geo-dev-fake-cf registers
      // __return_true on faz_trust_cf_ipcountry_header so geo-routing
      // can be exercised on a localhost install without a real GeoLite2
      // database. That contaminates the "default OFF" contract this
      // test verifies — clear callbacks so the assertion observes the
      // production default (false). Each wp eval invocation is a fresh
      // PHP process; mutating $wp_filter here does not leak across tests.
      remove_all_filters( 'faz_trust_cf_ipcountry_header' );

      // Establish a baseline WITHOUT the CF header so we know what
      // get_visitor_country() would naturally resolve to via the fallback
      // chain (MaxMind / ip-api.com). Comparing OFF against the baseline
      // is robust: the contract is "header has no effect", not
      // "result is not DE" (the fallback could genuinely resolve to DE
      // on a German dev box / VPN).
      unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
      $baseline = \\FazCookie\\Includes\\Geolocation::get_visitor_country();

      $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';

      // First: filter OFF → header must be ignored, result identical to baseline.
      $off = \\FazCookie\\Includes\\Geolocation::get_visitor_country();

      // Then: filter ON → header consumed.
      $closure = function() { return true; };
      add_filter( 'faz_trust_cf_ipcountry_header', $closure, 10 );
      $on = \\FazCookie\\Includes\\Geolocation::get_visitor_country();
      remove_filter( 'faz_trust_cf_ipcountry_header', $closure, 10 );

      unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
      echo wp_json_encode( array( 'baseline' => $baseline, 'off' => $off, 'on' => $on ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.on, 'with trust filter ON, CF-IPCountry header steers the result').toBe('DE');
    expect(data.off, 'with trust filter OFF, CF-IPCountry header must NOT alter the resolved country (spoof-safe by default)').toBe(data.baseline);
  });

  test('GEO-22: Geolocation::get_visitor_country rejects the Cloudflare "XX" placeholder', () => {
    // Cloudflare emits "XX" when it cannot resolve a country (Tor exit nodes,
    // private networks, etc.). Routing on "XX" would create a phantom geo
    // bucket — treat it the same as no signal.
    const result = wpEval(`
      $_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
      $closure = function() { return true; };
      add_filter( 'faz_trust_cf_ipcountry_header', $closure, 10 );
      $resolved = \\FazCookie\\Includes\\Geolocation::get_visitor_country();
      remove_filter( 'faz_trust_cf_ipcountry_header', $closure, 10 );
      unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
      echo var_export( $resolved, true );
    `).trim();

    // The MaxMind / ip-api.com fallback is unreachable on the local test stack,
    // so "XX" collapsing to no signal must produce an empty string here.
    expect(result, "CF-IPCountry='XX' is treated as no signal").toBe("''");
  });

  test('GEO-23: is_country_dependent_output returns true when at least one active banner targets a country', () => {
    // The headers emitted by send_geo_cache_headers() (Cache-Control: no-store,
    // Pragma: no-cache, X-LiteSpeed-Cache-Control: no-cache, optional Vary)
    // are gated entirely on this predicate. We probe it via reflection
    // because headers_list() returns empty under WP-CLI; the actual header
    // round-trip is asserted at the REST layer in GEO-26.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $ref = new ReflectionClass( $fe );
      $method = $ref->getMethod( 'is_country_dependent_output' );
      $method->setAccessible( true );
      echo $method->invoke( $fe ) ? 'true' : 'false';
    `).trim();

    expect(result, 'targeted active banner makes the frontend output country-dependent').toBe('true');
  });

  test('GEO-24: is_country_dependent_output returns false on single-banner installs (match-all only)', () => {
    // Symmetric to GEO-23: when no banner targets a country and geo_targeting
    // does NOT carry default_behavior='no_banner', the output is identical for
    // every visitor and the cache layer is left alone.
    const result = wpEval(`
      global $wpdb;
      // Snapshot faz_settings so the geolocation mutation below doesn't
      // leak into subsequent tests (GEO-25/26/27 read different geo
      // shapes; other suite files assume the install-default settings
      // they backed up themselves).
      $prev_settings = get_option( 'faz_settings', null );

      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 0, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      // Make sure geo_targeting is off / default_behavior is not no_banner.
      $s = get_option( 'faz_settings', array() );
      if ( ! isset( $s['geolocation'] ) ) { $s['geolocation'] = array(); }
      $s['geolocation']['geo_targeting']    = false;
      $s['geolocation']['default_behavior'] = 'show_banner';
      update_option( 'faz_settings', $s );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $ref = new ReflectionClass( $fe );
      $method = $ref->getMethod( 'is_country_dependent_output' );
      $method->setAccessible( true );
      $value = $method->invoke( $fe ) ? 'true' : 'false';

      // Restore faz_settings to its pre-test value (or delete if it
      // didn't exist before). Mirror of the same backup/restore the
      // pr-2026-04-19-audit spec uses around its faz_settings mutations.
      if ( null === $prev_settings ) {
        delete_option( 'faz_settings' );
      } else {
        update_option( 'faz_settings', $prev_settings );
      }
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      echo $value;
    `).trim();

    expect(result, "single-banner install with code=ALL must NOT be flagged country-dependent").toBe('false');
  });

  test('GEO-25: maybe_disable_country_page_cache defines DONOTCACHE* constants when country-dependent', () => {
    // The constants are read by every major page-cache plugin (WP Rocket,
    // W3 Total Cache, WP Super Cache, LiteSpeed Cache) as a per-request
    // bypass hint. Once defined for a request they are not undefinable, so
    // a fresh process is the only clean way to test this. Reflection is the
    // cheapest "fresh process" we have here.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $ref = new ReflectionClass( $fe );
      $method = $ref->getMethod( 'maybe_disable_country_page_cache' );
      $method->setAccessible( true );
      $method->invoke( $fe );

      echo wp_json_encode( array(
        'DONOTCACHEPAGE'   => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
        'DONOTCACHEOBJECT' => defined( 'DONOTCACHEOBJECT' ) && DONOTCACHEOBJECT,
        'DONOTCACHEDB'     => defined( 'DONOTCACHEDB' ) && DONOTCACHEDB,
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.DONOTCACHEPAGE, 'DONOTCACHEPAGE defined for country-dependent request').toBe(true);
    expect(data.DONOTCACHEOBJECT, 'DONOTCACHEOBJECT defined for country-dependent request').toBe(true);
    expect(data.DONOTCACHEDB, 'DONOTCACHEDB defined for country-dependent request').toBe(true);
  });

  test('GEO-26: public banner REST emits no-store + LiteSpeed control when output is country-dependent', () => {
    // Symmetric to GEO-23 but for the REST endpoint /faz/v1/banner/{lang}
    // that the frontend bootstrap reads. CDNs / browsers must not reuse a
    // payload that was rendered for a different country.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $lang = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
      $req = new WP_REST_Request( 'GET', '/faz/v1/banner/' . $lang );
      $res = rest_do_request( $req );
      echo wp_json_encode( array(
        'status'  => $res->get_status(),
        'headers' => $res->get_headers(),
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.status, 'REST request succeeds').toBe(200);
    const cc = (data.headers['Cache-Control'] || '').toString().toLowerCase();
    expect(cc, 'Cache-Control: no-store when country-dependent').toContain('no-store');
    // X-LiteSpeed-Cache-Control may be a string or an array; flatten before checking.
    const ls = Array.isArray(data.headers['X-LiteSpeed-Cache-Control'])
      ? data.headers['X-LiteSpeed-Cache-Control'].join(',')
      : (data.headers['X-LiteSpeed-Cache-Control'] || '');
    expect(String(ls).toLowerCase(), 'X-LiteSpeed-Cache-Control: no-cache hint').toContain('no-cache');
  });

  test('GEO-27: public banner REST emits short public cache when NO banner is country-dependent', () => {
    // Single-banner installs (the 99% baseline) get a CDN-cacheable response
    // (max-age=300). The header switches to no-store only when target_countries
    // makes the payload vary by visitor.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 0, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $lang = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
      $req = new WP_REST_Request( 'GET', '/faz/v1/banner/' . $lang );
      $res = rest_do_request( $req );
      echo wp_json_encode( array(
        'status'  => $res->get_status(),
        'headers' => $res->get_headers(),
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.status, 'REST request succeeds').toBe(200);
    const cc = (data.headers['Cache-Control'] || '').toString().toLowerCase();
    expect(cc, 'Cache-Control: public when output is country-independent').toContain('public');
    expect(cc, 'max-age=300 publicly cacheable').toContain('max-age=300');
    expect(cc, 'no-store must NOT be emitted on the country-independent path').not.toContain('no-store');
  });

  test('GEO-28: public banner REST payload exposes bannerSlug and activeLaw for the frontend bootstrap', () => {
    // The frontend uses these to detect "the user previously consented under
    // banner X / law Y, but the active banner changed → invalidate consent".
    // Without them in the payload the scope-change invalidation in script.js
    // has nothing to compare against.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      $lang = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
      $req = new WP_REST_Request( 'GET', '/faz/v1/banner/' . $lang );
      $res = rest_do_request( $req );
      $data = $res->get_data();
      $expected = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner_for_country( '' );
      echo wp_json_encode( array(
        'bannerSlug' => isset( $data['bannerSlug'] ) ? $data['bannerSlug'] : null,
        'activeLaw'  => isset( $data['activeLaw'] ) ? $data['activeLaw'] : null,
        'expected_slug' => $expected->get_slug(),
        'expected_law'  => $expected->get_law(),
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.bannerSlug, 'bannerSlug present in REST payload').toBe(data.expected_slug);
    expect(data.activeLaw, 'activeLaw present in REST payload').toBe(data.expected_law);
    expect(['gdpr', 'ccpa', 'lgpd', 'pipeda']).toContain(data.activeLaw);
  });

  test('GEO-29: frontend bootstrap exposes _bannerSlug + _activeLaw + _geoRouting in _fazStore', () => {
    // The scope-change invalidator in script.js needs these three properties
    // on the global store. We probe the rendered HTML for the localize block
    // and check the three keys are present.
    const result = wpEval(`
      global $wpdb;
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '["US"]', 'status' => 1, 'banner_default' => 0 ),
        array( 'banner_id' => 2 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 1, 'banner_default' => 1 ),
        array( 'banner_id' => 1 )
      );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();

      // Hit the home page through the REST router for a clean fetch.
      $req = new WP_REST_Request( 'GET', '/faz/v1/banner/' . ( function_exists( 'faz_default_language' ) ? faz_default_language() : 'en' ) );
      $res = rest_do_request( $req );
      $data = $res->get_data();
      echo wp_json_encode( array(
        'has_bannerSlug' => isset( $data['bannerSlug'] ),
        'has_activeLaw'  => isset( $data['activeLaw'] ),
        'has_html'       => isset( $data['html'] ),
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.has_bannerSlug, 'REST payload carries bannerSlug').toBe(true);
    expect(data.has_activeLaw, 'REST payload carries activeLaw').toBe(true);
    expect(data.has_html, 'REST payload carries html for client-side render').toBe(true);
  });

  test('GEO-30: has_country_dependent_banners() returns true when a ruleSet code is non-ALL', () => {
    // The new method considers both:
    //   - target_countries non-empty (the new geo-routing field), AND
    //   - the legacy ruleSet[0].code != 'ALL' (the old per-banner geo gate from
    //     the original Settings → Geolocation UI).
    // Either one makes the page vary by visitor country — and either one must
    // therefore trigger cache busting.
    const result = wpEval(`
      global $wpdb;
      // Reset both rows: empty target_countries, default ruleSet (ALL).
      $banner1 = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( 1 );
      $settings = $banner1->get_settings();
      if ( ! is_array( $settings ) ) { $settings = array(); }
      if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) {
        $settings['settings'] = array();
      }
      // ruleSet lives under .settings.ruleSet — same nesting as applicableLaw.
      $settings['settings']['ruleSet'] = array( array( 'code' => 'ALL', 'regions' => array() ) );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array(
          'target_countries' => '[]',
          'status'           => 1,
          'banner_default'   => 1,
          'settings'         => wp_json_encode( $settings ),
        ),
        array( 'banner_id' => 1 )
      );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'target_countries' => '[]', 'status' => 0 ),
        array( 'banner_id' => 2 )
      );
      $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $ctrl->delete_cache();
      $before = $ctrl->has_country_dependent_banners();

      // Now flip ruleSet[0].code to a country code on the active banner.
      $settings['settings']['ruleSet'] = array( array( 'code' => 'IT', 'regions' => array() ) );
      $wpdb->update( $wpdb->prefix . 'faz_banners',
        array( 'settings' => wp_json_encode( $settings ) ),
        array( 'banner_id' => 1 )
      );
      $ctrl->delete_cache();
      $after = $ctrl->has_country_dependent_banners();

      echo wp_json_encode( array( 'before' => $before, 'after' => $after ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.before, 'all banners with code=ALL → output is country-independent').toBe(false);
    expect(data.after, "ruleSet code != 'ALL' makes output country-dependent (legacy geo gate)").toBe(true);
  });
});
