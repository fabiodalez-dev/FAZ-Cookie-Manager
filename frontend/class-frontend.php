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
use FazCookie\Admin\Modules\Banners\Includes\Banner;
use FazCookie\Admin\Modules\Settings\Includes\Settings;
use FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings;
use FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger;
use FazCookie\Admin\Modules\Pageviews\Api\Api as Pageviews_Api;
use FazCookie\Frontend\Modules\Banner_Rest\Banner_Rest;
use FazCookie\Includes\Geolocation;
use FazCookie\Includes\Ab_Test;
use FazCookie\Includes\Gvl;
use FazCookie\Includes\Known_Providers;
use FazCookie\Includes\Filesystem;
use FazCookie\Includes\Cookie_Table_Shortcode;
use FazCookie\Includes\Cookie_Policy_Shortcode;
use FazCookie\Includes\Do_Not_Sell_Shortcode;
use FazCookie\Includes\Cookie_Settings_Shortcode;
use FazCookie\Frontend\Includes\Placeholder_Builder;
use FazCookie\Frontend\Includes\Embed_Inventory;
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
	 * Instagram Feed's settings page slug, where its GDPR option lives.
	 *
	 * Pinned by tests/unit/test-smash-balloon-gdpr-php.php against the slug the
	 * plugin actually registers, so this cannot rot into a dead link silently.
	 *
	 * @since 1.26.0
	 * @var string
	 */
	const SMASH_BALLOON_SETTINGS_SLUG = 'sbi-settings';

	/**
	 * The attribute walk every tag pattern in this class uses, written the way
	 * the HTML tokenizer reads a start tag.
	 *
	 * A value is quoted only when a quote is the first character after `=`,
	 * and an unquoted value runs to the next whitespace or `>` whatever it
	 * contains. A stray quote or `=` where a name should start is accepted as
	 * the tokenizer accepts it (with a parse error), one character at a time,
	 * so a tag the engine will render is never one this class refuses to see.
	 * A `/` is consumed unless it is the last thing before `>`, which each
	 * pattern then captures or skips on its own, so self-closing shapes keep
	 * their existing capture semantics.
	 *
	 * `[^>]*` was the walk before this, on every pass, and a `>` inside a
	 * QUOTED value ended it early and hid every attribute after it: an
	 * `<img title="a>b" src="https://tracker/p.png">` was never parked, and
	 * for server-rendered markup the engine starts the fetch before any
	 * client layer can act. Every quantifier here is possessive and the
	 * alternatives cannot start with the same character, so there is nothing
	 * to backtrack into and a megabyte of unterminated value fails in one
	 * pass instead of tripping the PCRE limit — which `[^>]*` did, and a PCRE
	 * failure skips that pass for the whole page.
	 *
	 * Used with the `#` delimiter: it contains `/`.
	 */
	const ATTR_TOKEN = '/\G[\s\/"\'=>]*+([^\s"\'>\/=]++)(?:\s*+=\s*+("[^"]*+"|\'[^\']*+\'|[^\s>"\'][^\s>]*+))?/';

	/**
	 * One attribute token, anchored at the offset it is asked from: whatever
	 * cannot start a name (whitespace, a slash, a stray quote, `=`, `>`) is
	 * skipped, then a name, then optionally `=` and a value shaped as in
	 * ATTR_WALK. tokenize_tag_attrs() walks a string with it one token at a
	 * time; `\G` at the start makes the pattern anchored, so a failed match
	 * does not scan forward.
	 */
	const ATTR_WALK = '(?:\s++|/(?!\s*+>)|[^\s"\'>/=]++(?:\s*+=\s*+(?:"[^"]*+"|\'[^\']*+\'|[^\s>"\'][^\s>]*+))?|[^\s>/])*+';

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

	/** External consent scripts that must execute in order without blocking parsing. */
	private $deferred_script_handles = array();

	/** Inline bootstrap source, '' when unavailable. Null until first read. */
	private $bootstrap_js = null;

	/**
	 * Whether the inline bootstrap is being attached to a head handle on this
	 * request. Nothing in the chain may be deferred unless it is: see
	 * enqueue_deferred_script().
	 *
	 * @var bool
	 */
	private $bootstrap_emitted = false;

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
	 * Shortest Open Cookie Database wildcard PREFIX that may authorise a
	 * server-side deletion. The bundled dataset ships prefixes as short as two
	 * characters (`_s`, `ct`, `sr`, `tp`, `p_`), matched by a bare
	 * `0 === strpos()`, which classifies ordinary first-party names such as
	 * `_session` or `ct_checkout` into a category that is blocked pre-consent.
	 * Classification keeps those hits; enforcement refuses them.
	 */
	const ENFORCEABLE_WILDCARD_MIN_LENGTH = 6;

	/**
	 * _fazConfig keys moved out of the inline config into the content-hashed
	 * static file (window._fazStaticConfig). Each one is the same for every
	 * visitor of a page type, so inlining it re-sent it with every HTML
	 * response and kept it out of the browser cache. `_serviceCatalogue` is
	 * the bulk of the config once per-service consent is on (~43 KB for ~330
	 * services against ~12 KB for everything else). #310
	 */
	const STATIC_CONFIG_KEYS = array( '_providersToBlock', '_cookieCategoryMap', '_serviceCatalogue' );

	/**
	 * Per-request cache for blocked categories and provider map.
	 *
	 * @var array|null
	 */
	private $blocked_categories_cache = null;
	private $provider_map_cache       = null;
	private $whitelist_cache          = null;

	/**
	 * Request cache for get_consentable_category_slugs().
	 *
	 * @var string[]|null
	 */
	private $consentable_categories_cache = null;
	private $service_consent_cache    = null;
	/**
	 * The visitor's OWN blocked set and per-service map, kept apart from the
	 * render-time ones above. They differ only on a 'fallback' render (see
	 * decide_visitor_state_cache_veto()), where the render blocks the shareable
	 * union but the destructive cookie layers must still read what this visitor
	 * actually granted. Null until get_blocked_categories() /
	 * get_service_consent() fill them.
	 *
	 * @var array|null
	 */
	private $enforcement_blocked_categories_cache = null;
	private $enforcement_service_consent_cache    = null;
	/**
	 * Memo of the `faz_output_buffer_fail_closed` filter, read the first
	 * time a markup pass fails (see run_markup_pass()). Null until then.
	 *
	 * @var bool|null
	 */
	private $markup_fail_closed_cache = null;
	/**
	 * Sale/sharing categories a first visit under an opt-out law allows but
	 * every render parks; see sale_share_parked_by_default(). Null until
	 * computed.
	 *
	 * @var array|null
	 */
	private $sale_share_parked_cache = null;
	/**
	 * Per-request cache for get_consent_cookie_scope(). `false` = not computed
	 * yet; null is a legitimate value (no scope check on this request).
	 *
	 * @var array|null|false
	 */
	private $consent_cookie_scope_cache = false;
	/**
	 * Per-request memo for is_vendor_record_stale(), keyed by cookie.
	 *
	 * @var array<string,bool>
	 */
	private $vendor_record_stale_cache = array();
	/**
	 * Categories blocked by a binding privacy signal on THIS request.
	 *
	 * Populated by get_blocked_categories(); read by get_service_consent(),
	 * which must not let a stale per-service grant reopen a category GPC or a
	 * Do Not Sell request closed. Keyed by slug.
	 *
	 * @var array<string,string>|null 'gpc'|'dnsmpi' per slug.
	 */
	private $signal_blocked_categories = null;
	private $pattern_service_cache    = null;
	private $per_service_cache        = null;
	private $enforceable_cache        = null;
	private $settings_option_cache    = null;
	private $always_allowed_cache     = null;
	private $service_cookie_decisions_cache = null;
	/** @var array|null Visitor-view twin of the above, filled only on a 'fallback' render. */
	private $visitor_service_cookie_decisions_cache = null;
	private $whitelisted_cookie_patterns_cache = null;
	private $catalog_cookie_categories_cache = null;
	/**
	 * Per-cookie-name memo for the Open Cookie Database tier, mirrored into the
	 * faz_server_cookie_definition_map transient. Misses are memoized too — an
	 * unknown name is the common case and re-answering it costs a full rebuild.
	 *
	 * @var array<string,string>|null
	 */
	private $definition_category_memo = null;
	/** @var bool Whether the memo gained an entry that is not persisted yet. */
	private $definition_category_memo_dirty = false;
	private $cookie_allowed_cache = array();
	private $server_cookie_guard_started = false;
	/** @var bool Whether this request printed the banner stylesheet as a non-blocking media="print" link. */
	private $banner_css_non_blocking = false;
	/**
	 * Precomputed provider-pattern metadata (lowercased pattern, URL-vs-code
	 * classification) for the output-buffer matching hot loop.
	 */
	private $provider_match_meta_cache = null;
	/** Same precomputation for the per-service pattern → service-ids map. */
	private $service_match_meta_cache = null;
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
	 * Whether print_gcm_default_inline() emitted the synchronous denied
	 * `consent default` in THIS response. The Advanced Consent Mode gtag
	 * exemption (process_script_tag / inline_script_block_category) is only
	 * sound while that default precedes the exempted stack, so the exemption
	 * reads this flag: a response without wp_head() output, an AMP response or
	 * any other path that never printed the default keeps the Google stack
	 * hard-blocked, exactly like Basic mode.
	 *
	 * @var bool
	 */
	private $gcm_default_emitted = false;

	/**
	 * Per-request memo for is_bot_variant_render() (null = not yet computed).
	 *
	 * @var bool|null
	 */
	private $bot_variant_render = null;

	/**
	 * Whether the page-cache veto for a User-Agent-variant render is in force.
	 *
	 * @var bool
	 */
	private $bot_variance_cache_vetoed = false;

	/**
	 * Verdict of decide_visitor_state_cache_veto() for this request.
	 *
	 *   null         not decided (no send_headers pass for a front-end page:
	 *                REST, CLI, unit harnesses) — the visitor's state is
	 *                honoured as before;
	 *   'invariant'  Cache Compatibility / the strict shell already make the
	 *                render visitor-invariant;
	 *   'baseline'   the visitor's state blocks exactly what a first visit
	 *                blocks, so the render is the shareable one anyway;
	 *   'vetoed'     the render follows the visitor's state and the response
	 *                is kept out of every shared cache;
	 *   'fallback'   the veto could not be installed (or was filtered off), so
	 *                the render is the shareable one: nothing granted beyond a
	 *                first visit, every denial still honoured.
	 *
	 * @var string|null
	 */
	private $visitor_state_veto = null;

	/**
	 * Whether maybe_apply_ab_test() vetoed the page cache for its split.
	 *
	 * @var bool
	 */
	private $ab_test_cache_vetoed = false;

	/**
	 * Per-request memo for is_geo_bootstrap_cache_active() (null = not yet
	 * computed). The readiness check touches banner/ruleset storage, so every
	 * consumer in a request must share one verdict.
	 *
	 * @var bool|null
	 */
	private $geo_bootstrap_active_cache = null;
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
		// Opt-in footer legal-links nav. Self-gating: it renders nothing at all
		// unless legal_links.enabled is on and at least one published page is
		// selected, so instantiating it unconditionally costs one option read.
		new \FazCookie\Frontend\Modules\Legal_Links\Legal_Links();
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
		// Cookie shredding needs the resolved main query so WooCommerce checkout /
		// cart conditionals and page exclusions are reliable. template_redirect is
		// still before any template output, so Set-Cookie deletion headers remain
		// safe while gateway cookies are protected only in the request where their
		// scripts are actually allowed.
		add_action( 'template_redirect', array( $this, 'shred_non_consented_cookies' ), 1 );
		add_action( 'template_redirect', array( $this, 'start_output_buffer' ) );
		// Register after the output-buffer flusher. Its shutdown callback can
		// create placeholders, and the inventory must persist the final set —
		// including an empty set when embeds were removed from this page.
		add_action( 'template_redirect', array( Embed_Inventory::class, 'begin' ) );
		// Optional server-cookie guard. Opening a dedicated buffer on init keeps
		// headers pending until page, AJAX, REST and redirect callbacks have had a
		// chance to emit Set-Cookie. The output callback then filters the final
		// header set immediately before PHP sends it.
		// WP Consent API: declare the consent TYPE, not merely that a CMP exists.
		// Without this filter the API treats the site as having no consent
		// management at all and wp_has_consent() returns TRUE for every category
		// — so any Consent-API-aware plugin gating server-rendered output on
		// wp_has_consent('marketing') printed its pixel on every request, before
		// any consent, on every FAZ install. Registering the plugin (below in
		// faz-cookie-manager.php) was never enough on its own.
		add_filter( 'wp_get_consent_type', array( $this, 'filter_wp_consent_type' ) );
		add_action( 'init', array( $this, 'start_server_cookie_guard' ), 20 );
		add_filter( 'rest_pre_echo_response', array( $this, 'filter_server_cookies_before_rest_echo' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_redirect', array( $this, 'filter_server_cookies_before_redirect' ), PHP_INT_MAX, 2 );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_loader_tag' ), 10, 3 );
		add_filter( 'script_loader_tag', array( $this, 'defer_runtime_script_tag' ), 25, 2 );
		add_filter( 'script_loader_tag', array( $this, 'prioritize_runtime_script_tag' ), 26, 2 );
		// After every enqueue hook has run, before anything is printed.
		add_action( 'wp_print_scripts', array( $this, 'warn_on_undeferred_handles' ), PHP_INT_MAX );
		add_filter( 'style_loader_tag', array( $this, 'filter_style_loader_tag' ), 10, 4 );
		add_filter( 'style_loader_tag', array( $this, 'mark_banner_stylesheet' ), 15, 2 );

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

			// LiteSpeed "Remove Query Strings" (optm-qs_rm) strips `?ver=` from
			// every internal .js/.css URL, so after an update the browser and
			// the CDN keep the previous runtime under the unchanged URL. Its own
			// escape is `_litespeed_rm_qs=0` in the URL. Priority 10 runs before
			// LiteSpeed's own script_loader_src / style_loader_src (999).
			add_filter( 'script_loader_src', array( $this, 'keep_own_asset_version_query' ), 10, 2 );
			add_filter( 'style_loader_src', array( $this, 'keep_own_asset_version_query' ), 10, 2 );

			// On a handle this plugin defers itself, `data-no-defer="1"` and the
			// LiteSpeed defer-exclusion below read like a contradiction and are
			// not one: they say "do not APPLY YOUR deferral to this", which is
			// exactly what protects the ordering this plugin chose. An optimizer
			// that re-deferred or delayed these handles would either duplicate
			// the attribute or, with "delay until interaction", hold the consent
			// runtime until the first click — by which point the scripts a click
			// releases have already fired. The one thing none of these hints must
			// do is remove a defer that is already there, and none of them does.
			//
			// LiteSpeed Cache pattern-based exclude lists
			// (belt-and-suspenders in case the tag attribute ever gets
			// stripped by a future LS release).
			add_filter( 'litespeed_optm_js_defer_exc', array( $this, 'litespeed_exclude_own_scripts' ) );
			add_filter( 'litespeed_optm_js_delay_inc', array( $this, 'litespeed_exclude_own_scripts_from_include' ) );
			add_filter( 'litespeed_optimize_js_excludes', array( $this, 'litespeed_exclude_own_scripts' ) );
			add_filter( 'litespeed_optimize_css_excludes', array( $this, 'litespeed_exclude_own_scripts' ) );
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
			// Protect the normal content-hashed banner stylesheet here; the
			// unwritable-filesystem inline fallback carries a matching exclusion
			// marker in its CSS content below. Together they keep CSS combine/minify
			// from producing an unstyled first paint and a full-screen layout shift.
			add_filter( 'style_loader_tag', array( $this, 'tag_own_styles_nooptimize' ), 20, 2 );

			// WP Rocket exclude helpers — same intent.
			add_filter( 'rocket_exclude_defer_js', array( $this, 'rocket_exclude_own_scripts' ) );
			add_filter( 'rocket_delay_js_exclusions', array( $this, 'rocket_exclude_own_scripts' ) );
			add_filter( 'rocket_delay_js_exclusions', array( $this, 'rocket_exclude_own_inline' ) );
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

			// WP-Optimize "Minify" reads none of the data-* hints either: on 4.x it
			// merged script.min.js, the config file, a11y AND the inline bootstrap
			// into a deferred wpo-minify-header bundle, so the bootstrap ran after
			// the parse it is meant to guard. Its exclusion list is filterable by
			// URL substring (WP_Optimize_Minify_Functions::get_default_ignore()).
			add_filter( 'wp-optimize-minify-default-exclusions', array( $this, 'wpo_exclude_own_scripts' ) );

			// SiteGround Optimizer (Speed Optimizer). Its minify, async and
			// combine passes exclude by script handle; "Remove Query Strings"
			// by URL substring, and it stripped the `?ver=` from script.min.js on
			// 7.x, which is the runtime-skew hazard the LiteSpeed `_litespeed_rm_qs`
			// escape guards against.
			add_filter( 'sgo_js_minify_exclude', array( $this, 'sgo_exclude_own_handles' ) );
			add_filter( 'sgo_js_async_exclude', array( $this, 'sgo_exclude_own_handles' ) );
			add_filter( 'sgo_javascript_combine_exclude_ids', array( $this, 'sgo_exclude_own_handles' ) );
			add_filter( 'sgo_css_minify_exclude', array( $this, 'sgo_exclude_own_handles' ) );
			add_filter( 'sgo_css_combine_exclude', array( $this, 'sgo_exclude_own_handles' ) );
			add_filter( 'sgo_rqs_exclude', array( $this, 'sgo_exclude_own_urls' ) );
			add_filter( 'sgo_javascript_combine_excluded_internal_paths', array( $this, 'sgo_exclude_own_urls' ) );
			add_filter( 'sgo_javascript_combine_excluded_inline_content', array( $this, 'rocket_exclude_own_inline' ) );

			// Hummingbird Asset Optimization asks per handle whether a resource
			// may be minified or combined (core/modules/minify/class-minify-group.php).
			add_filter( 'wphb_minify_resource', array( $this, 'hummingbird_keep_own_resource' ), 10, 2 );
			add_filter( 'wphb_combine_resource', array( $this, 'hummingbird_keep_own_resource' ), 10, 2 );

			// Jetpack Boost. "Defer non-essential JavaScript" moves every script
			// tag to the end of the document unless it carries its ignore
			// attribute; the filter receives the matched tags and returns the
			// ones still to move. "Concatenate JS" asks `js_do_concat` per
			// handle (and already skips deferred handles on its own).
			add_filter( 'jetpack_boost_render_blocking_js_exclude_scripts', array( $this, 'jetpack_boost_keep_own_scripts' ) );
			add_filter( 'js_do_concat', array( $this, 'jetpack_boost_do_concat' ), 10, 2 );
			add_filter( 'css_do_concat', array( $this, 'jetpack_boost_do_concat' ), 10, 2 );

			// W3 Total Cache "Minify (auto)" reads none of the data-* hints: on
			// 2.8.x it folded script.min.js, the content-hashed config file and
			// a11y into its own deferred bundle (wp-content/cache/minify/*.js).
			// Its per-tag seam is this pair of filters (Minify_AutoJs.php /
			// Minify_AutoCss.php, `$do_tag_minification`); returning false
			// leaves the tag exactly as it was printed.
			add_filter( 'w3tc_minify_js_do_tag_minification', array( $this, 'w3tc_keep_own_tag' ), 10, 3 );
			add_filter( 'w3tc_minify_css_do_tag_minification', array( $this, 'w3tc_keep_own_tag' ), 10, 3 );

			// Cross-version FlyingPress compatibility — DO NOT remove any of these
			// as "dead code", each covers a different FlyingPress line (verified
			// against FlyingPress 5.5.0 source):
			//   * flying_press_exclude_from_delay:js / :defer:js — the exclusion
			//     mechanism on FlyingPress 4.16–4.x. These hooks were REMOVED in
			//     FlyingPress 5, where JavaScript::delay_scripts() reads the
			//     exclusion list straight from Config::$config['js_delay_excludes']
			//     with no filter, so on 5.x these two add_filter() calls are inert
			//     but must stay for 4.x installs.
			//   * flying_press_exclude_from_minify:js / :css — still present in 5.x
			//     and 4.x (CSS::minify() reads the :css one).
			//   * flying_press_apply_runtime_delay_exclusions() (below) — the 5.x
			//     path: it injects the same keywords into the in-memory
			//     Config::$config lists FlyingPress 5 reads without a filter —
			//     'js_delay_excludes' (Delay all JavaScript),
			//     'js_delay_third_party_excludes' (Delay third-party scripts) and
			//     'css_rucss_include_selectors' (Remove unused CSS) — on front-end
			//     renders only. pre_update_option_FLYING_PRESS_CONFIG strips them
			//     again if FlyingPress re-saves its whole static config later in
			//     the same request, so the saved option never carries them.
			//   * flying_press_optimization:after — the safety net: whatever pass
			//     still rewrote a FAZ tag ("Delay selected scripts" has no exclusion
			//     list at all, and a FlyingPress release may add new passes), the
			//     tag is restored to load immediately before FlyingPress caches it.
			// FlyingPress older than 4.16 (no delay/defer filter) falls back to the
			// manual "Delay JavaScript" keyword exclusion documented in readme.txt.
			// Registered in the always-run frontend composition root — the admin
			// cache adapter is not loaded on ordinary public requests.
			add_filter( 'flying_press_exclude_from_delay:js', array( $this, 'flying_press_exclude_own_scripts' ) );
			add_filter( 'flying_press_exclude_from_defer:js', array( $this, 'flying_press_exclude_own_scripts' ) );
			add_filter( 'flying_press_exclude_from_minify:js', array( $this, 'flying_press_exclude_own_scripts' ) );
			add_filter( 'flying_press_exclude_from_minify:css', array( $this, 'flying_press_exclude_own_scripts' ) );
			add_filter( 'flying_press_optimization:after', array( $this, 'flying_press_restore_own_assets' ), 1000 );
			add_filter( 'pre_update_option_FLYING_PRESS_CONFIG', array( $this, 'flying_press_strip_runtime_keywords' ), PHP_INT_MAX, 2 );
			add_action( 'plugins_loaded', array( $this, 'flying_press_apply_runtime_delay_exclusions' ), PHP_INT_MAX, 0 );
			// Re-apply on `wp` too (front-end page loads, after `init`): if
			// FlyingPress populates its static config later than plugins_loaded,
			// the early pass finds no key and no-ops; `wp` fires before FlyingPress
			// optimises the output buffer, so this catch-all lands the exclusion on
			// late-config load orders. Idempotent ($updated !== $config guard) and
			// front-end-gated, so the extra call is a no-op when nothing changed.
			add_action( 'wp', array( $this, 'flying_press_apply_runtime_delay_exclusions' ), PHP_INT_MAX, 0 );
			add_action( 'flying_press_update_config:after', array( $this, 'flying_press_apply_runtime_delay_exclusions' ), PHP_INT_MAX, 0 );
			// Covers non-standard/late bootstrap paths where plugins_loaded has
			// already fired before this object is constructed.
			if ( did_action( 'plugins_loaded' ) ) {
				$this->flying_press_apply_runtime_delay_exclusions();
			}
		}

		// WordPress passes the attribute map and raw inline data (WP 5.7+).
		add_filter( 'wp_inline_script_attributes', array( $this, 'filter_inline_script_attributes' ), 10, 2 );
		add_action( 'send_headers', array( $this, 'send_geo_cache_headers' ), 0 );
		add_action( 'send_headers', array( $this, 'send_vary_header' ) );

		// FlyingPress honours neither the DONOTCACHEPAGE constant nor the
		// Cache-Control: no-store header that send_geo_cache_headers() emits
		// for country-dependent output — it decides cacheability solely via
		// its documented flying_press_is_cacheable filter
		// (https://docs.flyingpress.com/en/articles/11406011-conditionally-control-page-caching).
		// Without this bridge a FlyingPress-cached page freezes ONE visitor's
		// country variant (wrong banner / gdprApplies for everyone else) —
		// part of the FlyingPress reports in issue #125. Registered
		// unconditionally: with FlyingPress absent the filter never runs.
		add_filter( 'flying_press_is_cacheable', array( $this, 'flying_press_is_cacheable' ) );

		// Super Page Cache decides on its own bypass list and then REPLACES
		// Cache-Control with its Cloudflare value (`s-maxage=31536000`): on
		// 5.3.x the crawler-only render went out as cacheable for a year at
		// the edge. Its `swcfpc_cache_bypass` filter is asked right before
		// that, after every FAZ veto has defined DONOTCACHEPAGE. A page-cache
		// veto, not an optimiser exclusion, so it is registered outside the
		// `faz_auto_exclude_cache_plugins` block, like the FlyingPress one.
		add_filter( 'swcfpc_cache_bypass', array( $this, 'super_page_cache_bypass' ) );

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
	 * Split the store data into the static payload (STATIC_CONFIG_KEYS, the
	 * same for every visitor of a page type, written to the content-hashed
	 * config-*.js file) and the inline remainder (per-visitor and per-request
	 * keys, printed into the page). Keys absent from the store are skipped;
	 * nothing else is transformed. The caller uses 'inline' only once the
	 * static file has actually been provided, otherwise the full store stays
	 * inline. #310
	 *
	 * @param array $store_data Full _fazConfig store.
	 * @return array{static: array, inline: array}
	 */
	public static function split_static_config( array $store_data ) {
		$static_config = array();
		foreach ( self::STATIC_CONFIG_KEYS as $static_key ) {
			if ( isset( $store_data[ $static_key ] ) ) {
				$static_config[ $static_key ] = $store_data[ $static_key ];
				unset( $store_data[ $static_key ] );
			}
		}
		return array(
			'static' => $static_config,
			'inline' => $store_data,
		);
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
		// This gate must stay ABOVE the bot gate below. Both only return, so
		// the order was once immaterial; it stopped being so when the bot gate
		// gained a side effect. On a page where the banner is off by settings
		// or excluded by path, the render does not vary by User-Agent at all,
		// so vetoing the page cache there would spend the cache for nothing —
		// the same over-reach case 8b guards against for hide_from_bots: false.
		// send_geo_cache_headers() orders these two the same way.
		if ( $this->is_banner_disabled_by_settings() ) {
			return;
		}
		// Skip banner for search engine bots (configurable via Settings).
		//
		// bot_suppression_applies() answers both halves of that question: the
		// setting, and whether this response can be kept out of the shared page
		// cache. A banner-less copy that a cache is free to store is handed to
		// every later human visitor — banner permanently hidden, trackers
		// unblocked, nothing on the page to reveal it. Cache Compatibility Mode
		// and the cached jurisdiction shell need an invariant render and so
		// never suppress (#158, gooloo.de); every other full-page cache gets a
		// no-store veto instead (kalabron.com, LiteSpeed).
		if ( $this->bot_suppression_applies() ) {
			return;
		}

		// Geo-targeting: skip banner for visitors outside target regions.
		// Same invariance requirement as the bot-skip above — under Cache
		// Compatibility Mode geo gating is resolved client-side from the
		// invariant config (is_country_dependent_output() is false), so the
		// enqueue must never short-circuit per visitor here. Mirrors the
		// cache-compat gate already applied to geo at load_banner().
		if ( ! $this->is_cache_compatibility_enabled() && ! $this->is_geo_bootstrap_cache_active() && $this->is_geo_banner_disabled() ) {
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

			/**
			 * Opt-in: load the main banner bundle in the footer instead of the
			 * head. External bundles use defer in either position; the inline
			 * bootstrap protects dynamic resources until they execute. Default
			 * false keeps the bootstrap in the head, before page scripts run.
			 * Server-side blocking is unaffected.
			 *
			 * Ignored when Google Consent Mode or the IAB TCF CMP is active.
			 * Both declare this handle as a dependency and must themselves load
			 * in <head> — GCM has to emit `consent default` and the TCF stub has
			 * to define `__tcfapi` before any ad script runs, and moving them
			 * later is a compliance regression, not a tuning choice.
			 * WP_Scripts::set_group then pulls a dependency back into the head
			 * group, so honouring the filter while either is enabled produced no
			 * footer move at all: the option read as supported and silently did
			 * nothing on exactly the installs most likely to want it. Now the
			 * constraint is explicit, and `faz_main_script_effective_in_footer`
			 * reports what was actually applied.
			 *
			 * Resolved HERE, before the first deferred enqueue, because whether
			 * the bootstrap reaches the head is what decides if deferring any
			 * handle in this chain is safe — including the configuration file,
			 * which is enqueued before the main handle exists.
			 *
			 * @since 1.26.0
			 * @param bool $in_footer Default false (head).
			 */
			$in_footer = (bool) apply_filters( 'faz_main_script_in_footer', false );
			if ( $in_footer && ! $alt_asset ) {
				$gcm_active = is_object( $this->gcm_settings ) && true === $this->gcm_settings->is_gcm_enabled();
				$tcf_active = (bool) $this->settings->get( 'iab', 'enabled' )
					&& absint( $this->settings->get( 'iab', 'cmp_id' ) ) >= 2;
				if ( $gcm_active || $tcf_active ) {
					$in_footer = false;
				}
			}
			// Alternative-asset mode inlines every bundle and attaches no
			// bootstrap, so nothing in that mode is deferred either way.
			$this->bootstrap_emitted = ! $alt_asset && ! $in_footer && '' !== $this->deferred_bootstrap_js();

			// Offload the static bulk of _fazConfig (provider block patterns,
			// cookie-category map and, with per-service consent, the ~43 KB
			// service catalogue — all identical for every visitor of a given
			// page type) into a content-hashed, browser-cacheable .js
			// file instead of re-inlining it into every HTML response. The
			// file defines window._fazStaticConfig; script.js merges it back
			// into _fazConfig at the start of its deferred execution. Server-side
			// blocking is unaffected either way; if the static file cannot be provided the
			// full config stays inline exactly as before.
			$store_data  = $this->get_store_data();
			$static_deps = array();
			if ( ! $alt_asset && apply_filters( 'faz_external_static_assets', true ) ) {
				$split         = self::split_static_config( $store_data );
				$static_config = $split['static'];
				$static_json   = ! empty( $static_config ) ? wp_json_encode( $static_config ) : false;
				if ( false !== $static_json && '' !== $static_json ) {
					$static_url = $this->get_static_asset_url(
						'config-' . md5( $static_json ) . '.js',
						'window._fazStaticConfig=' . $static_json . ';'
					);
					if ( '' !== $static_url ) {
						$static_handle = $this->plugin_name . '-static-config';
						$this->enqueue_deferred_script( $static_handle, $static_url, array(), null, false );
						$static_deps[] = $static_handle;
						$store_data    = $split['inline'];
						$store_data['_staticConfigRequired'] = true;
					}
				}
			}

			if ( $alt_asset ) {
				$this->enqueue_inline_bundle( $script_handle, 'js/script' . $suffix . '.js', array(), false );
			} else {
				/**
				 * The position actually used, after the GCM/TCF constraint above.
				 *
				 * Read-only signal for diagnostics and for anyone who needs to
				 * know whether their `faz_main_script_in_footer` request survived.
				 *
				 * @since 1.26.0
				 * @param bool $in_footer Effective position (true = footer).
				 */
				do_action( 'faz_main_script_effective_in_footer', $in_footer );
				$this->enqueue_deferred_script( $script_handle, faz_frontend_url() . 'js/script' . $suffix . '.js', $static_deps, $this->version, $in_footer );
			}

			wp_localize_script( $script_handle, '_fazConfig', $store_data );

			if ( ! $alt_asset ) {
				// The full config is merged by script.js after its deferred static
				// dependency executes. Inline "before" code runs while parsing and
				// must never depend on a deferred external file being available.
				$bootstrap_js = $this->deferred_bootstrap_js();
				$attached     = '' !== $bootstrap_js
					&& false !== wp_add_inline_script( $script_handle, $bootstrap_js, 'before' );
				// The verdict above was a prediction; this is what happened.
				// wp_add_inline_script() returns false on an empty payload or an
				// unregistered handle, and a silent false is how a deferred
				// runtime ends up with nothing holding its window.
				$this->note_bootstrap_emission( $attached, ! $in_footer );
			}

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
			// the banner container (consent-bridge iframe, blocked embeds).
			// `_fazAddPlaceholder` inserts `.video-placeholder-{normal,youtube}` next
			// to blocked iframes (outside #faz-consent), so the scoped template.json
			// rules never reach them. Without this floor, placeholders for lazy-loaded
			// iframes (Bricks/Elementor/Divi Video) collapse to 0×0. The 4-ancestor
			// probe in _fazAddPlaceholder (issue #87) expected this CSS floor.
			// Neutralise WordPress core's block-border support rule
			// `html :where([style*="border-color"]){border-style:solid}`
			// (wp-includes, WP 5.9+). The banner container carries the
			// per-button colour CSS variables (…-border-color) in its inline
			// style, so that substring selector matches it and paints an
			// unintended medium/solid currentColor (black) border around the
			// whole banner — the intended border lives on .faz-consent-bar, not
			// the container. The id selector outranks WP's zero-specificity
			// :where(), and border-radius is unaffected.
			$css .= '#faz-consent{border-style:none}';
			// Inline age-confirmation row (GDPR Art. 8) injected by script.js
			// above the accept surfaces. Inherits the banner's text direction
			// (RTL support) from its container. The [hidden] attribute keeps
			// the validation message out of the flow until an un-affirmed
			// accept reveals it.
			$css .= '.faz-age-confirm{display:flex;flex-wrap:wrap;align-items:flex-start;gap:8px;margin:0 0 12px;font-size:13px;line-height:1.4}'
				. '.faz-age-confirm-cb{margin:2px 0 0;flex:0 0 auto}'
				. '.faz-age-confirm-label{cursor:pointer}'
				. '.faz-age-confirm-error{flex-basis:100%;margin:2px 0 0;color:#b00020;font-size:12px}';
			// The rules that style PAGE content rather than the banner. Kept in
			// the full stylesheet too, so every delivery path stays complete
			// (the [faz_cookie_settings] button rules are already in it, via
			// prepare_banner_styles()).
			$css .= self::page_utility_css();
			$css_handle = $this->plugin_name . '-css';
			// Serve the compiled banner CSS (~30 KB) as a real, content-hashed
			// stylesheet instead of inlining it into every page's <head> where
			// it can never be browser-cached. The hash filename is immutable,
			// so repeat views cost zero bytes. Falls back to the historical
			// inline injection when the uploads dir is not writable, and stays
			// inline in alternative-asset mode (those sites deliberately avoid
			// plugin-URL patterns ad blockers match on — an uploads URL still
			// contains the plugin slug).
			$external_css_url = '';
			if ( ! $alt_asset && apply_filters( 'faz_external_static_assets', true ) ) {
				$external_css_url = $this->get_static_asset_url( 'banner-' . md5( $css ) . '.css', $css );
			}
			if ( '' !== $external_css_url ) {
				/**
				 * Whether the external banner stylesheet loads without blocking
				 * the first paint.
				 *
				 * Nothing on the page needs it before script.js runs: the banner
				 * is built from a <script type="text/template"> by script.js, and
				 * the few rules that style server-rendered page content
				 * (.faz-hidden on blocked embeds, the placeholder floor, the
				 * [faz_cookie_settings] button) are printed inline below. So the
				 * link is printed as media="print" — fetched at low priority,
				 * never render-blocking — and script.js switches it to "all"
				 * and holds the banner reveal until it has loaded. No inline
				 * onload handler is used, so strict CSPs (script-src-attr) are
				 * unaffected. Return false to restore a render-blocking link.
				 *
				 * @since 1.34.4
				 * @param bool $non_blocking Default true.
				 */
				$this->banner_css_non_blocking = (bool) apply_filters( 'faz_banner_css_non_blocking', true );
				wp_enqueue_style( $css_handle, $external_css_url, array(), null, $this->banner_css_non_blocking ? 'print' : 'all' );
				if ( $this->banner_css_non_blocking ) {
					$page_handle = $css_handle . '-page';
					wp_register_style( $page_handle, false, array(), $this->version );
					wp_enqueue_style( $page_handle );
					// Same LiteSpeed exclusion marker as the inline fallback below.
					wp_add_inline_style( $page_handle, '/* faz-cookie-manager/assets/ */' . self::page_content_css() . self::pending_banner_css() );
				}
			} else {
				wp_register_style( $css_handle, false, array(), $this->version );
				wp_enqueue_style( $css_handle );
				// WP has no inline-style attribute filter, so its generated <style>
				// cannot receive data-no-optimize. LiteSpeed applies its official CSS
				// exclusion patterns to the complete tag before combining it; embed
				// the generated-assets marker in a harmless comment so the same
				// `faz-cookie-manager/assets/` exclusion protects this fallback too.
				wp_add_inline_style( $css_handle, '/* faz-cookie-manager/assets/ */' . $css );
			}

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
				// Basic mode also needs its baseline before page-level Google
				// snippets. This small bridge is self-contained and must stay
				// synchronous while the config/main/TCF downloads are deferred.
				//
				// "Self-contained" is the load-bearing claim, so it is spelled
				// out: gcm.js reads `window._fazConfig` (localized, printed in
				// the data bucket ahead of every inline below it), the inline
				// `var _fazGcm` above, and the `fazcookie-consent` cookie. It
				// touches nothing script.js creates — no `window.fazcookie`, no
				// merged static configuration — and its consent wiring is a
				// `fazcookie_consent_update` listener, which is ordering-free by
				// construction. Add a dependency on anything script.js defines
				// and this bundle has to move back behind the deferred runtime,
				// which puts the `consent default` baseline after the page's own
				// Google snippets: a compliance regression, not a tuning choice.
				//
				// The dependency on $script_handle is dropped in non-alt mode for
				// a second, separate reason: enqueue_inline_bundle() attaches its
				// code in the default 'after' position, and
				// WP_Scripts::filter_eligible_strategies() clears the strategy of
				// any handle with an 'after' inline AND of everything it depends
				// on. Re-adding array( $script_handle ) here would therefore
				// un-defer the entire chain silently. warn_on_undeferred_handles()
				// reports it under WP_DEBUG if it ever happens again.
				$this->enqueue_inline_bundle( $gcm_handle, 'js/gcm' . $gcm_suffix . '.js', $alt_asset ? array( $script_handle ) : array(), false );
			}

			// IAB TCF v2.3 CMP stub (when IAB is enabled AND a valid CMP ID is set
			// AND the Global Vendor List has been downloaded).
			// IAB Europe assigns CMP IDs; 0 and 1 are reserved/invalid. A TC string
			// built with CmpId < 2 is rejected by the IAB-compliant ad-tech supply
			// chain, so emitting one (the euconsent-v2 cookie) is a supply-chain
			// non-compliance. Refuse to activate the CMP — do not enqueue tcf-cmp.js
			// and do not emit _fazTcfConfig — until a registered CMP ID is configured.
			// The same refusal applies while no GVL is stored: without it the CMP
			// would encode VendorListVersion 0 and an empty DisclosedVendors
			// segment, which the IAB CMP validator rejects (Controls Catalogue
			// checks 6/7), and answer getVendorList with null. Settings::update()
			// downloads the GVL the moment TCF is switched on, so this window is
			// normally just a failed download away from closing itself.
			$gvl         = Gvl::get_instance();
			$iab_enabled = (bool) $this->settings->get( 'iab', 'enabled' )
				&& absint( $this->settings->get( 'iab', 'cmp_id' ) ) >= 2
				&& $gvl->has_data();
			if ( $iab_enabled ) {
				// Early command-queue stub so ad scripts can call __tcfapi before CMP loads.
				// Handles 'ping' directly so pre-CMP callers get a valid response.
				$tcf_stub = 'if(typeof window.__tcfapi!=="function"){var a=[];window.__tcfapi=function(cmd,ver,cb){if(cmd==="ping"){cb({gdprApplies:undefined,cmpLoaded:false,cmpStatus:"stub",displayStatus:"hidden",apiVersion:"2.3"},true);return;}a.push(arguments);};window.__tcfapi.a=a;}';
				wp_add_inline_script( $script_handle, $tcf_stub, 'before' );
				$tcf_suffix = $this->get_script_suffix( 'js/tcf-cmp' );
				$tcf_handle = $script_handle . '-tcf-cmp';
				if ( $alt_asset ) {
					$this->enqueue_inline_bundle( $tcf_handle, 'js/tcf-cmp' . $tcf_suffix . '.js', array( $script_handle ), false );
				} else {
					$this->enqueue_deferred_script( $tcf_handle, faz_frontend_url() . 'js/tcf-cmp' . $tcf_suffix . '.js', array( $script_handle ), $this->version, false );
				}

				$country_code = $this->resolve_tcf_publisher_cc();

				// ConsentLanguage: use current banner language (uppercase 2-char ISO 639-1).
				$consent_lang = strtoupper( substr( faz_current_language(), 0, 2 ) );
				if ( ! preg_match( '/^[A-Z]{2}$/', $consent_lang ) ) {
					$consent_lang = 'EN';
				}

				$gdpr_applies = $this->resolve_tcf_gdpr_applies();

				// Build TCF config with GVL data (the gate above guarantees it).
				$tcf_config = array(
					'publisherCC'         => $country_code,
					'consentLanguage'     => $consent_lang,
					'gdprApplies'         => $gdpr_applies,
					'cmpId'               => absint( $this->settings->get( 'iab', 'cmp_id' ) ),
					'purposeOneTreatment' => (bool) $this->settings->get( 'iab', 'purpose_one_treatment' ),
				);

				if ( $gvl->has_data() ) {
					$tcf_config['gvlVersion']       = $gvl->get_version();
					$tcf_config['purposes']         = $gvl->get_purposes( strtolower( $consent_lang ) );
					$tcf_config['specialPurposes']  = $gvl->get_special_purposes();
					$tcf_config['features']         = $gvl->get_features();
					$tcf_config['specialFeatures']  = $gvl->get_special_features();

					$selected_ids = (array) get_option( 'faz_gvl_selected_vendors', array() );
					if ( ! empty( $selected_ids ) ) {
						// Only vendors the GVL in use still carries and has not
						// marked deleted: get_vendors() filters both. The raw
						// option used to be emitted here, so a since-deleted
						// vendor stayed in DisclosedVendors and kept receiving
						// consent and LI bits.
						$selected_vendors = $gvl->get_vendors( $selected_ids );
						$tcf_config['selectedVendors'] = array_map( 'absint', array_keys( $selected_vendors ) );
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
				// Minted by the class that accepts it, like the consent token
				// above: two copies of the same bucket arithmetic in two files
				// is what let the consent token's window and the endpoint's
				// window drift apart in issue #292.
				$pv_token = Pageviews_Api::current_token();

				// The URL is baked into HTML that page caches replay to everyone
				// (LiteSpeed, WP Rocket and most CDNs drop gclid/utm_*/fbclid from
				// the cache key, so a page warmed by an ad click is served under
				// the clean URL). The raw REQUEST_URI therefore put one visitor's
				// click identifiers into every later visitor's pageview beacon.
				// faz_normalize_page_url() keeps the routing parameters that tell
				// pages apart and drops campaign and session ones — the same
				// spelling the consent log and the embed inventory use.
				$pv_url = home_url( add_query_arg( array(), false ) );
				if ( function_exists( 'faz_normalize_page_url' ) ) {
					$pv_url = faz_normalize_page_url( $pv_url );
				}

				wp_localize_script(
					$script_handle,
					'_fazPageviewConfig',
					array(
						'restUrl'   => rest_url( 'faz/v1/pageviews' ),
						'pageUrl'   => $pv_url,
						'pageTitle' => wp_get_document_title(),
						'token'     => $pv_token,
						// Where a fresh token comes from when the cached copy's
						// has aged out of the window. Same for every visitor, so
						// it is as cacheable as the rest of this object.
						'tokenUrl'  => add_query_arg( 'action', Consent_Logger::TOKEN_REFRESH_ACTION, admin_url( 'admin-ajax.php' ) ),
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
					"function fazSend(b){return fetch(_fazPageviewConfig.restUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)})}" .
					// One refresh per page, shared by every event: a stale cached
					// copy refuses the pageview and the banner_view together.
					"var fazRefresh=null;" .
					"function fazTrack(t){" .
						"var b={token:_fazPageviewConfig.token,page_url:_fazPageviewConfig.pageUrl,page_title:_fazPageviewConfig.pageTitle,event_type:t};" .
						// A 403 invalid_token means the HTML outlived the token
						// window (Consent_Logger::handle_token_refresh()): fetch
						// the current token once, resend once, never loop.
						"fazSend(b).then(function(r){" .
							"var u=_fazPageviewConfig.tokenUrl;" .
							"if(!u||!r||r.status!==403||typeof r.json!=='function')return;" .
							"return r.json().then(function(j){" .
								"if(!j||j.code!=='invalid_token')return;" .
								"fazRefresh=fazRefresh||fetch(u+(u.indexOf('?')<0?'?':'&')+'_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(x){return x.json()}).then(function(x){return x&&x.success&&x.data&&x.data.pageview?String(x.data.pageview):''},function(){return ''});" .
								"return fazRefresh.then(function(k){if(!k||k===b.token)return;_fazPageviewConfig.token=k;b.token=k;return fazSend(b)})" .
							"})" .
						"}).catch(function(){});" .
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
				// A time-bucketed HMAC proving the page was rendered by this
				// site. It is NOT a secret — it ships in the HTML — and the
				// endpoint pairs it with a same-origin check. Minted by the
				// logger that accepts it, so the two cannot drift apart.
				$hmac_token = Consent_Logger::current_token();

				wp_localize_script(
					$script_handle,
					'_fazConsentLog',
					array(
						'restUrl'        => rest_url( 'faz/v1/consent' ),
						'token'          => $hmac_token,
						'bannerSlug'     => $this->banner ? $this->banner->get_slug() : '',
						'policyRevision' => isset( $faz_settings['general']['consent_revision'] ) ? max( 1, absint( $faz_settings['general']['consent_revision'] ) ) : 1,
						// The page's identity as the placeholder inventory records
						// it — the same function, for the same render — so the
						// audit compares like with like. A browser-built URL kept
						// only origin + path and dropped the routing parameters
						// (?p=, ?lang=) the inventory keys on. It varies by page,
						// never by visitor, so a cached copy stays correct.
						'cookieNames'    => function_exists( 'faz_consent_cookie_names' ) ? faz_consent_cookie_names() : array(),
						'pageUrl'        => class_exists( Embed_Inventory::class ) ? Embed_Inventory::current_url() : '',
						// Where the beacon fetches a current token when the one
						// above is refused as stale — a page an edge cache kept
						// past the token window. See
						// Consent_Logger::handle_token_refresh().
						'tokenUrl'       => add_query_arg( 'action', Consent_Logger::TOKEN_REFRESH_ACTION, admin_url( 'admin-ajax.php' ) ),
					)
				);
					$inline_js = "document.addEventListener('fazcookie_consent_update',function(e){" .
						"var d=e.detail||{};" .
						"if(!d.action||d.action==='init')return;" .
						"if(typeof _fazConsentLog==='undefined')return;" .
						// Snapshot the decision and ID before another action or navigation
						// changes the cookie. Only the TC string waits until the CMP's
						// later listener has written this decision. A synchronous pagehide
						// flush still starts the keepalive request before the page leaves.
						"var safeUrl=(function(){if(_fazConsentLog.pageUrl)return _fazConsentLog.pageUrl;try{var current=new URL(window.location.href);return current.origin+current.pathname+current.search}catch(err){var origin=window.location.origin||(window.location.protocol+'//'+window.location.host);return origin+(window.location.pathname||'')+(window.location.search||'')}})();" .
						"var fazSend=function(b){return fetch(_fazConsentLog.restUrl,{" .
							"method:'POST'," .
							// keepalive so the request survives the navigation that
							// follows it. Withdrawal forces window.location.reload()
							// as soon as the storage cleanup resolves, and browsers
							// abort in-flight non-keepalive fetches on unload — so
							// the log rows most likely to be lost were exactly the
							// ones proving a withdrawal, while acceptances (which do
							// not reload) always landed. An accountability record
							// that is systematically biased toward consents is worse
							// than one with gaps.
							"keepalive:true," .
							"headers:{'Content-Type':'application/json'}," .
							"body:JSON.stringify(b)" .
						"})};" .
						"function readCookie(name){var names=_fazConsentLog.cookieNames||{};var prefix=(names[name]||name)+'=';var parts=document.cookie.split(';');for(var i=0;i<parts.length;i++){var p=parts[i].trim();if(p.indexOf(prefix)===0)return [p,p.substring(prefix.length)]}return null}" .
						"var payload={" .
								"consent_id:(function(){var m=readCookie('fazcookie-consent');if(!m)return '';var v=m[1];try{v=decodeURIComponent(v)}catch(err){}var p=v.match(/(?:^|,)consentid:([^,;]+)/);return p?p[1]:''})()," .
								"status:d.action==='reject'?'rejected':d.action==='all'?'accepted':'partial'," .
								"categories:(function(){var c={};(d.accepted||[]).forEach(function(k){c[k]='yes'});(d.rejected||[]).forEach(function(k){c[k]='no'});" .
									// P1-3: fold the granular per-service (svc.*) and per-cookie
									// (ck.*) decisions from the consent cookie into the logged
									// record, namespaced by their prefix, so the consent log is a
									// complete audit trail (GDPR accountability) — not just the
									// category-level summary. When per-service consent is off there
									// are no svc.*/ck.* entries and this adds nothing.
									// `undecided:1` is folded as meta.signal_only:yes: the
									// record was created by GPC or a Do Not Sell request and
									// the visitor had not answered the banner, which a row
									// marked "partial" cannot otherwise say.
									// A gpcx.<id>:1 marker (a service accepted on its own
									// blocked embed while GPC was asserted) is logged as the
									// audit key meta.gpc_exception.<id>:yes — the log keeps only
									// yes/no values, and meta.* stays out of the dashboard's
									// per-category acceptance chart.
									"try{var cm=readCookie('fazcookie-consent');if(cm){var cv=cm[1];try{cv=decodeURIComponent(cv)}catch(er){}cv.split(',').forEach(function(pr){var ci=pr.indexOf(':');if(ci<1)return;var ck=pr.substring(0,ci);if(ck.indexOf('svc.')===0||ck.indexOf('ck.')===0){c[ck]=pr.substring(ci+1)}else if(ck.indexOf('gpcx.')===0&&pr.substring(ci+1)==='1'){c['meta.gpc_exception.'+ck.substring(5)]='yes'}else if(ck==='undecided'&&pr.substring(ci+1)==='1'){c['meta.signal_only']='yes'}})}}catch(er){}" .
										// Age-gate accountability (GDPR Art. 5(2)/7(1)): when the
										// visitor affirmed they meet the digital age of consent, fold
										// the reserved meta.age_affirmed:yes key into the logged
										// categories map so the record demonstrates the age was
										// affirmed at consent time. get_consent_stats() skips the
										// meta.* prefix so it never appears as a phantom category.
										"if(d.ageAffirmed===true){c['meta.age_affirmed']='yes'}" .
									"return c})()," .
								"url:safeUrl," .
								"banner_slug:_fazConsentLog.bannerSlug||''," .
								"policy_revision:_fazConsentLog.policyRevision||1," .
								// TCF TC string (euconsent-v2 cookie) and GPP string
								// captured passively from an external CMP (__gpp API).
								// FAZ does not implement GPP or generate a GPP string.
								"tc_string:''," .
								"gpp_string:''," .
								// Jurisdiction-bootstrap verdict ('live' or
								// 'strict-fallback', set by script.js before the
								// banner mounts; absent on a page that was never the
								// strict shell). The consent log needs it to record
								// the ruleset this visitor was actually enforced under.
								"geo_bootstrap:(typeof window._fazGeoBootstrapResolved==='string'?window._fazGeoBootstrapResolved:'')," .
								"token:_fazConsentLog.token" .
						"};" .
						"var sent=false,timer,tcReady=false;function captureTc(){if(tcReady)return;tcReady=true;var m=readCookie('euconsent-v2');payload.tc_string=m?m[1]:''}function send(gppString){if(sent)return;captureTc();sent=true;clearTimeout(timer);window.removeEventListener('pagehide',flush);payload.gpp_string=typeof gppString==='string'?gppString:'';" .
						"var u=_fazConsentLog.tokenUrl;" .
						"return fazSend(u?Object.assign({can_refresh:1},payload):payload).then(function(r){" .
							"if(!u||!r||r.status!==403||typeof r.json!=='function')return;" .
							"return r.json().then(function(j){" .
								"if(!j||j.code!=='invalid_token')return;" .
								"return fetch(u+(u.indexOf('?')<0?'?':'&')+'_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(x){return x.json()}).then(function(x){return x&&x.success&&x.data&&x.data.consent?String(x.data.consent):''},function(){return ''}).then(function(k){if(k&&k!==payload.token){_fazConsentLog.token=k;payload.token=k}return fazSend(payload)})" .
							"})" .
						"}).catch(function(){});" .
						"}function flush(){send('')}" .
						"window.addEventListener('pagehide',flush);Promise.resolve().then(function(){if(sent)return;captureTc();if(typeof window.__gpp!=='function'){flush();return}timer=setTimeout(flush,150);try{window.__gpp('ping',function(data,success){if(success!==false)send(data&&data.gppString)})}catch(err){flush()}" .
					"}).catch(function(){});" .
				"});";
				// 'before', not the default 'after', and both halves of that
				// matter. An 'after' inline makes WordPress drop the defer
				// strategy for this handle and for the configuration file it
				// depends on, which would revert the whole non-blocking chain
				// without a word. And it is safe here because this snippet
				// depends on nothing script.js defines: it registers a
				// `fazcookie_consent_update` listener and reads `_fazConsentLog`,
				// which wp_localize_script() prints in the data bucket above it.
				// The listener only ever fires long after the runtime has
				// executed, so running at parse time costs nothing.
				wp_add_inline_script( $script_handle, $inline_js, 'before' );
			}

			// Enqueue the native a11y module that runs after fazcookie_banner_loaded.
			// In ad-blocker mode (alt_asset) the plugin path contains "cookie" which
			// can match filter lists, so we inline the script to avoid blocking.
			$a11y_handle = $script_handle . '-a11y';
			$a11y_suffix = $this->get_script_suffix( 'js/a11y' );
			if ( $alt_asset ) {
				$this->enqueue_inline_bundle( $a11y_handle, 'js/a11y' . $a11y_suffix . '.js', array( $script_handle ), true );
			} else {
				$this->enqueue_deferred_script( $a11y_handle, faz_frontend_url() . 'js/a11y' . $a11y_suffix . '.js', array( $script_handle ), $this->version, true );
			}
			// Pass translatable checkbox label templates — {name} is replaced in JS.
			wp_localize_script(
				$a11y_handle,
				'fazA11yConfig',
				array(
					'bannerTitleTag'     => $this->settings->get( 'banner_control', 'banner_title_tag' ),
					'preferenceTitleTag' => $this->settings->get( 'banner_control', 'preference_title_tag' ),
					'categoryTitleTag'   => $this->settings->get( 'banner_control', 'category_title_tag' ),
					/* translators: {name} is replaced with the cookie category name (appears twice, do not translate {name}) */
					'checkboxEnabled'  => __( '{name} enabled, disable {name}', 'faz-cookie-manager' ),
					/* translators: {name} is replaced with the cookie category name (appears twice, do not translate {name}) */
					'checkboxDisabled' => __( '{name} disabled, enable {name}', 'faz-cookie-manager' ),
				)
			);
		if ( true === $this->is_wpconsentapi_enabled() ) {
			$handle = $script_handle . '-wca';
			// Compute the suffix per-file so SCRIPT_DEBUG and a missing generated
			// asset both fall back safely to the readable source.
			$wca_suffix = $this->get_script_suffix( 'js/wca' );
			if ( $alt_asset ) {
				$this->enqueue_inline_bundle( $handle, 'js/wca' . $wca_suffix . '.js', array( $script_handle ), false );
			} else {
				$this->enqueue_deferred_script( $handle, faz_frontend_url() . 'js/wca' . $wca_suffix . '.js', array( $script_handle ), $this->version, false );
			}
			if ( true === $this->is_gsk_enabled() ) {
				wp_add_inline_script( $handle, 'var _fazGsk = true;', 'before' );
			}
			wp_enqueue_script( $handle );
		}
		$ms_uet     = (bool) $this->settings->get( 'microsoft', 'uet_consent_mode' );
		$ms_clarity = (bool) $this->settings->get( 'microsoft', 'clarity_consent' );
		if ( $ms_uet || $ms_clarity ) {
			$ms_handle = $script_handle . '-microsoft-consent';
			$ms_suffix = $this->get_script_suffix( 'js/microsoft-consent' );
			if ( $alt_asset ) {
				$this->enqueue_inline_bundle( $ms_handle, 'js/microsoft-consent' . $ms_suffix . '.js', array( $script_handle ), false );
			} else {
				$this->enqueue_deferred_script( $ms_handle, faz_frontend_url() . 'js/microsoft-consent' . $ms_suffix . '.js', array( $script_handle ), $this->version, false );
			}
			if ( $ms_uet ) {
				wp_add_inline_script( $ms_handle, 'window._fazMicrosoftUET = true;', 'before' );
			}
			if ( $ms_clarity ) {
				wp_add_inline_script( $ms_handle, 'window._fazMicrosoftClarity = true;', 'before' );
			}
		}
	}

	/**
	 * Keep the dependency chain ordered and non-blocking on WP 5.0+ too.
	 *
	 * What a visitor experiences, since "non-blocking" is not free. A `defer`
	 * script is fetched during parsing and executed before DOMContentLoaded, so
	 * the banner's first paint moves from "as soon as the head script runs" to
	 * "once the document has parsed" — later, but still before the load event
	 * and still on the same page view, never after a navigation. It does not
	 * move the layout: the notice container is `position: fixed`, so it is
	 * outside the flow wherever it appears, and the anti-FOUC rule in
	 * insert_styles() keeps it invisible until the runtime has positioned it
	 * rather than painting it unstyled first. Embeds the server blocked keep
	 * their placeholder throughout, because that markup and the banner
	 * stylesheet are both in the HTML and neither is deferred. What a visitor
	 * can see during the window is a third-party resource some script is trying
	 * to insert dynamically: it is held, so the space is empty until the handoff.
	 * That is the trade this release makes, and it is the same trade the hold
	 * has always made — the alternative is letting the request out first.
	 *
	 * Deferring is only safe while the inline bootstrap is holding dynamic
	 * resources for the download window; without it the runtime arrives after
	 * the page's own scripts with nothing in between. So the gate is not "can
	 * the bootstrap be read" — it is "was the bootstrap actually attached, in
	 * the head, for this request". Those were two different conditions
	 * evaluated in two places: the emission is separately guarded by the
	 * alternative-asset branch and by wp_add_inline_script() succeeding, and a
	 * handle whose chain runs in the footer gets no head-time protection at
	 * all. One flag, set by the one call site that emits it, now answers for
	 * both. Falling back to a synchronous request is slower and never
	 * unprotected.
	 */
	private function enqueue_deferred_script( $handle, $src, $dependencies, $version, $in_footer ) {
		wp_enqueue_script( $handle, $src, $dependencies, $version, $in_footer );
		if ( ! $this->bootstrap_emitted ) {
			return;
		}
		$this->deferred_script_handles[ $handle ] = true;
		wp_script_add_data( $handle, 'strategy', 'defer' );
	}

	/**
	 * Report a deferred handle that an 'after' inline has quietly un-deferred.
	 *
	 * WP_Scripts::filter_eligible_strategies() returns an empty strategy set for
	 * any handle carrying an inline script in the default 'after' position, and
	 * it recurses into dependents — so one
	 * `wp_add_inline_script( $handle, $code )` without an explicit 'before'
	 * anywhere in this chain forces the whole chain back to blocking. Nothing
	 * errors, nothing logs, and the only visible effect is that the request is
	 * render-blocking again. That is the single easiest way to revert this
	 * release by accident, from inside the plugin or from a site's own hook, so
	 * it gets named out loud under WP_DEBUG instead of being left to a profiler.
	 *
	 * Diagnostic only: it changes nothing, because an 'after' inline is a
	 * legitimate thing for a site to add and losing defer is the safe direction.
	 *
	 * @return void
	 */
	public function warn_on_undeferred_handles() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || empty( $this->deferred_script_handles ) ) {
			return;
		}
		$scripts = wp_scripts();
		foreach ( array_keys( $this->deferred_script_handles ) as $handle ) {
			if ( ! isset( $scripts->registered[ $handle ] ) ) {
				continue;
			}
			$extra = $scripts->registered[ $handle ]->extra;
			if ( empty( $extra['after'] ) ) {
				continue;
			}
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'FAZ Cookie Manager: the script handle "' . $handle . '" carries an inline script in the "after" position, '
				. 'which makes WordPress drop the defer strategy for it and for everything that depends on it. '
				. 'Pass "before" as the third argument to wp_add_inline_script() to keep the consent runtime non-blocking.'
			);
		}
	}

	/**
	 * Record that the inline bootstrap was attached to a head handle.
	 *
	 * Called from the one place that attaches it. A WP_DEBUG breadcrumb when it
	 * could not be attached, because the silent version of this failure is
	 * exactly the regression the docblock above describes: a deferred runtime
	 * with nothing holding the window, visible only as a performance change.
	 *
	 * @param bool $attached Whether wp_add_inline_script() accepted the code.
	 * @param bool $in_head  Whether the carrying handle prints in the head.
	 * @return void
	 */
	private function note_bootstrap_emission( $attached, $in_head ) {
		$this->bootstrap_emitted = (bool) $attached && (bool) $in_head;
		if ( $this->bootstrap_emitted || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			'FAZ Cookie Manager: the inline consent bootstrap was not emitted in <head> ('
			. ( $attached ? 'the carrying script prints in the footer' : 'frontend/js/bootstrap.js is missing or unreadable' )
			. '), so the consent runtime and the generated configuration stay synchronous for this request.'
		);
	}

	/**
	 * Inline bootstrap source, or '' when it cannot be read.
	 *
	 * file_get_contents() returns false on a missing or unreadable file, and
	 * WP_Scripts::add_inline_script() opens with `if ( ! $data ) return false;`
	 * — so passing that straight through emitted no bootstrap, raised no
	 * notice, and left the deferred runtime arriving with nothing holding
	 * dynamic resources in the meantime. Read it once, through one accessor,
	 * so the decision to defer and the protection that makes deferring safe
	 * can never disagree.
	 *
	 * @return string JavaScript source, or '' when unavailable.
	 */
	private function deferred_bootstrap_js() {
		if ( null === $this->bootstrap_js ) {
			$path = plugin_dir_path( __FILE__ ) . 'js/bootstrap' . $this->get_script_suffix( 'js/bootstrap' ) . '.js';
			$code = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset, not a remote request.
			$this->bootstrap_js = ( is_string( $code ) && '' !== trim( $code ) ) ? $code : '';
		}
		return $this->bootstrap_js;
	}

	/**
	 * WP added native strategies in 6.3; older supported versions still need
	 * the attribute. Touch only the external tag, never inline bootstraps.
	 */
	public function defer_runtime_script_tag( $tag, $handle ) {
		global $wp_version;
		// Modern core can deliberately remove defer when a third-party
		// synchronous script depends on this handle, or when a handle printing
		// in the head has a blocking dependent. Respect that decision rather
		// than re-applying the attribute behind its back: core knows the
		// dependency graph and this filter does not, and losing defer is the
		// safe direction — a synchronous runtime is slower, never unprotected.
		//
		// The reverse assumption is the one that has to hold, and it is enforced
		// where the strategy is requested, not here: `strategy => defer` is only
		// ever set when the inline bootstrap is actually being attached to a
		// head handle on this request (see enqueue_deferred_script()). So on
		// 6.3+ either core defers and the bootstrap is holding the window, or
		// core does not defer and there is no window to hold.
		if ( isset( $wp_version ) && version_compare( $wp_version, '6.3', '>=' ) ) {
			return $tag;
		}
		if ( is_admin() || empty( $this->deferred_script_handles[ $handle ] ) ) {
			return $tag;
		}
		$new_tag = preg_replace_callback( '#<script(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>#i', function ( $match ) {
			$attributes = $match[1];
			if ( ! preg_match( '/(?:^|\s)src\s*=/i', $attributes ) || preg_match( '/\btype\s*=\s*[\'"]text\/plain/i', $attributes ) ) {
				return $match[0];
			}
			// An async rewrite would lose the config -> runtime -> bridge order.
			$attributes = preg_replace( '/\s+(?:async|defer)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|$)/i', '', $attributes );
			return '<script defer' . $attributes . '>';
		}, $tag );
		// PCRE failure returns null, and this composite tag carries the whole
		// inline bootstrap plus the runtime's <script src>. Returning null from
		// a tag filter drops all of it from the page — the consent banner with
		// it. Same guard tag_own_scripts_nooptimize() already uses.
		return is_string( $new_tag ) ? $new_tag : $tag;
	}

	/**
	 * Fetch the consent runtime at high priority.
	 *
	 * Chrome downloads deferred scripts at low priority, behind the page's
	 * images, and the banner cannot paint until the runtime has run. On a shop
	 * home page (wp.org topic render-blocking-requests-2) script.min.js
	 * finished downloading at 2.8 s on a throttled mobile load, and the banner
	 * text — the largest text block, so PageSpeed's LCP element — painted
	 * after it. With fetchpriority="high" on the runtime alone, the same page
	 * measured LCP 3.31 s → 3.02 s (median of 8 interleaved runs each), for a
	 * 0.16 s later first paint. The config file and the banner stylesheet keep
	 * their priority, and nothing else changes in when the runtime executes:
	 * defer still holds the config → runtime order. Only the external runtime
	 * tag is touched, so the alternative-asset (inline) mode is unaffected.
	 *
	 * @param string $tag    Rendered script markup for the handle.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function prioritize_runtime_script_tag( $tag, $handle ) {
		if ( is_admin() || $this->plugin_name !== $handle ) {
			return $tag;
		}
		/**
		 * Whether the consent runtime is fetched with fetchpriority="high".
		 *
		 * @since 1.34.4
		 * @param bool $high Default true.
		 */
		if ( ! apply_filters( 'faz_runtime_fetchpriority', true ) ) {
			return $tag;
		}
		// A priority registered for the handle through WordPress (6.9+:
		// wp_script_add_data( $handle, 'fetchpriority', … ) or the
		// 'fetchpriority' script arg) is a deliberate choice. 'auto' prints no
		// attribute and 'low' can be lifted to 'auto' by a dependent, so the
		// markup alone cannot show it: read the registration and keep it.
		if ( function_exists( 'wp_scripts' ) ) {
			$registered = wp_scripts()->get_data( $handle, 'fetchpriority' );
			if ( is_string( $registered ) && '' !== $registered ) {
				return $tag;
			}
		}
		$id      = preg_quote( $handle . '-js', '#' );
		$new_tag = preg_replace_callback( '#<script(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>#i', function ( $match ) use ( $id ) {
			$attributes = $match[1];
			if ( ! preg_match( '/(?:^|\s)src\s*=/i', $attributes )
				|| ! preg_match( '#(?:^|\s)id\s*=\s*([\'"])' . $id . '\1#i', $attributes )
				|| preg_match( '/(?:^|\s)fetchpriority\s*=/i', $attributes ) ) {
				return $match[0];
			}
			return '<script fetchpriority="high"' . $attributes . '>';
		}, $tag );
		// Same guard as defer_runtime_script_tag(): a PCRE failure must not
		// drop the bootstrap and the runtime from the page.
		return is_string( $new_tag ) ? $new_tag : $tag;
	}

	/**
	 * Enqueue a bundled local script as inline code behind an empty WP handle.
	 *
	 * Alternative-asset mode exists specifically to avoid a public plugin URL
	 * containing ad-blocker keywords. Every FAZ frontend bundle must therefore
	 * use the same delivery path, not just the main and accessibility bundles.
	 *
	 * Known limit: an inline bundle is exposed to page rewriters that parse
	 * the document as HTML4 (DOMDocument::loadHTML(), e.g. WPSpeed's image
	 * optimiser). libxml ends a <script> at the first `</` followed by a
	 * letter, so a `</` inside the bundle's string literals truncates the
	 * script. The banner template escapes `</` as `<\/` for this reason
	 * (escape_template_end_tags()), but that is not done here on purpose:
	 * rewriting `</` in JavaScript source would corrupt regex literals and
	 * other code where `<\/` is not equivalent. A site that combines this
	 * option with such a rewriter should leave this option off.
	 *
	 * @param string $handle        Script handle.
	 * @param string $relative_path Path relative to frontend/.
	 * @param array  $dependencies  Script dependencies.
	 * @param bool   $in_footer     Whether to print in the footer.
	 * @return bool Whether the local bundle was found and attached.
	 */
	private function enqueue_inline_bundle( $handle, $relative_path, $dependencies = array(), $in_footer = false ) {
		$path = plugin_dir_path( __FILE__ ) . ltrim( $relative_path, '/' );
		wp_register_script( $handle, false, $dependencies, $this->version, $in_footer );
		wp_enqueue_script( $handle );
		if ( ! file_exists( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- trusted local bundle.
		wp_add_inline_script( $handle, file_get_contents( $path ) );
		return true;
	}

	/**
	 * Persist a content-hashed static asset under
	 * uploads/faz-cookie-manager/assets/ and return its URL ('' on failure).
	 *
	 * Used to move the per-page inline payloads (compiled banner CSS, static
	 * provider config) into real files the browser can cache: the filename
	 * embeds a hash of the content, so it is immutable — no ?ver needed, no
	 * invalidation problem, and stale full-page caches keep referencing their
	 * own (still existing) hash. Steady state costs one file_exists() per asset
	 * per request; the WP_Filesystem machinery is only touched when the file is
	 * missing (first request after a change).
	 *
	 * Superseded hashes are reaped by cleanup_static_assets() on the daily
	 * cron, using mtime as a last-used timestamp (refreshed at most once a day
	 * by the hit path below), so the directory cannot grow without bound on a
	 * long-lived install.
	 *
	 * @param string $filename Hash-carrying file name (no path).
	 * @param string $contents File contents to write when missing.
	 * @return string Public URL, or '' when the file cannot be provided.
	 */
	private function get_static_asset_url( $filename, $contents ) {
		static $write_failed = false;

		$dir_info = self::get_static_assets_dir();
		if ( empty( $dir_info['path'] ) || empty( $dir_info['url'] ) ) {
			return '';
		}

		$path = $dir_info['path'] . $filename;
		if ( file_exists( $path ) ) {
			// Keep mtime meaning "last used" for the reaper, without paying a
			// write on every request. filemtime() here hits PHP's per-request
			// stat cache populated by the file_exists() above.
			$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $mtime && ( time() - $mtime ) > DAY_IN_SECONDS ) {
				@touch( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- last-used marker for the daily reaper; WP_Filesystem has no touch primitive, and this runs on the frontend hot path where loading it would cost more than the write it replaces.
			}
			return $dir_info['url'] . $filename;
		}

		if ( $write_failed ) {
			return '';
		}

		$fs = Filesystem::get_instance();
		if ( ! $fs->can_access_filesystem() || ! wp_mkdir_p( $dir_info['path'] ) ) {
			$write_failed = true;
			return '';
		}
		if ( ! file_exists( $dir_info['path'] . 'index.php' ) ) {
			$fs->put_contents( $dir_info['path'] . 'index.php', "<?php // Silence is golden.\n" );
		}

		// Stage + rename instead of writing $path directly: put_contents() is
		// not atomic, so a concurrent request could see the file exist (and
		// hand its URL to a browser) while it is still half-written — and
		// because the name is a content hash it would stay truncated forever.
		// A same-directory rename() is atomic on POSIX and NTFS. Mirrors the
		// staging pattern in Geolocation::extract_mmdb().
		$staged = $dir_info['path'] . '.' . $filename . '.' . wp_generate_uuid4() . '.tmp';
		if ( ! $fs->put_contents( $staged, $contents ) ) {
			$write_failed = true;
			return '';
		}
		if ( ! @rename( $staged, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- intentional atomic same-dir swap; WP_Filesystem has no atomic move.
			wp_delete_file( $staged );
			// A parallel request may have landed the same file first. The name
			// is a content hash, so that copy is byte-identical — use it.
			if ( ! file_exists( $path ) ) {
				$write_failed = true;
				return '';
			}
		}

		return $dir_info['url'] . $filename;
	}

	/**
	 * Resolve (and memoize) the generated-assets directory.
	 *
	 * @return array { path: string, url: string }
	 */
	private static function get_static_assets_dir() {
		// Keyed by blog id, not a plain static: get_uploads_dir() resolves through
		// wp_upload_dir(), which is per-site. A single static would be filled from
		// whichever site ran first, and after a switch_to_blog() — network admin,
		// a multisite cron pass, any plugin that iterates blogs — both the serve
		// path and the reaper would then be pointed at another site's directory.
		// The reaper deletes files, so getting this wrong is destructive.
		static $dir_info = array();
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( ! isset( $dir_info[ $blog_id ] ) ) {
			$dir_info[ $blog_id ] = Filesystem::get_instance()->get_uploads_dir( 'faz-cookie-manager/assets' );
		}
		return is_array( $dir_info[ $blog_id ] ) ? $dir_info[ $blog_id ] : array();
	}

	/**
	 * Reap generated static assets that have not been served for a while.
	 *
	 * The config/banner assets are content-hashed, so every settings, cookie
	 * or banner-template change mints a NEW filename and orphans the previous
	 * one. Without this, an install that is edited regularly accumulates
	 * orphans forever.
	 *
	 * Edits are not the only axis, and this is the part that is easy to get
	 * wrong when reasoning about how much disk this costs. The hashed payload is
	 * the CLIENT provider list plus the cookie-category map, and that list is
	 * legitimately different on a WooCommerce checkout or cart page (payment
	 * gateway patterns removed), on a page excluded from script blocking (empty),
	 * under a geo ruleset that resolves to different blocking, and when a
	 * self-restricting integration withdraws its own patterns. So the live set at
	 * any moment is roughly (page contexts × geo rulesets), and the retained set
	 * is that multiplied by the number of settings revisions inside the window —
	 * not one file per edit.
	 *
	 * mtime is used as a last-used timestamp: get_static_asset_url() refreshes
	 * it (at most once a day) whenever it serves an existing file, so assets
	 * still referenced by live pages keep moving forward and are never reaped.
	 * The retention window is deliberately generous — full-page caches can
	 * hold a rendered page (and its asset URL) for days without PHP running —
	 * and the files are small, so erring long costs a few MB at worst.
	 *
	 * @return int Number of files deleted.
	 */
	public static function cleanup_static_assets() {
		$dir_info = self::get_static_assets_dir();
		if ( empty( $dir_info['path'] ) || ! is_dir( $dir_info['path'] ) ) {
			return 0;
		}

		/**
		 * Filter how long an unused generated asset is kept, in days.
		 *
		 * The default is deliberately long. mtime is refreshed only when PHP
		 * renders the page, and a full-page cache serves the HTML — including the
		 * config-*.js and banner-*.css URLs — without running PHP at all. An
		 * asset can therefore look untouched for as long as the cache TTL while
		 * still being referenced by pages in that cache. Deleting it gives those
		 * visitors a 404 on the banner's own stylesheet and script, which on this
		 * plugin means the consent UI may not appear.
		 *
		 * A year covers every plausible page-cache TTL with margin, and errs the
		 * safe way: the cost of keeping an orphan is disk, the cost of deleting a
		 * live one is a missing consent banner.
		 *
		 * Be honest about that disk cost rather than calling it negligible, since
		 * this default is what a site owner is deciding whether to lower. Each
		 * config asset is tens of kilobytes and the retained count is settings
		 * revisions × page contexts × geo rulesets (see cleanup_static_assets()),
		 * so a large, frequently edited, geo-targeted site can reach tens of
		 * megabytes over a year — not the handful a single-variant reading of
		 * this would suggest. Still the right trade against a broken banner, and
		 * still worth lowering through this filter on a site that knows its cache
		 * TTL.
		 *
		 * @since 1.26.0
		 * @param int $days Days an unused asset is kept. Default 365.
		 */
		$days = (int) apply_filters( 'faz_static_asset_retention_days', 365 );
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$deleted = 0;
		// Only ever touch files this class generates: the two content-hashed
		// families, plus staging files orphaned by an interrupted write.
		$patterns = array( 'config-*.js', 'banner-*.css', '.config-*.js.*.tmp', '.banner-*.css.*.tmp' );
		foreach ( $patterns as $pattern ) {
			$files = glob( $dir_info['path'] . $pattern );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				// A failed stat must mean "leave it alone", never "delete it".
				// filemtime() returns false on a permissions problem or a race
				// with a concurrent write, and treating that as "old" would reap
				// an asset that pages in a full-page cache still reference.
				if ( false === $mtime || $mtime > $cutoff ) {
					continue;
				}
				wp_delete_file( $file );
				$deleted++;
			}
		}

		return $deleted;
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
		return faz_asset_suffix( 'frontend/' . ltrim( $asset_base, '/' ) );
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
		// One predicate, shared with Amp_Consent, Banner_Rest,
		// Translation_Compat and faz_current_language(). See
		// FazCookie\Includes\Cache_Compatibility for why the three states
		// matter and what the five copies used to disagree about.
		return \FazCookie\Includes\Cache_Compatibility::is_active( $this->get_faz_settings() );
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
		// Use the wider UI-suppressed check: if the banner UI is hidden by the
		// current runtime/settings context, the placeholder CSS is unnecessary.
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
		// depends on to register script.js / gcm.js / tcf-cmp.js.
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
		$visitor_country = ( $this->is_cache_compatibility_enabled() || $this->is_geo_bootstrap_cache_active() )
			? ''
			: $this->get_visitor_country();
		$this->banner    = Controller::get_instance()->get_active_banner_for_country( $visitor_country );

		// A/B testing of banner variants (Settings → Banner Control): when the
		// admin has enabled the test with 2+ valid variants, replace the
		// normally-selected banner with the visitor's persistently-assigned
		// variant. No-op (returns the banner above) when the feature is off,
		// under-configured, or Cache Compatibility Mode is on. Runs BEFORE the
		// runtime law-routing block below so, in the common configuration
		// (runtime geo-routing off → $runtime_ruleset is null → that block is
		// skipped), the chosen variant is what ships; when runtime geo-routing
		// IS active, jurisdiction compliance still wins over the A/B preference.
		$this->banner = $this->maybe_apply_ab_test( $this->banner, $visitor_country );

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

		if ( null !== $runtime_ruleset ) {
			$this->banner->set_settings(
				Geo_Runtime::apply_ui_requirements(
					$runtime_ruleset,
					$this->banner->get_settings(),
					\FazCookie\Includes\Withdrawal_Path::satisfies_revisit_requirement( $this->get_faz_settings() )
				)
			);
		}

		// Per-banner geo-targeting: skip banner if visitor's country doesn't match the ruleSet.
		if ( ! $this->is_cache_compatibility_enabled() && ! $this->is_geo_bootstrap_cache_active() && $this->is_geo_blocked() ) {
			return;
		}

		\FazCookie\Frontend\Modules\Shortcodes\Shortcodes::set_applied_ruleset( $runtime_ruleset );
		$this->template = $this->banner->get_template();
	}

	/**
	 * Apply the banner-variant A/B test to the selected banner.
	 *
	 * When Settings → Banner Control → A/B test is enabled and lists two or
	 * more slugs that still resolve to ACTIVE banner rows, the visitor is
	 * assigned one variant via a random split and that variant's banner replaces
	 * the default selection. No experiment cookie is written before consent.
	 * Once the visitor acts, the existing strictly-necessary consent record's
	 * scoped banner slug keeps the assignment stable and the consent log records
	 * which variant produced the decision (accountability).
	 *
	 * Compliance: the test only ever chooses among banner rows the admin
	 * already created, each independently compliant (equal-weight buttons,
	 * opt-in categories). It cannot author a dark pattern.
	 *
	 * Cache Compatibility Mode: a per-visitor SERVER-side random split is
	 * fundamentally incompatible with full-page caching — the first anonymous
	 * visitor's variant would be baked into the cached HTML and then served to
	 * everyone, silently ending the experiment and making the persisted cookie
	 * disagree with the served banner. So when that mode is on we skip the
	 * split entirely and serve the normally-selected banner. (A future
	 * cache-safe variant would have to run the split client-side in script.js;
	 * until then A/B testing simply requires Cache Compatibility Mode off.)
	 *
	 * @since 1.25.0
	 * @param Banner|false $default_banner  The banner selected before A/B.
	 * @param string       $visitor_country Resolved visitor country. Used to keep
	 *                                      variant selection within the same
	 *                                      geo-eligibility as the normal banner
	 *                                      selection: filters candidates through
	 *                                      Ab_Test::is_banner_geo_eligible()
	 *                                      (target_countries + ruleSet) so an A/B
	 *                                      experiment can never surface a variant
	 *                                      that normal routing would have hidden
	 *                                      or excluded for this visitor.
	 * @return Banner|false The variant banner, or $default_banner unchanged.
	 */
	private function maybe_apply_ab_test( $default_banner, $visitor_country ) {
		// See docblock: no server-side split under Cache Compatibility Mode.
		// The jurisdiction bootstrap caches one strict shell, so a per-visitor
		// random split is equally incompatible there: the first MISS's variant
		// would be frozen into the shared cache entry.
		if ( false === $default_banner || $this->is_cache_compatibility_enabled() || $this->is_geo_bootstrap_cache_active() ) {
			return $default_banner;
		}

		$ab = $this->settings->get( 'banner_control', 'ab_test' );
		if ( ! is_array( $ab ) || empty( $ab['status'] ) ) {
			return $default_banner;
		}

		$configured = ( isset( $ab['variants'] ) && is_array( $ab['variants'] ) ) ? $ab['variants'] : array();
		if ( count( $configured ) < 2 ) {
			return $default_banner;
		}

		// Re-validate the configured slugs against the LIVE banner rows so a
		// variant whose banner was deleted or deactivated after setup drops out.
		$controller = Controller::get_instance();
		$valid      = $controller->filter_active_variant_slugs( $configured );
		if ( count( $valid ) < 2 ) {
			return $default_banner;
		}

		// An experiment may vary presentation, never the legal/geo regime. Keep
		// only active banners with the same law + Do-Not-Sell model as the banner
		// selected by normal routing, and which are eligible for this visitor.
		// Without this gate a US-targeted CCPA variant could replace an EU GDPR
		// banner (or a country-restricted variant could make is_geo_blocked() hide
		// the notice entirely), turning an A/B setting into a compliance override.
		$required_model = Ab_Test::experiment_model( $default_banner );
		$compatible     = array();
		foreach ( $valid as $slug ) {
			$candidate = $controller->get_active_banner_by_slug( $slug );
			if ( false === $candidate || Ab_Test::experiment_model( $candidate ) !== $required_model ) {
				continue;
			}
			// Geo scope (target_countries) + ruleSet eligibility go through ONE
			// shared implementation with the REST language-swap path
			// (Ab_Test::is_banner_geo_eligible) so the initial server render and a
			// later REST swap can never resolve a different variant for the same
			// visitor. Case is normalised on both country and targets.
			if ( ! Ab_Test::is_banner_geo_eligible( $candidate, $visitor_country ) ) {
				continue;
			}
			$compatible[ $slug ] = $candidate;
		}
		$valid = array_keys( $compatible );
		if ( count( $valid ) < 2 ) {
			return $default_banner;
		}

		// The split is per visitor (a random draw before any action, the
		// recorded __scope.banner after), so the page it produces must not be
		// stored: a page cache freezes the first MISS's variant into the copy
		// every later visitor receives, the experiment then measures one arm,
		// and the record of which banner a visitor saw stops matching the one
		// served. Keep this response out of shared caches; when that is not
		// possible (output already started) serve the normally-routed banner,
		// which is the same for everyone. Moving the draw into script.js is the
		// cache-friendly alternative, and a larger change than this fix.
		if ( ! $this->send_page_cache_veto( 'FAZ Cookie Manager A/B banner test' ) ) {
			return $default_banner;
		}
		$this->ab_test_cache_vetoed = true;

		// Reuse the assignment already present in the strictly-necessary consent
		// record. Creating a standalone A/B cookie before the visitor acts would
		// itself be non-essential tracking under opt-in regimes. Before any action
		// the split is intentionally stateless; the selected banner is still
		// recorded with the eventual consent and becomes sticky from then on.
		$consent       = function_exists( 'faz_parse_consent_cookie' ) ? faz_parse_consent_cookie() : array();
		$stored_banner = isset( $consent['__scope.banner'] ) ? sanitize_title( (string) $consent['__scope.banner'] ) : '';
		$chosen        = Ab_Test::pick_variant( $valid, $stored_banner, wp_rand( 0, count( $valid ) - 1 ) );
		if ( '' === $chosen ) {
			return $default_banner;
		}

		return isset( $compatible[ $chosen ] ) ? $compatible[ $chosen ] : $default_banner;
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
		return $this->is_banner_geo_blocked( $this->banner, $this->get_visitor_country() );
	}

	/**
	 * Check one banner's ruleSet against an already-resolved visitor country.
	 *
	 * Shared by the normal post-selection guard and A/B candidate filtering so
	 * an experiment cannot choose a banner that the normal geo path would hide.
	 *
	 * @param Banner $banner  Banner to evaluate.
	 * @param string $country ISO-3166 alpha-2 country code, or ''.
	 * @return bool True when the banner must not be shown.
	 */
	private function is_banner_geo_blocked( $banner, $country ) {
		$settings = $banner->get_settings();
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
	 * PublisherCC for the TC string: the publisher's country of establishment.
	 *
	 * The configured iab.publisher_cc wins. Without one, the region of the site
	 * locale is a reasonable proxy (de_DE → DE). When the locale has no region
	 * either — fi, el, et, lv, hr, ja and every other region-less WordPress
	 * locale — the answer is 'AA', the value the TC String specification
	 * reserves for an unknown publisher country. This used to fall back to a
	 * hard-coded 'IT' (repeated in tcf-cmp.js), which asserted a specific, and
	 * for every non-Italian publisher wrong, country in a signed consent record.
	 *
	 * @return string Two upper-case letters.
	 */
	private function resolve_tcf_publisher_cc() {
		$saved_cc     = $this->settings->get( 'iab', 'publisher_cc' );
		$country_code = is_scalar( $saved_cc ) && '' !== (string) $saved_cc ? strtoupper( sanitize_text_field( (string) $saved_cc ) ) : '';
		if ( preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
			return $country_code;
		}
		$site_locale = (string) get_locale();
		if ( preg_match( '/^[a-z]{2,3}[_-]([A-Z]{2})/i', $site_locale, $matches ) ) {
			return strtoupper( $matches[1] );
		}
		return 'AA';
	}

	/**
	 * gdprApplies for the TCF payload.
	 *
	 * The CMP API spec defines gdprApplies as the PUBLISHER's perspective:
	 * whether the Framework applies to this user, in this context. When the
	 * geo-routing runtime resolves a ruleset for the visitor, that ruleset IS
	 * the publisher's declaration — it decides the banner model, the blocked
	 * scripts and the GCM defaults — and its `signals.tcf_v22` says whether the
	 * regime it enforces is a TCF one. gdpr-strict routes CH, AD, MC, SM, VA
	 * (and every US visitor without a state ruleset) to an opt-in banner with
	 * tcf_v22 true, while Geolocation::$eu_countries does not list them; so
	 * reading only the country list made the CMP tell vendors gdprApplies=false
	 * for a visitor whose page had just enforced opt-in and shown the TCF UI,
	 * which under TCF semantics entitles them to disregard the TC string.
	 *
	 * The signal is the flag, not the model: LGPD/POPIA rulesets are opt-in
	 * but carry tcf_v22 false and must stay false. Without a resolved ruleset
	 * (routing off) the country list decides, with "unknown country" kept
	 * conservative (true). Under Cache Compatibility Mode the payload must be
	 * identical for every anonymous visitor, so nothing per-visitor is read.
	 *
	 * @return bool
	 */
	private function resolve_tcf_gdpr_applies() {
		if ( $this->is_cache_compatibility_enabled() ) {
			return true;
		}
		$ruleset = $this->get_runtime_ruleset();
		if ( is_array( $ruleset )
			&& isset( $ruleset['signals'] ) && is_array( $ruleset['signals'] )
			&& array_key_exists( 'tcf_v22', $ruleset['signals'] ) ) {
			return (bool) $ruleset['signals']['tcf_v22'];
		}
		$visitor_country = $this->get_visitor_country();
		return empty( $visitor_country ) || in_array( $visitor_country, Geolocation::$eu_countries, true );
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

		// Every dimension below varies the render by the visitor's COUNTRY, and
		// all of them read it through Geolocation::get_visitor_country(). On an
		// install with no way to resolve a country that call returns '' for
		// every visitor: TCF gdprApplies stays true, the country→language
		// fallback stays on the site language, "no banner outside targets"
		// fails open, country-targeted banner rows fall back to the match-all
		// row and the runtime resolves the one most-protective ruleset. The
		// render is then invariant, and a no-store veto buys nothing while
		// costing the publisher their whole page cache — on every request, on
		// most shared hosting. So no source, no veto, whichever of the
		// dimensions is configured; the filter below can still force it.
		//
		// Deliberately keyed on whether a source is CONFIGURED, not on whether
		// this particular visitor resolved: with a source present the output is
		// country-dependent even for a visitor the source happens to miss.
		if ( ! $this->has_country_signal_source() ) {
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

		// Runtime geo-routing: when the Geo-Targeting UI toggle (or the explicit
		// developer override) enables the resolved ruleset, it drives the
		// pre-consent category defaults, the GCM signals and the blocked-category
		// set, the rendered HTML/JS varies by the visitor's country/region. A
		// cached response would leak one jurisdiction's defaults to another, so
		// the output must always be treated as country-dependent while the flag
		// is on — independent of any multi-banner geo-targeting configuration.
		// The filter can explicitly override the UI-derived state. (Reached
		// only with a country source: see the early return above.)
		if ( Geo_Runtime::is_enabled() ) {
			$dependent = true;
		}

		return (bool) apply_filters( 'faz_country_dependent_banner_output', $dependent, $settings );
	}

	/**
	 * Whether this install has any way at all to determine a visitor country.
	 *
	 * The four sources Geolocation actually consults, in its own order. None of
	 * them is a live third-party API call: the Cloudflare header only exists
	 * behind Cloudflare and only counts when the publisher has trusted it, the
	 * two server extensions are provided by the host, and GeoLite2 is a local
	 * database the publisher downloads once.
	 *
	 * Evaluated per request and never memoised across requests: a publisher who
	 * installs GeoLite2, or moves behind Cloudflare and trusts the header, must
	 * get the cache veto back on the very next request. Any page cached before
	 * that carried the most-protective shell, so the stale direction over-blocks
	 * rather than under-blocks.
	 *
	 * @return bool True when at least one country source is available.
	 */
	private function has_country_signal_source() {
		// One predicate, in Geolocation. The body that used to live here was
		// duplicated by a DIFFERENT test in the admin (a saved licence key was
		// treated as proof a database existed), so a site with no database at
		// all was told its geo source was configured while this method — the
		// one the resolver's behaviour actually follows — said otherwise.
		// Keeping the wrapper preserves the private call sites and the
		// `faz_has_country_signal_source` filter contract.
		return \FazCookie\Includes\Geolocation::has_country_source();
	}

	/**
	 * Declare the WP Consent API consent type for this visitor.
	 *
	 * `optin`  — nothing may fire before an affirmative act (GDPR and friends).
	 * `optout` — processing is permitted until the visitor objects (CCPA/CPRA).
	 *
	 * Resolved from the banner already selected for this visitor, which is the
	 * same source get_blocked_categories() uses for `$is_optout_law`, so the
	 * Consent API and this plugin's own gating can never disagree about the
	 * regime. Before the banner is loaded there is nothing to distinguish, and
	 * the stricter answer is the safe one.
	 *
	 * @param string $type Type proposed by another integration.
	 * @return string
	 */
	public function filter_wp_consent_type( $type ) {
		// Never downgrade a stricter declaration another plugin already made.
		if ( 'optin' === $type ) {
			return $type;
		}
		// Answer with the regime actually being ENFORCED, not the banner's label.
		// Declaring 'optout' makes wp_has_consent() return true by default, so
		// every third-party plugin honouring the WP Consent API is told it may
		// fire — including server-side, where FAZ's output filtering cannot
		// reach it. Getting this wrong is not a mislabel, it is other people's
		// trackers running before consent.
		//
		// Two states where the banner says ccpa and the enforcement does not:
		//
		// 1. The runtime ruleset resolved an opt-in law for this visitor while
		//    the publisher has no active GDPR banner. maybe_apply_geo_runtime()
		//    deliberately KEEPS the CCPA banner there (fail-closed, so blocking
		//    still runs) — so the banner's law is the one thing that is known to
		//    be wrong in exactly that case.
		// 2. Cache Compatibility Mode blocks every non-necessary category
		//    regardless of law, so nothing may be assumed consented.
		return self::resolve_wp_consent_type(
			$this->get_runtime_ruleset(),
			$this->is_cache_compatibility_enabled(),
			$this->banner ? (string) $this->banner->get_law() : ''
		);
	}

	/**
	 * The WP Consent API type for a resolved ruleset, mode and banner law.
	 *
	 * One rule for the two places that answer it: the page render
	 * (filter_wp_consent_type(), shipped in the store as `_wpConsentType`) and
	 * the public banner endpoint (`wpConsentType`), which replaces the store
	 * after a language swap or the jurisdiction bootstrap. Without the second,
	 * a cached strict shell kept its `optin` verdict after the live payload
	 * resolved a CCPA banner, and wca.js, where the stricter verdict wins, kept
	 * declaring opt-in for the rest of the page view.
	 *
	 * @param array|null $runtime_ruleset     Ruleset resolved for the visitor.
	 * @param bool       $cache_compatibility Whether Cache Compatibility applies.
	 * @param string     $law                 Law of the banner being served.
	 * @return string 'optin' or 'optout'.
	 */
	public static function resolve_wp_consent_type( $runtime_ruleset, $cache_compatibility, $law ) {
		if ( null !== $runtime_ruleset && 'ccpa' !== Geo_Runtime::model_to_law( $runtime_ruleset ) ) {
			return 'optin';
		}
		if ( $cache_compatibility ) {
			return 'optin';
		}
		return 'ccpa' === $law ? 'optout' : 'optin';
	}

	/**
	 * Whether Apache mod_geoip is configured on this site.
	 *
	 * Asks whether the MODULE is loaded, not whether THIS request carries its
	 * header — the distinction the cache veto turns on. mod_geoip resolves
	 * REMOTE_ADDR and emits nothing for loopback or private addresses, so a
	 * cache warmer's request looks identical to "no geo source at all" if you
	 * only test the header.
	 *
	 * apache_get_modules() exists only under mod_php. Under PHP-FPM (and CGI)
	 * there is no way to enumerate Apache's modules from PHP, so fall back to
	 * the header. That fallback is the pre-existing behaviour, so this can only
	 * ever widen the veto, never narrow it, and `faz_has_country_signal_source`
	 * remains the explicit override for a publisher who knows their stack.
	 *
	 * @return bool
	 */
	private function mod_geoip_configured() {
		return \FazCookie\Includes\Geolocation::mod_geoip_configured();
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
		// Jurisdiction bootstrap: the cached document is a visitor-invariant,
		// most-protective shell. The live no-store REST request chooses the real
		// banner/ruleset in the browser before any optional resource is released,
		// so the country-dependent page-cache veto is unnecessary while the full
		// bootstrap readiness gate holds.
		if ( $this->is_geo_bootstrap_cache_active() ) {
			return;
		}
		\FazCookie\Includes\Cache_Compatibility::define_no_cache_constants();
	}

	/**
	 * Whether `hide_from_bots` is effectively enabled for this request.
	 *
	 * A missing key counts as enabled: that is the historical behaviour and it
	 * matches Settings::get_defaults(), where `hide_from_bots` is true.
	 *
	 * @return bool
	 */
	private function hide_from_bots_enabled() {
		$settings = $this->get_faz_settings();
		return ! isset( $settings['banner_control']['hide_from_bots'] )
			|| ! empty( $settings['banner_control']['hide_from_bots'] );
	}

	/**
	 * Whether this response's banner render varies by User-Agent.
	 *
	 * True only when `hide_from_bots` actually changes the output for the
	 * caller at hand — the setting is on and the request is a known crawler.
	 * Cache Compatibility Mode and the cached jurisdiction shell both require a
	 * visitor-invariant render, so under either one the answer is always false
	 * and the crawler sees exactly what a human sees.
	 *
	 * faz_is_bot() is tested first on purpose: it is a substring scan over one
	 * request header, while the two cache predicates below read settings and
	 * banner storage. This runs on `send_headers` for every front-end request,
	 * and for all but a few per cent of them the cheap test ends it.
	 *
	 * @return bool
	 */
	private function is_bot_variant_render() {
		if ( null !== $this->bot_variant_render ) {
			return $this->bot_variant_render;
		}
		// `function_exists` + leading `\`: this predicate is reachable from
		// `flying_press_is_cacheable`, which can fire on bootstraps where
		// includes/class-utils.php has not been loaded, and an unqualified call
		// would resolve inside FazCookie\Frontend first. Same guard the AMP
		// consent class uses.
		$variant = function_exists( 'faz_is_bot' ) && \faz_is_bot()
			/**
			 * Whether a crawler-only render may be kept out of shared caches.
			 *
			 * The two things `hide_from_bots` wants — a banner-less copy for
			 * crawlers, and a page the cache can keep — cannot both be had: a
			 * cache that does not vary by User-Agent will hand the crawler's
			 * copy to the next human. Returning false here resolves that in
			 * favour of the cache, and FAZ then stops hiding the banner from
			 * crawlers at all rather than risk hiding it from visitors.
			 *
			 * @since 1.34.1
			 * @param bool $veto Default true (hide from bots, bypass the cache).
			 */
			&& (bool) apply_filters( 'faz_bot_render_cache_veto', true )
			&& $this->hide_from_bots_enabled()
			&& ! $this->is_cache_compatibility_enabled()
			&& ! $this->is_geo_bootstrap_cache_active();

		$this->bot_variant_render = $variant;
		return $variant;
	}

	/**
	 * Keep a User-Agent-variant render out of every shared page cache.
	 *
	 * `hide_from_bots` makes the rendered HTML depend on the User-Agent: a
	 * crawler receives no banner container, no banner stylesheet and no
	 * runtime. Nothing in the response ever said so, and no page cache varies
	 * by User-Agent, so the first crawler to warm a URL stored a banner-less
	 * copy that the cache then served to every human visitor — no consent UI,
	 * trackers unblocked, and nothing on the page to reveal it.
	 *
	 * Reported on kalabron.com (o2switch: LiteSpeed Web ADC in front of
	 * LiteSpeed Cache), where the banner vanished whenever LiteSpeed was
	 * enabled and came back the moment the host turned it off. A request with a
	 * Googlebot User-Agent returned the page with no `#fazBannerTemplate`, no
	 * `script.min.js` and no banner CSS — under
	 * `x-litespeed-cache-control: public,max-age=14400`, i.e. explicitly
	 * cacheable for four hours and keyed without the User-Agent.
	 *
	 * This is the country-variance hazard one dimension over, so it gets the
	 * same answer and the same header set as send_geo_cache_headers().
	 *
	 * @return bool Whether the veto is in force for this request.
	 */
	private function veto_page_cache_for_bot_variance() {
		if ( $this->bot_variance_cache_vetoed ) {
			return true;
		}
		// LSCache decides cacheability from this action and the request-time
		// header, not from a response-side `Cache-Control: no-store` (see the
		// rest_pre_dispatch note in faz-cookie-manager.php). Fire it either way:
		// it only records intent, which LSCWP applies when it buffers output.
		if ( ! $this->send_page_cache_veto( 'FAZ Cookie Manager crawler-only banner render', 'User-Agent' ) ) {
			// Output has already started, so the directive the edge cache reads
			// can no longer be set. Report the veto as absent: the caller then
			// renders the banner instead, which costs a crawler a few KB of
			// markup. The other direction costs a human their consent notice.
			return false;
		}
		$this->bot_variance_cache_vetoed = true;
		return true;
	}

	/**
	 * Keep THIS response out of every shared cache: the one veto every
	 * per-request variance in this class uses.
	 *
	 * Three things in one call, because each cache family listens to a
	 * different one and a veto that speaks to only some of them is a page that
	 * one of the others keeps:
	 *
	 *  - `litespeed_control_set_nocache` — LiteSpeed Cache decides from this
	 *    action (and LSCACHE_NO_CACHE), not from a response-side no-store.
	 *    Fired first and unconditionally: it only records intent, which LSCWP
	 *    applies when it buffers the output, so it still counts when the
	 *    headers below can no longer be sent.
	 *  - the response headers in Cache_Compatibility::NO_STORE_HEADERS, for
	 *    the caches that only read headers (CDNs, Varnish, nginx, QUIC.cloud);
	 *  - the DONOTCACHE* constants the WordPress page caches read.
	 *
	 * FlyingPress reads none of the three; flying_press_is_cacheable() answers
	 * it from the same memoised verdicts.
	 *
	 * @param string $reason Diagnostic label passed to LiteSpeed.
	 * @param string $vary   Optional request header the variance is keyed on.
	 * @return bool False when headers were already sent, i.e. the edge caches
	 *              could not be told. Callers must then render the variant
	 *              that is safe to share.
	 */
	private function send_page_cache_veto( $reason, $vary = '' ) {
		do_action( 'litespeed_control_set_nocache', $reason );
		if ( headers_sent() ) {
			return false;
		}
		foreach ( \FazCookie\Includes\Cache_Compatibility::NO_STORE_HEADERS as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( '' !== $vary ) {
			header( 'Vary: ' . $vary, false );
		}
		\FazCookie\Includes\Cache_Compatibility::define_no_cache_constants();
		return true;
	}

	/**
	 * Keep a render that follows THIS visitor's consent state out of every
	 * shared cache, or make the render one that is safe to share.
	 *
	 * get_blocked_categories() reads three things from the request: the
	 * consent cookie, Sec-GPC and the Do-Not-Sell cookie. Its output is only
	 * visitor-invariant under Cache Compatibility Mode or the strict-shell
	 * bootstrap, and Cache Compatibility pauses itself while Geo-Targeting is
	 * on (the default) — while send_geo_cache_headers() vetoes the cache only
	 * when the output varies by COUNTRY, which it cannot on a site with no
	 * country source. So on most installs a returning visitor who had accepted
	 * marketing, arriving on a page-cache MISS, produced a page with the
	 * trackers server-side EXECUTABLE, the page cache stored it, and every
	 * later visitor — first visits under GDPR included — was served those
	 * trackers before any consent. No client code can undo a script that has
	 * already run. The mirror case is milder but real: a Sec-GPC or Do Not Sell
	 * MISS stored a page with the sell/share categories blocked for everyone.
	 *
	 * The baseline is what a first visit with no signal renders: that is the
	 * one copy every visitor may share, because script.js releases from it
	 * whatever the visitor's own cookie grants. Whenever the visitor's state
	 * yields a different block set (or carries per-service decisions), the
	 * response gets the full page-cache veto before any output. If the veto
	 * cannot be installed — headers already sent, or a publisher who filtered
	 * it off — the render falls back to the shareable form instead (the union
	 * of the baseline and the visitor's own denials; see
	 * get_blocked_categories()), the same way the crawler veto falls back to
	 * rendering the banner.
	 *
	 * Net effect: cache HITs serve the baseline page to everyone and the
	 * runtime unblocks for those who consented; MISSes from visitors whose
	 * state changes the render are not stored.
	 *
	 * @param bool $already_vetoed Whether another veto already keeps this
	 *                             response out of shared caches.
	 * @return string The verdict stored in $visitor_state_veto.
	 */
	private function decide_visitor_state_cache_veto( $already_vetoed = false ) {
		if ( null !== $this->visitor_state_veto ) {
			return $this->visitor_state_veto;
		}
		if ( $this->is_cache_compatibility_enabled() || $this->is_geo_bootstrap_cache_active() ) {
			$this->visitor_state_veto = 'invariant';
			return $this->visitor_state_veto;
		}
		if ( ! $this->visitor_state_changes_render() ) {
			$this->visitor_state_veto = 'baseline';
			return $this->visitor_state_veto;
		}
		if ( $already_vetoed ) {
			$this->visitor_state_veto = 'vetoed';
			return $this->visitor_state_veto;
		}
		/**
		 * Whether a render that follows the visitor's consent state may be kept
		 * out of shared page caches.
		 *
		 * Returning false keeps every page cacheable and makes the server render
		 * the shareable form for every visitor instead: nothing granted beyond
		 * a first visit is released server-side (script.js releases it from the
		 * consent cookie), every denial is still honoured.
		 *
		 * @since 1.34.2
		 * @param bool $veto Default true.
		 */
		$wanted = (bool) apply_filters( 'faz_veto_cache_for_visitor_state', true );
		$this->visitor_state_veto = ( $wanted && $this->send_page_cache_veto( 'FAZ Cookie Manager visitor-specific consent render' ) )
			? 'vetoed'
			: 'fallback';
		return $this->visitor_state_veto;
	}

	/**
	 * Whether the visitor's consent cookie, Sec-GPC or Do-Not-Sell cookie makes
	 * the server-side blocking differ from a first visit.
	 *
	 * Compared on the computed block set, not on the presence of a cookie: a
	 * GDPR visitor who rejected everything, or a GPC signal under an opt-in
	 * law (sell/share already blocked), renders exactly the baseline, and that
	 * page may be cached like any other.
	 *
	 * @return bool
	 */
	private function visitor_state_changes_render() {
		$consent = $this->get_scoped_valid_consent_cookie();
		$gpc     = $this->request_has_gpc();
		$dnsmpi  = $this->request_has_dnsmpi();
		if ( '' === $consent && ! $gpc && ! $dnsmpi ) {
			return false;
		}
		$categories = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items();
		$unused     = array();
		$actual     = $this->compute_blocked_categories( $categories, $consent, $gpc, $dnsmpi, $unused );
		$baseline   = $this->compute_blocked_categories( $categories, '', false, false, $unused );
		// Both renders park the opt-out law's sale/sharing categories (see
		// sale_share_parked_by_default()), so they are compared with it: a
		// GPC, Do Not Sell or opted-out visitor then renders the baseline.
		$parked = $this->sale_share_parked_by_default( $categories );
		if ( ! empty( $parked ) ) {
			$actual   = $this->union_blocked_categories( $categories, $actual, $parked );
			$baseline = $this->union_blocked_categories( $categories, $baseline, $parked );
		}
		if ( $actual !== $baseline ) {
			return true;
		}
		// svc.* decisions move individual embeds across the category verdict
		// (a YES releases one in a blocked category, a NO holds one in an
		// allowed category). A first visit has none.
		if ( '' !== $consent ) {
			$settings = $this->get_faz_settings();
			if ( ! empty( $settings['banner_control']['per_service_consent'] )
				&& preg_match( '/(?:^|,)svc\.[a-z0-9_-]+:(?:yes|no)(?=,|$)/', $consent ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the banner may actually be withheld from this crawler.
	 *
	 * Suppression and the cache veto are one decision, not two: the render may
	 * only differ for a crawler if this response is also kept out of the shared
	 * cache. When the veto cannot be installed, the banner renders.
	 *
	 * @return bool
	 */
	private function bot_suppression_applies() {
		if ( ! $this->is_bot_variant_render() ) {
			return false;
		}
		return $this->veto_page_cache_for_bot_variance();
	}

	/**
	 * Whether the opt-in cache-safe jurisdiction bootstrap is active.
	 *
	 * The cached HTML is always the most-protective GDPR shell. script.js makes
	 * a public, no-store request for the visitor's live jurisdiction before it
	 * mounts the banner or runs its first unblock pass. A failed/slow request
	 * leaves the strict shell untouched, so every failure direction is safe.
	 *
	 * Readiness requires: explicit publisher opt-in, the runtime enabled, a
	 * loadable strict fallback ruleset, an active globally-applicable GDPR
	 * banner for the cached shell, and no output dimensions that the bootstrap
	 * endpoint does not yet replace (IAB TCF, country-language fallback, global
	 * no-banner targeting, or country-dependent banner rows).
	 *
	 * The rendered output may vary by NOTHING except the resolved law:
	 *     IAB TCF off, no country→language fallback, no geo-targeting
	 *     "no banner outside targets", no country-targeted banner rows. Those
	 *     dimensions need additional client payloads before this gate can cover
	 *     them safely. When active, the shell is made invariant elsewhere:
	 * get_blocked_categories()/get_service_consent() ignore the visitor's
	 * consent state (client-side JS applies it), the A/B split and bot-skip
	 * are suppressed, and get_runtime_ruleset() always returns the explicit
	 * most-protective fallback.
	 *
	 * @since 1.27.0
	 * @return bool
	 */
	private function is_geo_bootstrap_cache_active() {
		if ( null !== $this->geo_bootstrap_active_cache ) {
			return $this->geo_bootstrap_active_cache;
		}
		$status = self::get_geo_bootstrap_status( $this->get_faz_settings() );

		$this->geo_bootstrap_active_cache =
			! empty( $status['active'] )
			&& ! $this->is_cache_compatibility_enabled()
			&& false === apply_filters( 'faz_is_amp_request', false );
		return $this->geo_bootstrap_active_cache;
	}

	/**
	 * Explain whether the strict-shell bootstrap can safely own normal pages.
	 *
	 * This is the single request-independent readiness gate used by both the
	 * frontend and the admin status endpoint. A requested optimisation never
	 * weakens enforcement: any unsupported dimension returns `active=false`, so
	 * send_geo_cache_headers() and the FlyingPress bridge retain their no-cache
	 * veto. AMP remains a request-specific exclusion in
	 * is_geo_bootstrap_cache_active().
	 *
	 * The legacy `faz_cache_vary_by_law` opt-in is preserved. The explicit
	 * `faz_cache_geo_bootstrap` filter runs last and can still override the saved
	 * UI setting for integrations that managed this feature in code.
	 *
	 * @since 1.28.0
	 * @param array|null $settings Sanitized settings, or null to read them.
	 * @return array{requested:bool,active:bool,reason:string}
	 */
	public static function get_geo_bootstrap_status( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'faz_settings', array() );
		}
		$stored_opt_in = ! empty( $settings['geolocation']['cache_geo_bootstrap'] );
		$legacy_opt_in = (bool) apply_filters( 'faz_cache_vary_by_law', false );
		$requested     = (bool) apply_filters( 'faz_cache_geo_bootstrap', $stored_opt_in || $legacy_opt_in );
		if ( ! $requested ) {
			return array( 'requested' => false, 'active' => false, 'reason' => 'disabled' );
		}
		if ( ! class_exists( Geo_Runtime::class ) || ! Geo_Runtime::is_enabled() ) {
			return array( 'requested' => true, 'active' => false, 'reason' => 'enforcement_disabled' );
		}

		if (
			function_exists( 'faz_i18n_is_multilingual' )
			&& ! faz_i18n_is_multilingual()
			&& apply_filters( 'faz_use_country_language_fallback', false )
		) {
			return array( 'requested' => true, 'active' => false, 'reason' => 'country_language' );
		}
		if ( ! empty( $settings['iab']['enabled'] ) ) {
			return array( 'requested' => true, 'active' => false, 'reason' => 'iab' );
		}
		if (
			! empty( $settings['geolocation']['geo_targeting'] )
			&& isset( $settings['geolocation']['default_behavior'] )
			&& 'no_banner' === $settings['geolocation']['default_behavior']
		) {
			return array( 'requested' => true, 'active' => false, 'reason' => 'no_banner' );
		}

		try {
			$controller = Controller::get_instance();
			if ( $controller->has_country_dependent_banners() ) {
				return array( 'requested' => true, 'active' => false, 'reason' => 'country_banners' );
			}
			$has_strict_banner = $controller->has_active_banner_for_law( 'gdpr', '' );
		} catch ( \Throwable $e ) {
			return array( 'requested' => true, 'active' => false, 'reason' => 'missing_gdpr_banner' );
		}
		if ( ! $has_strict_banner ) {
			return array( 'requested' => true, 'active' => false, 'reason' => 'missing_gdpr_banner' );
		}
		if ( apply_filters( 'faz_country_dependent_banner_output', false, $settings ) ) {
			return array( 'requested' => true, 'active' => false, 'reason' => 'custom_output' );
		}
		if ( null === self::get_geo_bootstrap_strict_ruleset() ) {
			return array( 'requested' => true, 'active' => false, 'reason' => 'missing_strict_ruleset' );
		}

		return array( 'requested' => true, 'active' => true, 'reason' => 'ready' );
	}

	/**
	 * Load the canonical most-protective ruleset for the cached shell.
	 *
	 * This deliberately bypasses Geo_Runtime::resolve_for_country( '' ). An
	 * empty country is not an authoritative override in Geo_Routing: its
	 * detector retains the request's real country, which can resolve to CCPA.
	 * A cache shell must never inherit request geography.
	 *
	 * @since 1.27.0
	 * @return array|null
	 */
	private static function get_geo_bootstrap_strict_ruleset() {
		$loader_class = '\\FazCookie\\Admin\\Modules\\Geo_Routing\\Includes\\Ruleset_Loader';
		if ( ! class_exists( $loader_class ) || ! method_exists( $loader_class, 'get_instance' ) ) {
			return null;
		}
		try {
			$ruleset = $loader_class::get_instance()->load_ruleset( 'fallback-gdpr-most-protective' );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $ruleset ) || 'gdpr' !== Geo_Runtime::model_to_law( $ruleset ) ) {
			return null;
		}
		return $ruleset;
	}

	/**
	 * FlyingPress `flying_press_is_cacheable` bridge: veto page caching when
	 * the rendered output varies by visitor country.
	 *
	 * Mirrors send_geo_cache_headers()' gating exactly, so FlyingPress skips
	 * caching precisely the requests every other supported page cache already
	 * bypasses via DONOTCACHEPAGE / no-store (which FlyingPress ignores).
	 * Under Cache Compatibility Mode is_country_dependent_output() is false,
	 * so the page stays cacheable — same behaviour as the other caches.
	 *
	 * @param bool $is_cacheable FlyingPress's current verdict for the request.
	 * @return bool
	 */
	public function flying_press_is_cacheable( $is_cacheable ) {
		// Never overturn an exclusion someone else already decided.
		if ( false === $is_cacheable ) {
			return $is_cacheable;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || true === faz_disable_banner() ) {
			return $is_cacheable;
		}
		if ( ! faz_is_front_end_request() ) {
			return $is_cacheable;
		}
		if ( $this->is_banner_disabled_by_settings() ) {
			return $is_cacheable;
		}
		// FlyingPress reads neither DONOTCACHEPAGE nor `Cache-Control: no-store`,
		// so the crawler veto has to be spoken in its own language too. Asked as
		// the plain variance question rather than through
		// bot_suppression_applies(): this filter runs while output is being
		// assembled, when the header half of the veto is no longer available,
		// and returning false here IS the veto.
		if ( $this->is_bot_variant_render() ) {
			return false;
		}
		// The page follows this visitor's consent state (decided on
		// send_headers, see decide_visitor_state_cache_veto()). A 'fallback'
		// verdict rendered the shareable form, which FlyingPress may keep.
		if ( 'vetoed' === $this->visitor_state_veto || $this->ab_test_cache_vetoed ) {
			return false;
		}
		if ( $this->is_country_dependent_output() ) {
			// The jurisdiction bootstrap renders one strict, visitor-invariant
			// document and resolves the live law through a no-store REST request
			// in the browser. FlyingPress may cache that shell normally.
			if ( $this->is_geo_bootstrap_cache_active() ) {
				return $is_cacheable;
			}
			return false;
		}
		return $is_cacheable;
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
		// Install the crawler veto here, while nothing has been sent yet. It is
		// independent of the country dimension below: a site with one global
		// banner and no geo targeting still renders differently for a crawler,
		// and that difference is exactly what a User-Agent-blind page cache
		// freezes for everyone. Resolving it on `send_headers` also means the
		// verdict is already memoised by the time wp_head asks.
		$this->bot_suppression_applies();

		// The strict shell is identical for every visitor and contains no
		// executable optional resources. Do not emit Vary/Set-Cookie/no-cache:
		// every compliant page cache and CDN should share this one document.
		$country_vetoed = false;
		if ( $this->is_country_dependent_output() && ! headers_sent() && ! $this->is_geo_bootstrap_cache_active() ) {
			$this->maybe_disable_country_page_cache();
			$country_vetoed = $this->send_page_cache_veto(
				'FAZ country-dependent banner',
				apply_filters( 'faz_trust_cf_ipcountry_header', false ) ? 'CF-IPCountry' : ''
			);
		}

		// Then the visitor-state dimension, which exists on every install —
		// including the ones with no country source, where nothing above fires.
		// Decided here, before any output, so the render that follows
		// (get_blocked_categories(), get_service_consent()) reads one verdict.
		$this->decide_visitor_state_cache_veto( $country_vetoed || $this->bot_variance_cache_vetoed || $this->ab_test_cache_vetoed );
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
	 * The region table itself lives in faz_region_map() (includes/class-utils.php)
	 * and the matching is done by faz_country_in_regions(). Only the
	 * `faz_is_target_region` filter is local to this call site — AMP pages
	 * resolve their target regions without it, and moving it into the shared
	 * helper would silently extend the filter to a surface that never had it.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code.
	 * @param array  $regions      List of region keys (e.g. 'eu', 'uk') or direct country codes.
	 * @return bool
	 */
	private function is_country_in_regions( $country_code, $regions ) {
		$country_code = strtoupper( (string) $country_code );
		$regions      = is_array( $regions ) ? $regions : (array) $regions;
		if ( faz_country_in_regions( $country_code, $regions ) ) {
			return true;
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
		// Banner HTML is the actual visible UI. PMP members are deliberately not
		// suppressed here: script.js closes the notice from action:auto while
		// keeping the revisit preference control rendered and reachable.
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
		// faz_request_is_https(), not is_ssl(): behind a TLS-terminating proxy
		// is_ssl() is false on an https page, so this repair — which exists for
		// precisely that deployment — never ran on the hosts that need it.
		if ( faz_request_is_https() && defined( 'FAZ_PLUGIN_URL' ) ) {
			$http_url = str_replace( 'https://', 'http://', FAZ_PLUGIN_URL );
			if ( strpos( $html, $http_url ) !== false ) {
				$https_url = set_url_scheme( FAZ_PLUGIN_URL, 'https' );
				$html      = str_replace( $http_url, $https_url, $html );

				// Auto-repair the cached template so subsequent requests
				// skip this replacement entirely — but ONLY on a server-verified
				// HTTPS signal.
				//
				// The rewrite above is per-request and harmless: at worst one
				// visitor gets https URLs on a page that did not need them. This
				// branch is different — it writes the rewritten template back to
				// wp_options, where it survives for every later visitor.
				//
				// faz_request_is_https() accepts X-Forwarded-Proto, which is
				// client-controlled on any site NOT behind a proxy that strips it.
				// Widening the outer gate to it therefore handed an unauthenticated
				// caller a persistent state change: one
				// `curl -H 'X-Forwarded-Proto: https'` against a plain-HTTP site
				// rewrites its cached banner to a scheme the server does not serve,
				// for everyone, until the cache is regenerated. is_ssl() and the
				// stored siteurl are both server-controlled, so the durable write
				// keeps the narrower signal it always had.
				$server_verified_https = is_ssl()
					|| 0 === stripos( (string) get_option( 'siteurl' ), 'https://' );
				$cache_key = apply_filters( 'faz_banner_template_cache_key', 'faz_banner_template' );
				$stored    = $server_verified_https ? get_option( $cache_key, array() ) : false;
				if ( is_array( $stored ) ) {
					$repaired = false;
					foreach ( $stored as $lang => $tpl ) {
						if ( isset( $tpl['html'] ) && strpos( $tpl['html'], $http_url ) !== false ) {
							$stored[ $lang ]['html'] = str_replace( $http_url, $https_url, $tpl['html'] );
							$repaired = true;
						}
					}
					if ( $repaired ) {
						// autoload=false — keep the multi-KB template blob out of alloptions.
						update_option( $cache_key, $stored, false );
					}
				}
			}
		}

		// Same class of defect one level up: the cached HTML carries the plugin
		// origin (scheme + host + port) that was current when it was generated,
		// so a site restored at a different address keeps requesting assets from
		// the old one. A backup restore rewrites the siteurl row directly, so
		// the update_option_siteurl hook in the bootstrap never fires — this is
		// the net that catches it. Issue #195: a WPVivid restore of a localhost
		// build left the live site asking https://localhost for the revisit
		// icon, which browsers surface as a local-network access prompt.
		$html = $this->repair_stale_asset_origin( $html );

		// `<script type="text/template">` is the W3C-recommended way to
		// embed inert HTML templates in the page (browsers do NOT execute
		// it — `type` is not `text/javascript` or `module`). The banner
		// frontend mounts this template into the DOM at runtime via
		// `_fazInjectBannerHtml()` in script.js. wp_enqueue_script()
		// cannot model this: there is no JS to load, only an HTML payload
		// that must appear inline so the renderer finds it synchronously.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- inert HTML template (type=text/template is non-executable); see comment above.
		echo '<script id="fazBannerTemplate" type="text/template">';
		echo self::escape_template_end_tags( wp_kses( $html, faz_allowed_html() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already through wp_kses(); only `</` is rewritten.
		echo '</script>';
	}

	/**
	 * Write every `</` in the banner template as `<\/`.
	 *
	 * The template travels inside a <script type="text/template">. HTML5 ends
	 * that element only at `</script`, but an HTML4 parser — libxml, i.e. PHP's
	 * DOMDocument::loadHTML() — ends a script at any `</` followed by a letter
	 * and then throws the stray end tags away. Optimisation plugins that
	 * rewrite the whole page through DOMDocument (WPSpeed's image optimiser,
	 * on by default) therefore delivered the template with every closing tag
	 * removed: the browser nested the whole banner inside the title and the
	 * consent bar rendered 0 px tall, with no buttons. `<\/` is plain text to
	 * both parsers; _fazReadBannerTemplate() in script.js turns it back.
	 *
	 * @param string $html Sanitised template markup.
	 * @return string
	 */
	public static function escape_template_end_tags( $html ) {
		return str_replace( '</', '<\/', (string) $html );
	}

	/**
	 * Rewrite plugin-asset URLs left over from a previous site address.
	 *
	 * The cached banner HTML embeds absolute URLs to this plugin's own assets
	 * (the revisit icon, the close icon). They are generated from
	 * FAZ_PLUGIN_URL, so they carry whatever origin the site had when the
	 * template was built. Restore that database at a different address —
	 * localhost build → live server, staging → production — and the cache
	 * keeps pointing at the old origin: the browser then issues a
	 * cross-origin request for the icon, which for a localhost origin trips
	 * the private-network access prompt (issue #195).
	 *
	 * Only URLs whose path matches this plugin's own directory are touched,
	 * and only when their origin differs from the current one, so a CDN or
	 * asset-rewriting layer that already produced a different (and correct)
	 * origin is left alone: FAZ_PLUGIN_URL reflects that rewrite too, so the
	 * origins match and nothing happens.
	 *
	 * Repairs the stored option as well, so the work happens once rather than
	 * on every request.
	 *
	 * @param string $html Cached banner HTML.
	 * @return string HTML with a current asset origin.
	 */
	private function repair_stale_asset_origin( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! defined( 'FAZ_PLUGIN_URL' ) ) {
			return (string) $html;
		}

		$current = wp_parse_url( FAZ_PLUGIN_URL );
		if ( empty( $current['scheme'] ) || empty( $current['host'] ) || empty( $current['path'] ) ) {
			return $html;
		}
		$current_origin = $current['scheme'] . '://' . $current['host']
			. ( empty( $current['port'] ) ? '' : ':' . $current['port'] );
		$plugin_path    = $current['path'];

		// Collect the distinct origins the cached HTML uses for OUR assets.
		$pattern = '#(https?://[^/"\'\s>]+)' . preg_quote( $plugin_path, '#' ) . '#i';
		if ( ! preg_match_all( $pattern, $html, $matches ) ) {
			return $html;
		}
		$stale = array_values( array_unique( array_diff( $matches[1], array( $current_origin ) ) ) );
		if ( ! $stale ) {
			return $html;
		}

		$rewrite = static function ( $subject ) use ( $stale, $plugin_path, $current_origin ) {
			foreach ( $stale as $origin ) {
				$subject = str_replace( $origin . $plugin_path, $current_origin . $plugin_path, $subject );
			}
			return $subject;
		};

		$html = $rewrite( $html );

		// Persist the repair so later requests skip this entirely.
		$cache_key = apply_filters( 'faz_banner_template_cache_key', 'faz_banner_template' );
		$stored    = get_option( $cache_key, array() );
		if ( is_array( $stored ) ) {
			$repaired = false;
			foreach ( $stored as $lang => $tpl ) {
				if ( ! isset( $tpl['html'] ) || ! is_string( $tpl['html'] ) ) {
					continue;
				}
				$fixed = $rewrite( $tpl['html'] );
				if ( $fixed !== $tpl['html'] ) {
					$stored[ $lang ]['html'] = $fixed;
					$repaired                = true;
				}
			}
			// Same rule as the mixed-content repair above, and for the same
			// reason — this is the SIBLING branch I fixed there and left here.
			// $current_origin derives from FAZ_PLUGIN_URL, whose scheme now
			// follows faz_request_is_https(), which trusts X-Forwarded-Proto.
			// That header is client-controlled on any site not behind a proxy
			// that strips it, so persisting an origin derived from it hands an
			// unauthenticated caller a durable rewrite of every visitor's banner.
			// The per-request rewrite above keeps the wide signal; the DB write
			// requires a server-controlled one.
			$server_verified_https = is_ssl()
				|| 0 === stripos( (string) get_option( 'siteurl' ), 'https://' );
			$forged_scheme_only = ( 'https' === $current['scheme'] ) && ! $server_verified_https;
			if ( $repaired && ! $forged_scheme_only ) {
				// autoload=false — keep the multi-KB template blob out of alloptions.
				update_option( $cache_key, $stored, false );
			}
		}

		return $html;
	}

	/**
	 * Get gcm data
	 *
	 * @return array
	 */
	/**
	 * Resolved geo ruleset for the current visitor, or null.
	 *
	 * Enabled by the Geo-Targeting setting and overridable through the
	 * `faz_geo_ruleset_runtime` filter. The resolved ruleset (model +
	 * default_categories + CMv2) drives the
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
		if ( $this->is_geo_bootstrap_cache_active() ) {
			// Never call resolve_for_country( '' ) here: empty is not an
			// authoritative override and the detector would re-use the request's
			// country. The cached document must be strict regardless of who (or
			// which cache-warmer) produced it.
			return self::get_geo_bootstrap_strict_ruleset();
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
		// dataLayer.push({…}): those are the Google code-signatures
		// is_gcm_managed_script() looks for, and the exemption they drive is
		// meant for the stack that FOLLOWS this default, not for the default
		// itself. The tag's survival in the output buffer does not rest on its
		// body, though: it is printed with an own-handle id (see
		// gcm_default_script_id()), so process_script_tag() returns it untouched
		// before any provider pattern is consulted — an admin custom rule or a
		// `faz_blocking_rules` code signature such as `gtag(` that happens to
		// match this body cannot re-type it to text/plain while the gtag stack
		// it was written for stays exempted.
		$dl    = wp_json_encode( $this->gcm_datalayer_name() );
		$js    = 'window[' . $dl . ']=window[' . $dl . ']||[];function gtag(){window[' . $dl . '].push(arguments);}';
		$js   .= "gtag('consent','default'," . wp_json_encode( $default ) . ');';
		if ( $npa_fallback ) {
			// ad_storage is denied in the baseline → request non-personalized ads.
			$js .= "gtag('set',{npa:1});";
		}
		$js .= "gtag('set','ads_data_redaction'," . ( $redaction ? 'true' : 'false' ) . ');';
		$js .= "gtag('set','url_passthrough'," . ( $passthrough ? 'true' : 'false' ) . ');';

		// Record the emission BEFORE printing: the Advanced-mode gtag exemption
		// in process_script_tag() / inline_script_block_category() only applies
		// while this default is in front of the exempted stack in the same
		// response (see $gcm_default_emitted).
		$this->gcm_default_emitted = true;

		// The same optimizer opt-outs every other FAZ script carries
		// (tag_own_scripts_nooptimize / tag_own_inline_attributes_nooptimize).
		// This tag is echoed, so neither filter sees it, and it is the one that
		// matters most: a delay-until-interaction or defer pass (LiteSpeed Guest
		// Mode, WP Rocket, Autoptimize, Cloudflare Rocket Loader) would move the
		// denied `consent default` behind the gtag snippets it must precede,
		// and Google treats a tag that fires without a default as granted.
		$hints = apply_filters( 'faz_auto_exclude_cache_plugins', true )
			? ' data-no-defer="1" data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-ao-skip="1" data-noptimize="1"'
			: '';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline JS bootstrap; the id is a plugin constant passed through esc_attr, $hints is a literal, and every interpolated value is a bool/int or wp_json_encode of plugin-controlled constants, no user input.
		echo '<script id="' . esc_attr( $this->gcm_default_script_id() ) . '"' . $hints . '>' . $js . "</script>\n";
	}

	/**
	 * The id printed on the Advanced Consent Mode `consent default` tag.
	 *
	 * Shaped like a core inline-script id (`<handle>-js-before`) on an own
	 * handle, so is_own_inline_script_id() recognises the tag as the plugin's
	 * and process_script_tag() / inline_script_block_category() return it
	 * untouched by construction — the same protection every other FAZ tag
	 * already had. The handle mirrors the gcm.js one (`<plugin>-gcm`) with a
	 * `-default` suffix.
	 *
	 * @return string
	 */
	private function gcm_default_script_id() {
		$base = is_string( $this->plugin_name ) && '' !== $this->plugin_name ? $this->plugin_name : 'faz-cookie-manager';
		return $base . '-gcm-default-js-before';
	}

	/**
	 * Whether the Advanced Consent Mode gtag exemption may apply to a tag on
	 * this request: Advanced mode is on AND the synchronous denied default has
	 * been emitted in this response. Without the default in front of it, an
	 * exempted gtag stack would run with no consent signal at all and Google
	 * treats that as granted, so the answer is no and the stack stays
	 * hard-blocked (Basic-mode behaviour).
	 *
	 * @return bool
	 */
	private function gcm_advanced_exemption_active() {
		return $this->gcm_default_emitted
			&& $this->gcm_settings
			&& $this->gcm_settings->is_advanced_mode();
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
	 * Normalize the consent-cookie lifetime for the active legal model.
	 *
	 * GDPR-family: the Garante's six-month rule is a MAXIMUM, and there is no
	 * legal minimum. A publisher who deliberately re-asks every 30 days is being
	 * MORE protective, so the cap applies and nothing is raised. An earlier
	 * revision of this method imposed a 180-day floor; that silently extended
	 * the stored consent of every install configured below it — restrictive in
	 * the wrong direction — and is not something a consent plugin may do.
	 *
	 * CCPA/CPRA is the one place a floor belongs: §1798.135 bars asking a
	 * consumer who opted out to consent again for at least 12 months, so a
	 * shorter value would re-prompt sooner than the statute allows. The 3650-day
	 * ceiling is the same bound the banner UI advertises — enforced here too,
	 * because a value can reach this method from the REST API, an import or a
	 * direct DB edit without ever passing the admin field.
	 *
	 * Kept as a pure public helper so migrations, integrations and the unit suite
	 * can exercise the same rule used by get_store_data().
	 *
	 * @param string $law             Banner law/model identifier.
	 * @param int    $configured_days Configured lifetime in days.
	 * @return int Effective lifetime in days.
	 */
	public static function normalize_consent_expiry( $law, $configured_days ) {
		$law             = strtolower( (string) $law );
		$configured_days = absint( $configured_days );

		if ( 'ccpa' === $law ) {
			return min( 3650, max( 365, $configured_days ) );
		}

		// A zero/absent configuration still has to produce a usable lifetime;
		// the cap, not a floor, is what the law dictates above that.
		if ( $configured_days < 1 ) {
			return 182;
		}

		return min( 182, $configured_days );
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

		// Age gate (GDPR Art. 8) minimum age. Sanitizer clamps the persisted
		// value to 13-18; default 16. Resolved once here so it feeds both the
		// _ageGate store and the sprintf'd age-confirmation label below.
		$age_min = isset( $settings['age_gate']['min_age'] ) ? absint( $settings['age_gate']['min_age'] ) : 16;

		// Consent-cookie lifetime is normalized at the final runtime boundary so
		// legacy rows, imports and direct API writes cannot bypass the law-aware
		// limits shown in the admin editor.
		$faz_law = $banner->get_law();
		$faz_configured_expiry = isset( $banner_settings['settings']['consentExpiry']['value'] )
			? absint( $banner_settings['settings']['consentExpiry']['value'] )
			: ( 'ccpa' === $faz_law ? 365 : 180 );
		$faz_expiry = self::normalize_consent_expiry( $faz_law, $faz_configured_expiry );

		$geo_bootstrap_active  = $this->is_geo_bootstrap_cache_active();
		$geo_bootstrap_timeout = min( 5000, max( 250, absint( apply_filters( 'faz_geo_bootstrap_timeout_ms', 1500 ) ) ) );
		$providers = array();
		$store     = array(
			'_ipData'       => array(),
			'_assetsURL'    => FAZ_PLUGIN_URL . 'frontend/images/',
			'_publicURL'    => set_url_scheme( get_site_url() ),
			'_expiry'       => $faz_expiry,
			'_categories'   => $this->get_cookie_groups(),
			'_activeLaw'         => $banner->get_law(),
			// WP Consent API regime for this visitor, computed by the SAME method
			// that answers `wp_get_consent_type` server-side. wca.js declares
			// window.wp_consent_type from this, not from _activeLaw: the banner's
			// label is deliberately wrong in the fail-closed geo-runtime branch
			// (an opt-in ruleset served under a kept CCPA banner) and under Cache
			// Compatibility Mode, which is exactly where filter_wp_consent_type()
			// corrects it — a client that re-derived the regime from the label
			// undid that correction for every JS Consent-API consumer.
			'_wpConsentType'     => $this->filter_wp_consent_type( '' ),
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
			// Cache-safe jurisdiction bootstrap. When true, the surrounding page
			// is the strict fallback shell and script.js MUST fetch the live,
			// no-store banner payload before mounting UI or unblocking anything.
			'_geoBootstrap'      => $geo_bootstrap_active,
			'_geoBootstrapTimeout' => $geo_bootstrap_timeout,
			// Server-side fingerprint of the active scope, keyed by
			// wp_salt('auth'). Used by _fazConsentScopeChanged() to detect
			// cookie tampering: a visitor who hand-edits __scope.banner /
			// __scope.law to match the current scope cannot also forge a
			// matching __scope.fp without knowing the salt, so any
			// tampering attempt invalidates and re-prompts. Truncated to
			// 32 hex chars — enough collision resistance for an integrity
			// check, short enough to stay light in the cookie.
			'_srcdocKey'         => self::srcdoc_key(),
			'_cookieNames'       => function_exists( 'faz_consent_cookie_names' ) ? faz_consent_cookie_names() : array(),
			'_siteScopeBound'    => faz_consent_site_bound(),
			'_scopeFingerprint'  => faz_scope_fingerprint( $banner->get_slug(), $banner->get_law() ),
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
				'consent_id' => __( 'Consent ID', 'faz-cookie-manager' ),
				'privacy_region_label'                  => __( 'We value your privacy', 'faz-cookie-manager' ),
				'consent_saved'                         => __( 'Your cookie preferences have been saved.', 'faz-cookie-manager' ),
				'optout_preferences_label'              => __( 'Opt-out Preferences', 'faz-cookie-manager' ),
				'customise_consent_preferences_label'   => __( 'Customise Consent Preferences', 'faz-cookie-manager' ),
				'service_consent_label'                 => __( 'Service consent', 'faz-cookie-manager' ),
				'cookies'                               => __( 'Cookies', 'faz-cookie-manager' ),
				'cookie_consent_label'                  => __( 'Cookie consent', 'faz-cookie-manager' ),
				'vendor_consent_label'                  => __( 'Vendor consent', 'faz-cookie-manager' ),
				'third_party_cookie_note'               => __( 'These cookies are set by the embedded service on its own domain and are controlled by allowing or blocking the embed above — they cannot be removed individually.', 'faz-cookie-manager' ),
				// Age gate (GDPR Art. 8). The number is sprintf'd server-side so
				// the label is correct per-locale with no JS string concatenation.
				// translators: %d is the minimum digital age of consent.
				'age_confirm_label'                     => sprintf( __( 'I confirm I am at least %d years old', 'faz-cookie-manager' ), $age_min ),
				'age_confirm_error'                     => __( 'Please confirm you meet the minimum age to accept optional cookies.', 'faz-cookie-manager' ),
				// Fallback for the opt-out countdown subtext. script.js normally
				// reuses the text the template already rendered (translated), but
				// falls back when that element is empty — which happened, and put
				// an English sentence under an Italian success message on a
				// non-English site.
				// translators: %d is a number of seconds.
				'optout_autoclose_countdown'            => sprintf( __( 'Banner closes automatically in %d s...', 'faz-cookie-manager' ), 0 ),
				// IAB TCF section of the preference centre, including the Right-to-Object
				// controls: a legal notice to the visitor, so translated like the rest.
				'iab_consent'                           => __( 'Consent', 'faz-cookie-manager' ),
				'iab_cookie_retention'                  => __( 'Cookie retention', 'faz-cookie-manager' ),
				'iab_features'                          => __( 'Features', 'faz-cookie-manager' ),
				'iab_legitimate_interest'               => __( 'Legitimate Interest', 'faz-cookie-manager' ),
				'iab_legitimate_interest_note'          => __( 'Some vendors process your data on the basis of legitimate interest rather than consent. You have the right to object: switch a purpose off to object to that processing.', 'faz-cookie-manager' ),
				'iab_vendor_consent'                    => __( 'IAB Vendor Consent', 'faz-cookie-manager' ),
				'iab_vendor_legitimate_interest_label'  => __( 'Allow processing based on legitimate interest', 'faz-cookie-manager' ),
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
			foreach ( $service['patterns'] as $pattern ) {
				// Per pattern, not per service, so the CAPTCHA reclassification
				// below resolves exactly as it does in get_provider_category_map().
				$category = $this->known_provider_category( $service['category'], $pattern, $valid_categories );
				if ( 'necessary' === $category || ! in_array( $category, $valid_categories, true ) ) {
					continue;
				}
				if ( ! isset( $this->providers[ $pattern ] ) ) {
					$this->providers[ $pattern ] = array( $category );
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

		// 3. Admin custom blocking rules (Settings → Script Blocking). Tracked
		// as EXPLICIT rules, exactly like get_provider_category_map() does
		// with $custom_patterns, so the gateway / Instagram Feed exemption
		// below can never drop them from the client list while the server map
		// keeps them (admin intent wins on both layers or on neither).
		$custom_rules    = isset( $settings['script_blocking']['custom_rules'] ) ? $settings['script_blocking']['custom_rules'] : array();
		$client_explicit = array();
		foreach ( $custom_rules as $rule ) {
			$pattern  = isset( $rule['pattern'] ) ? $rule['pattern'] : '';
			$category = isset( $rule['category'] ) ? $rule['category'] : '';
			if ( ! empty( $pattern ) && ! empty( $category ) && in_array( $category, $valid_categories, true ) ) {
				$this->providers[ $pattern ]   = array( $category );
				$client_explicit[ $pattern ] = true;
			}
		}

		// 4. Developer filter. Snapshot first so a rule the filter ADDS counts
		// as explicit too (mirrors the $pre_filter_map snapshot on the server).
		$pre_filter_client = $this->providers;
		$this->providers   = apply_filters( 'faz_blocking_rules_client', $this->providers );
		foreach ( array_keys( $this->providers ) as $pattern ) {
			if ( ! array_key_exists( $pattern, $pre_filter_client ) ) {
				$client_explicit[ $pattern ] = true;
			}
		}

		// Some payment SDKs are required outside checkout too (e.g. Stripe express buttons).
		// The Instagram Feed exemption rides along here for the same reason it
		// exists in get_provider_category_map(): the client-side interceptor is a
		// SEPARATE list built from $this->providers, so exempting only the server
		// map would leave the browser re-blocking the very script the server just
		// allowed — the pattern reaching the page in _fazConfig is enough to do it.
		// Explicit rules are carved out (see apply_client_provider_exemptions()).
		$client_exemptions        = $this->apply_client_provider_exemptions( $this->providers, $client_explicit );
		$this->providers          = $client_exemptions['providers'];
		$client_gateway_whitelist = $client_exemptions['whitelist'];

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
		$user_whitelist = $this->get_user_whitelist_patterns();
		// Plus the always-allowed gateway tokens whenever an explicit rule
		// overlapped one and was therefore KEPT on the client list (see
		// apply_client_provider_exemptions()). The server releases a gateway's
		// own urls through get_whitelist(), not by dropping the rule, so the
		// client needs the same tokens to resolve them the same way. Empty on
		// every install without such a rule: the payload is unchanged there.
		// The anti-spam endpoints the server exempts must be exempt on the client
		// too, or a CAPTCHA a form loads after DOMContentLoaded is held by the
		// runtime that the server just let through (#343). Off, they are judged
		// on both sides like any other provider.
		$client_antispam = $this->antispam_allowed_before_consent() ? self::ANTISPAM_CHALLENGE_PATTERNS : array();
		$store['_userWhitelist'] = array_values( array_unique( array_merge( $user_whitelist, $client_gateway_whitelist, $client_antispam ) ) );
		// Non-filterable commerce configuration invariant mirrored by the
		// runtime MutationObserver/createElement interceptor. Keep it separate
		// from the editable whitelist so an empty Settings field cannot remove
		// the protection and diagnostics can distinguish product policy from a
		// publisher exception.
		$store['_strictlyNecessaryScripts'] = self::strictly_necessary_script_handles();

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

		// Age gate (GDPR Art. 8). Site-wide (not per-visitor/geo), so it is safe
		// to bake into full-page caches; the checkbox is injected client-side
		// from this JSON, so it is cache-safe and never baked into cached markup.
		$age_gate = array(
			'enabled' => ! empty( $settings['age_gate']['enabled'] ),
			'minAge'  => $age_min,
		);
		$store['_ageGate'] = $age_gate;

		// GTM Data Layer toggle.
		$store['_gtmDataLayer'] = ! empty( $settings['banner_control']['gtm_datalayer'] );
		// Anti-adblock banner resilience toggle. Emitted ONLY when true so the
		// _fazConfig blob stays byte-identical for every default-off install
		// (no behaviour change, no cache-diff). Site-wide static flag, safe
		// under cache-compatibility mode.
		if ( ! empty( $settings['banner_control']['adblock_resilience'] ) ) {
			$store['_adblockResilience'] = true;
		}
		// Advanced Consent Mode (#165): mirror the server-side gtag exemption to
		// the client blocker so dynamically-injected Google tags (which bypass
		// the output buffer) are also allowed to load before consent.
		$store['_gcmAdvanced'] = $this->gcm_settings ? (bool) $this->gcm_settings->is_advanced_mode() : false;

		// IAB vendor data for preference center.
		// Mirror the asset-enqueue gate above: the IAB/TCF preference UI is
		// only surfaced when a registered CMP ID (>= 2) is configured AND the
		// Global Vendor List is stored. CMP IDs 0 and 1 are reserved/invalid in
		// the IAB GVL, so emitting the vendor list and purpose toggles without
		// one would show a TCF UI that can never produce a valid TC string
		// (tcf-cmp.js is not enqueued in that state); without a GVL there is
		// nothing to disclose and tcf-cmp.js is not enqueued either. Keep both
		// gates in sync.
		$gvl         = Gvl::get_instance();
		$iab_enabled = (bool) $this->settings->get( 'iab', 'enabled' )
			&& absint( $this->settings->get( 'iab', 'cmp_id' ) ) >= 2
			&& $gvl->has_data();
		$store['_iabEnabled'] = $iab_enabled;
		if ( $iab_enabled ) {
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
	 * Wider net than `is_banner_disabled_by_settings()`: also covers a runtime
	 * geo fallback for which blocking must remain active without showing a
	 * mismatched regional notice.
	 *
	 * PMP members are intentionally NOT suppressed here. Their `action:auto`
	 * cookie makes script.js hide the first-layer notice immediately, while the
	 * rendered revisit control remains available so changing optional purposes
	 * is as easy as the normal consent flow.
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

		// hide_from_bots: mirror the enqueue_scripts() gate so a crawler gets
		// no banner container markup or placeholder CSS either — the setting
		// promises "cleaner HTML to crawlers", and skipping only the script
		// left the full (visually hidden) container in the bot's copy. The
		// shared predicate carries the cache conditions; see
		// veto_page_cache_for_bot_variance().
		if ( $this->bot_suppression_applies() ) {
			return true;
		}

		// Runtime geo-routing opt-in fallback with no matching banner: keep
		// blocking active (template loaded) but hide the mismatched opt-out UI.
		if ( $this->faz_law_fallback_suppress ) {
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
		// A feed, robots.txt or a trackback is not a page a visitor consents
		// on — see the rationale on filter_content_blocking(). These pass
		// through template_redirect, so the guards above do not catch them.
		if ( faz_is_machine_readable_request() ) {
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
	 * Process the output buffer: find scripts, iframes, images, media, CSS
	 * links and inline CSS that belong to blocked providers and neutralise them.
	 *
	 * - Scripts: type → text/plain, add data-faz-category.
	 * - Iframes: src → data-faz-src, add data-faz-category.
	 * - Images:  src → data-faz-src, srcset → data-faz-srcset, add data-faz-category.
	 * - Media:   <video|audio|source|embed|track|input type=image> src → data-faz-src,
	 *            poster → data-faz-poster, <object> data → data-faz-data.
	 * - CSS:     href → data-faz-href, add data-faz-category; <style> text and
	 *            `style` attributes neutralised in place (original in
	 *            data-faz-css / data-faz-style) or parked whole.
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	public function process_output_buffer( $html ) {
		// ob_start callbacks may receive null in edge cases (nested buffers, abort).
		// Cast to string to satisfy PHP 8.1+ strict typing for strpos().
		$html = (string) $html;
		if ( '' === $html ) {
			return $this->refresh_srcdoc_proofs( $html );
		}

		$blocked_categories = $this->get_blocked_categories();
		$has_service_consent = ! empty( $this->get_service_consent() );
		if ( empty( $blocked_categories ) && ! $has_service_consent ) {
			return $this->refresh_srcdoc_proofs( $html );
		}

		$providers = $this->get_provider_category_map();
		if ( empty( $providers ) ) {
			return $this->refresh_srcdoc_proofs( $html );
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

		// preg_replace_callback returns null for the whole document on a PCRE
		// error (backtrack or recursion limit, JIT stack). Serving the buffer
		// as it was then left every element that pass judges executable, the
		// trackers included, so a failed pass is re-run over a linear walk
		// with the same callback: run_markup_pass() says why and how, and the
		// `faz_output_buffer_fail_closed` filter restores the old skip. The
		// kinds that failed are logged once, at the end.
		$failed_passes = array();

		// Block tracking pixel <img> inside <noscript> FIRST — BEFORE the stash.
		// Meta Pixel, GA, Adobe Analytics etc. embed `<img>` beacons inside
		// `<noscript>` blocks for JS-disabled visitors; those still need to be
		// consent-gated server-side. process_noscript_tag rewrites the
		// `<noscript>` block's inner content (img src → data-faz-src + data-faz-
		// category) while preserving the enclosing `<noscript>` wrapper, so the
		// stash that follows still sees a well-formed block and protects it
		// from the iframe filter.
		//
		// Single merged pass (was two full-document traversals): each block is
		// pixel-processed AND stashed in the same callback. The ordering inside
		// that callback is what matters — gate first, stash second. Stashing
		// first is what the original two-pass version got wrong, and it made
		// the gating dead code, because by then every `<noscript>` had already
		// become a `__FAZ_NOSCRIPT_STASH_N__` marker with nothing left to match.
		//
		// Stashing protects <noscript> content from the script/iframe/link
		// filters below: it only renders when JS is disabled; visitors with JS
		// (>99% case) never see it. Without stashing, an iframe wrapped in
		// <noscript> (e.g. theme's "video fallback for non-JS users") would be
		// rewritten by process_iframe_tag into a consent placeholder that lives
		// forever in the DOM as a 0×0 phantom — invisible to humans, but found
		// by document.querySelector. The phantom collides with
		// `_fazAddPlaceholder()` placeholders for legitimate dynamic iframes
		// and breaks any `.first()`-style locator. Restored after all filters.
		//
		// The pixel processing (Meta Pixel, GA, Adobe Analytics <img> beacons
		// for JS-disabled visitors) must still be consent-gated server-side:
		// process_noscript_tag rewrites the block's inner content (img src →
		// data-faz-src + data-faz-category) but preserves the enclosing
		// <noscript> wrapper, and it is that PROCESSED block that gets stashed
		// and later restored.
		// 0. Every <iframe srcdoc> is lifted out of the buffer BEFORE any other
		// pass runs, and put back verbatim after the last one. The attribute's
		// value is markup, and every pass below is a regex over markup: the
		// script pass re-typed a <script src> inside it, the <img> pass renamed
		// its src, the <link> and <style> passes did the same — each writing a
		// double-quoted attribute INTO a double-quoted value, which ends the
		// value at the first injected quote. The engine then read the tracker's
		// URL as the frame's src and a truncated document as its srcdoc; with a
		// single-quoted srcdoc the inner <img> was delivered parked into a
		// document that has no runtime to restore it.
		//
		// So the srcdoc is judged whole here (a sub-document's fetches are
		// beyond any gate once it is parsed), its token is replaced by a marker
		// no pass can match, and the marker is swapped back at the very end for
		// the ORIGINAL token: `srcdoc=…` when allowed, `data-faz-srcdoc=…` when
		// parked, bytes and quoting untouched either way. Runs ahead of the
		// <noscript> stash as well, because a single-quoted srcdoc can carry a
		// literal <noscript> that the stash would otherwise cut out of the
		// attribute.
		//
		// The opening-tag pattern uses ATTR_WALK, and the reason that walk
		// exists is this pass: walking "anything but a quote, or a quoted run"
		// let the apostrophe in `title=it's` open a run that swallowed the next
		// iframe, and the `[^>]*` the other passes used stops at the first `>`
		// of the document inside the value.
		// Both stashes mark their place with a per-request nonce the page
		// cannot know: with a fixed marker, a paragraph that spelled
		// `__FAZ_SRCDOC_STASH_0__` received the srcdoc token as live markup.
		$stash_nonce = $this->stash_nonce();

		// Comments and raw-text bodies are text to the parser, and no pass may
		// rewrite inside them — find_inert_spans() says why and how they are
		// found. Stashed before every other pass, put back after the last of
		// them; the script and style passes are handed their bodies back.
		$inert_stash = array();
		$html        = $this->stash_inert_spans( $html, $stash_nonce, $inert_stash );

		$srcdoc_stash  = array();
		$srcdoc_marker = '';
		$result        = $this->stash_iframe_srcdocs( $html, $providers, $blocked_categories, $stash_nonce, $srcdoc_stash, $srcdoc_marker, $failed_passes );
		if ( null !== $result ) {
			$html = $result;
		}

		$noscript_stash = array();
		if ( false !== stripos( $html, '<noscript' ) ) {
			$html = $this->run_markup_pass(
				$html,
				'#<noscript(?=[\s/>])' . self::ATTR_WALK . '(?:/\s*+)?>(.*?)</noscript(?=[\s/>])[^>]*>#is',
				'noscript',
				function ( $m ) use ( $providers, $blocked_categories, &$noscript_stash, $stash_nonce ) {
					$processed              = $this->process_noscript_tag( $m, $providers, $blocked_categories );
					$key                    = '__FAZ_NOSCRIPT_STASH_' . $stash_nonce . '_' . count( $noscript_stash ) . '__';
					$noscript_stash[ $key ] = $processed;
					return $key;
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 1. Block <script> tags.
		if ( false !== stripos( $html, '<script' ) ) {
			$html = $this->run_markup_pass(
				$html,
				'#' . self::script_token_skip() . '<script(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>(.*?)</script(?=[\s/>])[^>]*>#is',
				'script',
				function ( $m ) use ( $providers, $blocked_categories, &$inert_stash ) {
					return $this->process_with_inert_body(
						$m,
						'script',
						$inert_stash,
						function ( $m ) use ( $providers, $blocked_categories ) {
							return $this->process_script_tag( $m, $providers, $blocked_categories );
						}
					);
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 1-bis. The SVG namespace. <image> is an <img> by another name and
		// <use href="https://x/sprite.svg#icon"> fetches an external document,
		// both through `href` or its legacy spelling `xlink:href` — attributes
		// no pass here had ever looked at, so an SVG tracking pixel in server
		// markup went out before consent. Measured per engine in
		// tests/browser-intent/write-stream-differential.spec.ts, which printed
		// it as an accepted limit of the element set until this pass.
		// <feImage> fetches through href exactly as <image> does. The tag name
		// must be followed by whitespace, a slash or `>`: a word boundary let
		// <use-case> and <image-gallery> custom elements through and mutilated
		// them. Led by tag_token_skip(), as the img pass is: an `<image href>`
		// inside another tag's quoted value is part of that value.
		if ( false !== stripos( $html, '<image' ) || false !== stripos( $html, '<use' ) || false !== stripos( $html, '<feimage' ) ) {
			$html = $this->run_markup_pass(
				$html,
				'#' . self::tag_token_skip( '(?:image|use|feimage)' ) . '<(image|use|feImage)(?=[\s/>])(' . self::ATTR_WALK . ')(/?)\s*+>#is',
				'svg',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_svg_href_tag( $m, $providers, $blocked_categories );
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 1-ter. The legacy `background` presentation attribute. It fetches an
		// image on <body> and on every table part, and no pass here looked at
		// it — measured fetching in all three engines by
		// tests/browser-intent/write-stream-differential.spec.ts.
		// The pre-check accepts the same `background = "…"` spacing the
		// pattern does; a plain `background=` lookup skipped it. Led by
		// tag_token_skip(): a `<td background>` quoted inside another tag's
		// value, or inside a bogus comment, is not an element.
		if ( 0 !== preg_match( '/\bbackground\s*=/i', $html ) ) {
			$html = $this->run_markup_pass(
				$html,
				'#' . self::tag_token_skip( '(?:body|table|thead|tbody|tfoot|tr|td|th)' ) . '<(body|table|thead|tbody|tfoot|tr|td|th)(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>#is',
				'background',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_background_attr_tag( $m, $providers, $blocked_categories );
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 2. Block <iframe> tags (YouTube, Facebook, Maps, etc.).
		// iframe_pass_pattern() skips every other token whole first, so an
		// <iframe> quoted in a tooltip's value is text, not a frame.
		if ( false !== stripos( $html, '<iframe' ) ) {
			$html = $this->run_markup_pass(
				$html,
				self::iframe_pass_pattern(),
				'iframe',
				function ( $m ) use ( $providers, $blocked_categories, $srcdoc_marker ) {
					return $this->process_iframe_tag( $m, $providers, $blocked_categories, $srcdoc_marker );
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 3. (Moved above the stash — see "Block tracking pixel <img> inside
		//     <noscript>" earlier in this method. Keeping the step-number gap
		//     to avoid renumbering the rest of the pipeline.)

		// 4. Block inline CSS url()/@import channels — any blocked-provider target
		//    inside a <style> (fonts, background-image, cursor, mask, @import).
		if ( false !== stripos( $html, '<style' ) ) {
			$html = $this->run_markup_pass(
				$html,
				'#<style(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>(.*?)</style(?=[\s/>])[^>]*>#is',
				'style',
				function ( $m ) use ( $providers, $blocked_categories, &$inert_stash ) {
					return $this->process_with_inert_body(
						$m,
						'style',
						$inert_stash,
						function ( $m ) use ( $providers, $blocked_categories ) {
							return $this->process_style_tag( $m, $providers, $blocked_categories );
						}
					);
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 4b. Block tracking <img> pixels rendered directly in the document.
		//
		// The identical beacon inside <noscript> was already gated, but one in
		// the body was not: the buffer had no <img> pass at all, despite the
		// docblock above claiming "Images: src → data-faz-src". A conversion
		// plugin or an affiliate network that server-renders
		// `<img src="https://www.facebook.com/tr?id=X&ev=Purchase" width="1">`
		// fired it for every visitor, including one who had rejected marketing —
		// and for a beacon the request IS the tracking event, so the client-side
		// MutationObserver backstop cannot undo it: the preload scanner dispatches
		// the fetch before any script runs.
		// tag_token_skip() consumes every other token whole first: an `<img`
		// inside another tag's quoted value (`data-html="<img src='…'>"`) is
		// part of that value, and rewriting it there broke the markup.
		if ( false !== stripos( $html, '<img' ) ) {
			$html = $this->run_markup_pass(
				$html,
				'#' . self::tag_token_skip( 'img' ) . '<img(?=[\s/>])(' . self::ATTR_WALK . '(?:/\s*+)?)>#is',
				'img',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_img_tag( $m, $providers, $blocked_categories );
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 4c. Media, plug-in, text-track and image-button elements.
		//
		// <video src|poster>, <audio src>, <source src|srcset>, <embed src>,
		// <object data>, <track src> and <input type=image src> all fetch from
		// the parser exactly as <img src> does, and no pass here looked at
		// them: a legacy `<object data="https://www.youtube.com/embed/ID">`
		// loaded the player document — and its cookies — before consent, a
		// `<video poster="https://i.ytimg.com/…">` sent the visitor's IP and
		// referrer to a provider the catalogue files under marketing, and the
		// element then lived unparked for the whole page view. The deferred
		// bootstrap defers parser-built markup to this pass as the authority,
		// and the runtime's restore branches for data-faz-src, data-faz-srcset,
		// data-faz-poster and data-faz-data were already in place: the server
		// was the one layer not writing those keys. The tag name must be
		// followed by whitespace, a slash or `>`, so <video-player> and
		// <source-list> custom elements are not read as the media tag.
		// Like every pass here it never sees comments or script bodies (the
		// inert stash at the top): a `<video poster=\"…\">` inside a JS
		// string is text, and parking it broke the string and the script.
		// Every opening tag is walked, as in the style-attribute pass, and the
		// callback keeps only the media ones: a pattern anchored on the media
		// names started a match wherever `<video` stood, including inside
		// another tag's quoted value (`data-tip='<video poster="…">'`, the way
		// tooltip libraries keep HTML), and the double-quoted keys it wrote
		// there closed that value early. Walked whole, a quoted value is part
		// of its own tag, which is how the linear fallback already read it.
		// non_opening_token_skip() consumes end tags, `<?…>`, `<!x…>` and
		// `</ …>` whole first, as the fallback does: a `<video` inside
		// `</p title="…">` or a bogus comment is not an element either.
		// The name runs to whitespace, `/` or `>`, as the tokenizer and
		// tag_token_skip() read it: a narrower class stopped at `<x-tipé` and
		// let the scan restart inside that tag's quoted value. The callback
		// compares the lower-cased name with the media names.
		if ( 0 !== preg_match( '/<(?:video|audio|source|embed|object|track|input)(?=[\s\/>])/i', $html ) ) {
			$html = $this->run_markup_pass(
				$html,
				'#' . self::non_opening_token_skip() . '<([a-zA-Z][^\s/>]*+)(?=[\s/>])(' . self::ATTR_WALK . ')(/?)\s*+>#is',
				'media',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_media_resource_tag( $m, $providers, $blocked_categories );
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 5. Block fetching <link> tags: stylesheet AND the preload family.
		//
		// This used to match rel="stylesheet" only, which left the whole preload
		// family open: `<link rel="preload" as="script" href="…/gtag/js?id=G-X">`
		// is fetched by the browser's PRELOAD SCANNER, before any script runs, so
		// the paired <script> being correctly re-typed to text/plain stops the
		// execution but not the request. The visitor's IP and full URL reach the
		// provider on a first visit with no consent — and with as="image" on a
		// beacon URL the tracking hit completes outright. The ubiquitous
		// async-CSS pattern (rel="preload" as="style" onload="this.rel='stylesheet'")
		// escaped the same way.
		//
		// dns-prefetch/preconnect are deliberately NOT gated: they resolve DNS
		// and open a socket but transfer no URL and set no cookie, and gating
		// them would break unrelated performance work for no privacy gain.
		if ( false !== stripos( $html, '<link' ) ) {
			// Match ANY <link> and decide inside the callback. Pinning the rel
			// value in the pattern only caught a single quoted token, so
			// `<link rel=preload …>` (unquoted, which HTML allows) and
			// `rel="preload stylesheet"` (the standard async-CSS pair) both
			// slipped past and fetched the provider pre-consent.
			$html = $this->run_markup_pass(
				$html,
				'#<link(?=[\s/>])(' . self::ATTR_WALK . '(?:/\s*+)?)>#is',
				'link',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_link_tag( $m, $providers, $blocked_categories );
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 5b. Inline `style` attributes, on whatever tag carries one.
		//
		// Pass 4 gates CSS URLs inside <style> ELEMENTS. The same url() in a
		// style ATTRIBUTE — `<div style="background-image:url(https://i.ytimg.com/vi/ID/hq.jpg)">`
		// from a theme hero or a lazy-video plugin — shipped live, and the
		// engine fetched the poster at first paint while the identical URL in
		// an <img src> on the same page was parked. Nothing on the client
		// reads a parser-built style attribute, so the server is the layer.
		// Every opening tag is walked; process_style_attr_tag() returns at
		// once for the great majority that carry no style, or no URL in it.
		// End tags and bogus comments are consumed whole first, and the tag
		// name is read to whitespace, `/` or `>`, as in the media pass, so a
		// `style=` quoted inside one is left alone.
		// Runs after the src/href passes so a tag they already parked keeps
		// its one category label.
		//
		// Comments and the bodies of <script> and the other raw-text
		// containers never reach this pass (the inert stash at the top): a
		// tag-shaped substring inside a string literal is not an element, and
		// rewriting it stopped the whole inline script from parsing.
		if ( 0 !== preg_match( '/\bstyle\s*=/i', $html ) ) {
			$html = $this->run_markup_pass(
				$html,
				'#' . self::non_opening_token_skip() . '<([a-zA-Z][^\s/>]*+)(?=[\s/>])(' . self::ATTR_WALK . ')(/?)\s*+>#is',
				'style-attr',
				function ( $m ) use ( $providers, $blocked_categories ) {
					return $this->process_style_attr_tag( $m, $providers, $blocked_categories );
				},
				$failed_passes,
				$inert_stash
			);
		}

		// 6. Block <script data-faz-waitfor="category"> (deferred dependency scripts).
		if ( false !== stripos( $html, 'data-faz-waitfor' ) ) {
			$html = $this->run_markup_pass(
				$html,
				'#<script(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>(.*?)</script(?=[\s/>])[^>]*>#is',
				'waitfor',
				function ( $m ) use ( $blocked_categories ) {
					$attrs    = $m[1];
					$content  = $m[2];
					$wait_cat = $this->extract_tag_attr( $attrs, 'data-faz-waitfor' );
					if ( '' === $wait_cat || ! in_array( $wait_cat, $blocked_categories, true ) ) {
						return $m[0]; // Category is allowed — let it run.
					}
					// Block: change type to text/plain so JS won't execute.
					$new_attrs = $this->set_script_type_plain( $attrs );
					return '<script' . $new_attrs . '>' . $content . '</script>';
				},
				$failed_passes,
				$inert_stash
			);
		}

		$this->log_markup_pass_failure( $failed_passes, 'output buffer' );

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
		// Then the inert spans, which a restored <noscript> block can hold.
		// Before the embed detectors below: they read the page as they always
		// have, script bodies included.
		if ( ! empty( $inert_stash ) ) {
			$html = strtr( $html, $inert_stash );
		}

		// 7. Hide social/widget embed containers that depend on blocked scripts
		// (Facebook/Instagram/Twitter/X, Smash Balloon Instagram Feed, Elementor
		// video widgets). filter_content_blocking() already runs these same two
		// detectors for the_content/widget_text/widget_block_content, but an
		// embed placed OUTSIDE post content - a theme-builder header/footer, a
		// global (non-widget-area) template part, or any markup a page builder
		// renders directly into the page - never passes through those narrower
		// filters and only ever reaches the page here, in the full-page buffer.
		// Without this, such an embed is never detected at all.
		$html = $this->process_social_embeds( $html, $blocked_categories );
		$html = $this->process_elementor_video_widgets( $html, $blocked_categories, $providers );
		$html = $this->process_bricks_map_widgets( $html, $blocked_categories, $providers );

		// Put every srcdoc back, last of all, so no pass above — the embed
		// detectors included — ever saw its markup.
		if ( ! empty( $srcdoc_stash ) ) {
			$html = strtr( $html, $srcdoc_stash );
		}

		// A pass the linear fallback re-ran has judged every element, so the
		// placeholder set is the page's; a skipped one has not.
		if ( empty( $failed_passes ) || $this->markup_fail_closed() ) {
			Embed_Inventory::complete_render();
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
		// WooCommerce Blocks publishes checkout/cart configuration through
		// inline `*-js-before` tags. These are application state, not tracking,
		// and the checkout cannot safely choose a guest/authenticated flow when
		// they are inert. Keep this invariant ahead of every provider/custom-rule
		// decision so integrations cannot accidentally reclassify the handles.
		if ( $this->is_strictly_necessary_script( '', $id ) ) {
			return $full;
		}
		if ( $this->is_own_inline_script_id( $id ) ) {
			return $full;
		}
		// Never inspect the plugin's own EXTERNAL script tags either (core
		// prints them as id="<handle>-js"): the static-config asset and the
		// main bundle must be immune to pattern collisions by construction,
		// not by luck of the provider list's contents.
		if ( $this->is_own_asset_tag_id( $id, '-js' ) ) {
			return $full;
		}
		// wp_localize_script / wp_set_script_translations payloads carry only
		// config data or translation strings — never executable tracker code.
		// Substring matching against their body produces false positives
		// (e.g. trx_addons emits `animate_to_mc4wp_form_submitted` which
		// would otherwise match the `mc4wp` MailChimp pattern and crash
		// the page with `ReferenceError: TRX_ADDONS_STORAGE is not defined`).
		if ( $this->is_wp_localize_or_translations_inline_id( $id ) || ( ! $this->has_attr_token( $attrs, 'src' ) && $this->is_json_data_assignment( $content ) ) ) {
			return $full;
		}
		// The same shape printed under any other id, or none: an inline body
		// made only of `var|let|const|window.NAME = <JSON>;` assignments
		// defines values and requests nothing. Query Monitor publishes
		// `var QueryMonitorData = {…}` this way, and its JSON names the page's
		// handles and callbacks — a handle like google_gtagjs carries the
		// `gtag` signature — so the whole payload was re-typed and its admin
		// bar stopped working (GitHub issue #322). The tracker that reads such
		// data is judged on its own tag. Only the BODY stops being evidence:
		// the tag's identity still counts, as it does in the inline-attributes
		// filter, so a -js-before/-after of a tracker handle follows the
		// tracker. This matcher reads an inline script through its body, so
		// for a data-only body the code signatures are looked for in the id.
		$match_body = $content;
		if ( '' === $this->extract_tag_attr( $attrs, 'src' ) && $this->is_inline_json_data_assignment( $content ) ) {
			$match_body = (string) $this->extract_tag_attr( $attrs, 'id' );
		}

		// Never block whitelisted scripts.
		if ( $this->is_whitelisted( $attrs, $content ) ) {
			return $full;
		}
		// The type is the `type` TOKEN, read through the tokenizer: a substring
		// match found `type='text/plain'` inside an alt value and left the
		// script live, and so did `data-fazcookie` spelled in a decoy.
		$type = strtolower( trim( $this->extract_tag_attr( $attrs, 'type' ) ) );
		// Skip already-blocked scripts.
		if ( 'text/plain' === $type ) {
			return $full;
		}
		// Skip scripts already tagged with data-fazcookie.
		if ( $this->has_attr_token( $attrs, 'data-fazcookie' ) ) {
			return $full;
		}
		// Skip non-executable script types (structured data, templates, etc.).
		if ( in_array( $type, array( 'application/ld+json', 'application/json', 'text/template', 'text/html', 'importmap' ), true ) ) {
			return $full;
		}

		$matched_category = $this->match_script_to_provider( $attrs, $match_body, $providers, $blocked_categories );
		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			// Category is allowed — but per-service consent might still block it.
			$svc_blocked = $this->check_per_service_blocking( $attrs, $match_body );
			if ( true !== $svc_blocked ) {
				return $full;
			}
			// Per-service says block even though category is allowed.
			if ( ! $matched_category ) {
				$matched_category = 'functional'; // Fallback if no category matched.
			}
		} else {
			// Category is blocked — but per-service consent might allow this specific service.
			$svc_blocked = $this->check_per_service_blocking( $attrs, $match_body );
			if ( false === $svc_blocked ) {
				return $full; // Per-service says allow.
			}
		}

		// Advanced Consent Mode (#165): when on, let Consent-Mode-aware Google
		// tags (gtag.js / GA4 / Ads) load before consent. print_gcm_default_inline()
		// has already emitted a synchronous `consent default → denied` at the top
		// of <head>, so these send cookieless/modeled pings and upgrade on
		// consent instead of being hard-blocked. The GTM *container* (gtm.js) is
		// intentionally NOT exempt: it can host non-Google tags that ignore
		// Consent Mode.
		//
		// The exemption is only sound while that default actually precedes the
		// stack in THIS response — gcm_advanced_exemption_active() checks the
		// emission flag, so a response that never printed the default (no
		// wp_head(), AMP) keeps the stack hard-blocked.
		//
		// But never override an EXPLICIT per-service denial: if the visitor
		// rejected this specific Google service via per-service consent
		// (`svc.<id>:no` → $svc_blocked === true), that deliberate choice wins
		// and the tag stays hard-blocked. The exemption only applies to the
		// pre-consent / category-default state, not an explicit rejection.
		if ( true !== $svc_blocked
			&& $this->gcm_advanced_exemption_active()
			&& $this->is_gcm_managed_script( $attrs, $content ) ) {
			return $full;
		}

		return '<script' . $this->label_category_first( $this->set_script_type_plain( $attrs ), $matched_category ) . '>' . $content . '</script>';
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

		// The three ad domains are matched as HOSTS, against the tag's URL.
		// stripos() over the whole haystack answered yes for any tag that merely
		// mentioned one of these names — a URL carrying `?redirect=doubleclick.net`,
		// a look-alike host such as `doubleclick.net.evil.example`, a word in an
		// inline comment — and answering yes here means "Consent Mode manages
		// this tag, let it load before consent". That is the one decision on this
		// path where a loose match lets a tracker run before the visitor has said
		// anything, so it gets the strict test.
		foreach ( preg_split( '/[\s,]+/', (string) $ctx['url'], -1, PREG_SPLIT_NO_EMPTY ) as $faz_candidate ) {
			$faz_host = (string) \wp_parse_url( $faz_candidate, PHP_URL_HOST );
			if ( '' === $faz_host ) {
				continue;
			}
			$faz_host = strtolower( $faz_host );
			foreach ( array( 'googleadservices.com', 'googlesyndication.com', 'doubleclick.net' ) as $faz_domain ) {
				if ( $this->host_is_or_subdomain_of( $faz_host, $faz_domain ) ) {
					return true;
				}
			}
			// The PATH, not the whole URL: `gtm.js?id=GTM-X&next=/gtag/js` carries
			// the string but loads the GTM container, which must stay blocked —
			// and the container is the one thing this method has always been
			// careful to exclude.
			if ( $this->host_is_or_subdomain_of( $faz_host, 'googletagmanager.com' ) ) {
				$faz_path = (string) \wp_parse_url( $faz_candidate, PHP_URL_PATH );
				if ( 0 === stripos( $faz_path, '/gtag/js' ) ) {
					return true;
				}
			}
		}

		// Code fragments, not hosts, so these stay substring tests: an inline
		// bootstrap has no URL to read, and the gtag.js reference inside a
		// server-rendered loader is a string in a script body. Narrowing these
		// to the URL would newly block the inline Advanced Consent Mode
		// bootstrap (#165) that this method exists to leave running.
		foreach ( array(
			'googletagmanager.com/gtag/js',
			"gtag('config'",
			'gtag("config"',
			"gtag('js'",
			'gtag("js"',
		) as $faz_needle ) {
			if ( false !== stripos( (string) $ctx['content'], $faz_needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a host is a domain itself or a subdomain of it.
	 *
	 * The dot is what does the work. Without it `notdoubleclick.net` and
	 * `doubleclick.net.evil.example` both pass, which is the failure this
	 * replaces. Mirrors _fazHostMatches() in frontend/js/script.js.
	 *
	 * @param string $host   Lowercased hostname.
	 * @param string $domain Lowercased registrable domain.
	 * @return bool
	 */
	private function host_is_or_subdomain_of( $host, $domain ) {
		$host   = (string) $host;
		$domain = (string) $domain;
		if ( '' === $host || '' === $domain ) {
			return false;
		}
		return $host === $domain || substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain;
	}

	/**
	 * Process a single <iframe> tag for blocking.
	 *
	 * @param array $m               Regex match.
	 * @param array $providers       Provider→category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_iframe_tag( $m, $providers, $blocked_categories, $srcdoc_marker = '' ) {
		$attrs = $m[1];
		$full  = $m[0];

		if ( $this->is_whitelisted( $attrs, '' ) ) {
			return $full;
		}
		// The token, not a substring: `data-faz-src` is the start of
		// `data-faz-srcdoc`, and matching it made every srcdoc frame look
		// already parked, leaving its src live.
		if ( $this->has_attr_token( $attrs, 'data-faz-src' ) && ! $this->has_attr_token( $attrs, 'src' ) ) {
			return $full; // Parked, and nothing live left (a bare sentinel does not exempt).
		}
		// A frame whose ALLOWED srcdoc is in the stash (the "lite embed"
		// pattern: a click-to-load facade with the provider URL in src). The
		// engine ignores src while srcdoc is present, so nothing fetches as
		// rendered — but the facade's own Play button removes srcdoc, and the
		// src it activates had been gated by nothing: the frame navigated to
		// the provider with no consent. So a blocked src is parked here too,
		// in place; the srcdoc stays live, byte-for-byte, and restore 2b
		// hands src back on consent. Parked WITHOUT the placeholder wrap:
		// that sent the bare stash marker through wp_kses, which stripped it,
		// so the srcdoc never came back. And NOT hidden: what the frame shows
		// is the allowed srcdoc, and with src parked nothing in it can reach
		// the provider. Hiding it left a hole where the site put its facade
		// and took away the only thing on the page that said a video was
		// there; the banner and the revisit widget remain the way to consent.
		// The runtime's park leaves a frame with a live srcdoc visible too.
		// A parked srcdoc already took src with it. The marker carries the
		// request nonce, so the page cannot spell it.
		$live_srcdoc = '' !== $srcdoc_marker && false !== strpos( $attrs, $srcdoc_marker . 'LIVE_' );

		$matched_category = $this->match_script_to_provider( $attrs, '', $providers, $blocked_categories );
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

		// Rename src → data-faz-src on the token the tokenizer would produce,
		// whatever precedes it.
		list( $new_attrs, $renamed ) = $this->rename_attr_tokens( $attrs, array( 'src' => 'data-faz-src' ) );
		if ( $live_srcdoc ) {
			if ( empty( $renamed ) ) {
				return $full; // No live src: the allowed srcdoc alone, nothing to park.
			}
			$new_attrs = $this->label_category_first( $new_attrs, $matched_category );
			return isset( $m[2] )
				? '<iframe' . $new_attrs . '>' . $m[2] . '</iframe>'
				: '<iframe' . $new_attrs . '/>';
		}
		$new_attrs = $this->label_category_first( $new_attrs, $matched_category );

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
		return $this->normalize_resource_url( $this->extract_tag_attr( $attrs, 'src' ) );
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
	 * Process a <noscript> block — gate the <img>, <iframe> and <link>
	 * fallbacks inside it for the visitor who has JavaScript off.
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
		// <link> is one: async-CSS optimisers pair every live preload with a
		// `<noscript><link rel="stylesheet">` fallback, and that copy used to
		// be stashed past the <link> pass and restored verbatim — so a visitor
		// without JavaScript, the one who can never consent, fetched Google
		// Fonts on every page while the live preload beside it was parked.
		if ( false === stripos( $content, '<img' ) && false === stripos( $content, '<iframe' ) && false === stripos( $content, '<link' ) ) {
			return $full;
		}

		// Pass 1 — code-signature patterns (fbq(, gtag …) against the raw text.
		// Kept in its own variable: a content-level hit means the block as a
		// whole is a tracking fallback, which the rewrite below uses to decide
		// whether unmatched sibling tags travel with it.
		$content_matched = $this->match_script_to_provider( '', $content, $providers, $blocked_categories );

		// Pass 2 — URL-fragment patterns against each embedded resource's OWN
		// tag attributes. The content-only call above carries no src haystack
		// (attrs are empty), so URL patterns like "www.facebook.com/tr" never
		// gated <noscript> pixel beacons — the very tags this pass exists for.
		// Match each <img>/<iframe>/<link> exactly the way the script/iframe/
		// link filters match regular tags, skipping whitelisted resources. A
		// <link> is a resource only through the rel tokens process_link_tag()
		// gates (stylesheet and the preload family); dns-prefetch, preconnect
		// and the plugin's own banner stylesheet are exempt here as there.
		// Resolve each tag's own category once, here, and keep it alongside the
		// tag. Both the gating decision below and the category label written
		// onto each rewritten tag need it, and matching twice invites the two to
		// disagree.
		// EVERY match is kept, in document order, whitelisted ones included and
		// flagged. The rewrite below re-walks the same pattern with a counter, so
		// the two passes have to see the same list or the indices drift.
		$embedded_tags  = array();
		$tag_whitelisted = array();
		$tag_categories = array();
		$tag_svcs       = array();
		if ( preg_match_all( '#<(?:img|iframe|link)(?=[\s/>])' . self::ATTR_WALK . '#i', $content, $embedded_matches ) ) {
			foreach ( $embedded_matches[0] as $embedded_tag ) {
				$whitelisted = $this->is_whitelisted( $embedded_tag, '' );
				if ( ! $whitelisted && 0 === stripos( $embedded_tag, '<link' ) ) {
					$whitelisted = ! $this->link_tag_fetches( $embedded_tag )
						|| $this->is_own_asset_tag_id( $this->extract_tag_attr( $embedded_tag, 'id' ), '-css' );
				}
				$embedded_tags[]   = $embedded_tag;
				$tag_whitelisted[] = $whitelisted;
				$tag_categories[]  = $whitelisted ? '' : $this->match_script_to_provider( $embedded_tag, '', $providers, $blocked_categories );
				$tag_svcs[]        = $whitelisted ? null : $this->check_per_service_blocking( $embedded_tag, '' );
			}
		}

		// The block's own text can carry a per-service decision too (a service
		// named in the fallback markup). It is inherited by a resource that has
		// no decision of its own ONLY when it is restrictive.
		//
		// Letting a block-level svc:yes stand in for an undecided resource is the
		// same aggregation bypass this method was rewritten to remove, just one
		// level up: a service named in the block text and granted would release a
		// sibling pixel whose own category is denied, and that sibling would load
		// before consent for a visitor without JavaScript. Permission does not
		// travel between resources; denial may, because erring restrictive at
		// worst blocks something the visitor allowed and never the reverse.
		$content_svc = $this->check_per_service_blocking( '', $content );

		// Decide EVERY resource on its own terms. Aggregating the block into one
		// verdict — one category, one service decision — was an authorisation
		// bypass in both layers: an explicit svc:yes on the first resource made
		// the whole block "allowed", so a second resource in a denied category
		// and with no decision of its own kept its live src and loaded as soon as
		// the page rendered without JavaScript. Precedence per resource is
		// svc:no → gate, svc:yes → leave alone, no decision → its category
		// decides, falling back to the block-level signature that marks the whole
		// <noscript> as a tracking fallback.
		$gate = array();
		foreach ( array_keys( $embedded_tags ) as $index ) {
			if ( ! empty( $tag_whitelisted[ $index ] ) ) {
				$gate[ $index ] = false;
				continue;
			}
			// The resource's OWN decision, either way.
			if ( null !== $tag_svcs[ $index ] ) {
				$gate[ $index ] = ( true === $tag_svcs[ $index ] );
				continue;
			}
			// No decision of its own: a restrictive block-level one still binds,
			// a permissive one does not (see above).
			if ( true === $content_svc ) {
				$gate[ $index ] = true;
				continue;
			}
			$cat            = $tag_categories[ $index ] ? $tag_categories[ $index ] : $content_matched;
			$gate[ $index ] = ( $cat && in_array( $cat, $blocked_categories, true ) );
		}

		if ( ! in_array( true, $gate, true ) ) {
			return $full;
		}

		// Rewrite ONLY the tags this pass actually resolved as blockable.
		//
		// A blanket preg_replace over $content would neutralise every <img> and
		// <iframe> in the block, including the ones $embedded_tags deliberately
		// excludes because the administrator whitelisted them, and any unrelated
		// resource that matches no provider at all — a static logo sitting in the
		// same <noscript> as a Meta pixel would stop loading and be labelled with
		// the pixel's category. Every other filter in this class treats the
		// whitelist as binding, and this one must too.
		//
		// The blast radius of getting this wrong grew with the gating fix above:
		// matching used to see only the block's text, so blocking rarely
		// triggered here. Now that each embedded tag is matched properly, it
		// triggers often, which turns a latent over-block into a routine one.
		$blocked_content = $content;
		// Rewrite in place, by position, with a counter — never by substring.
		//
		// str_replace() on the matched fragment replaced EVERY occurrence, and
		// the pattern captures an opening tag WITHOUT its closing ">", so a
		// gated `<img src="a.gif"` is a literal prefix of an allowed
		// `<img src="a.gif" alt="logo">`. The allowed tag was rewritten too,
		// inheriting the gated one's category, and a whitelisted resource the
		// admin had deliberately exempted could be neutralised by a sibling it
		// merely resembles. Walking the same pattern again with an index makes
		// each match its own target and the aliasing unrepresentable.
		$rewrite_index   = -1;
		$blocked_content = preg_replace_callback(
			'#<(?:img|iframe|link)(?=[\s/>])' . self::ATTR_WALK . '#i',
			function ( $m ) use ( &$rewrite_index, $gate, $tag_categories, $content_matched ) {
				$rewrite_index++;
				$tag = $m[0];
				if ( empty( $gate[ $rewrite_index ] ) ) {
					return $tag;
				}
				// Label each resource with ITS OWN category where one is known.
				// Falling back to a single block-level category for every tag
				// mislabels a block that mixes providers — a Meta pixel and a
				// YouTube embed would both be reported under whichever matched
				// first, and the visitor's per-category choice would be applied
				// to the wrong one. A resource gated purely by a per-service
				// decision may match no provider at all; 'functional' keeps it
				// revealable rather than stranding it under a category nothing
				// will ever grant.
				$label_category = isset( $tag_categories[ $rewrite_index ] ) ? $tag_categories[ $rewrite_index ] : '';
				if ( ! $label_category ) {
					$label_category = $content_matched ? $content_matched : 'functional';
				}
				// The match is `<img`, `<iframe` or `<link` plus its attributes;
				// rename the fetching attribute on its token, whatever precedes
				// it. A <link> fetches through href or imagesrcset, and both are
				// parked under the names process_link_tag() uses, so the
				// runtime's one restore branch reads them.
				if ( ! preg_match( '/^<(?:img|iframe|link)/i', $tag, $open ) ) {
					return $tag;
				}
				$map = 0 === strcasecmp( substr( $open[0], 1 ), 'link' )
					? array(
						'imagesrcset' => 'data-faz-imagesrcset',
						'href'        => 'data-faz-href',
					)
					: array( 'src' => 'data-faz-src' );
				list( $rewritten, $renamed ) = $this->rename_attr_tokens( substr( $tag, strlen( $open[0] ) ), $map );
				if ( empty( $renamed ) ) {
					return $tag;
				}
				return $open[0] . $this->label_category_first( $rewritten, $label_category );
			},
			$content
		);
		if ( null === $blocked_content ) {
			// PCRE failure — serve the block untouched rather than a mangled one.
			return $full;
		}
		if ( $blocked_content === $content ) {
			return $full;
		}
		return str_replace( $content, $blocked_content, $full );
	}

	/**
	 * Process an <img> tag rendered directly in the document.
	 *
	 * Deliberately narrow: an image is only gated when it matches a blocked
	 * PROVIDER pattern or a denied per-service decision — i.e. it is a known
	 * tracking endpoint. Ordinary content images, and anything already carrying
	 * data-faz-src, are returned untouched, because gating them would blank the
	 * page for a visitor who has merely not answered the banner yet.
	 *
	 * @param array $m                  Regex match: [0] full tag, [1] attributes.
	 * @param array $providers          Provider match table.
	 * @param array $blocked_categories Categories blocked for this visitor.
	 * @return string
	 */
	private function process_img_tag( $m, $providers, $blocked_categories ) {
		$attrs = $m[1];
		$full  = $m[0];

		// Already parked means parked AND nothing live left: a forged empty
		// `data-faz-src=""` next to a live src used to exempt the tag. The
		// token, not a substring: `data-faz-src` is also the start of
		// `data-faz-srcset`.
		if ( $this->has_attr_token( $attrs, 'data-faz-src' ) && ! $this->has_attr_token( $attrs, 'src' ) && ! $this->has_attr_token( $attrs, 'srcset' ) ) {
			return $full;
		}
		if ( $this->is_whitelisted( $attrs, '' ) ) {
			return $full;
		}
		// A data: src is not a request: the engine decodes it in place, and an
		// SVG shown through <img> can neither fetch an external resource nor
		// run script, so a provider URL inside the payload is text, not a
		// beacon. Parking it was a false positive — one the runtime's restore
		// then refused to undo, so the image stayed blank after consent. The
		// runtime's own src gate and the bootstrap never park a data: image
		// either; this brings the server level with them. Judged all the same
		// when a srcset stands next to it, because srcset candidates ARE
		// fetched. (An <iframe src="data:…"> is a different thing: its payload
		// is a document whose fetches no gate can reach once it is parsed, so
		// process_iframe_tag keeps parking it and the restore accepts it back.)
		if ( ! $this->has_attr_token( $attrs, 'srcset' ) ) {
			$src_value = $this->normalize_resource_url( $this->extract_tag_attr( $attrs, 'src' ) );
			if ( '' !== $src_value && 0 === stripos( $src_value, 'data:' ) ) {
				return $full;
			}
		}

		$matched_category = $this->match_script_to_provider( $attrs, '', $providers, $blocked_categories );
		$svc_blocked      = $this->check_per_service_blocking( $attrs, '' );

		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			// No blocked provider matched: gate only on an explicit service denial.
			if ( true !== $svc_blocked ) {
				return $full;
			}
			if ( ! $matched_category ) {
				$matched_category = 'functional';
			}
		} elseif ( false === $svc_blocked ) {
			// The category is blocked but this service was explicitly allowed.
			return $full;
		}

		// Park src AND srcset. A responsive tracking image can carry the beacon
		// in srcset alone — the browser picks a candidate from there in
		// preference to src — so renaming only src leaves the request to fire.
		// `data-faz-srcset` is the attribute the client's own restore pass
		// already looks for (script.js: document.querySelectorAll('[data-faz-srcset]')),
		// so no new convention is introduced.
		// Both renamed on their tokens, whatever precedes them.
		list( $with_src, $renamed ) = $this->rename_attr_tokens(
			$attrs,
			array(
				'srcset' => 'data-faz-srcset',
				'src'    => 'data-faz-src',
			)
		);
		$parked_srcset = ! empty( $renamed['srcset'] );
		$parked_src    = ! empty( $renamed['src'] );
		if ( ! $parked_src && ! $parked_srcset ) {
			return $full; // Nothing fetchable to park — leave the tag alone.
		}
		// Put metadata BEFORE the captured attributes. The outer tag regex keeps
		// an XHTML-style closing slash inside $attrs (`<img ... />`), so appending
		// metadata after it produced invalid `<img ... / data-faz-category=...>`.
		// Prefixing preserves both ordinary and self-closing source markup.
		$with_src = $this->label_category_first( $with_src, $matched_category );
		if ( $parked_srcset ) {
			// The srcset restore reads its category from its own attribute;
			// the matcher's, alone, for the same reason.
			$with_src = ' data-faz-srcset-category="' . esc_attr( $matched_category ) . '"' . $this->strip_attr_tokens( $with_src, 'data-faz-srcset-category' );
		}
		return '<img' . $with_src . '>';
	}

	/**
	 * Process an SVG <image> / <use> / <feImage> tag for a blocked href.
	 *
	 * Both spellings — `href` and the legacy `xlink:href` — park into ONE
	 * attribute, `data-faz-href`, which is what the client's restore pass reads.
	 * One parked name means one restore branch; two would be two branches that
	 * can disagree, and a resource a restore pass cannot select is not delayed,
	 * it is dead.
	 *
	 * Both spellings are REMOVED when both are present: sprites and exports
	 * commonly carry the pair, and parking the first left the second live,
	 * which fetches. The parked value is the SVG2 `href` when there is one,
	 * because that is the one an engine honours over `xlink:href` when both
	 * are present; the restore writes it back as `href`, which every engine in
	 * the support matrix reads.
	 *
	 * @param array $m                  Regex match: tag name, attributes, closing slash.
	 * @param array $providers          Provider-to-category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_svg_href_tag( $m, $providers, $blocked_categories ) {
		$tag   = strtolower( isset( $m[1] ) ? $m[1] : '' );
		$attrs = isset( $m[2] ) ? $m[2] : '';
		$close = isset( $m[3] ) ? $m[3] : '';
		$full  = isset( $m[0] ) ? $m[0] : '';

		if ( ( $this->has_attr_token( $attrs, 'data-faz-href' ) || $this->has_attr_token( $attrs, 'data-faz-src' ) )
			&& ! $this->has_attr_token( $attrs, 'href' ) && ! $this->has_attr_token( $attrs, 'xlink:href' ) && ! $this->has_attr_token( $attrs, 'src' ) ) {
			return $full; // Parked, and nothing live left (a bare sentinel does not exempt).
		}
		if ( $this->is_whitelisted( $attrs, '' ) ) {
			return $full;
		}

		$matched_category = $this->match_script_to_provider( $attrs, '', $providers, $blocked_categories );
		$svc_blocked      = $this->check_per_service_blocking( $attrs, '' );

		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			if ( true !== $svc_blocked ) {
				return $full;
			}
			if ( ! $matched_category ) {
				$matched_category = 'functional';
			}
		} elseif ( false === $svc_blocked ) {
			return $full;
		}

		// Every href/xlink:href token leaves; the first SVG2 value (else the
		// first legacy one) is what gets parked. Outside <svg> the tree
		// builder turns <image> into <img>, which fetches `src`, so on that
		// tag src is parked as data-faz-src too — the restore pass for <img>
		// reads it there. `srcset` goes with it for the reason the <img> pass
		// gives: the tree builder keeps every attribute, and the browser picks
		// a candidate from srcset in PREFERENCE to src, so a responsive beacon
		// needs no src at all. Listing only src left a tag carrying srcset
		// alone with nothing to park, and the early return below handed it
		// back untouched. Edits from the end, so earlier offsets stay valid.
		$wanted = 'image' === $tag ? array( 'href', 'xlink:href', 'src', 'srcset' ) : array( 'href', 'xlink:href' );
		$tokens = $this->tokenize_tag_attrs( $attrs, $wanted );
		$value  = '';
		$legacy = '';
		$parked = $attrs;
		$src    = false;
		$srcset = false;
		for ( $i = count( $tokens ) - 1; $i >= 0; $i-- ) {
			$token = $tokens[ $i ];
			if ( null === $token['value'] ) {
				continue;
			}
			if ( 'srcset' === $token['key'] ) {
				$parked = substr_replace( $parked, ' data-faz-srcset=' . $token['value'], $token['offset'], $token['length'] );
				$srcset = true;
				continue;
			}
			if ( 'src' === $token['key'] ) {
				$parked = substr_replace( $parked, ' data-faz-src=' . $token['value'], $token['offset'], $token['length'] );
				$src    = true;
				continue;
			}
			if ( 'href' === $token['key'] ) {
				$value = $token['value']; // Walking backwards: the last assignment is the first token.
			} elseif ( '' === $value ) {
				$legacy = $token['value'];
			}
			$parked = substr_replace( $parked, ' ', $token['offset'], $token['length'] );
		}
		if ( '' === $value ) {
			$value = $legacy;
		}
		if ( '' === $value && ! $src && ! $srcset ) {
			return $full; // Nothing fetchable to park.
		}

		// Metadata BEFORE the captured attributes, for the same reason as
		// process_img_tag(): a self-closing tag keeps its slash inside $attrs.
		$metadata = '' !== $value ? ' data-faz-href=' . $value : '';
		$out      = $this->label_category_first( $metadata . $parked, $matched_category );
		if ( $srcset ) {
			// The srcset restore reads its category from its own attribute, as
			// it does on an <img>; the matcher's, alone.
			$out = ' data-faz-srcset-category="' . esc_attr( $matched_category ) . '"' . $this->strip_attr_tokens( $out, 'data-faz-srcset-category' );
		}
		return '<' . $tag . $out . $close . '>';
	}

	/**
	 * Process the legacy `background` presentation attribute.
	 *
	 * Obsolete in the spec, still honoured by every engine, and a one-attribute
	 * tracking pixel on a <body> or a <td> is as much a request as an <img>.
	 * Parked into data-faz-background, which the client's restore pass reads.
	 *
	 * @param array $m                  Regex match: tag name, attributes.
	 * @param array $providers          Provider-to-category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_background_attr_tag( $m, $providers, $blocked_categories ) {
		$tag   = strtolower( isset( $m[1] ) ? $m[1] : '' );
		$attrs = isset( $m[2] ) ? $m[2] : '';
		$full  = isset( $m[0] ) ? $m[0] : '';

		// The pattern matches every table part; only one carrying the
		// attribute is worth the provider match.
		if ( ! $this->has_attr_token( $attrs, 'background' ) ) {
			return $full;
		}
		// A live background is judged whether or not a parked one sits next
		// to it (a bare sentinel does not exempt); the pre-filter above
		// already returned when there is none.
		if ( $this->is_whitelisted( $attrs, '' ) ) {
			return $full;
		}

		$matched_category = $this->match_script_to_provider( $attrs, '', $providers, $blocked_categories );
		$svc_blocked      = $this->check_per_service_blocking( $attrs, '' );

		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			if ( true !== $svc_blocked ) {
				return $full;
			}
			if ( ! $matched_category ) {
				$matched_category = 'functional';
			}
		} elseif ( false === $svc_blocked ) {
			return $full;
		}

		list( $parked, $renamed ) = $this->rename_attr_tokens( $attrs, array( 'background' => 'data-faz-background' ) );
		if ( empty( $renamed ) ) {
			return $full;
		}

		return '<' . $tag . $this->label_category_first( $parked, $matched_category ) . '>';
	}

	/** Response-local proof for server parks; deliberately excluded from static config. */
	private static function srcdoc_key() {
		static $key;
		if ( null === $key ) {
			// random_bytes() throws when the system has no usable entropy source
			// (rare, but seen on locked-down hosts). This runs inside the page
			// render, so an uncaught exception would take the whole front end
			// down with it. wp_generate_password() falls back to WordPress's own
			// generator; alphanumeric only, so the value stays attribute-safe
			// exactly like the hex form.
			try {
				$key = bin2hex( random_bytes( 24 ) );
			} catch ( \Throwable $e ) {
				$key = function_exists( 'wp_generate_password' ) ? wp_generate_password( 48, false, false ) : hash( 'sha256', uniqid( '', true ) . wp_salt( 'auth' ) );
			}
		}
		return $key;
	}

	/**
	 * Decode character references the way the HTML tokenizer does in an
	 * attribute value, for JUDGING only.
	 *
	 * html_entity_decode() needs the semicolon, the tokenizer does not: a
	 * numeric reference without one (`ur&#108(`, `&#x75rl(`) is decoded, and so
	 * is a legacy named one (`&amp`, `&lt`, `&gt`, `&quot`) unless a letter, a
	 * digit or `=` follows it. Those four are the legacy names that change how
	 * CSS or markup is tokenised; the others decode to symbols that cannot
	 * form a function name or a URL delimiter. Numeric 0, surrogates and code
	 * points past U+10FFFF become U+FFFD, as in the tokenizer. Whatever is
	 * still encoded is then decoded by html_entity_decode().
	 *
	 * @param string $value Attribute value or markup.
	 * @return string
	 */
	private static function decode_html_references( $value ) {
		$value = (string) $value;
		if ( false === strpos( $value, '&' ) ) {
			return $value;
		}
		$numeric = preg_replace_callback(
			'/&#(?:[xX]([0-9a-fA-F]+)|([0-9]+));?/',
			function ( $m ) {
				$digits = '' !== $m[1] ? ltrim( $m[1], '0' ) : ltrim( $m[2], '0' );
				$cp     = 0;
				if ( '' !== $digits && strlen( $digits ) <= 7 ) {
					$cp = '' !== $m[1] ? hexdec( $digits ) : (int) $digits;
				} elseif ( '' !== $digits ) {
					$cp = 0x110000;
				}
				if ( 0 === $cp || $cp > 0x10FFFF || ( $cp >= 0xD800 && $cp <= 0xDFFF ) ) {
					$cp = 0xFFFD;
				}
				if ( $cp < 0x80 ) {
					return chr( $cp );
				}
				if ( $cp < 0x800 ) {
					return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
				}
				if ( $cp < 0x10000 ) {
					return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
				}
				return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
			},
			$value
		);
		if ( null === $numeric ) {
			return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$legacy = preg_replace_callback(
			'/&(amp|lt|gt|quot|AMP|LT|GT|QUOT)(?![;A-Za-z0-9=])/',
			function ( $m ) {
				$map = array( 'amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"' );
				return $map[ strtolower( $m[1] ) ];
			},
			$numeric
		);
		return html_entity_decode( null === $legacy ? $numeric : $legacy, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private function srcdoc_signature( $value, $category ) {
		// Sign the DOM attribute value, independent of quoting/entity spelling.
		$raw = ( strlen( $value ) >= 2 && ( '"' === $value[0] || "'" === $value[0] ) ) ? substr( $value, 1, -1 ) : $value;
		return hash_hmac( 'sha256', $category . '|' . html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), wp_salt( 'auth' ) );
	}

	/**
	 * Process a media, plug-in, text-track or image-button element for the
	 * attributes the parser fetches from.
	 *
	 * <video src|poster>, <audio src>, <source src|srcset>, <embed src>,
	 * <object data>, <track src> and <input type=image src> request a URL
	 * exactly as <img src> does. Each attribute is judged ON ITS OWN through
	 * resource_attr_block_category(): a <video> can pair a first-party src
	 * with a provider poster, and one verdict for the tag would park, or
	 * release, both. Each is parked under the key its restore branch in
	 * script.js reads (data-faz-src, data-faz-srcset with its own category,
	 * data-faz-poster, data-faz-data); metadata goes BEFORE the captured
	 * attributes for the reason process_img_tag() gives. No hidden class: the
	 * restore for these keys removes none, so one added here would outlive
	 * consent.
	 *
	 * @param array $m                  Regex match: tag name, attributes, closing slash.
	 * @param array $providers          Provider-to-category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_media_resource_tag( $m, $providers, $blocked_categories ) {
		$tag   = strtolower( isset( $m[1] ) ? $m[1] : '' );
		$attrs = isset( $m[2] ) ? $m[2] : '';
		$close = isset( $m[3] ) ? $m[3] : '';
		$full  = isset( $m[0] ) ? $m[0] : '';

		// The pass walks every opening tag; only these fetch through it.
		if ( ! in_array( $tag, array( 'video', 'audio', 'source', 'embed', 'object', 'track', 'input' ), true ) ) {
			return $full;
		}

		switch ( $tag ) {
			case 'video':
				$wanted = array(
					'src'    => 'data-faz-src',
					'poster' => 'data-faz-poster',
				);
				break;
			case 'source':
				$wanted = array(
					'src'    => 'data-faz-src',
					'srcset' => 'data-faz-srcset',
				);
				break;
			case 'object':
				$wanted = array( 'data' => 'data-faz-data' );
				break;
			case 'input':
				// Only an image button fetches its src. Every other input is a
				// form control, and a src on it is noise the engine ignores.
				if ( 'image' !== strtolower( trim( $this->extract_tag_attr( $attrs, 'type' ) ) ) ) {
					return $full;
				}
				$wanted = array( 'src' => 'data-faz-src' );
				break;
			default: // audio, embed, track.
				$wanted = array( 'src' => 'data-faz-src' );
		}

		$parked          = $attrs;
		$categories      = array();
		$srcset_category = '';
		foreach ( $wanted as $name => $parked_name ) {
			if ( ! $this->has_attr_token( $attrs, $name ) ) {
				continue;
			}
			$category = $this->resource_attr_block_category( $attrs, $name, $providers, $blocked_categories );
			if ( false === $category ) {
				continue;
			}
			list( $parked, $renamed ) = $this->rename_attr_tokens( $parked, array( $name => $parked_name ) );
			if ( empty( $renamed ) ) {
				continue; // A bare attribute with no value fetches nothing.
			}
			if ( 'srcset' === $name ) {
				$srcset_category = $category;
			} else {
				$categories[] = $category;
			}
		}
		if ( array() === $categories && '' === $srcset_category ) {
			return $full;
		}

		$out = $parked;
		if ( '' !== $srcset_category ) {
			// The srcset restore reads its category from its own attribute, as
			// it does on an <img>.
			$out = ' data-faz-srcset-category="' . esc_attr( $srcset_category ) . '"' . $this->strip_attr_tokens( $out, 'data-faz-srcset-category' );
		}
		$label = array() !== $categories ? $categories[0] : $srcset_category;
		return '<' . $tag . $this->label_category_first( $out, $label ) . $close . '>';
	}

	/**
	 * Judge ONE URL-bearing attribute of a tag on its own.
	 *
	 * The tag-level matcher reads the first of src/href/xlink:href/background
	 * and would judge a <video>'s poster by its src. The attribute is judged
	 * through a probe instead: the tag's attributes with every OTHER URL
	 * attribute removed and this one spelled `src`, so the matcher, the
	 * whitelist and the per-service check read it exactly the way they read
	 * an <img src> — id and class included, so faz-skip and an id/class
	 * whitelist entry still exempt the tag. `srcset` keeps its own name: the
	 * match context already folds a candidate list in.
	 *
	 * @param string $attrs              Attribute string.
	 * @param string $name               The attribute to judge.
	 * @param array  $providers          Provider-to-category map.
	 * @param array  $blocked_categories Currently blocked category slugs.
	 * @return string|false The category to park under, or false to leave it live.
	 */
	private function resource_attr_block_category( $attrs, $name, $providers, $blocked_categories ) {
		$probe = $attrs;
		foreach ( array( 'src', 'srcset', 'href', 'xlink:href', 'background', 'poster', 'data', 'imagesrcset' ) as $other ) {
			if ( $other !== $name ) {
				$probe = $this->strip_attr_tokens( $probe, $other );
			}
		}
		if ( 'src' !== $name && 'srcset' !== $name ) {
			list( $probe ) = $this->rename_attr_tokens( $probe, array( $name => 'src' ) );
		}
		if ( $this->is_whitelisted( $probe, '' ) ) {
			return false;
		}

		$matched_category = $this->match_script_to_provider( $probe, '', $providers, $blocked_categories );
		$svc_blocked      = $this->check_per_service_blocking( $probe, '' );

		if ( ! $matched_category || ! in_array( $matched_category, $blocked_categories, true ) ) {
			if ( true !== $svc_blocked ) {
				return false;
			}
			return $matched_category ? $matched_category : 'functional';
		}
		if ( false === $svc_blocked ) {
			return false;
		}
		return $matched_category;
	}

	/**
	 * Process any tag's inline `style` attribute for blockable CSS URLs.
	 *
	 * The <style> pass gated url()/@import inside style ELEMENTS only, so a
	 * `<div style="background-image:url(https://i.ytimg.com/vi/ID/hq.jpg)">`
	 * shipped live and the engine fetched the poster at first paint. The
	 * value is judged as the engine parses it — character references decoded,
	 * then the same extractor as the <style> pass (CSS escapes, every url()
	 * shape, @import, image-set()/src() strings). The neutralised
	 * declarations become the live style; the original moves to
	 * data-faz-style, which the runtime hands back on consent only when no URL
	 * in it is still blocked. A form the in-place rewrite cannot reach parks
	 * the value WHOLE — no live style at all — as the <style> pass parks a
	 * sheet.
	 *
	 * The category label is the matcher's, first and alone, as everywhere —
	 * unless an earlier pass already parked a key on this tag and labelled
	 * it. Two labels on one element cannot both be read, the restore for
	 * this key re-judges the ORIGINAL declarations by URL and never trusts
	 * the label, so keeping the earlier one costs nothing here and keeps the
	 * other restore selectable.
	 *
	 * @param array $m                  Regex match: tag name, attributes, closing slash.
	 * @param array $providers          Provider-to-category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @return string
	 */
	private function process_style_attr_tag( $m, $providers, $blocked_categories ) {
		$tag   = isset( $m[1] ) ? $m[1] : '';
		$attrs = isset( $m[2] ) ? $m[2] : '';
		$close = isset( $m[3] ) ? $m[3] : '';
		$full  = isset( $m[0] ) ? $m[0] : '';

		// Every opening tag in the document reaches here; most carry no style
		// at all, and most styles name no URL. Cheap substring tests first.
		if ( false === stripos( $attrs, 'style' ) ) {
			return $full;
		}
		$raw = $this->extract_tag_attr( $attrs, 'style' );
		if ( '' === $raw ) {
			return $full;
		}
		// What the engine parses is the DECODED attribute value, so the cheap
		// pre-check reads that too: on the raw text `ur&#108;(`, `&#x75;rl(`
		// and `image&#45;set(` named no function and left the pass untouched.
		$css = self::decode_html_references( $raw );
		if ( ! preg_match( '/url|image-set|src\(|@import|\\\\/i', $css ) ) {
			return $full;
		}
		if ( $this->is_whitelisted( $attrs, '' ) ) {
			return $full;
		}

		$categories = array();
		$rewritten  = $this->neutralize_blocked_css_urls( $css, $providers, $blocked_categories, $categories );
		if ( empty( $categories ) || $rewritten === $css ) {
			return $full;
		}

		// Every style token leaves on its own bytes (the parser keeps the first
		// of a duplicated attribute, and the first is the one judged above).
		list( $parked, $renamed ) = $this->rename_attr_tokens( $attrs, array( 'style' => 'data-faz-style' ) );
		if ( empty( $renamed ) ) {
			return $full;
		}
		$live = '' !== $rewritten ? ' style="' . esc_attr( $rewritten ) . '"' : '';

		$labelled = false;
		if ( $this->has_attr_token( $parked, 'data-faz-category' ) ) {
			foreach ( array( 'data-faz-src', 'data-faz-srcset', 'data-faz-href', 'data-faz-imagesrcset', 'data-faz-background', 'data-faz-poster', 'data-faz-data', 'data-faz-srcdoc' ) as $key ) {
				if ( $this->has_attr_token( $parked, $key ) ) {
					$labelled = true;
					break;
				}
			}
		}
		$out = $labelled ? $live . $parked : $this->label_category_first( $live . $parked, reset( $categories ) );
		return '<' . $tag . $out . $close . '>';
	}

	/**
	 * Whether a <link> fetches a resource: rel carries stylesheet, preload,
	 * modulepreload, prefetch or prerender.
	 *
	 * The same token set process_link_tag() gates in the live document, read
	 * the same way — rel is a space-separated TOKEN LIST whose value may be
	 * unquoted. dns-prefetch and preconnect resolve DNS and open a socket but
	 * transfer no URL and set no cookie, so they are not fetches, for the
	 * reason given there. The two lists must stay equal: a <link> judged
	 * differently inside and outside <noscript> is the layer drift this
	 * helper exists to prevent.
	 *
	 * @param string $attrs Attribute string.
	 * @return bool
	 */
	private function link_tag_fetches( $attrs ) {
		$rel_value = $this->extract_tag_attr( $attrs, 'rel' );
		if ( '' === $rel_value ) {
			return false;
		}
		$rel_tokens = preg_split( '/\s+/', strtolower( trim( $rel_value ) ), -1, PREG_SPLIT_NO_EMPTY );
		return ! empty( $rel_tokens ) && array() !== array_intersect( $rel_tokens, array( 'stylesheet', 'preload', 'modulepreload', 'prefetch', 'prerender' ) );
	}

	/**
	 * Stash an <iframe srcdoc>, parking it when its markup names a blocked
	 * provider.
	 *
	 * The attribute is a document, so the unit of judgement is the whole
	 * attribute: the sub-document's resources are fetched by a parser that no
	 * gate on either side of the plugin can reach once the context exists.
	 * Every absolute URL the DECODED markup names is matched through the
	 * ordinary provider matcher, and the first blocked one decides.
	 *
	 * Whatever the verdict, the srcdoc token leaves the buffer here and comes
	 * back only when every other pass has run, byte-for-byte: the passes are
	 * regexes over markup and the value IS markup. A parked srcdoc takes the
	 * frame's src with it, because the engine ignores src while srcdoc is
	 * present and removing srcdoc would activate it; both park under ONE
	 * data-faz-category, and the frame is hidden like a parked-src frame.
	 *
	 * @param array $m                  Regex match: the iframe's attributes.
	 * @param array $providers          Provider-to-category map.
	 * @param array $blocked_categories Currently blocked category slugs.
	 * @param array $stash              Marker => original token, filled here.
	 * @return string
	 */
	private function process_iframe_srcdoc_tag( $m, $providers, $blocked_categories, array &$stash, $marker_prefix ) {
		$attrs = isset( $m[1] ) ? $m[1] : '';
		$close = isset( $m[2] ) ? $m[2] : '';
		$full  = isset( $m[0] ) ? $m[0] : '';

		// EVERY srcdoc and data-faz-srcdoc token leaves the buffer. The parser
		// keeps the first of a duplicated attribute, so the first live srcdoc
		// is the one judged — and once it is parked the next one would become
		// THE srcdoc, so a parked verdict applies to all of them. A parked
		// token is protected, not re-judged; a live one next to it is judged
		// all the same, because the parked one is no verdict on it and the
		// engine loads the live one.
		$category = $this->extract_tag_attr( $attrs, 'data-faz-category' );
		$signature = $this->extract_tag_attr( $attrs, 'data-faz-srcdoc-sig' );
		// Strip every old proof before rebuilding one for the current response.
		foreach ( array_reverse( $this->tokenize_tag_attrs( $attrs, array( 'data-faz-srcdoc-sig', 'data-faz-srcdoc-key' ) ) ) as $proof ) {
			$proof_start = $proof['offset'] > 0 && ' ' === $attrs[ $proof['offset'] - 1 ] ? $proof['offset'] - 1 : $proof['offset'];
			$attrs = substr_replace( $attrs, '', $proof_start, $proof['length'] + $proof['offset'] - $proof_start );
		}
		$tokens = $this->tokenize_tag_attrs( $attrs, array( 'srcdoc', 'data-faz-srcdoc' ) );
		if ( array() === $tokens ) {
			return $full;
		}
		$doc = null;
		foreach ( $tokens as $token ) {
			if ( 'srcdoc' === $token['key'] && null !== $token['value'] && '' !== $token['value'] ) {
				$doc = $token;
				break;
			}
		}

		// The marker names its kind: LIVE for a `srcdoc` token, KEPT for an
		// already-parked data-faz-srcdoc. The iframe pass skips a frame only
		// on a LIVE one, because only a live srcdoc makes src inert.
		$rest = $attrs;
		foreach ( $tokens as $i => $token ) {
			$tokens[ $i ]['marker'] = $marker_prefix . ( 'srcdoc' === $token['key'] ? 'LIVE_' : 'KEPT_' ) . count( $stash ) . '__';
			$original = substr( $attrs, $token['offset'], $token['length'] );
			if ( 'data-faz-srcdoc' === $token['key'] ) {
				if ( null === $doc && null !== $token['value'] && '' !== $category && hash_equals( $this->srcdoc_signature( $token['value'], $category ), $signature ) ) {
					$original .= ' data-faz-srcdoc-sig="' . $signature . '" data-faz-srcdoc-key="' . self::srcdoc_key() . '"';
				} else {
					// Inert input stays inert; never promote it to a live srcdoc.
					$original = 'data-faz-untrusted-srcdoc=' . ( $token['value'] ?? '""' );
				}
			}
			$stash[ $tokens[ $i ]['marker'] ] = $original;
		}
		for ( $i = count( $tokens ) - 1; $i >= 0; $i-- ) {
			// Padded where the token was glued to its neighbour: the marker is
			// name characters, and `srcdoc="…"src=…` with the marker dropped
			// in bare became ONE token, so the src was never renamed and the
			// frame navigated to it once its srcdoc was gone.
			$at     = $tokens[ $i ]['offset'];
			$end    = $at + $tokens[ $i ]['length'];
			$before = $at > 0 ? $rest[ $at - 1 ] : ' ';
			$after  = $end < strlen( $rest ) ? $rest[ $end ] : ' ';
			$marker = ( ctype_space( $before ) || '/' === $before ? '' : ' ' ) . $tokens[ $i ]['marker'] . ( ctype_space( $after ) || '/' === $after ? '' : ' ' );
			$rest   = substr_replace( $rest, $marker, $at, $tokens[ $i ]['length'] );
		}
		if ( null === $doc ) {
			return '<iframe' . $rest . $close . '>';
		}

		$raw   = $doc['value'];
		$inner = ( strlen( $raw ) >= 2 && ( '"' === $raw[0] || "'" === $raw[0] ) ) ? substr( $raw, 1, -1 ) : $raw;
		if ( '' === $inner ) {
			return '<iframe' . $rest . $close . '>';
		}

		// The whitelist reads the frame's own src/id/class, so it is asked of
		// the attributes WITHOUT the document in them.
		$matched_category = '';
		if ( ! $this->is_whitelisted( $rest, '' ) ) {
			// Judged on what the engine would parse, not on the entity-encoded
			// source: `src=&quot;https://…&quot;` is a quoted URL to the parser.
			// Decoded until stable (the attribute decodes once, the document it
			// becomes decodes again, so `&amp;#97;` is a letter by the time the
			// request goes out) and stripped of tab/LF/CR the way the URL
			// parser strips them, or `&NewLine;` inside a host hid it from the
			// URL pattern below.
			$markup = $inner;
			for ( $pass = 0; $pass < 3; $pass++ ) {
				$decoded = self::decode_html_references( $markup );
				if ( $decoded === $markup ) {
					break;
				}
				$markup = $decoded;
			}
			$markup = str_replace( array( "\t", "\n", "\r" ), '', $markup );
			// Every `\` is a `/` to the URL parser the document will run, so
			// `\\host`, `/\host` and `\/host` are all `//host` here, and a meta
			// refresh to `\\host` is one too. For judging only.
			$markup = str_replace( '\\', '/', $markup );
			// `https:/host` and `https:host` are special-scheme URLs the engine
			// resolves to `https://host`; the normaliser gives them their slashes.
			if ( preg_match_all( '#(?:https?:(?://|/?)|//)[^\s"\'<>)]+#i', $markup, $found ) ) {
				foreach ( $found[0] as $url ) {
					$category = $this->match_script_to_provider( ' src="' . $this->normalize_resource_url( $url ) . '"', '', $providers, $blocked_categories );
					if ( $category && in_array( $category, $blocked_categories, true ) ) {
						$matched_category = $category;
						break;
					}
				}
			}
		}
		if ( '' === $matched_category ) {
			return '<iframe' . $rest . $close . '>';
		}

		foreach ( $tokens as $token ) {
			if ( 'srcdoc' === $token['key'] ) {
				$stash[ $token['marker'] ] = 'data-faz-srcdoc=' . $token['value'] . ' data-faz-srcdoc-sig="' . $this->srcdoc_signature( $token['value'], $matched_category ) . '" data-faz-srcdoc-key="' . self::srcdoc_key() . '"';
			}
		}
		// src goes with it, on its token in $rest, where the markers are plain
		// names and every offset is current.
		list( $rest ) = $this->rename_attr_tokens( $rest, array( 'src' => 'data-faz-src' ) );
		// One category: a parked token next to this one may have brought its
		// own. Metadata BEFORE the captured attributes, as in
		// process_img_tag(): a self-closing tag keeps its slash separate.
		return self::faz_add_hidden_class( '<iframe' . $this->label_category_first( $rest, $matched_category ) . $close . '>' );
	}

	/**
	 * Walk a tag's attribute string the way the HTML tokenizer reads it and
	 * return the tokens named in $wanted, in document order.
	 *
	 * Streaming: one preg_match per token, anchored at the previous token's
	 * end, keeping only what the caller asked for. The first shape of this
	 * method materialised EVERY token with preg_match_all and offset capture,
	 * about 1.3 KB each, so 100k attributes on one iframe cost 132 MB — a
	 * fatal under a 40 MB memory limit — and it ran for every <iframe> as soon
	 * as the page contained the word "srcdoc".
	 *
	 * A name counts wherever the tokenizer would start one: right after a
	 * closing quote (`title="x"src=…`) or a slash (`<img/src=…>`) as much as
	 * after whitespace. Every rename and lookup in this class goes through
	 * these tokens, so a `name=` inside another attribute's value is never
	 * mistaken for the attribute.
	 *
	 * @param string   $attrs  Attribute string, as captured after the tag name.
	 * @param string[] $wanted Attribute names to return, any case.
	 * @return array[] Each: name, key (lower-cased name), offset of the name,
	 *                 length of name-through-value, value (quotes included) or
	 *                 null for a bare attribute.
	 */
	private function tokenize_tag_attrs( $attrs, array $wanted ) {
		$tokens = array();
		$this->each_attr_token(
			$attrs,
			$wanted,
			function ( $token ) use ( &$tokens ) {
				$tokens[] = $token;
				return true;
			}
		);
		return $tokens;
	}

	/**
	 * The streaming walk behind every token helper: one anchored preg_match
	 * per token, the callback invoked for each wanted one with the same shape
	 * tokenize_tag_attrs() returns, nothing kept. The callback returns false
	 * to stop the walk.
	 *
	 * @param string   $attrs    Attribute string.
	 * @param string[] $wanted   Attribute names, any case.
	 * @param callable $callback function ( array $token ): bool
	 * @return void
	 */
	private function each_attr_token( $attrs, array $wanted, $callback ) {
		$attrs  = (string) $attrs;
		$length = strlen( $attrs );
		$lookup = array_fill_keys( array_map( 'strtolower', $wanted ), true );
		$offset = 0;
		while ( $offset < $length && preg_match( self::ATTR_TOKEN, $attrs, $f, PREG_OFFSET_CAPTURE, $offset ) ) {
			$end = $f[0][1] + strlen( $f[0][0] );
			if ( $end <= $offset ) {
				break; // Cannot happen with this pattern; never loop forever if it did.
			}
			$offset = $end;
			$key    = strtolower( $f[1][0] );
			if ( ! isset( $lookup[ $key ] ) ) {
				continue;
			}
			$has_value = isset( $f[2] ) && -1 !== $f[2][1];
			$token     = array(
				'name'   => $f[1][0],
				'key'    => $key,
				'offset' => $f[1][1],
				'length' => $end - $f[1][1],
				'value'  => $has_value ? $f[2][0] : null,
			);
			if ( false === $callback( $token ) ) {
				return;
			}
		}
	}

	/**
	 * Rewrite the wanted tokens in ONE pass over the string: the output is
	 * built from substr() slices between tokens, so a megabyte of repeated
	 * attributes costs one copy, not one copy per token. A per-token
	 * substr_replace() over 75k `type` tokens took 3.2 s and 65 MB — a fatal
	 * in the output buffer under an ordinary memory limit.
	 *
	 * @param string   $attrs   Attribute string.
	 * @param string[] $wanted  Attribute names, any case.
	 * @param callable $replace function ( array $token ): ?string — the text
	 *                          replacing the token, or null to keep it.
	 * @return string
	 */
	private function rewrite_attr_tokens( $attrs, array $wanted, $replace ) {
		$attrs = (string) $attrs;
		$out   = '';
		$pos   = 0;
		$this->each_attr_token(
			$attrs,
			$wanted,
			function ( $token ) use ( &$out, &$pos, $attrs, $replace ) {
				$replacement = $replace( $token );
				if ( null === $replacement ) {
					return true;
				}
				$out .= substr( $attrs, $pos, $token['offset'] - $pos ) . $replacement;
				$pos  = $token['offset'] + $token['length'];
				return true;
			}
		);
		return $out . substr( $attrs, $pos );
	}

	/**
	 * Remove every token of this name, any casing or spacing.
	 *
	 * @param string $attrs Attribute string.
	 * @param string $name  Attribute name.
	 * @return string
	 */
	private function strip_attr_tokens( $attrs, $name ) {
		return $this->rewrite_attr_tokens(
			$attrs,
			array( $name ),
			function () {
				return '';
			}
		);
	}

	/**
	 * The matcher's category, FIRST and alone. A page-authored
	 * data-faz-category="necessary" next to a live tracker attribute is a
	 * restore order — the runtime restores a parked resource whose category
	 * is allowed, before consent — so when a pass parks a live attribute every
	 * category token already on the tag is removed and exactly one, the
	 * matcher's, is written ahead of the captured attributes. Only a tag with
	 * nothing live to park keeps what it carries, and those return earlier.
	 *
	 * @param string $attrs    Attribute string, already rewritten.
	 * @param string $category The matcher's category.
	 * @return string
	 */
	private function label_category_first( $attrs, $category ) {
		return ' data-faz-category="' . esc_attr( $category ) . '"' . $this->strip_attr_tokens( $attrs, 'data-faz-category' );
	}

	/**
	 * Whether the attribute string carries a token of this name.
	 *
	 * @param string $attrs Attribute string.
	 * @param string $name  Attribute name.
	 * @return bool
	 */
	private function has_attr_token( $attrs, $name ) {
		$found = false;
		$this->each_attr_token(
			$attrs,
			array( $name ),
			function () use ( &$found ) {
				$found = true;
				return false;
			}
		);
		return $found;
	}

	/**
	 * Rename every token named in $map to the mapped name, in place, on the
	 * tokens the tokenizer produces. A bare attribute (no value) fetches
	 * nothing and is left alone. Every occurrence is renamed, because a
	 * duplicated attribute is kept first-wins by the parser and renaming the
	 * first alone left the second live.
	 *
	 * @param string $attrs Attribute string.
	 * @param array  $map   name => new name.
	 * @return array{0:string,1:array} The rewritten string and the keys renamed.
	 */
	private function rename_attr_tokens( $attrs, array $map ) {
		$attrs   = (string) $attrs;
		$renamed = array();
		$out     = $this->rewrite_attr_tokens(
			$attrs,
			array_keys( $map ),
			function ( $token ) use ( &$renamed, $attrs, $map ) {
				if ( null === $token['value'] ) {
					return null;
				}
				$renamed[ $token['key'] ] = true;
				// A leading space when the token sits right after a quote or a
				// slash, where the new name would otherwise glue to it.
				$glued = $token['offset'] > 0 && ! ctype_space( $attrs[ $token['offset'] - 1 ] );
				return ( $glued ? ' ' : '' ) . $map[ $token['key'] ] . '=' . $token['value'];
			}
		);
		return array( $out, $renamed );
	}

	/**
	 * A resource URL as the engine resolves it, for matching only.
	 *
	 * Chromium decodes character references, strips tab, LF and CR anywhere
	 * in the input, and percent-decodes the host before it resolves anything,
	 * so `cdn.tr&#97;cker.test`, `cdn.tra&Tab;cker.test`, a literal newline
	 * inside the host and `cdn.tracker%2Etest` are all one host to it and
	 * were four different strings to a substring match on the raw bytes. A
	 * contributor can write any of them through wp_kses. The decode loops
	 * until stable, capped, so a doubly-encoded reference is seen through as
	 * well; only the host is percent-decoded, the path keeps its bytes.
	 *
	 * @param string $value Raw attribute value.
	 * @return string
	 */
	private function normalize_resource_url( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$decoded = self::decode_html_references( $value );
			if ( $decoded === $value ) {
				break;
			}
			$value = $decoded;
		}
		$value = str_replace( array( "\t", "\n", "\r" ), '', $value );
		// WHATWG turns every `\` into `/` for a special scheme, BEFORE the
		// host is read: `https:\\host/p` is `https://host/p`, and
		// `https://cdn.tracker.test\@site.test/p` has host cdn.tracker.test
		// with `@site.test` in its path. A parse of the raw bytes read the
		// decoy as the host, which the whitelist then trusted.
		// The class is `[\\/]` in the pattern, i.e. a backslash or a slash:
		// written as `[\\/]` in a single-quoted string it was `[\/]`, only
		// ever a slash, and `\\host` was never normalised.
		if ( preg_match( '#^\s*(?:https?:|[\\\\/]{2})#i', $value ) ) {
			$value = str_replace( '\\', '/', $value );
		}
		// A special scheme takes exactly two slashes whatever was written:
		// `https:/host` and `https:host` resolve to `https://host`.
		$value = preg_replace( '#^(\s*https?:)/*#i', '$1//', $value );
		if ( preg_match( '#^(\s*(?:[a-z][a-z0-9+.\-]*:)?//)([^/?\#\s]+)(.*)$#is', $value, $p ) ) {
			// The host is what follows the LAST `@` of the authority: the
			// engine reads `https://site.test@cdn.tracker.test/` from
			// cdn.tracker.test, and once the backslashes above are slashes,
			// `site.test\@cdn.tracker.test` has already closed its authority
			// at site.test. Dropping the userinfo here is what lets the
			// boundary check work on host and path alone, with no `@`
			// separator that would over-park a decoy in a path or a query.
			$authority = $p[2];
			$at        = strrpos( $authority, '@' );
			if ( false !== $at ) {
				$authority = substr( $authority, $at + 1 );
			}
			$value = $p[1] . rawurldecode( $authority ) . $p[3];
		}
		return $value;
	}

	/**
	 * The same, for a comma-separated candidate list (srcset, imagesrcset).
	 *
	 * @param string $value Raw list.
	 * @return string
	 */
	private function normalize_resource_list( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		$parts = explode( ',', $value );
		foreach ( $parts as $i => $part ) {
			$parts[ $i ] = $this->normalize_resource_url( ltrim( $part ) );
		}
		return implode( ', ', $parts );
	}

	/**
	 * A per-request nonce for the stash markers. A fixed marker could be
	 * spelled by the page — a paragraph containing `__FAZ_SRCDOC_STASH_0__`
	 * received the srcdoc token as live markup — so the markers carry
	 * something the page cannot know.
	 *
	 * @return string
	 */
	/**
	 * Replace every iframe srcdoc token with a marker no later pass can match.
	 *
	 * Shared by both entry points on purpose. This protection first existed
	 * only in process_output_buffer(), and filter_content_blocking() — which
	 * runs the same script and iframe passes over `the_content` — did not have
	 * it, so a <script> inside a srcdoc was re-typed in place and the injected
	 * double-quoted attribute ended the srcdoc value at its first quote. One
	 * helper, one place to keep right.
	 *
	 * @param string $html               Markup to walk.
	 * @param array  $providers          Provider-to-category map.
	 * @param array  $blocked_categories Currently blocked category slugs.
	 * @param string $stash_nonce        Per-request nonce the page cannot know.
	 * @param array  $stash              Marker => original token, filled here.
	 * @param string $marker             Marker prefix, set here.
	 * @param array  $failed             Optional: the failed pass kinds, appended to.
	 * @return string|null Markup with the tokens stashed. On a PCRE error the
	 *                     linear fallback stashes them (run_markup_pass()); null
	 *                     only when `faz_output_buffer_fail_closed` is false.
	 */
	private function stash_iframe_srcdocs( $html, $providers, $blocked_categories, $stash_nonce, array &$stash, &$marker, &$failed = null ) {
		$marker = '__FAZ_SRCDOC_' . $stash_nonce . '_';
		$stash  = array();
		if ( false === stripos( $html, 'srcdoc' ) ) {
			return $html;
		}
		$failures = array();
		$unused   = array();
		$result   = $this->run_markup_pass(
			$html,
			'#' . self::tag_token_skip( 'iframe' ) . '<iframe(?=[\s/>])(' . self::ATTR_WALK . ')(/?)\s*+>#is',
			'srcdoc',
			function ( $m ) use ( $providers, $blocked_categories, &$stash, $marker ) {
				return $this->process_iframe_srcdoc_tag( $m, $providers, $blocked_categories, $stash, $marker );
			},
			$failures,
			$unused,
			function () {
				return null;
			}
		);
		if ( is_array( $failed ) ) {
			$failed = array_merge( $failed, $failures );
		}
		return $result;
	}

	/** Refresh authenticated cached fragments even when no categories are blocked. */
	private function refresh_srcdoc_proofs( $html ) {
		$stash = array();
		$marker = '';
		$result = $this->stash_iframe_srcdocs( $html, array(), array(), $this->stash_nonce(), $stash, $marker );
		return null === $result ? $html : strtr( $result, $stash );
	}

	private function stash_nonce() {
		try {
			return bin2hex( random_bytes( 6 ) );
		} catch ( \Exception $e ) {
			return substr( md5( uniqid( '', true ) ), 0, 12 );
		}
	}

	/**
	 * Spans of a document the HTML parser reads as inert text: closed
	 * comments, and the bodies of <script>, <style>, <textarea>, <title> and
	 * <xmp> in HTML content.
	 *
	 * Every pass in the output buffer is a regex over the whole response, and
	 * a response carries markup that is not markup. Inside a script it is
	 * usually a string literal — `"<video poster=\"https://i.ytimg.com/…\">"`
	 * — and a pass that parked it wrote `data-faz-category="marketing"` into
	 * a double-quoted literal, which ended the literal at the first injected
	 * quote and stopped the WHOLE inline script from parsing, the data
	 * scripts #322 leaves executable included. A tag a script writes is
	 * judged by the runtime when it is written, and a blocked script is
	 * restored only once its category is allowed, so the server has nothing
	 * to do inside the body.
	 *
	 * A regex alternation in front of each pass could not get this right:
	 * the spans are a property of the HTML tokenizer, and imitating it
	 * piecemeal hid real elements behind tokens it did not know. So this is
	 * a small tokenizer, one linear walk, and every rule in it errs the same
	 * way — when unsure, a span is NOT inert, and the passes see it:
	 *
	 * - Every construct the tokenizer reads as one token is consumed whole
	 *   first, so a `<script` written inside it never opens a span: start and
	 *   end tags with their attributes (`</p title="<script>">` is an end tag
	 *   with an attribute), bogus comments (`<?…>`, `<!…>`, `</ …>`, all up
	 *   to the first `>`) and comments.
	 * - A comment ends as the parser ends it: `<!-->`, `<!--->`, `-->` or
	 *   `--!>`. One that never closes is not inert; the walk goes on after
	 *   its `<!--`.
	 * - Inside <svg> and <math> nothing is raw text: there <title>, <style>
	 *   and <script> are elements with children, and an <img> in them breaks
	 *   out and loads. The walk counts those containers open and closed and
	 *   records no span while one is open (a breakout it does not model
	 *   keeps it counting: more is judged, never less).
	 * - A raw-text body ends at the first `</name` followed by whitespace,
	 *   `/` or `>`, which is never later than where the parser ends it. A
	 *   body with no end ends the walk: nothing after it is recorded.
	 * - <iframe>, <noembed> and <noframes> bodies are raw text to the parser
	 *   and are recorded like the others; only the BODY is, so the iframe
	 *   pass still sees the opening tag it parks, and the body comes back
	 *   inside its placeholder. Walked past without being recorded, a quoted
	 *   value of a tag-shaped string in one of them (`<noembed><a title="`)
	 *   ran past the end tag in the opening-tag walk of the media and
	 *   style-attribute passes and swallowed the real <video> after it,
	 *   which shipped live.
	 * - <noscript> is walked past but not recorded: the noscript pass reads
	 *   its body.
	 * - Everything after <plaintext> is text to the end of the document, and
	 *   is recorded as one span.
	 *
	 * @param string $html Document.
	 * @return array<int,array{0:int,1:int}>|null Offset/length pairs in
	 *         document order, or null when the walk could not finish (a PCRE
	 *         error), in which case nothing may be treated as inert.
	 */
	private function find_inert_spans( $html ) {
		$spans     = array();
		$len       = strlen( $html );
		$pos       = 0;
		$foreign   = 0;
		$dash_next = -1;
		$bang_next = -1;
		$recorded  = array( 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes' );
		$walked    = array( 'noscript' );
		$tag_re    = '#\G<([a-zA-Z][^\s/>]*+)' . self::ATTR_WALK . '(/\s*+)?>#';
		$end_re    = '#\G</[a-zA-Z][^\s/>]*+' . self::ATTR_WALK . '(?:/\s*+)?>#';
		while ( $pos < $len ) {
			$lt = strpos( $html, '<', $pos );
			if ( false === $lt || $lt + 1 >= $len ) {
				break;
			}
			$c = $html[ $lt + 1 ];
			if ( '!' === $c && '<!--' === substr( $html, $lt, 4 ) ) {
				$p = $lt + 4;
				if ( $p < $len && '>' === $html[ $p ] ) {
					$end = $p + 1;
				} elseif ( '->' === substr( $html, $p, 2 ) ) {
					$end = $p + 2;
				} else {
					if ( false !== $dash_next && $dash_next < $p ) {
						$dash_next = strpos( $html, '-->', $p );
					}
					if ( false !== $bang_next && $bang_next < $p ) {
						$bang_next = strpos( $html, '--!>', $p );
					}
					$a   = false === $dash_next ? PHP_INT_MAX : $dash_next + 3;
					$b   = false === $bang_next ? PHP_INT_MAX : $bang_next + 4;
					$end = min( $a, $b );
				}
				if ( PHP_INT_MAX === $end ) {
					$pos = $p; // Never closed: not inert, keep walking.
					continue;
				}
				$spans[] = array( $lt, $end - $lt );
				$pos     = $end;
				continue;
			}
			if ( '!' === $c || '?' === $c || ( '/' === $c && ( $lt + 2 >= $len || ! ctype_alpha( $html[ $lt + 2 ] ) ) ) ) {
				// A bogus comment (or `</>`), one token up to the first `>`.
				$gt = strpos( $html, '>', $lt + 2 );
				if ( false === $gt ) {
					break;
				}
				$pos = $gt + 1;
				continue;
			}
			if ( '/' === $c ) {
				$ok = preg_match( $end_re, $html, $m, 0, $lt );
				if ( false === $ok ) {
					return null;
				}
				if ( 0 === $ok ) {
					break; // A tag still open at the end of the document.
				}
				if ( $foreign > 0 && preg_match( '#\G</(?:svg|math)(?=[\s/>])#i', $html, $unused, 0, $lt ) ) {
					$foreign--;
				}
				$pos = $lt + strlen( $m[0] );
				continue;
			}
			if ( ! ctype_alpha( $c ) ) {
				$pos = $lt + 1; // A `<` that starts nothing is text.
				continue;
			}
			$ok = preg_match( $tag_re, $html, $m, 0, $lt );
			if ( false === $ok ) {
				return null;
			}
			if ( 0 === $ok ) {
				break;
			}
			$name  = strtolower( $m[1] );
			$after = $lt + strlen( $m[0] );
			$pos   = $after;
			if ( 'svg' === $name || 'math' === $name ) {
				if ( empty( $m[2] ) ) {
					$foreign++;
				}
				continue;
			}
			if ( $foreign > 0 ) {
				continue;
			}
			if ( 'plaintext' === $name ) {
				if ( $after < $len ) {
					$spans[] = array( $after, $len - $after );
				}
				break;
			}
			$is_recorded = in_array( $name, $recorded, true );
			if ( ! $is_recorded && ! in_array( $name, $walked, true ) ) {
				continue;
			}
			$ok = preg_match( '#</' . $name . '(?=[\s/>])#i', $html, $close, PREG_OFFSET_CAPTURE, $after );
			if ( false === $ok ) {
				return null;
			}
			if ( 0 === $ok ) {
				break;
			}
			$close_at = $close[0][1];
			if ( $is_recorded && $close_at > $after ) {
				$spans[] = array( $after, $close_at - $after );
			}
			$pos = $close_at;
		}
		return $spans;
	}

	/**
	 * Leading alternation for the passes that find one element by name: every
	 * construct the tokenizer reads as one token, consumed whole and skipped.
	 *
	 * A pattern anchored on a tag name starts a match wherever the name
	 * stands, and a `<script`, `<img` or `<iframe` written INSIDE one of these
	 * tokens is not an element: `</p title="<script>">` is an end tag with an
	 * attribute, `<div data-html="<img src='…'>">` is a tooltip library
	 * keeping HTML in a value, and `<?x …>`, `<!x …>`, `</ …>` are bogus
	 * comments that end at their first `>`. Matched there, the script pattern
	 * took the real tracker after it for the body of a script that does not
	 * exist, and the tracker shipped live; the img and iframe patterns wrote
	 * double-quoted data-faz-* keys (or a whole placeholder) into the value,
	 * which closed it early and broke the markup. Other opening tags are
	 * consumed through ATTR_WALK for the same reason, which is how the linear
	 * fallback (linear_markup_pass()) already reads them, so both paths agree.
	 * `<!--` is left out: a closed comment is already stashed, and one that
	 * never closes is judged rather than skipped.
	 * Every quantifier is possessive: nothing here can backtrack, so this
	 * adds no PCRE-limit failure to a pass. Closed comments and raw-text
	 * bodies are already out of the buffer (stash_inert_spans()).
	 *
	 * @param string $name Lower-case name of the element the pass looks for,
	 *                     or a non-capturing group of names for a pass that
	 *                     looks for several.
	 * @return string Pattern fragment ending in `|`, for the `#` delimiter.
	 */
	private static function tag_token_skip( $name ) {
		return '(?:' . self::non_opening_tokens()
			. '|<(?!' . $name . '(?=[\s/>]))[a-zA-Z][^\s/>]*+' . self::ATTR_WALK . '(?:/\s*+)?>'
			. ')(*SKIP)(*FAIL)|';
	}

	/**
	 * Leading alternation for the passes that walk every opening tag (media,
	 * style attribute): the one-token constructs that are not opening tags,
	 * consumed whole and skipped.
	 *
	 * Walking every opening tag keeps a value quoted inside one whole, but an
	 * end tag with an attribute, `<?…>`, `<!x…>` and `</ …>` were not walked:
	 * the regex moved on one character at a time into them, and a `<video` or
	 * `<b style=…>` inside `</p title="…">` or a bogus comment was rewritten
	 * there, with double-quoted keys that closed the value early. These are
	 * the same alternatives tag_token_skip() leads with, without the opening
	 * tags, which these passes match themselves.
	 *
	 * @return string Pattern fragment ending in `|`, for the `#` delimiter.
	 */
	private static function non_opening_token_skip() {
		return '(?:' . self::non_opening_tokens() . ')(*SKIP)(*FAIL)|';
	}

	/**
	 * The one-token constructs other than an opening tag, as alternatives:
	 * an end tag (attributes walked through ATTR_WALK), `<?…>`, `<!x…>` but
	 * not `<!--`, and `</` not followed by a letter.
	 *
	 * @return string Alternation without a wrapping group.
	 */
	private static function non_opening_tokens() {
		return '</[a-zA-Z][^\s/>]*+' . self::ATTR_WALK . '(?:/\s*+)?>'
			. '|<\?[^>]*+>|<!(?!--)[^>]*+>'
			. '|</(?![a-zA-Z])[^>]*+>';
	}

	/**
	 * tag_token_skip() for the step-1 script pattern.
	 *
	 * @return string Pattern fragment ending in `|`, for the `#` delimiter.
	 */
	private static function script_token_skip() {
		return self::tag_token_skip( 'script' );
	}

	/**
	 * The <iframe> element pattern, shared by both entry points.
	 *
	 * Groups as process_iframe_tag() reads them: 1 attributes, 2 body (absent
	 * when self-closed). Led by tag_token_skip(), and closed by a skip of an
	 * <iframe> opening tag that has no end tag, so a value inside that tag is
	 * not read as a second frame either.
	 *
	 * @return string
	 */
	private static function iframe_pass_pattern() {
		return '#' . self::tag_token_skip( 'iframe' )
			. '<iframe(?=[\s/>])(' . self::ATTR_WALK . ')(?:>(.*?)</iframe(?=[\s/>])[^>]*>|/\s*+>)'
			. '|<iframe(?=[\s/>])' . self::ATTR_WALK . '(?:/\s*+)?>(*SKIP)(*FAIL)#is';
	}

	/**
	 * Replace every inert span with a marker no pass can match.
	 *
	 * The markers carry the request nonce, like the srcdoc and noscript
	 * stashes, so the page cannot spell one. The script and style passes,
	 * which must read a body, are handed it back by process_with_inert_body().
	 *
	 * @param string $html  Document.
	 * @param string $nonce Per-request nonce.
	 * @param array  $stash Marker => original text, filled here.
	 * @return string Document with the spans stashed; unchanged when the walk
	 *                could not finish.
	 */
	private function stash_inert_spans( $html, $nonce, array &$stash ) {
		$stash = array();
		$spans = $this->find_inert_spans( $html );
		if ( empty( $spans ) ) {
			return $html;
		}
		$out  = '';
		$last = 0;
		foreach ( $spans as $span ) {
			$key           = '__FAZ_INERT_' . $nonce . '_' . count( $stash ) . '__';
			$stash[ $key ] = substr( $html, $span[0], $span[1] );
			$out          .= substr( $html, $last, $span[0] - $last ) . $key;
			$last          = $span[0] + $span[1];
		}
		return $out . substr( $html, $last );
	}

	/**
	 * What the linear fallback of each markup pass looks for, and the shape
	 * of the match array the pass's callback is written against.
	 *
	 * Shapes, as the pass's own pattern captures them:
	 * - body:    0 element, 1 attributes, 2 body (script, style).
	 * - inner:   0 element, 1 body (noscript).
	 * - iframe:  0 element, 1 attributes, 2 body; no 2 when self-closed.
	 * - open:    0 tag, 1 attributes with the closing slash (img, link).
	 * - open2:   0 tag, 1 attributes, 2 slash (the srcdoc stash).
	 * - open3:   0 tag, 1 name, 2 attributes, 3 slash (svg, media, style attr).
	 * - named:   0 tag, 1 name, 2 attributes (background).
	 *
	 * `*` stands for every opening tag: the style-attribute pattern accepts
	 * any name that starts with a letter and runs to whitespace, `/` or `>`,
	 * which is how the walk reads a name.
	 */
	const LINEAR_PASS_KINDS = array(
		'srcdoc'     => array( 'tags' => array( 'iframe' ), 'shape' => 'open2' ),
		'noscript'   => array( 'tags' => array( 'noscript' ), 'shape' => 'inner' ),
		'script'     => array( 'tags' => array( 'script' ), 'shape' => 'body' ),
		'svg'        => array( 'tags' => array( 'image', 'use', 'feimage' ), 'shape' => 'open3' ),
		'background' => array( 'tags' => array( 'body', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ), 'shape' => 'named' ),
		'iframe'     => array( 'tags' => array( 'iframe' ), 'shape' => 'iframe' ),
		'style'      => array( 'tags' => array( 'style' ), 'shape' => 'body' ),
		'img'        => array( 'tags' => array( 'img' ), 'shape' => 'open' ),
		'media'      => array( 'tags' => array( 'video', 'audio', 'source', 'embed', 'object', 'track', 'input' ), 'shape' => 'open3' ),
		'link'       => array( 'tags' => array( 'link' ), 'shape' => 'open' ),
		'style-attr' => array( 'tags' => '*', 'shape' => 'open3' ),
		'waitfor'    => array( 'tags' => array( 'script' ), 'shape' => 'body' ),
	);

	/**
	 * Run one markup pass, and stay fail-closed when PCRE gives up on it.
	 *
	 * preg_replace_callback() returns null for the WHOLE document when it
	 * hits a limit anywhere in it (a megabyte of unterminated inline script
	 * exhausts pcre.backtrack_limit in the lazy body of the script pattern),
	 * and every element it had already judged is lost with it. Serving the
	 * buffer as it was then shipped every server-rendered tracker on the
	 * page executable before consent: the one failure a consent layer must
	 * not turn into an allow.
	 *
	 * So the same callback is run again over a linear walk of the document
	 * (linear_markup_pass()), which finds the same elements with string
	 * functions and nothing that can backtrack. Each element is judged by
	 * the processor that judges it on the regex path, so the decision (and
	 * the parked shape the runtime restores after consent: text/plain,
	 * data-faz-src, data-faz-category) is the one a working pass produces,
	 * whitelist, necessary category and own-asset exemptions included.
	 *
	 * The callbacks of the failed run may already have written into the
	 * inert stash; it is put back as it was before the run, so the body of
	 * a <style> the CSS pass rewrote is not rewritten a second time.
	 *
	 * The `faz_output_buffer_fail_closed` filter (default true) returns the
	 * previous behaviour: `$legacy` when given, the document unchanged
	 * otherwise.
	 *
	 * @param string        $html        Document.
	 * @param string        $pattern     The pass's pattern.
	 * @param string        $kind        Key of LINEAR_PASS_KINDS.
	 * @param callable      $callback    The pass's callback.
	 * @param array         $failed      Kinds that failed, with the PCRE error code, appended to.
	 * @param array         $inert_stash Inert stash shared with the callbacks.
	 * @param callable|null $legacy      Behaviour with fail-closed turned off.
	 * @return string
	 */
	private function run_markup_pass( $html, $pattern, $kind, $callback, array &$failed, array &$inert_stash, $legacy = null ) {
		$snapshot = $inert_stash;
		$result   = preg_replace_callback( $pattern, $callback, $html );
		if ( null !== $result ) {
			return $result;
		}
		$failed[]    = $kind . ' (PCRE error ' . preg_last_error() . ')';
		$inert_stash = $snapshot;
		if ( ! $this->markup_fail_closed() ) {
			return null === $legacy ? $html : $legacy( $html );
		}
		return $this->linear_markup_pass( $html, $kind, $callback );
	}

	/**
	 * Whether a failed markup pass falls back to the linear walk.
	 *
	 * @return bool
	 */
	private function markup_fail_closed() {
		if ( null === $this->markup_fail_closed_cache ) {
			/**
			 * Whether a markup pass that PCRE could not complete (backtrack or
			 * recursion limit, JIT stack) is re-run over a linear walk of the
			 * document instead of being skipped.
			 *
			 * Skipping it serves the page with every element that pass judges
			 * left as the server rendered it, trackers included. Return false
			 * only to restore that behaviour.
			 *
			 * @since 1.34.2
			 *
			 * @param bool $fail_closed Default true.
			 */
			$this->markup_fail_closed_cache = (bool) apply_filters( 'faz_output_buffer_fail_closed', true );
		}
		return $this->markup_fail_closed_cache;
	}

	/**
	 * Log, at most once an hour, that a markup pass fell back.
	 *
	 * A page that trips the limit trips it on every request; one line per
	 * request said nothing the first one had not, and filled the log.
	 *
	 * @param array  $failed  Kinds that failed.
	 * @param string $context Entry point, for the log line.
	 * @return void
	 */
	private function log_markup_pass_failure( array $failed, $context ) {
		if ( empty( $failed ) ) {
			return;
		}
		$key = 'faz_markup_pcre_fail_logged';
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );
		$what = $this->markup_fail_closed()
			? 'the elements were judged by the linear fallback'
			: 'blocking was SKIPPED for those elements (faz_output_buffer_fail_closed is false)';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'FAZ Cookie Manager: ' . $context . ' pass(es) ' . implode( ', ', array_unique( $failed ) ) . '; ' . $what . '. pcre.backtrack_limit is ' . ini_get( 'pcre.backtrack_limit' ) . '. Logged at most once an hour.' );
	}

	/**
	 * The linear walk behind run_markup_pass(): find the elements a pass
	 * looks for with string functions only, build the match its pattern
	 * would have captured, and hand it to the pass's callback.
	 *
	 * The walk reads the document as the HTML tokenizer does, as
	 * find_inert_spans() does, and errs the same way, toward judging:
	 * - every tag, start or end, is consumed with its attributes, so a
	 *   `<script` inside an attribute value never opens an element;
	 * - bogus comments (`<?…>`, `<!…>`, `</ …>`) end at their first `>`; a
	 *   `<!--` is walked into, not skipped (a closed comment is already in
	 *   the inert stash, and a tag inside one that is not gets judged, which
	 *   is harmless in text);
	 * - the bodies of script, style, textarea, title and xmp are text and
	 *   are walked past, except inside svg and math, where they are not;
	 * - a body with no end runs to the end of the document. For a script
	 *   that is what the parser does as well (the element is never run), and
	 *   for an iframe it is the frame the parser builds, src included.
	 *
	 * Cost is one strpos per `<` plus a strcspn per attribute: no pattern,
	 * so nothing in it can exhaust a PCRE limit.
	 *
	 * @param string   $html     Document.
	 * @param string   $kind     Key of LINEAR_PASS_KINDS.
	 * @param callable $callback The pass's callback.
	 * @return string
	 */
	private function linear_markup_pass( $html, $kind, $callback ) {
		$spec    = self::LINEAR_PASS_KINDS[ $kind ];
		$tags    = $spec['tags'];
		$shape   = $spec['shape'];
		$raw     = array( 'script', 'style', 'textarea', 'title', 'xmp' );
		$len     = strlen( $html );
		$out     = '';
		$last    = 0;
		$pos     = 0;
		$foreign = 0;
		while ( $pos < $len ) {
			$lt = strpos( $html, '<', $pos );
			if ( false === $lt || $lt + 1 >= $len ) {
				break;
			}
			$c = $html[ $lt + 1 ];
			if ( '!' === $c || '?' === $c ) {
				if ( '<!--' === substr( $html, $lt, 4 ) ) {
					$pos = $lt + 4;
					continue;
				}
				$gt = strpos( $html, '>', $lt + 2 );
				if ( false === $gt ) {
					break;
				}
				$pos = $gt + 1;
				continue;
			}
			$is_end  = '/' === $c;
			$name_at = $is_end ? $lt + 2 : $lt + 1;
			if ( $name_at >= $len || ! ctype_alpha( $html[ $name_at ] ) ) {
				if ( ! $is_end ) {
					$pos = $lt + 1; // A `<` that starts nothing is text.
					continue;
				}
				$gt = strpos( $html, '>', $lt + 2 ); // `</ …>`: a bogus comment.
				if ( false === $gt ) {
					break;
				}
				$pos = $gt + 1;
				continue;
			}
			$name_len = strcspn( $html, " \t\n\x0B\f\r/>", $name_at );
			$name_end = $name_at + $name_len;
			$name     = substr( $html, $name_at, $name_len );
			$lower    = strtolower( $name );
			$tag      = self::linear_tag_end( $html, $name_end, $len );
			if ( null === $tag ) {
				break; // A tag still open at the end: the rest is inside it.
			}
			$gt    = $tag[0];
			$slash = $tag[1];
			if ( $is_end ) {
				if ( $foreign > 0 && ( 'svg' === $lower || 'math' === $lower ) ) {
					$foreign--;
				}
				$pos = $gt + 1;
				continue;
			}

			$attrs_end = false === $slash ? $gt : $slash;
			$end       = $gt + 1;
			if ( '*' === $tags ) {
				// Every opening tag, its name read to whitespace, `/` or `>` as
				// the pass's pattern reads it. A narrower class here left a
				// custom element such as <x-tipé> unjudged on exactly the page
				// where the regex had failed, with its url() live.
				$is_target = true;
			} else {
				$is_target = in_array( $lower, $tags, true );
			}
			$has_body = 'body' === $shape || 'inner' === $shape || ( 'iframe' === $shape && false === $slash );

			if ( $is_target ) {
				$attrs = substr( $html, $name_end, $attrs_end - $name_end );
				if ( $has_body ) {
					$close = self::linear_close_tag( $html, $lower, $gt + 1, $len );
					$body  = null === $close ? substr( $html, $gt + 1 ) : substr( $html, $gt + 1, $close[0] - $gt - 1 );
					$end   = null === $close ? $len : $close[1];
					$full  = substr( $html, $lt, $end - $lt );
					$m     = 'inner' === $shape ? array( $full, $body ) : array( $full, $attrs, $body );
				} else {
					$full = substr( $html, $lt, $end - $lt );
					switch ( $shape ) {
						case 'open':
							$m = array( $full, substr( $html, $name_end, $gt - $name_end ) );
							break;
						case 'open2':
							$m = array( $full, $attrs, false === $slash ? '' : '/' );
							break;
						case 'named':
							$m = array( $full, $name, $attrs );
							break;
						case 'iframe':
							$m = array( $full, $attrs );
							break;
						default: // open3.
							$m = array( $full, $name, $attrs, false === $slash ? '' : '/' );
					}
				}
				$out .= substr( $html, $last, $lt - $last ) . (string) call_user_func( $callback, $m );
				$last = $end;
				$pos  = $end;
				if ( $has_body ) {
					continue;
				}
			} else {
				$pos = $end;
			}

			if ( 'svg' === $lower || 'math' === $lower ) {
				if ( false === $slash ) {
					$foreign++;
				}
				continue;
			}
			if ( 0 === $foreign && in_array( $lower, $raw, true ) ) {
				$close = self::linear_close_tag( $html, $lower, $end, $len );
				if ( null === $close ) {
					break; // A body with no end: the rest is text.
				}
				$pos = $close[0];
			}
		}
		if ( 0 === $last ) {
			return $html;
		}
		return $out . substr( $html, $last );
	}

	/**
	 * Where a tag ends, read as ATTR_WALK reads it, without a pattern.
	 *
	 * @param string $html Document.
	 * @param int    $p    Offset just past the tag name.
	 * @param int    $len  Document length.
	 * @return array{0:int,1:int|false}|null Offset of the closing `>`, and of
	 *         the self-closing slash before it (false when there is none);
	 *         null when the tag never ends.
	 */
	private static function linear_tag_end( $html, $p, $len ) {
		$ws    = " \t\n\x0B\f\r";
		$slash = false;
		while ( $p < $len ) {
			$p += strspn( $html, $ws, $p );
			if ( $p >= $len ) {
				return null;
			}
			$c = $html[ $p ];
			if ( '>' === $c ) {
				return array( $p, $slash );
			}
			$slash = false;
			if ( '/' === $c ) {
				$q = $p + 1 + strspn( $html, $ws, $p + 1 );
				if ( $q < $len && '>' === $html[ $q ] ) {
					$slash = $p;
				}
				++$p;
				continue;
			}
			$n = strcspn( $html, $ws . "\"'>/=", $p );
			if ( 0 === $n ) {
				++$p; // A stray quote or `=`, one character, as ATTR_WALK reads it.
				continue;
			}
			$p += $n;
			$q  = $p + strspn( $html, $ws, $p );
			if ( $q >= $len || '=' !== $html[ $q ] ) {
				continue;
			}
			$v = $q + 1 + strspn( $html, $ws, $q + 1 );
			if ( $v >= $len ) {
				return null;
			}
			$quote = $html[ $v ];
			if ( '"' === $quote || "'" === $quote ) {
				$close = strpos( $html, $quote, $v + 1 );
				// Unterminated: ATTR_WALK reads the `=` and the quote as stray
				// characters and goes on, and so does this.
				$p = false === $close ? $q + 1 : $close + 1;
				continue;
			}
			$p = '>' === $quote ? $v : $v + strcspn( $html, $ws . '>', $v );
		}
		return null;
	}

	/**
	 * The end tag of a raw-text or iframe body: `</name` followed by
	 * whitespace, `/` or `>`, through its `>`.
	 *
	 * @param string $html Document.
	 * @param string $name Lower-case element name.
	 * @param int    $from Offset to search from.
	 * @param int    $len  Document length.
	 * @return array{0:int,1:int}|null Offset of `</`, offset just past `>`;
	 *         null when there is none.
	 */
	private static function linear_close_tag( $html, $name, $from, $len ) {
		$needle = '</' . $name;
		$step   = strlen( $needle );
		$at     = stripos( $html, $needle, $from );
		while ( false !== $at ) {
			$next = $at + $step;
			if ( $next >= $len ) {
				return null;
			}
			if ( false !== strpos( " \t\n\x0B\f\r/>", $html[ $next ] ) ) {
				$gt = strpos( $html, '>', $next );
				return false === $gt ? null : array( $at, $gt + 1 );
			}
			$at = stripos( $html, $needle, $next );
		}
		return null;
	}

	/**
	 * Run a script or style pass on an element whose body is stashed.
	 *
	 * The processor is given the real body, and the body it returns (the
	 * style pass rewrites CSS) goes back into the stash, so the passes after
	 * this one still cannot see it. Should the result not have the shape the
	 * processors build, it is returned as it is: the body is then visible to
	 * the later passes, as it was before the stash existed.
	 *
	 * @param array    $m       Match: 0 element, 1 attributes, 2 body.
	 * @param string   $tag     `script` or `style`.
	 * @param array    $stash   Inert stash, updated in place.
	 * @param callable $process Processor taking the match.
	 * @return string
	 */
	private function process_with_inert_body( $m, $tag, array &$stash, $process ) {
		$marker = isset( $m[2] ) ? $m[2] : '';
		if ( '' === $marker || ! isset( $stash[ $marker ] ) ) {
			return $process( $m );
		}
		$real   = $stash[ $marker ];
		$m[0]   = str_replace( $marker, $real, $m[0] );
		$m[2]   = $real;
		$result = $process( $m );
		if ( 1 === preg_match( '#^(<' . $tag . '(?=[\s/>])' . self::ATTR_WALK . '(?:/\s*+)?>)(.*)(</' . $tag . '(?=[\s/>])[^>]*+>)$#is', $result, $r ) ) {
			$stash[ $marker ] = $r[2];
			return $r[1] . $marker . $r[3];
		}
		return $result;
	}

	private function process_link_tag( $m, $providers, $blocked_categories ) {
		$attrs = $m[1];
		$full  = $m[0];

		// rel is a space-separated TOKEN LIST and its value may be unquoted, so
		// the tokens are parsed here rather than pinned in the caller's pattern.
		// Only fetching rels are gated: dns-prefetch and preconnect resolve DNS
		// and open a socket but transfer no URL and set no cookie, so gating them
		// would break unrelated performance work for no privacy gain.
		$rel_value = $this->extract_tag_attr( $attrs, 'rel' );
		if ( '' === $rel_value ) {
			return $full;
		}
		$rel_tokens = preg_split( '/\s+/', strtolower( trim( $rel_value ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $rel_tokens || ! array_intersect( $rel_tokens, array( 'stylesheet', 'preload', 'modulepreload', 'prefetch', 'prerender' ) ) ) {
			return $full;
		}

		// Own stylesheets (core prints them as id="<handle>-css") — e.g. the
		// content-hashed banner CSS served from uploads — must never be gated
		// by provider patterns.
		if ( $this->is_own_asset_tag_id( $this->extract_tag_attr( $attrs, 'id' ), '-css' ) ) {
			return $full;
		}
		if ( $this->is_whitelisted( $attrs, '' ) ) {
			return $full;
		}
		if ( ( $this->has_attr_token( $attrs, 'data-faz-href' ) || $this->has_attr_token( $attrs, 'data-faz-imagesrcset' ) )
			&& ! $this->has_attr_token( $attrs, 'href' ) && ! $this->has_attr_token( $attrs, 'imagesrcset' ) ) {
			return $full; // Parked, and nothing live left (a bare sentinel does not exempt).
		}

		$matched_category = $this->match_script_to_provider( $attrs, '', $providers, $blocked_categories );
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

		// Park imagesrcset BEFORE href. get_provider_match_context() folds
		// srcset/imagesrcset into the text this tag is matched against, so a
		// `<link rel="preload" as="image" imagesrcset="https://tracker/…">` was
		// correctly recognised as blocked and then left able to fetch: renaming
		// href alone does nothing to a preload whose URL lives in imagesrcset.
		// Both renamed on their tokens, every occurrence: a duplicated
		// attribute is kept first-wins by the parser, so renaming only the
		// first left the second one live.
		list( $new_attrs ) = $this->rename_attr_tokens(
			$attrs,
			array(
				'imagesrcset' => 'data-faz-imagesrcset',
				'href'        => 'data-faz-href',
			)
		);
		// As with <img>, $attrs may already end in the source tag's `/`.
		// Prefix metadata so the slash remains the final token before `>`.
		return '<link' . $this->label_category_first( $new_attrs, $matched_category ) . '>';
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

		if ( '' === trim( $content ) ) {
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

		$category  = reset( $categories );
		$new_attrs = ' data-faz-css="' . esc_attr( base64_encode( $content ) ) . '"' . $this->strip_attr_tokens( $attrs, 'data-faz-css' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- inert transport for original CSS text, decoded only after consent.

		return '<style' . $this->label_category_first( $new_attrs, $category ) . '>' . $rewritten . '</style>';
	}

	/**
	 * Replace blocked CSS URL tokens with an inert data URL.
	 *
	 * Three in-place passes neutralise what a regex over the raw text can
	 * reach — url() in its three forms, an @import string with or without
	 * whitespace, the string arguments of image-set()/src() — and leave the
	 * rest of the sheet active. Then the REWRITTEN text is read the way the
	 * CSS tokenizer reads it (css_urls(): escapes decoded, a url( left open,
	 * every string channel), and if a blocked URL is still named the sheet is
	 * parked WHOLE: an empty string comes back, the caller keeps the original
	 * in data-faz-css, and the runtime restores it on consent. Partial is not
	 * an option there; partial is how `u\72l(…)` and `@import"…"` fetched
	 * Google Fonts at parse time while the two client layers, which read all
	 * of these forms, called the same text blocked. This is the runtime's
	 * _fazNeutralizeCssUrls() in PHP, and the two must stay in step.
	 *
	 * @param string $css                CSS text.
	 * @param array  $providers          Provider-to-category map.
	 * @param array  $blocked_categories Currently blocked category slugs.
	 * @param array  $categories         Output list of categories that triggered a block.
	 * @return string Rewritten CSS, or '' when the sheet must be parked whole.
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
			'/url\(\s*(?:"([^"]*+)"|\'([^\']*+)\'|([^)\s]++))\s*\)/i',
			function ( $m ) use ( $block_url, $inert_url ) {
				$url = isset( $m[1] ) && '' !== $m[1] ? $m[1] : ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : ( isset( $m[3] ) ? $m[3] : '' ) );
				if ( ! $block_url( $url ) ) {
					return $m[0];
				}
				return 'url("' . $inert_url . '")';
			},
			$original
		);
		// A PCRE error (backtrack or stack limit on crafted or very large CSS)
		// returns null, and a null subject must never reach the next pass
		// (deprecated / TypeError on PHP 8.1+). The patterns are possessive, so
		// a long string or data URI is one step, not one stack frame per
		// character; this is the last resort. It used to fail OPEN, returning
		// the original text with no category, which threw away what the earlier
		// passes had neutralised: a long image-set() string after a Google
		// Fonts @import shipped the @import live. Now the text is judged as a
		// whole: a pass that already met a blocked URL, or a blocked URL the
		// residual reader finds in the original, parks the sheet whole (empty
		// live text, the original restored on consent). Only text that names
		// nothing blocked is left as it was.
		// When the residual reader cannot read the text either (it runs the
		// same engine under the same limits), a blocked provider named anywhere
		// in it is enough: a plain substring test needs no regex, and a sheet
		// parked here is restored on consent, while one left live fetches.
		$fail_closed = function () use ( $original, $providers, $blocked_categories, &$categories ) {
			$residual = $this->css_blocked_category( $original, $providers, $blocked_categories );
			if ( false !== $residual ) {
				$categories[] = $residual;
			}
			if ( empty( $categories ) ) {
				$decoded = $this->css_decode_escapes( $original );
				foreach ( $providers as $pattern => $category ) {
					if ( '' === (string) $pattern || ! in_array( $category, (array) $blocked_categories, true ) ) {
						continue;
					}
					if ( false !== stripos( $original, (string) $pattern ) || ( is_string( $decoded ) && false !== stripos( $decoded, (string) $pattern ) ) ) {
						$categories[] = $category;
						break;
					}
				}
			}
			$categories = array_values( array_unique( $categories ) );
			return empty( $categories ) ? $original : '';
		};
		if ( null === $rewritten ) {
			return $fail_closed();
		}

		// `\s*`, not `\s+`: the at-keyword may be followed directly by the
		// string token, and `@import"…"` is what esbuild's CSS minifier and
		// critical-CSS inliners emit. The replacement always puts one space
		// after the at-keyword: `@importurl(` would be a different, unknown
		// at-rule, and `@import url("data:…")` is valid in its place.
		$rewritten = preg_replace_callback(
			'/(@import\s*)(["\'])([^"\']++)\2/i',
			function ( $m ) use ( $block_url, $inert_url ) {
				if ( ! $block_url( $m[3] ) ) {
					return $m[0];
				}
				return rtrim( $m[1] ) . ' url("' . $inert_url . '")';
			},
			$rewritten
		);
		if ( null === $rewritten ) {
			return $fail_closed();
		}

		// image-set() and src() name a file with a bare string and no url()
		// around it. One level of nesting in the body, so the string after a
		// nested url() is reached; a first-party candidate next to the blocked
		// one stays.
		$rewritten = preg_replace_callback(
			'/((?:-webkit-)?(?:image-set|src)\()((?:[^()]++|\([^()]*+\))*+)(\))/i',
			function ( $m ) use ( $block_url, $inert_url ) {
				$inner = preg_replace_callback(
					'/"((?:[^"\\\\]++|\\\\[\s\S])*+)"|\'((?:[^\'\\\\]++|\\\\[\s\S])*+)\'/',
					function ( $s ) use ( $block_url, $inert_url ) {
						$url = isset( $s[1] ) && '' !== $s[1] ? $s[1] : ( isset( $s[2] ) ? $s[2] : '' );
						return $block_url( $url ) ? '"' . $inert_url . '"' : $s[0];
					},
					$m[2]
				);
				return null === $inner ? $m[0] : $m[1] . $inner . $m[3];
			},
			$rewritten
		);
		if ( null === $rewritten ) {
			return $fail_closed();
		}

		// The judgement: does the engine still fetch a blocked URL from what
		// is left? Read as the tokenizer reads it, which the in-place passes
		// above cannot be — an escape in the function name or the host, a
		// url( with no closing paren. Any hit parks the sheet whole.
		$residual = $this->css_blocked_category( $rewritten, $providers, $blocked_categories );
		if ( false !== $residual ) {
			$categories[] = $residual;
			$categories   = array_values( array_unique( $categories ) );
			return '';
		}

		$categories = array_values( array_unique( $categories ) );
		return $rewritten;
	}

	/**
	 * The first blocked category among every URL a CSS text names, or false.
	 *
	 * @param string $css                CSS text.
	 * @param array  $providers          Provider-to-category map.
	 * @param array  $blocked_categories Currently blocked category slugs.
	 * @return string|false
	 */
	private function css_blocked_category( $css, $providers, $blocked_categories ) {
		foreach ( $this->css_urls( $css ) as $url ) {
			$category = $this->css_url_block_category( $url, $providers, $blocked_categories );
			if ( false !== $category ) {
				return $category;
			}
		}
		return false;
	}

	/**
	 * Every URL a stylesheet names, read the way the CSS tokenizer reads it.
	 *
	 * url() in its three forms, `@import` followed by a string with or
	 * without whitespace, the string arguments of image-set() and src(), a
	 * url( left open — and all of them again on a copy with CSS escapes
	 * decoded, because an escape can hide the function name or a character
	 * of the host from the raw text. The PHP twin of _fazCssUrls() in
	 * script.js; the two read the same forms or the layers disagree on the
	 * same text.
	 *
	 * @param string $css CSS text.
	 * @return string[]
	 */
	private function css_urls( $css ) {
		$css  = (string) $css;
		$urls = $this->css_urls_raw( $css, false );
		$decoded = $this->css_decode_escapes( $css );
		$passes  = array( $decoded );
		// A comment between two tokens is nothing to the tokenizer, so
		// `@import/*c*/"https://…"` imports that string, and no pattern read it
		// while the comment sat in the way: one more pass on the text with its
		// comments removed (before escapes are decoded, as the tokenizer does;
		// twin of _fazCssUrls() and of cssUrlsAll() in bootstrap.js). That pass
		// can only ADD URLs: the stripper cannot tell where the tokenizer is,
		// and `/*` inside an unquoted url(…) or an unterminated string is text
		// the engine keeps, so the passes above still read the original.
		$uncommented = preg_replace( '/("(?:[^"\\\\\n]++|\\\\[\s\S])*+"|\'(?:[^\'\\\\\n]++|\\\\[\s\S])*+\')|\/\*[^*]*+\*++(?:[^\/*][^*]*+\*++)*+\//', '$1', $css );
		if ( null !== $uncommented ) {
			$passes[] = $this->css_decode_escapes( $uncommented );
		}
		foreach ( array_unique( $passes ) as $text ) {
			if ( $text === $css ) {
				continue;
			}
			foreach ( $this->css_urls_raw( $text, true ) as $url ) {
				if ( ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
			}
		}
		return $urls;
	}

	/**
	 * One extraction pass of css_urls().
	 *
	 * The bare branch of url() stops at the first whitespace on the RAW text,
	 * where an unquoted token cannot hold one; on the DECODED copy an escape
	 * may have become a space inside the URL the engine resolved, so the body
	 * runs to the closing paren instead. A CSS string ends at an UNESCAPED
	 * quote, so every string body crosses `\"`.
	 *
	 * @param string $css     CSS text.
	 * @param bool   $decoded Whether $css is the escape-decoded copy.
	 * @return string[]
	 */
	private function css_urls_raw( $css, $decoded ) {
		$urls = array();
		if ( '' === $css ) {
			return $urls;
		}
		$url_pattern = $decoded
			? '/url\(\s*(?:"((?:[^"\\\\]++|\\\\[\s\S])*+)"|\'((?:[^\'\\\\]++|\\\\[\s\S])*+)\'|([^)]++))\)/i'
			: '/url\(\s*(?:"((?:[^"\\\\]++|\\\\[\s\S])*+)"|\'((?:[^\'\\\\]++|\\\\[\s\S])*+)\'|([^)\s]++))\s*\)/i';
		if ( preg_match_all( $url_pattern, $css, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $f ) {
				// The decoded bare branch runs to the closing paren in one
				// possessive step; the whitespace before the paren, which a
				// lazy body used to leave out, is trimmed here instead.
				$bare   = isset( $f[3] ) ? ( $decoded ? rtrim( $f[3], " \t\n\r\f" ) : $f[3] ) : '';
				$urls[] = isset( $f[1] ) && '' !== $f[1] ? $f[1] : ( isset( $f[2] ) && '' !== $f[2] ? $f[2] : $bare );
			}
		}
		if ( preg_match_all( '/@import\s*(["\'])((?:(?:(?!\1)[^\\\\])++|\\\\[\s\S])++)\1/i', $css, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $f ) {
				$urls[] = $f[2];
			}
		}
		if ( preg_match_all( '/(?:-webkit-)?(?:image-set|src)\(((?:[^()]++|\([^()]*+\))*+)\)/i', $css, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $f ) {
				if ( preg_match_all( '/"((?:[^"\\\\]++|\\\\[\s\S])*+)"|\'((?:[^\'\\\\]++|\\\\[\s\S])*+)\'/', $f[1], $strings, PREG_SET_ORDER ) ) {
					foreach ( $strings as $s ) {
						$urls[] = isset( $s[1] ) && '' !== $s[1] ? $s[1] : ( isset( $s[2] ) ? $s[2] : '' );
					}
				}
			}
		}
		// A url( left OPEN: the tokenizer reads to the end of the text as the
		// URL, and the engine fetches it. Anchored at the end, so only a
		// genuinely unclosed token matches.
		if ( preg_match( '/url\(\s*(?:"((?:[^"\\\\]++|\\\\[\s\S])*+)|\'((?:[^\'\\\\]++|\\\\[\s\S])*+)|([^)\s]*+))\s*$/i', $css, $open ) ) {
			$value = isset( $open[1] ) && '' !== $open[1] ? $open[1] : ( isset( $open[2] ) && '' !== $open[2] ? $open[2] : ( isset( $open[3] ) ? $open[3] : '' ) );
			if ( '' !== $value ) {
				$urls[] = $value;
			}
		}
		return $urls;
	}

	/**
	 * Decode CSS escapes the way the tokenizer consumes them.
	 *
	 * `\XX` is one to six hex digits with one optional whitespace swallowed
	 * after them (CRLF counts as one), `\c` is any other character. `u\72l(`
	 * is `url(` to the tokenizer, `\75 rl(` is too, and `tracker\.test` is
	 * `tracker.test` — none of which a regex over the raw text could see.
	 * The twin of _fazCssDecodeEscapes() in script.js.
	 *
	 * @param string $css CSS text.
	 * @return string
	 */
	private function css_decode_escapes( $css ) {
		$css = (string) $css;
		if ( false === strpos( $css, '\\' ) ) {
			return $css;
		}
		$decoded = preg_replace_callback(
			'/\\\\([0-9a-f]{1,6})(?:\r\n|[ \t\n\r\f])?|\\\\([\s\S])/i',
			function ( $m ) {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					$cp = (int) hexdec( $m[1] );
					if ( 0 === $cp || $cp > 0x10FFFF || ( $cp >= 0xD800 && $cp <= 0xDFFF ) ) {
						return "\xEF\xBF\xBD"; // U+FFFD, as the tokenizer substitutes.
					}
					return $this->css_codepoint_to_utf8( $cp );
				}
				return isset( $m[2] ) ? $m[2] : '';
			},
			$css
		);
		return null === $decoded ? $css : $decoded;
	}

	/**
	 * UTF-8 bytes for one code point, with no mbstring dependency.
	 *
	 * @param int $cp Code point, already range-checked.
	 * @return string
	 */
	private function css_codepoint_to_utf8( $cp ) {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
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
		// Escapes first, so `tracker\.test` is the host it is to the tokenizer
		// — the runtime's _fazCssUrlShouldBlock() decodes before it judges too.
		$url = trim( $this->css_decode_escapes( (string) $url ), " \t\n\r\0\x0B\"'" );
		if ( '' === $url || '#' === $url[0] || 0 === stripos( $url, 'data:' ) || 0 === stripos( $url, 'blob:' ) ) {
			return false;
		}
		// Normalise before matching, for the same reason every other route
		// does. has_provider_boundary() stopped treating `@` as a boundary on
		// the grounds that userinfo is dropped first — true of the haystacks
		// get_provider_match_context() builds, and this route passed the raw
		// token, so `url(https://x@cdn.tracker.test/p.png)` had its host behind
		// an `@` that was no longer a boundary and matched nothing. Firefox
		// fetches subresources with embedded credentials. Relative values are
		// returned unchanged, so a decoy in a path or a query stays unmatched.
		$url = $this->normalize_resource_url( $url );
		if ( '' === $url ) {
			return false;
		}
		// The administrator's whitelist binds here as in every tag pass, and
		// as it does in the runtime's _fazCssUrlShouldBlock(): a URL the
		// operator exempted is not a blocked URL whichever channel names it.
		foreach ( $this->get_whitelist() as $pattern ) {
			if ( $this->matches_whitelist_pattern( $url, $pattern ) ) {
				return false;
			}
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
				$this->normalize_resource_url( $this->extract_tag_attr( $attrs, 'src' ) ),
				$this->normalize_resource_url( $this->extract_tag_attr( $attrs, 'href' ) ),
				$this->normalize_resource_url( $this->extract_tag_attr( $attrs, 'xlink:href' ) ),
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
		// The first token of that name, as the tokenizer would produce it:
		// the old lookbehind pattern found `src=` inside another attribute's
		// value and parked a local image for the URL in its alt text. The
		// walk stops at the first one; the rest of the tag is never read.
		$value = '';
		$this->each_attr_token(
			$attrs,
			array( $name ),
			function ( $token ) use ( &$value ) {
				$raw = $token['value'];
				if ( null !== $raw ) {
					$value = ( strlen( $raw ) >= 2 && ( '"' === $raw[0] || "'" === $raw[0] ) ) ? substr( $raw, 1, -1 ) : $raw;
				}
				return false;
			}
		);
		return $value;
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
		// A whitelist token in the query or the fragment is not the resource:
		// `pixel.png?x=jquery.min.js` is the pixel. Unless the pattern itself
		// names a query or a fragment, the value is matched without them.
		if ( false === strpbrk( $pattern, '?#' ) ) {
			$cut = strpbrk( $value, '?#' );
			if ( false !== $cut ) {
				$value = substr( $value, 0, strlen( $value ) - strlen( $cut ) );
			}
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
	 * Anti-abuse challenge endpoints (CAPTCHA and bot challenges) that are
	 * allowed before consent unless the owner switches that off (#343).
	 * Substring patterns: 'hcaptcha.com' also covers js.hcaptcha.com, and
	 * 'google.com/recaptcha' covers recaptcha/api.js.
	 */
	const ANTISPAM_CHALLENGE_PATTERNS = array(
		'google.com/recaptcha',
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Matching a third-party challenge URL in the consent whitelist; this string does not load a plugin asset.
		'gstatic.com/recaptcha',
		'hcaptcha.com',
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Matching a third-party challenge URL in the consent whitelist; this string does not load a plugin asset.
		'challenges.cloudflare.com/turnstile',
	);

	/**
	 * Whether reCAPTCHA, hCaptcha and Turnstile load before consent.
	 *
	 * Settings → Script Blocking → "Allow anti-spam services before consent",
	 * on by default. Anything but an explicit false keeps the default, so an
	 * install that predates the setting behaves as before.
	 *
	 * @return bool
	 */
	private function antispam_allowed_before_consent() {
		$settings = $this->get_faz_settings();
		if ( ! isset( $settings['script_blocking'] ) || ! is_array( $settings['script_blocking'] )
			|| ! array_key_exists( 'allow_antispam_before_consent', $settings['script_blocking'] ) ) {
			return true;
		}
		return false !== $settings['script_blocking']['allow_antispam_before_consent'];
	}

	/**
	 * Category a built-in provider pattern is blocked under.
	 *
	 * The catalogue files reCAPTCHA, hCaptcha and Turnstile (and the WPForms /
	 * Ninja Forms CAPTCHA handles) as necessary, and necessary patterns are
	 * never blocked. With "Allow anti-spam services before consent" off that
	 * would leave the switch doing nothing unless the owner also wrote a custom
	 * rule, so those patterns are held under functional instead, the category
	 * a CAPTCHA belongs to once it is not treated as strictly necessary (#343).
	 * The category must be one the visitor can accept: a hidden category never
	 * reaches the banner, so a CAPTCHA held under it would stay blocked for
	 * good. Functional first, then uncategorized, then any other category the
	 * banner shows. Falling back to necessary would let the CAPTCHA load
	 * before consent with the switch off, so that happens only on a site
	 * whose banner offers nothing but necessary, where nothing could hold it.
	 *
	 * @param string   $category         Category from the provider catalogue.
	 * @param string   $pattern          The provider pattern.
	 * @param string[] $valid_categories Category slugs that exist on this site.
	 * @return string
	 */
	private function known_provider_category( $category, $pattern, $valid_categories ) {
		if ( 'necessary' !== $category ) {
			return $category;
		}
		if ( 1 !== preg_match( '/recaptcha|hcaptcha|turnstile/i', (string) $pattern ) ) {
			return $category;
		}
		if ( $this->antispam_allowed_before_consent() ) {
			return $category;
		}
		$consentable = array_values( array_intersect( $this->get_consentable_category_slugs(), $valid_categories ) );
		foreach ( array( 'functional', 'uncategorized' ) as $preferred ) {
			if ( in_array( $preferred, $consentable, true ) ) {
				return $preferred;
			}
		}
		return isset( $consentable[0] ) ? $consentable[0] : $category;
	}

	/**
	 * Slugs of the categories a visitor can accept in the banner: visible,
	 * not necessary, not wordpress-internal (the same filter as
	 * get_cookie_groups()). Cached for the request.
	 *
	 * @return string[]
	 */
	private function get_consentable_category_slugs() {
		if ( null !== $this->consentable_categories_cache ) {
			return $this->consentable_categories_cache;
		}
		$slugs = array();
		foreach ( \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items() as $cat_data ) {
			$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $cat_data );
			$slug     = $category->get_slug();
			if ( false === $category->get_visibility() || in_array( $slug, array( 'necessary', 'wordpress-internal' ), true ) ) {
				continue;
			}
			$slugs[] = $slug;
		}
		$this->consentable_categories_cache = $slugs;
		return $slugs;
	}

	/**
	 * Script Blocking Exceptions as the blocker should honour them.
	 *
	 * With "Allow anti-spam services before consent" off, the CAPTCHA entries a
	 * fresh install stores there are dropped: they are the plugin's default,
	 * not a choice the owner made, and since every exception wins over every
	 * rule they would keep reCAPTCHA live and make the switch a no-op on a
	 * default install (#343). Only exact copies of those shipped strings go;
	 * any other entry, including a broader "recaptcha" the owner typed, stays.
	 *
	 * @return string[]
	 */
	private function get_user_whitelist_patterns() {
		$settings = $this->get_faz_settings();
		$patterns = isset( $settings['script_blocking']['whitelist_patterns'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', (array) $settings['script_blocking']['whitelist_patterns'] ) ) )
			: array();
		if ( $this->antispam_allowed_before_consent() ) {
			return $patterns;
		}
		$shipped = array_map( 'strtolower', Settings::DEFAULT_ANTISPAM_EXCEPTIONS );
		return array_values(
			array_filter(
				$patterns,
				function ( $pattern ) use ( $shipped ) {
					return ! in_array( strtolower( trim( $pattern ) ), $shipped, true );
				}
			)
		);
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

		// Do not whitelist whole third-party plugins or generic handles here.
		// A plugin directory is not a consent category: it can contain a tracker,
		// an inline pixel, or a later update that adds one. Unmatched assets already
		// pass through unchanged, while a recognised provider remains blockable.
		// Publishers may add a narrowly-scoped, audited exception in Settings when
		// a resource is genuinely strictly necessary for their own use case.
		$whitelist = array_merge( $whitelist, $this->get_always_allowed_gateway_patterns() );

		// strictly_necessary_script_handles() is deliberately NOT merged here.
		// The whitelist matches by token PREFIX against id and class as well as
		// src, so `wc-settings` here would also exempt `wc-settings-tracker-js`
		// and `class="wc-settings-tracker"`, plus iframes, noscript pixels, link
		// and style tags. Those handles are exempted by exact identity in
		// is_strictly_necessary_script(), which runs ahead of this check in all
		// three script paths.

		// ── Anti-abuse challenge endpoints — strictly necessary ──
		//
		// These are NOT plugin directories or generic handles, and the rule above
		// does not reach them: each is one third-party endpoint whose only
		// function is to protect a form the visitor is actively trying to use.
		// Blocking them pre-consent does not degrade a feature, it removes the
		// login, registration, comment or checkout form outright — the visitor
		// cannot even reach the banner's Accept button on a login screen. ePrivacy
		// exempts measures strictly necessary to deliver a service the user
		// requested, and anti-abuse protection of that same service qualifies.
		//
		// google-recaptcha is a matchable entry in known-providers.json, so
		// without this the provider matcher blocks it by name. Restored after the
		// hardcoded strictly-necessary list was removed: Activator::
		// seed_default_whitelist() returns early on any install that already has
		// stored patterns, so no migration would have given these back to an
		// existing site. Substring matching means 'hcaptcha.com' also covers
		// js.hcaptcha.com, and 'google.com/recaptcha' covers recaptcha/api.js.
		//
		// Deliberately NOT restored from the old list: the bare 'grecaptcha' JS
		// global and 'akismet' (a server-side filter with no browser asset that
		// needs unblocking) — both are generic handles the rule above rightly
		// rejects.
		//
		// Whether a CAPTCHA counts as strictly necessary is the site owner's
		// call, not ours: some legal advisors (notably in Germany) want
		// reCAPTCHA held until consent because it sends data to Google. The
		// switch is on by default, so nothing changes for existing sites; off,
		// these endpoints leave the whitelist, the shipped CAPTCHA exceptions
		// are ignored (get_user_whitelist_patterns()) and the catalogue holds
		// them under functional (known_provider_category()) (#343).
		if ( $this->antispam_allowed_before_consent() ) {
			$whitelist = array_merge( $whitelist, self::ANTISPAM_CHALLENGE_PATTERNS );
		}

		// ── WooCommerce core infrastructure ──
		if ( class_exists( 'WooCommerce', false ) ) {
			// On checkout/cart pages, explicitly allow the selected payment SDKs
			// only. Core WooCommerce assets that do not match a provider pass through
			// naturally; broad plugin paths would also exempt an embedded tracker.
			if ( $this->is_wc_checkout_or_cart() ) {
				$whitelist = array_merge( $whitelist, $this->get_payment_gateway_whitelist() );
			}
		}

		$whitelist = apply_filters( 'faz_whitelisted_scripts', $whitelist );

		if ( ! is_array( $whitelist ) ) {
			$whitelist = array();
		}

		// Merge user-defined whitelist patterns from Settings → Script Blocking.
		$whitelist = array_merge( $whitelist, $this->get_user_whitelist_patterns() );

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
	 * WooCommerce core handles that carry state required to render commerce UI.
	 *
	 * This list is intentionally handle-scoped. It does not exempt the
	 * WooCommerce plugin directory, analytics/order-attribution code, payment
	 * SDKs, or arbitrary `wc-*` scripts. `wc-settings` publishes the shared
	 * `wcSettings` object (including checkoutAllowsGuest); the middleware tag
	 * publishes the Store API nonce; and the mini-cart tag publishes its lazy
	 * dependency manifest. None of the three is a tracking surface.
	 *
	 * @return string[]
	 */
	private static function strictly_necessary_script_handles() {
		return array(
			'wc-settings',
			'wc-blocks-middleware',
			'wc-mini-cart-block-frontend',
		);
	}

	/**
	 * Whether a WP script identity belongs to the non-filterable core invariant.
	 *
	 * Handles must match exactly. IDs may additionally use only the suffixes
	 * WordPress itself generates for an enqueued script and its before/after
	 * payloads; a look-alike such as `wc-settings-tracker-js` stays blockable.
	 *
	 * @param string $handle Registered WordPress script handle, when available.
	 * @param string $id     Rendered script element ID, when available.
	 * @return bool
	 */
	private function is_strictly_necessary_script( $handle = '', $id = '' ) {
		$handle = strtolower( trim( (string) $handle ) );
		$id     = strtolower( trim( (string) $id ) );

		foreach ( self::strictly_necessary_script_handles() as $required_handle ) {
			if ( $handle === $required_handle ) {
				return true;
			}
			if ( in_array(
				$id,
				array(
					$required_handle,
					$required_handle . '-js',
					$required_handle . '-js-before',
					$required_handle . '-js-after',
				),
				true
			) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Catalogue of supported payment gateways and the script patterns that make
	 * each one functional (the SDK loader that defines its JS global plus the
	 * gateway plugin's own script handles). Single source of truth shared with
	 * the admin settings (the per-gateway opt-in checkboxes) and the sanitiser.
	 *
	 * Deliberately excludes a gateway's MARKETING pixel (e.g. PayPal's
	 * paypal.com/tagmanager/pptm.js): enabling a gateway must not unblock its
	 * tracking pixel, only the payment machinery.
	 *
	 * @return array<string,array{label:string,patterns:string[]}>
	 */
	public static function payment_gateway_catalog() {
		return array(
			'paypal'     => array( 'label' => 'PayPal',     'patterns' => array( 'paypal.com/sdk/js', 'paypalobjects.com/api/checkout.js', 'ppcp-gateway', 'ppcp-webhooks', 'PayPalCommerceGateway' ) ),
			'stripe'     => array( 'label' => 'Stripe',     'patterns' => array( 'js.stripe.com', 'm.stripe.network', 'wc-stripe-', 'stripe-payment', 'stripe-upe' ) ),
			'square'     => array( 'label' => 'Square',     'patterns' => array( 'squareup.com', 'square-credit-card' ) ),
			'braintree'  => array( 'label' => 'Braintree',  'patterns' => array( 'braintreegateway.com', 'braintree-web/', 'wc-braintree' ) ),
			'klarna'     => array( 'label' => 'Klarna',     'patterns' => array( 'x.klarnacdn.net', 'klarna-payments', 'klarna-checkout' ) ),
			'mollie'     => array( 'label' => 'Mollie',     'patterns' => array( 'js.mollie.com', 'mollie-payments', 'plugins/mollie-payments-for-woocommerce/' ) ),
			'amazon_pay' => array( 'label' => 'Amazon Pay', 'patterns' => array( 'static-na.payments-amazon.com', 'amazonpay', 'amazon-payments-advanced' ) ),
		);
	}

	/**
	 * Payment gateway script patterns that are exempt from consent blocking on
	 * the current request.
	 *
	 * Payment SDKs can set cookies and fingerprint, so they are NOT globally
	 * allowed by default (that would load a tracker before consent — a GDPR /
	 * ePrivacy violation). They are exempt only when:
	 *
	 *   1. the request is a WooCommerce checkout/cart page — the SDK is then
	 *      strictly necessary for the transaction the visitor initiated (the
	 *      ePrivacy Art. 5(3) "strictly necessary" exemption), OR
	 *   2. the site owner explicitly enabled that gateway under Settings →
	 *      Script Blocking → Payment gateways — an informed, per-gateway,
	 *      admin-responsibility opt-in for stores whose payment forms live
	 *      outside a WooCommerce checkout (Forminator, Paid Memberships Pro,
	 *      Easy Digital Downloads, Give, …).
	 *
	 * This is also the source the cookie shredder consults
	 * (is_always_allowed_gateway_pattern → compute_whitelisted_cookie_patterns),
	 * so a gateway's cookies are exempted exactly when its scripts are — never
	 * for a gateway that is neither on-checkout nor opted-in. The shredder runs
	 * on template_redirect, after the main query is resolved, so the WooCommerce
	 * conditional is reliable there too.
	 *
	 * @return string[]
	 */
	private function get_always_allowed_gateway_patterns() {
		$catalog     = self::payment_gateway_catalog();
		$settings    = $this->get_faz_settings();
		$enabled     = ( isset( $settings['script_blocking']['payment_gateways'] ) && is_array( $settings['script_blocking']['payment_gateways'] ) )
			? $settings['script_blocking']['payment_gateways']
			: array();
		$on_checkout = $this->is_wc_checkout_or_cart();

		$patterns = array();
		foreach ( $catalog as $key => $gateway ) {
			// Strictly necessary on WooCommerce checkout/cart; otherwise only when
			// the admin opted this gateway in.
			//
			// Read through the sanitiser rather than `! empty()`. The write path
			// coerces properly now, but an install that stored the string "false"
			// or "no" before that fix keeps it, and `! empty( "false" )` is true —
			// so the very install this fix exists for would go on exempting the
			// gateway from consent blocking after upgrading. Sanitising here
			// closes it for existing data without a migration, and a migration
			// would also have to win a version race with two other open branches.
			if ( $on_checkout || ( isset( $enabled[ $key ] ) && \faz_sanitize_bool_strict( $enabled[ $key ] ) ) ) {
				$patterns = array_merge( $patterns, $gateway['patterns'] );
			}
		}

		$patterns = apply_filters( 'faz_always_allowed_gateway_patterns', $patterns );
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
	private function gateway_pattern_matches( $pattern, $list ) {
		$pattern = trim( (string) $pattern );
		if ( '' === $pattern ) {
			return false;
		}
		foreach ( $list as $allowed_pattern ) {
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
	 * Whether a script pattern is exempt from consent BLOCKING on this request —
	 * i.e. the gateway is opted-in OR this is a WooCommerce checkout/cart page.
	 * Drives the script whitelist and the client-side block map, both resolved at
	 * `wp_enqueue_scripts` where the request context is ready. Context-dependent.
	 *
	 * @param string $pattern
	 * @return bool
	 */
	private function is_always_allowed_gateway_pattern( $pattern ) {
		if ( null === $this->always_allowed_cache ) {
			$this->always_allowed_cache = $this->get_always_allowed_gateway_patterns();
		}
		return $this->gateway_pattern_matches( $pattern, $this->always_allowed_cache );
	}

	/**
	 * Apply the payment-gateway and Instagram Feed exemptions to the CLIENT
	 * provider list (get_store_data() → `_providersToBlock`), with the same
	 * explicit-rule carve-out get_provider_category_map() applies to the
	 * server map.
	 *
	 * Built-in patterns (provider database, cookie DB) that overlap an
	 * always-allowed gateway token — or, on WooCommerce checkout/cart, any
	 * entry of get_payment_gateway_whitelist() — are removed, as before. An
	 * EXPLICIT rule (an admin custom rule, or one added through
	 * `faz_blocking_rules_client`) is never removed: gateway_pattern_matches()
	 * is a bidirectional substring test, so a generic rule like `payment` or
	 * `klarna` would otherwise vanish from the client for being a substring of
	 * `stripe-payment` / `x.klarnacdn.net` while the server map kept it — and a
	 * dynamically injected script the admin had ruled on ran before consent.
	 *
	 * The server does not release a gateway's OWN urls by dropping the rule
	 * but through get_whitelist(), which merges the same tokens. When an
	 * explicit rule overlapped a gateway and was kept, the overlapping tokens
	 * are therefore returned for the client whitelist, so a gateway-hosted url
	 * resolves to "allowed" on both layers and a differently-hosted script
	 * under the explicit rule resolves to "blocked" on both. Nothing is
	 * returned when no explicit rule overlapped: an install without such a
	 * rule ships exactly the payload it shipped before.
	 *
	 * @param array $providers Pattern => category list.
	 * @param array $explicit  Pattern => true for admin / filter-added rules.
	 * @return array{providers: array, whitelist: string[]}
	 */
	private function apply_client_provider_exemptions( array $providers, array $explicit ) {
		$sb_exempt_patterns = self::smash_balloon_exempt_patterns();
		$on_checkout        = $this->is_wc_checkout_or_cart();
		$checkout_whitelist = $on_checkout ? $this->get_payment_gateway_whitelist() : array();
		$gateway_overlap    = false;

		foreach ( array_keys( $providers ) as $pattern ) {
			$gateway_hit = $this->is_always_allowed_gateway_pattern( $pattern );
			if ( ! $gateway_hit && $on_checkout ) {
				// On checkout/cart pages the remaining gateway patterns go too, so
				// the JS interceptors cannot break a payment.
				foreach ( $checkout_whitelist as $gw ) {
					if ( false !== stripos( $pattern, $gw ) ) {
						$gateway_hit = true;
						break;
					}
				}
			}
			if ( ! $gateway_hit && ! isset( $sb_exempt_patterns[ $pattern ] ) ) {
				continue;
			}
			if ( isset( $explicit[ $pattern ] ) ) {
				if ( $gateway_hit ) {
					$gateway_overlap = true;
				}
				continue;
			}
			unset( $providers[ $pattern ] );
		}

		$whitelist = array();
		if ( $gateway_overlap ) {
			$whitelist = array_merge( (array) $this->always_allowed_cache, $checkout_whitelist );
			$whitelist = array_values( array_unique( array_filter( array_map( 'strval', $whitelist ), 'strlen' ) ) );
		}

		return array(
			'providers' => $providers,
			'whitelist' => $whitelist,
		);
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
	 * Scope the active banner gives the consent cookie, for
	 * faz_get_valid_consent_cookie().
	 *
	 * The server used to validate the cookie by revision alone while script.js
	 * (_fazConsentScopeChanged) also compared __scope.banner / __scope.law /
	 * __scope.fp with the banner served. On the request where the scope changed
	 * — a CCPA-acknowledged cookie arriving under the GDPR banner after a
	 * country change, a GeoIP miss or an admin re-targeting — the server still
	 * read `marketing:yes` and shipped the trackers executable; the client then
	 * invalidated the same cookie and re-prompted, one page view too late.
	 * Passing this scope makes both layers reject the same cookie on the same
	 * request.
	 *
	 * Mirrors the client independently of page-cache variance: a record given
	 * under another law is never consent here. How much of the scope must
	 * match depends on geographic routing (`_geoRouting` / `_geoBootstrap` on
	 * the client). With routing, banner, law and fingerprint all decide.
	 * Without it every visitor gets the same banner, so only the law does: a
	 * new banner slug or a fingerprint changed by rotated AUTH salts is not a
	 * new regime and must not re-prompt the whole audience.
	 * Strict mode follows `faz_strict_scope_fingerprint`; null means no banner
	 * has been selected yet, so only revision/site binding can be checked.
	 *
	 * @return array|null
	 */
	private function get_consent_cookie_scope() {
		if ( false !== $this->consent_cookie_scope_cache ) {
			return $this->consent_cookie_scope_cache;
		}
		$scope = null;
		if ( $this->banner ) {
			$slug  = (string) $this->banner->get_slug();
			$law   = (string) $this->banner->get_law();
			$scope = array(
				'slug'        => $slug,
				'law'         => $law,
				'fingerprint' => faz_scope_fingerprint( $slug, $law ),
				'strict'      => (bool) apply_filters( 'faz_strict_scope_fingerprint', false ),
				'routed'      => $this->is_country_dependent_output() || $this->is_geo_bootstrap_cache_active(),
			);
		}
		$this->consent_cookie_scope_cache = $scope;
		return $scope;
	}

	/**
	 * The consent cookie this request may rely on: current revision AND the
	 * scope of the banner being served (see get_consent_cookie_scope()).
	 *
	 * @return string Raw cookie, or '' when none is valid.
	 */
	private function get_scoped_valid_consent_cookie() {
		if ( ! function_exists( 'faz_get_valid_consent_cookie' ) ) {
			return '';
		}
		$cookie = (string) faz_get_valid_consent_cookie( '', $this->get_consent_cookie_scope() );
		if ( '' !== $cookie && $this->is_vendor_record_stale( $cookie ) ) {
			return '';
		}
		return $cookie;
	}

	/**
	 * Whether script.js will discard this decided cookie on this page because
	 * the visitor never saw every vendor now selected for IAB TCF (see
	 * faz_is_vendor_record_stale()).
	 *
	 * Treated like a scope change: the server reads the cookie as absent on the
	 * same request the client invalidates it, instead of rendering the accepted
	 * categories' trackers executable one page view before the re-prompt. It is
	 * NOT deleted here, nor on `init` as a stale revision is: the endpoint and
	 * pages AMP serves never show a vendor list, run at `init` before an AMP
	 * request can be recognised, and would destroy on every AMP page view the
	 * cookie the AMP flow had just written. The client deletes it on the
	 * canonical page, where it re-prompts.
	 *
	 * Only where the CMP can run: a page with a banner loaded (script.js is
	 * there to re-prompt). The law is the active banner's, as
	 * _fazCurrentLaw() reads it.
	 *
	 * @param string $cookie Revision- and scope-valid consent cookie.
	 * @return bool
	 */
	private function is_vendor_record_stale( $cookie ) {
		if ( ! $this->template || ! $this->banner || ! function_exists( 'faz_is_vendor_record_stale' ) ) {
			return false;
		}
		if ( ! isset( $this->vendor_record_stale_cache[ $cookie ] ) ) {
			$this->vendor_record_stale_cache[ $cookie ] = faz_is_vendor_record_stale( $cookie, (string) $this->banner->get_law() );
		}
		return $this->vendor_record_stale_cache[ $cookie ];
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
		$this->signal_blocked_categories = array();
		$categories = \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items();
		$blocked = array();

		// The cached jurisdiction shell shares this branch with Cache
		// Compatibility Mode:
		// the cached HTML is replayed to visitors whose consent state, GPC
		// signal and DNSMPI opt-out the server never saw, so the render must
		// not depend on any of them. Block every non-necessary category
		// server-side — reversible client-side, where script.js applies the
		// visitor's actual consent/law defaults after the live bootstrap.
		// Letting the shell ship EXECUTING scripts instead would replay trackers
		// to cached visitors who have
		// opted out (consent cookie / Sec-GPC / fazcookie-dnsmpi) — an
		// enforcement failure no client can undo.
		if ( $this->is_cache_compatibility_enabled() || $this->is_geo_bootstrap_cache_active() ) {
			foreach ( $categories as $cat_data ) {
				$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $cat_data );
				$slug     = $category->get_slug();
				if ( 'necessary' !== $slug ) {
					$blocked[] = $slug;
				}
			}
			// The cookie layers stand down in this mode (see
			// server_cookie_enforcement_permitted()), so no separate view.
			$this->blocked_categories_cache             = $blocked;
			$this->enforcement_blocked_categories_cache = $blocked;
			return $blocked;
		}

		$signals = array();
		$blocked = $this->compute_blocked_categories(
			$categories,
			$this->get_scoped_valid_consent_cookie(),
			$this->request_has_gpc(),
			$this->request_has_dnsmpi(),
			$signals
		);
		$this->signal_blocked_categories = $signals;
		// What the server-side cookie layers enforce: this visitor's own state,
		// never the shareable union below. That union is a render-time
		// over-block the client undoes from the consent cookie; a cookie the
		// shredder deletes, or a Set-Cookie header the guard strips, cannot be
		// undone, so applying the union there destroyed a consenting visitor's
		// `_ga` on every page of a site whose veto was off. Their denials are in
		// this set too, so a rejected category's cookies are still deleted.
		$this->enforcement_blocked_categories_cache = $blocked;

		// The visitor's state could not be kept out of the shared cache (see
		// decide_visitor_state_cache_veto()), so this render may be stored and
		// replayed to everyone: block whatever a first visit blocks AND whatever
		// this visitor's own state blocks. Their denials (a CCPA opt-out, GPC,
		// Do Not Sell, a ruleset-granted category they switched off) are still
		// enforced on their page; their grants beyond the first-visit default
		// are not, and script.js releases those client-side from the consent
		// cookie, exactly as it does on a Cache Compatibility page. A replay of
		// this copy can therefore only over-block, which the client undoes for
		// every visitor — never under-block, which nothing can undo.
		if ( 'fallback' === $this->visitor_state_veto ) {
			$unused  = array();
			$blocked = $this->union_blocked_categories(
				$categories,
				$blocked,
				$this->compute_blocked_categories( $categories, '', false, false, $unused )
			);
		}
		// Under an opt-out law the render parks the sale/sharing categories a
		// first visit allows, for every visitor: see
		// sale_share_parked_by_default(). A render-time over-block like the
		// union above, so the cookie layers keep reading the visitor's own set.
		$parked = $this->sale_share_parked_by_default( $categories );
		if ( ! empty( $parked ) ) {
			$blocked = $this->union_blocked_categories( $categories, $blocked, $parked );
		}
		$this->blocked_categories_cache = $blocked;
		return $blocked;
	}

	/**
	 * The sale/sharing categories every render parks under an opt-out law,
	 * although a visitor with no signal may run them.
	 *
	 * CCPA/CPRA is an opt-out regime, so a first visit blocks nothing it may
	 * sell or share (see compute_blocked_categories()). But that first-visit
	 * render is the page a shared cache keeps and replays, and the visitor it
	 * is replayed to may carry Sec-GPC: 1, navigator.globalPrivacyControl or
	 * a `fazcookie-dnsmpi` cookie: a binding opt-out (CPPA Reg. §7025, Cal.
	 * Civ. Code §1798.120) that the server never saw, because the cache
	 * answered. The sale/sharing scripts in that copy were executable from
	 * the parser, before script.js could apply the opt-out. The visitor-state
	 * veto keeps the signalled visitor's own page out of the cache; it cannot
	 * change the page a HIT serves them.
	 *
	 * So the render parks those categories (text/plain + data-faz-category,
	 * the shape of every server-side block) for everyone, and script.js
	 * releases them on its first pass when none of the three signals and no
	 * recorded opt-out is present: _fazSetInitialState() on a first visit, the
	 * restore pass for a recorded choice, both reading the categories from the
	 * consent store, which GPC and Do Not Sell set to "no" before any unblock
	 * runs. Without a signal they still run; with one they never do. The cost
	 * is the block-then-release delay: the scripts run when the runtime
	 * starts (DOMContentLoaded, or the restore pass for a returning visitor)
	 * instead of from the parser.
	 *
	 * The page is then the same for a signalled and an unsignalled visitor,
	 * so a GPC or Do Not Sell first visit no longer needs the visitor-state
	 * veto and can be cached like any other.
	 *
	 * Only where script.js is the one releasing: the active banner's law is
	 * `ccpa` (the law _fazGetLaw() reads, so the runtime treats the page as
	 * opt-out and runs its restore pass on a first visit). Under a GDPR-law
	 * banner the runtime releases nothing before consent, so parking a
	 * category an opt-out ruleset grants there would hold it until the
	 * visitor clicks; it is left as before. Not on AMP, which has no runtime.
	 * Cache Compatibility and the strict shell park every category already.
	 *
	 * @param array $categories Category rows.
	 * @return array Slugs, in catalogue order.
	 */
	private function sale_share_parked_by_default( $categories ) {
		if ( null !== $this->sale_share_parked_cache ) {
			return $this->sale_share_parked_cache;
		}
		$parked = array();
		if ( $this->banner && 'ccpa' === $this->banner->get_law() && ! apply_filters( 'faz_is_amp_request', false ) ) {
			/**
			 * Whether a render under an opt-out law parks the sale/sharing
			 * categories a first visit allows, for script.js to release when
			 * the visitor carries no GPC, Do Not Sell or recorded opt-out.
			 *
			 * Returning false ships them executable from the parser again,
			 * which a cached copy then replays to visitors whose opt-out
			 * signal the server never saw.
			 *
			 * @since 1.34.2
			 *
			 * @param bool $park Default true.
			 */
			$park = (bool) apply_filters( 'faz_ccpa_park_sell_share_by_default', true );
			if ( $park ) {
				$unused   = array();
				$baseline = $this->compute_blocked_categories( $categories, '', false, false, $unused );
				foreach ( $categories as $cat_data ) {
					$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $cat_data );
					$slug     = $category->get_slug();
					if ( 'necessary' === $slug || in_array( $slug, $baseline, true ) ) {
						continue;
					}
					if ( $category->get_sell_personal_data() || $category->get_share_personal_data() ) {
						$parked[] = $slug;
					}
				}
			}
		}
		if ( $this->banner ) {
			$this->sale_share_parked_cache = $parked;
		}
		return $parked;
	}

	/**
	 * The blocked-category set the destructive cookie layers enforce.
	 *
	 * Identical to get_blocked_categories() except on a 'fallback' verdict,
	 * where the render blocks the shareable union, and under an opt-out law,
	 * where it also parks the sale/sharing categories a first visit allows
	 * (sale_share_parked_by_default()); this returns the visitor's own set
	 * (see the comment where it is filled). Read by
	 * is_cookie_allowed() and shred_non_consented_cookies() only — never by
	 * anything that decides what the page itself executes.
	 *
	 * @return array Slugs of blocked categories.
	 */
	private function get_enforcement_blocked_categories() {
		$render = $this->get_blocked_categories();
		return is_array( $this->enforcement_blocked_categories_cache ) ? $this->enforcement_blocked_categories_cache : $render;
	}

	/**
	 * The blocked-category set for one visitor state, with no memo and no
	 * cache decision: get_blocked_categories() asks it for the visitor's real
	 * state, decide_visitor_state_cache_veto() also asks it for the first-visit
	 * baseline the two are compared against.
	 *
	 * @param array  $categories    Category rows from Category_Controller.
	 * @param string $consent       Valid, scoped consent cookie, or ''.
	 * @param bool   $gpc_optout    Whether the request carries Sec-GPC: 1.
	 * @param bool   $dnsmpi_optout Whether the Do-Not-Sell cookie is set.
	 * @param array  $signals       Out: slug => 'gpc'|'dnsmpi' for categories a
	 *                              signal (not the cookie) blocked.
	 * @return array Slugs of blocked categories, in catalogue order.
	 */
	private function compute_blocked_categories( $categories, $consent, $gpc_optout, $dnsmpi_optout, &$signals ) {
		$blocked = array();
		$signals = array();

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
		// This is the visitor's state, and what the cookie layers enforce. The
		// RENDER still parks the sale/sharing categories among these, for
		// script.js to release, because a cached copy of it is replayed to
		// visitors whose GPC or Do Not Sell signal the server never saw: see
		// sale_share_parked_by_default().
		$is_optout_law = ( $this->banner && 'ccpa' === $this->banner->get_law() );

		// Runtime geo-routing: when a ruleset is
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
		// request carries Sec-GPC:1, block the sell/share categories server-side,
		// including when a prior consent cookie grants them. GPC is a visitor
		// signal, not a publisher-configurable preference, and therefore is never
		// gated on the banner toggle or binary law.
		// (Read by request_has_gpc().)

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
		// (Read by request_has_dnsmpi().)

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
				$signals[ $slug ] = 'dnsmpi';
				continue;
			}
			if ( $gpc_optout && ( $category->get_sell_personal_data() || $category->get_share_personal_data() ) ) {
				$blocked[] = $slug;
				$signals[ $slug ] = 'gpc';
				continue;
			}
			if ( empty( $consent ) ) {
				$rs_default = ( null !== $ruleset ) ? Geo_Runtime::category_default( $ruleset, $slug ) : null;
				if ( false === $rs_default ) {
					// Ruleset denies this category until an explicit action.
					$blocked[] = $slug;
				} elseif ( true === $rs_default ) {
					// Ruleset grants this category — do not block (overrides the
					// blanket opt-in pre-consent block).
					continue;
				} elseif ( ! $is_optout_law ) {
					// No ruleset opinion: opt-in law blocks every non-necessary
					// category until the visitor consents.
					//
					// The category's `prior_consent` flag is deliberately NOT read
					// here. It pre-grants the category on the client (defaultConsent
					// = true seeds the in-memory store and pre-ticks the toggle) for
					// an admin-declared consent-exempt category; the server keeps
					// its server-rendered tags blocked under an opt-in law until an
					// affirmative act, because unblocking on a REST/import-only flag
					// would be pre-consent loading the moment it is misconfigured.
					// Fail-closed is the documented split (see the REST schema of
					// `prior_consent` in class-categories-api.php and README.md).
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
		return $blocked;
	}

	/**
	 * Union of two blocked-category lists, in catalogue order.
	 *
	 * @param array $categories Category rows.
	 * @param array $first      Slugs.
	 * @param array $second     Slugs.
	 * @return array
	 */
	private function union_blocked_categories( $categories, $first, $second ) {
		$union = array();
		foreach ( $categories as $cat_data ) {
			$slug = ( new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $cat_data ) )->get_slug();
			if ( in_array( $slug, $first, true ) || in_array( $slug, $second, true ) ) {
				$union[] = $slug;
			}
		}
		return $union;
	}

	/**
	 * Whether the request carries a Global Privacy Control signal (Sec-GPC: 1).
	 *
	 * @return bool
	 */
	private function request_has_gpc() {
		return isset( $_SERVER['HTTP_SEC_GPC'] )
			&& '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) );
	}

	/**
	 * Whether the visitor submitted the Do Not Sell or Share form.
	 *
	 * @return bool
	 */
	private function request_has_dnsmpi() {
		$name = function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( 'fazcookie-dnsmpi' ) : 'fazcookie-dnsmpi';
		return isset( $_COOKIE[ $name ] )
			&& '1' === sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
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
		$this->service_consent_cache             = array();
		$this->enforcement_service_consent_cache = array();
		// Jurisdiction shell: same invariance requirement as Cache Compatibility
		// Mode — svc.* decisions live in the consent cookie, which cached HTML
		// must not depend on. Enforced client-side instead.
		if ( $this->is_cache_compatibility_enabled() || $this->is_geo_bootstrap_cache_active() ) {
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

		$consent = $this->get_scoped_valid_consent_cookie();
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

		$signal_blocked_services = $this->get_signal_blocked_services( $consent );

		// Extract valid svc.* entries for services currently exposed by this site.
		if ( preg_match_all( '/(?:^|,)svc\.([a-z0-9_-]+):(yes|no)(?=,|$)/', $consent, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( ! isset( $active_service_ids[ $match[1] ] ) ) {
					continue;
				}
				if ( 'yes' === $match[2] && isset( $signal_blocked_services[ $match[1] ] ) ) {
					continue;
				}
				// The cookie layers honour the grant whatever the render does:
				// see get_enforcement_service_consent().
				$this->enforcement_service_consent_cache[ $match[1] ] = $match[2];
				// Shareable fallback render (see get_blocked_categories()): an
				// explicit svc.* NO still blocks this visitor's embed, a YES is
				// left for script.js to release.
				if ( 'yes' === $match[2] && 'fallback' === $this->visitor_state_veto ) {
					continue;
				}
				$this->service_consent_cache[ $match[1] ] = $match[2];
			}
		}
		return $this->service_consent_cache;
	}

	/**
	 * Per-service decisions the destructive cookie layers enforce.
	 *
	 * get_service_consent() drops an svc.* YES on a 'fallback' render so the
	 * shareable page does not execute the embed; the shredder and the
	 * Set-Cookie guard must still honour it, for the same reason as
	 * get_enforcement_blocked_categories(). Signal-blocked grants are excluded
	 * here exactly as there.
	 *
	 * @return array [ 'google-analytics' => 'yes', 'hotjar' => 'no', ... ]
	 */
	private function get_enforcement_service_consent() {
		$render = $this->get_service_consent();
		return is_array( $this->enforcement_service_consent_cache ) ? $this->enforcement_service_consent_cache : $render;
	}

	/**
	 * Services whose grants cannot override the current sale/share opt-out.
	 * Shared by svc.* and ck.*: a per-cookie YES must not reopen a category
	 * after the service grant has already been filtered. Explicit NO survives.
	 * GPC permits a marked exception; a Do Not Sell request never does.
	 *
	 * @param string $consent Valid consent cookie.
	 * @return array<string,bool>
	 */
	private function get_signal_blocked_services( $consent ) {
		$gpc_header     = $this->request_has_gpc();
		$dnsmpi_req     = $this->request_has_dnsmpi();
		$signal_blocked = array();
		if ( $gpc_header || $dnsmpi_req ) {
			$this->get_blocked_categories();
			$signal_blocked = is_array( $this->signal_blocked_categories ) ? $this->signal_blocked_categories : array();
		}
		$gpc_exceptions = array();
		if ( ! empty( $signal_blocked ) && ! in_array( 'dnsmpi', $signal_blocked, true ) ) {
			if ( preg_match_all( '/(?:^|,)gpcx\.([a-z0-9_-]+):1(?=,|$)/', $consent, $gx, PREG_SET_ORDER ) ) {
				foreach ( $gx as $g ) {
					// A marker without its live service grant is not an exception.
					if ( preg_match( '/(?:^|,)svc\.' . preg_quote( $g[1], '/' ) . ':yes(?=,|$)/', $consent ) ) {
						$gpc_exceptions[ $g[1] ] = true;
					}
				}
			}
		}
		$blocked = array();
		foreach ( $this->get_enforceable_services() as $service ) {
			if ( isset( $signal_blocked[ $service['category'] ] ) && ! isset( $gpc_exceptions[ $service['id'] ] ) ) {
				$blocked[ $service['id'] ] = true;
			}
		}
		return $blocked;
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

		// Same matching strategy as match_script_to_provider() — see the
		// comment there. Metadata built once per request off the memoized map.
		if ( null === $this->service_match_meta_cache ) {
			$service_meta = array();
			foreach ( $pattern_map as $pattern => $service_ids ) {
				if ( empty( $pattern ) ) {
					continue;
				}
				$pattern        = (string) $pattern;
				$service_meta[] = array(
					'pattern'     => $pattern,
					'lower'       => strtolower( $pattern ),
					'is_url'      => false !== strpos( $pattern, '.' ) || false !== strpos( $pattern, '/' ),
					'service_ids' => $service_ids,
				);
			}
			$this->service_match_meta_cache = $service_meta;
		}

		$url_lc    = strtolower( $url );
		$inline_lc = null; // Lazily lowered — only decoded data: URI payloads need it.

		$matched_service_ids = array();
		foreach ( $this->service_match_meta_cache as $m ) {
			$matched = ( '' !== $url_lc && $this->provider_pattern_matches_lc( $url_lc, $m['lower'] ) );
			if ( ! $matched ) {
				if ( ! $m['is_url'] ) {
					$matched = false !== stripos( $inline, $m['pattern'] );
				} elseif ( $is_data_uri_payload ) {
					if ( null === $inline_lc ) {
						$inline_lc = strtolower( $inline );
					}
					$matched = $this->provider_pattern_matches_lc( $inline_lc, $m['lower'] );
				}
			}
			if ( $matched ) {
				foreach ( $m['service_ids'] as $service_id ) {
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
		// Every URL value is matched as the engine resolves it, not as it is
		// written: see normalize_resource_url().
		$url = $this->normalize_resource_url( $this->extract_tag_attr( $attrs, 'src' ) );
		if ( '' === $url ) {
			$url = $this->normalize_resource_url( $this->extract_tag_attr( $attrs, 'href' ) );
		}
		if ( '' === $url ) {
			// The legacy SVG spelling is its own token to the tokenizer; the
			// lookbehind pattern this replaced used to find `href` inside it.
			$url = $this->normalize_resource_url( $this->extract_tag_attr( $attrs, 'xlink:href' ) );
		}
		if ( '' === $url ) {
			// The legacy `background` presentation attribute carries a URL that
			// the CSS-less engine still fetches. Last in the chain because it is
			// only ever the URL when there is no src and no href, so no existing
			// caller's answer changes.
			$url = $this->normalize_resource_url( $this->extract_tag_attr( $attrs, 'background' ) );
		}
		// srcset / imagesrcset are matched IN ADDITION to src, never as a mere
		// fallback: a responsive image can pair a perfectly innocent src with a
		// tracking candidate in srcset, and the browser prefers the srcset one.
		// Treating them as a fallback (the first shape of this fix) left exactly
		// that tag ungated, because src was non-empty. Kept out of $url so the
		// data: URI branch below still sees only the primary URL; folded into the
		// haystack instead, which is what provider matching actually reads.
		// Descriptors ("2x", "640w") and inter-candidate commas are harmless to
		// fragment matching.
		$srcset_haystack = trim(
			$this->normalize_resource_list( $this->extract_tag_attr( $attrs, 'srcset' ) ) . ' ' . $this->normalize_resource_list( $this->extract_tag_attr( $attrs, 'imagesrcset' ) )
		);

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
			// srcset joins `url`, not just the haystack: match_script_to_provider()
			// tests the ~840 URL-fragment patterns against `url` alone, so a
			// candidate living only in srcset matched nothing when it was folded
			// into the haystack only. Appended AFTER the data: branch above, which
			// must keep seeing the primary URL by itself.
			'url'                 => trim( $url . ' ' . $srcset_haystack ),
			'content'             => $normalized_content,
			'haystack'            => trim( $url . ' ' . $srcset_haystack . ' ' . $normalized_content ),
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
			// `\` is a slash to every special-scheme URL parser. Userinfo is
			// not a separator here: normalize_resource_url() drops it, so the
			// host after an `@` is matched at a `/`, while an `@` inside a
			// path or a query (`/local.png?u=me@cdn.tracker.test`) is not a
			// boundary and does not over-park.
			if ( ! preg_match( '/[\/.:\s"\'`=;,(<{\[\\\\]/', $before ) ) {
				return false;
			}
		}

		// A pattern that already ends on a separator carries its own right-hand
		// boundary: `js.hs-scripts.com/` cannot be a prefix of a longer domain
		// label, because the slash has closed it. Demanding another separator
		// after it means the pattern can only ever match at the very end of a
		// URL — so a perfectly ordinary `js.hs-scripts.com/12345.js` was left
		// unblocked, the next character being `1`. Twenty of the shipped
		// provider definitions carry at least one pattern in this shape.
		$last_char = substr( $target, $index + $length - 1, 1 );
		if ( '' !== $last_char && preg_match( '/[\/.:\?#=&]/', $last_char ) ) {
			return true;
		}

		$after_pos = $index + $length;
		if ( $after_pos < strlen( $target ) ) {
			$after = substr( $target, $after_pos, 1 );
			if ( ! preg_match( '/[\/.:\?#\s"\'=;,&)<}\]\\\\]/', $after ) ) {
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
		$map                = array();
		$valid_categories   = $this->get_valid_category_slugs();
		$blocked_categories = null;

		// 1. Cookie-DB patterns can belong to multiple categories. Keep the
		// first valid non-necessary category as fallback, but never discard a
		// denied category merely because an allowed one was listed first.
		foreach ( $this->providers as $pattern => $cats ) {
			if ( empty( $cats ) ) {
				continue;
			}
			$selected = false;
			foreach ( $cats as $candidate ) {
				if ( 'necessary' === $candidate || ! in_array( $candidate, $valid_categories, true ) ) {
					continue;
				}
				if ( false === $selected ) {
					$selected = $candidate;
					continue;
				}
				// Consent is only needed to choose between valid categories;
				// single-category patterns retain their existing classification.
				if ( null === $blocked_categories ) {
					$blocked_categories = $this->get_blocked_categories();
				}
				if ( in_array( $selected, $blocked_categories, true ) ) {
					break;
				}
				if ( in_array( $candidate, $blocked_categories, true ) ) {
					$selected = $candidate;
					break;
				}
			}
			if ( false !== $selected ) {
				$map[ $pattern ] = $selected;
			}
		}

		// 2. Known providers database.
		$known_map        = Known_Providers::get_pattern_map();
		foreach ( $known_map as $pattern => $category ) {
			$category = $this->known_provider_category( $category, $pattern, $valid_categories );
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
		// The category must be a REAL category slug, mirroring the client-side
		// check in get_store_data(): a rule whose category no longer exists
		// (the legacy 'performance' slug, or a category the admin deleted)
		// would otherwise override a built-in tracker's category with one that
		// is never in get_blocked_categories() — i.e. the server would ship
		// the tracker unblocked pre-consent while the client, which skips the
		// rule, still blocked it. Skipping the rule keeps the built-in
		// classification and fails closed on both layers.
		$settings        = $this->get_faz_settings();
		$custom_rules    = isset( $settings['script_blocking']['custom_rules'] ) ? $settings['script_blocking']['custom_rules'] : array();
		$custom_patterns  = array();
		foreach ( $custom_rules as $rule ) {
			$pattern  = isset( $rule['pattern'] ) ? $rule['pattern'] : '';
			$category = isset( $rule['category'] ) ? $rule['category'] : '';
			if ( ! empty( $pattern ) && ! empty( $category ) && in_array( $category, $valid_categories, true ) ) {
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
		// 5b. Same treatment for Instagram Feed's own patterns when it is limiting
		// itself. Exempting the container (process_social_embeds) without this was
		// a half measure that produced the worst of the three outcomes: the feed's
		// <div> rendered while its first-party script stayed blocked, so the
		// visitor got an empty box with no placeholder to explain it — worse than
		// either blocking the feed or letting it work. With the GDPR setting on
		// Yes the plugin serves local copies and contacts nothing, so its script
		// is first-party and declares no cookies; there is nothing left to gate.
		// Pattern list read from the provider database rather than repeated here,
		// so the two cannot drift apart.
		$sb_exempt_patterns = self::smash_balloon_exempt_patterns();

		foreach ( array_keys( $map ) as $p ) {
			$is_explicit_rule = isset( $custom_patterns[ $p ] ) || ! array_key_exists( $p, $pre_filter_map );
			if ( $is_explicit_rule ) {
				continue;
			}
			if ( $this->is_always_allowed_gateway_pattern( $p ) ) {
				unset( $map[ $p ] );
				continue;
			}
			if ( isset( $sb_exempt_patterns[ $p ] ) ) {
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
	 * Blocking → whitelist_patterns) AND of payment gateways allowed on the
	 * current request (explicit admin opt-in or a real WooCommerce checkout/cart),
	 * whose scripts run pre-consent so their cookies may persist too. A gateway
	 * that is neither opted in nor needed on this request is never exempted.
	 *
	 * Used both by `get_store_data()` to populate
	 * `_whitelistedCookiePatterns` for the frontend network interceptors,
	 * AND by `shred_non_consented_cookies()` so the server-side shredder
	 * on `template_redirect` doesn't delete cookies that the frontend whitelist
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
	 * Each whitelist entry is ALSO emitted verbatim as a cookie-name pattern,
	 * so an admin can exempt a specific cookie by name (or `name_*`) even when
	 * it belongs to no Known_Providers service. That is the supported remedy
	 * for a false positive, and it must exist before enforcement is allowed to
	 * delete anything.
	 *
	 * @param string[] $user_whitelist   Sanitised patterns from settings.
	 * @param string[] $valid_categories Category slugs that exist in this install.
	 * @return string[] Unique cookie-name patterns to skip on shred/interceptor.
	 */
	private function compute_whitelisted_cookie_patterns( $user_whitelist, $valid_categories ) {
		$patterns = array();
		$known    = Known_Providers::get_all();

		// A whitelist entry is also honoured as a literal cookie-NAME pattern
		// (wildcards included, via cookie_name_matches()). Without this the
		// whitelist can only rescue cookies belonging to a Known_Providers
		// service, so an admin whose own catalogued cookie is being shredded —
		// or a false positive from any tier outside that map — has no supported
		// remedy at all. The same three-character minimum applies: a one- or
		// two-character token is not a cookie name, it is a typo.
		foreach ( (array) $user_whitelist as $allowed ) {
			$allowed = sanitize_text_field( (string) $allowed );
			if ( '' === $allowed || strlen( $allowed ) < 3 ) {
				continue;
			}
			$patterns[] = $allowed;
		}

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

			// Payment-gateway cookies (Stripe __stripe_mid, etc.) are exempt only
			// when the same gateway scripts are allowed on this request: an explicit
			// per-gateway admin opt-in or a resolved WooCommerce checkout/cart. This
			// keeps script blocking, server shredding, client cleanup and developer
			// filter extensions on the same context-aware source of truth.
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
	 * @param string     $attrs              The tag's attribute string.
	 * @param string     $content            The inline script content.
	 * @param array      $providers          Provider map from get_provider_category_map().
	 * @param array|null $blocked_categories Categories blocked on this request, so a
	 *                                       blocked match can win over an allowed one.
	 *                                       Null falls back to get_blocked_categories().
	 * @return string|false Matched category slug or false.
	 */
	private function match_script_to_provider( $attrs, $content, $providers, $blocked_categories = null ) {
		$match_context = $this->get_provider_match_context( $attrs, $content );
		$url                 = $match_context['url'];
		$inline              = $match_context['content'];
		$is_data_uri_payload = ! empty( $match_context['is_data_uri_payload'] );

		// Hot loop: runs for every <script>/<iframe>/<style>/<link> in the page
		// against ~1,000 patterns (~840 URL fragments + ~200 code signatures).
		// Exact-semantics optimizations, benchmarked on PHP 8.4:
		//   1. Pattern metadata (lowercased form, URL-vs-signature class) is
		//      precomputed once per request instead of per tag×pattern.
		//   2. The URL haystack (short) is lowercased ONCE per tag and the 840
		//      URL patterns matched with strpos() — measurably faster than
		//      per-pattern stripos(), and strpos(lc(h), lc(n)) is
		//      byte-identical to stripos(h, n).
		//   3. Inline content keeps per-pattern stripos(): PHP 8's stripos is
		//      as fast as strpos on large haystacks, so pre-lowering a big
		//      inline blob per tag would be pure overhead for the ~200
		//      signature patterns.
		$meta      = $this->get_provider_match_meta( $providers );
		$url_lc    = strtolower( $url );
		$inline_lc = null; // Lazily lowered — only decoded data: URI payloads need it.

		// Same fail-closed rule as filter_script_loader_tag(): when more than
		// one pattern matches a tag, a BLOCKED category decides and an allowed
		// one is only a fallback. Every caller of this method treats a
		// non-blocked category as "let it through", so returning the first match
		// let a generic pattern in an allowed category release a tracker that a
		// specific pattern classifies as blocked — in six call sites, and on the
		// output-buffer layer that exists to catch what the tag filter misses.
		// Both layers read the same ordered map, so without this they fail
		// together rather than backing each other up.
		//
		// Cost: a tag whose only matches are in allowed categories now scans the
		// rest of the map instead of returning early. Tags that match nothing
		// already scanned it whole, and a tag that matches a blocked pattern
		// still returns at once, so the extra work is confined to the minority
		// of tags that match only allowed patterns.
		// Passed in by every caller, which already holds the list for its own
		// decision. Read as an argument rather than fetched here so this matcher
		// keeps depending on nothing but its inputs: reaching for
		// get_blocked_categories() from inside it pulled the admin category
		// catalogue into a pure matching routine, and two standalone unit
		// harnesses that legitimately stub only what their own path touches
		// fatalled on the missing class. The fallback covers a future caller
		// that forgets, and never runs today.
		if ( null === $blocked_categories ) {
			$blocked_categories = $this->get_blocked_categories();
		}
		$fallback_category = false;

		foreach ( $meta as $m ) {
			$matched = false;
			// Patterns that look like URL fragments (contain '.' or '/') are designed
			// to match tracker domains in src/href attributes.  Applying them to the
			// inline text body causes false positives: config scripts that merely
			// reference a tracker domain in their data (e.g. Rank Math's rankMath.links
			// object contains youtu.be, facebook.com, etc.) would be incorrectly blocked.
			if ( '' !== $url_lc && $this->provider_pattern_matches_lc( $url_lc, $m['lower'] ) ) {
				$matched = true;
			} elseif ( ! $m['is_url'] ) {
				// Code-signature patterns (fbq(, gtag, _ga …) match inline content.
				if ( false !== stripos( $inline, $m['pattern'] ) ) {
					$matched = true;
				}
			} elseif ( $is_data_uri_payload ) {
				// URL-fragment patterns may also match decoded data: script payloads
				// because that payload is the executable source, not page data.
				if ( null === $inline_lc ) {
					$inline_lc = strtolower( $inline );
				}
				if ( $this->provider_pattern_matches_lc( $inline_lc, $m['lower'] ) ) {
					$matched = true;
				}
			}
			if ( ! $matched ) {
				continue;
			}
			if ( in_array( $m['category'], $blocked_categories, true ) ) {
				return $m['category'];
			}
			if ( false === $fallback_category ) {
				$fallback_category = $m['category'];
			}
		}

		return $fallback_category;
	}

	/**
	 * Precompute per-pattern matching metadata for the provider map.
	 *
	 * get_provider_category_map() is memoized per Frontend instance and every
	 * internal caller passes that same filtered map. Return the built table
	 * immediately: hashing all ~1,000 keys here on every inspected HTML tag would
	 * recreate the O(tags × patterns) traversal this cache exists to remove.
	 *
	 * @param array $providers Provider map [ pattern => category ].
	 * @return array[] Ordered list of { lower, is_url, category }.
	 */
	private function get_provider_match_meta( $providers ) {
		if ( null !== $this->provider_match_meta_cache ) {
			return $this->provider_match_meta_cache;
		}
		$meta = array();
		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			$pattern = (string) $pattern;
			$meta[]  = array(
				'pattern'  => $pattern,
				'lower'    => strtolower( $pattern ),
				'is_url'   => false !== strpos( $pattern, '.' ) || false !== strpos( $pattern, '/' ),
				'category' => $category,
			);
		}
		$this->provider_match_meta_cache = $meta;
		return $meta;
	}

	/**
	 * provider_url_pattern_matches() for pre-lowercased inputs.
	 *
	 * Identical semantics (stripos ≡ strpos over lowercased operands, same
	 * tolower table) without stripos's per-call haystack copy. The boundary
	 * check is case-independent — it only tests separator characters.
	 *
	 * @param string $target_lc  Lowercased URL-like haystack.
	 * @param string $pattern_lc Lowercased provider pattern.
	 * @return bool
	 */
	private function provider_pattern_matches_lc( $target_lc, $pattern_lc ) {
		if ( '' === $target_lc || '' === $pattern_lc ) {
			return false;
		}

		$offset = 0;
		$length = strlen( $pattern_lc );
		while ( false !== ( $index = strpos( $target_lc, $pattern_lc, $offset ) ) ) {
			if ( $this->has_provider_boundary( $target_lc, $index, $length ) ) {
				return true;
			}
			$offset = $index + 1;
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
		// The `type` TOKEN, every occurrence of it, rewritten in place: a
		// regex over the attribute string rewrote `type='module'` inside an
		// alt value and left the script with no real type at all, executing.
		if ( ! $this->has_attr_token( $attrs, 'type' ) ) {
			// No type: PREPEND one, so that if the tokenizer failed on a tag
			// that does carry a type the parser's first-wins rule still reads
			// the inert one.
			return ' type="text/plain"' . $attrs;
		}
		$original = $this->extract_tag_attr( $attrs, 'type' );
		$source   = $attrs;
		$attrs    = $this->rewrite_attr_tokens(
			$source,
			array( 'type' ),
			function ( $token ) use ( $source ) {
				$glued = $token['offset'] > 0 && ! ctype_space( $source[ $token['offset'] - 1 ] );
				return ( $glued ? ' ' : '' ) . 'type="text/plain"';
			}
		);
		// Preserve non-default types (e.g. "module") so JS can restore them.
		if ( 'text/plain' !== $original && 'text/javascript' !== $original && '' !== $original && ! $this->has_attr_token( $attrs, 'data-faz-original-type' ) ) {
			$attrs .= ' data-faz-original-type="' . esc_attr( $original ) . '"';
		}
		return $attrs;
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
	 * Names an administrator has explicitly declared despite the name-based
	 * suppression above. Written only by the scanner's set-aside review.
	 */
	const DECLARED_INTERNAL_OPTION = 'faz_declared_internal_cookies';

	/** Request-lifetime cache for DECLARED_INTERNAL_OPTION. */
	private static $declared_internal_cache = null;

	/**
	 * Whether suppression of this name is a FACT about WordPress rather than a
	 * guess about the site.
	 *
	 * is_wp_internal_cookie() answers two different questions with one list:
	 * "never shred this" and "never show this". For authentication and
	 * administrative state the two answers coincide and both are certain — a
	 * logged-out visitor structurally cannot receive `wordpress_logged_in_*`,
	 * so declaring it would misdescribe the site in the opposite direction.
	 * Those stay non-negotiable here.
	 *
	 * Everything else on that list is site-dependent, which is the whole of
	 * issue #243: `_lscache_vary` never reaches an anonymous visitor on a
	 * plain blog and reaches every one of them on a WooCommerce site with a
	 * cart, and `comment_author_*`, `wp-postpass_`, `wpdiscuz_nonce_` and
	 * `wordpress_test_cookie` are all set for people who are not logged in at
	 * all. Suppressing those is a reasonable default, not a fact, so an
	 * administrator who has looked at their own site may overrule it.
	 *
	 * @param string $name Cookie name.
	 * @return bool
	 */
	public static function internal_cookie_is_structural( $name ) {
		$prefixes = array(
			'wordpress_logged_in_',
			'wordpress_sec_',
			'wp-settings-',
			'wp-settings-time-',
		);
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( (string) $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this cookie must be kept out of everything a visitor reads.
	 *
	 * The display-side twin of is_wp_internal_cookie(), and deliberately a
	 * SEPARATE predicate rather than a change to it. is_wp_internal_cookie()
	 * still governs is_always_allowed_cookie_name(), so the invariant that
	 * matters for site breakage is strengthened rather than relaxed: these
	 * cookies are never shredded whether or not they are declared. Declaring
	 * one only ever adds transparency; it can never arm a deletion.
	 *
	 * Default behaviour is byte-identical to before — every name the old
	 * predicate hid is still hidden until an administrator says otherwise
	 * about that specific name.
	 *
	 * @param string $name Cookie name.
	 * @return bool
	 */
	public static function is_declaration_suppressed( $name ) {
		$name = (string) $name;
		if ( ! self::is_wp_internal_cookie( $name ) ) {
			return false;
		}
		if ( self::internal_cookie_is_structural( $name ) ) {
			return true;
		}
		return ! in_array( $name, self::declared_internal_cookies(), true );
	}

	/**
	 * The administrator's per-name overrides, read once per request.
	 *
	 * @return string[]
	 */
	public static function declared_internal_cookies() {
		if ( null !== self::$declared_internal_cache ) {
			return self::$declared_internal_cache;
		}
		$stored = get_option( self::DECLARED_INTERNAL_OPTION, array() );
		$names  = array();
		foreach ( (array) $stored as $stored_name ) {
			$stored_name = sanitize_text_field( (string) $stored_name );
			// An override is only meaningful for a name the default would hide,
			// and it must never reach the structural tier — re-checked on READ
			// as well as on write, so a row that predates this rule (or arrives
			// by a route that skipped the guard) cannot take effect.
			if ( '' === $stored_name || self::internal_cookie_is_structural( $stored_name ) ) {
				continue;
			}
			$names[] = $stored_name;
		}
		self::$declared_internal_cache = array_values( array_unique( $names ) );
		return self::$declared_internal_cache;
	}

	/** Drop the request-lifetime override cache. For tests and after a write. */
	public static function flush_declared_internal_cache() {
		self::$declared_internal_cache = null;
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
				// Sensitive processing is never bundled into Accept All when the
				// jurisdiction requires a distinct affirmative choice.
				'requiresSeparateOptIn' => Geo_Runtime::requires_separate_optin( $this->get_runtime_ruleset(), $category->get_slug() ),
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
			if ( self::is_declaration_suppressed( $name ) ) {
				continue;
			}
			$provider = isset( $item->url_pattern ) ? sanitize_text_field( (string) $item->url_pattern ) : '';
			$cookies[] = array(
				'cookieID'    => $name,
				'neverDelete' => self::is_wp_internal_cookie( $name ),
				'domain'      => isset( $item->domain ) ? sanitize_text_field( (string) $item->domain ) : '',
				'provider'    => $provider,
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
			if ( self::is_declaration_suppressed( $cookie->get_name() ) ) {
				continue;
			}
			$cookies[] = array(
				'cookieID'    => sanitize_text_field( $cookie->get_name() ),
				'neverDelete' => self::is_wp_internal_cookie( $cookie->get_name() ),
				'domain'      => sanitize_text_field( $cookie->get_domain() ),
				'provider'    => sanitize_text_field( $cookie->get_url_pattern() ),
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
		return self::prepare_banner_tags( $this->banner );
	}

	/**
	 * Prepare one banner's tag/style descriptors for the frontend.
	 *
	 * @since 1.27.0
	 * @param object|false $banner Banner instance.
	 * @return array
	 */
	public static function prepare_banner_tags( $banner ) {
		$data = array();
		if ( ! $banner ) {
			return $data;
		}
		$settings  = $banner->get_settings();
		$configs   = isset( $settings['config'] ) ? $settings['config'] : array();
		$supported = array(
			'accept-button',
			'reject-button',
			'settings-button',
			'readmore-button',
			'donotsell-button',
			'show-desc-button',
			'hide-desc-button',
			'always-active',
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
		return self::prepare_banner_config( $this->banner );
	}

	/**
	 * Prepare one banner's compact client configuration.
	 *
	 * Shared with the jurisdiction bootstrap REST response so a cached strict
	 * shell can atomically replace every behaviour/layout value before the
	 * client mounts the visitor's actual banner.
	 *
	 * @since 1.27.0
	 * @param object|false $banner Banner instance.
	 * @return array
	 */
	public static function prepare_banner_config( $banner ) {
		$data   = array();

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
		$data['settings']['softCookieWall']           = 'classic' !== ( $settings['type'] ?? '' ) && ! empty( $settings['softCookieWall'] );
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
		$raw_css = isset( $this->template['styles'] ) ? $this->template['styles'] : '';
		return self::prepare_banner_styles( $raw_css );
	}

	/**
	 * Rules for the [faz_cookie_settings] "manage consent preferences" button.
	 *
	 * Not the floating .faz-btn-revisit widget. Rendered inside page content
	 * (outside #faz-consent), so it can't inherit the banner's scoped
	 * rules — replicate the primary "accept" button here. The COLOUR
	 * properties consume the same --faz-accept-button-* custom properties
	 * the admin sets in Banner > Colours, which script.js exposes on :root;
	 * the --faz-btn-* sizing properties (font/line-height/padding/radius)
	 * are static design constants shared with the template's .faz-btn rule
	 * (never JS-set), so the gdpr.json defaults below are the effective
	 * values and the button still looks right before JS runs.
	 *
	 * @return string
	 */
	private static function settings_button_css() {
		return '.faz-cookie-settings-btn{'
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
	}

	/**
	 * Rules that style server-rendered PAGE content rather than the banner.
	 *
	 * They have to apply at first paint, before script.js runs, so when the
	 * banner stylesheet is delivered as a non-blocking link they are also
	 * printed inline:
	 *
	 * - `.faz-hidden` hides the original of a blocked social embed that
	 *   process_social_embeds() marks server-side; a late rule would let the
	 *   tracker-backed markup show for a moment.
	 * - `.video-placeholder-*` is the size floor for the placeholders
	 *   _fazAddPlaceholder inserts next to blocked iframes (outside
	 *   #faz-consent, so the scoped template rules never reach them); without
	 *   it lazy-loaded iframes (Bricks/Elementor/Divi Video) collapse to 0×0
	 *   (issue #87).
	 * - `.faz-consent-bridge` keeps the consent-forwarding iframe invisible.
	 * - The [faz_cookie_settings] button sits in the page content.
	 *
	 * @return string
	 */
	private static function page_content_css() {
		return self::page_utility_css() . self::settings_button_css();
	}

	/**
	 * Keep the rendered-but-unrevealed banner out of the page flow.
	 *
	 * script.js inserts the overlay, revisit widget, notice and preference
	 * modal at the top of <body> before the non-blocking banner stylesheet
	 * may have arrived. #faz-style-inline only hides them (visibility keeps
	 * their space), and the rules that take them out of the flow live in
	 * that stylesheet, so until it applies the invisible preference modal
	 * pushed the page down by its full height and everything jumped back on
	 * load — a layout shift on exactly the slow visits the non-blocking
	 * delivery is for. Scoped to `html:not(.faz-ready)`: script.js adds
	 * faz-ready only once the sheet applies, so the banner's own positioning
	 * takes over in the same frame. pointer-events:none because the
	 * container and modal boxes themselves carry no data-faz-tag, so they stay
	 * hit-testable while transparent: fixed at the top of the viewport they
	 * would swallow taps meant for the page. Printed only with the non-blocking link;
	 * a render-blocking sheet is always in place before the banner exists.
	 *
	 * @return string
	 */
	private static function pending_banner_css() {
		return 'html:not(.faz-ready) .faz-overlay,html:not(.faz-ready) .faz-btn-revisit-wrapper,'
			. 'html:not(.faz-ready) .faz-consent-container,html:not(.faz-ready) .faz-modal{position:fixed!important;pointer-events:none!important}';
	}

	/**
	 * The utility half of page_content_css(): every rule except the
	 * [faz_cookie_settings] button, which prepare_banner_styles() already
	 * puts in the banner stylesheet.
	 *
	 * @return string
	 */
	private static function page_utility_css() {
		return '.faz-hidden{display:none!important;visibility:hidden!important}'
			. '.faz-hide{display:none!important}'
			. '.faz-consent-bridge{width:0;height:0;border:0}'
			. '.video-placeholder-normal,.video-placeholder-youtube{min-height:200px;display:flex;align-items:center;justify-content:center;width:100%;max-width:100%;box-sizing:border-box}';
	}

	/**
	 * Assemble the exact CSS shipped for a banner template.
	 *
	 * The live jurisdiction endpoint must return the same scoped/reset/utility
	 * stylesheet as the initial PHP render. Returning Template::get_styles()
	 * directly would omit specificity boosting and the safety/placeholder rules,
	 * so a cached shell swapped to another banner could look or behave differently
	 * from a normal uncached render.
	 *
	 * @since 1.27.0
	 * @param string $raw_css Raw template CSS.
	 * @return string Complete CSS string ready for inline output.
	 */
	public static function prepare_banner_styles( $raw_css ) {
		$raw_css   = is_string( $raw_css ) ? $raw_css : '';
		$layout    = self::get_mobile_layout();
		// Keep an explicit pipeline revision in addition to FAZ_VERSION so a
		// development deploy cannot reuse CSS assembled before utility rules were
		// added. Release builds also invalidate through the version as usual.
		//
		// The mobile layout is part of the key because it changes the assembled
		// CSS without changing $raw_css: keyed on the template alone, toggling
		// the setting would keep serving the previously cached stylesheet for a
		// day and the setting would look broken. It is a site-wide value, not a
		// per-visitor one, so it stays safe under Cache Compatibility Mode.
		// v4: the compact layout gained a full-width row for the Do-Not-Sell
		// control. That rule is appended after $raw_css is hashed, so without
		// this bump an install that had already cached v3 within this same
		// plugin version would keep serving the stylesheet in which the control
		// collapses — the revision is here for exactly this case.
		// v5: the compact labels wrap instead of clipping, the buttons follow
		// DOM order and the classic chevron keeps its padding. Same reason.
		// v7: style the consent identifier in both inline and detached modals.
		$cache_key = 'faz_boosted_css_v7_' . FAZ_VERSION . '_' . $layout . '_' . md5( $raw_css );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$css       = self::boost_css_specificity( $raw_css );
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
			. '}'
			. '#faz-consent [data-faz-tag="detail-title"],'
			. '.faz-modal [data-faz-tag="detail-title"]{color:var(--faz-detail-title-color,#212121);}'
			. '#faz-consent [data-faz-tag="detail-description"],'
			. '.faz-modal [data-faz-tag="detail-description"]{color:var(--faz-detail-description-color,#212121);}'
			. '#faz-consent [data-faz-tag="detail-category-description"],'
			. '.faz-modal [data-faz-tag="detail-category-description"]{color:var(--faz-detail-category-description-color,#212121);}'
			. '#faz-consent [data-faz-tag="audit-table"],'
			. '.faz-modal [data-faz-tag="audit-table"]{color:var(--faz-audit-table-color,#212121);}';
		$css_fixes .= '#faz-consent [data-faz-tag="detail-consent-id"],.faz-modal [data-faz-tag="detail-consent-id"]{display:block;margin:12px 24px;font-size:12px;color:var(--faz-detail-description-color,#212121);}'
			. '#faz-consent [data-faz-tag="detail-consent-id"][hidden],.faz-modal [data-faz-tag="detail-consent-id"][hidden]{display:none;}'
			. '#faz-consent [data-faz-tag="detail-consent-id"] input,.faz-modal [data-faz-tag="detail-consent-id"] input{display:block;width:100%;max-width:100%;margin-top:4px;padding:6px 8px;border:1px solid #c5c7cb;border-radius:4px;background:transparent;color:inherit;font-size:12px;}';
		$css = $css_reset . $css . $css_fixes . self::settings_button_css() . self::compact_mobile_css( $layout );

		set_transient( $cache_key, $css, DAY_IN_SECONDS );
		return $css;
	}

	/**
	 * Current phone layout for the consent notice.
	 *
	 * Static because prepare_banner_styles() is reached both from the frontend
	 * render and from the banner REST endpoint, neither of which shares an
	 * instance.
	 *
	 * @since 1.34.0
	 * @return string Either 'comfortable' or 'compact'; never anything else.
	 */
	private static function get_mobile_layout() {
		$settings = get_option( 'faz_settings', array() );
		$value    = '';
		if ( is_array( $settings ) && isset( $settings['banner_control']['mobile_layout'] ) ) {
			$value = $settings['banner_control']['mobile_layout'];
		}
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		// Re-check here rather than trusting the stored value. The sanitiser
		// already whitelists it on save, but this string decides which CSS is
		// emitted, and a row written before the sanitiser existed - or by hand -
		// must not reach the stylesheet.
		return 'compact' === $value ? 'compact' : 'comfortable';
	}

	/**
	 * Compact phone layout for the notice buttons.
	 *
	 * Below 440px the shipped templates give each notice button its own
	 * full-width row, which on a 390x844 screen makes the notice 372px tall -
	 * about 44% of the viewport, and past half of it on a 375x667 phone. Laying
	 * the buttons out on a shared row brings the same notice to 244px (29%).
	 *
	 * Five constraints shape the rules below, and none of them is cosmetic:
	 *
	 * 1. `flex: 1 1 0` makes the accept and reject buttons exactly as wide as
	 *    each other because the same layout pass sizes them, not because two
	 *    widths were written to match. EDPB Guidelines 03/2022 require the
	 *    accept and reject options to carry equal prominence, so equality has to
	 *    survive translation into any language - which hardcoded widths do not.
	 * 2. Labels wrap instead of being cut. Three buttons on a 361-440px row get
	 *    about 100px each, and translated labels do not fit that on one line
	 *    ("Az összes elfogadása", "Alles accepteren"). With `nowrap` the accept
	 *    label was clipped while the shorter reject label was not - two boxes of
	 *    equal size that no longer looked equal. Wrapping keeps every word
	 *    visible, and `align-items: stretch` on the row gives every button the
	 *    height of the tallest one, so a two-line accept never stands taller
	 *    than a one-line reject.
	 * 3. `min-height: 44px` keeps the tap target at the size a finger needs.
	 *    Shrinking the buttons to fit is exactly the wrong trade.
	 * 4. Visual order is DOM order. Every template writes the buttons as
	 *    [customise][reject][accept], and the template's own phone rules
	 *    reorder them with `order`, which moves the boxes but not the Tab
	 *    sequence: on a single row that made keyboard focus run right to left
	 *    (WCAG 2.4.3). Stacked one per row the mismatch is only odd; side by side
	 *    it is backwards. Rewriting the DOM would change every other layout, so
	 *    compact resets `order` instead and lets the markup decide. Accept and
	 *    reject stay adjacent either way, which is what keeps them reading as
	 *    one pair of equal options.
	 * 5. Under 360px three buttons no longer fit side by side. Accept and reject
	 *    stay paired on their own row and "customise" - the one control that
	 *    takes no part in the equal-prominence comparison - takes a row of its
	 *    own. Since it comes first in the markup, that row sits above the pair.
	 *
	 * Emitted AFTER boost_css_specificity(), so the selectors are written with
	 * their `#faz-consent` prefix already in place and need no `!important`:
	 * `#faz-consent .faz-notice-btn-wrapper .faz-btn` is specificity 1-2-0, equal
	 * to the template's boosted `.faz-notice-btn-wrapper .faz-btn-*` phone rules
	 * and above its 1-1-0 ones, and wins the ties on document order. The RTL and
	 * classic rules below carry the extra class those template rules carry.
	 *
	 * @since 1.34.0
	 * @param string $layout Resolved layout, already whitelisted.
	 * @return string CSS, or an empty string for the default layout.
	 */
	private static function compact_mobile_css( $layout ) {
		if ( 'compact' !== $layout ) {
			return '';
		}

		return '@media (max-width:440px){'
			// `stretch`, not the templates' `center`: every button in a row takes
			// the height of the tallest, so a label that wraps to two lines makes
			// its partner two lines tall as well instead of standing out.
			. '#faz-consent .faz-notice-btn-wrapper{'
			. 'flex-direction:row;flex-wrap:wrap;align-items:stretch;gap:8px;margin-top:12px;'
			. '}'
			// `order:0` hands the sequence back to the markup (constraint 4). It
			// also overrides the `.faz-btn-accept{margin-top:16px}` the box and
			// banner templates give the button they used to sort first.
			//
			// inline-flex centres a wrapped label vertically in a stretched box
			// for both variants the shortcode can emit: a <button> centres its
			// content natively, a link-type <a> does not.
			//
			// `overflow-wrap:break-word` only breaks inside a word that cannot fit
			// the box on its own line (a long compound), never ordinary text.
			. '#faz-consent .faz-notice-btn-wrapper .faz-btn{'
			. 'order:0;flex:1 1 0;width:auto;min-width:0;margin:0;'
			. 'display:inline-flex;align-items:center;justify-content:center;'
			. 'min-height:44px;font-size:13px;line-height:1.25;padding:8px 6px;'
			. 'white-space:normal;overflow-wrap:break-word;word-break:normal;hyphens:auto;'
			. '}'
			// The classic template's RTL phone rule gives reject alone an 8px
			// end margin (1-3-0, so the reset above does not reach it). The row
			// already has its gap; drop it so the spacing stays symmetric.
			. '#faz-consent .faz-rtl .faz-notice-btn-wrapper .faz-btn-reject{margin-right:0;}'
			// The classic template - also used for Full-width + Pushdown - draws
			// the customise chevron as an absolutely positioned ::after 12px
			// from the end of the button and relies on the 28px end padding it
			// sets on desktop to keep the label clear of it. With the padding
			// above the chevron sat on the label ("Personalizz▾"), so the end
			// padding is restored for that template only, and the chevron is
			// centred vertically because the stretched button is no longer the
			// 40px it was positioned for. Customise is not one of the compared
			// options, so the extra padding cannot unbalance accept and reject.
			. '#faz-consent.faz-classic-top .faz-notice-btn-wrapper .faz-btn-customize,'
			. '#faz-consent.faz-classic-bottom .faz-notice-btn-wrapper .faz-btn-customize{padding-right:28px;}'
			. '#faz-consent.faz-classic-top .faz-notice-btn-wrapper .faz-btn-customize::after,'
			. '#faz-consent.faz-classic-bottom .faz-notice-btn-wrapper .faz-btn-customize::after{top:calc(50% - 3px);}'
			// The Do-Not-Sell control is a flex child of this same wrapper, last
			// in the markup. On a single row it would have to share the first
			// line with the accept/reject pair, and at 360px their 40% bases
			// leave it almost nothing to grow into: it collapses to a few pixels
			// while its label spills across Accept.
			//
			// It is not one of the compared options — EDPB 03/2022 equal
			// prominence governs accept against reject — so it takes a full-width
			// row of its own, last, where it cannot compete with the pair and
			// cannot be squeezed. This is reached in normal operation, not only
			// by hand: Geo_Runtime turns donotSell on for a US visitor even when
			// applicableLaw is 'gdpr', and class-template.php then keeps the
			// button precisely because its status is true.
			//
			// Matched by data attribute because the two variants share nothing
			// else: the shortcode emits either `.faz-btn.faz-btn-do-not-sell` or a
			// bare `<a>` with no class at all. `display:block` keeps the
			// template's left-aligned link look instead of the centred flex box
			// the compared buttons use. The label - "Do Not Sell or Share My
			// Personal Information" - is statutory wording that cannot be
			// shortened, so it wraps like the others.
			. '#faz-consent .faz-notice-btn-wrapper [data-faz-tag="donotsell-button"]{'
			. 'flex:1 1 100%;width:100%;display:block;white-space:normal;'
			. '}'
			. '}'
			. '@media (max-width:360px){'
			// 40% rather than calc(50% - 4px): the exact value lands on the
			// width budget to the pixel, and one sub-pixel of rounding pushes
			// the second button onto its own row. A basis under half leaves
			// flex-grow to fill the row and cannot round into a wrap.
			. '#faz-consent .faz-notice-btn-wrapper .faz-btn-accept,'
			. '#faz-consent .faz-notice-btn-wrapper .faz-btn-reject{flex:1 1 40%;}'
			. '#faz-consent .faz-notice-btn-wrapper .faz-btn-customize{flex:1 1 100%;}'
			. '}';
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
	private static function boost_css_specificity( $css ) {
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
			'.faz-popup-center',
		);

		// Classes on sibling elements (outside #faz-consent in the DOM).
		$sibling_prefixes = array(
			'.faz-overlay',
			'.faz-btn-revisit',
			'.faz-revisit-',
			'.faz-hide',
			'.faz-hidden',
			'.faz-modal',
			'.faz-consent-bridge',
			'#faz-cookie-wall',
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

		$scoped = preg_replace_callback(
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
		return is_string( $scoped ) ? $scoped : $css;
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
	 * Whether a rendered asset tag id ("<handle>-js" / "<handle>-css")
	 * belongs to one of this plugin's own enqueued handles.
	 *
	 * @param string $id     Tag id attribute value.
	 * @param string $suffix Core-appended suffix ('-js' or '-css').
	 * @return bool
	 */
	private function is_own_asset_tag_id( $id, $suffix ) {
		if ( ! is_string( $id ) || '' === $id || '' === $suffix ) {
			return false;
		}
		$len = strlen( $suffix );
		if ( strlen( $id ) <= $len || substr( $id, -$len ) !== $suffix ) {
			return false;
		}
		return $this->is_own_script_handle( substr( $id, 0, -$len ) );
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

	/** Only a single global variable declaration whose value is JSON. */
	private function is_json_data_assignment( $content ) {
		if ( ! preg_match( '/^\s*(?:var|let|const)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=/', $content, $m ) ) {
			return false;
		}
		if ( 'location' === $m[1] ) {
			return false;
		}
		$value = rtrim( substr( $content, strlen( $m[0] ) ) );
		if ( ';' === substr( $value, -1 ) ) {
			$value = substr( $value, 0, -1 );
		}
		json_decode( $value );
		return JSON_ERROR_NONE === json_last_error();
	}

	/**
	 * Whether an inline script body only assigns JSON values.
	 *
	 * Accepted: one or more `var NAME = <JSON>`, `let NAME = <JSON>`,
	 * `const NAME = <JSON>` or `window.NAME = <JSON>` statements, each
	 * optionally ended by a semicolon, with nothing else in the body except
	 * whitespace, the CDATA markers core wraps localized data in, and
	 * `//# sourceURL=` / `//# sourceMappingURL=` lines. Every value must be
	 * valid JSON as json_decode() reads it, so an object literal holding a
	 * function, a single-quoted string, a reference to another variable or a
	 * call is not data and the script is judged as usual. A declaration list
	 * (`var a = {…}, b = …`) is rejected too: the part after the comma can be
	 * any expression. So is an assignment to `location`, which navigates.
	 *
	 * @param string $content Inline script body.
	 * @return bool
	 */
	private function is_inline_json_data_assignment( $content ) {
		if ( ! is_string( $content ) ) {
			return false;
		}
		// A `//` comment ends at ANY JavaScript line terminator: LF, CR, U+2028
		// and U+2029 (UTF-8 E2 80 A8 / E2 80 A9). Stripping the sourceURL line
		// up to LF only swallowed `\rfbq('init')` with the "comment" and the
		// tag was exempted as data while the browser ran the call. Matched
		// byte-wise, without /u, so invalid UTF-8 cannot null the pattern.
		$body = preg_replace( '#^[ \t]*//\#[ \t]*source(?:Mapping)?URL=(?:(?!\xE2\x80[\xA8\xA9])[^\r\n])*#m', '', $content );
		if ( null === $body ) {
			return false;
		}
		$body = str_replace( array( '/* <![CDATA[ */', '/* ]]> */' ), '', $body );
		$len  = strlen( $body );
		$pos  = 0;
		$seen = 0;
		while ( true ) {
			while ( $pos < $len && ctype_space( $body[ $pos ] ) ) {
				$pos++;
			}
			if ( $pos >= $len ) {
				return $seen > 0;
			}
			if ( ! preg_match( '/\G(?:(?:var|let|const)\s+|window\.)([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*/', $body, $m, 0, $pos ) ) {
				return false;
			}
			// `location` is a global whose assignment is an action, not a
			// definition: `var location = "https://…"` at top level and
			// `window.location = …` both go through the Location setter and
			// navigate the page, whatever JSON value is given (an object
			// navigates to its string form). Exempted, that navigation ran
			// before consent and outside every provider and custom rule, so it
			// is judged as code. The grammar above already refuses every other
			// spelling (`document.location`, `top.location`, `window["…"]`).
			// Event handler names need no entry: an IDL handler takes a
			// callable or null, and JSON can spell neither a function nor
			// source to compile.
			if ( 'location' === $m[1] ) {
				return false;
			}
			$pos += strlen( $m[0] );
			$end  = $this->json_value_end( $body, $pos );
			if ( false === $end ) {
				return false;
			}
			$value = substr( $body, $pos, $end - $pos );
			json_decode( $value );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return false;
			}
			$pos = $end;
			while ( $pos < $len && ctype_space( $body[ $pos ] ) && "\n" !== $body[ $pos ] ) {
				$pos++;
			}
			if ( $pos < $len && ';' === $body[ $pos ] ) {
				$pos++;
			} elseif ( $pos < $len && "\n" !== $body[ $pos ] ) {
				// Anything but a statement end after the value (`,`, an
				// operator, a call) means this is not a plain assignment.
				return false;
			}
			$seen++;
		}
	}

	/**
	 * Offset just past the JSON value that starts at $start, or false.
	 *
	 * A scanner, not a parser: it finds where the value ENDS (matching
	 * brackets outside strings, honouring escapes) so json_decode() can then
	 * judge exactly that slice. Scalars end at the first character that
	 * cannot continue them.
	 *
	 * @param string $s     Script body.
	 * @param int    $start Offset of the value's first character.
	 * @return int|false
	 */
	private function json_value_end( $s, $start ) {
		$len = strlen( $s );
		if ( $start >= $len ) {
			return false;
		}
		$first = $s[ $start ];
		if ( '{' !== $first && '[' !== $first && '"' !== $first ) {
			if ( ! preg_match( '/\G(?:-?[0-9][0-9.eE+-]*|true|false|null)/', $s, $m, 0, $start ) ) {
				return false;
			}
			return $start + strlen( $m[0] );
		}
		$depth     = 0;
		$in_string = false;
		for ( $i = $start; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( $in_string ) {
				if ( '\\' === $c ) {
					$i++;
				} elseif ( '"' === $c ) {
					$in_string = false;
					if ( 0 === $depth ) {
						return $i + 1;
					}
				}
				continue;
			}
			if ( '"' === $c ) {
				$in_string = true;
			} elseif ( '{' === $c || '[' === $c ) {
				$depth++;
			} elseif ( '}' === $c || ']' === $c ) {
				$depth--;
				if ( 0 === $depth ) {
					return $i + 1;
				}
				if ( $depth < 0 ) {
					return false;
				}
			}
		}
		return false;
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
	 * optimisation plugins preserve our loading order. Native defer is
	 * protected by the inline bootstrap; delaying until user interaction
	 * would prevent the runtime from taking over. The banner (and the
	 * `_fazCreateElementBackup` interceptor that blocks third-party
	 * trackers pre-consent) must run at page load, not at first user
	 * interaction — otherwise ads and analytics scripts released by the
	 * same interaction get to execute alongside the banner.
	 *
	 * The attributes below are recognised by the major plugins:
	 *   - `data-no-defer`        — LiteSpeed Cache, Hummingbird, SG Optimizer
	 *   - `data-noptimize`       — Autoptimize: the tag is neither merged, moved
	 *                              nor delayed. Breeze (its fork) only stops
	 *                              moving it; its merge ignores every exclusion
	 *                              (see readme.txt, Breeze)
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
		$hints = ' data-no-defer="1" data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-ao-skip="1" data-noptimize="1"';
		// `script_loader_tag` receives the *composite* markup for this
		// handle — the external `<script src>` PLUS any inline before/after
		// snippets registered via `wp_add_inline_script()` concatenated in
		// a single string. Tag every opening `<script>` in the blob
		// (excluding ones we have already neutralised to `text/plain` or
		// that already carry the hints from a prior filter pass).
		$new_tag = preg_replace_callback(
			'#<script(?=[\s/>])(' . self::ATTR_WALK . ')(/?)\s*+>#i',
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
			$attributes['data-noptimize']   = '1';
		}
		return $attributes;
	}

	/**
	 * Mark the non-blocking banner stylesheet so script.js can find it.
	 *
	 * script.js switches this link from media="print" to "all" and holds the
	 * banner reveal until it has loaded (_fazWhenBannerCssReady). The marker
	 * is an attribute rather than the generated id so it survives a renamed
	 * plugin handle and CDN URL rewriting alike.
	 *
	 * @param string $tag    Rendered stylesheet link.
	 * @param string $handle Registered WordPress style handle.
	 * @return string
	 */
	public function mark_banner_stylesheet( $tag, $handle ) {
		if ( ! $this->banner_css_non_blocking || $this->plugin_name . '-css' !== $handle || false !== strpos( $tag, 'data-faz-banner-css' ) ) {
			return $tag;
		}
		$tagged = preg_replace( '/<link\b/i', '<link data-faz-banner-css="1"', $tag, 1 );
		return is_string( $tagged ) ? $tagged : $tag;
	}

	/**
	 * Keep FAZ's compiled banner stylesheet out of CSS optimisation pipelines.
	 *
	 * LiteSpeed recognises `data-no-optimize="1"` on stylesheet links before it
	 * combines, minifies or asynchronously rewrites them. The second attribute
	 * covers optimisers that use the historical no-optimise spelling. Running at
	 * priority 20 leaves the consent-blocking style filter at priority 10 intact.
	 *
	 * @param string $tag    Rendered stylesheet link.
	 * @param string $handle Registered WordPress style handle.
	 * @return string
	 */
	public function tag_own_styles_nooptimize( $tag, $handle ) {
		if ( is_admin() || ! $this->is_own_script_handle( $handle ) || false !== strpos( $tag, 'data-no-optimize' ) ) {
			return $tag;
		}
		$tagged = preg_replace( '/<link\b/i', '<link data-no-optimize="1" data-noptimize="1"', $tag, 1 );
		return is_string( $tagged ) ? $tagged : $tag;
	}

	/**
	 * FlyingPress v4/v5 filter callback: add every FAZ asset marker without
	 * dropping or duplicating exclusions registered by the site or another
	 * integration.
	 *
	 * @param mixed $excludes Existing FlyingPress exclusion keywords.
	 * @return array
	 */
	public function flying_press_exclude_own_scripts( $excludes ) {
		$excludes = is_array( $excludes ) ? $excludes : array();
		foreach ( array( 'faz-cookie-manager', 'faz-fw' ) as $keyword ) {
			if ( ! in_array( $keyword, $excludes, true ) ) {
				$excludes[] = $keyword;
			}
		}
		return $excludes;
	}

	/**
	 * The FlyingPress 5 runtime-config lists FAZ adds itself to, and the
	 * keywords it adds to each one.
	 *
	 * Verified against FlyingPress 5.5.0 and 5.6.5 (same code in both):
	 *
	 *   - `js_delay_excludes` — JavaScript::delay_scripts(), the "Delay all
	 *     JavaScript" pass. Keywords are matched with stripos() against the
	 *     whole `<script>` tag, so `faz-cookie-manager` hits the id of every
	 *     FAZ tag (`faz-cookie-manager-js`, `-js-extra`, `-js-before`,
	 *     `-gcm-default-js-before`) and `faz-fw` the alternative-asset family.
	 *   - `js_delay_third_party_excludes` — JavaScript::delay_third_party_scripts(),
	 *     "Delay third-party scripts". It first keeps the tags that mention any
	 *     domain in the Cloud Optimizer's `external_domains`, and then honours
	 *     ONLY this list. A FAZ asset served from a CDN host (BunnyCDN on the
	 *     site that reported it) counts as third-party, and so does the inline
	 *     `_fazConfig`, whose default whitelist contains `www.google.com/recaptcha`:
	 *     without this list the banner waited for the first interaction, or
	 *     script.js ran with no `_fazConfig` and showed nothing at all.
	 *   - `css_rucss_include_selectors` — "Remove unused CSS". The dashboard
	 *     describes it as keywords that force matching selectors into the
	 *     generated used CSS, and the value travels to the Cloud Optimizer in
	 *     Config::safe_config(). The banner's states (`.faz-modal.faz-modal-open`,
	 *     `.faz-switch input:checked`, …) are not on screen when the page is
	 *     analysed, so they were dropped while CSS::load_used_css() turned the
	 *     banner stylesheet into a `data-href` link that loads only on
	 *     interaction. `faz-` is a substring of every FAZ selector, `#faz-consent`
	 *     and `[data-faz-tag]` included.
	 *
	 * "Delay selected scripts" (`js_delay_selected_includes`) has no exclusion
	 * list; flying_press_restore_own_assets() covers it.
	 *
	 * @return array<string, string[]>
	 */
	private function flying_press_runtime_keyword_map() {
		$script_keywords = array( 'faz-cookie-manager', 'faz-fw' );
		return array(
			'js_delay_excludes'             => $script_keywords,
			'js_delay_third_party_excludes' => $script_keywords,
			'css_rucss_include_selectors'   => array( 'faz-' ),
		);
	}

	/**
	 * Add FAZ to FlyingPress v5's runtime optimisation config.
	 *
	 * FlyingPress v5 no longer consumes the v4 delay/defer filters while
	 * optimizing HTML; it reads the lists in flying_press_runtime_keyword_map()
	 * straight from Config::$config. The helper only receives FlyingPress's
	 * already-loaded static value; FAZ deliberately does not filter or write the
	 * `FLYING_PRESS_CONFIG` option, so disabling FAZ restores the administrator's
	 * exact configuration. Empty/unmigrated or older config shapes are left
	 * untouched, and so is every list that is missing or not an array.
	 *
	 * @param mixed $config FlyingPress configuration option.
	 * @return mixed
	 */
	public function flying_press_add_delay_exclusions_to_config( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}
		foreach ( $this->flying_press_runtime_keyword_map() as $key => $keywords ) {
			// Require an ARRAY value, not just the key: a non-array list (null, or
			// a delimited string as some cache plugins use) would be coerced to
			// array() by the merge and silently drop the administrator's original
			// value. Leave any unexpected shape untouched.
			if ( ! isset( $config[ $key ] ) || ! is_array( $config[ $key ] ) ) {
				continue;
			}
			foreach ( $keywords as $keyword ) {
				if ( ! in_array( $keyword, $config[ $key ], true ) ) {
					$config[ $key ][] = $keyword;
				}
			}
		}
		return $config;
	}

	/**
	 * Patch FlyingPress v5's already-loaded config after all normal plugins have
	 * booted (covering either plugin load order) and after a settings update
	 * replaces Config::$config during the current request.
	 *
	 * Reflection keeps this compatibility bridge fail-closed if FlyingPress
	 * changes the property visibility or removes the current config shape: no
	 * fatal is allowed on a public request. No persistent option is modified.
	 *
	 * @return void
	 */
	public function flying_press_apply_runtime_delay_exclusions() {
		// Front-end page renders only. Frontend is also constructed on AJAX/REST
		// requests (Consent_Logger's REST routes live on this class), and this
		// method runs at plugins_loaded, i.e. BEFORE FlyingPress's own settings
		// save would run on such a request. Mutating the shared static
		// \FlyingPress\Config::$config there risks the injected keywords leaking
		// into the option FlyingPress persists — the opposite of the "never
		// overwrites the administrator's saved config" guarantee below. The
		// runtime exclusion is only needed while FlyingPress is serving/optimising
		// a public page anyway, so restrict the in-memory patch to those requests.
		// wp_doing_cron() is excluded explicitly: faz_is_front_end_request() does
		// NOT treat cron as non-frontend, and FlyingPress's own preload runs on
		// cron and reads Config::$config — mutating it there could leak the FAZ
		// keywords into the persisted option if a cron pass re-saves the config
		// (mirrors the wp_doing_cron() guard on flying_press_is_cacheable()).
		// WP-CLI is excluded for the same persistence reason: FlyingPress commands
		// such as license activation call Config::update_config() and save the whole
		// shared static config after plugins_loaded has fired.
		if ( ! faz_is_front_end_request() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! class_exists( '\\FlyingPress\\Config' ) || ! property_exists( '\\FlyingPress\\Config', 'config' ) ) {
			return;
		}
		try {
			$property = new \ReflectionProperty( '\\FlyingPress\\Config', 'config' );
			if ( ! $property->isStatic() ) {
				return;
			}
			if ( ! $property->isPublic() ) {
				$property->setAccessible( true );
			}
			$config = $property->getValue();
			if ( ! is_array( $config ) || ! array_key_exists( 'js_delay_excludes', $config ) ) {
				// The v5 config shape this bridge relies on has changed. Degrade
				// quietly (the consent banner may then be delayed by "Delay all
				// JavaScript"), but leave a WP_DEBUG breadcrumb so the regression
				// is diagnosable and the readme's manual-exclusion fallback applies.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'FAZ Cookie Manager: FlyingPress delay-exclusion bridge skipped — \\FlyingPress\\Config::$config has no js_delay_excludes key (FlyingPress internals changed). Add "faz-cookie-manager" to FlyingPress "Delay JavaScript" exclusions manually.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return;
			}
			$updated = $this->flying_press_add_delay_exclusions_to_config( $config );
			if ( $updated !== $config ) {
				$this->flying_press_record_injected_keywords( $config, $updated );
				$property->setValue( null, $updated );
			}
		} catch ( \Throwable $error ) {
			// Compatibility code must degrade to FlyingPress's original config.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'FAZ Cookie Manager: FlyingPress delay-exclusion bridge failed — ' . $error->getMessage() . '. Add "faz-cookie-manager" to FlyingPress "Delay JavaScript" exclusions manually.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			unset( $error );
		}
	}

	/**
	 * Keywords this request added to \FlyingPress\Config::$config, per list.
	 *
	 * @var array<string, string[]>
	 */
	private $flying_press_injected = array();

	/**
	 * Remember which keywords the runtime bridge added (and were not already
	 * there), so flying_press_strip_runtime_keywords() removes exactly those.
	 *
	 * @param array $before Config before the patch.
	 * @param array $after  Config after the patch.
	 * @return void
	 */
	private function flying_press_record_injected_keywords( array $before, array $after ) {
		foreach ( array_keys( $this->flying_press_runtime_keyword_map() ) as $key ) {
			if ( ! isset( $after[ $key ] ) || ! is_array( $after[ $key ] ) ) {
				continue;
			}
			$had   = isset( $before[ $key ] ) && is_array( $before[ $key ] ) ? $before[ $key ] : array();
			$added = array_values( array_diff( $after[ $key ], $had ) );
			if ( array() === $added ) {
				continue;
			}
			$known                               = isset( $this->flying_press_injected[ $key ] ) ? $this->flying_press_injected[ $key ] : array();
			$this->flying_press_injected[ $key ] = array_values( array_unique( array_merge( $known, $added ) ) );
		}
	}

	/**
	 * `pre_update_option_FLYING_PRESS_CONFIG`: keep the runtime keywords out of
	 * the saved option.
	 *
	 * FlyingPress saves its WHOLE static config whenever anything calls
	 * Config::update_config(): after migrate_config() on an upgrade request,
	 * Cloudflare::on_config_updated() re-saves cf_* fields from the
	 * `flying_press_update_config:after` action that runs on `init`, and the
	 * license and WP-CLI paths do the same. On a front-end request that ran
	 * after the bridge, that copy carries the FAZ keywords, and once saved
	 * they would outlive FAZ itself. Only keywords THIS request injected are
	 * removed, and only when the previously saved value did not already
	 * contain them, so an exclusion the administrator typed in the FlyingPress
	 * dashboard is never touched (that save is a REST request, on which the
	 * bridge injects nothing).
	 *
	 * @param mixed $value     Value about to be saved.
	 * @param mixed $old_value Value currently saved.
	 * @return mixed
	 */
	public function flying_press_strip_runtime_keywords( $value, $old_value = array() ) {
		if ( array() === $this->flying_press_injected || ! is_array( $value ) ) {
			return $value;
		}
		$old = is_array( $old_value ) ? $old_value : array();
		foreach ( $this->flying_press_injected as $key => $keywords ) {
			if ( ! isset( $value[ $key ] ) || ! is_array( $value[ $key ] ) ) {
				continue;
			}
			$saved = isset( $old[ $key ] ) && is_array( $old[ $key ] ) ? $old[ $key ] : array();
			$kept  = array();
			foreach ( $value[ $key ] as $entry ) {
				if ( in_array( $entry, $keywords, true ) && ! in_array( $entry, $saved, true ) ) {
					continue;
				}
				$kept[] = $entry;
			}
			if ( count( $kept ) !== count( $value[ $key ] ) ) {
				$value[ $key ] = $kept;
			}
		}
		return $value;
	}

	/**
	 * `flying_press_optimization:after` safety net: undo any FlyingPress rewrite
	 * that still reached a FAZ tag.
	 *
	 * The exclusion lists above cover the passes that have one. "Delay selected
	 * scripts" has none (an include keyword such as `gtag` or `recaptcha` that
	 * happens to match the GCM default or the `_fazConfig` whitelist delays
	 * it), "Remove unused CSS" rewrites every stylesheet link, and a future
	 * FlyingPress release may add a pass or rename a list. The filter runs on
	 * the finished HTML right before FlyingPress writes it to its page cache,
	 * so whatever is restored here is also what the cache serves.
	 *
	 * Only tags whose id belongs to FAZ are touched, and only when they carry
	 * FlyingPress's own markers:
	 *   - `<script data-loading-method=… data-src=…>` → `src` restored, the
	 *     markers removed; a `data:text/javascript,` source (how FlyingPress
	 *     carries an inline script) becomes the inline body again;
	 *   - `<script defer src="data:text/javascript,…">` (the "defer" delay
	 *     method on an inline script) → inline again;
	 *   - a `type` that is not a script type (an optimiser's parking type)
	 *     on such a tag is dropped, which means text/javascript;
	 *   - `<link data-href=…>` without `href` → `href` restored.
	 *
	 * @param mixed $html Optimised page HTML.
	 * @return mixed
	 */
	public function flying_press_restore_own_assets( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, 'faz-' ) ) {
			return $html;
		}
		$restored = 0;

		$scripts = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $match ) use ( &$restored ) {
				$result = $this->flying_press_restore_script_tag( $match[0], '<script' . $match[1] . '>' );
				if ( $result !== $match[0] ) {
					++$restored;
				}
				return $result;
			},
			$html
		);
		if ( is_string( $scripts ) ) {
			$html = $scripts;
		}

		$links = preg_replace_callback(
			'#<link\b[^>]*>#i',
			function ( $match ) use ( &$restored ) {
				$result = $this->flying_press_restore_link_tag( $match[0] );
				if ( $result !== $match[0] ) {
					++$restored;
				}
				return $result;
			},
			$html
		);
		if ( is_string( $links ) ) {
			$html = $links;
		}

		if ( $restored > 0 ) {
			$this->flying_press_note_restored_assets( $restored );
		}
		return $html;
	}

	/**
	 * Restore one `<script>` tag (see flying_press_restore_own_assets()).
	 *
	 * @param string $tag      Complete tag, `<script …>…</script>`.
	 * @param string $open_tag Its opening tag.
	 * @return string The restored tag, or $tag unchanged.
	 */
	private function flying_press_restore_script_tag( $tag, $open_tag ) {
		$id = self::flying_press_tag_attribute( $open_tag, 'id' );
		if ( null === $id || ! ( $this->is_own_asset_tag_id( $id, '-js' ) || $this->is_own_inline_script_id( $id ) ) ) {
			return $tag;
		}
		$data_src = self::flying_press_tag_attribute( $open_tag, 'data-src' );
		$src      = self::flying_press_tag_attribute( $open_tag, 'src' );
		$delayed  = null !== self::flying_press_tag_attribute( $open_tag, 'data-loading-method' ) || null !== $data_src;
		$inlined  = is_string( $src ) && 0 === stripos( $src, 'data:text/javascript,' );
		if ( ! $delayed && ! $inlined ) {
			return $tag;
		}
		$target = null !== $data_src ? $data_src : $src;
		if ( ! is_string( $target ) || '' === $target ) {
			return $tag;
		}

		$open = self::flying_press_tag_remove_attribute( $open_tag, 'data-loading-method' );
		$open = self::flying_press_tag_remove_attribute( $open, 'data-src' );
		$open = self::flying_press_tag_remove_attribute( $open, 'src' );
		$type = self::flying_press_tag_attribute( $open, 'type' );
		if ( null !== $type && ! in_array( strtolower( trim( $type ) ), array( '', 'text/javascript', 'application/javascript', 'module' ), true ) ) {
			$open = self::flying_press_tag_remove_attribute( $open, 'type' );
		}

		if ( 0 === stripos( $target, 'data:text/javascript,' ) ) {
			$code = rawurldecode( substr( $target, strlen( 'data:text/javascript,' ) ) );
			// The body came from an inline script, so it cannot hold a closing
			// tag; one that does is not FAZ's and is left to FlyingPress.
			if ( false !== stripos( $code, '</script' ) ) {
				return $tag;
			}
			// defer/async mean nothing on an inline script; FlyingPress added them.
			$open = self::flying_press_tag_remove_attribute( $open, 'defer' );
			$open = self::flying_press_tag_remove_attribute( $open, 'async' );
			return $open . $code . '</script>';
		}
		return self::flying_press_tag_set_attribute( $open, 'src', $target ) . '</script>';
	}

	/**
	 * Restore one `<link>` tag whose `href` FlyingPress parked in `data-href`.
	 *
	 * @param string $tag `<link …>` tag.
	 * @return string
	 */
	private function flying_press_restore_link_tag( $tag ) {
		$id = self::flying_press_tag_attribute( $tag, 'id' );
		if ( null === $id || ! $this->is_own_asset_tag_id( $id, '-css' ) ) {
			return $tag;
		}
		$data_href = self::flying_press_tag_attribute( $tag, 'data-href' );
		if ( null === $data_href || '' === $data_href || null !== self::flying_press_tag_attribute( $tag, 'href' ) ) {
			return $tag;
		}
		$open = self::flying_press_tag_remove_attribute( $tag, 'data-href' );
		return self::flying_press_tag_set_attribute( $open, 'href', $data_href );
	}

	/**
	 * Leave one diagnostic per day when the safety net had to act: it means
	 * an optimiser pass is configured to delay FAZ, and the administrator can
	 * stop the rewrite at the source.
	 *
	 * @param int $count Number of tags restored on this render.
	 * @return void
	 */
	private function flying_press_note_restored_assets( $count ) {
		$key = 'faz_flying_press_restored_assets';
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient(
			$key,
			array(
				'count' => (int) $count,
				'time'  => time(),
			),
			defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400
		);
		error_log( 'FAZ Cookie Manager: FlyingPress rewrote ' . (int) $count . ' FAZ asset(s) for delayed loading ("Delay selected scripts", "Delay third-party scripts" or "Remove unused CSS"); FAZ restored them so the consent banner loads immediately. Add "faz-cookie-manager" to the FlyingPress exclusions and remove any "Delay selected" keyword that matches FAZ to stop the rewrite. Logged at most once a day.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Read one attribute of an HTML start tag.
	 *
	 * @param string $tag  Start tag (or a complete element; only its first tag is read).
	 * @param string $name Attribute name.
	 * @return string|null The raw (still HTML-encoded) value, '' for a bare
	 *                     boolean attribute, null when absent.
	 */
	private static function flying_press_tag_attribute( $tag, $name ) {
		if ( ! preg_match( '/^<[^>]*>/', $tag, $start ) ) {
			return null;
		}
		$pattern = '/\s' . preg_quote( $name, '/' ) . '(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?(?=[\s>\/])/i';
		if ( ! preg_match( $pattern, $start[0], $m ) ) {
			return null;
		}
		foreach ( array( 1, 2, 3 ) as $group ) {
			if ( isset( $m[ $group ] ) && '' !== $m[ $group ] ) {
				return $m[ $group ];
			}
		}
		return '';
	}

	/**
	 * Remove every occurrence of one attribute from an HTML start tag.
	 *
	 * @param string $tag  Start tag.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function flying_press_tag_remove_attribute( $tag, $name ) {
		$pattern = '/\s' . preg_quote( $name, '/' ) . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?(?=[\s>\/])/i';
		$result  = preg_replace( $pattern, '', $tag );
		return is_string( $result ) ? $result : $tag;
	}

	/**
	 * Append an attribute to an HTML start tag, keeping a raw value raw.
	 *
	 * @param string $tag   Start tag without the attribute.
	 * @param string $name  Attribute name.
	 * @param string $value Raw (already HTML-encoded) value.
	 * @return string
	 */
	private static function flying_press_tag_set_attribute( $tag, $name, $value ) {
		$quote     = false === strpos( $value, '"' ) ? '"' : "'";
		$attribute = ' ' . $name . '=' . $quote . $value . $quote;
		$result    = preg_replace_callback(
			'/\s*(\/?)>$/',
			static function ( $match ) use ( $attribute ) {
				return $attribute . $match[1] . '>';
			},
			$tag,
			1
		);
		return is_string( $result ) ? $result : $tag;
	}

	/**
	 * LiteSpeed Cache filter callback — add our plugin's path fragment
	 * to whatever JS or CSS exclude list LiteSpeed is assembling. Pattern-
	 * matched against asset URLs, so `plugins/faz-cookie-manager/` matches
	 * every bundled asset and `faz-cookie-manager/assets/` matches the generated
	 * config and banner stylesheet. Used as a fallback in case the tag-level
	 * opt-out attributes ever stop being honoured by a future LiteSpeed release.
	 *
	 * @param mixed $excludes Pattern list (array or newline-joined string).
	 * @return mixed
	 */
	public function litespeed_exclude_own_scripts( $excludes ) {
		// Two fragments, because the plugin's assets no longer all live under
		// plugins/: the content-hashed banner CSS and config JS are served from
		// the uploads directory. The path-based exclusion is the fallback for
		// when the data-no-optimize / data-no-defer attributes are stripped, and
		// it stopped covering those two files when they moved. Matched on
		// `faz-cookie-manager/assets/` rather than a full uploads path so a site
		// with a renamed uploads directory is still covered.
		$patterns = array( 'plugins/faz-cookie-manager/', 'faz-cookie-manager/assets/' );
		if ( is_string( $excludes ) ) {
			foreach ( $patterns as $pattern ) {
				if ( false === strpos( $excludes, $pattern ) ) {
					$excludes = trim( $excludes . "\n" . $pattern );
				}
			}
			return $excludes;
		}
		if ( ! is_array( $excludes ) ) {
			$excludes = array();
		}
		foreach ( $patterns as $pattern ) {
			if ( ! in_array( $pattern, $excludes, true ) ) {
				$excludes[] = $pattern;
			}
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
		// Second pattern for the content-hashed assets now served from uploads —
		// see litespeed_exclude_own_scripts() for why the plugin-directory
		// pattern alone no longer covers them.
		$patterns = array(
			'/wp-content/plugins/faz-cookie-manager/(.*)',
			'faz-cookie-manager/assets/(.*)',
		);
		foreach ( $patterns as $pattern ) {
			if ( ! in_array( $pattern, $excludes, true ) ) {
				$excludes[] = $pattern;
			}
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
		// _fazConsentLog marks both the logger's localised data and its
		// listener: delayed, the listener is not yet attached when the visitor
		// makes the first choice, and that decision never reaches the log.
		foreach ( array( '_fazConfig', '_fazCfg', '_fazGcm', '_fazTcfConfig', '_fazStaticConfig', '_fazConsentLog' ) as $needle ) {
			if ( ! in_array( $needle, $excluded, true ) ) {
				$excluded[] = $needle;
			}
		}
		return $excluded;
	}

	/**
	 * W3 Total Cache auto-minify: keep every FAZ tag out of its bundles.
	 *
	 * `$tag` is the full `<script …>` / `<link …>` markup and `$file` the URL
	 * W3TC resolved for it. A FAZ tag is recognised by the hints every own
	 * handle carries (tag_own_scripts_nooptimize(), filter_style_loader_tag())
	 * or, for a tag printed by another path, by its own asset URL.
	 *
	 * @param bool   $do   Whether W3TC may minify/combine this tag.
	 * @param string $tag  The tag markup.
	 * @param string $file The resolved file URL.
	 * @return bool
	 */
	public function w3tc_keep_own_tag( $do, $tag = '', $file = '' ) {
		if ( ! $do ) {
			return $do;
		}
		$tag = is_string( $tag ) ? $tag : '';
		if ( false !== strpos( $tag, 'data-no-minify' ) || false !== strpos( $tag, 'data-no-optimize' ) ) {
			return false;
		}
		foreach ( array( 'faz-cookie-manager', 'faz-fw', 'faz-dsar-form', 'faz-dnsmpi-form' ) as $keyword ) {
			if ( false !== strpos( $tag, $keyword ) ) {
				return false;
			}
		}
		if ( is_string( $file ) && '' !== $file && $this->is_own_asset_url( $file ) ) {
			return false;
		}
		return $do;
	}

	/**
	 * SiteGround Optimizer: own handles for its minify / async / combine
	 * exclusion lists (compared with in_array() against the handle).
	 *
	 * @param array $handles Handles SiteGround Optimizer already excludes.
	 * @return array
	 */
	public function sgo_exclude_own_handles( $handles ) {
		if ( ! is_array( $handles ) ) {
			return $handles;
		}
		// Every handle enqueue_scripts() registers, under both base names
		// (the default and the alternative-asset family), plus the shortcode
		// handles. See is_own_script_handle() for the prefix contract.
		$own = array( 'faz-dsar-form', 'faz-dnsmpi-form', 'faz-dsar' );
		foreach ( array( (string) $this->plugin_name, 'faz-fw' ) as $base ) {
			if ( '' === $base ) {
				continue;
			}
			$own[] = $base;
			// The non-blocking stylesheet also registers a separate inline
			// -css-page handle for page content and pending-banner rules.
			foreach ( array( '-static-config', '-css', '-css-page', '-gcm', '-tcf-cmp', '-a11y', '-wca', '-microsoft-consent' ) as $suffix ) {
				$own[] = $base . $suffix;
			}
		}
		// The combinator compares the printed tag's id attribute, which carries
		// WordPress's `-js` / `-css` suffix (and `-js-before` / `-js-extra` on
		// the inline parts); the minify and async passes compare the handle.
		foreach ( $own as $handle ) {
			foreach ( array( '-js', '-js-before', '-js-after', '-js-extra', '-js-translations', '-css' ) as $suffix ) {
				$own[] = $handle . $suffix;
			}
		}
		foreach ( $own as $handle ) {
			if ( ! in_array( $handle, $handles, true ) ) {
				$handles[] = $handle;
			}
		}
		return $handles;
	}

	/**
	 * Super Page Cache: bypass its cache (and keep its Cloudflare Cache-Control
	 * off the response) on every response FAZ vetoed.
	 *
	 * @param bool $bypass Whether Super Page Cache already bypasses this response.
	 * @return bool
	 */
	public function super_page_cache_bypass( $bypass ) {
		if ( $bypass ) {
			return $bypass;
		}
		return defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE;
	}

	/**
	 * SiteGround Optimizer "Remove Query Strings": keep `?ver=` on FAZ assets
	 * (compared as URL substrings).
	 *
	 * @param array $urls URL fragments SiteGround Optimizer already excludes.
	 * @return array
	 */
	public function sgo_exclude_own_urls( $urls ) {
		if ( ! is_array( $urls ) ) {
			return $urls;
		}
		foreach ( array( 'faz-cookie-manager', 'faz-fw' ) as $keyword ) {
			if ( ! in_array( $keyword, $urls, true ) ) {
				$urls[] = $keyword;
			}
		}
		return $urls;
	}

	/**
	 * Hummingbird Asset Optimization: never minify or combine an own handle.
	 *
	 * @param bool   $allowed Whether Hummingbird may process the resource.
	 * @param string $handle  Script or style handle.
	 * @return bool
	 */
	public function hummingbird_keep_own_resource( $allowed, $handle = '' ) {
		if ( $allowed && ( 'faz-dsar' === $handle || $this->is_own_script_handle( $handle ) ) ) {
			return false;
		}
		return $allowed;
	}

	/**
	 * Jetpack Boost "Defer non-essential JavaScript": keep FAZ tags where
	 * they are. A moved tag would run after every third-party script that
	 * was not moved, and the inline bootstrap would guard nothing.
	 *
	 * Boost builds the list with preg_match_all( …, PREG_OFFSET_CAPTURE ), so
	 * each element is `array( markup, byte offset )`
	 * (class-render-blocking-js.php, get_script_tags()); a plain string is
	 * accepted as well in case that ever changes. An element of any other
	 * shape is left to Boost.
	 *
	 * @param array $script_tags Script tags Boost is about to move to the end of the document.
	 * @return array The same list without FAZ's own tags.
	 */
	public function jetpack_boost_keep_own_scripts( $script_tags ) {
		if ( ! is_array( $script_tags ) ) {
			return $script_tags;
		}
		return array_values(
			array_filter(
				$script_tags,
				function ( $entry ) {
					$tag = $entry;
					if ( is_array( $entry ) ) {
						$tag = isset( $entry[0] ) ? $entry[0] : '';
					}
					if ( ! is_string( $tag ) || '' === $tag ) {
						return true;
					}
					if ( false !== strpos( $tag, 'data-no-defer' ) ) {
						return false;
					}
					foreach ( array( 'faz-cookie-manager', 'faz-fw', 'faz-dsar-form', 'faz-dnsmpi-form', '_fazBootstrap', '_fazConfig', '_fazCfg', '_fazGcm', '_fazTcfConfig', '_fazConsentLog' ) as $keyword ) {
						if ( false !== strpos( $tag, $keyword ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Jetpack Boost (and WordPress.com page-optimize) concatenation: never
	 * fold an own handle into a combined file.
	 *
	 * @param bool   $do_concat Whether the handle may be concatenated.
	 * @param string $handle    Script or style handle.
	 * @return bool
	 */
	public function jetpack_boost_do_concat( $do_concat, $handle = '' ) {
		// 'faz-dsar' is the data request form's stylesheet handle; the script
		// handles all carry a prefix is_own_script_handle() knows.
		if ( $do_concat && ( 'faz-dsar' === $handle || $this->is_own_script_handle( $handle ) ) ) {
			return false;
		}
		return $do_concat;
	}

	/**
	 * WP-Optimize minify: keep FAZ assets out of its merged bundles.
	 *
	 * The list is matched as URL substrings against every script and
	 * stylesheet WP-Optimize considers; a match is printed untouched, with its
	 * inline before/after data in place.
	 *
	 * @param array $exclusions WP-Optimize's default exclusion list.
	 * @return array
	 */
	public function wpo_exclude_own_scripts( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			return $exclusions;
		}
		foreach ( array( 'faz-cookie-manager', 'faz-fw' ) as $keyword ) {
			if ( ! in_array( $keyword, $exclusions, true ) ) {
				$exclusions[] = $keyword;
			}
		}
		return $exclusions;
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
		if ( $this->is_strictly_necessary_script( $handle, $this->extract_tag_attr( $tag, 'id' ) ) ) {
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
		$blocked = $this->get_blocked_categories();

		// Several patterns can match one script, and the order of this map is
		// an accident of how it is assembled (cookie DB, then the provider
		// database, then admin rules, then a filter) — not a policy. Stopping
		// at the FIRST match therefore let the assembly order decide whether a
		// tracker loads before consent: a generic pattern in a category the
		// visitor allows won over a specific pattern that classifies the same
		// script as blocked, and the script ran while the banner promised it
		// would not.
		//
		// Fail closed instead: a matching pattern in a BLOCKED category decides
		// immediately, an allowed one is only remembered in case nothing
		// blocked matches. Whitelisting and per-service consent remain the ways
		// to let a matched script through, both of which are explicit.
		$matched_category = false;
		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			// Match against the handle and script src only. Matching the full tag
			// can false-positive on inline data snippets or unrelated attributes.
			if ( false === stripos( $handle, $pattern ) && false === stripos( $tag_src, $pattern ) ) {
				continue;
			}
			if ( in_array( $category, $blocked, true ) ) {
				$matched_category = $category;
				break;
			}
			if ( false === $matched_category ) {
				$matched_category = $category;
			}
		}

		if ( false === $matched_category ) {
			return $tag;
		}

		$should_block = in_array( $matched_category, $blocked, true );

		// Per-service consent override.
		$svc_blocked = $this->check_per_service_blocking( $tag, '' );
		if ( false === $svc_blocked ) {
			$should_block = false; // Service explicitly allowed.
		} elseif ( true === $svc_blocked ) {
			$should_block = true;  // Service explicitly blocked.
		}

		if ( $should_block ) {
			$tag = $this->block_script_tag_safely( $tag, $matched_category, 'script handle ' . $handle );
		}

		return $tag;
	}

	/**
	 * Gate inline scripts through the core WordPress attributes filter.
	 *
	 * Preserve nonce, id and all unrelated attributes; the tag is built by core
	 * after this callback, so raw inline content need not be parsed as HTML.
	 *
	 * @param array  $attributes Script attributes supplied by WordPress.
	 * @param string $data       Raw inline script content.
	 * @return array
	 */
	public function filter_inline_script_attributes( $attributes, $data ) {
		$type = strtolower( trim( (string) ( $attributes['type'] ?? '' ) ) );
		// Data blocks and optimiser placeholders are not executable JS.
		if ( '' !== $type && 'module' !== $type && ! preg_match( '#^(?:(?:text|application)/(?:x-)?(?:java|ecma)script|text/(?:javascript1\.[0-5]|jscript|livescript))$#', $type ) ) {
			return $attributes;
		}
		$id = (string) ( $attributes['id'] ?? '' );
		$handle = preg_replace( '/-js-(?:before|after|extra|translations)$/', '', $id );
		$attrs = '';
		foreach ( $attributes as $name => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			$attrs .= ' ' . esc_attr( $name ) . '="' . esc_attr( true === $value ? '' : $value ) . '"';
		}
		$category = $this->inline_script_block_category( $attrs, $data, $id, (string) $handle );
		if ( false === $category ) {
			return $attributes;
		}
		if ( '' !== $type && 'text/javascript' !== $type && ! isset( $attributes['data-faz-original-type'] ) ) {
			$attributes['data-faz-original-type'] = $attributes['type'];
		}
		$attributes['type'] = 'text/plain';
		$attributes['data-faz-category'] = $category;
		return $attributes;
	}

	/**
	 * Compatibility wrapper for callers supplying a complete inline tag.
	 *
	 * @param string $tag    Inline script markup.
	 * @param string $id     Script element ID.
	 * @param string $handle Optional enqueue handle.
	 * @return string
	 */
	public function filter_inline_script_tag( $tag, $id, $handle = '' ) {
		if ( ! preg_match( '#<script(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>(.*)</script(?=[\s/>])[^>]*>#si', $tag, $match ) ) {
			return $tag;
		}
		$category = $this->inline_script_block_category( $match[1], $match[2], $id, $handle );
		return false === $category ? $tag : $this->block_script_tag_safely( $tag, $category, 'inline script handle ' . $handle );
	}

	/**
	 * Resolve all matching inline patterns before applying service overrides.
	 *
	 * @param string $attrs   Serialized attributes for whitelist/service matching.
	 * @param string $content Raw JavaScript content.
	 * @param string $id      Script ID.
	 * @param string $handle  Enqueue handle inferred from the ID when available.
	 * @return string|false Category to block, or false when allowed.
	 */
	private function inline_script_block_category( $attrs, $content, $id, $handle ) {
		if ( is_admin() ) {
			return false;
		}
		if ( ! $this->template ) {
			return false;
		}
		if ( true === faz_disable_banner() || $this->is_banner_disabled_by_settings() || $this->is_blocking_disabled_for_page() ) {
			return false;
		}
		if ( $this->is_strictly_necessary_script( $handle, $id ) ) {
			return false;
		}
		// Guard: never block FAZ's own localize/translation/config inline scripts.
		// wp_localize_script() emits a <script id="faz-cookie-manager-js-extra"> tag
		// whose content includes category slugs like "analytics" — which would otherwise
		// be matched by the provider pattern and blocked, preventing window._fazConfig
		// from being defined and crashing the entire banner.
		// Check both the inferred enqueue handle and the rendered script ID.
		if ( $this->is_own_script_handle( $handle ) || $this->is_own_inline_script_id( $id ) ) {
			return false;
		}
		// wp_localize_script / wp_set_script_translations payloads carry only
		// config data or translation strings — never executable tracker code.
		// See is_wp_localize_or_translations_inline_id() for the full rationale.
		if ( $this->is_wp_localize_or_translations_inline_id( $id ) || ( ! $this->has_attr_token( $attrs, 'src' ) && $this->is_json_data_assignment( $content ) ) ) {
			return false;
		}

		// Never block our own inline scripts (match on attributes + handle only).
		if ( $this->is_whitelisted( $attrs . ' ' . $handle . ' ' . $id, '' ) ) {
			return false;
		}
		// Skip if already blocked by another mechanism.
		if ( false !== strpos( $attrs, 'data-faz-category' ) ) {
			return false;
		}
		// A body made only of JSON assignments defines data and sends nothing,
		// the same rule process_script_tag() applies (#322): its TEXT is never
		// evidence, so code signatures are not looked for in it. The script's
		// identity still is — a -js-before/-after attached to a tracker handle
		// follows the tracker whatever its body holds. Query Monitor 4.x prints
		// its payload through wp_print_inline_script_tag(), so this filter
		// judges it before the output buffer does; without the rule here the
		// two layers disagreed and QueryMonitorData was retyped.
		$body_is_data = $this->is_inline_json_data_assignment( (string) $content );
		$body         = $body_is_data ? '' : (string) $content;

		$providers = $this->get_provider_category_map();
		if ( empty( $providers ) ) {
			return false;
		}

		$blocked = $this->get_blocked_categories();
		$matched_category = false;
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
				$matched = '' !== $body && false !== stripos( $body, $pattern );
			}
			if ( ! $matched ) {
				continue;
			}

			if ( false === $matched_category || in_array( $category, $blocked, true ) ) {
				$matched_category = $category;
			}
			if ( in_array( $category, $blocked, true ) ) {
				break;
			}
		}
		if ( false === $matched_category ) {
			return false;
		}
		$should_block = in_array( $matched_category, $blocked, true );
		$svc_blocked = $this->check_per_service_blocking( $attrs, $body );
		if ( false === $svc_blocked ) {
			$should_block = false;
		} elseif ( true === $svc_blocked ) {
			$should_block = true;
		}
		// Match the output-buffer exemption for Advanced Consent Mode (same
		// emitted-default condition, see gcm_advanced_exemption_active()), but
		// an explicit service denial still wins over a cookieless bootstrap.
		if ( true !== $svc_blocked && $this->gcm_advanced_exemption_active()
			&& $this->is_gcm_managed_script( $attrs, $content ) ) {
			return false;
		}
		return $should_block ? $matched_category : false;
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

		$blocked = $this->get_blocked_categories();
		$matched_category = false;
		foreach ( $providers as $pattern => $category ) {
			if ( empty( $pattern ) || ( false === stripos( $handle, $pattern ) && false === stripos( $href, $pattern ) ) ) {
				continue;
			}
			if ( false === $matched_category || in_array( $category, $blocked, true ) ) {
				$matched_category = $category;
			}
			if ( in_array( $category, $blocked, true ) ) {
				break;
			}
		}
		if ( false === $matched_category ) {
			return $tag;
		}
		$should_block = in_array( $matched_category, $blocked, true );
		$svc_blocked = $this->check_per_service_blocking( $tag, '' );
		if ( false === $svc_blocked ) {
			$should_block = false;
		} elseif ( true === $svc_blocked ) {
			$should_block = true;
		}
		return $should_block ? self::block_link_tag_without_regex( $tag, $matched_category ) : $tag;
	}

	/**
	 * Emit `Vary: Accept-Language` only when an integrator asks for it.
	 *
	 * The HTML does not depend on Accept-Language. faz_current_language() never
	 * reads the header (issue #67: the server renders the site default, or the
	 * URL language of a multilingual plugin), and the browser-preferred
	 * language is resolved in script.js from navigator.languages, which swaps
	 * the banner through the REST endpoint. faz_detect_browser_language() is
	 * not called on any render path. The header therefore described a variance
	 * that does not exist, and its only effect was to split every cached URL
	 * into one entry per Accept-Language spelling (hundreds of distinct values
	 * in real traffic) on the caches that honour Vary — Cloudflare, Varnish,
	 * LiteSpeed — a lower hit rate for an identical document.
	 *
	 * Kept behind `faz_send_vary_header` (now default false) for a site whose
	 * own theme or plugins do vary the page by Accept-Language and relied on
	 * FAZ to announce it.
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
		 * Whether to announce `Vary: Accept-Language`.
		 *
		 * @since 1.34.2 Defaults to false: FAZ's HTML does not vary by it.
		 * @param bool $enabled Whether to emit the header. Defaults to false.
		 */
		if ( true !== apply_filters( 'faz_send_vary_header', false ) ) {
			return;
		}

		// Preserve any previously set Vary tokens (e.g. Cookie added elsewhere).
		header( 'Vary: Accept-Language', false );
	}

	/**
	 * Mark FAZ's versioned asset URLs as exempt from LiteSpeed's query-string
	 * removal.
	 *
	 * LiteSpeed Cache's "Remove Query Strings" drops everything after `?` from
	 * internal `.js?` / `.css?` URLs (Optimize::remove_query_strings()), which
	 * is the `?ver=` that changes on every FAZ release. The URL of script.min.js
	 * then stays identical across updates, and the browser, the CDN and
	 * QUIC.cloud keep serving the previous runtime against the new markup until
	 * their TTL ends — a consent runtime one version behind the server that
	 * renders its config. LiteSpeed skips any URL containing
	 * `_litespeed_rm_qs=0`.
	 *
	 * Added unconditionally rather than only when LSCWP_V is defined: the
	 * parameter is inert everywhere else (a static file ignores its query
	 * string), the markup stays the same whether or not LiteSpeed is active —
	 * so a page cached before LiteSpeed was switched on is not a different
	 * document — and nothing depends on the order in which LiteSpeed is
	 * loaded. Only URLs that already carry a query string are touched; the
	 * content-hashed config-<md5>.js and banner-<md5>.css have none and carry
	 * their version in the file name.
	 *
	 * @param string $src    Asset URL.
	 * @param string $handle Registered handle.
	 * @return string
	 */
	public function keep_own_asset_version_query( $src, $handle = '' ) {
		if ( ! is_string( $src ) || '' === $src || is_admin() ) {
			return $src;
		}
		if ( false === strpos( $src, '?' ) || false !== strpos( $src, '_litespeed_rm_qs=' ) ) {
			return $src;
		}
		if ( ! $this->is_own_script_handle( (string) $handle ) && ! $this->is_own_asset_url( $src ) ) {
			return $src;
		}
		return $src . '&_litespeed_rm_qs=0';
	}

	/**
	 * Whether a URL points inside this plugin's directory (scheme-insensitive:
	 * a TLS-terminating proxy can make the two spellings differ).
	 *
	 * Covers own stylesheets enqueued under handles without the plugin prefix
	 * (faz-legal-links, faz-cookie-table-shortcode).
	 *
	 * @param string $url Asset URL.
	 * @return bool
	 */
	private function is_own_asset_url( $url ) {
		if ( ! defined( 'FAZ_PLUGIN_URL' ) || '' === (string) FAZ_PLUGIN_URL ) {
			return false;
		}
		$base = preg_replace( '#^https?:#i', '', (string) FAZ_PLUGIN_URL );
		$url  = preg_replace( '#^https?:#i', '', (string) $url );
		return '' !== $base && 0 === strpos( $url, $base );
	}

	/**
	 * Open the opt-in output buffer that guards outgoing Set-Cookie headers.
	 *
	 * @return void
	 */
	public function start_server_cookie_guard() {
		if ( $this->server_cookie_guard_started || ! $this->server_cookie_guard_enabled() || headers_sent() ) {
			return;
		}
		$this->server_cookie_guard_started = true;
		ob_start( array( $this, 'filter_outgoing_cookies' ) );
	}

	/**
	 * Whether the destructive server-side layers may run at all on this request.
	 *
	 * Everything below the opt-in is a SAFETY standdown: conditions under which
	 * enforcement must not happen no matter which layer is asking. Split out so
	 * the pre-existing shredder can consult the standdowns WITHOUT inheriting
	 * the opt-in that gates the new outgoing-header guard — see
	 * cookie_shredder_enabled().
	 *
	 * @return bool
	 */
	private function server_cookie_enforcement_permitted() {
		if ( wp_doing_cron() || ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
			return false;
		}
		if ( true === faz_disable_banner() || $this->is_blocking_disabled_for_page() ) {
			return false;
		}
		// Enforcement must not outlive the banner. Blocking a script is
		// reversible client-side; a Set-Cookie header that was never sent is
		// gone for that response, and on a page where no banner is shown there
		// is no script.js and no way for that visitor to ever record consent —
		// so the stripping would be permanent with no remedy. Every destructive
		// server-side layer — the output buffer, the REST/redirect transport
		// boundaries and shred_non_consented_cookies() — asks this one function,
		// so they cannot drift apart on it.
		//
		// Cache Compatibility Mode is excluded for the same reason: there
		// get_blocked_categories() returns every non-necessary slug
		// unconditionally, without ever reading the consent cookie, which is
		// safe only for enforcement the client can undo. The jurisdiction shell
		// forces the same all-blocked list (see get_blocked_categories), so the
		// identical standdown applies — otherwise the shredder would destroy a
		// CONSENTED visitor's cookies on a cache miss.
		if ( $this->is_cache_compatibility_enabled() || $this->is_geo_bootstrap_cache_active() || ! $this->server_cookie_guard_has_consent_context() ) {
			return false;
		}
		// A valid authenticated scanner marker is the only bypass for AJAX/REST
		// subrequests. It lets the scanner observe the exact header that a visitor
		// would receive; a forged cookie alone is insufficient because the token
		// must resolve to the current user's live transient.
		if ( class_exists( '\FazCookie\Admin\Modules\Scanner\Includes\Controller' )
			&& \FazCookie\Admin\Modules\Scanner\Includes\Controller::is_browser_scan_request()
		) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the NEW outgoing Set-Cookie guard may run.
	 *
	 * Opt-in, because stripping a Set-Cookie header a third-party plugin just
	 * emitted is a capability this plugin did not previously have: it must be a
	 * decision an operator made, not something an upgrade turns on underneath
	 * them.
	 *
	 * @return bool
	 */
	private function server_cookie_guard_enabled() {
		$settings = $this->get_faz_settings();
		if ( empty( $settings['script_blocking']['block_server_cookies'] ) ) {
			return false;
		}
		return $this->server_cookie_enforcement_permitted();
	}

	/**
	 * Whether the pre-existing cookie shredder may run.
	 *
	 * Deliberately NOT gated on script_blocking.block_server_cookies. That
	 * setting is new in this release and defaults to false; routing the
	 * shredder through it turned a behaviour that had run on every front-end
	 * render since the feature shipped into one every existing install silently
	 * lost on upgrade, with no migration and no notice. An opt-in protects
	 * operators from a NEW capability being switched on under them; it must not
	 * switch an established one off.
	 *
	 * The safety standdowns still apply in full — the shredder is destructive
	 * and irreversible in exactly the same way, so it stands down wherever the
	 * visitor has no way to consent (no banner, geo no-banner routing, excluded
	 * page) and under Cache Compatibility Mode, where get_blocked_categories()
	 * answers without ever reading the consent cookie.
	 *
	 * @return bool
	 */
	private function cookie_shredder_enabled() {
		return $this->server_cookie_enforcement_permitted();
	}

	/**
	 * Whether a consent context exists for the visitor the guard would enforce.
	 *
	 * @return bool
	 */
	private function server_cookie_guard_has_consent_context() {
		if ( faz_is_front_end_request() ) {
			// load_banner() runs at init priority 10, before this guard opens
			// its buffer at init priority 20, and leaves $this->template null on
			// every no-banner path: the global toggle, page exclusions, AMP,
			// geo no-banner routing, "no active banner" and geo blocking.
			// Leaning on that single already-made decision keeps the three
			// enforcement layers in agreement instead of duplicating six
			// conditions that could drift apart.
			//
			// is_banner_disabled_by_settings() is re-evaluated on top of it
			// because a numeric page-ID exclusion is not resolvable at init but
			// is at the flush call site, which is where the destructive
			// filtering actually happens.
			return (bool) $this->template && ! $this->is_banner_disabled_by_settings();
		}
		// REST and admin-ajax subrequests: faz_is_front_end_request() is false
		// there, so load_banner() never runs and $this->template is
		// structurally null. Gating on it would silently kill the guard on
		// subrequests — the one thing it does that the shredder cannot — so
		// re-evaluate instead the two visitor-facing decisions that do not
		// depend on the main query.
		return ! $this->is_banner_disabled_by_settings() && ! $this->is_geo_banner_disabled();
	}

	/**
	 * Output-buffer callback. The body is returned byte-for-byte unchanged.
	 *
	 * @param string $buffer Buffered response body.
	 * @return string
	 */
	public function filter_outgoing_cookies( $buffer ) {
		$this->filter_current_set_cookie_headers();
		return (string) $buffer;
	}

	/** REST transport boundary: filter after the route callback, before echo. */
	public function filter_server_cookies_before_rest_echo( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature.
		if ( $this->server_cookie_guard_enabled() ) {
			$this->filter_current_set_cookie_headers();
		}
		return $result;
	}

	/** Redirect transport boundary: filter headers already queued by callbacks. */
	public function filter_server_cookies_before_redirect( $location, $status ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature.
		if ( $this->server_cookie_guard_enabled() ) {
			$this->filter_current_set_cookie_headers();
		}
		return $location;
	}

	/** @return void */
	private function filter_current_set_cookie_headers() {
		if ( ! $this->server_cookie_guard_enabled() || headers_sent() ) {
			return;
		}
		$headers = headers_list();
		$result  = $this->filter_outgoing_cookie_header_lines( $headers );
		if ( empty( $result['blocked'] ) ) {
			return;
		}
		header_remove( 'Set-Cookie' );
		foreach ( $result['set_cookie_headers'] as $header_line ) {
			header( $header_line, false );
		}
		$this->record_blocked_server_cookies( $result['blocked'] );
	}

	/**
	 * Pure header-list filter used by the transport boundary and unit tests.
	 * Cookie values are never returned in the blocked diagnostics.
	 *
	 * @param string[] $headers Full response header lines.
	 * @return array{set_cookie_headers:string[],blocked:array<int,array{name:string,category:string}>}
	 */
	public function filter_outgoing_cookie_header_lines( $headers ) {
		$keep    = array();
		$blocked = array();
		foreach ( (array) $headers as $header_line ) {
			if ( ! is_string( $header_line ) || 0 !== stripos( $header_line, 'Set-Cookie:' ) ) {
				continue;
			}
			$cookie_string = trim( substr( $header_line, strlen( 'Set-Cookie:' ) ) );
			$first_part    = trim( (string) strtok( $cookie_string, ';' ) );
			$equals        = strpos( $first_part, '=' );
			$name          = false === $equals ? '' : trim( substr( $first_part, 0, $equals ) );
			if ( '' === $name || $this->is_cookie_deletion_header( $cookie_string ) || $this->is_cookie_allowed( $name, $this->set_cookie_attribute( $cookie_string, 'domain' ) ) ) {
				$keep[] = $header_line;
				continue;
			}
			$blocked[] = array(
				'name'     => sanitize_text_field( $name ),
				'category' => $this->get_cookie_category_slug( $name ),
			);
		}
		$this->persist_definition_category_memo();
		return array(
			'set_cookie_headers' => $keep,
			'blocked'            => $blocked,
		);
	}

	/** @return string */
	private function set_cookie_attribute( $cookie_string, $attribute ) {
		if ( preg_match( '/(?:^|;)\s*' . preg_quote( $attribute, '/' ) . '\s*=\s*([^;]+)/i', (string) $cookie_string, $match ) ) {
			return trim( $match[1] );
		}
		return '';
	}

	/** @return bool */
	private function is_cookie_deletion_header( $cookie_string ) {
		$max_age = $this->set_cookie_attribute( $cookie_string, 'max-age' );
		if ( '' !== $max_age && is_numeric( $max_age ) && (int) $max_age <= 0 ) {
			return true;
		}
		$expires = $this->set_cookie_attribute( $cookie_string, 'expires' );
		return '' !== $expires && false !== strtotime( $expires ) && strtotime( $expires ) <= time();
	}

	/** @return void */
	private function record_blocked_server_cookies( $blocked ) {
		$recent = get_transient( 'faz_recent_blocked_server_cookies' );
		$recent = is_array( $recent ) ? $recent : array();
		// PATH ONLY, and capped.
		//
		// sanitize_text_field() strips tags; it does NOT strip the query string
		// and does not truncate. WordPress query strings routinely carry
		// personal data — ?email=, the WooCommerce order_key, the password-reset
		// key+login pair, _wpnonce, site-search terms — and this diagnostic is
		// written by ANONYMOUS front-end requests, then kept for 24 hours and
		// rendered in a manage_options table. Nothing erases it: the plugin's
		// GDPR eraser is email-keyed and covers consent logs only, and the
		// default uninstall path skips cleanup (gated on remove_data_on_uninstall,
		// default false). The question this column exists to answer is "which
		// page emitted the cookie", which the path answers exactly as well as
		// the full URI does, so the query string is discarded before storage
		// rather than after.
		//
		// The 255-char cap bounds the row as well: with no length limit an
		// anonymous visitor requesting a URL with an 8 KB query string drove a
		// ~400 KB wp_options rewrite per request.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = substr( (string) strtok( $uri, '?' ), 0, 255 );
		foreach ( (array) $blocked as $entry ) {
			if ( empty( $entry['name'] ) ) {
				continue;
			}
			$recent[] = array(
				'name'       => sanitize_text_field( $entry['name'] ),
				'category'   => sanitize_key( isset( $entry['category'] ) ? $entry['category'] : '' ),
				'request'    => $path,
				'blocked_at' => time(),
			);
			do_action( 'faz_server_cookie_blocked', $entry['name'], isset( $entry['category'] ) ? $entry['category'] : '' );
		}
		// Retain exactly what the System Status table renders (its own
		// array_slice(…, 0, 20) after array_reverse). The previous 50 kept 30
		// entries no reader ever saw, which is retention without a purpose.
		set_transient( 'faz_recent_blocked_server_cookies', array_slice( $recent, -20 ), DAY_IN_SECONDS );
	}

	/** @return bool */
	private function is_always_allowed_cookie_name( $name ) {
		// Single source of truth with the display layer. is_wp_internal_cookie()
		// is the plugin's own never-show / never-shred allowlist: WordPress auth
		// and settings cookies, comment_author_*, _litespeed_*, lscache_vary and
		// wpdiscuz_nonce_*. Commit ad72cd3 added wpdiscuz_nonce_ there after the
		// server-side shredder deleted a live site's comment CSRF nonce
		// (gooloo.de, FAZ 1.13.6) — but only the banner/table/policy renderers
		// ever consulted that list, so enforcement never received the fix.
		// Delegating here makes the invariant hold in the one direction that
		// matters: a cookie the plugin refuses to SHOW can never be DELETED.
		if ( self::is_wp_internal_cookie( $name ) ) {
			return true;
		}
		$patterns = array(
			'fazcookie-consent*', 'fazcookie-dnsmpi*', 'fazVendorConsent*', 'fazVendorLI*', 'euconsent-v2*', 'fazVendorSource*', 'faz_scan_session',
			'wordpress_*', 'wp-settings-*', 'wp-settings-time-*', 'wp-postpass_*',
			'comment_author_*', 'wp_lang', 'wordpress_test_cookie',
			'wp_woocommerce_session_*', 'woocommerce_*', 'wc_cart_hash_*', 'wc_fragments_*',
			'PHPSESSID', '_GRECAPTCHA', 'cf_clearance',
		);
		foreach ( $patterns as $pattern ) {
			if ( $this->cookie_name_matches( $name, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classify a cookie across every tier available to this install.
	 *
	 * CLASSIFICATION ONLY — this answers "what is this cookie?" for the admin
	 * screens, the cookie policy and the blocked-cookie diagnostics. It is
	 * deliberately wide: the admin catalogue, Known_Providers, the curated
	 * Cookie_Database and the 6,754-entry bundled Open Cookie Database. That
	 * breadth is a genuine accuracy win for a human reading a report.
	 *
	 * It is NOT an authority to destroy anything. Deletion is decided by
	 * get_enforceable_cookie_category_slug(), which is narrower on purpose.
	 * Do not point an enforcement path back at this method.
	 *
	 * @param string $name Cookie name.
	 * @return string Category slug, or '' when no tier recognises the name.
	 */
	private function get_cookie_category_slug( $name ) {
		$name = (string) $name;
		// The administrator-owned inventory is authoritative. A reviewed or
		// manually corrected classification must win over bundled heuristics.
		foreach ( $this->get_catalog_cookie_categories() as $pattern => $category ) {
			if ( $this->cookie_name_matches( $name, $pattern ) ) {
				return $category;
			}
		}
		foreach ( Known_Providers::get_cookie_map() as $pattern => $category ) {
			if ( $this->cookie_name_matches( $name, $pattern ) ) {
				return sanitize_key( $category );
			}
		}
		if ( class_exists( '\FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database' ) ) {
			$known = \FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database::lookup( $name );
			if ( is_array( $known ) && ! empty( $known['category'] ) ) {
				return sanitize_key( $known['category'] );
			}
		}
		if ( class_exists( '\FazCookie\Includes\Cookie_Definitions' ) ) {
			return $this->lookup_definition_category( $name );
		}
		return '';
	}

	/**
	 * Category slug the server is permitted to ACT on: the narrow twin of
	 * get_cookie_category_slug(), and the only classifier is_cookie_allowed()
	 * may consult.
	 *
	 * Two tiers by default, both reviewable by a human on this site:
	 *
	 *   1. The admin catalogue (wp_faz_cookies). An administrator who marks a
	 *      cookie `marketing` must have that decision enforced, and most such
	 *      corrections sit outside Known_Providers by definition — which is why
	 *      this is NOT a revert to a Known_Providers-only map.
	 *   2. Known_Providers, the curated 283-pattern bundled map.
	 *
	 * The Cookie_Database and Open Cookie Database tiers are excluded because
	 * they are third-party datasets, refreshable from GitHub, in which 99.5% of
	 * entries land in a slug that is blocked pre-consent under an opt-in law,
	 * and whose wildcard entries are bare prefixes as short as two characters
	 * (`_s`, `ct`, `sr`, `tp`). Excellent hints for an admin screen; not an
	 * acceptable authority for issuing setcookie( …, -1 ) against a visitor's
	 * session. A site that wants them can opt in per the filter below, and even
	 * then short wildcard prefixes stay non-enforceable.
	 *
	 * @param string $name Cookie name.
	 * @return string Category slug, or '' when no enforceable tier knows it.
	 */
	private function get_enforceable_cookie_category_slug( $name ) {
		$name = (string) $name;
		foreach ( $this->get_catalog_cookie_categories() as $pattern => $category ) {
			if ( $this->cookie_name_matches( $name, $pattern ) ) {
				return $category;
			}
		}
		foreach ( Known_Providers::get_cookie_map() as $pattern => $category ) {
			if ( $this->cookie_name_matches( $name, $pattern ) ) {
				return sanitize_key( $category );
			}
		}
		/**
		 * Whether the bundled third-party cookie datasets (Cookie_Database and
		 * the Open Cookie Database) may authorise server-side DELETION, not
		 * merely classification.
		 *
		 * Off by default: see the method docblock. Turning it on still cannot
		 * enforce an Open Cookie Database wildcard whose prefix is shorter than
		 * self::ENFORCEABLE_WILDCARD_MIN_LENGTH.
		 *
		 * @param bool $enabled Whether the bundled datasets may authorise deletion.
		 */
		if ( ! apply_filters( 'faz_shred_uses_cookie_database', false ) ) {
			return '';
		}
		if ( class_exists( '\FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database' ) ) {
			$known = \FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database::lookup( $name );
			if ( is_array( $known ) && ! empty( $known['category'] ) ) {
				return sanitize_key( $known['category'] );
			}
		}
		return $this->enforceable_definition_category( $name );
	}

	/**
	 * Open Cookie Database tier of get_enforceable_cookie_category_slug().
	 *
	 * Unlike lookup_definition_category(), this inspects HOW the dataset
	 * matched. Cookie_Definitions::lookup() falls back to a bare
	 * `0 === strpos( $name, $pattern )` prefix test over its wildcard entries,
	 * and the bundled snapshot ships two- and three-character prefixes, so
	 * `_session` matches `_s` (Analytics) and `tp_visitor` matches `tp`
	 * (Marketing). A prefix that short carries no evidence about an ordinary
	 * first-party cookie, so it is refused enforcement even when the operator
	 * opted the dataset in. Exact-name hits are unaffected.
	 *
	 * @param string $name Cookie name.
	 * @return string Category slug, or '' when the dataset must not be acted on.
	 */
	private function enforceable_definition_category( $name ) {
		if ( ! class_exists( '\FazCookie\Includes\Cookie_Definitions' ) ) {
			return '';
		}
		$definition = \FazCookie\Includes\Cookie_Definitions::get_instance()->lookup( $name );
		if ( ! is_array( $definition ) || empty( $definition['category'] ) ) {
			return '';
		}
		$matched = isset( $definition['name'] ) ? (string) $definition['name'] : '';
		if ( ! empty( $definition['wildcard'] ) && strlen( $matched ) < self::ENFORCEABLE_WILDCARD_MIN_LENGTH ) {
			return '';
		}
		$category = sanitize_key( $definition['category'] );
		return 'advertising' === $category ? 'marketing' : $category;
	}

	/**
	 * Open Cookie Database tier of get_cookie_category_slug(), memoized by name.
	 *
	 * Materializing the bundled Open Cookie Database costs ~13 ms and ~16 MB of
	 * peak memory, and it is otherwise rebuilt on every request that reaches
	 * this tier — including the pre-consent first page view of every visitor,
	 * where blocked categories are non-empty by design and the guard in
	 * is_cookie_allowed() cannot short-circuit. The cookie names seen on a site
	 * are a small and stable set, so the resolved verdicts are persisted next to
	 * faz_server_cookie_category_map: same TTL, and the same invalidation hooks
	 * in class-cli.php, so an admin reclassification takes effect on both maps
	 * at the same moment rather than trading a performance bug for a staleness
	 * bug.
	 *
	 * @param string $name Cookie name.
	 * @return string Category slug, or '' when the database does not know it.
	 */
	private function lookup_definition_category( $name ) {
		if ( null === $this->definition_category_memo ) {
			$cached                         = get_transient( 'faz_server_cookie_definition_map' );
			$this->definition_category_memo = is_array( $cached ) ? $cached : array();
		}
		// array_key_exists, not isset: a memoized miss is stored as '' and must
		// not be re-resolved.
		if ( array_key_exists( $name, $this->definition_category_memo ) ) {
			return $this->definition_category_memo[ $name ];
		}

		$category   = '';
		$definition = \FazCookie\Includes\Cookie_Definitions::get_instance()->lookup( $name );
		if ( is_array( $definition ) && ! empty( $definition['category'] ) ) {
			$category = sanitize_key( $definition['category'] );
			$category = 'advertising' === $category ? 'marketing' : $category;
		}

		$this->definition_category_memo[ $name ] = $category;
		// Bounded: a crawler spraying junk cookie names must not grow the stored
		// row without limit. Least-recently-added entries fall off first.
		if ( count( $this->definition_category_memo ) > 200 ) {
			$this->definition_category_memo = array_slice( $this->definition_category_memo, -200, null, true );
		}
		$this->definition_category_memo_dirty = true;
		return $category;
	}

	/**
	 * Persist the Open Cookie Database memo once per request, if it grew.
	 *
	 * Called from the two entry points that classify cookies in bulk so a
	 * request that meets fifteen unknown names performs one write, not fifteen.
	 *
	 * @return void
	 */
	private function persist_definition_category_memo() {
		if ( ! $this->definition_category_memo_dirty || ! is_array( $this->definition_category_memo ) ) {
			return;
		}
		$this->definition_category_memo_dirty = false;
		set_transient( 'faz_server_cookie_definition_map', $this->definition_category_memo, HOUR_IN_SECONDS );
	}

	/**
	 * Cookie-name → category map derived from the admin catalogue.
	 *
	 * WordPress-internal rows are skipped, exactly as prepare_frontend_cookies()
	 * and get_cookies() already skip them: a cookie the plugin deliberately
	 * hides from the banner must not be the thing that drives a server-side
	 * deletion. This was the one place a hidden technical cookie could reach
	 * setcookie( …, -1 ).
	 *
	 * The transient key is versioned (`…_v2`): a map persisted by an earlier
	 * build still contains the unfiltered rows, and without the bump it would
	 * keep answering for up to an hour after deploy — the bug would look fixed
	 * in test and stay live in production. includes/class-cli.php busts both
	 * keys on every cookie/category/settings write.
	 *
	 * @return array<string,string>
	 */
	private function get_catalog_cookie_categories() {
		if ( null !== $this->catalog_cookie_categories_cache ) {
			return $this->catalog_cookie_categories_cache;
		}
		$cached = get_transient( 'faz_server_cookie_category_map_v2' );
		if ( is_array( $cached ) ) {
			$this->catalog_cookie_categories_cache = $cached;
			return $cached;
		}
		$this->catalog_cookie_categories_cache = array();
		$category_slugs = array();
		foreach ( \FazCookie\Admin\Modules\Cookies\Includes\Category_Controller::get_instance()->get_items() as $category_row ) {
			$category = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $category_row );
			$category_slugs[ $category->get_id() ] = sanitize_key( $category->get_slug() );
		}
		foreach ( \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller::get_instance()->get_items() as $cookie_row ) {
			$cookie = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie( $cookie_row );
			$name   = $cookie->get_name();
			$cat_id = $cookie->get_category();
			if ( '' === $name || self::is_wp_internal_cookie( $name ) ) {
				continue;
			}
			if ( ! empty( $category_slugs[ $cat_id ] ) ) {
				$this->catalog_cookie_categories_cache[ $name ] = $category_slugs[ $cat_id ];
			}
		}
		set_transient( 'faz_server_cookie_category_map_v2', $this->catalog_cookie_categories_cache, HOUR_IN_SECONDS );
		return $this->catalog_cookie_categories_cache;
	}

	/**
	 * Delete non-consented cookies before the page renders (cookie shredding).
	 *
	 * Runs on template_redirect, after the main query has resolved but before
	 * template output. Classifies each existing cookie through
	 * is_cookie_allowed() — admin catalogue then Known_Providers — and deletes
	 * those belonging to categories the visitor has not consented to.
	 *
	 * Gated on `cookie_shredder_enabled()` — the safety standdowns only, NOT
	 * the new block_server_cookies opt-in, which governs only the outgoing
	 * Set-Cookie guard. The decision that
	 * governs every destructive server-side enforcement layer, not a private
	 * copy of the `script_blocking.block_server_cookies` opt-in. Destroying a
	 * visitor's existing cookie is irreversible for that request and the failure
	 * signature is indirect — "users randomly logged out, cart empties, comments
	 * 403" with nothing pointing at this plugin — so it must be a decision an
	 * operator made, not something an upgrade turns on underneath them.
	 * Client-side cleanup on consent-save (script.js _fazCleanupRevokedCookies)
	 * is unaffected and remains the default enforcement for existing cookies.
	 *
	 * Three clauses of that gate matter here beyond the opt-in, and all three
	 * are wanted:
	 *
	 * - Cache Compatibility Mode. There get_blocked_categories() returns every
	 *   non-necessary slug unconditionally, returning before it ever reads the
	 *   consent cookie. Under a private opt-in check this shredder passed its
	 *   own gate, is_cookie_allowed() resolved a CONSENTED visitor's `_ga` to
	 *   false, and every enforceably-classified cookie was destroyed on every
	 *   front-end render. That is the regression the shared gate closes, and it
	 *   is why the cache-compat decision must be made in exactly one place.
	 * - No consent context (faz_disable_banner(), page exclusions, no banner,
	 *   banner_control off, geo no-banner routing). Such a visitor has no
	 *   script.js and can never record consent, so deleting their cookies is
	 *   permanent with no remedy. This also subsumes the `! $this->template` and
	 *   faz_disable_banner()/is_blocking_disabled_for_page() checks this method
	 *   used to repeat — one gate, not two that can drift apart.
	 * - A valid authenticated browser-scan request. Standing down there lets the
	 *   scanner inventory exactly the cookies a real visitor would keep instead
	 *   of scanning a page it has just emptied.
	 */
	public function shred_non_consented_cookies() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! $this->cookie_shredder_enabled() ) {
			return;
		}
		// Early bail: with no blocked category and no explicit per-service or
		// per-cookie denial, is_cookie_allowed() provably returns true for every
		// name, so the loop below would classify every cookie in the request to
		// reach a foregone conclusion. Both conditions are required — a
		// `svc.<id>:no` / `ck.<svc>.<cookie>:no` denial stays enforceable on a
		// site where no category is blocked.
		//
		// Both questions are asked of the visitor view (see
		// is_cookie_allowed_for_visitor()): the cookies being deleted are the
		// ones already in THIS visitor's browser, so their own grants decide.
		$visitor_view = $this->render_overblocks_visitor();
		$blocked      = $visitor_view ? $this->get_enforcement_blocked_categories() : $this->get_blocked_categories();
		if ( empty( $blocked ) && empty( $this->get_service_cookie_decisions( $visitor_view ) ) ) {
			return;
		}

		$host              = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$current_host      = preg_replace( '/^www\./', '', preg_replace( '/:\d+$/', '', $host ) );
		$shared_domain     = ltrim( (string) $this->get_cookie_domain(), '.' );
		$domain_candidates = array_values( array_unique( array_filter( array( $current_host, $shared_domain ) ) ) );

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( ! $this->is_cookie_allowed_for_visitor( $name ) ) {
				setcookie( $name, '', -1, '/' );
				foreach ( $domain_candidates as $domain ) {
					setcookie( $name, '', -1, '/', $domain );
					setcookie( $name, '', -1, '/', '.' . $domain );
				}
				unset( $_COOKIE[ $name ] );
			}
		}

		$this->persist_definition_category_memo();
	}

	/**
	 * Decide whether a cookie may exist for the current visitor.
	 *
	 * This is the single policy used by both the existing-cookie shredder and
	 * the outgoing Set-Cookie guard: per-cookie > per-service > category, with
	 * explicit denials winning shared patterns and necessary/internal cookies
	 * always preserved.
	 *
	 * Unknown cookies fail permissive because blocking an unclassified session
	 * token can break authentication or checkout. The scanner inventories them;
	 * once classified, this guard enforces their category automatically.
	 *
	 * This public entry point answers for the RESPONSE being built: on a
	 * 'fallback' render (see decide_visitor_state_cache_veto()) it reads the
	 * shareable block set, because that response may be stored by a cache that
	 * also keeps its headers (nginx with fastcgi_ignore_headers Set-Cookie, some
	 * CDNs) and a Set-Cookie for a category a first visit has not granted would
	 * be replayed to every later visitor. script.js sets those cookies after
	 * consent instead. The shredder, which deletes cookies already in this
	 * visitor's browser, asks is_cookie_allowed_for_visitor().
	 *
	 * @param string $name   Cookie name.
	 * @param string $domain Optional cookie domain (reserved for future rules).
	 * @return bool
	 */
	public function is_cookie_allowed( $name, $domain = '' ) {
		return $this->cookie_allowed_in_view( $name, $domain, false );
	}

	/**
	 * Whether a cookie already in the visitor's browser may stay there.
	 *
	 * Same policy as is_cookie_allowed(), read against the visitor's OWN state
	 * on a 'fallback' render instead of the shareable union. That union is a
	 * render-time over-block the client undoes; a deletion is not undone, and
	 * applying the union here destroyed a consenting visitor's `_ga` on every
	 * page of a site whose visitor-state veto was off. Their denials are part
	 * of their own state, so a rejected category's cookies are still deleted.
	 * The opt-out-law render that parks the sale/sharing categories is the
	 * same kind of over-block and is read the same way (see
	 * render_overblocks_visitor()); everywhere else the two views are one set.
	 *
	 * @param string $name Cookie name.
	 * @return bool
	 */
	private function is_cookie_allowed_for_visitor( $name ) {
		return $this->cookie_allowed_in_view( $name, '', $this->render_overblocks_visitor() );
	}

	/**
	 * Whether this render blocks more than the visitor's own state does: a
	 * 'fallback' render (the shareable union), or an opt-out-law render that
	 * parks the sale/sharing categories for script.js to release (see
	 * sale_share_parked_by_default()). Either over-block is undone on the
	 * client; a cookie deleted on its strength is not, so the shredder reads
	 * the visitor's own set whenever this is true.
	 *
	 * @return bool
	 */
	private function render_overblocks_visitor() {
		if ( 'fallback' === $this->visitor_state_veto ) {
			return true;
		}
		return $this->get_blocked_categories() !== $this->get_enforcement_blocked_categories();
	}

	/**
	 * @param string $name         Cookie name.
	 * @param string $domain       Optional cookie domain.
	 * @param bool   $visitor_view Read the visitor's own state (fallback shredder).
	 * @return bool
	 */
	private function cookie_allowed_in_view( $name, $domain, $visitor_view ) {
		$name      = sanitize_text_field( (string) $name );
		$cache_key = ( $visitor_view ? 'visitor|' : '' ) . strtolower( $name . '|' . (string) $domain );
		$blocked_categories = $visitor_view ? $this->get_enforcement_blocked_categories() : $this->get_blocked_categories();
		if ( isset( $this->cookie_allowed_cache[ $cache_key ] ) ) {
			return $this->cookie_allowed_cache[ $cache_key ];
		}
		if ( '' === $name || $this->is_always_allowed_cookie_name( $name ) ) {
			$this->cookie_allowed_cache[ $cache_key ] = true;
			return true;
		}

		$service_decisions = $this->get_service_cookie_decisions( $visitor_view );

		// Nothing is blocked and no explicit per-service/per-cookie decision
		// exists: every remaining path in this method provably returns true, so
		// the enforcement classification cascade below (admin catalogue →
		// Known_Providers, plus the opt-in bundled-dataset tiers) would be paid
		// for an answer that is already determined. The shredder runs at
		// template_redirect priority 1 on every front-end render, so that cost
		// is charged per request.
		//
		// BOTH conditions are required. Guarding on the category list alone
		// would silently stop honouring explicit `svc.<id>:no` /
		// `ck.<svc>.<cookie>:no` denials, which remain enforceable even when no
		// category is blocked — a consent regression, and one that a casual
		// smoke test would miss because most sites configure no per-service
		// consent at all.
		if ( empty( $service_decisions ) && empty( $blocked_categories ) ) {
			$this->cookie_allowed_cache[ $cache_key ] = true;
			return true;
		}

		$service_allows = false;
		foreach ( $service_decisions as $pattern => $decisions ) {
			if ( ! $this->cookie_name_matches( $name, $pattern ) ) {
				continue;
			}
			if ( in_array( 'no', $decisions, true ) ) {
				$this->cookie_allowed_cache[ $cache_key ] = false;
				return false;
			}
			if ( in_array( 'yes', $decisions, true ) ) {
				$service_allows = true;
			}
		}
		if ( $service_allows ) {
			$this->cookie_allowed_cache[ $cache_key ] = true;
			return true;
		}

		// Enforcement reads the NARROW classifier. get_cookie_category_slug()
		// stays wide for the admin UI and the blocked-cookie diagnostics, but a
		// third-party dataset must never be what authorises a deletion.
		$category = $this->get_enforceable_cookie_category_slug( $name );
		// 'uncategorized' is the ABSENCE of a classification, not a verdict: the
		// scanner writes every cookie it fails to recognise as uncategorized
		// (Scanner\Controller::…, "Default fallback category for unknown
		// cookies"), and get_blocked_categories() blocks every non-necessary
		// slug pre-consent. Treating it as a real blocked category inverts this
		// method's own contract — "unknown cookies fail permissive because
		// blocking an unclassified session token can break authentication or
		// checkout" — into "we do not know what this is, so delete it". That
		// inversion is what broke comments on gooloo.de (commit ad72cd3).
		if ( '' === $category || in_array( $category, array( 'necessary', 'wordpress-internal', 'uncategorized' ), true ) ) {
			$this->cookie_allowed_cache[ $cache_key ] = true;
			return true;
		}
		if ( ! in_array( $category, $blocked_categories, true ) ) {
			$this->cookie_allowed_cache[ $cache_key ] = true;
			return true;
		}

		foreach ( $this->get_whitelisted_cookie_patterns() as $pattern ) {
			if ( $this->cookie_name_matches( $name, $pattern ) ) {
				$this->cookie_allowed_cache[ $cache_key ] = true;
				return true;
			}
		}

		$this->cookie_allowed_cache[ $cache_key ] = false;
		return false;
	}

	/**
	 * @param bool $visitor_view Read the visitor's own svc.* grants (see
	 *                           is_cookie_allowed_for_visitor()); only differs
	 *                           from the render view on a 'fallback' render.
	 * @return array<string,array<int,string>>
	 */
	private function get_service_cookie_decisions( $visitor_view = false ) {
		if ( $visitor_view && 'fallback' === $this->visitor_state_veto ) {
			if ( null === $this->visitor_service_cookie_decisions_cache ) {
				$this->visitor_service_cookie_decisions_cache = $this->compute_service_cookie_decisions( $this->get_enforcement_service_consent() );
			}
			return $this->visitor_service_cookie_decisions_cache;
		}
		if ( null === $this->service_cookie_decisions_cache ) {
			// On a 'fallback' render get_service_consent() has already dropped
			// the svc.* grants; a ck.* grant read straight from the cookie must
			// not reopen a Set-Cookie on that cacheable response either.
			$this->service_cookie_decisions_cache = $this->compute_service_cookie_decisions( $this->get_service_consent(), 'fallback' === $this->visitor_state_veto );
		}
		return $this->service_cookie_decisions_cache;
	}

	/**
	 * @param array $service_consent Per-service map (render or visitor view).
	 * @param bool  $denials_only    Keep only the 'no' decisions.
	 * @return array<string,array<int,string>>
	 */
	private function compute_service_cookie_decisions( $service_consent, $denials_only = false ) {
		$decisions       = array();
		$settings        = $this->get_faz_settings();
		$banner_control  = isset( $settings['banner_control'] ) ? (array) $settings['banner_control'] : array();
		$per_cookie      = ! empty( $banner_control['per_cookie_consent'] ) && ! empty( $banner_control['per_service_consent'] );
		// Scoped like get_service_consent() and get_blocked_categories(): a
		// ck.* grant recorded under another banner/law must not outlive the
		// cookie the rest of this request has already rejected.
		$valid           = $per_cookie ? $this->get_scoped_valid_consent_cookie() : '';
		$consent         = ( '' !== $valid && function_exists( 'faz_parse_consent_cookie' ) ) ? faz_parse_consent_cookie( $valid ) : array();
		$signal_blocked = $per_cookie ? $this->get_signal_blocked_services( $valid ) : array();
		if ( empty( $service_consent ) && ! $per_cookie ) {
			return $decisions;
		}
		foreach ( $this->get_enforceable_services() as $service ) {
			$svc_id = isset( $service['id'] ) ? sanitize_key( $service['id'] ) : '';
			if ( '' === $svc_id || empty( $service['cookies'] ) ) {
				continue;
			}
			$svc_decision = isset( $service_consent[ $svc_id ] ) && in_array( $service_consent[ $svc_id ], array( 'yes', 'no' ), true ) ? $service_consent[ $svc_id ] : '';
			foreach ( $service['cookies'] as $cookie_pattern ) {
				$cookie_pattern = sanitize_text_field( (string) $cookie_pattern );
				$decision       = $this->resolve_service_cookie_decision( $svc_id, $cookie_pattern, $svc_decision, $consent, $per_cookie );
				if ( 'yes' === $decision && ( $denials_only || isset( $signal_blocked[ $svc_id ] ) ) ) {
					continue;
				}
				if ( '' === $cookie_pattern || ! in_array( $decision, array( 'yes', 'no' ), true ) ) {
					continue;
				}
				if ( ! isset( $decisions[ $cookie_pattern ] ) ) {
					$decisions[ $cookie_pattern ] = array();
				}
				$decisions[ $cookie_pattern ][] = $decision;
			}
		}
		return $decisions;
	}

	/** @return string[] */
	private function get_whitelisted_cookie_patterns() {
		if ( null !== $this->whitelisted_cookie_patterns_cache ) {
			return $this->whitelisted_cookie_patterns_cache;
		}
		$settings       = $this->get_faz_settings();
		$user_whitelist = $this->get_user_whitelist_patterns();
		$this->whitelisted_cookie_patterns_cache = $this->compute_whitelisted_cookie_patterns( $user_whitelist, $this->get_valid_category_slugs() );
		return $this->whitelisted_cookie_patterns_cache;
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
		// Never rewrite a machine-readable representation of the content.
		//
		// WordPress applies `the_content` inside get_the_content_feed(), so a
		// post carrying an embed was shipped to feed readers as a
		// data-faz-src placeholder — and script.js does not exist there, so
		// nothing would ever restore it. The embed was simply gone for every
		// RSS subscriber, every Mailchimp RSS-to-email campaign and every
		// headless consumer, with no error anywhere to show for it.
		//
		// Blocking is also meaningless in a feed: consent is a browser-side
		// state, a feed reader has no banner to accept, and the third-party
		// resource is fetched (or not) by the reader under its own rules.
		if ( faz_is_machine_readable_request() || wp_doing_cron() ) {
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

		// An iframe's srcdoc is markup inside an attribute value, and every pass
		// below is a regex over markup: without this the script pass re-typed a
		// <script src> INSIDE the value and wrote `data-faz-category="…"` into a
		// double-quoted attribute, which ends the srcdoc at the first injected
		// quote. The embedded document was then truncated — destroyed rather
		// than delayed — and the leftovers became stray attributes on the frame.
		// process_output_buffer() has had this protection since the same defect
		// was found there; this entry point runs the same passes and did not.
		$stash_nonce = $this->stash_nonce();
		// Comments and script bodies are text; see process_output_buffer().
		$inert_stash = array();
		$content     = $this->stash_inert_spans( $content, $stash_nonce, $inert_stash );

		$srcdoc_stash  = array();
		$srcdoc_marker = '';
		$failed_passes = array();
		$stashed       = $this->stash_iframe_srcdocs( $content, $providers, $blocked_categories, $stash_nonce, $srcdoc_stash, $srcdoc_marker, $failed_passes );
		if ( null === $stashed ) {
			// Fail-closed turned off: the srcdoc stays in place and the passes
			// below see it, as before the stash existed.
			$srcdoc_stash  = array();
			$srcdoc_marker = '';
		} else {
			$content = $stashed;
		}

		// Block <script> tags in content.
		// On a PCRE error the same callback is re-run over the linear walk
		// (run_markup_pass()). The strip that used to stand here was a second
		// lazy regex over the same markup, which fails on the same input and
		// then returned the content unfiltered; it is kept only for
		// `faz_output_buffer_fail_closed` set to false.
		if ( false !== stripos( $content, '<script' ) ) {
			$content = $this->run_markup_pass(
				$content,
				'#' . self::script_token_skip() . '<script(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>(.*?)</script(?=[\s/>])[^>]*>#is',
				'script',
				function ( $m ) use ( $providers, $blocked_categories, &$inert_stash ) {
					return $this->process_with_inert_body(
						$m,
						'script',
						$inert_stash,
						function ( $m ) use ( $providers, $blocked_categories ) {
							return $this->process_script_tag( $m, $providers, $blocked_categories );
						}
					);
				},
				$failed_passes,
				$inert_stash,
				function ( $content ) {
					return preg_replace( '#<script\b[^>]*>.*?</script(?=[\s/>])[^>]*>#is', '', $content ) ?? $content;
				}
			);
		}

		// Block <iframe> tags in content.
		if ( false !== stripos( $content, '<iframe' ) ) {
			$content = $this->run_markup_pass(
				$content,
				self::iframe_pass_pattern(),
				'iframe',
				function ( $m ) use ( $providers, $blocked_categories, $srcdoc_marker ) {
					return $this->process_iframe_tag( $m, $providers, $blocked_categories, $srcdoc_marker );
				},
				$failed_passes,
				$inert_stash,
				function ( $content ) {
					return preg_replace( '#<iframe\b[^>]*>.*?</iframe(?=[\s/>])[^>]*>#is', '', $content ) ?? $content;
				}
			);
		}
		$this->log_markup_pass_failure( $failed_passes, 'content filter' );

		if ( ! empty( $inert_stash ) ) {
			$content = strtr( $content, $inert_stash );
		}

		// Hide social embed containers that depend on blocked scripts.
		$content = $this->process_social_embeds( $content, $blocked_categories );

		// Hide Elementor video widgets (they render an EMPTY .elementor-video
		// wrapper server-side and build the real iframe client-side from
		// data-settings, so the generic <iframe> blocker above never sees one).
		$content = $this->process_elementor_video_widgets( $content, $blocked_categories, $providers );
		$content = $this->process_bricks_map_widgets( $content, $blocked_categories, $providers );

		// Last, so no pass above can have written into the value: the marker
		// goes back to the ORIGINAL token — `srcdoc=…` when the document is
		// allowed, `data-faz-srcdoc=…` when it is parked — bytes and quoting
		// untouched either way.
		if ( ! empty( $srcdoc_stash ) ) {
			$content = strtr( $content, $srcdoc_stash );
		}

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
		// Same reason as filter_content_blocking(): WP_Embed::autoembed runs on
		// `the_content` at priority 8, and get_the_content_feed() applies
		// `the_content`, so this fires in feeds too — and $this->template IS
		// populated there. A post carrying a bare YouTube URL shipped to every
		// subscriber as a placeholder div that no script.js would ever restore.
		//
		// wp_doing_cron() is the second half: a newsletter plugin rendering
		// post content on WP-Cron mails out data-faz-src placeholders and
		// type="text/plain" scripts, and there is no JS in an email either.
		if ( faz_is_machine_readable_request() || wp_doing_cron() ) {
			return $html;
		}
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
		// Rename iframe src to prevent loading and label the frame, in one walk
		// over its attributes.
		$frame_category = $matched_category;
		$blocked_html   = preg_replace_callback(
			'#<iframe(?=[\s/>])(' . self::ATTR_WALK . '(?:/\s*+)?)>#i',
			function ( $m ) use ( $frame_category ) {
				list( $attrs ) = $this->rename_attr_tokens( $m[1], array( 'src' => 'data-faz-src' ) );
				return '<iframe data-faz-category="' . esc_attr( $frame_category ) . '"' . $attrs . '>';
			},
			$blocked_html
		) ?? $blocked_html;
		$blocked_html = self::faz_add_hidden_class( $blocked_html );
		// Disable scripts using set_script_type_plain() for consistent type handling.
		$cat_attr = $matched_category;
		$result = preg_replace_callback(
			'#<script(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>(.*?)</script(?=[\s/>])[^>]*>#is',
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
			$blocked_html = preg_replace( '#<script\b[^>]*>.*?</script(?=[\s/>])[^>]*>#is', '', $blocked_html ) ?? $blocked_html;
		}

		return Placeholder_Builder::build( $service_id, $service_name, $matched_category, $blocked_html, $thumb_url );
	}

	/**
	 * Admin URL of Instagram Feed's own settings screen.
	 *
	 * Where its GDPR option lives, so a notice can send the site owner to the
	 * control that actually decides the behaviour instead of describing it.
	 * The slug is pinned by a test against the plugin's own registration, since
	 * a link to a page that does not exist is worse than no link.
	 *
	 * @since 1.26.0
	 * @return string
	 */
	public static function smash_balloon_settings_url() {
		return function_exists( 'admin_url' )
			? admin_url( 'admin.php?page=' . self::SMASH_BALLOON_SETTINGS_SLUG )
			: '';
	}

	/**
	 * Blocking patterns to drop while Instagram Feed is limiting itself.
	 *
	 * Exempting the container alone was a half measure that produced the worst of
	 * the three possible outcomes: the feed's `<div>` rendered while its script
	 * stayed blocked, so the visitor got an empty box and not even a placeholder
	 * to explain it — worse than blocking the feed outright, and worse than
	 * letting it work. With the GDPR setting on Yes the plugin serves local
	 * copies and contacts nothing, so its script is first-party and its provider
	 * entry declares no cookies; there is nothing left to gate.
	 *
	 * Two provider entries cover the same asset and BOTH must go, which is why
	 * this is derived rather than listed: `smash-balloon-instagram` owns
	 * `sb-instagram`, while the generic `instagram` entry carries
	 * `plugins/instagram-feed/js/` — a path pattern matching the very same
	 * first-party script. Dropping only the first leaves the second blocking it,
	 * which is exactly the empty-box state described above.
	 *
	 * @since 1.26.0
	 * @return array Map of pattern => true; empty when the accommodation is off.
	 */
	public static function smash_balloon_exempt_patterns() {
		if ( ! self::smash_balloon_self_restricts() ) {
			return array();
		}
		$exempt = array();
		foreach ( Known_Providers::get_all() as $service_id => $service ) {
			$patterns = isset( $service['patterns'] ) && is_array( $service['patterns'] ) ? $service['patterns'] : array();
			foreach ( $patterns as $pattern ) {
				// Instagram Feed's own assets, wherever the provider database
				// files them: its dedicated entry, plus any pattern addressing
				// the plugin's own directory. That directory name is the wp.org
				// slug, so it is as stable an anchor as the provider id itself.
				//
				// `-pro` is matched too, and deliberately. Instagram Feed Pro is
				// the same product installed under its own slug: it defines the
				// same SBIVER and reads the same sb_instagram_settings, so the
				// accommodation applies identically — and the provider database
				// carries a separate `plugins/instagram-feed-pro/js/` pattern
				// that would otherwise go on blocking the same first-party
				// script on exactly the sites that paid for it.
				if ( 'smash-balloon-instagram' === $service_id || preg_match( '#instagram-feed(-pro)?/#i', (string) $pattern ) ) {
					$exempt[ $pattern ] = true;
				}
			}
		}
		return $exempt;
	}

	/**
	 * Whether Smash Balloon's Instagram Feed already restricts itself.
	 *
	 * Instagram Feed has its own GDPR setting, `gdpr` in the
	 * `sb_instagram_settings` option, with three values. Only `yes` makes it
	 * self-restrict: SB_Instagram_GDPR_Integrations::blocking_cdn() then returns
	 * true unconditionally, the feed serves local image copies, and no request
	 * reaches Instagram's CDN at all. Gating that again adds nothing.
	 *
	 * Everything else keeps our blocking, and `auto` — the DEFAULT — is the case
	 * that matters most. Instagram Feed's automatic mode does not detect an
	 * arbitrary consent plugin: gdpr_plugins_active() checks a hardcoded list of
	 * nine specific ones by class, constant or function, and this plugin is not
	 * among them (as a de-branded fork it deliberately defines no CKY_* constant).
	 * On `auto` it therefore concludes no consent plugin is present and restricts
	 * nothing, which makes our block the only thing holding that feed back.
	 *
	 * Plugin inactive, option missing, key absent or unreadable: block. A signal
	 * we cannot read is not permission to load a tracker.
	 *
	 * @since 1.26.0
	 * @return bool True when Instagram Feed is handling it and we should not.
	 */
	public static function smash_balloon_self_restricts() {
		// Guarded so the method degrades to "block" in any context where these
		// are unavailable — standalone unit harnesses stub only what the code
		// they exercise needs, and an undefined function here would fatal a
		// caller that has nothing to do with Instagram.
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'faz_respect_smash_balloon_gdpr', true ) ) {
			return false;
		}
		// The option outlives the plugin. Deactivating or deleting Instagram
		// Feed leaves `sb_instagram_settings` behind in wp_options, so a stale
		// `gdpr=yes` from an install that is no longer running would read as
		// "somebody else is handling this" when nobody is — and standing down
		// on that would ship the very tracker this accommodation assumes is
		// already contained. Require a signal that the code implementing the
		// limitation is loaded: either the plugin's version constant or the
		// class whose blocking_cdn() is the behaviour being deferred to.
		// Both vanish with the plugin and neither can be left behind in the
		// database, which is exactly the property the option lacks.
		if ( ! defined( 'SBIVER' ) && ! class_exists( 'SB_Instagram_GDPR_Integrations', false ) ) {
			return false;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$settings = get_option( 'sb_instagram_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['gdpr'] ) ) {
			return false;
		}
		// Only a string can carry the documented value. A corrupted option
		// holding an array or an object would raise a conversion warning or
		// throw outright on the cast, turning a malformed setting into a
		// frontend error — so reject the type instead of normalising it.
		if ( ! is_string( $settings['gdpr'] ) ) {
			return false;
		}
		return 'yes' === strtolower( trim( $settings['gdpr'] ) );
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
			$result = preg_replace_callback(
				'#(<(?:div|blockquote|span)\b)([^>]*class\s*=\s*["\'][^"\']*\b' . preg_quote( $class, '#' ) . '\b[^"\']*["\'][^>]*)>#i',
				function ( $m ) use ( $category, $info ) {
					// Skip if already processed.
					if ( false !== strpos( $m[2], 'data-faz-category' ) ) {
						return $m[0];
					}
					// Honour the admin's exemptions here too. Social containers
					// were the one blocking path that consulted neither the
					// whitelist nor class="faz-skip", so a site owner who wanted
					// one embed through had no way to say so — reported on wp.org
					// by somebody who tried exactly that with the container id and
					// class, and got nothing. Every other filter in this class
					// treats those as binding; this one now does too.
					if ( $this->is_whitelisted( $m[2], '' ) ) {
						return $m[0];
					}
					$placeholder = Placeholder_Builder::build_social( $info['service_id'], $info['label'], $category );
					// Placeholder before + hidden original element.
					$blocked = $m[1] . $m[2] . ' data-faz-category="' . esc_attr( $category ) . '">';
				return $placeholder . self::faz_add_hidden_class( $blocked );
				},
				$content
			);
			// preg_replace_callback() returns null on a PCRE error. Preserve the
			// original HTML so content filters and the full-page output buffer can
			// never turn the response into an empty page.
			if ( null !== $result ) {
				$content = $result;
			} else {
				error_log( '[FAZ Cookie Manager] PCRE error ' . preg_last_error() . ' in process_social_embeds (' . $class . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// Smash Balloon Instagram Feed renders as <div id="sb_instagram" ...>
		// (or "sb_instagram_<n>" when multiple feeds are on one page) rather
		// than a class-based embed like the ones above, so it needs an
		// id-anchored match instead of a class-anchored one. Same block/
		// placeholder logic otherwise - mirrors the Known_Providers entry
		// "smash-balloon-instagram" (distinct from the generic "instagram"
		// oEmbed entry above, since it is blocked by its own script pattern).
		$social_ids = array(
			'sb_instagram' => array( 'service_id' => 'smash-balloon-instagram', 'label' => 'Instagram', 'category' => 'marketing' ),
		);
		// Stand down when Instagram Feed already removed the third-party surface
		// itself. Blocking a feed that makes no third-party request protects
		// nobody and costs the visitor a placeholder they must click for nothing.
		$sb_self_restricts = self::smash_balloon_self_restricts();

		foreach ( $social_ids as $id_prefix => $info ) {
			if ( false === stripos( $content, $id_prefix ) ) {
				continue;
			}

			$category     = $info['category'];
			$should_block = in_array( $category, $blocked_categories, true );

			// Applied here, inside the loop, rather than by dropping the entry
			// before it: dropping it would also skip the per-service check
			// below, so a visitor who explicitly denied smash-balloon-instagram
			// would get the feed anyway while the preference-centre toggle
			// showed it off. An explicit denial is a stronger and more specific
			// signal than an inferred redundancy, and the rule everywhere else
			// in this plugin is that the most restrictive explicit answer wins.
			// So this only clears the CATEGORY-level block, and leaves the
			// per-service decision free to put it back.
			if ( $sb_self_restricts && 'smash-balloon-instagram' === $info['service_id'] ) {
				$should_block = false;
			}

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

			// Insert a placeholder BEFORE the widget container, and hide it.
			$result = preg_replace_callback(
				'#(<(?:div|blockquote|span)\b)([^>]*\bid\s*=\s*["\']' . preg_quote( $id_prefix, '#' ) . '[^"\']*["\'][^>]*)>#i',
				function ( $m ) use ( $category, $info ) {
					// Skip if already processed.
					if ( false !== strpos( $m[2], 'data-faz-category' ) ) {
						return $m[0];
					}
					// Honour the admin's exemptions here too. Social containers
					// were the one blocking path that consulted neither the
					// whitelist nor class="faz-skip", so a site owner who wanted
					// one embed through had no way to say so — reported on wp.org
					// by somebody who tried exactly that with the container id and
					// class, and got nothing. Every other filter in this class
					// treats those as binding; this one now does too.
					if ( $this->is_whitelisted( $m[2], '' ) ) {
						return $m[0];
					}
					$placeholder = Placeholder_Builder::build_social( $info['service_id'], $info['label'], $category );
					// Placeholder before + hidden original element.
					$blocked = $m[1] . $m[2] . ' data-faz-category="' . esc_attr( $category ) . '">';
					return $placeholder . self::faz_add_hidden_class( $blocked );
				},
				$content
			);
			// A PCRE failure returns null. filter_content_blocking() is hooked
			// to the_content / widget_text / widget_block_content, and a null
			// return there does not pass the content through — it renders as an
			// empty string, silently blanking the post body. Keep the unblocked
			// HTML instead, matching the scripts/iframes passes in
			// filter_content_blocking().
			if ( null !== $result ) {
				$content = $result;
			} else {
				error_log( '[FAZ Cookie Manager] PCRE error ' . preg_last_error() . ' in process_social_embeds (' . $id_prefix . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		return $content;
	}

	/**
	 * Process Elementor "Video" widgets.
	 *
	 * Elementor's native Video widget renders an EMPTY `<div class="elementor-video">`
	 * server-side and builds the real iframe entirely client-side, reading the
	 * source URL from the `youtube_url` / `vimeo_url` / … key inside the
	 * ancestor widget's `data-settings` JSON attribute - never from a `src`
	 * attribute. The generic <iframe> blocker (process_iframe_tag) therefore
	 * never sees anything to match, because there is no iframe in the initial
	 * HTML at all. Detect the widget wrapper directly instead: parse its
	 * data-settings JSON for the embed URL, resolve the provider the same way
	 * the iframe blocker does, and gate the whole wrapper - mirroring
	 * process_social_embeds() (placeholder before + faz-hidden original).
	 *
	 * @param string $content            HTML content.
	 * @param array  $blocked_categories Blocked category slugs.
	 * @param array  $providers          Provider match table.
	 * @return string Modified content.
	 */
	private function process_elementor_video_widgets( $content, $blocked_categories, $providers = array() ) {
		if ( false === stripos( $content, 'elementor-widget-video' ) ) {
			return $content;
		}

		$result = preg_replace_callback(
			'#<div\b(?=[^>]*\bclass\s*=\s*["\'][^"\']*\belementor-widget-video\b)(?=[^>]*\bdata-settings\s*=)([^>]*)>#i',
			function ( $m ) use ( $blocked_categories, $providers ) {
				$attrs = $m[1];

				// Skip if already processed.
				if ( false !== strpos( $attrs, 'data-faz-category' ) ) {
					return '<div' . $attrs . '>';
				}

				$raw_settings = $this->extract_tag_attr( $attrs, 'data-settings' );
				if ( '' === $raw_settings ) {
					return '<div' . $attrs . '>';
				}

				// data-settings is HTML-entity-encoded JSON (Elementor escapes it
				// for the attribute) - decode entities before json_decode.
				$settings = json_decode( html_entity_decode( $raw_settings, ENT_QUOTES ), true );
				if ( ! is_array( $settings ) ) {
					return '<div' . $attrs . '>';
				}

				// The Video widget stores the source URL under one of these keys
				// depending on the selected "video_type".
				$url = '';
				foreach ( array( 'youtube_url', 'vimeo_url', 'dailymotion_url', 'external_url' ) as $key ) {
					if ( ! empty( $settings[ $key ] ) ) {
						$url = (string) $settings[ $key ];
						break;
					}
				}
				if ( '' === $url ) {
					return '<div' . $attrs . '>';
				}

				$service_id = Placeholder_Builder::detect_service_from_url( $url );
				if ( 'default' === $service_id ) {
					return '<div' . $attrs . '>'; // Self-hosted / unrecognised source - nothing to gate.
				}

				// The widget is judged by the same rules as every other embed.
				// Its wrapper carries the classes (Elementor's Advanced → CSS
				// Classes lands here, faz-skip included) and the id. The video
				// URL lives in data-settings as the editor typed it
				// (youtube.com/watch?v=…, vimeo.com/123), while the browser
				// loads the embed URL Elementor derives from it
				// (youtube.com/embed/…, player.vimeo.com/video/…). A rule
				// written for the plain iframe targets the latter, so both are
				// presented as a src — the embed first, exactly as an iframe to
				// it would be judged, then the stored URL.
				$src_candidates = array();
				foreach ( array_unique( array_filter( array( $this->elementor_video_embed_url( $service_id, $settings ), $url ) ) ) as $candidate ) {
					$src_candidates[] = 'src="' . esc_attr( $candidate ) . '"';
				}
				if ( $this->is_whitelisted( $attrs, '' ) ) {
					return '<div' . $attrs . '>';
				}
				foreach ( $src_candidates as $src_attrs ) {
					if ( $this->is_whitelisted( $src_attrs, '' ) ) {
						return '<div' . $attrs . '>';
					}
				}

				// The category comes from the merged provider map — the saved
				// cookie list, the catalogue, the admin's Script Blocking rules
				// and the faz_blocking_rules filter — like a plain iframe to the
				// same URL. Reading the catalogue alone made YouTube marketing
				// here whatever the site had set. The catalogue remains the
				// fallback when nothing in the map matches.
				$category = false;
				foreach ( $src_candidates as $src_attrs ) {
					$category = $this->match_script_to_provider( $src_attrs, '', $providers, $blocked_categories );
					if ( $category ) {
						break;
					}
				}
				if ( ! $category ) {
					$known    = Known_Providers::get_all();
					$category = isset( $known[ $service_id ]['category'] ) ? $known[ $service_id ]['category'] : 'marketing';
				}

				$should_block = in_array( $category, $blocked_categories, true );

				// Per-service consent check: override category-level decision.
				$service_consent = $this->get_service_consent();
				if ( isset( $service_consent[ $service_id ] ) ) {
					if ( 'yes' === $service_consent[ $service_id ] ) {
						$should_block = false;
					} elseif ( 'no' === $service_consent[ $service_id ] ) {
						$should_block = true;
					}
				}

				if ( ! $should_block ) {
					return '<div' . $attrs . '>';
				}

				$label       = Placeholder_Builder::get_service_name( $service_id );
				$placeholder = Placeholder_Builder::build_social( $service_id, $label, $category );
				$blocked     = '<div' . $attrs . ' data-faz-category="' . esc_attr( $category ) . '">';
				return $placeholder . self::faz_add_hidden_class( $blocked );
			},
			$content
		);

		return null !== $result ? $result : $content;
	}

	/**
	 * The embed URL an Elementor Video widget loads in the browser.
	 *
	 * Elementor stores the URL as the editor typed it and builds the iframe
	 * client-side from a fixed embed base per host. Only the base matters here:
	 * provider patterns are host/path fragments, never video ids.
	 *
	 * @param string $service_id Service detected from the stored URL.
	 * @param array  $settings   Decoded data-settings of the widget.
	 * @return string Embed base URL, or '' when the host has none.
	 */
	private function elementor_video_embed_url( $service_id, array $settings ) {
		switch ( $service_id ) {
			case 'youtube':
				$privacy = isset( $settings['yt_privacy'] ) && 'yes' === $settings['yt_privacy'];
				return $privacy ? 'https://www.youtube-nocookie.com/embed/' : 'https://www.youtube.com/embed/';
			case 'vimeo':
				return 'https://player.vimeo.com/video/';
			case 'dailymotion':
				return 'https://www.dailymotion.com/embed/video/';
		}
		return '';
	}

	/**
	 * Give Bricks' JavaScript Google Maps container the same consent UI as an iframe.
	 * Keep its options inert until consent so Bricks cannot initialise it early.
	 *
	 * @param string $content HTML content.
	 * @param array $blocked_categories Blocked category slugs.
	 * @param array $providers Merged provider category map.
	 * @return string
	 */
	private function process_bricks_map_widgets( $content, $blocked_categories, $providers ) {
		if ( false === stripos( $content, 'data-bricks-map-options' ) ) {
			return $content;
		}
		$result = preg_replace_callback(
			'#<div\b(?=[^>]*\bclass\s*=\s*["\'][^"\']*\bbrxe-map\b)([^>]*)>#i',
			function ( $m ) use ( $blocked_categories, $providers ) {
				$attrs = $m[1];
				if ( ! preg_match( '/\sdata-bricks-map-options\s*=/i', $attrs ) || false !== stripos( $attrs, 'data-faz-category' ) ) {
					return $m[0];
				}
				// The widget stands for the API script Bricks enqueues for it, so
				// it is judged as that tag: its handle id and its callback query
				// included, so an exemption or rule written on either (as the
				// Script Blocking screen shows the tag) covers the widget too.
				$src = 'id="bricks-google-maps-js" src="https://maps.googleapis.com/maps/api/js?callback=bricksMap"';
				if ( $this->is_whitelisted( $attrs, '' ) || $this->is_whitelisted( $src, '' ) ) {
					return $m[0];
				}
				$category = $this->match_script_to_provider( $src, '', $providers, $blocked_categories );
				if ( ! $category ) {
					$known = Known_Providers::get_all();
					$category = $known['google-maps']['category'] ?? 'functional';
				}
				$blocked = in_array( $category, $blocked_categories, true );
				$consent = $this->get_service_consent();
				if ( isset( $consent['google-maps'] ) ) {
					$blocked = 'yes' !== $consent['google-maps'];
				}
				if ( ! $blocked ) {
					return $m[0];
				}
				$attrs = preg_replace( '/\sdata-bricks-map-options\s*=/i', ' data-faz-bricks-map-options=', $attrs, 1 );
				$widget = '<div' . $attrs . ' data-faz-category="' . esc_attr( $category ) . '" data-faz-service="google-maps">';
				return Placeholder_Builder::build_social( 'google-maps', Placeholder_Builder::get_service_name( 'google-maps' ), $category ) . self::faz_add_hidden_class( $widget );
			},
			$content
		);
		return null !== $result ? $result : $content;
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
		// Every branch coalesces to $html because preg_replace() returns null on
		// a PCRE error, and this method declares a non-nullable string return —
		// so an unguarded null would be a TypeError, i.e. a fatal on the front
		// end, rather than merely a missing class. Returning the markup
		// unchanged only costs the hidden class: the element is still a blocked
		// placeholder, so nothing is exposed.
		if ( preg_match( '/\bclass\s*=\s*"/', $html ) ) {
			return preg_replace( '/\bclass\s*=\s*"([^"]*)"/i', 'class="$1 faz-hidden"', $html, 1 ) ?? $html;
		}
		if ( preg_match( '/\bclass\s*=\s*\'([^\']*)\'/i', $html ) ) {
			return preg_replace( '/\bclass\s*=\s*\'([^\']*)\'/i', 'class=\'$1 faz-hidden\'', $html, 1 ) ?? $html;
		}
		return preg_replace( '/(<\w+)(\s|>)/', '$1 class="faz-hidden"$2', $html, 1 ) ?? $html;
	}

	/**
	 * Neutralise one script tag and retain the original markup on PCRE failure.
	 *
	 * @param string $tag      Complete script tag.
	 * @param string $category Consent category.
	 * @param string $context  Diagnostic context for the error log.
	 * @return string
	 */
	private function block_script_tag_safely( string $tag, string $category, string $context ): string {
		$blocked = preg_replace_callback(
			'#<script(?=[\s/>])(' . self::ATTR_WALK . ')(?:/\s*+)?>#i',
			function ( $match ) use ( $category ) {
				return '<script' . $this->label_category_first( $this->set_script_type_plain( $match[1] ), $category ) . '>';
			},
			$tag,
			1
		);
		if ( null !== $blocked ) {
			return $blocked;
		}

		// Returning null from a WordPress tag filter removes the resource
		// entirely. Keep the tag inert instead, without relying on PCRE again.
		$error = preg_last_error();
		$tag   = self::block_script_tag_without_regex( $tag, $category );
		error_log( '[FAZ Cookie Manager] PCRE error ' . $error . ' while blocking ' . $context ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return $tag;
	}

	/**
	 * Fail-closed script neutralisation for the rare case where PCRE itself is
	 * unavailable (for example, an exhausted backtrack limit).
	 *
	 * This deliberately uses only string operations. Injecting text/plain
	 * before the existing attributes makes the script inert, while preserving
	 * a non-default original type lets the frontend restore modules and other
	 * specialised script types after consent.
	 *
	 * @param string $tag      Complete script tag.
	 * @param string $category Consent category.
	 * @return string
	 */
	private static function block_script_tag_without_regex( string $tag, string $category ): string {
		$script_pos = stripos( $tag, '<script' );
		if ( false === $script_pos ) {
			return $tag;
		}

		$open_end    = strpos( $tag, '>', $script_pos );
		$opening_tag = false === $open_end
			? substr( $tag, $script_pos )
			: substr( $tag, $script_pos, $open_end - $script_pos + 1 );
		$attrs       = substr( $opening_tag, strlen( '<script' ), -1 );
		$replacement = '<script' . self::script_attrs_type_plain_without_regex( $attrs );
		if ( '' !== $category && false === stripos( $opening_tag, 'data-faz-category' ) ) {
			$replacement = '<script data-faz-category="' . esc_attr( $category ) . '"' . substr( $replacement, strlen( '<script' ) );
		}

		return substr_replace( $tag, $replacement . '>', $script_pos, strlen( $opening_tag ) );
	}

	/**
	 * Prefix script attributes with an inert type without using PCRE.
	 *
	 * @param string $attrs Script attributes.
	 * @return string
	 */
	private static function script_attrs_type_plain_without_regex( string $attrs ): string {
		$opening_tag  = '<script' . $attrs . '>';
		$original_type = self::script_type_without_regex( $opening_tag );
		$replacement   = ' type="text/plain"';
		if ( '' !== $original_type
			&& 'text/plain' !== strtolower( $original_type )
			&& 'text/javascript' !== strtolower( $original_type )
			&& false === stripos( $attrs, 'data-faz-original-type' ) ) {
			$replacement .= ' data-faz-original-type="' . esc_attr( $original_type ) . '"';
		}
		return $replacement . $attrs;
	}

	/**
	 * Rename a stylesheet href without PCRE so a regex failure cannot remove
	 * the entire link tag from WordPress output.
	 *
	 * @param string $tag      Complete link tag.
	 * @param string $category Consent category.
	 * @return string
	 */
	private static function block_link_tag_without_regex( string $tag, string $category ): string {
		$link_pos = stripos( $tag, '<link' );
		if ( false === $link_pos ) {
			return $tag;
		}
		$open_end = strpos( $tag, '>', $link_pos );
		$opening  = false === $open_end
			? substr( $tag, $link_pos )
			: substr( $tag, $link_pos, $open_end - $link_pos + 1 );
		$href_pos = self::attribute_name_position_without_regex( $opening, 'href' );
		if ( false !== $href_pos ) {
			$tag = substr_replace( $tag, 'data-faz-href', $link_pos + $href_pos, strlen( 'href' ) );
		}
		if ( false === stripos( $opening, 'data-faz-category' ) ) {
			$tag = substr_replace(
				$tag,
				'<link data-faz-category="' . esc_attr( $category ) . '"',
				$link_pos,
				strlen( '<link' )
			);
		}
		return $tag;
	}

	/**
	 * Locate a standalone attribute name in an opening HTML tag.
	 *
	 * @param string $opening_tag Opening tag.
	 * @param string $attribute   Attribute name.
	 * @return int|false
	 */
	private static function attribute_name_position_without_regex( string $opening_tag, string $attribute ) {
		$length = strlen( $opening_tag );
		$cursor = 0;
		$attr_length = strlen( $attribute );
		while ( $cursor < $length ) {
			$pos = stripos( $opening_tag, $attribute, $cursor );
			if ( false === $pos ) {
				return false;
			}
			$before = $pos > 0 ? $opening_tag[ $pos - 1 ] : '';
			$after  = $pos + $attr_length < $length ? $opening_tag[ $pos + $attr_length ] : '';
			if ( ctype_space( $before ) && ( '=' === $after || ctype_space( $after ) ) ) {
				$value_pos = $pos + $attr_length;
				while ( $value_pos < $length && ctype_space( $opening_tag[ $value_pos ] ) ) {
					$value_pos++;
				}
				if ( $value_pos < $length && '=' === $opening_tag[ $value_pos ] ) {
					return $pos;
				}
			}
			$cursor = $pos + $attr_length;
		}
		return false;
	}

	/**
	 * Read the opening script tag's type attribute without PCRE.
	 *
	 * Handles quoted and unquoted HTML attribute values and ignores names such
	 * as data-type. It is intentionally narrow: the normal path uses the common
	 * attribute helpers; this parser exists only for the PCRE-failure fallback.
	 *
	 * @param string $opening_tag Opening script tag.
	 * @return string
	 */
	private static function script_type_without_regex( string $opening_tag ): string {
		$length = strlen( $opening_tag );
		$cursor = 0;
		while ( $cursor < $length ) {
			$pos = stripos( $opening_tag, 'type', $cursor );
			if ( false === $pos ) {
				return '';
			}

			$attribute_pos = self::attribute_name_position_without_regex( $opening_tag, 'type' );
			if ( false === $attribute_pos || $attribute_pos !== $pos ) {
				$cursor = $pos + 4;
				continue;
			}

			$value_pos = $pos + 4;
			while ( $value_pos < $length && ctype_space( $opening_tag[ $value_pos ] ) ) {
				$value_pos++;
			}
			if ( $value_pos >= $length || '=' !== $opening_tag[ $value_pos ] ) {
				$cursor = $pos + 4;
				continue;
			}
			$value_pos++;
			while ( $value_pos < $length && ctype_space( $opening_tag[ $value_pos ] ) ) {
				$value_pos++;
			}
			if ( $value_pos >= $length ) {
				return '';
			}

			$quote = $opening_tag[ $value_pos ];
			if ( '"' === $quote || "'" === $quote ) {
				$value_pos++;
				$value_end = strpos( $opening_tag, $quote, $value_pos );
				return false === $value_end ? '' : substr( $opening_tag, $value_pos, $value_end - $value_pos );
			}

			$value_end = $value_pos;
			while ( $value_end < $length
				&& ! ctype_space( $opening_tag[ $value_end ] )
				&& '>' !== $opening_tag[ $value_end ] ) {
				$value_end++;
			}
			return substr( $opening_tag, $value_pos, $value_end - $value_pos );
		}
		return '';
	}
}
