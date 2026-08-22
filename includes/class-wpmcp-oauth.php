<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_OAuth {

	const ACCESS_TTL   = 3600;
	const REFRESH_TTL  = 2592000;
	const CODE_TTL     = 600;
	const OPT_CLIENTS  = 'wpmcp_oauth_clients';
	const OPT_TOKENS   = 'wpmcp_oauth_tokens';
	const CODE_PREFIX  = 'wpmcp_oauth_code_';
	const AUTHED_FLAG  = 'wpmcp_bearer_user';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'serve_discovery' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'authenticate_bearer' ), 5 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'validate_bearer_session' ), 20 );
	}

	public static function enabled(): bool {
		if ( ! is_ssl() && ! defined( 'WPMCP_ALLOW_INSECURE_OAUTH' ) ) {
			return false;
		}
		return (bool) apply_filters( 'wpmcp_oauth_enabled', true );
	}

	public static function serve_discovery(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH ) ?: '';

		$server_meta = '/.well-known/oauth-authorization-server';
		$resource_meta = '/.well-known/oauth-protected-resource';

		$matched = null;
		foreach ( array( $server_meta, $resource_meta ) as $prefix ) {
			if ( '' !== $path && ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) ) ) {
				$matched = $prefix;
				break;
			}
		}
		if ( null === $matched || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		header( 'Content-Type: application/json', true );
		header( 'Access-Control-Allow-Origin: *' );
		if ( $matched === $server_meta ) {
			echo wp_json_encode(
				array(
					'issuer'                      => home_url( '/' ),
					'authorization_endpoint'      => rest_url( 'wpmcp/v1/oauth/authorize' ),
					'token_endpoint'              => rest_url( 'wpmcp/v1/oauth/token' ),
					'registration_endpoint'       => rest_url( 'wpmcp/v1/oauth/register' ),
					'revocation_endpoint'         => rest_url( 'wpmcp/v1/oauth/revoke' ),
					'response_types_supported'    => array( 'code' ),
					'grant_types_supported'       => array( 'authorization_code', 'refresh_token' ),
					'code_challenge_methods_supported' => array( 'S256' ),
					'token_endpoint_auth_methods_supported' => array( 'none' ),
					'scopes_supported'            => array( 'mcp' ),
				)
			);
			exit;
		}

		echo wp_json_encode(
			array(
				'resource'               => home_url( '/' ),
				'authorization_servers'  => array( home_url( '/' ) ),
				'scopes_supported'       => array( 'mcp' ),
				'bearer_methods_supported' => array( 'header' ),
				'resource_documentation' => admin_url( 'admin.php?page=wpmcp' ),
			)
		);
		exit;
	}

	public static function register_routes(): void {
		if ( ! self::enabled() ) {
			return;
		}
		register_rest_route(
			'wpmcp/v1',
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'wpmcp/v1',
			'/oauth/authorize',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'render_consent' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_authorize' ),
					'permission_callback' => '__return_true',
				),
			)
		);
		register_rest_route(
			'wpmcp/v1',
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'wpmcp/v1',
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_revoke' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function clients(): array {
		return (array) get_option( self::OPT_CLIENTS, array() );
	}

	private static function save_clients( array $clients ): void {
		update_option( self::OPT_CLIENTS, $clients, false );
	}

	public static function handle_register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return self::oauth_error( 400, 'invalid_request', 'A JSON body is required.' );
		}
		$name = sanitize_text_field( (string) ( $body['client_name'] ?? 'MCP Client' ) );
		if ( mb_strlen( $name ) > 100 ) {
			$name = mb_substr( $name, 0, 100 );
		}
		$redirect_uris = $body['redirect_uris'] ?? array();
		if ( ! is_array( $redirect_uris ) || empty( $redirect_uris ) ) {
			return self::oauth_error( 400, 'invalid_redirect_uri', 'redirect_uris must be a non-empty array.' );
		}
		$clean = array();
		foreach ( $redirect_uris as $uri ) {
			$uri = esc_url_raw( (string) $uri );
			$scheme = wp_parse_url( $uri, PHP_URL_SCHEME );
			if ( '' === $uri || ! in_array( $scheme, array( 'https', 'http', 'com.example.oauth', 'vscode' ), true ) && null === $scheme ) {
				continue;
			}
			if ( 'http' === $scheme && ! str_contains( $uri, 'localhost' ) && ! str_contains( $uri, '127.0.0.1' ) ) {
				return self::oauth_error( 400, 'invalid_redirect_uri', 'http redirect URIs are only allowed on localhost.' );
			}
			$clean[] = $uri;
		}
		if ( empty( $clean ) ) {
			return self::oauth_error( 400, 'invalid_redirect_uri', 'No valid https (or localhost http) redirect URIs supplied.' );
		}
		$client_id = 'wpmcp_' . strtolower( wp_generate_password( 24, false, false ) );
		$clients          = self::clients();
		$clients[ $client_id ] = array(
			'name'          => $name,
			'redirect_uris' => array_slice( $clean, 0, 5 ),
			'created_at'    => gmdate( 'c' ),
		);
		self::save_clients( $clients );
		return new WP_REST_Response(
			array(
				'client_id'                  => $client_id,
				'client_name'                => $name,
				'redirect_uris'              => $clean,
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
			),
			201
		);
	}

	public static function render_consent( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			$login = wp_login_url( $request->get_url() );
			wp_safe_redirect( $login );
			exit;
		}
		$params = $request->get_query_params();
		$error  = self::validate_authorize_request( $params );
		if ( is_wp_error( $error ) ) {
			return new WP_REST_Response( '<h1>Authorization error</h1><p>' . esc_html( $error->get_error_message() ) . '</p>', 400, array( 'Content-Type' => 'text/html; charset=utf-8' ) );
		}
		$clients    = self::clients();
		$client     = $clients[ $params['client_id'] ] ?? array();
		$state_html = '';
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method' ) as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$state_html .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $key ), esc_attr( rawurlencode( (string) $params[ $key ] ) ) );
			}
		}
		$html = sprintf(
			'<!doctype html><html><head><meta charset="utf-8"><title>Authorize %1$s</title><style>body{font-family:-apple-system,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}.card{background:#fff;padding:32px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.2);max-width:420px}button{padding:8px 20px;border-radius:4px;border:1px solid #2271b1;font-size:14px;cursor:pointer}.allow{background:#2271b1;color:#fff}.deny{background:#fff;color:#2271b1;margin-left:8px}</style></head><body><div class="card"><h2>Authorize "%1$s"?</h2><p>The application <strong>%1$s</strong> wants to connect to this WordPress site over MCP with your admin account.</p><form method="post" action="%2$s">%3$s<input type="hidden" name="_wpnonce" value="%4$s"><button class="allow" name="decision" value="approve">Approve</button><button class="deny" name="decision" value="deny">Deny</button></form></div></body></html>',
			esc_html( $client['name'] ?? 'Unknown client' ),
			esc_url( rest_url( 'wpmcp/v1/oauth/authorize' ) ),
			$state_html,
			esc_attr( wp_create_nonce( 'wpmcp_oauth_consent' ) )
		);
		return new WP_REST_Response( $html, 200, array( 'Content-Type' => 'text/html; charset=utf-8' ) );
	}

	private static function validate_authorize_request( array $params ): WP_Error|true {
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method' ) as $required ) {
			if ( empty( $params[ $required ] ) ) {
				return new WP_Error( 'wpmcp_missing_param', sprintf( 'Missing parameter: %s', $required ) );
			}
		}
		if ( 'code' !== $params['response_type'] ) {
			return new WP_Error( 'wpmcp_bad_response_type', 'Only response_type=code is supported.' );
		}
		$clients = self::clients();
		if ( ! isset( $clients[ $params['client_id'] ] ) ) {
			return new WP_Error( 'wpmcp_unknown_client', 'Unknown client_id. Register first via the registration endpoint.' );
		}
		$raw_uri = rawurldecode( (string) $params['redirect_uri'] );
		if ( ! in_array( $raw_uri, $clients[ $params['client_id'] ]['redirect_uris'], true ) ) {
			return new WP_Error( 'wpmcp_bad_redirect_uri', 'redirect_uri is not registered for this client.' );
		}
		if ( 'S256' !== $params['code_challenge_method'] ) {
			return new WP_Error( 'wpmcp_bad_pkce', 'Only code_challenge_method=S256 is supported.' );
		}
		return true;
	}

	public static function handle_authorize( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::oauth_error( 401, 'access_denied', 'Sign in as an administrator first.' );
		}
		if ( ! wp_verify_nonce( (string) $request->get_param( '_wpnonce' ), 'wpmcp_oauth_consent' ) ) {
			return self::oauth_error( 403, 'invalid_request', 'Bad nonce.' );
		}
		$params = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method' ) as $key ) {
			$value        = (string) $request->get_param( $key );
			$params[ $key ] = $value ? rawurldecode( $value ) : '';
		}
		$valid = self::validate_authorize_request( $params );
		if ( is_wp_error( $valid ) ) {
			return self::oauth_error( 400, 'invalid_request', $valid->get_error_message() );
		}
		$redirect_uri = $params['redirect_uri'];
		$sep          = str_contains( $redirect_uri, '?' ) ? '&' : '?';

		if ( 'deny' === $request->get_param( 'decision' ) ) {
			$tail = 'error=access_denied';
			if ( '' !== $params['state'] ) {
				$tail .= '&state=' . rawurlencode( $params['state'] );
			}
			wp_safe_redirect( $redirect_uri . $sep . $tail );
			exit;
		}

		$code = wp_generate_password( 48, false, false );
		set_transient(
			self::CODE_PREFIX . hash( 'sha256', $code ),
			array(
				'user_id'       => get_current_user_id(),
				'client_id'     => $params['client_id'],
				'redirect_uri'  => $redirect_uri,
				'challenge'     => $params['code_challenge'],
				'created_at'    => time(),
			),
			self::CODE_TTL
		);

		$tail = 'code=' . rawurlencode( $code );
		if ( '' !== $params['state'] ) {
			$tail .= '&state=' . rawurlencode( $params['state'] );
		}
		wp_safe_redirect( $redirect_uri . $sep . $tail );
		exit;
	}

	public static function handle_token( WP_REST_Request $request ) {
		$grant_type = (string) $request->get_param( 'grant_type' );

		if ( 'authorization_code' === $grant_type ) {
			$code         = (string) $request->get_param( 'code' );
			$key          = self::CODE_PREFIX . hash( 'sha256', $code );
			$data         = get_transient( $key );
			$redirect_uri = (string) $request->get_param( 'redirect_uri' );
			$verifier     = (string) $request->get_param( 'code_verifier' );

			if ( ! $data ) {
				return self::oauth_error( 400, 'invalid_grant', 'Code expired or already used.' );
			}
			delete_transient( $key );

			if ( ! hash_equals( (string) $data['redirect_uri'], $redirect_uri ) ) {
				return self::oauth_error( 400, 'invalid_grant', 'redirect_uri mismatch.' );
			}
			$client_id = (string) $request->get_param( 'client_id' );
			if ( ! hash_equals( (string) $data['client_id'], $client_id ) ) {
				return self::oauth_error( 400, 'invalid_client', 'client_id mismatch.' );
			}
			if ( '' === $verifier || ! hash_equals( (string) $data['challenge'], self::pkce_challenge( $verifier ) ) ) {
				return self::oauth_error( 400, 'invalid_grant', 'PKCE verification failed.' );
			}

			$tokens = self::issue_tokens( (int) $data['user_id'], $client_id );
			return new WP_REST_Response( $tokens, 200 );
		}

		if ( 'refresh_token' === $grant_type ) {
			$refresh = (string) $request->get_param( 'refresh_token' );
			$tokens  = self::tokens();
			$hash    = hash( 'sha256', $refresh );
			if ( ! isset( $tokens[ $hash ] ) || 'refresh' !== $tokens[ $hash ]['type'] ) {
				return self::oauth_error( 400, 'invalid_grant', 'Unknown refresh token.' );
			}
			$entry = $tokens[ $hash ];
			unset( $tokens[ $hash ] );
			if ( $entry['expires_at'] < time() ) {
				self::save_tokens( $tokens );
				return self::oauth_error( 400, 'invalid_grant', 'Refresh token expired.' );
			}
			self::save_tokens( $tokens );
			return new WP_REST_Response( self::issue_tokens( (int) $entry['user_id'], (string) $entry['client_id'] ), 200 );
		}

		return self::oauth_error( 400, 'unsupported_grant_type', 'Supported: authorization_code, refresh_token.' );
	}

	public static function handle_revoke( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		if ( '' !== $token ) {
			$tokens = self::tokens();
			unset( $tokens[ hash( 'sha256', $token ) ] );
			self::save_tokens( $tokens );
		}
		return new WP_REST_Response( new stdClass(), 200 );
	}

	public static function authenticate_bearer( $result ) {
		if ( null !== $result || ! self::enabled() ) {
			return $result;
		}
		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
		if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', $header, $matches ) || ! str_starts_with( $matches[1], 'wpmcp_' ) ) {
			return $result;
		}
		$tokens = self::tokens();
		$hash   = hash( 'sha256', $matches[1] );
		if ( ! isset( $tokens[ $hash ] ) || 'access' !== $tokens[ $hash ]['type'] ) {
			return $result;
		}
		if ( $tokens[ $hash ]['expires_at'] < time() ) {
			unset( $tokens[ $hash ] );
			self::save_tokens( $tokens );
			return $result;
		}
		$GLOBALS[ self::AUTHED_FLAG ] = (int) $tokens[ $hash ]['user_id'];
		wp_set_current_user( (int) $tokens[ $hash ]['user_id'] );
		return $result;
	}

	public static function validate_bearer_session( $result ) {
		if ( null === $result || is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $GLOBALS[ self::AUTHED_FLAG ] ) ) {
			return true;
		}
		return $result;
	}

	private static function issue_tokens( int $user_id, string $client_id ): array {
		$access  = 'wpmcp_at_' . wp_generate_password( 48, false, false );
		$refresh = 'wpmcp_rt_' . wp_generate_password( 48, false, false );
		$now     = time();

		$tokens           = self::tokens();
		$tokens[ hash( 'sha256', $access ) ]  = array(
			'type'       => 'access',
			'user_id'    => $user_id,
			'client_id'  => $client_id,
			'issued_at'  => $now,
			'expires_at' => $now + self::ACCESS_TTL,
		);
		$tokens[ hash( 'sha256', $refresh ) ] = array(
			'type'       => 'refresh',
			'user_id'    => $user_id,
			'client_id'  => $client_id,
			'issued_at'  => $now,
			'expires_at' => $now + self::REFRESH_TTL,
		);
		self::save_tokens( $tokens );

		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => 'mcp',
		);
	}

	private static function tokens(): array {
		return (array) get_option( self::OPT_TOKENS, array() );
	}

	private static function save_tokens( array $tokens ): void {
		$now    = time();
		$tokens = array_filter( $tokens, static fn( $t ) => ( $t['expires_at'] ?? 0 ) >= $now );
		if ( count( $tokens ) > 500 ) {
			uasort( $tokens, static fn( $a, $b ) => ( $a['expires_at'] ?? 0 ) <=> ( $b['expires_at'] ?? 0 ) );
			$tokens = array_slice( $tokens, -500, null, true );
		}
		update_option( self::OPT_TOKENS, $tokens, false );
	}

	public static function pkce_challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( pack( 'H*', hash( 'sha256', $verifier ) ) ), '+/', '-_' ), '=' );
	}

	public static function revoke_client( string $client_id ): void {
		$clients = self::clients();
		unset( $clients[ $client_id ] );
		self::save_clients( $clients );
		$tokens = self::tokens();
		$tokens = array_filter( $tokens, static fn( $t ) => $t['client_id'] !== $client_id );
		self::save_tokens( $tokens );
	}

	private static function oauth_error( int $status, string $code, string $description ): WP_Error {
		return new WP_Error( $code, $description, array( 'status' => $status ) );
	}
}

WPMCP_OAuth::init();
