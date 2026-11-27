<?php
/**
 * Box + Pushdown: Accept and Reject are sized by the layout, not by their
 * labels, above the phone layouts.
 *
 * The box leaves the three notice buttons 390px, and its rule sized each one
 * from its text on a row that wraps. With the default order and the Italian or
 * German labels Accept ended alone on a second row, twice as wide as Reject
 * (EDPB Guidelines 03/2022: accepting and refusing carry the same weight).
 *
 * Drives the real Template::box_pushdown_rules() through the real
 * Frontend::prepare_banner_styles(), so the rules asserted are the ones a
 * visitor receives. The geometry itself is measured by the E2E spec
 * box-pushdown.spec.ts; this file pins the rules it depends on.
 *
 * Run: php tests/unit/test-box-pushdown-css-php.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'FAZ_VERSION', 'unit' );
define( 'DAY_IN_SECONDS', 86400 );
function get_option( $name, $fallback = false ) { return $fallback; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }

$root = dirname( __DIR__, 2 );
require_once $root . '/frontend/class-frontend.php';
require_once $root . '/admin/modules/banners/includes/class-template.php';

$passed = 0;
function t( $cond, $label ) {
	global $passed;
	if ( $cond ) { $passed++; return; }
	fwrite( STDERR, "FAIL: $label\n" );
	exit( 1 );
}

/** Body of the first `$query{...}` block, brace-matched. */
function media_body( $css, $query ) {
	$start = strpos( $css, $query );
	if ( false === $start ) {
		return '';
	}
	$open  = strpos( $css, '{', $start );
	$depth = 0;
	for ( $i = $open, $len = strlen( $css ); $i < $len; $i++ ) {
		if ( '{' === $css[ $i ] ) {
			++$depth;
		} elseif ( '}' === $css[ $i ] && 0 === --$depth ) {
			return substr( $css, $open + 1, $i - $open - 1 );
		}
	}
	return '';
}

/** Declarations of the rule whose selector list contains $selector. */
function rule_body( $css, $selector ) {
	return preg_match( '/(?:^|\})[^{}]*' . preg_quote( $selector, '/' ) . '[^{}]*\{([^}]*)\}/', $css, $m ) ? $m[1] : '';
}

$method = new ReflectionMethod( 'FazCookie\\Admin\\Modules\\Banners\\Includes\\Template', 'box_pushdown_rules' );
$method->setAccessible( true );
$raw = $method->invoke( null );

// The stylesheet is stored through wp_kses(): a `>` reaches the browser as
// `&gt;` and voids its rule.
t( false === strpos( $raw, '>' ), 'no child combinator in the stored rules' );

$css  = \FazCookie\Frontend\Frontend::prepare_banner_styles( $raw );
$row  = '#faz-consent:where(.faz-box-pushdown) .faz-notice-btn-wrapper';
$wide = media_body( $css, '@media (min-width:577px)' );
t( '' !== $wide, 'the box has rules for widths above the phone layouts' );

$pair = rule_body( $wide, $row . ' .faz-btn-accept' );
t( '' !== $pair && false !== strpos( rule_body( $wide, $row . ' .faz-btn-reject' ), 'flex:1 1 0' ), 'Accept and Reject are sized by one rule' );
t( rule_body( $wide, $row . ' .faz-btn-accept' ) === rule_body( $wide, $row . ' .faz-btn-reject' ), 'the same declarations reach both' );
// One flex basis of 0 and no minimum: the row is shared in equal parts
// whatever the two labels are. `auto` here is the defect.
t( 1 === preg_match( '/(?:^|;)flex:1 1 0(?=;|$)/', $pair ), 'Accept and Reject share the row in equal parts (flex:1 1 0)' );
t( 1 === preg_match( '/(?:^|;)min-width:0(?=;|$)/', $pair ), 'a long label cannot widen its button past its half (min-width:0)' );
t( 1 === preg_match( '/(?:^|;)white-space:normal(?=;|$)/', $pair ), 'a label too long for half the row wraps instead of overflowing' );
t( false !== strpos( $wide, $row . '{align-items:stretch;}' ), 'the row stretches both to the height of the taller' );

// Customise is not one of the compared choices and takes its own row, so the
// pair, adjacent in every allowed order, always has the other row to itself.
t( 1 === preg_match( '/(?:^|;)flex:1 1 100%(?=;|$)/', rule_body( $wide, $row . ' .faz-btn-customize' ) ), 'Customise takes a row of its own' );
// With a zero basis the pair would let a fourth item onto its row.
t( 1 === preg_match( '/(?:^|;)flex:1 1 100%(?=;|$)/', rule_body( $wide, $row . ' [data-faz-tag=donotsell-button]' ) ), 'the Do Not Sell control cannot join the pair on its row' );

// Focus order is markup order (WCAG 2.4.3): the layout never reorders.
t( false === strpos( $wide, 'order:' ), 'no button is reordered with CSS' );

// The phone layouts are left alone: nothing outside the block sizes the pair,
// and the block follows the generic rule it overrides at equal specificity.
$rest = str_replace( '@media (min-width:577px){' . $wide . '}', '', $css );
t( $rest !== $css && false === strpos( $rest, '.faz-notice-btn-wrapper .faz-btn-accept,' . $row ), 'the pair rule exists only above 576px' );
$generic = strpos( $css, $row . ' .faz-btn{flex:1 1 auto;}' );
t( false !== $generic && strpos( $css, '@media (min-width:577px)' ) > $generic, 'it comes after the generic flex rule it overrides' );

echo "$passed passed: Box + Pushdown sizes Accept and Reject equally above the phone layouts\n";
