<?php
/**
 * Settings, access rules, links and mail.
 *
 * @package client-page-link
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Settings ────────────────────────────────

/**
 * @return array{post_types: string[], lifetime_days: int}
 */
function cplink_settings() {
	$saved = get_option( CPLINK_OPTION, array() );
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
function cplink_lifetime() {
	$days = max( 1, min( 365, (int) cplink_settings()['lifetime_days'] ) );
	return (int) apply_filters( 'cplink_lifetime', $days * DAY_IN_SECONDS );
}

function cplink_post_types() {
	return array_values( array_filter( (array) cplink_settings()['post_types'], 'post_type_exists' ) );
}

// ── Who may see a page ──────────────────────

/** The client assigned to a post, or 0. */
function cplink_client_id( $post_id ) {
	return (int) get_post_meta( $post_id, CPLINK_META_CLIENT, true );
}

function cplink_is_client_user( $user_id ) {
	$user = get_userdata( $user_id );
	return $user && in_array( CPLINK_ROLE, (array) $user->roles, true );
}

/** A post is restricted when it is an enabled type and has a client. */
function cplink_is_restricted( $post_id ) {
	return in_array( get_post_type( $post_id ), cplink_post_types(), true ) && cplink_client_id( $post_id ) > 0;
}

function cplink_user_can_view( $post_id, $user_id = null ) {
	if ( ! cplink_is_restricted( $post_id ) ) {
		return true;
	}
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	if ( ! $user_id ) {
		return false;
	}
	if ( user_can( $user_id, 'edit_post', $post_id ) ) {
		return true;
	}
	return cplink_client_id( $post_id ) === $user_id;
}

/** Published posts assigned to a client, newest first. */
function cplink_client_posts( $user_id, $limit = 5 ) {
	return get_posts(
		array(
			'post_type'        => cplink_post_types(),
			'post_status'      => 'publish',
			'posts_per_page'   => $limit,
			'fields'           => 'ids',
			'meta_key'         => CPLINK_META_CLIENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => (int) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'suppress_filters' => true,
			'cplink_internal'  => true,
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
		if ( ! cplink_is_restricted( $post_id ) ) {
			return;
		}

		// Private pages must never be stored by a page cache.
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( cplink_user_can_view( $post_id ) ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
	},
	5
);

add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( is_singular() && cplink_is_restricted( get_queried_object_id() ) ) {
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
		if ( is_admin() || $query->get( 'cplink_internal' ) || ( $query->is_main_query() && $query->is_singular() ) ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'     => CPLINK_META_CLIENT,
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
					return cplink_user_can_view( $page->ID );
				}
			)
		);
	}
);

// A single post requested by ID over REST skips pre_get_posts.
add_action(
	'init',
	function () {
		foreach ( cplink_post_types() as $type ) {
			add_filter(
				"rest_prepare_{$type}",
				function ( $response, $post ) {
					if ( cplink_user_can_view( $post->ID ) ) {
						return $response;
					}
					return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'client-page-link' ), array( 'status' => 404 ) );
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
function cplink_hash( $token ) {
	return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
}

/**
 * A new link that opens the post as its client. Empty when there is no client.
 */
function cplink_issue_link( $post_id ) {
	$client_id = cplink_client_id( $post_id );
	if ( ! $client_id || ! cplink_is_client_user( $client_id ) ) {
		return '';
	}

	$now    = time();
	$tokens = array_filter(
		(array) get_post_meta( $post_id, CPLINK_META_TOKENS, true ),
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
	$tokens[ cplink_hash( $token ) ] = array(
		'user'    => $client_id,
		'expires' => $now + cplink_lifetime(),
	);
	update_post_meta( $post_id, CPLINK_META_TOKENS, $tokens );

	return add_query_arg( CPLINK_QUERY_ARG, $token, get_permalink( $post_id ) );
}

function cplink_revoke_links( $post_id ) {
	delete_post_meta( $post_id, CPLINK_META_TOKENS );
}

/**
 * Open the link: sign the client in and drop the token from the address bar.
 */
add_action(
	'template_redirect',
	function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token is the credential.
		if ( empty( $_GET[ CPLINK_QUERY_ARG ] ) || ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! cplink_is_restricted( $post_id ) ) {
			return;
		}
		nocache_headers();

		// Someone who can already see the page keeps their own session. An
		// editor who clicks a client's link must not be signed out.
		if ( is_user_logged_in() && cplink_user_can_view( $post_id ) ) {
			wp_safe_redirect( get_permalink( $post_id ) );
			exit;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ CPLINK_QUERY_ARG ] ) );
		// phpcs:enable
		$tokens = (array) get_post_meta( $post_id, CPLINK_META_TOKENS, true );
		$entry  = $tokens[ cplink_hash( $token ) ] ?? null;

		// Invalid, expired, or issued to a previous client: fall through to the 404.
		if ( ! is_array( $entry ) || $entry['expires'] <= time() ) {
			return;
		}
		$user_id = (int) $entry['user'];
		if ( cplink_client_id( $post_id ) !== $user_id || ! cplink_is_client_user( $user_id ) ) {
			return;
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		do_action( 'cplink_signed_in', $user_id, $post_id );
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
		if ( $user instanceof WP_User && in_array( CPLINK_ROLE, (array) $user->roles, true ) ) {
			return new WP_Error( 'cplink_link_only', __( 'This account opens only from the link we sent by email.', 'client-page-link' ) );
		}
		return $user;
	},
	30
);

/** Stay signed in as long as the link lasts, not WordPress's default 14 days. */
add_filter(
	'auth_cookie_expiration',
	function ( $length, $user_id ) {
		return cplink_is_client_user( $user_id ) ? cplink_lifetime() : $length;
	},
	10,
	2
);

add_filter(
	'show_admin_bar',
	function ( $show ) {
		return cplink_is_client_user( get_current_user_id() ) ? false : $show;
	}
);

/** Clients have nothing to do in wp-admin; send them to their page. */
add_action(
	'admin_init',
	function () {
		if ( wp_doing_ajax() || ! cplink_is_client_user( get_current_user_id() ) ) {
			return;
		}
		$posts = cplink_client_posts( get_current_user_id(), 1 );
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
function cplink_send_link_mail( $post_id, $context = 'invite' ) {
	$user = get_userdata( cplink_client_id( $post_id ) );
	$link = cplink_issue_link( $post_id );
	if ( ! $user || ! $link ) {
		return false;
	}

	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$title   = wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES );
	$expires = wp_date( get_option( 'date_format' ), time() + cplink_lifetime() );

	if ( 'request' === $context ) {
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your link', 'client-page-link' ), $site );
		$intro   = __( 'Here is a new link to your page. If you did not ask for it, you can ignore this email.', 'client-page-link' );
	} else {
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your page is ready', 'client-page-link' ), $site );
		$intro   = __( 'We have prepared a page for you. There is no password: the link below opens it directly.', 'client-page-link' );
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
			sprintf( __( 'The link works until %s. After that, you can ask for a new one on our site.', 'client-page-link' ), $expires ),
			'',
			'-- ',
			$site,
			home_url( '/' ),
		)
	);

	$subject = apply_filters( 'cplink_mail_subject', $subject, $post_id, $context );
	$body    = apply_filters( 'cplink_mail_body', $body, $post_id, $context, $link );

	return wp_mail( $user->user_email, $subject, $body );
}
