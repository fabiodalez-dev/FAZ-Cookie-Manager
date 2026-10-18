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

// No runtime code may read the key, however it gets there: a pattern for
// `['signals']['gpp']` missed `$signals = $ruleset['signals']; $signals['gpp']`.
// So the check is that no PHP file of the plugin, and no front-end script,
// names a `gpp` key at all: a quoted 'gpp' / "gpp" (array access or literal)
// in PHP, `.gpp` or ['gpp'] in JS.
$readers = array();
$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	$path = str_replace( '\\', '/', substr( (string) $file, strlen( $root ) ) );
	if ( preg_match( '#^/(?:tests|vendor|node_modules|\.git|graphify-out|\.code-review-graph)/#', $path ) ) {
		continue;
	}
	if ( preg_match( '/\.php$/', $path ) ) {
		$pattern = '/([\'"])gpp\1/';
	} elseif ( preg_match( '#^/frontend/js/[^/]+(?<!\.min)\.js$#', $path ) ) {
		$pattern = '/\.gpp\b|\[\s*([\'"])gpp\1\s*\]/';
	} else {
		continue;
	}
	if ( preg_match( $pattern, (string) file_get_contents( (string) $file ) ) ) {
		$readers[] = ltrim( $path, '/' );
	}
}
gpp_check( array() === $readers, 'nothing at runtime names a gpp key (' . implode( ', ', $readers ) . ')' );

echo "ruleset-gpp-deprecated: " . ( $checks - $failed ) . " passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
