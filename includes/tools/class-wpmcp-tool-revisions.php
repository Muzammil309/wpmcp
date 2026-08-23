<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Revisions {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'revision-read',
			array(
				'title'       => 'Revisions Read',
				'description' => 'List and inspect post revisions. Operations: list-revisions, get-revision (full revision fields side by side with the current values).',
				'category'    => 'revisions',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation'   => array( 'type' => 'string', 'enum' => array( 'list-revisions', 'get-revision' ), 'default' => 'list-revisions' ),
						'post_id'     => array( 'type' => 'integer', 'description' => 'list-revisions only' ),
						'revision_id' => array( 'type' => 'integer', 'description' => 'get-revision only' ),
					),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'restore-revision',
			array(
				'title'       => 'Restore Revision',
				'description' => 'Restore a post to a saved revision. The current title/content/excerpt/status are captured in the change ledger first and can be rolled back via rollback-change. Destructive; requires confirm:true.',
				'category'    => 'revisions',
				'write'       => true,
				'confirm'     => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'revision_id' => array( 'type' => 'integer', 'required' => true ),
						'confirm'     => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'revision_id', 'confirm' ),
				),
				'handler'     => array( $this, 'restore' ),
			)
		);
	}

	private function revision_payload( WP_Post $rev ): array {
		return array(
			'id'        => (int) $rev->ID,
			'parent_id' => (int) $rev->post_parent,
			'title'     => $rev->post_title,
			'excerpt'   => $rev->post_excerpt,
			'content'   => $rev->post_content,
			'date'      => get_the_date( 'c', $rev ),
			'author_id' => (int) $rev->post_author,
		);
	}

	public function read( array $args ): array {
		$operation = (string) ( $args['operation'] ?? 'list-revisions' );

		if ( 'get-revision' === $operation ) {
			$rev = get_post( (int) ( $args['revision_id'] ?? 0 ) );
			if ( ! $rev || 'revision' !== $rev->post_type ) {
				return array( 'error' => 'revision_not_found' );
			}
			if ( ! current_user_can( 'edit_post', (int) $rev->post_parent ) ) {
				return array( 'error' => 'forbidden' );
			}
			$parent = get_post( (int) $rev->post_parent );
			return array(
				'revision' => $this->revision_payload( $rev ),
				'current'  => $parent ? array(
					'id'      => (int) $parent->ID,
					'title'   => $parent->post_title,
					'excerpt' => $parent->post_excerpt,
					'content' => $parent->post_content,
					'status'  => $parent->post_status,
				) : null,
			);
		}

		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		if ( ! wp_revisions_enabled( $post ) ) {
			return array( 'revisions_enabled' => false, 'total' => 0, 'revisions' => array() );
		}
		$revisions = wp_get_post_revisions( $post_id );
		$out       = array();
		foreach ( $revisions as $rev ) {
			$out[] = array(
				'id'             => (int) $rev->ID,
				'parent_id'      => (int) $rev->post_parent,
				'title'          => $rev->post_title,
				'date'           => get_the_date( 'c', $rev ),
				'author_id'      => (int) $rev->post_author,
				'content_length' => strlen( (string) $rev->post_content ),
				'preview'        => mb_substr( wp_strip_all_tags( (string) $rev->post_content ), 0, 200 ),
			);
		}
		return array( 'revisions_enabled' => true, 'total' => count( $out ), 'revisions' => $out );
	}

	public function restore( array $args ): array {
		$revision_id = (int) ( $args['revision_id'] ?? 0 );
		$rev         = get_post( $revision_id );
		if ( ! $rev || 'revision' !== $rev->post_type ) {
			return array( 'error' => 'revision_not_found' );
		}
		$parent_id = (int) $rev->post_parent;
		if ( ! $parent_id || ! current_user_can( 'edit_post', $parent_id ) ) {
			return array( 'error' => 'forbidden' );
		}
		$parent = get_post( $parent_id );
		if ( ! $parent ) {
			return array( 'error' => 'post_not_found' );
		}
		$before = array(
			'title'   => $parent->post_title,
			'content' => $parent->post_content,
			'status'  => $parent->post_status,
		);

		$restored = wp_restore_post_revision( $revision_id );
		if ( is_wp_error( $restored ) || ! $restored ) {
			return array( 'error' => 'restore_failed', 'message' => is_wp_error( $restored ) ? $restored->get_error_message() : null );
		}
		clean_post_cache( $parent_id );

		$this->log->record(
			'content',
			'restore-revision',
			$parent_id,
			get_the_title( $parent_id ),
			sprintf( 'Restored post %d to revision %d (%s)', $parent_id, $revision_id, get_the_date( 'c', $rev ) ),
			$before,
			true
		);
		$fresh = get_post( $parent_id );
		return array(
			'ok'          => true,
			'post_id'     => $parent_id,
			'restored_to' => array(
				'title'   => $fresh->post_title,
				'content' => $fresh->post_content,
			),
		);
	}
}
