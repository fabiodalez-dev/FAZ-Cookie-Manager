<?php
/**
 * Module bootstrap for Cookie Policy Generator (Spec 002).
 *
 * Singleton; registers:
 *  - the `[faz_cookie_policy]` shortcode (frontend, FR-03)
 *  - the admin menu entry "Cookie Policy" (via class-admin.php hook)
 *  - the REST endpoints under faz/v1/cookie-policy/*  (admin form)
 *
 * @package FazCookie\Admin\Modules\Cookie_Policy_Generator
 * @since   1.16.0
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator;

use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Api\Cookie_Policy_Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @class Cookie_Policy_Generator
 * @since 1.16.0
 */
class Cookie_Policy_Generator {

	/**
	 * Shortcode name.
	 *
	 * Deliberately `faz_cookie_policy_v2` (NOT `faz_cookie_policy`) to
	 * coexist with the long-standing `[faz_cookie_policy]` shortcode
	 * defined in includes/class-cookie-policy-shortcode.php. The legacy
	 * shortcode accepts site_name / contact / show_table attributes and
	 * renders a canned five-section policy in the active WP locale; the
	 * v2 shortcode is jurisdiction-aware (GDPR / CCPA / LGPD) and pulls
	 * its data from the admin form (Spec 002). Both stay supported.
	 *
	 * @since 1.16.0
	 */
	const SHORTCODE = 'faz_cookie_policy_v2';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire shortcode + REST API into WordPress.
	 *
	 * Called once from the main plugin bootstrap (faz-cookie-manager.php).
	 *
	 * @return void
	 */
	public function init() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		// Also register the REST endpoints.
		Cookie_Policy_Api::get_instance()->init();
		// Enqueue the frontend CSS only on pages that actually use the shortcode.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_assets' ) );
	}

	/**
	 * Conditional frontend asset enqueue — only when the current post
	 * contains the [faz_cookie_policy] shortcode. The CSS file is tiny
	 * (~30 lines, mostly resets) and inherits everything else from the
	 * host theme; loading it everywhere would be wasted bytes on
	 * 99% of pageviews.
	 *
	 * @return void
	 */
	public function maybe_enqueue_frontend_assets() {
		if ( is_admin() ) {
			return;
		}
		// Only when the global $post actually contains the shortcode. We
		// avoid is_singular()-only checks because the shortcode is valid
		// in arbitrary pages, archives, or even widgets (where $post may
		// not be the visible content).
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}
		if ( ! has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			return;
		}
		wp_enqueue_style(
			'faz-cookie-policy',
			plugins_url( 'frontend/css/faz-cookie-policy.css', FAZ_PLUGIN_FILENAME ),
			array(),
			defined( 'FAZ_VERSION' ) ? FAZ_VERSION : '1.0.0'
		);
	}

	/**
	 * `[faz_cookie_policy]` shortcode callback.
	 *
	 * Attributes:
	 *   - lang         (en, it, fr, de, es, pt-BR) — override visitor locale
	 *   - jurisdiction (gdpr-strict, ccpa-california, lgpd-brazil)
	 *
	 * Both are optional. Without them the renderer falls back to WP get_locale
	 * + admin default jurisdiction.
	 *
	 * @param array<string,string> $atts Raw attributes from WP shortcode parser.
	 * @return string HTML.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'lang'         => '',
				'jurisdiction' => '',
			),
			(array) $atts,
			self::SHORTCODE
		);
		return Renderer::render( $atts );
	}
}
