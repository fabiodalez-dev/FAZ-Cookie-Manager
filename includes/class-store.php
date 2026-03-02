<?php
/**
 * Plugin store abstract class.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @author     Sarath GP <sarath.gp@mozilor.com>
 * @package    FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Exception;
use WP_Error;

/**
 * Abstract data class for CRUD operations
 *
 * @version  3.0.0
 * @package  FazCookie\Includes
 */
abstract class Store {

	/**
	 * ID for this object.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	protected $id = 0;

	/**
	 * Core data for this object.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	protected $data = array();

	/**
	 * Default data for this object.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	protected $default_data = array();

	/**
	 * Current language
	 *
	 * @var string
	 */
	protected $language = '';

	/**
	 * Core data changes for this object.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	protected $revisions = array();

	/**
	 * This is false until the object is read from the DB.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $loaded = false;

	/**
	 * Mode of reading the object. Possible values edit/view.
	 *
	 * @var string
	 */
	protected $context = 'view';

	/**
	 * Meta data storage.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	protected $meta_data = array();

	/**
	 * Default constructor.
	 *
	 * @param int|object|array $read ID to load from the DB (optional) or already queried data.
	 */
	public function __construct( $read = 0 ) {
		$this->default_data = $this->data;
		// $read is used by child classes; kept in signature for compatibility.
		unset( $read );
	}

	/**
	 * Read data directly from DB
	 *
	 * @return void
	 */
	public function get_data_from_db() {
		if ( $this->get_id() > 0 ) {
			$this->read( $this );
		}
	}

		/**
		 * Create if id not exist or update if exist
		 *
		 * @return integer
		 */
	public function save() {
		if ( $this->get_id() ) {
			$this->update( $this );
		} else {
			$this->create( $this );
		}
		return $this->get_id();
	}

	/**
	 * Delete an item from the database.
	 *
	 * @return void
	 */
	public function delete() {
		if ( $this->get_id() > 0 ) {
			$this->remove( $this );
		}
	}

	/**
	 * Remove an existing item
	 *
	 * @param object $object Object of the corresponding item.
	 * @return void|WP_Error
	 */
	protected function remove( $object ) {
		// translators: %s: Class method name.
		return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'faz-cookie-manager' ), __METHOD__ ), array( 'status' => 405 ) );
	}

	/**
	 * Read an existing item
	 *
	 * @param object $object Object of the corresponding item.
	 * @return void|WP_Error
	 */
	protected function read( $object ) {
		// translators: %s: Class method name.
		return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'faz-cookie-manager' ), __METHOD__ ), array( 'status' => 405 ) );
	}

	/**
	 * Update an existing item
	 *
	 * @param object|array $object Object of the corresponding item.
	 * @return void|WP_Error
	 */
	protected function update( $object ) {
		// translators: %s: Class method name.
		return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'faz-cookie-manager' ), __METHOD__ ), array( 'status' => 405 ) );
	}

	/**
	 * Create an existing item
	 *
	 * @param object $object Object of the corresponding item.
	 * @return void|WP_Error
	 */
	protected function create( $object ) {
		// translators: %s: Class method name.
		return new WP_Error( 'invalid-method', sprintf( __( "Method '%s' not implemented. Must be overridden in subclass.", 'faz-cookie-manager' ), __METHOD__ ), array( 'status' => 405 ) );
	}

	/**
	 * Set all props to default values.
	 *
	 * @since 3.0.0
	 */
	public function set_defaults() {
		$this->data      = $this->default_data;
		$this->revisions = array();
		$this->set_loaded( false );
	}

	/**
	 * Set object loaded property.
	 *
	 * @since 3.0.0
	 * @param boolean $loaded Should read?.
	 */
	public function set_loaded( $loaded = true ) {
		$this->loaded = (bool) $loaded;
	}

	/**
	 * Get object loaded property.
	 *
	 * @since  3.0.0
	 * @return boolean
	 */
	public function get_loaded() {
		return (bool) $this->loaded;
	}

	/** Getter functions */

	/**
	 * Returns all data for this object.
	 *
	 * @since  3.0.0
	 * @return array
	 */
	public function get_data() {
		return array_merge( array( 'id' => $this->get_id() ), $this->data );
	}

	/**
	 * Get All Meta Data.
	 *
	 * @since 3.0.0
	 * @return array
	 */
	public function get_meta_data() {
		return array_values( array_filter( $this->meta_data, array( $this, 'filter_null_meta' ) ) );
	}

	/**
	 * Filter null meta values from array.
	 *
	 * @since  3.0.0
	 * @param mixed $meta Meta value to check.
	 * @return bool
	 */
	protected function filter_null_meta( $meta ) {
		return ! is_null( $meta->value );
	}

	/**
	 * Returns only the changes to the object properties.
	 *
	 * @return array
	 */
	public function get_revisions() {
		return $this->revisions;
	}

	/**
	 * Returns the unique ID for this object.
	 *
	 * @since  3.0.0
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Returns the name of the item.
	 *
	 * @since  3.0.0
	 * @return string
	 */
	public function get_name() {
		return stripslashes( $this->get_object_data( 'name' ) );
	}

	/**
	 * Get slug.
	 *
	 * @since 3.0.0
	 * @return string $slug Slug of the item.
	 */
	public function get_slug() {
		$slug = $this->get_object_data( 'slug' );
		if ( empty( $slug ) ) {
			$slug = $this->get_name();
		}
		return sanitize_title( $slug );
	}

	/**
	 * Return the description if any
	 *
	 * @param string $language Current language.
	 * @return array|string
	 */
	public function get_description( $language = '' ) {
		$contents        = array();
		$prop            = 'description';
		$data            = $this->get_object_data( $prop );
		$languages       = faz_selected_languages( $language );
		$default_content = isset( $data['en'] ) ? $data['en'] : $this->get_translations( 'en', $prop );

		foreach ( $languages as $lang ) {
			$content           = isset( $data[ $lang ] ) ? $data[ $lang ] : '';
			$content           = empty( $content ) ? $this->get_translations( $lang, $prop ) : $content;
			$content           = empty( $content ) && 'view' === $this->get_context() ? $default_content : $content;
			$contents[ $lang ] = stripslashes( wp_kses_post( $content ) );
		}
		if ( '' !== $language ) {
			return isset( $contents[ $language ] ) ? $contents[ $language ] : '';
		}
		return $contents;
	}

	/**
	 * Get item language
	 *
	 * @return string|false
	 */
	public function get_language() {
		return is_string( $this->language ) ? sanitize_text_field( $this->language ) : false;
	}
	/**
	 * Get date_created
	 *
	 * @since  3.0.0
	 * @return string|null Date string if the date is set or null if there is no date.
	 */
	public function get_date_created() {
		return $this->get_object_data( 'date_created' );
	}

	/**
	 * Get date_modified
	 *
	 * @since  3.0.0
	 * @return string|null Date string if the date is set or null if there is no date.
	 */
	public function get_date_modified() {
		return $this->get_object_data( 'date_modified' );
	}

	/**
	 * Gets a prop for a getter method.
	 *
	 * Gets the value from either current pending changes, or the data itself.
	 *
	 * @since  3.0.0
	 * @param  string $data Name of prop to get.
	 * @return mixed
	 */
	protected function get_object_data( $data ) {
		$value = null;
		if ( array_key_exists( $data, $this->data ) ) {
			$value = array_key_exists( $data, $this->revisions ) ? $this->revisions[ $data ] : $this->data[ $data ];
		}
		return $value;
	}

	/**
	 * Returns the view context.
	 *
	 * @return string
	 */
	public function get_context() {
		return sanitize_text_field( $this->context );
	}
	/** Setter functions */
	/**
	 * Sets a prop for a setter method.
	 *
	 * This stores changes in a special array so we can track what needs saving
	 * the the DB later.
	 *
	 * @since 3.0.0
	 * @param string $data Name of prop to set.
	 * @param mixed  $value Value of the prop.
	 */
	protected function set_object_data( $data, $value ) {
		if ( array_key_exists( $data, $this->data ) ) {
			if ( true === $this->loaded ) {
				if ( $value !== $this->data[ $data ] || array_key_exists( $data, $this->revisions ) ) {
					$this->revisions[ $data ] = $value;
				}
			} else {
				$this->data[ $data ] = $value;
			}
		}
	}

	/**
	 * Set a collection of props in one go, collect any errors, and return the result.
	 * Only sets using public methods.
	 *
	 * @since  3.0.0
	 *
	 * @param array $data Key value pairs to set. Key is the prop and should map to a setter function name.
	 *
	 * @return bool|WP_Error
	 */
	public function set_multi_item_data( $data ) {
		$errors = false;

		foreach ( $data as $item => $value ) {
			try {
				$setter = "set_$item";

				if ( is_callable( array( $this, $setter ) ) ) {
					$this->{$setter}( $value );
				}
			} catch ( Exception $e ) {
				if ( ! $errors ) {
					$errors = new WP_Error();
				}
				$errors->add( 101, $e->getMessage() );
			}
		}
		return $errors && count( $errors->get_error_codes() ) ? $errors : true;
	}

	/**
	 * Set all meta data from array.
	 *
	 * @since 3.0.0
	 * @param array $data Key/Value pairs.
	 */
	public function set_meta_data( $data ) {
		if ( ! empty( $data ) && is_array( $data ) ) {
			foreach ( $data as $meta ) {
				$meta = (array) $meta;
				if ( isset( $meta['key'], $meta['value'] ) ) {
					$this->meta_data[ $meta['key'] ] = $meta['value'];
				}
			}
		}
	}

	/**
	 * Set ID.
	 *
	 * @since 3.0.0
	 * @param int $id ID.
	 */
	public function set_id( $id ) {
		$this->id = absint( $id );
	}

	/**
	 * Set name.
	 *
	 * @since 3.0.0
	 * @param string $name Name of the item.
	 */
	public function set_name( $name ) {
		$this->set_object_data( 'name', sanitize_text_field( $name ) );
	}

	/**
	 * Set slug.
	 *
	 * @since 3.0.0
	 * @param string $slug Slug of the item.
	 */
	public function set_slug( $slug ) {
		$this->set_object_data( 'slug', sanitize_title( $slug ) );
	}

	/**
	 * Set description.
	 *
	 * @since 3.0.0
	 * @param string|array $data Item description.
	 */
	public function set_description( $data ) {
		$description = array();
		$languages   = faz_selected_languages();
		foreach ( $languages as $lang ) {
			$description[ $lang ] = isset( $data[ $lang ] ) ? wp_filter_post_kses( $data[ $lang ] ) : '';
		}
		$this->set_object_data( 'description', $description );
	}

	/**
	 * Set date_created
	 *
	 * @since  3.0.0
	 * @param string|integer|null $date UTC timestamp, or ISO 8601 DateTime. If the DateTime string has no timezone or offset, WordPress site timezone will be assumed. Null if there is no date.
	 */
	public function set_date_created( $date ) {
		$this->set_object_data( 'date_created', $date );
	}
	/**
	 * Set date_created
	 *
	 * @since  3.0.0
	 * @param string|integer|null $date UTC timestamp, or ISO 8601 DateTime. If the DateTime string has no timezone or offset, WordPress site timezone will be assumed. Null if there is no date.
	 */
	public function set_date_modified( $date ) {
		$this->set_object_data( 'date_modified', $date );
	}

	/**
	 * Sets the item language
	 *
	 * @param string $language Language of the item.
	 * @return void
	 */
	public function set_language( $language ) {
		$this->language = is_string( $language ) ? sanitize_text_field( $language ) : false;
	}

	/**
	 * Set the context
	 *
	 * @param string $context Context.
	 * @return void
	 */
	public function set_context( $context = '' ) {
		$this->context = sanitize_text_field( $context );
	}

	/**
	 * Get translations
	 *
	 * @param string $lang Language code.
	 * @param string $key Specific key if any.
	 * @return mixed
	 */
	public function get_translations( $lang = '', $key = '' ) {
		return array();
	}
}
