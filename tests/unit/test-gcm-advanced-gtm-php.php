<?php
/**
 * Standalone unit tests: the Google Tag Manager container under Advanced
 * Consent Mode (#385).
 *
 * Subsystem: gcm-advanced-gtm-php
 *
 * Advanced Consent Mode (#165) exempts gtag.js / GA4 / Ads from blocking once
 * the synchronous denied `consent default` has been printed, and keeps the GTM
 * container (gtm.js) blocked because it can run non-Google tags that ignore
 * Consent Mode. Sites that configure GA4 and Google Ads conversions INSIDE Tag
 * Manager lost every cookieless ping for visitors who did not consent (issue
 * #385). The opt-in `advanced_mode_gtm` setting lets the container load before
 * consent too. Pinned here:
 *
 *   1. Frontend::is_gtm_container_script() — host + exact `/gtm.js` path for a
 *      src, a host-checked URL literal for an inline snippet; look-alike hosts,
 *      the name in a query, the noscript frame and gtag.js do not count.
 *   2. The output buffer lets gtm.js through only with Advanced mode on, the
 *      opt-in on AND the default emitted in this response; an explicit
 *      svc.google-tag-manager:no still blocks it; the noscript iframe stays
 *      blocked; non-Google trackers stay blocked; with the opt-in off the
 *      behaviour is unchanged (gtm.js?next=/gtag/js included).
 *   3. Google_Tag::render() prints the built-in GTM- container live only with
 *      both `gtag_unblocked` and `gtm_unblocked`.
 *
 * Run: php tests/unit/test-gcm-advanced-gtm-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	// Double for the `use FazCookie\Includes\Known_Providers` alias inside
	// class-frontend.php — no autoload, no JSON read. Also what
	// Google_Tag::category_for() reads.
	class Known_Providers {
		public static function get_all() {
			return array( 'google-tag-manager' => array( 'category' => 'analytics' ) );
		}
		public static function get_cookie_map() {
			return array();
		}
		public static function get_pattern_map() {
			return array();
		}
	}
}

namespace {

	// ---------- Bootstrap ----------

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	$GLOBALS['__faz_is_amp'] = false;

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $str ) {
			return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			$str = (string) $str;
			$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
			return trim( wp_strip_all_tags( $str ) );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $value ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $value ) {
			return str_replace( '&', '&amp;', (string) $value );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			if ( 'faz_is_amp_request' === $tag ) {
				return $GLOBALS['__faz_is_amp'];
			}
			return $value;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			$url = (string) $url;
			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'placeholder:' . $url;
			}
			return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); // phpcs:ignore
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) {
			return true;
		}
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $v ) {
			return abs( (int) $v );
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0 ) {
			return json_encode( $data, $flags );
		}
	}
	if ( ! function_exists( 'faz_sanitize_bool' ) ) {
		function faz_sanitize_bool( $v ) {
			if ( is_bool( $v ) ) {
				return $v;
			}
			if ( is_string( $v ) ) {
				return in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true );
			}
			return (bool) $v;
		}
	}

	// Minimal Gcm_Settings double: advanced flag + a key→value bag. The
	// container answer mirrors the real Gcm_Settings::is_advanced_gtm_mode()
	// unless a test pins it through $gtm_mode, which is how the suite proves
	// the frontend asks the settings class instead of re-deriving it.
	class FazTest_GcmSettings {
		public $advanced = true;
		public $bag      = array();
		public $gtm_mode = null;
		public function is_advanced_mode() {
			return (bool) $this->advanced;
		}
		public function is_advanced_gtm_mode() {
			if ( null !== $this->gtm_mode ) {
				return (bool) $this->gtm_mode;
			}
			return $this->is_advanced_mode() && faz_sanitize_bool( $this->get( 'advanced_mode_gtm' ) );
		}
		public function get( $key ) {
			return array_key_exists( $key, $this->bag ) ? $this->bag[ $key ] : null;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-google-tag.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Includes\Google_Tag;

	// ---------- assert helpers ----------

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function ok( $cond, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $cond ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
		}
	}

	// ---------- reflection harness ----------

	/**
	 * A Frontend on a first visit under an opt-in law (analytics + marketing
	 * blocked), with the given Advanced / container opt-in state.
	 */
	function faz_gtm_frontend( $advanced = true, $gtm = true, array $service_consent = array() ) {
		$rc  = new ReflectionClass( Frontend::class );
		$fe  = $rc->newInstanceWithoutConstructor();
		$gcm = new FazTest_GcmSettings();
		$gcm->advanced = $advanced;
		$gcm->bag      = array( 'advanced_mode_gtm' => $gtm );
		$preset = array(
			'gcm_settings'              => $gcm,
			'blocked_categories_cache'  => array( 'analytics', 'marketing' ),
			// The shipped catalogue patterns for the two Google loaders and the
			// noscript frame, a non-Google tracker, and an admin code rule naming
			// the container ID (the only way the inline snippet is matched
			// server-side: every built-in GTM pattern is a URL fragment).
			'provider_map_cache'        => array(
				'googletagmanager.com/gtm.js'  => 'analytics',
				'googletagmanager.com/gtag/js' => 'analytics',
				'googletagmanager.com/ns.html' => 'analytics',
				'connect.facebook.net'         => 'marketing',
				'GTM-ABC123'                   => 'analytics',
			),
			'service_consent_cache'     => $service_consent,
			'whitelist_cache'           => array(),
			'settings_option_cache'     => array(),
			'pattern_service_cache'     => array(
				'googletagmanager.com/gtm.js'  => array( 'google-tag-manager' ),
				'googletagmanager.com/ns.html' => array( 'google-tag-manager' ),
				'GTM-ABC123'                   => array( 'google-tag-manager' ),
				'googletagmanager.com/gtag/js' => array( 'google-analytics' ),
			),
			'provider_match_meta_cache' => null,
			'service_match_meta_cache'  => null,
		);
		foreach ( $preset as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}
	function faz_gtm_default( $fe ) {
		ob_start();
		$fe->print_gcm_default_inline();
		return ob_get_clean();
	}
	function faz_gtm_buffer( $fe, $html ) {
		$m = new ReflectionMethod( Frontend::class, 'process_output_buffer' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $html );
	}
	function faz_gtm_call( $fe, $method, $attrs, $content = '' ) {
		if ( ! method_exists( Frontend::class, $method ) ) {
			return 'missing method ' . $method;
		}
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $fe, $attrs, $content );
	}
	/** Whether a tag from the input survives the buffer byte for byte. */
	function faz_gtm_live( $out, $tag ) {
		return false !== strpos( $out, $tag );
	}

	$gtm_src     = '<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-ABC123"></script>';
	$gtm_inline  = '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer","GTM-ABC123");</script>';
	$gtm_next    = '<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-ABC123&next=/gtag/js"></script>';
	$gtm_ns      = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC123" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
	$gtag_loader = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script>';
	$fb_pixel    = '<script async src="https://connect.facebook.net/en_US/fbevents.js"></script>';

	function faz_gtm_page( $fe, $with_default, $body ) {
		$default = $with_default ? faz_gtm_default( $fe ) : '';
		return faz_gtm_buffer( $fe, '<html><head>' . $default . $body . '</head><body></body></html>' );
	}

	// =====================================================================
	// 1. The container matcher
	// =====================================================================
	echo "is_gtm_container_script() — host and exact path\n";
	$fe = faz_gtm_frontend();
	foreach ( array(
		' src="https://www.googletagmanager.com/gtm.js?id=GTM-X"'               => true,
		' src="//www.googletagmanager.com/gtm.js?id=GTM-X"'                     => true,
		' src="https://WWW.GoogleTagManager.com/GTM.JS?id=GTM-X"'               => true,
		' src="https://www.googletagmanager.com/gtm.js?id=GTM-X&amp;next=/gtag/js"' => true,
		' src="https://www.googletagmanager.com/ns.html?id=GTM-X"'              => false,
		' src="https://www.googletagmanager.com/gtag/js?id=G-X"'                => false,
		' src="https://googletagmanager.com.evil.example/gtm.js?id=GTM-X"'      => false,
		' src="https://notgoogletagmanager.com/gtm.js?id=GTM-X"'                => false,
		' src="https://tracker.example/x.js?u=https://www.googletagmanager.com/gtm.js"' => false,
		' src="https://www.googletagmanager.com/gtm.js/extra"'                  => false,
		' src="https://example.test/gtm.js?id=GTM-X"'                           => false,
	) as $attrs => $expected ) {
		ok( $expected === faz_gtm_call( $fe, 'is_gtm_container_script', $attrs ), ( $expected ? 'container: ' : 'not the container: ' ) . trim( $attrs ) );
	}
	$snippet_body = substr( $gtm_inline, 8, -9 );
	ok( true === faz_gtm_call( $fe, 'is_gtm_container_script', '', $snippet_body ), 'the standard inline snippet is the container' );
	ok( false === faz_gtm_call( $fe, 'is_gtm_container_script', '', 'var u="https://googletagmanager.com.evil.example/gtm.js";' ), 'an inline URL on a look-alike host is not' );
	ok( false === faz_gtm_call( $fe, 'is_gtm_container_script', '', 'var u="https://notgoogletagmanager.com/gtm.js";' ), 'an inline URL on a host that merely ends with the name is not' );
	ok( false === faz_gtm_call( $fe, 'is_gtm_container_script', '', 'dataLayer.push({"gtm.start":1,event:"gtm.js"});' ), 'container DATA (gtm.start / dataLayer.push) is not the container' );
	ok( false === faz_gtm_call( $fe, 'is_gtm_container_script', '', 'fbq("init","1");' ), 'an unrelated inline script is not' );

	// =====================================================================
	// 2. The output buffer
	// =====================================================================
	echo "\nAdvanced + container opt-in + default emitted\n";
	$fe  = faz_gtm_frontend( true, true );
	$out = faz_gtm_page( $fe, true, $gtm_src . $gtm_inline . $gtag_loader . $fb_pixel . $gtm_ns );
	ok( faz_gtm_live( $out, $gtm_src ), 'the gtm.js loader is left running' );
	ok( faz_gtm_live( $out, $gtm_inline ), 'the inline container snippet is left running' );
	ok( faz_gtm_live( $out, $gtag_loader ), 'gtag.js keeps its exemption' );
	ok( ! faz_gtm_live( $out, $fb_pixel ) && false !== strpos( $out, 'data-faz-category="marketing"' ), 'a non-Google tracker stays blocked' );
	ok( false === strpos( $out, '<iframe src="https://www.googletagmanager.com/ns.html' ), 'the noscript iframe stays blocked: no JS, no consent default' );
	ok( false !== strpos( $out, "gtag('consent','default'," ), 'the denied default is in front of the container' );
	ok( strpos( $out, "gtag('consent','default'," ) < strpos( $out, 'googletagmanager.com/gtm.js' ), 'and precedes it in document order' );

	echo "\nthe container waits in every other state\n";
	$fe  = faz_gtm_frontend( true, false );
	$out = faz_gtm_page( $fe, true, $gtm_src . $gtm_inline . $gtag_loader . $gtm_next );
	ok( ! faz_gtm_live( $out, $gtm_src ), 'opt-in off: gtm.js is blocked' );
	ok( ! faz_gtm_live( $out, $gtm_inline ), 'opt-in off: the inline snippet is blocked' );
	ok( ! faz_gtm_live( $out, $gtm_next ), 'opt-in off: gtm.js?next=/gtag/js is still not mistaken for gtag.js' );
	ok( faz_gtm_live( $out, $gtag_loader ), 'opt-in off: gtag.js keeps its exemption' );

	$fe  = faz_gtm_frontend( false, true );
	$out = faz_gtm_page( $fe, true, $gtm_src . $gtm_inline );
	ok( ! faz_gtm_live( $out, $gtm_src ) && ! faz_gtm_live( $out, $gtm_inline ), 'Advanced off: the opt-in alone exempts nothing' );

	$fe  = faz_gtm_frontend( true, true );
	$out = faz_gtm_page( $fe, false, $gtm_src . $gtm_inline );
	ok( ! faz_gtm_live( $out, $gtm_src ) && ! faz_gtm_live( $out, $gtm_inline ), 'no default emitted in this response: the container stays blocked' );

	$GLOBALS['__faz_is_amp'] = true;
	$fe  = faz_gtm_frontend( true, true );
	$out = faz_gtm_page( $fe, true, $gtm_src );
	ok( ! faz_gtm_live( $out, $gtm_src ), 'AMP (no default printed): the container stays blocked' );
	$GLOBALS['__faz_is_amp'] = false;

	echo "\nan explicit denial of the Tag Manager service wins\n";
	$fe  = faz_gtm_frontend( true, true, array( 'google-tag-manager' => 'no' ) );
	$out = faz_gtm_page( $fe, true, $gtm_src . $gtm_inline . $gtag_loader );
	ok( ! faz_gtm_live( $out, $gtm_src ), 'svc.google-tag-manager:no → gtm.js blocked' );
	ok( ! faz_gtm_live( $out, $gtm_inline ), 'svc.google-tag-manager:no → the inline snippet blocked' );
	ok( faz_gtm_live( $out, $gtag_loader ), '…while gtag.js keeps its exemption' );

	$fe  = faz_gtm_frontend( true, true, array( 'google-analytics' => 'no' ) );
	$out = faz_gtm_page( $fe, true, $gtm_src );
	ok( faz_gtm_live( $out, $gtm_src ), 'a denial of another service does not hold the container' );

	echo "\none source of truth: Gcm_Settings::is_advanced_gtm_mode()\n";
	// The raw option says on, but the settings class says off (as it would for
	// any condition it adds later). The frontend must follow the class.
	$fe       = faz_gtm_frontend( true, true );
	$rp       = new ReflectionProperty( Frontend::class, 'gcm_settings' );
	$rp->setAccessible( true );
	$rp->getValue( $fe )->gtm_mode = false;
	$out = faz_gtm_page( $fe, true, $gtm_src . $gtm_inline . $gtag_loader );
	ok( ! faz_gtm_live( $out, $gtm_src ) && ! faz_gtm_live( $out, $gtm_inline ), 'is_advanced_gtm_mode() false → the container stays blocked whatever the raw option holds' );
	ok( faz_gtm_live( $out, $gtag_loader ), '…and gtag.js keeps its exemption' );
	$fe_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	ok( 0 === preg_match( "/get\(\s*'advanced_mode_gtm'\s*\)/", $fe_src ), 'class-frontend.php never reads advanced_mode_gtm raw (store flag and exemption both go through the settings class)' );

	// =====================================================================
	// 3. The built-in GTM- container
	// =====================================================================
	echo "\nGoogle_Tag::render() — the built-in container\n";
	$ids  = array( 'G-ABCD1234', 'GTM-ABC123' );
	$park = '/<script type="text\/plain" data-faz-category="analytics" data-faz-service="google-tag-manager"/';
	ok( 1 === preg_match( $park, Google_Tag::render( $ids, array( 'gtag_unblocked' => true ) ) ), 'gtag exemption alone: the container stays parked' );
	ok( 1 === preg_match( $park, Google_Tag::render( $ids, array( 'gtm_unblocked' => true ) ) ), 'container flag without the gtag exemption: still parked' );
	$live = Google_Tag::render( $ids, array( 'gtag_unblocked' => true, 'gtm_unblocked' => true ) );
	ok( 0 === preg_match( $park, $live ) && 1 === preg_match( '/<script data-faz-google-tag="google-tag-manager">\(function\(w,d,s,l,i\)/', $live ), 'both: the container is printed live' );
	ok( false === strpos( $live, 'text/plain' ), 'both: nothing is parked' );

	// ---------- summary ----------
	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run}\n";
		exit( 0 );
	}
	echo "\033[31m{$tests_failed} FAILED\033[0m — {$tests_passed}/{$tests_run} passed\n";
	exit( 1 );
}
