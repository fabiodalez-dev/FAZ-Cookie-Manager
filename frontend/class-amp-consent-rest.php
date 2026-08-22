<?php
/**
 * Server-side bridge for AMP consent.
 *
 * @package    FazCookie
 * @subpackage FazCookie/Frontend
 */

namespace FazCookie\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FazCookie\Admin\Modules\Banners\Includes\Controller as Banner_Controller;
use FazCookie\Admin\Modules\Consentlogs\Includes\Controller as Consent_Log_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories;
use FazCookie\Frontend\Includes\Geo_Runtime;
use FazCookie\Includes\Geolocation;

/**
 * Remote consent state used by AMP's checkConsentHref/onUpdateHref flow.
 *
 * The public routes intentionally do not use a WordPress login nonce: AMP
 * Cache pages are cross-origin anonymous documents. Instead every request is
 * bound to an active banner by an HMAC scope and must pass AMP's CORS security
 * model (publisher source origin + exact publisher/cache request origin).
 */
class AMP_Consent_Rest {

	const REST_NAMESPACE = 'faz/v1';
	const CHECK_ROUTE    = '/amp-consent/check';
	const UPDATE_ROUTE   = '/amp-consent/update';
	const STATE_VERSION  = 1;

	/** @var string CORS origin accepted for the request currently being served. */
	private $cors_origin = '';

	/** @var string Publisher source origin accepted for the current request. */
	private $cors_source_origin = '';

	/**
	 * Register the REST and response-header hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Authorise preflight BEFORE core answers it. WordPress short-circuits
		// every OPTIONS request in rest_handle_options_request(), hooked on
		// rest_pre_dispatch at priority 10, so a route callback never sees one.
		// Running at 9 is the only place the bridge can establish the CORS origin
		// for a preflight; without it serve_cors_headers() had nothing to emit
		// and stripped the header core had already set, failing every preflight.
		add_filter( 'rest_pre_dispatch', array( $this, 'authorize_preflight' ), 9, 3 );
		// WordPress core reflects arbitrary REST origins. Replace that broad
		// header for these two routes after core's rest_send_cors_headers runs.
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_cors_headers' ), 20, 4 );
	}

	/**
	 * Register AMP endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
		$common = array(
			'permission_callback' => '__return_true',
			'args'                => array(
				'banner' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_title',
				),
				'scope'  => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::CHECK_ROUTE,
			array_merge(
				$common,
				array(
					'methods'  => 'POST',
					'callback' => array( $this, 'handle_check' ),
				)
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::UPDATE_ROUTE,
			array_merge(
				$common,
				array(
					'methods'  => 'POST',
					'callback' => array( $this, 'handle_update' ),
					'args'     => array_merge(
						$common['args'],
						array(
							'ampUserId' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							// NOT `required`. This value arrives in the POST body, and
							// the body is JSON sent as text/plain (see
							// hydrate_amp_body()), which WordPress does not parse into
							// params. A `required` arg is enforced during dispatch,
							// BEFORE the callback runs — so marking it required made
							// WordPress reject every real AMP update with
							// rest_missing_callback_param before the bridge saw it.
							// handle_update() validates its presence itself.
							'consentStateValue' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_key',
							),
							'purposeConsent' => array(
								'type' => 'object',
							),
							// Current amp-consent runtime uses the plural key in both
							// check and update payloads. Keep the singular alias above for
							// compatibility with older examples/integrations.
							'purposeConsents' => array(
								'type' => 'object',
							),
						)
					),
				)
			)
		);

		/*
		 * No explicit OPTIONS routes.
		 *
		 * They used to be registered here, described as making preflight obey the
		 * same origin checks as the POSTs. They never ran: core short-circuits
		 * every OPTIONS request in rest_handle_options_request() on
		 * rest_pre_dispatch, before route callbacks. So authorize_request() never
		 * executed for a preflight, $this->cors_origin stayed empty, and
		 * serve_cors_headers() then STRIPPED the Access-Control-Allow-Origin
		 * header core had already emitted — meaning a real preflight against
		 * these routes failed outright rather than being checked more strictly.
		 *
		 * amp-consent sends its body as text/plain and normally stays preflight-
		 * free (see hydrate_amp_body()). JSON/custom clients can still preflight,
		 * so authorize_preflight() now performs the origin check at priority 9,
		 * before core's priority-10 short circuit.
		 */
	}

	/**
	 * The origin accepted for the request currently being served.
	 *
	 * Exposed so a test can assert that a denied preflight records nothing —
	 * the difference between failing closed and failing open is invisible from
	 * the return value alone.
	 *
	 * @return string
	 */
	public function cors_origin_for_tests() {
		return $this->cors_origin;
	}

	/**
	 * Establish the CORS origin for a preflight against the AMP routes.
	 *
	 * Returns $result untouched — this is an authorisation side effect, not a
	 * response. A denied origin simply leaves cors_origin empty, and
	 * serve_cors_headers() then emits nothing, so the browser refuses the
	 * follow-up request.
	 *
	 * "Fail-closed by construction" only holds if nothing can be inherited, so
	 * the state is cleared for EVERY request on the two AMP routes — the exact
	 * surface serve_cors_headers() acts on — before the method is even looked at.
	 * The clearing used to live solely in authorize_request(), which a request
	 * that never reaches its callback (a dispatch-time 400, a denied preflight)
	 * does not run: within one PHP process serving several REST sub-requests, such
	 * a response could then carry the origin an earlier request had established.
	 *
	 * @param mixed            $result  Existing short-circuit result.
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function authorize_preflight( $result, $server, $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_method' ) ) {
			return $result;
		}
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( '/' . self::REST_NAMESPACE . self::CHECK_ROUTE !== $route
			&& '/' . self::REST_NAMESPACE . self::UPDATE_ROUTE !== $route ) {
			return $result;
		}
		$this->cors_origin        = '';
		$this->cors_source_origin = '';
		if ( 'OPTIONS' !== strtoupper( (string) $request->get_method() ) ) {
			return $result;
		}
		$this->authorize_request( $request );
		return $result;
	}

	/**
	 * Make the AMP runtime's request body readable as request params.
	 *
	 * amp-consent posts its payload as JSON but deliberately labels it
	 * `text/plain;charset=utf-8` so the request stays preflight-free (see
	 * amphtml's setupJsonFetchInit). WordPress only turns a body into params for
	 * `application/json` or `application/x-www-form-urlencoded`
	 * (WP_REST_Request::parse_body_params + parse_json_params), so with the real
	 * runtime every body param resolved to null: checkConsentHref failed its
	 * instance comparison and returned 400 on every request, and onUpdateHref
	 * was rejected during dispatch. The bridge never ran once against real AMP.
	 *
	 * Decoding here rather than switching AMP to application/json is deliberate:
	 * text/plain is the amp-consent wire contract and avoids an unnecessary CORS
	 * round trip. JSON callers are still supported; authorize_preflight() handles
	 * their OPTIONS request before WordPress core short-circuits it.
	 *
	 * Query-string params are left alone — `banner`, `scope` and
	 * `__amp_source_origin` travel in the URL and WordPress parses those
	 * normally. Body values never overwrite them.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	private function hydrate_amp_body( $request ) {
		$body = $request->get_body();
		if ( '' === trim( (string) $body ) ) {
			return;
		}

		$content_type = $request->get_content_type();
		$type         = isset( $content_type['value'] ) ? strtolower( $content_type['value'] ) : '';
		if ( 'application/json' === $type || 'application/x-www-form-urlencoded' === $type ) {
			return; // WordPress already parsed it.
		}

		$decoded = json_decode( (string) $body, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			return;
		}

		// The only body keys that legitimately carry a structure are the purpose
		// maps; everything else the bridge reads is a single scalar.
		$map_params = array( 'purposeConsent', 'purposeConsents' );

		foreach ( $decoded as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			// A URL param is authoritative: it is the one the server itself
			// baked into checkConsentHref/onUpdateHref, so a body key must not
			// be able to restate `banner` or `scope` with something else.
			if ( null !== $request->get_param( $key ) ) {
				continue;
			}
			// Hydrate a value only in the shape its key can be read in. A body
			// like {"consentStateValue":{"a":1}} reached sanitize_key( (string)
			// $value ) and emitted an "Array to string conversion" warning on its
			// way to the same fail-closed answer. The outcome was never wrong, but
			// a payload any stranger can send that fills the error log is worth
			// dropping at the boundary rather than tolerating downstream.
			if ( in_array( $key, $map_params, true ) ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
			} elseif ( ! is_scalar( $value ) ) {
				continue;
			}
			$request->set_param( $key, $value );
		}
	}

	/**
	 * Build banner-scoped endpoint URLs for amp-consent.
	 *
	 * @param object $banner Active banner object.
	 * @return array{check:string,update:string,instance:string}
	 */
	public static function endpoint_urls( $banner ) {
		$slug         = sanitize_title( (string) $banner->get_slug() );
		$query        = array(
			'banner' => $slug,
			'scope'  => self::scope_token( $slug ),
		);
		$update_query = $query;
		$source_origin = self::origin_from_url( home_url( '/' ) );
		if ( '' !== $source_origin ) {
			// amp-consent intentionally sends onUpdateHref with ampCors=false,
			// so the runtime does not append __amp_source_origin to cross-origin
			// cache requests. Bind it into the rendered endpoint URL instead.
			$update_query['__amp_source_origin'] = $source_origin;
		}
		return array(
			'check'    => set_url_scheme( add_query_arg( $query, rest_url( self::REST_NAMESPACE . self::CHECK_ROUTE ) ), 'https' ),
			'update'   => set_url_scheme( add_query_arg( $update_query, rest_url( self::REST_NAMESPACE . self::UPDATE_ROUTE ) ), 'https' ),
			'instance' => self::instance_id( $slug ),
		);
	}

	/**
	 * Public, cache-safe scope marker. CORS remains the CSRF control.
	 *
	 * @param string $banner_slug Banner slug.
	 * @return string
	 */
	public static function scope_token( $banner_slug ) {
		return wp_hash( 'faz_amp_consent_scope|' . sanitize_title( (string) $banner_slug ), 'auth' );
	}

	/**
	 * Stable AMP consent instance for one banner scope.
	 *
	 * @param string $banner_slug Banner slug.
	 * @return string
	 */
	public static function instance_id( $banner_slug ) {
		return 'faz-cookie-consent-' . sanitize_title( (string) $banner_slug );
	}

	/**
	 * Return visible optional categories as AMP purposes.
	 *
	 * Purpose IDs intentionally reuse sanitized category slugs. This makes
	 * `data-block-on-consent-purposes` and the standard consent cookie share a
	 * single vocabulary and avoids a lossy mapping at the REST boundary.
	 *
	 * @return array[] Array of {id,slug,name} maps.
	 */
	public static function get_purposes() {
		$purposes   = array();
		$country    = class_exists( Geolocation::class ) ? Geolocation::get_visitor_country() : '';
		$geo_runtime = class_exists( Geo_Runtime::class );
		$ruleset     = $geo_runtime ? Geo_Runtime::resolve_for_country( $country ) : null;
		$categories = Category_Controller::get_instance()->get_items();
		foreach ( (array) $categories as $raw_category ) {
			$category = new Cookie_Categories( $raw_category );
			$slug     = sanitize_key( (string) $category->get_slug() );
			if (
				'' === $slug
				|| 'necessary' === $slug
				|| 'wordpress-internal' === $slug
				|| ! $category->get_visibility()
			) {
				continue;
			}
			$prior = method_exists( $category, 'get_prior_consent' ) ? (bool) $category->get_prior_consent() : false;
			$sell  = method_exists( $category, 'get_sell_personal_data' ) ? (bool) $category->get_sell_personal_data() : false;
			$share = method_exists( $category, 'get_share_personal_data' ) ? (bool) $category->get_share_personal_data() : false;
			$defaults = $geo_runtime
				? Geo_Runtime::default_consent(
					$ruleset,
					$slug,
					$prior,
					$sell,
					$share
				)
				: array(
					'gdpr' => $prior,
					'ccpa' => ! $sell && ! $share,
				);
			$purposes[] = array(
				'id'                      => $slug,
				'slug'                    => $slug,
				'name'                    => wp_strip_all_tags( (string) $category->get_name( faz_current_language() ) ),
				'do_not_sell'             => $sell || $share,
				'default_consent'          => $defaults,
				'default_from_ruleset'     => $geo_runtime && Geo_Runtime::is_ruleset_default( $ruleset, $slug ),
				'requires_separate_optin' => $geo_runtime && Geo_Runtime::requires_separate_optin( $ruleset, $slug ),
			);
		}
		return $purposes;
	}

	/**
	 * Name each purpose gets as an AMP action argument.
	 *
	 * `setPurpose(<name>=event.checked)` takes a plain identifier, so a slug's
	 * hyphens have to become underscores. That substitution is not injective,
	 * and category slugs are admin-editable: a site with both "social-media" and
	 * "social_media" rendered the same argument twice, and the server — which
	 * accepted the underscore form as an alias of the hyphenated slug — read one
	 * value for both. Ticking one box granted the other category too, which is
	 * the one direction a consent bridge may never fail in.
	 *
	 * Every raw id is reserved before any alias is handed out, so an argument
	 * can never collide with a different category's own slug, and an argument
	 * already taken gains a numeric suffix. Both the renderer and
	 * normalize_purpose_consent() derive this map from the same purpose list, so
	 * the two ends stay in step by construction rather than by convention.
	 *
	 * @param array[] $purposes Purposes as returned by get_purposes().
	 * @return array<string,string> Purpose id => action-argument name.
	 */
	public static function purpose_action_args( $purposes ) {
		$reserved = array();
		foreach ( (array) $purposes as $purpose ) {
			$id = isset( $purpose['id'] ) ? sanitize_key( $purpose['id'] ) : '';
			if ( '' !== $id ) {
				$reserved[ $id ] = true;
			}
		}

		$args  = array();
		$taken = $reserved;
		foreach ( array_keys( $reserved ) as $id ) {
			$base  = str_replace( '-', '_', $id );
			$arg   = $base;
			$index = 2;
			// A slug that needs no translation keeps its own name: the entry it
			// finds in $taken is its own reservation.
			while ( $arg !== $id && isset( $taken[ $arg ] ) ) {
				$arg = $base . '_' . $index;
				$index++;
			}
			$args[ $id ]   = $arg;
			$taken[ $arg ] = true;
		}
		return $args;
	}

	/**
	 * checkConsentHref callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_check( $request ) {
		// Before anything reads a param: the AMP runtime's body is JSON labelled
		// text/plain, which WordPress leaves unparsed.
		$this->hydrate_amp_body( $request );

		$cors = $this->authorize_request( $request );
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}
		if ( ! $this->is_banner_control_enabled() ) {
			return $this->response(
				array(
					'consentRequired'   => false,
					'consentStateValue' => 'unknown',
					'purposeConsents'   => array(),
					'expireCache'       => true,
				)
			);
		}

		$context = $this->resolve_context( $request );
		if ( is_wp_error( $context ) ) {
			if ( 'faz_amp_inactive_banner' === $context->get_error_code() ) {
				// The slug baked into a cached AMP document no longer resolves.
				// Answering consentRequired:false here told amp-consent to
				// unblock EVERY gated component — so a publisher who simply
				// renamed or replaced a banner turned tracker gating off for
				// every visitor still being served that document from the AMP
				// cache, for as long as it took the cache to re-crawl. No
				// attacker was needed; the signed scope stops forgery, not a
				// routine configuration change.
				//
				// If any banner is still active site-wide, consent is still
				// required — the canonical page is prompting for it. Answer
				// restrictively and expire the cache so the document gets
				// re-fetched with the current banner's scope.
				$replacement = Banner_Controller::get_instance()->get_active_banner();
				if ( $replacement ) {
					$purposes = self::get_purposes();
					return $this->response(
						array(
							'consentRequired'   => true,
							'consentStateValue' => 'unknown',
							'purposeConsents'   => self::purpose_defaults( $purposes, false ),
							'purposeConsent'    => self::purpose_defaults( $purposes, false ),
							'expireCache'       => true,
						)
					);
				}

				// No banner is active anywhere: the publisher has genuinely
				// stopped asking for consent, so there is nothing to gate.
				return $this->response(
					array(
						'consentRequired'   => false,
						'consentStateValue' => 'unknown',
						'purposeConsents'   => array(),
						'purposeConsent'    => array(),
						'expireCache'       => true,
					)
				);
			}
			return $context;
		}

		$instance = sanitize_text_field( (string) $request->get_param( 'consentInstanceId' ) );
		if ( ! hash_equals( $context['instance'], $instance ) ) {
			return new \WP_Error( 'faz_amp_invalid_instance', __( 'Invalid AMP consent instance.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		$incoming_state = self::sanitize_state( $request->get_param( 'consentStateValue' ) );
		$cookie         = function_exists( 'faz_get_valid_consent_cookie' ) ? faz_get_valid_consent_cookie() : '';
		$server_state   = self::state_from_cookie( $cookie, $context );

		// A signed state returned by checkConsentHref binds AMP's local cache to
		// the server expiry/revision. It exists for exactly one situation: a
		// browser that REFUSES the publisher cookie in a third-party context, so
		// the cookie's absence proves nothing about the visitor's wishes.
		//
		// It must never substitute for a cookie that could have been sent and
		// was not. On a same-origin request the consent cookie is first-party;
		// if it is missing, it was deleted — and clearing cookies is a
		// recognised way of withdrawing consent. Honouring the signed state
		// there kept answering "accepted" for the whole remaining lifetime (up
		// to 180 or 365 days) while the canonical page had already gone back to
		// prompting: the withdrawal simply never reached AMP.
		//
		// Scope changes were already covered — decode_state_string() rejects a
		// token whose banner, law or revision no longer match — but a plain
		// cookie deletion changes none of those, so it slipped through.
		$site_origin      = self::origin_from_url( home_url( '/' ) );
		$request_from_cache = '' !== $this->cors_origin && $this->cors_origin !== $site_origin;
		if ( false === $server_state && $request_from_cache ) {
			$signed = sanitize_text_field( (string) $request->get_param( 'consentString' ) );
			$server_state = self::decode_state_string( $signed, $context );
		}

		if ( false === $server_state ) {
			$data = array(
				'consentRequired'   => true,
				'consentStateValue' => 'unknown',
				'purposeConsents'   => self::purpose_defaults( $context['purposes'], false ),
				'purposeConsent'    => self::purpose_defaults( $context['purposes'], false ),
				// Clear an accepted/rejected AMP localStorage decision whenever the
				// server cannot prove its current scope, revision and lifetime.
				'expireCache'       => in_array( $incoming_state, array( 'accepted', 'rejected' ), true ),
				'sharedData'        => self::shared_data( $context, 0 ),
			);
			return $this->response( $data );
		}

		// A current Sec-GPC signal overrides a conflicting persisted grant on
		// every check. The response is enough to keep AMP components blocked;
		// the canonical cookie is reconciled by the standard runtime (or by the
		// next AMP update) without turning this read endpoint into a write.
		self::apply_gpc_to_purposes( $server_state['purposes'], $context['purposes'] );

		$amp_purposes = self::purpose_values_for_amp( $server_state['purposes'], $context['purposes'] );
		$data         = array(
			'consentRequired'   => true,
			'consentStateValue' => $server_state['state'],
			'purposeConsents'   => $amp_purposes,
			'purposeConsent'    => $amp_purposes,
			'expireCache'       => 'unknown' !== $incoming_state && $incoming_state !== $server_state['state'],
			'sharedData'        => self::shared_data( $context, $server_state['expires'] ),
		);
		// Classic FAZ cookies created before the AMP bridge do not contain their
		// original absolute expiry. Do not invent a new sliding TTL for them: the
		// browser cookie remains authoritative and its disappearance will make the
		// next check expire AMP's cache. Bridge-written cookies carry `exp`, so
		// their exact deadline can safely be signed into AMP local storage.
		if ( $server_state['expires'] > time() ) {
			$data['consentString'] = self::encode_state_string( $server_state, $context );
		}
		return $this->response( $data );
	}

	/**
	 * onUpdateHref callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update( $request ) {
		$this->hydrate_amp_body( $request );

		$cors = $this->authorize_request( $request );
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}
		if ( ! $this->is_banner_control_enabled() ) {
			return new \WP_Error( 'faz_amp_banner_disabled', __( 'The AMP consent banner is disabled.', 'faz-cookie-manager' ), array( 'status' => 409 ) );
		}

		// Validated here rather than via a `required` route arg: the value comes
		// from the body, and dispatch-time enforcement rejected every real AMP
		// request before this callback could decode it.
		$requested_state = sanitize_key( (string) $request->get_param( 'consentStateValue' ) );
		if ( '' === $requested_state ) {
			return new \WP_Error(
				'faz_amp_missing_state',
				__( 'Missing AMP consent state.', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}

		$context = $this->resolve_context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$instance = sanitize_text_field( (string) $request->get_param( 'consentInstanceId' ) );
		if ( ! hash_equals( $context['instance'], $instance ) ) {
			return new \WP_Error( 'faz_amp_invalid_instance', __( 'Invalid AMP consent instance.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		$state = self::sanitize_state( $request->get_param( 'consentStateValue' ) );
		if ( ! in_array( $state, array( 'accepted', 'rejected' ), true ) ) {
			return new \WP_Error( 'faz_amp_invalid_state', __( 'Invalid AMP consent state.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}

		if ( function_exists( 'faz_throttle_request' ) && faz_throttle_request( 'faz_amp_consent_update', 3 ) ) {
			return new \WP_Error( 'faz_amp_rate_limited', __( 'Too many consent updates.', 'faz-cookie-manager' ), array( 'status' => 429 ) );
		}

		$purpose_input = $request->get_param( 'purposeConsents' );
		if ( ! is_array( $purpose_input ) ) {
			$purpose_input = $request->get_param( 'purposeConsent' );
		}
		$purposes      = self::normalize_purpose_consent( $purpose_input, $context['purposes'], $state );
		self::apply_gpc_to_purposes( $purposes, $context['purposes'] );
		$gpc_active    = self::is_gpc_active();
		$expires       = time() + ( $context['expiry_days'] * DAY_IN_SECONDS );
		$consent_id    = self::consent_id( $request->get_param( 'ampUserId' ) );
		$cookie_value  = self::build_cookie_value( $state, $purposes, $context, $consent_id, $expires, null, $gpc_active );

		if ( function_exists( 'faz_set_browser_cookie' ) ) {
			// AMP Cache pages call the publisher endpoint cross-site. None+Secure
			// lets supporting browsers store/send the publisher cookie in that
			// credentialed request. Browsers that block third-party cookies may
			// still refuse it; in that case a later check cannot prove the cached
			// decision and expires it fail-closed. We do not claim guaranteed
			// cross-site synchronization under browser storage blocking.
			$site_origin = self::origin_from_url( home_url( '/' ) );
			$from_cache  = '' !== $this->cors_origin && $this->cors_origin !== $site_origin;
			faz_set_browser_cookie(
				'fazcookie-consent',
				$cookie_value,
				$expires,
				null,
				$from_cache ? 'None' : 'Lax',
				$from_cache ? true : null
			);
		}

		$this->maybe_log_consent( $state, $purposes, $context, $consent_id );

		$server_state = array(
			'state'    => $state,
			'purposes' => $purposes,
			'expires'  => $expires,
		);
		$amp_purposes = self::purpose_values_for_amp( $purposes, $context['purposes'] );
		return $this->response(
			array(
				'updated'           => true,
				'consentStateValue' => $state,
				'consentString'     => self::encode_state_string( $server_state, $context ),
				'purposeConsents'   => $amp_purposes,
				'purposeConsent'    => $amp_purposes,
				'sharedData'        => self::shared_data( $context, $expires ),
			)
		);
	}

	/**
	 * Enforce GPC against sale/share purposes while retaining unrelated choices.
	 *
	 * @param array $values   Purpose slug => bool map, modified by reference.
	 * @param array $purposes Purpose descriptors.
	 * @return bool Whether any grant was revoked.
	 */
	private static function apply_gpc_to_purposes( &$values, $purposes ) {
		$active = self::is_gpc_active();
		if ( ! $active ) {
			return false;
		}
		$changed = false;
		foreach ( (array) $purposes as $purpose ) {
			$id = isset( $purpose['id'] ) ? sanitize_key( $purpose['id'] ) : '';
			if ( '' !== $id && ! empty( $purpose['do_not_sell'] ) ) {
				if ( ! empty( $values[ $id ] ) ) {
					$changed = true;
				}
				$values[ $id ] = false;
			}
		}
		return $changed;
	}

	/**
	 * Whether this AMP request carries an asserted Global Privacy Control signal.
	 *
	 * @return bool
	 */
	private static function is_gpc_active() {
		return isset( $_SERVER['HTTP_SEC_GPC'] )
			&& '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) );
	}

	/**
	 * Resolve and validate the banner scope carried by a cached AMP document.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function resolve_context( $request ) {
		$slug  = sanitize_title( (string) $request->get_param( 'banner' ) );
		$scope = sanitize_text_field( (string) $request->get_param( 'scope' ) );
		if ( '' === $slug || '' === $scope || ! hash_equals( self::scope_token( $slug ), $scope ) ) {
			return new \WP_Error( 'faz_amp_invalid_scope', __( 'Invalid AMP consent scope.', 'faz-cookie-manager' ), array( 'status' => 403 ) );
		}

		$banner = Banner_Controller::get_instance()->get_active_banner_by_slug( $slug );
		if ( ! $banner ) {
			return new \WP_Error( 'faz_amp_inactive_banner', __( 'The AMP consent banner is no longer active.', 'faz-cookie-manager' ), array( 'status' => 409 ) );
		}

		// The RAW law, not a gdpr/ccpa fold. class-frontend.php hashes
		// $banner->get_law() verbatim into _scopeFingerprint, and the banner UI
		// offers a third value ("gdpr_ccpa"), with the setup wizard writing
		// "both" and "popia" as well. Folding here produced a different
		// fingerprint for every one of those, so an AMP-written cookie read as
		// scope-tampered to the classic JS and a classic cookie was never
		// recognised by state_from_cookie() — the two surfaces silently stopped
		// reconciling on a mainstream GDPR+CCPA configuration.
		// Taken verbatim, not sanitize_key()'d: class-frontend.php feeds the raw
		// return value into both _activeLaw and the fingerprint hash, and the
		// classic JS writes that same raw string into __scope.law. Normalising
		// on only one side is how the two formulas drift apart again.
		$law = (string) $banner->get_law();
		if ( '' === $law ) {
			$law = 'gdpr';
		}
		// The expiry rule itself is only CCPA-vs-rest, so that one keeps the
		// fold — a "gdpr_ccpa" banner is a GDPR-family banner for lifetime
		// purposes and must not inherit the 365-day CCPA floor.
		$expiry_law        = ( 'ccpa' === $law ) ? 'ccpa' : 'gdpr';
		$settings          = $banner->get_settings();
		// The fallback for a banner that never stored consentExpiry must be the
		// SAME number Frontend::get_store_data() falls back to, or the classic page
		// and its AMP twin hand the same visitor two different lifetimes. 180 is the
		// plugin's canonical GDPR-family value: it is what gdpr.json ships, what the
		// setup wizard writes and what the editor pre-fills. 182 (the bare six-month
		// cap) was the outlier and made the AMP copy of a default banner outlive the
		// canonical one by two days.
		$configured_expiry = isset( $settings['settings']['consentExpiry']['value'] )
			? absint( $settings['settings']['consentExpiry']['value'] )
			: ( 'ccpa' === $expiry_law ? 365 : 180 );
		$expiry_days = Frontend::normalize_consent_expiry( $expiry_law, $configured_expiry );
		$revision    = function_exists( 'faz_get_consent_revision' ) ? faz_get_consent_revision() : 1;

		return array(
			'banner'            => $banner,
			'slug'              => $slug,
			'law'               => $law,
			'expiry_law'        => $expiry_law,
			'instance'          => self::instance_id( $slug ),
			'purposes'          => self::get_purposes(),
			'expiry_days'       => $expiry_days,
			'revision'          => max( 1, absint( $revision ) ),
			'scope_fingerprint' => substr( wp_hash( $slug . '|' . $law, 'auth' ), 0, 32 ),
		);
	}

	/**
	 * Whether the public banner runtime is enabled globally.
	 *
	 * @return bool
	 */
	private function is_banner_control_enabled() {
		$settings = get_option( 'faz_settings', array() );
		return is_array( $settings ) && ! empty( $settings['banner_control']['status'] );
	}

	/**
	 * Validate AMP CORS provenance before reading or mutating consent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	private function authorize_request( $request ) {
		// One bridge instance can serve more than one subrequest (for example a
		// REST batch). Never let an origin accepted for an earlier request leak
		// into the CORS response of a later denied request.
		$this->cors_origin        = '';
		$this->cors_source_origin = '';
		$source = sanitize_text_field( (string) $request->get_param( '__amp_source_origin' ) );
		$result = self::validate_cors_environment( $_SERVER, $source, home_url( '/' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->cors_origin        = $result['origin'];
		$this->cors_source_origin = $result['source'];
		return true;
	}

	/**
	 * Pure AMP CORS validator, public for regression tests.
	 *
	 * @param array  $server        Server headers.
	 * @param string $source_origin __amp_source_origin.
	 * @param string $site_url      Publisher URL.
	 * @return array|\WP_Error
	 */
	public static function validate_cors_environment( $server, $source_origin, $site_url ) {
		$site_origin   = self::origin_from_url( $site_url );
		$source_origin = self::origin_from_url( $source_origin );
		if ( '' === $site_origin ) {
			return new \WP_Error( 'faz_amp_invalid_site_origin', 'Invalid publisher origin.', array( 'status' => 500 ) );
		}

		$origin = isset( $server['HTTP_ORIGIN'] )
			? self::origin_from_url( sanitize_text_field( wp_unslash( $server['HTTP_ORIGIN'] ) ) )
			: '';
		$same_origin = isset( $server['HTTP_AMP_SAME_ORIGIN'] )
			&& 'true' === strtolower( sanitize_text_field( wp_unslash( $server['HTTP_AMP_SAME_ORIGIN'] ) ) );

		if ( '' === $origin ) {
			if ( ! $same_origin || ( '' !== $source_origin && $source_origin !== $site_origin ) ) {
				return new \WP_Error( 'faz_amp_cors_denied', 'AMP request origin denied.', array( 'status' => 403 ) );
			}
			return array( 'origin' => $site_origin, 'source' => $site_origin );
		}

		if ( $source_origin !== $site_origin ) {
			return new \WP_Error( 'faz_amp_source_origin_denied', 'AMP source origin denied.', array( 'status' => 403 ) );
		}
		if ( $origin !== $site_origin && ! self::is_allowed_amp_cache_origin( $origin, $site_origin ) ) {
			return new \WP_Error( 'faz_amp_cors_denied', 'AMP request origin denied.', array( 'status' => 403 ) );
		}

		return array( 'origin' => $origin, 'source' => $site_origin );
	}

	/**
	 * Whether an HTTPS origin belongs to a trusted AMP cache.
	 *
	 * The filter permits publishers to add another cache from the official AMP
	 * cache registry without weakening the default suffix boundary.
	 *
	 * @param string $origin      Normalized request origin.
	 * @param string $site_origin Normalized publisher origin.
	 * @return bool
	 */
	private static function is_allowed_amp_cache_origin( $origin, $site_origin ) {
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ) {
			return false;
		}
		$site_parts = wp_parse_url( $site_origin );
		$host       = strtolower( isset( $parts['host'] ) ? $parts['host'] : '' );
		$site_host  = strtolower( is_array( $site_parts ) && isset( $site_parts['host'] ) ? $site_parts['host'] : '' );
		$cache_label = str_replace( '.', '-', str_replace( '-', '--', $site_host ) );
		$expected    = strlen( $cache_label ) <= 63
			? $cache_label . '.cdn.ampproject.org'
			: '';
		$allowed = '' !== $expected && hash_equals( $expected, $host );

		// Long/IDN publisher domains can use the cache's hashed hostname, and
		// other caches have different domains. Publishers may add only the exact
		// verified origins they use; the default never trusts every AMP-cache
		// subdomain, because onUpdateHref carries a static source-origin query.
		return (bool) apply_filters( 'faz_amp_consent_allowed_cache_origin', $allowed, $origin, $site_origin );
	}

	/**
	 * Normalize a URL to scheme://host[:non-default-port].
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin_from_url( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host   = strtolower( $parts['host'] );
		$origin = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( ! ( 80 === $port && 'http' === $scheme ) && ! ( 443 === $port && 'https' === $scheme ) ) {
				$origin .= ':' . $port;
			}
		}
		return $origin;
	}

	/**
	 * Convert AMP purpose input into a complete, fail-closed map.
	 *
	 * @param mixed  $input    Incoming purposeConsents map. amp-consent sends one
	 *                         BOOLEAN per purpose (purposeConsentDefault, or
	 *                         setPurpose(x=event.checked)); the scalar aliases
	 *                         1/'1'/'true' and 0/'0'/'false' are also accepted.
	 *                         Nothing else is recognised — in particular the
	 *                         numeric CONSENT_ITEM_STATE codes (1 accepted,
	 *                         2 rejected) are a GLOBAL-state vocabulary that never
	 *                         appears in this map, and a stray 2 here resolves to
	 *                         null and is denied by the fail-closed default below,
	 *                         not by any 1/2 translation.
	 * @param array  $purposes Configured purposes.
	 * @param string $state    Global state.
	 * @return array<string,bool>
	 */
	public static function normalize_purpose_consent( $input, $purposes, $state ) {
		$input  = is_array( $input ) ? $input : array();
		$result = array();
		$args   = self::purpose_action_args( $purposes );
		foreach ( (array) $purposes as $purpose ) {
			$id = isset( $purpose['id'] ) ? sanitize_key( $purpose['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			// AMP action-argument names cannot contain hyphens, so a checkbox
			// reports under the name purpose_action_args() assigned it — read that
			// first, or a category named "social-media" would record nothing while
			// appearing to work.
			//
			// The plain slug remains a valid key because it is the vocabulary of
			// everything that is not a checkbox: the server's own purposeConsents
			// responses and the signed consentString both use it. That fallback is
			// unambiguous only because purpose_action_args() never hands one
			// category an argument equal to another category's slug.
			$arg   = isset( $args[ $id ] ) ? $args[ $id ] : $id;
			$given = null;
			if ( array_key_exists( $arg, $input ) ) {
				$given = $input[ $arg ];
			} elseif ( $arg !== $id && array_key_exists( $id, $input ) ) {
				$given = $input[ $id ];
			}

			// Reject-all always wins. Accepted requests must explicitly carry a
			// boolean true for each purpose; missing/ambiguous values are denied.
			$result[ $id ] = 'accepted' === $state
				&& null !== $given
				&& true === self::to_bool_or_null( $given );
		}
		return $result;
	}

	/** @return bool|null */
	private static function to_bool_or_null( $value ) {
		if ( true === $value || false === $value ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		if ( 1 === $value || '1' === $value || 'true' === strtolower( (string) $value ) ) {
			return true;
		}
		if ( 0 === $value || '0' === $value || 'false' === strtolower( (string) $value ) ) {
			return false;
		}
		return null;
	}

	/** @return array<string,bool> */
	private static function purpose_defaults( $purposes, $value ) {
		$result = array();
		foreach ( (array) $purposes as $purpose ) {
			if ( ! empty( $purpose['id'] ) ) {
				$result[ sanitize_key( $purpose['id'] ) ] = (bool) $value;
			}
		}
		return self::purpose_values_for_amp( $result, $purposes );
	}

	/**
	 * Translate persisted purpose slugs to the identifiers AMP actions use.
	 *
	 * The standard cookie and signed consent string intentionally retain raw
	 * category slugs. The AMP runtime cannot use a hyphenated slug as an action
	 * argument, so every map crossing the AMP boundary must use the same
	 * collision-safe aliases as setPurpose() and purposeConsentRequired.
	 *
	 * @param array   $values   Purpose slug => boolean map.
	 * @param array[] $purposes Purposes as returned by get_purposes().
	 * @return array<string,bool>
	 */
	public static function purpose_values_for_amp( $values, $purposes ) {
		$values = is_array( $values ) ? $values : array();
		$args   = self::purpose_action_args( $purposes );
		$result = array();
		foreach ( $args as $id => $arg ) {
			$result[ $arg ] = isset( $values[ $id ] ) && true === (bool) $values[ $id ];
		}
		return $result;
	}

	/**
	 * Keys build_cookie_value() owns outright.
	 *
	 * A category slug is admin-editable, so one could legitimately be named
	 * "action" or "consent". Writing purposes into the same flat namespace
	 * without this guard let such a slug overwrite the control field that
	 * state_from_cookie() hard-gates on, which would make every later AMP read
	 * behave as if no decision had ever been recorded.
	 *
	 * @var string[]
	 */
	private static $reserved_cookie_keys = array(
		'consentid',
		'consent',
		'action',
		'necessary',
		'rev',
		'__scope.banner',
		'__scope.law',
		'__scope.fp',
		'source',
		'ts',
		'exp',
	);

	/**
	 * Parse the flat "key:value,key:value" consent cookie into a map.
	 *
	 * @param string $cookie Raw cookie value.
	 * @return array<string,string>
	 */
	private static function parse_cookie_pairs( $cookie ) {
		$pairs = array();
		foreach ( explode( ',', (string) $cookie ) as $chunk ) {
			if ( '' === $chunk || false === strpos( $chunk, ':' ) ) {
				continue;
			}
			list( $key, $value ) = explode( ':', $chunk, 2 );
			$key                 = trim( $key );
			if ( '' === $key ) {
				continue;
			}
			$pairs[ $key ] = trim( $value );
		}
		return $pairs;
	}

	/**
	 * Build the standard FAZ cookie from an AMP decision.
	 *
	 * The previous implementation built a fresh array and imploded it, so an AMP
	 * decision DESTROYED every key it does not know about. Two of those matter a
	 * great deal: `svc.*` holds per-service grants, and `gpc` records that the
	 * visitor sent a Global Privacy Control opt-out. Turning a recorded GPC
	 * opt-out into an AMP "accepted" cookie is the worst outcome this plugin can
	 * produce, so the existing cookie is now read and its unowned keys carried
	 * forward.
	 *
	 * @param string $state           accepted|rejected.
	 * @param array  $purposes        Purpose slug => bool.
	 * @param array  $context         Resolved banner context.
	 * @param string $consent_id      Consent identifier.
	 * @param int    $expires         Absolute expiry timestamp.
	 * @param string    $existing_cookie Current cookie value; read from $_COOKIE when null.
	 * @param bool|null $gpc_active     Current GPC state; auto-detected when null.
	 * @return string
	 */
	public static function build_cookie_value( $state, $purposes, $context, $consent_id, $expires, $existing_cookie = null, $gpc_active = null ) {
		if ( null === $existing_cookie ) {
			$existing_cookie = isset( $_COOKIE['fazcookie-consent'] )
				? sanitize_text_field( wp_unslash( $_COOKIE['fazcookie-consent'] ) )
				: '';
		}
		if ( null === $gpc_active ) {
			$gpc_active = self::is_gpc_active();
		}

		$pairs = array(
			'consentid'     => sanitize_text_field( (string) $consent_id ),
			'consent'       => 'accepted' === $state ? 'yes' : 'no',
			'action'        => 'yes',
			'necessary'     => 'yes',
			'rev'           => (string) absint( $context['revision'] ),
			'__scope.banner' => sanitize_title( $context['slug'] ),
			'__scope.law'    => (string) $context['law'],
			'__scope.fp'     => sanitize_text_field( $context['scope_fingerprint'] ),
			'source'        => 'amp',
			'ts'            => (string) time(),
			'exp'           => (string) absint( $expires ),
		);
		if ( $gpc_active ) {
			$pairs['gpc'] = '1';
		}

		$purpose_keys = array();
		foreach ( (array) $purposes as $purpose => $allowed ) {
			$key = sanitize_key( $purpose );
			if ( '' === $key || in_array( $key, self::$reserved_cookie_keys, true ) ) {
				// A category whose slug collides with a control field is dropped
				// rather than allowed to corrupt the decision it would overwrite.
				continue;
			}
			$pairs[ $key ]        = $allowed ? 'yes' : 'no';
			$purpose_keys[ $key ] = true;
		}

		// Carry forward everything this bridge does not own — notably `gpc` and
		// every `svc.*` per-service grant. A key the AMP decision explicitly
		// wrote wins; anything else survives untouched.
		//
		// EXCEPT a grant across a withdrawal. Carrying `yes` forward unchanged
		// meant an AMP "Reject All" produced a cookie that read consent:no with
		// every category denied while still saying `svc.<id>:yes` — and
		// is_cookie_allowed() returns true on a matching service decision
		// BEFORE it ever consults the category, so the shredder spared those
		// cookies, the outgoing guard passed their Set-Cookie headers, and
		// script.js unblocked the provider on the next classic page. The
		// visitor's withdrawal was recorded everywhere an auditor looks while
		// the service kept running. The classic runtime already does the
		// opposite — _fazClearStoredServiceConsent() runs on revoke, on GPC
		// opt-out and on save/reject — so this also stops the two runtimes
		// disagreeing about what "reject" means.
		//
		// The same rule closes the hidden-category case: get_purposes() omits
		// a category whose visibility is off, so the purpose loop above never
		// writes it and its old `yes` would survive a total withdrawal.
		//
		// Denials and the GPC opt-out are exactly what the carry-forward exists
		// to protect, so they still survive: only `yes` is refused, and only
		// when the decision was not an acceptance.
		$faz_carry_grants = ( 'accepted' === $state );
		foreach ( self::parse_cookie_pairs( $existing_cookie ) as $key => $value ) {
			if ( isset( $pairs[ $key ] ) || isset( $purpose_keys[ $key ] ) ) {
				continue;
			}
			// AMP exposes category purposes, not the classic runtime's granular
			// service/cookie controls. While GPC is asserted, carrying an opaque
			// svc.*:yes or ck.*:yes could override the category-level sale/share
			// denial on the next classic request. Clear granular overrides rather
			// than preserve an unprovable exemption; unrelated category consent is
			// retained in the explicit purpose map above.
			if ( $gpc_active && ( 0 === strpos( $key, 'svc.' ) || 0 === strpos( $key, 'ck.' ) ) ) {
				continue;
			}
			if ( ! $faz_carry_grants && 'gpc' !== $key && 'yes' === $value ) {
				continue;
			}
			$pairs[ $key ] = $value;
		}

		$out = array();
		foreach ( $pairs as $key => $value ) {
			$out[] = $key . ':' . $value;
		}
		return implode( ',', $out );
	}

	/**
	 * Read a standard FAZ cookie only when scope, revision and TTL are current.
	 *
	 * @return array|false
	 */
	public static function state_from_cookie( $cookie, $context ) {
		if ( '' === (string) $cookie ) {
			return false;
		}
		$parsed = function_exists( 'faz_parse_consent_cookie' ) ? faz_parse_consent_cookie( $cookie ) : array();
		if ( 'yes' !== ( isset( $parsed['action'] ) ? $parsed['action'] : '' ) ) {
			return false;
		}
		if ( absint( isset( $parsed['rev'] ) ? $parsed['rev'] : 1 ) < absint( $context['revision'] ) ) {
			return false;
		}
		$stored_banner = isset( $parsed['__scope.banner'] ) ? $parsed['__scope.banner'] : ( isset( $parsed['banner'] ) ? $parsed['banner'] : '' );
		$stored_law    = isset( $parsed['__scope.law'] ) ? $parsed['__scope.law'] : ( isset( $parsed['law'] ) ? $parsed['law'] : '' );
		$stored_fp     = isset( $parsed['__scope.fp'] ) ? $parsed['__scope.fp'] : '';
		if (
			! hash_equals( (string) $context['slug'], (string) $stored_banner )
			|| ! hash_equals( (string) $context['law'], (string) $stored_law )
			|| ! hash_equals( (string) $context['scope_fingerprint'], (string) $stored_fp )
		) {
			return false;
		}
		$has_absolute_expiry = isset( $parsed['exp'] );
		$expires            = $has_absolute_expiry ? absint( $parsed['exp'] ) : 0;
		if ( $has_absolute_expiry && $expires <= time() ) {
			return false;
		}

		$purposes = array();
		foreach ( (array) $context['purposes'] as $purpose ) {
			$id              = sanitize_key( $purpose['id'] );
			$purposes[ $id ] = isset( $parsed[ $id ] ) && 'yes' === $parsed[ $id ];
		}
		$state = isset( $parsed['consent'] ) && 'yes' === $parsed['consent'] ? 'accepted' : 'rejected';
		return array( 'state' => $state, 'purposes' => $purposes, 'expires' => $expires );
	}

	/**
	 * Signed state carried in AMP local storage via consentString.
	 *
	 * @return string
	 */
	/**
	 * Best available URL for the page an AMP consent was given on.
	 *
	 * @return string
	 */
	private function resolve_consent_url() {
		$referer = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';
		if ( '' === $referer ) {
			return home_url( '/' );
		}
		// Accept the publisher's own origin and its AMP cache form; anything
		// else is an unverified claim and is not worth recording as fact.
		$referer_origin = self::origin_from_url( $referer );
		if ( '' === $referer_origin ) {
			return home_url( '/' );
		}
		if ( $referer_origin === self::origin_from_url( home_url( '/' ) ) || $referer_origin === $this->cors_origin ) {
			return $referer;
		}
		return home_url( '/' );
	}

	/**
	 * Identifier of the site a signed state belongs to.
	 *
	 * @return string
	 */
	private static function site_scope_id() {
		if ( function_exists( 'get_current_blog_id' ) ) {
			return (string) get_current_blog_id();
		}
		return (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	}

	public static function encode_state_string( $state, $context ) {
		$payload = wp_json_encode(
			array(
				'v'        => self::STATE_VERSION,
				// Bind the token to THIS site. WordPress salts are network-wide on
				// multisite, so without a site component a token minted on subsite
				// A validated on subsite B whenever slug and law coincided — which
				// they routinely do with default slugs. One site's consent silently
				// honoured by another is a consent-boundary violation.
				'site'     => self::site_scope_id(),
				'banner'   => $context['slug'],
				'law'      => $context['law'],
				'rev'      => absint( $context['revision'] ),
				'exp'      => absint( $state['expires'] ),
				'state'    => self::sanitize_state( $state['state'] ),
				'purposes' => (array) $state['purposes'],
			)
		);
		$encoded = self::base64url_encode( $payload );
		return $encoded . '.' . wp_hash( 'faz_amp_consent_state|' . $encoded, 'auth' );
	}

	/** @return array|false */
	public static function decode_state_string( $signed, $context ) {
		$parts = explode( '.', (string) $signed, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( wp_hash( 'faz_amp_consent_state|' . $parts[0], 'auth' ), $parts[1] ) ) {
			return false;
		}
		$json = self::base64url_decode( $parts[0] );
		$data = json_decode( $json, true );
		if (
			! is_array( $data )
			|| self::STATE_VERSION !== absint( isset( $data['v'] ) ? $data['v'] : 0 )
			// Verified, not merely signed: a field that is put into the payload
			// and never compared changes nothing. This is what stops a token
			// minted on one multisite subsite from validating on another.
			|| ! hash_equals( self::site_scope_id(), (string) ( isset( $data['site'] ) ? $data['site'] : '' ) )
			|| ! hash_equals( (string) $context['slug'], (string) ( isset( $data['banner'] ) ? $data['banner'] : '' ) )
			|| ! hash_equals( (string) $context['law'], (string) ( isset( $data['law'] ) ? $data['law'] : '' ) )
			|| absint( isset( $data['rev'] ) ? $data['rev'] : 0 ) !== absint( $context['revision'] )
			|| absint( isset( $data['exp'] ) ? $data['exp'] : 0 ) <= time()
		) {
			return false;
		}
		$state = self::sanitize_state( isset( $data['state'] ) ? $data['state'] : '' );
		if ( ! in_array( $state, array( 'accepted', 'rejected' ), true ) ) {
			return false;
		}
		$purposes = self::normalize_purpose_consent(
			isset( $data['purposes'] ) ? $data['purposes'] : array(),
			$context['purposes'],
			$state
		);
		return array( 'state' => $state, 'purposes' => $purposes, 'expires' => absint( $data['exp'] ) );
	}

	/** @return string */
	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/** @return string */
	private static function base64url_decode( $value ) {
		$value = strtr( (string) $value, '-_', '+/' );
		$pad   = strlen( $value ) % 4;
		if ( $pad ) {
			$value .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $value, true );
		return false === $decoded ? '' : $decoded;
	}

	/** @return string */
	private static function sanitize_state( $state ) {
		$state = sanitize_key( (string) $state );
		return in_array( $state, array( 'accepted', 'rejected', 'unknown' ), true ) ? $state : 'unknown';
	}

	/** @return string */
	private static function consent_id( $amp_user_id ) {
		$current = function_exists( 'faz_parse_consent_cookie' ) ? faz_parse_consent_cookie() : array();
		if ( ! empty( $current['consentid'] ) ) {
			return substr( sanitize_text_field( $current['consentid'] ), 0, 64 );
		}
		// ampUserId is an AMP-generated cross-page identifier. It is useful to
		// AMP itself but unnecessary for FAZ accountability, so never derive our
		// consent identifier from it. Generate a fresh random id on first action.
		unset( $amp_user_id );
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Record AMP decisions through the existing local accountability store.
	 *
	 * @return void
	 */
	private function maybe_log_consent( $state, $purposes, $context, $consent_id ) {
		$settings = get_option( 'faz_settings', array() );
		if ( empty( $settings['consent_logs']['status'] ) ) {
			return;
		}
		$categories = array( 'necessary' => 'yes' );
		foreach ( $purposes as $purpose => $allowed ) {
			$categories[ $purpose ] = $allowed ? 'yes' : 'no';
		}
		$allowed_count = count( array_filter( $purposes ) );
		if ( empty( $purposes ) ) {
			$status = 'accepted' === $state ? 'accepted' : 'rejected';
		} else {
			$status = 0 === $allowed_count
				? 'rejected'
				: ( $allowed_count === count( $purposes ) ? 'accepted' : 'partial' );
		}
		Consent_Log_Controller::get_instance()->log_consent(
			array(
				'consent_id'     => $consent_id,
				'status'         => $status,
				'categories'     => $categories,
				// The page consent was actually collected on, when AMP tells us.
				// Recording home_url() for every AMP row made the accountability
				// record look plausible and be wrong, and silently weaker than the
				// one the standard flow writes — mixed into the same table with no
				// marker. The referer is the AMP document; it is validated against
				// the site before being trusted, and falls back to the home URL.
				'url'            => $this->resolve_consent_url(),
				'banner_slug'    => $context['slug'],
				'policy_revision' => $context['revision'],
				'signal_gpc'     => isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ? 1 : 0,
				'signal_dnt'     => isset( $_SERVER['HTTP_DNT'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) ? 1 : 0,
			)
		);
	}

	/** @return array */
	private static function shared_data( $context, $expires ) {
		$data = array(
			'fazConsentRevision' => absint( $context['revision'] ),
			'fazBanner'          => $context['slug'],
			'fazLaw'             => $context['law'],
		);
		// Two situations produce no deadline: consent is unknown, and a classic
		// pre-bridge cookie that never recorded its absolute `exp`. Neither is a
		// deadline of zero. Publishing fazExpiresAt: 0 states that consent expired
		// at the epoch — a false fact for any template or amp-analytics that reads
		// sharedData — where an absent key is the honest encoding of "unknown".
		if ( absint( $expires ) > 0 ) {
			$data['fazExpiresAt'] = absint( $expires );
		}
		return $data;
	}

	/**
	 * Wrap a response and attach the exact CORS headers for tests and REST.
	 *
	 * @param array $data Response body.
	 * @return \WP_REST_Response
	 */
	private function response( $data ) {
		$response = rest_ensure_response( $data );
		if ( method_exists( $response, 'header' ) ) {
			foreach ( $this->cors_headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	/** @return array<string,string> */
	private function cors_headers() {
		return array(
			'Access-Control-Allow-Origin'            => $this->cors_origin,
			'Access-Control-Allow-Credentials'       => 'true',
			'Access-Control-Allow-Methods'           => 'POST, OPTIONS',
			'Access-Control-Allow-Headers'           => 'Content-Type, AMP-Same-Origin',
			'AMP-Access-Control-Allow-Source-Origin' => $this->cors_source_origin,
			'Access-Control-Expose-Headers'          => 'AMP-Access-Control-Allow-Source-Origin',
			'Cache-Control'                          => 'private, no-store, no-cache, max-age=0',
			'Vary'                                   => 'Origin',
		);
	}

	/**
	 * Replace generic WP REST CORS headers for these routes.
	 *
	 * @param bool              $served  Whether served.
	 * @param \WP_HTTP_Response $result Response.
	 * @param \WP_REST_Request $request Request.
	 * @param \WP_REST_Server  $server  Server.
	 * @return bool
	 */
	public function serve_cors_headers( $served, $result, $request, $server ) {
		unset( $result, $server );
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE . '/amp-consent/' ) ) {
			return $served;
		}
		if ( ! headers_sent() ) {
			header_remove( 'Access-Control-Allow-Origin' );
			header_remove( 'Access-Control-Allow-Credentials' );
			header_remove( 'AMP-Access-Control-Allow-Source-Origin' );
			// On denied/error requests cors_origin remains empty: remove core's
			// reflected wildcard/Origin header and deliberately emit no CORS grant.
			if ( '' !== $this->cors_origin ) {
				foreach ( $this->cors_headers() as $name => $value ) {
					header( $name . ': ' . $value, true );
				}
			}
		}
		return $served;
	}
}
