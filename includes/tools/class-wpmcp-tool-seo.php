<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_SEO {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;
	private WPMCP_SEO_Manager $seo;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log, WPMCP_SEO_Manager $seo ) {
		$this->registry = $registry;
		$this->log      = $log;
		$this->seo      = $seo;
	}

	public function register(): void {
		$this->registry->register(
			'seo-read',
			array(
				'title'       => 'SEO Read',
				'description' => sprintf( 'Read SEO data through one unified field vocabulary regardless of which SEO plugin is active. Operations: get-post-seo, get-term-seo, get-settings, get-status. Active plugin: %s.', $this->seo->active_label() ),
				'category'    => 'seo',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array(
							'type' => 'string',
							'enum' => array( 'get-post-seo', 'get-term-seo', 'get-settings', 'get-status' ),
							'description' => 'Omit to list available operations',
						),
						'post_id'   => array( 'type' => 'integer', 'description' => 'For get-post-seo' ),
						'term_id'   => array( 'type' => 'integer', 'description' => 'For get-term-seo' ),
						'taxonomy'  => array( 'type' => 'string', 'default' => 'category', 'description' => 'For get-term-seo' ),
					),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'seo-write',
			array(
				'title'       => 'SEO Write',
				'description' => 'Write SEO metadata through the unified field vocabulary: title, description, canonical, noindex, nofollow, focus_keyword, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image. Fields a plugin does not support are reported in unsupported[]. Operations: update-post-seo, update-term-seo.',
				'category'    => 'seo',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array(
							'type' => 'string',
							'enum' => array( 'update-post-seo', 'update-term-seo' ),
							'description' => 'Omit to list available operations',
						),
						'post_id'   => array( 'type' => 'integer' ),
						'term_id'   => array( 'type' => 'integer' ),
						'taxonomy'  => array( 'type' => 'string', 'default' => 'category' ),
						'fields'    => array(
							'type'       => 'object',
							'properties' => array(
								'title'       => array( 'type' => 'string', 'description' => 'SEO title (aim <= 60 chars)' ),
								'description' => array( 'type' => 'string', 'description' => 'Meta description (aim <= 155 chars)' ),
								'canonical'   => array( 'type' => 'string' ),
								'noindex'     => array( 'type' => 'boolean' ),
								'nofollow'    => array( 'type' => 'boolean' ),
								'focus_keyword' => array( 'type' => 'string' ),
								'og_title'    => array( 'type' => 'string' ),
								'og_description' => array( 'type' => 'string' ),
								'og_image'    => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'url' => array( 'type' => 'string' ) ) ),
								'twitter_title' => array( 'type' => 'string' ),
								'twitter_description' => array( 'type' => 'string' ),
								'twitter_image' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'url' => array( 'type' => 'string' ) ) ),
							),
						),
					),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	public function read( array $args ): array {
		$operation = $args['operation'] ?? '';
		switch ( $operation ) {
			case '':
				return array(
					'mode'       => 'read',
					'plugin'     => $this->seo->active_label(),
					'operations' => array(
						array( 'operation' => 'get-post-seo', 'arguments' => array( 'post_id' => 'int' ) ),
						array( 'operation' => 'get-term-seo', 'arguments' => array( 'term_id' => 'int', 'taxonomy' => 'string' ) ),
						array( 'operation' => 'get-settings', 'arguments' => new stdClass() ),
						array( 'operation' => 'get-status', 'arguments' => new stdClass() ),
					),
					'unified_fields' => $this->unified_fields(),
				);
			case 'get-post-seo':
				return $this->seo->get_post_seo( (int) ( $args['post_id'] ?? 0 ) );
			case 'get-term-seo':
				return $this->seo->get_term_seo( (int) ( $args['term_id'] ?? 0 ), sanitize_key( $args['taxonomy'] ?? 'category' ) );
			case 'get-settings':
				return $this->seo->get_settings();
			case 'get-status':
				return $this->seo->status();
			default:
				return array( 'error' => 'unknown_operation', 'known' => array( 'get-post-seo', 'get-term-seo', 'get-settings', 'get-status' ) );
		}
	}

	public function write( array $args ): array {
		$operation = $args['operation'] ?? '';
		$fields    = isset( $args['fields'] ) && is_array( $args['fields'] ) ? $args['fields'] : array();
		if ( '' === $operation ) {
			return array(
				'mode'       => 'write',
				'operations' => array(
					array( 'operation' => 'update-post-seo', 'arguments' => array( 'post_id' => 'int', 'fields' => 'object' ) ),
					array( 'operation' => 'update-term-seo', 'arguments' => array( 'term_id' => 'int', 'taxonomy' => 'string', 'fields' => 'object' ) ),
				),
				'unified_fields' => $this->unified_fields(),
			);
		}
		if ( empty( $fields ) ) {
			return array( 'error' => 'fields_object_required' );
		}
		$supported = $this->seo->active()->supported_fields();
		$unsupported = array_values( array_diff( array_keys( $fields ), $supported ) );

		switch ( $operation ) {
			case 'update-post-seo':
				$post_id = (int) ( $args['post_id'] ?? 0 );
				$post    = get_post( $post_id );
				if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
					return array( 'error' => 'post_not_found_or_forbidden' );
				}
				$before = $this->seo->active()->get_post_seo( $post_id );
				$result = $this->seo->update_post_seo( $post_id, $fields );
				$this->log->record( 'seo', 'update-post-seo', $post_id, $post->post_title, sprintf( 'Updated SEO fields: %s', implode( ', ', $result['updated'] ?? array() ) ), $before, true );
				$result['unsupported'] = $unsupported;
				return $result;
			case 'update-term-seo':
				$term_id  = (int) ( $args['term_id'] ?? 0 );
				$taxonomy = sanitize_key( $args['taxonomy'] ?? 'category' );
				$tax      = get_taxonomy( $taxonomy );
				if ( ! $tax || ! current_user_can( $tax->cap->manage_terms ) ) {
					return array( 'error' => 'term_not_found_or_forbidden' );
				}
				$before = $this->seo->active()->get_term_seo( $term_id, $taxonomy );
				$result = $this->seo->update_term_seo( $term_id, $taxonomy, $fields );
				$this->log->record( 'seo', 'update-term-seo', $term_id, $taxonomy . '#' . $term_id, sprintf( 'Updated term SEO fields: %s', implode( ', ', $result['updated'] ?? array() ) ), $before, true );
				$result['unsupported'] = $unsupported;
				return $result;
			default:
				return array( 'error' => 'unknown_operation', 'known' => array( 'update-post-seo', 'update-term-seo' ) );
		}
	}

	private function unified_fields(): array {
		return array(
			'title'       => 'SEO title',
			'description' => 'Meta description',
			'canonical'   => 'Canonical URL',
			'noindex'     => 'Robots noindex boolean',
			'nofollow'    => 'Robots nofollow boolean',
			'focus_keyword' => 'Target keyword where supported',
			'og_title'    => 'Open Graph title',
			'og_description' => 'Open Graph description',
			'og_image'    => '{ id | url } Open Graph image',
			'twitter_title' => 'Twitter card title',
			'twitter_description' => 'Twitter card description',
			'twitter_image' => '{ id | url } Twitter card image',
		);
	}
}
