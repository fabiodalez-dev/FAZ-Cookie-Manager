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
		return (object) array( 'id' => 'faz-cookie-manager-banner' );
	}

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

		public static function is_enabled() {
			return self::$enabled;
		}

		public static function missing_law_banner_mode() {
			return self::$mode;
		}
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
	optout_notice_assert( false !== strpos( $output, 'are seeing your opt-out banner.' ), 'with main_banner the notice says opt-in visitors see the opt-out banner' );
	optout_notice_assert( false !== strpos( $output, 'notice-warning' ) && false !== strpos( $output, 'EU law does not allow' ), 'and warns, naming the legal consequence' );
	Geo_Runtime::$mode = 'gdpr_version';

	Controller::$items[] = (object) array(
		'status'   => 1,
		'settings' => array( 'settings' => array( 'applicableLaw' => 'gdpr' ) ),
	);
	ob_start();
	$admin->optout_only_banner_notice();
	$output = ob_get_clean();
	optout_notice_assert( '' === $output, 'an active opt-in banner suppresses the notice' );

	echo "\n--\nChecks: {$run}\nFailed: {$failed}\n\n";
	if ( $failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
