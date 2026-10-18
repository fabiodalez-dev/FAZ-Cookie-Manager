<?php
/**
 * Standalone unit tests — vendors the GVL marks as deleted are not vendors.
 *
 * Subsystem: gvl-deleted-vendors-php
 *
 * The IAB keeps de-registered vendors in vendor-list.json with a `deletedDate`
 * ("If present, vendor is considered deleted after this date/time and MUST NOT
 * be established to users"). In the live v3 list roughly one vendor in six
 * carries one. Gvl::get_vendors() returned them like any other vendor, so the
 * admin picker offered them, the first-download auto-select picked twenty of
 * them, the preference center listed them, and the CMP granted them consent,
 * legitimate interest and a place in DisclosedVendors — a positive TC-string
 * signal for a vendor that has left the Framework.
 *
 * Pure-logic tests: get_option()/update_option() are backed by a global array,
 * the IAB download is a stubbed wp_remote_get(), and files land in a temp dir.
 *
 * Run from project root:
 *   php tests/unit/test-gvl-deleted-vendors-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'LOCK_EX' ) ) {
		define( 'LOCK_EX', 2 );
	}

	$GLOBALS['faz_test_options']  = array();
	$GLOBALS['faz_test_gvl_body'] = '';

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
	function absint( $v ) {
		return abs( (int) $v );
	}
	function sanitize_text_field( $v ) {
		return trim( (string) $v );
	}
	function sanitize_file_name( $v ) {
		return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $v );
	}
	function current_time( $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
	function is_wp_error( $thing ) {
		return false;
	}
	function wp_remote_get( $url, $args = array() ) {
		return array( 'code' => 200, 'body' => $GLOBALS['faz_test_gvl_body'] );
	}
	function wp_remote_retrieve_response_code( $r ) {
		return $r['code'];
	}
	function wp_remote_retrieve_body( $r ) {
		return $r['body'];
	}
	function wp_upload_dir() {
		$dir = sys_get_temp_dir() . '/faz-gvl-test-' . getmypid();
		return array( 'basedir' => $dir, 'baseurl' => 'http://localhost/uploads' );
	}
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
	function trailingslashit( $s ) {
		return rtrim( $s, '/' ) . '/';
	}
	function wp_tempnam( $filename, $dir ) {
		return tempnam( $dir, 'faz' );
	}
	function wp_delete_file( $f ) {
		@unlink( $f );
	}

	$GLOBALS['faz_test_actions']         = array();
	$GLOBALS['faz_test_template_clears'] = 0;
	function do_action( $tag ) {
		$GLOBALS['faz_test_actions'][] = $tag;
	}
	function faz_clear_banner_template_cache() {
		++$GLOBALS['faz_test_template_clears'];
	}
	function faz_default_language() {
		return 'en';
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-gvl.php';

	use FazCookie\Includes\Gvl;

	$tests_run = $tests_passed = $tests_failed = 0;
	function assert_eq( $actual, $expected, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $actual === $expected ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m $label\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m $label\n";
			echo '      expected: ' . var_export( $expected, true ) . "\n";
			echo '      actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	$past   = gmdate( 'Y-m-d\TH:i:s\Z', time() - 86400 * 30 );
	$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + 86400 * 30 );

	$gvl_json = array(
		'gvlSpecificationVersion' => 3,
		'vendorListVersion'       => 180,
		'tcfPolicyVersion'        => 5,
		'vendors'                 => array(
			'1'   => array( 'id' => 1, 'name' => 'Live Vendor', 'purposes' => array( 1, 3 ), 'legIntPurposes' => array( 2 ) ),
			'68'  => array( 'id' => 68, 'name' => 'Amazon Ad Server', 'purposes' => array( 1 ), 'legIntPurposes' => array( 2, 7 ), 'deletedDate' => $past ),
			'999' => array( 'id' => 999, 'name' => 'Leaving Soon', 'purposes' => array( 1 ), 'legIntPurposes' => array(), 'deletedDate' => $future ),
			'853' => array( 'id' => 853, 'name' => 'Ensighten', 'purposes' => array( 1 ), 'legIntPurposes' => array(), 'deletedDate' => 'not-a-date' ),
		),
		'purposes'                => array( '1' => array( 'id' => 1, 'name' => 'Store' ) ),
	);

	echo "\n\033[1mGVL deletedDate handling\033[0m\n\n";

	// ---------------------------------------------------------------------
	// Reading: a stored GVL with deleted vendors.
	// ---------------------------------------------------------------------
	$GLOBALS['faz_test_options'][ Gvl::OPTION_KEY ] = $gvl_json;
	$GLOBALS['faz_test_options'][ Gvl::META_KEY ]   = array( 'version' => 180 );
	$gvl = Gvl::get_instance();

	echo "Gvl::get_vendors()\n";
	$all = $gvl->get_vendors();
	assert_eq( isset( $all[1] ), true, 'a live vendor is returned' );
	assert_eq( isset( $all[68] ), false, 'a vendor with a past deletedDate is NOT returned' );
	assert_eq( isset( $all[999] ), true, 'a vendor whose deletedDate is still in the future is returned (deleted only after that date)' );
	assert_eq( isset( $all[853] ), false, 'an unparseable deletedDate is treated as deleted (fail closed)' );

	$subset = $gvl->get_vendors( array( 68, 1, 999, 4242 ) );
	assert_eq( array_keys( $subset ), array( 1, 999 ), 'get_vendors( ids ) drops deleted and unknown ids, keeps the live ones in order' );

	echo "\nGvl::get_vendor()\n";
	assert_eq( is_array( $gvl->get_vendor( 1 ) ), true, 'get_vendor() returns a live vendor' );
	assert_eq( $gvl->get_vendor( 68 ), null, 'get_vendor() returns null for a deleted vendor' );

	echo "\nGvl::is_vendor_deleted()\n";
	assert_eq( Gvl::is_vendor_deleted( array( 'id' => 1 ) ), false, 'no deletedDate → not deleted' );
	assert_eq( Gvl::is_vendor_deleted( array( 'id' => 68, 'deletedDate' => $past ) ), true, 'past deletedDate → deleted' );
	assert_eq( Gvl::is_vendor_deleted( array( 'id' => 999, 'deletedDate' => $future ) ), false, 'future deletedDate → not yet deleted' );
	assert_eq( Gvl::is_vendor_deleted( array( 'id' => 7, 'deletedDate' => null ) ), false, 'an explicit null deletedDate is no date → not deleted' );
	assert_eq( Gvl::is_vendor_deleted( array( 'id' => 8, 'deletedDate' => '' ) ), false, 'an empty deletedDate is no date → not deleted' );
	assert_eq( Gvl::is_vendor_deleted( array( 'id' => 9, 'deletedDate' => 'not a date' ) ), true, 'a present but unreadable date still fails closed → deleted' );

	// ---------------------------------------------------------------------
	// Download: the selected-vendor option is pruned to live vendors.
	// ---------------------------------------------------------------------
	echo "\nGvl::download() prunes faz_gvl_selected_vendors\n";
	$GLOBALS['faz_test_gvl_body'] = json_encode( $gvl_json );
	$GLOBALS['faz_test_options']['faz_gvl_selected_vendors'] = array( 68, 1, 853, 4242 );
	$result = $gvl->download();
	assert_eq( $result['success'], true, 'download succeeds against the stubbed IAB response' );
	assert_eq(
		get_option( 'faz_gvl_selected_vendors' ),
		array( 1 ),
		'after the refresh only live vendors remain selected (deleted and vanished ids pruned)'
	);

	// An install that never selected anything must stay "never selected", so
	// the first-download auto-select in the GVL API still runs for it.
	unset( $GLOBALS['faz_test_options']['faz_gvl_selected_vendors'] );
	$gvl->download();
	assert_eq( array_key_exists( 'faz_gvl_selected_vendors', $GLOBALS['faz_test_options'] ), false, 'download never creates the selection option when it does not exist' );

	// ---------------------------------------------------------------------
	// Weekly cron, same list version: a vendor whose deletedDate has passed
	// since the last tick is pruned from the selection, and the selected
	// vendors travel in every page's _fazConfig. cron_update() purged only on
	// a version change, so the cached pages kept announcing the deleted vendor.
	// ---------------------------------------------------------------------
	echo "\nGvl::cron_update() when the pruning changes the selection\n";
	$GLOBALS['faz_test_options']['faz_settings']             = array( 'iab' => array( 'enabled' => true ) );
	$GLOBALS['faz_test_options'][ Gvl::META_KEY ]            = array( 'version' => 180 );
	$GLOBALS['faz_test_options']['faz_gvl_selected_vendors'] = array( 1, 68 );
	$GLOBALS['faz_test_actions']                             = array();
	$GLOBALS['faz_test_template_clears']                     = 0;
	Gvl::cron_update();
	assert_eq( get_option( 'faz_gvl_selected_vendors' ), array( 1 ), 'PRECONDITION: the deleted vendor is pruned on the same list version' );
	assert_eq( in_array( 'faz_purge_page_cache', $GLOBALS['faz_test_actions'], true ), true, 'the page caches are purged, so cached pages stop announcing it' );
	assert_eq( $GLOBALS['faz_test_template_clears'] > 0, true, 'and the stored banner template is dropped' );

	$GLOBALS['faz_test_actions']         = array();
	$GLOBALS['faz_test_template_clears'] = 0;
	Gvl::cron_update();
	assert_eq( in_array( 'faz_purge_page_cache', $GLOBALS['faz_test_actions'], true ), false, 'an unchanged selection on the same version purges nothing' );
	assert_eq( $GLOBALS['faz_test_template_clears'], 0, 'and leaves the banner template alone' );

	// Clean the temp upload dir.
	$dir = wp_upload_dir()['basedir'];
	if ( is_dir( $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $dir );
	}

	echo "\n" . ( $tests_failed > 0 ? "\033[31m" : "\033[32m" ) . "{$tests_passed}/{$tests_run} passed\033[0m\n";
	exit( $tests_failed > 0 ? 1 : 0 );
}
