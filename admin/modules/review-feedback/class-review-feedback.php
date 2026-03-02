<?php
/**
 * Class Review_Feedback file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Review_Feedback;

use FazCookie\Includes\Modules;
use FazCookie\Includes\Notice;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Uninstall feedback Operation
 *
 * @class       Review_Feedback
 * @version     3.0.0
 * @package     FazCookie
 */
class Review_Feedback extends Modules {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'faz/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/settings/notices/review_notice';

	/**
	 * WordPress.org review link
	 *
	 * @var string
	 */
	protected $review_url = 'https://wordpress.org/support/plugin/faz-cookie-manager/reviews/#new-post';

	/**
	 * Constructor.
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'add_notice' ) );
		add_action( 'admin_print_footer_scripts', array( $this, 'add_script' ) );
		add_filter( 'admin_footer_text', array( $this, 'add_footer_review_link' ) );
	}

	/**
	 * Display review notice
	 *
	 * @return void
	 */
	public function add_notice() {
		global $pagenow;
		
		// Only show on plugins page
		if ( 'plugins.php' !== $pagenow ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notices = Notice::get_instance()->get();
		if ( ! isset( $notices['review_notice'] ) || empty( $notices['review_notice'] ) ) {
			return;
		}

		$screen = get_current_screen();

		$plugin_dir_url = defined( 'FAZ_PLUGIN_URL' ) ? FAZ_PLUGIN_URL : trailingslashit( site_url() );
		$assets_path    = $plugin_dir_url . 'admin/dist/img/';
		?>
		<div class="faz-notice-review faz-admin-notice faz-admin-notice-default is-dismissible">
			<div class="faz-admin-notice-content">
				<div class="faz-admin-notice-message">
					<div class="faz-row faz-align-center">
						<div class="faz-col-12">
							<h4 class="faz-admin-notice-header"><img width="100" src="<?php echo esc_url( $assets_path . 'logo.svg' ); ?>" alt="<?php esc_attr_e( 'FAZ Cookie Manager Logo', 'faz-cookie-manager' ); ?>"></h4> <?php //phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage ?>
							<p style="margin-top: 15px; margin-bottom:5px;"><?php 
								/* translators: %1$s: opening bold tag, %2$s: closing bold tag */
								echo wp_kses_post( sprintf( __( 'Hey, we at %1$s FAZ Cookie Manager %2$s would like to thank you for using our plugin. We would really appreciate if you could take a moment to drop a quick review that will inspire us to keep going.', 'faz-cookie-manager' ), '<b>', '</b>' ) );
							?></p>
						</div>
						<div class="faz-col-12">
							<div class="faz-flex" style="margin-top: 10px;">
								<button class="faz-button faz-button-review"><?php echo esc_html__( 'Review now', 'faz-cookie-manager' ); ?></button>
								<button class="faz-button-outline-secondary faz-button faz-button-never"><?php echo esc_html__( 'Never show again', 'faz-cookie-manager' ); ?></button>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="faz-admin-notice-close"><button type="button" aria-label="Close" class="faz-close faz-button-cancel"><span aria-hidden="true">×</span></button></div>
		</div>
		<style>
			.faz-admin-notice {
				display: flex;
				justify-content: space-between;
				position: relative;
				margin: 0 0 10px;
				border-radius: 5px;
				background: #ffffff;
				margin: 15px 20px 10px 2px;
				width: calc(100% - 20px);
				float: left;
				position: relative;
				border: 1px solid #d7e1f2;
			}
			.faz-admin-notice-content {
				display: flex;
				align-items: center;
				margin: 0;
				padding: 10px 15px 10px 15px;
				border: 0;
			}
			.faz-admin-notice-header {
				margin-top: 5px;
			}
			.faz-admin-notice .faz-admin-notice-content .faz-admin-notice-header {
				display: flex;
				align-items: center;
				margin: 0 0 5px;
				padding: 0;
				border: 0;
				color: #23282d;
				font-size: 16px;
				line-height: 18px;
			}
			.faz-button {
				width: auto;
				min-width: 80px;
				padding: 8px 14px;
				background-color: #1863dc;
				color: #ffffff;
				border: 1px solid #1863dc;
				font-weight: 500;
				font-size: 14px;
				border-radius: 3px;
				cursor: pointer;
				line-height: 16px;
			}
			.faz-button-outline-secondary {
				background-color: transparent;
				color: #555d66;
				border-color: #c9d0d6;
			}
			.faz-flex {
				.faz-button-outline-secondary{
					margin-left: 10px;
				}
			}
			.faz-admin-notice .faz-close {
				font-size: 20px;
				font-weight: 300;
				padding: 0;
				background: transparent;
				border: none;
				display: inline-block;
				color: #7e7e7e;
				cursor: pointer;
			}
			.faz-admin-notice .faz-admin-notice-close {
				display: flex;
				align-items: center;
				margin-right: 15px;
				position: absolute;
				right: 0;
				top: 10px;
			}
			.faz-admin-notice .faz-admin-notice-content p {
				font-size: 14px;
			}
			.faz-admin-notice-footer {
				position: absolute;
				right: 45px;
				bottom: 15px;
			}
			.faz-admin-notice-message {
				flex: 1;
				position: relative;
				padding: 5px 20px 3px 0px;
			}
		</style>
		<?php

	}

	/**
	 * Review feedback scripts.
	 *
	 * @return void
	 */
	public function add_script() {
		global $pagenow;

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		// Only load script on plugins page
		if ( 'plugins.php' !== $pagenow ) {
			return;
		}

		$expiry = 60 * DAY_IN_SECONDS;
		
		?>
			<script type="text/javascript">
				(function($) {
					const expiration = '<?php echo esc_js( $expiry ); ?>';
					function fazUpdateNotice( expiry = expiration ) {
						$.ajax({
							url: "<?php echo esc_url_raw( rest_url() . $this->namespace . $this->rest_base ); ?>",
							headers: {
								'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
								contentType: 'application/json'
							},
							type: 'POST',
							dataType: 'json',
							data: {
								expiry: expiry
							},
							complete: function( response ) {
								$('.faz-notice-review').hide();
							}
						});
					}
					$(document).on('click', '.faz-button-cancel', function(e) {
						e.preventDefault();
						fazUpdateNotice();
					});
					$(document).on('click', '.faz-button-review', function(e) {
						e.preventDefault();
						window.open('<?php echo esc_js( $this->review_url ); ?>');
					});
					$(document).on('click', '.faz-button-never', function(e) {
						e.preventDefault();
						fazUpdateNotice(0);
					});
				})(jQuery)
			</script>
			<?php
	}

	function add_footer_review_link($footer_text) {
		
		// Check if we are on the FAZ Cookie Manager Dashboard page
		$screen = get_current_screen();
		if ($screen->id == 'toplevel_page_faz-cookie-manager') {
			$link_text = esc_html__( 'Give us a 5-star rating!', 'faz-cookie-manager' );
			$link1 = sprintf(
				'<a class="faz-button-review" href="%1$s" target="_blank" title="%2$s">&#9733;&#9733;&#9733;&#9733;&#9733;</a>',
				$this->review_url,
				$link_text
			);
			$link2 = sprintf(
				'<a class="faz-button-review" href="%1$s" target="_blank" title="%2$s">WordPress.org</a>',
				$this->review_url,
				$link_text
			);

			return sprintf(
				/* translators: %1$s: FAZ Cookie Manager plugin name in bold, %2$s: star rating link, %3$s: WordPress.org link */
				esc_html__(
					'Please rate %1$s %2$s on %3$s to help us spread the word. Thank you from the FAZ Cookie Manager team!',
					'faz-cookie-manager'
				),
				sprintf( '<strong>%1$s</strong>', 'FAZ Cookie Manager' ),
				wp_kses_post( $link1 ),
				wp_kses_post( $link2 )
			);
		}
		return $footer_text;
	}
}
