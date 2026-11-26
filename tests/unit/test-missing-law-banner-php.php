<?php
/**
 * An opt-in visitor on a site whose banners are all opt-out (issue #377).
 *
 * Until 1.36.1 that visitor got no banner: Frontend::load_banner() kept the
 * CCPA banner for blocking and hid its UI. With Geo-Targeting on by default
 * and an unresolved country falling back to the opt-in ruleset, a site with
 * one CCPA banner and no country source showed a banner to nobody.
 *
 *   - Banner::apply_runtime_law_rendition() rebuilds the CCPA banner as an
 *     opt-in one for the request: the law's switches from gdpr.json, the
 *     banner's own look, the GDPR consent lifetime cap, the GDPR text.
	 *   - Legacy fallback preferences never waive an opt-in jurisdiction.
	 *   - The recursive settings sanitiser normalizes malformed/retired modes.
 *
 * Run: php tests/unit/test-missing-law-banner-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	$GLOBALS['faz_test_options'] = array();

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
	function sanitize_file_name( $name ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name );
	}
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
	function faz_default_language() { return 'en'; }
	function faz_selected_languages( $language = '' ) { return array( 'en', 'it', 'fr' ); }
	function faz_sanitize_content( $value ) { return is_scalar( $value ) ? (string) $value : ''; }
	function faz_sanitize_text( $value ) { return is_scalar( $value ) ? (string) $value : ''; }
	function wp_cache_get( $key, $group = '' ) {
		return false;
	}
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		return true;
	}
	function wp_upload_dir() {
		return array( 'basedir' => sys_get_temp_dir() . '/faz-missing-law-no-uploads' );
	}
	function trailingslashit( $path ) {
		return rtrim( (string) $path, '/\\' ) . '/';
	}
	function faz_read_json_file( $file_path = '' ) {
		if ( ! is_string( $file_path ) || ! file_exists( $file_path ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $file_path ), true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

namespace FazCookie\Admin\Modules\Banners\Includes {
	/**
	 * Stand-in for the banner Controller: the real defaults loader, a
	 * configurable answer to "is there an active GDPR banner?".
	 */
	class Controller {
		public static $has_gdpr = false;
		public static $asked    = array();

		public static function get_instance() {
			return new self();
		}

		public static function get_default_configs( $type = 'gdpr' ) {
			return faz_read_json_file( __DIR__ . '/../../admin/modules/banners/includes/configs/' . $type . '.json' );
		}

		public function has_active_banner_for_law( $law, $country = '' ) {
			self::$asked[] = array( $law, $country );
			return 'gdpr' === $law ? self::$has_gdpr : true;
		}
	}
}

namespace FazCookie\Admin\Modules\Geo_Routing {
	/** Resolves every visitor to the ruleset the test sets. */
	class Geo_Routing {
		public static $ruleset = array( 'model' => 'opt-in' );

		public static function get_instance() {
			return new self();
		}

		public function get_visitor_context( $ip, $country, $region ) {
			return array( 'ruleset' => self::$ruleset );
		}
	}
}

namespace {
	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-store.php';
	require_once $root . '/admin/modules/banners/includes/class-banner.php';
	require_once $root . '/frontend/includes/class-geo-runtime.php';
	require_once $root . '/admin/modules/settings/includes/class-settings.php';

	use FazCookie\Admin\Modules\Banners\Includes\Banner;
	use FazCookie\Admin\Modules\Banners\Includes\Controller;
	use FazCookie\Admin\Modules\Geo_Routing\Geo_Routing;
	use FazCookie\Frontend\Includes\Geo_Runtime;

	/**
	 * Banner without the DB constructor. get_settings() returns what is stored,
	 * so the law the rendition writes is the law every later reader sees.
	 */
	class Missing_Law_Test_Banner extends Banner {
		public function __construct( array $settings, array $contents = array() ) {
			$this->data = array(
				'settings' => $settings,
				'contents' => $contents,
			);
		}

		public function get_settings() {
			return $this->data['settings'];
		}

		public function stored( $key ) {
			return $this->data[ $key ];
		}
	}

	$run    = 0;
	$failed = 0;

	function mlb_assert( $condition, $label ) {
		global $run, $failed;
		$run++;
		if ( $condition ) {
			echo "  \033[32m✓\033[0m {$label}\n";
			return;
		}
		$failed++;
		echo "  \033[31m✗\033[0m {$label}\n";
	}

	function mlb_ccpa_banner() {
		$defaults = Controller::get_default_configs( 'ccpa' );
		$settings = $defaults;
		unset( $settings['meta'] );
		$settings['settings']['type']                    = 'box';
		$settings['settings']['position']                = 'bottom-right';
		$settings['settings']['theme']                   = 'dark';
		$settings['settings']['consentExpiry']['value']  = 365;
		$settings['config']['notice']['elements']['buttons']['elements']['accept']['styles']['background-color'] = '#AA0000';
		$settings['config']['notice']['styles']['background-color'] = '#101010';
		$contents = array( 'en' => array( 'notice' => array( 'elements' => array( 'title' => 'Opt out of the sale of your data' ) ) ) );
		return new Missing_Law_Test_Banner( $settings, $contents );
	}

	function mlb_reset_runtime() {
		$memo = new \ReflectionProperty( Geo_Runtime::class, 'ruleset_memo' );
		$memo->setAccessible( true );
		$memo->setValue( null, array() );
		Controller::$asked = array();
	}

	echo "\n== Opt-in visitors when every banner is opt-out (#377) ==\n";

	echo "\n-- Banner::apply_runtime_law_rendition() --\n";

	$banner  = mlb_ccpa_banner();
	$changed = $banner->apply_runtime_law_rendition( 'gdpr' );
	$config  = $banner->get_settings()['config'];
	$buttons = $config['notice']['elements']['buttons']['elements'];
	$gdpr    = Controller::get_default_configs( 'gdpr' );

	mlb_assert( true === $changed, 'a CCPA banner is rendered as GDPR' );
	mlb_assert( 'gdpr' === $banner->get_law(), 'its law is gdpr for the rest of the request' );
	mlb_assert( 'gdpr' === $banner->get_runtime_rendition(), 'it reports the rendition, which keys the template cache' );
	mlb_assert( ! empty( $buttons['accept']['status'] ) && ! empty( $buttons['reject']['status'] ) && ! empty( $buttons['settings']['status'] ), 'Accept, Reject and the settings button are on' );
	mlb_assert( empty( $buttons['donotSell']['status'] ), 'the Do Not Sell button is off' );
	mlb_assert( empty( $config['optoutPopup']['status'] ) && ! empty( $config['preferenceCenter']['status'] ), 'the opt-out popup gives way to the preference centre' );
	mlb_assert( $gdpr['config']['auditTable']['status'] === $config['auditTable']['status'], 'every other switch the two defaults disagree on follows GDPR (audit table)' );
	mlb_assert( '#AA0000' === $buttons['accept']['styles']['background-color'], 'the Accept button keeps the banner\'s colour' );
	mlb_assert( '#101010' === $config['notice']['styles']['background-color'], 'the notice keeps the banner\'s background' );
	mlb_assert( 'bottom-right' === $banner->get_settings()['settings']['position'] && 'dark' === $banner->get_settings()['settings']['theme'], 'position and theme are the banner\'s' );
	mlb_assert( (int) $gdpr['settings']['consentExpiry']['value'] === (int) $banner->get_settings()['settings']['consentExpiry']['value'], 'a 365-day consent lifetime is capped at the GDPR default' );
	mlb_assert( array() === $banner->stored( 'contents' ), 'the opt-out text is dropped, so each language falls back to its GDPR catalogue' );
	$catalogue = $banner->get_translations( 'en' );
	mlb_assert( isset( $catalogue['notice']['elements']['buttons']['elements']['accept'] ) && '' !== (string) $catalogue['notice']['elements']['buttons']['elements']['accept'], 'that catalogue is the GDPR one, with an Accept label' );
	mlb_assert( false === $banner->apply_runtime_law_rendition( 'gdpr' ), 'a second call changes nothing' );

	// A policy URL belongs to the site, even when the notice copy changes law.
	// Exercise get_contents(), not just the raw array: a link-only slot would
	// suppress its whole-language fallback and render blank text/buttons.
	$linked = new Missing_Law_Test_Banner( mlb_ccpa_banner()->get_settings(), array(
		'en' => array( 'notice' => array( 'elements' => array( 'privacyLink' => 'https://legal.example/policy', 'title' => 'Old opt-out title' ) ) ),
		'it' => json_encode( array( 'notice' => array( 'elements' => array( 'privacyLink' => '/informativa-cookie/' ) ) ) ),
		'fr' => array( 'notice' => array( 'elements' => array( 'privacyLink' => array( 'invalid' ) ) ) ),
	) );
	$linked->apply_runtime_law_rendition( 'gdpr' );
	foreach ( array( 'en' => 'https://legal.example/policy', 'it' => '/informativa-cookie/', 'fr' => '' ) as $lang => $url ) {
		$content = $linked->get_contents( $lang );
		$default = $linked->get_translations( $lang );
		mlb_assert( $url === $content['notice']['elements']['privacyLink'], "$lang: its policy URL survives, malformed links use the default" );
		mlb_assert( $default['notice']['elements']['description'] === $content['notice']['elements']['description'], "$lang: the description is the full GDPR catalogue text" );
		mlb_assert( $default['notice']['elements']['buttons']['elements']['reject'] === $content['notice']['elements']['buttons']['elements']['reject'], "$lang: Reject keeps its translated label" );
	}
	$contents = $linked->stored( 'contents' );
	$linked->apply_runtime_law_rendition( 'gdpr' );
	mlb_assert( $contents === $linked->stored( 'contents' ), 'repeated conversion keeps the restored policy links' );

	$own = mlb_ccpa_banner();
	$short                                        = $own->get_settings();
	$short['settings']['consentExpiry']['value']  = 30;
	$own                                          = new Missing_Law_Test_Banner( $short );
	$own->apply_runtime_law_rendition( 'gdpr' );
	mlb_assert( 30 === (int) $own->get_settings()['settings']['consentExpiry']['value'], 'a shorter lifetime than the GDPR default is kept' );

	$gdpr_banner = new Missing_Law_Test_Banner( array_diff_key( $gdpr, array( 'meta' => 1 ) ) );
	mlb_assert( false === $gdpr_banner->apply_runtime_law_rendition( 'gdpr' ) && '' === $gdpr_banner->get_runtime_rendition(), 'a GDPR banner is left alone' );
	mlb_assert( false === mlb_ccpa_banner()->apply_runtime_law_rendition( 'ccpa' ), 'only the opt-in direction exists' );

	echo "\n-- Geo_Runtime: missing_law_banner --\n";

	$GLOBALS['faz_test_options']['faz_settings'] = array( 'geolocation' => array( 'geo_targeting' => true ) );
	mlb_assert( 'gdpr_version' === Geo_Runtime::missing_law_banner_mode(), 'an install that never saved the setting gets the opt-in version' );

	mlb_reset_runtime();
	Controller::$has_gdpr = false;
	mlb_assert( null !== Geo_Runtime::resolve_for_country( 'DE' ), 'gdpr_version keeps the opt-in ruleset' );

	$GLOBALS['faz_test_options']['faz_settings']['geolocation']['missing_law_banner'] = 'main_banner';
	mlb_reset_runtime();
	mlb_assert( null !== Geo_Runtime::resolve_for_country( 'DE' ), 'a legacy main_banner preference cannot waive the opt-in ruleset' );
	mlb_assert( 'gdpr_version' === Geo_Runtime::missing_law_banner_mode(), 'the retired mode reads as the opt-in rendition' );
	mlb_assert( null !== Geo_Runtime::resolve_for_country( 'DE' ), 'the protective ruleset is memoised for the request' );
	mlb_assert( null !== Geo_Runtime::resolve_for_country( '' ), 'an unknown country keeps opt-in protection too' );

	mlb_reset_runtime();
	Controller::$has_gdpr = true;
	mlb_assert( null !== Geo_Runtime::resolve_for_country( 'DE' ), 'a GDPR banner for the visitor keeps the ruleset, whatever the setting' );

	mlb_reset_runtime();
	Controller::$has_gdpr = false;
	Geo_Routing::$ruleset = array( 'model' => 'opt-out-with-sensitive-opt-in' );
	mlb_assert( null !== Geo_Runtime::resolve_for_country( 'US' ) && array() === Controller::$asked, 'an opt-out ruleset is never waived' );
	Geo_Routing::$ruleset = array( 'model' => 'opt-in' );

	$GLOBALS['faz_test_options']['faz_settings']['geolocation']['missing_law_banner'] = 'anything-else';
	mlb_assert( 'gdpr_version' === Geo_Runtime::missing_law_banner_mode(), 'an unknown stored value reads as the opt-in version' );

	echo "\n-- Settings sanitiser --\n";

	foreach ( array( 'main_banner', array( 'main_banner' ), array(), 'anything-else' ) as $value ) {
		$clean = \FazCookie\Admin\Modules\Settings\Includes\Settings::sanitize(
			array( 'geolocation' => array( 'missing_law_banner' => $value ) ),
			array( 'geolocation' => array( 'missing_law_banner' => 'gdpr_version' ) )
		);
		mlb_assert( 'gdpr_version' === $clean['geolocation']['missing_law_banner'], 'recursive sanitisation restores the protective scalar for ' . json_encode( $value ) );
	}

	echo "\n--\nChecks: {$run}\nFailed: {$failed}\n\n";
	if ( $failed > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
