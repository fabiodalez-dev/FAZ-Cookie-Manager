<?php
/**
 * The LGPD scaffold must describe the IP hashing the consent log really does.
 *
 * The "Categories of personal data processed (Art. 5, I)" section of the LGPD
 * policy said "IP (hashed with monthly salt)" in six languages. The one record
 * a data subject or the ANPD can inspect — the consent log — stores
 * hash( 'sha256', $ip . wp_salt() ) (Consentlogs\Controller::hash_ip()): a
 * fixed per-installation salt, so the same IP yields the same hash for the
 * whole retention period. The monthly-rotating salt exists only in the
 * geo-routing module, for ephemeral cache keys. The published promise of
 * cross-month unlinkability was false for the retained record.
 *
 * This runner checks (1) the consent-log hash is indeed month-independent,
 * so the test fails if someone implements rotation without updating the text
 * the other way round; (2) no LGPD scaffold still claims a monthly salt and
 * the English one says "site-specific salt"; (3) the generated gettext
 * catalogue carries the same English section as en.md.
 *
 * Run: php tests/unit/test-lgpd-ip-hash-disclosure-php.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$tests_run = $tests_passed = $tests_failed = 0;
function lg_true( $c, $label, $detail = '' ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $c ) { $tests_passed++; echo "  ✓ $label\n"; }
	else { $tests_failed++; echo "  ✗ $label\n" . ( '' !== $detail ? "      $detail\n" : '' ); }
}

$root = dirname( __DIR__, 2 );
$dir  = $root . '/admin/modules/cookie-policy-generator/templates/lgpd-brazil';

echo "\n== LGPD scaffold: IP hashing disclosure matches the consent log ==\n\n";

// (1) The retained record's hash is the static-salt form, no month component.
$controller = (string) file_get_contents( $root . '/admin/modules/consentlogs/includes/class-controller.php' );
lg_true(
	false !== strpos( $controller, "return hash( 'sha256', \$ip . \\wp_salt() );" ),
	'consent log hashes the IP with the static per-install wp_salt() (premise of the disclosure)'
);
lg_true(
	! preg_match( "/function hash_ip\\(.*?gmdate\\(/s", substr( $controller, (int) strpos( $controller, 'function hash_ip(' ), 600 ) ),
	'consent-log hash_ip() has no date/month component'
);

// (2) No LGPD scaffold claims a monthly salt.
$monthly_markers = array(
	'en.md'    => 'monthly salt',
	'pt-BR.md' => 'sal mensal',
	'nl.md'    => 'maandelijkse salt',
	'hr.md'    => 'mjesečnim saltom',
	'bg.md'    => 'месечна',
	'cs.md'    => 'měsíčně',
);
foreach ( glob( $dir . '/*.md' ) as $file ) {
	$base = basename( $file );
	$text = (string) file_get_contents( $file );
	lg_true( false === stripos( $text, 'monthly' ), "lgpd-brazil/$base does not say \"monthly\"" );
	if ( isset( $monthly_markers[ $base ] ) ) {
		$marker = $monthly_markers[ $base ];
		// Case-insensitive on Unicode, with up to 40 characters of context on
		// each side for the failure message; no mbstring needed (#342).
		$found  = 1 === preg_match( '/.{0,40}' . preg_quote( $marker, '/' ) . '.{0,50}/isu', $text, $context );
		lg_true( ! $found, "lgpd-brazil/$base no longer claims a monthly salt (\"$marker\")", $found ? 'found: ' . trim( $context[0] ) : '' );
	}
}
$en = (string) file_get_contents( $dir . '/en.md' );
lg_true(
	false !== strpos( $en, 'IP (one-way hashed with a site-specific salt)' ),
	'lgpd-brazil/en.md says "IP (one-way hashed with a site-specific salt)"'
);

// (3) Gettext catalogue mirrors en.md for this section.
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) { return $text; }
}
$catalogue = require $root . '/admin/modules/cookie-policy-generator/includes/cookie-policy-gettext-catalog.php';
$found     = false;
foreach ( (array) ( $catalogue['lgpd-brazil'] ?? array() ) as $section ) {
	if ( 0 === strpos( $section, '## Categories of personal data processed' ) ) {
		$found = $section;
	}
}
lg_true( false !== $found && false !== strpos( $found, 'site-specific salt' ) && false === strpos( $found, 'monthly' ), 'gettext catalogue entry for lgpd-brazil section-3 matches the corrected en.md', var_export( $found, true ) );

echo "\n--\nTests:  $tests_run\nPassed: $tests_passed\nFailed: $tests_failed\n";
exit( $tests_failed > 0 ? 1 : 0 );
