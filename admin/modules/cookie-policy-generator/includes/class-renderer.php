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
		// 1.16.2 — drop list lines whose only content is a bold label and a
		// now-empty placeholder. Templates carry rows like
		// `- **Register / USt-ID:** {{COMPANY_REGISTRY}}` or
		// `- **DPO:** {{DPO_NAME}} — {{DPO_EMAIL}}`; when the admin leaves
		// those fields blank the substitution result is an orphan label with
		// trailing whitespace/em-dashes/commas, visually broken on the public
		// policy page. Reported by Gooloo on 1.16.1.
		$markdown = self::strip_empty_label_lines( $markdown );
		// By default drop the scaffold's leading H1 ("# Cookie Policy") so the
		// rendered policy does not duplicate the WordPress page title it is
		// usually placed inside. `show_title="true"` keeps it. The policy stays
		// self-referential either way — the intro paragraph names itself.
		if ( ! self::should_show_title( $atts ) ) {
			$markdown = self::strip_leading_h1( $markdown );
		}
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

		// Disclaimer block. Admin-configurable since 1.16.2: visibility
		// + text are stored in the `disclaimer` sub-array of the
		// faz_cookie_policy_data option. Default behaviour remains
		// "show the standard FAZ disclaimer" so existing installs keep
		// the same rendered output until they edit the field.
		$html .= self::disclaimer( $jurisdiction, $lang, $data, $settings );

		// FR-07: compute the policy version hash. Exposed in <head> (if
		// wp_head hasn't fired) AND as a data-faz-policy-version attribute
		// on the article wrapper (HTML5-clean, always survives).
		$policy_version = self::register_version_meta( $template_path, $data );

		// Wrap in <article> per NFR-02-X accessibility.
		$wrapper_open  = '<article class="faz-cookie-policy" lang="' . esc_attr( $lang )
			. '" data-jurisdiction="' . esc_attr( $jurisdiction )
			. '" data-faz-policy-version="' . esc_attr( $policy_version ) . '">';
		$wrapper_close = '</article>';

		// NFR-02-XI: sanitize the body content via kses. We CANNOT use plain
		// wp_kses_post() here: `aria-level` is NOT in WordPress's default
		// 'post' allowlist, so wp_kses_post strips it while keeping
		// role="heading" — leaving the category-name spans as headings with no
		// level, an axe-critical WCAG 4.1.2 / 1.3.1 failure. Extend the 'post'
		// allowlist with ONLY role + aria-level (no broadening of style/data-*)
		// so the ARIA heading semantics survive the sanitization pass. The
		// wrapper is emitted by trusted code and bypasses kses.
		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( $allowed as $tag => $attrs ) {
			if ( is_array( $attrs ) ) {
				$allowed[ $tag ]['role']       = true;
				$allowed[ $tag ]['aria-level'] = true;
			}
		}
		return $wrapper_open . wp_kses( $html, $allowed ) . $wrapper_close;
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
			        cat.name AS category_name, cat.description AS category_description,
			        cat.slug AS category_slug
			   FROM `{$cookies_table}` AS c
			   LEFT JOIN `{$categories_table}` AS cat ON c.category = cat.category_id
			   ORDER BY cat.priority ASC, c.name ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$html = '';
		} else {
			// 1.16.2 — exclude WordPress-internal infrastructure cookies
			// (wp-settings-*, wordpress_logged_in_*, wordpress_test_cookie,
			// comment_author_*, etc.) AND the "wordpress-internal" admin
			// category as a whole. These are strictly-necessary admin-only
			// cookies that visitors never receive — listing them in the
			// public Cookie Policy is misleading and bloats the page.
			// Mirrors the same filter applied to the frontend banner
			// (Frontend::is_wp_internal_cookie + 'wordpress-internal'
			// category skip in prepare_frontend_categories).
			$rows = array_values( array_filter( $rows, function ( $row ) {
				// Skip the dedicated "wordpress-internal" admin category
				// entirely — by convention it groups admin-only cookies
				// that visitors never receive.
				if ( 'wordpress-internal' === (string) ( $row['category_slug'] ?? '' ) ) {
					return false;
				}
				$name = (string) ( $row['cookie_name'] ?? '' );
				if ( class_exists( '\\FazCookie\\Frontend\\Frontend' )
					&& \FazCookie\Frontend\Frontend::is_wp_internal_cookie( $name ) ) {
					return false;
				}
				return true;
			} ) );
			if ( empty( $rows ) ) {
				wp_cache_set( $cache_key, '', 'faz_cookie_policy', 5 * MINUTE_IN_SECONDS );
				self::$cookie_list_cache[ $cache_key ] = '';
				return '';
			}
			$grouped = array();
			foreach ( $rows as $row ) {
				// Categories' `name` and `description` columns store i18n
				// JSON objects shaped like {"en":"Functional","it":"Funzionale"}
				// — same shape as cookies' `description` / `duration` columns
				// (the legacy [faz_cookie_table] decodes them via
				// localize_category_name() in includes/class-cookie-table-shortcode.php).
				// We must decode here too, otherwise the rendered policy
				// shows literal JSON like <h3>{"en":"Functional"}</h3>
				// (reported by James in the wp.org support thread
				// "Performance Impact???" on 1.16.0). Use $lang as the
				// preferred key, fall back to 'en' then first non-empty.
				$cat = self::decode_i18n_text( $row['category_name'] ?? '', $lang ) ?: 'Uncategorized';
				$grouped[ $cat ][] = $row;
			}

			// Localised table-column headings. Translators handled via
			// gettext; default English used when locale strings are
			// unavailable (Cookie Policy renderer may be called outside
			// admin pages where __() context isn't always loaded).
			$col_cookie   = esc_html__( 'Cookie', 'faz-cookie-manager' );
			$col_domain   = esc_html__( 'Domain', 'faz-cookie-manager' );
			$col_duration = esc_html__( 'Duration', 'faz-cookie-manager' );
			$col_desc     = esc_html__( 'Description', 'faz-cookie-manager' );
			$cookies_lbl  = esc_html__( 'cookies', 'faz-cookie-manager' );

			$parts = array();
			foreach ( $grouped as $cat_name => $items ) {
				$cookie_count = count( $items );
				$parts[]      = '<section class="faz-cookie-policy-category">';
				// HTML5 <details> gives a native, JS-free accordion. The
				// <summary> doubles as the category heading; we keep an
				// inner <h3> for outline/accessibility tools that walk
				// heading levels. Setting `open` on the first category
				// only would require state we don't have here, so leave
				// all collapsed by default — visitors expand the ones
				// they care about. Reported by Gooloo on 1.16.x: the
				// previous flat <dl> layout (1.16.0/1.16.1) produced a
				// 700+ line wall of definition-list pairs that buried
				// the policy text below it.
				$parts[] = '<details class="faz-cookie-policy-details">';
				$parts[] = '<summary class="faz-cookie-policy-summary">';
				// Use <span role="heading" aria-level="3"> instead of an
				// actual <h3> so the category name does NOT trigger the
				// block-level layout reset that every WordPress block
				// theme (twentytwentyfive, twentytwentyfour) applies to
				// headings inside .entry-content. Inline span keeps the
				// chevron + name + count on one line; the role/aria-level
				// pair restores the heading semantics for screen readers
				// and document-outline tools.
				$parts[] = '<span class="faz-cookie-policy-category-name" role="heading" aria-level="3">' . esc_html( $cat_name ) . '</span>';
				$parts[] = '<span class="faz-cookie-policy-count">' . esc_html( (string) $cookie_count ) . ' ' . $cookies_lbl . '</span>';
				$parts[] = '</summary>';
				$parts[] = '<div class="faz-cookie-policy-details-body">';
				$cat_desc = self::decode_i18n_text( $items[0]['category_description'] ?? '', $lang );
				if ( '' !== $cat_desc ) {
					// Category description may contain HTML (admin sometimes
					// stores <p>…</p> inside the JSON value); wp_kses_post at
					// the renderer boundary will filter it. Use wp_kses_post
					// here too so well-formed HTML survives without escaping.
					$parts[] = wp_kses_post( $cat_desc );
				}
				$parts[] = '<table class="faz-cookie-policy-table">';
				$parts[] = '<thead><tr>'
					. '<th scope="col">' . $col_cookie . '</th>'
					. '<th scope="col">' . $col_domain . '</th>'
					. '<th scope="col">' . $col_duration . '</th>'
					. '<th scope="col">' . $col_desc . '</th>'
					. '</tr></thead>';
				$parts[] = '<tbody>';
				foreach ( $items as $row ) {
					// Cookie `name` and `domain` are plain identifiers (not
					// translated). Cookie `duration` and `description` ARE
					// i18n JSON objects on the same schema as categories.
					$name     = (string) ( $row['cookie_name'] ?? '' );
					$domain   = (string) ( $row['cookie_domain'] ?? '' );
					$duration = self::decode_i18n_text( $row['cookie_duration'] ?? '', $lang );
					$desc     = self::decode_i18n_text( $row['cookie_description'] ?? '', $lang );
					$parts[]  = '<tr>';
					$parts[]  = '<td data-label="' . $col_cookie . '"><code>' . esc_html( $name ) . '</code></td>';
					$parts[]  = '<td data-label="' . $col_domain . '">' . ( '' !== $domain ? esc_html( $domain ) : '&mdash;' ) . '</td>';
					$parts[]  = '<td data-label="' . $col_duration . '">' . ( '' !== $duration ? esc_html( $duration ) : '&mdash;' ) . '</td>';
					// Cookie description may contain HTML inside the JSON value.
					$parts[]  = '<td data-label="' . $col_desc . '">' . wp_kses_post( $desc ) . '</td>';
					$parts[]  = '</tr>';
				}
				$parts[] = '</tbody>';
				$parts[] = '</table>';
				$parts[] = '</div>';
				$parts[] = '</details>';
				$parts[] = '</section>';
			}
			$html = implode( "\n", $parts );
		}

		wp_cache_set( $cache_key, $html, 'faz_cookie_policy', 5 * MINUTE_IN_SECONDS );
		self::$cookie_list_cache[ $cache_key ] = $html;
		return $html;
	}

	/**
	 * Decode an i18n JSON value (`{"en":"…","it":"…"}`) into the active
	 * language string. Plain strings pass through unchanged.
	 *
	 * Categories' `name`/`description` and cookies' `description`/`duration`
	 * are stored as JSON objects keyed by language code; mirror the decode
	 * logic of Cookie_Table_Shortcode::localize_category_name() so the
	 * generated policy doesn't leak raw JSON (wp.org topic
	 * "Performance Impact???", 1.16.0).
	 *
	 * Fallback chain: $lang → 'en' → first non-empty entry.
	 */
	private static function decode_i18n_text( $value, $lang ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		if ( '{' !== $value[0] ) {
			return $value;
		}
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return $value;
		}
		if ( is_string( $lang ) && '' !== $lang && isset( $decoded[ $lang ] ) && is_string( $decoded[ $lang ] ) && '' !== $decoded[ $lang ] ) {
			return $decoded[ $lang ];
		}
		if ( isset( $decoded['en'] ) && is_string( $decoded['en'] ) && '' !== $decoded['en'] ) {
			return $decoded['en'];
		}
		foreach ( $decoded as $v ) {
			if ( is_string( $v ) && '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * Drop list-item lines whose only content is a bold label and an
	 * empty/whitespace tail. Applies after placeholder substitution so a
	 * blank admin field doesn't leave behind "**Label:** — " in the
	 * rendered policy.
	 *
	 * Matched shapes (case-insensitive, multiline):
	 *   `- **Anything:**`            → drop
	 *   `- **Anything:**  `          → drop
	 *   `- **Anything:** —  `        → drop
	 *   `- **Anything:**  —  `       → drop
	 *   `- **Anything:**  -  `       → drop (ASCII dash variants)
	 *   `* **Anything:** , ,`        → drop (also stray separators)
	 *
	 * NOT matched (kept):
	 *   `- **Address:** Via Roma 10`        → real content present
	 *   `- **Phone:** +39 02 1234`          → digits/text present
	 *   `- **Title** (without colon)`       → not the "label: value" shape
	 *
	 * @param string $markdown
	 * @return string
	 */
	/**
	 * Whether the shortcode asked to keep the scaffold's leading H1 title.
	 *
	 * Default is false (the title is dropped). Accepts the usual truthy strings
	 * so `show_title="true"`, `="1"`, `="yes"`, `="on"` all enable it.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return bool
	 */
	private static function should_show_title( $atts ) {
		if ( ! is_array( $atts ) || ! isset( $atts['show_title'] ) ) {
			return false;
		}
		$val = strtolower( trim( (string) $atts['show_title'] ) );
		return in_array( $val, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Remove the scaffold's leading level-1 ATX heading ("# Cookie Policy").
	 *
	 * Only the FIRST line is touched, and only when it is a single-`#` heading;
	 * deeper headings (`## …`) are body structure and are preserved. A scaffold
	 * without a leading H1 is returned unchanged.
	 *
	 * @param string $markdown
	 * @return string
	 */
	private static function strip_leading_h1( $markdown ) {
		if ( ! is_string( $markdown ) || '' === $markdown ) {
			return (string) $markdown;
		}
		// \A + optional leading blank lines, then a single `#` followed by at
		// least one space/tab (so `## …` H2 does NOT match) and the heading text,
		// then the line break(s). Replaced once.
		return (string) preg_replace( '/\A\s*#[ \t]+\S[^\n]*\R+/u', '', $markdown, 1 );
	}

	/**
	 * Remove bullet lines whose only content is an empty "**Label:**" run.
	 *
	 * Strips list items that render as a bold label with no value (plus any
	 * trailing dashes/dots/commas), then collapses the blank lines the
	 * deletion leaves behind. Falls back to the original markdown on PCRE error.
	 *
	 * @param string $markdown Raw policy markdown.
	 * @return string Cleaned markdown.
	 */
	private static function strip_empty_label_lines( $markdown ) {
		if ( ! is_string( $markdown ) || '' === $markdown ) {
			return (string) $markdown;
		}
		// `[\s\-—–·,]*` allows a mixture of whitespace, ASCII hyphen,
		// en-dash, em-dash, middle dot and comma in the trailing run.
		// `m` flag = ^/$ match per line.
		$pattern = '/^[ \t]*[-*][ \t]+\*\*[^*\n]+:\*\*[\s\-—–·,]*\r?$/mu';
		$cleaned = preg_replace( $pattern, '', $markdown );
		if ( null === $cleaned ) {
			// Regex error (e.g. PCRE compile fail under exotic PHP build).
			// Fall back to the un-cleaned source rather than nuking the policy.
			return $markdown;
		}
		// Collapse the 2+ blank lines the deletion may have left behind so
		// the markdown parser doesn't render extra paragraph spacing.
		$cleaned = (string) preg_replace( "/(\r?\n){3,}/", "\n\n", $cleaned );
		return $cleaned;
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
			'gads'          => 'Google Ads',
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
			'criteo'        => 'Criteo',
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
	 * Disclaimer block. Admin-configurable since 1.16.2:
	 *   - `disclaimer.show` (bool, default true): hide the block entirely.
	 *   - `disclaimer.text` (string, default ''): when non-empty, replaces
	 *     the standard FAZ disclaimer with custom markup (filtered via
	 *     wp_kses_post). When empty, the standard localised text is used.
	 *
	 * Wrapper changed from <footer> to <div class="faz-cookie-policy-disclaimer">
	 * in 1.16.2 so the element does not introduce a landmark inside an <article>
	 * (Gooloo feedback: <footer> is an HTML5 landmark and visually disrupts
	 * themes that style page <footer> globally).
	 *
	 * @param string $jurisdiction
	 * @param string $lang
	 * @param array  $data     Includes OFFICIAL_RESOURCES_URL.
	 * @param array  $settings Full settings array (reads `disclaimer.*`).
	 * @return string HTML <div> block, or empty string when hidden.
	 */
	private static function disclaimer( $jurisdiction, $lang, array $data, array $settings = array() ) {
		$disc = is_array( $settings['disclaimer'] ?? null ) ? $settings['disclaimer'] : array();
		$show = array_key_exists( 'show', $disc ) ? (bool) $disc['show'] : true;
		if ( ! $show ) {
			return '';
		}
		$custom = isset( $disc['text'] ) && is_string( $disc['text'] ) ? trim( $disc['text'] ) : '';
		if ( '' !== $custom ) {
			// Admin-provided text. Allow inline HTML (links, em, strong) but
			// run through wp_kses_post for safety since the outer render()
			// pass skips kses for $html_value tokens here.
			$body = wp_kses_post( $custom );
		} else {
			$texts = array(
				'en'    => 'This cookie policy was generated by FAZ Cookie Manager using a template scaffold for the %s jurisdiction. Templates do not constitute legal advice. The administrator of this site remains the data controller under applicable law and is responsible for the accuracy and adequacy of the published content. For jurisdiction-specific guidance, consult: %s.',
				'it'    => 'Questa cookie policy è stata generata da FAZ Cookie Manager usando uno scaffold modello per la giurisdizione %s. I modelli non costituiscono consulenza legale. L\'amministratore di questo sito resta il titolare del trattamento dei dati ai sensi della legge applicabile ed è responsabile dell\'accuratezza e adeguatezza dei contenuti pubblicati. Per indicazioni specifiche per la giurisdizione, consultare: %s.',
				'fr'    => 'Cette politique de cookies a été générée par FAZ Cookie Manager à partir d\'un modèle pour la juridiction %s. Les modèles ne constituent pas un conseil juridique. L\'administrateur de ce site reste le responsable du traitement au sens de la loi applicable et est responsable de l\'exactitude et de l\'adéquation du contenu publié. Pour des conseils spécifiques à la juridiction, consultez : %s.',
				'de'    => 'Diese Cookie-Richtlinie wurde von FAZ Cookie Manager aus einer Vorlage für die Rechtsordnung %s generiert. Vorlagen stellen keine Rechtsberatung dar. Der Administrator dieser Website bleibt für die Datenverarbeitung verantwortlich und für die Richtigkeit und Angemessenheit der veröffentlichten Inhalte verantwortlich. Für rechtsraumspezifische Hinweise siehe: %s.',
				'es'    => 'Esta política de cookies fue generada por FAZ Cookie Manager a partir de una plantilla para la jurisdicción %s. Las plantillas no constituyen asesoramiento legal. El administrador de este sitio sigue siendo el responsable del tratamiento de datos según la ley aplicable y es responsable de la exactitud y adecuación del contenido publicado. Para orientación específica de la jurisdicción, consulte: %s.',
				'pt-BR' => 'Esta política de cookies foi gerada pelo FAZ Cookie Manager a partir de um modelo para a jurisdição %s. Os modelos não constituem aconselhamento jurídico. O administrador deste site permanece como controlador dos dados conforme a lei aplicável e é responsável pela exatidão e adequação do conteúdo publicado. Para orientação específica da jurisdição, consulte: %s.',
				'bg'    => 'Тази политика за бисквитки е генерирана от FAZ Cookie Manager въз основа на образец за юрисдикция %s. Образците не представляват правен съвет. Администраторът на този сайт остава администратор на лични данни съгласно приложимото право и носи отговорност за точността и адекватността на публикуваното съдържание. За насоки, специфични за юрисдикцията, направете справка с: %s.',
				'cs'    => 'Tyto zásady používání cookies byly vygenerovány pluginem FAZ Cookie Manager na základě šablony pro jurisdikci %s. Šablony nepředstavují právní poradenství. Provozovatel tohoto webu zůstává správcem osobních údajů ve smyslu platných právních předpisů a odpovídá za správnost a přiměřenost zveřejněného obsahu. Pro informace specifické pro danou jurisdikci se obraťte na: %s.',
			);
			$tpl = $texts[ $lang ] ?? $texts['en'];
			$jurisdiction_label = self::jurisdiction_display_name( $jurisdiction, $lang );
			$url = (string) ( $data['OFFICIAL_RESOURCES_URL'] ?? '' );
			$url_html = $url ? '<a href="' . esc_url( $url ) . '" rel="noopener" target="_blank">' . esc_html( $url ) . '</a>' : '—';
			$body = sprintf( $tpl, '<strong>' . esc_html( $jurisdiction_label ) . '</strong>', $url_html );
		}
		return "\n" . '<div class="faz-cookie-policy-disclaimer">' . $body . '</div>';
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

	/**
	 * Whether the given language is a supported policy template language.
	 *
	 * @param string $lang Language code (normalised before comparison).
	 * @return bool True when the language is in Generator::LANGUAGES.
	 */
	private static function is_supported_lang( $lang ) {
		return in_array( self::normalize_lang( (string) $lang ), Generator::LANGUAGES, true );
	}

	/**
	 * Canonicalise a language code: underscores → hyphens, lowercase the
	 * language part and uppercase the region part (e.g. pt_br → pt-BR).
	 *
	 * @param string $lang Raw language code.
	 * @return string Normalised language code.
	 */
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

	/**
	 * Map a WordPress locale to a policy template language code.
	 *
	 * Reduces a full locale to the template's language key (it_IT → it,
	 * en_US → en), preserving pt-BR as the only region-qualified template.
	 *
	 * @param string $wp_locale WordPress locale (e.g. from get_locale()).
	 * @return string Template language code; 'en' when empty.
	 */
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

	/**
	 * Format the current date in the policy template's language.
	 *
	 * Localises the month name to the template language (not the site locale)
	 * and assembles day/month/year in that language's conventional order.
	 *
	 * @param string $lang Template language code.
	 * @return string Human-readable localised date.
	 */
	private static function format_date( $lang ) {
		$ts      = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$ts_unix = strtotime( $ts );
		// date_i18n() localises month names to the SITE locale, not the policy
		// template's language, so an it/fr/bg policy on an English site still
		// printed an English month name ("3 June 2026" instead of "3 giugno
		// 2026"). Localise the month explicitly per template language and
		// assemble the date in that language's usual order.
		$day        = (int) gmdate( 'j', $ts_unix );
		$month      = (int) gmdate( 'n', $ts_unix );
		$year       = gmdate( 'Y', $ts_unix );
		$months     = self::month_names( $lang );
		$month_name = $months[ $month - 1 ] ?? gmdate( 'F', $ts_unix );
		switch ( $lang ) {
			case 'en':
				return sprintf( '%s %d, %s', $month_name, $day, $year );       // June 3, 2026
			case 'de':
				return sprintf( '%d. %s %s', $day, $month_name, $year );       // 3. Juni 2026
			case 'es':
			case 'pt-BR':
				return sprintf( '%d de %s de %s', $day, $month_name, $year );  // 3 de junho de 2026
			case 'bg':
				return sprintf( '%d %s %s г.', $day, $month_name, $year );     // 3 юни 2026 г.
			case 'cs':
				// Czech dates take an ordinal day (trailing dot) and decline the
				// month into the genitive — month_names() returns those forms.
				return sprintf( '%d. %s %s', $day, $month_name, $year );       // 3. června 2026
			default:
				return sprintf( '%d %s %s', $day, $month_name, $year );        // it / fr: 3 giugno 2026
		}
	}

	/**
	 * Localised month names for a template language.
	 *
	 * @param string $lang Template language code.
	 * @return string[] Zero-indexed list of 12 month names; English fallback.
	 */
	private static function month_names( $lang ) {
		$names = array(
			'en'    => array( 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ),
			'it'    => array( 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre' ),
			'fr'    => array( 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' ),
			'de'    => array( 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' ),
			'es'    => array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ),
			'pt-BR' => array( 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro' ),
			'bg'    => array( 'януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември' ),
			// Genitive forms: Czech declines the month when written in a date
			// ("3. června 2026"), and month_names() is only ever consumed by
			// format_date(), so storing the genitive here keeps the date correct.
			'cs'    => array( 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince' ),
		);
		return $names[ $lang ] ?? $names['en'];
	}

	/**
	 * Format the data-retention period as a localised "%d months" string.
	 *
	 * @param array  $settings Settings array (reads `retention_months`).
	 * @param string $lang     Template language code.
	 * @return string Localised retention label; defaults to 12 months.
	 */
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
			'bg'    => '%d месеца',
			'cs'    => '%d měsíců',
		);
		return sprintf( $labels[ $lang ] ?? '%d months', $months );
	}

	/**
	 * Human-readable, localised display name for a jurisdiction.
	 *
	 * @param string $jurisdiction Jurisdiction key (e.g. 'gdpr-strict').
	 * @param string $lang         Template language code.
	 * @return string Localised label; English or raw key as fallback.
	 */
	private static function jurisdiction_display_name( $jurisdiction, $lang ) {
		$names = array(
			'gdpr-strict'     => array( 'en' => 'GDPR (EU/EEA/UK)', 'it' => 'GDPR (UE/SEE/UK)', 'fr' => 'RGPD (UE/EEE/UK)', 'de' => 'DSGVO (EU/EWR/UK)', 'es' => 'RGPD (UE/EEE/UK)', 'pt-BR' => 'GDPR (UE/EEE/UK)', 'bg' => 'GDPR (ЕС/ЕИП/Обединеното кралство)', 'cs' => 'GDPR (EU/EHP/UK)' ),
			'ccpa-california' => array( 'en' => 'CCPA/CPRA (California)', 'it' => 'CCPA/CPRA (California)', 'fr' => 'CCPA/CPRA (Californie)', 'de' => 'CCPA/CPRA (Kalifornien)', 'es' => 'CCPA/CPRA (California)', 'pt-BR' => 'CCPA/CPRA (Califórnia)', 'bg' => 'CCPA/CPRA (Калифорния)', 'cs' => 'CCPA/CPRA (Kalifornie)' ),
			'lgpd-brazil'     => array( 'en' => 'LGPD (Brazil)', 'it' => 'LGPD (Brasile)', 'fr' => 'LGPD (Brésil)', 'de' => 'LGPD (Brasilien)', 'es' => 'LGPD (Brasil)', 'pt-BR' => 'LGPD (Brasil)', 'bg' => 'LGPD (Бразилия)', 'cs' => 'LGPD (Brazílie)' ),
		);
		return $names[ $jurisdiction ][ $lang ] ?? $names[ $jurisdiction ]['en'] ?? $jurisdiction;
	}

	/**
	 * Endonym (native display name) for a template language.
	 *
	 * @param string $lang    Language code to name.
	 * @param string $in_lang Language to express the name in (currently unused;
	 *                        names are returned as endonyms).
	 * @return string Native language name; raw code as fallback.
	 */
	private static function language_display_name( $lang, $in_lang ) {
		$names = array(
			'en'    => 'English',
			'it'    => 'Italiano',
			'fr'    => 'Français',
			'de'    => 'Deutsch',
			'es'    => 'Español',
			'pt-BR' => 'Português (Brasil)',
			'bg'    => 'Български',
			'cs'    => 'Čeština',
		);
		return $names[ $lang ] ?? $lang;
	}

	/**
	 * Official data-protection authority URL for a jurisdiction.
	 *
	 * @param string $jurisdiction Jurisdiction key.
	 * @return string Authority URL, or empty string when unknown.
	 */
	private static function official_resources_url( $jurisdiction ) {
		$urls = array(
			'gdpr-strict'     => 'https://edpb.europa.eu/',
			'ccpa-california' => 'https://cppa.ca.gov/',
			'lgpd-brazil'     => 'https://www.gov.br/anpd/pt-br',
		);
		return $urls[ $jurisdiction ] ?? '';
	}

	/**
	 * Build the canonical URL of the current page for {{COOKIE_POLICY_URL}}.
	 *
	 * Derives the host from home_url() (admin-controlled) rather than the
	 * attacker-controlled Host header, and uses only the sanitised request
	 * path — query string and fragment are stripped so preview nonces never
	 * leak into the published policy text.
	 *
	 * @return string Canonical path-only home URL for the current request.
	 */
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
		// sanitised REQUEST_URI path. We also STRIP the query string
		// and fragment: rendering the policy from inside the WP
		// preview flow (?preview_id=…&preview_nonce=…) would otherwise
		// leak the preview nonce into the public policy text, as
		// reported by Gooloo. The canonical policy URL is its path
		// only — neither query state nor anchor belong in the
		// {{COOKIE_POLICY_URL}} placeholder.
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return home_url( '/' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = wp_unslash( (string) $_SERVER['REQUEST_URI'] );
		// Normalise leading slash and drop any control chars / CR-LF
		// the request might have smuggled.
		$request_uri = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $request_uri );
		// Strip query string and fragment — keep only the path.
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return home_url( '/' );
		}
		$path = '/' . ltrim( $path, '/' );
		return home_url( $path );
	}

	/**
	 * Whether a database table exists (exact name match).
	 *
	 * @param string $table Fully-qualified table name.
	 * @return bool True when the table is present.
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
