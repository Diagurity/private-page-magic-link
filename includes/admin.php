<?php
/**
 * Settings page, the meta box on each post, and its buttons.
 *
 * @package client-page-link
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Settings page ───────────────────────────

add_action(
	'admin_menu',
	function () {
		add_options_page(
			__( 'Client Page Link', 'client-page-link' ),
			__( 'Client Page Link', 'client-page-link' ),
			'manage_options',
			'client-page-link',
			'cplink_render_settings'
		);
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'cplink',
			CPLINK_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'cplink_sanitize_settings',
			)
		);
	}
);

function cplink_sanitize_settings( $input ) {
	$types = array();
	foreach ( (array) ( $input['post_types'] ?? array() ) as $type ) {
		$type = sanitize_key( $type );
		if ( post_type_exists( $type ) ) {
			$types[] = $type;
		}
	}
	return array(
		'post_types'    => $types,
		'lifetime_days' => max( 1, min( 365, absint( $input['lifetime_days'] ?? 30 ) ) ),
	);
}

function cplink_render_settings() {
	$settings = cplink_settings();
	$types    = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Client Page Link', 'client-page-link' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'cplink' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Post types', 'client-page-link' ); ?></th>
					<td>
						<?php foreach ( $types as $type ) : ?>
							<label style="display:block">
								<input type="checkbox" name="<?php echo esc_attr( CPLINK_OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $settings['post_types'], true ) ); ?>>
								<?php echo esc_html( $type->labels->singular_name ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'A box to assign a client appears on these. Posts without a client stay public.', 'client-page-link' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cplink-days"><?php esc_html_e( 'Link lifetime (days)', 'client-page-link' ); ?></label></th>
					<td>
						<input id="cplink-days" type="number" min="1" max="365" name="<?php echo esc_attr( CPLINK_OPTION ); ?>[lifetime_days]" value="<?php echo esc_attr( $settings['lifetime_days'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Links work any number of times until then, and a client who opened one stays signed in for the same period. Short lifetimes break links that sit in an inbox during a long review.', 'client-page-link' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Letting clients ask for a new link', 'client-page-link' ); ?></h2>
		<p><?php esc_html_e( 'Put this shortcode on any public page:', 'client-page-link' ); ?> <code>[client_page_link_request]</code></p>
	</div>
	<?php
}

// ── Meta box ────────────────────────────────

add_action(
	'add_meta_boxes',
	function () {
		foreach ( cplink_post_types() as $type ) {
			add_meta_box( 'cplink', __( 'Client', 'client-page-link' ), 'cplink_render_meta_box', $type, 'side' );
		}
	}
);

function cplink_render_meta_box( $post ) {
	wp_nonce_field( 'cplink_save_' . $post->ID, 'cplink_nonce' );
	$current = cplink_client_id( $post->ID );
	$clients = get_users(
		array(
			'role'    => CPLINK_ROLE,
			'orderby' => 'display_name',
			'number'  => 500,
		)
	);
	?>
	<p>
		<label for="cplink-client"><?php esc_html_e( 'Client', 'client-page-link' ); ?></label>
		<select id="cplink-client" name="cplink_client" style="width:100%">
			<option value="0"><?php esc_html_e( '— None (public) —', 'client-page-link' ); ?></option>
			<?php foreach ( $clients as $client ) : ?>
				<option value="<?php echo esc_attr( $client->ID ); ?>" <?php selected( $current, $client->ID ); ?>>
					<?php echo esc_html( $client->display_name . ' <' . $client->user_email . '>' ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>
	<?php if ( current_user_can( 'create_users' ) ) : ?>
		<p>
			<label for="cplink-new-email"><?php esc_html_e( 'Or add a new client', 'client-page-link' ); ?></label>
			<input id="cplink-new-email" type="email" name="cplink_new_email" placeholder="<?php esc_attr_e( 'Email', 'client-page-link' ); ?>" style="width:100%">
			<input type="text" name="cplink_new_name" placeholder="<?php esc_attr_e( 'Name (optional)', 'client-page-link' ); ?>" style="width:100%;margin-top:4px">
		</p>
	<?php endif; ?>
	<?php
	if ( ! $current ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		echo '<p class="description">' . esc_html__( 'Publish the post to send a link.', 'client-page-link' ) . '</p>';
		return;
	}
	foreach ( array(
		'invite' => __( 'Send link by email', 'client-page-link' ),
		'show'   => __( 'Show a link to copy', 'client-page-link' ),
		'revoke' => __( 'Revoke all links', 'client-page-link' ),
	) as $do => $label ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'cplink_' . $do,
					'post'   => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'cplink_' . $do . '_' . $post->ID
		);
		printf( '<a class="button" style="margin:0 4px 4px 0" href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}
	echo '<p class="description">' . esc_html__( 'Revoking also signs the client out everywhere. Send a new link afterwards.', 'client-page-link' ) . '</p>';
}

add_action(
	'save_post',
	function ( $post_id ) {
		if ( ! isset( $_POST['cplink_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['cplink_nonce'] ), 'cplink_save_' . $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$client = isset( $_POST['cplink_client'] ) ? absint( $_POST['cplink_client'] ) : 0;
		$email  = isset( $_POST['cplink_new_email'] ) ? sanitize_email( wp_unslash( $_POST['cplink_new_email'] ) ) : '';

		if ( $email && is_email( $email ) && current_user_can( 'create_users' ) ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				// Never turn staff into a link-only client.
				$client = cplink_is_client_user( $existing->ID ) ? $existing->ID : $client;
			} else {
				$name    = isset( $_POST['cplink_new_name'] ) ? sanitize_text_field( wp_unslash( $_POST['cplink_new_name'] ) ) : '';
				$created = wp_insert_user(
					array(
						'user_login'   => cplink_unique_login( $email ),
						'user_email'   => $email,
						'user_pass'    => wp_generate_password( 32, true, true ),
						'display_name' => $name ? $name : $email,
						'role'         => CPLINK_ROLE,
					)
				);
				if ( ! is_wp_error( $created ) ) {
					$client = $created;
				}
			}
		}

		if ( $client && ! cplink_is_client_user( $client ) ) {
			return;
		}

		$previous = cplink_client_id( $post_id );
		if ( $previous === $client ) {
			return;
		}
		// Links issued to the previous client must stop working.
		cplink_revoke_links( $post_id );
		if ( $client ) {
			update_post_meta( $post_id, CPLINK_META_CLIENT, $client );
		} else {
			delete_post_meta( $post_id, CPLINK_META_CLIENT );
		}
	}
);

function cplink_unique_login( $email ) {
	$base  = sanitize_user( strstr( $email, '@', true ), true );
	$base  = $base ? $base : 'client';
	$login = $base;
	$i     = 2;
	while ( username_exists( $login ) ) {
		$login = $base . $i++;
	}
	return $login;
}

// ── Buttons ─────────────────────────────────

/**
 * @return int The post the button belongs to, after checking nonce and capability.
 */
function cplink_admin_action_post( $action ) {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	check_admin_referer( 'cplink_' . $action . '_' . $post_id );
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'client-page-link' ), '', array( 'response' => 403 ) );
	}
	return $post_id;
}

function cplink_back( $post_id, $notice ) {
	wp_safe_redirect( add_query_arg( 'cplink_notice', $notice, get_edit_post_link( $post_id, 'raw' ) ) );
	exit;
}

add_action(
	'admin_post_cplink_invite',
	function () {
		$post_id = cplink_admin_action_post( 'invite' );
		cplink_back( $post_id, cplink_send_link_mail( $post_id, 'invite' ) ? 'sent' : 'failed' );
	}
);

/**
 * The link is not put in the redirect URL, where it would stay in the
 * browser history. It waits for five minutes where only this editor reads it.
 */
add_action(
	'admin_post_cplink_show',
	function () {
		$post_id = cplink_admin_action_post( 'show' );
		$link    = cplink_issue_link( $post_id );
		if ( $link ) {
			set_transient( 'cplink_shown_' . get_current_user_id() . '_' . $post_id, $link, 5 * MINUTE_IN_SECONDS );
		}
		cplink_back( $post_id, $link ? 'shown' : 'failed' );
	}
);

add_action(
	'admin_post_cplink_revoke',
	function () {
		$post_id = cplink_admin_action_post( 'revoke' );
		cplink_revoke_links( $post_id );
		// A leaked link may already have been opened: end the client's sessions too.
		$client = cplink_client_id( $post_id );
		if ( $client && cplink_is_client_user( $client ) ) {
			WP_Session_Tokens::get_instance( $client )->destroy_all();
		}
		cplink_back( $post_id, 'revoked' );
	}
);

add_action(
	'admin_notices',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['cplink_notice'] ) ? sanitize_key( $_GET['cplink_notice'] ) : '';
		$post   = get_post();
		if ( ! $notice || ! $post ) {
			return;
		}
		$messages = array(
			'sent'    => array( 'success', __( 'The link was sent to the client.', 'client-page-link' ) ),
			'revoked' => array( 'success', __( 'All links to this page were revoked and the client was signed out.', 'client-page-link' ) ),
			'failed'  => array( 'error', __( 'No link was created. Check that a client is assigned and that this site can send email.', 'client-page-link' ) ),
		);
		if ( 'shown' === $notice ) {
			$key  = 'cplink_shown_' . get_current_user_id() . '_' . $post->ID;
			$link = get_transient( $key );
			delete_transient( $key );
			if ( $link ) {
				printf(
					'<div class="notice notice-info"><p>%s</p><p><input type="text" readonly class="large-text code" onfocus="this.select()" value="%s"></p></div>',
					esc_html__( 'Copy this link into your own email. It is shown only once.', 'client-page-link' ),
					esc_attr( $link )
				);
			}
			return;
		}
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}
);
