<?php
/**
 * Generate a PO fragment from one shipped Cookie Policy template language.
 *
 * Usage:
 *   php scripts/generate-cookie-policy-po-fragment.php \
 *     cs languages/faz-cookie-manager-cs_CZ.po /tmp/policy-cs.po
 *
 * Merge the fragment before the locale PO so these source-derived policy
 * translations win while the existing PO header and all UI translations are
 * retained:
 *   msgcat --use-first /tmp/policy-cs.po languages/faz-cookie-manager-cs_CZ.po
 *
 * A jurisdiction is omitted, with an explicit STDERR warning, when its
 * translated template no longer has the same section/placeholder structure
 * as the canonical English template. Other safe jurisdictions are still
 * emitted so one legacy template cannot make the whole locale resync unusable.
 *
 * @package FazCookie\Build
 */

if ( 4 !== $argc ) {
	fwrite( STDERR, "Usage: php {$argv[0]} <template-lang> <existing-locale.po> <output.po>\n" );
	exit( 2 );
}

$lang          = (string) $argv[1];
$header_source = (string) $argv[2];
$destination   = (string) $argv[3];
$root          = dirname( __DIR__ );
$templates     = $root . '/admin/modules/cookie-policy-generator/templates';
$jurisdictions = array( 'gdpr-strict', 'ccpa-california', 'lgpd-brazil', 'popia-southafrica' );

/**
 * Split a policy into its introduction plus H2 sections.
 *
 * @param string $markdown Policy Markdown.
 * @return string[]
 */
function faz_policy_po_sections( $markdown ) {
	$parts = preg_split( '/(?=^##[ \t]+)/m', str_replace( array( "\r\n", "\r" ), "\n", $markdown ) );
	if ( ! is_array( $parts ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
}

/**
 * Return the sorted placeholder multiset from a policy section.
 *
 * @param string $section Policy section.
 * @return string[]
 */
function faz_policy_po_tokens( $section ) {
	$matches = array();
	preg_match_all( '/\{\{[A-Z][A-Z0-9_]*\}\}/', $section, $matches );
	$tokens = $matches[0] ?? array();
	sort( $tokens, SORT_STRING );
	return $tokens;
}

/**
 * Encode one complete PO string without ASCII-escaping translated text.
 *
 * @param string $value PO field value.
 * @return string|false
 */
function faz_policy_po_quote( $value ) {
	return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

if ( ! is_readable( $header_source ) ) {
	fwrite( STDERR, "Cannot read locale PO header: {$header_source}\n" );
	exit( 1 );
}
$existing_po = str_replace( "\r\n", "\n", (string) file_get_contents( $header_source ) );
$header_end  = strpos( $existing_po, "\n\n" );
if ( false === $header_end ) {
	fwrite( STDERR, "Cannot parse locale PO header: {$header_source}\n" );
	exit( 1 );
}
$header  = rtrim( substr( $existing_po, 0, $header_end ) );
$entries = array();
$skipped = array();
foreach ( $jurisdictions as $jurisdiction ) {
	$source_path      = $templates . '/' . $jurisdiction . '/en.md';
	$translation_path = $templates . '/' . $jurisdiction . '/' . $lang . '.md';
	if ( ! is_readable( $source_path ) || ! is_readable( $translation_path ) ) {
		fwrite( STDERR, "Missing source or translation for {$jurisdiction}/{$lang}.\n" );
		exit( 1 );
	}
	$source_sections = faz_policy_po_sections( (string) file_get_contents( $source_path ) );
	$local_sections  = faz_policy_po_sections( (string) file_get_contents( $translation_path ) );
	if ( count( $source_sections ) !== count( $local_sections ) ) {
		$skipped[] = "{$jurisdiction}/{$lang}: section count " . count( $local_sections ) . ' != ' . count( $source_sections );
		continue;
	}

	$jurisdiction_entries = array();
	$placeholder_mismatch = false;
	foreach ( $source_sections as $index => $source ) {
		$translation = $local_sections[ $index ];
		if ( faz_policy_po_tokens( $source ) !== faz_policy_po_tokens( $translation ) ) {
			$skipped[] = "{$jurisdiction}/{$lang}: placeholder mismatch in section {$index}";
			$placeholder_mismatch = true;
			break;
		}
		$section_name = 0 === $index ? 'introduction' : 'section-' . $index;
		$context      = 'Cookie policy template: ' . $jurisdiction . ' / ' . $section_name;
		$jurisdiction_entries[] = 'msgctxt ' . faz_policy_po_quote( $context ) . "\n"
			. 'msgid ' . faz_policy_po_quote( $source ) . "\n"
			. 'msgstr ' . faz_policy_po_quote( $translation ) . "\n";
	}
	if ( ! $placeholder_mismatch ) {
		$entries = array_merge( $entries, $jurisdiction_entries );
	}
}

if ( ! $entries ) {
	fwrite( STDERR, "No policy sections could be merged safely; no fragment was written.\n" );
	foreach ( $skipped as $reason ) {
		fwrite( STDERR, "Skipped {$reason}.\n" );
	}
	exit( 1 );
}

$written = file_put_contents( $destination, $header . "\n\n" . implode( "\n", $entries ) );
if ( false === $written ) {
	fwrite( STDERR, "Cannot write PO fragment: {$destination}\n" );
	exit( 1 );
}

fwrite( STDOUT, "Generated {$destination} with " . count( $entries ) . " safely matched policy sections.\n" );
foreach ( $skipped as $reason ) {
	fwrite( STDERR, "Skipped {$reason}; update that template before including it in the PO fragment.\n" );
}
