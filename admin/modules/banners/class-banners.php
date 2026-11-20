<?php
/**
 * Class Banners file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Banners;

use FazCookie\Includes\Modules;
use FazCookie\Admin\Modules\Banners\Includes\Controller;
use FazCookie\Admin\Modules\Banners\Api\Api;
use FazCookie\Admin\Modules\Banners\Includes\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Banners
 * @version     3.0.0
 * @package     FazCookie
 */
class Banners extends Modules {

	/**
	 * Banners controller class.
	 *
	 * @var object
	 */
	private $controller;

	/**
	 * Constructor.
	 */
	public function init() {
		$this->controller = Controller::get_instance();
		$this->load_apis();
		add_action( 'faz_after_update_banner', array( $this->controller, 'delete_cache' ) );
		add_action( 'faz_after_update_banner', array( Template::get_instance(), 'delete_cache' ) );
		add_action( 'faz_reinstall_tables', array( $this->controller, 'reinstall' ) );
		// The brand logo takes its alt text from the Media Library (#404), and
		// the built template is cached: an alt edited there has to rebuild it.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'maybe_flush_on_alt_change' ), 10, 3 );
		}
	}

	/**
	 * Rebuild the banner template when an image's alt text changes.
	 *
	 * Any attachment, not only the current logo: telling them apart would need
	 * the banner config on every meta write, and alt edits are rare admin
	 * actions while a rebuild costs one template build.
	 *
	 * @param int|int[] $meta_id   Meta id(s).
	 * @param int       $object_id Post id.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public function maybe_flush_on_alt_change( $meta_id, $object_id, $meta_key ) {
		if ( '_wp_attachment_image_alt' === $meta_key && function_exists( 'faz_clear_banner_template_cache' ) ) {
			faz_clear_banner_template_cache();
		}
	}

	/**
	 * Load API files
	 *
	 * @return void
	 */
	public function load_apis() {
		new Api();
	}
}
