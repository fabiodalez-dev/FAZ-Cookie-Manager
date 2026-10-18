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
 * Dropping the sentence was itself wrong for the opening one. The controller
 * name is its SUBJECT (`explains how **{{COMPANY_NAME}}** ("we" …) uses
 * cookies …, in compliance with the California Consumer Privacy Act …`), and
 * the same sentence is the only place the document states the law it is
 * written under. So with no name saved the policy no longer said which law
 * applied. Renderer::name_unnamed_controller() now names the controller with
 * a neutral formula in the template language before substitution; only the
 * sentences that exist to give a contact value (an email, `contact us at
 * ****`) are still dropped.
 *
 * Pinned here, against every shipped template, with COMPANY_NAME and
 * COMPANY_EMAIL empty and every other placeholder filled:
 *   - no `****` survives anywhere;
 *   - every line the stripper changed is a whole sentence, never one that
 *     starts in lower case, on `Civ.`, `UE ` or a closing bracket;
 *   - in all 40 scaffolds the opening sentence stays whole, names the
 *     controller with the formula of its language and cites its law;
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
// The step that names an unnamed controller runs on the scaffold before
// substitution. Looked up softly so that, on a build without it, the checks
// below fail one by one instead of the whole file stopping on a fatal.
$naming = null;
if ( method_exists( Renderer::class, 'name_unnamed_controller' ) ) {
	$naming = new ReflectionMethod( Renderer::class, 'name_unnamed_controller' );
	$naming->setAccessible( true );
}
evs_check( null !== $naming, 'Renderer names an unnamed controller before substitution' );

/**
 * The scaffold with every placeholder filled except the controller's name and
 * email, through the same two passes render() applies before this one.
 */
function evs_substituted( $file ) {
	global $labels, $naming;
	$scaffold = (string) file_get_contents( $file );
	preg_match_all( '/\{\{([A-Z_]+)\}\}/', $scaffold, $m );
	$data = array();
	foreach ( array_unique( $m[1] ) as $token ) {
		$data[ $token ] = 'Filled' . $token;
	}
	$data['COMPANY_NAME']  = '';
	$data['COMPANY_EMAIL'] = '';
	if ( null !== $naming ) {
		$scaffold = $naming->invoke( null, $scaffold, basename( $file, '.md' ), $data['COMPANY_NAME'] );
	}
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

// The opening sentence carries the controller as its subject and is the one
// place the document cites its law. With no name saved it must stay, whole,
// with the controller named by the neutral formula of the template language.
// The formulas are spelled out here rather than read from the renderer, so a
// change to them is a deliberate edit in two places. Two scaffolds put a word
// before the name that only fits a company name — the Czech `společnost`
// ("the company") and the Portuguese article `a` — and it goes with it.
$evs_formula = array(
	'en'    => 'the operator of this website',
	'it'    => 'il titolare di questo sito',
	'de'    => 'der Betreiber dieser Website',
	'fr'    => "l'exploitant de ce site",
	'es'    => 'el titular de este sitio web',
	'pt-BR' => 'o responsável por este site',
	'nl'    => 'de beheerder van deze website',
	'cs'    => 'provozovatel tohoto webu',
	'hr'    => 'voditelj ove web stranice',
	'bg'    => 'операторът на този уебсайт',
);
$evs_law = array(
	'gdpr-strict'       => '2016/679',
	'ccpa-california'   => 'CCPA',
	'lgpd-brazil'       => 'LGPD',
	'popia-southafrica' => 'POPIA',
);
$openings = 0;
foreach ( $templates as $file ) {
	$jur  = basename( dirname( $file ) );
	$lang = basename( $file, '.md' );
	$name = $jur . '/' . $lang;
	$src  = '';
	foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $source_line ) {
		if ( false !== strpos( $source_line, '**{{COMPANY_NAME}}**' ) ) {
			$src = $source_line;
			break;
		}
	}
	if ( '' === $src || ! isset( $evs_formula[ $lang ], $evs_law[ $jur ] ) ) {
		evs_check( false, "{$name}: the scaffold has an opening that names the controller" );
		continue;
	}
	$expected = preg_replace( '/(?:(?<=\s)(?:společnost|a) )?\*\*\{\{COMPANY_NAME\}\}\*\*/u', $evs_formula[ $lang ], $src );
	$out      = $strip->invoke( null, evs_substituted( $file ) );
	$ok       = false !== strpos( "\n" . $out . "\n", "\n" . $expected . "\n" )
		&& false !== strpos( $expected, $evs_law[ $jur ] )
		&& false === strpos( $expected, '****' );
	evs_check( $ok, "{$name}: with no name saved the opening stays whole, names the controller neutrally and cites " . $evs_law[ $jur ], mb_substr( $expected, 0, 110 ) );
	$openings += $ok ? 1 : 0;
}
evs_check( 40 === $openings, "all 40 openings survive an empty controller name ({$openings})" );

// Grammar the substitution alone would get wrong: the Czech `jak společnost
// provozovatel` and the Portuguese `como a o responsável`.
$cs = $strip->invoke( null, evs_substituted( $root . '/admin/modules/cookie-policy-generator/templates/gdpr-strict/cs.md' ) );
evs_check( false !== strpos( $cs, 'jak provozovatel tohoto webu („my“' ) && false === strpos( $cs, 'společnost provozovatel' ), 'gdpr-strict/cs: `společnost` goes with the missing name' );
$pt = $strip->invoke( null, evs_substituted( $root . '/admin/modules/cookie-policy-generator/templates/popia-southafrica/pt-BR.md' ) );
evs_check( false !== strpos( $pt, 'explica como o responsável por este site («nós»' ) && false === strpos( $pt, 'como a o ' ), 'popia-southafrica/pt-BR: the article before the missing name goes with it' );

// The ones that were cut mid-citation before stay whole now as well.
$ccpa = $strip->invoke( null, evs_substituted( $root . '/admin/modules/cookie-policy-generator/templates/ccpa-california/en.md' ) );
evs_check(
	false !== strpos( $ccpa, 'This Notice at Collection and Cookie Policy explains how the operator of this website ("we", "us", "our") collects' )
		&& false !== strpos( $ccpa, 'in compliance with the California Consumer Privacy Act of 2018' )
		&& false !== strpos( $ccpa, 'Civ. Code §§ 1798.100 et seq.)' ),
	'ccpa-california/en: the opening names the operator and cites the California Consumer Privacy Act'
);
$es = $strip->invoke( null, evs_substituted( $root . '/admin/modules/cookie-policy-generator/templates/gdpr-strict/es.md' ) );
evs_check( false !== strpos( $es, 'explica cómo el titular de este sitio web ("nosotros") utiliza' ) && false !== strpos( $es, 'UE 2016/679) y la Directiva ePrivacy' ), 'gdpr-strict/es: the opening stays whole with the citation' );

// A saved name is left exactly where the scaffold puts it.
if ( null !== $naming ) {
	$src = (string) file_get_contents( $root . '/admin/modules/cookie-policy-generator/templates/gdpr-strict/cs.md' );
	evs_check( $src === $naming->invoke( null, $src, 'cs', 'Acme s.r.o.' ), 'a saved name leaves the scaffold untouched' );
	evs_check( $src !== $naming->invoke( null, $src, 'cs', '  ' ), 'a name of blanks counts as no name' );
	evs_check( "- **Data controller:** {{COMPANY_NAME}}\n" === $naming->invoke( null, "- **Data controller:** {{COMPANY_NAME}}\n", 'en', '' ), 'a label row holding only the name is left for the label stripper to drop' );
}
$de = $strip->invoke( null, evs_substituted( $root . '/admin/modules/cookie-policy-generator/templates/ccpa-california/de.md' ) );
evs_check( false === strpos( $de, '****' ) && 1 !== preg_match( '/^##[^\n]*\n\s*\z/m', $de ), 'ccpa-california/de: the lone emptied contact line goes, and the heading above it with it' );

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

// No controller saved at all: the label list goes, and the sentence that
// introduced it ("The data controller … is:") must go with it rather than
// stop on its colon in front of the next heading.
$orphans = array();
$kept    = array();
foreach ( $templates as $file ) {
	$name     = basename( dirname( $file ) ) . '/' . basename( $file );
	$scaffold = (string) file_get_contents( $file );
	preg_match_all( '/\{\{([A-Z_]+)\}\}/', $scaffold, $m );
	$data = array();
	foreach ( array_unique( $m[1] ) as $token ) {
		$data[ $token ] = 0 === strpos( $token, 'COMPANY_' ) || 0 === strpos( $token, 'DPO_' ) || 0 === strpos( $token, 'INFO_OFFICER' ) ? '' : 'Filled' . $token;
	}
	$out = $strip->invoke( null, $labels->invoke( null, Generator::substitute( $scaffold, $data ) ) );
	if ( preg_match( '/^(?![ \t]*(?:#|[-*+][ \t]))[^\n]*:[ \t]*\n(?:[ \t]*\n)*(?=#|\z)/mu', $out, $hit ) ) {
		$orphans[] = $name . ' => ' . mb_substr( trim( $hit[0] ), 0, 50 );
	}
	// Control: with only the name saved the list keeps an item, and its
	// introduction stays.
	$data['COMPANY_NAME'] = 'Acme Srl';
	$named                = $labels->invoke( null, Generator::substitute( $scaffold, $data ) );
	if ( preg_match( '/^[^\n#\-*][^\n]*:[ \t]*\n\n[-*][ \t]+\*\*[^*\n]+:\*\*[ \t]*Acme Srl/mu', $named ) ) {
		$kept[] = $name;
	}
}
evs_check( array() === $orphans, 'with no controller saved, no sentence is left ending on its colon over an emptied list', implode( ' | ', $orphans ) );
evs_check( count( $kept ) >= 20, 'control: with the name saved, the list introduction stays (' . count( $kept ) . ' scaffolds)' );
evs_check(
	"Intro:\n\n- **A:** x\n\n## Next\n" === $labels->invoke( null, "Intro:\n\n- **A:** x\n- **B:** \n\n## Next\n" ),
	'a list that keeps one item keeps its introduction',
	var_export( $labels->invoke( null, "Intro:\n\n- **A:** x\n- **B:** \n\n## Next\n" ), true )
);
evs_check(
	"Text.\n\n## Next\n\nBody.\n" === $labels->invoke( null, "Text.\n\n## Who\n\nIntro:\n\n- **A:** \n- **B:** \n\n## Next\n\nBody.\n" ),
	'an emptied list takes its introduction and the heading left over nothing',
	var_export( $labels->invoke( null, "Text.\n\n## Who\n\nIntro:\n\n- **A:** \n- **B:** \n\n## Next\n\nBody.\n" ), true )
);

echo "policy-empty-value-sentences: " . ( $checks - $failed ) . " passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
