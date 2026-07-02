<?php
/**
 * Fired during plugin activation
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie
 * @subpackage FazCookie/includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FazCookie\Admin\Modules\Banners\Includes\Banner;
use FazCookie\Admin\Modules\Banners\Includes\Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Admin\Modules\Consentlogs\Includes\Controller as ConsentLogs_Controller;
use FazCookie\Admin\Modules\Pageviews\Includes\Controller as Pageviews_Controller;
use FazCookie\Admin\Modules\Scanner\Includes\Controller as Scanner_Controller;

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      3.0.0
 * @package    FazCookie
 * @subpackage FazCookie/includes
 * @author     Fabio D'Alessandro
 */
class Activator {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;
	/**
	 * Update DB callbacks.
	 *
	 * @var array
	 */
	private static $db_updates = array(
		'3.0.7' => array(
			'update_db_307',
		),
		'3.2.1' => array(
			'update_db_321',
		),
		'3.3.7' => array(
			'update_db_337',
		),
		'3.4.0' => array(
			'update_db_340',
		),
		'3.4.1' => array(
			'update_db_341',
		),
		'3.5.0' => array(
			'update_db_350',
		),
		'3.6.0' => array(
			'update_db_360',
		),
	);
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
	 * Activate the plugin
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		// Consolidate one-time migrations into a single admin_init callback
		// to avoid 7 separate get_option() calls on every admin page load.
		add_action( 'admin_init', array( __CLASS__, 'run_pending_migrations' ) );
		add_action( 'faz_daily_cleanup', array( __CLASS__, 'run_retention_cleanup' ) );
		add_action( 'faz_weekly_gvl_update', array( 'FazCookie\Includes\Gvl', 'cron_update' ) );
		add_action( 'faz_scheduled_scan', array( __CLASS__, 'run_scheduled_scan' ) );
		add_action( 'faz_after_update_settings', array( __CLASS__, 'reschedule_auto_scan' ) );
		// F009: keep the IAB unmatched-vendors transient fresh on every
		// cookie write path (create / update / delete) — not just update.
		// A new cookie can introduce an unmatched IAB vendor; a deleted
		// cookie can resolve one. Restricting the listener to "update"
		// only would leave the notice stale until the next edit on an
		// existing row, surprising the publisher.
		add_action( 'faz_after_update_cookie', array( __CLASS__, 'maybe_check_unmatched_vendors' ) );
		add_action( 'faz_after_create_cookie', array( __CLASS__, 'maybe_check_unmatched_vendors' ) );
		add_action( 'faz_after_delete_cookie', array( __CLASS__, 'maybe_check_unmatched_vendors' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		self::schedule_cleanup();
	}

	/**
	 * Bump this only when adding/changing a migration in the sequence below.
	 */
	const MIGRATIONS_VERSION = '2026.06.17.1';

	/**
	 * Run all pending one-time data migrations in a single admin_init callback.
	 *
	 * Checks a consolidated version flag first. When the flag matches
	 * MIGRATIONS_VERSION, all migrations have already completed and we skip
	 * everything with a single get_option() call. This replaces 7
	 * separate admin_init hooks that each performed their own DB lookup.
	 *
	 * @return void
	 */
	public static function run_pending_migrations() {
		if ( get_option( 'faz_migrations_version' ) === self::MIGRATIONS_VERSION ) {
			return;
		}
		try {
			self::ensure_uncategorized_category();
			self::ensure_wordpress_internal_category();
			self::rename_advertisement_to_marketing();
			self::fix_uncategorized_prior_consent();
			self::fix_banner_gdpr_defaults();
			self::fix_brand_logo_path();
			self::seed_default_whitelist();
			self::enable_gpc_on_ccpa_banners();
			self::ensure_share_personal_data_column();
			self::clear_necessary_optout_flags();
			self::reset_stale_per_cookie_consent();
		} catch ( \Throwable $e ) {
			// Do not mark migrations complete — retry on next admin load.
			return;
		}
		update_option( 'faz_migrations_version', self::MIGRATIONS_VERSION, false );
	}

	/**
	 * Seed default whitelist patterns for existing installs.
	 *
	 * Only adds defaults when the whitelist is empty, so user customizations
	 * are never overwritten.
	 *
	 * @return void
	 */
	public static function seed_default_whitelist() {
		$settings = get_option( 'faz_settings' );
		if ( ! is_array( $settings ) ) {
			return;
		}
		$current = isset( $settings['script_blocking']['whitelist_patterns'] )
			? $settings['script_blocking']['whitelist_patterns']
			: null;

		if ( ! empty( $current ) ) {
			return;
		}

		$defaults = array(
			'googleapis.com/youtube/v3/',
			'googleapis.com/customsearch/',
			'translation.googleapis.com/',
			'www.google.com/recaptcha/api',
			'challenges.cloudflare.com/',
			'maps.googleapis.com/maps/api/',
			'www.googleapis.com/oauth2/',
			'fonts.googleapis.com/',
			'cdn.jsdelivr.net/',
			'unpkg.com/',
			'hcaptcha.com/',
		);

		if ( ! isset( $settings['script_blocking'] ) ) {
			$settings['script_blocking'] = array();
		}
		$settings['script_blocking']['whitelist_patterns'] = $defaults;
		update_option( 'faz_settings', $settings );
	}

	/**
	 * Register custom cron schedules (WordPress only provides hourly, twicedaily, daily).
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Weekly', 'faz-cookie-manager' ),
			);
		}
		if ( ! isset( $schedules['faz_daily'] ) ) {
			$schedules['faz_daily'] = array(
				'interval' => DAY_IN_SECONDS,
				'display'  => __( 'Once Daily (FAZ)', 'faz-cookie-manager' ),
			);
		}
		if ( ! isset( $schedules['faz_weekly'] ) ) {
			$schedules['faz_weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly (FAZ)', 'faz-cookie-manager' ),
			);
		}
		if ( ! isset( $schedules['faz_monthly'] ) ) {
			$schedules['faz_monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly (FAZ)', 'faz-cookie-manager' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule recurring cron events: daily retention cleanup and weekly GVL update.
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'faz_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'faz_daily_cleanup' );
		}
		if ( ! wp_next_scheduled( 'faz_weekly_gvl_update' ) ) {
			wp_schedule_event( time(), 'weekly', 'faz_weekly_gvl_update' );
		}
		self::schedule_auto_scan();
	}

	/**
	 * Schedule or unschedule automatic cookie scanning based on settings.
	 */
	public static function schedule_auto_scan() {
		$settings = get_option( 'faz_settings' );
		if ( ! empty( $settings['scanner']['auto_scan'] ) ) {
			$frequency = isset( $settings['scanner']['scan_frequency'] ) ? $settings['scanner']['scan_frequency'] : 'weekly';
			$schedule  = 'faz_' . $frequency;
			if ( ! wp_next_scheduled( 'faz_scheduled_scan' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, 'faz_scheduled_scan' );
			}
		}
	}

	/**
	 * Reschedule automatic scanning when settings are updated.
	 *
	 * Clears any existing schedule and re-registers if auto_scan is enabled.
	 */
	public static function reschedule_auto_scan() {
		$timestamp = wp_next_scheduled( 'faz_scheduled_scan' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'faz_scheduled_scan' );
		}

		$settings = get_option( 'faz_settings' );
		if ( ! empty( $settings['scanner']['auto_scan'] ) ) {
			$frequency = isset( $settings['scanner']['scan_frequency'] ) ? $settings['scanner']['scan_frequency'] : 'weekly';
			$schedule  = 'faz_' . $frequency;
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, 'faz_scheduled_scan' );
		}
	}

	/**
	 * Run consent log retention cleanup based on settings.
	 */
	public static function run_retention_cleanup() {
		$settings  = get_option( 'faz_settings' );
		$retention = isset( $settings['consent_logs']['retention'] ) ? (int) $settings['consent_logs']['retention'] : 12;
		if ( $retention > 0 ) {
			ConsentLogs_Controller::get_instance()->cleanup_old_logs( $retention );
		}
		self::cleanup_old_dsar_requests( $settings );
	}

	/**
	 * Purge DSAR (faz_dsar) form submissions older than the configured retention.
	 *
	 * DSAR posts store the requester's name, email and message in the clear, so
	 * keeping them indefinitely is a data-minimisation problem. Retention is in
	 * months: read from `faz_settings['dsar']['retention']`, defaulting to 24
	 * (long enough to evidence handling of the request, then purged). A value of
	 * 0 disables auto-purge. Filterable via `faz_dsar_retention_months`.
	 *
	 * @param array|false $settings The faz_settings option (passed to avoid a re-read).
	 * @return int Number of DSAR posts deleted.
	 */
	public static function cleanup_old_dsar_requests( $settings = false ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'faz_settings' );
		}
		$default   = isset( $settings['dsar']['retention'] ) ? (int) $settings['dsar']['retention'] : 24;
		$retention = (int) apply_filters( 'faz_dsar_retention_months', $default );
		if ( $retention <= 0 || ! post_type_exists( 'faz_dsar' ) ) {
			return 0;
		}
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $retention * MONTH_IN_SECONDS ) );
		$deleted = 0;
		$old_ids = get_posts(
			array(
				'post_type'        => 'faz_dsar',
				'post_status'      => 'any',
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: this DSAR retention cleanup must find and delete EVERY expired faz_dsar record regardless of third-party query filters (e.g. multilingual language scoping), so filters are suppressed on this internal housekeeping query. Not a wp.org plugin_repo error (WordPressVIPMinimum only).
				'suppress_filters' => true,
				'date_query'       => array(
					array(
						'column' => 'post_date_gmt',
						'before' => $cutoff,
					),
				),
			)
		);
		foreach ( (array) $old_ids as $post_id ) {
			if ( wp_delete_post( (int) $post_id, true ) ) {
				$deleted++;
			}
		}
		return $deleted;
	}

	/**
	 * Run scheduled cookie scan and notify admin if new cookies are found.
	 *
	 * Hooked to the faz_scheduled_scan cron event. Compares cookie count
	 * before and after the scan to determine how many new cookies were added.
	 */
	public static function run_scheduled_scan() {
		$settings = get_option( 'faz_settings' );
		if ( empty( $settings['scanner']['auto_scan'] ) ) {
			return;
		}

		$max_pages = isset( $settings['scanner']['max_pages'] ) ? absint( $settings['scanner']['max_pages'] ) : 20;

		// Count cookies before scan to detect newly added ones.
		$cookie_controller = Cookie_Controller::get_instance();
		$before            = $cookie_controller->get_item_from_db();
		$count_before      = is_array( $before ) ? count( $before ) : 0;

		// Run the scan.
		$scanner = Scanner_Controller::get_instance();
		$scanner->run_scan( $max_pages );

		// Count cookies after scan.
		$after       = $cookie_controller->get_item_from_db();
		$count_after = is_array( $after ) ? count( $after ) : 0;
		$new_count   = $count_after - $count_before;

		if ( $new_count > 0 ) {
			// Set admin notice transient.
			set_transient( 'faz_scan_new_cookies', $new_count, DAY_IN_SECONDS );

			// Send email to admin.
			$admin_email = get_option( 'admin_email' );
			$site_name   = get_bloginfo( 'name' );
			/* translators: 1: site name, 2: number of new cookies */
			$subject = sprintf( '[%s] %d new cookies detected', $site_name, $new_count );
			$message = sprintf(
				"The scheduled cookie scan on %s found %d new cookie(s).\n\n" .
				"Review them at: %s\n\n" .
				"— FAZ Cookie Manager",
				$site_name,
				$new_count,
				admin_url( 'admin.php?page=faz-cookie-manager-cookies' )
			);
			wp_mail( $admin_email, $subject, $message );
		}

		// Check for unmatched IAB vendors (only if IAB TCF is enabled).
		if ( ! empty( $settings['iab']['enabled'] ) ) {
			$unmatched = self::detect_unmatched_vendors();
			if ( ! empty( $unmatched ) ) {
				set_transient( 'faz_unmatched_vendors', $unmatched, WEEK_IN_SECONDS );
			} else {
				delete_transient( 'faz_unmatched_vendors' );
			}
		}
	}

	/**
	 * Check the plugin version and run the updater is required.
	 *
	 * This check is done on all requests and runs if the versions do not match.
	 */
	public static function check_version() {
		if ( ! defined( 'IFRAME_REQUEST' ) && version_compare( get_option( 'faz_version', '0.0.0' ), FAZ_VERSION, '<' ) ) {
			self::install();
		}
	}
	/**
	 * Install all the plugin
	 *
	 * @return void
	 */
	public static function install() {
		self::check_for_upgrade();
		if ( true === faz_first_time_install() ) {
			add_option( 'faz_first_time_activated_plugin', 'true' );
		}
		self::ensure_default_settings();
		self::install_all_tables();
		self::maybe_update_db();
		// Ensure required default categories always exist, even on re-activation
		// after a file-only delete (uninstall.php not called). These calls are
		// idempotent — they no-op when the category already exists.
		self::ensure_uncategorized_category();
		self::ensure_wordpress_internal_category();
		// Neutralise a stale pre-1.18.2 per_cookie_consent=true on upgrade. This
		// also runs in run_pending_migrations(), but that hook is admin_init-only;
		// install() runs from check_version() on the `init` hook (frontend too),
		// so the reset lands on the first request of ANY kind after the upgrade —
		// closing the window where a rarely-admined site would re-activate
		// per-cookie consent on the frontend before an admin ever loads wp-admin.
		self::reset_stale_per_cookie_consent();
		// Always clear the banner template cache on version upgrades so new
		// CSS rules, shortcodes, and template HTML take effect immediately.
		// Without this, users upgrading across multiple versions (e.g. 1.8 →
		// 1.11.x) keep a stale cached template that may lack CSS for new
		// elements like the inline SVG revisit icon.
		faz_clear_banner_template_cache();
		// Invalidate page caches so visitors immediately see the new
		// `_fazConfig`/banner-template payload — without this step the
		// cached HTML keeps embedding the previous version's localized
		// data and our fixes don't reach end-users until the cache
		// expires (or until they manually purge). See `purge_page_caches()`
		// for the matrix of supported cache plugins.
		self::purge_page_caches();
		do_action( 'faz_after_activate', FAZ_VERSION );
		// Bump `faz_version` LAST so a fatal in any of the steps above
		// (table create, migration, purge) leaves the version flag at the
		// previous value — the next admin request will re-enter `install()`
		// via `check_version()` and retry the failed step instead of
		// silently skipping the migration forever.
		update_option( 'faz_version', FAZ_VERSION );
		self::update_db_version();
	}

	/**
	 * Seed default settings during the activation lifecycle on first installs.
	 *
	 * The Settings admin module also performs this work when it is loaded, but
	 * activation can run in contexts where that module is not instantiated
	 * first (for example WP-CLI or a deferred admin load). The activator owns
	 * the fresh-install contract, so it must leave faz_settings complete.
	 *
	 * @return void
	 */
	private static function ensure_default_settings() {
		if ( true !== faz_first_time_install() || false !== get_option( 'faz_settings', false ) ) {
			return;
		}

		$settings = new \FazCookie\Admin\Modules\Settings\Includes\Settings();
		$settings->update( $settings->get_defaults(), false );
	}

	/**
	 * Best-effort full-cache purge across the most common WordPress cache
	 * stacks. Called on every plugin version bump (and again on full
	 * activation) so visitors get a fresh HTML payload that embeds the
	 * up-to-date `_fazConfig` localize block.
	 *
	 * Each plugin is detected by a stable public symbol (action/filter
	 * /function) so this method never crashes if the cache plugin is
	 * absent. CDN edges (Cloudflare, Bunny, KeyCDN, etc.) are NOT touched
	 * here — those need API credentials we do not own; admins running a
	 * CDN should also purge it manually after a FAZ upgrade.
	 *
	 * @since 1.13.9
	 * @return void
	 */
	public static function purge_page_caches() {
		// Each best-effort purge call is wrapped in try/catch so a single
		// misbehaving cache plugin (corrupted state, partial install,
		// permissions issue) cannot abort the upgrade flow midway and
		// leave `faz_version` un-bumped. Throwables are logged via
		// `error_log` for forensics but do not propagate.
		$purgers = array(
			array( 'LiteSpeed Cache', function () {
				if ( defined( 'LSCWP_V' ) ) {
					do_action( 'litespeed_purge_all', 'FAZ Cookie Manager upgrade' );
				}
			} ),
			array( 'WP Rocket', function () {
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
				}
			} ),
			array( 'W3 Total Cache', function () {
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
				}
			} ),
			array( 'WP Super Cache', function () {
				if ( function_exists( 'wp_cache_clear_cache' ) ) {
					wp_cache_clear_cache();
				}
			} ),
			array( 'Cache Enabler', function () {
				if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
					do_action( 'cache_enabler_clear_complete_cache' );
				}
			} ),
			array( 'SG Optimizer', function () {
				if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
					sg_cachepress_purge_cache();
				}
			} ),
			array( 'Hummingbird', function () {
				if ( has_action( 'wphb_clear_page_cache' ) ) {
					do_action( 'wphb_clear_page_cache' );
				}
			} ),
			array( 'Breeze', function () {
				if ( class_exists( 'Breeze_PurgeCache' ) ) {
					\Breeze_PurgeCache::breeze_cache_flush();
				}
			} ),
			array( 'Autoptimize', function () {
				if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
					\autoptimizeCache::clearall();
				}
			} ),
			array( 'WP-Optimize', function () {
				if ( class_exists( 'WP_Optimize' ) && function_exists( 'wpo_cache_flush' ) ) {
					wpo_cache_flush();
				}
			} ),
			array( 'Comet Cache', function () {
				if ( class_exists( '\\WebSharks\\CometCache\\Classes\\Plugin' ) ) {
					$comet = \WebSharks\CometCache\Classes\Plugin::class;
					if ( method_exists( $comet, 'wipeCache' ) ) {
						$comet::wipeCache();
					}
				}
			} ),
			array( 'WP Object Cache', function () {
				// Generic WP object cache (Memcached, Redis via the drop-in).
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
			} ),
		);

		foreach ( $purgers as $entry ) {
			list( $label, $callable ) = $entry;
			try {
				$callable();
			} catch ( \Throwable $e ) {
				error_log( 'FAZ purge_page_caches: ' . $label . ' failed — ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Install all database tables at activation.
	 *
	 * @return void
	 */
	public static function install_all_tables() {
		// Core tables (banners, cookies, categories).
		$base_controllers = array(
			'FazCookie\Admin\Modules\Banners\Includes\Controller',
			'FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller',
			'FazCookie\Admin\Modules\Cookies\Includes\Category_Controller',
		);
		foreach ( $base_controllers as $controller_class ) {
			if ( class_exists( $controller_class ) ) {
				$controller = $controller_class::get_instance();
				$controller->install_tables();
			}
		}

		// Consent logs table (standalone controller).
		if ( class_exists( 'FazCookie\Admin\Modules\Consentlogs\Includes\Controller' ) ) {
			ConsentLogs_Controller::get_instance()->maybe_create_table();
		}

		// Pageviews table (standalone controller).
		if ( class_exists( 'FazCookie\Admin\Modules\Pageviews\Includes\Controller' ) ) {
			Pageviews_Controller::get_instance()->maybe_create_table();
		}
	}

	/**
	 * Set a temporary flag during the first time installation.
	 *
	 * The transient name was renamed `_faz_first_time_install` →
	 * `faz_first_time_install` to satisfy the wp.org "Use Prefixes"
	 * guideline (a leading underscore counts as a 1-character prefix).
	 * Migrate the legacy name on the same call so installs upgrading
	 * mid-flight don't lose the flag.
	 *
	 * @return void
	 */
	public static function check_for_upgrade() {
		// 30 minutes is enough for the post-activation redirect flow to
		// run (admin lands on the welcome screen, dismisses the notice,
		// browses around). 30 SECONDS — the previous value — was a typo:
		// the flag would expire before the activation_redirect itself
		// fires on a slow-bootstrapping host.
		$ttl = 30 * MINUTE_IN_SECONDS;

		// Migration: copy legacy transient onto the new name and delete it.
		$legacy = get_site_transient( '_faz_first_time_install' );
		if ( false !== $legacy ) {
			set_site_transient( 'faz_first_time_install', $legacy, $ttl );
			delete_site_transient( '_faz_first_time_install' );
		}

		if ( false === get_option( 'faz_settings', false ) ) {
			if ( false === get_site_transient( 'faz_first_time_install' ) ) {
				set_site_transient( 'faz_first_time_install', true, $ttl );
			}
		}
	}

	/**
	 * Update DB version to track changes to data structure.
	 *
	 * @param string $version Current version.
	 * @return void
	 */
	public static function update_db_version( $version = null ) {
		$target = is_null( $version ) ? FAZ_VERSION : $version;

		// If the v2 geo-routing migration didn't complete (status was
		// 'partial' / 'no_table'), pin the DB-version option below 3.6.0
		// so the next activator pass re-enters update_db_360() and
		// Migration_V2 picks up the residual columns listed in
		// `faz_geo_v2_migration_pending`. Without this, the updater
		// loop would advance past 3.6.0 and never retry the failed
		// columns on subsequent activates.
		$migration_complete = (bool) get_option( 'faz_geo_v2_migration_complete', true );
		if ( ! $migration_complete && version_compare( $target, '3.6.0', '>=' ) ) {
			$target = '3.5.0';
		}

		update_option( 'faz_cookie_consent_db_version', $target );
	}

	/**
	 * Check if any database changes is required on the latest release
	 *
	 * @return boolean
	 */
	private static function needs_db_update() {
		$current_version = get_option( 'faz_cookie_consent_db_version', '3.0.7' ); // @since 3.0.7 introduced DB migrations
		$updates         = self::$db_updates;
		$update_versions = array_keys( $updates );
		usort( $update_versions, 'version_compare' );
		return ! is_null( $current_version ) && version_compare( $current_version, end( $update_versions ), '<' );
	}

	/**
	 * Update DB if required
	 *
	 * @return void
	 */
	public static function maybe_update_db() {
		if ( self::needs_db_update() ) {
			self::update();
		}
	}

	/**
	 * Run a update check during each release update.
	 *
	 * @return void
	 */
	private static function update() {
		$current_version = get_option( 'faz_cookie_consent_db_version', '3.0.7' );
		foreach ( self::$db_updates as $version => $callbacks ) {
			if ( version_compare( $current_version, $version, '<' ) ) {
				foreach ( $callbacks as $callback ) {
					self::$callback();
				}
			}
		}
	}

	/**
	 * Migrate existing banner contents to support new CCPA/GPC changes
	 *
	 * @return void
	 */
	public static function update_db_307() {
		$items = Controller::get_instance()->get_items();
		foreach ( $items as $item ) {
			$banner   = new Banner( $item->banner_id );
			$contents = $banner->get_contents();
			foreach ( $contents as $language => $content ) {
				$translation = $banner->get_translations( $language );
				$text        = isset( $translation['optoutPopup']['elements']['buttons']['elements']['confirm'] ) ? $translation['optoutPopup']['elements']['buttons']['elements']['confirm'] : 'Save My Preferences';
				$content['optoutPopup']['elements']['buttons']['elements']['confirm'] = $text;
				$contents[ $language ] = $content;
			}
			$banner->set_contents( $contents );
			$banner->save();
		}
	}

	public static function update_db_321() {
		$items = Controller::get_instance()->get_items();
		foreach ( $items as $item ) {
			$banner   = new Banner( $item->banner_id );
			$contents = $banner->get_contents();
			$settings = $banner->get_settings();
			if ( isset($contents['en']) ) {
				$translation = $banner->get_translations( 'en' );
				$text        = isset( $translation['optoutPopup']['elements']['gpcOption']['elements']['description'] ) ? $translation['optoutPopup']['elements']['gpcOption']['elements']['description'] : "<p>Your opt-out settings for this website have been respected since we detected a <b>Global Privacy Control</b> signal from your browser and, therefore, you cannot change this setting.</p>";
				$contents['en']['optoutPopup']['elements']['gpcOption']['elements']['description'] = $text;
			}
			if ( isset($settings['config']) ) {
				$settings['config']['preferenceCenter']['elements']['categories']['elements']['toggle']['status']=!$settings['config']['categoryPreview']['status'];
			}
			$banner->set_contents( $contents );
			$banner->set_settings( $settings );
			$banner->save();
		}
	}

	/**
	 * Fix MySQL schema compatibility for TEXT/LONGTEXT columns.
	 * Remove DEFAULT values from TEXT/LONGTEXT columns to prevent MySQL errors.
	 *
	 * @since 3.3.7
	 * @return void
	 */
	public static function update_db_337() {
		// Reset table version options to force schema update with corrected definitions
		delete_option( 'faz_banners_table_version' );
		delete_option( 'faz_cookie_table_version' );
		delete_option( 'faz_cookie_category_table_version' );

		// Reinstall tables with the corrected schema (without DEFAULT on TEXT/LONGTEXT columns)
		$controllers = array(
			'FazCookie\Admin\Modules\Banners\Includes\Controller',
			'FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller',
			'FazCookie\Admin\Modules\Cookies\Includes\Category_Controller',
		);

		foreach ( $controllers as $controller_class ) {
			if ( class_exists( $controller_class ) ) {
				$controller = $controller_class::get_instance();
				$controller->install_tables();
			}
		}
	}

	public static function update_db_340() {
		// Only run this migration for users who have migrated from legacy UI
		$migration_options = get_option( 'faz_migration_options', array() );
		$migration_status  = isset( $migration_options['status'] ) ? $migration_options['status'] : false;

		if ( ! $migration_status ) {
			return;
		}

		$items = Controller::get_instance()->get_items();
		foreach ( $items as $item ) {
			$banner   = new Banner( $item->banner_id );
			$settings = $banner->get_settings();
			$law      = $banner->get_law();

			// For CCPA banners, explicitly disable the accept button
			if ( 'ccpa' === $law ) {
				if ( isset( $settings['config']['notice']['elements']['buttons']['elements']['accept'] ) ) {
					$settings['config']['notice']['elements']['buttons']['elements']['accept']['status'] = false;
					$banner->set_settings( $settings );
					$banner->save();
				}
			} else {
				// For non-CCPA banners, enable the accept button if it's disabled
				if ( isset( $settings['config']['notice']['elements']['buttons']['elements']['accept']['status'] )
					&& false === $settings['config']['notice']['elements']['buttons']['elements']['accept']['status'] ) {
					$settings['config']['notice']['elements']['buttons']['elements']['accept']['status'] = true;
					$banner->set_settings( $settings );
					$banner->save();
				}
			}
		}
	}

	/**
	 * Force dbDelta to re-run on the banners table so new indexes are applied.
	 *
	 * @since 3.4.1
	 * @return void
	 */
	public static function update_db_341() {
		delete_option( 'faz_banners_table_version' );

		$controller_class = 'FazCookie\Admin\Modules\Banners\Includes\Controller';
		if ( class_exists( $controller_class ) ) {
			$controller_class::get_instance()->install_tables();
		}
	}

	/**
	 * Add target_countries (JSON array of ISO-3166 alpha-2 codes) and priority
	 * (int) columns to wp_faz_banners. Backfill existing rows with the
	 * "match-all" empty array (so the post-upgrade behaviour is identical to
	 * the single-banner mode) and ensure exactly one banner carries
	 * banner_default=1 (the fallback row used when no target matches).
	 *
	 * Idempotent: the column-add step uses dbDelta which no-ops if the columns
	 * already exist; the backfill step only touches rows whose target_countries
	 * is NULL or empty string (i.e. rows the column-add just introduced).
	 *
	 * @since 1.14.0
	 * @return void
	 */
	public static function update_db_350() {
		global $wpdb;

		// 1. Re-run install_tables so dbDelta picks up the new columns
		//    (`target_countries longtext`, `priority int(11)`).
		//    install() calls install_all_tables() BEFORE maybe_update_db(),
		//    which already set faz_banners_table_version = FAZ_VERSION. By
		//    the time this migration runs, install_tables()'s version-gate
		//    (`get_option(...) !== FAZ_VERSION`) is false and dbDelta never
		//    re-runs — the new columns would never be added. Mirror
		//    update_db_341() and clear the version option first so
		//    install_tables() actually invokes dbDelta.
		delete_option( 'faz_banners_table_version' );
		$controller_class = 'FazCookie\Admin\Modules\Banners\Includes\Controller';
		if ( class_exists( $controller_class ) ) {
			$controller_class::get_instance()->install_tables();
		}

		$table = $wpdb->prefix . 'faz_banners';

		// 1a. Safety net for MySQL 8.0 STRICT_TRANS_TABLES installs where
		//     dbDelta's `longtext NOT NULL` ADD COLUMN can refuse to
		//     materialise the new columns. Probe information_schema and
		//     issue an explicit ALTER + NULL-to-default backfill when
		//     dbDelta silently skipped them. Both columns are checked
		//     independently so a partial migration is detected.
		$schema = $wpdb->get_var( 'SELECT DATABASE()' );
		if ( $schema ) {
			$tc_exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					$schema,
					$wpdb->prefix . 'faz_banners',
					'target_countries'
				)
			);
			if ( 0 === $tc_exists ) {
				// F002/F009 fix: match the canonical CREATE TABLE schema in
				// class-controller.php which declares this column NOT NULL
				// (longtext NOT NULL). The pre-fix safety-net added it as
				// NULL-able, producing a nullability drift between fresh
				// installs (NOT NULL) and upgraded installs (NULL-able).
				//
				// F106 fix (1.14.3): the pre-fix one-shot
				// `ALTER … ADD COLUMN longtext NOT NULL` (no DEFAULT)
				// failed on MySQL with sql_mode=STRICT_TRANS_TABLES
				// (default 5.7+) when the table was non-empty, because
				// existing rows had no value to populate the new
				// non-nullable column. Use a 3-step idempotent path:
				//   1. ADD COLUMN as NULL-able (always succeeds).
				//   2. UPDATE backfill any NULL with '[]'.
				//   3. ALTER COLUMN to NOT NULL (now safe — no NULLs).
				// `longtext NOT NULL DEFAULT '[]'` would be one-shot but
				// MySQL pre-8.0.13 doesn't support DEFAULT on longtext
				// at all — strict mode + portability beats elegance.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-shot DDL on the plugin's custom table; $table = $wpdb->prefix . 'faz_banners' (no user input), column type is a fixed literal.
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `target_countries` longtext NULL" );
				// Backfill any NULL or empty-string rows with the
				// canonical empty-array value before tightening the
				// constraint. Skipped automatically when the table is
				// empty.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query( "UPDATE `{$table}` SET `target_countries` = '[]' WHERE `target_countries` IS NULL OR `target_countries` = ''" );
				// Now lock down to NOT NULL — every row has a value.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `target_countries` longtext NOT NULL" );
			}
			$pr_exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					$schema,
					$wpdb->prefix . 'faz_banners',
					'priority'
				)
			);
			if ( 0 === $pr_exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot DDL; column type + default are fixed literals.
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `priority` int(11) NOT NULL DEFAULT 0" );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot DDL.
				$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `priority` (`priority`)" );
			}
		}

		// 2. Backfill target_countries on rows that the column-add introduced.
		//    Empty JSON array '[]' means "match every visitor" — preserves the
		//    pre-upgrade behaviour where the banner showed to everyone gated
		//    only by geo_targeting on/off.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table identifier is $wpdb->prefix + literal "faz_banners"; values are bound via %s placeholders.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET `target_countries` = %s WHERE `target_countries` IS NULL OR `target_countries` = %s",
				'[]',
				''
			)
		);

		// 3. Ensure exactly ONE banner is the fallback default.
		//    Cases handled:
		//      - 0 defaults → promote the first status=1 row (the currently
		//        active banner pre-upgrade); if there's no active banner
		//        either, promote the lowest banner_id so the selector still
		//        has something to serve.
		//      - >1 defaults → reset every default flag, then promote a
		//        single canonical row (same selection rule as above).
		//        Multiple defaults make the last-resort fallback non-
		//        deterministic, so this case must be flattened too.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table identifier is $wpdb->prefix + literal "faz_banners"; values are bound via %d placeholder.
		$has_default = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(banner_id) FROM `{$table}` WHERE `banner_default` = %d",
				1
			)
		);
		if ( 1 !== $has_default ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table identifier is $wpdb->prefix + literal "faz_banners"; values are bound via %d placeholders.
			$fallback_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT banner_id FROM `{$table}` WHERE `status` = %d ORDER BY banner_id ASC LIMIT %d",
					1,
					1
				)
			);
			if ( $fallback_id <= 0 ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table identifier is $wpdb->prefix + literal "faz_banners"; value bound via %d placeholder.
				$fallback_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT banner_id FROM `{$table}` ORDER BY banner_id ASC LIMIT %d",
						1
					)
				);
			}
			if ( $fallback_id > 0 ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table identifier is $wpdb->prefix + literal "faz_banners"; value bound via %d placeholder.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$table}` SET `banner_default` = %d WHERE `banner_default` <> %d",
						0,
						0
					)
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot migration write to the custom faz_banners table; row identifier is the integer banner_id we just selected.
				$wpdb->update( $table, array( 'banner_default' => 1 ), array( 'banner_id' => $fallback_id ), array( '%d' ), array( '%d' ) );
			}
		}

		// F103 fix (1.14.3) + F303 fix (1.14.4): upgrade-path companion
		// to the `ENGINE=InnoDB` literals in get_schema(). dbDelta does
		// NOT migrate existing tables' storage engines — installs that
		// historically created these tables under a MyISAM default
		// would stay on MyISAM forever, defeating any START TRANSACTION
		// the controllers issue against them. MyISAM-on-InnoDB-host is
		// rare in 2026 but legacy AWS RDS parameter groups and
		// customised shared hosts still trip it.
		//
		// Tables that participate in transactional code paths:
		// - faz_banners: delete_item() wraps DELETE + promote_fallback
		// - faz_cookies + faz_cookie_categories: settings import in
		//   admin/modules/settings/api/class-api.php uses START
		//   TRANSACTION to wrap multi-row writes.
		$faz_innodb_tables = array(
			$wpdb->prefix . 'faz_banners',
			$wpdb->prefix . 'faz_cookies',
			$wpdb->prefix . 'faz_cookie_categories',
		);
		// R4-S004 fix (1.14.4): track per-table completion so a partial
		// failure (disk pressure, ROW_FORMAT incompatibility on legacy
		// MyISAM rows >8KB, lock-wait timeout on a busy table) doesn't
		// silently bump db_version past 1.14.3 and leave the failed
		// table stuck on MyISAM forever. We accumulate failures and
		// the caller (maybe_update_db) can compare the resulting
		// `faz_innodb_migration_pending` option on the next admin
		// load to detect incomplete migration and retry.
		$faz_innodb_failed = array();
		foreach ( $faz_innodb_tables as $faz_innodb_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$current_engine = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT `ENGINE` FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
					$faz_innodb_table
				)
			);
			if ( $current_engine && 'InnoDB' !== $current_engine ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$alter_result = $wpdb->query( "ALTER TABLE `{$faz_innodb_table}` ENGINE=InnoDB" );
				if ( false === $alter_result ) {
					$faz_innodb_failed[] = $faz_innodb_table;
				}
			}
		}
		if ( ! empty( $faz_innodb_failed ) ) {
			// Persist the failure list so a re-run of update_db_350 (on
			// next admin load, since maybe_update_db re-enters per
			// request) can target only the still-pending tables.
			// Storing as a non-autoloaded option keeps the hot path lean.
			update_option( 'faz_innodb_migration_pending', $faz_innodb_failed, false );
			if ( function_exists( 'error_log' ) ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'[FAZ Cookie Manager] InnoDB migration failed for: %s — settings-import transactions on these tables will be silent no-ops until the engine is converted.',
						implode( ', ', $faz_innodb_failed )
					)
				);
			}
		} else {
			// Clear any stale pending flag from prior partial runs.
			delete_option( 'faz_innodb_migration_pending' );
		}

		faz_clear_banner_template_cache();
	}

	/**
	 * Geo-routing v2 schema migration (spec 001 — task T015).
	 *
	 * Adds 7 NULL-default columns to wp_faz_consent_logs via online DDL.
	 * Delegated to \FazCookie\Includes\Migration_V2 (R4-S004 pattern):
	 *   - Probes MySQL version (5.7.6+ required for INPLACE, LOCK=NONE)
	 *   - Per-column ALTER with idempotent re-entry
	 *   - Persists `faz_geo_v2_migration_pending` on partial failure
	 *
	 * Constitution V Auditable Records: NULL on legacy rows is correct
	 * (pre-v2 visits had no geo context to capture).
	 *
	 * @since 1.15.0
	 * @return void
	 */
	public static function update_db_360() {
		$status = \FazCookie\Includes\Migration_V2::run();

		// Propagate non-success states so the updater pipeline can retry
		// instead of silently advancing past 3.6.0 with v2 still
		// half-applied. `faz_geo_v2_migration_complete` is consulted by
		// update_db_version() — when not true, the DB version option is
		// pinned to the previous milestone (3.5.0) so the next activator
		// invocation re-runs update_db_360() and Migration_V2::run() can
		// pick up where it left off (`faz_geo_v2_migration_pending`
		// already lists residual columns from the R4-S004 pattern).
		//
		// Notes:
		// - `ok`        → migration succeeded this run.
		// - `no_op`     → already complete on a previous run.
		// - `mysql_too_old` → admin explicitly opted out of v2 by virtue
		//                     of running below MIN_INNODB_VERSION; mark
		//                     complete so we don't loop the upgrader, but
		//                     leave `faz_geo_v2_disabled_reason` set so
		//                     consumer code knows to skip v2 columns.
		// - `partial`   → some columns added, others failed mid-ALTER;
		//                 keep the pipeline pinned at 3.5.0 so the next
		//                 activator run retries.
		// - `no_table`  → consent_logs table missing; downstream
		//                 install_all_tables() will create it, then the
		//                 next activator pass migrates.
		$complete = in_array( $status, array( 'ok', 'no_op', 'mysql_too_old' ), true );
		update_option( 'faz_geo_v2_migration_complete', $complete, false );

		if ( function_exists( 'error_log' ) && in_array( $status, array( 'mysql_too_old', 'partial', 'no_table' ), true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[FAZ Cookie Manager] geo-routing v2 migration status: %s. Check faz_geo_v2_migration_pending / faz_geo_v2_disabled_reason options for details.',
				$status
			) );
		}
	}

	/**
	 * Ensure the "uncategorized" cookie category exists.
	 */
	public static function ensure_uncategorized_category() {
		self::ensure_category_by_slug( 'uncategorized', array(
			'name'        => 'Uncategorized',
			'description' => 'Cookies that have not yet been categorized.',
		), false );
	}

	/**
	 * Fix uncategorized category to opt-out by default (GDPR compliance).
	 * Existing installs had prior_consent=1 (opt-in), which violates GDPR.
	 */
	public static function fix_uncategorized_prior_consent() {
		if ( get_option( 'faz_uncategorized_consent_fixed' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'faz_cookie_categories';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time SHOW TABLES probe in the activation/upgrade path; result is meaningful only at this moment and must not be cached.
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot data migration write to the plugin's custom `faz_cookie_categories` table; runs only on activation and is invalidated by Cache::invalidate_cache_group when the controller next reads the row.
			$wpdb->update(
				$table,
				array( 'prior_consent' => 0 ),
				array( 'slug' => 'uncategorized' ),
				array( '%d' ),
				array( '%s' )
			);
		}
		update_option( 'faz_uncategorized_consent_fixed', 1 );
	}

	/**
	 * One-time migration: enable readMore link and closeButton on the banner.
	 * GDPR requires a cookie policy link and a non-ambiguous way to dismiss.
	 */
	public static function fix_banner_gdpr_defaults() {
		if ( get_option( 'faz_banner_gdpr_defaults_fixed' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'faz_banners';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time SHOW TABLES probe in the activation/upgrade path.
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is $wpdb->prefix + literal "faz_banners" (escaped via esc_sql); one-time read inside the activation/upgrade migration runner — caching would defeat the purpose.
		$rows = $wpdb->get_results( "SELECT banner_id, settings FROM `" . esc_sql( $table ) . "`" );
		foreach ( $rows as $row ) {
			$settings = json_decode( $row->settings, true );
			if ( ! is_array( $settings ) ) {
				continue;
			}
			$changed = false;
			// Enable readMore link.
			if ( isset( $settings['config']['notice']['elements']['buttons']['elements']['readMore']['status'] )
				&& ! $settings['config']['notice']['elements']['buttons']['elements']['readMore']['status'] ) {
				$settings['config']['notice']['elements']['buttons']['elements']['readMore']['status'] = true;
				$changed = true;
			}
			// Enable close button.
			if ( isset( $settings['config']['notice']['elements']['closeButton']['status'] )
				&& ! $settings['config']['notice']['elements']['closeButton']['status'] ) {
				$settings['config']['notice']['elements']['closeButton']['status'] = true;
				$changed = true;
			}
			if ( $changed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot migration write to the custom faz_banners table; row identifier is the integer banner_id we just selected, value is JSON-encoded with `%s`. Cache is invalidated below by faz_clear_banner_template_cache().
				$wpdb->update(
					$table,
					array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
					array( 'banner_id' => $row->banner_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
		// Clear banner template cache (base + language variants) to force regeneration.
		faz_clear_banner_template_cache();
		update_option( 'faz_banner_gdpr_defaults_fixed', 1 );
	}

	/**
	 * Fix the brand logo URL in banner settings after moving cookie.png
	 * from plugin root to frontend/images/.
	 *
	 * Replaces any stored URL ending in /faz-cookie-manager/cookie.png
	 * with /faz-cookie-manager/frontend/images/cookie.png.
	 * Idempotent — runs once, guarded by option flag.
	 */
	public static function fix_brand_logo_path() {
		if ( get_option( 'faz_brand_logo_path_fixed' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'faz_banners';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time SHOW TABLES probe in the activation/upgrade path.
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is $wpdb->prefix + literal "faz_banners" (escaped via esc_sql); one-time read inside an activation-only migration that rewrites a stored URL prefix.
		$rows = $wpdb->get_results( "SELECT banner_id, settings FROM `" . esc_sql( $table ) . "`" );
		$old_suffix = '/faz-cookie-manager/cookie.png';
		$new_suffix = '/faz-cookie-manager/frontend/images/cookie.png';
		foreach ( $rows as $row ) {
			$settings = json_decode( $row->settings, true );
			if ( ! is_array( $settings ) ) {
				continue;
			}
			$url = isset( $settings['config']['notice']['elements']['brandLogo']['meta']['url'] )
				? $settings['config']['notice']['elements']['brandLogo']['meta']['url']
				: '';
			if ( $url && false !== strpos( $url, $old_suffix ) ) {
				$settings['config']['notice']['elements']['brandLogo']['meta']['url'] = str_replace(
					$old_suffix,
					$new_suffix,
					$url
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot migration write to the custom faz_banners table; banner_id comes from the SELECT just above. Cache invalidated by faz_clear_banner_template_cache() at the end of this function.
				$wpdb->update(
					$table,
					array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
					array( 'banner_id' => $row->banner_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
		// Clear banner template cache (base + language variants) to force regeneration with new URL.
		faz_clear_banner_template_cache();
		update_option( 'faz_brand_logo_path_fixed', 1, false );
	}

	/**
	 * Ensure the faz_cookie_categories.share_personal_data column exists.
	 *
	 * CPRA §1798.140(ah) distinguishes "sharing" (cross-context behavioural
	 * advertising) from a "sale". 1.17.2 adds a dedicated share_personal_data
	 * flag alongside sell_personal_data. dbDelta in install_tables() adds the
	 * column on the normal version-gated upgrade path, but this idempotent guard
	 * covers installs that were already on a 1.17.2 dev build (same table
	 * version, so dbDelta would not re-run) and any host where dbDelta skipped
	 * the ALTER. Existing rows get the schema default 1 (opt-out-able).
	 */
	public static function ensure_share_personal_data_column() {
		if ( get_option( 'faz_share_personal_data_column_added' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'faz_cookie_categories';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time SHOW TABLES probe in the activation/upgrade path.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is $wpdb->prefix + literal; SHOW COLUMNS probe, no user input.
		$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `" . esc_sql( $table ) . "` LIKE 'share_personal_data'" );
		if ( ! $has_column ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix + literal "faz_cookie_categories"; the column definition is a fixed literal (no user input); one-shot DDL on the activation/upgrade path.
			$result = $wpdb->query( "ALTER TABLE `" . esc_sql( $table ) . "` ADD COLUMN `share_personal_data` int(11) NOT NULL DEFAULT 1 AFTER `sell_personal_data`" );
			if ( false === $result ) {
				// The ALTER failed: throw so run_pending_migrations() does not mark
				// the migration set complete and the column is retried on the next
				// admin load, instead of permanently flagging it as added.
				throw new \RuntimeException( 'FAZ: failed to add the share_personal_data column; migration will retry.' );
			}
		}
		Category_Controller::get_instance()->delete_cache();
		update_option( 'faz_share_personal_data_column_added', 1, false );
	}

	/**
	 * Clear the CCPA opt-out flags on the always-exempt `necessary` category.
	 *
	 * sell_personal_data / share_personal_data both default to 1 at the schema
	 * level, so a seeded `necessary` row inherits sell/share = true even though
	 * strictly-necessary processing is never subject to a CPRA "Do Not Sell or
	 * Share" opt-out (and the admin editor hides those toggles for it). Left as
	 * 1/1 the row is serialized as `ccpaDoNotSell: true` to the frontend and
	 * carried through import/export, contradicting its always-exempt status.
	 * Normalise the stored row to 0/0 so every layer agrees. Idempotent — only
	 * writes when a value is actually non-zero. Runs after
	 * ensure_share_personal_data_column() so the column is guaranteed to exist.
	 */
	public static function clear_necessary_optout_flags() {
		global $wpdb;
		$table = $wpdb->prefix . 'faz_cookie_categories';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time SHOW TABLES probe in the activation/upgrade path.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		// Only `necessary` is normalised CATEGORICALLY here: strictly-necessary
		// cookies are never sold or shared under any configuration, so forcing
		// sell/share=0 on existing installs can't clobber a legitimate admin
		// choice. functional / wordpress-internal are seeded to 0 for NEW
		// installs (Category_Controller::load_default), but they are NOT
		// force-reset on existing installs — an admin may have deliberately
		// flagged a functional cookie as shared, and a migration must not
		// silently overwrite that explicit classification.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is $wpdb->prefix + literal "faz_cookie_categories" (escaped via esc_sql); slug bound via %s; one-shot idempotent migration write.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `" . esc_sql( $table ) . "` SET sell_personal_data = 0, share_personal_data = 0 WHERE slug = %s AND ( sell_personal_data <> 0 OR share_personal_data <> 0 )",
				'necessary'
			)
		);
		if ( false === $result ) {
			// Throw so run_pending_migrations() does not bump the version and the
			// normalisation is retried on the next admin load.
			throw new \RuntimeException( 'FAZ: failed to clear opt-out flags on the necessary category; migration will retry.' );
		}
		if ( $result > 0 ) {
			Category_Controller::get_instance()->delete_cache();
		}
	}

	/**
	 * Make the per-cookie consent ungating (1.20.0) a surprise-free upgrade.
	 *
	 * Per-cookie consent was settable before 1.18.2, then hard-gated: 1.18.2
	 * through 1.19.x forced banner_control.per_cookie_consent to false on every
	 * write, so any stored `true` is necessarily a stale pre-gate value the
	 * runtime was masking. 1.20.0 removes that mask and drives the feature from
	 * the saved setting, so without this reset such installs would silently
	 * re-activate the nested per-cookie toggles on upgrade. Reset the stale flag
	 * to false so per-cookie consent starts OFF for everyone and must be
	 * re-enabled explicitly. Fresh installs already default to false, and no
	 * deliberate current `true` can exist at 1.20.0 (the write-gate prevented
	 * it), so this only ever clears a legacy value on that one upgrade.
	 *
	 * A one-time marker (`faz_reset_stale_per_cookie_consent_done`) makes the
	 * reset fire exactly once: install() calls this on every version upgrade,
	 * so without the marker a later release would re-clear a per_cookie_consent
	 * an admin intentionally re-enabled after 1.20.0.
	 *
	 * @return void
	 */
	public static function reset_stale_per_cookie_consent() {
		if ( get_option( 'faz_reset_stale_per_cookie_consent_done' ) ) {
			return; // Already neutralised once — never clobber a later intentional choice.
		}
		$settings = get_option( 'faz_settings' );
		if ( ! is_array( $settings ) || empty( $settings['banner_control'] ) || ! is_array( $settings['banner_control'] ) ) {
			update_option( 'faz_reset_stale_per_cookie_consent_done', 1, false );
			return;
		}
		if ( ! empty( $settings['banner_control']['per_cookie_consent'] ) ) {
			$settings['banner_control']['per_cookie_consent'] = false;
			update_option( 'faz_settings', $settings );
		}
		update_option( 'faz_reset_stale_per_cookie_consent_done', 1, false );
	}

	/**
	 * Enable "Respect Global Privacy Control" on existing CCPA banners.
	 *
	 * CPPA Reg. §7025 requires a business subject to CCPA/CPRA to treat a GPC
	 * signal as a valid opt-out of the sale/sharing of personal information,
	 * with NO admin opt-in required. The frontend now honours GPC (see
	 * script.js::_fazGpcActive / _fazApplyGpcOptOut and behaviours.respectGPC),
	 * and the default CCPA banner configs ship with respectGPC enabled — but
	 * banners created before this release stored respectGPC.status = false.
	 * Flip them on so existing CCPA installs become §7025-compliant on upgrade.
	 *
	 * Scoped to banners whose applicableLaw is 'ccpa' so opt-in (GDPR-family)
	 * banners are untouched. Idempotent — guarded by an option flag and a
	 * value check, so it never rewrites a banner that is already correct.
	 */
	public static function enable_gpc_on_ccpa_banners() {
		if ( get_option( 'faz_ccpa_gpc_migrated' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'faz_banners';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time SHOW TABLES probe in the activation/upgrade path.
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is $wpdb->prefix + literal "faz_banners" (escaped via esc_sql); one-time read inside the activation/upgrade migration runner.
		$rows = $wpdb->get_results( "SELECT banner_id, settings FROM `" . esc_sql( $table ) . "`" );
		$had_failures = false;
		foreach ( $rows as $row ) {
			$settings = json_decode( $row->settings, true );
			if ( ! is_array( $settings ) ) {
				continue;
			}
			// Only touch CCPA banners (opt-out paradigm).
			$law = isset( $settings['settings']['applicableLaw'] ) ? (string) $settings['settings']['applicableLaw'] : 'gdpr';
			if ( 'ccpa' !== $law ) {
				continue;
			}
			$current = isset( $settings['behaviours']['respectGPC']['status'] )
				? (bool) $settings['behaviours']['respectGPC']['status']
				: false;
			if ( true === $current ) {
				continue; // Already compliant.
			}
			if ( ! isset( $settings['behaviours'] ) || ! is_array( $settings['behaviours'] ) ) {
				$settings['behaviours'] = array();
			}
			$settings['behaviours']['respectGPC'] = array( 'status' => true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot migration write to the custom faz_banners table; banner_id comes from the SELECT above, value JSON-encoded with %s. Caches invalidated below.
			$result = $wpdb->update(
				$table,
				array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
				array( 'banner_id' => $row->banner_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $result ) {
				// A write failed: stop and leave the migration flag unset so the
				// upgrade path retries on the next load. Already-updated rows are
				// re-skipped by the `true === $current` guard above (idempotent).
				$had_failures = true;
				break;
			}
		}
		// Invalidate the banner-controller item cache (epoch) and template cache
		// so any rows already updated above are served with the new respectGPC value.
		if ( class_exists( '\FazCookie\Admin\Modules\Banners\Includes\Controller' ) ) {
			\FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance()->delete_cache();
		}
		faz_clear_banner_template_cache();
		// Only mark the migration complete when every CCPA banner was flipped
		// successfully; otherwise a transient DB failure would permanently leave
		// some CCPA banners non-compliant with CPPA Reg. §7025. Throw on failure
		// so run_pending_migrations() does not bump the migration version and the
		// flip is retried on the next admin load (already-flipped banners are
		// re-skipped by the `true === $current` guard above, so this is idempotent).
		if ( $had_failures ) {
			throw new \RuntimeException( 'FAZ: failed enabling respectGPC on one or more CCPA banners; migration will retry.' );
		}
		update_option( 'faz_ccpa_gpc_migrated', 1, false );
	}

	/**
	 * Ensure the "wordpress-internal" cookie category exists.
	 */
	public static function ensure_wordpress_internal_category() {
		self::ensure_category_by_slug( 'wordpress-internal', array(
			'name'        => 'WordPress Internal',
			'description' => 'Cookies set by WordPress core for logged-in administrators. Not shown to site visitors.',
		), false, false );
	}

	/**
	 * Rename the legacy "advertisement" category slug to "marketing".
	 * Idempotent — skips if "marketing" already exists or "advertisement" is gone.
	 */
	public static function rename_advertisement_to_marketing() {
		if ( get_option( 'faz_migrated_advert_to_marketing' ) ) {
			return; // Already completed.
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'faz_cookie_categories';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is $wpdb->prefix + literal "faz_cookie_categories" (no user input); slug is bound via prepare(%s); one-shot migration query.
		$old_id  = $wpdb->get_var( $wpdb->prepare( "SELECT category_id FROM {$table} WHERE slug = %s LIMIT 1", 'advertisement' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- same as above.
		$new_id  = $wpdb->get_var( $wpdb->prepare( "SELECT category_id FROM {$table} WHERE slug = %s LIMIT 1", 'marketing' ) );

		// 1. Rename or merge category slug.
		if ( $old_id && ! $new_id ) {
			// Simple rename.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot migration write; slug literal, table is plugin-prefix.
			$wpdb->update( $table, array( 'slug' => 'marketing' ), array( 'slug' => 'advertisement' ) );
		} elseif ( $old_id && $new_id ) {
			// Both exist — reassign cookies from old to new, then delete legacy row.
			$cookies_table = $wpdb->prefix . 'faz_cookies';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot migration write; both old_id and new_id come from SELECTs above, $cookies_table is prefix+literal.
			$updated = $wpdb->update(
				$cookies_table,
				array( 'category' => (int) $new_id ),
				array( 'category' => (int) $old_id ),
				array( '%d' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				// Update failed — abort to avoid orphaning cookies.
				return;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot migration delete; category_id from SELECT.
			$deleted = $wpdb->delete( $table, array( 'category_id' => (int) $old_id ), array( '%d' ) );
			if ( false === $deleted ) {
				return;
			}
		}

		// 2. Fix display name: rename "Advertisement" → "Marketing" in all languages.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is $wpdb->prefix + literal; slug is bound via prepare(%s); migration-only read.
		$name_json = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE slug = %s LIMIT 1", 'marketing' ) );
		if ( $name_json ) {
			$names = json_decode( $name_json, true );
			if ( is_array( $names ) ) {
				$changed = false;
				foreach ( $names as $lang => $val ) {
					if ( 'Advertisement' === $val ) {
						$names[ $lang ] = 'Marketing';
						$changed        = true;
					}
				}
				if ( $changed ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot migration write; values JSON-encoded.
					$wpdb->update( $table, array( 'name' => wp_json_encode( $names ) ), array( 'slug' => 'marketing' ) );
				}
			}
		}

		// 3. Rename key in saved GCM region settings.
		$gcm = get_option( 'faz_gcm_settings' );
		if ( is_array( $gcm ) && ! empty( $gcm['default_settings'] ) && is_array( $gcm['default_settings'] ) ) {
			$changed = false;
			foreach ( $gcm['default_settings'] as &$region ) {
				if ( is_array( $region ) && isset( $region['advertisement'] ) && ! isset( $region['marketing'] ) ) {
					$region['marketing'] = $region['advertisement'];
					unset( $region['advertisement'] );
					$changed = true;
				}
			}
			unset( $region );
			if ( $changed ) {
				update_option( 'faz_gcm_settings', $gcm );
			}
		}

		update_option( 'faz_migrated_advert_to_marketing', 1, false );
	}

	/**
	 * Re-check unmatched IAB vendors after cookies are updated.
	 *
	 * Hooked to `faz_after_update_cookie` so the notice stays current
	 * when cookies are added, removed, or re-categorised.
	 *
	 * @return void
	 */
	public static function maybe_check_unmatched_vendors() {
		$settings = get_option( 'faz_settings' );
		if ( ! empty( $settings['iab']['enabled'] ) ) {
			$unmatched = self::detect_unmatched_vendors();
			if ( ! empty( $unmatched ) ) {
				set_transient( 'faz_unmatched_vendors', $unmatched, WEEK_IN_SECONDS );
			} else {
				delete_transient( 'faz_unmatched_vendors' );
			}
		}
	}

	/**
	 * Detect services found by the scanner that have a Known Provider entry
	 * but no matching GVL vendor selected.
	 *
	 * Compares detected cookie domains against Known Provider patterns,
	 * then checks whether a corresponding IAB GVL vendor is selected.
	 *
	 * @return array Array of unmatched service descriptors, each with
	 *               'service', 'category', and optional 'suggested' keys.
	 */
	public static function detect_unmatched_vendors() {
		$known        = Known_Providers::get_all();
		$selected_ids = (array) get_option( 'faz_gvl_selected_vendors', array() );

		// Get selected vendor names from GVL data.
		$gvl = Gvl::get_instance();
		if ( ! $gvl->has_data() ) {
			return array();
		}

		$all_vendors    = $gvl->get_vendors();
		$selected_names = array();
		foreach ( $selected_ids as $id ) {
			if ( isset( $all_vendors[ $id ] ) ) {
				$selected_names[] = strtolower( $all_vendors[ $id ]['name'] ?? '' );
			}
		}

		// Get detected cookie domains from the database.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot read of cookie domains during the post-scan IAB-vendor coverage check; values change on every scan so caching would mask the answer.
		$detected_domains = $wpdb->get_col(
			"SELECT DISTINCT domain FROM {$wpdb->prefix}faz_cookies WHERE domain != '' AND discovered = 1"
		);

		// For each Known Provider, check if it is detected on the site
		// but NOT covered by a selected GVL vendor.
		$unmatched = array();
		foreach ( $known as $service ) {
			// Check if any detected cookie domain matches this service's patterns.
			$is_detected = false;
			foreach ( $detected_domains as $domain ) {
				foreach ( $service['patterns'] as $pattern ) {
					if ( false !== stripos( $domain, $pattern ) || false !== stripos( $pattern, $domain ) ) {
						$is_detected = true;
						break 2;
					}
				}
			}

			if ( ! $is_detected ) {
				continue;
			}

			// Check if there is a matching GVL vendor selected.
			$service_name_lower = strtolower( $service['label'] );
			$has_vendor         = false;
			foreach ( $selected_names as $vname ) {
				if ( false !== strpos( $vname, $service_name_lower ) || false !== strpos( $service_name_lower, $vname ) ) {
					$has_vendor = true;
					break;
				}
			}

			if ( ! $has_vendor ) {
				// Try to find a matching GVL vendor to suggest.
				$suggested_vendor = null;
				foreach ( $all_vendors as $vid => $v ) {
					$vendor_name = strtolower( $v['name'] ?? '' );
					if ( false !== strpos( $vendor_name, $service_name_lower ) || false !== strpos( $service_name_lower, $vendor_name ) ) {
						$suggested_vendor = array(
							'id'   => absint( $vid ),
							'name' => $v['name'],
						);
						break;
					}
				}

				$unmatched[] = array(
					'service'   => $service['label'],
					'category'  => $service['category'],
					'suggested' => $suggested_vendor,
				);
			}
		}

		return $unmatched;
	}

	/**
	 * Create a cookie category if it does not already exist.
	 *
	 * @param string $slug          Category slug.
	 * @param array  $fallback_data Default name/description if not in Category_Controller defaults.
	 * @param bool   $prior_consent Whether prior consent is required. Default false.
	 * @param bool   $visibility    Whether visible on frontend. Default true.
	 */
	private static function ensure_category_by_slug( $slug, $fallback_data, $prior_consent = false, $visibility = true ) {
		$category_controller = Category_Controller::get_instance();
		$categories          = $category_controller->get_items();
		foreach ( $categories as $cat ) {
			if ( $slug === $cat->slug ) {
				return; // Already exists.
			}
		}
		$lang     = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
		$defaults = Category_Controller::get_defaults();
		$data     = isset( $defaults[ $slug ] ) && is_array( $defaults[ $slug ] )
			? array_merge( $fallback_data, $defaults[ $slug ] )
			: $fallback_data;

		$object = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories();
		$object->set_name( array( $lang => $data['name'] ) );
		$object->set_description( array( $lang => $data['description'] ) );
		$object->set_slug( $slug );
		$object->set_prior_consent( $prior_consent );
		$object->set_visibility( $visibility );
		$object->save();
	}
}
