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

// The six-month rule is a MAXIMUM. There is no legal minimum, so a publisher
// who asks again sooner is being more protective and the normalizer must leave
// that choice alone. These three assertions previously demanded a 180-day
// floor — they encoded a defect that silently extended stored consent on every
// install configured below it.
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 1 ), 1, 'GDPR keeps a deliberately short lifetime — no floor exists' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 30 ), 30, 'GDPR keeps a 30-day re-prompt, which is stricter than the cap' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 180 ), 180, 'GDPR accepts 180 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 181 ), 181, 'GDPR accepts the middle of the six-month window' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 182 ), 182, 'GDPR accepts 182 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 365 ), 182, 'GDPR caps an oversized value at 182 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr', 0 ), 182, 'An absent configuration still yields a usable lifetime' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'gdpr_ccpa', 365 ), 182, 'Both uses the stricter GDPR-family cap' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'both', 90 ), 90, 'Both alias also keeps a shorter, more protective lifetime' );
// CCPA is the one place a floor belongs: §1798.135 bars re-asking an opted-out
// consumer for twelve months, so a shorter value would re-prompt too soon.
faz_expiry_same( Frontend::normalize_consent_expiry( 'ccpa', 1 ), 365, 'CCPA raises an undersized value to 365 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'ccpa', 365 ), 365, 'CCPA accepts 365 days' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'ccpa', 730 ), 730, 'CCPA preserves values longer than 12 months' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'ccpa', 9999 ), 3650, 'CCPA enforces the 3650-day ceiling the admin help advertises' );
faz_expiry_same( Frontend::normalize_consent_expiry( 'unknown', 365 ), 182, 'Unknown models fail closed to the GDPR-family rule' );

$frontend_source = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
faz_expiry_same(
	false !== strpos( $frontend_source, '$faz_expiry = self::normalize_consent_expiry( $faz_law, $faz_configured_expiry );' ),
	true,
	'get_store_data uses the tested normalizer at the runtime boundary'
);

$banner_view = file_get_contents( dirname( __DIR__, 2 ) . '/admin/views/banner.php' );
faz_expiry_same(
	false !== strpos( $banner_view, 'id="faz-b-expiry" min="1" max="182" step="1"' ),
	true,
	'the admin field caps at 182 days and imposes no minimum'
);
faz_expiry_same(
	false !== strpos( $banner_view, 'id="faz-b-expiry-hint"' ),
	true,
	'a clamp on law change is announced instead of rewriting the field in silence'
);

$banner_js = file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/js/pages/banner.js' );
faz_expiry_same(
	false !== strpos( $banner_js, 'syncConsentExpiryConstraints(lawEl.value, true)' ),
	true,
	'only the user-initiated law change clamps the stored value'
);
faz_expiry_same(
	substr_count( $banner_js, 'syncConsentExpiryConstraints(lawEl.value, true)' ),
	1,
	'page load and form sync refresh the bounds without touching the value'
);

if ( $failures ) {
	echo "\n{$failures} of {$checks} consent expiry checks failed.\n";
	exit( 1 );
}

echo "\nALL PASS ({$checks} consent expiry checks)\n";
