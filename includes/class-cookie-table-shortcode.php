<?php
/**
 * Cookie Table Shortcode — [faz_cookie_table]
 *
 * Renders an HTML table of cookies grouped by category.
 * Supports multilingual fields with the same fallback mechanism as the banner.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cookie_Table_Shortcode {

	/**
	 * Whether the inline CSS has already been output.
	 *
	 * @var bool
	 */
	private static $css_output = false;

	/**
	 * Initialize and register shortcodes.
	 */
	public function __construct() {
		add_shortcode( 'faz_cookie_table', array( $this, 'render' ) );
		add_shortcode( 'cookie_audit', array( $this, 'render' ) ); // backward compat
	}

	/**
	 * Extract a localized string from a multilingual value.
	 *
	 * Uses the same fallback chain as the banner:
	 * current language → default language → first available key → raw string.
	 *
	 * @param mixed  $value   String or associative array keyed by language code.
	 * @param string $lang    Current language code.
	 * @param string $default Default language code.
	 * @return string
	 */
	private function localize( $value, $lang, $default, $wp_lang = '' ) {
		if ( empty( $value ) ) {
			return '';
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			if ( isset( $value[ $lang ] ) && '' !== $value[ $lang ] ) {
				return $value[ $lang ];
			}
			// Try WordPress locale prefix (e.g. 'fr' from 'fr_FR').
			if ( $wp_lang && isset( $value[ $wp_lang ] ) && '' !== $value[ $wp_lang ] ) {
				return $value[ $wp_lang ];
			}
			if ( isset( $value[ $default ] ) && '' !== $value[ $default ] ) {
				return $value[ $default ];
			}
			// Fallback: first non-empty value.
			foreach ( $value as $v ) {
				if ( '' !== $v ) {
					return $v;
				}
			}
		}
		return '';
	}

	/**
	 * Localize a category name while preferring plugin customizations over stock translations.
	 *
	 * Custom names are saved in the plugin settings, typically in the default language.
	 * When they exist, they should win over bundled fallback translations used by the shortcode.
	 *
	 * @param \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories $cat_obj Category object.
	 * @param mixed                                                       $value Category name value.
	 * @param string                                                      $lang Current language code.
	 * @param string                                                      $default Default language code.
	 * @param string                                                      $wp_lang WordPress locale prefix.
	 * @return string
	 */
	private function localize_category_name( $cat_obj, $value, $lang, $default, $wp_lang = '' ) {
		if ( empty( $value ) ) {
			return '';
		}
		if ( is_string( $value ) ) {
			// Dynamic value comes from the user's banner config (DB), not from
			// a hard-coded literal — translation strings extractors (xgettext
			// / Plugin Check) cannot pick this up, and forwarding a variable
			// to __() is a no-op unless the runtime catalogue happens to
			// contain a key matching the runtime value. Returning the value
			// verbatim is the documented Plugin Directory expectation.
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return '';
		}

		$current_value = ( $lang && isset( $value[ $lang ] ) && '' !== $value[ $lang ] ) ? $value[ $lang ] : '';
		$wp_value      = ( $wp_lang && isset( $value[ $wp_lang ] ) && '' !== $value[ $wp_lang ] ) ? $value[ $wp_lang ] : '';
		$default_value = ( isset( $value[ $default ] ) && '' !== $value[ $default ] ) ? $value[ $default ] : '';

		$stock_current = $lang ? $cat_obj->get_translations( $lang, 'name' ) : '';
		$stock_wp      = $wp_lang ? $cat_obj->get_translations( $wp_lang, 'name' ) : '';
		$stock_default = $default ? $cat_obj->get_translations( $default, 'name' ) : '';

		// User custom name wins over stock translations.
		if ( '' !== $current_value && $current_value !== $stock_current ) {
			return $current_value;
		}
		if ( '' !== $wp_value && $wp_value !== $stock_wp ) {
			return $wp_value;
		}
		if ( '' !== $default_value && $default_value !== $stock_default ) {
			return $default_value;
		}
		// Stock value: dynamic strings sourced from user-edited banner
		// config or from the WP locale-keyed array. The translation parser
		// cannot extract from variable arguments (see comment above), so we
		// return the value verbatim instead of routing it through __() —
		// the per-language array already contains the desired translation
		// for the requested locale.
		if ( '' !== $current_value ) {
			return $current_value;
		}
		if ( '' !== $wp_value ) {
			return $wp_value;
		}
		if ( '' !== $default_value ) {
			return $default_value;
		}
		foreach ( $value as $entry ) {
			if ( '' !== $entry ) {
				return $entry;
			}
		}
		return '';
	}

	/**
	 * Render the cookie table.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'columns'  => 'name,domain,duration,description',
				'heading'  => '',
				'category' => '',
			),
			$atts,
			'faz_cookie_table'
		);
		$atts['category'] = sanitize_text_field( $atts['category'] );
		$atts['heading']  = sanitize_text_field( $atts['heading'] );

		$lang    = function_exists( 'faz_current_language' ) ? faz_current_language() : 'en';
		$default = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
		// Also consider the WordPress locale (e.g. 'fr_FR' → 'fr') for sites
		// that set a non-English locale without a multilingual plugin.
		$wp_lang = substr( get_locale(), 0, 2 );

		// Parse requested columns.
		$allowed_columns = array(
			'name'        => __( 'Cookie', 'faz-cookie-manager' ),
			'domain'      => __( 'Domain', 'faz-cookie-manager' ),
			'duration'    => __( 'Duration', 'faz-cookie-manager' ),
			'description' => __( 'Description', 'faz-cookie-manager' ),
			'category'    => __( 'Category', 'faz-cookie-manager' ),
		);
		$columns = array_map( 'trim', explode( ',', $atts['columns'] ) );
		$columns = array_filter( $columns, function( $c ) use ( $allowed_columns ) {
			return isset( $allowed_columns[ $c ] );
		} );
		if ( empty( $columns ) ) {
			$columns = array( 'name', 'domain', 'duration', 'description' );
		}

		// Fetch categories and build lookup, excluding hidden categories.
		$cat_controller = Category_Controller::get_instance();
		$categories     = $cat_controller->get_item_from_db();
		$hidden_cat_ids = array();
		$cat_map        = array(); // category_id => localized name
		foreach ( $categories as $cat ) {
			$cat_obj = new \FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories( $cat );
			if ( false === $cat_obj->get_visibility() ) {
				$hidden_cat_ids[] = absint( $cat->category_id );
				continue;
			}
			// Never show WordPress internal cookies to visitors.
			if ( 'wordpress-internal' === $cat_obj->get_slug() ) {
				$hidden_cat_ids[] = absint( $cat->category_id );
				continue;
			}
			$localized_name               = $this->localize_category_name( $cat_obj, $cat->name, $lang, $default, $wp_lang );
			$cat_map[ $cat->category_id ] = $localized_name;
		}

		// Fetch cookies.
		$cookie_controller = Cookie_Controller::get_instance();
		if ( ! empty( $atts['category'] ) ) {
			// Filter by category slug or ID.
			$target_cat_id = null;
			if ( is_numeric( $atts['category'] ) ) {
				$target_cat_id = absint( $atts['category'] );
			} else {
				foreach ( $categories as $cat ) {
					if ( $cat->slug === $atts['category'] ) {
						$target_cat_id = $cat->category_id;
						break;
					}
				}
			}
			$cookies = $target_cat_id ? $cookie_controller->get_items_by_category( $target_cat_id ) : array();
		} else {
			$cookies = $cookie_controller->get_item_from_db();
		}

		// Exclude cookies belonging to hidden categories.
		if ( ! empty( $hidden_cat_ids ) ) {
			$cookies = array_filter( $cookies, function( $cookie ) use ( $hidden_cat_ids ) {
				$cat_id = isset( $cookie->category ) ? absint( $cookie->category ) : 0;
				return ! in_array( $cat_id, $hidden_cat_ids, true );
			} );
		}

		// Exclude individual WP-internal cookies (may appear under "necessary" or other categories).
		$cookies = array_filter( $cookies, function( $cookie ) {
			$name = isset( $cookie->name ) ? $cookie->name : '';
			return ! \FazCookie\Frontend\Frontend::is_wp_internal_cookie( $name );
		} );

		if ( empty( $cookies ) ) {
			return '<p class="faz-cookie-table-empty">' . esc_html__( 'No cookies found.', 'faz-cookie-manager' ) . '</p>';
		}

		// Group cookies by category for nicer display.
		$grouped   = array();
		$show_cats = empty( $atts['category'] ); // Only group when showing all.
		foreach ( $cookies as $cookie ) {
			$cat_id = isset( $cookie->category ) ? absint( $cookie->category ) : 0;
			if ( ! isset( $grouped[ $cat_id ] ) ) {
				$grouped[ $cat_id ] = array();
			}
			$grouped[ $cat_id ][] = $cookie;
		}

		// Sort categories by priority (use category order from DB).
		$sorted_cat_ids = array_keys( $cat_map );
		$ordered        = array();
		foreach ( $sorted_cat_ids as $cid ) {
			if ( isset( $grouped[ $cid ] ) ) {
				$ordered[ $cid ] = $grouped[ $cid ];
				unset( $grouped[ $cid ] );
			}
		}
		// Append any remaining (uncategorized or unknown category).
		foreach ( $grouped as $cid => $items ) {
			$ordered[ $cid ] = $items;
		}

		// Enqueue the shortcode-specific stylesheet exactly once per page
		// load. Using wp_enqueue_style() (instead of an inline <style> tag)
		// satisfies the WordPress.org "use wp_enqueue commands" guideline
		// and lets caching plugins / minifiers process the CSS like any
		// other registered style.
		if ( ! self::$css_output ) {
			self::$css_output = true;

			$plugin_url     = defined( 'FAZ_PLUGIN_URL' ) ? FAZ_PLUGIN_URL : plugin_dir_url( dirname( __FILE__ ) );
			$css_relative   = 'frontend/css/cookie-table-shortcode.css';
			$css_path       = ( defined( 'FAZ_PLUGIN_BASEPATH' ) ? FAZ_PLUGIN_BASEPATH : plugin_dir_path( dirname( __FILE__ ) ) ) . $css_relative;
			$css_version    = defined( 'FAZ_VERSION' ) ? FAZ_VERSION : false;
			if ( file_exists( $css_path ) ) {
				$css_version = filemtime( $css_path );
			}

			wp_register_style(
				'faz-cookie-table-shortcode',
				$plugin_url . $css_relative,
				array(),
				$css_version
			);
			wp_enqueue_style( 'faz-cookie-table-shortcode' );
		}

		// Build HTML.
		ob_start();
		?>
		<div class="faz-cookie-table-wrap">
		<?php if ( ! empty( $atts['heading'] ) ) : ?>
			<h3 class="faz-cookie-table-heading"><?php echo esc_html( $atts['heading'] ); ?></h3>
		<?php endif; ?>

		<?php foreach ( $ordered as $cat_id => $cat_cookies ) : ?>
			<?php if ( $show_cats ) : ?>
				<h4 class="faz-cookie-table-category">
					<?php echo esc_html( isset( $cat_map[ $cat_id ] ) ? $cat_map[ $cat_id ] : __( 'Other', 'faz-cookie-manager' ) ); ?>
				</h4>
			<?php endif; ?>
			<table class="faz-cookie-table">
				<thead>
					<tr>
						<?php foreach ( $columns as $col ) : ?>
							<th><?php echo esc_html( $allowed_columns[ $col ] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $cat_cookies as $cookie ) : ?>
					<tr>
						<?php foreach ( $columns as $col ) : ?>
							<td data-label="<?php echo esc_attr( $allowed_columns[ $col ] ); ?>">
							<?php
							switch ( $col ) {
								case 'name':
									echo esc_html( isset( $cookie->name ) ? $cookie->name : '' );
									break;
								case 'domain':
									echo esc_html( isset( $cookie->domain ) ? $cookie->domain : '' );
									break;
								case 'duration':
									echo esc_html( $this->localize(
										isset( $cookie->duration ) ? $cookie->duration : '',
										$lang,
										$default,
										$wp_lang
									) );
									break;
								case 'description':
									echo esc_html( $this->localize(
										isset( $cookie->description ) ? $cookie->description : '',
										$lang,
										$default,
										$wp_lang
									) );
									break;
								case 'category':
									echo esc_html( isset( $cat_map[ $cookie->category ] ) ? $cat_map[ $cookie->category ] : '' );
									break;
							}
							?>
							</td>
						<?php endforeach; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
		</div>
		<?php

		return ob_get_clean();
	}
}
