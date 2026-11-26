<?php
/**
 * Class Geo_Routing file — module orchestrator for jurisdictional rule-sets.
 *
 * Spec: specs/001-geo-routing-next/spec.md
 * Task: T001 (P1 Foundation — module scaffolding)
 *
 * @package FazCookie\Admin\Modules\Geo_Routing
 */

namespace FazCookie\Admin\Modules\Geo_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Module orchestrator for geo-routing v2.
 *
 * Placeholder at P1. Concrete responsibilities materialize in later phases:
 *   - P2: triggers `\FazCookie\Includes\Migration_V2::run()` on activation.
 *   - P3: instantiates `Includes\Geo_Detector`, `Includes\Ipinfo_Client`,
 *         `Includes\Ruleset_Loader`, `Includes\Ruleset_Resolver`.
 *   - P6: registers admin tab and REST endpoints via `Api\Geo_Api`.
 *
 * Until then this class is intentionally inert — instantiation must be a
 * no-op so that the P1 scaffolding does not regress any of the baseline
 * 21 + 12 + 10 compliance / verification / E2E tests.
 *
 * @class    Geo_Routing
 * @package  FazCookie\Admin\Modules\Geo_Routing
 * @since    1.15.0
 */
class Geo_Routing {

	/**
	 * Singleton instance.
	 *
	 * @var Geo_Routing|null
	 */
	private static $instance = null;

	/**
	 * Plugin version this module was first introduced in.
	 *
	 * @var string
	 */
	const SINCE = '1.15.0';

	/**
	 * Return the current instance (singleton).
	 *
	 * Constitution §Stack: every module orchestrator follows the
	 * `get_instance()` singleton pattern used by Banners, Cookies,
	 * GCM, Settings, etc.
	 *
	 * @return Geo_Routing
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — singleton.
	 */
	private function __construct() {
		// Intentionally empty at P1. Wiring lands in subsequent phases.
	}

	/**
	 * Path to the rulesets catalog directory (admin/modules/geo-routing/rulesets/).
	 *
	 * Helper used by Ruleset_Loader (T008) to resolve JSON files. Lives
	 * on the orchestrator so a test or override can swap it via filter.
	 *
	 * @return string Absolute filesystem path with trailing slash.
	 */
	public function get_rulesets_dir() {
		/**
		 * Filter the rulesets catalog directory location.
		 *
		 * Default: the bundled `admin/modules/geo-routing/rulesets/`.
		 * Override to point at a custom directory (e.g. for testing or
		 * site-specific ruleset libraries).
		 *
		 * @since 1.15.0
		 * @param string $path Absolute path with trailing slash.
		 */
		return apply_filters(
			'faz_geo_rulesets_dir',
			trailingslashit( __DIR__ . '/rulesets' )
		);
	}

	/**
	 * Path to the JSON schema for ruleset validation.
	 *
	 * Used by CI validator (T003) and by Ruleset_Loader (T008) to verify
	 * each loaded `.json` matches the contract.
	 *
	 * @return string Absolute filesystem path.
	 */
	public function get_schema_path() {
		return __DIR__ . '/schemas/ruleset.schema.json';
	}

	/**
	 * Apply a dot-notation delta into the ruleset array.
	 *
	 * Used to materialize admin per-country overrides. The path is
	 * `signals.cmv2.ad_storage` style; the value replaces whatever
	 * lives at that path. Missing intermediate keys are NOT created
	 * (overrides only meaningfully change EXISTING ruleset fields).
	 *
	 * Pure function — safe to call repeatedly. Returns the modified
	 * ruleset; original is not mutated.
	 *
	 * Trace: L2-SP1-S004 fix, Q3 override application.
	 *
	 * @param array  $ruleset Source ruleset.
	 * @param string $dot_path Dot-notation key path.
	 * @param mixed  $value    Scalar / null replacement.
	 * @return array Modified ruleset.
	 */
	public static function apply_delta( $ruleset, $dot_path, $value ) {
		if ( ! is_array( $ruleset ) || '' === $dot_path ) {
			return $ruleset;
		}
		$parts = explode( '.', $dot_path );
		// Walk the ruleset path; abort if any intermediate is missing.
		$ref =& $ruleset;
		// Cache count() outside the loop condition. O(1) on PHP arrays so
		// correctness-equivalent, but PHPCS SlevomatCodingStandard flags
		// the in-condition form and Plugin Check strict mode would flag it.
		$parts_n = count( $parts );
		for ( $i = 0; $i < $parts_n - 1; $i++ ) {
			if ( ! is_array( $ref ) || ! array_key_exists( $parts[ $i ], $ref ) ) {
				return $ruleset;
			}
			$ref =& $ref[ $parts[ $i ] ];
		}
		$leaf = end( $parts );
		if ( is_array( $ref ) && array_key_exists( $leaf, $ref ) ) {
			$ref[ $leaf ] = $value;
		}
		return $ruleset;
	}

	/**
	 * Whether a rule set lets processing start before the visitor acts.
	 *
	 * @param array|null $ruleset Loaded rule set.
	 * @return bool
	 */
	private static function is_opt_out_ruleset( $ruleset ) {
		return is_array( $ruleset ) && isset( $ruleset['model'] ) && 0 === strpos( (string) $ruleset['model'], 'opt-out' );
	}

	/**
	 * The rule set that stands in for an opt-out one nobody could verify.
	 *
	 * Normally the catalogue's most-protective fallback. When that file is
	 * missing, unreadable, fails the schema, or the index names an opt-out
	 * rule set as the fallback, the built-in copy below is returned instead,
	 * so the caller always receives an opt-in rule set.
	 *
	 * A null here would NOT be the safe answer: Geo_Runtime::resolve_for_country()
	 * turns a missing rule set into "no runtime geo for this request", and the
	 * frontend, the banner REST endpoint and AMP then enforce the law of the
	 * country-selected banner. For the visitor this guards (an address that
	 * geolocates to an opt-out US state) that banner is typically the CCPA
	 * one, i.e. the opt-out regime again. An opt-in rule set is the one
	 * outcome every consumer already enforces: model_to_law() maps it to
	 * 'gdpr' and category_default() blocks each category it names.
	 *
	 * @param object $loader Ruleset_Loader instance.
	 * @return array{0: array, 1: string} Rule set and its id.
	 */
	private static function protective_ruleset( $loader ) {
		$fallback_id = $loader->get_fallback_id();
		$fallback    = $loader->load_ruleset( $fallback_id );
		if ( is_array( $fallback ) && ! self::is_opt_out_ruleset( $fallback ) ) {
			return array( $fallback, $fallback_id );
		}
		return array( self::emergency_opt_in_ruleset(), 'fallback-gdpr-most-protective' );
	}

	/**
	 * Built-in copy of what rulesets/fallback-gdpr-most-protective.json enforces.
	 *
	 * Only the fields the runtime reads (id, model, signals, ui), with the
	 * same values as the shipped file: it exists for the request where that
	 * file cannot be loaded, and must enforce what the file would have. It
	 * carries the file's id because that is what it stands for in the consent
	 * log and in the transfer-regime wording (Shortcodes::set_applied_ruleset()).
	 * tests/unit/test-ipinfo-unknown-vpn-fallback-php.php fails when the two
	 * drift apart.
	 *
	 * @return array
	 */
	private static function emergency_opt_in_ruleset() {
		return array(
			'id'      => 'fallback-gdpr-most-protective',
			'model'   => 'opt-in',
			'signals' => array(
				'tcf_v22'      => true,
				'gpc_honored'  => true,
				'gpc_required' => false,
				'dnt_honored'  => true,
				'cmv2'         => array(
					'ad_storage'              => 'denied',
					'analytics_storage'       => 'denied',
					'ad_user_data'            => 'denied',
					'ad_personalization'      => 'denied',
					'personalization_storage' => 'denied',
					'functionality_storage'   => 'denied',
					'security_storage'        => 'granted',
				),
			),
			'ui'      => array(
				'equal_weight_buttons'     => true,
				'donotsell_link_required'  => false,
				'sensitive_separate_optin' => true,
				'revisit_widget_required'  => true,
				'default_categories'       => array(
					'necessary'  => 'granted-locked',
					'functional' => 'denied',
					'analytics'  => 'denied',
					'marketing'  => 'denied',
					'profiling'  => 'denied',
				),
			),
		);
	}

	/**
	 * Get the complete visitor context: geo + ruleset + signals.
	 *
	 * Single entry point for consumers (admin UI preview, banner
	 * controller integration in P6, REST endpoints) — combines
	 * Geo_Detector::detect() + Ruleset_Resolver::resolve() +
	 * Ruleset_Loader::load_ruleset() in one call.
	 *
	 * Returns null on any internal failure (consumer falls back to
	 * the pre-v2 banner selection path — backwards-compatible).
	 *
	 * Spec: FR-02 + FR-03 + FR-06 combined.
	 * Task: T023 (P3 integration, non-invasive)
	 *
	 * @param string|null $ip_override      Optional explicit IP for testing.
	 * @param string|null $country_override Optional authoritative country code
	 *                                      (ISO 3166-1 alpha-2). When supplied it
	 *                                      overrides the detected country so the
	 *                                      caller (e.g. the runtime banner picker,
	 *                                      which already resolved the country via
	 *                                      `faz_visitor_country`) keeps banner
	 *                                      selection and ruleset resolution on the
	 *                                      SAME country.
	 * @param string|null $region_override Optional authoritative ISO 3166-2 region
	 *                                      (e.g. 'CA-QC'), typically from the
	 *                                      frontend's `faz_visitor_region`. When the
	 *                                      country is overridden the detected region
	 *                                      may belong to a DIFFERENT country, so any
	 *                                      region whose 'CC' prefix does not match the
	 *                                      resolved country is discarded — preventing
	 *                                      e.g. US + CA-QC from resolving law25-quebec.
	 * @return array|null Shape: ['country' => 'IT', 'region' => '',
	 *                            'vpn' => false, 'source' => 'cf_header',
	 *                            'vpn_unverified' => false,
	 *                            'ruleset_id' => 'gdpr-strict',
	 *                            'ruleset' => array $loaded_json_decoded].
	 *                    `vpn_unverified` is true when the ipinfo lookup was
	 *                    wanted and failed; the rule set returned is then
	 *                    never an opt-out one, per-country deltas included:
	 *                    it is replaced by the most-protective fallback, or
	 *                    by a built-in opt-in copy when that cannot be loaded.
	 */
	public function get_visitor_context( $ip_override = null, $country_override = null, $region_override = null ) {
		// Lazy autoload of the resolver chain (only when consumer calls).
		$detector_class = '\\FazCookie\\Admin\\Modules\\Geo_Routing\\Includes\\Geo_Detector';
		$loader_class   = '\\FazCookie\\Admin\\Modules\\Geo_Routing\\Includes\\Ruleset_Loader';
		$resolver_class = '\\FazCookie\\Admin\\Modules\\Geo_Routing\\Includes\\Ruleset_Resolver';

		if ( ! class_exists( $detector_class ) || ! class_exists( $loader_class ) || ! class_exists( $resolver_class ) ) {
			return null;
		}

		try {
			$detector = new $detector_class();
			$loader   = $loader_class::get_instance();

			$geo = $detector->detect( $ip_override );

			// Authoritative country override (e.g. from the frontend's
			// `faz_visitor_country`): keep banner selection and ruleset
			// resolution on the same country.
			if ( is_string( $country_override ) && 1 === preg_match( '/^[A-Za-z]{2}$/', $country_override ) ) {
				$geo['country'] = strtoupper( $country_override );
			}

			// Authoritative region override (e.g. from `faz_visitor_region`).
			if ( is_string( $region_override ) && 1 === preg_match( '/^[A-Za-z]{2}-[A-Za-z0-9]{1,3}$/', $region_override ) ) {
				$geo['region'] = strtoupper( $region_override );
			}

			// Coherence guard: a region must belong to the resolved country.
			// When the country was overridden, the detected (or overridden)
			// region can reference a different country — discard it so a
			// mismatched 'CC-RR' never reaches the sub-national resolver stage
			// (e.g. country US + region CA-QC must NOT resolve law25-quebec).
			$gc = isset( $geo['country'] ) ? strtoupper( (string) $geo['country'] ) : '';
			$gr = isset( $geo['region'] ) ? strtoupper( (string) $geo['region'] ) : '';
			if ( '' !== $gr && ( '' === $gc || strtoupper( substr( $gr, 0, 2 ) ) !== $gc ) ) {
				$geo['region'] = '';
			}

			$overrides = (array) get_option( 'faz_geo_admin_overrides', array() );

			$ruleset_id = $resolver_class::resolve(
				isset( $geo['country'] ) ? $geo['country'] : '',
				isset( $geo['region'] ) ? $geo['region'] : '',
				isset( $geo['vpn'] ) ? (bool) $geo['vpn'] : false,
				$overrides,
				$loader->load_index(),
				$loader->load_us_regions(),
				$loader->get_fallback_id(),
				// L2-SP1-S006 fix: pass the catalog ids to validate
				// admin overrides against. Invalid overrides degrade
				// to auto-detection instead of producing non-loadable
				// ruleset ids.
				$loader->list_all(),
				// Generic non-US sub-national region map (e.g. CA-QC →
				// law25-quebec), parallel to the US `_us_regions` map.
				$loader->load_regions()
			);

			$ruleset = $loader->load_ruleset( $ruleset_id );
			if ( null === $ruleset ) {
				// Catalog incomplete (typical during P4/P5 buildout) — load fallback.
				$ruleset    = $loader->load_ruleset( $loader->get_fallback_id() );
				$ruleset_id = $loader->get_fallback_id();
			}

			// The VPN lookup was wanted and could not be had: ipinfo refused,
			// was unreachable, or the hourly lookup budget is spent. The
			// resolver above was told "not a VPN", which nobody established.
			// Where that leads to an opt-in rule set nothing is lost. Where it
			// leads to an opt-out one, a visitor from an opt-in jurisdiction
			// whose VPN exits there would get a notice instead of a choice —
			// the very case the lookup exists for — so the unknown is resolved
			// the way every other geo failure is: the most-protective
			// fallback. The model test is Geo_Runtime::model_to_law()'s prefix
			// rule (every shipped opt-out rule set is
			// 'opt-out-with-sensitive-opt-in'); 'hybrid' already maps to the
			// opt-in side there.
			//
			// protective_ruleset() cannot fail: when the fallback file itself
			// cannot be loaded it answers with a built-in opt-in copy, so the
			// opt-out rule set never survives a failed replacement.
			$vpn_unverified = ! empty( $geo['vpn_unverified'] );
			if ( $vpn_unverified && self::is_opt_out_ruleset( $ruleset ) ) {
				list( $ruleset, $ruleset_id ) = self::protective_ruleset( $loader );
			}

			// L2-SP1-S004 fix (1.15.0): apply per-country delta override
			// at materialization time. Without this, admins setting
			// `signals.cmv2.ad_storage=denied` in the override editor
			// would see the value persisted but never reflected at
			// runtime — the operator intent would silently no-op.
			$country = isset( $geo['country'] ) ? $geo['country'] : '';
			if ( '' !== $country && isset( $overrides[ $country ]['delta'] ) && is_array( $overrides[ $country ]['delta'] ) && is_array( $ruleset ) ) {
				foreach ( $overrides[ $country ]['delta'] as $dot_path => $value ) {
					$ruleset = self::apply_delta( $ruleset, (string) $dot_path, $value );
				}
			}

			// The deltas run after the replacement above, and `model` is a
			// field like any other to them: a saved `model` delta would turn
			// the fallback back into an opt-out rule set (or turn an opt-in
			// country's own rule set into one). So the guarantee is checked on
			// what is actually returned. Model deltas are not refused at save
			// time instead, because they have a legitimate use (moving a
			// country to 'opt-in' while keeping the rest of its rule set), and
			// a save-time rule would not reach an option that is already
			// stored or was written without the REST sanitizer. The
			// replacement is the fallback WITHOUT the deltas: they are what
			// made the result opt-out.
			if ( $vpn_unverified && self::is_opt_out_ruleset( $ruleset ) ) {
				list( $ruleset, $ruleset_id ) = self::protective_ruleset( $loader );
			}

			return array(
				'country'    => isset( $geo['country'] ) ? $geo['country'] : 'XX',
				'region'     => isset( $geo['region'] ) ? $geo['region'] : '',
				'vpn'        => isset( $geo['vpn'] ) ? $geo['vpn'] : null,
				'source'     => isset( $geo['source'] ) ? $geo['source'] : 'unknown',
				'vpn_unverified' => $vpn_unverified,
				'ruleset_id' => $ruleset_id,
				'ruleset'    => $ruleset,
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
