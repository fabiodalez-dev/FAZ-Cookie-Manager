<?php
/**
 * Standalone tests for section-level Cookie Policy gettext overrides.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['faz_policy_test_locale']       = 'cs_CZ';
$GLOBALS['faz_policy_test_translations'] = array(
	'Cookie policy template: gdpr-strict / section-9' => "## Vlastní kontakt\n\nPište nám na **{{COMPANY_EMAIL}}**.",
	// Invalid on purpose: the canonical CCPA introduction contains required
	// LAST_UPDATED_DATE and COMPANY_NAME placeholders.
	'Cookie policy template: ccpa-california / introduction' => '# Rozbitý překlad bez zástupných symbolů',
);

function determine_locale() {
	return $GLOBALS['faz_policy_test_locale'];
}

function _x( $text, $context, $domain ) {
	if ( 'faz-cookie-manager' !== $domain ) {
		return $text;
	}
	return $GLOBALS['faz_policy_test_translations'][ $context ] ?? $text;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-template-translations.php';

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Template_Translations;

$tests_run = $tests_passed = $tests_failed = 0;

function assert_eq( $actual, $expected, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $actual === $expected ) {
		$tests_passed++;
		echo "  \033[32m✓\033[0m {$label}\n";
		return;
	}
	$tests_failed++;
	echo "  \033[31m✗\033[0m {$label}\n";
}

function assert_true( $condition, $label ) {
	assert_eq( (bool) $condition, true, $label );
}

echo "\n== Cookie Policy template gettext overrides ==\n\n";

$gdpr_cs_path = Generator::resolve_template_path( 'gdpr-strict', 'cs' );
$gdpr_cs      = (string) file_get_contents( $gdpr_cs_path );
$effective    = Template_Translations::apply( 'gdpr-strict', 'cs', $gdpr_cs );

assert_true(
	false !== strpos( $effective, '<strong>{{COMPANY_EMAIL}}</strong>' )
		|| false !== strpos( $effective, '**{{COMPANY_EMAIL}}**' ),
	'valid gettext override preserves COMPANY_EMAIL placeholder'
);
assert_true(
	false !== strpos( $effective, '## Vlastní kontakt' ),
	'valid gettext override replaces its matching policy section'
);
assert_true(
	false !== strpos( $effective, '## Kdo jsme' ),
	'untranslated sections keep the reviewed bundled Czech copy'
);

$ccpa_cs_path = Generator::resolve_template_path( 'ccpa-california', 'cs' );
$ccpa_cs      = (string) file_get_contents( $ccpa_cs_path );
$ccpa_result  = Template_Translations::apply( 'ccpa-california', 'cs', $ccpa_cs );
assert_eq(
	$ccpa_result,
	$ccpa_cs,
	'invalid translation that drops required placeholders falls back to the intact bundled policy'
);

$GLOBALS['faz_policy_test_locale'] = 'en_US';
$mismatch_result                   = Template_Translations::apply( 'gdpr-strict', 'cs', $gdpr_cs );
assert_eq(
	$mismatch_result,
	$gdpr_cs,
	'gettext overrides are ignored when the active WP locale does not match shortcode lang'
);

$hash_a = Generator::policy_version_hash( $gdpr_cs_path, array( 'COMPANY_NAME' => 'ACME' ), $gdpr_cs );
$hash_b = Generator::policy_version_hash( $gdpr_cs_path, array( 'COMPANY_NAME' => 'ACME' ), $effective );
assert_true( $hash_a !== $hash_b, 'gettext policy edits change the public policy version hash' );

$catalog_source = (string) file_get_contents( Template_Translations::CATALOG_FILE );
assert_eq(
	substr_count( $catalog_source, "'Cookie policy template:" ),
	44,
	'generated source exposes all 44 jurisdiction sections to WordPress gettext extraction (10 gdpr + 13 ccpa + 10 lgpd + 11 popia)'
);

$fragment_path = tempnam( sys_get_temp_dir(), 'faz-policy-po-' );
$fragment_log  = array();
$fragment_exit = 1;
$fragment_cmd  = escapeshellarg( PHP_BINARY ) . ' '
	. escapeshellarg( dirname( __DIR__, 2 ) . '/scripts/generate-cookie-policy-po-fragment.php' ) . ' it '
	. escapeshellarg( dirname( __DIR__, 2 ) . '/languages/faz-cookie-manager-it_IT.po' ) . ' '
	. escapeshellarg( $fragment_path ) . ' 2>&1';
exec( $fragment_cmd, $fragment_log, $fragment_exit );
$fragment_source = is_readable( $fragment_path ) ? (string) file_get_contents( $fragment_path ) : '';
if ( is_string( $fragment_path ) && file_exists( $fragment_path ) ) {
	unlink( $fragment_path );
}
assert_eq( $fragment_exit, 0, 'PO fragment generation remains usable when one legacy jurisdiction is structurally stale' );
assert_true(
	false !== strpos( implode( "\n", $fragment_log ), 'Skipped ccpa-california/it' ),
	'PO fragment generation reports skipped unsafe jurisdiction merges'
);
assert_true(
	false !== strpos( $fragment_source, 'Cookie policy template: popia-southafrica / introduction' ),
	'PO fragment retains safely aligned jurisdictions after a skip'
);

echo "\n--\n";
echo "Tests:  {$tests_run}\n";
echo "Passed: {$tests_passed}\n";
echo "Failed: {$tests_failed}\n\n";
if ( $tests_failed > 0 ) {
	echo "\033[31mFAIL\033[0m\n";
	exit( 1 );
}
echo "\033[32mALL PASS\033[0m\n";
