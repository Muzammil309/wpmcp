<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Updater {

	const TRANSIENT = 'wpmcp_update_check';

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
	}

	private static function repo(): string {
		return (string) apply_filters( 'wpmcp_github_repo', get_option( 'wpmcp_github_repo', 'yourname/wpmcp' ) );
	}

	public static function latest_release() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_safe_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::TRANSIENT, array(), 30 * MINUTE_IN_SECONDS );
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) || empty( $body['download_url'] ) && empty( $body['assets'][0]['browser_download_url'] ) ) {
			set_transient( self::TRANSIENT, array(), 30 * MINUTE_IN_SECONDS );
			return array();
		}
		$asset_url = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['browser_download_url'] ) && str_ends_with( strtolower( $asset['name'] ), '.zip' ) ) {
				$asset_url = $asset['browser_download_url'];
				break;
			}
		}
		$release = array(
			'version' => ltrim( (string) $body['tag_name'], 'v' ),
			'url'     => '' !== $asset_url ? $asset_url : (string) $body['download_url'],
			'changelog' => wp_kses_post( (string) ( $body['body'] ?? '' ) ),
		);
		set_transient( self::TRANSIENT, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	public static function inject_update( $transient ) {
		if ( empty( $transient->checked ) || ! isset( $transient->response ) ) {
			return $transient;
		}
		$release = self::latest_release();
		if ( empty( $release['version'] ) ) {
			return $transient;
		}
		if ( version_compare( WPMCP_VERSION, $release['version'], '>=' ) ) {
			unset( $transient->response[ plugin_basename( WPMCP_FILE ) ] );
			return $transient;
		}
		$transient->response[ plugin_basename( WPMCP_FILE ) ] = (object) array(
			'slug'            => dirname( plugin_basename( WPMCP_FILE ) ),
			'plugin'          => plugin_basename( WPMCP_FILE ),
			'new_version'     => $release['version'],
			'url'             => home_url(),
			'package'         => $release['url'],
			'upgrade_notice'  => '',
		);
		return $transient;
	}

	public static function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== dirname( plugin_basename( WPMCP_FILE ) ) ) {
			return $result;
		}
		$release = self::latest_release();
		if ( empty( $release['version'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'WP MCP Suite',
			'slug'          => dirname( plugin_basename( WPMCP_FILE ) ),
			'version'       => $release['version'],
			'download_link' => $release['url'],
			'sections'      => array(
				'description' => 'WordPress MCP server for AI agents with a SEO-first toolset.',
				'changelog'   => $release['changelog'],
			),
		);
	}

	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = null ) {
		global $wp_filesystem;
		if ( empty( $remote_source ) || empty( basename( $source ) ) || false === strpos( basename( $source ), 'wpmcp' ) ) {
			return $source;
		}
		$desired = trailingslashit( dirname( $source ) ) . 'wpmcp';
		if ( trailingslashit( strtolower( $source ) ) === trailingslashit( strtolower( $desired ) ) || basename( $source ) === 'wpmcp' ) {
			return $source;
		}
		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}
		return $source;
	}
}

WPMCP_Updater::init();
