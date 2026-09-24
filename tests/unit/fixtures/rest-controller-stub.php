<?php
/**
 * Minimal stand-in for FazCookie\Includes\Rest_Controller.
 *
 * The pageview API class extends it, so PHP needs the parent to exist before
 * the file can be loaded at all — even to reach three static methods that touch
 * nothing but wp_hash(), apply_filters() and their own class constants. Loading
 * the real one would drag in WP_REST_Controller and the REST bootstrap, which
 * is a great deal of machinery to stand up for arithmetic. An empty parent buys
 * the behavioural assertion; the day the window arithmetic starts depending on
 * something this parent provides, the test will say so by failing to load.
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes;

if ( ! class_exists( __NAMESPACE__ . '\\Rest_Controller' ) ) {
	class Rest_Controller {}
}
