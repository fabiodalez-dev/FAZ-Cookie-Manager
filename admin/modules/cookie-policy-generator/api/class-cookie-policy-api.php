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
				'permission_callback' => array( $this, 'check_admin_read' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_settings' ),
				'permission_callback' => array( $this, 'check_admin_write' ),
			),
		) );

		register_rest_route( $ns, "/{$base}/preview", array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'preview' ),
			'permission_callback' => array( $this, 'check_admin_write' ),
		) );

		// GET /cookie-policy/suggest-services — auto-detect third-party
		// services from cookie-scanner domains. Surfaced via the
		// "Auto-detect from cookie scan" button in the Third-party
		// services tab of the Cookie Policy admin page; deferred-save
		// UX so the admin reviews the pre-ticked boxes and clicks Save.
		register_rest_route( $ns, "/{$base}/suggest-services", array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'suggest_services' ),
			'permission_callback' => array( $this, 'check_admin_read' ),
		) );

		// GET /cookie-policy/detected-services — same scan-derived list
		// as /suggest-services minus the already_selected/newly_suggested
		// partition. Rendered as a "Detected" badge next to each service
		// the scanner has seen, so the admin understands WHY auto-detect
		// would tick a given box even before clicking it. Cheap by design:
		// the renderServicesList() call needs only the set.
		register_rest_route( $ns, "/{$base}/detected-services", array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'detected_services' ),
			'permission_callback' => array( $this, 'check_admin_read' ),
		) );
	}

	/**
	 * Permission callback for read-only endpoints (GET).
	 *
	 * Only enforces `manage_options` — no `wp_rest` nonce check. WordPress
	 * REST convention is that nonces are CSRF protection for browser-cookie
	 * sessions; Application Passwords, WP-CLI, and OAuth callers
	 * authenticate via Basic Auth / app password tokens and DO NOT carry
	 * an `X-WP-Nonce` header. Requiring nonce on GET would 403 every
	 * legitimate programmatic admin read.
	 *
	 * @param WP_REST_Request $request
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
	 * `manage_options` + `wp_rest` nonce — the nonce is the CSRF gate for
	 * browser-cookie admin sessions. Non-browser callers (Application
	 * Passwords, WP-CLI) should authenticate via Basic Auth, which
	 * WordPress core treats as a separate authenticated flow that
	 * bypasses the nonce requirement at the cookie layer.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_admin_write( $request ) {
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
	 * Back-compat alias — kept for any external code that hooked the old
	 * single permission callback. New endpoints SHOULD use check_admin_read
	 * (for GET) or check_admin_write (for POST/PUT/PATCH/DELETE) directly.
	 *
	 * @deprecated 1.16.1 Use check_admin_read or check_admin_write.
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_admin( $request ) {
		return $this->check_admin_write( $request );
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
	 * Note: company.name / company.email return EMPTY strings deliberately
	 * — they used to be seeded with get_option('blogname') / get_option(
	 * 'admin_email'), but that meant the first Save round-trip persisted
	 * `admin_email` into faz_cookie_policy_data and the public-facing
	 * `[faz_cookie_policy_complete]` shortcode then published the WP admin
	 * email as the controller contact, even when the operator never
	 * explicitly entered it. The admin email in particular is PII (often a
	 * real person's inbox) and must not be published as a public contact
	 * until the operator types it into the form. UX prefill (showing the
	 * admin a sensible suggestion) should happen JS-side via placeholders,
	 * never via persisted defaults. Matches the parallel fix already
	 * applied to `Renderer::baseline_defaults()`.
	 *
	 * @return array
	 */
	private function default_settings() {
		return array(
			'jurisdiction'         => 'gdpr-strict',
			'default_lang'         => '',
			'company'              => array(
				'name'     => '',
				'address'  => '',
				'email'    => '',
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
			// Admin-editable disclaimer (1.16.2). `show=true` + empty `text`
			// reproduces the pre-1.16.2 behaviour: standard FAZ disclaimer in
			// the active language. Set `show=false` to hide entirely.
			'disclaimer'           => array(
				'show' => true,
				'text' => '',
			),
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

		// Allowlist of recognised third-party services. Kept FLAT here for
		// O(N) intersect; the JS groups them by category for UI purposes.
		// SYNC with includes/class-renderer.php::build_services_list() AND
		// admin/assets/js/pages/cookie-policy.js renderServicesList() AND
		// admin/class-admin.php fazConfig.i18n.cookiePolicy.svc* keys.
		$allowed_services = array(
			// Analytics
			'ga4', 'gtm', 'matomo', 'plausible', 'mixpanel', 'amplitude', 'heap', 'fathom', 'statcounter',
			// Heatmaps / session recording
			'hotjar', 'clarity', 'mouseflow', 'smartlook', 'luckyorange', 'fullstory', 'logrocket', 'crazyegg',
			// Advertising pixels
			'gads', 'meta', 'tiktok', 'linkedin', 'msuet', 'twitter', 'pinterest', 'reddit', 'snap', 'quora', 'outbrain', 'taboola', 'criteo',
			// CDN / edge / performance
			'cf', 'fastly', 'akamai', 'cloudfront', 'bunnycdn', 'jsdelivr',
			// Anti-bot / forms
			'recaptcha', 'hcaptcha', 'turnstile', 'akismet',
			// Maps / embeds / media
			'gmaps', 'mapbox', 'osm', 'youtube', 'vimeo', 'twitterembed', 'instagram', 'spotify', 'soundcloud', 'wistia', 'brightcove', 'jwplayer',
			// Chat / support
			'intercom', 'zendesk', 'crisp', 'livechat', 'tawk', 'drift', 'hubspotchat', 'tidio',
			// Email / marketing automation
			'mailchimp', 'activecampaign', 'convertkit', 'hubspot', 'brevo', 'klaviyo', 'pardot', 'marketo', 'adobe',
			// Payments / commerce
			'stripe', 'paypal', 'square', 'shopify',
			// Social sign-in / auth
			'google_signin', 'apple_signin', 'facebook_signin', 'auth0', 'okta',
			// Error / RUM monitoring
			'sentry', 'newrelic', 'datadog', 'bugsnag', 'raygun',
			// Personalisation / A-B testing
			'optimizely', 'vwo', 'convert', 'abtasty',
			// Push notifications
			'onesignal', 'pushwoosh', 'fcm',
		);
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

		if ( isset( $in['disclaimer'] ) && is_array( $in['disclaimer'] ) ) {
			$d = $in['disclaimer'];
			// `show` is the visibility toggle. Default true preserves the
			// pre-1.16.2 behaviour for installs that submit a payload
			// without the field. Accept truthy strings ('1','true','on')
			// because some form serialisations stringify booleans.
			if ( array_key_exists( 'show', $d ) ) {
				$raw = $d['show'];
				if ( is_bool( $raw ) ) {
					$out['disclaimer']['show'] = $raw;
				} elseif ( is_string( $raw ) ) {
					$out['disclaimer']['show'] = in_array( strtolower( $raw ), array( '1', 'true', 'on', 'yes' ), true );
				} else {
					$out['disclaimer']['show'] = (bool) $raw;
				}
			}
			// `text`: when non-empty replaces the standard FAZ disclaimer.
			// 4000 chars is generous enough for a legal-style block but
			// keeps the payload bounded. wp_kses_post at render time
			// strips dangerous markup; here we just length-cap and
			// preserve newlines.
			if ( array_key_exists( 'text', $d ) ) {
				$out['disclaimer']['text'] = $this->trim_clip_multiline( (string) $d['text'], 4000 );
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
	 * GET /cookie-policy/suggest-services — return services whose tracking
	 * domains were observed by the cookie scanner, partitioned against
	 * what the admin has already declared in faz_cookie_policy_data.
	 *
	 * Response shape (mirrors /faz/v1/gvl/suggest for parity):
	 *   {
	 *     "service_ids":      ["gads", "tiktok", "youtube"],   // ALL matches
	 *     "already_selected": ["gads"],                         // intersect with saved
	 *     "newly_suggested":  ["tiktok", "youtube"],            // matches NOT yet saved
	 *     "scan_available":   true
	 *   }
	 *
	 * Read-only — never mutates the saved selection. The admin reviews
	 * the pre-ticked checkboxes in the Third-party services tab and
	 * commits via Save (deferred-save UX).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function suggest_services( WP_REST_Request $request ) {
		unset( $request );
		$scan      = $this->scan_discovered_services();
		$matched   = $scan['service_ids'];
		$saved     = (array) get_option( self::OPTION, array() );
		$selected  = isset( $saved['third_party_services'] ) && is_array( $saved['third_party_services'] )
			? array_values( array_unique( array_map( 'strval', $saved['third_party_services'] ) ) )
			: array();

		$already_selected = array_values( array_intersect( $matched, $selected ) );
		$newly_suggested  = array_values( array_diff( $matched, $selected ) );
		// Sort both partitions for stable, deterministic output — matches the
		// GVL sibling \FazCookie\Admin\Modules\Gvl\Api::suggest_from_cookies(),
		// which sorts already_selected and newly_suggested the same way.
		sort( $already_selected );
		sort( $newly_suggested );

		return new WP_REST_Response( array(
			'service_ids'      => $matched,
			'already_selected' => $already_selected,
			'newly_suggested'  => $newly_suggested,
			'scan_available'   => $scan['scan_available'],
		), 200 );
	}

	/**
	 * GET /cookie-policy/detected-services — bare-list shape for the
	 * "Detected" badge rendered next to each checkbox in the Third-party
	 * services tab. Same scan logic as suggest_services() but stripped
	 * of the already/newly partition — the JS only needs the set.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function detected_services( WP_REST_Request $request ) {
		unset( $request );
		$scan = $this->scan_discovered_services();
		return new WP_REST_Response( array(
			'service_ids'    => $scan['service_ids'],
			'scan_available' => $scan['scan_available'],
		), 200 );
	}

	/**
	 * Core scan helper. Reads every scanner-discovered cookie domain from
	 * wp_faz_cookies in a SINGLE query and derives BOTH the matched service
	 * IDs and the scan_available flag from that one result set, so the two
	 * can never disagree.
	 *
	 * Previously this was split across two queries — a SELECT DISTINCT for
	 * the service IDs and a separate COUNT for scan_available. A concurrent
	 * delete landing between them could return scan_available=true with an
	 * empty service_ids list (or the reverse), mis-routing the UI hint
	 * ("no matches found" vs "run the cookie scanner first"). One query
	 * closes that TOCTOU window.
	 *
	 * scan_available is true when the result set has any row: a
	 * blank-domain discovered row still appears as a '' entry, so a
	 * non-empty set is equivalent to the old COUNT(*) WHERE discovered=1 > 0.
	 * Only the non-blank domains are matched against the bundled
	 * domain → service-ID map. Scanner-discovered rows only (discovered=1):
	 * manually-added cookies (discovered=0) are admin curation, not real
	 * network traffic. Mirrors \FazCookie\Includes\Gvl::
	 * suggest_vendor_ids_from_scanned_cookies() and shares its dot-prefix
	 * suffix-guard so '.notgoogle.com' cannot trick a match against
	 * 'google.com'. Matches are filtered against the API allowlist (see
	 * sanitize_settings) so a stale JSON-map entry can never inject a
	 * service ID the generator does not recognise.
	 *
	 * @return array{service_ids: string[], scan_available: bool}
	 */
	private function scan_discovered_services() {
		global $wpdb;
		// Whitelist-sanitise the table identifier. It is server-derived from the
		// WP prefix (no user input), but stripping anything outside [A-Za-z0-9_]
		// makes the interpolated query below provably injection-safe in code,
		// not just by convention. ($wpdb->prepare()'s %i identifier placeholder
		// would be cleaner but needs WP 6.2+; this plugin keeps Requires at least 5.0.)
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix . 'faz_cookies' );
		if ( ! self::table_exists( $table ) ) {
			return array( 'service_ids' => array(), 'scan_available' => false );
		}
		// Note: no `domain <> ''` filter here (unlike the per-output split it
		// replaces) — a blank-domain discovered row must still count toward
		// scan_available, and the matching loop skips blank domains anyway.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$domains        = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT domain FROM `{$table}` WHERE discovered = %d", 1 ) );
		$scan_available = ! empty( $domains );
		if ( ! $scan_available ) {
			return array( 'service_ids' => array(), 'scan_available' => false );
		}

		$map = self::load_domain_service_map();
		if ( empty( $map ) ) {
			return array( 'service_ids' => array(), 'scan_available' => true );
		}

		$map_keys = array_keys( $map );
		$matched  = array();
		foreach ( $domains as $raw_domain ) {
			$d = strtolower( ltrim( (string) $raw_domain, '.' ) );
			if ( '' === $d ) {
				continue;
			}
			// Exact match first.
			if ( isset( $map[ $d ] ) ) {
				foreach ( (array) $map[ $d ] as $sid ) {
					$matched[ (string) $sid ] = true;
				}
				continue;
			}
			// Suffix match with dot-prefix guard. `m.linkedin.com` ends with
			// `.linkedin.com` and matches; `notlinkedin.com` ends with
			// `tlinkedin.com` and correctly does NOT match.
			foreach ( $map_keys as $key ) {
				if ( substr( $d, -( strlen( $key ) + 1 ) ) === '.' . $key ) {
					foreach ( (array) $map[ $key ] as $sid ) {
						$matched[ (string) $sid ] = true;
					}
				}
			}
		}

		if ( empty( $matched ) ) {
			return array( 'service_ids' => array(), 'scan_available' => true );
		}

		// Reuse sanitize_settings' allowlist as the single source of truth.
		// A stale or PR-mismatched entry in the JSON map cannot inject an
		// unknown service ID into the UI — sanitize the suggested set the
		// same way a /settings POST would sanitize an incoming payload.
		$pruned = $this->sanitize_settings( array( 'third_party_services' => array_keys( $matched ) ) );
		$ids    = isset( $pruned['third_party_services'] ) ? (array) $pruned['third_party_services'] : array();
		sort( $ids );
		return array( 'service_ids' => $ids, 'scan_available' => true );
	}

	/**
	 * SHOW TABLES LIKE existence probe, cached per request keyed by table
	 * name. Called by scan_discovered_services() (which both the suggest
	 * and detected callbacks share) so repeated calls on the same admin
	 * page request don't re-run the round-trip.
	 *
	 * @param string $table Fully-qualified table name (with prefix).
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$cache[ $table ] = ( $exists === $table );
		return $cache[ $table ];
	}

	/**
	 * Load the bundled domain → service-ID map. Schema mirrors the GVL
	 * domain-to-vendor.json so both loaders share the same shape
	 * (`{ "mappings": { "<domain>": ["<sid>", …] } }`). Cached per request
	 * via a static — cold reads cost microseconds; this avoids the
	 * suggest + detected pair on the same admin page hitting the disk
	 * twice.
	 *
	 * @return array<string, string[]>
	 */
	private static function load_domain_service_map() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$file = dirname( __DIR__ ) . '/data/domain-to-service.json';
		if ( ! is_readable( $file ) ) {
			$cached = array();
			return $cached;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin-shipped JSON data file, not user input.
		$json    = (string) file_get_contents( $file );
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['mappings'] ) || ! is_array( $decoded['mappings'] ) ) {
			$cached = array();
			return $cached;
		}
		$out = array();
		foreach ( $decoded['mappings'] as $domain => $services ) {
			if ( ! is_string( $domain ) || '' === $domain || ! is_array( $services ) ) {
				continue;
			}
			$sids = array();
			foreach ( $services as $s ) {
				if ( is_string( $s ) && '' !== $s ) {
					$sids[] = sanitize_text_field( $s );
				}
			}
			if ( ! empty( $sids ) ) {
				$out[ strtolower( $domain ) ] = $sids;
			}
		}
		$cached = $out;
		return $cached;
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
