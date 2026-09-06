<?php
/**
 * Class Cookie_Categories file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Cookies\Includes;

use FazCookie\Includes\Store;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookie category Operation
 *
 * @class       Cookie_Categories
 * @version     3.0.0
 * @package     FazCookie
 */
class Cookie_Categories extends Store {

	/**
	 * Data array, with defaults.
	 *
	 * @var array
	 */
	protected $data = array(
		'name'               => '',
		'slug'               => '',
		'description'        => array(),
		'prior_consent'      => false,
		'visibility'         => true,
		'priority'           => 0,
		'meta'               => array(),
		'date_created'       => '',
		'date_modified'      => '',
		'language'           => 'en',
		'sell_personal_data' => true,
		'share_personal_data' => true,
		'cookies'            => array(),
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
		if ( isset( $data->category_id ) ) {
			$this->set_id( $data->category_id );
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
		Category_Controller::get_instance()->create_item( $object );
	}
	/**
	 * Read cookie data from database
	 *
	 * @param object $category instance of Cookie_Categories.
	 * @return void
	 */
	public function read( $category ) {
		$this->set_defaults();
		$data = Category_Controller::get_instance()->get_item( $category->get_id() );
		$this->set_data( $data );
	}

	/**
	 * Assign data to objects
	 *
	 * @param array|object $data Array of data.
	 * @return void
	 */
	public function set_data( $data ) {
		if ( isset( $data->category_id ) ) {
			$this->set_multi_item_data(
				array(
					'name'               => $data->name,
					'slug'               => $data->slug,
					'description'        => $data->description,
					'prior_consent'      => $data->prior_consent,
					'visibility'         => $data->visibility,
					'priority'           => $data->priority,
					'sell_personal_data' => $data->sell_personal_data,
					// `?? true` guards the upgrade window where a row predates the
					// share_personal_data column (before dbDelta adds it): default
					// to opt-out-able, matching the schema default.
					'share_personal_data' => $data->share_personal_data ?? true,
					'meta'               => $data->meta,
					'cookies'            => $data->cookies,
					'date_created'       => $data->date_created,
					'date_modified'      => $data->date_modified,
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
	 * Update cookie category data
	 *
	 * @param object $object Instance of Cookie.
	 * @return void
	 */
	public function update( $object ) {
		Category_Controller::get_instance()->update_item( $object );
	}

	/**
	 * Delete a cookie category from database
	 *
	 * @param object $object Category object.
	 * @return void
	 */
	public function remove( $object ) {
		Category_Controller::get_instance()->delete_item( $object );
	}

	/**
	 * Get translated cookie category name.
	 *
	 * @param string $language Language code.
	 * @return array|string
	 */
	public function get_name( $language = '' ) {
		$contents        = array();
		$prop            = 'name';
		$data            = $this->normalize_multilingual_data( $this->get_object_data( $prop ) );
		$languages       = faz_selected_languages( $language );
		$default         = faz_default_language();
		$default_content = isset( $data[ $default ] ) ? $data[ $default ] : $this->get_translations( $default, $prop );
		foreach ( $languages as $lang ) {
			$content           = isset( $data[ $lang ] ) ? $data[ $lang ] : '';
			$content           = empty( $content ) ? $this->get_translations( $lang, $prop ) : $content;
			$translated        = $this->translate_stored_value( $lang, $prop, $content );
			$content           = '' !== $translated ? $translated : $content;
			$content           = empty( $content ) && 'view' === $this->get_context() ? $default_content : $content;
			$contents[ $lang ] = is_string( $content ) ? stripslashes( wp_kses_post( $content ) ) : '';
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $lang => $content ) {
				if ( ! isset( $contents[ $lang ] ) && is_string( $content ) ) {
					$contents[ $lang ] = stripslashes( wp_kses_post( $content ) );
				}
			}
		}
		if ( '' !== $language ) {
			return isset( $contents[ $language ] ) ? $contents[ $language ] : '';
		}
		return $contents;
	}

	/**
	 * Return prior consent of the category.
	 *
	 * @return boolean
	 */
	public function get_prior_consent() {
		return (bool) $this->get_object_data( 'prior_consent' );
	}

	/**
	 * Return visibility of the category.
	 *
	 * @return boolean
	 */
	public function get_visibility() {
		return (bool) $this->get_object_data( 'visibility' );
	}

	/**
	 * Return the priority of the category.
	 *
	 * @return int
	 */
	public function get_priority() {
		return absint( $this->get_object_data( 'priority' ) );
	}

	/**
	 * Return true if the category sells any personal data.
	 *
	 * @return boolean
	 */
	public function get_sell_personal_data() {
		return (bool) $this->get_object_data( 'sell_personal_data' );
	}

	/**
	 * Return true if the category SHARES personal data for cross-context
	 * behavioural advertising (CPRA §1798.140(ah)). Distinct from a sale; both
	 * are covered by the combined "Do Not Sell or Share" opt-out.
	 *
	 * @return boolean
	 */
	public function get_share_personal_data() {
		return (bool) $this->get_object_data( 'share_personal_data' );
	}

	/**
	 * Return category meta data.
	 *
	 * @return array
	 */
	public function get_meta() {
		$meta = array();
		$data = $this->get_object_data( 'meta' );
		foreach ( $data as $key => $item ) {
			$meta[ $key ] = sanitize_textarea_field( $item );
		}
		return $meta;
	}

	/**
	 * Return list of cookies associated to each category
	 *
	 * @return array
	 */
	public function get_cookies() {
		return $this->get_object_data( 'cookies' );
	}

	/**
	 * Set the name of the category to an object.
	 *
	 * @param string|array $data Name of the category.
	 * @return void
	 */
	public function set_name( $data ) {
		$data      = $this->normalize_multilingual_data( $data );
		$name      = array();
		$languages = faz_selected_languages();
		foreach ( $languages as $lang ) {
			$name[ $lang ] = isset( $data[ $lang ] ) && is_string( $data[ $lang ] ) ? wp_filter_post_kses( $data[ $lang ] ) : '';
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $lang => $value ) {
				if ( ! isset( $name[ $lang ] ) && is_string( $value ) ) {
					$name[ $lang ] = wp_filter_post_kses( $value );
				}
			}
		}
		$this->set_object_data( 'name', $name );
	}

	/**
	 * Set prior consent of a category
	 *
	 * @param boolean $data True if it sells personal data.
	 * @return void
	 */
	public function set_prior_consent( $data ) {
		$this->set_object_data( 'prior_consent', (bool) $data );
	}

	/**
	 * Set visibility of a category
	 *
	 * @param boolean $data true or false based on the visibility of a category.
	 * @return void
	 */
	public function set_visibility( $data ) {
		$this->set_object_data( 'visibility', (bool) $data );
	}

	/**
	 * Set true/false based on the personal information stored.
	 *
	 * @param boolean $data true if sells personl data.
	 * @return void
	 */
	public function set_sell_personal_data( $data ) {
		$this->set_object_data( 'sell_personal_data', (bool) $data );
	}

	/**
	 * Set whether the category SHARES personal data for cross-context
	 * behavioural advertising (CPRA), distinct from a sale.
	 *
	 * @param boolean $data true if the category shares personal data.
	 * @return void
	 */
	public function set_share_personal_data( $data ) {
		$this->set_object_data( 'share_personal_data', (bool) $data );
	}

	/**
	 * Priority of a category. Based on this category will be ordered.
	 *
	 * @param  integer $data priority number.
	 * @return void
	 */
	public function set_priority( $data ) {
		$this->set_object_data( 'priority', absint( $data ) );
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
	 * Assign cookies to the object
	 *
	 * @param array $data cookie array.
	 * @return void
	 */
	public function set_cookies( $data ) {
		$this->set_object_data( 'cookies', $data );
	}

	/**
	 * Get contents by language.
	 *
	 * @param string $lang Language code.
	 * @param string $key Specific key if any.
	 * @return string
	 */
	public function get_translations( $lang = '', $key = '' ) {
		$slug      = $this->get_slug();
		$cache_key = 'faz_category_contents_v2_' . $lang;
		$contents  = wp_cache_get( $cache_key, 'faz_category_contents' );
		if ( false === $contents ) {
			$safe_lang  = sanitize_file_name( $lang );
			$upload_dir = wp_upload_dir();
			$translation = faz_read_json_file( $upload_dir['basedir'] . '/fazcookie/languages/banners/' . $safe_lang . '.json' );
			$bundled = faz_read_json_file( __DIR__ . "/contents/categories/{$safe_lang}.json" );
			$english = faz_read_json_file( __DIR__ . '/contents/categories/en.json' );
			// Resolve per field: a partial uploaded catalogue must not hide the
			// bundled translation for all of the other categories.
			$contents = is_array( $english ) ? $english : array();
			foreach ( array( $bundled, $translation['category_data'] ?? array() ) as $catalogue ) {
				if ( ! is_array( $catalogue ) ) {
					continue;
				}
				foreach ( $catalogue as $category => $fields ) {
					foreach ( is_array( $fields ) ? $fields : array() as $field => $value ) {
						if ( is_string( $value ) && '' !== trim( $value ) ) {
							if ( 'en' !== $lang && $value === ( $english[ $category ][ $field ] ?? null ) ) {
								continue; // An English fallback must not replace a local translation.
							}

							$contents[ $category ][ $field ] = $value;
						}
					}
				}
			}
			wp_cache_set( $cache_key, $contents, 'faz_category_contents', 12 * HOUR_IN_SECONDS );
		}
		return isset( $contents[ $slug ][ $key ] ) ? $contents[ $slug ][ $key ] : '';
	}

	/**
	 * Replace English defaults previously materialised in non-English slots.
	 *
	 * The editor used to allow edits in the default language only, so saving
	 * wrote "Necessary" into the ru and uk slots. Those slots are then no longer
	 * empty, so no fallback fires and the English text is stored data — which is
	 * why shipping a catalogue is not enough on its own to repair an existing
	 * install.
	 *
	 * The test is equality with the shipped English default, compared with tags
	 * stripped so an editor-flattened description still matches. Wording that
	 * differs is administrator-authored and preserved.
	 *
	 * The one case this cannot serve: an administrator who deliberately wants the
	 * English word in a non-English slot (say "Marketing" left as-is in Russian)
	 * gets the bundled translation instead. Stored text carries no record of
	 * whether it was chosen or inherited, so the two are indistinguishable here,
	 * and repairing the far more common case is the better trade.
	 *
	 * @param string $lang Target language.
	 * @param string $key Field name.
	 * @param mixed  $source Stored value.
	 * @return string Translation, or empty to preserve the stored value.
	 */
	protected function translate_stored_value( $lang, $key, $source ) {
		if ( 'en' === $lang || ! is_string( $source ) || '' === $source ) {
			return '';
		}
		static $catalogue = null;
		if ( null === $catalogue ) {
			$catalogue = faz_read_json_file( __DIR__ . '/contents/categories/en.json' );
		}
		$english   = $catalogue[ $this->get_slug() ][ $key ] ?? '';
		if ( '' === $english || trim( wp_strip_all_tags( $source ) ) !== trim( wp_strip_all_tags( $english ) ) ) {
			return '';
		}
		return $this->get_translations( $lang, $key );
	}
}
