<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_CLI {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	private const BLOCKED_PREFIXES = array(
		'eval', 'shell', 'db query ', 'db import', 'db export', 'db search',
		'config', 'package', 'server', 'cache flush-all',
	);

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public static function available(): bool {
		return class_exists( 'WPMCP_REST' );
	}

	public function register(): void {
		if ( ! defined( 'WPMCP_ALLOW_WP_CLI' ) || ! WPMCP_ALLOW_WP_CLI ) {
			return;
		}
		if ( ! function_exists( 'WP_CLI' ) && ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		if ( ! wpmcp_is_pro() ) {
			return;
		}

		$this->registry->register(
			'run-wp-cli',
			array(
				'title'       => 'Run WP-CLI',
				'description' => 'Run a wp-cli command in-process. Blocklisted: eval, shell, db query/import/export, config, package, server. Pro only; requires the WPMCP_ALLOW_WP_CLI constant.',
				'category'    => 'wp-cli',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'command' => array( 'type' => 'string', 'description' => 'Without the leading wp, e.g. "plugin list --status=active --format=json"' ),
						'timeout_seconds' => array( 'type' => 'integer', 'default' => 30, 'maximum' => 120 ),
					),
					'required'   => array( 'command' ),
				),
				'handler'     => array( $this, 'run' ),
			)
		);
	}

	public static function is_blocked( string $command ): bool {
		$normalized = strtolower( trim( preg_replace( '/^(--\S+\s+)+/', '', $command ) ) );
		foreach ( self::BLOCKED_PREFIXES as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $normalized, $prefix ) || str_starts_with( $normalized, ltrim( $prefix ) . ' ' ) || $normalized === trim( $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	public function run( array $args ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'forbidden' );
		}
		if ( ! class_exists( 'WP_CLI' ) || ! method_exists( 'WP_CLI', 'runcommand' ) ) {
			return array( 'error' => 'wp_cli_unavailable' );
		}
		$command = trim( (string) ( $args['command'] ?? '' ) );
		if ( '' === $command ) {
			return array( 'error' => 'command_required' );
		}
		if ( self::is_blocked( $command ) ) {
			return array( 'error' => 'blocked_command', 'message' => 'This command family is refused over MCP for safety.' );
		}

		try {
			$result = WP_CLI::runcommand(
				$command,
				array(
					'return'     => 'all',
					'exit_error' => false,
					'parse'      => 'std',
					'color'      => 'never',
				)
			);
		} catch ( Throwable $e ) {
			return array( 'error' => 'command_error', 'message' => $e->getMessage() );
		}

		$stdout  = (string) ( $result->stdout ?? '' );
		$stderr  = (string) ( $result->stderr ?? '' );
		$code    = (int) ( $result->return_code ?? 0 );

		$this->log->record( 'wp-cli', 'run-wp-cli', 0, strtok( $command, ' ' ), sprintf( 'Ran: %s', mb_substr( $command, 0, 120 ) ) );
		return array(
			'ok'          => 0 === $code,
			'command'     => $command,
			'return_code' => $code,
			'output'      => mb_substr( $stdout, 0, 8000 ),
			'stderr'      => mb_substr( $stderr, 0, 2000 ),
		);
	}
}
