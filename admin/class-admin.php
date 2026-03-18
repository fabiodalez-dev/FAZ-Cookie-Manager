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
		$this->load_modules();
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_network_menu' ) );
		}
		add_action( 'admin_init', array( $this, 'load_plugin' ) );
		add_action( 'activated_plugin', array( $this, 'handle_activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'deregister_api_fetch' ), 0 );
		add_action( 'admin_head', array( $this, 'print_api_fetch_polyfill' ), 0 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_classes' ) );
		add_action( 'admin_print_scripts', array( $this, 'hide_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'woocommerce_compat_notice' ) );
		add_action( 'admin_notices', array( $this, 'scheduled_scan_notice' ) );
		add_action( 'admin_notices', array( $this, 'unmatched_vendors_notice' ) );
		add_action( 'wp_ajax_faz_dismiss_unmatched', array( $this, 'ajax_dismiss_unmatched_vendors' ) );
		add_filter( 'plugin_action_links_' . FAZ_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
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
	 * Load all the modules.
	 *
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
	public function deregister_api_fetch() {
		if ( false === faz_is_admin_page() || ! $this->is_classicpress() ) {
			return;
		}
		wp_dequeue_script( 'wp-api-fetch' );
		wp_deregister_script( 'wp-api-fetch' );
		wp_register_script( 'wp-api-fetch', false, array(), false, true );
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
	 * Print wp.apiFetch polyfill for ClassicPress.
	 *
	 * Mirrors the WordPress 5.x wp-api-fetch API surface so that all admin
	 * JS works identically on ClassicPress without the broken native bundle.
	 *
	 * Hooked to admin_head at priority 0 so it runs before any script that
	 * depends on wp.apiFetch. We echo directly because ClassicPress (WP 4.9
	 * fork) does not output inline scripts for handles with no source URL.
	 *
	 * @return void
	 */
	public function print_api_fetch_polyfill() {
		if ( false === faz_is_admin_page() || ! $this->is_classicpress() ) {
			return;
		}
		$nonce    = wp_create_nonce( 'wp_rest' );
		$rest_url = rest_url();

		$polyfill = sprintf(
			'(function(root,nonce){
"use strict";
var middlewares=[];
function registerMiddleware(m){middlewares.unshift(m);}
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
return {__fazParsed:true,data:data,headers:{get:function(h){return response.headers.get(h);}}};
});
});
}
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
function apiFetch(options){return runMiddleware(0,options);}
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
return (result&&result.__fazParsed)?result.data:result;
});
};
middleware.nonce=currentNonce;
return middleware;
}
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
var fetchAllMiddleware=function(options,next){
if(options.parse!==false){return next(options);}
return next(options).then(function(response){
var total=parseInt(
(response.headers&&response.headers.get("X-WP-TotalPages"))||"1",10
);
if(isNaN(total)||total<=1){return response;}
var pages=[response.json()];
var base=(options.path||"").replace(/([?&])page=[^&]*/g,"").replace(/\?$/,"");
for(var p=2;p<=total;p++){
var sep=base.indexOf("?")>-1?"&":"?";
pages.push(apiFetch(Object.assign({},options,{
path:base+sep+"page="+p,
parse:true
})));
}
return Promise.all(pages).then(function(results){
return [].concat.apply([],results);
});
});
};
registerMiddleware(function(options,next){
var opts=Object.assign({},options);
if(opts.data&&!(opts.data instanceof window.FormData)){
opts.headers=Object.assign({"Content-Type":"application/json"},opts.headers||{});
}
return next(opts);
});
registerMiddleware(createNonceMiddleware(nonce));
registerMiddleware(createRootURLMiddleware(root));
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

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted polyfill source.
		echo '<script>' . $polyfill . '</script>';
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

		// ClassicPress polyfill is printed directly in admin_head — see print_api_fetch_polyfill().

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
					'url'  => get_site_url(),
					'name' => esc_attr( get_option( 'blogname' ) ),
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
				}

				// Pass theme presets so banner.js can reset colours on theme switch.
				if ( 'banner' === $page['view'] ) {
					$theme_file = plugin_dir_path( __FILE__ ) . 'modules/banners/includes/templates/6.2.0/theme.json';
					$presets    = file_exists( $theme_file ) ? json_decode( file_get_contents( $theme_file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					wp_add_inline_script( 'faz-admin', 'fazConfig.themePresets=' . wp_json_encode( $presets ) . ';', 'after' );
				}
				break;
			}
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
						$site_ids = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
						foreach ( $site_ids as $site_id ) :
							switch_to_blog( $site_id );
							$settings  = get_option( 'faz_settings' );
							$banner_on = ! empty( $settings['banner_control']['status'] );
							$admin_url = get_admin_url( $site_id, 'admin.php?page=faz-cookie-manager' );
							$site_name = get_bloginfo( 'name' );
							$site_obj  = get_site( $site_id );
							restore_current_blog();
						?>
						<tr>
							<td><strong><?php echo esc_html( $site_name ? $site_name : ( $site_obj ? $site_obj->domain . $site_obj->path : '#' . $site_id ) ); ?></strong></td>
							<td>
								<?php if ( $banner_on ) : ?>
									<span style="color:green;">&#9679; <?php esc_html_e( 'Active', 'faz-cookie-manager' ); ?></span>
								<?php else : ?>
									<span style="color:#999;">&#9679; <?php esc_html_e( 'Inactive', 'faz-cookie-manager' ); ?></span>
								<?php endif; ?>
							</td>
							<td><a href="<?php echo esc_url( $admin_url ); ?>"><?php esc_html_e( 'Configure', 'faz-cookie-manager' ); ?></a></td>
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
	public function render_page() {
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
	 * Hide all the unrelated notices from plugin pages.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function hide_admin_notices() {
		if ( empty( $_REQUEST['page'] ) || ! preg_match( '/' . preg_quote( self::ADMIN_SLUG, '/' ) . '/', esc_html( wp_unslash( $_REQUEST['page'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
					$class = ! empty( $arr['function'][0] ) && is_object( $arr['function'][0] ) ? strtolower( get_class( $arr['function'][0] ) ) : '';
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
		global $wpdb;
		$table  = $wpdb->prefix . 'faz_consent_logs';
		$cutoff = date( 'Y-m-d H:i:s', strtotime( '-30 days', strtotime( current_time( 'mysql' ) ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as total,
						SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
						SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
						SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial
				 FROM {$table}
				 WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			),
			ARRAY_A
		);

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
				$total
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
