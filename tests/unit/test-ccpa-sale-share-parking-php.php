<?php
/**
 * Standalone unit tests — under an opt-out law the shared render parks the
 * sale/sharing categories, for script.js to release when no opt-out signal is
 * present.
 *
 * Subsystem: ccpa-sale-share-parking-php
 *
 * A CCPA first visit blocks nothing it may sell or share, and that render is
 * the copy a page cache keeps. Replayed to a visitor sending Sec-GPC: 1, with
 * navigator.globalPrivacyControl or with the `fazcookie-dnsmpi` cookie, the
 * Meta Pixel in it ran from the parser before script.js could apply the
 * opt-out: the signal the server never saw, because the cache answered. The
 * render now parks the sale/sharing categories in the usual shape and the
 * runtime releases them on its first pass when the visitor has no signal.
 *
 *   1. CCPA first visit, through the real output buffer: the sale/share tracker
 *      is parked (text/plain + data-faz-category), a script of a category that
 *      is neither sold nor shared stays executable.
 *   2. The visitor's own state, which the cookie layers enforce, still blocks
 *      nothing.
 *   3. `faz_ccpa_park_sell_share_by_default` false: executable, as before.
 *   4. A GDPR-law banner ("GDPR + US" included) is unchanged: sale/share is
 *      blocked by the opt-in default already, nothing else is touched.
 *   5. AMP: no runtime to release, no parking.
 *
 * Run: php tests/unit/test-ccpa-sale-share-parking-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
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
	class Geolocation {
		public static function get_visitor_country() {
			return '';
		}
		public static function has_country_source() {
			return false;
		}
	}
}

namespace FazCookie\Admin\Modules\Banners\Includes {
	class Controller {
		public static function get_instance() {
			return new self();
		}
		public function has_country_dependent_banners() {
			return false;
		}
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Category_Controller {
		public static function get_instance() {
			return new self();
		}
		public function get_items() {
			return array_map(
				function ( $slug ) {
					return array( 'slug' => $slug );
				},
				array( 'necessary', 'functional', 'marketing' )
			);
		}
	}
	class Cookie_Categories {
		private $data;
		public function __construct( $data ) {
			$this->data = $data;
		}
		public function get_slug() {
			return $this->data['slug'];
		}
		public function get_sell_personal_data() {
			return 'marketing' === $this->get_slug();
		}
		public function get_share_personal_data() {
			return 'marketing' === $this->get_slug();
		}
	}
}

namespace {

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );

	$GLOBALS['park_filters'] = array();

	function apply_filters( $tag, $value ) {
		return array_key_exists( $tag, $GLOBALS['park_filters'] ) ? $GLOBALS['park_filters'][ $tag ] : $value;
	}
	function get_option( $name, $default = false ) {
		return $default;
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
	function wp_strip_all_tags( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); // phpcs:ignore
	}
	function wp_hash( $data, $scheme = 'auth' ) {
		return hash_hmac( 'md5', (string) $data, 'park-salt-' . $scheme );
	}
	function get_transient( $key ) {
		return false;
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		return true;
	}
	// The validated-cookie boundary: a first visit has none.
	function faz_get_valid_consent_cookie() {
		return '';
	}

	require dirname( __DIR__, 2 ) . '/frontend/includes/class-geo-runtime.php';
	require dirname( __DIR__, 2 ) . '/includes/class-cache-compatibility.php';
	require dirname( __DIR__, 2 ) . '/includes/class-withdrawal-path.php';
	require dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';
	require __DIR__ . '/helpers/load-consent-scope.php';
	require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;
	function assert_true( $cond, $label ) {
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

	/** A first-visit request under a banner of the given law. */
	function park_frontend( $law ) {
		$_SERVER = array();
		$_COOKIE = array();
		$rc      = new ReflectionClass( Frontend::class );
		$fe      = $rc->newInstanceWithoutConstructor();
		$preset  = array(
			'settings_option_cache'      => array(),
			'geo_bootstrap_active_cache' => false,
			'provider_map_cache'         => array(
				'connect.facebook.net' => 'marketing',
				'cdn.chat.test'        => 'functional',
			),
			'service_consent_cache'      => array(),
			'whitelist_cache'            => array(),
			'pattern_service_cache'      => array(),
			'provider_match_meta_cache'  => null,
			'service_match_meta_cache'   => null,
			'template'                   => array( 'html' => '<div></div>' ),
			'banner'                     => new class( $law ) {
				private $law;
				public function __construct( $law ) {
					$this->law = $law;
				}
				public function get_law() {
					return $this->law;
				}
				public function get_slug() {
					return 'b-' . $this->law;
				}
			},
		);
		foreach ( $preset as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}
	function park_render( $fe ) {
		$m = new ReflectionMethod( Frontend::class, 'process_output_buffer' );
		$m->setAccessible( true );
		return $m->invoke( $fe, $GLOBALS['park_page'] );
	}
	function park_call( $fe, $method ) {
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $fe );
	}

	$park_page = '<html><head>'
		. '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>'
		. '<script src="https://cdn.chat.test/widget.js"></script>'
		. '</head><body></body></html>';
	$pixel_parked = '<script data-faz-category="marketing" type="text/plain" src="https://connect.facebook.net/en_US/fbevents.js"></script>';
	$pixel_live   = '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>';
	$chat_live    = '<script src="https://cdn.chat.test/widget.js"></script>';

	echo "\n-- 1-2. CCPA first visit --\n";
	$fe  = park_frontend( 'ccpa' );
	$out = park_render( $fe );
	assert_true( false !== strpos( $out, $pixel_parked ), 'the sale/share tracker is parked in the shared render' );
	assert_true( false !== strpos( $out, $chat_live ), 'a script of a category neither sold nor shared stays executable' );
	assert_true( array( 'marketing' ) === park_call( $fe, 'get_blocked_categories' ), 'the render blocks exactly the sale/share category' );
	assert_true( array() === park_call( $fe, 'get_enforcement_blocked_categories' ), 'the visitor\'s own state blocks nothing: the cookie layers leave sale/share cookies alone' );

	echo "\n-- 3. faz_ccpa_park_sell_share_by_default = false --\n";
	$GLOBALS['park_filters']['faz_ccpa_park_sell_share_by_default'] = false;
	$out = park_render( park_frontend( 'ccpa' ) );
	assert_true( false !== strpos( $out, $pixel_live ) && false === strpos( $out, 'text/plain' ), 'the tracker ships executable, as before' );
	unset( $GLOBALS['park_filters']['faz_ccpa_park_sell_share_by_default'] );

	echo "\n-- 4. GDPR-law banner (GDPR + US included) --\n";
	$fe  = park_frontend( 'gdpr' );
	$out = park_render( $fe );
	assert_true( false !== strpos( $out, $pixel_parked ), 'sale/share is blocked by the opt-in default, as before' );
	assert_true( false !== strpos( $out, '<script data-faz-category="functional" type="text/plain" src="https://cdn.chat.test/widget.js"></script>' ), 'and every optional category with it, as before' );
	assert_true( park_call( $fe, 'get_blocked_categories' ) === park_call( $fe, 'get_enforcement_blocked_categories' ), 'no render-only over-block under an opt-in law' );

	echo "\n-- 5. AMP --\n";
	$GLOBALS['park_filters']['faz_is_amp_request'] = true;
	$fe = park_frontend( 'ccpa' );
	assert_true( array() === park_call( $fe, 'get_blocked_categories' ), 'no runtime to release it, so nothing is parked' );
	unset( $GLOBALS['park_filters']['faz_is_amp_request'] );

	echo "\n--\n";
	echo "Tests:  {$tests_run}\n";
	echo "Passed: {$tests_passed}\n";
	echo "Failed: {$tests_failed}\n\n";
	if ( $tests_failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mALL PASS\033[0m\n";
}
