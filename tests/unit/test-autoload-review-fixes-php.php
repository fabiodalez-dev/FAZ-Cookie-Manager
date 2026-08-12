<?php
/**
 * Five defects found reviewing the backend-autoload/query pass, plus the two
 * batching constants that pass left unreachable.
 *
 * The through-line is that each one is invisible from the outside. A cron
 * callback registered twice still scans; a suspension cancelled early still
 * writes the right rows; a redundant index still returns the right answer; a
 * setting the API cannot reach still has a value. Nothing errors, so nothing
 * gets noticed — which is exactly why they are pinned here.
 *
 *   1. Scanner cron hooks were registered as closures from two callsites that
 *      both fire in the same request. Closures cannot be de-duplicated by
 *      WordPress (_wp_filter_build_unique_id() hashes them by object identity),
 *      so both stayed attached and the scan ran twice.
 *   2. Base_Controller's bulk-write suspension was a boolean, so a nested
 *      resume re-enabled invalidation under a still-running outer batch.
 *   3. Pageview retention read a settings key that Settings::get_defaults()
 *      never declared, making a destructive default unreachable through the
 *      settings API.
 *   4. The pageviews table carried a single-column index that was a strict
 *      prefix of a composite one, paying write cost on the plugin's hottest
 *      insert for a lookup the composite already served.
 *   5. Batch size and per-run cap in both retention purges were literals.
 *
 * 1, 2 and 3 run against the real shipped classes. 4 and 5 are asserted against
 * the shipped source: only a live MySQL server could observe the index, and the
 * batching values are read inside a $wpdb loop that a unit harness cannot enter.
 *
 * Run: php tests/unit/test-autoload-review-fixes-php.php
 */

// --- stubs for the two real classes loaded below ----------------------------

namespace FazCookie\Includes {
	// Settings extends this; it carries no behaviour the sanitiser touches.
	class Store {}
}

namespace FazCookie\Admin\Modules\Scanner\Includes {
	class Scanner_Logger {
		public static function get_instance() { return new self(); }
		public function log( $message, $context = null ) {}
	}
	class Cookie_Database {
		public static function lookup( $name ) { return false; }
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$tests_run = 0;
	$failed    = 0;

	function alq_eq( $actual, $expected, $label ) {
		global $tests_run, $failed;
		$tests_run++;
		if ( $actual === $expected ) {
			echo "  \033[32mPASS\033[0m {$label}\n";
			return;
		}
		$failed++;
		echo "  \033[31mFAIL\033[0m {$label}\n";
		echo "        expected: " . var_export( $expected, true ) . "\n";
		echo "        actual:   " . var_export( $actual, true ) . "\n";
	}

	$alq_root = dirname( __DIR__, 2 );

	// WordPress's real hook table de-duplicates array callbacks for us, which
	// would hide the very difference finding 1 is about. Registrations are
	// therefore just collected verbatim.
	$GLOBALS['alq_actions'] = array();
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['alq_actions'][] = array( $hook, $callback );
		return true;
	}
	function do_action( $hook ) {}
	function get_site_url() { return 'https://example.test'; }
	function absint( $value ) { return abs( (int) $value ); }
	function faz_sanitize_bool( $value ) { return filter_var( $value, FILTER_VALIDATE_BOOLEAN ); }
	function faz_default_language() { return 'en'; }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_title( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $value ) ); }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }

	echo "Backend autoload/query review fixes\n\n";

	// -----------------------------------------------------------------------
	// 1. Scanner cron hooks register exactly once per request.
	// -----------------------------------------------------------------------
	require_once $alq_root . '/admin/modules/scanner/includes/class-controller.php';
	$scanner = 'FazCookie\\Admin\\Modules\\Scanner\\Includes\\Controller';

	// The real shape of the bug: the plugin bootstrap registers the hooks so
	// wp-cron.php (which never loads the admin modules) has a handler, and the
	// scanner module's init() registers them again. Both run in one admin/REST
	// request. A third call stands in for any future caller with the same
	// legitimate reason not to know about the other two.
	$scanner::register_cron_hook();
	$scanner::register_cron_hook();
	$scanner::register_cron_hook();

	$hooks = array_map(
		function ( $entry ) {
			return $entry[0];
		},
		$GLOBALS['alq_actions']
	);

	alq_eq( count( $hooks ), 2, 'three register_cron_hook() calls attach two callbacks, not six' );
	alq_eq(
		count( array_keys( $hooks, 'faz_async_cookie_scan', true ) ),
		1,
		'the scan hook has exactly one handler — a second would crawl the whole site twice'
	);
	alq_eq(
		count( array_keys( $hooks, 'faz_async_httponly_cookie_check', true ) ),
		1,
		'the httpOnly-check hook has exactly one handler'
	);
	// The guard must not have been bought by giving up the laziness the closures
	// were chosen for: an array callback naming the instance would have to build
	// the controller on every front-end request just to name the handler.
	foreach ( $GLOBALS['alq_actions'] as $entry ) {
		alq_eq( $entry[1] instanceof \Closure, true, "{$entry[0]} is still registered lazily, as a closure" );
	}

	// -----------------------------------------------------------------------
	// 2. Bulk-write suspension is re-entrant.
	// -----------------------------------------------------------------------
	// Base_Controller is abstract, but the three methods under test are static
	// and so is the counter they share, so the shipped class is driven directly
	// — no subclass, no instance, and nothing paraphrased.
	require_once $alq_root . '/includes/class-base-controller.php';
	$bc = 'FazCookie\\Includes\\Base_Controller';

	alq_eq( $bc::is_cache_invalidation_suspended(), false, 'starts unsuspended' );
	$bc::suspend_cache_invalidation();                       // outer bulk writer
	$bc::suspend_cache_invalidation();                       // nested one
	alq_eq( $bc::is_cache_invalidation_suspended(), true, 'nested suspend keeps it suspended' );
	$bc::resume_cache_invalidation();                        // inner finishes
	alq_eq(
		$bc::is_cache_invalidation_suspended(),
		true,
		'the INNER resume does not re-enable invalidation under the still-running outer batch'
	);
	$bc::resume_cache_invalidation();                        // outer finishes
	alq_eq( $bc::is_cache_invalidation_suspended(), false, 'the outermost resume ends bulk mode' );

	// The failure path pinned by test-scanner-bulk-write-php.php: a writer that
	// throws unwinds through its finally. An unpaired resume must not push the
	// counter negative, which would leave the NEXT bulk writer's suspend
	// silently ineffective for the rest of the request.
	$bc::resume_cache_invalidation();
	alq_eq( $bc::is_cache_invalidation_suspended(), false, 'an unpaired resume is a no-op, not a negative counter' );
	$bc::suspend_cache_invalidation();
	alq_eq( $bc::is_cache_invalidation_suspended(), true, 'and the next bulk writer still suspends correctly' );
	$bc::resume_cache_invalidation();
	alq_eq( $bc::is_cache_invalidation_suspended(), false, 'and still resumes correctly' );

	// -----------------------------------------------------------------------
	// 3. Pageview retention is a real, reachable setting.
	// -----------------------------------------------------------------------
	require_once $alq_root . '/admin/modules/settings/includes/class-settings.php';
	$settings_cls = 'FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings';
	$defaults_all = ( new $settings_cls() )->get_defaults();

	alq_eq(
		isset( $defaults_all['pageviews']['retention'] ),
		true,
		'get_defaults() declares the pageviews group the retention cleanup already reads'
	);
	alq_eq( $defaults_all['pageviews']['retention'], 6, 'and its default is the 6 months that were hardcoded' );

	// Same defect, same file, one group over: cleanup_old_dsar_requests() reads
	// $settings['dsar']['retention'] and the group was equally undeclared, so the
	// documented 24-month window was unreachable too and every install ran on the
	// hardcoded fallback. Flagged during the first pass and left out of scope
	// then; the group-threading added for pageviews is what made it a two-line
	// fix rather than a second mechanism.
	alq_eq(
		isset( $defaults_all['dsar']['retention'] ),
		true,
		'get_defaults() declares the dsar group the DSAR cleanup already reads'
	);
	alq_eq( $defaults_all['dsar']['retention'], 24, 'and its default is the 24 months that were hardcoded' );
	// DSAR records evidence that a request was answered — the kind of record a
	// controller may have to produce — so unlike pageviews they keep the floor of
	// 1 and "never purge" stays unreachable from the UI.
	alq_eq(
		$settings_cls::sanitize( array( 'dsar' => array( 'retention' => 0 ) ), array( 'dsar' => array( 'retention' => 24 ) ) )['dsar']['retention'],
		1,
		'a stored 0 for DSAR is floored to 1, never "keep forever"'
	);
	alq_eq(
		$settings_cls::sanitize( array( 'dsar' => array( 'retention' => 6 ) ), array( 'dsar' => array( 'retention' => 24 ) ) )['dsar']['retention'],
		6,
		'but a site can shorten it'
	);

	// absint( -1 ) is 1, so a negative retention used to land ABOVE the pageviews
	// floor and store as one month — the SHORTEST window — when the obvious
	// reading of a negative is "off". Clamping the signed value sends it to the
	// floor of each group instead.
	alq_eq(
		$settings_cls::sanitize( array( 'pageviews' => array( 'retention' => -1 ) ), array( 'pageviews' => array( 'retention' => 6 ) ) )['pageviews']['retention'],
		0,
		'a negative pageview retention floors to 0 (never purge), not to 1 month'
	);
	alq_eq(
		$settings_cls::sanitize( array( 'consent_logs' => array( 'retention' => -1 ) ), array( 'consent_logs' => array( 'retention' => 12 ) ) )['consent_logs']['retention'],
		1,
		'and a negative consent-log retention still floors to 1, never to "keep forever"'
	);

	// The seeding marker records that the work happened; writing it first made it
	// a claim, and a failed write then locked the seed out permanently.
	$act_seed = (string) file_get_contents( $alq_root . '/includes/class-activator.php' );
	$seed_at  = strpos( $act_seed, 'function seed_default_whitelist' );
	$seed_end = false === $seed_at ? false : strpos( $act_seed, "\n\t}", $seed_at );
	$region   = ( false === $seed_at || false === $seed_end ) ? '' : substr( $act_seed, $seed_at, $seed_end - $seed_at );
	$write_at = strpos( $region, "update_option( 'faz_settings', \$settings );" );
	$mark_at  = strpos( $region, "add_option( 'faz_default_whitelist_seeded'" );
	// Both must be FOUND, not merely ordered: strpos() returns false, which
	// compares as 0, so a missing needle would otherwise satisfy a bare `<`.
	alq_eq(
		false !== $write_at && false !== $mark_at && $write_at < $mark_at,
		true,
		'the seeded marker is written AFTER the whitelist, not before'
	);
	alq_eq(
		false !== strpos( $region, 'RuntimeException' ),
		true,
		'and a failed persist throws so run_pending_migrations() retries'
	);

	// The retention SELECT must not ask for an order no index covers.
	foreach ( array( 'consentlogs', 'pageviews' ) as $mod ) {
		$src = (string) file_get_contents( $alq_root . '/admin/modules/' . $mod . '/includes/class-controller.php' );
		alq_eq(
			false === strpos( $src, 'ORDER BY log_id ASC LIMIT' ) && false === strpos( $src, 'ORDER BY id ASC LIMIT' ),
			true,
			$mod . ' — the retention SELECT does not sort by a key idx_created_at cannot cover'
		);
	}

	// The key the Activator reads and the key the defaults declare have to be
	// the same one; a rename on either side silently restores the bug.
	$act_src = (string) file_get_contents( $alq_root . '/includes/class-activator.php' );
	alq_eq(
		false !== strpos( $act_src, "\$settings['pageviews']['retention']" ),
		true,
		'and it is the exact path run_retention_cleanup() reads'
	);
	alq_eq(
		false !== strpos( $act_src, "\$settings['dsar']['retention']" ),
		true,
		'and the DSAR cleanup reads its group by the same path'
	);
	alq_eq(
		false !== strpos( $act_src, "apply_filters( 'faz_pageviews_retention_months'" ),
		true,
		'the pre-existing filter still overrides the stored value'
	);

	// sanitize_option() dispatches on the bare leaf name across the whole
	// settings tree, and 'retention' now means two different things, so the
	// group has to travel with it.
	$sanitize_defaults = array(
		'consent_logs' => array( 'retention' => 12 ),
		'pageviews'    => array( 'retention' => 6 ),
	);

	$absent = $settings_cls::sanitize( array(), $sanitize_defaults );
	alq_eq( $absent['pageviews']['retention'], 6, 'an install with no stored value gets the documented 6 months' );

	$set = $settings_cls::sanitize(
		array(
			'consent_logs' => array( 'retention' => 24 ),
			'pageviews'    => array( 'retention' => 3 ),
		),
		$sanitize_defaults
	);
	alq_eq( $set['pageviews']['retention'], 3, 'a site can now actually shorten pageview retention' );
	alq_eq( $set['consent_logs']['retention'], 24, 'and consent-log retention is unaffected' );

	$zero = $settings_cls::sanitize(
		array(
			'consent_logs' => array( 'retention' => 0 ),
			'pageviews'    => array( 'retention' => 0 ),
		),
		$sanitize_defaults
	);
	alq_eq( $zero['pageviews']['retention'], 0, '0 survives for pageviews — the documented "never purge"' );
	alq_eq(
		$zero['consent_logs']['retention'],
		1,
		'but 0 is still refused for consent logs, which are accountability evidence and must not be kept forever'
	);

	$huge = $settings_cls::sanitize( array( 'pageviews' => array( 'retention' => 99999 ) ), $sanitize_defaults );
	alq_eq( $huge['pageviews']['retention'], 120, 'and the ten-year ceiling still applies' );

	// Called directly, with no group, the old contract is unchanged — the third
	// parameter is defaulted precisely so external callers keep working.
	alq_eq( $settings_cls::sanitize_option( 'retention', 0 ), 1, 'a group-less direct call keeps the conservative floor' );

	// -----------------------------------------------------------------------
	// 4. The pageviews table carries no redundant index.
	// -----------------------------------------------------------------------
	$pv_src = (string) file_get_contents( $alq_root . '/admin/modules/pageviews/includes/class-controller.php' );

	alq_eq(
		false === strpos( $pv_src, 'KEY idx_event_type (event_type)' ),
		true,
		'idx_event_type is gone — idx_event_created already covers its leftmost prefix'
	);
	alq_eq(
		false !== strpos( $pv_src, 'KEY idx_event_created (event_type,created_at)' ),
		true,
		'the composite that subsumes it is still there'
	);
	alq_eq(
		false !== strpos( $pv_src, 'KEY idx_created_at (created_at)' ),
		true,
		'and idx_created_at survives — retention range-scans it, and it is NOT a prefix of the composite'
	);
	// A schema change has to move the version this file gates dbDelta on, or
	// nothing re-runs on upgrade.
	alq_eq(
		1 === preg_match( "#private \\\$db_version = '1\.2';#", $pv_src ),
		true,
		'db_version was bumped, which is how this file signals a schema change'
	);
	alq_eq(
		false !== strpos( $pv_src, 'dbDelta only ADDS columns and keys; it never drops them' ),
		true,
		'the migration note records that existing installs keep the redundant key until dropped by hand'
	);

	// -----------------------------------------------------------------------
	// 5. Both retention purges are tunable, and clamped.
	// -----------------------------------------------------------------------
	foreach ( array(
		'consentlogs' => 'admin/modules/consentlogs/includes/class-controller.php',
		'pageviews'   => 'admin/modules/pageviews/includes/class-controller.php',
	) as $name => $rel ) {
		$src = (string) file_get_contents( $alq_root . '/' . $rel );

		alq_eq(
			false !== strpos( $src, "apply_filters( 'faz_retention_batch_size', 1000 )" ),
			true,
			"{$name} — batch size is filterable, defaulting to the previous literal"
		);
		alq_eq(
			false !== strpos( $src, "apply_filters( 'faz_retention_max_rows', 200000 )" ),
			true,
			"{$name} — per-run cap is filterable, defaulting to the previous literal"
		);
		// The clamps are load-bearing, not politeness: the batch size becomes
		// that many %d placeholders in one prepared DELETE, and a filter
		// returning 0 or a negative would purge nothing while reporting success
		// — the same silent-retention-failure class this code was rewritten to
		// eliminate.
		alq_eq(
			false !== strpos( $src, 'max( 100, min( 10000, $batch_size ) )' ),
			true,
			"{$name} — batch size is clamped to a range that cannot break prepare()"
		);
		alq_eq(
			false !== strpos( $src, 'max( $batch_size, min( 10000000, $max_rows ) )' ),
			true,
			"{$name} — the cap can never fall below one batch, which would purge nothing"
		);
		// A filter read into a variable nobody uses is worse than no filter,
		// because it looks wired. Both have to reach the SQL.
		alq_eq(
			false !== strpos( $src, 'LIMIT %d' ) && false !== strpos( $src, '$batch_size' ),
			true,
			"{$name} — the batch size is bound into the SELECT, not merely computed"
		);
		alq_eq(
			false !== strpos( $src, '$deleted < $max_rows' ),
			true,
			"{$name} — the cap is what ends the loop"
		);
	}

	echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
	exit( 0 === $failed ? 0 : 1 );
}
