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

/**
 * The method under test, extracted verbatim from Frontend so this file runs
 * without a WordPress bootstrap. Kept byte-identical to the shipped body; the
 * guard below fails the suite if the two drift apart.
 */
class Sb_Gdpr_Probe {
	public function smash_balloon_self_restricts() {
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'faz_respect_smash_balloon_gdpr', true ) ) {
			return false;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$settings = get_option( 'sb_instagram_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['gdpr'] ) ) {
			return false;
		}
		return 'yes' === strtolower( trim( (string) $settings['gdpr'] ) );
	}
}

$probe = new Sb_Gdpr_Probe();

echo "Smash Balloon Instagram Feed — GDPR accommodation\n\n";

// --- the reported case -----------------------------------------------------
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'yes' ) );
sb_eq( $probe->smash_balloon_self_restricts(), true, "gdpr 'yes' — Instagram Feed self-restricts, we stand down" );

// --- the default, and the one that would ship a tracker if we got it wrong --
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'auto' ) );
sb_eq( $probe->smash_balloon_self_restricts(), false, "gdpr 'auto' (the DEFAULT) — SB detects no consent plugin, so we must block" );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'no' ) );
sb_eq( $probe->smash_balloon_self_restricts(), false, "gdpr 'no' — SB restricts nothing, we must block" );

// --- absent or unreadable signals all fall to the protective side ----------
$GLOBALS['sb_options'] = array();
sb_eq( $probe->smash_balloon_self_restricts(), false, 'plugin not installed / option missing — block' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'other' => 'x' ) );
sb_eq( $probe->smash_balloon_self_restricts(), false, 'option present but no gdpr key — block' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => 'corrupted-not-an-array' );
sb_eq( $probe->smash_balloon_self_restricts(), false, 'option corrupted to a scalar — block, do not fatal' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => '' ) );
sb_eq( $probe->smash_balloon_self_restricts(), false, 'empty gdpr value — block' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'YES' ) );
sb_eq( $probe->smash_balloon_self_restricts(), true, 'value comparison is case-insensitive' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => ' yes ' ) );
sb_eq( $probe->smash_balloon_self_restricts(), true, 'surrounding whitespace does not defeat the match' );

// A value nobody expects must not be read as permission.
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 'true' ) );
sb_eq( $probe->smash_balloon_self_restricts(), false, "an unexpected value ('true') is not treated as yes" );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => 1 ) );
sb_eq( $probe->smash_balloon_self_restricts(), false, 'a truthy non-string is not treated as yes' );

// --- the escape hatch ------------------------------------------------------
$GLOBALS['sb_options']      = array( 'sb_instagram_settings' => array( 'gdpr' => 'yes' ) );
$GLOBALS['sb_filter_value'] = false;
sb_eq( $probe->smash_balloon_self_restricts(), false, 'faz_respect_smash_balloon_gdpr=false forces the old always-block behaviour' );
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

// --- drift guard -----------------------------------------------------------
// The body above is a copy. If the shipped one changes, this suite would keep
// passing while testing nothing that ships — so compare the two directly.
$shipped = file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );
$needle  = "private function smash_balloon_self_restricts() {";
$pos     = strpos( (string) $shipped, $needle );
$end     = false === $pos ? false : strpos( (string) $shipped, "\n\t}", $pos );
$body    = ( false === $pos || false === $end ) ? '' : substr( (string) $shipped, $pos, $end - $pos );
$normalise = function ( $code ) {
	return trim( preg_replace( '/\s+/', ' ', (string) $code ) );
};
$copy_body = $normalise( "
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'faz_respect_smash_balloon_gdpr', true ) ) {
			return false;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		\$settings = get_option( 'sb_instagram_settings', array() );
		if ( ! is_array( \$settings ) || ! isset( \$settings['gdpr'] ) ) {
			return false;
		}
		return 'yes' === strtolower( trim( (string) \$settings['gdpr'] ) );
" );
sb_eq(
	false !== strpos( $normalise( $body ), $copy_body ),
	true,
	'the copy under test still matches the shipped method (drift guard)'
);

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( 0 === $failed ? 0 : 1 );
