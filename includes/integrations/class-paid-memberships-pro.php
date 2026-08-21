<?php
/**
 * Paid Memberships Pro integration (paid privacy alternative).
 *
 * When a logged-in visitor has one of the configured PMP membership levels,
 * the cookie banner is suppressed and a necessary-only privacy state is
 * applied. Optional purposes stay denied unless the member later changes them
 * explicitly in the preference centre. Non-paying visitors are unaffected and
 * follow the standard consent flow.
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

use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories;
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
	 * Whether the current visitor should receive the paid privacy alternative.
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
	 * Exempted members persist a necessary-only state so server-side blocking,
	 * client-side banner logic, GCM and TCF agree during the current page load.
	 * Visitors who are no longer exempted must not retain a stale PMP-managed
	 * cookie from a previous membership state.
	 */
	public function sync_consent_cookie() {
		$current_cookie    = function_exists( 'faz_get_valid_consent_cookie' ) ? faz_get_valid_consent_cookie() : '';
		$is_pmp_managed    = function_exists( 'faz_is_pmp_managed_consent_cookie' ) ? faz_is_pmp_managed_consent_cookie( $current_cookie ) : false;
		$has_vendor_cookie = ! empty( $_COOKIE['fazVendorConsent'] );
		$has_tcf_cookie    = ! empty( $_COOKIE['euconsent-v2'] );
		$is_exempted       = $this->is_current_user_exempted();

		if ( ! $is_exempted ) {
			// Tear down PMP-sourced consent tracking for a member who is no
			// longer exempt. Two PMP-specific triggers, EITHER of which is safe:
			//
			//   1. The main consent cookie is still PMP-managed — it carries
			//      `source:pmp` ($is_pmp_managed). Clear the whole
			//      consent state.
			//   2. The `fazVendorSource=pmp` companion marker is present. It is
			//      set ONLY for exempt members (see the exempt branch below) and
			//      outlives the main consent cookie, so it covers the ex-member
			//      whose PMP-managed `fazcookie-consent` has already expired
			//      ($is_pmp_managed is false) but who still carries residual
			//      `fazVendorConsent` / `euconsent-v2` from the exempt period.
			//
			// Crucially, a STANDARD visitor never receives `source:pmp` nor the
			// marker, so neither trigger can ever wipe their own, legitimately
			// self-set vendor/TCF cookies — which is what previously forced the
			// conservative `$is_pmp_managed`-only check (clearing on the mere
			// presence of vendor cookies would loop: clear → banner → re-accept
			// → vendor cookie re-created → clear → …). The marker breaks that
			// tie without re-introducing the loop.
			$pmp_vendor_marker = isset( $_COOKIE['fazVendorSource'] ) && 'pmp' === $_COOKIE['fazVendorSource'];
			if ( $is_pmp_managed && function_exists( 'faz_clear_consent_tracking_cookies' ) ) {
				faz_clear_consent_tracking_cookies();
			}
			if ( ( $is_pmp_managed || $pmp_vendor_marker ) && function_exists( 'faz_expire_browser_cookie' ) ) {
				faz_expire_browser_cookie( 'fazVendorConsent' );
				faz_expire_browser_cookie( 'euconsent-v2' );
				faz_expire_browser_cookie( 'fazVendorSource' );
				unset( $_COOKIE['fazVendorConsent'], $_COOKIE['euconsent-v2'], $_COOKIE['fazVendorSource'] );
			}
			return;
		}

		// Revocation support (members can change or withdraw their consent).
		// If the current cookie is valid and was NOT automatically applied by this
		// integration (it carries no `source:pmp`) yet records an explicit
		// `action:yes`, the member opened the preference center and made their
		// own decision. Honour it — do NOT overwrite with the necessary-only
		// default — so a member who later enables, say, functional storage keeps
		// that choice
		// across page loads instead of having it silently re-granted on the next
		// request. Manual saves drop the `source:pmp` marker automatically
		// (script.js never loads `source` into the consent store, so re-
		// serialising the cookie on a user action omits it), which is exactly
		// what distinguishes a self-made choice from our managed default here.
		//
		// This makes the paid privacy alternative a revocable default rather
		// than a forced state: the member can still grant or withdraw each
		// optional purpose through the ordinary preference centre.
		if ( '' !== $current_cookie && ! $is_pmp_managed ) {
			$parsed_current = function_exists( 'faz_parse_consent_cookie' )
				? faz_parse_consent_cookie( $current_cookie )
				: array();
			if ( isset( $parsed_current['action'] ) && 'yes' === $parsed_current['action'] ) {
				return;
			}
		}

		$desired_cookie = $this->build_exempted_privacy_cookie_value();
		$needs_refresh  = $current_cookie !== $desired_cookie || $has_vendor_cookie || $has_tcf_cookie;
		if ( ! $needs_refresh ) {
			return;
		}

		// A "new privacy state" is a first application, a stale/expired state, or
		// conversion of the historical allow-all PMP cookie. The exact desired
		// value is stable, so this logs once and cannot flood on page reloads.
		$is_new_state = ( '' === $current_cookie || ( $is_pmp_managed && $current_cookie !== $desired_cookie ) );

		if ( function_exists( 'faz_expire_browser_cookie' ) ) {
			faz_expire_browser_cookie( 'fazVendorConsent' );
			faz_expire_browser_cookie( 'euconsent-v2' );
		}
		// Companion marker: flag that this member's vendor/TCF state is
		// PMP-managed. It lets the non-exempt branch above safely clear any
		// residual fazVendorConsent / euconsent-v2 once the member loses
		// exemption — including after the main consent cookie has expired — WITHOUT
		// risking a standard visitor's own cookies (they never receive this
		// marker). The 365-day TTL deliberately outlives the 180-day consent
		// cookie so the marker is still around to trigger that cleanup. It holds
		// no personal data — only the literal string "pmp".
		if ( function_exists( 'faz_set_browser_cookie' ) ) {
			faz_set_browser_cookie( 'fazVendorSource', 'pmp', time() + ( 365 * DAY_IN_SECONDS ) );
			$_COOKIE['fazVendorSource'] = 'pmp';
		}
		$this->set_consent_cookie( $desired_cookie );

		// Accountability: record the automatic necessary-only decision as a
		// rejection, with an audit-only meta marker that distinguishes it from a
		// banner click without polluting the dashboard's consent-status totals.
		if ( $is_new_state ) {
			$this->log_pmp_privacy_state( $desired_cookie );
		}

		/**
		 * Fires after the PMP integration applies its necessary-only privacy state.
		 *
		 * @param int   $user_id     Current user ID.
		 * @param array $parts       Cookie parts that were set.
		 */
		do_action( 'faz_pmp_privacy_applied', get_current_user_id(), explode( ',', $desired_cookie ) );
		// Backward compatibility for integrations listening to the historical,
		// inaccurately named hook. The payload now represents necessary-only state.
		do_action_deprecated(
			'faz_pmp_consent_auto_granted',
			array( get_current_user_id(), explode( ',', $desired_cookie ) ),
			'1.27.0',
			'faz_pmp_privacy_applied'
		);
	}

	/**
	 * Write a consent-log row for a PMP necessary-only state. It is a rejected
	 * decision for aggregate reporting, with meta.pmp_privacy=yes preserving the
	 * source in the accountability record.
	 *
	 * @param string $cookie_value The PMP-managed privacy cookie that was set.
	 * @return void
	 */
	private function log_pmp_privacy_state( $cookie_value ) {
		$settings = new Settings();
		if ( true !== $settings->get( 'consent_logs', 'status' ) ) {
			return;
		}
		$controller = '\FazCookie\Admin\Modules\Consentlogs\Includes\Controller';
		if ( ! class_exists( $controller ) ) {
			return;
		}

		$parsed     = function_exists( 'faz_parse_consent_cookie' ) ? faz_parse_consent_cookie( $cookie_value ) : array();
		$consent_id = isset( $parsed['consentid'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $parsed['consentid'] ) : '';
		// Carry the policy revision baked into the cookie (set by
		// build_exempted_privacy_cookie_value) so the audit trail records the
		// revision actually applied instead of the controller's default of 1.
		$revision   = isset( $parsed['rev'] ) ? max( 1, absint( $parsed['rev'] ) ) : 1;

		// Reduce the parsed cookie to category states only (drop meta + scope +
		// per-service keys), so the logged `categories` blob mirrors what an
		// explicit consent record would store.
		$meta       = array( 'action' => 1, 'consent' => 1, 'consentid' => 1, 'rev' => 1, 'source' => 1, 'gpc' => 1 );
		$categories = array();
		if ( is_array( $parsed ) ) {
			foreach ( $parsed as $key => $value ) {
				if ( isset( $meta[ $key ] ) ) {
					continue;
				}
				if ( 0 === strpos( $key, '__scope.' ) || 0 === strpos( $key, 'svc.' ) ) {
					continue;
				}
				$categories[ $key ] = $value;
			}
		}
		$categories['meta.pmp_privacy'] = 'yes';

		call_user_func(
			array( $controller::get_instance(), 'log_consent' ),
			array(
				'consent_id'      => $consent_id,
				'status'          => 'rejected',
				'categories'      => $categories,
				'url'             => '',
				'policy_revision' => $revision,
			)
		);
	}

	/**
	 * Build the necessary-only cookie value used for exempted members.
	 *
	 * @return string
	 */
	private function build_exempted_privacy_cookie_value() {
		$categories = $this->get_category_states();
		$parts      = array(
			// `auto` is deliberately truthy so the hidden banner stays closed, but
			// is not `yes`: getFazConsent().isUserActionCompleted and cross-domain
			// forwarding must not represent a server-applied privacy default as a
			// visitor action.
			'action:auto',
			'consent:no',
		);
		foreach ( $categories as $slug => $state ) {
			$parts[] = $slug . ':' . $state;
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

		// httponly=false / secure=is_ssl() are REQUIRED by design and are NOT a
		// security weakness here: `fazcookie-consent` holds only opt-in/opt-out
		// booleans (no session token, no auth secret) and MUST be readable by
		// the banner JS, which gates all downstream tracking on it. `secure` is
		// already set to is_ssl() so it is marked Secure on every HTTPS site.
		// Same contract — and same justification — as faz_set_browser_cookie().
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( // nosemgrep
			'fazcookie-consent',
			$value,
			array( // nosemgrep
				'expires'  => $expiry,
				'path'     => '/',
				'domain'   => '',
				'secure'   => is_ssl(), // nosemgrep
				'httponly' => false, // nosemgrep
				'samesite' => 'Lax',
			)
		);
		$_COOKIE['fazcookie-consent'] = $value;
	}

	/**
	 * Fetch every visible category and produce its privacy-default state.
	 * Only the canonical necessary category remains available; every optional
	 * category is denied, including custom categories configured as preactive.
	 *
	 * @return array
	 */
	private function get_category_states() {
		$categories = Category_Controller::get_instance()->get_items();
		$states     = array();
		foreach ( $categories as $category_data ) {
			$category = new Cookie_Categories( $category_data );
			// Skip categories the banner itself would hide: invisible
			// categories and the `wordpress-internal` bucket (wp-settings-*,
			// wordpress_logged_in_*, etc.). Those cookies are set by WP for
			// admin/auth purposes and never appear in the frontend consent
			// UI, so declaring consent for them in a visitor-facing cookie
			// would be noise at best and a compliance mismatch at worst.
			// Mirrors Frontend::get_cookie_groups().
			if ( false === $category->get_visibility() ) {
				continue;
			}
			if ( 'wordpress-internal' === $category->get_slug() ) {
				continue;
			}
			$slug = sanitize_key( $category->get_slug() );
			if ( '' !== $slug ) {
				$states[ $slug ] = ( 'necessary' === $slug ) ? 'yes' : 'no';
			}
		}
		if ( empty( $states ) ) {
			// Fallback to the default GDPR category set.
			return array(
				'necessary'   => 'yes',
				'analytics'   => 'no',
				'functional'  => 'no',
				'marketing'   => 'no',
				'performance' => 'no',
			);
		}
		return $states;
	}
}
