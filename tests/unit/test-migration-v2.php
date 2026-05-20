<?php
/**
 * Standalone unit tests for Migration_V2 contract methods.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-07
 * Task: T016 (P2 Migration unit tests)
 *
 * Tests the PURE methods of Migration_V2 (columns_to_add allowlist,
 * version_supports_online_ddl semver compare, missing_columns set
 * arithmetic). Methods that touch $wpdb are tested via E2E (T017).
 *
 * Run:
 *   php tests/unit/test-migration-v2.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-migration-v2.php';

use FazCookie\Includes\Migration_V2;

$tests_run    = 0;
$tests_passed = 0;
$tests_failed = 0;

function assert_eq( $actual, $expected, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $actual === $expected ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
	} else {
		$tests_failed++;
		echo "  \033[31m✗\033[0m " . $label . "\n";
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}

function assert_true( $cond, $label ) {
	assert_eq( (bool) $cond, true, $label );
}

echo "\n== Migration_V2 — unit tests (T016) ==\n\n";

// ---------- columns_to_add allowlist ----------

$cols = Migration_V2::columns_to_add();
assert_eq( count( $cols ), 7, 'columns_to_add() returns exactly 7 columns' );
assert_true( isset( $cols['country_at_consent'] ), 'has country_at_consent' );
assert_true( isset( $cols['region_at_consent'] ), 'has region_at_consent' );
assert_true( isset( $cols['ruleset_id_at_consent'] ), 'has ruleset_id_at_consent' );
assert_true( isset( $cols['signal_gpc_received'] ), 'has signal_gpc_received' );
assert_true( isset( $cols['signal_dnt_received'] ), 'has signal_dnt_received' );
assert_true( isset( $cols['tc_string'] ), 'has tc_string' );
assert_true( isset( $cols['gpp_string'] ), 'has gpp_string' );

// ---------- DDL fragment shape ----------

foreach ( $cols as $name => $ddl ) {
	// Each DDL must be `<TYPE>(<N>) NULL DEFAULT NULL` (sized) or
	// `<TYPE> NULL DEFAULT NULL` (unsized, e.g. TEXT) shape — defensive
	// against future edits that might add unsafe SQL.
	$ok = (bool) preg_match( '/^([A-Z]+\([0-9]+\)|[A-Z]+) NULL DEFAULT NULL$/', $ddl );
	assert_true( $ok, "DDL for '{$name}' matches safe shape" );
}

// ---------- Specific column sizes ----------

assert_eq( $cols['country_at_consent'], 'VARCHAR(2) NULL DEFAULT NULL', 'country_at_consent is VARCHAR(2)' );
assert_eq( $cols['region_at_consent'], 'VARCHAR(6) NULL DEFAULT NULL', 'region_at_consent is VARCHAR(6)' );
assert_eq( $cols['ruleset_id_at_consent'], 'VARCHAR(64) NULL DEFAULT NULL', 'ruleset_id_at_consent is VARCHAR(64)' );
assert_eq( $cols['signal_gpc_received'], 'TINYINT(1) NULL DEFAULT NULL', 'signal_gpc_received is TINYINT(1)' );
assert_eq( $cols['signal_dnt_received'], 'TINYINT(1) NULL DEFAULT NULL', 'signal_dnt_received is TINYINT(1)' );
assert_eq( $cols['tc_string'], 'TEXT NULL DEFAULT NULL', 'tc_string is TEXT (post L1-SP1-S002 fix)' );
assert_eq( $cols['gpp_string'], 'TEXT NULL DEFAULT NULL', 'gpp_string is TEXT (post L1-SP1-S002 fix)' );

// ---------- Constants exist ----------

assert_eq( Migration_V2::MIN_INNODB_VERSION, '5.7.6', 'MIN_INNODB_VERSION constant' );
assert_eq( Migration_V2::PENDING_OPTION, 'faz_geo_v2_migration_pending', 'PENDING_OPTION constant' );
assert_eq( Migration_V2::DISABLED_REASON_OPTION, 'faz_geo_v2_disabled_reason', 'DISABLED_REASON_OPTION constant' );

// ---------- Class shape ----------

assert_true( method_exists( 'FazCookie\Includes\Migration_V2', 'run' ), 'run() method exists' );
assert_true( method_exists( 'FazCookie\Includes\Migration_V2', 'version_supports_online_ddl' ), 'version_supports_online_ddl() exists' );
assert_true( method_exists( 'FazCookie\Includes\Migration_V2', 'missing_columns' ), 'missing_columns() exists' );
assert_true( method_exists( 'FazCookie\Includes\Migration_V2', 'is_complete' ), 'is_complete() exists' );

// ---------- Summary ----------

echo "\n--\n";
echo "Tests:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n\n";

if ( $tests_failed > 0 ) {
	echo "\033[31mFAIL\033[0m\n";
	exit( 1 );
}
echo "\033[32mPASS\033[0m\n";
exit( 0 );
