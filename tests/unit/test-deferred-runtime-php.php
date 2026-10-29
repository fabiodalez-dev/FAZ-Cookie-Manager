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
// The gate is no longer "is the bootstrap file readable" but "was the bootstrap
// attached to a head handle on this request" — a readable file that is never
// emitted, or emitted onto a footer handle, protects nothing. enqueue_scripts()
// resolves that before the first deferred enqueue; set it here the same way.
function faz_defer_emitted( $object, $value ) {
    $flag = new ReflectionProperty( \FazCookie\Frontend\Frontend::class, 'bootstrap_emitted' );
    $flag->setAccessible( true );
    $flag->setValue( $object, $value );
}
faz_defer_emitted( $frontend, true );
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

// The PageSpeed report also named banner-*.css. Preserve the non-matching
// media and CSP nonce while marking the link for the runtime's load handler.
$css_flag = new ReflectionProperty( $frontend, 'banner_css_non_blocking' );
$css_flag->setAccessible( true );
$css_flag->setValue( $frontend, true );
$plugin_name = new ReflectionProperty( $frontend, 'plugin_name' );
$plugin_name->setAccessible( true );
$plugin_name->setValue( $frontend, 'faz-cookie-manager' );
$css_link = '<link rel="stylesheet" id="faz-cookie-manager-css-css" href="/banner.css" media="print" nonce="csp" />';
$marked = $frontend->mark_banner_stylesheet( $css_link, 'faz-cookie-manager-css' );
check_defer( false !== strpos( $marked, 'data-faz-banner-css="1"' ), 'non-blocking banner stylesheet is discoverable by the runtime' );
check_defer( false !== strpos( $marked, 'media="print"' ) && false !== strpos( $marked, 'nonce="csp"' ), 'stylesheet marking preserves non-blocking media and CSP nonce' );
check_defer( $marked === $frontend->mark_banner_stylesheet( $marked, 'faz-cookie-manager-css' ), 'stylesheet marking is idempotent' );
check_defer( $css_link === $frontend->mark_banner_stylesheet( $css_link, 'theme-css' ), 'foreign stylesheet markup is unchanged' );
$css_flag->setValue( $frontend, false );
check_defer( $css_link === $frontend->mark_banner_stylesheet( $css_link, 'faz-cookie-manager-css' ), 'explicit blocking-style opt-out remains supported' );

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

// The emission verdict, which is what the defer gate now reads. A readable file
// is necessary and not sufficient: alternative-asset mode attaches no
// bootstrap, a footer-positioned chain attaches it after the head scripts have
// already run, and wp_add_inline_script() can refuse the payload outright. Each
// of those used to end in a deferred runtime with nothing holding its window,
// and in silence.
$GLOBALS['faz_defer_dir'] = dirname( __DIR__, 2 ) . '/frontend/';
$note = new ReflectionMethod( \FazCookie\Frontend\Frontend::class, 'note_bootstrap_emission' );
$note->setAccessible( true );
$flag = new ReflectionProperty( \FazCookie\Frontend\Frontend::class, 'bootstrap_emitted' );
$flag->setAccessible( true );

$verdicts = array(
    array( true, true, true, 'attached in the head -> emitted' ),
    array( true, false, false, 'attached but printing in the footer -> not emitted' ),
    array( false, true, false, 'not attached, head or not -> not emitted' ),
    array( false, false, false, 'neither -> not emitted' ),
);
foreach ( $verdicts as $case ) {
    $subject = ( new ReflectionClass( \FazCookie\Frontend\Frontend::class ) )->newInstanceWithoutConstructor();
    $note->invoke( $subject, $case[0], $case[1] );
    check_defer( $case[2] === $flag->getValue( $subject ), $case[3] );
}

// And the gate consumes it: the same handle defers or does not, on that flag
// alone, with the shipped bootstrap readable in both runs.
foreach ( array( true, false ) as $emitted ) {
    $GLOBALS['faz_defer_data'] = array();
    $subject = ( new ReflectionClass( \FazCookie\Frontend\Frontend::class ) )->newInstanceWithoutConstructor();
    faz_defer_emitted( $subject, $emitted );
    $gate = new ReflectionMethod( $subject, 'enqueue_deferred_script' );
    $gate->setAccessible( true );
    $gate->invoke( $subject, 'faz-cookie-manager', '/script.js', array(), 'test', false );
    $deferred = isset( $GLOBALS['faz_defer_data']['faz-cookie-manager']['strategy'] );
    check_defer( $emitted === $deferred, $emitted ? 'emitted -> defer' : 'not emitted -> stay synchronous' );
}
$GLOBALS['faz_defer_dir'] = dirname( __DIR__, 2 ) . '/frontend/';
$present = ( new ReflectionClass( \FazCookie\Frontend\Frontend::class ) )->newInstanceWithoutConstructor();
$present_js = new ReflectionMethod( $present, 'deferred_bootstrap_js' );
$present_js->setAccessible( true );
check_defer( false !== strpos( $present_js->invoke( $present ), '_fazBootstrap' ), 'the shipped bootstrap is found and carries the handoff' );

echo "ALL PASS ({$passed})\n";
