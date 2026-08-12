<?php
/**
 * Unit test — the one-time migration that completes the reCAPTCHA whitelist
 * on installs that predate 1.17.2.
 *
 * reCAPTCHA needs two hosts to work: the API endpoint on www.google.com and
 * the widget script on www.gstatic.com. Older installs were seeded with the
 * first only, so the endpoint is allowed before consent and the script it
 * pulls is blocked — the CAPTCHA never renders and the form behind it cannot
 * be submitted. The shipped defaults have carried both since 1.17.2, but a
 * stored whitelist is never revisited, so upgrading alone never fixed it.
 *
 * What is actually asserted here is the RESTRAINT, not the addition. The
 * migration must add the pattern only where the site has already said it
 * wants reCAPTCHA to run before consent, must never remove anything, and must
 * never run twice — because the second run is the one that would overrule an
 * admin who removed the entry on purpose. Whitelisting is the act of
 * switching off consent enforcement for a URL; a migration that widens it on
 * a guess is a migration that quietly grants what nobody agreed to.
 *
 * Self-contained: WordPress is stubbed, so this runs under a bare `php`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['faz_test_options'] = array();
$GLOBALS['faz_test_writes']  = 0;

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_options'] )
			? $GLOBALS['faz_test_options'][ $name ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_test_options'][ $name ] = $value;
		$GLOBALS['faz_test_writes']++;
		return true;
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-activator.php';

$passed = 0;
$failed = 0;
function rg_eq( $actual, $expected, $label ) {
	global $passed, $failed;
	if ( $actual === $expected ) {
		$passed++;
		echo "  \033[32mPASS\033[0m {$label}\n";
	} else {
		$failed++;
		echo "  \033[31mFAIL\033[0m {$label}\n";
		echo "        expected: " . var_export( $expected, true ) . "\n";
		echo "        actual:   " . var_export( $actual, true ) . "\n";
	}
}

/**
 * Put a site into a known state and run the migration once.
 *
 * @param array|false $patterns Stored whitelist, or false for no settings row.
 * @param bool        $already  Whether the migration has already run here.
 * @return array|false Resulting whitelist, or false when no settings row exists.
 */
function rg_run( $patterns, $already = false ) {
	$GLOBALS['faz_test_options'] = array();
	$GLOBALS['faz_test_writes']  = 0;
	if ( false !== $patterns ) {
		$GLOBALS['faz_test_options']['faz_settings'] = array(
			'script_blocking' => array( 'whitelist_patterns' => $patterns ),
		);
	}
	if ( $already ) {
		$GLOBALS['faz_test_options']['faz_recaptcha_gstatic_migrated'] = 'done';
	}
	\FazCookie\Includes\Activator::add_recaptcha_gstatic_pattern();
	$settings = get_option( 'faz_settings' );
	return is_array( $settings ) ? $settings['script_blocking']['whitelist_patterns'] : false;
}

echo "reCAPTCHA gstatic whitelist migration\n\n";

// 1-2. The case the migration exists for: the endpoint is whitelisted, its
// script is not.
$out = rg_run( array( 'www.google.com/recaptcha/api', 'hcaptcha.com/' ) );
rg_eq( in_array( 'www.gstatic.com/recaptcha/', $out, true ), true, 'the widget script is whitelisted alongside the endpoint' );
rg_eq( in_array( 'hcaptcha.com/', $out, true ), true, 'and every unrelated pattern survives untouched' );

// 3. Already complete — no duplicate.
$out = rg_run( array( 'www.google.com/recaptcha/api', 'www.gstatic.com/recaptcha/' ) );
rg_eq( count( $out ), 2, 'a whitelist that already names gstatic is left exactly as it was' );

// 4. Loose matching: an admin who typed the host differently has still
// decided, and must not be given a near-duplicate.
$out = rg_run( array( 'https://www.google.com/recaptcha/api.js', 'gstatic.com/recaptcha' ) );
rg_eq( count( $out ), 2, 'a differently-spelled gstatic entry counts as already decided' );

// 5. THE RESTRAINT. No reCAPTCHA on this site: adding the pattern would
// switch off pre-consent blocking for a host nobody asked to allow.
$out = rg_run( array( 'challenges.cloudflare.com/' ) );
rg_eq( $out, array( 'challenges.cloudflare.com/' ), 'a site not using reCAPTCHA is not widened' );

// 6. An emptied whitelist is a decision too, not an absence.
$out = rg_run( array() );
rg_eq( $out, array(), 'a deliberately emptied whitelist stays empty' );

// 7-8. Once per site, ever. The marker is what stops a later
// MIGRATIONS_VERSION bump from re-adding what an admin has since removed.
$out = rg_run( array( 'www.google.com/recaptcha/api' ), true );
rg_eq( $out, array( 'www.google.com/recaptcha/api' ), 'a site already migrated is not touched again' );
rg_eq( get_option( 'faz_recaptcha_gstatic_migrated' ), 'done', 'and its marker is left in place' );

// 9-10. The marker is written even when there is nothing to do, so a broken
// or missing settings row is not reconsidered on every admin load forever.
rg_run( false );
rg_eq( get_option( 'faz_recaptcha_gstatic_migrated' ), 'done', 'a site with no settings row is still marked done' );
$out = rg_run( 'not-an-array' );
rg_eq( get_option( 'faz_recaptcha_gstatic_migrated' ), 'done', 'and so is one whose whitelist is not a list' );

// 11. Malformed entries must not crash the admin load.
$out = rg_run( array( 'www.google.com/recaptcha/api', null, 42, array( 'nested' ) ) );
rg_eq( in_array( 'www.gstatic.com/recaptcha/', $out, true ), true, 'non-string entries are skipped rather than fatal' );

// 12. It writes once, not once per pattern.
rg_run( array( 'www.google.com/recaptcha/api' ) );
rg_eq( $GLOBALS['faz_test_writes'], 2, 'exactly two writes: the marker and the settings row' );

// The shipped defaults carry both halves of the reCAPTCHA entry — which is
// what makes the missing one on older installs a gap rather than a policy.
$settings_src = (string) file_get_contents( $root . '/admin/modules/settings/includes/class-settings.php' );
rg_eq( false !== strpos( $settings_src, 'www.google.com/recaptcha/api' ), true, 'the shipped defaults whitelist the reCAPTCHA endpoint' );
rg_eq( false !== strpos( $settings_src, 'www.gstatic.com/recaptcha/' ), true, 'and the widget script too' );

echo "\n" . ( $failed === 0 ? "\033[32m" : "\033[31m" ) . "{$passed} passed, {$failed} failed\033[0m\n";
exit( 0 === $failed ? 0 : 1 );
