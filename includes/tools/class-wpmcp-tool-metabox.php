<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Metabox {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public static function available(): bool {
		return defined( 'RWMB_VERSION' ) && function_exists( 'rwmb_meta' );
	}

	public function register(): void {
		if ( ! self::available() ) {
			return;
		}

		$this->registry->register(
			'metabox-read',
			array(
				'title'       => 'MetaBox Read',
				'description' => 'Read MetaBox data: list-field-groups, get-field-group, get-fields (values for one post). Registers only when MetaBox is active.',
				'category'    => 'metabox',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'list-field-groups', 'get-field-group', 'get-fields' ), 'default' => 'list-field-groups' ),
						'group_id'  => array( 'type' => 'integer', 'description' => 'get-field-group: group post ID' ),
						'post_id'   => array( 'type' => 'integer', 'description' => 'get-fields: post ID' ),
					),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'metabox-write',
			array(
				'title'       => 'MetaBox Write',
				'description' => 'Write MetaBox custom-field values for a post. Unknown field names are skipped; no deletes; ids immutable. Pro only. Registers only when MetaBox is active.',
				'category'    => 'metabox',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'required' => True ),
						'fields'  => array( 'type' => 'object', 'required' => True ),
					),
					'required'   => array( 'post_id', 'fields' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	private function groups(): array {
		if ( post_type_exists( 'meta-box' ) ) {
			return get_posts( array( 'post_type' => 'meta-box', 'numberposts' => 200, 'post_status' => 'publish' ) );
		}
		return array();
	}

	public function read( array $args ): array {
		$operation = (string) ( $args['operation'] ?? 'list-field-groups' );

		if ( 'list-field-groups' === $operation ) {
			$out = array();
			foreach ( $this->groups() as $g ) {
				$out[] = array( 'id' => $g->ID, 'title' => $g->post_title, 'status' => $g->post_status );
			}
			return array( 'total' => count( $out ), 'groups' => $out );
		}

		if ( 'get-field-group' === $operation ) {
			$id  = (int) ( $args['group_id'] ?? 0 );
			$all = array_filter( $this->groups(), static fn( $g ) => (int) $g->ID === $id );
			if ( empty( $all ) ) {
				return array( 'error' => 'group_not_found' );
			}
			$fields = rwmb_get_object_fields( $id, 'post' );
			return array(
				'id'     => $id,
				'title'  => reset( $all )->post_title,
				'fields' => array_map( static fn( $f ) => array( 'id' => $f['id'], 'name' => $f['name'] ?? '', 'label' => $f['label'] ?? '', 'type' => $f['type'] ), array_values( (array) $fields ) ),
			);
		}

		if ( 'get-fields' === $operation ) {
			$post_id = (int) ( $args['post_id'] ?? 0 );
			if ( ! get_post( $post_id ) ) {
				return array( 'error' => 'post_not_found' );
			}
			$values = rwmb_meta( '', array( 'object_type' => 'post' ), $post_id );
			$fields = rwmb_get_object_fields( $post_id, 'post' );
			$named  = array();
			foreach ( (array) $fields as $key => $f ) {
				$named[ $key ] = rwmb_meta( $key, array( 'object_type' => 'post' ), $post_id );
			}
			unset( $values );
			return array( 'post_id' => $post_id, 'fields' => (object) $named );
		}

		return array( 'error' => 'unknown_operation' );
	}

	public function write( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$fields = isset( $args['fields'] ) && is_array( $args['fields'] ) ? $args['fields'] : array();
		if ( empty( $fields ) ) {
			return array( 'error' => 'fields_required' );
		}

		$known = array();
		foreach ( (array) rwmb_get_object_fields( $post_id, 'post' ) as $key => $f ) {
			$known[ $key ] = $f;
		}

		$updated = array(); $skipped = array();
		foreach ( $fields as $name => $value ) {
			$name = sanitize_text_field( (string) $name );
			if ( ! isset( $known[ $name ] ) ) {
				$skipped[] = $name;
				continue;
			}
			update_post_meta( $post_id, $name, $value );
			$updated[] = $name;
		}
		$this->log->record( 'metabox', 'update-fields', $post_id, $post->post_title, sprintf( 'Updated %d MetaBox field(s)', count( $updated ) ), array(), false );
		return array( 'ok' => true, 'post_id' => $post_id, 'updated' => $updated, 'skipped_unknown' => $skipped );
	}
}
