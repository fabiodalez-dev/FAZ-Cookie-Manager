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
	 * standard loader and waits for consent: it can host tags that do not read
	 * Consent Mode, which is why Advanced Consent Mode does not exempt it
	 * unless the site owner explicitly opts the container in (#385,
	 * `gtm_unblocked`).
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
	 *     @type bool   $gtm_unblocked  Print the Tag Manager container live as
	 *                                  well (#385: Advanced Consent Mode with
	 *                                  the container opt-in, default emitted,
	 *                                  its service not explicitly denied).
	 *     @type string $data_layer     dataLayer array name, same charset as
	 *                                  Frontend::gcm_datalayer_name() (it is
	 *                                  only used as a window[] key and a URL
	 *                                  parameter), so the Consent Mode default
	 *                                  and the tags share one array.
	 *     @type string $hints          Extra attribute string for optimiser opt-outs.
	 *     @type string $guard_cookie   Consent cookie name. When set, every tag
	 *                                  that would go out live is printed as a
	 *                                  client-side guard instead (see
	 *                                  guard_script()): the caller could not
	 *                                  read the visitor's svc.* choices.
	 *     @type string $guard_id       Id prefix for the guard scripts.
	 * }
	 * @return string
	 */
	public static function render( array $ids, array $args = array() ) {
		$unblocked  = ! empty( $args['gtag_unblocked'] );
		// The container never goes out live without the gtag exemption: that
		// is what guarantees the denied default is already in front of it.
		$gtm_live   = $unblocked && ! empty( $args['gtm_unblocked'] );
		$data_layer = isset( $args['data_layer'] ) && is_string( $args['data_layer'] ) && preg_match( '/^[A-Za-z0-9_$]+$/', $args['data_layer'] )
			? $args['data_layer']
			: 'dataLayer';
		$hints      = isset( $args['hints'] ) && is_string( $args['hints'] ) ? $args['hints'] : '';
		$dl         = wp_json_encode( $data_layer );
		// The cookie name is interpolated into a script body, so it is held to
		// the shape faz_consent_cookie_name() produces; anything else disables
		// the guard, and the caller's parked/live decision stands alone.
		$guard      = isset( $args['guard_cookie'] ) && is_string( $args['guard_cookie'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $args['guard_cookie'] )
			? $args['guard_cookie']
			: '';
		$guard_id   = isset( $args['guard_id'] ) && is_string( $args['guard_id'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $args['guard_id'] )
			? $args['guard_id']
			: '';

		$groups = array();
		foreach ( self::parse_ids( implode( ',', $ids ) ) as $id ) {
			$groups[ self::provider_for( $id ) ][] = $id;
		}

		$out = '';
		foreach ( array( 'google-analytics' => 'analytics', 'google-ads' => 'marketing' ) as $provider => $fallback ) {
			if ( empty( $groups[ $provider ] ) ) {
				continue;
			}
			$category = self::category_for( $provider, $fallback );
			$gate     = $unblocked ? '' : self::gate_attrs( $provider, $category );
			$src      = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $groups[ $provider ][0] );
			if ( 'dataLayer' !== $data_layer ) {
				$src .= '&l=' . rawurlencode( $data_layer );
			}
			$js = 'window[' . $dl . ']=window[' . $dl . ']||[];function gtag(){window[' . $dl . '].push(arguments);}gtag("js",new Date());';
			foreach ( $groups[ $provider ] as $id ) {
				$js .= 'gtag("config",' . wp_json_encode( $id ) . ');';
			}
			if ( $unblocked && '' !== $guard ) {
				$out .= self::guard_script( $provider, $category, $guard, $guard_id, $hints, $src, $js );
				continue;
			}
			$out .= '<script' . $gate . ' async src="' . esc_url( $src ) . '" data-faz-google-tag="' . esc_attr( $provider ) . '"' . $hints . '></script>' . "\n";
			$out .= '<script' . $gate . ' data-faz-google-tag="' . esc_attr( $provider ) . '"' . $hints . '>' . $js . "</script>\n";
		}

		if ( ! empty( $groups['google-tag-manager'] ) ) {
			$category = self::category_for( 'google-tag-manager', 'analytics' );
			$gate     = $gtm_live ? '' : self::gate_attrs( 'google-tag-manager', $category );
			foreach ( $groups['google-tag-manager'] as $index => $id ) {
				$js   = '(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});'
					. 'var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";'
					. 'j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);'
					. '})(window,document,"script",' . $dl . ',' . wp_json_encode( $id ) . ');';
				if ( $gtm_live && '' !== $guard ) {
					$out .= self::guard_script( 'google-tag-manager', $category, $guard, '' !== $guard_id && $index > 0 ? $guard_id . '-' . ( $index + 1 ) : $guard_id, $hints, '', $js );
					continue;
				}
				$out .= '<script' . $gate . ' data-faz-google-tag="google-tag-manager"' . $hints . '>' . $js . "</script>\n";
			}
		}

		return $out;
	}

	/**
	 * A live tag wrapped in a check of the visitor's own per-service choice.
	 *
	 * Under Cache Compatibility Mode and the jurisdiction shell the HTML is the
	 * same for every visitor, so the server cannot read `svc.<id>:no` from the
	 * consent cookie and a tag it prints live runs for a visitor who switched
	 * that very service off: this markup sits at the top of <head>, the runtime
	 * that enforces per-service choices loads after it, and a script the parser
	 * has already executed cannot be taken back. The decision therefore moves
	 * into the tag itself. The guard reads the consent cookie and creates the
	 * same scripts render() would have printed: live when the service is not
	 * denied, otherwise parked exactly like the server-side parked markup
	 * (`type="text/plain"`, data-faz-category, data-faz-service), so the
	 * runtime releases them the moment the visitor switches the service back
	 * on. The created scripts keep the data-faz-google-tag marker and the exact
	 * src / body own_tag_provider() and _fazOwnGoogleTagProvider() recognise.
	 *
	 * Only an explicit `svc.<id>:no` parks the tag: the caller already decided
	 * it may load before consent (Advanced Consent Mode, denied default in
	 * front of it), which is the same rule the server applies when it can read
	 * the cookie. A cookie jar that cannot be read parks it too.
	 *
	 * @param string $provider Provider id, also the per-service consent key.
	 * @param string $category Consent category the parked copy waits for.
	 * @param string $cookie   Consent cookie name (validated by render()).
	 * @param string $id_base  Id prefix (validated by render()), or ''.
	 * @param string $hints    Extra attribute string for optimiser opt-outs.
	 * @param string $src      Loader URL, or '' for an inline-only tag.
	 * @param string $body     Inline body.
	 * @return string
	 */
	private static function guard_script( $provider, $category, $cookie, $id_base, $hints, $src, $body ) {
		// JSON_HEX_TAG: the values are inside a <script>, none may close it.
		$flags = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES;
		$js    = '(function(d,k,s,c,u,b){var no=true;'
			. 'try{var v="",p=d.cookie.split(";"),i,t;'
			. 'for(i=0;i<p.length;i++){t=p[i].replace(/^\\s+/,"");if(t.indexOf(k+"=")===0){v=t.substring(k.length+1);break;}}'
			. 'try{v=decodeURIComponent(v);}catch(e){}'
			. 'no=(","+v+",").indexOf(",svc."+s+":no,")!==-1;}catch(e){}'
			. 'function mk(src,body){var e=d.createElement("script");e.setAttribute("data-faz-google-tag",s);'
			. 'if(no){e.type="text/plain";e.setAttribute("data-faz-category",c);e.setAttribute("data-faz-service",s);}'
			. 'if(src){e.async=true;e.setAttribute("src",src);}else{e.text=body;}'
			. '(d.head||d.documentElement).appendChild(e);}'
			. 'if(u){mk(u,"");}mk("",b);'
			. '})(document,' . wp_json_encode( $cookie, $flags ) . ',' . wp_json_encode( $provider, $flags ) . ',' . wp_json_encode( $category, $flags )
			. ',' . wp_json_encode( $src, $flags ) . ',' . wp_json_encode( $body, $flags ) . ');';
		$id    = '' !== $id_base ? ' id="' . esc_attr( $id_base . '-' . $provider . '-js-before' ) . '"' : '';
		return '<script' . $id . ' data-faz-google-tag-guard="' . esc_attr( $provider ) . '"' . $hints . '>' . $js . "</script>\n";
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
