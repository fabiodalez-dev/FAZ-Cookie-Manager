<?php
/**
 * Handles shortcodes used by the plugin.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie\Includes
 */

namespace FazCookie\Frontend\Modules\Shortcodes;

use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

	/**
	 * Handles shortcodes
	 *
	 * @class       Shortcodes
	 * @version     3.0.0
	 * @package     FazCookie\Frontend\Modules\Shortcodes
	 */
class Shortcodes {

	/**
	 * Banner properties
	 *
	 * @var array
	 */
	protected $properties;

	/**
	 * Banner contents
	 *
	 * @var array
	 */
	protected $contents;

	/**
	 * Banner template
	 *
	 * @var array
	 */
	protected $template;

	/**
	 * Banner default language
	 *
	 * @var string
	 */
	protected $language = 'en';

	/**
	 * Shortcode data, loads based on versions.
	 *
	 * @var array
	 */
	protected $shortcode_data;

	/**
	 * Available shortcodes
	 *
	 * @var array
	 */
	protected $shortcodes;

	/**
	 * Check if connected to web app.
	 *
	 * @var boolean
	 */
	protected $connected;

	/**
	 * Check if preview mode is active.
	 *
	 * @var boolean
	 */
	private $preview = false;

	/**
	 * Check if preview mode is active.
	 *
	 * @var boolean
	 */
	private $law = 'gdpr';

	/**
	 * Default constructor
	 *
	 * @param object  $banner Banner object.
	 * @param boolean $template Banner template.
	 */
	public function __construct( $banner, $template = false ) {
		$contents         = $banner->get_contents();
		$settings         = $banner->get_settings();
		$this->preview    = defined( 'FAZ_PREVIEW_REQUEST' ) && FAZ_PREVIEW_REQUEST;
		$this->connected  = faz_is_cloud_request();
		$this->language   = $banner->get_language();
		$this->template   = $template;
		$this->properties = $settings;
		$this->law        = $banner->get_law();
		$this->contents   = isset( $contents[ $this->language ] ) ? $contents[ $this->language ] : '';
		$this->load_shortcodes();
		$this->init();
	}

	/**
	 * Load shortcodes from a json file
	 *
	 * @return void
	 */
	private function load_shortcodes() {
		$this->shortcodes = faz_read_json_file( dirname( __FILE__ ) . '/versions/' . esc_html( $this->template ) . '/shortcodes.json' );
	}
	/**
	 * Init shortcodes.
	 */
	public function init() {

		$shortcodes = ( isset( $this->shortcodes ) && is_array( $this->shortcodes ) ) ? $this->shortcodes : array();
		if ( empty( $shortcodes ) ) {
			return false;
		}
		foreach ( $shortcodes as $shortcode ) {
			$code = $shortcode['key'];
			if ( method_exists( $this, $code ) ) {
				add_shortcode( apply_filters( "faz_{$code}_shortcode_tag", $code ), array( $this, $code ) );
			}
		}
	}

	/**
	 * Return notice title
	 *
	 * @return string
	 */
	public function faz_notice_title() {
		return isset( $this->contents['notice']['elements']['title'] ) ? $this->contents['notice']['elements']['title'] : '';
	}

	/**
	 * Return notice description
	 *
	 * @return string
	 */
	public function faz_notice_description() {
		return isset( $this->contents['notice']['elements']['description'] ) ? do_shortcode( $this->contents['notice']['elements']['description'] ) : '';
	}

	/**
	 * Return accept button text
	 *
	 * @return string
	 */
	public function faz_accept_text() {
		return isset( $this->contents['notice']['elements']['buttons']['elements']['accept'] ) ? $this->contents['notice']['elements']['buttons']['elements']['accept'] : '';
	}

	/**
	 * Return reject button text
	 *
	 * @return string
	 */
	public function faz_reject_text() {
		return isset( $this->contents['notice']['elements']['buttons']['elements']['reject'] ) ? $this->contents['notice']['elements']['buttons']['elements']['reject'] : '';
	}

	/**
	 * Return settings button text
	 *
	 * @return string
	 */
	public function faz_settings_text() {
		return isset( $this->contents['notice']['elements']['buttons']['elements']['settings'] ) ? $this->contents['notice']['elements']['buttons']['elements']['settings'] : '';
	}

	/**
	 * Return readmore button text
	 *
	 * @return string
	 */
	public function faz_readmore_text() {
		return isset( $this->contents['notice']['elements']['buttons']['elements']['readMore'] ) ? $this->contents['notice']['elements']['buttons']['elements']['readMore'] : '';
	}

	/**
	 * Returns donotsell button text
	 *
	 * @return string
	 */
	public function faz_donotsell_text() {
		return isset( $this->contents['notice']['elements']['buttons']['elements']['donotSell'] ) ? $this->contents['notice']['elements']['buttons']['elements']['donotSell'] : '';
	}

	/**
	 * Preference title
	 *
	 * @return string
	 */
	public function faz_preference_title() {
		return isset( $this->contents['preferenceCenter']['elements']['title'] ) ? $this->contents['preferenceCenter']['elements']['title'] : '';
	}

	/**
	 * Return preference description
	 *
	 * @return string
	 */
	public function faz_preference_description() {
		return isset( $this->contents['preferenceCenter']['elements']['description'] ) ? $this->contents['preferenceCenter']['elements']['description'] : '';
	}

	/**
	 * Return preference accept button text
	 *
	 * @return string
	 */
	public function faz_preference_accept_text() {
		return isset( $this->contents['preferenceCenter']['elements']['buttons']['elements']['accept'] ) ? $this->contents['preferenceCenter']['elements']['buttons']['elements']['accept'] : '';
	}

	/**
	 * Return preference reject button text
	 *
	 * @return string
	 */
	public function faz_preference_reject_text() {
		return isset( $this->contents['preferenceCenter']['elements']['buttons']['elements']['reject'] ) ? $this->contents['preferenceCenter']['elements']['buttons']['elements']['reject'] : '';
	}

	/**
	 * Return preference save button text
	 *
	 * @return string
	 */
	public function faz_preference_save_text() {
		return isset( $this->contents['preferenceCenter']['elements']['buttons']['elements']['save'] ) ? $this->contents['preferenceCenter']['elements']['buttons']['elements']['save'] : '';
	}

	/**
	 * Return preference always enabled text
	 *
	 * @return string
	 */
	public function faz_preference_always_enabled() {
		return isset( $this->contents['preferenceCenter']['elements']['category']['elements']['alwaysEnabled'] ) ? $this->contents['preferenceCenter']['elements']['category']['elements']['alwaysEnabled'] : '';
	}

	/**
	 * Callback for the shortcode [faz_revisit_title]
	 *
	 * @return string
	 */
	public function faz_revisit_title() {
		return isset( $this->contents['revisitConsent']['elements']['title'] ) ? $this->contents['revisitConsent']['elements']['title'] : '';
	}

	/**
	 * Callback for the shortcode [faz_preview_save_text]
	 *
	 * @return string
	 */
	public function faz_preview_save_text() {
		return isset( $this->contents['categoryPreview']['elements']['buttons']['elements']['save'] ) ? $this->contents['categoryPreview']['elements']['buttons']['elements']['save'] : '';
	}
	/**
	 * Return accept button HTML
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function faz_accept( $atts ) {
		return $this->get_btn_html( 'accept-button' );
	}

	/**
	 * Return reject button HTML
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function faz_reject( $atts ) {
		return $this->get_btn_html( 'reject-button' );
	}

	/**
	 * Return settings button HTML
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function faz_settings( $atts ) {
		return $this->get_btn_html( 'settings-button' );
	}

	/**
	 * Return readmore button HTML
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function faz_readmore( $atts ) {
		return $this->get_btn_html( 'readmore-button' );
	}

	/**
	 * Return donotsell button HTML
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function faz_donot_sell( $atts ) {
		return $this->get_btn_html( 'donotsell-button' );
	}

	/**
	 * Return button HTML
	 *
	 * @param string $tag Shortcode tag.
	 * @return string
	 */
	public function get_btn_html( $tag = 'settings-button' ) {

		$config         = faz_array_search( $this->properties['config'], 'tag', $tag );
		$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', $tag );

		if ( false === $shortcode_data ) {
			return '';
		}
		$btn_html = isset( $shortcode_data['content']['button'] ) ? $shortcode_data['content']['button'] : '';
		if ( isset( $config['type'] ) && 'link' === $config['type'] ) {
			$btn_html = isset( $shortcode_data['content']['link'] ) ? wp_kses( $shortcode_data['content']['link'], faz_allowed_html() ) : '';
		}
		return do_shortcode( $btn_html );
	}

	/**
	 * Return preference table HTML
	 *
	 * @return string
	 */
	public function faz_preference_category() {
		$html           = '';
		$categories     = Category_Controller::get_instance()->get_items();
		$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', 'detail-categories' );
		$content        = isset( $shortcode_data['content']['container'] ) ? wp_kses( $shortcode_data['content']['container'], faz_allowed_html() ) : '';

		if ( '' === $content ) {
			return $html;
		}

		foreach ( $categories as $category ) {
			$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $category );
			if ( false === $category->get_visibility() ) {
				continue;
			}
			$cookies = $category->get_cookies();
			if ( empty( $cookies ) ) {
				continue;
			}
			$audit_table = $this->faz_audit_table( $cookies );
			$description = $category->get_description( $this->language );
			$name        = $category->get_name( $this->language );

			$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', 'detail-category-toggle' );

			$html .= str_replace(
				array(
					'[faz_preference_{{category_slug}}_title]',
					'[faz_preference_{{category_slug}}_status]',
					'[faz_preference_{{category_slug}}_description]',
					'{{category_slug}}',
					'[faz_audit_table]',
				),
				array(
					esc_html( $name ),
					esc_html( $category->get_prior_consent() ),
					wp_kses_post( $description ),
					esc_html( $category->get_slug() ),
					$audit_table,
				),
				$content
			);
		}
		return do_shortcode( $html );
	}

	/**
	 * Cookie audit table.
	 *
	 * @param array $cookies Cookies array.
	 * @return string
	 */
	public function faz_audit_table( $cookies = array() ) {
		$html = '';

		$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', 'audit-table' );
		$config         = faz_array_search( $this->properties['config'], 'tag', 'audit-table' );

		if ( isset( $config['status'] ) && false === $config['status'] ) {
			return;
		}

		$container = isset( $shortcode_data['content']['container'] ) ? $shortcode_data['content']['container'] : '';
		if ( '' === $shortcode_data ) {
			return $html;
		}
		$contents = isset( $this->contents['auditTable']['elements'] ) ? $this->contents['auditTable']['elements'] : '';

		if ( empty( $cookies ) ) {
			$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', 'audit-table-empty' );
			$container      = isset( $shortcode_data['content']['container'] ) ? $shortcode_data['content']['container'] : '';
			$html           = do_shortcode( $container );
			return $html;
		}
		foreach ( $cookies as $cookie ) {
			$table_body  = '';
			$section     = $container;
			$description = $cookie['description'];
			$duration    = $cookie['duration'];
			$description = isset( $description[ $this->language ] ) ? $description[ $this->language ] : '';
			$duration    = isset( $duration[ $this->language ] ) ? $duration[ $this->language ] : '';
			$table_body .= '<li>';
			$table_body .= '<div>' . esc_html( isset( $contents['headers']['elements']['id'] ) ? $contents['headers']['elements']['id'] : '' ) . '</div>';
			$table_body .= '<div>' . esc_html( $cookie['name'] ) . '</div>';
			$table_body .= '</li>';
			$table_body .= '<li>';
			$table_body .= '<div>' . esc_html( isset( $contents['headers']['elements']['duration'] ) ? $contents['headers']['elements']['duration'] : '' ) . '</div>';
			$table_body .= '<div>' . esc_html( $duration ) . '</div>';
			$table_body .= '</li>';
			$table_body .= '<li>';
			$table_body .= '<div>' . esc_html( isset( $contents['headers']['elements']['description'] ) ? $contents['headers']['elements']['description'] : '' ) . '</div>';
			$table_body .= '<div>' . wp_kses( $description, faz_allowed_html() ) . '</div>';
			$table_body .= '</li>';

			$html .= str_replace(
				array(
					'[CONTENT]',
				),
				array(
					$table_body,
				),
				$section
			);
		}
		return $html;
	}

	/**
	 * Category detail preview.
	 *
	 * @return string
	 */
	public function faz_preview_category() {
		$html           = '';
		$categories     = Category_Controller::get_instance()->get_items();
		$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', 'detail-category-preview' );
		$container      = isset( $shortcode_data['content']['container'] ) ? $shortcode_data['content']['container'] : '';
		foreach ( $categories as $category ) {
			$object = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $category );
			if ( false === $object->get_visibility() ) {
				continue;
			}
			if ( empty( $object->get_cookies() ) ) {
				continue;
			}
			$name = $object->get_name( $this->language );
			$html  .= str_replace(
				array(
					'[faz_preview_{{category_slug}}_title]',
					'{{category_slug}}',
				),
				array(
					$name,
					$object->get_slug(),
				),
				$container
			);
		}
		return $html;
	}

	/**
	 * Callback for the shortcode [faz_privacy_link]
	 *
	 * @return string
	 */
	public function faz_privacy_link() {
		$privacy_link = isset( $this->contents['notice']['elements']['privacyLink'] ) ? trim( (string) $this->contents['notice']['elements']['privacyLink'] ) : '';
		if ( '' === $privacy_link ) {
			$privacy_link = home_url( '/cookie-policy' );
		}
		return esc_url( $privacy_link );
	}

	/**
	 * Callback for the shortcode [faz_show_desc]
	 *
	 * @return string
	 */
	public function faz_show_desc() {
		return $this->get_btn_html( 'show-desc-button' );
	}

	/**
	 * Callback for the shortcode [faz_hide_desc]
	 *
	 * @return string
	 */
	public function faz_hide_desc() {
		return $this->get_btn_html( 'hide-desc-button' );
	}

	/**
	 * Callback for the shortcode [faz_showmore_text]
	 *
	 * @return string
	 */
	public function faz_showmore_text() {
		$key = 'ccpa' === $this->law ? 'optoutPopup' : 'preferenceCenter';
		return isset( $this->contents[ $key ]['elements']['showMore'] ) ? $this->contents[ $key ]['elements']['showMore'] : '';
	}

	/**
	 * Callback for the shortcode [faz_showless_text]
	 *
	 * @return string
	 */
	public function faz_showless_text() {
		$key = 'ccpa' === $this->law ? 'optoutPopup' : 'preferenceCenter';
		return isset( $this->contents[ $key ]['elements']['showLess'] ) ? $this->contents[ $key ]['elements']['showLess'] : '';
	}

	/**
	 * Callback for the shortcode [faz_enable_category_label]
	 *
	 * @return string
	 */
	public function faz_enable_category_label() {
		return isset( $this->contents['preferenceCenter']['elements']['category']['elements']['enable'] ) ? $this->contents['preferenceCenter']['elements']['category']['elements']['enable'] : '';
	}

	/**
	 * Callback for the shortcode [faz_disable_category_label]
	 *
	 * @return string
	 */
	public function faz_disable_category_label() {
		return isset( $this->contents['preferenceCenter']['elements']['category']['elements']['disable'] ) ? $this->contents['preferenceCenter']['elements']['category']['elements']['disable'] : '';
	}

	/**
	 * Callback for the shortcode [faz_audit_table_empty_text]
	 *
	 * @return string
	 */
	public function faz_audit_table_empty_text() {
		return isset( $this->contents['auditTable']['elements']['message'] ) ? $this->contents['auditTable']['elements']['message'] : '';
	}

	/**
	 * Callback for the shortcode [faz_notice_close_label]
	 *
	 * @return string
	 */
	public function faz_notice_close_label() {
		return isset( $this->contents['notice']['elements']['closeButton'] ) ? $this->contents['notice']['elements']['closeButton'] : '';
	}

	/**
	 * Callback for the shortcode [faz_preference_close_label]
	 *
	 * @return string
	 */
	public function faz_preference_close_label() {
		return isset( $this->contents['preferenceCenter']['elements']['closeButton'] ) ? $this->contents['preferenceCenter']['elements']['closeButton'] : '';
	}

	/**
	 * Callback for the shortcode [faz_optout_cancel_text]
	 *
	 * @return string
	 */
	public function faz_optout_cancel_text() {
		return isset( $this->contents['optoutPopup']['elements']['buttons']['elements']['cancel'] ) ? $this->contents['optoutPopup']['elements']['buttons']['elements']['cancel'] : '';
	}

	/**
	 * Callback for the shortcode [faz_optout_confirm_text]
	 *
	 * @return string
	 */
	public function faz_optout_confirm_text() {
		return isset( $this->contents['optoutPopup']['elements']['buttons']['elements']['confirm'] ) ? $this->contents['optoutPopup']['elements']['buttons']['elements']['confirm'] : '';
	}

	/**
	 * Callback for the shortcode [faz_optout_confirmation]
	 *
	 * @return string
	 */
	public function faz_optout_confirmation() {
		return isset( $this->contents['optoutPopup']['elements']['confirmation'] ) ? $this->contents['optoutPopup']['elements']['confirmation'] : '';
	}

	/**
	 * Callback for the shortcode [faz_category_toggle_label]
	 *
	 * @return string
	 */
	public function faz_category_toggle_label() {
		$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', 'detail-category-toggle' );
		return isset( $shortcode_data['content']['container'] ) ? $shortcode_data['content']['container'] : '';
	}

	/**
	 * Callback for the shortcode [faz_video_placeholder]
	 *
	 * @return string
	 */
	public function faz_video_placeholder() {
		$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', 'video-placeholder' );
		return do_shortcode( isset( $shortcode_data['content']['container'] ) ? $shortcode_data['content']['container'] : '' );
	}

	/**
	 * Callback for the shortcode [faz_video_placeholder_title]
	 *
	 * @return string
	 */
	public function faz_video_placeholder_title() {
		return isset( $this->contents['videoPlaceholder']['elements']['title'] ) ? $this->contents['videoPlaceholder']['elements']['title'] : '';

	}

	/**
	 * Populate audit table.
	 *
	 * @return string
	 */
	public function faz_outside_audit_table() {
		$html           = '';
		$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', 'outside-audit-table' );
		$container      = isset( $shortcode_data['content']['container'] ) ? $shortcode_data['content']['container'] : '';
		$categories     = Category_Controller::get_instance()->get_items();

		if ( empty( $categories ) ) {
			return $html;
		}

		foreach ( $categories as $category ) {
			$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $category );
			if ( false === $category->get_visibility() ) {
				continue;
			}
			if ( empty( $category->get_cookies() ) ) {
				continue;
			}
			$audit_table = $this->faz_audit_table_by_category( $category );
			$name        = $category->get_name( $this->language );
			$html       .= str_replace(
				array(
					'[faz_preference_{{category_slug}}_title]',
					'[CONTENT]',
				),
				array(
					esc_html( $name ),
					wp_kses( $audit_table, faz_allowed_html() ),
				),
				$container
			);
		}
		return do_shortcode( $html );
	}

	/**
	 * Create audit-table for each category.
	 *
	 * @param object $category Category object.
	 * @return string
	 */
	public function faz_audit_table_by_category( $category ) {
		$cookies = $category->get_cookies();
		if ( empty( $cookies ) ) {
			return '';
		}
		$contents   = isset( $this->contents['auditTable']['elements'] ) ? $this->contents['auditTable']['elements'] : '';
		$html       = '';
		$table_head = '<thead><tr>
		<th>' . esc_html( isset( $contents['headers']['elements']['id'] ) ? $contents['headers']['elements']['id'] : '' ) . '</th>
		<th>' . esc_html( isset( $contents['headers']['elements']['duration'] ) ? $contents['headers']['elements']['duration'] : '' ) . '</th>
		<th>' . esc_html( isset( $contents['headers']['elements']['description'] ) ? $contents['headers']['elements']['description'] : '' ) . '</th>
		</tr></thead>';
		$table_body = '<tbody>';
		foreach ( $cookies as $cookie ) {
			$description = $cookie['description'];
			$duration    = $cookie['duration'];
			$description = isset( $description[ $this->language ] ) ? $description[ $this->language ] : '';
			$duration    = isset( $duration[ $this->language ] ) ? $duration[ $this->language ] : '';

			$table_body .= '<tr>';
			$table_body .= '<td>' . esc_html( $cookie['name'] ) . '</td>';
			$table_body .= '<td>' . esc_html( $duration ) . '</td>';
			$table_body .= '<td>' . wp_kses( $description, faz_allowed_html() ) . '</td>';
			$table_body .= '</tr>';
		}
		$table_body .= '</tbody>';
		$html        = $table_head . $table_body;
		return $html;
	}

	public function faz_optout_title() {
		return isset( $this->contents['optoutPopup']['elements']['title'] ) ? $this->contents['optoutPopup']['elements']['title'] : '';
	}
	public function faz_optout_description() {
		return isset( $this->contents['optoutPopup']['elements']['description'] ) ? $this->contents['optoutPopup']['elements']['description'] : '';
	}
	public function faz_optout_option_title() {
		return isset( $this->contents['optoutPopup']['elements']['optOption']['elements']['title'] ) ? $this->contents['optoutPopup']['elements']['optOption']['elements']['title'] : '';
	}
	public function faz_optout_gpc_description() {
		return isset( $this->contents['optoutPopup']['elements']['gpcOption']['elements']['description'] ) ? $this->contents['optoutPopup']['elements']['gpcOption']['elements']['description'] : '';
	}
	public function faz_enable_optout_label() {
		return isset( $this->contents['optoutPopup']['elements']['optOption']['elements']['enable'] ) ? $this->contents['optoutPopup']['elements']['optOption']['elements']['enable'] : '';
	}
	public function faz_disable_optout_label() {
		return isset( $this->contents['optoutPopup']['elements']['optOption']['elements']['disable'] ) ? $this->contents['optoutPopup']['elements']['optOption']['elements']['disable'] : '';
	}
	public function faz_optout_toggle_label() {
		$shortcode_data = faz_array_search( $this->shortcodes, 'uiTag', 'optout-option-toggle' );
		return isset( $shortcode_data['content']['container'] ) ? $shortcode_data['content']['container'] : '';
	}
	public function faz_optout_close_label() {
		return isset( $this->contents['optoutPopup']['elements']['closeButton'] ) ? $this->contents['optoutPopup']['elements']['closeButton'] : '';
	}

}

