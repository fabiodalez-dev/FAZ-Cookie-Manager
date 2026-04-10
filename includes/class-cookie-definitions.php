<?php
/**
 * Open Cookie Database integration.
 *
 * Downloads and caches cookie definitions from the forked Open-Cookie-Database
 * repo on GitHub. Provides local lookup for auto-categorization — replaces the
 * cookie.is scraper with a fully offline, license-clean solution (Apache-2.0).
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cookie_Definitions {

	/**
	 * Raw GitHub URL for the JSON definitions file.
	 * Points to the user's fork so they can sync upstream updates.
	 */
	const SOURCE_URL = 'https://raw.githubusercontent.com/fabiodalez-dev/Open-Cookie-Database/master/open-cookie-database.json';

	/**
	 * Bundled snapshot shipped with the plugin for first-run lookups.
	 */
	const BUNDLED_DATA_FILE = 'includes/data/open-cookie-database.json';

	/**
	 * WP option key where definitions are cached.
	 */
	const OPTION_KEY = 'faz_cookie_definitions';

	/**
	 * WP option key for metadata (last update time, count, version).
	 */
	const META_KEY = 'faz_cookie_definitions_meta';

	/**
	 * Map Open Cookie Database categories → FAZ category slugs.
	 *
	 * @var array
	 */
	private static $category_map = array(
		'necessary'  => 'necessary',
		'functional' => 'functional',
		'analytics'  => 'analytics',
		'marketing'  => 'marketing',
		'security'   => 'necessary',
	);

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * In-memory cache of definitions keyed by lowercase cookie name.
	 *
	 * @var array|null
	 */
	private $lookup = null;

	/**
	 * Wildcard entries (wildcardMatch=1) for pattern matching.
	 *
	 * @var array|null
	 */
	private $wildcards = null;

	/**
	 * Cached bundled definitions payload.
	 *
	 * @var array|null
	 */
	private $bundled_data = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Download definitions from GitHub and store locally.
	 *
	 * @return array{success: bool, count: int, message: string}
	 */
	public function update_definitions() {
		$response = wp_remote_get(
			self::SOURCE_URL,
			array(
				'timeout'    => 30,
				'user-agent' => 'FAZCookieManager/1.0 (WordPress)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'count'   => 0,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'success' => false,
				'count'   => 0,
				'message' => sprintf( 'HTTP %d from GitHub', $code ),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data ) ) {
			return array(
				'success' => false,
				'count'   => 0,
				'message' => 'Invalid JSON or empty dataset',
			);
		}

		$total_cookies = $this->count_definitions( $data );

		// Store raw definitions.
		update_option( self::OPTION_KEY, $data, false ); // autoload=false (large)
		update_option(
			self::META_KEY,
			array(
				'updated_at' => current_time( 'mysql' ),
				'count'      => $total_cookies,
				'source'     => self::SOURCE_URL,
			),
			false // autoload=false, matches OPTION_KEY and keeps meta out of the autoload bucket
		);

		// Clear in-memory cache.
		$this->lookup    = null;
		$this->wildcards = null;

		return array(
			'success' => true,
			'count'   => $total_cookies,
			'message' => sprintf( 'Downloaded %d cookie definitions', $total_cookies ),
		);
	}

	/**
	 * Check if definitions have been downloaded.
	 *
	 * @return bool
	 */
	public function has_definitions() {
		$stored = get_option( self::OPTION_KEY, false );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return true;
		}

		$bundled = $this->get_bundled_data();
		return is_array( $bundled ) && ! empty( $bundled );
	}

	/**
	 * Get metadata about the stored definitions.
	 *
	 * @return array
	 */
	public function get_meta() {
		$stored = get_option( self::OPTION_KEY, false );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			$meta = get_option( self::META_KEY, array() );
			// Normalize legacy META_KEY entries that predate the 'source'
			// field: without this, the UI branch that picks "downloaded"
			// vs. "bundled" datasets can fire on stale metadata even when
			// the active dataset is the downloaded one.
			$defaults = array(
				'updated_at' => '',
				'count'      => $this->count_definitions( $stored ),
				'source'     => self::SOURCE_URL,
			);
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}
			return array_merge( $defaults, $meta );
		}
		return $this->get_bundled_meta();
	}

	/**
	 * Build in-memory lookup index from stored definitions.
	 */
	private function build_lookup() {
		if ( null !== $this->lookup ) {
			return;
		}

		$this->lookup    = array();
		$this->wildcards = array();

		$data = $this->get_runtime_data();
		if ( ! is_array( $data ) ) {
			return;
		}

		// The JSON is grouped by platform: { "Google Analytics": [{...}, ...], ... }
		foreach ( $data as $platform => $entries ) {
			// Handle both grouped format (array of arrays) and flat format (single entry).
			if ( ! is_array( $entries ) ) {
				continue;
			}
			// If the first key is numeric, it's a list of entries; otherwise treat as a single entry.
			$entry_list = isset( $entries[0] ) ? $entries : array( $entries );

			foreach ( $entry_list as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$cookie_name = isset( $entry['cookie'] ) ? $entry['cookie'] : '';
				if ( empty( $cookie_name ) ) {
					continue;
				}

				$normalized = array(
					'name'            => $cookie_name,
					'category'        => $this->map_category( isset( $entry['category'] ) ? $entry['category'] : '' ),
					'description'     => isset( $entry['description'] ) ? $entry['description'] : '',
					'duration'        => isset( $entry['retentionPeriod'] ) ? $entry['retentionPeriod'] : '',
					'domain'          => isset( $entry['domain'] ) ? $entry['domain'] : '',
					'data_controller' => isset( $entry['dataController'] ) ? $entry['dataController'] : '',
					'wildcard'        => ! empty( $entry['wildcardMatch'] ) && '0' !== $entry['wildcardMatch'],
				);

				$key = strtolower( $cookie_name );

				if ( $normalized['wildcard'] ) {
					$this->wildcards[ $key ] = $normalized;
				} else {
					$this->lookup[ $key ] = $normalized;
				}
			}
		}
	}

	/**
	 * Map an Open Cookie Database category to a FAZ slug.
	 *
	 * @param string $category Category from the database.
	 * @return string FAZ category slug.
	 */
	private function map_category( $category ) {
		$lower = strtolower( trim( $category ) );
		return isset( self::$category_map[ $lower ] ) ? self::$category_map[ $lower ] : 'uncategorized';
	}

	/**
	 * Look up a single cookie by name.
	 *
	 * Tries exact match first, then wildcard (prefix) matching.
	 *
	 * @param string $name Cookie name.
	 * @return array|false Normalized definition or false if not found.
	 */
	public function lookup( $name ) {
		$this->build_lookup();

		$key = strtolower( trim( $name ) );

		// 1. Exact match.
		if ( isset( $this->lookup[ $key ] ) ) {
			return $this->lookup[ $key ];
		}

		// 2. Wildcard (prefix) match — the DB entry name is a prefix.
		foreach ( $this->wildcards as $pattern => $def ) {
			if ( 0 === strpos( $key, $pattern ) ) {
				return $def;
			}
		}

		return false;
	}

	/**
	 * Look up multiple cookies at once. Returns the same format as the
	 * old cookie.is scraper endpoint for backward compatibility.
	 *
	 * @param array $names Array of cookie name strings.
	 * @return array Array of result objects compatible with scraper response.
	 */
	public function lookup_batch( $names ) {
		$results = array();

		foreach ( $names as $name ) {
			$name = sanitize_text_field( trim( $name ) );
			if ( empty( $name ) ) {
				continue;
			}

			// Check built-in Cookie_Database first (curated WP cookies, etc.).
			$local = \FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database::lookup( $name );
			if ( $local ) {
				$results[] = array(
					'name'        => $name,
					'category'    => $local['category'],
					'description' => isset( $local['description'] ) ? $local['description'] : '',
					'duration'    => isset( $local['duration'] ) ? $local['duration'] : '',
					'domain'      => '',
					'vendor'      => isset( $local['vendor'] ) ? $local['vendor'] : '',
					'found'       => true,
				);
				continue;
			}

			// Then check Open Cookie Database.
			$def = $this->lookup( $name );
			if ( $def ) {
				$results[] = array(
					'name'        => $name,
					'category'    => $def['category'],
					'description' => $def['description'],
					'duration'    => $def['duration'],
					'domain'      => $def['domain'],
					'vendor'      => $def['data_controller'],
					'found'       => true,
				);
			} else {
				$results[] = array(
					'name'        => $name,
					'category'    => 'uncategorized',
					'description' => '',
					'duration'    => '',
					'domain'      => '',
					'vendor'      => '',
					'found'       => false,
				);
			}
		}

		return $results;
	}

	/**
	 * Return the currently active definitions dataset.
	 *
	 * Updated definitions stored in the database take precedence over the
	 * bundled snapshot that ships with the plugin.
	 *
	 * @return array
	 */
	private function get_runtime_data() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return $stored;
		}

		return $this->get_bundled_data();
	}

	/**
	 * Return the absolute path to the bundled snapshot file.
	 *
	 * @return string
	 */
	private function get_bundled_file_path() {
		return FAZ_PLUGIN_BASEPATH . self::BUNDLED_DATA_FILE;
	}

	/**
	 * Load bundled definitions from disk once per request.
	 *
	 * @return array
	 */
	private function get_bundled_data() {
		if ( null !== $this->bundled_data ) {
			return $this->bundled_data;
		}

		$file = $this->get_bundled_file_path();
		if ( ! is_readable( $file ) ) {
			$this->bundled_data = array();
			return $this->bundled_data;
		}

		$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled JSON snapshot.
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			$this->bundled_data = array();
			return $this->bundled_data;
		}

		$this->bundled_data = $data;
		return $this->bundled_data;
	}

	/**
	 * Return metadata for the bundled snapshot.
	 *
	 * @return array
	 */
	private function get_bundled_meta() {
		$data = $this->get_bundled_data();
		if ( empty( $data ) ) {
			return array();
		}

		$file = $this->get_bundled_file_path();
		return array(
			'count'      => $this->count_definitions( $data ),
			'updated_at' => is_readable( $file ) ? gmdate( 'Y-m-d H:i:s', filemtime( $file ) ) : '',
			'source'     => 'bundled',
		);
	}

	/**
	 * Count individual cookie definitions in a raw dataset.
	 *
	 * @param array $data Raw OCD dataset.
	 * @return int
	 */
	private function count_definitions( array $data ) {
		$total_cookies = 0;
		foreach ( $data as $entries ) {
			if ( is_array( $entries ) ) {
				$total_cookies += isset( $entries[0] ) ? count( $entries ) : 1;
			}
		}

		return $total_cookies;
	}
}
