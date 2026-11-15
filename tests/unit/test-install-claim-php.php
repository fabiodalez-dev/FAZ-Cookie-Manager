<?php
/**
 * The lazy upgrade runs in one request at a time, and visitors do not drive
 * the migration batch.
 *
 * check_version() runs install() on `init` for every request until
 * faz_version is bumped on install()'s last line. After a release every
 * concurrent request, anonymous ones included, re-ran the whole install()
 * (dbDelta probes, migrations, cache rotations, a page-cache purge across
 * every supported cache plugin); with a step that kept throwing, every request
 * repeated it. Activator::install_under_claim() takes an add_option() claim,
 * taken over by age with a compare-and-delete, and keeps it after a failure as
 * a back-off.
 *
 * run_pending_migrations() was hooked on admin_init, which also fires for
 * anonymous admin-ajax.php / admin-post.php requests; while a step declined
 * each of them re-ran the batch and its faz_clear_cache. The admin_init entry
 * now needs a logged-in user, and cron (a one-off event per upgrade, plus the
 * daily event) runs the batch where nobody opens wp-admin.
 *
 * Run: php tests/unit/test-install-claim-php.php
 */

namespace {
	class Faz_Claim_Stub {
		public function __call( $name, $args ) { return array(); }
		public static function __callStatic( $name, $args ) { return array(); }
		public static function get_instance() { return new static(); }
	}

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'FAZ_VERSION', '2.0.0' );

	$GLOBALS['faz_claim_options'] = array();
	$GLOBALS['faz_claim_actions'] = array();
	$GLOBALS['faz_claim_hooks']   = array();
	$GLOBALS['faz_claim_cron']    = array();
	$GLOBALS['faz_claim_logged']  = false;
	$GLOBALS['faz_claim_now']     = 1000000;

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_claim_options'] ) ? $GLOBALS['faz_claim_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_claim_options'][ $name ] = $value;
		return true;
	}
	function add_option( $name, $value, $deprecated = '', $autoload = null ) {
		if ( array_key_exists( $name, $GLOBALS['faz_claim_options'] ) ) {
			return false;
		}
		$GLOBALS['faz_claim_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['faz_claim_options'][ $name ] );
		return true;
	}
	function do_action( $tag ) { $GLOBALS['faz_claim_actions'][] = $tag; }
	function add_action( $tag, $callback ) { $GLOBALS['faz_claim_hooks'][] = array( $tag, $callback ); return true; }
	function add_filter() { return true; }
	function apply_filters( $tag, $value ) { return $value; }
	function wp_cache_delete( $key, $group = '' ) { return true; }
	function is_user_logged_in() { return $GLOBALS['faz_claim_logged']; }
	function is_admin() { return false; }
	function wp_doing_cron() { return false; }
	function wp_next_scheduled( $hook ) { return isset( $GLOBALS['faz_claim_cron'][ $hook ] ) ? $GLOBALS['faz_claim_cron'][ $hook ] : false; }
	function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['faz_claim_cron'][ $hook ] = $ts; return true; }
	function faz_clear_banner_template_cache() { return true; }

	spl_autoload_register(
		function ( $class ) {
			$pos = strrpos( $class, '\\' );
			if ( false === $pos ) {
				return;
			}
			$namespace = substr( $class, 0, $pos );
			$short     = substr( $class, $pos + 1 );
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- fixed template, autoloader-supplied name, test-only file.
			eval( "namespace {$namespace}; class {$short} extends \\Faz_Claim_Stub {}" );
		}
	);

	/** Options-table double: compare-and-delete and uncached reads. */
	class Faz_Claim_Wpdb {
		public $prefix  = 'wp_';
		public $options = 'wp_options';
		public function __call( $name, $args ) { return array(); }
		public function prepare( $query, ...$args ) { return array( $query, $args ); }
		public function get_var( $prepared = null ) {
			if ( is_array( $prepared ) && isset( $prepared[1][0] ) && array_key_exists( $prepared[1][0], $GLOBALS['faz_claim_options'] ) ) {
				return (string) $GLOBALS['faz_claim_options'][ $prepared[1][0] ];
			}
			return null;
		}
		public function delete( $table, $where, $format = null ) {
			$name = $where['option_name'];
			if ( ! array_key_exists( $name, $GLOBALS['faz_claim_options'] ) ) {
				return 0;
			}
			if ( isset( $where['option_value'] ) && (string) $GLOBALS['faz_claim_options'][ $name ] !== $where['option_value'] ) {
				return 0;
			}
			unset( $GLOBALS['faz_claim_options'][ $name ] );
			return 1;
		}
		public function esc_like( $v ) { return $v; }
	}
	$GLOBALS['wpdb'] = new Faz_Claim_Wpdb();
}

namespace FazCookie\Includes {
	// time() inside the Activator's namespace resolves here first.
	function time() { return $GLOBALS['faz_claim_now']; }
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/class-activator.php';

	use FazCookie\Includes\Activator;

	$pass = 0;
	$fail = 0;
	function claim_ok( $cond, $label ) {
		global $pass, $fail;
		if ( $cond ) { ++$pass; echo "  [PASS] $label\n"; } else { ++$fail; echo "  [FAIL] $label\n"; }
	}
	function claim_run( $installer ) {
		$m = new ReflectionMethod( Activator::class, 'install_under_claim' );
		$m->setAccessible( true );
		return $m->invoke( null, $installer );
	}
	function claim_reset() {
		$GLOBALS['faz_claim_options'] = array( 'faz_version' => '1.0.0' );
		$GLOBALS['faz_claim_actions'] = array();
		$GLOBALS['faz_claim_cron']    = array();
		$GLOBALS['faz_claim_now']     = 1000000;
	}

	echo "\n== Install claim ==\n\n";

	$runs    = 0;
	$install = function () use ( &$runs ) {
		++$runs;
		$GLOBALS['faz_claim_options']['faz_version'] = FAZ_VERSION;
	};

	// 1. The first request runs the upgrade and releases the claim.
	claim_reset();
	claim_ok( true === claim_run( $install ) && 1 === $runs, '01 the first request runs install()' );
	claim_ok( ! array_key_exists( Activator::INSTALL_CLAIM, $GLOBALS['faz_claim_options'] ), '01b the claim is released on success' );

	// 2. A concurrent request does not run it while the claim is held.
	claim_reset();
	$runs = 0;
	$GLOBALS['faz_claim_options'][ Activator::INSTALL_CLAIM ] = ( $GLOBALS['faz_claim_now'] - 10 ) . ':' . FAZ_VERSION;
	claim_ok( false === claim_run( $install ) && 0 === $runs, '02 a request that finds a live claim does not run install()' );

	// 3. A request whose option copy predates another's finished upgrade does not re-run it.
	claim_reset();
	$runs = 0;
	$GLOBALS['faz_claim_options']['faz_version'] = FAZ_VERSION; // Bumped in the DB by another request.
	claim_ok( false === claim_run( $install ) && 0 === $runs, '03 a finished upgrade is not run again from a stale option copy' );
	claim_ok( ! array_key_exists( Activator::INSTALL_CLAIM, $GLOBALS['faz_claim_options'] ), '03b and the claim is released' );

	// 4. A failing upgrade keeps the claim: the next requests back off.
	claim_reset();
	$runs  = 0;
	$throw = function () use ( &$runs ) {
		++$runs;
		throw new RuntimeException( 'step failed' );
	};
	$threw = false;
	try {
		claim_run( $throw );
	} catch ( RuntimeException $e ) {
		$threw = true;
	}
	claim_ok( $threw && 1 === $runs, '04 a failing install() still surfaces its error' );
	for ( $i = 0; $i < 5; $i++ ) {
		$GLOBALS['faz_claim_now'] += 30;
		try {
			claim_run( $throw );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
	}
	claim_ok( 1 === $runs, '04b the next requests inside the window do not retry it' );

	// 5. After the window the claim is taken over and the upgrade retried.
	$GLOBALS['faz_claim_now'] += Activator::INSTALL_CLAIM_TTL;
	claim_ok( true === claim_run( $install ) && 2 === $runs, '05 after the window the upgrade is retried' );

	// 6. A claim left by another plugin version is stale at once.
	claim_reset();
	$runs = 0;
	$GLOBALS['faz_claim_options'][ Activator::INSTALL_CLAIM ] = $GLOBALS['faz_claim_now'] . ':1.9.9';
	claim_ok( true === claim_run( $install ) && 1 === $runs, '06 a claim written by another release does not hold back this one' );

	// 7. check_version() goes through the claim.
	$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-activator.php' );
	claim_ok( 1 === preg_match( '/function check_version\(\)\s*\{[^}]*install_under_claim\(/s', $src ), '07 check_version() runs install() under the claim' );

	echo "\n== Migration triggers ==\n\n";

	// 8. Anonymous admin_init (admin-ajax nopriv) does not run the batch.
	claim_reset();
	$GLOBALS['faz_claim_logged'] = false;
	Activator::run_pending_migrations_on_admin_init();
	claim_ok( ! in_array( 'faz_clear_cache', $GLOBALS['faz_claim_actions'], true ), '08 an anonymous admin_init does not run the batch or its cache clear' );

	// 9. A logged-in admin_init does.
	claim_reset();
	$GLOBALS['faz_claim_logged'] = true;
	Activator::run_pending_migrations_on_admin_init();
	claim_ok( in_array( 'faz_clear_cache', $GLOBALS['faz_claim_actions'], true ), '09 a logged-in admin_init runs the batch' );

	// 10. Cron runs it where nobody opens wp-admin.
	$GLOBALS['faz_claim_hooks'] = array();
	Activator::init();
	$hooked = function ( $tag, $method ) {
		foreach ( $GLOBALS['faz_claim_hooks'] as $hook ) {
			if ( $tag === $hook[0] && is_array( $hook[1] ) && $method === $hook[1][1] ) {
				return true;
			}
		}
		return false;
	};
	claim_ok( $hooked( 'admin_init', 'run_pending_migrations_on_admin_init' ) && ! $hooked( 'admin_init', 'run_pending_migrations' ), '10 admin_init goes through the logged-in gate' );
	claim_ok( $hooked( Activator::MIGRATIONS_HOOK, 'run_pending_migrations' ), '10b the one-off cron event runs the batch' );
	claim_ok( $hooked( 'faz_daily_cleanup', 'run_pending_migrations' ), '10c the daily event retries it' );

	// 11. An upgrade schedules the one-off event when migrations are pending.
	claim_reset();
	$m = new ReflectionMethod( Activator::class, 'schedule_pending_migrations' );
	$m->setAccessible( true );
	$m->invoke( null );
	claim_ok( isset( $GLOBALS['faz_claim_cron'][ Activator::MIGRATIONS_HOOK ] ), '11 pending migrations are scheduled on cron' );
	claim_reset();
	$GLOBALS['faz_claim_options']['faz_migrations_version'] = Activator::MIGRATIONS_VERSION;
	$m->invoke( null );
	claim_ok( empty( $GLOBALS['faz_claim_cron'] ), '11b nothing is scheduled when they are done' );
	claim_ok( 1 === preg_match( '/function install\(\).*?self::schedule_pending_migrations\(\);.*?update_option\( \'faz_version\'/s', $src ), '11c install() schedules them before bumping faz_version' );

	echo "\nPassed: $pass\nFailed: $fail\n";
	exit( $fail > 0 ? 1 : 0 );
}
