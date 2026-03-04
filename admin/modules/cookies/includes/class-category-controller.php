<?php
/**
 * Class Category_Controller file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Cookies\Includes;

use FazCookie\Includes\Base_Controller;
use FazCookie\Includes\Cache;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Category_Controller
 * @version     3.0.0
 * @package     FazCookie
 */
class Category_Controller extends Base_Controller {

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
	protected $cache_group = 'categories';

	/**
	 * Table versioning option name.
	 *
	 * @var string
	 */
	protected $table_option = 'cookie_category';

	/**
	 * Cateogory identifier key.
	 *
	 * @var string
	 */
	protected $id = 'category_id';
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
			"{$wpdb->prefix}faz_cookie_categories",
		);
		return $tables;
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
		CREATE TABLE {$wpdb->prefix}faz_cookie_categories (
			category_id bigint(20) NOT NULL AUTO_INCREMENT,
			name text NOT NULL,
			slug varchar(190) NOT NULL DEFAULT '',
			description longtext NOT NULL,
			prior_consent int(11) NOT NULL default 0,
			visibility int(11) NOT NULL default 1,
			priority int(11) NOT NULL default 0,
			sell_personal_data int(11) NOT NULL default 0,
			meta longtext NULL,
			date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (category_id),
			UNIQUE KEY slug (slug)
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
			$results = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}faz_cookie_categories` WHERE `category_id` = %d", absint( $args['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$results = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}faz_cookie_categories`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( isset( $results ) && ! empty( $results ) ) {
			if ( true === is_array( $results ) ) {
				foreach ( $results as $data ) {
					$item = $this->prepare_item( $data );
					if ( ! empty( $item ) ) {
						$item->cookies               = $this->get_cookies( $item->category_id );
						$items[ $item->{$this->id} ] = $item;
					}
				}
			} else {
				$items = $this->prepare_item( $results );
				if ( ! empty( $items ) ) {
					$items->cookies = $this->get_cookies( $results->category_id );
				}
			}
		}
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
			$wpdb->prefix . 'faz_cookie_categories',
			array(
				'name'               => wp_json_encode( $object->get_name() ),
				'slug'               => $object->get_slug(),
				'description'        => wp_json_encode( $object->get_description() ),
				'prior_consent'      => ( true === $object->get_prior_consent() ? 1 : 0 ),
				'visibility'         => ( true === $object->get_visibility() ? 1 : 0 ),
				'priority'           => $object->get_priority(),
				'sell_personal_data' => ( true === $object->get_sell_personal_data() ? 1 : 0 ),
				'meta'               => wp_json_encode( $object->get_meta() ),
				'date_created'       => $object->get_date_created(),
				'date_modified'      => $object->get_date_modified(),
			),
			array(
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);
		$object->set_id( $wpdb->insert_id );
		do_action( 'faz_after_update_cookie_category' );
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
			$wpdb->prefix . 'faz_cookie_categories',
			array(
				'name'               => wp_json_encode( $object->get_name() ),
				'slug'               => $object->get_slug(),
				'description'        => wp_json_encode( $object->get_description() ),
				'prior_consent'      => ( true === $object->get_prior_consent() ? 1 : 0 ),
				'visibility'         => ( true === $object->get_visibility() ? 1 : 0 ),
				'priority'           => $object->get_priority(),
				'sell_personal_data' => ( true === $object->get_sell_personal_data() ? 1 : 0 ),
				'meta'               => wp_json_encode( $object->get_meta() ),
				'date_modified'      => $object->get_date_modified(),
			),
			array( 'category_id' => $object->get_id() ),
			array(
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
			)
		);
		if ( defined( 'FAZ_BULK_REQUEST' ) && FAZ_BULK_REQUEST ) {
			return;
		}
		do_action( 'faz_after_update_cookie_category' );
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
		$object                     = new stdClass();
		$object->category_id        = isset( $item->category_id ) ? absint( $item->category_id ) : 0;
		$object->name               = isset( $item->name ) ? $this->prepare_json( $item->name ) : '';
		$object->slug               = isset( $item->slug ) ? sanitize_text_field( $item->slug ) : '';
		$object->description        = isset( $item->description ) ? $this->prepare_json( $item->description ) : '';
		$object->prior_consent      = isset( $item->prior_consent ) ? absint( $item->prior_consent ) : '';
		$object->priority           = isset( $item->priority ) ? absint( $item->priority ) : '';
		$object->visibility         = isset( $item->visibility ) ? absint( $item->visibility ) : 0;
		$object->sell_personal_data = isset( $item->sell_personal_data ) ? absint( $item->sell_personal_data ) : 1;
		$object->meta               = isset( $item->meta ) ? $this->prepare_json( $item->meta ) : '';
		$object->date_created       = isset( $item->date_created ) ? sanitize_text_field( $item->date_created ) : '';
		$object->date_modified      = isset( $item->date_modified ) ? sanitize_text_field( $item->date_modified ) : '';
		return $object;
	}

	/**
	 * Delete a category from database.
	 *
	 * @param object $object Category object.
	 * @return void
	 */
	public function delete_item( $object ) {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_cookie_categories',
			array(
				'category_id' => $object->get_id(),
			)
		);
		do_action( 'faz_after_update_cookie_category' );
	}

	/**
	 * Get contents by language.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		$contents = wp_cache_get( 'faz_category_contents_en', 'faz_category_contents' );
		if ( ! $contents ) {
			$contents = faz_read_json_file( dirname( __FILE__ ) . '/contents/categories/en.json' );
			wp_cache_set( 'faz_category_contents_en', $contents, 'faz_category_contents', 12 * HOUR_IN_SECONDS );
		}
		return $contents;
	}
	/**
	 * Load default cookies.
	 *
	 * @return void
	 */
	protected function load_default() {
		$categories = self::get_defaults();
		$lang       = faz_default_language();
		foreach ( $categories as $slug => $category ) {
			$object               = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories();
			$name[ $lang ]        = isset( $category['name'] ) ? $category['name'] : '';
			$description[ $lang ] = isset( $category['description'] ) ? $category['description'] : '';
			$object->set_name( $name );
			$object->set_description( $description );
			$object->set_slug( $slug );
			if ( 'necessary' === $slug || 'uncategorized' === $slug ) {
				$object->set_prior_consent( true );
			}
			$object->save();
		}
	}

	/**
	 * Decode a JSON string if necessary
	 *
	 * @param string $data String data.
	 * @return array
	 */
	public function prepare_json( $data ) {
		if ( empty( $data ) ) {
			return array();
		}
		return is_string( $data ) ? json_decode( $data, true ) : $data;
	}

	/**
	 * Load items from the cache.
	 *
	 * @param boolean $id Category ID.
	 * @return array|object|false
	 */
	protected function get_cache( $id = false ) {
		$key        = 'all';
		$categories = array();
		$items      = Cache::get( $key, $this->cache_group );
		if ( false === $items ) {
			return false;
		}
		if ( ! empty( $items ) ) {
			foreach ( $items as $data ) {
				$item = $this->prepare_item( $data );
				if ( ! empty( $item ) ) {
					$item->cookies                    = $data->cookies;
					$categories[ $item->category_id ] = $item;
				}
			}
		}
		return isset( $id ) && isset( $categories[ $id ] ) ? $categories[ $id ] : $categories;
	}

	/**
	 * Get cookies of each category.
	 *
	 * @param string|int $category Category slug or id.
	 * @return array
	 */
	public function get_cookies( $category = '' ) {
		$cookies = array();
		if ( empty( $category ) ) {
			return array();
		}
		$items = Cookie_Controller::get_instance()->get_items_by_category( $category );
		foreach ( $items as $data ) {
			$object    = new Cookie( $data );
			$cookies[] = $object->get_prepared_data();
		}
		return $cookies;
	}
}
