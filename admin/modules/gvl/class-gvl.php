<?php
/**
 * GVL admin module — IAB Global Vendor List management.
 *
 * @package FazCookie\Admin\Modules\Gvl
 */

namespace FazCookie\Admin\Modules\Gvl;

use FazCookie\Includes\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gvl extends Modules {

	/**
	 * Initialize the module.
	 */
	public function init() {
		new Api\Api();
	}
}
