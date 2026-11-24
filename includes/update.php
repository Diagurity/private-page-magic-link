<?php
/**
 * Update notices from diagurity.com, through WordPress's own update screen.
 *
 * The site sends one GET for a small JSON file, at most once a day, with no
 * site URL or other data attached. The file is served by a Cloudflare Worker
 * because many shared hosts cannot reach diagurity.com directly.
 *
 * @package private-page-magic-link
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PPML_UPDATE_URL', 'https://seccheck-fetcher.odd-brook-2273.workers.dev/wp-plugins/updates/private-page-magic-link.json' );
define( 'PPML_SLUG', 'private-page-magic-link' );

/**
 * The update info, or null. Cached for a day, failures included, so a slow or
 * missing server never slows down wp-admin.
 *
 * @return array|null
 */
function ppml_update_info() {
	$cached = get_transient( 'ppml_update_info' );
	if ( false !== $cached ) {
		return is_array( $cached ) && $cached ? $cached : null;
	}

	$info     = null;
	$response = wp_remote_get(
		PPML_UPDATE_URL,
		array(
			'timeout'    => 5,
			// WordPress's default user agent carries the site URL. Send only the plugin and version.
			'user-agent' => PPML_SLUG . '/' . PPML_VERSION,
		)
	);
	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) && ! empty( $data['version'] ) && ! empty( $data['download_url'] ) ) {
			$info = $data;
		}
	}

	set_transient( 'ppml_update_info', $info ? $info : array(), DAY_IN_SECONDS );
	return $info;
}

/** The row WordPress keeps for this plugin in its update data. */
function ppml_update_item( $info ) {
	return (object) array(
		'id'           => PPML_SLUG,
		'slug'         => PPML_SLUG,
		'plugin'       => plugin_basename( PPML_FILE ),
		'new_version'  => (string) $info['version'],
		'package'      => (string) $info['download_url'],
		'url'          => (string) ( $info['homepage'] ?? 'https://diagurity.com/' ),
		'requires'     => (string) ( $info['requires'] ?? '' ),
		'requires_php' => (string) ( $info['requires_php'] ?? '' ),
		'tested'       => (string) ( $info['tested'] ?? '' ),
	);
}

/**
 * Newer version: list it as an update. Otherwise list it under no_update, so
 * the auto-update toggle appears on the Plugins screen.
 */
add_filter(
	'pre_set_site_transient_update_plugins',
	function ( $transient ) {
		if ( empty( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$info = ppml_update_info();
		if ( ! $info ) {
			return $transient;
		}
		$basename = plugin_basename( PPML_FILE );
		$current  = (string) ( $transient->checked[ $basename ] ?? PPML_VERSION );
		$item     = ppml_update_item( $info );

		if ( version_compare( (string) $info['version'], $current, '>' ) ) {
			$transient->response[ $basename ] = $item;
			unset( $transient->no_update[ $basename ] );
		} else {
			$item->new_version                 = $current;
			$transient->no_update[ $basename ] = $item;
			unset( $transient->response[ $basename ] );
		}
		return $transient;
	}
);

/** "View details": description and changelog in the user's language (Japanese or English). */
add_filter(
	'plugins_api',
	function ( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || PPML_SLUG !== (string) ( $args->slug ?? '' ) ) {
			return $result;
		}
		$info = ppml_update_info();
		if ( ! $info ) {
			return $result;
		}

		$lang     = 0 === strpos( get_user_locale(), 'ja' ) ? 'ja' : 'en';
		$sections = array();
		foreach ( array( 'description', 'changelog' ) as $key ) {
			$text = $info['sections'][ $key ][ $lang ] ?? ( $info['sections'][ $key ]['en'] ?? '' );
			if ( '' !== $text ) {
				$sections[ $key ] = (string) $text;
			}
		}

		return (object) array(
			'name'          => (string) ( $info['name'] ?? 'Private Page Magic Link' ),
			'slug'          => PPML_SLUG,
			'version'       => (string) $info['version'],
			'author'        => '<a href="https://diagurity.com/">Diagurity</a>',
			'requires'      => (string) ( $info['requires'] ?? '' ),
			'requires_php'  => (string) ( $info['requires_php'] ?? '' ),
			'tested'        => (string) ( $info['tested'] ?? '' ),
			'last_updated'  => (string) ( $info['last_updated'] ?? '' ),
			'homepage'      => (string) ( $info['homepage'] ?? 'https://diagurity.com/' ),
			'download_link' => (string) $info['download_url'],
			'sections'      => $sections,
		);
	},
	20,
	3
);

/** "Check again" and finished updates fetch the file afresh. */
function ppml_flush_update_cache() {
	delete_transient( 'ppml_update_info' );
}
add_action( 'load-update-core.php', 'ppml_flush_update_cache' );
add_action( 'upgrader_process_complete', 'ppml_flush_update_cache' );
