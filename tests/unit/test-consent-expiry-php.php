<?php
/**
 * Consent lifetime normalization regression tests.
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

use FazCookie\Frontend\Frontend;

$failures = 0;
$checks   = 0;

function faz_expiry_same( $actual, $expected, $label ) {
	global $failures, $checks;
	++$checks;
	if ( $actual !== $expected ) {
		++$failures;
		echo "FAIL: {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
		return;
	}
	echo "PASS: {$label}\n";
}

faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 1 ), 180, 'GDPR raises an undersized value to 180 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 180 ), 180, 'GDPR accepts 180 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 181 ), 181, 'GDPR accepts the middle of the six-month window' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 182 ), 182, 'GDPR accepts 182 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 365 ), 182, 'GDPR caps an oversized value at 182 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr_ccpa', 365 ), 182, 'Both uses the stricter GDPR-family window' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'both', 90 ), 180, 'Both alias raises an undersized value to 180 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'ccpa', 1 ), 365, 'CCPA raises an undersized value to 365 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'ccpa', 365 ), 365, 'CCPA accepts 365 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'ccpa', 730 ), 730, 'CCPA preserves values longer than 12 months' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'unknown', 365 ), 182, 'Unknown models fail closed to the GDPR-family rule' );

$frontend_source = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
faz_expiry_same(
	false !== strpos( $frontend_source, '$faz_expiry = self::normalize_consent_expiry( $faz_law, $faz_configured_expiry );' ),
	true,
	'get_store_data uses the tested normalizer at the runtime boundary'
);

$banner_view = file_get_contents( dirname( __DIR__, 2 ) . '/admin/views/banner.php' );
faz_expiry_same(
	false !== strpos( $banner_view, 'id="faz-b-expiry" min="180" max="182" step="1"' ),
	true,
	'the server-rendered admin field starts with GDPR-safe bounds'
);

if ( $failures ) {
	echo "\n{$failures} of {$checks} consent expiry checks failed.\n";
	exit( 1 );
}

echo "\nALL PASS ({$checks} consent expiry checks)\n";
