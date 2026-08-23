<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Themes {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'theme-read',
			array(
				'title'       => 'Theme Read',
				'description' => 'Read the active theme: context (name, version, parent/child, block-theme, menu locations) and theme_mod values. Operations: get-theme-context, get-mods.',
				'category'    => 'themes',
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'get-theme-context', 'get-mods' ), 'default' => 'get-theme-context' ),
						'keys'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'get-mods: only return these mod keys' ),
					),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'theme-write',
			array(
				'title'       => 'Theme Write',
				'description' => "Write the active theme's settings: set-mods merges theme_mod values; create-child-theme generates and optionally activates a child theme (confirm:true). Pro only. Write ships disabled.",
				'category'    => 'themes',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'set-mods', 'create-child-theme' ), 'required' => true ),
						'mods'      => array( 'type' => 'object', 'description' => 'set-mods: key => value' ),
						'name'      => array( 'type' => 'string', 'description' => 'create-child-theme: child theme name' ),
						'activate'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'create-child-theme: activate after creation' ),
						'confirm'   => array( 'type' => 'boolean', 'description' => 'create-child-theme only' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	private function context(): array {
		$theme = wp_get_theme();
		return array(
			'name'        => $theme->get( 'Name' ),
			'slug'        => get_stylesheet(),
			'version'     => $theme->get( 'Version' ),
			'parent'      => $theme->get_template() !== get_stylesheet() ? $theme->get_template() : null,
			'block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'menu_locations' => get_registered_nav_menus(),
			'tags'        => $theme->get( 'Tags' ),
		);
	}

	public function read( array $args ): array {
		$operation = (string) ( $args['operation'] ?? 'get-theme-context' );

		if ( 'get-theme-context' === $operation ) {
			return $this->context();
		}
		if ( 'get-mods' === $operation ) {
			$mods = get_theme_mods();
			$mods = is_array( $mods ) ? $mods : array();
			unset( $mods['sidebars_widgets'], $mods['custom_css_post_id'] );
			if ( ! empty( $args['keys'] ) && is_array( $args['keys'] ) ) {
				$picked = array();
				foreach ( (array) $args['keys'] as $key ) {
					$key = sanitize_key( (string) $key );
					if ( array_key_exists( $key, $mods ) ) {
						$picked[ $key ] = $mods[ $key ];
					}
				}
				return array( 'mods' => $picked );
			}
			return array( 'theme' => get_stylesheet(), 'mods' => $mods );
		}
		return array( 'error' => 'unknown_operation' );
	}

	public function write( array $args ): array {
		$operation = (string) ( $args['operation'] ?? '' );

		if ( 'set-mods' === $operation ) {
			$mods = isset( $args['mods'] ) && is_array( $args['mods'] ) ? $args['mods'] : array();
			if ( empty( $mods ) ) {
				return array( 'error' => 'mods_required' );
			}
			$before = array();
			foreach ( $mods as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key || 0 === strpos( $key, 'sidebars_' ) || 'custom_css_post_id' === $key ) {
					continue;
				}
				$before[ $key ] = get_theme_mod( $key );
				set_theme_mod( $key, is_scalar( $value ) || is_array( $value ) ? $value : null );
			}
			$this->log->record( 'themes', 'set-mods', 0, get_stylesheet(), sprintf( 'Updated %d theme mods on %s', count( $before ), get_stylesheet() ), $before, true );
			return array( 'ok' => true, 'updated' => count( $before ) );
		}

		if ( 'create-child-theme' === $operation ) {
			if ( empty( $args['confirm'] ) ) {
				return array( 'error' => 'confirm_required' );
			}
			$name = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
			if ( '' === $name ) {
				return array( 'error' => 'name_required' );
			}
			$parent = wp_get_theme();
			if ( $parent->parent() ) {
				return array( 'error' => 'already_a_child_theme', 'message' => 'The active theme is already a child theme.' );
			}
			$slug  = sanitize_title( $name );
			$theme_dir = WP_CONTENT_DIR . '/themes/' . $slug;
			if ( ! wp_mkdir_p( $theme_dir ) ) {
				return array( 'error' => 'mkdir_failed' );
			}
			$style = sprintf(
				"/*\nTheme Name: %s\nTemplate: %s\nDescription: Child of %s, generated by WP MCP Suite.\nVersion: 1.0.0\n*/\n",
				$name,
				$parent->get_stylesheet(),
				$parent->get( 'Name' )
			);
			file_put_contents( $theme_dir . '/style.css', $style );
			file_put_contents(
				$theme_dir . '/functions.php',
				sprintf(
					"<?php\nadd_action( 'wp_enqueue_scripts', function () {\n\twp_enqueue_style( '%s-parent', get_template_directory_uri() . '/style.css' );\n}, 20 );\n",
					$slug
				)
			);
			if ( ! empty( $args['activate'] ) ) {
				switch_theme( $slug );
			}
			$this->log->record( 'themes', 'create-child-theme', 0, $slug, sprintf( 'Created child theme %s of %s', $name, $parent->get( 'Name' ) ) );
			return array( 'ok' => true, 'slug' => $slug, 'dir' => $theme_dir, 'activated' => ! empty( $args['activate'] ) );
		}

		return array( 'error' => 'unknown_operation' );
	}
}
