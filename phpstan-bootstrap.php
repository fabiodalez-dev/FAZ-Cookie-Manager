<?php
/**
 * Constants supplied by WordPress at runtime but required for static analysis.
 *
 * Keep this file repository-owned: phpstan.neon references it, and a clean CI
 * checkout must be able to run the same analysis as a developer machine.
 *
 * @package FazCookie
 */

define( 'FAZ_VERSION', '0.0.0-static-analysis' );
define( 'FAZ_PLUGIN_BASENAME', 'faz-cookie-manager/faz-cookie-manager.php' );
define( 'FAZ_PLUGIN_BASEPATH', __DIR__ . '/' );
define( 'FAZ_PLUGIN_FILENAME', __DIR__ . '/faz-cookie-manager.php' );
define( 'FAZ_POST_TYPE', 'cookielawinfo' );
define( 'FAZ_DEFAULT_LANGUAGE', 'en' );
define( 'FAZ_APP_URL', '' );
define( 'FAZ_APP_CDN_URL', '' );
define( 'FAZ_PLUGIN_URL', 'https://example.test/wp-content/plugins/faz-cookie-manager/' );
define( 'FAZ_APP_ASSETS_URL', FAZ_PLUGIN_URL . 'frontend/images/' );

if ( ! defined( 'WP_MEMORY_LIMIT' ) ) {
	define( 'WP_MEMORY_LIMIT', '256M' );
}
