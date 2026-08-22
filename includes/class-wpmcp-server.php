<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Server {

	const PROTOCOL_VERSION = '2025-06-18';
	const SERVER_NAME      = 'wpmcp-suite';
	const SESSION_TTL      = 3600;

	private WPMCP_Registry $registry;

	public function __construct( WPMCP_Registry $registry ) {
		$this->registry = $registry;
	}

	public function handle_request( array $body ): array|WP_Error {
		if ( ! isset( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] ) {
			return $this->error( null, -32600, 'Invalid Request: jsonrpc must be "2.0"' );
		}
		$id     = $body['id'] ?? null;
		$method = $body['method'] ?? '';
		$params = $body['params'] ?? array();

		if ( is_string( $method ) && str_starts_with( $method, 'notifications/' ) ) {
			return array();
		}

		try {
			switch ( $method ) {
				case 'initialize':
					return $this->rpc_initialize( $id, $params );
				case 'ping':
					return $this->result( $id, new stdClass() );
				case 'tools/list':
					return $this->result( $id, array( 'tools' => $this->registry->list_for_client() ) );
				case 'tools/call':
					return $this->rpc_tools_call( $id, $params );
				default:
					return $this->error( $id, -32601, sprintf( 'Method not found: %s', $method ) );
			}
		} catch ( Throwable $e ) {
			return $this->error( $id, -32603, $e->getMessage() );
		}
	}

	private function rpc_initialize( $id, array $params ): array {
		$client_version = (string) ( $params['protocolVersion'] ?? self::PROTOCOL_VERSION );
		$protocol       = version_compare( $this->version_key( $client_version ), $this->version_key( self::PROTOCOL_VERSION ), '>' )
			? self::PROTOCOL_VERSION
			: $client_version;
		return $this->result(
			$id,
			array(
				'protocolVersion' => $protocol,
				'capabilities'    => array(
					'tools' => array( 'listChanged' => false ),
				),
				'serverInfo'      => array(
					'name'    => self::SERVER_NAME,
					'version' => WPMCP_VERSION,
				),
				'instructions'    => sprintf(
					'WordPress %1$s MCP server. Active SEO integration: %2$s. Tools cover content, media, settings and SEO. Write tools ship disabled by default; enable them under WP MCP -> Tools in wp-admin.',
					get_bloginfo( 'version' ),
					wpmcp_plugin()->seo->active_label()
				),
			)
		);
	}

	private function version_key( string $version ): string {
		return preg_replace( '/[^0-9]/', '', str_pad( $version, 12, '0' ) ) ?? '0';
	}

	private function rpc_tools_call( $id, array $params ): array {
		$name = (string) ( $params['name'] ?? '' );
		$args = $params['arguments'] ?? array();
		if ( ! is_array( $args ) ) {
			return $this->error( $id, -32602, 'arguments must be an object' );
		}
		$result = $this->registry->call( $name, $args );
		if ( is_wp_error( $result ) ) {
			$code = (int) ( $result->get_error_data()['status'] ?? 0 );
			$jsonrpc_code = match ( true ) {
				404 === $code => -32602,
				403 === $code => -32602,
				default       => -32603,
			};
			return $this->error( $id, $jsonrpc_code, $result->get_error_message() );
		}
		if ( is_array( $result ) && isset( $result['error'] ) ) {
			return $this->result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => wp_json_encode( $result ),
						),
					),
					'isError' => true,
				)
			);
		}
		return $this->result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $result ),
					),
				),
			)
		);
	}

	private function result( $id, $data ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $data,
		);
	}

	private function error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
