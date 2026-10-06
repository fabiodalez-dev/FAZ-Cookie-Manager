<?php
/**
 * Cache Compatibility Mode — the one predicate that decides whether it applies.
 *
 * The setting has THREE states, not two, and that is the whole reason this
 * class exists:
 *
 *   off     the administrator did not ask for it.
 *   active  asked for, and in force: the render must be visitor-invariant.
 *   paused  asked for, but jurisdiction routing is enabled. Per-country
 *           enforcement cannot be served from one shared cached page, so
 *           runtime compliance wins and the optimisation stands down. The
 *           saved value is deliberately preserved — turn routing off and it
 *           resumes, with no second setting to remember and nothing to undo.
 *
 * Before this class, each consumer re-derived the answer from the raw option
 * and they did not agree. Three of the five (Frontend, Amp_Consent,
 * Banner_Rest) checked Geo_Runtime::is_enabled() and so implemented `paused`;
 * the two language seams (Translation_Compat and faz_current_language()) read
 * only the raw flag and so implemented `active`. On a multilingual install
 * with routing on and the flag saved, that split was user-visible: everything
 * else resolved per visitor, while language resolution stayed frozen on the
 * site default — the banner came out in the wrong language for no reason the
 * administrator could see. faz_current_language() even carried a comment
 * explaining it had "no access to Frontend::is_cache_compatibility_enabled()",
 * which is exactly the gap this fills: a static the procedural helpers can
 * reach.
 *
 * The two language seams ask is_shared_cache_render() rather than is_active().
 * Pausing stops the mode constraining the render; it does not stop the page
 * being cached on an install where routing has no country source, and a
 * language read from visitor state must never reach a shared cached page.
 *
 * Deliberately NOT memoised. Every caller already paid for
 * Geo_Runtime::is_enabled() on each call, the option is served from
 * WordPress's own cache, and a per-request memo would need a reset hook that
 * a future caller could forget — trading a real correctness risk for an
 * imperceptible saving.
 *
 * @since   1.34.0
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cache_Compatibility
 */
class Cache_Compatibility {

	/**
	 * The administrator never asked for it.
	 */
	const STATE_OFF = 'off';

	/**
	 * Asked for and in force.
	 */
	const STATE_ACTIVE = 'active';

	/**
	 * Asked for, but jurisdiction routing takes precedence for now.
	 */
	const STATE_PAUSED = 'paused';

	/**
	 * Whether the switch is saved on, regardless of whether it applies.
	 *
	 * This is the administrator's stored intention — the value the settings
	 * screen must keep showing while the mode is paused, so that disabling
	 * routing restores the configuration they chose rather than a default.
	 *
	 * @param array|null $settings Settings to read, or null to load them.
	 * @return bool
	 */
	public static function is_requested( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'faz_settings', array() );
		}
		return is_array( $settings ) && ! empty( $settings['banner_control']['cache_compatibility'] );
	}

	/**
	 * Which of the three states this request is in.
	 *
	 * @param array|null $settings Settings to read, or null to load them.
	 * @return string One of STATE_OFF, STATE_ACTIVE, STATE_PAUSED.
	 */
	public static function state( $settings = null ) {
		if ( ! self::is_requested( $settings ) ) {
			return self::STATE_OFF;
		}

		// A shared full-page cache cannot safely serve jurisdiction-specific
		// law, defaults and mandatory controls. The integrator filter inside
		// is_enabled() can still switch routing off entirely, which resumes
		// this mode — one switch, one direction, no third state to reconcile.
		//
		// This asks the SWITCH, not Geo_Runtime::mode(), and that is deliberate.
		// In the baseline and degraded modes no country is resolved, so one
		// fallback rule set is applied to everyone and the render is in fact
		// visitor-invariant: keeping the mode active there would be sound today
		// and would look like the tighter answer. It is the wrong contract. The
		// mode is derived from whether a GeoLite2 database works, and that flips
		// to `routing` on its own — a scheduled download completing, a licence
		// key finally resolving — with no settings save and therefore no cache
		// purge. Every page already in a full-page cache would keep serving the
		// invariant shell while the runtime had started routing per country.
		// The switch only changes when an administrator saves, which is the one
		// moment a cache flush is part of the flow.
		$routing = class_exists( '\FazCookie\Frontend\Includes\Geo_Runtime' )
			&& \FazCookie\Frontend\Includes\Geo_Runtime::is_enabled();

		return $routing ? self::STATE_PAUSED : self::STATE_ACTIVE;
	}

	/**
	 * Whether the visitor-invariance contract applies to this request.
	 *
	 * The single predicate every consumer must ask. It is true only in the
	 * `active` state: a paused mode constrains nothing.
	 *
	 * @param array|null $settings Settings to read, or null to load them.
	 * @return bool
	 */
	public static function is_active( $settings = null ) {
		return self::STATE_ACTIVE === self::state( $settings );
	}

	/**
	 * Whether a page rendered now can be served to other visitors from one
	 * shared full-page cache entry, given the saved setting.
	 *
	 * This is the question visitor-state language resolution must ask, and it
	 * is NOT the same as is_active(). A paused mode stops constraining the
	 * render, but pausing does not by itself stop the page being cached:
	 * Frontend::is_country_dependent_output() vetoes the cache for routing
	 * only when a country source exists, because without one every visitor
	 * gets the same fallback rule set and the page is invariant. So in the
	 * baseline and degraded modes a paused site still serves its pages from
	 * the cache the administrator turned this mode on for — and a language
	 * read from visitor state (WPML's ?lang= parameter, a TranslatePress or
	 * Weglot cookie) would be frozen into a page other visitors receive.
	 *
	 *   off                          false — nothing was promised.
	 *   active                       true.
	 *   paused, no country source    true  — the page is still cacheable.
	 *   paused, country source       false — the routing veto keeps the page
	 *                                        out of the shared cache, so
	 *                                        per-visitor language is safe.
	 *
	 * When the answer cannot be established (the Geolocation class is not
	 * loaded), it is true: pinning the language to the URL or the site default
	 * costs a visitor their preferred language for one view; the opposite
	 * mistake serves one visitor's language to everyone behind the cache.
	 *
	 * @param array|null $settings Settings to read, or null to load them.
	 * @return bool
	 */
	public static function is_shared_cache_render( $settings = null ) {
		$state = self::state( $settings );
		if ( self::STATE_OFF === $state ) {
			return false;
		}
		if ( self::STATE_ACTIVE === $state ) {
			return true;
		}

		if (
			! class_exists( '\FazCookie\Includes\Geolocation' )
			|| ! method_exists( '\FazCookie\Includes\Geolocation', 'has_country_source' )
		) {
			return true;
		}

		// The same predicate the Frontend veto uses, including its
		// `faz_has_country_signal_source` override, so this answer and the
		// cache headers the page is sent with cannot disagree.
		return ! \FazCookie\Includes\Geolocation::has_country_source();
	}

	/**
	 * Whether the mode was asked for but is standing down.
	 *
	 * Only the admin screens need this: the front end behaves identically for
	 * `off` and `paused`, and must not grow a third branch.
	 *
	 * @param array|null $settings Settings to read, or null to load them.
	 * @return bool
	 */
	public static function is_paused( $settings = null ) {
		return self::STATE_PAUSED === self::state( $settings );
	}
}
