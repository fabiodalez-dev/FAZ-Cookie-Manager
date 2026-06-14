<?php
/**
 * Runtime geo-routing helpers.
 *
 * Shared, mostly-pure helpers that apply a resolved geo-routing ruleset to the
 * live banner when the opt-in `faz_geo_ruleset_runtime` filter is enabled
 * (default off → catalogue-only behaviour, zero change for existing installs).
 *
 * Consumed by both the server render (FazCookie\Frontend\Frontend) and the REST
 * language-swap endpoint (FazCookie\Frontend\Modules\Banner_Rest\Banner_Rest) so
 * the two never diverge. Every method except resolve_for_country() is a pure
 * function of its arguments, which keeps them trivially unit-testable without a
 * WordPress runtime.
 *
 * @package FazCookie\Frontend\Includes
 */

namespace FazCookie\Frontend\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Geo_Runtime
 */
class Geo_Runtime {

	/**
	 * Per-request memo of resolved rulesets, keyed by the country used to
	 * resolve them. `false` as a value means "resolved to none" (so we never
	 * re-resolve), distinct from an absent key ("not yet resolved").
	 *
	 * @var array<string,array|false>
	 */
	private static $ruleset_memo = array();

	/**
	 * Whether the runtime geo-routing flag is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		// 1.18.2 HOTFIX: the runtime application of geo rulesets is temporarily
		// hard-disabled. A resolved jurisdiction (e.g. California, model
		// "opt-out-with-sensitive-opt-in") is mapped to a GDPR banner and the
		// ruleset's declared CCPA UI obligations — Do Not Sell link, GPC
		// handling, sensitive-data separate opt-in — are NOT yet enforced, so
		// the banner would not deliver the compliant UI the ruleset declares.
		// Re-enable once those obligations are wired (see CHANGELOG 1.18.2).
		// Catalogue-only (default) banner behaviour is unaffected by this.
		// To restore after the rework, return:
		//   (bool) apply_filters( 'faz_geo_ruleset_runtime', false );
		return false;
	}

	/**
	 * Resolve the geo ruleset for the given visitor country, or null.
	 *
	 * The country is supplied by the caller (the same value used to select the
	 * banner, so banner selection and ruleset resolution can never disagree on
	 * the country — finding #5). The region is resolved here via
	 * Geolocation::get_visitor_region() — which honours the `faz_visitor_region`
	 * filter and the GeoLite2-City subdivision lookup — and passed alongside the
	 * country so sub-national rulesets (e.g. Law 25 for CA-QC) resolve, while a
	 * region whose prefix does not match the country is discarded downstream.
	 * Returns null when the flag is off or resolution fails.
	 *
	 * @param string $country ISO 3166-1 alpha-2 country code (authoritative).
	 * @return array|null
	 */
	public static function resolve_for_country( $country ) {
		$key = is_string( $country ) ? strtoupper( $country ) : '';
		if ( array_key_exists( $key, self::$ruleset_memo ) ) {
			$memo = self::$ruleset_memo[ $key ];
			return ( false === $memo ) ? null : $memo;
		}
		// Check the flag BEFORE memoising. Writing the memo first would poison
		// the per-request cache if this runs before the `faz_geo_ruleset_runtime`
		// filter is hooked (e.g. a late-priority init): a subsequent call in the
		// same PHP process — including tests that toggle the flag — would then
		// return null from the memo even though the feature is on. When disabled
		// we deliberately do NOT memo, so a later-enabled call re-resolves.
		if ( ! self::is_enabled() ) {
			return null;
		}
		self::$ruleset_memo[ $key ] = false;

		$class = '\\FazCookie\\Admin\\Modules\\Geo_Routing\\Geo_Routing';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_instance' ) ) {
			return null;
		}
		// Resolve the sub-national region (honours faz_visitor_region) so it can
		// be passed as an authoritative override; empty when no City DB / signal.
		$region = '';
		if ( class_exists( '\\FazCookie\\Includes\\Geolocation' )
			&& method_exists( '\\FazCookie\\Includes\\Geolocation', 'get_visitor_region' ) ) {
			try {
				$region = (string) \FazCookie\Includes\Geolocation::get_visitor_region();
			} catch ( \Throwable $e ) {
				$region = '';
			}
		}
		try {
			$ctx = $class::get_instance()->get_visitor_context( null, $key, $region );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( is_array( $ctx ) && isset( $ctx['ruleset'] ) && is_array( $ctx['ruleset'] ) ) {
			self::$ruleset_memo[ $key ] = $ctx['ruleset'];
			return $ctx['ruleset'];
		}
		return null;
	}

	/**
	 * Map a ruleset `ui.default_categories` state for a slug to a boolean.
	 *
	 * @param array  $ruleset Ruleset array.
	 * @param string $slug    Category slug.
	 * @return bool|null true = granted, false = denied-until-action, null = unset.
	 */
	public static function category_default( $ruleset, $slug ) {
		$cats = ( isset( $ruleset['ui']['default_categories'] ) && is_array( $ruleset['ui']['default_categories'] ) )
			? $ruleset['ui']['default_categories'] : array();
		if ( ! isset( $cats[ $slug ] ) ) {
			return null;
		}
		$state = $cats[ $slug ];
		if ( 'granted' === $state || 'granted-locked' === $state ) {
			return true;
		}
		if ( 'denied' === $state || 'denied-until-action' === $state ) {
			return false;
		}
		return null;
	}

	/**
	 * Whether a resolved ruleset actually NAMES this category.
	 *
	 * The client uses this to decide, per category, whether its pre-consent
	 * default is jurisdiction-authoritative (came from the ruleset) or must fall
	 * back to the effective-law logic — the same split the server applies in
	 * get_blocked_categories(). Without it, a custom category absent from the
	 * ruleset would be read as ruleset-denied on the client while the server
	 * applies the (opt-out) law and runs it: client/server divergence.
	 *
	 * @param array|null $ruleset Resolved ruleset, or null.
	 * @param string     $slug    Category slug.
	 * @return bool
	 */
	public static function is_ruleset_default( $ruleset, $slug ) {
		return ( null !== $ruleset ) && ( null !== self::category_default( $ruleset, $slug ) );
	}

	/**
	 * Map a ruleset `model` to the binary law the frontend JS enforces.
	 *
	 * The JS collapses every law to `gdpr` (opt-in: deny non-necessary until
	 * action) or `ccpa` (opt-out: allow until the visitor opts out). Only the
	 * pure opt-out model maps to `ccpa`; opt-in, hybrid and
	 * opt-out-with-sensitive-opt-in all enforce as `gdpr` because their
	 * per-category `default_categories` already encode which categories are
	 * granted vs denied — the granularity lives in the defaults, not the law.
	 *
	 * @param array $ruleset Ruleset array.
	 * @return string 'gdpr' or 'ccpa'.
	 */
	public static function model_to_law( $ruleset ) {
		$model = isset( $ruleset['model'] ) ? (string) $ruleset['model'] : '';
		return ( 'opt-out' === $model ) ? 'ccpa' : 'gdpr';
	}

	/**
	 * Per-category default consent ({ gdpr, ccpa }) for the frontend store.
	 *
	 * Base behaviour (catalogue): gdpr = the category's prior-consent flag;
	 * ccpa = exempt only when necessary or neither sold nor shared. When a
	 * runtime ruleset names the category, its state wins for BOTH laws so the
	 * banner reflects the visitor's jurisdiction (necessary stays granted).
	 *
	 * Pure: takes primitives, not a Cookie_Categories object, so it is callable
	 * from any context and unit-testable without WordPress.
	 *
	 * @param array|null $ruleset       Resolved ruleset, or null for catalogue.
	 * @param string     $slug          Category slug.
	 * @param bool       $prior_consent Category prior-consent flag.
	 * @param bool       $sell          Category sells personal data.
	 * @param bool       $share         Category shares personal data.
	 * @return array{gdpr: bool, ccpa: bool}
	 */
	public static function default_consent( $ruleset, $slug, $prior_consent, $sell, $share ) {
		$gdpr = (bool) $prior_consent;
		$ccpa = ( 'necessary' === $slug || ( false === (bool) $sell && false === (bool) $share ) );

		if ( null !== $ruleset ) {
			$rs_default = self::category_default( $ruleset, $slug );
			if ( null !== $rs_default ) {
				$value = ( 'necessary' === $slug ) ? true : $rs_default;
				$gdpr  = $value;
				$ccpa  = $value;
			}
		}

		return array(
			'gdpr' => $gdpr,
			'ccpa' => $ccpa,
		);
	}

	/**
	 * Override Google Consent Mode v2 default_settings from a ruleset's CMv2.
	 *
	 * Writes the CATEGORY-MIRROR keys that gcm.js actually reads for the
	 * storage-type signals (marketing → ad_storage, analytics →
	 * analytics_storage, functional → functionality_storage AND
	 * personalization_storage, necessary → security_storage) plus the canonical
	 * ad_user_data / ad_personalization keys (which gcm.js reads directly). The
	 * canonical equivalents are also written for row consistency. gcm.js
	 * deliberately reads the mirrors (the non-personalized-ads fallback keeps
	 * `marketing` and canonical `ad_storage` out of sync), so writing the
	 * mirrors is what makes the ruleset CMv2 signals reach gtag.
	 *
	 * Limitation: gcm.js derives BOTH functionality_storage and
	 * personalization_storage from the single `functional` mirror, so they
	 * cannot be set independently here. The more-restrictive of the two CMv2
	 * values wins (denied if either is denied) — the compliance-safe choice.
	 *
	 * @param array|null $ruleset      Resolved ruleset, or null (no-op).
	 * @param array      $gcm_settings GCM settings array (from Gcm_Settings::get()).
	 * @return array Possibly-modified GCM settings.
	 */
	public static function apply_cmv2_to_gcm( $ruleset, $gcm_settings ) {
		if ( null === $ruleset
			|| ! isset( $ruleset['signals']['cmv2'] ) || ! is_array( $ruleset['signals']['cmv2'] )
			|| ! isset( $gcm_settings['default_settings'] ) || ! is_array( $gcm_settings['default_settings'] ) ) {
			return $gcm_settings;
		}
		$cmv2 = $ruleset['signals']['cmv2'];
		$norm = static function ( $state ) {
			return ( 'granted' === $state || 'granted-locked' === $state ) ? 'granted' : 'denied';
		};

		// CMv2 canonical key → the gcm-row keys gcm.js reads. The mirror is the
		// load-bearing one for the storage types; the canonical is written too
		// so the stored row stays internally consistent.
		$direct = array(
			'ad_storage'        => array( 'marketing', 'ad_storage' ),
			'analytics_storage' => array( 'analytics', 'analytics_storage' ),
			'security_storage'  => array( 'necessary', 'security_storage' ),
			'ad_user_data'      => array( 'ad_user_data' ),
			'ad_personalization' => array( 'ad_personalization' ),
		);

		foreach ( $gcm_settings['default_settings'] as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $direct as $cmv2_key => $row_keys ) {
				if ( ! isset( $cmv2[ $cmv2_key ] ) ) {
					continue;
				}
				$value = $norm( $cmv2[ $cmv2_key ] );
				foreach ( $row_keys as $rk ) {
					$gcm_settings['default_settings'][ $idx ][ $rk ] = $value;
				}
			}

			// functionality_storage + personalization_storage both derive from
			// the `functional` mirror in gcm.js — set it to the more-restrictive
			// of the two CMv2 values, and mirror that onto both canonical keys.
			$func = isset( $cmv2['functionality_storage'] ) ? $norm( $cmv2['functionality_storage'] ) : null;
			$pers = isset( $cmv2['personalization_storage'] ) ? $norm( $cmv2['personalization_storage'] ) : null;
			if ( null !== $func || null !== $pers ) {
				$combined = ( 'denied' === $func || 'denied' === $pers ) ? 'denied' : 'granted';
				$gcm_settings['default_settings'][ $idx ]['functional']              = $combined;
				$gcm_settings['default_settings'][ $idx ]['functionality_storage']   = $combined;
				$gcm_settings['default_settings'][ $idx ]['personalization_storage'] = $combined;
			}
		}
		return $gcm_settings;
	}
}
