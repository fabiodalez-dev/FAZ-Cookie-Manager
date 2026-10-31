<?php
/**
 * Built-in Google tag (#366): GA4 / Google tag, Google Ads / Floodlight and
 * Google Tag Manager loaded by the plugin itself.
 *
 * The plugin already knew how to gate these tags when a theme or another
 * plugin printed them: gtag.js and the GTM container are in the provider
 * catalogue, Basic Consent Mode keeps them blocked until consent and Advanced
 * Consent Mode lets gtag.js run cookieless after the denied default. What was
 * missing was a supported way to add them, so that placement, the dataLayer
 * name and the consent gate do not depend on how a snippet was pasted.
 *
 * This class only validates IDs and builds markup. Frontend decides whether
 * and where to print it.
 *
 * @package FazCookie
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google tag ID validation and snippet rendering.
 */
class Google_Tag {

	/**
	 * At most this many IDs are kept; a site needing more uses a container.
	 */
	const MAX_IDS = 5;

	/**
	 * Accepted ID shapes, by the provider that owns them in the catalogue.
	 *
	 * G- and GT- are Google tag / GA4 destinations, AW- Google Ads and DC-
	 * Floodlight (both loaded through gtag.js), GTM- a Tag Manager container.
	 *
	 * @var array<string, string> Regex => provider id.
	 */
	const PATTERNS = array(
		'/^(G|GT)-[A-Z0-9]{4,20}$/' => 'google-analytics',
		'/^(AW|DC)-[A-Z0-9]{4,20}$/' => 'google-ads',
		'/^GTM-[A-Z0-9]{4,12}$/'    => 'google-tag-manager',
	);

	/**
	 * Normalise a list of IDs typed by the site owner.
	 *
	 * Comma, semicolon or whitespace separated, case-insensitive. Anything that
	 * is not one of the accepted shapes is dropped (it is interpolated into a
	 * script URL and body, so it is validated, not escaped), duplicates are
	 * removed and the list is capped at MAX_IDS.
	 *
	 * @param mixed $value Raw value.
	 * @return string Canonical comma-separated list, possibly empty.
	 */
	public static function sanitize_ids( $value ) {
		return implode( ',', self::parse_ids( $value ) );
	}

	/**
	 * Parse a stored or typed list into valid, unique IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function parse_ids( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ',', array_filter( $value, 'is_scalar' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( preg_split( '/[\s,;]+/', strtoupper( (string) $value ), -1, PREG_SPLIT_NO_EMPTY ) as $id ) {
			if ( null !== self::provider_for( $id ) && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
			if ( count( $ids ) >= self::MAX_IDS ) {
				break;
			}
		}
		return $ids;
	}

	/**
	 * The catalogue provider an ID belongs to.
	 *
	 * @param string $id Upper-case ID.
	 * @return string|null Provider id, or null when the shape is not accepted.
	 */
	public static function provider_for( $id ) {
		foreach ( self::PATTERNS as $pattern => $provider ) {
			if ( preg_match( $pattern, (string) $id ) ) {
				return $provider;
			}
		}
		return null;
	}

	/**
	 * Consent category of a provider, read from the catalogue.
	 *
	 * Read rather than written down here, so the built-in tag waits for the
	 * same category a pasted copy of it would.
	 *
	 * @param string $provider Provider id.
	 * @param string $fallback Category used when the catalogue has none.
	 * @return string
	 */
	public static function category_for( $provider, $fallback ) {
		$all = Known_Providers::get_all();
		return isset( $all[ $provider ]['category'] ) && is_string( $all[ $provider ]['category'] ) && '' !== $all[ $provider ]['category']
			? $all[ $provider ]['category']
			: $fallback;
	}

	/**
	 * Build the markup for the given IDs.
	 *
	 * gtag.js IDs are grouped by provider and each group gets its own loader,
	 * so a visitor who accepts only Marketing still gets Google Ads and one who
	 * accepts only Analytics still gets GA4. A Tag Manager container is the
	 * standard loader and always waits for consent: it can host tags that do
	 * not read Consent Mode, which is also why Advanced Consent Mode never
	 * exempts it.
	 *
	 * Blocked markup is written in the shape the runtime already restores —
	 * `type="text/plain"` with `data-faz-category` and `data-faz-service` — so
	 * it does not depend on the output buffer: on a request the buffer skips,
	 * the tag stays parked instead of running without consent.
	 *
	 * @param string[] $ids  Valid IDs (parse_ids()).
	 * @param array    $args {
	 *     @type bool   $gtag_unblocked Print the gtag.js groups as live scripts
	 *                                  (Advanced Consent Mode, default already
	 *                                  emitted in front of them).
	 *     @type string $data_layer     dataLayer array name, same charset as
	 *                                  Frontend::gcm_datalayer_name() (it is
	 *                                  only used as a window[] key and a URL
	 *                                  parameter), so the Consent Mode default
	 *                                  and the tags share one array.
	 *     @type string $hints          Extra attribute string for optimiser opt-outs.
	 * }
	 * @return string
	 */
	public static function render( array $ids, array $args = array() ) {
		$unblocked  = ! empty( $args['gtag_unblocked'] );
		$data_layer = isset( $args['data_layer'] ) && is_string( $args['data_layer'] ) && preg_match( '/^[A-Za-z0-9_$]+$/', $args['data_layer'] )
			? $args['data_layer']
			: 'dataLayer';
		$hints      = isset( $args['hints'] ) && is_string( $args['hints'] ) ? $args['hints'] : '';
		$dl         = wp_json_encode( $data_layer );

		$groups = array();
		foreach ( self::parse_ids( implode( ',', $ids ) ) as $id ) {
			$groups[ self::provider_for( $id ) ][] = $id;
		}

		$out = '';
		foreach ( array( 'google-analytics' => 'analytics', 'google-ads' => 'marketing' ) as $provider => $fallback ) {
			if ( empty( $groups[ $provider ] ) ) {
				continue;
			}
			$gate = $unblocked ? '' : self::gate_attrs( $provider, self::category_for( $provider, $fallback ) );
			$src  = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $groups[ $provider ][0] );
			if ( 'dataLayer' !== $data_layer ) {
				$src .= '&l=' . rawurlencode( $data_layer );
			}
			$js = 'window[' . $dl . ']=window[' . $dl . ']||[];function gtag(){window[' . $dl . '].push(arguments);}gtag("js",new Date());';
			foreach ( $groups[ $provider ] as $id ) {
				$js .= 'gtag("config",' . wp_json_encode( $id ) . ');';
			}
			$out .= '<script' . $gate . ' async src="' . esc_url( $src ) . '" data-faz-google-tag="' . esc_attr( $provider ) . '"' . $hints . '></script>' . "\n";
			$out .= '<script' . $gate . ' data-faz-google-tag="' . esc_attr( $provider ) . '"' . $hints . '>' . $js . "</script>\n";
		}

		if ( ! empty( $groups['google-tag-manager'] ) ) {
			$gate = self::gate_attrs( 'google-tag-manager', self::category_for( 'google-tag-manager', 'analytics' ) );
			foreach ( $groups['google-tag-manager'] as $id ) {
				$js   = '(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});'
					. 'var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";'
					. 'j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);'
					. '})(window,document,"script",' . $dl . ',' . wp_json_encode( $id ) . ');';
				$out .= '<script' . $gate . ' data-faz-google-tag="google-tag-manager"' . $hints . '>' . $js . "</script>\n";
			}
		}

		return $out;
	}

	/**
	 * The provider of a gtag.js script this class printed, or '' (#371 review).
	 *
	 * The Ads/Floodlight loader is `googletagmanager.com/gtag/js?id=AW-…`, the
	 * same URL shape as the GA4 loader, so the catalogue's google-analytics
	 * pattern matches it too. Judged by that URL, a visitor who accepted
	 * Marketing and rejected Analytics (or denied the google-analytics service)
	 * never got the Ads loader, only its inline `config` call into a dataLayer
	 * nothing reads. The server already knows which provider each of its own
	 * scripts belongs to and prints it in data-faz-google-tag; this tells the
	 * blocking paths when that marker can be trusted over the URL match.
	 *
	 * Trusted only when the marker, the URL and every ID agree with what
	 * render() produces: a gtag.js loader whose `id` maps to the marker's
	 * provider, optionally with the `l` dataLayer parameter, or an inline body
	 * of exactly the generated bootstrap whose `config` IDs all map to it.
	 * Anything else — a theme script borrowing the attribute, another URL, an
	 * edited body — returns '' and keeps the URL matching it always had. The
	 * Tag Manager container is never trusted this way: it can host tags that
	 * do not read Consent Mode. Mirrored by _fazOwnGoogleTagProvider() in
	 * frontend/js/script.js.
	 *
	 * @param string $marker The data-faz-google-tag value.
	 * @param string $src    The src attribute, entity-decoded ('' for inline).
	 * @param string $body   The inline body ('' for a loader).
	 * @return string 'google-analytics', 'google-ads' or ''.
	 */
	public static function own_tag_provider( $marker, $src, $body ) {
		$marker = is_string( $marker ) ? $marker : '';
		if ( 'google-analytics' !== $marker && 'google-ads' !== $marker ) {
			return '';
		}
		$src = is_string( $src ) ? trim( $src ) : '';
		$ids = array();
		if ( '' !== $src ) {
			if ( ! preg_match( '#^https://www\.googletagmanager\.com/gtag/js\?id=([A-Z0-9-]+)(?:&l=[A-Za-z0-9_%]+)?$#', $src, $m ) ) {
				return '';
			}
			$ids[] = $m[1];
		} else {
			$body = is_string( $body ) ? trim( $body ) : '';
			if ( ! preg_match( '#^window\[("[A-Za-z0-9_$]+")\]=window\[\1\]\|\|\[\];function gtag\(\)\{window\[\1\]\.push\(arguments\);\}gtag\("js",new Date\(\)\);((?:gtag\("config","[A-Z0-9-]+"\);)+)$#', $body, $m ) ) {
				return '';
			}
			preg_match_all( '#gtag\("config","([A-Z0-9-]+)"\);#', $m[2], $all );
			$ids = $all[1];
		}
		foreach ( $ids as $id ) {
			if ( self::provider_for( $id ) !== $marker ) {
				return '';
			}
		}
		return $ids ? $marker : '';
	}

	/**
	 * The attributes that park a script until its category is accepted.
	 *
	 * @param string $provider Provider id, also the per-service consent key.
	 * @param string $category Consent category.
	 * @return string
	 */
	private static function gate_attrs( $provider, $category ) {
		return ' type="text/plain" data-faz-category="' . esc_attr( $category ) . '" data-faz-service="' . esc_attr( $provider ) . '"';
	}
}
