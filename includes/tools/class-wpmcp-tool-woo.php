<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Woo {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public static function available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public function register(): void {
		if ( ! self::available() ) {
			return;
		}

		$this->registry->register(
			'woo-status',
			array(
				'title'       => 'WooCommerce Status',
				'description' => 'Detect WooCommerce, its version and store counts (products, orders). Free; the six woo-* data tools require a Pro license.',
				'category'    => 'woocommerce',
				'capability'  => 'edit_posts',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'handler'     => array( $this, 'status' ),
			)
		);

		$pro = array( 'pro' => true, 'category' => 'woocommerce', 'capability' => 'manage_woocommerce' );

		$this->registry->register(
			'list-products',
			$pro + array(
				'title'       => 'List Products',
				'description' => 'Catalog of WooCommerce products with type, price, stock and status. Filter by search term, status or category slug.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Matches title, SKU and content' ),
						'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'any' ), 'default' => 'any' ),
						'category' => array( 'type' => 'string', 'description' => 'Product category slug' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
						'page'     => array( 'type' => 'integer', 'default' => 1 ),
					),
				),
				'handler'     => array( $this, 'list_products' ),
			)
		);

		$this->registry->register(
			'get-product',
			$pro + array(
				'title'       => 'Get Product',
				'description' => 'Full detail for one product: pricing, stock, categories, attributes summary.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'get_product' ),
			)
		);

		$this->registry->register(
			'update-product',
			$pro + array(
				'title'       => 'Update Product',
				'description' => 'Update pricing, stock, status or copy on a product. Only passed fields change. Recorded to the change ledger with a before-image.',
				'write'       => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'                 => array( 'type' => 'integer' ),
						'regular_price'      => array( 'type' => 'string' ),
						'sale_price'         => array( 'type' => 'string' ),
						'stock_quantity'     => array( 'type' => 'integer' ),
						'manage_stock'       => array( 'type' => 'boolean' ),
						'stock_status'       => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
						'status'             => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
						'description'        => array( 'type' => 'string' ),
						'short_description'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'update_product' ),
			)
		);

		$this->registry->register(
			'list-orders',
			$pro + array(
				'title'       => 'List Orders',
				'description' => 'Recent WooCommerce orders with status, total and customer. Filter by status, date range or search.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'description' => 'auto-draft, pending, processing, on-hold, completed, cancelled, refunded, failed or any' ),
						'search'   => array( 'type' => 'string' ),
						'after'    => array( 'type' => 'string', 'description' => 'Y-m-d or full date, created-at lower bound' ),
						'before'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
						'page'     => array( 'type' => 'integer', 'default' => 1 ),
					),
				),
				'handler'     => array( $this, 'list_orders' ),
			)
		);

		$this->registry->register(
			'get-order',
			$pro + array(
				'title'       => 'Get Order',
				'description' => 'Full order detail: status, totals, customer, line items.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'get_order' ),
			)
		);

		$this->registry->register(
			'update-order',
			$pro + array(
				'title'       => 'Update Order',
				'description' => 'Change an order status (or customer note). Recorded to the change ledger; rollback restores the prior status.',
				'write'       => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'            => array( 'type' => 'integer' ),
						'status'        => array( 'type' => 'string', 'enum' => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ) ),
						'customer_note' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'handler'     => array( $this, 'update_order' ),
			)
		);
	}

	public function status(): array {
		$products = wc_get_products( array( 'limit' => 1, 'paginate' => true ) );
		$orders   = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'limit' => 1, 'paginate' => true ) ) : null;
		return array(
			'woocommerce'    => true,
			'version'        => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'hpos'           => class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
			'product_count'  => $products ? $products->total : 0,
			'order_count'    => $orders ? $orders->total : 0,
			'pro_active'     => wpmcp_is_pro(),
		);
	}

	public function list_products( array $args ): array {
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$status   = sanitize_key( (string) ( $args['status'] ?? 'any' ) );

		if ( '' !== $search ) {
			$query = new WP_Query(
				array(
					'post_type'              => 'product',
					's'                      => $search,
					'post_status'            => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private' ) : $status,
					'posts_per_page'         => $per_page,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'no_found_rows'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$ids   = $query->posts;
			$total = (int) $query->found_posts;
		} else {
			$wc_args = array(
				'limit'    => $per_page,
				'page'     => $page,
				'paginate' => true,
				'orderby'  => 'date',
				'order'    => 'DESC',
			);
			if ( 'any' !== $status ) {
				$wc_args['status'] = $status;
			}
			if ( ! empty( $args['category'] ) ) {
				$wc_args['category'] = array( sanitize_title( (string) $args['category'] ) );
			}
			$results = wc_get_products( $wc_args );
			$ids     = wp_list_pluck( $results->products, 'get_id' );
			$total   = (int) $results->total;
		}

		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$out[] = $this->product_row( $product );
			}
		}
		return array(
			'total'    => $total,
			'page'     => $page,
			'products' => $out,
		);
	}

	private function product_row( WC_Product $product ): array {
		return array(
			'id'           => $product->get_id(),
			'name'         => $product->get_name(),
			'sku'          => $product->get_sku(),
			'type'         => $product->get_type(),
			'status'       => $product->get_status(),
			'price'        => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'   => $product->get_sale_price(),
			'stock_quantity' => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
			'stock_status' => $product->get_stock_status(),
		);
	}

	public function get_product( array $args ): array {
		$product = wc_get_product( (int) ( $args['id'] ?? 0 ) );
		if ( ! $product ) {
			return array( 'error' => 'product_not_found' );
		}
		$row               = $this->product_row( $product );
		$row['description'] = wp_strip_all_tags( (string) $product->get_description() );
		$row['short_description'] = wp_strip_all_tags( (string) $product->get_short_description() );
		$row['permalink']  = get_permalink( $product->get_id() );
		$row['categories'] = array_map(
			static fn( $term ) => array( 'slug' => $term->slug, 'name' => $term->name ),
			wp_get_post_terms( $product->get_id(), 'product_cat' )
		);
		$row['manage_stock'] = $product->get_manage_stock();
		return $row;
	}

	public function update_product( array $args ): array {
		$id      = (int) ( $args['id'] ?? 0 );
		$product = wc_get_product( $id );
		if ( ! $product || ! current_user_can( 'edit_post', $id ) ) {
			return array( 'error' => 'product_not_found_or_forbidden' );
		}

		$fields = array(
			'regular_price'     => 'set_regular_price',
			'sale_price'        => 'set_sale_price',
			'status'            => 'set_status',
			'description'       => 'set_description',
			'short_description' => 'set_short_description',
		);
		$before = array(
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'status'            => $product->get_status(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'stock_quantity'    => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
			'stock_status'      => $product->get_stock_status(),
			'manage_stock'      => $product->get_manage_stock(),
		);

		$changed = array();
		foreach ( $fields as $key => $setter ) {
			if ( isset( $args[ $key ] ) ) {
				$product->{$setter}( sanitize_textarea_field( (string) $args[ $key ] ) );
				$changed[] = $key;
			}
		}
		if ( isset( $args['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $args['manage_stock'] );
			$changed[] = 'manage_stock';
		}
		if ( isset( $args['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $args['stock_quantity'] );
			$changed[] = 'stock_quantity';
		}
		if ( isset( $args['stock_status'] ) ) {
			$product->set_stock_status( sanitize_key( (string) $args['stock_status'] ) );
			$changed[] = 'stock_status';
		}

		if ( empty( $changed ) ) {
			return array( 'error' => 'nothing_to_update' );
		}

		$saved = $product->save();
		if ( ! $saved ) {
			return array( 'error' => 'save_failed' );
		}

		$this->log->record( 'woocommerce', 'update-product', $id, $product->get_name(), sprintf( 'Updated: %s', implode( ', ', $changed ) ), $before, true );
		return array(
			'ok'      => true,
			'id'      => $id,
			'updated' => $changed,
		);
	}

	public function list_orders( array $args ): array {
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$wc_args  = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'type'     => 'shop_order',
		);
		if ( ! empty( $args['status'] ) && 'any' !== $args['status'] ) {
			$wc_args['status'] = sanitize_key( (string) $args['status'] );
		} else {
			$wc_args['status'] = array_keys( wc_get_order_statuses() );
		}
		if ( ! empty( $args['search'] ) ) {
			$wc_args['search'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( ! empty( $args['after'] ) ) {
			$wc_args['date_created'] = '>=' . strtotime( (string) $args['after'] );
		}
		if ( ! empty( $args['before'] ) ) {
			$wc_args['date_created'] = ( isset( $wc_args['date_created'] ) ? '' : '<=' ) . strtotime( (string) $args['before'] );
		}
		$results = wc_get_orders( $wc_args );

		$out = array();
		foreach ( $results->orders as $order ) {
			$out[] = $this->order_row( $order );
		}
		return array(
			'total'  => (int) $results->total,
			'page'   => $page,
			'orders' => $out,
		);
	}

	private function order_row( WC_Order $order ): array {
		return array(
			'id'       => $order->get_id(),
			'number'   => $order->get_order_number(),
			'status'   => $order->get_status(),
			'total'    => $order->get_total(),
			'currency' => $order->get_currency(),
			'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'    => $order->get_billing_email(),
			'date'     => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
		);
	}

	public function get_order( array $args ): array {
		$order = wc_get_order( (int) ( $args['id'] ?? 0 ) );
		if ( ! $order || ! is_a( $order, WC_Order::class ) ) {
			return array( 'error' => 'order_not_found' );
		}
		$row                = $this->order_row( $order );
		$row['subtotal']    = $order->get_subtotal();
		$row['shipping_total'] = $order->get_shipping_total();
		$row['tax_total']   = $order->get_total_tax();
		$row['customer_note'] = $order->get_customer_note();
		$row['line_items']  = array_map(
			static fn( $item ) => array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
			),
			array_values( $order->get_items() )
		);
		return $row;
	}

	public function update_order( array $args ): array {
		$id    = (int) ( $args['id'] ?? 0 );
		$order = wc_get_order( $id );
		if ( ! $order || ! is_a( $order, WC_Order::class ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'order_not_found_or_forbidden' );
		}

		$before = array(
			'status'        => $order->get_status(),
			'customer_note' => $order->get_customer_note(),
		);
		$changed = array();

		if ( isset( $args['status'] ) ) {
			$status = 'wc-' . sanitize_key( (string) $args['status'] );
			if ( ! array_key_exists( $status, wc_get_order_statuses() ) ) {
				return array( 'error' => 'unknown_status' );
			}
			$order->set_status( $args['status'] );
			$changed[] = 'status';
		}
		if ( isset( $args['customer_note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( (string) $args['customer_note'] ) );
			$changed[] = 'customer_note';
		}
		if ( empty( $changed ) ) {
			return array( 'error' => 'nothing_to_update' );
		}
		$order->save();

		$this->log->record( 'woocommerce', 'update-order', $id, $order->get_order_number(), sprintf( 'Updated: %s', implode( ', ', $changed ) ), $before, true );
		return array(
			'ok'      => true,
			'id'      => $id,
			'status'  => $order->get_status(),
			'updated' => $changed,
		);
	}
}
