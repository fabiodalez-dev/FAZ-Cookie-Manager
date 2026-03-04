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
	 * @param object|false $banner Banner object.
	 */
	public function __construct( $banner = false ) {
		$this->language = faz_current_language();
		if ( $banner ) {
			$this->banner     = $banner;
			$this->properties = $banner->get_settings();
			$this->load();
		}
		add_action( 'faz_after_update_banner', array( $this, 'clear_template' ) );
		add_action( 'faz_after_update_cookie_category', array( $this, 'clear_template' ) );
		add_action( 'faz_after_update_cookie', array( $this, 'clear_template' ) );
		add_action( 'faz_clear_cache', array( $this, 'clear_template' ) );
	}

	/**
	 * Get or Set templates based on the condition.
	 *
	 * @return void
	 */
	public function load() {
		if ( true === $this->is_preview() || empty( $this->get_stored() ) ) {
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
				// Brand logo: set img src from config meta.url (custom upload).
				if ( 'brand-logo' === $tag && isset( $config['meta']['url'] ) && '#' !== $config['meta']['url'] ) {
					$logo_imgs = $element->getElementsByTagName( 'img' );
					foreach ( $logo_imgs as $logo_img ) {
						$logo_img->setAttribute( 'src', esc_url( $config['meta']['url'] ) );
					}
				}
				$preview = $this->is_preview();
				$enabled = isset( $config['status'] ) && false === $preview ? $config['status'] : true;

				if ( false === $enabled ) {
					$element->parentNode->removeChild( $element );  //phpcs:ignore WordPress.NamingConventions.ValidVariableName	
					continue;
				}

				$styles = isset( $config['styles'] ) ? $config['styles'] : array();

				$existing = $element->getAttribute( 'style' );
				$style    = isset( $existing ) ? $existing : '';
				if ( ! empty( $styles ) ) {
					foreach ( $styles as $property => $value ) {
						if ( '' !== $value ) {
							$style .= $property . ':' . $value . ';';
						}
					}
				}
				if ( '' !== $style ) {
					$element->setAttribute( 'style', esc_attr( $style ) );
				}
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
	 * Retrieve stored template.
	 *
	 * @return string
	 */
	public function get_stored() {
		$stored = get_option( 'faz_banner_template', array() );
		return isset( $stored[ $this->language ] ) ? $stored[ $this->language ] : array();
	}

	/**
	 * Store templates to options table
	 *
	 * @return void
	 */
	public function update() {
		$stored = get_option( 'faz_banner_template', array() );
		$stored = is_array( $stored ) && ! empty( $stored ) ? $stored : array();

		$stored[ $this->language ] = array(
			'html'   => wp_kses( $this->html, faz_allowed_html() ),
			'styles' => wp_kses(
				$this->styles,
				faz_allowed_html()
			),
		);
		update_option(
			'faz_banner_template',
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
	public function clear_template() {
		update_option( 'faz_banner_template', '' );
	}

	/**
	 * Return the asset path
	 *
	 * @param string $path Template path.
	 * @return string
	 */
	public function get_assets_path( $path ) {
		$base_name = wp_basename( $path );
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
		);
	}
}

