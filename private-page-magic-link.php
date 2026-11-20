<?php
/**
 * Plugin Name:       Private Page Magic Link
 * Description:       Make a page that only one person can see, and send them a link that opens it. No passwords, no login screen, and the link keeps working for weeks.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Hanashiro Works
 * Author URI:        https://hanashiroworks.jp/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       private-page-magic-link
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PPML_VERSION', '0.1.0' );
define( 'PPML_FILE', __FILE__ );
define( 'PPML_ROLE', 'ppml_client' );
define( 'PPML_META_CLIENT', '_ppml_client' );
define( 'PPML_META_TOKENS', '_ppml_tokens' );
define( 'PPML_META_CURRENT', '_ppml_current' );
define( 'PPML_OPTION', 'ppml_settings' );
define( 'PPML_QUERY_ARG', 'ppml' );

require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/request.php';

/**
 * Bundled translations. Ones from translate.wordpress.org, when present,
 * are loaded first and win.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'private-page-magic-link', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	},
	0
);

// The role name is saved in the language active at activation. Show it in the current one.
add_action(
	'init',
	function () {
		$roles = wp_roles();
		if ( isset( $roles->role_names[ PPML_ROLE ] ) ) {
			$roles->role_names[ PPML_ROLE ] = __( 'Viewer (link only)', 'private-page-magic-link' );
		}
	}
);

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

/**
 * The client role can only read. It exists so that the plugin knows which
 * accounts must never sign in with a password.
 */
function ppml_activate() {
	add_role( PPML_ROLE, __( 'Viewer (link only)', 'private-page-magic-link' ), array( 'read' => true ) );
}
register_activation_hook( __FILE__, 'ppml_activate' );
