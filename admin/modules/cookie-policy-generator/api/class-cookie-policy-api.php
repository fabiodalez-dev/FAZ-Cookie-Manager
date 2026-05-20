<?php
/**
 * Class Cookie_Policy_Api file — REST endpoints for the Cookie Policy generator admin UI.
 *
 * Spec: specs/002-cookie-policy-generator/spec.md FR-02 + US-05 (preview)
 *
 * Endpoints (faz/v1/cookie-policy/*):
 *   GET    /settings   — return current admin form data
 *   POST   /settings   — save admin form data
 *   POST   /preview    — render policy HTML without persisting (US-05)
 *
 * Constitution XI: all gated by manage_options + nonce; output escaped at
 * the Renderer boundary.
 *
 * @package FazCookie\Admin\Modules\Cookie_Policy_Generator\Api
 * @since   1.16.0
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Api;

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @class Cookie_Policy_Api
 * @since 1.16.0
 */
class Cookie_Policy_Api {

	const REST_NAMESPACE = 'faz/v1';
	const BASE           = 'cookie-policy';
	const OPTION         = 'faz_cookie_policy_data';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$ns   = self::REST_NAMESPACE;
		$base = self::BASE;

		register_rest_route( $ns, "/{$base}/settings", array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_settings' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
		) );

		register_rest_route( $ns, "/{$base}/preview", array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'preview' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );
	}

	/**
	 * manage_options + nonce.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_admin( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Admin capability required.', array( 'status' => 403 ) );
		}
		if ( function_exists( 'faz_verify_nonce' ) ) {
			$check = faz_verify_nonce( $request );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			return true;
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'invalid_nonce', 'Invalid nonce.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /cookie-policy/settings.
	 */
	public function get_settings() {
		$defaults = $this->default_settings();
		$saved    = (array) get_option( self::OPTION, array() );
		// Merge defaults so the UI never has missing keys.
		$out = array_replace_recursive( $defaults, $saved );
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * POST /cookie-policy/settings.
	 *
	 * @param WP_REST_Request $request
	 */
	public function set_settings( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$clean = $this->sanitize_settings( $body );
		update_option( self::OPTION, $clean, false );
		return new WP_REST_Response( array( 'saved' => true, 'data' => $clean ), 200 );
	}

	/**
	 * POST /cookie-policy/preview — render without persisting (US-05).
	 *
	 * Body may include `settings` (override saved values) + `lang` +
	 * `jurisdiction`. When `settings` is absent, the currently-saved
	 * faz_cookie_policy_data is used.
	 *
	 * @param WP_REST_Request $request
	 */
	public function preview( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$override = isset( $body['settings'] ) && is_array( $body['settings'] )
			? $this->sanitize_settings( $body['settings'] )
			: null;

		$filter = null;
		if ( $override ) {
			// Temporarily swap the option so Renderer reads the preview payload.
			// We use a filter — no DB write happens; pre_option_<key> short-circuits get_option.
			$filter = function ( $value ) use ( $override ) {
				return $override;
			};
			add_filter( 'pre_option_' . self::OPTION, $filter );
		}

		// try/finally so a throw inside Renderer::render() (or any filter
		// down the chain) cannot leave the temporary pre_option_ filter
		// installed for the rest of the request — that would leak the
		// preview payload into any subsequent get_option() call.
		try {
			$atts = array(
				'lang'         => isset( $body['lang'] ) ? sanitize_text_field( (string) $body['lang'] ) : '',
				'jurisdiction' => isset( $body['jurisdiction'] ) ? sanitize_text_field( (string) $body['jurisdiction'] ) : '',
			);
			$html = Renderer::render( $atts );
		} finally {
			if ( $filter ) {
				remove_filter( 'pre_option_' . self::OPTION, $filter );
			}
		}

		return new WP_REST_Response( array( 'html' => $html ), 200 );
	}

	/**
	 * Hard-coded defaults so the form never renders empty fields.
	 *
	 * @return array
	 */
	private function default_settings() {
		return array(
			'jurisdiction'         => 'gdpr-strict',
			'default_lang'         => '',
			'company'              => array(
				'name'     => (string) get_option( 'blogname', '' ),
				'address'  => '',
				'email'    => (string) get_option( 'admin_email', '' ),
				'registry' => '',
			),
			'dpo'                  => array(
				'name'    => '',
				'email'   => '',
				'address' => '',
			),
			'third_party_services' => array(),
			'retention_months'     => 12,
			'privacy_policy_url'   => '',
			'language_priority'    => array( 'en', 'it', 'fr', 'de', 'es', 'pt-BR' ),
			'section_overrides'    => array(), // section_id → free-form markdown
		);
	}

	/**
	 * Sanitize incoming admin payload — drop anything not in the schema,
	 * coerce types, enforce length caps.
	 *
	 * @param array $in Raw input.
	 * @return array Clean.
	 */
	private function sanitize_settings( array $in ) {
		$out = $this->default_settings();

		if ( isset( $in['jurisdiction'] ) && in_array( $in['jurisdiction'], Generator::JURISDICTIONS, true ) ) {
			$out['jurisdiction'] = (string) $in['jurisdiction'];
		}
		if ( isset( $in['default_lang'] ) && ( '' === $in['default_lang'] || in_array( $in['default_lang'], Generator::LANGUAGES, true ) ) ) {
			$out['default_lang'] = (string) $in['default_lang'];
		}

		$company = is_array( $in['company'] ?? null ) ? $in['company'] : array();
		$out['company']['name']     = $this->trim_clip( $company['name'] ?? '', 200 );
		$out['company']['address']  = $this->trim_clip( $company['address'] ?? '', 500 );
		$out['company']['email']    = sanitize_email( (string) ( $company['email'] ?? '' ) );
		$out['company']['registry'] = $this->trim_clip( $company['registry'] ?? '', 100 );

		$dpo = is_array( $in['dpo'] ?? null ) ? $in['dpo'] : array();
		$out['dpo']['name']    = $this->trim_clip( $dpo['name'] ?? '', 200 );
		$out['dpo']['email']   = sanitize_email( (string) ( $dpo['email'] ?? '' ) );
		$out['dpo']['address'] = $this->trim_clip( $dpo['address'] ?? '', 500 );

		$allowed_services = array( 'ga4', 'gtm', 'meta', 'tiktok', 'linkedin', 'msuet', 'clarity', 'cf', 'recaptcha', 'hotjar' );
		$services = is_array( $in['third_party_services'] ?? null ) ? $in['third_party_services'] : array();
		$out['third_party_services'] = array_values( array_intersect( $allowed_services, array_map( 'sanitize_text_field', $services ) ) );

		if ( isset( $in['retention_months'] ) ) {
			$months = (int) $in['retention_months'];
			$out['retention_months'] = max( 1, min( 120, $months ) );
		}

		if ( isset( $in['privacy_policy_url'] ) ) {
			$url = esc_url_raw( (string) $in['privacy_policy_url'] );
			if ( '' !== $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$out['privacy_policy_url'] = $url;
			}
		}

		if ( isset( $in['language_priority'] ) && is_array( $in['language_priority'] ) ) {
			$cleaned = array();
			foreach ( $in['language_priority'] as $l ) {
				if ( is_string( $l ) && in_array( $l, Generator::LANGUAGES, true ) && ! in_array( $l, $cleaned, true ) ) {
					$cleaned[] = $l;
				}
			}
			if ( ! empty( $cleaned ) ) {
				$out['language_priority'] = $cleaned;
			}
		}

		if ( isset( $in['section_overrides'] ) && is_array( $in['section_overrides'] ) ) {
			$cleaned = array();
			foreach ( $in['section_overrides'] as $k => $v ) {
				if ( ! is_string( $k ) || ! is_string( $v ) ) {
					continue;
				}
				$key = preg_replace( '/[^a-z0-9_]/', '', strtolower( $k ) );
				if ( '' === $key || strlen( $key ) > 64 ) {
					continue;
				}
				// Section overrides are markdown bodies that REPLACE a template
				// section — they need newlines, lists, paragraphs intact.
				// trim_clip() uses sanitize_text_field() which collapses
				// whitespace and strips newlines; for this field we use the
				// multiline-safe variant.
				$cleaned[ $key ] = $this->trim_clip_multiline( $v, 5000 );
			}
			$out['section_overrides'] = $cleaned;
		}

		return $out;
	}

	/**
	 * sanitize + trim + length cap. Single-line (sanitize_text_field).
	 *
	 * Multibyte-safe: uses mb_strlen / mb_substr so 200/500/5000 character
	 * caps count CHARACTERS, not bytes — important for company names with
	 * non-ASCII chars (e.g. "Société Générale" or "São Paulo Café").
	 * Without this, a byte-cap at 200 could slice a multibyte character in
	 * half and yield invalid UTF-8.
	 *
	 * @param mixed $val
	 * @param int   $max
	 * @return string
	 */
	private function trim_clip( $val, $max ) {
		if ( ! is_scalar( $val ) ) {
			return '';
		}
		$v = sanitize_text_field( (string) $val );
		$v = trim( $v );
		return self::clip_chars( $v, $max );
	}

	/**
	 * sanitize + trim + length cap, multiline-safe. Preserves newlines so
	 * markdown structure (lists, paragraphs, headings) survives.
	 *
	 * @param mixed $val
	 * @param int   $max
	 * @return string
	 */
	private function trim_clip_multiline( $val, $max ) {
		if ( ! is_scalar( $val ) ) {
			return '';
		}
		$v = sanitize_textarea_field( (string) $val );
		$v = trim( $v );
		return self::clip_chars( $v, $max );
	}

	/**
	 * UTF-8-aware length cap. Falls back to byte cap when mbstring is
	 * unavailable (very rare on modern PHP).
	 *
	 * @param string $v
	 * @param int    $max
	 * @return string
	 */
	private static function clip_chars( $v, $max ) {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $v, 'UTF-8' ) > $max ) {
				return mb_substr( $v, 0, $max, 'UTF-8' );
			}
			return $v;
		}
		if ( strlen( $v ) > $max ) {
			return substr( $v, 0, $max );
		}
		return $v;
	}
}
