<?php
/**
 * Class Geo_Api file — REST endpoints for geo-routing admin UI.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-05
 * Task: T087 + T089 (P6 Admin UI)
 *
 * Endpoints under faz/v1/geo/*:
 *   GET    /geo/rulesets        — list all available ruleset IDs
 *   GET    /geo/rulesets/{id}   — get one ruleset config
 *   GET    /geo/overrides       — list per-country admin overrides
 *   POST   /geo/overrides       — set / replace overrides
 *   DELETE /geo/overrides/{country} — remove one
 *   POST   /geo/preview         — dry-run resolve {country, region} → ruleset
 *   GET    /geo/status          — pipeline diagnostics (ipinfo configured, etc.)
 *
 * Constitution XI — manage_options gated + nonce verified.
 *
 * @package FazCookie\Admin\Modules\Geo_Routing\Api
 * @since   1.15.0
 */

namespace FazCookie\Admin\Modules\Geo_Routing\Api;

use FazCookie\Admin\Modules\Geo_Routing\Geo_Routing;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Ruleset_Loader;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Ruleset_Resolver;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Ipinfo_Client;
use FazCookie\Admin\Modules\Geo_Routing\Includes\Secrets;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API for geo-routing admin UI.
 *
 * @class    Geo_Api
 * @since    1.15.0
 */
class Geo_Api {

	const NAMESPACE = 'faz/v1';
	const BASE      = 'geo';

	/**
	 * Singleton.
	 *
	 * @var Geo_Api|null
	 */
	private static $instance = null;

	/**
	 * @return Geo_Api
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook into rest_api_init.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns   = self::NAMESPACE;
		$base = self::BASE;

		register_rest_route( $ns, "/{$base}/rulesets", array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_rulesets' ),
			'permission_callback' => array( $this, 'check_admin_read' ),
		) );

		register_rest_route( $ns, "/{$base}/rulesets/(?P<id>[a-z0-9-]+)", array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_ruleset' ),
			'permission_callback' => array( $this, 'check_admin_read' ),
			'args'                => array(
				'id' => array(
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( $ns, "/{$base}/overrides", array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_overrides' ),
				'permission_callback' => array( $this, 'check_admin_read' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_overrides' ),
				'permission_callback' => array( $this, 'check_admin_write' ),
			),
		) );

		// L2-SP1-S007 fix (1.15.0): widen regex to accept both
		// uppercase and lowercase country codes; the handler normalizes
		// via strtoupper(). Eliminates the asymmetry where POST
		// /overrides accepted {it: {...}} (case-coerced) but DELETE
		// /overrides/it returned 404.
		register_rest_route( $ns, "/{$base}/overrides/(?P<country>[A-Za-z]{2})", array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_override' ),
			'permission_callback' => array( $this, 'check_admin_write' ),
		) );

		register_rest_route( $ns, "/{$base}/preview", array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'preview' ),
			'permission_callback' => array( $this, 'check_admin_write' ),
		) );

		register_rest_route( $ns, "/{$base}/status", array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'status' ),
			'permission_callback' => array( $this, 'check_admin_read' ),
		) );

		register_rest_route( $ns, "/{$base}/ipinfo-settings", array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_ipinfo_settings' ),
				'permission_callback' => array( $this, 'check_admin_read' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_ipinfo_settings' ),
				'permission_callback' => array( $this, 'check_admin_write' ),
			),
		) );

		register_rest_route( $ns, "/{$base}/pipl-attestation", array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_pipl_attestation' ),
				'permission_callback' => array( $this, 'check_admin_read' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_pipl_attestation' ),
				'permission_callback' => array( $this, 'check_admin_write' ),
			),
		) );
	}

	/**
	 * Permission callback for read-only endpoints (GET).
	 *
	 * Only enforces `manage_options` — no `wp_rest` nonce check. WordPress
	 * REST convention is that nonces are CSRF protection for browser-cookie
	 * sessions; Application Passwords, WP-CLI, and OAuth callers DO NOT
	 * carry `X-WP-Nonce` and would otherwise 403 on every legitimate read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_admin_read( $request ) {
		unset( $request );
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Admin capability required.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission callback for mutating endpoints (POST / PUT / PATCH / DELETE).
	 *
	 * `manage_options` + `wp_rest` nonce (CSRF gate for browser-cookie
	 * sessions). Non-browser callers should authenticate via Basic Auth
	 * with an Application Password, which WordPress core treats as a
	 * separately-authenticated flow bypassing the cookie-layer nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_admin_write( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Admin capability required.', array( 'status' => 403 ) );
		}
		// Delegate nonce verification to the project helper to honour the
		// internal REST contract (consistent WP_Error code/message shape with
		// every other write endpoint under faz/v1/*). faz_verify_nonce()
		// reads X-WP-Nonce from the request and returns true or a 403 WP_Error.
		if ( function_exists( 'faz_verify_nonce' ) ) {
			$check = faz_verify_nonce( $request );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		} else {
			// Defensive fallback for the unlikely case the helper isn't loaded.
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error( 'invalid_nonce', 'Invalid nonce.', array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/**
	 * Back-compat alias — kept for any external code that hooked the old
	 * single permission callback. New endpoints SHOULD use the typed
	 * variants directly.
	 *
	 * @deprecated 1.16.1 Use check_admin_read or check_admin_write.
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_admin( $request ) {
		return $this->check_admin_write( $request );
	}

	/**
	 * GET /rulesets — list all ruleset IDs + display names.
	 */
	public function list_rulesets() {
		$loader = Ruleset_Loader::get_instance();
		$ids    = $loader->list_all();
		$out    = array();
		foreach ( $ids as $id ) {
			$r = $loader->load_ruleset( $id );
			if ( null === $r ) {
				continue;
			}
			$out[] = array(
				'id'           => $r['id'],
				'display_name' => $r['display_name'],
				'version'      => $r['version'],
				'applies_to'   => $r['applies_to'],
				'native_lang'  => $r['native_lang'],
				'model'        => $r['model'],
			);
		}
		return new WP_REST_Response( array( 'rulesets' => $out ), 200 );
	}

	/**
	 * GET /rulesets/{id} — full ruleset payload.
	 */
	public function get_ruleset( $request ) {
		$id = (string) $request->get_param( 'id' );
		$r  = Ruleset_Loader::get_instance()->load_ruleset( $id );
		if ( null === $r ) {
			return new WP_Error( 'not_found', 'Ruleset not found.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $r, 200 );
	}

	/**
	 * GET /overrides — current per-country override map.
	 */
	public function get_overrides() {
		$overrides = (array) get_option( 'faz_geo_admin_overrides', array() );
		return new WP_REST_Response( array( 'overrides' => $overrides ), 200 );
	}

	/**
	 * POST /overrides — replace the entire override map.
	 *
	 * Request body shape (Q3 resolution 2026-05-20):
	 *   {
	 *     "overrides": {
	 *       "<country_code>": {
	 *         "ruleset_id": "..." | null,
	 *         "delta": { "<dot.notation.path>": <value>, ... }
	 *       }
	 *     }
	 *   }
	 */
	public function set_overrides( WP_REST_Request $request ) {
		$body      = $request->get_json_params();
		$overrides = isset( $body['overrides'] ) ? (array) $body['overrides'] : array();
		$sanitized = $this->sanitize_overrides( $overrides );
		update_option( 'faz_geo_admin_overrides', $sanitized, false );
		return new WP_REST_Response( array( 'overrides' => $sanitized ), 200 );
	}

	/**
	 * DELETE /overrides/{country} — remove a single country override.
	 */
	public function delete_override( WP_REST_Request $request ) {
		$country   = strtoupper( (string) $request->get_param( 'country' ) );
		$overrides = (array) get_option( 'faz_geo_admin_overrides', array() );
		if ( isset( $overrides[ $country ] ) ) {
			unset( $overrides[ $country ] );
			update_option( 'faz_geo_admin_overrides', $overrides, false );
		}
		return new WP_REST_Response( array( 'overrides' => $overrides ), 200 );
	}

	/**
	 * POST /preview — dry-run resolve {country, region} → ruleset.
	 *
	 * Lightly rate-limited per admin user: at most 60 calls/min. The
	 * endpoint is gated by manage_options + nonce so the only realistic
	 * abuse vector is a compromised admin session or a buggy/rogue
	 * filter on cron — but the call does ruleset JSON load + schema
	 * validation + resolve, so a tight loop is non-trivial. The window
	 * is purely a guardrail against accidental call-storm patterns.
	 */
	public function preview( WP_REST_Request $request ) {
		// Per-user transient counter, 60s window.
		$user_id  = get_current_user_id();
		$rate_key = 'faz_geo_preview_rate_' . ( $user_id > 0 ? $user_id : 'anon' );
		$count    = (int) get_transient( $rate_key );
		if ( $count >= 60 ) {
			return new WP_Error(
				'rate_limited',
				'Too many preview requests (max 60/min).',
				array( 'status' => 429 )
			);
		}
		set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

		$body    = $request->get_json_params();
		$country = isset( $body['country'] ) ? (string) $body['country'] : '';
		$region  = isset( $body['region'] ) ? (string) $body['region'] : '';
		$vpn     = ! empty( $body['vpn'] );

		// L1-SP1-S007 fix (1.15.0): normalize country/region BEFORE
		// echoing them back in the response. Eliminates any payload-
		// reflection path — if a future UI rendering reads `input.country`
		// into a DOM attribute, the value is guaranteed to be `^[A-Z]{2}$`
		// shape (or empty), not arbitrary user input.
		$country = strtoupper( trim( $country ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = '';
		}
		$region = strtoupper( trim( $region ) );
		if ( ! preg_match( '/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $region ) ) {
			$region = '';
		}

		$loader     = Ruleset_Loader::get_instance();
		$overrides  = (array) get_option( 'faz_geo_admin_overrides', array() );
		$ruleset_id = Ruleset_Resolver::resolve(
			$country,
			$region,
			$vpn,
			$overrides,
			$loader->load_index(),
			$loader->load_us_regions(),
			$loader->get_fallback_id(),
			null,
			$loader->load_regions()
		);

		$ruleset = $loader->load_ruleset( $ruleset_id );

		return new WP_REST_Response( array(
			'input'      => array( 'country' => $country, 'region' => $region, 'vpn' => $vpn ),
			'ruleset_id' => $ruleset_id,
			'ruleset'    => $ruleset,
		), 200 );
	}

	/**
	 * GET /status — pipeline diagnostics.
	 */
	public function status() {
		$ipinfo  = new Ipinfo_Client();
		$migrate = '\\FazCookie\\Includes\\Migration_V2';
		$status  = array(
			'ipinfo' => array(
				'optin'       => $ipinfo->is_optin_active(),
				'key_present' => '' !== $ipinfo->get_api_key(),
			),
			'migration' => array(
				'complete'         => class_exists( $migrate ) ? $migrate::is_complete() : false,
				'pending_columns'  => (array) get_option( 'faz_geo_v2_migration_pending', array() ),
				'disabled_reason'  => (string) get_option( 'faz_geo_v2_disabled_reason', '' ),
			),
			'catalog' => array(
				'rulesets_count' => count( Ruleset_Loader::get_instance()->list_all() ),
				'fallback_id'    => Ruleset_Loader::get_instance()->get_fallback_id(),
			),
			// Whether the resolved ruleset is actually applied to the live banner
			// per visitor. Currently hard-off (1.18.2 hotfix) until the per-
			// jurisdiction UI obligations (Do Not Sell link, GPC, sensitive
			// separate opt-in) are wired; the catalogue is preview/reference only.
			'runtime' => array(
				'applied' => \FazCookie\Frontend\Includes\Geo_Runtime::is_enabled(),
			),
		);
		return new WP_REST_Response( $status, 200 );
	}

	/**
	 * GET /ipinfo-settings.
	 */
	public function get_ipinfo_settings() {
		$client = new Ipinfo_Client();
		return new WP_REST_Response( array(
			'optin'        => $client->is_optin_active(),
			'key_present'  => '' !== $client->get_api_key(),
			'attested_at'  => (int) get_option( 'faz_geo_ipinfo_optin_confirmed_at', 0 ),
		), 200 );
	}

	/**
	 * POST /ipinfo-settings — { optin, api_key (optional, will be encrypted), attestation }.
	 */
	public function set_ipinfo_settings( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$optin = ! empty( $body['optin'] );
		$attestation_ok = ! empty( $body['attestation_dpf_scc'] );

		if ( $optin && ! $attestation_ok ) {
			return new WP_Error( 'attestation_required', 'You must attest to DPF/SCC compliance before enabling ipinfo.', array( 'status' => 400 ) );
		}

		// Pre-validate the API key (if present) BEFORE persisting any option.
		// Previously `faz_geo_ipinfo_optin` was written before the key length
		// and encryption checks, so a 400/500 response left admin state
		// out of sync with the persisted opt-in flag.
		$encrypted_key   = null;
		$clear_key       = false;
		if ( isset( $body['api_key'] ) ) {
			$key = (string) $body['api_key'];
			if ( '' === $key ) {
				$clear_key = true;
			} else {
				// Bound the input: wp_options.option_value is LONGTEXT and would
				// happily store 64 KB of payload; legitimate ipinfo tokens are
				// 14-32 alphanumeric chars. 512 is a generous ceiling for any
				// future provider that uses long opaque tokens.
				if ( strlen( $key ) > 512 ) {
					return new WP_Error( 'invalid_api_key', 'API key too long (max 512 chars).', array( 'status' => 400 ) );
				}
				// Strip control / whitespace characters that can't possibly belong
				// in an opaque API token but could otherwise corrupt the encrypted
				// payload after XOR.
				$key_clean = preg_replace( '/[\x00-\x1F\x7F]/', '', $key );
				$encrypted = Secrets::encrypt( $key_clean );
				if ( '' === $encrypted ) {
					return new WP_Error( 'encryption_unavailable', 'Could not encrypt the API key (wp_salt unavailable).', array( 'status' => 500 ) );
				}
				$encrypted_key = $encrypted;
			}
		}

		// All validations passed — persist atomically.
		update_option( 'faz_geo_ipinfo_optin', $optin, false );

		if ( $clear_key ) {
			delete_option( 'faz_geo_ipinfo_api_key' );
		} elseif ( null !== $encrypted_key ) {
			update_option( 'faz_geo_ipinfo_api_key', $encrypted_key, false );
		}

		if ( $optin && $attestation_ok ) {
			update_option( 'faz_geo_ipinfo_optin_confirmed_at', time(), false );
		}

		return new WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/**
	 * GET /pipl-attestation. Returns the current state + the audit log of
	 * past attestation transitions (append-only).
	 */
	public function get_pipl_attestation() {
		$data = (array) get_option( 'faz_geo_pipl_cross_border_attested', array() );
		$log  = (array) get_option( 'faz_geo_pipl_attestation_log', array() );

		// Sanitize the audit log for JSON output — drop any malformed
		// entries instead of trusting raw option content downstream.
		$sanitized_log = array();
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$sanitized_log[] = array(
				'attested'  => ! empty( $entry['attested'] ),
				'timestamp' => (int) ( $entry['timestamp'] ?? 0 ),
				'user_id'   => (int) ( $entry['user_id'] ?? 0 ),
			);
		}

		return new WP_REST_Response( array(
			'attested'   => ! empty( $data['attested'] ),
			'timestamp'  => (int) ( $data['timestamp'] ?? 0 ),
			'user_id'    => (int) ( $data['user_id'] ?? 0 ),
			'audit_log'  => $sanitized_log,
		), 200 );
	}

	/**
	 * POST /pipl-attestation — { attested: bool }.
	 *
	 * The "current" state lives in `faz_geo_pipl_cross_border_attested`.
	 * Every state transition (true→false OR false→true) is also appended
	 * to `faz_geo_pipl_attestation_log` so the audit trail for cross-border
	 * data transfer obligations (PIPL Art. 38–43, GDPR Art. 30) survives
	 * revocations. The log is capped at 200 entries; older entries roll
	 * off (this is per-install regulatory audit history, not infinite
	 * retention).
	 */
	public function set_pipl_attestation( WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$attested = ! empty( $body['attested'] );
		$data     = array(
			'attested'  => $attested,
			'timestamp' => time(),
			'user_id'   => get_current_user_id(),
		);

		// Only append to the log when the state actually changes — avoids
		// padding the log with repeated POSTs of the same value.
		$prev = (array) get_option( 'faz_geo_pipl_cross_border_attested', array() );
		$prev_attested = ! empty( $prev['attested'] );
		if ( empty( $prev ) || $prev_attested !== $attested ) {
			$log   = (array) get_option( 'faz_geo_pipl_attestation_log', array() );
			$log[] = $data;
			// Cap at 200 entries — keep the most recent.
			if ( count( $log ) > 200 ) {
				$log = array_slice( $log, -200 );
			}
			update_option( 'faz_geo_pipl_attestation_log', $log, false );
		}

		update_option( 'faz_geo_pipl_cross_border_attested', $data, false );
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Sanitize the overrides payload.
	 *
	 * @param array $overrides Raw input.
	 * @return array Sanitized.
	 */
	private function sanitize_overrides( $overrides ) {
		$loader = Ruleset_Loader::get_instance();
		$valid_rulesets = $loader->list_all();
		$out = array();
		foreach ( $overrides as $country => $config ) {
			$country = strtoupper( (string) $country );
			if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
				continue;
			}
			$entry = array(
				'ruleset_id' => null,
				'delta'      => array(),
			);
			if ( isset( $config['ruleset_id'] ) && is_string( $config['ruleset_id'] ) ) {
				$rid = $config['ruleset_id'];
				if ( '' !== $rid && in_array( $rid, $valid_rulesets, true ) ) {
					$entry['ruleset_id'] = $rid;
				}
			}
			if ( isset( $config['delta'] ) && is_array( $config['delta'] ) ) {
				foreach ( $config['delta'] as $path => $value ) {
					if ( ! is_string( $path ) ) {
						continue;
					}
					// L1-SP1-S005 fix (1.15.0): normalize to lowercase
					// BEFORE the regex, then enforce case-sensitive
					// pattern. Otherwise paths like 'Signals.CmV2'
					// would pass validation but silently no-op at
					// runtime (ruleset keys are all-lowercase).
					$path = strtolower( $path );
					if ( ! preg_match( '/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)*$/', $path ) ) {
						continue;
					}
					// Only scalar values allowed in delta.
					if ( is_scalar( $value ) || is_null( $value ) ) {
						$entry['delta'][ $path ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
					}
				}
			}
			$out[ $country ] = $entry;
		}
		return $out;
	}
}
