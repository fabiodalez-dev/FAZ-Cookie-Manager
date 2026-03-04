<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://fabiodalez.it/
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
use FazCookie\Includes\Gvl;
use FazCookie\Includes\Cookie_Table_Shortcode;
/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    FazCookie
 * @subpackage FazCookie\Frontend
 * @author     Fabio D'Alessandro
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
		$this->settings = new Settings();
		$this->gcm_settings = new Gcm_Settings();
		new Consent_Logger();
		new Cookie_Table_Shortcode();
		add_action( 'init', array( $this, 'load_banner' ) );
		add_action( 'wp_footer', array( $this, 'banner_html' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 1 );
		add_action( 'wp_head', array( $this, 'insert_styles' ) );
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

			// CSS specificity isolation against page builders (Elementor, Divi,
			// Beaver Builder).  Two layers:
			// 1. Prefix ALL template selectors with #faz-consent (specificity 100+)
			//    so every rule beats class-based page-builder overrides.
			// 2. Baseline * reset (specificity 100) for inherited text properties
			//    that builders inject globally; prefixed template rules (110+)
			//    override this where needed.
			$css = $this->boost_css_specificity( $css );
			$css_reset = '#faz-consent,#faz-consent *,#faz-consent *::before,#faz-consent *::after{'
				. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;'
				. 'letter-spacing:normal;'
				. 'text-transform:none;'
				. 'font-style:normal;'
				. 'text-decoration:none;'
				. 'word-spacing:normal;'
				. 'line-height:1.5;'
				. 'box-sizing:border-box;'
				. '}';
			// "Always active" label: push right, next to the toggle.
			$css_fixes = '#faz-consent .faz-accordion-header .faz-always-active,'
				. '.faz-modal .faz-accordion-header .faz-always-active{'
				. 'margin-left:auto;margin-right:8px;white-space:nowrap;'
				. '}';
			$css = $css_reset . $css . $css_fixes;

			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/script' . $suffix . '.js', array(), $this->version, false );
			wp_localize_script( $this->plugin_name, '_fazConfig', $this->get_store_data() );
			wp_localize_script( $this->plugin_name, '_fazStyles', array( 'css' => $css ) );

			// GCM (Google Consent Mode) in local mode.
			if ( true === $this->gcm_settings->is_gcm_enabled() ) {
				$gcm      = $this->get_gcm_data();
				$gcm_json = wp_json_encode( $gcm );
				wp_add_inline_script( $this->plugin_name, 'var _fazGcm = ' . $gcm_json . ';', 'before' );
				$gcm_suffix = ''; // Always load non-minified (no build tooling).
				$gcm_handle = $this->plugin_name . '-gcm';
				wp_enqueue_script( $gcm_handle, plugin_dir_url( __FILE__ ) . 'js/gcm' . $gcm_suffix . '.js', array(), $this->version, false );
			}

			// IAB TCF v2.3 CMP stub (when IAB is enabled in settings).
			$iab_enabled = (bool) $this->settings->get( 'iab', 'enabled' );
			if ( $iab_enabled ) {
				// Early command-queue stub so ad scripts can call __tcfapi before CMP loads.
				// Handles 'ping' directly so pre-CMP callers get a valid response.
				$tcf_stub = 'if(typeof window.__tcfapi!=="function"){var a=[];window.__tcfapi=function(cmd,ver,cb){if(cmd==="ping"){cb({gdprApplies:undefined,cmpLoaded:false,cmpStatus:"stub",displayStatus:"hidden",apiVersion:"2.2"},true);return;}a.push(arguments);};window.__tcfapi.a=a;}';
				wp_add_inline_script( $this->plugin_name, $tcf_stub, 'before' );
				$tcf_suffix = ''; // Always load non-minified (no build tooling).
				$tcf_handle = $this->plugin_name . '-tcf-cmp';
				wp_enqueue_script( $tcf_handle, plugin_dir_url( __FILE__ ) . 'js/tcf-cmp' . $tcf_suffix . '.js', array( $this->plugin_name ), $this->version, false );

				// PublisherCC: use admin setting, fall back to site locale.
				$saved_cc     = $this->settings->get( 'iab', 'publisher_cc' );
				$country_code = ! empty( $saved_cc ) ? strtoupper( sanitize_text_field( $saved_cc ) ) : '';
				if ( ! preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
					$site_locale = (string) get_locale();
					if ( preg_match( '/^[a-z]{2}[_-]([A-Z]{2})/i', $site_locale, $matches ) ) {
						$country_code = strtoupper( $matches[1] );
					} else {
						$country_code = 'IT';
					}
				}

				// ConsentLanguage: use current banner language (uppercase 2-char ISO 639-1).
				$consent_lang = strtoupper( substr( faz_current_language(), 0, 2 ) );
				if ( ! preg_match( '/^[A-Z]{2}$/', $consent_lang ) ) {
					$consent_lang = 'EN';
				}

				// gdprApplies: true when visitor is in EU/EEA or country unknown (safe default).
				$visitor_country = Geolocation::get_country();
				$gdpr_applies    = empty( $visitor_country ) ? 'true' : ( Geolocation::is_eu() ? 'true' : 'false' );

				// Build TCF config with GVL data if available.
				$tcf_config = array(
					'publisherCC'         => $country_code,
					'consentLanguage'     => $consent_lang,
					'gdprApplies'         => 'true' === $gdpr_applies,
					'cmpId'               => absint( $this->settings->get( 'iab', 'cmp_id' ) ),
					'purposeOneTreatment' => (bool) $this->settings->get( 'iab', 'purpose_one_treatment' ),
				);

				$gvl = Gvl::get_instance();
				if ( $gvl->has_data() ) {
					$tcf_config['gvlVersion']       = $gvl->get_version();
					$tcf_config['purposes']         = $gvl->get_purposes( strtolower( $consent_lang ) );
					$tcf_config['specialPurposes']  = $gvl->get_special_purposes();
					$tcf_config['features']         = $gvl->get_features();
					$tcf_config['specialFeatures']  = $gvl->get_special_features();

					$selected_ids = (array) get_option( 'faz_gvl_selected_vendors', array() );
					if ( ! empty( $selected_ids ) ) {
						$tcf_config['selectedVendors'] = array_map( 'absint', $selected_ids );
						$selected_vendors = $gvl->get_vendors( $selected_ids );
						$compact_vendors  = array();
						foreach ( $selected_vendors as $vid => $v ) {
							$compact_vendors[ $vid ] = array(
								'name'           => isset( $v['name'] ) ? $v['name'] : '',
								'purposes'       => isset( $v['purposes'] ) ? $v['purposes'] : array(),
								'legIntPurposes' => isset( $v['legIntPurposes'] ) ? $v['legIntPurposes'] : array(),
								'features'       => isset( $v['features'] ) ? $v['features'] : array(),
								'specialFeatures' => isset( $v['specialFeatures'] ) ? $v['specialFeatures'] : array(),
							);
						}
						$tcf_config['vendors'] = $compact_vendors;
					}
				}

				wp_add_inline_script(
					$tcf_handle,
					'window._fazTcfConfig=' . wp_json_encode( $tcf_config ) . ';',
					'before'
				);
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
					"if(d.action==='init')return;" .
					"if(d.action==='all')fazTrack('banner_accept');" .
					"else if(d.action==='reject')fazTrack('banner_reject');" .
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

		// IAB vendor data for preference center.
		$iab_enabled = (bool) $this->settings->get( 'iab', 'enabled' );
		$store['_iabEnabled'] = $iab_enabled;
		if ( $iab_enabled ) {
			$gvl = Gvl::get_instance();
			if ( $gvl->has_data() ) {
				$selected_ids = (array) get_option( 'faz_gvl_selected_vendors', array() );
				if ( ! empty( $selected_ids ) ) {
					$selected_vendors = $gvl->get_vendors( $selected_ids );
					$vendor_data = array();
					foreach ( $selected_vendors as $vid => $v ) {
						$vendor_data[] = array(
							'id'             => absint( $vid ),
							'name'           => isset( $v['name'] ) ? $v['name'] : '',
							'purposes'       => isset( $v['purposes'] ) ? $v['purposes'] : array(),
							'legIntPurposes' => isset( $v['legIntPurposes'] ) ? $v['legIntPurposes'] : array(),
							'features'       => isset( $v['features'] ) ? $v['features'] : array(),
							'policyUrl'      => isset( $v['policyUrl'] ) ? $v['policyUrl'] : '',
							'cookieMaxAgeSeconds' => isset( $v['cookieMaxAgeSeconds'] ) ? $v['cookieMaxAgeSeconds'] : null,
						);
					}
					$store['_iabVendors'] = $vendor_data;
				}
				$purposes = $gvl->get_purposes( faz_current_language() );
				$purpose_data = array();
				foreach ( $purposes as $pid => $p ) {
					$purpose_data[] = array(
						'id'   => isset( $p['id'] ) ? $p['id'] : absint( $pid ),
						'name' => isset( $p['name'] ) ? $p['name'] : '',
					);
				}
				$store['_iabPurposes'] = $purpose_data;
			}
		}

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
	/**
	 * Check if a cookie name is a WordPress-internal cookie.
	 * These are admin-only cookies that visitors never receive
	 * and must never appear in the consent banner.
	 *
	 * @param string $name Cookie name.
	 * @return bool
	 */
	public static function is_wp_internal_cookie( $name ) {
		// Exact matches.
		if ( 'wordpress_test_cookie' === $name ) {
			return true;
		}
		// Prefix matches (these have dynamic suffixes like hashes or user IDs).
		$prefixes = array(
			'wordpress_logged_in_',
			'wordpress_sec_',
			'wp-settings-',
			'wp-settings-time-',
			'wp-postpass_',
		);
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	public function get_cookie_groups() {
		$cookie_groups = array();
		$categories    = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items();

		foreach ( $categories as $category ) {
			$category        = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $category );
			if ( false === $category->get_visibility() ) {
				continue;
			}
			// Never show internal categories in the frontend banner.
			if ( 'wordpress-internal' === $category->get_slug() ) {
				continue;
			}
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
	 * @param object|null $category Category object.
	 * @return array
	 */
	public function get_cookies( $category = null ) {
		if ( ! $category instanceof \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories ) {
			return array();
		}
		$cookies  = array();
		$cat_slug = $category->get_slug();
		$items    = \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller::get_instance()->get_items_by_category( $category->get_id() );
		foreach ( $items as $item ) {
			$cookie = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie( $item->cookie_id );
			// Skip WordPress-internal cookies — visitors never receive them.
			if ( self::is_wp_internal_cookie( $cookie->get_name() ) ) {
				continue;
			}
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
	 * Prefix all CSS selectors with #faz-consent to boost specificity above
	 * page-builder rules (Elementor, Divi, Beaver Builder).
	 *
	 * Three cases:
	 *  1. Container-level selectors (.faz-consent-container, position classes)
	 *     → compound: #faz-consent.class (no space).
	 *  2. Sibling elements (overlay, revisit widget, utility .faz-hide)
	 *     → leave unprefixed (they live outside #faz-consent in the DOM).
	 *  3. Everything else (descendants inside the banner)
	 *     → descendant: #faz-consent .class (with space).
	 *
	 * @param string $css Raw template CSS.
	 * @return string CSS with selectors scoped to #faz-consent.
	 */
	private function boost_css_specificity( $css ) {
		if ( empty( $css ) ) {
			return $css;
		}

		// Position/state classes applied directly ON .faz-consent-container.
		$container_classes = array(
			'.faz-classic-top',
			'.faz-classic-bottom',
			'.faz-banner-top',
			'.faz-banner-bottom',
			'.faz-box-bottom-left',
			'.faz-box-bottom-right',
			'.faz-box-top-left',
			'.faz-box-top-right',
		);

		// Classes on sibling elements (outside #faz-consent in the DOM).
		$sibling_prefixes = array(
			'.faz-overlay',
			'.faz-btn-revisit',
			'.faz-revisit-',
			'.faz-hide',
			'.faz-modal',
		);

		// Classes inside .faz-modal (popup/sidebar) OR inside #faz-consent (classic).
		// These need dual selectors to match in both template structures.
		$modal_prefixes = array(
			'.faz-preference',
			'.faz-prefrence',
			'.faz-accordion',
			'.faz-audit',
			'.faz-cookie-des',
			'.faz-always-active',
			'.faz-switch',
			'.faz-chevron',
			'.faz-show-desc',
			'.faz-hide-desc',
			'.faz-btn',
			'.faz-category',
			'.faz-notice',
			'.faz-opt-out',
			'.faz-footer',
			'.faz-iab-vendors',
			'.faz-vendor-',
		);

		return preg_replace_callback(
			'/([^{}]+?)(\{)/',
			function ( $m ) use ( $container_classes, $sibling_prefixes, $modal_prefixes ) {
				$raw = $m[1];
				// Skip @-rules (e.g. @media).
				if ( strpos( $raw, '@' ) !== false ) {
					return $m[0];
				}
				$parts = explode( ',', $raw );
				$out   = array();
				foreach ( $parts as $sel ) {
					$s = trim( $sel );
					if ( '' === $s ) {
						continue;
					}

					// Rule 1: .faz-consent-container → replace with #faz-consent.
					if ( strpos( $s, '.faz-consent-container' ) === 0 ) {
						$out[] = '#faz-consent' . substr( $s, 22 );
						continue;
					}

					// Rule 2: Container position classes → compound (no space).
					$matched = false;
					foreach ( $container_classes as $cls ) {
						if ( strpos( $s, $cls ) === 0 ) {
							$out[]   = '#faz-consent' . $s;
							$matched = true;
							break;
						}
					}
					if ( $matched ) {
						continue;
					}

					// Rule 3: Sibling elements → leave as-is.
					foreach ( $sibling_prefixes as $pfx ) {
						if ( strpos( $s, $pfx ) === 0 ) {
							$out[]   = $s;
							$matched = true;
							break;
						}
					}
					if ( $matched ) {
						continue;
					}

					// Rule 3b: Modal descendants → dual selector for popup + classic.
					foreach ( $modal_prefixes as $pfx ) {
						if ( strpos( $s, $pfx ) === 0 ) {
							$out[]   = '#faz-consent ' . $s . ',.faz-modal ' . $s;
							$matched = true;
							break;
						}
					}
					if ( $matched ) {
						continue;
					}

					// Rule 4: Descendants → prefix with space.
					$out[] = '#faz-consent ' . $s;
				}
				return implode( ',', $out ) . '{';
			},
			$css
		);
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
