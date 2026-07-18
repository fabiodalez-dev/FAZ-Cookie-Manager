<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @package    FazCookie\Admin
 */

namespace FazCookie\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }


/**
 * The admin-specific functionality of the plugin.
 *
 * @package    FazCookie
 * @subpackage FazCookie/admin
 */
class Admin {

	/**
	 * Admin menu slug prefix.
	 */
	private const ADMIN_SLUG = 'faz-cookie-manager';

	/**
	 * The version of this plugin.
	 *
	 * @since    3.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Admin modules of the plugin
	 *
	 * @var array
	 */
	private static $modules;

	/**
	 * Currently active modules
	 *
	 * @var array
	 */
	private static $active_modules;

	/**
	 * Existing modules
	 *
	 * @var array
	 */
	public static $existing_modules;

	/**
	 * Submenu pages config.
	 *
	 * @var array
	 */
	private $pages;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    3.0.0
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $version ) {
		$this->version = $version;
		self::$modules = $this->get_default_modules();
		$this->load();
		$this->maybe_load_modules();
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_network_menu' ) );
		}
		add_action( 'admin_init', array( $this, 'load_plugin' ) );
		// Emit no-cache headers EARLY on every plugin admin page — runs in
		// admin_init (priority 1) before any module can produce output that
		// flips headers_sent() to true. Previously this lived only inside
		// render_page() which fires too late: pages that boot a list-table
		// or print a notice on admin_init already sent their headers by the
		// time render_page() tried to add the LSCache opt-out.
		add_action( 'admin_init', array( $this, 'send_admin_nocache_headers' ), 1 );
		add_action( 'activated_plugin', array( $this, 'handle_activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'deregister_api_fetch' ), 0 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_classes' ) );
		// Issue #107 — defensive guard for the WP-core wp-auth-check JS error
		// ("Cannot read properties of undefined (reading 'hasClass')") that
		// fires on FAZ admin pages whose custom-rendered template can race
		// the core script's expectation of #wp-auth-check-wrap being in the
		// DOM. Inject an empty placeholder when missing — the core script's
		// jQuery selector then resolves cleanly and the warning surface
		// stays available. Override via the existing `wp_auth_check_load`
		// filter if a site prefers to disable wp-auth-check on FAZ pages
		// entirely. Compatible with ClassicPress 1.x (admin_print_footer_scripts
		// is a WP 2.x action, no WP 6.x-only API used).
		add_action( 'admin_print_footer_scripts', array( $this, 'ensure_wp_auth_check_wrap' ), 1 );
		add_action( 'admin_notices', array( $this, 'woocommerce_compat_notice' ) );
		add_action( 'admin_notices', array( $this, 'cookie_definitions_notice' ) );
		add_action( 'admin_notices', array( $this, 'scheduled_scan_notice' ) );
		add_action( 'admin_notices', array( $this, 'unmatched_vendors_notice' ) );
		add_action( 'admin_notices', array( $this, 'redundant_geo_routing_notice' ) );
		add_action( 'wp_ajax_faz_dismiss_unmatched', array( $this, 'ajax_dismiss_unmatched_vendors' ) );
		add_action( 'wp_ajax_faz_disable_redundant_geo_routing', array( $this, 'ajax_disable_redundant_geo_routing' ) );
		add_action( 'wp_ajax_faz_dismiss_redundant_geo_routing', array( $this, 'ajax_dismiss_redundant_geo_routing' ) );
		add_filter( 'plugin_action_links_' . FAZ_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'rest_api_init', array( $this, 'add_rest_nocache_headers' ) );
	}

	/**
	 * Load activator on each load.
	 *
	 * @return void
	 */
	public function load() {
		\FazCookie\Includes\Activator::init();
	}

	/**
	 * Get the default modules array.
	 *
	 * @return array
	 */
	public function get_default_modules() {
		return array(
			'settings',
			'gcm',
			'gvl',
			'languages',
			'dashboard',
			'banners',
			'cookies',
			'consentlogs',
			'scanner',
			'pageviews',
			'cache',
		);
	}

	/**
	 * Decide whether to load modules immediately or defer them.
	 *
	 * Modules register REST API routes and admin_init hooks. On non-FAZ,
	 * non-REST admin pages (e.g. WP Dashboard, Posts editor) none of this
	 * work is needed. Deferring module instantiation on those pages avoids
	 * instantiating 11 module classes, 11 API classes, and registering
	 * 49 REST routes on every admin request.
	 *
	 * @return void
	 */
	private function maybe_load_modules() {
		// REST API requests need routes registered — always load.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$this->load_modules();
			return;
		}
		// AJAX requests may target FAZ endpoints — always load.
		if ( wp_doing_ajax() ) {
			$this->load_modules();
			return;
		}
		// On admin pages, check if this is a FAZ page (early, before get_current_screen).
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( false !== strpos( $page, 'faz-cookie-manager' ) ) {
			$this->load_modules();
			return;
		}
		// Deferred load: hook into rest_api_init so routes are still registered
		// if WordPress processes a REST request that was not detected above
		// (e.g. internal REST calls without REST_REQUEST defined).
		add_action( 'rest_api_init', array( $this, 'load_modules' ), 1 );
	}

	/**
	 * Load all the modules.
	 *
	 * @return void
	 */
	public function load_modules() {
		// Prevent double-loading when called from both maybe_load_modules and rest_api_init.
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		foreach ( self::$modules as $module ) {
			$parts      = explode( '_', $module );
			$class      = implode( '_', $parts );
			$class_name = 'FazCookie\\Admin\\Modules\\' . ucfirst( $module ) . '\\' . ucfirst( $class );

			if ( class_exists( $class_name ) ) {
				$module_obj = new $class_name( $module );
				if ( $module_obj instanceof $class_name ) {
					if ( $module_obj->is_active() ) {
						self::$active_modules[ $module ] = true;
					}
				}
			}
		}
	}

	/**
	 * Admin page definitions.
	 *
	 * @return array
	 */
	private function get_admin_pages() {
		return array(
			'dashboard'    => array(
				'title' => __( 'Dashboard', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG,
				'view'  => 'dashboard',
			),
			'banner'       => array(
				'title' => __( 'Cookie Banner', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-banner',
				'view'  => 'banner',
			),
			'cookies'      => array(
				'title' => __( 'Cookies', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-cookies',
				'view'  => 'cookies',
			),
			'consent-logs' => array(
				'title' => __( 'Consent Logs', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-consent-logs',
				'view'  => 'consent-logs',
			),
			'gcm'          => array(
				'title' => __( 'Google Consent Mode', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-gcm',
				'view'  => 'gcm',
			),
			'languages'    => array(
				'title' => __( 'Languages', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-languages',
				'view'  => 'languages',
			),
			'gvl'          => array(
				'title' => __( 'Vendor List (IAB)', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-gvl',
				'view'  => 'gvl',
			),
			'geo-routing'  => array(
				'title' => __( 'Geo-routing', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-geo-routing',
				'view'  => 'geo-routing',
			),
			'cookie-policy' => array(
				'title' => __( 'Cookie Policy', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-cookie-policy',
				'view'  => 'cookie-policy',
			),
			'settings'     => array(
				'title' => __( 'Settings', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-settings',
				'view'  => 'settings',
			),
			'import-export' => array(
				'title' => __( 'Import / Export', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-import-export',
				'view'  => 'import-export',
			),
			'system-status' => array(
				'title' => __( 'System Status', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-system-status',
				'view'  => 'system-status',
			),
		);
	}

	/**
	 * Check if running on ClassicPress.
	 *
	 * @return bool
	 */
	private function is_classicpress() {
		return function_exists( 'classicpress_version' );
	}

	/**
	 * Deregister the native wp-api-fetch on ClassicPress admin pages.
	 *
	 * ClassicPress ships a WP 4.9 build of wp-api-fetch that lacks
	 * createRootURLMiddleware, crashing the page before any plugin JS runs.
	 * We replace it with an empty stub and provide our own polyfill.
	 *
	 * @return void
	 */
	/**
	 * Inject an empty #wp-auth-check-wrap placeholder when the WP-core
	 * `wp_auth_check_html` action did not render one. WordPress'
	 * `wp-auth-check.min.js` calls `.hasClass()` on the result of a
	 * selector that returns undefined when the wrap is missing — fires
	 * the console TypeError reported in issue #107 on FAZ admin pages.
	 *
	 * The placeholder is empty + display:none; the real wrap (if WP ever
	 * renders it later in the same request) is appended after and its
	 * markup is independent. ClassicPress 1.x supports both `wp_auth_check`
	 * and the placeholder div pattern.
	 *
	 * @return void
	 */
	public function ensure_wp_auth_check_wrap() {
		if ( false === faz_is_admin_page() ) {
			return;
		}
		if ( ! function_exists( 'wp_script_is' ) || ! wp_script_is( 'wp-auth-check', 'enqueued' ) ) {
			return;
		}
		// Output a no-op placeholder. The id selector is what the core
		// script reads; the rest of the markup (#wp-auth-check, .form,
		// the iframe) is rendered by the core wp_auth_check_html action
		// itself when present. The placeholder is harmless if the real
		// wrap is also present — wp-auth-check.js only reads the first
		// match.
		echo '<div id="wp-auth-check-wrap" class="hidden" style="display:none" aria-hidden="true"></div>';
	}

	public function deregister_api_fetch() {
		if ( false === faz_is_admin_page() || ! $this->is_classicpress() ) {
			return;
		}

		// The polyfill file ships only in the GitHub-full release ZIP. The
		// wp.org variant excludes it because Plugin Check fingerprints it as
		// `library_core_files` (it re-implements `wp-includes/js/dist/api-fetch.js`).
		// On the wp.org build, a ClassicPress visitor reaching this point
		// has the native (WP 4.9-era) `wp-api-fetch` already enqueued by
		// core — leaving it in place is the least-bad outcome (admin pages
		// that depend on `createRootURLMiddleware` will degrade, but the
		// rest of the admin keeps working). ClassicPress users who need the
		// full FAZ admin experience should grab the GitHub `-full` ZIP.
		$polyfill_path = plugin_dir_path( __FILE__ ) . 'assets/js/cp-api-fetch-polyfill.js';
		if ( ! file_exists( $polyfill_path ) ) {
			return;
		}

		wp_dequeue_script( 'wp-api-fetch' );
		wp_deregister_script( 'wp-api-fetch' );

		// Re-register the `wp-api-fetch` handle so it points at our static
		// polyfill file (admin/assets/js/cp-api-fetch-polyfill.js). The
		// nonce + REST URL the polyfill needs are passed via
		// `wp_localize_script()` as the `fazApiFetchConfig` global, so the
		// JS file itself is fully static and cacheable. Loaded in the
		// document head (`$in_footer = false`) so any consumer that calls
		// `wp.apiFetch(...)` early in the page lifecycle still works.
		wp_register_script(
			'wp-api-fetch',
			plugin_dir_url( __FILE__ ) . 'assets/js/cp-api-fetch-polyfill.js',
			array(),
			FAZ_VERSION,
			false
		);
		wp_localize_script(
			'wp-api-fetch',
			'fazApiFetchConfig',
			array(
				'restUrl' => rest_url(),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
		// Enqueue immediately on FAZ admin pages so the polyfill is on the
		// page even when no other plugin script declares it as a
		// dependency. Other handles that already list `wp-api-fetch` as a
		// dep will get it via dependency resolution as before.
		wp_enqueue_script( 'wp-api-fetch' );
	}

	/**
	 * Script dependencies — uses native wp-api-fetch on WordPress,
	 * none on ClassicPress (polyfill injected separately).
	 *
	 * @return array
	 */
	private function get_script_dependencies() {
		// Always depend on wp-api-fetch: native on WP, polyfill-carrying stub on CP.
		return array( 'wp-api-fetch' );
	}

	/**
	 * Legacy entrypoint — superseded by `deregister_api_fetch()`.
	 *
	 * Kept only as a no-op so any third-party code that grabbed the public
	 * method reference through an action callback list does not crash. The
	 * polyfill is now delivered as a static JS file (cp-api-fetch-polyfill.js)
	 * registered against the `wp-api-fetch` handle in `deregister_api_fetch()`,
	 * which removes the previous inline `<script>` echo (WordPress.Security.
	 * EscapeOutput.OutputNotEscaped error in Plugin Check) and lets browser
	 * caches and cache plugins handle the file like any other admin asset.
	 *
	 * @return void
	 */
	public function print_api_fetch_polyfill() {
		// Intentional no-op. Keep until at least one major release after
		// 1.13.8 to allow downstream code that hooked this method to shift
		// to the new flow without sudden `do_action` failures.
		return;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    3.0.0
	 */
	public function enqueue_styles() {
		if ( false === faz_is_admin_page() ) {
			return;
		}
		wp_enqueue_style(
			'faz-admin',
			plugin_dir_url( __FILE__ ) . 'assets/css/faz-admin.css',
			array(),
			$this->version
		);
		// WordPress dashicons (for icon support in quick links, etc.).
		wp_enqueue_style( 'dashicons' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    3.0.0
	 */
	public function enqueue_scripts() {
		if ( false === faz_is_admin_page() ) {
			return;
		}

		// Core utilities — wp-api-fetch on WordPress, polyfill on ClassicPress.
		wp_enqueue_script(
			'faz-admin',
			plugin_dir_url( __FILE__ ) . 'assets/js/faz-admin.js',
			$this->get_script_dependencies(),
			$this->version,
			true
		);

		// ClassicPress wp.apiFetch polyfill is delivered as a static file
		// (`admin/assets/js/cp-api-fetch-polyfill.js`) re-registered against
		// the `wp-api-fetch` handle in `deregister_api_fetch()`. Loaded in
		// the head, before any consumer.

		// Localize config data for JS.
		wp_localize_script(
			'faz-admin',
			'fazConfig',
			array(
				'api'            => array(
					'base'  => rest_url( 'faz/v1/' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				),
				'site'           => array(
					'url'        => get_site_url(),
					'name'       => esc_attr( get_option( 'blogname' ) ),
					'previewUrl' => add_query_arg(
						array(
							'faz_banner_preview' => '1',
						),
						set_url_scheme( home_url( '/' ) )
					),
				),
				'assetsURL'      => defined( 'FAZ_PLUGIN_URL' ) ? FAZ_PLUGIN_URL . 'frontend/images/' : '',
				'defaultLogo'    => plugins_url( 'frontend/images/cookie.png', FAZ_PLUGIN_FILENAME ),
				'adminURL'       => admin_url( 'admin.php' ),
				'isClassicPress' => $this->is_classicpress(),
				'upload'         => array(
					'mediaEndpoint' => rest_url( 'wp/v2/media' ),
					'maxSize'       => wp_max_upload_size(),
				),
				'multilingual'   => faz_i18n_is_multilingual() && count( faz_selected_languages() ) > 0,
				'languages'      => array(
					'selected' => faz_selected_languages(),
					'default'  => faz_default_language(),
				),
				'version'        => $this->version,
				'modules'        => self::$active_modules,
				'canEditScripts' => current_user_can( 'unfiltered_html' ),
				'locale'         => get_user_locale(),
				'i18n'           => array(
					// Dashboard widget strings (used by dashboard.js).
					'accepted'                 => __( 'Accepted', 'faz-cookie-manager' ),
					'rejected'                 => __( 'Rejected', 'faz-cookie-manager' ),
					'totalResponses'           => __( 'total responses', 'faz-cookie-manager' ),
					// Common.
					'saved'                    => __( 'Settings saved successfully.', 'faz-cookie-manager' ),
					'saveFailed'               => __( 'Failed to save settings.', 'faz-cookie-manager' ),
					'loadFailed'               => __( 'Failed to load settings.', 'faz-cookie-manager' ),
					// Default loading-button label for FAZ.btnLoading (faz-admin.js)
					// when a call site passes no explicit label.
					'saving'                   => __( 'Saving…', 'faz-cookie-manager' ),
					'confirmDelete'            => __( 'Are you sure you want to delete this?', 'faz-cookie-manager' ),
					// (banner-status toggle strings live in the consolidated 'banner'
				// sub-array further down — kept together to avoid PHP's last-key-wins
				// silently dropping these entries when a later `'banner' => array(…)`
				// is appended in the same parent array.)
					// Cookies page.
					'cookies'                  => array(
						// Cookie modal field labels (issue #97 — these used to be
						// hardcoded English in admin/assets/js/pages/cookies.js;
						// 1.13.17 wraps them through __() so they participate in
						// the same translation pipeline as the opt-in / opt-out
						// script labels rendered alongside them).
						'nameLabel'                => __( 'Cookie Name', 'faz-cookie-manager' ),
						'domainLabel'              => __( 'Domain', 'faz-cookie-manager' ),
						'durationLabel'            => __( 'Duration', 'faz-cookie-manager' ),
						'durationPlaceholder'      => __( 'e.g. 1 year', 'faz-cookie-manager' ),
						'descriptionLabel'         => __( 'Description', 'faz-cookie-manager' ),
						'bulkDeleteConfirm'        => __( 'Delete selected cookie(s)?', 'faz-cookie-manager' ),
						'bulkDeleteFailed'         => __( 'Bulk delete failed.', 'faz-cookie-manager' ),
						'categoriesSaved'          => __( 'Categories saved.', 'faz-cookie-manager' ),
						'deleteAllStale'           => __( 'Delete all stale', 'faz-cookie-manager' ),
						'allCookies'               => __( 'All Cookies', 'faz-cookie-manager' ),
						'hidden'                   => __( 'hidden', 'faz-cookie-manager' ),
						'hiddenFromFrontend'       => __( 'Hidden from frontend', 'faz-cookie-manager' ),
						/* translators: %d: number of seconds until retry */
						'serverBusyRetrying'       => __( 'Server busy, retrying in %ds...', 'faz-cookie-manager' ),
						/* translators: %d: total number of pages to scan */
						'scanningPages'            => __( 'Scanning 0/%d pages...', 'faz-cookie-manager' ),
						'rulePlaceholder'          => __( 'e.g. custom-tracker.com/script.js', 'faz-cookie-manager' ),
						/* translators: %1$d: number of rules added, %2$s: template name */
						'rulesAdded'               => __( 'Added %1$d rules from %2$s (saved)', 'faz-cookie-manager' ),
						/* translators: %s: template name */
						'allCookiesExist'          => __( 'All cookies from %s already exist', 'faz-cookie-manager' ),
						'noCookiesFound'           => __( 'No cookies found.', 'faz-cookie-manager' ),
						'edit'                     => __( 'Edit', 'faz-cookie-manager' ),
						'delete'                   => __( 'Delete', 'faz-cookie-manager' ),
						'deleteStale'              => __( 'Delete stale', 'faz-cookie-manager' ),
						'category'                 => __( 'Category', 'faz-cookie-manager' ),
						'cancel'                   => __( 'Cancel', 'faz-cookie-manager' ),
						'cookieUpdated'            => __( 'Cookie updated.', 'faz-cookie-manager' ),
						'cookieAdded'              => __( 'Cookie added.', 'faz-cookie-manager' ),
						'cookieSaveFailed'         => __( 'Failed to save cookie.', 'faz-cookie-manager' ),
						/* translators: %s: cookie name */
						'cookieDeleteConfirm'      => __( 'Delete cookie "%s"?', 'faz-cookie-manager' ),
						'cookieDeleted'            => __( 'Cookie deleted.', 'faz-cookie-manager' ),
						'cookieDeleteFailed'       => __( 'Failed to delete cookie.', 'faz-cookie-manager' ),
						'staleDeleted'             => __( 'Stale cookie deleted.', 'faz-cookie-manager' ),
						'staleDeleteFailed'        => __( 'Failed to delete stale cookie.', 'faz-cookie-manager' ),
						// Manual service registration dropdown (#161).
						'selectService'            => __( 'Select a service…', 'faz-cookie-manager' ),
						'servicesLoadFailed'       => __( 'Could not load services', 'faz-cookie-manager' ),
						/* translators: 1: service label, 2: number of cookies registered. */
						'serviceRegistered'        => __( '%1$s: %2$d cookie(s) registered', 'faz-cookie-manager' ),
						'registerFailed'           => __( 'Could not register service.', 'faz-cookie-manager' ),
						'staleAllConfirm'          => __( 'Delete all stale cookies not found in the latest scan?', 'faz-cookie-manager' ),
						'staleNone'                => __( 'No stale cookies to delete.', 'faz-cookie-manager' ),
						'staleDeleteAllFailed'     => __( 'Failed to delete stale cookies.', 'faz-cookie-manager' ),
						'staleLoadFailed'          => __( 'Failed to load cookies for stale cleanup.', 'faz-cookie-manager' ),
						'scanStarted'              => __( 'Scanning...', 'faz-cookie-manager' ),
						'scanSite'                 => __( 'Scan Site', 'faz-cookie-manager' ),
						'discoveringPages'         => __( 'Discovering pages...', 'faz-cookie-manager' ),
						'enrichingServer'          => __( 'Enriching with server scan...', 'faz-cookie-manager' ),
						'savingResults'            => __( 'Saving results...', 'faz-cookie-manager' ),
						'noCookiesToProcess'       => __( 'No cookies to process.', 'faz-cookie-manager' ),
						'noUncategorized'          => __( 'No uncategorized cookies to process.', 'faz-cookie-manager' ),
						'noneAutoCategorized'      => __( 'No cookies could be auto-categorized.', 'faz-cookie-manager' ),
						'autoCatFailed'            => __( 'Auto-categorize failed.', 'faz-cookie-manager' ),
						'noDefinitions'            => __( 'No definitions downloaded yet. Click "Update Definitions" to download.', 'faz-cookie-manager' ),
						'definitionsLoadFailed'    => __( 'Could not load definitions status.', 'faz-cookie-manager' ),
						'downloadingDefinitions'   => __( 'Downloading definitions from GitHub...', 'faz-cookie-manager' ),
						'definitionsUpdated'       => __( 'Definitions updated.', 'faz-cookie-manager' ),
						'definitionsFailed'        => __( 'Update failed.', 'faz-cookie-manager' ),
						'definitionsNetworkFailed' => __( 'Update failed. Check your network connection.', 'faz-cookie-manager' ),
						'select'                   => __( '— Select —', 'faz-cookie-manager' ),
						'remove'                   => __( 'Remove', 'faz-cookie-manager' ),
						'rulesIncomplete'          => __( 'rule(s) incomplete — fill in both pattern and category.', 'faz-cookie-manager' ),
						'rulesSaved'               => __( 'Custom rules saved.', 'faz-cookie-manager' ),
						'rulesSaveFailed'          => __( 'Failed to save custom rules.', 'faz-cookie-manager' ),
						'noTemplates'              => __( 'No templates available.', 'faz-cookie-manager' ),
						'templateEmpty'            => __( 'No patterns or cookies in template.', 'faz-cookie-manager' ),
						'templateCatNotFound'      => __( 'not found — cookies not added.', 'faz-cookie-manager' ),
						'templateCookiesFailed'    => __( 'Failed to create cookies from template.', 'faz-cookie-manager' ),
						'templateLoadFailed'       => __( 'Failed to load templates.', 'faz-cookie-manager' ),
						'shortcodeCopied'          => __( 'Shortcode copied!', 'faz-cookie-manager' ),
						'noScanLogs'               => __( 'No scan logs available.', 'faz-cookie-manager' ),
						'debugLogDownloadFailed'   => __( 'Failed to download debug log.', 'faz-cookie-manager' ),
						'clearDebugLogsConfirm'    => __( 'Clear all scanner debug logs?', 'faz-cookie-manager' ),
						'debugLogsCleared'         => __( 'Debug logs cleared.', 'faz-cookie-manager' ),
						'debugLogsClearFailed'     => __( 'Failed to clear debug logs.', 'faz-cookie-manager' ),
					),
					// Banner page.
					'banner'                   => array(
						/* translators: %s: preset name */
						'presetApplied'            => __( 'Preset applied: %s', 'faz-cookie-manager' ),
						'loadFailed'               => __( 'Failed to load banner settings.', 'faz-cookie-manager' ),
						'saved'                    => __( 'Banner settings saved.', 'faz-cookie-manager' ),
						'saveFailed'               => __( 'Failed to save banner settings.', 'faz-cookie-manager' ),
						// Banner-status toggle (loadBannerEnabledToggle on the Banner page).
						'enabled'                  => __( 'Cookie banner enabled.', 'faz-cookie-manager' ),
						'disabled'                 => __( 'Cookie banner disabled.', 'faz-cookie-manager' ),
						'toggleFailed'             => __( 'Failed to update banner status.', 'faz-cookie-manager' ),
						// Colour-contrast checker (Colours tab, WCAG SC 1.4.3).
						'contrastTitle'            => __( 'Accessibility: low colour contrast', 'faz-cookie-manager' ),
						'contrastIntro'            => __( 'These colour pairs fall below the WCAG AA 4.5:1 minimum and may be hard to read:', 'faz-cookie-manager' ),
						'cTitle'                   => __( 'Title vs banner background', 'faz-cookie-manager' ),
						'cDesc'                    => __( 'Description vs banner background', 'faz-cookie-manager' ),
						'cLink'                    => __( 'Link vs banner background', 'faz-cookie-manager' ),
						'cAccept'                  => __( 'Accept button text vs its background', 'faz-cookie-manager' ),
						'cReject'                  => __( 'Reject button text vs its background', 'faz-cookie-manager' ),
						'cSettings'                => __( 'Settings button text vs its background', 'faz-cookie-manager' ),
						'cSave'                    => __( 'Save button text vs its background', 'faz-cookie-manager' ),
						'cCatLabel'                => __( 'Category label vs banner background', 'faz-cookie-manager' ),
						'cDoNotSell'               => __( 'Do Not Sell text vs banner background', 'faz-cookie-manager' ),
					),
					// Settings page.
					'settings'                 => array(
						'loadFailed'               => __( 'Failed to load settings.', 'faz-cookie-manager' ),
						'saved'                    => __( 'Settings saved successfully.', 'faz-cookie-manager' ),
						'saveFailed'               => __( 'Failed to save settings.', 'faz-cookie-manager' ),
						'gvlUpdated'               => __( 'GVL updated.', 'faz-cookie-manager' ),
						'gvlFailed'                => __( 'Failed to update GVL.', 'faz-cookie-manager' ),
						'geoipNoKey'               => __( 'Please enter a MaxMind license key first.', 'faz-cookie-manager' ),
						'geoipUpdated'             => __( 'GeoIP database updated successfully.', 'faz-cookie-manager' ),
						'geoipFailed'              => __( 'Failed to update database.', 'faz-cookie-manager' ),
						'noGvlData'                => __( 'No GVL data downloaded yet. Click "Update GVL Now" to download.', 'faz-cookie-manager' ),
						'noGvlAvailable'           => __( 'No GVL data available.', 'faz-cookie-manager' ),
						'gvlVersion'               => __( 'GVL Version: ', 'faz-cookie-manager' ),
						'gvlVendors'               => __( 'Vendors: ', 'faz-cookie-manager' ),
						'gvlLastUpdated'           => __( 'Last Updated: ', 'faz-cookie-manager' ),
						'dbLabel'                  => __( 'Database: ', 'faz-cookie-manager' ),
						'dbFileInfo'               => __( '{file} ({size} KB) - Last updated: {date}', 'faz-cookie-manager' ),
						'gvlUpdatedWithMeta'       => __( 'GVL updated: v{version} ({count} vendors)', 'faz-cookie-manager' ),
						'noGeoipDb'                => __( 'No GeoIP database installed. Enter your license key and click "Update Database".', 'faz-cookie-manager' ),
					),
					// GCM page.
					'gcm'                      => array(
						'loadFailed'               => __( 'Failed to load GCM settings.', 'faz-cookie-manager' ),
						'saved'                    => __( 'GCM settings saved successfully.', 'faz-cookie-manager' ),
						'saveFailed'               => __( 'Failed to save GCM settings.', 'faz-cookie-manager' ),
					),
					// Consent logs page.
					'consentLogs'              => array(
						/* translators: %1$s: start index, %2$s: end index, %3$s: total entries */
						'showing'                  => __( 'Showing %1$s\u2013%2$s of %3$s', 'faz-cookie-manager' ),
						'loadFailed'               => __( 'Failed to load consent logs.', 'faz-cookie-manager' ),
						'noLogs'                   => __( 'No consent logs found.', 'faz-cookie-manager' ),
						'exportOk'                 => __( 'CSV exported successfully.', 'faz-cookie-manager' ),
						'exportFailed'             => __( 'Failed to export CSV.', 'faz-cookie-manager' ),
						'prev'                     => __( '← Prev', 'faz-cookie-manager' ),
						'next'                     => __( 'Next →', 'faz-cookie-manager' ),
					),
					// Languages page.
					'languages'                => array(
						'loadFailed'               => __( 'Failed to load languages.', 'faz-cookie-manager' ),
						'noLanguages'              => __( 'No languages selected. Add one below.', 'faz-cookie-manager' ),
						'atLeastOne'               => __( 'At least one language must be selected.', 'faz-cookie-manager' ),
						'added'                    => __( 'Added', 'faz-cookie-manager' ),
						'noResults'                => __( 'No languages found.', 'faz-cookie-manager' ),
						/* translators: %s: language name */
						'removeLanguage'           => __( 'Remove %s', 'faz-cookie-manager' ),
						'saved'                    => __( 'Languages saved successfully.', 'faz-cookie-manager' ),
						'saveFailed'               => __( 'Failed to save languages.', 'faz-cookie-manager' ),
					),
					// GVL page.
					'gvl'                      => array(
						'noData'                   => __( 'No GVL data downloaded yet. Click "Update GVL Now" to download.', 'faz-cookie-manager' ),
						'loadFailed'               => __( 'Failed to load GVL status.', 'faz-cookie-manager' ),
						'vendorsLoadFailed'        => __( 'Failed to load vendors. Make sure GVL is downloaded.', 'faz-cookie-manager' ),
						'noVendors'                => __( 'No vendors found.', 'faz-cookie-manager' ),
						'vendorDetailFailed'       => __( 'Failed to load vendor details.', 'faz-cookie-manager' ),
						/* translators: %1$d: current page, %2$d: total pages, %3$d: total vendor count */
						'pagination'               => __( 'Page %1$d of %2$d (%3$d vendors)', 'faz-cookie-manager' ),
						/* translators: %d: number of selected vendors (singular) */
						'selectedVendor'           => __( 'Selected: %d vendor', 'faz-cookie-manager' ),
						/* translators: %d: number of selected vendors (plural) */
						'selectedVendors'          => __( 'Selected: %d vendors', 'faz-cookie-manager' ),
						/* translators: %d: number of vendors saved */
						'savedCount'               => __( 'Saved %d vendor(s).', 'faz-cookie-manager' ),
						'selectedLoadFailed'       => __( 'Could not load your saved selection — reload before changing it.', 'faz-cookie-manager' ),
						'notHydrated'              => __( 'Your saved selection has not loaded yet — reload the page before saving.', 'faz-cookie-manager' ),
						'selectionSaved'           => __( 'vendor(s) saved.', 'faz-cookie-manager' ),
						'selectionSavedWithCount'  => __( 'Saved {count} vendor(s).', 'faz-cookie-manager' ),
						'selectionFailed'          => __( 'Failed to save selection.', 'faz-cookie-manager' ),
						'updated'                  => __( 'GVL updated.', 'faz-cookie-manager' ),
						'updatedWithMeta'          => __( 'GVL updated: v{version} ({count} vendors)', 'faz-cookie-manager' ),
						'updateFailed'             => __( 'Failed to update GVL.', 'faz-cookie-manager' ),
						'version'                  => __( 'GVL Version: ', 'faz-cookie-manager' ),
						'vendors'                  => __( 'Vendors: ', 'faz-cookie-manager' ),
						'lastUpdated'              => __( 'Last Updated: ', 'faz-cookie-manager' ),
						// Auto-detect-vendors-from-cookie-scan button feedback.
						'autoDetectScanning'       => __( 'Scanning cookie inventory…', 'faz-cookie-manager' ),
						'autoDetectHydrating'      => __( 'Loading saved selection…', 'faz-cookie-manager' ),
						'autoDetectNoGvl'          => __( 'Update the Global Vendor List first, then try Auto-detect again.', 'faz-cookie-manager' ),
						'autoDetectNoScan'         => __( 'No scanner data yet. Run the cookie scanner first.', 'faz-cookie-manager' ),
						'autoDetectNoMatch'        => __( 'No matching ad-tech vendors were found in the scanned cookies.', 'faz-cookie-manager' ),
						/* translators: %d: number of vendors pre-ticked from the cookie scan */
						'autoDetectAdded'          => __( 'Pre-ticked %d vendor(s) from cookie scan. Click Save Selection to apply.', 'faz-cookie-manager' ),
						/* translators: %d: number of auto-detected vendors already in the selection */
						'autoDetectAllAlready'     => __( 'All %d auto-detected vendor(s) were already in your selection.', 'faz-cookie-manager' ),
						/* translators: %1$d: number of newly pre-ticked vendors, %2$d: number already selected */
						'autoDetectMixed'          => __( 'Pre-ticked %1$d new vendor(s), %2$d were already selected. Click Save Selection to apply.', 'faz-cookie-manager' ),
						'autoDetectFailed'         => __( 'Auto-detect failed. Check the cookie scanner and try again.', 'faz-cookie-manager' ),
					),
					// Import/Export page.
					'importExport'             => array(
						'exporting'                => __( 'Exporting...', 'faz-cookie-manager' ),
						'exportOk'                 => __( 'Settings exported successfully.', 'faz-cookie-manager' ),
						'exportFailed'             => __( 'Export failed.', 'faz-cookie-manager' ),
						'invalidJson'              => __( 'Invalid JSON file.', 'faz-cookie-manager' ),
						'notFazExport'             => __( 'This file is not a FAZ Cookie Manager export.', 'faz-cookie-manager' ),
						'importConfirm'            => __( 'This will overwrite your current settings. Continue?', 'faz-cookie-manager' ),
						'importing'                => __( 'Importing...', 'faz-cookie-manager' ),
						'importOk'                 => __( 'Import completed successfully. Reloading...', 'faz-cookie-manager' ),
						'importedOk'               => __( 'Settings imported successfully.', 'faz-cookie-manager' ),
						/* translators: %s: low-level error message surfaced from the import endpoint */
						'importFailed'             => __( 'Import failed: %s', 'faz-cookie-manager' ),
					),
					// Dashboard page.
					'dashboard'                => array(
						'selectBothDates'          => __( 'Please select both start and end dates.', 'faz-cookie-manager' ),
						'startBeforeEnd'           => __( 'Start date must be before end date.', 'faz-cookie-manager' ),
						'noCategoryData'           => __( 'No category data yet.', 'faz-cookie-manager' ),
					),
					// System Status page.
					'systemStatus'             => array(
						'copied'                   => __( 'Status copied to clipboard!', 'faz-cookie-manager' ),
					),
					// Geo-routing page (admin/assets/js/pages/geo-routing.js).
					'geo'                      => array(
						// Overrides panel.
						'country'                      => __( 'Country', 'faz-cookie-manager' ),
						'rulesetOverride'              => __( 'Ruleset override', 'faz-cookie-manager' ),
						'deltaFields'                  => __( 'Delta fields', 'faz-cookie-manager' ),
						'action'                       => __( 'Action', 'faz-cookie-manager' ),
						'delete'                       => __( 'Delete', 'faz-cookie-manager' ),
						'autoDetect'                   => __( '(auto-detect)', 'faz-cookie-manager' ),
						/* translators: %d: number of country overrides configured */
						'overridesConfiguredSingular'  => __( '%d override configured.', 'faz-cookie-manager' ),
						/* translators: %d: number of country overrides configured */
						'overridesConfiguredPlural'    => __( '%d overrides configured.', 'faz-cookie-manager' ),
						'addOverride'                  => __( 'Add override', 'faz-cookie-manager' ),
						'noOverrides'                  => __( 'No per-country overrides configured. Rule-sets are auto-resolved per country and US state for preview and reference only.', 'faz-cookie-manager' ),
						'confirmDelete'                => __( 'Remove this override?', 'faz-cookie-manager' ),
						// Pipeline status panel.
						'runtimeApplicationLabel'      => __( 'Runtime rule-set application', 'faz-cookie-manager' ),
						'runtimeApplicationActive'     => __( '✅ active', 'faz-cookie-manager' ),
						'runtimeApplicationOff'        => __( '⚪ off — catalogue is preview/reference only', 'faz-cookie-manager' ),
						'catalogRulesets'              => __( 'Catalog rulesets', 'faz-cookie-manager' ),
						'fallbackRuleset'              => __( 'Fallback ruleset', 'faz-cookie-manager' ),
						'ipinfoOptin'                  => __( 'ipinfo opt-in', 'faz-cookie-manager' ),
						'ipinfoApiKey'                 => __( 'ipinfo API key', 'faz-cookie-manager' ),
						'schemaMigration'              => __( 'Schema migration v2', 'faz-cookie-manager' ),
						'pendingColumns'               => __( 'Pending columns', 'faz-cookie-manager' ),
						'migrationDisabled'            => __( 'Migration disabled', 'faz-cookie-manager' ),
						'statusActive'                 => __( '✅ active', 'faz-cookie-manager' ),
						'statusDisabled'               => __( '⚪ disabled', 'faz-cookie-manager' ),
						'statusConfigured'             => __( '✅ configured', 'faz-cookie-manager' ),
						'statusNotSet'                 => __( '⚪ not set', 'faz-cookie-manager' ),
						'statusComplete'               => __( '✅ complete', 'faz-cookie-manager' ),
						'statusIncomplete'             => __( '⚠️ incomplete', 'faz-cookie-manager' ),
						// Preview panel.
						'resolvedRuleset'              => __( 'Resolved ruleset', 'faz-cookie-manager' ),
						'fullRulesetJson'              => __( 'Full ruleset JSON', 'faz-cookie-manager' ),
						// ipinfo panel.
						'settingsSaved'                => __( 'Settings saved.', 'faz-cookie-manager' ),
						'apiKeyLabel'                  => __( 'API key', 'faz-cookie-manager' ),
						'apiKeyStored'                 => __( '(stored — leave blank to keep)', 'faz-cookie-manager' ),
						'apiKeyPlaceholder'            => __( 'token from ipinfo.io/account/token', 'faz-cookie-manager' ),
						'enableIpinfo'                 => __( 'Enable ipinfo.io VPN detection', 'faz-cookie-manager' ),
						'attestDpfScc'                 => __( 'I attest to having a DPF / SCC / DPA agreement with ipinfo.io for cross-border data transfer of EU/UK visitor IPs (required for opt-in)', 'faz-cookie-manager' ),
						// PIPL panel.
						'piplAttestText'               => __( 'I attest to having a Standard Contract (PIPL Art. 38) or CAC security assessment (Art. 40) for cross-border data transfers OF data subject to PIPL, OR to not process any data that requires such mechanisms.', 'faz-cookie-manager' ),
						/* translators: %1$s: localised timestamp, %2$s: WP user id of the attesting admin */
						'piplAttestedAt'               => __( 'Attested at %1$s by user ID %2$s', 'faz-cookie-manager' ),
						// Common buttons.
						'save'                         => __( 'Save', 'faz-cookie-manager' ),
						/* translators: %s: low-level error message from the REST endpoint */
						'errorPrefix'                  => __( 'Error: %s', 'faz-cookie-manager' ),
					),
					// Cookie Policy generator (admin/assets/js/pages/cookie-policy.js).
					'cookiePolicy'             => array(
						'loadFailed'               => __( 'Load failed', 'faz-cookie-manager' ),
						'saving'                   => __( 'Saving…', 'faz-cookie-manager' ),
						'saved'                    => __( 'Saved.', 'faz-cookie-manager' ),
						'saveFailed'               => __( 'Save failed', 'faz-cookie-manager' ),
						'previewFailed'            => __( 'Preview failed', 'faz-cookie-manager' ),
						'initFailed'               => __( 'The generator could not start on this page. Please reload; if it persists, a plugin or theme conflict is likely.', 'faz-cookie-manager' ),
						// Auto-detect-from-cookie-scan button + Detected badge.
						'svcDetectedBadge'         => __( 'Detected', 'faz-cookie-manager' ),
						'svcDetectedTooltip'       => __( 'The cookie scanner observed a tracking domain for this service on your site.', 'faz-cookie-manager' ),
						'svcAutoDetectScanning'    => __( 'Scanning cookie inventory…', 'faz-cookie-manager' ),
						'svcAutoDetectNoScan'      => __( 'No scanner data yet. Run the cookie scanner first.', 'faz-cookie-manager' ),
						'svcAutoDetectNoMatch'     => __( 'No matching services found among scanned cookies.', 'faz-cookie-manager' ),
						/* translators: %d: number of detected services already selected */
						'svcAutoDetectAllAlready'  => __( 'All %d detected service(s) are already selected.', 'faz-cookie-manager' ),
						'svcAutoDetectNoneAdded'   => __( 'Detected services left unticked, as you set them.', 'faz-cookie-manager' ),
						/* translators: %1$d: number of newly pre-ticked services, %2$d: number of services already selected */
						'svcAutoDetectDone'        => __( 'Pre-ticked %1$d new service(s), %2$d were already selected. Click Save to commit.', 'faz-cookie-manager' ),
						'svcAutoDetectFailed'      => __( 'Auto-detect failed', 'faz-cookie-manager' ),
						// Group headings.
						'grpAnalytics'             => __( 'Analytics', 'faz-cookie-manager' ),
						'grpHeatmaps'              => __( 'Heatmaps & session recording', 'faz-cookie-manager' ),
						'grpAdPixels'              => __( 'Advertising pixels', 'faz-cookie-manager' ),
						'grpCdn'                   => __( 'CDN, edge & performance', 'faz-cookie-manager' ),
						'grpAntibot'               => __( 'Anti-bot & forms', 'faz-cookie-manager' ),
						'grpEmbeds'                => __( 'Maps, embeds & media', 'faz-cookie-manager' ),
						'grpChat'                  => __( 'Chat & support', 'faz-cookie-manager' ),
						'grpEmail'                 => __( 'Email & marketing automation', 'faz-cookie-manager' ),
						'grpPayments'              => __( 'Payments & commerce', 'faz-cookie-manager' ),
						'grpSignin'                => __( 'Social sign-in & auth', 'faz-cookie-manager' ),
						'grpMonitoring'            => __( 'Error & RUM monitoring', 'faz-cookie-manager' ),
						'grpAbtest'                => __( 'Personalisation & A/B testing', 'faz-cookie-manager' ),
						'grpPush'                  => __( 'Push notifications', 'faz-cookie-manager' ),
						// Service labels (brand names — verbatim, but t()-routed so
						// translators may attach a gloss for less-known names).
						// Analytics
						'svcGa4'                   => __( 'Google Analytics 4', 'faz-cookie-manager' ),
						'svcGtm'                   => __( 'Google Tag Manager', 'faz-cookie-manager' ),
						'svcMatomo'                => __( 'Matomo Analytics', 'faz-cookie-manager' ),
						'svcPlausible'             => __( 'Plausible Analytics', 'faz-cookie-manager' ),
						'svcMixpanel'              => __( 'Mixpanel', 'faz-cookie-manager' ),
						'svcAmplitude'             => __( 'Amplitude', 'faz-cookie-manager' ),
						'svcHeap'                  => __( 'Heap', 'faz-cookie-manager' ),
						'svcFathom'                => __( 'Fathom Analytics', 'faz-cookie-manager' ),
						'svcStatcounter'           => __( 'Statcounter', 'faz-cookie-manager' ),
						// Heatmaps
						'svcHotjar'                => __( 'Hotjar', 'faz-cookie-manager' ),
						'svcClarity'               => __( 'Microsoft Clarity', 'faz-cookie-manager' ),
						'svcMouseflow'             => __( 'Mouseflow', 'faz-cookie-manager' ),
						'svcSmartlook'             => __( 'Smartlook', 'faz-cookie-manager' ),
						'svcLuckyorange'           => __( 'Lucky Orange', 'faz-cookie-manager' ),
						'svcFullstory'             => __( 'FullStory', 'faz-cookie-manager' ),
						'svcLogrocket'             => __( 'LogRocket', 'faz-cookie-manager' ),
						'svcCrazyegg'              => __( 'Crazy Egg', 'faz-cookie-manager' ),
						// Advertising
						'svcGads'                  => __( 'Google Ads', 'faz-cookie-manager' ),
						'svcMeta'                  => __( 'Meta (Facebook) Pixel', 'faz-cookie-manager' ),
						'svcTiktok'                => __( 'TikTok Pixel', 'faz-cookie-manager' ),
						'svcLinkedin'              => __( 'LinkedIn Insight Tag', 'faz-cookie-manager' ),
						'svcMsuet'                 => __( 'Microsoft UET', 'faz-cookie-manager' ),
						'svcTwitter'               => __( 'Twitter (X) Pixel', 'faz-cookie-manager' ),
						'svcPinterest'             => __( 'Pinterest Tag', 'faz-cookie-manager' ),
						'svcReddit'                => __( 'Reddit Pixel', 'faz-cookie-manager' ),
						'svcSnap'                  => __( 'Snapchat Pixel', 'faz-cookie-manager' ),
						'svcQuora'                 => __( 'Quora Pixel', 'faz-cookie-manager' ),
						'svcOutbrain'              => __( 'Outbrain', 'faz-cookie-manager' ),
						'svcTaboola'               => __( 'Taboola', 'faz-cookie-manager' ),
						'svcCriteo'                => __( 'Criteo', 'faz-cookie-manager' ),
						// CDN
						'svcCf'                    => __( 'Cloudflare', 'faz-cookie-manager' ),
						'svcFastly'                => __( 'Fastly', 'faz-cookie-manager' ),
						'svcAkamai'                => __( 'Akamai', 'faz-cookie-manager' ),
						'svcCloudfront'            => __( 'Amazon CloudFront', 'faz-cookie-manager' ),
						'svcBunnycdn'              => __( 'BunnyCDN', 'faz-cookie-manager' ),
						'svcJsdelivr'              => __( 'jsDelivr', 'faz-cookie-manager' ),
						// Anti-bot
						'svcRecaptcha'             => __( 'Google reCAPTCHA', 'faz-cookie-manager' ),
						'svcHcaptcha'              => __( 'hCaptcha', 'faz-cookie-manager' ),
						'svcTurnstile'             => __( 'Cloudflare Turnstile', 'faz-cookie-manager' ),
						'svcAkismet'               => __( 'Akismet', 'faz-cookie-manager' ),
						// Embeds
						'svcGmaps'                 => __( 'Google Maps', 'faz-cookie-manager' ),
						'svcMapbox'                => __( 'Mapbox', 'faz-cookie-manager' ),
						'svcOsm'                   => __( 'OpenStreetMap', 'faz-cookie-manager' ),
						'svcYoutube'               => __( 'YouTube (embed)', 'faz-cookie-manager' ),
						'svcVimeo'                 => __( 'Vimeo (embed)', 'faz-cookie-manager' ),
						'svcTwitterembed'          => __( 'Twitter / X (embed)', 'faz-cookie-manager' ),
						'svcInstagram'             => __( 'Instagram (embed)', 'faz-cookie-manager' ),
						'svcSpotify'               => __( 'Spotify (embed)', 'faz-cookie-manager' ),
						'svcSoundcloud'            => __( 'SoundCloud (embed)', 'faz-cookie-manager' ),
						'svcWistia'                => __( 'Wistia', 'faz-cookie-manager' ),
						'svcBrightcove'            => __( 'Brightcove', 'faz-cookie-manager' ),
						'svcJwplayer'              => __( 'JW Player', 'faz-cookie-manager' ),
						// Chat
						'svcIntercom'              => __( 'Intercom', 'faz-cookie-manager' ),
						'svcZendesk'               => __( 'Zendesk Chat', 'faz-cookie-manager' ),
						'svcCrisp'                 => __( 'Crisp', 'faz-cookie-manager' ),
						'svcLivechat'              => __( 'LiveChat', 'faz-cookie-manager' ),
						'svcTawk'                  => __( 'Tawk.to', 'faz-cookie-manager' ),
						'svcDrift'                 => __( 'Drift', 'faz-cookie-manager' ),
						'svcHubspotchat'           => __( 'HubSpot Chat', 'faz-cookie-manager' ),
						'svcTidio'                 => __( 'Tidio', 'faz-cookie-manager' ),
						// Email
						'svcMailchimp'             => __( 'Mailchimp', 'faz-cookie-manager' ),
						'svcActivecampaign'        => __( 'ActiveCampaign', 'faz-cookie-manager' ),
						'svcConvertkit'            => __( 'ConvertKit / Kit', 'faz-cookie-manager' ),
						'svcHubspot'               => __( 'HubSpot', 'faz-cookie-manager' ),
						'svcBrevo'                 => __( 'Brevo (Sendinblue)', 'faz-cookie-manager' ),
						'svcKlaviyo'               => __( 'Klaviyo', 'faz-cookie-manager' ),
						'svcPardot'                => __( 'Salesforce Pardot', 'faz-cookie-manager' ),
						'svcMarketo'               => __( 'Adobe Marketo Engage', 'faz-cookie-manager' ),
						'svcAdobe'                 => __( 'Adobe Analytics', 'faz-cookie-manager' ),
						// Payments
						'svcStripe'                => __( 'Stripe', 'faz-cookie-manager' ),
						'svcPaypal'                => __( 'PayPal', 'faz-cookie-manager' ),
						'svcSquare'                => __( 'Square', 'faz-cookie-manager' ),
						'svcShopify'               => __( 'Shopify', 'faz-cookie-manager' ),
						// Social sign-in
						'svcGoogleSignin'          => __( 'Sign in with Google', 'faz-cookie-manager' ),
						'svcAppleSignin'           => __( 'Sign in with Apple', 'faz-cookie-manager' ),
						'svcFacebookSignin'        => __( 'Sign in with Facebook', 'faz-cookie-manager' ),
						'svcAuth0'                 => __( 'Auth0', 'faz-cookie-manager' ),
						'svcOkta'                  => __( 'Okta', 'faz-cookie-manager' ),
						// Monitoring
						'svcSentry'                => __( 'Sentry', 'faz-cookie-manager' ),
						'svcNewrelic'              => __( 'New Relic', 'faz-cookie-manager' ),
						'svcDatadog'               => __( 'Datadog', 'faz-cookie-manager' ),
						'svcBugsnag'               => __( 'Bugsnag', 'faz-cookie-manager' ),
						'svcRaygun'                => __( 'Raygun', 'faz-cookie-manager' ),
						// A/B
						'svcOptimizely'            => __( 'Optimizely', 'faz-cookie-manager' ),
						'svcVwo'                   => __( 'VWO', 'faz-cookie-manager' ),
						'svcConvert'               => __( 'Convert.com', 'faz-cookie-manager' ),
						'svcAbtasty'               => __( 'AB Tasty', 'faz-cookie-manager' ),
						// Push
						'svcOnesignal'             => __( 'OneSignal', 'faz-cookie-manager' ),
						'svcPushwoosh'             => __( 'Pushwoosh', 'faz-cookie-manager' ),
						'svcFcm'                   => __( 'Firebase Cloud Messaging', 'faz-cookie-manager' ),
					),
				),
			)
		);

		// Enqueue page-specific JS if it exists.
		$this->ensure_pages_loaded();
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $this->pages as $page ) {
			if ( $page['slug'] === $current_page ) {
				$page_js = plugin_dir_path( __FILE__ ) . 'assets/js/pages/' . $page['view'] . '.js';

				// For banner page: enqueue media/FilePond BEFORE page script so deps are declared.
				if ( 'banner' === $page['view'] ) {
					if ( function_exists( 'wp_enqueue_media' ) ) {
						wp_enqueue_media();
					}
					$this->maybe_enqueue_core_filepond();
				}

				if ( file_exists( $page_js ) ) {
					$page_deps = array( 'faz-admin' );
					// Add FilePond as an explicit dependency if enqueued (ClassicPress).
					if ( 'banner' === $page['view'] ) {
						foreach ( array( 'filepond', 'wp-filepond' ) as $fp ) {
							if ( wp_script_is( $fp, 'enqueued' ) ) {
								$page_deps[] = $fp;
								break;
							}
						}
					}
					wp_enqueue_script(
						'faz-page-' . $page['view'],
						plugin_dir_url( __FILE__ ) . 'assets/js/pages/' . $page['view'] . '.js',
						$page_deps,
						filemtime( $page_js ),
						true
					);

					// Blocked-script watchdog for the two pages whose page script
					// filename contains "cookie" (cookie-policy.js, cookies.js) and
					// is therefore liable to be blocked by ad blockers / browser
					// privacy shields matching that name. The view ships a hidden
					// "notice notice-error" element plus an aria-live <p> and a
					// <template> carrying the message; this inline script reveals
					// the notice (and announces it) only after the window 'load'
					// lifecycle completes AND the boot flag is still unset — so a
					// merely slow-but-not-blocked footer script never trips a false
					// positive — and it re-hides the notice if the flag later turns
					// true. Registered via wp_add_inline_script() (not an inline
					// <script> in the view) to stay Plugin-Check clean, mirroring
					// the languages.php pattern.
					$faz_watchdog = array(
						'cookie-policy' => array( 'flag' => 'fazCpBooted', 'notice' => 'faz-cp-script-blocked' ),
						'cookies'       => array( 'flag' => 'fazCookiesBooted', 'notice' => 'faz-cookies-script-blocked' ),
					);
					if ( isset( $faz_watchdog[ $page['view'] ] ) ) {
						$faz_wd  = $faz_watchdog[ $page['view'] ];
						$faz_cfg = wp_json_encode(
							array(
								'flag'   => $faz_wd['flag'],
								'notice' => $faz_wd['notice'],
							)
						);
						$faz_wd_js = '( function () {' .
							'var cfg = ' . $faz_cfg . ';' .
							'var notice = document.getElementById( cfg.notice );' .
							'if ( ! notice ) { return; }' .
							'var msg = notice.querySelector( "[aria-live]" );' .
							'var tpl = notice.querySelector( "template" );' .
							'function booted() { return !! window[ cfg.flag ]; }' .
							// Reveal: populate the aria-live region (content change drives
							// the screen-reader announcement) and show the error notice.
							'function reveal() {' .
								'if ( booted() ) { return; }' .
								// Read the parsed template content (a DocumentFragment lives in
								// .content) as raw text — tpl.innerHTML would re-serialize
								// entities and .textContent would double-escape any &, <, > in a
								// localized string. tpl.textContent is empty for a <template>.
								'if ( msg && tpl && ! msg.textContent ) { msg.textContent = tpl.content.textContent; }' .
								'notice.style.display = "";' .
							'}' .
							// Recovery: if the script later boots, clear the announcement
							// and hide the notice again so it never persists falsely.
							'function clear() {' .
								'notice.style.display = "none";' .
								'if ( msg ) { msg.textContent = ""; }' .
							'}' .
							// Decide once the page-load lifecycle has settled: by the time
							// window "load" fires, every enqueued footer script has had its
							// chance to run, so an unset flag now means genuinely blocked
							// (not merely slow). Re-check shortly after as a last-resort
							// fallback and to catch a late boot for recovery.
							'function decide() { if ( booted() ) { clear(); } else { reveal(); } }' .
							'function arm() {' .
								'decide();' .
								'window.setTimeout( decide, 1500 );' .
							'}' .
							'if ( document.readyState === "complete" ) { arm(); }' .
							'else { window.addEventListener( "load", arm ); }' .
						'}() );';
						wp_add_inline_script( 'faz-page-' . $page['view'], $faz_wd_js, 'after' );
					}
				}

				// Pass theme presets so banner.js can reset colours on theme switch.
				if ( 'banner' === $page['view'] ) {
					$theme_file = plugin_dir_path( __FILE__ ) . 'modules/banners/includes/templates/6.2.0/theme.json';
					$presets    = file_exists( $theme_file ) ? json_decode( file_get_contents( $theme_file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					wp_add_inline_script( 'faz-admin', 'fazConfig.themePresets=' . wp_json_encode( $presets ) . ';', 'after' );

					// Per-law default notice descriptions for every selected or bundled
					// language. Banner rows preserve unselected translations, so those
					// defaults must remain available for the all-language mismatch scan.
					$faz_law_langs = faz_selected_languages();
					$faz_contents_dir = plugin_dir_path( __FILE__ ) . 'modules/banners/includes/contents/';
					$faz_content_files = glob( $faz_contents_dir . '*.json' );
					if ( is_array( $faz_content_files ) ) {
						foreach ( $faz_content_files as $faz_content_file ) {
							$faz_content_lang = basename( $faz_content_file, '.json' );
							if ( 'default' !== $faz_content_lang ) {
								$faz_law_langs[] = $faz_content_lang;
							}
						}
					}
					// Also include downloaded translations (validated against the
					// plugin's translated-language list, is_faz_translated()) that
					// may no longer be selected: a banner
					// can still carry such a language, and the all-language reload +
					// save-time mismatch scan need its per-law defaults available.
					$faz_uploads   = wp_upload_dir();
					$faz_trans_dir = trailingslashit( $faz_uploads['basedir'] ) . 'fazcookie/languages/banners/';
					$faz_trans_files = glob( $faz_trans_dir . '*.json' );
					if ( is_array( $faz_trans_files ) ) {
						$faz_lang_ctrl = \FazCookie\Admin\Modules\Languages\Includes\Controller::get_instance();
						foreach ( $faz_trans_files as $faz_trans_file ) {
							$faz_trans_lang = basename( $faz_trans_file, '.json' );
							if ( '' !== $faz_trans_lang && $faz_lang_ctrl->is_faz_translated( $faz_trans_lang ) ) {
								$faz_law_langs[] = $faz_trans_lang;
							}
						}
					}
					$faz_law_langs = array_values( array_unique( $faz_law_langs ) );
					if ( empty( $faz_law_langs ) ) {
						$faz_law_langs = array( faz_default_language() );
					}
					$faz_law_descs = array();
					foreach ( $faz_law_langs as $faz_law_lang ) {
						$faz_law_descs[ $faz_law_lang ] = \FazCookie\Admin\Modules\Banners\Includes\Banner::get_law_notice_descriptions( $faz_law_lang );
					}
					wp_add_inline_script( 'faz-admin', 'fazConfig.lawNoticeDescriptions=' . wp_json_encode( $faz_law_descs ) . ';', 'after' );
				}

				// Preload REST API responses so page JS gets instant data.
				$this->preload_page_data( $page['view'] );
				break;
			}
		}
	}

	/**
	 * Preload REST API data for the current page so wp.apiFetch serves it
	 * from memory instead of making HTTP requests.
	 *
	 * @param string $view The page view name (e.g. 'banner', 'settings', 'dashboard', 'cookies').
	 * @return void
	 */
	private function preload_page_data( $view ) {
		$paths = array();
		switch ( $view ) {
			case 'banner':
				$paths = array( '/faz/v1/banners/1', '/faz/v1/banners/design-presets' );
				break;
			case 'settings':
				$paths = array( '/faz/v1/settings', '/faz/v1/settings/geolite2/status', '/faz/v1/gvl' );
				break;
			case 'dashboard':
				$paths = array( '/faz/v1/pageviews/banner-stats?days=7', '/faz/v1/pageviews/chart?days=7', '/faz/v1/consent_logs/stats?days=7' );
				break;
			case 'cookies':
				$paths = array( '/faz/v1/cookies/categories' );
				break;
		}
		if ( empty( $paths ) ) {
			return;
		}

		// Execute REST requests internally and build preload cache.
		// Keys must match FAZ.api path format: "faz/v1/endpoint" (no leading slash).
		$preloaded = array();
		foreach ( $paths as $path ) {
			$parts   = wp_parse_url( $path );
			$request = new \WP_REST_Request( 'GET', $parts['path'] );
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $query_params );
				$request->set_query_params( $query_params );
			}
			$response = rest_do_request( $request );
			if ( $response->is_error() ) {
				continue;
			}
			if ( 200 === $response->get_status() ) {
				$key = ltrim( $parts['path'], '/' );
				if ( ! empty( $parts['query'] ) ) {
					$key .= '?' . $parts['query'];
				}
				$preloaded[ $key ] = array(
					'body'    => $response->get_data(),
					'headers' => $response->get_headers(),
				);
			}
		}

		if ( ! empty( $preloaded ) ) {
			wp_add_inline_script(
				'faz-admin',
				sprintf( 'wp.apiFetch.use(wp.apiFetch.createPreloadingMiddleware(%s));', wp_json_encode( $preloaded ) ),
				'before'
			);
		}
	}

	/**
	 * Enqueue FilePond from ClassicPress core if available.
	 *
	 * ClassicPress ships FilePond as its media uploader instead of the
	 * WordPress plupload / wp.media stack. If present, banner.js will
	 * use it as a fallback for the brand logo upload.
	 *
	 * @return void
	 */
	private function maybe_enqueue_core_filepond() {
		if ( ! $this->is_classicpress() ) {
			return;
		}
		$style_handle  = '';
		$script_handle = '';

		foreach ( array( 'filepond', 'wp-filepond' ) as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				$style_handle = $handle;
				break;
			}
		}
		foreach ( array( 'filepond', 'wp-filepond' ) as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				$script_handle = $handle;
				break;
			}
		}

		if ( ! $style_handle ) {
			$candidates = array(
				'wp-admin/css/filepond.min.css',
				'wp-admin/css/filepond.css',
				'wp-includes/css/filepond.min.css',
				'wp-includes/css/filepond.css',
			);
			$found = $this->find_core_asset( $candidates );
			if ( $found ) {
				$abs = trailingslashit( ABSPATH ) . $found;
				wp_register_style( 'filepond', $this->core_asset_url( $found ), array(), (string) filemtime( $abs ) );
				$style_handle = 'filepond';
			}
		}

		if ( ! $script_handle ) {
			$candidates = array(
				'wp-admin/js/filepond/filepond.min.js',
				'wp-admin/js/filepond.min.js',
				'wp-admin/js/filepond.js',
				'wp-includes/js/filepond/filepond.min.js',
				'wp-includes/js/filepond.min.js',
				'wp-includes/js/filepond.js',
			);
			$found = $this->find_core_asset( $candidates );
			if ( $found ) {
				$abs = trailingslashit( ABSPATH ) . $found;
				wp_register_script( 'filepond', $this->core_asset_url( $found ), array(), (string) filemtime( $abs ), true );
				$script_handle = 'filepond';
			}
		}

		if ( $style_handle ) {
			wp_enqueue_style( $style_handle );
		}
		if ( $script_handle ) {
			wp_enqueue_script( $script_handle );
		}
	}

	/**
	 * Find a core asset from a list of candidate relative paths.
	 *
	 * @param array $candidates Relative paths from ABSPATH.
	 * @return string Found relative path, or empty string.
	 */
	private function find_core_asset( $candidates ) {
		foreach ( $candidates as $relative ) {
			if ( file_exists( trailingslashit( ABSPATH ) . ltrim( $relative, '/' ) ) ) {
				return ltrim( $relative, '/' );
			}
		}
		return '';
	}

	/**
	 * Convert a core-relative path into a public URL.
	 *
	 * @param string $relative Path relative to ABSPATH.
	 * @return string
	 */
	private function core_asset_url( $relative ) {
		$relative = ltrim( $relative, '/' );
		if ( 0 === strpos( $relative, 'wp-admin/' ) ) {
			return admin_url( substr( $relative, strlen( 'wp-admin/' ) ) );
		}
		if ( 0 === strpos( $relative, 'wp-includes/' ) ) {
			return includes_url( substr( $relative, strlen( 'wp-includes/' ) ) );
		}
		return site_url( '/' . $relative );
	}


	/**
	 * Prepare shortcodes for banner preview.
	 *
	 * @return array
	 */
	public function prepare_shortcodes() {
		$data   = array();
		$data[] = array(
			'key'     => 'faz_readmore',
			'content' => do_shortcode( '[faz_readmore]' ),
			'tag'     => 'readmore-button',
		);
		$data[] = array(
			'key'        => 'faz_show_desc',
			'content'    => do_shortcode( '[faz_show_desc]' ),
			'tag'        => 'show-desc-button',
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_hide_desc',
			'content'    => do_shortcode( '[faz_hide_desc]' ),
			'tag'        => 'hide-desc-button',
			'attributes' => array(),
		);
		return $data;
	}

	/**
	 * Register main menu and submenu pages.
	 *
	 * @return void
	 */
	public function admin_menu() {
		$this->ensure_pages_loaded();
		$capability = 'manage_options';
		$parent     = self::ADMIN_SLUG;

		// Main menu page (Dashboard).
		add_menu_page(
			__( 'FAZ Cookie', 'faz-cookie-manager' ),
			__( 'FAZ Cookie', 'faz-cookie-manager' ),
			$capability,
			$parent,
			array( $this, 'render_page' ),
			'dashicons-food',
			40
		);

		// Submenu pages.
		foreach ( $this->pages as $key => $page ) {
			add_submenu_page(
				$parent,
				$page['title'],
				$page['title'],
				$capability,
				$page['slug'],
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Register a network admin menu page for multisite installs.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register_network_menu() {
		add_menu_page(
			__( 'FAZ Cookie', 'faz-cookie-manager' ),
			__( 'FAZ Cookie', 'faz-cookie-manager' ),
			'manage_network_options',
			'faz-cookie-manager-network',
			array( $this, 'render_network_page' ),
			'dashicons-food',
			81
		);
	}

	/**
	 * Render the network admin overview page.
	 *
	 * Lists all subsites with their banner status and a link to each
	 * subsite's admin configuration page.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function render_network_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FAZ Cookie Manager — Network', 'faz-cookie-manager' ); ?></h1>
			<div class="card" style="max-width:800px;margin-top:20px;">
				<h2><?php esc_html_e( 'Multisite Configuration', 'faz-cookie-manager' ); ?></h2>
				<p><?php esc_html_e( 'FAZ Cookie Manager is network-activated. Each subsite has its own independent configuration.', 'faz-cookie-manager' ); ?></p>
				<p><?php esc_html_e( 'Navigate to each subsite\'s admin panel to configure the cookie banner, categories, and settings.', 'faz-cookie-manager' ); ?></p>

				<h3><?php esc_html_e( 'Active Sites', 'faz-cookie-manager' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Site', 'faz-cookie-manager' ); ?></th>
							<th><?php esc_html_e( 'Banner Status', 'faz-cookie-manager' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'faz-cookie-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Building this table costs one switch_to_blog() +
						// get_option()/get_bloginfo()/get_site() per subsite. On a
						// large network that is an expensive N+1 to repeat on every
						// page load, so memoise the computed rows in a short-lived
						// network transient. The overview tolerates a few minutes
						// of staleness (a freshly toggled banner shows up on the
						// next cache cycle); super-admins who need it live can
						// purge the transient or wait out the 5-minute TTL.
						$faz_network_rows = get_site_transient( 'faz_network_overview' );
						if ( false === $faz_network_rows ) {
							$faz_network_rows = array();
							$site_ids         = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
							foreach ( $site_ids as $site_id ) {
								switch_to_blog( $site_id );
								$settings  = get_option( 'faz_settings' );
								$site_obj  = get_site( $site_id );
								$site_name = get_bloginfo( 'name' );
								$faz_network_rows[] = array(
									'name'      => $site_name ? $site_name : ( $site_obj ? $site_obj->domain . $site_obj->path : '#' . $site_id ),
									'banner_on' => ! empty( $settings['banner_control']['status'] ),
									'admin_url' => get_admin_url( $site_id, 'admin.php?page=faz-cookie-manager' ),
								);
								restore_current_blog();
							}
							set_site_transient( 'faz_network_overview', $faz_network_rows, 5 * MINUTE_IN_SECONDS );
						}
						foreach ( $faz_network_rows as $faz_row ) :
						?>
						<tr>
							<td><strong><?php echo esc_html( $faz_row['name'] ); ?></strong></td>
							<td>
								<?php if ( $faz_row['banner_on'] ) : ?>
									<span style="color:green;">&#9679; <?php esc_html_e( 'Active', 'faz-cookie-manager' ); ?></span>
								<?php else : ?>
									<span style="color:#999;">&#9679; <?php esc_html_e( 'Inactive', 'faz-cookie-manager' ); ?></span>
								<?php endif; ?>
							</td>
							<td><a href="<?php echo esc_url( $faz_row['admin_url'] ); ?>"><?php esc_html_e( 'Configure', 'faz-cookie-manager' ); ?></a></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render an admin page by including its view file.
	 *
	 * @return void
	 */
	/**
	 * Emit the no-cache stack on every plugin admin page.
	 *
	 * Reported on prod (fabiodalez.it 2026-05-18, 1.14.1): after creating /
	 * deleting banners, the admin would still see the previous state.
	 * LiteSpeed (and Cloudflare "cache everything" misconfigurations,
	 * varnish 4.x, etc.) can cache /wp-admin/ responses when the
	 * cookie-keyed exemption fails. We force-opt-out with the full stack:
	 *   - nocache_headers()  (WP core re-affirm)
	 *   - explicit Cache-Control: no-store
	 *   - X-LiteSpeed-Cache-Control: no-cache  (LSCache request-time opt-out)
	 *   - DONOTCACHE* constants
	 *   - litespeed_control_set_nocache action
	 *
	 * Hooked on admin_init priority 1 so it runs before any module's
	 * admin_init listeners that might produce output and flip
	 * headers_sent() to true (the prior placement inside render_page() was
	 * too late on pages that boot a list-table or admin notice early —
	 * three pages, consent-logs / gcm / languages, never received the
	 * header pre-fix).
	 *
	 * @return void
	 */
	public function send_admin_nocache_headers() {
		// Only act on the plugin's own admin pages — avoids touching
		// unrelated /wp-admin/ responses.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page || false === strpos( $page, self::ADMIN_SLUG ) ) {
			return;
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
			header( 'X-LiteSpeed-Cache-Control: no-cache', true );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}
		do_action( 'litespeed_control_set_nocache', 'FAZ Cookie Manager admin page' );
	}

	public function render_page() {
		// Defensive double-emit of the no-cache stack — admin_init priority 1
		// already did this (send_admin_nocache_headers), but some bootstrap
		// orderings (e.g. plugins that filter the admin_init queue) could
		// theoretically skip it; re-emitting here is cheap and idempotent.
		$this->send_admin_nocache_headers();

		$this->ensure_pages_loaded();
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : self::ADMIN_SLUG; // phpcs:ignore WordPress.Security.NonceVerification

		$faz_page_title = '';
		$faz_page_slug  = 'dashboard';

		foreach ( $this->pages as $page ) {
			if ( $page['slug'] === $current_page ) {
				$faz_page_title = $page['title'];
				$faz_page_slug  = $page['view'];
				break;
			}
		}

		include plugin_dir_path( __FILE__ ) . 'views/base.php';
	}

	/**
	 * Lazy-initialize the admin pages array if not already loaded.
	 *
	 * @return void
	 */
	private function ensure_pages_loaded() {
		if ( ! isset( $this->pages ) ) {
			$this->pages = $this->get_admin_pages();
		}
	}

	/**
	 * Add custom class to admin body tag.
	 *
	 * @param string $classes List of classes.
	 * @return string
	 */
	public function admin_body_classes( $classes ) {
		if ( true === faz_is_admin_page() ) {
			$classes .= ' faz-admin-page';
		}
		return $classes;
	}

	/**
	 * Returns Jed-formatted localization data.
	 *
	 * @since 4.0.0
	 *
	 * @param  string $domain Translation domain.
	 * @return array          The information of the locale.
	 */
	public function get_jed_locale_data( $domain ) {
		$locale = array(
			'' => array(
				'domain' => $domain,
				'lang'   => is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale(),
			),
		);

		$json_translations = $this->load_json_translations();

		foreach ( $json_translations as $key => $value ) {
			$locale[ $key ] = array( $value );
		}

		$json = wp_json_encode( $locale );
		if ( preg_match( '/<br[\s\/\\\\]*>/', $json ) ) {
			foreach ( $locale as $key => $value ) {
				foreach ( (array) $value as $sub_key => $sub_value ) {
					if ( is_string( $sub_value ) ) {
						$locale[ $key ][ $sub_key ] = str_replace( array( '<br>', '<br/>', '<br />' ), '', $sub_value );
					}
				}
			}
		}

		return $locale;
	}

	/**
	 * Load translations from JSON files.
	 *
	 * @since 4.0.0
	 *
	 * @return array The merged translations from all JSON files.
	 */
	private function load_json_translations() {
		$translations = array();

		$current_lang = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$lang_code    = substr( $current_lang, 0, 2 );

		$languages_dir = WP_CONTENT_DIR . '/languages/';
		$json_paths    = array();
		$plugins_dir   = $languages_dir . 'plugins/';

		if ( is_dir( $plugins_dir ) ) {
			$files = glob( $plugins_dir . 'faz-cookie-manager-' . $current_lang . '-*.json' );
			if ( ! empty( $files ) ) {
				$json_paths = array_merge( $json_paths, $files );
			}

			$files = glob( $plugins_dir . 'faz-cookie-manager-' . $lang_code . '-*.json' );
			if ( ! empty( $files ) ) {
				$json_paths = array_merge( $json_paths, $files );
			}

			$files = glob( $plugins_dir . 'faz-cookie-manager-en-*.json' );
			if ( ! empty( $files ) ) {
				$json_paths = array_merge( $json_paths, $files );
			}
		}

		foreach ( $json_paths as $path ) {
			if ( file_exists( $path ) ) {
				$json_content = file_get_contents( $path );
				$json_data    = json_decode( $json_content, true );

				if ( $json_data && is_array( $json_data ) ) {
					if ( isset( $json_data['locale_data']['messages'] ) ) {
						$message_translations = $json_data['locale_data']['messages'];
						foreach ( $message_translations as $key => $value ) {
							if ( is_array( $value ) && isset( $value[0] ) ) {
								if ( '' !== $key ) {
									$translations[ $key ] = $value[0];
								}
							}
						}
					}
				}
			}
		}

		return $translations;
	}

	/**
	 * Legacy notice handler retained as a no-op for backward compatibility.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function hide_admin_notices() {
		// Intentionally left as a no-op.
		// Plugins should not suppress notices from WordPress core or other
		// plugins on their own admin screens.
		return;
	}

	/**
	 * Display a dismissible notice on FAZ admin pages when WooCommerce is active.
	 *
	 * Informs the site owner that payment gateway scripts are automatically
	 * whitelisted on checkout/cart pages to prevent breaking the store.
	 *
	 * @return void
	 */
	public function woocommerce_compat_notice() {
		if ( ! faz_is_admin_page() ) {
			return;
		}
		if ( ! class_exists( 'WooCommerce', false ) ) {
			return;
		}
		// Dismissible via user meta — once dismissed, never show again.
		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, 'faz_wc_notice_dismissed', true ) ) {
			return;
		}
		// Handle dismiss action.
		if ( isset( $_GET['faz_dismiss_wc_notice'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_faz_nonce'] ?? '' ) ), 'faz_dismiss_wc_notice' ) ) {
			update_user_meta( $user_id, 'faz_wc_notice_dismissed', 1 );
			return;
		}
		$dismiss_url = wp_nonce_url( add_query_arg( 'faz_dismiss_wc_notice', '1' ), 'faz_dismiss_wc_notice', '_faz_nonce' );
		echo '<div class="notice notice-info" style="position:relative">';
		echo '<p><strong>' . esc_html__( 'WooCommerce detected', 'faz-cookie-manager' ) . '</strong> — ';
		echo esc_html__( 'FAZ Cookie Manager automatically whitelists WooCommerce core scripts and payment gateway scripts (PayPal, Stripe, Mollie, etc.) on checkout and cart pages so your store keeps working. This can be customised via the', 'faz-cookie-manager' );
		echo ' <code>faz_whitelisted_scripts</code> ' . esc_html__( 'and', 'faz-cookie-manager' );
		echo ' <code>faz_payment_gateway_whitelist</code> ' . esc_html__( 'filters.', 'faz-cookie-manager' );
		echo '</p>';
		echo '<a href="' . esc_url( $dismiss_url ) . '" style="position:absolute;top:0;right:0;padding:9px;text-decoration:none;color:#787c82">';
		echo '<span class="dashicons dashicons-dismiss"></span></a>';
		echo '</div>';
	}

	/**
	 * Display a persistent notice until local cookie definitions are downloaded.
	 *
	 * Kept scoped to FAZ admin screens so it does not hijack the wider WordPress
	 * dashboard. The download itself remains an explicit user action.
	 *
	 * @return void
	 */
	public function cookie_definitions_notice() {
		if ( ! faz_is_admin_page() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$defs = \FazCookie\Includes\Cookie_Definitions::get_instance();
		$meta = $defs->get_meta();
		if ( $defs->has_definitions() && ( ! isset( $meta['source'] ) || 'bundled' !== $meta['source'] ) ) {
			return;
		}

		$url = admin_url( 'admin.php?page=faz-cookie-manager-cookies#faz-cookie-definitions-card' );

		printf(
			'<div class="notice notice-warning"><p>%s</p><p><a href="%s" class="button button-secondary">%s</a></p></div>',
			esc_html__( 'Cookie definitions are using the built-in snapshot. Download the latest version from GitHub for improved categorization.', 'faz-cookie-manager' ),
			esc_url( $url ),
			esc_html__( 'Update Cookie Definitions', 'faz-cookie-manager' )
		);
	}

	/**
	 * Display a dismissible notice when a scheduled scan found new cookies.
	 *
	 * @return void
	 */
	public function scheduled_scan_notice() {
		$new_count = get_transient( 'faz_scan_new_cookies' );
		if ( ! $new_count ) {
			return;
		}

		if ( ! faz_is_admin_page() ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			sprintf(
				/* translators: %d: number of new cookies found */
				esc_html__( 'Scheduled scan found %d new cookie(s).', 'faz-cookie-manager' ),
				absint( $new_count )
			),
			esc_url( admin_url( 'admin.php?page=faz-cookie-manager-cookies' ) ),
			esc_html__( 'Review now', 'faz-cookie-manager' )
		);

		delete_transient( 'faz_scan_new_cookies' );
	}

	/**
	 * Display a dismissible notice when detected services lack a matching selected IAB vendor.
	 *
	 * Only shown on FAZ admin pages when IAB TCF is enabled.
	 *
	 * @return void
	 */
	public function unmatched_vendors_notice() {
		$unmatched = get_transient( 'faz_unmatched_vendors' );
		if ( empty( $unmatched ) || ! is_array( $unmatched ) ) {
			return;
		}
		if ( ! faz_is_admin_page() ) {
			return;
		}

		$names = array_map(
			function ( $u ) {
				return '<strong>' . esc_html( $u['service'] ) . '</strong>';
			},
			$unmatched
		);
		$list = implode( ', ', $names );

		$dismiss_nonce = wp_create_nonce( 'faz_dismiss_unmatched' );
		printf(
			'<div class="notice notice-warning is-dismissible" id="faz-unmatched-vendors-notice"><p>%s %s</p><p><a href="%s" class="button button-small">%s</a> <button type="button" class="button button-small button-link" onclick="jQuery(\'#faz-unmatched-vendors-notice\').fadeOut();jQuery.post(ajaxurl,{action:\'faz_dismiss_unmatched\',_wpnonce:\'%s\'});">%s</button></p></div>',
			wp_kses_post(
				sprintf(
					/* translators: %s: comma-separated list of service names */
					__( 'FAZ Cookie Manager detected services on your site that are not covered by any selected IAB vendor: %s.', 'faz-cookie-manager' ),
					$list
				)
			),
			esc_html__( 'Add the matching vendors to ensure proper TCF consent for these services.', 'faz-cookie-manager' ),
			esc_url( admin_url( 'admin.php?page=faz-cookie-manager-gvl' ) ),
			esc_html__( 'Go to Vendor List', 'faz-cookie-manager' ),
			esc_attr( $dismiss_nonce ),
			esc_html__( 'Dismiss', 'faz-cookie-manager' )
		);
	}

	/**
	 * AJAX handler: dismiss the unmatched vendors notice.
	 *
	 * @return void
	 */
	public function ajax_dismiss_unmatched_vendors() {
		check_ajax_referer( 'faz_dismiss_unmatched', '_wpnonce' );
		delete_transient( 'faz_unmatched_vendors' );
		wp_die();
	}

	/**
	 * Detect a configuration that makes the plugin emit Cache-Control:
	 * no-store on every page WITHOUT any functional benefit. The single
	 * combination that triggers this is:
	 *
	 *   - geo-routing toggle on (settings.geolocation.geo_targeting=true)
	 *   - default_behavior set to "no_banner" (the only value that flips
	 *     send_geo_cache_headers() via is_country_dependent_output())
	 *   - no global target regions selected (settings.geolocation.target_regions
	 *     empty) — the country split the no_banner gate keys off in
	 *     Frontend::is_geo_banner_disabled() is empty, so the gate can never
	 *     hide the banner for one country and show it for another
	 *   - at least one banner configured, and no banner has a target_countries
	 *     list (so the per-banner geo gate isn't doing anything either)
	 *   - IAB TCF disabled (else the no-cache is justified anyway)
	 *
	 * In that configuration the plugin penalises the CDN cache for the
	 * entire site while neither the global target-regions gate nor any
	 * per-banner target-countries list can actually produce a per-country
	 * split — same symptom James (gooloo / english truffles) hit in support
	 * thread "Performance Impact???". Detecting it lets us point admins
	 * at a one-click resolution. Note: when target_regions IS populated the
	 * banner genuinely varies by country, so the no-store is justified and
	 * this returns false (no warning).
	 *
	 * Returns true when the user-visible warning should be shown.
	 *
	 * @return bool
	 */
	private function is_redundant_geo_routing_active() {
		$settings = get_option( 'faz_settings', array() );
		$geo      = isset( $settings['geolocation'] ) && is_array( $settings['geolocation'] ) ? $settings['geolocation'] : array();
		$iab      = isset( $settings['iab'] ) && is_array( $settings['iab'] ) ? $settings['iab'] : array();

		if ( empty( $geo['geo_targeting'] ) ) {
			return false;
		}
		$default_behavior = isset( $geo['default_behavior'] ) ? (string) $geo['default_behavior'] : 'show_banner';
		if ( 'no_banner' !== $default_behavior ) {
			return false;
		}
		if ( ! empty( $iab['enabled'] ) ) {
			return false;
		}
		if ( ! class_exists( '\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller' ) ) {
			return false;
		}
		$controller = \FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance();
		if ( $controller->has_country_dependent_banners() ) {
			return false;
		}
		$banners = $controller->get_items();
		if ( ! is_array( $banners ) || count( $banners ) < 1 ) {
			// Zero banners means the plugin isn't really in use yet —
			// no point nagging. has_country_dependent_banners() above
			// already filtered out the "multi-banner with at least one
			// target_countries set" case, so the warning fires whenever
			// the admin has 1+ banners but NONE of them carries a
			// target-countries list — which is exactly the configuration
			// where Geo-routing's no_banner gate cannot fire usefully.
			return false;
		}
		// The global "hide banner outside target regions" gate
		// (Frontend::is_geo_banner_disabled) keys off
		// settings.geolocation.target_regions — NOT the per-banner
		// target_countries that has_country_dependent_banners() inspects.
		// When at least one target region is selected, the banner genuinely
		// varies by visitor country (in-region visitors see it, others do
		// not), so the Cache-Control: no-store IS functionally justified and
		// the configuration is not redundant. Only when no target region is
		// selected does the no_banner gate have nothing to act on, making the
		// full-page-cache penalty pointless — which is the case we warn about.
		$target_regions = isset( $geo['target_regions'] ) && is_array( $geo['target_regions'] )
			? array_filter( $geo['target_regions'] )
			: array();
		if ( ! empty( $target_regions ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Render a dismissible admin warning when is_redundant_geo_routing_active()
	 * matches. Two action buttons:
	 *   - "Disable Geo-routing" — one-click fix via AJAX (faz_disable_redundant_geo_routing)
	 *   - "Open Geo-routing settings" — link to the relevant admin tab
	 *
	 * The "dismiss" cross suppresses the notice for the current site for
	 * the lifetime of the redundant configuration; clearing the geo_targeting
	 * flag (or any of the other guards) makes the notice eligible again on
	 * the next admin load, so a reverted change reopens the alert.
	 *
	 * @return void
	 */
	public function redundant_geo_routing_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! faz_is_admin_page() ) {
			return;
		}
		if ( ! $this->is_redundant_geo_routing_active() ) {
			return;
		}
		if ( get_transient( 'faz_dismiss_redundant_geo_routing' ) ) {
			return;
		}

		$disable_nonce = wp_create_nonce( 'faz_disable_redundant_geo_routing' );
		$dismiss_nonce = wp_create_nonce( 'faz_dismiss_redundant_geo_routing' );
		$settings_url  = admin_url( 'admin.php?page=faz-cookie-manager-settings#faz-geo-regions' );

		echo '<div class="notice notice-warning is-dismissible" id="faz-redundant-geo-routing-notice">';
		echo '<p><strong>' . esc_html__( 'FAZ Cookie Manager — Geo-routing is on but has no effect', 'faz-cookie-manager' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Geo-routing is enabled in Settings with "Hide banner outside target regions" as the default behaviour, but no target regions are selected and none of your banners has a target-countries list. In this configuration the plugin tells the CDN that every page is country-dependent (so no full-page cache is kept) while the geo-routing gate has nothing to target, so it can never actually fire. The most common symptom is "X-Cdn-Cache-Status: MISS" on every page, and a Lighthouse drop.', 'faz-cookie-manager' ) . '</p>';
		echo '<p>' . esc_html__( 'If you do NOT need per-country banners, the safe fix is to disable Geo-routing. If you DO want per-country banners, select one or more target regions in Settings → Geolocation, or add a target-countries list to at least one banner — the warning will clear on its own.', 'faz-cookie-manager' ) . '</p>';
		printf(
			'<p><button type="button" class="button button-primary" id="faz-disable-redundant-geo-routing" data-nonce="%s">%s</button> <a href="%s" class="button">%s</a></p>',
			esc_attr( $disable_nonce ),
			esc_html__( 'Disable Geo-routing now', 'faz-cookie-manager' ),
			esc_url( $settings_url ),
			esc_html__( 'Open Geo-routing settings', 'faz-cookie-manager' )
		);
		echo '</div>';

		// Notice behaviour is attached via wp_add_inline_script (not a raw
		// <script> echo) so Plugin Check's WordPress.Security.EscapeOutput
		// sniff stays clean — same lesson recorded on print_api_fetch_polyfill()
		// above. The faz-admin handle is always enqueued on FAZ admin pages
		// (enqueue_scripts() gates on faz_is_admin_page(), the same gate this
		// notice uses), so the inline script always has a host handle.
		$inline_js = sprintf(
			'(function(){' .
			'var notice=document.getElementById("faz-redundant-geo-routing-notice");' .
			'if(!notice){return;}' .
			'var btn=notice.querySelector("#faz-disable-redundant-geo-routing");' .
			'if(btn){btn.addEventListener("click",function(){' .
			'btn.disabled=true;btn.textContent=%1$s;' .
			'var body=new URLSearchParams();' .
			'body.set("action","faz_disable_redundant_geo_routing");' .
			'body.set("_wpnonce",btn.dataset.nonce);' .
			'fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:body})' .
			'.then(function(r){return r.json();})' .
			'.then(function(json){' .
			'if(json&&json.success){notice.querySelector("p:last-of-type").textContent=%2$s;}' .
			'else{btn.disabled=false;btn.textContent=%3$s;}' .
			'})' .
			'.catch(function(){btn.disabled=false;btn.textContent=%3$s;});' .
			'});}' .
			'var dismissNonce=%4$s;' .
			'notice.addEventListener("click",function(e){' .
			'var target=e.target;' .
			'if(!target||!target.classList||!target.classList.contains("notice-dismiss")){return;}' .
			'var body=new URLSearchParams();' .
			'body.set("action","faz_dismiss_redundant_geo_routing");' .
			'body.set("_wpnonce",dismissNonce);' .
			'fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:body});' .
			'});' .
			'})();',
			wp_json_encode( __( 'Disabling…', 'faz-cookie-manager' ) ),
			wp_json_encode( __( 'Geo-routing disabled. The CDN cache should start filling again within minutes of organic traffic.', 'faz-cookie-manager' ) ),
			wp_json_encode( __( 'Disable Geo-routing now', 'faz-cookie-manager' ) ),
			wp_json_encode( $dismiss_nonce )
		);
		wp_add_inline_script( 'faz-admin', $inline_js, 'after' );
	}

	/**
	 * AJAX handler — flips settings.geolocation.geo_targeting off in the
	 * faz_settings option. Atomic (single update_option call), idempotent
	 * (running it twice on an already-disabled config is a no-op), guarded
	 * by capability + nonce.
	 *
	 * @return void
	 */
	public function ajax_disable_redundant_geo_routing() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
			return;
		}
		check_ajax_referer( 'faz_disable_redundant_geo_routing', '_wpnonce' );

		$settings = get_option( 'faz_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		if ( ! isset( $settings['geolocation'] ) || ! is_array( $settings['geolocation'] ) ) {
			$settings['geolocation'] = array();
		}
		$settings['geolocation']['geo_targeting'] = false;
		update_option( 'faz_settings', $settings );
		delete_transient( 'faz_dismiss_redundant_geo_routing' );
		wp_send_json_success();
	}

	/**
	 * AJAX handler — silence the notice without changing settings. Stored
	 * as a 30-day transient (long enough that the warning doesn't pester
	 * an admin who has read it, short enough that a setting drift will
	 * surface it again on the next month's admin session).
	 *
	 * @return void
	 */
	public function ajax_dismiss_redundant_geo_routing() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
			return;
		}
		check_ajax_referer( 'faz_dismiss_redundant_geo_routing', '_wpnonce' );
		set_transient( 'faz_dismiss_redundant_geo_routing', 1, 30 * DAY_IN_SECONDS );
		wp_send_json_success();
	}

	/**
	 * Handle redirect after plugin activation.
	 *
	 * @param string $plugin Plugin basename.
	 * @return void
	 */
	public function handle_activation_redirect( $plugin ) {
		if ( FAZ_PLUGIN_BASENAME !== $plugin ) {
			return;
		}
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::ADMIN_SLUG ) );
		exit;
	}

	/**
	 * Load plugin for the first time.
	 *
	 * @return void
	 */
	public function load_plugin() {
		if ( is_admin() && 'true' === get_option( 'faz_first_time_activated_plugin' ) ) {
			do_action( 'faz_after_first_time_install' );
			delete_option( 'faz_first_time_activated_plugin' );
		}
	}

	/**
	 * Send no-cache headers for all faz/v1 REST responses so HTTP caches
	 * (LiteSpeed, Nginx proxy, CDNs) never serve stale cookie/category data.
	 *
	 * @return void
	 */
	public function add_rest_nocache_headers() {
		add_filter(
			'rest_pre_serve_request',
			function ( $served, $result, $request ) {
				$route = $request->get_route();
				// Exclude /faz/v1/banner/* — those routes set their own
				// Cache-Control: public, max-age=300 for CDN caching.
				if (
					0 === strpos( $route, '/faz/v1' )
					&& 0 !== strpos( $route, '/faz/v1/banner' )
					&& ! headers_sent()
				) {
					header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
					header( 'Pragma: no-cache' );
					header( 'X-LiteSpeed-Cache-Control: no-cache' );
				}
				return $served;
			},
			10,
			3
		);
	}

	/**
	 * Register the dashboard widget for consent stats overview.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function register_dashboard_widget() {
		wp_add_dashboard_widget(
			'faz_consent_widget',
			__( 'Cookie Consent Overview', 'faz-cookie-manager' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget with consent statistics.
	 *
	 * Shows acceptance/rejection percentages and total interactions
	 * from the last 30 days.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function render_dashboard_widget() {
		// Cache the aggregation query for 5 minutes to avoid
		// running a COUNT/SUM on every WP Dashboard page load.
		$stats = get_transient( 'faz_dashboard_widget_stats' );
		if ( false === $stats ) {
			global $wpdb;
			$table  = $wpdb->prefix . 'faz_consent_logs';
			$cutoff = date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is plugin-prefix; $cutoff is bound via prepare(%s). The result IS cached — see set_transient() below — and the transient is consulted at the top of this function; the get_row() only runs on a cache miss.
			$stats = $wpdb->get_row(
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
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			set_transient( 'faz_dashboard_widget_stats', $stats, 5 * MINUTE_IN_SECONDS );
		}

		$total    = intval( $stats['total'] ?? 0 );
		$accepted = intval( $stats['accepted'] ?? 0 );
		$rejected = intval( $stats['rejected'] ?? 0 );

		$accept_pct = $total > 0 ? round( $accepted / $total * 100 ) : 0;
		$reject_pct = $total > 0 ? round( $rejected / $total * 100 ) : 0;
		?>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
			<div style="text-align:center;padding:12px;background:#f0f0f1;border-radius:6px;">
				<div style="font-size:24px;font-weight:700;color:#00a32a;"><?php echo esc_html( $accept_pct ); ?>%</div>
				<div style="font-size:12px;color:#646970;"><?php esc_html_e( 'Accepted', 'faz-cookie-manager' ); ?></div>
			</div>
			<div style="text-align:center;padding:12px;background:#f0f0f1;border-radius:6px;">
				<div style="font-size:24px;font-weight:700;color:#d63638;"><?php echo esc_html( $reject_pct ); ?>%</div>
				<div style="font-size:12px;color:#646970;"><?php esc_html_e( 'Rejected', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
		<p style="margin:0;font-size:13px;color:#646970;">
			<?php
			printf(
				/* translators: %d: number of consent interactions in last 30 days */
				esc_html__( '%d consent interactions in the last 30 days.', 'faz-cookie-manager' ),
				absint( $total )
			);
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager' ) ); ?>"><?php esc_html_e( 'View details', 'faz-cookie-manager' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Modify plugin action links on plugin listing page.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$links[] = '<a href="' . esc_url( get_admin_url( null, 'admin.php?page=' . self::ADMIN_SLUG ) ) . '">' . esc_html__( 'Settings', 'faz-cookie-manager' ) . '</a>';
		return array_reverse( $links );
	}
}
