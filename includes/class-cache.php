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
	 * Per-request memoization of group → resolved prefix.
	 *
	 * Critical: without this cache, if the underlying store (`wp_cache_*`
	 * backend or the `options` table that backs `get_transient()`) returns
	 * false for any reason — transient not set yet, DB error, persistent
	 * object cache miss on a non-persistent install — every call to
	 * `get_cache_prefix()` / `get_transient_prefix()` would regenerate a
	 * new `microtime()` prefix. That makes every cached item unreachable
	 * from subsequent lookups AND triggers a fresh `set_transient()`
	 * write. For payloads as large as the plugin's category/cookie list
	 * (~40 KB serialized), a single MySQL error is enough to kick off a
	 * cascade: the failing INSERT is logged verbatim by wpdb (with the
	 * full serialized value inline), the caller retries, each retry
	 * regenerates the prefix and logs another 40 KB query — a single
	 * E2E run produced a 40 GB `debug.log`.
	 *
	 * Memoizing here pins the prefix to whatever the store returned on
	 * the first call of the request. If the store is broken the prefix
	 * is still stable for the rest of the request, so we stop multiplying
	 * log entries and stop creating orphan `_transient_faz_transient_*`
	 * rows in `wp_options` on every pageload.
	 *
	 * @var array<string,string>
	 */
	private static $prefix_cache = array();

	/**
	 * Per-request memoization for transient prefixes (separate namespace so
	 * object-cache and transient prefixes can diverge within a request when
	 * both backends are consulted).
	 *
	 * @var array<string,string>
	 */
	private static $transient_prefix_cache = array();

	/**
	 * Reset the per-request memoization. Used by tests and by
	 * `invalidate_cache_group()` / `delete_cache()` so the next lookup
	 * picks up the freshly-written prefix.
	 *
	 * @param string $group Group to reset, or empty string to reset all.
	 * @return void
	 */
	public static function reset_prefix_cache( $group = '' ) {
		if ( '' === $group ) {
			self::$prefix_cache           = array();
			self::$transient_prefix_cache = array();
			return;
		}
		unset( self::$prefix_cache[ $group ], self::$transient_prefix_cache[ $group ] );
	}

	/**
	 * Get prefix for use with wp_cache_set. Allows all cache in a group to be invalidated at once.
	 *
	 * @param  string $group Group of cache to get.
	 * @return string
	 */
	public static function get_cache_prefix( $group ) {
		if ( isset( self::$prefix_cache[ $group ] ) ) {
			return self::$prefix_cache[ $group ];
		}

		$prefix = wp_cache_get( 'faz_' . $group . '_cache_prefix', $group );
		if ( false === $prefix ) {
			$prefix = microtime();
			wp_cache_set( 'faz_' . $group . '_cache_prefix', $prefix, $group );
		}

		self::$prefix_cache[ $group ] = 'faz_cache_' . $prefix . '_';
		return self::$prefix_cache[ $group ];
	}

	/**
	 * Invalidate cache group.
	 *
	 * @param string $group Group of cache to clear.
	 * @since 3.9.0
	 */
	public static function invalidate_cache_group( $group ) {
		wp_cache_set( 'faz_' . $group . '_cache_prefix', microtime(), $group );
		self::reset_prefix_cache( $group );
	}

	/**
	 * Delete cache group
	 *
	 * @param string $group Cache group name.
	 * @return void
	 */
	public static function delete_cache( $group ) {
		wp_cache_set( 'faz_' . $group . '_cache_prefix', microtime(), $group );
		self::reset_prefix_cache( $group );
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
		if ( isset( self::$transient_prefix_cache[ $group ] ) ) {
			return self::$transient_prefix_cache[ $group ];
		}

		$prefix = get_transient( 'faz_' . $group . '_transient_prefix' );
		if ( false === $prefix ) {
			$prefix = microtime();
			set_transient( 'faz_' . $group . '_transient_prefix', $prefix );
		}

		self::$transient_prefix_cache[ $group ] = 'faz_transient_' . $prefix . '_';
		return self::$transient_prefix_cache[ $group ];
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
		// Drop the memoized prefix so the next call after the underlying
		// transient has been invalidated upstream picks up a fresh one.
		self::reset_prefix_cache( $group );
	}
}
