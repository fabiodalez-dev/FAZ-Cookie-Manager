<?php
/**
 * The PECL geoip extension is a country source only when it has a database.
 *
 * Many hosts compile the extension in without installing a GeoIP database;
 * every lookup then returns false and no visitor ever resolves a country. The
 * predicate behind the page-cache veto (Geolocation::has_country_source())
 * counted the bare function as a source, so on those hosts every front-end
 * response was no-store for a render that never varied by country.
 *
 * Loads the REAL includes/class-geolocation.php; the extension is simulated
 * with global functions and constants, which is exactly how PHP exposes it.
 *
 * Run: php tests/unit/test-geoip-extension-source-php.php
 */
namespace {
	$faz_tmp = sys_get_temp_dir() . '/faz-geoip-ext-' . getmypid() . '/';
	@mkdir( $faz_tmp . 'faz-cookie-manager/', 0777, true );
	define( 'ABSPATH', $faz_tmp );

	$GLOBALS['geoip_db'] = array();

	function apply_filters( $tag, $value ) { return $value; }
	function has_filter( $tag ) { return false; }
	function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
	function wp_upload_dir() { return array( 'basedir' => rtrim( ABSPATH, '/' ), 'baseurl' => 'http://example.test' ); }

	// The extension, as PHP exposes it: the lookup, the database probe and the
	// edition constants. No database is "installed" until a test adds one.
	define( 'GEOIP_COUNTRY_EDITION', 1 );
	define( 'GEOIP_COUNTRY_EDITION_V6', 12 );
	function geoip_country_code_by_name( $host ) { return false; }
	function geoip_db_avail( $edition ) { return ! empty( $GLOBALS['geoip_db'][ $edition ] ); }

	require dirname( __DIR__, 2 ) . '/includes/class-geolocation.php';

	use FazCookie\Includes\Geolocation;

	$passed = 0;
	$failed = 0;
	function geoip_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) { $passed++; echo "  PASS {$label}\n"; return; }
		$failed++;
		echo "  FAIL {$label}\n";
	}

	echo "PECL geoip without a database\n";
	geoip_check( false === Geolocation::has_country_source(), 'extension loaded, no country database: not a source (no page-cache veto)' );

	echo "PECL geoip with a database\n";
	$GLOBALS['geoip_db'][ GEOIP_COUNTRY_EDITION ] = true;
	geoip_check( true === Geolocation::has_country_source(), 'country database available: a source' );
	$GLOBALS['geoip_db'] = array( GEOIP_COUNTRY_EDITION_V6 => true );
	geoip_check( true === Geolocation::has_country_source(), 'IPv6 country database alone: a source' );

	@rmdir( $faz_tmp . 'faz-cookie-manager/' );
	@rmdir( $faz_tmp );
	echo "geoip-extension-source: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
