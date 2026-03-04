<?php
/**
 * WordPress file sytstem API.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie\Includes
 */

namespace FazCookie\Includes;

use FazCookie\Includes\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Controller Class
 *
 * @package FazCookie
 * @version  3.0.0
 */
abstract class Base_Controller {
	/**
	 * Cache group.
	 *
	 * @var string
	 */
	protected $cache_group = '';

	/**
	 * Table versioning option name.
	 *
	 * @var string
	 */
	protected $table_option = '';
	/**
	 * Item unique identifier
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Load items from cache if any.
	 *
	 * @param boolean|integer $id Item id.
	 * @return array|object|false
	 */
	protected function get_cache( $id = false ) {
		$items  = array();
		$cached = Cache::get( 'all', $this->cache_group );
		if ( false === $cached ) {
			return false;
		}
		if ( ! empty( $cached ) ) {
			foreach ( $cached as $data ) {
				$item = $this->prepare_item( $data );
				if ( ! empty( $item ) ) {
					$items[ $item->{$this->id} ] = $item;
				}
			}
		}
		return isset( $id ) && isset( $items[ $id ] ) ? $items[ $id ] : $items;
	}
	/**
	 * Set items to the cache.
	 *
	 * @param array $data Data.
	 * @return void
	 */
	protected function set_cache( $data = array() ) {
		Cache::set( 'all', $this->cache_group, $data );
	}

	/**
	 * Delete the cache.
	 *
	 * @return void
	 */
	public function delete_cache() {
		Cache::delete( $this->cache_group );
		wp_cache_delete( 'faz_settings', 'options' );
		wp_cache_delete( 'faz_banner_template', 'options' );
	}

	/**
	 * Reset the cache on page load.
	 *
	 * @return void
	 */
	public function reset_cache() {
		if ( faz_is_admin_request() && faz_is_admin_page() ) {
			Cache::delete( $this->cache_group );
		}
	}

	/**
	 * Get multiple items.
	 *
	 * @param array $args Array of arguments.
	 * @return array
	 */
	public function get_items( $args = array() ) {
		$cached = $this->get_cache();
		if ( false !== $cached ) {
			return $cached;
		}
		$items = $this->get_item_from_db( $args );
		$this->set_cache( $items );
		return $items;
	}

	/**
	 * Get a single item.
	 *
	 * @param integer $id Item ID.
	 * @return array|object
	 */
	public function get_item( $id ) {
		$cached = $this->get_cache( $id );
		if ( false !== $cached ) {
			return $cached;
		}
		$item = $this->get_item_from_db( array( 'id' => $id ) );
		return $item;
	}

	/**
	 * Load data directly from database.
	 *
	 * @param array $args Array of arguments.
	 * @return array|object
	 */
	abstract protected function get_item_from_db( $args = array() );

	/**
	 * Create an item.
	 *
	 * @param object $object Item object.
	 * @return void
	 */
	abstract public function create_item( $object );

	/**
	 * Update an item.
	 *
	 * @param object $object Item object.
	 * @return void
	 */
	abstract public function update_item( $object );

	/**
	 * Delete an item.
	 *
	 * @param object $object Item object.
	 * @return void
	 */
	abstract public function delete_item( $object );

	/**
	 * Delete an item.
	 *
	 * @param object $object Item object.
	 * @return array|object
	 */
	abstract public function prepare_item( $object );

	/**
	 * Get table schema from each module.
	 *
	 * @return string
	 */
	abstract protected function get_schema();

	/**
	 * Get list of tables to be created.
	 *
	 * @return array
	 */
	abstract protected function get_tables();

	/**
	 * Insert default data to the plugin.
	 *
	 * @return void
	 */
	abstract protected function load_default();

	/**
	 * Install tables on the database.
	 *
	 * @return void
	 */
	public function install_tables() {
		if ( get_option( "faz_{$this->table_option}_table_version" ) !== FAZ_VERSION ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $this->get_schema(), true );
			$this->validate_tables();
		}
	}

	/**
	 * Validate if all the necessary tables are inserted
	 *
	 * @param boolean $force Force install tables and data's.
	 * @return void
	 */
	public function validate_tables( $force = false ) {
		$queries        = dbDelta( $this->get_schema(), false );
		$missing_tables = false;
		foreach ( $queries as $table_name => $result ) {
			if ( "Created table $table_name" === $result ) {
				if ( in_array( $table_name, $this->get_tables(), true ) ) {
					$missing_tables = true;
					$this->update_missing_tables( $table_name );
				}
			}
		}
		if ( false === $missing_tables ) {
			if ( false === $this->data_exist() || true === $force ) {
				$this->load_default();
			}
			foreach ( $this->get_tables() as $table ) {
				$this->update_missing_tables( $table, true );
			}
			update_option( "faz_{$this->table_option}_table_version", FAZ_VERSION );
		}
	}

	/**
	 * Reinstall the tables if not installed.
	 *
	 * @return void
	 */
	public function reinstall() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->get_schema(), true );
		$this->validate_tables( ! $this->data_exist() );
	}

	/**
	 * Return a list of missing tables.
	 *
	 * @return array
	 */
	protected function missing_tables() {
		return get_option( 'faz_missing_tables', array() );
	}

	/**
	 * Add or delete missing tables
	 *
	 * @param string  $table_name Tablename.
	 * @param boolean $clear Whether to keep or remove table name from the list.
	 * @return void
	 */
	protected function update_missing_tables( $table_name = null, $clear = false ) {
		if ( ! $table_name ) {
			return;
		}
		$missing_tables = get_option( 'faz_missing_tables', array() );
		if ( true === $clear ) {
			if ( isset( $missing_tables[ $table_name ] ) ) {
				unset( $missing_tables[ $table_name ] );
			}
		} else {
			if ( ! isset( $missing_tables[ $table_name ] ) ) {
				$missing_tables[ $table_name ] = true;
			}
		}
		update_option( 'faz_missing_tables', $missing_tables );
	}

	/**
	 * Check if table exist.
	 *
	 * @return boolean
	 */
	protected function table_exist() {
		foreach ( $this->get_tables() as $table_name ) {
			$table = wp_cache_get( $table_name . 'faz_table_exist', 'table-details' );
			if ( $table === false ) {
				global $wpdb;
				$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				wp_cache_set( $table_name . 'faz_table_exist', $table, 'table-details', MINUTE_IN_SECONDS );
			}
			if ( strtolower( $table_name ) !== strtolower( isset($table) ? $table : '' ) )  { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->update_missing_tables( $table_name );
				return false;
			}
		}
		return true;
	}

	/**
	 * Check if table is empty
	 *
	 * @return boolean
	 */
	protected function data_exist() {
		global $wpdb;
		$count = 0;
		foreach ( $this->get_tables() as $table_name ) {
			if ( ! $this->table_exist() ) {
				return false;
			}
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $count > 0 ) {
				return true;
			} else {
				$this->update_missing_tables( $table_name ); // Possibility for missing data while creating the table.
				return false;
			}
		}
		return false;
	}
}
