<?php
/**
 * Standalone unit tests for Secrets — round-2 hardening.
 *
 * Regression guards for the 2026-05-20 changes:
 *  - removed insecure shared fallback salt ('faz-fallback-salt-not-secure')
 *  - v1 backward-compat decrypt path now refuses to decode (returns '')
 *    so garbage XOR output cannot leak as a bearer token to ipinfo.io
 *  - encrypt() / current_key_hint() / derive_key() return '' when
 *    wp_salt() is unavailable, refusing to fall back to a predictable
 *    keystream
 *  - salt-rotation detection via 8-char hex key-hint prefix in v2 format
 *
 * Run:
 *   php tests/unit/test-secrets.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
define( 'FAZ_VERSION', '1.15.0-test' );

$GLOBALS['_faz_test_salt'] = 'test-salt-stable';
function wp_salt( $scheme = 'auth' ) {
	return $GLOBALS['_faz_test_salt'];
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/geo-routing/includes/class-secrets.php';
use FazCookie\Admin\Modules\Geo_Routing\Includes\Secrets;

$tests_run = $tests_passed = $tests_failed = 0;

function assert_eq( $a, $e, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $a === $e ) { $tests_passed++; echo "  \033[32m✓\033[0m $label\n"; }
	else { $tests_failed++; echo "  \033[31m✗\033[0m $label\n      expected: " . var_export( $e, true ) . "\n      actual:   " . var_export( $a, true ) . "\n"; }
}
function assert_true( $c, $l ) { assert_eq( (bool) $c, true, $l ); }
function assert_false( $c, $l ) { assert_eq( (bool) $c, false, $l ); }
function assert_neq( $a, $e, $l ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $a !== $e ) { $tests_passed++; echo "  \033[32m✓\033[0m $l\n"; }
	else { $tests_failed++; echo "  \033[31m✗\033[0m $l (got: " . var_export( $a, true ) . ")\n"; }
}

echo "\n== Secrets — encryption hardening (round-2 fix) ==\n\n";

// A v2 value exactly as 1.15.0 – 1.39.0 wrote it: plain XOR a SHA-256
// counter keystream of wp_salt('auth'), with the 8-hex key hint.
function faz_test_legacy_v2( $plain ) {
	$salt   = wp_salt( 'auth' );
	$stream = '';
	while ( strlen( $stream ) < strlen( $plain ) ) {
		$stream .= hash( 'sha256', $salt . strlen( $stream ), true );
	}
	$hint = substr( hash( 'sha256', 'faz_secrets_v2|' . $salt ), 0, 8 );
	return 'v2:' . $hint . ':' . base64_encode( $plain ^ substr( $stream, 0, strlen( $plain ) ) );
}

// ---------- 1. v3 encrypt → decrypt roundtrip ----------
$plain = 'svn_secret_token_42';
$enc = Secrets::encrypt( $plain );
assert_true( 0 === strpos( $enc, 'v3:' ), 'encrypt produces v3-prefixed ciphertext when sodium is available' );
assert_eq( Secrets::decrypt( $enc ), $plain, 'v3 roundtrip preserves plaintext' );

// ---------- 2. v3 format shape: v3:<8 hex>:<base64> ----------
assert_true(
	1 === preg_match( '/^v3:[0-9a-f]{8}:[A-Za-z0-9+\/=]+$/', $enc ),
	'v3 ciphertext format: prefix + 8-hex keyhint + base64 payload'
);

// ---------- 2b. Values saved by earlier versions (v2) still open ----------
$legacy = faz_test_legacy_v2( $plain );
assert_eq( Secrets::decrypt( $legacy ), $plain, 'a v2 value written by 1.39.0 still decrypts' );
assert_true( Secrets::needs_upgrade( $legacy ), 'a v2 value is flagged for re-encryption' );
assert_false( Secrets::needs_upgrade( $enc ), 'a v3 value is not flagged for re-encryption' );

// ---------- 2c. No keystream reuse, and tampering is detected ----------
$a  = 'AAAAAAAAAAAAAAAAAAAA';
$b  = 'BBBBBBBBBBBBBBBBBBBB';
$ea = base64_decode( explode( ':', Secrets::encrypt( $a ), 3 )[2] );
$eb = base64_decode( explode( ':', Secrets::encrypt( $b ), 3 )[2] );
// With v2 the two ciphertexts XOR to $a ^ $b, which reveals one plaintext
// from the other. Compare the bodies after the 24-byte nonce.
assert_neq(
	substr( $ea ^ $eb, 24 + 16 ),
	$a ^ $b,
	'two v3 ciphertexts of equal length do not XOR to the XOR of their plaintexts'
);
assert_neq( Secrets::encrypt( $plain ), Secrets::encrypt( $plain ), 'v3 uses a fresh nonce per value' );
$raw    = base64_decode( explode( ':', $enc, 3 )[2] );
$raw[30] = chr( ord( $raw[30] ) ^ 1 );
$parts  = explode( ':', $enc, 3 );
assert_eq(
	Secrets::decrypt( $parts[0] . ':' . $parts[1] . ':' . base64_encode( $raw ) ),
	'',
	'a v3 value with one flipped bit does not open (MAC)'
);
assert_eq( Secrets::decrypt( $parts[0] . ':' . $parts[1] . ':' . base64_encode( substr( $raw, 0, 30 ) ) ), '', 'a truncated v3 value does not open' );

// ---------- 3. v1 backward-compat is CLOSED — must return '' ----------
// Construct a v1-shaped ciphertext by hand: 'v1:' + base64(plaintext XOR key).
$v1_payload = base64_encode( $plain ^ str_repeat( "\0", strlen( $plain ) ) );
$v1_cipher  = 'v1:' . $v1_payload;
assert_eq(
	Secrets::decrypt( $v1_cipher ),
	'',
	'v1 backward-compat decode returns empty (deprecated path, no key-hint = no salt-rotation detection)'
);

// ---------- 4. Salt rotation detection ----------
$enc_before = Secrets::encrypt( 'rotation-test' );
$GLOBALS['_faz_test_salt'] = 'test-salt-ROTATED'; // simulate wp_salt() change
$dec_after_rotation = Secrets::decrypt( $enc_before );
assert_eq(
	$dec_after_rotation,
	'',
	'Salt rotation detected via key-hint mismatch → decrypt returns empty'
);
$GLOBALS['_faz_test_salt'] = 'test-salt-stable'; // restore

// ---------- 5. No-salt failure: wp_salt() returns empty ----------
$GLOBALS['_faz_test_salt'] = '';
assert_eq(
	Secrets::encrypt( 'should-fail' ),
	'',
	'encrypt() refuses to encrypt when wp_salt() returns empty (no insecure fallback)'
);
// Decrypt also refuses (it consults current_key_hint).
$some_old_cipher = 'v2:abcd1234:' . base64_encode( 'whatever' );
assert_eq(
	Secrets::decrypt( $some_old_cipher ),
	'',
	'decrypt() refuses when current_key_hint is unavailable (no insecure fallback)'
);
$GLOBALS['_faz_test_salt'] = 'test-salt-stable'; // restore

// ---------- 6. Malformed v2 inputs ----------
assert_eq(
	Secrets::decrypt( 'v2:tooShort' ),
	'',
	'v2 ciphertext missing payload segment → empty'
);
assert_eq(
	Secrets::decrypt( 'v2:notHexAtAll:Zm9v' ),
	'',
	'v2 ciphertext with non-matching key-hint → empty'
);
assert_eq(
	Secrets::decrypt( 'v4:future-version:Zm9v' ),
	'',
	'Unknown version prefix (v4) → empty (forward-compat safe)'
);
assert_eq(
	Secrets::decrypt( 'v3:notHexAtAll:Zm9v' ),
	'',
	'v3 ciphertext with non-matching key-hint → empty'
);

// ---------- 7. Long payloads ----------
$long_secret = str_repeat( 'A', 512 ); // matches the API key max-length cap
$long_enc = Secrets::encrypt( $long_secret );
assert_eq(
	Secrets::decrypt( $long_enc ),
	$long_secret,
	'512-byte payload roundtrips (matches Geo_Api API-key length cap)'
);

// v3 salt rotation is detected the same way as v2.
$enc_v3 = Secrets::encrypt( 'rotation-v3' );
$GLOBALS['_faz_test_salt'] = 'test-salt-ROTATED';
assert_eq( Secrets::decrypt( $enc_v3 ), '', 'v3: salt rotation detected via key-hint mismatch → empty' );
$GLOBALS['_faz_test_salt'] = 'test-salt-stable';

// ---------- Summary ----------
echo "\n--\n";
echo "Tests:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n\n";

if ( $tests_failed > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
echo "\033[32mPASS\033[0m\n";
exit( 0 );
