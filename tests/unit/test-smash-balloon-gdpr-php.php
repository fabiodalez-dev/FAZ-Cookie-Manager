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
		if ( ! defined( 'SBIVER' ) && ! class_exists( 'SB_Instagram_GDPR_Integrations', false ) ) {
			return false;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$settings = get_option( 'sb_instagram_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['gdpr'] ) ) {
			return false;
		}
		if ( ! is_string( $settings['gdpr'] ) ) {
			return false;
		}
		return 'yes' === strtolower( trim( $settings['gdpr'] ) );
	}
}

$probe = new Sb_Gdpr_Probe();

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
sb_eq( $probe->smash_balloon_self_restricts(), false, "gdpr 'yes' but Instagram Feed NOT loaded — stale option, keep blocking" );

// From here on, simulate the plugin being active. Its bootstrap defines SBIVER
// unconditionally (guarded only against redefinition), so the constant is
// present for exactly as long as the plugin is.
define( 'SBIVER', '6.11.4' );

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

// A corrupted option can hold a non-scalar. Casting an array to string raises a
// conversion warning; casting an object without __toString throws. Either turns
// a malformed setting into a frontend error, so the type is rejected outright —
// and these two cases pin that it neither throws nor reads as permission.
$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => array( 'yes' ) ) );
sb_eq( $probe->smash_balloon_self_restricts(), false, 'an array value is rejected, not stringified' );

$GLOBALS['sb_options'] = array( 'sb_instagram_settings' => array( 'gdpr' => new stdClass() ) );
sb_eq( $probe->smash_balloon_self_restricts(), false, 'an object value is rejected without throwing' );

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
// Compare logic, not prose: run both sides through PHP's own tokenizer and drop
// comments before collapsing whitespace. A guard that also pinned the comments
// would fail on every clarification of the reasoning — which is the one kind of
// edit nobody should have to think twice about — while a naive strip of `//`
// would maul any string literal containing a URL.
$normalise = function ( $code ) {
	$out = '';
	foreach ( token_get_all( '<?php ' . $code ) as $token ) {
		if ( is_array( $token ) ) {
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] || T_OPEN_TAG === $token[0] ) {
				continue;
			}
			$out .= $token[1];
			continue;
		}
		$out .= $token;
	}
	return trim( preg_replace( '/\s+/', ' ', $out ) );
};
$copy_body = $normalise( "
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'faz_respect_smash_balloon_gdpr', true ) ) {
			return false;
		}
		if ( ! defined( 'SBIVER' ) && ! class_exists( 'SB_Instagram_GDPR_Integrations', false ) ) {
			return false;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		\$settings = get_option( 'sb_instagram_settings', array() );
		if ( ! is_array( \$settings ) || ! isset( \$settings['gdpr'] ) ) {
			return false;
		}
		if ( ! is_string( \$settings['gdpr'] ) ) {
			return false;
		}
		return 'yes' === strtolower( trim( \$settings['gdpr'] ) );
" );
sb_eq(
	false !== strpos( $normalise( $body ), $copy_body ),
	true,
	'the copy under test still matches the shipped method (drift guard)'
);

// --- the class arm, and where the exemption is applied ----------------------
// Only the SBIVER arm is exercised behaviourally above: satisfying the other
// one would mean declaring SB_Instagram_GDPR_Integrations in this process, and
// a class is no more removable than a constant. The drift guard already pins
// both arms textually, so assert the class arm here rather than leave a reader
// to infer it from a body comparison.
sb_eq(
	false !== strpos( $normalise( $body ), "class_exists( 'SB_Instagram_GDPR_Integrations', false )" ),
	true,
	'the loaded-class signal is accepted as an alternative to the version constant'
);

// The self-restriction must clear only the CATEGORY-level block and leave the
// per-service decision able to put it back. Removing the entry from
// $social_ids instead would skip that check entirely, so a visitor who
// explicitly denied smash-balloon-instagram would get the feed anyway while the
// preference-centre toggle showed it off. Pinned structurally because the
// difference is invisible in the blocked/not-blocked outcome that the rest of
// this suite can observe.
$social_pass = strpos( (string) $shipped, '$sb_self_restricts = $this->smash_balloon_self_restricts();' );
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

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( 0 === $failed ? 0 : 1 );
