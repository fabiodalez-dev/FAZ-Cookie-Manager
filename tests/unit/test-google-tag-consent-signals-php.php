<?php
/**
 * Standalone unit tests: the built-in Google tag never runs without Consent
 * Mode signals.
 *
 * Subsystem: google-tag-consent-signals-php
 *
 * The built-in Google tag (#366) is printed whether or not Google Consent Mode
 * is switched on, while gcm.js — the only thing that emits `consent default`
 * and the `consent update` on the visitor's choice — was enqueued only with
 * GCM on. With GCM off and a GT-/G- ID configured, a visitor who accepted
 * Analytics alone got gtag.js with no consent state at all, and Google reads a
 * tag without a default as fully granted: Ads destinations linked to the tag
 * and Google Signals wrote advertising cookies without Marketing consent.
 *
 * Pinned here:
 *   1. Gcm_Settings::sends_consent_signals() is true with GCM on, and with GCM
 *      off as soon as one valid built-in ID is configured; false otherwise.
 *   2. Configured IDs do NOT turn Advanced mode on: with GCM off the tags stay
 *      parked until consent (print_google_tag() output).
 *   3. Frontend decides whether to load gcm.js through that one method, never
 *      through the raw GCM switch.
 *   4. The admin help no longer says the tag is independent of Consent Mode.
 *
 * Run: php tests/unit/test-google-tag-consent-signals-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {
	class Store {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	$GLOBALS['faz_sig_option'] = null;

	function get_current_blog_id() {
		return 1;
	}
	function get_option( $name, $default = false ) {
		return null === $GLOBALS['faz_sig_option'] ? $default : $GLOBALS['faz_sig_option'];
	}
	function update_option( $name, $value ) {
		$GLOBALS['faz_sig_option'] = $value;
		return true;
	}
	function do_action( $hook, $value = null ) {}
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function faz_sanitize_bool( $value ) {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( 'false', '0', 'no', 'off', 'null', 'undefined', '' ), true );
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			return false;
		}
		return (bool) $value;
	}
	function faz_sanitize_text( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
	function esc_url( $value ) {
		return str_replace( '&', '&amp;', (string) $value );
	}

	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-known-providers.php';
	require_once $root . '/includes/class-google-tag.php';
	require_once $root . '/admin/modules/gcm/includes/class-gcm-settings.php';

	use FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings;
	use FazCookie\Includes\Google_Tag;

	$run  = 0;
	$fail = 0;
	function sig_ok( $condition, $label ) {
		global $run, $fail;
		++$run;
		if ( $condition ) {
			echo "  PASS {$label}\n";
			return;
		}
		++$fail;
		echo "  FAIL {$label}\n";
	}
	function sig_settings( array $option ) {
		$GLOBALS['faz_sig_option'] = $option;
		Gcm_Settings::flush_runtime_cache();
		return new Gcm_Settings();
	}
	function sig_signals( Gcm_Settings $settings ) {
		return method_exists( $settings, 'sends_consent_signals' ) ? $settings->sends_consent_signals() : 'missing method';
	}

	echo "Gcm_Settings::sends_consent_signals()\n";
	sig_ok( false === sig_signals( sig_settings( array( 'status' => false ) ) ), 'GCM off, no built-in tag: no signals, as before' );
	sig_ok( true === sig_signals( sig_settings( array( 'status' => true ) ) ), 'GCM on: signals' );
	sig_ok( true === sig_signals( sig_settings( array( 'status' => false, 'google_tag_ids' => 'GT-ABCD1234' ) ) ), 'GCM off + a GT- ID: signals are sent anyway' );
	sig_ok( true === sig_signals( sig_settings( array( 'status' => false, 'google_tag_ids' => 'G-ABCD1234' ) ) ), 'GCM off + a G- ID: signals' );
	sig_ok( true === sig_signals( sig_settings( array( 'status' => false, 'google_tag_ids' => 'AW-123456789' ) ) ), 'GCM off + an AW- ID: signals' );
	sig_ok( true === sig_signals( sig_settings( array( 'status' => false, 'google_tag_ids' => 'GTM-ABC123' ) ) ), 'GCM off + a container: signals (the Google tags inside it read them)' );
	sig_ok( false === sig_signals( sig_settings( array( 'status' => false, 'google_tag_ids' => 'UA-12345-1, nonsense' ) ) ), 'GCM off + only invalid IDs (nothing is printed): no signals' );

	// The mode gcm.js is told about: signals sent for the built-in tag alone.
	// There the Consent Mode state follows the banner's pre-consent state
	// (tests/unit/js/gcm-signals-only-preconsent.test.mjs).
	echo "\nGcm_Settings::sends_signals_only()\n";
	$only = function ( array $option ) {
		$settings = sig_settings( $option );
		return method_exists( $settings, 'sends_signals_only' ) ? $settings->sends_signals_only() : 'missing method';
	};
	sig_ok( true === $only( array( 'status' => false, 'google_tag_ids' => 'GT-ABCD1234' ) ), 'GCM off + a built-in tag: signals-only' );
	sig_ok( false === $only( array( 'status' => true, 'google_tag_ids' => 'GT-ABCD1234' ) ), 'GCM on + a built-in tag: the configured Consent Mode decides' );
	sig_ok( false === $only( array( 'status' => true ) ), 'GCM on: not signals-only' );
	sig_ok( false === $only( array( 'status' => false ) ), 'GCM off, no tag: nothing is sent at all' );

	// Settings saved while GCM was on must not come back with GCM off: the GCM
	// page then says everything is denied by default.
	echo "\nGcm_Settings::signals_only_payload()\n";
	$granted_row = array( 'ad_storage' => 'granted', 'analytics_storage' => 'granted', 'ad_user_data' => 'granted', 'ad_personalization' => 'granted', 'regions' => 'US' );
	$stored      = sig_settings( array( 'status' => false, 'google_tag_ids' => 'GT-ABCD1234', 'default_settings' => array( $granted_row ), 'url_passthrough' => true, 'ads_data_redaction' => true, 'gacm_enabled' => true, 'gacm_provider_ids' => '89,91', 'non_personalized_ads_fallback' => true, 'wait_for_update' => 2000 ) );
	$payload     = method_exists( $stored, 'signals_only_payload' ) ? $stored->signals_only_payload( $stored->get() ) : array();
	$rows        = isset( $payload['default_settings'] ) && is_array( $payload['default_settings'] ) ? $payload['default_settings'] : array();
	sig_ok( 1 === count( $rows ) && 'All' === $rows[0]['regions'], 'a stored region row is replaced by the single default row' );
	$denied = ! empty( $rows ) && 'denied' === $rows[0]['ad_storage'] && 'denied' === $rows[0]['analytics_storage'] && 'denied' === $rows[0]['ad_user_data'] && 'denied' === $rows[0]['ad_personalization'];
	sig_ok( $denied, 'every ad and analytics signal starts denied' );
	sig_ok( isset( $payload['url_passthrough'] ) && false === $payload['url_passthrough'], 'stored URL passthrough is dropped' );
	sig_ok( isset( $payload['ads_data_redaction'] ) && false === $payload['ads_data_redaction'], 'stored ads data redaction is dropped' );
	sig_ok( isset( $payload['gacm_enabled'] ) && false === $payload['gacm_enabled'] && '' === $payload['gacm_provider_ids'], 'stored Additional Consent is dropped' );
	sig_ok( isset( $payload['non_personalized_ads_fallback'] ) && false === $payload['non_personalized_ads_fallback'], 'stored non-personalised-ads fallback is dropped' );
	sig_ok( isset( $payload['wait_for_update'] ) && 500 === $payload['wait_for_update'], 'wait_for_update is the shipped default' );
	$fe_src_payload = (string) file_get_contents( $root . '/frontend/class-frontend.php' );
	sig_ok( 1 === preg_match( '/if \( \$gcm->sends_signals_only\(\) \) \{\s*return \$gcm->signals_only_payload\( \$gcm_settings \);\s*\}\s*\/\/ When the runtime geo-routing flag is on/', $fe_src_payload ), 'get_gcm_data() uses it in signals-only mode, before any ruleset override' );

	echo "\nconfigured IDs never switch Advanced mode on\n";
	$off = sig_settings( array( 'status' => false, 'advanced_mode' => true, 'advanced_mode_gtm' => true, 'google_tag_ids' => 'GT-ABCD1234, GTM-ABC123' ) );
	sig_ok( false === $off->is_advanced_mode(), 'GCM off: Advanced mode stays off whatever the stored flag says' );
	sig_ok( false === $off->is_advanced_gtm_mode(), 'GCM off: the container opt-in stays off' );
	// What print_google_tag() renders in that state: gtag_unblocked and
	// gtm_unblocked both false.
	$html = Google_Tag::render( $off->get_google_tag_ids() );
	preg_match_all( '/<script\b([^>]*)>/', $html, $tags );
	$parked = array_filter( $tags[1], function ( $a ) { return false !== strpos( $a, 'type="text/plain"' ); } );
	sig_ok( 3 === count( $tags[1] ) && 3 === count( $parked ), 'GCM off: every built-in tag waits for consent' );

	echo "\nFrontend loads gcm.js through that method\n";
	$fe_src = (string) file_get_contents( $root . '/frontend/class-frontend.php' );
	sig_ok( 0 === preg_match( '/->is_gcm_enabled\(\)/', $fe_src ), 'class-frontend.php never gates on the raw GCM switch' );
	sig_ok( 1 === preg_match( '/if \( true === \$this->gcm_settings->sends_consent_signals\(\) \) \{\s*\$gcm = \$this->get_gcm_data\(\);/', $fe_src ), 'the gcm.js enqueue is gated on sends_consent_signals()' );
	sig_ok( 1 === preg_match( '/\$gcm_active = is_object\( \$this->gcm_settings \) && true === \$this->gcm_settings->sends_consent_signals\(\);/', $fe_src ), 'the head/footer placement follows the same answer (gcm.js must stay in <head>)' );

	echo "\nadmin help\n";
	$view = (string) file_get_contents( $root . '/admin/views/gcm.php' );
	sig_ok( false === strpos( $view, 'This works whether or not Google Consent Mode is enabled' ), 'the help no longer calls the tag independent of Consent Mode' );
	sig_ok( false !== strpos( $view, 'still sends the Consent Mode signals' ), 'and says the signals are sent with Consent Mode off' );

	echo "\n";
	if ( 0 === $fail ) {
		echo "ALL PASS — {$run}/{$run}\n";
		exit( 0 );
	}
	echo "{$fail} FAILED — " . ( $run - $fail ) . "/{$run} passed\n";
	exit( 1 );
}
