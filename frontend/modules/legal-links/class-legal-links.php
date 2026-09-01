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
	 * Memoised build_html() output for the current request.
	 *
	 * Shared by maybe_enqueue_styles() (wp_enqueue_scripts) and render()
	 * (wp_footer) so both agree on whether anything will actually be printed,
	 * and so the page/permalink lookups happen once per request rather than
	 * twice. Null means "not built yet"; '' is a real, cached "renders nothing".
	 *
	 * Only ever holds the result of the live-settings build. build_html() with
	 * an injected $config (the unit tests) deliberately bypasses this.
	 *
	 * @var string|null
	 */
	private $html = null;

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
		echo $this->get_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * build_html() against the live settings, built at most once per request.
	 *
	 * @return string
	 */
	private function get_html(): string {
		if ( null === $this->html ) {
			$this->html = $this->build_html();
		}
		return $this->html;
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
	 * Mirrors Cookie_Policy_Generator::maybe_enqueue_frontend_assets(), but
	 * gates on the ACTUAL rendered output rather than on enabled/link_items:
	 * a list whose every page has since been deleted, unpublished or left
	 * unlabelled is still "enabled and non-empty" while rendering nothing, and
	 * would otherwise ship a stylesheet for markup that never appears.
	 *
	 * Building here rather than at wp_footer costs nothing extra — the result is
	 * memoised and render() reuses it, so the page lookups happen once either
	 * way — and a site that never turned the feature on still short-circuits on
	 * the enabled flag before touching a single page.
	 *
	 * @return void
	 */
	public function maybe_enqueue_styles() {
		if ( is_admin() ) {
			return;
		}
		if ( '' === $this->get_html() ) {
			return;
		}
		wp_enqueue_style(
			'faz-legal-links',
			// FAZ_PLUGIN_URL, not plugins_url(): the latter resolves its scheme
			// through is_ssl(), which is false behind a TLS-terminating proxy —
			// mixed content on a public page. The seventh such call site; the
			// first sweep measured a page where this feature was inactive, so it
			// looked complete.
			// defined() guard, matching class-cookie-table-shortcode.php:333: the
			// standalone unit harness loads this class without the bootstrap that
			// defines the constant. The fallback is the previous behaviour
			// verbatim, so nothing regresses where the constant is absent.
			defined( 'FAZ_PLUGIN_URL' )
				? FAZ_PLUGIN_URL . 'frontend/css/faz-legal-links.css'
				: plugins_url( 'frontend/css/faz-legal-links.css', FAZ_PLUGIN_FILENAME ),
			array(),
			defined( 'FAZ_VERSION' ) ? FAZ_VERSION : '1.0.0'
		);
	}
}
