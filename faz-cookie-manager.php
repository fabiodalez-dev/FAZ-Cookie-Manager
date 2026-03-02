<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://fabiodalez.it/
 * @since             1.0.0
 * @package           FAZ_Cookie_Manager
 *
 * @wordpress-plugin
 * Plugin Name:       FAZ Cookie Manager
 * Plugin URI:        https://github.com/fabiodalez-dev/faz-cookie-manager
 * Description:       A comprehensive GDPR/CCPA cookie consent manager with built-in cookie scanner, local consent logging, Google Consent Mode v2, and IAB TCF v2.2 support.
 * Version:           1.0.5
 * Requires at least: 5.0
 * Tested up to:      6.7
 * Stable tag:        1.0.5
 * Requires PHP:      7.4
 * Author:            Fabio D'Alessandro
 * Author URI:        https://fabiodalez.it/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       faz-cookie-manager
 * Domain Path:       /languages
 * Update URI:        false
 */

/*
	Copyright 2024-2025 Fabio D'Alessandro

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <https://www.gnu.org/licenses/>.
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'FAZ_VERSION', '1.0.5' );
define( 'FAZ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'FAZ_PLUGIN_BASEPATH', plugin_dir_path( __FILE__ ) );
define( 'FAZ_SETTINGS_FIELD', 'CookieLawInfo-0.9' );
define( 'FAZ_ADMIN_OPTIONS_NAME', 'CookieLawInfo-0.8.3' );
define( 'FAZ_PLUGIN_FILENAME', __FILE__ );
define( 'FAZ_POST_TYPE', 'cookielawinfo' );
define( 'FAZ_DEFAULT_LANGUAGE', faz_set_default_language() );

/** Stub for backward compat — cloud URLs removed. */
if ( ! defined( 'FAZ_APP_URL' ) ) {
	define( 'FAZ_APP_URL', '' );
}
if ( ! defined( 'FAZ_APP_CDN_URL' ) ) {
	define( 'FAZ_APP_CDN_URL', '' );
}

/**
 * Load and set default language of the site.
 *
 * @return string
 */
function faz_set_default_language() {
	$default = get_option( 'WPLANG', 'en_US' );
	if ( empty( $default ) || strlen( $default ) <= 1 ) {
		$default = 'en';
	}
	return substr( $default, 0, 2 );
}

/**
 * Add an upgrade notice whenever an update is available.
 *
 * @param array $data Upgrade data.
 * @param array $response Upgrade response data.
 * @return void
 */
function faz_upgrade_notice( $data, $response ) {
	if ( isset( $data['upgrade_notice'] ) ) {
		add_action( 'admin_print_footer_scripts', 'faz_upgrade_notice_js' );
		$msg = str_replace( array( '<p>', '</p>' ), array( '<div>', '</div>' ), $data['upgrade_notice'] );
		echo '<style type="text/css">
        #faz-cookie-manager-update .update-message p:last-child{ display:none;}     
        #faz-cookie-manager-update ul{ list-style:disc; margin-left:30px;}
        .faz-upgrade-notice{ padding-left:30px;}
        </style>
        <div class="update-message faz-upgrade-notice"><div style="color: #f56e28;">' . esc_html__( 'Please make sure the cache is cleared after each plugin update especially if you have minified JS and/or CSS files.', 'faz-cookie-manager' ) . '</div>' . wp_kses_post( wpautop( $msg ) ) . '</div>';
	}
}

/**
 * Javascript for handling upgrade notice.
 *
 * @return void
 */
function faz_upgrade_notice_js() {     ?>
		<script>
			( function( $ ){
				var update_dv=$( '#faz-cookie-manager-update ');
				update_dv.find('.faz-upgrade-notice').next('p').remove();
				update_dv.find('a.update-link:eq(0)').click(function(){
					$('.faz-upgrade-notice').remove();
				});
			})( jQuery );
		</script>
		<?php
}

add_action( 'in_plugin_update_message-' . FAZ_PLUGIN_BASENAME, 'faz_upgrade_notice', 10, 2 );

//declare compliance with WP Consent API
add_filter( "wp_consent_api_registered_".FAZ_PLUGIN_BASENAME, '__return_true' );

/**
 * Return internal DB version.
 *
 * @return string
 */
function faz_get_consent_db_version() {
	return get_option( 'faz_cookie_consent_db_version', get_option( 'faz_cookie_consent_lite_db_version', '2.0' ) );
}

/**
 * Check if plugin is in legacy version.
 *
 * Always returns false — legacy code has been removed from this fork.
 * Kept for backward compatibility with any code that calls this function.
 *
 * @return boolean
 */
function faz_is_legacy() {
	return false;
}

/**
 * Define plugin URL constants.
 */
if ( ! function_exists( 'faz_define_constants' ) ) {
	function faz_define_constants() {
		if ( ! defined( 'FAZ_PLUGIN_URL' ) ) {
			define( 'FAZ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
		}
		if ( ! defined( 'FAZ_APP_ASSETS_URL' ) ) {
			define( 'FAZ_APP_ASSETS_URL', plugin_dir_url( __FILE__ ) . 'frontend/images/' );
		}
	}
}

faz_define_constants();

require_once FAZ_PLUGIN_BASEPATH . 'class-autoloader.php';

$autoloader = new \FazCookie\Autoloader();
$autoloader->register();

register_activation_hook( __FILE__, array( \FazCookie\Includes\Activator::get_instance(), 'install' ) );

$faz_loader = new \FazCookie\Includes\CLI();
$faz_loader->run();
