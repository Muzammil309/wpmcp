<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Elementor {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		if ( ! WPMCP_EL_Document::available() ) {
			return;
		}

		$this->registry->register(
			'elementor-status',
			array(
				'title'       => 'Elementor Status',
				'description' => 'Detect Elementor version and whether it is usable over MCP. Read-only.',
				'category'    => 'elementor',
				'capability'  => 'edit_posts',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'handler'     => fn() => array(
					'version'     => ELEMENTOR_VERSION,
					'pro'         => defined( 'ELEMENTOR_PRO_VERSION' ),
					'widget_count' => count( \Elementor\Plugin::$instance->widgets_manager->get_widget_types() ),
				),
			)
		);

		$this->registry->register(
			'list-elementor-widgets',
			array(
				'title'       => 'List Elementor Widgets',
				'description' => 'Catalog of registered Elementor widgets (name, title, categories). Filter by search.',
				'category'    => 'elementor',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'default' => 100, 'maximum' => 300 ),
					),
				),
				'handler'     => array( $this, 'list_widgets' ),
			)
		);

		$this->registry->register(
			'get-widget-schema',
			array(
				'title'       => 'Get Widget Schema',
				'description' => 'Content controls of one Elementor widget: real setting names, types, labels, defaults.',
				'category'    => 'elementor',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'widget_type' => array( 'type' => 'string', 'description' => 'e.g. heading' ),
					),
					'required'   => array( 'widget_type' ),
				),
				'handler'     => array( $this, 'widget_schema' ),
			)
		);

		$this->registry->register(
			'get-page-structure',
			array(
				'title'       => 'Get Page Structure',
				'description' => 'Normalized Elementor element tree for a page: ids, types, widget names, summarized settings.',
				'category'    => 'elementor',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'page_structure' ),
			)
		);

		$this->registry->register(
			'add-container',
			array(
				'title'       => 'Add Container',
				'description' => 'Append a flexbox container (or nest into an existing container) with settings. Returns the new element id.',
				'category'    => 'elementor',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'settings'  => array( 'type' => 'object' ),
						'parent_id' => array( 'type' => 'string' ),
						'index'     => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'add_container' ),
			)
		);

		$this->registry->register(
			'add-widget',
			array(
				'title'       => 'Add Widget',
				'description' => 'Insert a widget into a container with settings. Omit container_id to use the first container; creates nothing if the page has none.',
				'category'    => 'elementor',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'widget_type' => array( 'type' => 'string', 'description' => 'Widget name from list-elementor-widgets, e.g. heading' ),
						'settings'    => array( 'type' => 'object' ),
						'container_id' => array( 'type' => 'string' ),
						'index'       => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id', 'widget_type' ),
				),
				'handler'     => array( $this, 'add_widget' ),
			)
		);

		$this->registry->register(
			'update-element',
			array(
				'title'       => 'Update Element',
				'description' => 'Merge settings into any element by id. Only passed settings change.',
				'category'    => 'elementor',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'settings'   => array( 'type' => 'object' ),
					),
					'required'   => array( 'post_id', 'element_id', 'settings' ),
				),
				'handler'     => array( $this, 'update_element' ),
			)
		);

		$this->registry->register(
			'duplicate-element',
			array(
				'title'       => 'Duplicate Element',
				'description' => 'Deep-clone an element subtree with fresh ids, placed right after the original. Styles travel with the copy via its own settings.',
				'category'    => 'elementor',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id', 'element_id' ),
				),
				'handler'     => array( $this, 'duplicate_element' ),
			)
		);

		$this->registry->register(
			'move-element',
			array(
				'title'       => 'Move Element',
				'description' => 'Re-parent or reorder an element by id. Moving inside its own subtree is refused.',
				'category'    => 'elementor',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'parent_id'  => array( 'type' => 'string', 'description' => 'New parent; omit to move to top level' ),
						'index'      => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id', 'element_id' ),
				),
				'handler'     => array( $this, 'move_element' ),
			)
		);

		$this->registry->register(
			'remove-element',
			array(
				'title'       => 'Remove Element',
				'description' => 'Delete an element subtree by id. Destructive; requires confirm:true.',
				'category'    => 'elementor',
				'write'       => true,
				'confirm'     => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'confirm'    => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'post_id', 'element_id', 'confirm' ),
				),
				'handler'     => array( $this, 'remove_element' ),
			)
		);

		$this->registry->register(
			'clear-page',
			array(
				'title'       => 'Clear Page',
				'description' => 'Remove every Elementor element from a page. Destructive; requires confirm:true.',
				'category'    => 'elementor',
				'write'       => true,
				'confirm'     => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'post_id', 'confirm' ),
				),
				'handler'     => array( $this, 'clear_page' ),
			)
		);

		$this->registry->register(
			'build-page',
			array(
				'title'       => 'Build Page',
				'description' => 'Composite: create a page (or reuse one) and build containers + widgets from a declarative JSON tree in a single call. structure = [{settings:{}, widgets:[{type, settings}]}]. Returns page id and all element ids.',
				'category'    => 'elementor',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array( 'type' => 'string', 'description' => 'Page title (creates a draft page)' ),
						'post_id'   => array( 'type' => 'integer', 'description' => 'Existing page to rebuild instead of creating' ),
						'structure' => array(
							'type'        => 'array',
							'description' => 'Container list',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'settings' => array( 'type' => 'object' ),
									'widgets'  => array(
										'type'        => 'array',
										'description' => 'Widgets in this container',
										'items'       => array(
											'type'       => 'object',
											'properties' => array(
												'type'     => array( 'type' => 'string' ),
												'settings' => array( 'type' => 'object' ),
											),
											'required'   => array( 'type' ),
										),
									),
									'containers' => array( 'type' => 'array', 'description' => 'Nested containers (same shape)' ),
								),
							),
						),
						'status'    => array( 'type' => 'string', 'enum' => array( 'draft', 'publish' ), 'default' => 'draft' ),
						'page_template' => array( 'type' => 'string', 'enum' => array( 'canvas', 'full-width', 'default' ), 'default' => 'default', 'description' => 'Elementor page layout: canvas = blank page (no theme chrome), full-width = theme header/footer kept, default = theme template.' ),
					),
					'required'   => array( 'structure' ),
				),
				'handler'     => array( $this, 'build_page' ),
			)
		);
	}


	private function load( int $post_id ): WPMCP_EL_Document|WP_Error|array {
		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		return WPMCP_EL_Document::load( $post_id );
	}
	private function mutate( array $args, callable $fn, string $action ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$before = get_post_meta( $post_id, '_elementor_data', true );
		$doc    = WPMCP_EL_Document::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return array( 'error' => $doc->get_error_code(), 'message' => $doc->get_error_message() );
		}
		$result = $fn( $doc, $post );
		if ( isset( $result['error'] ) || is_wp_error( $result ) ) {
			return is_array( $result ) ? $result : array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() );
		}
		$saved  = $doc->save();
		$return = is_array( $result ) ? $result : array();
		$return += array(
			'saved' => (bool) ( $saved['saved'] ?? false ),
		);
		if ( ! empty( $saved['error'] ) ) {
			$return['save_error'] = $saved['error'];
		}
		$this->log->record( 'elementor', $action, $post_id, $post->post_title, sprintf( '%s on post %d', $action, $post_id ), array( 'data' => $before ), true );
		return $return;
	}

	public function status(): array {
		return array(
			'version'      => ELEMENTOR_VERSION,
			'pro'          => defined( 'ELEMENTOR_PRO_VERSION' ),
			'widget_count' => count( \Elementor\Plugin::$instance->widgets_manager->get_widget_types() ),
		);
	}

	public function list_widgets( array $args ): array {
		if ( ! WPMCP_EL_Document::available() ) {
			return array( 'error' => 'wpmcp_elementor_missing', 'message' => 'Elementor is not installed or active.' );
		}
		$search   = strtolower( sanitize_text_field( $args['search'] ?? '' ) );
		$per_page = min( 300, max( 1, (int) ( $args['per_page'] ?? 100 ) ) );
		$all      = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
		$out      = array();
		foreach ( $all as $name => $widget ) {
			if ( '' !== $search && ! str_contains( strtolower( $name . ' ' . $widget->get_title() ), $search ) ) {
				continue;
			}
			$out[] = array(
				'name'       => $name,
				'title'      => $widget->get_title(),
				'categories' => $widget->get_categories(),
			);
			if ( count( $out ) >= $per_page ) {
				break;
			}
		}
		return array( 'total_registered' => count( $all ), 'widgets' => $out );
	}

	public function widget_schema( array $args ): array {
		if ( ! WPMCP_EL_Document::available() ) {
			return array( 'error' => 'wpmcp_elementor_missing', 'message' => 'Elementor is not installed or active.' );
		}
		$type = sanitize_text_field( (string) ( $args['widget_type'] ?? '' ) );
		if ( 'container' === $type ) {
			$container_el = \Elementor\Plugin::$instance->elements_manager->get_element_types( 'container' );
			if ( ! $container_el ) {
				return array( 'error' => 'wpmcp_unknown_widget', 'message' => 'Containers are not registered on this Elementor install.' );
			}
			$controls = array();
			foreach ( $container_el->get_controls() as $name => $control ) {
				if ( ! is_array( $control ) || empty( $control['type'] ) || in_array( $control['type'], array( \Elementor\Controls_Manager::TAB_CONTENT, \Elementor\Controls_Manager::TAB_STYLE, \Elementor\Controls_Manager::SECTION ), true ) ) {
					continue;
				}
				$controls[ $name ] = array(
					'type'    => (string) $control['type'],
					'label'   => (string) ( $control['label'] ?? '' ),
					'default' => $control['default'] ?? null,
				);
				if ( count( $controls ) >= 120 ) {
					break;
				}
			}
			return array( 'name' => 'container', 'title' => 'Flexbox Container', 'controls' => $controls );
		}
		$doc  = new WPMCP_EL_Document();
		$result = $doc->widget_controls( $type );
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() );
		}
		return $result;
	}

	public function page_structure( array $args ): array {
		$doc = $this->load( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! is_object( $doc ) || is_wp_error( $doc ) ) {
			return is_array( $doc ) ? $doc : array( 'error' => $doc->get_error_code(), 'message' => $doc->get_error_message() );
		}
		return array(
			'post_id'  => $doc->post_id,
			'is_built_with_elementor' => (bool) get_post_meta( $doc->post_id, '_elementor_edit_mode', true ),
			'elements' => $doc->structure(),
		);
	}

	public function add_container( array $args ): array {
		return $this->mutate(
			$args,
			function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$id = $doc->add_container(
					(array) ( $args['settings'] ?? array() ),
					isset( $args['parent_id'] ) ? sanitize_text_field( (string) $args['parent_id'] ) : null,
					isset( $args['index'] ) ? (int) $args['index'] : null
				);
				if ( is_wp_error( $id ) ) {
					return array( 'error' => $id->get_error_code(), 'message' => $id->get_error_message() );
				}
				return array( 'container_id' => $id );
			},
			'add-container'
		);
	}

	public function add_widget( array $args ): array {
		return $this->mutate(
			$args,
			function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$type = sanitize_text_field( (string) $args['widget_type'] );
				if ( ! \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $type ) ) {
					return array( 'error' => 'unknown_widget', 'message' => sprintf( '%s is not registered.', $type ) );
				}
				$id = $doc->add_widget(
					$type,
					(array) ( $args['settings'] ?? array() ),
					isset( $args['container_id'] ) ? sanitize_text_field( (string) $args['container_id'] ) : null,
					isset( $args['index'] ) ? (int) $args['index'] : null
				);
				if ( is_wp_error( $id ) ) {
					return array( 'error' => $id->get_error_code(), 'message' => $id->get_error_message() );
				}
				return array( 'widget_id' => $id );
			},
			'add-widget'
		);
	}

	public function update_element( array $args ): array {
		return $this->mutate(
			$args,
			function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$result = $doc->update_element(
					sanitize_text_field( (string) $args['element_id'] ),
					(array) ( $args['settings'] ?? array() )
				);
				if ( is_wp_error( $result ) ) {
					return array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() );
				}
				return array( 'updated' => true );
			},
			'update-element'
		);
	}

	public function duplicate_element( array $args ): array {
		return $this->mutate(
			$args,
			function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$new_id = $doc->duplicate_element( sanitize_text_field( (string) $args['element_id'] ) );
				if ( is_wp_error( $new_id ) ) {
					return array( 'error' => $new_id->get_error_code(), 'message' => $new_id->get_error_message() );
				}
				return array( 'new_element_id' => $new_id );
			},
			'duplicate-element'
		);
	}

	public function move_element( array $args ): array {
		return $this->mutate(
			$args,
			function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$result = $doc->move_element(
					sanitize_text_field( (string) $args['element_id'] ),
					isset( $args['parent_id'] ) ? sanitize_text_field( (string) $args['parent_id'] ) : null,
					isset( $args['index'] ) ? (int) $args['index'] : null
				);
				if ( is_wp_error( $result ) ) {
					return array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() );
				}
				return array( 'moved' => true );
			},
			'move-element'
		);
	}

	public function remove_element( array $args ): array {
		return $this->mutate(
			$args,
			function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$result = $doc->remove_element( sanitize_text_field( (string) $args['element_id'] ) );
				if ( is_wp_error( $result ) ) {
					return array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() );
				}
				return array( 'removed' => true );
			},
			'remove-element'
		);
	}

	public function clear_page( array $args ): array {
		return $this->mutate(
			$args,
			function ( WPMCP_EL_Document $doc ) {
				$doc->clear();
				return array( 'cleared' => true );
			},
			'clear-page'
		);
	}

	public function build_page( array $args ): array {
		$structure = $args['structure'] ?? array();
		if ( ! is_array( $structure ) || empty( $structure ) ) {
			return array( 'error' => 'structure_required', 'message' => 'Pass at least one container in structure[].' );
		}

		if ( ! empty( $args['post_id'] ) ) {
			$post_id = (int) $args['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				return array( 'error' => 'post_not_found_or_forbidden' );
			}
		} else {
			$title = sanitize_text_field( (string) ( $args['title'] ?? 'Built with MCP' ) );
			$status = in_array( $args['status'] ?? 'draft', array( 'draft', 'publish' ), true ) ? $args['status'] : 'draft';
			$post_id = wp_insert_post(
				array(
					'post_title'  => $title,
					'post_status' => $status,
					'post_type'   => 'page',
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return array( 'error' => $post_id->get_error_message() );
			}
		}

		$before = get_post_meta( $post_id, '_elementor_data', true );
		$doc    = WPMCP_EL_Document::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return array( 'error' => $doc->get_error_code(), 'message' => $doc->get_error_message() );
		}
		if ( empty( $args['post_id'] ) ) {
			$doc->clear();
		}

		$ids = $this->build_tree( $doc, $structure, null );
		if ( isset( $ids['error'] ) ) {
			return $ids;
		}
		$saved  = $doc->save();

		$page_template = (string) ( $args['page_template'] ?? 'default' );
		if ( in_array( $page_template, array( 'canvas', 'full-width', 'default' ), true ) && 'default' !== $page_template ) {
			$settings             = (array) json_decode( (string) get_post_meta( $post_id, '_elementor_page_settings', true ), true );
			$settings['template'] = 'canvas' === $page_template ? 'elementor_canvas' : 'elementor_header_footer';
			// Elementor stores page settings as a serialized array (not JSON).
			update_metadata( 'post', $post_id, '_elementor_page_settings', wp_slash( $settings ) );
			// And mirrors the layout into the WP page-template meta so the template loader picks it up.
			update_metadata( 'post', $post_id, '_wp_page_template', $settings['template'] );
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}

		wpmcp_plugin()->change_log->record( 'elementor', 'build-page', $post_id, get_the_title( $post_id ), sprintf( 'Built %d top-level containers', count( $structure ) ), array( 'data' => $before ), true );

		return array(
			'post_id'    => $post_id,
			'permalink'  => get_permalink( $post_id ),
			'edit_link'  => get_edit_post_link( $post_id, 'raw' ),
			'containers' => $ids,
			'saved'      => (bool) ( $saved['saved'] ?? false ),
		);
	}

	private function build_tree( WPMCP_EL_Document $doc, array $containers, ?string $parent_id ): array {
		$ids = array();
		foreach ( $containers as $i => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$container_id = $doc->add_container(
				(array) ( $definition['settings'] ?? array() ),
				$parent_id,
				null
			);
			if ( is_wp_error( $container_id ) ) {
				return array( 'error' => $container_id->get_error_code(), 'message' => sprintf( 'Container #%d: %s', $i, $container_id->get_error_message() ) );
			}
			$entry = array( 'container_id' => $container_id, 'widgets' => array() );
			foreach ( (array) ( $definition['widgets'] ?? array() ) as $w ) {
				if ( empty( $w['type'] ) ) {
					continue;
				}
				$type = sanitize_text_field( (string) $w['type'] );
				if ( ! \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $type ) ) {
					$entry['widgets'][] = array( 'error' => 'unknown_widget', 'type' => $type );
					continue;
				}
				$widget_id = $doc->add_widget( $type, (array) ( $w['settings'] ?? array() ), $container_id );
				if ( is_wp_error( $widget_id ) ) {
					$entry['widgets'][] = array( 'error' => $widget_id->get_error_message() );
					continue;
				}
				$entry['widgets'][] = array( 'type' => $type, 'widget_id' => $widget_id );
			}
			foreach ( (array) ( $definition['containers'] ?? array() ) as $nested ) {
				$nested_ids = $this->build_tree( $doc, array( $nested ), $container_id );
				if ( isset( $nested_ids['error'] ) ) {
					return $nested_ids;
				}
				$entry['containers'][] = $nested_ids[0];
			}
			$ids[] = $entry;
		}
		return $ids;
	}
}
