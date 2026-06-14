<?php
/**
 * Class Ruleset_Loader file — lazy loader of jurisdictional rule-sets.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-01
 * Task: T008 (P1 Foundation)
 *
 * @package FazCookie\Admin\Modules\Geo_Routing\Includes
 */

namespace FazCookie\Admin\Modules\Geo_Routing\Includes;

use FazCookie\Admin\Modules\Geo_Routing\Geo_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lazy loader of ruleset JSON catalogs.
 *
 * Reads `rulesets/_index.json` once per request (cached) and exposes:
 *   - `load_index()`         → array<country_code, ruleset_id>
 *   - `load_us_regions()`    → array<region_code (US-XX), ruleset_id>
 *   - `load_ruleset($id)`    → decoded JSON ruleset (validated against schema if available)
 *   - `list_all()`           → array of ruleset IDs present in catalog
 *   - `get_fallback_id()`    → default fallback ruleset id from _index.json
 *
 * Performance contract (NFR-01): individual ruleset JSON is loaded on
 * demand, not eagerly. _index.json is small and always loaded once per
 * request; ruleset bodies use `wp_cache` 5-min TTL.
 *
 * Constitution VI Versioned Policy: each ruleset carries its `version`
 * field — the consumer checks for diff and triggers re-prompt accordingly.
 *
 * @class    Ruleset_Loader
 * @package  FazCookie\Admin\Modules\Geo_Routing\Includes
 * @since    1.15.0
 */
class Ruleset_Loader {

	/**
	 * Singleton instance.
	 *
	 * @var Ruleset_Loader|null
	 */
	private static $instance = null;

	/**
	 * Cached _index.json decoded payload (per-request).
	 *
	 * @var array|null
	 */
	private $index_cache = null;

	/**
	 * Cache group for `wp_cache_get` / `wp_cache_set`.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'faz_geo_rulesets';

	/**
	 * Cache TTL in seconds (5 minutes per NFR-01).
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * Get singleton instance.
	 *
	 * @return Ruleset_Loader
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
	private function __construct() {}

	/**
	 * Load and return the country-code → ruleset_id map from _index.json.
	 *
	 * @return array<string,string> ISO 3166-1 alpha-2 → ruleset id.
	 */
	public function load_index() {
		$this->ensure_index_loaded();
		if ( ! is_array( $this->index_cache ) || ! isset( $this->index_cache['countries'] ) ) {
			return array();
		}
		return $this->index_cache['countries'];
	}

	/**
	 * Load and return the US-region → ruleset_id map from _index.json.
	 *
	 * @return array<string,string> ISO 3166-2 (US-XX) → ruleset id.
	 */
	public function load_us_regions() {
		$this->ensure_index_loaded();
		if ( ! is_array( $this->index_cache ) || ! isset( $this->index_cache['_us_regions'] ) ) {
			return array();
		}
		// Filter out internal `_comment` key.
		$regions = $this->index_cache['_us_regions'];
		unset( $regions['_comment'] );
		return $regions;
	}

	/**
	 * Load and return the generic non-US sub-national region → ruleset_id map
	 * from _index.json (`_regions`).
	 *
	 * Parallel to load_us_regions() but for provinces/states outside the US
	 * (e.g. 'CA-QC' => 'law25-quebec'). Keys are ISO 3166-2 ('CC-RR'). Returns
	 * an empty array when the index has no `_regions` block.
	 *
	 * @return array<string,string> ISO 3166-2 → ruleset id.
	 */
	public function load_regions() {
		$this->ensure_index_loaded();
		if ( ! is_array( $this->index_cache ) || ! isset( $this->index_cache['_regions'] ) ) {
			return array();
		}
		$regions = $this->index_cache['_regions'];
		unset( $regions['_comment'] );
		return $regions;
	}

	/**
	 * Get the default fallback ruleset id (when country has no mapping).
	 *
	 * @return string Ruleset id. Defaults to 'fallback-gdpr-most-protective'.
	 */
	public function get_fallback_id() {
		$this->ensure_index_loaded();
		if ( is_array( $this->index_cache ) && ! empty( $this->index_cache['_default_fallback'] ) ) {
			return (string) $this->index_cache['_default_fallback'];
		}
		return 'fallback-gdpr-most-protective';
	}

	/**
	 * Load a single ruleset by id.
	 *
	 * Resolves `rulesets/{id}.json`, decodes, validates against schema
	 * (if schema available), and caches via `wp_cache`.
	 *
	 * @param string $id Ruleset id.
	 * @return array|null Decoded ruleset on success; null on missing file or schema violation.
	 */
	public function load_ruleset( $id ) {
		$id = $this->sanitize_id( $id );
		if ( '' === $id ) {
			return null;
		}

		$cache_key = 'ruleset_' . $id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$rulesets_dir = Geo_Routing::get_instance()->get_rulesets_dir();
		$file_path    = $rulesets_dir . $id . '.json';

		if ( ! is_readable( $file_path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin file, not remote.
		$json_raw = file_get_contents( $file_path );
		if ( false === $json_raw ) {
			return null;
		}

		$decoded = json_decode( $json_raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		if ( ! $this->validate_against_schema( $decoded ) ) {
			return null;
		}

		wp_cache_set( $cache_key, $decoded, self::CACHE_GROUP, self::CACHE_TTL );
		return $decoded;
	}

	/**
	 * List all ruleset ids present in the catalog directory.
	 *
	 * Scans `rulesets/*.json` excluding `_index.json` and `_*.json` helper files.
	 *
	 * @return array<int,string> Ruleset ids.
	 */
	public function list_all() {
		$rulesets_dir = Geo_Routing::get_instance()->get_rulesets_dir();
		$files        = glob( $rulesets_dir . '*.json' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		$ids = array();
		foreach ( $files as $file ) {
			$basename = basename( $file, '.json' );
			if ( '' !== $basename && '_' !== $basename[0] ) {
				$ids[] = $basename;
			}
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * Validate decoded ruleset against the JSON schema.
	 *
	 * Light validation (no external library): checks required top-level
	 * keys per schema. Full schema validation is performed in CI by
	 * `scripts/validate-rulesets.sh` (T003). Runtime validation here is
	 * a smoke check, not a substitute for the CI gate.
	 *
	 * @param array $ruleset Decoded ruleset.
	 * @return bool True if minimum required keys present.
	 */
	private function validate_against_schema( $ruleset ) {
		if ( ! is_array( $ruleset ) ) {
			return false;
		}
		$required = array(
			'id',
			'version',
			'display_name',
			'applies_to',
			'model',
			'native_lang',
			'official_resources_url',
			'signals',
			'ui',
			'_meta',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $ruleset ) ) {
				return false;
			}
		}
		// Sanity: id is a non-empty lowercase string.
		if ( empty( $ruleset['id'] ) || ! is_string( $ruleset['id'] ) ) {
			return false;
		}
		// Sanity: model is one of the four enums.
		$valid_models = array( 'opt-in', 'opt-out', 'hybrid', 'opt-out-with-sensitive-opt-in' );
		if ( ! in_array( $ruleset['model'], $valid_models, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Lazy-load the _index.json into the per-request cache.
	 *
	 * @return void
	 */
	private function ensure_index_loaded() {
		if ( null !== $this->index_cache ) {
			return;
		}
		$rulesets_dir = Geo_Routing::get_instance()->get_rulesets_dir();
		$index_path   = $rulesets_dir . '_index.json';
		if ( ! is_readable( $index_path ) ) {
			$this->index_cache = array();
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin file.
		$json_raw = file_get_contents( $index_path );
		if ( false === $json_raw ) {
			$this->index_cache = array();
			return;
		}
		$decoded = json_decode( $json_raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$this->index_cache = array();
			return;
		}
		$this->index_cache = is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Sanitize a ruleset id (defensive against path traversal).
	 *
	 * @param string $id Ruleset id candidate.
	 * @return string Sanitized id or empty string if invalid.
	 */
	private function sanitize_id( $id ) {
		$id = is_string( $id ) ? strtolower( $id ) : '';
		// L1-SP1-S006 fix (1.15.0): allow 2-character ids (e.g. 'eu')
		// by widening the middle quantifier from + to *. Path traversal
		// defense is preserved — the char class [a-z0-9-] still excludes
		// /, ., and ..
		if ( ! preg_match( '/^[a-z][a-z0-9-]*[a-z0-9]$/', $id ) ) {
			return '';
		}
		return $id;
	}
}
