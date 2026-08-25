<?php
/**
 * Plugin Name: FAZ E2E Woo Lab
 * Description: Emits deterministic cookies on WooCommerce pages for scanner e2e coverage.
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Faz_E2E_Woo_Lab {
	const ENABLE_OPTION    = 'faz_e2e_woo_lab_enabled';
	const TOKEN_OPTION     = 'faz_e2e_woo_lab_token';
	const LOOKALIKE_OPTION = 'faz_e2e_woo_lab_lookalike';

	public function __construct() {
		add_action( 'wp_footer', array( $this, 'render_woo_signals' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_lookalike_id_script' ) );
		add_action( 'wp_footer', array( $this, 'render_lookalike_class_script' ), 99 );
	}

	/**
	 * Whether the strictly-necessary look-alike emitters are armed.
	 *
	 * Gated by its own option so the look-alikes only exist for the spec that
	 * asserts on them and never perturb the other suites.
	 */
	private function lookalikes_enabled() {
		return 'yes' === get_option( self::LOOKALIKE_OPTION, 'no' );
	}

	/**
	 * ID vector: handle `wc-settings-tracker` renders id="wc-settings-tracker-js".
	 *
	 * The src deliberately carries no `wc-settings` fragment, so the only thing
	 * that can exempt this tag from consent is a whitelist entry matching the
	 * rendered ID by token prefix. It is NOT one of the three real strictly
	 * necessary handles, so it must stay blockable.
	 */
	public function enqueue_lookalike_id_script() {
		if ( ! $this->lookalikes_enabled() ) {
			return;
		}
		wp_enqueue_script(
			'wc-settings-tracker',
			home_url( '/faz-e2e/lookalike/id-vector.js' ),
			array(),
			'1.0.0',
			true
		);
	}

	/**
	 * Class vector: class="wc-settings-tracker" on an otherwise blockable tag.
	 *
	 * Here the src carries the `wc-settings` fragment (slash-delimited so the
	 * provider matcher's boundary check accepts it), so the tag is provider
	 * matchable; only a whitelist hit on the CLASS attribute can exempt it.
	 */
	public function render_lookalike_class_script() {
		if ( ! $this->lookalikes_enabled() ) {
			return;
		}
		printf(
			'<script class="wc-settings-tracker" src="%s"></script>' . "\n",
			esc_url( home_url( '/faz-e2e/lookalike/wc-settings/class-vector.js' ) )
		);
	}

	private function enabled() {
		return class_exists( 'WooCommerce' ) && 'yes' === get_option( self::ENABLE_OPTION, 'no' );
	}

	private function token() {
		$token = sanitize_key( (string) get_option( self::TOKEN_OPTION, 'default' ) );
		return '' !== $token ? $token : 'default';
	}

	private function emit_js_cookie( $name ) {
		printf(
			"<script>document.cookie='%s=1; path=/; SameSite=Lax';</script>\n",
			esc_js( $name )
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function matches_page( $key ) {
		$page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $key ) : 0;
		return $page_id > 0 && is_page( $page_id );
	}

	public function render_woo_signals() {
		if ( ! $this->enabled() ) {
			return;
		}

		$token = $this->token();

		if ( ( function_exists( 'is_shop' ) && is_shop() ) || $this->matches_page( 'shop' ) ) {
			$this->emit_js_cookie( '_faz_lab_wc_shop_' . $token );
		}
		if ( ( function_exists( 'is_product' ) && is_product() ) || is_singular( 'product' ) ) {
			$this->emit_js_cookie( '_faz_lab_wc_product_' . $token );
		}
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || $this->matches_page( 'cart' ) ) {
			$this->emit_js_cookie( '_faz_lab_wc_cart_' . $token );
		}
		if ( ( function_exists( 'is_checkout' ) && is_checkout() ) || $this->matches_page( 'checkout' ) ) {
			$this->emit_js_cookie( '_faz_lab_wc_checkout_' . $token );
			echo '<script src="https://js.stripe.com/v3/"></script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ( function_exists( 'is_account_page' ) && is_account_page() ) || $this->matches_page( 'myaccount' ) ) {
			$this->emit_js_cookie( '_faz_lab_wc_account_' . $token );
			echo '<script src="https://www.google.com/recaptcha/api.js"></script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}

new Faz_E2E_Woo_Lab();
