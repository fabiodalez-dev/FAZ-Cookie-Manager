<?php
/** Cookie policy page creation for guided setup. @package FazCookie */
namespace FazCookie\Admin\Modules\Settings\Includes;

use WP_Error;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Policy_Page {
	/** Refuse silent template-language fallbacks before publishing anything. */
	public static function validate( $language, $law ) {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return new WP_Error( 'faz_policy_permission', __( 'You need permission to publish pages to create the cookie policy.', 'faz-cookie-manager' ), array( 'status' => 403 ) );
		}
		$languages = \FazCookie\Admin\Modules\Languages\Includes\Controller::get_instance()->get_languages();
		if ( ! is_string( $language ) || ! in_array( $language, array_values( $languages ), true ) || ! in_array( $law, Onboarding::LAWS, true ) ) {
			return new WP_Error( 'faz_policy_language', __( 'Choose a supported language for the cookie policy.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		$language = Generator::normalize_language_code( $language );
		$jurisdictions = array( 'gdpr' => 'gdpr-strict', 'both' => 'gdpr-strict', 'ccpa' => 'ccpa-california', 'popia' => 'popia-southafrica' );
		$path = Generator::resolve_template_path( $jurisdictions[ $law ], $language );
		if ( ! $path || basename( $path, '.md' ) !== $language ) {
			return new WP_Error( 'faz_policy_translation_missing', __( 'A cookie policy template is not available in the selected language. Choose another language or turn off automatic page creation and translate the policy manually.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		return array( 'language' => $language, 'jurisdiction' => $jurisdictions[ $law ], 'path' => $path );
	}

	/** Create once per language and jurisdiction; never overwrite existing content. */
	public static function ensure( $language, $law ) {
		$config = self::validate( $language, $law );
		if ( is_wp_error( $config ) ) { return $config; }
		$language = $config['language'];
		$shortcode = '[faz_cookie_policy_complete lang="' . $language . '" jurisdiction="' . $config['jurisdiction'] . '"]';
		$key = 'faz_setup_policy_page_' . sanitize_key( $language . '_' . $config['jurisdiction'] );
		// An atomic option prevents concurrent wizard submissions creating duplicates.
		// Expired leases are removed conditionally, so an interrupted PHP process
		// cannot block future setup forever or delete another request's lock.
		$lock = $key . '_lock';
		$previous = get_option( $lock, '' );
		if ( $previous && (int) $previous < time() - 300 ) { self::release_lock( $lock, $previous ); }
		$token = time() . ':' . wp_generate_uuid4();
		if ( ! add_option( $lock, $token, '', false ) ) {
			return new WP_Error( 'faz_policy_busy', __( 'Cookie policy creation is already in progress. Please try again shortly.', 'faz-cookie-manager' ), array( 'status' => 409 ) );
		}
		try {
			$page = get_post( (int) get_option( $key, 0 ) );
			if ( $page && 'trash' !== $page->post_status && 'page' === $page->post_type && false !== strpos( $page->post_content, $shortcode ) && ( 'publish' !== $page->post_status || '' !== $page->post_password ) ) {
				return self::not_public_error();
			}
			if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status || '' !== $page->post_password || false === strpos( $page->post_content, $shortcode ) ) {
				// Recover a page after an interrupted request, including when saving
				// its option failed. Search the exact language-bearing shortcode.
				$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'has_password' => false, 's' => $shortcode, 'posts_per_page' => -1 ) );
				$page = null;
				foreach ( $pages as $candidate ) {
					if ( false !== strpos( $candidate->post_content, $shortcode ) ) { $page = $candidate; break; }
				}
			}
			if ( ! $page ) {
				// The language is explicit: rendering never depends on the visitor's
				// locale or on a later change to the banner language.
				$content = '<!-- wp:shortcode -->' . "\n" . $shortcode . "\n" . '<!-- /wp:shortcode -->';
				// Use the template's translated heading, never the admin's locale.
				$template = (string) file_get_contents( $config['path'] );
				$title = preg_match( '/^#\s+(.+)$/m', $template, $heading ) ? wp_strip_all_tags( $heading[1] ) : 'Cookie Policy';
				$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => 'cookie-policy-' . sanitize_title( $language ), 'post_content' => $content ), true );
				if ( is_wp_error( $id ) ) { return $id; }
				if ( ! $id ) { return new WP_Error( 'faz_policy_create_failed', __( 'The cookie policy page could not be created. Please try again.', 'faz-cookie-manager' ), array( 'status' => 500 ) ); }
				$page = get_post( $id );
				update_post_meta( $id, '_faz_setup_policy_language', $language );
				// Editorial plugins may force a draft even when publish was requested.
				// Remember it for retries, but never point the public banner at a draft.
				update_option( $key, $id, false );
				if ( ! $page || 'publish' !== $page->post_status || '' !== $page->post_password ) {
					return self::not_public_error();
				}
			}
			update_option( $key, $page->ID, false );
			return array( 'id' => $page->ID, 'url' => get_permalink( $page ), 'language' => $language );
		} finally {
			self::release_lock( $lock, $token );
		}
	}

	/** Report a publication veto without overwriting or duplicating the page. */
	private static function not_public_error() {
		return new WP_Error( 'faz_policy_not_public', __( 'The cookie policy page is not public. Publish it without password protection in Pages, or turn off automatic page creation to finish setup.', 'faz-cookie-manager' ), array( 'status' => 409 ) );
	}

	/** Delete only the lease we observed, not a successor's lease. */
	private static function release_lock( $key, $token ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => $token ), array( '%s', '%s' ) );
		wp_cache_delete( $key, 'options' );
	}
}
