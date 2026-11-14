<?php
/**
 * The DSAR confirmation mail goes to an address nobody verified, under the
 * site's name, so the greeting must not carry anything a mail client turns
 * into a link. The old filter caught only scheme:// and www. text; a bare
 * domain or a path still reached the mail and got auto-linked.
 *
 * Run: php tests/unit/test-dsar-greeting-name-php.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/includes/class-ip-hasher.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dsar-shortcode.php';

use FazCookie\Includes\DSAR_Shortcode;

$ok = 0; $ko = 0;
function t( $c, $l ) { global $ok, $ko; if ( $c ) { ++$ok; echo "  PASS $l\n"; } else { ++$ko; echo "  FAIL $l\n"; } }

$g = static function ( $name ) { return DSAR_Shortcode::greeting_name( $name, 'jane@example.test' ); };

foreach ( array(
	'Reset your password at evil-example.com/login',
	'visit evil-example.com now',
	'see https://evil.test/x',
	'go www.evil.test',
	'mail me at boss@evil.test',
	'open /wp-login.php',
	'café.example',
	'EVIL.COM',
) as $hostile ) {
	$out = $g( $hostile );
	t( 'jane@example.test' === $out || ! preg_match( '~[\p{L}\p{N}-]\.[\p{L}]{2,}|://|www\.|@|/~iu', $out ), "no linkable text survives in '$hostile' (got '$out')" );
}

t( 'Jane Doe' === $g( 'Jane Doe' ), 'a plain name is kept' );
t( "Anne-Marie O'Neil" === $g( "Anne-Marie O'Neil" ), 'hyphens and apostrophes are kept' );
t( 'Dr. Smith' === $g( 'Dr. Smith' ), 'an abbreviation with a dot is kept' );
t( 'J.R.R. Tolkien' === $g( 'J.R.R. Tolkien' ), 'initials are kept' );
t( 'José Müller' === $g( 'José Müller' ), 'non-ASCII letters are kept' );
t( 'jane@example.test' === $g( 'https://evil.test' ), 'nothing left falls back to the address' );
t( 60 >= mb_strlen( $g( str_repeat( 'Long ', 40 ) ) ), 'the greeting is capped at 60 characters' );

echo "\ndsar greeting name: $ok passed, $ko failed\n";
exit( $ko > 0 ? 1 : 0 );
