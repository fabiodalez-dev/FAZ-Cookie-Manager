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
$faz_head_markup = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $faz_head_markup );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<title><?php esc_html_e( 'Banner Preview', 'faz-cookie-manager' ); ?></title>
	<?php echo $faz_head_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffered wp_head markup is already escaped by core/theme APIs. ?>
	<style id="faz-banner-preview-frame-shell">
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
	<div id="faz-b-preview-root"></div>
</body>
</html>
