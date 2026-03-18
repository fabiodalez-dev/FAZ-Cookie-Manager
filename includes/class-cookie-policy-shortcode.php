<?php
/**
 * Cookie Policy Shortcode — [faz_cookie_policy]
 *
 * Generates a complete cookie policy page with standard sections,
 * optionally embedding the [faz_cookie_table] shortcode output.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cookie_Policy_Shortcode {

	/**
	 * Initialize and register the shortcode.
	 */
	public function __construct() {
		add_shortcode( 'faz_cookie_policy', array( $this, 'render' ) );
	}

	/**
	 * Render the cookie policy.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'show_table' => 'yes',
				'site_name'  => get_bloginfo( 'name' ),
				'contact'    => get_option( 'admin_email' ),
			),
			$atts,
			'faz_cookie_policy'
		);

		$site_name  = esc_html( $atts['site_name'] );
		$contact    = sanitize_email( $atts['contact'] );
		$show_table = 'yes' === $atts['show_table'];

		// Enqueue inline styles for the policy page.
		wp_register_style( 'faz-cookie-policy', false );
		wp_enqueue_style( 'faz-cookie-policy' );
		wp_add_inline_style( 'faz-cookie-policy', '
.faz-cookie-policy { max-width: 800px; line-height: 1.7; }
.faz-cookie-policy h2 { margin-top: 2em; }
.faz-cookie-policy ul { padding-left: 1.5em; }
.faz-cookie-policy li { margin-bottom: 0.5em; }
.faz-policy-manage-btn {
	background: #1863DC; color: #fff; border: none; padding: 10px 20px;
	border-radius: 6px; cursor: pointer; font-size: 14px;
}
.faz-policy-manage-btn:hover { background: #1453b8; }
' );

		ob_start();
		?>
		<div class="faz-cookie-policy">

		<h2><?php esc_html_e( 'What Are Cookies', 'faz-cookie-manager' ); ?></h2>
		<p><?php
			/* translators: %s: site name */
			printf( esc_html__( 'Cookies are small text files that are placed on your device when you visit %s. They are widely used to make websites work more efficiently, as well as to provide information to the website owners.', 'faz-cookie-manager' ), $site_name );
		?></p>

		<h2><?php esc_html_e( 'How We Use Cookies', 'faz-cookie-manager' ); ?></h2>
		<p><?php
			/* translators: %s: site name */
			printf( esc_html__( '%s uses cookies for several purposes, including:', 'faz-cookie-manager' ), $site_name );
		?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Necessary cookies', 'faz-cookie-manager' ); ?></strong> — <?php esc_html_e( 'These cookies are essential for the website to function properly. They enable basic functions like page navigation, access to secure areas, and shopping cart functionality. The website cannot function properly without these cookies.', 'faz-cookie-manager' ); ?></li>
			<li><strong><?php esc_html_e( 'Functional cookies', 'faz-cookie-manager' ); ?></strong> — <?php esc_html_e( 'These cookies enable the website to remember choices you make (such as your language preference or region) and provide enhanced, more personalised features.', 'faz-cookie-manager' ); ?></li>
			<li><strong><?php esc_html_e( 'Analytics cookies', 'faz-cookie-manager' ); ?></strong> — <?php esc_html_e( 'These cookies help us understand how visitors interact with the website by collecting and reporting information anonymously. This helps us improve the website and your experience.', 'faz-cookie-manager' ); ?></li>
			<li><strong><?php esc_html_e( 'Marketing cookies', 'faz-cookie-manager' ); ?></strong> — <?php esc_html_e( 'These cookies are used to deliver advertisements that are relevant to you and your interests. They are also used to limit the number of times you see an advertisement and help measure the effectiveness of advertising campaigns.', 'faz-cookie-manager' ); ?></li>
		</ul>

		<?php if ( $show_table ) : ?>
		<h2><?php esc_html_e( 'Cookies We Use', 'faz-cookie-manager' ); ?></h2>
		<p><?php esc_html_e( 'The table below lists the cookies used on this website, along with their purpose, duration, and category.', 'faz-cookie-manager' ); ?></p>
		<?php echo do_shortcode( '[faz_cookie_table]' ); ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'How to Manage Cookies', 'faz-cookie-manager' ); ?></h2>
		<p><?php esc_html_e( 'You can manage your cookie preferences at any time by clicking the cookie icon in the bottom corner of the page, or by using the button below.', 'faz-cookie-manager' ); ?></p>
		<p><button type="button" class="faz-consent-trigger faz-policy-manage-btn"><?php esc_html_e( 'Manage Cookie Preferences', 'faz-cookie-manager' ); ?></button></p>
		<p><?php esc_html_e( 'You can also control cookies through your browser settings. Most browsers allow you to:', 'faz-cookie-manager' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'View what cookies are stored and delete them individually', 'faz-cookie-manager' ); ?></li>
			<li><?php esc_html_e( 'Block third-party cookies', 'faz-cookie-manager' ); ?></li>
			<li><?php esc_html_e( 'Block cookies from specific sites', 'faz-cookie-manager' ); ?></li>
			<li><?php esc_html_e( 'Block all cookies from being set', 'faz-cookie-manager' ); ?></li>
			<li><?php esc_html_e( 'Delete all cookies when you close your browser', 'faz-cookie-manager' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Please note that if you disable cookies, some features of this website may not function properly.', 'faz-cookie-manager' ); ?></p>

		<h2><?php esc_html_e( 'More Information', 'faz-cookie-manager' ); ?></h2>
		<p><?php
			/* translators: %s: contact email address wrapped in a mailto link */
			printf( esc_html__( 'If you have questions about our use of cookies, please contact us at %s.', 'faz-cookie-manager' ), '<a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>' );
		?></p>
		<p><?php
			/* translators: %s: current date */
			printf( esc_html__( 'This cookie policy was last updated on %s.', 'faz-cookie-manager' ), esc_html( wp_date( get_option( 'date_format' ) ) ) );
		?></p>

		</div>
		<?php
		return ob_get_clean();
	}
}
