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
	}

	/**
	 * Rebuild the banner template when an image's alt text changes.
	 *
	 * The template is rebuilt for any attachment, not only the current logo:
	 * alt edits are rare admin actions and a rebuild costs one template build.
	 * Only the page-cache purge, which empties every cached page, waits for the
	 * logo check below.
	 *
	 * Registered by Admin::__construct() on every request, not by init(): the
	 * full attachment edit screen saves the alt through post.php, where the
	 * admin modules are not loaded.
	 *
	 * Returns whether the image is a banner's logo, so the caller purges the
	 * page cache only then: pages cached with the banner keep the old alt, but
	 * an alt edited on any other image must not empty the whole cache.
	 *
	 * @param int|int[] $meta_id   Meta id(s).
	 * @param int       $object_id Post id.
	 * @param string    $meta_key  Meta key.
	 * @return bool True when the image is shown as a banner's brand logo.
	 */
	public static function maybe_flush_on_alt_change( $meta_id, $object_id, $meta_key ) {
		if ( '_wp_attachment_image_alt' !== $meta_key ) {
			return false;
		}
		if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
			faz_clear_banner_template_cache();
		}
		return self::is_brand_logo_attachment( (int) $object_id );
	}

	/**
	 * Whether an attachment is the brand logo shown by any banner.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public static function is_brand_logo_attachment( $attachment_id ) {
		if ( $attachment_id <= 0 ) {
			return false;
		}
		foreach ( (array) Controller::get_instance()->get_items() as $banner ) {
			$logo = isset( $banner->settings['config']['notice']['elements']['brandLogo'] ) ? $banner->settings['config']['notice']['elements']['brandLogo'] : null;
			if ( ! is_array( $logo ) || empty( $logo['status'] ) || empty( $logo['meta']['url'] ) ) {
				continue;
			}
			if ( Template::brand_logo_attachment_id( (string) $logo['meta']['url'] ) === $attachment_id ) {
				return true;
			}
		}
		return false;
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
