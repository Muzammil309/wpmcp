<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_History {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'list-changes',
			array(
				'title'       => 'List Changes',
				'description' => 'Recent MCP-made changes, newest first. Filter by domain (content, media, settings, seo, redirects) or rolled_back state.',
				'category'    => 'history',
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'per_page'  => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
						'page'      => array( 'type' => 'integer', 'default' => 1 ),
						'domain'    => array( 'type' => 'string', 'description' => 'Optional domain filter' ),
						'rolled_back' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => 'Optional rollback-state filter' ),
					),
				),
				'handler'     => array( $this, 'list_changes' ),
			)
		);

		$this->registry->register(
			'get-change',
			array(
				'title'       => 'Get Change',
				'description' => 'One change entry in full, including its before-image (the data needed to undo it).',
				'category'    => 'history',
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'get_change' ),
			)
		);

		$this->registry->register(
			'rollback-change',
			array(
				'title'       => 'Rollback Change',
				'description' => 'Undo a recorded change from its before-image: restores post fields, SEO metadata, media fields or settings. Destructive; requires confirm:true.',
				'category'    => 'history',
				'write'       => true,
				'confirm'     => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'id', 'confirm' ),
				),
				'handler'     => array( $this, 'rollback_change' ),
			)
		);
	}

	public function list_changes( array $args ): array {
		$changes = $this->log->list_changes( (int) ( $args['per_page'] ?? 20 ), (int) ( $args['page'] ?? 1 ) );
		if ( ! empty( $args['domain'] ) ) {
			$changes = array_values( array_filter( $changes, static fn( $c ) => $c['domain'] === $args['domain'] ) );
		}
		if ( isset( $args['rolled_back'] ) && '' !== $args['rolled_back'] ) {
			$want    = 'yes' === $args['rolled_back'];
			$changes = array_values( array_filter( $changes, static fn( $c ) => $c['rolled_back'] === $want ) );
		}
		return array( 'changes' => $changes );
	}

	public function get_change( array $args ): array {
		$change = $this->log->get_change( (int) ( $args['id'] ?? 0 ) );
		if ( ! $change ) {
			return array( 'error' => 'change_not_found' );
		}
		return $change;
	}

	public function rollback_change( array $args ): array {
		$id     = (int) ( $args['id'] ?? 0 );
		$change = $this->log->get_change( $id );
		if ( ! $change ) {
			return array( 'error' => 'change_not_found' );
		}
		if ( $change['rolled_back'] ) {
			return array( 'error' => 'already_rolled_back' );
		}
		if ( ! $change['reversible'] || ! is_array( $change['before_image'] ) ) {
			return array( 'error' => 'not_reversible', 'message' => 'No before-image was captured for this entry.' );
		}

		$before = $change['before_image'];
		switch ( $change['action'] ) {
			case 'update-post':
				$result = $this->rollback_post( $change, $before );
				break;
			case 'update-media':
				$result = $this->rollback_media( $change, $before );
				break;
			case 'update-settings':
				$result = $this->rollback_settings( $before );
				break;
			case 'update-post-seo':
			case 'generate-meta-tags':
				$result = $this->rollback_seo( $change, $before );
				break;
			case 'generate-schema-markup':
				delete_post_meta( $change['target_id'], '_wpmcp_schema_jsonld' );
				$result = array( 'undone' => 'schema_removed' );
				break;
			case 'create-post':
			case 'create-term':
				$result = array( 'error' => 'manual_undo_required', 'message' => 'Creation entries are not auto-rolled-back; trash the created item manually.' );
				break;
			case 'update-product':
				$result = $this->rollback_product( $change, $before );
				break;
			case 'resize-media':
				$result = $this->rollback_resize( $change, $before );
				break;
			case 'update-order':
				$result = $this->rollback_order( $change, $before );
				break;
			default:
				$result = array( 'error' => 'unsupported_action' );
		}

		if ( isset( $result['error'] ) ) {
			return $result + array( 'change_id' => $id );
		}

		$this->log->mark_rolled_back( $id );
		wpmcp_plugin()->change_log->record(
			$change['domain'],
			'rollback-change',
			$change['target_id'],
			$change['target'],
			sprintf( 'Rolled back #%d (%s)', $id, $change['action'] )
		);
		return array( 'rolled_back' => true, 'change_id' => $id ) + $result;
	}

	private function rollback_post( array $change, array $before ): array {
		$post_id = $change['target_id'];
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$update = array( 'ID' => $post_id );
		foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status' ) as $key => $field ) {
			if ( isset( $before[ $key ] ) ) {
				$update[ $field ] = $before[ $key ];
			}
		}
		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}
		return array( 'restored_fields' => array_keys( array_intersect_key( $before, array( 'title' => 1, 'content' => 1, 'status' => 1 ) ) ) );
	}

	private function rollback_media( array $change, array $before ): array {
		$id   = $change['target_id'];
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return array( 'error' => 'attachment_not_found' );
		}
		if ( isset( $before['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $before['alt_text'] ) );
		}
		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => $before['title'] ?? $post->post_title,
				'post_excerpt' => $before['caption'] ?? $post->post_excerpt,
				'post_content' => $before['description'] ?? $post->post_content,
			)
		);
		return array( 'restored_fields' => array_keys( $before ) );
	}

	private function rollback_settings( array $before ): array {
		foreach ( $before as $key => $value ) {
			update_option( sanitize_key( $key ), $value );
		}
		return array( 'restored_keys' => array_keys( $before ) );
	}

	private function rollback_product( array $change, array $before ): array {
		if ( ! WPMCP_Tool_Woo::available() ) {
			return array( 'error' => 'woocommerce_missing' );
		}
		$product = wc_get_product( $change['target_id'] );
		if ( ! $product || ! current_user_can( 'edit_post', $change['target_id'] ) ) {
			return array( 'error' => 'product_not_found_or_forbidden' );
		}
		foreach ( array( 'regular_price', 'sale_price', 'status', 'description', 'short_description' ) as $field ) {
			if ( ! isset( $before[ $field ] ) ) {
				continue;
			}
			$product->{"set_{$field}"}( (string) $before[ $field ] );
		}
		if ( isset( $before['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $before['manage_stock'] );
			if ( $before['manage_stock'] && isset( $before['stock_quantity'] ) ) {
				$product->set_stock_quantity( (int) $before['stock_quantity'] );
			}
		}
		if ( isset( $before['stock_status'] ) ) {
			$product->set_stock_status( (string) $before['stock_status'] );
		}
		$product->save();
		return array( 'restored_fields' => array_keys( $before ) );
	}

	private function rollback_order( array $change, array $before ): array {
		if ( ! WPMCP_Tool_Woo::available() ) {
			return array( 'error' => 'woocommerce_missing' );
		}
		$order = wc_get_order( $change['target_id'] );
		if ( ! $order || ! is_a( $order, WC_Order::class ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'order_not_found_or_forbidden' );
		}
		if ( isset( $before['status'] ) ) {
			$order->set_status( (string) $before['status'] );
		}
		if ( isset( $before['customer_note'] ) ) {
			$order->set_customer_note( (string) $before['customer_note'] );
		}
		$order->save();
		return array( 'restored_fields' => array_keys( $before ) );
	}

	private function rollback_resize( array $change, array $before ): array {
		if ( empty( $before['backup_file'] ) ) {
			return array( 'error' => 'no_backup_available' );
		}
		$file = get_attached_file( $change['target_id'] );
		if ( ! $file ) {
			return array( 'error' => 'attachment_missing' );
		}
		$backup = dirname( $file ) . '/' . basename( (string) $before['backup_file'] );
		if ( ! file_exists( $backup ) ) {
			return array( 'error' => 'backup_file_gone', 'message' => 'The .wpmcp-bak file no longer exists on disk.' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		copy( $backup, $file );
		wp_update_attachment_metadata( $change['target_id'], wp_generate_attachment_metadata( $change['target_id'], $file ) );
		unlink( $backup );
		return array( 'restored' => true, 'dimensions' => sprintf( '%dx%d', (int) ( $before['old_width'] ?? 0 ), (int) ( $before['old_height'] ?? 0 ) ) );
	}

	private function rollback_seo( array $change, array $before ): array {
		unset( $before['plugin'], $before['permalink'], $before['effective_title'], $before['effective_description'], $before['note'] );
		if ( empty( $before ) ) {
			return wpmcp_plugin()->seo->update_post_seo( $change['target_id'], $this->clear_all_supported() );
		}
		$fields = array();
		foreach ( array( 'og_image', 'twitter_image' ) as $img_field ) {
			if ( isset( $before[ $img_field ] ) && is_array( $before[ $img_field ] ) ) {
				$fields[ $img_field ] = $before[ $img_field ];
				unset( $before[ $img_field ] );
			}
		}
		foreach ( $before as $field => $value ) {
			$fields[ $field ] = is_bool( $value ) ? $value : (string) $value;
		}
		return wpmcp_plugin()->seo->update_post_seo( $change['target_id'], $fields );
	}

	private function clear_all_supported(): array {
		$clear = array();
		foreach ( wpmcp_plugin()->seo->active()->supported_fields() as $field ) {
			$clear[ $field ] = str_contains( $field, 'noindex' ) || str_contains( $field, 'nofollow' ) ? false : '';
		}
		return $clear;
	}
}
