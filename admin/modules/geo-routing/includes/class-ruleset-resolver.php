<?php
/**
 * Class Ruleset_Resolver file — pure-function ruleset selection.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-03
 * Task: T009 (P1 Foundation)
 *
 * @package FazCookie\Admin\Modules\Geo_Routing\Includes
 */

namespace FazCookie\Admin\Modules\Geo_Routing\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Pure-function resolver: (country, region, vpn_detected, overrides) → ruleset_id.
 *
 * NO global state, NO database, NO HTTP calls. Fully unit-testable in
 * isolation (T010). The orchestrator in P3 will inject the loader and
 * call this resolver per-request.
 *
 * Resolution priority per spec FR-03 + Q1/Q2/Q7/Q8 resolutions:
 *
 *   1. VPN/proxy detected → forced fallback (Constitution Governance
 *      most-protective). Trumps all country detection.
 *   2. Admin per-country override → if `ruleset_id` set, use it;
 *      otherwise fall through to auto-detection but apply the `delta`
 *      at merge time (handled by consumer, not resolver).
 *   3. EU/EEA/UK country → gdpr-strict (or country-specific if v2 P4
 *      catalog grew distinct rulesets like gdpr-italy / gdpr-france).
 *   4. US country with region → us-region ruleset (ccpa-california etc.)
 *      or gdpr-strict for no-law states (Q2 resolved 2026-05-19).
 *   5. Other country in _index.json → its mapped ruleset.
 *   6. Otherwise (XX, unknown, lookup failed) → fallback-gdpr-most-protective.
 *
 * @class    Ruleset_Resolver
 * @package  FazCookie\Admin\Modules\Geo_Routing\Includes
 * @since    1.15.0
 */
class Ruleset_Resolver {

	/**
	 * Resolve a ruleset id for the given context.
	 *
	 * Pure function — no side effects, deterministic for given inputs.
	 *
	 * @param string|null $country         ISO 3166-1 alpha-2 (e.g. 'IT', 'US') or null/empty/XX for unknown.
	 * @param string|null $region          ISO 3166-2 (e.g. 'US-CA') or null when no subdivision detected.
	 * @param bool        $vpn_detected    True when ipinfo flagged the IP as VPN/proxy/Tor.
	 * @param array       $admin_overrides Per-country override map. Shape: ['IT' => ['ruleset_id' => 'gdpr-italy', 'delta' => [...]], ...].
	 * @param array       $index_countries Country → ruleset_id map (from Ruleset_Loader::load_index()).
	 * @param array       $index_regions     US region → ruleset_id map (from Ruleset_Loader::load_us_regions()).
	 * @param string      $fallback_id       Default fallback ruleset id (from Ruleset_Loader::get_fallback_id()).
	 * @param array|null  $valid_ruleset_ids Whitelist of loadable ids to validate admin overrides against, or null.
	 * @param array       $index_subnational Generic non-US sub-national region → ruleset_id map (e.g. 'CA-QC' => 'law25-quebec'), from Ruleset_Loader::load_regions().
	 * @return string Resolved ruleset id.
	 */
	public static function resolve(
		$country,
		$region,
		$vpn_detected,
		$admin_overrides,
		$index_countries,
		$index_regions,
		$fallback_id = 'fallback-gdpr-most-protective',
		$valid_ruleset_ids = null,
		$index_subnational = array()
	) {
		// Stage 1: VPN trumps everything. Constitution Governance.
		// Cast defensively so CLI scripts, REST consumers or future
		// callers that pass 1 / "true" / "1" don't silently bypass the
		// gate (the public docblock advertises @param bool but PHP won't
		// enforce it at call time).
		if ( (bool) $vpn_detected ) {
			return $fallback_id;
		}

		// Normalize inputs.
		$country = self::normalize_country( $country );
		$region  = self::normalize_region( $region );

		// Stage 2: admin override by country.
		if ( '' !== $country && is_array( $admin_overrides ) && isset( $admin_overrides[ $country ] ) ) {
			$override = $admin_overrides[ $country ];
			if ( is_array( $override ) && ! empty( $override['ruleset_id'] ) ) {
				$override_id = (string) $override['ruleset_id'];
				// L2-SP1-S006 fix (1.15.0): if a whitelist of valid
				// ruleset ids is provided, validate the override
				// against it. Without this, a corrupted
				// `faz_geo_admin_overrides` option (direct DB edit,
				// restore from stale backup with a since-removed
				// ruleset, third-party plugin filter on get_option)
				// could route visitors to a missing ruleset. With it,
				// invalid overrides degrade gracefully to the next
				// resolution stage instead of producing a non-loadable
				// id (Ruleset_Loader::load_ruleset would return null
				// and Geo_Routing::get_visitor_context falls back to
				// the most-protective ruleset — same end state, but
				// now with the choice made explicit at the resolver
				// boundary rather than buried in downstream null-checks).
				if ( null === $valid_ruleset_ids || ( is_array( $valid_ruleset_ids ) && in_array( $override_id, $valid_ruleset_ids, true ) ) ) {
					return $override_id;
				}
				// Invalid override id — fall through to auto-detection.
			}
			// `delta` without explicit `ruleset_id` falls through to auto-detection;
			// the delta is applied by the consumer when materializing the ruleset config.
		}

		// Stage 3: XX / unknown / empty country → fallback.
		if ( '' === $country || 'XX' === $country ) {
			return $fallback_id;
		}

		// Stage 3.5: generic NON-US sub-national region → ruleset. Lets the
		// catalog expose province/state rulesets outside the US (e.g.
		// CA-QC → law25-quebec) without special-casing each country. The US
		// keeps its dedicated stage below (it carries the extra no-law-state
		// policy), so US is excluded here in code rather than relying on the
		// catalog never mapping a US-* key in `_regions` — a future `_index.json`
		// edit that did so must not be able to bypass the US no-law fallback.
		// Region must already be ISO 3166-2 ('CC-RR'); it is populated by the
		// GeoLite2-City subdivision lookup or a CF-Region-Code header.
		if ( 'US' !== $country && '' !== $region && is_array( $index_subnational ) && isset( $index_subnational[ $region ] ) ) {
			return (string) $index_subnational[ $region ];
		}

		// Stage 4: US with region → us-region ruleset or gdpr-strict.
		if ( 'US' === $country ) {
			if ( '' !== $region && is_array( $index_regions ) && isset( $index_regions[ $region ] ) ) {
				return (string) $index_regions[ $region ];
			}
			// US state without privacy law (Q2 2026-05-19) → most-protective.
			return self::resolve_us_no_law( $index_countries, $fallback_id );
		}

		// Stage 4b: any other country with a region-specific ruleset (e.g.
		// CA-QC → law25-quebec). A region-level regime trumps the country
		// default because it is the stricter, more specific law: without this,
		// a Quebec visitor (CA-QC) fell through to the federal PIPEDA opt-out
		// ruleset instead of Law 25's opt-in regime, silently downgrading
		// protection and leaving law25-quebec.json unreachable. Generalizes
		// region routing beyond the US so any future sub-national ruleset
		// (CA-QC, future EU regions, etc.) is honored automatically.
		if ( '' !== $region && is_array( $index_regions ) && isset( $index_regions[ $region ] ) ) {
			return (string) $index_regions[ $region ];
		}

		// Stage 5: country in index map.
		if ( is_array( $index_countries ) && isset( $index_countries[ $country ] ) ) {
			return (string) $index_countries[ $country ];
		}

		// Stage 6: default.
		return $fallback_id;
	}

	/**
	 * Determine the ruleset for US states without comprehensive privacy law.
	 *
	 * Per Q2 resolution (2026-05-19): default to gdpr-strict (most-protective).
	 *
	 * Policy decision, not a catalog lookup: a US visitor from a state
	 * without comprehensive privacy law gets the most-protective opt-in
	 * treatment regardless of what _index.json says about the US entry.
	 * Previously this depended on `$index_countries['US'] === 'us-router'`
	 * as a sentinel — if the catalog evolved (e.g. US → 'us-fallback')
	 * the resolver silently degraded to fallback-gdpr-most-protective
	 * for every unknown US state, breaking the documented Q2 behaviour
	 * without any catalog-side error. Hardcoding the policy here makes
	 * the contract explicit and the failure mode loud.
	 *
	 * @param array  $index_countries Country index map (kept for signature
	 *                                stability with callers / future hooks
	 *                                that may want to inspect catalog state).
	 * @param string $fallback_id     Hard fallback (kept for signature stability).
	 * @return string Ruleset id — always 'gdpr-strict'.
	 */
	private static function resolve_us_no_law( $index_countries, $fallback_id ) {
		unset( $index_countries, $fallback_id ); // policy constant: inputs not consulted.
		return 'gdpr-strict';
	}

	/**
	 * Normalize a country code candidate to uppercase ISO 3166-1 alpha-2.
	 *
	 * Returns empty string for invalid / null / unrecognizable input.
	 *
	 * @param string|null $country Candidate.
	 * @return string '' | 'XX' | 'IT' | 'US' | etc.
	 */
	private static function normalize_country( $country ) {
		if ( ! is_string( $country ) ) {
			return '';
		}
		$country = strtoupper( trim( $country ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return '';
		}
		return $country;
	}

	/**
	 * Normalize a region code candidate to ISO 3166-2 (e.g. 'US-CA').
	 *
	 * Returns empty string for invalid input.
	 *
	 * @param string|null $region Candidate.
	 * @return string
	 */
	private static function normalize_region( $region ) {
		if ( ! is_string( $region ) ) {
			return '';
		}
		$region = strtoupper( trim( $region ) );
		if ( ! preg_match( '/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $region ) ) {
			return '';
		}
		return $region;
	}
}
