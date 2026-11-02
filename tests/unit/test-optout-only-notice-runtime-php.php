<?php
/**
 * Regression coverage for the opt-out-only admin notice runtime guard.
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	function current_user_can( $capability ) {
		return 'manage_options' === $capability;
	}

	function get_current_screen() {
		return (object) array( 'id' => $GLOBALS['faz_notice_screen'] ?? 'faz-cookie-manager-banner' );
	}
	function get_option( $key, $default = false ) { return 'faz_settings' === $key ? ( $GLOBALS['faz_notice_settings'] ?? array() ) : $default; }
	function home_url( $path ) { return 'https://example.test' . $path; }
	function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
	function wp_nonce_url( $url, $action, $key ) { return $url . '&' . $key . '=test-token'; }

	function esc_html__( $text, $domain = '' ) {
		unset( $domain );
		return $text;
	}

	function esc_url( $url ) {
		return $url;
	}

	function __( $text, $domain = '' ) {
		unset( $domain );
		return $text;
	}

	function esc_html( $text ) {
		return $text;
	}

	function esc_attr( $text ) {
		return $text;
	}

	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

namespace FazCookie\Frontend\Includes {
	class Geo_Runtime {
		public static $enabled = false;
		public static $mode    = 'gdpr_version';
		public static $state   = 'routing';

		public static function is_enabled() {
			return self::$enabled;
		}

		public static function missing_law_banner_mode() {
			return self::$mode;
		}
		public static function current_state() { return array( 'mode' => self::$state ); }
	}
}

namespace FazCookie\Admin\Modules\Banners\Includes {
	class Controller {
		public static $get_instance_calls = 0;
		public static $items              = array();

		public static function get_instance() {
			self::$get_instance_calls++;
			return new self();
		}

		public function get_items() {
			return self::$items;
		}
	}
}

namespace {
	require_once __DIR__ . '/../../admin/class-admin.php';

	use FazCookie\Admin\Admin;
	use FazCookie\Admin\Modules\Banners\Includes\Controller;
	use FazCookie\Frontend\Includes\Geo_Runtime;

	$run    = 0;
	$failed = 0;

	function optout_notice_assert( $condition, $label ) {
		global $run, $failed;
		$run++;
		if ( $condition ) {
			echo "  \033[32m✓\033[0m {$label}\n";
			return;
		}
		$failed++;
		echo "  \033[31m✗\033[0m {$label}\n";
	}

	echo "\n== Opt-out-only notice runtime guard ==\n\n";

	$admin = ( new \ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();

	Geo_Runtime::$enabled = false;
	ob_start();
	$admin->optout_only_banner_notice();
	$output = ob_get_clean();
	optout_notice_assert( '' === $output, 'runtime disabled produces no warning' );
	optout_notice_assert( 0 === Controller::$get_instance_calls, 'runtime disabled returns before querying banners' );

	Geo_Runtime::$enabled = true;
	ob_start();
	$admin->optout_only_banner_notice();
	$output = ob_get_clean();
	optout_notice_assert( '' === $output, 'runtime enabled with no banners produces no warning' );
	optout_notice_assert( 1 === Controller::$get_instance_calls, 'runtime enabled reaches the banner check' );

	Controller::$items = array(
		(object) array(
			'status'   => 1,
			'settings' => json_encode( array( 'settings' => array( 'applicableLaw' => 'ccpa' ) ) ),
		),
	);
	ob_start();
	$admin->optout_only_banner_notice();
	$output = ob_get_clean();
	optout_notice_assert( false !== strpos( $output, 'are seeing an opt-in version of your banner.' ), 'by default the CCPA-only notice says opt-in visitors see an opt-in version' );
	optout_notice_assert( false !== strpos( $output, 'notice-info' ), 'that default is information, not a warning' );
	optout_notice_assert( false === strpos( $output, 'seeing no banner' ), 'nothing says these visitors see no banner any more' );

	Geo_Runtime::$mode = 'main_banner';
	ob_start();
	$admin->optout_only_banner_notice();
	$output = ob_get_clean();
	optout_notice_assert( false !== strpos( $output, 'are seeing an opt-in version of your banner.' ), 'a legacy main_banner preference cannot change the protective notice' );
	optout_notice_assert( false === strpos( $output, 'are seeing your opt-out banner.' ), 'the notice no longer advertises the retired waiver' );
	Geo_Runtime::$mode = 'gdpr_version';

	$GLOBALS['faz_notice_screen'] = 'dashboard';
	Geo_Runtime::$state = 'baseline';
	ob_start();
	$admin->optout_only_banner_notice();
	$output = ob_get_clean();
	optout_notice_assert( false !== strpos( $output, 'every visitor receives the protective opt-in fallback' ), 'the WordPress dashboard explains that no country source affects everyone' );
	optout_notice_assert( false !== strpos( $output, 'faz_banner_test=1' ) && false !== strpos( $output, '_faz_test_nonce=test-token' ), 'the on-site check link is nonce-bound' );
	optout_notice_assert( false !== strpos( $output, 'without clearing consent' ), 'the check explains why a returning administrator may otherwise see no banner' );

	$GLOBALS['faz_notice_screen'] = 'plugins';
	ob_start();
	$admin->optout_only_banner_notice();
	optout_notice_assert( '' === ob_get_clean(), 'unrelated WordPress screens are not filled with notices' );
	$GLOBALS['faz_notice_screen'] = 'faz-cookie-manager-banner';

	Controller::$items[] = (object) array(
		'status'   => 1,
		'settings' => array( 'settings' => array( 'applicableLaw' => 'gdpr' ) ),
		'target_countries' => array( 'DE' ),
	);
	ob_start();
	$admin->optout_only_banner_notice();
	optout_notice_assert( false !== strpos( ob_get_clean(), 'No active GDPR banner covers every country' ), 'a country-restricted GDPR banner does not hide the fallback notice' );
	Controller::$items[1]->target_countries = array();
	ob_start();
	$admin->optout_only_banner_notice();
	$output = ob_get_clean();
	optout_notice_assert( '' === $output, 'an active opt-in banner suppresses the notice' );
	Controller::$items = array( Controller::$items[0] );
	$GLOBALS['faz_notice_settings'] = array( 'banner_control' => array( 'status' => false ) );
	ob_start();
	$admin->optout_only_banner_notice();
	optout_notice_assert( '' === ob_get_clean(), 'a deliberately disabled global banner is not advertised as visible' );

	echo "\n--\nChecks: {$run}\nFailed: {$failed}\n\n";
	if ( $failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
