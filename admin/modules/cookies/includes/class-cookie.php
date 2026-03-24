<?php
/**
 * Class Cookie file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Cookies\Includes;

use FazCookie\Includes\Store;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Cookie
 * @version     3.0.0
 * @package     FazCookie
 */
class Cookie extends Store {

	/**
	 * Data array, with defaults.
	 *
	 * @var array
	 */
	protected $data = array(
		'name'          => '',
		'slug'          => '',
		'description'   => array(),
		'duration'      => array(),
		'domain'        => '',
		'category'      => '',
		'type'          => '',
		'discovered'    => false,
		'url_pattern'   => '',
		'meta'          => '',
		'date_created'  => null,
		'date_modified' => null,
	);

	/**
	 * Constructor
	 *
	 * @param mixed $data ID or slug of the cookie.
	 */
	public function __construct( $data = '' ) {
		parent::__construct( $data );
		if ( is_int( $data ) && 0 !== $data ) {
			$this->set_id( $data );
		}
		if ( isset( $data->cookie_id ) ) {
			$this->set_id( $data->cookie_id );
			$this->read_direct( $data );
		} else {
			$this->get_data_from_db();
		}
	}

	/**
	 * Create a new cookie
	 *
	 * @param object $object instance of Cookie_Categories.
	 * @return void
	 */
	public function create( $object ) {
		Cookie_Controller::get_instance()->create_item( $object );
	}

	/**
	 * Read cookie data from database
	 *
	 * @param object $cookie instance of Cookie_Categories.
	 * @return void
	 */
	public function read( $cookie ) {
		$this->set_defaults();
		$data = Cookie_Controller::get_instance()->get_item( $cookie->get_id() );
		$this->set_data( $data );
	}

	/**
	 * Assign data to objects
	 *
	 * @param array|object $data Array of data.
	 * @return void
	 */
	public function set_data( $data ) {
		if ( isset( $data->cookie_id ) ) {
			$this->set_multi_item_data(
				array(
					'name'          => $data->name,
					'slug'          => $data->slug,
					'description'   => $data->description,
					'domain'        => $data->domain,
					'duration'      => $data->duration,
					'category'      => $data->category,
					'type'          => $data->type,
					'discovered'    => $data->discovered,
					'url_pattern'   => $data->url_pattern,
					'meta'          => $data->meta,
					'date_created'  => $data->date_created,
					'date_modified' => $data->date_modified,
				)
			);
			$this->set_loaded( true );
		}
	}

	/**
	 * Read directly from the data object given.
	 * Used for assigning data to object if it is already fetched from API or DB.
	 *
	 * @param array|object $data Category data.
	 * @return void
	 */
	public function read_direct( $data ) {
		$this->set_data( $data );
	}

	/**
	 * Get an array of data required for APIs.
	 *
	 * @return array
	 */
	public function get_prepared_data() {
		return array(
			'id'            => $this->get_id(),
			'name'          => $this->get_name(),
			'slug'          => $this->get_slug(),
			'description'   => $this->get_description(),
			'duration'      => $this->get_duration(),
			'type'          => $this->get_type(),
			'domain'        => $this->get_domain(),
			'discovered'    => $this->is_discovered(),
			'url_pattern'   => $this->get_url_pattern(),
			'category'      => $this->get_category(),
			'date_created'  => $this->get_date_created(),
			'date_modified' => $this->get_date_modified(),
		);
	}

	/**
	 * Update cookie category data
	 *
	 * @param object $object Instance of Cookie.
	 * @return void
	 */
	public function update( $object ) {
		Cookie_Controller::get_instance()->update_item( $object );
	}

	/**
	 * Delete a cookie category from database
	 *
	 * @param object $object Cookie object.
	 * @return void
	 */
	public function remove( $object ) {
		Cookie_Controller::get_instance()->delete_item( $object );
	}

	/**
	 * Get the type of a cookie
	 *
	 * @return int
	 */
	public function get_type() {
		return absint( $this->get_object_data( 'type' ) );
	}

	/**
	 * Get the cookie duration
	 *
	 * @return array
	 */
	public function get_duration() {
		$contents        = array();
		$prop            = 'duration';
		$data            = $this->normalize_multilingual_data( $this->get_object_data( $prop ) );
		$default         = faz_default_language();
		$languages       = faz_selected_languages();
		$default_content = isset( $data[ $default ] ) ? $data[ $default ] : '';
		foreach ( $languages as $lang ) {
			$content           = isset( $data[ $lang ] ) ? $data[ $lang ] : '';
			$content           = empty( $content ) ? $this->get_translations( $lang, $prop ) : $content;
			$content           = empty( $content ) && 'view' === $this->get_context() ? $default_content : $content;
			$contents[ $lang ] = stripslashes( wp_kses_post( $content ) );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $lang => $content ) {
				if ( ! isset( $contents[ $lang ] ) && is_string( $content ) ) {
					$contents[ $lang ] = stripslashes( wp_kses_post( $content ) );
				}
			}
		}
		return $contents;
	}

	/**
	 * Return the cookie domain
	 *
	 * @return string
	 */
	public function get_domain() {
		return sanitize_text_field( $this->get_object_data( 'domain' ) );
	}

	/**
	 * Get cookie category id
	 *
	 * @return int
	 */
	public function get_category() {
		return absint( $this->get_object_data( 'category' ) );
	}

	/**
	 * Check whether the cookie is added manually or not.
	 *
	 * @return boolean
	 */
	public function is_discovered() {
		return (bool) $this->get_object_data( 'discovered' );
	}

	/**
	 * Get URL patterns for script blocking purposes.
	 *
	 * @return string
	 */
	public function get_url_pattern() {
		return $this->get_object_data( 'url_pattern' );
	}

	/**
	 * Return cookie meta data.
	 *
	 * @return array
	 */
	public function get_meta() {
		$meta = array();
		$data = $this->get_object_data( 'meta' );
		$data = ( isset( $data ) && is_array( $data ) ) ? $data : array();
		foreach ( $data as $key => $item ) {
			$meta[ $key ] = sanitize_textarea_field( $item );
		}
		return $meta;
	}

	/**
	 * Set the cookie type
	 *
	 * @param string|int $type Cookie type.
	 * @return void
	 */
	public function set_type( $type ) {
		$this->set_object_data( 'type', absint( $type ) );
	}

	/**
	 * Set the cookie duration
	 *
	 * @param string|array $data Cookie duration.
	 * @return void
	 */
	public function set_duration( $data ) {
		$data      = $this->normalize_multilingual_data( $data );
		$duration  = array();
		$languages = faz_selected_languages();
		// Ensure selected languages are always present.
		foreach ( $languages as $lang ) {
			$duration[ $lang ] = isset( $data[ $lang ] ) && is_string( $data[ $lang ] ) ? wp_filter_post_kses( $data[ $lang ] ) : '';
		}
		// Preserve extra language keys already in the payload so that
		// translations are not silently lost when a language is deselected.
		if ( is_array( $data ) ) {
			foreach ( $data as $lang => $value ) {
				if ( ! isset( $duration[ $lang ] ) && is_string( $value ) ) {
					$duration[ $lang ] = wp_filter_post_kses( $value );
				}
			}
		}
		$this->set_object_data( 'duration', $duration );
	}

	/**
	 * Set cookie category id
	 *
	 * @param integer $category Cookie category ID.
	 * @return void
	 */
	public function set_category( $category ) {
		$this->set_object_data( 'category', absint( $category ) );
	}

	/**
	 * Set the status of a cookie if it is either added manually or automatically
	 *
	 * @param boolean $data True if cookie is added manually.
	 * @return void
	 */
	public function set_discovered( $data ) {
		$this->set_object_data( 'discovered', (bool) $data );
	}

	/**
	 * Set cookie domain
	 *
	 * @param string $data Cookie domain.
	 * @return void
	 */
	public function set_domain( $data ) {
		$this->set_object_data( 'domain', sanitize_text_field( $data ) );
	}

	/**
	 * Set URL pattern
	 *
	 * @param string $data URL pattern.
	 * @return void
	 */
	public function set_url_pattern( $data ) {
		$this->set_object_data( 'url_pattern', sanitize_text_field( $data ) );
	}

	/**
	 * Set meta data
	 *
	 * @param array $data Meta data array.
	 * @return void
	 */
	public function set_meta( $data ) {
		$this->set_object_data( 'meta', $data );
	}
	/**
	 * Get contents by language.
	 *
	 * @param string $lang Language code.
	 * @param string $key Specific key if any.
	 * @return string
	 */
	public function get_translations( $lang = '', $key = '' ) {
		return '';
	}
}
