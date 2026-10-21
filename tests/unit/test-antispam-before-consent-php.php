<?php
/**
 * #343: reCAPTCHA, hCaptcha and Turnstile are allowed before consent only while
 * Settings → Script Blocking → "Allow anti-spam services before consent" is on.
 *
 * The four challenge endpoints were hard-coded into get_whitelist(), ahead of
 * the provider rules, so a site owner who wanted reCAPTCHA held until consent
 * could not get there from the settings: removing it from the exceptions and
 * adding a custom rule changed nothing. This drives the real get_whitelist(),
 * the client configuration builder's whitelist and the output buffer.
 *
 * Run: php tests/unit/test-antispam-before-consent-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	// Double for the `use FazCookie\Includes\Known_Providers` alias inside
	// class-frontend.php — no autoload, no JSON read.
	class Known_Providers {
		public static function get_all() {
			return array();
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
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); // phpcs:ignore
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

	require_once dirname( __DIR__, 2 ) . '/includes/class-store.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	if ( ! function_exists( 'faz_sanitize_bool_strict' ) ) {
		function faz_sanitize_bool_strict( $value ) {
			return true === $value || 1 === $value || '1' === $value || 'true' === $value;
		}
	}

	$tests_run = 0;
	$tests_failed = 0;
	function asc_check( $cond, $label ) {
		global $tests_run, $tests_failed;
		$tests_run++;
		if ( $cond ) {
			echo "  \033[32m✓\033[0m {$label}\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m {$label}\n";
		}
	}

	/**
	 * @param array|null $settings The stored faz_settings value.
	 */
	function asc_frontend( $settings, array $providers = array(), array $blocked = array() ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$preset = array(
			'blocked_categories_cache'  => $blocked,
			'provider_map_cache'        => $providers,
			'service_consent_cache'     => array(),
			'whitelist_cache'           => null,
			'settings_option_cache'     => $settings,
			'pattern_service_cache'     => array(),
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

	function asc_call( $fe, $method, array $args = array() ) {
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $fe, $args );
	}

	$challenge = array( 'google.com/recaptcha', 'gstatic.com/recaptcha', 'hcaptcha.com', 'challenges.cloudflare.com/turnstile' );
	$off       = array( 'script_blocking' => array( 'allow_antispam_before_consent' => false ) );
	$on        = array( 'script_blocking' => array( 'allow_antispam_before_consent' => true ) );

	echo "\n-- get_whitelist() --\n";
	$legacy = asc_call( asc_frontend( array() ), 'get_whitelist' );
	asc_check( array() === array_diff( $challenge, $legacy ), 'an install without the setting keeps the four challenge endpoints (default unchanged)' );
	$wl_on = asc_call( asc_frontend( $on ), 'get_whitelist' );
	asc_check( array() === array_diff( $challenge, $wl_on ), 'switched on: the four challenge endpoints are allowed' );
	$wl_off = asc_call( asc_frontend( $off ), 'get_whitelist' );
	asc_check( array() === array_intersect( $challenge, $wl_off ), 'switched off: none of the four challenge endpoints is whitelisted any more' );
	asc_check( in_array( 'faz-cookie-manager', $wl_off, true ) && in_array( 'wp-includes/', $wl_off, true ), 'switched off: the plugin and WordPress core stay protected' );
	$wl_user = asc_call( asc_frontend( array( 'script_blocking' => array( 'allow_antispam_before_consent' => false, 'whitelist_patterns' => array( 'hcaptcha.com' ) ) ) ), 'get_whitelist' );
	asc_check( in_array( 'hcaptcha.com', $wl_user, true ) && ! in_array( 'google.com/recaptcha', $wl_user, true ), 'switched off: an exception the owner keeps still applies, and only that one' );

	echo "\n-- a default install: the shipped CAPTCHA exceptions follow the switch --\n";
	// A fresh install stores these four in Script Blocking Exceptions, and every
	// exception wins over every rule, so they alone would keep reCAPTCHA live.
	$shipped     = \FazCookie\Admin\Modules\Settings\Includes\Settings::DEFAULT_ANTISPAM_EXCEPTIONS;
	$recaptcha   = 'https://www.google.com/recaptcha/api.js?render=KEY';
	$covers      = function ( array $whitelist, $url ) {
		foreach ( $whitelist as $p ) {
			if ( '' !== $p && false !== stripos( $url, $p ) ) {
				return true;
			}
		}
		return false;
	};
	$defaults_off = array( 'script_blocking' => array( 'allow_antispam_before_consent' => false, 'whitelist_patterns' => $shipped ) );
	$defaults_on  = array( 'script_blocking' => array( 'allow_antispam_before_consent' => true, 'whitelist_patterns' => $shipped ) );
	asc_check( ! $covers( asc_call( asc_frontend( $defaults_off ), 'get_whitelist' ), $recaptcha ), 'switched off on a default install: nothing in the whitelist covers reCAPTCHA' );
	asc_check( $covers( asc_call( asc_frontend( $defaults_on ), 'get_whitelist' ), $recaptcha ), 'switched on on a default install: reCAPTCHA is covered, as before' );
	$own = array( 'script_blocking' => array( 'allow_antispam_before_consent' => false, 'whitelist_patterns' => array_merge( $shipped, array( 'recaptcha', 'my-inline-script-id' ) ) ) );
	$own_list = asc_call( asc_frontend( $own ), 'get_user_whitelist_patterns' );
	asc_check( array( 'recaptcha', 'my-inline-script-id' ) === $own_list, 'switched off: only the shipped entries go, exceptions the owner wrote stay' );
	asc_check( $shipped === asc_call( asc_frontend( $defaults_on ), 'get_user_whitelist_patterns' ), 'switched on: the stored exceptions are returned untouched' );
	$out_defaults = asc_call( asc_frontend( $defaults_off, array( 'google.com/recaptcha' => 'functional' ), array( 'functional' ) ), 'process_output_buffer', array( '<html><head></head><body><script src="' . $recaptcha . '" id="r-js"></script></body></html>' ) );
	asc_check( false !== strpos( $out_defaults, 'type="text/plain"' ), 'switched off on a default install: the rule parks reCAPTCHA in the HTML' );
	$fe_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	asc_check( 3 === substr_count( $fe_src, '$this->get_user_whitelist_patterns()' ), 'server whitelist, client whitelist and cookie exemptions all read the exceptions through the same filter' );
	asc_check( false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php' ), "'whitelist_patterns' => self::DEFAULT_ANTISPAM_EXCEPTIONS," ), 'the shipped exceptions are the list the filter recognises' );

	echo "\n-- the catalogue holds CAPTCHAs under functional when the switch is off --\n";
	$cats = array( 'necessary', 'functional', 'analytics', 'marketing' );
	foreach ( array( 'google.com/recaptcha', 'recaptcha/api.js', 'js.hcaptcha.com', 'challenges.cloudflare.com/turnstile', 'wpforms-recaptcha', 'nf-google-recaptcha' ) as $pat ) {
		asc_check( 'functional' === asc_call( asc_frontend( $off ), 'known_provider_category', array( 'necessary', $pat, $cats ) ), "switched off: {$pat} is held under functional" );
	}
	asc_check( 'necessary' === asc_call( asc_frontend( $on ), 'known_provider_category', array( 'necessary', 'google.com/recaptcha', $cats ) ), 'switched on: reCAPTCHA keeps the catalogue category' );
	asc_check( 'necessary' === asc_call( asc_frontend( $off ), 'known_provider_category', array( 'necessary', 'wp-includes/js/jquery', $cats ) ), 'switched off: other necessary patterns are untouched' );
	asc_check( 'marketing' === asc_call( asc_frontend( $off ), 'known_provider_category', array( 'marketing', 'connect.facebook.net', $cats ) ), 'switched off: non-necessary providers are untouched' );
	asc_check( 'necessary' === asc_call( asc_frontend( $off ), 'known_provider_category', array( 'necessary', 'google.com/recaptcha', array( 'necessary', 'analytics' ) ) ), 'a site without a functional category keeps the catalogue answer' );
	asc_check( 2 === substr_count( $fe_src, '$this->known_provider_category(' ), 'the server map and the client provider list both apply it' );

	echo "\n-- output buffer: a custom rule now blocks reCAPTCHA --\n";
	$providers = array( 'google.com/recaptcha' => 'functional' );
	$html      = '<html><head></head><body><script src="https://www.google.com/recaptcha/api.js?render=KEY" id="elementor-recaptcha_v3-api-js"></script></body></html>';
	$out_off   = asc_call( asc_frontend( $off, $providers, array( 'functional' ) ), 'process_output_buffer', array( $html ) );
	asc_check( false !== strpos( $out_off, 'type="text/plain"' ) && false !== strpos( $out_off, 'data-faz-category="functional"' ), 'switched off: reCAPTCHA matched by the rule is parked until the functional category is accepted' );
	$out_on = asc_call( asc_frontend( $on, $providers, array( 'functional' ) ), 'process_output_buffer', array( $html ) );
	asc_check( $out_on === $html, 'switched on: reCAPTCHA is left live, as before' );

	echo "\n-- client whitelist mirrors the server --\n";
	$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
	asc_check( false !== strpos( $src, '$client_antispam = $this->antispam_allowed_before_consent() ? self::ANTISPAM_CHALLENGE_PATTERNS : array();' ), 'the runtime whitelist gets the same endpoints only while the switch is on' );
	$settings_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php' );
	asc_check( 1 === preg_match( "/'allow_antispam_before_consent'\s*=>\s*true/", $settings_src ), 'the setting defaults to on' );
	asc_check( false !== strpos( $settings_src, "case 'allow_antispam_before_consent':\n\t\t\t\t\$value = faz_sanitize_bool( \$value );" ), 'the setting is sanitised as a boolean' );
	$view_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/views/settings.php' );
	asc_check( false !== strpos( $view_src, 'data-path="script_blocking.allow_antispam_before_consent"' ), 'the switch is on the Script Blocking settings screen' );

	echo "\nPassed: " . ( $tests_run - $tests_failed ) . "; Failed: {$tests_failed}\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
