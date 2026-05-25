<?php
/**
 * Class Renderer file — orchestrates Generator + cookie-list + disclaimer.
 *
 * Spec: specs/002-cookie-policy-generator/spec.md FR-03 + FR-04 + FR-06
 *
 * Pipeline (called by the shortcode handler):
 *   1. Resolve lang  (request override > admin default > get_locale)
 *   2. Resolve jurisdiction  (request override > admin default > gdpr-strict)
 *   3. Load scaffold via Generator::resolve_template_path()
 *   4. Build data array (admin settings + cookie list + jurisdiction-specific refs)
 *   5. Substitute placeholders via Generator::substitute()
 *   6. Convert markdown → HTML via Generator::markdown_to_html()
 *   7. Append the non-removable disclaimer (FR-04 — hardcoded, NOT override-able)
 *   8. wp_kses_post the whole thing for output safety
 *
 * @package FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes
 * @since   1.16.0
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cookie policy renderer.
 *
 * @class    Renderer
 * @since    1.16.0
 */
class Renderer {

	const SETTINGS_OPTION = 'faz_cookie_policy_data';

	/**
	 * Static cache for the cookie-list HTML — FR-06 mandates 5min TTL via
	 * wp_cache; this is the per-request micro-cache so the same shortcode
	 * called twice on the same page doesn't re-render the list.
	 *
	 * @var array<string,string>
	 */
	private static $cookie_list_cache = array();

	/**
	 * Public entry point used by the shortcode handler.
	 *
	 * @param array<string,string> $atts Shortcode attributes:
	 *                                   - 'lang' (optional)
	 *                                   - 'jurisdiction' (optional)
	 * @return string HTML (already wp_kses_post'd, safe to echo).
	 */
	public static function render( $atts = array() ) {
		$settings = (array) get_option( self::SETTINGS_OPTION, array() );

		// Merge a minimal structural baseline so substitution doesn't trip on
		// missing keys when the option is absent. See baseline_defaults() —
		// it deliberately does NOT seed admin_email / blogname into the
		// public-facing fields (those are PII / operational values that must
		// only appear once the admin explicitly saves them). Existing saved
		// values win on key collision.
		$settings = array_replace_recursive( self::baseline_defaults(), $settings );

		// FR-03 step 1: resolve language.
		$lang = self::resolve_lang( $atts, $settings );

		// FR-03 step 2: resolve jurisdiction.
		$jurisdiction = self::resolve_jurisdiction( $atts, $settings );

		// FR-03 step 3: load scaffold.
		$template_path = Generator::resolve_template_path( $jurisdiction, $lang );
		if ( null === $template_path ) {
			// NFR-03 graceful no-op + admin notice.
			return self::no_template_notice( $jurisdiction, $lang );
		}
		// No error-suppression on the read: the `null === $template_path`
		// guard above already excludes the "no template" case, and an I/O
		// failure here (permissions, disk full, deleted file mid-request)
		// is a real problem the operator should see in their debug log
		// rather than silently degrade to the empty-string branch.
		// The empty-result branch below still handles a legitimate empty
		// template file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin-shipped Markdown template, not user content.
		$scaffold = (string) file_get_contents( $template_path );
		if ( '' === $scaffold ) {
			return self::no_template_notice( $jurisdiction, $lang );
		}

		// FR-03 step 4: build data.
		$data = self::build_data( $settings, $jurisdiction, $lang );

		// FR-03 step 5+6: substitute + convert.
		//
		// HTML-valued tokens (COOKIE_CATEGORIES, THIRD_PARTY_SERVICES) MUST NOT
		// flow through markdown_to_html(): the line-based parser only preserves
		// a narrow allowlist of OPENING tags, so closing tags (`</dt>`, `</dd>`)
		// and inline tags (`<small>`, `<strong>`) would be wrapped in spurious
		// `<p>` blocks and produce invalid nesting like `<p><small>…</small></dt></p>`.
		// Two-pass strategy: substitute HTML tokens with single-line sentinels
		// (plain text — markdown is happy), run markdown, then swap each
		// sentinel back for the real HTML. Standalone-line sentinels get the
		// surrounding `<p>` wrapper stripped so the injected block sits at the
		// right nesting level.
		$html_tokens   = array_intersect_key( $data, array_flip( Generator::HTML_TOKENS ) );
		$data_for_md   = $data;
		foreach ( array_keys( $html_tokens ) as $token_name ) {
			$data_for_md[ $token_name ] = Generator::html_token_sentinel( $token_name );
		}

		// Defensive: strip any literal occurrence of the sentinel strings
		// from the HTML values themselves before substitution. The sentinel
		// format (`__FAZ_HTML_TOKEN_*__`, double-underscores + all caps) is
		// exotic enough to make real collisions vanishingly rare, but a DB
		// row containing the literal sentinel as a cookie name or description
		// would cause the post-markdown str_replace pass to double-insert.
		// O(N) prefix scan over a handful of cookie rows — negligible cost.
		foreach ( $html_tokens as $token_name => $html_value ) {
			$s = Generator::html_token_sentinel( $token_name );
			if ( is_string( $html_value ) && false !== strpos( $html_value, $s ) ) {
				$html_tokens[ $token_name ] = str_replace( $s, '', $html_value );
			}
		}

		$markdown = Generator::substitute( $scaffold, $data_for_md );
		$html     = Generator::markdown_to_html( $markdown );

		foreach ( $html_tokens as $token_name => $html_value ) {
			$sentinel = Generator::html_token_sentinel( $token_name );
			// Standalone-line case: markdown wrapped the sentinel in `<p>…</p>`.
			// Strip the wrapper so the block-level HTML isn't nested inside <p>.
			$html = (string) preg_replace(
				'/<p>\s*' . preg_quote( $sentinel, '/' ) . '\s*<\/p>/',
				(string) $html_value,
				$html
			);
			// Inline-case fallback: sentinel sat mid-paragraph. Replace in place;
			// the surrounding <p> stays. HTML-block tokens are conventionally
			// standalone so this branch is defensive — covered by tests.
			$html = str_replace( $sentinel, (string) $html_value, $html );
		}

		// FR-04: append the non-removable disclaimer. Hardcoded, NOT in the
		// template file (so admin section-overrides cannot suppress it).
		$html .= self::disclaimer( $jurisdiction, $lang, $data );

		// FR-07: compute the policy version hash. Exposed in <head> (if
		// wp_head hasn't fired) AND as a data-faz-policy-version attribute
		// on the article wrapper (HTML5-clean, always survives).
		$policy_version = self::register_version_meta( $template_path, $data );

		// Wrap in <article> per NFR-02-X accessibility.
		$wrapper_open  = '<article class="faz-cookie-policy" lang="' . esc_attr( $lang )
			. '" data-jurisdiction="' . esc_attr( $jurisdiction )
			. '" data-faz-policy-version="' . esc_attr( $policy_version ) . '">';
		$wrapper_close = '</article>';

		// NFR-02-XI: sanitize the body content via wp_kses_post. The wrapper
		// is emitted by trusted code (no user input reaches it un-escaped)
		// and bypasses the kses pass.
		return $wrapper_open . wp_kses_post( $html ) . $wrapper_close;
	}

	/**
	 * Baseline defaults the public shortcode falls back to on a fresh install.
	 *
	 * This is a PUBLIC-FACING safety floor for `[faz_cookie_policy_complete]`, NOT a
	 * UX prefill. It is distinct from `Cookie_Policy_Api::default_settings()`,
	 * which prefills the admin form (that runs admin-side, behind auth, where
	 * exposing `blogname` / `admin_email` to the operator is fine).
	 *
	 * Anything seeded here will surface in the rendered public policy without
	 * any admin Save — so we deliberately do NOT seed
	 * `get_option( 'admin_email' )` or `get_option( 'blogname' )` here. The
	 * admin email in particular is PII (often a real person's mailbox) and
	 * must not be published as the controller contact until the operator
	 * explicitly confirms it via the Cookie Policy admin form.
	 *
	 * Keep this minimal — only the structural keys the renderer needs to walk
	 * (jurisdiction, retention_months, empty company/dpo arrays, etc.) so
	 * substitution doesn't trip on missing keys. Real values must come from
	 * the saved `faz_cookie_policy_data` option.
	 *
	 * @return array
	 */
	private static function baseline_defaults() {
		return array(
			'jurisdiction'         => 'gdpr-strict',
			'company'              => array(
				'name'     => '',
				'address'  => '',
				'email'    => '',
				'registry' => '',
			),
			'dpo'                  => array(
				'name'    => '',
				'email'   => '',
				'address' => '',
			),
			'retention_months'     => 12,
			'privacy_policy_url'   => '',
			'third_party_services' => array(),
			'section_overrides'    => array(),
		);
	}

	/**
	 * Resolve effective language. Honour explicit attr > admin default > get_locale.
	 *
	 * @param array $atts
	 * @param array $settings
	 * @return string
	 */
	private static function resolve_lang( array $atts, array $settings ) {
		// Explicit shortcode attr.
		if ( ! empty( $atts['lang'] ) && self::is_supported_lang( $atts['lang'] ) ) {
			return self::normalize_lang( $atts['lang'] );
		}
		// Admin default for this page (less common — usually we follow visitor locale).
		if ( ! empty( $settings['default_lang'] ) && self::is_supported_lang( $settings['default_lang'] ) ) {
			return self::normalize_lang( $settings['default_lang'] );
		}
		// WordPress get_locale → first 2 chars (en_US → en, it_IT → it, pt_BR → pt-BR).
		$wp_locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en';
		$candidate = self::wp_locale_to_template_lang( $wp_locale );
		if ( self::is_supported_lang( $candidate ) ) {
			return $candidate;
		}
		return 'en';
	}

	/**
	 * Resolve effective jurisdiction. Explicit > admin default > gdpr-strict.
	 *
	 * @param array $atts
	 * @param array $settings
	 * @return string
	 */
	private static function resolve_jurisdiction( array $atts, array $settings ) {
		if ( ! empty( $atts['jurisdiction'] ) && in_array( $atts['jurisdiction'], Generator::JURISDICTIONS, true ) ) {
			return (string) $atts['jurisdiction'];
		}
		if ( ! empty( $settings['jurisdiction'] ) && in_array( $settings['jurisdiction'], Generator::JURISDICTIONS, true ) ) {
			return (string) $settings['jurisdiction'];
		}
		return 'gdpr-strict';
	}

	/**
	 * Build the substitution-data array.
	 *
	 * @param array  $settings    Admin form payload.
	 * @param string $jurisdiction
	 * @param string $lang
	 * @return array<string,string>
	 */
	private static function build_data( array $settings, $jurisdiction, $lang ) {
		$company = (array) ( $settings['company'] ?? array() );
		$dpo     = (array) ( $settings['dpo'] ?? array() );

		$data = array(
			'COMPANY_NAME'           => esc_html( (string) ( $company['name'] ?? '' ) ),
			'COMPANY_ADDRESS'        => esc_html( (string) ( $company['address'] ?? '' ) ),
			'COMPANY_EMAIL'          => esc_html( (string) ( $company['email'] ?? '' ) ),
			'COMPANY_REGISTRY'       => esc_html( (string) ( $company['registry'] ?? '' ) ),
			'DPO_EMAIL'              => esc_html( (string) ( $dpo['email'] ?? '' ) ),
			'DPO_NAME'               => esc_html( (string) ( $dpo['name'] ?? '' ) ),
			'COOKIE_CATEGORIES'      => self::build_cookie_list_html( $lang ),
			'THIRD_PARTY_SERVICES'   => self::build_services_list( $settings ),
			'LAST_UPDATED_DATE'      => esc_html( self::format_date( $lang ) ),
			'COOKIE_POLICY_URL'      => esc_url( self::current_url() ),
			'PRIVACY_POLICY_URL'     => esc_url( (string) ( $settings['privacy_policy_url'] ?? '' ) ),
			'RETENTION_PERIOD'       => esc_html( self::format_retention( $settings, $lang ) ),
			'JURISDICTION_NAME'      => esc_html( self::jurisdiction_display_name( $jurisdiction, $lang ) ),
			'LANGUAGE_NAME'          => esc_html( self::language_display_name( $lang, $lang ) ),
			'OFFICIAL_RESOURCES_URL' => esc_url( self::official_resources_url( $jurisdiction ) ),
		);

		// Jurisdiction-specific official body refs.
		$data['EDPB_CONTACT']   = ( 'gdpr-strict' === $jurisdiction ) ? 'edpb@edpb.europa.eu' : '';
		$data['CA_PIPC_CONTACT'] = ( 'ccpa-california' === $jurisdiction ) ? 'cppa@cppa.ca.gov' : '';
		$data['ANPD_CONTACT']   = ( 'lgpd-brazil' === $jurisdiction ) ? 'comunicacao@anpd.gov.br' : '';

		/**
		 * Filter the data array passed to the template substitution.
		 *
		 * Use this to inject site-specific placeholders or override defaults.
		 * Returned values are NOT auto-escaped — the renderer wp_kses_post's
		 * the whole output, so safe HTML is allowed.
		 *
		 * @since 1.16.0
		 * @param array  $data         Token name → value map.
		 * @param string $jurisdiction Effective jurisdiction.
		 * @param string $lang         Effective language.
		 * @param array  $settings     Admin settings raw.
		 */
		return (array) apply_filters( 'faz_cookie_policy_data', $data, $jurisdiction, $lang, $settings );
	}

	/**
	 * Build the cookie list HTML (FR-06). Pulled from wp_faz_cookies +
	 * wp_faz_cookie_categories, cached 5 min.
	 *
	 * @param string $lang For category-name translation when available.
	 * @return string HTML <ul>/<dl> markup.
	 */
	private static function build_cookie_list_html( $lang ) {
		$cache_key = 'faz_cookie_policy_list_' . $lang;
		if ( isset( self::$cookie_list_cache[ $cache_key ] ) ) {
			return self::$cookie_list_cache[ $cache_key ];
		}
		$cached = wp_cache_get( $cache_key, 'faz_cookie_policy' );
		if ( false !== $cached && is_string( $cached ) ) {
			self::$cookie_list_cache[ $cache_key ] = $cached;
			return $cached;
		}

		global $wpdb;
		$cookies_table   = $wpdb->prefix . 'faz_cookies';
		$categories_table = $wpdb->prefix . 'faz_cookie_categories';

		// Schema sanity: skip if either table is missing (e.g. unactivated install).
		if ( ! self::table_exists( $cookies_table ) || ! self::table_exists( $categories_table ) ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			// Column aliases bridge the legacy in-PHP names (cookie_name,
			// cookie_domain, cookie_duration, cookie_description,
			// category_name, category_description) to the actual schema
			// (`name`, `domain`, `duration`, `description`, etc.). Without
			// the aliases the SELECT errors out with "Unknown column
			// 'c.cookie_name'" and build_cookie_list_html returned an empty
			// string — meaning [faz_cookie_policy_complete] rendered without any
			// inventory section at all. The downstream rendering loop reads
			// $row['cookie_name'] etc., so the aliases keep it untouched.
			"SELECT c.cookie_id, c.name AS cookie_name, c.domain AS cookie_domain,
			        c.duration AS cookie_duration, c.description AS cookie_description,
			        c.category AS category_id,
			        cat.name AS category_name, cat.description AS category_description
			   FROM `{$cookies_table}` AS c
			   LEFT JOIN `{$categories_table}` AS cat ON c.category = cat.category_id
			   ORDER BY cat.priority ASC, c.name ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$html = '';
		} else {
			$grouped = array();
			foreach ( $rows as $row ) {
				$cat = (string) ( $row['category_name'] ?? 'Uncategorized' );
				$grouped[ $cat ][] = $row;
			}

			$parts = array();
			foreach ( $grouped as $cat_name => $items ) {
				$parts[] = '<section class="faz-cookie-policy-category">';
				$parts[] = '<h3>' . esc_html( $cat_name ) . '</h3>';
				if ( ! empty( $items[0]['category_description'] ) ) {
					$parts[] = '<p>' . esc_html( $items[0]['category_description'] ) . '</p>';
				}
				$parts[] = '<dl>';
				foreach ( $items as $row ) {
					$name     = (string) ( $row['cookie_name'] ?? '' );
					$domain   = (string) ( $row['cookie_domain'] ?? '' );
					$duration = (string) ( $row['cookie_duration'] ?? '' );
					$desc     = (string) ( $row['cookie_description'] ?? '' );
					$parts[]  = '<dt><code>' . esc_html( $name ) . '</code>';
					if ( '' !== $domain ) {
						$parts[] = ' <small>(' . esc_html( $domain ) . ')</small>';
					}
					$parts[] = '</dt>';
					$parts[] = '<dd>';
					if ( '' !== $duration ) {
						$parts[] = '<strong>' . esc_html__( 'Duration:', 'faz-cookie-manager' ) . '</strong> ' . esc_html( $duration ) . ' &middot; ';
					}
					$parts[] = esc_html( $desc );
					$parts[] = '</dd>';
				}
				$parts[] = '</dl>';
				$parts[] = '</section>';
			}
			$html = implode( "\n", $parts );
		}

		wp_cache_set( $cache_key, $html, 'faz_cookie_policy', 5 * MINUTE_IN_SECONDS );
		self::$cookie_list_cache[ $cache_key ] = $html;
		return $html;
	}

	/**
	 * Build a comma-separated services list from settings.
	 *
	 * @param array $settings
	 * @return string
	 */
	private static function build_services_list( array $settings ) {
		$services = (array) ( $settings['third_party_services'] ?? array() );
		if ( empty( $services ) ) {
			return esc_html__( 'None declared.', 'faz-cookie-manager' );
		}
		// Display-name map. Brand names are verbatim (registered marks).
		// Grouped here in the same category buckets used by the admin form
		// and the API allowlist — single source of truth for service IDs.
		$names = array(
			// Analytics
			'ga4'           => 'Google Analytics 4',
			'gtm'           => 'Google Tag Manager',
			'matomo'        => 'Matomo Analytics',
			'plausible'     => 'Plausible Analytics',
			'mixpanel'      => 'Mixpanel',
			'amplitude'     => 'Amplitude',
			'heap'          => 'Heap',
			'fathom'        => 'Fathom Analytics',
			'statcounter'   => 'Statcounter',
			// Heatmaps / session recording
			'hotjar'        => 'Hotjar',
			'clarity'       => 'Microsoft Clarity',
			'mouseflow'     => 'Mouseflow',
			'smartlook'     => 'Smartlook',
			'luckyorange'   => 'Lucky Orange',
			'fullstory'     => 'FullStory',
			'logrocket'     => 'LogRocket',
			'crazyegg'      => 'Crazy Egg',
			// Advertising pixels
			'meta'          => 'Meta (Facebook) Pixel',
			'tiktok'        => 'TikTok Pixel',
			'linkedin'      => 'LinkedIn Insight Tag',
			'msuet'         => 'Microsoft UET',
			'twitter'       => 'Twitter (X) Pixel',
			'pinterest'     => 'Pinterest Tag',
			'reddit'        => 'Reddit Pixel',
			'snap'          => 'Snapchat Pixel',
			'quora'         => 'Quora Pixel',
			'outbrain'      => 'Outbrain',
			'taboola'       => 'Taboola',
			// CDN / edge / performance
			'cf'            => 'Cloudflare',
			'fastly'        => 'Fastly',
			'akamai'        => 'Akamai',
			'cloudfront'    => 'Amazon CloudFront',
			'bunnycdn'      => 'BunnyCDN',
			'jsdelivr'      => 'jsDelivr',
			// Anti-bot / forms
			'recaptcha'     => 'Google reCAPTCHA',
			'hcaptcha'      => 'hCaptcha',
			'turnstile'     => 'Cloudflare Turnstile',
			'akismet'       => 'Akismet',
			// Maps / embeds / media
			'gmaps'         => 'Google Maps',
			'mapbox'        => 'Mapbox',
			'osm'           => 'OpenStreetMap',
			'youtube'       => 'YouTube (embed)',
			'vimeo'         => 'Vimeo (embed)',
			'twitterembed'  => 'Twitter / X (embed)',
			'instagram'     => 'Instagram (embed)',
			'spotify'       => 'Spotify (embed)',
			'soundcloud'    => 'SoundCloud (embed)',
			'wistia'        => 'Wistia',
			'brightcove'    => 'Brightcove',
			'jwplayer'      => 'JW Player',
			// Chat / support
			'intercom'      => 'Intercom',
			'zendesk'       => 'Zendesk Chat',
			'crisp'         => 'Crisp',
			'livechat'      => 'LiveChat',
			'tawk'          => 'Tawk.to',
			'drift'         => 'Drift',
			'hubspotchat'   => 'HubSpot Chat',
			'tidio'         => 'Tidio',
			// Email / marketing automation
			'mailchimp'        => 'Mailchimp',
			'activecampaign'   => 'ActiveCampaign',
			'convertkit'       => 'ConvertKit / Kit',
			'hubspot'          => 'HubSpot',
			'brevo'            => 'Brevo (formerly Sendinblue)',
			'klaviyo'          => 'Klaviyo',
			'pardot'           => 'Salesforce Pardot',
			'marketo'          => 'Adobe Marketo Engage',
			'adobe'            => 'Adobe Analytics',
			// Payments / commerce
			'stripe'        => 'Stripe',
			'paypal'        => 'PayPal',
			'square'        => 'Square',
			'shopify'       => 'Shopify',
			// Social sign-in / auth
			'google_signin'   => 'Sign in with Google',
			'apple_signin'    => 'Sign in with Apple',
			'facebook_signin' => 'Sign in with Facebook',
			'auth0'           => 'Auth0',
			'okta'            => 'Okta',
			// Error / RUM monitoring
			'sentry'        => 'Sentry',
			'newrelic'      => 'New Relic',
			'datadog'       => 'Datadog',
			'bugsnag'       => 'Bugsnag',
			'raygun'        => 'Raygun',
			// Personalisation / A-B testing
			'optimizely'    => 'Optimizely',
			'vwo'           => 'VWO',
			'convert'       => 'Convert.com',
			'abtasty'       => 'AB Tasty',
			// Push notifications
			'onesignal'     => 'OneSignal',
			'pushwoosh'     => 'Pushwoosh',
			'fcm'           => 'Firebase Cloud Messaging',
		);
		$display = array();
		foreach ( $services as $svc ) {
			if ( is_string( $svc ) && isset( $names[ $svc ] ) ) {
				$display[] = $names[ $svc ];
			}
		}
		return $display ? implode( ', ', array_map( 'esc_html', $display ) ) : esc_html__( 'None declared.', 'faz-cookie-manager' );
	}

	/**
	 * The hardcoded non-removable disclaimer (FR-04). One per language.
	 *
	 * @param string $jurisdiction
	 * @param string $lang
	 * @param array  $data Includes OFFICIAL_RESOURCES_URL.
	 * @return string HTML <footer> block.
	 */
	private static function disclaimer( $jurisdiction, $lang, array $data ) {
		$texts = array(
			'en'    => 'This cookie policy was generated by FAZ Cookie Manager using a template scaffold for the %s jurisdiction. Templates do not constitute legal advice. The administrator of this site remains the data controller under applicable law and is responsible for the accuracy and adequacy of the published content. For jurisdiction-specific guidance, consult: %s.',
			'it'    => 'Questa cookie policy è stata generata da FAZ Cookie Manager usando uno scaffold modello per la giurisdizione %s. I modelli non costituiscono consulenza legale. L\'amministratore di questo sito resta il titolare del trattamento dei dati ai sensi della legge applicabile ed è responsabile dell\'accuratezza e adeguatezza dei contenuti pubblicati. Per indicazioni specifiche per la giurisdizione, consultare: %s.',
			'fr'    => 'Cette politique de cookies a été générée par FAZ Cookie Manager à partir d\'un modèle pour la juridiction %s. Les modèles ne constituent pas un conseil juridique. L\'administrateur de ce site reste le responsable du traitement au sens de la loi applicable et est responsable de l\'exactitude et de l\'adéquation du contenu publié. Pour des conseils spécifiques à la juridiction, consultez : %s.',
			'de'    => 'Diese Cookie-Richtlinie wurde von FAZ Cookie Manager aus einer Vorlage für die Rechtsordnung %s generiert. Vorlagen stellen keine Rechtsberatung dar. Der Administrator dieser Website bleibt für die Datenverarbeitung verantwortlich und für die Richtigkeit und Angemessenheit der veröffentlichten Inhalte verantwortlich. Für rechtsraumspezifische Hinweise siehe: %s.',
			'es'    => 'Esta política de cookies fue generada por FAZ Cookie Manager a partir de una plantilla para la jurisdicción %s. Las plantillas no constituyen asesoramiento legal. El administrador de este sitio sigue siendo el responsable del tratamiento de datos según la ley aplicable y es responsable de la exactitud y adecuación del contenido publicado. Para orientación específica de la jurisdicción, consulte: %s.',
			'pt-BR' => 'Esta política de cookies foi gerada pelo FAZ Cookie Manager a partir de um modelo para a jurisdição %s. Os modelos não constituem aconselhamento jurídico. O administrador deste site permanece como controlador dos dados conforme a lei aplicável e é responsável pela exatidão e adequação do conteúdo publicado. Para orientação específica da jurisdição, consulte: %s.',
		);
		$tpl = $texts[ $lang ] ?? $texts['en'];
		$jurisdiction_label = self::jurisdiction_display_name( $jurisdiction, $lang );
		$url = (string) ( $data['OFFICIAL_RESOURCES_URL'] ?? '' );
		$url_html = $url ? '<a href="' . esc_url( $url ) . '" rel="noopener" target="_blank">' . esc_html( $url ) . '</a>' : '—';
		// sprintf with escaped jurisdiction + url_html (the url is already esc_url'd).
		$body = sprintf( $tpl, '<strong>' . esc_html( $jurisdiction_label ) . '</strong>', $url_html );
		return "\n" . '<footer class="faz-cookie-policy-disclaimer">' . $body . '</footer>';
	}

	/**
	 * NFR-03 graceful no-op: when no template is found AND no settings.
	 *
	 * @param string $jurisdiction
	 * @param string $lang
	 * @return string Admin-only HTML notice, public output empty.
	 */
	private static function no_template_notice( $jurisdiction, $lang ) {
		if ( current_user_can( 'manage_options' ) ) {
			return '<div class="faz-cookie-policy-empty notice notice-warning"><p>' .
				sprintf(
					/* translators: 1: jurisdiction, 2: lang */
					esc_html__( 'FAZ Cookie Policy: no template scaffold found for jurisdiction "%1$s" and language "%2$s". Configure the generator under FAZ Cookie Manager → Cookie Policy.', 'faz-cookie-manager' ),
					esc_html( $jurisdiction ),
					esc_html( $lang )
				) .
				'</p></div>';
		}
		return ''; // anonymous visitors see nothing
	}

	/**
	 * FR-07 compute the policy version hash. The hash is exposed in two ways:
	 *
	 *  1. As a <meta name="faz-policy-version"> in <head>, when wp_head has
	 *     not yet fired. Useful for AJAX / fragment renders.
	 *  2. As a data-faz-policy-version="..." attribute on the <article>
	 *     wrapper itself — always present, survives the the_content/wp_head
	 *     ordering issue (shortcodes run AFTER wp_head, so a late
	 *     add_action('wp_head', ...) would be a no-op).
	 *
	 * The <meta> tag was previously placed inline inside <article> as well,
	 * but HTML5 disallows <meta> inside <body> without itemprop attributes,
	 * so browsers and Playwright's DOM dropped it. The data-attribute is a
	 * standards-clean alternative.
	 *
	 * @param string $template_path
	 * @param array  $data
	 * @return string Policy version hash (also used as data-attribute value).
	 */
	private static function register_version_meta( $template_path, array $data ) {
		static $registered = false;
		static $static_hash = '';
		$hash = Generator::policy_version_hash( $template_path, $data );
		// Multiple shortcodes on the same page must not register multiple
		// add_action callbacks (would emit duplicate <meta> tags). Guard
		// with a static flag; the first call stashes its hash, the closure
		// reads the closed-over value at fire time.
		if ( ! $registered && did_action( 'wp_head' ) === 0 ) {
			$static_hash  = $hash;
			$registered_ref = &$static_hash; // closure captures by reference so the
			                                  // hash can still update if later renders
			                                  // happen before wp_head fires.
			add_action( 'wp_head', function () use ( &$registered_ref ) {
				echo '<meta name="faz-policy-version" content="' . esc_attr( $registered_ref ) . '">' . "\n";
			}, 99 );
			$registered = true;
		} elseif ( $registered ) {
			// Subsequent shortcode renders on the same page update the stash
			// to whatever the last render produced. The closure echoes the
			// final value when wp_head fires (which is BEFORE the_content
			// runs in canonical rendering, but for shortcodes called via
			// AJAX / REST `template_redirect` will have fired before render).
			$static_hash = $hash;
		}
		return $hash;
	}

	// ---------- Lang helpers ----------

	private static function is_supported_lang( $lang ) {
		return in_array( self::normalize_lang( (string) $lang ), Generator::LANGUAGES, true );
	}

	private static function normalize_lang( $lang ) {
		$lang = (string) $lang;
		// Normalize underscores → hyphens (pt_BR → pt-BR).
		$lang = str_replace( '_', '-', $lang );
		// Lower the language part, upper the region part if present (it-IT, pt-BR).
		if ( strpos( $lang, '-' ) !== false ) {
			$parts = explode( '-', $lang, 2 );
			return strtolower( $parts[0] ) . '-' . strtoupper( $parts[1] );
		}
		return strtolower( $lang );
	}

	private static function wp_locale_to_template_lang( $wp_locale ) {
		// it_IT → it, en_US → en, pt_BR → pt-BR.
		if ( '' === $wp_locale ) {
			return 'en';
		}
		$wp_locale = str_replace( '-', '_', $wp_locale );
		if ( 'pt_BR' === $wp_locale ) {
			return 'pt-BR';
		}
		$base = strtolower( strtok( $wp_locale, '_' ) );
		return $base;
	}

	// ---------- Misc helpers ----------

	private static function format_date( $lang ) {
		$ts = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$ts_unix = strtotime( $ts );
		$formats = array(
			'en'    => 'F j, Y',
			'it'    => 'j F Y',
			'fr'    => 'j F Y',
			'de'    => 'j. F Y',
			'es'    => 'j \\d\\e F \\d\\e Y',
			'pt-BR' => 'j \\d\\e F \\d\\e Y',
		);
		return date_i18n( $formats[ $lang ] ?? 'Y-m-d', $ts_unix );
	}

	private static function format_retention( array $settings, $lang ) {
		$months = (int) ( $settings['retention_months'] ?? 12 );
		if ( $months <= 0 ) { $months = 12; }
		$labels = array(
			'en'    => '%d months',
			'it'    => '%d mesi',
			'fr'    => '%d mois',
			'de'    => '%d Monate',
			'es'    => '%d meses',
			'pt-BR' => '%d meses',
		);
		return sprintf( $labels[ $lang ] ?? '%d months', $months );
	}

	private static function jurisdiction_display_name( $jurisdiction, $lang ) {
		$names = array(
			'gdpr-strict'     => array( 'en' => 'GDPR (EU/EEA/UK)', 'it' => 'GDPR (UE/SEE/UK)', 'fr' => 'RGPD (UE/EEE/UK)', 'de' => 'DSGVO (EU/EWR/UK)', 'es' => 'RGPD (UE/EEE/UK)', 'pt-BR' => 'GDPR (UE/EEE/UK)' ),
			'ccpa-california' => array( 'en' => 'CCPA/CPRA (California)', 'it' => 'CCPA/CPRA (California)', 'fr' => 'CCPA/CPRA (Californie)', 'de' => 'CCPA/CPRA (Kalifornien)', 'es' => 'CCPA/CPRA (California)', 'pt-BR' => 'CCPA/CPRA (Califórnia)' ),
			'lgpd-brazil'     => array( 'en' => 'LGPD (Brazil)', 'it' => 'LGPD (Brasile)', 'fr' => 'LGPD (Brésil)', 'de' => 'LGPD (Brasilien)', 'es' => 'LGPD (Brasil)', 'pt-BR' => 'LGPD (Brasil)' ),
		);
		return $names[ $jurisdiction ][ $lang ] ?? $names[ $jurisdiction ]['en'] ?? $jurisdiction;
	}

	private static function language_display_name( $lang, $in_lang ) {
		$names = array(
			'en'    => 'English',
			'it'    => 'Italiano',
			'fr'    => 'Français',
			'de'    => 'Deutsch',
			'es'    => 'Español',
			'pt-BR' => 'Português (Brasil)',
		);
		return $names[ $lang ] ?? $lang;
	}

	private static function official_resources_url( $jurisdiction ) {
		$urls = array(
			'gdpr-strict'     => 'https://edpb.europa.eu/',
			'ccpa-california' => 'https://cppa.ca.gov/',
			'lgpd-brazil'     => 'https://www.gov.br/anpd/pt-br',
		);
		return $urls[ $jurisdiction ] ?? '';
	}

	private static function current_url() {
		// SECURITY: do NOT trust $_SERVER['HTTP_HOST'] for the host
		// component. The Host header is attacker-controlled (think Host
		// header injection); building the canonical URL of a published
		// Cookie Policy from it would let an attacker forge the
		// {{COOKIE_POLICY_URL}} placeholder to point at evil.com even
		// after esc_url() (which only validates the SHAPE of a URL,
		// not the hostname). We pull the host from home_url() — that
		// reads `siteurl` from wp_options, which is admin-controlled
		// and not derived from the request — and combine it with the
		// sanitised REQUEST_URI path.
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return home_url( '/' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = wp_unslash( (string) $_SERVER['REQUEST_URI'] );
		// Normalise leading slash and drop any control chars / CR-LF
		// the request might have smuggled.
		$request_uri = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $request_uri );
		$request_uri = '/' . ltrim( (string) $request_uri, '/' );
		return home_url( $request_uri );
	}

	private static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
