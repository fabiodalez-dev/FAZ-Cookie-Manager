<?php
/**
 * The shipped .mo catalogues translate every Cookie Policy section.
 *
 * Template_Translations::apply() only serves the gettext version of a policy
 * when every section of the English scaffold has a usable translation. When a
 * bundled .md has fewer sections than the English scaffold (LGPD in de/fr has
 * 8, English has 10), a single missing section makes the whole gettext policy
 * fall back to that shorter .md: the visitor loses sections and the public
 * policy version hash changes.
 *
 * That happened in the 1.34.2 i18n resync: two policy sections were reworded
 * in English, the old translations went obsolete, and only it_IT was
 * re-translated, so the de_DE/fr_FR LGPD policy lost "Categories of personal
 * data processed (Art. 5, I)" and its contact section. This suite reads the
 * compiled .mo files exactly as WordPress would, through _x(), and asserts
 * that every shipped locale yields a complete gettext policy for every
 * jurisdiction.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['faz_mo_test_locale'] = 'en_US';
$GLOBALS['faz_mo_test_map']    = array();

function determine_locale() {
	return $GLOBALS['faz_mo_test_locale'];
}

function _x( $text, $context, $domain ) {
	if ( 'faz-cookie-manager' !== $domain ) {
		return $text;
	}
	$key = $context . "\x04" . $text;
	$map = $GLOBALS['faz_mo_test_map'];
	return ( isset( $map[ $key ] ) && '' !== $map[ $key ] ) ? $map[ $key ] : $text;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

/**
 * Decode a binary .mo into a map of raw key ("ctxt\x04msgid") => translation.
 *
 * @param string $path Path to the .mo file.
 * @return array|string Map on success, error string on failure.
 */
function faz_policy_decode_mo( $path ) {
	$blob = @file_get_contents( $path );
	if ( false === $blob || strlen( $blob ) < 28 ) {
		return 'unreadable or truncated';
	}
	$magic = substr( $blob, 0, 4 );
	if ( "\xde\x12\x04\x95" === $magic ) {
		$fmt = 'V';
	} elseif ( "\x95\x04\x12\xde" === $magic ) {
		$fmt = 'N';
	} else {
		return 'bad magic (not a .mo file)';
	}
	$header = unpack( $fmt . '5', substr( $blob, 4, 20 ) );
	$count  = $header[2];
	$o_orig = $header[3];
	$o_tran = $header[4];
	$map    = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$o = unpack( $fmt . '2', substr( $blob, $o_orig + ( $i * 8 ), 8 ) );
		$t = unpack( $fmt . '2', substr( $blob, $o_tran + ( $i * 8 ), 8 ) );
		$map[ substr( $blob, $o[2], $o[1] ) ] = substr( $blob, $t[2], $t[1] );
	}
	return $map;
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-template-translations.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Template_Translations;

$tests_run = $tests_passed = $tests_failed = 0;

function mo_assert( $condition, $label, $detail = '' ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $condition ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m {$label}\n";
		return;
	}
	$tests_failed++;
	echo "  \033[31m✗\033[0m {$label}\n";
	if ( '' !== $detail ) {
		echo "        {$detail}\n";
	}
}

echo "\n== Shipped .mo catalogues translate every Cookie Policy section ==\n\n";

$languages     = dirname( __DIR__, 2 ) . '/languages';
$locales       = array(
	'cs_CZ' => 'cs',
	'de_DE' => 'de',
	'fr_FR' => 'fr',
	'hr'    => 'hr',
	'it_IT' => 'it',
	'nl_NL' => 'nl',
);
$jurisdictions = array( 'gdpr-strict', 'ccpa-california', 'lgpd-brazil', 'popia-southafrica' );

foreach ( $locales as $locale => $lang ) {
	$map = faz_policy_decode_mo( "{$languages}/faz-cookie-manager-{$locale}.mo" );
	mo_assert( is_array( $map ), "{$locale} — the shipped .mo decodes", is_string( $map ) ? $map : '' );
	if ( ! is_array( $map ) ) {
		continue;
	}
	$GLOBALS['faz_mo_test_locale'] = $locale;
	$GLOBALS['faz_mo_test_map']    = $map;

	foreach ( $jurisdictions as $jurisdiction ) {
		$en_sections = Template_Translations::split_sections(
			(string) file_get_contents( Generator::resolve_template_path( $jurisdiction, 'en' ) )
		);
		$local_path  = Generator::resolve_template_path( $jurisdiction, $lang );
		$bundled     = (string) file_get_contents( $local_path );
		$effective   = Template_Translations::apply( $jurisdiction, $lang, $bundled );
		$sections    = Template_Translations::split_sections( $effective );

		$untranslated = array();
		foreach ( $sections as $index => $section ) {
			foreach ( $en_sections as $en_section ) {
				if ( trim( $section ) === trim( $en_section ) ) {
					$untranslated[] = $index;
				}
			}
		}

		mo_assert(
			count( $sections ) === count( $en_sections ) && array() === $untranslated,
			sprintf( '%s %s — the policy has all %d sections, none left in English', $locale, $jurisdiction, count( $en_sections ) ),
			sprintf( 'got %d sections, English at positions [%s]', count( $sections ), implode( ', ', $untranslated ) )
		);
	}
}

// The case that regressed: LGPD in German and French, whose bundled .md has
// fewer sections than the English scaffold, so only a complete gettext
// catalogue keeps the Art. 5, I section and the contact section on screen.
$lgpd_cases = array(
	'de_DE' => array( 'de', '## Kategorien der verarbeiteten personenbezogenen Daten (Art. 5, I)' ),
	'fr_FR' => array( 'fr', '## Catégories de données personnelles traitées (Art. 5, I)' ),
);
foreach ( $lgpd_cases as $locale => $case ) {
	list( $lang, $heading ) = $case;
	$GLOBALS['faz_mo_test_locale'] = $locale;
	$GLOBALS['faz_mo_test_map']    = faz_policy_decode_mo( "{$languages}/faz-cookie-manager-{$locale}.mo" );
	$bundled                       = (string) file_get_contents( Generator::resolve_template_path( 'lgpd-brazil', $lang ) );
	$effective                     = Template_Translations::apply( 'lgpd-brazil', $lang, $bundled );
	mo_assert(
		$effective !== $bundled && false !== strpos( $effective, $heading ),
		"{$locale} lgpd-brazil — the gettext policy is served, with the Art. 5, I section"
	);
	mo_assert(
		false === strpos( $effective, 'monthly' ) && false === strpos( $effective, 'monatlich' ) && false === strpos( $effective, 'mensuel' ),
		"{$locale} lgpd-brazil — no section still promises a monthly IP salt"
	);
}

// PR #331: on cs_CZ sites the gettext policy replaces cs.md, so the wording fix
// has to hold on that path too — consent is asked before the optional cookies,
// never before the strictly necessary ones.
$GLOBALS['faz_mo_test_locale'] = 'cs_CZ';
$GLOBALS['faz_mo_test_map']    = faz_policy_decode_mo( "{$languages}/faz-cookie-manager-cs_CZ.mo" );
foreach ( array( 'gdpr-strict', 'lgpd-brazil' ) as $jurisdiction ) {
	$bundled   = (string) file_get_contents( Generator::resolve_template_path( $jurisdiction, 'cs' ) );
	$effective = Template_Translations::apply( $jurisdiction, 'cs', $bundled );
	mo_assert(
		false !== strpos( $effective, 'před uložením jakýchkoli nepovinných cookies' )
			&& false === strpos( $effective, 'nezbytně nutného cookies' )
			&& false === strpos( $effective, 'nepodstatného cookies' ),
		"cs_CZ {$jurisdiction} — consent is asked before the optional cookies, in the gettext policy as in cs.md"
	);
}

// The POPIA policy used the calque "nepodstatných" (literally "irrelevant")
// for "non-essential"; it now says "nepovinných", like gdpr-strict and
// lgpd-brazil. Both the gettext path and the bundled cs.md must agree.
$bundled   = (string) file_get_contents( Generator::resolve_template_path( 'popia-southafrica', 'cs' ) );
$effective = Template_Translations::apply( 'popia-southafrica', 'cs', $bundled );
foreach ( array( 'gettext' => $effective, 'cs.md' => $bundled ) as $faz_path => $faz_text ) {
	mo_assert(
		false !== strpos( $faz_text, 'nepovinných' ) && false === stripos( $faz_text, 'nepodstatn' ),
		"cs_CZ popia-southafrica ({$faz_path}) — non-essential cookies are \"nepovinné\", never \"nepodstatné\""
	);
}

echo "\n--\n";
echo "Tests:  {$tests_run}\n";
echo "Passed: {$tests_passed}\n";
echo "Failed: {$tests_failed}\n\n";
if ( $tests_failed > 0 ) {
	echo "\033[31mFAIL\033[0m\n";
	exit( 1 );
}
echo "\033[32mALL PASS\033[0m\n";
