<?php
/**
 * Class Document_Config file — immutable description of one legal document type.
 *
 * Plan: docs/legal-documents-generator-plan.md §3.1
 *
 * The engine (Generator + Renderer + Template_Translations) is already generic;
 * what differs between a Cookie Policy, a Privacy Policy and a Terms page is
 * DATA — where the templates live, which option holds the settings, which
 * jurisdictions apply — plus exactly one behavioural hook (which tokens get
 * built). So the abstraction is a value object with typed accessors and a
 * callable, not an interface hierarchy: there is no polymorphic behaviour to
 * dispatch on, and a bare config array would give neither validation nor a
 * single place to catch a typo'd key.
 *
 * The constructor validates eagerly and throws: a malformed registry entry is a
 * programming error that must surface the moment the registry is built, not as
 * a null-ish token halfway through a rendered legal document.
 *
 * @package FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes
 * @since   1.25.1
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One legal document type, described as data.
 *
 * Immutable by construction: everything is assigned in the constructor and only
 * ever read back through getters. PHP 7.4 floor — no readonly properties, no
 * constructor promotion, no union types.
 *
 * @class    Document_Config
 * @since    1.25.1
 */
class Document_Config {

	/**
	 * Registry key, e.g. 'cookie-policy'.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Shortcode tag that renders this document.
	 *
	 * @var string
	 */
	private $shortcode;

	/**
	 * wp_options key holding this document's settings.
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Absolute path to the <jurisdiction>/<lang>.md tree (no trailing slash).
	 *
	 * @var string
	 */
	private $templates_dir;

	/**
	 * Jurisdictions this document ships. The FIRST entry is the fallback used
	 * when neither the shortcode attribute nor the saved setting names a valid
	 * one — reordering the array changes the default legal regime.
	 *
	 * @var string[]
	 */
	private $jurisdictions;

	/**
	 * Jurisdiction → native language map.
	 *
	 * @var array<string,string>
	 */
	private $native_lang;

	/**
	 * Tokens whose values are pre-rendered HTML and must bypass the markdown
	 * pass (see Generator::HTML_TOKENS for the why).
	 *
	 * @var string[]
	 */
	private $html_tokens;

	/**
	 * Absolute path to the generated _x() catalogue for this document.
	 *
	 * @var string
	 */
	private $gettext_catalog;

	/**
	 * CSS class on the <article> wrapper.
	 *
	 * @var string
	 */
	private $wrapper_class;

	/**
	 * callable( array $settings, string $jurisdiction, string $lang ): array
	 *
	 * @var callable
	 */
	private $data_builder;

	/**
	 * callable( string $jurisdiction, array $settings ): string[]
	 *
	 * @var callable
	 */
	private $required_fields;

	/**
	 * Build and validate one document description.
	 *
	 * @param array $config Registry entry.
	 * @throws \InvalidArgumentException When a field is missing or mistyped.
	 */
	public function __construct( array $config ) {
		foreach ( array( 'slug', 'shortcode', 'option', 'templates_dir', 'gettext_catalog', 'wrapper_class' ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_string( $config[ $key ] ) || '' === trim( $config[ $key ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Document_Config: "%s" must be a non-empty string.', $key )
				);
			}
		}

		if ( ! isset( $config['jurisdictions'] ) || ! is_array( $config['jurisdictions'] ) || array() === $config['jurisdictions'] ) {
			throw new \InvalidArgumentException( 'Document_Config: "jurisdictions" must be a non-empty array.' );
		}
		foreach ( $config['jurisdictions'] as $jurisdiction ) {
			if ( ! is_string( $jurisdiction ) || '' === $jurisdiction ) {
				throw new \InvalidArgumentException( 'Document_Config: every "jurisdictions" entry must be a non-empty string.' );
			}
		}

		if ( ! isset( $config['native_lang'] ) || ! is_array( $config['native_lang'] ) ) {
			throw new \InvalidArgumentException( 'Document_Config: "native_lang" must be an array.' );
		}
		foreach ( $config['native_lang'] as $lang ) {
			if ( ! is_string( $lang ) || '' === $lang ) {
				throw new \InvalidArgumentException( 'Document_Config: every "native_lang" value must be a non-empty string.' );
			}
		}

		if ( ! isset( $config['html_tokens'] ) || ! is_array( $config['html_tokens'] ) ) {
			throw new \InvalidArgumentException( 'Document_Config: "html_tokens" must be an array.' );
		}
		foreach ( $config['html_tokens'] as $token ) {
			if ( ! is_string( $token ) || '' === $token ) {
				throw new \InvalidArgumentException( 'Document_Config: every "html_tokens" entry must be a non-empty string.' );
			}
		}

		foreach ( array( 'data_builder', 'required_fields' ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_callable( $config[ $key ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Document_Config: "%s" must be callable.', $key )
				);
			}
		}

		$this->slug          = (string) $config['slug'];
		$this->shortcode     = (string) $config['shortcode'];
		$this->option        = (string) $config['option'];
		// Normalised here rather than trusted, so a registry entry written with
		// a trailing slash cannot produce '…/templates//gdpr-strict/en.md'.
		$this->templates_dir   = rtrim( (string) $config['templates_dir'], '/' );
		$this->jurisdictions   = array_values( $config['jurisdictions'] );
		$this->native_lang     = $config['native_lang'];
		$this->html_tokens     = array_values( $config['html_tokens'] );
		$this->gettext_catalog = (string) $config['gettext_catalog'];
		$this->wrapper_class   = (string) $config['wrapper_class'];
		$this->data_builder    = $config['data_builder'];
		$this->required_fields = $config['required_fields'];
	}

	/**
	 * Registry key.
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * Shortcode tag rendering this document.
	 *
	 * @return string
	 */
	public function shortcode() {
		return $this->shortcode;
	}

	/**
	 * wp_options key holding this document's settings.
	 *
	 * @return string
	 */
	public function option() {
		return $this->option;
	}

	/**
	 * Absolute templates root, no trailing slash.
	 *
	 * @return string
	 */
	public function templates_dir() {
		return $this->templates_dir;
	}

	/**
	 * Supported jurisdictions; the first entry is the fallback regime.
	 *
	 * @return string[]
	 */
	public function jurisdictions() {
		return $this->jurisdictions;
	}

	/**
	 * Native language of a jurisdiction, 'en' when unmapped.
	 *
	 * @param string $jurisdiction Jurisdiction id.
	 * @return string
	 */
	public function native_lang( $jurisdiction ) {
		return isset( $this->native_lang[ $jurisdiction ] ) ? $this->native_lang[ $jurisdiction ] : 'en';
	}

	/**
	 * Tokens protected from the markdown pass.
	 *
	 * @return string[]
	 */
	public function html_tokens() {
		return $this->html_tokens;
	}

	/**
	 * Absolute path to the generated gettext catalogue.
	 *
	 * @return string
	 */
	public function gettext_catalog() {
		return $this->gettext_catalog;
	}

	/**
	 * CSS class on the rendered <article> wrapper.
	 *
	 * @return string
	 */
	public function wrapper_class() {
		return $this->wrapper_class;
	}

	/**
	 * Build the substitution-data array for this document.
	 *
	 * @param array  $settings     Saved settings for this document.
	 * @param string $jurisdiction Effective jurisdiction.
	 * @param string $lang         Effective language.
	 * @return array<string,mixed>
	 */
	public function build_data( array $settings, $jurisdiction, $lang ) {
		return (array) call_user_func( $this->data_builder, $settings, $jurisdiction, $lang );
	}

	/**
	 * Settings this document's jurisdiction cannot legally do without.
	 *
	 * @param string $jurisdiction Effective jurisdiction.
	 * @param array  $settings     Saved settings for this document.
	 * @return string[] Missing dot-paths.
	 */
	public function missing_required_settings( $jurisdiction, array $settings ) {
		return (array) call_user_func( $this->required_fields, $jurisdiction, $settings );
	}
}
