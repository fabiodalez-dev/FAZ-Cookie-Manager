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
			date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (banner_id)
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
			$results = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}faz_banners`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
				'name'           => $banner->get_name(),
				'slug'           => $banner->get_slug(),
				'status'         => ( true === $banner->get_status() ? 1 : 0 ),
				'settings'       => wp_json_encode( $banner->get_settings() ),
				'banner_default' => ( true === $banner->get_default() ? 1 : 0 ),
				'contents'       => wp_json_encode( $banner->get_contents() ),
				'date_created'   => $banner->get_date_created(),
				'date_modified'  => $banner->get_date_modified(),
			),
			array(
				'%s',
				'%s',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);
		if ( false !== $created && $wpdb->insert_id ) {
			$id = $wpdb->insert_id;
			$banner->set_id( $id );
			$banner->set_slug( $banner->get_name() );
			$slug = $banner->get_slug() . '-' . $id; // Append ID to the slug of the each banner.
			$banner->set_slug( $slug );
			$banner->save();
			$banner->set_id( $wpdb->insert_id );
			$this->delete_cache();
		}
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
			'name'           => $banner->get_name(),
			'slug'           => $banner->get_slug(),
			'status'         => ( true === $banner->get_status() ? 1 : 0 ),
			'settings'       => wp_json_encode( $banner->get_settings() ),
			'banner_default' => ( true === $banner->get_default() ? 1 : 0 ),
			'contents'       => wp_json_encode( $banner->get_contents() ),
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
			)
		);
		if ( false !== $updated ) {
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
		if ( false !== $status && $status > 0 ) {
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
		$items        = $this->get_items();
		$current_lang = faz_current_language();
		foreach ( $items as $key => $item ) {
			$banner = new Banner( $item->banner_id );
			if ( true === $banner->get_status() ) {
				$banner->set_language( $current_lang );
				return $banner;
			}
		}
		return false;
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
