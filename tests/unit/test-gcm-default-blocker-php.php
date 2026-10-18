<?php
/**
 * Standalone unit tests: the Advanced Consent Mode `consent default` must
 * survive the output-buffer blocker by construction, and the gtag exemption
 * must never outlive the default it depends on.
 *
 * Subsystem: gcm-default-blocker-php
 *
 * Advanced Consent Mode (#165) lets the Consent-Mode-aware Google stack
 * (gtag.js / GA4 / Ads) load before consent because a synchronous denied
 * `consent default` has already been printed at the top of <head>. That makes
 * the inline default the single thing standing between the exempted gtag
 * stack and pre-consent `_ga` / `_gcl_au` cookies. Two defects are pinned:
 *
 *   1. The default was printed as a bare `<script>` with no id, so it was the
 *      only FAZ-authored tag the blocker did not recognise as its own. Its
 *      survival rested on no provider pattern matching its body — and admin
 *      custom rules / `faz_blocking_rules` are matched as code signatures
 *      against inline bodies. A rule like `gtag(` (an input the Script
 *      Blocking help text invites) re-typed the default to text/plain while
 *      the gtag stack, matching the same rule, stayed exempted: Google then
 *      treated consent as granted. The default now carries an own-handle id
 *      and is returned untouched before any pattern is consulted.
 *
 *   2. The exemption did not check that the default had actually been
 *      emitted in this response. A page without wp_head() output (or an AMP
 *      response, where the default is deliberately not printed) exempted the
 *      gtag stack with no default in front of it. The exemption is now
 *      conditioned on print_gcm_default_inline() having emitted the tag in
 *      the same request; otherwise the stack is hard-blocked, which is the
 *      Basic-mode (fail-closed) behaviour.
 *
 * Run: php tests/unit/test-gcm-default-blocker-php.php
 *  or: bash scripts/run-unit-tests.sh
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

	// Minimal Gcm_Settings double: advanced flag + a key→value bag.
	class FazTest_GcmSettings {
		public $advanced = true;
		public $bag      = array();
		public function is_advanced_mode() {
			return (bool) $this->advanced;
		}
		public function get( $key ) {
			return array_key_exists( $key, $this->bag ) ? $this->bag[ $key ] : null;
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

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
	 * A Frontend with Advanced Consent Mode on, the given provider map and
	 * analytics + marketing blocked (a first visit under an opt-in law).
	 */
	function faz_adv_frontend( array $providers, $advanced = true ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$gcm = new FazTest_GcmSettings();
		$gcm->advanced = $advanced;
		$preset = array(
			'gcm_settings'              => $gcm,
			'blocked_categories_cache'  => array( 'analytics', 'marketing' ),
			'provider_map_cache'        => $providers,
			'service_consent_cache'     => array(),
			'whitelist_cache'           => array(),
			'settings_option_cache'     => array(),
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
	function faz_adv_default( $fe ) {
		ob_start();
		$fe->print_gcm_default_inline();
		return ob_get_clean();
	}
	function faz_adv_buffer( $fe, $html ) {
		$m = new ReflectionMethod( Frontend::class, 'process_output_buffer' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $html );
	}

	// The Google stack a theme or plugin prints: the loader plus the config
	// snippet. Both match a `gtag(`-style rule and the loader matches the
	// built-in URL pattern as well.
	$gtag_loader = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script>';
	$gtag_config = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-TEST');</script>";
	$builtin     = array( 'googletagmanager.com/gtag/js' => 'analytics' );

	// =====================================================================
	// 1. The default survives an admin code-signature rule that matches its body
	// =====================================================================
	echo "consent default vs. admin inline-code rules\n";

	foreach ( array( 'gtag(', 'gtag', 'function gtag', 'consent' ) as $rule ) {
		$fe      = faz_adv_frontend( $builtin + array( $rule => 'analytics' ) );
		$default = faz_adv_default( $fe );
		ok( '' !== $default, "[$rule] the default is emitted" );
		$html = '<html><head>' . $default . $gtag_loader . $gtag_config . '</head><body></body></html>';
		$out  = faz_adv_buffer( $fe, $html );
		ok( false !== strpos( $out, $default ), "[$rule] the inline consent default is returned untouched" );
		ok( false === strpos( $out, 'type="text/plain"' ), "[$rule] nothing in the Advanced-mode head is re-typed: default and exempted gtag stack both run" );
		ok( false !== strpos( $out, $gtag_loader ) && false !== strpos( $out, $gtag_config ), "[$rule] the gtag stack stays exempted" );
	}

	// A rule that does NOT touch the default keeps its ordinary effect on the
	// page: a non-Google tracker matching it is still re-typed.
	$fe      = faz_adv_frontend( $builtin + array( 'fbq(' => 'marketing' ) );
	$default = faz_adv_default( $fe );
	$out     = faz_adv_buffer( $fe, '<html><head>' . $default . '<script>fbq("init","1");</script></head></html>' );
	ok( false !== strpos( $out, $default ), 'an unrelated rule leaves the default alone' );
	ok( false !== strpos( $out, 'type="text/plain"' ) && false !== strpos( $out, 'data-faz-category="marketing"' ), 'and still blocks the tracker it was written for' );

	// =====================================================================
	// 2. The default is recognisable as the plugin's own tag
	// =====================================================================
	echo "\nthe default is an own tag, not an anonymous <script>\n";
	$fe      = faz_adv_frontend( $builtin );
	$default = faz_adv_default( $fe );
	ok( 1 === preg_match( '/<script\s+id="faz-cookie-manager-gcm-default-js-before"/', $default ), 'the default carries an own-handle id the blocker short-circuits on' );
	ok( false !== strpos( $default, "gtag('consent','default'," ), 'and still emits the consent default call' );

	// =====================================================================
	// 3. No default in this response → no exemption (fail closed)
	// =====================================================================
	echo "\nthe gtag exemption is conditioned on the default having been emitted\n";

	// print_gcm_default_inline() never ran on this instance (a template that
	// skips wp_head(), a response without <head>): the stack must be blocked
	// like any other tracker, because nothing set the denied baseline.
	$fe  = faz_adv_frontend( $builtin + array( 'gtag(' => 'analytics' ) );
	$out = faz_adv_buffer( $fe, '<html><head>' . $gtag_loader . $gtag_config . '</head><body></body></html>' );
	ok( 2 === substr_count( $out, 'type="text/plain"' ), 'without an emitted default both gtag tags are re-typed to text/plain' );
	ok( false === strpos( $out, $gtag_loader ), 'the gtag.js loader is not exempted when no default preceded it' );

	// AMP: the default is deliberately not printed (amp-consent owns the
	// signal), so the exemption must not fire there either.
	$GLOBALS['__faz_is_amp'] = true;
	$fe  = faz_adv_frontend( $builtin + array( 'gtag(' => 'analytics' ) );
	ok( '' === faz_adv_default( $fe ), 'AMP: the default is not printed' );
	$out = faz_adv_buffer( $fe, '<html><head>' . $gtag_loader . $gtag_config . '</head></html>' );
	ok( 2 === substr_count( $out, 'type="text/plain"' ), 'AMP: the gtag stack is hard-blocked, not exempted' );
	$GLOBALS['__faz_is_amp'] = false;

	// And once the default HAS been emitted on the same instance, the
	// exemption applies — the positive half of the same contract.
	$fe      = faz_adv_frontend( $builtin + array( 'gtag(' => 'analytics' ) );
	$default = faz_adv_default( $fe );
	$out     = faz_adv_buffer( $fe, '<html><head>' . $default . $gtag_loader . $gtag_config . '</head></html>' );
	ok( false === strpos( $out, 'type="text/plain"' ), 'with the default emitted the stack is exempted again' );

	// Advanced OFF: Basic mode never exempts, default or not.
	$fe  = faz_adv_frontend( $builtin, false );
	ok( '' === faz_adv_default( $fe ), 'Basic mode prints no default' );
	$out = faz_adv_buffer( $fe, '<html><head>' . $gtag_loader . '</head></html>' );
	ok( false !== strpos( $out, 'type="text/plain"' ), 'Basic mode blocks the loader as before' );

	// ---------- summary ----------
	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "\033[32mALL PASS\033[0m — {$tests_passed}/{$tests_run}\n";
		exit( 0 );
	}
	echo "\033[31m{$tests_failed} FAILED\033[0m — {$tests_passed}/{$tests_run} passed\n";
	exit( 1 );
}
