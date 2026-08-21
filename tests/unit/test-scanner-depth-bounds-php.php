<?php
/** Regression checks for the scanner depth safety envelope. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

require_once __DIR__ . '/../../admin/modules/scanner/includes/class-controller.php';

use FazCookie\Admin\Modules\Scanner\Includes\Controller;

$cases = array(
	-10     => 1,
	0       => 1,
	1       => 1,
	20      => 20,
	2000    => 2000,
	2001    => 2000,
	9999999 => 2000,
);
$failed = 0;
foreach ( $cases as $input => $expected ) {
	$actual = Controller::normalize_max_pages( $input );
	if ( $expected !== $actual ) {
		$failed++;
		echo "  [FAIL] {$input}: expected {$expected}, got {$actual}\n";
	} else {
		echo "  [PASS] {$input} -> {$actual}\n";
	}
}

$api = file_get_contents( __DIR__ . '/../../admin/modules/scanner/api/class-api.php' );
$checks = array(
	'REST schema publishes the shared maximum' => false !== strpos( $api, "'maximum'           => Controller::MAX_SCAN_PAGES" ),
	'REST start path uses the shared clamp'     => false !== strpos( $api, 'Controller::normalize_max_pages' ),
);
foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		$failed++;
		echo "  [FAIL] {$label}\n";
	} else {
		echo "  [PASS] {$label}\n";
	}
}

if ( $failed ) {
	exit( 1 );
}
echo 'Scanner depth bounds: ' . ( count( $cases ) + count( $checks ) ) . " passed.\n";
