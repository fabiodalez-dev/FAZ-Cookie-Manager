<?php
/**
 * Real server blocking for every ruleset, both banner laws, all 16 category
 * choices, and independently asserted GPC/DNSMPI signals. The catalogue/HTTP
 * boundary is stubbed; Frontend and Geo_Runtime enforcement are unmodified.
 * Resolving a country is covered separately by test-ruleset-resolver.php.
 */
namespace FazCookie\Includes {
    class Geolocation {
        public static function get_visitor_country() { return 'IT'; }
    }
}
namespace FazCookie\Admin\Modules\Cookies\Includes {
    class Category_Controller {
        public static function get_instance() { return new self(); }
        public function get_items() {
            return array_map( function ( $slug ) { return array( 'slug' => $slug ); }, array( 'necessary', 'functional', 'analytics', 'marketing', 'profiling' ) );
        }
    }
    class Cookie_Categories {
        private $data;
        public function __construct( $data ) { $this->data = $data; }
        public function get_slug() { return $this->data['slug']; }
        public function get_sell_personal_data() { return 'marketing' === $this->get_slug(); }
        public function get_share_personal_data() { return 'marketing' === $this->get_slug(); }
    }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    function get_option( $name, $default = false ) { return array( 'geolocation' => array( 'geo_targeting' => true ) ); }
    function apply_filters( $tag, $value ) { return $value; }
    function wp_unslash( $value ) { return $value; }
    function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
    // This is the validated-cookie input boundary, NOT the parser under test.
    function faz_get_valid_consent_cookie() { return $GLOBALS['intent_cookie']; }
    require dirname( __DIR__, 2 ) . '/frontend/includes/class-geo-runtime.php';
    require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
    use FazCookie\Frontend\Frontend;
    use FazCookie\Frontend\Includes\Geo_Runtime;
    $class = new ReflectionClass( Frontend::class );
    $method = $class->getMethod( 'get_blocked_categories' );
    $method->setAccessible( true );
    $memo = new ReflectionProperty( Geo_Runtime::class, 'ruleset_memo' );
    $memo->setAccessible( true );
    function assign_intent_property( $object, $name, $value ) {
        $property = new ReflectionProperty( Frontend::class, $name );
        $property->setAccessible( true );
        $property->setValue( $object, $value );
    }
    $passed = 0;
    $failed = 0;
    function same_blocked( $actual, $expected, $label ) {
        global $passed, $failed;
        sort( $actual ); sort( $expected );
        if ( $actual === $expected ) { $passed++; return; }
        $failed++;
        echo 'FAIL ' . $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) . "\n";
    }
    $optional = array( 'functional', 'analytics', 'marketing', 'profiling' );
    $rulesets = 0;
    foreach ( glob( dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/rulesets/*.json' ) as $path ) {
        if ( '_' === basename( $path )[0] ) { continue; }
        $ruleset = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
        $rulesets++;
        $memo->setValue( null, array( 'IT' => $ruleset ) );
        foreach ( array( 'gdpr', 'ccpa' ) as $law ) {
            $frontend = $class->newInstanceWithoutConstructor();
            assign_intent_property( $frontend, 'settings_option_cache', array() );
            assign_intent_property( $frontend, 'geo_bootstrap_active_cache', false );
            assign_intent_property( $frontend, 'banner', new class( $law ) {
                private $law;
                public function __construct( $law ) { $this->law = $law; }
                public function get_law() { return $this->law; }
            } );
            $_SERVER = array(); $_COOKIE = array(); $GLOBALS['intent_cookie'] = '';
            $denied = array();
            foreach ( $optional as $slug ) {
                if ( ! in_array( $ruleset['ui']['default_categories'][ $slug ], array( 'granted', 'granted-locked' ), true ) ) { $denied[] = $slug; }
            }
            same_blocked( $method->invoke( $frontend ), $denied, $ruleset['id'] . '/' . $law . '/first-visit' );
            for ( $mask = 0; $mask < 16; $mask++ ) {
                for ( $signal = 0; $signal < 4; $signal++ ) {
                    $parts = array( 'action:yes', 'consent:yes', 'necessary:yes' );
                    $denied = array();
                    foreach ( $optional as $i => $slug ) {
                        $grant = (bool) ( $mask & ( 1 << $i ) );
                        $parts[] = $slug . ':' . ( $grant ? 'yes' : 'no' );
                        if ( ! $grant || ( $signal && 'marketing' === $slug ) ) { $denied[] = $slug; }
                    }
                    $_SERVER = $signal & 1 ? array( 'HTTP_SEC_GPC' => '1' ) : array();
                    $_COOKIE = $signal & 2 ? array( 'fazcookie-dnsmpi' => '1' ) : array();
                    $GLOBALS['intent_cookie'] = implode( ',', $parts );
                    assign_intent_property( $frontend, 'blocked_categories_cache', null );
                    same_blocked( $method->invoke( $frontend ), $denied, $ruleset['id'] . '/' . $law . '/choices-' . $mask . '/signal-' . $signal );
                }
            }
            // A newer catalogue category, or any non-yes value, must not
            // inherit a permissive default from a partially stored decision.
            foreach ( array( 'action:yes,necessary:yes', 'action:yes,analytics:garbage,marketing:0,profiling:true,functional:no' ) as $cookie ) {
                $GLOBALS['intent_cookie'] = $cookie;
                $_SERVER = array(); $_COOKIE = array();
                assign_intent_property( $frontend, 'blocked_categories_cache', null );
                same_blocked( $method->invoke( $frontend ), $optional, $ruleset['id'] . '/' . $law . '/missing-or-invalid-grants' );
            }
            // A shared cache shell must never contain executing optional scripts,
            // even if warmed with a previously accepted cookie.
            $GLOBALS['intent_cookie'] = 'action:yes,necessary:yes,functional:yes,analytics:yes,marketing:yes,profiling:yes';
            assign_intent_property( $frontend, 'geo_bootstrap_active_cache', true );
            assign_intent_property( $frontend, 'blocked_categories_cache', null );
            same_blocked( $method->invoke( $frontend ), $optional, $ruleset['id'] . '/' . $law . '/cache-warmer-grants' );
        }
    }
    if ( $rulesets < 47 ) { $failed++; echo "FAIL: missing jurisdictions\n"; }
    echo "jurisdiction-server-intent: {$passed} passed, {$failed} failed ({$rulesets} rulesets)\n";
    exit( $failed ? 1 : 0 );
}
