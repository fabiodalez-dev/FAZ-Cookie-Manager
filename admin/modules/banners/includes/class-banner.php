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
		'name'             => '',
		'slug'             => '',
		'status'           => false,
		'settings'         => '',
		'default'          => false,
		'contents'         => array(),
		'target_countries' => array(),
		'priority'         => 0,
		'date_created'     => '',
		'date_modified'    => '',
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
					'name'             => $data->name,
					'slug'             => $data->slug,
					'status'           => $data->status,
					'settings'         => $data->settings,
					'contents'         => $data->contents,
					'default'          => $data->banner_default,
					'target_countries' => isset( $data->target_countries ) ? $data->target_countries : array(),
					'priority'         => isset( $data->priority ) ? (int) $data->priority : 0,
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
			$default_type       = self::get_default_config_type( $data );
			$data               = self::sanitize_settings( array( $this, 'sanitize_option' ), $data, $this->controller->get_default_configs( $default_type ) );
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
			$data      = $this->normalize_multilingual_data( $data );
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
	 * Set the list of country codes this banner targets.
	 *
	 * Stored as a normalised array of ISO-3166 alpha-2 codes. An empty array
	 * means "match every visitor" (the pre-1.13.18 default behaviour).
	 *
	 * @since 1.14.0
	 * @param array|string $countries Array of country codes, or a JSON string.
	 * @return void
	 */
	public function set_target_countries( $countries ) {
		$key = 'target_countries';
		if ( ! array_key_exists( $key, $this->data ) ) {
			return;
		}
		if ( is_string( $countries ) ) {
			$decoded   = json_decode( $countries, true );
			$countries = is_array( $decoded ) ? $decoded : array();
		}
		$countries = is_array( $countries ) ? $countries : array();
		$normalised = array();
		foreach ( $countries as $code ) {
			if ( ! is_string( $code ) ) {
				continue;
			}
			$code = strtoupper( trim( $code ) );
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $code ) && ! in_array( $code, $normalised, true ) ) {
				$normalised[] = $code;
			}
		}
		sort( $normalised );
		$this->data[ $key ] = $normalised;
	}

	/**
	 * Set the priority used to break ties when multiple banners target the
	 * same country. Higher wins.
	 *
	 * @since 1.14.0
	 * @param int $priority Non-negative integer; negative values are clamped to 0.
	 * @return void
	 */
	public function set_priority( $priority = 0 ) {
		$key = 'priority';
		if ( ! array_key_exists( $key, $this->data ) ) {
			return;
		}
		$priority = (int) $priority;
		$this->data[ $key ] = $priority < 0 ? 0 : $priority;
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
				$default_type = self::get_default_config_type( $settings );
				$settings     = self::sanitize_settings( array( $this, 'sanitize_option' ), $settings, $this->controller->get_default_configs( $default_type ) );
			}
		}
		return $settings;
	}

	/**
	 * Apply non-persistent runtime fixes required for a working opt-out UI.
	 *
	 * Classic (including the legacy banner+pushdown combination) has no
	 * opt-out popup. A CCPA banner, or a GDPR+CCPA banner with Do Not Sell
	 * enabled, must therefore render with a popup-capable layout. This changes
	 * only the in-memory Banner object used for frontend output; the editor
	 * remains responsible for migrating and saving the stored configuration.
	 *
	 * @return bool Whether the in-memory settings changed.
	 */
	public function apply_runtime_layout_compatibility() {
		$properties = $this->get_settings();
		if ( ! is_array( $properties ) ) {
			return false;
		}

		$settings = isset( $properties['settings'] ) && is_array( $properties['settings'] )
			? $properties['settings']
			: array();
		$config   = isset( $properties['config'] ) && is_array( $properties['config'] )
			? $properties['config']
			: array();

		$law        = isset( $settings['applicableLaw'] ) ? sanitize_key( $settings['applicableLaw'] ) : 'gdpr';
		$type       = isset( $settings['type'] ) ? sanitize_key( $settings['type'] ) : 'box';
		$ptype      = isset( $settings['preferenceCenterType'] ) ? sanitize_key( $settings['preferenceCenterType'] ) : 'popup';
		// The nested buttons.elements.donotSell branch is the canonical
		// Do-Not-Sell flag that survives sanitize_settings.
		$dns_status = isset( $config['notice']['elements']['buttons']['elements']['donotSell']['status'] )
			? (bool) $config['notice']['elements']['buttons']['elements']['donotSell']['status']
			: false;

		// Back-fill from the legacy direct key. Very old "Both" (GDPR + US) banners
		// stored Do-Not-Sell only at config.notice.elements.donotSell.status, which
		// sanitize_settings drops (it is absent from the default config). Read it
		// from the RAW stored settings so a legacy US opt-out is not silently lost
		// when such a banner renders — otherwise the button is never enabled and
		// the banner degrades to pure GDPR.
		if ( ! $dns_status && isset( $this->data['settings'] ) ) {
			$raw = is_string( $this->data['settings'] )
				? json_decode( $this->data['settings'], true )
				: $this->data['settings'];
			if ( is_array( $raw ) && ! empty( $raw['config']['notice']['elements']['donotSell']['status'] ) ) {
				$dns_status = true;
			}
		}
		$changed = false;

		// CCPA and "Both" (gdpr + Do-Not-Sell on) require the first-party opt-out
		// entry point; the block below enables the canonical nested button branch
		// when missing, covering both cases (a separate ccpa-only enable here
		// would be redundant — its write can never win over this one).
		$shows_do_not_sell = 'ccpa' === $law || $dns_status;
		if ( $shows_do_not_sell ) {
			if ( empty( $properties['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] ) ) {
				if ( ! isset( $properties['config']['notice']['elements']['buttons']['elements']['donotSell'] )
					|| ! is_array( $properties['config']['notice']['elements']['buttons']['elements']['donotSell'] ) ) {
					$properties['config']['notice']['elements']['buttons']['elements']['donotSell'] = array();
				}
				$properties['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] = true;
				$changed = true;
			}
		}
		if ( $shows_do_not_sell && empty( $config['optoutPopup']['status'] ) ) {
			if ( ! isset( $properties['config']['optoutPopup'] ) || ! is_array( $properties['config']['optoutPopup'] ) ) {
				$properties['config']['optoutPopup'] = array();
			}
			$properties['config']['optoutPopup']['status'] = true;
			$changed = true;
		}

		if ( $shows_do_not_sell && 'classic' === $type ) {
			$properties['settings']['type']                 = 'box';
			$properties['settings']['preferenceCenterType'] = 'popup';
			$position = isset( $settings['position'] ) ? sanitize_key( $settings['position'] ) : '';
			if ( ! in_array( $position, array( 'bottom-left', 'bottom-right' ), true ) ) {
				$properties['settings']['position'] = 'bottom-left';
			}
			if ( isset( $properties['config']['categoryPreview'] ) && is_array( $properties['config']['categoryPreview'] ) ) {
				$properties['config']['categoryPreview']['status'] = false;
			}
			$changed = true;
		} elseif ( $shows_do_not_sell && 'banner' === $type && 'pushdown' === $ptype ) {
			$properties['settings']['preferenceCenterType'] = 'popup';
			if ( isset( $properties['config']['categoryPreview'] ) && is_array( $properties['config']['categoryPreview'] ) ) {
				$properties['config']['categoryPreview']['status'] = false;
			}
			$changed = true;
		}

		if ( $changed ) {
			$this->data['settings'] = $properties;
		}

		return $changed;
	}

	/**
	 * Repair untouched notice copy that belongs to the other law.
	 *
	 * This is deliberately non-persistent and only changes an empty description
	 * or one that still exactly matches the bundled default for the other law.
	 * Customised copy is never changed.
	 *
	 * @return bool Whether the in-memory contents changed.
	 */
	public function apply_runtime_law_content_compatibility() {
		if ( ! array_key_exists( 'contents', $this->data ) ) {
			return false;
		}

		$properties = $this->get_settings();
		$settings   = isset( $properties['settings'] ) && is_array( $properties['settings'] )
			? $properties['settings']
			: array();
		$law        = isset( $settings['applicableLaw'] ) ? sanitize_key( $settings['applicableLaw'] ) : 'gdpr';
		// "Both" is stored as applicableLaw='gdpr' (+ Do-Not-Sell on) and, like
		// pure GDPR, uses the neutral GDPR default copy — see fazLawToDescKey()
		// in banner.js for the mixed-audience rationale.
		$new_key    = 'ccpa' === $law ? 'ccpa' : 'gdpr';
		$old_key    = 'ccpa' === $new_key ? 'gdpr' : 'ccpa';
		$contents   = $this->normalize_multilingual_data( $this->data['contents'] );
		$changed    = false;

		foreach ( $contents as $lang => &$content ) {
			// A language entry may still be a JSON string (not yet decoded by
			// normalize_multilingual_data); decode it so its description is read
			// correctly instead of being treated as empty and wrongly repaired.
			if ( is_string( $content ) ) {
				$decoded = json_decode( $content, true );
				if ( is_array( $decoded ) ) {
					$content = $decoded;
				}
			}
			if ( ! is_array( $content ) ) {
				continue;
			}
			// Skip languages whose stored content is effectively blank. Such a
			// language is rendered entirely from its bundled {lang}.json
			// translation by get_contents()'s array_empty_assoc() fallback —
			// writing only the law-default description here would leave a
			// partial entry (description set, title/buttons still empty) that
			// makes the language look "non-blank", defeating that whole-language
			// fallback and blanking the title/labels on non-default locales.
			if ( empty( self::array_empty_assoc( $content ) ) ) {
				continue;
			}
			$defaults    = self::get_law_notice_descriptions( $lang );
			$current     = isset( $content['notice']['elements']['description'] )
				? $content['notice']['elements']['description']
				: '';
			$current     = self::normalize_notice_description( $current );
			$old_default = self::normalize_notice_description( $defaults[ $old_key ] );
			$new_default = isset( $defaults[ $new_key ] ) ? $defaults[ $new_key ] : '';

			if ( ( '' === $current || $current === $old_default )
				&& '' !== $new_default
				&& self::normalize_notice_description( $new_default ) !== $current ) {
				if ( ! isset( $content['notice'] ) || ! is_array( $content['notice'] ) ) {
					$content['notice'] = array();
				}
				if ( ! isset( $content['notice']['elements'] ) || ! is_array( $content['notice']['elements'] ) ) {
					$content['notice']['elements'] = array();
				}
				$content['notice']['elements']['description'] = $new_default;
				$changed = true;
			}
		}
		unset( $content );

		if ( $changed ) {
			$this->data['contents'] = $contents;
		}

		return $changed;
	}

	/**
	 * Normalize notice HTML for conservative default-copy comparisons.
	 *
	 * @param mixed $value Notice description.
	 * @return string
	 */
	private static function normalize_notice_description( $value ) {
		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	/**
	 * Pick the matching default config tree for sanitization.
	 *
	 * Partial admin payloads must be backfilled from the correct law-specific
	 * defaults, otherwise CCPA banners can silently inherit GDPR-only flags.
	 *
	 * @param array $settings Banner settings payload.
	 * @return string
	 */
	private static function get_default_config_type( $settings ) {
		$law = isset( $settings['settings']['applicableLaw'] ) ? sanitize_key( $settings['settings']['applicableLaw'] ) : 'gdpr';
		return 'ccpa' === $law ? 'ccpa' : 'gdpr';
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
	 * Get the list of ISO-3166 alpha-2 country codes this banner targets.
	 *
	 * An empty array means "match every visitor". The Controller's
	 * get_active_banner_for_country() consumes this to pick the right banner
	 * for the visitor's detected country.
	 *
	 * @since 1.14.0
	 * @return array
	 */
	public function get_target_countries() {
		$raw = $this->get_object_data( 'target_countries' );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Get the tie-break priority for this banner. Higher wins.
	 *
	 * @since 1.14.0
	 * @return int
	 */
	public function get_priority() {
		return (int) $this->get_object_data( 'priority' );
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
			$data = $this->normalize_multilingual_data( $this->data[ $key ] );
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
	 * Notice description for a SINGLE language, resolved cheaply.
	 *
	 * Unlike get_contents() this does not loop over and sanitize every selected
	 * language — it resolves only the requested language (with the same
	 * empty→get_translations() fallback) and returns its raw notice description.
	 * Used by the template cache signature so a cache-hit render does not
	 * re-sanitize the whole multilingual content tree on every page load. The
	 * raw value is a valid fingerprint: sanitize_contents() is deterministic, so
	 * the raw description changes iff the rendered one does.
	 *
	 * @param string $lang Language code; defaults to the current language.
	 * @return string
	 */
	public function get_notice_description( $lang = '' ) {
		if ( ! array_key_exists( 'contents', $this->data ) ) {
			return '';
		}
		$lang    = '' !== $lang ? $lang : $this->get_language();
		$data    = $this->normalize_multilingual_data( $this->data['contents'] );
		$content = isset( $data[ $lang ] ) ? $data[ $lang ] : array();
		if ( empty( self::array_empty_assoc( $content ) ) ) {
			$content = $this->get_translations( $lang );
		}
		if ( is_string( $content ) ) {
			$content = json_decode( $content, true );
		}
		if ( ! is_array( $content ) ) {
			return '';
		}
		return isset( $content['notice']['elements']['description'] )
			? (string) $content['notice']['elements']['description']
			: '';
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
			case 'color':
			case 'border-color':
			case 'background-color':
				// Admin-set colour values are later emitted as CSS custom-property
				// values (class-template.php). esc_attr() at output does NOT strip
				// CSS metacharacters ({ } ;), so sanitise to a strict colour here
				// (hex / rgba / transparent) — a value like "red;}@import url(...)"
				// would otherwise break out of the custom-property declaration into
				// free-form CSS on every page.
				$value = faz_sanitize_color( $value );
				break;
			case 'customCSS':
				// customCSS field removed from the admin UI in 1.13.11 for
				// wp.org compliance ("plugins must not allow arbitrary code
				// insertion"). The case is kept so legacy DB rows survive
				// sanitize_meta_field() unchanged, but the value is never
				// rendered (see frontend/class-frontend.php and
				// admin/modules/banners/api/class-api.php for the inert
				// render path).
				$value = is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : '';
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
	 * Default notice description text for each law, in a given language.
	 *
	 * Used by the banner editor so that switching the law dropdown can re-load
	 * the law-appropriate copy. The CCPA description names the "Do Not Sell"
	 * link and the consent-preferences icon; the GDPR description does not.
	 * Without this, changing the law updates donotSell.status but leaves the
	 * old copy in place, so a CCPA description can survive on a GDPR banner and
	 * promise a link the layout no longer renders.
	 *
	 * @param string $lang Language code (falls back to the bundled en.json).
	 * @return array { gdpr: string, ccpa: string }
	 */
	public static function get_law_notice_descriptions( $lang = 'en' ) {
		$safe_lang = sanitize_file_name( (string) $lang );
		$cache_key = 'faz_law_notice_desc_' . ( '' !== $safe_lang ? $safe_lang : 'en' );
		// Object-cached: this is called per language on every frontend render
		// (the runtime law-content compatibility pass) and on the banner-editor
		// page load, so avoid re-reading the JSON files each time.
		$cached = wp_cache_get( $cache_key, 'faz_banner_contents' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$dir  = dirname( __FILE__ ) . '/contents/';
		$data = array();

		// Prefer a downloaded translation, but ONLY when the language is
		// registered as translated — the same gate get_translations() uses, so
		// the untouched-default baseline is read from the same source the
		// frontend actually renders (an orphaned file on disk is ignored).
		if ( '' !== $safe_lang
			&& \FazCookie\Admin\Modules\Languages\Includes\Controller::get_instance()->is_faz_translated( $safe_lang ) ) {
			$upload_dir      = wp_upload_dir();
			$translated_file = trailingslashit( $upload_dir['basedir'] ) . 'fazcookie/languages/banners/' . $safe_lang . '.json';
			if ( file_exists( $translated_file ) ) {
				$translated = faz_read_json_file( $translated_file );
				if ( isset( $translated['banner_data'] ) && is_array( $translated['banner_data'] ) ) {
					$data = $translated['banner_data'];
				}
			}
		}
		if ( empty( $data ) ) {
			$file = ( '' !== $safe_lang && file_exists( $dir . $safe_lang . '.json' ) ) ? $dir . $safe_lang . '.json' : $dir . 'en.json';
			$data = faz_read_json_file( $file );
		}
		$out = array(
			'gdpr' => '',
			'ccpa' => '',
		);
		foreach ( array( 'gdpr', 'ccpa' ) as $law ) {
			if ( isset( $data[ $law ]['notice']['elements']['description'] ) && is_string( $data[ $law ]['notice']['elements']['description'] ) ) {
				$out[ $law ] = $data[ $law ]['notice']['elements']['description'];
			}
		}
		wp_cache_set( $cache_key, $out, 'faz_banner_contents', 12 * HOUR_IN_SECONDS );
		return $out;
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
				$safe_lang = sanitize_file_name( $lang );
				$translation = faz_read_json_file( $upload_dir['basedir'] . '/fazcookie/languages/banners/' . $safe_lang . '.json' );
				if($translation) {
					$contents = $translation['banner_data'];
				}
				if(!$contents) {
					$contents = faz_read_json_file( dirname( __FILE__ ) . '/contents/' . $safe_lang . '.json' );
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
