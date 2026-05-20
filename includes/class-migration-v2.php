<?php
/**
 * Class Migration_V2 file — geo-routing v2 database migration.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-07 + data-model.md §5
 * Tasks: T012 + T013 + T014 (P2 Migration)
 *
 * Adds 7 NULL-default columns to wp_faz_consent_logs to record the
 * geo / signal / TCF / GPP context that applied at consent time. All
 * columns are NULL on legacy rows (no retroactive backfill — pre-v2
 * visits had no geo context to capture).
 *
 * Pattern: replicates the R4-S004 idempotent partial-failure recovery
 * shipped in update_db_350 (faz_innodb_migration_pending option). On
 * each invocation:
 *   1. Probe MySQL version (must be 5.7.6+ for ALGORITHM=INPLACE,
 *      LOCK=NONE on InnoDB ADD COLUMN).
 *   2. For each missing column, issue an individual ALTER. Capture
 *      false return → mark column as still-pending.
 *   3. Persist `faz_geo_v2_migration_pending` array with the residual
 *      column list. Subsequent runs retry only those.
 *   4. Clear the pending option on a fully-successful run.
 *
 * Constitution V Auditable Records: new columns extend the audit trail
 * without mutating existing rows.
 *
 * @package FazCookie\Includes
 * @since   1.15.0
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Geo-routing v2 schema migration.
 *
 * @class    Migration_V2
 * @package  FazCookie\Includes
 * @since    1.15.0
 */
class Migration_V2 {

	/**
	 * Minimum InnoDB version required for online ADD COLUMN.
	 *
	 * MySQL 5.7.6 + MariaDB 10.3+ support ALGORITHM=INPLACE, LOCK=NONE
	 * for ADD COLUMN on InnoDB tables. Below that, ALTER falls back to
	 * ALGORITHM=COPY which holds an exclusive table lock for the
	 * duration of the copy — unacceptable on production tables.
	 *
	 * @var string
	 */
	const MIN_INNODB_VERSION = '5.7.6';

	/**
	 * Minimum MariaDB version supporting online DDL with
	 * `ALGORITHM=INPLACE, LOCK=NONE` for ADD COLUMN on InnoDB.
	 *
	 * MariaDB diverged from MySQL on online-DDL syntax around 10.3.x;
	 * the InnoDB engine plus the relevant ALTER ALGORITHM= support landed
	 * in 10.3.0. Older MariaDB falls back to the COPY algorithm with a
	 * brief lock; we still allow it (the rows are NULL-default ADD COLUMN
	 * so the copy is non-blocking writes; we just emit a notice).
	 *
	 * @var string
	 */
	const MIN_MARIADB_VERSION = '10.3.0';

	/**
	 * Option storing the list of columns NOT YET migrated (R4-S004
	 * pattern). Empty / missing on fully-migrated installs.
	 *
	 * @var string
	 */
	const PENDING_OPTION = 'faz_geo_v2_migration_pending';

	/**
	 * Option storing the reason migration was skipped (e.g. MySQL too old).
	 *
	 * @var string
	 */
	const DISABLED_REASON_OPTION = 'faz_geo_v2_disabled_reason';

	/**
	 * Columns this migration adds to wp_faz_consent_logs.
	 *
	 * Each entry: column_name → DDL fragment.
	 *
	 * Specifications:
	 * - country_at_consent     : ISO 3166-1 alpha-2 (2 chars)
	 * - region_at_consent      : ISO 3166-2 (e.g. US-CA — 6 chars max)
	 * - ruleset_id_at_consent  : id string (≤64 chars per schema regex)
	 * - signal_gpc_received    : 0/1 flag
	 * - signal_dnt_received    : 0/1 flag
	 * - tc_string              : IAB TC String (large)
	 * - gpp_string             : IAB GPP container string (large)
	 *
	 * @return array<string,string>
	 */
	public static function columns_to_add() {
		return array(
			'country_at_consent'    => "VARCHAR(2) NULL DEFAULT NULL",
			'region_at_consent'     => "VARCHAR(6) NULL DEFAULT NULL",
			'ruleset_id_at_consent' => "VARCHAR(64) NULL DEFAULT NULL",
			'signal_gpc_received'   => "TINYINT(1) NULL DEFAULT NULL",
			'signal_dnt_received'   => "TINYINT(1) NULL DEFAULT NULL",
			// MySQL 5.7 rejects an explicit DEFAULT on TEXT/BLOB columns
			// ("BLOB and TEXT columns cannot have DEFAULT values" — MySQL 5.7
			// docs). NULL is still implicitly allowed because the column is
			// declared NULL; we just don't write DEFAULT NULL.
			'tc_string'             => "TEXT NULL",
			'gpp_string'            => "TEXT NULL",
		);
	}

	/**
	 * Run the migration. Idempotent: safe to call multiple times.
	 *
	 * @return string Status: 'ok' | 'mysql_too_old' | 'partial' | 'no_table' | 'no_op'.
	 */
	public static function run() {
		global $wpdb;
		$table = $wpdb->prefix . 'faz_consent_logs';

		// L2-SP1-S002 fix (1.15.0): MySQL advisory lock to serialize
		// concurrent activator invocations. Without this, two parallel
		// `maybe_update_db()` runs (admin reactivate + cron / WP-CLI
		// + browser admin / multi-worker FPM) both observed the column
		// missing, both issued ALTER TABLE, the second got ERROR 1060
		// "Duplicate column name" → wpdb returned false → the column
		// got marked as failed in `faz_geo_v2_migration_pending` even
		// though it actually existed. Acquiring `GET_LOCK` with a 10s
		// timeout makes the second caller wait for the first to finish,
		// then it sees the columns present and exits with status='no_op'.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lock_acquired = (int) $wpdb->get_var( "SELECT GET_LOCK('faz_geo_v2_migration', 10)" );
		if ( 1 !== $lock_acquired ) {
			// Lock not granted within 10s — another worker is still
			// running the migration. Return cleanly; the next
			// `maybe_update_db()` cycle will re-enter.
			return 'lock_busy';
		}

		try {
			$result = self::run_locked( $table );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->get_var( "SELECT RELEASE_LOCK('faz_geo_v2_migration')" );
		}
		return $result;
	}

	/**
	 * Inner migration body — called by run() after acquiring the
	 * advisory lock. Same flow as the pre-fix run().
	 *
	 * @param string $table Full table name (with prefix).
	 * @return string Status: 'ok' | 'mysql_too_old' | 'partial' | 'no_table' | 'no_op'.
	 */
	protected static function run_locked( $table ) {
		global $wpdb;

		// 1. Bail if the consent_logs table itself doesn't exist yet
		//    (e.g. activator running before install_all_tables completed).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
				$table
			)
		);
		if ( ! $table_exists ) {
			return 'no_table';
		}

		// 2. Probe MySQL/MariaDB version. Below 5.7.6 we'd be forced into
		//    ALGORITHM=COPY which holds a table-lock for the whole rebuild
		//    — never acceptable on a production audit-trail table.
		if ( ! self::version_supports_online_ddl() ) {
			update_option(
				self::DISABLED_REASON_OPTION,
				sprintf( 'MySQL version below %s (or MariaDB below %s) — online DDL unavailable. Upgrade your database before enabling geo-routing v2.', self::MIN_INNODB_VERSION, self::MIN_MARIADB_VERSION ),
				false
			);
			return 'mysql_too_old';
		}
		delete_option( self::DISABLED_REASON_OPTION );

		// 3. Compute the list of columns still missing.
		$missing = self::missing_columns( $table );
		if ( empty( $missing ) ) {
			delete_option( self::PENDING_OPTION );
			return 'no_op';
		}

		// 4. Apply each ALTER individually. Accumulate failures.
		$failed = array();
		foreach ( $missing as $column ) {
			if ( ! self::add_column( $table, $column ) ) {
				$failed[] = $column;
			}
		}

		// 5. Persist residual pending list (R4-S004 pattern).
		if ( ! empty( $failed ) ) {
			update_option( self::PENDING_OPTION, $failed, false );
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[FAZ Cookie Manager] geo-routing v2 migration: failed to add columns %s. Re-run will retry.',
						implode( ', ', $failed )
					)
				);
			}
			return 'partial';
		}

		// 6. Success.
		delete_option( self::PENDING_OPTION );
		return 'ok';
	}

	/**
	 * Check whether the current InnoDB version supports online DDL.
	 *
	 * @return bool
	 */
	public static function version_supports_online_ddl() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
		if ( empty( $mysql_version ) ) {
			return false;
		}
		// Extract leading semver (handles MariaDB suffixes like "10.5.18-MariaDB").
		if ( ! preg_match( '/^(\d+\.\d+\.\d+)/', $mysql_version, $matches ) ) {
			return false;
		}
		$detected = $matches[1];

		// MariaDB 10.3+ supports the equivalent INPLACE ADD COLUMN.
		// We map MariaDB ≥ MIN_MARIADB_VERSION → "OK", below that → fail.
		$is_mariadb = ( false !== stripos( $mysql_version, 'mariadb' ) );
		if ( $is_mariadb ) {
			return version_compare( $detected, self::MIN_MARIADB_VERSION, '>=' );
		}
		// MySQL path.
		return version_compare( $detected, self::MIN_INNODB_VERSION, '>=' );
	}

	/**
	 * Get the list of columns in `columns_to_add()` that are NOT
	 * yet present on the target table.
	 *
	 * @param string $table Full table name (with prefix).
	 * @return array<int,string> List of column names still missing.
	 */
	public static function missing_columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
				$table
			)
		);
		if ( ! is_array( $existing ) ) {
			return array_keys( self::columns_to_add() );
		}
		$existing_lower = array_map( 'strtolower', $existing );
		$missing        = array();
		foreach ( self::columns_to_add() as $column => $ddl ) {
			if ( ! in_array( strtolower( $column ), $existing_lower, true ) ) {
				$missing[] = $column;
			}
		}
		return $missing;
	}

	/**
	 * Add a single column via ALTER ... ALGORITHM=INPLACE, LOCK=NONE.
	 *
	 * The column name is validated against an allowlist (`columns_to_add`
	 * keys) to prevent any caller injecting an arbitrary identifier.
	 *
	 * @param string $table   Full table name (with prefix).
	 * @param string $column  Column name (must be in allowlist).
	 * @return bool True on success.
	 */
	private static function add_column( $table, $column ) {
		global $wpdb;
		$allowed = self::columns_to_add();
		if ( ! isset( $allowed[ $column ] ) ) {
			return false;
		}
		$ddl = $allowed[ $column ];

		// Defensive: allow only ASCII identifier in column + ddl.
		if ( ! preg_match( '/^[a-z_][a-z0-9_]*$/i', $column ) ) {
			return false;
		}
		// L1-SP1-S002 fix (1.15.0): accept both sized (VARCHAR(N), TINYINT(1))
		// and unsized (TEXT) safe DDL shapes. TEXT avoids the 4096-char
		// truncation risk for large IAB TC/GPP strings. MySQL 5.7 compat:
		// DEFAULT NULL is optional — TEXT/BLOB columns cannot have an
		// explicit DEFAULT, so allow `... NULL` without `DEFAULT NULL`.
		if ( ! preg_match( '/^([A-Z]+\([0-9]+\)|[A-Z]+) NULL(?: DEFAULT NULL)?$/', $ddl ) ) {
			return false;
		}

		// Construct ALTER. Backticks + allowlist-validated identifier.
		// $table is callsite-controlled ($wpdb->prefix . 'faz_consent_logs'), $column comes from a static $allowed map,
		// $ddl is one of two regex-validated literals (see L285-L292). prepare() is not usable for identifiers/DDL.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = sprintf(
			'ALTER TABLE `%s` ADD COLUMN `%s` %s, ALGORITHM=INPLACE, LOCK=NONE',
			str_replace( '`', '', $table ),
			$column,
			$ddl
		);

		// Suppress dbDelta-style warnings; we report success via return code.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// Some MySQL/MariaDB versions reject ALGORITHM= clause on the
			// rare composite/index-dependent ALTER. Retry without the
			// online hints — the operation is still safe (NULL-default
			// ADD COLUMN), just might briefly lock. Same allowlist as above.
			// Log a notice so operators on large consent_logs tables (millions
			// of rows) know that the fallback path holds a brief table lock
			// during COPY: implicit ALGORITHM=COPY blocks writes for the
			// copy duration. ADD COLUMN with NULL default is fast in
			// practice, but operators should be told so they can correlate
			// any brief write-stall window with this migration.
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[FAZ Cookie Manager] geo-routing v2 migration: online ADD COLUMN `%s` on `%s` failed; retrying without ALGORITHM=INPLACE,LOCK=NONE (implicit ALGORITHM=COPY — table briefly locked during copy).',
					$column,
					$table
				) );
			}
			// Build the fallback ALTER as a separate assignment so the phpcs:ignore
			// on the $wpdb->query() statement applies cleanly. Same allowlist
			// guarantees as the primary path above ($table from $wpdb->prefix,
			// $column from static $allowed, $ddl from regex-validated literals).
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$sql_fallback = sprintf(
				'ALTER TABLE `%s` ADD COLUMN `%s` %s',
				str_replace( '`', '', $table ),
				$column,
				$ddl
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$result = $wpdb->query( $sql_fallback );
		}

		return false !== $result;
	}

	/**
	 * Convenience: returns true if migration is complete on this install.
	 *
	 * @return bool
	 */
	public static function is_complete() {
		global $wpdb;
		$table = $wpdb->prefix . 'faz_consent_logs';
		return empty( self::missing_columns( $table ) );
	}
}
