<?php
/**
 * Class Controller file.
 *
 * Local DB-backed consent log controller.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Consentlogs\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Consent Log Operations using local WordPress database.
 *
 * @class       Controller
 * @version     3.0.0
 * @package     FazCookie
 */
class Controller {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Table name (without prefix)
	 *
	 * @var string
	 */
	private $table_name = 'faz_consent_logs';

	/**
	 * DB version option key
	 *
	 * @var string
	 */
	private $db_version = '1.2';

	/**
	 * The ruleset every page enforces while the jurisdiction bootstrap owns
	 * normal pages: the one Frontend::get_geo_bootstrap_strict_ruleset()
	 * loads for the cached shell.
	 *
	 * @since 1.34.2
	 * @var string
	 */
	const GEO_BOOTSTRAP_STRICT_RULESET = 'fallback-gdpr-most-protective';

	/**
	 * Return the current instance of the class
	 *
	 * @return Controller
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - ensure table exists.
	 *
	 * Deferred to plugins_loaded so wp_salt() (pluggable.php) is guaranteed
	 * to be available before the migration query runs. Running it synchronously
	 * in the constructor crashes WordPress Playground where the loading order
	 * differs from a standard installation.
	 */
	private function __construct() {
		if ( did_action( 'plugins_loaded' ) ) {
			$this->maybe_create_table();
		} else {
			add_action( 'plugins_loaded', array( $this, 'maybe_create_table' ), 20 );
		}
	}

	/**
	 * Get the full table name with WP prefix.
	 *
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . $this->table_name;
	}

	/**
	 * Create the consent logs table if it does not exist.
	 *
	 * @return void
	 */
	public function maybe_create_table() {
		$installed_version = get_option( 'faz_consent_logs_db_version', '0' );
		if ( version_compare( $installed_version, $this->db_version, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			log_id bigint(20) NOT NULL AUTO_INCREMENT,
			consent_id varchar(190) NOT NULL,
			status varchar(20) NOT NULL,
			categories longtext,
			ip_hash varchar(64) DEFAULT '',
			user_agent text,
			url varchar(500) DEFAULT '',
			banner_slug varchar(190) DEFAULT '',
			policy_revision bigint(20) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			created_at_gmt datetime DEFAULT NULL,
			PRIMARY KEY  (log_id),
			KEY idx_consent_id (consent_id),
			KEY idx_status (status),
			KEY idx_created_at (created_at),
			KEY idx_banner_slug (banner_slug)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		// dbDelta() creates the table with created_at_gmt, or adds the column
		// when it is missing, so from here on inserts may write it (see
		// Activator::ensure_consent_log_gmt_column() for the upgrade path).
		update_option( 'faz_consent_log_gmt_column_added', 1, false );

		// Migrate legacy plaintext user_agent values to hashed form.
		$migration_ok = true;
		if ( version_compare( $installed_version, '1.1', '<' ) ) {
			if ( function_exists( 'wp_salt' ) ) {
				$migration_ok = $this->hash_legacy_user_agents();
			}
			// If wp_salt() is not yet available, skip migration silently.
			// Fresh installs have no rows to migrate; upgrades will retry next request.
		}

		if ( $migration_ok ) {
			update_option( 'faz_consent_logs_db_version', $this->db_version );
		}
	}

	/**
	 * Hash any legacy plaintext user_agent rows in PHP.
	 *
	 * The previous implementation used MySQL's SHA2()/REGEXP in a single UPDATE,
	 * which fatals on SQLite-backed WordPress (no SHA2 function) and left the
	 * migration to retry — and emit a database error — on every request. We hash
	 * in PHP instead, with the exact same algorithm as hash_user_agent()
	 * (hash('sha256', $ua . wp_salt('auth')) === LOWER(SHA2(CONCAT(ua, salt), 256))),
	 * so the result is identical and the path is portable across MySQL and SQLite.
	 * A log_id cursor guarantees forward progress and loop termination regardless
	 * of row contents.
	 *
	 * @return bool True on success (or nothing to migrate), false on a query error.
	 */
	private function hash_legacy_user_agents() {
		global $wpdb;
		$table_name = $this->get_table_name();
		$batch_size = 500;
		$last_id    = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-shot upgrade-path migration; $table_name is $wpdb->prefix + literal "faz_consent_logs"; bounds are bound via prepare(%d).
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT log_id, user_agent FROM {$table_name}
					 WHERE log_id > %d
					 ORDER BY log_id ASC
					 LIMIT %d",
					$last_id,
					$batch_size
				),
				ARRAY_A
			);

			if ( null === $rows ) {
				return false;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row['log_id'];
				$ua      = (string) $row['user_agent'];
				// Skip empties and already-hashed values (64-char lowercase hex).
				if ( '' === $ua || preg_match( '/^[0-9a-f]{64}$/', $ua ) ) {
					continue;
				}
				$updated = $wpdb->update(
					$table_name,
					array( 'user_agent' => $this->hash_user_agent( $ua ) ),
					array( 'log_id' => (int) $row['log_id'] ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					return false;
				}
			}
		} while ( count( $rows ) === $batch_size );

		return true;
	}

	/**
	 * Pseudonymise an IP address before it is stored.
	 *
	 * A salted one-way hash, keyed by the installation's salt. It is
	 * pseudonymisation in the sense of GDPR Art. 4(5), not anonymisation: the
	 * same address always yields the same value for the life of the salt, so
	 * rows from one address stay linkable for the retention period, and
	 * anyone holding wp-config's salts and a candidate address can confirm a
	 * match. The row is therefore still personal data, kept as the server-side
	 * witness of where a consent came from and purged with the row. The
	 * wording shown to administrators (the eraser message, the readme) says
	 * exactly this, and no more.
	 *
	 * @param string $ip The IP address.
	 * @return string SHA256 hash of IP + salt.
	 */
	private function hash_ip( $ip ) {
		if ( empty( $ip ) ) {
			return '';
		}
		return hash( 'sha256', $ip . \wp_salt() );
	}

	/**
	 * Hash the user agent so the raw fingerprint is not stored in the database.
	 *
	 * @param string $user_agent Raw user agent string.
	 * @return string
	 */
	private function hash_user_agent( $user_agent ) {
		if ( empty( $user_agent ) ) {
			return '';
		}

		return hash( 'sha256', $user_agent . \wp_salt( 'auth' ) );
	}

	/**
	 * Reduce a consent-log URL to the page it identifies.
	 *
	 * Delegates to faz_normalize_page_url(), the one rule the placeholder
	 * inventory also keys on: credentials, fragments and every non-routing
	 * parameter (campaign, token, reset key, email) are dropped, while the
	 * WordPress routing parameters survive. Dropping the whole query — what
	 * this did before — recorded every plain-permalink and ?lang= page as the
	 * home page, so the row could not say which page consent was given on.
	 * The inline body below is the fallback for a load order in which the
	 * utility is not yet defined; it keeps the old, stricter behaviour.
	 *
	 * @param string $url URL to sanitize.
	 * @return string
	 */
	private function sanitize_log_url( $url ) {
		if ( function_exists( 'faz_normalize_page_url' ) ) {
			return faz_normalize_page_url( $url );
		}
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts || ! is_array( $parts ) ) {
			return '';
		}

		$sanitized = '';
		if ( ! empty( $parts['scheme'] ) ) {
			$sanitized .= $parts['scheme'] . '://';
		}
		// Deliberately omit user:pass — never persist credentials in logs.
		if ( ! empty( $parts['host'] ) ) {
			$sanitized .= $parts['host'];
		}
		if ( ! empty( $parts['port'] ) ) {
			$sanitized .= ':' . absint( $parts['port'] );
		}
		if ( ! empty( $parts['path'] ) ) {
			$sanitized .= $parts['path'];
		}

		return $sanitized;
	}

	/**
	 * Generate a unique consent ID.
	 *
	 * @return string Base64-encoded random string.
	 */
	private function generate_consent_id() {
		return base64_encode( wp_generate_password( 24, false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Get the visitor's IP address.
	 *
	 * @return string
	 */
	private function get_visitor_ip() {
		return faz_resolve_client_ip();
	}

	/**
	 * Log a consent record.
	 *
	 * @param array $data Consent data with keys: consent_id, status, categories, url.
	 * @return array|false The inserted record data or false on failure.
	 */
	public function log_consent( $data ) {
		global $wpdb;

		$consent_id = ! empty( $data['consent_id'] ) ? sanitize_text_field( $data['consent_id'] ) : $this->generate_consent_id();
		$status     = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'partial';
		// Constrain status to the known set. The REST `status` param has no
		// validate_callback, so an unauthenticated POST could otherwise write an
		// arbitrary string that pollutes the dashboard's GROUP BY statistics
		// (total != accepted + rejected + partial). Unknown values fold to
		// 'partial'; the internal-only dnsmpi_optout / dns_rescinded / pmp_grant
		// stay valid because the internal callers pass them verbatim —
		// 'pmp_grant' in particular exists so a membership-based programmatic
		// grant (Paid_Memberships_Pro::log_pmp_grant) is never conflated with a
		// consent the visitor gave through the banner; folding it to 'partial'
		// was exactly that conflation.
		if ( ! in_array( $status, array( 'accepted', 'rejected', 'partial', 'dnsmpi_optout', 'dns_rescinded', 'pmp_grant' ), true ) ) {
			$status = 'partial';
		}
		$categories = isset( $data['categories'] ) ? $data['categories'] : array();
		$url        = isset( $data['url'] ) ? $this->sanitize_log_url( $data['url'] ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $this->hash_user_agent( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		$ip_hash    = $this->hash_ip( $this->get_visitor_ip() );
		$banner_slug = isset( $data['banner_slug'] ) ? sanitize_title( $data['banner_slug'] ) : '';
		$policy_revision = isset( $data['policy_revision'] ) ? max( 1, absint( $data['policy_revision'] ) ) : 1;

		// Sanitize the category/service decision map before persisting. The
		// REST `categories` param has no per-key sanitize_callback and the
		// frontend now folds svc.*/ck.* keys read from the (client-writable)
		// consent cookie into it, so a crafted cookie could otherwise push
		// arbitrary keys/values into the consent-log row. Cap the entry count,
		// length-bound every key, and constrain values so the stored audit map
		// stays well-formed regardless of what the client sends.
		//
		// The cap is on the STORED map: 250 entries in total, of which the
		// server reserves Gpc_Exception_Audit::MAX_IDS for the verdict keys it
		// appends below. Capping the client's map at 250 and appending after it
		// let a row reach 250 + the number of exceptions.
		if ( is_array( $categories ) || is_object( $categories ) ) {
			$clean      = array();
			$count      = 0;
			$client_cap = 250 - ( class_exists( '\\FazCookie\\Includes\\Gpc_Exception_Audit' ) ? \FazCookie\Includes\Gpc_Exception_Audit::MAX_IDS : 50 );
			foreach ( (array) $categories as $key => $value ) {
				if ( $count >= $client_cap ) {
					break;
				}
				$key = substr( sanitize_text_field( (string) $key ), 0, 190 );
				// Only yes/no scalars are valid. Skip nested arrays/objects before
				// the string cast so a crafted payload can't trigger an
				// array-to-string warning (or an object __toString fatal).
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$value = sanitize_text_field( (string) $value );
				if ( '' === $key || ! in_array( $value, array( 'yes', 'no' ), true ) ) {
					continue;
				}
				$clean[ $key ] = $value;
				++$count;
			}

			// A GPC exception in this map is the client's claim that the visitor
			// clicked Accept on a blocked embed. The cookie cannot prove it —
			// a page script can write the same pairs (#285) — so the server adds
			// its own verdict: whether the circumstances it would have been
			// minted in actually held. The client cannot write these keys; the
			// audit strips them first.
			//
			// The earlier row it is judged against is fetched only when this
			// map carries a client exception at all — one scan, the same prefix
			// decide() collects on — so an ordinary consent post costs no extra
			// query. Use the immediately preceding row: a decision without the
			// exception is a boundary (including withdrawal), not a row to skip.
			// Look up the id as stored, not as posted.
			if ( class_exists( '\\FazCookie\\Includes\\Gpc_Exception_Audit' ) ) {
				$has_exception = false;
				foreach ( array_keys( $clean ) as $clean_key ) {
					if ( 0 === strpos( (string) $clean_key, 'meta.gpc_exception.' ) ) {
						$has_exception = true;
						break;
					}
				}
				$previous = array();
				if ( $has_exception && ! empty( $data['consent_id'] ) ) {
					$previous = (array) $this->get_previous_consent_row( $consent_id );
				}
				$clean = \FazCookie\Includes\Gpc_Exception_Audit::decide(
					$clean,
					$data,
					function_exists( 'faz_get_valid_consent_cookie' ) ? (string) faz_get_valid_consent_cookie() : '',
					$previous
				);
			}

			$categories = wp_json_encode( $clean );
		} else {
			// A scalar value (e.g. a DNSMPI opt-out passes '' and the audit /
			// export contract expects '' for that column — NOT the '[]' that
			// wp_json_encode( array() ) would produce). Keep it as a sanitized
			// string, length-bounded so a crafted REST payload (the param has no
			// validate_callback) cannot write an unbounded blob into the log.
			$categories = substr( sanitize_text_field( (string) $categories ), 0, 1000 );
		}

		// L2-SP1-S003 fix (1.15.0): populate the 7 geo-routing v2
		// audit columns added by Migration_V2. Resolves Constitution V
		// (Auditable Records) gap — until this commit the columns were
		// added schema-side but never written, leaving NULL on every row.
		//
		// Data source priority:
		//   1. $data['visitor_context'] explicitly passed by a caller that
		//      already resolved the context it enforced.
		//   2. The enforcement chain the frontend itself used for this
		//      visitor — Geolocation::get_visitor_country() and
		//      Geo_Runtime::resolve_for_country() — see
		//      runtime_visitor_context() for why it is that chain and not
		//      the bare detector.
		//   3. NULL columns (legacy fallback).
		$geo_fields = $this->resolve_geo_audit_fields( $data );

		$insert_data = array(
			'consent_id'      => $consent_id,
			'status'          => $status,
			'categories'      => $categories,
			'ip_hash'         => $ip_hash,
			'user_agent'      => $user_agent,
			'url'             => $url,
			'banner_slug'     => $banner_slug,
			'policy_revision' => $policy_revision,
			'created_at'      => current_time( 'mysql' ),
		);
		$insert_format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );
		// The same instant in UTC (1.34.2). Written only once the column is
		// known to exist (Activator::ensure_consent_log_gmt_column() sets the
		// flag), so an install still waiting for its migration keeps inserting.
		if ( get_option( 'faz_consent_log_gmt_column_added' ) ) {
			$insert_data['created_at_gmt'] = current_time( 'mysql', true );
			$insert_format[]               = '%s';
		}

		// Append v2 columns only when geo-routing v2 has resolved data
		// AND the migration completed successfully on this install. Two
		// guards (both must be false for v2 columns to be appended):
		//   - faz_geo_v2_disabled_reason: set when admin explicitly disabled
		//     v2 (MySQL too old, kill switch, etc.) — skip permanently.
		//   - faz_geo_v2_migration_pending: set when Migration_V2::run()
		//     finished with status 'partial' (some columns added, others
		//     failed mid-ALTER). Appending v2 keys here would hit the
		//     unknown-column branch on the missing columns; better to fall
		//     back to a v1-shape insert until the next activator pass
		//     retries the migration.
		if (
			is_array( $geo_fields )
			&& ! empty( $geo_fields )
			&& '' === (string) get_option( 'faz_geo_v2_disabled_reason', '' )
			&& ! get_option( 'faz_geo_v2_migration_pending', false )
		) {
			foreach ( $geo_fields as $col => $val ) {
				$insert_data[ $col ]   = $val;
				$insert_format[]        = is_int( $val ) ? '%d' : '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- consent log writes by design must not be cached; every visitor consent action produces a fresh row.
		$result = $wpdb->insert(
			$this->get_table_name(),
			$insert_data,
			$insert_format
		);

		if ( false === $result ) {
			if ( ! empty( $wpdb->last_error ) ) {
				error_log( 'FAZ consent log insert failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return false;
		}

		return array(
			'log_id'     => $wpdb->insert_id,
			'consent_id' => $consent_id,
			'status'     => $status,
			'banner_slug' => $banner_slug,
			'policy_revision' => $policy_revision,
			'created_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Resolve the 7 geo-routing v2 audit fields for `log_consent()`.
	 *
	 * Spec: 001-geo-routing-next FR-07, L2-SP1-S003 fix.
	 *
	 * Returns columns the caller can pass-through to $wpdb->insert:
	 *   country_at_consent, region_at_consent, ruleset_id_at_consent,
	 *   signal_gpc_received, signal_dnt_received, tc_string, gpp_string
	 *
	 * Defensive: never throws. If geo-routing v2 module is absent,
	 * returns an empty array → caller skips the v2 columns entirely.
	 *
	 * @since 1.15.0
	 * @param array $data Caller payload (may contain 'visitor_context', and
	 *                    'geo_bootstrap' on the browser path — see
	 *                    runtime_visitor_context()).
	 * @return array Sparse column→value map.
	 */
	protected function resolve_geo_audit_fields( $data ) {
		$ctx = isset( $data['visitor_context'] ) && is_array( $data['visitor_context'] ) ? $data['visitor_context'] : null;

		if ( null === $ctx ) {
			// The jurisdiction-bootstrap verdict, when the caller is the one
			// path that runs the bootstrap (the inline beacon through the
			// consent endpoint, which always sends the key). null means the
			// caller never renders the strict shell: AMP, Do-Not-Sell, CLI.
			$verdict = null;
			if ( array_key_exists( 'geo_bootstrap', $data ) ) {
				$verdict = is_string( $data['geo_bootstrap'] ) ? $data['geo_bootstrap'] : '';
				if ( ! in_array( $verdict, array( 'live', 'strict-fallback' ), true ) ) {
					$verdict = '';
				}
			}
			$ctx = $this->runtime_visitor_context( $verdict );
		}

		if ( ! is_array( $ctx ) ) {
			// No jurisdiction context at all (the geo classes are absent): the
			// signal and TCF/GPP columns below still have a source, the request.
			$ctx = array();
		}

		$out = array();
		// 'XX' is the detector's "unknown" sentinel, not a country: the data
		// model (specs/001-geo-routing-next/data-model.md) keeps an unresolved
		// country NULL, and a row claiming a country of XX would read as a
		// value to anyone correlating the export.
		if ( isset( $ctx['country'] ) && is_string( $ctx['country'] ) && preg_match( '/^[A-Z]{2}$/', $ctx['country'] ) && 'XX' !== $ctx['country'] ) {
			$out['country_at_consent'] = $ctx['country'];
		}
		if ( isset( $ctx['region'] ) && is_string( $ctx['region'] ) && preg_match( '/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $ctx['region'] ) ) {
			$out['region_at_consent'] = $ctx['region'];
		}
		if ( isset( $ctx['ruleset_id'] ) && is_string( $ctx['ruleset_id'] ) && preg_match( '/^[a-z][a-z0-9-]*[a-z0-9]$/', $ctx['ruleset_id'] ) ) {
			$out['ruleset_id_at_consent'] = $ctx['ruleset_id'];
		}
		if ( isset( $ctx['signals']['gpc'] ) ) {
			$out['signal_gpc_received'] = ! empty( $ctx['signals']['gpc'] ) ? 1 : 0;
		} elseif ( isset( $data['signal_gpc'] ) ) {
			$out['signal_gpc_received'] = ! empty( $data['signal_gpc'] ) ? 1 : 0;
		}
		if ( isset( $ctx['signals']['dnt'] ) ) {
			$out['signal_dnt_received'] = ! empty( $ctx['signals']['dnt'] ) ? 1 : 0;
		} elseif ( isset( $data['signal_dnt'] ) ) {
			$out['signal_dnt_received'] = ! empty( $data['signal_dnt'] ) ? 1 : 0;
		}
		// IAB TC + GPP strings: stored verbatim for audit, but pass through
		// sanitize_text_field() to strip control chars / extra whitespace
		// that can't legally appear in a TCF/GPP container string. The
		// regex shape is intentionally not enforced — TCF / GPP have
		// evolving versions; we keep the schema permissive and validate
		// at consumer time. Length is bounded by the TEXT column type
		// (65 KB) which is far above any real-world payload (TCF strings
		// are typically < 1 KB).
		if ( isset( $data['tc_string'] ) && is_string( $data['tc_string'] ) ) {
			$tc = sanitize_text_field( wp_unslash( $data['tc_string'] ) );
			if ( '' !== $tc ) {
				$out['tc_string'] = $tc;
			}
		}
		// The GPP string is NOT produced by this plugin: it is read from
		// whatever CMP runs on the page and lands in the accountability
		// record as given. Unlike the TCF string, which this plugin's own CMP
		// writes, it is therefore kept only when it has the shape of a GPP
		// string -- base64url segments joined by `~`, with `.` between the
		// sub-sections of a section -- and a bounded length, so free text, a
		// URL or markup posted in its place is not stored as evidence.
		if ( isset( $data['gpp_string'] ) && is_string( $data['gpp_string'] ) ) {
			$gpp = sanitize_text_field( wp_unslash( $data['gpp_string'] ) );
			if ( '' !== $gpp && strlen( $gpp ) <= 4096 && preg_match( '/^[A-Za-z0-9_-]+(?:[~.][A-Za-z0-9_-]*)*$/', $gpp ) ) {
				$out['gpp_string'] = $gpp;
			}
		}
		return $out;
	}

	/**
	 * The jurisdiction context the frontend ENFORCED for this visitor.
	 *
	 * Resolved through the same chain the frontend uses to pick the banner,
	 * the consent defaults, the blocking decision and the GCM defaults:
	 * Geolocation::get_visitor_country() (which honours `faz_visitor_country`
	 * and the proxy-trust settings), Geolocation::get_visitor_region() and
	 * Geo_Runtime::resolve_for_country(), which hands that country to the
	 * routing module as an authoritative override precisely so that nothing
	 * downstream can disagree on it.
	 *
	 * This method used to call the bare detector instead
	 * (Geo_Routing::get_visitor_context() with no override). The two chains
	 * agree on the default topologies — a local MMDB, or a trusted Cloudflare
	 * edge — and disagree on every opt-in one: a country supplied by the
	 * `faz_visitor_country` callback (which the Geolocation screen reports as
	 * a working source) never reached the detector, so a US-CA visitor who
	 * was served ccpa-california was logged as 'XX' under
	 * 'fallback-gdpr-most-protective'; the admin override reached only the
	 * log; a legacy `faz_trust_proxy_headers` site resolved the proxy's own
	 * address here and the visitor's there. Under Cache Compatibility Mode the
	 * frontend applies no server-side ruleset at all (every per-visitor render
	 * path returns null), so the row claims none either.
	 *
	 * The jurisdiction bootstrap (Settings → Geolocation → cache-safe
	 * bootstrap) is the one topology where the chain above is not enough.
	 * While Frontend::get_geo_bootstrap_status() reports the gate active,
	 * every normal page is rendered as the strict most-protective GDPR shell
	 * (Frontend::get_runtime_ruleset() returns that fallback and never
	 * resolves the country); script.js then asks the banner REST for the live
	 * law, which resolves it through the very chain used here, and applies
	 * it. So the visitor was enforced under EITHER the per-country ruleset
	 * (the live payload landed) OR the strict shell (the lookup timed out,
	 * failed, or had no endpoint) — and only the browser knows which. The
	 * inline beacon therefore reports script.js's verdict as `geo_bootstrap`
	 * ('live' or 'strict-fallback'), and this method records the strict
	 * fallback's id for 'strict-fallback', the per-country ruleset for
	 * 'live', and no ruleset at all when the gate is active but the browser
	 * said nothing (a page cached before the beacon learned to report): an
	 * unclaimed ruleset is never wrong, a guessed one can be. The server
	 * gate is consulted, not the browser alone, so a verdict from a page
	 * cached under a setting since turned off, or a forged one, cannot make
	 * the row claim a shell the frontend did not render. Callers that never
	 * run the bootstrap (AMP, whose pages the gate excludes; the Do-Not-Sell
	 * form) pass no verdict and keep the per-country chain. The visitor's
	 * country and region are recorded in every case: they describe the
	 * visitor, not the ruleset, and are what the live lookup resolved from.
	 *
	 * Runs inside the consent REST request, which is never served from a
	 * page cache and carries the visitor's own address and headers, so it
	 * sees what the page render saw. Never throws: an absent class or a
	 * resolver error degrades to "no context", never to a wrong one.
	 *
	 * @since 1.34.2
	 * @param string|null $bootstrap_verdict 'live' or 'strict-fallback' as the
	 *                    browser reported it, '' when the browser path reported
	 *                    nothing, null when the caller never runs the bootstrap.
	 * @return array|null Keys: country ('' when unknown), region, ruleset_id
	 *                    ('' when nothing was enforced or nothing can be
	 *                    claimed). Null when the geo classes are not loaded.
	 */
	protected function runtime_visitor_context( $bootstrap_verdict = null ) {
		$geolocation = '\\FazCookie\\Includes\\Geolocation';
		$runtime     = '\\FazCookie\\Frontend\\Includes\\Geo_Runtime';
		$cache_mode  = '\\FazCookie\\Includes\\Cache_Compatibility';

		if ( ! class_exists( $geolocation ) || ! method_exists( $geolocation, 'get_visitor_country' ) ) {
			return null;
		}

		try {
			$country = strtoupper( trim( (string) $geolocation::get_visitor_country() ) );
			if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) || 'XX' === $country ) {
				$country = '';
			}

			$region = '';
			if ( method_exists( $geolocation, 'get_visitor_region' ) ) {
				$region = strtoupper( trim( (string) $geolocation::get_visitor_region() ) );
			}
			// Coherence, as the routing module applies it: a region belongs to
			// the resolved country or it is nothing.
			if ( 1 !== preg_match( '/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $region ) || '' === $country || substr( $region, 0, 2 ) !== $country ) {
				$region = '';
			}

			$ruleset_id = '';
			$invariant  = class_exists( $cache_mode ) && method_exists( $cache_mode, 'is_active' ) && $cache_mode::is_active();
			// The gate is asked only when it can change the answer: a 'live'
			// verdict and a caller outside the bootstrap both resolve per
			// country, as the banner REST and Frontend::get_runtime_ruleset()
			// did for that visitor.
			$shell = ! $invariant && null !== $bootstrap_verdict && 'live' !== $bootstrap_verdict
				? $this->geo_bootstrap_shell_active()
				: false;
			if ( $invariant ) {
				$ruleset_id = '';
			} elseif ( false !== $shell ) {
				// true: the strict shell was what every page rendered. null:
				// the gate could not be read, so the shell cannot be ruled out.
				// Either way the per-country ruleset is not what was enforced
				// unless the browser said the live payload landed.
				$ruleset_id = ( true === $shell && 'strict-fallback' === $bootstrap_verdict )
					? self::GEO_BOOTSTRAP_STRICT_RULESET
					: '';
			} elseif ( class_exists( $runtime ) && method_exists( $runtime, 'resolve_for_country' ) ) {
				$ruleset = $runtime::resolve_for_country( $country );
				if ( is_array( $ruleset ) && isset( $ruleset['id'] ) && is_string( $ruleset['id'] ) ) {
					$ruleset_id = $ruleset['id'];
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return array(
			'country'    => $country,
			'region'     => $region,
			'ruleset_id' => $ruleset_id,
		);
	}

	/**
	 * Whether the jurisdiction bootstrap currently owns normal pages.
	 *
	 * Mirrors Frontend::is_geo_bootstrap_cache_active() for a request that is
	 * not a page render: the request-independent readiness gate the frontend
	 * and the admin status endpoint share, minus the AMP exclusion (an AMP
	 * consent never carries a bootstrap verdict, so it never reaches here)
	 * and minus Cache Compatibility Mode, which the caller has already ruled
	 * out. Asked at most once per consent write, and only when the browser
	 * reported a shell or nothing: get_geo_bootstrap_status() runs two banner
	 * queries and loads a ruleset file, which the common 'live' path skips.
	 *
	 * @since 1.34.2
	 * @return bool|null True when active, false when not, null when the gate
	 *                   cannot be read (class absent or it threw).
	 */
	protected function geo_bootstrap_shell_active() {
		$frontend = '\\FazCookie\\Frontend\\Frontend';
		if ( ! class_exists( $frontend ) || ! method_exists( $frontend, 'get_geo_bootstrap_status' ) ) {
			return null;
		}
		try {
			$status = $frontend::get_geo_bootstrap_status();
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $status ) || ! array_key_exists( 'active', $status ) ) {
			return null;
		}
		return ! empty( $status['active'] ) && false === apply_filters( 'faz_is_amp_request', false );
	}

	/**
	 * A stored created_at value as the same instant in UTC, RFC 3339.
	 *
	 * created_at is written with current_time( 'mysql' ): the site's wall
	 * clock, no offset — the WordPress convention post_date and comment_date
	 * follow. That is fine inside the site, which knows its own timezone, and
	 * ambiguous the moment the value leaves it: an auditor correlating the
	 * export with server logs or a vendor's TCF records (both UTC) has to
	 * guess the offset, and guesses wrong by an hour around a DST change.
	 * The export and the REST payload therefore carry this alongside the
	 * stored value, so the stored value keeps its meaning for the admin table
	 * and existing integrations while the instant is stated once, unambiguously.
	 *
	 * Converted with the site's timezone, as WordPress does for the GMT
	 * columns of its own tables. A value from inside the one repeated hour of
	 * a DST fall-back resolves to one of its two instants; log_id order
	 * disambiguates those rows.
	 *
	 * @since 1.34.2
	 * @param mixed $created_at The stored `Y-m-d H:i:s` site-time value.
	 * @return string `Y-m-d\TH:i:s\Z`, or '' when the value is empty or unparseable.
	 */
	public static function created_at_gmt( $created_at ) {
		$created_at = is_string( $created_at ) ? trim( $created_at ) : '';
		if ( '' === $created_at || ! function_exists( 'get_gmt_from_date' ) ) {
			return '';
		}
		if ( false === strtotime( $created_at ) ) {
			return '';
		}
		$gmt = get_gmt_from_date( $created_at, 'Y-m-d\TH:i:s\Z' );
		return is_string( $gmt ) ? $gmt : '';
	}

	/**
	 * The UTC instant of a consent-log row, RFC 3339.
	 *
	 * Reads the stored created_at_gmt column (1.34.2) and falls back to the
	 * value computed from created_at, with the site's time zone, only for a
	 * row that has none: written before the migration added the column, not
	 * yet reached by its backfill, or with an unparseable created_at.
	 *
	 * @since 1.34.2
	 * @param array $row Consent-log row.
	 * @return string `Y-m-d\TH:i:s\Z`, or '' when neither is available.
	 */
	public static function row_created_at_gmt( $row ) {
		$stored = is_array( $row ) && isset( $row['created_at_gmt'] ) && is_string( $row['created_at_gmt'] ) ? trim( $row['created_at_gmt'] ) : '';
		if ( '' !== $stored && 0 !== strpos( $stored, '0000-00-00' ) ) {
			$timestamp = strtotime( $stored . ' UTC' );
			if ( false !== $timestamp ) {
				return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
			}
		}
		return self::created_at_gmt( is_array( $row ) && isset( $row['created_at'] ) ? $row['created_at'] : '' );
	}

	/**
	 * Shared list/export status clause, including the GPC pseudo-status.
	 *
	 * @param string $status Requested filter.
	 * @return array{0:string,1:array}
	 */
	private function status_filter( $status ) {
		global $wpdb;
		if ( 'gpc_exception' !== $status ) {
			return array( 'status = %s', array( $status ) );
		}
		$values = array();
		foreach ( array( 'meta.gpc_exception.', 'meta.gpc_exception_served.', 'meta.gpc_exception_carried.' ) as $prefix ) {
			$values[] = '%' . $wpdb->esc_like( '"' . $prefix ) . '%';
		}
		return array( '(categories LIKE %s OR categories LIKE %s OR categories LIKE %s)', $values );
	}

	/**
	 * Get paginated consent logs.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *     @type int    $paged    Current page. Default 1.
	 *     @type int    $per_page Items per page. Default 10.
	 *     @type string $search   Search term (matches consent_id or url).
	 *     @type string $status   Filter by status.
	 *     @type string $orderby  Column to order by. Default 'created_at'.
	 *     @type string $order    ASC or DESC. Default 'DESC'.
	 * }
	 * @return array {
	 *     @type array $items Array of log records.
	 *     @type int   $total Total number of matching records.
	 *     @type int   $pages Total number of pages.
	 * }
	 */
	public function get_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'paged'    => 1,
			'per_page' => 10,
			'search'   => '',
			'status'   => '',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		);
		$args     = wp_parse_args( $args, $defaults );
		$table    = $this->get_table_name();

		$where = array( '1=1' );
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(consent_id LIKE %s OR url LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $args['status'] ) ) {
			list( $status_clause, $status_values ) = $this->status_filter( $args['status'] );
			$where[] = $status_clause;
			$values  = array_merge( $values, $status_values );
		}

		$where_clause = implode( ' AND ', $where );

		// Whitelist orderby columns.
		$allowed_orderby = array( 'log_id', 'consent_id', 'status', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		// Count total — always use prepare() even when $values is empty.
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $where_clause is built from a server-controlled allowlist of column names + bound %s/%d placeholders, all user values flow through $values which prepare() binds. Stats query — caching would mask near-real-time admin dashboard.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", $values ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix + literal; literal WHERE 1=1 has no user input. Stats query — caching would mask near-real-time data.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE 1=1" );
		}

		// Get items.
		$per_page = absint( $args['per_page'] );
		$offset   = ( absint( $args['paged'] ) - 1 ) * $per_page;

		$query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_values = array_merge( $values, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is built from plugin-prefix $table, allowlisted $orderby/$order, and a $where_clause assembled from allowlisted column names + %s/%d placeholders; all user input flows through $query_values which prepare() binds. Caching would mask admin-dashboard listing freshness.
		$items = $wpdb->get_results( $wpdb->prepare( $query, $query_values ), ARRAY_A );

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		// Decode categories JSON for each item.
		foreach ( $items as &$item ) {
			if ( ! empty( $item['categories'] ) ) {
				$decoded = json_decode( $item['categories'], true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$item['categories'] = $decoded;
				}
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * The address hash a row written by this request would carry.
	 *
	 * Lets the consent endpoint tell whether the row a request corrects was
	 * written from the same address, with the formula log_consent() stores,
	 * without a second copy of it.
	 *
	 * @since 1.34.2
	 * @return string '' when the address cannot be resolved.
	 */
	public function current_ip_hash() {
		return $this->hash_ip( $this->get_visitor_ip() );
	}

	/**
	 * Get a single consent log by consent_id.
	 *
	 * @param string $consent_id The consent ID to look up.
	 * @return array|null The log record or null if not found.
	 */
	public function get_log_by_consent_id( $consent_id ) {
		global $wpdb;

		$table = $this->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $consent_id is bound via prepare(%s). Caching would mask post-write reads from the same request.
		$item  = $wpdb->get_row(
			$wpdb->prepare(
				// log_id breaks ties: created_at has one-second resolution and
				// a consent id can log twice in a second (the throttle lets a
				// change through), so without it "newest" was arbitrary.
				"SELECT * FROM {$table} WHERE consent_id = %s ORDER BY created_at DESC, log_id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$consent_id
			),
			ARRAY_A
		);

		if ( null === $item ) {
			return null;
		}

		if ( ! empty( $item['categories'] ) ) {
			$decoded = json_decode( $item['categories'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$item['categories'] = $decoded;
			}
		}

		return $item;
	}

	/**
	 * All decisions for an identifier, for authenticated privacy tools.
	 *
	 * @param string $consent_id Verified consent identifier.
	 * @return array|\WP_Error History or a retryable database error.
	 */
	public function get_logs_by_consent_id( $consent_id ) {
		global $wpdb;
		$table = $this->get_table_name();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE consent_id = %s ORDER BY created_at DESC, log_id DESC", $consent_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! empty( $wpdb->last_error ) ) {
			return new \WP_Error( 'faz_consent_history_query', __( 'Consent history could not be read. Please retry the privacy request.', 'faz-cookie-manager' ) );
		}
		return (array) $rows;
	}

	/** Erase the complete history, not just the latest decision. */
	public function delete_logs_by_consent_id( $consent_id ) {
		global $wpdb;
		return $wpdb->delete( $this->get_table_name(), array( 'consent_id' => $consent_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The immediately preceding decision for a consent id.
	 *
	 * A decision without the exception breaks its carry chain. Searching past
	 * that row would allow a revoked exception to inherit an older verdict.
	 *
	 * @param string $consent_id Sanitised consent id.
	 * @return array|null The log record, categories decoded, or null.
	 */
	private function get_previous_consent_row( $consent_id ) {
		global $wpdb;

		$table = $this->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $consent_id is bound via prepare(%s). Read right before the row it informs is written: a cached answer could be stale.
		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE consent_id = %s ORDER BY created_at DESC, log_id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$consent_id
			),
			ARRAY_A
		);

		if ( null === $item ) {
			return null;
		}
		if ( ! empty( $item['categories'] ) && is_string( $item['categories'] ) ) {
			$decoded = json_decode( $item['categories'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$item['categories'] = $decoded;
			}
		}
		return $item;
	}

	/**
	 * Get consent statistics grouped by status.
	 *
	 * @return array Array of objects with 'type' and 'count' keys.
	 */
	public function get_statistics() {
		global $wpdb;

		$table = $this->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; literal GROUP BY status query has no user input. Aggregate refreshed on every request — caching would defeat the purpose.
		$results = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $results ) || empty( $results ) ) {
			return array();
		}

		$logs  = array();
		$total = 0;
		foreach ( $results as $row ) {
			$count  = absint( $row['count'] );
			$total += $count;
			$logs[] = array(
				'type'  => sanitize_text_field( $row['status'] ),
				'count' => $count,
			);
		}

		if ( $total <= 0 ) {
			return array();
		}

		return $logs;
	}

	/**
	 * Export consent logs as CSV.
	 *
	 * @param array $args Query arguments (same as get_logs, but per_page can be -1 for all).
	 * @return string|false CSV content, or false when generation fails.
	 */
	public function export_csv( $args = array() ) {
		$output = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $output ) {
			return '';
		}
		$complete = $this->write_csv( $output, $args );
		if ( false === $complete ) {
			fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			// false, not a partial string: the caller decides what to do with a
			// failure, and a truncated CSV that reads as complete is the failure
			// mode worth refusing outright.
			return false;
		}
		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	/**
	 * Write the export straight to a stream, one batch at a time.
	 *
	 * export_csv() above still returns a string, because callers that want the
	 * whole thing in memory legitimately exist. The HTTP download does not: it
	 * used to build the entire file in a php://temp buffer and only then echo it,
	 * so peak memory held the complete CSV — on the plugin's highest-volume table,
	 * for the site with the most rows, which is exactly the site whose
	 * administrator needs the export and the one where it ran out of memory.
	 *
	 * Batching the QUERY without batching the OUTPUT solved half the problem and
	 * hid the other half: the SELECT no longer spiked, so the failure moved from
	 * an obvious "SQL returned too much" to an opaque allowed-memory-exhausted
	 * partway through a download.
	 *
	 * @param resource $handle Open, writable stream.
	 * @param array    $args   Same filters as export_csv().
	 * @return bool True when every batch was written; false on a database error.
	 */
	private function write_csv( $handle, $args = array() ) {
		global $wpdb;

		$table = $this->get_table_name();

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(consent_id LIKE %s OR url LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $args['status'] ) ) {
			list( $status_clause, $status_values ) = $this->status_filter( $args['status'] );
			$where[] = $status_clause;
			$values  = array_merge( $values, $status_values );
		}

		$where_clause = implode( ' AND ', $where );


		// CSV header row. The seven Migration_V2 columns are part of the proof
		// the plugin collects — the regime the visitor was enforced under, the
		// privacy signals the request carried, the TC and GPP strings consented
		// to — and this export is the path readme.txt advertises for audits and
		// DPIAs, so they belong here. Emitted empty for a row written before the
		// migration (or on an install where it is pending), never shifted.
		// created_at is exported twice on purpose: the stored site-time value
		// and the same instant in UTC — see created_at_gmt().
		//
		// The first ten columns are exactly the 1.34.1 header, names included,
		// and every new column comes after them. 'Created At' keeps its name and
		// its value (site time, as it always was) rather than being renamed to
		// say so: an integration that reads the export by column name — a
		// spreadsheet lookup, a DPIA script, an import into a CMP or a SIEM —
		// would otherwise lose the timestamp silently on upgrade, which is a
		// worse failure than a label that does not name its clock. The clock is
		// named by the column beside it, 'Created At (UTC)', which carries the
		// same instant with its offset resolved.
		fputcsv(
			$handle,
			array(
				'Log ID', 'Consent ID', 'Status', 'Categories', 'IP Hash', 'User Agent Hash', 'URL', 'Banner Slug', 'Policy Revision',
				'Created At', 'Created At (UTC)',
				'Country', 'Region', 'Ruleset', 'GPC Received', 'DNT Received', 'TC String', 'GPP String',
			),
			',',
			'"',
			'\\'
		);

		// Stream in keyset-paginated batches instead of materialising the whole
		// table in one get_results() call: the consent-log table is the plugin's
		// highest-volume table and an unbounded SELECT * exhausts memory on
		// long-lived installs. log_id is the auto-increment PK, so walking it
		// descending preserves the newest-first export order and every batch is
		// an index range scan.
		$batch_size  = 1000;
		$last_log_id = null;

		do {
			// Do not seed the cursor with PHP_INT_MAX: on a 32-bit PHP build that
			// would exclude valid BIGINT rows above 2,147,483,647. The first batch
			// has no cursor predicate; subsequent batches use an id read from MySQL.
			// Bind the BIGINT cursor as a decimal string. WordPress' %d formatter
			// casts through the platform integer size and would still overflow on
			// 32-bit PHP after the first batch.
			$cursor_clause = null === $last_log_id ? '' : ' AND log_id < %s';
			$batch_values  = null === $last_log_id
				? array_merge( $values, array( $batch_size ) )
				: array_merge( $values, array( $last_log_id, $batch_size ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $where_clause is built from a closed allowlist of column names + %s/%d placeholders, all user values flow through $batch_values which prepare() binds.
			$query = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_clause}{$cursor_clause} ORDER BY log_id DESC LIMIT %d",
				$batch_values
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query produced by the prepare() call above. CSV export — must reflect live data.
			$items = $wpdb->get_results( $query, ARRAY_A );

			// A failed query also returns an empty array, so without this the
			// export stopped at the error and handed back a CSV that LOOKS
			// complete — the worst possible outcome for a file somebody exports
			// to answer a data-subject request or an audit. Same conflation the
			// retention purge above had, in the one place where the missing rows
			// are the whole point of the file.
			if ( '' !== $wpdb->last_error ) {
				error_log( 'FAZ: consent-log export query failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a truncated export must never pass for a complete one.
				return false;
			}

			if ( ! is_array( $items ) || empty( $items ) ) {
				break;
			}

			foreach ( $items as $item ) {
				$last_log_id = (string) $item['log_id'];
				fputcsv(
					$handle,
					array_map(
						array( $this, 'sanitize_csv_cell' ),
						array(
							$item['log_id'],
							$item['consent_id'],
							$item['status'],
							$item['categories'], // Already JSON string from DB.
							$item['ip_hash'],
							$item['user_agent'],
							$item['url'],
							isset( $item['banner_slug'] ) ? $item['banner_slug'] : '',
							isset( $item['policy_revision'] ) ? $item['policy_revision'] : 1,
							$item['created_at'],
							self::row_created_at_gmt( $item ),
							isset( $item['country_at_consent'] ) ? (string) $item['country_at_consent'] : '',
							isset( $item['region_at_consent'] ) ? (string) $item['region_at_consent'] : '',
							isset( $item['ruleset_id_at_consent'] ) ? (string) $item['ruleset_id_at_consent'] : '',
							isset( $item['signal_gpc_received'] ) ? (string) $item['signal_gpc_received'] : '',
							isset( $item['signal_dnt_received'] ) ? (string) $item['signal_dnt_received'] : '',
							isset( $item['tc_string'] ) ? (string) $item['tc_string'] : '',
							isset( $item['gpp_string'] ) ? (string) $item['gpp_string'] : '',
						)
					),
					',',
					'"',
					'\\'
				);
			}
		} while ( count( $items ) === $batch_size );


		return true;
	}

	/**
	 * Public entry point for streaming the export to an already-open stream.
	 *
	 * @param resource $handle Open, writable stream (e.g. fopen('php://output','w')).
	 * @param array    $args   Same filters as export_csv().
	 * @return bool True when the stream was usable.
	 */
	public function stream_csv( $handle, $args = array() ) {
		if ( ! is_resource( $handle ) ) {
			return false;
		}
		// Propagated, though a streamed failure cannot be retracted: the headers
		// and the first batches have already reached the client. What the caller
		// gets back is the truth about completeness, which is the most this
		// direction can offer — and more than silently claiming success.
		return false !== $this->write_csv( $handle, $args );
	}

	/**
	 * Sanitize a single CSV cell to prevent formula injection.
	 *
	 * Prefixes values starting with dangerous characters (=, +, -, @, \t, \r)
	 * with a single quote so spreadsheet apps do not interpret them as formulas.
	 *
	 * @param string $value Cell value.
	 * @return string Sanitized value.
	 */
	private function sanitize_csv_cell( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		// Strip leading whitespace/newlines that could bypass the prefix check.
		$trimmed = ltrim( $value, " \t\n\r\0\x0B" );
		if ( '' !== $trimmed && in_array( $trimmed[0], array( '=', '+', '-', '@', "\t", "\r", "\n" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Get detailed consent statistics: daily breakdown, totals, and per-category acceptance.
	 *
	 * @param int $days Number of days to look back.
	 * @return array {
	 *     @type array      $daily      Daily breakdown with date, accepted, rejected, partial, total.
	 *     @type array      $totals     Overall totals for the period.
	 *     @type array      $categories Per-category yes/no counts.
	 * }
	 */
	public function get_consent_stats( $days = 30 ) {
		global $wpdb;

		$table  = $this->get_table_name();
		$days   = absint( $days );
		// Use PHP-computed cutoff with current_time() for consistency with
		// how created_at is stored (via current_time('mysql') in log_consent).
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', strtotime( current_time( 'mysql' ) ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The three queries below all read from the plugin-prefix custom $table with $cutoff bound via prepare(%s); they power the admin dashboard's live aggregate and must not be cached.

		// Daily consent breakdown.
		$daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date,
						SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
						SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
						SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial,
						COUNT(*) as total
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY DATE(created_at)
				 ORDER BY date ASC",
				$cutoff
			),
			ARRAY_A
		);

		if ( ! is_array( $daily ) ) {
			$daily = array();
		}

		// Overall totals.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as total,
						SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
						SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
						SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial
				 FROM {$table}
				 WHERE created_at >= %s",
				$cutoff
			),
			ARRAY_A
		);

		if ( ! is_array( $totals ) ) {
			$totals = array(
				'total'    => 0,
				'accepted' => 0,
				'rejected' => 0,
				'partial'  => 0,
			);
		}

		// Per-category acceptance rates — parse the JSON categories column.
		$category_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT categories FROM {$table}
				 WHERE created_at >= %s
				 AND categories IS NOT NULL AND categories != ''",
				$cutoff
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$cat_counts = array();
		if ( is_array( $category_rows ) ) {
			foreach ( $category_rows as $row ) {
				$cats = json_decode( $row['categories'], true );
				if ( ! is_array( $cats ) ) {
					continue;
				}
				foreach ( $cats as $cat => $value ) {
					$cat = sanitize_text_field( $cat );
					// Skip reserved audit-only keys (e.g. meta.age_affirmed): they
					// ride the categories JSON for accountability but are NOT
					// consent categories, so counting them would draw a phantom
					// bar in the dashboard acceptance-rate chart. They still render
					// as an audit pill in the individual consent-log row.
					if ( 0 === strpos( $cat, 'meta.' ) ) {
						continue;
					}
					if ( ! isset( $cat_counts[ $cat ] ) ) {
						$cat_counts[ $cat ] = array( 'yes' => 0, 'no' => 0 );
					}
					if ( 'yes' === $value ) {
						$cat_counts[ $cat ]['yes']++;
					} else {
						$cat_counts[ $cat ]['no']++;
					}
				}
			}
		}

		return array(
			'daily'      => $daily,
			'totals'     => $totals,
			'categories' => $cat_counts,
		);
	}

	/**
	 * Accept-rate per banner variant for the A/B test Dashboard panel.
	 *
	 * Groups the consent log by banner_slug for the requested variant slugs and
	 * reports, per variant, the total consents and the full per-status split —
	 * accepted / rejected / partial plus the two CCPA / Do-Not-Sell outcomes
	 * (dnsmpi_optout, dns_rescinded) — with the acceptance rate. The
	 * banner_slug column is written on every consent (Frontend records the
	 * variant the visitor was served), so this attributes each consent to the
	 * variant that produced it — the accountability side of the random split.
	 *
	 * The raw grouped rows are reshaped by Ab_Test::compute_stats(), which
	 * zero-fills variants with no consents yet, follows the requested slug
	 * order, and computes accept_rate over an explicit `decisions` denominator
	 * (accepted + rejected + partial + optout + rescinded) so a CCPA variant's
	 * rate is not silently diluted by opt-out / rescind events — while also
	 * surfacing the per-status counts so the composition stays visible. Keeping
	 * the arithmetic there makes it unit-testable without a database and keeps
	 * this method a thin query wrapper.
	 *
	 * @since 1.25.0
	 * @param array $slugs Banner variant slugs to report.
	 * @param int   $days  Look-back window in days; 0 means all time.
	 * @return array<int, array<string, mixed>> Per-variant stats rows.
	 */
	public function get_ab_test_stats( $slugs, $days = 0 ) {
		global $wpdb;

		// Normalise to a unique, non-empty list of string slugs.
		$slugs = is_array( $slugs ) ? $slugs : array();
		$slugs = array_values( array_unique( array_filter( array_map( function ( $s ) {
			return is_scalar( $s ) ? trim( (string) $s ) : '';
		}, $slugs ), function ( $s ) {
			return '' !== $s;
		} ) ) );
		if ( empty( $slugs ) ) {
			return array();
		}

		$table = $this->get_table_name();
		$days  = absint( $days );

		// One %s placeholder per slug for the IN() list; every value is bound
		// through prepare() below.
		$placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );
		$params       = $slugs;

		$cutoff_sql = '';
		if ( $days > 0 ) {
			// PHP-computed cutoff via current_time() for consistency with how
			// created_at is stored (current_time('mysql') in log_consent).
			$cutoff       = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', strtotime( current_time( 'mysql' ) ) ) );
			$cutoff_sql   = ' AND created_at >= %s';
			$params[]     = $cutoff;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is the plugin-prefix custom table; the only interpolated fragments are a $wpdb->prepare-safe list of %s placeholders and a fixed cutoff clause, every value bound via prepare(). Live admin aggregate — must not be cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT banner_slug,
						COUNT(*) as total,
						SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
						SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
						SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial,
						SUM(CASE WHEN status = 'dnsmpi_optout' THEN 1 ELSE 0 END) as optout,
						SUM(CASE WHEN status = 'dns_rescinded' THEN 1 ELSE 0 END) as rescinded
				 FROM {$table}
				 WHERE banner_slug IN ({$placeholders}){$cutoff_sql}
				 GROUP BY banner_slug", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		return \FazCookie\Includes\Ab_Test::compute_stats( $rows, $slugs );
	}

	/**
	 * Cleanup old consent logs beyond a given retention period.
	 *
	 * @param int $months Number of months to retain. Logs older than this are deleted.
	 * @return int Number of rows deleted.
	 */
	public function cleanup_old_logs( $months = 12 ) {
		global $wpdb;

		$table    = $this->get_table_name();
		$months   = absint( $months );
		// On the clock created_at is on. The column holds site time (see
		// log_consent()), and the stats cutoffs are computed from
		// current_time( 'mysql' ) for that reason; this one was computed in UTC,
		// so the purge ran early or late by the site's offset.
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months", strtotime( current_time( 'mysql' ) ) ) );

		// Delete in bounded batches: the first cleanup on a long-lived install
		// can match hundreds of thousands of rows, and a single unbounded
		// DELETE holds a long InnoDB transaction (lock contention, huge undo
		// log, replication lag). Each batch is an index range scan on
		// idx_created_at; the cap keeps one cron run from monopolising the DB.
		// Select-then-delete rather than `DELETE … LIMIT`. That clause is a MySQL
		// extension: SQLite refuses it unless compiled with an option WordPress's
		// SQLite integration does not enable, so on a SQLite-backed install every
		// batch returned false, the guard below logged once and broke, and
		// retention returned 0 forever — silently, since 0 also means "nothing to
		// delete". Retention that never runs is a data-minimisation failure, not
		// a performance one. Same shape as the DSAR cleanup in class-activator.
		//
		// Both numbers are filterable because the right values are properties of
		// the host, not of this plugin: 1000 rows per batch and 200000 per run
		// suit shared hosting, and are wrong in both directions elsewhere — a
		// constrained box wants smaller batches, while an install with millions
		// of expired rows needs many cron runs to catch up at 200k and would
		// rather raise the cap once. The bounds are not politeness: the batch
		// size becomes that many %d placeholders in one prepared DELETE, and an
		// unclamped filter would walk into max_prepared_stmt_count / packet
		// limits; a cap of 0 or less would purge nothing at all while reporting
		// success, which is the same silent-retention-failure class this code
		// was rewritten to eliminate.
		$batch_size = (int) apply_filters( 'faz_retention_batch_size', 1000 );
		$batch_size = max( 100, min( 10000, $batch_size ) );
		$max_rows   = (int) apply_filters( 'faz_retention_max_rows', 200000 );
		$max_rows   = max( $batch_size, min( 10000000, $max_rows ) );

		$deleted = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $cutoff is bound via prepare(%s). Retention read — caching irrelevant.
			// Unordered on purpose. The filter is on created_at and the only index
			// that helps is idx_created_at, which does not cover an ORDER BY on the
			// primary key — so asking for one made the database read and sort the
			// whole expired set before applying LIMIT, on exactly the tables where
			// that set is largest. Deletion needs the ids, not an order, and
			// progress is guaranteed because every id read is then deleted.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT log_id FROM {$table} WHERE created_at < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff,
					// The remaining budget, not the batch size. The cap used to be
					// checked only in the loop condition, so the LAST batch always
					// read a full batch and could overshoot by up to batch_size - 1
					// rows — a cap that is exceeded on every run it applies to is
					// not a cap.
					min( $batch_size, max( 0, $max_rows - $deleted ) )
				)
			);
			// get_col() returns an empty array both when the query fails and when
			// it matches nothing, so distinguish them explicitly: a failure that
			// reads as "done" is the exact bug this replaces.
			if ( '' !== $wpdb->last_error ) {
				error_log( 'FAZ: consent-log retention SELECT failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a silent retention failure is a data-minimisation problem; it must leave a trace.
				break;
			}
			// Kept as decimal STRINGS, never absint()/%d. On 32-bit PHP both truncate
			// at 2147483647, so a row with a larger id would be rewritten to that
			// value — and if a row with THAT id exists, the DELETE removes somebody
			// else's record. The column is BIGINT precisely because the id can
			// exceed the platform integer, so the id must never travel through one.
			$ids = array_values( array_filter( array_map( 'strval', (array) $ids ), static function ( $id ) {
				return '' !== $id && ctype_digit( $id ) && '0' !== $id;
			} ) );
			if ( empty( $ids ) ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; ids are bound via prepare(%s) as decimal strings. Retention cleanup writes — caching irrelevant for DELETE.
			$batch = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE log_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ids
				)
			);
			// A failed query returns false, and (int) false is 0 — which reads
			// exactly like "no rows left to delete". Retention would then appear
			// to have run while nothing was purged, and the caller has no way to
			// tell the difference. Surface it and stop.
			if ( false === $batch ) {
				error_log( 'FAZ: consent-log retention DELETE failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a silent retention failure is a data-minimisation problem; it must leave a trace.
				break;
			}
			$deleted += (int) $batch;
			// Loop on the SELECT's own size, not the DELETE's return: a row
			// removed by a concurrent request makes the DELETE report fewer than
			// it read, and treating that as "last page" would stop early and
			// leave expired rows behind until the next cron run.
		} while ( count( $ids ) === $batch_size && $deleted < $max_rows );

		return (int) $deleted;
	}
}
