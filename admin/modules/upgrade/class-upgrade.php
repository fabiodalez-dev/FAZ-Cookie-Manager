<?php
/**
 * Class Review_Feedback file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Upgrade;

use FazCookie\Admin\Modules\Settings\Includes\Settings;
use FazCookie\Includes\Modules;
use FazCookie\Includes\Notice;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Uninstall feedback Operation
 *
 * @class       Review_Feedback
 * @version     3.0.0
 * @package     FazCookie
 */
class Upgrade extends Modules {

	/**
	 * Existing plugin settings.
	 *
	 * @var array
	 */
	protected $settings = array();

	/**
	 * Skip overriding the settings.
	 *
	 * @var boolean
	 */
	protected $skip = false;

	/**
	 * Current law
	 *
	 * @var string
	 */
	protected $law = 'gdpr';
	/**
	 * Constructor.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'migrate' ) );
		add_action( 'admin_init', array( $this, 'add_migration_notice' ) );
		add_action( 'admin_init', array( $this, 'revert' ) );
	}

	/**
	 * Start doing migration
	 *
	 * @return void
	 */
	public function migrate() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'migrate' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['migrate'] ) && 'process' === sanitize_text_field( wp_unslash( $_GET['migrate'] ) ) ) {
			register_post_type( FAZ_POST_TYPE );
			register_taxonomy(
				'cookielawinfo-category',
				'cookielawinfo'
			);
			$this->start_migration();
		}
	}
	/**
	 * Start migrating
	 *
	 * @return void
	 */
	public function start_migration() {
		$legacy_main = FAZ_PLUGIN_BASEPATH . 'legacy/includes/class-cookie-law-info.php';
		if ( ! file_exists( $legacy_main ) ) {
			return; // Legacy directory removed from this fork.
		}
		require_once $legacy_main;
		require_once FAZ_PLUGIN_BASEPATH . 'legacy/public/modules/shortcode/shortcode.php';
		require_once FAZ_PLUGIN_BASEPATH . 'legacy/admin/modules/ccpa/ccpa.php';

		$this->settings = \Cookie_Law_Info::get_settings();
		$this->migrate_settings();
		$this->migrate_categories();
		$this->migrate_banners();
		update_option(
			'faz_migration_options',
			array(
				'status' => true,
				'expiry' => time() + 14 * DAY_IN_SECONDS,
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=faz-cookie-manager' ) );
		exit;
	}

	/**
	 * Return old plugin items.
	 *
	 * @return array
	 */
	public function get_old_category_terms() {
		global $wp_version;
		$taxonomy = 'cookielawinfo-category';
		$terms    = array();
		if ( version_compare( $wp_version, '4.9', '>=' ) ) {
			$args  = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			);
			$terms = get_terms( $args );
		} else {
			$terms = get_terms( $taxonomy, array( 'hide_empty' => false ) ); // phpcs:ignore WordPress.WP.DeprecatedParameters.Get_termsParam2Found
		}
		return $this->order_term_by_key( $terms );
	}

	/**
	 * Migrate the cookie categories.
	 *
	 * @return void
	 */
	public function migrate_categories() {
		$this->clear_existing_categories();
		$terms        = $this->get_old_category_terms();
		$lang         = faz_default_language();
		$languages    = faz_i18n_selected_languages();
		$default_lang = faz_i18n_default_language();

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( is_object( $term ) ) {
					$object        = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories();
					$term_language = faz_i18n_term_language( $term->term_id );
					if ( $term_language === $default_lang ) {

						$name        = array();
						$description = array();

						foreach ( $languages as $language ) {
							$name[ $language ]        = $term->name;
							$description[ $language ] = strip_shortcodes( $term->description );
							if ( $default_lang !== $language ) {
								$translated = faz_i18n_term_by_language( $term->term_id, $language );
								if ( false !== $translated ) {
									$name[ $language ]        = isset( $translated->name ) ? $translated->name : '';
									$description[ $language ] = isset( $translated->description ) ? strip_shortcodes( $translated->description ) : '';
								}
							}
						}
						$object->set_name( $name );
						$object->set_description( $description );
						$object->set_slug( $term->slug );
						if ( 'necessary' === $term->slug ) {
							$object->set_prior_consent( true );
						}
						$object->save();
						$cookies = $this->get_cookies_by_term( $term->slug );

						if ( ! empty( $cookies ) ) {
							foreach ( $cookies as $key => $item ) {
								$cookie      = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie();
								$meta        = get_post_custom( $item->ID );
								$description = array();
								$duration    = array();
								foreach ( $languages as $language ) {
									$description[ $language ] = strip_shortcodes( $item->post_content );
									$duration[ $language ]    = sanitize_text_field( isset( $meta['_cli_cookie_duration'][0] ) ? $meta['_cli_cookie_duration'][0] : '' );
									if ( $default_lang !== $language ) {
										$translated = faz_i18n_post_by_language( $item->ID, $language );
										if ( ! $translated ) {
											continue;
										}
										$translated_meta          = get_post_custom( $translated->ID );
										$duration[ $language ]    = sanitize_text_field( ! empty( $translated_meta['_cli_cookie_duration'][0] ) ? $translated_meta['_cli_cookie_duration'][0] : $duration[ $lang ] );
										$description[ $language ] = isset( $translated->post_content ) ? strip_shortcodes( $translated->post_content ) : '';
									}
								}
								$cookie->set_name( $item->post_title );
								$cookie->set_description( $description );
								$cookie->set_duration( $duration );
								$cookie->set_category( $object->get_id() );
								$cookie->save();
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Flush existing data from cookie and category table.
	 *
	 * @return void
	 */
	public function clear_existing_categories() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}faz_cookie_categories;" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}faz_cookies;" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Get the cookies for corresponding categories.
	 *
	 * @param string $slug Slug of the term.
	 * @return array
	 */
	public function get_cookies_by_term( $slug ) {
		$cookies = array();
		$args    = array(
			'posts_per_page' => -1,
			'post_type'      => 'cookielawinfo',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'cookielawinfo-category',
					'field'    => 'slug',
					'terms'    => $slug,
				),
			),
		);
		$posts   = get_posts( $args );
		if ( $posts ) {
			$cookies = $posts;
		}
		return $cookies;
	}

	/**
	 * Migrate the site settings
	 *
	 * @return void
	 */
	public function migrate_settings() {
		$settings                         = new Settings();
		$options                          = $settings->get();
		$options['languages']['default']  = faz_i18n_default_language();
		$options['languages']['selected'] = faz_i18n_selected_languages();
		$settings->update( $options );
	}

	/**
	 * Migrate banner config and contents
	 *
	 * @return void
	 */
	public function migrate_banners() {
		$languages = faz_i18n_selected_languages();
		$banners   = \FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance()->get_items();
		$old_law   = isset( $this->settings['consent_type'] ) ? $this->settings['consent_type'] : 'gdpr';
		foreach ( $banners as $key => $item ) {
			$banner    = new \FazCookie\Admin\Modules\Banners\Includes\Banner( $item->banner_id );
			$type      = $banner->get_law();
			$this->law = $type;
			$banner->set_status( false );

			if ( $type === $old_law || ( 'ccpa_gdpr' === $old_law && 'gdpr' === $type ) ) {
				$banner->set_status( true );
				$contents = array();
				foreach ( $languages as $language ) {
					$contents[ $language ] = $this->prepare_contents( $banner->get_contents( $language ), $language );
				}
				$banner->set_contents( $contents );
				$this->skip = false;
			} elseif ( 'ccpa_gdpr' === $old_law ) {
				if ( 'gdpr' === $type ) {
					$banner->set_status( true );
				}
				$this->skip = false;
			} else {
				$this->skip = true;
			}

			$settings = $this->prepare_config( $banner->get_settings() );
			$banner->set_settings( $settings );
			$banner->save();
		}
	}

	/**
	 * Prepare banner config for migration.
	 *
	 * @param array $config Banner config.
	 * @return array
	 */
	public function prepare_config( $config = array() ) {
		$settings     = $this->settings;
		$current_type = isset( $settings['cookie_bar_as'] ) ? sanitize_text_field( $settings['cookie_bar_as'] ) : 'banner';
		$position     = isset( $settings['notify_position_vertical'] ) ? sanitize_text_field( $settings['notify_position_vertical'] ) : 'bottom-left';
		$heading      = isset( $settings['bar_heading_text'] ) ? $settings['bar_heading_text'] : '';

		$type = 'banner';
		if ( 'popup' === $current_type || 'widget' === $current_type ) {
			$type     = 'box';
			$position = isset( $settings['widget_position'] ) ? sanitize_text_field( $settings['widget_position'] ) : 'left';
			$position = "bottom-{$position}";
		}
		$config['settings']['type']     = $type;
		$config['settings']['theme']    = 'custom';
		$config['settings']['position'] = $position;

		$background_color = isset( $settings['background'] ) ? faz_sanitize_color( $settings['background'] ) : $config['config']['notice']['styles']['background-color'];
		$border_color     = isset( $settings['border'] ) ? faz_sanitize_color( $settings['border'] ) : $config['config']['notice']['styles']['border-color'];
		$color            = isset( $settings['text'] ) ? faz_sanitize_color( $settings['text'] ) : $config['config']['notice']['styles']['color'];

		$config['config']['notice']['styles']['background-color']                 = $background_color;
		$config['config']['notice']['styles']['border-color']                     = $border_color;
		$config['config']['notice']['elements']['title']['styles']['color']       = $color;
		$config['config']['notice']['elements']['description']['styles']['color'] = $color;
		$config['config']['notice']['elements']['closeButton']['status'] = has_shortcode( $settings['notify_message'], 'cookie_close' );

		$config['config']['preferenceCenter']['styles']['background-color'] = $background_color;
		$config['config']['preferenceCenter']['styles']['border-color']     = $border_color;

		$buttons_config = isset( $config['config']['notice']['elements']['buttons']['elements'] ) ? $config['config']['notice']['elements']['buttons']['elements'] : array();

		if ( ! empty( $buttons_config ) ) {
			$accept_button = has_shortcode( $settings['notify_message'], 'cookie_accept_all' ) ? 'button_7' : 'button_1';

			$buttons_config['accept']    = isset( $buttons_config['accept'] ) ? $this->prepare_buttons( $accept_button, $buttons_config['accept'] ) : array();
			// Only enable accept button for GDPR banners, not for US state law (CCPA)
			$buttons_config['accept']['status'] = ( 'ccpa' !== $this->law );
			$buttons_config['reject']    = isset( $buttons_config['reject'] ) ? $this->prepare_buttons( 'button_3', $buttons_config['reject'] ) : array();
			$buttons_config['settings']  = isset( $buttons_config['settings'] ) ? $this->prepare_buttons( 'button_4', $buttons_config['settings'] ) : array();
			$buttons_config['donotSell'] = isset( $buttons_config['donotSell'] ) ? $this->prepare_buttons( 'button_6', $buttons_config['donotSell'] ) : array();
			$buttons_config['readMore']  = isset( $buttons_config['readMore'] ) ? $this->prepare_buttons( 'button_2', $buttons_config['readMore'] ) : array();
			$buttons_config['readMore']  = $this->prepare_readmore( $buttons_config['readMore'] );

			$config['config']['notice']['elements']['buttons']['elements'] = $buttons_config;

			$preference_center  = isset( $config['config']['preferenceCenter'] ) ? $config['config']['preferenceCenter'] : array();
			$preference_buttons = isset( $preference_center['elements']['buttons']['elements'] ) ? $preference_center['elements']['buttons']['elements'] : array();

			$preference_buttons['accept']['styles'] = isset( $buttons_config['accept']['styles'] ) ? $buttons_config['accept']['styles'] : array();
			$preference_buttons['reject']['styles'] = isset( $buttons_config['reject']['styles'] ) ? $buttons_config['reject']['styles'] : array();
			$preference_buttons['save']['styles']   = isset( $buttons_config['settings']['styles'] ) ? $buttons_config['settings']['styles'] : array();

			$config['config']['preferenceCenter']['elements']['buttons']['elements'] = $preference_buttons;
		}
		$revisit_options = isset( $config['config']['revisitConsent'] ) ? $config['config']['revisitConsent'] : array();

		$config['config']['revisitConsent'] = $this->get_revisit_options( $revisit_options );
		return $config;
	}

	/**
	 * Prepare the buttons for migration.
	 *
	 * @param string $button Button slug as of old settings.
	 * @param array  $config Existing config.
	 * @return array
	 */
	public function prepare_buttons( $button = 'button_1', $config = array() ) {
		$settings  = $this->settings;
		$shortcode = $this->get_shortcode( $button );
		if ( false === $this->skip ) {
			$config['status'] = has_shortcode( $settings['notify_message'], $shortcode );
		}
		if ( 'ccpa' === $this->law && 'button_6' !== $button || 'gdpr' === $this->law && 'button_6' === $button ) {
			$config['status'] = false;
		}
		$config['styles']['background-color'] = isset( $settings[ "{$button}_button_colour" ] ) ? $settings[ "{$button}_button_colour" ] : '';
		$config['styles']['color']            = isset( $settings[ "{$button}_link_colour" ] ) ? $settings[ "{$button}_link_colour" ] : '';
		$config['styles']['border-color']     = $config['styles']['background-color'];
		return $config;
	}

	/**
	 * Set background and border color transparent.
	 *
	 * @param array $config Existing config.
	 * @return array
	 */
	public function prepare_readmore( $config = array() ) {
		$config['styles']['background-color'] = 'transparent';
		$config['styles']['border-color']     = 'transparent';
		return $config;
	}

	/**
	 * Prepare banner contents for migration.
	 *
	 * @param array  $contents Existing contents.
	 * @param string $language Language slug.
	 * @return array
	 */
	public function prepare_contents( $contents, $language ) {
		$settings              = $this->settings;
		$notice                = isset( $contents['notice']['elements'] ) ? $contents['notice']['elements'] : array();
		$notice['title']       = isset( $settings['bar_heading_text'] ) ? faz_i18n_translate_string( $settings['bar_heading_text'], 'bar_heading_text', $language ) : '';
		$notice['description'] = isset( $settings['notify_message'] ) ? wp_strip_all_tags( strip_shortcodes( faz_i18n_translate_string( $settings['notify_message'], 'notify_message', $language ) ) ) : '';
		$notice['privacyLink'] = $this->get_readmore_link();

		$accept_button                             = has_shortcode( $settings['notify_message'], 'cookie_accept_all' ) ? 'button_7' : 'button_1';
		$notice['buttons']['elements']['accept']   = $this->get_button_text( $accept_button, $language );
		$notice['buttons']['elements']['reject']   = $this->get_button_text( 'button_3', $language );
		$notice['buttons']['elements']['settings'] = $this->get_button_text( 'button_4', $language );
		$notice['buttons']['elements']['readMore'] = $this->get_button_text( 'button_2', $language );
		$contents['notice']['elements']            = $notice;

		// Preference center.

		$preference = isset( $contents['preferenceCenter']['elements'] ) ? $contents['preferenceCenter']['elements'] : array();
		$existing   = $this->get_preference_center_texts();

		$preference['title']                             = faz_i18n_translate_string( $existing['title'], 'privacy_overview_title', $language, 'cookielawinfo_privacy_overview_content_settings' );
		$preference['description']                       = faz_i18n_translate_string( $existing['description'], 'privacy_overview_content', $language, 'cookielawinfo_privacy_overview_content_settings' );
		$preference['buttons']['elements']['accept']     = $this->get_button_text( $accept_button, $language );
		$preference['buttons']['elements']['reject']     = $this->get_button_text( 'button_3', $language );
		$contents['preferenceCenter']['elements']        = $preference;
		$contents['revisitConsent']['elements']['title'] = faz_i18n_translate_string( $existing['title'], 'title', $language );
		return $contents;
	}

	/**
	 * Get button text based on language
	 *
	 * @param string $button Button slug.
	 * @param string $language Language code.
	 * @return string
	 */
	public function get_button_text( $button = 'button_1', $language = 'en' ) {
		$settings = $this->settings;
		return isset( $settings[ "{$button}_text" ] ) ? faz_i18n_translate_string( $settings[ "{$button}_text" ], "{$button}_text", $language ) : '';
	}

	/**
	 * Return preference center options
	 *
	 * @return array
	 */
	public function get_preference_center_texts() {
		$overview    = get_option( 'cookielawinfo_privacy_overview_content_settings' );
		$title       = sanitize_text_field( stripslashes( isset( $overview['privacy_overview_title'] ) ? $overview['privacy_overview_title'] : '' ) );
		$description = wp_kses_post( isset( $overview['privacy_overview_content'] ) ? $overview['privacy_overview_content'] : '' );
		return array(
			'title'       => $title,
			'description' => $description,
		);
	}

	/**
	 * Migrate revisit consent option.
	 *
	 * @param array $options Array of options.
	 * @return array
	 */
	public function get_revisit_options( $options = array() ) {
		$settings            = $this->settings;
		$status              = isset( $settings['showagain_tab'] ) ? (bool) $settings['showagain_tab'] : false;
		$position            = isset( $settings['notify_position_horizontal'] ) && 'right' === $settings['notify_position_horizontal'] ? 'bottom-right' : 'bottom-left';
		$options['status']   = $status;
		$options['position'] = $position;
		return $options;
	}

	/**
	 * Map shortcodes to corresponding buttons.
	 *
	 * @param string $button Button slug.
	 * @return string
	 */
	public function get_shortcode( $button ) {
		switch ( $button ) {
			case 'button_1':
				return 'cookie_button';
			case 'button_2':
				return 'cookie_link';
			case 'button_3':
				return 'cookie_reject';
			case 'button_4':
				return 'cookie_settings';
			case 'button_6':
				return 'wt_cli_ccpa_optout';
			case 'button_7':
				return 'cookie_accept_all';
			default:
				return 'cookie_button';
		}
	}

	/**
	 * Reorder the terms based on the priority.
	 *
	 * @param array  $terms Terms array.
	 * @param string $order Sorting order.
	 * @return array
	 */
	public function order_term_by_key( $terms, $order = 'DESC' ) {
		$sort_order  = SORT_DESC;
		$meta_values = array();
		if ( 'ASC' === $order ) {
			$sort_order = SORT_ASC;
		}
		if ( ! empty( $terms ) && is_array( $terms ) ) {
			foreach ( $terms as $key => $term ) {
				$priority      = get_term_meta( $term->term_id, 'CLIpriority' );
				$meta_values[] = isset( $priority ) ? absint( $priority ) : 0;
			}
			if ( ! empty( $meta_values ) && is_array( $meta_values ) ) {
				array_multisort( $meta_values, $sort_order, $terms );
			}
		}
		return $terms;
	}

	/**
	 * Add a migration notice which allows to revert back to the legacy UI.
	 *
	 * @return void
	 */
	public function add_migration_notice() {
		$options = get_option( 'faz_migration_options', array() );
		$status  = isset( $options['status'] ) ? $options['status'] : false;
		$expiry  = isset( $options['expiry'] ) ? $options['expiry'] : 0;

		if ( ! $status || ( 0 !== $expiry && time() > $expiry ) ) {
			return;
		}
		if ( true === $status ) {
			$date   = date_i18n( 'M d,Y', $expiry );
			$notice = Notice::get_instance();
			$notice->add(
				'migration_notice',
				array( // translators: %s: Migration notice expiry notice.
					'message' => sprintf( __( 'Not satisfied with the New UI and related changes? You can switch back to the old UI at any time until %s.', 'faz-cookie-manager' ), esc_html( $date ) ),
					'type'    => 'info',
				)
			);
		}
	}
	/**
	 * Return the readmore link
	 *
	 * @return string
	 */
	private function get_readmore_link() {
		if ( isset( $this->settings['button_2_url_type'] ) && $this->settings['button_2_url_type'] === 'url' ) {
			return isset( $this->settings['button_2_url'] ) ? esc_url_raw( $this->settings['button_2_url'] ) : '';
		} else {
			$page = isset( $this->settings['button_2_page'] ) ? intval( $this->settings['button_2_page'] ) : false;

			$privacy = '';
			if ( ! $page ) {
				return '';
			}
			$post = get_post( $page );
			if ( $post instanceof \WP_Post ) {
				if ( 'publish' === $post->post_status ) {
					$privacy = esc_url_raw( get_page_link( $post ) );
				}
			}
			return $privacy;
		}
	}
	/**
	 * Revert plugin to legacy UI
	 *
	 * @return void
	 */
	public function revert() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'revert' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['revert'] ) || 'true' !== sanitize_text_field( wp_unslash( $_GET['revert'] ) ) ) {
			return;
		}
		$settings = new Settings();
		$options  = $settings->get();

		$options['api']['token']         = '';
		$options['account']['connected'] = false;
		$settings->update( $options );
		delete_option( 'faz_cookie_consent_lite_db_version' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=cookielawinfo&page=faz-cookie-manager' ) );
		exit;
	}
}
