<?php
/**
 * Gutenberg blocks registration and rendering.
 *
 * Provides three server-side rendered blocks that wrap existing shortcodes:
 *  - faz/cookie-table   (wraps [faz_cookie_table])
 *  - faz/cookie-policy  (wraps [faz_cookie_policy])
 *  - faz/consent-button (standalone manage-consent button)
 *
 * @package FazCookie\Includes\Blocks
 */

namespace FazCookie\Includes\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Blocks {

	/**
	 * Hook into WordPress init to register blocks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register all Gutenberg blocks and the shared editor script.
	 *
	 * @return void
	 */
	public function register_blocks() {
		// Block 1: Cookie Table.
		register_block_type( 'faz/cookie-table', array(
			'api_version'     => 3,
			'title'           => __( 'Cookie Table', 'faz-cookie-manager' ),
			'description'     => __( 'Display a table of all cookies used on the site.', 'faz-cookie-manager' ),
			'category'        => 'widgets',
			'icon'            => 'editor-table',
			'keywords'        => array( 'cookie', 'gdpr', 'privacy', 'table' ),
			'attributes'      => array(
				'columns'  => array(
					'type'    => 'string',
					'default' => 'name,domain,duration,description,category',
				),
				'category' => array(
					'type'    => 'string',
					'default' => '',
				),
				'heading'  => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'render_callback' => array( $this, 'render_cookie_table' ),
			'editor_script'   => 'faz-blocks-editor',
		) );

		// Block 2: Cookie Policy.
		register_block_type( 'faz/cookie-policy', array(
			'api_version'     => 3,
			'title'           => __( 'Cookie Policy', 'faz-cookie-manager' ),
			'description'     => __( 'Display an auto-generated cookie policy page.', 'faz-cookie-manager' ),
			'category'        => 'widgets',
			'icon'            => 'privacy',
			'keywords'        => array( 'cookie', 'policy', 'gdpr', 'privacy' ),
			'attributes'      => array(
				'show_table' => array(
					'type'    => 'string',
					'default' => 'yes',
				),
				'site_name'  => array(
					'type'    => 'string',
					'default' => '',
				),
				'contact'    => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'render_callback' => array( $this, 'render_cookie_policy' ),
			'editor_script'   => 'faz-blocks-editor',
		) );

		// Block 3: Manage Consent Button.
		register_block_type( 'faz/consent-button', array(
			'api_version'     => 3,
			'title'           => __( 'Manage Cookies Button', 'faz-cookie-manager' ),
			'description'     => __( 'A button that opens the cookie consent preferences.', 'faz-cookie-manager' ),
			'category'        => 'widgets',
			'icon'            => 'shield',
			'keywords'        => array( 'cookie', 'consent', 'manage', 'preferences', 'gdpr' ),
			'attributes'      => array(
				'label' => array(
					'type'    => 'string',
					'default' => '',
				),
				'style' => array(
					'type'    => 'string',
					'default' => 'button',
				),
			),
			'render_callback' => array( $this, 'render_consent_button' ),
			'editor_script'   => 'faz-blocks-editor',
		) );

		// Register the shared editor script (no build step needed).
		wp_register_script(
			'faz-blocks-editor',
			plugins_url( 'editor.js', __FILE__ ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			FAZ_VERSION,
			true
		);
	}

	/**
	 * Render callback for the Cookie Table block.
	 *
	 * @param array $atts Block attributes.
	 * @return string HTML output.
	 */
	public function render_cookie_table( $atts ) {
		$shortcode_atts = '';
		if ( ! empty( $atts['columns'] ) ) {
			$shortcode_atts .= ' columns="' . esc_attr( $atts['columns'] ) . '"';
		}
		if ( ! empty( $atts['category'] ) ) {
			$shortcode_atts .= ' category="' . esc_attr( $atts['category'] ) . '"';
		}
		if ( ! empty( $atts['heading'] ) ) {
			$shortcode_atts .= ' heading="' . esc_attr( $atts['heading'] ) . '"';
		}
		return do_shortcode( '[faz_cookie_table' . $shortcode_atts . ']' );
	}

	/**
	 * Render callback for the Cookie Policy block.
	 *
	 * @param array $atts Block attributes.
	 * @return string HTML output.
	 */
	public function render_cookie_policy( $atts ) {
		$shortcode_atts = '';
		if ( ! empty( $atts['show_table'] ) ) {
			$shortcode_atts .= ' show_table="' . esc_attr( $atts['show_table'] ) . '"';
		}
		if ( ! empty( $atts['site_name'] ) ) {
			$shortcode_atts .= ' site_name="' . esc_attr( $atts['site_name'] ) . '"';
		}
		if ( ! empty( $atts['contact'] ) ) {
			$shortcode_atts .= ' contact="' . esc_attr( $atts['contact'] ) . '"';
		}
		return do_shortcode( '[faz_cookie_policy' . $shortcode_atts . ']' );
	}

	/**
	 * Render callback for the Manage Consent Button block.
	 *
	 * @param array $atts Block attributes.
	 * @return string HTML output.
	 */
	public function render_consent_button( $atts ) {
		$label   = ! empty( $atts['label'] ) ? esc_html( $atts['label'] ) : esc_html__( 'Manage Cookie Preferences', 'faz-cookie-manager' );
		$is_link = 'link' === ( isset( $atts['style'] ) ? $atts['style'] : 'button' );

		if ( $is_link ) {
			return '<a href="#" class="faz-consent-trigger">' . $label . '</a>';
		}
		return '<button type="button" class="faz-consent-trigger wp-element-button">' . $label . '</button>';
	}
}
