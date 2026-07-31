<?php
/**
 * Class Document_Registry file — the hardcoded catalogue of document types.
 *
 * Plan: docs/legal-documents-generator-plan.md §3.1
 *
 * Deliberately NOT filterable in v1: opening document registration to third
 * parties before the Document_Config shape has settled would create a compat
 * contract we cannot yet honour, for a use case nobody has asked for. A filter
 * can be added later; it cannot be taken away.
 *
 * Only the cookie policy is registered here. That is the whole point of this
 * step — the engine learns to take its coordinates from a config object while
 * still rendering exactly the one document it renders today.
 *
 * @package FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes
 * @since   1.25.1
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// build() reads every coordinate off these four classes, and this file is
// reachable without an autoloader (unit suites, CLI tools). Requiring them here
// as well as in class-renderer.php makes either file a valid entry point; the
// renderer cycle is safe because no class is referenced at file scope.
require_once __DIR__ . '/class-document-config.php';
require_once __DIR__ . '/class-generator.php';
require_once __DIR__ . '/class-template-translations.php';
require_once __DIR__ . '/class-renderer.php';

/**
 * Static registry of Document_Config value objects.
 *
 * @class    Document_Registry
 * @since    1.25.1
 */
class Document_Registry {

	/**
	 * Built configs, slug-keyed. Null until the first access.
	 *
	 * @var array<string,Document_Config>|null
	 */
	private static $configs = null;

	/**
	 * One document config, or null when the slug is unknown.
	 *
	 * @param string $slug Document slug.
	 * @return Document_Config|null
	 */
	public static function get( $slug ) {
		$configs = self::build();
		return isset( $configs[ (string) $slug ] ) ? $configs[ (string) $slug ] : null;
	}

	/**
	 * Every registered document, slug-keyed.
	 *
	 * @return array<string,Document_Config>
	 */
	public static function all() {
		return self::build();
	}

	/**
	 * Registered document slugs.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_keys( self::build() );
	}

	/**
	 * Build the registry once, on first access.
	 *
	 * Lazy on purpose. Building at file-load time would require Generator,
	 * Renderer and Template_Translations to already be loaded the instant this
	 * file is included, which is a load-order trap for any context that pulls
	 * the classes in by hand — the golden-render suite does exactly that, with
	 * no autoloader and no plugin bootstrap.
	 *
	 * @return array<string,Document_Config>
	 */
	private static function build() {
		if ( null !== self::$configs ) {
			return self::$configs;
		}

		self::$configs = array(
			'cookie-policy' => new Document_Config(
				array(
					'slug' => 'cookie-policy',
					// Literal rather than Cookie_Policy_Generator::SHORTCODE: that
					// class is the module bootstrap and is not loaded in the
					// bootstrap-free unit-test context this registry must survive.
					'shortcode'       => 'faz_cookie_policy_complete',
					'option'          => Renderer::SETTINGS_OPTION,
					'templates_dir'   => Generator::templates_dir(),
					'jurisdictions'   => Generator::JURISDICTIONS,
					'native_lang'     => Generator::NATIVE_LANG,
					'html_tokens'     => Generator::HTML_TOKENS,
					'gettext_catalog' => Template_Translations::CATALOG_FILE,
					'wrapper_class'   => 'faz-cookie-policy',
					'data_builder'    => array( Renderer::class, 'build_data' ),
					// Receives this config as its third argument, so Generator's
					// per-document gate sees 'cookie-policy' and falls through to
					// the existing POPIA-only rules verbatim. It reads the slug
					// off the object it was handed — no recursion back here.
					'required_fields' => array( Generator::class, 'missing_required_settings' ),
				)
			),
		);

		return self::$configs;
	}
}
