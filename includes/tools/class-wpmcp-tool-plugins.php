<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Plugins {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'plugin-manage',
			array(
				'title'       => 'Plugin Manage',
				'description' => 'Manage installed plugins. Operations: list, activate, deactivate, install (wordpress.org slug), update, delete (confirm:true). Write operations ship disabled; this plugin can never be deactivated or deleted over MCP.',
				'category'    => 'plugins',
				'write'       => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'list', 'activate', 'deactivate', 'install', 'update', 'delete' ), 'required' => true ),
						'plugin'    => array( 'type' => 'string', 'description' => 'Plugin file path relative to wp-content/plugins, e.g. hello.php or elementor/elementor.php' ),
						'slug'      => array( 'type' => 'string', 'description' => 'install: wordpress.org slug' ),
						'search'    => array( 'type' => 'string', 'description' => 'list: filter by name substring' ),
						'confirm'   => array( 'type' => 'boolean', 'description' => 'delete only' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'handle_plugin' ),
			)
		);

		$this->registry->register(
			'theme-manage',
			array(
				'title'       => 'Theme Manage',
				'description' => 'Manage themes. Operations: list, switch (activate an installed theme), delete (inactive themes only, confirm:true). Write operations ship disabled.',
				'category'    => 'themes',
				'write'       => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'list', 'switch', 'delete' ), 'required' => true ),
						'theme'     => array( 'type' => 'string', 'description' => 'Theme directory slug' ),
						'confirm'   => array( 'type' => 'boolean', 'description' => 'delete only' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'handle_theme' ),
			)
		);
	}

	private static function self_protected( string $plugin_file ): bool {
		return str_contains( $plugin_file, 'wpmcp' );
	}

	private function require_upgrades(): array|WP_Error {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		return array();
	}

	private function require_fs(): void {
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	public function handle_plugin( array $args ): array {
		$operation = (string) ( $args['operation'] ?? '' );

		if ( 'list' === $operation ) {
			$search  = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
			$out     = array();
			$updates = get_site_transient( 'update_plugins' ) ?? new stdClass();
			foreach ( get_plugins() as $file => $data ) {
				if ( '' !== $search && ! str_contains( strtolower( $data['Name'] . ' ' . $file ), $search ) ) {
					continue;
				}
				$out[] = array(
					'plugin'    => $file,
					'name'      => $data['Name'],
					'version'   => $data['Version'],
					'status'    => is_plugin_active( $file ) ? 'active' : 'inactive',
					'update'    => isset( $updates->response[ $file ] ) ? (string) $updates->response[ $file ]->new_version : null,
				);
			}
			return array( 'total' => count( $out ), 'plugins' => $out );
		}

		$needs_plugin = in_array( $operation, array( 'activate', 'deactivate', 'update', 'delete' ), true );
		$plugin       = trim( (string) ( $args['plugin'] ?? '' ) );
		if ( $needs_plugin && ( '' === $plugin || ! self::self_protected_check_path( $plugin ) ) ) {
			return array( 'error' => 'invalid_plugin_path' );
		}
		if ( '' !== $plugin && self::self_protected( $plugin ) ) {
			return array( 'error' => 'self_protection', 'message' => 'WP MCP Suite cannot be managed through its own MCP server.' );
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return array( 'error' => 'forbidden' );
		}
		$this->require_fs();

		switch ( $operation ) {
			case 'activate':
				if ( ! validate_plugin( $plugin ) ) {
					$result = activate_plugin( $plugin );
					return $result instanceof WP_Error
						? array( 'error' => 'activate_failed', 'message' => $result->get_error_message() )
						: array( 'ok' => true, 'activated' => $plugin );
				}
				return array( 'error' => 'invalid_plugin_path' );

			case 'deactivate':
				if ( is_plugin_active( $plugin ) ) {
					deactivate_plugins( $plugin );
					return array( 'ok' => true, 'deactivated' => $plugin );
				}
				return array( 'error' => 'already_inactive' );

			case 'install':
				if ( '' === ( $args['slug'] ?? '' ) ) {
					return array( 'error' => 'slug_required' );
				}
				$this->require_upgrades();
				$api = plugins_api( 'plugin_information', array( 'slug' => sanitize_key( (string) $args['slug'] ), 'fields' => array( 'sections' => false ) ) );
				if ( is_wp_error( $api ) ) {
					return array( 'error' => 'not_found_on_org', 'message' => $api->get_error_message() );
				}
				$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
				$result   = $upgrader->install( $api->download_link );
				if ( is_wp_error( $result ) ) {
					return array( 'error' => 'install_failed', 'message' => $result->get_error_message() );
				}
				if ( true !== $result ) {
					return array( 'error' => 'install_failed', 'message' => 'Upgrader returned a non-true result.' );
				}
				$installed = WP_PLUGIN_DIR . '/' . $args['slug'];
				$this->log->record( 'plugins', 'install-plugin', 0, (string) $args['slug'], sprintf( 'Installed plugin %s', $args['slug'] ) );
				return array( 'ok' => true, 'installed' => $installed );

			case 'update':
				$this->require_upgrades();
				$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
				$result   = $upgrader->upgrade( $plugin );
				if ( is_wp_error( $result ) ) {
					return array( 'error' => 'update_failed', 'message' => $result->get_error_message() );
				}
				$this->log->record( 'plugins', 'update-plugin', 0, $plugin, sprintf( 'Updated plugin %s', $plugin ) );
				return array( 'ok' => true, 'updated' => $plugin );

			case 'delete':
				if ( empty( $args['confirm'] ) ) {
					return array( 'error' => 'confirm_required' );
				}
				if ( is_plugin_active( $plugin ) ) {
					return array( 'error' => 'deactivate_first' );
				}
				$deleted = delete_plugins( array( $plugin ) );
				if ( is_wp_error( $deleted ) ) {
					return array( 'error' => 'delete_failed', 'message' => $deleted->get_error_message() );
				}
				$this->log->record( 'plugins', 'delete-plugin', 0, $plugin, sprintf( 'Deleted plugin %s', $plugin ), array(), false );
				return array( 'deleted' => true, 'plugin' => $plugin );
		}

		return array( 'error' => 'unknown_operation' );
	}

	private static function self_protected_check_path( string $path ): bool {
		return '' !== preg_replace( '/[^a-z0-9_\.\-\/]/i', '', $path );
	}

	public function handle_theme( array $args ): array {
		$operation = (string) ( $args['operation'] ?? '' );

		if ( 'list' === $operation ) {
			$out = array();
			foreach ( wp_get_themes() as $slug => $theme ) {
				$update = get_site_transient( 'update_themes' );
				$out[]  = array(
					'theme'     => $slug,
					'name'      => $theme->get( 'Name' ),
					'version'   => $theme->get( 'Version' ),
					'active'    => get_stylesheet() === $slug,
					'parent'    => $theme->get_template() !== $slug ? $theme->get_template() : null,
					'update'    => isset( $update->response[ $slug ] ) ? (string) $update->response[ $slug ]['new_version'] : null,
				);
			}
			return array( 'total' => count( $out ), 'themes' => $out );
		}

		$slug = sanitize_title( (string) ( $args['theme'] ?? '' ) );
		if ( '' === $slug ) {
			return array( 'error' => 'theme_required' );
		}
		if ( ! current_user_can( 'switch_themes' ) ) {
			return array( 'error' => 'forbidden' );
		}

		switch ( $operation ) {
			case 'switch':
				$theme = wp_get_theme( $slug );
				if ( ! $theme->exists() ) {
					return array( 'error' => 'theme_not_found' );
				}
				if ( get_stylesheet() === $slug ) {
					return array( 'already_active' => true );
				}
				$previous = get_option( 'stylesheet' );
				switch_theme( $slug );
				$this->log->record( 'themes', 'switch-theme', 0, $slug, sprintf( 'Switched active theme to %s', $slug ), array( 'previous_theme' => $previous ), true );
				return array( 'ok' => true, 'active_theme' => $slug );

			case 'delete':
				if ( empty( $args['confirm'] ) ) {
					return array( 'error' => 'confirm_required' );
				}
				if ( get_stylesheet() === $slug ) {
					return array( 'error' => 'cannot_delete_active_theme' );
				}
				$theme = wp_get_theme( $slug );
				if ( ! $theme->exists() ) {
					return array( 'error' => 'theme_not_found' );
				}
				$deleted = delete_theme( $slug );
				if ( is_wp_error( $deleted ) ) {
					return array( 'error' => 'delete_failed', 'message' => $deleted->get_error_message() );
				}
				$this->log->record( 'themes', 'delete-theme', 0, $slug, sprintf( 'Deleted theme %s', $slug ), array(), false );
				return array( 'deleted' => true, 'theme' => $slug );
		}

		return array( 'error' => 'unknown_operation' );
	}
}
