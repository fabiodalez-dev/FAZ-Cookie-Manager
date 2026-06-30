<?php
/**
 * Standalone unit tests for Shortcodes::translate_header() (#164 / review).
 *
 * Subsystem: translate-header-php
 *
 * The cookie-audit-table column headers (Cookie / Duration / Description) are
 * translated through translate_header() → translate_default_text(): a value
 * equal to the bundled English default is replaced with its translation, while
 * an admin-customised value is preserved verbatim. The hardening pinned here:
 * when the audit-table config is absent ($contents is the empty string, or the
 * key is missing/empty), the header must fall back to the translated DEFAULT
 * rather than vanishing — and is_array() must guard the empty-string case.
 *
 * Run: php tests/unit/test-translate-header-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	// __() marks the strings it translates so the test can detect a real
	// translation vs a passed-through custom value.
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return 'XX-' . $text;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/modules/shortcodes/class-shortcodes.php';

	use FazCookie\Frontend\Modules\Shortcodes\Shortcodes;

	$tests_run = 0; $tests_passed = 0; $tests_failed = 0;
	function eq( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	$rc  = new ReflectionClass( Shortcodes::class );
	$sc  = $rc->newInstanceWithoutConstructor();
	$m   = new ReflectionMethod( Shortcodes::class, 'translate_header' );
	$m->setAccessible( true );
	$call = function ( $contents, $key, $default ) use ( $m, $sc ) {
		return $m->invoke( $sc, $contents, $key, $default );
	};

	echo "translate_header()\n";

	// Config present, value == default → translated.
	$present_default = array( 'headers' => array( 'elements' => array( 'description' => 'Description' ) ) );
	eq( $call( $present_default, 'description', 'Description' ), 'XX-Description', 'value == default → translated' );

	// Config present, admin-customised value → preserved verbatim (not translated).
	$present_custom = array( 'headers' => array( 'elements' => array( 'description' => 'Our cookies' ) ) );
	eq( $call( $present_custom, 'description', 'Description' ), 'Our cookies', 'custom value → preserved (no translation)' );

	// The hardened cases — header must render the translated default, not vanish:
	// (a) $contents is the empty string (section absent).
	eq( $call( '', 'description', 'Description' ), 'XX-Description', "absent config ('' string) → translated default (is_array guard)" );
	// (b) $contents is an array but the key is missing.
	eq( $call( array( 'headers' => array( 'elements' => array() ) ), 'description', 'Description' ), 'XX-Description', 'missing key → translated default' );
	// (c) $contents has the key but it is an empty string.
	$empty_val = array( 'headers' => array( 'elements' => array( 'description' => '' ) ) );
	eq( $call( $empty_val, 'description', 'Description' ), 'XX-Description', 'empty value → translated default' );

	// Other header keys behave the same.
	eq( $call( '', 'id', 'Cookie' ), 'XX-Cookie', "absent config → translated 'Cookie' default" );
	eq( $call( '', 'duration', 'Duration' ), 'XX-Duration', "absent config → translated 'Duration' default" );

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run}\n";
		exit( 0 );
	}
	echo "\033[31m{$tests_failed} FAILED\033[0m — {$tests_passed}/{$tests_run} passed\n";
	exit( 1 );
}
