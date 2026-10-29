<?php
/**
 * GTranslate integration: the domains its Translation Proxy serves this site on.
 *
 * GTranslate's paid tiers translate through a proxy run by GTranslate rather
 * than inside WordPress. The "Enterprise" tier serves every translated copy on
 * a host of its own — a sub-domain (`de.example.com`) or, with "custom
 * domains", another registrable domain altogether (`example.de`). The proxy
 * fetches the page from this origin, rewrites the origin's URLs to the
 * translated host, caches the result, and forwards every request made on the
 * translated host — /wp-json and admin-ajax.php included — back to this origin.
 *
 * For this plugin that means requests that are genuinely the site's own arrive
 * with `Origin: https://example.de` and without the Fetch Metadata headers the
 * proxy does not forward, and the same-origin gate of the consent log and of
 * the Do-Not-Sell form refused all of them as `cross_origin`. The domains the
 * proxy serves are listed in GTranslate's own settings, so they can be trusted
 * with no configuration from the site owner; faz_trusted_origins() merges what
 * this class returns.
 *
 * Read from the `GTranslate` option, the same shape GTranslate::load_settings()
 * derives its widget configuration from (gtranslate/gtranslate.php 5.x):
 *   - `pro_version` → URL structure `sub_directory`: same host, nothing to trust;
 *   - `enterprise_version` → URL structure `sub_domain`, one host per language:
 *     `<lang>.<host>`, or the hosts of `custom_domains_data` (a JSON object
 *     `{"de":"example.de",…}` stored slashed) when `custom_domains` is on;
 *   - the languages are `fincl_langs` and `incl_langs` (which one the widget
 *     shows depends on its look; both are hosts the proxy answers on).
 * `pro_version` wins over `enterprise_version` exactly as it does there.
 *
 * Deliberately NOT read: the `X-GT-Lang` / `X-GT-Viewer-IP` request headers the
 * proxy adds. Anyone who can reach the origin can send them, so they prove
 * nothing about where a request came from; trust is derived from the site's
 * own settings only.
 *
 * @package FazCookie\Includes\Integrations
 */

namespace FazCookie\Includes\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers only: there is nothing to hook, the list is read on demand.
 */
class Gtranslate {

	/**
	 * Whether the GTranslate plugin is loaded on this request.
	 *
	 * Its main file defines `GTRANSLATE_VERSION` and the `GTranslate` widget
	 * class on load. Checked at call time, never at include time: this plugin
	 * sorts before `gtranslate/` and is loaded first. A deactivated GTranslate
	 * leaves its option behind; its domains then stop being trusted, which is
	 * right — the proxy no longer serves this site from them.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'GTRANSLATE_VERSION' ) || class_exists( 'GTranslate', false );
	}

	/**
	 * Hosts the GTranslate proxy serves this site on, lowercase, unique.
	 *
	 * @param array|null  $data      The `GTranslate` option, or null to read it
	 *                               (only while GTranslate is active).
	 * @param string|null $home_host Host of the WordPress Address, or null for
	 *                               home_url()'s.
	 * @return string[]
	 */
	public static function translated_hosts( $data = null, $home_host = null ) {
		if ( null === $data ) {
			if ( ! self::is_active() || ! function_exists( 'get_option' ) ) {
				return array();
			}
			$data = get_option( 'GTranslate' );
		}
		if ( ! is_array( $data ) || empty( $data['enterprise_version'] ) || ! empty( $data['pro_version'] ) ) {
			// No proxy-on-its-own-host tier: the free widget translates in the
			// browser and the sub-directory tier stays on this host.
			return array();
		}

		$hosts            = array();
		$custom_languages = array();

		// Custom domains: one registrable domain per language, synchronised
		// from the GTranslate dashboard into a slashed JSON string.
		if ( ! empty( $data['custom_domains'] ) && ! empty( $data['custom_domains_data'] ) ) {
			$map = $data['custom_domains_data'];
			if ( is_string( $map ) ) {
				$map = json_decode( stripslashes( $map ), true );
			}
			if ( is_array( $map ) ) {
				foreach ( $map as $lang => $domain ) {
					if ( is_string( $domain ) && self::clean( array( $domain ) ) ) {
						$hosts[] = $domain;
						$custom_languages[] = strtolower( (string) $lang );
					}
				}
			}
			// GTranslate falls back to <lang>.<host> for each language absent
			// from the map, even when other languages have custom domains.
		}

		if ( null === $home_host ) {
			$home_host = ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) )
				? (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST )
				: '';
		}
		$home_host = strtolower( trim( (string) $home_host ) );
		if ( '' === $home_host || filter_var( $home_host, FILTER_VALIDATE_IP ) ) {
			return self::clean( $hosts );
		}
		// GTranslate's sub-domains hang off the bare domain: www.example.com
		// is translated on de.example.com. Do not also trust de.www.example.com:
		// it is not a proxy host described by these settings.
		if ( 0 === strpos( $home_host, 'www.' ) ) {
			$home_host = substr( $home_host, 4 );
		}

		$languages = array();
		foreach ( array( 'fincl_langs', 'incl_langs', 'languages' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$languages = array_merge( $languages, $data[ $key ] );
			}
		}
		$default = isset( $data['default_language'] ) ? strtolower( (string) $data['default_language'] ) : '';
		foreach ( $languages as $lang ) {
			$lang = strtolower( trim( (string) $lang ) );
			// Language codes are `de`, `zh-CN`, `haw`: letters and one hyphen.
			// The source language is served on the origin itself.
			if ( '' === $lang || $lang === $default || in_array( $lang, $custom_languages, true ) || ! preg_match( '/^[a-z]{2,3}(?:-[a-z]{2,4})?$/', $lang ) ) {
				continue;
			}
			$hosts[] = $lang . '.' . $home_host;
		}

		return self::clean( $hosts );
	}

	/**
	 * Lowercase, strip a scheme/path someone pasted, drop junk and duplicates.
	 *
	 * @param array $hosts Raw host strings.
	 * @return string[]
	 */
	private static function clean( array $hosts ) {
		$out = array();
		foreach ( $hosts as $host ) {
			$host = strtolower( trim( (string) $host ) );
			$host = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $host );
			$host = rtrim( (string) preg_replace( '#[/?\#:].*$#', '', (string) $host ), '.' );
			if ( '' !== $host && preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $host ) ) {
				$out[ $host ] = true;
			}
		}
		return array_keys( $out );
	}
}
