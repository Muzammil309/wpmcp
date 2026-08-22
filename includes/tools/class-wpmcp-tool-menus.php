<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Menus {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'menu-read',
			array(
				'title'       => 'Menu Read',
				'description' => 'Read navigation menus. Operations: list-menus, get-menu (nested item tree), list-locations, render (HTML).',
				'category'    => 'menus',
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'list-menus', 'get-menu', 'list-locations', 'render' ), 'default' => 'list-menus' ),
						'menu'      => array( 'type' => 'string', 'description' => 'Menu ID, slug or name (get-menu / render)' ),
						'location'  => array( 'type' => 'string', 'description' => 'Theme location slug (get-menu / render)' ),
						'depth'     => array( 'type' => 'integer', 'description' => 'render: levels to include, 0 = all' ),
					),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'menu-write',
			array(
				'title'       => 'Menu Write',
				'description' => 'Manage navigation menus. Operations: create-menu, rename-menu, delete-menu (confirm), assign-location, unassign-location, add-item, update-item, delete-item, reorder-items. Write operations ship disabled.',
				'category'    => 'menus',
				'write'       => true,
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'create-menu', 'rename-menu', 'delete-menu', 'assign-location', 'unassign-location', 'add-item', 'update-item', 'delete-item', 'reorder-items' ), 'required' => true ),
						'menu'      => array( 'type' => 'string', 'description' => 'Menu ID, slug or name' ),
						'name'      => array( 'type' => 'string', 'description' => 'create-menu / rename-menu: new name' ),
						'location'  => array( 'type' => 'string' ),
						'item_id'   => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string', 'description' => 'add-item / update-item: label' ),
						'url'       => array( 'type' => 'string', 'description' => 'add-item custom URL' ),
						'object_id' => array( 'type' => 'integer', 'description' => 'add-item: post/page ID' ),
						'object'    => array( 'type' => 'string', 'description' => 'add-item: post, page, custom, category' ),
						'parent_id' => array( 'type' => 'integer', 'description' => '0 = top level' ),
						'confirm'   => array( 'type' => 'boolean', 'description' => 'delete-menu only' ),
						'order'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'reorder-items: item ids in order' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	private function resolve_menu( array $args ) {
		$menu = (string) ( $args['menu'] ?? ( $args['location'] ?? '' ) );
		if ( '' === $menu ) {
			return null;
		}
		if ( '' !== ( $args['location'] ?? '' ) && '' === ( $args['menu'] ?? '' ) ) {
			$locations = get_nav_menu_locations();
			$slug      = sanitize_title( (string) $args['location'] );
			if ( isset( $locations[ $slug ] ) ) {
				return wp_get_nav_menu_object( $locations[ $slug ] );
			}
			return null;
		}
		return wp_get_nav_menu_object( $menu );
	}

	private function item_row( WP_Post $item ): array {
		return array(
			'id'         => (int) $item->ID,
			'parent_id'  => (int) $item->menu_item_parent,
			'title'      => $item->title,
			'url'        => (string) get_post_meta( $item->ID, '_menu_item_url', true ),
			'object'     => (string) get_post_meta( $item->ID, '_menu_item_object', true ),
			'object_id'  => (int) get_post_meta( $item->ID, '_menu_item_object_id', true ),
			'type'       => (string) get_post_meta( $item->ID, '_menu_item_type', true ),
			'menu_order' => (int) $item->menu_order,
		);
	}

	public function read( array $args ): array {
		$operation = (string) ( $args['operation'] ?? 'list-menus' );

		if ( 'list-menus' === $operation ) {
			$out = array();
			foreach ( wp_get_nav_menus() as $menu ) {
				$out[] = array(
					'id'         => (int) $menu->term_id,
					'name'       => $menu->name,
					'slug'       => $menu->slug,
					'item_count' => (int) $menu->count,
					'locations'  => array_keys( (array) get_nav_menu_locations(), (int) $menu->term_id, true ),
				);
			}
			return array( 'total' => count( $out ), 'menus' => $out );
		}

		if ( 'list-locations' === $operation ) {
			$locations = get_nav_menu_locations();
			$registered = get_registered_nav_menus();
			$out = array();
			foreach ( $registered as $slug => $description ) {
				$out[] = array(
					'location'    => $slug,
					'description' => $description,
					'menu_id'     => $locations[ $slug ] ?? null,
				);
			}
			return array( 'locations' => $out );
		}

		$menu = $this->resolve_menu( $args );
		if ( ! $menu ) {
			return array( 'error' => 'menu_not_found' );
		}

		if ( 'get-menu' === $operation ) {
			$items = wp_get_nav_menu_items( $menu->term_id ) ?: array();
			return array(
				'id'    => (int) $menu->term_id,
				'name'  => $menu->name,
				'items' => array_map( array( $this, 'item_row' ), array_values( $items ) ),
			);
		}

		// render.
		return array(
			'id'   => (int) $menu->term_id,
			'name' => $menu->name,
			'html' => wp_nav_menu(
				array(
					'menu'            => $menu->term_id,
					'echo'            => false,
					'depth'           => max( 0, (int) ( $args['depth'] ?? 0 ) ),
					'container'       => false,
					'fallback_cb'     => false,
				)
			),
		);
	}

	public function write( array $args ): array {
		$operation = (string) ( $args['operation'] ?? '' );

		if ( 'create-menu' === $operation ) {
			$name = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
			if ( '' === $name ) {
				return array( 'error' => 'name_required' );
			}
			$id = wp_create_nav_menu( $name );
			if ( is_wp_error( $id ) ) {
				return array( 'error' => 'create_failed', 'message' => $id->get_error_message() );
			}
			$this->log->record( 'menus', 'create-menu', $id, $name, 'Created nav menu' );
			return array( 'ok' => true, 'id' => $id, 'name' => $name );
		}

		$menu = $this->resolve_menu( array( 'menu' => (string) ( $args['menu'] ?? '' ) ) );
		if ( ! $menu && in_array( $operation, array( 'rename-menu', 'delete-menu', 'assign-location', 'unassign-location', 'add-item', 'update-item', 'delete-item', 'reorder-items' ), true ) ) {
			return array( 'error' => 'menu_not_found' );
		}

		switch ( $operation ) {
			case 'rename-menu':
				$name = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
				if ( '' === $name ) {
					return array( 'error' => 'name_required' );
				}
				$result = wp_update_nav_menu_object( $menu->term_id, array( 'menu-name' => $name, 'name' => $name ) );
				if ( is_wp_error( $result ) ) {
					return array( 'error' => 'rename_failed', 'message' => $result->get_error_message() );
				}
				$this->log->record( 'menus', 'rename-menu', $menu->term_id, $name, sprintf( 'Renamed menu from %s', $menu->name ) );
				return array( 'ok' => true, 'id' => $menu->term_id, 'name' => $name );

			case 'delete-menu':
				if ( empty( $args['confirm'] ) ) {
					return array( 'error' => 'confirm_required' );
				}
				$deleted = wp_delete_nav_menu( $menu->term_id );
				if ( ! $deleted ) {
					return array( 'error' => 'delete_failed' );
				}
				$this->log->record( 'menus', 'delete-menu', $menu->term_id, $menu->name, 'Deleted nav menu', array( 'name' => $menu->name ), false );
				return array( 'deleted' => true, 'id' => $menu->term_id );

			case 'assign-location':
				$location = sanitize_title( (string) ( $args['location'] ?? '' ) );
				$registered = get_registered_nav_menus();
				if ( ! isset( $registered[ $location ] ) ) {
					return array( 'error' => 'unknown_location' );
				}
				$locations           = get_nav_menu_locations();
				$locations[ $location ] = (int) $menu->term_id;
				set_theme_mod( 'nav_menu_locations', $locations );
				$this->log->record( 'menus', 'assign-location', $menu->term_id, $menu->name, sprintf( 'Assigned to %s', $location ) );
				return array( 'ok' => true, 'location' => $location, 'menu_id' => (int) $menu->term_id );

			case 'unassign-location':
				$location = sanitize_title( (string) ( $args['location'] ?? '' ) );
				$locations = get_nav_menu_locations();
				if ( isset( $locations[ $location ] ) ) {
					unset( $locations[ $location ] );
					set_theme_mod( 'nav_menu_locations', $locations );
				}
				return array( 'ok' => true, 'location' => $location );

			case 'add-item':
				$object = sanitize_key( (string) ( $args['object'] ?? 'custom' ) );
				$title  = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
				if ( 'custom' === $object ) {
					if ( '' === $title || '' === ( $args['url'] ?? '' ) ) {
						return array( 'error' => 'title_and_url_required' );
					}
					$object_id = 0;
					$url       = esc_url_raw( (string) $args['url'] );
				} else {
					$object_id = (int) ( $args['object_id'] ?? 0 );
					$target    = get_post( $object_id );
					if ( ! $target ) {
						return array( 'error' => 'object_not_found' );
					}
					if ( '' === $title ) {
						$title = $target->post_title;
					}
					$url = '';
				}
				$item_id = wp_update_nav_menu_item(
					$menu->term_id,
					0,
					array(
						'menu-item-title'  => $title,
						'menu-item-url'    => $url,
						'menu-item-object' => $object,
						'menu-item-object-id' => $object_id,
						'menu-item-type'   => 'custom' === $object ? 'custom' : 'post_type',
						'menu-item-status' => 'publish',
						'menu-item-parent-id' => (int) ( $args['parent_id'] ?? 0 ),
					)
				);
				if ( is_wp_error( $item_id ) ) {
					return array( 'error' => 'add_failed', 'message' => $item_id->get_error_message() );
				}
				$this->log->record( 'menus', 'add-item', $item_id, $title, sprintf( 'Added item to %s', $menu->name ) );
				return array( 'ok' => true, 'item_id' => $item_id );

			case 'update-item':
				$item_id = (int) ( $args['item_id'] ?? 0 );
				$item    = get_post( $item_id );
				if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
					return array( 'error' => 'item_not_found' );
				}
				$payload = array( 'menu-item-db-id' => $item_id );
				if ( isset( $args['title'] ) ) {
					$payload['menu-item-title'] = sanitize_text_field( (string) $args['title'] );
				}
				if ( isset( $args['url'] ) ) {
					$payload['menu-item-url'] = esc_url_raw( (string) $args['url'] );
				}
				if ( isset( $args['parent_id'] ) ) {
					$payload['menu-item-parent-id'] = max( 0, (int) $args['parent_id'] );
				}
				wp_update_nav_menu_item( $menu->term_id, $item_id, $payload );
				$this->log->record( 'menus', 'update-item', $item_id, (string) $item->post_title, 'Updated menu item', array( 'title' => $item->post_title ), true );
				return array( 'ok' => true, 'item_id' => $item_id );

			case 'delete-item':
				$item_id = (int) ( $args['item_id'] ?? 0 );
				$item    = get_post( $item_id );
				if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
					return array( 'error' => 'item_not_found' );
				}
				wp_delete_post( $item_id, true );
				$this->log->record( 'menus', 'delete-item', $item_id, (string) $item->post_title, 'Deleted menu item', array( 'title' => $item->post_title ), false );
				return array( 'deleted' => true, 'item_id' => $item_id );

			case 'reorder-items':
				$order = (array) ( $args['order'] ?? array() );
				if ( empty( $order ) ) {
					return array( 'error' => 'order_required' );
				}
				foreach ( array_values( $order ) as $position => $item_id ) {
					$item_id = (int) $item_id;
					$post    = get_post( $item_id );
					if ( $post && 'nav_menu_item' === $post->post_type ) {
						wp_update_post( array( 'ID' => $item_id, 'menu_order' => $position + 1 ) );
					}
				}
				$this->log->record( 'menus', 'reorder-items', $menu->term_id, $menu->name, sprintf( 'Reordered %d items', count( $order ) ) );
				return array( 'ok' => true, 'reordered' => count( $order ) );
		}

		return array( 'error' => 'unknown_operation' );
	}
}
