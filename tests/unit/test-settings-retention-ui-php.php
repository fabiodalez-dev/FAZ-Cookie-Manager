<?php
/**
 * Settings-screen regression checks for retention controls and effective-state
 * status labels. The settings API already tests persistence/clamping; this
 * pins the missing admin controls so a supported retention setting cannot
 * silently become unreachable again.
 *
 * The second half boots the WP-CLI command class and runs `wp faz status`
 * against a saved option, because the admin System Status page and the CLI
 * table are two renderings of the same judgement and they had already
 * drifted once: the admin twin was corrected to read the live per-service /
 * per-cookie flags while the CLI kept the literal "No (disabled in 1.18.2)"
 * for two more minor versions. A file-level string check on the view cannot
 * catch that, so the CLI is exercised for real.
 *
 * Run: php tests/unit/test-settings-retention-ui-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace WP_CLI\Utils {
	/**
	 * Capture the rows `wp faz status` would print instead of formatting them.
	 *
	 * @param string $format Output format.
	 * @param array  $items  Rows.
	 * @param array  $fields Column names.
	 * @return void
	 */
	function format_items( $format, $items, $fields ) {
		unset( $format, $fields );
		$GLOBALS['faz_cli_rows'] = $items;
	}
}

namespace {

	$root     = dirname( __DIR__, 2 );
	$settings = (string) file_get_contents( $root . '/admin/views/settings.php' );
	$status   = (string) file_get_contents( $root . '/admin/views/system-status.php' );
	$passed   = 0;
	$failed   = 0;

	function settings_ui_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) {
			$passed++;
			echo "  [PASS] {$label}\n";
		} else {
			$failed++;
			echo "  [FAIL] {$label}\n";
		}
	}

	echo "== Settings retention UI ==\n";
	settings_ui_check( false !== strpos( $settings, 'data-path="pageviews.retention"' ), 'pageview retention is editable in Settings' );
	settings_ui_check( false !== strpos( $settings, 'data-path="dsar.retention"' ), 'DSAR retention is editable in Settings' );
	settings_ui_check( false !== strpos( $settings, 'data-path="pageviews.retention" value="6" min="0" max="120"' ), 'pageview UI exposes the supported zero (no auto-purge) value' );
	settings_ui_check( false !== strpos( $settings, 'data-path="dsar.retention" value="24" min="1" max="120"' ), 'DSAR UI preserves the accountability floor' );
	settings_ui_check( false !== strpos( $status, 'Per-Cookie Consent' ), 'system status reports the effective per-cookie feature state' );
	settings_ui_check( false === strpos( $status, 'disabled in 1.18.2' ), 'system status does not present a stale version-bound status message' );

	// ------------------------------------------------------------------
	// `wp faz status` — the CLI twin of the System Status table.
	// ------------------------------------------------------------------

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root . '/' );
	}
	define( 'WP_CLI', true );
	define( 'FAZ_VERSION', 'test' );

	$GLOBALS['faz_cli_options'] = array();
	$GLOBALS['faz_cli_rows']    = array();

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_cli_options'] )
			? $GLOBALS['faz_cli_options'][ $name ]
			: $default;
	}

	function get_bloginfo( $show = '' ) {
		unset( $show );
		return '6.9';
	}

	$wpdb = new class() {
		public $prefix = 'wp_';
		public function get_var( $query ) {
			unset( $query );
			return 0;
		}
	};

	require_once $root . '/includes/class-wp-cli-commands.php';

	/**
	 * Run `wp faz status` for a saved faz_settings option and return the
	 * table as key => value.
	 *
	 * @param array $faz_settings The stored option.
	 * @return array<string,string>
	 */
	function faz_cli_status_rows( array $faz_settings ) {
		$GLOBALS['faz_cli_options'] = array(
			'faz_settings'     => $faz_settings,
			'faz_gcm_settings' => array( 'status' => false ),
		);
		$GLOBALS['faz_cli_rows']    = array();
		$cli = new \FazCookie\Includes\WP_CLI_Commands();
		$cli->status( array(), array( 'format' => 'json' ) );
		$out = array();
		foreach ( (array) $GLOBALS['faz_cli_rows'] as $row ) {
			$out[ (string) $row['Key'] ] = (string) $row['Value'];
		}
		return $out;
	}

	echo "== wp faz status reports the live per-service / per-cookie state ==\n";

	$cli_on = faz_cli_status_rows(
		array(
			'banner_control' => array(
				'status'              => true,
				'per_service_consent' => true,
				'per_cookie_consent'  => true,
			),
		)
	);
	settings_ui_check( isset( $cli_on['Per-Service Consent'] ) && 'Yes' === $cli_on['Per-Service Consent'], 'CLI: per-service consent enabled in the option is reported as Yes' );
	settings_ui_check( isset( $cli_on['Per-Cookie Consent'] ) && 'Yes' === $cli_on['Per-Cookie Consent'], 'CLI: per-cookie consent enabled in the option is reported as Yes' );

	$cli_service_only = faz_cli_status_rows(
		array(
			'banner_control' => array(
				'status'              => true,
				'per_service_consent' => true,
				'per_cookie_consent'  => false,
			),
		)
	);
	settings_ui_check( isset( $cli_service_only['Per-Service Consent'] ) && 'Yes' === $cli_service_only['Per-Service Consent'], 'CLI: per-service on / per-cookie off reports per-service as Yes' );
	settings_ui_check( isset( $cli_service_only['Per-Cookie Consent'] ) && 'No' === $cli_service_only['Per-Cookie Consent'], 'CLI: per-service on / per-cookie off reports per-cookie as No' );

	$cli_off = faz_cli_status_rows(
		array(
			'banner_control' => array(
				'status'              => true,
				'per_service_consent' => false,
				'per_cookie_consent'  => false,
			),
		)
	);
	settings_ui_check( isset( $cli_off['Per-Service Consent'] ) && 'No' === $cli_off['Per-Service Consent'], 'CLI: per-service consent disabled in the option is reported as a plain No' );
	settings_ui_check( isset( $cli_off['Per-Cookie Consent'] ) && 'No' === $cli_off['Per-Cookie Consent'], 'CLI: per-cookie consent disabled in the option is reported as a plain No' );

	$cli_legacy = faz_cli_status_rows( array( 'banner_control' => array( 'status' => true ) ) );
	settings_ui_check( isset( $cli_legacy['Per-Service Consent'] ) && 'No' === $cli_legacy['Per-Service Consent'], 'CLI: an option without the keys reports per-service as No' );
	settings_ui_check( isset( $cli_legacy['Per-Cookie Consent'] ) && 'No' === $cli_legacy['Per-Cookie Consent'], 'CLI: an option without the keys reports per-cookie as No' );

	$cli_source = (string) file_get_contents( $root . '/includes/class-wp-cli-commands.php' );
	settings_ui_check( false === strpos( $cli_source, 'disabled in 1.18.2' ), 'CLI source does not carry the stale version-bound status message' );

	echo "Passed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
