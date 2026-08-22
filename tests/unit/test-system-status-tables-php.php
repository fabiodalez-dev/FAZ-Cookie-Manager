<?php
/**
 * System Status tables — a data grid is not a label:value list.
 *
 * .faz-status-table is built for two-column "Plugin Version | 1.26.0" rows: it
 * bolds the first cell and gives it 40% width. "Recently Blocked Server
 * Cookies" reused it for four DATA columns with no header row at all, so the
 * cookie name rendered as a row label and the category, request URI and
 * timestamp beside it had no stated meaning — on the page an administrator
 * opens to check that the risky block_server_cookies setting is not breaking
 * checkout or login.
 *
 * The structural rule is asserted rather than the one table: any status table
 * with more than two columns must caption them. Add a fifth column to the
 * blocked-cookies table, or a third to any other, and this goes red.
 */

$passed = 0;
$failed = 0;
function ss_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "  [PASS] {$label}\n";
		return;
	}
	++$failed;
	echo "  [FAIL] {$label}\n";
}

$view_path = dirname( __DIR__, 2 ) . '/admin/views/system-status.php';
$css_path  = dirname( __DIR__, 2 ) . '/admin/assets/css/faz-admin.css';
$view      = file_get_contents( $view_path );
$css       = file_get_contents( $css_path );

// Reduce the template to its markup skeleton. Every PHP island becomes one
// text node, which is all the column arithmetic below needs; the loops
// collapse to a single representative <tr>, which is exactly the row shape
// under inspection.
$markup = preg_replace( '/<\?(?:php|=).*?\?>/s', 'X', $view );
$markup = preg_replace( '/<\?(?:php|=).*$/s', 'X', $markup );

$doc = new DOMDocument();
libxml_use_internal_errors( true );
$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $markup );
libxml_clear_errors();

$xpath  = new DOMXPath( $doc );
$tables = $xpath->query( '//table[contains(concat(" ", normalize-space(@class), " "), " faz-status-table ")]' );

ss_ok( $tables->length >= 5, 'the System Status page still renders its status tables (' . $tables->length . ' found)' );

$wide            = 0;
$narrow          = 0;
$captioned_wide  = 0;
$variant_on_wide = 0;
$variant_on_narrow = 0;
$header_matches_columns = 0;
$scoped_headers  = 0;

foreach ( $tables as $table ) {
	$columns = 0;
	foreach ( $xpath->query( './/tr', $table ) as $row ) {
		$cells   = $xpath->query( './td', $row )->length;
		$columns = max( $columns, $cells );
	}
	$headers   = $xpath->query( './/thead/tr/th', $table );
	$class     = ' ' . preg_replace( '/\s+/', ' ', (string) $table->getAttribute( 'class' ) ) . ' ';
	$is_variant = false !== strpos( $class, ' faz-status-table-data ' );

	if ( $columns > 2 ) {
		++$wide;
		if ( $headers->length > 0 ) {
			++$captioned_wide;
		}
		if ( $headers->length === $columns ) {
			++$header_matches_columns;
		}
		if ( $is_variant ) {
			++$variant_on_wide;
		}
		$all_scoped = $headers->length > 0;
		foreach ( $headers as $header ) {
			if ( 'col' !== $header->getAttribute( 'scope' ) ) {
				$all_scoped = false;
			}
		}
		if ( $all_scoped ) {
			++$scoped_headers;
		}
	} else {
		++$narrow;
		if ( $is_variant || $headers->length > 0 ) {
			++$variant_on_narrow;
		}
	}
}

ss_ok( $wide > 0, 'at least one status table carries more than two columns — the case this rule exists for' );
ss_ok( $wide === $captioned_wide, 'every status table with more than two columns has a header row' );
ss_ok( $wide === $header_matches_columns, 'the header row names EVERY column — a caption short of the data is worse than none' );
ss_ok( $wide === $scoped_headers, 'each header cell declares scope="col", so a screen reader announces it with the cell below' );
ss_ok( $wide === $variant_on_wide, 'a multi-column status table opts into the data variant rather than the label:value styling' );

// The reason this is a variant and not an edit to the shared rules: four other
// tables on this page depend on the label:value styling, and they must be
// untouched by it.
ss_ok( $narrow >= 4, 'the label:value status tables are still there to be protected (' . $narrow . ' found)' );
ss_ok( 0 === $variant_on_narrow, 'no two-column status table carries the data variant or a header row' );

echo "== The styling reaches the variant and stops there ==\n";

$css_squeezed = preg_replace( '/\s+/', ' ', $css );

// Unchanged base rule. Relaxing it here instead of overriding it in the variant
// would silently restyle the Environment, Plugin Configuration, Database and
// Cron Jobs tables, which is the regression this whole approach avoids.
ss_ok(
	false !== strpos( $css_squeezed, '.faz-status-table td:first-child { font-weight: 600; width: 40%; color: var(--faz-text-secondary); }' ),
	'the shared label:value rule is left exactly as it was'
);
ss_ok(
	false !== strpos( $css_squeezed, '.faz-status-table thead th {' ),
	'header cells are styled — and only a table that HAS a thead can be reached by that selector'
);
ss_ok(
	false !== strpos( $css_squeezed, '.faz-status-table-data td:first-child { font-weight: 400; width: auto;' ),
	'the variant releases the first cell from being a 40% bold row label'
);
// No unscoped override may exist: a bare `.faz-status-table td { ... }` added
// after the base block would travel to all five tables.
ss_ok(
	1 === substr_count( $css_squeezed, '.faz-status-table td:first-child {' ),
	'the first-cell rule is declared once — a second, unscoped copy would reach the label:value tables'
);

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
