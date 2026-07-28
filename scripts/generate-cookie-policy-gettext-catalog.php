<?php
/**
 * Generate the PHP gettext catalogue for Cookie Policy Markdown templates.
 *
 * Usage: php scripts/generate-cookie-policy-gettext-catalog.php
 *
 * @package FazCookie\Build
 */

$root          = dirname( __DIR__ );
$templates     = $root . '/admin/modules/cookie-policy-generator/templates';
$destination   = $root . '/admin/modules/cookie-policy-generator/includes/cookie-policy-gettext-catalog.php';
$jurisdictions = array( 'gdpr-strict', 'ccpa-california', 'lgpd-brazil', 'popia-southafrica' );

/**
 * Split a policy into its introduction plus H2 sections.
 *
 * @param string $markdown Policy Markdown.
 * @return string[]
 */
function faz_policy_catalog_sections( $markdown ) {
	$parts = preg_split( '/(?=^##[ \t]+)/m', str_replace( array( "\r\n", "\r" ), "\n", $markdown ) );
	if ( ! is_array( $parts ) ) {
		return array();
	}
	return array_values(
		array_filter(
			array_map( 'trim', $parts ),
			static function ( $part ) {
				return '' !== $part;
			}
		)
	);
}

$lines   = array();
$lines[] = '<?php';
$lines[] = '/**';
$lines[] = ' * Generated gettext catalogue for Cookie Policy Markdown sections.';
$lines[] = ' *';
$lines[] = ' * DO NOT EDIT: run scripts/generate-cookie-policy-gettext-catalog.php.';
$lines[] = ' *';
$lines[] = ' * @package FazCookie\\Admin\\Modules\\Cookie_Policy_Generator\\Includes';
$lines[] = ' */';
$lines[] = '';
$lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
$lines[] = "\texit;";
$lines[] = '}';
$lines[] = '';
$lines[] = 'return array(';

foreach ( $jurisdictions as $jurisdiction ) {
	$source_path = $templates . '/' . $jurisdiction . '/en.md';
	$markdown    = file_get_contents( $source_path );
	if ( false === $markdown || '' === $markdown ) {
		fwrite( STDERR, "Cannot read canonical policy template: {$source_path}\n" );
		exit( 1 );
	}
	$sections = faz_policy_catalog_sections( $markdown );
	$lines[]  = "\t" . var_export( $jurisdiction, true ) . ' => array(';
	foreach ( $sections as $index => $section ) {
		$section_name = 0 === $index ? 'introduction' : 'section-' . $index;
		$context      = 'Cookie policy template: ' . $jurisdiction . ' / ' . $section_name;
		$lines[]      = "\t\t_x(";
		// Do not indent continuation lines inside the exported literal: leading
		// whitespace would become part of the gettext msgid and stop it matching
		// the canonical Markdown section read at runtime.
		$lines[] = "\t\t\t" . var_export( $section, true ) . ',';
		$lines[] = "\t\t\t" . var_export( $context, true ) . ',';
		$lines[] = "\t\t\t'faz-cookie-manager'";
		$lines[] = "\t\t),";
	}
	$lines[] = "\t),";
}

$lines[] = ');';
$lines[] = '';

$written = file_put_contents( $destination, implode( "\n", $lines ) );
if ( false === $written ) {
	fwrite( STDERR, "Cannot write generated catalogue: {$destination}\n" );
	exit( 1 );
}

fwrite( STDOUT, "Generated {$destination}\n" );
