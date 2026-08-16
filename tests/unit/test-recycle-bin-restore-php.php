<?php
/**
 * Behavioural regression tests for Cookies_API::restore_deleted().
 *
 * test-scan-deletion-safety-php.php pins the SHAPE of the restore path by
 * containment; those checks go red if the guards are edited away. They cannot
 * go red if the guards survive as text but stop meaning anything — a refactor
 * that keeps the allowlist and reintroduces a settable identity by some other
 * route reads fine and restores nothing.
 *
 * So this suite runs the real method. It stubs the WordPress functions and the
 * two collaborators (Cookie, Cookie_Controller), then loads the production
 * class-cookies-api.php unmodified and calls restore_deleted() against a
 * snapshot deliberately carrying BOTH identity keys get_prepared_data() and the
 * database row could plausibly contribute — 'id' and 'cookie_id'. What is
 * asserted is what the fix promised:
 *
 *   - no identity setter is ever invoked, whatever the snapshot holds;
 *   - get_id() is still 0 at the moment save() is called, so the write is an
 *     INSERT and cannot land on some unrelated live row;
 *   - a save() that wrote nothing does not increment the counter, and leaves
 *     the recycle bin intact so the retry still has something to restore.
 *
 * The last one is the one that turns a bug into data loss, and it is invisible
 * to any check that only reads the source.
 */

namespace FazCookie\Admin\Modules\Cookies\Api {
	/**
	 * Stand-in for the REST controller ancestry.
	 *
	 * restore_deleted() inherits nothing it uses, so an empty parent keeps the
	 * WP_REST_Controller chain (and all of WordPress) out of this suite.
	 */
	abstract class API_Controller {}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {

	/**
	 * Recording stand-in for the Cookie model.
	 *
	 * The setter surface mirrors the real one on purpose: Store provides
	 * set_id/set_name/set_slug/set_description and Cookie provides the rest.
	 * The production loop gates every call on method_exists(), so a stub that
	 * answered through __call() alone would make the whole restore loop skip
	 * silently and every assertion here pass for the wrong reason. Each
	 * allowlisted setter is therefore a real declared method, and __call() is
	 * kept only as a recorder for a setter name that does not exist yet — a
	 * future rename shows up in the log instead of fataling.
	 */
	class Cookie {
		/** @var string[] Setter names invoked across the whole run. */
		public static $calls = array();
		/** @var int[] Value of get_id() at each save() call. */
		public static $id_at_save = array();
		/** @var int What the next save() reports back as the written row id. */
		public static $next_save_id = 0;

		/** @var int Identity, exactly as Store models it: 0 until set. */
		private $id = 0;

		public static function reset( $next_save_id ) {
			self::$calls        = array();
			self::$id_at_save   = array();
			self::$next_save_id = $next_save_id;
		}

		/**
		 * Store::set_id(). Present so a blind dispatch would actually reach it.
		 */
		public function set_id( $id ) {
			self::$calls[] = 'set_id';
			$this->id      = (int) $id;
		}

		public function get_id() {
			return $this->id;
		}

		/**
		 * Store::save() — creates when the id is falsy, updates otherwise, and
		 * returns get_id() either way. The stub reproduces the only property
		 * the caller may rely on: a row that was not written leaves the id at 0.
		 */
		public function save() {
			self::$calls[]      = 'save';
			self::$id_at_save[] = $this->get_id();
			if ( self::$next_save_id ) {
				$this->id = self::$next_save_id;
			}
			return $this->get_id();
		}

		public function set_name( $value ) {
			self::$calls[] = 'set_name'; }
		public function set_slug( $value ) {
			self::$calls[] = 'set_slug'; }
		public function set_description( $value ) {
			self::$calls[] = 'set_description'; }
		public function set_duration( $value ) {
			self::$calls[] = 'set_duration'; }
		public function set_type( $value ) {
			self::$calls[] = 'set_type'; }
		public function set_domain( $value ) {
			self::$calls[] = 'set_domain'; }
		public function set_discovered( $value ) {
			self::$calls[] = 'set_discovered'; }
		public function set_url_pattern( $value ) {
			self::$calls[] = 'set_url_pattern'; }
		public function set_category( $value ) {
			self::$calls[] = 'set_category'; }
		public function set_transfer( $value ) {
			self::$calls[] = 'set_transfer'; }
		public function set_opt_in_script( $value ) {
			self::$calls[] = 'set_opt_in_script'; }
		public function set_opt_out_script( $value ) {
			self::$calls[] = 'set_opt_out_script'; }

		/** Catch-all recorder for a setter the model does not declare. */
		public function __call( $name, $arguments ) {
			self::$calls[] = $name;
		}
	}

	/**
	 * Stand-in for the controller the restore consults for duplicates.
	 *
	 * An empty current set is the interesting case: nothing is skipped as an
	 * already-present name, so every snapshot row reaches the restore loop.
	 */
	class Cookie_Controller {
		public static function get_instance() {
			return new self();
		}
		public function get_item_from_db() {
			return array();
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	class WP_Error {
		public $code;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code = $code;
		}
	}

	$GLOBALS['faz_options']       = array();
	$GLOBALS['faz_option_writes'] = array();

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_options'] ) ? $GLOBALS['faz_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_options'][ $name ] = $value;
		$GLOBALS['faz_option_writes'][]  = $name;
		return true;
	}
	function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {}
	function do_action( $hook ) {}
	function rest_ensure_response( $response ) {
		return $response;
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function esc_html__( $text, $domain = null ) {
		return $text;
	}

	require_once dirname( __DIR__, 2 ) . '/admin/modules/cookies/api/class-cookies-api.php';

	use FazCookie\Admin\Modules\Cookies\Api\Cookies_API;
	use FazCookie\Admin\Modules\Cookies\Includes\Cookie;

	$passed = 0;
	$failed = 0;
	function rb_ok( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) {
			$passed++;
			echo "  [PASS] {$label}\n";
			return;
		}
		$failed++;
		echo "  [FAIL] {$label}\n";
	}

	$bin_option = Cookies_API::RECYCLE_BIN_OPTION;

	/**
	 * A snapshot as get_prepared_data() + get_script_data() would leave it,
	 * plus both identity keys. 'id' is the one get_prepared_data() really
	 * emits; 'cookie_id' is the name the database row uses, and is included so
	 * that closing only the 'id' hole would not be enough to pass.
	 */
	$snapshot = array(
		'id'             => 42,
		'cookie_id'      => 42,
		'name'           => '_ga',
		'slug'           => '_ga',
		'description'    => array( 'en' => 'Google Analytics.' ),
		'duration'       => array( 'en' => '2 years' ),
		'type'           => 'HTTP',
		'domain'         => 'example.com',
		'discovered'     => true,
		'url_pattern'    => '',
		'category'       => 'analytics',
		'transfer'       => array(),
		'opt_in_script'  => '',
		'opt_out_script' => '',
	);
	$batch = array(
		'deleted_at' => 1700000000,
		'cookies'    => array( $snapshot ),
	);

	$api = new Cookies_API();

	echo "== A snapshot cannot choose the identity of the row it restores ==\n";
	$GLOBALS['faz_options']       = array( $bin_option => array( $batch ) );
	$GLOBALS['faz_option_writes'] = array();
	Cookie::reset( 7 );
	$response = $api->restore_deleted( null );

	// Not vacuous: if the loop had skipped this row (a duplicate-name match, a
	// stub whose setters method_exists() cannot see), none of the twelve would
	// be here and every other assertion below would pass for the wrong reason.
	rb_ok(
		in_array( 'set_name', Cookie::$calls, true )
			&& in_array( 'set_category', Cookie::$calls, true )
			&& in_array( 'set_opt_out_script', Cookie::$calls, true )
			&& 12 === count( array_diff( Cookie::$calls, array( 'save' ) ) ),
		'the restore loop actually ran and replayed all twelve allowlisted fields'
	);
	rb_ok( ! in_array( 'set_id', Cookie::$calls, true ), "the snapshot's 'id' never reaches set_id()" );
	rb_ok( ! in_array( 'set_cookie_id', Cookie::$calls, true ), "the snapshot's 'cookie_id' never reaches a setter either" );
	rb_ok( array( 0 ) === Cookie::$id_at_save, 'get_id() was still 0 when save() was called — the write is an INSERT' );
	rb_ok( is_array( $response ) && 1 === $response['restored'], 'a row that was written counts as restored' );
	rb_ok( array() === get_option( $bin_option, 'missing' ), 'a successful restore consumes the batch' );

	echo "== A restore that wrote nothing keeps the undo ==\n";
	$GLOBALS['faz_options']       = array( $bin_option => array( $batch ) );
	$GLOBALS['faz_option_writes'] = array();
	Cookie::reset( 0 );
	$response = $api->restore_deleted( null );

	rb_ok( in_array( 'save', Cookie::$calls, true ), 'the row was offered to save() — the failure is at the write, not before it' );
	rb_ok( is_array( $response ) && 0 === $response['restored'], 'save() returning 0 is not counted as a restore' );
	rb_ok( ! in_array( $bin_option, $GLOBALS['faz_option_writes'], true ), 'the recycle bin is not written at all when nothing was restored' );
	rb_ok( array( $batch ) === get_option( $bin_option, 'missing' ), 'the batch survives the failed restore, so the retry still has something to put back' );

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
