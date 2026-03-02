<?php
/**
 * Cache class.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Cache.
 */
class Cache {

	/**
	 * Get prefix for use with wp_cache_set. Allows all cache in a group to be invalidated at once.
	 *
	 * @param  string $group Group of cache to get.
	 * @return string
	 */
	public static function get_cache_prefix( $group ) {
		$prefix = wp_cache_get( 'faz_' . $group . '_cache_prefix', $group );
		if ( false === $prefix ) {
			$prefix = microtime();
			wp_cache_set( 'faz_' . $group . '_cache_prefix', $prefix, $group );
		}

		return 'faz_cache_' . $prefix . '_';
	}

	/**
	 * Invalidate cache group.
	 *
	 * @param string $group Group of cache to clear.
	 * @since 3.9.0
	 */
	public static function invalidate_cache_group( $group ) {
		wp_cache_set( 'faz_' . $group . '_cache_prefix', microtime(), $group );
	}

	/**
	 * Delete cache group
	 *
	 * @param string $group Cache group name.
	 * @return void
	 */
	public static function delete_cache( $group ) {
		wp_cache_set( 'faz_' . $group . '_cache_prefix', microtime(), $group );
	}
	/**
	 * Get cache from either transient or object cache.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return bool|array
	 */
	public static function get( $key, $group ) {
		$items = self::get_cache( $key, $group );
		if ( false === $items ) { // Object cache is empty so fetch from transients.
			$items = self::get_transient( $key, $group );
			self::set_cache( $key, $group, $items );
		}
		return $items;
	}

	/**
	 * Store data to both object cache and transient.
	 *
	 * @param string  $key Cache key.
	 * @param string  $group Cache group.
	 * @param array   $data Data to be store.
	 * @param boolean $transient If true store data in transients.
	 */
	public static function set( $key, $group, $data = array(), $transient = true ) {
		self::set_cache( $key, $group, $data );
		if ( $transient ) {
			self::set_transient( $key, $group, $data );
		}
	}
	/**
	 * Delete the cache
	 *
	 * @param string $group Cache group.
	 * @return void
	 */
	public static function delete( $group ) {
		self::delete_cache( $group );
		self::delete_transient( $group );
	}

	/**
	 * Load items from the object cache.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return bool|array
	 */
	public static function get_cache( $key, $group ) {
		$key   = self::get_cache_prefix( $group ) . $key;
		$items = wp_cache_get( $key, $group );
		if ( $items ) {
			return $items;
		}
		return false;
	}
	/**
	 * Store data to the cache
	 *
	 * @param string       $key Cache key.
	 * @param string       $group Cache group.
	 * @param array|object $data Data to be stored.
	 * @return void
	 */
	public static function set_cache( $key, $group, $data ) {
		$key = self::get_cache_prefix( $group ) . $key;
		wp_cache_set( $key, $data, $group );
	}


	/** Transient Functions */

	/**
	 * Get unique transient key based on time.
	 *
	 * @param string $group Transient.
	 * @return string
	 */
	public static function get_transient_prefix( $group ) {
		$prefix = get_transient( 'faz_' . $group . '_transient_prefix' );
		if ( false === $prefix ) {
			$prefix = microtime();
			set_transient( 'faz_' . $group . '_transient_prefix', $prefix );
		}
		return 'faz_transient_' . $prefix . '_';
	}

	/**
	 * Load items from the transient
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return bool|array
	 */
	public static function get_transient( $key, $group ) {
		$key   = self::get_transient_prefix( $group ) . $key;
		$items = get_transient( $key );
		if ( $items ) {
			return $items;
		}
		return false;
	}

	/**
	 * Store data to the transient
	 *
	 * @param string       $key Cache key.
	 * @param string       $group Cache group.
	 * @param array|object $data Data to be stored.
	 * @return void
	 */
	public static function set_transient( $key, $group, $data ) {
		$key = self::get_transient_prefix( $group ) . $key;
		set_transient( $key, $data );

	}

	/**
	 * Get all transients with prefix "faz" default
	 *
	 * @param string $prefix Transient prefix.
	 * @return array
	 */
	public static function get_transient_keys_with_prefix( $prefix ) {
		global $wpdb;

		$prefix = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		$keys   = $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $prefix ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( is_wp_error( $keys ) ) {
			return array();
		}

		return array_map(
			function( $key ) {
				// Remove '_transient_' prefix from the option name.
				$name = $key['option_name'];
				return 0 === strpos( $name, '_transient_' ) ? substr( $name, 11 ) : $name;
			},
			$keys
		);
	}

	/**
	 * Delete all transients with certain prefix.
	 *
	 * @param string $group Transient group.
	 * @return void
	 */
	public static function delete_transient( $group ) {
		$prefix     = self::get_transient_prefix( $group );
		$transients = self::get_transient_keys_with_prefix( $prefix );
		foreach ( $transients as $key ) {
			delete_transient( $key );
		}
	}
}
