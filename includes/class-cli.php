<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie
 * @subpackage FazCookie/includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FazCookie\Includes\Loader;
use FazCookie\Includes\I18n;
use FazCookie\Includes\DSAR_Shortcode;
use FazCookie\Admin\Admin;
use FazCookie\Frontend\Frontend;
use FazCookie\Admin\Modules\Settings\Includes\Settings;

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      3.0.0
 * @package    FazCookie
 * @subpackage FazCookie/Includes
 * @author     Fabio D'Alessandro
 */
class CLI {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    3.0.0
	 * @access   protected
	 * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    3.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    3.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Initial version of plugin database.
	 *
	 * Since 1.9.4 we've started to store cookie database version on the plugin.
	 *
	 * @var string
	 */
	public static $db_initial_version = '1.9.4';

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    3.0.0
	 */
	public function __construct() {
		if ( defined( 'FAZ_VERSION' ) ) {
			$this->version = FAZ_VERSION;
		} else {
			$this->version = '1.0.5';
		}
		$this->plugin_name = 'faz-cookie-manager';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->register_blocks();
		$this->register_privacy_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Loader. Orchestrates the hooks of the plugin.
	 * - I18n. Defines internationalization functionality.
	 * - Admin. Defines all hooks for the admin area.
	 * - Frontend. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    3.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-utils.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-formatting.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-i18n-helpers.php';
		$this->loader = new \FazCookie\Includes\Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the I18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    3.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = I18n::get_instance();
		$this->loader->add_action( 'init', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    3.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Admin( $this->get_version() );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    3.0.0
	 * @access   private
	 */
	private function define_public_hooks() {
		// Register DSAR CPT unconditionally — the post type must be present on
		// admin requests so post.php?post=…&action=edit edit links for stored
		// DSAR requests resolve to a registered post type.
		new DSAR_Shortcode();

		// Invalidate the frontend-output caches on every cookie / category /
		// settings write, registered UNCONDITIONALLY here — the plugin's single
		// always-run composition root. These transients (the cookie-scripts map
		// and the per-service detected-cookie-names set, both consumed by the
		// frontend store payload) must be busted even when the write happens on
		// a pure-admin page request that skips the Frontend constructor below.
		// Registering the bust inside Frontend would miss exactly that case.
		$faz_bust_frontend_caches = static function () {
			\delete_transient( 'faz_cookie_scripts_map' );
			\delete_transient( 'faz_detected_cookie_names' );
			\delete_transient( 'faz_server_cookie_category_map' );
			// Versioned sibling: the map gained a wordpress-internal filter, so
			// the key was bumped to stop a stale unfiltered map from answering
			// for an hour after deploy. Both are deleted while installs that
			// upgrade mid-hour may still hold the old one.
			\delete_transient( 'faz_server_cookie_category_map_v2' );
			// The Open Cookie Database memo answers the same question one tier
			// lower, so it must expire on the same events: a reclassified cookie
			// or category has to win over a memoized fallback verdict.
			\delete_transient( 'faz_server_cookie_definition_map' );
		};
		foreach ( array(
			'faz_after_update_cookie',
			'faz_after_create_cookie',
			'faz_after_delete_cookie',
			'faz_after_update_cookie_category',
			'faz_after_delete_cookie_category',
			'faz_after_update_settings',
			'faz_clear_cache',
		) as $faz_cache_bust_hook ) {
			\add_action( $faz_cache_bust_hook, $faz_bust_frontend_caches );
		}

		// Upgrade cleanup for the abandoned two-key cache design. Older 1.27.0
		// builds baked `faz-law` into FlyingPress's pre-WordPress drop-in; merely
		// removing our filter does not rewrite that generated file, so the stale
		// cookie would continue fragmenting the cache until the next FlyingPress
		// settings save. Repair it on the first admin request when present.
		\add_action( 'admin_init', array( self::class, 'remove_legacy_flyingpress_law_vary' ), 1 );

		// Skip frontend initialization on admin page requests — none of the
		// frontend hooks (wp_footer, wp_enqueue_scripts, template_redirect,
		// etc.) fire in admin context, so the object creation is wasted work.
		// We must NOT skip on REST API or AJAX requests because the
		// Consent_Logger registers REST routes through the Frontend class.
		if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! self::is_rest_url() ) {
			return;
		}
		new Frontend( $this->get_plugin_name(), $this->get_version() );
	}

	/**
	 * Rebuild FlyingPress's drop-in when it still contains the retired
	 * jurisdiction cookie cache key.
	 *
	 * @since 1.27.0
	 * @return void
	 */
	public static function remove_legacy_flyingpress_law_vary() {
		// admin_init is not an administrator-only hook: it fires on every
		// authenticated wp-admin hit (a Subscriber loading profile.php) and on
		// every admin-ajax.php request, logged-out ones included. This routine
		// rewrites a filesystem drop-in and writes options, so it must be
		// reachable only by someone entitled to change site configuration.
		// current_user_can() is pluggable, but wp-settings.php loads
		// pluggable.php long before init — let alone admin_init — so guarding it
		// with function_exists() would only hide a broken bootstrap.
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! \defined( 'WP_CONTENT_DIR' )
			|| ! \class_exists( '\FlyingPress\AdvancedCache' )
			|| ! \method_exists( '\FlyingPress\AdvancedCache', 'add_advanced_cache' )
		) {
			return;
		}
		$dropin = WP_CONTENT_DIR . ( \class_exists( 'Atomic_Persistent_Data' )
			? '/flying-press-advanced-cache.php'
			: '/advanced-cache.php' );
		if ( ! \is_readable( $dropin ) ) {
			return;
		}
		$marker_option = 'faz_flyingpress_law_vary_repaired';
		$fingerprint   = self::flyingpress_dropin_fingerprint( $dropin );
		if ( '' === $fingerprint ) {
			// The drop-in passed is_readable() but could not be stat'ed, so it
			// vanished underneath us. Without an identity there is nothing to
			// record an outcome against, and every following admin request would
			// redo the whole scan; bail and let a later request re-evaluate.
			return;
		}
		if ( $fingerprint === (string) \get_option( $marker_option, '' ) ) {
			return;
		}
		$contents = \file_get_contents( $dropin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local generated drop-in, read once during admin cleanup.
		if ( ! \is_string( $contents )
			|| false === \strpos( $contents, 'FlyingPress' )
		) {
			return;
		}
		if ( false === \strpos( $contents, 'faz-law' ) ) {
			\update_option( $marker_option, $fingerprint, false );
			return;
		}

		// add_option() is an atomic claim in WordPress's options table. It keeps
		// simultaneous admin/admin-ajax requests from rewriting the generated
		// drop-in concurrently. A stale claim is recoverable after five minutes.
		$lock_option = 'faz_flyingpress_law_vary_repairing';
		$now         = \time();
		if ( ! \add_option( $lock_option, $now, '', false ) ) {
			$started = (int) \get_option( $lock_option, 0 );
			if ( $started > $now - 300 ) {
				return;
			}
			\delete_option( $lock_option );
			if ( ! \add_option( $lock_option, $now, '', false ) ) {
				return;
			}
		}
		try {
			\FlyingPress\AdvancedCache::add_advanced_cache();
		} catch ( \Throwable $e ) {
			// Best-effort migration: never break wp-admin because a third-party
			// cache plugin could not rewrite its generated drop-in. The marker is
			// still recorded below, so a build that throws on every call cannot
			// turn this cleanup into a rewrite on every admin request.
			unset( $e );
		} finally {
			\clearstatcache( true, $dropin );
			// Record the attempt against the drop-in's identity AFTER the
			// rewrite, whether or not `faz-law` actually disappeared. Marking
			// only on success left a drop-in we cannot repair being regenerated
			// on every single admin request, forever. Because the marker is a
			// content fingerprint and not a boolean one-shot, a later genuine
			// FlyingPress regeneration changes it and re-arms the attempt.
			// is_readable() first: FlyingPress may have unlinked the drop-in
			// instead of rewriting it, and stat'ing a missing path would only
			// add a warning to the log. No identity means no marker — the next
			// admin request bails at the readability check above anyway.
			$attempted = \is_readable( $dropin ) ? self::flyingpress_dropin_fingerprint( $dropin ) : '';
			if ( '' !== $attempted ) {
				\update_option( $marker_option, $attempted, false );
			}
			\delete_option( $lock_option );
		}
	}

	/**
	 * Collision-resistant identity for a generated FlyingPress drop-in.
	 *
	 * A later FlyingPress regeneration changes the marker and re-enables the
	 * safety scan, unlike a permanent boolean one-shot flag.
	 *
	 * @param string $dropin Absolute drop-in path.
	 * @return string
	 */
	private static function flyingpress_dropin_fingerprint( $dropin ) {
		$mtime = \filemtime( $dropin );
		$size  = \filesize( $dropin );
		$digest = \hash_file( 'sha256', $dropin );
		if ( false === $mtime || false === $size || false === $digest ) {
			return '';
		}
		return (string) $mtime . ':' . (string) $size . ':' . $digest;
	}

	/**
	 * Check if the current request URL targets the REST API.
	 *
	 * REST_REQUEST is not defined yet during plugins_loaded, so we
	 * also check the request URI as a fallback.
	 *
	 * @return bool
	 */
	private static function is_rest_url() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$rest_prefix = rest_get_url_prefix();
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		return false !== strpos( $request_uri, '/' . $rest_prefix . '/' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    3.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     3.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     3.0.0
	 * @return    Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     3.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Register Gutenberg blocks.
	 *
	 * Blocks are loaded on every request (admin + frontend) so that
	 * the editor can register them and the frontend can render them.
	 *
	 * @return void
	 */
	private function register_blocks() {
		new \FazCookie\Includes\Blocks\Blocks();
	}

	/**
	 * Register WordPress privacy tools hooks (Export/Erase Personal Data).
	 *
	 * Adds privacy policy suggested content, a personal data exporter,
	 * and a personal data eraser for consent logs.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	private function register_privacy_hooks() {
		// Register privacy policy suggested content.
		add_action( 'admin_init', function () {
			if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
				return;
			}
			$content = sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p>',
				esc_html__( 'Cookie Consent (FAZ Cookie Manager)', 'faz-cookie-manager' ),
				esc_html__( 'This site uses the FAZ Cookie Manager plugin to collect and manage cookie consent. When you interact with the cookie banner, we record your consent choice (accepted, rejected, or partial), a hashed version of your IP address, your browser user agent, and the page URL where consent was given. This data is stored locally on our server and retained for the period configured in the plugin settings (default: 12 months).', 'faz-cookie-manager' ),
				esc_html__( 'You can change your cookie preferences at any time using the cookie icon in the bottom corner of the page.', 'faz-cookie-manager' )
			);
			wp_add_privacy_policy_content( 'FAZ Cookie Manager', $content );
		});

		// Consume every plugin's registered privacy-policy content (FAZ's own
		// included) into the faz_privacy_content_snapshot option. Runs only on
		// genuine wp-admin requests, on FAZ screens — see Content_Collector.
		\FazCookie\Admin\Modules\Privacy_Policy_Generator\Includes\Content_Collector::register_hooks();

		// Register personal data exporter.
		add_filter( 'wp_privacy_personal_data_exporters', function ( $exporters ) {
			$exporters['faz-cookie-manager'] = array(
				'exporter_friendly_name' => __( 'FAZ Cookie Manager — Consent Logs & Privacy Requests', 'faz-cookie-manager' ),
				'callback'               => 'faz_privacy_exporter',
			);
			return $exporters;
		});

		// Register personal data eraser.
		add_filter( 'wp_privacy_personal_data_erasers', function ( $erasers ) {
			$erasers['faz-cookie-manager'] = array(
				'eraser_friendly_name' => __( 'FAZ Cookie Manager — Consent Logs & Privacy Requests', 'faz-cookie-manager' ),
				'callback'             => 'faz_privacy_eraser',
			);
			return $erasers;
		});
	}

}
