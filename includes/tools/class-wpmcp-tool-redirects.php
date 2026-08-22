<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Redirects {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;
	private WPMCP_Redirects $redirects;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log, WPMCP_Redirects $redirects ) {
		$this->registry  = $registry;
		$this->log       = $log;
		$this->redirects = $redirects;
	}

	public function register(): void {
		$this->registry->register(
			'redirect-read',
			array(
				'title'       => 'Redirect Read',
				'description' => 'List configured redirects with hit counts.',
				'category'    => 'redirects',
				'capability'  => 'edit_posts',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'handler'     => fn() => array( 'redirects' => $this->redirects->all() ),
			)
		);

		$this->registry->register(
			'redirect-write',
			array(
				'title'       => 'Redirect Write',
				'description' => 'Manage 301/302/307/308 redirects. Operations: add {from,to,code}, update {index,to,code,enabled}, delete {index}. Loop and duplicate protected; every change is logged.',
				'category'    => 'redirects',
				'write'       => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'add', 'update', 'delete' ), 'description' => 'Omit to list operations' ),
						'from'      => array( 'type' => 'string', 'description' => 'add: source path, e.g. /old-page/' ),
						'to'        => array( 'type' => 'string', 'description' => 'add: target URL or path' ),
						'code'      => array( 'type' => 'integer', 'enum' => array( 301, 302, 307, 308 ), 'default' => 301 ),
						'index'     => array( 'type' => 'integer', 'description' => 'update/delete: position from redirect-read list' ),
						'enabled'   => array( 'type' => 'boolean' ),
					),
				),
				'handler'     => array( $this, 'write' ),
			)
		);

		$this->registry->register(
			'scan-broken-links',
			array(
				'title'       => 'Scan Broken Links',
				'description' => 'Scan published content for broken links. Processes a bounded batch per call (default 10 posts) and returns a cursor for the next batch. Outbound requests: GET with short timeout, capped body read.',
				'category'    => 'redirects',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'batch_size'   => array( 'type' => 'integer', 'default' => 10, 'maximum' => 25 ),
						'cursor'       => array( 'type' => 'integer', 'description' => 'Post offset returned by the previous call' ),
						'post_types'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'default' => array( 'post', 'page' ) ),
						'check_external' => array( 'type' => 'boolean', 'default' => true ),
					),
				),
				'handler'     => array( $this, 'scan_broken_links' ),
			)
		);
	}

	public function write( array $args ): array {
		$operation = (string) ( $args['operation'] ?? '' );
		switch ( $operation ) {
			case '':
				return array(
					'operations' => array(
						array( 'operation' => 'add', 'arguments' => array( 'from' => '/old-path/', 'to' => 'https://…/new-path/', 'code' => 301 ) ),
						array( 'operation' => 'update', 'arguments' => array( 'index' => 0, 'to' => '…', 'enabled' => false ) ),
						array( 'operation' => 'delete', 'arguments' => array( 'index' => 0 ) ),
					),
				);
			case 'add':
				$result = $this->redirects->add( (string) ( $args['from'] ?? '' ), (string) ( $args['to'] ?? '' ), (int) ( $args['code'] ?? 301 ) );
				break;
			case 'update':
				$fields = array_filter(
					array(
						'to'      => $args['to'] ?? null,
						'code'    => isset( $args['code'] ) ? (int) $args['code'] : null,
						'enabled' => isset( $args['enabled'] ) ? (bool) $args['enabled'] : null,
					),
					static fn( $v ) => null !== $v
				);
				$result = $this->redirects->update( (int) ( $args['index'] ?? -1 ), $fields );
				break;
			case 'delete':
				$result = $this->redirects->delete( (int) ( $args['index'] ?? -1 ) );
				break;
			default:
				return array( 'error' => 'unknown_operation' );
		}
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() );
		}
		$this->log->record( 'redirects', 'redirect-' . $operation, 0, (string) ( $args['from'] ?? sprintf( '#%d', $args['index'] ?? -1 ) ), sprintf( 'Redirect %s: %s', $operation, wp_json_encode( array_intersect_key( $args, array_flip( array( 'from', 'to', 'code', 'index', 'enabled' ) ) ) ) ) );
		return array( 'ok' => true, 'result' => $result );
	}

	public function scan_broken_links( array $args ): array {
		$batch_size = min( 25, max( 1, (int) ( $args['batch_size'] ?? 10 ) ) );
		$offset     = max( 0, (int) ( $args['cursor'] ?? 0 ) );
		$post_types = isset( $args['post_types'] ) && is_array( $args['post_types'] )
			? array_map( 'sanitize_key', $args['post_types'] )
			: array( 'post', 'page' );
		$check_external = ! empty( $args['check_external'] );

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $batch_size,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'all',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$broken    = array();
		foreach ( $query->posts as $post ) {
			$html = apply_filters( 'the_content', $post->post_content );
			$dom  = new DOMDocument();
			libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
			libxml_clear_errors();
			foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
				$href = trim( (string) $a->getAttribute( 'href' ) );
				if ( '' === $href || str_starts_with( $href, '#' ) || str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) ) {
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( null === $host || $host === $site_host ) {
					continue;
				}
				if ( ! $check_external ) {
					continue;
				}
				$status = $this->probe( $href );
				if ( null !== $status && ( $status >= 400 || 0 === $status ) ) {
					$broken[] = array(
						'post_id'     => $post->ID,
						'post_title'  => $post->post_title,
						'url'         => $href,
						'http_status' => $status,
					);
				}
			}
		}

		$total  = (int) $query->found_posts;
		$cursor = $offset + count( $query->posts );
		return array(
			'scanned_posts' => count( $query->posts ),
			'next_cursor'   => $cursor < $total ? $cursor : null,
			'progress'      => sprintf( '%d/%d posts scanned', min( $cursor, $total ), $total ),
			'broken_links'  => $broken,
		);
	}

	private function probe( string $url ): ?int {
		if ( is_wp_error( WPMCP_Url_Guard::validate( $url ) ) ) {
			return 0;
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 5,
				'redirection' => 3,
				'sslverify'  => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return (int) wp_remote_retrieve_response_code( $response );
	}
}
