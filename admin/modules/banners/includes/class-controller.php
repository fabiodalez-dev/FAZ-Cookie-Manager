<?php
/**
 * Class Controller file.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Admin\Modules\Banners\Includes
 */

namespace FazCookie\Admin\Modules\Banners\Includes;

use FazCookie\Includes\Base_Controller;

use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Controller
 * @version     3.0.0
 * @package     CookieYe
 */
class Controller extends Base_Controller {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;
	/**
	 * Cache group
	 *
	 * @var string
	 */
	protected $cache_group = 'banners';

	/**
	 * Table versioning option name.
	 *
	 * @var string
	 */
	protected $table_option = 'banners';

	/**
	 * Unique item identifier.
	 *
	 * @var string
	 */
	protected $id = 'banner_id';

	/**
	 * Return the current instance of the class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Return a list of Cookies tables
	 *
	 * @return array Cookies tables.
	 */
	protected function get_tables() {
		global $wpdb;
		return array(
			"{$wpdb->prefix}faz_banners",
		);
	}

	/**
	 * Get table schema
	 *
	 * @return string
	 */
	protected function get_schema() {
		global $wpdb;

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$tables = "
		CREATE TABLE {$wpdb->prefix}faz_banners (
			banner_id bigint(20) NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			slug varchar(190) NOT NULL DEFAULT '',
			status int(11) NOT NULL DEFAULT 0,
			settings longtext NOT NULL,
			banner_default int(11) NOT NULL DEFAULT 0,
			contents longtext NOT NULL,
			target_countries longtext NOT NULL,
			priority int(11) NOT NULL DEFAULT 0,
			date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (banner_id),
			KEY slug (slug),
			KEY status (status),
			KEY priority (priority)
	) $collate;
	";
		return $tables;
	}

	/**
	 * Get a list of banners from localhost.
	 *
	 * @param array $args Array of arguments.
	 * @return array
	 */
	public function get_item_from_db( $args = array() ) {

		global $wpdb;
		$items = array();
		if ( false === $this->data_exist() ) {
			return $items;
		}

		if ( isset( $args['id'] ) && '' !== $args['id'] ) {
			$results = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}faz_banners` WHERE `banner_id` = %d", absint( $args['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			// ORDER BY banner_id ASC so the multi-banner picker
			// (get_active_banner_for_country) sees a deterministic row
			// order. Without it, the status_default fallback was the
			// first banner_default=1 row in MySQL's unspecified order —
			// non-deterministic across replicas and after row reorders.
			$results = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}faz_banners` ORDER BY `banner_id` ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( isset( $results ) && ! empty( $results ) ) {
			if ( true === is_array( $results ) ) {
				foreach ( $results as $data ) {
					$item = $this->prepare_item( $data );
					if ( ! empty( $item ) ) {
						$items[ $item->{$this->id} ] = $item;
					}
				}
			} else {
				$items = $this->prepare_item( $results );
			}
		}
		return $items;
	}

	/**
	 * Create a new banner.
	 *
	 * @param object $banner Banner object.
	 * @return void
	 */
	public function create_item( $banner ) {
		global $wpdb;
		$date_created = current_time( 'mysql' );
		$banner->set_date_created( $date_created );
		$banner->set_date_modified( $date_created );

		$created = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_banners',
			array(
				'name'             => $banner->get_name(),
				'slug'             => $banner->get_slug(),
				'status'           => ( true === $banner->get_status() ? 1 : 0 ),
				'settings'         => wp_json_encode( $banner->get_settings() ),
				'banner_default'   => ( true === $banner->get_default() ? 1 : 0 ),
				'contents'         => wp_json_encode( $banner->get_contents() ),
				'target_countries' => wp_json_encode( $banner->get_target_countries() ),
				'priority'         => $banner->get_priority(),
				'date_created'     => $banner->get_date_created(),
				'date_modified'    => $banner->get_date_modified(),
			),
			array(
				'%s',
				'%s',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
			)
		);
		if ( false === $created || ! $wpdb->insert_id ) {
			return;
		}
		$id = $wpdb->insert_id;
		// Enforce single-default invariant on create too (mirrors update_item).
		// The insert above has already written banner_default=1 for the new
		// row; clear the flag on every other row so the just-inserted row is
		// the sole default after this call returns.
		if ( true === $banner->get_default() ) {
			$this->clear_default_on_others( $id );
		}
		$banner->set_id( $id );
		$banner->set_slug( $banner->get_name() );
		$slug = $banner->get_slug() . '-' . $id; // Append ID to the slug of the each banner.
		$banner->set_slug( $slug );
		$banner->save();
		$banner->set_id( $wpdb->insert_id );
		$this->delete_cache();
		do_action( 'faz_after_update_banner' );
	}

	/**
	 * Update an existing banner locally.
	 *
	 * @param object $banner Banner object.
	 * @return void
	 */
	public function update_item( $banner ) {
		global $wpdb;
		$data = array(
			'name'             => $banner->get_name(),
			'slug'             => $banner->get_slug(),
			'status'           => ( true === $banner->get_status() ? 1 : 0 ),
			'settings'         => wp_json_encode( $banner->get_settings() ),
			'banner_default'   => ( true === $banner->get_default() ? 1 : 0 ),
			'contents'         => wp_json_encode( $banner->get_contents() ),
			'target_countries' => wp_json_encode( $banner->get_target_countries() ),
			'priority'         => $banner->get_priority(),
		);
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prefix . 'faz_banners',
			$data,
			array( 'banner_id' => $banner->get_id() ),
			array(
				'%s',
				'%s',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%d',
			)
		);
		if ( false === $updated ) {
			return;
		}
		// Enforce single-default invariant AFTER the current row's UPDATE
		// succeeded — clearing peer rows first would leave the DB without
		// any banner_default=1 if our own UPDATE then failed.
		if ( true === $banner->get_default() ) {
			$this->clear_default_on_others( $banner->get_id() );
		}
		if ( $updated > 0 ) {
			$this->delete_cache();
		}
		if ( defined( 'FAZ_BULK_REQUEST' ) && FAZ_BULK_REQUEST ) {
			return;
		}
		do_action( 'faz_after_update_banner' );
	}

	/**
	 * Delete a banner locally.
	 *
	 * @param object $id Banner id.
	 * @return boolean
	 */
	public function delete_item( $id ) {
		global $wpdb;
		$status = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_banners',
			array(
				'banner_id' => $id,
			)
		);
		if ( false === $status ) {
			return false;
		}
		if ( $status > 0 ) {
			$this->delete_cache();
		}
		do_action( 'faz_after_update_banner' );
		return $status;
	}

	/**
	 * Prepare banner data to response.
	 *
	 * @param object $item Banner object.
	 * @return object|false
	 */
	public function prepare_item( $item ) {
		if ( false === is_object( $item ) ) {
			return false;
		}
		$data                 = new stdClass();
		$data->banner_id      = isset( $item->banner_id ) ? absint( $item->banner_id ) : 0;
		$data->name           = isset( $item->name ) ? sanitize_text_field( $item->name ) : '';
		$data->slug           = isset( $item->slug ) ? sanitize_text_field( $item->slug ) : '';
		$data->settings       = isset( $item->settings ) ? $this->prepare_json( $item->settings ) : array();
		$data->contents       = isset( $item->contents ) ? $this->prepare_json( $item->contents ) : array();
		$data->banner_default = isset( $item->banner_default ) ? absint( $item->banner_default ) : 0;
		$data->status         = isset( $item->status ) ? absint( $item->status ) : 0;
		// target_countries is stored as JSON in the DB but Banner::set_data()
		// expects an array; decode here so the model layer never sees raw JSON.
		if ( isset( $item->target_countries ) && is_string( $item->target_countries ) ) {
			$decoded = json_decode( $item->target_countries, true );
			$data->target_countries = is_array( $decoded ) ? $decoded : array();
		} else {
			$data->target_countries = isset( $item->target_countries ) && is_array( $item->target_countries ) ? $item->target_countries : array();
		}
		$data->priority = isset( $item->priority ) ? (int) $item->priority : 0;
		if (isset($data->settings['settings']['type']) && ($data->settings['settings']['type'] === "classic")) {
			$data->settings['settings']['preferenceCenterType'] = "pushdown";
		}
		return $data;
	}

	/**
	 * Decode a JSON string if necessary
	 *
	 * @param string $data String data.
	 * @return array
	 */
	public function prepare_json( $data ) {
		return is_string( $data ) ? json_decode( $data, true ) : $data;
	}

	/**
	 * Load default banner
	 *
	 * @return void
	 */
	protected function load_default() {
		$banner = new \FazCookie\Admin\Modules\Banners\Includes\Banner();
		$banner->set_name( 'GDPR' );
		$banner->set_status( true );
		$banner->set_default( true );
		$banner->save();
		$banner = new \FazCookie\Admin\Modules\Banners\Includes\Banner();
		$banner->set_name( 'CCPA' );
		$banner->set_settings( self::get_default_configs( 'ccpa' ) );
		$banner->save();
	}

	/**
	 * Get banner
	 *
	 * @return object|bool
	 */
	public function get_active_banner() {
		return $this->get_active_banner_for_country( '' );
	}

	/**
	 * Get the banner that should be rendered for a visitor in $country.
	 *
	 * Selection order (highest match wins, ties broken by priority desc, then
	 * by lowest banner_id for determinism):
	 *
	 *   1. Active banners (status=1) whose target_countries list contains
	 *      the upper-cased $country code.
	 *   2. If none match, active banners with an empty target_countries list
	 *      (the "match-all" rows used by single-banner installs).
	 *   3. If none match, the banner flagged banner_default=1 — even if
	 *      status=0 — so a fallback row is always available for visitors
	 *      from countries the admin has not explicitly mapped.
	 *
	 * `$country` is normalised to upper-case A-Z (length 2). Passing an empty
	 * string or a malformed code skips step 1 and goes straight to the
	 * empty-list / banner_default fallback chain — preserving the pre-feature
	 * behaviour of get_active_banner() for callers that have not yet wired
	 * geolocation into the picker.
	 *
	 * @since 1.14.0
	 * @param string $country Visitor's ISO-3166 alpha-2 country code, or ''.
	 * @return Banner|false
	 */
	public function get_active_banner_for_country( $country = '' ) {
		$items        = $this->get_items();
		$current_lang = faz_current_language();
		if ( empty( $items ) || ! is_array( $items ) ) {
			return false;
		}

		$country = is_string( $country ) ? strtoupper( trim( $country ) ) : '';
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = '';
		}

		$status_match    = array(); // status=1 + targets the country
		$status_anyland  = array(); // status=1 + empty target list
		$status_targeted = array(); // status=1 + non-empty targets (fallback)
		$status_default  = null;    // banner_default=1 fallback (any status)

		foreach ( $items as $item ) {
			$banner = new Banner( $item->banner_id );
			$status = (bool) $banner->get_status();
			$targets = $banner->get_target_countries();
			$is_default = (bool) $banner->get_default();

			if ( $status ) {
				if ( '' !== $country && in_array( $country, $targets, true ) ) {
					$status_match[] = $banner;
				} elseif ( empty( $targets ) ) {
					$status_anyland[] = $banner;
				} else {
					// status=1 with non-empty targets but doesn't match $country.
					// Kept as a last-resort fallback so legacy single-banner
					// callers of get_active_banner() (which passes '') still
					// receive a status=1 banner instead of false when no
					// match-all or banner_default row exists. Mirrors the
					// pre-1.14.0 behaviour of "first status=1 wins".
					$status_targeted[] = $banner;
				}
			}

			if ( $is_default && null === $status_default ) {
				$status_default = $banner;
			}
		}

		$winner = null;
		if ( ! empty( $status_match ) ) {
			$winner = self::pick_highest_priority( $status_match );
		} elseif ( ! empty( $status_anyland ) ) {
			$winner = self::pick_highest_priority( $status_anyland );
		} elseif ( null !== $status_default ) {
			$winner = $status_default;
		} elseif ( ! empty( $status_targeted ) ) {
			// Pre-1.14.0 contract preservation: third-party callers of
			// get_active_banner() (which passes country='') must still get
			// a status=1 banner back when one exists, even if its
			// target_countries doesn't match — otherwise an install with a
			// single status=1, country-targeted, non-default banner returns
			// false where pre-1.14.0 it returned that banner.
			$winner = self::pick_highest_priority( $status_targeted );
		}

		if ( null === $winner ) {
			return false;
		}

		$winner->set_language( $current_lang );
		return $winner;
	}

	/**
	 * Pick the Banner with the highest priority from a non-empty list.
	 * Ties broken by the lowest banner_id for deterministic selection.
	 *
	 * @since 1.14.0
	 * @param Banner[] $banners
	 * @return Banner
	 */
	private static function pick_highest_priority( $banners ) {
		usort( $banners, function ( $a, $b ) {
			$pa = (int) $a->get_priority();
			$pb = (int) $b->get_priority();
			if ( $pa !== $pb ) {
				return $pb <=> $pa; // desc
			}
			return ( (int) $a->get_id() ) <=> ( (int) $b->get_id() ); // asc
		} );
		return $banners[0];
	}

	/**
	 * Whether the rendered banner can vary by visitor country.
	 *
	 * Used by frontend cache guards. A country-targeted active banner means the
	 * same URL can legitimately render a different banner for a different
	 * country, so full-page caches must not reuse the response globally.
	 *
	 * @since 1.14.0
	 * @return bool
	 */
	public function has_country_dependent_banners() {
		// Memoize via transient — this method runs on every front-end
		// request once geo-routing is enabled, reading EVERY banner row
		// each time. On busy installs the per-request DB load was a
		// real amplifier (F-SEC-04 in the adamsreview report). 5-minute
		// TTL is short enough that an admin Save-toggling-a-banner
		// experience stays snappy (delete_cache() below invalidates it
		// on writes anyway), and long enough that a static page tier
		// sees this as a near-zero-cost call.
		$cached = get_transient( 'faz_has_country_dependent_banners' );
		if ( false !== $cached ) {
			return (bool) $cached;
		}
		$items = $this->get_items();
		if ( empty( $items ) || ! is_array( $items ) ) {
			set_transient( 'faz_has_country_dependent_banners', 0, 5 * MINUTE_IN_SECONDS );
			return false;
		}
		foreach ( $items as $item ) {
			$banner = new Banner( $item->banner_id );
			if ( ! $banner->get_status() ) {
				continue;
			}
			if ( ! empty( $banner->get_target_countries() ) ) {
				set_transient( 'faz_has_country_dependent_banners', 1, 5 * MINUTE_IN_SECONDS );
				return true;
			}
			// The ruleSet lives under .settings.ruleSet (see Banner::get_law()
			// for the same nesting pattern). ANY entry whose code is not the
			// wildcard "ALL" gates the banner on visitor country and therefore
			// makes the rendered output country-dependent — iterate the whole
			// ruleSet, not just the first entry, otherwise a ruleSet like
			// [{code:ALL}, {code:US}] is silently classified as country-
			// independent and the cache headers never fire.
			$settings = $banner->get_settings();
			$inner    = isset( $settings['settings'] ) && is_array( $settings['settings'] ) ? $settings['settings'] : array();
			$rules    = isset( $inner['ruleSet'] ) && is_array( $inner['ruleSet'] ) ? $inner['ruleSet'] : array();
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$code = isset( $rule['code'] ) ? strtoupper( (string) $rule['code'] ) : 'ALL';
				if ( 'ALL' !== $code ) {
					set_transient( 'faz_has_country_dependent_banners', 1, 5 * MINUTE_IN_SECONDS );
					return true;
				}
			}
		}
		set_transient( 'faz_has_country_dependent_banners', 0, 5 * MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Invalidate the country-dependent transient on top of the normal
	 * group-level cache invalidation. Mirrors the cache_group invalidation
	 * the base class already does; both are needed so a banner save (or
	 * delete) takes effect on the front-end immediately instead of after
	 * the 5-minute transient TTL elapses.
	 *
	 * @return void
	 */
	public function delete_cache() {
		delete_transient( 'faz_has_country_dependent_banners' );
		parent::delete_cache();
	}

	/**
	 * Zero out banner_default on every row except $keep_id.
	 *
	 * Enforces the single-default invariant the admin help text promises
	 * ("Saving this option will clear the flag on every other banner").
	 * Called from create_item / update_item when the banner being saved
	 * has banner_default=1.
	 *
	 * @since 1.14.0
	 * @param int $keep_id Banner id whose banner_default flag must be preserved.
	 * @return void
	 */
	public function clear_default_on_others( $keep_id ) {
		global $wpdb;
		$keep_id = absint( $keep_id );
		if ( $keep_id <= 0 ) {
			return;
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE `{$wpdb->prefix}faz_banners` SET `banner_default` = 0 WHERE `banner_default` = 1 AND `banner_id` <> %d",
				$keep_id
			)
		);
		$this->delete_cache();
	}

	/**
	 * Returns the active banner item from DB.
	 *
	 * @return array|object|false
	 */
	public function get_active_item() {
		global $wpdb;
		if ( false === $this->data_exist() ) {
			return array();
		}
		$item = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT * FROM `{$wpdb->prefix}faz_banners` WHERE `status` = 1;"
		);
		return $this->prepare_item( $item );
	}
	/**
	 * Load template from either a localhost or web app
	 *
	 * @param Banner $object Banner object.
	 * @return object
	 */
	public function get_template( $object ) {
		return new \FazCookie\Admin\Modules\Banners\Includes\Template( $object );
	}

	/**
	 * Check banner status
	 *
	 * @return boolean
	 */
	public function check_status() {
		global $wpdb;
		if ( false === $this->table_exist() ) {
			return false;
		}
		$items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(banner_id) FROM {$wpdb->prefix}faz_banners WHERE status = %d", 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $items > 0 ? true : false;
	}

	/**
	 *  Return the default settings of a banner.
	 *
	 * @param string $type Consent type. Default value "gdpr".
	 * @return array
	 */
	public static function get_default_configs( $type = 'gdpr' ) {
		$settings = wp_cache_get( 'default', 'faz_banner_settings_' . $type );
		if ( ! $settings ) {
			$settings = faz_read_json_file( dirname( __FILE__ ) . '/configs/' . $type . '.json' );
			wp_cache_set( 'default', $settings, 'faz_banner_settings_' . $type, 12 * HOUR_IN_SECONDS );
		}
		return $settings;
	}
}
