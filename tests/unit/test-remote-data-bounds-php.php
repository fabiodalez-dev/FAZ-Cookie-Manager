<?php
/**
 * Remote data the plugin downloads is bounded (2026-11 audit, hardening).
 *
 *  - The Open Cookie Database refresh accepts only the category values the
 *    database is known to use, so a refresh cannot introduce a value this
 *    code was never written against; the bundled snapshot still validates.
 *  - The Open Cookie Database and the IAB GVL / purposes downloads ask
 *    WordPress for a size-capped body (limit_response_size), and a body that
 *    reached the cap is refused instead of being decoded.
 *  - The languages controller no longer carries download( $src ), an
 *    uncalled method that fetched any URL into uploads under its basename.
 *  - Every directory shipped in the plugin has an index.php.
 *
 * Run: php tests/unit/test-remote-data-bounds-php.php
 *
 * @package FazCookie\Tests\Unit
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'FAZ_PLUGIN_BASEPATH', dirname( __DIR__, 2 ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$options      = array();
$http_body    = '';
$http_calls   = array();
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { global $options; $options[ $key ] = $value; return true; }
function wp_remote_get( $url, $args = array() ) { global $http_calls; $http_calls[] = array( $url, $args ); return array(); }
function is_wp_error( $r ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return 200; }
function wp_remote_retrieve_body( $r ) { global $http_body; return $http_body; }
function current_time( $format, $gmt = false ) { return '2026-11-16 12:00:00'; }
function delete_transient( $key ) { return true; }
function __( $text, $domain = 'default' ) { return $text; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-cookie-definitions.php';
require_once $root . '/includes/class-gvl.php';
use FazCookie\Includes\Cookie_Definitions as Definitions;
use FazCookie\Includes\Gvl;

$passed = 0;
$failed = 0;
function rb_check( $cond, $label ) {
	global $passed, $failed;
	if ( $cond ) { ++$passed; echo "  PASS: $label\n"; }
	else { ++$failed; echo "  FAIL: $label\n"; }
}

echo "\n== Open Cookie Database: category allowlist ==\n";

$defs = Definitions::get_instance();
$ok   = array( 'a' => array( array( 'cookie' => '_ga', 'category' => 'Analytics' ) ) );

$http_body = json_encode( $ok );
$res       = $defs->update_definitions();
rb_check( true === $res['success'], 'a refresh with a known category is accepted' );

foreach ( array( 'Necessary', 'Functional', 'Analytics', 'Marketing', 'Security', 'Personalization', 'Uncategorized', '', ' marketing ' ) as $known ) {
	$http_body = json_encode( array( 'p' => array( array( 'cookie' => '_k', 'category' => $known ) ) ) );
	$res       = $defs->update_definitions();
	rb_check( true === $res['success'], 'known category "' . $known . '" is accepted' );
}

$options[ Definitions::OPTION_KEY ] = $ok;
foreach ( array( 'Strictly Necessary', 'necessary; marketing', 'Essential', '<b>Necessary</b>' ) as $unknown ) {
	$http_body = json_encode(
		array(
			'p' => array(
				array( 'cookie' => '_good', 'category' => 'Analytics' ),
				array( 'cookie' => '_bad', 'category' => $unknown ),
			),
		)
	);
	$res = $defs->update_definitions();
	rb_check(
		false === $res['success'] && $ok === $options[ Definitions::OPTION_KEY ],
		'a refresh carrying the unknown category "' . $unknown . '" is refused and the stored dataset kept'
	);
}

// The bundled snapshot must stay valid under the same rule, otherwise the
// rule would refuse the very data the plugin ships.
$bundled = json_decode( (string) file_get_contents( $root . '/includes/data/open-cookie-database.json' ), true );
$valid   = new ReflectionMethod( Definitions::class, 'valid_definitions' );
$valid->setAccessible( true );
rb_check( is_array( $bundled ) && true === $valid->invoke( $defs, $bundled ), 'the bundled snapshot passes the category allowlist' );

echo "\n== Download size caps ==\n";

$http_calls = array();
$http_body  = json_encode( $ok );
$defs->update_definitions();
$args = $http_calls[0][1];
rb_check(
	isset( $args['limit_response_size'] ) && Definitions::MAX_RESPONSE_BYTES === $args['limit_response_size'],
	'the definitions request asks for a size-capped body'
);

$options[ Definitions::OPTION_KEY ] = $ok;
// A body of exactly the cap is what limit_response_size hands back when it cut
// a longer one. Make it valid JSON anyway, to prove the length alone refuses.
$pad       = Definitions::MAX_RESPONSE_BYTES - strlen( json_encode( array( 'a' => array( array( 'cookie' => '_x', 'category' => 'Analytics', 'description' => '' ) ) ) ) );
$http_body = json_encode( array( 'a' => array( array( 'cookie' => '_x', 'category' => 'Analytics', 'description' => str_repeat( 'x', $pad ) ) ) ) );
rb_check( Definitions::MAX_RESPONSE_BYTES === strlen( $http_body ), 'fixture: body is exactly the cap' );
$res = $defs->update_definitions();
rb_check( false === $res['success'] && $ok === $options[ Definitions::OPTION_KEY ], 'a definitions body that reached the cap is refused' );

$gvl        = Gvl::get_instance();
$http_calls = array();
$vendors    = array( 'vendorListVersion' => 1, 'vendors' => array( '1' => array( 'id' => 1, 'name' => 'V' ) ) );
$pad        = Gvl::MAX_GVL_BYTES - strlen( json_encode( $vendors + array( 'x' => '' ) ) );
$http_body  = json_encode( $vendors + array( 'x' => str_repeat( 'x', $pad ) ) );
$res        = $gvl->download();
rb_check(
	isset( $http_calls[0][1]['limit_response_size'] ) && Gvl::MAX_GVL_BYTES === $http_calls[0][1]['limit_response_size'],
	'the GVL request asks for a size-capped body'
);
rb_check( false === $res['success'] && ! isset( $options[ Gvl::OPTION_KEY ] ), 'a GVL body that reached the cap is refused and nothing is stored' );

$http_calls = array();
$purposes   = array( 'purposes' => array( '1' => array( 'id' => 1 ) ) );
$pad        = Gvl::MAX_PURPOSES_BYTES - strlen( json_encode( $purposes + array( 'x' => '' ) ) );
$http_body  = json_encode( $purposes + array( 'x' => str_repeat( 'x', $pad ) ) );
$res        = $gvl->download_purposes( 'it' );
rb_check(
	isset( $http_calls[0][1]['limit_response_size'] ) && Gvl::MAX_PURPOSES_BYTES === $http_calls[0][1]['limit_response_size'],
	'the purposes request asks for a size-capped body'
);
rb_check( false === $res['success'] && ! isset( $options[ Gvl::PURPOSES_KEY ] ), 'a purposes body that reached the cap is refused' );

echo "\n== Dead remote write path and directory indexes ==\n";

$lang_src = (string) file_get_contents( $root . '/admin/modules/languages/includes/class-controller.php' );
rb_check( false === strpos( $lang_src, 'function download(' ), 'languages Controller has no download( $src ) method' );
rb_check( false === strpos( $lang_src, 'download_url(' ), 'languages Controller never calls download_url()' );

$missing = array();
foreach ( array( 'admin', 'includes', 'frontend', 'integrations', 'public', 'languages' ) as $top ) {
	if ( ! is_dir( $root . '/' . $top ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/' . $top, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $it as $entry ) {
		if ( $entry->isDir() && ! file_exists( $entry->getPathname() . '/index.php' ) ) {
			$missing[] = substr( $entry->getPathname(), strlen( $root ) + 1 );
		}
	}
	if ( ! file_exists( $root . '/' . $top . '/index.php' ) ) {
		$missing[] = $top;
	}
}
rb_check( array() === $missing, 'every shipped directory has an index.php' . ( $missing ? ' (missing: ' . implode( ', ', $missing ) . ')' : '' ) );

echo "\n$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
