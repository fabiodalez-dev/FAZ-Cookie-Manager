<?php
/**
 * PHPStan symbols for optional third-party integrations.
 *
 * These APIs are loaded only when their owning plugin is active. Production
 * code guards every call with function_exists(), class_exists(), or
 * is_callable(); the declarations below let PHPStan validate those guarded
 * call sites without loading any third-party plugin at analysis time.
 */

namespace {
	class autoptimizeCache {
		/** @return mixed */
		public static function clearall() {}
	}

	/** @return mixed */
	function rocket_clean_domain() {}

	/** @return mixed */
	function sg_cachepress_purge_cache() {}

	/** @return mixed */
	function w3tc_pgcache_flush() {}

	/** @return string */
	function weglot_get_current_language() {}

	/** @return mixed */
	function wp_cache_clean_cache( $file_prefix = '' ) {}

	/** @return mixed */
	function wpfc_clear_all_cache() {}
}

namespace FlyingPress {
	class Purge {
		/** @return mixed */
		public static function purge_pages() {}

		/** @return mixed */
		public static function purge_everything() {}
	}
}

namespace LiteSpeed {
	class Purge {
		/** @return mixed */
		public static function purge_all() {}
	}
}
