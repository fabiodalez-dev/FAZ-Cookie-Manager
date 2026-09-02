<?php
/**
 * Frontend consent logger.
 *
 * Registers AJAX and REST handlers to log visitor consent from the frontend.
 *
 * @package FazCookie\Frontend\Modules\ConsentLogger
 */

namespace FazCookie\Frontend\Modules\Consent_Logger;

use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Consent Logger - handles frontend consent logging via AJAX and REST.
 *
 * @class       Consent_Logger
 * @version     3.0.0
 * @package     FazCookie
 */
class Consent_Logger {

	/**
	 * Constructor - register hooks.
	 */
	public function __construct() {
		// Public REST route.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register public REST route for consent logging.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		// `permission_callback => __return_true` is intentional. This endpoint
		// records the GDPR consent decision of an anonymous visitor — it MUST
		// be reachable without authentication, otherwise no consent could
		// ever be logged. Abuse mitigation:
		//   - Required HMAC `token` embedded in a page rendered by this site.
		//   - Same-origin request validation (Fetch Metadata / Origin / Referer).
		//   - All inputs sanitized via `sanitize_callback`.
		//   - The handler verifies both controls before any DB write.
		// See `handle_rest_consent()` for the verification logic.
		register_rest_route(
			'faz/v1',
			'/consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest_consent' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'consent_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'partial',
					),
					'categories' => array(
						'type'    => array( 'object', 'array' ),
						'default' => array(),
					),
					'url'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'banner_slug' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'policy_revision' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'tc_string' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'gpp_string' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle REST consent logging.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_rest_consent( $request ) {
		// The HMAC is intentionally cache-compatible, hence it is public to any
		// visitor who receives the page and is not a CSRF credential by itself.
		// Require a browser-provided same-origin signal before accepting it; this
		// prevents a third-party page from replaying a leaked/current token to
		// create arbitrary consent-log records in a visitor's context.
		if ( ! $this->is_same_origin_request() ) {
			return new \WP_Error(
				'cross_origin_request',
				__( 'This request must originate from this website.', 'faz-cookie-manager' ),
				array( 'status' => 403 )
			);
		}

		// Verify the cache-compatible, time-bucketed token. Accept the previous
		// bucket as well so a page held by a normal full-page cache remains usable.
		$token = $request->get_param( 'token' );
		if ( ! empty( $token ) ) {
			$current_bucket  = (string) floor( time() / ( 12 * HOUR_IN_SECONDS ) );
			$previous_bucket = (string) ( floor( time() / ( 12 * HOUR_IN_SECONDS ) ) - 1 );
			$valid = hash_equals( wp_hash( 'faz_consent_' . $current_bucket ), $token )
				|| hash_equals( wp_hash( 'faz_consent_' . $previous_bucket ), $token );
			if ( ! $valid ) {
				return new \WP_Error(
					'invalid_token',
					'Invalid origin token.',
					array( 'status' => 403 )
				);
			}
		} else {
			// No token = request not from a page rendered by this plugin.
			return new \WP_Error(
				'missing_token',
				'Origin token required.',
				array( 'status' => 403 )
			);
		}

		// Dual guardrail: per-IP AND per-consent_id throttle.
		// The IP check prevents a single client from flooding with different consent_ids.
		// The consent_id check prevents replaying the same consent_id from different IPs.
		$consent_id           = $request->get_param( 'consent_id' );
		$sanitized_consent_id = sanitize_text_field( (string) ( $consent_id ?? '' ) );
		$is_ip_throttled      = faz_throttle_request( 'faz_consent_ip', 10 );
		$is_consent_throttled = false;

		// Answer a throttled caller before doing any work for it. The block
		// below runs a database query (last_logged_status) and arms two
		// transients, and until now all of that happened even when the verdict
		// at the bottom was already decided — so a client being rate-limited
		// still cost a query per request, which is the flooding this endpoint
		// throttles in the first place. Nothing below can clear an IP throttle,
		// so returning here changes no outcome, only the price of reaching it.
		if ( $is_ip_throttled ) {
			return rest_ensure_response( array( 'throttled' => true ) );
		}

		if ( '' !== $sanitized_consent_id ) {
			// The per-consent_id throttle exists to stop one id being replayed
			// from many IPs. But the consent_id is deliberately KEPT across
			// sessions (script.js keeps `consentid` so analytics can correlate),
			// so a visitor who accepts and then withdraws minutes later posts the
			// SAME id — and the withdrawal was dropped inside the 300s window,
			// with an HTTP 200 the fire-and-forget client never inspects.
			//
			// The surviving row then affirmatively states "accepted" for a
			// visitor who has withdrawn: worse than a missing record, and the one
			// record Art. 7(3) accountability actually needs. A status change is
			// never a replay — it is the event — so it bypasses the id throttle.
			// The per-IP throttle above still bounds flooding.
			// Allowlisted BEFORE the comparison, and matching the set the
			// controller keeps (anything else it folds to 'partial'). Without
			// this, sanitize_key() made any non-empty string a "change", so a
			// caller could alternate junk values — or even valid ones — and mint
			// a fresh row on every request, using the accountability bypass as an
			// unthrottled write path.
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! in_array( $status, array( 'accepted', 'rejected', 'partial', 'dnsmpi_optout', 'dns_rescinded', 'pmp_grant' ), true ) ) {
				$status = '';
			}
			$previous       = $this->last_logged_status( $sanitized_consent_id );
			$status_changed = '' !== $status && $status !== $previous;

			// The window is ARMED unconditionally and its verdict ignored only for
			// a status change. Skipping the call entirely — the first shape of this
			// fix — meant a bypass never armed anything, so the next identical
			// replay was let through too and only the one after it was blocked.
			// That silently weakened the 300s guarantee by one request; arming it
			// here keeps replays throttled from the very first repeat while a real
			// change still always lands.
			$consent_key   = 'faz_consent_' . substr( md5( $sanitized_consent_id ), 0, 8 );
			$window_closed = faz_throttle_request( $consent_key, 300 );

			// The FIRST change in a window is always free; the ones after it are
			// rate-limited. A flat cap on every change was tried first and was
			// wrong — it re-broke the case this guard exists for (accept by
			// mistake, reject seconds later, withdrawal dropped again). Splitting
			// the two keeps the immediate correction guaranteed while denying a
			// script an unbounded write path by alternating valid statuses, which
			// the per-IP throttle alone does not stop across a distributed set of
			// addresses sharing one consent_id.
			if ( $status_changed ) {
				$hash = substr( md5( $sanitized_consent_id ), 0, 8 );
				if ( faz_throttle_request( 'faz_consent_chg1_' . $hash, 300 ) ) {
					// A change already landed in this window — throttle the rest.
					$status_changed = ! faz_throttle_request( 'faz_consent_chgn_' . $hash, 10 );
				}
			}

			$is_consent_throttled = $status_changed ? false : $window_closed;
		}
		if ( $is_ip_throttled || $is_consent_throttled ) {
			return rest_ensure_response( array( 'throttled' => true ) );
		}

		$data = array(
			'consent_id' => $request->get_param( 'consent_id' ),
			'status'     => $request->get_param( 'status' ),
			'categories' => $request->get_param( 'categories' ),
			'url'        => $request->get_param( 'url' ),
			'banner_slug' => $request->get_param( 'banner_slug' ),
			'policy_revision' => $request->get_param( 'policy_revision' ),
			// Audit signals derived server-side from the request headers so the
			// signal_gpc_received / signal_dnt_received columns are actually
			// populated in the normal frontend flow (they were always NULL
			// before — the frontend payload never carried them). GPC is the
			// `Sec-GPC: 1` header (mirror of navigator.globalPrivacyControl);
			// DNT is the legacy `DNT: 1` header.
			'signal_gpc' => ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ) ? 1 : 0,
			'signal_dnt' => ( isset( $_SERVER['HTTP_DNT'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) ) ? 1 : 0,
			'tc_string'  => $request->get_param( 'tc_string' ),
			'gpp_string' => $request->get_param( 'gpp_string' ),
		);

		$result = Controller::get_instance()->log_consent( $data );

		if ( false === $result ) {
			return new \WP_Error(
				'consent_log_failed',
				__( 'Failed to log consent.', 'faz-cookie-manager' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * The status of the most recent log row for a consent id, if any.
	 *
	 * Used only to tell a status CHANGE apart from a replay of the same status:
	 * the first is the event accountability exists to record and must never be
	 * throttled away, the second is what the throttle is for. Reuses the
	 * controller's own newest-row lookup rather than adding a second query with
	 * its own idea of "latest".
	 *
	 * @param string $consent_id Sanitised consent id.
	 * @return string Previous status, or '' when nothing is recorded yet.
	 */
	private function last_logged_status( $consent_id ) {
		if ( '' === $consent_id ) {
			return '';
		}
		$previous = Controller::get_instance()->get_log_by_consent_id( $consent_id );
		return ( is_array( $previous ) && isset( $previous['status'] ) )
			? sanitize_key( (string) $previous['status'] )
			: '';
	}

	/**
	 * Accept only a browser POST initiated by this exact origin.
	 *
	 * Consent logging is an anonymous endpoint. A public, cache-compatible
	 * token is useful as a request-origin marker but cannot distinguish the
	 * site's own page from a cross-site form/fetch. Fetch Metadata is preferred;
	 * when unavailable, match scheme, host and effective port of Origin (or
	 * Referer) against home_url(). Reject when no trustworthy signal exists.
	 *
	 * @return bool
	 */
	private function is_same_origin_request() {
		if ( ! empty( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) {
			$fetch_site = sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) );
			return 'same-origin' === $fetch_site;
		}

		$site    = wp_parse_url( home_url( '/' ) );
		$request = array();
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$request = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$request = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
		}
		if ( ! is_array( $site ) || ! is_array( $request ) ) {
			return false;
		}

		$site_scheme    = isset( $site['scheme'] ) ? strtolower( $site['scheme'] ) : '';
		$request_scheme = isset( $request['scheme'] ) ? strtolower( $request['scheme'] ) : '';
		$site_host      = isset( $site['host'] ) ? strtolower( $site['host'] ) : '';
		$request_host   = isset( $request['host'] ) ? strtolower( $request['host'] ) : '';
		$site_port      = isset( $site['port'] ) ? (int) $site['port'] : ( 'https' === $site_scheme ? 443 : 80 );
		$request_port   = isset( $request['port'] ) ? (int) $request['port'] : ( 'https' === $request_scheme ? 443 : 80 );

		return '' !== $site_scheme && '' !== $site_host
			&& $site_scheme === $request_scheme
			&& $site_host === $request_host
			&& $site_port === $request_port;
	}
}
