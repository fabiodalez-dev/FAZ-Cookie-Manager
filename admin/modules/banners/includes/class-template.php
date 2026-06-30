<?php
/**
 * Banner template class
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Admin\Modules\Banners\Includes
 */

namespace FazCookie\Admin\Modules\Banners\Includes;

use DOMDocument;
use DOMXPath;
use FazCookie\Includes\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles templating of Cookie banner elements
 *
 * @version     3.0.0
 * @package     FazCookie\Admin\Modules\Banners\Includes
 */
class Template {

	/**
	 * Banner properties
	 *
	 * @var array
	 */
	protected $properties;

	/**
	 * Template styles
	 *
	 * @var string
	 */
	protected $styles = '';

	/**
	 * Template HTML
	 *
	 * @var string
	 */
	protected $html = '';

	/**
	 * Template type, by default it will be banner
	 *
	 * @var string
	 */
	protected $type = 'banner';

	protected $ptype = 'popup';

	/**
	 * Whether this is a banner+pushdown combo (uses classic template without inline toggles).
	 *
	 * @var bool
	 */
	protected $banner_pushdown = false;

	/**
	 * Theme presets to be applied on the template
	 *
	 * @var array
	 */
	protected $presets = array();

	/**
	 * Type of theme dark/light
	 *
	 * @var string
	 */
	protected $theme;

	/**
	 * Template ID
	 *
	 * @var string|int
	 */
	protected $id;

	/**
	 * Banner object
	 *
	 * @var object
	 */
	protected $banner;

	/**
	 * Template config
	 *
	 * @var array
	 */
	protected $template;

	/**
	 * Object cache group
	 *
	 * @var string
	 */
	protected $cache_group = 'banner_template';

	/**
	 * Language of the template
	 *
	 * @var string
	 */
	protected $language = 'en';

	/**
	 * Memoized result of resolve_build_locale() for this request.
	 *
	 * Pinned on first call (which happens in get_layout_signature() before any
	 * switch_to_locale()), so the cache-signature read in load(), the locale
	 * generate() builds with, and the signature re-read in update() all agree
	 * even if get_locale() shifts mid-request. null = not yet resolved.
	 *
	 * @var string|null
	 */
	private $resolved_build_locale = null;

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Return the current instance of the class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor function
	 *
	 * @param object|false $banner   Banner object.
	 * @param string|null  $language Optional explicit language override. When
	 *                               omitted, falls back to faz_current_language().
	 *                               Used by the REST banner endpoint to render
	 *                               the template in a visitor-specific language
	 *                               without touching the request-level cache.
	 */
	public function __construct( $banner = false, $language = null ) {
		// Normalise the language override before using it as an option-array
		// key. Callers (e.g. the REST controller) already validate against
		// faz_selected_languages(), but defence-in-depth: we sanitise here
		// so every entry point is safe.
		$language       = is_string( $language ) ? trim( sanitize_text_field( $language ) ) : '';
		$this->language = '' !== $language ? $language : faz_current_language();
		if ( $banner ) {
			// Runtime compatibility migrations for banners saved before the
			// CCPA Classic-guard / law-content-sync shipped: move a Classic (or
			// Full-width + Pushdown) CCPA/Both banner to a popup-capable layout
			// and re-sync the law-appropriate notice copy, so the Do-Not-Sell
			// opt-out works on legacy rows without an admin re-save.
			if ( is_callable( array( $banner, 'apply_runtime_layout_compatibility' ) ) ) {
				$banner->apply_runtime_layout_compatibility();
			}
			if ( is_callable( array( $banner, 'apply_runtime_law_content_compatibility' ) ) ) {
				$banner->apply_runtime_law_content_compatibility();
			}
			$this->banner     = $banner;
			$this->properties = $banner->get_settings();
			$this->load();
		}
		add_action( 'faz_after_update_banner', array( $this, 'clear_template' ) );
		add_action( 'faz_after_update_cookie_category', array( $this, 'clear_template' ) );
		add_action( 'faz_after_delete_cookie_category', array( $this, 'clear_template' ) );
		add_action( 'faz_after_update_cookie', array( $this, 'clear_template' ) );
		add_action( 'faz_after_create_cookie', array( $this, 'clear_template' ) );
		add_action( 'faz_after_delete_cookie', array( $this, 'clear_template' ) );
		add_action( 'faz_after_update_settings', array( $this, 'clear_template' ), 10, 1 );
		add_action( 'faz_clear_cache', array( $this, 'clear_template' ) );
	}

	/**
	 * Get or Set templates based on the condition.
	 *
	 * @return void
	 */
	public function load() {
		$stored = $this->get_stored();
		if ( true === $this->is_preview()
			|| empty( $stored )
			|| ! isset( $stored['layout_signature'] )
			|| $this->get_layout_signature() !== $stored['layout_signature'] ) {
			$this->generate();
		} else {
			$this->set_template();
		}
	}
	/**
	 * Returns the content html template from the configs.
	 *
	 * @return void
	 */
	public function generate() {
		// Switch WP locale to the banner's target language *before* any
		// `__( '...', 'faz-cookie-manager' )` runs inside prepare_html() or
		// the shortcode registration below. Without this, a site with WP
		// locale en_US and faz_settings.languages.default=de would cache a
		// banner template under the `[de]` key that still contains English
		// strings like "We value your privacy" — because gettext resolves
		// against the runtime locale, not the plugin's language setting.
		// See GitHub issue tracking banner German-only regression.
		$locale_switched = false;
		$target_locale   = $this->resolve_build_locale();
		if ( $target_locale && function_exists( 'switch_to_locale' ) && $target_locale !== get_locale() ) {
			$locale_switched = switch_to_locale( $target_locale );
		}

		$settings    = isset( $this->properties['settings'] ) ? $this->properties['settings'] : array();
		$this->id    = isset( $settings['versionID'] ) ? $settings['versionID'] : 'default';
		$this->type  = isset( $settings['type'] ) ? $settings['type'] : 'box';
		$this->ptype = isset( $settings['preferenceCenterType'] ) ? $settings['preferenceCenterType'] : 'popup';
		// Banner + pushdown uses the classic template (which has preference-wrapper for
		// pushdown expansion) but without inline category toggles.
		$this->banner_pushdown = ( $this->type === 'banner' && $this->ptype === 'pushdown' );
		if ( $this->banner_pushdown ) {
			$this->type = 'classic';
			if ( isset( $this->properties['config']['categoryPreview'] ) ) {
				$this->properties['config']['categoryPreview']['status'] = false;
			}
		} elseif ( $this->type === 'classic' ) {
			$this->ptype = 'pushdown';
			// Classic type requires inline category preview toggles
			if ( isset( $this->properties['config']['categoryPreview'] ) ) {
				$this->properties['config']['categoryPreview']['status'] = true;
			}
		}
		$this->theme = isset( $settings['theme'] ) ? $settings['theme'] : 'light';

		if ( strpos($this->ptype, 'sidebar') !== false ) {
			if ( $this->type === "banner" ) {
				$this->type = "banner-sidebar";
			}
			if ( $this->type === "box" ) {
				$this->type = "box-sidebar";
			}
		}

		$templates     = $this->get_templates( $this->id );
		$this->presets = $this->get_presets( $this->id );
		foreach ( $templates as $template ) {
			$type = isset( $template['type'] ) ? $template['type'] : '';
			if ( $type === $this->type ) {
				$this->template = $template;
				$this->styles   = isset( $this->template['css'] ) ? $this->template['css'] : '';
				break;
			}
		}
		new \FazCookie\Frontend\Modules\Shortcodes\Shortcodes( $this->banner, $this->id );
		$this->prepare_html();

		if ( false === $this->is_preview() ) {
			$this->update();
		}

		// Pair the earlier switch_to_locale(). Restoring inside generate()
		// (not in __construct) keeps the scope minimal: only the template
		// generation path sees the alternate locale, so admin menu strings,
		// REST responses, etc. are not affected.
		if ( $locale_switched && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}
	}

	/**
	 * Resolve the WP locale to switch to while building the cached banner
	 * template, so that bundled-default plugin strings wrapped in
	 * `__( '...', 'faz-cookie-manager' )` (e.g. "Always Active" and the
	 * cookie-audit-table headers "Cookie"/"Duration"/"Description") resolve
	 * correctly.
	 *
	 * Normally this is the banner's own language ($this->language). But on a
	 * single-language install left at the un-configured English default,
	 * `faz_current_language()` returns 'en' regardless of the WordPress site
	 * locale, so the template was force-built in en_US and those chrome
	 * strings stayed English even on a sk_SK / de_DE site — while runtime
	 * strings ("Show more"/"Show less", resolved outside the cache) DID
	 * translate. Reported for sk_SK on wordpress.org.
	 *
	 * Fix: when the banner language is the un-configured 'en' default AND the
	 * install is not running a URL-based multilingual plugin AND the WordPress
	 * site locale is non-English, build in the WordPress site locale. The
	 * banner *content* still comes from $this->language ('en' → bundled
	 * English copy); only the gettext chrome follows the site language. An
	 * explicit non-English banner language (e.g. FAZ default 'de' on a WP
	 * en_US site) is untouched. The site locale is invariant per site, so
	 * this stays full-page-cache safe.
	 *
	 * @return string Target WP locale (e.g. 'sk_SK'), or '' to skip switching.
	 */
	private function resolve_build_locale() {
		// Memoize: pin the value on first call so the three callsites
		// (load() signature, generate() switch, update() signature) cannot
		// diverge if get_locale() shifts inside the switch_to_locale() window.
		if ( null !== $this->resolved_build_locale ) {
			return $this->resolved_build_locale;
		}

		$faz_locale = function_exists( 'faz_wp_locale' ) ? faz_wp_locale( $this->language ) : '';
		$resolved   = $faz_locale;

		if (
			'en' === $this->language
			&& function_exists( 'faz_i18n_is_multilingual' )
			&& ! faz_i18n_is_multilingual()
		) {
			$wp_locale = get_locale();
			if ( is_string( $wp_locale ) && '' !== $wp_locale && 0 !== strpos( $wp_locale, 'en' ) ) {
				$resolved = $wp_locale;
			}
		}

		$this->resolved_build_locale = $resolved;
		return $resolved;
	}

	/**
	 * Get presets by template version
	 *
	 * @param integer $id Template version.
	 * @return array
	 */
	public function get_presets( $id ) {
		$this->id = isset( $id ) ? $id : 0;
		$key      = '_preset_' . $id;
		$presets  = Cache::get( $key, $this->cache_group );
		$presets  = ( isset( $presets ) && is_array( $presets ) ) ? $presets : array();
		if ( empty( $presets ) ) {
			$presets = $this->load_presets();
			Cache::set( $key, $this->cache_group, $presets, false );
		}
		return $presets;
	}

	/**
	 * Get templates by template version
	 *
	 * @param integer $id Template version.
	 * @return array
	 */
	public function get_templates( $id ) {
		$this->id  = isset( $id ) ? $id : 0;
		$key       = '_template_' . $this->id;
		$templates = Cache::get( $key, $this->cache_group );
		$templates = ( isset( $templates ) && is_array( $templates ) ) ? $templates : array();

		if ( empty( $templates ) ) {
			$templates = $this->load_templates();
			Cache::set( $key, $this->cache_group, $templates, false );
		}
		return $templates;
	}
	/**
	 * Returns the template styles
	 *
	 * @return string
	 */
	public function get_styles() {
		if ( ! $this->styles ) {
			return '';
		}

		return $this->styles;
	}
	/**
	 * Get template HTML
	 *
	 * @return string
	 */
	public function get_html() {
		if ( ! $this->html ) {
			return '';
		}
		return wp_kses( $this->html, faz_allowed_html() );
	}

	/**
	 * Get the template config and presets
	 *
	 * @return array
	 */
	private function load_templates() {
		return faz_read_json_file( dirname( __FILE__ ) . '/templates/' . sanitize_file_name( $this->id ) . '/template.json' );
	}

	/**
	 * Load presets from plugin itself.
	 *
	 * @return array
	 */
	private function load_presets() {
		return faz_read_json_file( dirname( __FILE__ ) . '/templates/' . sanitize_file_name( $this->id ) . '/theme.json' );
	}

	/**
	 * Clear templates and preset from transient.
	 *
	 * @return void
	 */
	public function reset() {
		Cache::delete( $this->cache_group );
	}
	/**
	 * Publicly available function clear template cache.
	 *
	 * @return void
	 */
	public function delete_cache() {
		if ( faz_is_admin_request() ) {
			$this->reset();
		}
	}
	/**
	 * Returns the template HTML after processing the shortcodes
	 *
	 * @return string
	 */
	private function prepare_html() {
		$html     = '';
		$colors   = array();
		$template = isset( $this->template['html'] ) ? $this->template['html'] : '';
		if ( '' === $template ) {
			return $html;
		}
		$html = do_shortcode( $template );
		if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			return $html;
		}
		try {
			$dom         = new DOMDocument();
			$used_errors = libxml_use_internal_errors( true );
			if ( function_exists( 'mb_encode_numericentity' ) ) {
				$html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
			}
			$dom->loadHTML( $html, LIBXML_HTML_NODEFDTD );
			$used_errors || libxml_use_internal_errors( false );

			$finder     = new DOMXPath( $dom );

			// Add id="faz-consent" for CSS specificity isolation against page builders.
			$container = $finder->query( '//div[contains(@class,"faz-consent-container")]' )->item( 0 );
			if ( $container instanceof \DOMElement ) {
				$container->setAttribute( 'id', 'faz-consent' );
			}

			// Banner + pushdown: strip inline category preview (only classic shows it).
			if ( $this->banner_pushdown ) {
				$preview_nodes = $finder->query( '//*[contains(@class, "faz-category-direct-preview-wrapper")]' );
				foreach ( $preview_nodes as $node ) {
					$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				}
			}

			$elements   = $finder->query( '//*[@data-faz-tag]' );
			$properties = $this->properties;
			$configs    = isset( $properties['config'] ) ? $properties['config'] : array();

			if ( ! empty( $this->presets ) ) {
				foreach ( $this->presets as $preset ) {
					$theme = isset( $preset['name'] ) ? $preset['name'] : '';
					if ( $theme === $this->theme ) {
						$colors = ( isset( $preset['settings'] ) && is_array( $preset['settings'] ) ) ? $preset['settings'] : array();
						break;
					}
				}
			}

			if ( ! empty( $colors ) ) {
				// Preset provides base colours; DB config (user customizations) can override.
				// When the user switches themes, banner.js clears saved styles so the
				// new preset applies cleanly. After that, any Colours-tab edits are
				// stored in DB config and win over the preset until the next theme switch.
				$configs = array_replace_recursive( $colors, $configs );
			}

			$css_vars = array();

			foreach ( $elements as $element ) {
				if ( ! $element instanceof \DOMElement ) {
					continue;
				}
				$tag = $element->getAttribute( 'data-faz-tag' );
				if ( empty( $tag ) ) {
					continue;
				}
				if ( in_array( $tag, $this->image_tags(), true ) ) {
					$img_tags = $element->getELementsByTagName( 'img' );
					foreach ( $img_tags as $img ) {
						$src = $this->get_assets_path( $img->getAttribute( 'src' ) );
						$img->setAttribute( 'src', $src );
					}
				}
				$config  = faz_array_search( $configs, 'tag', $tag );
				// "Do Not Sell" button: hide when applicableLaw is 'gdpr' (only show for ccpa/both).
				if ( 'donotsell-button' === $tag ) {
					$law = isset( $properties['settings']['applicableLaw'] ) ? $properties['settings']['applicableLaw'] : 'gdpr';
					if ( 'gdpr' === $law && ( ! isset( $config['status'] ) || false === $config['status'] ) ) {
						$element->parentNode->removeChild( $element ); //phpcs:ignore WordPress.NamingConventions.ValidVariableName
						continue;
					}
				}
				// Close button: Garante Privacy Provv. 10/06/2021 + EDPB Guidelines
				// 03/2022 — hide the X when the Reject button is present. Two
				// dismissal paths of different visual weight (X = neutral-looking,
				// Reject = labelled) on the same banner constitutes a recognised
				// dark pattern (ambiguità multipla scelta).
				//
				// Per-banner opt-out (1.14.0+): the admin can override this on a
				// single banner by setting settings.allowCloseButtonWithReject =
				// true. Use case: multi-banner geo-routing serves a CCPA-style
				// banner to US visitors (where the dark-pattern rule does not
				// apply) — that banner can keep the X next to Reject without
				// breaking compliance on the EU-served banner, which keeps the
				// default behaviour. The flag is per-banner row, so it travels
				// with the geo-routing config and never affects other banners.
				if ( 'close-button' === $tag ) {
					$reject_cfg     = faz_array_search( $configs, 'tag', 'reject-button' );
					$reject_enabled = $reject_cfg && ( ! isset( $reject_cfg['status'] ) || true === $reject_cfg['status'] );
					$allow_override = ! empty( $properties['settings']['allowCloseButtonWithReject'] );
					if ( $reject_enabled && ! $allow_override ) {
						$element->parentNode->removeChild( $element ); //phpcs:ignore WordPress.NamingConventions.ValidVariableName
						continue;
					}
				}
				// Brand logo: set img src from config meta.url (custom upload).
				if ( 'brand-logo' === $tag && isset( $config['meta']['url'] ) && '#' !== $config['meta']['url'] ) {
					$logo_imgs = $element->getElementsByTagName( 'img' );
					foreach ( $logo_imgs as $logo_img ) {
						$logo_img->setAttribute( 'src', esc_url( $config['meta']['url'] ) );
					}
				}
				$preview = $this->is_preview();
				$enabled = isset( $config['status'] ) && false === $preview ? $config['status'] : true;

				// Category toggles are required for GDPR granular consent — never remove.
				if ( false === $enabled && 'detail-category-toggle' !== $tag ) {
					$element->parentNode->removeChild( $element );  //phpcs:ignore WordPress.NamingConventions.ValidVariableName
					continue;
				}

				$styles = isset( $config['styles'] ) ? $config['styles'] : array();
				if ( ! empty( $styles ) ) {
					foreach ( $styles as $property => $value ) {
						if ( '' !== $value ) {
							$safe_tag = preg_replace( '/[^a-zA-Z0-9\-_]/', '-', $tag );
							$css_vars[ '--faz-' . $safe_tag . '-' . $property ] = $value;
						}
					}
				}
			}

			// The modal preference-center toggles (.faz-switch) read
			// --faz-toggle-active/inactive-background-color, which the per-element
			// loop above does not produce (the toggle's tag-derived var name does
			// not match). Source them from the same categoryPreview toggle config
			// the "Category Preview" colour pickers already write, so one control
			// colours both the inline preview toggles and the modal toggles.
			$cp_toggle = isset( $configs['categoryPreview']['elements']['toggle']['states'] )
				? $configs['categoryPreview']['elements']['toggle']['states']
				: array();
			if ( ! empty( $cp_toggle['active']['styles']['background-color'] ) ) {
				$css_vars['--faz-toggle-active-background-color'] = $cp_toggle['active']['styles']['background-color'];
			}
			if ( ! empty( $cp_toggle['inactive']['styles']['background-color'] ) ) {
				$css_vars['--faz-toggle-inactive-background-color'] = $cp_toggle['inactive']['styles']['background-color'];
			}

			if ( ! empty( $css_vars ) ) {
				$vars_string = '';
				foreach ( $css_vars as $var => $val ) {
					$vars_string .= esc_attr( $var ) . ':' . esc_attr( $val ) . ';';
				}
				// Emit vars on three selectors:
				// .faz-consent-container → boost_css_specificity() converts to #faz-consent
				//   covers banner-internal elements and classic-mode preference center
				// .faz-modal → left as-is (sibling element)
				//   covers popup-mode preference center and optout popup
				// .faz-btn-revisit-wrapper → left as-is (sibling element)
				//   covers the revisit consent floating button
				$this->styles = '.faz-consent-container{' . $vars_string . '}'
					. '.faz-modal{' . $vars_string . '}'
					. '.faz-btn-revisit-wrapper{' . $vars_string . '}'
					. $this->styles;
			}

			$this->html = $dom->saveHTML( $dom->documentElement ); //phpcs:ignore WordPress.NamingConventions.ValidVariableName
		} catch ( \Exception $e ) {
			// Could not generate the template.
			$this->html = $html;
		}
		return $this->html;
	}

	/**
	 * Check if banner is in preview mode.
	 *
	 * @return boolean
	 */
	public function is_preview() {
		return defined( 'FAZ_PREVIEW_REQUEST' ) && FAZ_PREVIEW_REQUEST;
	}
	/**
	 * Return the language-aware cache key for the banner template option.
	 *
	 * @return string
	 */
	private function get_cache_key() {
		return apply_filters( 'faz_banner_template_cache_key', 'faz_banner_template' );
	}

	/**
	 * Return the option-array slot for this banner/language template.
	 *
	 * Multi-banner geo-routing can render different banners in the same
	 * language. The stored template therefore needs to be scoped by banner id as
	 * well as language; otherwise the first rendered `en` banner can be reused
	 * for every other `en` banner.
	 *
	 * @return string
	 */
	private function get_storage_key() {
		$banner_id = ( $this->banner && is_callable( array( $this->banner, 'get_id' ) ) ) ? absint( $this->banner->get_id() ) : 0;
		if ( $banner_id > 0 ) {
			return 'banner_' . $banner_id . ':' . $this->language;
		}
		return $this->language;
	}

	/**
	 * Fingerprint the inputs that determine the cached template/CSS.
	 *
	 * @return string
	 */
	private function get_layout_signature() {
		$settings = isset( $this->properties['settings'] ) && is_array( $this->properties['settings'] )
			? $this->properties['settings']
			: array();
		$config   = isset( $this->properties['config'] ) && is_array( $this->properties['config'] )
			? $this->properties['config']
			: array();
		// Only the nested buttons.elements.donotSell branch survives sanitize_settings;
		// the legacy direct notice.elements.donotSell key is dropped, so don't read it.
		$do_not_sell = ! empty( $config['notice']['elements']['buttons']['elements']['donotSell']['status'] );
		// Resolve only the current language's description (cheap) instead of
		// get_contents() which re-sanitizes every selected language on a cache hit.
		$notice_description = $this->banner ? $this->banner->get_notice_description( $this->language ) : '';

		// Banner-control flags that change the generated preference-center markup
		// (the per-service / per-cookie toggle structure script.js hydrates).
		// Read from the global faz_settings option (autoloaded → cheap).
		$faz_settings   = get_option( 'faz_settings', array() );
		$banner_control = ( is_array( $faz_settings ) && isset( $faz_settings['banner_control'] ) && is_array( $faz_settings['banner_control'] ) )
			? $faz_settings['banner_control']
			: array();

		return md5(
			wp_json_encode(
				array(
					// Bump the cache whenever the plugin updates: the generated
					// template HTML (ids, classes, accordion structure) can change
					// between releases, and the stored template must never outlive
					// the JS that hydrates it. Without this a post-update install
					// keeps serving the previous version's markup to the new
					// script.js, silently breaking features such as the per-service
					// sub-toggles (issue #146).
					'plugin_version' => defined( 'FAZ_VERSION' ) ? FAZ_VERSION : 'dev',
					'version'        => isset( $settings['versionID'] ) ? $settings['versionID'] : 'default',
					'type'           => isset( $settings['type'] ) ? $settings['type'] : 'box',
					'ptype'          => isset( $settings['preferenceCenterType'] ) ? $settings['preferenceCenterType'] : 'popup',
					'theme'          => isset( $settings['theme'] ) ? $settings['theme'] : 'light',
					'law'            => isset( $settings['applicableLaw'] ) ? $settings['applicableLaw'] : 'gdpr',
					'do_not_sell'    => $do_not_sell,
					'optout_popup'   => ! empty( $config['optoutPopup']['status'] ),
					// Toggling per-service / per-cookie consent changes the
					// preference-center structure, so it must invalidate the cache.
					'per_service'    => ! empty( $banner_control['per_service_consent'] ),
					'per_cookie'     => ! empty( $banner_control['per_cookie_consent'] ),
					'description'    => md5( $notice_description ),
					// The locale resolve_build_locale() picks drives the translated
					// chrome (Always Active / audit-table headers). It can differ
					// from $this->language (e.g. de_DE when the key is 'en'), so it
					// must be in the signature or a WP locale switch would keep
					// serving stale-language labels from cache. (CodeRabbit PR #164)
					'build_locale'   => $this->resolve_build_locale(),
				)
			)
		);
	}

	/**
	 * Retrieve stored template.
	 *
	 * @return array
	 */
	public function get_stored() {
		$stored = get_option( $this->get_cache_key(), array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$storage_key = $this->get_storage_key();
		return isset( $stored[ $storage_key ] ) ? $stored[ $storage_key ] : array();
	}

	/**
	 * Store templates to options table
	 *
	 * @return void
	 */
	public function update() {
		$cache_key = $this->get_cache_key();
		$stored    = get_option( $cache_key, array() );
		$stored    = is_array( $stored ) && ! empty( $stored ) ? $stored : array();

		$stored[ $this->get_storage_key() ] = array(
			'html'             => wp_kses( $this->html, faz_allowed_html() ),
			'styles'           => wp_kses(
				$this->styles,
				faz_allowed_html()
			),
			'layout_signature' => $this->get_layout_signature(),
		);
		update_option(
			$cache_key,
			$stored
		);
	}

	/**
	 * Set templates from the stored
	 *
	 * @return void
	 */
	public function set_template() {
		$template     = $this->get_stored();
		$this->styles = isset( $template['styles'] ) ? $template['styles'] : '';
		$this->html   = isset( $template['html'] ) ? $template['html'] : '';
	}

	/**
	 * Reset banner template
	 *
	 * @return void
	 */
	public function clear_template( $clear = true ) {
		if ( false === $clear ) {
			return;
		}
		faz_clear_banner_template_cache();
	}

	/**
	 * Return the asset path
	 *
	 * @param string $path Template path.
	 * @return string
	 */
	public function get_assets_path( $path ) {
		$base_name  = wp_basename( $path );
		$assets_url = defined( 'FAZ_APP_ASSETS_URL' ) ? FAZ_APP_ASSETS_URL : '';
		return $assets_url . $base_name;
	}
	/**
	 * Elements contain image tags
	 *
	 * @return array
	 */
	public function image_tags() {
		return array(
			'revisit-consent',
			'close-button',
			'detail-close',
			'detail-powered-by',
			'optout-close',
			'optout-powered-by',
			'optout-success-icon',
		);
	}
}
