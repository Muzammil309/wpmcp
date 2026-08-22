<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Media {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'list-media',
			array(
				'title'       => 'List Media',
				'description' => 'List and search Media Library attachments. Filter by mime type and search term; newest first.',
				'category'    => 'media',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array( 'type' => 'string', 'description' => 'Matches title, alt text, caption and description' ),
						'mime_type' => array( 'type' => 'string', 'description' => 'e.g. image, image/jpeg, application/pdf' ),
						'per_page'  => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
						'page'      => array( 'type' => 'integer', 'default' => 1 ),
					),
				),
				'handler'     => array( $this, 'list_media' ),
			)
		);

		$this->registry->register(
			'get-media',
			array(
				'title'       => 'Get Media',
				'description' => 'Read a Media Library attachment: URL, all registered sizes, dimensions, alt text, title, caption, description and mime type.',
				'category'    => 'media',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => 'Attachment ID' ),
					),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'get_media' ),
			)
		);

		$this->registry->register(
			'update-media',
			array(
				'title'       => 'Update Media',
				'description' => 'Edit attachment metadata: alt text, title, caption, description. One call accessibility/SEO fix.',
				'category'    => 'media',
				'write'       => true,
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'alt_text'    => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'update_media' ),
			)
		);

		$this->registry->register(
			'delete-media',
			array(
				'title'       => 'Delete Media',
				'description' => 'Permanently delete an attachment and its files. Destructive; requires confirm:true. The before-image in the change ledger keeps the metadata.',
				'category'    => 'media',
				'write'       => true,
				'confirm'     => true,
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'id', 'confirm' ),
				),
				'handler'     => array( $this, 'delete_media' ),
			)
		);

		$this->registry->register(
			'sideload-image',
			array(
				'title'       => 'Sideload Image',
				'description' => 'Download an image from a public http(s) URL into the Media Library. SSRF-guarded: public hosts only, 10 MB cap, image types only.',
				'category'    => 'media',
				'write'       => true,
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'     => array( 'type' => 'string', 'description' => 'Direct image URL' ),
						'post_id' => array( 'type' => 'integer', 'description' => 'Optional post to attach to' ),
						'alt_text' => array( 'type' => 'string' ),
					),
					'required'   => array( 'url' ),
				),
				'handler'     => array( $this, 'sideload_image' ),
			)
		);
		$this->registry->register(
			'search-images',
			array(
				'title'       => 'Search Stock Images',
				'description' => 'Search Openverse (Creative Commons) for free stock images. Returns direct URLs ready for sideload-image or add-stock-image. No API key required.',
				'category'    => 'media',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'    => array( 'type' => 'string', 'required' => True, 'description' => 'Search phrase, e.g. modern office interior' ),
						'license'  => array( 'type' => 'string', 'description' => 'Comma separated CC licenses, e.g. cc0,by,pdm. Default: all' ),
						'per_page' => array( 'type' => 'integer', 'default' => 12, 'maximum' => 20 ),
						'page'     => array( 'type' => 'integer', 'default' => 1 ),
					),
					'required'   => array( 'query' ),
				),
				'handler'     => array( $this, 'search_images' ),
			)
		);

		$this->registry->register(
			'add-stock-image',
			array(
				'title'       => 'Add Stock Image',
				'description' => 'Search Openverse and sideload the chosen result straight into the Media Library in one call. Pass query + index, or a direct image_url from search-images. License credit stored as caption.',
				'category'    => 'media',
				'write'       => True,
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'     => array( 'type' => 'string', 'description' => 'Search phrase when using index' ),
						'index'     => array( 'type' => 'integer', 'default' => 0, 'description' => 'Result index from the query' ),
						'image_url' => array( 'type' => 'string', 'description' => 'Direct result URL from search-images (overrides query/index)' ),
						'alt_text'  => array( 'type' => 'string' ),
					),
				),
				'handler'     => array( $this, 'add_stock_image' ),
			)
		);

		$this->registry->register(
			'resize-media',
			array(
				'title'       => 'Resize Media',
				'description' => 'Scale or crop an image attachment in place. Keeps a .wpmcp-bak backup of the original file and records it in the change ledger - rollback-change restores the original pixels.',
				'category'    => 'media',
				'write'       => True,
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'required' => True ),
						'mode'    => array( 'type' => 'string', 'enum' => array( 'scale', 'crop' ), 'default' => 'scale' ),
						'width'   => array( 'type' => 'integer', 'description' => 'scale: max width; crop: exact width' ),
						'height'  => array( 'type' => 'integer', 'description' => 'scale: optional max height; crop: required' ),
						'quality' => array( 'type' => 'integer', 'default' => 82, 'maximum' => 100 ),
					),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'resize_media' ),
			)
		);

	}

	public function list_media( array $args ): array {
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$query    = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				's'                      => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
				'post_mime_type'         => '' !== ( $args['mime_type'] ?? '' ) ? sanitize_text_field( (string) $args['mime_type'] ) : '',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$out = array();
		foreach ( $query->posts as $post ) {
			$meta  = wp_get_attachment_metadata( $post->ID );
			$out[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'url'       => wp_get_attachment_url( $post->ID ),
				'mime_type' => $post->post_mime_type,
				'alt_text'  => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
				'width'     => (int) ( $meta['width'] ?? 0 ),
				'height'    => (int) ( $meta['height'] ?? 0 ),
				'uploaded_at' => get_the_date( 'c', $post ),
			);
		}
		return array(
			'total' => (int) $query->found_posts,
			'page'  => $page,
			'media' => $out,
		);
	}

	public function delete_media( array $args ): array {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'delete_post', $id ) ) {
			return array( 'error' => 'attachment_not_found_or_forbidden' );
		}
		$this->log->record(
			'media',
			'delete-media',
			$id,
			$post->post_title,
			'Deleted attachment',
			array(
				'title'   => $post->post_title,
				'caption' => $post->post_excerpt,
				'description' => $post->post_content,
				'alt_text' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			),
			true
		);
		$deleted = wp_delete_attachment( $id, true );
		if ( ! $deleted ) {
			return array( 'error' => 'delete_failed' );
		}
		return array( 'deleted' => true, 'id' => $id );
	}

	public function get_media( array $args ): array {
		$id  = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return array( 'error' => 'attachment_not_found' );
		}
		$meta    = wp_get_attachment_metadata( $id );
		$sizes   = array();
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				$src            = wp_get_attachment_image_src( $id, $name );
				$sizes[ $name ] = array(
					'width'  => (int) ( $size['width'] ?? 0 ),
					'height' => (int) ( $size['height'] ?? 0 ),
					'url'    => $src ? $src[0] : '',
				);
			}
		}
		return array(
			'id'          => $id,
			'url'         => wp_get_attachment_url( $id ),
			'mime_type'   => $post->post_mime_type,
			'alt_text'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'       => $post->post_title,
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'width'       => (int) ( $meta['width'] ?? 0 ),
			'height'      => (int) ( $meta['height'] ?? 0 ),
			'sizes'       => $sizes,
			'uploaded_at' => get_the_date( 'c', $post ),
		);
	}

	public function update_media( array $args ): array {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			return array( 'error' => 'attachment_not_found_or_forbidden' );
		}
		$updated = array();
		$before  = array(
			'alt_text'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'       => $post->post_title,
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
		);
		if ( isset( $args['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
			$updated[] = 'alt_text';
		}
		$post_update = array( 'ID' => $id );
		foreach ( array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' ) as $arg => $field ) {
			if ( isset( $args[ $arg ] ) ) {
				$post_update[ $field ] = sanitize_text_field( (string) $args[ $arg ] );
				$updated[]             = $arg;
			}
		}
		if ( count( $post_update ) > 1 ) {
			wp_update_post( $post_update );
		}
		$this->log->record( 'media', 'update-media', $id, $before['title'], sprintf( 'Updated media fields: %s', implode( ', ', $updated ) ), $before, true );
		return array( 'id' => $id, 'updated' => $updated );
	}

	public function sideload_image( array $args ): array {
		$url = trim( (string) ( $args['url'] ?? '' ) );
		$guard = WPMCP_Url_Guard::validate( $url );
		if ( is_wp_error( $guard ) ) {
			return array( 'error' => $guard->get_error_code(), 'message' => $guard->get_error_message() );
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return array( 'error' => 'download_failed', 'message' => $tmp->get_error_message() );
		}
		$file_array = array(
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'image.jpg' ),
			'tmp_name' => $tmp,
		);
		$id = media_handle_sideload( $file_array, (int) ( $args['post_id'] ?? 0 ) );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return array( 'error' => 'sideload_failed', 'message' => $id->get_error_message() );
		}
		if ( isset( $args['alt_text'] ) && '' !== $args['alt_text'] ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		$this->log->record( 'media', 'sideload-image', $id, basename( $file_array['name'] ), sprintf( 'Imported image from %s', wp_parse_url( $url, PHP_URL_HOST ) ) );
		return array(
			'id'  => $id,
			'url' => $src ? $src[0] : wp_get_attachment_url( $id ),
		);
	}

	public function search_images( array $args ): array {
		$query = trim( (string) ( $args['query'] ?? '' ) );
		if ( '' === $query ) {
			return array( 'error' => 'query_required' );
		}
		$per_page = min( 20, max( 1, (int) ( $args['per_page'] ?? 12 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$url      = add_query_arg(
			array_filter(
				array(
					'q'            => rawurlencode( $query ),
					'page_size'    => $per_page,
					'page'         => $page,
					'mature'       => 'false',
					'aspect_ratio' => 'wide',
					'license'      => isset( $args['license'] ) ? preg_replace( '/\s+/', '', (string) $args['license'] ) : null,
				),
				static fn( $v ) => null !== $v && '' !== $v
			),
			'https://api.openverse.org/v1/images/'
		);
		$response = wp_safe_remote_get(
			$url,
			array( 'timeout' => 20, 'user-agent' => 'WPMCP-Suite/' . WPMCP_VERSION . '; media picker' )
		);
		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'openverse_unreachable', 'message' => $response->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['results'] ) ) {
			return array(
				'error'       => 'bad_response',
				'status_code' => wp_remote_retrieve_response_code( $response ),
				'body_sample' => mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 200 ),
				'hint'        => 'Openverse rate-limits by IP; retry shortly or pass a license filter.',
			);
		}
		$out = array();
		foreach ( (array) $body['results'] as $i => $r ) {
			$out[] = array(
				'index'       => $i + ( ( $page - 1 ) * $per_page ),
				'title'       => (string) ( $r['title'] ?? '' ),
				'url'         => (string) ( $r['url'] ?? '' ),
				'thumbnail'   => (string) ( $r['thumbnail'] ?? '' ),
				'width'       => (int) ( $r['width'] ?? 0 ),
				'height'      => (int) ( $r['height'] ?? 0 ),
				'license'     => trim( ( $r['license'] ?? '' ) . ' ' . ( $r['license_version'] ?? '' ) ),
				'creator'     => (string) ( $r['creator'] ?? '' ),
				'source'      => (string) ( $r['source'] ?? '' ),
				'landing_url' => (string) ( $r['foreign_landing_url'] ?? '' ),
			);
		}
		return array(
			'total_count' => (int) ( $body['result_count'] ?? count( $out ) ),
			'query'       => $query,
			'images'      => $out,
		);
	}

	public function add_stock_image( array $args ): array {
		$url    = trim( (string) ( $args['image_url'] ?? '' ) );
		$credit = '';
		if ( '' === $url ) {
			$query = trim( (string) ( $args['query'] ?? '' ) );
			if ( '' === $query ) {
				return array( 'error' => 'query_or_image_url_required' );
			}
			$results = $this->search_images( array( 'query' => $query, 'per_page' => 10 ) );
			if ( isset( $results['error'] ) ) {
				return $results;
			}
			$index = max( 0, (int) ( $args['index'] ?? 0 ) );
			if ( empty( $results['images'][ $index ] ) ) {
				return array( 'error' => 'index_out_of_range', 'total' => $results['total_count'] ?? 0 );
			}
			$pick   = $results['images'][ $index ];
			$url    = $pick['url'];
			$credit = sprintf( '%s by %s (%s)', $pick['title'], $pick['creator'], strtoupper( $pick['license'] ) );
		}
		$alt = sanitize_text_field( (string) ( $args['alt_text'] ?? '' ) );
		if ( '' === $alt && '' !== $credit ) {
			$alt = $credit;
		}
		$sideloaded = $this->sideload_image( array( 'url' => $url, 'alt_text' => $alt ) );
		if ( isset( $sideloaded['error'] ) ) {
			return $sideloaded;
		}
		if ( '' !== $credit ) {
			wp_update_post( array( 'ID' => $sideloaded['id'], 'post_excerpt' => sanitize_text_field( $credit ) ) );
		}
		return $sideloaded + array( 'credit' => '' !== $credit ? $credit : null );
	}

	public function resize_media( array $args ): array {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			return array( 'error' => 'attachment_not_found_or_forbidden' );
		}
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array( 'error' => 'file_missing' );
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return array( 'error' => 'not_editable_image', 'message' => $editor->get_error_message() );
		}
		$size   = $editor->get_size();
		$width  = isset( $args['width'] ) ? max( 16, (int) $args['width'] ) : 0;
		$height = isset( $args['height'] ) ? max( 16, (int) $args['height'] ) : 0;
		$crop   = 'crop' === ( $args['mode'] ?? 'scale' );
		if ( $crop && ( ! $width || ! $height ) ) {
			return array( 'error' => 'crop_requires_both_dimensions' );
		}
		if ( ! $width && ! $height ) {
			return array( 'error' => 'dimensions_required' );
		}
		if ( isset( $args['quality'] ) ) {
			$editor->set_quality( min( 100, max( 30, (int) $args['quality'] ) ) );
		}
		$resized = $editor->resize( $width, $height, $crop );
		if ( is_wp_error( $resized ) ) {
			return array( 'error' => 'resize_failed', 'message' => $resized->get_error_message() );
		}
		$backup_rel = null;
		$backup_abs = $file . '.wpmcp-bak';
		if ( copy( $file, $backup_abs ) ) {
			$backup_rel = basename( dirname( $file ) ) . '/' . basename( $backup_abs );
		}
		$saved = $editor->save( $file );
		if ( is_wp_error( $saved ) ) {
			return array( 'error' => 'save_failed', 'message' => $saved->get_error_message() );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		$new_size = $editor->get_size();
		$this->log->record(
			'media', 'resize-media', $id, $post->post_title,
			sprintf( 'Resized %dx%d to %dx%d', (int) $size['width'], (int) $size['height'], (int) $new_size['width'], (int) $new_size['height'] ),
			array(
				'backup_file' => $backup_rel,
				'old_width'   => (int) $size['width'],
				'old_height'  => (int) $size['height'],
			),
			true
		);
		return array(
			'ok'        => true,
			'id'        => $id,
			'old_size'  => array( 'width' => (int) $size['width'], 'height' => (int) $size['height'] ),
			'new_size'  => array( 'width' => (int) $new_size['width'], 'height' => (int) $new_size['height'] ),
			'backed_up' => null !== $backup_rel,
		);
	}
}
