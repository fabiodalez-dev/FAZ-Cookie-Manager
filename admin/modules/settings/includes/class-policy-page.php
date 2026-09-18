<?php
/** Cookie policy page creation for guided setup. @package FazCookie */
namespace FazCookie\Admin\Modules\Settings\Includes;

use WP_Error;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Policy_Page {
	/** Wizard law => Cookie Policy generator jurisdiction. */
	const JURISDICTIONS = array( 'gdpr' => 'gdpr-strict', 'both' => 'gdpr-strict', 'ccpa' => 'ccpa-california', 'popia' => 'popia-southafrica' );

	/**
	 * Whether a policy template ships in exactly this language for this law.
	 *
	 * The generator falls back to the jurisdiction's native language or to
	 * English when a translation is missing; the wizard must never publish that
	 * fallback under another language's name, so only an exact match counts.
	 * Used by the setup view to disable page creation up front, and by
	 * validate() as the authoritative server-side check.
	 *
	 * @param mixed  $language Wizard language code.
	 * @param string $law      Wizard law key.
	 * @return bool
	 */
	public static function has_template( $language, $law ) {
		if ( ! is_scalar( $law ) || ! isset( self::JURISDICTIONS[ $law ] ) ) { return false; }
		$language = Generator::normalize_language_code( $language );
		if ( '' === $language ) { return false; }
		$path = Generator::resolve_template_path( self::JURISDICTIONS[ $law ], $language );
		return $path && basename( $path, '.md' ) === $language;
	}

	/** Refuse silent template-language fallbacks before publishing anything. */
	public static function validate( $language, $law ) {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return new WP_Error( 'faz_policy_permission', __( 'You need permission to publish pages to create the cookie policy.', 'faz-cookie-manager' ), array( 'status' => 403 ) );
		}
		$languages = \FazCookie\Admin\Modules\Languages\Includes\Controller::get_instance()->get_languages();
		if ( ! is_string( $language ) || ! in_array( $language, array_values( $languages ), true ) || ! in_array( $law, Onboarding::LAWS, true ) ) {
			return new WP_Error( 'faz_policy_language', __( 'Choose a supported language for the cookie policy.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		if ( ! self::has_template( $language, $law ) ) {
			return new WP_Error( 'faz_policy_translation_missing', __( 'A cookie policy template is not available in the selected language. Create the policy on the Cookie Policy page and translate it manually.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		$language = Generator::normalize_language_code( $language );
		return array( 'language' => $language, 'jurisdiction' => self::JURISDICTIONS[ $law ], 'path' => Generator::resolve_template_path( self::JURISDICTIONS[ $law ], $language ) );
	}

	/**
	 * The setup wizard's cookie-policy step. Advisory: it never fails setup.
	 *
	 * It runs only after Onboarding::finish() has saved the jurisdiction, so
	 * the setup is already complete. Returning an error at this point would
	 * report a saved configuration as a failure and invite a retry of work that
	 * succeeded. Every failure, from page creation to the banner link read-back,
	 * is therefore appended to finish()'s own advisory warning instead, and the
	 * administrator can create or link the page afterwards. When the page could
	 * not be created, the banner is left untouched and no cookie_page is set.
	 *
	 * @param array  $result   Successful Onboarding::finish() result.
	 * @param mixed  $language Wizard language code (the banner contents key).
	 * @param string $law      Wizard law key.
	 * @return array The result, with cookie_page on success and warning always a string.
	 */
	public static function apply_to_setup( array $result, $language, $law ) {
		$warnings = array();
		if ( isset( $result['warning'] ) && is_string( $result['warning'] ) && '' !== $result['warning'] ) {
			$warnings[] = $result['warning'];
		}
		$page = self::ensure( $language, $law );
		if ( is_wp_error( $page ) ) {
			/* translators: %s: the reason the cookie policy page could not be created. */
			$warnings[] = sprintf( __( 'Setup is complete, but the cookie policy page was not created: %s', 'faz-cookie-manager' ), $page->get_error_message() );
		} else {
			$result['cookie_page'] = $page;
			if ( ! self::link_banner( $language, $page['url'] ) ) {
				$warnings[] = __( 'The cookie policy page was created, but the banner could not be linked to it. Set the privacy link on the Cookie Banner page.', 'faz-cookie-manager' );
			}
		}
		$result['warning'] = implode( ' ', $warnings );
		return $result;
	}

	/**
	 * Point the active banner's privacy link at the generated page.
	 *
	 * A custom link the administrator already assigned is preserved; only an
	 * empty link, the unresolved default "/cookie-policy", or a link to a page
	 * this wizard generated is replaced. The saved banner is read back, because
	 * a save that silently dropped the link would leave visitors without it.
	 *
	 * @param mixed  $language Banner contents language key.
	 * @param string $url      Published page URL.
	 * @return bool False only when the link was written but did not persist.
	 */
	private static function link_banner( $language, $url ) {
		$banner = \FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance()->get_active_banner();
		if ( ! $banner ) { return true; }
		$contents = $banner->get_contents();
		$link = $contents[ $language ]['notice']['elements']['privacyLink'] ?? '';
		// Stored banner contents are not type-guaranteed: a non-string value
		// counts as no link at all rather than reaching the string functions.
		$link = is_scalar( $link ) ? (string) $link : '';
		$resolved_link = 0 === strpos( $link, '/' ) && 0 !== strpos( $link, '//' ) ? home_url( $link ) : $link;
		$linked_page = $resolved_link ? url_to_postid( $resolved_link ) : 0;
		if ( '' === $link || ( '/cookie-policy' === $link && ! $linked_page ) || ( $linked_page && get_post_meta( $linked_page, '_faz_setup_policy_language', true ) ) ) {
			$contents[ $language ]['notice']['elements']['privacyLink'] = $url;
			$banner->set_contents( $contents );
			$saved_id = $banner->save();
			$persisted = new \FazCookie\Admin\Modules\Banners\Includes\Banner( (int) $saved_id );
			$saved_contents = $persisted->get_contents();
			if ( ( $saved_contents[ $language ]['notice']['elements']['privacyLink'] ?? '' ) !== $url ) {
				return false;
			}
			faz_clear_banner_template_cache();
		}
		return true;
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
		return new WP_Error( 'faz_policy_not_public', __( 'The cookie policy page is not public. Publish it without password protection in Pages, then link it from the Cookie Banner page.', 'faz-cookie-manager' ), array( 'status' => 409 ) );
	}

	/** Delete only the lease we observed, not a successor's lease. */
	private static function release_lock( $key, $token ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => $token ), array( '%s', '%s' ) );
		wp_cache_delete( $key, 'options' );
	}
}
