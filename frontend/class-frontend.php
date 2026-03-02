<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://www.webtoffee.com/
 * @since      3.0.0
 *
 * @package    FazCookie
 * @subpackage FazCookie/Frontend
 */

namespace FazCookie\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FazCookie\Admin\Modules\Banners\Includes\Controller;
use FazCookie\Admin\Modules\Settings\Includes\Settings;
use FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings;
use FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger;
use FazCookie\Includes\Geolocation;
use FazCookie\Includes\Cookie_Table_Shortcode;
/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    FazCookie
 * @subpackage FazCookie\Frontend
 * @author     WebToffee <info@webtoffee.com>
 */
class Frontend {

	/**
	 * The ID of this plugin.
	 *
	 * @since    3.0.0
	 * @access   private
	 * @var      string    $plugin_name  The ID of this plugin.
	 */
	private $plugin_name;

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
	 * Banner object
	 *
	 * @var object
	 */
	protected $banner;

	/**
	 * Plugin settings
	 *
	 * @var object
	 */
	protected $settings;

	/**
	 * Plugin settings
	 *
	 * @var object
	 */
	protected $gcm_settings;

	/**
	 * Banner template
	 *
	 * @var object
	 */
	protected $template;

	/**
	 * Providers list
	 *
	 * @var array
	 */
	protected $providers = array();
	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    3.0.0
	 * @param      string $plugin_name       The name of the plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->load_modules();
		$this->settings = new Settings();
		$this->gcm_settings = new Gcm_Settings();
		new Consent_Logger();
		new Cookie_Table_Shortcode();
		add_action( 'init', array( $this, 'load_banner' ) );
		add_action( 'wp_footer', array( $this, 'banner_html' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 1 );
		add_action( 'wp_head', array( $this, 'insert_script' ), 1 );
		add_action( 'wp_head', array( $this, 'insert_styles' ) );
	}

	/**
	 * Get the default modules array
	 *
	 * @since 3.0.0
	 * @return array
	 */
	public function get_default_modules() {
		$modules = array();
		return $modules;
	}

	/**
	 * Load all the modules
	 *
	 * @return void
	 */
	public function load_modules() {
		if ( true === faz_disable_banner() ) {
			return;
		}
		foreach ( $this->get_default_modules() as $module ) {
			$parts      = explode( '_', $module );
			$class      = implode( '_', $parts );
			$class      = str_ireplace( '-', '_', $class );
			$module     = str_ireplace( '-', '_', $module );
			$class_name = 'FazCookie\\Frontend\\Modules\\' . ucwords( $module, '_' ) . '\\' . ucwords( $class, '_' );

			if ( class_exists( $class_name ) ) {
				$module_obj = new $class_name( $module );
				if ( $module_obj instanceof $class_name ) {
					if ( $module_obj->is_active() ) {
						$module_obj->init();
						self::$active_modules[ $module ] = true;
					}
				}
			}
		}
	}

	/**
	 * Enqeue front end scripts
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( true === faz_disable_banner() ) {
			return;
		}
		if ( $this->is_banner_disabled_by_settings() ) {
			return;
		}
		$suffix = ''; // Always load non-minified JS.
		if ( false === $this->settings->is_connected() ) {
			if ( ! $this->template ) {
				return;
			}
			$css    = isset( $this->template['styles'] ) ? $this->template['styles'] : '';
			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/script' . $suffix . '.js', array(), $this->version, false );
			wp_localize_script( $this->plugin_name, '_fazConfig', $this->get_store_data() );
			wp_localize_script( $this->plugin_name, '_fazStyles', array( 'css' => $css ) );

			// GCM (Google Consent Mode) in local mode.
			if ( true === $this->gcm_settings->is_gcm_enabled() ) {
				$gcm      = $this->get_gcm_data();
				$gcm_json = wp_json_encode( $gcm );
				wp_add_inline_script( $this->plugin_name, 'var _fazGcm = ' . $gcm_json . ';', 'before' );
				$gcm_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
				$gcm_handle = $this->plugin_name . '-gcm';
				wp_enqueue_script( $gcm_handle, plugin_dir_url( __FILE__ ) . 'js/gcm' . $gcm_suffix . '.js', array(), $this->version, false );
			}

			// IAB TCF v2.2 CMP stub (when IAB is enabled in settings).
			$iab_enabled = (bool) $this->settings->get( 'iab', 'enabled' );
			if ( $iab_enabled ) {
				// Early command-queue stub so ad scripts can call __tcfapi before CMP loads.
				$tcf_stub = 'if(typeof window.__tcfapi!=="function"){var a=[];window.__tcfapi=function(){a.push(arguments);};window.__tcfapi.a=a;}';
				wp_add_inline_script( $this->plugin_name, $tcf_stub, 'before' );
				$tcf_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
				$tcf_handle = $this->plugin_name . '-tcf-cmp';
				wp_enqueue_script( $tcf_handle, plugin_dir_url( __FILE__ ) . 'js/tcf-cmp' . $tcf_suffix . '.js', array( $this->plugin_name ), $this->version, false );
			}

			// Pageview and banner interaction tracking.
			wp_localize_script(
				$this->plugin_name,
				'_fazPageviewConfig',
				array(
					'restUrl'   => rest_url( 'faz/v1/pageviews' ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'pageUrl'   => home_url( add_query_arg( array(), false ) ),
					'pageTitle' => wp_get_document_title(),
				)
			);
			$pv_js = "(function(){" .
				"if(typeof _fazPageviewConfig==='undefined')return;" .
				"var sid=sessionStorage.getItem('faz_sid');" .
				"if(!sid){sid=Math.random().toString(36).substring(2)+Date.now().toString(36);sessionStorage.setItem('faz_sid',sid);}" .
				"function fazTrack(t){" .
					"fetch(_fazPageviewConfig.restUrl,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':_fazPageviewConfig.nonce}," .
					"body:JSON.stringify({page_url:_fazPageviewConfig.pageUrl,page_title:_fazPageviewConfig.pageTitle,event_type:t,session_id:sid})});" .
				"}" .
				"fazTrack('pageview');" .
				"document.addEventListener('fazcookie_banner_loaded',function(){fazTrack('banner_view');});" .
				"document.addEventListener('fazcookie_consent_update',function(e){" .
					"var d=e.detail||{};" .
					"if(d.accepted)fazTrack('banner_accept');" .
					"else if(d.rejected)fazTrack('banner_reject');" .
					"else fazTrack('banner_settings');" .
				"});" .
			"})();";
			wp_add_inline_script( $this->plugin_name, $pv_js );

			// Add consent logging if enabled.
			$faz_settings    = get_option( 'faz_settings' );
			$log_consent_on  = isset( $faz_settings['consent_logs']['status'] ) && true === $faz_settings['consent_logs']['status'];
			if ( $log_consent_on ) {
				wp_localize_script(
					$this->plugin_name,
					'_fazConsentLog',
					array(
						'restUrl' => rest_url( 'faz/v1/consent' ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
					)
				);
				$inline_js = "document.addEventListener('fazcookie_consent_update',function(e){" .
					"var d=e.detail||{};" .
					"if(typeof _fazConsentLog==='undefined')return;" .
					"fetch(_fazConsentLog.restUrl,{" .
						"method:'POST'," .
						"headers:{'Content-Type':'application/json','X-WP-Nonce':_fazConsentLog.nonce}," .
						"body:JSON.stringify({" .
							"consent_id:(function(){var m=document.cookie.match(/consentid:([^,;]+)/);return m?m[1]:''})()," .
							"status:(d.rejected&&d.rejected.length&&(!d.accepted||!d.accepted.length))?'rejected':(d.accepted&&d.accepted.length?'accepted':'partial')," .
							"categories:(function(){var c={};(d.accepted||[]).forEach(function(k){c[k]='yes'});(d.rejected||[]).forEach(function(k){c[k]='no'});return c})()," .
							"url:window.location.href" .
						"})" .
					"});" .
				"});";
				wp_add_inline_script( $this->plugin_name, $inline_js );
			}
		}
		if ( true === $this->is_wpconsentapi_enabled() ) {
			$handle = $this->plugin_name . '-wca';
			wp_register_script( $handle, plugin_dir_url( __FILE__ ) . 'js/wca' . $suffix . '.js', array(), $this->version, false );
			if ( true === $this->is_gsk_enabled() ) {
				wp_add_inline_script( $handle, 'const _fazGsk = true;', 'before' );
			}
			wp_enqueue_script( $handle );
		}
		$ms_uet     = (bool) $this->settings->get( 'microsoft', 'uet_consent_mode' );
		$ms_clarity = (bool) $this->settings->get( 'microsoft', 'clarity_consent' );
		if ( $ms_uet || $ms_clarity ) {
			$ms_handle = $this->plugin_name . '-microsoft-consent';
			wp_enqueue_script( $ms_handle, plugin_dir_url( __FILE__ ) . 'js/microsoft-consent' . $suffix . '.js', array(), $this->version, false );
			if ( $ms_uet ) {
				wp_add_inline_script( $ms_handle, 'window._fazMicrosoftUET = true;', 'before' );
			}
			if ( $ms_clarity ) {
				wp_add_inline_script( $ms_handle, 'window._fazMicrosoftClarity = true;', 'before' );
			}
		}
	}

	/**
	 * Add inline styles to the head
	 *
	 * @return void
	 */
	public function insert_styles() {
		if ( true === $this->settings->is_connected() || true === faz_disable_banner() || is_admin() ) {
			return;
		}
		if ( $this->is_banner_disabled_by_settings() ) {
			return;
		}
		echo '<style id="faz-style-inline">[data-faz-tag]{visibility:hidden;}</style>';
	}
	/**
	 * Add web app script on the header.
	 *
	 * @return void
	 */
	public function insert_script() {
		if ( false === $this->settings->is_connected() || true === faz_disable_banner() ) {
			return;
		}
		if ( true === $this->gcm_settings->is_gcm_enabled() ) {
			$gcm = $this->get_gcm_data();
			$gcm_json = wp_json_encode($gcm);
			?>
<script id="faz-cookie-manager-gcm-var-js">
var _fazGcm = <?php echo $gcm_json; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
</script>
<?php
			$suffix = ''; // Always load non-minified JS.
			$script_url = plugin_dir_url( __FILE__ ) . 'js/gcm' . $suffix . '.js'; 
?>
<script id="faz-cookie-manager-gcm-js" type="text/javascript" src="<?php echo esc_url( $script_url ); ?>"></script> <?php //phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		}
		echo '<script id="fazcookie" type="text/javascript" src="' . esc_url( $this->settings->get_script_url() ) . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}
	/**
	 * Load active banner.
	 *
	 * @return void
	 */
	public function load_banner() {
		if ( true === $this->settings->is_connected() || true === faz_disable_banner() ) {
			return;
		}
		if ( $this->is_banner_disabled_by_settings() ) {
			return;
		}
		if ( ! faz_is_front_end_request() ) {
			return;
		}
		$this->banner = Controller::get_instance()->get_active_banner();
		if ( false === $this->banner ) {
			return;
		}

		// Geo-targeting: skip banner if visitor's country doesn't match the ruleSet.
		if ( $this->is_geo_blocked() ) {
			return;
		}

		$this->template = $this->banner->get_template();
	}

	/**
	 * Check if the banner should be blocked for this visitor based on geo rules.
	 *
	 * @return bool True if the banner should NOT be shown.
	 */
	private function is_geo_blocked() {
		if ( ! $this->banner ) {
			return false;
		}
		$settings = $this->banner->get_settings();
		$rules    = isset( $settings['ruleSet'] ) ? $settings['ruleSet'] : array();
		if ( empty( $rules ) ) {
			return false;
		}
		$rule = $rules[0];
		$code = isset( $rule['code'] ) ? strtoupper( $rule['code'] ) : 'ALL';

		// ALL = show banner worldwide.
		if ( 'ALL' === $code ) {
			return false;
		}

		$country = Geolocation::get_country();
		// If we can't detect the country, show the banner (safe default).
		if ( empty( $country ) ) {
			return false;
		}

		switch ( $code ) {
			case 'EU':
				return ! in_array( $country, Geolocation::$eu_countries, true );
			case 'US':
				return 'US' !== $country;
			case 'OTHER':
				$regions = isset( $rule['regions'] ) ? array_map( 'strtoupper', (array) $rule['regions'] ) : array();
				return ! in_array( $country, $regions, true );
			default:
				return false;
		}
	}
	/**
	 * Print banner HTML as script template using
	 * type="text/template" attribute
	 *
	 * @return void
	 */
	public function banner_html() {
		if ( ! $this->template || true === faz_disable_banner() ) {
			return;
		}
		if ( $this->is_banner_disabled_by_settings() ) {
			return;
		}
		$html = isset( $this->template['html'] ) ? $this->template['html'] : '';
		echo '<script id="fazBannerTemplate" type="text/template">';
		echo wp_kses( $html, faz_allowed_html() );
		echo '</script>';
	}
	/**
	 * Modify tags to now show any `id` attribute.
	 *
	 * @param string $tag The `<script>` tag for the enqueued script.
	 * @param string $handle The script's registered handle.
	 */
	public function script_loader_tag( $tag, $handle ) {
		if ( false === $this->settings->is_connected() || true === faz_disable_banner() ) {
			return $tag;
		}
		if ( $handle === $this->plugin_name ) {
			$tag = '<script id="fazcookie" type="text/javascript" src="' . esc_attr( $this->settings->get_script_url() ) . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		}
		return $tag;
	}
	/**
	 * Get gcm data
	 *
	 * @return array
	 */
	public function get_gcm_data() {
		if ( ! $this->gcm_settings ) {
			return;
		}
		$gcm          = $this->gcm_settings;
		$gcm_settings = $gcm->get();
		return $gcm_settings;
	}
	/**
	 * Get store data
	 *
	 * @return array
	 */
	public function get_store_data() {
		if ( ! $this->banner ) {
			return;
		}
		$settings        = get_option( 'faz_settings' );
		$banner          = $this->banner;
		$banner_settings = $banner->get_settings();

		$providers = array();
		$store     = array(
			'_ipData'       => array(),
			'_assetsURL'    => FAZ_PLUGIN_URL . 'frontend/images/',
			'_publicURL'    => get_site_url(),
			'_expiry'       => min( 180, isset( $banner_settings['settings']['consentExpiry']['value'] ) ? absint( $banner_settings['settings']['consentExpiry']['value'] ) : 180 ),
			'_categories'   => $this->get_cookie_groups(),
			'_activeLaw'    => 'gdpr',
			'_rootDomain'   => $this->get_cookie_domain(),
			'_block'        => true,
			'_showBanner'   => true,
			'_bannerConfig' => $this->prepare_config(),
			'_version'      => $this->version,
			'_logConsent'   => isset( $settings['consent_logs']['status'] ) && true === $settings['consent_logs']['status'] ? true : false,
			'_tags'         => $this->prepare_tags(),
			'_shortCodes'   => $this->prepare_shortcodes( $banner->get_settings() ),
			'_rtl'          => $this->is_rtl(),
			'_language'     => faz_current_language(),
		);
		foreach ( $this->providers as $key => $value ) {
			$providers[] = array(
				're'         => $key,
				'categories' => $value,
			);
		}
		$store['_providersToBlock'] = $providers;
		return $store;
	}
	/**
	 * Return cookie domain
	 *
	 * @return string
	 */
	public function get_cookie_domain() {
		$domain = '';
		$subdomain = $this->settings->get( 'banner_control', 'subdomain_sharing' );
		if ( $subdomain ) {
			$parsed = wp_parse_url( home_url() );
			$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
			$parts  = explode( '.', $host );
			if ( count( $parts ) > 2 ) {
				$domain = '.' . implode( '.', array_slice( $parts, -2 ) );
			} elseif ( ! empty( $host ) ) {
				$domain = '.' . $host;
			}
		}
		return apply_filters( 'faz_cookie_domain', $domain );
	}

	/**
	 * Check if banner is disabled by FAZ settings (global toggle or page exclusion).
	 *
	 * @return boolean
	 */
	protected function is_banner_disabled_by_settings() {
		$banner_status = $this->settings->get( 'banner_control', 'status' );
		if ( false === $banner_status ) {
			return true;
		}

		$excluded = $this->settings->get( 'banner_control', 'excluded_pages' );
		if ( ! empty( $excluded ) && is_array( $excluded ) ) {
			$current_id  = get_the_ID();
			$current_url = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			foreach ( $excluded as $exclusion ) {
				if ( is_numeric( $exclusion ) && absint( $exclusion ) === absint( $current_id ) ) {
					return true;
				}
				if ( is_string( $exclusion ) && ! empty( $exclusion ) && fnmatch( $exclusion, $current_url ) ) {
					return true;
				}
			}
		}
		return false;
	}
	/**
	 * Get cookie groups
	 *
	 * @return array
	 */
	public function get_cookie_groups() {
		$cookie_groups = array();
		$categories    = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items();

		foreach ( $categories as $category ) {
			$category        = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $category );
			$cookie_groups[] = array(
				'name'           => $category->get_name( faz_current_language() ),
				'slug'           => $category->get_slug(),
				'isNecessary'    => 'necessary' === $category->get_slug() ? true : false,
				'ccpaDoNotSell'  => $category->get_sell_personal_data(),
				'cookies'        => $this->get_cookies( $category ),
				'active'         => true,
				'defaultConsent' => array(
					'gdpr' => $category->get_prior_consent(),
					'ccpa' => 'necessary' === $category->get_slug() || $category->get_sell_personal_data() === false ? true : false,
				),
			);
		}
		return $cookie_groups;
	}
	/**
	 * Get cookies by category
	 *
	 * @param object $category Category object.
	 * @return array
	 */
	public function get_cookies( $category = '' ) {
		$cookies  = array();
		$cat_slug = $category->get_slug();
		$items    = \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller::get_instance()->get_items_by_category( $category->get_id() );
		foreach ( $items as $item ) {
			$cookie    = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie( $item->cookie_id );
			$cookies[] = array(
				'cookieID' => $cookie->get_name(),
				'domain'   => $cookie->get_domain(),
				'provider' => $cookie->get_url_pattern(),
			);
			$provider  = $cookie->get_url_pattern();
			if ( '' !== $provider && 'necessary' !== $cat_slug ) {
				if ( ! isset( $this->providers[ $provider ] ) ) {
					$this->providers[ $provider ] = array();
				}
				if ( isset( $this->providers[ $provider ] ) && ! in_array( $cat_slug, $this->providers[ $provider ], true ) ) {
					$this->providers[ $provider ][] = $cat_slug;
				}
			}
		}
		return $cookies;
	}

	/**
	 * Prepare the HTML elements tags for front-end script.
	 *
	 * @return array
	 */
	public function prepare_tags() {
		$data = array();
		if ( ! $this->banner ) {
			return;
		}
		$settings  = $this->banner->get_settings();
		$configs   = isset( $settings['config'] ) ? $settings['config'] : array();
		$supported = array(
			'accept-button',
			'reject-button',
			'settings-button',
			'readmore-button',
			'donotsell-button',
			'show-desc-button',
			'hide-desc-button',
			'faz-always-active',
			'faz-link',
			'accept-button',
			'revisit-consent',
		);
		foreach ( $supported as $tag ) {
			$config = faz_array_search( $configs, 'tag', $tag );
			$data[] = array(
				'tag'    => $tag,
				'styles' => isset( $config['styles'] ) ? $config['styles'] : array(),
			);
		}
		return $data;
	}

	/**
	 * Prepare config for the front-end processing
	 *
	 * @return array
	 */
	public function prepare_config() {
		$data   = array();
		$banner = $this->banner;

		if ( ! $banner ) {
			return $data;
		}

		$properties                                   = $banner->get_settings();
		$data['settings']['type']                     = $properties['settings']['type'];
		$data['settings']['preferenceCenterType']     = $properties['settings']['type'] === "classic" ? "pushdown" : $properties['settings']['preferenceCenterType'];
		$data['settings']['position']                 = $properties['settings']['position'];
		$data['settings']['applicableLaw']            = $properties['settings']['applicableLaw'];
		$data['behaviours']['reloadBannerOnAccept']   = $properties['behaviours']['reloadBannerOnAccept']['status'];
		$data['behaviours']['loadAnalyticsByDefault'] = $properties['behaviours']['loadAnalyticsByDefault']['status'];
		$data['behaviours']['animations']             = $properties['behaviours']['animations'];
		$data['config']['revisitConsent']             = $properties['config']['revisitConsent'];
		$data['config']['preferenceCenter']['toggle'] = $properties['config']['preferenceCenter']['elements']['categories']['elements']['toggle'];
		$data['config']['categoryPreview']['status']  = $properties['config']['categoryPreview']['status'];
		$data['config']['categoryPreview']['toggle']  = $properties['config']['categoryPreview']['elements']['toggle'];
		$data['config']['videoPlaceholder']['status'] = $properties['config']['videoPlaceholder']['status'];
		$data['config']['videoPlaceholder']['styles'] = array_merge( $properties['config']['videoPlaceholder']['styles'], $properties['config']['videoPlaceholder']['elements']['title']['styles'] );
		$data['config']['readMore']                   = $properties['config']['notice']['elements']['buttons']['elements']['readMore'];
		$data['config']['showMore']                    = $properties['config']['accessibilityOverrides']['elements']['preferenceCenter']['elements']['showMore'] ?? array();
		$data['config']['showLess']                    = $properties['config']['accessibilityOverrides']['elements']['preferenceCenter']['elements']['showLess'] ?? array();
		$data['config']['alwaysActive']                = $properties['config']['accessibilityOverrides']['elements']['preferenceCenter']['elements']['alwaysActive'] ?? array();
		$data['config']['manualLinks']                 = $properties['config']['accessibilityOverrides']['elements']['manualLinks'] ?? array();
		$data['config']['auditTable']['status']       = $properties['config']['auditTable']['status'];
		$data['config']['optOption']['status']        = $properties['config']['optoutPopup']['elements']['optOption']['status'];
		$data['config']['optOption']['toggle']        = $properties['config']['optoutPopup']['elements']['optOption']['elements']['toggle'];
		return $data;
	}

	/**
	 * Prepare shortcodes to be used on visitor side.
	 *
	 * @param array $properties Banner properties.
	 * @return array
	 */
	public function prepare_shortcodes( $properties = array() ) {

		$settings   = isset( $properties['settings'] ) ? $properties['settings'] : array();
		$version_id = isset( $settings['versionID'] ) ? $settings['versionID'] : 'default';
		$shortcodes = new \FazCookie\Frontend\Modules\Shortcodes\Shortcodes( $this->banner, $version_id );
		$data       = array();
		$configs    = ( isset( $properties['config'] ) && is_array( $properties['config'] ) ) ? $properties['config'] : array();
		$config     = faz_array_search( $configs, 'tag', 'readmore-button' );
		$attributes = array();
		if ( isset( $config['meta']['noFollow'] ) && true === $config['meta']['noFollow'] ) {
			$attributes['rel'] = 'nofollow';
		}
		if ( isset( $config['meta']['newTab'] ) && true === $config['meta']['newTab'] ) {
			$attributes['target'] = '_blank';
		}
		$data[] = array(
			'key'        => 'faz_readmore',
			'content'    => do_shortcode( '[faz_readmore]' ),
			'tag'        => 'readmore-button',
			'status'     => isset( $config['status'] ) && true === $config['status'] ? true : false,
			'attributes' => $attributes,
		);
		$data[] = array(
			'key'        => 'faz_show_desc',
			'content'    => do_shortcode( '[faz_show_desc]' ),
			'tag'        => 'show-desc-button',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_hide_desc',
			'content'    => do_shortcode( '[faz_hide_desc]' ),
			'tag'        => 'hide-desc-button',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_optout_show_desc',
			'content'    => do_shortcode( '[faz_optout_show_desc]' ),
			'tag'        => 'optout-show-desc-button',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_optout_hide_desc',
			'content'    => do_shortcode( '[faz_optout_hide_desc]' ),
			'tag'        => 'optout-hide-desc-button',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_category_toggle_label',
			'content'    => do_shortcode( '[faz_category_toggle_label]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_enable_category_label',
			'content'    => do_shortcode( '[faz_enable_category_label]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_disable_category_label',
			'content'    => do_shortcode( '[faz_disable_category_label]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);

		$data[] = array(
			'key'        => 'faz_video_placeholder',
			'content'    => do_shortcode( '[faz_video_placeholder]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_enable_optout_label',
			'content'    => do_shortcode( '[faz_enable_optout_label]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_disable_optout_label',
			'content'    => do_shortcode( '[faz_disable_optout_label]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_optout_toggle_label',
			'content'    => do_shortcode( '[faz_optout_toggle_label]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_optout_option_title',
			'content'    => do_shortcode( '[faz_optout_option_title]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_optout_close_label',
			'content'    => do_shortcode( '[faz_optout_close_label]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);
		$data[] = array(
			'key'        => 'faz_preference_close_label',
			'content'    => do_shortcode( '[faz_preference_close_label]' ),
			'tag'        => '',
			'status'     => true,
			'attributes' => array(),
		);
		return $data;
	}

	/**
	 * Determines whether the current/given language code is right-to-left (RTL)
	 *
	 * @param string $language Current language.
	 * @return boolean
	 */
	public function is_rtl( $language = '' ) {
		if ( ! $language ) {
			$language = faz_current_language();
		}

		return in_array( $language, array( 'ar', 'az', 'dv', 'he', 'ku', 'fa', 'ur' ), true );
	}

	/**
	 * Check whether the WP Consent API plugin is enabled
	 *
	 * @return boolean
	 */
	public function is_wpconsentapi_enabled() {
		return class_exists( 'WP_CONSENT_API' );
	}

	/**
	 * Check whether the Google Site Kit plugin is enabled
	 *
	 * @return boolean
	 */
	public function is_gsk_enabled() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'google-site-kit/google-site-kit.php' );
	}
}
