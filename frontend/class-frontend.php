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
use FazCookie\Frontend\Modules\Banner_Rest\Banner_Rest;
use FazCookie\Includes\Geolocation;
use FazCookie\Includes\Gvl;
use FazCookie\Includes\Known_Providers;
use FazCookie\Includes\Cookie_Table_Shortcode;
use FazCookie\Includes\Cookie_Policy_Shortcode;
use FazCookie\Frontend\Includes\Placeholder_Builder;
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
	private $whitelist_cache          = null;
	private $service_consent_cache    = null;
	private $pattern_service_cache    = null;
	private $settings_option_cache    = null;
	private $always_allowed_cache     = null;
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
		new Banner_Rest();
		new Cookie_Table_Shortcode();
		new Cookie_Policy_Shortcode();
		new AMP_Consent();
		new Translation_Compat();
		add_action( 'init', array( $this, 'load_banner' ) );
		add_action( 'wp_footer', array( $this, 'banner_html' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 1 );
		add_action( 'wp_head', array( $this, 'insert_styles' ) );
		add_action( 'template_redirect', array( $this, 'render_banner_preview_frame' ), 0 );
		add_action( 'template_redirect', array( $this, 'start_output_buffer' ) );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_loader_tag' ), 10, 3 );
		add_filter( 'style_loader_tag', array( $this, 'filter_style_loader_tag' ), 10, 4 );

		// Auto-exclusion from cache/optimization plugins' defer + delay +
		// combine + minify features. Consent banners MUST run before
		// third-party trackers, so those optimisations on FAZ assets
		// would defeat the whole plugin (a LiteSpeed-delayed banner
		// appears only after the first user interaction, by which point
		// ad/analytics scripts released by the same interaction have
		// already fired).
		//
		// Opt-out via filter: a site admin deliberately running FAZ
		// through their cache plugin's JS delay (e.g. for an A/B test)
		// can disable this block with:
		//     add_filter( 'faz_auto_exclude_cache_plugins', '__return_false' );
		if ( apply_filters( 'faz_auto_exclude_cache_plugins', true ) ) {
			// Run *after* `filter_script_loader_tag` (priority 20 > 10)
			// so we don't tag a tag we may have already re-typed to
			// `text/plain`.
			add_filter( 'script_loader_tag', array( $this, 'tag_own_scripts_nooptimize' ), 20, 2 );

			// LiteSpeed Cache pattern-based exclude lists
			// (belt-and-suspenders in case the tag attribute ever gets
			// stripped by a future LS release).
			add_filter( 'litespeed_optm_js_defer_exc', array( $this, 'litespeed_exclude_own_scripts' ) );
			add_filter( 'litespeed_optm_js_delay_inc', array( $this, 'litespeed_exclude_own_scripts_from_include' ) );
			add_filter( 'litespeed_optimize_js_excludes', array( $this, 'litespeed_exclude_own_scripts' ) );
			// Guest Mode delays EVERY JS regardless of the regular exclude
			// lists above — it has its own separate list. Without this our
			// consent banner stays hidden until the visitor interacts with
			// the page, which breaks first-visit compliance.
			add_filter( 'litespeed_optm_gm_js_exc', array( $this, 'litespeed_exclude_own_scripts' ) );

			// `-js-extra` (wp_localize_script payload) and `-js-translations`
			// inline tags do NOT travel through `script_loader_tag` — they
			// are emitted by core via `wp_get_inline_script_tag()`, which
			// reads its attributes from the `wp_inline_script_attributes`
			// filter. Without this hook those tags reach the page without
			// our 5 cache opt-out attrs, and a delay-aware optimizer (e.g.
			// LiteSpeed Guest Mode) re-types them to `litespeed/javascript`,
			// stranding the localized config until first user interaction.
			add_filter( 'wp_inline_script_attributes', array( $this, 'tag_own_inline_attributes_nooptimize' ), 10, 2 );

			// WP Rocket exclude helpers — same intent.
			add_filter( 'rocket_exclude_defer_js', array( $this, 'rocket_exclude_own_scripts' ) );
			add_filter( 'rocket_delay_js_exclusions', array( $this, 'rocket_exclude_own_scripts' ) );
			add_filter( 'rocket_minify_excluded_external_js', array( $this, 'rocket_exclude_own_scripts' ) );

			// Autoptimize exclude helper.
			add_filter( 'autoptimize_filter_js_exclude', array( $this, 'autoptimize_exclude_own_scripts' ) );
		}

		// WP 5.7+ exposes wp_inline_script_tag for inline scripts added via
		// wp_add_inline_script(). Using this filter catches them BEFORE the
		// output buffer, giving a cleaner block (the browser never sees the
		// original script in the source). The OB remains active as a catch-all
		// for scripts injected outside the WP enqueue system. On WP < 5.7
		// the filter simply does not exist, so add_filter is a safe no-op
		// and the OB handles everything.
		add_filter( 'wp_inline_script_tag', array( $this, 'filter_inline_script_tag' ), 10, 3 );
		add_action( 'send_headers', array( $this, 'shred_non_consented_cookies' ) );
		add_action( 'send_headers', array( $this, 'send_vary_header' ) );

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
		// AMP pages use amp-consent; do not enqueue the JS banner.
		if ( apply_filters( 'faz_is_amp_request', false ) ) {
			return;
		}
		// Skip banner for search engine bots (configurable via Settings).
		$bot_settings = $this->get_faz_settings();
		if ( ! isset( $bot_settings['banner_control']['hide_from_bots'] ) || ! empty( $bot_settings['banner_control']['hide_from_bots'] ) ) {
			if ( faz_is_bot() ) {
				return;
			}
		}
		if ( $this->is_banner_disabled_by_settings() ) {
			return;
		}

		// Geo-targeting: skip banner for visitors outside target regions.
		if ( $this->is_geo_banner_disabled() ) {
			return;
		}

		$suffix = $this->get_script_suffix( 'js/script' );
		if ( false === $this->settings->is_connected() ) {
			if ( ! $this->template ) {
				return;
			}
			$css = $this->get_boosted_css();

			// Custom CSS (banner.meta.customCSS) is no longer rendered on
			// the frontend — removed in 1.13.11 for wp.org compliance
			// ("plugins must not allow arbitrary code insertion"). Existing
			// values remain in the database for downgrade safety but are
			// inert. Use Customizer → Additional CSS (built-in WordPress)
			// and target `.faz-consent-container`, `.faz-modal`, etc.

			// Ad-blocker compatibility: use generic handle/var names to avoid filter lists.
			$faz_settings = $this->get_faz_settings();
			$alt_asset = ! empty( $faz_settings['banner_control']['alternative_asset_path'] );
			$script_handle = $alt_asset ? 'faz-fw' : $this->plugin_name;
			$config_var    = $alt_asset ? '_fazCfg' : '_fazConfig';

			if ( $alt_asset ) {
				// Serve script inline to avoid ad blocker URL pattern matching.
				// The plugin directory name contains "cookie" which triggers filter lists.
				$script_path = plugin_dir_path( __FILE__ ) . 'js/script' . $suffix . '.js';
				wp_register_script( $script_handle, false, array(), $this->version, false );
				wp_enqueue_script( $script_handle );
				if ( file_exists( $script_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file
					$script_content = file_get_contents( $script_path );
					wp_add_inline_script( $script_handle, $script_content );
				}
			} else {
				wp_enqueue_script( $script_handle, plugin_dir_url( __FILE__ ) . 'js/script' . $suffix . '.js', array(), $this->version, false );
			}

			wp_localize_script( $script_handle, $config_var, $this->get_store_data() );
			if ( $alt_asset ) {
				// Bridge the alternative config variable to the expected name.
				wp_add_inline_script( $script_handle, 'window._fazConfig = window._fazCfg;', 'before' );
			}
			// Inject template CSS as a proper inline style (nonce-compatible; no unsafe-inline needed).
			// Utility rules appended AFTER boost_css_specificity() so they are NOT
			// scoped inside #faz-consent — these classes are used on elements outside
			// the banner container (consent-bridge iframe, age-gate overlay, blocked embeds).
			$css .= '.faz-hidden{display:none!important;visibility:hidden!important}'
				. '.faz-consent-bridge{width:0;height:0;border:0}'
				. '.faz-age-gate-overlay{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6)}'
				. '.faz-age-gate-modal{background:#fff;border-radius:8px;padding:24px 32px;max-width:420px;text-align:center}';
			$css_handle = $this->plugin_name . '-css';
			wp_register_style( $css_handle, false, array(), $this->version );
			wp_enqueue_style( $css_handle );
			wp_add_inline_style( $css_handle, $css );

			// wp_localize_script for _fazStyles removed: CSS is now injected via
			// wp_add_inline_style above; the JS variable was no longer consumed.

			// GCM (Google Consent Mode) in local mode.
			if ( true === $this->gcm_settings->is_gcm_enabled() ) {
				$gcm      = $this->get_gcm_data();
				$gcm_json = wp_json_encode( $gcm );
				wp_add_inline_script( $script_handle, 'var _fazGcm = ' . $gcm_json . ';', 'before' );
				$gcm_suffix = $this->get_script_suffix( 'js/gcm' );
				$gcm_handle = $this->plugin_name . '-gcm';
				wp_enqueue_script( $gcm_handle, plugin_dir_url( __FILE__ ) . 'js/gcm' . $gcm_suffix . '.js', array( $script_handle ), $this->version, false );
			}

			// IAB TCF v2.3 CMP stub (when IAB is enabled in settings).
			$iab_enabled = (bool) $this->settings->get( 'iab', 'enabled' );
			if ( $iab_enabled ) {
				// Early command-queue stub so ad scripts can call __tcfapi before CMP loads.
				// Handles 'ping' directly so pre-CMP callers get a valid response.
				$tcf_stub = 'if(typeof window.__tcfapi!=="function"){var a=[];window.__tcfapi=function(cmd,ver,cb){if(cmd==="ping"){cb({gdprApplies:undefined,cmpLoaded:false,cmpStatus:"stub",displayStatus:"hidden",apiVersion:"2.3"},true);return;}a.push(arguments);};window.__tcfapi.a=a;}';
				wp_add_inline_script( $script_handle, $tcf_stub, 'before' );
				$tcf_suffix = $this->get_script_suffix( 'js/tcf-cmp' );
				$tcf_handle = $script_handle . '-tcf-cmp';
				wp_enqueue_script( $tcf_handle, plugin_dir_url( __FILE__ ) . 'js/tcf-cmp' . $tcf_suffix . '.js', array( $script_handle ), $this->version, false );

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

			// Load settings once for pageview tracking and consent logging checks.
			$faz_settings = $this->get_faz_settings();

			// Pageview and banner interaction tracking (opt-in via Settings).
			$pv_tracking = isset( $faz_settings['pageview_tracking'] ) && true === $faz_settings['pageview_tracking'];
			if ( $pv_tracking ) {
				$pv_bucket    = (string) floor( time() / ( 12 * HOUR_IN_SECONDS ) );
				$pv_token     = wp_hash( 'faz_pageview_' . $pv_bucket );

				wp_localize_script(
					$script_handle,
					'_fazPageviewConfig',
					array(
						'restUrl'   => rest_url( 'faz/v1/pageviews' ),
						'pageUrl'   => home_url( add_query_arg( array(), false ) ),
						'pageTitle' => wp_get_document_title(),
						'token'     => $pv_token,
					)
				);
				$pv_js = "(function(){" .
					"if(typeof _fazPageviewConfig==='undefined')return;" .
					"var sid=sessionStorage.getItem('faz_sid');" .
					"if(!sid){sid=Math.random().toString(36).substring(2)+Date.now().toString(36);sessionStorage.setItem('faz_sid',sid);}" .
					"function fazTrack(t){" .
						"fetch(_fazPageviewConfig.restUrl,{method:'POST',headers:{'Content-Type':'application/json'}," .
						"body:JSON.stringify({token:_fazPageviewConfig.token,page_url:_fazPageviewConfig.pageUrl,page_title:_fazPageviewConfig.pageTitle,event_type:t,session_id:sid})}).catch(function(){});" .
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
				wp_add_inline_script( $script_handle, $pv_js, 'before' );
			}

			// Add consent logging if enabled.
			$log_consent_on  = isset( $faz_settings['consent_logs']['status'] ) && true === $faz_settings['consent_logs']['status'];
			if ( $log_consent_on ) {
				// Generate a time-bucketed HMAC token to verify requests originate
				// from pages rendered by this site. The bucket covers 12 hours to
				// tolerate page caching. The token is NOT a secret (it's in the
				// HTML source) but prevents casual spoofing from external origins.
				$bucket    = (string) floor( time() / ( 12 * HOUR_IN_SECONDS ) );
				$hmac_token = wp_hash( 'faz_consent_' . $bucket );

				wp_localize_script(
					$script_handle,
					'_fazConsentLog',
					array(
						'restUrl'        => rest_url( 'faz/v1/consent' ),
						'token'          => $hmac_token,
						'bannerSlug'     => $this->banner ? $this->banner->get_slug() : '',
						'policyRevision' => isset( $faz_settings['general']['consent_revision'] ) ? max( 1, absint( $faz_settings['general']['consent_revision'] ) ) : 1,
					)
				);
					$inline_js = "document.addEventListener('fazcookie_consent_update',function(e){" .
						"var d=e.detail||{};" .
						"if(!d.action||d.action==='init')return;" .
						"if(typeof _fazConsentLog==='undefined')return;" .
						"var safeUrl=(function(){try{var current=new URL(window.location.href);return current.origin+current.pathname}catch(err){var origin=window.location.origin||(window.location.protocol+'//'+window.location.host);return origin+(window.location.pathname||'')}})();" .
						"fetch(_fazConsentLog.restUrl,{" .
							"method:'POST'," .
							"headers:{'Content-Type':'application/json'}," .
							"body:JSON.stringify({" .
								"consent_id:(function(){var m=document.cookie.match(/fazcookie-consent=([^;]+)/);if(!m)return '';var v=m[1];try{v=decodeURIComponent(v)}catch(err){}var p=v.match(/(?:^|,)consentid:([^,;]+)/);return p?p[1]:''})()," .
								"status:d.action==='reject'?'rejected':d.action==='all'?'accepted':'partial'," .
								"categories:(function(){var c={};(d.accepted||[]).forEach(function(k){c[k]='yes'});(d.rejected||[]).forEach(function(k){c[k]='no'});return c})()," .
								"url:safeUrl," .
								"banner_slug:_fazConsentLog.bannerSlug||''," .
								"policy_revision:_fazConsentLog.policyRevision||1," .
								"token:_fazConsentLog.token" .
						"})" .
					"}).catch(function(){});" .
				"});";
				wp_add_inline_script( $script_handle, $inline_js );
			}

			// Enqueue the native a11y module that runs after fazcookie_banner_loaded.
			// In ad-blocker mode (alt_asset) the plugin path contains "cookie" which
			// can match filter lists, so we inline the script to avoid blocking.
			$a11y_handle = $script_handle . '-a11y';
			$a11y_suffix = $this->get_script_suffix( 'js/a11y' );
			if ( $alt_asset ) {
				$a11y_path = plugin_dir_path( __FILE__ ) . 'js/a11y' . $a11y_suffix . '.js';
				wp_register_script( $a11y_handle, false, array( $script_handle ), $this->version, true );
				wp_enqueue_script( $a11y_handle );
				if ( file_exists( $a11y_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file
					wp_add_inline_script( $a11y_handle, file_get_contents( $a11y_path ) );
				}
			} else {
				wp_enqueue_script( $a11y_handle, plugin_dir_url( __FILE__ ) . 'js/a11y' . $a11y_suffix . '.js', array( $script_handle ), $this->version, true );
			}
			// Pass translatable checkbox label templates — {name} is replaced in JS.
			wp_localize_script(
				$a11y_handle,
				'fazA11yConfig',
				array(
					/* translators: {name} is replaced with the cookie category name (appears twice, do not translate {name}) */
					'checkboxEnabled'  => __( '{name} enabled, disable {name}', 'faz-cookie-manager' ),
					/* translators: {name} is replaced with the cookie category name (appears twice, do not translate {name}) */
					'checkboxDisabled' => __( '{name} disabled, enable {name}', 'faz-cookie-manager' ),
				)
			);
		}
		if ( true === $this->is_wpconsentapi_enabled() ) {
			$handle = $this->plugin_name . '-wca';
			// Compute the suffix per-file: wca.js and microsoft-consent.js
			// are not in the build:min pipeline, so reusing the $suffix
			// computed for script.js would produce URLs like wca.min.js
			// that 404 on any install where script.min.js exists.
			$wca_suffix = $this->get_script_suffix( 'js/wca' );
			wp_register_script( $handle, plugin_dir_url( __FILE__ ) . 'js/wca' . $wca_suffix . '.js', array(), $this->version, false );
			if ( true === $this->is_gsk_enabled() ) {
				wp_add_inline_script( $handle, 'var _fazGsk = true;', 'before' );
			}
			wp_enqueue_script( $handle );
		}
		$ms_uet     = (bool) $this->settings->get( 'microsoft', 'uet_consent_mode' );
		$ms_clarity = (bool) $this->settings->get( 'microsoft', 'clarity_consent' );
		if ( $ms_uet || $ms_clarity ) {
			$ms_handle = $this->plugin_name . '-microsoft-consent';
			$ms_suffix = $this->get_script_suffix( 'js/microsoft-consent' );
			wp_enqueue_script( $ms_handle, plugin_dir_url( __FILE__ ) . 'js/microsoft-consent' . $ms_suffix . '.js', array(), $this->version, false );
			if ( $ms_uet ) {
				wp_add_inline_script( $ms_handle, 'window._fazMicrosoftUET = true;', 'before' );
			}
			if ( $ms_clarity ) {
				wp_add_inline_script( $ms_handle, 'window._fazMicrosoftClarity = true;', 'before' );
			}
		}
	}

	/**
	 * Return the script suffix to use for frontend assets.
	 *
	 * Production loads `.min.js` when available. Development keeps the
	 * readable source when SCRIPT_DEBUG is enabled or when no minified file
	 * has been generated yet.
	 *
	 * @param string $asset_base Relative path without extension/min suffix.
	 * @return string
	 */
	private function get_script_suffix( $asset_base ) {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return '';
		}

		$minified_path = plugin_dir_path( __FILE__ ) . $asset_base . '.min.js';
		return file_exists( $minified_path ) ? '.min' : '';
	}

	/**
	 * Return the raw faz_settings option with per-request memoization.
	 *
	 * @return array
	 */
	private function get_faz_settings() {
		if ( null === $this->settings_option_cache ) {
			$this->settings_option_cache = get_option( 'faz_settings', array() );
			if ( ! is_array( $this->settings_option_cache ) ) {
				$this->settings_option_cache = array();
			}
		}

		return $this->settings_option_cache;
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
		// Use the wider UI-suppressed check: if the banner UI is hidden (e.g.
		// for PMP-exempt members), the placeholder CSS is unnecessary.
		if ( $this->is_banner_ui_suppressed() ) {
			return;
		}
		// AMP pages use <amp-consent> — skip regular inline styles.
		if ( apply_filters( 'faz_is_amp_request', false ) ) {
			return;
		}
		$placeholder_css = wp_strip_all_tags( Placeholder_Builder::get_css() );
		echo '<style id="faz-style-inline">[data-faz-tag]{visibility:hidden;}'
			. $placeholder_css // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS stripped of all tags; esc_html() would break selectors.
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
		// NOTE: We deliberately do NOT check is_banner_ui_suppressed() here.
		// load_banner() populates $this->template, which enqueue_scripts()
		// depends on to register script.js / gcm.js / tcf-cmp.js. PMP-exempt
		// members must still receive those bootstrap scripts so GCM can fire
		// the auto-granted consent signals to AdSense / GTM — only the
		// visible banner HTML (insert_styles, banner_html) gets suppressed,
		// and those two hooks check is_banner_ui_suppressed() themselves.
		if ( $this->is_banner_disabled_by_settings() ) {
			return;
		}
		if ( ! faz_is_front_end_request() ) {
			return;
		}
		// AMP uses <amp-consent>: skip the classic JS banner/runtime.
		if ( apply_filters( 'faz_is_amp_request', false ) ) {
			return;
		}
		// Global geo-targeting (Settings → Geolocation): skip banner for
		// visitors outside configured target regions.
		if ( $this->is_geo_banner_disabled() ) {
			return;
		}
		$this->banner = Controller::get_instance()->get_active_banner();
		if ( false === $this->banner ) {
			return;
		}

		// Per-banner geo-targeting: skip banner if visitor's country doesn't match the ruleSet.
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
	 * Check if the banner should be disabled for this visitor based on
	 * the global geo-targeting settings (Settings → Geolocation).
	 *
	 * Shared guard used by both enqueue_scripts() and load_banner().
	 *
	 * @return bool True if the banner should NOT be shown.
	 */
	private function is_geo_banner_disabled() {
		$faz_geo_settings = $this->get_faz_settings();
		if ( empty( $faz_geo_settings['geolocation']['geo_targeting'] ) ) {
			return false;
		}

		$country = '';
		// Try Cloudflare header first (free, no MaxMind needed).
		// Only trust the header when explicitly opted in via filter and value is valid.
		if (
			apply_filters( 'faz_trust_cf_ipcountry_header', false )
			&& isset( $_SERVER['HTTP_CF_IPCOUNTRY'] )
			&& preg_match( '/^[A-Z]{2}$/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) )
			&& 'XX' !== sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) )
		) {
			$country = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		}
		// Fallback to MaxMind / other detection methods.
		if ( empty( $country ) ) {
			$country = Geolocation::get_country();
		}

		if ( ! empty( $country ) ) {
			$target_regions = isset( $faz_geo_settings['geolocation']['target_regions'] )
				? $faz_geo_settings['geolocation']['target_regions']
				: array( 'eu', 'uk' );
			$default_behavior = isset( $faz_geo_settings['geolocation']['default_behavior'] )
				? $faz_geo_settings['geolocation']['default_behavior']
				: 'show_banner';

			$is_target = $this->is_country_in_regions( $country, $target_regions );

			if ( ! $is_target && 'no_banner' === $default_behavior ) {
				return true;
			}
		}
		// If country cannot be resolved (no MaxMind DB, no Cloudflare), show banner to everyone (fail-open).
		return false;
	}

	/**
	 * Check if a country code belongs to any of the given region groups.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code.
	 * @param array  $regions      List of region keys (e.g. 'eu', 'uk') or direct country codes.
	 * @return bool
	 */
	private function is_country_in_regions( $country_code, $regions ) {
		$country_code = strtoupper( $country_code );
		$regions      = is_array( $regions ) ? $regions : (array) $regions;

		$region_map = array(
			'eu' => array(
				'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
				'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
				'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
				// EEA.
				'IS', 'LI', 'NO',
				// UK kept here for consistency with Geolocation::$eu_countries and UK GDPR handling.
				'GB',
			),
			'uk' => array( 'GB' ),
			'us' => array( 'US' ),
			'ca' => array( 'CA' ),
			'br' => array( 'BR' ),
			'au' => array( 'AU' ),
			'jp' => array( 'JP' ),
			'ch' => array( 'CH' ),
		);

		foreach ( $regions as $region ) {
			$region = strtolower( $region );
			if ( isset( $region_map[ $region ] ) ) {
				if ( in_array( $country_code, $region_map[ $region ], true ) ) {
					return true;
				}
			} elseif ( strtoupper( $region ) === $country_code ) {
				// Direct country code match (e.g., 'ZA' for South Africa).
				return true;
			}
		}

		/**
		 * Filters whether a country is considered within a target region.
		 *
		 * @param bool   $is_target    Whether the country matched any region (false at this point).
		 * @param string $country_code ISO 3166-1 alpha-2 country code.
		 * @param array  $regions      The configured target regions.
		 */
		return apply_filters( 'faz_is_target_region', false, $country_code, $regions );
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
		// Banner HTML is the actual visible UI — suppress for PMP-exempt
		// members so they never see the consent dialog.
		if ( $this->is_banner_ui_suppressed() ) {
			return;
		}
		// AMP pages use <amp-consent> — skip regular banner template.
		if ( apply_filters( 'faz_is_amp_request', false ) ) {
			return;
		}
		$html = isset( $this->template['html'] ) ? $this->template['html'] : '';

		// Fix mixed-content: cached template may contain http:// plugin URLs
		// when the site is served over HTTPS (reverse proxy, load balancer, or
		// siteurl stored as http:// in the database).
		if ( is_ssl() && defined( 'FAZ_PLUGIN_URL' ) ) {
			$http_url = str_replace( 'https://', 'http://', FAZ_PLUGIN_URL );
			if ( strpos( $html, $http_url ) !== false ) {
				$https_url = set_url_scheme( FAZ_PLUGIN_URL, 'https' );
				$html      = str_replace( $http_url, $https_url, $html );

				// Auto-repair the cached template so subsequent requests
				// skip this replacement entirely.
				$cache_key = apply_filters( 'faz_banner_template_cache_key', 'faz_banner_template' );
				$stored    = get_option( $cache_key, array() );
				if ( is_array( $stored ) ) {
					$repaired = false;
					foreach ( $stored as $lang => $tpl ) {
						if ( isset( $tpl['html'] ) && strpos( $tpl['html'], $http_url ) !== false ) {
							$stored[ $lang ]['html'] = str_replace( $http_url, $https_url, $tpl['html'] );
							$repaired = true;
						}
					}
					if ( $repaired ) {
						update_option( $cache_key, $stored );
					}
				}
			}
		}

		// `<script type="text/template">` is the W3C-recommended way to
		// embed inert HTML templates in the page (browsers do NOT execute
		// it — `type` is not `text/javascript` or `module`). The banner
		// frontend mounts this template into the DOM at runtime via
		// `_fazInjectBannerHtml()` in script.js. wp_enqueue_script()
		// cannot model this: there is no JS to load, only an HTML payload
		// that must appear inline so the renderer finds it synchronously.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- inert HTML template (type=text/template is non-executable); see comment above.
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
		$settings        = $this->get_faz_settings();
		$banner          = $this->banner;
		$banner_settings = $banner->get_settings();

		$providers = array();
		$store     = array(
			'_ipData'       => array(),
			'_assetsURL'    => FAZ_PLUGIN_URL . 'frontend/images/',
			'_publicURL'    => set_url_scheme( get_site_url() ),
			'_expiry'       => max( 1, isset( $banner_settings['settings']['consentExpiry']['value'] ) ? absint( $banner_settings['settings']['consentExpiry']['value'] ) : 180 ),
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
			'_i18n'         => array(
				'privacy_region_label'                  => __( 'We value your privacy', 'faz-cookie-manager' ),
				'optout_preferences_label'              => __( 'Opt-out Preferences', 'faz-cookie-manager' ),
				'customise_consent_preferences_label'   => __( 'Customise Consent Preferences', 'faz-cookie-manager' ),
				'service_consent_label'                 => __( 'Service consent', 'faz-cookie-manager' ),
				'vendor_consent_label'                  => __( 'Vendor consent', 'faz-cookie-manager' ),
			),
			'_rtl'          => $this->is_rtl(),
			'_language'     => faz_current_language(),
			// Client-side language detection (see includes/class-i18n-helpers.php
			// and GitHub issue #67). When _browserDetect is true, script.js
			// parses navigator.languages, matches against _availableLanguages,
			// and — if different from _language — fetches the banner in the
			// detected language via the REST endpoint and swaps the DOM before
			// the banner is shown. This keeps the server-rendered HTML
			// cacheable by CDNs/full-page caches.
			'_availableLanguages' => faz_selected_languages(),
			'_defaultLanguage'    => faz_default_language(),
			'_languageMap'        => function_exists( 'faz_get_lang_map' ) ? faz_get_lang_map() : array(),
			'_browserDetect'      => function_exists( 'faz_browser_detect_enabled' ) ? faz_browser_detect_enabled() : false,
			'_bannerEndpoint'     => esc_url_raw( rest_url( 'faz/v1/banner/' ) ),
			// Consent revision: when the admin bumps this, returning visitors
			// with a lower revision in their cookie are treated as having no
			// consent, and the banner is shown again. See Settings →
			// "Invalidate all consents".
			'_consentRevision' => isset( $settings['general']['consent_revision'] ) ? max( 1, absint( $settings['general']['consent_revision'] ) ) : 1,
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

		// Some payment SDKs are required outside checkout too (e.g. Stripe express buttons).
		foreach ( array_keys( $this->providers ) as $pattern ) {
			if ( $this->is_always_allowed_gateway_pattern( $pattern ) ) {
				unset( $this->providers[ $pattern ] );
			}
		}

		// On WooCommerce checkout/cart pages, remove the remaining payment gateway patterns
		// from client-side blocking so JS interceptors don't break payments.
		if ( $this->is_wc_checkout_or_cart() ) {
			$gateway_whitelist = $this->get_payment_gateway_whitelist();
			foreach ( array_keys( $this->providers ) as $pattern ) {
				foreach ( $gateway_whitelist as $gw ) {
					if ( false !== stripos( $pattern, $gw ) ) {
						unset( $this->providers[ $pattern ] );
						break;
					}
				}
			}
		}

		// On pages excluded from script blocking, send empty providers
		// so client-side interceptors don't block anything.
		if ( $this->is_blocking_disabled_for_page() ) {
			$this->providers = array();
		}

		foreach ( $this->providers as $key => $value ) {
			$providers[] = array(
				're'         => $key,
				'categories' => $value,
			);
		}
		$store['_providersToBlock'] = $providers;

		// User-defined whitelist patterns for client-side network interceptors.
		$user_whitelist = isset( $settings['script_blocking']['whitelist_patterns'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', (array) $settings['script_blocking']['whitelist_patterns'] ) ) )
			: array();
		$store['_userWhitelist'] = $user_whitelist;

		$store['_whitelistedCookiePatterns'] = $this->compute_whitelisted_cookie_patterns( $user_whitelist, $valid_categories );

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

		// Age gate (GDPR Art. 8).
		$age_gate = array(
			'enabled' => ! empty( $settings['age_gate']['enabled'] ),
			'minAge'  => isset( $settings['age_gate']['min_age'] ) ? absint( $settings['age_gate']['min_age'] ) : 16,
		);
		$store['_ageGate'] = $age_gate;

		// GTM Data Layer toggle.
		$store['_gtmDataLayer'] = ! empty( $settings['banner_control']['gtm_datalayer'] );

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

		// Cross-domain consent forwarding.
		if ( ! empty( $settings['consent_forwarding']['enabled'] ) ) {
			$targets = isset( $settings['consent_forwarding']['target_domains'] )
				? array_filter( array_map( 'esc_url', $settings['consent_forwarding']['target_domains'] ) )
				: array();
			$store['_consentForwarding'] = array(
				'enabled' => true,
				'targets' => array_values( $targets ),
			);
		}

		// Per-service consent: pass service list to frontend.
		$per_service = ! empty( $settings['banner_control']['per_service_consent'] );
		if ( $per_service ) {
			$known    = Known_Providers::get_all();
			$services = array();
			foreach ( $known as $id => $service ) {
				if ( 'necessary' === $service['category'] ) {
					continue;
				}
				if ( ! in_array( $service['category'], $valid_categories, true ) ) {
					continue;
				}
				$services[] = array(
					'id'       => sanitize_key( $id ),
					'label'    => sanitize_text_field( $service['label'] ),
					'category' => sanitize_key( $service['category'] ),
					'patterns' => array_map( 'sanitize_text_field', $service['patterns'] ),
					'cookies'  => ! empty( $service['cookies'] ) ? array_map( 'sanitize_text_field', $service['cookies'] ) : array(),
				);
			}
			$store['_perServiceConsent'] = true;
			$store['_services']         = $services;
		}

		return $store;
	}
	/**
	 * Return cookie domain.
	 *
	 * Thin wrapper around the global faz_get_cookie_domain() helper so the
	 * scope used for client-side localization (`_rootDomain` on _fazStore)
	 * stays byte-for-byte identical to the scope used server-side by
	 * faz_set_browser_cookie() / faz_expire_browser_cookie(). The underlying
	 * helper already reads `faz_settings.banner_control.subdomain_sharing`,
	 * parses home_url() with the public-suffix awareness this method used
	 * to re-implement, and applies the `faz_cookie_domain` filter — so we
	 * just delegate. Kept as a public method to preserve the call sites
	 * that receive a Frontend instance (e.g. `$this->get_cookie_domain()`
	 * inside the cookie-shredding path).
	 *
	 * @return string
	 */
	public function get_cookie_domain() {
		return faz_get_cookie_domain();
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
	 * Check whether the visible banner UI (template HTML + CSS) should be
	 * suppressed for the current request.
	 *
	 * Wider net than `is_banner_disabled_by_settings()`: also covers the
	 * Paid Memberships Pro integration where exempted members must NOT see
	 * the banner, even though all the consent bootstrap (script.js, gcm.js,
	 * tcf-cmp.js) still needs to load so GCM can read the auto-granted
	 * cookie and emit the right `consent` signals to AdSense / GTM.
	 *
	 * Use this for banner-rendering hooks. Use
	 * `is_banner_disabled_by_settings()` for script enqueuing.
	 *
	 * @return boolean
	 */
	protected function is_banner_ui_suppressed() {
		if ( $this->is_banner_disabled_by_settings() ) {
			return true;
		}

		if ( class_exists( '\\FazCookie\\Includes\\Integrations\\Paid_Memberships_Pro' )
			&& \FazCookie\Includes\Integrations\Paid_Memberships_Pro::get_instance()->is_current_user_exempted()
		) {
			return true;
		}

		return false;
	}

	/**
	 * Check if script blocking is disabled for the current page.
	 * The banner still shows, but all blocking layers are bypassed.
	 *
	 * @return boolean
	 */
	protected function is_blocking_disabled_for_page() {
		$excluded = $this->settings->get( 'script_blocking', 'excluded_pages' );
		if ( empty( $excluded ) || ! is_array( $excluded ) ) {
			return false;
		}
		$current_url = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		foreach ( $excluded as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}
			$pattern = trim( $pattern );
			if ( empty( $pattern ) ) {
				continue;
			}
			if ( fnmatch( $pattern, $current_url ) ) {
				return true;
			}
		}
		return false;
	}

	/* ─── Server-side script blocking via output buffering ───── */

	/**
	 * Render the dedicated minimal frontend page used by the admin preview iframe.
	 *
	 * The page intentionally skips theme markup and only prints a bare shell with
	 * frontend assets from wp_head(), so the admin preview inherits real site CSS
	 * without flashing the whole page before JS injects the preview banner.
	 *
	 * @return void
	 */
	public function render_banner_preview_frame() {
		if ( ! function_exists( 'faz_is_banner_preview_request' ) || ! faz_is_banner_preview_request() ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );
		show_admin_bar( false );

		$template = __DIR__ . '/views/banner-preview-frame.php';
		if ( file_exists( $template ) ) {
			require $template;
		}
		exit;
	}

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
		// AMP pages have no custom scripts — skip output buffering.
		if ( apply_filters( 'faz_is_amp_request', false ) ) {
			return;
		}
		if ( $this->is_blocking_disabled_for_page() ) {
			return;
		}

		/*
		 * Output-buffer-with-callback pattern (intentional, no explicit close).
		 *
		 * `ob_start()` is invoked here with a callback (`process_output_buffer`)
		 * and is intentionally NOT closed elsewhere via `ob_end_flush()` /
		 * `ob_get_clean()`: that's exactly how the WordPress core
		 * "template_redirect → buffered final render" pattern works (and how
		 * caching/optimisation plugins like LiteSpeed Cache, WP Rocket,
		 * Autoptimize, and Cloudflare APO all hook into the output).
		 *
		 * PHP automatically flushes any open output buffer at request shutdown
		 * (in reverse open order), invoking each callback exactly once with
		 * the buffered HTML. Calling `ob_end_flush()` ourselves would ALSO
		 * fire the callback, but earlier than necessary — it would force any
		 * downstream filter (e.g. AMP transformer, page-cache writers) to
		 * receive a partially-finalised document and would risk
		 * double-execution of the callback if some other plugin then closes
		 * the buffer again.
		 *
		 * The shutdown safety-net registered below is purely belt-and-braces:
		 * if a hostile environment somehow lost the auto-flush (e.g.
		 * `error_reporting(0)` + a fatal in another shutdown handler), this
		 * forces the callback to run.
		 */
		ob_start( array( $this, 'process_output_buffer' ) );
		register_shutdown_function( array( $this, 'flush_output_buffer_on_shutdown' ) );
	}

	/**
	 * Belt-and-braces buffer flusher invoked at request shutdown.
	 *
	 * PHP normally auto-flushes any output buffer left open at shutdown,
	 * so this is a safety net for environments where the auto-flush is
	 * disabled or pre-empted by another shutdown handler. We only flush
	 * if our buffer is still on top of the stack (verified via
	 * `ob_list_handlers()`) so we never close someone else's buffer.
	 *
	 * @since 1.13.11
	 * @return void
	 */
	public function flush_output_buffer_on_shutdown() {
		if ( 0 === ob_get_level() ) {
			return;
		}
		$handlers = ob_list_handlers();
		$top      = end( $handlers );
		if ( false === $top ) {
			return;
		}
		// Match either the static class-prefixed string or the array form
		// PHP records depending on how the callback was registered.
		if ( false === strpos( (string) $top, 'process_output_buffer' ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_ob_end_flush -- safety net for the buffer we opened ourselves; only fires when the same handler is still on top of the stack.
		@ob_end_flush();
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
		// ob_start callbacks may receive null in edge cases (nested buffers, abort).
		// Cast to string to satisfy PHP 8.1+ strict typing for strpos().
		$html = (string) $html;
		if ( '' === $html ) {
			return $html;
		}

		$blocked_categories = $this->get_blocked_categories();
		$has_service_consent = ! empty( $this->get_service_consent() );
		if ( empty( $blocked_categories ) && ! $has_service_consent ) {
			return $html;
		}

		$providers = $this->get_provider_category_map();
		if ( empty( $providers ) ) {
			return $html;
		}

		$old_backtrack = ini_get( 'pcre.backtrack_limit' );
		$old_recursion = ini_get( 'pcre.recursion_limit' );
		$current_backtrack = (int) $old_backtrack;
		$current_recursion = (int) $old_recursion;
		if ( $current_backtrack > 0 && $current_backtrack < 1000000 ) {
			@ini_set( 'pcre.backtrack_limit', '1000000' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions.Discouraged -- raise PCRE limits temporarily so preg_replace_callback over very large HTML (page builders like Bricks easily produce >1MB DOM blobs) doesn't fail with "backtrack limit exceeded" and silently drop script blocking. Bound increase, current value preserved and never lowered.
		}
		if ( $current_recursion > 0 && $current_recursion < 100000 ) {
			@ini_set( 'pcre.recursion_limit', '100000' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions.Discouraged -- same rationale as the backtrack_limit raise above; needed to keep the script-blocking regex pass safe on very large pages.
		}

		// preg_replace_callback returns null on PCRE error (e.g. backtrack limit
		// exceeded with very large page builders like Bricks). Graceful degradation:
		// serve unfiltered HTML (page works without consent blocking) rather than
		// stripping all markup (broken page) or throwing (crash). Log the error
		// so site owners can diagnose and raise pcre.backtrack_limit if needed.
		$pcre_failed = false;

		// 1. Block <script> tags.
		if ( false !== stripos( $html, '<script' ) ) {
			$result = preg_replace_callback(
				'#<script\b([^>]*)>(.*?)</script>#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_script_tag( $m, $providers, $blocked_categories );
				},
				$html
			);
			if ( null === $result ) {
				$pcre_failed = true;
			} else {
				$html = $result;
			}
		}

		// 2. Block <iframe> tags (YouTube, Facebook, Maps, etc.).
		if ( false !== stripos( $html, '<iframe' ) ) {
			$result = preg_replace_callback(
				'#<iframe\b([^>]*)(?:>(.*?)</iframe>|/>)#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_iframe_tag( $m, $providers, $blocked_categories );
				},
				$html
			);
			if ( null === $result ) {
				$pcre_failed = true;
			} else {
				$html = $result;
			}
		}

		// 3. Block tracking pixel <img> inside <noscript> (Meta Pixel, etc.).
		if ( false !== stripos( $html, '<noscript' ) ) {
			$result = preg_replace_callback(
				'#<noscript\b[^>]*>(.*?)</noscript>#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_noscript_tag( $m, $providers, $blocked_categories );
				},
				$html
			);
			if ( null === $result ) {
				$pcre_failed = true;
			} else {
				$html = $result;
			}
		}

		// 4. Block <link rel="stylesheet"> (Google Fonts, Adobe Fonts, etc.).
		if ( false !== stripos( $html, '<link' ) ) {
			$result = preg_replace_callback(
				'#<link\b([^>]*rel\s*=\s*["\']stylesheet["\'][^>]*)/?>#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_link_tag( $m, $providers, $blocked_categories );
				},
				$html
			);
			if ( null === $result ) {
				$pcre_failed = true;
			} else {
				$html = $result;
			}
		}

		// 5. Block <script data-faz-waitfor="category"> (deferred dependency scripts).
		if ( false !== stripos( $html, 'data-faz-waitfor' ) ) {
			$result = preg_replace_callback(
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
			if ( null === $result ) {
				$pcre_failed = true;
			} else {
				$html = $result;
			}
		}

		if ( $pcre_failed ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'FAZ Cookie Manager: PCRE error in output buffer — script blocking skipped. Consider raising pcre.backtrack_limit (current: ' . ini_get( 'pcre.backtrack_limit' ) . ').' );
		}

		if ( false !== $old_backtrack && '' !== $old_backtrack ) {
			@ini_set( 'pcre.backtrack_limit', (string) $old_backtrack ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions.Discouraged -- restoring the original PCRE backtrack_limit value captured before the script-blocking pass (paired with the temporary raise above).
		}
		if ( false !== $old_recursion && '' !== $old_recursion ) {
			@ini_set( 'pcre.recursion_limit', (string) $old_recursion ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions.Discouraged -- restoring the original PCRE recursion_limit value (paired with the temporary raise above).
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
			// Category is allowed — but per-service consent might still block it.
			$svc_blocked = $this->check_per_service_blocking( $attrs, $content );
			if ( true !== $svc_blocked ) {
				return $full;
			}
			// Per-service says block even though category is allowed.
			if ( ! $matched_category ) {
				$matched_category = 'functional'; // Fallback if no category matched.
			}
		} else {
			// Category is blocked — but per-service consent might allow this specific service.
			$svc_blocked = $this->check_per_service_blocking( $attrs, $content );
			if ( false === $svc_blocked ) {
				return $full; // Per-service says allow.
			}
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
			// Category allowed — but per-service might block.
			$svc_blocked = $this->check_per_service_blocking( $attrs, '' );
			if ( true !== $svc_blocked ) {
				return $full;
			}
			if ( ! $matched_category ) {
				$matched_category = 'functional';
			}
		} else {
			// Category blocked — per-service might allow.
			$svc_blocked = $this->check_per_service_blocking( $attrs, '' );
			if ( false === $svc_blocked ) {
				return $full;
			}
		}

		// Rename src → data-faz-src (avoid matching data-src).
		$new_attrs = preg_replace( '/(^|\s)src\s*=\s*/i', '$1data-faz-src=', $attrs, 1 );
		$new_attrs .= ' data-faz-category="' . esc_attr( $matched_category ) . '"';

		$inner = isset( $m[2] ) ? $m[2] : '';
		$blocked_iframe = isset( $m[2] )
			? '<iframe' . $new_attrs . '>' . $inner . '</iframe>'
			: '<iframe' . $new_attrs . '/>';
		$blocked_iframe = self::faz_add_hidden_class( $blocked_iframe );

		// Detect service from iframe src URL.
		$src          = $this->extract_src_from_attrs( $attrs );
		$service_id   = Placeholder_Builder::detect_service_from_url( $src );
		$service_name = 'default' !== $service_id
			? Placeholder_Builder::get_service_name( $service_id )
			: $this->get_service_label_from_attrs( $attrs );
		if ( ! $service_name ) {
			$service_name = Placeholder_Builder::get_service_name( 'default' );
		}

		$thumb_url = Placeholder_Builder::get_video_thumbnail( $src );

		return Placeholder_Builder::build( $service_id, $service_name, $matched_category, $blocked_iframe, $thumb_url );
	}

	/**
	 * Extract the src attribute value from an attribute string.
	 *
	 * @param string $attrs Attribute string.
	 * @return string URL or empty string.
	 */
	private function extract_src_from_attrs( $attrs ) {
		if ( preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Try to determine the service label from iframe attributes via Known_Providers.
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

		// Only process if the noscript contains a blockable fallback resource.
		if ( false === stripos( $content, '<img' ) && false === stripos( $content, '<iframe' ) ) {
			return $full;
		}

		$matched_category = $this->match_script_to_provider( '', $content, $providers );
		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			$svc_blocked = $this->check_per_service_blocking( '', $content );
			if ( true !== $svc_blocked ) {
				return $full;
			}
			if ( ! $matched_category ) {
				$matched_category = 'functional';
			}
		} else {
			$svc_blocked = $this->check_per_service_blocking( '', $content );
			if ( false === $svc_blocked ) {
				return $full;
			}
		}

		// Block tracking fallback resources by replacing src → data-faz-src inside the noscript.
		$blocked_content = preg_replace( '/(<(?:img|iframe)\b[^>]*)(\s)src\s*=/i', '$1$2data-faz-src=', $content );
		$blocked_content = preg_replace( '/(<(?:img|iframe)\b)/i', '$1 data-faz-category="' . esc_attr( $matched_category ) . '"', $blocked_content );
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
			$svc_blocked = $this->check_per_service_blocking( $attrs, '' );
			if ( true !== $svc_blocked ) {
				return $full;
			}
			if ( ! $matched_category ) {
				$matched_category = 'functional';
			}
		} else {
			$svc_blocked = $this->check_per_service_blocking( $attrs, '' );
			if ( false === $svc_blocked ) {
				return $full;
			}
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
		$whitelist = $this->get_whitelist();
		$attr_values = array_filter(
			array(
				$this->extract_tag_attr( $attrs, 'src' ),
				$this->extract_tag_attr( $attrs, 'href' ),
				$this->extract_tag_attr( $attrs, 'id' ),
				$this->extract_tag_attr( $attrs, 'class' ),
			)
		);

		foreach ( $whitelist as $pattern ) {
			foreach ( $attr_values as $value ) {
				if ( $this->matches_whitelist_pattern( $value, $pattern ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extract an attribute value from a tag attribute string.
	 *
	 * Supports double-quoted, single-quoted, and unquoted HTML5 attribute values.
	 *
	 * @param string $attrs Tag attributes.
	 * @param string $name  Attribute name.
	 * @return string
	 */
	private function extract_tag_attr( $attrs, $name ) {
		if ( preg_match( '/(?<![a-z0-9\-])' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $matches ) ) {
			for ( $i = 1; $i <= 3; $i++ ) {
				if ( isset( $matches[ $i ] ) && '' !== $matches[ $i ] ) {
					return $matches[ $i ];
				}
			}
		}

		return '';
	}

	/**
	 * Match a single attribute value against a whitelist pattern.
	 *
	 * URL/path patterns are matched only against normalized src/href values.
	 * Bare handle-like patterns are matched only against ID/class values.
	 *
	 * @param string $value   Attribute value.
	 * @param string $pattern Whitelist pattern.
	 * @return bool
	 */
	private function matches_whitelist_pattern( $value, $pattern ) {
		$value   = trim( (string) $value );
		$pattern = trim( (string) $pattern );
		if ( '' === $value || '' === $pattern ) {
			return false;
		}

		$is_url_pattern = false !== strpos( $pattern, '/' ) || false !== strpos( $pattern, '.' );
		if ( $is_url_pattern ) {
			$parsed = wp_parse_url( $value );
			if ( false !== $parsed && is_array( $parsed ) ) {
				$host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
				$path = isset( $parsed['path'] ) ? ltrim( strtolower( $parsed['path'] ), '/' ) : '';
				$normalized = $host . ( $path ? '/' . $path : '' );
				$path_only   = ltrim( strtolower( $value ), '/' );
				$needle      = ltrim( strtolower( $pattern ), '/' );
				return false !== strpos( $normalized, $needle ) || false !== strpos( $path_only, $needle );
			}

			return false !== strpos( ltrim( strtolower( $value ), '/' ), ltrim( strtolower( $pattern ), '/' ) );
		}

		return false !== stripos( $value, $pattern );
	}

	/**
	 * Build and cache the whitelist array (once per request).
	 *
	 * @return string[]
	 */
	private function get_whitelist() {
		if ( null !== $this->whitelist_cache ) {
			return $this->whitelist_cache;
		}

		// ── Core infrastructure: WordPress, jQuery, and our own scripts ──
		$whitelist = array(
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
			'wp-polyfill',
			'wp-hooks',
			'wp-i18n',
			'wp-api-fetch',
			'wp-url',
			'regenerator-runtime',
		);

		// ── Page builders — essential for layout rendering ──
		$whitelist = array_merge( $whitelist, array(
			'plugins/elementor/',
			'plugins/elementor-pro/',
			'elementor-frontend',
			'elementor-common',
			'elementor-waypoints',
			'plugins/js_composer/',
			'plugins/wpbakery/',
			'js_composer_front',
			'wpb_composer_front_js',
			'plugins/beaver-builder-lite-version/',
			'plugins/bb-plugin/',
			'fl-builder-',
			'plugins/divi-builder/',
			'et_pb_',
			'et-builder-',
			'plugins/oxygen/',
			'plugins/bricks/',
			'bricks-frontend',
		) );

		// ── Form plugins — necessary for form submission ──
		$whitelist = array_merge( $whitelist, array(
			'plugins/contact-form-7/',
			'wpcf7',
			'plugins/wpforms/',
			'wpforms-',
			'plugins/gravityforms/',
			'gform_',
			'plugins/formidable/',
			'frm_',
			'plugins/ninja-forms/',
			'nf-front-end',
			'plugins/happyforms/',
			'plugins/forminator/',
			'forminator-front',
			'plugins/fluent-forms/',
			'fluentform',
			'plugins/ws-form/',
		) );

		// ── Anti-spam / CAPTCHA — necessary for form protection ──
		$whitelist = array_merge( $whitelist, array(
			'google.com/recaptcha',
			'gstatic.com/recaptcha',
			'grecaptcha',
			'recaptcha/api.js',
			'hcaptcha.com',
			'js.hcaptcha.com',
			'challenges.cloudflare.com/turnstile',
			'akismet',
		) );

		// ── Security plugins ──
		$whitelist = array_merge( $whitelist, array(
			'plugins/wordfence/',
			'plugins/better-wp-security/',
			'plugins/sucuri-scanner/',
			'plugins/all-in-one-wp-security-and-firewall/',
		) );

		// ── WooCommerce essential scripts ──
		$whitelist = array_merge( $whitelist, array(
			'woocommerce/assets/js/',
			'wc-cart-fragments',
			'wc-checkout',
			'wc-add-to-cart',
		) );

		// ── Caching / optimisation plugins ──
		$whitelist = array_merge( $whitelist, array(
			'plugins/wp-rocket/',
			'plugins/litespeed-cache/',
			'plugins/w3-total-cache/',
			'plugins/wp-super-cache/',
			'plugins/autoptimize/',
			'plugins/wp-fastest-cache/',
		) );

		// ── Translation / multilingual ──
		$whitelist = array_merge( $whitelist, array(
			'plugins/sitepress-multilingual-cms/',
			'plugins/polylang/',
			'plugins/translatepress-multilingual/',
		) );

		// ── Other WordPress essentials ──
		$whitelist = array_merge( $whitelist, array(
			'plugins/advanced-custom-fields/',
			'plugins/acf/',
			'plugins/classic-editor/',
			'plugins/shortcodes-ultimate/',
		) );
		$whitelist = array_merge( $whitelist, $this->get_always_allowed_gateway_patterns() );

		// ── WooCommerce core infrastructure ──
		if ( class_exists( 'WooCommerce', false ) ) {
			$whitelist = array_merge( $whitelist, array(
				'plugins/woocommerce/',
				'wc-settings',
				'wc-blocks-',
				'wc-cart',
				'wc-checkout',
				'wc-payment-method-',
				'woocommerce-layout',
				'woocommerce-smallscreen',
				'woocommerce-general',
			) );
			// On checkout/cart pages, also whitelist payment gateway scripts
			// (PayPal SDK, Mollie, Klarna, etc.) — they are necessary for purchases.
			if ( $this->is_wc_checkout_or_cart() ) {
				$whitelist = array_merge( $whitelist, $this->get_payment_gateway_whitelist() );
			}
		}

		$whitelist = apply_filters( 'faz_whitelisted_scripts', $whitelist );

		if ( ! is_array( $whitelist ) ) {
			$whitelist = array();
		}

		// Merge user-defined whitelist patterns from Settings → Script Blocking.
		$faz_settings_wl = $this->get_faz_settings();
		$user_patterns   = isset( $faz_settings_wl['script_blocking']['whitelist_patterns'] )
			? array_filter( array_map( 'sanitize_text_field', (array) $faz_settings_wl['script_blocking']['whitelist_patterns'] ) )
			: array();
		$whitelist = array_merge( $whitelist, $user_patterns );

		// Sanitise: trim, deduplicate, remove empty strings (stripos('x','') === 0 always).
		$this->whitelist_cache = array_values(
			array_unique(
				array_filter(
					array_map( 'trim', array_map( 'strval', $whitelist ) ),
					function ( $p ) {
						return '' !== $p;
					}
				)
			)
		);

		return $this->whitelist_cache;
	}

	/**
	 * Return payment SDK patterns that must never be blocked on the storefront.
	 *
	 * Stripe can appear on product/cart/account flows outside checkout (express
	 * buttons, saved cards, payment-request widgets), so keep it globally allowed.
	 *
	 * @return string[]
	 */
	private function get_always_allowed_gateway_patterns() {
		$patterns = apply_filters(
			'faz_always_allowed_gateway_patterns',
			array(
				'js.stripe.com',
				'm.stripe.network',
				'wc-stripe-',
				'stripe-payment',
				'stripe-upe',
			)
		);
		if ( ! is_array( $patterns ) ) {
			$patterns = array( $patterns );
		}

		return array_values(
			array_filter(
				array_map( 'trim', array_map( 'strval', $patterns ) ),
				function ( $pattern ) {
					return '' !== $pattern;
				}
			)
		);
	}

	/**
	 * Check if a provider pattern is always allowed on the storefront.
	 *
	 * @param string $pattern Provider pattern to check.
	 * @return bool
	 */
	private function is_always_allowed_gateway_pattern( $pattern ) {
		$pattern = trim( (string) $pattern );
		if ( '' === $pattern ) {
			return false;
		}

		if ( null === $this->always_allowed_cache ) {
			$this->always_allowed_cache = $this->get_always_allowed_gateway_patterns();
		}

		foreach ( $this->always_allowed_cache as $allowed_pattern ) {
			if ( false !== stripos( $pattern, $allowed_pattern ) || false !== stripos( $allowed_pattern, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the current page is a WooCommerce checkout or cart page.
	 *
	 * @return bool
	 */
	private function is_wc_checkout_or_cart() {
		if ( ! class_exists( 'WooCommerce', false ) ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		// WooCommerce Blocks checkout/cart (shortcode-less pages).
		if ( function_exists( 'has_block' ) ) {
			if ( has_block( 'woocommerce/checkout' ) || has_block( 'woocommerce/cart' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return additional whitelist patterns for payment gateway scripts.
	 *
	 * These scripts are necessary for completing purchases on checkout/cart pages.
	 * Filterable via `faz_payment_gateway_whitelist` for custom gateways.
	 *
	 * @return string[]
	 */
	private function get_payment_gateway_whitelist() {
		$patterns = apply_filters( 'faz_payment_gateway_whitelist', array(
			// PayPal.
			'paypal.com/sdk/js',
			'paypalobjects.com/api/checkout.js',
			'ppcp-gateway',
			'ppcp-webhooks',
			'PayPalCommerceGateway',
			// Mollie.
			'mollie-payments',
			'plugins/mollie-payments-for-woocommerce/',
			// Square.
			'squareup.com',
			'square-credit-card',
			// Braintree.
			'braintreegateway.com',
			'braintree-web/',
			'wc-braintree',
			// Klarna.
			'x.klarnacdn.net',
			'klarna-payments',
			'klarna-checkout',
			// Amazon Pay.
			'amazonpay',
			'amazon-payments-advanced',
		) );

		if ( ! is_array( $patterns ) ) {
			return array();
		}

		// Sanitise: trim, remove empty strings (stripos('x','') === 0 always).
		return array_values(
			array_filter(
				array_map( 'trim', array_map( 'strval', $patterns ) ),
				function ( $p ) {
					return '' !== $p;
				}
			)
		);
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
		$consent = function_exists( 'faz_get_valid_consent_cookie' ) ? faz_get_valid_consent_cookie() : '';
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
				// Parse consent cookie: "consent:yes,necessary:yes,analytics:no,marketing:no"
				if ( preg_match( '/(^|,)' . preg_quote( $slug, '/' ) . ':(\w+)/', $consent, $cm ) ) {
					if ( 'yes' !== $cm[2] ) {
						$blocked[] = $slug;
					}
				} elseif ( 'marketing' === $slug && preg_match( '/(^|,)advertisement:(\w+)/', $consent, $cm ) ) {
					// Backward compat: old cookies may still use "advertisement" instead of "marketing".
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
	 * Parse per-service consent entries from the consent cookie.
	 *
	 * Service consent keys use the format "svc.service-id:yes|no".
	 *
	 * @return array [ 'google-analytics' => 'yes', 'hotjar' => 'no', ... ]
	 */
	private function get_service_consent() {
		if ( null !== $this->service_consent_cache ) {
			return $this->service_consent_cache;
		}
		$this->service_consent_cache = array();
		$settings    = $this->get_faz_settings();
		$per_service = ! empty( $settings['banner_control']['per_service_consent'] );
		if ( ! $per_service ) {
			return $this->service_consent_cache;
		}

		$consent = function_exists( 'faz_get_valid_consent_cookie' ) ? faz_get_valid_consent_cookie() : '';
		if ( empty( $consent ) ) {
			return $this->service_consent_cache;
		}

		// Extract all svc.* entries from the consent cookie.
		if ( preg_match_all( '/(?:^|,)svc\.([a-z0-9_-]+):(\w+)/', $consent, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$this->service_consent_cache[ $match[1] ] = $match[2];
			}
		}
		return $this->service_consent_cache;
	}

	/**
	 * Build a lookup map from Known_Providers patterns → service IDs.
	 *
	 * @return array [ 'google-analytics.com/analytics.js' => 'google-analytics', ... ]
	 */
	private function get_pattern_service_map() {
		if ( null !== $this->pattern_service_cache ) {
			return $this->pattern_service_cache;
		}
		$this->pattern_service_cache = array();
		$known = Known_Providers::get_all();
		foreach ( $known as $id => $service ) {
			if ( 'necessary' === $service['category'] ) {
				continue;
			}
			foreach ( $service['patterns'] as $pattern ) {
				$this->pattern_service_cache[ $pattern ] = sanitize_key( $id );
			}
		}
		return $this->pattern_service_cache;
	}

	/**
	 * Check if a script should be blocked considering per-service consent.
	 *
	 * Returns:
	 *   true  — service is explicitly blocked (svc.id:no)
	 *   false — service is explicitly allowed (svc.id:yes)
	 *   null  — no per-service consent, fall back to category blocking
	 *
	 * @param string $attrs   Script tag attributes.
	 * @param string $content Script inline content.
	 * @return bool|null
	 */
	private function check_per_service_blocking( $attrs, $content ) {
		$service_consent = $this->get_service_consent();
		if ( empty( $service_consent ) ) {
			return null;
		}

		$pattern_map   = $this->get_pattern_service_map();
		$match_context = $this->get_provider_match_context( $attrs, $content );
		$haystack      = $match_context['haystack'];

		foreach ( $pattern_map as $pattern => $service_id ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			if ( false !== stripos( $haystack, $pattern ) ) {
				if ( isset( $service_consent[ $service_id ] ) ) {
					return 'yes' !== $service_consent[ $service_id ];
				}
				// Service found but no explicit consent — fall back to category.
				return null;
			}
		}

		return null;
	}

	/**
	 * Build a normalized haystack for provider and per-service matching.
	 *
	 * If src/href points to a supported data: URI, decode the payload and
	 * treat it as inline content so provider/service patterns can match.
	 *
	 * @param string $attrs   Tag attributes string.
	 * @param string $content Inline content associated with the tag.
	 * @return array
	 */
	private function get_provider_match_context( $attrs, $content ) {
		$url = $this->extract_tag_attr( $attrs, 'src' );
		if ( '' === $url ) {
			$url = $this->extract_tag_attr( $attrs, 'href' );
		}

		$normalized_content = (string) $content;
		if ( '' !== $url && 0 === stripos( $url, 'data:' ) ) {
			$decoded_payload = $this->decode_data_uri_payload( $url );
			if ( '' !== $decoded_payload ) {
				$url                = '';
				$normalized_content = trim( $decoded_payload . ' ' . $normalized_content );
			} else {
				// Decode failed — clear the raw data URI so the encoded blob
				// is not matched against provider patterns in the haystack.
				$url = '';
			}
		}

		return array(
			'url'      => $url,
			'content'  => $normalized_content,
			'haystack' => trim( $url . ' ' . $normalized_content ),
		);
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
		$settings     = $this->get_faz_settings();
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

		// 5. Remove always-allowed gateway patterns (e.g. Stripe on checkout).
		foreach ( array_keys( $map ) as $p ) {
			if ( $this->is_always_allowed_gateway_pattern( $p ) ) {
				unset( $map[ $p ] );
			}
		}

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
	 * Build the set of cookie patterns owned by services the user has
	 * whitelisted (Settings → Script Blocking → whitelist_patterns).
	 *
	 * Used both by `get_store_data()` to populate
	 * `_whitelistedCookiePatterns` for the frontend network interceptors,
	 * AND by `shred_non_consented_cookies()` so the server-side shredder
	 * on `send_headers` doesn't delete cookies that the frontend whitelist
	 * intentionally allows to persist. Keeping a single helper prevents
	 * the two layers from drifting out of sync.
	 *
	 * Matching is intentionally one-directional with a minimum needle
	 * length: "the service pattern contains the user's whitelist token".
	 * Short or empty tokens (e.g. the admin pastes "js" or "com") would
	 * otherwise whitelist practically every provider. The two-pound-three
	 * minimum matches the behaviour documented for the frontend
	 * `_fazIsUserWhitelisted()` consumer.
	 *
	 * @param string[] $user_whitelist   Sanitised patterns from settings.
	 * @param string[] $valid_categories Category slugs that exist in this install.
	 * @return string[] Unique cookie-name patterns to skip on shred/interceptor.
	 */
	private function compute_whitelisted_cookie_patterns( $user_whitelist, $valid_categories ) {
		if ( empty( $user_whitelist ) ) {
			return array();
		}

		$patterns = array();
		$known    = Known_Providers::get_all();

		foreach ( $known as $service ) {
			if ( 'necessary' === $service['category']
				|| empty( $service['cookies'] )
				|| empty( $service['patterns'] )
			) {
				continue;
			}
			if ( ! in_array( $service['category'], $valid_categories, true ) ) {
				continue;
			}

			$service_whitelisted = false;
			foreach ( $service['patterns'] as $pattern ) {
				foreach ( $user_whitelist as $allowed ) {
					if ( '' === $allowed || strlen( $allowed ) < 3 ) {
						continue;
					}
					if ( false !== stripos( $pattern, $allowed ) ) {
						$service_whitelisted = true;
						break 2;
					}
				}
			}
			if ( ! $service_whitelisted ) {
				continue;
			}

			foreach ( $service['cookies'] as $cookie_pattern ) {
				$patterns[] = sanitize_text_field( $cookie_pattern );
			}
		}

		return array_values( array_unique( $patterns ) );
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
		$match_context = $this->get_provider_match_context( $attrs, $content );
		$haystack      = $match_context['haystack'];

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
	 * Decode the payload portion of a data: URI.
	 *
	 * Supports both plain (`data:text/javascript,code`) and base64
	 * (`data:text/javascript;base64,Y29kZQ==`) variants.
	 *
	 * @param string $uri Full data: URI string.
	 * @return string Decoded payload, or '' on failure.
	 */
	private function decode_data_uri_payload( $uri ) {
		$max_payload_bytes = (int) apply_filters( 'faz_data_uri_decode_max_bytes', 65536 );

		// Strip the "data:" prefix.
		$rest = substr( $uri, 5 );
		if ( false === $rest || '' === $rest ) {
			return '';
		}

		$comma_pos = strpos( $rest, ',' );
		if ( false === $comma_pos ) {
			return '';
		}

		$meta    = substr( $rest, 0, $comma_pos );
		$payload = substr( $rest, $comma_pos + 1 );

		if ( false === $payload || '' === $payload ) {
			return '';
		}
		if ( ! $this->is_inspectable_data_uri_meta( $meta ) ) {
			return '';
		}
		if ( $max_payload_bytes > 0 && strlen( $payload ) > $max_payload_bytes ) {
			return '';
		}

		// Check for base64 encoding.
		if ( false !== stripos( $meta, ';base64' ) ) {
			// Percent-decode first for RFC 2397 conformance (e.g. %3D → =).
			$payload = rawurldecode( $payload );
			if ( $max_payload_bytes > 0 && strlen( $payload ) > $max_payload_bytes ) {
				return '';
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding data: URI payload for provider matching, not obfuscation.
			$decoded = base64_decode( $payload, true );
			if ( false === $decoded ) {
				return '';
			}
			if ( $max_payload_bytes > 0 && strlen( $decoded ) > $max_payload_bytes ) {
				return '';
			}
			return $decoded;
		}

		// Plain-text data: URI — URL-decode the payload.
		$decoded = rawurldecode( $payload );
		if ( $max_payload_bytes > 0 && strlen( $decoded ) > $max_payload_bytes ) {
			return '';
		}
		return $decoded;
	}

	/**
	 * Only inspect text/script-like data: URIs for provider matching.
	 *
	 * @param string $meta data: URI metadata before the first comma.
	 * @return bool
	 */
	private function is_inspectable_data_uri_meta( $meta ) {
		$parts      = explode( ';', (string) $meta );
		$media_type = strtolower( trim( $parts[0] ) );

		if ( '' === $media_type ) {
			return true;
		}
		if ( 0 === strpos( $media_type, 'text/' ) ) {
			return true;
		}
		if ( false !== strpos( $media_type, 'javascript' ) || false !== strpos( $media_type, 'ecmascript' ) ) {
			return true;
		}
		if ( 'image/svg+xml' === $media_type ) {
			return true;
		}

		return strlen( $media_type ) > 4 && '+xml' === substr( $media_type, -4 );
	}

	/**
	 * Replace or insert type="text/plain" in a script tag's attributes.
	 *
	 * @param string $attrs Original attributes string.
	 * @return string Modified attributes string.
	 */
	private function set_script_type_plain( $attrs ) {
		if ( preg_match( '/type\s*=\s*["\']([^"\']*)["\']/i', $attrs, $tm ) ) {
			$original = $tm[1];
			$attrs    = preg_replace( '/type\s*=\s*["\'][^"\']*["\']/i', 'type="text/plain"', $attrs );
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
		// Exact matches — infrastructure cookies that are never consent-relevant.
		$exact = array(
			'wordpress_test_cookie',
			'_lscache_vary',       // LiteSpeed Cache page-cache key.
			'lscache_vary',        // LiteSpeed Cache (alt name).
		);
		if ( in_array( $name, $exact, true ) ) {
			return true;
		}
		// Prefix matches (these have dynamic suffixes like hashes or user IDs).
		// These are *technical* / strictly-necessary cookies — CSRF nonces,
		// session anti-tampering tokens, page-cache keys — that must NEVER
		// be shredded server-side or exposed in the consent banner. If they
		// were, the corresponding plugin would emit AJAX calls with stale
		// or missing nonces and `wp_verify_nonce()` would fail with 403,
		// breaking the feature entirely.
		$prefixes = array(
			'wordpress_logged_in_',
			'wordpress_sec_',
			'wp-settings-',
			'wp-settings-time-',
			'wp-postpass_',
			'_litespeed_',          // LiteSpeed Cache internal.
			// wpDiscuz — comments/AJAX nonce. Without this prefix in the
			// allowlist, rejecting the "uncategorized" category would
			// shred the nonce server-side and the next comment-submit
			// AJAX call would 403, completely breaking comments.
			// Reported on gooloo.de (FAZ 1.13.6 + wpDiscuz 7.6.5 +
			// LiteSpeed + Divi).
			'wpdiscuz_nonce_',
			// WordPress core comment-author cookies. Set when a visitor
			// posts a comment with name/email/url remembered. Strictly
			// necessary for the "remember me" comments UX and not used
			// for cross-site tracking.
			//
			// The single `comment_author_` prefix already covers all three
			// core variants (`comment_author_{HASH}`,
			// `comment_author_email_{HASH}`, `comment_author_url_{HASH}`)
			// because the loop below uses `0 === strpos($name, $prefix)`,
			// which is a leading-substring match.
			'comment_author_',
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
			$cookies = $this->prepare_frontend_cookies( $category->get_cookies(), $category->get_slug() );
			$cookie_groups[] = array(
				'name'           => $category->get_name( faz_current_language() ),
				'slug'           => $category->get_slug(),
				'isNecessary'    => 'necessary' === $category->get_slug() ? true : false,
				'ccpaDoNotSell'  => $category->get_sell_personal_data(),
				'cookies'        => $cookies,
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
	 * Prepare preloaded cookie rows for frontend output and provider indexing.
	 *
	 * @param array  $items    Raw/prepared cookie rows.
	 * @param string $cat_slug Category slug.
	 * @return array
	 */
	private function prepare_frontend_cookies( $items, $cat_slug ) {
		$cookies = array();
		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) ) {
				$item = (object) $item;
			}
			if ( ! is_object( $item ) ) {
				continue;
			}
			$name = isset( $item->name ) ? sanitize_text_field( (string) $item->name ) : '';
			if ( self::is_wp_internal_cookie( $name ) ) {
				continue;
			}
			$provider = isset( $item->url_pattern ) ? sanitize_text_field( (string) $item->url_pattern ) : '';
			$cookies[] = array(
				'cookieID' => $name,
				'domain'   => isset( $item->domain ) ? sanitize_text_field( (string) $item->domain ) : '',
				'provider' => $provider,
			);
			if ( '' !== $provider && 'necessary' !== $cat_slug ) {
				if ( ! isset( $this->providers[ $provider ] ) ) {
					$this->providers[ $provider ] = array();
				}
				if ( ! in_array( $cat_slug, $this->providers[ $provider ], true ) ) {
					$this->providers[ $provider ][] = $cat_slug;
				}
			}
		}

		return $cookies;
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
		$preloaded = $category->get_cookies();
		if ( ! empty( $preloaded ) ) {
			return $this->prepare_frontend_cookies( $preloaded, $category->get_slug() );
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
				'cookieID' => sanitize_text_field( $cookie->get_name() ),
				'domain'   => sanitize_text_field( $cookie->get_domain() ),
				'provider' => sanitize_text_field( $cookie->get_url_pattern() ),
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
		$settings   = $properties['settings'] ?? array();
		$behaviours = $properties['behaviours'] ?? array();
		$config     = $properties['config'] ?? array();

		$data['settings']['type']                     = $settings['type'] ?? 'box';
		$data['settings']['preferenceCenterType']     = ( $settings['type'] ?? '' ) === 'classic' ? 'pushdown' : ( $settings['preferenceCenterType'] ?? 'popup' );
		$data['settings']['position']                 = $settings['position'] ?? 'bottom-right';
		$data['settings']['applicableLaw']            = $settings['applicableLaw'] ?? 'gdpr';
		$data['behaviours']['reloadBannerOnAccept']   = $behaviours['reloadBannerOnAccept']['status'] ?? false;
		$data['behaviours']['loadAnalyticsByDefault'] = $behaviours['loadAnalyticsByDefault']['status'] ?? false;
		$data['behaviours']['animations']             = $behaviours['animations'] ?? array();
		$data['config']['revisitConsent']             = $config['revisitConsent'] ?? array();
		$data['config']['preferenceCenter']['toggle'] = $config['preferenceCenter']['toggle']
			?? $config['preferenceCenter']['elements']['categories']['elements']['toggle']
			?? array();
		$data['config']['categoryPreview']['status']  = $config['categoryPreview']['status'] ?? false;
		$data['config']['categoryPreview']['toggle']  = $config['categoryPreview']['elements']['toggle'] ?? array();
		$data['config']['videoPlaceholder']['status'] = $config['videoPlaceholder']['status'] ?? false;
		$data['config']['videoPlaceholder']['styles'] = array_merge( $config['videoPlaceholder']['styles'] ?? array(), $config['videoPlaceholder']['elements']['title']['styles'] ?? array() );
		$data['config']['readMore']                   = $config['notice']['elements']['buttons']['elements']['readMore'] ?? array();
		$data['config']['showMore']                    = $config['accessibilityOverrides']['elements']['preferenceCenter']['elements']['showMore'] ?? array();
		$data['config']['showLess']                    = $config['accessibilityOverrides']['elements']['preferenceCenter']['elements']['showLess'] ?? array();
		$data['config']['alwaysActive']                = $config['accessibilityOverrides']['elements']['preferenceCenter']['elements']['alwaysActive'] ?? array();
		$data['config']['manualLinks']                 = $config['accessibilityOverrides']['elements']['manualLinks'] ?? array();
		$data['config']['auditTable']['status']       = $config['auditTable']['status'] ?? false;
		$data['config']['optOption']['status']        = $config['optoutPopup']['elements']['optOption']['status'] ?? false;
		$data['config']['optOption']['toggle']        = $config['optoutPopup']['elements']['optOption']['elements']['toggle'] ?? array();
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
			. '}'
			// Prevent page-builder themes (Divi, Elementor, Beaver) from leaking
			// link colors into banner buttons rendered as <a> tags.
			. '#faz-consent a,#faz-consent button{color:inherit;background-color:transparent;}';
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
			'.faz-hidden',
			'.faz-modal',
			'.faz-age-gate',
			'.faz-consent-bridge',
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
			'.faz-service-',
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

					// Skip @keyframes step selectors (0%, 100%, from, to).
					if ( preg_match( '/^(?:\d+%|from|to)$/i', $s ) ) {
						$out[] = $s;
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
	/**
	 * Return the list of script handles owned by this plugin.
	 *
	 * Detect whether a registered script handle belongs to this plugin.
	 *
	 * Uses prefix matching rather than a hardcoded suffix list so the
	 * check stays correct when:
	 *  - the bootstrap passes a different base name (e.g. a rebrand);
	 *  - the admin enables the "Alternative asset path" mode, which
	 *    aliases the main handle to `faz-fw` and derives its children
	 *    as `faz-fw-gcm`, `faz-fw-tcf-cmp`, `faz-fw-a11y`;
	 *  - a future release registers a new sub-handle (e.g.
	 *    `faz-cookie-manager-stripe-sdk`) without this helper needing
	 *    to grow a new entry.
	 *
	 * @param string $handle Registered script handle.
	 * @return bool
	 */
	private function is_own_script_handle( $handle ) {
		if ( ! is_string( $handle ) || '' === $handle ) {
			return false;
		}
		$base = (string) $this->plugin_name;
		if ( '' === $base ) {
			$base = 'faz-cookie-manager';
		}
		// Match `<base>` and `<base>-*`, plus the alt-asset `faz-fw`
		// family and its children.
		if ( $handle === $base || 0 === strpos( $handle, $base . '-' ) ) {
			return true;
		}
		if ( 'faz-fw' === $handle || 0 === strpos( $handle, 'faz-fw-' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Inject opt-out hints into our own `<script>` tags so cache /
	 * optimisation plugins leave them alone. A deferred or delayed
	 * consent banner defeats the plugin's purpose: the banner (and the
	 * `_fazCreateElementBackup` interceptor that blocks third-party
	 * trackers pre-consent) must run at page load, not at first user
	 * interaction — otherwise ads and analytics scripts released by the
	 * same interaction get to execute alongside the banner.
	 *
	 * The attributes below are recognised by the major plugins:
	 *   - `data-no-defer`        — LiteSpeed Cache, Hummingbird, SG Optimizer
	 *   - `data-no-optimize`     — LiteSpeed Cache, WP Rocket, W3 Total Cache
	 *   - `data-no-minify`       — WP Rocket
	 *   - `data-cfasync="false"` — Autoptimize, Cloudflare Rocket Loader
	 *   - `data-ao-skip`         — Autoptimize
	 *
	 * Priority 20 ensures this runs after our own `filter_script_loader_tag`
	 * (priority 10) so we don't tag a script we may have re-typed to
	 * `text/plain`.
	 *
	 * @param string $tag    Full `<script>` tag.
	 * @param string $handle Registered script handle.
	 * @return string
	 */
	public function tag_own_scripts_nooptimize( $tag, $handle ) {
		if ( is_admin() ) {
			return $tag;
		}
		if ( ! $this->is_own_script_handle( $handle ) ) {
			return $tag;
		}
		$hints = ' data-no-defer="1" data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-ao-skip="1"';
		// `script_loader_tag` receives the *composite* markup for this
		// handle — the external `<script src>` PLUS any inline before/after
		// snippets registered via `wp_add_inline_script()` concatenated in
		// a single string. Tag every opening `<script>` in the blob
		// (excluding ones we have already neutralised to `text/plain` or
		// that already carry the hints from a prior filter pass).
		$new_tag = preg_replace_callback(
			'#<script\b([^>]*?)(/?)>#i',
			function ( $m ) use ( $hints ) {
				$attrs = $m[1];
				// Skip blocked scripts and idempotent re-runs.
				if ( false !== strpos( $attrs, 'type="text/plain"' ) || false !== strpos( $attrs, "type='text/plain'" ) ) {
					return $m[0];
				}
				if ( false !== strpos( $attrs, 'data-no-defer' ) ) {
					return $m[0];
				}
				return '<script' . $attrs . $hints . $m[2] . '>';
			},
			$tag
		);
		return is_string( $new_tag ) ? $new_tag : $tag;
	}

	/**
	 * Inject the cache opt-out hints into core's `wp_get_inline_script_tag()`
	 * attribute pipeline.
	 *
	 * `script_loader_tag` only fires for enqueued `<script src>` blobs (and
	 * the before/after inlines concatenated with them). The payloads emitted
	 * by `wp_localize_script()` (`{handle}-js-extra`) and translations
	 * (`{handle}-js-translations`) take a *different* path: core calls
	 * `wp_print_inline_script_tag()` → `wp_get_inline_script_tag()`, which
	 * applies the `wp_inline_script_attributes` filter to its `$attributes`
	 * array and serialises the result. So those tags never see our
	 * `tag_own_scripts_nooptimize()` filter and ship without the 5 opt-out
	 * data-* attrs — leaving them eligible for LiteSpeed Guest Mode delay,
	 * which strands the localized config under `type="litespeed/javascript"`
	 * until first user interaction.
	 *
	 * The filter signature is `( $attributes, $data )`. We:
	 *   - require an `id` attribute (every WP inline tag has one);
	 *   - peel the `-js-extra|-js-translations|-js-before|-js-after` suffix
	 *     to recover the registered handle;
	 *   - skip if it isn't ours (`is_own_script_handle`);
	 *   - skip if another mechanism has already neutralised the tag to
	 *     `text/plain` (pre-consent blocking path);
	 *   - else add the same 5 hints `tag_own_scripts_nooptimize` adds.
	 *
	 * @param array  $attributes Key-value pairs for the inline `<script>` tag.
	 * @param string $data       Inline JavaScript content (unused).
	 * @return array Modified attributes.
	 */
	public function tag_own_inline_attributes_nooptimize( $attributes, $data = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		unset( $data );
		if ( is_admin() || ! is_array( $attributes ) ) {
			return $attributes;
		}
		$id = isset( $attributes['id'] ) ? (string) $attributes['id'] : '';
		if ( '' === $id ) {
			return $attributes;
		}
		$handle = preg_replace( '/-js-(extra|translations|before|after)$/', '', $id );
		if ( $handle === $id || ! is_string( $handle ) ) {
			// No matching suffix — not a WP-managed inline-script id we own.
			return $attributes;
		}
		if ( ! $this->is_own_script_handle( $handle ) ) {
			return $attributes;
		}
		// Skip if pre-consent blocking has already neutralised the tag.
		if ( isset( $attributes['type'] ) && 'text/plain' === $attributes['type'] ) {
			return $attributes;
		}
		// Idempotent: don't double-set if a parallel mechanism already added them.
		if ( ! isset( $attributes['data-no-defer'] ) ) {
			$attributes['data-no-defer']    = '1';
			$attributes['data-no-optimize'] = '1';
			$attributes['data-no-minify']   = '1';
			$attributes['data-cfasync']     = 'false';
			$attributes['data-ao-skip']     = '1';
		}
		return $attributes;
	}

	/**
	 * LiteSpeed Cache filter callback — add our plugin's path fragment
	 * to whatever exclude list LiteSpeed is assembling (defer / delay /
	 * generic JS optimize). Pattern-matched against script `src`, so
	 * `plugins/faz-cookie-manager/` matches every asset the plugin
	 * enqueues regardless of the registered handle. Used as a fallback
	 * in case the `data-no-defer` tag attribute ever stops being honoured
	 * by a future LiteSpeed release.
	 *
	 * @param mixed $excludes Pattern list (array or newline-joined string).
	 * @return mixed
	 */
	public function litespeed_exclude_own_scripts( $excludes ) {
		$pattern = 'plugins/faz-cookie-manager/';
		if ( is_string( $excludes ) ) {
			if ( false !== strpos( $excludes, $pattern ) ) {
				return $excludes;
			}
			return trim( $excludes . "\n" . $pattern );
		}
		if ( ! is_array( $excludes ) ) {
			$excludes = array();
		}
		if ( ! in_array( $pattern, $excludes, true ) ) {
			$excludes[] = $pattern;
		}
		return $excludes;
	}

	/**
	 * LiteSpeed `Delay JS` *include* list: it's the opposite semantic —
	 * when configured, ONLY listed patterns are delayed. If an admin set
	 * it, we must make sure our scripts are NOT in it, otherwise we
	 * would be delayed again. Remove any entry that matches our plugin
	 * path.
	 *
	 * @param mixed $includes Pattern list.
	 * @return mixed
	 */
	public function litespeed_exclude_own_scripts_from_include( $includes ) {
		// Two-phase match so that entries an admin added by hand (just
		// the bare token `faz-cookie-manager`, or a relative path like
		// `faz-cookie-manager/frontend/js/script.min.js` without the
		// `wp-content/plugins/` prefix) are scrubbed too — that's the
		// 1.13.0 / 1.13.1 behaviour gooloo.de relied on, and 1.13.2
		// accidentally regressed by being strictly path-anchored.
		//
		// At the same time we keep the post-CodeRabbit guard against
		// false-positives: a third-party companion entry such as
		// `my-integration-faz-cookie-manager-compat.js` must NOT be
		// dropped — `faz-cookie-manager` appears there only as a
		// substring of a larger token, not as a token of its own.
		//
		// Strategy: keep the entry if neither rule fires.
		//   1. Reject if the entry contains the literal absolute path
		//      `plugins/faz-cookie-manager/`.
		//   2. Reject if `faz-cookie-manager` appears as a complete
		//      token — i.e. preceded by start-of-string / `/` / `=`
		//      AND followed by end-of-string / `/` / `.`.
		$matcher = static function ( $v ) {
			if ( ! is_string( $v ) ) {
				return true;
			}
			if ( false !== strpos( $v, 'plugins/faz-cookie-manager/' ) ) {
				return false;
			}
			if ( preg_match( '#(^|[/=])faz-cookie-manager($|[/.])#', $v ) ) {
				return false;
			}
			return true;
		};
		if ( is_array( $includes ) ) {
			return array_values( array_filter( $includes, $matcher ) );
		}
		if ( is_string( $includes ) ) {
			$lines = preg_split( '/[\r\n]+/', $includes );
			$lines = array_values( array_filter( (array) $lines, $matcher ) );
			return implode( "\n", $lines );
		}
		return $includes;
	}

	/**
	 * WP Rocket exclude callback — same pattern-based approach.
	 *
	 * @param array $excludes Existing exclude patterns.
	 * @return array
	 */
	public function rocket_exclude_own_scripts( $excludes ) {
		if ( ! is_array( $excludes ) ) {
			$excludes = array();
		}
		$pattern = '/wp-content/plugins/faz-cookie-manager/(.*)';
		if ( ! in_array( $pattern, $excludes, true ) ) {
			$excludes[] = $pattern;
		}
		return $excludes;
	}

	/**
	 * Autoptimize exclude callback — accepts a comma-separated string.
	 *
	 * @param string $excludes Comma-joined exclusion list.
	 * @return string
	 */
	public function autoptimize_exclude_own_scripts( $excludes ) {
		$pattern = 'faz-cookie-manager';
		$excludes = is_string( $excludes ) ? $excludes : '';
		if ( false !== strpos( $excludes, $pattern ) ) {
			return $excludes;
		}
		return '' === $excludes ? $pattern : rtrim( $excludes, ', ' ) . ', ' . $pattern;
	}

	public function filter_script_loader_tag( $tag, $handle, $src ) {
		if ( is_admin() ) {
			return $tag;
		}
		if ( ! $this->template ) {
			return $tag;
		}
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() || $this->is_blocking_disabled_for_page() ) {
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
				$should_block = in_array( $category, $blocked, true );

				// Per-service consent override.
				$svc_blocked = $this->check_per_service_blocking( $tag, '' );
				if ( false === $svc_blocked ) {
					$should_block = false; // Service explicitly allowed.
				} elseif ( true === $svc_blocked ) {
					$should_block = true;  // Service explicitly blocked.
				}

				if ( $should_block ) {
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
	 * Filter inline scripts added via wp_add_inline_script() (WP 5.7+).
	 *
	 * The `wp_inline_script_tag` filter was introduced in WordPress 5.7.
	 * On older versions the filter does not exist and the output buffer
	 * catches inline scripts instead. When the filter IS available, it
	 * provides a cleaner interception point: the browser never sees the
	 * original script in the page source (vs. OB which replaces it after
	 * the entire page is buffered).
	 *
	 * The filter signature changed across WP versions:
	 *   WP 5.7-6.2: ( $tag, $id )           — 2 args
	 *   WP 6.3+:    ( $tag, $id, $handle )   — 3 args (handle = enqueue handle)
	 *
	 * We register with 3 args and default $handle to '' for WP < 6.3.
	 *
	 * @param string $tag    Full <script>…</script> tag with inline content.
	 * @param string $id     The script ID attribute value.
	 * @param string $handle The WP enqueue handle (WP 6.3+, '' otherwise).
	 * @return string Modified tag (type="text/plain" + data-faz-category when blocked).
	 */
	public function filter_inline_script_tag( $tag, $id, $handle = '' ) {
		if ( is_admin() ) {
			return $tag;
		}
		if ( ! $this->template ) {
			return $tag;
		}
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() || $this->is_blocking_disabled_for_page() ) {
			return $tag;
		}
		// Extract attributes and inline content separately so the whitelist
		// only matches against attributes (same policy as the OB path in
		// process_script_tag, which passes $attrs to is_whitelisted).
		// Matching against the full $tag would let any inline script that
		// mentions a whitelist token (e.g. "jquery", "wp-includes/") in its
		// body bypass blocking — a false-positive risk for third-party
		// analytics/marketing snippets.
		$attrs   = '';
		$content = '';
		if ( preg_match( '/<script([^>]*)>(.*?)<\/script>/s', $tag, $match ) ) {
			$attrs   = $match[1];
			$content = $match[2];
		}

		// Never block our own inline scripts (match on attributes + handle only).
		if ( $this->is_whitelisted( $attrs . ' ' . $handle . ' ' . $id, '' ) ) {
			return $tag;
		}
		// Skip if already blocked by another mechanism.
		if ( false !== strpos( $attrs, 'data-faz-category' ) ) {
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
			// Match against handle, id, tag attributes, and inline content.
			if (
				( '' !== $handle && false !== stripos( $handle, $pattern ) ) ||
				( '' !== $id && false !== stripos( $id, $pattern ) ) ||
				false !== stripos( $tag, $pattern ) ||
				false !== stripos( $content, $pattern )
			) {
				$blocked = $this->get_blocked_categories();
				$should_block = in_array( $category, $blocked, true );

				// Per-service consent override.
				$svc_blocked = $this->check_per_service_blocking( $tag, $content );
				if ( false === $svc_blocked ) {
					$should_block = false;
				} elseif ( true === $svc_blocked ) {
					$should_block = true;
				}

				if ( $should_block ) {
					$tag = preg_replace_callback(
						'/<script\b([^>]*)>/i',
						function ( $mm ) use ( $category ) {
							$new_attrs = $this->set_script_type_plain( $mm[1] );
							if ( false === strpos( $new_attrs, 'data-faz-category' ) ) {
								$new_attrs .= ' data-faz-category="' . esc_attr( $category ) . '"';
							}
							return '<script' . $new_attrs . '>';
						},
						$tag,
						1
					);
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
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() || $this->is_blocking_disabled_for_page() ) {
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
				$should_block = in_array( $category, $blocked, true );

				// Per-service consent override.
				$svc_blocked = $this->check_per_service_blocking( $tag, '' );
				if ( false === $svc_blocked ) {
					$should_block = false;
				} elseif ( true === $svc_blocked ) {
					$should_block = true;
				}

				if ( $should_block ) {
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
	 * Emit `Vary: Accept-Language` when client-side browser-language detection
	 * is active.
	 *
	 * Caches and CDNs that honour Vary can then key the cached response by
	 * Accept-Language. When a URL-based multilingual plugin
	 * (WPML/Polylang/TranslatePress/Weglot) is active, browser detection is
	 * not used, so the header is not needed. The emission follows
	 * faz_browser_detect_enabled() so the two escape hatches
	 * (faz_disable_browser_language_detection and the plugin/language checks)
	 * stay consistent — disabling detection also stops cache fragmentation.
	 *
	 * @return void
	 */
	public function send_vary_header() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		if ( true === faz_disable_banner() ) {
			return;
		}
		// Only relevant when client-side browser-language detection is active.
		// faz_browser_detect_enabled() already covers: (a) no URL-based
		// multilingual plugin, (b) 2+ languages selected, and (c) the
		// faz_disable_browser_language_detection filter escape hatch.
		if ( ! function_exists( 'faz_browser_detect_enabled' ) || ! faz_browser_detect_enabled() ) {
			return;
		}
		/**
		 * Short-circuit the Vary header emission.
		 *
		 * @param bool $enabled Whether to emit the header. Defaults to true.
		 */
		if ( false === apply_filters( 'faz_send_vary_header', true ) ) {
			return;
		}

		// Preserve any previously set Vary tokens (e.g. Cookie added elsewhere).
		header( 'Vary: Accept-Language', false );
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
		if ( true === faz_disable_banner() || $this->is_blocking_disabled_for_page() ) {
			return;
		}

		$blocked_categories = $this->get_blocked_categories();

		// Per-service consent: also shred cookies for explicitly denied services
		// even when their category is allowed (svc.hotjar:no + analytics:yes).
		$service_consent   = $this->get_service_consent();
		$svc_cookie_map    = array(); // pattern → service_id
		if ( ! empty( $service_consent ) ) {
			$known = Known_Providers::get_all();
			foreach ( $known as $id => $service ) {
				$svc_id = sanitize_key( $id );
				if ( isset( $service_consent[ $svc_id ] ) && 'no' === $service_consent[ $svc_id ] && ! empty( $service['cookies'] ) ) {
					foreach ( $service['cookies'] as $cookie_pattern ) {
						$svc_cookie_map[ $cookie_pattern ] = $svc_id;
					}
				}
			}
		}

		// Nothing to shred: no blocked categories AND no denied services.
		if ( empty( $blocked_categories ) && empty( $svc_cookie_map ) ) {
			return;
		}

		$cookie_map = Known_Providers::get_cookie_map();
		if ( empty( $cookie_map ) && empty( $svc_cookie_map ) ) {
			return;
		}

		$host              = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$current_host      = preg_replace( '/^www\./', '', preg_replace( '/:\d+$/', '', $host ) );
		$shared_domain     = ltrim( (string) $this->get_cookie_domain(), '.' );
		$domain_candidates = array_values( array_unique( array_filter( array( $current_host, $shared_domain ) ) ) );

		// Whitelist short-circuit: cookies belonging to services the admin
		// has whitelisted (Settings → Script Blocking → whitelist_patterns)
		// must survive `send_headers` shredding too, otherwise the frontend
		// whitelist is only honored on the first page load and is silently
		// neutralized on every subsequent request.
		$settings                    = $this->get_faz_settings();
		$user_whitelist              = isset( $settings['script_blocking']['whitelist_patterns'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', (array) $settings['script_blocking']['whitelist_patterns'] ) ) )
			: array();
		$whitelisted_cookie_patterns = $this->compute_whitelisted_cookie_patterns(
			$user_whitelist,
			$this->get_valid_category_slugs()
		);

		foreach ( array_keys( $_COOKIE ) as $name ) {
			$should_shred = false;

			// Category-based shredding.
			foreach ( $cookie_map as $pattern => $category ) {
				if ( ! in_array( $category, $blocked_categories, true ) ) {
					continue;
				}
				if ( $this->cookie_name_matches( $name, $pattern ) ) {
					$should_shred = true;
					break;
				}
			}

			// Per-service shredding (service explicitly denied).
			if ( ! $should_shred && ! empty( $svc_cookie_map ) ) {
				foreach ( $svc_cookie_map as $pattern => $svc_id ) {
					if ( $this->cookie_name_matches( $name, $pattern ) ) {
						$should_shred = true;
						break;
					}
				}
			}

			// Honor the frontend whitelist after category/service decisions:
			// a whitelisted service overrides both.
			if ( $should_shred && ! empty( $whitelisted_cookie_patterns ) ) {
				foreach ( $whitelisted_cookie_patterns as $wl_pattern ) {
					if ( $this->cookie_name_matches( $name, $wl_pattern ) ) {
						$should_shred = false;
						break;
					}
				}
			}

			if ( $should_shred ) {
				setcookie( $name, '', -1, '/' );
				foreach ( $domain_candidates as $domain ) {
					setcookie( $name, '', -1, '/', $domain );
					setcookie( $name, '', -1, '/', '.' . $domain );
				}
				unset( $_COOKIE[ $name ] );
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
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() || $this->is_blocking_disabled_for_page() ) {
			return $content;
		}
		// AMP pages disallow custom scripts — content blocking is unnecessary.
		if ( apply_filters( 'faz_is_amp_request', false ) ) {
			return $content;
		}

		$blocked_categories = $this->get_blocked_categories();
		$has_service_consent = ! empty( $this->get_service_consent() );
		if ( empty( $blocked_categories ) && ! $has_service_consent ) {
			return $content;
		}

		$providers = $this->get_provider_category_map();
		if ( empty( $providers ) ) {
			return $content;
		}

		// Block <script> tags in content.
		// Fail-secure: on PCRE error, strip scripts entirely rather than serving
		// them unblocked, which would violate consent requirements.
		if ( false !== stripos( $content, '<script' ) ) {
			$result = preg_replace_callback(
				'#<script\b([^>]*)>(.*?)</script>#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_script_tag( $m, $providers, $blocked_categories );
				},
				$content
			);
			if ( null !== $result ) {
				$content = $result;
			} else {
				error_log( '[FAZ Cookie Manager] PCRE error ' . preg_last_error() . ' in filter_content_blocking (scripts)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$content = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $content ) ?? $content;
			}
		}

		// Block <iframe> tags in content.
		if ( false !== stripos( $content, '<iframe' ) ) {
			$result = preg_replace_callback(
				'#<iframe\b([^>]*)(?:>(.*?)</iframe>|/>)#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_iframe_tag( $m, $providers, $blocked_categories );
				},
				$content
			);
			if ( null !== $result ) {
				$content = $result;
			} else {
				error_log( '[FAZ Cookie Manager] PCRE error ' . preg_last_error() . ' in filter_content_blocking (iframes)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$content = preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', $content ) ?? $content;
			}
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
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() || $this->is_blocking_disabled_for_page() ) {
			return $html;
		}
		// AMP pages disallow custom scripts — oEmbed blocking is unnecessary.
		if ( apply_filters( 'faz_is_amp_request', false ) ) {
			return $html;
		}

		$blocked_categories = $this->get_blocked_categories();
		$has_service_consent = ! empty( $this->get_service_consent() );
		if ( empty( $blocked_categories ) && ! $has_service_consent ) {
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
				$is_cat_blocked = in_array( $category, $blocked_categories, true );

				// Per-service consent override.
				$svc_blocked = $this->check_per_service_blocking( $html, $url );
				if ( false === $svc_blocked ) {
					$is_cat_blocked = false; // Service explicitly allowed.
				} elseif ( true === $svc_blocked ) {
					$is_cat_blocked = true;  // Service explicitly blocked.
				}

				if ( $is_cat_blocked ) {
					$matched_category = $category;
					break;
				}
			}
		}

		if ( ! $matched_category ) {
			return $html;
		}

		// Detect service from the oEmbed URL.
		$service_id   = Placeholder_Builder::detect_service_from_url( $url );
		$service_name = 'default' !== $service_id
			? Placeholder_Builder::get_service_name( $service_id )
			: $this->get_service_label_from_attrs( $url );
		if ( ! $service_name ) {
			$service_name = Placeholder_Builder::get_service_name( 'default' );
		}

		$thumb_url = Placeholder_Builder::get_video_thumbnail( $url );

		// Neutralize the embed: wrap iframes, disable scripts.
		$blocked_html = $html;
		// Rename iframe src to prevent loading.
		$blocked_html = preg_replace( '/(<iframe\b[^>]*\s)src\s*=\s*/i', '$1data-faz-src=', $blocked_html );
		// Add data-faz-category to iframes.
		$blocked_html = preg_replace( '/(<iframe\b)/', '$1 data-faz-category="' . esc_attr( $matched_category ) . '"', $blocked_html );
		$blocked_html = self::faz_add_hidden_class( $blocked_html );
		// Disable scripts using set_script_type_plain() for consistent type handling.
		$cat_attr = $matched_category;
		$result = preg_replace_callback(
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
		if ( null !== $result ) {
			$blocked_html = $result;
		} else {
			error_log( '[FAZ Cookie Manager] PCRE error ' . preg_last_error() . ' in block_oembed (scripts)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$blocked_html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $blocked_html ) ?? $blocked_html;
		}

		return Placeholder_Builder::build( $service_id, $service_name, $matched_category, $blocked_html, $thumb_url );
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
			'fb-page'          => array( 'service_id' => 'facebook',  'label' => 'Facebook',    'category' => 'marketing' ),
			'fb-video'         => array( 'service_id' => 'facebook',  'label' => 'Facebook',    'category' => 'marketing' ),
			'fb-post'          => array( 'service_id' => 'facebook',  'label' => 'Facebook',    'category' => 'marketing' ),
			'fb-comments'      => array( 'service_id' => 'facebook',  'label' => 'Facebook',    'category' => 'marketing' ),
			'fb-like'          => array( 'service_id' => 'facebook',  'label' => 'Facebook',    'category' => 'marketing' ),
			'instagram-media'  => array( 'service_id' => 'instagram', 'label' => 'Instagram',   'category' => 'marketing' ),
			'twitter-tweet'    => array( 'service_id' => 'twitter',   'label' => 'X (Twitter)', 'category' => 'marketing' ),
			'twitter-timeline' => array( 'service_id' => 'twitter',   'label' => 'X (Twitter)', 'category' => 'marketing' ),
		);

		foreach ( $social_classes as $class => $info ) {
			if ( false === strpos( $content, $class ) ) {
				continue;
			}

			$category     = $info['category'];
			$should_block = in_array( $category, $blocked_categories, true );

			// Per-service consent check: override category-level decision.
			$service_consent = $this->get_service_consent();
			if ( ! empty( $service_consent ) && ! empty( $info['service_id'] ) ) {
				$svc_key = $info['service_id'];
				if ( isset( $service_consent[ $svc_key ] ) ) {
					if ( 'yes' === $service_consent[ $svc_key ] ) {
						$should_block = false;
					} elseif ( 'no' === $service_consent[ $svc_key ] ) {
						$should_block = true;
					}
				}
			}

			if ( ! $should_block ) {
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
					$placeholder = Placeholder_Builder::build_social( $info['service_id'], $info['label'], $category );
					// Placeholder before + hidden original element.
					$blocked = $m[1] . $m[2] . ' data-faz-category="' . esc_attr( $category ) . '">';
				return $placeholder . self::faz_add_hidden_class( $blocked );
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

	/**
	 * Append faz-hidden to an HTML element's class attribute safely.
	 * If a class= attribute already exists, extends it. Otherwise adds one.
	 *
	 * @param string $html Full HTML element string.
	 * @return string
	 */
	private static function faz_add_hidden_class( string $html ): string {
		if ( preg_match( '/\bclass\s*=\s*"/', $html ) ) {
			return preg_replace( '/\bclass\s*=\s*"([^"]*)"/i', 'class="$1 faz-hidden"', $html, 1 );
		}
		if ( preg_match( '/\bclass\s*=\s*\'([^\']*)\'/i', $html ) ) {
			return preg_replace( '/\bclass\s*=\s*\'([^\']*)\'/i', 'class=\'$1 faz-hidden\'', $html, 1 );
		}
		return preg_replace( '/(<\w+)(\s|>)/', '$1 class="faz-hidden"$2', $html, 1 );
	}
}
