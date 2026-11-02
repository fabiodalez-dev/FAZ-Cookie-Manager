<?php
/** The on-site banner check is admin-only and cannot enter a shared cache. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['faz_test_admin'] = true;
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['faz_test_admin']; }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'faz_banner_test' === $action; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return is_string( $value ) ? $value : ''; }
require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
$frontend = ( new ReflectionClass( \FazCookie\Frontend\Frontend::class ) )->newInstanceWithoutConstructor();
$check = new ReflectionMethod( $frontend, 'is_admin_banner_test_request' );
$check->setAccessible( true );
$passed = 0;
foreach ( array(
	array( array(), true, false ),
	array( array( 'faz_banner_test' => '1' ), true, false ),
	array( array( 'faz_banner_test' => '1', '_faz_test_nonce' => 'wrong' ), true, false ),
	array( array( 'faz_banner_test' => '1', '_faz_test_nonce' => array( 'valid' ) ), true, false ),
	array( array( 'faz_banner_test' => '1', '_faz_test_nonce' => 'valid' ), false, false ),
	array( array( 'faz_banner_test' => '1', '_faz_test_nonce' => 'valid' ), true, true ),
) as list( $query, $admin, $expected ) ) {
	$_GET = $query;
	$GLOBALS['faz_test_admin'] = $admin;
	if ( $expected !== $check->invoke( $frontend ) ) { throw new RuntimeException( 'Banner check authorization failed' ); }
	++$passed;
}
echo "ALL PASS ({$passed})\n";
