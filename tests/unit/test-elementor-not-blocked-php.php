<?php
/**
 * Elementor's own assets must never be blocked automatically (issue #218).
 *
 * Reported on GitHub: Elementor lightboxes did not open until a visitor accepted
 * everything, because `elementor/assets/lib/share-link` shipped in the provider
 * database as a `functional` pattern. Declining consent blocked the script the
 * lightbox depends on, so a core page-builder feature broke for every visitor
 * who said no — and the reporter had to discover the whitelist to get it back.
 *
 * The entry was wrong on its own terms, and the shape of the wrongness is what
 * this file pins:
 *
 *   - The pattern addresses a FIRST-PARTY file, served from the site's own
 *     plugins directory. No request leaves the site when it loads.
 *   - The entry declared ZERO cookies. Under GDPR/ePrivacy the thing consent
 *     governs is storage and third-party transmission; a script that does
 *     neither has nothing to consent to.
 *   - share-link builds share URLs. It is a helper, not a tracker: the network
 *     is contacted only if the visitor clicks a share button, which is their
 *     own action and a navigation, not a background call.
 *
 * So blocking it bought no privacy at all and cost a working site. The relevant
 * asymmetry is the reverse of the usual one: over-blocking is normally the safe
 * error, but only when there is something to block. Here it was pure cost.
 *
 * Deliberately NOT generalised into "no provider may declare zero cookies", even
 * though that rule would have caught this: a dozen legitimate entries have the
 * same shape because they match the script HANDLE of a plugin that goes on to
 * load a real third party (Site Kit → Google, HubSpot, the Facebook pixel).
 * Removing blocking is the dangerous direction here, so the guard stays narrow
 * and evidence-led.
 *
 * Run: php tests/unit/test-elementor-not-blocked-php.php
 */

$tests_run = 0;
$failed    = 0;
function el_eq( $actual, $expected, $label ) {
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

$root      = dirname( __DIR__, 2 );
$providers = json_decode( (string) file_get_contents( $root . '/includes/data/known-providers.json' ), true );

// The floor matches the one at the foot of this file rather than a token
// "it parsed" number: the catalogue ships 337 entries, so a threshold of 100
// would still pass with two thirds of the providers accidentally dropped —
// and this assertion is what the rest of the file leans on to mean "the
// deletion below removed one entry, not a section".
el_eq( is_array( $providers ) && count( $providers ) > 300, true, 'the provider database loads' );

// 1. No entry may name an Elementor asset path.
$offenders = array();
foreach ( (array) $providers as $service_id => $service ) {
	foreach ( (array) ( isset( $service['patterns'] ) ? $service['patterns'] : array() ) as $pattern ) {
		if ( false !== stripos( (string) $pattern, 'elementor/assets' ) || false !== stripos( (string) $pattern, 'plugins/elementor/' ) ) {
			$offenders[] = $service_id . ' → ' . $pattern;
		}
	}
}
el_eq( $offenders, array(), "no provider blocks Elementor's own asset paths" );

// 2. The specific pattern from the report, matched the way the blocker matches:
//    substring against the script URL. A site running Elementor serves the file
//    from this path, so if any pattern is a substring of it the lightbox breaks
//    again exactly as reported.
$real_url  = '/wp-content/plugins/elementor/assets/lib/share-link/share-link.min.js';
$would_block = array();
foreach ( (array) $providers as $service_id => $service ) {
	foreach ( (array) ( isset( $service['patterns'] ) ? $service['patterns'] : array() ) as $pattern ) {
		$pattern = (string) $pattern;
		if ( '' !== $pattern && false !== stripos( $real_url, $pattern ) ) {
			$would_block[] = $service_id . ' → ' . $pattern;
		}
	}
}
el_eq( $would_block, array(), 'the reported lightbox script URL matches no blocking pattern' );

// 3. And no blocker template offers it either. A template is admin-initiated,
//    but one whose only pattern is a harmless first-party file — under a
//    description promising to block "embeds, widgets, or third-party content" —
//    is a trap: the admin gets a broken lightbox and no third party blocked.
$templates = glob( $root . '/admin/modules/cookies/includes/blocker-templates/*.json' );
$bad_templates = array();
foreach ( (array) $templates as $file ) {
	$data = json_decode( (string) file_get_contents( $file ), true );
	foreach ( (array) ( isset( $data['patterns'] ) ? $data['patterns'] : array() ) as $pattern ) {
		if ( false !== stripos( (string) $pattern, 'elementor/assets' ) ) {
			$bad_templates[] = basename( $file );
		}
	}
}
el_eq( $bad_templates, array(), 'no blocker template offers to block Elementor assets' );

// 4. The database must still be a real database — otherwise everything above is
//    green because there is nothing left to check.
el_eq( count( (array) $providers ) > 300, true, 'the rest of the provider database is intact' );

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( 0 === $failed ? 0 : 1 );
