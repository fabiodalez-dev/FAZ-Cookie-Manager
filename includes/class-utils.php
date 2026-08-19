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
	 * Reads the new `faz_first_time_install` transient (4-char `faz_` prefix
	 * compliant with the wp.org "Use Prefixes" guideline), and falls back to
	 * the legacy `_faz_first_time_install` for installs that activated under
	 * earlier plugin versions and have not yet hit class-activator.php (where
	 * the legacy name is migrated on the next activation).
	 *
	 * @return boolean
	 */
	function faz_first_time_install() {
		if ( (bool) get_site_transient( 'faz_first_time_install' ) ) {
			return true;
		}
		// Legacy fallback — pre-prefix-rename installs.
		if ( (bool) get_site_transient( '_faz_first_time_install' ) ) {
			return true;
		}
		return (bool) get_option( 'faz_first_time_activated_plugin' );
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
if ( ! function_exists( 'faz_is_machine_readable_request' ) ) {

	/**
	 * Whether this request renders a machine-readable representation of the
	 * site rather than a page a visitor browses.
	 *
	 * Feeds, robots.txt and trackbacks reach template_redirect and run
	 * `the_content`, so neither the is_admin() guards on the output buffer nor
	 * the one on filter_content_blocking() catches them. Rewriting there is
	 * both harmful and pointless: harmful because a feed carries no script.js,
	 * so an embed replaced by a data-faz-src placeholder can never be restored
	 * and is simply lost to every subscriber; pointless because consent is a
	 * browser-side state and a feed reader has no banner to accept.
	 *
	 * Deliberately NOT including is_404() or is_search() — those are ordinary
	 * pages a visitor sees, and third-party embeds can appear in both.
	 *
	 * @return bool
	 */
	function faz_is_machine_readable_request() {
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return true;
		}
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return true;
		}
		if ( function_exists( 'is_trackback' ) && is_trackback() ) {
			return true;
		}
		// Core added is_favicon() in 5.4; the plugin floor is 5.0.
		if ( function_exists( 'is_favicon' ) && is_favicon() ) {
			return true;
		}
		/**
		 * Filter whether this request is machine-readable and must never have
		 * its content rewritten by the blocker.
		 *
		 * @since 1.27.0
		 * @param bool $is_machine_readable Whether to skip blocking entirely.
		 */
		return (bool) apply_filters( 'faz_is_machine_readable_request', false );
	}
}
if ( ! function_exists( 'faz_is_banner_preview_request' ) ) {

	/**
	 * Check if the current request is the admin banner preview iframe.
	 *
	 * @return boolean
	 */
	function faz_is_banner_preview_request() {
		return isset( $_GET['faz_banner_preview'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& '1' === $_GET['faz_banner_preview'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& current_user_can( 'manage_options' );
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

		// A builder-preview marker in the URL is NOT proof of a builder
		// preview. These markers used to be honoured on presence alone — no
		// capability check, and no check that the builder was even installed —
		// and this function gates every destructive layer we have: the output
		// buffer, the script/style/inline tag filters and both content
		// filters. `?elementor-preview=1` therefore served any anonymous
		// visitor a page with every tracker unblocked and no banner, on any
		// site, including sites with no page builder at all. A crawler, a
		// shared link or a screenshot tool was enough to produce a
		// pre-consent tracking page.
		//
		// Editing is the capability the real preview always has, and the
		// builder's own constant/function is the proof it is installed.
		// Upstream carries the same markers, but there they only hide the
		// banner — we wired them into blocking, so the blast radius is ours.
		$faz_can_preview = function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );

		if ( $faz_can_preview
			&& ( ( isset( $_GET['et_fb'] ) && function_exists( 'et_fb_is_enabled' ) )
				|| ( defined( 'ET_FB_ENABLED' ) && ET_FB_ENABLED )
				|| ( isset( $_GET['elementor-preview'] ) && did_action( 'elementor/loaded' ) )
				|| ( isset( $_POST['cs_preview_state'] ) && defined( 'CS_VERSION' ) ) )
		) {
			return true;
		}

		// The Customizer is core, not a third-party builder: it authenticates
		// on its own and $wp_customize only exists inside it.
		if ( isset( $wp_customize )
			|| ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
			return true;
		}
		// Bricks Builder visual editor (?bricks=run) and its preview iframe
		// (?bricks_preview / ?_bricksmode). Bricks renders its layout outside
		// the_content filter, so the banner template would otherwise paint
		// on top of the editor canvas and block element clicks. Reported on
		// gooloo.de (#87 follow-up).
		if ( ( $faz_can_preview && isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] )
			|| ( $faz_can_preview && isset( $_GET['bricks_preview'] ) )
			|| ( $faz_can_preview && isset( $_GET['_bricksmode'] ) )
			|| ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
			|| ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() )
			|| ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) )
		{
			return true;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing
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
		if ( faz_is_banner_preview_request() ) {
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
if ( ! function_exists( 'faz_ip_in_cidr_list' ) ) {
	/**
	 * Whether an IP address falls within any CIDR / bare-IP entry in a list.
	 *
	 * Supports IPv4 and IPv6. A bare IP (no `/prefix`) matches only itself.
	 * Malformed entries are skipped. Used to gate trust of forwarded proxy
	 * headers behind a trusted-proxy allowlist.
	 *
	 * @since 1.17.2
	 * @param string   $ip   The IP address to test.
	 * @param string[] $list CIDRs (e.g. "10.0.0.0/8", "2400:cb00::/32") or bare IPs.
	 * @return bool True when $ip is inside any entry.
	 */
	function faz_ip_in_cidr_list( $ip, $list ) {
		$ip_bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid IP returns false, handled below.
		if ( false === $ip_bin ) {
			return false;
		}
		foreach ( (array) $list as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( false === strpos( $entry, '/' ) ) {
				// Bare IP — exact binary match.
				$entry_bin = @inet_pton( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $entry_bin && hash_equals( $entry_bin, $ip_bin ) ) {
					return true;
				}
				continue;
			}
			list( $subnet, $prefix ) = explode( '/', $entry, 2 );
			$subnet_bin              = @inet_pton( trim( $subnet ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$prefix                  = (int) $prefix;
			if ( false === $subnet_bin || $prefix < 0 ) {
				continue;
			}
			// Address families must match (both IPv4 or both IPv6).
			if ( strlen( $subnet_bin ) !== strlen( $ip_bin ) ) {
				continue;
			}
			$max_bits = strlen( $ip_bin ) * 8;
			if ( $prefix > $max_bits ) {
				continue;
			}
			$bytes_full = intdiv( $prefix, 8 );
			$bits_rem   = $prefix % 8;
			if ( $bytes_full > 0 && ! hash_equals( substr( $subnet_bin, 0, $bytes_full ), substr( $ip_bin, 0, $bytes_full ) ) ) {
				continue;
			}
			if ( 0 === $bits_rem ) {
				return true; // Full-byte prefix matched.
			}
			$mask     = chr( 0xFF << ( 8 - $bits_rem ) & 0xFF );
			$ip_byte  = isset( $ip_bin[ $bytes_full ] ) ? $ip_bin[ $bytes_full ] : "\0";
			$sub_byte = isset( $subnet_bin[ $bytes_full ] ) ? $subnet_bin[ $bytes_full ] : "\0";
			if ( ( ord( $ip_byte ) & ord( $mask ) ) === ( ord( $sub_byte ) & ord( $mask ) ) ) {
				return true;
			}
		}
		return false;
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
			/**
			 * Trusted reverse-proxy CIDR allowlist.
			 *
			 * Forwarded headers (X-Forwarded-For etc.) are client-controlled and
			 * spoofable. When this allowlist is non-empty, the forwarded headers
			 * are consulted ONLY if REMOTE_ADDR (the actual TCP peer) is one of
			 * the trusted proxies — so an attacker who can reach the origin
			 * directly cannot inject an arbitrary client IP to rotate past the
			 * per-IP throttle or poison the hashed ip_hash. When the allowlist
			 * is empty the legacy behaviour is preserved (the admin opted into
			 * `faz_trust_proxy_headers` without scoping it), but configuring
			 * this list is strongly recommended when the origin is also
			 * directly reachable. CIDRs and bare IPs are both accepted.
			 *
			 * @since 1.17.2
			 * @param string[] $cidrs       Trusted proxy CIDRs / IPs. Default empty.
			 * @param string   $remote_addr The REMOTE_ADDR value.
			 */
			$trusted_proxies = (array) apply_filters( 'faz_trusted_proxies', array(), $remote_addr );
			if ( ! empty( $trusted_proxies ) && ! faz_ip_in_cidr_list( $remote_addr, $trusted_proxies ) ) {
				// REMOTE_ADDR is not a trusted proxy — the forwarded headers
				// cannot be vouched for, so fall back to the real peer address.
				return $remote_addr;
			}
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

if ( ! function_exists( 'faz_path_matches_pattern' ) ) {
	/**
	 * Match a URL path against a glob-like pattern with case-folding and
	 * portable fallback when `fnmatch()` is unavailable.
	 *
	 * Handles three failure modes that the previous `fnmatch($pattern,
	 * $current_url)` call exhibited:
	 *   1. `fnmatch()` is not always available on Windows builds of PHP
	 *      without the POSIX extension — sites using `script_blocking.
	 *      excluded_pages` would fatal there. The fallback converts the
	 *      glob to a regex using only `preg_*` (always available).
	 *   2. `fnmatch()` defaults to case-sensitive on POSIX. URL paths are
	 *      case-insensitive in practice (admins typing `/Privacy/*` expect
	 *      `/privacy/foo` to match). We pass `FNM_CASEFOLD` when the
	 *      constant exists, and use `i` flag in the regex fallback.
	 *   3. Trailing-slash mismatch (`/privacy` vs `/privacy/`) — caller
	 *      should pre-normalise (this helper does not).
	 *
	 * @since 1.13.12
	 * @param string $pattern Glob pattern (e.g. `/privacy/*`).
	 * @param string $path    Request path (already query-/fragment-stripped).
	 * @return bool
	 */
	function faz_path_matches_pattern( $pattern, $path ) {
		$pattern = (string) $pattern;
		$path    = (string) $path;
		if ( '' === $pattern ) {
			return false;
		}
		if ( function_exists( 'fnmatch' ) ) {
			$flags = defined( 'FNM_CASEFOLD' ) ? FNM_CASEFOLD : 0;
			return fnmatch( $pattern, $path, $flags );
		}
		// Portable fallback: glob → regex. Quote everything but `*` and `?`,
		// then expand them. Anchor to full-string match. Case-insensitive.
		$regex = '';
		$len   = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $pattern[ $i ];
			if ( '*' === $c ) {
				$regex .= '.*';
			} elseif ( '?' === $c ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $c, '#' );
			}
		}
		return 1 === preg_match( '#^' . $regex . '$#i', $path );
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
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
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
		$export_items = array();
		$email        = sanitize_email( (string) $email_address );
		$page         = max( 1, (int) $page );
		$per_page     = 20;
		$done         = true;

		// Consent logs store only one-way hashed IPs (SHA-256 + salt) and cannot
		// be linked back to an email address — nothing to export there. DSAR form
		// submissions (post_type faz_dsar), however, store the requester's name,
		// email, request type and message in the clear, keyed by _dsar_email, so
		// they ARE exportable personal data.
		if ( is_email( $email ) && post_type_exists( 'faz_dsar' ) ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'faz_dsar',
					'post_status'    => 'any',
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- DSAR exports are admin-triggered, low-volume, and must filter by the requester's email.
					'meta_query'     => array(
						array(
							'key'     => '_dsar_email',
							'value'   => $email,
							'compare' => '=',
						),
					),
				)
			);
			foreach ( $query->posts as $post_id ) {
				$export_items[] = array(
					'group_id'    => 'faz_dsar_requests',
					'group_label' => __( 'Privacy requests (FAZ Cookie Manager)', 'faz-cookie-manager' ),
					'item_id'     => 'faz-dsar-' . (int) $post_id,
					'data'        => array(
						array(
							'name'  => __( 'Name', 'faz-cookie-manager' ),
							'value' => (string) get_post_meta( $post_id, '_dsar_name', true ),
						),
						array(
							'name'  => __( 'Email', 'faz-cookie-manager' ),
							'value' => (string) get_post_meta( $post_id, '_dsar_email', true ),
						),
						array(
							'name'  => __( 'Request type', 'faz-cookie-manager' ),
							'value' => (string) get_post_meta( $post_id, '_dsar_type', true ),
						),
						array(
							'name'  => __( 'Message', 'faz-cookie-manager' ),
							'value' => (string) get_post_meta( $post_id, '_dsar_message', true ),
						),
						array(
							'name'  => __( 'Status', 'faz-cookie-manager' ),
							'value' => (string) get_post_meta( $post_id, '_dsar_status', true ),
						),
						array(
							'name'  => __( 'Submitted', 'faz-cookie-manager' ),
							'value' => (string) get_post_field( 'post_date', $post_id ),
						),
					),
				);
			}
			$done = $page >= max( 1, (int) $query->max_num_pages );
		}

		return array(
			'data' => $export_items,
			'done' => $done,
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
		$email         = sanitize_email( (string) $email_address );
		$page          = max( 1, (int) $page );
		$per_page      = 20;
		$items_removed = 0;
		$messages      = array();
		$done          = true;

		// Consent logs use one-way IP hashing and cannot be linked to an email,
		// so there is nothing to erase there (they auto-purge by retention). DSAR
		// form submissions DO carry the requester's email and free text, so they
		// are erasable on request.
		$messages[] = __( 'FAZ Cookie Manager consent logs use anonymized IP hashes and cannot be linked to email addresses; they auto-purge after the configured retention period.', 'faz-cookie-manager' );

		if ( is_email( $email ) && post_type_exists( 'faz_dsar' ) ) {
			// IMPORTANT: always read the FIRST batch, never `paged => $page`.
			// WordPress calls the eraser repeatedly, incrementing $page, but each
			// call here DELETES the records it finds — so the matching set shrinks
			// underneath the offset. Paging forward (page 2, 3, …) would skip the
			// records that slid into the earlier offsets and leave them behind
			// (e.g. 25 records / 20-per-page → page 1 deletes 20, page 2 offsets
			// past the remaining 5 and reports done, orphaning them). Reading
			// page 1 every time drains the set deterministically.
			$query = new WP_Query(
				array(
					'post_type'      => 'faz_dsar',
					'post_status'    => 'any',
					'posts_per_page' => $per_page,
					'paged'          => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- DSAR erasures are admin-triggered, low-volume, and must filter by the requester's email.
					'meta_query'     => array(
						array(
							'key'     => '_dsar_email',
							'value'   => $email,
							'compare' => '=',
						),
					),
				)
			);
			$found = count( $query->posts );
			foreach ( $query->posts as $post_id ) {
				if ( wp_delete_post( (int) $post_id, true ) ) {
					$items_removed++;
				}
			}
			if ( $items_removed > 0 ) {
				/* translators: %d: number of privacy-request records deleted. */
				$messages[] = sprintf( _n( 'Deleted %d FAZ Cookie Manager privacy request.', 'Deleted %d FAZ Cookie Manager privacy requests.', $items_removed, 'faz-cookie-manager' ), $items_removed );
			}
			// Not done while this batch was full — there may be more to drain on
			// the next call (which again reads the new first page).
			$done = $found < $per_page;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => $done,
		);
	}
}

if ( ! function_exists( 'faz_region_map' ) ) {
	/**
	 * The canonical region-key to country-code table.
	 *
	 * This is the single definition in the plugin. It used to be written out
	 * twice — once in Frontend::is_country_in_regions() and once in
	 * AMP_Consent::country_in_regions(), the second one carrying a docblock
	 * that called itself a mirror of the first. Hand-copied mirrors drift:
	 * the 'za' bucket was added to the Frontend table and never to the AMP
	 * one, and the divergence stayed invisible only because a single-country
	 * region token also matches through the direct-country-code fallback in
	 * faz_country_in_regions(). A multi-country region added to one table and
	 * not the other would not have been rescued that way. Both call sites now
	 * read this array, so "the two tables are identical" is a property of the
	 * code rather than something a reviewer has to re-check by eye.
	 *
	 * The 'eu' preset deliberately EXCLUDES GB, matching REGION_PRESETS.EU in
	 * admin/assets/js/pages/banner.js: the UK has its own data-protection
	 * regime (UK GDPR) and its own 'uk' bucket. Geolocation::$eu_countries is
	 * a separate concern (an EU+UK shorthand for "is this visitor under any
	 * GDPR-class law") and is intentionally not derived from this table.
	 *
	 * @since 1.26.0
	 * @return array<string, string[]> Lowercase region key => uppercase ISO 3166-1 alpha-2 codes.
	 */
	function faz_region_map() {
		return array(
			'eu' => array(
				'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
				'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
				'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
				// EEA.
				'IS', 'LI', 'NO',
			),
			'uk' => array( 'GB' ),
			'us' => array( 'US' ),
			'ca' => array( 'CA' ),
			'br' => array( 'BR' ),
			'au' => array( 'AU' ),
			'jp' => array( 'JP' ),
			'ch' => array( 'CH' ),
			'za' => array( 'ZA' ),
		);
	}
}

if ( ! function_exists( 'faz_country_in_regions' ) ) {
	/**
	 * Whether a country code belongs to any of the given region groups.
	 *
	 * Region keys are matched case-insensitively against faz_region_map(). A
	 * token that is not a known region key is treated as a direct country code
	 * (e.g. 'ZA' before the 'za' bucket existed), so publisher configurations
	 * naming a plain ISO code keep working. Unmatched means false.
	 *
	 * @since 1.26.0
	 * @param string   $country_code ISO 3166-1 alpha-2 country code.
	 * @param string[] $regions      Region keys ('eu', 'uk', ...) or direct country codes.
	 * @return bool True when $country_code matches any entry in $regions.
	 */
	function faz_country_in_regions( $country_code, $regions ) {
		$country_code = strtoupper( (string) $country_code );
		$region_map   = faz_region_map();

		foreach ( (array) $regions as $region ) {
			$region = strtolower( (string) $region );
			if ( isset( $region_map[ $region ] ) ) {
				if ( in_array( $country_code, $region_map[ $region ], true ) ) {
					return true;
				}
			} elseif ( strtoupper( $region ) === $country_code ) {
				// Direct country code match (e.g., 'ZA' for South Africa).
				return true;
			}
		}

		return false;
	}
}

/**
 * Merge settings arrays recursively, replacing sequential (numeric-keyed)
 * arrays entirely instead of concatenating them.
 *
 * @param array $existing Current settings from the database.
 * @param array $incoming New values from the API request.
 * @return array Merged settings.
 */
function faz_merge_settings( array $existing, array $incoming ) {
	foreach ( $incoming as $key => $value ) {
		if ( isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) && is_array( $value ) ) {
			if ( wp_is_numeric_array( $value ) ) {
				$existing[ $key ] = $value;
			} else {
				$existing[ $key ] = faz_merge_settings( $existing[ $key ], $value );
			}
		} else {
			$existing[ $key ] = $value;
		}
	}
	return $existing;
}
