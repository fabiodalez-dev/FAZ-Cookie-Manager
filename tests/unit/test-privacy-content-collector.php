<?php
/**
 * Standalone unit tests for the privacy-policy content collector.
 *
 * Covers the contract the generated Privacy Policy depends on: the snapshot is
 * built only from currently-registered declarations, sanitisation happens
 * before hashing (so an unchanged plugin never reads as changed), an
 * unchanged collection never writes, operator overrides are never silently
 * overwritten, and every context the core `_doing_it_wrong` guard forbids is
 * refused.
 *
 * Run:
 *   php tests/unit/test-privacy-content-collector.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

// ---------------------------------------------------------------------------
// WordPress stubs — only what the collector actually touches.
// ---------------------------------------------------------------------------

$GLOBALS['faz_pc_options']       = array();
$GLOBALS['faz_pc_writes']        = 0;
$GLOBALS['faz_pc_last_autoload'] = null;
$GLOBALS['faz_pc_actions']       = array();
$GLOBALS['faz_pc_registered']    = array();
$GLOBALS['faz_pc_ctx']           = array(
	'is_admin'    => true,
	'did_action'  => true,
	'doing_ajax'  => false,
	'doing_cron'  => false,
	'can_manage'  => true,
);

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['faz_pc_options'] ) ? $GLOBALS['faz_pc_options'][ $name ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_pc_options'][ $name ]  = $value;
		$GLOBALS['faz_pc_last_autoload']     = $autoload;
		++$GLOBALS['faz_pc_writes'];
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['faz_pc_options'][ $name ] );
		return true;
	}
}

/**
 * Deliberately a naive strip, not a faithful kses. Both the stored text and
 * the hash go through it, and that identity is precisely the invariant under
 * test — weakening this stub to `return $v` would make the suite pass without
 * proving anything.
 */
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $value ) {
		return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $value );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( (string) $title, '-' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( wp_strip_all_tags_stub( (string) $value ) );
	}
}
function wp_strip_all_tags_stub( $value ) {
	return strip_tags( $value );
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['faz_pc_actions'][] = array( $hook, $callback );
		return true;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return (bool) $GLOBALS['faz_pc_ctx']['is_admin'];
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return $GLOBALS['faz_pc_ctx']['did_action'] ? 1 : 0;
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return (bool) $GLOBALS['faz_pc_ctx']['doing_ajax'];
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() {
		return (bool) $GLOBALS['faz_pc_ctx']['doing_cron'];
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return (bool) $GLOBALS['faz_pc_ctx']['can_manage'];
	}
}

/**
 * Core stand-in. Defining it up front also proves the collector takes the
 * `class_exists` short circuit: the require path points at
 * ABSPATH . 'wp-admin/…', which does not exist here, so the absence of a
 * fatal is itself an assertion.
 */
if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
	class WP_Privacy_Policy_Content {
		public static function get_suggested_policy_text() {
			return $GLOBALS['faz_pc_registered'];
		}
	}
}

if ( ! class_exists( 'WP_Screen' ) ) {
	class WP_Screen {
		public $id = '';
		public function __construct( $id = '' ) {
			$this->id = $id;
		}
	}
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/privacy-policy-generator/includes/class-content-collector.php';

use FazCookie\Admin\Modules\Privacy_Policy_Generator\Includes\Content_Collector;

// ---------------------------------------------------------------------------
// Assertions.
// ---------------------------------------------------------------------------

$faz_pc_run    = 0;
$faz_pc_passed = 0;
$faz_pc_failed = 0;

function faz_pc_eq( $actual, $expected, $label ) {
	global $faz_pc_run, $faz_pc_passed, $faz_pc_failed;
	++$faz_pc_run;
	if ( $actual === $expected ) {
		++$faz_pc_passed;
		echo "  \033[32m✓\033[0m {$label}\n";
		return;
	}
	++$faz_pc_failed;
	echo "  \033[31m✗\033[0m {$label}\n";
	echo '      expected: ' . var_export( $expected, true ) . "\n";
	echo '      actual:   ' . var_export( $actual, true ) . "\n";
}

function faz_pc_true( $condition, $label ) {
	faz_pc_eq( (bool) $condition, true, $label );
}

/**
 * Reset the world between groups.
 *
 * @param array $registered Entries WP_Privacy_Policy_Content should report.
 * @return void
 */
function faz_pc_reset( $registered = array() ) {
	$GLOBALS['faz_pc_options']       = array();
	$GLOBALS['faz_pc_writes']        = 0;
	$GLOBALS['faz_pc_last_autoload'] = null;
	$GLOBALS['faz_pc_registered']    = $registered;
	$GLOBALS['faz_pc_ctx']           = array(
		'is_admin'   => true,
		'did_action' => true,
		'doing_ajax' => false,
		'doing_cron' => false,
		'can_manage' => true,
	);
}

/**
 * One registered contribution, in core's shape.
 *
 * @param string $name Plugin display name.
 * @param string $text Policy text.
 * @return array
 */
function faz_pc_entry( $name, $text ) {
	return array(
		'plugin_name' => $name,
		'policy_text' => $text,
	);
}

$wc_text  = '<h2>WooCommerce</h2><p>We collect order data.</p>';
$ak_text  = '<h2>Akismet</h2><p>We send comments to Akismet.</p>';
$faz_text = '<h2>FAZ Cookie Manager</h2><p>We record consent.</p>';

echo "\n== Privacy content collector ==\n\n";

// ---------------------------------------------------------------------------
// 1. First collection.
// ---------------------------------------------------------------------------

$before = time();
faz_pc_reset(
	array(
		faz_pc_entry( 'WooCommerce', $wc_text ),
		faz_pc_entry( 'Akismet', $ak_text ),
		faz_pc_entry( 'FAZ Cookie Manager', $faz_text ),
	)
);

$snapshot = Content_Collector::collect();
faz_pc_eq( $snapshot['schema'], 1, 'First collection stores schema 1' );
faz_pc_eq(
	array_keys( $snapshot['blocks'] ),
	array( 'woocommerce', 'akismet', 'faz-cookie-manager' ),
	'Block ids are slugged plugin names'
);
faz_pc_eq( $GLOBALS['faz_pc_writes'], 1, 'First collection writes the option exactly once' );
faz_pc_eq( $GLOBALS['faz_pc_last_autoload'], false, 'Snapshot is written with autoload false' );
faz_pc_true( $snapshot['collected_at'] >= $before, 'collected_at is stamped' );

foreach ( $snapshot['blocks'] as $id => $block ) {
	faz_pc_eq( $block['source_hash'], hash( 'sha256', $block['source_html'] ), "Hash matches stored text for {$id}" );
}
faz_pc_true( $snapshot['blocks']['woocommerce']['added'] >= $before, 'New block records added' );
faz_pc_eq( $snapshot['blocks']['woocommerce']['updated'], 0, 'New block has no updated timestamp' );
faz_pc_eq( $snapshot['blocks']['woocommerce']['removed'], 0, 'New block is not removed' );
faz_pc_eq( $snapshot['blocks']['woocommerce']['override'], array( 'text' => '', 'anchor_hash' => '' ), 'New block has an empty override' );
faz_pc_eq( $snapshot['blocks']['woocommerce']['source_html'], $wc_text, 'Block carries the producer text verbatim' );

// ---------------------------------------------------------------------------
// 2. Idempotence.
// ---------------------------------------------------------------------------

$stamp = $snapshot['collected_at'];
Content_Collector::collect();
Content_Collector::collect();
faz_pc_eq( $GLOBALS['faz_pc_writes'], 1, 'Repeat collection with identical input never writes again' );
faz_pc_eq( Content_Collector::get_snapshot()['collected_at'], $stamp, 'collected_at does not move without a material change' );

// ---------------------------------------------------------------------------
// 3. Input hygiene.
// ---------------------------------------------------------------------------

faz_pc_reset(
	array(
		faz_pc_entry( 'WooCommerce', $wc_text ),
		array( 'plugin_name' => 'Long Gone Plugin', 'policy_text' => '<p>Ghost.</p>', 'removed' => 123 ),
		faz_pc_entry( '', '<p>Nameless.</p>' ),
		faz_pc_entry( 'Empty Plugin', '' ),
		faz_pc_entry( 'Noisy Plugin', '<p>Hello.</p><script>alert(1)</script>' ),
		'not-an-array',
	)
);

$snapshot = Content_Collector::collect();
faz_pc_eq( array_keys( $snapshot['blocks'] ), array( 'woocommerce', 'noisy-plugin' ), 'Ghosts, nameless and empty entries are all dropped' );
faz_pc_eq( $snapshot['blocks']['noisy-plugin']['source_html'], '<p>Hello.</p>', 'Script tags are stripped from collected text' );
faz_pc_eq(
	$snapshot['blocks']['noisy-plugin']['source_hash'],
	hash( 'sha256', '<p>Hello.</p>' ),
	'Hash is taken on the sanitised text, not the raw producer text'
);

// The same input must stay stable: a hash taken before sanitisation would
// re-diff forever here.
$writes = $GLOBALS['faz_pc_writes'];
Content_Collector::collect();
faz_pc_eq( $GLOBALS['faz_pc_writes'], $writes, 'Sanitised text does not re-diff on the next collection' );

// ---------------------------------------------------------------------------
// 4. Untouched block adopts an upstream update.
// ---------------------------------------------------------------------------

faz_pc_reset( array( faz_pc_entry( 'WooCommerce', $wc_text ) ) );
Content_Collector::collect();

$wc_v2                        = '<h2>WooCommerce</h2><p>We collect order and payment data.</p>';
$GLOBALS['faz_pc_registered'] = array( faz_pc_entry( 'WooCommerce', $wc_v2 ) );
$snapshot                     = Content_Collector::collect();

faz_pc_eq( $snapshot['blocks']['woocommerce']['source_html'], $wc_v2, 'Untouched block adopts the new upstream text' );
faz_pc_true( $snapshot['blocks']['woocommerce']['updated'] > 0, 'Adopted change records an updated timestamp' );

$rows = Content_Collector::describe();
faz_pc_eq( $rows[0]['stale'], false, 'An untouched block is never stale' );
faz_pc_eq( $rows[0]['effective_html'], $wc_v2, 'describe() serves the upstream text when no override is set' );
faz_pc_eq( Content_Collector::effective_blocks()['woocommerce']['html'], $wc_v2, 'effective_blocks() serves the upstream text' );

// ---------------------------------------------------------------------------
// 5. Edited block keeps the operator's wording.
// ---------------------------------------------------------------------------

$own = '<p>Our own wording</p>';
faz_pc_true( Content_Collector::set_override( 'woocommerce', $own ), 'set_override() reports a write' );

$snapshot = Content_Collector::get_snapshot();
faz_pc_eq(
	$snapshot['blocks']['woocommerce']['override']['anchor_hash'],
	$snapshot['blocks']['woocommerce']['source_hash'],
	'Override anchors to the source hash it was written against'
);

$wc_v3                        = '<h2>WooCommerce</h2><p>We collect order, payment and shipping data.</p>';
$GLOBALS['faz_pc_registered'] = array( faz_pc_entry( 'WooCommerce', $wc_v3 ) );
$snapshot                     = Content_Collector::collect();

faz_pc_eq( Content_Collector::effective_blocks()['woocommerce']['html'], $own, 'Upstream change never overwrites operator wording' );
faz_pc_eq( $snapshot['blocks']['woocommerce']['source_html'], $wc_v3, 'The placeholder keeps tracking underneath the override' );
$rows = Content_Collector::describe();
faz_pc_eq( $rows[0]['stale'], true, 'An edited block whose source moved is flagged stale' );
faz_pc_eq( $rows[0]['override'], $own, 'describe() exposes the operator text' );

// ---------------------------------------------------------------------------
// 6. Rename carry-forward.
// ---------------------------------------------------------------------------

faz_pc_reset( array( faz_pc_entry( 'WooCommerce', $wc_text ) ) );
Content_Collector::collect();
Content_Collector::set_override( 'woocommerce', $own );

$GLOBALS['faz_pc_registered'] = array( faz_pc_entry( 'WooCommerce (translated)', $wc_text ) );
$snapshot                     = Content_Collector::collect();

faz_pc_eq( array_keys( $snapshot['blocks'] ), array( 'woocommerce' ), 'A renamed plugin keeps its block id' );
faz_pc_eq( $snapshot['blocks']['woocommerce']['plugin_name'], 'WooCommerce (translated)', 'The new display name is adopted' );
faz_pc_eq( Content_Collector::describe()[0]['stale'], false, 'A rename alone does not make an override stale' );

// ---------------------------------------------------------------------------
// 7. Removal.
// ---------------------------------------------------------------------------

faz_pc_reset( array( faz_pc_entry( 'WooCommerce', $wc_text ), faz_pc_entry( 'Akismet', $ak_text ) ) );
Content_Collector::collect();

$GLOBALS['faz_pc_registered'] = array( faz_pc_entry( 'WooCommerce', $wc_text ) );
$snapshot                     = Content_Collector::collect();
faz_pc_eq( array_keys( $snapshot['blocks'] ), array( 'woocommerce' ), 'An untouched block disappears with its producer' );
faz_pc_eq( isset( Content_Collector::effective_blocks()['akismet'] ), false, 'A dropped block never reaches the renderer' );

Content_Collector::set_override( 'woocommerce', $own );
$GLOBALS['faz_pc_registered'] = array();
$snapshot                     = Content_Collector::collect();
faz_pc_eq( array_keys( $snapshot['blocks'] ), array( 'woocommerce' ), 'An edited block survives its producer' );
faz_pc_true( $snapshot['blocks']['woocommerce']['removed'] > 0, 'The surviving block records when the producer left' );
faz_pc_eq( Content_Collector::describe()[0]['orphaned'], true, 'An edited block without a producer is flagged orphaned' );
faz_pc_eq( Content_Collector::effective_blocks()['woocommerce']['html'], $own, 'An orphaned block still renders the operator wording' );

// ---------------------------------------------------------------------------
// 8. Revival.
// ---------------------------------------------------------------------------

$GLOBALS['faz_pc_registered'] = array( faz_pc_entry( 'WooCommerce', $wc_text ) );
$snapshot                     = Content_Collector::collect();
faz_pc_eq( $snapshot['blocks']['woocommerce']['removed'], 0, 'A returning producer clears the removed flag' );
faz_pc_eq( $snapshot['blocks']['woocommerce']['override']['text'], $own, 'Revival leaves the override untouched' );
faz_pc_eq( Content_Collector::describe()[0]['orphaned'], false, 'A revived block is no longer orphaned' );

// ---------------------------------------------------------------------------
// 9. Duplicate display names.
// ---------------------------------------------------------------------------

$stats_a = '<p>Statistics, flavour A.</p>';
$stats_b = '<p>Statistics, flavour B.</p>';
faz_pc_reset(
	array(
		faz_pc_entry( 'WP Statistics', $stats_a ),
		faz_pc_entry( 'WP Statistics', $stats_b ),
	)
);
$snapshot = Content_Collector::collect();
$expected = array( 'wp-statistics', 'wp-statistics-' . substr( hash( 'sha256', $stats_b ), 0, 8 ) );
faz_pc_eq( array_keys( $snapshot['blocks'] ), $expected, 'A same-name collision gets a deterministic hash suffix' );

$writes = $GLOBALS['faz_pc_writes'];
Content_Collector::collect();
faz_pc_eq( array_keys( Content_Collector::get_snapshot()['blocks'] ), $expected, 'Duplicate ids are stable across collections' );
faz_pc_eq( $GLOBALS['faz_pc_writes'], $writes, 'Duplicates do not churn the option' );

// A rewrite that is ambiguous on both sides must never be matched onto a twin.
$GLOBALS['faz_pc_registered'] = array(
	faz_pc_entry( 'WP Statistics', '<p>Statistics, flavour C.</p>' ),
	faz_pc_entry( 'WP Statistics', '<p>Statistics, flavour D.</p>' ),
);
Content_Collector::collect();
$ids = array_keys( Content_Collector::get_snapshot()['blocks'] );
faz_pc_eq( count( $ids ), 2, 'An ambiguous twin rewrite yields two blocks, not four' );

// ---------------------------------------------------------------------------
// 10. set_override edges.
// ---------------------------------------------------------------------------

faz_pc_reset( array( faz_pc_entry( 'WooCommerce', $wc_text ) ) );
Content_Collector::collect();

$writes = $GLOBALS['faz_pc_writes'];
faz_pc_eq( Content_Collector::set_override( 'no-such-block', '<p>x</p>' ), false, 'An unknown block id is refused' );
faz_pc_eq( $GLOBALS['faz_pc_writes'], $writes, 'A refused override writes nothing' );

Content_Collector::set_override( 'woocommerce', $own );
faz_pc_true( Content_Collector::set_override( 'woocommerce', "   \n  " ), 'Whitespace-only text clears the override' );
faz_pc_eq( Content_Collector::get_snapshot()['blocks']['woocommerce']['override']['text'], '', 'A cleared block goes back to tracking upstream' );
faz_pc_eq( Content_Collector::effective_blocks()['woocommerce']['html'], $wc_text, 'A cleared block renders the collected text again' );

faz_pc_eq( Content_Collector::set_override( 'woocommerce', '<p>Kept</p><script>alert(1)</script>' ), true, 'Override input is accepted' );
faz_pc_eq( Content_Collector::get_snapshot()['blocks']['woocommerce']['override']['text'], '<p>Kept</p>', 'Script tags are stripped from override input' );

faz_pc_eq( Content_Collector::set_override( 'woocommerce', str_repeat( 'a', Content_Collector::MAX_HTML + 500 ) ), true, 'An oversized override is accepted' );
faz_pc_eq(
	strlen( Content_Collector::get_snapshot()['blocks']['woocommerce']['override']['text'] ),
	Content_Collector::MAX_HTML,
	'An oversized override is clipped to MAX_HTML'
);

// Clearing the override of a removed block removes the block entirely.
Content_Collector::set_override( 'woocommerce', $own );
$GLOBALS['faz_pc_registered'] = array();
Content_Collector::collect();
faz_pc_true( Content_Collector::set_override( 'woocommerce', '' ), 'Clearing an orphaned block reports a write' );
faz_pc_eq( Content_Collector::get_snapshot()['blocks'], array(), 'Clearing an orphaned block deletes it' );

// ---------------------------------------------------------------------------
// 11. Guards.
// ---------------------------------------------------------------------------

faz_pc_reset( array( faz_pc_entry( 'WooCommerce', $wc_text ) ) );
$GLOBALS['faz_pc_actions'] = array();
Content_Collector::register_hooks();
faz_pc_eq( count( $GLOBALS['faz_pc_actions'] ), 1, 'register_hooks() registers exactly one listener' );
faz_pc_eq( $GLOBALS['faz_pc_actions'][0][0], 'current_screen', 'The listener is on current_screen' );

Content_Collector::maybe_collect( new WP_Screen( 'dashboard' ) );
faz_pc_eq( $GLOBALS['faz_pc_writes'], 0, 'A non-FAZ admin screen never collects' );

$faz_screen = new WP_Screen( 'toplevel_page_faz-cookie-manager' );

$GLOBALS['faz_pc_ctx']['did_action'] = false;
Content_Collector::maybe_collect( $faz_screen );
faz_pc_eq( $GLOBALS['faz_pc_writes'], 0, 'Collection before admin_init is refused' );
$GLOBALS['faz_pc_ctx']['did_action'] = true;

$GLOBALS['faz_pc_ctx']['is_admin'] = false;
Content_Collector::maybe_collect( $faz_screen );
faz_pc_eq( $GLOBALS['faz_pc_writes'], 0, 'Collection outside wp-admin is refused' );
$GLOBALS['faz_pc_ctx']['is_admin'] = true;

$GLOBALS['faz_pc_ctx']['doing_ajax'] = true;
Content_Collector::maybe_collect( $faz_screen );
faz_pc_eq( $GLOBALS['faz_pc_writes'], 0, 'Collection during admin-ajax is refused' );
$GLOBALS['faz_pc_ctx']['doing_ajax'] = false;

$GLOBALS['faz_pc_ctx']['doing_cron'] = true;
Content_Collector::maybe_collect( $faz_screen );
faz_pc_eq( $GLOBALS['faz_pc_writes'], 0, 'Collection during cron is refused' );
$GLOBALS['faz_pc_ctx']['doing_cron'] = false;

$GLOBALS['faz_pc_ctx']['can_manage'] = false;
Content_Collector::maybe_collect( $faz_screen );
faz_pc_eq( $GLOBALS['faz_pc_writes'], 0, 'Collection without manage_options is refused' );
$GLOBALS['faz_pc_ctx']['can_manage'] = true;

Content_Collector::maybe_collect( $faz_screen );
faz_pc_eq( $GLOBALS['faz_pc_writes'], 1, 'A FAZ admin screen with every guard satisfied collects' );

// ---------------------------------------------------------------------------
// 12. Corruption.
// ---------------------------------------------------------------------------

faz_pc_reset( array( faz_pc_entry( 'WooCommerce', $wc_text ) ) );
$empty = array( 'schema' => 1, 'collected_at' => 0, 'blocks' => array() );

$GLOBALS['faz_pc_options'][ Content_Collector::OPTION ] = 'garbage-string';
faz_pc_eq( Content_Collector::get_snapshot(), $empty, 'A non-array option reads as an empty snapshot' );

$GLOBALS['faz_pc_options'][ Content_Collector::OPTION ] = array( 'schema' => 99, 'blocks' => array( 'x' => array() ) );
faz_pc_eq( Content_Collector::get_snapshot(), $empty, 'A foreign schema reads as an empty snapshot' );

$snapshot = Content_Collector::collect();
faz_pc_eq( array_keys( $snapshot['blocks'] ), array( 'woocommerce' ), 'The next collection rebuilds from scratch' );

$good = $snapshot['blocks']['woocommerce'];
$GLOBALS['faz_pc_options'][ Content_Collector::OPTION ] = array(
	'schema'       => 1,
	'collected_at' => 100,
	'blocks'       => array(
		'woocommerce' => $good,
		'broken'      => array( 'plugin_name' => 'Broken', 'source_html' => '<p>x</p>' ),
	),
);
faz_pc_eq( array_keys( Content_Collector::get_snapshot()['blocks'] ), array( 'woocommerce' ), 'A malformed block is dropped and its siblings survive' );

// ---------------------------------------------------------------------------
// 13. MAX_BLOCKS.
// ---------------------------------------------------------------------------

faz_pc_reset();
$full = array();
for ( $i = 0; $i < Content_Collector::MAX_BLOCKS; $i++ ) {
	$full[] = faz_pc_entry( 'Plugin ' . $i, '<p>Body ' . $i . '</p>' );
}
$GLOBALS['faz_pc_registered'] = $full;
$snapshot                     = Content_Collector::collect();
faz_pc_eq( count( $snapshot['blocks'] ), Content_Collector::MAX_BLOCKS, 'The map fills to MAX_BLOCKS' );

Content_Collector::set_override( 'plugin-0', $own );
$GLOBALS['faz_pc_registered'][] = faz_pc_entry( 'One Too Many', '<p>Overflow</p>' );
$snapshot                       = Content_Collector::collect();
faz_pc_eq( count( $snapshot['blocks'] ), Content_Collector::MAX_BLOCKS, 'A block past the cap is refused, never squeezed in' );
faz_pc_eq( isset( $snapshot['blocks']['one-too-many'] ), false, 'The overflow block is the one refused' );
faz_pc_eq( $snapshot['blocks']['plugin-0']['override']['text'], $own, 'The cap never evicts an operator-edited block' );

echo "\n--\nTests:  {$faz_pc_run}\nPassed: {$faz_pc_passed}\nFailed: {$faz_pc_failed}\n\n";
if ( $faz_pc_failed > 0 ) {
	echo "\033[31mFAIL\033[0m\n";
	exit( 1 );
}
echo "\033[32mPASS\033[0m\n";
