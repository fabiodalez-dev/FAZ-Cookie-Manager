<?php
/**
 * Standalone regression tests for the FlyingPress 5 optimiser bridge.
 *
 * Subsystem: flyingpress-optimizer-bridge
 *
 * FlyingPress 5 reads several exclusion/include lists straight from the
 * static \FlyingPress\Config::$config with no filter in front of them. The
 * bootstrap suite (test-flyingpress-frontend-bootstrap-php.php) covers the
 * "Delay all JavaScript" list; this one covers the rest of the bridge:
 *
 *   - "Delay third-party scripts" (`js_delay_third_party_excludes`): on a site
 *     serving FAZ from a CDN host, or with the inline `_fazConfig` mentioning
 *     a whitelisted Google domain, every FAZ tag counted as third-party and
 *     waited for the first interaction;
 *   - "Remove unused CSS" (`css_rucss_include_selectors` plus the
 *     `flying_press_exclude_from_minify:css` filter).
 *
 * FlyingPress's passes are modelled here by their documented observable
 * contract (a keyword matched case-insensitively anywhere in the tag excludes
 * it), not by its code.
 *
 * Run from project root:
 *   php tests/unit/test-flyingpress-optimizer-bridge-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Admin\Modules\Settings\Includes {
	class Settings {}
}

namespace FazCookie\Admin\Modules\Gcm\Includes {
	class Gcm_Settings {}
}

namespace FazCookie\Frontend\Modules\Consent_Logger {
	class Consent_Logger {}
}

namespace FazCookie\Frontend\Modules\Banner_Rest {
	class Banner_Rest {}
}

namespace FazCookie\Frontend\Modules\Legal_Links {
	class Legal_Links {}
}

namespace FazCookie\Includes {
	class Geolocation {}
	class Gvl {}
	class Known_Providers {}
	class Cookie_Table_Shortcode {}
	class Cookie_Policy_Shortcode {}
	class Do_Not_Sell_Shortcode {}
	class Cookie_Settings_Shortcode {}
}

namespace FazCookie\Frontend {
	class AMP_Consent {}
	class Translation_Compat {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['faz_test_filters']       = array();
	$GLOBALS['faz_test_actions']       = array();
	$GLOBALS['faz_test_actions_fired'] = array();
	$GLOBALS['faz_test_transients']    = array();
	$GLOBALS['faz_test_error_log']     = array();

	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
		$GLOBALS['faz_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
		return true;
	}

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
		$GLOBALS['faz_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
		return true;
	}

	function apply_filters( $hook, $value ) { // phpcs:ignore
		$args      = array_slice( func_get_args(), 2 );
		$callbacks = isset( $GLOBALS['faz_test_filters'][ $hook ] ) ? $GLOBALS['faz_test_filters'][ $hook ] : array();
		usort(
			$callbacks,
			static function ( $a, $b ) {
				return $a[1] <=> $b[1];
			}
		);
		foreach ( $callbacks as $entry ) {
			$call_args = array_slice( array_merge( array( $value ), $args ), 0, $entry[2] );
			$value     = call_user_func_array( $entry[0], $call_args );
		}
		return $value;
	}

	function do_action( $hook ) { // phpcs:ignore
		$args = array_slice( func_get_args(), 1 );
		$GLOBALS['faz_test_actions_fired'][ $hook ] = did_action( $hook ) + 1;
		$callbacks = isset( $GLOBALS['faz_test_actions'][ $hook ] ) ? $GLOBALS['faz_test_actions'][ $hook ] : array();
		usort(
			$callbacks,
			static function ( $a, $b ) {
				return $a[1] <=> $b[1];
			}
		);
		foreach ( $callbacks as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[2] ) );
		}
	}

	function did_action( $hook ) { // phpcs:ignore
		return isset( $GLOBALS['faz_test_actions_fired'][ $hook ] ) ? $GLOBALS['faz_test_actions_fired'][ $hook ] : 0;
	}

	function is_admin() { // phpcs:ignore
		return false;
	}

	$GLOBALS['faz_test_is_frontend'] = true;
	function faz_is_front_end_request() { // phpcs:ignore
		return (bool) $GLOBALS['faz_test_is_frontend'];
	}

	function wp_doing_cron() { // phpcs:ignore
		return false;
	}

	function get_transient( $key ) { // phpcs:ignore
		return array_key_exists( $key, $GLOBALS['faz_test_transients'] ) ? $GLOBALS['faz_test_transients'][ $key ] : false;
	}

	function set_transient( $key, $value, $ttl = 0 ) { // phpcs:ignore
		$GLOBALS['faz_test_transients'][ $key ] = $value;
		return true;
	}

	$faz_pass = 0;
	$faz_fail = 0;
	function faz_ob_ok( $condition, $label ) { // phpcs:ignore
		global $faz_pass, $faz_fail;
		if ( $condition ) {
			++$faz_pass;
			echo "  [PASS] {$label}\n";
			return;
		}
		++$faz_fail;
		echo "  [FAIL] {$label}\n";
	}

	/**
	 * Model of FlyingPress's keyword exclusion: any non-empty keyword found
	 * case-insensitively anywhere in the tag excludes it.
	 */
	function faz_ob_matches_any( array $keywords, $tag ) { // phpcs:ignore
		foreach ( $keywords as $keyword ) {
			if ( '' !== (string) $keyword && false !== stripos( $tag, (string) $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	echo "FlyingPress optimiser bridge\n\n";

	// A FlyingPress 5 config as migrate_config() leaves it: every list exists.
	$faz_fp5_config = static function ( array $overrides = array() ) {
		return array_merge(
			array(
				'js_delay'                      => true,
				'js_delay_method'               => 'background',
				'js_delay_excludes'             => array( 'first-party-rule' ),
				'js_delay_third_party'          => true,
				'js_delay_third_party_excludes' => array( 'third-party-rule' ),
				'js_delay_selected'             => false,
				'js_delay_selected_includes'    => array(),
				'css_rucss'                     => true,
				'css_rucss_include_selectors'   => array( '.user-selector' ),
			),
			$overrides
		);
	};

	$frontend = new Frontend( 'faz-cookie-manager', 'test' );

	// ---------------------------------------------------------------------
	// 1. Delay third-party scripts.
	// ---------------------------------------------------------------------
	$patched = $frontend->flying_press_add_delay_exclusions_to_config( $faz_fp5_config() );
	faz_ob_ok(
		array( 'third-party-rule', 'faz-cookie-manager', 'faz-fw' ) === $patched['js_delay_third_party_excludes'],
		'01 third-party exclusions gain the FAZ keywords and keep the administrator rule first'
	);
	faz_ob_ok(
		array( 'first-party-rule', 'faz-cookie-manager', 'faz-fw' ) === $patched['js_delay_excludes'],
		'02 first-party exclusions are still patched'
	);
	faz_ob_ok(
		array() === $patched['js_delay_selected_includes'],
		'03 the "Delay selected" include list is never touched (adding to it would delay FAZ)'
	);
	$twice = $frontend->flying_press_add_delay_exclusions_to_config( $patched );
	faz_ob_ok( $twice === $patched, '04 patching is idempotent (no duplicated keywords)' );

	// The tags the reporting site served: FAZ from a CDN host the Cloud
	// Optimizer lists as external, and the inline config carrying the default
	// whitelist's reCAPTCHA domain.
	$external_domains = array( 'b-cdn.net', 'www.google.com' );
	$faz_tags         = array(
		'config' => '<script id="faz-cookie-manager-js-extra">var _fazConfig = {"whitelist":["www.google.com/recaptcha"]};</script>',
		'main'   => '<script id="faz-cookie-manager-js" src="https://shop.b-cdn.net/wp-content/plugins/faz-cookie-manager/frontend/js/script.min.js"></script>',
		'alt'    => '<script id="faz-fw-js" src="https://shop.b-cdn.net/wp-content/uploads/faz-fw/a.js"></script>',
	);
	$third_party_delays = static function ( array $config, $tag ) use ( $external_domains ) {
		return faz_ob_matches_any( $external_domains, $tag )
			&& ! faz_ob_matches_any( $config['js_delay_third_party_excludes'], $tag );
	};
	foreach ( $faz_tags as $label => $tag ) {
		faz_ob_ok( $third_party_delays( $faz_fp5_config(), $tag ), "05 precondition: unpatched config delays the {$label} tag as third-party" );
		faz_ob_ok( ! $third_party_delays( $patched, $tag ), "06 patched config leaves the {$label} tag executable at page load" );
	}
	faz_ob_ok(
		$third_party_delays( $patched, '<script src="https://www.google.com/recaptcha/api.js"></script>' ),
		'07 a genuine third-party script stays eligible for delay'
	);

	// Missing / non-array lists stay exactly as they were.
	$no_third = $faz_fp5_config();
	unset( $no_third['js_delay_third_party_excludes'] );
	$no_third_patched = $frontend->flying_press_add_delay_exclusions_to_config( $no_third );
	faz_ob_ok( ! array_key_exists( 'js_delay_third_party_excludes', $no_third_patched ), '08 a missing third-party list is not created' );
	$string_third = $frontend->flying_press_add_delay_exclusions_to_config( $faz_fp5_config( array( 'js_delay_third_party_excludes' => 'a,b' ) ) );
	faz_ob_ok( 'a,b' === $string_third['js_delay_third_party_excludes'], '09 a non-array third-party list is left untouched' );

	// ---------------------------------------------------------------------
	// 3. Remove unused CSS.
	// ---------------------------------------------------------------------
	faz_ob_ok(
		array( '.user-selector', 'faz-' ) === $patched['css_rucss_include_selectors'],
		'10 RUCSS include keywords gain "faz-" after the administrator selector'
	);
	$include = $patched['css_rucss_include_selectors'];
	foreach ( array( '#faz-consent', '.faz-modal.faz-modal-open', '.faz-switch input:checked', '[data-faz-tag="notice"]' ) as $selector ) {
		faz_ob_ok( faz_ob_matches_any( $include, $selector ), "11 RUCSS keeps the banner selector {$selector}" );
	}
	$null_rucss = $frontend->flying_press_add_delay_exclusions_to_config( $faz_fp5_config( array( 'css_rucss_include_selectors' => null ) ) );
	faz_ob_ok( null === $null_rucss['css_rucss_include_selectors'], '12 a non-array RUCSS list is left untouched' );

	$css_link = "<link rel='stylesheet' id='faz-cookie-manager-css' href='https://example.test/wp-content/uploads/faz-cookie-manager/assets/banner-abc.css' media='all' />";
	$css_excl = apply_filters( 'flying_press_exclude_from_minify:css', array( 'theme.css' ) );
	faz_ob_ok( is_array( $css_excl ) && in_array( 'theme.css', $css_excl, true ), '13 the CSS minify filter preserves existing exclusions' );
	faz_ob_ok( is_array( $css_excl ) && faz_ob_matches_any( $css_excl, $css_link ), '14 the FAZ banner stylesheet is excluded from FlyingPress CSS minify' );

	// End-to-end through the real runtime bridge on plugins_loaded.
	eval( 'namespace FlyingPress; class Config { public static $config = array(); }' );
	\FlyingPress\Config::$config = $faz_fp5_config();
	do_action( 'plugins_loaded' );
	faz_ob_ok(
		in_array( 'faz-cookie-manager', \FlyingPress\Config::$config['js_delay_third_party_excludes'], true )
			&& in_array( 'faz-', \FlyingPress\Config::$config['css_rucss_include_selectors'], true ),
		'15 the plugins_loaded bridge patches the third-party and RUCSS lists in memory'
	);

	// ---------------------------------------------------------------------
	// 2. flying_press_optimization:after safety net. The fixtures are the
	// shapes FlyingPress 5 writes: data-loading-method + data-src for a
	// delayed tag, a data:text/javascript, URI for an inline body, data-href
	// for a stylesheet held back by "Remove unused CSS". Only apply_filters()
	// is used so the unfixed code reports failures instead of fataling.
	// ---------------------------------------------------------------------
	$log_file = tempnam( sys_get_temp_dir(), 'faz-fp-log' );
	ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	$GLOBALS['faz_test_transients'] = array();
	$restore = static function ( $html ) {
		return apply_filters( 'flying_press_optimization:after', $html );
	};
	$config_body = 'var _fazConfig = {"whitelist":["www.google.com/recaptcha"],"x":"a+b & c"};';
	$cases       = array(
		'delayed inline config (third-party pass)' => array(
			'<script id="faz-cookie-manager-js-extra" data-loading-method="user-interaction" data-src="data:text/javascript,' . rawurlencode( $config_body ) . '"></script>',
			'<script id="faz-cookie-manager-js-extra">' . $config_body . '</script>',
		),
		'delayed external runtime keeps its own attributes' => array(
			'<script data-no-defer="1" id="faz-cookie-manager-js" data-loading-method="user-interaction" data-src="https://shop.b-cdn.net/wp-content/plugins/faz-cookie-manager/frontend/js/script.min.js?ver=1&amp;x=2"></script>',
			'<script data-no-defer="1" id="faz-cookie-manager-js" src="https://shop.b-cdn.net/wp-content/plugins/faz-cookie-manager/frontend/js/script.min.js?ver=1&amp;x=2"></script>',
		),
		'GCM default delayed by a "Delay selected" gtag keyword' => array(
			'<script id="faz-cookie-manager-gcm-default-js-before" data-loading-method="user-interaction" data-src="data:text/javascript,' . rawurlencode( "gtag('consent','default',{});" ) . '"></script>',
			"<script id=\"faz-cookie-manager-gcm-default-js-before\">gtag('consent','default',{});</script>",
		),
		'inline body turned into a deferred data: URI' => array(
			'<script id="faz-cookie-manager-js-before" defer src="data:text/javascript,' . rawurlencode( 'window._fazGcm = 1;' ) . '"></script>',
			'<script id="faz-cookie-manager-js-before">window._fazGcm = 1;</script>',
		),
		'parked non-script type is dropped' => array(
			'<script type="flying-press/delayed" id="faz-fw-js" data-loading-method="idle" data-src="/wp-content/uploads/faz-fw/a.js"></script>',
			'<script id="faz-fw-js" src="/wp-content/uploads/faz-fw/a.js"></script>',
		),
		'banner stylesheet held back by Remove unused CSS' => array(
			"<link rel='stylesheet' id='faz-cookie-manager-css' media='all' data-href='https://example.test/wp-content/uploads/faz-cookie-manager/assets/banner-abc.css' />",
			"<link rel='stylesheet' id='faz-cookie-manager-css' media='all' href=\"https://example.test/wp-content/uploads/faz-cookie-manager/assets/banner-abc.css\"/>",
		),
	);
	foreach ( $cases as $label => $case ) {
		$page = '<html><head>' . $case[0] . '</head><body></body></html>';
		faz_ob_ok(
			'<html><head>' . $case[1] . '</head><body></body></html>' === $restore( $page ),
			'16 restored: ' . $label
		);
	}
	faz_ob_ok( 1 === count( $GLOBALS['faz_test_transients'] ), '17 the restoration leaves one diagnostic transient' );
	$log_lines = array_filter( explode( "\n", (string) file_get_contents( $log_file ) ) );
	faz_ob_ok(
		1 === count( $log_lines ) && false !== strpos( implode( '', $log_lines ), 'FAZ Cookie Manager: FlyingPress rewrote 1 FAZ asset' ),
		'18 the diagnostic is logged once, not once per page'
	);

	$untouched = array(
		'a delayed third-party script'              => '<script id="google-gtag-js" data-loading-method="user-interaction" data-src="https://www.googletagmanager.com/gtag/js"></script>',
		'a FAZ script FlyingPress did not rewrite'  => '<script id="faz-cookie-manager-js" src="/wp-content/plugins/faz-cookie-manager/frontend/js/script.min.js" defer></script>',
		'the banner template'                       => '<script id="fazBannerTemplate" type="text/template"><div class="faz-consent-container"></div></script>',
		'a FAZ stylesheet that still has its href'  => "<link rel='stylesheet' id='faz-cookie-manager-css' href='/banner.css' data-href='/old.css' />",
		'another plugin stylesheet held back'       => "<link rel='stylesheet' id='theme-css' data-href='/theme.css' />",
		'a look-alike id that is not a FAZ handle'  => '<script id="faz-cookie-manager" data-loading-method="user-interaction" data-src="/x.js"></script>',
	);
	foreach ( $untouched as $label => $tag ) {
		$page = '<html><head>' . $tag . '<link rel="stylesheet" id="faz-cookie-manager-css" href="/b.css"></head></html>';
		faz_ob_ok( $page === $restore( $page ), '19 left alone: ' . $label );
	}

	$restored_once = $restore( '<head>' . $cases['delayed inline config (third-party pass)'][0] . '</head>' );
	faz_ob_ok( $restored_once === $restore( $restored_once ), '20 the safety net is idempotent' );
	$log_lines = array_filter( explode( "\n", (string) file_get_contents( $log_file ) ) );
	faz_ob_ok( 1 === count( $log_lines ), '21 later restorations within the day do not log again' );
	unlink( $log_file );

	echo "\n" . ( 0 === $faz_fail ? "ALL PASS ({$faz_pass})\n" : "FAILED: {$faz_fail}, passed: {$faz_pass}\n" );
	exit( 0 === $faz_fail ? 0 : 1 );
}
