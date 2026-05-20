/**
 * E2E — Migration_V2 (spec 001 task T017).
 *
 * Verifies that the geo-routing v2 schema migration runs idempotently
 * via the activator hook, adds 7 NULL-default columns to
 * wp_faz_consent_logs, and remains queryable for both legacy (NULL on
 * v2 columns) and post-migration rows.
 *
 * Skips gracefully when MySQL version < 5.7.6 (CI sometimes uses an
 * older MariaDB; the migration intentionally aborts and persists a
 * disabled-reason option).
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

test.describe('Migration_V2 — geo-routing v2 schema migration', () => {
  test('idempotent run + 7 columns added + legacy rows queryable', () => {
    const raw = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_consent_logs';

      // 1. Force a fresh migration attempt regardless of prior state.
      delete_option( 'faz_geo_v2_migration_pending' );
      delete_option( 'faz_geo_v2_disabled_reason' );

      // 2. Run migration.
      $status = \\FazCookie\\Includes\\Migration_V2::run();

      // 3. Capture state for assertions.
      $version_ok = \\FazCookie\\Includes\\Migration_V2::version_supports_online_ddl();
      $missing_after_run = \\FazCookie\\Includes\\Migration_V2::missing_columns( $table );
      $disabled_reason = get_option( 'faz_geo_v2_disabled_reason' );
      $pending = get_option( 'faz_geo_v2_migration_pending' );

      // 4. Idempotency: run a SECOND time → no-op.
      $status_second = \\FazCookie\\Includes\\Migration_V2::run();

      // 5. Validate INFORMATION_SCHEMA reflects the new columns.
      $existing_cols = $wpdb->get_col( $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
        $table
      ) );
      $existing_cols_lower = array_map( 'strtolower', $existing_cols );
      $required = array(
        'country_at_consent', 'region_at_consent', 'ruleset_id_at_consent',
        'signal_gpc_received', 'signal_dnt_received', 'tc_string', 'gpp_string'
      );
      $cols_present = array();
      foreach ( $required as $col ) {
        $cols_present[ $col ] = in_array( strtolower( $col ), $existing_cols_lower, true );
      }

      // 6. Legacy-row compatibility: confirm SELECT * works without errors
      //    (NULL on v2 columns must not break readers).
      $legacy_query_ok = false;
      try {
        $wpdb->get_results( "SELECT * FROM {$table} LIMIT 1" );
        $legacy_query_ok = ( '' === (string) $wpdb->last_error );
      } catch ( \\Throwable $e ) {
        $legacy_query_ok = false;
      }

      echo wp_json_encode( array(
        'status'             => $status,
        'status_second'      => $status_second,
        'version_ok'         => $version_ok,
        'missing_after_run'  => $missing_after_run,
        'disabled_reason'    => $disabled_reason,
        'pending'            => $pending,
        'cols_present'       => $cols_present,
        'legacy_query_ok'    => $legacy_query_ok,
      ) );
    `).trim();

    const data = JSON.parse(raw);

    if (!data.version_ok) {
      // MySQL < 5.7.6 / MariaDB < 10.3 — migration intentionally aborts.
      // Validate the abort path is reported correctly.
      expect(data.status, 'status reports mysql_too_old').toBe('mysql_too_old');
      expect(
        data.disabled_reason,
        'disabled_reason persisted with explanation',
      ).toContain('5.7.6');
      test.skip(true, 'MySQL/MariaDB version below 5.7.6 — migration intentionally skipped on this CI runner');
      return;
    }

    // Normal path — version OK, all 7 columns must be present.
    expect(['ok', 'no_op'], 'first run status is ok or no_op (already-migrated)').toContain(data.status);
    expect(data.status_second, 'second run is no_op (idempotent)').toBe('no_op');
    expect(data.missing_after_run, 'no columns remain missing after run').toEqual([]);
    expect(data.pending, 'pending option cleared on success').toBeFalsy();

    // All 7 columns must be present.
    for (const col of [
      'country_at_consent',
      'region_at_consent',
      'ruleset_id_at_consent',
      'signal_gpc_received',
      'signal_dnt_received',
      'tc_string',
      'gpp_string',
    ]) {
      expect(data.cols_present[col], `column ${col} present`).toBe(true);
    }

    expect(data.legacy_query_ok, 'SELECT * on consent_logs works with new schema').toBe(true);
  });

  test('insert + read v2 columns (country/region/ruleset/signals)', () => {
    const raw = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_consent_logs';

      // Skip if migration didn't apply (MySQL too old).
      if ( ! \\FazCookie\\Includes\\Migration_V2::is_complete() ) {
        echo wp_json_encode( array( 'skipped' => true ) );
        exit;
      }

      // Insert a v2 row populating all 7 new columns. Column names match
      // the canonical CREATE TABLE in
      // admin/modules/consentlogs/includes/class-controller.php::install_table():
      //   log_id, consent_id, status, categories, ip_hash, user_agent,
      //   url, banner_slug, policy_revision, created_at
      // plus the 7 columns added by Migration_V2. The earlier test payload
      // referenced non-existent columns (user_agent_hash, policy_version,
      // banner_version, language, choice, method, date_created) — $wpdb->
      // insert tolerates them silently by skipping unknown keys, but the
      // assertion was effectively meaningless on those fields.
      $consent_id = 'test-v2-' . time();
      $wpdb->insert(
        $table,
        array(
          'consent_id'              => $consent_id,
          'status'                  => 'accepted',
          'categories'              => '{}',
          'ip_hash'                 => str_repeat( 'a', 64 ),
          'user_agent'              => 'phpunit-migration-test',
          'url'                     => 'https://example.test/',
          'banner_slug'             => 'test-banner',
          'policy_revision'         => 1,
          'created_at'              => current_time( 'mysql' ),
          // v2 columns under test:
          'country_at_consent'      => 'US',
          'region_at_consent'       => 'US-CA',
          'ruleset_id_at_consent'   => 'ccpa-california',
          'signal_gpc_received'     => 1,
          'signal_dnt_received'     => 0,
          'tc_string'               => 'CPyyy=test',
          'gpp_string'              => 'DBABAA~test',
        )
      );

      // Surface insert errors so the test fails loudly instead of returning
      // a null row that the assertion would coerce to falsy.
      if ( ! empty( $wpdb->last_error ) ) {
        echo wp_json_encode( array( 'insert_error' => $wpdb->last_error ) );
        exit;
      }

      $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT country_at_consent, region_at_consent, ruleset_id_at_consent, signal_gpc_received, signal_dnt_received, tc_string, gpp_string FROM {$table} WHERE consent_id = %s",
        $consent_id
      ), ARRAY_A );

      // Cleanup
      $wpdb->delete( $table, array( 'consent_id' => $consent_id ) );

      echo wp_json_encode( $row );
    `).trim();

    const data = JSON.parse(raw);

    if (data && data.skipped) {
      test.skip(true, 'Migration not complete — skipping insert test');
      return;
    }

    // Loud failure if the test payload missed a real column (regression
    // guard against the previous schema mismatch where non-existent
    // columns silently became no-ops).
    expect(data, 'row inserted without DB error').not.toHaveProperty('insert_error');

    expect(data, 'row inserted+read with v2 columns').toBeTruthy();
    expect(data.country_at_consent).toBe('US');
    expect(data.region_at_consent).toBe('US-CA');
    expect(data.ruleset_id_at_consent).toBe('ccpa-california');
    expect(String(data.signal_gpc_received)).toBe('1');
    expect(String(data.signal_dnt_received)).toBe('0');
    expect(data.tc_string).toBe('CPyyy=test');
    expect(data.gpp_string).toBe('DBABAA~test');
  });
});
