<?php
/**
 * Plugin Name:       Client Page Link
 * Description:       Give each client a private page they open from a link in their email. No passwords, no login screen, and the link keeps working for weeks.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Hanashiro Works
 * Author URI:        https://hanashiroworks.jp/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       client-page-link
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CPLINK_VERSION', '0.1.0' );
define( 'CPLINK_FILE', __FILE__ );
define( 'CPLINK_ROLE', 'cplink_client' );
define( 'CPLINK_META_CLIENT', '_cplink_client' );
define( 'CPLINK_META_TOKENS', '_cplink_tokens' );
define( 'CPLINK_OPTION', 'cplink_settings' );
define( 'CPLINK_QUERY_ARG', 'cplink' );

require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/request.php';

/**
 * Bundled translations. Ones from translate.wordpress.org, when present,
 * are loaded first and win.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'client-page-link', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	},
	0
);

// The role name is saved in the language active at activation. Show it in the current one.
add_action(
	'init',
	function () {
		$roles = wp_roles();
		if ( isset( $roles->role_names[ CPLINK_ROLE ] ) ) {
			$roles->role_names[ CPLINK_ROLE ] = __( 'Client (link only)', 'client-page-link' );
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
function cplink_activate() {
	add_role( CPLINK_ROLE, __( 'Client (link only)', 'client-page-link' ), array( 'read' => true ) );
}
register_activation_hook( __FILE__, 'cplink_activate' );
