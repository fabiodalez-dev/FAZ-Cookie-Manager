<?php
/**
 * Standalone regression tests for banner theme/CSS contracts.
 *
 * @package FazCookie\Tests\Unit
 */

$root         = dirname( __DIR__, 2 );
$tests_run    = 0;
$tests_passed = 0;
$tests_failed = 0;

function faz_theme_assert( $condition, $label ) {
	global $tests_run, $tests_passed, $tests_failed;
	++$tests_run;
	if ( $condition ) {
		++$tests_passed;
		echo "  \033[32m✓\033[0m {$label}\n";
		return;
	}
	++$tests_failed;
	echo "  \033[31m✗\033[0m {$label}\n";
}

function faz_theme_json( $path ) {
	$data = json_decode( file_get_contents( $path ), true );
	faz_theme_assert( is_array( $data ), basename( $path ) . ' is valid JSON' );
	return $data;
}

function faz_theme_luminance( $hex ) {
	$hex      = ltrim( $hex, '#' );
	$channels = array();
	for ( $index = 0; $index < 3; ++$index ) {
		$value      = hexdec( substr( $hex, $index * 2, 2 ) ) / 255;
		$channels[] = $value <= 0.04045 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
	}
	return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
}

function faz_theme_contrast( $foreground, $background ) {
	$first  = faz_theme_luminance( $foreground );
	$second = faz_theme_luminance( $background );
	return ( max( $first, $second ) + 0.05 ) / ( min( $first, $second ) + 0.05 );
}

echo "\n== Banner theme/CSS contracts ==\n";

$preset_dir = $root . '/admin/modules/banners/includes/presets';
$presets    = glob( $preset_dir . '/*.json' );
foreach ( $presets as $preset_path ) {
	$preset     = faz_theme_json( $preset_path );
	$config     = $preset['config'];
	$foreground = $config['accessibilityOverrides']['elements']['preferenceCenter']['elements']['alwaysActive']['styles']['color'] ?? '';
	$background = $config['preferenceCenter']['styles']['background-color'] ?? '';
	$name       = $preset['name'] ?? basename( $preset_path );
	faz_theme_assert( '' !== $foreground, "{$name} defines the Always Active theme colour" );
	faz_theme_assert( '' !== $background, "{$name} defines the preference-center background colour" );
	faz_theme_assert( faz_theme_contrast( $foreground, $background ) >= 7, "{$name} Always Active colour has AAA contrast" );
}

$dark         = faz_theme_json( $preset_dir . '/dark-professional.json' );
$audit_styles = $dark['config']['auditTable']['styles'] ?? array();
faz_theme_assert( isset( $audit_styles['color'], $audit_styles['background-color'], $audit_styles['border-color'] ), 'Dark Professional defines the complete audit-table palette' );
if ( isset( $audit_styles['color'], $audit_styles['background-color'] ) ) {
	faz_theme_assert( faz_theme_contrast( $audit_styles['color'], $audit_styles['background-color'] ) >= 4.5, 'Dark Professional audit table text has AA contrast' );
}

foreach ( array( '6.0.0', '6.2.0' ) as $version ) {
	$shortcodes = file_get_contents( $root . "/frontend/modules/shortcodes/versions/{$version}/shortcodes.json" );
	faz_theme_assert( false !== strpos( $shortcodes, 'class=\\"faz-always-active\\" data-faz-tag=\\"always-active\\"' ), "Shortcodes {$version} expose the canonical Always Active tag" );
}

$frontend = file_get_contents( $root . '/frontend/class-frontend.php' );
faz_theme_assert( false !== strpos( $frontend, "'always-active'," ), 'Frontend runtime exports the canonical Always Active tag' );
faz_theme_assert( false === strpos( $frontend, "'faz-always-active'," ), 'Frontend runtime no longer exports the invalid prefixed tag' );

$preview = file_get_contents( $root . '/admin/modules/banners/api/class-api.php' );
foreach ( array( 'detail-title', 'detail-description', 'detail-category-description', 'audit-table' ) as $tag ) {
	$variable = '--faz-' . $tag . '-color';
	faz_theme_assert( false !== strpos( $frontend, $variable ), "Frontend CSS consumes {$variable}" );
	faz_theme_assert( false !== strpos( $preview, $variable ), "Admin preview CSS consumes {$variable}" );
}

$banner_editor = file_get_contents( $root . '/admin/assets/js/pages/banner.js' );
faz_theme_assert( false !== strpos( $banner_editor, "applyPresetSection('auditTable', c.auditTable)" ), 'Design presets apply audit-table styles' );
faz_theme_assert( false !== strpos( $banner_editor, 'accessibilityOverrides.elements.preferenceCenter.elements.alwaysActive.styles' ), 'Design presets apply Always Active styles' );

echo "\n{$tests_passed}/{$tests_run} assertions passed\n";
exit( $tests_failed > 0 ? 1 : 0 );
