<?php
/**
 * Footer legal links.
 *
 * Renders an optional, opt-in <nav> of legal pages (Cookie Policy, Privacy
 * Policy, Imprint, Terms, …) on `wp_footer`. Off by default.
 *
 * @package FazCookie\Frontend\Modules\Legal_Links
 * @since   1.26.0
 */

namespace FazCookie\Frontend\Modules\Legal_Links;

use FazCookie\Admin\Modules\Settings\Includes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Prints the footer legal-links navigation.
 *
 * @class   Legal_Links
 * @since   1.26.0
 * @package FazCookie\Frontend\Modules\Legal_Links
 */
class Legal_Links {

	/**
	 * Maximum links rendered. Mirrors the cap enforced by
	 * Settings::sanitize_option( 'link_items' ) — kept here as a second, purely
	 * defensive bound so a settings row written before the cap existed (or by a
	 * direct DB edit) can never print an unbounded list into every cached page.
	 *
	 * @var int
	 */
	const MAX_LINKS = 20;

	/**
	 * Constructor. Wires the footer output and the stylesheet.
	 */
	public function __construct() {
		// Priority 20 puts the nav AFTER Frontend::banner_html(), which runs at
		// the default 10 — several E2E selectors and the banner's own DOM
		// assumptions depend on the consent container's position in the footer.
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
		// The stylesheet MUST be enqueued from wp_enqueue_scripts: by the time
		// render() runs on wp_footer, wp_head has long been printed and a late
		// wp_enqueue_style() would either be dropped or emit a <link> in <body>.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_styles' ) );
	}

	/**
	 * `wp_footer` callback.
	 *
	 * @return void
	 */
	public function render() {
		// build_html() escapes every interpolated value at the point of
		// concatenation (esc_url / esc_html / esc_attr__), and the surrounding
		// markup is a literal — nothing here is unescaped.
		echo $this->build_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the legal-links markup.
	 *
	 * HARD RULE — this method must read NOTHING request-dependent: no $_COOKIE,
	 * no consent store, no geo lookup, no is_user_logged_in() branch. The output
	 * is a pure function of the stored option plus each referenced page's
	 * publish state, which is exactly what keeps Cache Compatibility Mode's
	 * "one cached variant per URL" promise intact. That mode only shipped in
	 * 1.21.0 once every render path had been made invariant; this must not
	 * become the first exception.
	 *
	 * Skipping unpublished pages at RENDER time (rather than pruning the stored
	 * list on save) means unpublishing a page removes its link immediately,
	 * without the admin having to revisit the Settings screen.
	 *
	 * @param array|null $config Optional settings group, injected by the unit
	 *                           tests so the Settings/Store stack is not needed.
	 *                           Null reads the live settings.
	 * @return string HTML, or '' when nothing should be rendered.
	 */
	public function build_html( $config = null ): string {
		if ( ! is_array( $config ) ) {
			$config = Settings::get_instance()->get( 'legal_links' );
		}
		if ( empty( $config['enabled'] ) ) {
			return '';
		}
		if ( empty( $config['link_items'] ) || ! is_array( $config['link_items'] ) ) {
			return '';
		}

		$items = array();
		foreach ( $config['link_items'] as $item ) {
			if ( count( $items ) >= self::MAX_LINKS ) {
				break;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			// (int), not absint(), for the same reason as the sanitiser: absint(-5)
			// is 5, and a malformed ID must not resolve to a different real page.
			$page_id = (int) ( isset( $item['page_id'] ) ? $item['page_id'] : 0 );
			if ( $page_id < 1 ) {
				continue;
			}
			$post = get_post( $page_id );
			if ( ! $post || 'publish' !== get_post_status( $post ) ) {
				continue;
			}
			$url = get_permalink( $post );
			if ( empty( $url ) ) {
				continue;
			}
			$label = trim( (string) ( isset( $item['label'] ) ? $item['label'] : '' ) );
			if ( '' === $label ) {
				// get_the_title() rather than $post->post_title so a translation
				// plugin can localise the label. That varies per URL (WPML /
				// Polylang serve each language on its own URL), never per visitor,
				// so the one-variant-per-URL guarantee still holds.
				$label = trim( (string) get_the_title( $post ) );
			}
			// A published page with no title and no custom label would render an
			// empty, unlabelled link — worse than no link at all for a screen
			// reader, so it is dropped.
			if ( '' === $label ) {
				continue;
			}
			$items[] = '<li class="faz-legal-links-item"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}

		// Every configured page was unpublished, deleted or unlabelled: emit
		// nothing rather than an empty <nav>, which would be a landmark with no
		// content for assistive technology.
		if ( empty( $items ) ) {
			return '';
		}

		return '<nav class="faz-legal-links" aria-label="' . esc_attr__( 'Legal information', 'faz-cookie-manager' ) . '">'
			. '<ul class="faz-legal-links-list">' . implode( '', $items ) . '</ul></nav>';
	}

	/**
	 * Enqueue the (tiny) stylesheet, but only when the feature is actually on.
	 *
	 * Mirrors Cookie_Policy_Generator::maybe_enqueue_frontend_assets(). The
	 * enabled/empty check reads the same option the renderer does, so a site
	 * that never turned the feature on ships zero extra bytes.
	 *
	 * @return void
	 */
	public function maybe_enqueue_styles() {
		if ( is_admin() ) {
			return;
		}
		$config = Settings::get_instance()->get( 'legal_links' );
		if ( empty( $config['enabled'] ) || empty( $config['link_items'] ) ) {
			return;
		}
		wp_enqueue_style(
			'faz-legal-links',
			plugins_url( 'frontend/css/faz-legal-links.css', FAZ_PLUGIN_FILENAME ),
			array(),
			defined( 'FAZ_VERSION' ) ? FAZ_VERSION : '1.0.0'
		);
	}
}
