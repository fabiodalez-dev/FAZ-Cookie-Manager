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
use FazCookie\Includes\Do_Not_Sell_Shortcode;
use FazCookie\Includes\Cookie_Settings_Shortcode;
use FazCookie\Frontend\Includes\Placeholder_Builder;
use FazCookie\Frontend\Includes\Geo_Runtime;
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
	private $per_service_cache        = null;
	private $enforceable_cache        = null;
	private $settings_option_cache    = null;
	private $always_allowed_cache     = null;
	/**
	 * Set when runtime geo-routing resolves an opt-in jurisdiction but no
	 * matching active banner exists, so the country-selected (opt-out) banner
	 * is kept only to drive server-side blocking — its visible UI is suppressed
	 * to avoid showing opt-out copy while scripts are blocked opt-in style.
	 *
	 * @var bool
	 */
	private $faz_law_fallback_suppress = false;
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
		new Do_Not_Sell_Shortcode();
		new Cookie_Settings_Shortcode();
		new AMP_Consent();
		new Translation_Compat();
		add_action( 'init', array( $this, 'load_banner' ) );
		add_action( 'wp_footer', array( $this, 'banner_html' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 1 );
		add_action( 'wp_head', array( $this, 'insert_styles' ) );
		// Advanced Consent Mode (#165): the denied `consent default` must be set
		// synchronously, before any Google tag. Priority 0 puts it at the very
		// top of <head>, ahead of theme/plugin gtag snippets. No-op unless
		// Advanced mode is on.
		add_action( 'wp_head', array( $this, 'print_gcm_default_inline' ), 0 );
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
			// `Load JavaScript deferred` wraps any matching inline <script> in a
			// DOMContentLoaded callback. Our wp_localize_script payload emits a
			// top-level `var _fazConfig = {...}`; once wrapped the `var` becomes
			// function-local and `window._fazConfig` is never set, fataling
			// `_backupNodes` in script.js. Adding the four bootstrap markers to
			// `rocket_defer_inline_exclusions` short-circuits the wrapping in
			// `DeferJS::defer_inline_js()` before its jQuery-detection regex
			// runs (which would otherwise match the `addtoany-jquery` provider
			// substring inside the localized JSON). Reported by @dominikkucharski
			// in #95.
			add_filter( 'rocket_defer_inline_exclusions', array( $this, 'rocket_exclude_own_inline' ) );

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
		add_action( 'send_headers', array( $this, 'send_geo_cache_headers' ), 0 );
		add_action( 'send_headers', array( $this, 'shred_non_consented_cookies' ) );
		add_action( 'send_headers', array( $this, 'send_vary_header' ) );

		// Content-level blocking (defense-in-depth — runs before output buffer).
		add_filter( 'the_content', array( $this, 'filter_content_blocking' ), 1000 );
		add_filter( 'widget_text', array( $this, 'filter_content_blocking' ), 1000 );
		add_filter( 'widget_block_content', array( $this, 'filter_content_blocking' ), 1000 );
		add_filter( 'embed_oembed_html', array( $this, 'filter_oembed_blocking' ), 1000, 2 );

		// The cookie-scripts map and the per-service detected-cookie-names
		// transients are invalidated on cookie / category / settings writes by
		// CLI::define_public_hooks(), which registers the bust UNCONDITIONALLY —
		// this Frontend constructor is skipped on pure-admin (non-AJAX, non-REST)
		// requests, so a cache bust registered here would miss writes that
		// originate from an admin page load. See includes/class-cli.php.
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
		//
		// MUST be suppressed under Cache Compatibility Mode: the page is then
		// full-page cached and the render has to be visitor-invariant. A bot
		// request (incl. the cache-warming crawler) would otherwise generate a
		// banner-less copy that LiteSpeed/WP Rocket caches and serves to every
		// human visitor — leaving the banner permanently hidden and trackers
		// unblocked (the server-rendered container stays, but script.min.js /
		// _fazConfig that reveal it and block scripts are never enqueued).
		// Reported on gooloo.de after enabling Cache Compatibility Mode. (#158)
		if ( ! $this->is_cache_compatibility_enabled() ) {
			$bot_settings = $this->get_faz_settings();
			if ( ! isset( $bot_settings['banner_control']['hide_from_bots'] ) || ! empty( $bot_settings['banner_control']['hide_from_bots'] ) ) {
				if ( faz_is_bot() ) {
					return;
				}
			}
		}
		if ( $this->is_banner_disabled_by_settings() ) {
			return;
		}

		// Geo-targeting: skip banner for visitors outside target regions.
		// Same invariance requirement as the bot-skip above — under Cache
		// Compatibility Mode geo gating is resolved client-side from the
		// invariant config (is_country_dependent_output() is false), so the
		// enqueue must never short-circuit per visitor here. Mirrors the
		// cache-compat gate already applied to geo at load_banner().
		if ( ! $this->is_cache_compatibility_enabled() && $this->is_geo_banner_disabled() ) {
			return;
		}

		$suffix = $this->get_script_suffix( 'js/script' );
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

			// Ad-blocker compatibility: serve script inline to avoid URL pattern matching.
			// The plugin directory name contains "cookie" which triggers filter lists.
			// The config variable always stays _fazConfig — ad blockers match URLs, not variable names.
			$faz_settings  = $this->get_faz_settings();
			$alt_asset     = ! empty( $faz_settings['banner_control']['alternative_asset_path'] );
			$script_handle = $alt_asset ? 'faz-fw' : $this->plugin_name;

			if ( $alt_asset ) {
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

			wp_localize_script( $script_handle, '_fazConfig', $this->get_store_data() );

			// Pre-initialise window.dataLayer so third-party trackers that emit
			// `dataLayer.push(…)` bare (without the GTM bootstrap that
			// historically initialises `var dataLayer = dataLayer || []`) do
			// not throw `ReferenceError: dataLayer is not defined` when the
			// plugin blocks the GTM bootstrap as a tracker.
			//
			// Reported by a publisher on citationstyler.com (LiteSpeed Cache +
			// gtm4wp): the GTM4WP bootstrap script carries `data-faz-category=
			// "analytics"` and is held in `type="text/plain"`, so its
			// `dataLayer = dataLayer || []` never runs; a downstream gtm4wp
			// push that lacks the blocking attributes still tries
			// `dataLayer.push(...)` and crashes.
			//
			// WP_Scripts emits this inline in the "before" bucket, which
			// renders AFTER the wp_localize_script "data" bucket (where the
			// `_fazConfig` blob lives) but BEFORE the main `<script src="…">`
			// tag. The dataLayer initialiser therefore runs after _fazConfig
			// is on the page, but ahead of any later inline tags and the
			// page-level body content where third-party trackers fire — which
			// is all we need: only the trackers that emit bare `dataLayer.push`
			// require this guard, and they always render after this point in
			// the document order.
			wp_add_inline_script(
				$script_handle,
				'window.dataLayer = window.dataLayer || [];',
				'before'
			);
			// Inject template CSS as a proper inline style (nonce-compatible; no unsafe-inline needed).
			// Utility rules appended AFTER boost_css_specificity() so they are NOT
			// scoped inside #faz-consent — these classes are used on elements outside
			// the banner container (consent-bridge iframe, age-gate overlay, blocked embeds).
			// `_fazAddPlaceholder` inserts `.video-placeholder-{normal,youtube}` next
			// to blocked iframes (outside #faz-consent), so the scoped template.json
			// rules never reach them. Without this floor, placeholders for lazy-loaded
			// iframes (Bricks/Elementor/Divi Video) collapse to 0×0. The 4-ancestor
			// probe in _fazAddPlaceholder (issue #87) expected this CSS floor.
			$css .= '.faz-hidden{display:none!important;visibility:hidden!important}'
				. '.faz-consent-bridge{width:0;height:0;border:0}'
				. '.faz-age-gate-overlay{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6)}'
				. '.faz-age-gate-modal{background:#fff;border-radius:8px;padding:24px 32px;max-width:420px;text-align:center}'
				. '.video-placeholder-normal,.video-placeholder-youtube{min-height:200px;display:flex;align-items:center;justify-content:center;width:100%;max-width:100%;box-sizing:border-box}';
			$css_handle = $this->plugin_name . '-css';
			wp_register_style( $css_handle, false, array(), $this->version );
			wp_enqueue_style( $css_handle );
			wp_add_inline_style( $css_handle, $css );

			// wp_localize_script for _fazStyles removed: CSS is now injected via
			// wp_add_inline_style above; the JS variable was no longer consumed.

			// GCM (Google Consent Mode) in local mode.
			if ( true === $this->gcm_settings->is_gcm_enabled() ) {
				$gcm = $this->get_gcm_data();
				// Advanced Consent Mode (#165): tell gcm.js to skip its own
				// `consent default` emission — print_gcm_default_inline() has
				// already set the denied baseline synchronously in <head> — and
				// keep only the `consent update` wiring.
				$gcm['advanced_mode'] = $this->gcm_settings->is_advanced_mode();
				// Single source of truth for the dataLayer array name so gcm.js
				// and print_gcm_default_inline() push to the same array (#165 F4).
				$gcm['data_layer_name'] = $this->gcm_datalayer_name();
				$gcm_json               = wp_json_encode( $gcm );
				wp_add_inline_script( $script_handle, 'var _fazGcm = ' . $gcm_json . ';', 'before' );
				$gcm_suffix = $this->get_script_suffix( 'js/gcm' );
				$gcm_handle = $script_handle . '-gcm';
				wp_enqueue_script( $gcm_handle, plugin_dir_url( __FILE__ ) . 'js/gcm' . $gcm_suffix . '.js', array( $script_handle ), $this->version, false );
			}

			// IAB TCF v2.3 CMP stub (when IAB is enabled AND a valid CMP ID is set).
			// IAB Europe assigns CMP IDs; 0 and 1 are reserved/invalid. A TC string
			// built with CmpId < 2 is rejected by the IAB-compliant ad-tech supply
			// chain, so emitting one (the euconsent-v2 cookie) is a supply-chain
			// non-compliance. Refuse to activate the CMP — do not enqueue tcf-cmp.js
			// and do not emit _fazTcfConfig — until a registered CMP ID is configured.
			$iab_enabled = (bool) $this->settings->get( 'iab', 'enabled' )
				&& absint( $this->settings->get( 'iab', 'cmp_id' ) ) >= 2;
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

				// gdprApplies: true when visitor is in EU/EEA or country unknown
				// (safe default). Under Cache Compatibility Mode this payload
				// must be identical for every anonymous visitor, so do not read
				// the visitor country; use the conservative TCF default instead.
				if ( $this->is_cache_compatibility_enabled() ) {
					$gdpr_applies = true;
				} else {
					$visitor_country = $this->get_visitor_country();
					$gdpr_applies    = empty( $visitor_country ) || in_array( $visitor_country, Geolocation::$eu_countries, true );
				}

				// Build TCF config with GVL data if available.
				$tcf_config = array(
					'publisherCC'         => $country_code,
					'consentLanguage'     => $consent_lang,
					'gdprApplies'         => $gdpr_applies,
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
				// Pre-consent banner/pageview metrics are AGGREGATE-ONLY: no
				// per-visitor identifier is created or transmitted. We removed
				// the former sessionStorage "faz_sid" + session_id field — it
				// was a linkable identifier written before any consent (an
				// ePrivacy/Art.5(3) risk) and, crucially, was never read back:
				// every dashboard query is a COUNT(*) grouped by day/event, so
				// dropping it is lossless. The events that remain (pageview,
				// banner_view/accept/reject/settings) are unlinkable counters
				// measuring the consent mechanism itself.
				$pv_js = "(function(){" .
					"if(typeof _fazPageviewConfig==='undefined')return;" .
					"function fazTrack(t){" .
						"fetch(_fazPageviewConfig.restUrl,{method:'POST',headers:{'Content-Type':'application/json'}," .
						"body:JSON.stringify({token:_fazPageviewConfig.token,page_url:_fazPageviewConfig.pageUrl,page_title:_fazPageviewConfig.pageTitle,event_type:t})}).catch(function(){});" .
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
								"categories:(function(){var c={};(d.accepted||[]).forEach(function(k){c[k]='yes'});(d.rejected||[]).forEach(function(k){c[k]='no'});" .
									// P1-3: fold the granular per-service (svc.*) and per-cookie
									// (ck.*) decisions from the consent cookie into the logged
									// record, namespaced by their prefix, so the consent log is a
									// complete audit trail (GDPR accountability) — not just the
									// category-level summary. When per-service consent is off there
									// are no svc.*/ck.* entries and this adds nothing.
									"try{var cm=document.cookie.match(/fazcookie-consent=([^;]+)/);if(cm){var cv=cm[1];try{cv=decodeURIComponent(cv)}catch(er){}cv.split(',').forEach(function(pr){var ci=pr.indexOf(':');if(ci<1)return;var ck=pr.substring(0,ci);if(ck.indexOf('svc.')===0||ck.indexOf('ck.')===0){c[ck]=pr.substring(ci+1)}})}}catch(er){}" .
									"return c})()," .
								"url:safeUrl," .
								"banner_slug:_fazConsentLog.bannerSlug||''," .
								"policy_revision:_fazConsentLog.policyRevision||1," .
								// TCF TC string (euconsent-v2 cookie) and GPP string
								// (__gpp API), forwarded so the tc_string / gpp_string
								// audit columns are populated for TCF/GPP-enabled sites.
								"tc_string:(function(){var m=document.cookie.match(/euconsent-v2=([^;]+)/);return m?m[1]:''})()," .
								"gpp_string:(function(){try{if(typeof window.__gpp!=='function')return '';var s='';window.__gpp('getGPPData',function(d){if(d&&d.gppString)s=d.gppString});return s}catch(e){return ''}})()," .
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
	 * Whether Cache Compatibility Mode is active for the current request.
	 *
	 * @return bool
	 */
	private function is_cache_compatibility_enabled() {
		$settings = $this->get_faz_settings();
		return ! empty( $settings['banner_control']['cache_compatibility'] );
	}

	/**
	 * Add inline styles to the head
	 *
	 * @return void
	 */
	public function insert_styles() {
		if ( true === faz_disable_banner() || is_admin() ) {
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
		// Anti-FOUC: hide the banner until the JS has positioned/styled it.
		// The rule is scoped to `html:not(.faz-ready)` (instead of being a flat
		// `[data-faz-tag]{visibility:hidden}` that the JS reveals by *removing*
		// this whole <style> element) so the reveal survives CSS-optimizer
		// plugins. LiteSpeed/WP Rocket/Autoptimize hoist inline <style> blocks
		// into a combined stylesheet; once that happens the element no longer
		// exists for `_fazRemoveStyles()` to remove and the flat rule would keep
		// the banner permanently hidden (its fixed container still eating
		// clicks). With the state-class gate, the JS adding `faz-ready` to
		// <html> reveals the banner regardless of where the rule ended up.
		// data-no-optimize / data-noptimize additionally ask the CSS optimisers
		// (LiteSpeed Cache, WP Rocket, Autoptimize, …) to leave the block inline
		// (belt-and-suspenders).
		echo '<style id="faz-style-inline" data-no-optimize="1" data-noptimize="1">html:not(.faz-ready) [data-faz-tag]{visibility:hidden;}</style>';
		// The blocked-embed placeholder CSS must OUTLIVE the banner reveal: it
		// styles content that stays on the page until the visitor consents.
		// Keep it in its OWN persistent <style> — `_fazRemoveStyles()` removes
		// `#faz-style-inline` once the banner is positioned, and bundling the
		// placeholder CSS there stripped its styling the moment the JS ran,
		// leaving a bare unstyled box (the "nice design flashes, ugly box
		// stays" report). This block has no id the JS removes.
		echo '<style data-faz-placeholder-style="1" data-no-optimize="1" data-noptimize="1">'
			. $placeholder_css // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS stripped of all tags; esc_html() would break selectors.
			. '</style>';
	}
	/**
	 * Load active banner.
	 *
	 * @return void
	 */
	public function load_banner() {
		if ( true === faz_disable_banner() ) {
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
		$this->maybe_disable_country_page_cache();
		// AMP uses <amp-consent>: skip the classic JS banner/runtime.
		if ( apply_filters( 'faz_is_amp_request', false ) ) {
			return;
		}
		// Global geo-targeting (Settings → Geolocation): skip banner for
		// visitors outside configured target regions. Cache Compatibility Mode
		// must keep the server-rendered HTML invariant across visitors, so it
		// leaves this decision to the client-side runtime instead.
		if ( ! $this->is_cache_compatibility_enabled() && $this->is_geo_banner_disabled() ) {
			return;
		}
		// Multi-banner geo-routing (1.13.18+): pass the visitor's detected
		// country to the controller so it can pick the right banner profile
		// (e.g. CCPA-light for US visitors, GDPR-full for EEA visitors). If
		// the country cannot be resolved or the admin has only configured
		// one banner with empty target_countries, the picker falls back to
		// the match-all row or the banner_default=1 row — preserving the
		// pre-feature behaviour for installs that have not adopted multi-
		// banner setups.
		$visitor_country = $this->is_cache_compatibility_enabled() ? '' : $this->get_visitor_country();
		$this->banner    = Controller::get_instance()->get_active_banner_for_country( $visitor_country );

		// Runtime geo-routing (flag-gated): the resolved ruleset's MODEL decides
		// which consent regime actually applies to this visitor. Prefer the
		// active banner whose applicableLaw matches that model so the UI the
		// visitor sees (opt-in accept/reject vs opt-out notice) matches the
		// jurisdiction enforced — otherwise a country-selected opt-out banner
		// would show an opt-out checkbox while scripts are blocked opt-in style.
		// Falls back to the country-selected banner when no law-matching active
		// banner exists. get_runtime_ruleset() resolves with $visitor_country,
		// so banner selection and ruleset never disagree on the country.
		$runtime_ruleset = $this->get_runtime_ruleset();
		if ( null !== $runtime_ruleset ) {
			$wanted_law  = Geo_Runtime::model_to_law( $runtime_ruleset );
			$current_law = ( false !== $this->banner ) ? $this->banner->get_law() : '';
			if ( $wanted_law !== $current_law ) {
				$law_banner = Controller::get_instance()->get_active_banner_for_law( $wanted_law, $visitor_country );
				if ( false !== $law_banner ) {
					$this->banner = $law_banner;
				} elseif ( 'gdpr' === $wanted_law && false !== $this->banner ) {
					// Fail-closed: the resolved ruleset enforces an opt-in
					// (gdpr-style) regime but the publisher has no active GDPR
					// banner, so the only candidate is the country-selected
					// opt-out (CCPA) banner. Keep it so server-side blocking
					// still runs (start_output_buffer needs $this->template),
					// but suppress its visible UI — showing an opt-out notice
					// ("we use cookies, opt out here") while scripts are blocked
					// opt-in style would misrepresent the true state. Scripts
					// stay blocked; the visitor simply sees no (misleading)
					// banner. A correctly-configured install (a GDPR banner
					// exists) never reaches this branch.
					$this->faz_law_fallback_suppress = true;
				}
			}
		}

		if ( false === $this->banner ) {
			return;
		}

		// Per-banner geo-targeting: skip banner if visitor's country doesn't match the ruleSet.
		if ( ! $this->is_cache_compatibility_enabled() && $this->is_geo_blocked() ) {
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
		$inner    = isset( $settings['settings'] ) && is_array( $settings['settings'] ) ? $settings['settings'] : array();
		$rules    = isset( $inner['ruleSet'] ) && is_array( $inner['ruleSet'] ) ? $inner['ruleSet'] : array();
		if ( empty( $rules ) ) {
			return false;
		}

		// Iterate every rule, NOT just $rules[0] — mirrors the iteration in
		// Controller::has_country_dependent_banners(). The banner is shown
		// (is_geo_blocked() returns false) when ANY rule matches the
		// visitor's country; only when no rule matches do we block.
		// Without this, a ruleSet like [{code:ALL}, {code:US}] would emit
		// no-cache headers but the runtime check on $rules[0] only would
		// still let the first rule decide, asymmetrically.
		$country = $this->get_visitor_country();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			if ( $this->rule_matches_visitor( $rule, $country ) ) {
				return false;
			}
		}

		// No rule matched. Safe default: if we couldn't detect the country
		// at all, show the banner — losing the geo signal must not silently
		// hide a consent surface.
		if ( empty( $country ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether $rule (a single ruleSet entry) matches a visitor in $country.
	 *
	 * ALL matches any visitor (including '' when country detection failed).
	 * EU / US / OTHER match against the corresponding region set and require
	 * a resolved country code; unknown country yields no match for those.
	 *
	 * @since 1.14.0
	 * @param array  $rule    A single entry from settings.ruleSet.
	 * @param string $country ISO-3166 alpha-2 country code, '' if unknown.
	 * @return bool
	 */
	private function rule_matches_visitor( $rule, $country ) {
		$code = isset( $rule['code'] ) ? strtoupper( (string) $rule['code'] ) : 'ALL';

		if ( 'ALL' === $code ) {
			return true;
		}

		// EU / US / OTHER all need a resolved visitor country to match.
		if ( '' === $country ) {
			return false;
		}

		switch ( $code ) {
			case 'EU':
				return in_array( $country, Geolocation::$eu_countries, true );
			case 'US':
				return 'US' === $country;
			case 'OTHER':
				$regions = isset( $rule['regions'] ) ? array_map( 'strtoupper', (array) $rule['regions'] ) : array();
				return in_array( $country, $regions, true );
			default:
				return false;
		}
	}

	/**
	 * Resolve the visitor's ISO-3166 alpha-2 country code using the same
	 * detection chain that powers is_geo_banner_disabled():
	 *
	 *   1. Cloudflare CF-IPCountry header (only when opted-in via filter).
	 *   2. MaxMind / ip-api.com fallback through Geolocation::get_country().
	 *
	 * Returns '' when the country cannot be resolved (no MaxMind DB, no
	 * Cloudflare, network errors). Callers must treat '' as "no signal" —
	 * the banner picker falls back to the match-all / banner_default row.
	 *
	 * Filterable via `faz_visitor_country` so test fixtures and edge
	 * deployments (e.g. always-EU staging) can stub a country deterministically.
	 *
	 * @since 1.14.0
	 * @return string Upper-case 2-letter code or '' if unknown.
	 */
	private function get_visitor_country() {
		return Geolocation::get_visitor_country();
	}

	/**
	 * Whether the current frontend response can vary by visitor country.
	 *
	 * @return bool
	 */
	private function is_country_dependent_output() {
		$settings = $this->get_faz_settings();

		// Cache Compatibility Mode (issue #158): the publisher has opted to keep
		// the page fully cacheable by LiteSpeed/QUIC.cloud/Varnish/Nginx and to
		// resolve any jurisdiction-specific logic on the client. Short-circuit to
		// "not country-dependent" so send_geo_cache_headers() emits no no-cache /
		// no-store headers and maybe_disable_country_page_cache() never defines
		// DONOTCACHEPAGE. Still routed through the filter so a developer can force
		// the cache-bust back on per request if a specific page truly varies.
		if ( $this->is_cache_compatibility_enabled() ) {
			return (bool) apply_filters( 'faz_country_dependent_banner_output', false, $settings );
		}

		$dependent = false;

		// Country→language fallback (CodeRabbit review, 1.14.2): when the
		// install has NO URL-based multilingual plugin AND the publisher
		// opted in via the faz_use_country_language_fallback filter,
		// faz_current_language() picks the banner language from the
		// visitor's detected country (an IT visitor sees "it" content on
		// /en/). A cached HTML response for an EN visitor would then
		// silently serve the wrong language to an IT visitor. Mark the
		// output as country-dependent in that combination so the page
		// cache stays per-country.
		if (
			function_exists( 'faz_i18n_is_multilingual' )
			&& ! faz_i18n_is_multilingual()
			&& apply_filters( 'faz_use_country_language_fallback', false )
		) {
			$dependent = true;
		}

		// IAB TCF: _fazTcfConfig.gdprApplies is derived from the visitor
		// country at render time (Geolocation::is_eu_visitor()). A cached
		// page served to a non-EU visitor that originally rendered for an
		// EU visitor would carry gdprApplies=true (or vice versa), which
		// silently mis-applies TCF consent semantics. Mark the output as
		// country-dependent whenever IAB is enabled, even with a single
		// banner and no explicit geo-targeting.
		if ( ! empty( $settings['iab']['enabled'] ) ) {
			$dependent = true;
		}

		if (
			! empty( $settings['geolocation']['geo_targeting'] )
			&& isset( $settings['geolocation']['default_behavior'] )
			&& 'no_banner' === $settings['geolocation']['default_behavior']
		) {
			$dependent = true;
		}

		if ( Controller::get_instance()->has_country_dependent_banners() ) {
			$dependent = true;
		}

		// Runtime geo-routing (flag-gated): when the resolved ruleset drives the
		// pre-consent category defaults, the GCM signals and the blocked-category
		// set, the rendered HTML/JS varies by the visitor's country/region. A
		// cached response would leak one jurisdiction's defaults to another, so
		// the output must always be treated as country-dependent while the flag
		// is on — independent of any multi-banner geo-targeting configuration.
		// 1.18.2 HOTFIX: routed through Geo_Runtime::is_enabled() (hard-false)
		// so the disabled runtime no longer forces no-cache; restoring the
		// feature restores the cache-bust automatically via the same gate.
		if ( Geo_Runtime::is_enabled() ) {
			$dependent = true;
		}

		return (bool) apply_filters( 'faz_country_dependent_banner_output', $dependent, $settings );
	}

	/**
	 * Set common page-cache bypass constants for country-dependent banners.
	 *
	 * @return void
	 */
	private function maybe_disable_country_page_cache() {
		if ( ! $this->is_country_dependent_output() ) {
			return;
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
	}

	/**
	 * Emit no-cache headers for pages whose banner varies by visitor country.
	 *
	 * @return void
	 */
	public function send_geo_cache_headers() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || true === faz_disable_banner() ) {
			return;
		}
		// Match the same scope every other frontend hook in this class uses
		// (enqueue_scripts gates on faz_is_front_end_request too). Without
		// this guard send_headers fires on REST API, heartbeat, sitemap,
		// robots.txt etc., each running the DB-hitting is_country_dependent
		// chain pointlessly.
		if ( ! faz_is_front_end_request() ) {
			return;
		}
		// Mirror load_banner()'s gating: when banner_control is off (or any
		// per-page disable filter trips), the page never renders the banner
		// payload, so emitting Cache-Control: no-store would penalise the
		// site's full-page cache for nothing. Same check load_banner() uses
		// at line 651 — keep them aligned.
		if ( $this->is_banner_disabled_by_settings() ) {
			return;
		}
		if ( ! $this->is_country_dependent_output() || headers_sent() ) {
			return;
		}

		$this->maybe_disable_country_page_cache();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( apply_filters( 'faz_trust_cf_ipcountry_header', false ) ) {
			header( 'Vary: CF-IPCountry', false );
		}
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_control_set_nocache', 'FAZ country-dependent banner' );
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

		$country = $this->get_visitor_country();

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
			// F008 fix: align with admin/assets/js/pages/banner.js
			// REGION_PRESETS.EU which deliberately EXCLUDES GB (UK is its
			// own preset). The pre-fix table included GB for "consistency
			// with Geolocation::$eu_countries", but that produced a
			// silent divergence: a publisher selecting "EU" in the admin
			// (per-banner target_countries) got a 30-country set without
			// GB; the same publisher's global Settings → Geolocation →
			// target_regions=eu got a 31-country set with GB. UK has its
			// own data-protection regime (UK GDPR) and deserves its own
			// preset — keep the 'uk' bucket as the canonical home for GB.
			// Geolocation::$eu_countries is a separate concern (lex
			// generalis EU+UK shorthand for "is this visitor under any
			// GDPR-class law") and remains unchanged.
			'eu' => array(
				'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
				'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
				'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
				// EEA.
				'IS', 'LI', 'NO',
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
	/**
	 * Resolved geo ruleset for the current visitor, or null.
	 *
	 * Gated behind the opt-in `faz_geo_ruleset_runtime` filter (default false),
	 * so existing installs see ZERO behaviour change until they enable it. When
	 * on, the resolved ruleset (model + default_categories + CMv2) drives the
	 * chosen banner's per-category consent defaults, the server-side blocking
	 * decision, the GCM defaults AND the banner selection (the active banner
	 * whose applicableLaw matches the ruleset model). Resolution uses the same
	 * visitor country as banner selection — see get_visitor_country() — so the
	 * two can never disagree on the country (finding #5). Memoised per request
	 * inside Geo_Runtime, keyed by that country.
	 *
	 * @return array|null
	 */
	private function get_runtime_ruleset() {
		if ( $this->is_cache_compatibility_enabled() ) {
			return null;
		}
		return Geo_Runtime::resolve_for_country( $this->get_visitor_country() );
	}

	/**
	 * Build the Google Consent Mode payload passed to gcm.js.
	 *
	 * Reads the stored GCM settings and, when the geo-routing runtime flag is
	 * on and a ruleset resolves for the visitor, overlays the ruleset's
	 * `signals.cmv2` defaults via Geo_Runtime::apply_cmv2_to_gcm().
	 *
	 * @return array|null GCM data array, or null when GCM is disabled.
	 */
	public function get_gcm_data() {
		if ( ! $this->gcm_settings ) {
			return;
		}
		$gcm          = $this->gcm_settings;
		$gcm_settings = $gcm->get();

		// When the runtime geo-routing flag is on, override the Google Consent
		// Mode default signals from the resolved ruleset's CMv2 block so the
		// per-jurisdiction defaults (e.g. Quebec: ad_storage/analytics_storage
		// denied) actually drive GCM, not just the catalogue. Writes the
		// canonical signal keys, which gcm.js reads (with a category-mirror
		// fallback for legacy payloads).
		return Geo_Runtime::apply_cmv2_to_gcm( $this->get_runtime_ruleset(), $gcm_settings );
	}

	/**
	 * Advanced Consent Mode (#165): emit the denied `consent default`
	 * synchronously in <head>, before any Google tag.
	 *
	 * Google requires the default to be set before the gtag snippet, otherwise
	 * the first hit ignores it ("default consent status set too late"). The
	 * baseline is the most-restrictive denied state (every storage denied
	 * except security_storage), so pre-consent hits are cookieless and Google
	 * reports gcs=G100; gcm.js upgrades the relevant signals to granted on the
	 * `fazcookie_consent_update` event.
	 *
	 * No-op unless Advanced mode is on. In Basic mode gcm.js emits the default
	 * as before; in Advanced mode gcm.js skips its own default emission (it
	 * reads `advanced_mode` from the payload) to avoid a duplicate.
	 *
	 * @return void
	 */
	public function print_gcm_default_inline() {
		// AMP pages delegate consent to <amp-consent>; emitting this inline gtag
		// bootstrap there would invalidate the very branch enqueue_scripts(),
		// insert_styles() and load_banner() already skip for AMP.
		if ( apply_filters( 'faz_is_amp_request', false ) ) {
			return;
		}
		if ( ! $this->gcm_settings || ! $this->gcm_settings->is_advanced_mode() ) {
			return;
		}

		$default = array(
			'ad_storage'              => 'denied',
			'ad_user_data'            => 'denied',
			'ad_personalization'      => 'denied',
			'analytics_storage'       => 'denied',
			'functionality_storage'   => 'denied',
			'personalization_storage' => 'denied',
			'security_storage'        => 'granted',
		);
		$wait = absint( $this->gcm_settings->get( 'wait_for_update' ) );
		if ( $wait > 0 ) {
			$default['wait_for_update'] = $wait;
		}

		$redaction    = faz_sanitize_bool( $this->gcm_settings->get( 'ads_data_redaction' ) );
		$passthrough  = faz_sanitize_bool( $this->gcm_settings->get( 'url_passthrough' ) );
		$npa_fallback = faz_sanitize_bool( $this->gcm_settings->get( 'non_personalized_ads_fallback' ) );

		// Resolve the dataLayer array name from the single server-side source
		// (also passed to gcm.js in the _fazGcm payload) so the inline default
		// and gcm.js's `consent update` always push to the SAME array — even
		// when a site uses a non-default dataLayer name.
		// INVARIANT — this inline bootstrap must only ever emit gtag('consent',…)
		// and gtag('set',…) calls. It must NOT emit gtag('config'|'js',…) or
		// dataLayer.push({…}): those are exactly the Google code-signatures
		// is_gcm_managed_script() / match_script_to_provider() look for, so the
		// output-buffer blocker would neutralise this very <script> while still
		// letting the (exempted) gtag stack load — i.e. the denied default would
		// never run and a pre-consent cookie could be written. Keep it to
		// consent/set only so it always falls through the blocker unmatched.
		$dl    = wp_json_encode( $this->gcm_datalayer_name() );
		$js    = 'window[' . $dl . ']=window[' . $dl . ']||[];function gtag(){window[' . $dl . '].push(arguments);}';
		$js   .= "gtag('consent','default'," . wp_json_encode( $default ) . ');';
		if ( $npa_fallback ) {
			// ad_storage is denied in the baseline → request non-personalized ads.
			$js .= "gtag('set',{npa:1});";
		}
		$js .= "gtag('set','ads_data_redaction'," . ( $redaction ? 'true' : 'false' ) . ');';
		$js .= "gtag('set','url_passthrough'," . ( $passthrough ? 'true' : 'false' ) . ');';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS bootstrap; every interpolated value is a bool/int or wp_json_encode of plugin-controlled constants, no user input.
		echo "<script>" . $js . "</script>\n";
	}

	/**
	 * Resolve the JS dataLayer array name. Single source of truth shared by
	 * the synchronous inline default (print_gcm_default_inline) and gcm.js
	 * (via the `data_layer_name` key on the `_fazGcm` payload), so both push
	 * to the same array. Defaults to `dataLayer`; sites using GTM with a
	 * custom dataLayer name can override via the `faz_gcm_datalayer_name`
	 * filter. Restricted to a JS-identifier-safe charset.
	 *
	 * @return string
	 */
	private function gcm_datalayer_name() {
		$name = apply_filters( 'faz_gcm_datalayer_name', 'dataLayer' );
		$name = is_string( $name ) ? preg_replace( '/[^A-Za-z0-9_$]/', '', $name ) : '';
		return '' !== $name ? $name : 'dataLayer';
	}
	/**
	 * Distinct cookie names detected on this site (wp_faz_cookies.name).
	 *
	 * Backs the per-service consent list (P2): the preference center shows
	 * only services that have at least one cookie actually present on this
	 * site, instead of the full provider catalogue. Cached in a transient
	 * because the set changes only when the scanner writes new cookies.
	 *
	 * @return string[]
	 */
	private function get_detected_cookie_names() {
		$cached = get_transient( 'faz_detected_cookie_names' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prefix interpolation only, no user input; result cached in the transient set immediately below.
		$names = $wpdb->get_col( "SELECT DISTINCT name FROM {$wpdb->prefix}faz_cookies WHERE name <> '' AND discovered = 1" );
		$names = is_array( $names ) ? array_values( array_filter( array_map( 'strval', $names ) ) ) : array();
		set_transient( 'faz_detected_cookie_names', $names, 6 * HOUR_IN_SECONDS );
		return $names;
	}

	/**
	 * Whether any cookie name a provider declares matches a detected cookie.
	 *
	 * A "*" anywhere in a provider cookie name is treated as a wildcard
	 * (e.g. "_ga_*" matches "_ga_ABC123"); other names match exactly and
	 * case-insensitively.
	 *
	 * @param string[] $provider_cookies Cookie names the provider declares.
	 * @param string[] $detected_names   Cookie names detected on the site.
	 * @return bool
	 */
	private function provider_has_detected_cookie( $provider_cookies, $detected_names ) {
		foreach ( (array) $provider_cookies as $pc ) {
			$pc = (string) $pc;
			if ( '' === $pc ) {
				continue;
			}
			if ( false !== strpos( $pc, '*' ) ) {
				$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pc, '/' ) ) . '$/i';
				foreach ( $detected_names as $dn ) {
					if ( preg_match( $regex, $dn ) ) {
						return true;
					}
				}
			} else {
				foreach ( $detected_names as $dn ) {
					if ( 0 === strcasecmp( $pc, $dn ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Services eligible for granular consent on this site.
	 *
	 * Only scanner-detected services in an active category are exposed and
	 * enforced. This keeps the preference center and both blocking layers on
	 * the same allowlist.
	 *
	 * @param string[]|null $valid_categories Active category slugs.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_per_service_services( $valid_categories = null ) {
		if ( null !== $this->per_service_cache ) {
			return $this->per_service_cache;
		}

		if ( null === $valid_categories ) {
			$valid_categories = $this->get_valid_category_slugs();
		}

		$services       = array();
		$seen           = array();
		$detected_names = $this->get_detected_cookie_names();
		foreach ( Known_Providers::get_all() as $id => $service ) {
			if ( 'necessary' === $service['category'] || ! in_array( $service['category'], $valid_categories, true ) ) {
				continue;
			}

			$service_cookies = ! empty( $service['cookies'] ) ? array_map( 'sanitize_text_field', $service['cookies'] ) : array();
			if ( ! $this->provider_has_detected_cookie( $service_cookies, $detected_names ) ) {
				continue;
			}

			$service_id          = sanitize_key( $id );
			$services[]          = array(
				'id'          => $service_id,
				'label'       => sanitize_text_field( $service['label'] ),
				'category'    => sanitize_key( $service['category'] ),
				'patterns'    => array_values( array_filter( array_map( 'sanitize_text_field', $service['patterns'] ) ) ),
				'cookies'     => $service_cookies,
				'third_party' => $this->is_third_party_service( $service_id ),
			);
			$seen[ $service_id ] = true;
		}

		// Also surface any enforceable service the visitor has ALREADY made an
		// explicit per-service choice for (svc.<id>:yes|no in the consent cookie),
		// even when the scanner never recorded one of its cookies. A block-first
		// embed such as YouTube only sets its cookies once the real iframe loads,
		// so detection never sees it; the toggle therefore only ever came from the
		// page-level reveal of its blocked placeholder (the client-side reveal in
		// script.js) — which disappears the moment the embed is accepted and the
		// iframe runs unblocked. Without this branch the granted (or denied)
		// service has no toggle in the preference center on a page without the
		// placeholder, so the choice can never be reviewed or WITHDRAWN (GDPR
		// Art. 7(3): withdrawal must be as easy as consent). Keying off the
		// persisted svc.* decision makes the toggle stick site-wide, independent
		// of any placeholder being present — confirmed against criptasemantica.it,
		// where accepting YouTube emptied `_services` and the toggle vanished.
		//
		// Page-cache safety: get_service_consent() returns an empty map whenever
		// there is no consent cookie (and when per-service consent is off), so for
		// the cacheable first-visit/anonymous request this whole branch is a no-op
		// and `_services` is byte-identical to the detection-only list. The
		// augmentation only fires for a request that already carries a consent
		// cookie — output that is visitor-dependent under the plugin's existing
		// per-service/per-cookie server enforcement regardless of this method.
		$decided = $this->get_service_consent(); // id => yes|no, already enforceable-gated.
		if ( ! empty( $decided ) ) {
			$enforceable_by_id = array();
			foreach ( $this->get_enforceable_services( $valid_categories ) as $enforceable ) {
				if ( ! empty( $enforceable['id'] ) ) {
					$enforceable_by_id[ $enforceable['id'] ] = $enforceable;
				}
			}
			foreach ( array_keys( $decided ) as $decided_id ) {
				if ( isset( $seen[ $decided_id ] ) || ! isset( $enforceable_by_id[ $decided_id ] ) ) {
					continue;
				}
				$service_entry = $enforceable_by_id[ $decided_id ];
				// Only surface a decided service whose category is still active. The
				// enforceable cache may have been computed earlier in the request for
				// a broader category set (it ignores the $valid_categories argument
				// once memoised), so re-check here rather than trust the lookup. A
				// site that renamed/removed the provider's category, or disabled it,
				// then never shows a stale toggle.
				if ( empty( $service_entry['category'] ) || ! in_array( $service_entry['category'], $valid_categories, true ) ) {
					continue;
				}
				$services[]          = $service_entry;
				$seen[ $decided_id ] = true;
			}
		}

		/**
		 * Filter the per-service consent list.
		 *
		 * This is the AUTHORITATIVE service list — it feeds BOTH the client
		 * store (`_services`) AND the server-side enforcement (the pre-consent
		 * block decision and the cookie shredder all call this method), so a
		 * service added, removed, or re-categorised here stays consistent across
		 * the UI, the output buffer, and PHP shredding. Prefer this over
		 * `faz_store_data` for anything that must actually be enforced.
		 *
		 * @since 1.19.0
		 * @param array $services         Each: {id,label,category,patterns,cookies}.
		 * @param array $valid_categories Active non-necessary category slugs.
		 */
		$services = apply_filters( 'faz_per_service_services', $services, $valid_categories );

		$this->per_service_cache = $services;
		return $this->per_service_cache;
	}

	/**
	 * Providers whose explicit per-service choice (svc.<id>:yes|no) must be
	 * enforced, regardless of whether the scanner has seen one of their cookies.
	 *
	 * get_per_service_services() drives the visible preference-center toggles and
	 * is deliberately narrowed to scanner-detected services. Enforcement is
	 * broader: a visitor can accept or reject a provider straight from its
	 * content-blocker placeholder (e.g. a YouTube embed) on a block-first site
	 * where the provider's cookie was never observed — so its svc.* token has no
	 * detected-cookie entry to anchor to. This returns every Known_Providers
	 * entry in an active (non-necessary) category so get_service_consent() keeps
	 * such a token and get_pattern_service_map() can match the embed. Matching
	 * still only fires on a real script/iframe, and a provider with no explicit
	 * choice still falls through to category-level blocking (#134/#146).
	 *
	 * @param string[]|null $valid_categories Active category slugs.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_enforceable_services( $valid_categories = null ) {
		if ( null !== $this->enforceable_cache ) {
			return $this->enforceable_cache;
		}
		if ( null === $valid_categories ) {
			$valid_categories = $this->get_valid_category_slugs();
		}
		$services = array();
		foreach ( Known_Providers::get_all() as $id => $service ) {
			if ( 'necessary' === $service['category'] || ! in_array( $service['category'], $valid_categories, true ) ) {
				continue;
			}
			$enforceable_id = sanitize_key( $id );
			$services[]     = array(
				'id'          => $enforceable_id,
				'label'       => sanitize_text_field( $service['label'] ),
				'category'    => sanitize_key( $service['category'] ),
				'patterns'    => array_values( array_filter( array_map( 'sanitize_text_field', $service['patterns'] ) ) ),
				'cookies'     => ! empty( $service['cookies'] ) ? array_map( 'sanitize_text_field', $service['cookies'] ) : array(),
				'third_party' => $this->is_third_party_service( $enforceable_id ),
			);
		}
		/**
		 * Filter the enforceable per-service list (broader than the visible one).
		 *
		 * @since 1.20.0
		 * @param array $services         Each: {id,label,category,patterns,cookies}.
		 * @param array $valid_categories Active non-necessary category slugs.
		 */
		$services = apply_filters( 'faz_enforceable_services', $services, $valid_categories );
		$this->enforceable_cache = $services;
		return $this->enforceable_cache;
	}

	/**
	 * Presentation catalogue of per-service providers, keyed by service id.
	 *
	 * Shipped to the client so it can REVEAL a per-service toggle for a provider
	 * it actually blocks at runtime — a server-rendered placeholder
	 * (data-faz-service) or a JS-injected embed the MutationObserver catches —
	 * even when the scanner never saw the provider's cookie (block-first sites)
	 * or never saw the embed at all (JS-injected). Built from the SAME enforceable
	 * set used for blocking + shredding, so a revealed toggle is always a service
	 * the server already enforces, and scoped to active categories so it never
	 * dumps the whole ~160-entry catalogue. Presentation-only — enforcement reads
	 * get_enforceable_services() directly. #134/#146.
	 *
	 * @param string[]|null $valid_categories Active category slugs.
	 * @return array<string,array<string,mixed>> id => {id,label,category,cookies}.
	 */
	private function get_service_catalogue( $valid_categories = null ) {
		if ( null === $valid_categories ) {
			$valid_categories = $this->get_valid_category_slugs();
		}
		$catalogue = array();
		foreach ( $this->get_enforceable_services( $valid_categories ) as $svc ) {
			if ( empty( $svc['id'] ) ) {
				continue;
			}
			// Re-check the category against the live active set: get_enforceable_services()
			// memoises and ignores its $valid_categories argument once cached, so a cache
			// populated earlier for a broader set could otherwise ship a toggle for a
			// disabled/renamed category. Mirrors the guard in the withdrawal augmentation.
			if ( empty( $svc['category'] ) || ! in_array( $svc['category'], $valid_categories, true ) ) {
				continue;
			}
			$catalogue[ $svc['id'] ] = array(
				'id'          => $svc['id'],
				'label'       => isset( $svc['label'] ) ? $svc['label'] : $svc['id'],
				'category'    => isset( $svc['category'] ) ? $svc['category'] : '',
				'cookies'     => isset( $svc['cookies'] ) ? array_values( (array) $svc['cookies'] ) : array(),
				// Carried so the client-side reveal (which builds _services entries
				// from this catalogue) can flag embed services whose third-party
				// cookies the first-party shredder can't delete. #134/#146.
				'third_party' => ! empty( $svc['third_party'] ),
			);
		}
		return $catalogue;
	}

	/**
	 * Whether a service id is an embedded third-party widget whose cookies the
	 * first-party shredder cannot delete (so per-cookie enforcement is by
	 * blocking the embed, not by removing individual cookies).
	 *
	 * Guarded with class_exists() so the lookup degrades to false — never a
	 * fatal — if the Placeholder_Builder class is unavailable for any reason on
	 * a given install; under the normal autoloader it resolves and loads.
	 *
	 * @param string $service_id Sanitised service identifier.
	 * @return bool
	 */
	private function is_third_party_service( $service_id ) {
		return class_exists( Placeholder_Builder::class )
			&& Placeholder_Builder::is_embed_service( $service_id );
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

		// Consent-cookie lifetime, with a law-aware hard cap applied here so the
		// EFFECTIVE expiry can never exceed the legal maximum regardless of any
		// larger value an admin saved (the UI allows up to 10 years). The
		// Italian Garante (Linee guida cookie, 10 Jun 2021) caps consent
		// validity at 6 months for opt-in (GDPR-family) banners, so those are
		// clamped to 182 days. Opt-out (CCPA/CPRA) banners are NOT subject to
		// that cap — a LONGER lifetime is actually preferable there (CPRA bars
		// asking a user to re-confirm an opt-out more than once per 12 months),
		// so their configured value (default 365) is honoured as-is.
		$faz_configured_expiry = isset( $banner_settings['settings']['consentExpiry']['value'] )
			? absint( $banner_settings['settings']['consentExpiry']['value'] )
			: 180;
		$faz_expiry = ( 'ccpa' === $banner->get_law() )
			? max( 1, $faz_configured_expiry )
			: max( 1, min( 182, $faz_configured_expiry ) );

		$providers = array();
		$store     = array(
			'_ipData'       => array(),
			'_assetsURL'    => FAZ_PLUGIN_URL . 'frontend/images/',
			'_publicURL'    => set_url_scheme( get_site_url() ),
			'_expiry'       => $faz_expiry,
			'_categories'   => $this->get_cookie_groups(),
			'_activeLaw'         => $banner->get_law(),
			'_bannerSlug'        => $banner->get_slug(),
			'_geoRouting'        => $this->is_country_dependent_output(),
			// When a runtime geo ruleset is active, the per-category
			// defaultConsent values are jurisdiction-authoritative and the JS
			// must derive the pre-consent state directly from them rather than
			// from the binary law branch (which, under the CCPA opt-out model,
			// would otherwise leave non-necessary categories granted until the
			// visitor ticks the opt-out box — running blocked scripts). See
			// _fazSetInitialState().
			'_runtimeGeo'        => ( null !== $this->get_runtime_ruleset() ),
			// Server-side fingerprint of the active scope, keyed by
			// wp_salt('auth'). Used by _fazConsentScopeChanged() to detect
			// cookie tampering: a visitor who hand-edits __scope.banner /
			// __scope.law to match the current scope cannot also forge a
			// matching __scope.fp without knowing the salt, so any
			// tampering attempt invalidates and re-prompts. Truncated to
			// 32 hex chars — enough collision resistance for an integrity
			// check, short enough to stay light in the cookie.
			'_scopeFingerprint'  => substr( wp_hash( $banner->get_slug() . '|' . $banner->get_law(), 'auth' ), 0, 32 ),
			/**
			 * Strict-fingerprint mode (issue #106).
			 *
			 * When the admin returns true via the
			 * `faz_strict_scope_fingerprint` filter, the JS scope check
			 * ignores the legacy unprefixed `banner` / `law` cookie keys
			 * and requires `__scope.fp` to match the server-published
			 * fingerprint. Cookies written by pre-1.14.0 / fallback-only
			 * builds are then treated as invalid scope info → safe
			 * re-prompt. Default off (back-compat); planned default
			 * flip in 1.16.0. Compatible with ClassicPress 1.x (uses
			 * `apply_filters` only, no WP 6.x-only APIs).
			 */
			'_strictScopeFp'     => (bool) apply_filters( 'faz_strict_scope_fingerprint', false ),
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
				'consent_saved'                         => __( 'Your cookie preferences have been saved.', 'faz-cookie-manager' ),
				'optout_preferences_label'              => __( 'Opt-out Preferences', 'faz-cookie-manager' ),
				'customise_consent_preferences_label'   => __( 'Customise Consent Preferences', 'faz-cookie-manager' ),
				'service_consent_label'                 => __( 'Service consent', 'faz-cookie-manager' ),
				'cookies'                               => __( 'Cookies', 'faz-cookie-manager' ),
				'cookie_consent_label'                  => __( 'Cookie consent', 'faz-cookie-manager' ),
				'vendor_consent_label'                  => __( 'Vendor consent', 'faz-cookie-manager' ),
				'third_party_cookie_note'               => __( 'These cookies are set by the embedded service on its own domain and are controlled by allowing or blocking the embed above — they cannot be removed individually.', 'faz-cookie-manager' ),
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
			// Optional high-coverage runtime CSS-url blocker. Default off because
			// the aggressive client hooks touch broad browser APIs (Element.innerHTML,
			// insertAdjacentHTML, CharacterData inside <style>, Constructable
			// Stylesheets). Baseline blocking still covers server-rendered <style>
			// tags and direct HTMLStyleElement writes before consent.
			'_aggressiveCssUrlBlocking' => ! empty( $settings['script_blocking']['aggressive_css_url_blocking'] ),
		);
		// Merge DB-based providers with Known_Providers for client-side blocking.
		$valid_categories = $this->get_valid_category_slugs();
		$known            = Known_Providers::get_all();
		$provider_services = array(); // pattern => service id (per-service embed resolution).
		foreach ( $known as $known_id => $service ) {
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

		// Resolve pattern → service id from the SAME enforceable set the server
		// uses for svc.* decisions, the pattern→service map and the cookie
		// shredder (get_enforceable_services()), so the faz_enforceable_services
		// filter can never make the client resolve an embed to a different
		// service than the server enforces. The category-blocking map above
		// stays on the raw Known_Providers set on purpose — per-category
		// blocking must not depend on the per-service toggle/filter. #134/#146.
		foreach ( $this->get_enforceable_services( $valid_categories ) as $service ) {
			$service_id = isset( $service['id'] ) ? sanitize_key( $service['id'] ) : '';
			if ( '' === $service_id || empty( $service['patterns'] ) ) {
				continue;
			}
			foreach ( (array) $service['patterns'] as $pattern ) {
				$pattern = sanitize_text_field( (string) $pattern );
				if ( '' === $pattern || isset( $provider_services[ $pattern ] ) ) {
					continue;
				}
				$provider_services[ $pattern ] = $service_id;
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

		$expose_provider_service = ! empty( $settings['banner_control']['per_service_consent'] );
		foreach ( $this->providers as $key => $value ) {
			$entry = array(
				're'         => $key,
				'categories' => $value,
			);
			// Carry the provider's service id ONLY when per-service consent is on,
			// so the client can resolve a matched embed (even a dynamically
			// injected one, or a clean server-allowed iframe after a reload) to
			// its svc.<id> decision instead of re-blocking it under the still-
			// denied category. No extra payload on non-per-service sites. #134/#146.
			if ( $expose_provider_service && ! empty( $provider_services[ $key ] ) ) {
				$entry['service'] = $provider_services[ $key ];
			}
			$providers[] = $entry;
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

		// Per-cookie opt-in/opt-out scripts grouped by category slug.
		// Only populated when at least one cookie has a script defined.
		// Result is cached in a transient (12h) and invalidated on cookie saves.
		$cookie_scripts = get_transient( 'faz_cookie_scripts_map' );
		if ( false === $cookie_scripts ) {
			$cookie_scripts = $this->build_cookie_scripts_map();
			set_transient( 'faz_cookie_scripts_map', $cookie_scripts, HOUR_IN_SECONDS * 12 );
		}
		if ( ! empty( $cookie_scripts ) ) {
			$store['_cookieScripts'] = $cookie_scripts;
		}

		// Age gate (GDPR Art. 8).
		$age_gate = array(
			'enabled' => ! empty( $settings['age_gate']['enabled'] ),
			'minAge'  => isset( $settings['age_gate']['min_age'] ) ? absint( $settings['age_gate']['min_age'] ) : 16,
		);
		$store['_ageGate'] = $age_gate;

		// GTM Data Layer toggle.
		$store['_gtmDataLayer'] = ! empty( $settings['banner_control']['gtm_datalayer'] );
		// Advanced Consent Mode (#165): mirror the server-side gtag exemption to
		// the client blocker so dynamically-injected Google tags (which bypass
		// the output buffer) are also allowed to load before consent.
		$store['_gcmAdvanced'] = $this->gcm_settings ? (bool) $this->gcm_settings->is_advanced_mode() : false;

		// IAB vendor data for preference center.
		// Mirror the asset-enqueue gate above: the IAB/TCF preference UI is
		// only surfaced when a registered CMP ID (>= 2) is configured. CMP IDs
		// 0 and 1 are reserved/invalid in the IAB GVL, so emitting the vendor
		// list and purpose toggles without one would show a TCF UI that can
		// never produce a valid TC string (tcf-cmp.js is not enqueued in that
		// state). Keep both gates in sync.
		$iab_enabled = (bool) $this->settings->get( 'iab', 'enabled' )
			&& absint( $this->settings->get( 'iab', 'cmp_id' ) ) >= 2;
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
		// 1.18.3: per-service consent reintroduced — read the option again so the
		// resolved service list reaches the frontend preference center. 1.20.0:
		// per-COOKIE consent ungated — when its toggle is on, nested per-cookie
		// (ck.*) overrides are emitted below and enforced server-side too (see
		// shred_non_consented_cookies). The 1.18.2-hotfix correctness gaps stay
		// closed: per-service (svc.*) and per-cookie (ck.*) decisions are logged
		// (P1-3, inline consent logger), the consent cookie is hard-capped against
		// the 4 KB browser limit (P1-4, see _fazSetInStore), and the list below is
		// filtered to providers with a DETECTED cookie (P2, just here).
		$per_service = ! empty( $settings['banner_control']['per_service_consent'] );
		if ( $per_service ) {
			// P2: source the per-service toggle list from the cookies actually
			// DETECTED on this site (wp_faz_cookies), not the full ~160-entry
			// provider catalogue. A provider is shown only when at least one of
			// the cookie names it declares matches a detected cookie (wildcards
			// like "_ga_*" supported). Until a scan has run, $detected_names is
			// empty and NO provider matches, so the list is empty and the
			// preference center falls back to category-level toggles — we do NOT
			// dump the whole catalogue, which would both over-disclose unrelated
			// services and bloat the consent cookie toward the P1-4 cap. The admin
			// help text tells the publisher to run a scan to populate the list.
			$services                    = $this->get_per_service_services( $valid_categories );
			$store['_perServiceConsent'] = true;
			$store['_services']         = $services;
			// Presentation catalogue for runtime-revealed toggles. The visible
			// list above (`_services`) is scanner-detected only, so on a block-first
			// site — where a provider's cookie is never set, and a JS-injected embed
			// (e.g. a YouTube iframe_api player) never appears in the server HTML the
			// scanner fetches — it stays empty and no toggle ever shows. This map
			// lets the client REVEAL a per-service toggle for a provider it actually
			// blocks at runtime (the MutationObserver resolves the embed to its
			// svc.<id> via `_providersToBlock`), looking up the label/cookies here.
			// Keyed by service id; scoped to the SAME enforceable set used for
			// blocking + shredding, so a revealed toggle is always a service the
			// server already enforces. Presentation-only: the consent cookie still
			// gains svc.* tokens solely for services the visitor toggles, so this
			// adds no P1-4 bloat, and only present (blocked) providers are revealed,
			// so it never dumps the whole catalogue. #134/#146.
			$store['_serviceCatalogue'] = $this->get_service_catalogue( $valid_categories );
			// Per-cookie consent: nested per-cookie toggles inside each accepted
			// service. Enabled only when its admin toggle is on (and per-service
			// is on, which it is in this branch). Enforcement is by cookie
			// shredding on every request — client-side (_fazCleanupRevokedCookies)
			// and server-side (shred_non_consented_cookies, which reads the same
			// ck.* tokens) — since the service script, not the individual cookie,
			// is the atomic unit that gets gated.
			$store['_perCookieConsent'] = ! empty( $settings['banner_control']['per_cookie_consent'] );
		}

		/**
		 * Filter the frontend consent store emitted to the page — PRESENTATION ONLY.
		 *
		 * Lets integrations (and isolated test harnesses) adjust the
		 * CLIENT-SIDE store — what the JS renders (categories shown, flags,
		 * labels) — for the current request, without DB writes. It does NOT
		 * change server-side enforcement: the pre-consent script block and the
		 * cookie shredder read their own internal sources, so altering
		 * `_services` / flags here only affects the rendered UI and can diverge
		 * from what PHP actually blocks. To affect the ENFORCED per-service list
		 * (keeping UI, output buffer, and shredder consistent), use the
		 * `faz_per_service_services` filter instead.
		 *
		 * @since 1.19.0
		 * @param array    $store    The assembled store payload.
		 * @param Frontend $frontend The frontend instance.
		 */
		$filtered_store = apply_filters( 'faz_store_data', $store, $this );
		// Defend against a misbehaving filter callback that returns a non-array
		// (null, string, …): keep the original store rather than fatalling on the
		// array index below before the frontend can localise _fazConfig.
		if ( is_array( $filtered_store ) ) {
			$store = $filtered_store;
		}

		return $store;
	}

	/**
	 * Build the per-cookie opt-in/opt-out script map grouped by category slug.
	 *
	 * Replaces a previously unbounded LIKE+LIMIT 500 query that silently
	 * dropped rows beyond the cap. The new shape:
	 *   - paged ORDER BY cookie_id, 1000 rows per page (stable, deterministic)
	 *   - hard ceiling of 10,000 rows (defends against runaway tables)
	 *   - LIKE patterns anchored on the JSON KEY form (`"opt_in_script":`)
	 *     to eliminate false positives where the literal string appears
	 *     inside a description or comment
	 *   - error_log() warning if the ceiling is hit so publishers can see
	 *     it in the PHP error log instead of silently losing scripts.
	 *
	 * Result is cached by the caller in a 12h transient and invalidated
	 * on cookie saves / category renames (see Cookies module hooks).
	 *
	 * @return array<string, array{opt_in: string[], opt_out: string[]}>
	 */
	private function build_cookie_scripts_map() {
		global $wpdb;

		$cookie_scripts = array();
		$max_rows       = 10000; // hard ceiling to bound worst-case scans
		$page_size      = 1000;
		$last_id        = 0;
		$scanned        = 0;
		$ceiling_hit    = false;

		// LIKE patterns anchored on the JSON-key form (`"opt_in_script":"...`)
		// so a description containing the literal text "opt_in_script" cannot
		// false-positive into the result set. json_decode() below remains the
		// authoritative parser; LIKE only narrows the rows we have to decode.
		$like_opt_in  = '%"opt_in_script":"%';
		$like_opt_out = '%"opt_out_script":"%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, table-prefix interpolation only; values bound via $wpdb->prepare(); result used only for inline JS config, not rendered as HTML; outer transient provides caching.
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.cookie_id, c.meta, cat.slug AS category_slug
					 FROM {$wpdb->prefix}faz_cookies c
					 INNER JOIN {$wpdb->prefix}faz_cookie_categories cat ON c.category = cat.category_id
					 WHERE c.cookie_id > %d
					   AND ( c.meta LIKE %s OR c.meta LIKE %s )
					 ORDER BY c.cookie_id ASC
					 LIMIT %d",
					$last_id,
					$like_opt_in,
					$like_opt_out,
					$page_size
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->cookie_id;
				++$scanned;

				$meta     = json_decode( $row->meta, true );
				$cat_slug = sanitize_key( $row->category_slug );
				if ( ! is_array( $meta ) || ! $cat_slug ) {
					continue;
				}
				if ( ! isset( $cookie_scripts[ $cat_slug ] ) ) {
					$cookie_scripts[ $cat_slug ] = array( 'opt_in' => array(), 'opt_out' => array() );
				}
				if ( ! empty( $meta['opt_in_script'] ) ) {
					$cookie_scripts[ $cat_slug ]['opt_in'][] = (string) $meta['opt_in_script'];
				}
				if ( ! empty( $meta['opt_out_script'] ) ) {
					$cookie_scripts[ $cat_slug ]['opt_out'][] = (string) $meta['opt_out_script'];
				}

				if ( $scanned >= $max_rows ) {
					$ceiling_hit = true;
					break 2;
				}
			}
		} while ( count( $rows ) === $page_size );
		// phpcs:enable

		if ( $ceiling_hit ) {
			// Surface truncation in PHP error log so publishers can detect
			// the cap was reached and act (split categories, prune unused
			// cookies, etc.). Intentionally not a user-facing notice — this
			// runs on every front-end page render path.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[faz-cookie-manager] build_cookie_scripts_map: hit hard ceiling of %d rows; additional cookies with opt_in_script/opt_out_script were NOT loaded. Review wp_faz_cookies size.',
					$max_rows
				)
			);
		}

		return $cookie_scripts;
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
			// Strip query string + fragment so `/privacy/*` matches
			// `/privacy/?utm=foo` and `/privacy/`. Normalise empty path to
			// `/` so root exclusion (`/`) works.
			$current_path = strtok( (string) $current_url, '?' );
			$current_path = strtok( (string) $current_path, '#' );
			if ( '' === (string) $current_path ) {
				$current_path = '/';
			}
			foreach ( $excluded as $exclusion ) {
				if ( is_numeric( $exclusion ) && absint( $exclusion ) === absint( $current_id ) ) {
					return true;
				}
				if ( is_string( $exclusion ) && '' !== trim( $exclusion ) ) {
					if ( faz_path_matches_pattern( trim( $exclusion ), $current_path ) ) {
						return true;
					}
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

		// Runtime geo-routing opt-in fallback with no matching banner: keep
		// blocking active (template loaded) but hide the mismatched opt-out UI.
		if ( $this->faz_law_fallback_suppress ) {
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
		$current_url  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_path = strtok( (string) $current_url, '?' );
		$current_path = strtok( (string) $current_path, '#' );
		if ( '' === (string) $current_path ) {
			$current_path = '/';
		}
		foreach ( $excluded as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}
			$pattern = trim( $pattern );
			if ( empty( $pattern ) ) {
				continue;
			}
			if ( faz_path_matches_pattern( $pattern, $current_path ) ) {
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

		// Block tracking pixel <img> inside <noscript> FIRST — BEFORE the stash.
		// Meta Pixel, GA, Adobe Analytics etc. embed `<img>` beacons inside
		// `<noscript>` blocks for JS-disabled visitors; those still need to be
		// consent-gated server-side. process_noscript_tag rewrites the
		// `<noscript>` block's inner content (img src → data-faz-src + data-faz-
		// category) but preserves the enclosing `<noscript>` wrapper, so the
		// stash pass below still finds the (now-processed) block and protects
		// it from the iframe filter. Running this BEFORE the stash keeps the
		// matcher's `<noscript>` lookup productive — the original placement
		// AFTER the stash made this step dead code because every `<noscript>`
		// had already been replaced with a `__FAZ_NOSCRIPT_STASH_N__` marker.
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

		// Stash <noscript> blocks BEFORE running the script/iframe/link filters.
		// <noscript> content only renders when JS is disabled; visitors with JS
		// (>99% case) never see it. Without stashing, an iframe wrapped in
		// <noscript> (e.g. theme's "video fallback for non-JS users") would be
		// rewritten by process_iframe_tag into a consent placeholder that lives
		// forever in the DOM as a 0×0 phantom — invisible to humans, but found
		// by document.querySelector. The phantom collides with
		// `_fazAddPlaceholder()` placeholders for legitimate dynamic iframes
		// and breaks any `.first()`-style locator. Restore after all filters.
		// (The tracking-pixel pass above runs FIRST so its noscript matcher is
		// not defeated by the markers this stash introduces.)
		$noscript_stash = array();
		if ( false !== stripos( $html, '<noscript' ) ) {
			$stash_result = preg_replace_callback(
				'#<noscript\b[^>]*>.*?</noscript>#is',
				function ( $m ) use ( &$noscript_stash ) {
					$key = '__FAZ_NOSCRIPT_STASH_' . count( $noscript_stash ) . '__';
					$noscript_stash[ $key ] = $m[0];
					return $key;
				},
				$html
			);
			if ( null === $stash_result ) {
				$pcre_failed = true;
			} else {
				$html = $stash_result;
			}
		}

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

		// 3. (Moved above the stash — see "Block tracking pixel <img> inside
		//     <noscript>" earlier in this method. Keeping the step-number gap
		//     to avoid renumbering the rest of the pipeline.)

		// 4. Block inline CSS url()/@import channels — any blocked-provider target
		//    inside a <style> (fonts, background-image, cursor, mask, @import).
		if ( false !== stripos( $html, '<style' ) ) {
			$result = preg_replace_callback(
				'#<style\b([^>]*)>(.*?)</style>#is',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_style_tag( $m, $providers, $blocked_categories );
				},
				$html
			);
			if ( null === $result ) {
				$pcre_failed = true;
			} else {
				$html = $result;
			}
		}

		// 5. Block <link rel="stylesheet"> (Google Fonts, Adobe Fonts, etc.).
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

		// 6. Block <script data-faz-waitfor="category"> (deferred dependency scripts).
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

		// Restore <noscript> blocks we stashed before the filter pass.
		if ( ! empty( $noscript_stash ) ) {
			$html = strtr( $html, $noscript_stash );
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

		$id = $this->extract_tag_attr( $attrs, 'id' );
		if ( $this->is_own_inline_script_id( $id ) ) {
			return $full;
		}
		// wp_localize_script / wp_set_script_translations payloads carry only
		// config data or translation strings — never executable tracker code.
		// Substring matching against their body produces false positives
		// (e.g. trx_addons emits `animate_to_mc4wp_form_submitted` which
		// would otherwise match the `mc4wp` MailChimp pattern and crash
		// the page with `ReferenceError: TRX_ADDONS_STORAGE is not defined`).
		if ( $this->is_wp_localize_or_translations_inline_id( $id ) ) {
			return $full;
		}

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

		// Advanced Consent Mode (#165): when on, let Consent-Mode-aware Google
		// tags (gtag.js / GA4 / Ads) load before consent. gcm.js has already
		// emitted a synchronous `consent default → denied`, so these send
		// cookieless/modeled pings and upgrade on consent instead of being
		// hard-blocked. The GTM *container* (gtm.js) is intentionally NOT
		// exempt: it can host non-Google tags that ignore Consent Mode.
		//
		// But never override an EXPLICIT per-service denial: if the visitor
		// rejected this specific Google service via per-service consent
		// (`svc.<id>:no` → $svc_blocked === true), that deliberate choice wins
		// and the tag stays hard-blocked. The exemption only applies to the
		// pre-consent / category-default state, not an explicit rejection.
		if ( true !== $svc_blocked
			&& $this->gcm_settings && $this->gcm_settings->is_advanced_mode()
			&& $this->is_gcm_managed_script( $attrs, $content ) ) {
			return $full;
		}

		$new_attrs = $this->set_script_type_plain( $attrs );
		$new_attrs .= ' data-faz-category="' . esc_attr( $matched_category ) . '"';
		return '<script' . $new_attrs . '>' . $content . '</script>';
	}

	/**
	 * Whether a script is a Consent-Mode-aware Google tag that Advanced
	 * Consent Mode may load before consent (gtag.js / GA4 / Google Ads).
	 *
	 * Deliberately gtag-direct only: the GTM container (`gtm.js`) is excluded
	 * because it can host non-Google tags that don't read Consent Mode, so
	 * exempting it would let those load ungated.
	 *
	 * The URL needles are kept in sync with `_fazIsGcmManaged()` in
	 * frontend/js/script.js. The inline-call needles (`gtag('config'` /
	 * `gtag('js'`) are PHP-only by nature: they match a server-rendered inline
	 * gtag bootstrap, which the client matcher never sees (it gates dynamically
	 * created scripts by their `src` URL, not an inline body). They match the
	 * specific gtag *bootstrap* calls — `config` (tag init) and `js` (load
	 * timestamp) — rather than a bare `gtag(` substring, so a non-Google inline
	 * script that merely references the string `gtag(` is NOT exempted.
	 *
	 * @param string $attrs   Script tag attributes.
	 * @param string $content Inline script body.
	 * @return bool
	 */
	private function is_gcm_managed_script( $attrs, $content ) {
		$ctx = $this->get_provider_match_context( $attrs, $content );
		$hay = $ctx['haystack'];
		foreach ( array(
			'googletagmanager.com/gtag/js',
			"gtag('config'",
			'gtag("config"',
			"gtag('js'",
			'gtag("js"',
			'googleadservices.com',
			'googlesyndication.com',
			'doubleclick.net',
		) as $needle ) {
			if ( false !== stripos( $hay, $needle ) ) {
				return true;
			}
		}
		return false;
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
	 * Process an inline <style> tag for blockable CSS url()/@import references.
	 *
	 * Any external resource referenced from CSS — @font-face src:url(...), but
	 * also background-image, cursor, border-image, mask, list-style-image and
	 * bare-string @import — is fetched by the CSS engine, not by <link href>, so
	 * the stylesheet gate cannot see it. Keep the non-provider CSS active, replace
	 * only blocked-provider url()/@import targets with an inert data: URL, and
	 * store the original CSS for the frontend restore pass.
	 *
	 * @param array $m                  Regex match.
	 * @param array $providers          Provider-to-category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_style_tag( $m, $providers, $blocked_categories ) {
		$attrs   = isset( $m[1] ) ? $m[1] : '';
		$content = isset( $m[2] ) ? $m[2] : '';
		$full    = isset( $m[0] ) ? $m[0] : '';

		if ( '' === trim( $content ) || false !== strpos( $attrs, 'data-faz-css' ) ) {
			return $full;
		}
		if ( $this->is_whitelisted( $attrs, '' ) ) {
			return $full;
		}

		$categories = array();
		$rewritten  = $this->neutralize_blocked_css_urls( $content, $providers, $blocked_categories, $categories );
		if ( $rewritten === $content || empty( $categories ) ) {
			return $full;
		}

		$category = reset( $categories );
		$new_attrs = $attrs;
		$new_attrs .= ' data-faz-css="' . esc_attr( base64_encode( $content ) ) . '"'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- inert transport for original CSS text, decoded only after consent.
		$new_attrs .= ' data-faz-category="' . esc_attr( $category ) . '"';

		return '<style' . $new_attrs . '>' . $rewritten . '</style>';
	}

	/**
	 * Replace blocked CSS URL tokens with an inert data URL.
	 *
	 * @param string $css                CSS text.
	 * @param array  $providers          Provider-to-category map.
	 * @param array  $blocked_categories Currently blocked category slugs.
	 * @param array  $categories         Output list of categories that triggered a block.
	 * @return string Rewritten CSS.
	 */
	private function neutralize_blocked_css_urls( $css, $providers, $blocked_categories, &$categories ) {
		$categories = array();
		$inert_url  = 'data:application/octet-stream,';
		$block_url  = function ( $url ) use ( $providers, $blocked_categories, &$categories ) {
			$url      = trim( (string) $url );
			$category = $this->css_url_block_category( $url, $providers, $blocked_categories );
			if ( false === $category ) {
				return false;
			}
			$categories[] = $category;
			return true;
		};

		$original = (string) $css;

		$rewritten = preg_replace_callback(
			'/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s]+))\s*\)/i',
			function ( $m ) use ( $block_url, $inert_url ) {
				$url = isset( $m[1] ) && '' !== $m[1] ? $m[1] : ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : ( isset( $m[3] ) ? $m[3] : '' ) );
				if ( ! $block_url( $url ) ) {
					return $m[0];
				}
				return 'url("' . $inert_url . '")';
			},
			$original
		);
		// PCRE error (backtrack/recursion limit on crafted or very large CSS)
		// returns null. Fail OPEN: reset categories and return the ORIGINAL CSS
		// so process_style_tag leaves the <style> untouched, instead of emitting
		// an empty <style> body that strips every rule. Also never pass a null
		// subject into the second pass (deprecated / TypeError on PHP 8.1+).
		if ( null === $rewritten ) {
			$categories = array();
			return $original;
		}

		$rewritten = preg_replace_callback(
			'/(@import\s+)(["\'])([^"\']+)\2/i',
			function ( $m ) use ( $block_url, $inert_url ) {
				if ( ! $block_url( $m[3] ) ) {
					return $m[0];
				}
				return $m[1] . 'url("' . $inert_url . '")';
			},
			$rewritten
		);
		if ( null === $rewritten ) {
			$categories = array();
			return $original;
		}

		$categories = array_values( array_unique( $categories ) );
		return $rewritten;
	}

	/**
	 * Return the category that requires a CSS URL to be blocked.
	 *
	 * @param string $url                CSS url()/@import target.
	 * @param array  $providers          Provider-to-category map.
	 * @param array  $blocked_categories Currently blocked category slugs.
	 * @return string|false Category slug, or false when allowed.
	 */
	private function css_url_block_category( $url, $providers, $blocked_categories ) {
		$url = trim( (string) $url, " \t\n\r\0\x0B\"'" );
		if ( '' === $url || '#' === $url[0] || 0 === stripos( $url, 'data:' ) || 0 === stripos( $url, 'blob:' ) ) {
			return false;
		}

		$service_consent = $this->get_service_consent();
		$pattern_map     = ! empty( $service_consent ) ? $this->get_pattern_service_map() : array();
		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) || ! $this->provider_url_pattern_matches( $url, $pattern ) ) {
				continue;
			}

			$explicit_yes = false;
			if ( ! empty( $service_consent ) && ! empty( $pattern_map[ $pattern ] ) ) {
				foreach ( $pattern_map[ $pattern ] as $service_id ) {
					if ( isset( $service_consent[ $service_id ] ) && 'no' === $service_consent[ $service_id ] ) {
						return $category;
					}
					if ( isset( $service_consent[ $service_id ] ) && 'yes' === $service_consent[ $service_id ] ) {
						$explicit_yes = true;
					}
				}
			}

			if ( ! $explicit_yes && in_array( $category, $blocked_categories, true ) ) {
				return $category;
			}
		}

		return false;
	}

	/**
	 * Check if a tag should be whitelisted (never blocked).
	 *
	 * @param string $attrs   Tag attributes.
	 * @param string $content Inline content (for scripts).
	 * @return bool
	 */
	private function is_whitelisted( $attrs, $content ) {
		// Built-in escape hatch: class="faz-skip" bypasses blocking without any settings entry.
		// Token match (not substring) so "faz-skipper" or "my-faz-skip-btn" do not bypass.
		$class_attr = $this->extract_tag_attr( $attrs, 'class' );
		if ( '' !== $class_attr ) {
			$class_tokens = preg_split( '/\s+/', strtolower( $class_attr ), -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $class_tokens ) && in_array( 'faz-skip', $class_tokens, true ) ) {
				return true;
			}
		}

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

		// Domain+path patterns ("googleapis.com/maps/api/", "hcaptcha.com/")
		// — i.e. the pre-slash segment looks like a hostname (contains a dot)
		// — are matched HOST-ANCHORED so that "googleapis.com/" cannot be
		// spoofed by a look-alike host such as "evilgoogleapis.com/" or
		// "googleapis.com.attacker.net/". Filename patterns ("jquery.min.js"),
		// bare path fragments ("wp-includes/", "plugins/elementor/") and
		// handle/class tokens fall through to the looser matching below — they
		// are first-party infrastructure, not third-party domains.
		$slash_pos = strpos( $pattern, '/' );
		if ( false !== $slash_pos ) {
			$pattern_host = substr( $pattern, 0, $slash_pos );
			if ( false !== strpos( $pattern_host, '.' ) ) {
				return $this->matches_domain_pattern(
					$value,
					$pattern_host,
					ltrim( substr( $pattern, $slash_pos + 1 ), '/' )
				);
			}
		}

		$is_url_pattern = false !== $slash_pos || false !== strpos( $pattern, '.' );
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

		// Class and ID values: split by whitespace and require an exact token
		// match so that pattern "analytics" does not match "faz-analytics-helper".
		// Also allow hyphen-prefix matching so "faz-cookie-manager" matches
		// WordPress-generated IDs like "faz-cookie-manager-js-extra" (the
		// wp_localize_script suffix added automatically by WP core).
		$tokens        = preg_split( '/\s+/', strtolower( $value ), -1, PREG_SPLIT_NO_EMPTY );
		$lower_pattern = strtolower( $pattern );
		$is_prefix     = preg_match( '/[-_]$/', $lower_pattern );

		foreach ( $tokens as $token ) {
			if ( $token === $lower_pattern ) {
				return true;
			}
			if ( $is_prefix ) {
				if ( 0 === strpos( $token, $lower_pattern ) ) {
					return true;
				}
			} else {
				if ( 0 === strpos( $token, $lower_pattern . '-' ) || 0 === strpos( $token, $lower_pattern . '_' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Host-anchored match for a domain (+ optional path) whitelist pattern.
	 *
	 * The value's host must equal the pattern host or be a sub-domain of it
	 * on a label boundary — so "googleapis.com" matches "maps.googleapis.com"
	 * (legitimate sub-domain) but NOT "evilgoogleapis.com" or
	 * "googleapis.com.attacker.net". When the pattern carries a path, the
	 * request path must additionally begin with it (prefix-anchored).
	 *
	 * @param string $value        Attribute value (typically a src/href URL).
	 * @param string $pattern_host Host portion of the pattern (pre-slash).
	 * @param string $pattern_path Path portion of the pattern (post-slash, may be '').
	 * @return bool
	 */
	private function matches_domain_pattern( $value, $pattern_host, $pattern_path ) {
		$pattern_host = strtolower( trim( $pattern_host ) );
		if ( '' === $pattern_host ) {
			return false;
		}

		$parsed = wp_parse_url( $value );
		$host   = ( is_array( $parsed ) && isset( $parsed['host'] ) ) ? strtolower( $parsed['host'] ) : '';
		if ( '' === $host ) {
			// Relative URL (no host) can never satisfy a domain pattern.
			return false;
		}

		$host_ok = ( $host === $pattern_host )
			|| (
				strlen( $host ) > strlen( $pattern_host ) + 1
				&& substr( $host, - ( strlen( $pattern_host ) + 1 ) ) === '.' . $pattern_host
			);
		if ( ! $host_ok ) {
			return false;
		}

		$pattern_path = strtolower( $pattern_path );
		if ( '' === $pattern_path ) {
			return true;
		}

		$path = ( is_array( $parsed ) && isset( $parsed['path'] ) ) ? ltrim( strtolower( $parsed['path'] ), '/' ) : '';
		return 0 === strpos( $path, $pattern_path );
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
			'faz-fw',    // alt-asset mode handle (ad-blocker compatibility)
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
			// Forward: the provider pattern contains a gateway token.
			if ( false !== stripos( $pattern, $allowed_pattern ) ) {
				return true;
			}
			// Reverse: a gateway token contains the provider pattern. Guard with
			// a minimum needle length so a short, generic provider/custom pattern
			// (e.g. "co", "net") can't be a substring of a gateway URL and
			// silently exempt unrelated services from blocking/shredding.
			if ( strlen( $pattern ) >= 4 && false !== stripos( $allowed_pattern, $pattern ) ) {
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
		$categories = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items();
		$blocked = array();

		if ( $this->is_cache_compatibility_enabled() ) {
			foreach ( $categories as $cat_data ) {
				$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $cat_data );
				$slug     = $category->get_slug();
				if ( 'necessary' !== $slug ) {
					$blocked[] = $slug;
				}
			}
			$this->blocked_categories_cache = $blocked;
			return $blocked;
		}

		$consent = function_exists( 'faz_get_valid_consent_cookie' ) ? faz_get_valid_consent_cookie() : '';

		// CCPA/CPRA is an OPT-OUT regime: personal data may be sold/shared — and
		// the corresponding scripts may run — UNTIL the visitor exercises the
		// "Do Not Sell or Share" opt-out. So for a CCPA banner we do NOT block
		// anything server-side on first visit: the banner is a NOTICE, not a
		// gate. Once the visitor opts out, the consent cookie carries the
		// sale/sharing categories as ":no" and the consent-present branch below
		// blocks them on the next load (the opt-out flow reloads the page). This
		// mirrors the client-side default (script.js sets every category to
		// "yes" on a CCPA first visit) and removes the block→unblock flash that a
		// blanket pre-consent block would otherwise cause under an opt-out law.
		$is_optout_law = ( $this->banner && 'ccpa' === $this->banner->get_law() );

		// Runtime geo-routing (flag-gated, default off): when a ruleset is
		// resolved it is authoritative for the pre-consent default of each
		// category it names — denied-until-action → block now, granted → allow —
		// regardless of the banner's own law. This is what makes a Quebec (opt-in)
		// visitor actually have marketing/analytics blocked even when served a
		// banner the admin saved as opt-out. Categories the ruleset doesn't name
		// fall back to the law default below.
		$ruleset = $this->get_runtime_ruleset();

		// CPPA Reg. §7025: a browser Global Privacy Control signal (the
		// `Sec-GPC: 1` request header, mirror of navigator.globalPrivacyControl)
		// is a legally valid opt-out of the sale/sharing of personal information
		// and must be honoured immediately — including on the very first
		// response, before the JS opt-out (_fazApplyGpcOptOut) has had a chance
		// to write the consent cookie. So when no consent is recorded yet and the
		// request carries Sec-GPC:1, block the sell/share (ccpaDoNotSell)
		// categories server-side too, mirroring the client-side behaviour.
		// GPC applies when EITHER the banner is an opt-out (CCPA) law OR the
		// resolved runtime ruleset declares it honours GPC (signals.gpc_honored)
		// — the latter covers US-state rulesets whose model maps to a gdpr-style
		// banner (e.g. opt-out-with-sensitive-opt-in → California) yet still
		// require GPC sale/share opt-out. Pure opt-in jurisdictions that don't
		// honour GPC are unaffected (their non-necessary categories are already
		// blocked pre-consent).
		$gpc_header_on = isset( $_SERVER['HTTP_SEC_GPC'] )
			&& '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) );
		$ruleset_honors_gpc = ( null !== $ruleset && ! empty( $ruleset['signals']['gpc_honored'] ) );
		$gpc_optout = $gpc_header_on && ( $is_optout_law || $ruleset_honors_gpc );

		// Standalone "Do Not Sell or Share My Personal Information" opt-out: the
		// [faz_do_not_sell] form sets the `fazcookie-dnsmpi` cookie. That cookie
		// is a binding opt-out of the sale/sharing of personal information
		// (CCPA/CPRA §1798.120), so — like a GPC signal — it must actually stop
		// the sale/share scripts server-side, not merely record the request.
		// NOT gated on `$is_optout_law`: the cookie is only ever set by the
		// Do-Not-Sell form, which the admin enables for CCPA *and* for the
		// combined "GDPR + US" banner (saved with applicableLaw='gdpr' but with
		// the donotSell control on — see banner.js). Honouring the cookie
		// whenever it is present covers that mode too; under a pure opt-in law
		// the sell/share categories are already blocked, so this is a no-op
		// there.
		$dnsmpi_optout = isset( $_COOKIE['fazcookie-dnsmpi'] )
			&& '1' === sanitize_text_field( wp_unslash( $_COOKIE['fazcookie-dnsmpi'] ) );

		foreach ( $categories as $cat_data ) {
			$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $cat_data );
			$slug     = $category->get_slug();
			if ( 'necessary' === $slug ) {
				continue;
			}
			// A DNSMPI form opt-out blocks the sell/share categories outright,
			// overriding any consent-cookie value (mirrors the GPC override).
			if ( $dnsmpi_optout && ( $category->get_sell_personal_data() || $category->get_share_personal_data() ) ) {
				$blocked[] = $slug;
				continue;
			}
			if ( empty( $consent ) ) {
				$rs_default = ( null !== $ruleset ) ? Geo_Runtime::category_default( $ruleset, $slug ) : null;
				if ( false === $rs_default ) {
					// Ruleset denies this category until an explicit action.
					$blocked[] = $slug;
				} elseif ( $gpc_optout && ( $category->get_sell_personal_data() || $category->get_share_personal_data() ) ) {
					// A Global Privacy Control sale/share opt-out is a
					// legally-binding signal that OVERRIDES a ruleset 'granted'
					// default — a Sec-GPC:1 request must block sold/shared
					// categories even when the resolved ruleset grants them
					// (CPPA §7025). This check runs BEFORE the ruleset-granted
					// branch below so the grant can't bypass GPC. $gpc_optout
					// already embeds $is_optout_law, so opt-in visitors are
					// unaffected.
					$blocked[] = $slug;
				} elseif ( true === $rs_default ) {
					// Ruleset grants this category — do not block (overrides the
					// blanket opt-in pre-consent block).
					continue;
				} elseif ( ! $is_optout_law ) {
					// No ruleset opinion: opt-in law blocks every non-necessary
					// category until the visitor consents.
					$blocked[] = $slug;
				}
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
		if ( $this->is_cache_compatibility_enabled() ) {
			return $this->service_consent_cache;
		}
		// 1.18.3: per-service consent reintroduced — read the option again so the
		// cookie-shredder honours svc.* opt-outs. When the option is off this
		// returns an empty map and enforcement stays purely category-level.
		$settings    = $this->get_faz_settings();
		$per_service = ! empty( $settings['banner_control']['per_service_consent'] );
		if ( ! $per_service ) {
			return $this->service_consent_cache;
		}

		$consent = function_exists( 'faz_get_valid_consent_cookie' ) ? faz_get_valid_consent_cookie() : '';
		if ( empty( $consent ) ) {
			return $this->service_consent_cache;
		}

		// Honour an explicit svc.* choice for ANY provider the site can block,
		// not only scanner-detected ones — a placeholder lets a visitor accept or
		// reject a provider (e.g. YouTube) whose cookie was never observed on a
		// block-first site (#134/#146). Matching still only fires on a real embed.
		$active_service_ids = array_fill_keys(
			array_column( $this->get_enforceable_services(), 'id' ),
			true
		);

		// Extract valid svc.* entries for services currently exposed by this site.
		if ( preg_match_all( '/(?:^|,)svc\.([a-z0-9_-]+):(yes|no)(?=,|$)/', $consent, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( isset( $active_service_ids[ $match[1] ] ) ) {
					$this->service_consent_cache[ $match[1] ] = $match[2];
				}
			}
		}
		return $this->service_consent_cache;
	}

	/**
	 * Build a lookup map from Known_Providers patterns → service IDs.
	 *
	 * @return array [ 'google-analytics.com/analytics.js' => array( 'google-analytics' ), ... ]
	 */
	private function get_pattern_service_map() {
		if ( null !== $this->pattern_service_cache ) {
			return $this->pattern_service_cache;
		}
		$this->pattern_service_cache = array();
		// Build from the broad enforceable set so an embed can be matched even
		// when its provider is not in the scanner-detected list (#134/#146).
		foreach ( $this->get_enforceable_services() as $service ) {
			foreach ( $service['patterns'] as $pattern ) {
				if ( ! isset( $this->pattern_service_cache[ $pattern ] ) ) {
					$this->pattern_service_cache[ $pattern ] = array();
				}
				if ( ! in_array( $service['id'], $this->pattern_service_cache[ $pattern ], true ) ) {
					$this->pattern_service_cache[ $pattern ][] = $service['id'];
				}
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

		$pattern_map         = $this->get_pattern_service_map();
		$match_context       = $this->get_provider_match_context( $attrs, $content );
		$url                 = $match_context['url'];
		$inline              = $match_context['content'];
		$is_data_uri_payload = ! empty( $match_context['is_data_uri_payload'] );

		$matched_service_ids = array();
		foreach ( $pattern_map as $pattern => $service_ids ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			$is_url_pattern = false !== strpos( $pattern, '.' ) || false !== strpos( $pattern, '/' );
			$matched        = ( '' !== $url && $this->provider_url_pattern_matches( $url, $pattern ) );
			if ( ! $matched && ( ! $is_url_pattern || $is_data_uri_payload ) ) {
				$matched = $is_url_pattern
					? $this->provider_url_pattern_matches( $inline, $pattern )
					: false !== stripos( $inline, $pattern );
			}
			if ( $matched ) {
				foreach ( $service_ids as $service_id ) {
					$matched_service_ids[ $service_id ] = true;
				}
			}
		}

		$has_explicit_yes = false;
		foreach ( array_keys( $matched_service_ids ) as $service_id ) {
			if ( ! isset( $service_consent[ $service_id ] ) ) {
				continue;
			}
			if ( 'no' === $service_consent[ $service_id ] ) {
				return true;
			}
			$has_explicit_yes = true;
		}

		if ( $has_explicit_yes ) {
			return false;
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

		$normalized_content  = (string) $content;
		$is_data_uri_payload = false;
		if ( '' !== $url && 0 === stripos( $url, 'data:' ) ) {
			$decoded_payload = $this->decode_data_uri_payload( $url );
			if ( '' !== $decoded_payload ) {
				$url                 = '';
				$normalized_content  = trim( $decoded_payload . ' ' . $normalized_content );
				$is_data_uri_payload = true;
			} else {
				// Decode failed — clear the raw data URI so the encoded blob
				// is not matched against provider patterns in the haystack.
				$url = '';
			}
		}

		return array(
			'url'                 => $url,
			'content'             => $normalized_content,
			'haystack'            => trim( $url . ' ' . $normalized_content ),
			'is_data_uri_payload' => $is_data_uri_payload,
		);
	}

	/**
	 * Match a provider URL fragment with the same loose boundary checks used by JS.
	 *
	 * This is not a full URL parser: provider patterns can be host fragments,
	 * paths, plugin slugs, or decoded data-URI script payloads. The boundary check
	 * prevents accidental matches inside unrelated words/hosts such as
	 * notyoutube.com/embed while keeping normal host/path matches.
	 *
	 * @param string $target  URL-like haystack.
	 * @param string $pattern Provider URL fragment.
	 * @return bool
	 */
	private function provider_url_pattern_matches( $target, $pattern ) {
		$target  = (string) $target;
		$pattern = (string) $pattern;
		if ( '' === $target || '' === $pattern ) {
			return false;
		}

		$offset = 0;
		$length = strlen( $pattern );
		while ( false !== ( $index = stripos( $target, $pattern, $offset ) ) ) {
			if ( $this->has_provider_boundary( $target, $index, $length ) ) {
				return true;
			}
			$offset = $index + 1;
		}

		return false;
	}

	/**
	 * Mirror frontend/js/script.js::_fazHasProviderBoundary().
	 *
	 * @param string $target URL-like haystack.
	 * @param int    $index  Match start offset.
	 * @param int    $length Matched pattern length.
	 * @return bool
	 */
	private function has_provider_boundary( $target, $index, $length ) {
		if ( $index > 0 ) {
			$before = substr( $target, $index - 1, 1 );
			if ( ! preg_match( '/[\/.:\s"\'`=;,(<{\[]/', $before ) ) {
				return false;
			}
		}

		$after_pos = $index + $length;
		if ( $after_pos < strlen( $target ) ) {
			$after = substr( $target, $after_pos, 1 );
			if ( ! preg_match( '/[\/.:\?#\s"\'=;,&)<}\]]/', $after ) ) {
				return false;
			}
		}

		return true;
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
		$custom_patterns = array();
		foreach ( $custom_rules as $rule ) {
			$pattern  = isset( $rule['pattern'] ) ? $rule['pattern'] : '';
			$category = isset( $rule['category'] ) ? $rule['category'] : '';
			if ( ! empty( $pattern ) && ! empty( $category ) ) {
				$map[ $pattern ]               = $category;
				$custom_patterns[ $pattern ] = true;
			}
		}

		// 4. Developer filter (allows code-level custom rules). Snapshot the map
		// first so a rule the filter ADDS is treated as explicit (like an admin
		// custom rule) and is never silently exempted below.
		$pre_filter_map = $map;
		$map            = apply_filters( 'faz_blocking_rules', $map );

		// 5. Remove always-allowed gateway patterns (e.g. Stripe on checkout) —
		// but NEVER an explicit rule. Only the built-in provider patterns
		// (sections 1-2) are subject to the gateway exemption; an admin custom
		// rule (section 3) or a developer-filter-added rule (section 4) wins,
		// since the gateway match is substring-based and a generic pattern like
		// "payment" must not be silently exempted because it is a substring of
		// "stripe-payment".
		foreach ( array_keys( $map ) as $p ) {
			$is_explicit_rule = isset( $custom_patterns[ $p ] ) || ! array_key_exists( $p, $pre_filter_map );
			if ( $is_explicit_rule ) {
				continue;
			}
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
	 * Build the set of cookie patterns that must be exempt from shredding —
	 * the cookies of services the user has whitelisted (Settings → Script
	 * Blocking → whitelist_patterns) AND of always-allowed payment gateways
	 * (Stripe etc.), whose scripts run pre-consent so their cookies must
	 * persist too. The gateway exemption applies even with no user whitelist.
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

			// Always-allowed payment gateways (Stripe, etc.): their scripts run
			// pre-consent regardless of category, so the cookies they set must
			// also be exempt from the cookie shredder — otherwise the startup
			// cleanup deletes a live gateway cookie (e.g. __stripe_mid) the
			// moment it is written. This applies even with no user whitelist.
			foreach ( $service['patterns'] as $pattern ) {
				if ( $this->is_always_allowed_gateway_pattern( $pattern ) ) {
					$service_whitelisted = true;
					break;
				}
			}

			// Admin-configured whitelist patterns.
			if ( ! $service_whitelisted && ! empty( $user_whitelist ) ) {
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
		$url                 = $match_context['url'];
		$inline              = $match_context['content'];
		$is_data_uri_payload = ! empty( $match_context['is_data_uri_payload'] );

		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			// Patterns that look like URL fragments (contain '.' or '/') are designed
			// to match tracker domains in src/href attributes.  Applying them to the
			// inline text body causes false positives: config scripts that merely
			// reference a tracker domain in their data (e.g. Rank Math's rankMath.links
			// object contains youtu.be, facebook.com, etc.) would be incorrectly blocked.
			$is_url_pattern = false !== strpos( $pattern, '.' ) || false !== strpos( $pattern, '/' );
			if ( '' !== $url && $this->provider_url_pattern_matches( $url, $pattern ) ) {
				return $category;
			}
			if ( ! $is_url_pattern || $is_data_uri_payload ) {
				// Code-signature patterns (fbq(, gtag, _ga …) match inline content.
				// URL-fragment patterns may also match decoded data: script payloads
				// because that payload is the executable source, not page data.
				$matched_inline = $is_url_pattern
					? $this->provider_url_pattern_matches( $inline, $pattern )
					: false !== stripos( $inline, $pattern );
				if ( $matched_inline ) {
					return $category;
				}
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

	/**
	 * Assemble the per-category cookie groups exposed to the frontend banner.
	 *
	 * Skips hidden and `wordpress-internal` categories, then for each visible
	 * category computes `ccpaDoNotSell`, the per-category `defaultConsent`
	 * (jurisdiction-aware via Geo_Runtime::default_consent()) and
	 * `defaultFromRuleset` so the JS knows whether the default is
	 * ruleset-authoritative or must fall back to effective-law logic.
	 *
	 * @return array[] List of cookie-group descriptors for the banner store.
	 */
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
				// Subject to the combined "Do Not Sell or Share" opt-out when the
				// category is SOLD or SHARED (CPRA treats sharing for cross-context
				// behavioural advertising like a sale for opt-out purposes). The
				// always-exempt `necessary` category is never opt-out-able,
				// regardless of its stored flags (kept in sync with defaultConsent.ccpa).
				'ccpaDoNotSell'  => 'necessary' !== $category->get_slug() && ( $category->get_sell_personal_data() || $category->get_share_personal_data() ),
				'cookies'        => $cookies,
				'active'         => true,
				'defaultConsent' => Geo_Runtime::default_consent(
					$this->get_runtime_ruleset(),
					$category->get_slug(),
					(bool) $category->get_prior_consent(),
					(bool) $category->get_sell_personal_data(),
					(bool) $category->get_share_personal_data()
				),
				// True only when the runtime ruleset NAMES this category, so the
				// JS knows whether defaultConsent is jurisdiction-authoritative
				// (use it) or must fall back to the effective-law logic (matching
				// the server's get_blocked_categories split).
				'defaultFromRuleset' => Geo_Runtime::is_ruleset_default( $this->get_runtime_ruleset(), $category->get_slug() ),
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
		// Global Privacy Control: surfaced to the frontend so script.js can read
		// navigator.globalPrivacyControl and auto-apply an opt-out. Previously
		// the admin toggle was saved but never extracted here, so the setting
		// was inert (the signal was never honoured).
		$data['behaviours']['respectGPC']             = $behaviours['respectGPC']['status'] ?? false;
		$data['behaviours']['animations']             = $behaviours['animations'] ?? array();
		$data['config']['revisitConsent']             = $config['revisitConsent'] ?? array();
		$data['config']['preferenceCenter']['toggle'] = $config['preferenceCenter']['toggle']
			?? $config['preferenceCenter']['elements']['categories']['elements']['toggle']
			?? array();
		$data['config']['categoryPreview']['status']  = $config['categoryPreview']['status'] ?? false;
		$data['config']['categoryPreview']['toggle']  = $config['categoryPreview']['elements']['toggle'] ?? array();
		$data['config']['videoPlaceholder']['status'] = $config['videoPlaceholder']['status'] ?? true;
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
		// [faz_cookie_settings] "manage consent preferences" button (not the
		// floating .faz-btn-revisit widget). Rendered inside page content
		// (outside #faz-consent), so it can't inherit the banner's scoped
		// rules — replicate the primary "accept" button here. The COLOUR
		// properties consume the same --faz-accept-button-* custom properties
		// the admin sets in Banner > Colours, which script.js exposes on :root;
		// the --faz-btn-* sizing properties (font/line-height/padding/radius)
		// are static design constants shared with the template's .faz-btn rule
		// (never JS-set), so the gdpr.json defaults below are the effective
		// values and the button still looks right before JS runs.
		$css_settings_btn = '.faz-cookie-settings-btn{'
			. 'display:inline-block;'
			. 'font-family:inherit;'
			. 'font-size:var(--faz-btn-font-size,14px);'
			. 'line-height:var(--faz-btn-line-height,24px);'
			. 'font-weight:500;'
			. 'padding:var(--faz-btn-padding,8px 27px);'
			. 'border-radius:var(--faz-btn-border-radius,2px);'
			. 'border-width:var(--faz-accept-button-border-width,2px);'
			. 'border-style:var(--faz-accept-button-border-style,solid);'
			. 'border-color:var(--faz-accept-button-border-color,#1863dc);'
			. 'background:var(--faz-accept-button-background-color,#1863dc);'
			. 'color:var(--faz-accept-button-color,#ffffff);'
			. 'white-space:nowrap;text-align:center;text-transform:none;'
			. 'text-decoration:none;text-shadow:none;box-shadow:none;'
			. 'cursor:pointer;min-height:0;-webkit-appearance:none;appearance:none;'
			. 'transition:opacity .15s ease;'
			. '}'
			. '.faz-cookie-settings-btn:hover{opacity:.8;}'
			. '.faz-cookie-settings-btn:focus-visible{'
			. 'outline:2px solid var(--faz-accept-button-background-color,#1863dc);outline-offset:2px;'
			. '}';
		$css = $css_reset . $css . $css_fixes . $css_settings_btn;

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
		// Shortcode-specific handles that don't carry the plugin-name prefix.
		foreach ( array( 'faz-dsar-form', 'faz-dnsmpi-form' ) as $owned_handle ) {
			if ( $handle === $owned_handle || 0 === strpos( $handle, $owned_handle . '-' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect WP-generated inline script IDs owned by this plugin.
	 *
	 * Core appends these suffixes when printing `wp_localize_script()`,
	 * translations, and before/after inline payloads. The output-buffer
	 * fallback only sees the rendered `id`, so recover the registered handle
	 * before applying the own-handle guard.
	 *
	 * @param string $id Inline script tag ID.
	 * @return bool
	 */
	private function is_own_inline_script_id( $id ) {
		if ( ! is_string( $id ) || '' === $id ) {
			return false;
		}
		$handle = preg_replace( '/-js-(extra|translations|before|after)$/', '', $id );
		if ( $handle === $id || ! is_string( $handle ) ) {
			return false;
		}
		return $this->is_own_script_handle( $handle );
	}

	/**
	 * Detect inline scripts that carry only config/i18n payload — never tracker code.
	 *
	 * WordPress core emits two ID shapes that are guaranteed to be data-only:
	 *
	 *   - `<handle>-js-extra`        — `wp_localize_script()` output. Always a
	 *                                  `var NAME = { … };` assignment. No function
	 *                                  calls, no network I/O, no tracker pixels.
	 *   - `<handle>-js-translations` — `wp_set_script_translations()` output.
	 *                                  Always `wp.i18n.setLocaleData( JSON, domain );`
	 *                                  reaching only into WP's in-memory i18n store.
	 *
	 * Neither shape can host an analytics call. Yet `match_script_to_provider()`
	 * runs a `stripos( $content, $pattern )` substring match against the body of
	 * the inline script, which means any localised string or config key that
	 * incidentally contains a provider name (e.g. trx_addons emits the config key
	 * `animate_to_mc4wp_form_submitted`, RankMath localises `addtoany`, Yoast
	 * carries `gtag` in instruction strings) will trigger a false-positive block.
	 * The block rewrites the tag to `type="text/plain"`, the `var NAME = …`
	 * assignment never runs, and downstream code that reads the global crashes
	 * with `ReferenceError: NAME is not defined`.
	 *
	 * `-js-before` and `-js-after` are NOT exempted — `wp_add_inline_script()`
	 * with those positions accepts arbitrary executable code (a third-party
	 * plugin may legitimately emit a tracker call this way), so they still
	 * route through the regular provider matcher.
	 *
	 * The exemption applies to inline tags from any plugin, not just our own,
	 * because the convention is enforced by WordPress core: only `wp_localize_script`
	 * produces the `-js-extra` suffix, and only `wp_set_script_translations`
	 * produces the `-js-translations` suffix. A malicious plugin choosing one of
	 * those IDs to hide a tracker has the entire rest of the page to exfiltrate
	 * from — the assumption "the IDs WP core appends mark data, not behaviour"
	 * is the same one WordPress itself relies on.
	 *
	 * @param string $id Inline script tag ID.
	 * @return bool
	 */
	private function is_wp_localize_or_translations_inline_id( $id ) {
		if ( ! is_string( $id ) || '' === $id ) {
			return false;
		}
		return 1 === preg_match( '/-js-(extra|translations)$/', $id );
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
		if ( ! $this->is_own_inline_script_id( $id ) ) {
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
	 * WP Rocket inline-content exclude callback. Matches against substrings
	 * inside inline <script> bodies so DeferJS skips wrapping any tag that
	 * carries our bootstrap markers.
	 *
	 * @param array $excluded Existing inline-content exclusion patterns.
	 * @return array
	 */
	public function rocket_exclude_own_inline( $excluded ) {
		if ( ! is_array( $excluded ) ) {
			$excluded = array();
		}
		foreach ( array( '_fazConfig', '_fazCfg', '_fazGcm', '_fazTcfConfig' ) as $needle ) {
			if ( ! in_array( $needle, $excluded, true ) ) {
				$excluded[] = $needle;
			}
		}
		return $excluded;
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

		$tag_src = '' !== (string) $src ? (string) $src : $this->extract_tag_attr( $tag, 'src' );
		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			// Match against the handle and script src only. Matching the full tag
			// can false-positive on inline data snippets or unrelated attributes.
			if ( false !== stripos( $handle, $pattern ) || false !== stripos( $tag_src, $pattern ) ) {
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
		// Guard: never block FAZ's own localize/translation/config inline scripts.
		// wp_localize_script() emits a <script id="faz-cookie-manager-js-extra"> tag
		// whose content includes category slugs like "analytics" — which would otherwise
		// be matched by the provider pattern and blocked, preventing window._fazConfig
		// from being defined and crashing the entire banner.
		// The handle covers WP 6.3+; the ID-derived check covers WP 5.7-6.2
		// and the output-buffer fallback's rendered IDs.
		if ( $this->is_own_script_handle( $handle ) || $this->is_own_inline_script_id( $id ) ) {
			return $tag;
		}
		// wp_localize_script / wp_set_script_translations payloads carry only
		// config data or translation strings — never executable tracker code.
		// See is_wp_localize_or_translations_inline_id() for the full rationale.
		if ( $this->is_wp_localize_or_translations_inline_id( $id ) ) {
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
			// URL-fragment patterns (containing '.' or '/') are designed to match tracker
			// domains in external script src attributes.  Inline scripts have no src, so
			// applying URL patterns to their text body causes false positives when a config
			// script contains data with URLs mentioning known domains (e.g. Rank Math's
			// rankMath.links object contains youtu.be, facebook.com, etc.).
			// Handle and id are still checked against all patterns (a handle like
			// 'google-analytics.js' legitimately identifies an analytics script).
			$is_url_pattern = false !== strpos( $pattern, '.' ) || false !== strpos( $pattern, '/' );
			$matched        = (
				( '' !== $handle && false !== stripos( $handle, $pattern ) ) ||
				( '' !== $id && false !== stripos( $id, $pattern ) )
			);
			if ( ! $matched ) {
				if ( $is_url_pattern ) {
					// URL-fragment pattern with no src context — skip to avoid false positives.
					continue;
				}
				$matched = false !== stripos( $content, $pattern );
			}
			if ( ! $matched ) {
				continue;
			}

			$blocked      = $this->get_blocked_categories();
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

		// Per-service consent overrides the category fallback for matching
		// cookies. A denied service wins over an allowed service when providers
		// share a cookie pattern.
		$service_consent = $this->get_service_consent();
		// Per-cookie consent (ck.<svc>.<cookie>) is the most-specific override on
		// top of per-service: a cookie denied inside an otherwise-accepted service
		// is shredded server-side too. Mirrors the client resolver
		// _fazGetServiceCookieDecision (per-cookie > per-service > category).
		$shred_settings       = $this->get_faz_settings();
		$shred_banner_control = isset( $shred_settings['banner_control'] ) ? (array) $shred_settings['banner_control'] : array();
		$per_cookie_enabled   = ! empty( $shred_banner_control['per_cookie_consent'] ) && ! empty( $shred_banner_control['per_service_consent'] );
		// Parse ck.* from the SAME validated (non-stale) consent string as svc.*,
		// so a revision-bumped/expired cookie does not enforce old per-cookie picks.
		$valid_consent_str = ( $per_cookie_enabled && function_exists( 'faz_get_valid_consent_cookie' ) ) ? (string) faz_get_valid_consent_cookie() : '';
		$consent_cookie    = ( '' !== $valid_consent_str && function_exists( 'faz_parse_consent_cookie' ) ) ? faz_parse_consent_cookie( $valid_consent_str ) : array();
		$svc_cookie_decisions = array(); // pattern => array( 'yes'|'no' ).
		if ( ! empty( $service_consent ) || $per_cookie_enabled ) {
			// Use the broad enforceable set (same source as get_service_consent()
			// and get_pattern_service_map()), not the narrow scanner-detected
			// list. Otherwise a svc.<id>:no for an enforceable-but-undetected
			// provider is honoured for script-blocking but its already-set cookie
			// is never shredded — the three layers must agree. (#134/#146)
			// The `|| $per_cookie_enabled` guard keeps the loop running for
			// per-cookie (ck.*) denials even when no service-level svc.* exists. (#135)
			foreach ( $this->get_enforceable_services() as $service ) {
				$svc_id = isset( $service['id'] ) ? sanitize_key( $service['id'] ) : '';
				if ( '' === $svc_id || empty( $service['cookies'] ) ) {
					continue;
				}
				$svc_decision = isset( $service_consent[ $svc_id ] ) ? $service_consent[ $svc_id ] : '';
				if ( 'yes' !== $svc_decision && 'no' !== $svc_decision ) {
					$svc_decision = '';
				}
				foreach ( $service['cookies'] as $cookie_pattern ) {
					$cookie_pattern = sanitize_text_field( (string) $cookie_pattern );
					if ( '' === $cookie_pattern ) {
						continue;
					}
					$pattern_decision = $this->resolve_service_cookie_decision( $svc_id, $cookie_pattern, $svc_decision, $consent_cookie, $per_cookie_enabled );
					if ( 'yes' !== $pattern_decision && 'no' !== $pattern_decision ) {
						continue; // No explicit svc/ck decision -> category fallback handles it.
					}
					if ( ! isset( $svc_cookie_decisions[ $cookie_pattern ] ) ) {
						$svc_cookie_decisions[ $cookie_pattern ] = array();
					}
					$svc_cookie_decisions[ $cookie_pattern ][] = $pattern_decision;
				}
			}
		}

		// Nothing to evaluate: no blocked categories and no explicit services.
		if ( empty( $blocked_categories ) && empty( $svc_cookie_decisions ) ) {
			return;
		}

		$cookie_map = Known_Providers::get_cookie_map();
		if ( empty( $cookie_map ) && empty( $svc_cookie_decisions ) ) {
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
			$should_shred   = false;
			$service_allows = false;

			foreach ( $svc_cookie_decisions as $pattern => $decisions ) {
				if ( ! $this->cookie_name_matches( $name, $pattern ) ) {
					continue;
				}
				foreach ( $decisions as $decision ) {
					if ( 'no' === $decision ) {
						$should_shred = true;
						break 2;
					}
					if ( 'yes' === $decision ) {
						$service_allows = true;
					}
				}
			}

			// Category consent is only the fallback when no matching service has
			// an explicit decision.
			if ( ! $should_shred && ! $service_allows ) {
				foreach ( $cookie_map as $pattern => $category ) {
					if ( ! in_array( $category, $blocked_categories, true ) ) {
						continue;
					}
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
	 * Effective decision for one declared cookie of a service: the per-cookie
	 * override (ck.<svc>.<cookie>) when present, else the per-service decision,
	 * else '' (let category fallback decide). Mirrors the client-side
	 * _fazCookieEffectiveConsent / _fazGetServiceCookieDecision precedence.
	 *
	 * @param string $svc_id             Sanitised service id.
	 * @param string $cookie_pattern     Declared cookie name/pattern.
	 * @param string $svc_decision       'yes'|'no'|'' per-service decision.
	 * @param array  $consent_cookie     Parsed consent cookie (ck.* tokens).
	 * @param bool   $per_cookie_enabled Whether per-cookie consent is active.
	 * @return string 'yes'|'no'|''
	 */
	private function resolve_service_cookie_decision( $svc_id, $cookie_pattern, $svc_decision, $consent_cookie, $per_cookie_enabled ) {
		$decision = ( 'yes' === $svc_decision || 'no' === $svc_decision ) ? $svc_decision : '';
		if ( $per_cookie_enabled ) {
			$ck_key = 'ck.' . $svc_id . '.' . $this->ck_escape_cookie_name( $cookie_pattern );
			if ( isset( $consent_cookie[ $ck_key ] ) && in_array( $consent_cookie[ $ck_key ], array( 'yes', 'no' ), true ) ) {
				$decision = $consent_cookie[ $ck_key ];
			}
		}
		return $decision;
	}

	/**
	 * Escape a cookie name for the ck.<service>.<cookie> consent token, matching
	 * the client-side _fazCkKey() escaping so PHP reconstructs the exact key the
	 * browser wrote. Percent first, so we never double-escape the % in %3A/%2C.
	 *
	 * @param string $cookie_name Raw declared cookie name/pattern.
	 * @return string
	 */
	private function ck_escape_cookie_name( $cookie_name ) {
		$cookie_name = (string) $cookie_name;
		$cookie_name = str_replace( '%', '%25', $cookie_name );
		$cookie_name = str_replace( ':', '%3A', $cookie_name );
		$cookie_name = str_replace( ',', '%2C', $cookie_name );
		return $cookie_name;
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
