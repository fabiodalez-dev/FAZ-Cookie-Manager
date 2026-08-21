<?php
/**
 * Behavioural regression tests for Cookies_API::bulk_delete() durability.
 *
 * The bug this pins was an ordering one, and ordering is invisible to any check
 * that reads the source for the presence of a snapshot: the snapshot WAS built,
 * the option WAS written, and every string a reviewer would grep for was there.
 * What was wrong is that $cookie->delete() ran inside the collecting loop while
 * update_option() ran after it, with its return value discarded. When the write
 * failed the rows were already gone, wp_options still held the PREVIOUS batch,
 * and the response still reported those rows as restorable — so the page drew
 * an Undo button that would put back a different set of cookies.
 *
 * So this suite drives the failure instead of asserting text. It stubs the two
 * collaborators and the WordPress functions, loads the production class
 * unmodified, and makes update_option() fail. What is asserted:
 *
 *   - a failed snapshot deletes nothing at all;
 *   - it does not disturb the batch already in the bin, so the Undo the page
 *     already offered still means what it said;
 *   - it reports the failure rather than a restorable count;
 *   - on the success path the write is ordered strictly BEFORE the first
 *     delete(), which is the property that makes the guarantee hold;
 *   - update_option()'s "value unchanged" false is not mistaken for a failure,
 *     which would abort perfectly safe purges.
 *
 * Also covers the deleted_at surfacing (get_deleted_batches) and the size guard.
 */

namespace FazCookie\Admin\Modules\Cookies\Api {
	/** Stand-in for the REST controller ancestry — nothing used here is inherited. */
	abstract class API_Controller {}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {

	/**
	 * Recording stand-in for the Cookie model.
	 *
	 * delete() appends to a global ordered log shared with update_option(), so
	 * "the snapshot was stored before anything was destroyed" is a checkable
	 * fact rather than an inference.
	 */
	class Cookie {
		/** @var int[] Ids whose delete() actually ran, in order. */
		public static $deleted = array();
		/** @var array<int,array> Rows the stub pretends the database holds. */
		public static $rows = array();

		/** @var int */
		private $id;

		public function __construct( $id = 0 ) {
			$this->id = (int) $id;
		}

		public static function reset( $rows ) {
			self::$deleted = array();
			self::$rows    = $rows;
		}

		public function get_loaded() {
			return isset( self::$rows[ $this->id ] );
		}

		public function get_name() {
			return isset( self::$rows[ $this->id ]['name'] ) ? self::$rows[ $this->id ]['name'] : '';
		}

		public function get_domain() {
			return isset( self::$rows[ $this->id ]['domain'] ) ? self::$rows[ $this->id ]['domain'] : '';
		}

		public function get_prepared_data() {
			return self::$rows[ $this->id ];
		}

		public function get_script_data() {
			return array(
				'opt_in_script'  => '',
				'opt_out_script' => '',
			);
		}

		public function delete() {
			self::$deleted[]        = $this->id;
			$GLOBALS['faz_order'][] = 'delete:' . $this->id;
			return true;
		}
	}

	/** Only referenced by restore_deleted(); present so class resolution never surprises us. */
	class Cookie_Controller {
		public static function get_instance() {
			return new self();
		}
		public function get_item_from_db() {
			return array();
		}
	}
}

namespace FazCookie\Admin\Modules\Scanner\Includes {

	/**
	 * Stand-in for the scanner controller.
	 *
	 * Only the reason=stale path touches it. The unscoped bulk delete must not,
	 * and a suite that never exercised the gate would not notice if it started.
	 */
	class Controller {
		/** @var string[] Keys the tally considers deletable. */
		public static $earned = array();

		public static function get_instance() {
			return new self();
		}

		public function deletable_stale_keys() {
			return self::$earned;
		}

		public static function canonical_key( $name, $domain ) {
			$name   = strtolower( trim( (string) $name ) );
			$domain = ltrim( strtolower( trim( (string) $domain ) ), '.' );
			return '' === $name ? '' : $name . '|' . $domain;
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
	}

	/** Minimal request: bulk_delete() reads exactly two params. */
	class Faz_Fake_Request {
		private $params;
		public function __construct( $params = array() ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
		}
	}

	$GLOBALS['faz_options'] = array();
	$GLOBALS['faz_order']   = array();
	/**
	 * How update_option() behaves.
	 *
	 * 'ok'      — stores and returns true.
	 * 'fail'    — stores NOTHING and returns false. This is the real shape of a
	 *             $wpdb write that did not land: WordPress bails before it
	 *             touches the object cache, so a read-back still sees the old
	 *             value. That is what makes the read-back check meaningful.
	 * 'silent'  — stores the value and returns false, i.e. the "value is already
	 *             identical" answer. Must NOT be read as a failure.
	 */
	$GLOBALS['faz_update_mode'] = 'ok';

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_options'] ) ? $GLOBALS['faz_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$mode = $GLOBALS['faz_update_mode'];
		if ( 'fail' === $mode ) {
			return false;
		}
		$GLOBALS['faz_options'][ $name ] = $value;
		$GLOBALS['faz_order'][]          = 'write';
		return 'silent' !== $mode;
	}
	function maybe_serialize( $value ) {
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
	}
	function human_time_diff( $from, $to = 0 ) {
		$diff = absint( $to - $from );
		return $diff . ' seconds';
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
	function current_user_can( $capability ) {
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
	use FazCookie\Admin\Modules\Scanner\Includes\Controller as Scanner_Controller;

	$passed = 0;
	$failed = 0;
	function bd_ok( $condition, $label ) {
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
	$api        = new Cookies_API();

	/** Two live rows the purge will target. */
	$rows = array(
		1 => array(
			'id'       => 1,
			'name'     => '_ga',
			'slug'     => '_ga',
			'domain'   => 'example.com',
			'category' => 'analytics',
		),
		2 => array(
			'id'       => 2,
			'name'     => '_gid',
			'slug'     => '_gid',
			'domain'   => 'example.com',
			'category' => 'analytics',
		),
	);

	/** A genuinely older purge, already sitting in the bin. */
	$older_batch = array(
		'deleted_at' => 1700000000,
		'cookies'    => array( array( 'id' => 99, 'name' => '_fbp', 'slug' => '_fbp' ) ),
	);

	echo "== A snapshot that cannot be written does not destroy anything ==\n";
	$GLOBALS['faz_options']     = array( $bin_option => array( $older_batch ) );
	$GLOBALS['faz_order']       = array();
	$GLOBALS['faz_update_mode'] = 'fail';
	Cookie::reset( $rows );
	$response = $api->bulk_delete( new Faz_Fake_Request( array( 'ids' => array( 1, 2 ) ) ) );

	bd_ok( array() === Cookie::$deleted, 'no row is deleted when the recycle-bin write fails' );
	bd_ok(
		$response instanceof WP_Error && 'faz_recycle_bin_write_failed' === $response->code,
		'the failure is reported as an error, not as a successful delete'
	);
	bd_ok(
		$response instanceof WP_Error
			&& 0 === $response->data['restorable']
			&& 0 === $response->data['deleted']
			&& true === $response->data['snapshot_failed'],
		'the response claims nothing restorable and flags the snapshot failure'
	);
	bd_ok(
		array( $older_batch ) === get_option( $bin_option, 'missing' ),
		'the previous batch is left untouched — the Undo already on screen still means what it said'
	);

	echo "== The snapshot is stored before the first row is destroyed ==\n";
	/*
	 * The ordering assertion, and the one that would have caught the original
	 * bug. Moving delete() back inside the collecting loop keeps every other
	 * check in this file green and turns this one red.
	 */
	$GLOBALS['faz_options']     = array( $bin_option => array( $older_batch ) );
	$GLOBALS['faz_order']       = array();
	$GLOBALS['faz_update_mode'] = 'ok';
	Cookie::reset( $rows );
	$response = $api->bulk_delete( new Faz_Fake_Request( array( 'ids' => array( 1, 2 ) ) ) );

	bd_ok(
		array( 'write', 'delete:1', 'delete:2' ) === $GLOBALS['faz_order'],
		'the bin is written first, then every row is deleted'
	);
	bd_ok(
		is_array( $response ) && 2 === $response['deleted'] && 2 === $response['restorable'],
		'a successful purge reports both rows deleted and both restorable'
	);
	$bin_after = get_option( $bin_option, 'missing' );
	bd_ok(
		is_array( $bin_after )
			&& 2 === count( $bin_after )
			&& 2 === count( $bin_after[0]['cookies'] )
			&& $bin_after[0]['deleted_at'] > 0
			&& $older_batch === $bin_after[1],
		'the new batch is stamped and unshifted ahead of the older one, which survives'
	);

	echo "== An unchanged value is not a failed write ==\n";
	/*
	 * update_option() answers false both for "the write failed" and for "the
	 * stored value was already identical". Reading the second as the first
	 * would abort purges that are entirely safe, so the production code
	 * resolves a false by reading the option back. Deleting that read-back
	 * turns this pair red while leaving the failure cases above green.
	 */
	$GLOBALS['faz_options']     = array( $bin_option => array() );
	$GLOBALS['faz_order']       = array();
	$GLOBALS['faz_update_mode'] = 'silent';
	Cookie::reset( $rows );
	$response = $api->bulk_delete( new Faz_Fake_Request( array( 'ids' => array( 1 ) ) ) );

	bd_ok( array( 1 ) === Cookie::$deleted, 'a write that landed but reported false still permits the delete' );
	bd_ok( is_array( $response ) && 1 === $response['restorable'], 'and it is reported as restorable' );

	echo "== The stale gate still refuses rows that have not earned deletion ==\n";
	/*
	 * Kept because the two-phase rewrite moved this gate's neighbourhood. A
	 * refusal must still cost nothing: no snapshot, no delete, no bin write.
	 */
	$GLOBALS['faz_options']     = array( $bin_option => array() );
	$GLOBALS['faz_order']       = array();
	$GLOBALS['faz_update_mode'] = 'ok';
	Scanner_Controller::$earned = array( '_ga|example.com' );
	Cookie::reset( $rows );
	$response = $api->bulk_delete(
		new Faz_Fake_Request(
			array(
				'ids'    => array( 1, 2 ),
				'reason' => 'stale',
			)
		)
	);

	bd_ok(
		array( 1 ) === Cookie::$deleted && is_array( $response ) && 1 === $response['refused'],
		'only the earned row is purged; the unearned one is refused, not deleted'
	);
	$bin_after = get_option( $bin_option, 'missing' );
	bd_ok(
		is_array( $bin_after ) && 1 === count( $bin_after[0]['cookies'] ) && '_ga' === $bin_after[0]['cookies'][0]['name'],
		'the snapshot holds only the row that was actually removed'
	);
	Scanner_Controller::$earned = array();

	echo "== A request that removes nothing writes no batch ==\n";
	$GLOBALS['faz_options'] = array( $bin_option => array( $older_batch ) );
	$GLOBALS['faz_order']   = array();
	Cookie::reset( array() );
	$response = $api->bulk_delete( new Faz_Fake_Request( array( 'ids' => array( 41, 42 ) ) ) );

	bd_ok(
		is_array( $response ) && 0 === $response['deleted'] && 0 === $response['restorable']
			&& array() === $GLOBALS['faz_order'],
		'ids that match no live row neither write nor delete'
	);

	echo "== An oversized bin sheds its oldest batches instead of failing the write ==\n";
	/*
	 * A single option row has a hard ceiling (max_allowed_packet). Without the
	 * guard a very large purge would push the bin past it, the write would
	 * fail, and — correctly, but unhelpfully — the whole purge would abort. The
	 * guard drops history, never the batch being created right now.
	 */
	$fat = array(
		'id'          => 3,
		'name'        => '_fat',
		'slug'        => '_fat',
		'domain'      => 'example.com',
		'description' => str_repeat( 'x', 1200000 ),
	);
	$GLOBALS['faz_options'] = array(
		$bin_option => array(
			array( 'deleted_at' => 1700000001, 'cookies' => array( $fat ) ),
			array( 'deleted_at' => 1700000000, 'cookies' => array( $fat ) ),
		),
	);
	$GLOBALS['faz_order']       = array();
	$GLOBALS['faz_update_mode'] = 'ok';
	Cookie::reset( array( 3 => $fat ) );
	$response  = $api->bulk_delete( new Faz_Fake_Request( array( 'ids' => array( 3 ) ) ) );
	$bin_after = get_option( $bin_option, 'missing' );

	bd_ok(
		is_array( $bin_after ) && 1 === count( $bin_after ) && $bin_after[0]['deleted_at'] > 1700000001,
		'the oldest batches are shed and the newest — the undo for this very purge — is kept'
	);
	bd_ok(
		strlen( serialize( $bin_after ) ) <= Cookies_API::RECYCLE_BIN_MAX_BYTES,
		'what is stored fits under the ceiling'
	);
	bd_ok( array( 3 ) === Cookie::$deleted, 'and the purge still went through' );
	unset( $fat );

	echo "== The age of a batch is reported, not just recorded ==\n";
	/*
	 * deleted_at was written from the first release and read by nothing, so the
	 * bar called an eight-month-old purge "recently deleted". Removing
	 * deleted_at_human from get_deleted_batches() turns the first two red.
	 */
	$GLOBALS['faz_options'] = array(
		$bin_option => array(
			array( 'deleted_at' => 1000, 'cookies' => array( array( 'name' => '_ga' ) ) ),
			array( 'deleted_at' => 0, 'cookies' => array( array( 'name' => '_gid' ) ) ),
			array( 'cookies' => array( array( 'name' => '_fbp' ) ) ),
		),
	);
	$batches = $api->get_deleted_batches( null );
	$rows_b  = $batches['batches'];

	bd_ok( 3 === count( $rows_b ), 'every batch is described' );
	bd_ok(
		isset( $rows_b[0]['deleted_at_human'] ) && '' !== $rows_b[0]['deleted_at_human']
			&& false !== strpos( $rows_b[0]['deleted_at_human'], 'seconds' ),
		'a timestamped batch reports a human-readable age derived from deleted_at'
	);
	bd_ok(
		'' === $rows_b[1]['deleted_at_human'] && '' === $rows_b[2]['deleted_at_human'],
		'a batch with no usable timestamp reports no age rather than a fabricated one'
	);
	bd_ok(
		1000 === $rows_b[0]['deleted_at'] && 1 === $rows_b[0]['count'],
		'the raw timestamp and count are still there for callers that want them'
	);

	echo "\n";
	if ( $failed > 0 ) {
		echo "FAIL: {$failed} failed, {$passed} passed\n";
		exit( 1 );
	}
	echo "ALL PASS: {$passed} passed\n";
	exit( 0 );
}
