<?php
/**
 * The runtime build marker must carry the release it ships with.
 *
 * script.js prints a one-time console warning, and System Status a ❌, when
 * the numeric prefix of `_FAZ_BUILD` differs from FAZ_VERSION: that is how a
 * plugin directory mixing files from two releases (an interrupted update, a
 * second copy of the plugin, a stale bundle) is told apart from a healthy one.
 * The comparison is only meaningful if every release keeps the two in step —
 * and before this test existed they drifted: 1.34.3 shipped a bundle marked
 * 1.34.2, 1.28.1 through 1.32.1 one marked 1.27.0. A release that left
 * script.js untouched would have warned every correctly installed site to
 * reinstall. scripts/bump-version.sh now rewrites the prefix with the rest of
 * the version sites; this pins that nothing ships with the two apart, in the
 * source and in the minified bundle the CI build gate keeps current.
 *
 * Run: php tests/unit/test-build-marker-version-php.php
 *
 * @package FazCookie\Tests\Unit
 */

$root   = dirname( __DIR__, 2 );
$passed = 0;
$failed = 0;
function marker_check( $cond, $label ) {
	global $passed, $failed;
	if ( $cond ) {
		$passed++;
		echo "  [PASS] {$label}\n";
	} else {
		$failed++;
		echo "  [FAIL] {$label}\n";
	}
}

$main = (string) file_get_contents( $root . '/faz-cookie-manager.php' );
// The grammar build-release.sh accepts: X.Y.Z, or a pre-release X.Y.Z-rcN.
preg_match( "/define\\( 'FAZ_VERSION', '([0-9]+\\.[0-9]+\\.[0-9]+(?:-(?:rc|beta|alpha)\\.?[0-9]+)?)' \\);/", $main, $m );
$version = isset( $m[1] ) ? $m[1] : '';
marker_check( '' !== $version, "FAZ_VERSION is declared in faz-cookie-manager.php ({$version})" );

foreach ( array( 'frontend/js/script.js', 'frontend/js/script.min.js' ) as $file ) {
	$source = (string) file_get_contents( $root . '/' . $file );
	$found  = preg_match( '/_FAZ_BUILD\s*=\s*["\']([0-9]+\.[0-9]+\.[0-9]+(?:-(?:rc|beta|alpha)\.?[0-9]+)?)\+([A-Za-z0-9._+-]+)["\']/', $source, $b );
	marker_check( 1 === $found, "{$file} declares _FAZ_BUILD as <version>+<suffix>" );
	marker_check( $found && $b[1] === $version, "{$file}: _FAZ_BUILD prefix {$b[1]} equals FAZ_VERSION {$version} (bump it with scripts/bump-version.sh, then npm run build:min)" );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
