<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Comments {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	private const STATUSES = array( 'approve', 'hold', 'spam', 'trash' );

	public function register(): void {
		$this->registry->register(
			'comment-read',
			array(
				'title'       => 'Comments Read',
				'description' => 'List and read comments. Operations: list-comments (filter by status/post/search), get-comment. Author email is included only for users who can moderate.',
				'category'    => 'comments',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'list-comments', 'get-comment' ), 'default' => 'list-comments' ),
						'status'    => array( 'type' => 'string', 'enum' => array( 'all', 'hold', 'approve', 'spam', 'trash' ), 'default' => 'all' ),
						'post_id'   => array( 'type' => 'integer' ),
						'search'    => array( 'type' => 'string' ),
						'id'        => array( 'type' => 'integer', 'description' => 'get-comment only' ),
						'per_page'  => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
						'page'      => array( 'type' => 'integer', 'default' => 1 ),
					),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'comment-write',
			array(
				'title'       => 'Comments Write',
				'description' => 'Moderate and create comments. Operations: create-comment (held for review by default), reply, set-status (approve/hold/spam/trash), delete (confirm:true; permanent). Status changes are recorded to the change ledger with the prior status.',
				'category'    => 'comments',
				'write'       => true,
				'pro'         => false,
				'capability'  => 'moderate_comments',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation'   => array( 'type' => 'string', 'enum' => array( 'create-comment', 'reply', 'set-status', 'delete' ), 'required' => true ),
						'post_id'     => array( 'type' => 'integer', 'description' => 'create-comment only' ),
						'parent_id'   => array( 'type' => 'integer', 'description' => 'reply only: parent comment id' ),
						'id'          => array( 'type' => 'integer', 'description' => 'set-status/delete target comment id' ),
						'content'     => array( 'type' => 'string', 'description' => 'create-comment/reply body' ),
						'author_name' => array( 'type' => 'string', 'description' => 'create-comment only' ),
						'author_email' => array( 'type' => 'string', 'description' => 'create-comment only' ),
						'author_url'  => array( 'type' => 'string', 'description' => 'create-comment only' ),
						'approve_now' => array( 'type' => 'boolean', 'default' => false, 'description' => 'create-comment/reply: approve immediately instead of holding' ),
						'status'      => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash' ), 'description' => 'set-status only' ),
						'confirm'     => array( 'type' => 'boolean', 'description' => 'delete only' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	private function status_of( WP_Comment $c ): string {
		if ( '1' === (string) $c->comment_approved ) {
			return 'approve';
		}
		if ( '0' === (string) $c->comment_approved ) {
			return 'hold';
		}
		return (string) $c->comment_approved;
	}

	private function payload( WP_Comment $c ): array {
		$can_moderate = current_user_can( 'moderate_comments' );
		$post         = get_post( (int) $c->comment_post_ID );
		return array(
			'id'             => (int) $c->comment_ID,
			'post_id'        => (int) $c->comment_post_ID,
			'post_title'     => $post ? $post->post_title : null,
			'parent_id'      => (int) $c->comment_parent,
			'author_name'    => $c->comment_author,
			'author_email'   => $can_moderate ? $c->comment_author_email : null,
			'author_url'     => $c->comment_author_url,
			'author_user_id' => (int) $c->user_id,
			'date'           => get_comment_date( 'c', $c ),
			'content'        => $c->comment_content,
			'status'         => $this->status_of( $c ),
		);
	}

	private function can_view( WP_Comment $c ): bool {
		$post = get_post( (int) $c->comment_post_ID );
		return $post && current_user_can( 'edit_post', $post->ID );
	}

	public function read( array $args ): array {
		$operation = (string) ( $args['operation'] ?? 'list-comments' );

		if ( 'get-comment' === $operation ) {
			$c = get_comment( (int) ( $args['id'] ?? 0 ) );
			if ( ! $c instanceof WP_Comment || ! $this->can_view( $c ) ) {
				return array( 'error' => 'comment_not_found_or_forbidden' );
			}
			return $this->payload( $c );
		}

		$status = sanitize_key( (string) ( $args['status'] ?? 'all' ) );
		if ( ! in_array( $status, array( 'all', 'hold', 'approve', 'spam', 'trash' ), true ) ) {
			$status = 'all';
		}
		$query = new WP_Comment_Query(
			array(
				'status'  => $status,
				'post_id' => isset( $args['post_id'] ) ? (int) $args['post_id'] : null,
				'search'  => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
				'number'  => min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
				'offset'  => ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
				'order'   => 'DESC',
				'orderby' => 'comment_date_gmt',
			)
		);
		$comments = array();
		foreach ( $query->comments as $c ) {
			if ( $this->can_view( $c ) ) {
				$comments[] = $this->payload( $c );
			}
		}
		return array( 'total' => count( $comments ), 'comments' => $comments );
	}

	public function write( array $args ): array {
		$operation = (string) ( $args['operation'] ?? '' );

		if ( 'create-comment' === $operation || 'reply' === $operation ) {
			$parent_id = 0;
			if ( 'reply' === $operation ) {
				$parent_id = (int) ( $args['parent_id'] ?? 0 );
				$parent    = get_comment( $parent_id );
				if ( ! $parent instanceof WP_Comment ) {
					return array( 'error' => 'parent_not_found' );
				}
				$post_id = (int) $parent->comment_post_ID;
			} else {
				$post_id = (int) ( $args['post_id'] ?? 0 );
			}
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				return array( 'error' => 'post_not_found_or_forbidden' );
			}
			$content = trim( (string) ( $args['content'] ?? '' ) );
			if ( '' === $content ) {
				return array( 'error' => 'content_required' );
			}
			$user     = wp_get_current_user();
			$approve  = ! empty( $args['approve_now'] ) && current_user_can( 'moderate_comments' );
			$approved = $approve ? 1 : 0;
			$comment_id = wp_insert_comment(
				array(
					'comment_post_ID'      => $post_id,
					'comment_parent'       => $parent_id,
					'comment_content'      => $content,
					'comment_author'       => sanitize_text_field( (string) ( $args['author_name'] ?? ( $user->exists() ? $user->display_name : '' ) ) ),
					'comment_author_email' => sanitize_email( (string) ( $args['author_email'] ?? ( $user->exists() ? $user->user_email : '' ) ) ),
					'comment_author_url'   => esc_url_raw( (string) ( $args['author_url'] ?? '' ) ),
					'user_id'              => $user->ID ?: 0,
					'comment_approved'     => $approved,
					'comment_date'         => current_time( 'mysql' ),
					'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s' ),
					'comment_agent'        => 'WP MCP Suite',
				)
			);
			if ( ! $comment_id ) {
				return array( 'error' => 'insert_failed' );
			}
			$this->log->record( 'comments', 'create-comment', (int) $comment_id, sprintf( '#%d on %s', $comment_id, $post->post_title ), sprintf( 'Created comment #%d on post %d (%s)', $comment_id, $post_id, $approved ? 'approved' : 'held' ) );
			$c = get_comment( $comment_id );
			return array( 'ok' => true, 'id' => (int) $comment_id, 'status' => $this->status_of( $c ) );
		}

		if ( 'set-status' === $operation ) {
			$id     = (int) ( $args['id'] ?? 0 );
			$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return array( 'error' => 'status_required', 'allowed' => self::STATUSES );
			}
			$c = get_comment( $id );
			if ( ! $c instanceof WP_Comment ) {
				return array( 'error' => 'comment_not_found' );
			}
			$old = $this->status_of( $c );
			if ( $old === $status ) {
				return array( 'ok' => true, 'id' => $id, 'status' => $status, 'changed' => false );
			}
			if ( ! wp_set_comment_status( $id, $status ) ) {
				return array( 'error' => 'status_change_failed' );
			}
			clean_comment_cache( $id );
			$this->log->record( 'comments', 'set-comment-status', $id, sprintf( '#%d', $id ), sprintf( 'Comment #%d: %s -> %s', $id, $old, $status ), array( 'status' => $old ), true );
			return array( 'ok' => true, 'id' => $id, 'status' => $status, 'previous' => $old );
		}

		if ( 'delete' === $operation ) {
			if ( empty( $args['confirm'] ) ) {
				return array( 'error' => 'confirm_required' );
			}
			$id = (int) ( $args['id'] ?? 0 );
			$c  = get_comment( $id );
			if ( ! $c instanceof WP_Comment ) {
				return array( 'error' => 'comment_not_found' );
			}
			$before = $this->payload( $c );
			if ( ! wp_delete_comment( $id, true ) ) {
				return array( 'error' => 'delete_failed' );
			}
			$this->log->record( 'comments', 'delete-comment', $id, sprintf( '#%d on post %d', $id, $before['post_id'] ), sprintf( 'Permanently deleted comment #%d', $id ), array( 'before' => $before ), false );
			return array( 'ok' => true, 'deleted' => $id );
		}

		return array( 'error' => 'unknown_operation' );
	}
}
