<?php
/**
 * Remove settings, links and assignments. Client accounts are kept, without
 * a role, so that their posts and comments keep an author.
 *
 * @package private-page-magic-link
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ppml_settings' );
delete_post_meta_by_key( '_ppml_client' );
delete_post_meta_by_key( '_ppml_tokens' );
delete_post_meta_by_key( '_ppml_current' );
remove_role( 'ppml_client' );
delete_transient( 'ppml_update_info' );
