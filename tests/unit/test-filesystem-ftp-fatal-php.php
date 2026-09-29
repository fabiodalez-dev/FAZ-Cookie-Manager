<?php
/**
 * Standalone unit tests for Filesystem's refusal to hand back a dead transport.
 *
 * Subsystem: filesystem-ftp-fatal-php
 *
 * WP_Filesystem() assigns $wp_filesystem BEFORE it connects and leaves the
 * object in place when the connection fails. Filesystem::get_filesystem()
 * discarded that return value, so on a host where the PHP user does not own the
 * files and the FTP extension is loaded — get_filesystem_method() picks
 * 'ftpext', our credentials are `true` rather than a host and password,
 * WP_Filesystem() returns false with `empty_hostname` — the first write reached
 * ftp_fput( null ). That is a TypeError, which is a fatal and not a falsy
 * return, so the caller's own fallback could never catch it, and every frontend
 * request needing a new config-*.js or banner-*.css answered 500 (#300).
 *
 * Pinned here:
 *   1. An unconnected transport is never returned: get_filesystem() gives null.
 *   2. put_contents() returns false instead of raising — this is the assertion
 *      the reported fatal would fail, because the double throws exactly as
 *      ftp_fput( null ) does.
 *   3. delete() and abspath() survive the same state; abspath() keeps its
 *      documented ABSPATH answer.
 *   4. get_contents() still reads, through the direct fallback it already had,
 *      so reading bundled JSON never depended on the transport.
 *   5. A healthy direct filesystem is still used and still writes — the guard
 *      must not disable the path it protects.
 *   6. A connected remote transport is refused by design: it cannot be told
 *      apart from one that only looks connected, and callers degrade to inline
 *      assets rather than risk a fatal per page view.
 *
 * Run: php tests/unit/test-filesystem-ftp-fatal-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	define( 'ABSPATH', sys_get_temp_dir() . '/faz-fs-abspath/' );

	class WP_Error_Double {
		public $errors = array();
		public function add( $code, $message = '' ) {
			$this->errors[ $code ][] = $message;
		}
	}

	class WP_Filesystem_Base {
		public $method = 'base';
		public $errors;
		public function __construct() {
			$this->errors = new WP_Error_Double();
		}
		public function abspath() {
			return ABSPATH;
		}
		public function exists( $f ) {
			return file_exists( $f );
		}
		public function is_readable( $f ) {
			return is_readable( $f );
		}
		public function get_contents( $f ) {
			return file_get_contents( $f ); // phpcs:ignore
		}
	}

	/** Behaves like ftp_fput( null ): raises instead of returning false. */
	class WP_Filesystem_FTPext_Double extends WP_Filesystem_Base {
		public $method = 'ftpext';
		public function put_contents( $file, $contents ) {
			throw new \TypeError( 'ftp_fput(): Argument #1 ($ftp) must be of type FTP\Connection, null given' );
		}
		public function delete( $file, $recursive = false, $type = false ) {
			throw new \TypeError( 'ftp_delete(): Argument #1 ($ftp) must be of type FTP\Connection, null given' );
		}
	}

	class WP_Filesystem_Direct_Double extends WP_Filesystem_Base {
		public $method  = 'direct';
		public $written = 0;
		public function put_contents( $file, $contents ) {
			$this->written++;
			return false !== file_put_contents( $file, $contents ); // phpcs:ignore
		}
		public function delete( $file, $recursive = false, $type = false ) {
			return @unlink( $file ); // phpcs:ignore
		}
	}

	// ---- WordPress function stubs -------------------------------------------
	function apply_filters( $hook, $value ) {
		return $value; }
	function add_filter() {
		return true; }
	function remove_filter() {
		return true; }
	function site_url() {
		return 'https://example.test'; }
	function request_filesystem_credentials() {
		return true; }
	function get_option( $name, $default = false ) {
		return $GLOBALS['__faz_options'][ $name ] ?? $default; }
	function update_option( $name, $value ) {
		$GLOBALS['__faz_options'][ $name ] = $value;
		return true; }
	function delete_option( $name ) {
		unset( $GLOBALS['__faz_options'][ $name ] );
		return true; }
	function is_ssl() {
		return true; }
	function wp_upload_dir() {
		return array(
			'basedir' => sys_get_temp_dir() . '/faz-fs-uploads',
			'baseurl' => 'https://example.test/uploads',
		); }

	/**
	 * Mirrors core: instantiate, then attempt the connection. The object stays
	 * assigned whatever the outcome — which is the whole trap.
	 */
	function WP_Filesystem( $args = false, $context = false ) {
		global $wp_filesystem;
		switch ( $GLOBALS['__faz_fs_mode'] ) {
			case 'ftp_broken':
				$wp_filesystem = new \WP_Filesystem_FTPext_Double();
				$wp_filesystem->errors->add( 'empty_hostname', 'FTP hostname is required' );
				return false;
			case 'ftp_connected':
				$wp_filesystem = new \WP_Filesystem_FTPext_Double();
				return true;
			case 'direct_ok':
			default:
				$wp_filesystem = new \WP_Filesystem_Direct_Double();
				return true;
		}
	}

	$GLOBALS['__faz_options'] = array();
	$GLOBALS['__faz_fs_mode'] = 'direct_ok';

	// get_filesystem() require_once's wp-admin/includes/file.php from ABSPATH.
	// Provide an empty one: the functions it would define are stubbed above, and
	// require_once must find a readable file for the method to proceed at all.
	@mkdir( ABSPATH . 'wp-admin/includes', 0777, true ); // phpcs:ignore
	if ( ! file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
		file_put_contents( ABSPATH . 'wp-admin/includes/file.php', "<?php\n// stub\n" ); // phpcs:ignore
	}

	require_once __DIR__ . '/../../includes/class-filesystem.php';

	$passed = 0;
	$failed = 0;
	function check( $label, $cond ) {
		global $passed, $failed;
		if ( $cond ) {
			$passed++;
			return;
		}
		$failed++;
		echo "  FAIL: {$label}\n";
	}
	function reset_fs( $mode ) {
		$GLOBALS['__faz_fs_mode'] = $mode;
		$GLOBALS['wp_filesystem'] = null;
	}

	$fs  = \FazCookie\Includes\Filesystem::get_instance();
	$tmp = sys_get_temp_dir() . '/faz-fs-probe-' . getmypid() . '.txt';

	// ---- 1-3. the reported state: assigned but never connected --------------
	reset_fs( 'ftp_broken' );
	check( '1. get_filesystem() refuses an unconnected transport', null === $fs->get_filesystem() );

	reset_fs( 'ftp_broken' );
	$raised = false;
	$result = null;
	try {
		$result = $fs->put_contents( $tmp, 'payload' );
	} catch ( \Throwable $e ) {
		$raised = true;
	}
	check( '2. put_contents() does not raise (the reported fatal)', ! $raised );
	check( '2b. put_contents() reports failure so the caller can fall back', false === $result );
	check( '2c. nothing was written', ! file_exists( $tmp ) );

	reset_fs( 'ftp_broken' );
	$raised = false;
	try {
		$fs->delete( $tmp );
	} catch ( \Throwable $e ) {
		$raised = true;
	}
	check( '3. delete() does not raise', ! $raised );

	reset_fs( 'ftp_broken' );
	$raised = false;
	$abs    = null;
	try {
		$abs = $fs->abspath();
	} catch ( \Throwable $e ) {
		$raised = true;
	}
	check( '3b. abspath() does not raise', ! $raised );
	check( '3c. abspath() still answers ABSPATH', ABSPATH === $abs );

	// ---- 4. reading must survive the same state ----------------------------
	file_put_contents( $tmp, '{"ok":true}' ); // phpcs:ignore
	reset_fs( 'ftp_broken' );
	check( '4. get_contents() still reads via the direct fallback', '{"ok":true}' === $fs->get_contents( $tmp ) );
	@unlink( $tmp ); // phpcs:ignore

	// ---- 5. the happy path must keep working -------------------------------
	reset_fs( 'direct_ok' );
	$direct = $fs->get_filesystem();
	check( '5. a healthy direct filesystem is returned', $direct instanceof \WP_Filesystem_Direct_Double );
	check( '5b. put_contents() writes through it', true === $fs->put_contents( $tmp, 'written' ) );
	check( '5c. the bytes are on disk', file_exists( $tmp ) && 'written' === file_get_contents( $tmp ) );
	@unlink( $tmp ); // phpcs:ignore

	// ---- 6. a connected remote transport is refused by design --------------
	reset_fs( 'ftp_connected' );
	check( '6. a connected remote transport is still refused', null === $fs->get_filesystem() );
	reset_fs( 'ftp_connected' );
	$raised = false;
	try {
		check( '6b. and its writes report failure instead of raising', false === $fs->put_contents( $tmp, 'x' ) );
	} catch ( \Throwable $e ) {
		$raised = true;
	}
	check( '6c. no raise on a connected remote transport either', ! $raised );
	@unlink( $tmp ); // phpcs:ignore

	@unlink( ABSPATH . 'wp-admin/includes/file.php' ); // phpcs:ignore
	@rmdir( ABSPATH . 'wp-admin/includes' ); // phpcs:ignore
	@rmdir( ABSPATH . 'wp-admin' ); // phpcs:ignore
	@rmdir( ABSPATH ); // phpcs:ignore

	echo "\n";
	if ( 0 === $failed ) {
		echo "ALL PASS ({$passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$failed}, passed: {$passed}\n";
	exit( 1 );
}
