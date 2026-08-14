<?php
/**
 * Regression checks for the built-in script-blocking whitelist boundaries.
 *
 * Default exceptions must cover only the plugin/WordPress infrastructure, not
 * entire third-party plugin directories or external CAPTCHA domains. A known
 * provider embedded in a page-builder asset must therefore remain blockable.
 *
 * Run: php tests/unit/test-default-whitelist-boundaries-php.php
 *
 * @package FazCookie\Tests\Unit
 */

$root       = dirname( __DIR__, 2 );
$frontend   = (string) file_get_contents( $root . '/frontend/class-frontend.php' );
$settings   = (string) file_get_contents( $root . '/admin/modules/settings/includes/class-settings.php' );
$passed     = 0;
$failed     = 0;

function whitelist_boundary_check( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  [PASS] {$label}\n";
	} else {
		$failed++;
		echo "  [FAIL] {$label}\n";
	}
}

echo "== Default whitelist boundaries ==\n";
foreach ( array( 'plugins/elementor/', 'plugins/contact-form-7/', 'plugins/woocommerce/', 'plugins/wp-rocket/' ) as $broad_pattern ) {
	whitelist_boundary_check( false === strpos( $frontend, "'{$broad_pattern}'" ), "built-in whitelist excludes {$broad_pattern}" );
}
foreach ( array( 'www.google.com/recaptcha/api', 'www.gstatic.com/recaptcha/', 'challenges.cloudflare.com/', 'hcaptcha.com/' ) as $external_pattern ) {
	whitelist_boundary_check( false === strpos( $settings, "'{$external_pattern}'" ), "settings do not pre-allow {$external_pattern}" );
}
whitelist_boundary_check( false !== strpos( $frontend, "'faz-cookie-manager'" ), 'plugin-owned frontend assets remain protected from self-blocking' );
whitelist_boundary_check( false !== strpos( $frontend, "'wp-includes/'" ), 'WordPress core assets remain protected from false positives' );

echo "Passed: {$passed}; Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
