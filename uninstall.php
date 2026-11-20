<?php
/**
 * Remove settings, links and assignments. Client accounts are kept, without
 * a role, so that their posts and comments keep an author.
 *
 * @package client-page-link
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cplink_settings' );
delete_post_meta_by_key( '_cplink_client' );
delete_post_meta_by_key( '_cplink_tokens' );
remove_role( 'cplink_client' );
