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
	 * `_complete` shortcode is jurisdiction-aware (GDPR / CCPA / LGPD / POPIA) and
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
		// Policy CSS: in <head> where the shortcode is known, else from the callback.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_assets' ) );
	}

	/**
	 * Load the policy CSS in <head> on the pages that show the policy.
	 *
	 * It used to be enqueued on every frontend page, because the shortcode
	 * also lives in widgets, blocks, builder elements and template parts that
	 * `has_shortcode( $post->post_content, ... )` cannot see. That made a
	 * render-blocking ~8 KB stylesheet part of every page of every site to
	 * style one page (#308). Now:
	 *   - a singular page whose content holds the shortcode (the page the
	 *     setup wizard creates, or any page the owner pasted it into) gets it
	 *     in <head>, so the policy never paints unstyled;
	 *   - every other placement gets it from render_shortcode(), which
	 *     WordPress prints with the footer scripts;
	 *   - `faz_load_shortcode_assets_everywhere` restores the old behaviour
	 *     for builders that inject the policy client-side.
	 *
	 * @return void
	 */
	public function maybe_enqueue_frontend_assets() {
		if ( is_admin() ) {
			return;
		}
		if ( faz_load_shortcode_assets_everywhere( 'cookie_policy' ) || $this->queried_post_has_shortcode() ) {
			$this->enqueue_frontend_style();
		}
	}

	/**
	 * Whether the main queried post's content carries the policy shortcode.
	 *
	 * @return bool
	 */
	private function queried_post_has_shortcode() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post
			&& is_string( $post->post_content )
			&& has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Enqueue the policy stylesheet. Safe to call more than once.
	 *
	 * @return void
	 */
	private function enqueue_frontend_style() {
		wp_enqueue_style(
			'faz-cookie-policy',
			// Not plugins_url(), which resolves its scheme through is_ssl() and so
			// emitted http:// behind a TLS-terminating proxy — mixed content on the
			// public cookie-policy page. FAZ_PLUGIN_URL already carries the
			// three-signal scheme.
			FAZ_PLUGIN_URL . 'frontend/css/faz-cookie-policy.css',
			array(),
			defined( 'FAZ_VERSION' ) ? FAZ_VERSION : '1.0.0'
		);
	}

	/**
	 * `[faz_cookie_policy_complete]` shortcode callback.
	 *
	 * Attributes:
	 *   - lang         (any valid BCP-47 code) — override visitor locale; when
	 *                    no template ships, the jurisdiction fallback is used
	 *   - jurisdiction (gdpr-strict, ccpa-california, lgpd-brazil, popia-southafrica)
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
		// Already in <head> on the pages maybe_enqueue_frontend_assets()
		// recognises; anywhere else WordPress prints it with the footer.
		if ( ! is_admin() ) {
			$this->enqueue_frontend_style();
		}
		return Renderer::render( $atts );
	}
}
