<?php
/**
 * Plugin store abstract class.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @author     Fabio D'Alessandro
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
	 * Option written once the stored cookie and category text has been unslashed.
	 *
	 * Until this fix the multilingual setters (cookie description and duration,
	 * category name and description) sanitised with wp_filter_post_kses(). That
	 * function is a `pre_*` filter: it expects SLASHED input and returns SLASHED
	 * output — addslashes( wp_kses( stripslashes( $data ) ) ). Our callers hand it
	 * plain values, so every apostrophe reached the table as \' ("It\'s"), and a
	 * real backslash was eaten on the way in. The getters papered over it with a
	 * stripslashes() on read, but every reader that goes to the columns directly
	 * — [faz_cookie_table], the faz/cookie-table block, the cookie policy
	 * generator, the settings export — printed the backslash to visitors.
	 *
	 * The setters now use wp_kses_post(), which does not slash, and
	 * Activator::unslash_cookie_text() strips the one level of slashes from the
	 * rows written before. Until that migration has run, the rows on disk are
	 * still the old shape, so the getters keep unslashing them (see
	 * unslash_legacy()); after it, they return what is stored.
	 */
	const TEXT_UNSLASHED_OPTION = 'faz_cookie_text_unslashed_done';

	/**
	 * Whether the stored cookie/category text may still carry the legacy slashes.
	 *
	 * @return bool
	 */
	public static function legacy_slashes_pending() {
		return ! get_option( self::TEXT_UNSLASHED_OPTION );
	}

	/**
	 * Undo the legacy slashing on a value read from the database, only while the
	 * one-time migration has not run yet.
	 *
	 * Before the migration this is the stripslashes() the getters always applied,
	 * so nothing a visitor or administrator sees changes in that window. After it
	 * the stored value is already clean and is returned untouched — stripping it
	 * again would eat a backslash the administrator actually typed.
	 *
	 * @param string $value Value as read from the database.
	 * @return string
	 */
	protected static function unslash_legacy( $value ) {
		return self::legacy_slashes_pending() ? stripslashes( $value ) : $value;
	}

	/**
	 * Strip one level of slashes from a stored multilingual text column.
	 *
	 * Accepts the column as stored (a JSON object of language => text, or a
	 * legacy plain string) or already decoded, and returns it in the same shape.
	 * stripslashes() is applied to string values only; anything else (null,
	 * numbers, nested arrays from a malformed row) is passed through.
	 *
	 * stripslashes() is the exact inverse of the addslashes() the old setters
	 * applied, so a row written through them comes back byte-for-byte as the
	 * administrator typed it. It is NOT idempotent (\\ becomes \, then nothing),
	 * which is why callers must apply it exactly once per legacy value.
	 *
	 * @param mixed $value Column value.
	 * @return mixed Same shape, unslashed.
	 */
	public static function unslash_text_column( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $item ) ) {
					$value[ $key ] = stripslashes( $item );
				}
			}
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			$unslashed = self::unslash_text_column( $decoded );
			if ( $unslashed === $decoded ) {
				return $value; // Nothing to strip: keep the stored bytes as they are.
			}
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $unslashed ) : json_encode( $unslashed );
			return false === $encoded ? $value : $encoded;
		}
		if ( JSON_ERROR_NONE === json_last_error() ) {
			// A JSON scalar ("\"text\"", a number): not a shape these columns use.
			return $value;
		}
		return stripslashes( $value );
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
		$data            = $this->normalize_multilingual_data( $this->get_object_data( $prop ) );
		$languages       = faz_selected_languages( $language );
		$default         = faz_default_language();
		$default_content = isset( $data[ $default ] ) ? $data[ $default ] : $this->get_translations( $default, $prop );

		foreach ( $languages as $lang ) {
			$content = isset( $data[ $lang ] ) ? $data[ $lang ] : '';
			if ( empty( $content ) ) {
				$content = $this->get_translations( $lang, $prop );
			} else {
				// Non-empty does not mean localised. Scanners and imports write
				// the same English sentence into every language slot, so an
				// Italian row could hold the plugin's own English description and
				// be skipped here for being non-empty — the mixed-language
				// declaration the catalogue exists to fix, surviving inside it.
				//
				// Attempting it is safe because the resolver stands down on
				// anything that is not the bundled English text for this cookie
				// (Cookie_Content_I18n::is_stock_description), so an
				// administrator's own wording returns '' and is preserved. The
				// protection lives in the resolver, not in this branch.
				$translated = $this->translate_stored_value( $lang, $prop, $content );
				$content    = '' !== $translated ? $translated : $content;
			}
			$content           = empty( $content ) && 'view' === $this->get_context() ? $default_content : $content;
			$contents[ $lang ] = is_string( $content ) ? self::unslash_legacy( wp_kses_post( $content ) ) : '';
		}
		// Preserve extra language keys beyond faz_selected_languages().
		foreach ( $data as $lang => $content ) {
			if ( ! isset( $contents[ $lang ] ) && is_string( $content ) ) {
				$contents[ $lang ] = self::unslash_legacy( wp_kses_post( $content ) );
			}
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
		$data        = $this->normalize_multilingual_data( $data );
		$description = array();
		$languages   = faz_selected_languages();
		// wp_kses_post(), not wp_filter_post_kses(): the latter slashes its output
		// and wrote \' into the table. See Store::TEXT_UNSLASHED_OPTION.
		// Ensure selected languages are always present.
		foreach ( $languages as $lang ) {
			$description[ $lang ] = isset( $data[ $lang ] ) && is_string( $data[ $lang ] ) ? wp_kses_post( $data[ $lang ] ) : '';
		}
		// Preserve extra language keys already in the payload so that
		// translations are not silently lost when a language is deselected.
		if ( is_array( $data ) ) {
			foreach ( $data as $lang => $value ) {
				if ( ! isset( $description[ $lang ] ) && is_string( $value ) ) {
					$description[ $lang ] = wp_kses_post( $value );
				}
			}
		}
		$this->set_object_data( 'description', $description );
	}

	/**
	 * Normalize multilingual payloads coming from DB, REST, or direct callers.
	 *
	 * @param mixed $data Raw multilingual value.
	 * @return array
	 */
	protected function normalize_multilingual_data( $data ) {
		if ( is_array( $data ) ) {
			return $data;
		}
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
			return is_array( $data ) ? $data : array();
		}
		if ( is_string( $data ) && '' !== $data ) {
			// Try JSON decode first — DB may store multilingual data as JSON string.
			$decoded = json_decode( $data, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
			return array(
				faz_default_language() => $data,
			);
		}
		return array();
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
	 * Subclasses may accept a third optional $source argument (Cookie does), which
	 * PHP permits: a child adding OPTIONAL parameters stays compatible, and the
	 * extra argument is harmlessly ignored by the ones that do not. Declaring it
	 * here instead would break every existing two-parameter override.
	 *
	 * @param string $lang Language code.
	 * @param string $key Specific key if any.
	 * @return mixed
	 */
	public function get_translations( $lang = '', $key = '' ) {
		return array();
	}

	/**
	 * Translate one already-populated multilingual value when the model knows
	 * how to distinguish bundled stock copy from administrator-authored copy.
	 *
	 * The base model deliberately stands down: calling get_translations() for
	 * every non-empty value would overwrite administrator text. Cookies and
	 * categories override this hook only when their catalogue can establish
	 * that the source is stock wording.
	 *
	 * @param string $lang   Language code.
	 * @param string $key    Field name.
	 * @param string $source Stored value.
	 * @return string Empty means preserve the stored value.
	 */
	protected function translate_stored_value( $lang, $key, $source ) {
		return '';
	}
}
