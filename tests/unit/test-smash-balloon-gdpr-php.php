<?php
/**
 * Standalone unit tests for the Smash Balloon Instagram Feed accommodation.
 *
 * Reported on wp.org ("Smash Balloon Instagram Feed content blocker"): since
 * 1.25.0 the feed is consent-blocked by default, and when Instagram Feed's own
 * GDPR setting is "Yes" that block is redundant — the plugin already serves
 * local image copies and makes no request to Instagram's CDN.
 *
 * What these tests pin, and why the default matters more than the reported case:
 * Instagram Feed's `auto` mode does NOT detect an arbitrary consent plugin. Its
 * gdpr_plugins_active() checks a hardcoded list of nine by class/constant/
 * function and this plugin is not on it, so on `auto` — the shipped default —
 * Instagram Feed restricts nothing and our block is the only thing holding the
 * feed back. Standing down there would ship a tracker. The accommodation is
 * therefore deliberately narrow: exactly one value turns it on.
 *
 * Run: php tests/unit/test-smash-balloon-gdpr-php.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['sb_options']     = array();
$GLOBALS['sb_filter_value'] = true;

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['sb_options'] ) ? $GLOBALS['sb_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		if ( 'faz_respect_smash_balloon_gdpr' === $hook ) {
			return $GLOBALS['sb_filter_value'];
		}
		return $value;
	}
}

// Loaded for real, not stubbed: the exempt-pattern set is derived FROM the
// provider database, so a stub would let the two drift and the assertions below
// would be checking this file against itself.
require_once dirname( __DIR__, 2 ) . '/includes/class-known-providers.php';
require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

use FazCookie\Frontend\Frontend;

$tests_run = 0;
$failed    = 0;
function sb_eq( $actual, $expected, $label ) {
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

echo "Smash Balloon Instagram Feed — GDPR accommodation\n\n";

// --- the plugin is not loaded ----------------------------------------------
// This block MUST run before anything defines SBIVER: a PHP constant cannot be
// undefined, so "plugin absent" is only observable while it is still absent.
// The ordering is load-bearing, not stylistic.
//
// Instagram Feed does not clean sb_instagram_settings out of wp_options when it
// is deactivated or deleted, so gdpr='yes' can outlive the code that honoured
// it. Reading that as "somebody else is handling this" would stand our block
// down while nothing at all restricted the feed — the one way this
// accommodation could ship a tracker.
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'yes' ) );
sb_eq( Frontend::smash_balloon_self_restricts(), false, "gdpr 'yes' but Instagram Feed NOT loaded — stale option, keep blocking" );

// From here on, simulate the plugin being active. Its bootstrap defines SBIVER
// unconditionally (guarded only against redefinition), so the constant is
// present for exactly as long as the plugin is.
define( 'SBIVER', '6.11.4' );

// --- the reported case -----------------------------------------------------
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'yes' ) );
sb_eq( Frontend::smash_balloon_self_restricts(), true, "gdpr 'yes' — Instagram Feed self-restricts, we stand down" );

// --- the default, and the one that would ship a tracker if we got it wrong --
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'auto' ) );
sb_eq( Frontend::smash_balloon_self_restricts(), false, "gdpr 'auto' (the DEFAULT) — SB detects no consent plugin, so we must block" );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'no' ) );
sb_eq( Frontend::smash_balloon_self_restricts(), false, "gdpr 'no' — SB restricts nothing, we must block" );

// --- absent or unreadable signals all fall to the protective side ----------
$GLOBALS['sb_options'] = array();
sb_eq( Frontend::smash_balloon_self_restricts(), false, 'plugin not installed / option missing — block' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'other' => 'x' ) );
sb_eq( Frontend::smash_balloon_self_restricts(), false, 'option present but no gdpr key — block' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => 'corrupted-not-an-array' );
sb_eq( Frontend::smash_balloon_self_restricts(), false, 'option corrupted to a scalar — block, do not fatal' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => '' ) );
sb_eq( Frontend::smash_balloon_self_restricts(), false, 'empty gdpr value — block' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'YES' ) );
sb_eq( Frontend::smash_balloon_self_restricts(), true, 'value comparison is case-insensitive' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => ' yes ' ) );
sb_eq( Frontend::smash_balloon_self_restricts(), true, 'surrounding whitespace does not defeat the match' );

// A value nobody expects must not be read as permission.
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'true' ) );
sb_eq( Frontend::smash_balloon_self_restricts(), false, "an unexpected value ('true') is not treated as yes" );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 1 ) );
sb_eq( Frontend::smash_balloon_self_restricts(), false, 'a truthy non-string is not treated as yes' );

// A corrupted option can hold a non-scalar. Casting an array to string raises a
// conversion warning; casting an object without __toString throws. Either turns
// a malformed setting into a frontend error, so the type is rejected outright —
// and these two cases pin that it neither throws nor reads as permission.
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => array( 'yes' ) ) );
sb_eq( Frontend::smash_balloon_self_restricts(), false, 'an array value is rejected, not stringified' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => new stdClass() ) );
sb_eq( Frontend::smash_balloon_self_restricts(), false, 'an object value is rejected without throwing' );

// --- the escape hatch ------------------------------------------------------
$GLOBALS['sb_options']      = array( 'sb_instagram_settings' => array( 'gdpr' => 'yes' ) );
$GLOBALS['sb_filter_value'] = false;
sb_eq( Frontend::smash_balloon_self_restricts(), false, 'faz_respect_smash_balloon_gdpr=false forces the old always-block behaviour' );
$GLOBALS['sb_filter_value'] = true;

// --- the link must point somewhere that exists -----------------------------
// A notice that sends the site owner to a 404 is worse than one that only names
// the setting. Pin our slug against the page Instagram Feed actually registers,
// read from the plugin itself when it is installed on this machine.
$sb_slug = 'sbi-settings';
$shipped_slug = '';
$fe_src = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
if ( preg_match( "/SMASH_BALLOON_SETTINGS_SLUG = '([^']+)'/", (string) $fe_src, $m ) ) {
	$shipped_slug = $m[1];
}
sb_eq( $shipped_slug, $sb_slug, 'the settings slug we link to is the one this test pins' );

$candidates = array(
	'/Users/fabio/Sites/faz-test/wp-content/plugins/instagram-feed/inc/admin/actions.php',
);
$checked = false;
foreach ( $candidates as $candidate ) {
	if ( ! is_readable( $candidate ) ) {
		continue;
	}
	$checked = true;
	$actions = (string) file_get_contents( $candidate );
	sb_eq(
		false !== strpos( $actions, $shipped_slug ),
		true,
		'Instagram Feed registers the page slug we link to (verified against the installed plugin)'
	);
}
if ( ! $checked ) {
	echo "  \033[33mSKIP\033[0m Instagram Feed not installed here — slug not cross-checked against the plugin\n";
}

// The self-restriction must clear only the CATEGORY-level block and leave the
// per-service decision able to put it back. Removing the entry from
// $social_ids instead would skip that check entirely, so a visitor who
// explicitly denied smash-balloon-instagram would get the feed anyway while the
// preference-centre toggle showed it off. Pinned structurally because the
// difference is invisible in the blocked/not-blocked outcome that the rest of
// this suite can observe.
$shipped     = $fe_src;
$social_pass = strpos( (string) $shipped, '$sb_self_restricts = self::smash_balloon_self_restricts();' );
sb_eq( false !== $social_pass, true, 'the self-restriction is resolved once, outside the social-id loop' );
if ( false !== $social_pass ) {
	$region        = substr( (string) $shipped, $social_pass, 2600 );
	$exemption_at  = strpos( $region, "if ( \$sb_self_restricts && 'smash-balloon-instagram' === \$info['service_id'] )" );
	$per_service_at = strpos( $region, '$service_consent = $this->get_service_consent();' );
	sb_eq( false !== $exemption_at, true, 'the exemption is applied inside the loop, not by dropping the entry' );
	sb_eq(
		( false !== $exemption_at && false !== $per_service_at && $exemption_at < $per_service_at ),
		true,
		'it runs BEFORE the per-service check, so an explicit denial still binds'
	);
	sb_eq(
		false === strpos( $region, "unset( \$social_ids['sb_instagram'] )" ),
		true,
		'the entry is never dropped from the loop'
	);
}

// --- the script side of the accommodation ----------------------------------
// Exempting the container without the script was a half measure that produced
// the worst of the three outcomes: the feed's <div> rendered while its own
// first-party script stayed blocked, so the visitor got an empty box with no
// placeholder to explain it. Measured on a real page before this was fixed.
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'yes' ) );
$exempt = Frontend::smash_balloon_exempt_patterns();

sb_eq( isset( $exempt['sb-instagram'] ), true, "gdpr 'yes' — the dedicated provider's pattern is exempt" );
sb_eq( isset( $exempt['sb_instagram_scripts'] ), true, "gdpr 'yes' — its handle pattern is exempt too" );

// The trap that made the first fix inert: a SECOND provider entry covers the
// same asset. The generic `instagram` service carries a path pattern for the
// plugin's own directory, and leaving it in place kept blocking the very script
// the dedicated exemption had just released.
sb_eq( isset( $exempt['plugins/instagram-feed/js/'] ), true, "the generic provider's path pattern for the same plugin is exempt" );
sb_eq( isset( $exempt['plugins/instagram-feed-pro/js/'] ), true, 'and the Pro build of the same plugin, which ships under its own slug' );

// Nothing beyond Instagram Feed may ride along: this exemption removes blocking,
// so breadth here is the dangerous direction.
$strays = array();
foreach ( array_keys( $exempt ) as $pattern ) {
	if ( false === stripos( $pattern, 'instagram-feed' ) && false === stripos( $pattern, 'sb-instagram' ) && false === stripos( $pattern, 'sb_instagram' ) ) {
		$strays[] = $pattern;
	}
}
sb_eq( $strays, array(), 'no unrelated provider is exempted along with it' );
sb_eq( isset( $exempt['instagram.com'] ), false, "instagram.com itself stays blocked — it is a genuine third party" );

// And every value is off when the accommodation is.
foreach ( array( 'auto', 'no', '' ) as $value ) {
	$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => $value ) );
	sb_eq( Frontend::smash_balloon_exempt_patterns(), array(), "gdpr '{$value}' — nothing is exempted" );
}

// Both blocking paths must consult it. The client-side interceptor is built from
// a SEPARATE list, so exempting only the server map would leave the browser
// re-blocking the script the server had just allowed.
$server_at = strpos( (string) $fe_src, '$sb_exempt_patterns = self::smash_balloon_exempt_patterns();' );
sb_eq( false !== $server_at, true, 'the server provider map applies the exemption' );
// Anchored on the client loop's own condition rather than on a count of call
// sites: a count says "two of something" and would go green on two copies in the
// same function, which is the failure it is meant to exclude.
sb_eq(
	false !== strpos( (string) $fe_src, '$this->is_always_allowed_gateway_pattern( $pattern ) || isset( $sb_exempt_patterns[ $pattern ] )' ),
	true,
	'and so does the client-side provider list (separate code path, same set)'
);

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( 0 === $failed ? 0 : 1 );
