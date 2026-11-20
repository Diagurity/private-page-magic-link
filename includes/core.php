<?php
/**
 * Settings, access rules, links and mail.
 *
 * @package private-page-magic-link
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Settings ────────────────────────────────

/**
 * @return array{post_types: string[], lifetime_days: int}
 */
function ppml_settings() {
	$saved = get_option( PPML_OPTION, array() );
	return wp_parse_args(
		is_array( $saved ) ? $saved : array(),
		array(
			'post_types'    => array( 'page' ),
			'lifetime_days' => 30,
		)
	);
}

/**
 * How long a link, and the sign-in it creates, stays valid.
 *
 * Links are not single-use and last for weeks on purpose. Clients open the
 * same email on their phone and on their computer, and a review on their side
 * can take a month. A link that dies after one click or a few days ends in an
 * unexplained "not found" page.
 */
function ppml_lifetime() {
	$days = max( 1, min( 365, (int) ppml_settings()['lifetime_days'] ) );
	return (int) apply_filters( 'ppml_lifetime', $days * DAY_IN_SECONDS );
}

function ppml_post_types() {
	return array_values( array_filter( (array) ppml_settings()['post_types'], 'post_type_exists' ) );
}

// ── Who may see a page ──────────────────────

/** The client assigned to a post, or 0. */
function ppml_client_id( $post_id ) {
	return (int) get_post_meta( $post_id, PPML_META_CLIENT, true );
}

function ppml_is_client_user( $user_id ) {
	$user = get_userdata( $user_id );
	return $user && in_array( PPML_ROLE, (array) $user->roles, true );
}

/** A post is restricted when it is an enabled type and has a client. */
function ppml_is_restricted( $post_id ) {
	return in_array( get_post_type( $post_id ), ppml_post_types(), true ) && ppml_client_id( $post_id ) > 0;
}

function ppml_user_can_view( $post_id, $user_id = null ) {
	if ( ! ppml_is_restricted( $post_id ) ) {
		return true;
	}
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	if ( ! $user_id ) {
		return false;
	}
	if ( user_can( $user_id, 'edit_post', $post_id ) ) {
		return true;
	}
	return ppml_client_id( $post_id ) === $user_id;
}

/** Published posts assigned to a client, newest first. */
function ppml_client_posts( $user_id, $limit = 5 ) {
	return get_posts(
		array(
			'post_type'        => ppml_post_types(),
			'post_status'      => 'publish',
			'posts_per_page'   => $limit,
			'fields'           => 'ids',
			'meta_key'         => PPML_META_CLIENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => (int) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'suppress_filters' => true,
			'ppml_internal'  => true,
		)
	);
}

/**
 * Anyone else gets the theme's 404 page, the same as for a page that does
 * not exist. A "please log in" page would confirm that the page is there.
 */
add_action(
	'template_redirect',
	function () {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! ppml_is_restricted( $post_id ) ) {
			return;
		}

		// Private pages must never be stored by a page cache.
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ppml_user_can_view( $post_id ) ) {
			return;
		}
		// set_404() alone keeps the post in the query. A theme whose 404
		// template runs the loop (or falls back to index.php) would print it.
		global $wp_query, $post;
		$wp_query->set_404();
		$wp_query->posts      = array();
		$wp_query->post       = null;
		$wp_query->post_count = 0;
		$wp_query->found_posts = 0;
		$wp_query->rewind_posts();
		$post = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- removing the private post.
		status_header( 404 );
	},
	5
);

add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( is_singular() && ppml_is_restricted( get_queried_object_id() ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
);

/**
 * Keep restricted posts out of lists: archives, search, feeds, REST
 * collections and sitemaps. A single post is still found, and the rule above
 * decides who sees it.
 */
add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( is_admin() || $query->get( 'ppml_internal' ) || ( $query->is_main_query() && $query->is_singular() ) ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'     => PPML_META_CLIENT,
			'compare' => 'NOT EXISTS',
		);
		$query->set( 'meta_query', $meta );
	}
);

// Page lists and menus built with get_pages() do not run WP_Query.
add_filter(
	'get_pages',
	function ( $pages ) {
		if ( is_admin() ) {
			return $pages;
		}
		return array_values(
			array_filter(
				$pages,
				function ( $page ) {
					return ppml_user_can_view( $page->ID );
				}
			)
		);
	}
);

// A single post requested by ID over REST skips pre_get_posts.
add_action(
	'init',
	function () {
		foreach ( ppml_post_types() as $type ) {
			add_filter(
				"rest_prepare_{$type}",
				function ( $response, $post ) {
					if ( ppml_user_can_view( $post->ID ) ) {
						return $response;
					}
					return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'private-page-magic-link' ), array( 'status' => 404 ) );
				},
				10,
				2
			);
		}
	},
	20
);

// ── Links ───────────────────────────────────

/**
 * Only a keyed hash of the token is stored, so a leaked database does not
 * hand out working links.
 */
function ppml_hash( $token ) {
	return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
}

/**
 * A new link that opens the post as its client. Empty when there is no client.
 */
function ppml_issue_link( $post_id ) {
	$client_id = ppml_client_id( $post_id );
	if ( ! $client_id || ! ppml_is_client_user( $client_id ) ) {
		return '';
	}

	$now    = time();
	$tokens = array_filter(
		(array) get_post_meta( $post_id, PPML_META_TOKENS, true ),
		function ( $t ) use ( $now ) {
			return is_array( $t ) && $t['expires'] > $now;
		}
	);
	// Keep the newest 20. Old invitations stay valid until then.
	uasort(
		$tokens,
		function ( $a, $b ) {
			return $b['expires'] <=> $a['expires'];
		}
	);
	$tokens = array_slice( $tokens, 0, 19, true );

	$token                         = wp_generate_password( 40, false, false );
	$tokens[ ppml_hash( $token ) ] = array(
		'user'    => $client_id,
		'expires' => $now + ppml_lifetime(),
	);
	update_post_meta( $post_id, PPML_META_TOKENS, $tokens );

	// Keep the newest link readable for editors, so the edit screen can always show it.
	$sealed = ppml_seal( $token );
	if ( $sealed ) {
		update_post_meta(
			$post_id,
			PPML_META_CURRENT,
			array(
				'token'   => $sealed,
				'user'    => $client_id,
				'expires' => $now + ppml_lifetime(),
			)
		);
	}

	return add_query_arg( PPML_QUERY_ARG, $token, get_permalink( $post_id ) );
}

/**
 * The link to hand to the client: the current one while it has at least half
 * of its lifetime left, otherwise a new one. Empty when there is no client.
 */
function ppml_current_link( $post_id ) {
	$current = get_post_meta( $post_id, PPML_META_CURRENT, true );
	$client  = ppml_client_id( $post_id );
	if ( is_array( $current ) && (int) $current['user'] === $client && $current['expires'] - time() >= ppml_lifetime() / 2 ) {
		$token  = ppml_unseal( $current['token'] );
		$tokens = (array) get_post_meta( $post_id, PPML_META_TOKENS, true );
		if ( $token && isset( $tokens[ ppml_hash( $token ) ] ) ) {
			return add_query_arg( PPML_QUERY_ARG, $token, get_permalink( $post_id ) );
		}
	}
	return ppml_issue_link( $post_id );
}

/** When the current link (if any) expires, as a timestamp, or 0. */
function ppml_current_expires( $post_id ) {
	$current = get_post_meta( $post_id, PPML_META_CURRENT, true );
	return is_array( $current ) ? (int) $current['expires'] : 0;
}

/**
 * The newest token is stored encrypted with the site's secret keys (in
 * wp-config.php), not in plain text. A copy of the database alone does not
 * contain a working link. Returns '' when OpenSSL is unavailable; links
 * then work but cannot be shown again.
 */
function ppml_seal( $token ) {
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return '';
	}
	$key    = hash( 'sha256', wp_salt( 'secure_auth' ), true );
	$iv     = random_bytes( 12 );
	$tag    = '';
	$cipher = openssl_encrypt( $token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
	return false === $cipher ? '' : base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

function ppml_unseal( $sealed ) {
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}
	$raw = base64_decode( (string) $sealed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	if ( false === $raw || strlen( $raw ) < 29 ) {
		return '';
	}
	$key   = hash( 'sha256', wp_salt( 'secure_auth' ), true );
	$token = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
	return false === $token ? '' : $token;
}

function ppml_revoke_links( $post_id ) {
	delete_post_meta( $post_id, PPML_META_TOKENS );
	delete_post_meta( $post_id, PPML_META_CURRENT );
}

/**
 * Open the link: sign the client in and drop the token from the address bar.
 */
add_action(
	'template_redirect',
	function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token is the credential.
		if ( empty( $_GET[ PPML_QUERY_ARG ] ) || ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! ppml_is_restricted( $post_id ) ) {
			return;
		}
		nocache_headers();

		// Someone who can already see the page keeps their own session. An
		// editor who clicks a client's link must not be signed out.
		if ( is_user_logged_in() && ppml_user_can_view( $post_id ) ) {
			wp_safe_redirect( get_permalink( $post_id ) );
			exit;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ PPML_QUERY_ARG ] ) );
		// phpcs:enable
		$tokens = (array) get_post_meta( $post_id, PPML_META_TOKENS, true );
		$entry  = $tokens[ ppml_hash( $token ) ] ?? null;

		// Invalid, expired, or issued to a previous client: fall through to the 404.
		if ( ! is_array( $entry ) || $entry['expires'] <= time() ) {
			return;
		}
		$user_id = (int) $entry['user'];
		if ( ppml_client_id( $post_id ) !== $user_id || ! ppml_is_client_user( $user_id ) ) {
			return;
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		do_action( 'ppml_signed_in', $user_id, $post_id );
		wp_safe_redirect( get_permalink( $post_id ) );
		exit;
	},
	1
);

// ── Rules for client accounts ───────────────

/** Clients have no password to guess. */
add_filter(
	'authenticate',
	function ( $user ) {
		if ( $user instanceof WP_User && in_array( PPML_ROLE, (array) $user->roles, true ) ) {
			return new WP_Error( 'ppml_link_only', __( 'This account opens only from the link we sent by email.', 'private-page-magic-link' ) );
		}
		return $user;
	},
	30
);

/** Stay signed in as long as the link lasts, not WordPress's default 14 days. */
add_filter(
	'auth_cookie_expiration',
	function ( $length, $user_id ) {
		return ppml_is_client_user( $user_id ) ? ppml_lifetime() : $length;
	},
	10,
	2
);

add_filter(
	'show_admin_bar',
	function ( $show ) {
		return ppml_is_client_user( get_current_user_id() ) ? false : $show;
	}
);

/** Clients have nothing to do in wp-admin; send them to their page. */
add_action(
	'admin_init',
	function () {
		if ( wp_doing_ajax() || ! ppml_is_client_user( get_current_user_id() ) ) {
			return;
		}
		$posts = ppml_client_posts( get_current_user_id(), 1 );
		wp_safe_redirect( $posts ? get_permalink( $posts[0] ) : home_url( '/' ) );
		exit;
	}
);

// ── Mail ────────────────────────────────────

/**
 * Send the client a fresh link to the post.
 *
 * @param string $context 'invite' (sent by an editor) or 'request' (asked for by the client).
 */
function ppml_send_link_mail( $post_id, $context = 'invite' ) {
	$user = get_userdata( ppml_client_id( $post_id ) );
	$link = ppml_current_link( $post_id );
	if ( ! $user || ! $link ) {
		return false;
	}

	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$title   = wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES );
	$expires = wp_date( get_option( 'date_format' ), ppml_current_expires( $post_id ) );

	if ( 'request' === $context ) {
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your link', 'private-page-magic-link' ), $site );
		$intro   = __( 'Here is a new link to your page. If you did not ask for it, you can ignore this email.', 'private-page-magic-link' );
	} else {
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your page is ready', 'private-page-magic-link' ), $site );
		$intro   = __( 'We have prepared a page for you. There is no password: the link below opens it directly.', 'private-page-magic-link' );
	}

	$body = implode(
		"\n",
		array(
			$intro,
			'',
			$title,
			$link,
			'',
			/* translators: %s: date */
			sprintf( __( 'The link works until %s. After that, you can ask for a new one on our site.', 'private-page-magic-link' ), $expires ),
			'',
			'-- ',
			$site,
			home_url( '/' ),
		)
	);

	$subject = apply_filters( 'ppml_mail_subject', $subject, $post_id, $context );
	$body    = apply_filters( 'ppml_mail_body', $body, $post_id, $context, $link );

	return wp_mail( $user->user_email, $subject, $body );
}
