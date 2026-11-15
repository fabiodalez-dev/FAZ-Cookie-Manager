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
 * Runs the production faz_drop_site_tables() extracted from the bootstrap.
 *
 * Run: php tests/unit/test-site-delete-tables-php.php
 */

$source = file_get_contents( dirname( __DIR__, 2 ) . '/faz-cookie-manager.php' );

$pass = 0;
$fail = 0;
function sdt_ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { ++$pass; echo "  [PASS] $label\n"; } else { ++$fail; echo "  [FAIL] $label\n"; }
}

echo "\n== Subsite deletion drops the plugin tables ==\n\n";

$has_function = (bool) preg_match( '/^function faz_drop_site_tables\(.*?^}/ms', $source, $match );
sdt_ok( $has_function, '01 the bootstrap defines faz_drop_site_tables()' );
sdt_ok( 1 === preg_match( "/add_filter\(\s*'wpmu_drop_tables',\s*'faz_drop_site_tables',\s*10,\s*2\s*\)/", $source ), '02 it is hooked on wpmu_drop_tables with the site id' );

if ( $has_function ) {
	eval( $match[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- production function body, test-only file.

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
