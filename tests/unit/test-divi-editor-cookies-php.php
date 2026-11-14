<?php
/**
 * Divi Builder editor cookies stay out of the visitor-facing declaration.
 *
 * The builder writes one cookie per edited post (`et-saved-post-303900-bb`,
 * `et-editing-post-303900-fb`, ...). Only a logged-in editor ever holds one,
 * but a scan run from an editor's browser filed them under Uncategorized and
 * they reached the public cookie list, one row per post.
 *
 * Two tables have to agree for the fix to hold: Frontend::is_wp_internal_cookie()
 * keeps them off every visitor surface, Cookie_Database files them under the
 * hidden wordpress-internal category at scan time. The drift check below runs
 * both against the same names so they cannot disagree silently.
 *
 * Run: php tests/unit/test-divi-editor-cookies-php.php
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-cookie-database.php';
require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

use FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database;
use FazCookie\Frontend\Frontend;

$ok = 0; $ko = 0;
function t( $c, $l ) { global $ok, $ko; if ( $c ) { ++$ok; echo "  PASS $l\n"; } else { ++$ko; echo "  FAIL $l\n"; } }

$cat = static function ( $name ) {
	$row = Cookie_Database::lookup( $name );
	return is_array( $row ) && isset( $row['category'] ) ? (string) $row['category'] : '';
};

// Names as the builder writes them: prefix + post ID + builder suffix.
$editor_cookies = array(
	'et-saved-post-303900-bb',
	'et-saving-post-303900-bb',
	'et-editing-post-303900-fb',
	'et-editor-available-post-303900-bb',
	'et-syncing-post-303900-bb',
	'et-recommend-sync-post-303900-bb',
	'et-reloaded-post-303900-vb',
	'et-pb-recent-items-colors',
	'et-pb-recent-items-font_family',
);

foreach ( $editor_cookies as $name ) {
	t( Frontend::is_wp_internal_cookie( $name ), "$name is kept off visitor surfaces" );
	t( 'wordpress-internal' === $cat( $name ), "$name is filed under wordpress-internal by the scanner" );
}

// The broad Divi prefixes are NOT editor-only: split testing sets visitor
// cookies, and those must stay declarable and blockable.
foreach ( array( 'et_pb_ab_view_page_12', '_et_pb_ab_shortcode_tracking', 'et_pb_ab_subject_7' ) as $name ) {
	t( ! Frontend::is_wp_internal_cookie( $name ), "$name (visitor-facing Divi cookie) is not suppressed" );
	t( 'wordpress-internal' !== $cat( $name ), "$name is not filed as wordpress-internal" );
}

// A name that merely contains the prefix somewhere else is not matched.
t( ! Frontend::is_wp_internal_cookie( 'my-et-saved-post-1' ), 'the match is anchored at the start of the name' );

echo "\ndivi editor cookies: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );
