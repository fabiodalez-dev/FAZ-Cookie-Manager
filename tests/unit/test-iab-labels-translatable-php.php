<?php
/**
 * Standalone unit test — every IAB label the preference centre shows is
 * translatable, in both places the visitor's strings come from.
 *
 * script.js renders the IAB/TCF section through _fazTranslate( key, fallback ),
 * which reads _fazStore._i18n first. That array is built twice: by
 * Frontend::get_store_data() for the page, and by Banner_Rest::build_i18n_payload()
 * when the banner is swapped to the visitor's language. A key present in
 * neither falls back to the English literal in script.js on every banner
 * language — and the Right-to-Object note added in 1.34.2 is a legal notice
 * to the visitor, not a cosmetic label.
 *
 * Static by design: it reads the three sources as text, so it cannot be
 * satisfied by a stub. A key used by script.js and missing from either PHP
 * array fails here.
 *
 * Run: php tests/unit/test-iab-labels-translatable-php.php
 *
 * @package FazCookie\Tests\Unit
 */

$root   = dirname( __DIR__, 2 );
$script = (string) file_get_contents( $root . '/frontend/js/script.js' );
$front  = (string) file_get_contents( $root . '/frontend/class-frontend.php' );
$rest   = (string) file_get_contents( $root . '/frontend/modules/banner-rest/class-banner-rest.php' );

$pass = 0;
$fail = 0;
function faz_check( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		++$fail;
		echo "  \033[31m✗\033[0m {$label}\n";
	}
}

preg_match_all( "/_fazTranslate\\(\\s*'(iab_[a-z_]+)'/", $script, $m );
$keys = array_values( array_unique( $m[1] ) );
sort( $keys );

faz_check( count( $keys ) >= 7, 'script.js uses the IAB label keys (found ' . count( $keys ) . ')' );

// The i18n array literal in each file: from "'_i18n'" (store) or the
// build_i18n_payload() body (REST) up to the end of the array.
$store_block = '';
if ( false !== ( $i = strpos( $front, "'_i18n'" ) ) ) {
	$store_block = substr( $front, $i, 6000 );
	$store_block = substr( $store_block, 0, (int) strpos( $store_block, "'_rtl'" ) );
}
$rest_block = '';
if ( false !== ( $i = strpos( $rest, 'function build_i18n_payload' ) ) ) {
	$rest_block = substr( $rest, $i, 8000 );
}

foreach ( $keys as $key ) {
	$re = "/'" . preg_quote( $key, '/' ) . "'\\s*=>\\s*__\\(/";
	faz_check( 1 === preg_match( $re, $store_block ), "{$key} is translated in the page store (_i18n)" );
	faz_check( 1 === preg_match( $re, $rest_block ), "{$key} is translated in the language-swap payload" );
}

echo "\n";
if ( 0 === $fail ) {
	echo "ALL PASS ({$pass})\n";
	exit( 0 );
}
echo "FAILED: {$fail}, passed: {$pass}\n";
exit( 1 );
