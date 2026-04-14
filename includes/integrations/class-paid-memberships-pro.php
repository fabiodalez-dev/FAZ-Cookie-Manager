<?php
/**
 * Paid Memberships Pro integration (Pay-or-Accept model).
 *
 * When a logged-in visitor has one of the configured PMP membership levels,
 * the cookie banner is suppressed and consent is auto-granted across all
 * categories. Non-paying visitors are unaffected and follow the standard
 * consent flow.
 *
 * Activation conditions (ALL must be true for the exemption to apply):
 *   1. PMP plugin is active (PMPRO_VERSION defined or pmpro_hasMembershipLevel() exists)
 *   2. Admin enabled the integration in Settings → Integrations
 *   3. Admin configured at least one exempt level ID
 *   4. Current visitor is logged in
 *   5. Current user has one of the configured exempt levels
 *
 * If PMP is not active, the entire integration is no-op and introduces no
 * overhead beyond a single function_exists() check per request.
 *
 * @package FazCookie\Includes\Integrations
 */

namespace FazCookie\Includes\Integrations;

use FazCookie\Admin\Modules\Settings\Includes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Paid_Memberships_Pro {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cached exemption result for the current request to avoid repeated
	 * PMP lookups (each call to pmpro_hasMembershipLevel() triggers a DB
	 * query on first call per user).
	 *
	 * @var bool|null
	 */
	private $cached_exempted = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. Called from the plugin bootstrap; safe to call even
	 * when PMP is not installed (the hooks simply short-circuit).
	 */
	public function register_hooks() {
		// Keep the consent cookie in sync with the current exemption state
		// before frontend PHP/JS and GCM/TCF read it on the page.
		add_action( 'init', array( $this, 'sync_consent_cookie' ), 5 );
	}

	/**
	 * Whether the PMP plugin is actually active on this site.
	 *
	 * @return bool
	 */
	public static function is_pmp_active() {
		return defined( 'PMPRO_VERSION' ) || function_exists( 'pmpro_hasMembershipLevel' );
	}

	/**
	 * Whether the current visitor should be exempted from the banner and
	 * auto-granted consent for all categories.
	 *
	 * @return bool
	 */
	public function is_current_user_exempted() {
		if ( null !== $this->cached_exempted ) {
			return $this->cached_exempted;
		}

		$this->cached_exempted = false;

		if ( ! self::is_pmp_active() ) {
			return false;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$settings = new Settings();
		$config   = $settings->get( 'integrations', 'paid_memberships_pro' );

		if ( empty( $config ) || ! is_array( $config ) ) {
			return false;
		}
		if ( empty( $config['enabled'] ) ) {
			return false;
		}

		$exempt_levels = isset( $config['exempt_levels'] ) && is_array( $config['exempt_levels'] )
			? array_map( 'absint', $config['exempt_levels'] )
			: array();
		$exempt_levels = array_values( array_filter( $exempt_levels ) );

		if ( empty( $exempt_levels ) ) {
			return false;
		}

		// PMP signature: pmpro_hasMembershipLevel( $level_ids, $user_id = null ).
		// Accepts an array of IDs and returns true if the user has any of them.
		if ( function_exists( 'pmpro_hasMembershipLevel' ) ) {
			$has_level = call_user_func( 'pmpro_hasMembershipLevel', $exempt_levels, get_current_user_id() );
			if ( $has_level ) {
				$this->cached_exempted = true;
			}
		}

		/**
		 * Allow third-party code to override the exemption decision. Useful for
		 * sites that combine PMP with other membership systems or need custom
		 * rules (e.g. exempt only active subscriptions, not expired ones).
		 *
		 * @param bool  $exempted       Whether to exempt the current user.
		 * @param array $exempt_levels  Configured PMP level IDs.
		 */
		$this->cached_exempted = (bool) apply_filters(
			'faz_pmp_user_exempted',
			$this->cached_exempted,
			$exempt_levels
		);

		return $this->cached_exempted;
	}

	/**
	 * Keep consent-tracking cookies aligned with the user's current PMP
	 * exemption state.
	 *
	 * Exempted members must persist an allow-all consent cookie so server-side
	 * blocking, client-side banner logic, GCM and TCF all read the same state
	 * during the current page load. Visitors who are no longer exempted must
	 * not retain a stale auto-granted cookie from a previous membership state.
	 */
	public function sync_consent_cookie() {
		$current_cookie    = function_exists( 'faz_get_valid_consent_cookie' ) ? faz_get_valid_consent_cookie() : '';
		$is_auto_granted   = function_exists( 'faz_is_auto_granted_consent_cookie' ) ? faz_is_auto_granted_consent_cookie( $current_cookie ) : false;
		$has_vendor_cookie = ! empty( $_COOKIE['fazVendorConsent'] );
		$has_tcf_cookie    = ! empty( $_COOKIE['euconsent-v2'] );

		if ( ! $this->is_current_user_exempted() ) {
			if ( $is_auto_granted && function_exists( 'faz_clear_consent_tracking_cookies' ) ) {
				faz_clear_consent_tracking_cookies();
			}
			return;
		}

		$desired_cookie = $this->build_exempted_consent_cookie_value( $current_cookie );
		$needs_refresh  = $current_cookie !== $desired_cookie || $has_vendor_cookie || $has_tcf_cookie;
		if ( ! $needs_refresh ) {
			return;
		}

		if ( function_exists( 'faz_expire_browser_cookie' ) ) {
			faz_expire_browser_cookie( 'fazVendorConsent' );
			faz_expire_browser_cookie( 'euconsent-v2' );
		}
		$this->set_consent_cookie( $desired_cookie );

		/**
		 * Fires after the PMP integration auto-grants consent for a member.
		 *
		 * @param int   $user_id     Current user ID.
		 * @param array $parts       Cookie parts that were set.
		 */
		do_action( 'faz_pmp_consent_auto_granted', get_current_user_id(), explode( ',', $desired_cookie ) );
	}

	/**
	 * Build the consent cookie value used for exempted members.
	 *
	 * @param string $existing_cookie Current valid consent cookie, if any.
	 * @return string
	 */
	private function build_exempted_consent_cookie_value( $existing_cookie = '' ) {
		$parsed     = function_exists( 'faz_parse_consent_cookie' ) ? faz_parse_consent_cookie( $existing_cookie ) : array();
		$consent_id = isset( $parsed['consentid'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $parsed['consentid'] ) : '';
		if ( '' === $consent_id ) {
			$consent_id = $this->generate_consent_id();
		}

		$categories = $this->get_category_slugs();
		$parts      = array(
			'action:yes',
			'consent:accepted',
			'consentid:' . $consent_id,
		);
		foreach ( $categories as $slug ) {
			$parts[] = $slug . ':yes';
		}

		$settings = new Settings();
		$revision = $settings->get( 'general', 'consent_revision' );
		$revision = is_numeric( $revision ) ? max( 1, absint( $revision ) ) : 1;
		$parts[]  = 'rev:' . $revision;
		$parts[]  = 'source:pmp';

		return implode( ',', $parts );
	}

	/**
	 * Persist the consent cookie for exempted members.
	 *
	 * @param string $value Cookie payload.
	 * @return void
	 */
	private function set_consent_cookie( $value ) {
		$expiry = time() + ( 180 * DAY_IN_SECONDS );
		if ( function_exists( 'faz_set_browser_cookie' ) ) {
			faz_set_browser_cookie( 'fazcookie-consent', $value, $expiry );
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie(
			'fazcookie-consent',
			$value,
			array(
				'expires'  => $expiry,
				'path'     => '/',
				'domain'   => '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE['fazcookie-consent'] = $value;
	}

	/**
	 * Cryptographically random 32-char consent ID, same format used by
	 * script.js when the visitor interacts with the banner manually.
	 *
	 * @return string
	 */
	private function generate_consent_id() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// Fallback for environments without CSPRNG.
			return wp_generate_password( 32, false, false );
		}
	}

	/**
	 * Fetch all active cookie category slugs so the auto-grant covers every
	 * category defined on the site (including admin-added custom ones).
	 *
	 * @return array
	 */
	private function get_category_slugs() {
		global $wpdb;
		$table = $wpdb->prefix . 'faz_cookie_categories';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_col( "SELECT slug FROM {$table}" );
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			// Fallback to the default GDPR category set.
			return array( 'necessary', 'analytics', 'functional', 'marketing', 'performance' );
		}
		return array_values( array_filter( array_map( 'sanitize_key', $rows ) ) );
	}
}
