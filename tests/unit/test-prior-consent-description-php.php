<?php
/**
 * The `prior_consent` category flag must be described with the polarity the
 * code gives it, and the server/client split must be declared.
 *
 * In code, prior_consent = true means "granted before any consent action":
 * script.js seeds the category "yes" and pre-ticks its toggle, while the
 * server-side blocker never reads the flag and keeps the category blocked under
 * an opt-in law. The REST schema said the opposite ("Whether prior consent is
 * required") and README.md told admins to set it OFF for a Garante-exempt
 * category — the one case where ON is the intended value. Reading either text,
 * an admin PUT {"prior_consent": true} on Analytics believing it would require
 * consent, and got a pre-ticked, pre-granted category instead.
 *
 * Source-level assertions on purpose: the field is REST/import-only (the
 * admin UI never sends it), so the documentation IS the interface.
 *
 * Run: php tests/unit/test-prior-consent-description-php.php
 */

$root = dirname( __DIR__, 2 );
$api  = file_get_contents( $root . '/admin/modules/cookies/api/class-categories-api.php' );
$doc  = file_get_contents( $root . '/README.md' );
$fe   = file_get_contents( $root . '/frontend/class-frontend.php' );

$passed = 0;
$failed = 0;
function pc_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { $passed++; echo "  PASS {$label}\n"; return; }
	$failed++;
	echo "  FAIL {$label}\n";
}

// REST schema: the description of the `prior_consent` property.
$schema_ok = preg_match( "/'prior_consent'\s*=>\s*array\(\s*'description'\s*=>\s*__\(\s*'([^']*)'/s", $api, $m );
pc_ok( $schema_ok, 'the categories REST schema declares prior_consent with a translatable description' );
$description = $schema_ok ? $m[1] : '';
pc_ok( false === stripos( $description, 'is required' ), 'REST: the description no longer says consent "is required" (inverted polarity)' );
pc_ok( false !== stripos( $description, 'before any consent action' ), 'REST: true is described as granting the category before any consent action' );
pc_ok( false !== stripos( $description, 'client-side only' ), 'REST: the client-only effect is declared' );
pc_ok( false !== stripos( $description, 'server-side blocker' ), 'REST: the server-side blocker is declared to keep blocking under an opt-in law' );

// README: the feature line.
$line = '';
foreach ( preg_split( '/\R/', $doc ) as $candidate ) {
	if ( false !== strpos( $candidate, '`prior_consent`' ) ) { $line = $candidate; break; }
}
pc_ok( '' !== $line, 'README.md documents the prior_consent flag' );
pc_ok( false === stripos( $line, 'Set to OFF' ), 'README: no longer tells admins to set it OFF for the exempt category' );
pc_ok( false !== stripos( $line, 'ON means' ) && false !== stripos( $line, 'granted before any consent action' ), 'README: ON is explained as pre-granting the category' );
pc_ok( false !== stripos( $line, 'client-side only' ) && false !== stripos( $line, 'server-side blocker' ), 'README: the client/server split is declared' );
pc_ok( false !== stripos( $line, 'Garante' ), 'README: the Garante exemption use case is kept' );

// Server: the pre-consent branch states that it deliberately ignores the flag.
pc_ok( false !== strpos( $fe, '`prior_consent` flag is deliberately NOT read' ), 'get_blocked_categories() documents that prior_consent is deliberately not consulted server-side' );

echo "prior-consent-description: {$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
