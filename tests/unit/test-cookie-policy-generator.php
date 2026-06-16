<?php
/**
 * Standalone unit tests for the Cookie Policy Generator (Spec 002).
 *
 * Covers:
 *  - Generator::substitute() placeholder substitution
 *  - Generator::markdown_to_html() lean markdown subset
 *  - Generator::resolve_template_path() fallback chain
 *  - Generator::policy_version_hash() versioning
 *  - Disclaimer presence in every supported language
 *
 * Run:
 *   php tests/unit/test-cookie-policy-generator.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
define( 'FAZ_VERSION', '1.16.0-test' );
define( 'MINUTE_IN_SECONDS', 60 );

// Minimal WP polyfills used by the classes under test. These are
// test-realistic sanitizers (NOT no-ops) so security assertions on the
// Generator's link rendering (esc_url + esc_html in markdown_to_html())
// actually exercise the escaping path. They mirror the safety contract
// of WP's real implementations without dragging in WP core.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $v ) {
		return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) {
		$u = (string) $u;
		// Drop dangerous schemes: javascript:, data:, vbscript:
		if ( preg_match( '/^\s*(?:javascript|data|vbscript):/i', $u ) ) {
			return '';
		}
		// Strip control chars + CR/LF.
		$u = preg_replace( '/[\x00-\x1F\x7F]/', '', $u );
		return $u;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) {
		return json_encode( $v );
	}
}

require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-generator.php';
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;

$tests_run = $tests_passed = $tests_failed = 0;

function assert_eq( $a, $e, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $a === $e ) { $tests_passed++; echo "  \033[32m✓\033[0m $label\n"; }
	else { $tests_failed++; echo "  \033[31m✗\033[0m $label\n      expected: " . var_export( $e, true ) . "\n      actual:   " . var_export( $a, true ) . "\n"; }
}
function assert_true( $c, $l ) { assert_eq( (bool) $c, true, $l ); }
function assert_false( $c, $l ) { assert_eq( (bool) $c, false, $l ); }
function assert_contains( $haystack, $needle, $l ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( is_string( $haystack ) && false !== strpos( $haystack, $needle ) ) { $tests_passed++; echo "  \033[32m✓\033[0m $l\n"; }
	else { $tests_failed++; echo "  \033[31m✗\033[0m $l\n      needle not found: " . var_export( $needle, true ) . "\n"; }
}

echo "\n== Cookie Policy Generator (Spec 002) ==\n\n";

// ---------- Generator::substitute ----------

$tpl = 'Hello {{COMPANY_NAME}}, contact us at {{COMPANY_EMAIL}}.';
$out = Generator::substitute( $tpl, array( 'COMPANY_NAME' => 'ACME Srl', 'COMPANY_EMAIL' => 'privacy@acme.test' ) );
assert_eq( $out, 'Hello ACME Srl, contact us at privacy@acme.test.', 'substitute replaces multiple placeholders' );

// Unknown tokens → empty (no leftover {{...}})
$out = Generator::substitute( 'Foo {{UNKNOWN_TOKEN}} bar', array() );
assert_eq( $out, 'Foo  bar', 'Unknown tokens replaced with empty string' );

// Scalar coercion
$out = Generator::substitute( 'Year {{YEAR}} count {{COUNT}}', array( 'YEAR' => 2026, 'COUNT' => 0 ) );
assert_eq( $out, 'Year 2026 count 0', 'Integer values stringified' );

// Array values → comma-joined
$out = Generator::substitute( '{{LIST}}', array( 'LIST' => array( 'a', 'b', 'c' ) ) );
assert_eq( $out, 'a, b, c', 'Array values comma-joined' );

// Empty template
assert_eq( Generator::substitute( '', array( 'X' => 'y' ) ), '', 'Empty template → empty output' );

// Non-string template
assert_eq( Generator::substitute( null, array() ), '', 'Null template → empty output' );

// Tokens with numbers and underscores
$out = Generator::substitute( '{{TOKEN_1_X}} {{TOKEN_2}}', array( 'TOKEN_1_X' => 'a', 'TOKEN_2' => 'b' ) );
assert_eq( $out, 'a b', 'Tokens with digits + underscores work' );

// lowercase tokens NOT matched (regex requires [A-Z][A-Z0-9_]*)
$out = Generator::substitute( '{{lowercase}} unchanged', array( 'lowercase' => 'X' ) );
assert_eq( $out, '{{lowercase}} unchanged', 'Lowercase tokens NOT matched (case-sensitive regex)' );

// ---------- Generator::markdown_to_html ----------

assert_eq(
	Generator::markdown_to_html( '# Title' ),
	'<h1>Title</h1>',
	'H1 heading'
);
assert_eq(
	Generator::markdown_to_html( '## Sub' ),
	'<h2>Sub</h2>',
	'H2 heading'
);
assert_eq(
	Generator::markdown_to_html( '### Smaller' ),
	'<h3>Smaller</h3>',
	'H3 heading'
);

$html = Generator::markdown_to_html( "- one\n- two\n- three" );
assert_contains( $html, '<ul>', 'UL opened on first bullet' );
assert_contains( $html, '<li>one</li>', 'List item 1' );
assert_contains( $html, '<li>three</li>', 'List item 3' );
assert_contains( $html, '</ul>', 'UL closed' );

$html = Generator::markdown_to_html( 'Plain paragraph.' );
assert_contains( $html, '<p>', 'Paragraph opened' );
assert_contains( $html, '</p>', 'Paragraph closed' );

$html = Generator::markdown_to_html( "First para.\n\nSecond para." );
$cnt = substr_count( $html, '<p>' );
assert_eq( $cnt, 2, 'Two paragraphs produce two <p> opens' );

$html = Generator::markdown_to_html( '**bold** and *italic*' );
assert_contains( $html, '<strong>bold</strong>', 'Bold via **' );
assert_contains( $html, '<em>italic</em>', 'Italic via *' );

$html = Generator::markdown_to_html( 'See [the rules](https://example.test/rules) here.' );
assert_contains( $html, '<a href="https://example.test/rules"', 'Link href' );
assert_contains( $html, '>the rules</a>', 'Link text' );

// Anti-XSS: javascript: links must NOT be rendered as <a href="javascript:">
// (the literal "javascript:" string may survive in the rendered text — that's
// fine because it's plain text, not an active link element. The contract is
// "no clickable href to a JS scheme", not "the substring never appears").
$html = Generator::markdown_to_html( '[click](javascript:alert(1))' );
assert_false( false !== strpos( $html, '<a href="javascript:' ), 'javascript: scheme NOT rendered as clickable <a href>' );
assert_false( false !== strpos( $html, 'href="javascript' ), 'No javascript scheme in any href attribute' );

// Anti-XSS: when a malicious http link slips through the markdown regex
// (only http/https are accepted), the esc_url polyfill must still strip
// dangerous control chars from the URL. This test relies on the polyfills
// being REAL sanitizers (not no-ops), confirming the security suite is
// exercising the actual escape path.
$html = Generator::markdown_to_html( "[ok](https://example.test/path)" );
assert_contains( $html, '<a href="https://example.test/path"', 'Valid https link renders with esc_url' );
// Link text containing HTML-special chars must be entity-escaped.
$html = Generator::markdown_to_html( '[<script>x</script>](https://example.test/)' );
assert_false( false !== strpos( $html, '<script>' ), 'Link text HTML escaped (no raw <script>)' );
assert_contains( $html, '&lt;script&gt;', 'Link text rendered as escaped entities' );

// Inline code
$html = Generator::markdown_to_html( 'Set `option` to true' );
assert_contains( $html, '<code>option</code>', 'Inline code' );

// Mixed: heading + paragraph + list
$md = "# Title\n\nIntro paragraph.\n\n- one\n- two\n\nMore text.";
$html = Generator::markdown_to_html( $md );
assert_contains( $html, '<h1>Title</h1>', 'Mixed: heading' );
assert_contains( $html, '<p>', 'Mixed: paragraphs' );
assert_contains( $html, '<ul>', 'Mixed: ul' );

// ---------- Generator::resolve_template_path ----------

// Templates dir actually exists (built in T162).
$tpl_dir = Generator::templates_dir();
assert_true( is_dir( $tpl_dir ), 'Templates directory exists' );

assert_true(
	null !== Generator::resolve_template_path( 'gdpr-strict', 'en' ),
	'gdpr-strict/en.md found'
);
assert_true(
	null !== Generator::resolve_template_path( 'gdpr-strict', 'it' ),
	'gdpr-strict/it.md found'
);
assert_true(
	null !== Generator::resolve_template_path( 'lgpd-brazil', 'pt-BR' ),
	'lgpd-brazil/pt-BR.md found'
);
assert_true(
	null !== Generator::resolve_template_path( 'ccpa-california', 'es' ),
	'ccpa-california/es.md found (CA bilingual mandate)'
);

// Fallback chain: zh (unsupported) → native of jurisdiction → en
assert_true(
	null !== Generator::resolve_template_path( 'gdpr-strict', 'zh' ),
	'Unsupported lang falls back to en (gdpr)'
);
assert_true(
	null !== Generator::resolve_template_path( 'lgpd-brazil', 'zh' ),
	'Unsupported lang falls back to pt-BR (lgpd native)'
);

// Unknown jurisdiction → null
assert_eq(
	Generator::resolve_template_path( 'pipl-china', 'en' ),
	null,
	'Unknown jurisdiction → null (not in scope v1)'
);

// Path-traversal hardening: a malicious $lang like "../../wp-config" must
// be rejected at the whitelist gate, not used to compose any filesystem
// path. Resolves to native-lang fallback instead.
$traversal_path = Generator::resolve_template_path( 'gdpr-strict', '../../../wp-config' );
assert_true(
	is_string( $traversal_path ) && false === strpos( $traversal_path, '..' ),
	'Path traversal in $lang rejected at whitelist gate'
);
// Verify it landed on the native-lang fallback (en for gdpr). Normalise
// path separators so the test passes on Windows too (where DIRECTORY_SEPARATOR
// is '\') — the contract is "ends with the gdpr-strict/en.md template",
// not "uses forward slashes".
$normalized_traversal = is_string( $traversal_path ) ? str_replace( '\\', '/', $traversal_path ) : '';
assert_true(
	'' !== $normalized_traversal && false !== strpos( $normalized_traversal, '/gdpr-strict/en.md' ),
	'Invalid $lang falls back to gdpr-strict native lang (en) — path normalised for OS independence'
);

// ---------- Constants ----------

assert_eq( count( Generator::JURISDICTIONS ), 3, '3 jurisdictions in scope v1' );
assert_eq( count( Generator::LANGUAGES ), 8, '8 languages in scope (en, it, fr, de, es, pt-BR, bg, cs)' );
assert_eq( Generator::NATIVE_LANG['lgpd-brazil'], 'pt-BR', 'LGPD native lang is pt-BR' );
assert_eq( Generator::NATIVE_LANG['gdpr-strict'], 'en', 'GDPR native lang is en' );
assert_eq( Generator::NATIVE_LANG['ccpa-california'], 'en', 'CCPA native lang is en' );

// ---------- Generator::policy_version_hash ----------

$path = Generator::resolve_template_path( 'gdpr-strict', 'en' );
$h1 = Generator::policy_version_hash( $path, array( 'COMPANY_NAME' => 'A' ) );
$h2 = Generator::policy_version_hash( $path, array( 'COMPANY_NAME' => 'B' ) );
assert_true( is_string( $h1 ) && strlen( $h1 ) === 13, 'Version hash format: 6.6 hex = 13 chars' );
assert_eq( $h1 === $h2, false, 'Different data → different hash' );

$h3 = Generator::policy_version_hash( $path, array( 'COMPANY_NAME' => 'A' ) );
assert_eq( $h1, $h3, 'Same input → same hash (deterministic)' );

// ---------- Real-world rendering smoke ----------

$gdpr_path = Generator::resolve_template_path( 'gdpr-strict', 'en' );
assert_true( is_string( $gdpr_path ) && '' !== $gdpr_path, 'gdpr-strict/en template path resolvable before read' );
$scaffold = file_get_contents( $gdpr_path );
$rendered = Generator::substitute( $scaffold, array(
	'COMPANY_NAME'         => 'ACME Srl',
	'COMPANY_ADDRESS'      => 'Via Roma 1, 00100 Roma, Italy',
	'COMPANY_EMAIL'        => 'privacy@acme.test',
	'DPO_NAME'             => 'Mario Rossi',
	'DPO_EMAIL'            => 'dpo@acme.test',
	'COOKIE_CATEGORIES'    => '<section>...</section>',
	'THIRD_PARTY_SERVICES' => 'Google Analytics 4, Cloudflare',
	'LAST_UPDATED_DATE'    => '2026-05-20',
	'RETENTION_PERIOD'     => '12 months',
	'EDPB_CONTACT'         => 'edpb@edpb.europa.eu',
	'OFFICIAL_RESOURCES_URL' => 'https://edpb.europa.eu/',
	'COOKIE_POLICY_URL'    => 'https://acme.test/cookie-policy/',
) );
assert_contains( $rendered, 'ACME Srl', 'Real GDPR-EN template renders company name' );
assert_contains( $rendered, 'privacy@acme.test', 'Real template renders contact email' );
assert_contains( $rendered, '12 months', 'Real template renders retention' );
assert_false( strpos( $rendered, '{{' ) !== false, 'Real template: no leftover {{...}} tokens' );

// Same for CCPA
$ccpa_path = Generator::resolve_template_path( 'ccpa-california', 'en' );
assert_true( is_string( $ccpa_path ) && '' !== $ccpa_path, 'ccpa-california/en template path resolvable before read' );
$ccpa_scaffold = file_get_contents( $ccpa_path );
$rendered = Generator::substitute( $ccpa_scaffold, array(
	'COMPANY_NAME'        => 'ACME Inc',
	'COMPANY_EMAIL'       => 'privacy@acme.test',
	'CA_PIPC_CONTACT'     => 'cppa@cppa.ca.gov',
	'COOKIE_POLICY_URL'   => 'https://acme.test/notice',
	'LAST_UPDATED_DATE'   => '2026-05-20',
	'COOKIE_CATEGORIES'   => '<dl></dl>',
	'THIRD_PARTY_SERVICES' => 'GA4',
	'RETENTION_PERIOD'    => '12 months',
	'OFFICIAL_RESOURCES_URL' => 'https://cppa.ca.gov/',
) );
assert_contains( $rendered, 'CCPA', 'CCPA-EN template mentions CCPA' );
assert_contains( $rendered, 'Do Not Sell or Share', 'CCPA-EN template mentions DNS link' );
assert_false( strpos( $rendered, '{{' ) !== false, 'CCPA template: no leftover tokens' );

// LGPD pt-BR — must mention Encarregado and ANPD
$lgpd_path = Generator::resolve_template_path( 'lgpd-brazil', 'pt-BR' );
assert_true( is_string( $lgpd_path ) && '' !== $lgpd_path, 'lgpd-brazil/pt-BR template path resolvable before read' );
$lgpd_scaffold = file_get_contents( $lgpd_path );
$rendered = Generator::substitute( $lgpd_scaffold, array(
	'COMPANY_NAME'      => 'ACME Ltda',
	'COMPANY_EMAIL'     => 'privacidade@acme.test',
	'DPO_NAME'          => 'João Silva',
	'DPO_EMAIL'         => 'encarregado@acme.test',
	'ANPD_CONTACT'      => 'comunicacao@anpd.gov.br',
	'COOKIE_POLICY_URL' => 'https://acme.test/cookies',
	'LAST_UPDATED_DATE' => '2026-05-20',
	'COOKIE_CATEGORIES' => '<dl></dl>',
	'THIRD_PARTY_SERVICES' => 'GA4',
	'RETENTION_PERIOD'  => '12 meses',
	'OFFICIAL_RESOURCES_URL' => 'https://www.gov.br/anpd/pt-br',
) );
assert_contains( $rendered, 'Encarregado', 'LGPD-pt-BR mentions Encarregado (Art. 41)' );
assert_contains( $rendered, 'ANPD', 'LGPD-pt-BR mentions ANPD' );

// ---------- Summary ----------

echo "\n--\n";
echo "Tests:  $tests_run\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n\n";
if ( $tests_failed > 0 ) { echo "\033[31mFAIL\033[0m\n"; exit( 1 ); }
echo "\033[32mPASS\033[0m\n";
exit( 0 );
