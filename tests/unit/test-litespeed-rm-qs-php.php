<?php
/**
 * FAZ's versioned asset URLs must survive LiteSpeed's "Remove Query Strings".
 *
 * LiteSpeed Cache (optm-qs_rm) hooks script_loader_src / style_loader_src at
 * priority 999 and drops everything after `?` from internal .js/.css URLs —
 * the `?ver=` that changes on every FAZ release. After an update the URL of
 * script.min.js stayed the same, so browsers, CDNs and QUIC.cloud kept the
 * previous runtime against the new server-rendered config. LiteSpeed skips a
 * URL that contains `_litespeed_rm_qs=0`.
 *
 * The LiteSpeed pass below is a verbatim port of
 * litespeed-cache/src/optimize.cls.php::remove_query_strings() (7.x), minus
 * the internal-file check, which every FAZ URL passes.
 *
 * Run: php tests/unit/test-litespeed-rm-qs-php.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'FAZ_PLUGIN_URL', 'https://example.test/wp-content/plugins/faz-cookie-manager/' );

	function is_admin() { return ! empty( $GLOBALS['rmqs_admin'] ); }

	require dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	function litespeed_remove_query_strings( $src ) {
		if ( strpos( $src, '_litespeed_rm_qs=0' ) || strpos( $src, '/recaptcha' ) ) {
			return $src;
		}
		if ( strpos( $src, '.js?' ) !== false || strpos( $src, '.css?' ) !== false ) {
			$src = preg_replace( '/\?.*/', '', $src );
		}
		return $src;
	}

	$passed = 0;
	$failed = 0;
	function rmqs_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) { $passed++; echo "  PASS {$label}\n"; return; }
		$failed++;
		echo "  FAIL {$label}\n";
	}

	$frontend = ( new ReflectionClass( Frontend::class ) )->newInstanceWithoutConstructor();
	$name     = new ReflectionProperty( Frontend::class, 'plugin_name' );
	$name->setAccessible( true );
	$name->setValue( $frontend, 'faz-cookie-manager' );

	/** WordPress order: FAZ at priority 10, LiteSpeed at 999. */
	function rmqs_pipeline( $frontend, $src, $handle ) {
		$src = method_exists( $frontend, 'keep_own_asset_version_query' ) ? $frontend->keep_own_asset_version_query( $src, $handle ) : $src;
		return litespeed_remove_query_strings( $src );
	}

	echo "FAZ assets keep their version through LiteSpeed\n";
	$plugin = FAZ_PLUGIN_URL;
	$cases  = array(
		'faz-cookie-manager'                   => $plugin . 'frontend/js/script.min.js?ver=1.34.2',
		'faz-cookie-manager-gcm'               => $plugin . 'frontend/js/gcm.min.js?ver=1.34.2',
		'faz-cookie-manager-tcf-cmp'           => $plugin . 'frontend/js/tcf-cmp.min.js?ver=1.34.2',
		'faz-cookie-manager-a11y'              => $plugin . 'frontend/js/a11y.min.js?ver=1.34.2',
		'faz-cookie-manager-wca'               => $plugin . 'frontend/js/wca.min.js?ver=1.34.2',
		'faz-cookie-manager-microsoft-consent' => $plugin . 'frontend/js/microsoft-consent.min.js?ver=1.34.2',
		'faz-dsar-form'                        => $plugin . 'frontend/js/faz-dsar.min.js?ver=1.34.2',
		'faz-dnsmpi-form'                      => $plugin . 'frontend/js/faz-dnsmpi.min.js?ver=1.34.2',
		'faz-fw'                               => $plugin . 'frontend/js/script.min.js?ver=1.34.2',
		'faz-legal-links'                      => 'http://example.test/wp-content/plugins/faz-cookie-manager/frontend/css/faz-legal-links.css?ver=1.34.2',
	);
	foreach ( $cases as $handle => $src ) {
		$out = rmqs_pipeline( $frontend, $src, $handle );
		rmqs_check( false !== strpos( $out, 'ver=1.34.2' ), "{$handle}: ?ver= survives LiteSpeed" );
	}

	echo "Everything else is left alone\n";
	$hashed = 'https://example.test/wp-content/uploads/faz-cookie-manager/assets/config-abc123.js';
	rmqs_check( $hashed === $frontend->keep_own_asset_version_query( $hashed, 'faz-cookie-manager-static-config' ), 'content-hashed file without a query string: untouched' );
	$theme = 'https://example.test/wp-content/themes/x/app.js?ver=2';
	rmqs_check( $theme === $frontend->keep_own_asset_version_query( $theme, 'theme-app' ), 'a foreign asset: untouched' );
	$once = $frontend->keep_own_asset_version_query( $cases['faz-cookie-manager'], 'faz-cookie-manager' );
	rmqs_check( $once === $frontend->keep_own_asset_version_query( $once, 'faz-cookie-manager' ), 'idempotent' );
	rmqs_check( 1 === substr_count( $once, '_litespeed_rm_qs=0' ), 'one marker, appended as a query parameter' );
	$GLOBALS['rmqs_admin'] = true;
	rmqs_check( $cases['faz-cookie-manager'] === $frontend->keep_own_asset_version_query( $cases['faz-cookie-manager'], 'faz-cookie-manager' ), 'admin screens untouched' );
	$GLOBALS['rmqs_admin'] = false;

	echo "litespeed-rm-qs: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
