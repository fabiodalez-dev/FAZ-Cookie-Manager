<?php
/**
 * GeoLite2 download, activation, and lookup integration test.
 *
 * This test downloads the small official MaxMind test databases from the
 * MaxMind-DB repository. The authenticated production endpoint is replaced by
 * a download_url() stub so the complete archive extraction and activation path
 * remains testable without storing a MaxMind license key in the repository.
 *
 * Run from the project root:
 *   php tests/integration/test-geolite2.php
 *
 * @package FazCookie\Tests\Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
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

$faz_test_root     = sys_get_temp_dir() . '/faz-geolite2-' . bin2hex( random_bytes( 6 ) );
$faz_test_archives = array();
$faz_test_edition  = 'GeoLite2-Country';
$tests_run         = 0;
$tests_failed      = 0;

function __( $message, $domain = 'default' ) { // phpcs:ignore
	unset( $domain );
	return $message;
}

function esc_html( $value ) { // phpcs:ignore
	return (string) $value;
}

function sanitize_text_field( $value ) { // phpcs:ignore
	return trim( (string) $value );
}

function is_wp_error( $value ) { // phpcs:ignore
	return $value instanceof WP_Error;
}

function trailingslashit( $value ) { // phpcs:ignore
	return rtrim( $value, '/\\' ) . '/';
}

function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) { // phpcs:ignore
	unset( $time, $create_dir, $refresh_cache );
	global $faz_test_root;
	return array( 'basedir' => $faz_test_root . '/uploads' );
}

function wp_mkdir_p( $directory ) { // phpcs:ignore
	return is_dir( $directory ) || mkdir( $directory, 0777, true );
}

function wp_generate_uuid4() { // phpcs:ignore
	return bin2hex( random_bytes( 16 ) );
}

function apply_filters( $tag, $value, ...$args ) { // phpcs:ignore
	unset( $args );
	global $faz_test_edition;
	if ( 'faz_geolite2_edition' === $tag ) {
		return $faz_test_edition;
	}
	return $value;
}

function add_query_arg( ...$args ) { // phpcs:ignore
	$query = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : array();
	$url   = isset( $args[1] ) ? $args[1] : '';
	return $url . '?' . http_build_query( $query );
}

function download_url( $url, $timeout = 300, $signature_verification = false ) { // phpcs:ignore
	unset( $timeout, $signature_verification );
	global $faz_test_archives, $faz_test_root;

	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
	$edition = isset( $query['edition_id'] ) ? $query['edition_id'] : '';
	if ( ! isset( $faz_test_archives[ $edition ] ) ) {
		return new WP_Error( 'missing_fixture', 'No archive fixture for ' . $edition );
	}

	$destination = $faz_test_root . '/download-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
	if ( ! copy( $faz_test_archives[ $edition ], $destination ) ) {
		return new WP_Error( 'fixture_copy_failed', 'Could not stage download fixture.' );
	}
	return $destination;
}

function faz_test_assert( $condition, $label ) {
	global $tests_run, $tests_failed;
	$tests_run++;
	if ( $condition ) {
		echo "[PASS] {$label}\n";
		return;
	}
	$tests_failed++;
	echo "[FAIL] {$label}\n";
}

function faz_test_fetch( $url, $destination ) {
	$context = stream_context_create(
		array(
			'http' => array(
				'timeout'    => 30,
				'user_agent' => 'FAZ-Cookie-Manager-GeoLite2-Test',
			),
		)
	);
	$data    = @file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $data || '' === $data ) {
		throw new RuntimeException( 'Could not download official fixture: ' . $url );
	}
	if ( false === file_put_contents( $destination, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		throw new RuntimeException( 'Could not write fixture: ' . $destination );
	}
}

function faz_test_archive( $root, $directory, $archive ) {
	$command = sprintf(
		'tar -czf %s -C %s %s',
		escapeshellarg( $archive ),
		escapeshellarg( $root ),
		escapeshellarg( $directory )
	);
	exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	if ( 0 !== $status ) {
		throw new RuntimeException( 'Could not create archive fixture.' );
	}
}

function faz_test_remove_tree( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}
	rmdir( $path );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-mmdb-reader.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-geolocation.php';

use FazCookie\Includes\Geolocation;
use FazCookie\Includes\Mmdb_Reader;

try {
	wp_mkdir_p( $faz_test_root );

	$country_mmdb = $faz_test_root . '/GeoIP2-Country-Test.mmdb';
	$city_mmdb    = $faz_test_root . '/GeoIP2-City-Test.mmdb';
	faz_test_fetch( 'https://raw.githubusercontent.com/maxmind/MaxMind-DB/main/test-data/GeoIP2-Country-Test.mmdb', $country_mmdb );
	faz_test_fetch( 'https://raw.githubusercontent.com/maxmind/MaxMind-DB/main/test-data/GeoIP2-City-Test.mmdb', $city_mmdb );

	$country_dir = $faz_test_root . '/country/GeoLite2-Country_20260611';
	$city_dir    = $faz_test_root . '/city/GeoLite2-City_20260611';
	$corrupt_dir = $faz_test_root . '/corrupt/GeoLite2-City_20260611';
	wp_mkdir_p( $country_dir );
	wp_mkdir_p( $city_dir );
	wp_mkdir_p( $corrupt_dir );
	copy( $country_mmdb, $country_dir . '/GeoLite2-Country.mmdb' );
	copy( $city_mmdb, $city_dir . '/GeoLite2-City.mmdb' );
	file_put_contents( $corrupt_dir . '/GeoLite2-City.mmdb', "not an MMDB\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$country_archive = $faz_test_root . '/GeoLite2-Country.tar.gz';
	$city_archive    = $faz_test_root . '/GeoLite2-City.tar.gz';
	$corrupt_archive = $faz_test_root . '/GeoLite2-City-Corrupt.tar.gz';
	faz_test_archive( dirname( $country_dir ), basename( $country_dir ), $country_archive );
	faz_test_archive( dirname( $city_dir ), basename( $city_dir ), $city_archive );
	faz_test_archive( dirname( $corrupt_dir ), basename( $corrupt_dir ), $corrupt_archive );

	$faz_test_archives = array(
		'GeoLite2-Country' => $country_archive,
		'GeoLite2-City'    => $city_archive,
	);

	$country_result = Geolocation::download_database( 'fixture-key', 'GeoLite2-Country' );
	$country_path   = Geolocation::get_database_path();
	$country_reader = new Mmdb_Reader( $country_path );
	faz_test_assert( true === $country_result, 'Country archive downloads and activates.' );
	faz_test_assert( 'GeoLite2-Country.mmdb' === basename( $country_path ), 'Country is the active filename.' );
	faz_test_assert( 'GB' === $country_reader->country( '81.2.69.160' ), 'Country database resolves the country.' );
	faz_test_assert( '' === $country_reader->subdivision( '81.2.69.160' ), 'Country database has no subdivision.' );

	$faz_test_edition = 'GeoLite2-City';
	$city_result      = Geolocation::download_database( 'fixture-key', 'GeoLite2-City' );
	$city_path        = Geolocation::get_database_path();
	$city_reader      = new Mmdb_Reader( $city_path );
	faz_test_assert( true === $city_result, 'City archive downloads and activates.' );
	faz_test_assert( 'GeoLite2-City.mmdb' === basename( $city_path ), 'City is the active filename.' );
	faz_test_assert( 'GB' === $city_reader->country( '81.2.69.160' ), 'City database still resolves the country.' );
	faz_test_assert( 'ENG' === $city_reader->subdivision( '81.2.69.160' ), 'City database resolves the subdivision.' );
	faz_test_assert( ! file_exists( Geolocation::get_data_dir() . 'GeoLite2-Country.mmdb' ), 'Switching to City removes Country.' );

	copy( $country_mmdb, Geolocation::get_data_dir() . 'GeoLite2-Country.mmdb' );
	faz_test_assert( $city_path === Geolocation::get_database_path(), 'Configured City wins when both editions exist.' );
	$faz_test_edition = 'GeoLite2-Country';
	faz_test_assert(
		'GeoLite2-Country.mmdb' === basename( Geolocation::get_database_path() ),
		'Configured Country wins when both editions exist.'
	);
	$faz_test_edition = 'GeoLite2-City';
	unlink( Geolocation::get_data_dir() . 'GeoLite2-Country.mmdb' );

	$city_hash                           = hash_file( 'sha256', $city_path );
	$faz_test_archives['GeoLite2-City'] = $country_archive;
	$wrong_result                       = Geolocation::download_database( 'fixture-key', 'GeoLite2-City' );
	faz_test_assert( is_wp_error( $wrong_result ), 'Wrong-edition MMDB is rejected.' );
	faz_test_assert( $city_hash === hash_file( 'sha256', $city_path ), 'Wrong edition leaves active City unchanged.' );

	$faz_test_archives['GeoLite2-City'] = $corrupt_archive;
	$corrupt_result                     = Geolocation::download_database( 'fixture-key', 'GeoLite2-City' );
	faz_test_assert( is_wp_error( $corrupt_result ), 'Corrupt MMDB is rejected.' );
	faz_test_assert( $city_hash === hash_file( 'sha256', $city_path ), 'Corrupt archive leaves active City unchanged.' );

	$staging = glob( Geolocation::get_data_dir() . '.GeoLite2-*' );
	faz_test_assert( empty( $staging ), 'Failed activations leave no staging files.' );
} catch ( Throwable $e ) {
	$tests_failed++;
	echo '[FAIL] Integration setup: ' . $e->getMessage() . "\n";
} finally {
	faz_test_remove_tree( $faz_test_root );
}

echo "\n{$tests_run} checks, {$tests_failed} failures\n";
exit( $tests_failed > 0 ? 1 : 0 );
