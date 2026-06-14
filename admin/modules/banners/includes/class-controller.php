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

		// F103 fix: explicitly require InnoDB. delete_item() relies on
		// row-level locks via START TRANSACTION + SELECT … FOR UPDATE +
		// COMMIT (the F016 v2 fix). On MyISAM those statements parse
		// fine but are silent no-ops, leaving the original
		// check-then-act race in place. dbDelta on existing tables
		// would otherwise honour the server's `default_storage_engine`
		// (which can still be MyISAM on legacy hosts, customised AWS
		// RDS parameter groups, or sites that ran `ALTER TABLE … ENGINE
		// = MyISAM` for other reasons), so we lock the choice here.
		// dbDelta on existing tables that are NOT InnoDB does not
		// auto-migrate them — a separate migration in class-activator
		// runs `ALTER TABLE … ENGINE=InnoDB` to bring upgraded installs
		// up to spec.
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
		$banner->set_id( $id );
		$banner->set_slug( $banner->get_name() );
		$slug = $banner->get_slug() . '-' . $id; // Append ID to the slug of the each banner.
		$banner->set_slug( $slug );
		// Persist the freshly-generated slug + invariants.
		//
		// F006/F007 fix: $banner->save() routes through update_item()
		// because get_id() > 0 after the INSERT above. update_item()
		// itself runs clear_default_on_others() (for default=true rows)
		// AND fires both delete_cache() and do_action(
		// 'faz_after_update_banner' ) at its tail. Calling them again
		// here would double-invalidate every cache adapter.
		//
		// F108 fix: the save() call routes to update_item() which can
		// early-return at `if ( false === $updated ) { return; }` —
		// leaving the row with a raw `name`-only slug, no
		// clear_default_on_others() call, and no cache invalidation /
		// listener notification. That's a half-baked-create regression
		// vs. the pre-fix behaviour where the tail always fired. Detect
		// the early-return case here and run the cleanup tail
		// explicitly. We can't add a return value to save() without a
		// cascading API change, so check the saved-slug state in the
		// DB to detect whether update_item completed (the slug we
		// passed in includes `-{id}`; a pre-update-failure row has the
		// bare name in its slug column).
		//
		// We MUST NOT re-read $wpdb->insert_id after save(): downstream
		// option/transient writes inside the hook chain can pollute
		// $wpdb->insert_id with the AUTO_INCREMENT of an unrelated
		// table (wp_options on busy sites often runs in the millions —
		// pre-fix this surfaced as banner IDs like 2,513,570 in admin
		// redirect URLs).
		$banner->save();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$persisted_slug = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `slug` FROM `{$wpdb->prefix}faz_banners` WHERE `banner_id` = %d",
				$id
			)
		);
		if ( $persisted_slug !== $slug ) {
			// update_item early-returned (UPDATE failed or row vanished
			// between INSERT and UPDATE). The row exists with a stale
			// slug; rather than leave a half-baked state, run the
			// invariant tail unconditionally, then attempt the focused
			// slug UPDATE.
			//
			// R4-S002 fix (1.14.4): the prior shape gated the invariant
			// tail (clear_default_on_others + delete_cache + do_action)
			// on the focused slug UPDATE FAILING. That conflated two
			// orthogonal concerns — slug persistence and the
			// at-most-one-default invariant. If save() failed AND the
			// focused UPDATE happens to succeed, the invariant could
			// still be violated (multiple banner_default=1 rows from
			// the failed save's pre-state), AND the listener event +
			// cache flush would be skipped. Run the invariant tail
			// unconditionally so the recovery is complete regardless
			// of the slug-patch outcome.
			//
			// CodeRabbit#2 (1.14.4): the focused slug UPDATE still runs
			// — it keeps slug uniqueness on the row when the original
			// save() suffered an intermittent UPDATE failure on
			// otherwise-valid data. Just no longer gates the invariant
			// recovery.
			if ( true === $banner->get_default() ) {
				$this->clear_default_on_others( $id );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$wpdb->prefix . 'faz_banners',
				array( 'slug' => $slug ),
				array( 'banner_id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
			$this->delete_cache();
			do_action( 'faz_after_update_banner' );
		}
	}

	/**
	 * Update an existing banner locally.
	 *
	 * @param object $banner Banner object.
	 * @return void
	 */
	public function update_item( $banner ) {
		global $wpdb;
		// Capture the pre-update default state so we can detect the
		// "was default → now not" transition AFTER the UPDATE succeeds.
		// Without this, an admin un-toggling the flag on the only
		// banner_default=1 row leaves the DB with zero defaults, breaking
		// the picker's status_default fallback.
		$was_default = false;
		if ( $banner->get_id() > 0 ) {
			$existing    = new Banner( $banner->get_id() );
			$was_default = (bool) $existing->get_default();
		}
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
		// R4-S001 fix (1.14.4): wrap UPDATE + invariant branches in
		// a transaction so the FOR UPDATE lock that
		// promote_fallback_default acquires actually serializes
		// concurrent callers. Without this wrapper, update_item runs
		// in MySQL autocommit mode → each FOR UPDATE statement
		// releases its row lock at end-of-statement, BEFORE the
		// subsequent UPDATE runs. Mirrors the transaction wrapper
		// delete_item has carried since F112.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' );
			return;
		}
		// Default-flag invariants. Both branches run AFTER the current
		// row's UPDATE so a failed UPDATE never leaves the DB in a worse
		// state than before:
		//   - default=true  → clear peers (at-most-one invariant)
		//   - default=false but was_default=true → promote a peer to
		//     default (at-least-one invariant). Otherwise the install
		//     ends up with zero banner_default=1 rows and
		//     get_active_banner_for_country() loses its last-resort
		//     fallback for unmatched countries.
		//
		// R4-S003 fix: track whether an invariant branch fired so the
		// post-COMMIT delete_cache also flushes when peer rows changed
		// but the current row's UPDATE was a no-op ($updated === 0
		// from $wpdb->update means "identical data, 0 rows affected").
		$invariant_fired = false;
		if ( true === $banner->get_default() ) {
			$this->clear_default_on_others( $banner->get_id() );
			$invariant_fired = true;
		} elseif ( $was_default ) {
			$this->promote_fallback_default( $banner->get_id() );
			$invariant_fired = true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'COMMIT' );
		if ( $updated > 0 || $invariant_fired ) {
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
		$id = absint( $id );
		// F016 fix v2: serialize read+delete via an InnoDB transaction
		// (`SELECT ... FOR UPDATE` + DELETE + COMMIT). The first attempt
		// at this used `DELETE ... RETURNING banner_default` which is
		// MariaDB-only — MySQL never implemented RETURNING through 9.x —
		// so on stock MySQL the pre-fix race window was still wide open
		// via the fallback path. Transaction-based locking covers both
		// engines uniformly and works on every MySQL ≥ 5.6 (ClassicPress
		// floor) as long as the storage engine is InnoDB, which
		// install_tables() guarantees.
		//
		// The FOR UPDATE clause acquires a row-level X-lock that any
		// concurrent SELECT/DELETE on the same banner_id must wait on
		// until COMMIT. Two admin sessions deleting the same row
		// therefore see exactly one was_default=1; the loser's SELECT
		// either finds null (row already gone) and rolls back, or
		// returns the post-DELETE state (impossible — DELETE acquires
		// the same X-lock).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		$was_default_raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT `banner_default` FROM `{$wpdb->prefix}faz_banners` WHERE `banner_id` = %d FOR UPDATE",
				$id
			)
		);
		if ( null === $was_default_raw ) {
			// F101 fix: do NOT fire faz_after_update_banner on the
			// not-found return path. The DELETE was a no-op (no row to
			// purge cache for, no listener event to dispatch), so
			// triggering LSCache/Rocket purges and the epoch bump here
			// just wastes CPU on idempotent 404 retries from stale
			// admin tabs.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'COMMIT' );
			return 0;
		}
		$was_default = (int) $was_default_raw;
		$status      = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'faz_banners',
			array( 'banner_id' => $id ),
			array( '%d' )
		);
		if ( false === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		// F112 fix: promote_fallback_default() inside the SAME
		// transaction so the (read-other-defaults + UPDATE one-of-them)
		// pair is also row-locked. The pre-fix had `COMMIT` BEFORE the
		// fallback promotion, releasing the X-lock and re-introducing a
		// race window where two concurrent deletes of two different
		// default banners could each promote the same peer to default
		// (ending with two default rows). Keeping the work inside the
		// same transaction extends the lock scope to cover the read+
		// write of the promoted peer too.
		if ( $status > 0 && 1 === $was_default ) {
			$this->promote_fallback_default( $id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'COMMIT' );
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

		// F010 fix: classify against the raw stdClass rows produced by
		// prepare_item() instead of instantiating a Banner per iteration.
		// prepare_item() already decodes target_countries, normalises
		// status / banner_default, and exposes priority — every field this
		// classifier needs. The pre-fix `new Banner( $item->banner_id )`
		// per row turned a single get_active_banner_for_country() call
		// into N Controller round-trips + N full sanitize_settings
		// cascades (each re-resolving defaults against gdpr.json/ccpa.json).
		// On a 10-banner install that's 10 DB reads and ~50 ms of CPU per
		// front-end page load; this drops to one DB read (the cached
		// get_items()) and a single Banner instantiation for the winning
		// row at the bottom of the function.
		$status_match_ids    = array(); // status=1 + targets the country
		$status_anyland_ids  = array(); // status=1 + empty target list
		$status_targeted_ids = array(); // status=1 + non-empty targets (fallback)
		$status_default_id   = null;    // banner_default=1 fallback (any status)

		foreach ( $items as $item ) {
			$iid        = (int) $item->banner_id;
			$status     = 1 === (int) $item->status;
			$targets    = is_array( $item->target_countries ) ? $item->target_countries : array();
			$is_default = 1 === (int) $item->banner_default;
			$priority   = isset( $item->priority ) ? (int) $item->priority : 0;

			if ( $status ) {
				$entry = array( 'id' => $iid, 'priority' => $priority );
				if ( '' !== $country && in_array( $country, $targets, true ) ) {
					$status_match_ids[] = $entry;
				} elseif ( empty( $targets ) ) {
					$status_anyland_ids[] = $entry;
				} else {
					// status=1 with non-empty targets but doesn't match $country.
					// Kept as a last-resort fallback so legacy single-banner
					// callers of get_active_banner() (which passes '') still
					// receive a status=1 banner instead of false when no
					// match-all or banner_default row exists. Mirrors the
					// pre-1.14.0 behaviour of "first status=1 wins".
					$status_targeted_ids[] = $entry;
				}
			}

			if ( $is_default && null === $status_default_id ) {
				$status_default_id = $iid;
			}
		}

		$winner_id = null;
		if ( ! empty( $status_match_ids ) ) {
			$winner_id = self::pick_highest_priority_id( $status_match_ids );
		} elseif ( ! empty( $status_anyland_ids ) ) {
			$winner_id = self::pick_highest_priority_id( $status_anyland_ids );
		} elseif ( null !== $status_default_id ) {
			$winner_id = $status_default_id;
		} elseif ( ! empty( $status_targeted_ids ) ) {
			// Pre-1.14.0 contract preservation: third-party callers of
			// get_active_banner() (which passes country='') must still get
			// a status=1 banner back when one exists, even if its
			// target_countries doesn't match — otherwise an install with a
			// single status=1, country-targeted, non-default banner returns
			// false where pre-1.14.0 it returned that banner.
			$winner_id = self::pick_highest_priority_id( $status_targeted_ids );
		}

		if ( null === $winner_id ) {
			return false;
		}

		// Only NOW instantiate a Banner — once, for the winner. This is the
		// single point where prepare_item()'s shallow shape isn't enough
		// (the caller wants get_law() / get_settings() / sanitize_settings
		// guarantees etc.).
		$winner = new Banner( $winner_id );
		$winner->set_language( $current_lang );
		return $winner;
	}

	/**
	 * Pick the active banner whose applicable law matches, optionally honouring
	 * the visitor's country for tie-breaking.
	 *
	 * Used by the runtime geo-routing wiring (flag `faz_geo_ruleset_runtime`):
	 * once the resolved ruleset's MODEL maps to a binary law (gdpr/ccpa), the
	 * frontend prefers the active banner carrying that law so the UI matches the
	 * jurisdiction enforced. Restricts the same status / country / default tier
	 * logic as get_active_banner_for_country() to banners whose
	 * `settings.settings.applicableLaw` equals $law (defaulting to 'gdpr' when
	 * unset, mirroring Banner::get_law()). Returns false when no active banner
	 * carries that law, so the caller keeps the country-selected banner.
	 *
	 * @since 1.17.2
	 * @param string $law     'gdpr' or 'ccpa'.
	 * @param string $country Visitor's ISO-3166 alpha-2 country code, or ''.
	 * @return Banner|false
	 */
	public function get_active_banner_for_law( $law, $country = '' ) {
		$law          = ( 'ccpa' === $law ) ? 'ccpa' : 'gdpr';
		$items        = $this->get_items();
		$current_lang = faz_current_language();
		if ( empty( $items ) || ! is_array( $items ) ) {
			return false;
		}

		$country = is_string( $country ) ? strtoupper( trim( $country ) ) : '';
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = '';
		}

		$match_ids   = array(); // law match + status=1 + targets the country
		$anyland_ids = array(); // law match + status=1 + empty target list
		$default_id  = null;    // law match + status=1 + banner_default=1

		foreach ( $items as $item ) {
			$inner = ( isset( $item->settings['settings'] ) && is_array( $item->settings['settings'] ) )
				? $item->settings['settings'] : array();
			$item_law = isset( $inner['applicableLaw'] ) && 'ccpa' === $inner['applicableLaw'] ? 'ccpa' : 'gdpr';
			if ( $item_law !== $law ) {
				continue;
			}

			$iid      = (int) $item->banner_id;
			$status   = 1 === (int) $item->status;
			$targets  = is_array( $item->target_countries ) ? $item->target_countries : array();
			$priority = isset( $item->priority ) ? (int) $item->priority : 0;

			// This selector is an ENABLEMENT preference, not a last-resort
			// fallback like get_active_banner_for_country(): only ACTIVE
			// (status=1) banners qualify, including the banner_default row.
			// Returning a disabled banner here would override the
			// country-selected active banner with one the admin turned off.
			if ( ! $status ) {
				continue;
			}

			$entry = array( 'id' => $iid, 'priority' => $priority );
			if ( '' !== $country && in_array( $country, $targets, true ) ) {
				$match_ids[] = $entry;
			} elseif ( empty( $targets ) ) {
				$anyland_ids[] = $entry;
			}
			// The default-banner fallback must be GLOBALLY applicable (empty
			// target list). A banner_default row that is country-restricted is
			// already captured in $match_ids when it targets the visitor's
			// country (which outranks $default_id); using it as the law-global
			// fallback for a visitor it does NOT target would serve a banner the
			// admin scoped to other countries. Requiring empty targets here makes
			// the method return false instead — callers then fail closed.
			if ( 1 === (int) $item->banner_default && empty( $targets ) && null === $default_id ) {
				$default_id = $iid;
			}
		}

		$winner_id = null;
		if ( ! empty( $match_ids ) ) {
			$winner_id = self::pick_highest_priority_id( $match_ids );
		} elseif ( ! empty( $anyland_ids ) ) {
			$winner_id = self::pick_highest_priority_id( $anyland_ids );
		} elseif ( null !== $default_id ) {
			$winner_id = $default_id;
		}

		if ( null === $winner_id ) {
			return false;
		}

		$winner = new Banner( $winner_id );
		$winner->set_language( $current_lang );
		return $winner;
	}

	/**
	 * Variant of pick_highest_priority() that operates on raw
	 * {id, priority} pairs instead of Banner instances. Lets the caller
	 * defer Banner instantiation until after the winner is known —
	 * critical for get_active_banner_for_country() which previously paid
	 * an N+1 Banner construction cost per page load.
	 *
	 * @since 1.14.2
	 * @param array<int, array{id:int, priority:int}> $entries
	 * @return int
	 */
	private static function pick_highest_priority_id( $entries ) {
		usort( $entries, function ( $a, $b ) {
			if ( $a['priority'] !== $b['priority'] ) {
				return $b['priority'] <=> $a['priority']; // desc
			}
			return $a['id'] <=> $b['id']; // asc tie-break
		} );
		return (int) $entries[0]['id'];
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
		// Memoize via wp_cache + epoch (issue #109). The previous transient
		// approach lost correctness on multi-node Redis without replication:
		// delete_transient only invalidated the local node, so other nodes
		// served stale geo-routing decisions until the 5-min TTL expired.
		// Replacement strategy — "cache aside with versioned key":
		//   1. The cache key embeds an epoch read from an OPTION
		//      (`faz_banner_cache_epoch`). Options sit in WP's central
		//      `alloptions` cache which IS replicated/invalidated across
		//      nodes by every object-cache backend that supports
		//      wp_cache_set_multiple (WP 6.0+) AND by every backend that
		//      hooks the `updated_option` action.
		//   2. delete_cache() bumps the epoch via update_option. All nodes
		//      immediately read the new epoch on the next request → the
		//      OLD cache key is never queried again. The stale entry
		//      under the OLD key expires harmlessly via TTL.
		// ClassicPress 1.x: wp_cache_get/set + get_option/update_option are
		// pre-WP 2.x — no compat shim needed.
		// F104 v2 follow-up: read epoch as STRING (delete_cache() now
		// stores microtime as string, not int). Cast-to-string is safe
		// for both legacy int values (stored by pre-fix builds) and the
		// new microtime form — the value only ever appears as a cache-
		// key suffix, never in arithmetic. Pre-existing int rows on
		// upgraded installs survive the change because PHP coerces them
		// to string at concatenation time anyway.
		$epoch     = (string) get_option( 'faz_banner_cache_epoch', '0' );
		$cache_key = 'faz_has_country_dependent_banners_v' . $epoch;
		$cached    = wp_cache_get( $cache_key, 'faz_banners' );
		if ( false !== $cached ) {
			return (bool) $cached;
		}
		$items = $this->get_items();
		if ( empty( $items ) || ! is_array( $items ) ) {
			wp_cache_set( $cache_key, 0, 'faz_banners', 5 * MINUTE_IN_SECONDS );
			return false;
		}
		foreach ( $items as $item ) {
			$banner = new Banner( $item->banner_id );
			if ( ! $banner->get_status() ) {
				continue;
			}
			if ( ! empty( $banner->get_target_countries() ) ) {
				wp_cache_set( $cache_key, 1, 'faz_banners', 5 * MINUTE_IN_SECONDS );
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
					wp_cache_set( $cache_key, 1, 'faz_banners', 5 * MINUTE_IN_SECONDS );
					return true;
				}
			}
		}
		wp_cache_set( $cache_key, 0, 'faz_banners', 5 * MINUTE_IN_SECONDS );
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
		// Bump the cache epoch (issue #109). The has_country_dependent_banners
		// memoization key embeds this epoch, so a bump effectively
		// invalidates every node's cache simultaneously — the OLD key is
		// never queried again. Replaces the previous delete_transient
		// call which only invalidated the local Redis/Memcached node.
		//
		// F015 fix (v2 — F104 follow-up): use the microtime STRING (not
		// a cast-to-int) so the value survives 32-bit PHP. ClassicPress
		// 1.x still supports 32-bit PHP where PHP_INT_MAX is 2,147,483,647
		// (≈ 2.1×10⁹). `(int)(microtime(true)*1000)` ≈ 1.75×10¹² today
		// would silently overflow → every subsequent epoch would collapse
		// to the same overflowed integer and cache invalidation would
		// stop working. The cache-key embedding code casts to (string)
		// regardless, so storing the raw microtime string costs nothing.
		//
		// Concurrency: sprintf with %.6F forces 6-decimal microsecond
		// resolution. A bare (string) cast follows PHP's default
		// precision=14 ini, which on float values near 1.75e9 actually
		// emits only ~4 decimal digits (~100μs resolution). Two
		// concurrent admin saves landing within ~100μs would collapse
		// to the same epoch string and cache invalidation would
		// silently fail. %.6F is locale-independent and always emits
		// exactly 6 decimals, restoring true sub-millisecond
		// uniqueness without depending on the host's precision ini.
		// (F305 fix, 1.14.4 — supersedes the F104/F015 implementation.)
		$next = sprintf( '%.6F', microtime( true ) );
		// F107 fix: autoload=false (was true in the F011 fix). The
		// option is written on every banner create/update/delete —
		// every hot-write would force a full wp_load_alloptions()
		// rebuild on every node if autoloaded. Cross-node propagation
		// is achieved via the updated_option action, which fires
		// regardless of autoload (third-party cache plugins listen to
		// it). Trading lazy single-DB-hit-per-request reads for
		// every-save alloptions thrash is net-negative.
		update_option( 'faz_banner_cache_epoch', $next, false );
		// Sweep the legacy transient written by pre-fix 1.14.0 builds.
		// One-shot cleanup; the call is cheap and idempotent.
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
		// R4-S003 fix (1.14.4): cache invalidation is the CALLER's
		// responsibility — mirrors promote_fallback_default's contract
		// since F301. The pre-fix internal delete_cache() call ran
		// PRE-COMMIT when this method executes inside update_item's
		// transaction wrapper (R4-S001), opening the same cache-
		// poisoning race F301 closed for delete_item. All current
		// callers (create_item slug-probe, update_item) invoke
		// delete_cache after the surrounding transaction commits.
	}

	/**
	 * Promote a peer banner to banner_default=1 when the caller is about
	 * to leave the install with zero default rows.
	 *
	 * Enforces the at-least-one-default invariant the multi-banner picker
	 * relies on: get_active_banner_for_country() falls back to
	 * banner_default=1 when no targeted / match-all banner matches the
	 * visitor's country, and a zero-default install loses that path.
	 *
	 * Selection preference (deterministic):
	 *   1. status=1 AND banner_default=0 row with the lowest banner_id
	 *      (other than $exclude_id) — the cleanest promotion target
	 *   2. any row with the lowest banner_id (other than $exclude_id)
	 *
	 * No-op when no other row exists — the caller's row is the only one
	 * left and removing its default flag is a legitimate "I want zero
	 * banners on this install" state.
	 *
	 * Concurrency contract: MUST run inside the caller's transaction.
	 * Both SELECTs use FOR UPDATE so the row we promote is row-locked
	 * under the caller's lock scope; cache invalidation is intentionally
	 * NOT performed here — callers (update_item / delete_item) flush the
	 * cache AFTER their own COMMIT so a concurrent read doesn't
	 * repopulate it with the pre-commit snapshot.
	 *
	 * @since 1.14.0
	 * @param int $exclude_id Row that just lost its default flag; never re-promoted to itself.
	 * @return void
	 */
	public function promote_fallback_default( $exclude_id = 0 ) {
		global $wpdb;
		$exclude_id = absint( $exclude_id );
		// F302 / CodeRabbit#1: prefer an ACTIVE, NON-DEFAULT peer first
		// (typical case after delete_item removes the row that was the
		// only default). Both SELECTs use FOR UPDATE so the chosen row
		// is row-locked under the caller's transaction — REPEATABLE
		// READ alone gives a consistent snapshot but no row lock, so
		// two concurrent delete_item() transactions could both pick
		// the same peer and one of the UPDATEs would lose. With
		// FOR UPDATE the second SELECT blocks until the first
		// transaction commits/rolls back.
		$fallback_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT banner_id FROM `{$wpdb->prefix}faz_banners` WHERE `status` = %d AND `banner_default` = %d AND `banner_id` <> %d ORDER BY `banner_id` ASC LIMIT %d FOR UPDATE",
				1,
				0,
				$exclude_id,
				1
			)
		);
		if ( $fallback_id <= 0 ) {
			$fallback_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT banner_id FROM `{$wpdb->prefix}faz_banners` WHERE `banner_id` <> %d ORDER BY `banner_id` ASC LIMIT %d FOR UPDATE",
					$exclude_id,
					1
				)
			);
		}
		if ( $fallback_id > 0 ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'faz_banners',
				array( 'banner_default' => 1 ),
				array( 'banner_id' => $fallback_id ),
				array( '%d' ),
				array( '%d' )
			);
			// F301: NO delete_cache() here — caller invokes it after COMMIT.
		}
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
