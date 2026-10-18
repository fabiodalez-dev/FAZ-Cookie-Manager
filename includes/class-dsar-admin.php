<?php
/**
 * Admin screen for stored Data Subject Access Requests (post_type faz_dsar).
 *
 * Without this class the record created by [faz_dsar_form] is a dead end: the
 * link in the notification email opens the post editor, every DSAR meta key is
 * underscore-prefixed (so `is_protected_meta()` hides it from the Custom Fields
 * panel), and nothing is registered to render it — the admin sees a title and
 * no way to act. Reported from the field: the only way to honour a request was
 * to copy the address out of the email and retype it into
 * Tools → Erase Personal Data.
 *
 * WordPress already owns that flow. `wp_create_user_request()` is the documented
 * entry point into it, the plugin already registers its exporter and eraser
 * (see Utils), and this class is the bridge: it shows the request and turns it
 * into a core privacy request with one click, verifying the requester's identity
 * through core's own confirmation email rather than trusting a public form.
 *
 * @package FazCookie\Includes
 * @since 1.34.1
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DSAR_Admin {

	/** Post type holding the stored requests. */
	const POST_TYPE = 'faz_dsar';

	/** admin-post.php action name. */
	const ACTION = 'faz_dsar_action';

	/** Query arg carrying the outcome back to the edit screen. */
	const NOTICE_ARG = 'faz_dsar_msg';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'register_meta_boxes' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	/**
	 * Surface the stored requests under the plugin's own menu.
	 *
	 * The post type is registered with `show_in_menu => false`, which left the
	 * list screen reachable only by guessing its URL or following the link in a
	 * notification email — so a request whose email was lost or filtered was,
	 * in practice, invisible. Core only auto-registers a menu for
	 * `show_in_menu === true`, so the submenu is added here by hand.
	 */
	public function register_menu() {
		$label = __( 'Data Requests', 'faz-cookie-manager' );
		$menu  = $label;

		// The count is here for a reason beyond convenience. Past the daily
		// ceiling the form stops emailing each request, which means an attacker
		// who floods the form decides whether the admin's inbox hears about a
		// genuine request. This number does not go through mail, cannot be
		// rate-limited by traffic, and is on screen on every admin page — so
		// "nobody was told" stops being something a flood can arrange.
		$pending = DSAR_Shortcode::pending_count();
		if ( $pending > 0 ) {
			$menu .= sprintf(
				' <span class="update-plugins count-%1$d"><span class="plugin-count">%2$s</span></span>',
				$pending,
				number_format_i18n( $pending )
			);
		}

		add_submenu_page(
			'faz-cookie-manager',
			$label,
			$menu,
			'edit_faz_dsars',
			'edit.php?post_type=' . self::POST_TYPE
		);
	}

	/**
	 * Register the request detail and action boxes.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function register_meta_boxes( $post ) {
		add_meta_box(
			'faz-dsar-details',
			__( 'Request details', 'faz-cookie-manager' ),
			array( $this, 'render_details_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'faz-dsar-actions',
			__( 'Handle this request', 'faz-cookie-manager' ),
			array( $this, 'render_actions_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the submitted values, read-only.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_details_box( $post ) {
		$name    = (string) get_post_meta( $post->ID, '_dsar_name', true );
		$email   = (string) get_post_meta( $post->ID, '_dsar_email', true );
		$type    = (string) get_post_meta( $post->ID, '_dsar_type', true );
		$message = (string) get_post_meta( $post->ID, '_dsar_message', true );
		// An honoured erasure blanks the name, the address and the message in
		// place (faz_privacy_eraser()). Saying so beats three empty cells, which
		// read as a broken record rather than an erased one.
		$erased  = 'erased' === self::get_status( $post->ID );
		$blanked = '<em>' . esc_html__( '(erased)', 'faz-cookie-manager' ) . '</em>';

		$rows = array(
			__( 'Name', 'faz-cookie-manager')         => '' !== $name
				? esc_html( $name )
				: ( $erased ? $blanked : '&mdash;' ),
			__( 'Email', 'faz-cookie-manager' )       => $email
				? '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>'
				: ( $erased ? $blanked : '&mdash;' ),
			__( 'Request type', 'faz-cookie-manager' ) => esc_html( DSAR_Shortcode::get_type_label( $type ) ),
			__( 'Submitted', 'faz-cookie-manager' )    => esc_html( get_post_time( 'Y-m-d H:i', false, $post ) ),
			__( 'Status', 'faz-cookie-manager' )       => esc_html( self::status_label( self::get_status( $post->ID ) ) ),
		);
		$consent_id = (string) get_post_meta( $post->ID, '_dsar_consent_id', true );
		if ( '' !== $consent_id ) {
			$rows[ __( 'Consent ID', 'faz-cookie-manager' ) ] = '<code>' . esc_html( $consent_id ) . '</code> <a href="' . esc_url( add_query_arg( array( 'page' => 'faz-cookie-manager-consent-logs', 'consent_id' => rawurlencode( $consent_id ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'View consent history', 'faz-cookie-manager' ) . '</a>';
		}
		?>
		<table class="widefat striped" style="margin-bottom:12px;">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th scope="row" style="width:180px;"><?php echo esc_html( $label ); ?></th>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped individually above; the email cell is an intentional mailto anchor. ?>
					<td><?php echo $value; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><strong><?php esc_html_e( 'Additional information from the requester', 'faz-cookie-manager' ); ?></strong></p>
		<?php if ( '' === trim( $message ) && $erased ) : ?>
			<p><em><?php esc_html_e( '(erased)', 'faz-cookie-manager' ); ?></em></p>
		<?php elseif ( '' === trim( $message ) ) : ?>
			<p><em><?php esc_html_e( '(none provided)', 'faz-cookie-manager' ); ?></em></p>
		<?php else : ?>
			<div style="padding:10px;background:#f6f7f7;border-left:4px solid #c3c4c7;white-space:pre-wrap;">
				<?php echo esc_html( $message ); ?>
			</div>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'These values are stored exactly as submitted and cannot be edited here: the record is the audit trail of what the data subject asked for.', 'faz-cookie-manager' ); ?>
		</p>
		<?php if ( $erased ) : ?>
			<p class="description">
				<?php esc_html_e( 'This request was erased: the name, the address and the message are gone. What is kept is the right invoked and the date, so you can still show that the request was received and answered (GDPR Art. 5(2)). Erasure matches the email address rather than one request, so every stored request from that address was erased together with this one.', 'faz-cookie-manager' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the action buttons.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_actions_box( $post ) {
		$email      = (string) get_post_meta( $post->ID, '_dsar_email', true );
		$type       = (string) get_post_meta( $post->ID, '_dsar_type', true );
		$status     = self::get_status( $post->ID );
		$request_id = (int) get_post_meta( $post->ID, '_dsar_core_request_id', true );

		if ( ! is_email( $email ) ) {
			// Two different situations, and telling them apart matters: an
			// honoured erasure deliberately removed the address, and reading
			// "this record has no valid email address" there suggests a broken
			// submission the administrator should go and fix.
			if ( 'erased' === $status ) {
				echo '<p>' . esc_html__( 'The personal data in this request has been erased, so there is no address left to create a WordPress privacy request for. The dated record of the request is kept on purpose, to show that it was received and answered.', 'faz-cookie-manager' ) . '</p>';
				return;
			}
			echo '<p>' . esc_html__( 'This record has no valid email address, so it cannot be turned into a WordPress privacy request.', 'faz-cookie-manager' ) . '</p>';
			return;
		}

		$consent_id = (string) get_post_meta( $post->ID, '_dsar_consent_id', true );
		if ( '' !== $consent_id ) {
			if ( '' !== faz_dsar_verified_consent_id( $post->ID, $email ) ) {
				echo '<p>' . esc_html__( 'Ownership of this consent ID has been independently verified.', 'faz-cookie-manager' ) . '</p>';
			} else {
				echo self::consent_cookie_note( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from esc_html__() in consent_cookie_note().
				echo '<p>' . esc_html__( 'Independently verify that this consent ID belongs to the requester before confirming it below. Email confirmation alone does not prove ownership. Consent history is excluded from automated exports and erasure until verified.', 'faz-cookie-manager' ) . '</p>';
				if ( current_user_can( 'manage_privacy_options' ) ) {
					self::action_link( $post->ID, 'verify_consent_id', __( 'Confirm independently verified consent ID', 'faz-cookie-manager' ) );
				}
			}
		}

		if ( $request_id && get_post( $request_id ) ) {
			$action_name = (string) get_post_meta( $post->ID, '_dsar_core_request_action', true );
			printf(
				'<p>%s</p>',
				esc_html__( 'A WordPress privacy request has already been created for this address. Finish it from the core tool:', 'faz-cookie-manager' )
			);
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( self::core_tool_url( $action_name, $email ) ),
				esc_html( self::core_tool_label( $action_name ) )
			);
		} else {
			echo '<p>' . esc_html__( 'Create the matching WordPress privacy request. The data subject receives a confirmation link first — WordPress verifies the address before any data is exported or erased.', 'faz-cookie-manager' ) . '</p>';

			$suggested = self::suggested_action( $type );
			$buttons   = array(
				'remove_personal_data' => __( 'Create erasure request', 'faz-cookie-manager' ),
				'export_personal_data' => __( 'Create export request', 'faz-cookie-manager' ),
			);
			foreach ( $buttons as $action_name => $label ) {
				if ( ! current_user_can( self::required_cap( $action_name ) ) ) {
					continue;
				}
				self::action_link( $post->ID, $action_name, $label, $action_name === $suggested );
			}

			if ( '' === $suggested ) {
				echo '<p class="description">' . esc_html__( 'WordPress has no automated flow for this right: handle it by replying to the requester. An export request is still useful to see what data the site holds about them.', 'faz-cookie-manager' ) . '</p>';
			}
		}

		echo '<hr>';
		if ( 'completed' === $status ) {
			self::action_link( $post->ID, 'reopen', __( 'Reopen request', 'faz-cookie-manager' ) );
		} else {
			self::action_link( $post->ID, 'complete', __( 'Mark as completed', 'faz-cookie-manager' ) );
		}
		echo '<p class="description">' . esc_html__( 'GDPR Article 12 gives you one month to respond.', 'faz-cookie-manager' ) . '</p>';
	}

	/**
	 * Perform a requested action and return to the record.
	 */
	public function handle_action() {
		// GET, like core's own privacy actions — see action_link() for why a form
		// is not available inside the editor. Read from $_GET and not $_REQUEST:
		// PHP's request_order is configurable, and where it includes cookies
		// they are merged LAST, so a cookie planted on the administrator's
		// browser would override the parameters in the link they actually
		// clicked. The nonce below covers both parameters, so swapping either
		// one invalidates it, but the parameters must be read from the place the
		// nonce was computed over.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$action  = isset( $_GET['faz_action'] ) ? sanitize_key( wp_unslash( $_GET['faz_action'] ) ) : '';

		// The nonce first, then the record: a 404 that answers before the nonce
		// is checked would let any logged-in user walk the ID space and learn
		// which IDs are data subject requests.
		check_admin_referer( self::nonce_action( $post_id, $action ) );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Request not found.', 'faz-cookie-manager' ), '', array( 'response' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to handle data subject requests.', 'faz-cookie-manager' ), '', array( 'response' => 403 ) );
		}

		if ( 'verify_consent_id' === $action ) {
			if ( ! current_user_can( 'manage_privacy_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to verify consent ownership.', 'faz-cookie-manager' ), '', array( 'response' => 403 ) );
			}
			$proof = faz_dsar_consent_verification_token( $post_id );
			if ( '' !== $proof ) {
				update_post_meta( $post_id, '_dsar_consent_id_verified', $proof );
			}
			$this->redirect_back( $post_id, '' !== $proof ? 'consent_verified' : 'error' );
		}

		if ( 'complete' === $action || 'reopen' === $action ) {
			update_post_meta( $post_id, '_dsar_status', 'complete' === $action ? 'completed' : 'pending' );
			DSAR_Shortcode::flush_pending_count();
			$this->redirect_back( $post_id, 'complete' === $action ? 'completed' : 'reopened' );
		}

		if ( ! in_array( $action, array( 'remove_personal_data', 'export_personal_data' ), true ) ) {
			$this->redirect_back( $post_id, 'error' );
		}
		// The core privacy capability is checked on top of edit_post: holding the
		// DSAR capabilities must not be a way around manage_privacy_options.
		if ( ! current_user_can( self::required_cap( $action ) ) ) {
			wp_die( esc_html__( 'You are not allowed to create privacy requests.', 'faz-cookie-manager' ), '', array( 'response' => 403 ) );
		}

		$email   = (string) get_post_meta( $post_id, '_dsar_email', true );
		$request = wp_create_user_request( $email, $action );

		if ( is_wp_error( $request ) ) {
			$this->redirect_back( $post_id, 'duplicate_request' === $request->get_error_code() ? 'duplicate' : 'error' );
		}

		update_post_meta( $post_id, '_dsar_core_request_id', (int) $request );
		update_post_meta( $post_id, '_dsar_core_request_action', $action );
		update_post_meta( $post_id, '_dsar_status', 'forwarded' );
		DSAR_Shortcode::flush_pending_count();

		$this->redirect_back( $post_id, self::confirmation_outcome( $post_id, $action, (int) $request ) );
	}

	/**
	 * Send core's confirmation email for a created request and name the outcome.
	 *
	 * wp_send_user_request() returns a WP_Error when the mail could not be
	 * handed to the mailer, and the notice used to say "WordPress has emailed
	 * the requester" regardless. The request exists either way — core keeps it
	 * as request-pending and its tool offers "Resend email" — so the outcome
	 * is a notice of its own, `created_unsent`, that says what did and did not
	 * happen and points at the tool that can finish it.
	 *
	 * @since 1.34.1
	 * @param int    $post_id    DSAR record ID.
	 * @param string $action     Core request action name.
	 * @param int    $request_id Core request ID returned by wp_create_user_request().
	 * @return string Notice code: created_erasure, created_export or created_unsent.
	 */
	public static function confirmation_outcome( $post_id, $action, $request_id ) {
		$created = 'remove_personal_data' === $action ? 'created_erasure' : 'created_export';

		/**
		 * Whether to send core's identity-confirmation email to the data subject.
		 *
		 * Returning false creates the request without asking the address to
		 * confirm, which removes the only identity check in the flow. Only do
		 * that where identity is established some other way.
		 *
		 * @since 1.34.1
		 * @param bool   $send    Default true.
		 * @param int    $post_id DSAR record ID.
		 * @param string $action  Core request action name.
		 */
		if ( ! apply_filters( 'faz_dsar_send_core_confirmation', true, $post_id, $action ) ) {
			return $created;
		}

		$sent = wp_send_user_request( (int) $request_id );
		return is_wp_error( $sent ) ? 'created_unsent' : $created;
	}

	/**
	 * Redirect to the record's edit screen carrying an outcome, and stop.
	 *
	 * @param int    $post_id Record ID.
	 * @param string $code    Notice code.
	 */
	private function redirect_back( $post_id, $code ) {
		wp_safe_redirect(
			add_query_arg(
				self::NOTICE_ARG,
				$code,
				get_edit_post_link( $post_id, 'raw' )
			)
		);
		exit;
	}

	/**
	 * Print the outcome of the last action.
	 */
	public function render_notice() {
		if ( ! isset( $_GET[ self::NOTICE_ARG ] ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$code    = sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) );
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$email   = $post_id ? (string) get_post_meta( $post_id, '_dsar_email', true ) : '';

		$messages = array(
			'consent_verified' => array( 'success', __( 'Consent ownership verification recorded.', 'faz-cookie-manager' ), '' ),
			'created_erasure' => array(
				'success',
				__( 'Erasure request created. WordPress has emailed the requester a confirmation link; once they confirm, erase their data from Tools → Erase Personal Data.', 'faz-cookie-manager' ),
				'remove_personal_data',
			),
			'created_export'  => array(
				'success',
				__( 'Export request created. WordPress has emailed the requester a confirmation link; once they confirm, send their data from Tools → Export Personal Data.', 'faz-cookie-manager' ),
				'export_personal_data',
			),
			'created_unsent'  => array(
				'warning',
				__( 'Privacy request created, but WordPress could not send the confirmation email to the requester. Once mail delivery works, open the request in Tools → Export/Erase Personal Data and use "Resend email".', 'faz-cookie-manager' ),
				'',
			),
			'duplicate'       => array(
				'warning',
				__( 'WordPress already has an unconfirmed privacy request for this address, so a second one was not created.', 'faz-cookie-manager' ),
				'',
			),
			'completed'       => array( 'success', __( 'Request marked as completed.', 'faz-cookie-manager' ), '' ),
			'reopened'        => array( 'success', __( 'Request reopened.', 'faz-cookie-manager' ), '' ),
			'error'           => array( 'error', __( 'The request could not be processed.', 'faz-cookie-manager' ), '' ),
		);
		if ( ! isset( $messages[ $code ] ) || ! self::notice_is_true( $code, $post_id ) ) {
			return;
		}
		list( $kind, $text, $tool ) = $messages[ $code ];
		if ( 'created_unsent' === $code ) {
			// Which core tool holds the request is on the record, and
			// notice_is_true() has already checked that it names a real one.
			$tool = (string) get_post_meta( $post_id, '_dsar_core_request_action', true );
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s',
			esc_attr( $kind ),
			esc_html( $text )
		);
		if ( $tool && is_email( $email ) ) {
			printf(
				' <a href="%1$s">%2$s</a>',
				esc_url( self::core_tool_url( $tool, $email ) ),
				esc_html( self::core_tool_label( $tool ) )
			);
		}
		echo '</p></div>';
	}

	/**
	 * Replace the list-table columns with the ones that identify a request.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : __( 'Date', 'faz-cookie-manager' );
		unset( $columns['date'] );

		$columns['faz_dsar_email']  = __( 'Requester', 'faz-cookie-manager' );
		$columns['faz_dsar_type']   = __( 'Right invoked', 'faz-cookie-manager' );
		$columns['faz_dsar_status'] = __( 'Status', 'faz-cookie-manager' );
		$columns['date']            = $date;

		return $columns;
	}

	/**
	 * Fill one of the columns above.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Record ID.
	 */
	public function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'faz_dsar_email':
				$email = (string) get_post_meta( $post_id, '_dsar_email', true );
				echo $email
					? '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>'
					: '&mdash;';
				break;
			case 'faz_dsar_type':
				echo esc_html( DSAR_Shortcode::get_type_label( (string) get_post_meta( $post_id, '_dsar_type', true ) ) );
				break;
			case 'faz_dsar_status':
				echo esc_html( self::status_label( self::get_status( $post_id ) ) );
				break;
		}
	}

	/**
	 * Read a record's status, defaulting to pending for records written before
	 * the status meta existed.
	 *
	 * @param int $post_id Record ID.
	 * @return string
	 */
	public static function get_status( $post_id ) {
		$status = (string) get_post_meta( $post_id, '_dsar_status', true );
		return '' === $status ? 'pending' : $status;
	}

	/**
	 * Human-readable status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'pending'   => __( 'Awaiting action', 'faz-cookie-manager' ),
			'forwarded' => __( 'Sent to the WordPress privacy tools', 'faz-cookie-manager' ),
			'completed' => __( 'Completed', 'faz-cookie-manager' ),
			// Written by faz_privacy_eraser() when an erasure is honoured: the
			// name, address and message are gone, the dated trace of the request
			// stays. Without an entry here the screen printed the raw key.
			'erased'    => __( 'Personal data erased — record kept for accountability', 'faz-cookie-manager' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Which core request matches the right the visitor invoked.
	 *
	 * Access and portability are served by an export; erasure by a removal.
	 * Rectification, restriction and objection have no automated counterpart in
	 * WordPress — they are answered by a human — so nothing is suggested.
	 *
	 * @param string $type DSAR type key.
	 * @return string Core action name, or '' when there is no match.
	 */
	public static function suggested_action( $type ) {
		switch ( $type ) {
			case 'erasure':
				return 'remove_personal_data';
			case 'access':
			case 'portability':
				return 'export_personal_data';
			default:
				return '';
		}
	}

	/**
	 * Capability required to create a given core privacy request.
	 *
	 * @param string $action Core action name.
	 * @return string
	 */
	public static function required_cap( $action ) {
		return 'remove_personal_data' === $action
			? 'erase_others_personal_data'
			: 'export_others_personal_data';
	}

	/**
	 * URL of the core tool that finishes a given request, pre-filtered by email.
	 *
	 * @param string $action Core action name.
	 * @param string $email  Requester's address.
	 * @return string
	 */
	public static function core_tool_url( $action, $email ) {
		$page = 'remove_personal_data' === $action ? 'remove_personal_data' : 'export_personal_data';
		// Encoded here, because add_query_arg() does NOT encode the values it
		// is given: it urlencode_deep()s only the query string already on the
		// URL, merges the new pairs after that, and hands the result to
		// build_query(), which is _http_build_query( …, $urlencode = false ).
		// So a raw address went out verbatim, and an alias — jane+gdpr@…, the
		// everyday shape of an address someone gives a website — arrived at the
		// core tool with the "+" read back as a space, which matches nothing.
		return add_query_arg(
			array(
				'page' => $page,
				's'    => rawurlencode( $email ),
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Label for the core tool link.
	 *
	 * @param string $action Core action name.
	 * @return string
	 */
	public static function core_tool_label( $action ) {
		return 'remove_personal_data' === $action
			? __( 'Open Tools → Erase Personal Data', 'faz-cookie-manager' )
			: __( 'Open Tools → Export Personal Data', 'faz-cookie-manager' );
	}

	/**
	 * The nonce action for one operation on one record.
	 *
	 * Bound to the record AND to the operation. Binding it to the record alone
	 * left every action on that record interchangeable: the nonce from the
	 * "Mark as completed" link would have validated a request to create an
	 * erasure request, so anything able to alter the `faz_action` parameter in
	 * flight — a planted cookie where PHP merges them into the request, a
	 * rewritten link — could have upgraded a harmless click into one with legal
	 * consequences.
	 *
	 * @param int    $post_id Record ID.
	 * @param string $action  Action key.
	 * @return string
	 */
	public static function nonce_action( $post_id, $action ) {
		return self::ACTION . '_' . (int) $post_id . '_' . sanitize_key( $action );
	}

	/**
	 * What the submission said about the declared consent ID, for the
	 * administrator deciding whether to verify it.
	 *
	 * Recorded at submission by DSAR_Shortcode::consent_cookie_match(): whether
	 * the ID equals the consent cookie of the browser that sent the request. A
	 * match is not proof and a mismatch is not a refusal, so neither changes
	 * what the verification button does; a mismatch is shown as a warning.
	 * Records from before 1.34.2 carry no outcome and show nothing.
	 *
	 * @param int $post_id DSAR record ID.
	 * @return string Escaped HTML, or '' when there is nothing to say.
	 */
	public static function consent_cookie_note( $post_id ) {
		switch ( (string) get_post_meta( $post_id, '_dsar_consent_id_cookie', true ) ) {
			case 'match':
				return '<p>' . esc_html__( 'This consent ID matches the consent cookie of the browser that submitted the request. That supports, but does not prove, that it belongs to the requester.', 'faz-cookie-manager' ) . '</p>';
			case 'mismatch':
				return '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Warning:', 'faz-cookie-manager' ) . '</strong> ' . esc_html__( 'This consent ID does not match the consent cookie of the browser that submitted the request. It may belong to someone else or be mistyped: verify ownership with particular care before confirming it.', 'faz-cookie-manager' ) . '</p></div>';
			case 'no_cookie':
				return '<p>' . esc_html__( 'The browser that submitted the request had no consent cookie, so the consent ID could not be compared with it.', 'faz-cookie-manager' ) . '</p>';
		}
		return '';
	}

	/**
	 * Print one action as a nonce-protected link.
	 *
	 * A POST form would be the stricter choice, and it cannot be used here: a
	 * meta box renders inside the editor's own `<form id="post">`, HTML forbids
	 * nesting forms, and the parser's handling of that is actively harmful — it
	 * drops the inner opening tag and lets the inner `</form>` close the
	 * EDITOR's form, so everything after the box falls outside it. Measured, not
	 * assumed: three nested forms came back as two, with the editor's form
	 * terminated early.
	 *
	 * A nonce'd GET link is also what WordPress itself uses for these same
	 * operations — Tools > Erase Personal Data sends its "Resend email" and
	 * "Remove request" actions exactly this way, and so does every Trash and
	 * Delete row action in the admin. `rel="nofollow"` keeps crawlers off it.
	 * The nonce is bound to the record, the handler re-checks the capability,
	 * and nothing destructive happens on the far side: the request core creates
	 * must still be confirmed by the data subject before any data moves.
	 *
	 * @param int    $post_id Record ID.
	 * @param string $action  Action key.
	 * @param string $label   Link label.
	 * @param bool   $primary Whether to style it as the suggested action.
	 */
	public static function action_link( $post_id, $action, $label, $primary = false ) {
		printf(
			'<p><a class="button %1$s" rel="nofollow" href="%2$s">%3$s</a></p>',
			$primary ? 'button-primary' : '',
			esc_url( self::action_url( $post_id, $action ) ),
			esc_html( $label )
		);
	}

	/**
	 * Nonce-protected URL for one of this screen's actions.
	 *
	 * @param int    $post_id Record ID.
	 * @param string $action  Action key.
	 * @return string
	 */
	public static function action_url( $post_id, $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => self::ACTION,
					'post'       => (int) $post_id,
					'faz_action' => $action,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $post_id, $action )
		);
	}

	/**
	 * Whether the record actually shows the outcome the notice claims.
	 *
	 * The outcome travels back in the URL, which anyone can write. Without this
	 * check a crafted link handed to an administrator would show them "erasure
	 * request created" for a request nobody created — a believable lie, in the
	 * site's own admin, about the one thing on this screen that has legal
	 * consequences. The notice is only printed when the stored record agrees.
	 *
	 * @param string $code    Notice code.
	 * @param int    $post_id Record ID.
	 * @return bool
	 */
	public static function notice_is_true( $code, $post_id ) {
		if ( ! $post_id ) {
			return false;
		}
		switch ( $code ) {
			case 'consent_verified':
				return '' !== faz_dsar_verified_consent_id( $post_id, (string) get_post_meta( $post_id, '_dsar_email', true ) );
			case 'created_erasure':
			case 'created_export':
				$request_id = (int) get_post_meta( $post_id, '_dsar_core_request_id', true );
				$expected   = 'created_erasure' === $code ? 'remove_personal_data' : 'export_personal_data';
				return $request_id > 0
					&& $expected === (string) get_post_meta( $post_id, '_dsar_core_request_action', true );
			case 'created_unsent':
				// Claims a request was created, of either kind; the record must
				// carry one before the claim is shown.
				$request_id = (int) get_post_meta( $post_id, '_dsar_core_request_id', true );
				return $request_id > 0
					&& in_array(
						(string) get_post_meta( $post_id, '_dsar_core_request_action', true ),
						array( 'remove_personal_data', 'export_personal_data' ),
						true
					);

			case 'completed':
				return 'completed' === self::get_status( $post_id );
			case 'reopened':
				return 'pending' === self::get_status( $post_id );
			default:
				// duplicate / error claim nothing about the record's state.
				return true;
		}
	}
}
