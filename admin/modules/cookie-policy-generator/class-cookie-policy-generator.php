<?php
/**
 * Module bootstrap for Cookie Policy Generator (Spec 002).
 *
 * Singleton; registers:
 *  - the `[faz_cookie_policy_complete]` shortcode (frontend, FR-03)
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
	 * Deliberately `faz_cookie_policy_complete` (NOT `faz_cookie_policy`) to
	 * coexist with the long-standing `[faz_cookie_policy]` shortcode
	 * defined in includes/class-cookie-policy-shortcode.php. The legacy
	 * shortcode accepts site_name / contact / show_table attributes and
	 * renders a canned five-section policy in the active WP locale; the
	 * `_complete` shortcode is jurisdiction-aware (GDPR / CCPA / LGPD) and
	 * pulls its data from the admin form (Spec 002). Both stay supported —
	 * `_complete` is the human-readable suffix chosen to make the migration
	 * path obvious for operators upgrading from the canned legacy version.
	 *
	 * @since 1.16.0
	 */
	const SHORTCODE = 'faz_cookie_policy_complete';

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
	 * Frontend asset enqueue for the Cookie Policy Generator.
	 *
	 * The shortcode is valid in widgets, blocks, page-builder elements,
	 * and template parts where `has_shortcode($post->post_content, ...)`
	 * cannot see it (Elementor/Bricks/Beaver Builder store their content
	 * outside `post_content`; widget areas live in their own option). To
	 * avoid unstyled policies in those legitimate placements we just
	 * enqueue the CSS on every frontend pageview — the file is tiny
	 * (~30 lines of resets, ~1 KB) and inherits everything else from
	 * the host theme, so the wasted bytes are negligible.
	 *
	 * @return void
	 */
	public function maybe_enqueue_frontend_assets() {
		if ( is_admin() ) {
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
	 * `[faz_cookie_policy_complete]` shortcode callback.
	 *
	 * Attributes:
	 *   - lang         (en, it, fr, de, es, pt-BR, bg, cs) — override visitor locale
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
				// By default the generated policy does NOT render its own H1
				// title: the shortcode is normally placed inside a WordPress
				// page that already has a title ("Cookie Policy"), so emitting
				// another one duplicates it. Set show_title="true" to render the
				// scaffold's leading heading (e.g. for a title-less embed).
				'show_title'   => '',
			),
			(array) $atts,
			self::SHORTCODE
		);
		// The block / visual editor "curls" attribute quotes (lang="it" becomes
		// lang=”it”) and WordPress' shortcode parser keeps the curly quotes as
		// part of the value (”it”), so the language never matched a supported
		// code and the policy silently fell back to the site locale (reported by
		// a user whose [...lang="it"] rendered in English). A language /
		// jurisdiction code only ever contains ASCII letters, digits, hyphens
		// and underscores — the renderer normalises locale-style underscores
		// (pt_BR → pt-BR), so the underscore must survive the cleanup; strip
		// everything else to neutralise smart quotes, straight quotes and stray
		// whitespace regardless of encoding.
		foreach ( array( 'lang', 'jurisdiction' ) as $faz_attr_key ) {
			$atts[ $faz_attr_key ] = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $atts[ $faz_attr_key ] );
		}
		return Renderer::render( $atts );
	}
}
