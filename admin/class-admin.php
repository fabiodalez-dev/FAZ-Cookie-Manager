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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    FazCookie
 * @subpackage FazCookie/admin
 * @since      3.0.0
 */
class Admin {

	/**
	 * Admin menu slug prefix.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	const ADMIN_SLUG = 'faz-cookie-manager';

	/**
	 * The version of this plugin.
	 *
	 * @since  3.0.0
	 * @access private
	 * @var    string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Admin modules of the plugin.
	 *
	 * @since  3.0.0
	 * @access private
	 * @var    array $modules List of module slugs.
	 */
	private static $modules;

	/**
	 * Currently active modules.
	 *
	 * @since  3.0.0
	 * @access private
	 * @var    array $active_modules Map of active module slug => true.
	 */
	private static $active_modules;

	/**
	 * Existing modules.
	 *
	 * @since 3.0.0
	 * @var   array $existing_modules List of existing module slugs.
	 */
	public static $existing_modules;

	/**
	 * Submenu pages config.
	 *
	 * @since  3.0.0
	 * @access private
	 * @var    array $pages Associative array of page definitions.
	 */
	private $pages;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since 3.0.0
	 * @param string $version The version of this plugin.
	 */
	public function __construct( $version ) {
		$this->version = $version;
		self::$modules = $this->get_default_modules();
		$this->load();
		$this->load_modules();
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'load_plugin' ) );
		add_action( 'activated_plugin', array( $this, 'handle_activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'deregister_api_fetch' ), 0 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_classes' ) );
		add_action( 'admin_print_scripts', array( $this, 'hide_admin_notices' ) );
		add_filter( 'plugin_action_links_' . FAZ_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Load activator on each load.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	public function load() {
		\FazCookie\Includes\Activator::init();
	}

	/**
	 * Get the default modules array.
	 *
	 * @since  3.0.0
	 * @return array List of default module slugs.
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
	 * Load all the modules.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	public function load_modules() {
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
	 * @since  3.0.0
	 * @return array Associative array of page config arrays.
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
			'settings'     => array(
				'title' => __( 'Settings', 'faz-cookie-manager' ),
				'slug'  => self::ADMIN_SLUG . '-settings',
				'view'  => 'settings',
			),
		);
	}

	/**
	 * Check if running on ClassicPress.
	 *
	 * Uses the officially recommended detection method per ClassicPress docs:
	 * https://docs.classicpress.net/developer-guides/best-practice-to-detect-a-classicpress-install/
	 *
	 * classicpress_version() is a function registered exclusively by ClassicPress
	 * core and is guaranteed to exist on every CP install from 1.0.0 onwards.
	 * There is no CLASSICPRESS_VERSION constant — function_exists() is the correct check.
	 *
	 * @since  3.0.0
	 * @return bool True when running on ClassicPress, false otherwise.
	 */
	private function is_classicpress() {
		return function_exists( 'classicpress_version' );
	}

	/**
	 * Deregister the native wp-api-fetch handle on ClassicPress admin pages only.
	 *
	 * On ClassicPress (a WP 4.9 fork), core outputs an inline bootstrap script
	 * alongside wp-api-fetch that calls wp.apiFetch.createRootURLMiddleware — a
	 * function that does not exist in the 4.9 build — crashing the page before
	 * any plugin JS runs. We therefore remove the handle and replace it with an
	 * empty stub so that bootstrap is never output; our polyfill then provides the
	 * full wp.apiFetch implementation.
	 *
	 * On standard WordPress the native wp-api-fetch bundle is complete and correct,
	 * so we leave it entirely untouched.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	public function deregister_api_fetch() {
		if ( false === faz_is_admin_page() ) {
			return;
		}

		if ( ! $this->is_classicpress() ) {
			return;
		}

		wp_dequeue_script( 'wp-api-fetch' );
		wp_deregister_script( 'wp-api-fetch' );

		// Re-register as an empty stub so any handle that declares wp-api-fetch
		// as a dependency still resolves without pulling in the broken script.
		wp_register_script( 'wp-api-fetch', false, array(), false, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since  3.0.0
	 * @return void
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

		// WordPress / ClassicPress dashicons (for icon support in quick links, etc.).
		wp_enqueue_style( 'dashicons' );
	}

	/**
	 * Get base script dependencies.
	 *
	 * On standard WordPress we depend on the native wp-api-fetch handle, which
	 * ships a complete, up-to-date implementation including createRootURLMiddleware.
	 *
	 * On ClassicPress the native wp-api-fetch is a WP 4.9 build that lacks the
	 * full 5.x middleware stack; we deregister it (see deregister_api_fetch()) and
	 * inject our own polyfill instead, so no core handle is needed there.
	 *
	 * @since  3.0.0
	 * @return array Script dependency handles.
	 */
	private function get_script_dependencies() {
		if ( $this->is_classicpress() ) {
			return array();
		}

		return array( 'wp-api-fetch' );
	}

	/**
	 * Complete wp.apiFetch polyfill for ClassicPress.
	 *
	 * Mirrors the full WordPress 5.x wp-api-fetch package API so that:
	 *   - The WP core inline bootstrap (createRootURLMiddleware / createNonceMiddleware)
	 *     runs without errors on ClassicPress.
	 *   - Any plugin that registers custom middleware via .use() works correctly.
	 *   - All call patterns used by faz-admin.js are supported:
	 *     path-based requests, data payloads, parse:false for raw Response access.
	 *
	 * Public API surface matches WordPress 5.x wp-api-fetch:
	 *   apiFetch( options )                         — main callable
	 *   apiFetch.use( middleware )                  — register a middleware
	 *   apiFetch.setFetchHandler( handler )         — override the fetch layer
	 *   apiFetch.createRootURLMiddleware( root )    — resolves relative paths
	 *   apiFetch.createNonceMiddleware( nonce )     — injects X-WP-Nonce header
	 *   apiFetch.createPreloadingMiddleware( data ) — serves cached responses
	 *   apiFetch.fetchAllMiddleware                 — follows X-WP-TotalPages
	 *   apiFetch.mediaUploadMiddleware              — handles FormData uploads
	 *
	 * @since  3.0.0
	 * @return void
	 */
	private function enqueue_api_fetch_polyfill() {
		$nonce    = wp_create_nonce( 'wp_rest' );
		$rest_url = rest_url();

		$polyfill = sprintf(
			'(function(root,nonce){
"use strict";

/* Middleware stack */
var middlewares=[];
function registerMiddleware(m){middlewares.unshift(m);}

/* Default fetch handler */
var fetchHandler=defaultFetchHandler;
function defaultFetchHandler(options){
var parse=options.parse!==false;
return window.fetch(options.url,options).then(function(response){
if(!parse){return response;}
return response.text().then(function(text){
var data;
try{data=text?JSON.parse(text):null;}catch(e){data=null;}
if(!response.ok){
var err=Object.assign(
new Error(data&&data.message?data.message:"Unknown error"),
{code:"unknown_error",data:{status:response.status}},
data||{}
);
return Promise.reject(err);
}
return data;
});
});
}

/* Run middleware chain */
function runMiddleware(idx,options){
if(idx>=middlewares.length){
var req=Object.assign({},options);
if(req.data&&!req.body&&!(req.data instanceof window.FormData)){
req.body=JSON.stringify(req.data);
}
return fetchHandler(req);
}
return middlewares[idx](options,function(next){return runMiddleware(idx+1,next);});
}

/* Main apiFetch function */
function apiFetch(options){return runMiddleware(0,options);}

/* Resolves a relative path against the REST root URL */
function createRootURLMiddleware(rootURL){
return function(options,next){
var opts=Object.assign({},options);
if(opts.path!==undefined&&opts.url===undefined){
opts.url=rootURL.replace(/\/+$/,"")+"/"+opts.path.replace(/^\/+/,"");
delete opts.path;
}
return next(opts);
};
}

/* Injects X-WP-Nonce and refreshes it from the response header */
function createNonceMiddleware(initialNonce){
var currentNonce=initialNonce;
var middleware=function(options,next){
var opts=Object.assign({},options);
opts.headers=Object.assign({},opts.headers);
if(currentNonce&&!opts.headers["X-WP-Nonce"]){
opts.headers["X-WP-Nonce"]=currentNonce;
}
return next(opts).then(function(result){
if(result&&result.headers&&typeof result.headers.get==="function"){
var fresh=result.headers.get("X-WP-Nonce");
if(fresh){currentNonce=fresh;}
}
return result;
});
};
middleware.nonce=currentNonce;
return middleware;
}

/* Returns preloaded cached data for matching GET requests */
function createPreloadingMiddleware(preloadedData){
var cache=Object.assign({},preloadedData);
return function(options,next){
var method=(options.method||"GET").toUpperCase();
if(method!=="GET"){return next(options);}
var key=options.path||(options.url||"");
if(Object.prototype.hasOwnProperty.call(cache,key)){
var cached=cache[key];
delete cache[key];
if(options.parse===false){
return Promise.resolve(
new window.Response(JSON.stringify(cached.body),{
status:200,
headers:new window.Headers(cached.headers||{})
})
);
}
return Promise.resolve(cached.body);
}
return next(options);
};
}

/* Removes Content-Type for FormData so the browser sets the multipart boundary */
var mediaUploadMiddleware=function(options,next){
var opts=Object.assign({},options);
if(opts.data instanceof window.FormData){
opts.body=opts.data;
opts.headers=Object.assign({},opts.headers);
delete opts.headers["Content-Type"];
delete opts.data;
}
return next(opts);
};

/* Follows X-WP-TotalPages to accumulate all pages of a paginated endpoint */
var fetchAllMiddleware=function(options,next){
if(options.parse!==false){return next(options);}
return next(options).then(function(response){
var total=parseInt(
(response.headers&&response.headers.get("X-WP-TotalPages"))||"1",10
);
if(isNaN(total)||total<=1){return response;}
var pages=[response.json()];
for(var p=2;p<=total;p++){
var sep=(options.path||"").indexOf("?")>-1?"&":"?";
pages.push(apiFetch(Object.assign({},options,{
path:(options.path||"")+sep+"page="+p,
parse:true
})));
}
return Promise.all(pages).then(function(results){
return [].concat.apply([],results);
});
});
};

/* Default Content-Type middleware */
apiFetch.use(function(options,next){
var opts=Object.assign({},options);
if(opts.data&&!(opts.data instanceof window.FormData)){
opts.headers=Object.assign({"Content-Type":"application/json"},opts.headers||{});
}
return next(opts);
});

/* Register default root + nonce middlewares */
apiFetch.use(createNonceMiddleware(nonce));
apiFetch.use(createRootURLMiddleware(root));

/* Assign public API — must come after the .use() calls above */
apiFetch.use=registerMiddleware;
apiFetch.setFetchHandler=function(h){fetchHandler=h;};
apiFetch.createRootURLMiddleware=createRootURLMiddleware;
apiFetch.createNonceMiddleware=createNonceMiddleware;
apiFetch.createPreloadingMiddleware=createPreloadingMiddleware;
apiFetch.fetchAllMiddleware=fetchAllMiddleware;
apiFetch.mediaUploadMiddleware=mediaUploadMiddleware;

window.wp=window.wp||{};
window.wp.apiFetch=apiFetch;

}(%s,%s));',
			wp_json_encode( $rest_url ),
			wp_json_encode( $nonce )
		);

		wp_add_inline_script( 'faz-admin', $polyfill, 'before' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( false === faz_is_admin_page() ) {
			return;
		}

		$deps = $this->get_script_dependencies();

		// Core utilities.
		wp_enqueue_script(
			'faz-admin',
			plugin_dir_url( __FILE__ ) . 'assets/js/faz-admin.js',
			$deps,
			$this->version,
			true
		);

		// On ClassicPress inject our own wp.apiFetch polyfill before faz-admin.js.
		// On WordPress the native wp-api-fetch handle is used instead.
		// See get_script_dependencies() and deregister_api_fetch() for context.
		if ( $this->is_classicpress() ) {
			$this->enqueue_api_fetch_polyfill();
		}

		// Localize config data for JS.
		$this->localize_admin_config();

		// Enqueue page-specific assets.
		$this->enqueue_page_assets();
	}

	/**
	 * Localize configuration data for JS.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	private function localize_admin_config() {
		wp_localize_script(
			'faz-admin',
			'fazConfig',
			array(
				'api'            => array(
					'base'  => rest_url( 'faz/v1/' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				),
				'site'           => array(
					'url'  => get_site_url(),
					'name' => esc_attr( get_option( 'blogname' ) ),
				),
				'adminURL'       => admin_url( 'admin.php' ),
				'assetsURL'      => defined( 'FAZ_PLUGIN_URL' ) ? FAZ_PLUGIN_URL . 'frontend/images/' : '',
				'defaultLogo'    => plugins_url( 'cookie.png', FAZ_PLUGIN_FILENAME ),
				'isClassicPress' => $this->is_classicpress(),
				'multilingual'   => faz_i18n_is_multilingual() && count( faz_selected_languages() ) > 0,
				'languages'      => array(
					'selected' => faz_selected_languages(),
					'default'  => faz_default_language(),
				),
				'version'        => $this->version,
				'modules'        => self::$active_modules,
			)
		);
	}

	/**
	 * Enqueue page-specific JS and any extra assets required by a page.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	private function enqueue_page_assets() {
		$this->ensure_pages_loaded();

		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		foreach ( $this->pages as $page ) {
			if ( $page['slug'] !== $current_page ) {
				continue;
			}

			$page_js = plugin_dir_path( __FILE__ ) . 'assets/js/pages/' . $page['view'] . '.js';

			if ( file_exists( $page_js ) ) {
				wp_enqueue_script(
					'faz-page-' . $page['view'],
					plugin_dir_url( __FILE__ ) . 'assets/js/pages/' . $page['view'] . '.js',
					array( 'faz-admin' ),
					filemtime( $page_js ),
					true
				);
			}

			// Enqueue WordPress / ClassicPress media library for the banner page (brand logo uploader).
			if ( 'banner' === $page['view'] ) {
				wp_enqueue_media();

				// Pass theme presets so banner.js can reset colours on theme switch.
				$theme_file = plugin_dir_path( __FILE__ ) . 'modules/banners/includes/templates/6.2.0/theme.json';
				$presets    = file_exists( $theme_file )
					? json_decode( file_get_contents( $theme_file ), true ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					: array();

				wp_add_inline_script( 'faz-admin', 'fazConfig.themePresets=' . wp_json_encode( $presets ) . ';', 'after' );
			}

			break;
		}
	}

	/**
	 * Prepare shortcodes for banner preview.
	 *
	 * @since  3.0.0
	 * @return array List of shortcode data arrays.
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
	 * @since  3.0.0
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
		foreach ( $this->pages as $page ) {
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
	 * Render an admin page by including its view file.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	public function render_page() {
		$this->ensure_pages_loaded();

		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : self::ADMIN_SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
	 * @since  3.0.0
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
	 * @since  3.0.0
	 * @param  string $classes Space-separated list of body classes.
	 * @return string Modified class list.
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
	 * @since  4.0.0
	 * @param  string $domain Translation domain.
	 * @return array          Locale data in Jed format.
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
	 * @since  4.0.0
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
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$json_content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json_data    = json_decode( $json_content, true );

			if ( ! $json_data || ! is_array( $json_data ) ) {
				continue;
			}

			if ( ! isset( $json_data['locale_data']['messages'] ) ) {
				continue;
			}

			foreach ( $json_data['locale_data']['messages'] as $key => $value ) {
				if ( is_array( $value ) && isset( $value[0] ) && '' !== $key ) {
					$translations[ $key ] = $value[0];
				}
			}
		}

		return $translations;
	}

	/**
	 * Hide all the unrelated notices from plugin pages.
	 *
	 * @since  3.0.0
	 * @return void
	 */
	public function hide_admin_notices() {
		if ( empty( $_REQUEST['page'] ) || ! preg_match( '/' . preg_quote( self::ADMIN_SLUG, '/' ) . '/', esc_html( wp_unslash( $_REQUEST['page'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}

		global $wp_filter;

		$notices_type = array(
			'user_admin_notices',
			'admin_notices',
			'all_admin_notices',
		);

		foreach ( $notices_type as $type ) {
			if ( empty( $wp_filter[ $type ]->callbacks ) || ! is_array( $wp_filter[ $type ]->callbacks ) ) {
				continue;
			}

			foreach ( $wp_filter[ $type ]->callbacks as $priority => $hooks ) {
				foreach ( $hooks as $name => $arr ) {
					if ( is_object( $arr['function'] ) && $arr['function'] instanceof \Closure ) {
						unset( $wp_filter[ $type ]->callbacks[ $priority ][ $name ] );
						continue;
					}

					$class = ! empty( $arr['function'][0] ) && is_object( $arr['function'][0] )
						? strtolower( get_class( $arr['function'][0] ) )
						: '';

					if ( ! empty( $class ) && preg_match( '/^faz/', $class ) ) {
						continue;
					}

					if ( ! empty( $name ) && ! preg_match( '/^faz/', $name ) ) {
						unset( $wp_filter[ $type ]->callbacks[ $priority ][ $name ] );
					}
				}
			}
		}
	}

	/**
	 * Handle redirect after plugin activation.
	 *
	 * @since  3.0.0
	 * @param  string $plugin Plugin basename.
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
	 * @since  3.0.0
	 * @return void
	 */
	public function load_plugin() {
		if ( is_admin() && 'true' === get_option( 'faz_first_time_activated_plugin' ) ) {
			do_action( 'faz_after_first_time_install' );
			delete_option( 'faz_first_time_activated_plugin' );
		}
	}

	/**
	 * Modify plugin action links on plugin listing page.
	 *
	 * @since  3.0.0
	 * @param  array $links Existing action links.
	 * @return array Modified action links with Settings prepended.
	 */
	public function plugin_action_links( $links ) {
		$links[] = '<a href="' . get_admin_url( null, 'admin.php?page=' . self::ADMIN_SLUG ) . '">' . esc_html__( 'Settings', 'faz-cookie-manager' ) . '</a>';

		return array_reverse( $links );
	}
}
