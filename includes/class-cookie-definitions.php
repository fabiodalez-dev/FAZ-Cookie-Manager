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
	 * When the bundled snapshot's DATA was captured, in UTC.
	 *
	 * Deliberately not derived from the file's mtime. An unzip sets mtime to
	 * whatever the extractor decides — usually the moment WordPress installed
	 * the update — so mtime answers "when was this file written here", not
	 * "how old is this data". The two differ by however long the release sat
	 * on wordpress.org before a site took it, and the second question is the
	 * one both the staleness check below and the admin screen are asking.
	 *
	 * MUST be updated whenever includes/data/open-cookie-database.json is
	 * regenerated. tests/unit/test-bundled-definitions-date-php.php fails if
	 * the file changes and this does not.
	 */
	const BUNDLED_DATA_DATE = '2026-08-14 09:36:46';

	/**
	 * WP option key where definitions are cached.
	 */
	const OPTION_KEY = 'faz_cookie_definitions';

	/**
	 * WP option key for metadata (last update time, count, version).
	 */
	const META_KEY = 'faz_cookie_definitions_meta';

	/**
	 * Option caching the bundled snapshot's metadata (count / date), keyed by
	 * the file's mtime+size. The bundled JSON is ~2.8 MB — decoding it just to
	 * render the "definitions available" admin notice cost 100+ ms and tens of
	 * MB of peak memory on every FAZ admin screen without a downloaded dataset.
	 */
	const BUNDLED_META_KEY = 'faz_cookie_definitions_bundled_meta';

	/**
	 * Map Open Cookie Database categories → FAZ category slugs.
	 *
	 * @var array
	 */
	private static $category_map = array(
		'necessary'       => 'necessary',
		'functional'      => 'functional',
		'analytics'       => 'analytics',
		'marketing'       => 'marketing',
		'security'        => 'necessary',
		// Google's taxonomy, not the GDPR one: personalization_storage is a
		// Consent Mode v2 signal, and gcm.js already drives it from the
		// functional category. Without this row the database's own
		// "Personalization" entries fell through to uncategorized, so the same
		// cookie was functional to Google and unclassified to the banner.
		'personalization' => 'functional',
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
				// Local time for display — every admin screen shows local — and
				// the SAME instant in UTC for comparison. Storing only the local
				// string forced the reader to guess the offset that was in force
				// when it was written, which is unknowable across a DST change
				// and is the ambiguity that made the comparison fragile. This is
				// WordPress's own post_date / post_date_gmt convention.
				'updated_at'     => current_time( 'mysql' ),
				'updated_at_gmt' => current_time( 'mysql', true ),
				'count'          => $total_cookies,
				'source'         => self::SOURCE_URL,
			),
			false // autoload=false, matches OPTION_KEY and keeps meta out of the autoload bucket
		);

		// Clear in-memory cache.
		$this->lookup    = null;
		$this->wildcards = null;
		// …and the cross-request memo of this tier's verdicts. The frontend
		// persists resolved name->category answers (misses included, stored as
		// '') in faz_server_cookie_definition_map for an hour so the 2.5 MB
		// dataset is not re-materialized on every render. That memo is derived
		// from the option just overwritten, so leaving it in place would let a
		// freshly downloaded database sit behind stale verdicts — including
		// negative ones for names it can now classify — until the TTL expired.
		// The catalogue-tier sibling faz_server_cookie_category_map is busted on
		// every write to ITS source; this is the same rule applied to this one.
		delete_transient( 'faz_server_cookie_definition_map' );

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

		// Answer from the cached bundled metadata when possible so admin
		// screens don't decode the 2.8 MB bundled JSON just for this check.
		$meta = $this->get_bundled_meta();
		return ! empty( $meta['count'] );
	}

	/**
	 * Get metadata about the stored definitions.
	 *
	 * @return array
	 */
	public function get_meta() {
		$stored = get_option( self::OPTION_KEY, false );
		// Report the dataset the lookup actually uses. Describing the stored
		// copy while get_runtime_data() answers from the bundle would put the
		// wrong date and the wrong source on the one screen an admin consults
		// to ask how old their definitions are.
		if ( is_array( $stored ) && ! empty( $stored ) && ! $this->bundle_supersedes_stored() ) {
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
		if ( is_array( $stored ) && ! empty( $stored ) && ! $this->bundle_supersedes_stored() ) {
			return $stored;
		}

		return $this->get_bundled_data();
	}

	/**
	 * Whether the bundled snapshot is newer than the downloaded copy.
	 *
	 * The stored option used to win unconditionally, and nothing ever revisited
	 * it — no version check, no clearing on upgrade. That split installs in two:
	 * a site that never pressed "Update definitions" got fresher data with every
	 * plugin update, because each release ships a newer bundle; a site that
	 * pressed it once was pinned to that instant forever, while newer bundles
	 * shipped past it unused. The button offered as the cure for stale data was
	 * the thing that made stale data permanent.
	 *
	 * Nothing is deleted. The verdict is taken at read time, so a later download
	 * of a genuinely newer dataset wins again on its own.
	 *
	 * @return bool
	 */
	private function bundle_supersedes_stored() {
		$meta       = get_option( self::META_KEY, array() );
		$downloaded = is_array( $meta ) && ! empty( $meta['updated_at'] ) ? (string) $meta['updated_at'] : '';

		// A stored copy with no date predates the versions that record one, so
		// it is older than any bundle we could be shipping today.
		if ( '' === $downloaded ) {
			return true;
		}

		// Prefer the UTC stamp when the metadata carries one: it needs no offset
		// applied, so no guess can be wrong. Only metadata written before that
		// field existed takes the legacy path below.
		$downloaded_gmt = is_array( $meta ) && ! empty( $meta['updated_at_gmt'] ) ? (string) $meta['updated_at_gmt'] : '';
		if ( '' !== $downloaded_gmt ) {
			$gmt_ts = strtotime( $downloaded_gmt . ' UTC' );
			if ( false !== $gmt_ts ) {
				return strtotime( self::BUNDLED_DATA_DATE . ' UTC' ) > $gmt_ts;
			}
		}

		$downloaded_ts = strtotime( $downloaded );
		if ( false === $downloaded_ts ) {
			return true;
		}

		// update_definitions() stamps with current_time( 'mysql' ), i.e. SITE
		// LOCAL time, while BUNDLED_DATA_DATE is UTC. Comparing them raw lets the
		// site's offset decide the winner: at UTC+13 a download would look 13
		// hours older than it is and lose to a bundle it actually postdates, and
		// at UTC-11 the reverse. I first wrote this off as "under a day and the
		// deltas are months", which is true of the common case and useless as an
		// argument — the whole point of the comparison is the close call.
		//
		// Normalise the stored stamp to UTC. The offset at write time is not
		// recorded, so the current one is the best available; being wrong by an
		// hour across a DST boundary is bounded, unlike being wrong by the whole
		// offset on every comparison.
		// LEGACY PATH: metadata without updated_at_gmt. The offset at write
		// time was never recorded, so the best available reconstruction is the
		// offset AT THE MOMENT THE DOWNLOAD WAS STAMPED, not today's. Using
		// the current one shifts a stamp written on the other side of a DST
		// change by an hour, which is enough to invert this comparison when the
		// two dates are close — and close is the only case that needs deciding.
		$offset        = function_exists( 'faz_site_utc_offset' )
			? faz_site_utc_offset( $downloaded_ts )
			: (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$downloaded_ts = $downloaded_ts - $offset;

		return strtotime( self::BUNDLED_DATA_DATE . ' UTC' ) > $downloaded_ts;
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
		$file = $this->get_bundled_file_path();
		if ( ! is_readable( $file ) ) {
			return array();
		}

		// The snapshot only changes on plugin updates, so cache its metadata
		// keyed by mtime+size instead of re-decoding 2.8 MB per admin screen.
		$mtime       = (int) filemtime( $file );
		$size        = (int) filesize( $file );
		// The declared capture date is part of the key, not just the file's
		// mtime and size. Without it a site that already cached this meta keeps
		// serving the value computed by the previous code — which is how the
		// old, filemtime-derived date survived this very change on a test site
		// whose file rsync had left untouched. Any edit to BUNDLED_DATA_DATE
		// now invalidates the cache by construction.
		$fingerprint = $mtime . ':' . $size . ':' . self::BUNDLED_DATA_DATE;
		$cached      = get_option( self::BUNDLED_META_KEY, false );
		if (
			is_array( $cached )
			&& isset( $cached['fingerprint'], $cached['meta'] )
			&& $fingerprint === $cached['fingerprint']
			&& is_array( $cached['meta'] )
		) {
			return $cached['meta'];
		}

		$data = $this->get_bundled_data();
		if ( empty( $data ) ) {
			return array();
		}

		$meta = array(
			'count'      => $this->count_definitions( $data ),
			// The capture date, not filemtime(): mtime is when the unzip wrote
			// the file on this server, so the admin screen was reporting the
			// install date of the update as the age of the data.
			'updated_at' => self::BUNDLED_DATA_DATE,
			'source'     => 'bundled',
		);
		update_option(
			self::BUNDLED_META_KEY,
			array(
				'fingerprint' => $fingerprint,
				'meta'        => $meta,
			),
			false
		);
		return $meta;
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
