<?php
/**
 * Plugin Name: FAZ E2E Scan Lab
 * Description: Emits deterministic scan fixtures for FAZ Cookie Manager e2e coverage.
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Faz_E2E_Scan_Lab {
	const TOKEN_OPTION = 'faz_e2e_scan_lab_token';
	const HOME_OPTION  = 'faz_e2e_scan_lab_home_enabled';

	public function __construct() {
		// Cache-compatibility tests exercise the explicit emergency stand-down
		// path. The production runtime is default-on and intentionally takes
		// precedence over that legacy mode, so the AJAX request under test needs
		// a request-scoped way to disable it without changing global site state.
		if ( isset( $_GET['faz_e2e_disable_geo_runtime'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'faz_geo_ruleset_runtime', '__return_false', PHP_INT_MAX );
		}
		add_action( 'send_headers', array( $this, 'send_header_cookie' ) );
		add_action( 'wp_head', array( $this, 'render_page_signals' ), 1 );
		add_action( 'wp_footer', array( $this, 'render_page_signals' ), 100 );
		add_action( 'wp_ajax_faz_e2e_scan_ajax_cookie', array( $this, 'send_ajax_cookie' ) );
		add_action( 'wp_ajax_nopriv_faz_e2e_scan_ajax_cookie', array( $this, 'send_ajax_cookie' ) );
	}

	private function token() {
		$token = sanitize_key( (string) get_option( self::TOKEN_OPTION, 'default' ) );
		return '' !== $token ? $token : 'default';
	}

	private function scenario() {
		if ( ! is_singular() ) {
			return '';
		}

		$post = get_post();
		if ( ! $post || empty( $post->post_name ) ) {
			return '';
		}

		if ( 0 !== strpos( $post->post_name, 'faz-lab-' ) ) {
			return '';
		}

		// A trailing -NNN is an instance counter, not part of the scenario
		// name: WordPress slugs are unique, so a scenario that must run on
		// many pages at once (the long-crawl spec needs one PHP Set-Cookie
		// emitter per sampled page) can only be seeded as faz-lab-headers-000,
		// -001, … Stripping the counter keeps every one of them on the
		// 'headers' branch. Unsuffixed slugs are unaffected: 'faz-lab-headers'
		// still yields exactly 'headers'.
		return (string) preg_replace( '/-\d+$/', '', substr( $post->post_name, 8 ) );
	}

	private function print_js_cookie( $name, $delay_ms = 0 ) {
		$name = esc_js( $name );
		$code = "document.cookie='{$name}=1; path=/; SameSite=Lax';";
		if ( $delay_ms > 0 ) {
			$code = "setTimeout(function(){ {$code} }, {$delay_ms});";
		}
		printf( "<script>%s</script>\n", $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function print_script_tag( $attribute, $src ) {
		printf(
			'<script %1$s="%2$s"></script>' . "\n",
			esc_attr( $attribute ),
			esc_url( $src )
		);
	}

	public function send_header_cookie() {
		if ( 'headers' !== $this->scenario() ) {
			return;
		}

		header(
			sprintf(
				'Set-Cookie: %s=1; Path=/; HttpOnly; SameSite=Lax',
				sanitize_key( '_faz_lab_http_' . $this->token() )
			),
			false
		);
	}

	/** Emit the real-world regression cookie from a delayed PHP AJAX response. */
	public function send_ajax_cookie() {
		$necessary = isset( $_GET['necessary'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['necessary'] ) );
		$name      = $necessary ? 'wp_woocommerce_session_fazlab' : 'brikpanel_vid';
		$value     = $necessary ? 'cart-session' : 'private-visitor-id';
		header(
			sprintf(
				'Set-Cookie: %1$s=%2$s; Path=/; Max-Age=31536000; HttpOnly; SameSite=Lax',
				$name,
				$value
			),
			false
		);
		wp_send_json_success( array( 'cookie' => $name ) );
	}

	public function render_page_signals() {
		$scenario = $this->scenario();

		if ( is_front_page() && 'yes' === get_option( self::HOME_OPTION, 'no' ) ) {
			$this->print_script_tag( 'data-src', 'https://www.googletagmanager.com/gtag/js?id=G-FAZHOME' );
		}

		if ( '' === $scenario ) {
			return;
		}

		$token = $this->token();

		switch ( $scenario ) {
			case 'js-basic':
				$this->print_js_cookie( '_faz_lab_js_basic_' . $token );
				break;
			case 'js-delayed':
				$this->print_js_cookie( '_faz_lab_js_delayed_' . $token, 500 );
				break;
			case 'ajax-httponly':
				?>
				<script>
				if (!window.__fazAjaxHttpOnlyLabStarted) {
					window.__fazAjaxHttpOnlyLabStarted = true;
					setTimeout(function () {
						fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php?action=faz_e2e_scan_ajax_cookie' ) ); ?>, { credentials: 'same-origin' });
					}, 350);
				}
				</script>
				<?php
				break;
			case 'js-dupe-a':
			case 'js-dupe-b':
				$this->print_js_cookie( '_faz_lab_dupe_' . $token );
				break;
			case 'script-src-ga':
				$this->print_script_tag( 'src', 'https://www.googletagmanager.com/gtag/js?id=G-FAZLAB' );
				break;
			case 'script-data-src-ga':
				$this->print_script_tag( 'data-src', 'https://www.googletagmanager.com/gtag/js?id=G-FAZLAB' );
				break;
			case 'script-litespeed-fb':
				$this->print_script_tag( 'data-litespeed-src', 'https://connect.facebook.net/en_US/fbevents.js' );
				break;
			case 'iframe-youtube':
				echo '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="FAZ lab"></iframe>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'script-src-facebook':
				$this->print_script_tag( 'src', 'https://connect.facebook.net/en_US/fbevents.js' );
				break;
		}
	}
}

new Faz_E2E_Scan_Lab();
