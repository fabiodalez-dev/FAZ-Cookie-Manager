<?php
/**
 * `signals.gpp` is gone from the shipped rule sets and tolerated in custom ones.
 *
 * 1.34.2 removed the IAB GPP declaration the plugin did not implement. The
 * schema then rejected `signals.gpp` outright (`additionalProperties: false`),
 * which turned every custom rule set written for 1.34.1 — the documented
 * shape, `{ "enabled": true, "section": "usco" }` — into a validation failure.
 * Pinned here: no shipped rule set declares it, the schema accepts it in any
 * shape as a deprecated, ignored property, every other unknown signal is
 * still refused, and nothing at runtime reads it.
 *
 * Run: php tests/unit/test-ruleset-gpp-deprecated-php.php
 */
$root   = dirname( __DIR__, 2 );
$checks = 0;
$failed = 0;
function gpp_check( $ok, $label ) {
	global $checks, $failed;
	++$checks;
	if ( $ok ) {
		echo "  PASS {$label}\n";
		return;
	}
	++$failed;
	echo "  FAIL {$label}\n";
}

$schema  = json_decode( (string) file_get_contents( $root . '/admin/modules/geo-routing/schemas/ruleset.schema.json' ), true );
$signals = $schema['properties']['signals'] ?? array();
$gpp     = $signals['properties']['gpp'] ?? null;

gpp_check( is_array( $gpp ), 'the schema knows signals.gpp' );
gpp_check( is_array( $gpp ) && true === ( $gpp['deprecated'] ?? null ), 'and marks it deprecated' );
gpp_check( is_array( $gpp ) && array() === array_diff( array_keys( $gpp ), array( 'deprecated', 'description' ) ), 'with no type or shape constraint, so the 1.34.1 object { enabled, section } validates' );
gpp_check( ! in_array( 'gpp', (array) ( $signals['required'] ?? array() ), true ), 'and does not require it' );
gpp_check( false === ( $signals['additionalProperties'] ?? null ), 'every other unknown signal is still refused' );

$shipped = glob( $root . '/admin/modules/geo-routing/rulesets/*.json' );
$with    = array();
foreach ( (array) $shipped as $file ) {
	if ( '_' === basename( $file )[0] ) {
		continue;
	}
	$ruleset = json_decode( (string) file_get_contents( $file ), true );
	if ( isset( $ruleset['signals'] ) && array_key_exists( 'gpp', (array) $ruleset['signals'] ) ) {
		$with[] = basename( $file );
	}
}
gpp_check( count( (array) $shipped ) > 40 && array() === $with, 'no shipped rule set declares signals.gpp (' . implode( ', ', $with ) . ')' );

$runtime = '';
foreach ( array( '/frontend/includes/class-geo-runtime.php', '/frontend/class-frontend.php', '/admin/modules/geo-routing/includes/class-ruleset-loader.php', '/admin/modules/geo-routing/includes/class-ruleset-resolver.php' ) as $path ) {
	$runtime .= (string) file_get_contents( $root . $path );
}
gpp_check( ! preg_match( "/\\[\\s*'signals'\\s*\\]\\s*\\[\\s*'gpp'\\s*\\]/", $runtime ), 'nothing at runtime reads signals.gpp' );

echo "ruleset-gpp-deprecated: " . ( $checks - $failed ) . " passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
