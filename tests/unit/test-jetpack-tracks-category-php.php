<?php
/**
 * Automattic Tracks cookies must be gateable and disclosable.
 *
 * `wordpress-internal` is not a label — it is a behaviour. Frontend::is_cookie_allowed()
 * returns true for that category BEFORE any consent check, and every visitor-facing
 * declaration filters it out, so a cookie classified there is permanently
 * unblockable and permanently undisclosed. That is correct for wp-settings-* and
 * the auth cookies, which a visitor never receives; it was wrong for the Jetpack
 * Tracks trio, which Jetpack also sets outside wp-admin.
 *
 * Run: php tests/unit/test-jetpack-tracks-category-php.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-cookie-database.php';

use FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database;

$passed = 0;
$failed = 0;
function jp_check( $ok, $label ) {
	global $passed, $failed;
	if ( $ok ) { ++$passed; echo "  \033[32mPASS\033[0m {$label}\n"; return; }
	++$failed; echo "  \033[31mFAIL\033[0m {$label}\n";
}

foreach ( array( 'tk_ai', 'tk_qs', 'tk_lr' ) as $name ) {
	$row = Cookie_Database::lookup( $name );
	jp_check( is_array( $row ), "{$name} is in the known-cookie database" );
	jp_check(
		is_array( $row ) && isset( $row['category'] ) && 'analytics' === $row['category'],
		"{$name} is analytics, so it can be blocked and must be declared"
	);
	jp_check(
		is_array( $row ) && isset( $row['category'] ) && 'wordpress-internal' !== $row['category'],
		"{$name} is NOT wordpress-internal (that category is always-allowed and never shown)"
	);
}

// The other direction: cookies a visitor genuinely never receives must STAY
// internal, or this change would start declaring admin-only cookies in the
// visitor's banner — which the project forbids outright.
foreach ( array( 'wp-settings-', 'wordpress_', 'wp_lang' ) as $name ) {
	$row = Cookie_Database::lookup( $name );
	jp_check(
		is_array( $row ) && isset( $row['category'] ) && 'wordpress-internal' === $row['category'],
		"{$name} stays wordpress-internal — admin-only cookies must never reach the banner"
	);
}

echo "\njetpack tracks category: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
