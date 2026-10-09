<?php
/** Verify actual generated script markup on supported old and new WP versions. */
define( 'ABSPATH', __DIR__ . '/' );
function is_admin() { return ! empty( $GLOBALS['faz_defer_admin'] ); }
function wp_enqueue_script( $handle, $src, $deps, $ver, $footer ) {
    $GLOBALS['faz_defer_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'footer' );
}
function wp_script_add_data( $handle, $key, $value ) {
    $GLOBALS['faz_defer_data'][ $handle ][ $key ] = $value;
}
require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
$frontend = ( new ReflectionClass( \FazCookie\Frontend\Frontend::class ) )->newInstanceWithoutConstructor();
$enqueue = new ReflectionMethod( $frontend, 'enqueue_deferred_script' );
$enqueue->setAccessible( true );
$enqueue->invoke( $frontend, 'faz-cookie-manager-static-config', '/config.js', array(), null, false );
$enqueue->invoke( $frontend, 'faz-cookie-manager', '/script.js', array( 'faz-cookie-manager-static-config' ), 'test', false );
$passed = 0;
function check_defer( $condition, $label ) {
    global $passed;
    if ( ! $condition ) { fwrite( STDERR, "FAIL: {$label}\n" ); exit( 1 ); }
    ++$passed;
}
foreach ( array( 'faz-cookie-manager-static-config', 'faz-cookie-manager' ) as $handle ) {
    check_defer( 'defer' === $GLOBALS['faz_defer_data'][ $handle ]['strategy'], 'native WP strategy' );
    $before = '<script id="' . $handle . '-js-before" nonce="csp">window.config={};</script>';
    $source = '<script src="/file.js" id="' . $handle . '-js" nonce="csp" data-no-defer="1"></script>';
    $tag = $frontend->defer_runtime_script_tag( $before . $source, $handle );
    check_defer( 0 === strpos( $tag, $before ), 'inline bootstrap remains synchronous' );
    check_defer( false !== strpos( $tag, '<script defer src=' ), 'external request is non-blocking on old WP' );
    check_defer( 2 === substr_count( $tag, 'nonce="csp"' ), 'CSP nonces retained' );
    check_defer( $tag === $frontend->defer_runtime_script_tag( $tag, $handle ), 'idempotent filter' );
    $async = $frontend->defer_runtime_script_tag( str_replace( '<script src', '<script async="async" src', $source ), $handle );
    check_defer( false === strpos( $async, ' async' ) && false !== strpos( $async, '<script defer ' ), 'async cannot reorder dependencies' );
}
$foreign = '<script src="/theme.js"></script>';
check_defer( $foreign === $frontend->defer_runtime_script_tag( $foreign, 'theme' ), 'foreign scripts untouched' );
$GLOBALS['faz_defer_admin'] = true;
check_defer( $foreign === $frontend->defer_runtime_script_tag( $foreign, 'faz-cookie-manager' ), 'admin untouched' );
$GLOBALS['faz_defer_admin'] = false;
$GLOBALS['wp_version'] = '6.3';
check_defer( $foreign === $frontend->defer_runtime_script_tag( $foreign, 'faz-cookie-manager' ), 'respect native dependency fallback on modern WP' );
echo "ALL PASS ({$passed})\n";
