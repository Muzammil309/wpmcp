<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Auth {

	public static function app_passwords_available(): bool {
		return ! defined( 'WP_APPLICATION_PASSWORDS_DISABLED' ) || ! WP_APPLICATION_PASSWORDS_DISABLED;
	}

	public static function profile_url(): string {
		return self_admin_url( 'profile.php#application-passwords-section' );
	}

	public static function endpoint_url(): string {
		return rest_url( WPMCP_REST::NAMESPACE_URI . '/mcp' );
	}

	public static function current_auth_method(): string {
		if ( did_action( 'application_passwords_authenticated' ) ) {
			return 'application_password';
		}
		if ( is_user_logged_in() && wp_verify_nonce( wp_get_raw_referer(), 'wp_rest' ) ) {
			return 'cookie';
		}
		return is_user_logged_in() ? 'cookie' : 'none';
	}

	public static function client_config_snippets(): array {
		$url = self::endpoint_url();
		return array(
			'claude_code' => sprintf( 'claude mcp add --transport http wordpress %s', $url ),
			'cursor'      => wp_json_encode(
				array(
					'mcpServers' => array(
						'wordpress' => array(
							'url' => $url,
						),
					),
				)
			),
			'codex'       => sprintf(
				"[mcp_servers.wordpress]\nurl = \"%s\"\nhttp_headers = { \"Authorization\" = \"Basic <base64 of user:app-password>\" }",
				$url
			),
			'claude_desktop' => wp_json_encode(
				array(
					'mcpServers' => array(
						'wordpress' => array(
							'type'    => 'http',
							'url'     => $url,
							'headers' => array(
								'Authorization' => 'Basic <base64 of user:app-password>',
							),
						),
					),
				)
			),
		);
	}

	public static function require_real_auth_for_mcp( $result ) {
		if ( null === $result || is_wp_error( $result ) ) {
			return $result;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri || false === strpos( $uri, '/' . rest_get_url_prefix() . '/' . WPMCP_REST::NAMESPACE_URI . '/mcp' ) ) {
			return $result;
		}
		if ( 'application_password' !== self::current_auth_method() && empty( $GLOBALS['wpmcp_bearer_user'] ) && ! apply_filters( 'wpmcp_allow_cookie_auth', false ) ) {
			return new WP_Error(
				'wpmcp_app_password_required',
				'The MCP endpoint requires an Application Password. Create one under Users -> Profile and use HTTP Basic auth.',
				array( 'status' => 401 )
			);
		}
		return $result;
	}
}

add_filter( 'rest_authentication_errors', array( 'WPMCP_Auth', 'require_real_auth_for_mcp' ), 30 );
