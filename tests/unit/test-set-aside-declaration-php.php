<?php
/**
 * #243 — an administrator may declare what the scan could only set aside.
 *
 * A browser scan runs from one logged-in session and cannot tell whether a
 * cookie it saw there also reaches visitors: `_lscache_vary` never reaches an
 * anonymous visitor on a plain blog and reaches every one of them on a shop
 * with a cart. Same cookie, same plugin, opposite correctness, decided by site
 * configuration no crawl observes.
 *
 * Two halves have to hold together, and the first without the second is the
 * trap these checks exist to catch. Writing the catalogue row is not enough:
 * `_lscache_vary` is on Frontend::is_wp_internal_cookie()'s exact list, so a
 * row declared without lifting the display guard would be stored, reported as
 * declared, and shown to nobody.
 *
 * The safety rule that must never weaken: declaring adds TRANSPARENCY and can
 * never arm a DELETION. is_always_allowed_cookie_name() still consults the
 * unmodified is_wp_internal_cookie(), so a declared cache-vary cookie is
 * described to visitors and still never shredded.
 *
 * Run: php tests/unit/test-set-aside-declaration-php.php
 */

namespace {

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'YEAR_IN_SECONDS', 31536000 );

$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function add_option( $k, $v, $x = '', $a = null ) { if ( array_key_exists( $k, $GLOBALS['opts'] ) ) { return false; } $GLOBALS['opts'][ $k ] = $v; return true; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_title( $v ) { return preg_replace( '/[^a-z0-9\-]/', '-', strtolower( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function __( $v, ...$u ) { return $v; }
function esc_html__( $v, ...$u ) { return $v; }
function esc_html( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_ssl() { return true; }
function get_current_user_id() { return 7; }
function wp_doing_ajax() { return false; }
function is_admin() { return false; }
function apply_filters( $t, $v, ...$a ) { return $v; }
function did_action( $t ) { return 1; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
class WP_Error { public function __construct( ...$args ) {} }

require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';

use FazCookie\Frontend\Frontend;

/**
 * The real controller with only the catalogue write replaced.
 *
 * save_cookies() is the one thing here that needs a database; everything the
 * promotion actually decides — which row, whether it may be promoted, what the
 * catalogue is handed, what is left behind — is the code under test. Capturing
 * the call is also what lets the measured-attributes assertion exist at all.
 */
class Recording_Controller extends \FazCookie\Admin\Modules\Scanner\Includes\Controller {
	public $saved = array();
	public function save_cookies( $cookies ) {
		$this->saved[] = $cookies;
		return count( $cookies );
	}
}

$ok = 0; $ko = 0;
function t( $c, $l ) { global $ok, $ko; if ( $c ) { ++$ok; echo "  PASS $l\n"; } else { ++$ko; echo "  FAIL $l\n"; } }

/** Rewrite the override list and drop the request-lifetime cache with it. */
function set_overrides( array $names ) {
	$GLOBALS['opts'][ Frontend::DECLARED_INTERNAL_OPTION ] = $names;
	Frontend::flush_declared_internal_cache();
}

// ---------------------------------------------------------------- the tiers --

t( Frontend::internal_cookie_is_structural( 'wordpress_logged_in_9f2a41' ),
	'an authentication cookie is structural' );
t( Frontend::internal_cookie_is_structural( 'wp-settings-7' ),
	'so is administrative screen state' );
t( ! Frontend::internal_cookie_is_structural( '_lscache_vary' ),
	'a cache-vary cookie is NOT structural — whether visitors get it depends on the site' );
t( ! Frontend::internal_cookie_is_structural( 'comment_author_9f2a41' ),
	'nor is a comment-author cookie, which anonymous commenters do receive' );

// ------------------------------------------------- default behaviour is kept --

set_overrides( array() );
t( Frontend::is_declaration_suppressed( '_lscache_vary' ),
	'with no override, a cache-vary cookie is hidden exactly as before' );
t( Frontend::is_declaration_suppressed( 'comment_author_9f2a41' ),
	'and so is a comment-author cookie' );
t( ! Frontend::is_declaration_suppressed( '_ga' ),
	'an ordinary analytics cookie was never suppressed and still is not' );

// ------------------------------------------------------- the override works --

set_overrides( array( '_lscache_vary' ) );
t( ! Frontend::is_declaration_suppressed( '_lscache_vary' ),
	'once declared, the cache-vary cookie reaches the banner and the policy' );
t( Frontend::is_declaration_suppressed( 'comment_author_9f2a41' ),
	'declaring one name does not unhide another' );

// ------------------------------------- the override can never reach the auth --

set_overrides( array( 'wordpress_logged_in_9f2a41', 'wp-settings-7' ) );
t( Frontend::is_declaration_suppressed( 'wordpress_logged_in_9f2a41' ),
	'a structural name stays suppressed even when the option lists it' );
t( Frontend::is_declaration_suppressed( 'wp-settings-7' ),
	'the same for administrative screen state' );

// ----------------------------------------- declaring never arms the shredder --

set_overrides( array( '_lscache_vary' ) );
t( Frontend::is_wp_internal_cookie( '_lscache_vary' ),
	'the never-shred list is untouched by a declaration — is_always_allowed_cookie_name() delegates to it' );

// -------------------------------------------------- the set-aside bookkeeping --

set_overrides( array() );
$ctl = new Recording_Controller();
$ctl->remember_set_aside_cookies(
	array(
		array( 'name' => '_lscache_vary', 'domain' => 'shop.example', 'duration' => '2 days', 'source' => 'admin-runtime' ),
		array( 'name' => 'wordpress_logged_in_9f2a41', 'domain' => 'shop.example', 'duration' => 'session' ),
		array( 'name' => '_ga', 'domain' => '.example', 'duration' => '2 years' ),
	)
);
$rows  = $ctl->set_aside_cookies();
$names = array_column( $rows, 'name' );

t( ! in_array( 'wordpress_logged_in_9f2a41', $names, true ),
	'a structural name is never offered for a decision that would be refused' );
t( in_array( '_lscache_vary', $names, true ) && in_array( '_ga', $names, true ),
	'the decidable names are offered' );

$vary = null;
foreach ( $rows as $row ) { if ( '_lscache_vary' === $row['name'] ) { $vary = $row; } }
t( is_array( $vary ) && 'shop.example' === $vary['domain'] && '2 days' === $vary['duration'],
	'the measured domain and lifetime survive, so nothing has to be retyped' );
t( is_array( $vary ) && true === $vary['suppressed'],
	'the row says declaring it will also lift the display guard' );

$ga = null;
foreach ( $rows as $row ) { if ( '_ga' === $row['name'] ) { $ga = $row; } }
t( is_array( $ga ) && false === $ga['suppressed'],
	'an ordinary cookie carries no such warning' );

// ------------------------------------------------------------- the promotion --

$result = $ctl->declare_set_aside_cookie( '_lscache_vary' );
t( 'declared' === $result['status'], 'declaring a decidable name succeeds' );
t( count( $ctl->saved ) === 1 && '_lscache_vary' === $ctl->saved[0][0]['name'],
	'the catalogue is handed the row' );
t( 'shop.example' === $ctl->saved[0][0]['domain'] && '2 days' === $ctl->saved[0][0]['duration'],
	'and handed the MEASURED attributes, not defaults' );

Frontend::flush_declared_internal_cache();
t( ! Frontend::is_declaration_suppressed( '_lscache_vary' ),
	'and the display guard is lifted in the same operation — a row nobody can see is not a declaration' );

$left = array_column( $ctl->set_aside_cookies(), 'name' );
t( ! in_array( '_lscache_vary', $left, true ) && in_array( '_ga', $left, true ),
	'the decided row leaves the queue and the undecided one stays' );

// ------------------------------------------------------------ the refusals --

$before  = count( $ctl->saved );
$refused = $ctl->declare_set_aside_cookie( 'wordpress_logged_in_9f2a41' );
t( 'structural' === $refused['status'], 'the route refuses an authentication cookie' );
t( count( $ctl->saved ) === $before, 'and writes nothing when it refuses' );
t( Frontend::is_declaration_suppressed( 'wordpress_logged_in_9f2a41' ),
	'the authentication cookie is still suppressed after the refused attempt' );

t( 'unknown' === $ctl->declare_set_aside_cookie( 'never_observed' )['status'],
	'a name that was never set aside cannot be declared' );

echo "\nset-aside declaration: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );

}
