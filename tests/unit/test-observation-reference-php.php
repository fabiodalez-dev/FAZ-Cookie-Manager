<?php
/**
 * The two guards that decide what a persisted observation MEANS.
 *
 * A browser-scan observation sits in user meta for the length of a crawl, so
 * anything that reads it later has to answer two questions the row alone does
 * not: which URL was this, and when was it seen. Both were answered wrongly.
 *
 * `observation_url()` exists because `home_url( $request_path )` double-prefixed
 * the subdirectory on an install in one — REQUEST_URI's path already contains
 * it, and core appends to `get_option('home')` verbatim — so `set_cookie_scope()`
 * derived an RFC 6265 default-path of '/blog/blog/shop' and persisted it.
 *
 * `observation_reference_time()` exists because `Expires` is absolute: it means
 * what it meant when the header arrived. Judged against the current clock on a
 * later request, a cookie legitimately set to expire inside the crawl window
 * was reclassified as a clearing directive and deleted — vanishing from the
 * public declaration, which is the one direction that matters for compliance.
 *
 * The two negatives here are the load-bearing ones. A corrupt `observed_at` of
 * 0 must NOT be honoured: read as the epoch it makes every `Expires` look like
 * the future, so a genuine clearing directive survives as a phantom active
 * cookie — the exact inverse of the bug the reference time was added to fix.
 * And a request path of '//evil.com/x' must not survive the leading-slash
 * guard, or `set_cookie_scope()` derives a default-path of '//evil.com' and one
 * cookie splits into two rows.
 *
 * Both functions are lifted out of the shipped class rather than reimplemented:
 * a local copy would stay green while the real one drifted.
 *
 * Run: php tests/unit/test-observation-reference-php.php
 *
 * @package FazCookie\Tests\Unit
 */

$run    = 0;
$failed = 0;
function obs_check( $condition, $label ) {
	global $run, $failed;
	++$run;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}
	++$failed;
	echo "  [FAIL] {$label}\n";
}

// --- Core helpers the lifted bodies call ------------------------------------
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
$GLOBALS['faz_home'] = 'https://example.com/blog';
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return rtrim( $GLOBALS['faz_home'], '/' ) . $path;
	}
}

// --- Lift both methods out of the shipped class -----------------------------
$controller_path = dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';
$src             = (string) file_get_contents( $controller_path );

/**
 * Slice one method body out by brace-matching and re-emit it as a function.
 *
 * @param string $src       Class source.
 * @param string $signature Exact method signature line.
 * @param string $arg       Parameter name to expose.
 * @param string $as        Function name to emit.
 * @return string PHP source, or '' when the signature is gone.
 */
function obs_lift( $src, $signature, $arg, $as ) {
	$start = strpos( $src, $signature );
	if ( false === $start ) {
		return '';
	}
	$open  = strpos( $src, '{', $start );
	$depth = 0;
	$end   = -1;
	for ( $i = $open, $len = strlen( $src ); $i < $len; $i++ ) {
		if ( '{' === $src[ $i ] ) {
			++$depth;
		} elseif ( '}' === $src[ $i ] ) {
			--$depth;
			if ( 0 === $depth ) {
				$end = $i;
				break;
			}
		}
	}
	if ( -1 === $end ) {
		return '';
	}
	return "function {$as}( {$arg} ) {" . substr( $src, $open + 1, $end - $open - 1 ) . "}\n";
}

$lifted  = obs_lift( $src, 'public function observation_url( $request_path ) {', '$request_path', 'obs_url' );
$lifted .= obs_lift( $src, 'public function observation_reference_time( $observation ) {', '$observation', 'obs_ref' );

obs_check( false !== strpos( $lifted, 'function obs_url' ), 'observation_url() was found in the shipped class' );
obs_check( false !== strpos( $lifted, 'function obs_ref' ), 'observation_reference_time() was found in the shipped class' );
if ( $failed > 0 ) {
	echo "\n{$run} checks, {$failed} failed — a method was renamed or removed; repoint the extractor.\n";
	exit( 1 );
}

// tempnam() creates the file it names, so reserve then rename — appending
// '.php' to the returned path would leave the original behind on every run.
$reserved = tempnam( sys_get_temp_dir(), 'faz-obs-' );
$tmp      = $reserved . '.php';
rename( $reserved, $tmp );
file_put_contents( $tmp, "<?php\n{$lifted}" );
require_once $tmp;
unlink( $tmp );

echo "== observation_url() builds from the site ROOT, not the home path ==\n";

obs_check(
	'https://example.com/blog/shop/x' === obs_url( '/blog/shop/x' ),
	'a subdirectory install no longer doubles the subdirectory'
);
obs_check(
	'https://example.com/' === obs_url( '/' ),
	'the site root maps to the root'
);
obs_check(
	'https://example.com/shop/x' === obs_url( 'shop/x' ),
	'a missing leading slash is added'
);
obs_check(
	'https://example.com/' === obs_url( '' ),
	'an empty path becomes the root rather than a bare host'
);

// The load-bearing negative: '//evil.com/x' passes a naive leading-slash guard
// untouched, and set_cookie_scope() then derives '//evil.com' as the default
// path. Nothing fetches the URL, so this is a malformed capture key rather than
// request forgery — but a malformed key still splits one cookie into two rows.
obs_check(
	'https://example.com/evil.com/x' === obs_url( '//evil.com/x' ),
	'a protocol-relative-looking path is collapsed to a single slash'
);

$GLOBALS['faz_home'] = 'https://example.com';
obs_check(
	'https://example.com/shop/x' === obs_url( '/shop/x' ),
	'a root install is unchanged — the fix must be a no-op there'
);
$GLOBALS['faz_home'] = 'https://example.com:8080/blog';
obs_check(
	'https://example.com:8080/blog/shop' === obs_url( '/blog/shop' ),
	'a non-default port is preserved'
);
$GLOBALS['faz_home'] = 'https://example.com/blog';

echo "== observation_reference_time() honours a real timestamp ==\n";

$now = time();
obs_check( $now - 3600 === obs_ref( array( 'observed_at' => $now - 3600 ) ), 'a real observed_at is used verbatim' );
obs_check( 1700000000 === obs_ref( array( 'observed_at' => '1700000000' ) ), 'a numeric-string observed_at is cast to int' );

echo "== ...and refuses the values that would invert the bug ==\n";

// isset() alone would let these through. A reference of 0 makes every Expires
// look like the future, so a genuine clearing directive is never recognised.
obs_check( obs_ref( array( 'observed_at' => 0 ) ) >= $now, 'a corrupt observed_at of 0 falls back to NOW, not the epoch' );
obs_check( obs_ref( array( 'observed_at' => -5 ) ) >= $now, 'a negative observed_at falls back to NOW' );
obs_check( obs_ref( array( 'observed_at' => '' ) ) >= $now, 'an empty observed_at falls back to NOW' );
obs_check( obs_ref( array() ) >= $now, 'an absent observed_at falls back to NOW — legacy rows still work' );

echo "== The persisted-row callsites pass a reference; the live ones do not ==\n";

// Source assertions, deliberately narrow. Which reference each callsite means
// is the whole point of the change and cannot be exercised without a booted
// WordPress, so pin the wiring instead. A persisted-row callsite reverted to
// the current clock reintroduces the deletion bug; a live-header callsite given
// a stale reference is a new one in the opposite direction.
obs_check(
	2 === substr_count( $src, 'set_cookie_is_deletion( $row, $controller->observation_reference_time( $row ) )' )
		+ substr_count( $src, 'set_cookie_is_deletion( $observation, $this->observation_reference_time( $observation ) )' ),
	'both persisted-row callsites pass the observation reference time'
);
obs_check(
	false !== strpos( $src, 'public function set_cookie_is_deletion( $parsed, $reference_time = null )' ),
	'the reference stays OPTIONAL so live-header callers are source-compatible'
);
obs_check(
	false !== strpos( $src, '$seen_at   = $this->observation_reference_time( $observation );' ),
	'the duration computation judges Expires against the same reference as the deletion check'
);

echo "\n{$run} checks, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
