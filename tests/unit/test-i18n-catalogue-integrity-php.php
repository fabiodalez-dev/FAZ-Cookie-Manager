<?php
/**
 * Standalone integrity checks for the shipped translation catalogues.
 *
 * WordPress loads the compiled `.mo` and never reads the `.po`. Nothing in this
 * repository connected the two: a contributor could hand-edit a `.po`, commit
 * without recompiling, and every suite plus both GitHub workflows would stay
 * green while the stale binary shipped. The failure is invisible in review
 * precisely because reviewers read the `.po` diff — which shows the corrected
 * string — while translated sites keep rendering the old one.
 *
 * What is asserted here, per locale:
 *
 *   1. `msgfmt --check-format --check-header --check-domain` accepts the `.po`.
 *      This is the one that can bite a visitor: a printf placeholder that
 *      disagrees with its msgid (`%1$s` dropped, `%d` turned into `%s`) is a
 *      runtime error or a mislabelled consent string, not a typo.
 *   2. The shipped `.mo` decodes to exactly the same entry map as a fresh
 *      compile of the committed `.po` — no missing, extra or differing entry.
 *   3. Every `.po` has a `.mo` beside it, since a catalogue with no binary is a
 *      translation that can never load.
 *
 * Deliberately NOT asserted: byte-identity between the shipped `.mo` and a
 * local `msgfmt` run. The `.mo` format carries a hash table whose size and
 * layout are an implementation detail of the compiling gettext, so byte-compare
 * turns a contributor's gettext version into a test failure. Comparing decoded
 * maps pins the property that actually matters and is version-independent.
 *
 * Run: php tests/unit/test-i18n-catalogue-integrity-php.php
 */

$tests_run = 0;
$failed    = 0;

function cat_eq( $actual, $expected, $label ) {
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

/**
 * Decode a binary `.mo` into an ordered map of key => translation.
 *
 * Keys are kept as the raw bytes gettext stores them, so a context-qualified
 * entry ("ctxt\x04msgid") and a plural entry ("singular\x00plural") stay
 * distinct from their unqualified namesakes without any interpretation on our
 * part — which is what makes this comparison exact rather than approximate.
 *
 * @param string $path Path to the .mo file.
 * @return array|string Map on success, error string on failure.
 */
function faz_decode_mo( $path ) {
	$blob = @file_get_contents( $path );
	if ( false === $blob || strlen( $blob ) < 28 ) {
		return 'unreadable or truncated';
	}

	$magic = substr( $blob, 0, 4 );
	if ( "\xde\x12\x04\x95" === $magic ) {
		$fmt = 'V'; // little endian
	} elseif ( "\x95\x04\x12\xde" === $magic ) {
		$fmt = 'N'; // big endian
	} else {
		return 'bad magic (not a .mo file)';
	}

	$header = unpack( $fmt . '5', substr( $blob, 4, 20 ) );
	$count  = $header[2];
	$o_orig = $header[3];
	$o_tran = $header[4];

	if ( $o_orig + ( 8 * $count ) > strlen( $blob ) || $o_tran + ( 8 * $count ) > strlen( $blob ) ) {
		return 'string tables run past end of file';
	}

	$map = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$o = unpack( $fmt . '2', substr( $blob, $o_orig + ( $i * 8 ), 8 ) );
		$t = unpack( $fmt . '2', substr( $blob, $o_tran + ( $i * 8 ), 8 ) );
		if ( $o[2] + $o[1] > strlen( $blob ) || $t[2] + $t[1] > strlen( $blob ) ) {
			return 'string entry runs past end of file';
		}
		$key = substr( $blob, $o[2], $o[1] );
		$val = substr( $blob, $t[2], $t[1] );
		$map[ $key ] = $val;
	}
	ksort( $map );

	return $map;
}

echo "Translation catalogue integrity (.po <-> shipped .mo)\n\n";

$languages = dirname( __DIR__, 2 ) . '/languages';
// glob() returns false on an unreadable directory, not an empty array, and
// sort( false ) is a TypeError on PHP 8. Coercing here means a missing
// languages/ directory fails the assertion below on its own terms instead of
// crashing the runner with a type error that names neither the directory nor
// the reason.
$pos = glob( $languages . '/*.po' );
$pos = is_array( $pos ) ? $pos : array();
sort( $pos );

cat_eq( count( $pos ) > 0, true, 'at least one .po catalogue is present' );

// The whole point is comparing against a compile, so without msgfmt this suite
// can only report that it did not run.
//
// Locally that is a fair skip: not every machine has gettext, and refusing to
// run the other assertions would help nobody. On a build machine it is the
// opposite — a suite that exits 0 having verified nothing is indistinguishable
// from one that verified everything, which is the failure this file exists to
// prevent. So the skip is tolerated where a human reads the output and is a
// hard failure where nobody does.
exec( 'command -v msgfmt', $which, $has_msgfmt );
if ( 0 !== $has_msgfmt ) {
	$unattended = false !== getenv( 'CI' ) || false !== getenv( 'FAZ_REQUIRE_MSGFMT' );
	if ( $unattended ) {
		echo "  \033[31mFAIL\033[0m msgfmt not installed — install gettext; this suite cannot verify anything without it\n";
	} else {
		echo "  \033[33mSKIP\033[0m msgfmt not installed — catalogues not verified against a compile\n";
		echo "         (set FAZ_REQUIRE_MSGFMT=1 to make this a failure)\n";
	}
	echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
	exit( ( $unattended || $failed > 0 ) ? 1 : 0 );
}

$tmp = sys_get_temp_dir() . '/faz-catalogue-check-' . getmypid() . '.mo';

// `wp i18n make-pot` derives the support-forum slug in Report-Msgid-Bugs-To
// from the NAME OF THE DIRECTORY it runs in, not from the plugin header. Run it
// from a scratch worktree — which is the normal way to work on two branches at
// once — and every catalogue silently ships a 404 as the address translators are
// invited to report bugs to. The command succeeds and the diff reads like a
// routine header bump, so nothing but an assertion catches it.
//
// The expected slug is taken from the plugin's own Text Domain header: it is the
// one source that does not move with the checkout, which is precisely the
// variable that causes this.
$plugin_php = dirname( __DIR__, 2 ) . '/faz-cookie-manager.php';
$domain     = '';
if ( preg_match( '/^\s*\*\s*Text Domain:\s*(\S+)/mi', (string) @file_get_contents( $plugin_php ), $m ) ) {
	$domain = $m[1];
}
cat_eq( '' !== $domain, true, 'the plugin declares a Text Domain to check catalogue headers against' );

// The header has to be reassembled before it can be read. PO wraps long values
// across adjacent quoted lines, and Poedit does exactly that to this one — the
// Czech catalogue carries it as "…/plugin/faz-cookie-" + "manager\n". Matching
// line by line would skip precisely the catalogues a translation tool has
// touched, which are the ones worth checking. And a skip here is never silent:
// a guard that quietly declines to run reads as a pass.
$pots = glob( $languages . '/*.pot' );
foreach ( array_merge( $pos, is_array( $pots ) ? $pots : array() ) as $catalogue ) {
	$name  = basename( $catalogue );
	$lines = explode( "\n", (string) @file_get_contents( $catalogue ) );
	$joined = '';
	$in_header = false;
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( ! $in_header ) {
			if ( 'msgstr ""' === $line ) {
				$in_header = true;
			}
			continue;
		}
		if ( '' === $line || '"' !== substr( $line, 0, 1 ) ) {
			break; // end of the header entry
		}
		$joined .= substr( $line, 1, -1 );
	}

	if ( ! preg_match( '#Report-Msgid-Bugs-To:\s*(\S+?)\\\\n#', $joined, $m ) ) {
		cat_eq( false, true, "{$name} — a Report-Msgid-Bugs-To header could be read" );
		continue;
	}
	$slug = basename( rtrim( $m[1], '/' ) );
	cat_eq( $slug, $domain, "{$name} — the bug-report URL points at this plugin, not the build directory" );
}

foreach ( $pos as $po ) {
	$name = basename( $po );
	$mo   = substr( $po, 0, -3 ) . '.mo';

	// 1. The catalogue itself must be well formed.
	$out  = array();
	$code = 0;
	exec( 'msgfmt --check-format --check-header --check-domain -o /dev/null ' . escapeshellarg( $po ) . ' 2>&1', $out, $code );
	cat_eq( $code, 0, "{$name} — msgfmt --check-* accepts the catalogue" . ( 0 === $code ? '' : ': ' . implode( ' | ', $out ) ) );

	// 2. A .po with no .mo never reaches a visitor.
	if ( ! file_exists( $mo ) ) {
		cat_eq( false, true, "{$name} — a compiled .mo exists beside the .po" );
		continue;
	}
	cat_eq( true, true, "{$name} — a compiled .mo exists beside the .po" );

	// 3. The shipped binary must carry what the committed source says.
	$out  = array();
	$code = 0;
	exec( 'msgfmt -o ' . escapeshellarg( $tmp ) . ' ' . escapeshellarg( $po ) . ' 2>&1', $out, $code );
	if ( 0 !== $code ) {
		cat_eq( false, true, "{$name} — the .po compiles" );
		continue;
	}

	$shipped = faz_decode_mo( $mo );
	$fresh   = faz_decode_mo( $tmp );

	if ( is_string( $shipped ) || is_string( $fresh ) ) {
		cat_eq( false, true, "{$name} — both .mo files decode (" . ( is_string( $shipped ) ? "shipped: {$shipped}" : "fresh: {$fresh}" ) . ')' );
		continue;
	}

	$missing = array_diff_key( $fresh, $shipped );
	$extra   = array_diff_key( $shipped, $fresh );
	$differ  = array();
	foreach ( $fresh as $k => $v ) {
		if ( isset( $shipped[ $k ] ) && $shipped[ $k ] !== $v ) {
			$differ[] = $k;
		}
	}

	// Report the counts rather than a bare bool: "3 entries differ" tells the
	// contributor they forgot msgfmt; "false !== true" tells them nothing.
	cat_eq(
		sprintf( 'missing:%d extra:%d differing:%d', count( $missing ), count( $extra ), count( $differ ) ),
		'missing:0 extra:0 differing:0',
		"{$name} — the shipped .mo is a compile of the committed .po"
	);

	if ( $missing || $extra || $differ ) {
		$sample = array_slice( array_merge( array_keys( $missing ), array_keys( $extra ), $differ ), 0, 3 );
		foreach ( $sample as $k ) {
			echo '        e.g. ' . var_export( substr( str_replace( array( "\x04", "\x00" ), array( ' | ', ' / ' ), $k ), 0, 90 ), true ) . "\n";
		}
		echo "        fix: msgfmt -o " . basename( $mo ) . ' ' . basename( $po ) . "\n";
	}
}

@unlink( $tmp );

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( 0 === $failed ? 0 : 1 );
