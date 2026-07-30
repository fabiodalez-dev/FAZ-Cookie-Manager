<?php
/**
 * Runtime-oriented unit tests for the guided setup wizard.
 *
 * These checks execute the real Onboarding class against an in-memory
 * Banner/Controller/Settings implementation. They cover exact persisted law
 * models, multilingual/custom-content preservation, the targeted-only fallback
 * case, and the atomic failure path where Store::save() returns an ID even
 * though the underlying update did not persist.
 *
 * Run: php tests/unit/test-onboarding-runtime-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code, $message, $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	function faz_sanitize_bool( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	$GLOBALS['faz_onboarding_runtime_cache_clears'] = 0;
	function faz_clear_banner_template_cache() {
		$GLOBALS['faz_onboarding_runtime_cache_clears']++;
	}

	class Faz_Runtime_Wpdb {
		private $snapshot = null;

		public function query( $sql ) {
			$command = strtoupper( trim( $sql ) );
			if ( 'START TRANSACTION' === $command ) {
				$this->snapshot = array(
					'banners' => \FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance()->snapshot_state(),
					'settings' => \FazCookie\Admin\Modules\Settings\Includes\Settings::$storage,
					'gcm'      => \FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings::$storage,
				);
				return 0;
			}
			if ( 'ROLLBACK' === $command && is_array( $this->snapshot ) ) {
				\FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance()->restore_state( $this->snapshot['banners'] );
				\FazCookie\Admin\Modules\Settings\Includes\Settings::$storage = $this->snapshot['settings'];
				\FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings::$storage = $this->snapshot['gcm'];
				$this->snapshot = null;
				return 0;
			}
			if ( 'COMMIT' === $command ) {
				$this->snapshot = null;
				return 0;
			}
			return 0;
		}
	}
}

namespace FazCookie\Admin\Modules\Banners\Includes {
	class Controller {
		private static $instance;
		private $rows = array();
		private $next_id = 1;
		public $corrupt_next_save = false;

		public static function get_instance() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function reset() {
			$this->rows              = array();
			$this->next_id           = 1;
			$this->corrupt_next_save = false;
		}

		public function snapshot_state() {
			return array( 'rows' => $this->rows, 'next_id' => $this->next_id );
		}

		public function restore_state( $state ) {
			$this->rows    = $state['rows'];
			$this->next_id = $state['next_id'];
		}

		public function delete_cache() {}

		public function persist( Banner $banner ) {
			$id = $banner->get_id();
			if ( $this->corrupt_next_save && $id > 0 ) {
				$this->corrupt_next_save = false;
				return $id;
			}
			if ( $id <= 0 ) {
				$id = $this->next_id++;
				$banner->assign_id( $id );
			}
			if ( $banner->get_default() ) {
				foreach ( $this->rows as &$row ) {
					$row['default'] = false;
				}
				unset( $row );
			}
			$this->rows[ $id ] = $banner->export_row();
			return $id;
		}

		public function row( $id ) {
			return isset( $this->rows[ $id ] ) ? $this->rows[ $id ] : null;
		}

		public function count() {
			return count( $this->rows );
		}

		public function get_active_banner() {
			foreach ( $this->rows as $id => $row ) {
				if ( $row['status'] && empty( $row['targets'] ) ) {
					return new Banner( $id );
				}
			}
			foreach ( $this->rows as $id => $row ) {
				if ( $row['default'] ) {
					return new Banner( $id );
				}
			}
			foreach ( $this->rows as $id => $row ) {
				if ( $row['status'] ) {
					return new Banner( $id );
				}
			}
			return false;
		}
	}

	class Banner {
		private $id = 0;
		private $name = '';
		private $status = false;
		private $default = false;
		private $settings;
		private $contents;
		private $targets = array();

		public function __construct( $id = 0 ) {
			$this->settings = self::default_settings();
			$this->contents = self::default_contents();
			if ( (int) $id > 0 ) {
				$row = Controller::get_instance()->row( (int) $id );
				if ( is_array( $row ) ) {
					$this->id       = (int) $id;
					$this->name     = $row['name'];
					$this->status   = $row['status'];
					$this->default  = $row['default'];
					$this->settings = $row['settings'];
					$this->contents = $row['contents'];
					$this->targets  = $row['targets'];
				}
			}
		}

		public static function default_settings() {
			$buttons = array();
			foreach ( array( 'accept', 'reject', 'settings', 'readMore' ) as $button ) {
				$buttons[ $button ] = array( 'status' => true );
			}
			$buttons['donotSell'] = array( 'status' => false, 'tag' => 'donotsell-button' );
			return array(
				'settings' => array(
					'applicableLaw' => 'gdpr',
					'consentExpiry' => array( 'status' => true, 'value' => 180 ),
					'customMarker'  => 'keep-me',
				),
				'config'   => array(
					'notice'      => array(
						'elements' => array(
							'buttons' => array( 'elements' => $buttons ),
						),
					),
					'optoutPopup' => array( 'status' => false ),
				),
			);
		}

		public static function default_contents() {
			return array(
				'en' => array( 'notice' => array( 'elements' => array( 'title' => 'Default EN' ) ) ),
				'it' => array( 'notice' => array( 'elements' => array( 'title' => 'Default IT' ) ) ),
			);
		}

		public function export_row() {
			return array(
				'name'     => $this->name,
				'status'   => $this->status,
				'default'  => $this->default,
				'settings' => $this->settings,
				'contents' => $this->contents,
				'targets'  => $this->targets,
			);
		}

		public function assign_id( $id ) {
			$this->id = (int) $id;
		}

		public function save() {
			return Controller::get_instance()->persist( $this );
		}

		public function get_id() {
			return $this->id;
		}

		public function set_name( $name ) {
			$this->name = (string) $name;
		}

		public function set_status( $status ) {
			$this->status = (bool) $status;
		}

		public function get_status() {
			return $this->status;
		}

		public function set_default( $default ) {
			$this->default = (bool) $default;
		}

		public function get_default() {
			return $this->default;
		}

		public function set_settings( $settings ) {
			$this->settings = $settings;
		}

		public function get_settings() {
			return $this->settings;
		}

		public function set_contents( $contents ) {
			$this->contents = $contents;
		}

		public function get_contents() {
			return $this->contents;
		}

		public function set_target_countries( $targets ) {
			$this->targets = array_values( $targets );
		}

		public function get_target_countries() {
			return $this->targets;
		}

		public function get_law() {
			return isset( $this->settings['settings']['applicableLaw'] )
				? $this->settings['settings']['applicableLaw']
				: 'gdpr';
		}
	}
}

namespace FazCookie\Admin\Modules\Settings\Includes {
	class Settings {
		public static $storage = array();
		public static $updates = 0;
		public static $corrupt_next_update = false;

		public function get_defaults() {
			return self::$storage;
		}

		public static function sanitize( $settings, $defaults ) {
			unset( $defaults );
			return $settings;
		}

		public function get( $key = '' ) {
			if ( '' === $key ) {
				return self::$storage;
			}
			return isset( self::$storage[ $key ] ) ? self::$storage[ $key ] : array();
		}

		public function update( $value ) {
			self::$updates++;
			if ( self::$corrupt_next_update ) {
				self::$corrupt_next_update = false;
				return;
			}
			self::$storage = $value;
		}

		public static function clear_cache() {}
	}
}

namespace FazCookie\Admin\Modules\Gcm\Includes {
	class Gcm_Settings {
		public static $storage = array( 'status' => false );
		public static $corrupt_next_update = false;

		public function update( $value ) {
			if ( self::$corrupt_next_update ) {
				self::$corrupt_next_update = false;
				return;
			}
			self::$storage = array( 'status' => ! empty( $value['status'] ) );
		}

		public function get() {
			return self::$storage;
		}
	}
}

namespace {
	require_once __DIR__ . '/../../admin/modules/settings/includes/class-onboarding.php';

	use FazCookie\Admin\Modules\Banners\Includes\Banner;
	use FazCookie\Admin\Modules\Banners\Includes\Controller;
	use FazCookie\Admin\Modules\Settings\Includes\Onboarding;
	use FazCookie\Admin\Modules\Settings\Includes\Settings;
	use FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings;

	$GLOBALS['wpdb'] = new Faz_Runtime_Wpdb();

	$tests_run = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function faz_runtime_assert_same( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32mPASS\033[0m " . str_pad( (string) $tests_run, 2, '0', STR_PAD_LEFT ) . " $label\n";
			return;
		}
		$tests_failed++;
		echo "  \033[31mFAIL\033[0m " . str_pad( (string) $tests_run, 2, '0', STR_PAD_LEFT ) . " $label\n";
		echo '       expected: ' . var_export( $expected, true ) . "\n";
		echo '       actual:   ' . var_export( $actual, true ) . "\n";
	}

	function faz_seed_runtime_banner( $law, $targets, $default, $contents ) {
		$banner   = new Banner();
		$settings = Banner::default_settings();
		$settings['settings']['applicableLaw'] = $law;
		$banner->set_name( 'Fixture' );
		$banner->set_status( true );
		$banner->set_default( $default );
		$banner->set_target_countries( $targets );
		$banner->set_contents( $contents );
		$banner->set_settings( $settings );
		$banner->save();
		return $banner;
	}

	function faz_button_statuses( $settings ) {
		$out = array();
		foreach ( array( 'accept', 'reject', 'settings', 'readMore' ) as $button ) {
			$out[ $button ] = ! empty( $settings['config']['notice']['elements']['buttons']['elements'][ $button ]['status'] );
		}
		return $out;
	}

	echo "\n== Onboarding persisted runtime ==\n\n";
	$controller = Controller::get_instance();
	$onboarding = new Onboarding();
	$custom_contents = array(
		'en' => array( 'notice' => array( 'elements' => array( 'title' => 'Custom EN' ) ) ),
		'it' => array( 'notice' => array( 'elements' => array( 'title' => 'Custom IT' ) ) ),
	);

	// Existing global banner -> exact CCPA model without collateral mutations.
	$controller->reset();
	$global = faz_seed_runtime_banner( 'gdpr', array(), true, $custom_contents );
	$global_id = $global->get_id();
	$result = $onboarding->apply_law_to_default_banner( 'ccpa' );
	$persisted = new Banner( $global_id );
	$ccpa = $persisted->get_settings();
	faz_runtime_assert_same( $result, true, 'CCPA application succeeds' );
	faz_runtime_assert_same( $controller->count(), 1, 'CCPA updates one global row without creating a peer' );
	faz_runtime_assert_same( $persisted->get_id(), $global_id, 'CCPA keeps the existing banner identity' );
	faz_runtime_assert_same( $persisted->get_status(), true, 'CCPA leaves the configured banner active' );
	faz_runtime_assert_same( $persisted->get_law(), 'ccpa', 'CCPA persists the opt-out law' );
	faz_runtime_assert_same( array( ! empty( $ccpa['settings']['consentExpiry']['status'] ), (int) $ccpa['settings']['consentExpiry']['value'] ), array( true, 365 ), 'CCPA persists an enabled 365-day lifetime' );
	faz_runtime_assert_same( faz_button_statuses( $ccpa ), array( 'accept' => false, 'reject' => false, 'settings' => false, 'readMore' => false ), 'CCPA hides all GDPR notice controls' );
	faz_runtime_assert_same( array( ! empty( $ccpa['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] ), ! empty( $ccpa['config']['optoutPopup']['status'] ) ), array( true, true ), 'CCPA enables Do Not Sell and its popup' );
	faz_runtime_assert_same( $persisted->get_contents(), $custom_contents, 'CCPA preserves every multilingual content value' );
	faz_runtime_assert_same( $ccpa['settings']['customMarker'], 'keep-me', 'CCPA preserves unrelated visual/configuration fields' );

	// Switching the same banner back to GDPR must fully undo the CCPA model.
	$result = $onboarding->apply_law_to_default_banner( 'gdpr' );
	$persisted = new Banner( $global_id );
	$gdpr = $persisted->get_settings();
	faz_runtime_assert_same( $result, true, 'GDPR application succeeds on an existing CCPA banner' );
	faz_runtime_assert_same( $persisted->get_law(), 'gdpr', 'GDPR law replaces the prior CCPA law' );
	faz_runtime_assert_same( (int) $gdpr['settings']['consentExpiry']['value'], 180, 'GDPR resets lifetime to 180 days' );
	faz_runtime_assert_same( faz_button_statuses( $gdpr ), array( 'accept' => true, 'reject' => true, 'settings' => true, 'readMore' => true ), 'GDPR restores every equal-weight notice control' );
	faz_runtime_assert_same( array( ! empty( $gdpr['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] ), ! empty( $gdpr['config']['optoutPopup']['status'] ) ), array( false, false ), 'GDPR removes the US opt-out control and popup' );

	// Reopening a completed wizard on the same consent model must not erase the
	// admin's custom lifetime or per-button visibility.
	$gdpr['settings']['consentExpiry'] = array( 'status' => false, 'value' => 90 );
	$gdpr['config']['notice']['elements']['buttons']['elements']['reject']['status'] = false;
	$gdpr['config']['notice']['elements']['buttons']['elements']['readMore']['status'] = false;
	$persisted->set_settings( $gdpr );
	$persisted->save();
	$result = $onboarding->apply_law_to_default_banner( 'both', true );
	$preserved = ( new Banner( $global_id ) )->get_settings();
	faz_runtime_assert_same( $result, true, 'same-model re-entry application succeeds' );
	faz_runtime_assert_same( $preserved['settings']['consentExpiry'], array( 'status' => false, 'value' => 90 ), 'same-model re-entry preserves custom consent expiry' );
	faz_runtime_assert_same( faz_button_statuses( $preserved ), array( 'accept' => true, 'reject' => false, 'settings' => true, 'readMore' => false ), 'same-model re-entry preserves individual notice buttons' );
	$result = $onboarding->apply_law_to_default_banner( 'ccpa', true );
	$switched = ( new Banner( $global_id ) )->get_settings();
	faz_runtime_assert_same(
		array( $result, $switched['settings']['consentExpiry'], faz_button_statuses( $switched ) ),
		array( true, array( 'status' => true, 'value' => 365 ), array( 'accept' => false, 'reject' => false, 'settings' => false, 'readMore' => false ) ),
		'an opt-in to opt-out model switch reapplies the canonical preset'
	);

	// With only a targeted row, onboarding must create a separate global fallback.
	$controller->reset();
	$targeted = faz_seed_runtime_banner( 'gdpr', array( 'US' ), false, $custom_contents );
	$targeted_id = $targeted->get_id();
	$result = $onboarding->apply_law_to_default_banner( 'both' );
	$targeted_after = new Banner( $targeted_id );
	$fallback = $controller->get_active_banner();
	$fallback_settings = $fallback->get_settings();
	faz_runtime_assert_same( $result, true, 'Both application succeeds with only a targeted banner present' );
	faz_runtime_assert_same( $controller->count(), 2, 'Both creates one additional global fallback' );
	faz_runtime_assert_same( array( $targeted_after->get_id(), $targeted_after->get_law() ), array( $targeted_id, 'gdpr' ), 'targeted banner identity and law remain unchanged' );
	faz_runtime_assert_same( $targeted_after->get_target_countries(), array( 'US' ), 'targeted banner keeps its country scope' );
	faz_runtime_assert_same( $targeted_after->get_contents(), $custom_contents, 'targeted banner keeps EN and IT content' );
	faz_runtime_assert_same( array( $fallback->get_id() !== $targeted_id, $fallback->get_default(), $fallback->get_status(), $fallback->get_target_countries() ), array( true, true, true, array() ), 'new fallback is distinct, global, active, and default' );
	faz_runtime_assert_same( array( $fallback->get_law(), (int) $fallback_settings['settings']['consentExpiry']['value'] ), array( 'gdpr', 180 ), 'Both fallback uses the stricter GDPR law and lifetime' );
	faz_runtime_assert_same( array( faz_button_statuses( $fallback_settings ), ! empty( $fallback_settings['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] ), ! empty( $fallback_settings['config']['optoutPopup']['status'] ) ), array( array( 'accept' => true, 'reject' => true, 'settings' => true, 'readMore' => true ), true, true ), 'Both combines GDPR controls with the US opt-out path' );
	faz_runtime_assert_same( array_keys( $fallback->get_contents() ), array( 'en', 'it' ), 'new fallback contains every configured language' );

	// A reported save ID is insufficient: finish must reject a non-persisted row.
	$controller->reset();
	faz_seed_runtime_banner( 'gdpr', array(), true, $custom_contents );
	Settings::$storage = array( 'onboarding' => array( 'completed' => false, 'law' => '' ) );
	Settings::$updates = 0;
	$GLOBALS['faz_onboarding_runtime_cache_clears'] = 0;
	$before = Settings::$storage;
	$controller->corrupt_next_save = true;
	$failure = $onboarding->finish( 'ccpa' );
	$banner_after_failure = $controller->get_active_banner();
	faz_runtime_assert_same(
		array(
			is_wp_error( $failure ),
			is_wp_error( $failure ) ? $failure->get_error_code() : '',
			Settings::$storage,
			Settings::$updates,
			$GLOBALS['faz_onboarding_runtime_cache_clears'],
		),
		array( true, 'faz_onboarding_banner_save_failed', $before, 0, 0 ),
		'finish remains incomplete when the banner update did not persist'
	);

	// A silent settings write failure must not be reported as a completed wizard.
	$controller->reset();
	faz_seed_runtime_banner( 'gdpr', array(), true, $custom_contents );
	Settings::$storage = array(
		'banner_control' => array( 'status' => true ),
		'consent_logs'   => array( 'status' => true ),
		'onboarding'     => array( 'completed' => false, 'dismissed' => false, 'law' => '' ),
	);
	Settings::$updates = 0;
	Settings::$corrupt_next_update = true;
	$GLOBALS['faz_onboarding_runtime_cache_clears'] = 0;
	$before = Settings::$storage;
	$failure = $onboarding->finish( 'ccpa' );
	faz_runtime_assert_same(
		array(
			is_wp_error( $failure ),
			is_wp_error( $failure ) ? $failure->get_error_code() : '',
			Settings::$storage,
			Settings::$updates,
			$GLOBALS['faz_onboarding_runtime_cache_clears'],
			$banner_after_failure->get_law(),
		),
		array( true, 'faz_onboarding_settings_save_failed', $before, 1, 0, 'gdpr' ),
		'finish rolls the banner back when the settings write does not survive read-back'
	);

	// A later-store GCM failure must roll back the already-written banner too.
	$controller->reset();
	faz_seed_runtime_banner( 'gdpr', array(), true, $custom_contents );
	Settings::$storage = array( 'onboarding' => array( 'completed' => false, 'law' => '' ) );
	Settings::$updates = 0;
	Settings::$corrupt_next_update = false;
	Gcm_Settings::$storage = array( 'status' => false );
	Gcm_Settings::$corrupt_next_update = true;
	$failure = $onboarding->finish( 'ccpa', array( 'gcm' => array( 'enabled' => true ) ) );
	faz_runtime_assert_same(
		array(
			is_wp_error( $failure ) ? $failure->get_error_code() : '',
			$controller->get_active_banner()->get_law(),
			Settings::$storage['onboarding']['completed'],
			Gcm_Settings::$storage['status'],
		),
		array( 'faz_onboarding_gcm_save_failed', 'gdpr', false, false ),
		'GCM read-back failure rolls back banner, settings, and GCM together'
	);

	echo "\n--\n";
	echo "Tests:  $tests_run\n";
	echo "Passed: $tests_passed\n";
	echo "Failed: $tests_failed\n\n";
	if ( 31 !== $tests_run ) {
		echo "\033[31mFAIL: expected exactly 31 checks\033[0m\n";
		exit( 1 );
	}
	if ( $tests_failed > 0 ) {
		exit( 1 );
	}
	echo "\033[32m31 passed, 0 failed\033[0m\n";
}
