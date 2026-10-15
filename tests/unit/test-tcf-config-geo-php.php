<?php
/**
 * Standalone unit tests — the TCF payload's gdprApplies and publisherCC.
 *
 * Subsystem: tcf-config-geo-php
 *
 * Two fields of _fazTcfConfig were decided by hard-coded tables that disagreed
 * with the rest of the plugin:
 *
 *  - gdprApplies came from Geolocation::$eu_countries (EU27 + GB/IS/LI/NO),
 *    while the geo-routing catalogue routes CH, AD, MC, SM, VA — and every US
 *    visitor without a state ruleset — to gdpr-strict, whose
 *    `signals.tcf_v22` is true. Those visitors got the opt-in banner, blocked
 *    scripts and the TCF UI, and a TCData saying gdprApplies=false, which
 *    entitles vendors to ignore the TC string. The CMP API spec defines
 *    gdprApplies as the PUBLISHER's perspective; the ruleset is where the
 *    operator declared it. `signals.tcf_v22` had no consumer anywhere.
 *
 *  - publisherCC fell back to the literal 'IT' when the site locale carried no
 *    region (fi, el, et, lv, hr, ja, ...). The TC String spec reserves 'AA'
 *    for an unknown publisher country.
 *
 * The Frontend object is built without its constructor; the settings cache is
 * seeded by reflection and the collaborators are stubbed, as in
 * test-cache-compatibility-php.php.
 *
 * Run from project root:
 *   php tests/unit/test-tcf-config-geo-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}
	class Geolocation {
		public static $visitorCountry = '';
		public static $hasSource      = true;
		// Mirrors the real list's SHAPE (EU27 + GB + EEA); CH/AD/MC/SM/VA absent.
		public static $eu_countries = array( 'AT', 'BE', 'DE', 'FR', 'IT', 'GB', 'IS', 'LI', 'NO' );
		public static function get_visitor_country() {
			return self::$visitorCountry;
		}
		public static function has_country_source() {
			return self::$hasSource;
		}
	}
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
	class Gvl {
		public static $has_data = false;
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function has_data() {
			return self::$has_data;
		}
	}
}

namespace FazCookie\Frontend\Includes {
	class Geo_Runtime {
		public static $enabled = false;
		public static $ruleset = null;
		public static function is_enabled() {
			return self::$enabled;
		}
		public static function resolve_for_country( $country ) {
			return self::$enabled ? self::$ruleset : null;
		}
	}
}

namespace FazCookie\Admin\Modules\Banners\Includes {
	class Controller {
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function has_country_dependent_banners() {
			return false;
		}
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {
		private static $instance = null;
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		public function get_items() {
			return array();
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	$GLOBALS['faz_test_locale'] = 'en_US';
	function get_locale() {
		return $GLOBALS['faz_test_locale'];
	}
	function faz_sanitize_bool( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
	function wp_strip_all_tags( $str ) {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
	}
	function sanitize_text_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
	function absint( $v ) {
		return abs( (int) $v );
	}
	function faz_sanitize_text( $value ) {
		return is_array( $value ) ? array_map( 'faz_sanitize_text', $value ) : sanitize_text_field( $value );
	}
	function apply_filters( $tag, $value ) {
		return $value;
	}
	function add_filter() {
		return true;
	}
	function add_action() {
		return true;
	}
	function __( $text, $domain = 'default' ) {
		return $text;
	}
	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['faz_test_options'][ $name ] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-i18n-helpers.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;
	use FazCookie\Frontend\Includes\Geo_Runtime;
	use FazCookie\Includes\Geolocation;

	$tests_run = $tests_passed = $tests_failed = 0;
	function assert_eq( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m $label\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m $label\n";
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	/** A settings double exposing only what the TCF block reads. */
	class FazTest_Settings {
		private $iab;
		public function __construct( array $iab ) {
			$this->iab = $iab;
		}
		public function get( $group = '', $key = '' ) {
			if ( 'iab' !== $group ) {
				return array();
			}
			if ( '' === $key ) {
				return $this->iab;
			}
			return isset( $this->iab[ $key ] ) ? $this->iab[ $key ] : array();
		}
	}

	/** Frontend with seeded settings cache, settings double, and the geo-bootstrap memo forced off. */
	function faz_frontend( array $settings, array $iab = array() ) {
		$rc = new ReflectionClass( Frontend::class );
		$fe = $rc->newInstanceWithoutConstructor();
		$p  = new ReflectionProperty( Frontend::class, 'settings_option_cache' );
		$p->setAccessible( true );
		$p->setValue( $fe, $settings );
		$m = new ReflectionProperty( Frontend::class, 'geo_bootstrap_active_cache' );
		$m->setAccessible( true );
		$m->setValue( $fe, false );
		$s = new ReflectionProperty( Frontend::class, 'settings' );
		$s->setAccessible( true );
		$s->setValue( $fe, new FazTest_Settings( $iab ) );
		return $fe;
	}

	function faz_call( $fe, $method ) {
		if ( ! method_exists( $fe, $method ) ) {
			return "<missing method {$method}>";
		}
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $fe );
	}

	$off = array( 'banner_control' => array( 'cache_compatibility' => false ) );
	$strict = array( 'id' => 'gdpr-strict', 'model' => 'opt-in', 'signals' => array( 'tcf_v22' => true ) );
	$ccpa   = array( 'id' => 'ccpa-california', 'model' => 'opt-out', 'signals' => array( 'tcf_v22' => false ) );
	$lgpd   = array( 'id' => 'lgpd-brazil', 'model' => 'opt-in', 'signals' => array( 'tcf_v22' => false ) );

	echo "\n\033[1mTCF payload: gdprApplies follows the ruleset the visitor is under\033[0m\n\n";

	// Routing on, visitor in Zürich → gdpr-strict (tcf_v22 true). The banner is
	// opt-in, scripts are blocked, so the CMP must not tell vendors otherwise.
	Geo_Runtime::$enabled      = true;
	Geo_Runtime::$ruleset      = $strict;
	Geolocation::$visitorCountry = 'CH';
	assert_eq( faz_call( faz_frontend( $off ), 'resolve_tcf_gdpr_applies' ), true, 'CH under gdpr-strict (tcf_v22 true): gdprApplies is TRUE' );

	Geolocation::$visitorCountry = 'US';
	assert_eq( faz_call( faz_frontend( $off ), 'resolve_tcf_gdpr_applies' ), true, 'US visitor routed to gdpr-strict by the catalogue: gdprApplies is TRUE (publisher perspective)' );

	// A ruleset that maps to an opt-in model but is not TCF (LGPD) must stay
	// false: model is not the signal, tcf_v22 is.
	Geo_Runtime::$ruleset        = $lgpd;
	Geolocation::$visitorCountry = 'BR';
	assert_eq( faz_call( faz_frontend( $off ), 'resolve_tcf_gdpr_applies' ), false, 'BR under lgpd-brazil (opt-in but tcf_v22 false): gdprApplies is FALSE' );

	Geo_Runtime::$ruleset        = $ccpa;
	Geolocation::$visitorCountry = 'US';
	assert_eq( faz_call( faz_frontend( $off ), 'resolve_tcf_gdpr_applies' ), false, 'US under ccpa-california: gdprApplies is FALSE' );

	// Routing off: the legacy country list still decides.
	Geo_Runtime::$enabled        = false;
	Geo_Runtime::$ruleset        = null;
	Geolocation::$visitorCountry = 'DE';
	assert_eq( faz_call( faz_frontend( $off ), 'resolve_tcf_gdpr_applies' ), true, 'routing off, DE: gdprApplies TRUE from the EU list' );
	Geolocation::$visitorCountry = 'US';
	assert_eq( faz_call( faz_frontend( $off ), 'resolve_tcf_gdpr_applies' ), false, 'routing off, US: gdprApplies FALSE from the EU list' );
	Geolocation::$visitorCountry = '';
	assert_eq( faz_call( faz_frontend( $off ), 'resolve_tcf_gdpr_applies' ), true, 'routing off, country unknown: conservative TRUE' );

	// Cache Compatibility Mode: payload must be visitor-invariant → TRUE, even
	// for a US visitor the country list would mark FALSE. (With routing ON and
	// a country source the mode is PAUSED by design and the ruleset decides —
	// pinned by test-cache-compatibility-php.php — so routing is off here.)
	Geo_Runtime::$enabled        = false;
	Geo_Runtime::$ruleset        = null;
	Geolocation::$visitorCountry = 'US';
	assert_eq( faz_call( faz_frontend( array( 'banner_control' => array( 'cache_compatibility' => true ) ) ), 'resolve_tcf_gdpr_applies' ), true, 'cache compatibility ON: TRUE without reading the visitor country' );

	echo "\n\033[1mTCF payload: publisherCC is never invented\033[0m\n\n";

	Geo_Runtime::$enabled = false;
	assert_eq( faz_call( faz_frontend( $off, array( 'publisher_cc' => 'FR' ) ), 'resolve_tcf_publisher_cc' ), 'FR', 'a configured publisher country wins' );
	assert_eq( faz_call( faz_frontend( $off, array( 'publisher_cc' => 'de' ) ), 'resolve_tcf_publisher_cc' ), 'DE', 'a lower-case configured value is normalised' );

	$GLOBALS['faz_test_locale'] = 'de_DE';
	assert_eq( faz_call( faz_frontend( $off, array( 'publisher_cc' => '' ) ), 'resolve_tcf_publisher_cc' ), 'DE', 'no setting, locale with a region: the region is used' );

	$GLOBALS['faz_test_locale'] = 'fi';
	assert_eq( faz_call( faz_frontend( $off, array( 'publisher_cc' => '' ) ), 'resolve_tcf_publisher_cc' ), 'AA', 'no setting, region-less locale (fi): AA — unknown, not IT' );

	$GLOBALS['faz_test_locale'] = 'el';
	assert_eq( faz_call( faz_frontend( $off, array() ), 'resolve_tcf_publisher_cc' ), 'AA', 'no setting at all, region-less locale (el): AA' );

	echo "\n" . ( $tests_failed > 0 ? "\033[31m" : "\033[32m" ) . "{$tests_passed}/{$tests_run} passed\033[0m\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
