<?php
/**
 * Class Cookie_Controller file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Cookies\Includes;

use FazCookie\Includes\Base_Controller;
use FazCookie\Includes\Cache;
use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Cookie_Controller
 * @version     3.0.0
 * @package     FazCookie
 */
class Cookie_Controller extends Base_Controller {

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
	protected $cache_group = 'cookies';

	/**
	 * Table versioning option name.
	 *
	 * @var string
	 */
	protected $table_option = 'cookie';

	/**
	 * Cateogory identifier key.
	 *
	 * @var string
	 */
	protected $id = 'cookie_id';

	/**
	 * API path for cookies.
	 *
	 * @var string
	 */
	protected $path = 'cookies';

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
	 * Clear cookie caches, including the detected-name list used by
	 * per-service consent.
	 *
	 * @return void
	 */
	public function delete_cache() {
		// Honour the bulk-mode suspension the same way the parent does. Without
		// this the parent returns early during a scanner import but the transient
		// below is still deleted once per inserted row, which is most of the cost
		// the suspension exists to avoid. The flush that runs after
		// resume_cache_invalidation() clears it once for the whole batch.
		if ( self::is_cache_invalidation_suspended() ) {
			return;
		}
		parent::delete_cache();
		delete_transient( 'faz_detected_cookie_names' );
		// Admin-declared cookies surfaced as consent services (#360): the list is
		// a pure function of this table plus the category slugs, and this method
		// runs on every cookie AND category write (hooked on both actions).
		$this->forget_declared_service_map();
	}

	/**
	 * Request memo of get_declared_service_map().
	 *
	 * @var array|null
	 */
	private $declared_service_map = null;

	/**
	 * Drop the declared-services map, persistent and in-request.
	 *
	 * @return void
	 */
	public function forget_declared_service_map() {
		$this->declared_service_map = null;
		delete_transient( 'faz_declared_services_v2' );
		// The pre-review shape; a leftover would only expire, never be read.
		delete_transient( 'faz_declared_services_v1' );
	}

	/**
	 * Hand-declared cookies the runtime exposes as consent services (#360).
	 *
	 * The single answer to "which declared row is which service": the frontend
	 * builds window.getFazConsent().services and the per-service toggles from
	 * `services`, the scripts map tags each script with its owner from
	 * `by_cookie`, and the REST response shows the admin the same key. Each of
	 * them used to re-derive it on its own, and they drifted — the dialog showed
	 * a key for rows the runtime never exposed, and a script was gated on a
	 * service its row did not own (#365 review).
	 *
	 * A row is exposed when it is not scanner-discovered, has a name the banner
	 * may declare, sits in a category other than necessary/wordpress-internal,
	 * is not a Known_Providers cookie (that one already has its provider's
	 * toggle), and its key is not taken by a row in another category. Rows
	 * sharing a key in the same category fold into one service.
	 *
	 * Pure function of the inventory and the category slugs, never of the
	 * visitor, so it is safe under Cache Compatibility Mode. One pass over the
	 * cookies and a plain id→slug query for the categories: no Category_Controller
	 * hydration, which builds every category's cookie list just to read a slug.
	 *
	 * @return array{services: array<string,array>, by_cookie: array<int,string>}
	 */
	public function get_declared_service_map() {
		if ( null !== $this->declared_service_map ) {
			return $this->declared_service_map;
		}
		$cached = get_transient( 'faz_declared_services_v2' );
		if ( is_array( $cached ) && isset( $cached['services'], $cached['by_cookie'] ) ) {
			$this->declared_service_map = $cached;
			return $cached;
		}

		$category_slugs = $this->category_slugs_by_id();

		$suppressed = is_callable( array( '\\FazCookie\\Frontend\\Frontend', 'is_declaration_suppressed' ) );
		$services   = array();
		$by_cookie  = array();
		foreach ( $this->get_items() as $cookie_row ) {
			$cookie = new Cookie( $cookie_row );
			if ( $cookie->is_discovered() ) {
				continue;
			}
			$name = $cookie->get_name();
			if ( '' === $name || ( $suppressed && \FazCookie\Frontend\Frontend::is_declaration_suppressed( $name ) ) ) {
				continue;
			}
			$cat_slug = isset( $category_slugs[ (int) $cookie->get_category() ] ) ? $category_slugs[ (int) $cookie->get_category() ] : '';
			if ( '' === $cat_slug || 'necessary' === $cat_slug || 'wordpress-internal' === $cat_slug ) {
				continue;
			}
			if ( Cookie::is_provider_cookie_name( $name ) ) {
				continue;
			}
			$service_id = $cookie->get_service_id();
			if ( '' === $service_id ) {
				continue;
			}
			$clean_name = sanitize_text_field( $name );
			if ( isset( $services[ $service_id ] ) ) {
				if ( $services[ $service_id ]['category'] !== $cat_slug ) {
					// A clash survives only on rows that have not been saved since it
					// arose (an id frozen before this check existed, a category moved
					// by direct SQL): every save re-resolves the key against other
					// categories (Cookie::save()), so saving this row gives it its own.
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator-actionable, once per rebuild, not user-facing.
					error_log( sprintf( '[faz-cookie-manager] declared cookie "%s" (category %s) shares service id "%s" with a cookie in category %s and was not exposed as a service; open it under Cookies and save it to give it its own id.', $clean_name, $cat_slug, $service_id, $services[ $service_id ]['category'] ) );
					continue;
				}
				if ( ! in_array( $clean_name, $services[ $service_id ]['cookies'], true ) ) {
					$services[ $service_id ]['cookies'][] = $clean_name;
				}
			} else {
				$services[ $service_id ] = array(
					'id'       => $service_id,
					'label'    => $clean_name,
					'category' => $cat_slug,
					'cookies'  => array( $clean_name ),
				);
			}
			$by_cookie[ (int) $cookie->get_id() ] = $service_id;
		}

		$this->declared_service_map = array(
			'services'  => $services,
			'by_cookie' => $by_cookie,
		);
		set_transient( 'faz_declared_services_v2', $this->declared_service_map, HOUR_IN_SECONDS );
		return $this->declared_service_map;
	}

	/**
	 * Category id → slug, read with one plain query (no Category_Controller
	 * hydration, which builds every category's cookie list just to read a slug).
	 *
	 * @return array<int,string>
	 */
	private function category_slugs_by_id() {
		global $wpdb;
		$category_slugs = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, table-prefix interpolation only, no user input; callers cache what they build from it.
		$rows = $wpdb->get_results( "SELECT category_id, slug FROM {$wpdb->prefix}faz_cookie_categories" );
		foreach ( (array) $rows as $row ) {
			$category_slugs[ (int) $row->category_id ] = sanitize_key( $row->slug );
		}
		return $category_slugs;
	}

	/**
	 * A service id no declared cookie in another category already holds.
	 *
	 * Called on every save of a declared row. A key already used in the SAME
	 * category is returned unchanged (the rows fold into one service); one used
	 * only elsewhere gets the first free -2, -3… suffix.
	 *
	 * The row the runtime currently exposes under the key, saved without leaving
	 * its category, always keeps it (#365 review). On a clash an older version
	 * left behind (two "Chat" rows in two categories), editing the winner — even
	 * just its description — would otherwise hand `chat`, and every visitor's
	 * svc.chat choice, to the cookie in the other category. The losing row is
	 * the one that moves, when it is saved.
	 *
	 * @param string $service_id  Derived id.
	 * @param int    $category_id Category of the row being saved.
	 * @param int    $cookie_id   Id of the row being saved (0 when new).
	 * @return string
	 */
	public function unique_declared_service_id( $service_id, $category_id, $cookie_id ) {
		if ( (int) $cookie_id > 0 ) {
			// Read before this save lands, so it says who is exposed NOW.
			$map   = $this->get_declared_service_map();
			$slugs = $this->category_slugs_by_id();
			if (
				isset( $map['by_cookie'][ (int) $cookie_id ], $map['services'][ $service_id ], $slugs[ (int) $category_id ] )
				&& $map['by_cookie'][ (int) $cookie_id ] === $service_id
				&& $map['services'][ $service_id ]['category'] === $slugs[ (int) $category_id ]
			) {
				return $service_id;
			}
		}
		$taken = array();
		foreach ( $this->get_items() as $cookie_row ) {
			$other = new Cookie( $cookie_row );
			if ( $other->is_discovered() || (int) $other->get_id() === (int) $cookie_id ) {
				continue;
			}
			$other_id = $other->get_service_id();
			if ( '' === $other_id ) {
				continue;
			}
			if ( ! isset( $taken[ $other_id ] ) ) {
				$taken[ $other_id ] = array();
			}
			$taken[ $other_id ][ (int) $other->get_category() ] = true;
		}
		$candidate = $service_id;
		$suffix    = 2;
		while ( isset( $taken[ $candidate ] ) && ! isset( $taken[ $candidate ][ (int) $category_id ] ) ) {
			$candidate = $service_id . '-' . $suffix;
			++$suffix;
		}
		return $candidate;
	}

	/**
	 * Return a list of Cookies tables
	 *
	 * @return array Cookies tables.
	 */
	protected function get_tables() {
		global $wpdb;
		$tables = array(
			"{$wpdb->prefix}faz_cookies",
		);
		return $tables;
	}

	/**
	 * Load default banner
	 *
	 * @return void
	 */
	protected function load_default() {
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
        CREATE TABLE {$wpdb->prefix}faz_cookies (
			cookie_id bigint(20) NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			slug varchar(190) NOT NULL DEFAULT '',
			description longtext NOT NULL,
			duration text NOT NULL,
			domain varchar(190) NOT NULL DEFAULT '',
			category bigint(20) NOT NULL,
			type text NOT NULL,
			discovered int(11) NOT NULL default 0,
			url_pattern varchar(190) NULL default '',
			meta longtext,
			date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (cookie_id),
			KEY category (category),
			KEY name (name)
        ) ENGINE=InnoDB $collate;
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
		if ( false === $this->table_exist() ) {
			return $items;
		}

		if ( isset( $args['id'] ) && '' !== $args['id'] ) {
			$results = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}faz_cookies` WHERE `cookie_id` = %d", absint( $args['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( isset( $args['category'] ) && '' !== $args['category'] ) {
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}faz_cookies` WHERE `category` = %d", absint( $args['category'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			// Unfiltered "get all" path. Bound it with a high but finite cap so
			// a runaway scanner that discovered tens of thousands of cookies
			// cannot OOM the request loading + caching every row. 10000 is far
			// beyond any legitimate cookie inventory; deterministic ORDER BY
			// keeps the truncation (if it ever happens) stable across requests.
			$results = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}faz_cookies` ORDER BY `cookie_id` ASC LIMIT 10000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( isset( $results ) && ! empty( $results ) ) {
			if ( true === is_array( $results ) ) {
				foreach ( $results as $data ) {
					$item = $this->prepare_item( $data );
					if ( ! empty( $item ) ) {
						$items[ $item->{$this->id} ] = $item;
						$this->set_object_cache( $this->cache_group . '_' . $item->{$this->id}, $item );
					}
				}
			} else {
				$items = $this->prepare_item( $results );
				if ( ! empty( $items ) ) {
					$this->set_object_cache( $this->cache_group . '_' . $items->{$this->id}, $items );
				}
			}
		}
		return $items;
	}

	/**
	 * Get a single item.
	 *
	 * @param integer $id Item ID.
	 * @return array|object
	 */
	public function get_item( $id ) {
		if ( ! $id ) {
			return array();
		}
		$cache_key = $this->cache_group . '_' . $id;
		$cached    = $this->get_object_cache( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$item = $this->get_item_from_db( array( 'id' => $id ) );
		return $item;
	}

	/**
	 * Get cookies based on the category.
	 *
	 * @param boolean|integer|array $category Category id.
	 * @return array
	 */
	public function get_items_by_category( $category = false ) {
		// Support receiving an array of args (from REST API) or a single category ID.
		if ( is_array( $category ) ) {
			$cat = isset( $category['category'] ) ? $category['category'] : false;
		} else {
			$cat = $category;
		}

		$args = array();
		if ( $cat ) {
			$args['category'] = $cat;
		}

		$cache_key = $this->cache_group . '_category_' . ( $cat ? $cat : 'all' );
		$cached    = $this->get_object_cache( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$items = $this->get_item_from_db( $args );
		$this->set_object_cache( $cache_key, $items );
		return $items;
	}

	/**
	 * Create a new cookie record (inserts into wp_faz_cookies).
	 *
	 * @param object $object Cookie object.
	 * @return void|false False on DB-write failure; void on success.
	 */
	public function create_item( $object ) {
		global $wpdb;
		$date_created = current_time( 'mysql' );
		$object->set_date_created( $date_created );
		$object->set_date_modified( $date_created );
		$stored = $object->get_data();

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_cookies',
			array(
				'name'          => $object->get_name(),
				'slug'          => $object->get_slug(),
				'description'   => wp_json_encode( isset( $stored['description'] ) && is_array( $stored['description'] ) ? $stored['description'] : array() ),
				'duration'      => wp_json_encode( isset( $stored['duration'] ) && is_array( $stored['duration'] ) ? $stored['duration'] : array() ),
				'domain'        => $object->get_domain(),
				'category'      => $object->get_category(),
				'type'          => $object->get_type(),
				'discovered'    => ( true === $object->is_discovered() ? 1 : 0 ),
				'url_pattern'   => $object->get_url_pattern(),
				'meta'          => wp_json_encode( $object->get_meta() ),
				'date_created'  => $object->get_date_created(),
				'date_modified' => $object->get_date_modified(),
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
		if ( false === $result ) {
			return false;
		}
		$object->set_id( $wpdb->insert_id );
		$this->delete_cache();
		// In bulk mode (scanner import loop) the caller fires this once after
		// the loop — the listeners (unmatched-vendor re-check, page-cache
		// purge) are whole-dataset operations that must not run per row.
		if ( ! self::is_cache_invalidation_suspended() ) {
			do_action( 'faz_after_create_cookie' );
		}
	}

	/**
	 * Update an existing category on a local db.
	 *
	 * @param object $object category object.
	 * @return void
	 */
	public function update_item( $object ) {
		global $wpdb;
		$date_modified = current_time( 'mysql' );
		$object->set_date_modified( $date_modified );
		// Revisions merged over data, and that is the whole point. On an object
		// LOADED from the database, set_object_data() stages every change in
		// $this->revisions and leaves $this->data holding the values as read;
		// get_data() returns only the latter. Reading description and duration
		// straight from it therefore wrote back exactly what was already stored and
		// dropped the edit — silently, because name, category and every other field
		// go through getters, which DO consult revisions, so a save looked like it
		// worked while the two multilingual fields snapped back.
		$stored = array_merge( $object->get_data(), $object->get_revisions() );
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_cookies',
			array(
				'name'          => $object->get_name(),
				'slug'          => $object->get_slug(),
				'description'   => wp_json_encode( isset( $stored['description'] ) && is_array( $stored['description'] ) ? $stored['description'] : array() ),
				'duration'      => wp_json_encode( isset( $stored['duration'] ) && is_array( $stored['duration'] ) ? $stored['duration'] : array() ),
				'domain'        => $object->get_domain(),
				'category'      => $object->get_category(),
				'type'          => $object->get_type(),
				'discovered'    => ( true === $object->is_discovered() ? 1 : 0 ),
				'url_pattern'   => $object->get_url_pattern(),
				'meta'          => wp_json_encode( $object->get_meta() ),
				'date_created'  => $object->get_date_created(),
				'date_modified' => $date_modified,
			),
			array( 'cookie_id' => $object->get_id() ),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
		if ( false === $result ) {
			return false;
		}
		// 0 === $result is "no row changed" (id not matched, or identical
		// values) — nothing to invalidate. Mirror delete_item()'s contract.
		if ( 0 === $result ) {
			return 0;
		}
		$this->delete_cache();
		do_action( 'faz_after_update_cookie' );
		return $result;
	}

	/**
	 * Delete a cookie from the database.
	 *
	 * @param object $object Cookie object.
	 * @return void|false
	 */
	public function delete_item( $object ) {
		global $wpdb;
		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_cookies',
			array(
				'cookie_id' => $object->get_id(),
			),
			array( '%d' )
		);
		if ( false === $result ) {
			return false;
		}
		// 0 === $result is "no row matched" (the caller passed an id that does
		// not exist in wp_faz_cookies). Skip the cache flush + after-delete
		// action in that case — emitting `faz_after_delete_cookie` for a
		// non-event would wake up every listener (Category_Controller cache
		// invalidation, IAB unmatched-vendor canary, page-cache plugin purge,
		// …) for nothing, and the cache invalidations are NOT free at the
		// thousand-cookie scale this plugin ships with.
		if ( 0 === $result ) {
			return 0;
		}
		$this->delete_cache();
		do_action( 'faz_after_delete_cookie' );
	}

	/**
	 * Properly sanitize category data before sending to the controllers.
	 *
	 * @param object $item Category raw data.
	 * @return object|false
	 */
	public function prepare_item( $item ) {

		if ( false === is_object( $item ) ) {
			return false;
		}
		$object                = new stdClass();
		$object->cookie_id     = isset( $item->cookie_id ) ? absint( $item->cookie_id ) : 0;
		$object->name          = isset( $item->name ) ? sanitize_text_field( $item->name ) : '';
		$object->slug          = isset( $item->slug ) ? sanitize_text_field( $item->slug ) : '';
		$object->description   = isset( $item->description ) ? $this->prepare_json( $item->description ) : array();
		$object->duration      = isset( $item->duration ) ? $this->prepare_json( $item->duration ) : array();
		$object->domain        = isset( $item->domain ) ? sanitize_text_field( $item->domain ) : '';
		$object->category      = isset( $item->category ) ? absint( $item->category ) : '';
		$object->type          = isset( $item->type ) ? sanitize_text_field( $item->type ) : '';
		$object->discovered    = isset( $item->discovered ) ? absint( $item->discovered ) : 0;
		$object->url_pattern   = isset( $item->url_pattern ) ? sanitize_textarea_field( $item->url_pattern ) : '';
		$object->meta          = isset( $item->meta ) ? $this->prepare_json( $item->meta ) : array();
		$object->date_created  = isset( $item->date_created ) ? sanitize_text_field( $item->date_created ) : '';
		$object->date_modified = isset( $item->date_modified ) ? sanitize_text_field( $item->date_modified ) : '';
		return $object;
	}

	/**
	 * Decode a JSON string if necessary
	 *
	 * @param string $data String data.
	 * @return array
	 */
	public function prepare_json( $data ) {
		return is_string( $data ) ? json_decode( ( $data ), true ) : $data;
	}
}
