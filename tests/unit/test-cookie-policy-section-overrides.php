<?php
/**
 * Standalone unit tests for administrator-authored Cookie Policy sections.
 *
 * Covers the legal-document safety contract: shipped text stays the fallback
 * placeholder, overrides are isolated by jurisdiction/language, anchors fail
 * closed after scaffold drift, placeholders remain substitutable, and the
 * stored option shape is bounded and sanitised.
 *
 * Run:
 *   php tests/unit/test-cookie-policy-section-overrides.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) {
		return (string) $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-template-translations.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-section-overrides.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Section_Overrides;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Template_Translations;

$faz_so_run = 0;
$faz_so_passed = 0;
$faz_so_failed = 0;

function faz_so_eq( $actual, $expected, $label ) {
	global $faz_so_run, $faz_so_passed, $faz_so_failed;
	$faz_so_run++;
	if ( $actual === $expected ) {
		$faz_so_passed++;
		echo "  \033[32m✓\033[0m {$label}\n";
		return;
	}
	$faz_so_failed++;
	echo "  \033[31m✗\033[0m {$label}\n";
	echo '      expected: ' . var_export( $expected, true ) . "\n";
	echo '      actual:   ' . var_export( $actual, true ) . "\n";
}

function faz_so_true( $condition, $label ) {
	faz_so_eq( (bool) $condition, true, $label );
}

function faz_so_contains( $haystack, $needle, $label ) {
	faz_so_true( is_string( $haystack ) && false !== strpos( $haystack, $needle ), $label );
}

echo "\n== Cookie Policy section overrides ==\n\n";

$scaffold = "# Cookie Policy\nWelcome {{COMPANY_NAME}}.\n\n## Contact\nEmail {{COMPANY_EMAIL}}.\n\n## Cookies\n{{COOKIE_CATEGORIES}}\n";
$sections = Template_Translations::split_sections( $scaffold );
faz_so_eq( count( $sections ), 3, 'Scaffold splits into introduction plus two level-two sections' );

$none = array( 'section_overrides' => array() );
faz_so_eq(
	Section_Overrides::apply( 'gdpr-strict', 'en', $scaffold, $none ),
	$scaffold,
	'No override returns the shipped scaffold byte-for-byte'
);

$english = array(
	'section_overrides' => array(
		'gdpr-strict' => array(
			'en' => array(
				'1' => array(
					'anchor' => '## Contact',
					'text'   => "## Contact\nWrite to {{COMPANY_EMAIL}}.",
				),
			),
		),
	),
);
$overridden = Section_Overrides::apply( 'gdpr-strict', 'en', $scaffold, $english );
faz_so_contains( $overridden, 'Write to {{COMPANY_EMAIL}}.', 'Matching anchor replaces exactly the selected section' );
faz_so_contains( $overridden, '{{COOKIE_CATEGORIES}}', 'Unedited sections remain in the effective scaffold' );
faz_so_contains(
	Generator::substitute( $overridden, array( 'COMPANY_EMAIL' => 'privacy@example.test', 'COMPANY_NAME' => 'ACME', 'COOKIE_CATEGORIES' => 'Necessary' ) ),
	'Write to privacy@example.test.',
	'Placeholders inside an administrator override use the normal substitution pipeline'
);
faz_so_eq(
	Section_Overrides::apply( 'gdpr-strict', 'it', $scaffold, $english ),
	$scaffold,
	'English override does not leak into another language'
);
faz_so_eq(
	Section_Overrides::apply( 'popia-southafrica', 'en', $scaffold, $english ),
	$scaffold,
	'GDPR override does not leak into another jurisdiction'
);

$drifted = $english;
$drifted['section_overrides']['gdpr-strict']['en']['1']['anchor'] = '## Former contact heading';
faz_so_eq(
	Section_Overrides::apply( 'gdpr-strict', 'en', $scaffold, $drifted ),
	$scaffold,
	'Stale anchor fails closed to the shipped legal text'
);
$anchorless = $english;
$anchorless['section_overrides']['gdpr-strict']['en']['1']['anchor'] = '';
faz_so_eq(
	Section_Overrides::apply( 'gdpr-strict', 'en', $scaffold, $anchorless ),
	$scaffold,
	'Missing anchor cannot bypass scaffold-drift protection'
);

$slovak = array(
	'section_overrides' => array(
		'gdpr-strict' => array(
			'sk' => array(
				'0' => array(
					'anchor' => '# Cookie Policy',
					'text'   => "# Zásady používania súborov cookie\nPrevádzkovateľ: {{COMPANY_NAME}}.",
				),
			),
		),
	),
);
$slovak_result = Section_Overrides::apply( 'gdpr-strict', 'sk', $scaffold, $slovak );
faz_so_contains( $slovak_result, 'Zásady používania súborov cookie', 'Unbundled Slovak language receives its own override bucket' );
faz_so_contains( $slovak_result, '## Contact', 'A partial Slovak override keeps shipped fallback sections' );

$description = Section_Overrides::describe( 'gdpr-strict', 'en', $scaffold, $english );
faz_so_eq( $description[1]['shipped'], "## Contact\nEmail {{COMPANY_EMAIL}}.", 'Editor receives shipped text separately for the textarea placeholder' );
faz_so_eq( $description[1]['override'], "## Contact\nWrite to {{COMPANY_EMAIL}}.", 'Editor receives authored text separately as the textarea value' );
faz_so_eq( $description[2]['override'], '', 'An untouched section has an empty value, not copied shipped text' );

$missing_placeholder = $english;
$missing_placeholder['section_overrides']['gdpr-strict']['en']['1']['text'] = "## Contact\nUse our contact form.";
$warnings = Section_Overrides::placeholder_warnings( 'gdpr-strict', 'en', $scaffold, $missing_placeholder );
faz_so_eq( count( $warnings ), 1, 'Dropping a shipped placeholder produces one advisory warning' );
faz_so_contains( $warnings[0], '{{COMPANY_EMAIL}}', 'Placeholder warning identifies the omitted token' );
$stale_missing_placeholder = $missing_placeholder;
$stale_missing_placeholder['section_overrides']['gdpr-strict']['en']['1']['anchor'] = '## Old contact';
faz_so_eq(
	Section_Overrides::placeholder_warnings( 'gdpr-strict', 'en', $scaffold, $stale_missing_placeholder ),
	array(),
	'Inactive stale override does not claim that shipped information is missing'
);

$clip = static function ( $value, $max ) {
	return substr( str_replace( "\0", '', (string) $value ), 0, $max );
};
$raw = array(
	'gdpr-strict' => array(
		'sk' => array(
			'0'            => array( 'anchor' => '# Cookie Policy', 'text' => "## Vlastný text\nRiadok 2" ),
			'1'            => array( 'anchor' => '', 'text' => 'Unsafe without anchor' ),
			'not-an-index' => array( 'anchor' => '## Contact', 'text' => 'Bad index' ),
			'2'            => array( 'anchor' => '## Cookies', 'text' => '   ' ),
		),
		'xx-invalid!' => array(
			'0' => array( 'anchor' => '# Cookie Policy', 'text' => 'Unknown language' ),
		),
	),
	'unknown-law' => array(
		'en' => array(
			'0' => array( 'anchor' => '# Cookie Policy', 'text' => 'Unknown jurisdiction' ),
		),
	),
);
$sanitised = Section_Overrides::sanitize(
	$raw,
	Generator::JURISDICTIONS,
	array( 'en', 'sk' ),
	$clip
);
faz_so_eq( array_keys( $sanitised ), array( 'gdpr-strict' ), 'Sanitizer drops unknown jurisdictions' );
faz_so_eq( array_keys( $sanitised['gdpr-strict'] ), array( 'sk' ), 'Sanitizer keeps Slovak and drops unknown language buckets' );
faz_so_eq( array_keys( $sanitised['gdpr-strict']['sk'] ), array( 0 ), 'Sanitizer keeps only bounded numeric entries with text and anchor' );
faz_so_contains( $sanitised['gdpr-strict']['sk'][0]['text'], "\n", 'Sanitizer preserves Markdown line breaks' );
faz_so_eq( Section_Overrides::sanitize( array(), Generator::JURISDICTIONS, array( 'en', 'sk' ), $clip ), array(), 'Empty PHP array remains an empty override map' );
faz_so_eq( Section_Overrides::sanitize( 'not-an-array', Generator::JURISDICTIONS, array( 'en', 'sk' ), $clip ), array(), 'Non-array override payload is rejected' );

echo "\n--\nTests:  {$faz_so_run}\nPassed: {$faz_so_passed}\nFailed: {$faz_so_failed}\n\n";
if ( $faz_so_failed > 0 ) {
	echo "\033[31mFAIL\033[0m\n";
	exit( 1 );
}
echo "\033[32mPASS\033[0m\n";
