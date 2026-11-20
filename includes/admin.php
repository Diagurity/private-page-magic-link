<?php
/**
 * Settings page, the meta box on each post, and its buttons.
 *
 * @package private-page-magic-link
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Settings page ───────────────────────────

add_action(
	'admin_menu',
	function () {
		add_options_page(
			__( 'Private Page Magic Link', 'private-page-magic-link' ),
			__( 'Private Page Magic Link', 'private-page-magic-link' ),
			'manage_options',
			'private-page-magic-link',
			'ppml_render_settings'
		);
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'ppml',
			PPML_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'ppml_sanitize_settings',
			)
		);
	}
);

function ppml_sanitize_settings( $input ) {
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

function ppml_render_settings() {
	$settings = ppml_settings();
	$types    = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Private Page Magic Link', 'private-page-magic-link' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'ppml' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Post types', 'private-page-magic-link' ); ?></th>
					<td>
						<?php foreach ( $types as $type ) : ?>
							<label style="display:block">
								<input type="checkbox" name="<?php echo esc_attr( PPML_OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $settings['post_types'], true ) ); ?>>
								<?php echo esc_html( $type->labels->singular_name ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Their edit screens get a box to choose a viewer. Posts without a viewer stay public.', 'private-page-magic-link' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ppml-days"><?php esc_html_e( 'Link lifetime (days)', 'private-page-magic-link' ); ?></label></th>
					<td>
						<input id="ppml-days" type="number" min="1" max="365" name="<?php echo esc_attr( PPML_OPTION ); ?>[lifetime_days]" value="<?php echo esc_attr( $settings['lifetime_days'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Until then a link opens any number of times, and a viewer who opened one stays signed in for the same period. A short lifetime breaks links that wait in an inbox during a long review.', 'private-page-magic-link' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Letting viewers ask for a new link', 'private-page-magic-link' ); ?></h2>
		<p><?php esc_html_e( 'Put this shortcode on any public page:', 'private-page-magic-link' ); ?> <code>[private_page_magic_link_request]</code></p>
	</div>
	<?php
}

// A "Settings" link on the Plugins screen.
add_filter(
	'plugin_action_links_' . plugin_basename( PPML_FILE ),
	function ( $links ) {
		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=private-page-magic-link' ) ), esc_html__( 'Settings', 'private-page-magic-link' ) );
		return $links;
	}
);

// ── Meta box ────────────────────────────────

add_action(
	'add_meta_boxes',
	function () {
		foreach ( ppml_post_types() as $type ) {
			add_meta_box( 'ppml-viewer', __( 'Private page for a viewer', 'private-page-magic-link' ), 'ppml_render_meta_box', $type, 'normal', 'high' );
		}
	}
);

function ppml_render_meta_box( $post ) {
	wp_nonce_field( 'ppml_save_' . $post->ID, 'ppml_nonce' );
	$current = ppml_client_id( $post->ID );
	$user    = $current ? get_userdata( $current ) : null;
	$clients = get_users(
		array(
			'role'    => PPML_ROLE,
			'orderby' => 'display_name',
			'number'  => 500,
		)
	);
	?>
	<div class="ppml">
		<div class="ppml-status <?php echo $current ? 'is-private' : 'is-public'; ?>">
			<span class="dashicons <?php echo $current ? 'dashicons-lock' : 'dashicons-unlock'; ?>" aria-hidden="true"></span>
			<div>
				<strong>
					<?php
					if ( $user ) {
						/* translators: %s: viewer's name */
						printf( esc_html__( 'Private: only %s can see this page', 'private-page-magic-link' ), esc_html( $user->display_name ) );
					} else {
						esc_html_e( 'Public: anyone can see this page', 'private-page-magic-link' );
					}
					?>
				</strong>
				<p><?php esc_html_e( 'Choose a viewer and this page becomes visible only to them and to people who can edit it, such as administrators. Everyone else sees "page not found".', 'private-page-magic-link' ); ?></p>
			</div>
		</div>

		<div class="ppml-section">
			<h4 class="ppml-heading"><span class="ppml-step">1</span><?php esc_html_e( 'Viewer', 'private-page-magic-link' ); ?></h4>
			<select id="ppml-client" name="ppml_client" class="ppml-select">
				<option value="0"><?php esc_html_e( '— None (public) —', 'private-page-magic-link' ); ?></option>
				<?php foreach ( $clients as $client ) : ?>
					<option value="<?php echo esc_attr( $client->ID ); ?>" <?php selected( $current, $client->ID ); ?>>
						<?php echo esc_html( $client->display_name . ' <' . $client->user_email . '>' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( current_user_can( 'create_users' ) ) : ?>
				<details class="ppml-new" <?php echo $clients ? '' : 'open'; ?>>
					<summary><?php esc_html_e( 'Add a new viewer', 'private-page-magic-link' ); ?></summary>
					<div class="ppml-new-fields">
						<input type="email" name="ppml_new_email" aria-label="<?php esc_attr_e( 'Email', 'private-page-magic-link' ); ?>" placeholder="<?php esc_attr_e( 'Email', 'private-page-magic-link' ); ?>">
						<input type="text" name="ppml_new_name" aria-label="<?php esc_attr_e( 'Name (optional)', 'private-page-magic-link' ); ?>" placeholder="<?php esc_attr_e( 'Name (optional)', 'private-page-magic-link' ); ?>">
					</div>
					<p class="ppml-note"><?php esc_html_e( 'Registered when you update the page. No password is created for them.', 'private-page-magic-link' ); ?></p>
				</details>
			<?php endif; ?>
		</div>

		<div class="ppml-section">
			<h4 class="ppml-heading"><span class="ppml-step">2</span><?php esc_html_e( 'Link for the viewer', 'private-page-magic-link' ); ?></h4>
			<?php
			if ( ! $current ) {
				echo '<p class="ppml-empty">' . esc_html__( 'Choose a viewer and update the page. The link will appear here.', 'private-page-magic-link' ) . '</p></div></div>';
				return;
			}
			if ( 'publish' !== $post->post_status ) {
				echo '<p class="ppml-empty">' . esc_html__( 'Publish the page. The link will appear here.', 'private-page-magic-link' ) . '</p></div></div>';
				return;
			}
			$link    = ppml_current_link( $post->ID );
			$expires = ppml_current_expires( $post->ID );
			?>
			<div class="ppml-box" data-post="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ppml_ajax_' . $post->ID ) ); ?>">
				<div class="ppml-link">
					<p class="ppml-lead"><?php esc_html_e( 'Send this link to the viewer. Opening it takes them straight into this page, without a password, any number of times.', 'private-page-magic-link' ); ?></p>
					<div class="ppml-copyrow">
						<input type="text" readonly class="ppml-url" value="<?php echo esc_attr( $link ); ?>" onfocus="this.select()">
						<button type="button" class="button button-primary ppml-copy"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><?php esc_html_e( 'Copy', 'private-page-magic-link' ); ?></button>
					</div>
					<p class="ppml-meta">
						<span class="ppml-copied" hidden><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Copied', 'private-page-magic-link' ); ?></span>
						<span class="ppml-expires"><span class="dashicons dashicons-clock" aria-hidden="true"></span><span class="ppml-expires-text">
						<?php
						if ( $expires ) {
							/* translators: %s: date */
							printf( esc_html__( 'Works until %s. A new link appears here before then.', 'private-page-magic-link' ), esc_html( wp_date( get_option( 'date_format' ), $expires ) ) );
						}
						?>
						</span></span>
					</p>
				</div>
				<div class="ppml-mail">
					<button type="button" class="button ppml-do" data-do="mail"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><?php esc_html_e( 'Email this link', 'private-page-magic-link' ); ?></button>
					<span class="ppml-to">
						<?php
						/* translators: %s: email address */
						printf( esc_html__( 'To: %s', 'private-page-magic-link' ), esc_html( $user ? $user->user_email : '' ) );
						?>
					</span>
				</div>
				<p class="ppml-result" role="status"></p>
				<div class="ppml-danger">
					<button type="button" class="button-link button-link-delete ppml-do" data-do="renew" data-confirm="<?php esc_attr_e( 'Replace the link? Every link sent so far stops working and the viewer is signed out.', 'private-page-magic-link' ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Replace the link', 'private-page-magic-link' ); ?></button>
					<span class="ppml-note"><?php esc_html_e( 'Use this if a link reached the wrong person, then send the new link to the viewer.', 'private-page-magic-link' ); ?></span>
				</div>
			</div>
		</div>
	</div>
	<?php
}

add_action(
	'save_post',
	function ( $post_id ) {
		if ( ! isset( $_POST['ppml_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ppml_nonce'] ), 'ppml_save_' . $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$client = isset( $_POST['ppml_client'] ) ? absint( $_POST['ppml_client'] ) : 0;
		$email  = isset( $_POST['ppml_new_email'] ) ? sanitize_email( wp_unslash( $_POST['ppml_new_email'] ) ) : '';

		if ( $email && is_email( $email ) && current_user_can( 'create_users' ) ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				// Never turn staff into a link-only client.
				$client = ppml_is_client_user( $existing->ID ) ? $existing->ID : $client;
			} else {
				$name    = isset( $_POST['ppml_new_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ppml_new_name'] ) ) : '';
				$created = wp_insert_user(
					array(
						'user_login'   => ppml_unique_login( $email ),
						'user_email'   => $email,
						'user_pass'    => wp_generate_password( 32, true, true ),
						'display_name' => $name ? $name : $email,
						'role'         => PPML_ROLE,
					)
				);
				if ( ! is_wp_error( $created ) ) {
					$client = $created;
				}
			}
		}

		if ( $client && ! ppml_is_client_user( $client ) ) {
			return;
		}

		$previous = ppml_client_id( $post_id );
		if ( $previous === $client ) {
			return;
		}
		// Links issued to the previous client must stop working.
		ppml_revoke_links( $post_id );
		if ( $client ) {
			update_post_meta( $post_id, PPML_META_CLIENT, $client );
		} else {
			delete_post_meta( $post_id, PPML_META_CLIENT );
		}
	}
);

function ppml_unique_login( $email ) {
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
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! in_array( get_post_type(), ppml_post_types(), true ) ) {
			return;
		}
		wp_enqueue_style( 'ppml-admin', plugins_url( 'assets/admin.css', PPML_FILE ), array( 'dashicons' ), PPML_VERSION );
		wp_enqueue_script( 'ppml-admin', plugins_url( 'assets/admin.js', PPML_FILE ), array(), PPML_VERSION, true );
		wp_localize_script(
			'ppml-admin',
			'ppmlAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'failed'  => __( 'Something went wrong. Reload the page and try again.', 'private-page-magic-link' ),
			)
		);
	}
);

/**
 * One endpoint for the three buttons: link, mail, revoke.
 */
add_action(
	'wp_ajax_ppml',
	function () {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( 'ppml_ajax_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Sorry, you are not allowed to do that.', 'private-page-magic-link' ), 403 );
		}

		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( 'mail' === $do ) {
			ppml_send_link_mail( $post_id, 'invite' )
				? wp_send_json_success( array( 'message' => __( 'Sent. If it does not arrive, ask the viewer to check their spam folder.', 'private-page-magic-link' ) ) )
				: wp_send_json_error( __( 'The email could not be sent. Check that this site can send email, or copy the link instead.', 'private-page-magic-link' ) );
		}
		if ( 'renew' === $do ) {
			ppml_revoke_links( $post_id );
			// A leaked link may already have been opened: end the client's sessions too.
			$client = ppml_client_id( $post_id );
			if ( $client && ppml_is_client_user( $client ) ) {
				WP_Session_Tokens::get_instance( $client )->destroy_all();
			}
			$link = ppml_current_link( $post_id );
			$link
				? wp_send_json_success(
					array(
						'link'    => $link,
						/* translators: %s: date */
						'expires' => sprintf( __( 'Works until %s. A new link appears here before then.', 'private-page-magic-link' ), wp_date( get_option( 'date_format' ), ppml_current_expires( $post_id ) ) ),
						'message' => __( 'The link was replaced and the viewer was signed out. Send them the new link.', 'private-page-magic-link' ),
					)
				)
				: wp_send_json_error( __( 'No link was made. Check that the viewer is saved.', 'private-page-magic-link' ) );
		}
		wp_send_json_error( '', 400 );
	}
);
