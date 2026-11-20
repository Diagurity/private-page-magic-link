<?php
/**
 * [private_page_magic_link_request]: a form where a client asks for a new link.
 *
 * @package private-page-magic-link
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode(
	'private_page_magic_link_request',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['ppml_sent'] ) ) {
			return '<p class="ppml-sent">' . esc_html__( 'If the address is registered, a new link is on its way. Please check your inbox.', 'private-page-magic-link' ) . '</p>';
		}
		ob_start();
		?>
		<form class="ppml-request" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ppml_request">
			<input type="hidden" name="back" value="<?php echo esc_url( get_permalink() ); ?>">
			<p>
				<label for="ppml-email"><?php esc_html_e( 'Your email address', 'private-page-magic-link' ); ?></label><br>
				<input id="ppml-email" type="email" name="email" required autocomplete="email">
			</p>
			<p style="position:absolute;left:-9999px" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this empty', 'private-page-magic-link' ); ?> <input type="text" name="ppml_website" tabindex="-1" autocomplete="off"></label>
			</p>
			<p><button type="submit"><?php esc_html_e( 'Send me a new link', 'private-page-magic-link' ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}
);

/**
 * The answer is the same whether or not the address is a client's, so the
 * form cannot be used to find out who the clients are.
 *
 * There is no nonce: the form sits on public pages that a page cache may keep
 * for longer than a nonce lives, and the worst a forged request can do is send
 * the real client their own link. Rate limits and a honeypot cover abuse.
 */
function ppml_handle_request() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- see above.
	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
	$back = wp_validate_redirect( $back, home_url( '/' ) );
	$done = add_query_arg( 'ppml_sent', '1', $back );

	if ( ! empty( $_POST['ppml_website'] ) ) {
		wp_safe_redirect( $done );
		exit;
	}

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	// phpcs:enable
	if ( $email && is_email( $email ) && ppml_request_allowed( $email ) ) {
		$user = get_user_by( 'email', $email );
		if ( $user && ppml_can_be_viewer( $user->ID ) ) {
			foreach ( ppml_client_posts( $user->ID ) as $post_id ) {
				ppml_send_link_mail( $post_id, 'request' );
			}
		}
	}

	wp_safe_redirect( $done );
	exit;
}
add_action( 'admin_post_nopriv_ppml_request', 'ppml_handle_request' );
add_action( 'admin_post_ppml_request', 'ppml_handle_request' );

/**
 * Three requests per IP an hour, one per address every ten minutes.
 */
function ppml_request_allowed( $email ) {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( $ip ) {
		$ip_key = 'ppml_ip_' . md5( $ip );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= (int) apply_filters( 'ppml_request_ip_limit', 3 ) ) {
			return false;
		}
		set_transient( $ip_key, $count + 1, HOUR_IN_SECONDS );
	}

	$mail_key = 'ppml_mail_' . md5( strtolower( $email ) );
	if ( get_transient( $mail_key ) ) {
		return false;
	}
	set_transient( $mail_key, 1, 10 * MINUTE_IN_SECONDS );
	return true;
}
