<?php
/**
 * Placeholder builder for blocked third-party content.
 *
 * Generates branded, accessible placeholder HTML when iframes, oEmbeds,
 * or social embeds are blocked pending cookie consent.
 *
 * @package FazCookie\Frontend\Includes
 */

namespace FazCookie\Frontend\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Placeholder_Builder
 */
class Placeholder_Builder {

	/**
	 * Service icons as inline SVG paths (compact, 24x24 viewBox).
	 *
	 * @var array<string,string>
	 */
	private static $service_icons = array(
		'youtube'     => '<path d="M23.5 6.2c-.3-1-1-1.8-2-2.1C19.6 3.5 12 3.5 12 3.5s-7.6 0-9.5.6c-1 .3-1.8 1-2 2.1C0 8.1 0 12 0 12s0 3.9.5 5.8c.3 1 1 1.8 2 2.1 1.9.6 9.5.6 9.5.6s7.6 0 9.5-.6c1-.3 1.8-1 2-2.1.5-1.9.5-5.8.5-5.8s0-3.9-.5-5.8zM9.5 15.6V8.4l6.3 3.6-6.3 3.6z" fill="#FF0000"/>',
		'vimeo'       => '<path d="M22.9 6.2c-.1 2.2-1.6 5.1-4.7 8.9C15 18.9 12.4 20.5 10.2 20.5c-1.4 0-2.5-1.3-3.4-3.8L5 9.5C4.4 7 3.7 5.7 3 5.7c-.2 0-.8.4-1.8 1.1L0 5.3 3.3 2.4c1.5-1.3 2.6-2 3.4-2 1.8-.2 2.9 1 3.3 3.6.4 2.8.7 4.5.9 5.2.5 2.3 1 3.4 1.7 3.4.5 0 1.2-.8 2.1-2.3.9-1.5 1.4-2.7 1.5-3.5.1-1.4-.4-2.1-1.5-2.1-.5 0-1.1.1-1.7.4 1.1-3.7 3.3-5.5 6.4-5.3 2.3.1 3.4 1.6 3.3 4.4z" fill="#1AB7EA"/>',
		'google-maps' => '<path d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7zm0 9.5c-1.4 0-2.5-1.1-2.5-2.5S10.6 6.5 12 6.5 14.5 7.6 14.5 9 13.4 11.5 12 11.5z" fill="#4285F4"/>',
		'facebook'    => '<path d="M24 12c0-6.6-5.4-12-12-12S0 5.4 0 12c0 6 4.4 11 10.1 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.7 4.5-4.7 1.3 0 2.7.2 2.7.2v3h-1.5c-1.5 0-2 .9-2 1.9V12h3.3l-.5 3.5h-2.8v8.4C19.6 23 24 18 24 12z" fill="#1877F2"/>',
		'instagram'   => '<path d="M12 2.2c3.2 0 3.6 0 4.8.1 3.5.2 5.1 1.7 5.3 5.3.1 1.3.1 1.6.1 4.8 0 3.2 0 3.6-.1 4.8-.2 3.5-1.8 5.1-5.3 5.3-1.3.1-1.6.1-4.8.1-3.2 0-3.6 0-4.8-.1-3.5-.2-5.1-1.8-5.3-5.3-.1-1.3-.1-1.6-.1-4.8 0-3.2 0-3.6.1-4.8.2-3.5 1.8-5.1 5.3-5.3 1.3-.1 1.6-.1 4.8-.1zM12 0C8.7 0 8.3 0 7.1.1 2.7.3.3 2.7.1 7.1 0 8.3 0 8.7 0 12s0 3.7.1 4.9c.2 4.4 2.6 6.8 7 7 1.2.1 1.6.1 4.9.1s3.7 0 4.9-.1c4.4-.2 6.8-2.6 7-7 .1-1.2.1-1.6.1-4.9s0-3.7-.1-4.9c-.2-4.4-2.6-6.8-7-7C16.7 0 16.3 0 12 0zm0 5.8a6.2 6.2 0 100 12.4 6.2 6.2 0 000-12.4zM12 16a4 4 0 110-8 4 4 0 010 8zm6.4-10.8a1.4 1.4 0 100 2.8 1.4 1.4 0 000-2.8z" fill="#E4405F"/>',
		'twitter'     => '<path d="M18.2 2h3.6l-7.9 9 9.3 12.3h-7.3l-5.7-7.4-6.5 7.4H.1l8.4-9.6L0 2h7.5l5.1 6.8L18.2 2zm-1.3 19.1h2L7.3 4H5.1l11.8 17.1z" fill="#000"/>',
		'spotify'     => '<path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.6 0 12 0zm5.5 17.3c-.2.3-.6.4-.9.2-2.6-1.6-5.8-1.9-9.6-1.1-.4.1-.7-.2-.8-.5-.1-.4.2-.7.5-.8 4.2-.9 7.8-.5 10.6 1.2.4.2.4.7.2 1zm1.5-3.3c-.3.4-.8.5-1.2.3-3-1.8-7.5-2.4-11-1.3-.4.1-.9-.1-1-.6-.1-.4.1-.9.6-1 4-.1.2 8.9.7 12.2 2.5.3.2.5.8.2 1.1zm.1-3.4C15.3 8.4 8.9 8.2 5.2 9.3c-.5.2-1-.2-1.2-.7-.2-.5.2-1 .7-1.2 4.3-1.3 11.4-1 15.9 1.5.5.3.6.9.4 1.3-.3.5-.9.6-1.3.4z" fill="#1DB954"/>',
		'dailymotion' => '<path d="M12.1 2C6.5 2 2 6.5 2 12.1s4.5 10.1 10.1 10.1c2.4 0 4.7-.9 6.5-2.4v2h3.4V12.1C22 6.5 17.5 2 12.1 2zm0 16.1c-3.3 0-6-2.7-6-6s2.7-6 6-6 6 2.7 6 6-2.7 6-6 6z" fill="#00B2FF"/>',
		'soundcloud'  => '<path d="M1.2 14.3c-.1 0-.2-.1-.2-.2l-.3-2.1.3-2.2c0-.1.1-.2.2-.2s.2.1.2.2l.4 2.2-.4 2.1c0 .1-.1.2-.2.2zm1.7.5c-.1 0-.2-.1-.3-.2L2.3 12l.3-3.1c0-.1.1-.2.3-.2.1 0 .2.1.2.2l.4 3.1-.4 2.6c0 .1-.1.2-.2.2zm1.7.2c-.1 0-.2-.1-.3-.3l-.3-2.7.3-3.7c0-.1.1-.3.3-.3.1 0 .2.1.3.3l.3 3.7-.3 2.7c0 .2-.1.3-.3.3zm1.8 0c-.2 0-.3-.1-.3-.3l-.3-2.7.3-4.2c0-.2.1-.3.3-.3s.3.1.3.3l.3 4.2-.3 2.7c0 .2-.1.3-.3.3zM8 15c-.2 0-.3-.2-.3-.3l-.3-2.7.3-4.5c0-.2.2-.3.3-.3.2 0 .3.2.3.3l.3 4.5-.3 2.7c-.1.2-.2.3-.3.3zm1.7.1c-.2 0-.4-.2-.4-.4l-.2-2.7.2-4.8c0-.2.2-.4.4-.4s.4.2.4.4l.2 4.8-.2 2.7c0 .2-.2.4-.4.4zm1.8 0c-.2 0-.4-.2-.4-.4L10.9 12l.2-5c0-.2.2-.4.4-.4.2 0 .4.2.4.4l.2 5-.2 2.7c0 .2-.2.4-.4.4zm2.2-.1c-.3 0-.4-.2-.5-.4l-.1-2.6.1-5c0-.3.2-.5.5-.5.2 0 .4.2.5.5l.1 5-.1 2.6c0 .3-.2.5-.5.5zM22 9c-.6 0-1.2.1-1.7.4-.3-3.2-3-5.7-6.3-5.7-.8 0-1.5.1-2.2.4-.3.1-.4.2-.4.5v10.1c0 .3.2.5.4.5h10.2c1.6 0 2.9-1.3 2.9-2.9 0-1.8-1.3-3.3-2.9-3.3z" fill="#FF5500"/>',
		'twitch'      => '<path d="M11.6 11h-1.4V6.6h1.4V11zm3.8 0h-1.4V6.6h1.4V11zM7 1L3.4 4.6v14.8h4.2V23l3.6-3.6h2.8L21 12.6V1H7zm12.6 11l-2.8 2.8h-2.8L11.2 17.6v-2.8H7.6V2.4h12v9.6z" fill="#9146FF"/>',
		'default'     => '<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm0 18c-4.4 0-8-3.6-8-8s3.6-8 8-8 8 3.6 8 8-3.6 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z" fill="#666"/>',
	);

	/**
	 * Map URL fragments to service identifiers.
	 *
	 * @var array<string,string>
	 */
	private static $url_service_map = array(
		'youtube.com'          => 'youtube',
		'youtube-nocookie.com' => 'youtube',
		'youtu.be'             => 'youtube',
		'vimeo.com'            => 'vimeo',
		'google.com/maps'      => 'google-maps',
		'maps.google'          => 'google-maps',
		'facebook.com'         => 'facebook',
		'instagram.com'        => 'instagram',
		'twitter.com'          => 'twitter',
		'x.com'                => 'twitter',
		'spotify.com'          => 'spotify',
		'dailymotion.com'      => 'dailymotion',
		'soundcloud.com'       => 'soundcloud',
		'twitch.tv'            => 'twitch',
	);

	/**
	 * Map service IDs to human-readable names.
	 *
	 * @var array<string,string>
	 */
	private static $service_names = array(
		'youtube'     => 'YouTube',
		'vimeo'       => 'Vimeo',
		'google-maps' => 'Google Maps',
		'facebook'    => 'Facebook',
		'instagram'   => 'Instagram',
		'twitter'     => 'Twitter/X',
		'spotify'     => 'Spotify',
		'dailymotion' => 'Dailymotion',
		'soundcloud'  => 'SoundCloud',
		'twitch'      => 'Twitch',
	);

	/**
	 * Build a placeholder for blocked content.
	 *
	 * @param string $service_id     Service identifier (e.g., 'youtube', 'google-maps').
	 * @param string $service_name   Human-readable name (e.g., 'YouTube').
	 * @param string $category       Consent category slug.
	 * @param string $blocked_html   Original blocked HTML (stored in <template> for JS restoration).
	 * @param string $thumbnail_url  Optional thumbnail URL for video embeds.
	 * @return string Placeholder HTML.
	 */
	public static function build( $service_id, $service_name, $category, $blocked_html, $thumbnail_url = '' ) {
		$icon_svg = isset( self::$service_icons[ $service_id ] )
			? self::$service_icons[ $service_id ]
			: self::$service_icons['default'];

		$has_thumb = ! empty( $thumbnail_url );
		$class     = 'faz-placeholder' . ( $has_thumb ? ' faz-placeholder--video' : '' );

		$message = sprintf(
			/* translators: %s: service name (e.g., "YouTube", "Google Maps") */
			esc_html__( 'This content is blocked because %s cookies have not been accepted.', 'faz-cookie-manager' ),
			esc_html( $service_name )
		);

		$button_text = esc_html__( 'Accept cookies', 'faz-cookie-manager' );

		$html  = '<div class="' . esc_attr( $class ) . '" data-faz-category="' . esc_attr( $category ) . '">';

		if ( $has_thumb ) {
			$html .= '<img class="faz-placeholder-thumb" src="' . esc_url( $thumbnail_url ) . '" alt="" loading="lazy"/>';
		}

		$html .= '<div class="faz-placeholder-overlay">';
		$html .= '<svg class="faz-placeholder-icon" viewBox="0 0 24 24" width="32" height="32" xmlns="http://www.w3.org/2000/svg">' . $icon_svg . '</svg>';
		$html .= '<p class="faz-placeholder-msg">' . $message . '</p>';
		$html .= '<button type="button" class="faz-placeholder-btn" data-faz-accept="' . esc_attr( $category ) . '">' . $button_text . '</button>';
		$html .= '</div>';

		// Hidden original content for JS to restore after consent.
		// Sanitize with wp_kses to prevent XSS from crafted oEmbed/post content.
		$safe_html = wp_kses( $blocked_html, array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'iframe' => array(
					'src' => true, 'data-faz-src' => true, 'data-faz-category' => true,
					'width' => true, 'height' => true, 'frameborder' => true,
					'allow' => true, 'allowfullscreen' => true, 'loading' => true,
					'style' => true, 'class' => true, 'id' => true, 'title' => true,
				),
				'script' => array(
					'type' => true, 'src' => true, 'data-faz-category' => true,
					'data-faz-src' => true, 'async' => true, 'defer' => true,
				),
			)
		) );
		$html .= '<template class="faz-placeholder-content">' . $safe_html . '</template>';

		$html .= '</div>';

		return $html;
	}

	/**
	 * Build a social-embed placeholder (no <template> — the original element
	 * stays hidden as the next sibling in the DOM).
	 *
	 * @param string $service_id   Service identifier (e.g., 'facebook', 'instagram').
	 * @param string $service_name Human-readable name.
	 * @param string $category     Consent category slug.
	 * @return string Placeholder HTML (placed before the hidden original element).
	 */
	public static function build_social( $service_id, $service_name, $category ) {
		$icon_svg = isset( self::$service_icons[ $service_id ] )
			? self::$service_icons[ $service_id ]
			: self::$service_icons['default'];

		$message = sprintf(
			/* translators: %s: service name (e.g., "YouTube", "Google Maps") */
			esc_html__( 'This content is blocked because %s cookies have not been accepted.', 'faz-cookie-manager' ),
			esc_html( $service_name )
		);

		$button_text = esc_html__( 'Accept cookies', 'faz-cookie-manager' );

		$html  = '<div class="faz-placeholder faz-placeholder--social faz-social-placeholder" data-faz-category="' . esc_attr( $category ) . '">';
		$html .= '<div class="faz-placeholder-overlay">';
		$html .= '<svg class="faz-placeholder-icon" viewBox="0 0 24 24" width="32" height="32" xmlns="http://www.w3.org/2000/svg">' . $icon_svg . '</svg>';
		$html .= '<p class="faz-placeholder-msg">' . $message . '</p>';
		$html .= '<button type="button" class="faz-placeholder-btn" data-faz-accept="' . esc_attr( $category ) . '">' . $button_text . '</button>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Detect service identifier from a URL.
	 *
	 * @param string $url URL to inspect.
	 * @return string Service identifier (e.g. 'youtube') or 'default'.
	 */
	public static function detect_service_from_url( $url ) {
		foreach ( self::$url_service_map as $domain => $id ) {
			if ( false !== stripos( $url, $domain ) ) {
				return $id;
			}
		}
		return 'default';
	}

	/**
	 * Get human-readable service name from a service ID.
	 *
	 * @param string $service_id Service identifier.
	 * @return string Human-readable name.
	 */
	public static function get_service_name( $service_id ) {
		if ( isset( self::$service_names[ $service_id ] ) ) {
			return self::$service_names[ $service_id ];
		}
		return __( 'third-party', 'faz-cookie-manager' );
	}

	/**
	 * Extract a video thumbnail URL (YouTube only for now — no external API needed).
	 *
	 * img.youtube.com is a static CDN that serves no cookies or tracking.
	 *
	 * @param string $url Video URL or iframe src.
	 * @return string Thumbnail URL or empty string.
	 */
	public static function get_video_thumbnail( $url ) {
		// YouTube: embed, watch, or short URL.
		if ( preg_match( '/(?:youtube(?:-nocookie)?\.com\/(?:embed\/|watch\?v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m ) ) {
			return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
		}
		return '';
	}

	/**
	 * Return the placeholder CSS rules.
	 *
	 * Intended to be output once in the <head> via insert_styles().
	 *
	 * @return string Minified CSS.
	 */
	public static function get_css() {
		return '.faz-placeholder{position:relative;width:100%;min-height:180px;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
			. '.faz-placeholder--video{aspect-ratio:16/9;min-height:0}'
			. '.faz-placeholder-thumb{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:blur(4px) brightness(.7)}'
			. '.faz-placeholder-overlay{position:relative;z-index:1;text-align:center;padding:24px;color:#555}'
			. '.faz-placeholder--video .faz-placeholder-overlay{color:#fff;background:rgba(0,0,0,.55);border-radius:8px;padding:24px 32px}'
			. '.faz-placeholder-icon{margin:0 auto 12px;display:block}'
			. '.faz-placeholder-msg{margin:0 0 16px;font-size:14px;line-height:1.5;max-width:320px}'
			. '.faz-placeholder-btn{background:#1863DC;color:#fff;border:none;padding:10px 24px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:500;transition:background .2s}'
			. '.faz-placeholder-btn:hover{background:#1453b8}'
			. '.faz-placeholder--social{min-height:120px}';
	}
}
