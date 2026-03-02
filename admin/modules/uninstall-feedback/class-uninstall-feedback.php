<?php
/**
 * Class Uninstall_Feedback file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Uninstall_Feedback;

use WP_Error;
use WP_REST_Response;
use FazCookie\Includes\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Uninstall feedback Operation
 *
 * @class       Uninstall_Feedback
 * @version     3.0.0
 * @package     FazCookie
 */
class Uninstall_Feedback extends Modules {

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	protected $plugin_file = FAZ_PLUGIN_BASENAME; // plugin main file.

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
	protected $rest_base = '/uninstall-feedback';

	/**
	 * Constructor.
	 */
	public function init() {

		add_action( 'admin_footer', array( $this, 'attach_feedback_modal' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), array( $this, 'plugin_action_links' ) );
		add_action( 'rest_api_init', array( $this, 'faz_register_routes' ) );
	}

	/**
	 * Register the routes for uninstall feedback.
	 */
	public function faz_register_routes() {

		register_rest_route(
			$this->namespace,
			$this->rest_base,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_uninstall_reason' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);
	}

	/**
	 * Check if a given request has access to create an item.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'fazcookie_rest_cannot_create', __( 'Sorry, you are not allowed to create resources.', 'faz-cookie-manager' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Edit action links
	 *
	 * @param array $links action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {

		if ( array_key_exists( 'deactivate', $links ) ) {
			$links['deactivate'] = str_replace( '<a', '<a class="faz-deactivate-link"', $links['deactivate'] );
		}

		return $links;
	}

	/**
	 * Get the uninstall reasons
	 *
	 * @return array
	 */
	private function get_uninstall_reasons() {

		$reasons = array(
			array(
				'id'     => 'setup-difficult',
				'text'   => __( 'Setup is too difficult/ Lack of documentation', 'faz-cookie-manager' ),
				'fields' => array(
					array(
						'type'        => 'textarea',
						'placeholder' => __(
							'Describe the challenges that you faced while using our plugin',
							'faz-cookie-manager'
						),
					),
				),
			),
			array(
				'id'     => 'not-have-that-feature',
				'text'   => __( 'The plugin is great, but I need specific feature that you don\'t support', 'faz-cookie-manager' ),
				'fields' => array(
					array(
						'type'        => 'textarea',
						'placeholder' => __( 'Could you tell us more about that feature?', 'faz-cookie-manager' ),
					),
				),
			),
			array(
				'id'   => 'affecting-performance',
				'text' => __( 'The plugin is affecting website speed', 'faz-cookie-manager' ),
			),
			array(
				'id'     => 'found-better-plugin',
				'text'   => __( 'I found a better plugin', 'faz-cookie-manager' ),
				'fields' => array(
					array(
						'type'        => 'text',
						'placeholder' => __( 'Please share which plugin', 'faz-cookie-manager' ),
					),
				),
			),
			array(
				'id'     => 'fazcookie-connection-issues',
				'text'   => __( 'I have issues while connecting to the web app', 'faz-cookie-manager' ),
				'fields' => array(
					array(
						'type'        => 'textarea',
						'placeholder' => __( 'Please describe the issues', 'faz-cookie-manager' ),
					),
				),
			),
			array(
				'id'   => 'use-fazcookie-webapp',
				'text' => __( 'I would like to use the web app instead of the plugin', 'faz-cookie-manager' ),
			),
			array(
				'id'   => 'temporary-deactivation',
				'text' => __( 'It’s a temporary deactivation', 'faz-cookie-manager' ),
			),
			array(
				'id'     => 'other',
				'text'   => __( 'Other', 'faz-cookie-manager' ),
				'fields' => array(
					array(
						'type'        => 'textarea',
						'placeholder' => __( 'Please share the reason', 'faz-cookie-manager' ),
					),
				),
			),
		);

		return $reasons;
	}

	/**
	 * Attach modal for feedback and uninstall
	 *
	 * @return void
	 */
	public function attach_feedback_modal() {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow ) {
			return;
		}
		$reasons = $this->get_uninstall_reasons();
		?>
		<div class="faz-modal" id="faz-modal">
			<div class="faz-modal-wrap">
				<div class="faz-modal-header">
					<h3><?php echo esc_html__( 'Quick Feedback', 'faz-cookie-manager' ); ?></h3>
					<button type="button" class="faz-modal-close"><svg width="10" height="10" viewBox="0 0 10 10" fill="none" xmlns="http://www.w3.org/2000/svg"> <path d="M0.572899 0.00327209C0.459691 0.00320032 0.349006 0.036716 0.254854 0.0995771C0.160701 0.162438 0.0873146 0.251818 0.0439819 0.356405C0.000649228 0.460992 -0.0106814 0.576084 0.0114242 0.687113C0.0335299 0.798142 0.0880779 0.900118 0.168164 0.980132L4.18928 5L0.168164 9.01987C0.0604905 9.12754 0 9.27358 0 9.42585C0 9.57812 0.0604905 9.72416 0.168164 9.83184C0.275838 9.93951 0.421875 10 0.574148 10C0.726422 10 0.872459 9.93951 0.980133 9.83184L5.00125 5.81197L9.02237 9.83184C9.13023 9.93836 9.2755 9.99844 9.4271 9.99923C9.5023 9.99958 9.57681 9.98497 9.6463 9.95623C9.71579 9.92749 9.77886 9.8852 9.83184 9.83184C9.93924 9.72402 9.99955 9.57804 9.99955 9.42585C9.99955 9.27367 9.93924 9.12768 9.83184 9.01987L5.81072 5L9.83184 0.980132C9.88515 0.926818 9.92744 0.863524 9.9563 0.793865C9.98515 0.724206 10 0.649547 10 0.574148C10 0.49875 9.98515 0.42409 9.9563 0.354431C9.92744 0.284772 9.88515 0.221479 9.83184 0.168164C9.77852 0.114849 9.71523 0.072558 9.64557 0.0437044C9.57591 0.0148507 9.50125 0 9.42585 0C9.35045 0 9.27579 0.0148507 9.20614 0.0437044C9.13648 0.072558 9.07318 0.114849 9.01987 0.168164L4.99813 4.19053L0.976385 0.170662C0.868901 0.0635642 0.723383 0.00338113 0.57165 0.00327209H0.572899Z" fill="#ffffff"/> </svg></button>
				</div>
				<div class="faz-modal-body">
					<h4 class="faz-feedback-caption"><?php echo esc_html__( 'If you have a moment, please let us know why you are deactivating FAZ Cookie Manager.', 'faz-cookie-manager' ); ?></h4>
					<ul class="faz-feedback-reasons-list">
						<?php
						foreach ( $reasons as $reason ) :
							?>
							<li>
								<div class="faz-feedback-form-group">
									<label class="faz-feedback-label"><input type="radio" name="selected-reason" value="<?php echo esc_attr( $reason['id'] ); ?>" class="faz-feedback-input-radio"><?php echo esc_html( $reason['text'] ); ?></label>
									<?php
										$fields = ( isset( $reason['fields'] ) && is_array( $reason['fields'] ) ) ? $reason['fields'] : array();
									if ( empty( $fields ) ) {
										continue;
									}
									?>
									<div class="faz-feedback-form-fields">
										<?php

										foreach ( $fields as $field ) :
											$field_type        = isset( $field['type'] ) ? $field['type'] : 'text';
											$field_placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
											$field_key         = isset( $reason['id'] ) ? $reason['id'] : '';
											$field_name        = $field_key . '-' . $field_type;
											if ( 'textarea' === $field_type ) :
												?>
												<textarea rows="3" cols="45" class="faz-feedback-input-field" name="<?php echo esc_attr( $field_name ); ?>" placeholder="<?php echo esc_attr( $field_placeholder ); ?>"></textarea>
												<?php
											else :
												?>
												<input class="faz-feedback-input-field" type="text" name="<?php echo esc_attr( $field_name ); ?>" placeholder="<?php echo esc_attr( $field_placeholder ); ?>" >
												<?php
											endif;
											endforeach;
										?>
									</div>
								</div>
							</li>

						<?php endforeach; ?>
					</ul>
					<div class="faz-uninstall-feedback-privacy-policy">
						<?php echo esc_html__( "We do not collect any personal data when you submit this form. It's your feedback that we value.", 'faz-cookie-manager' ); ?>
						<a href="https://fabiodalez.it/privacy-policy/" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Privacy Policy', 'faz-cookie-manager' ); ?></a>
					</div>
				</div>
				<div class="faz-modal-footer">
					<button class="button-primary faz-modal-submit">
						<?php echo esc_html__( 'Submit & Deactivate', 'faz-cookie-manager' ); ?>
					</button>
					<a class="faz-goto-support" href="https://fabiodalez.it/support/" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-external"></span>
						<?php echo esc_html__( 'Go to support', 'faz-cookie-manager' ); ?>
					</a>
					<button class="button-secondary faz-modal-skip">
						<?php echo esc_html__( 'Skip & Deactivate', 'faz-cookie-manager' ); ?>
					</button>
				</div>
			</div>
		</div>

		<style type="text/css">
			.faz-modal {
				position: fixed;
				z-index: 99999;
				top: 0;
				right: 0;
				bottom: 0;
				left: 0;
				background: rgba(0, 0, 0, 0.5);
				display: none;
			}

			.faz-modal.modal-active {
				display: flex;
				align-items: center;
				justify-content: center;
			}

			.faz-modal-wrap {
				width: 600px;
				position: relative;
				background: #fff;
			}

			.faz-modal-header {
				background: linear-gradient(336.94deg,#1A7FBB -111.69%,#26238D 196.34%);
				padding: 12px 20px;
			}

			.faz-modal-header h3 {
				display: inline-block;
				color: #fff;
				line-height: 150%;
				margin: 0;
			}

			.faz-modal-body {
				font-size: 14px;
				line-height: 2.4em;
				padding: 5px 30px 20px 30px;
				box-sizing: border-box;
			}

			.faz-modal-body h3 {
				font-size: 15px;
			}

			.faz-modal-body .input-text,
			.faz-modal-body {
				width: 100%;
			}

			.faz-modal-body .faz-feedback-input {
				margin-top: 5px;
				margin-left: 20px;
			}

			.faz-modal-footer {
				padding: 0px 20px 15px 20px;
				display: flex;
			}

			.faz-button-left {
				float: left;
			}

			.faz-button-right {
				float: right;
			}

			.faz-sub-reasons {
				display: none;
				padding-left: 20px;
				padding-top: 10px;
				padding-bottom: 4px;
			}

			.faz-uninstall-feedback-privacy-policy {
				text-align: left;
				font-size: 12px;
				line-height: 14px;
				margin-top: 20px;
				font-style: italic;
			}

			.faz-uninstall-feedback-privacy-policy a {
				font-size: 11px;
				color: #1863DC;
				text-decoration-color: #99c3d7;
			}

			.faz-goto-support {
				color: #1863DC;
				text-decoration: none;
				display: flex;
				align-items: center;
				margin-left: 15px;
			}

			.faz-modal-footer .faz-modal-submit {
				background-color: #1863DC;
				border-color: #1863DC;
				color: #FFFFFF;
			}

			.faz-modal-footer .faz-modal-skip {
				font-size: 12px;
				color: #a4afb7;
				background: none;
				border: none;
				margin-left: auto;
			}

			.faz-modal-close {
				background: transparent;
				border: none;
				color: #fff;
				float: right;
				font-size: 18px;
				font-weight: lighter;
				cursor: pointer;
			}

			.faz-feedback-caption {
				font-weight: bold;
				font-size: 15px;
				color: #27283C;
				line-height: 1.5;
			}

			input[type="radio"].faz-feedback-input-radio {
				margin: 0 10px 0 0;
				box-shadow: none;
			}
			.faz-feedback-reasons-list li {
				line-height: 1.9;
			}
			.faz-feedback-label {
				font-size: 13px;
			}
			.faz-modal .faz-feedback-input-field {
				width: 98%;
				display: flex;
				padding: 5px;
				-webkit-box-shadow: none;
				box-shadow: none;
				font-size: 13px;
			}
			.faz-modal input[type="text"].faz-feedback-input-field:focus {
				-webkit-box-shadow: none;
				box-shadow: none;
			}
			.faz-feedback-form-fields {
				margin: 10px 0 0 25px;
				display: none;
			}
		</style>

		<script type="text/javascript">
			(function($) {
				$(function() {
					const modal = $('#faz-modal');
					let deactivateLink = '';
					$('a.faz-deactivate-link').click(function(e) {
						e.preventDefault();
						modal.addClass('modal-active');
						deactivateLink = $(this).attr('href');
						modal.find('a.dont-bother-me').attr('href', deactivateLink).css('float', 'right');
					});

					modal.on('click', '.faz-modal-skip', function(e) {
						e.preventDefault();
						modal.removeClass('modal-active');
						window.location.href = deactivateLink;
					});

					modal.on('click', '.faz-modal-close', function(e) {
						e.preventDefault();
						modal.removeClass('modal-active');
					});
					modal.on('click', 'input[type="radio"]', function() {
						$('.faz-feedback-form-fields').hide();
						const $parent =   $(this).closest('.faz-feedback-form-group');
						if( !$parent ) return;
						const $fields = $parent.find('.faz-feedback-form-fields');
						if( !$fields ) return;
						const $input = $fields.find('.faz-feedback-input-field');
						$input && $fields.show(),$input.focus();
					});
					modal.on('click', '.faz-modal-submit', function(e) {
						e.preventDefault();
						const button = $(this);
						if (button.hasClass('disabled')) {
							return;
						}
						const $radio = $('input[type="radio"]:checked', modal);
						const $parent =   $radio && $radio.closest('.faz-feedback-form-group');
						if( !$parent ) {
							window.location.href = deactivateLink;
							return;
						}
						const $input = $parent.find('.faz-feedback-input-field');
						$.ajax({
							url: "<?php echo esc_url_raw( rest_url() . $this->namespace . $this->rest_base ); ?>",
							type: 'POST',
							data: {
								reason_id: (0 === $radio.length) ? 'none' : $radio.val(),
								reason_text: (0 === $radio.length) ? 'none' : $radio.closest('label').text(),
								reason_info: (0 !== $input.length) ? $input.val().trim() : ''
							},
							beforeSend: function(xhr) {
								button.addClass('disabled');
								button.text('Processing...');
								xhr.setRequestHeader( 'X-WP-Nonce', '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>');
							},
							complete: function() {
								window.location.href = deactivateLink;
							}
						});
					});
				});
			}(jQuery));
		</script>
		<?php
	}

	/**
	 * Send uninstall reason to server
	 *
	 * @param \WP_REST_Request $request request data.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function send_uninstall_reason( $request ) {
		$reason_id = isset( $request['reason_id'] ) ? sanitize_text_field( $request['reason_id'] ) : '';
		if ( empty( $reason_id ) ) {
			return new WP_Error( 'missing_reason', __( 'Reason ID is required.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		$valid_ids = array( 'none', 'setup-difficult', 'not-have-that-feature', 'affecting-performance', 'found-better-plugin', 'fazcookie-connection-issues', 'use-fazcookie-webapp', 'temporary-deactivation', 'other' );
		if ( ! in_array( $reason_id, $valid_ids, true ) ) {
			return new WP_Error( 'invalid_reason', __( 'Invalid reason ID.', 'faz-cookie-manager' ), array( 'status' => 400 ) );
		}
		// External feedback endpoint disabled — data stays local.
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}
