<?php
/**
 * Utility functions class
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @author     Fabio D'Alessandro
 * @package    FazCookie\Includes
 */

use FazCookie\Includes\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! function_exists( 'faz_parse_url' ) ) {
	/**
	 * Return parsed URL
	 *
	 * @param string $url URL string to be parsed.
	 * @return array URL parts.
	 */
	function faz_parse_url( $url ) {
		return function_exists( 'wp_parse_url' )
			? wp_parse_url( $url )
			: parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( 'faz_read_json_file' ) ) {
	/**
	 * Processes a json file from the specified path
	 * and returns an array with its contents, or a void array if none found.
	 *
	 * @since 3.0.0
	 *
	 * @param string $file_path Path to file. Empty if no file.
	 * @return array Contents from json file.
	 */
	function faz_read_json_file( $file_path = '' ) {
		$config = array();

		$file_system = Filesystem::get_instance();
		$json        = $file_system->get_contents( $file_path );
		if ( ! $json ) {
			return $config;
		}
		$decoded_file        = json_decode(
			$json,
			true
		);
		$json_decoding_error = json_last_error();
		if ( JSON_ERROR_NONE !== $json_decoding_error ) {
			return $config;
		}
		if ( is_array( $decoded_file ) ) {
			$config = $decoded_file;
		}
		return $config;
	}
}

if ( ! function_exists( 'faz_i18n_date' ) ) {
	/**
	 * Get localized date.
	 *
	 * @param string $date Date in time stamped format.
	 * @return string
	 */
	function faz_i18n_date( $date = '' ) {
		return date_i18n( 'd/m/Y g:i:s', $date );
	}
}
if ( ! function_exists( 'faz_is_admin_request' ) ) {
	/**
	 * Check if the current request is an admin (non-AJAX) request.
	 *
	 * @return boolean
	 */
	function faz_is_admin_request() {
		return is_admin() && ! faz_is_ajax_request();
	}
}
if ( ! function_exists( 'faz_is_ajax_request' ) ) {
	/**
	 * Check if the current request is an AJAX request.
	 *
	 * @return boolean
	 */
	function faz_is_ajax_request() {
		return wp_doing_ajax();
	}
}
if ( ! function_exists( 'faz_is_rest_request' ) ) {

	/**
	 * Check if a request is a rest request
	 *
	 * @return boolean
	 */
	function faz_is_rest_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$rest_prefix = trailingslashit( rest_get_url_prefix() );
		$request     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : false;
		if ( ! $request ) {
			return false;
		}
		$is_rest_api_request = ( false !== strpos( $request, $rest_prefix ) );

		return apply_filters( 'faz_is_rest_api_request', $is_rest_api_request );
	}
}
if ( ! function_exists( 'faz_array_search' ) ) {

	/**
	 * Get settings of element from banner properties by using the tag "data-faz-tag"
	 *
	 * @param array  $array Array to be searched.
	 * @param string $key Tag to be used for searching.
	 * @param string $value  Tag name.
	 * @return array
	 */
	function faz_array_search( $array = array(), $key = '', $value = '' ) {

		$results = array();
		if ( is_array( $array ) ) {
			if ( isset( $array[ $key ] ) && $array[ $key ] === $value ) {
				$results = $array;
			}
			foreach ( $array as $sub_array ) {
				$results = array_merge( $results, faz_array_search( $sub_array, $key, $value ) );
			}
		}
		return $results;
	}
}
if ( ! function_exists( 'faz_first_time_install' ) ) {

	/**
	 * Check if the plugin is activated for the first time.
	 *
	 * @return boolean
	 */
	function faz_first_time_install() {
		return (bool) get_site_transient( '_faz_first_time_install' ) || (bool) get_option( 'faz_first_time_activated_plugin' );
	}
}

if ( ! function_exists( 'faz_is_admin_page' ) ) {

	/**
	 * Check if the plugin is activated for the first time.
	 *
	 * @return boolean
	 */
	function faz_is_admin_page() {
		if ( ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'get_current_screen' ) && ! empty( get_current_screen() ) ) {
			$screen = get_current_screen();
			$page   = isset( $screen->id ) ? $screen->id : false;
			if ( false !== strpos( $page, 'toplevel_page_faz-cookie-manager' ) ) {
				return true;
			}
			if ( ! empty( $screen->parent_base ) && false !== strpos( $screen->parent_base, 'faz-cookie-manager' ) ) {
				return true;
			}
		} else {
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return false !== strpos( $page, 'faz-cookie-manager' );
	}
}

if ( ! function_exists( 'faz_is_front_end_request' ) ) {

	/**
	 * Check if request coming from front-end.
	 *
	 * @return boolean
	 */
	function faz_is_front_end_request() {
		if ( is_admin() || faz_is_rest_request() || faz_is_ajax_request() ) {
			return false;
		}
		return true;
	}
}
if ( ! function_exists( 'faz_disable_banner' ) ) {

	/**
	 * Check if the banner should be disabled (page builder preview contexts).
	 *
	 * @return boolean
	 */
	function faz_disable_banner() {
		global $wp_customize;
		if ( isset( $_GET['et_fb'] ) || ( defined( 'ET_FB_ENABLED' ) && ET_FB_ENABLED ) //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| isset( $_GET['elementor-preview'] ) || isset( $_POST['cs_preview_state'] ) || isset( $wp_customize ) //phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		|| ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) )
		{
			return true;
		}
		// Scanner mode: disable banner and blocking so the scanner iframe
		// can detect third-party scripts and the cookies they set.
		// Only works for logged-in admins to prevent abuse.
		if ( isset( $_GET['faz_scanning'] ) && '1' === $_GET['faz_scanning'] && current_user_can( 'manage_options' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Prevent LiteSpeed/cache from serving a cached version during scan.
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
			}
			// Tell LiteSpeed not to cache this page variation.
			if ( defined( 'LSCWP_V' ) ) {
				do_action( 'litespeed_control_set_nocache', 'FAZ scanner bypass' );
			}
			return true;
		}
		// Admin-only frontend preview iframe used by the banner customizer.
		if ( isset( $_GET['faz_banner_preview'] ) && '1' === $_GET['faz_banner_preview'] && current_user_can( 'manage_options' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
			}
			if ( defined( 'LSCWP_V' ) ) {
				do_action( 'litespeed_control_set_nocache', 'FAZ banner preview iframe' );
			}
			return true;
		}
		return false;
	}
}
if ( ! function_exists( 'faz_missing_tables' ) ) {

	/**
	 * Get the list of missing database tables.
	 *
	 * @return array
	 */
	function faz_missing_tables() {
		return get_option( 'faz_missing_tables', array() );
	}
}
if ( ! function_exists( 'faz_resolve_client_ip' ) ) {
	/**
	 * Resolve the client IP address with proxy awareness.
	 *
	 * Checks common proxy headers before falling back to REMOTE_ADDR.
	 * Proxy headers (X-Forwarded-For, X-Real-IP, CF-Connecting-IP) are
	 * client-controlled and can be spoofed. Private/reserved ranges are
	 * rejected to mitigate trivial bypasses.
	 *
	 * @since 1.1.0
	 * @return string Client IP address, or empty string if unavailable.
	 */
	function faz_resolve_client_ip() {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		/**
		 * Whether to trust proxy headers (X-Forwarded-For, X-Real-IP, CF-Connecting-IP).
		 *
		 * These headers are client-controlled and can be spoofed. Only enable
		 * this filter if WordPress is behind a trusted reverse proxy.
		 *
		 * @since 1.1.0
		 * @param bool   $trust       Whether to trust proxy headers. Default false.
		 * @param string $remote_addr The REMOTE_ADDR value.
		 */
		if ( apply_filters( 'faz_trust_proxy_headers', false, $remote_addr ) ) {
			$headers = array(
				'HTTP_CF_CONNECTING_IP', // Cloudflare.
				'HTTP_X_FORWARDED_FOR',  // Generic reverse proxy.
				'HTTP_X_REAL_IP',        // Nginx.
			);
			foreach ( $headers as $header ) {
				if ( ! empty( $_SERVER[ $header ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					// Header may contain comma-separated IPs (e.g. X-Forwarded-For chain) — take the first.
					$ip = strtok( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), ',' );
					$ip = trim( $ip );
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						return $ip;
					}
				}
			}
		}

		return $remote_addr;
	}
}

if ( ! function_exists( 'faz_throttle_request' ) ) {
	/**
	 * Rate limiter — returns true if the request should be throttled.
	 *
	 * Uses wp_cache_add() for atomic check-and-set when a persistent object
	 * cache (Redis, Memcached) is active. Falls back to transients (database-
	 * backed) on standard WordPress installations without persistent cache.
	 *
	 * @since 1.1.0
	 * @param string $prefix Cache key prefix (e.g. 'faz_consent', 'faz_pv').
	 * @param int    $ttl    Throttle window in seconds. Default 1.
	 * @return bool True if request is a duplicate and should be skipped.
	 */
	function faz_throttle_request( $prefix = 'faz_throttle', $ttl = 1 ) {
		$ttl       = max( 1, absint( $ttl ) );
		$client_ip = faz_resolve_client_ip();
		if ( empty( $client_ip ) ) {
			// Cannot identify the client — skip throttling rather than
			// collapsing all unidentified callers into one bucket.
			return false;
		}

		$ip_hash = md5( $client_ip );
		$key     = $prefix . '_' . $ip_hash;

		if ( wp_using_ext_object_cache() ) {
			// Persistent object cache — atomic wp_cache_add().
			return ! wp_cache_add( $key, 1, 'faz_throttle', $ttl );
		}

		// Fallback: transient-based throttle (database-backed, survives across requests).
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, $ttl );
		return false;
	}
}

if ( ! function_exists( 'faz_is_bot' ) ) {
	/**
	 * Detect search engine bots and crawlers by user agent.
	 *
	 * Returns true for known bot user agents so the cookie banner
	 * can be skipped (crawlers don't need consent).
	 *
	 * @since 1.5.0
	 * @return bool True if the current request is from a known bot.
	 */
	function faz_is_bot() {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return false;
		}
		$ua = wp_unslash( $_SERVER['HTTP_USER_AGENT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$bot_patterns = array(
			'Googlebot', 'Bingbot', 'Slurp', 'DuckDuckBot', 'Baiduspider',
			'YandexBot', 'facebot', 'ia_archiver', 'Twitterbot', 'LinkedInBot',
			'Pinterest', 'WhatsApp', 'TelegramBot', 'Applebot', 'AdsBot-Google',
			'Mediapartners-Google', 'Google-InspectionTool', 'Storebot-Google',
			'SemrushBot', 'AhrefsBot', 'MJ12bot', 'DotBot', 'PetalBot',
			'Bytespider', 'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'PerplexityBot',
			'Amazonbot', 'anthropic-ai', 'Discordbot',
		);

		/**
		 * Filter the list of bot user agent patterns.
		 *
		 * @since 1.5.0
		 * @param array $bot_patterns Array of user agent substrings to match.
		 */
		$bot_patterns = apply_filters( 'faz_bot_patterns', $bot_patterns );

		foreach ( $bot_patterns as $pattern ) {
			if ( false !== stripos( $ua, $pattern ) ) {
				/**
				 * Filter the bot detection result.
				 *
				 * @since 1.5.0
				 * @param bool   $is_bot Whether the current request is from a bot.
				 * @param string $ua     The user agent string.
				 */
				return apply_filters( 'faz_is_bot', true, $ua );
			}
		}

		/** This filter is documented above. */
		return apply_filters( 'faz_is_bot', false, $ua );
	}
}

if ( ! function_exists( 'faz_verify_nonce' ) ) {
	/**
	 * Verify nonce.
	 *
	 * @return WP_Error|boolean
	 */
	function faz_verify_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'fazcookie_rest_invalid_nonce', __( 'Invalid nonce. Please refresh the page and try again.', 'faz-cookie-manager' ), array( 'status' => 403 ) );
		}
		return true;
	}
}

if ( ! function_exists( 'faz_privacy_exporter' ) ) {
	/**
	 * Export personal data (consent logs) for WordPress privacy tools.
	 *
	 * Consent logs store only one-way hashed IPs (SHA-256 + salt), not email
	 * addresses. We cannot link consent records to a specific email address.
	 * This is privacy-by-design: the data is pseudonymized and not
	 * attributable to a specific individual without the original IP.
	 *
	 * @since 1.5.0
	 * @param string $email_address The user's email address.
	 * @param int    $page          Page number for batched exports.
	 * @return array Export data conforming to the WP privacy exporter format.
	 */
	function faz_privacy_exporter( $email_address, $page = 1 ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}
}

if ( ! function_exists( 'faz_privacy_eraser' ) ) {
	/**
	 * Erase personal data (consent logs) for WordPress privacy tools.
	 *
	 * Consent logs use one-way IP hashing (SHA-256 + salt). We cannot
	 * identify which records belong to a specific email address.
	 * Records are auto-purged after the configured retention period.
	 *
	 * @since 1.5.0
	 * @param string $email_address The user's email address.
	 * @param int    $page          Page number for batched erasures.
	 * @return array Erasure result conforming to the WP privacy eraser format.
	 */
	function faz_privacy_eraser( $email_address, $page = 1 ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => true,
			'messages'       => array(
				__( 'FAZ Cookie Manager consent logs use anonymized IP hashes and cannot be linked to email addresses. Records are automatically purged after the configured retention period.', 'faz-cookie-manager' ),
			),
			'done'           => true,
		);
	}
}
