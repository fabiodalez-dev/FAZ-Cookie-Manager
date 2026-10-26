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
 * Version:           1.34.4
 * Requires at least: 5.0
 * Tested up to:      7.1
 * Stable tag:        1.34.4
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

define( 'FAZ_VERSION', '1.34.4' );
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
 * Whether this request is HTTPS, including behind a TLS-terminating proxy.
 *
 * `is_ssl()` inspects $_SERVER['HTTPS'] and the port, both of which a reverse
 * proxy that terminates TLS may never forward to PHP. On those hosts is_ssl()
 * is false on an https:// page, so `set_url_scheme()` emitted http:// asset URLs
 * into an https document — a mixed-content block that silently kills the banner.
 *
 * Deliberately a standalone function, not Filesystem::is_ssl() (which applies
 * the same three signals): the constants are defined before the autoloader is
 * guaranteed to have run, so this must not depend on a class being loadable.
 *
 * @return bool
 */
if ( ! function_exists( 'faz_request_is_https' ) ) {
	function faz_request_is_https() {
		if ( is_ssl() ) {
			return true;
		}
		if ( 0 === stripos( (string) get_option( 'siteurl' ), 'https://' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to a literal, never stored or emitted.
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) ) {
			return true;
		}
		return false;
	}
}

/**
 * Define plugin URL constants.
 */
if ( ! function_exists( 'faz_define_constants' ) ) {
	function faz_define_constants() {
		// Force https when the request really is https by any of the three
		// signals — set_url_scheme() alone consults only is_ssl().
		$faz_url_scheme = faz_request_is_https() ? 'https' : null;
		if ( ! defined( 'FAZ_PLUGIN_URL' ) ) {
			define( 'FAZ_PLUGIN_URL', set_url_scheme( plugin_dir_url( __FILE__ ), $faz_url_scheme ) );
		}
		if ( ! defined( 'FAZ_APP_ASSETS_URL' ) ) {
			// The SAME scheme, for the same reason. Fixing only FAZ_PLUGIN_URL
			// left the images constant on bare set_url_scheme(), so behind a
			// TLS-terminating proxy get_assets_path() still emitted http:// —
			// exactly the sibling-branch mistake this whole file's HTTPS fix was
			// written to correct.
			define( 'FAZ_APP_ASSETS_URL', set_url_scheme( plugin_dir_url( __FILE__ ) . 'frontend/images/', $faz_url_scheme ) );
		}
	}
}

/**
 * URL of the plugin's frontend/ directory, with the corrected HTTPS scheme.
 *
 * Frontend assets were enqueued through plugin_dir_url() directly, which routes
 * to plugins_url() -> set_url_scheme() -> is_ssl(). Behind a TLS-terminating
 * proxy that is false, so script.min.js / a11y.min.js / faz-cookie-policy.css
 * were emitted as http:// into an https document and blocked as mixed content —
 * fixing FAZ_PLUGIN_URL alone did not reach them, because they never used it.
 *
 * @return string
 */
if ( ! function_exists( 'faz_frontend_url' ) ) {
	function faz_frontend_url() {
		return FAZ_PLUGIN_URL . 'frontend/';
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

/** Whether consent must be bound to the current multisite site on any topology. */
function faz_consent_site_bound() {
	return function_exists( 'is_multisite' ) && is_multisite();
}

/** Per-site names prevent one multisite choice from overwriting another. */
function faz_consent_cookie_name( $name = 'fazcookie-consent' ) {
	$managed = array( 'fazcookie-consent', 'fazVendorConsent', 'fazVendorLI', 'euconsent-v2', 'fazcookie-dnsmpi', 'fazVendorSource' );
	return faz_consent_site_bound() && in_array( $name, $managed, true )
		? $name . '-n' . get_current_network_id() . '-b' . get_current_blog_id() : $name;
}

/** Cookie names exposed to every runtime consumer, including early logger/GCM. */
function faz_consent_cookie_names() {
	$names = array();
	foreach ( array( 'fazcookie-consent', 'fazVendorConsent', 'fazVendorLI', 'euconsent-v2', 'fazcookie-dnsmpi', 'fazVendorSource' ) as $name ) {
		$names[ $name ] = faz_consent_cookie_name( $name );
	}
	return $names;
}

/** Only a necessary-only PMP state may omit a consent fingerprint. */
function faz_pmp_necessary_only( array $parsed ) {
	if ( ( $parsed['source'] ?? '' ) !== 'pmp' || ( $parsed['action'] ?? '' ) !== 'auto' || ( $parsed['consent'] ?? '' ) !== 'no' ) {
		return false;
	}
	foreach ( $parsed as $key => $value ) {
		if ( 'necessary' !== $key && 1 === preg_match( '/^yes(?::|$)/', (string) $value ) ) {
			return false;
		}
	}
	return true;
}

/** Build the same scope fingerprint for classic, REST and AMP consent. */
function faz_scope_fingerprint( $slug, $law ) {
	$scope = $slug . '|' . $law;
	if ( faz_consent_site_bound() ) {
		$scope .= '|' . get_current_network_id() . ':' . get_current_blog_id();
	}
	return substr( wp_hash( $scope, 'auth' ), 0, 32 );
}

/**
 * Return the raw FAZ consent cookie value from the current request.
 *
 * @return string
 */
function faz_get_consent_cookie_value() {
	if ( ! isset( $_COOKIE[ faz_consent_cookie_name() ] ) ) {
		return '';
	}

	// First-pass sanitization: applied directly to $_COOKIE on the same line
	// so static analyzers (Plugin Check, PHPCS) see the input is sanitized
	// at the point of access. sanitize_text_field() preserves '%' octets
	// because they are ASCII-printable, so the URL-decode step below still
	// works on the percent-encoded payload written by _fazSetInStore() in
	// frontend/js/script.js.
	$raw = sanitize_text_field( wp_unslash( (string) $_COOKIE[ faz_consent_cookie_name() ] ) );

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

	if ( faz_consent_site_bound() && ! faz_pmp_necessary_only( $parsed ) ) {
		// Pluggable functions are unavailable during early plugin loading. Fail
		// closed until WordPress can verify the site's fingerprint.
		if ( ! function_exists( 'wp_hash' ) || empty( $parsed['__scope.fp'] ) ||
			! hash_equals( faz_scope_fingerprint( $parsed['__scope.banner'] ?? '', $parsed['__scope.law'] ?? '' ), $parsed['__scope.fp'] ) ) {
			return true;
		}
	}
	return $current_revision > 1 && $stored_revision < $current_revision;
}

/**
 * Decode a vendor bitfield written by _fazEncodeVendorBits() in
 * frontend/js/script.js: base64url, six bits per character, bit (id - 1) most
 * significant first — the alphabet and bit order of the TC string.
 *
 * Mirrors _fazDecodeVendorBits(): null when the text is not a bitfield at all
 * (so a damaged record is told apart from an empty set), an empty list for "".
 *
 * @param string $text Bitfield.
 * @return int[]|null
 */
function faz_decode_vendor_bits( $text ) {
	if ( ! is_string( $text ) ) {
		return null;
	}
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
	$ids      = array();
	$length   = strlen( $text );
	for ( $i = 0; $i < $length; $i++ ) {
		$value = strpos( $alphabet, $text[ $i ] );
		if ( false === $value ) {
			return null;
		}
		for ( $bit = 0; $bit < 6; $bit++ ) {
			if ( $value & ( 32 >> $bit ) ) {
				$ids[] = $i * 6 + $bit + 1;
			}
		}
	}
	return $ids;
}

/**
 * Parse a fazVendorConsent value, the server twin of _fazParseVendorRecord()
 * in frontend/js/script.js (and of the reader in tcf-cmp.js).
 *
 * Two formats:
 *  - compact (since 1.34.2): `d:<bitfield>,c:<bitfield|*>` — `d` the vendors
 *    DISCLOSED when the visitor chose, `c` the ones consented, `*` all of the
 *    disclosed ones. A consent bit for an undisclosed vendor is ignored;
 *  - per pair (earlier releases): `id:yes|no`, one pair per disclosed vendor.
 *
 * @param string $raw Cookie value.
 * @return array{disclosed:array<int,bool>,consent:array<int,bool>}|null Null
 *         when there is no usable record.
 */
function faz_parse_vendor_consent_record( $raw ) {
	if ( ! is_string( $raw ) || '' === $raw ) {
		return null;
	}
	$disclosed         = array();
	$consent           = array();
	$compact_disclosed = null;
	$compact_consent   = null;
	$legacy            = false;
	foreach ( explode( ',', $raw ) as $pair ) {
		$idx = strpos( $pair, ':' );
		if ( false === $idx ) {
			continue;
		}
		$key   = trim( substr( $pair, 0, $idx ) );
		$value = trim( (string) substr( $pair, $idx + 1 ) );
		if ( 'd' === $key ) {
			$compact_disclosed = $value;
			continue;
		}
		if ( 'c' === $key ) {
			$compact_consent = $value;
			continue;
		}
		// parseInt(): leading digits, optional sign, the rest ignored.
		if ( ! preg_match( '/^([+-]?)(\d+)/', $key, $m ) || '-' === $m[1] ) {
			continue;
		}
		$id = (int) $m[2];
		if ( $id <= 0 ) {
			continue;
		}
		$legacy            = true;
		$disclosed[ $id ] = true;
		$consent[ $id ]   = 'yes' === $value;
	}
	if ( null !== $compact_disclosed ) {
		$disclosed_ids = faz_decode_vendor_bits( $compact_disclosed );
		if ( null === $disclosed_ids ) {
			return null;
		}
		$all         = '*' === $compact_consent;
		$consent_ids = $all ? array() : faz_decode_vendor_bits( null === $compact_consent ? '' : $compact_consent );
		$granted     = array_fill_keys( is_array( $consent_ids ) ? $consent_ids : array(), true );
		$out         = array(
			'disclosed' => array(),
			'consent'   => array(),
		);
		foreach ( $disclosed_ids as $id ) {
			$out['disclosed'][ $id ] = true;
			$out['consent'][ $id ]   = $all || isset( $granted[ $id ] );
		}
		return $out;
	}
	return $legacy ? array(
		'disclosed' => $disclosed,
		'consent'   => $consent,
	) : null;
}

/**
 * Whether a decided consent cookie must be asked again because the visitor
 * was never shown every vendor now selected for IAB TCF.
 *
 * The server twin of _fazVendorRecordStale() in frontend/js/script.js. The
 * client re-prompts such a visitor, but only after its runtime has started;
 * a server that still read the cookie as valid had already rendered the
 * accepted categories' trackers EXECUTABLE in that same page view, and a
 * script that has run cannot be recalled. Callers that render a page on which
 * the CMP runs treat a cookie this returns true for as absent, exactly as they
 * treat a scope change (see Frontend::get_scoped_valid_consent_cookie()).
 *
 * Same rules, in the same order: TCF active (enabled, a registered CMP ID,
 * a stored GVL) with at least one live selected vendor; a decided record
 * (`action:yes`, no `undecided:1`); not an opt-out law (`ccpa`, where 11 CCR
 * 7026(k) is against asking again); then stale when there is no vendor record
 * or a live selected vendor is missing from its disclosed set. A vendor
 * REMOVED from the selection never makes it stale.
 *
 * A cookie written by the AMP consent endpoint carries no vendor record: AMP
 * never shows the vendor list, so no vendor consent exists for it, and on a
 * canonical page with TCF active it is re-asked like any other record that
 * lacks one (see the note in class-amp-consent-rest.php).
 *
 * @param string      $cookie Raw consent cookie (already revision/scope valid).
 * @param string      $law    Law of the banner being served.
 * @param string|null $record Raw fazVendorConsent value; null reads the request.
 * @return bool
 */
function faz_is_vendor_record_stale( $cookie, $law, $record = null ) {
	$cookie = (string) $cookie;
	if ( '' === $cookie || 'ccpa' === (string) $law ) {
		return false;
	}
	$settings = get_option( 'faz_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['iab']['enabled'] ) ) {
		return false;
	}
	if ( ! isset( $settings['iab']['cmp_id'] ) || absint( $settings['iab']['cmp_id'] ) < 2 ) {
		return false;
	}
	$parsed = faz_parse_consent_cookie( $cookie );
	if ( ! isset( $parsed['action'] ) || 'yes' !== $parsed['action'] ) {
		return false;
	}
	if ( isset( $parsed['undecided'] ) && '1' === $parsed['undecided'] ) {
		return false;
	}
	$selected = get_option( 'faz_gvl_selected_vendors', array() );
	if ( ! is_array( $selected ) || empty( $selected ) ) {
		return false;
	}
	if ( null === $record ) {
		$record = isset( $_COOKIE[ faz_consent_cookie_name( 'fazVendorConsent' ) ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ faz_consent_cookie_name( 'fazVendorConsent' ) ] ) ) : '';
	}
	$vendor_record = faz_parse_vendor_consent_record( (string) $record );

	// Only the selected vendors this record does not disclose can make it
	// stale; when there are none the GVL itself is never loaded.
	$undisclosed = array();
	foreach ( $selected as $id ) {
		$id = absint( $id );
		if ( $id > 0 && ( null === $vendor_record || empty( $vendor_record['disclosed'][ $id ] ) ) ) {
			$undisclosed[] = $id;
		}
	}
	if ( empty( $undisclosed ) || ! class_exists( '\\FazCookie\\Includes\\Gvl' ) ) {
		return false;
	}
	$gvl = \FazCookie\Includes\Gvl::get_instance();
	if ( ! $gvl->has_data() ) {
		return false;
	}
	// A live vendor is one the GVL still carries and has not marked deleted —
	// the list the preference center shows (_iabVendors).
	return ! empty( $gvl->get_vendors( $undisclosed ) );
}

/**
 * Read a scope key from a parsed consent cookie, honouring the legacy slot.
 *
 * Mirrors _fazReadScopedCookieValue() in frontend/js/script.js. Cookies written
 * before the `__scope.` prefix stored the banner slug and law under the bare
 * `banner` / `law` keys, which a category slug can also be called; a value that
 * looks like a category decision ("yes", "no", "yes:<ts>") is therefore not a
 * scope value. Strict mode reads only the prefixed key.
 *
 * @param array  $parsed     Parsed cookie (faz_parse_consent_cookie()).
 * @param string $key        Prefixed scope key.
 * @param string $legacy_key Unprefixed pre-1.14 key.
 * @param bool   $strict     Ignore the legacy slot.
 * @return string
 */
function faz_read_scoped_cookie_value( $parsed, $key, $legacy_key, $strict ) {
	$primary = isset( $parsed[ $key ] ) ? (string) $parsed[ $key ] : '';
	if ( '' !== $primary || $strict ) {
		return $primary;
	}
	$legacy = isset( $parsed[ $legacy_key ] ) ? (string) $parsed[ $legacy_key ] : '';
	if ( '' === $legacy || preg_match( '/^(yes|no)(:.*)?$/i', $legacy ) ) {
		return '';
	}
	return $legacy;
}

/**
 * Whether a consent cookie was recorded under a different banner/law scope.
 *
 * This is the server twin of _fazConsentScopeChanged() in script.js and the
 * one scope predicate every server surface uses (classic blocker, per-service
 * consent, AMP bridge). A record acknowledged under one legal regime is not
 * consent under another (GDPR Art. 4(11)/7(1), EDPB 05/2020 §55-60), so a
 * cookie whose scope no longer matches the banner served must be treated as
 * absent on the SAME request the client invalidates it — not one page later,
 * after the trackers it granted have already run.
 *
 * Rules, in order:
 *  - the Paid Memberships Pro privacy alternative (source:pmp, action:auto,
 *    consent:no) is a server-refreshed necessary-only state, never consent,
 *    and carries no scope on purpose: it is valid under every scope;
 *  - without geographic routing (`routed` false) every visitor is served the
 *    same banner, so only a change of LAW invalidates. A new banner slug (an
 *    A/B test ended, a banner re-created) or a new fingerprint (AUTH salts
 *    rotated) is not a different regime; strict mode still requires the
 *    fingerprint to be present, as the client does;
 *  - strict mode (`faz_strict_scope_fingerprint`, and always on AMP): every
 *    scope key must be present and hash_equals the active scope;
 *  - a cookie with no scope keys at all is a pre-1.14 upgrade case and stands;
 *  - a fingerprint present on both sides decides on its own (tamper check);
 *  - otherwise the banner slug and law decide when both sides know them.
 *
 * `routed` defaults to true, so a caller that does not say (the AMP bridge,
 * which is pinned to one banner) keeps the full comparison.
 *
 * @param array $parsed Parsed cookie (faz_parse_consent_cookie()).
 * @param array $scope  { slug, law, fingerprint, strict, routed } of the active banner.
 * @return bool True when the record belongs to another scope.
 */
function faz_consent_cookie_scope_changed( $parsed, $scope ) {
	$parsed = is_array( $parsed ) ? $parsed : array();
	$scope  = is_array( $scope ) ? $scope : array();

	if ( faz_pmp_necessary_only( $parsed ) ) {
		return false;
	}

	$strict      = ! empty( $scope['strict'] );
	$slug        = isset( $scope['slug'] ) ? (string) $scope['slug'] : '';
	$law         = isset( $scope['law'] ) ? (string) $scope['law'] : '';
	$fingerprint = isset( $scope['fingerprint'] ) ? (string) $scope['fingerprint'] : '';

	$stored_fp     = isset( $parsed['__scope.fp'] ) ? (string) $parsed['__scope.fp'] : '';
	$stored_banner = faz_read_scoped_cookie_value( $parsed, '__scope.banner', 'banner', $strict );
	$stored_law    = faz_read_scoped_cookie_value( $parsed, '__scope.law', 'law', $strict );
	$routed        = ! array_key_exists( 'routed', $scope ) || ! empty( $scope['routed'] );

	if ( ! $routed ) {
		if ( $strict && '' === $stored_fp ) {
			return true;
		}
		return '' !== $law && '' !== $stored_law && ! hash_equals( $law, $stored_law );
	}

	if ( $strict ) {
		return '' === $stored_fp
			|| '' === $stored_banner
			|| '' === $stored_law
			|| ! hash_equals( $fingerprint, $stored_fp )
			|| ! hash_equals( $slug, $stored_banner )
			|| ! hash_equals( $law, $stored_law );
	}

	if ( '' === $stored_banner && '' === $stored_law && '' === $stored_fp ) {
		return false;
	}
	if ( '' !== $stored_fp && '' !== $fingerprint && ! hash_equals( $fingerprint, $stored_fp ) ) {
		return true;
	}
	return ( '' !== $slug && '' !== $stored_banner && ! hash_equals( $slug, $stored_banner ) )
		|| ( '' !== $law && '' !== $stored_law && ! hash_equals( $law, $stored_law ) );
}

/**
 * Return the consent cookie only if it is valid for the current revision and,
 * when the active scope is given, for the banner/law it is being relied on.
 *
 * @param string     $cookie Raw cookie string. Falls back to the current request.
 * @param array|null $scope  Active banner scope ({ slug, law, fingerprint,
 *                           strict }, see faz_consent_cookie_scope_changed()).
 *                           Null keeps the revision-only check, for callers
 *                           that run before a banner is selected.
 * @return string
 */
function faz_get_valid_consent_cookie( $cookie = '', $scope = null ) {
	$cookie = '' !== $cookie ? (string) $cookie : faz_get_consent_cookie_value();
	if ( '' === $cookie || faz_is_consent_cookie_stale( $cookie ) ) {
		return '';
	}
	if ( is_array( $scope ) && faz_consent_cookie_scope_changed( faz_parse_consent_cookie( $cookie ), $scope ) ) {
		return '';
	}
	return $cookie;
}

/**
 * Whether the given consent cookie is managed by the PMP integration.
 *
 * @param string $cookie Raw cookie string. Falls back to the current request.
 * @return bool
 */
function faz_is_pmp_managed_consent_cookie( $cookie = '' ) {
	$cookie = '' !== $cookie ? (string) $cookie : faz_get_valid_consent_cookie();
	if ( '' === $cookie ) {
		return false;
	}
	$parsed = faz_parse_consent_cookie( $cookie );
	return isset( $parsed['source'] ) && 'pmp' === $parsed['source'];
}

/**
 * Backward-compatible alias for the pre-1.27.0 helper name.
 *
 * The PMP integration no longer grants optional purposes automatically: it
 * applies a privacy-preserving necessary-only state. Keep this function so
 * third-party integrations do not fatal, but route new code through the
 * accurately named predicate above.
 *
 * @param string $cookie Raw cookie string. Falls back to the current request.
 * @return bool
 */
function faz_is_auto_granted_consent_cookie( $cookie = '' ) {
	return faz_is_pmp_managed_consent_cookie( $cookie );
}

/**
 * Set a browser cookie and mirror it into the current PHP request.
 *
 * @param string      $name Cookie name.
 * @param string      $value Cookie value.
 * @param int         $expires Unix timestamp.
 * @param string|null $domain Cookie domain. Null uses the FAZ consent domain.
 * @param string      $same_site SameSite policy (Lax, Strict or None).
 * @param bool|null   $force_secure Override the normal is_ssl() result.
 * @return void
 */
function faz_set_browser_cookie( $name, $value, $expires, $domain = null, $same_site = 'Lax', $force_secure = null ) {
	$name = faz_consent_cookie_name( $name );
	$domain = null === $domain ? faz_get_cookie_domain() : (string) $domain;
	$same_site = in_array( $same_site, array( 'Lax', 'Strict', 'None' ), true ) ? $same_site : 'Lax';
	$secure    = null === $force_secure ? is_ssl() : (bool) $force_secure;
	// Browsers reject SameSite=None without Secure. Fold an invalid caller
	// combination back to Lax instead of emitting a cookie that cannot stick.
	if ( 'None' === $same_site && ! $secure ) {
		$same_site = 'Lax';
	}

	if ( ! headers_sent() ) {
		$options = array(
			'expires'  => (int) $expires,
			'path'     => '/',
			'secure'   => $secure,
			// httponly=false is REQUIRED by design: the consent cookie
			// (`fazcookie-consent`) is the source of truth for what
			// frontend JS may or may not do (load GA, load Meta Pixel,
			// fire dataLayer events). The frontend MUST read it to gate
			// downstream tracking and re-write it when the user changes
			// preferences. Marking it HttpOnly would hide it from the
			// banner script and break the entire consent UX. The cookie
			// holds opt-in/opt-out booleans only — no session token, no
			// auth secret — so the CWE-1004 / CWE-614 threat model
			// (session theft via JS) does not apply.
			'httponly' => false,
			'samesite' => $same_site,
		);
		if ( '' !== $domain ) {
			$options['domain'] = $domain;
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( $name, $value, $options ); // nosemgrep
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
	$name = faz_consent_cookie_name( $name );
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
				// Mirror image of faz_set_browser_cookie() above:
				// httponly MUST stay false so the frontend JS can
				// observe the cookie's disappearance and re-render the
				// banner. See the longer rationale on that function.
				'httponly' => false,
				'samesite' => 'Lax',
			);
			if ( '' !== $domain ) {
				$options['domain'] = $domain;
			}
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie( $name, '', $options ); // nosemgrep
		}
	}

	unset( $_COOKIE[ $name ] );
}

/**
 * Clear all consent-tracking cookies that must stay in sync.
 *
 * The same set _fazInvalidateStoredConsent() in script.js deletes. fazVendorLI
 * (the IAB Right-to-Object record) belongs to it: left behind, the objections
 * of a choice that no longer exists outlived it and were applied to the next
 * one, so a visitor who never objected under the new choice still signalled
 * the old objections.
 *
 * @return void
 */
function faz_clear_consent_tracking_cookies() {
	foreach ( array( 'fazcookie-consent', 'fazVendorConsent', 'fazVendorLI', 'euconsent-v2' ) as $cookie_name ) {
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

/**
 * Drop the cached banner when the site address changes.
 *
 * The rendered banner is cached in faz_banner_template with absolute plugin
 * asset URLs baked in, so moving the site to a different address leaves the
 * cache pointing at the old one — the browser then requests the icon from the
 * previous host. This catches the deliberate move (Settings → General, WP-CLI,
 * multisite domain edit). A restored database bypasses update_option entirely,
 * which is why Frontend also repairs a stale origin at render time. Reported as
 * issue #195 after a WPVivid restore from a localhost build.
 *
 * Loads the helper defensively: it lives in a plain-function file, not a class,
 * so the autoloader will not pull it in on its own.
 *
 * @return void
 */
function faz_flush_banner_template_on_address_change() {
	if ( ! function_exists( 'faz_clear_banner_template_cache' ) ) {
		require_once FAZ_PLUGIN_BASEPATH . 'includes/class-i18n-helpers.php';
	}
	if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
		faz_clear_banner_template_cache();
	}
}

add_action( 'update_option_siteurl', 'faz_flush_banner_template_on_address_change' );
add_action( 'update_option_home', 'faz_flush_banner_template_on_address_change' );

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

// Register the scanner's WP-Cron hooks on EVERY request, not only inside the
// admin/REST module loader. wp-cron.php runs in a front-end context where the
// admin modules are never loaded, so a scan scheduled from the admin would fire
// its cron event with no callback attached and silently do nothing. Registering
// here (a pair of cheap add_action calls) guarantees the loopback cron worker
// has the handler.
\FazCookie\Admin\Modules\Scanner\Includes\Controller::register_cron_hook();
\FazCookie\Admin\Modules\Scanner\Includes\Controller::register_browser_scan_observer();

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
	// The one no-store set every FAZ veto sends (CDN-targeted directives
	// included); LiteSpeed's header keeps the no-vary token these routes
	// always carried.
	if ( \FazCookie\Includes\Cache_Compatibility::send_no_store_headers() ) {
		header( 'X-LiteSpeed-Cache-Control: no-cache, no-vary', true );
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
