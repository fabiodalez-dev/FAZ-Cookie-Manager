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

	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

namespace FazCookie\Frontend\Includes {
	class Geo_Runtime {
		public static $enabled = false;

		public static function is_enabled() {
			return self::$enabled;
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
	optout_notice_assert( false !== strpos( $output, 'Visitors under an opt-in privacy law are seeing no banner.' ), 'an active CCPA-only configuration emits the warning headline' );
	optout_notice_assert( false !== strpos( $output, 'Every active banner uses the opt-out (Do Not Sell) model.' ), 'the CCPA-only warning explains the configuration gap' );

	Controller::$items[] = (object) array(
		'status'   => 1,
		'settings' => array( 'settings' => array( 'applicableLaw' => 'gdpr' ) ),
	);
	ob_start();
	$admin->optout_only_banner_notice();
	$output = ob_get_clean();
	optout_notice_assert( '' === $output, 'an active opt-in banner suppresses the warning' );

	echo "\n--\nChecks: {$run}\nFailed: {$failed}\n\n";
	if ( $failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
