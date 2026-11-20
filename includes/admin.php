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
			add_meta_box( 'cplink', __( 'Private page for a client', 'client-page-link' ), 'cplink_render_meta_box', $type, 'side' );
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
	<p class="description" style="margin-top:0"><?php esc_html_e( 'Choose a client and this page becomes visible only to them (and to editors). Everyone else sees "page not found".', 'client-page-link' ); ?></p>
	<p>
		<label for="cplink-client"><strong><?php esc_html_e( 'Client', 'client-page-link' ); ?></strong></label>
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
		<details <?php echo $clients ? '' : 'open'; ?>>
			<summary><?php esc_html_e( 'Add a new client', 'client-page-link' ); ?></summary>
			<p>
				<input type="email" name="cplink_new_email" aria-label="<?php esc_attr_e( 'Email', 'client-page-link' ); ?>" placeholder="<?php esc_attr_e( 'Email', 'client-page-link' ); ?>" style="width:100%">
				<input type="text" name="cplink_new_name" aria-label="<?php esc_attr_e( 'Name (optional)', 'client-page-link' ); ?>" placeholder="<?php esc_attr_e( 'Name (optional)', 'client-page-link' ); ?>" style="width:100%;margin-top:4px">
				<span class="description"><?php esc_html_e( 'Saved when you update the page. No password is created for them.', 'client-page-link' ); ?></span>
			</p>
		</details>
	<?php endif; ?>
	<hr>
	<p><strong><?php esc_html_e( 'Link for the client', 'client-page-link' ); ?></strong></p>
	<?php
	if ( ! $current ) {
		echo '<p class="description">' . esc_html__( 'Choose a client and update the page. Then you can make a link here.', 'client-page-link' ) . '</p>';
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		echo '<p class="description">' . esc_html__( 'Publish the page. Then you can make a link here.', 'client-page-link' ) . '</p>';
		return;
	}
	$user = get_userdata( $current );
	$link    = cplink_current_link( $post->ID );
	$expires = cplink_current_expires( $post->ID );
	?>
	<div class="cplink-box" data-post="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'cplink_ajax_' . $post->ID ) ); ?>">
		<p class="description"><?php esc_html_e( 'Send this link to the client. Opening it takes them straight into this page, without a password, any number of times.', 'client-page-link' ); ?></p>
		<div class="cplink-link">
			<input type="text" readonly class="cplink-url" value="<?php echo esc_attr( $link ); ?>" style="width:100%;font-size:12px" onfocus="this.select()">
			<p style="margin:4px 0 0">
				<button type="button" class="button cplink-copy"><?php esc_html_e( 'Copy', 'client-page-link' ); ?></button>
				<span class="cplink-copied" hidden><?php esc_html_e( 'Copied', 'client-page-link' ); ?></span>
			</p>
			<p class="description cplink-expires">
				<?php
				if ( $expires ) {
					/* translators: %s: date */
					printf( esc_html__( 'Works until %s. A new link appears here before then.', 'client-page-link' ), esc_html( wp_date( get_option( 'date_format' ), $expires ) ) );
				}
				?>
			</p>
		</div>
		<p>
			<button type="button" class="button cplink-do" data-do="mail">
				<?php
				/* translators: %s: email address */
				printf( esc_html__( 'Email this link to %s', 'client-page-link' ), esc_html( $user ? $user->user_email : '' ) );
				?>
			</button>
		</p>
		<p class="cplink-result" role="status"></p>
		<p style="margin-top:16px">
			<button type="button" class="button-link button-link-delete cplink-do" data-do="renew" data-confirm="<?php esc_attr_e( 'Replace the link? Every link sent so far stops working and the client is signed out.', 'client-page-link' ); ?>"><?php esc_html_e( 'Replace the link', 'client-page-link' ); ?></button>
			<br><span class="description"><?php esc_html_e( 'For a link that reached the wrong person. Send the new link to the client afterwards.', 'client-page-link' ); ?></span>
		</p>
	</div>
	<?php
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

// ── Buttons (no page reload) ────────────────

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! in_array( get_post_type(), cplink_post_types(), true ) ) {
			return;
		}
		wp_enqueue_script( 'cplink-admin', plugins_url( 'assets/admin.js', CPLINK_FILE ), array(), CPLINK_VERSION, true );
		wp_localize_script(
			'cplink-admin',
			'cplinkAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'failed'  => __( 'Something went wrong. Reload the page and try again.', 'client-page-link' ),
			)
		);
	}
);

/**
 * One endpoint for the three buttons: link, mail, revoke.
 */
add_action(
	'wp_ajax_cplink',
	function () {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( 'cplink_ajax_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Sorry, you are not allowed to do that.', 'client-page-link' ), 403 );
		}

		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( 'mail' === $do ) {
			cplink_send_link_mail( $post_id, 'invite' )
				? wp_send_json_success( array( 'message' => __( 'Sent. Ask the client to check their spam folder if it does not arrive.', 'client-page-link' ) ) )
				: wp_send_json_error( __( 'The email could not be sent. Check that this site can send email, or copy the link instead.', 'client-page-link' ) );
		}
		if ( 'renew' === $do ) {
			cplink_revoke_links( $post_id );
			// A leaked link may already have been opened: end the client's sessions too.
			$client = cplink_client_id( $post_id );
			if ( $client && cplink_is_client_user( $client ) ) {
				WP_Session_Tokens::get_instance( $client )->destroy_all();
			}
			$link = cplink_current_link( $post_id );
			$link
				? wp_send_json_success(
					array(
						'link'    => $link,
						/* translators: %s: date */
						'expires' => sprintf( __( 'Works until %s. A new link appears here before then.', 'client-page-link' ), wp_date( get_option( 'date_format' ), cplink_current_expires( $post_id ) ) ),
						'message' => __( 'The link was replaced and the client was signed out. Send them the new link.', 'client-page-link' ),
					)
				)
				: wp_send_json_error( __( 'No link was made. Check that the client is saved.', 'client-page-link' ) );
		}
		wp_send_json_error( '', 400 );
	}
);
