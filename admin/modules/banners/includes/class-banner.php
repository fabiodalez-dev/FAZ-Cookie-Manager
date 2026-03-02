<?php
/**
 * Class Banner file.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Admin\Modules\Banners\Includes
 */

namespace FazCookie\Admin\Modules\Banners\Includes;

use FazCookie\Includes\Store;
use FazCookie\Admin\Modules\Banners\Includes\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Banner
 * @version     3.0.0
 * @package     FazCookie
 */
class Banner extends Store {

	/**
	 * Banner controller class.
	 *
	 * @var object
	 */
	private $controller;

	/**
	 * Data array, with defaults.
	 *
	 * @var array
	 */
	protected $data = array(
		'name'          => '',
		'slug'          => '',
		'status'        => false,
		'settings'      => '',
		'default'       => false,
		'contents'      => array(),
		'date_created'  => '',
		'date_modified' => '',
	);

	/**
	 * Constructor
	 *
	 * @param mixed $data ID or slug of the cookie.
	 */
	public function __construct( $data = '' ) {
		$this->controller = Controller::get_instance();
		parent::__construct( $data );
		if ( is_int( $data ) && 0 !== $data ) {
			$this->set_id( $data );
		}
		if ( isset( $data->banner_id ) ) {
			$this->set_id( $data->banner_id );
			$this->read_direct( $data );
		} else {
			$this->get_data_from_db();
		}
	}
	/**
	 * Read data directly from DB
	 *
	 * @return void
	 */
	public function get_data_from_db() {
		if ( $this->get_id() > 0 ) {
			$this->read( $this );
		} else {
			$this->set_settings( $this->controller->get_default_configs() );
			$this->set_contents( self::get_default_contents() );
		}
	}
	/**
	 * Read directly from the data object given.
	 * Used for assigning data to object if it is already fetched from API or DB.
	 *
	 * @param array|object $data Banner data.
	 * @return void
	 */
	public function read_direct( $data ) {
		$this->set_data( $data );
	}

	/**
	 * Assign data to objects
	 *
	 * @param array|object $data Array of data.
	 * @return void
	 */
	public function set_data( $data ) {
		if ( isset( $data->banner_id ) ) {
			$this->set_multi_item_data(
				array(
					'name'     => $data->name,
					'slug'     => $data->slug,
					'status'   => $data->status,
					'settings' => $data->settings,
					'contents' => $data->contents,
					'default'  => $data->banner_default,
				)
			);
			$this->set_loaded( true );
		}
	}
	/**
	 * Read cookie data from database
	 *
	 * @param object $banner Instance of Banner.
	 * @return void
	 */
	public function read( $banner ) {
		$banner->set_defaults();
		$data = $this->controller->get_item( $banner->get_id() );
		$this->set_data( $data );
	}

	/**
	 * Insert a new banner on the database.
	 *
	 * @param object $banner Consent banner object.
	 * @return void
	 */
	public function create( $banner ) {
		$this->controller->create_item( $banner );
	}
	/**
	 * Update banner data
	 *
	 * @param object $banner Instance of Banner.
	 * @return void
	 */
	public function update( $banner ) {
		$this->controller->update_item( $banner );
	}
	/**
	 * Set banner settings
	 *
	 * @since 3.0.0
	 * @param array $data Settings data.
	 * @return void
	 */
	public function set_settings( $data ) {
		$key = 'settings';
		if ( array_key_exists( $key, $this->data ) ) {
			$data               = self::sanitize_settings( array( $this, 'sanitize_option' ), $data, $this->controller->get_default_configs() );
			$this->data[ $key ] = $data;
		}
	}
	/**
	 * Set contents for a banner
	 *
	 * @since 3.0.0
	 * @param array $data Banner contents of all selected languages.
	 * @return void
	 */
	public function set_contents( $data ) {
		$key = 'contents';
		if ( array_key_exists( $key, $this->data ) ) {
			$contents  = array();
			$languages = faz_selected_languages();
			foreach ( $languages as $lang ) {
				$contents[ $lang ] = isset( $data[ $lang ] ) ? $this->sanitize_contents( $data[ $lang ], $this->get_translations( $lang ) ) : array();
			}
			$this->data[ $key ] = $contents;
		}
	}
	/**
	 * Set banner default status
	 *
	 * @since 3.0.0
	 * @param boolean $default Default status to be set.
	 * @return void
	 */
	public function set_default( $default = false ) {
		$key = 'default';
		if ( array_key_exists( $key, $this->data ) ) {
			$this->data[ $key ] = (bool) $default;
		}
	}
	/**
	 * Set banner status
	 *
	 * @since 3.0.0
	 * @param boolean $status Default status to be set.
	 * @return void
	 */
	public function set_status( $status = false ) {
		$key = 'status';
		if ( array_key_exists( $key, $this->data ) ) {
			$this->data[ $key ] = (bool) $status;
		}
	}
	/**
	 * Get banner settings
	 *
	 * @since 3.0.0
	 * @return array
	 */
	public function get_settings() {
		$settings = array();
		$key      = 'settings';
		if ( array_key_exists( $key, $this->data ) ) {
			$settings = ( is_string( $this->data[ $key ] ) ) ? json_decode( $this->data[ $key ], true ) : $this->data[ $key ];
			if ( is_array( $settings ) ) {
				$settings = self::sanitize_settings( array( $this, 'sanitize_option' ), $settings, $this->controller->get_default_configs() );
			}
		}
		return $settings;
	}

	/**
	 * Excludes items from sanitizing multiple times.
	 *
	 * @return array
	 */
	public static function get_excludes() {
		return array(
			'selected',
			'headers',
			'locations',
			'regions',
			'country',
		);
	}
	/**
	 * Return type of the banner
	 *
	 * @return string
	 */
	public function get_type() {
		$config = $this->get_settings();
		return isset( $config['settings']['type'] ) ? $config['settings']['type'] : 'box';
	}

	/**
	 * Get the type of law used in the current banner.
	 *
	 * @return string
	 */
	public function get_law() {
		$config = $this->get_settings();
		return isset( $config['settings']['applicableLaw'] ) ? $config['settings']['applicableLaw'] : 'gdpr';
	}
	/**
	 * Get the default state of a banner.
	 *
	 * @return boolean
	 */
	public function get_default() {
		return (bool) $this->get_object_data( 'default' );
	}
	/**
	 * Get the default state of a banner.
	 *
	 * @return boolean
	 */
	public function get_status() {
		return (bool) $this->get_object_data( 'status' );
	}

	/**
	 * Get current language of the banner
	 *
	 * @return string|boolean
	 */
	public function get_language() {
		if ( '' === $this->language ) {
			return faz_default_language();
		}
		return is_string( $this->language ) ? sanitize_text_field( $this->language ) : false;
	}
	/**
	 * Get banner contents
	 *
	 * @param string $language Get language based content of each banner.
	 * @return array
	 */
	public function get_contents( $language = '' ) {
		$contents  = array();
		$key       = 'contents';
		$current   = $this->get_language();
		$languages = faz_selected_languages( $current );
		if ( array_key_exists( $key, $this->data ) ) {
			$data = $this->data[ $key ];
			foreach ( $languages as $lang ) {
				$content           = isset( $data[ $lang ] ) ? $data[ $lang ] : array();
				$content           = empty( self::array_empty_assoc( $content ) ) ? $this->get_translations( $lang ) : $content;
				$content           = is_string( $content ) ? json_decode( $content, true ) : $content;
				$contents[ $lang ] = $this->sanitize_contents( $content );
			}
		}
		if ( '' !== $language ) {
			return isset( $contents[ $language ] ) ? $contents[ $language ] : array();
		}
		return $contents;
	}
	/**
	 * Sanitize all the banner before insert or retrieval
	 *
	 * @since 3.0.0
	 * @param callable $function Callback function.
	 * @param array    $settings input array.
	 * @param array    $defaults Default settings of the banner.
	 * @return array
	 */
	public static function sanitize_settings( $function, $settings, $defaults ) {
		$result  = array();
		$excludes = self::get_excludes();
		foreach ( $defaults as $key => $data ) {
			$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : $data;
			$defaults = $data;
			if ( in_array( $key, $excludes, true ) ) {
				$result[ $key ] = $function( $key, $value );
				continue;
			}
			if ( is_array( $value ) ) {
				$result[ $key ] = self::sanitize_settings( $function, $value, $defaults );
			} else {
				if ( is_string( $key ) ) {
					$result[ $key ] = $function( $key, $value );
				}
			}
		}
		return $result;
	}

	/**
	 * Sanitize all the banner before insert or retrieval
	 *
	 * @param array      $contents input array.
	 * @param array|bool $defaults Default settings.
	 * @return array
	 */
	public function sanitize_contents( $contents, $defaults = false ) {
		$result   = array();
		$defaults = false === $defaults ? $this->get_default_contents() : $defaults;
		foreach ( $defaults as $key => $data ) {
			$value    = isset( $contents[ $key ] ) ? $contents[ $key ] : $data;
			$defaults = $data;

			if ( is_array( $value ) ) {
				$result[ $key ] = $this->sanitize_contents( $value, $defaults );
			} else {
				if ( is_string( $key ) ) {
					$result[ $key ] = $this->sanitize_content( $key, $value );
				}
			}
		}
		return $result;
	}

	/**
	 * Check if an array is associative or indexed
	 *
	 * @param array $array Input array.
	 * @return Boolean
	 */
	public static function array_has_key( $array ) {
		if ( count( array_filter( array_keys( $array ), 'is_string' ) ) === 0 ) {
			return false;
		}
		return true;
	}

	/**
	 * Generate the template HTML for a banner
	 *
	 * @since 3.0.0
	 * @return array
	 */
	public function get_template() {
		$object = $this->controller->get_template( $this );
		$data   = array(
			'html'   => '',
			'styles' => '',
		);
		if ( ! $object ) {
			return $data;
		}
		$data['html']   = $object->get_html();
		$data['styles'] = $object->get_styles();
		return $data;
	}

	/**
	 * Sanitize the option values
	 *
	 * @param string $option The name of the option.
	 * @param string $value  The unsanitised value.
	 * @return string Sanitized value.
	 */
	public static function sanitize_option( $option, $value ) {
		switch ( $option ) {
			case 'enableBanner':
			case 'enableConsentLog':
			case 'title':
			case 'enable':
			case 'isLink':
			case 'noFollow':
			case 'newTab':
			case 'minimizeOnClick':
			case 'categoryInNotice':
			case 'brandLogo':
			case 'fazLogo':
			case 'text':
			case 'activeText':
			case 'inActiveText':
			case 'alwaysEnabledText':
			case 'poweredByLogo':
			case 'noticeToggler':
			case 'reloadOnAccept':
			case 'enableCallbacks':
			case 'status':
				$value = faz_sanitize_bool( $value );
				break;
			case 'background-color':
				$value = faz_sanitize_color( $value );
				break;
			default:
				$value = faz_sanitize_text( $value );
				break;
		}
		return $value;
	}

	/**
	 * Sanitize the contents
	 *
	 * @param string $option The name of the option.
	 * @param string $value  The unsanitised value.
	 * @return string Sanitized value.
	 */
	public function sanitize_content( $option, $value ) {
		switch ( $option ) {
			case 'description':
				$value = faz_sanitize_content( $value );
				break;
			default:
				$value = faz_sanitize_text( $value );
				break;
		}
		return $value;
	}

	/**
	 * Returns default contents to be loaded while creating the banner.
	 *
	 * @return array
	 */
	public static function get_default_contents() {
		$contents = wp_cache_get( 'faz_contents_default', 'faz_banner_contents' );
		if ( ! $contents ) {
			$contents = faz_read_json_file( dirname( __FILE__ ) . '/contents/default.json' );
			wp_cache_set( 'faz_contents_default', $contents, 'faz_banner_contents', 12 * HOUR_IN_SECONDS );
		}
		return $contents;
	}

	/**
	 * Get contents by language.
	 *
	 * @param string $lang Language code.
	 * @param string $key Specific key if any.
	 * @return array
	 */
	public function get_translations( $lang = '', $key = '' ) {
		$contents = wp_cache_get( 'faz_contents_' . $lang, 'faz_banner_contents' );
		$law      = $this->get_law();
		$translated     = \FazCookie\Admin\Modules\Languages\Includes\Controller::get_instance()->is_faz_translated($lang);
		$upload_dir    = wp_upload_dir();
		if ( ! $contents ) {
			if($translated) {
				$translation = faz_read_json_file( $upload_dir['basedir'] . '/fazcookie/languages/banners/' . esc_html( $lang ) . '.json' );
				if($translation) {
					$contents = $translation['banner_data'];
				}
				if(!$contents) {
					$contents = faz_read_json_file( dirname( __FILE__ ) . '/contents/' . esc_html( $lang ) . '.json' );
				}
			}
			if ( empty( $contents ) ) {
				$contents = faz_read_json_file( dirname( __FILE__ ) . '/contents/en.json' );
			}
			wp_cache_set( 'faz_contents_' . $lang, $contents, 'faz_banner_contents', 12 * HOUR_IN_SECONDS );
		}
		return isset( $contents[ $law ] ) && is_array( $contents[ $law ] ) ? $contents[ $law ] : array();
	}
	/**
	 * Get selected languages for the banner.
	 *
	 * @return array
	 */
	public function get_selected_languages() {
		$settings = $this->get_settings();
		return isset( $settings['settings']['languages']['selected'] ) ? $settings['settings']['languages']['selected'] : array();
	}

	/**
	 * Check if an associative array is empty.
	 *
	 * @param array $array Array to be checked.
	 * @return array
	 */
	public function array_empty_assoc( $array = array() ) {
		return array_filter( self::compare( $array ) );
	}

	/**
	 * Compare two deeply neseted array.
	 *
	 * @param array   $contents Array of contents.
	 * @param boolean $defaults Default items in an array.
	 * @param array   $result Final result.
	 * @return array
	 */
	public static function compare( $contents = array(), $defaults = false, $result = array() ) {
		$defaults = false === $defaults ? self::get_default_contents() : $defaults;
		foreach ( $defaults as $key => $data ) {
			$value    = isset( $contents[ $key ] ) ? $contents[ $key ] : $data;
			$defaults = $data;
			if ( is_array( $value ) ) {
				$result = self::compare( $value, $defaults, $result );
			} else {
				$result[] = $value;
			}
		}
		return $result;
	}
}
