<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_License {

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	public static function is_pro(): bool {
		if ( defined( 'WPMCP_PRO' ) && WPMCP_PRO ) {
			return true;
		}
		$data = self::data();
		return 'active' === ( $data['status'] ?? '' ) && ( $data['expires_at'] ?? 0 ) > time();
	}

	private static function data(): array {
		return (array) get_option( 'wpmcp_license', array() );
	}

	private static function save( array $data ): void {
		update_option( 'wpmcp_license', $data, false );
	}

	public static function key(): string {
		return (string) ( self::data()['key'] ?? '' );
	}

	private static function server_url(): string {
		return (string) apply_filters( 'wpmcp_license_server_url', get_option( 'wpmcp_license_server_url', '' ) );
	}

	public static function activate( string $key ): array|WP_Error {
		$key    = sanitize_text_field( trim( $key ) );
		$server = self::server_url();
		if ( defined( 'WPMCP_PRO' ) && WPMCP_PRO ) {
			self::save(
				array(
					'key'        => $key,
					'status'     => 'active',
					'expires_at' => time() + YEAR_IN_SECONDS,
					'source'     => 'constant',
				)
			);
			return array( 'status' => 'active', 'note' => 'WPMCP_PRO constant active.' );
		}
		if ( '' === $server ) {
			return new WP_Error( 'wpmcp_no_license_server', 'No licensing server configured. Set the server URL or define WPMCP_PRO for development builds.' );
		}
		$response = wp_safe_remote_post(
			trailingslashit( $server ) . 'activate',
			array(
				'timeout' => 15,
				'body'    => array(
					'license_key' => $key,
					'site_url'    => home_url(),
					'version'     => WPMCP_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['active'] ) ) {
			return new WP_Error( 'wpmcp_license_invalid', (string) ( $body['message'] ?? 'License activation failed.' ), array( 'status' => 400 ) );
		}
		self::save(
			array(
				'key'        => $key,
				'status'     => 'active',
				'expires_at' => (int) ( $body['expires_at'] ?? time() + MONTH_IN_SECONDS ),
				'plan'       => sanitize_key( (string) ( $body['plan'] ?? 'pro' ) ),
			)
		);
		return array( 'status' => 'active' );
	}

	public static function deactivate(): void {
		$data = self::data();
		if ( isset( $data['key'], $data['source'] ) && 'constant' !== $data['source'] ) {
			$server = self::server_url();
			if ( '' !== $server ) {
				wp_safe_remote_post(
					trailingslashit( $server ) . 'deactivate',
					array(
						'timeout' => 10,
						'body'    => array(
							'license_key' => $data['key'],
							'site_url'    => home_url(),
						),
					)
				);
			}
		}
		delete_option( 'wpmcp_license' );
	}

	public static function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['wpmcp_license_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpmcp_license_nonce'] ) ), 'wpmcp_license' ) ) {
			return;
		}
		if ( isset( $_POST['wpmcp_deactivate_license'] ) ) {
			self::deactivate();
			add_action( 'admin_notices', static fn() => echo_notice( 'License deactivated.', 'warning' ) );
			return;
		}
		$key = isset( $_POST['wpmcp_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wpmcp_license_key'] ) ) : '';
		if ( '' !== $key ) {
			$result = self::activate( $key );
			if ( is_wp_error( $result ) ) {
				add_action( 'admin_notices', static fn() => echo_notice( esc_html( $result->get_error_message() ), 'error' ) );
			} else {
				add_action( 'admin_notices', static fn() => echo_notice( 'Pro license activated.', 'success' ) );
			}
		}
	}
}

function echo_notice( string $message, string $type ): void {
	printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), $message );
}

WPMCP_License::init();
