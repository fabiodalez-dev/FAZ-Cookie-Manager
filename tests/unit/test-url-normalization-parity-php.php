<?php
/**
 * The server half of the URL-normalization parity contract.
 *
 * Scanner_Controller::normalize_url() must produce byte-identical output to
 * scan-engine.js's normalizeUrl(). The JS half of this table lives in
 * tests/unit/js/scan-engine-normalize-parity.test.mjs and reads the SAME
 * fixture, so neither runner can be made green by editing the other side.
 *
 * Why this exists: normalize_url() stopped force-appending a trailing slash to
 * file-like paths, and the client kept its old rule. deduplicateUrls() applies
 * the client rule to server-supplied URLs, so on a /%postname%.html permalink
 * structure discover returned '/x.html', the browser crawled '/x.html/',
 * reported the slashed form, and sanitize_scanned_urls() re-queued it — the
 * same page captured under two keys, with the server-side change undone before
 * anything was fetched. The suite had no assertion on either side.
 *
 * The function body is lifted out of the shipped class rather than
 * reimplemented here: a local copy would stay green while the real one
 * diverged, which is precisely the failure this test exists to catch. The
 * class itself cannot simply be required — it needs a booted WordPress — so
 * the body is extracted and given the three core helpers it calls.
 *
 * Run: php tests/unit/test-url-normalization-parity-php.php
 *
 * @package FazCookie\Tests\Unit
 */

$run    = 0;
$failed = 0;
function parity_check( $condition, $label ) {
	global $run, $failed;
	++$run;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}
	++$failed;
	echo "  [FAIL] {$label}\n";
}

// --- WordPress helpers the extracted body calls -----------------------------
// Behaviour mirrors core: trailingslashit strips any existing trailing slashes
// then appends exactly one, and wp_parse_url is parse_url for our purposes.
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $string ) {
		return rtrim( $string, '/\\' );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return untrailingslashit( $string ) . '/';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

// --- Lift normalize_url()'s body out of the shipped class -------------------
$controller_path = dirname( __DIR__, 2 ) . '/admin/modules/scanner/includes/class-controller.php';
$src             = (string) file_get_contents( $controller_path );

$signature = 'public function normalize_url( $url ) {';
$start     = strpos( $src, $signature );
if ( false === $start ) {
	echo "  [FAIL] normalize_url() was not found in class-controller.php — it was renamed or\n";
	echo "         removed. Point this extractor at the new name; do not delete the parity check.\n";
	echo "\n1 checks, 1 failed\n";
	exit( 1 );
}

// Brace-match from the signature's opening brace so a nested block inside the
// body cannot terminate the slice early.
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
	echo "  [FAIL] could not brace-match normalize_url() — the source is malformed\n";
	echo "\n1 checks, 1 failed\n";
	exit( 1 );
}

$body = substr( $src, $open + 1, $end - $open - 1 );

// Materialise the body as a plain function in a temp file and require it.
// Writing and requiring beats eval(): same faithfulness, and it leaves no
// eval() in the repository for every future security sweep to re-flag.
// tempnam() CREATES the file it names, so appending '.php' would write and
// remove a different path and leave the original behind on every run. Reserve
// the name, then rename it into place.
$tmp_reserved = tempnam( sys_get_temp_dir(), 'faz-parity-' );
$tmp          = $tmp_reserved . '.php';
rename( $tmp_reserved, $tmp );
file_put_contents( $tmp, "<?php\nfunction faz_parity_normalize_url( \$url ) {\n{$body}\n}\n" );
require_once $tmp;
unlink( $tmp );

// --- The shared table -------------------------------------------------------
$fixture_path = __DIR__ . '/fixtures/url-normalization-parity.json';
$fixture      = json_decode( (string) file_get_contents( $fixture_path ), true );
parity_check( is_array( $fixture ) && ! empty( $fixture['cases'] ), 'the shared fixture loaded' );

echo "== normalize_url() matches the shared parity table ==\n";

foreach ( $fixture['cases'] as $case ) {
	$actual = faz_parity_normalize_url( $case['input'] );
	parity_check(
		$case['expected'] === $actual,
		"{$case['input']} -> {$case['expected']}" .
			( $case['expected'] === $actual ? '' : " (got: {$actual})" )
	);
}

echo "== Normalization is idempotent ==\n";

// Both sides re-normalize URLs the other has already normalized
// (deduplicateUrls on server-supplied lists, sanitize_scanned_urls on
// client-reported ones), so a non-idempotent rule would drift on every hop.
foreach ( $fixture['cases'] as $case ) {
	$once = faz_parity_normalize_url( $case['input'] );
	parity_check(
		$once === faz_parity_normalize_url( $once ),
		"idempotent for {$case['input']}"
	);
}

echo "== The leading-dot exclusion is present in the shipped source ==\n";

// A behavioural table alone would not catch a rewrite that agrees on these
// inputs by a different rule. The exclusion is the subtle half of the
// contract — it is what keeps /.well-known/ a directory — so pin it.
parity_check(
	false !== strpos( $src, "0 !== strpos( \$last_segment, '.' )" ),
	'the leading-dot exclusion is still in normalize_url()'
);

echo "\n{$run} checks, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
