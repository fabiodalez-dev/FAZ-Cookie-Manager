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
		if ( false === $this->table_exist() ) {
			return $items;
		}

		if ( isset( $args['id'] ) && '' !== $args['id'] ) {
			$results = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}faz_cookies` WHERE `cookie_id` = %d", absint( $args['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( isset( $args['category'] ) && '' !== $args['category'] ) {
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}faz_cookies` WHERE `category` = %d", absint( $args['category'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$results = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}faz_cookies`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( isset( $results ) && ! empty( $results ) ) {
			if ( true === is_array( $results ) ) {
				foreach ( $results as $data ) {
					$item = $this->prepare_item( $data );
					if ( ! empty( $item ) ) {
						$items[ $item->{$this->id} ] = $item;
						wp_cache_set( $this->cache_group . '_' . $item->{$this->id}, $item, $this->cache_group );
					}
				}
			} else {
				$items = $this->prepare_item( $results );
				if ( ! empty( $items ) ) {
					wp_cache_set( $this->cache_group . '_' . $items->{$this->id}, $items, $this->cache_group );
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
		$cached    = wp_cache_get( $cache_key, $this->cache_group );
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
		$cached    = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			return $cached;
		}
		$items = $this->get_item_from_db( $args );
		wp_cache_set( $cache_key, $items, $this->cache_group );
		return $items;
	}

	/**
	 * Create a new category
	 *
	 * @param object $object Category object.
	 * @return void
	 */
	public function create_item( $object ) {
		global $wpdb;
		$date_created = current_time( 'mysql' );
		$object->set_date_created( $date_created );
		$object->set_date_modified( $date_created );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_cookies',
			array(
				'name'          => $object->get_name(),
				'slug'          => $object->get_slug(),
				'description'   => wp_json_encode( $object->get_description() ),
				'duration'      => wp_json_encode( $object->get_duration() ),
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
		$object->set_id( $wpdb->insert_id );
		do_action( 'faz_after_update_cookie' );
	}

	/**
	 * Update an existing category on a local db.
	 *
	 * @param object $object category object.
	 * @return void
	 */
	public function update_item( $object ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_cookies',
			array(
				'name'          => $object->get_name(),
				'slug'          => $object->get_slug(),
				'description'   => wp_json_encode( $object->get_description() ),
				'duration'      => wp_json_encode( $object->get_duration() ),
				'domain'        => $object->get_domain(),
				'category'      => $object->get_category(),
				'type'          => $object->get_type(),
				'discovered'    => ( true === $object->is_discovered() ? 1 : 0 ),
				'url_pattern'   => $object->get_url_pattern(),
				'meta'          => wp_json_encode( $object->get_meta() ),
				'date_created'  => $object->get_date_created(),
				'date_modified' => $object->get_date_modified(),
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
		do_action( 'faz_after_update_cookie' );
	}

	/**
	 * Delete a cookie from the database.
	 *
	 * @param object $object Cookie object.
	 * @return void
	 */
	public function delete_item( $object ) {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_cookies',
			array(
				'cookie_id' => $object->get_id(),
			)
		);
		do_action( 'faz_after_update_cookie' );
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
