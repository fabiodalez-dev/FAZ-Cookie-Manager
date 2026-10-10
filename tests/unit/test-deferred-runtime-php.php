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
function wp_add_inline_script( $handle, $data, $position = 'after' ) {
    if ( ! $data ) { return false; }
    $GLOBALS['faz_defer_inline'][ $handle ][ $position ][] = $data;
    return true;
}
function plugin_dir_path( $file ) { return $GLOBALS['faz_defer_dir']; }
function faz_asset_suffix( $asset ) { return ''; }
require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
// The real bootstrap asset, so "deferring is safe" is proven against the file
// that actually ships rather than against a fixture.
$GLOBALS['faz_defer_dir'] = dirname( __DIR__, 2 ) . '/frontend/';
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

// Without a readable bootstrap, deferring would leave the runtime arriving
// after the page's own scripts with nothing holding dynamic resources in
// between. file_get_contents() returns false there and
// WP_Scripts::add_inline_script() drops a falsy payload silently, so the old
// code emitted no bootstrap and deferred anyway — a pre-consent window that
// raised no error. Deferring must switch itself off instead.
$GLOBALS['faz_defer_dir']     = dirname( __DIR__, 2 ) . '/frontend/does-not-exist/';
$GLOBALS['faz_defer_scripts'] = array();
$GLOBALS['faz_defer_data']    = array();
$bare = ( new ReflectionClass( \FazCookie\Frontend\Frontend::class ) )->newInstanceWithoutConstructor();
$bare_enqueue = new ReflectionMethod( $bare, 'enqueue_deferred_script' );
$bare_enqueue->setAccessible( true );
$bare_enqueue->invoke( $bare, 'faz-cookie-manager', '/script.js', array(), 'test', false );
check_defer( isset( $GLOBALS['faz_defer_scripts']['faz-cookie-manager'] ), 'the script is still enqueued without the bootstrap' );
check_defer( ! isset( $GLOBALS['faz_defer_data']['faz-cookie-manager']['strategy'] ), 'no bootstrap -> no defer strategy' );
$untouched = '<script src="/script.js" id="faz-cookie-manager-js"></script>';
check_defer( $untouched === $bare->defer_runtime_script_tag( $untouched, 'faz-cookie-manager' ), 'no bootstrap -> the old-WP fallback adds no defer either' );

$readable = new ReflectionMethod( $bare, 'deferred_bootstrap_js' );
$readable->setAccessible( true );
check_defer( '' === $readable->invoke( $bare ), 'an unreadable bootstrap reads as empty, never as false' );
$GLOBALS['faz_defer_dir'] = dirname( __DIR__, 2 ) . '/frontend/';
$present = ( new ReflectionClass( \FazCookie\Frontend\Frontend::class ) )->newInstanceWithoutConstructor();
$present_js = new ReflectionMethod( $present, 'deferred_bootstrap_js' );
$present_js->setAccessible( true );
check_defer( false !== strpos( $present_js->invoke( $present ), '_fazBootstrap' ), 'the shipped bootstrap is found and carries the handoff' );

echo "ALL PASS ({$passed})\n";
