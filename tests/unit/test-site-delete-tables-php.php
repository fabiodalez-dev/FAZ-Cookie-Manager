<?php
/**
 * Deleting a subsite drops this plugin's tables for that site.
 *
 * Core drops the list filtered through wpmu_drop_tables, from
 * wp_uninitialize_site() on WordPress 5.1+ and from wpmu_delete_blog() before
 * that. The plugin did not add its tables, so a deleted subsite left its
 * consent log (ip_hash, user agent), pageviews, embed inventory and banner /
 * cookie / category tables behind.
 *
 * Loads the production must-use helper without the regular plugin bootstrap.
 *
 * Run: php tests/unit/test-site-delete-tables-php.php
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['sdt_multisite'] = false;
$GLOBALS['sdt_hooks']     = array();
function is_multisite() {
	return $GLOBALS['sdt_multisite'];
}
function add_filter( $hook, $callback, $priority, $accepted_args ) {
	$GLOBALS['sdt_hooks'][] = array( $hook, $callback, $priority, $accepted_args );
}
$root   = dirname( __DIR__, 2 );
$helper = $root . '/includes/multisite-cleanup.php';

$pass = 0;
$fail = 0;
function sdt_ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { ++$pass; echo "  [PASS] $label\n"; } else { ++$fail; echo "  [FAIL] $label\n"; }
}

echo "\n== Subsite deletion drops the plugin tables ==\n\n";

require $helper;
sdt_ok( array() === $GLOBALS['sdt_hooks'], 'single-site does not register a hook' );
$GLOBALS['sdt_multisite'] = true;
require $helper;
require $helper;
sdt_ok(
	array( array( 'wpmu_drop_tables', 'faz_drop_site_tables', 10, 2 ) ) === $GLOBALS['sdt_hooks'],
	'the standalone helper registers exactly once even if both the MU and regular copies are loaded'
);
sdt_ok( false !== strpos( file_get_contents( $root . '/faz-cookie-manager.php' ), '/includes/multisite-cleanup.php' ), 'the plugin bootstrap includes the same helper' );

if ( function_exists( 'faz_drop_site_tables' ) ) {
	class Faz_Sdt_Wpdb {
		public function get_blog_prefix( $blog_id ) { return 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_'; }
	}
	$GLOBALS['wpdb'] = new Faz_Sdt_Wpdb();

	$core   = array( 'posts' => 'wp_7_posts', 'options' => 'wp_7_options' );
	$tables = faz_drop_site_tables( $core, 7 );
	foreach ( array( 'faz_banners', 'faz_cookie_categories', 'faz_cookies', 'faz_consent_logs', 'faz_pageviews', 'faz_embed_placeholders' ) as $t ) {
		sdt_ok( in_array( 'wp_7_' . $t, $tables, true ), '03 drops wp_7_' . $t );
	}
	sdt_ok( 'wp_7_posts' === $tables['posts'] && 'wp_7_options' === $tables['options'], '04 core tables are kept in the list' );
	sdt_ok( 0 === count( array_filter( $tables, function ( $t ) { return 0 === strpos( $t, 'wp_faz_' ); } ) ), '05 nothing of the main site is listed' );
}

echo "\nPassed: $pass\nFailed: $fail\n";
exit( $fail > 0 ? 1 : 0 );
