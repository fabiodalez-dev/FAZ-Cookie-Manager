<?php
/**
 * Unit test — migrations must bust the frontend caches when they finish.
 *
 * CLI::define_public_hooks() registers one cache-bust closure on seven hooks
 * (faz_after_{update,create,delete}_cookie, faz_after_{update,delete}_cookie_category,
 * faz_after_update_settings, faz_clear_cache). It deletes, among others,
 * faz_server_cookie_category_map_v2 — the one-hour classifier that authorises
 * SERVER-SIDE cookie destruction. Every controller write path fires one of the
 * seven.
 *
 * Migrations are the one class of write that structurally cannot. They reach
 * wp_faz_cookie_categories and wp_faz_cookies with direct $wpdb->update /
 * ->delete calls, by design, because they run before and around the controller
 * layer. rename_advertisement_to_marketing() is the concrete case: it rewrites
 * the category slug and reassigns every cookie's category without firing a
 * single hook, so on an install still carrying the pre-2026-03 `advertisement`
 * slug the classifier could keep answering "advertisement" for up to an hour
 * while the blocked-category list already said "marketing" — and the cookie was
 * allowed through. (Fail-permissive, so the direction is under-enforcement, not
 * over-deletion; still wrong, and still silent.)
 *
 * The fix fires once for the whole batch rather than inside that one migration,
 * so it covers every migration added later by default. That is what this file
 * pins — including the two cases where it must NOT fire.
 *
 * Self-contained: WordPress and the controller layer are stubbed, so this runs
 * under a bare `php`.
 */

namespace {
	/**
	 * Permissive stand-in for the admin controller layer.
	 *
	 * The migration batch is driven FOR REAL — all fourteen, in order — so this
	 * test observes the function under test rather than a paraphrase of it.
	 * Everything the migrations reach for that is not the subject of the test
	 * answers with an empty result: "nothing to migrate here", which every
	 * migration is required to handle.
	 */
	class Faz_Mig_Stub {
		public function __call( $name, $args ) { return array(); }
		public static function __callStatic( $name, $args ) { return array(); }
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	/**
	 * Declared explicitly rather than auto-stubbed, because this is the one the
	 * throwing case detonates: rename_advertisement_to_marketing() reads the
	 * category catalogue through it.
	 */
	class Category_Controller extends \Faz_Mig_Stub {
		public static function get_instance() {
			if ( '' !== $GLOBALS['faz_mig_boom'] ) {
				throw new \RuntimeException( $GLOBALS['faz_mig_boom'] );
			}
			return new self();
		}
	}
}

namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['faz_mig_options'] = array();
	$GLOBALS['faz_mig_actions'] = array();
	// Set to a message to make the first migration blow up, which is how the
	// "a batch that threw commits nothing" case is staged.
	$GLOBALS['faz_mig_boom'] = '';

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_mig_options'] )
			? $GLOBALS['faz_mig_options'][ $name ]
			: $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_mig_options'][ $name ] = $value;
		return true;
	}
	function add_option( $name, $value, $deprecated = '', $autoload = null ) {
		$GLOBALS['faz_mig_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['faz_mig_options'][ $name ] );
		return true;
	}
	function do_action( $tag ) {
		$GLOBALS['faz_mig_actions'][] = $tag;
	}
	function apply_filters( $tag, $value ) { return $value; }
	function wp_cache_delete( $key, $group = '' ) { return true; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function esc_sql( $value ) { return $value; }
	function wp_set_option_autoload_values( $values ) { return array(); }

	/*
	 * Anything else the batch reaches for gets a permissive stand-in on demand.
	 *
	 * eval() here is safe and is the only way to do this: the class name comes
	 * from PHP's own autoload callback (a name the migration code literally
	 * wrote, never user or network input), the template is a fixed string with
	 * no interpolated data, and the whole file is a CLI test runner that is
	 * never shipped in the plugin ZIP. The alternative — hard-coding every
	 * controller the fourteen migrations touch — makes the suite go red the day
	 * migration fifteen names a new one, which is the failure mode this test
	 * exists to avoid.
	 */
	spl_autoload_register(
		function ( $class ) {
			$pos       = strrpos( $class, '\\' );
			$namespace = substr( $class, 0, $pos );
			$short     = substr( $class, $pos + 1 );
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- see the note above: fixed template, autoloader-supplied name, test-only file.
			eval( "namespace {$namespace}; class {$short} extends \\Faz_Mig_Stub { public static function get_instance() { return new self(); } }" );
		}
	);

	class Faz_Mig_Wpdb {
		public $prefix     = 'wp_';
		public $options    = 'wp_options';
		public $last_error = '';
		public function __call( $name, $args ) { return array(); }
		public function get_var( $q = null ) { return null; }
		public function get_row( $q = null, $o = null ) { return null; }
		public function get_col( $q = null ) { return array(); }
		public function get_results( $q = null, $o = null ) { return array(); }
		public function query( $q ) { return 0; }
		public function prepare( $q ) { return $q; }
		public function update( $t, $d, $w, $f = null, $wf = null ) { return 1; }
		public function delete( $t, $w, $f = null ) { return 1; }
		public function insert( $t, $d, $f = null ) { return 1; }
		public function esc_like( $v ) { return $v; }
		public function get_charset_collate() { return ''; }
	}
	$GLOBALS['wpdb'] = new Faz_Mig_Wpdb();

	require_once dirname( __DIR__, 2 ) . '/includes/class-activator.php';

	use FazCookie\Includes\Activator;

	$run    = 0;
	$failed = 0;
	function mig_check( $condition, $label ) {
		global $run, $failed;
		++$run;
		if ( $condition ) {
			echo 'PASS ' . $run . ': ' . $label . "\n";
			return;
		}
		++$failed;
		echo 'FAIL ' . $run . ': ' . $label . "\n";
	}

	/**
	 * @param array  $options Starting option table.
	 * @param string $boom    Non-empty to make the first migration throw.
	 * @return array Actions fired during the batch.
	 */
	function mig_run( $options = array(), $boom = '' ) {
		// seed_own_consent_cookie() reaches into the scanner module tree, which
		// this harness does not load — no autoloader is registered here, so
		// class_exists() is false and the step correctly declines, which now
		// withholds faz_migrations_version and would make every assertion below
		// read as "the batch did not complete". That is a property of the
		// harness, not of the code under test: on a real admin request the
		// autoloader resolves both classes. Start from an install where that
		// step is already done, so this file keeps testing what it is about —
		// the cache bust. The declined-step behaviour itself is covered in
		// test-geo-enforcement-migration-php.php.
		if ( ! array_key_exists( 'faz_own_cookie_seeded', $options ) ) {
			$options['faz_own_cookie_seeded'] = '1';
		}
		$GLOBALS['faz_mig_options'] = $options;
		$GLOBALS['faz_mig_actions'] = array();
		$GLOBALS['faz_mig_boom']    = $boom;
		// The migrations warn on stub shapes they were never meant to see
		// (get_col() answering an array where a scalar is expected, and so on).
		// Those warnings are an artefact of the harness, not of the code under
		// test, and the assertions below do not depend on them.
		$previous = error_reporting( E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED );
		Activator::run_pending_migrations();
		error_reporting( $previous );
		$GLOBALS['faz_mig_boom'] = '';
		return $GLOBALS['faz_mig_actions'];
	}

	/* ── 1. The batch completes → the caches are busted ───────────────────── */

	$fired = mig_run();

	// Precondition, asserted first and named as such: if this is red the batch
	// did not finish and every assertion after it is meaningless. The likely
	// cause is a NEWLY ADDED migration reaching for a function this harness does
	// not stub — add the stub above rather than deleting the test.
	mig_check(
		Activator::MIGRATIONS_VERSION === get_option( 'faz_migrations_version' ),
		'PRECONDITION: the full migration batch ran to completion under the stub harness'
	);

	mig_check(
		in_array( 'faz_clear_cache', $fired, true ),
		'a completed migration batch fires faz_clear_cache, the hook the frontend cache-bust closure listens on'
	);
	mig_check(
		1 === count( array_keys( $fired, 'faz_clear_cache', true ) ),
		'it fires exactly once for the whole batch, not once per migration'
	);

	/* ── 2. Already migrated → nothing fires ──────────────────────────────── */

	// The consolidated version flag exists to make the common admin_init request
	// a single get_option(). Busting on that path would delete the classifier
	// map on every admin page load, which is a cache that no longer caches.
	$already = mig_run( array( 'faz_migrations_version' => Activator::MIGRATIONS_VERSION ) );
	mig_check(
		empty( $already ),
		'an already-migrated site fires nothing — the early-return fast path is preserved'
	);

	/* ── 3. The batch threw → nothing committed, nothing busted ───────────── */

	$threw = mig_run( array(), 'category catalogue unavailable' );
	mig_check(
		false === get_option( 'faz_migrations_version' ),
		'a batch that threw leaves the version flag alone so it retries'
	);
	// This assertion used to read the other way — "a batch that threw does not
	// bust the caches, nothing was committed to invalidate" — and it pinned a
	// defect. The migrations are plain $wpdb writes under autocommit with no
	// surrounding transaction, and each sets its own completion flag, so they
	// commit incrementally: a throw in a LATER migration leaves an EARLIER one
	// committed. On that path the old placement skipped the bust, leaving
	// faz_server_cookie_category_map_v2 answering with the pre-migration slug
	// for up to an hour while the blocked-category list already used the new
	// one. The bust now lives in a `finally`, so it fires on both paths.
	mig_check(
		in_array( 'faz_clear_cache', $threw, true ),
		'a batch that threw STILL busts the caches — migrations commit incrementally, so an earlier one may have landed'
	);

	/* ── 4. The hook name is the one actually listened on ─────────────────── */

	// The fix is worthless if the string drifts from the closure's registration
	// list, and the two files never reference each other.
	$cli_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cli.php' );
	mig_check(
		false !== strpos( $cli_source, "'faz_clear_cache'" ),
		'faz_clear_cache is still one of the hooks CLI::define_public_hooks() registers the bust on'
	);
	mig_check(
		false !== strpos( $cli_source, 'faz_server_cookie_category_map_v2' ),
		'that closure still deletes the server-cookie classifier map, which is what the stale-slug window exposed'
	);

	echo $run . ' checks, ' . $failed . " failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
