<?php
/**
 * #243 — the scanner's known-set must not contradict the frontend name guard.
 *
 * Cookie_Database classified wordpress_logged_in_* as `necessary`, i.e. a
 * cookie fit to appear in the public declaration, while
 * Frontend::is_wp_internal_cookie() listed the same prefix as internal and
 * suppressed it everywhere a visitor could see it. Two tables, one cookie,
 * opposite answers; the strict one won only because every visitor-facing
 * surface consults it too.
 *
 * These checks pin BOTH halves: the classification is now the honest one, and
 * the visitor-facing outcome is unchanged — which is what makes the change
 * safe to ship rather than merely tidier.
 *
 * Run: php tests/unit/test-scanner-wp-auth-classification-php.php
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-cookie-database.php';

use FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database;

$ok = 0; $ko = 0;
function t( $c, $l ) { global $ok, $ko; if ( $c ) { ++$ok; echo "  PASS $l\n"; } else { ++$ko; echo "  FAIL $l\n"; } }

$cat = static function ( $name ) {
	$row = Cookie_Database::lookup( $name );
	return is_array( $row ) && isset( $row['category'] ) ? (string) $row['category'] : '';
};

// The fix: an auth cookie a logged-out visitor never receives is internal.
t( 'wordpress-internal' === $cat( 'wordpress_logged_in_9f2a41' ),
	'wordpress_logged_in_* is classified wordpress-internal, not declarable' );

// Its sibling was already right; the pair must now agree.
t( $cat( 'wordpress_logged_in_9f2a41' ) === $cat( 'wordpress_sec_9f2a41' ),
	'and agrees with wordpress_sec_*, the other half of the same auth pair' );

// The regression guard. wordpress_test_cookie IS set for anonymous visitors on
// wp-login.php, so its catalogue classification stays necessary; the display guard is a separate policy tested below.
t( 'necessary' === $cat( 'wordpress_test_cookie' ),
	'wordpress_test_cookie stays necessary — anonymous visitors do receive it' );

// Prefix specificity still resolves; a bare wordpress_* stays internal.
t( 'wordpress-internal' === $cat( 'wordpress_deadbeef' ),
	'an unrecognised wordpress_* prefix stays internal' );

// The invariant that makes this a no-op for visitors: the frontend name guard
// already suppressed this cookie, and still does. Asserted against the real
// list rather than a copy of it, so the two cannot drift apart again silently.
require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
t( \FazCookie\Frontend\Frontend::is_wp_internal_cookie( 'wordpress_logged_in_9f2a41' ),
	'the real frontend guard suppresses the authentication cookie' );
t( ! \FazCookie\Frontend\Frontend::is_wp_internal_cookie( '_ga' ),
	'the guard does not suppress an unrelated analytics cookie' );
t( \FazCookie\Frontend\Frontend::is_wp_internal_cookie( 'wordpress_test_cookie' ),
	'test-cookie classification stays necessary while its existing display guard stays unchanged' );

echo "\nscanner WP auth classification: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );
