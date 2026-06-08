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
		$is_exempted       = $this->is_current_user_exempted();

		if ( ! $is_exempted ) {
			// Tear down PMP-sourced consent tracking for a member who is no
			// longer exempt. Two PMP-specific triggers, EITHER of which is safe:
			//
			//   1. The main consent cookie is still the PMP auto-grant — it
			//      carries `source:pmp` ($is_auto_granted). Clear the whole
			//      consent state.
			//   2. The `fazVendorSource=pmp` companion marker is present. It is
			//      set ONLY for exempt members (see the exempt branch below) and
			//      outlives the main consent cookie, so it covers the ex-member
			//      whose auto-granted `fazcookie-consent` has already expired
			//      ($is_auto_granted is false) but who still carries residual
			//      `fazVendorConsent` / `euconsent-v2` from the exempt period.
			//
			// Crucially, a STANDARD visitor never receives `source:pmp` nor the
			// marker, so neither trigger can ever wipe their own, legitimately
			// self-set vendor/TCF cookies — which is what previously forced the
			// conservative `$is_auto_granted`-only check (clearing on the mere
			// presence of vendor cookies would loop: clear → banner → re-accept
			// → vendor cookie re-created → clear → …). The marker breaks that
			// tie without re-introducing the loop.
			$pmp_vendor_marker = isset( $_COOKIE['fazVendorSource'] ) && 'pmp' === $_COOKIE['fazVendorSource'];
			if ( $is_auto_granted && function_exists( 'faz_clear_consent_tracking_cookies' ) ) {
				faz_clear_consent_tracking_cookies();
			}
			if ( ( $is_auto_granted || $pmp_vendor_marker ) && function_exists( 'faz_expire_browser_cookie' ) ) {
				faz_expire_browser_cookie( 'fazVendorConsent' );
				faz_expire_browser_cookie( 'euconsent-v2' );
				faz_expire_browser_cookie( 'fazVendorSource' );
				unset( $_COOKIE['fazVendorConsent'], $_COOKIE['euconsent-v2'], $_COOKIE['fazVendorSource'] );
			}
			return;
		}

		// Revocation support (members can change or withdraw their consent).
		// If the current cookie is valid and was NOT auto-granted by this
		// integration (it carries no `source:pmp`) yet records an explicit
		// `action:yes`, the member opened the preference center and made their
		// own decision. Honour it — do NOT overwrite with the all-categories
		// auto-grant — so a member who rejects, say, marketing keeps that choice
		// across page loads instead of having it silently re-granted on the next
		// request. Manual saves drop the `source:pmp` marker automatically
		// (script.js never loads `source` into the consent store, so re-
		// serialising the cookie on a user action omits it), which is exactly
		// what distinguishes a self-made choice from our auto-grant here.
		//
		// This is what makes the "pay-or-accept" exemption a revocable DEFAULT
		// rather than an irrevocable, forced all-consent state — the lawful
		// basis for the initial auto-grant is the site owner's to decide, but
		// the member must always be able to override it.
		if ( '' !== $current_cookie && ! $is_auto_granted ) {
			$parsed_current = function_exists( 'faz_parse_consent_cookie' )
				? faz_parse_consent_cookie( $current_cookie )
				: array();
			if ( isset( $parsed_current['action'] ) && 'yes' === $parsed_current['action'] ) {
				return;
			}
		}

		$desired_cookie = $this->build_exempted_consent_cookie_value( $current_cookie );
		$needs_refresh  = $current_cookie !== $desired_cookie || $has_vendor_cookie || $has_tcf_cookie;
		if ( ! $needs_refresh ) {
			return;
		}

		// A "new grant" is one where there was no prior valid consent cookie for
		// this member (first exemption, or the previous one expired / was a stale
		// revision). sync_consent_cookie() runs on every page load, but only
		// reaches this write when the cookie actually needs refreshing — gating
		// the audit-log write on a new grant keeps the per-pageload sync from
		// flooding the consent log with duplicate rows for the same membership.
		$is_new_grant = ( '' === $current_cookie );

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

		// Accountability: record the auto-grant in the consent log under a
		// DISTINCT status ('pmp_grant') so it is never conflated with an
		// explicit, freely-given consent. The lawful basis for this grant is the
		// site owner's pay-or-accept / contract decision, NOT a clear affirmative
		// action by the member — the log must reflect that. (The cookie already
		// carries the matching `source:pmp` marker.)
		if ( $is_new_grant ) {
			$this->log_pmp_grant( $desired_cookie );
		}

		/**
		 * Fires after the PMP integration auto-grants consent for a member.
		 *
		 * @param int   $user_id     Current user ID.
		 * @param array $parts       Cookie parts that were set.
		 */
		do_action( 'faz_pmp_consent_auto_granted', get_current_user_id(), explode( ',', $desired_cookie ) );
	}

	/**
	 * Write a consent-log row for a PMP auto-grant, tagged with the distinct
	 * `pmp_grant` status so the audit trail distinguishes membership-basis
	 * grants from explicit consent. No-op when consent logging is disabled or
	 * the consent-log controller is unavailable.
	 *
	 * @param string $cookie_value The auto-granted consent cookie that was set.
	 * @return void
	 */
	private function log_pmp_grant( $cookie_value ) {
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
		// build_exempted_consent_cookie_value) so the audit trail records the
		// revision actually granted instead of the controller's default of 1.
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

		call_user_func(
			array( $controller::get_instance(), 'log_consent' ),
			array(
				'consent_id'      => $consent_id,
				'status'          => 'pmp_grant',
				'categories'      => $categories,
				'url'             => '',
				'policy_revision' => $revision,
			)
		);
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
		// IMPORTANT: `consent:yes` is the only token recognized by script.js
		// (_fazUnblock guard at ~line 1400) and by the CCPA opt-out checkbox
		// (~line 2217). Using any other string here — e.g. `consent:accepted`,
		// which would look equally valid to a human reader — would leave every
		// `data-faz-tag` script blocked on the client side even though our
		// server-side output buffer has already rewritten them, meaning
		// PMP-exempt members would never actually see analytics / marketing
		// scripts fire. Keep this aligned with frontend/js/script.js.
		$parts      = array(
			'action:yes',
			'consent:yes',
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
		$categories = Category_Controller::get_instance()->get_items();
		$slugs      = array();
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
			$slugs[] = $category->get_slug();
		}
		$slugs = array_values( array_filter( array_map( 'sanitize_key', $slugs ) ) );
		if ( empty( $slugs ) ) {
			// Fallback to the default GDPR category set.
			return array( 'necessary', 'analytics', 'functional', 'marketing', 'performance' );
		}
		return $slugs;
	}
}
