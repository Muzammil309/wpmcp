<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Elementor_Extra {

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
			'find-element',
			array(
				'title'       => 'Find Element',
				'description' => 'Search a page for elements by widget type, element type or text inside settings. Returns matching element ids and settings previews.',
				'category'    => 'elementor',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer', 'required' => true ),
						'widget_type'  => array( 'type' => 'string', 'description' => 'e.g. heading, button' ),
						'element_type' => array( 'type' => 'string', 'enum' => array( 'container', 'widget' ) ),
						'search_text'  => array( 'type' => 'string', 'description' => 'Case-insensitive match in scalar settings values' ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'find_element' ),
			)
		);

		$this->registry->register(
			'reorder-elements',
			array(
				'title'       => 'Reorder Elements',
				'description' => 'Reorder the children of one container by passing their ids in the desired order. Unlisted children keep positions after the ordered ones.',
				'category'    => 'elementor',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer', 'required' => true ),
						'container_id' => array( 'type' => 'string', 'required' => true ),
						'order'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'required' => true ),
					),
					'required'   => array( 'post_id', 'container_id', 'order' ),
				),
				'handler'     => array( $this, 'reorder_elements' ),
			)
		);

		$this->registry->register(
			'batch-update',
			array(
				'title'       => 'Batch Update',
				'description' => 'Apply settings updates to many elements of one page in a single save. Each operation = { element_id, settings }.',
				'category'    => 'elementor',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer', 'required' => true ),
						'operations' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'element_id' => array( 'type' => 'string' ), 'settings' => array( 'type' => 'object' ) ), 'required' => array( 'element_id', 'settings' ) ), 'required' => true ),
					),
					'required'   => array( 'post_id', 'operations' ),
				),
				'handler'     => array( $this, 'batch_update' ),
			)
		);

		$this->registry->register(
			'export-page',
			array(
				'title'       => 'Export Page',
				'read-only'   => true,
				'description' => "Export a page's full Elementor data (elements + page settings) as JSON for backup or transfer.",
				'category'    => 'elementor',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer', 'required' => true ) ),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'export_page' ),
			)
		);

		$this->registry->register(
			'import-template',
			array(
				'title'       => 'Import Template',
				'description' => 'Import an Elementor JSON structure into a page, replacing its content by default.',
				'category'    => 'elementor',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array( 'type' => 'integer', 'required' => true ),
						'template_json' => array( 'type' => 'array', 'description' => 'Elementor elements array (as produced by export-page)', 'required' => true ),
						'replace_all'   => array( 'type' => 'boolean', 'default' => true, 'description' => 'false appends instead of replacing' ),
					),
					'required'   => array( 'post_id', 'template_json' ),
				),
				'handler'     => array( $this, 'import_template' ),
			)
		);

		$this->registry->register(
			'save-as-template',
			array(
				'title'       => 'Save As Template',
				'description' => "Save a page's Elementor content as a reusable template in the Elementor library.",
				'category'    => 'elementor',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'required' => true ),
						'title'   => array( 'type' => 'string', 'required' => true ),
					),
					'required'   => array( 'post_id', 'title' ),
				),
				'handler'     => array( $this, 'save_as_template' ),
			)
		);

		$this->registry->register(
			'apply-template',
			array(
				'title'       => 'Apply Template',
				'description' => "Append a saved Elementor library template's content to a page with fresh element ids.",
				'category'    => 'elementor',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer', 'required' => true ),
						'template_id' => array( 'type' => 'integer', 'description' => 'Elementor library post ID (from list-templates)', 'required' => true ),
					),
					'required'   => array( 'post_id', 'template_id' ),
				),
				'handler'     => array( $this, 'apply_template' ),
			)
		);

		$this->registry->register(
			'update-global-colors',
			array(
				'title'       => 'Update Global Colors',
				'description' => 'Replace the site-wide Elementor color palette (system + custom colors). Affects every element using global colors.',
				'category'    => 'elementor',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'colors' => array(
							'type'  => 'array',
							'required' => true,
							'items' => array( 'type' => 'object', 'properties' => array( '_id' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ), 'color' => array( 'type' => 'string' ) ), 'required' => array( '_id', 'title', 'color' ) ),
						),
					),
					'required'   => array( 'colors' ),
				),
				'handler'     => array( $this, 'update_global_colors' ),
			)
		);

		$this->registry->register(
			'update-global-typography',
			array(
				'title'       => 'Update Global Typography',
				'description' => 'Replace site-wide Elementor typography presets (primary/secondary/tertiary text etc.).',
				'category'    => 'elementor',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'typography' => array(
							'type'  => 'array',
							'required' => true,
							'items' => array( 'type' => 'object', 'properties' => array( '_id' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ) ), 'required' => array( '_id', 'title' ) ),
						),
					),
					'required'   => array( 'typography' ),
				),
				'handler'     => array( $this, 'update_global_typography' ),
			)
		);
		$this->registry->register(
			'update-page-settings',
			array(
				'title'       => 'Update Page Settings',
				'description' => "Merge settings into a page's Elementor document settings (layout template, custom_css, background etc). Only passed keys change.",
				'category'    => 'elementor',
				'write'       => True,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer', 'required' => True ),
						'settings' => array( 'type' => 'object', 'required' => True ),
						'template' => array( 'type' => 'string', 'enum' => array( 'canvas', 'full-width', 'default' ) ),
					),
					'required'   => array( 'post_id', 'settings' ),
				),
				'handler'     => array( $this, 'update_page_settings' ),
			)
		);

		$this->registry->register(
			'global-classes',
			array(
				'title'       => 'Global Classes (Elementor 4)',
				'description' => 'Manage the Elementor Class Manager design system. Operations: list, create, update, delete (confirm), reorder. Requires Elementor with the atomic-elements experiment active.',
				'category'    => 'elementor',
				'write'       => True,
				'pro'         => True,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'list', 'create', 'update', 'delete', 'reorder' ), 'required' => True ),
						'label'     => array( 'type' => 'string', 'description' => 'create: class label' ),
						'class_id'  => array( 'type' => 'string', 'description' => 'update/delete/reorder target g-id' ),
						'styles'    => array( 'type' => 'object', 'description' => 'create/update: props object e.g. {"color":"#f00"}' ),
						'order'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'reorder: full ordered id list' ),
						'confirm'   => array( 'type' => 'boolean', 'description' => 'delete only' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'global_classes' ),
			)
		);

		$this->registry->register(
			'get-element-settings',
			array(
				'title'       => 'Get Element Settings',
				'description' => 'Full raw settings of one element by id (container or widget), including every stored key.',
				'category'    => 'elementor',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer', 'required' => True ),
						'element_id' => array( 'type' => 'string', 'required' => True ),
					),
					'required'   => array( 'post_id', 'element_id' ),
				),
				'handler'     => array( $this, 'get_element_settings' ),
			)
		);

		$this->registry->register(
			'set-element-label',
			array(
				'title'       => 'Set Element Label',
				'description' => "Set an element's Navigator label so it reads as a human name inside the Elementor editor panel.",
				'category'    => 'elementor',
				'write'       => True,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer', 'required' => True ),
						'element_id' => array( 'type' => 'string', 'required' => True ),
						'label'      => array( 'type' => 'string', 'required' => True ),
					),
					'required'   => array( 'post_id', 'element_id', 'label' ),
				),
				'handler'     => array( $this, 'set_element_label' ),
			)
		);

		$this->registry->register(
			'list-pages',
			array(
				'title'       => 'List Elementor Pages',
				'description' => 'Lists posts/pages/CPTs built with Elementor, newest modified first.',
				'category'    => 'elementor',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'default' => 'page' ),
						'status'    => array( 'type' => 'string', 'default' => 'publish' ),
						'search'    => array( 'type' => 'string' ),
						'per_page'  => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
						'page'      => array( 'type' => 'integer', 'default' => 1 ),
					),
				),
				'handler'     => array( $this, 'list_pages' ),
			)
		);

	}
	public function find_element( array $args ): array {
		$doc = $this->load( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! is_object( $doc ) || is_wp_error( $doc ) ) {
			return is_array( $doc ) ? $doc : array( 'error' => $doc->get_error_code() );
		}
		$widget_type  = strtolower( sanitize_text_field( (string) ( $args['widget_type'] ?? '' ) ) );
		$element_type = strtolower( sanitize_text_field( (string) ( $args['element_type'] ?? '' ) ) );
		$search       = strtolower( sanitize_text_field( (string) ( $args['search_text'] ?? '' ) ) );

		$matches = array();
		$walk    = static function ( array $elements ) use ( &$walk, &$matches, $widget_type, $element_type, $search ) {
			foreach ( $elements as $element ) {
				$is_widget = ( $element['elType'] ?? '' ) === 'widget';
				$type_ok   = '' === $element_type || $element_type === ( $element['elType'] ?? '' );
				$widget_ok = '' === $widget_type || ( $is_widget && strtolower( (string) ( $element['widgetType'] ?? '' ) ) === $widget_type );
				if ( $type_ok && $widget_ok ) {
						$hit_search = true;
						if ( '' !== $search ) {
							$hit_search = false;
							foreach ( (array) ( $element['settings'] ?? array() ) as $key => $value ) {
								if ( '__' === substr( (string) $key, 0, 2 ) ) {
									continue;
								}
								if ( is_scalar( $value ) && str_contains( strtolower( (string) $value ), $search ) ) {
									$hit_search = true;
									break;
								}
							}
						}
						if ( $hit_search ) {
							$preview = array();
							$n = 0;
							foreach ( (array) ( $element['settings'] ?? array() ) as $key => $value ) {
								if ( '__' === substr( (string) $key, 0, 2 ) ) {
									continue;
								}
								$preview[ $key ] = is_scalar( $value ) || null === $value ? $value : '{…}';
								if ( ++$n >= 8 ) {
									break;
								}
							}
							$matches[] = array(
								'id'     => (string) ( $element['id'] ?? '' ),
								'elType' => (string) ( $element['elType'] ?? '' ),
								'widgetType' => $is_widget ? (string) ( $element['widgetType'] ?? '' ) : null,
								'settings_preview' => $preview,
							);
						}
				}
				if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
					$walk( $element['elements'] );
				}
			}
		};
		$walk( $doc->get_elements() );
		return array(
			'post_id'   => $doc->post_id,
			'total'     => count( $matches ),
			'elements'  => array_slice( $matches, 0, 50 ),
			'truncated' => count( $matches ) > 50,
		);
	}

	public function reorder_elements( array $args ): array {
		return $this->mutate(
			$args,
			static function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$result = $doc->reorder_children(
					sanitize_text_field( (string) ( $args['container_id'] ?? '' ) ),
					array_map( 'strval', (array) ( $args['order'] ?? array() ) )
				);
				return is_wp_error( $result )
					? array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() )
					: array( 'reordered' => true );
			},
			'reorder-elements'
		);
	}

	public function batch_update( array $args ): array {
		return $this->mutate(
			$args,
			static function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$updated   = array();
				$failed    = array();
				foreach ( (array) ( $args['operations'] ?? array() ) as $op ) {
					$id         = sanitize_text_field( (string) ( $op['element_id'] ?? '' ) );
					$settings   = (array) ( $op['settings'] ?? array() );
					$result     = $doc->update_element( $id, $settings );
					if ( is_wp_error( $result ) ) {
						$failed[] = array( 'element_id' => $id, 'error' => $result->get_error_code() );
					} else {
						$updated[] = $id;
					}
				}
				return array(
					'updated_elements' => $updated,
					'failed'           => $failed,
				);
			},
			'batch-update'
		);
	}

	public function export_page( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		return array(
			'post_id'       => $post_id,
			'title'         => $post->post_title,
			'version'       => get_post_meta( $post_id, '_elementor_version', true ),
			'template_type' => get_post_meta( $post_id, '_elementor_template_type', true ),
			'page_settings' => $this->page_settings_raw( $post_id ),
			'elements'      => is_array( $data ) ? $data : array(),
		);
	}

	private function page_settings_raw( int $post_id ): array {
		$ps = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( is_array( $ps ) ) {
			return $ps;
		}
		$decoded = json_decode( (string) $ps, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public function import_template( array $args ): array {
		return $this->mutate(
			$args,
			static function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$json        = (array) ( $args['template_json'] ?? array() );
				$replace_all = ! isset( $args['replace_all'] ) || (bool) $args['replace_all'];
				if ( empty( $json ) ) {
					return array( 'error' => 'template_json_required', 'message' => 'Pass a non-empty elements array.' );
				}
				if ( $replace_all ) {
					$doc->clear();
				}
				$ids = $doc->import_elements( $json );
				return array( 'imported_elements' => count( $ids ), 'ids' => $ids );
			},
			'import-template'
		);
	}

	public function save_as_template( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$title   = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		if ( '' === $title ) {
			return array( 'error' => 'title_required' );
		}
		$template_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'elementor_library',
			),
			true
		);
		if ( is_wp_error( $template_id ) ) {
			return array( 'error' => 'save_failed', 'message' => $template_id->get_error_message() );
		}
		foreach ( array( '_elementor_data', '_elementor_page_settings', '_elementor_version', '_elementor_template_type' ) as $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( '' !== $value ) {
				update_post_meta( $template_id, $meta_key, wp_slash( is_string( $value ) ? $value : wp_json_encode( $value ) ) );
			}
		}
		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );
		$this->log->record( 'elementor', 'save-as-template', $template_id, $title, sprintf( 'Saved page %d as template', $post_id ) );
		return array(
			'ok'          => true,
			'template_id' => $template_id,
			'title'       => $title,
		);
	}

	public function apply_template( array $args ): array {
		return $this->mutate(
			$args,
			static function ( WPMCP_EL_Document $doc ) use ( $args ) {
				$template_id = (int) ( $args['template_id'] ?? 0 );
				$source      = get_post( $template_id );
				if ( ! $source || 'elementor_library' !== $source->post_type ) {
					return array( 'error' => 'template_not_found' );
				}
				$data = json_decode( (string) get_post_meta( $template_id, '_elementor_data', true ), true );
				if ( empty( $data ) || ! is_array( $data ) ) {
					return array( 'error' => 'template_empty' );
				}
				$ids = $doc->import_elements( $data );
				return array( 'applied_elements' => count( $ids ), 'ids' => $ids );
			},
			'apply-template'
		);
	}

	public function update_global_colors( array $args ): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array( 'error' => 'wpmcp_elementor_missing' );
		}
		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit ) {
			return array( 'error' => 'kit_unavailable' );
		}
		$colors = (array) ( $args['colors'] ?? array() );
		$clean  = array();
		foreach ( $colors as $color ) {
			$_id    = sanitize_key( (string) ( $color['_id'] ?? '' ) );
			$title  = sanitize_text_field( (string) ( $color['title'] ?? '' ) );
			$value  = sanitize_text_field( (string) ( $color['color'] ?? '' ) );
			if ( '' === $_id || '' === $title || '' === $value ) {
				continue;
			}
			$clean[] = array( '_id' => $_id, 'title' => $title, 'color' => $value );
		}
		if ( count( $clean ) < count( $colors ) ) {
			return array( 'error' => 'invalid_color_entries' );
		}
		$before = $kit->get_settings_for_display( 'custom_colors' );
		$result = $kit->update_settings( array( 'custom_colors' => $clean ) );
		if ( is_wp_error( $result ) ) {
			return array( 'error' => 'update_failed', 'message' => $result->get_error_message() );
		}
		wpmcp_plugin()->change_log->record( 'elementor', 'update-global-colors', 0, 'Global colors', sprintf( 'Updated %d global color(s)', count( $clean ) ), array( 'colors' => $before ), true );
		return array( 'ok' => true, 'updated' => count( $clean ) );
	}

	public function update_global_typography( array $args ): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array( 'error' => 'wpmcp_elementor_missing' );
		}
		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit ) {
			return array( 'error' => 'kit_unavailable' );
		}
		$typography = (array) ( $args['typography'] ?? array() );
		$clean      = array();
		foreach ( $typography as $item ) {
			$_id   = sanitize_key( (string) ( $item['_id'] ?? '' ) );
			$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			if ( '' === $_id || '' === $title ) {
				continue;
			}
			unset( $item['_id'], $item['title'] );
			$entry            = array( '_id' => $_id, 'title' => $title );
			$allowed_settings = array( 'typography_font_family', 'typography_font_size', 'typography_font_weight', 'typography_line_height', 'typography_letter_spacing' );
			foreach ( $allowed_settings as $key ) {
				if ( isset( $item[ $key ] ) ) {
					$entry[ $key ] = is_array( $item[ $key ] ) ? $item[ $key ] : sanitize_text_field( (string) $item[ $key ] );
				}
			}
			$clean[] = $entry;
		}
		$before = $kit->get_settings_for_display( 'system_typography' );
		$result = $kit->update_settings( array( 'system_typography' => $clean ) );
		if ( is_wp_error( $result ) ) {
			return array( 'error' => 'update_failed', 'message' => $result->get_error_message() );
		}
		wpmcp_plugin()->change_log->record( 'elementor', 'update-global-typography', 0, 'Global typography', sprintf( 'Updated %d preset(s)', count( $clean ) ), array( 'typography' => $before ), true );
		return array( 'ok' => true, 'updated' => count( $clean ) );
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
	public function get_element_settings( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$doc = WPMCP_EL_Document::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return array( 'error' => $doc->get_error_code(), 'message' => $doc->get_error_message() );
		}
		$element_id = sanitize_text_field( (string) ( $args['element_id'] ?? '' ) );

		$found = null;
		$walk  = static function ( array $elements ) use ( &$walk, &$found, $element_id ) {
			foreach ( $elements as $el ) {
				if ( $found ) {
					return;
				}
				if ( (string) ( $el['id'] ?? '' ) === $element_id ) {
					$found = $el;
					return;
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
		};
		$walk( $doc->get_elements() );
		if ( empty( $found ) ) {
			return array( 'error' => 'element_not_found' );
		}
		return array(
			'id'         => (string) ( $found['id'] ?? '' ),
			'elType'     => (string) ( $found['elType'] ?? '' ),
			'widgetType' => isset( $found['widgetType'] ) ? (string) $found['widgetType'] : null,
			'settings'   => (object) ( $found['settings'] ?? array() ),
			'children'   => isset( $found['elements'] ) ? count( (array) $found['elements'] ) : 0,
		);
	}

	public function set_element_label( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$doc = WPMCP_EL_Document::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return array( 'error' => $doc->get_error_code(), 'message' => $doc->get_error_message() );
		}
		$element_id = sanitize_text_field( (string) ( $args['element_id'] ?? '' ) );
		$label      = sanitize_text_field( (string) ( $args['label'] ?? '' ) );
		if ( '' === $label ) {
			return array( 'error' => 'label_required' );
		}

		$target =& $doc->find_element_ref( $element_id );
		if ( null === $target ) {
			return array( 'error' => 'element_not_found' );
		}
		$old = (string) ( $target['settings']['_title'] ?? '' );
		$target['settings']['_title']        = $label;
		$target['editor_settings']['title'] = $label;

		$doc->mark_dirty();
		$saved = $doc->save();
		$this->log->record(
			'elementor', 'set-element-label', $post_id, get_the_title( $post_id ),
			sprintf( 'Label: %s -> %s', '' !== $old ? $old : '(none)', $label ),
			array( 'old_label' => $old ), true
		);
		return array( 'ok' => true, 'id' => $element_id, 'label' => $label, 'saved' => (bool) ( $saved['saved'] ?? false ) );
	}

	public function list_pages( array $args ): array {
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$query    = new WP_Query(
			array(
				'post_type'              => sanitize_key( (string) ( $args['post_type'] ?? 'page' ) ),
				'post_status'            => sanitize_key( (string) ( $args['status'] ?? 'publish' ) ),
				's'                      => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'meta_key'               => '_elementor_edit_mode',
				'meta_value'             => 'builder',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$out = array();
		foreach ( $query->posts as $post ) {
			$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
			$out[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'type'      => $post->post_type,
				'modified'  => get_the_modified_date( 'c', $post ),
				'permalink' => get_permalink( $post ),
				'elements'  => is_array( $data ) ? count( $data ) : 0,
			);
		}
		return array( 'total' => (int) $query->found_posts, 'pages' => $out );
	}
	public function update_page_settings( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$settings = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array();
		if ( isset( $args['template'] ) && in_array( $args['template'], array( 'canvas', 'full-width', 'default' ), true ) && 'default' !== $args['template'] ) {
			$settings['template'] = 'canvas' === $args['template'] ? 'elementor_canvas' : 'elementor_header_footer';
		}
		if ( empty( $settings ) ) {
			return array( 'error' => 'settings_required' );
		}
		$before = json_decode( (string) get_post_meta( $post_id, '_elementor_page_settings', true ), true );
		$merged = array_merge( (array) ( is_array( $before ) ? $before : array() ), $settings );

		update_metadata( 'post', $post_id, '_elementor_page_settings', wp_slash( $merged ) );
		if ( ! empty( $settings['template'] ) ) {
			update_metadata( 'post', $post_id, '_wp_page_template', $settings['template'] );
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		wpmcp_plugin()->change_log->record( 'elementor', 'update-page-settings', $post_id, get_the_title( $post_id ), sprintf( 'Updated %d page setting(s)', count( $settings ) ), is_array( $before ) ? $before : array(), true );
		return array( 'ok' => true, 'updated' => array_keys( $settings ) );
	}

	public function global_classes( array $args ): array {
		if ( ! class_exists( '\Elementor\Modules\GlobalClasses\Global_Classes_Repository' ) || ! \Elementor\Plugin::$instance->experiments->is_feature_active( 'e_classes' ) ) {
			return array( 'error' => 'global_classes_unavailable', 'message' => 'Requires Elementor 4.0+ with the e_classes (Global Classes) experiment active.' );
		}
		$repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make();
		$operation = (string) ( $args['operation'] ?? 'list' );

		if ( 'list' === $operation ) {
			$all = $repo->all();
			return array(
				'order'  => $repo->get_order(),
				'labels' => $repo->all_labels(),
				'items'  => $all->get_items(),
			);
		}

		$order = $repo->get_order();
		$touched = array();

		if ( 'create' === $operation ) {
			$label = sanitize_text_field( (string) ( $args['label'] ?? '' ) );
			if ( '' === $label ) {
				return array( 'error' => 'label_required' );
			}
			$new_id = 'g-' . substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
			$item = array(
				'id'       => $new_id,
				'label'    => $label,
				'variants' => array(
					'default' => array( 'props' => (object) ( $args['styles'] ?? array() ) ),
				),
			);
			$touched[ $new_id ] = $item;
			$order[]            = $new_id;
			$repo->apply_changes( $touched, array( 'added' => array( $new_id ) ), $order );

			// Some Elementor contexts skip batch post creation; ensure the post exists.
			if ( null === $repo->get( $new_id ) && class_exists( '\Elementor\Modules\GlobalClasses\Global_Class_Post' ) ) {
				$data = \Elementor\Modules\GlobalClasses\Utils\Global_Class_Data_Normalizer::normalize_style_fields( $item );
				\Elementor\Modules\GlobalClasses\Global_Class_Post::create( $new_id, $label, $data, null );
			}
			wpmcp_plugin()->change_log->record( 'elementor', 'create-global-class', 0, $label, sprintf( 'Created global class %s (%s)', $new_id, $label ) );
			return array( 'ok' => true, 'class_id' => $new_id, 'item' => $item );
		}

		$class_id = sanitize_text_field( (string) ( $args['class_id'] ?? '' ) );

		if ( 'update' === $operation ) {
			$current = $repo->get( $class_id );
			if ( null === $current ) {
				return array( 'error' => 'class_not_found' );
			}
			if ( isset( $args['label'] ) ) {
				$current['label'] = sanitize_text_field( (string) $args['label'] );
			}
			if ( isset( $args['styles'] ) && is_array( $args['styles'] ) ) {
				$current['variants']['default']['props'] = $args['styles'];
			}
			$touched[ $class_id ] = $current;
			$repo->apply_changes( $touched, array( 'modified' => array( $class_id ) ), $order );
			wpmcp_plugin()->change_log->record( 'elementor', 'update-global-class', 0, $class_id, sprintf( 'Updated global class %s', $class_id ), $current, true );
			return array( 'ok' => true, 'class_id' => $class_id );
		}

		if ( 'delete' === $operation ) {
			if ( empty( $args['confirm'] ) ) {
				return array( 'error' => 'confirm_required' );
			}
			if ( null === $repo->get( $class_id ) ) {
				return array( 'error' => 'class_not_found' );
			}
			$order = array_values( array_diff( $order, array( $class_id ) ) );
			$repo->apply_changes( array(), array( 'deleted' => array( $class_id ) ), $order );
			wpmcp_plugin()->change_log->record( 'elementor', 'delete-global-class', 0, $class_id, sprintf( 'Deleted global class %s', $class_id ) );
			return array( 'deleted' => true, 'class_id' => $class_id );
		}

		if ( 'reorder' === $operation ) {
			$new_order = array_map( 'strval', (array) ( $args['order'] ?? array() ) );
			if ( empty( $new_order ) ) {
				return array( 'error' => 'order_required' );
			}
			$repo->apply_changes( array(), array( 'order' => true ), $new_order );
			wpmcp_plugin()->change_log->record( 'elementor', 'reorder-global-classes', 0, 'Class manager', sprintf( 'Reordered %d classes', count( $new_order ) ) );
			return array( 'ok' => true, 'order' => $new_order );
		}

		return array( 'error' => 'unknown_operation' );
	}
}
