<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Content {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'list-posts',
			array(
				'title'       => 'List Posts',
				'description' => 'List posts, pages or any public post type. Filter by status, search term, author. Returns id, title, status, type, date and permalink.',
				'category'    => 'content',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'default' => 'post', 'description' => 'Post type slug, e.g. post, page' ),
						'status'    => array( 'type' => 'string', 'default' => 'publish', 'description' => 'publish, draft, pending, private, future, any' ),
						'search'    => array( 'type' => 'string', 'description' => 'Search keyword matched against title and content' ),
						'per_page'  => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
						'page'      => array( 'type' => 'integer', 'default' => 1 ),
						'orderby'   => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'id' ), 'default' => 'date' ),
						'order'     => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
					),
				),
				'handler'     => array( $this, 'list_posts' ),
			)
		);

		$this->registry->register(
			'get-post',
			array(
				'title'       => 'Get Post',
				'description' => 'Read one post in full: content (raw + rendered), excerpt, status, taxonomies, featured image, meta and the active SEO plugin data.',
				'category'    => 'content',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer', 'description' => 'Post ID' ),
						'raw_content' => array( 'type' => 'boolean', 'default' => true, 'description' => 'Include raw post_content' ),
					),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'get_post' ),
			)
		);

		$this->registry->register(
			'create-post',
			array(
				'title'       => 'Create Post',
				'description' => 'Create a post, page or custom post type with title, HTML content, excerpt, status, slug, date, categories, tags and featured image.',
				'category'    => 'content',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'       => array( 'type' => 'string' ),
						'content'     => array( 'type' => 'string', 'description' => 'HTML body content' ),
						'excerpt'     => array( 'type' => 'string' ),
						'post_type'   => array( 'type' => 'string', 'default' => 'post' ),
						'status'      => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private' ), 'default' => 'draft' ),
						'slug'        => array( 'type' => 'string' ),
						'date'        => array( 'type' => 'string', 'description' => 'ISO 8601 date, e.g. 2026-08-21T10:00:00' ),
						'author_id'   => array( 'type' => 'integer' ),
						'categories'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category IDs (posts)' ),
						'tags'        => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Tag IDs (posts)' ),
						'featured_image_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'title' ),
				),
				'handler'     => array( $this, 'create_post' ),
			)
		);

		$this->registry->register(
			'update-post',
			array(
				'title'       => 'Update Post',
				'description' => 'Update any subset of a post: title, content, excerpt, status, slug, categories, tags, featured image. Only passed fields change.',
				'category'    => 'content',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'content'     => array( 'type' => 'string' ),
						'excerpt'     => array( 'type' => 'string' ),
						'status'      => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private', 'future', 'trash' ) ),
						'slug'        => array( 'type' => 'string' ),
						'categories'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'tags'        => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'featured_image_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'update_post' ),
			)
		);

		$this->registry->register(
			'delete-post',
			array(
				'title'       => 'Delete Post',
				'description' => 'Trash a post (default) or delete permanently when force:true. Destructive; requires confirm:true.',
				'category'    => 'content',
				'write'       => true,
				'confirm'     => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'force'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'true bypasses trash and deletes permanently' ),
						'confirm' => array( 'type' => 'boolean', 'description' => 'Must be true to run this destructive tool' ),
					),
					'required'   => array( 'id', 'confirm' ),
				),
				'handler'     => array( $this, 'delete_post' ),
			)
		);

		$this->registry->register(
			'list-terms',
			array(
				'title'       => 'List Terms',
				'description' => 'List categories, tags or any public taxonomy terms.',
				'category'    => 'content',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => array( 'type' => 'string', 'default' => 'category' ),
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'default' => 50, 'maximum' => 100 ),
					),
				),
				'handler'     => array( $this, 'list_terms' ),
			)
		);

		$this->registry->register(
			'create-term',
			array(
				'title'       => 'Create Term',
				'description' => 'Create a category, tag or taxonomy term.',
				'category'    => 'content',
				'write'       => true,
				'capability'  => 'manage_categories',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'     => array( 'type' => 'string' ),
						'taxonomy' => array( 'type' => 'string', 'default' => 'category' ),
						'slug'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'name' ),
				),
				'handler'     => array( $this, 'create_term' ),
			)
		);

		$this->registry->register(
			'list-post-types',
			array(
				'title'       => 'List Post Types',
				'description' => 'Registered public post types with labels, hierarchy flags and published counts.',
				'category'    => 'content',
				'capability'  => 'edit_posts',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'handler'     => array( $this, 'list_post_types' ),
			)
		);

		$this->registry->register(
			'list-taxonomies',
			array(
				'title'       => 'List Taxonomies',
				'description' => 'Registered taxonomies with labels and object types; optionally include their terms.',
				'category'    => 'content',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'include_terms' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Include each taxonomy terms list (name, slug, count)' ),
					),
				),
				'handler'     => array( $this, 'list_taxonomies' ),
			)
		);
	}

	public function list_posts( array $args ): array {
		$query = new WP_Query(
			array(
				'post_type'              => sanitize_key( $args['post_type'] ?? 'post' ),
				'post_status'            => sanitize_key( $args['status'] ?? 'publish' ),
				's'                      => sanitize_text_field( $args['search'] ?? '' ),
				'posts_per_page'         => min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
				'paged'                  => max( 1, (int) ( $args['page'] ?? 1 ) ),
				'orderby'                => in_array( (string) ( $args['orderby'] ?? 'date' ), array( 'date', 'modified', 'title', 'id' ), true ) ? ( $args['orderby'] ?? 'date' ) : 'date',
				'order'                  => ( 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ) ? 'ASC' : 'DESC',
				'update_post_term_cache' => false,
			)
		);
		$posts = array_map( array( $this, 'summarize' ), $query->posts );
		return array(
			'total'      => (int) $query->found_posts,
			'pages'      => (int) $query->max_num_pages,
			'posts'      => $posts,
		);
	}

	public function get_post( array $args ): array {
		$post = get_post( (int) ( $args['id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$data = array(
			'id'             => $post->ID,
			'type'           => $post->post_type,
			'status'         => $post->post_status,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'excerpt'        => $post->post_excerpt,
			'date'           => get_the_date( 'c', $post ),
			'modified'       => get_the_modified_date( 'c', $post ),
			'permalink'      => get_permalink( $post ),
			'author_id'      => (int) $post->post_author,
			'featured_image' => null,
			'terms'          => array(),
		);
		if ( ! empty( $args['raw_content'] ) ) {
			$data['content'] = $post->post_content;
		}
		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$src                     = wp_get_attachment_image_src( $thumb_id, 'full' );
			$data['featured_image']  = array( 'id' => $thumb_id, 'url' => $src ? $src[0] : '' );
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$data['terms'][ $taxonomy ] = array_map( 'intval', $terms );
			}
		}
		$seo = wpmcp_plugin()->seo->get_post_seo( $post->ID );
		if ( empty( $seo['error'] ) ) {
			$data['seo'] = $seo;
		}
		return $data;
	}

	public function create_post( array $args ): array {
		$post_type = sanitize_key( $args['post_type'] ?? 'post' );
		if ( ! post_type_exists( $post_type ) ) {
			return array( 'error' => 'invalid_post_type' );
		}
		$pt = get_post_type_object( $post_type );
		if ( ! $pt || ! current_user_can( $pt->cap->create_posts ) ) {
			return array( 'error' => 'forbidden' );
		}
		$insert = array(
			'post_title'   => sanitize_text_field( $args['title'] ?? '' ),
			'post_content' => current_user_can( 'unfiltered_html' ) ? (string) ( $args['content'] ?? '' ) : wp_kses_post( $args['content'] ?? '' ),
			'post_excerpt' => sanitize_textarea_field( $args['excerpt'] ?? '' ),
			'post_status'  => in_array( $args['status'] ?? 'draft', array( 'draft', 'publish', 'pending', 'private' ), true ) ? $args['status'] : 'draft',
			'post_type'    => $post_type,
		);
		foreach ( array( 'slug' => 'post_name', 'date' => 'post_date' ) as $arg => $field ) {
			if ( ! empty( $args[ $arg ] ) ) {
				$insert[ $field ] = 'slug' === $arg ? sanitize_title( $args[ $arg ] ) : sanitize_text_field( $args[ $arg ] );
			}
		}
		if ( ! empty( $args['author_id'] ) ) {
			$insert['post_author'] = (int) $args['author_id'];
		}
		$id = wp_insert_post( $insert, true );
		if ( is_wp_error( $id ) ) {
			return array( 'error' => $id->get_error_message() );
		}
		$this->apply_terms_and_thumb( $id, $args );
		$this->log->record( 'content', 'create-post', $id, $insert['post_title'], sprintf( 'Created %s "%s"', $post_type, $insert['post_title'] ) );
		return array(
			'id'        => $id,
			'permalink' => get_permalink( $id ),
			'edit_link' => get_edit_post_link( $id, 'raw' ),
		);
	}

	public function update_post( array $args ): array {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$update = array( 'ID' => $id );
		$fields = array();
		foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name', 'status' => 'post_status' ) as $arg => $field ) {
			if ( isset( $args[ $arg ] ) ) {
				$value = 'content' === $arg ? ( current_user_can( 'unfiltered_html' ) ? (string) $args[ $arg ] : wp_kses_post( $args[ $arg ] ) ) : sanitize_text_field( (string) $args[ $arg ] );
				$update[ $field ] = $value;
				$fields[ $field ] = $value;
			}
		}
		$before = array(
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'status'  => $post->post_status,
		);
		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}
		$this->apply_terms_and_thumb( $id, $args );
		$this->log->record( 'content', 'update-post', $id, $post->post_title, sprintf( 'Updated fields: %s', implode( ', ', array_keys( $fields ) ?: array( 'none' ) ) ), $before, true );
		return array(
			'id'        => $id,
			'updated'   => array_keys( $fields ),
			'permalink' => get_permalink( $id ),
		);
	}

	public function delete_post( array $args ): array {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || ! current_user_can( 'delete_post', $id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$force = ! empty( $args['force'] );
		$this->log->record( 'content', 'delete-post', $id, $post->post_title, sprintf( '%s "%s"', $force ? 'Permanently deleted' : 'Trashed', $post->post_title ), array( 'title' => $post->post_title, 'content' => $post->post_content, 'status' => $post->post_status ), true );
		$result = wp_delete_post( $id, $force );
		if ( ! $result ) {
			return array( 'error' => 'delete_failed' );
		}
		return array(
			'deleted' => true,
			'forced'  => $force,
			'trash_id' => $force ? null : $result->ID,
		);
	}

	public function list_terms( array $args ): array {
		$taxonomy = sanitize_key( $args['taxonomy'] ?? 'category' );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array( 'error' => 'invalid_taxonomy' );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'search'     => sanitize_text_field( $args['search'] ?? '' ),
				'number'     => min( 100, max( 1, (int) ( $args['per_page'] ?? 50 ) ) ),
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array( 'error' => $terms->get_error_message() );
		}
		return array(
			'terms' => array_map(
				static fn( $term ) => array(
					'id'     => $term->term_id,
					'name'   => $term->name,
					'slug'   => $term->slug,
					'count'  => $term->count,
					'parent' => (int) $term->parent,
				),
				$terms
			),
		);
	}

	public function create_term( array $args ): array {
		$taxonomy = sanitize_key( $args['taxonomy'] ?? 'category' );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array( 'error' => 'invalid_taxonomy' );
		}
		$result = wp_insert_term(
			sanitize_text_field( $args['name'] ?? '' ),
			$taxonomy,
			array_filter(
				array(
					'slug'        => isset( $args['slug'] ) ? sanitize_title( $args['slug'] ) : '',
					'description' => isset( $args['description'] ) ? sanitize_textarea_field( $args['description'] ) : '',
					'parent'      => isset( $args['parent_id'] ) ? (int) $args['parent_id'] : 0,
				)
			)
		);
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}
		$this->log->record( 'content', 'create-term', (int) $result['term_id'], $args['name'], sprintf( 'Created %s term "%s"', $taxonomy, $args['name'] ) );
		return array( 'id' => (int) $result['term_id'] );
	}

	private function apply_terms_and_thumb( int $id, array $args ): void {
		$post_type = get_post_type( $id );
		if ( isset( $args['categories'] ) && is_array( $args['categories'] ) && taxonomy_exists( 'category' ) ) {
			wp_set_post_categories( $id, array_map( 'intval', $args['categories'] ), false );
		}
		if ( isset( $args['tags'] ) && is_array( $args['tags'] ) && taxonomy_exists( 'post_tag' ) ) {
			wp_set_object_terms( $id, array_map( 'intval', $args['tags'] ), 'post_tag', false );
		}
		if ( isset( $args['featured_image_id'] ) ) {
			$thumb = (int) $args['featured_image_id'];
			if ( $thumb && 'attachment' === get_post_type( $thumb ) ) {
				set_post_thumbnail( $id, $thumb );
			} else {
				delete_post_thumbnail( $id );
			}
		}
	}

	private function summarize( WP_Post $post ): array {
		return array(
			'id'        => $post->ID,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'date'      => get_the_date( 'c', $post ),
			'modified'  => get_the_modified_date( 'c', $post ),
			'permalink' => get_permalink( $post ),
		);
	}

	public function list_post_types( array $args ): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$counts = wp_count_posts( $type->name );
			$out[]  = array(
				'name'          => $type->name,
				'label'         => $type->labels->name,
				'singular'      => $type->labels->singular_name,
				'public'        => (bool) $type->public,
				'hierarchical'  => (bool) $type->hierarchical,
				'has_archive'   => (bool) $type->has_archive,
				'rest_base'     => $type->rest_base ? $type->rest_base : $type->name,
				'publish_count' => isset( $counts->publish ) ? (int) $counts->publish : null,
			);
		}
		return array( 'total' => count( $out ), 'post_types' => $out );
	}

	public function list_taxonomies( array $args ): array {
		$include = ! empty( $args['include_terms'] );
		$out     = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$entry = array(
				'name'         => $tax->name,
				'label'        => $tax->labels->name,
				'hierarchical' => (bool) $tax->hierarchical,
				'object_type'  => array_values( (array) $tax->object_type ),
			);
			if ( $include ) {
				$terms          = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false, 'number' => 200 ) );
				$entry['terms'] = is_wp_error( $terms ) ? array() : array_map( static fn( $t ) => array( 'name' => $t->name, 'slug' => $t->slug, 'count' => (int) $t->count ), array_values( (array) $terms ) );
			}
			$out[] = $entry;
		}
		return array( 'total' => count( $out ), 'taxonomies' => $out );
	}
}
