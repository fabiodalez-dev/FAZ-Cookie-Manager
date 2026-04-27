<?php
/**
 * Minimal frontend shell for the admin banner preview iframe.
 *
 * @package FazCookie\Frontend
 */

defined( 'ABSPATH' ) || exit;

ob_start();
wp_head();
$faz_head_markup = ob_get_clean();
if ( class_exists( 'DOMDocument' ) ) {
	$faz_dom = new DOMDocument();
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed HTML from wp_head is expected.
	@$faz_dom->loadHTML( '<!DOCTYPE html><html><head>' . $faz_head_markup . '</head></html>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );
	$faz_scripts = $faz_dom->getElementsByTagName( 'script' );
	while ( $faz_scripts->length > 0 ) {
		$faz_scripts->item( 0 )->parentNode->removeChild( $faz_scripts->item( 0 ) );
	}
	$faz_head_markup = preg_replace( '#^.*?<head>|</head>.*$#is', '', $faz_dom->saveHTML() );
} else {
	$faz_head_markup = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $faz_head_markup );
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<title><?php esc_html_e( 'Banner Preview', 'faz-cookie-manager' ); ?></title>
	<?php echo $faz_head_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffered wp_head markup is already escaped by core/theme APIs. ?>
	<?php
	/*
	 * Critical CSS for the iframe shell — must be inline.
	 *
	 * This file renders the *entire* HTML document for the banner-preview
	 * iframe (note the standalone `<!doctype html><html><head>` above).
	 * Routing this CSS through wp_enqueue_style() would require a second
	 * HTTP round-trip per preview load, during which the iframe body
	 * would render at zero height (no min-height: 100vh on body) and
	 * cause a visible FOUC + layout snap before the banner mounts. The
	 * style is small, self-contained, and only declares layout glue for
	 * the preview shell — it does not exist anywhere else in the plugin.
	 */
	?>
	<style id="faz-banner-preview-frame-shell"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- critical CSS for the iframe document shell; see comment above. ?>
		html,
		body {
			margin: 0;
			padding: 0;
			min-height: 100vh;
			overflow: hidden;
			background: transparent;
		}

		#faz-b-preview-root {
			position: relative;
			min-height: 100vh;
		}
	</style>
</head>
<body <?php body_class( 'faz-banner-preview-frame' ); ?>>
	<div id="faz-b-preview-root">
		<noscript><?php esc_html_e( 'JavaScript is required for banner preview.', 'faz-cookie-manager' ); ?></noscript>
	</div>
</body>
</html>
