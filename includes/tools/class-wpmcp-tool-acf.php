<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_ACF {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public static function available(): bool {
		return function_exists( 'get_field' ) && function_exists( 'acf_get_field_groups' );
	}

	public function register(): void {
		if ( ! self::available() ) {
			return;
		}

		$this->registry->register(
			'acf-read',
			array(
				'title'       => 'ACF Read',
				'description' => 'Read Advanced Custom Fields data. Operations: list-field-groups, get-field-group, get-fields (values for one post), list-options-pages. Registers only when ACF is active.',
				'category'    => 'acf',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'list-field-groups', 'get-field-group', 'get-fields', 'list-options-pages' ), 'default' => 'list-field-groups' ),
						'group_id'  => array( 'type' => 'integer', 'description' => 'get-field-group: field group post ID' ),
						'post_id'   => array( 'type' => 'integer', 'description' => 'get-fields: post ID (or options string like "options")' ),
					),
					'required'   => array(),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'acf-write',
			array(
				'title'       => 'ACF Write',
				'description' => 'Write ACF field values for a post (update-fields). Unknown field names are skipped; no deletes; slugs and keys immutable. Pro only.',
				'category'    => 'acf',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'update-fields' ), 'required' => true ),
						'post_id'   => array( 'type' => 'integer', 'required' => true ),
						'fields'    => array( 'type' => 'object', 'description' => 'Field name or key => value', 'required' => true ),
					),
					'required'   => array( 'operation', 'post_id', 'fields' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	public function read( array $args ): array {
		$operation = (string) ( $args['operation'] ?? 'list-field-groups' );

		if ( 'list-field-groups' === $operation ) {
			$groups = acf_get_field_groups();
			return array(
				'total'        => count( (array) $groups ),
				'field_groups' => array_map(
					static fn( $g ) => array(
						'id'         => (int) $g['ID'],
						'key'        => $g['key'],
						'title'      => $g['title'],
						'status'     => $g['active'] ? 'active' : 'inactive',
						'locations'  => count( (array) ( $g['location'] ?? array() ) ),
					),
					(array) $groups
				),
			);
		}

		if ( 'list-options-pages' === $operation ) {
			if ( ! function_exists( 'acf_get_options_page_menus' ) ) {
				return array( 'error' => 'options_pages_unavailable', 'message' => 'Requires ACF Pro.' );
			}
			return array( 'pages' => acf_get_options_page_menus() );
		}

		if ( 'get-field-group' === $operation ) {
			$group_id = (int) ( $args['group_id'] ?? 0 );
			$group    = $group_id > 0 ? acf_get_field_group( $group_id ) : null;
			if ( ! $group ) {
				return array( 'error' => 'field_group_not_found' );
			}
			$fields = acf_get_fields( $group );
			return array(
				'id'     => (int) $group['ID'],
				'key'    => $group['key'],
				'title'  => $group['title'],
				'fields' => array_map(
					static fn( $f ) => array(
						'name' => $f['name'],
						'key'  => $f['key'],
						'label' => $f['label'] ?? '',
						'type' => $f['type'],
						'required' => ! empty( $f['required'] ),
					),
					(array) $fields
				),
			);
		}

		if ( 'get-fields' === $operation ) {
			$post_id = isset( $args['post_id'] ) && is_string( $args['post_id'] ) ? sanitize_text_field( wp_unslash( $args['post_id'] ) ) : (int) ( $args['post_id'] ?? 0 );
			if ( is_int( $post_id ) && $post_id > 0 && ! get_post( $post_id ) ) {
				return array( 'error' => 'post_not_found' );
			}
			$fields = get_fields( $post_id );
			return array(
				'post_id' => $post_id,
				'fields'  => is_array( $fields ) ? $fields : new stdClass(),
			);
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

		// Only allow writing to fields that exist in at least one group for this post.
		$known = array();
		foreach ( (array) acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $f ) {
				$known[ $f['name'] ] = $f['key'];
			}
		}

		$updated = array();
		$skipped = array();
		foreach ( $fields as $name => $value ) {
			$name = sanitize_text_field( (string) $name );
			if ( ! isset( $known[ $name ] ) && ! in_array( $name, $known, true ) ) {
				$skipped[] = $name;
				continue;
			}
			update_field( $known[ $name ], $value, $post_id ); // by key: safest.
			$updated[] = $name;
		}

		$this->log->record( 'acf', 'update-fields', $post_id, $post->post_title, sprintf( 'Updated %d ACF field(s)', count( $updated ) ), array(), false );
		return array(
			'ok'      => true,
			'post_id' => $post_id,
			'updated' => $updated,
			'skipped_unknown' => $skipped,
		);
	}
}
