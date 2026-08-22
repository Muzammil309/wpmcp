<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_REST {

	const NAMESPACE_URI = 'wpmcp/v1';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_URI,
			'/mcp',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_post' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	public function permission_callback() {
		if ( ! wpmcp_server_enabled() ) {
			return new WP_Error( 'wpmcp_disabled', 'The MCP server is disabled. Enable it under WP MCP -> Connection.', array( 'status' => 403 ) );
		}
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			'Authentication required. Create a WordPress Application Password (Users -> Profile) and send it as HTTP Basic auth.',
			array( 'status' => 401 )
		);
	}

	public function handle_post( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$single = json_decode( $request->get_body(), true );
			$body   = is_array( $single ) ? $single : array();
		}

		if ( isset( $body[0] ) && is_array( $body[0] ) ) {
			$server    = new WPMCP_Server( wpmcp_plugin()->registry );
			$responses = array();
			foreach ( $body as $message ) {
				$response = $server->handle_request( (array) $message );
				if ( ! empty( $response ) ) {
					$responses[] = $response;
				}
			}
			return new WP_REST_Response( $responses, 200, array( 'Content-Type' => 'application/json' ) );
		}

		$server   = new WPMCP_Server( wpmcp_plugin()->registry );
		$response = $server->handle_request( $body );
		if ( empty( $response ) ) {
			return new WP_REST_Response( null, 202 );
		}
		return new WP_REST_Response( $response, 200, array( 'Content-Type' => 'application/json' ) );
	}

	public function handle_get( WP_REST_Request $request ) {
		return new WP_Error(
			'wpmcp_sse_unsupported',
			'SSE streaming is not enabled on this server; use POST with JSON responses.',
			array( 'status' => 405 )
		);
	}
}

function wpmcp_server_enabled(): bool {
	return (bool) apply_filters( 'wpmcp_server_enabled', get_option( 'wpmcp_server_enabled', 1 ) );
}
