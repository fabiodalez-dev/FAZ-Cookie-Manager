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
 * Description:       A comprehensive GDPR/CCPA cookie consent manager with built-in cookie scanner, local consent logging, Google Consent Mode v2, and IAB TCF v2.3 support.
 * Version:           1.16.0
 * Requires at least: 5.0
 * Tested up to:      7.0
 * Stable tag:        1.16.0
 * Requires PHP:      7.4
 * Author:            Fabio D'Alessandro
 * Author URI:        https://fabiodalez.it/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       faz-cookie-manager
 * Domain Path:       /languages
 */

/*
	Copyright 2024-2026 Fabio D'Alessandro

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

define( 'FAZ_VERSION', '1.16.0' );
define( 'FAZ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'FAZ_PLUGIN_BASEPATH', plugin_dir_path( __FILE__ ) );
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

// Upgrade notices are rendered by WordPress.org via readme.txt "Upgrade Notice"
// section. No custom update handler required.

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
 * Define plugin URL constants.
 */
if ( ! function_exists( 'faz_define_constants' ) ) {
	function faz_define_constants() {
		if ( ! defined( 'FAZ_PLUGIN_URL' ) ) {
			define( 'FAZ_PLUGIN_URL', set_url_scheme( plugin_dir_url( __FILE__ ) ) );
		}
		if ( ! defined( 'FAZ_APP_ASSETS_URL' ) ) {
			define( 'FAZ_APP_ASSETS_URL', set_url_scheme( plugin_dir_url( __FILE__ ) . 'frontend/images/' ) );
		}
	}
}

faz_define_constants();

/**
 * Return the cookie domain used by FAZ consent cookies.
 *
 * Single source of truth for cookie scope. Returns "" when subdomain sharing
 * is disabled (cookie stays on the exact host), or ".registrable-domain"
 * when enabled — with public-suffix awareness so ".co.uk", ".com.au", etc.
 * round-trip to three labels instead of two. Every server-side write/delete
 * (faz_set_browser_cookie, faz_expire_browser_cookie, cookie shredding) and
 * the `_rootDomain` value exposed to the frontend JS via wp_localize_script
 * go through here, so `setcookie()` and `document.cookie = ...` always hit
 * the same scope.
 *
 * Applies the `faz_cookie_domain` filter exactly once, which is why
 * Frontend::get_cookie_domain() is now a thin wrapper calling this helper
 * directly (do NOT re-apply the filter at the call site).
 *
 * @return string
 */
function faz_get_cookie_domain() {
	$settings  = get_option( 'faz_settings', array() );
	$subdomain = ! empty( $settings['banner_control']['subdomain_sharing'] );
	if ( ! $subdomain ) {
		return apply_filters( 'faz_cookie_domain', '' );
	}

	$domain = '';
	$parsed = wp_parse_url( home_url() );
	$host   = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';

	// RFC 6265 §4.1.2.3 — the Domain attribute MUST NOT be an IP address.
	// Browsers silently reject domain=<ip>, so skip the root-domain logic entirely.
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return apply_filters( 'faz_cookie_domain', '' );
	}

	$parts  = explode( '.', $host );
	$count  = count( $parts );

	$multi_level_tlds = array(
		'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'net.uk',
		'com.au', 'net.au', 'org.au', 'edu.au',
		'co.nz', 'net.nz', 'org.nz',
		'co.jp', 'or.jp', 'ne.jp',
		'co.kr', 'or.kr',
		'co.in', 'net.in', 'org.in',
		'co.za', 'org.za', 'web.za',
		'com.br', 'net.br', 'org.br',
		'com.cn', 'net.cn', 'org.cn',
		'com.hk', 'org.hk',
		'com.my', 'net.my', 'org.my',
		'com.sg', 'net.sg', 'org.sg',
		'com.tw', 'net.tw', 'org.tw',
		'co.id', 'or.id', 'web.id',
		'com.mx', 'org.mx',
		'co.il',
		'com.tr', 'org.tr',
	);

	$is_multi = false;
	if ( $count >= 3 ) {
		$last_two = implode( '.', array_slice( $parts, -2 ) );
		$is_multi = in_array( $last_two, $multi_level_tlds, true );
	}

	if ( $is_multi && $count > 3 ) {
		$domain = '.' . implode( '.', array_slice( $parts, -3 ) );
	} elseif ( ! $is_multi && $count > 2 ) {
		$domain = '.' . implode( '.', array_slice( $parts, -2 ) );
	} elseif ( '' !== $host ) {
		$domain = '.' . $host;
	}

	return apply_filters( 'faz_cookie_domain', $domain );
}

/**
 * Return the raw FAZ consent cookie value from the current request.
 *
 * @return string
 */
function faz_get_consent_cookie_value() {
	if ( ! isset( $_COOKIE['fazcookie-consent'] ) ) {
		return '';
	}

	// First-pass sanitization: applied directly to $_COOKIE on the same line
	// so static analyzers (Plugin Check, PHPCS) see the input is sanitized
	// at the point of access. sanitize_text_field() preserves '%' octets
	// because they are ASCII-printable, so the URL-decode step below still
	// works on the percent-encoded payload written by _fazSetInStore() in
	// frontend/js/script.js.
	$raw = sanitize_text_field( wp_unslash( (string) $_COOKIE['fazcookie-consent'] ) );

	if ( false !== strpos( $raw, '%' ) ) {
		// Second-pass sanitization: re-sanitize after URL-decode in case the
		// decoded payload reveals characters that the first pass left
		// percent-encoded. Defensive double-sanitization.
		$raw = sanitize_text_field( rawurldecode( $raw ) );
	}

	return $raw;
}

/**
 * Parse the FAZ consent cookie into a key/value map.
 *
 * @param string $cookie Raw cookie string. Falls back to the current request.
 * @return array
 */
function faz_parse_consent_cookie( $cookie = '' ) {
	$cookie = '' !== $cookie ? (string) $cookie : faz_get_consent_cookie_value();
	if ( '' === $cookie ) {
		return array();
	}

	$parsed = array();
	foreach ( explode( ',', $cookie ) as $pair ) {
		$pair = trim( (string) $pair );
		if ( '' === $pair ) {
			continue;
		}
		$parts = explode( ':', $pair, 2 );
		if ( 2 !== count( $parts ) ) {
			continue;
		}
		$key = trim( $parts[0] );
		if ( '' === $key ) {
			continue;
		}
		$parsed[ $key ] = trim( $parts[1] );
	}

	return $parsed;
}

/**
 * Return the current server-side consent revision.
 *
 * @return int
 */
function faz_get_consent_revision() {
	$settings = get_option( 'faz_settings', array() );
	$revision = isset( $settings['general']['consent_revision'] )
		? absint( $settings['general']['consent_revision'] )
		: 1;
	return max( 1, $revision );
}

/**
 * Whether the given consent cookie is stale for the current server revision.
 *
 * Cookies from versions prior to 1.11.0 have no `rev` key and remain valid
 * until the admin explicitly bumps the revision above 1.
 *
 * @param string $cookie Raw cookie string. Falls back to the current request.
 * @return bool
 */
function faz_is_consent_cookie_stale( $cookie = '' ) {
	$cookie = '' !== $cookie ? (string) $cookie : faz_get_consent_cookie_value();
	if ( '' === $cookie ) {
		return false;
	}

	$current_revision = faz_get_consent_revision();
	$parsed           = faz_parse_consent_cookie( $cookie );
	$stored_revision  = isset( $parsed['rev'] ) ? absint( $parsed['rev'] ) : 0;

	return $current_revision > 1 && $stored_revision < $current_revision;
}

/**
 * Return the consent cookie only if it is valid for the current revision.
 *
 * @param string $cookie Raw cookie string. Falls back to the current request.
 * @return string
 */
function faz_get_valid_consent_cookie( $cookie = '' ) {
	$cookie = '' !== $cookie ? (string) $cookie : faz_get_consent_cookie_value();
	if ( '' === $cookie || faz_is_consent_cookie_stale( $cookie ) ) {
		return '';
	}
	return $cookie;
}

/**
 * Whether the given consent cookie was auto-granted by the PMP integration.
 *
 * @param string $cookie Raw cookie string. Falls back to the current request.
 * @return bool
 */
function faz_is_auto_granted_consent_cookie( $cookie = '' ) {
	$cookie = '' !== $cookie ? (string) $cookie : faz_get_valid_consent_cookie();
	if ( '' === $cookie ) {
		return false;
	}
	$parsed = faz_parse_consent_cookie( $cookie );
	return isset( $parsed['source'] ) && 'pmp' === $parsed['source'];
}

/**
 * Set a browser cookie and mirror it into the current PHP request.
 *
 * @param string      $name Cookie name.
 * @param string      $value Cookie value.
 * @param int         $expires Unix timestamp.
 * @param string|null $domain Cookie domain. Null uses the FAZ consent domain.
 * @return void
 */
function faz_set_browser_cookie( $name, $value, $expires, $domain = null ) {
	$domain = null === $domain ? faz_get_cookie_domain() : (string) $domain;

	if ( ! headers_sent() ) {
		$options = array(
			'expires'  => (int) $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		);
		if ( '' !== $domain ) {
			$options['domain'] = $domain;
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( $name, $value, $options );
	}

	$_COOKIE[ $name ] = $value;
}

/**
 * Expire a browser cookie across the host-only and shared-domain variants.
 *
 * @param string $name Cookie name.
 * @return void
 */
function faz_expire_browser_cookie( $name ) {
	$domains       = array( '' );
	$cookie_domain = faz_get_cookie_domain();
	if ( '' !== $cookie_domain ) {
		$domains[] = $cookie_domain;
		$trimmed   = ltrim( $cookie_domain, '.' );
		if ( '' !== $trimmed ) {
			$domains[] = $trimmed;
		}
	}
	$domains = array_values( array_unique( $domains ) );

	if ( ! headers_sent() ) {
		foreach ( $domains as $domain ) {
			$options = array(
				'expires'  => time() - DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			);
			if ( '' !== $domain ) {
				$options['domain'] = $domain;
			}
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie( $name, '', $options );
		}
	}

	unset( $_COOKIE[ $name ] );
}

/**
 * Clear all consent-tracking cookies that must stay in sync.
 *
 * @return void
 */
function faz_clear_consent_tracking_cookies() {
	foreach ( array( 'fazcookie-consent', 'fazVendorConsent', 'euconsent-v2' ) as $cookie_name ) {
		faz_expire_browser_cookie( $cookie_name );
	}
}

/**
 * Clear stale consent cookies before frontend logic reads them.
 *
 * @return void
 */
function faz_maybe_invalidate_stale_consent_cookie() {
	if ( faz_is_consent_cookie_stale() ) {
		faz_clear_consent_tracking_cookies();
	}
}

add_action( 'init', 'faz_maybe_invalidate_stale_consent_cookie', 1 );

require_once FAZ_PLUGIN_BASEPATH . 'class-autoloader.php';

$autoloader = new \FazCookie\Autoloader();
$autoloader->register();

/**
 * Bootstrap geo-routing v2 REST API (spec 001 — P6 task T087).
 *
 * Registers all /faz/v1/geo/* endpoints on rest_api_init. Keeps the
 * orchestrator (admin/modules/geo-routing/class-geo-routing.php) inert
 * — only the REST surface activates here.
 *
 * @since 1.15.0
 */
add_action( 'rest_api_init', function() {
	if ( class_exists( '\\FazCookie\\Admin\\Modules\\Geo_Routing\\Api\\Geo_Api' ) ) {
		\FazCookie\Admin\Modules\Geo_Routing\Api\Geo_Api::get_instance()->register_routes();
	}
} );

register_activation_hook( __FILE__, function( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		$sites = get_sites( array( 'number' => 0 ) );
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			\FazCookie\Includes\Activator::install();
			restore_current_blog();
		}
	} else {
		\FazCookie\Includes\Activator::install();
	}
});

register_deactivation_hook( __FILE__, function( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		$sites = get_sites( array( 'number' => 0 ) );
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			\FazCookie\Includes\Deactivator::deactivate();
			restore_current_blog();
		}
	} else {
		\FazCookie\Includes\Deactivator::deactivate();
	}
});

/**
 * Auto-activate on newly created subsites when the plugin is network-activated.
 *
 * @since 1.7.0
 * @param WP_Site $new_site The new site object.
 */
add_action( 'wp_initialize_site', function( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( plugin_basename( FAZ_PLUGIN_FILENAME ) ) ) {
		return;
	}
	switch_to_blog( $new_site->blog_id );
	\FazCookie\Includes\Activator::install();
	restore_current_blog();
}, 900, 1 );

$faz_loader = new \FazCookie\Includes\CLI();
$faz_loader->run();

// Paid Memberships Pro integration (optional — no-op if PMP is not active).
// Registered after the core CLI loader so Frontend can query it during
// its own "is banner disabled" checks.
\FazCookie\Includes\Integrations\Paid_Memberships_Pro::get_instance()->register_hooks();

// Cookie Policy Generator (Spec 002) — registers the [faz_cookie_policy]
// shortcode and the faz/v1/cookie-policy/* REST API. Always loads (no
// module toggle); the admin tab is wired in admin/class-admin.php
// alongside the other module pages.
\FazCookie\Admin\Modules\Cookie_Policy_Generator\Cookie_Policy_Generator::get_instance()->init();

/**
 * Force every /faz/v1/* REST response out of the LiteSpeed / CDN cache.
 *
 * Reported on prod (fabiodalez.it 2026-05-18, 1.14.1): after a POST /banners
 * + immediate GET /banners, the GET returned the pre-POST list. Live trace
 * showed `x-litespeed-cache: hit,private` on the GET — LiteSpeed had stored
 * the previous GET response in its "private" (per-user) cache and was
 * serving it back, even though the response carried
 * `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private`.
 *
 * LSCache decides caching eligibility based on the
 * `litespeed_control_set_nocache` action and the request-time
 * `X-LiteSpeed-Cache-Control` header — it does NOT honour the
 * response-time `Cache-Control: no-store` directive on routes that the
 * site has opted into private-cache mode for. The fix is to fire the
 * action AND emit the header BEFORE the route callback runs, on every
 * request inside our namespace.
 *
 * Hooked on `rest_pre_dispatch` so it runs after WordPress matches the
 * route but before the callback fires — guaranteed coverage for every
 * /wp-json/faz/v1/* request, including ones that bypass the
 * Admin::render_page() path that the admin-page-only fix in commit
 * 6cc0b29 covers.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	$route = $request->get_route();
	if ( false === strpos( $route, '/faz/v1/' ) && '/faz/v1' !== rtrim( $route, '/' ) ) {
		return $result;
	}
	// F004 fix: the public /faz/v1/banner/{lang} endpoint emits
	// `Cache-Control: public, max-age=300` from its own callback so
	// LSCache / a CDN can serve the localized banner payload to repeat
	// anonymous visitors. Without this exclusion the no-cache stack
	// below would defeat that intentional 5-minute caching. The trailing
	// slash is load-bearing: `/faz/v1/banner` would also prefix-match
	// the admin REST collection `/faz/v1/banners` (plural), wrongly
	// exempting it from the no-cache stack and reintroducing the
	// LSCache stale-list bug that commit 143ee0b fixed. Mirrors the
	// exclusion in admin/class-admin.php::add_rest_nocache_headers.
	if ( 0 === strpos( $route, '/faz/v1/banner/' ) ) {
		return $result;
	}
	do_action( 'litespeed_control_set_nocache', 'FAZ Cookie Manager REST route' );
	if ( ! headers_sent() ) {
		header( 'X-LiteSpeed-Cache-Control: no-cache, no-vary', true );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
		define( 'DONOTCACHEOBJECT', true );
	}
	return $result;
}, 10, 3 );

// Register WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'faz', 'FazCookie\Includes\WP_CLI_Commands' );
}
