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
if ( ! function_exists( 'faz_normalize_page_url' ) ) {
	/**
	 * Reduce a URL to the page it identifies.
	 *
	 * Scheme, host, port, path and routing query; no credentials, no fragment, and
	 * no trailing slash. Two sides depend on producing the SAME string for the
	 * same page: the consent log stores it, and the placeholder inventory looks
	 * it up to answer "did this page ever offer that embed". If the two spellings
	 * drift, every exception reads as unverifiable, so the rule lives here once
	 * rather than in each caller. The scheme is kept in the returned string (it
	 * is what an administrator reads in the log), but the inventory keys its
	 * rows on this string with the scheme stripped — Embed_Inventory::key() —
	 * because the rendering request and the logging request can see different
	 * schemes behind a TLS-terminating proxy.
	 *
	 * Campaign and session parameters are dropped; WordPress routing parameters
	 * are retained so distinct posts and language variants cannot share evidence.
	 * The consent log stores its page URL through this function too, so the
	 * routing parameters that tell two pages apart survive into the log row.
	 *
	 * Those retained parameters come from the request, so anyone can vary them:
	 * `?lang=<anything>` on a site with no language plugin is a new string per
	 * value. Never use the output directly as a persistence key without a
	 * write-side cardinality gate — the embed inventory's flush() is the
	 * reference (no rows for 404 / search pages, none for a routing parameter
	 * WordPress did not parse). Keep this function identical for readers:
	 * the gate belongs on the write side only.
	 *
	 * @param string $url URL to reduce.
	 * @return string Normalised URL, or '' when nothing usable remains.
	 */
	function faz_normalize_page_url( $url ) {
		$url = function_exists( 'esc_url_raw' ) ? esc_url_raw( (string) $url ) : (string) $url;
		if ( '' === $url ) {
			return '';
		}

		$parts = faz_parse_url( $url );
		if ( false === $parts || ! is_array( $parts ) ) {
			return '';
		}

		$normalized = '';
		if ( ! empty( $parts['scheme'] ) ) {
			$normalized .= $parts['scheme'] . '://';
		}
		// Deliberately omit user:pass — never persist credentials.
		if ( ! empty( $parts['host'] ) ) {
			$normalized .= $parts['host'];
		}
		if ( ! empty( $parts['port'] ) ) {
			$normalized .= ':' . absint( $parts['port'] );
		}
		if ( ! empty( $parts['path'] ) ) {
			$normalized .= $parts['path'];
		}

		// A deeper path is the same page with or without a trailing slash, and
		// WordPress serves both. The home page is the case that bites: it
		// arrives as "https://site" from a stored value and "https://site/"
		// from a browser, so it is spelled one way — with the slash — rather
		// than left to whichever form happened to reach us.
		if ( '' !== $normalized ) {
			$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
			if ( '' === $path || '/' === $path ) {
				$normalized = rtrim( $normalized, '/' ) . '/';
			} else {
				$normalized = rtrim( $normalized, '/' );
			}
		}

		// Preserve WordPress routing parameters (plain permalinks, archives and
		// language/AMP variants), never campaign or arbitrary session parameters.
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$route = array_intersect_key( $query, array_flip( array( 'p', 'page_id', 'attachment_id', 'name', 'pagename', 'post_type', 'cat', 'tag', 'taxonomy', 'term', 'author', 'year', 'monthnum', 'day', 'paged', 'page', 's', 'lang', 'amp', 'feed' ) ) );
			ksort( $route );
			if ( ! empty( $route ) ) {
				$normalized .= '?' . http_build_query( $route, '', '&', PHP_QUERY_RFC3986 );
			}
		}
		return $normalized;
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
if ( ! function_exists( 'faz_cloudflare_proxy_cidrs' ) ) {
	/**
	 * Cloudflare's published proxy IP ranges — the shipped list, unfiltered.
	 *
	 * https://www.cloudflare.com/ips/, static as of 2026 — REFRESH ANNUALLY.
	 * This is the plugin's single copy of the list.
	 *
	 * Deliberately NOT filterable, and deliberately separate from
	 * `faz_trusted_proxy_cidrs()`. `CF-Connecting-IP` may be believed only from
	 * a peer that actually is Cloudflare, because Cloudflare is what overwrites
	 * that header; from anywhere else it is a client-supplied string. Were the
	 * CF binding read off the filtered list instead, an admin who added their
	 * own CDN's ranges would also have made `CF-Connecting-IP` authoritative
	 * from that CDN — a header it never writes, so one the visitor still
	 * controls. Hence a private copy here and a filtered view next door.
	 *
	 * @since 1.34.1
	 * @return string[] CIDR ranges, IPv4 first then IPv6.
	 */
	function faz_cloudflare_proxy_cidrs() {
		return array(
			// IPv4.
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			// IPv6.
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);
	}
}

if ( ! function_exists( 'faz_peer_is_cloudflare' ) ) {
	/**
	 * Whether the request arrived from one of Cloudflare's own published edges.
	 *
	 * The one predicate for "may this request assert a Cloudflare header".
	 * `CF-Connecting-IP`, `CF-IPCountry` and `CF-Region-Code` are written by
	 * Cloudflare's edge and by nothing else, so each of them is only worth
	 * reading when the peer is Cloudflare — and that question has to be
	 * answered the same way everywhere, which is why it lives here and not in
	 * the three classes that ask it.
	 *
	 * Reads the SHIPPED list and never the filtered view. An administrator who
	 * adds a range for their own CDN is telling the plugin which peer is
	 * infrastructure, not which headers that peer invents; granting a header
	 * CDN never writes on the strength of that range is how a visitor behind it
	 * ends up choosing their own country. Widening trust for an operator's own
	 * edge is `faz_trusted_proxy_cidrs` plus `faz_proxy_forwarded_header`.
	 *
	 * @since 1.34.1
	 * @param string $remote_addr Peer address; read from REMOTE_ADDR when empty.
	 * @return bool
	 */
	function faz_peer_is_cloudflare( $remote_addr = '' ) {
		if ( '' === $remote_addr ) {
			$remote_addr = ! empty( $_SERVER['REMOTE_ADDR'] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				? (string) $_SERVER['REMOTE_ADDR']
				: '';
		}
		if ( '' === $remote_addr || ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( faz_ip_in_cidr_list( $remote_addr, faz_cloudflare_proxy_cidrs() ) ) {
			return true;
		}

		// Many Cloudflare origins do not look like one, and the ones that follow
		// Cloudflare's own advice look least like it. nginx's real_ip module
		// with `real_ip_header CF-Connecting-IP`, Apache's mod_remoteip, and
		// most managed hosts RESTORE the visitor's address at the origin, so
		// REMOTE_ADDR is the visitor — or 127.0.0.1 behind a loopback proxy —
		// and never an edge. The range test above can never match there.
		//
		// There is nothing left for this function to verify on such a site:
		// the peer has already been rewritten, and whether that rewrite can be
		// trusted is a fact about the network, not about the request. Only the
		// operator knows it, so only the operator can assert it.
		//
		// Note what is NOT required: that `CF-Connecting-IP` still be present,
		// nor that it equal REMOTE_ADDR. An earlier revision demanded both and
		// was wrong twice over. mod_remoteip REMOVES the header once it has
		// consumed it — and a single-valued `CF-Connecting-IP` always is — so
		// the requirement made this unreachable on exactly the Apache origins
		// it names, and unreachable behind a loopback proxy that does not pass
		// the header on. The equality was no safeguard either: a client
		// reaching the origin directly can send its own address and satisfy
		// it, which is why it was already gated behind this same filter.
		/**
		 * Whether the visitor's address is restored at the origin for Cloudflare.
		 *
		 * Return true ONLY when both of these hold:
		 *
		 * - the web server, or the host, rewrites `REMOTE_ADDR` from
		 *   `CF-Connecting-IP` for Cloudflare's ranges and nothing else
		 *   (nginx `real_ip_header` with `set_real_ip_from` limited to those
		 *   ranges, Apache `mod_remoteip` with `RemoteIPTrustedProxy`, or the
		 *   equivalent your host documents);
		 * - the origin is NOT reachable except through Cloudflare.
		 *
		 * The second is the one that matters and the one no plugin can check.
		 * Without it a client reaches the origin directly and dictates
		 * `CF-IPCountry`, which is the whole reason this defaults to false.
		 *
		 * @since 1.34.1
		 * @param bool $restored Default false.
		 */
		if ( (bool) apply_filters( 'faz_remote_addr_rewritten_by_cloudflare', false ) ) {
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'faz_trusted_proxy_cidrs' ) ) {
	/**
	 * The reverse-proxy ranges whose forwarded header may be read at all.
	 *
	 * The shipped Cloudflare ranges (faz_cloudflare_proxy_cidrs()) plus
	 * whatever the `faz_trusted_proxy_cidrs` filter adds. `Geo_Detector::
	 * is_trusted_proxy()` reads this same function, so the two resolvers cannot
	 * attribute the same request to two different addresses.
	 *
	 * Membership here answers one question only — "is this peer infrastructure
	 * rather than a visitor?" — and it does NOT say which header that peer
	 * writes. That second question is answered per peer kind in
	 * `faz_forwarded_client_ip()`: a Cloudflare peer by `CF-Connecting-IP`, an
	 * admin-added peer by `faz_proxy_forwarded_header` (`X-Forwarded-For` by
	 * default), a loopback peer by `faz_loopback_proxy_header` (unset by
	 * default). Adding a range is therefore necessary but not always
	 * sufficient: it is the whole configuration for a CDN that appends to
	 * `X-Forwarded-For`, and half of it for one that writes some other header.
	 *
	 * A request whose REMOTE_ADDR is NOT in this list, and is not loopback, is
	 * treated as direct: CF-Connecting-IP / X-Forwarded-For / X-Real-IP are
	 * ignored, because those are set by an edge and anyone who can reach the
	 * origin directly can forge them.
	 *
	 * @since 1.34.1
	 * @return string[] CIDR ranges, IPv4 first then IPv6.
	 */
	function faz_trusted_proxy_cidrs() {
		/**
		 * Extend (or replace) the shipped trusted-proxy ranges.
		 *
		 * Add the published edge ranges of another CDN here to have the plugin
		 * recognise it as infrastructure rather than as a visitor, without
		 * opting into the unscoped `faz_trust_proxy_headers` trust. CIDRs and
		 * bare IPs are both accepted; non-string entries are ignored by the
		 * matcher.
		 *
		 * This filter alone does not choose a header. A peer matched only here
		 * is read through `faz_proxy_forwarded_header`, which defaults to
		 * `HTTP_X_FORWARDED_FOR` and is walked from the right — correct for any
		 * CDN that appends to that chain. A CDN that writes its own header
		 * instead (`True-Client-IP`, `Fastly-Client-IP`, …) needs both filters:
		 * this one to trust the peer, that one to name the header. The shipped
		 * `CF-Connecting-IP` binding is NOT extended by adding a range, by
		 * design — see faz_cloudflare_proxy_cidrs().
		 *
		 * @since 1.34.1
		 * @param string[] $cidrs Default = Cloudflare's published proxy ranges.
		 */
		return (array) apply_filters( 'faz_trusted_proxy_cidrs', faz_cloudflare_proxy_cidrs() );
	}
}

if ( ! function_exists( 'faz_ip_is_private_or_local' ) ) {
	/**
	 * Whether an address is loopback, link-local, RFC1918/ULA or reserved.
	 *
	 * Such an address cannot identify a visitor arriving from the internet: it
	 * is either the machine itself or something inside the private network.
	 *
	 * @since 1.34.1
	 * @param string $ip Address to test.
	 * @return bool False for a syntactically invalid address — ask
	 *              `filter_var()` separately when that distinction matters.
	 */
	function faz_ip_is_private_or_local( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}

if ( ! function_exists( 'faz_ip_is_loopback' ) ) {
	/**
	 * Whether an address is the loopback interface (127.0.0.0/8 or ::1).
	 *
	 * The one private class a remote client can never present as REMOTE_ADDR:
	 * a packet from the internet cannot arrive with a loopback source address,
	 * so a loopback peer is a reverse proxy or cache on this same host.
	 *
	 * Deliberately NOT the whole of RFC1918: on a LAN-hosted or containerised
	 * site a visitor's own peer address can be 10.x/172.16.x/192.168.x, which
	 * would make the visitor the "proxy" and leave them free to forge the
	 * forwarded header. Those topologies stay behind the explicit
	 * `faz_trust_proxy_headers` / `faz_trusted_proxies` opt-in.
	 *
	 * @since 1.34.1
	 * @param string $ip Address to test.
	 * @return bool
	 */
	function faz_ip_is_loopback( $ip ) {
		return faz_ip_in_cidr_list( (string) $ip, array( '127.0.0.0/8', '::1/128' ) );
	}
}

if ( ! function_exists( 'faz_remote_addr_is_trusted_proxy' ) ) {
	/**
	 * Whether the TCP peer in front of us is infrastructure, not a visitor.
	 *
	 * True in two cases, and only these two:
	 *   1. The address is inside the trusted-proxy ranges — the shipped
	 *      Cloudflare list plus anything `faz_trusted_proxy_cidrs` adds.
	 *   2. The address is loopback — a reverse proxy, a cache or a load
	 *      balancer on this same host. See faz_ip_is_loopback() for why the
	 *      rest of RFC1918 is deliberately NOT included.
	 *
	 * Also used to recognise known hops while walking an X-Forwarded-For
	 * chain, which is the same question asked of a different address.
	 *
	 * @since 1.34.1
	 * @param string $remote_addr The REMOTE_ADDR value, or one chain entry.
	 * @return bool
	 */
	function faz_remote_addr_is_trusted_proxy( $remote_addr ) {
		$remote_addr = (string) $remote_addr;
		if ( '' === $remote_addr || ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( faz_ip_is_loopback( $remote_addr ) ) {
			return true;
		}
		return faz_ip_in_cidr_list( $remote_addr, faz_trusted_proxy_cidrs() );
	}
}

if ( ! function_exists( 'faz_forwarded_client_ip' ) ) {
	/**
	 * The client address a verified proxy forwarded, or '' when there is none.
	 *
	 * Callers must first satisfy themselves that the peer is a proxy at all
	 * (faz_remote_addr_is_trusted_proxy()); this helper decides which header
	 * may be believed and which entry of it is the client.
	 *
	 * Three rules, all of them load-bearing:
	 *
	 * 1. Each header is bound to the proxy that actually sets it, and the kind
	 *    of peer in front decides which single header may be read. There are
	 *    three peer kinds and each gets exactly one header:
	 *      - a peer in the SHIPPED Cloudflare ranges
	 *        (faz_cloudflare_proxy_cidrs(), never the filtered list) is read on
	 *        `CF-Connecting-IP`, because Cloudflare OVERWRITES that header;
	 *        from anywhere else it is just another client-supplied string;
	 *      - a peer matching a range an admin added through
	 *        `faz_trusted_proxy_cidrs` is read on `faz_proxy_forwarded_header`,
	 *        `HTTP_X_FORWARDED_FOR` by default and walked from the right, which
	 *        is what every CDN that appends to that chain produces;
	 *      - a loopback peer is read on `faz_loopback_proxy_header`, which is
	 *        UNSET by default and so reads nothing until the site names the
	 *        header its local proxy writes.
	 *    Treating the headers as interchangeable let a client pick which one
	 *    was authoritative, which is the whole exploit.
	 * 2. There is NO fallback between headers. If the selected header yields
	 *    nothing usable, this returns '' and the caller keeps REMOTE_ADDR.
	 *    Falling through to a second header hands the choice back to whoever
	 *    sent the request.
	 * 3. The selected chain is walked from the RIGHT. A proxy APPENDS the peer
	 *    it saw rather than rewriting the header, so everything the client sent
	 *    stays on the left: taking the leftmost entry is taking a value the
	 *    client controls, which is a fresh throttle bucket per request and a
	 *    poisoned ip_hash. Known hops (trusted ranges, loopback) are stepped
	 *    over; the first entry that is not a known hop must be a valid public
	 *    address, and if it is not — private, reserved, malformed — the walk
	 *    stops rather than guessing further left.
	 *
	 * @since 1.34.1
	 * @param string $remote_addr The REMOTE_ADDR value (the actual TCP peer).
	 * @return string Public client address, or '' when none can be vouched for.
	 */
	function faz_forwarded_client_ip( $remote_addr = '' ) {
		$remote_addr = (string) $remote_addr;
		if ( '' === $remote_addr || ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		// Peer kind 1 — a Cloudflare edge: one header, no alternatives, no
		// fallback. Matched against the SHIPPED list and not the filtered one,
		// so a range an admin added for their own CDN can never make
		// CF-Connecting-IP authoritative from a peer that never writes it.
		if ( faz_ip_in_cidr_list( $remote_addr, faz_cloudflare_proxy_cidrs() ) ) {
			if ( empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				return '';
			}
			$cf_ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return filter_var( $cf_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ? $cf_ip : '';
		}

		if ( faz_ip_is_loopback( $remote_addr ) ) {
			// Peer kind 2 — a reverse proxy, cache or load balancer on this
			// same host.
			/**
			 * The one forwarded header a loopback reverse proxy is known to write.
			 *
			 * UNSET by default, deliberately, and so nothing is read from a
			 * loopback peer until the site names a header. The precondition for
			 * guessing wrong is the common case, not an exotic one: nginx's own
			 * convention is `proxy_set_header X-Real-IP $remote_addr;` with
			 * nothing managing X-Forwarded-For at all, and a header the local
			 * proxy does not itself write arrives from the client untouched. A
			 * default of `HTTP_X_FORWARDED_FOR` would therefore have let a
			 * visitor on such a host name their own address — a fresh per-IP
			 * throttle key per request and a poisoned consent-log ip_hash —
			 * which is worse than resolving to the peer address and skipping
			 * the per-IP ceiling, the behaviour an unconfigured site now gets.
			 *
			 * Name the header the local proxy writes, and only that one:
			 * `HTTP_X_FORWARDED_FOR` for `proxy_set_header X-Forwarded-For
			 * $proxy_add_x_forwarded_for;`, `HTTP_X_REAL_IP` for
			 * `proxy_set_header X-Real-IP $remote_addr;`. Exactly one header is
			 * read, and there is no fallback to another.
			 *
			 * @since 1.34.1
			 * @param string $header The `$_SERVER` key to read. Default '' (read nothing).
			 */
			$header = (string) apply_filters( 'faz_loopback_proxy_header', '' );
		} elseif ( faz_ip_in_cidr_list( $remote_addr, faz_trusted_proxy_cidrs() ) ) {
			// Peer kind 3 — a range an admin added through
			// `faz_trusted_proxy_cidrs`. The shipped CF binding above does not
			// apply: this peer is not Cloudflare and does not write
			// CF-Connecting-IP, so it is read through a named header instead.
			/**
			 * The forwarded header a non-Cloudflare trusted proxy writes.
			 *
			 * Applies to a peer matched by a range added through
			 * `faz_trusted_proxy_cidrs`. `HTTP_X_FORWARDED_FOR` by default,
			 * walked from the right, which is correct for every CDN or load
			 * balancer that APPENDS the peer it saw to that chain. Point it at
			 * `HTTP_TRUE_CLIENT_IP`, `HTTP_FASTLY_CLIENT_IP` or whatever single
			 * header your edge overwrites if it uses one of its own.
			 *
			 * Exactly one header is read, and there is no fallback to another:
			 * a fallback would let the visitor decide which header was
			 * authoritative by sending the other one.
			 *
			 * @since 1.34.1
			 * @param string $header      The `$_SERVER` key to read.
			 * @param string $remote_addr The peer address being read through.
			 */
			$header = (string) apply_filters( 'faz_proxy_forwarded_header', 'HTTP_X_FORWARDED_FOR', $remote_addr );
		} else {
			// Not a peer kind this function knows how to read.
			return '';
		}

		if ( '' === $header || empty( $_SERVER[ $header ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return '';
		}

		$chain = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array_reverse( $chain ) as $hop ) {
			$hop = trim( (string) $hop );
			if ( faz_remote_addr_is_trusted_proxy( $hop ) ) {
				// A hop we already know about — keep walking left.
				continue;
			}
			if ( filter_var( $hop, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $hop;
			}
			// Not a known hop and not a usable public address: stop here.
			// Anything further left was supplied by whoever sent this.
			break;
		}

		return '';
	}
}

if ( ! function_exists( 'faz_resolve_client_ip' ) ) {
	/**
	 * Resolve the client IP address with proxy awareness.
	 *
	 * Two paths, in this order:
	 *
	 * 1. `faz_trust_proxy_headers` — the explicit opt-in, unchanged since
	 *    1.1.0 and scoped by `faz_trusted_proxies` since 1.17.2. A site
	 *    already using those filters behaves exactly as it did.
	 * 2. Since 1.34.1, a trusted-peer path through faz_forwarded_client_ip(),
	 *    which binds each header to the proxy kind that sets it and walks a
	 *    chain from the right. ONE topology resolves with no configuration at
	 *    all: a peer in the shipped Cloudflare ranges, read on
	 *    `CF-Connecting-IP`. Another CDN takes a range in
	 *    `faz_trusted_proxy_cidrs` (and `faz_proxy_forwarded_header` if it
	 *    writes something other than X-Forwarded-For). A loopback peer takes
	 *    `faz_loopback_proxy_header`, which is unset by default, so until the
	 *    site names the header its local proxy writes nothing is read and the
	 *    peer address stands. Every per-IP key in this plugin — the DSAR
	 *    ceilings, the 60-second locks, the consent-log ip_hash, the geo lookup
	 *    — assumed this value identified ONE visitor, and behind an
	 *    unconfigured Cloudflare it was the edge's own address, shared by every
	 *    visitor of the site.
	 *
	 * A forwarded header arriving from a public peer that is NOT in the
	 * allowlist is still ignored, and so is one arriving from a trusted peer
	 * whose header has not been named; the resolved address stays REMOTE_ADDR:
	 * those headers are forgeable by anyone who can reach the origin, and
	 * trusting them unconditionally would hand an attacker a fresh key per
	 * request for every per-IP throttle in the plugin. The rest of RFC1918 is
	 * not a verified proxy either (faz_ip_is_loopback() says why), so a
	 * LAN-hosted or containerised site keeps using its peer address unless it
	 * opts in through path 1.
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
			// Deliberately NOT faz_forwarded_client_ip(): this path keeps the
			// semantics it has had since 1.1.0, leftmost entry first, because a
			// site that opted in may be relying on exactly that reading, and
			// scoping it is what `faz_trusted_proxies` is for. The stricter
			// parser governs the new default path below, which no site asked
			// for and must therefore not be fooled by a forged header.
			$headers = array(
				'HTTP_CF_CONNECTING_IP', // Cloudflare.
				'HTTP_X_FORWARDED_FOR',  // Generic reverse proxy.
				'HTTP_X_REAL_IP',        // Nginx.
			);
			foreach ( $headers as $header ) {
				if ( ! empty( $_SERVER[ $header ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					// Header may contain comma-separated IPs (e.g. X-Forwarded-For chain) — take the first.
					$ip = strtok( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), ',' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$ip = trim( (string) $ip );
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						return $ip;
					}
				}
			}

			return $remote_addr;
		}

		// Default path: the peer is verifiably a proxy, so the address it
		// forwards is the visitor. Without this every visitor behind that
		// proxy shares one key.
		if ( faz_remote_addr_is_trusted_proxy( $remote_addr ) ) {
			$forwarded = faz_forwarded_client_ip( $remote_addr );
			if ( '' !== $forwarded ) {
				return $forwarded;
			}
		}

		return $remote_addr;
	}
}

if ( ! function_exists( 'faz_client_ip_identifies_visitor' ) ) {
	/**
	 * Whether the resolved client address identifies one visitor.
	 *
	 * A per-IP ceiling may only be applied when it does. Behind a proxy whose
	 * forwarded header could not be read, every visitor of the site resolves to
	 * the same address, and a ceiling keyed on it stops being "five requests
	 * per visitor" and becomes "five requests for the whole site" — which, on
	 * the DSAR form, is a 24-hour denial of other people's Art. 15-22 requests.
	 * This predicate is for ceilings that DENY something to the visitor. The
	 * windows kept by `faz_throttle_request()` deliberately do not consult it:
	 * they bound writes from unauthenticated callers (the consent log, its
	 * refusal accounting, the AMP bridge, the pageview counter), and on a
	 * shared address a bounded site-wide window is the lesser harm compared to
	 * an unbounded one. The remedy for a site behind a proxy is to declare the
	 * proxy (`faz_trusted_proxy_cidrs`, `faz_loopback_proxy_header`), which
	 * gives every window a per-visitor key again.
	 *
	 * The rule is concrete, never a guess:
	 *   FALSE when the resolved value is empty, the `no-ip` literal, not a
	 *   valid address, loopback/RFC1918/ULA/reserved, or inside the
	 *   trusted-proxy ranges — shipped or added through
	 *   `faz_trusted_proxy_cidrs` — which means the forwarded value was
	 *   missing or unusable and what came back is the edge's own address.
	 *   TRUE otherwise — including an unknown CDN's edge, which is
	 *   indistinguishable from a direct public visitor and must not be guessed
	 *   at: treating "a forwarded header is present" as the test would silently
	 *   delete the ceiling on every host with mod_remoteip/realip configured,
	 *   where REMOTE_ADDR is already the real visitor and the header is still
	 *   there.
	 *
	 * @since 1.34.1
	 * @return bool
	 */
	function faz_client_ip_identifies_visitor() {
		$ip = (string) faz_resolve_client_ip();
		if ( '' === $ip || 'no-ip' === $ip ) {
			return false;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( faz_ip_is_private_or_local( $ip ) ) {
			return false;
		}
		return ! faz_ip_in_cidr_list( $ip, faz_trusted_proxy_cidrs() );
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
				// A record whose erasure has already been honoured keeps a dated,
				// non-identifying trace and NO address (see faz_privacy_eraser()).
				// It cannot match the meta_query above, so this is defence in
				// depth — but it is the line that guarantees an erased request is
				// never exported back out, and that nothing derived from the
				// address can leak through this exporter.
				$record_email = (string) get_post_meta( $post_id, '_dsar_email', true );
				if ( '' === $record_email ) {
					continue;
				}
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
							'value' => $record_email,
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
	 * Erase personal data (consent logs, stored DSAR requests) for WordPress
	 * privacy tools.
	 *
	 * Consent logs use one-way IP hashing (SHA-256 + salt). We cannot
	 * identify which records belong to a specific email address.
	 * Records are auto-purged after the configured retention period.
	 *
	 * Stored DSAR requests (post_type faz_dsar) DO carry the requester's name,
	 * address and free text, and are REDACTED here rather than deleted: the
	 * personal data goes, a dated record that the request existed and which
	 * right was invoked stays. Deleting the record outright destroyed the only
	 * evidence that the request was received and answered (GDPR Art. 5(2)
	 * accountability, Art. 12(3)) — and the admin screen tells the
	 * administrator that this record IS that audit trail. Keeping a
	 * non-identifying trace is also the position WordPress itself takes: core
	 * does not delete its own `user_request` post when an erasure completes, it
	 * flips it to `request-completed`. Art. 17(3)(b)-(e) is the ground for it.
	 *
	 * Nothing derived from the address is kept — no hash, keyed or otherwise. A
	 * salted but deterministic HMAC of an email is still pseudonymous personal
	 * data, re-identifiable by anyone holding the salt and a candidate address,
	 * so storing one would contradict the erasure this function performs. It is
	 * also unnecessary: DELETING `_dsar_email` is precisely what takes the
	 * record out of the `_dsar_email = $email` match set, which is what keeps
	 * the drain loop terminating and makes a second erasure for the same
	 * address a no-op.
	 *
	 * @since 1.5.0
	 * @since 1.34.1 DSAR records are redacted in place instead of force-deleted.
	 * @param string $email_address The user's email address.
	 * @param int    $page          Page number for batched erasures.
	 * @return array Erasure result conforming to the WP privacy eraser format.
	 */
	function faz_privacy_eraser( $email_address, $page = 1 ) {
		$email          = sanitize_email( (string) $email_address );
		$page           = max( 1, (int) $page );
		$per_page       = 20;
		$items_removed  = 0;
		$items_retained = false;
		$messages       = array();
		$done           = true;

		// Consent logs use one-way IP hashing and cannot be linked to an email,
		// so there is nothing to erase there (they auto-purge by retention). DSAR
		// form submissions DO carry the requester's email and free text, so they
		// are erasable on request.
		$messages[] = __( 'FAZ Cookie Manager consent logs use anonymized IP hashes and cannot be linked to email addresses; they auto-purge after the configured retention period.', 'faz-cookie-manager' );

		if ( is_email( $email ) && post_type_exists( 'faz_dsar' ) ) {
			// IMPORTANT: always read the FIRST batch, never `paged => $page`.
			// WordPress calls the eraser repeatedly, incrementing $page, but each
			// call here removes the records it finds FROM THE MATCH SET — so the
			// set shrinks underneath the offset. Paging forward (page 2, 3, …)
			// would skip the records that slid into the earlier offsets and leave
			// them behind (e.g. 25 records / 20-per-page → page 1 handles 20,
			// page 2 offsets past the remaining 5 and reports done, orphaning
			// them). Reading page 1 every time drains the set deterministically.
			//
			// Since the records are redacted rather than deleted, what keeps the
			// set shrinking is DELETING `_dsar_email` below: the meta_query is
			// `_dsar_email = $email`, so a redacted record no longer matches and
			// `$found` falls under `$per_page` exactly as it did when the records
			// were destroyed. Do NOT turn this into a skip or an exemption that
			// leaves `_dsar_email` in place: `$found` would stick at `$per_page`,
			// `$done` would never be true, and core's erasure AJAX loop would run
			// unbounded — a hang on the administrator's erase screen, worse than
			// the problem being fixed.
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
			$found      = count( $query->posts );
			$redacted   = 0;
			$unanswered = 0;
			$deleted    = 0;
			foreach ( $query->posts as $post_id ) {
				$post_id = (int) $post_id;

				/**
				 * Whether to keep a dated, non-identifying record of an erased request.
				 *
				 * True (the default) redacts the stored request in place: the
				 * name, the address and the free-text message go, the right
				 * invoked and the submission date stay, so the controller can
				 * still show that the request was received and answered.
				 * Returning false restores the pre-1.34.1 behaviour and force-
				 * deletes the record, audit trail included.
				 *
				 * @since 1.34.1
				 * @param bool   $retain  Default true.
				 * @param int    $post_id DSAR record ID.
				 * @param string $email   Address being erased.
				 */
				if ( ! apply_filters( 'faz_dsar_erasure_retain_record', true, $post_id, $email ) ) {
					if ( wp_delete_post( $post_id, true ) ) {
						$deleted++;
						$items_removed++;
					}
					continue;
				}

				// Read before redacting: the status says whether this request had
				// been answered, the type and date survive into the new title.
				$status = (string) get_post_meta( $post_id, '_dsar_status', true );
				$type   = (string) get_post_meta( $post_id, '_dsar_type', true );

				delete_post_meta( $post_id, '_dsar_name' );
				delete_post_meta( $post_id, '_dsar_message' );
				// Last of the three, and the one that matters to the loop above:
				// this is what removes the record from the match set.
				delete_post_meta( $post_id, '_dsar_email' );
				update_post_meta( $post_id, '_dsar_status', 'erased' );
				update_post_meta( $post_id, '_dsar_erased_at', current_time( 'mysql', true ) );

				// The title was `[<Label>] <name> — <date>`: the requester's name
				// is personal data and must not survive on the list screen or in
				// the posts table. The right invoked and the date do survive, so
				// the screen stays usable. post_content and the excerpt need no
				// treatment — DSAR_Shortcode::store_request() writes neither.
				$label = class_exists( '\FazCookie\Includes\DSAR_Shortcode' )
					? \FazCookie\Includes\DSAR_Shortcode::get_type_label( $type )
					: $type;
				$date  = substr( (string) get_post_field( 'post_date', $post_id ), 0, 10 );
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => '' !== $date
							? sprintf( '[%s] %s', $label, $date )
							: sprintf( '[%s]', $label ),
						// The slug too, and not as a nicety: store_request()
						// never sets post_name, and `private` is not one of the
						// statuses wp_insert_post() skips slug generation for, so
						// every stored request carries a post_name derived from
						// the old title — `erasure-jane-doe-2026-06-16`. Blanking
						// it here makes wp_insert_post() regenerate it from the
						// redacted title; leaving it would have kept the
						// requester's name in wp_posts after an "erasure".
						'post_name'  => '',
					)
				);

				$redacted++;
				$items_removed++;
				$items_retained = true;
				if ( ! in_array( $status, array( 'completed', 'erased' ), true ) ) {
					$unanswered++;
				}
			}

			if ( $redacted > 0 ) {
				$messages[] = sprintf(
					/* translators: %d: number of privacy-request records redacted. */
					_n(
						'Erased the personal data in %d FAZ Cookie Manager privacy request. A dated record of the request itself — the right invoked and the date, with no name, address or message — is kept to demonstrate compliance (GDPR Art. 5(2) accountability, Art. 17(3)(b)-(e)), the same position WordPress takes by keeping its own privacy-request records.',
						'Erased the personal data in %d FAZ Cookie Manager privacy requests. A dated record of each request itself — the right invoked and the date, with no name, address or message — is kept to demonstrate compliance (GDPR Art. 5(2) accountability, Art. 17(3)(b)-(e)), the same position WordPress takes by keeping its own privacy-request records.',
						$redacted,
						'faz-cookie-manager'
					),
					$redacted
				);
				if ( $unanswered > 0 ) {
					// An erasure matches the ADDRESS, not one request. Every
					// stored request from this address is redacted, including an
					// access or rectification request still waiting for an
					// answer — skipping those is not available (it would leave
					// their `_dsar_email` in place and break the drain above), so
					// the administrator is told instead of finding out later.
					$messages[] = sprintf(
						/* translators: %d: number of erased privacy requests that had not been answered yet. */
						_n(
							'%d of those requests had not been answered yet: an erasure matches the email address rather than a single request, so every stored request from this address was erased — including rights that were still awaiting a reply.',
							'%d of those requests had not been answered yet: an erasure matches the email address rather than a single request, so every stored request from this address was erased — including rights that were still awaiting a reply.',
							$unanswered,
							'faz-cookie-manager'
						),
						$unanswered
					);
				}
			}
			if ( $deleted > 0 ) {
				/* translators: %d: number of privacy-request records deleted. */
				$messages[] = sprintf( _n( 'Deleted %d FAZ Cookie Manager privacy request.', 'Deleted %d FAZ Cookie Manager privacy requests.', $deleted, 'faz-cookie-manager' ), $deleted );
			}
			if ( $redacted > 0 && class_exists( '\FazCookie\Includes\DSAR_Shortcode' ) ) {
				// A redaction changes `_dsar_status` without a post transition or
				// a delete, so neither `transition_post_status` nor
				// `before_delete_post` fires: without this the menu badge keeps
				// counting a request that is no longer pending for up to five
				// minutes, disagreeing with the list screen next to it.
				\FazCookie\Includes\DSAR_Shortcode::flush_pending_count();
			}
			// Not done while this batch was full — there may be more to drain on
			// the next call (which again reads the new first page).
			$done = $found < $per_page;
		}

		return array(
			'items_removed'  => $items_removed,
			// True once a record has been redacted rather than destroyed. Core
			// shows this to the administrator and in the completion email, so
			// reporting false while keeping a record would be the inverse of the
			// accountability problem this redaction solves.
			'items_retained' => $items_retained,
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
