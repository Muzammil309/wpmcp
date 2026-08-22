<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_EL_Document {

	public int $post_id;
	private array $elements;
	private bool $dirty = false;

	public static function available(): bool {
		return defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' );
	}

	public static function load( int $post_id ): self|WP_Error {
		if ( ! self::available() ) {
			return new WP_Error( 'wpmcp_elementor_missing', 'Elementor is not installed or active.' );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'wpmcp_post_missing', 'Post not found.' );
		}
		$doc      = new self();
		$doc->post_id = $post_id;
		$raw      = get_post_meta( $post_id, '_elementor_data', true );
		$decoded  = json_decode( (string) $raw, true );
		$doc->elements = is_array( $decoded ) ? $decoded : array();
		return $doc;
	}

	public function structure(): array {
		return $this->normalize_list( $this->elements, array() );
	}

	private function normalize_list( array $items, array $path ): array {
		$out = array();
		foreach ( array_values( $items ) as $i => $element ) {
			$node_path = array_merge( $path, array( $i ) );
			$node      = array(
				'id'     => (string) ( $element['id'] ?? '' ),
				'elType' => (string) ( $element['elType'] ?? '' ),
				'path'   => $node_path,
			);
			if ( ! empty( $element['widgetType'] ) ) {
				$node['widgetType'] = (string) $element['widgetType'];
			}
			if ( ! empty( $element['settings'] ) ) {
				$node['settings'] = $this->summarize_settings( (array) $element['settings'] );
			}
			if ( ! empty( $element['elements'] ) ) {
				$node['children'] = $this->normalize_list( $element['elements'], $node_path );
			}
			$out[] = $node;
		}
		return $out;
	}

	private function summarize_settings( array $settings ): array {
		$skip = array( '__globals__', '__dynamic__' );
		$out  = array();
		$n    = 0;
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			$out[ $key ] = is_scalar( $value ) || null === $value ? $value : '{…}';
			if ( ++$n >= 12 ) {
				$out['…more_settings'] = count( $settings ) - $n;
				break;
			}
		}
		return $out;
	}

	public function gen_id(): string {
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( null !== $this->find( $id ) );
		return $id;
	}

	public function find( string $element_id, ?array &$parent_list = null, ?int &$index = null ): ?array {
		return $this->search( $this->elements, $element_id, $parent_list, $index );
	}

	private function search( array $list, string $element_id, ?array &$parent_list, ?int &$index ): ?array {
		foreach ( $list as $i => $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $element_id ) {
				$parent_list_ref = null;
				$parent_list     = $list;
				$index           = (int) $i;
				return $element;
			}
			if ( ! empty( $element['elements'] ) ) {
				$found = $this->search( $element['elements'], $element_id, $parent_list, $index );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	public function add_container( array $settings = array(), ?string $parent_id = null, ?int $index = null ): string|WP_Error {
		$element = array(
			'id'       => $this->gen_id(),
			'elType'   => 'container',
			'settings' => $settings,
			'elements' => array(),
			'isInner'  => false,
		);
		$placed = $this->place( $element, $parent_id, $index, array( 'container', 'section', 'column', 'root' ) );
		if ( is_wp_error( $placed ) ) {
			return $placed;
		}
		return $element['id'];
	}

	public function add_widget( string $widget_type, array $settings = array(), ?string $container_id = null, ?int $index = null ): string|WP_Error {
		$element = array(
			'id'         => $this->gen_id(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
			'elements'   => array(),
		);
		$parent_id = $container_id;
		if ( null === $parent_id ) {
			$parent_id = $this->first_container_id();
		}
		$placed = $this->place( $element, $parent_id, $index, array( 'container', 'column', 'section' ) );
		if ( is_wp_error( $placed ) ) {
			return $placed;
		}
		return $element['id'];
	}

	private function first_container_id(): ?string {
		foreach ( $this->elements as $element ) {
			if ( in_array( $element['elType'] ?? '', array( 'container', 'section' ), true ) ) {
				return (string) $element['id'];
			}
		}
		return null;
	}

	private function place( array $element, ?string $parent_id, ?int $index, array $allowed_parents ): string|WP_Error {
		if ( null === $parent_id ) {
			if ( null === $index ) {
				$this->elements[] = $element;
			} else {
				$index = max( 0, min( count( $this->elements ), (int) $index ) );
				array_splice( $this->elements, $index, 0, array( $element ) );
			}
			$this->dirty = true;
			return true;
		}
		$parent = $this->find( $parent_id );
		if ( null === $parent ) {
			return new WP_Error( 'wpmcp_parent_missing', sprintf( 'Parent element %s not found.', $parent_id ) );
		}
		if ( ! in_array( $parent['elType'] ?? '', $allowed_parents, true ) ) {
			return new WP_Error( 'wpmcp_bad_parent', sprintf( 'Element %s is a %s; cannot nest there.', $parent_id, $parent['elType'] ) );
		}
		$ref  =& $this->find_ref_by_id( $this->elements, $parent_id );
		if ( null === $ref ) {
			return new WP_Error( 'wpmcp_parent_missing', 'Parent reference lost.' );
		}
		$index = max( 0, min( count( $ref['elements'] ?? array() ), (int) ( $index ?? count( $ref['elements'] ?? array() ) ) ) );
		if ( ! isset( $ref['elements'] ) ) {
			$ref['elements'] = array();
		}
		array_splice( $ref['elements'], $index, 0, array( $element ) );
		$this->dirty = true;
		return true;
	}

	public function get_elements(): array {
		return $this->elements;
	}

	public function replace_root( array $elements ): void {
		foreach ( $elements as &$element ) {
			if ( is_array( $element ) && empty( $element['id'] ) ) {
				$element['id'] = $this->gen_id();
			}
		}
		unset( $element );
		$this->elements = $elements;
		$this->dirty    = true;
	}

	public function import_elements( array $source_elements ): array {
		$ids = array();
		foreach ( $source_elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$clone            = $this->reid( $element );
			$this->elements[] = $clone;
			$ids[]            = (string) $clone['id'];
		}
		if ( ! empty( $ids ) ) {
			$this->dirty = true;
		}
		return $ids;
	}

	public function &find_element_ref( string $element_id ) {
		return $this->find_ref_by_id( $this->elements, $element_id );
	}

	private function &find_ref_by_id( array &$list, string $element_id ) {
		$count = count( $list );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( (string) ( $list[ $i ]['id'] ?? '' ) === $element_id ) {
				return $list[ $i ];
			}
			if ( ! empty( $list[ $i ]['elements'] ) && is_array( $list[ $i ]['elements'] ) ) {
				$found =& $this->find_ref_by_id( $list[ $i ]['elements'], $element_id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		$null = null;
		return $null;
	}

	public function update_element( string $element_id, array $settings, bool $replace = false ): bool|WP_Error {
		$ref =& $this->find_ref_by_id( $this->elements, $element_id );
		if ( null === $ref ) {
			return new WP_Error( 'wpmcp_element_missing', sprintf( 'Element %s not found.', $element_id ) );
		}
		$ref['settings'] = $replace ? $settings : array_merge( (array) ( $ref['settings'] ?? array() ), $settings );
		$this->dirty     = true;
		return true;
	}

	public function remove_element( string $element_id ): bool|WP_Error {
		if ( $this->remove_from_list( $this->elements, $element_id ) ) {
			$this->dirty = true;
			return true;
		}
		return new WP_Error( 'wpmcp_element_missing', sprintf( 'Element %s not found.', $element_id ) );
	}

	private function remove_from_list( array &$list, string $element_id ): bool {
		$count = count( $list );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( (string) ( $list[ $i ]['id'] ?? '' ) === $element_id ) {
				array_splice( $list, $i, 1 );
				return true;
			}
			if ( ! empty( $list[ $i ]['elements'] ) && is_array( $list[ $i ]['elements'] ) && $this->remove_from_list( $list[ $i ]['elements'], $element_id ) ) {
				return true;
			}
		}
		return false;
	}

	public function duplicate_element( string $element_id ): string|WP_Error {
		$original = $this->find( $element_id );
		if ( null === $original ) {
			return new WP_Error( 'wpmcp_element_missing', sprintf( 'Element %s not found.', $element_id ) );
		}
		$clone = $this->reid( $original );
		$list  =& $this->containing_list( $this->elements, $element_id );
		if ( null === $list ) {
			return new WP_Error( 'wpmcp_element_missing', 'Sibling list lost.' );
		}
		$index = $this->index_in_list( $list, $element_id );
		array_splice( $list, $index + 1, 0, array( $clone ) );
		$this->dirty = true;
		return $clone['id'];
	}

	private function reid( array $element ): array {
		$element['id'] = $this->gen_id();
		if ( ! empty( $element['elements'] ) ) {
			$element['elements'] = array_map( array( $this, 'reid' ), $element['elements'] );
		}
		return $element;
	}

	private function &containing_list( array &$list, string $element_id ) {
		$count = count( $list );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( (string) ( $list[ $i ]['id'] ?? '' ) === $element_id ) {
				return $list;
			}
			if ( ! empty( $list[ $i ]['elements'] ) && is_array( $list[ $i ]['elements'] ) ) {
				$found =& $this->containing_list( $list[ $i ]['elements'], $element_id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		$null = null;
		return $null;
	}

	private function index_in_list( array $list, string $element_id ): int {
		foreach ( $list as $i => $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $element_id ) {
				return (int) $i;
			}
			if ( ! empty( $element['elements'] ) ) {
				$found = $this->index_in_list( $element['elements'], $element_id );
				if ( -1 !== $found ) {
					return $found;
				}
			}
		}
		return -1;
	}

	public function reorder_children( string $container_id, array $ordered_ids ): bool|WP_Error {
		$ref =& $this->find_ref_by_id( $this->elements, $container_id );
		if ( null === $ref ) {
			return new WP_Error( 'wpmcp_element_missing', sprintf( 'Element %s not found.', $container_id ) );
		}
		$children = (array) ( $ref['elements'] ?? array() );
		$by_id    = array();
		foreach ( $children as $child ) {
			$by_id[ (string) ( $child['id'] ?? '' ) ] = $child;
		}
		$ordered = array();
		foreach ( $ordered_ids as $id ) {
			$id = (string) $id;
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
				unset( $by_id[ $id ] );
			}
		}
		$ref['elements'] = array_values( array_merge( $ordered, array_values( $by_id ) ) );
		$this->dirty     = true;
		return true;
	}

	public function move_element( string $element_id, ?string $parent_id, ?int $index ): bool|WP_Error {
		$element = $this->find( $element_id );
		if ( null === $element ) {
			return new WP_Error( 'wpmcp_element_missing', sprintf( 'Element %s not found.', $element_id ) );
		}
		if ( null !== $parent_id && $this->id_in_subtree( $element, $parent_id ) ) {
			return new WP_Error( 'wpmcp_no_op', 'Cannot move an element inside its own subtree.' );
		}
		$copy = $element;
		$this->remove_element( $element_id );
		$placed = $this->place( $copy, $parent_id, $index, array( 'container', 'section', 'column', 'root' ) );
		if ( is_wp_error( $placed ) ) {
			$this->place( $copy, null, null, array() );
			return $placed;
		}
		return true;
	}

	private function id_in_subtree( array $element, string $needle ): bool {
		if ( (string) ( $element['id'] ?? '' ) === $needle ) {
			return true;
		}
		foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
			if ( $this->id_in_subtree( $child, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public function clear(): void {
		$this->elements = array();
		$this->dirty    = true;
	}

	public function mark_dirty(): void {
		$this->dirty = true;
	}

	public function is_dirty(): bool {
		return $this->dirty;
	}

	public function save(): array {
		if ( ! $this->dirty ) {
			return array( 'saved' => false );
		}
		$saved_via_document = false;
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $this->post_id );
			if ( $document && $document->is_built_with_elementor() ) {
				$result             = $document->save(
					array(
						'elements' => $this->elements,
						'settings' => $document->get_settings(),
					)
				);
				$saved_via_document = ( true === $result );
			}
		}
		if ( ! $saved_via_document ) {
			update_post_meta( $this->post_id, '_elementor_data', wp_slash( wp_json_encode( $this->elements ) ) );
			update_post_meta( $this->post_id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $this->post_id, '_elementor_template_type', 'wp-page' );
			update_post_meta( $this->post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		$this->dirty = false;
		return array( 'saved' => true );
	}

	public function widget_types(): array {
		if ( ! self::available() ) {
			return array();
		}
		$widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
		$out     = array();
		foreach ( $widgets as $widget ) {
			$out[]      = array(
				'name'       => $widget->get_name(),
				'title'      => $widget->get_title(),
				'categories' => $widget->get_categories(),
				'icon'       => $widget->get_icon(),
			);
		}
		return $out;
	}

	public function widget_controls( string $widget_type ): array|WP_Error {
		if ( ! self::available() ) {
			return new WP_Error( 'wpmcp_elementor_missing', 'Elementor is not active.' );
		}
		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget_type );
		if ( ! $widget ) {
			return new WP_Error( 'wpmcp_unknown_widget', sprintf( 'Widget %s is not registered.', $widget_type ) );
		}
		$controls = array();
		foreach ( $widget->get_controls() as $name => $control ) {
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
		return array(
			'name'     => $widget->get_name(),
			'title'    => $widget->get_title(),
			'controls' => $controls,
		);
	}
}
