<?php
/**
 * A policy rendered with no controller name or email reads as whole sentences.
 *
 * Renderer::strip_empty_value_sentences() drops the sentence that carries an
 * emptied bold value (`****`) when the company name or the contact email is
 * not saved yet. It split sentences at any `.` followed by whitespace and a
 * capital, and the legal citations in the scaffolds carry abbreviations that
 * are followed by one: `Cal. Civ. Code` in every CCPA language and `Reg. UE` /
 * `Règl. UE` in gdpr-strict es/fr/it/pt-BR. Only the head of the opening
 * sentence went, and the policy opened on `Civ. Code §§ 1798.100 et seq.) …`
 * or `UE 2016/679) y la Directiva ePrivacy …`. A line that was nothing but the
 * emptied value (`**{{COMPANY_EMAIL}}**` under the CCPA contact heading in
 * de/es/fr/it) was kept as a "horizontal rule" — the markdown converter has
 * no such syntax — and printed a literal `****`.
 *
 * Pinned here, against every shipped template, with COMPANY_NAME and
 * COMPANY_EMAIL empty and every other placeholder filled:
 *   - no `****` survives anywhere;
 *   - every line the stripper changed is a whole sentence, never one that
 *     starts in lower case, on `Civ.`, `UE ` or a closing bracket;
 *   - the CCPA and gdpr-strict es openings are dropped whole;
 *   - the neighbours of a dropped sentence stay byte for byte.
 *
 * Run: php tests/unit/test-policy-empty-value-sentences-php.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
define( 'FAZ_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return (string) $u; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function __( $text, $domain = null ) { return $text; }
function apply_filters( $tag, $value ) { return $value; }

$root = dirname( __DIR__, 2 );
require_once $root . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once $root . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;

$checks = 0;
$failed = 0;
function evs_check( $ok, $label, $detail = '' ) {
	global $checks, $failed;
	++$checks;
	if ( $ok ) {
		echo "  PASS {$label}\n";
		return;
	}
	++$failed;
	echo "  FAIL {$label}\n";
	if ( '' !== $detail ) {
		echo '       ' . $detail . "\n";
	}
}

$strip = new ReflectionMethod( Renderer::class, 'strip_empty_value_sentences' );
$strip->setAccessible( true );
$labels = new ReflectionMethod( Renderer::class, 'strip_empty_label_lines' );
$labels->setAccessible( true );

/**
 * The scaffold with every placeholder filled except the controller's name and
 * email, through the same two passes render() applies before this one.
 */
function evs_substituted( $file ) {
	global $labels;
	$scaffold = (string) file_get_contents( $file );
	preg_match_all( '/\{\{([A-Z_]+)\}\}/', $scaffold, $m );
	$data = array();
	foreach ( array_unique( $m[1] ) as $token ) {
		$data[ $token ] = 'Filled' . $token;
	}
	$data['COMPANY_NAME']  = '';
	$data['COMPANY_EMAIL'] = '';
	return $labels->invoke( null, Generator::substitute( $scaffold, $data ) );
}

$templates = glob( $root . '/admin/modules/cookie-policy-generator/templates/*/*.md' );
evs_check( count( $templates ) >= 40, 'PRECONDITION: the shipped templates are found (' . count( $templates ) . ')' );

$survivors = array();
$fragments = array();
foreach ( $templates as $file ) {
	$name = basename( dirname( $file ) ) . '/' . basename( $file );
	$md   = evs_substituted( $file );
	$out  = $strip->invoke( null, $md );
	if ( false !== strpos( $out, '****' ) ) {
		$survivors[] = $name;
	}
	foreach ( explode( "\n", $md ) as $n => $line ) {
		if ( false === strpos( $line, '****' ) ) {
			continue;
		}
		$after = $strip->invoke( null, $line );
		$text  = preg_replace( '/^\s*(?:[-*+]|\d+\.|#{1,6}|>)?\s*/u', '', $after );
		if ( '' !== $text && ( preg_match( '/^\p{Ll}/u', $text ) || preg_match( '/^(?:Civ\.|UE |[)\],;:])/u', $text ) ) ) {
			$fragments[] = $name . ':' . ( $n + 1 ) . ' => ' . mb_substr( $text, 0, 60 );
		}
	}
}
evs_check( array() === $survivors, 'no template renders a literal **** with the controller name and email empty', implode( ', ', $survivors ) );
evs_check( array() === $fragments, 'every sentence the stripper leaves is whole: none starts mid-citation or in lower case', implode( ' | ', $fragments ) );

$ccpa = $strip->invoke( null, evs_substituted( $root . '/admin/modules/cookie-policy-generator/templates/ccpa-california/en.md' ) );
evs_check( false === strpos( $ccpa, 'Notice at Collection and Cookie Policy explains' ) && false === strpos( $ccpa, 'Civ. Code §§ 1798.100 et seq.)' ), 'ccpa-california/en: the opening sentence is dropped whole' );
$es = $strip->invoke( null, evs_substituted( $root . '/admin/modules/cookie-policy-generator/templates/gdpr-strict/es.md' ) );
evs_check( false === strpos( $es, 'UE 2016/679) y la Directiva ePrivacy' ), 'gdpr-strict/es: the opening sentence is dropped whole' );
$de = $strip->invoke( null, evs_substituted( $root . '/admin/modules/cookie-policy-generator/templates/ccpa-california/de.md' ) );
evs_check( false === strpos( $de, '****' ) && 1 !== preg_match( '/^##[^\n]*\n\s*\z/', $de ), 'ccpa-california/de: the lone emptied contact line goes, and the heading above it with it' );

// The neighbours of a dropped sentence stay as they were.
$line = 'We keep logs (Art. 12(3) GDPR). Write to us at ****. See Cal. Civ. Code § 1798.100. The end.';
evs_check(
	'We keep logs (Art. 12(3) GDPR). See Cal. Civ. Code § 1798.100. The end.' === $strip->invoke( null, $line ),
	'only the sentence with the empty value goes; an abbreviation in a neighbour keeps it whole',
	var_export( $strip->invoke( null, $line ), true )
);
$line = 'Intro sentence. Under Reg. UE 2016/679 the controller **** answers. Next sentence.';
evs_check(
	'Intro sentence. Next sentence.' === $strip->invoke( null, $line ),
	'a sentence holding Reg. UE and the empty value goes whole, its neighbours stay',
	var_export( $strip->invoke( null, $line ), true )
);
evs_check( "Text\n\n## Next\n" === $strip->invoke( null, "Text\n\n## Next\n" ), 'text without an empty value is returned unchanged' );

echo "policy-empty-value-sentences: " . ( $checks - $failed ) . " passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
