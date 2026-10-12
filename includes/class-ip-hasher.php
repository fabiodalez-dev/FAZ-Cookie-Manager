<?php
/**
 * Shared IP hashing for rate-limiting handlers.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a salted HMAC hash of the visitor's IP address for rate-limiting
 * and audit-log keys. Used by DSAR_Shortcode and Do_Not_Sell_Shortcode.
 */
trait IP_Hasher {

	/**
	 * Return a salted hash of the visitor's IP address.
	 *
	 * @return string 64-char hex string.
	 */
	private function hash_ip() {
		$ip = \faz_resolve_client_ip();
		if ( empty( $ip ) ) {
			// Group missing-IP requests intentionally without hashing an empty string.
			$ip = 'no-ip';
		}
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	/**
	 * Whether the hash above identifies ONE visitor.
	 *
	 * `hash_ip()` is a hash of whatever `faz_resolve_client_ip()` returned, and
	 * that is not always a visitor: behind a proxy whose forwarded header
	 * cannot be read it is the proxy's own address, and the 'no-ip' literal a
	 * few lines up groups every request that has no address at all. A key is
	 * still the right key for a lock measured in seconds; it is NOT a safe
	 * basis for a ceiling measured in hours, because one shared bucket turns a
	 * per-visitor limit into a site-wide denial. Callers that run such a
	 * ceiling must ask this first.
	 *
	 * The judgment lives in `faz_client_ip_identifies_visitor()` so that both
	 * shortcodes using this trait make it identically and from one place. When
	 * the helper is unavailable (a harness that stubs only the resolver), the
	 * historical answer is kept: apply the ceiling.
	 *
	 * @since 1.34.1
	 * @return bool
	 */
	private function ip_identifies_visitor() {
		if ( function_exists( 'faz_client_ip_identifies_visitor' ) ) {
			return (bool) \faz_client_ip_identifies_visitor();
		}
		return true;
	}

	/**
	 * Debug-only public accessor for `hash_ip()`.
	 *
	 * Exposed exclusively under `WP_DEBUG` for E2E regression coverage so
	 * tests can assert the hash is a valid SHA-256 hex string without
	 * resorting to `ReflectionMethod::setAccessible()` (which would lock
	 * the spec to the private method's visibility and silently break on
	 * any future refactor of the trait).
	 *
	 * Returns an empty string in production. Callers must check `WP_DEBUG`
	 * themselves before relying on the return value.
	 *
	 * @return string Either the 64-char hex hash, or '' when WP_DEBUG is off.
	 */
	public function debug_hash_ip() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return '';
		}
		return $this->hash_ip();
	}
}
