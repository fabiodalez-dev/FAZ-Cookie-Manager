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
	 * Cached result of get_meta() to avoid repeated json_decode calls.
	 *
	 * @var array|null
	 */
	private $decoded_meta = null;

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
		$this->decoded_meta = null;
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
	 * Note: Script fields (opt_in_script, opt_out_script) are intentionally
	 * excluded here because they are admin-only and only valid in the REST
	 * 'edit' context. Use get_script_data() to retrieve them, and merge the
	 * result in REST callers that should expose the scripts.
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
			'transfer'      => $this->get_transfer(),
			'date_created'  => $this->get_date_created(),
			'date_modified' => $this->get_date_modified(),
		);
	}

	/**
	 * Get the admin-only script fields for this cookie.
	 *
	 * These fields are kept separate from get_prepared_data() so that callers
	 * which do not run inside the REST 'edit' context (and therefore must not
	 * leak raw JS) do not receive them by default.
	 *
	 * @return array
	 */
	public function get_script_data() {
		return array(
			'opt_in_script'  => $this->get_opt_in_script(),
			'opt_out_script' => $this->get_opt_out_script(),
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
			$contents[ $lang ] = is_string( $content ) ? stripslashes( wp_kses_post( $content ) ) : '';
		}
		// Preserve extra language keys beyond faz_selected_languages().
		foreach ( $data as $lang => $content ) {
			if ( ! isset( $contents[ $lang ] ) && is_string( $content ) ) {
				$contents[ $lang ] = stripslashes( wp_kses_post( $content ) );
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
	 * Three key classes are handled distinctly on read:
	 *   - Script keys (opt_in_script, opt_out_script) are preserved as-is
	 *     because sanitize_textarea_field() strips HTML tags, which would
	 *     corrupt JS code. These keys are admin-only and are JSON-encoded
	 *     before reaching the browser.
	 *   - Structured keys (transfer) hold an associative array, not a scalar.
	 *     Running sanitize_textarea_field() over an array returns '' (PHP casts
	 *     the array to an empty string), which would silently blank the whole
	 *     third-country-transfer sub-object on every read/persist cycle. They
	 *     are routed to a dedicated structural sanitiser instead.
	 *   - Everything else is a scalar and is sanitised with
	 *     sanitize_textarea_field() as before.
	 *
	 * @return array
	 */
	public function get_meta() {
		if ( null !== $this->decoded_meta ) {
			return $this->decoded_meta;
		}
		$meta            = array();
		$raw             = $this->get_object_data( 'meta' );
		$data            = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
		$script_keys     = array( 'opt_in_script', 'opt_out_script' );
		$structured_keys = array( 'transfer' );
		if ( ! is_array( $data ) ) {
			$this->decoded_meta = $meta;
			return $meta;
		}
		foreach ( $data as $key => $item ) {
			if ( in_array( $key, $script_keys, true ) ) {
				$meta[ $key ] = (string) $item;
			} elseif ( in_array( $key, $structured_keys, true ) ) {
				$meta[ $key ] = $this->sanitize_transfer_meta( $item );
			} else {
				$meta[ $key ] = sanitize_textarea_field( $item );
			}
		}
		$this->decoded_meta = $meta;
		return $meta;
	}

	/**
	 * Return the opt-in script for this cookie (raw JS, admin-only).
	 *
	 * @return string
	 */
	public function get_opt_in_script() {
		$meta = $this->get_meta();
		return isset( $meta['opt_in_script'] ) ? (string) $meta['opt_in_script'] : '';
	}

	/**
	 * Return the opt-out script for this cookie (raw JS, admin-only).
	 *
	 * @return string
	 */
	public function get_opt_out_script() {
		$meta = $this->get_meta();
		return isset( $meta['opt_out_script'] ) ? (string) $meta['opt_out_script'] : '';
	}

	/**
	 * Set the opt-in script, merging into the existing meta JSON.
	 *
	 * Routes through set_meta() so the
	 * sanitize_meta_for_current_user capability gate (unfiltered_html
	 * required) applies on this write path too — set_meta is documented as
	 * the single source of truth for every wp_faz_cookies.meta write, and
	 * this setter must honour that invariant rather than reach for
	 * set_object_data directly.
	 *
	 * @param string $script JavaScript to execute when this cookie's category is accepted.
	 * @return void
	 */
	public function set_opt_in_script( $script ) {
		$meta                   = $this->get_meta();
		$meta['opt_in_script']  = (string) $script;
		$this->set_meta( $meta );
	}

	/**
	 * Set the opt-out script, merging into the existing meta JSON.
	 *
	 * Routes through set_meta() — same rationale as set_opt_in_script
	 * above.
	 *
	 * @param string $script JavaScript to execute when this cookie's category is rejected or revoked.
	 * @return void
	 */
	public function set_opt_out_script( $script ) {
		$meta                    = $this->get_meta();
		$meta['opt_out_script']  = (string) $script;
		$this->set_meta( $meta );
	}

	/**
	 * The disabled-by-default third-country transfer sub-object.
	 *
	 * A legacy cookie row whose meta JSON has no `transfer` key resolves to
	 * this shape, so every downstream surface treats it as "no disclosure" and
	 * renders byte-identically to a pre-feature install.
	 *
	 * @return array{enabled:bool,countries:array,safeguard:array}
	 */
	private function default_transfer() {
		return array(
			'enabled'   => false,
			'countries' => array(),
			'safeguard' => array(),
		);
	}

	/**
	 * Return the third-country (Schrems II) transfer disclosure for this cookie.
	 *
	 * Reads meta['transfer'] through the capability-aware get_meta() pipeline
	 * (which routes the key through sanitize_transfer_meta() so its array value
	 * is never corrupted). Any legacy / malformed value degrades to the disabled
	 * default — never a fatal.
	 *
	 * Shape:
	 *   [
	 *     'enabled'   => bool,                 // the opt-in gate; default false
	 *     'countries' => [ <lang> => string ], // e.g. ['en' => 'United States']
	 *     'safeguard' => [ <lang> => string ], // e.g. ['en' => 'EU-US DPF']
	 *   ]
	 *
	 * @return array
	 */
	public function get_transfer() {
		$meta = $this->get_meta();
		return isset( $meta['transfer'] ) && is_array( $meta['transfer'] )
			? $meta['transfer']
			: $this->default_transfer();
	}

	/**
	 * Set the third-country transfer disclosure, merging into the existing meta.
	 *
	 * Routes through set_meta() — same single-source-of-truth invariant as
	 * set_opt_in_script()/set_opt_out_script(). The value is structurally
	 * cleaned by sanitize_transfer_meta() before it is stored.
	 *
	 * Reachable from the REST single-item update, the bulk-update endpoint and
	 * settings import via the generic set_{key}() dispatch, because `transfer`
	 * is a (non-readonly) schema property.
	 *
	 * @param array|string $data Transfer sub-object (or JSON-encoded string).
	 * @return void
	 */
	public function set_transfer( $data ) {
		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );
			$data    = is_array( $decoded ) ? $decoded : array();
		}
		$meta             = $this->get_meta();
		$meta['transfer'] = $this->sanitize_transfer_meta( $data );
		$this->set_meta( $meta );
	}

	/**
	 * Structurally sanitise the transfer sub-object.
	 *
	 * Idempotent: safe to run both on write (raw REST input) and on read (an
	 * already-normalised array coming back through get_meta()). Coerces:
	 *   - enabled   → strict bool (accepts "true"/"1"/1/true; everything else false)
	 *   - countries → multilingual map of plain text (sanitize_text_field)
	 *   - safeguard → multilingual map of limited HTML (wp_kses, faz_allowed_html)
	 * A non-array input yields the disabled default so corrupt legacy JSON can
	 * never fatal or leak.
	 *
	 * Safeguard passes through wp_kses (not raw) precisely because it is NOT a
	 * script field: a low-privilege editor without unfiltered_html can still save
	 * a cookie, so its safeguard text must be markup-filtered here.
	 *
	 * @param mixed $data Raw transfer value.
	 * @return array{enabled:bool,countries:array,safeguard:array}
	 */
	private function sanitize_transfer_meta( $data ) {
		if ( ! is_array( $data ) ) {
			return $this->default_transfer();
		}

		$enabled = false;
		if ( isset( $data['enabled'] ) ) {
			$raw     = is_string( $data['enabled'] ) ? strtolower( trim( $data['enabled'] ) ) : $data['enabled'];
			$enabled = ! in_array( $raw, array( false, 0, '0', '', 'false', 'no', 'off' ), true );
		}

		$countries = $this->sanitize_transfer_lang_map(
			isset( $data['countries'] ) ? $data['countries'] : array(),
			'sanitize_text_field'
		);
		$safeguard = $this->sanitize_transfer_lang_map(
			isset( $data['safeguard'] ) ? $data['safeguard'] : array(),
			array( $this, 'kses_safeguard' )
		);

		return array(
			'enabled'   => (bool) $enabled,
			'countries' => $countries,
			'safeguard' => $safeguard,
		);
	}

	/**
	 * Normalise a multilingual { <lang> => string } map, running each value
	 * through the given per-value sanitiser. Mirrors the shape/fallback
	 * contract of the existing description/duration multilingual fields.
	 *
	 * A bare string is wrapped under the default language so a single-language
	 * admin payload (or a legacy scalar) still round-trips.
	 *
	 * @param mixed    $map       Raw value (array keyed by language, or a scalar).
	 * @param callable $sanitiser Per-value sanitiser.
	 * @return array
	 */
	private function sanitize_transfer_lang_map( $map, $sanitiser ) {
		$out = array();
		if ( is_array( $map ) ) {
			foreach ( $map as $lang => $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}
				$lang_key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $lang );
				if ( '' === $lang_key ) {
					continue;
				}
				$out[ $lang_key ] = call_user_func( $sanitiser, $value );
			}
		} elseif ( is_string( $map ) && '' !== trim( $map ) ) {
			$out[ faz_default_language() ] = call_user_func( $sanitiser, $map );
		}
		return $out;
	}

	/**
	 * wp_kses() a safeguard string against the plugin's allowed-HTML set.
	 *
	 * Wrapped in a named method so it can be passed as a callable to
	 * sanitize_transfer_lang_map() while still supplying the second
	 * faz_allowed_html() argument.
	 *
	 * @param string $value Raw safeguard text.
	 * @return string
	 */
	private function kses_safeguard( $value ) {
		return wp_kses( (string) $value, faz_allowed_html() );
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
	 * Defence-in-depth: routes through the capability-aware sanitiser
	 * (\FazCookie\Admin\Modules\Cookies\Api\Cookies_API::sanitize_meta_for_current_user)
	 * so any internal write path — including REST handlers, settings import,
	 * migrations — cannot silently smuggle raw JS into opt_in_script /
	 * opt_out_script for callers below the unfiltered_html capability gate.
	 *
	 * Skips when running outside a WordPress request context (e.g. unit tests
	 * before WP bootstrap) where capability functions are unavailable.
	 *
	 * @param array|string $data Meta data array (or JSON-encoded string).
	 * @return void
	 */
	public function set_meta( $data ) {
		if (
			is_callable( array( '\\FazCookie\\Admin\\Modules\\Cookies\\Api\\Cookies_API', 'sanitize_meta_for_current_user' ) )
			&& function_exists( 'current_user_can' )
		) {
			$data = \FazCookie\Admin\Modules\Cookies\Api\Cookies_API::sanitize_meta_for_current_user( $data );
		}
		$this->set_object_data( 'meta', $data );
		$this->decoded_meta = null;
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
