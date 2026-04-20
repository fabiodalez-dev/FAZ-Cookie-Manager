<?php
/**
 * Plugin Name: FAZ E2E Provider Matrix
 * Description: Deterministic provider/plugin signature matrix for scan and blocking e2e coverage.
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Faz_E2E_Provider_Matrix {
	const PAGE_SLUG     = 'faz-provider-matrix';
	const HITS_OPTION   = 'faz_e2e_provider_matrix_hits';
	const WOO_OPTION    = 'faz_e2e_provider_matrix_woo_enabled';
	const CUSTOM_OPTION = 'faz_e2e_provider_matrix_custom_enabled';

	/**
	 * Boot hooks.
	 */
	public function __construct() {
		add_action( 'parse_request', array( $this, 'maybe_serve_script' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_footer', array( $this, 'render_matrix' ), 5 );
	}

	/**
	 * Register local same-origin routes used by the e2e matrix.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'faz-e2e/v1',
			'/collect/(?P<path>.+)',
			array(
				array(
					'methods'             => \WP_REST_Server::ALLMETHODS,
					'callback'            => array( $this, 'collect_hit' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			'faz-e2e/v1',
			'/script/(?P<path>.+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'serve_script' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Serve raw JS from a same-origin pseudo-path so browser requests execute it.
	 *
	 * @return void
	 */
	public function maybe_serve_script( $wp = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		$provider    = isset( $_GET['provider'] ) ? sanitize_key( (string) wp_unslash( $_GET['provider'] ) ) : '';

		if ( false !== strpos( $request_uri, '/faz-e2e-provider-collect/' ) ) {
			$this->serve_raw_collect( $request_uri );
		}

		if ( empty( $provider ) || false === strpos( $request_uri, '/faz-e2e-provider-script/' ) ) {
			return;
		}

		$payload = $this->build_script_payload( $provider );
		if ( '' === $payload ) {
			status_header( 404 );
			header( 'Content-Type: application/javascript; charset=utf-8' );
			echo '/** Unknown FAZ matrix provider. */';
			exit;
		}

		status_header( 200 );
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Serve a raw same-origin collector endpoint with the provider in the path.
	 *
	 * @param string $request_uri Current request URI.
	 * @return void
	 */
	private function serve_raw_collect( $request_uri ) {
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$key  = is_string( $path ) ? substr( $path, strpos( $path, '/faz-e2e-provider-collect/' ) + 25 ) : '';
		$key  = ltrim( (string) $key, '/' );

		$hits = get_option( self::HITS_OPTION, array() );
		if ( ! is_array( $hits ) ) {
			$hits = array();
		}

		if ( '' !== $key ) {
			$hits[ $key ] = isset( $hits[ $key ] ) ? absint( $hits[ $key ] ) + 1 : 1;
			update_option( self::HITS_OPTION, $hits, false );
		}

		status_header( 204 );
		header( 'Cache-Control: no-store, max-age=0' );
		exit;
	}

	/**
	 * Record a matrix network hit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function collect_hit( $request ) {
		$path = sanitize_text_field( (string) $request->get_param( 'path' ) );
		$hits = get_option( self::HITS_OPTION, array() );

		if ( ! is_array( $hits ) ) {
			$hits = array();
		}

		$hits[ $path ] = isset( $hits[ $path ] ) ? absint( $hits[ $path ] ) + 1 : 1;
		update_option( self::HITS_OPTION, $hits, false );

		return new \WP_REST_Response(
			array(
				'hits' => $hits[ $path ],
				'ok'   => true,
				'path' => $path,
			),
			200
		);
	}

	/**
	 * Serve a same-origin JS payload whose path contains provider signatures.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function serve_script( $request ) {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$payload  = $this->build_script_payload( $provider );

		if ( '' === $payload ) {
			return new \WP_REST_Response( '/** Unknown FAZ matrix provider. */', 404, array( 'Content-Type' => 'application/javascript; charset=utf-8' ) );
		}

		return new \WP_REST_Response(
			$payload,
			200,
			array(
				'Cache-Control' => 'no-store, max-age=0',
				'Content-Type'  => 'application/javascript; charset=utf-8',
			)
		);
	}

	/**
	 * Render matrix resources on the dedicated page and optional Woo checkout hooks.
	 *
	 * @return void
	 */
	public function render_matrix() {
		if ( $this->is_matrix_page() ) {
			$this->print_active_scripts();
			$this->print_scan_only_signals();

			if ( 'yes' === get_option( self::CUSTOM_OPTION, 'no' ) ) {
				$this->print_script_src(
					$this->build_script_url( 'custom-unknown', 'faz-lab-custom-provider.js' ),
					'custom-unknown'
				);
				$this->print_script_src(
					$this->build_script_url( 'custom-functional', 'faz-lab-custom-functional.js' ),
					'custom-functional'
				);
			}

			return;
		}

		if ( 'yes' === get_option( self::WOO_OPTION, 'no' ) && $this->is_wc_checkout_or_cart() ) {
			$this->print_script_src(
				$this->build_script_url( 'stripe', 'js.stripe.com/v3/stripe-checkout.js' ),
				'woo-stripe'
			);
		}
	}

	/**
	 * Print the active same-origin provider scripts.
	 *
	 * @return void
	 */
	private function print_active_scripts() {
		$active = array(
			'ga-monsterinsights' => 'googletagmanager.com/gtag/js/monsterinsights-frontend-script.js',
			'facebook-pixel'     => 'connect.facebook.net/en_US/fbevents.js/facebook-for-woocommerce.js',
			'microsoft-ads'      => 'bat.bing.com/bat.js',
			'clarity'            => 'clarity.ms/tag/faz-matrix.js',
			'hotjar'             => 'static.hotjar.com/c/hotjar.js',
			'linkedin'           => 'snap.licdn.com/li.lms-analytics/insight.min.js',
			'stripe'             => 'js.stripe.com/v3/stripe.js',
			'mixpanel'           => 'cdn.mxpnl.com/libs/mixpanel-2-latest.min.js',
			'hubspot'            => 'js.hs-scripts.com/12345.js',
			'twitter'            => 'platform.twitter.com/widgets.js',
			'tiktok'             => 'analytics.tiktok.com/i18n/pixel/events.js',
			'pinterest'          => 'assets.pinterest.com/js/pinit.js',
			'snapchat'           => 'sc-static.net/scevent.min.js',
		);

		foreach ( $active as $provider => $path ) {
			$this->print_script_src( $this->build_script_url( $provider, $path ), $provider );
		}
	}

	/**
	 * Print additional scan-only dormant tags that the scanner should still detect.
	 *
	 * @return void
	 */
	private function print_scan_only_signals() {
		$dormant = array(
			array(
				'attr' => 'data-src',
				'tag'  => 'script',
				'url'  => home_url( '/wp-content/plugins/exactmetrics/assets/js/frontend.js?exactmetrics-frontend-script=1' ),
			),
			array(
				'attr' => 'data-src',
				'tag'  => 'script',
				'url'  => home_url( '/wp-content/plugins/gtm4wp/container-code.js' ),
			),
			array(
				'attr' => 'data-litespeed-src',
				'tag'  => 'script',
				'url'  => home_url( '/wp-content/plugins/pixel-caffeine/build/frontend.js' ),
			),
			array(
				'attr' => 'data-src',
				'tag'  => 'script',
				'url'  => 'https://securepubads.g.doubleclick.net/tag/js/gpt.js',
			),
			array(
				'attr' => 'data-src',
				'tag'  => 'iframe',
				'url'  => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
			),
		);

		foreach ( $dormant as $entry ) {
			if ( 'iframe' === $entry['tag'] ) {
				printf(
					'<iframe title="%1$s" %2$s="%3$s" style="display:none"></iframe>' . "\n",
					esc_attr__( 'FAZ Matrix Provider', 'faz-cookie-manager' ),
					esc_attr( $entry['attr'] ),
					esc_url( $entry['url'] )
				);
				continue;
			}

			printf(
				'<script %1$s="%2$s"></script>' . "\n",
				esc_attr( $entry['attr'] ),
				esc_url( $entry['url'] )
			);
		}
	}

	/**
	 * Print a single same-origin script tag.
	 *
	 * @param string $url Script URL.
	 * @param string $id  Provider ID for diagnostics.
	 * @return void
	 */
	private function print_script_src( $url, $id ) {
		$extra_attrs = '';
		$catalog     = $this->provider_catalog();

		if ( $this->is_scan_mode() && isset( $catalog[ $id ]['category'] ) ) {
			$extra_attrs .= ' data-fazcookie="fazcookie-' . esc_attr( $catalog[ $id ]['category'] ) . '"';
		}

		printf(
			'<script src="%1$s" data-faz-matrix-provider="%2$s" defer%3$s></script>' . "\n",
			esc_url( $url ),
			esc_attr( $id ),
			$extra_attrs
		);
	}

	/**
	 * Build a same-origin script URL whose path contains provider patterns.
	 *
	 * @param string $provider Provider key.
	 * @param string $path     Signature-bearing path fragment.
	 * @return string
	 */
	private function build_script_url( $provider, $path ) {
		return add_query_arg(
			'provider',
			rawurlencode( $provider ),
			home_url( '/faz-e2e-provider-script/' . ltrim( $path, '/' ) )
		);
	}

	/**
	 * Build the raw JS payload for a provider.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	private function build_script_payload( $provider ) {
		$catalog = $this->provider_catalog();

		if ( empty( $provider ) || ! isset( $catalog[ $provider ] ) ) {
			return '';
		}

		$entry      = $catalog[ $provider ];
		$cookie_js  = '';
		$cookie_set = isset( $entry['cookie'] ) ? (array) $entry['cookie'] : array();

		foreach ( $cookie_set as $cookie_name ) {
			$cookie_js .= 'document.cookie=' . wp_json_encode( $cookie_name . '=1; path=/; SameSite=Lax' ) . ';';
		}

		$collect_url = home_url( '/faz-e2e-provider-collect/' . rawurlencode( $provider ) );
		$collect_js  = 'if ( window.location.search.indexOf("faz_scanning=1") === -1 ) { fetch(' . wp_json_encode( $collect_url ) . ', { method: "POST", credentials: "same-origin" }).catch(function () {}); }';

		return "(function(){window.__fazProviderMatrixLoaded=window.__fazProviderMatrixLoaded||{};if(window.__fazProviderMatrixLoaded[" . wp_json_encode( $provider ) . "]){return;}window.__fazProviderMatrixLoaded[" . wp_json_encode( $provider ) . "]=true;" . $cookie_js . $collect_js . '}());';
	}

	/**
	 * Provider catalog used by the local script route.
	 *
	 * @return array
	 */
	private function provider_catalog() {
		return array(
			'ga-monsterinsights' => array(
				'category' => 'analytics',
				'cookie'   => array( '_ga', '_gid' ),
			),
			'facebook-pixel'     => array(
				'category' => 'marketing',
				'cookie'   => array( '_fbp', '_fbc' ),
			),
			'microsoft-ads'      => array(
				'category' => 'marketing',
				'cookie'   => array( '_uetsid', '_uetvid', 'MUID' ),
			),
			'clarity'            => array(
				'category' => 'analytics',
				'cookie'   => array( '_clck', '_clsk' ),
			),
			'hotjar'             => array(
				'category' => 'analytics',
				'cookie'   => array( '_hjSessionUser_123', '_hjSession_123' ),
			),
			'linkedin'           => array(
				'category' => 'marketing',
				'cookie'   => array( 'li_sugr', 'bcookie', 'lidc' ),
			),
			'stripe'             => array(
				'category' => 'functional',
				'cookie'   => array( '__stripe_mid', '__stripe_sid' ),
			),
			'mixpanel'           => array(
				'category' => 'analytics',
				'cookie'   => array( 'distinct_id' ),
			),
			'hubspot'            => array(
				'category' => 'marketing',
				'cookie'   => array( 'hubspotutk', '__hssc', '__hssrc', '__hstc' ),
			),
			'twitter'            => array(
				'category' => 'marketing',
				'cookie'   => array( 'guest_id', 'personalization_id' ),
			),
			'tiktok'             => array(
				'category' => 'marketing',
				'cookie'   => array( '_ttp', 'tt_webid' ),
			),
			'pinterest'          => array(
				'category' => 'marketing',
				'cookie'   => array( '_pin_unauth' ),
			),
			'snapchat'           => array(
				'category' => 'marketing',
				'cookie'   => array( '_scid', 'sc_at' ),
			),
			'custom-unknown'     => array(
				'category' => 'performance',
				'cookie'   => array( '_faz_custom_provider' ),
			),
			'custom-functional'  => array(
				'category' => 'functional',
				'cookie'   => array( '_faz_custom_functional' ),
			),
		);
	}

	/**
	 * Detect the dedicated provider matrix page.
	 *
	 * @return bool
	 */
	private function is_matrix_page() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		return $post && self::PAGE_SLUG === $post->post_name;
	}

	/**
	 * Detect the scanner iframe bypass mode.
	 *
	 * @return bool
	 */
	private function is_scan_mode() {
		return isset( $_GET['faz_scanning'] ) && '1' === $_GET['faz_scanning'] && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Detect Woo cart/checkout pages safely.
	 *
	 * @return bool
	 */
	private function is_wc_checkout_or_cart() {
		return ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() );
	}
}

new Faz_E2E_Provider_Matrix();
