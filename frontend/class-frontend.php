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
use FazCookie\Includes\Known_Providers;
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
	 * Per-request cache for blocked categories and provider map.
	 *
	 * @var array|null
	 */
	private $blocked_categories_cache = null;
	private $provider_map_cache       = null;
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
		add_action( 'template_redirect', array( $this, 'start_output_buffer' ) );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_loader_tag' ), 10, 3 );
		add_filter( 'style_loader_tag', array( $this, 'filter_style_loader_tag' ), 10, 4 );
		add_action( 'send_headers', array( $this, 'shred_non_consented_cookies' ) );

		// Content-level blocking (defense-in-depth — runs before output buffer).
		add_filter( 'the_content', array( $this, 'filter_content_blocking' ), 1000 );
		add_filter( 'widget_text', array( $this, 'filter_content_blocking' ), 1000 );
		add_filter( 'widget_block_content', array( $this, 'filter_content_blocking' ), 1000 );
		add_filter( 'embed_oembed_html', array( $this, 'filter_oembed_blocking' ), 1000, 2 );
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
			$css = $this->get_boosted_css();

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
				$tcf_stub = 'if(typeof window.__tcfapi!=="function"){var a=[];window.__tcfapi=function(cmd,ver,cb){if(cmd==="ping"){cb({gdprApplies:undefined,cmpLoaded:false,cmpStatus:"stub",displayStatus:"hidden",apiVersion:"2.3"},true);return;}a.push(arguments);};window.__tcfapi.a=a;}';
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
					"if(!d.action||d.action==='init')return;" .
					"if(typeof _fazConsentLog==='undefined')return;" .
					"fetch(_fazConsentLog.restUrl,{" .
						"method:'POST'," .
						"headers:{'Content-Type':'application/json','X-WP-Nonce':_fazConsentLog.nonce}," .
						"body:JSON.stringify({" .
							"consent_id:(function(){var m=document.cookie.match(/consentid:([^,;]+)/);return m?m[1]:''})()," .
							"status:d.action==='reject'?'rejected':d.action==='all'?'accepted':'partial'," .
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
		echo '<style id="faz-style-inline">[data-faz-tag]{visibility:hidden;}'
			. '.faz-iframe-placeholder{position:relative;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:8px;min-height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden}'
			. '.faz-iframe-placeholder-inner{text-align:center;padding:32px 24px;color:#555;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
			. '.faz-iframe-placeholder-inner svg{color:#999;margin-bottom:12px}'
			. '.faz-iframe-placeholder-inner p{margin:0 0 16px;font-size:14px;line-height:1.5}'
			. '.faz-iframe-placeholder-btn{display:inline-block;padding:10px 24px;background:#1863DC;color:#fff;border:none;border-radius:6px;font-size:14px;cursor:pointer;transition:background .2s}'
			. '.faz-iframe-placeholder-btn:hover{background:#1352b5}'
			. '.faz-iframe-placeholder--video{min-height:300px}'
			. '.faz-iframe-placeholder--video .faz-iframe-placeholder-inner{position:relative;z-index:2;background:rgba(0,0,0,.65);border-radius:8px;padding:24px 32px;color:#fff}'
			. '.faz-iframe-placeholder--video .faz-iframe-placeholder-inner svg{color:#fff}'
			. '.faz-iframe-placeholder--video .faz-iframe-placeholder-inner p{color:#eee}'
			. '.faz-iframe-placeholder-thumb{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:8px}'
			. '.faz-social-placeholder{min-height:120px}'
			. '</style>';
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
		// Merge DB-based providers with Known_Providers for client-side blocking.
		$valid_categories = $this->get_valid_category_slugs();
		$known            = Known_Providers::get_all();
		foreach ( $known as $service ) {
			if ( 'necessary' === $service['category'] ) {
				continue;
			}
			if ( ! in_array( $service['category'], $valid_categories, true ) ) {
				continue;
			}
			foreach ( $service['patterns'] as $pattern ) {
				if ( ! isset( $this->providers[ $pattern ] ) ) {
					$this->providers[ $pattern ] = array( $service['category'] );
				}
			}
		}

		// 3. Admin custom blocking rules (Settings → Script Blocking).
		$custom_rules = isset( $settings['script_blocking']['custom_rules'] ) ? $settings['script_blocking']['custom_rules'] : array();
		foreach ( $custom_rules as $rule ) {
			$pattern  = isset( $rule['pattern'] ) ? $rule['pattern'] : '';
			$category = isset( $rule['category'] ) ? $rule['category'] : '';
			if ( ! empty( $pattern ) && ! empty( $category ) && in_array( $category, $valid_categories, true ) ) {
				$this->providers[ $pattern ] = array( $category );
			}
		}

		// 4. Developer filter.
		$this->providers = apply_filters( 'faz_blocking_rules_client', $this->providers );

		foreach ( $this->providers as $key => $value ) {
			$providers[] = array(
				're'         => $key,
				'categories' => $value,
			);
		}
		$store['_providersToBlock'] = $providers;

		// Cookie-to-category map for client-side cookie cleanup on consent revocation.
		$cookie_category_map = array();
		$known_cookies = Known_Providers::get_cookie_map();
		foreach ( $known_cookies as $cookie_pattern => $category ) {
			if ( 'necessary' === $category ) {
				continue;
			}
			if ( ! in_array( $category, $valid_categories, true ) ) {
				continue;
			}
			$cookie_category_map[ $cookie_pattern ] = $category;
		}
		$store['_cookieCategoryMap'] = $cookie_category_map;

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

	/* ─── Server-side script blocking via output buffering ───── */

	/**
	 * Start output buffering to intercept and block third-party scripts
	 * before they reach the browser. This catches inline scripts that the
	 * client-side MutationObserver cannot intercept.
	 */
	public function start_output_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! $this->template ) {
			return;
		}
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() ) {
			return;
		}
		ob_start( array( $this, 'process_output_buffer' ) );
	}

	/**
	 * Process the output buffer: find scripts, iframes, images, and CSS links
	 * that belong to blocked providers and neutralise them.
	 *
	 * - Scripts: type → text/plain, add data-faz-category.
	 * - Iframes: src → data-faz-src, add data-faz-category.
	 * - Images:  src → data-faz-src, add data-faz-category.
	 * - CSS:     href → data-faz-href, add data-faz-category.
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	public function process_output_buffer( $html ) {
		if ( empty( $html ) ) {
			return $html;
		}

		$blocked_categories = $this->get_blocked_categories();
		if ( empty( $blocked_categories ) ) {
			return $html;
		}

		$providers = $this->get_provider_category_map();
		if ( empty( $providers ) ) {
			return $html;
		}

		// 1. Block <script> tags.
		if ( false !== strpos( $html, '<script' ) ) {
			$html = preg_replace_callback(
				'#<script\b([^>]*)>(.*?)</script>#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_script_tag( $m, $providers, $blocked_categories );
				},
				$html
			);
		}

		// 2. Block <iframe> tags (YouTube, Facebook, Maps, etc.).
		if ( false !== strpos( $html, '<iframe' ) ) {
			$html = preg_replace_callback(
				'#<iframe\b([^>]*)(?:>(.*?)</iframe>|/>)#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_iframe_tag( $m, $providers, $blocked_categories );
				},
				$html
			);
		}

		// 3. Block tracking pixel <img> inside <noscript> (Meta Pixel, etc.).
		if ( false !== strpos( $html, '<noscript' ) ) {
			$html = preg_replace_callback(
				'#<noscript\b[^>]*>(.*?)</noscript>#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_noscript_tag( $m, $providers, $blocked_categories );
				},
				$html
			);
		}

		// 4. Block <link rel="stylesheet"> (Google Fonts, Adobe Fonts, etc.).
		if ( false !== strpos( $html, '<link' ) ) {
			$html = preg_replace_callback(
				'#<link\b([^>]*rel\s*=\s*["\']stylesheet["\'][^>]*)/?>#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_link_tag( $m, $providers, $blocked_categories );
				},
				$html
			);
		}

		// 5. Block <script data-faz-waitfor="category"> (deferred dependency scripts).
		if ( false !== strpos( $html, 'data-faz-waitfor' ) ) {
			$html = preg_replace_callback(
				'#<script\b([^>]*data-faz-waitfor\s*=\s*["\']([^"\']+)["\'][^>]*)>(.*?)</script>#is',
				function ( $m ) use ( $blocked_categories ) {
					$attrs    = $m[1];
					$wait_cat = $m[2];
					$content  = $m[3];
					if ( ! in_array( $wait_cat, $blocked_categories, true ) ) {
						return $m[0]; // Category is allowed — let it run.
					}
					// Block: change type to text/plain so JS won't execute.
					$new_attrs = $this->set_script_type_plain( $attrs );
					return '<script' . $new_attrs . '>' . $content . '</script>';
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Process a single <script> tag for blocking.
	 *
	 * @param array $m               Regex match.
	 * @param array $providers       Provider→category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_script_tag( $m, $providers, $blocked_categories ) {
		$attrs   = $m[1];
		$content = $m[2];
		$full    = $m[0];

		// Never block whitelisted scripts.
		if ( $this->is_whitelisted( $attrs, $content ) ) {
			return $full;
		}
		// Skip already-blocked scripts.
		if ( preg_match( '/type\s*=\s*["\']text\/plain["\']/', $attrs ) ) {
			return $full;
		}
		// Skip scripts already tagged with data-fazcookie.
		if ( false !== strpos( $attrs, 'data-fazcookie' ) ) {
			return $full;
		}
		// Skip non-executable script types (structured data, templates, etc.).
		if ( preg_match( '/type\s*=\s*["\'](?:application\/(?:ld\+json|json)|text\/(?:template|html)|importmap)["\']/', $attrs ) ) {
			return $full;
		}

		$matched_category = $this->match_script_to_provider( $attrs, $content, $providers );
		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			return $full;
		}

		$new_attrs = $this->set_script_type_plain( $attrs );
		$new_attrs .= ' data-faz-category="' . esc_attr( $matched_category ) . '"';
		return '<script' . $new_attrs . '>' . $content . '</script>';
	}

	/**
	 * Process a single <iframe> tag for blocking.
	 *
	 * @param array $m               Regex match.
	 * @param array $providers       Provider→category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_iframe_tag( $m, $providers, $blocked_categories ) {
		$attrs = $m[1];
		$full  = $m[0];

		if ( $this->is_whitelisted( $attrs, '' ) ) {
			return $full;
		}
		if ( false !== strpos( $attrs, 'data-faz-src' ) ) {
			return $full;
		}

		$matched_category = $this->match_script_to_provider( $attrs, '', $providers );
		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			return $full;
		}

		// Rename src → data-faz-src (avoid matching data-src).
		$new_attrs = preg_replace( '/(^|\s)src\s*=\s*/i', '$1data-faz-src=', $attrs, 1 );
		$new_attrs .= ' data-faz-category="' . esc_attr( $matched_category ) . '"';
		$new_attrs .= ' style="display:none"';

		$inner = isset( $m[2] ) ? $m[2] : '';
		$blocked_iframe = isset( $m[2] )
			? '<iframe' . $new_attrs . '>' . $inner . '</iframe>'
			: '<iframe' . $new_attrs . '/>';

		// Detect the service label for the placeholder.
		$service_label = $this->get_service_label_from_attrs( $attrs );

		// Try to extract a video thumbnail (YouTube/Vimeo).
		$thumbnail_html = $this->get_video_thumbnail_html( $attrs );

		// Build placeholder.
		$placeholder = '<div class="faz-iframe-placeholder' . ( $thumbnail_html ? ' faz-iframe-placeholder--video' : '' ) . '" data-faz-category="' . esc_attr( $matched_category ) . '">'
			. $thumbnail_html
			. '<div class="faz-iframe-placeholder-inner">'
			. ( $thumbnail_html
				? '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>'
				: '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>'
			)
			. '<p>' . esc_html( sprintf(
				/* translators: %s: service name (e.g. "YouTube", "Google Maps") */
				__( 'This content is blocked because %s cookies have not been accepted.', 'faz-cookie-manager' ),
				$service_label ? $service_label : $matched_category
			) ) . '</p>'
			. '<button class="faz-iframe-placeholder-btn" onclick="(function(b){var p=b.closest(\'.faz-iframe-placeholder\');var cat=p.getAttribute(\'data-faz-category\');if(window._fazAcceptCategory){window._fazAcceptCategory(cat)}else{var el=document.querySelector(\'[data-faz-action=revisit-consent]\');if(el)el.click()}})(this)">'
			. esc_html__( 'Accept cookies', 'faz-cookie-manager' )
			. '</button>'
			. '</div>'
			. $blocked_iframe
			. '</div>';

		return $placeholder;
	}

	/**
	 * Try to determine the service label from iframe attributes.
	 *
	 * @param string $attrs Iframe attribute string.
	 * @return string|false Service label or false.
	 */
	private function get_service_label_from_attrs( $attrs ) {
		$all = Known_Providers::get_all();
		foreach ( $all as $service ) {
			foreach ( $service['patterns'] as $pattern ) {
				if ( false !== stripos( $attrs, $pattern ) ) {
					return $service['label'];
				}
			}
		}
		return false;
	}

	/**
	 * Extract a video thumbnail URL from iframe attributes (YouTube/Vimeo).
	 *
	 * @param string $attrs Iframe attribute string.
	 * @return string HTML for the thumbnail background, or empty string.
	 */
	private function get_video_thumbnail_html( $attrs ) {
		// YouTube: extract video ID. img.youtube.com is a static CDN (no cookies/tracking).
		if ( preg_match( '/youtube(?:-nocookie)?\.com\/embed\/([a-zA-Z0-9_-]{11})/', $attrs, $yt ) ) {
			$thumb_url = 'https://img.youtube.com/vi/' . $yt[1] . '/hqdefault.jpg';
			return '<img class="faz-iframe-placeholder-thumb" src="' . esc_url( $thumb_url ) . '" alt="" loading="lazy"/>';
		}
		return '';
	}

	/**
	 * Process a <noscript> block — block tracking pixel <img> tags inside.
	 *
	 * @param array $m               Regex match.
	 * @param array $providers       Provider→category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_noscript_tag( $m, $providers, $blocked_categories ) {
		$full    = $m[0];
		$content = $m[1];

		// Only process if the noscript contains an <img> with a known provider.
		if ( false === strpos( $content, '<img' ) ) {
			return $full;
		}

		$matched_category = $this->match_script_to_provider( '', $content, $providers );
		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			return $full;
		}

		// Block by replacing img src → data-faz-src inside the noscript.
		$blocked_content = preg_replace( '/(<img\b[^>]*)(^|\s)src\s*=/i', '$1$2data-faz-src=', $content );
		$blocked_content = preg_replace( '/(<img\b)/', '$1 data-faz-category="' . esc_attr( $matched_category ) . '"', $blocked_content );
		return str_replace( $content, $blocked_content, $full );
	}

	/**
	 * Process a <link rel="stylesheet"> tag for blocking.
	 *
	 * @param array $m               Regex match.
	 * @param array $providers       Provider→category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_link_tag( $m, $providers, $blocked_categories ) {
		$attrs = $m[1];
		$full  = $m[0];

		if ( $this->is_whitelisted( $attrs, '' ) ) {
			return $full;
		}
		if ( false !== strpos( $attrs, 'data-faz-href' ) ) {
			return $full;
		}

		$matched_category = $this->match_script_to_provider( $attrs, '', $providers );
		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			return $full;
		}

		// Rename href → data-faz-href (avoid matching data-href).
		$new_attrs = preg_replace( '/(^|\s)href\s*=\s*/i', '$1data-faz-href=', $attrs, 1 );
		$new_attrs .= ' data-faz-category="' . esc_attr( $matched_category ) . '"';
		return '<link' . $new_attrs . '/>';
	}

	/**
	 * Check if a tag should be whitelisted (never blocked).
	 *
	 * @param string $attrs   Tag attributes.
	 * @param string $content Inline content (for scripts).
	 * @return bool
	 */
	private function is_whitelisted( $attrs, $content ) {
		$whitelist = apply_filters( 'faz_whitelisted_scripts', array(
			'faz-cookie-manager',
			'fazcookie',
			'fazBannerTemplate',
			'wp-includes/',
			'wp-admin/',
			'jquery.min.js',
			'jquery.js',
			'jquery-core',
			'jquery-migrate',
			'wp-embed',
		) );

		// Match against tag attributes only (src, id, class, etc.) to prevent
		// tracking scripts from bypassing the block by including whitelist
		// keywords in their inline content.
		foreach ( $whitelist as $pattern ) {
			if ( false !== stripos( $attrs, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Determine which cookie categories are currently blocked.
	 * On first visit (no consent cookie), all non-necessary categories are blocked.
	 *
	 * @return array Slugs of blocked categories.
	 */
	private function get_blocked_categories() {
		if ( null !== $this->blocked_categories_cache ) {
			return $this->blocked_categories_cache;
		}
		$consent = isset( $_COOKIE['fazcookie-consent'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['fazcookie-consent'] ) ) : '';
		$categories = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items();
		$blocked = array();

		foreach ( $categories as $cat_data ) {
			$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $cat_data );
			$slug     = $category->get_slug();
			if ( 'necessary' === $slug ) {
				continue;
			}
			if ( empty( $consent ) ) {
				// No consent yet — block all non-necessary.
				$blocked[] = $slug;
			} else {
				// Parse consent cookie: "consent:yes,necessary:yes,analytics:no,advertisement:no"
				if ( preg_match( '/(^|,)' . preg_quote( $slug, '/' ) . ':(\w+)/', $consent, $cm ) ) {
					if ( 'yes' !== $cm[2] ) {
						$blocked[] = $slug;
					}
				} else {
					// Category not mentioned in consent — treat as blocked.
					$blocked[] = $slug;
				}
			}
		}
		$this->blocked_categories_cache = $blocked;
		return $blocked;
	}

	/**
	 * Build a map of provider URL patterns → category slugs.
	 *
	 * Merges three sources:
	 * 1. url_pattern from cookie DB (existing, usually empty).
	 * 2. Known_Providers hardcoded database (comprehensive).
	 * 3. cookie domain from DB for third-party cookies.
	 *
	 * @return array [ 'connect.facebook.net' => 'marketing', ... ]
	 */
	private function get_provider_category_map() {
		if ( null !== $this->provider_map_cache ) {
			return $this->provider_map_cache;
		}
		// Force cookie groups to be loaded so $this->providers is populated.
		if ( empty( $this->providers ) ) {
			$this->get_cookie_groups();
		}
		$map = array();
		// 1. Existing: url_pattern from cookie DB.
		foreach ( $this->providers as $pattern => $cats ) {
			if ( ! empty( $cats ) ) {
				$map[ $pattern ] = $cats[0];
			}
		}

		// 2. Known providers database.
		$valid_categories = $this->get_valid_category_slugs();
		$known_map        = Known_Providers::get_pattern_map();
		foreach ( $known_map as $pattern => $category ) {
			if ( 'necessary' === $category ) {
				continue;
			}
			if ( ! in_array( $category, $valid_categories, true ) ) {
				continue;
			}
			if ( ! isset( $map[ $pattern ] ) ) {
				$map[ $pattern ] = $category;
			}
		}

		// 3. Admin custom blocking rules (Settings → Script Blocking).
		// Custom rules CAN override built-in providers (admin intent takes priority).
		$settings     = get_option( 'faz_settings', array() );
		$custom_rules = isset( $settings['script_blocking']['custom_rules'] ) ? $settings['script_blocking']['custom_rules'] : array();
		foreach ( $custom_rules as $rule ) {
			$pattern  = isset( $rule['pattern'] ) ? $rule['pattern'] : '';
			$category = isset( $rule['category'] ) ? $rule['category'] : '';
			if ( ! empty( $pattern ) && ! empty( $category ) ) {
				$map[ $pattern ] = $category;
			}
		}

		// 4. Developer filter (allows code-level custom rules).
		$map = apply_filters( 'faz_blocking_rules', $map );

		$this->provider_map_cache = $map;
		return $map;
	}

	/**
	 * Return an array of all valid (existing) category slugs in this install.
	 *
	 * @return string[]
	 */
	private function get_valid_category_slugs() {
		$categories = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items();
		$slugs      = array();
		foreach ( $categories as $cat_data ) {
			$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $cat_data );
			$slugs[]  = $category->get_slug();
		}
		return $slugs;
	}

	/**
	 * Check if a <script> tag (by src or inline content) matches a known provider.
	 *
	 * @param string $attrs   The tag's attribute string.
	 * @param string $content The inline script content.
	 * @param array  $providers Provider map from get_provider_category_map().
	 * @return string|false Matched category slug or false.
	 */
	private function match_script_to_provider( $attrs, $content, $providers ) {
		// Extract src or href if present (link tags use href, scripts use src).
		$url = '';
		if ( preg_match( '/(?:src|href)\s*=\s*["\']([^"\']+)["\']/', $attrs, $sm ) ) {
			$url = $sm[1];
		}

		$haystack = $url . ' ' . $content;

		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			if ( false !== stripos( $haystack, $pattern ) ) {
				return $category;
			}
		}
		return false;
	}

	/**
	 * Replace or insert type="text/plain" in a script tag's attributes.
	 *
	 * @param string $attrs Original attributes string.
	 * @return string Modified attributes string.
	 */
	private function set_script_type_plain( $attrs ) {
		if ( preg_match( '/type\s*=\s*["\']([^"\']*)["\']/', $attrs, $tm ) ) {
			$original = $tm[1];
			$attrs    = preg_replace( '/type\s*=\s*["\'][^"\']*["\']/', 'type="text/plain"', $attrs );
			// Preserve non-default types (e.g. "module") so JS can restore them.
			if ( 'text/plain' !== $original && 'text/javascript' !== $original && '' !== $original ) {
				$attrs .= ' data-faz-original-type="' . esc_attr( $original ) . '"';
			}
			return $attrs;
		}
		return $attrs . ' type="text/plain"';
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
			$cookie = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie( $item );
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
	 * Return the fully assembled banner CSS with specificity boosting, cached
	 * in a transient so the regex work only runs once per template change.
	 *
	 * @return string Complete CSS string ready for inline output.
	 */
	private function get_boosted_css() {
		$raw_css   = isset( $this->template['styles'] ) ? $this->template['styles'] : '';
		$cache_key = 'faz_boosted_css_' . FAZ_VERSION . '_' . md5( $raw_css );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$css       = $this->boost_css_specificity( $raw_css );
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
		$css_fixes = '#faz-consent .faz-accordion-header .faz-always-active,'
			. '.faz-modal .faz-accordion-header .faz-always-active{'
			. 'margin-left:auto;margin-right:8px;white-space:nowrap;'
			. '}';
		$css = $css_reset . $css . $css_fixes;

		set_transient( $cache_key, $css, DAY_IN_SECONDS );
		return $css;
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
	 * Filter WordPress-enqueued scripts via script_loader_tag.
	 *
	 * Intercepts scripts at registration time (before OB) so even late-enqueued
	 * scripts from third-party plugins get blocked.
	 *
	 * @param string $tag    Full <script> tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string Modified tag.
	 */
	public function filter_script_loader_tag( $tag, $handle, $src ) {
		if ( is_admin() ) {
			return $tag;
		}
		if ( ! $this->template ) {
			return $tag;
		}
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() ) {
			return $tag;
		}
		// Never block our own scripts.
		if ( $this->is_whitelisted( $tag, '' ) ) {
			return $tag;
		}

		$providers = $this->get_provider_category_map();
		if ( empty( $providers ) ) {
			return $tag;
		}

		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			// Match against handle, src, and full tag.
			if ( false !== stripos( $handle, $pattern ) || false !== stripos( $src, $pattern ) || false !== stripos( $tag, $pattern ) ) {
				$blocked = $this->get_blocked_categories();
				if ( in_array( $category, $blocked, true ) ) {
					// Replace any type attribute with text/plain, saving the original.
					if ( preg_match( '/type\s*=\s*[\'"]([^\'"]*)[\'"]/', $tag, $type_match ) ) {
						$original_type = $type_match[1];
						$tag = preg_replace( '/type\s*=\s*[\'"][^\'"]*[\'"]/', 'type="text/plain"', $tag, 1 );
						if ( 'text/plain' !== $original_type && 'text/javascript' !== $original_type ) {
							$tag = str_replace( '<script ', '<script data-faz-original-type="' . esc_attr( $original_type ) . '" ', $tag );
						}
					} else {
						$tag = str_replace( '<script ', '<script type="text/plain" ', $tag );
					}
					// Add category attribute.
					if ( false === strpos( $tag, 'data-faz-category' ) ) {
						$tag = str_replace( '<script ', '<script data-faz-category="' . esc_attr( $category ) . '" ', $tag );
					}
				}
				break;
			}
		}
		return $tag;
	}

	/**
	 * Filter WP-enqueued stylesheets (e.g. Google Fonts, Adobe Fonts).
	 *
	 * Replaces href with data-faz-href to prevent loading before consent.
	 *
	 * @param string $tag    Full <link> tag.
	 * @param string $handle Style handle.
	 * @param string $href   Stylesheet URL.
	 * @param string $media  Media attribute.
	 * @return string Modified tag.
	 */
	public function filter_style_loader_tag( $tag, $handle, $href, $media ) {
		if ( is_admin() ) {
			return $tag;
		}
		if ( ! $this->template ) {
			return $tag;
		}
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() ) {
			return $tag;
		}
		if ( $this->is_whitelisted( $tag, '' ) ) {
			return $tag;
		}

		$providers = $this->get_provider_category_map();
		if ( empty( $providers ) ) {
			return $tag;
		}

		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			if ( false !== stripos( $handle, $pattern ) || false !== stripos( $href, $pattern ) ) {
				$blocked = $this->get_blocked_categories();
				if ( in_array( $category, $blocked, true ) ) {
					$tag = preg_replace( '/(^|\s)href\s*=\s*/i', '$1data-faz-href=', $tag, 1 );
					if ( false === strpos( $tag, 'data-faz-category' ) ) {
						$tag = str_replace( '<link ', '<link data-faz-category="' . esc_attr( $category ) . '" ', $tag );
					}
				}
				break;
			}
		}
		return $tag;
	}

	/**
	 * Delete non-consented cookies before the page renders (cookie shredding).
	 *
	 * Runs on the send_headers hook. Compares cookies against the
	 * Known_Providers cookie map and deletes any that belong to
	 * categories the visitor has not consented to.
	 */
	public function shred_non_consented_cookies() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! $this->template ) {
			return;
		}
		if ( true === faz_disable_banner() ) {
			return;
		}

		$blocked_categories = $this->get_blocked_categories();
		if ( empty( $blocked_categories ) ) {
			return;
		}

		$cookie_map = Known_Providers::get_cookie_map();
		if ( empty( $cookie_map ) ) {
			return;
		}

		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$domain = preg_replace( '/^www\./', '', $host );

		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( $cookie_map as $pattern => $category ) {
				if ( ! in_array( $category, $blocked_categories, true ) ) {
					continue;
				}
				if ( $this->cookie_name_matches( $name, $pattern ) ) {
					setcookie( $name, '', -1, '/' );
					if ( $domain ) {
						setcookie( $name, '', -1, '/', $domain );
						setcookie( $name, '', -1, '/', '.' . $domain );
					}
					unset( $_COOKIE[ $name ] );
					break;
				}
			}
		}
	}

	/**
	 * Check if a cookie name matches a pattern.
	 * Supports exact match and wildcard * suffix.
	 *
	 * @param string $name    Cookie name.
	 * @param string $pattern Pattern (e.g. '_ga', '_ga_*', '_pk_id.*').
	 * @return bool
	 */
	private function cookie_name_matches( $name, $pattern ) {
		if ( $name === $pattern ) {
			return true;
		}
		// Wildcard patterns: _ga_*, _pk_id.*, etc.
		if ( false !== strpos( $pattern, '*' ) ) {
			$regex = '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
			return (bool) preg_match( $regex, $name );
		}
		return false;
	}

	/**
	 * Content-level blocking for the_content / widget_text / widget_block_content.
	 *
	 * Scans HTML fragments from post content and widgets for scripts, iframes,
	 * and social embeds that belong to blocked providers. This runs at priority
	 * 1000 (after all plugins have injected their markup) and provides defense-
	 * in-depth — the output buffer catches anything that slips through.
	 *
	 * @param string $content HTML content.
	 * @return string Modified content.
	 */
	public function filter_content_blocking( $content ) {
		if ( empty( $content ) || is_admin() ) {
			return $content;
		}
		if ( ! $this->template ) {
			return $content;
		}
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() ) {
			return $content;
		}

		$blocked_categories = $this->get_blocked_categories();
		if ( empty( $blocked_categories ) ) {
			return $content;
		}

		$providers = $this->get_provider_category_map();
		if ( empty( $providers ) ) {
			return $content;
		}

		// Block <script> tags in content.
		if ( false !== strpos( $content, '<script' ) ) {
			$content = preg_replace_callback(
				'#<script\b([^>]*)>(.*?)</script>#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_script_tag( $m, $providers, $blocked_categories );
				},
				$content
			);
		}

		// Block <iframe> tags in content.
		if ( false !== strpos( $content, '<iframe' ) ) {
			$content = preg_replace_callback(
				'#<iframe\b([^>]*)(?:>(.*?)</iframe>|/>)#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_iframe_tag( $m, $providers, $blocked_categories );
				},
				$content
			);
		}

		// Hide social embed containers that depend on blocked scripts.
		$content = $this->process_social_embeds( $content, $blocked_categories );

		return $content;
	}

	/**
	 * Block WordPress oEmbed HTML (YouTube, Vimeo, etc.).
	 *
	 * Intercepts oEmbed output before it reaches the page. If the embed URL
	 * matches a blocked provider, wraps the embed in a consent placeholder.
	 *
	 * @param string $html Embed HTML.
	 * @param string $url  Original URL.
	 * @return string Modified HTML.
	 */
	public function filter_oembed_blocking( $html, $url ) {
		if ( empty( $html ) || is_admin() ) {
			return $html;
		}
		if ( ! $this->template ) {
			return $html;
		}
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() ) {
			return $html;
		}

		$blocked_categories = $this->get_blocked_categories();
		if ( empty( $blocked_categories ) ) {
			return $html;
		}

		$providers = $this->get_provider_category_map();
		if ( empty( $providers ) ) {
			return $html;
		}

		// Check if the oEmbed URL matches a known provider.
		$matched_category = false;
		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			if ( false !== stripos( $url, $pattern ) || false !== stripos( $html, $pattern ) ) {
				if ( in_array( $category, $blocked_categories, true ) ) {
					$matched_category = $category;
					break;
				}
			}
		}

		if ( ! $matched_category ) {
			return $html;
		}

		// Detect service label.
		$service_label = false;
		$all = Known_Providers::get_all();
		foreach ( $all as $service ) {
			foreach ( $service['patterns'] as $pat ) {
				if ( false !== stripos( $url, $pat ) ) {
					$service_label = $service['label'];
					break 2;
				}
			}
		}

		// Try to get video thumbnail. img.youtube.com is a static CDN (no cookies/tracking).
		$thumbnail_html = '';
		if ( preg_match( '/youtube(?:-nocookie)?\.com\/(?:watch\?v=|embed\/)([a-zA-Z0-9_-]{11})/', $url, $yt ) ) {
			$thumb_url = 'https://img.youtube.com/vi/' . $yt[1] . '/hqdefault.jpg';
			$thumbnail_html = '<img class="faz-iframe-placeholder-thumb" src="' . esc_url( $thumb_url ) . '" alt="" loading="lazy"/>';
		} elseif ( preg_match( '/youtu\.be\/([a-zA-Z0-9_-]{11})/', $url, $yt ) ) {
			$thumb_url = 'https://img.youtube.com/vi/' . $yt[1] . '/hqdefault.jpg';
			$thumbnail_html = '<img class="faz-iframe-placeholder-thumb" src="' . esc_url( $thumb_url ) . '" alt="" loading="lazy"/>';
		}

		// Neutralize the embed: wrap iframes, disable scripts.
		$blocked_html = $html;
		// Rename iframe src to prevent loading.
		$blocked_html = preg_replace( '/(<iframe\b[^>]*\s)src\s*=\s*/i', '$1data-faz-src=', $blocked_html );
		// Add data-faz-category to iframes.
		$blocked_html = preg_replace( '/(<iframe\b)/', '$1 data-faz-category="' . esc_attr( $matched_category ) . '" style="display:none"', $blocked_html );
		// Disable scripts using set_script_type_plain() for consistent type handling.
		$cat_attr = $matched_category;
		$blocked_html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $cat_attr ) {
				$attrs = $this->set_script_type_plain( $m[1] );
				if ( false === strpos( $attrs, 'data-faz-category' ) ) {
					$attrs .= ' data-faz-category="' . esc_attr( $cat_attr ) . '"';
				}
				return '<script' . $attrs . '>' . $m[2] . '</script>';
			},
			$blocked_html
		);

		// Build placeholder.
		$placeholder = '<div class="faz-iframe-placeholder' . ( $thumbnail_html ? ' faz-iframe-placeholder--video' : '' ) . '" data-faz-category="' . esc_attr( $matched_category ) . '">'
			. $thumbnail_html
			. '<div class="faz-iframe-placeholder-inner">'
			. ( $thumbnail_html
				? '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>'
				: '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>'
			)
			. '<p>' . esc_html( sprintf(
				/* translators: %s: service name */
				__( 'This content is blocked because %s cookies have not been accepted.', 'faz-cookie-manager' ),
				$service_label ? $service_label : $matched_category
			) ) . '</p>'
			. '<button class="faz-iframe-placeholder-btn" onclick="(function(b){var p=b.closest(\'.faz-iframe-placeholder\');var cat=p.getAttribute(\'data-faz-category\');if(window._fazAcceptCategory){window._fazAcceptCategory(cat)}else{var el=document.querySelector(\'[data-faz-action=revisit-consent]\');if(el)el.click()}})(this)">'
			. esc_html__( 'Accept cookies', 'faz-cookie-manager' )
			. '</button>'
			. '</div>'
			. $blocked_html
			. '</div>';

		return $placeholder;
	}

	/**
	 * Process social embed containers (Facebook, Instagram, Twitter/X).
	 *
	 * Social embeds use specific CSS class patterns that rely on external
	 * scripts to render. When those scripts are blocked, the raw HTML
	 * elements remain visible as plain text. This method hides them and
	 * adds a consent placeholder.
	 *
	 * @param string $content            HTML content.
	 * @param array  $blocked_categories Blocked category slugs.
	 * @return string Modified content.
	 */
	private function process_social_embeds( $content, $blocked_categories ) {
		$social_classes = array(
			'fb-page'          => array( 'label' => 'Facebook', 'category' => 'marketing' ),
			'fb-video'         => array( 'label' => 'Facebook', 'category' => 'marketing' ),
			'fb-post'          => array( 'label' => 'Facebook', 'category' => 'marketing' ),
			'fb-comments'      => array( 'label' => 'Facebook', 'category' => 'marketing' ),
			'fb-like'          => array( 'label' => 'Facebook', 'category' => 'marketing' ),
			'instagram-media'  => array( 'label' => 'Instagram', 'category' => 'marketing' ),
			'twitter-tweet'    => array( 'label' => 'X (Twitter)', 'category' => 'marketing' ),
			'twitter-timeline' => array( 'label' => 'X (Twitter)', 'category' => 'marketing' ),
		);

		foreach ( $social_classes as $class => $info ) {
			if ( false === strpos( $content, $class ) ) {
				continue;
			}
			$category = $info['category'];
			if ( ! in_array( $category, $blocked_categories, true ) ) {
				continue;
			}

			// Insert a placeholder BEFORE the social element, and hide the element.
			$content = preg_replace_callback(
				'#(<(?:div|blockquote|span)\b)([^>]*class\s*=\s*["\'][^"\']*\b' . preg_quote( $class, '#' ) . '\b[^"\']*["\'][^>]*)>#i',
				function ( $m ) use ( $category, $info ) {
					// Skip if already processed.
					if ( false !== strpos( $m[2], 'data-faz-category' ) ) {
						return $m[0];
					}
					$placeholder = '<div class="faz-iframe-placeholder faz-social-placeholder" data-faz-category="' . esc_attr( $category ) . '">'
						. '<div class="faz-iframe-placeholder-inner">'
						. '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>'
						. '<p>' . esc_html( sprintf(
							__( 'This content is blocked because %s cookies have not been accepted.', 'faz-cookie-manager' ),
							$info['label']
						) ) . '</p>'
						. '<button class="faz-iframe-placeholder-btn" onclick="(function(b){var p=b.closest(\'.faz-iframe-placeholder\');var cat=p.getAttribute(\'data-faz-category\');if(window._fazAcceptCategory){window._fazAcceptCategory(cat)}else{var el=document.querySelector(\'[data-faz-action=revisit-consent]\');if(el)el.click()}})(this)">'
						. esc_html__( 'Accept cookies', 'faz-cookie-manager' )
						. '</button>'
						. '</div></div>';
					// Placeholder before + hidden original element.
					return $placeholder . $m[1] . $m[2] . ' style="display:none" data-faz-category="' . esc_attr( $category ) . '">';
				},
				$content
			);
		}

		return $content;
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
