<?php
/**
 * Providers that block without leaving a visible trace.
 *
 * Issue #279: a blocked embed shows a placeholder, so it announces itself. A
 * blocked stylesheet does not — the page renders in fallback fonts, or a
 * control appears not to work — and because these providers declare no cookies
 * they contribute no row to the cookie declaration either. Nothing anywhere
 * says a third-party request is being held back, so the symptom points at the
 * theme, the cache or the CDN. In #253 that cost days on both sides.
 *
 * Run: php tests/unit/test-silent-providers-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	require_once dirname( __DIR__, 2 ) . '/includes/class-known-providers.php';
	// Loaded so Known_Providers::get_silent_providers() can class_exists()
	// its way into the real embed check instead of silently skipping it.
	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-placeholder-builder.php';

	use FazCookie\Includes\Known_Providers;

	$passed = 0;
	$failed = 0;
	function sp_check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) { $passed++; echo "  [PASS] {$label}\n"; }
		else { $failed++; echo "  [FAIL] {$label}\n"; }
	}

	echo "== Silent providers ==\n";

	$all    = Known_Providers::get_all();
	$silent = Known_Providers::get_silent_providers();

	sp_check( count( $all ) > 300, 'the catalogue loads (' . count( $all ) . ' providers)' );
	sp_check( count( $silent ) > 0 && count( $silent ) < count( $all ), 'silent providers are a subset (' . count( $silent ) . ')' );

	// Every silent provider must have patterns (something is parked) and no
	// cookies (nothing is declared). Both halves are what makes it invisible.
	$bad = 0;
	foreach ( $silent as $provider ) {
		if ( ! empty( $provider['cookies'] ) || empty( $provider['patterns'] ) ) {
			$bad++;
		}
	}
	sp_check( 0 === $bad, 'every silent provider parks something and declares nothing' );

	// Google Fonts is the reported case and must be listed: it is blocked by
	// design (German case law), sets no cookies, and renders no placeholder.
	$labels = array();
	foreach ( $silent as $provider ) {
		$labels[] = $provider['label'];
	}
	sp_check( in_array( 'Google Fonts', $labels, true ), 'Google Fonts is listed' );

	// A provider that does set cookies must NOT be listed: it already appears in
	// the cookie declaration, so it is not invisible.
	$with_cookies = null;
	foreach ( $all as $provider ) {
		if ( ! empty( $provider['cookies'] ) ) {
			$with_cookies = $provider['label'];
			break;
		}
	}
	sp_check( null !== $with_cookies && ! in_array( $with_cookies, $labels, true ),
		'a provider that declares cookies is not listed (' . (string) $with_cookies . ')' );

	// An embed-only provider (no cookies, but rendered via Placeholder_Builder
	// as a blocked embed) must NOT be listed either: it DOES show a placeholder,
	// so it is not silent. Loom and Rumble both declare zero cookies in the
	// catalogue yet are video embeds (Placeholder_Builder::$video_services /
	// $url_service_map), so the old cookies-only predicate wrongly caught them.
	sp_check( ! in_array( 'Loom', $labels, true ), 'Loom (embed, no cookies) is not listed' );
	sp_check( ! in_array( 'Rumble', $labels, true ), 'Rumble (embed, no cookies) is not listed' );

	// Deliberately NOT asserted here: whether a given provider is exempted by
	// the site's whitelist. The real matcher (Frontend::matches_whitelist_pattern)
	// compares the concrete URL in the tag against the entry, host-anchored so a
	// look-alike domain cannot spoof it. Answering the same question from the
	// provider's own pattern is an approximation, and an approximation that says
	// "allowed" when the runtime blocks it would mislead precisely on the page
	// someone opens to find out what is happening.

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
