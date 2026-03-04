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
	private function localize( $value, $lang, $default ) {
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
			$cat_map[ $cat->category_id ] = $this->localize( $cat->name, $lang, $default );
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

		// Build HTML.
		ob_start();
		if ( ! self::$css_output ) :
			self::$css_output = true;
		?>
		<style>
		.faz-cookie-table-wrap{margin:1.5em 0;font-size:14px;line-height:1.5;}
		.faz-cookie-table-heading{margin:0 0 1em;font-size:1.3em;}
		.faz-cookie-table-category{margin:1.5em 0 .5em;font-size:1.1em;border-bottom:2px solid #e2e8f0;padding-bottom:.3em;}
		.faz-cookie-table{width:100%;border-collapse:collapse;margin-bottom:1em;}
		.faz-cookie-table th,.faz-cookie-table td{padding:8px 12px;text-align:left;border:1px solid #e2e8f0;vertical-align:top;}
		.faz-cookie-table th{background:#f8fafc;font-weight:600;font-size:13px;white-space:nowrap;}
		.faz-cookie-table td{font-size:13px;}
		.faz-cookie-table tbody tr:nth-child(even){background:#fafbfc;}
		.faz-cookie-table-empty{color:#64748b;font-style:italic;}
		@media(max-width:600px){
			.faz-cookie-table,.faz-cookie-table thead,.faz-cookie-table tbody,.faz-cookie-table th,.faz-cookie-table td,.faz-cookie-table tr{display:block;}
			.faz-cookie-table thead{display:none;}
			.faz-cookie-table td{border:none;border-bottom:1px solid #e2e8f0;padding:6px 12px;}
			.faz-cookie-table td:before{content:attr(data-label);font-weight:600;display:block;margin-bottom:2px;font-size:12px;color:#64748b;}
			.faz-cookie-table tr{border:1px solid #e2e8f0;margin-bottom:8px;border-radius:4px;}
		}
		</style>
		<?php endif; ?>
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
										$default
									) );
									break;
								case 'description':
									echo esc_html( $this->localize(
										isset( $cookie->description ) ? $cookie->description : '',
										$lang,
										$default
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
