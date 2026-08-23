<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Export {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	private function dir(): string {
		$dir = wp_get_upload_dir()['basedir'] . '/wpmcp-exports';
		wp_mkdir_p( $dir );
		return $dir;
	}

	public function register(): void {
		$this->registry->register(
			'export-content',
			array(
				'title'       => 'Export Content',
				'description' => 'Export posts/pages to git-friendly JSON files (content, excerpt, slug, meta, terms) under uploads/wpmcp-exports/. One file per post.',
				'category'    => 'export',
				'write'       => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'default' => 'page' ),
						'search'    => array( 'type' => 'string' ),
						'limit'     => array( 'type' => 'integer', 'default' => 20, 'maximum' => 200 ),
					),
				),
				'handler'     => array( $this, 'export' ),
			)
		);

		$this->registry->register(
			'list-exports',
			array(
				'title'       => 'List Content Exports',
				'description' => 'List exported JSON mirror files with sizes and dates.',
				'category'    => 'export',
				'capability'  => 'manage_options',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'handler'     => array( $this, 'list_exports' ),
			)
		);

		$this->registry->register(
			'restore-content',
			array(
				'title'       => 'Restore Content',
				'description' => 'Restore a post from its export file (creates or updates by slug). Destructive-ish: overwrites content of an existing post with the same slug. Requires confirm:true.',
				'category'    => 'export',
				'write'       => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'file'    => array( 'type' => 'string', 'required' => true, 'description' => 'File name from list-exports' ),
						'confirm' => array( 'type' => 'boolean', 'required' => true ),
					),
					'required'   => array( 'file', 'confirm' ),
				),
				'handler'     => array( $this, 'restore' ),
			)
		);
	}

	private function payload( int $post_id ): array {
		$p     = get_post( $post_id );
		$terms = array();
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$t = wp_get_post_terms( $post_id, $tax );
			if ( ! is_wp_error( $t ) && $t ) {
				$terms[ $tax ] = array_map( static fn( $x ) => $x->slug, $t );
			}
		}
		return array(
			'id'        => $p->ID,
			'type'      => $p->post_type,
			'slug'      => $p->post_name,
			'title'     => $p->post_title,
			'status'    => $p->post_status,
			'content'   => $p->post_content,
			'excerpt'   => $p->post_excerpt,
			'modified'  => $p->post_modified_gmt,
			'terms'     => $terms,
		);
	}

	public function export( array $args ): array {
		$query = new WP_Query( array(
			'post_type'      => sanitize_key( (string) ( $args['post_type'] ?? 'page' ) ),
			'post_status'    => 'publish',
			's'              => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'posts_per_page' => min( 200, max( 1, (int) ( $args['limit'] ?? 20 ) ) ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );
		$dir   = $this->dir();
		$files = array();
		foreach ( $query->posts as $p ) {
			$payload = $this->payload( $p->ID );
			$file    = sanitize_title( $p->post_type . '-' . $p->post_name ) . '.json';
			file_put_contents( $dir . '/' . $file, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			$files[] = array( 'file' => $file, 'title' => $p->post_title, 'id' => $p->ID );
		}
		$this->log->record( 'export', 'export-content', 0, (string) ( $args['post_type'] ?? 'page' ), sprintf( 'Exported %d posts', count( $files ) ) );
		return array( 'exported' => count( $files ), 'files' => $files );
	}

	public function list_exports( array $args ): array {
		$dir   = $this->dir();
		$out   = array();
		foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
			$out[] = array(
				'file'    => basename( $file ),
				'size'    => filesize( $file ),
				'modified' => gmdate( 'c', (int) filemtime( $file ) ),
			);
		}
		return array( 'total' => count( $out ), 'files' => $out );
	}

	public function restore( array $args ): array {
		if ( empty( $args['confirm'] ) ) {
			return array( 'error' => 'confirm_required' );
		}
		$file = sanitize_file_name( (string) ( $args['file'] ?? '' ) );
		$path = $this->dir() . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return array( 'error' => 'file_not_found' );
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || empty( $data['slug'] ) || empty( $data['type'] ) ) {
			return array( 'error' => 'invalid_export_file' );
		}

		$existing = get_page_by_path( $data['slug'], OBJECT, $data['type'] );
		$before   = $existing ? $this->payload( $existing->ID ) : null;

		$payload = array(
			'post_title'   => $data['title'] ?? '',
			'post_name'    => $data['slug'],
			'post_content' => $data['content'] ?? '',
			'post_excerpt' => $data['excerpt'] ?? '',
			'post_status'  => $data['status'] ?? 'publish',
			'post_type'    => $data['type'],
		);
		if ( $existing ) {
			$payload['ID'] = $existing->ID;
			$post_id       = wp_update_post( $payload );
		} else {
			$post_id = wp_insert_post( $payload );
		}
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return array( 'error' => 'restore_failed' );
		}
		foreach ( (array) ( $data['terms'] ?? [] ) as $tax => $slugs ) {
			wp_set_post_terms( $post_id, (array) $slugs, $tax );
		}
		$this->log->record( 'export', 'restore-content', $post_id, $data['title'] ?? $data['slug'], sprintf( 'Restored %s from %s', $data['slug'], $file ), $before ? array( 'before' => $before ) : null, true );
		return array( 'ok' => true, 'post_id' => $post_id, 'created' => ! $existing );
	}
}
